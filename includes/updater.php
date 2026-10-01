<?php
/**
 * Sicherer Selbst-Updater per ZIP-Upload.
 *
 * Sicherheitsregeln:
 *  - Es wird NUR aktualisiert, wenn der ZIP-Dateiname exakt zum erlaubten
 *    Muster passt (UPDATE_ZIP_PATTERN, z.B. heftis-angebote-v1.2.0.zip).
 *  - Das ZIP muss den Marker includes/version.php enthalten (kein Fremd-ZIP).
 *  - config.php, install.php und der komplette uploads/-Ordner werden
 *    NIEMALS überschrieben → bestehende Angebote & Zugangsdaten bleiben sicher.
 *  - Schutz gegen Path-Traversal (../).
 */

function validateUpdateZipName(string $filename): bool {
    return (bool)preg_match(UPDATE_ZIP_PATTERN, $filename);
}

/**
 * Spielt ein Update-ZIP ein. $appRoot = Installations-Wurzel.
 * Liefert ['copied'=>int, 'skipped'=>[], 'errors'=>[]].
 */
function applyUpdateZip(string $zipPath, string $appRoot): array {
    $result = ['copied' => 0, 'skipped' => [], 'errors' => []];

    if (!class_exists('ZipArchive')) {
        $result['errors'][] = 'ZipArchive ist auf diesem Server nicht verfügbar. Bitte per FTP aktualisieren.';
        return $result;
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        $result['errors'][] = 'Das ZIP konnte nicht geöffnet werden.';
        return $result;
    }

    // Marker-Prüfung: ist das wirklich ein Heftis-Angebote-Paket?
    $hasMarker = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        if (preg_match('#(^|/)includes/version\.php$#', $zip->getNameIndex($i))) {
            $hasMarker = true;
            break;
        }
    }
    if (!$hasMarker) {
        $zip->close();
        $result['errors'][] = 'Das ist kein gültiges Update-Paket (interner Marker fehlt).';
        return $result;
    }

    $skipBasenames = ['config.php', 'install.php'];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        if ($entry === false || substr($entry, -1) === '/') continue; // Ordner überspringen

        // Oberste Ordnerebene (z.B. "offer-tracker/") abschneiden
        $rel = $entry;
        $slash = strpos($entry, '/');
        if ($slash !== false) $rel = substr($entry, $slash + 1);
        if ($rel === '' || $rel === false) continue;

        // Path-Traversal verhindern
        if (strpos($rel, '..') !== false || strpos($rel, "\0") !== false) {
            $result['errors'][] = 'Unsicherer Pfad ignoriert: ' . $entry;
            continue;
        }

        // Geschützte Dateien/Ordner niemals überschreiben
        if (in_array(basename($rel), $skipBasenames, true)) {
            $result['skipped'][] = $rel;
            continue;
        }
        if (strpos($rel, 'uploads/') === 0) {
            $result['skipped'][] = $rel;
            continue;
        }

        $dest = rtrim($appRoot, '/') . '/' . $rel;
        $destDir = dirname($dest);
        if (!is_dir($destDir) && !@mkdir($destDir, 0755, true)) {
            $result['errors'][] = 'Ordner konnte nicht angelegt werden: ' . $rel;
            continue;
        }

        $data = $zip->getFromIndex($i);
        if ($data === false) {
            $result['errors'][] = 'Lesen fehlgeschlagen: ' . $entry;
            continue;
        }
        if (@file_put_contents($dest, $data) === false) {
            $result['errors'][] = 'Schreiben fehlgeschlagen (Rechte?): ' . $rel;
            continue;
        }
        $result['copied']++;
    }

    $zip->close();
    return $result;
}
