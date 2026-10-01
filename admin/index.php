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

// Filter (per GET, damit die Ansicht als Link merkbar bleibt)
$filterStatus = (string)($_GET['status'] ?? 'open');   // open = alles außer Archiv
$filterKind   = trim((string)($_GET['kind'] ?? ''));
$filterQuery  = http_build_query(array_filter(['status' => $filterStatus !== 'open' ? $filterStatus : null, 'kind' => $filterKind ?: null]));
$backUrl      = 'index.php' . ($filterQuery ? '?' . $filterQuery : '');

// Pausieren / Reaktivieren · Archivieren / Wiederherstellen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $pid = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($action === 'toggle_pause') {
        $db->prepare("UPDATE offers SET is_paused = 1 - is_paused WHERE id = ? AND is_active = 1")
           ->execute([$pid]);
    } elseif ($action === 'toggle_archive') {
        $db->prepare("UPDATE offers SET is_archived = 1 - is_archived WHERE id = ? AND is_active = 1")
           ->execute([$pid]);
    }
    header('Location: ' . $backUrl);
    exit;
}

$allOffers = $db->query("
    SELECT o.*,
           COUNT(v.id) AS view_count,
           COUNT(DISTINCT v.ip_hash) AS unique_views,
           MAX(v.started_at) AS last_viewed,
           COALESCE(AVG(NULLIF(v.duration_seconds, 0)), 0) AS avg_duration
    FROM offers o
    LEFT JOIN offer_views v ON v.offer_id = o.id
    WHERE o.is_active = 1
    GROUP BY o.id
    ORDER BY o.created_at DESC
")->fetchAll();

// Status pro Eintrag einmal bestimmen; Bezeichnungen (auch eigene) für den Filter sammeln
$kindOptions  = [];
$statusCounts = array_fill_keys(array_keys(offerStatusLabels()), 0);
foreach ($allOffers as &$row) {
    $row['_status'] = offerStatus($row);
    $statusCounts[$row['_status']]++;
    $kindOptions[offerKind($row)] = ($kindOptions[offerKind($row)] ?? 0) + 1;
}
unset($row);
ksort($kindOptions, SORT_NATURAL | SORT_FLAG_CASE);

$validStatus = array_merge(['open', 'all'], array_keys(offerStatusLabels()));
if (!in_array($filterStatus, $validStatus, true)) $filterStatus = 'open';

$offers = array_values(array_filter($allOffers, function (array $o) use ($filterStatus, $filterKind) {
    if ($filterStatus === 'open' && $o['_status'] === 'archived') return false;
    if ($filterStatus !== 'open' && $filterStatus !== 'all' && $o['_status'] !== $filterStatus) return false;
    if ($filterKind !== '' && offerKind($o) !== $filterKind) return false;
    return true;
}));

$totalOffers = count($allOffers) - $statusCounts['archived'];
$totalViews = array_sum(array_column($allOffers, 'view_count'));
$totalUnique = array_sum(array_column($allOffers, 'unique_views'));
$isFiltered = $filterStatus !== 'open' || $filterKind !== '';

// Vorlage für die Copy-Paste-Nachricht (einmal laden)
$messageTemplate = getSetting($db, 'message_template', '') ?: defaultMessageTemplate();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — <?= e(BRAND_NAME) ?></title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="admin-wrap">
    <div class="admin-header">
        <h1><?= e(BRAND_NAME) ?></h1>
        <div class="admin-header-actions">
            <a href="upload.php" class="btn btn-primary">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                Neues Angebot
            </a>
            <a href="settings.php" class="btn btn-ghost btn-sm">Einstellungen</a>
            <a href="update.php" class="btn btn-ghost btn-sm">Update</a>
            <a href="logout.php" class="btn btn-ghost btn-sm">Abmelden</a>
        </div>
    </div>

    <div class="stat-grid">
        <div class="stat-box">
            <div class="stat-value"><?= $totalOffers ?></div>
            <div class="stat-label">Angebote</div>
        </div>
        <div class="stat-box">
            <div class="stat-value"><?= $totalViews ?></div>
            <div class="stat-label">Aufrufe gesamt</div>
        </div>
        <div class="stat-box">
            <div class="stat-value"><?= $totalUnique ?></div>
            <div class="stat-label">Unique Besucher</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>Alle Angebote<?= $isFiltered ? ' <span style="font-size:.85rem;font-weight:400;color:var(--text-muted)">(' . count($offers) . ' von ' . count($allOffers) . ')</span>' : '' ?></h2>
        </div>

        <?php if (!empty($allOffers)): ?>
        <form method="get" id="filterForm" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:1rem">
            <select name="status" style="flex:1 1 160px;padding:.55rem .9rem;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg);color:var(--text);font-size:.9rem">
                <option value="open"<?= $filterStatus === 'open' ? ' selected' : '' ?>>Alle ohne Archiv (<?= count($allOffers) - $statusCounts['archived'] ?>)</option>
                <?php foreach (offerStatusLabels() as $sKey => $sLabel): ?>
                    <option value="<?= e($sKey) ?>"<?= $filterStatus === $sKey ? ' selected' : '' ?>><?= e($sLabel) ?> (<?= $statusCounts[$sKey] ?>)</option>
                <?php endforeach; ?>
                <option value="all"<?= $filterStatus === 'all' ? ' selected' : '' ?>>Alle inkl. Archiv (<?= count($allOffers) ?>)</option>
            </select>
            <select name="kind" style="flex:1 1 160px;padding:.55rem .9rem;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg);color:var(--text);font-size:.9rem">
                <option value="">Alle Bezeichnungen</option>
                <?php foreach ($kindOptions as $kLabel => $kCount): ?>
                    <option value="<?= e($kLabel) ?>"<?= $filterKind === $kLabel ? ' selected' : '' ?>><?= e($kLabel) ?> (<?= $kCount ?>)</option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="btn btn-ghost btn-sm">Filtern</button></noscript>
            <?php if ($isFiltered): ?>
                <a href="index.php" class="btn btn-ghost btn-sm">Filter zurücksetzen</a>
            <?php endif; ?>
        </form>
        <?php endif; ?>

        <?php if (empty($allOffers)): ?>
            <div class="empty-state">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>
                </svg>
                <p>Noch keine Angebote vorhanden.</p>
                <a href="upload.php" class="btn btn-primary" style="margin-top:1rem">Erstes Angebot hochladen</a>
            </div>
        <?php elseif (empty($offers)): ?>
            <div class="empty-state">
                <p>Kein Eintrag passt zu diesem Filter.</p>
                <a href="index.php" class="btn btn-ghost" style="margin-top:1rem">Filter zurücksetzen</a>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Angebot</th>
                            <th>Status</th>
                            <th>Aufrufe</th>
                            <th>Unique</th>
                            <th style="white-space:nowrap">&#8709; Dauer</th>
                            <th>Zuletzt gesehen</th>
                            <th>Gültig bis</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($offers as $o):
                        $status   = $o['_status'];
                        $paused   = $status === 'paused';
                        $archived = $status === 'archived';
                        $rowMessage = renderOfferMessage($messageTemplate, [
                            'typ'         => offerKind($o),
                            'titel'       => $o['title'],
                            'link'        => BASE_URL . 'a/' . $o['slug'],
                            'passwort'    => offerPasswordText($o),
                            'gueltig_bis' => !empty($o['expires_at']) ? date('d.m.Y', strtotime($o['expires_at'])) : 'unbegrenzt',
                        ]);
                    ?>
                        <tr<?= $status !== 'active' ? ' style="opacity:.55"' : '' ?>>
                            <td>
                                <strong><?= e($o['title']) ?></strong><br>
                                <span style="font-size:.75rem;color:var(--text-muted)"><?= e(offerKind($o)) ?> · <?= e($o['original_filename']) ?></span>
                                <?php if (($o['bundle_type'] ?? 'single') === 'bundle'): ?>
                                    <span style="font-size:.7rem;color:var(--text-muted)"> · mehrseitig</span>
                                <?php endif; ?>
                                <?php if (!offerHasPassword($o)): ?>
                                    <span style="font-size:.7rem;color:var(--text-muted)"> · ohne Passwort</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $status === 'active' ? 'badge-active' : 'badge-inactive' ?>"><?= e(offerStatusLabels()[$status]) ?></span>
                            </td>
                            <td><?= (int)$o['view_count'] ?></td>
                            <td><?= (int)$o['unique_views'] ?></td>
                            <td><?= formatDuration((int)$o['avg_duration']) ?></td>
                            <td><?= $o['last_viewed'] ? timeAgo($o['last_viewed']) : '—' ?></td>
                            <td><?= !empty($o['expires_at']) ? date('d.m.Y', strtotime($o['expires_at'])) : '—' ?></td>
                            <td style="white-space:nowrap">
                                <button class="btn btn-primary btn-sm copy-msg-btn" data-message="<?= e($rowMessage) ?>">Nachricht kopieren</button>
                                <a href="stats.php?id=<?= $o['id'] ?>" class="btn btn-primary btn-sm">Statistik</a>
                                <a href="edit.php?id=<?= $o['id'] ?>" class="btn btn-ghost btn-sm">Bearbeiten</a>
                                <?php if (!$archived): ?>
                                <form method="post" style="display:inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="toggle_pause">
                                    <input type="hidden" name="id" value="<?= $o['id'] ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm"><?= $paused ? 'Reaktivieren' : 'Pausieren' ?></button>
                                </form>
                                <?php endif; ?>
                                <form method="post" style="display:inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="toggle_archive">
                                    <input type="hidden" name="id" value="<?= $o['id'] ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm" title="<?= $archived ? 'Aus dem Archiv zurückholen' : 'Kunde sieht dann: „Leider wurde dieses Angebot archiviert"' ?>"><?= $archived ? 'Wiederherstellen' : 'Archivieren' ?></button>
                                </form>
                                <button class="btn btn-ghost btn-sm copy-link-btn" data-url="<?= e(BASE_URL . 'a/' . $o['slug']) ?>" title="Link kopieren">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    const f = document.getElementById('filterForm');
    if (!f) return;
    f.querySelectorAll('select').forEach(s => s.addEventListener('change', () => f.submit()));
})();
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
document.querySelectorAll('.copy-msg-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        copyRichMessage(btn.dataset.message).then(() => {
            const orig = btn.textContent;
            btn.textContent = 'Kopiert!';
            setTimeout(() => btn.textContent = orig, 1500);
        });
    });
});

document.querySelectorAll('.copy-link-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const url = btn.dataset.url;
        navigator.clipboard.writeText(url).then(() => {
            const orig = btn.innerHTML;
            btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>';
            setTimeout(() => btn.innerHTML = orig, 1500);
        });
    });
});
</script>
</body>
</html>
