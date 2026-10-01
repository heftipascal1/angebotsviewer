<?php
/**
 * E-Mail-Versand über PHP mail() (funktioniert auf All-Inkl out-of-the-box).
 * Schlägt der Versand fehl, wird die Seite NICHT unterbrochen.
 */

function appHost(): string {
    $host = parse_url(BASE_URL, PHP_URL_HOST);
    return $host ?: ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function encodeSubject(string $subject): string {
    return '=?UTF-8?B?' . base64_encode($subject) . '?=';
}

function sendMailUtf8(string $to, string $subject, string $body, ?string $replyTo = null): bool {
    $from = 'noreply@' . appHost();
    $headers   = [];
    $headers[] = 'From: ' . BRAND_NAME . ' <' . $from . '>';
    if ($replyTo) $headers[] = 'Reply-To: ' . $replyTo;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';
    $headers[] = 'X-Mailer: ' . BRAND_NAME;

    try {
        return @mail($to, encodeSubject($subject), $body, implode("\r\n", $headers), '-f' . $from);
    } catch (Throwable $ex) {
        return false;
    }
}

/**
 * Benachrichtigung, dass ein Angebot angesehen wurde.
 * $meta = ['device'=>, 'browser'=>, 'os'=>], $viewCount = bisherige Aufrufe.
 */
function sendViewNotification(PDO $db, array $offer, array $meta, int $viewCount): void {
    if (getSetting($db, 'notify_enabled', '0') !== '1') return;
    $to = trim((string)getSetting($db, 'notify_email', ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;

    $when = date('d.m.Y H:i') . ' Uhr';
    $subject = '👀 Angesehen: ' . $offer['title'];

    $kind  = function_exists('offerKind') ? offerKind($offer) : 'Angebot';
    $body  = "Folgendes wurde gerade geöffnet: " . $kind . "\n\n";
    $body .= "Titel:     " . $offer['title'] . "\n";
    $body .= "Zeitpunkt: " . $when . "\n";
    $body .= "Gerät:     " . $meta['device'] . " · " . $meta['os'] . " · " . $meta['browser'] . "\n";
    $body .= "Aufrufe:   " . $viewCount . " gesamt\n\n";
    $body .= "Zur Statistik:\n" . BASE_URL . "admin/stats.php?id=" . (int)$offer['id'] . "\n";

    sendViewNotificationSafe($to, $subject, $body);
}

function sendViewNotificationSafe(string $to, string $subject, string $body): void {
    // Bewusst ohne Rückgabe – Fehler dürfen den Seitenaufbau nie stören.
    sendMailUtf8($to, $subject, $body);
}
