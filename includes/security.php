<?php
/**
 * Isolation hochgeladener Inhalte.
 *
 * Grundsätze:
 *  1. Hochgeladene Dateien liegen ausschließlich in uploads/ und sind von
 *     außen nie direkt erreichbar (uploads/.htaccess, vom Tool gepflegt).
 *  2. Nichts aus einem Upload wird auf dem Server ausgeführt: Es gibt nur
 *     eine Positivliste harmloser Dateitypen, gefährliche Namen (auch
 *     versteckt wie "bild.php.png") werden abgelehnt, und file.php liest
 *     Dateien nur – es führt sie nie aus.
 *  3. Inhalte, die im Browser Code ausführen können (HTML, SVG, XML),
 *     werden IMMER mit "Content-Security-Policy: sandbox" ausgeliefert.
 *     Sie laufen dadurch in einem eigenen, abgeschotteten Ursprung – auch
 *     wenn jemand die Datei direkt in einem Tab öffnet – und können nie im
 *     Namen eines eingeloggten Admins handeln.
 */

/** CSP für ausgelieferte Inhalte: eigener, abgeschotteter Ursprung. */
const UPLOAD_SANDBOX_CSP = "sandbox allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox; frame-ancestors 'self'";

/** Dateitypen, die im Browser Code ausführen können → immer Sandbox. */
function uploadIsActiveContent(string $ext): bool {
    return in_array(strtolower($ext), ['html', 'htm', 'svg', 'xml', 'xhtml', 'shtml'], true);
}

/**
 * true, wenn ein Pfadsegment eine gefährliche Endung enthält – egal an
 * welcher Stelle ("shell.php", "bild.php.png", "x.PHTML", "a.phar.jpg").
 * Manche Server-Konfigurationen führen auch Dateien mit Doppel-Endung aus.
 */
function uploadNameIsDangerous(string $segment): bool {
    $parts = explode('.', strtolower($segment));
    array_shift($parts);                          // Teil vor dem ersten Punkt ist der Name
    // Endungen, die ein Webserver als Programm ausführen oder als Konfiguration
    // lesen könnte. (Sprachkürzel wie "index.pl.html" bleiben erlaubt – CGI ist
    // im Upload-Ordner ohnehin abgeschaltet.)
    static $bad = [
        'php', 'php2', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar', 'inc',
        'cgi', 'fcgi', 'asp', 'aspx', 'ashx', 'asmx', 'jsp', 'jspx',
        'shtml', 'shtm', 'stm', 'htaccess', 'htpasswd', 'ini',
    ];
    foreach ($parts as $p) {
        if (in_array($p, $bad, true)) return true;
    }
    return false;
}

/** Soll-Inhalt von uploads/.htaccess (mehrfach abgesichert, Apache 2.2 und 2.4). */
function uploadsHtaccessContent(): string {
    return <<<'HTACCESS'
# ===== Angebotsviewer: Schutz des Upload-Ordners =====
# Wird vom Tool automatisch angelegt und repariert – bitte nicht ändern.
# Kein direkter Zugriff: Inhalte werden ausschließlich über file.php
# ausgeliefert (mit Passwort-/Token-Prüfung und Sandbox).

<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>

Options -Indexes -ExecCGI

# Falls doch eine Datei durchrutschen sollte: niemals als Programm ausführen
<IfModule mod_mime.c>
    RemoveHandler .php .phtml .pht .phar .php3 .php4 .php5 .php7 .php8 .phps .cgi .pl .py .sh .asp .aspx .shtml
    RemoveType .php .phtml .pht .phar .php3 .php4 .php5 .php7 .php8 .phps
</IfModule>
<FilesMatch "\.(php\d?|phtml|pht|phar|phps|cgi|pl|py|sh|asp|aspx|jsp|shtml)(\.|$)">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
</FilesMatch>
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>
<IfModule mod_php5.c>
    php_flag engine off
</IfModule>
HTACCESS;
}

/**
 * Stellt sicher, dass uploads/ existiert und korrekt gesperrt ist.
 * Fehlt die Sperre oder wurde sie verändert, wird sie neu geschrieben.
 * Liefert false, wenn das nicht möglich war (keine Schreibrechte).
 */
function ensureUploadsProtection(): bool {
    $dir = dirname(__DIR__) . '/uploads';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    $file = $dir . '/.htaccess';
    $want = uploadsHtaccessContent();
    if (!is_file($file) || (string)@file_get_contents($file) !== $want) {
        if (@file_put_contents($file, $want, LOCK_EX) === false) return false;
    }
    // Prüfdatei für den Live-Test (leer, harmlos)
    if (!is_file($dir . '/.gitkeep')) @file_put_contents($dir . '/.gitkeep', '');
    return true;
}

/**
 * Live-Test: Ist uploads/ von außen wirklich gesperrt?
 * Fragt die eigene Prüfdatei über das Internet ab. 2xx = NICHT geschützt.
 * @return array{tested:bool, protected:bool, code:int}
 */
function uploadsProtectionSelfTest(): array {
    if (!function_exists('bundleHttpStatus')) return ['tested' => false, 'protected' => false, 'code' => 0];
    $code = bundleHttpStatus(BASE_URL . 'uploads/.gitkeep');
    if ($code === 0) return ['tested' => false, 'protected' => false, 'code' => 0];
    return ['tested' => true, 'protected' => $code < 200 || $code >= 300, 'code' => $code];
}

/** Sicherheits-Header für jede Auslieferung eines hochgeladenen Inhalts. */
function sendUploadSecurityHeaders(string $ext): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: no-referrer');
    if (uploadIsActiveContent($ext)) {
        header('Content-Security-Policy: ' . UPLOAD_SANDBOX_CSP);
    } else {
        header("Content-Security-Policy: frame-ancestors 'self'");
        // Bilder, Schriften, CSS, JS werden aus der abgeschotteten Seite
        // (Ursprung "null") geladen – Schriften & Module brauchen dafür CORS.
        // Der Zugriff ist ohnehin über das Token im Pfad geschützt.
        header('Access-Control-Allow-Origin: *');
    }
}
