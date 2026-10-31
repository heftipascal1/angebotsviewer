<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/version.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/bundle.php';
require_once __DIR__ . '/includes/versions.php';
require_once __DIR__ . '/includes/tracking.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/migrations.php';
require_once __DIR__ . '/includes/reminders.php';

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

// Erinnerungen ggf. nach Auslieferung der Seite prüfen (höchstens 1x/Stunde)
register_shutdown_function(function () use ($db) {
    if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
    runRemindersIfDue($db);
});

$isAdmin     = isAdminLoggedIn();                 // Admin-Aufrufe werden nie gezählt
$isBot       = !$isAdmin && isPreviewBot();       // Link-Vorschauen & Bots ebenso wenig
$hasPassword = offerHasPassword($offer);

// Link-Vorschau (WhatsApp, iMessage, Outlook …) eines Angebots ohne Passwort:
// neutrale Seite ohne Inhalt, ohne Aufruf, ohne E-Mail.
if ($isBot && !$hasPassword) {
    renderBotPreview($offer);
    exit;
}

// Ohne Passwort: Zugang sofort. Der Aufruf wird aber erst gezählt, wenn die
// Seite im Browser läuft (JavaScript meldet sich bei api/track.php).
if (!$hasPassword && !$isAdmin) {
    $_SESSION[$sessionKey] = true;
}

// Handle password submit
if ($hasPassword && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isAdmin) {
        // Admin braucht kein Passwort und wird nicht gezählt
        header('Location: ' . BASE_URL . 'a/' . $slug);
        exit;
    }
    $lockRemaining = throttleStatus($db, $ipHash, 'offer_' . $offer['id']);
    if ($lockRemaining > 0) {
        $error = 'Zu viele Fehlversuche. Bitte in ' . ceil($lockRemaining / 60) . ' Min. erneut versuchen.';
    } elseif (password_verify($_POST['password'] ?? '', $offer['password_hash'])) {
        throttleReset($db, $ipHash, 'offer_' . $offer['id']);
        if (!$isBot) {
            recordView($db, $offer, $ipHash, $sessionKey);
        } else {
            $_SESSION[$sessionKey] = true;
        }
        header('Location: ' . BASE_URL . 'a/' . $slug);
        exit;
    } else {
        throttleFail($db, $ipHash, 'offer_' . $offer['id']);
        $error = 'Falsches Passwort. Bitte versuche es erneut.';
    }
}

// Admin sieht den Inhalt direkt; mit ?gate=1 die Passwort-Seite wie der Kunde.
$hasAccess = $isAdmin ? empty($_GET['gate']) : !empty($_SESSION[$sessionKey]);
$kind = offerKind($offer);
$heading = offerHeading($offer);
try {
    $curVersion = currentVersion($db, $offer);
} catch (Throwable $ex) {
    $curVersion = null;   // Versionsverlauf noch nicht migriert
}

// ===== Show offer =====
if ($hasAccess) {
    $viewId = $isAdmin ? 0 : (int)($_SESSION[$sessionKey . '_view'] ?? 0);
    $isBundleOffer = (($offer['bundle_type'] ?? 'single') === 'bundle');
    // Mehrseitiges Angebot -> über /f/{slug}/{startseite}, damit relative
    // Links im Angebot (Unterseiten, CSS, Bilder) funktionieren.
    $frameSrc = $isBundleOffer
        ? bundleFileUrl($db, $slug, (string)($offer['entry_file'] ?? 'index.html'), bundleAccessToken($slug))
        : BASE_URL . 'file.php?slug=' . $slug;
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($offer['title']) ?></title>
    <style>
        html,body{margin:0;padding:0;height:100%;overflow:hidden}
        body{display:flex;flex-direction:column}
        iframe{flex:1;width:100%;border:none;display:block}
        .av-adminbar{flex:none;display:flex;gap:.8rem;align-items:center;flex-wrap:wrap;padding:.45rem 1rem;background:#1e293b;color:#e2e8f0;font:13px/1.4 system-ui,-apple-system,sans-serif}
        .av-adminbar a{color:#93c5fd}
    </style>
</head>
<body>
    <?php if ($isAdmin): ?>
    <div class="av-adminbar">
        <strong>Admin-Ansicht</strong>
        <span>Du bist eingeloggt – dieser Aufruf wird nicht gezählt.<?= $curVersion !== null ? ' · ' . e(versionLabel($curVersion)) : '' ?></span>
        <a href="<?= e(BASE_URL . 'a/' . $slug . '?gate=1') ?>">Passwort-Seite ansehen</a>
        <a href="<?= e(BASE_URL . 'admin/stats.php?id=' . (int)$offer['id']) ?>">Statistik</a>
    </div>
    <?php endif; ?>
    <iframe id="avFrame" src="<?= e($frameSrc) ?>"
            sandbox="allow-scripts allow-popups allow-forms allow-popups-to-escape-sandbox"></iframe>
    <?php if (!$isAdmin): ?>
    <script>
    (function() {
        var viewId = <?= $viewId ?>;
        var slug = <?= json_encode($slug) ?>;
        var trackUrl = <?= json_encode(BASE_URL . 'api/track.php') ?>;
        var frame = document.getElementById('avFrame');
        var start = Date.now();
        var lastSent = -1;

        // ---- Zeit pro Unterseite (mehrseitige Angebote) ----
        // Jede ausgelieferte Seite meldet sich per postMessage mit ihrem Pfad.
        var pages = {}, curPage = null, curStart = Date.now(), visible = document.visibilityState === 'visible';
        function flushPage() {
            var now = Date.now();
            if (curPage && visible) pages[curPage] = (pages[curPage] || 0) + (now - curStart) / 1000;
            curStart = now;
        }
        window.addEventListener('message', function (ev) {
            if (!frame || ev.source !== frame.contentWindow) return;
            var d = ev.data;
            if (!d || d.__av !== 'page' || typeof d.p !== 'string') return;
            flushPage();
            curPage = d.p.slice(0, 255);
            if (!(curPage in pages)) pages[curPage] = 0;
        });

        function payload() {
            flushPage();
            var rounded = {};
            for (var k in pages) rounded[k] = Math.round(pages[k]);
            return new URLSearchParams({
                view_id: viewId,
                duration: Math.round((Date.now() - start) / 1000),
                pages: JSON.stringify(rounded)
            });
        }
        function sendBeat(useBeacon) {
            if (!viewId) return;
            var data = payload();
            var elapsed = +data.get('duration');
            if (elapsed === lastSent && !useBeacon) return;
            lastSent = elapsed;
            if (useBeacon && navigator.sendBeacon) {
                navigator.sendBeacon(trackUrl, data);
            } else {
                fetch(trackUrl, { method: 'POST', body: data, keepalive: true, credentials: 'same-origin' }).catch(function(){});
            }
        }

        // Aufruf erst jetzt zählen (nur Angebote ohne Passwort): echte Browser
        // führen dieses Script aus, Link-Vorschau-Dienste in der Regel nicht.
        if (!viewId) {
            fetch(trackUrl, {
                method: 'POST', credentials: 'same-origin',
                body: new URLSearchParams({ action: 'start', slug: slug })
            }).then(function (r) { return r.json(); })
              .then(function (j) { if (j && j.view_id) { viewId = j.view_id; start = Date.now(); } })
              .catch(function(){});
        }

        // Heartbeat alle 10 s, solange der Tab sichtbar ist
        setInterval(function() {
            if (document.visibilityState === 'visible') sendBeat(false);
        }, 10000);

        document.addEventListener('visibilitychange', function() {
            flushPage();
            visible = document.visibilityState === 'visible';
            if (!visible) sendBeat(true);
        });
        window.addEventListener('pagehide', function() { sendBeat(true); });
    })();
    </script>
    <?php endif; ?>
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
    <?php if ($curVersion !== null): ?>
        <p class="sub" style="font-size:.78rem;opacity:.7;margin-top:-.4rem"><?= e(versionLabel($curVersion)) ?></p>
    <?php endif; ?>

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

/**
 * Neutrale Seite für Link-Vorschau-Dienste (WhatsApp, iMessage, Outlook …)
 * bei Angeboten ohne Passwort: zeigt nur Überschrift und Bezeichnung –
 * keinen Inhalt, kein Tracking, keine E-Mail.
 */
function renderBotPreview(array $offer): void {
    $heading = offerHeading($offer);
    $kind = offerKind($offer);
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($heading) ?></title>
    <meta property="og:title" content="<?= e($heading) ?>">
    <meta property="og:description" content="<?= e($kind) ?> ansehen">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>assets/style.css">
</head>
<body class="password-page">
<div class="password-card">
    <h1><?= e($heading) ?></h1>
    <p class="sub"><?= e($kind) ?> ansehen</p>
</div>
</body>
</html>
    <?php
}
