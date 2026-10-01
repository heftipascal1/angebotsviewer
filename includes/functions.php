<?php

function generateSlug(int $length = 8): string {
    return bin2hex(random_bytes($length));
}

function hashIP(string $ip): string {
    return hash('sha256', $ip . APP_SECRET);
}

function formatDuration(int $seconds): string {
    if ($seconds < 60) return $seconds . 's';
    if ($seconds < 3600) return floor($seconds / 60) . 'min ' . ($seconds % 60) . 's';
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    return $h . 'h ' . $m . 'min';
}

function timeAgo(string $datetime): string {
    $now = new DateTime();
    $past = new DateTime($datetime);
    $diff = $now->diff($past);

    if ($diff->y > 0) return 'vor ' . $diff->y . ' Jahr' . ($diff->y > 1 ? 'en' : '');
    if ($diff->m > 0) return 'vor ' . $diff->m . ' Monat' . ($diff->m > 1 ? 'en' : '');
    if ($diff->d > 0) return 'vor ' . $diff->d . ' Tag' . ($diff->d > 1 ? 'en' : '');
    if ($diff->h > 0) return 'vor ' . $diff->h . ' Stunde' . ($diff->h > 1 ? 'n' : '');
    if ($diff->i > 0) return 'vor ' . $diff->i . ' Minute' . ($diff->i > 1 ? 'n' : '');
    return 'gerade eben';
}

function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/* ===== Sichere Session ===== */
function secureSession(): void {
    if (session_status() !== PHP_SESSION_NONE) return;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $secure,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* ===== Symmetrische Verschlüsselung (für rücklesbares Angebots-Passwort) =====
   Schlüssel wird aus APP_SECRET abgeleitet. Liegt das DB-Backup ohne config.php
   vor, sind die Passwörter NICHT lesbar. */
function encryptSecret(string $plain): string {
    if (!function_exists('openssl_encrypt')) return '';
    $key = hash('sha256', APP_SECRET, true);
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) return '';
    return base64_encode($iv . $tag . $ct);
}

function decryptSecret(?string $enc): ?string {
    if (!$enc || !function_exists('openssl_decrypt')) return null;
    $raw = base64_decode($enc, true);
    if ($raw === false || strlen($raw) < 28) return null;
    $key = hash('sha256', APP_SECRET, true);
    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct  = substr($raw, 28);
    $pt  = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $pt === false ? null : $pt;
}

/* ===== Bezeichnung eines Eintrags (Angebot / Webseite / Vorschau / eigene) ===== */

/** Vorschläge im Auswahlfeld. Zusätzlich ist eine freie Eingabe möglich. */
function kindPresets(): array {
    return ['Angebot', 'Webseite', 'Vorschau', 'Präsentation', 'Konzept', 'Entwurf', 'Dokument'];
}

/** Bereinigt eine eingegebene Bezeichnung. Leer -> 'Angebot'. */
function normalizeKind(string $label): string {
    $label = trim(preg_replace('/\s+/u', ' ', $label));
    if ($label === '') return 'Angebot';
    if (mb_strlen($label) > 40) $label = mb_substr($label, 0, 40);
    return $label;
}

function offerKind(array $offer): string {
    $k = trim((string)($offer['kind_label'] ?? ''));
    return $k !== '' ? $k : 'Angebot';
}

/**
 * Passender Artikel für die Kunden-Texte ("Dieses Angebot", "Diese Webseite").
 * Für unbekannte, frei eingegebene Bezeichnungen wird null geliefert – dann
 * weichen die Texte auf eine neutrale Formulierung aus.
 */
function kindArticle(string $label): ?string {
    static $map = [
        'angebot' => 'Dieses', 'dokument' => 'Dieses', 'konzept' => 'Dieses',
        'exposé' => 'Dieses', 'expose' => 'Dieses', 'portfolio' => 'Dieses',
        'webseite' => 'Diese', 'website' => 'Diese', 'homepage' => 'Diese',
        'vorschau' => 'Diese', 'präsentation' => 'Diese', 'praesentation' => 'Diese',
        'seite' => 'Diese', 'demo' => 'Diese', 'mappe' => 'Diese',
        'entwurf' => 'Dieser', 'vorschlag' => 'Dieser', 'katalog' => 'Dieser',
    ];
    return $map[mb_strtolower($label)] ?? null;
}

/** Satz wie "Dieses Angebot ist geschützt." bzw. neutraler Ersatz. */
function kindSentence(string $label, string $predicate, string $fallbackSubject = 'Dieser Inhalt'): string {
    $article = kindArticle($label);
    $subject = $article ? $article . ' ' . $label : $fallbackSubject;
    return $subject . ' ' . $predicate;
}

/* ===== Überschrift & Passwort-Schutz pro Eintrag ===== */

/** Bereinigt eine eingegebene Überschrift. Leer -> '' (= Standard BRAND_NAME). */
function normalizeHeading(string $heading): string {
    $heading = trim(preg_replace('/\s+/u', ' ', $heading));
    if (mb_strlen($heading) > 80) $heading = mb_substr($heading, 0, 80);
    return $heading;
}

/** Überschrift, die der Kunde auf der Passwort-/Hinweis-Seite sieht. */
function offerHeading(array $offer): string {
    $h = trim((string)($offer['heading'] ?? ''));
    return $h !== '' ? $h : BRAND_NAME;
}

/** true, wenn der Eintrag passwortgeschützt ist (leerer Hash = frei zugänglich). */
function offerHasPassword(array $offer): bool {
    return trim((string)($offer['password_hash'] ?? '')) !== '';
}

/* ===== Status eines Eintrags ===== */

/** 'archived' | 'expired' | 'paused' | 'active' (in dieser Priorität). */
function offerStatus(array $offer): string {
    if (!empty($offer['is_archived']) && (int)$offer['is_archived'] === 1) return 'archived';
    if (!empty($offer['expires_at']) && strtotime($offer['expires_at']) < time()) return 'expired';
    if (!empty($offer['is_paused']) && (int)$offer['is_paused'] === 1) return 'paused';
    return 'active';
}

/** Anzeigenamen der Status-Werte (für Badges und Filter). */
function offerStatusLabels(): array {
    return ['active' => 'Aktiv', 'paused' => 'Pausiert', 'expired' => 'Abgelaufen', 'archived' => 'Archiviert'];
}

/** Text für den Platzhalter {passwort} in der Copy-Paste-Nachricht. */
function offerPasswordText(array $offer): string {
    if (!offerHasPassword($offer)) return 'nicht nötig – der Link öffnet sich direkt';
    $plain = decryptSecret($offer['password_enc'] ?? null);
    return $plain ?? '(beim Bearbeiten neu setzen)';
}

/* ===== Copy-Paste-Nachricht ===== */
function defaultMessageTemplate(): string {
    return "Hallo,\n\n"
        . "vielen Dank für Ihr Interesse – hier ist Ihr persönlicher Zugang zu \"{titel}\":\n\n"
        . "Link: {link}\n"
        . "Passwort: {passwort}\n\n"
        . "Gültig bis: {gueltig_bis}\n\n"
        . "Bei Fragen melde ich mich gerne.\n\n"
        . "Viele Grüße";
}

function renderOfferMessage(string $template, array $vars): string {
    return strtr($template, [
        '{typ}'         => $vars['typ'] ?? 'Angebot',
        '{titel}'       => $vars['titel'] ?? '',
        '{link}'        => $vars['link'] ?? '',
        '{passwort}'    => $vars['passwort'] ?? '',
        '{gueltig_bis}' => $vars['gueltig_bis'] ?? '',
    ]);
}

/**
 * Wertet einen User-Agent aus und liefert Gerät, Browser und Betriebssystem.
 * Reine String-Auswertung des gespeicherten User-Agents – kein zusätzliches
 * Tracking, kein Schema-Eingriff.
 */
function parseUA(?string $ua): array {
    if (!$ua) return ['device' => 'Unbekannt', 'browser' => 'Unbekannt', 'os' => 'Unbekannt'];

    // Gerät
    if (preg_match('/ipad|tablet|playbook|silk/i', $ua)) {
        $device = 'Tablet';
    } elseif (preg_match('/mobile|android.*mobile|iphone|ipod|windows phone/i', $ua)) {
        $device = 'Smartphone';
    } else {
        $device = 'Desktop';
    }

    // Betriebssystem
    if (preg_match('/windows nt/i', $ua))            $os = 'Windows';
    elseif (preg_match('/iphone|ipad|ipod/i', $ua))  $os = 'iOS';
    elseif (preg_match('/mac os x|macintosh/i', $ua))$os = 'macOS';
    elseif (preg_match('/android/i', $ua))           $os = 'Android';
    elseif (preg_match('/linux/i', $ua))             $os = 'Linux';
    else                                             $os = 'Unbekannt';

    // Browser (Reihenfolge wichtig: Edge/Chrome vor Safari)
    if (preg_match('/edg(e|a|ios)?\//i', $ua))           $browser = 'Edge';
    elseif (preg_match('/opr\/|opera/i', $ua))           $browser = 'Opera';
    elseif (preg_match('/firefox|fxios/i', $ua))         $browser = 'Firefox';
    elseif (preg_match('/chrome|crios/i', $ua))          $browser = 'Chrome';
    elseif (preg_match('/safari/i', $ua))                $browser = 'Safari';
    else                                                 $browser = 'Unbekannt';

    return ['device' => $device, 'browser' => $browser, 'os' => $os];
}

/* ===== Einstellungs-Speicher (settings-Tabelle) ===== */

function ensureSettingsTable(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS settings (
            k VARCHAR(64) PRIMARY KEY,
            v VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function getSetting(PDO $db, string $key, ?string $default = null): ?string {
    ensureSettingsTable($db);
    $stmt = $db->prepare("SELECT v FROM settings WHERE k = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['v'] : $default;
}

function setSetting(PDO $db, string $key, string $value): void {
    ensureSettingsTable($db);
    $stmt = $db->prepare("
        INSERT INTO settings (k, v) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE v = VALUES(v)
    ");
    $stmt->execute([$key, $value]);
}

/* ===== Brute-Force-Schutz (auth_throttle) =====
   Alle Funktionen sind "fail-open": Wenn die Tabelle (noch) fehlt, blockieren
   sie niemanden – so kann man sich auch direkt nach einem Code-Update einloggen
   und die Migration ausführen. */

const THROTTLE_MAX_ATTEMPTS = 5;
const THROTTLE_LOCK_MINUTES = 10;

/** Liefert die verbleibende Sperrzeit in Sekunden (0 = nicht gesperrt). */
function throttleStatus(PDO $db, string $ipHash, string $context): int {
    try {
        $stmt = $db->prepare("SELECT locked_until FROM auth_throttle WHERE ip_hash = ? AND context = ?");
        $stmt->execute([$ipHash, $context]);
        $row = $stmt->fetch();
        if ($row && !empty($row['locked_until'])) {
            $remaining = strtotime($row['locked_until']) - time();
            if ($remaining > 0) return $remaining;
            // Sperre abgelaufen → zurücksetzen
            $db->prepare("DELETE FROM auth_throttle WHERE ip_hash = ? AND context = ?")
               ->execute([$ipHash, $context]);
        }
    } catch (Throwable $ex) { /* fail-open */ }
    return 0;
}

/** Registriert einen Fehlversuch und sperrt bei Überschreitung der Grenze. */
function throttleFail(PDO $db, string $ipHash, string $context): void {
    try {
        $db->prepare("
            INSERT INTO auth_throttle (ip_hash, context, attempts, updated_at)
            VALUES (?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE attempts = attempts + 1, updated_at = NOW()
        ")->execute([$ipHash, $context]);

        $stmt = $db->prepare("SELECT attempts FROM auth_throttle WHERE ip_hash = ? AND context = ?");
        $stmt->execute([$ipHash, $context]);
        $attempts = (int)$stmt->fetchColumn();

        if ($attempts >= THROTTLE_MAX_ATTEMPTS) {
            $db->prepare("
                UPDATE auth_throttle
                SET locked_until = (NOW() + INTERVAL " . THROTTLE_LOCK_MINUTES . " MINUTE)
                WHERE ip_hash = ? AND context = ?
            ")->execute([$ipHash, $context]);
        }
    } catch (Throwable $ex) { /* fail-open */ }
}

/** Setzt den Zähler nach erfolgreichem Login zurück. */
function throttleReset(PDO $db, string $ipHash, string $context): void {
    try {
        $db->prepare("DELETE FROM auth_throttle WHERE ip_hash = ? AND context = ?")
           ->execute([$ipHash, $context]);
    } catch (Throwable $ex) { /* fail-open */ }
}
