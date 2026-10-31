<?php
/**
 * Liefert die geschützten Angebots-Dateien aus – NUR wenn der Besucher das
 * Passwort in dieser Session bereits eingegeben hat. Direkter Zugriff auf den
 * uploads-Ordner ist per .htaccess gesperrt.
 *
 * Einzelne HTML-Datei:  file.php?slug=xxx
 * Mehrseitiges Angebot: /f/{slug}/{pfad}  ->  file.php?slug=xxx&p=unterseite.html
 *                       (relative Links im HTML funktionieren dadurch normal)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/bundle.php';
require_once __DIR__ . '/includes/versions.php';

secureSession();

/**
 * Aufruf-Varianten:
 *   file.php?slug=xxx                     (einzelne HTML-Datei)
 *   /f/{slug}/{pfad}   -> ?slug=&p=       (Rewrite-Weg)
 *   file.php/{slug}/{pfad}                (PATH_INFO-Weg, ohne mod_rewrite)
 */
$pathInfo = (string)($_SERVER['PATH_INFO'] ?? '');
$slugRaw  = (string)($_GET['slug'] ?? '');
$relRaw   = (string)($_GET['p'] ?? '');

// PATH_INFO nur auswerten, wenn kein expliziter slug-Parameter kommt
// (manche Server setzen PATH_INFO zusätzlich zur Rewrite-Query).
if ($pathInfo !== '' && $slugRaw === '') {
    $parts   = explode('/', ltrim($pathInfo, '/'), 2);
    $slugRaw = $parts[0];
    $relRaw  = $parts[1] ?? '';
}

$slug = preg_replace('/[^a-f0-9]/', '', $slugRaw);
if ($slug === '') {
    http_response_code(404);
    exit;
}

$db = getDB();
$stmt = $db->prepare("SELECT * FROM offers WHERE slug = ? AND is_active = 1");
$stmt->execute([$slug]);
$offer = $stmt->fetch();

if (!$offer) {
    http_response_code(404);
    exit;
}

// ===== Admin-Vorschau einer bestimmten Version =====
// Einzeldatei: file.php?slug=…&pv={token} · Mehrseitig: /f/{slug}/{token}/{pfad}
$previewToken = '';
$previewVid   = 0;
$pvCandidate  = (string)($_GET['pv'] ?? '');
if ($pvCandidate === '') {
    $tmpRel = ltrim(str_replace('\\', '/', $relRaw), '/');
    $tmpSeg = strpos($tmpRel, '/') === false ? $tmpRel : substr($tmpRel, 0, strpos($tmpRel, '/'));
    if (bundleIsPreviewSegment($tmpSeg)) {
        $pvCandidate = $tmpSeg;
        $relRaw = (string)substr($tmpRel, strlen($tmpSeg) + 1);
    }
}
if ($pvCandidate !== '') {
    $previewVid = bundleVerifyPreviewToken($slug, $pvCandidate);
    $pv = $previewVid > 0 ? versionById($db, (int)$offer['id'], $previewVid) : null;
    if ($pv === null) {
        http_response_code(403);
        exit;
    }
    $previewToken = $pvCandidate;
    // Angebot so behandeln, als wäre diese Version aktiv (nur für diese Anfrage)
    $offer['bundle_type'] = $pv['bundle_type'];
    $offer['filename']    = $pv['filename'];
    $offer['entry_file']  = $pv['entry_file'];
}

// Archiviert, pausiert oder abgelaufen? Dann auch keine Dateien mehr ausliefern
// (die Admin-Vorschau funktioniert trotzdem).
if ($previewToken === '' && offerStatus($offer) !== 'active') {
    http_response_code(410);
    exit;
}

$isBundle = (($offer['bundle_type'] ?? 'single') === 'bundle');

// Bei mehrseitigen Angeboten steht ggf. ein Zugriffs-Token vorne im Pfad.
// Es ersetzt die Session, weil der Browser im abgeschotteten iframe keine
// Cookies an nachgeladene Dateien (CSS/JS/Schriften) sendet.
$hasToken = $previewToken !== '';
$token    = $previewToken;
if ($isBundle && !$hasToken) {
    $rel = ltrim(str_replace('\\', '/', $relRaw), '/');
    $slashPos = strpos($rel, '/');
    $firstSeg = $slashPos === false ? $rel : substr($rel, 0, $slashPos);
    if (bundleIsTokenSegment($firstSeg)) {
        $hasToken = bundleVerifyToken($slug, $firstSeg);
        if (!$hasToken) {
            http_response_code(403);
            exit;
        }
        $token  = $firstSeg;
        $relRaw = $slashPos === false ? '' : substr($rel, $slashPos + 1);
    }
}

$sessionKey = 'offer_access_' . $offer['id'];
if (!$hasToken && empty($_SESSION[$sessionKey])) {
    http_response_code(403);
    exit;
}

if ($isBundle) {
    // Ordner der gerade angezeigten Version (offers.filename = Ordnername)
    $base = realpath(bundleDir((string)$offer['filename']));
    if ($base === false) {
        http_response_code(404);
        exit;
    }

    // Angeforderter Pfad – ohne Query, ohne Fragment, URL-dekodiert.
    $rel = str_replace('\\', '/', $relRaw);
    $rel = ltrim(preg_replace('/[?#].*$/', '', $rel), '/');
    if ($rel === '') {
        $rel = (string)($offer['entry_file'] ?? 'index.html');
    }
    if (strpos($rel, "\0") !== false) {
        http_response_code(400);
        exit;
    }

    // Ordner-Links ("kontakt/") auf deren index.html auflösen
    $path = bundleResolveFile($base, $rel);
    if ($path === null) {
        http_response_code(404);
        exit;
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, bundleAllowedExtensions(), true)) {
        http_response_code(403);
        exit;
    }

    header('Content-Type: ' . bundleMimeType($ext));
    // HTML/SVG/XML laufen immer abgeschottet (auch direkt in einem Tab geöffnet)
    sendUploadSecurityHeaders($ext);
    // Nicht zwischenspeichern: der Link ist personengebunden.
    header('Cache-Control: private, no-store');

    if (in_array($ext, ['html', 'htm', 'css'], true)) {
        // Absolute Pfade (/css/…, /img/…, /kontakt/) auf das Angebot umbiegen.
        if ($token === '') $token = bundleAccessToken($slug);
        $urlBase = bundleFileUrl($db, $slug, '', $token);
        $body = bundleRewriteRootUrls((string)file_get_contents($path), $urlBase, $ext);
        if ($ext !== 'css') {
            // Unterseite an den Viewer melden (Zeit pro Seite in der Statistik)
            $body = bundleInjectPageSignal($body, ltrim(str_replace('\\', '/', substr($path, strlen($base))), '/'));
        }
        header('Content-Length: ' . strlen($body));
        echo $body;
        exit;
    }

    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ===== Einzelne HTML-Datei (wie bisher) =====
$path = __DIR__ . '/uploads/' . basename($offer['filename']);
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store');
sendUploadSecurityHeaders('html');
readfile($path);
