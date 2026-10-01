<?php
/**
 * Zentrale Versions- & Branding-Info.
 * APP_VERSION  = Version des Tools (bei jedem Release erhöhen)
 * DB_VERSION   = Ziel-Schema-Version. Erhöhen, sobald in migrations.php
 *                eine neue Migration ergänzt wird.
 * BRAND_NAME   = Anzeigename überall (Kunden sollen NICHT merken, dass
 *                getrackt wird – daher neutraler Name).
 * UPDATE_ZIP_PATTERN = erlaubtes Namensmuster für Update-ZIPs. Der Updater
 *                spielt NUR ZIPs mit passendem Dateinamen ein.
 * UPDATE_GITHUB_REPO = GitHub-Repo, dessen Releases als Update-Quelle dienen.
 */
define('APP_VERSION', '1.7.1');
define('DB_VERSION', 6);
define('BRAND_NAME', 'Heftis Angebote');
define('UPDATE_ZIP_PATTERN', '/^heftis-angebote-v\d+\.\d+\.\d+\.zip$/i');
// GitHub-Repository (owner/repo), aus dessen Releases der Updater neue
// Versionen holt. Leer lassen = nur Update per ZIP-Upload.
define('UPDATE_GITHUB_REPO', 'heftipascal1/angebotsviewer');
