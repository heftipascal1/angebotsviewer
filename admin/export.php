<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/version.php';
require_once __DIR__ . '/../includes/migrations.php';
requireAdmin();

$db = getDB();
redirectIfMigrationPending($db);

$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT * FROM offers WHERE id = ? AND is_active = 1");
$stmt->execute([$id]);
$offer = $stmt->fetch();
if (!$offer) {
    header('Location: index.php');
    exit;
}

$stmt = $db->prepare("
    SELECT started_at, duration_seconds, ip_hash, user_agent
    FROM offer_views
    WHERE offer_id = ?
    ORDER BY started_at DESC
");
$stmt->execute([$id]);
$views = $stmt->fetchAll();

// Dateiname aus Titel ableiten (nur sichere Zeichen)
$safeTitle = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $offer['title']);
$filename = 'statistik_' . $safeTitle . '_' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
// UTF-8 BOM, damit Excel Umlaute korrekt anzeigt
fwrite($out, "\xEF\xBB\xBF");
// Semikolon-Trenner = Excel-DE-freundlich
fputcsv($out, ['Zeitpunkt', 'Verweildauer (Sek.)', 'Verweildauer', 'Gerät', 'System', 'Browser', 'Besucher'], ';', '"', '\\');

foreach ($views as $v) {
    $ua = parseUA($v['user_agent']);
    fputcsv($out, [
        date('d.m.Y H:i', strtotime($v['started_at'])),
        (int)$v['duration_seconds'],
        formatDuration((int)$v['duration_seconds']),
        $ua['device'],
        $ua['os'],
        $ua['browser'],
        '#' . substr($v['ip_hash'], 0, 8),
    ], ';', '"', '\\');
}
fclose($out);
exit;
