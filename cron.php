<?php
/**
 * Cronjob-Endpunkt für Erinnerungen: cron.php?key={geheimer Schlüssel}
 * Den Link findest du im Admin unter Einstellungen → Erinnerungen.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/version.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/migrations.php';
require_once __DIR__ . '/includes/reminders.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$db = getDB();
$key = (string)($_GET['key'] ?? '');
if ($key === '' || !hash_equals(cronKey($db), $key)) {
    http_response_code(403);
    echo "Zugriff verweigert.\n";
    exit;
}
if (getDbVersion($db) < DB_VERSION) {
    echo "Datenbank-Aktualisierung ausstehend – bitte im Admin unter Update ausführen.\n";
    exit;
}
if (!reminderSettings($db)['enabled']) {
    echo "Erinnerungen sind ausgeschaltet.\n";
    exit;
}
// Schutz vor zu häufigem Aufruf: höchstens alle 10 Minuten
$last = (int)getSetting($db, 'reminder_last_run', '0');
if ($last > time() - 600) {
    echo "Kürzlich geprüft – nichts zu tun.\n";
    exit;
}
setSetting($db, 'reminder_last_run', (string)time());
$r = runReminders($db);
echo $r['error']
    ? 'Fehler: ' . $r['error'] . "\n"
    : ($r['sent'] ? "Erinnerung verschickt: {$r['unopened']} ungeöffnet, {$r['expiring']} läuft bald ab.\n" : "Nichts fällig.\n");
