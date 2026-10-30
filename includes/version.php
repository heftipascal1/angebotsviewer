<?php
/**
 * Zentrale Versions- & Branding-Info.
 * APP_VERSION  = Version des Tools (bei jedem Release erhöhen)
 * DB_VERSION   = Ziel-Schema-Version. Erhöhen, sobald in migrations.php
 *                eine neue Migration ergänzt wird.
 * BRAND_NAME   = Anzeigename überall (Kunden sollen NICHT merken, dass
 *                getrackt wird – daher neutraler Name).
 * UPDATE_PACKAGE_NAME = Dateiname-Präfix der Update-ZIPs (angebotsviewer-vX.Y.Z.zip).
 * UPDATE_ZIP_PATTERN = erlaubtes Namensmuster für Update-ZIPs. Der Updater
 *                spielt NUR ZIPs mit passendem Dateinamen ein.
 * UPDATE_GITHUB_REPO = GitHub-Repo, dessen Releases als Update-Quelle dienen.
 */
define('APP_VERSION', '1.8.1');
define('DB_VERSION', 7);
define('BRAND_NAME', 'Heftis Angebote');
// Paketname der Update-ZIPs: angebotsviewer-vX.Y.Z.zip. Der alte Name
// heftis-angebote-vX.Y.Z.zip wird übergangsweise weiterhin akzeptiert.
define('UPDATE_PACKAGE_NAME', 'angebotsviewer');
define('UPDATE_ZIP_PATTERN', '/^(angebotsviewer|heftis-angebote)-v\d+\.\d+\.\d+\.zip$/i');
// GitHub-Repository (owner/repo), aus dessen Releases der Updater neue
// Versionen holt. Leer lassen = nur Update per ZIP-Upload.
define('UPDATE_GITHUB_REPO', 'heftipascal1/angebotsviewer');
