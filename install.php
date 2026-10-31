<?php
/**
 * Installer für eine NEUE Installation.
 *
 *  1. Prüft die Server-Voraussetzungen
 *  2. Legt die Datenbank-Tabellen an (Grundschema + alle Migrationen)
 *  3. Schreibt config.php mit Admin-Passwort und geheimem Schlüssel
 *
 * Sobald config.php existiert, ist der Installer gesperrt und bietet nur
 * noch an, sich selbst zu löschen. Bei Updates wird diese Datei nie
 * eingespielt (der Updater überspringt install.php).
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/version.php';
require_once __DIR__ . '/includes/migrations.php';

secureSession();
if (empty($_SESSION['install_csrf'])) $_SESSION['install_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['install_csrf'];
$configPath = __DIR__ . '/config.php';
$installed  = is_file($configPath);

function installCsrfOk(string $csrf): bool {
    return hash_equals($csrf, (string)($_POST['csrf'] ?? ''));
}

// ===== Installer löschen (nur nach erfolgter Installation) =====
if ($installed && ($_POST['action'] ?? '') === 'delete_self' && installCsrfOk($csrf)) {
    $deleted = @unlink(__FILE__);
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/';
    if ($deleted) {
        header('Location: ' . $base);
        exit;
    }
    $deleteError = 'install.php konnte nicht gelöscht werden. Bitte per FTP entfernen.';
}

// ===== Voraussetzungen =====
$checks = [
    ['PHP 7.4 oder neuer', version_compare(PHP_VERSION, '7.4.0', '>='), true, 'gefunden: ' . PHP_VERSION],
    ['PDO MySQL',          extension_loaded('pdo_mysql'), true, 'für die Datenbank'],
    ['mbstring',           extension_loaded('mbstring'), true, 'für Umlaute in Texten'],
    ['OpenSSL',            extension_loaded('openssl'), true, 'für verschlüsselte Passwörter'],
    ['ZIP',                class_exists('ZipArchive'), false, 'für mehrseitige Angebote & Updates per ZIP'],
    ['curl oder allow_url_fopen', function_exists('curl_init') || ini_get('allow_url_fopen'), false, 'für Updates über GitHub'],
    ['uploads/ beschreibbar', is_writable(__DIR__ . '/uploads'), true, 'hier landen die Angebote'],
];
$requiredOk = !in_array(false, array_map(fn($c) => !$c[2] || $c[1], $checks), true);

// Basis-URL vorschlagen (Ordner, in dem install.php liegt)
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$suggestUrl = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
            . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';

$form = [
    'db_host'  => trim((string)($_POST['db_host'] ?? 'localhost')),
    'db_name'  => trim((string)($_POST['db_name'] ?? '')),
    'db_user'  => trim((string)($_POST['db_user'] ?? '')),
    'base_url' => trim((string)($_POST['base_url'] ?? $suggestUrl)),
];
$errors = [];
$done = false;
$configText = '';

/** Grundschema (Stand vor allen Migrationen). Alles Weitere legen die Migrationen an. */
function installBaseSchema(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS offers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            slug VARCHAR(32) NOT NULL,
            password_hash VARCHAR(255) NOT NULL DEFAULT '',
            filename VARCHAR(255) NOT NULL,
            original_filename VARCHAR(255) NOT NULL DEFAULT '',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS offer_views (
            id INT AUTO_INCREMENT PRIMARY KEY,
            offer_id INT NOT NULL,
            session_hash VARCHAR(64) NOT NULL,
            ip_hash VARCHAR(64) NOT NULL,
            user_agent VARCHAR(500) NULL,
            started_at DATETIME NOT NULL,
            last_heartbeat DATETIME NULL,
            duration_seconds INT NOT NULL DEFAULT 0,
            KEY idx_offer (offer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function installConfigText(array $f, string $dbPass, string $adminHash, string $secret): string {
    $q = fn($v) => var_export((string)$v, true);
    return "<?php\n"
        . "/**\n * Konfiguration – erzeugt vom Installer am " . date('d.m.Y H:i') . ".\n"
        . " * Diese Datei niemals weitergeben oder überschreiben.\n */\n\n"
        . "define('DB_HOST', " . $q($f['db_host']) . ");\n"
        . "define('DB_NAME', " . $q($f['db_name']) . ");\n"
        . "define('DB_USER', " . $q($f['db_user']) . ");\n"
        . "define('DB_PASS', " . $q($dbPass) . ");\n\n"
        . "// Admin-Passwort (bcrypt-Hash)\n"
        . "define('ADMIN_PASSWORD_HASH', " . $q($adminHash) . ");\n\n"
        . "// Geheimer Schlüssel für Sessions, Tokens und Verschlüsselung\n"
        . "define('APP_SECRET', " . $q($secret) . ");\n\n"
        . "// Basis-URL der Installation (mit / am Ende)\n"
        . "define('BASE_URL', " . $q($f['base_url']) . ");\n\n"
        . "date_default_timezone_set('Europe/Berlin');\n";
}

if (!$installed && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'install') {
    if (!installCsrfOk($csrf)) $errors[] = 'Sitzung abgelaufen – bitte die Seite neu laden.';
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $admin1 = (string)($_POST['admin_pass'] ?? '');
    $admin2 = (string)($_POST['admin_pass2'] ?? '');
    if (!$requiredOk) $errors[] = 'Nicht alle Voraussetzungen sind erfüllt (siehe oben).';
    if ($form['db_host'] === '' || $form['db_name'] === '' || $form['db_user'] === '') $errors[] = 'Bitte Datenbank-Host, -Name und -Benutzer ausfüllen.';
    if (strlen($admin1) < 8) $errors[] = 'Das Admin-Passwort muss mindestens 8 Zeichen haben.';
    if ($admin1 !== $admin2) $errors[] = 'Die beiden Admin-Passwörter stimmen nicht überein.';
    if (!preg_match('#^https?://[^\s]+/$#', $form['base_url'])) $errors[] = 'Die Basis-URL muss mit http(s):// beginnen und mit / enden.';

    if (!$errors) {
        try {
            $db = new PDO(
                'mysql:host=' . $form['db_host'] . ';dbname=' . $form['db_name'] . ';charset=utf8mb4',
                $form['db_user'], $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
            );
        } catch (Throwable $ex) {
            $errors[] = 'Verbindung zur Datenbank fehlgeschlagen: ' . $ex->getMessage();
        }
    }
    if (!$errors) {
        try {
            installBaseSchema($db);
            runMigrations($db);
        } catch (Throwable $ex) {
            $errors[] = 'Tabellen konnten nicht angelegt werden: ' . $ex->getMessage();
        }
    }
    if (!$errors) {
        $configText = installConfigText($form, $dbPass, password_hash($admin1, PASSWORD_BCRYPT), bin2hex(random_bytes(32)));
        if (@file_put_contents($configPath, $configText, LOCK_EX) !== false) {
            @chmod($configPath, 0640);
            $configText = '';
        }
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Installation — <?= e(BRAND_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="admin-wrap" style="max-width:720px">
    <div class="admin-header">
        <div>
            <h1><?= e(BRAND_NAME) ?> installieren</h1>
            <p class="form-hint">Version <?= e(APP_VERSION) ?></p>
        </div>
    </div>

<?php if ($installed && !$done): ?>
    <div class="card">
        <div class="alert alert-success">Das Tool ist bereits installiert (<code>config.php</code> existiert). Der Installer ist gesperrt.</div>
        <?php if (!empty($deleteError)): ?><div class="alert alert-error"><?= e($deleteError) ?></div><?php endif; ?>
        <p class="form-hint" style="margin-bottom:1rem">Aus Sicherheitsgründen solltest du <code>install.php</code> jetzt löschen.</p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="delete_self">
            <button type="submit" class="btn btn-primary">install.php jetzt löschen</button>
            <a href="./" class="btn btn-ghost">Zum Login</a>
        </form>
    </div>

<?php elseif ($done): ?>
    <div class="card">
        <div class="alert alert-success"><strong>Installation abgeschlossen.</strong> Datenbank angelegt (Schema <?= (int)DB_VERSION ?>).</div>
        <?php if ($configText !== ''): ?>
            <div class="alert alert-error">
                <code>config.php</code> konnte nicht geschrieben werden (keine Schreibrechte im Hauptordner).
                Bitte den folgenden Inhalt als <code>config.php</code> per FTP in den Hauptordner hochladen:
            </div>
            <textarea readonly style="width:100%;min-height:320px;font-family:monospace;font-size:.8rem;padding:.8rem;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg);color:var(--text)"><?= e($configText) ?></textarea>
        <?php else: ?>
            <p class="form-hint" style="margin-bottom:1rem"><code>config.php</code> wurde angelegt. Jetzt noch den Installer löschen, dann kannst du dich einloggen.</p>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="delete_self">
                <button type="submit" class="btn btn-primary">install.php löschen &amp; zum Login</button>
            </form>
        <?php endif; ?>
    </div>

<?php else: ?>
    <div class="card">
        <div class="card-header"><h2>1. Voraussetzungen</h2></div>
        <table>
            <?php foreach ($checks as [$label, $ok, $required, $hint]): ?>
                <tr>
                    <td><?= e($label) ?> <span style="font-size:.75rem;color:var(--text-muted)">· <?= e($hint) ?><?= $required ? '' : ' (optional)' ?></span></td>
                    <td style="text-align:right"><?= $ok
                        ? '<span class="badge badge-active">OK</span>'
                        : '<span class="badge badge-inactive">' . ($required ? 'Fehlt' : 'Fehlt – optional') . '</span>' ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="card">
        <div class="card-header"><h2>2. Datenbank &amp; Zugang</h2></div>
        <?php foreach ($errors as $err): ?>
            <div class="alert alert-error"><?= e($err) ?></div>
        <?php endforeach; ?>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="install">
            <div class="form-group">
                <label>Datenbank-Host</label>
                <input type="text" name="db_host" value="<?= e($form['db_host']) ?>" required>
                <p class="form-hint">Bei All-Inkl meist <code>localhost</code>.</p>
            </div>
            <div class="form-group">
                <label>Datenbank-Name</label>
                <input type="text" name="db_name" value="<?= e($form['db_name']) ?>" required>
            </div>
            <div class="form-group">
                <label>Datenbank-Benutzer</label>
                <input type="text" name="db_user" value="<?= e($form['db_user']) ?>" required>
            </div>
            <div class="form-group">
                <label>Datenbank-Passwort</label>
                <input type="password" name="db_pass">
            </div>
            <div class="form-group">
                <label>Admin-Passwort (mind. 8 Zeichen)</label>
                <input type="password" name="admin_pass" required minlength="8">
            </div>
            <div class="form-group">
                <label>Admin-Passwort wiederholen</label>
                <input type="password" name="admin_pass2" required minlength="8">
            </div>
            <div class="form-group">
                <label>Basis-URL</label>
                <input type="text" name="base_url" value="<?= e($form['base_url']) ?>" required>
                <p class="form-hint">Adresse dieses Ordners, mit <code>/</code> am Ende. Wurde automatisch vorgeschlagen.</p>
            </div>
            <button type="submit" class="btn btn-primary btn-full" <?= $requiredOk ? '' : 'disabled' ?>>Installieren</button>
        </form>
    </div>
<?php endif; ?>
</div>
</body>
</html>
