<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/version.php';
require_once __DIR__ . '/../includes/migrations.php';
require_once __DIR__ . '/../includes/bundle.php';
require_once __DIR__ . '/../includes/versions.php';
requireAdmin();

$db = getDB();
redirectIfMigrationPending($db);
$id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM offers WHERE id = ? AND is_active = 1");
$stmt->execute([$id]);
$offer = $stmt->fetch();

if (!$offer) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';
if (($_GET['msg'] ?? '') !== '') $success = (string)$_GET['msg'];

// ===== Versionsverlauf: Version anzeigen / löschen =====
$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['activate_version', 'delete_version'], true)) {
    verifyCsrf();
    $versionId = (int)($_POST['version_id'] ?? 0);
    if ($action === 'activate_version') {
        $v = versionById($db, $id, $versionId);
        if ($v !== null && activateVersion($db, $id, $versionId)) {
            if ($v['bundle_type'] === 'bundle') {
                bundleDetectDelivery($db, $offer['slug'], (string)$v['entry_file']);
            }
            header('Location: edit.php?id=' . $id . '&msg=' . rawurlencode('Version ' . (int)$v['version_no'] . ' wird jetzt angezeigt.'));
            exit;
        }
        $error = 'Version nicht gefunden.';
    } else {
        $err = deleteVersion($db, $offer, $versionId);
        if ($err === null) {
            header('Location: edit.php?id=' . $id . '&msg=' . rawurlencode('Version gelöscht.'));
            exit;
        }
        $error = $err;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === '') {
    verifyCsrf();

    $title = trim($_POST['title'] ?? '');
    $kindLabel = normalizeKind($_POST['kind_label'] ?? '');
    $heading = normalizeHeading($_POST['heading'] ?? '');
    $newPassword = $_POST['password'] ?? '';
    $removePassword = !empty($_POST['remove_password']);
    $changes = [];

    if ($title === '') {
        $error = 'Der Titel darf nicht leer sein.';
    } else {
        // 1) Titel
        if ($title !== $offer['title']) {
            $db->prepare("UPDATE offers SET title = ? WHERE id = ?")->execute([$title, $id]);
            $changes[] = 'Titel aktualisiert';
        }

        // 1a) Bezeichnung (Angebot / Webseite / Vorschau / eigene)
        if ($kindLabel !== offerKind($offer)) {
            $db->prepare("UPDATE offers SET kind_label = ? WHERE id = ?")->execute([$kindLabel, $id]);
            $changes[] = 'Bezeichnung auf „' . $kindLabel . '" geändert';
        }

        // 1a2) Überschrift auf der Passwort-Seite (leer = Standard)
        $oldHeading = trim((string)($offer['heading'] ?? ''));
        if ($heading !== $oldHeading) {
            $db->prepare("UPDATE offers SET heading = ? WHERE id = ?")->execute([$heading !== '' ? $heading : null, $id]);
            $changes[] = $heading !== '' ? 'Überschrift auf „' . $heading . '" geändert' : 'Überschrift auf Standard zurückgesetzt';
        }

        // 1b) Ablaufdatum
        $expiresInput = trim($_POST['expires_at'] ?? '');
        $newExpires = ($expiresInput !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresInput))
            ? $expiresInput . ' 23:59:59'
            : null;
        $oldExpires = $offer['expires_at'] ?? null;
        if ($newExpires !== $oldExpires) {
            // Neues Ablaufdatum -> Ablauf-Erinnerung darf erneut kommen
            $db->prepare("UPDATE offers SET expires_at = ?, reminded_expiry_at = NULL WHERE id = ?")->execute([$newExpires, $id]);
            $changes[] = $newExpires ? 'Ablaufdatum gesetzt' : 'Ablaufdatum entfernt';
        }

        // 2) Passwort: entfernen (Link ohne Passwort) oder neu setzen
        if ($removePassword) {
            if (offerHasPassword($offer)) {
                $db->prepare("UPDATE offers SET password_hash = '', password_enc = NULL WHERE id = ?")->execute([$id]);
                $changes[] = 'Passwort entfernt – der Link öffnet sich jetzt direkt';
            }
        } elseif ($newPassword !== '') {
            $db->prepare("UPDATE offers SET password_hash = ?, password_enc = ? WHERE id = ?")
               ->execute([password_hash($newPassword, PASSWORD_BCRYPT), encryptSecret($newPassword), $id]);
            $changes[] = offerHasPassword($offer) ? 'Passwort geändert' : 'Passwort gesetzt';
        }

        // 2b) Startseite eines mehrseitigen Angebots wechseln
        $newEntry = trim($_POST['entry_file'] ?? '');
        if ($newEntry !== ''
            && ($offer['bundle_type'] ?? 'single') === 'bundle'
            && $newEntry !== ($offer['entry_file'] ?? '')
            && in_array($newEntry, bundleListPages((string)$offer['filename']), true)) {
            $db->prepare("UPDATE offers SET entry_file = ? WHERE id = ?")->execute([$newEntry, $id]);
            if (!empty($offer['current_version_id'])) {
                $db->prepare("UPDATE offer_versions SET entry_file = ? WHERE id = ?")->execute([$newEntry, (int)$offer['current_version_id']]);
            }
            $changes[] = 'Startseite geändert';
        }

        // 3) Neuer Inhalt (optional) — wird als NEUE VERSION abgelegt und sofort
        //    angezeigt. Ältere Versionen bleiben erhalten und lassen sich
        //    jederzeit wieder anzeigen. Link, Passwort und Statistik bleiben.
        if (isset($_FILES['htmlfile']) && $_FILES['htmlfile']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['htmlfile'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $versionNote = (string)($_POST['version_note'] ?? '');
            $versionNo = nextVersionNo($db, $id);
            $storageKey = versionStorageKey($offer['slug'], $versionNo);

            if (!in_array($ext, ['html', 'htm', 'zip'], true)) {
                $error = 'Nur HTML-Dateien (.html, .htm) oder ein ZIP-Archiv (.zip) sind erlaubt.';
            } elseif ($file['size'] > ($ext === 'zip' ? 60 : 10) * 1024 * 1024) {
                $error = $ext === 'zip'
                    ? 'Das ZIP darf maximal 60 MB groß sein.'
                    : 'Die Datei darf maximal 10 MB groß sein.';
            } elseif ($ext === 'zip') {
                $res = bundleExtract($file['tmp_name'], $storageKey);
                if ($res['ok']) {
                    $vid = createVersion($db, $id, $versionNo, 'bundle', $storageKey, $res['entry'], $file['name'], $versionNote);
                    activateVersion($db, $id, $vid);
                    $changes[] = 'Version ' . $versionNo . ' angelegt und angezeigt (mehrseitig, ' . (int)$res['files'] . ' Dateien, Start: ' . $res['entry'] . ')';
                    bundleDetectDelivery($db, $offer['slug'], (string)$res['entry']);
                } else {
                    $error = $res['error'];
                }
            } else {
                $storedName = $storageKey . '.html';
                $targetPath = __DIR__ . '/../uploads/' . basename($storedName);
                if (ensureUploadsProtection() && move_uploaded_file($file['tmp_name'], $targetPath)) {
                    $vid = createVersion($db, $id, $versionNo, 'single', $storedName, null, $file['name'], $versionNote);
                    activateVersion($db, $id, $vid);
                    $changes[] = 'Version ' . $versionNo . ' angelegt und angezeigt (HTML-Datei)';
                } else {
                    $error = 'Die neue Datei konnte nicht gespeichert werden (Schreibrechte im uploads-Ordner prüfen).';
                }
            }
        }

        if (!$error) {
            $success = $changes ? implode(' · ', $changes) : 'Keine Änderungen vorgenommen.';
            // Frische Daten laden
            $stmt = $db->prepare("SELECT * FROM offers WHERE id = ?");
            $stmt->execute([$id]);
            $offer = $stmt->fetch();
        }
    }
}

$offerLink = BASE_URL . 'a/' . $offer['slug'];
$isBundle  = (($offer['bundle_type'] ?? 'single') === 'bundle');
$kindValue = offerKind($offer);

// Bestehendes mehrseitiges Angebot, aber noch nie geprüft, wie die Dateien
// ausgeliefert werden können? Dann jetzt einmalig testen.
$deliveryInfo = null;
if ($isBundle) {
    if (getSetting($db, 'bundle_delivery', null) === null) {
        $deliveryInfo = bundleDetectDelivery($db, $offer['slug'], (string)($offer['entry_file'] ?? 'index.html'));
    }
    $deliveryMode = getSetting($db, 'bundle_delivery', null);
}
$pages     = $isBundle ? bundleListPages((string)$offer['filename']) : [];
$versions  = versionsForOffer($db, $id);
$currentVersionId = (int)($offer['current_version_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bearbeiten: <?= e($offer['title']) ?> — <?= e(BRAND_NAME) ?></title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="admin-wrap">
    <div class="admin-header">
        <div>
            <a href="stats.php?id=<?= $id ?>" class="btn btn-ghost btn-sm" style="margin-bottom:.8rem">← Zur Statistik</a>
            <h1>Angebot bearbeiten</h1>
        </div>
    </div>

    <div class="card">
        <?php if ($error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= e($success) ?></div>
        <?php endif; ?>

        <div class="form-group" style="margin-bottom:1.5rem">
            <label>Link (bleibt bei Änderungen immer gleich)</label>
            <div class="link-box">
                <input type="text" value="<?= e($offerLink) ?>" readonly id="lnk">
                <button type="button" class="btn btn-primary btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('lnk').value)">Kopieren</button>
            </div>
        </div>

        <form method="post" enctype="multipart/form-data">
            <?= csrfField() ?>

            <div class="form-group">
                <label>Titel</label>
                <input type="text" name="title" value="<?= e($offer['title']) ?>" required>
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
                    <input type="text" name="kind_label" id="kindInput" maxlength="40" value="<?= e($kindValue) ?>"
                           style="flex:1 1 200px;padding:.7rem 1rem;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg);color:var(--text);font-size:.95rem">
                </div>
                <p class="form-hint">So heißt es für den Kunden: „<span id="kindPreview"><?= e($kindValue) ?></span> ansehen".</p>
            </div>

            <div class="form-group">
                <label>Überschrift auf der Passwort-Seite (optional)</label>
                <input type="text" name="heading" maxlength="80" placeholder="<?= e(BRAND_NAME) ?>" value="<?= e((string)($offer['heading'] ?? '')) ?>">
                <p class="form-hint">Steht oben auf der Passwort-Seite. Leer = „<?= e(BRAND_NAME) ?>".</p>
            </div>

            <div class="form-group">
                <label>Passwort</label>
                <?php if (offerHasPassword($offer)): ?>
                    <p class="form-hint" style="margin-bottom:.5rem">Aktuell: <strong>mit Passwort geschützt</strong>.</p>
                    <input type="text" name="password" placeholder="Neues Passwort – leer lassen = unverändert" id="pwInput">
                    <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;margin-top:.6rem">
                        <input type="checkbox" name="remove_password" value="1" id="pwRemove" style="width:auto">
                        Passwort entfernen – Link öffnet das Angebot direkt
                    </label>
                    <p class="form-hint">Das alte Passwort wird nirgends im Klartext gespeichert.</p>
                <?php else: ?>
                    <p class="form-hint" style="margin-bottom:.5rem">Aktuell: <strong>ohne Passwort</strong> – der Link öffnet das Angebot direkt.</p>
                    <input type="text" name="password" placeholder="Passwort eingeben, um den Zugang zu schützen (optional)">
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label>Gültig bis (optional)</label>
                <input type="date" name="expires_at" value="<?= e(!empty($offer['expires_at']) ? date('Y-m-d', strtotime($offer['expires_at'])) : '') ?>" style="width:100%;padding:.7rem 1rem;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg);color:var(--text);font-size:.95rem">
                <p class="form-hint">Nach diesem Datum ist der Link nicht mehr abrufbar. Feld leeren = unbegrenzt gültig.</p>
            </div>

            <?php if ($isBundle): ?>
                <?php if (!empty($deliveryMode)): ?>
                    <p class="form-hint" style="margin-bottom:1rem">
                        Auslieferung der Unterseiten/Stylesheets: <strong><?= $deliveryMode === 'pathinfo' ? 'file.php/… (PATH_INFO)' : '/f/… (mod_rewrite)' ?></strong> — getestet und funktionsfähig.
                    </p>
                <?php elseif ($deliveryInfo !== null): ?>
                    <div class="alert alert-error">
                        <strong>Die Unterseiten und Stylesheets sind nicht erreichbar</strong> — das Angebot
                        erscheint beim Kunden ohne Design.
                        <?php if ($deliveryInfo['tested']): ?>
                            Getestet wurden <code>/f/…</code> (Status <?= (int)$deliveryInfo['rewrite'] ?>)
                            und <code>file.php/…</code> (Status <?= (int)$deliveryInfo['pathinfo'] ?>).
                            Bitte prüfen, ob die <code>.htaccess</code> aus dem Update im Hauptordner liegt.
                        <?php else: ?>
                            Der Selbsttest war nicht möglich (der Server erlaubt keine Anfrage an sich selbst).
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($isBundle && count($pages) > 1): ?>
            <div class="form-group">
                <label>Startseite</label>
                <select name="entry_file" style="width:100%;padding:.7rem 1rem;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg);color:var(--text);font-size:.95rem">
                    <?php foreach ($pages as $pg): ?>
                        <option value="<?= e($pg) ?>" <?= $pg === ($offer['entry_file'] ?? '') ? 'selected' : '' ?>><?= e($pg) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="form-hint">Diese Seite sieht der Kunde als Erstes. Alle anderen Seiten bleiben über die Links im Angebot erreichbar.</p>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label>Neue Version hochladen (HTML oder ZIP)</label>
                <div class="file-drop" id="fileDrop">
                    <input type="file" name="htmlfile" id="htmlfile" accept=".html,.htm,.zip">
                    <div class="file-label">Neue Datei hierher ziehen oder klicken (optional)</div>
                    <div class="file-name" id="fileName"></div>
                </div>
                <input type="text" name="version_note" maxlength="200" placeholder="Was ist neu? (optional, z.B. „Preise angepasst")" style="margin-top:.6rem">
                <p class="form-hint">
                    Aktuell angezeigt: <strong><?= e($offer['original_filename']) ?></strong>
                    <?php if ($isBundle): ?>
                        — mehrseitiges Angebot mit <?= count($pages) ?> Seite(n), Start: <code><?= e((string)($offer['entry_file'] ?? '')) ?></code>
                    <?php else: ?>
                        — einzelne HTML-Seite
                    <?php endif; ?>.<br>
                    Du kannst jederzeit zwischen einzelner HTML-Datei und ZIP (mehrere Seiten) wechseln.
                    Der neue Inhalt wird als <strong>neue Version</strong> gespeichert und sofort angezeigt;
                    ältere Versionen bleiben unten im Verlauf erhalten. Link, Passwort und Statistik bleiben gleich.
                </p>
            </div>

            <button type="submit" class="btn btn-primary btn-full">Änderungen speichern</button>
        </form>
    </div>

    <div class="card">
        <div class="card-header"><h2>Versionsverlauf</h2></div>
        <?php if (empty($versions)): ?>
            <p class="form-hint">Für dieses Angebot gibt es noch keine Versionsdaten. Beim nächsten Hochladen eines neuen Inhalts beginnt der Verlauf.</p>
        <?php else: ?>
            <p class="form-hint" style="margin-bottom:1rem">
                Der Kunde sieht immer die <strong>angezeigte</strong> Version (standardmäßig die neueste).
                Mit „Vorschau" siehst du dir eine Version an, ohne dass sich für den Kunden etwas ändert;
                mit „Anzeigen" schaltest du um – der Link bleibt derselbe.
            </p>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Version</th>
                            <th>Stand</th>
                            <th>Datei</th>
                            <th>Hinweis</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($versions as $v): $isCurrent = (int)$v['id'] === $currentVersionId; ?>
                        <tr<?= $isCurrent ? '' : ' style="opacity:.7"' ?>>
                            <td><strong>Version <?= (int)$v['version_no'] ?></strong></td>
                            <td><?= date('d.m.Y H:i', strtotime($v['created_at'])) ?></td>
                            <td>
                                <?= e((string)$v['original_filename']) ?>
                                <span style="font-size:.75rem;color:var(--text-muted)">
                                    · <?= $v['bundle_type'] === 'bundle' ? 'mehrseitig' . (!empty($v['entry_file']) ? ', Start: ' . e((string)$v['entry_file']) : '') : 'einzelne HTML-Seite' ?>
                                </span>
                            </td>
                            <td><?= $v['note'] !== null && $v['note'] !== '' ? e((string)$v['note']) : '<span style="color:var(--text-muted)">–</span>' ?></td>
                            <td>
                                <?php if ($isCurrent): ?>
                                    <span class="badge badge-active">Wird angezeigt</span>
                                <?php else: ?>
                                    <span class="badge badge-inactive">Nicht aktiv</span>
                                <?php endif; ?>
                            </td>
                            <td style="white-space:nowrap">
                                <a href="preview.php?id=<?= $id ?>&amp;version=<?= (int)$v['id'] ?>" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">Vorschau</a>
                                <?php if (!$isCurrent): ?>
                                    <form method="post" style="display:inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="activate_version">
                                        <input type="hidden" name="version_id" value="<?= (int)$v['id'] ?>">
                                        <button type="submit" class="btn btn-primary btn-sm">Anzeigen</button>
                                    </form>
                                    <form method="post" style="display:inline" onsubmit="return confirm('Version <?= (int)$v['version_no'] ?> endgültig löschen?')">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete_version">
                                        <input type="hidden" name="version_id" value="<?= (int)$v['id'] ?>">
                                        <button type="submit" class="btn btn-ghost btn-sm">Löschen</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    const rm = document.getElementById('pwRemove');
    const pw = document.getElementById('pwInput');
    if (rm && pw) rm.addEventListener('change', function () { pw.disabled = rm.checked; if (rm.checked) pw.value = ''; });
})();
(function () {
    const sel = document.getElementById('kindSelect');
    const inp = document.getElementById('kindInput');
    const pv  = document.getElementById('kindPreview');
    if (!sel || !inp) return;
    function preview() { if (pv) pv.textContent = inp.value.trim() || 'Angebot'; }
    sel.addEventListener('change', function () {
        if (sel.value !== '__custom') { inp.value = sel.value; } else { inp.value = ''; inp.focus(); }
        preview();
    });
    inp.addEventListener('input', function () {
        const match = Array.from(sel.options).some(o => o.value === inp.value);
        sel.value = match ? inp.value : '__custom';
        preview();
    });
})();
const drop = document.getElementById('fileDrop');
const input = document.getElementById('htmlfile');
const nameEl = document.getElementById('fileName');
drop.addEventListener('click', () => input.click());
input.addEventListener('change', () => { if (input.files.length) nameEl.textContent = input.files[0].name; });
['dragover','dragenter'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('drag-over'); }));
['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('drag-over'); }));
drop.addEventListener('drop', e => {
    if (e.dataTransfer.files.length) { input.files = e.dataTransfer.files; nameEl.textContent = e.dataTransfer.files[0].name; }
});
</script>
</body>
</html>
