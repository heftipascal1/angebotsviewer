<?php
/**
 * Tracking-Endpunkt des Viewers.
 *
 *  action=start  (slug)                        → zählt den Aufruf eines Angebots ohne
 *                                                Passwort, sobald die Seite im Browser läuft
 *  action=beat   (view_id, duration, pages)    → Verweildauer gesamt + pro Unterseite
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/version.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/bundle.php';
require_once __DIR__ . '/../includes/tracking.php';
require_once __DIR__ . '/../includes/auth.php';

secureSession();
header('Content-Type: application/json');
header('Cache-Control: no-store');

function trackFail(int $code): void {
    http_response_code($code);
    echo json_encode(['ok' => false]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') trackFail(405);
$action = (string)($_POST['action'] ?? 'beat');

// ===== Aufruf starten (nur Angebote ohne Passwort) =====
if ($action === 'start') {
    $slug = preg_replace('/[^a-f0-9]/', '', (string)($_POST['slug'] ?? ''));
    if ($slug === '') trackFail(400);

    // Admin und Bots werden nie gezählt
    if (isAdminLoggedIn() || isPreviewBot()) {
        echo json_encode(['ok' => true, 'view_id' => 0]);
        exit;
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM offers WHERE slug = ? AND is_active = 1");
    $stmt->execute([$slug]);
    $offer = $stmt->fetch();
    if (!$offer || offerStatus($offer) !== 'active') trackFail(410);

    // Zugang muss in dieser Session über view.php freigeschaltet worden sein
    $sessionKey = 'offer_access_' . $offer['id'];
    if (empty($_SESSION[$sessionKey])) trackFail(403);

    $viewId = (int)($_SESSION[$sessionKey . '_view'] ?? 0);
    if ($viewId <= 0) {
        $viewId = recordView($db, $offer, hashIP($_SERVER['REMOTE_ADDR'] ?? ''), $sessionKey);
    }
    echo json_encode(['ok' => true, 'view_id' => $viewId]);
    exit;
}

// ===== Heartbeat =====
$viewId = (int)($_POST['view_id'] ?? 0);
$duration = (int)($_POST['duration'] ?? 0);

// Sanity limits: ignore absurd durations (> 6h)
if ($viewId <= 0 || $duration < 0 || $duration > 21600) trackFail(400);

// Nur Views aktualisieren, die DIESE Session selbst geöffnet hat.
// Verhindert Manipulation fremder Statistiken über den offenen Endpoint.
$myViews = $_SESSION['my_views'] ?? [];
if (!is_array($myViews) || !in_array($viewId, array_map('intval', $myViews), true)) trackFail(403);

$db = getDB();
// Only ever increase the duration, never overwrite with a smaller value
$stmt = $db->prepare("
    UPDATE offer_views
    SET duration_seconds = GREATEST(duration_seconds, ?),
        last_heartbeat = NOW()
    WHERE id = ?
");
$stmt->execute([$duration, $viewId]);

// Zeit pro Unterseite (nur mehrseitige Angebote, ab Schema 8)
$pagesRaw = (string)($_POST['pages'] ?? '');
if ($pagesRaw !== '' && strlen($pagesRaw) <= 20000) {
    $pages = json_decode($pagesRaw, true);
    if (is_array($pages) && $pages) {
        try {
            storePageTimes($db, $viewId, $pages);
        } catch (Throwable $ex) {
            // Tabelle fehlt (Migration ausstehend) – Gesamtdauer ist trotzdem gespeichert
        }
    }
}

echo json_encode(['ok' => true]);
