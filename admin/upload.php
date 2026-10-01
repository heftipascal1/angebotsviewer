<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/version.php';
require_once __DIR__ . '/../includes/migrations.php';
require_once __DIR__ . '/../includes/bundle.php';
requireAdmin();

$db = getDB();
redirectIfMigrationPending($db);
$error = '';
$createdLink = '';
$kindValue = normalizeKind($_POST['kind_label'] ?? 'Angebot');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $title = trim($_POST['title'] ?? '');
    $kindLabel = normalizeKind($_POST['kind_label'] ?? '');
    $heading = normalizeHeading($_POST['heading'] ?? '');
    $password = $_POST['password'] ?? '';   // leer = ohne Passwort, Link öffnet direkt
    $expiresInput = trim($_POST['expires_at'] ?? '');
    // Datum (YYYY-MM-DD) → bis Ende des Tages gültig
    $expiresAt = ($expiresInput !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresInput))
        ? $expiresInput . ' 23:59:59'
        : null;

    if ($title === '') {
        $error = 'Bitte gib einen Titel ein.';
    } elseif (!isset($_FILES['htmlfile']) || $_FILES['htmlfile']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Bitte lade eine HTML-Datei oder ein ZIP hoch.';
    } else {
        $file = $_FILES['htmlfile'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, ['html', 'htm', 'zip'], true)) {
            $error = 'Nur HTML-Dateien (.html, .htm) oder ein ZIP-Archiv (.zip) sind erlaubt.';
        } elseif ($file['size'] > ($ext === 'zip' ? 60 : 10) * 1024 * 1024) {
            $error = $ext === 'zip'
                ? 'Das ZIP darf maximal 60 MB groß sein.'
                : 'Die Datei darf maximal 10 MB groß sein.';
        } else {
            $slug = generateSlug();
            $ok = false;
            $bundleType = 'single';
            $entryFile  = null;
            $storedName = $slug . '.html';

            if ($ext === 'zip') {
                // Mehrseitiges Angebot: ZIP nach uploads/<slug>/ entpacken.
                $res = bundleExtract($file['tmp_name'], $slug);
                if ($res['ok']) {
                    $ok = true;
                    $bundleType = 'bundle';
                    $entryFile  = $res['entry'];
                    $storedName = $slug;          // Ordnername
                    $bundleInfo = $res;
                } else {
                    $error = $res['error'];
                }
            } else {
                $ok = move_uploaded_file($file['tmp_name'], __DIR__ . '/../uploads/' . $storedName);
                if (!$ok) {
                    $error = 'Die Datei konnte nicht gespeichert werden. Prüfe die Schreibrechte im uploads-Ordner.';
                }
            }

            if ($ok) {
                $stmt = $db->prepare("
                    INSERT INTO offers (title, slug, kind_label, heading, password_hash, filename, original_filename, expires_at, password_enc, bundle_type, entry_file)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $title,
                    $slug,
                    $kindLabel,
                    $heading !== '' ? $heading : null,
                    $password !== '' ? password_hash($password, PASSWORD_BCRYPT) : '',
                    $storedName,
                    $file['name'],
                    $expiresAt,
                    $password !== '' ? encryptSecret($password) : null,
                    $bundleType,
                    $entryFile,
                ]);
                $createdLink = BASE_URL . 'a/' . $slug;

                // Mehrseitig? Prüfen, welcher Auslieferungs-Weg auf diesem
                // Server funktioniert (mod_rewrite oder PATH_INFO).
                if ($bundleType === 'bundle') {
                    $delivery = bundleDetectDelivery($db, $slug, (string)$entryFile);
                }

                // Copy-Paste-Nachricht vorbereiten
                $tpl = getSetting($db, 'message_template', '') ?: defaultMessageTemplate();
                $createdMessage = renderOfferMessage($tpl, [
                    'typ'         => $kindLabel,
                    'titel'       => $title,
                    'link'        => $createdLink,
                    'passwort'    => $password !== '' ? $password : 'nicht nötig – der Link öffnet sich direkt',
                    'gueltig_bis' => $expiresAt ? date('d.m.Y', strtotime($expiresAt)) : 'unbegrenzt',
                ]);
            } elseif ($ext === 'zip' && !$ok) {
                // Beim Fehlschlag den halben Ordner nicht liegen lassen.
                bundleDeleteDir(bundleDir($slug));
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Neues Angebot — <?= e(BRAND_NAME) ?></title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="admin-wrap">
    <div class="admin-header">
        <h1>Neues Angebot</h1>
        <a href="index.php" class="btn btn-ghost btn-sm">← Zurück</a>
    </div>

    <?php if ($createdLink): ?>
        <div class="card">
            <div class="alert alert-success">
                Angebot wurde erstellt! Der Link ist sofort einsatzbereit.
                <?php if (!empty($bundleInfo)): ?>
                    <br><strong>Mehrseitiges Angebot:</strong> <?= (int)$bundleInfo['files'] ?> Dateien übernommen,
                    Startseite <code><?= e($bundleInfo['entry']) ?></code>.
                    <?php if (!empty($bundleInfo['skipped'])): ?>
                        <?= (int)$bundleInfo['skipped'] ?> Datei(en) wurden ignoriert (nicht erlaubter Dateityp).
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php if (!empty($delivery) && $delivery['mode'] === null): ?>
                <div class="alert alert-error">
                    <strong>Achtung:</strong> Die Unterseiten und Stylesheets können vom Server
                    gerade nicht ausgeliefert werden — das Angebot würde ohne Design erscheinen.
                    <?php if ($delivery['tested']): ?>
                        Weder <code>/f/…</code> (mod_rewrite) noch <code>file.php/…</code> (PATH_INFO)
                        haben geantwortet (Status <?= (int)$delivery['rewrite'] ?> / <?= (int)$delivery['pathinfo'] ?>).
                        Bitte prüfe, ob die aktuelle <code>.htaccess</code> im Hauptordner liegt.
                    <?php else: ?>
                        Der Selbsttest konnte nicht ausgeführt werden (der Server erlaubt keine
                        Anfrage an sich selbst). Bitte das Angebot einmal selbst im Browser öffnen.
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="form-group">
                <label>Trackbarer Link für deinen Kunden</label>
                <div class="link-box">
                    <input type="text" value="<?= e($createdLink) ?>" readonly id="newLink">
                    <button class="btn btn-primary btn-sm" onclick="copyLink()">Kopieren</button>
                </div>
            </div>

            <div class="form-group">
                <label>Fertige Nachricht (Link + Passwort) — zum Kopieren</label>
                <textarea id="msgBox" readonly style="width:100%;min-height:180px;padding:.8rem 1rem;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg);color:var(--text);font-size:.9rem;line-height:1.5;font-family:inherit;resize:vertical"><?= e($createdMessage) ?></textarea>
                <button type="button" class="btn btn-primary" style="margin-top:.6rem" onclick="copyMsg()">Nachricht kopieren</button>
                <span id="msgCopied" style="margin-left:.6rem;color:var(--success);display:none">Kopiert!</span>
                <p class="form-hint">Vorlage anpassbar unter <a href="settings.php" style="color:var(--primary)">Einstellungen</a>.</p>
            </div>

            <div style="display:flex;gap:.5rem;margin-top:1rem">
                <a href="upload.php" class="btn btn-primary">Weiteres Angebot</a>
                <a href="index.php" class="btn btn-ghost">Zum Dashboard</a>
            </div>
        </div>
        <script>
        function copyLink() {
            const i = document.getElementById('newLink');
            navigator.clipboard.writeText(i.value);
        }
        function buildHtmlMessage(text) {
            const esc = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            const linked = esc.replace(/(https?:\/\/[^\s]+)/g, '<a href="$1">$1</a>');
            return linked.replace(/\n/g, '<br>');
        }
        async function copyRichMessage(text) {
            try {
                if (window.ClipboardItem && navigator.clipboard.write) {
                    const html = buildHtmlMessage(text);
                    await navigator.clipboard.write([new ClipboardItem({
                        'text/html':  new Blob([html], { type: 'text/html' }),
                        'text/plain': new Blob([text], { type: 'text/plain' })
                    })]);
                } else {
                    await navigator.clipboard.writeText(text);
                }
            } catch (e) {
                await navigator.clipboard.writeText(text);
            }
        }
        function copyMsg() {
            copyRichMessage(document.getElementById('msgBox').value).then(() => {
                const c = document.getElementById('msgCopied');
                c.style.display = 'inline';
                setTimeout(() => c.style.display = 'none', 1500);
            });
        }
        </script>
    <?php else: ?>
        <div class="card">
            <?php if ($error): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data">
                <?= csrfField() ?>

                <div class="form-group">
                    <label>Titel des Angebots</label>
                    <input type="text" name="title" placeholder="z.B. Angebot Webdesign – Firma Müller" value="<?= e($_POST['title'] ?? '') ?>" required>
                    <p class="form-hint">Nur für dich sichtbar, zur Wiedererkennung im Dashboard.</p>
                </div>

                <div class="form-group">
                    <label>Was ist das?</label>
                    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                        <select id="kindSelect" style="flex:1 1 200px;padding:.7rem 1rem;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg);color:var(--text);font-size:.95rem">
                            <?php foreach (kindPresets() as $k): ?>
                                <option value="<?= e($k) ?>"<?= $k === $kindValue ? ' selected' : '' ?>><?= e($k) ?></option>
                            <?php endforeach; ?>
                            <option value="__custom"<?= in_array($kindValue, kindPresets(), true) ? '' : ' selected' ?>>Eigene Bezeichnung …</option>
                        </select>
                        <input type="text" name="kind_label" id="kindInput" maxlength="40"
                               value="<?= e($kindValue) ?>" placeholder="z.B. Relaunch-Vorschau"
                               style="flex:1 1 200px;padding:.7rem 1rem;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg);color:var(--text);font-size:.95rem">
                    </div>
                    <p class="form-hint">Diese Bezeichnung sieht der Kunde: „<span id="kindPreview"><?= e($kindValue) ?></span> ansehen", „<span id="kindPreview2"><?= e($kindValue) ?></span> ist geschützt". Auch als Platzhalter <code>{typ}</code> in der Nachricht nutzbar.</p>
                </div>

                <div class="form-group">
                    <label>Überschrift auf der Passwort-Seite (optional)</label>
                    <input type="text" name="heading" maxlength="80" placeholder="<?= e(BRAND_NAME) ?>" value="<?= e($_POST['heading'] ?? '') ?>">
                    <p class="form-hint">Steht oben auf der Seite, auf der der Kunde das Passwort eingibt (z.B. dein Firmenname oder „Gästebuch Hotel Sonne"). Leer = „<?= e(BRAND_NAME) ?>".</p>
                </div>

                <div class="form-group">
                    <label>Passwort für den Kunden (optional)</label>
                    <input type="text" name="password" placeholder="z.B. Mueller2026 – leer lassen für Zugang ohne Passwort">
                    <p class="form-hint">Mit Passwort muss der Kunde es zuerst eingeben. <strong>Leer lassen</strong> = der Link öffnet das Angebot direkt, Aufrufe werden trotzdem gezählt.</p>
                </div>

                <div class="form-group">
                    <label>Gültig bis (optional)</label>
                    <input type="date" name="expires_at" value="<?= e($_POST['expires_at'] ?? '') ?>" style="width:100%;padding:.7rem 1rem;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg);color:var(--text);font-size:.95rem">
                    <p class="form-hint">Nach diesem Datum ist der Link automatisch nicht mehr abrufbar. Leer = unbegrenzt gültig.</p>
                </div>

                <div class="form-group">
                    <label>Angebot: HTML-Datei oder ZIP</label>
                    <div class="file-drop" id="fileDrop">
                        <input type="file" name="htmlfile" id="htmlfile" accept=".html,.htm,.zip" required>
                        <div class="file-label">Datei hierher ziehen oder klicken zum Auswählen</div>
                        <div class="file-name" id="fileName"></div>
                    </div>
                    <p class="form-hint">
                        <strong>Einzelne Seite:</strong> .html / .htm (max. 10 MB).<br>
                        <strong>Mehrere Seiten / komplette Mini-Webseite:</strong> .zip mit allen Dateien
                        (max. 60 MB). Die Startseite sollte <code>index.html</code> heißen; Unterseiten,
                        Bilder, CSS und Schriften bleiben untereinander verlinkt und anklickbar.
                    </p>
                </div>

                <button type="submit" class="btn btn-primary btn-full">Angebot erstellen & Link generieren</button>
            </form>
        </div>

        <script>
        const drop = document.getElementById('fileDrop');
        const input = document.getElementById('htmlfile');
        const nameEl = document.getElementById('fileName');

        drop.addEventListener('click', () => input.click());
        input.addEventListener('change', () => {
            if (input.files.length) nameEl.textContent = input.files[0].name;
        });
        ['dragover','dragenter'].forEach(ev => drop.addEventListener(ev, e => {
            e.preventDefault(); drop.classList.add('drag-over');
        }));
        ['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => {
            e.preventDefault(); drop.classList.remove('drag-over');
        }));
        drop.addEventListener('drop', e => {
            if (e.dataTransfer.files.length) {
                input.files = e.dataTransfer.files;
                nameEl.textContent = e.dataTransfer.files[0].name;
            }
        });
        </script>
    <?php endif; ?>
</div>
<script>
(function () {
    const sel = document.getElementById('kindSelect');
    const inp = document.getElementById('kindInput');
    const p1  = document.getElementById('kindPreview');
    const p2  = document.getElementById('kindPreview2');
    if (!sel || !inp) return;
    function preview() {
        const v = inp.value.trim() || 'Angebot';
        if (p1) p1.textContent = v;
        if (p2) p2.textContent = v;
    }
    sel.addEventListener('change', function () {
        if (sel.value !== '__custom') { inp.value = sel.value; }
        else { inp.value = ''; inp.focus(); }
        preview();
    });
    inp.addEventListener('input', function () {
        const match = Array.from(sel.options).some(o => o.value === inp.value);
        sel.value = match ? inp.value : '__custom';
        preview();
    });
})();
</script>
</body>
</html>
