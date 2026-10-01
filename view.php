<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/version.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/bundle.php';

secureSession();

$slug = preg_replace('/[^a-f0-9]/', '', $_GET['slug'] ?? '');
if ($slug === '') {
    http_response_code(404);
    die('Angebot nicht gefunden.');
}

$db = getDB();
$stmt = $db->prepare("SELECT * FROM offers WHERE slug = ? AND is_active = 1");
$stmt->execute([$slug]);
$offer = $stmt->fetch();

if (!$offer) {
    http_response_code(404);
    die('Angebot nicht gefunden oder nicht mehr verfügbar.');
}

// Archiviert, pausiert oder abgelaufen? (Spalten via !empty robust auch vor Migration.)
$status = offerStatus($offer);
if ($status !== 'active') {
    renderUnavailable($status, offerKind($offer), offerHeading($offer));
    exit;
}

$sessionKey = 'offer_access_' . $offer['id'];
$ipHash = hashIP($_SERVER['REMOTE_ADDR'] ?? '');
$error = '';

/**
 * Zugang freischalten und einen neuen Aufruf aufzeichnen (Statistik,
 * Heartbeat-Berechtigung, E-Mail-Benachrichtigung mit Spam-Schutz).
 */
function grantAccessAndRecordView(PDO $db, array $offer, string $ipHash, string $sessionKey): void {
    $_SESSION[$sessionKey] = true;

    $viewSession = bin2hex(random_bytes(16));
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

    $ins = $db->prepare("
        INSERT INTO offer_views (offer_id, session_hash, ip_hash, user_agent, started_at, last_heartbeat)
        VALUES (?, ?, ?, ?, NOW(), NOW())
    ");
    $ins->execute([$offer['id'], $viewSession, $ipHash, $ua]);
    $newViewId = (int)$db->lastInsertId();
    $_SESSION[$sessionKey . '_view'] = $newViewId;
    // Erlaubte View-IDs dieser Session (für die Tracking-Absicherung)
    if (!isset($_SESSION['my_views']) || !is_array($_SESSION['my_views'])) {
        $_SESSION['my_views'] = [];
    }
    $_SESSION['my_views'][] = $newViewId;

    // E-Mail-Benachrichtigung mit Spam-Schutz: höchstens 1 Mail pro
    // Besucher (ip_hash) und Angebot innerhalb von 6 Stunden.
    try {
        $offerId = (int)$offer['id'];
        $cnt = (int)$db->query("SELECT COUNT(*) FROM offer_views WHERE offer_id = $offerId")->fetchColumn();

        $chk = $db->prepare("
            SELECT COUNT(*) FROM offer_views
            WHERE offer_id = ? AND ip_hash = ? AND notified_at IS NOT NULL
              AND notified_at > (NOW() - INTERVAL 6 HOUR)
        ");
        $chk->execute([$offerId, $ipHash]);
        $recentlyNotified = (int)$chk->fetchColumn() > 0;

        if (!$recentlyNotified) {
            sendViewNotification($db, $offer, parseUA($ua), $cnt);
            $db->prepare("UPDATE offer_views SET notified_at = NOW() WHERE id = ?")
               ->execute([$newViewId]);
        }
    } catch (Throwable $ex) {
        // bewusst ignorieren – Benachrichtigung darf den Aufruf nie stören
    }
}

$hasPassword = offerHasPassword($offer);

// Ohne Passwort: Link öffnet sich direkt, Aufruf wird trotzdem gezählt
// (einmal pro Browser-Session, wie nach einer Passworteingabe).
if (!$hasPassword && empty($_SESSION[$sessionKey])) {
    grantAccessAndRecordView($db, $offer, $ipHash, $sessionKey);
}

// Handle password submit
if ($hasPassword && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $lockRemaining = throttleStatus($db, $ipHash, 'offer_' . $offer['id']);
    if ($lockRemaining > 0) {
        $error = 'Zu viele Fehlversuche. Bitte in ' . ceil($lockRemaining / 60) . ' Min. erneut versuchen.';
    } elseif (password_verify($_POST['password'] ?? '', $offer['password_hash'])) {
        throttleReset($db, $ipHash, 'offer_' . $offer['id']);
        grantAccessAndRecordView($db, $offer, $ipHash, $sessionKey);
        header('Location: ' . BASE_URL . 'a/' . $slug);
        exit;
    } else {
        throttleFail($db, $ipHash, 'offer_' . $offer['id']);
        $error = 'Falsches Passwort. Bitte versuche es erneut.';
    }
}

$hasAccess = !empty($_SESSION[$sessionKey]);
$kind = offerKind($offer);
$heading = offerHeading($offer);

// ===== Show offer =====
if ($hasAccess) {
    $viewId = (int)($_SESSION[$sessionKey . '_view'] ?? 0);
    // Mehrseitiges Angebot -> über /f/{slug}/{startseite}, damit relative
    // Links im Angebot (Unterseiten, CSS, Bilder) funktionieren.
    $frameSrc = (($offer['bundle_type'] ?? 'single') === 'bundle')
        ? bundleFileUrl($db, $slug, (string)($offer['entry_file'] ?? 'index.html'), bundleAccessToken($slug))
        : BASE_URL . 'file.php?slug=' . $slug;
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($offer['title']) ?></title>
    <style>html,body{margin:0;padding:0;height:100%;overflow:hidden}iframe{width:100%;height:100%;border:none;display:block}</style>
</head>
<body>
    <iframe src="<?= e($frameSrc) ?>"
            sandbox="allow-scripts allow-popups allow-forms allow-popups-to-escape-sandbox"></iframe>
    <script>
    (function() {
        var viewId = <?= $viewId ?>;
        var beaconUrl = <?= json_encode(BASE_URL . 'api/track.php') ?>;
        var start = Date.now();
        var lastSent = 0;

        function sendBeat(useBeacon) {
            var elapsed = Math.round((Date.now() - start) / 1000);
            if (elapsed === lastSent) return;
            lastSent = elapsed;
            var data = new URLSearchParams({ view_id: viewId, duration: elapsed });
            if (useBeacon && navigator.sendBeacon) {
                navigator.sendBeacon(beaconUrl, data);
            } else {
                fetch(beaconUrl, { method: 'POST', body: data, keepalive: true }).catch(function(){});
            }
        }

        // Heartbeat every 10s while tab is visible
        setInterval(function() {
            if (document.visibilityState === 'visible') sendBeat(false);
        }, 10000);

        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'hidden') sendBeat(true);
        });
        window.addEventListener('pagehide', function() { sendBeat(true); });
        window.addEventListener('beforeunload', function() { sendBeat(true); });
    })();
    </script>
</body>
</html>
    <?php
    exit;
}

// ===== Password gate =====
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($offer['title']) ?></title>
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>assets/style.css">
</head>
<body class="password-page">
<div class="password-card">
    <div class="login-icon">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
        </svg>
    </div>
    <h1><?= e($heading) ?></h1>
    <p class="sub"><?= e(kindSentence($kind, 'ist geschützt.')) ?> Bitte gib das Passwort ein, das du erhalten hast.</p>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="password" name="password" placeholder="Passwort" autofocus required>
        <button type="submit" class="btn btn-primary btn-full"><?= e($kind) ?> ansehen</button>
    </form>
</div>
</body>
</html>
<?php
/**
 * Neutrale "nicht verfügbar"-Seite (archiviert, pausiert oder abgelaufen) –
 * ohne Tracker-Hinweis, im Branding bzw. mit der eigenen Überschrift.
 */
function renderUnavailable(string $status, string $kind = 'Angebot', ?string $heading = null): void {
    $heading = ($heading !== null && $heading !== '') ? $heading : BRAND_NAME;
    switch ($status) {
        case 'archived':
            $msg  = 'Leider wurde ' . lcfirst(kindSentence($kind, 'archiviert.', 'dieser Inhalt'));
            $hint = 'Bitte melde dich direkt bei uns.';
            break;
        case 'expired':
            $msg  = kindSentence($kind, 'ist leider nicht mehr gültig.');
            $hint = 'Bitte wende dich bei Fragen an deinen Ansprechpartner.';
            break;
        default:
            $msg  = kindSentence($kind, 'ist derzeit nicht verfügbar.');
            $hint = 'Bitte wende dich bei Fragen an deinen Ansprechpartner.';
    }
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($heading) ?></title>
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>assets/style.css">
</head>
<body class="password-page">
<div class="password-card">
    <div class="login-icon">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>
        </svg>
    </div>
    <h1><?= e($heading) ?></h1>
    <p class="sub"><?= e($msg) ?> <?= e($hint) ?></p>
</div>
</body>
</html>
    <?php
}
