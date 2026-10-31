<?php
/**
 * Admin-Vorschau einer beliebigen Version eines Angebots.
 * Für den Kunden ändert sich nichts, und es wird nichts gezählt.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/version.php';
require_once __DIR__ . '/../includes/migrations.php';
require_once __DIR__ . '/../includes/bundle.php';
require_once __DIR__ . '/../includes/versions.php';
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

$versionId = (int)($_GET['version'] ?? ($offer['current_version_id'] ?? 0));
$version = versionById($db, $id, $versionId);
if ($version === null) {
    header('Location: edit.php?id=' . $id);
    exit;
}
$isCurrent = (int)$version['id'] === (int)($offer['current_version_id'] ?? 0);

$token = bundlePreviewToken($offer['slug'], (int)$version['id']);
$frameSrc = $version['bundle_type'] === 'bundle'
    ? bundleFileUrl($db, $offer['slug'], (string)($version['entry_file'] ?? 'index.html'), $token)
    : BASE_URL . 'file.php?slug=' . $offer['slug'] . '&pv=' . $token;
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Vorschau Version <?= (int)$version['version_no'] ?>: <?= e($offer['title']) ?> — <?= e(BRAND_NAME) ?></title>
    <style>
        html,body{margin:0;padding:0;height:100%;overflow:hidden}
        body{display:flex;flex-direction:column;font:13px/1.4 system-ui,-apple-system,sans-serif}
        .bar{flex:none;display:flex;gap:.8rem;align-items:center;flex-wrap:wrap;padding:.5rem 1rem;background:#1e293b;color:#e2e8f0}
        .bar a{color:#93c5fd}
        .bar .tag{padding:.1rem .5rem;border-radius:999px;font-weight:600;font-size:12px}
        .tag-on{background:#14532d;color:#bbf7d0}.tag-off{background:#713f12;color:#fde68a}
        .bar form{margin:0}
        .bar button{background:#3b82f6;color:#fff;border:0;border-radius:6px;padding:.35rem .8rem;font:inherit;font-weight:600;cursor:pointer}
        iframe{flex:1;width:100%;border:none;display:block}
    </style>
</head>
<body>
<div class="bar">
    <strong>Vorschau · Version <?= (int)$version['version_no'] ?></strong>
    <span class="tag <?= $isCurrent ? 'tag-on' : 'tag-off' ?>"><?= $isCurrent ? 'Wird dem Kunden angezeigt' : 'Nicht aktiv – Kunde sieht eine andere Version' ?></span>
    <span><?= e((string)$version['original_filename']) ?> · <?= date('d.m.Y H:i', strtotime($version['created_at'])) ?><?= !empty($version['note']) ? ' · ' . e((string)$version['note']) : '' ?></span>
    <span style="opacity:.7">Wird nicht gezählt.</span>
    <?php if (!$isCurrent): ?>
        <form method="post" action="edit.php?id=<?= $id ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="activate_version">
            <input type="hidden" name="version_id" value="<?= (int)$version['id'] ?>">
            <button type="submit">Diese Version anzeigen</button>
        </form>
    <?php endif; ?>
    <a href="edit.php?id=<?= $id ?>">← Zurück zum Bearbeiten</a>
</div>
<iframe src="<?= e($frameSrc) ?>" sandbox="allow-scripts allow-popups allow-forms allow-popups-to-escape-sandbox"></iframe>
</body>
</html>
