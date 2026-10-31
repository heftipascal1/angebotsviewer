<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/version.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/migrations.php';
require_once __DIR__ . '/../includes/reminders.php';
requireAdmin();

$db = getDB();
redirectIfMigrationPending($db);
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
    } elseif ($action === 'save_reminders') {
        setSetting($db, 'reminder_enabled', isset($_POST['reminder_enabled']) ? '1' : '0');
        setSetting($db, 'reminder_unopened_days', (string)max(1, min(60, (int)($_POST['reminder_unopened_days'] ?? 3))));
        setSetting($db, 'reminder_expiry_days', (string)max(1, min(30, (int)($_POST['reminder_expiry_days'] ?? 2))));
        $msg = 'Erinnerungen gespeichert.';
    } elseif ($action === 'run_reminders') {
        $r = runReminders($db);
        setSetting($db, 'reminder_last_run', (string)time());
        if ($r['error']) {
            $msg = $r['error'];
            $msgType = 'error';
        } elseif ($r['sent']) {
            $msg = 'Erinnerung verschickt: ' . $r['unopened'] . ' ungeöffnet, ' . $r['expiring'] . ' läuft bald ab.';
        } else {
            $msg = 'Geprüft – gerade ist nichts fällig.';
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
$reminders = reminderSettings($db);
$cronUrl = BASE_URL . 'cron.php?key=' . cronKey($db);
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
        <div class="card-header"><h2>Erinnerungen</h2></div>
        <p class="form-hint" style="margin-bottom:1.5rem">
            Du bekommst eine E-Mail an die Benachrichtigungs-Adresse oben, wenn ein Angebot
            nach einigen Tagen noch nicht geöffnet wurde oder bald abläuft – jeweils nur einmal pro Angebot.
        </p>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_reminders">
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer">
                    <input type="checkbox" name="reminder_enabled" value="1" <?= $reminders['enabled'] ? 'checked' : '' ?> style="width:auto">
                    Erinnerungen aktivieren
                </label>
            </div>
            <div class="form-group">
                <label>„Noch nicht geöffnet" nach … Tagen</label>
                <input type="number" name="reminder_unopened_days" min="1" max="60" value="<?= (int)$reminders['unopened_days'] ?>">
            </div>
            <div class="form-group">
                <label>„Läuft bald ab" … Tage vor dem Ablaufdatum</label>
                <input type="number" name="reminder_expiry_days" min="1" max="30" value="<?= (int)$reminders['expiry_days'] ?>">
            </div>
            <button type="submit" class="btn btn-primary">Speichern</button>
        </form>

        <hr style="border:none;border-top:1px solid var(--border);margin:1.5rem 0">

        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="run_reminders">
            <button type="submit" class="btn btn-ghost">Jetzt prüfen</button>
        </form>
        <p class="form-hint" style="margin-top:1rem">
            Geprüft wird automatisch höchstens einmal pro Stunde, sobald du das Dashboard öffnest oder ein Kunde
            ein Angebot aufruft. Für pünktliche Erinnerungen kannst du zusätzlich einen Cronjob
            (bei All-Inkl im KAS unter „Tools → Cronjobs") auf diese Adresse einrichten, z.&nbsp;B. stündlich:
        </p>
        <div class="link-box" style="margin-top:.5rem">
            <input type="text" value="<?= e($cronUrl) ?>" readonly id="cronUrl">
            <button type="button" class="btn btn-primary btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('cronUrl').value)">Kopieren</button>
        </div>
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
