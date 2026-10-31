<?php
/**
 * Aufruf-Tracking: Bot-Erkennung, Aufruf aufzeichnen, Seitenzeiten.
 *
 * Grundsätze:
 *  - Link-Vorschauen (WhatsApp, iMessage, Outlook, Slack …) und andere Bots
 *    erzeugen nie einen Aufruf und nie eine E-Mail.
 *  - Bei Angeboten ohne Passwort wird der Aufruf erst gezählt, wenn die Seite
 *    tatsächlich im Browser läuft (JavaScript meldet sich per api/track.php).
 *    Vorschau-Dienste führen in der Regel kein JavaScript aus.
 *  - Ist der Admin eingeloggt, wird nie gezählt.
 */

/** true, wenn die Anfrage von einem Bot / Link-Vorschau-Dienst stammt. */
function isPreviewBot(?string $ua = null): bool {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') return true;
    $ua = $ua ?? (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (trim($ua) === '') return true;
    return (bool)preg_match(
        '~(?<!cu)bot(?:[/;)\s_-]|$)|crawl|spider|slurp|facebookexternalhit|facebot|whatsapp|telegram'
        . '|slack|discord|skypeuripreview|linkedin|pinterest|embedly|quora link preview|vkshare'
        . '|bingpreview|microsoftpreview|ms-office|msoffice|microsoft office/|google-pagerenderer|googleimageproxy'
        . '|google-read-aloud|headlesschrome|phantomjs|python-|curl/|wget|go-http-client|okhttp'
        . '|java/|libwww|httpclient|node-fetch|axios/|proofpoint|mimecast|barracuda|preview~i',
        $ua
    );
}

/**
 * Zugang freischalten und einen neuen Aufruf aufzeichnen (Statistik,
 * Heartbeat-Berechtigung, E-Mail-Benachrichtigung mit Spam-Schutz).
 * Liefert die ID des neuen Aufrufs.
 */
function recordView(PDO $db, array $offer, string $ipHash, string $sessionKey): int {
    $_SESSION[$sessionKey] = true;

    $viewSession = bin2hex(random_bytes(16));
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

    // Aufruf der gerade angezeigten Version zuordnen (Spalte ab Schema 7;
    // vor der Migration fällt der Insert auf die alte Form zurück).
    $versionId = (int)($offer['current_version_id'] ?? 0);
    try {
        $ins = $db->prepare("
            INSERT INTO offer_views (offer_id, session_hash, ip_hash, user_agent, started_at, last_heartbeat, version_id)
            VALUES (?, ?, ?, ?, NOW(), NOW(), ?)
        ");
        $ins->execute([$offer['id'], $viewSession, $ipHash, $ua, $versionId > 0 ? $versionId : null]);
    } catch (PDOException $ex) {
        $ins = $db->prepare("
            INSERT INTO offer_views (offer_id, session_hash, ip_hash, user_agent, started_at, last_heartbeat)
            VALUES (?, ?, ?, ?, NOW(), NOW())
        ");
        $ins->execute([$offer['id'], $viewSession, $ipHash, $ua]);
    }
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
    return $newViewId;
}

/**
 * Speichert die Verweildauer pro Unterseite eines mehrseitigen Angebots.
 * $pages = ['kontakt/index.html' => 42, …] (Sekunden, aufsummiert pro Aufruf).
 * Nur Seiten, die es im Ordner der gesehenen Version wirklich gibt, werden
 * übernommen – Fremdeingaben landen so nie in der Statistik.
 */
function storePageTimes(PDO $db, int $viewId, array $pages): int {
    if ($viewId <= 0 || !$pages) return 0;
    $stmt = $db->prepare("
        SELECT vw.offer_id, COALESCE(ver.filename, o.filename) AS folder,
               COALESCE(ver.bundle_type, o.bundle_type) AS btype
        FROM offer_views vw
        JOIN offers o ON o.id = vw.offer_id
        LEFT JOIN offer_versions ver ON ver.id = vw.version_id
        WHERE vw.id = ?
    ");
    $stmt->execute([$viewId]);
    $row = $stmt->fetch();
    if (!$row || $row['btype'] !== 'bundle') return 0;
    $base = realpath(bundleDir((string)$row['folder']));
    if ($base === false) return 0;

    $ins = $db->prepare("
        INSERT INTO offer_page_views (view_id, offer_id, page, seconds)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE seconds = GREATEST(seconds, VALUES(seconds))
    ");
    $stored = 0;
    foreach (array_slice($pages, 0, 50, true) as $page => $seconds) {
        $page = ltrim(str_replace('\\', '/', (string)$page), '/');
        if ($page === '' || strlen($page) > 255 || strpos($page, '..') !== false || strpos($page, "\0") !== false) continue;
        $real = realpath($base . '/' . $page);
        if ($real === false || strpos($real, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($real)) continue;
        $seconds = max(0, min(21600, (int)round((float)$seconds)));
        $ins->execute([$viewId, (int)$row['offer_id'], $page, $seconds]);
        $stored++;
    }
    return $stored;
}

/** Lesbarer Name einer Unterseite: <title> der Datei, sonst der Pfad. */
function pageLabel(string $folder, string $page): string {
    static $cache = [];
    $key = $folder . '|' . $page;
    if (isset($cache[$key])) return $cache[$key];
    $label = '';
    $base = realpath(bundleDir($folder));
    $file = $base ? realpath($base . '/' . $page) : false;
    if ($file && strpos($file, $base . DIRECTORY_SEPARATOR) === 0 && is_file($file)) {
        $head = (string)@file_get_contents($file, false, null, 0, 65536);
        if (preg_match('~<title[^>]*>(.*?)</title>~is', $head, $m)) {
            $label = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')));
        }
    }
    if ($label === '') {
        $label = preg_replace('~(^|/)index\.html?$~i', '$1', $page);
        $label = $label === '' ? 'Startseite' : '/' . $label;
    }
    return $cache[$key] = mb_substr($label, 0, 80);
}
