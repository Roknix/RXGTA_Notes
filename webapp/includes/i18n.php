<?php
/*
 * Mehrsprachigkeit (i18n).
 *
 * Konvention:
 *   - Übersetzungs-Keys sind in includes/lang/<locale>.php gepflegt (PHP-Array-Return).
 *   - Aufruf in PHP-Templates: <?= h(__('nav.dashboard')) ?>
 *     -> __() liefert ROH-Text, die HTML-Escape-Pflicht bleibt beim Aufrufer.
 *   - Aufruf in JS:           t('js.toast.saved')
 *     -> window.I18N enthält die "js."-Untermenge, in header.php als JSON eingespielt.
 *   - Argumente für %s / %1$s werden via vsprintf eingesetzt.
 *
 * Sicherheit:
 *   - Locale-Werte durchlaufen IMMER tryLocale()/normalizeLocale() (strenge Whitelist),
 *     bevor sie in einen Dateipfad fließen. Sonst Path-Traversal über Cookie/Header.
 *   - Übersetzungs-Strings dürfen KEIN Markup enthalten. Pflicht im lang-File-Header.
 *   - vsprintf-Format-Strings stammen aus den lang-Files (vertrauenswürdig), nie aus User-Input.
 */

const SUPPORTED_LOCALES = ['en', 'de'];
const DEFAULT_LOCALE    = 'en';
const LOCALE_COOKIE     = 'locale';

function tryLocale($raw): ?string {
    if (!is_string($raw)) return null;
    $raw = strtolower(substr($raw, 0, 5));
    return in_array($raw, SUPPORTED_LOCALES, true) ? $raw : null;
}

function normalizeLocale($raw): string {
    return tryLocale($raw) ?? DEFAULT_LOCALE;
}

function detectFromAcceptLanguage(): ?string {
    $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    if ($header === '') return null;
    // Einfacher Parse: erste 2 Zeichen jedes Eintrags prüfen, q-Werte werden ignoriert
    // (die meisten Browser senden den Wunsch sowieso zuerst).
    foreach (explode(',', $header) as $part) {
        $code = strtolower(substr(trim($part), 0, 2));
        if (in_array($code, SUPPORTED_LOCALES, true)) return $code;
    }
    return null;
}

function resolveLocale(): string {
    static $cached = null;
    if ($cached !== null) return $cached;

    // 1) Eingeloggter User: bevorzugte Sprache aus DB
    if (!empty($_SESSION['user_id'])) {
        try {
            $stmt = getDB()->prepare("SELECT locale FROM users WHERE id = ?");
            $stmt->execute([(int)$_SESSION['user_id']]);
            $fromDb = tryLocale($stmt->fetchColumn());
            if ($fromDb !== null) return $cached = $fromDb;
        } catch (Throwable $e) {
            // DB-Fehler hier nicht eskalieren — Cookie/Header sind brauchbare Fallbacks.
        }
    }

    // 2) Explizites Cookie (vom Sprachschalter gesetzt)
    $fromCookie = tryLocale($_COOKIE[LOCALE_COOKIE] ?? null);
    if ($fromCookie !== null) return $cached = $fromCookie;

    // 3) Browser Accept-Language
    $fromHeader = detectFromAcceptLanguage();
    if ($fromHeader !== null) return $cached = $fromHeader;

    return $cached = DEFAULT_LOCALE;
}

function loadLocaleStrings(string $locale): array {
    static $cache = [];
    $locale = normalizeLocale($locale);
    if (isset($cache[$locale])) return $cache[$locale];

    // Englisch ist Master/Fallback — fehlende Keys in einer anderen Sprache
    // fallen automatisch auf die englische Variante zurück statt auf den Roh-Key.
    $en = require __DIR__ . '/lang/en.php';
    if ($locale === 'en') return $cache['en'] = $en;

    $other = require __DIR__ . "/lang/{$locale}.php";
    return $cache[$locale] = array_merge($en, $other);
}

function __(string $key, ...$args): string {
    static $strings = null;
    if ($strings === null) {
        $strings = loadLocaleStrings(resolveLocale());
    }
    $msg = $strings[$key] ?? $key;
    if (!$args) return $msg;
    // vsprintf-Format-Strings kommen ausschließlich aus den lang-Files (vertrauenswürdig).
    // User-Daten landen als $args und werden durch vsprintf escaped behandelt.
    return @vsprintf($msg, $args) ?: $msg;
}

function jsLocaleStrings(): array {
    static $strings = null;
    if ($strings === null) {
        $all = loadLocaleStrings(resolveLocale());
        $strings = [];
        // JS bekommt: alle 'js.*' Strings + Navigation-Labels (für Module-Switcher in account.php)
        // + Enum-Labels (für DB-Werte → UI-Übersetzung in Listen-Renderern).
        foreach ($all as $k => $v) {
            if (strncmp($k, 'js.', 3) === 0
                || strncmp($k, 'nav.', 4) === 0
                || strncmp($k, 'enum.', 5) === 0
                || strncmp($k, 'msg.', 4) === 0
                || strncmp($k, 'page.', 5) === 0
                || strncmp($k, 'btn.', 4) === 0
                || strncmp($k, 'common.', 7) === 0
                || strncmp($k, 'account.modules.', 16) === 0) {
                $strings[$k] = $v;
            }
        }
    }
    return $strings;
}

function setLocaleCookie(string $locale): void {
    $locale = normalizeLocale($locale);
    if (!headers_sent()) {
        setcookie(LOCALE_COOKIE, $locale, [
            'expires'  => time() + 31536000, // 1 Jahr
            'path'     => '/',
            // Konsistent mit den Auth-Cookies (auth.php): nur über HTTPS senden,
            // wenn die Verbindung TLS ist. Auf reinem HTTP würde Secure=true das
            // Cookie sonst komplett verwerfen.
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => false, // JS darf lesen, ist kein Auth-Wert
            'samesite' => 'Lax', // Lax: greift auch bei Cross-Site-Klicks auf /p/<token>
        ]);
    }
    // Im aktuellen Request schon konsistent halten.
    $_COOKIE[LOCALE_COOKIE] = $locale;
}
