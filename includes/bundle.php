<?php
/**
 * Mehrseitige Angebote / Mini-Webseiten (ZIP-Upload).
 *
 * Ein Angebot ist entweder
 *   - 'single': eine einzelne HTML-Datei  (uploads/<slug>.html)  — wie bisher
 *   - 'bundle': ein entpacktes ZIP        (uploads/<slug>/...)
 *
 * Bundles werden NIE direkt ausgeliefert (uploads/.htaccess sperrt alles),
 * sondern ausschließlich über file.php nach Passwort-Prüfung. Damit relative
 * Links (kontakt.html, css/style.css, bilder/logo.png) im Browser einfach
 * funktionieren, läuft die Auslieferung über die schöne URL
 *   /f/{slug}/{pfad}   ->   file.php?slug={slug}&p={pfad}
 */

/** Dateitypen, die aus einem ZIP übernommen werden. Alles andere wird verworfen. */
function bundleAllowedExtensions(): array {
    return [
        // Seiten & Code
        'html', 'htm', 'css', 'js', 'mjs', 'json', 'map', 'txt', 'xml', 'webmanifest', 'csv',
        // Bilder
        'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'bmp',
        // Schriften
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        // Medien & Dokumente
        'mp4', 'webm', 'ogg', 'ogv', 'mp3', 'wav', 'm4a', 'pdf',
    ];
}

/** Grenzen gegen ZIP-Bomben und versehentliche Riesen-Uploads. */
const BUNDLE_MAX_FILES       = 800;
const BUNDLE_MAX_TOTAL_BYTES = 80 * 1024 * 1024;   // entpackt insgesamt
const BUNDLE_MAX_FILE_BYTES  = 30 * 1024 * 1024;   // je Einzeldatei

function bundleMimeType(string $ext): string {
    static $map = [
        'html' => 'text/html; charset=utf-8',
        'htm'  => 'text/html; charset=utf-8',
        'css'  => 'text/css; charset=utf-8',
        'js'   => 'text/javascript; charset=utf-8',
        'mjs'  => 'text/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'map'  => 'application/json; charset=utf-8',
        'webmanifest' => 'application/manifest+json; charset=utf-8',
        'txt'  => 'text/plain; charset=utf-8',
        'csv'  => 'text/csv; charset=utf-8',
        'xml'  => 'application/xml; charset=utf-8',
        'svg'  => 'image/svg+xml',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'ico'  => 'image/x-icon',
        'bmp'  => 'image/bmp',
        'woff' => 'font/woff',
        'woff2'=> 'font/woff2',
        'ttf'  => 'font/ttf',
        'otf'  => 'font/otf',
        'eot'  => 'application/vnd.ms-fontobject',
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
        'ogg'  => 'audio/ogg',
        'ogv'  => 'video/ogg',
        'mp3'  => 'audio/mpeg',
        'wav'  => 'audio/wav',
        'm4a'  => 'audio/mp4',
        'pdf'  => 'application/pdf',
    ];
    return $map[$ext] ?? 'application/octet-stream';
}

/** Absoluter Pfad zum Bundle-Ordner eines Angebots. */
function bundleDir(string $slug): string {
    return dirname(__DIR__) . '/uploads/' . $slug;
}

/**
 * Prüft einen Pfad aus dem ZIP und liefert den bereinigten relativen Pfad
 * zurück – oder null, wenn die Datei nicht übernommen werden darf.
 */
function bundleSafeRelPath(string $path): ?string {
    $path = str_replace('\\', '/', $path);
    if ($path === '' || substr($path, -1) === '/') return null;      // Ordner
    if ($path[0] === '/' || preg_match('#^[a-z]:/#i', $path)) return null; // absolut
    if (strpos($path, "\0") !== false) return null;

    $parts = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') return null;                              // Traversal
        if ($seg[0] === '.') return null;                            // .DS_Store, .htaccess, …
        if ($seg === '__MACOSX') return null;
        if (strlen($seg) > 120) return null;
        $parts[] = $seg;
    }
    if (!$parts || count($parts) > 12) return null;

    $rel = implode('/', $parts);
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, bundleAllowedExtensions(), true)) return null;

    return $rel;
}

/** Entfernt einen gemeinsamen Wurzelordner ("Angebot/index.html" -> "index.html"). */
function bundleStripCommonPrefix(array $paths): array {
    if (count($paths) < 2) {
        // Auch bei einer einzelnen Datei einen Ordner-Prefix entfernen wäre riskant.
        return $paths;
    }
    $first = explode('/', $paths[0]);
    if (count($first) < 2) return $paths;
    $prefix = $first[0] . '/';
    foreach ($paths as $p) {
        if (strpos($p, $prefix) !== 0) return $paths;
    }
    return array_map(fn($p) => substr($p, strlen($prefix)), $paths);
}

/** Sucht die Startseite: index.html ganz oben, sonst die oberste/erste HTML-Datei. */
function bundlePickEntry(array $relPaths): ?string {
    $htmls = array_values(array_filter($relPaths, function ($p) {
        $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
        return $ext === 'html' || $ext === 'htm';
    }));
    if (!$htmls) return null;

    foreach (['index.html', 'index.htm', 'start.html', 'home.html'] as $cand) {
        foreach ($htmls as $p) {
            if (strtolower($p) === $cand) return $p;
        }
    }
    // sonst: geringste Ordnertiefe, dann alphabetisch
    usort($htmls, function ($a, $b) {
        $da = substr_count($a, '/');
        $db = substr_count($b, '/');
        return $da === $db ? strcasecmp($a, $b) : $da <=> $db;
    });
    return $htmls[0];
}

/** Löscht einen Ordner samt Inhalt (nur innerhalb von uploads/). */
function bundleDeleteDir(string $dir): void {
    $uploads = realpath(dirname(__DIR__) . '/uploads');
    $real    = realpath($dir);
    if (!$uploads || !$real || $real === $uploads || strpos($real, $uploads . DIRECTORY_SEPARATOR) !== 0) return;
    if (!is_dir($real)) return;

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($real);
}

/** Listet alle HTML-Seiten eines Bundles (relative Pfade, sortiert). */
function bundleListPages(string $slug): array {
    $dir = bundleDir($slug);
    if (!is_dir($dir)) return [];
    $pages = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $ext = strtolower($file->getExtension());
        if ($ext !== 'html' && $ext !== 'htm') continue;
        $pages[] = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($dir))), '/');
    }
    usort($pages, fn($a, $b) => substr_count($a, '/') === substr_count($b, '/')
        ? strcasecmp($a, $b)
        : substr_count($a, '/') <=> substr_count($b, '/'));
    return $pages;
}

/**
 * Entpackt ein hochgeladenes ZIP nach uploads/<slug>/.
 * Bestehende Inhalte des Ziel-Ordners werden ersetzt (Link + Statistik bleiben,
 * weil Slug und Datenbank-Eintrag unverändert sind).
 *
 * @return array{ok:bool, error?:string, entry?:string, files?:int}
 */
function bundleExtract(string $zipPath, string $slug): array {
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'error' => 'Auf diesem Server fehlt die PHP-Erweiterung "zip". Bitte beim Hoster aktivieren lassen oder eine einzelne HTML-Datei hochladen.'];
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return ['ok' => false, 'error' => 'Das ZIP-Archiv konnte nicht geöffnet werden.'];
    }

    // 1) Durchgang: prüfen & Pfade sammeln
    $entries = [];   // index im ZIP => bereinigter Pfad
    $total   = 0;
    $skipped = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if ($stat === false) continue;

        $rel = bundleSafeRelPath($stat['name']);
        if ($rel === null) { $skipped++; continue; }

        if ($stat['size'] > BUNDLE_MAX_FILE_BYTES) {
            $zip->close();
            return ['ok' => false, 'error' => 'Die Datei "' . $rel . '" im ZIP ist zu groß (max. 30 MB pro Datei).'];
        }
        $total += (int)$stat['size'];
        if ($total > BUNDLE_MAX_TOTAL_BYTES) {
            $zip->close();
            return ['ok' => false, 'error' => 'Das entpackte Archiv ist zu groß (max. 80 MB insgesamt).'];
        }
        $entries[$i] = $rel;
        if (count($entries) > BUNDLE_MAX_FILES) {
            $zip->close();
            return ['ok' => false, 'error' => 'Das ZIP enthält zu viele Dateien (max. 800).'];
        }
    }

    if (!$entries) {
        $zip->close();
        return ['ok' => false, 'error' => 'Im ZIP wurden keine verwendbaren Dateien gefunden (mindestens eine .html-Datei wird benötigt).'];
    }

    // Gemeinsamen Wurzelordner entfernen
    $indexes = array_keys($entries);
    $stripped = bundleStripCommonPrefix(array_values($entries));
    $entries = array_combine($indexes, $stripped);

    $entry = bundlePickEntry($entries);
    if ($entry === null) {
        $zip->close();
        return ['ok' => false, 'error' => 'Im ZIP ist keine HTML-Seite enthalten. Bitte ein Archiv mit index.html hochladen.'];
    }

    // 2) Durchgang: in temporären Ordner entpacken, dann atomar tauschen
    $target = bundleDir($slug);
    $tmp    = $target . '.new';
    bundleDeleteDir($tmp);
    if (!@mkdir($tmp, 0755, true)) {
        $zip->close();
        return ['ok' => false, 'error' => 'Der Zielordner konnte nicht angelegt werden. Bitte Schreibrechte im uploads-Ordner prüfen.'];
    }

    foreach ($entries as $i => $rel) {
        $dest = $tmp . '/' . $rel;
        $dir  = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            bundleDeleteDir($tmp);
            $zip->close();
            return ['ok' => false, 'error' => 'Unterordner "' . $rel . '" konnte nicht angelegt werden.'];
        }
        $stream = $zip->getStream($zip->getNameIndex($i));
        if ($stream === false) { $skipped++; continue; }
        $out = @fopen($dest, 'wb');
        if ($out === false) {
            fclose($stream);
            bundleDeleteDir($tmp);
            $zip->close();
            return ['ok' => false, 'error' => 'Die Datei "' . $rel . '" konnte nicht gespeichert werden.'];
        }
        stream_copy_to_stream($stream, $out);
        fclose($out);
        fclose($stream);
    }
    $zip->close();

    // Alten Stand ersetzen
    $old = $target . '.old';
    bundleDeleteDir($old);
    if (is_dir($target) && !@rename($target, $old)) {
        bundleDeleteDir($tmp);
        return ['ok' => false, 'error' => 'Der bisherige Stand konnte nicht ersetzt werden (Schreibrechte im uploads-Ordner prüfen).'];
    }
    if (!@rename($tmp, $target)) {
        if (is_dir($old)) @rename($old, $target);   // zurückrollen
        bundleDeleteDir($tmp);
        return ['ok' => false, 'error' => 'Die neuen Dateien konnten nicht aktiviert werden.'];
    }
    bundleDeleteDir($old);

    return ['ok' => true, 'entry' => $entry, 'files' => count($entries), 'skipped' => $skipped];
}

/* =====================================================================
   Auslieferungs-Weg für mehrseitige Angebote
   ---------------------------------------------------------------------
   Damit relative Links (unterseite.html, css/style.css) im Browser
   korrekt auflösen, muss die Datei unter einem PFAD erreichbar sein –
   eine Query allein (?p=…) reicht nicht. Dafür gibt es zwei Wege:

     'rewrite'  → /f/{slug}/{pfad}          (braucht mod_rewrite)
     'pathinfo' → file.php/{slug}/{pfad}    (braucht nur PATH_INFO)

   Welcher Weg auf diesem Server funktioniert, ermittelt der Selbsttest
   automatisch und merkt sich das Ergebnis in den Einstellungen.
   ===================================================================== */

/** Baut die URL, unter der die Startseite/eine Datei ausgeliefert wird. */
function bundleFileUrl(PDO $db, string $slug, string $relPath = '', string $token = ''): string {
    $mode   = getSetting($db, 'bundle_delivery', 'rewrite') ?: 'rewrite';
    $prefix = $token !== '' ? $token . '/' : '';
    $rel    = $prefix . ltrim($relPath, '/');
    return $mode === 'pathinfo'
        ? BASE_URL . 'file.php/' . $slug . '/' . $rel
        : BASE_URL . 'f/' . $slug . '/' . $rel;
}

/** HTTP-Statuscode einer URL holen (ohne Cookies). 0 = nicht erreichbar. */
function bundleHttpStatus(string $url): int {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_NOBODY         => true,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $code;
    }
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create([
            'http' => ['method' => 'HEAD', 'timeout' => 8, 'ignore_errors' => true],
            'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $headers = @get_headers($url, false, $ctx);
        if ($headers && preg_match('#\s(\d{3})\s#', $headers[0] . ' ', $m)) {
            return (int)$m[1];
        }
    }
    return 0;
}

/**
 * Prüft, welcher Auslieferungs-Weg auf diesem Server funktioniert, und
 * speichert ihn. Getestet wird gegen ein echtes Bundle-Angebot: erreicht
 * die Anfrage file.php, antwortet dieses mit 403 (keine Passwort-Session)
 * – genau das ist das gesuchte Signal. 404 = Weg funktioniert nicht.
 *
 * @return array{mode:?string, rewrite:int, pathinfo:int, tested:bool}
 */
function bundleDetectDelivery(PDO $db, string $slug, string $entry): array {
    $rel  = ltrim($entry, '/');
    $out  = ['mode' => null, 'rewrite' => 0, 'pathinfo' => 0, 'tested' => true];

    $reached = fn(int $c) => in_array($c, [200, 403, 410], true);

    $out['rewrite'] = bundleHttpStatus(BASE_URL . 'f/' . $slug . '/' . $rel);
    if ($reached($out['rewrite'])) {
        $out['mode'] = 'rewrite';
    } else {
        $out['pathinfo'] = bundleHttpStatus(BASE_URL . 'file.php/' . $slug . '/' . $rel);
        if ($reached($out['pathinfo'])) $out['mode'] = 'pathinfo';
    }

    if ($out['mode'] !== null) {
        setSetting($db, 'bundle_delivery', $out['mode']);
    } elseif ($out['rewrite'] === 0 && $out['pathinfo'] === 0) {
        // Server erlaubt keine Anfrage an sich selbst – Selbsttest nicht möglich.
        $out['tested'] = false;
    }
    return $out;
}

/* =====================================================================
   Zugriffs-Token für Bundle-Dateien
   ---------------------------------------------------------------------
   Das Angebot läuft in einem abgeschotteten iframe (sandbox ohne
   allow-same-origin). Dessen Herkunft ist "opak" – der Browser sendet
   das Session-Cookie deshalb NICHT an nachgeladene Dateien (CSS, JS,
   Schriften, Bilder). Ergebnis: die Seite käme ohne Design an.

   Lösung: nach erfolgreicher Passworteingabe wird ein signiertes,
   zeitlich begrenztes Token in den Pfad eingebaut:
       /f/{slug}/{token}/{pfad}
   Relative Links im Angebot bleiben dadurch automatisch innerhalb des
   Tokens. Das Token ist an den Slug gebunden, per HMAC mit APP_SECRET
   signiert und wird serverseitig ohne Session geprüft – es muss also
   nichts gespeichert werden. Referrer-Policy "no-referrer" verhindert,
   dass es nach außen leakt.
   ===================================================================== */

const BUNDLE_TOKEN_TTL = 43200;   // 12 Stunden

function bundleAccessToken(string $slug, int $ttl = BUNDLE_TOKEN_TTL): string {
    $exp = time() + $ttl;
    $sig = substr(hash_hmac('sha256', $slug . '|' . $exp, APP_SECRET), 0, 32);
    return 't' . $exp . '-' . $sig;
}

function bundleIsTokenSegment(string $segment): bool {
    return (bool)preg_match('/^t\d{10,}-[a-f0-9]{32}$/', $segment);
}

function bundleVerifyToken(string $slug, string $token): bool {
    if (!bundleIsTokenSegment($token)) return false;
    [$expPart, $sig] = explode('-', substr($token, 1), 2);
    $exp = (int)$expPart;
    if ($exp < time()) return false;
    $expected = substr(hash_hmac('sha256', $slug . '|' . $exp, APP_SECRET), 0, 32);
    return hash_equals($expected, $sig);
}

/* =====================================================================
   Absolute Pfade ("/css/style.css", "/img/foto.webp", "/kontakt/")
   ---------------------------------------------------------------------
   Exportierte Webseiten (Astro, Eleventy, WordPress-Export …) verlinken
   ihre Dateien oft absolut ab der Domain-Wurzel. Im Angebotsviewer würde
   "/css/style.css" aber auf der Server-Wurzel landen – nicht im Angebot.
   Deshalb werden solche Pfade bei der Auslieferung von HTML und CSS auf
   die Angebots-Basis "…/f/{slug}/{token}/" umgeschrieben.
   ===================================================================== */

/** Schreibt einen einzelnen Wurzel-Pfad um ("/x" -> base + "x"); andere bleiben. */
function bundleRewriteOneUrl(string $url, string $base): string {
    $url = trim($url);
    if ($url === '' || $url[0] !== '/') return $url;      // relativ, absolut (http), mailto, #, data:
    if (isset($url[1]) && $url[1] === '/') return $url;   // protokoll-relativ (//cdn…)
    return $base . ltrim($url, '/');
}

/** srcset-Wert: mehrere Kandidaten "url 900w, url 2x" einzeln umschreiben. */
function bundleRewriteSrcset(string $value, string $base): string {
    $parts = array_map('trim', explode(',', $value));
    foreach ($parts as &$cand) {
        if ($cand === '') continue;
        $bits = preg_split('/\s+/', $cand, 2);
        $bits[0] = bundleRewriteOneUrl($bits[0], $base);
        $cand = implode(' ', $bits);
    }
    unset($cand);
    return implode(', ', $parts);
}

/**
 * Schreibt Wurzel-Pfade in HTML oder CSS auf die Angebots-Basis um.
 * $base endet mit "/" (z.B. https://domain.de/angebote/f/ab12cd34/tok…/).
 */
function bundleRewriteRootUrls(string $content, string $base, string $ext): string {
    if ($base === '' || substr($base, -1) !== '/') $base .= '/';

    // CSS: url(/pfad), url("/pfad"), url('/pfad') – gilt für .css UND <style>/style="" im HTML
    $content = preg_replace_callback(
        '/url\(\s*([\'"]?)(\/(?!\/)[^\'")]*)\1\s*\)/i',
        fn($m) => 'url(' . $m[1] . bundleRewriteOneUrl($m[2], $base) . $m[1] . ')',
        $content
    );
    // CSS @import "/pfad"
    $content = preg_replace_callback(
        '/@import\s+([\'"])(\/(?!\/)[^\'"]*)\1/i',
        fn($m) => '@import ' . $m[1] . bundleRewriteOneUrl($m[2], $base) . $m[1],
        $content
    );

    if ($ext === 'html' || $ext === 'htm') {
        // Attribute mit einer URL
        $content = preg_replace_callback(
            '/\b(href|src|poster|action|formaction|data-src|data-href|data-bg|content)\s*=\s*([\'"])(\/(?!\/)[^\'"]*)\2/i',
            fn($m) => $m[1] . '=' . $m[2] . bundleRewriteOneUrl($m[3], $base) . $m[2],
            $content
        );
        // Attribute mit mehreren URLs (srcset)
        $content = preg_replace_callback(
            '/\b(srcset|data-srcset)\s*=\s*([\'"])([^\'"]*)\2/i',
            fn($m) => $m[1] . '=' . $m[2] . bundleRewriteSrcset($m[3], $base) . $m[2],
            $content
        );
        // <base href="/"> würde alles wieder auf die Server-Wurzel ziehen -> entfernen
        $content = preg_replace('/<base\b[^>]*>/i', '', $content);
    }
    return $content;
}

/**
 * Löst einen angeforderten Pfad innerhalb des Bundles auf eine Datei auf.
 * Ordner-Links wie "kontakt/" oder "kontakt" landen auf kontakt/index.html,
 * "kontakt" alternativ auf kontakt.html. Liefert den realen Pfad oder null.
 */
function bundleResolveFile(string $base, string $rel): ?string {
    $rel = trim($rel, '/');
    $candidates = $rel === ''
        ? []
        : [$rel, $rel . '/index.html', $rel . '/index.htm', $rel . '.html', $rel . '.htm'];
    foreach ($candidates as $cand) {
        $path = realpath($base . '/' . $cand);
        if ($path !== false && strpos($path, $base . DIRECTORY_SEPARATOR) === 0 && is_file($path)) {
            return $path;
        }
    }
    return null;
}
