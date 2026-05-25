<?php
define('APP_NAME', 'RoknixRP Note');

// Konfiguration + Secrets liegen in data/secret.php (außerhalb von Git, .htaccess Deny all).
// secret.php muss definieren: APP_PEPPER, DB_HOST, DB_NAME, DB_USER, DB_PASS.
// Bei frischer Installation wird die Datei mit Pepper auto-generiert; die DB-Credentials
// muss der Admin manuell eintragen (siehe data/secret.php.example).
(function () {
    $secretPath = dirname(__DIR__) . '/data/secret.php';
    if (!file_exists($secretPath)) {
        // Frische Installation: Pepper erzeugen, DB-Credentials als Platzhalter.
        $dataDir = dirname($secretPath);
        if (!is_dir($dataDir)) mkdir($dataDir, 0750, true);
        $pepper  = bin2hex(random_bytes(32));
        $content = "<?php\n"
                 . "// Auto-generiert beim ersten Start. APP_PEPPER niemals ändern, solange User existieren.\n"
                 . "// DB_* manuell mit deinen Datenbankzugangsdaten befüllen, dann Seite neu laden.\n"
                 . "define('APP_PEPPER', " . var_export($pepper, true) . ");\n"
                 . "define('DB_HOST', 'BITTE_EINTRAGEN'); // z.B. localhost\n"
                 . "define('DB_NAME', 'BITTE_EINTRAGEN'); // Name deiner Datenbank\n"
                 . "define('DB_USER', 'BITTE_EINTRAGEN'); // Datenbankbenutzer\n"
                 . "define('DB_PASS', 'BITTE_EINTRAGEN'); // Datenbankpasswort\n";
        file_put_contents($secretPath, $content, LOCK_EX);
        @chmod($secretPath, 0640);
        http_response_code(500);
        die('Konfigurationsfehler: data/secret.php wurde neu erzeugt. Bitte DB-Credentials eintragen und Seite neu laden.');
    }

    require_once $secretPath;
    $missing = [];
    foreach (['APP_PEPPER','DB_HOST','DB_NAME','DB_USER','DB_PASS'] as $c) {
        if (!defined($c) || constant($c) === 'BITTE_EINTRAGEN') $missing[] = $c;
    }
    if ($missing) {
        http_response_code(500);
        die('Konfigurationsfehler: data/secret.php unvollständig. Fehlt/Platzhalter: ' . implode(', ', $missing));
    }
})();

// Kurze aktive PHP-Session (Garbage-Collection-resistent auf Shared Hosting).
// Längeres "Angemeldet bleiben" läuft über Remember-Me-Tokens (siehe auth.php).
define('SESSION_LIFETIME', 7200); // 2 Stunden
define('REMEMBER_ME_LIFETIME', 2592000); // 30 Tage
define('REMEMBER_ME_COOKIE', 'rem');
define('MAX_FAILED_ATTEMPTS', 5);
define('LOCKOUT_SECONDS', 900); // 15 Minuten
define('MIN_PASSWORD_LENGTH', 8);

// Per-IP Login-Drossel (ergänzt das per-User-Lockout). Etwas großzügiger,
// damit getrennte legitime Nutzer hinter NAT/Carrier nicht gegenseitig sperren.
define('IP_MAX_FAILED_ATTEMPTS', 10);
define('IP_LOCKOUT_SECONDS', 900);     // 15 Minuten Sperre nach Überschreitung
define('IP_ATTEMPT_WINDOW_SECONDS', 900); // 15min Zählfenster bevor failed_count resettet

date_default_timezone_set('Europe/Berlin');

// ===== CSP-Nonce + Security-Headers =====
// Nonce wird einmal pro Request erzeugt und in jedem <script>-Tag wiederholt.
// Damit kann die CSP 'unsafe-inline' (für Skripte) später entfernt werden.
function cspNonce(): string {
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }
    return $nonce;
}

/*
 * Setzt die Content-Security-Policy + ergänzende Security-Header dynamisch,
 * damit der Nonce-Wert pro Request frisch ist. Ersetzt das CSP-Setzen aus
 * der .htaccess. Wird automatisch beim Laden von config.php ausgeführt.
 *
 * script-src nutzt jetzt Nonce statt 'unsafe-inline'. Voraussetzung:
 *   1. Jeder Inline-Script-Tag trägt das Nonce-Attribut (siehe Templates).
 *   2. KEINE Inline-Event-Handler (onclick=…) mehr — diese sind durch Event-
 *      Delegation per data-action ersetzt. Siehe assets/app.js.
 * Sobald wieder Inline-Handler ins HTML rutschen, brechen sie hier.
 *
 * style-src behält 'unsafe-inline' — viele Stylings stehen als inline-Attribut
 * in Templates (z.B. avatar_color), das ist deutlich weniger riskant als JS.
 */
function sendSecurityHeaders(): void {
    if (headers_sent()) return;
    $nonce = cspNonce();
    $csp = "default-src 'self'; "
         . "script-src 'self' 'nonce-{$nonce}'; "
         . "style-src 'self' 'unsafe-inline' fonts.googleapis.com; "
         . "font-src 'self' fonts.gstatic.com; "
         . "img-src 'self' data:; "
         . "connect-src 'self'; "
         . "frame-ancestors 'none'; "
         . "base-uri 'self'; "
         . "form-action 'self';";
    header('Content-Security-Policy: ' . $csp);
}

sendSecurityHeaders();

// Mehrsprachigkeit: muss nach DB-Konfig geladen werden, weil resolveLocale() in
// eingeloggten Requests die DB nach users.locale fragt.
require_once __DIR__ . '/i18n.php';
