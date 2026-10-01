<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/version.php';
require_once __DIR__ . '/../includes/migrations.php';
require_once __DIR__ . '/../includes/updater.php';
requireAdmin();

$db = getDB();
$applied = [];
$error = '';
$uploadResult = null;

$appRoot = dirname(__DIR__);
$dbVersion = getDbVersion($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'migrate') {
    verifyCsrf();
    try {
        $applied = runMigrations($db);
        $dbVersion = getDbVersion($db);
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}

// ===== Update per ZIP-Upload (mit Dateinamen-Prüfung) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_zip') {
    verifyCsrf();
    if (!isset($_FILES['zip']) || $_FILES['zip']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Bitte eine ZIP-Datei auswählen.';
    } else {
        $origName = $_FILES['zip']['name'];
        // *** Sicherheits-Gate: NUR bei korrektem Dateinamen updaten ***
        if (!validateUpdateZipName($origName)) {
            $error = 'Update abgelehnt: Falscher Dateiname „' . $origName . '". '
                   . 'Erlaubt ist nur ein Paket wie z.B. heftis-angebote-v1.2.0.zip.';
        } else {
            $uploadResult = applyUpdateZip($_FILES['zip']['tmp_name'], $appRoot);
            if (empty($uploadResult['errors'])) {
                // Neuer Code liegt jetzt auf der Platte → frisch laden lassen,
                // damit anschließend evtl. Migrationen mit neuer DB_VERSION greifen.
                header('Location: update.php?updated=' . $uploadResult['copied']);
                exit;
            }
        }
    }
}
// ===== Update direkt aus GitHub-Releases =====
$ghRelease = null;   // Ergebnis von "Nach Updates suchen"
$ghError   = '';
if (($_GET['check'] ?? '') === 'github') {
    $ghRelease = githubFetchRelease();
    if (isset($ghRelease['error'])) {
        $ghError = $ghRelease['error'];
        $ghRelease = null;
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'github_install') {
    verifyCsrf();
    $tag = trim((string)($_POST['tag'] ?? ''));
    if (!preg_match('/^v?\d+\.\d+\.\d+$/', $tag)) {
        $error = 'Ungültige Release-Angabe.';
    } else {
        $uploadResult = githubInstallRelease($tag, $appRoot);
        if (empty($uploadResult['errors'])) {
            header('Location: update.php?updated=' . $uploadResult['copied'] . '&from=github');
            exit;
        }
    }
}
$justUpdated = isset($_GET['updated']) ? (int)$_GET['updated'] : null;

$needsMigration = $dbVersion < DB_VERSION;

// Anzahl bestehender Angebote/Aufrufe — zur Beruhigung anzeigen
$offerCount = (int)$db->query("SELECT COUNT(*) FROM offers WHERE is_active = 1")->fetchColumn();
$viewCount  = (int)$db->query("SELECT COUNT(*) FROM offer_views")->fetchColumn();

// Schreibrechte uploads prüfen
$uploadsWritable = is_writable(__DIR__ . '/../uploads');
$installerPresent = is_file(__DIR__ . '/../install.php');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update — <?= e(BRAND_NAME) ?></title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="admin-wrap">
    <div class="admin-header">
        <div>
            <a href="index.php" class="btn btn-ghost btn-sm" style="margin-bottom:.8rem">← Dashboard</a>
            <h1>Update &amp; Wartung</h1>
        </div>
    </div>

    <div class="stat-grid">
        <div class="stat-box">
            <div class="stat-value" style="font-size:1.5rem"><?= e(APP_VERSION) ?></div>
            <div class="stat-label">Tool-Version</div>
        </div>
        <div class="stat-box">
            <div class="stat-value" style="font-size:1.5rem"><?= $dbVersion ?> / <?= DB_VERSION ?></div>
            <div class="stat-label">DB-Schema</div>
        </div>
        <div class="stat-box">
            <div class="stat-value" style="font-size:1.5rem"><?= $offerCount ?></div>
            <div class="stat-label">Angebote (bleiben erhalten)</div>
        </div>
        <div class="stat-box">
            <div class="stat-value" style="font-size:1.5rem"><?= $viewCount ?></div>
            <div class="stat-label">Aufrufe (bleiben erhalten)</div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="card"><div class="alert alert-error"><?= e($error) ?></div></div>
    <?php endif; ?>

    <?php if ($applied): ?>
        <div class="card"><div class="alert alert-success">
            Datenbank erfolgreich aktualisiert auf Version <?= $dbVersion ?>
            (angewendet: <?= e(implode(', ', $applied)) ?>). Deine Angebote und Statistiken sind unverändert.
        </div></div>
    <?php endif; ?>

    <?php if ($justUpdated !== null): ?>
        <div class="card"><div class="alert alert-success">
            Update <?= ($_GET['from'] ?? '') === 'github' ? 'von GitHub ' : '' ?>eingespielt: <?= $justUpdated ?> Dateien aktualisiert.
            <code>config.php</code> und der Ordner <code>uploads/</code> wurden bewusst nicht angefasst.
            <?php if ($needsMigration): ?>
                <br><br>Es steht noch eine Datenbank-Anpassung an — siehe unten.
            <?php endif; ?>
        </div></div>
    <?php endif; ?>

    <?php if ($uploadResult !== null && !empty($uploadResult['errors'])): ?>
        <div class="card"><div class="alert alert-error">
            <strong>Beim Update sind Probleme aufgetreten:</strong><br>
            <?= implode('<br>', array_map('htmlspecialchars', $uploadResult['errors'])) ?>
        </div></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h2>Update über GitHub</h2></div>
        <?php if (!githubUpdateAvailable()): ?>
            <p class="form-hint">Auf diesem Server sind Internet-Downloads aus PHP nicht erlaubt. Bitte das Update-Paket unten als ZIP hochladen.</p>
        <?php else: ?>
            <p class="form-hint" style="margin-bottom:1rem">
                Prüft das Repository <code><?= e(UPDATE_GITHUB_REPO) ?></code> auf ein neueres Release
                und spielt es mit einem Klick ein. <code>config.php</code> und <code>uploads/</code>
                bleiben auch hier unangetastet.
            </p>
            <?php if ($ghError): ?>
                <div class="alert alert-error"><?= e($ghError) ?></div>
            <?php endif; ?>
            <?php if ($ghRelease !== null): ?>
                <?php if (githubIsNewer($ghRelease)): ?>
                    <div class="alert alert-success">
                        <strong>Neue Version verfügbar: <?= e($ghRelease['version']) ?></strong>
                        (installiert: <?= e(APP_VERSION) ?>)
                        <?php if ($ghRelease['published']): ?> · veröffentlicht am <?= e(date('d.m.Y', strtotime($ghRelease['published']))) ?><?php endif; ?>
                        <br>Paket: <code><?= e($ghRelease['asset_name']) ?></code>
                        <?php if ($ghRelease['asset_size']): ?> (<?= round($ghRelease['asset_size'] / 1024) ?> KB)<?php endif; ?>
                        <?php if ($ghRelease['url']): ?> · <a href="<?= e($ghRelease['url']) ?>" target="_blank" rel="noopener" style="color:inherit">Release auf GitHub ansehen</a><?php endif; ?>
                    </div>
                    <?php if (trim($ghRelease['notes']) !== ''): ?>
                        <pre style="white-space:pre-wrap;font-family:inherit;font-size:.85rem;color:var(--text-muted);background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);padding:.8rem 1rem;max-height:220px;overflow:auto"><?= e($ghRelease['notes']) ?></pre>
                    <?php endif; ?>
                    <form method="post" style="margin-top:1rem">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="github_install">
                        <input type="hidden" name="tag" value="<?= e($ghRelease['tag']) ?>">
                        <button type="submit" class="btn btn-primary">Version <?= e($ghRelease['version']) ?> jetzt herunterladen &amp; einspielen</button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-success">
                        Du bist auf dem aktuellen Stand (installiert <?= e(APP_VERSION) ?>, neuestes Release <?= e($ghRelease['version']) ?>).
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            <a href="update.php?check=github" class="btn <?= $ghRelease === null ? 'btn-primary' : 'btn-ghost' ?>" style="margin-top:.5rem">Nach Updates suchen</a>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header"><h2>Update einspielen (ZIP)</h2></div>
        <p class="form-hint" style="margin-bottom:1rem">
            Lade hier das neue Update-Paket hoch. Aus Sicherheitsgründen wird das Update
            <strong>nur akzeptiert, wenn der Dateiname stimmt</strong>
            (Format: <code>heftis-angebote-vX.Y.Z.zip</code>).
            <code>config.php</code> und deine Angebote in <code>uploads/</code> werden dabei
            <strong>niemals</strong> überschrieben.
        </p>
        <form method="post" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="upload_zip">
            <div class="form-group">
                <div class="file-drop" id="zipDrop">
                    <input type="file" name="zip" id="zipfile" accept=".zip" required>
                    <div class="file-label">ZIP hierher ziehen oder klicken</div>
                    <div class="file-name" id="zipName"></div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Update prüfen &amp; einspielen</button>
        </form>
    </div>

    <div class="card">
        <div class="card-header"><h2>Datenbank-Status</h2></div>
        <?php if ($needsMigration): ?>
            <div class="alert alert-error" style="background:rgba(120,53,15,.4);color:#fde68a">
                Es gibt ausstehende Datenbank-Anpassungen (Schema <?= $dbVersion ?> → <?= DB_VERSION ?>).
                Klicke unten, um sie sicher einzuspielen. <strong>Bestehende Angebote werden dabei nicht verändert.</strong>
            </div>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="migrate">
                <button type="submit" class="btn btn-primary">Datenbank jetzt aktualisieren</button>
            </form>
        <?php else: ?>
            <div class="alert alert-success">Datenbank ist auf dem aktuellen Stand. Nichts zu tun.</div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header"><h2>So aktualisierst du das Tool</h2></div>
        <p class="form-hint" style="margin-bottom:.8rem">
            Am einfachsten oben über <strong>„Nach Updates suchen"</strong> (GitHub) oder per
            ZIP-Upload. Der manuelle Weg per FTP geht weiterhin:
        </p>
        <ol style="padding-left:1.2rem;line-height:2;color:var(--text)">
            <li>Neue Version (ZIP) lokal entpacken.</li>
            <li>Per FTP <strong>alle Dateien überschreiben</strong> —
                <strong style="color:var(--success)">außer</strong> dem Ordner
                <code>uploads/</code> und der Datei <code>config.php</code>.
                Diese beiden enthalten deine Angebote &amp; Zugangsdaten und dürfen
                <strong>nicht</strong> überschrieben werden.</li>
            <li>Diese Seite (<code>update.php</code>) neu laden und – falls angezeigt –
                auf „Datenbank jetzt aktualisieren" klicken.</li>
        </ol>
        <p class="form-hint" style="margin-top:1rem">
            Tipp: Wenn du auf Nummer sicher gehen willst, lade vor dem Update einmal
            den Ordner <code>uploads/</code> und exportiere deine Datenbank im KAS
            (Backup). Nötig ist es dank des additiven Migrationssystems nicht,
            aber ein Backup schadet nie.
        </p>
    </div>

    <div class="card">
        <div class="card-header"><h2>Sicherheits-Check</h2></div>
        <table>
            <tr>
                <td>uploads/ beschreibbar</td>
                <td><?= $uploadsWritable
                    ? '<span class="badge badge-active">OK</span>'
                    : '<span class="badge badge-inactive">Nicht beschreibbar</span>' ?></td>
            </tr>
            <tr>
                <td>install.php entfernt</td>
                <td><?= $installerPresent
                    ? '<span class="badge badge-inactive">Noch vorhanden – bitte löschen!</span>'
                    : '<span class="badge badge-active">OK</span>' ?></td>
            </tr>
        </table>
    </div>
</div>
<script>
(function(){
    var drop = document.getElementById('zipDrop');
    var input = document.getElementById('zipfile');
    var nameEl = document.getElementById('zipName');
    if (!drop) return;
    drop.addEventListener('click', function(){ input.click(); });
    input.addEventListener('change', function(){ if (input.files.length) nameEl.textContent = input.files[0].name; });
    ['dragover','dragenter'].forEach(function(ev){ drop.addEventListener(ev, function(e){ e.preventDefault(); drop.classList.add('drag-over'); }); });
    ['dragleave','drop'].forEach(function(ev){ drop.addEventListener(ev, function(e){ e.preventDefault(); drop.classList.remove('drag-over'); }); });
    drop.addEventListener('drop', function(e){ if (e.dataTransfer.files.length) { input.files = e.dataTransfer.files; nameEl.textContent = e.dataTransfer.files[0].name; } });
})();
</script>
</body>
</html>
