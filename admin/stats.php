<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bundle.php';
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

// Handle deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verifyCsrf();
    // soft-delete + Dateien entfernen (Einzeldatei oder ganzer Bundle-Ordner)
    if (($offer['bundle_type'] ?? 'single') === 'bundle') {
        bundleDeleteDir(bundleDir($offer['slug']));
    } else {
        $filePath = __DIR__ . '/../uploads/' . basename($offer['filename']);
        if (is_file($filePath)) @unlink($filePath);
    }
    $db->prepare("UPDATE offers SET is_active = 0 WHERE id = ?")->execute([$id]);
    header('Location: index.php');
    exit;
}

// Aggregate stats
$stmt = $db->prepare("
    SELECT
        COUNT(*) AS total_views,
        COUNT(DISTINCT ip_hash) AS unique_views,
        COALESCE(AVG(NULLIF(duration_seconds,0)),0) AS avg_duration,
        COALESCE(MAX(duration_seconds),0) AS max_duration,
        MAX(started_at) AS last_view,
        MIN(started_at) AS first_view
    FROM offer_views WHERE offer_id = ?
");
$stmt->execute([$id]);
$stats = $stmt->fetch();

// Recent individual views
$stmt = $db->prepare("
    SELECT started_at, duration_seconds, ip_hash, user_agent
    FROM offer_views
    WHERE offer_id = ?
    ORDER BY started_at DESC
    LIMIT 100
");
$stmt->execute([$id]);
$views = $stmt->fetchAll();

// Views per day (last 14 days)
$stmt = $db->prepare("
    SELECT DATE(started_at) AS day, COUNT(*) AS cnt
    FROM offer_views
    WHERE offer_id = ? AND started_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
    GROUP BY DATE(started_at)
");
$stmt->execute([$id]);
$dailyRaw = [];
foreach ($stmt->fetchAll() as $row) {
    $dailyRaw[$row['day']] = (int)$row['cnt'];
}

$daily = [];
for ($i = 13; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $daily[$day] = $dailyRaw[$day] ?? 0;
}
$maxDaily = max(1, max($daily));

$offerLink = BASE_URL . 'a/' . $offer['slug'];

// Copy-Paste-Nachricht (Passwort wird entschlüsselt; ohne Passwort = Hinweistext)
$plainPw = decryptSecret($offer['password_enc'] ?? null);
$tpl = getSetting($db, 'message_template', '') ?: defaultMessageTemplate();
$offerMessage = renderOfferMessage($tpl, [
    'typ'         => offerKind($offer),
    'titel'       => $offer['title'],
    'link'        => $offerLink,
    'passwort'    => offerPasswordText($offer),
    'gueltig_bis' => !empty($offer['expires_at']) ? date('d.m.Y', strtotime($offer['expires_at'])) : 'unbegrenzt',
]);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statistik: <?= e($offer['title']) ?> — <?= e(BRAND_NAME) ?></title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="admin-wrap">
    <div class="admin-header">
        <div>
            <a href="index.php" class="btn btn-ghost btn-sm" style="margin-bottom:.8rem">← Dashboard</a>
            <h1><?= e($offer['title']) ?></h1>
        </div>
        <div class="admin-header-actions">
            <a href="edit.php?id=<?= $id ?>" class="btn btn-primary btn-sm">Bearbeiten</a>
            <a href="export.php?id=<?= $id ?>" class="btn btn-ghost btn-sm">CSV-Export</a>
            <button class="btn btn-danger btn-sm" onclick="document.getElementById('delModal').style.display='flex'">Löschen</button>
        </div>
    </div>

    <div class="card">
        <div class="form-group">
            <label>Trackbarer Link</label>
            <div class="link-box">
                <input type="text" value="<?= e($offerLink) ?>" readonly id="lnk">
                <button class="btn btn-primary btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('lnk').value)">Kopieren</button>
            </div>
        </div>
        <div class="form-group" style="margin-bottom:0">
            <label>Fertige Nachricht (Link + Passwort) — zum Kopieren</label>
            <textarea id="msgBox" readonly style="width:100%;min-height:170px;padding:.8rem 1rem;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg);color:var(--text);font-size:.9rem;line-height:1.5;font-family:inherit;resize:vertical"><?= e($offerMessage) ?></textarea>
            <button type="button" class="btn btn-primary" style="margin-top:.6rem" onclick="copyMsg()">Nachricht kopieren</button>
            <span id="msgCopied" style="margin-left:.6rem;color:var(--success);display:none">Kopiert!</span>
            <?php if (offerHasPassword($offer) && $plainPw === null): ?>
                <p class="form-hint" style="color:var(--warning)">Hinweis: Das Passwort dieses (älteren) Angebots ist nicht hinterlegt. Setze es einmal über „Bearbeiten" neu, dann erscheint es hier automatisch.</p>
            <?php else: ?>
                <p class="form-hint">Vorlage anpassbar unter <a href="settings.php" style="color:var(--primary)">Einstellungen</a>.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="stat-grid">
        <div class="stat-box">
            <div class="stat-value"><?= (int)$stats['total_views'] ?></div>
            <div class="stat-label">Aufrufe</div>
        </div>
        <div class="stat-box">
            <div class="stat-value"><?= (int)$stats['unique_views'] ?></div>
            <div class="stat-label">Unique Besucher</div>
        </div>
        <div class="stat-box">
            <div class="stat-value"><?= formatDuration((int)$stats['avg_duration']) ?></div>
            <div class="stat-label">&#8709; Verweildauer</div>
        </div>
        <div class="stat-box">
            <div class="stat-value"><?= formatDuration((int)$stats['max_duration']) ?></div>
            <div class="stat-label">Längste Ansicht</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Aufrufe (letzte 14 Tage)</h2></div>
        <div class="chart-bar-wrap">
            <?php foreach ($daily as $day => $cnt): ?>
                <div class="chart-bar" style="height: <?= max(2, round($cnt / $maxDaily * 100)) ?>%">
                    <span class="chart-tooltip"><?= date('d.m.', strtotime($day)) ?>: <?= $cnt ?> Aufrufe</span>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="chart-labels">
            <?php foreach (array_keys($daily) as $i => $day): ?>
                <span><?= ($i % 2 === 0) ? date('d.m', strtotime($day)) : '' ?></span>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Einzelne Aufrufe</h2></div>
        <?php if (empty($views)): ?>
            <div class="empty-state"><p>Noch keine Aufrufe. Sobald dein Kunde den Link öffnet, erscheinen hier die Details.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Zeitpunkt</th>
                            <th>Verweildauer</th>
                            <th>Gerät</th>
                            <th>System</th>
                            <th>Browser</th>
                            <th>Besucher</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($views as $v): $ua = parseUA($v['user_agent']); ?>
                        <tr>
                            <td><?= date('d.m.Y H:i', strtotime($v['started_at'])) ?> Uhr</td>
                            <td><?= $v['duration_seconds'] > 0 ? formatDuration((int)$v['duration_seconds']) : '<span style="color:var(--text-muted)">–</span>' ?></td>
                            <td><?= e($ua['device']) ?></td>
                            <td><?= e($ua['os']) ?></td>
                            <td><?= e($ua['browser']) ?></td>
                            <td><span style="font-family:monospace;font-size:.75rem;color:var(--text-muted)">#<?= substr($v['ip_hash'], 0, 8) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="form-hint" style="margin-top:1rem">Besucher werden anonym über einen Hash unterschieden (DSGVO-konform, keine Klartext-IP gespeichert).</p>
        <?php endif; ?>
    </div>
</div>

<div class="modal-overlay" id="delModal" style="display:none">
    <div class="modal">
        <h3><?= e(offerKind($offer)) ?> löschen?</h3>
        <p>Der Eintrag und alle Statistiken werden entfernt. Der Link funktioniert danach nicht mehr.</p>
        <form method="post" class="modal-actions">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <button type="button" class="btn btn-ghost" onclick="document.getElementById('delModal').style.display='none'">Abbrechen</button>
            <button type="submit" class="btn btn-danger">Endgültig löschen</button>
        </form>
    </div>
</div>
<script>
function buildHtmlMessage(text) {
    const esc = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const linked = esc.replace(/(https?:\/\/[^\s]+)/g, '<a href="$1">$1</a>');
    return linked.replace(/\n/g, '<br>');
}
async function copyRichMessage(text) {
    try {
        if (window.ClipboardItem && navigator.clipboard.write) {
            const html = buildHtmlMessage(text);
            await navigator.clipboard.write([new ClipboardItem({
                'text/html':  new Blob([html], { type: 'text/html' }),
                'text/plain': new Blob([text], { type: 'text/plain' })
            })]);
        } else {
            await navigator.clipboard.writeText(text);
        }
    } catch (e) {
        await navigator.clipboard.writeText(text);
    }
}
function copyMsg() {
    copyRichMessage(document.getElementById('msgBox').value).then(() => {
        const c = document.getElementById('msgCopied');
        c.style.display = 'inline';
        setTimeout(() => c.style.display = 'none', 1500);
    });
}
</script>
</body>
</html>
