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

if ($pathInfo !== '') {
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

// Pausiert oder abgelaufen? Dann auch keine Dateien mehr ausliefern.
$isPaused  = !empty($offer['is_paused']) && (int)$offer['is_paused'] === 1;
$isExpired = !empty($offer['expires_at']) && strtotime($offer['expires_at']) < time();
if ($isPaused || $isExpired) {
    http_response_code(410);
    exit;
}

$isBundle = (($offer['bundle_type'] ?? 'single') === 'bundle');

// Bei mehrseitigen Angeboten steht ggf. ein Zugriffs-Token vorne im Pfad.
// Es ersetzt die Session, weil der Browser im abgeschotteten iframe keine
// Cookies an nachgeladene Dateien (CSS/JS/Schriften) sendet.
$hasToken = false;
if ($isBundle) {
    $rel = ltrim(str_replace('\\', '/', $relRaw), '/');
    $slashPos = strpos($rel, '/');
    $firstSeg = $slashPos === false ? $rel : substr($rel, 0, $slashPos);
    if (bundleIsTokenSegment($firstSeg)) {
        $hasToken = bundleVerifyToken($slug, $firstSeg);
        if (!$hasToken) {
            http_response_code(403);
            exit;
        }
        $relRaw = $slashPos === false ? '' : substr($rel, $slashPos + 1);
    }
}

$sessionKey = 'offer_access_' . $offer['id'];
if (!$hasToken && empty($_SESSION[$sessionKey])) {
    http_response_code(403);
    exit;
}

if ($isBundle) {
    $base = realpath(bundleDir($slug));
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

    $path = realpath($base . '/' . $rel);
    if ($path === false
        || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0
        || !is_file($path)) {
        http_response_code(404);
        exit;
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, bundleAllowedExtensions(), true)) {
        http_response_code(403);
        exit;
    }

    header('Content-Type: ' . bundleMimeType($ext));
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Content-Security-Policy: frame-ancestors \'self\';');
    // Nicht zwischenspeichern: der Link ist personengebunden.
    header('Cache-Control: private, no-store');
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
header('X-Frame-Options: SAMEORIGIN');
header('Content-Security-Policy: frame-ancestors \'self\';');
readfile($path);
