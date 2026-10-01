<?php
/**
 * Konfiguration — kopiere diese Datei als config.php und passe die Werte an.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'dein_datenbankname');
define('DB_USER', 'dein_dbuser');
define('DB_PASS', 'dein_dbpasswort');

// Admin-Passwort (wird bei der Installation gesetzt)
define('ADMIN_PASSWORD_HASH', '');

// Geheimer Schluessel fuer CSRF-Token und Session-Sicherheit
define('APP_SECRET', '');

// Basis-URL deiner Installation (mit / am Ende)
define('BASE_URL', 'https://deine-domain.de/offer-tracker/');

// Zeitzone
date_default_timezone_set('Europe/Berlin');
