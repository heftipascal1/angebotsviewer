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

/* =====================================================================
   Update direkt aus GitHub-Releases
   ---------------------------------------------------------------------
   Ablauf: githubLatestRelease() fragt die GitHub-API nach dem neuesten
   Release von UPDATE_GITHUB_REPO und sucht darin ein ZIP-Asset, dessen
   Name zu UPDATE_ZIP_PATTERN passt. githubInstallRelease() lädt genau
   dieses Asset herunter und übergibt es an applyUpdateZip() – dieselben
   Sicherheitsregeln wie beim manuellen ZIP-Upload gelten also auch hier.
   ===================================================================== */

/** HTTP-GET (curl bevorzugt, sonst allow_url_fopen). Liefert Body oder null. */
function updaterHttpGet(string $url, array $headers = [], int $timeout = 30): ?string {
    $headers[] = 'User-Agent: HeftisAngebote-Updater/' . APP_VERSION;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ($body !== false && $code >= 200 && $code < 300) ? $body : null;
    }
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create([
            'http' => ['method' => 'GET', 'header' => implode("\r\n", $headers), 'timeout' => $timeout, 'follow_location' => 1, 'ignore_errors' => true],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s(\d{3})#', $h, $m)) $status = (int)$m[1];
        }
        return ($body !== false && $status >= 200 && $status < 300) ? $body : null;
    }
    return null;
}

/** true, wenn der Server überhaupt Updates aus dem Internet laden kann. */
function githubUpdateAvailable(): bool {
    return defined('UPDATE_GITHUB_REPO') && UPDATE_GITHUB_REPO !== ''
        && (function_exists('curl_init') || ini_get('allow_url_fopen'));
}

/**
 * Release-Infos von GitHub holen. $tag = null → neuestes Release.
 * Liefert ['tag','version','name','url','published','asset_name','asset_url','asset_size','notes']
 * oder ['error' => '...'].
 */
function githubFetchRelease(?string $tag = null): array {
    if (!githubUpdateAvailable()) {
        return ['error' => 'GitHub-Updates sind auf diesem Server nicht möglich (weder curl noch allow_url_fopen).'];
    }
    if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', UPDATE_GITHUB_REPO)) {
        return ['error' => 'UPDATE_GITHUB_REPO ist ungültig konfiguriert.'];
    }
    $api = 'https://api.github.com/repos/' . UPDATE_GITHUB_REPO . '/releases/'
         . ($tag === null ? 'latest' : 'tags/' . rawurlencode($tag));
    $json = updaterHttpGet($api, ['Accept: application/vnd.github+json'], 20);
    if ($json === null) {
        return ['error' => 'GitHub konnte nicht erreicht werden oder es gibt noch kein Release.'];
    }
    $rel = json_decode($json, true);
    if (!is_array($rel) || empty($rel['tag_name'])) {
        return ['error' => 'Unerwartete Antwort von GitHub.'];
    }
    $asset = null;
    foreach ($rel['assets'] ?? [] as $a) {
        if (!empty($a['name']) && validateUpdateZipName($a['name']) && !empty($a['browser_download_url'])) {
            $asset = $a;
            break;
        }
    }
    if ($asset === null) {
        return ['error' => 'Das Release ' . $rel['tag_name'] . ' enthält kein Update-Paket (heftis-angebote-vX.Y.Z.zip).'];
    }
    return [
        'tag'        => (string)$rel['tag_name'],
        'version'    => ltrim((string)$rel['tag_name'], 'vV'),
        'name'       => (string)($rel['name'] ?? $rel['tag_name']),
        'url'        => (string)($rel['html_url'] ?? ''),
        'published'  => (string)($rel['published_at'] ?? ''),
        'notes'      => (string)($rel['body'] ?? ''),
        'asset_name' => (string)$asset['name'],
        'asset_url'  => (string)$asset['browser_download_url'],
        'asset_size' => (int)($asset['size'] ?? 0),
    ];
}

/** Ist die Release-Version neuer als die installierte? */
function githubIsNewer(array $release): bool {
    return !empty($release['version']) && version_compare($release['version'], APP_VERSION, '>');
}

/**
 * Lädt das Update-Paket eines Releases (per Tag, der Asset-Link wird
 * serverseitig frisch von GitHub aufgelöst) und spielt es ein.
 * Liefert dasselbe Ergebnis-Array wie applyUpdateZip().
 */
function githubInstallRelease(string $tag, string $appRoot): array {
    $rel = githubFetchRelease($tag);
    if (isset($rel['error'])) {
        return ['copied' => 0, 'skipped' => [], 'errors' => [$rel['error']]];
    }
    // Nur von GitHub laden – nie von einer frei gewählten URL.
    $host = parse_url($rel['asset_url'], PHP_URL_HOST) ?: '';
    if (!in_array($host, ['github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com'], true)) {
        return ['copied' => 0, 'skipped' => [], 'errors' => ['Download-Adresse liegt nicht bei GitHub: ' . $host]];
    }

    $data = updaterHttpGet($rel['asset_url'], ['Accept: application/octet-stream'], 120);
    if ($data === null || $data === '') {
        return ['copied' => 0, 'skipped' => [], 'errors' => ['Das Update-Paket konnte nicht von GitHub geladen werden.']];
    }

    $tmp = tempnam(sys_get_temp_dir(), 'ha-update-');
    if ($tmp === false || @file_put_contents($tmp, $data) === false) {
        return ['copied' => 0, 'skipped' => [], 'errors' => ['Temporäre Datei konnte nicht geschrieben werden.']];
    }
    try {
        return applyUpdateZip($tmp, $appRoot);
    } finally {
        @unlink($tmp);
    }
}
