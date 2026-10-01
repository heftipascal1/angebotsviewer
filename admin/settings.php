<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/version.php';
require_once __DIR__ . '/../includes/mailer.php';
requireAdmin();

$db = getDB();
$msg = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        $email = trim($_POST['notify_email'] ?? '');
        $enabled = isset($_POST['notify_enabled']) ? '1' : '0';

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = 'Bitte eine gültige E-Mail-Adresse eingeben.';
            $msgType = 'error';
        } else {
            setSetting($db, 'notify_email', $email);
            setSetting($db, 'notify_enabled', $enabled);
            $msg = 'Einstellungen gespeichert.';
        }
    } elseif ($action === 'save_template') {
        $tpl = (string)($_POST['message_template'] ?? '');
        setSetting($db, 'message_template', $tpl);
        $msg = 'Nachrichten-Vorlage gespeichert.';
    } elseif ($action === 'test') {
        $email = trim((string)getSetting($db, 'notify_email', ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = 'Bitte zuerst eine gültige E-Mail speichern.';
            $msgType = 'error';
        } else {
            $ok = sendMailUtf8(
                $email,
                'Test – ' . BRAND_NAME,
                "Das ist eine Test-Benachrichtigung.\n\nWenn du diese E-Mail erhältst, "
                . "funktioniert der Versand.\n\n" . BRAND_NAME
            );
            $msg = $ok
                ? 'Test-E-Mail wurde verschickt. Schau in dein Postfach (ggf. Spam-Ordner).'
                : 'Versand fehlgeschlagen. Auf manchen Servern ist mail() eingeschränkt.';
            $msgType = $ok ? 'success' : 'error';
        }
    }
}

$notifyEmail = (string)getSetting($db, 'notify_email', '');
$notifyEnabled = getSetting($db, 'notify_enabled', '0') === '1';
$messageTemplate = getSetting($db, 'message_template', '') ?: defaultMessageTemplate();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Einstellungen — <?= e(BRAND_NAME) ?></title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="admin-wrap">
    <div class="admin-header">
        <div>
            <a href="index.php" class="btn btn-ghost btn-sm" style="margin-bottom:.8rem">← Dashboard</a>
            <h1>Einstellungen</h1>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="card"><div class="alert alert-<?= $msgType === 'error' ? 'error' : 'success' ?>"><?= e($msg) ?></div></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h2>E-Mail-Benachrichtigung</h2></div>
        <p class="form-hint" style="margin-bottom:1.5rem">
            Erhalte eine E-Mail, sobald eines deiner Angebote geöffnet wird –
            inkl. Zeitpunkt und Gerät.
        </p>

        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save">

            <div class="form-group">
                <label>Benachrichtigungs-E-Mail</label>
                <input type="text" name="notify_email" value="<?= e($notifyEmail) ?>" placeholder="du@deine-domain.de">
                <p class="form-hint">An diese Adresse werden die Benachrichtigungen geschickt.</p>
            </div>

            <div class="form-group">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer">
                    <input type="checkbox" name="notify_enabled" value="1" <?= $notifyEnabled ? 'checked' : '' ?> style="width:auto">
                    Benachrichtigungen aktivieren
                </label>
            </div>

            <button type="submit" class="btn btn-primary">Speichern</button>
        </form>

        <hr style="border:none;border-top:1px solid var(--border);margin:1.5rem 0">

        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="test">
            <button type="submit" class="btn btn-ghost">Test-E-Mail senden</button>
            <p class="form-hint" style="margin-top:.5rem">
                Sendet eine Test-Nachricht an die gespeicherte Adresse, um den Versand zu prüfen.
            </p>
        </form>
    </div>

    <div class="card">
        <div class="card-header"><h2>Nachrichten-Vorlage (Copy-Paste)</h2></div>
        <p class="form-hint" style="margin-bottom:1rem">
            Diese Vorlage wird beim Erstellen eines Angebots und in der Statistik als
            fertige Nachricht zum Kopieren angezeigt. Verfügbare Platzhalter:
            <code>{titel}</code>, <code>{link}</code>, <code>{passwort}</code>, <code>{gueltig_bis}</code>,
            <code>{typ}</code> (die gewählte Bezeichnung, z.B. Angebot oder Webseite).
        </p>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_template">
            <div class="form-group">
                <textarea name="message_template" style="width:100%;min-height:220px;padding:.8rem 1rem;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg);color:var(--text);font-size:.9rem;line-height:1.5;font-family:inherit;resize:vertical"><?= e($messageTemplate) ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Vorlage speichern</button>
        </form>
    </div>
</div>
</body>
</html>
