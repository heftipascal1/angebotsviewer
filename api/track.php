<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

secureSession();
header('Content-Type: application/json');

$viewId = (int)($_POST['view_id'] ?? 0);
$duration = (int)($_POST['duration'] ?? 0);

// Sanity limits: ignore absurd durations (> 6h)
if ($viewId <= 0 || $duration < 0 || $duration > 21600) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}

// Nur Views aktualisieren, die DIESE Session selbst geöffnet hat.
// Verhindert Manipulation fremder Statistiken über den offenen Endpoint.
$myViews = $_SESSION['my_views'] ?? [];
if (!is_array($myViews) || !in_array($viewId, array_map('intval', $myViews), true)) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

$db = getDB();
// Only ever increase the duration, never overwrite with a smaller value
$stmt = $db->prepare("
    UPDATE offer_views
    SET duration_seconds = GREATEST(duration_seconds, ?),
        last_heartbeat = NOW()
    WHERE id = ?
");
$stmt->execute([$duration, $viewId]);

echo json_encode(['ok' => true]);
