<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function startSecureSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        // Serverseitige Session-Lebensdauer anpassen (Standard: 1440s = 24min)
        ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);

        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();

        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
            session_unset();
            session_destroy();
            session_start();
        }
        $_SESSION['last_activity'] = time();
    }
}

function hashPassword(string $password): string {
    return password_hash(APP_PEPPER . $password, PASSWORD_BCRYPT, ['cost' => 12]);
}

function verifyPassword(string $password, string $hash): bool {
    return password_verify(APP_PEPPER . $password, $hash);
}

function generateCSRFToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRF(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF-Token ungültig. Seite neu laden.']);
        exit;
    }
}

function isApiRequest(): bool {
    return strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;
}

function makeFingerprint(): string {
    // HMAC aus User-Agent + App-Pepper — ohne den Pepper nicht reproduzierbar
    return hash_hmac('sha256', $_SERVER['HTTP_USER_AGENT'] ?? 'unknown', APP_PEPPER);
}

// ===== Remember-Me-Tokens (Selector + Verifier) =====

function _rememberCookieOptions(int $expires): array {
    return [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ];
}

function _setRememberCookie(string $selector, string $verifier, int $expiresAt): void {
    setcookie(REMEMBER_ME_COOKIE, $selector . ':' . $verifier, _rememberCookieOptions($expiresAt));
}

function issueRememberToken(int $userId): void {
    $selector     = bin2hex(random_bytes(16));
    $verifier     = bin2hex(random_bytes(32));
    $verifierHash = hash_hmac('sha256', $verifier, APP_PEPPER);
    $expiresAt    = time() + REMEMBER_ME_LIFETIME;
    $userAgent    = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);

    getDB()->prepare(
        "INSERT INTO auth_tokens (user_id, selector, verifier_hash, fingerprint, user_agent, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, UNIX_TIMESTAMP())"
    )->execute([$userId, $selector, $verifierHash, makeFingerprint(), $userAgent, $expiresAt]);

    _setRememberCookie($selector, $verifier, $expiresAt);
}

function getCurrentRememberSelector(): ?string {
    if (empty($_COOKIE[REMEMBER_ME_COOKIE])) return null;
    $parts = explode(':', (string)$_COOKIE[REMEMBER_ME_COOKIE], 2);
    if (count($parts) !== 2 || !preg_match('/^[a-f0-9]{32}$/', $parts[0])) return null;
    return $parts[0];
}

function clearRememberCookie(bool $deleteDbRow = true): void {
    if ($deleteDbRow && !empty($_COOKIE[REMEMBER_ME_COOKIE])) {
        $parts = explode(':', (string)$_COOKIE[REMEMBER_ME_COOKIE], 2);
        if (count($parts) === 2 && preg_match('/^[a-f0-9]{32}$/', $parts[0])) {
            try {
                getDB()->prepare("DELETE FROM auth_tokens WHERE selector = ?")->execute([$parts[0]]);
            } catch (Throwable $e) { /* ignore */ }
        }
    }
    setcookie(REMEMBER_ME_COOKIE, '', _rememberCookieOptions(time() - 3600));
    unset($_COOKIE[REMEMBER_ME_COOKIE]);
}

function invalidateAllTokensForUser(int $userId): void {
    try {
        getDB()->prepare("DELETE FROM auth_tokens WHERE user_id = ?")->execute([$userId]);
    } catch (Throwable $e) { /* ignore */ }
}

// Erhöht den session_epoch eines Users → kippt alle aktiven PHP-Sessions dieses
// Users beim nächsten requireAuth(). Wird ergänzend zu invalidateAllTokensForUser()
// aufgerufen (Remember-Me + aktive Browser-Session = wirklich beide raus).
// Liefert den neuen Epoch-Wert zurück.
function bumpSessionEpoch(int $userId): int {
    try {
        $db = getDB();
        $db->prepare("UPDATE users SET session_epoch = session_epoch + 1 WHERE id = ?")
            ->execute([$userId]);
        $stmt = $db->prepare("SELECT session_epoch FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function cleanupExpiredTokens(): void {
    // Opportunistische Garbage Collection (~1% der Requests), kein Cron nötig.
    try {
        if (random_int(1, 100) === 1) {
            getDB()->prepare("DELETE FROM auth_tokens WHERE expires_at < ?")->execute([time()]);
        }
    } catch (Throwable $e) { /* ignore */ }
}

// Validiert das Remember-Me-Cookie, rotiert den Verifier und liefert user_id zurück.
// Bei Verifier-Mismatch trotz existierendem Selector: Diebstahlsverdacht → alle Tokens des Users löschen.
function consumeRememberCookie(): ?int {
    if (empty($_COOKIE[REMEMBER_ME_COOKIE])) return null;

    $raw   = (string)$_COOKIE[REMEMBER_ME_COOKIE];
    $parts = explode(':', $raw, 2);
    if (count($parts) !== 2
        || !preg_match('/^[a-f0-9]{32}$/', $parts[0])
        || !preg_match('/^[a-f0-9]{64}$/', $parts[1])) {
        clearRememberCookie();
        return null;
    }
    [$selector, $verifier] = $parts;

    try {
        $db   = getDB();
        $stmt = $db->prepare("SELECT id, user_id, verifier_hash, fingerprint, expires_at, last_used_at FROM auth_tokens WHERE selector = ?");
        $stmt->execute([$selector]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        return null;
    }

    if (!$row) {
        clearRememberCookie();
        return null;
    }

    if ((int)$row['expires_at'] < time()) {
        try { $db->prepare("DELETE FROM auth_tokens WHERE id = ?")->execute([(int)$row['id']]); } catch (Throwable $e) {}
        clearRememberCookie();
        return null;
    }

    $expectedHash = hash_hmac('sha256', $verifier, APP_PEPPER);
    if (!hash_equals((string)$row['verifier_hash'], $expectedHash)) {
        // Selector existiert, Verifier passt aber nicht. Zwei mögliche Ursachen:
        //  a) Diebstahl/Replay-Versuch → alle Tokens des Users widerrufen.
        //  b) Race: paralleler Request hat den Verifier gerade rotiert, Browser hatte
        //     das neue Set-Cookie noch nicht übernommen. Grace-Fenster 5 s nach last_used_at.
        $lastUsed = (int)($row['last_used_at'] ?? 0);
        $isFreshRotationRace = $lastUsed > 0 && (time() - $lastUsed) <= 5;
        if ($isFreshRotationRace) {
            // Nur das Cookie in DIESEM Browser löschen — DB-Zeile bleibt, damit der
            // parallele Request, der gerade rotiert hat, weiter gültig bleibt.
            clearRememberCookie(false);
            return null;
        }
        invalidateAllTokensForUser((int)$row['user_id']);
        clearRememberCookie();
        error_log('[auth] Remember-Token-Verifier-Mismatch für user_id=' . (int)$row['user_id'] . ' → alle Tokens widerrufen');
        return null;
    }

    if (!hash_equals((string)$row['fingerprint'], makeFingerprint())) {
        // Anderer User-Agent als beim Ausstellen → Token nur für dieses Gerät löschen.
        try { $db->prepare("DELETE FROM auth_tokens WHERE id = ?")->execute([(int)$row['id']]); } catch (Throwable $e) {}
        clearRememberCookie();
        return null;
    }

    // User noch in DB?
    $stmt = $db->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([(int)$row['user_id']]);
    if (!$stmt->fetch()) {
        try { $db->prepare("DELETE FROM auth_tokens WHERE id = ?")->execute([(int)$row['id']]); } catch (Throwable $e) {}
        clearRememberCookie();
        return null;
    }

    // Rotation: neuer Verifier, sliding expiration.
    $newVerifier     = bin2hex(random_bytes(32));
    $newVerifierHash = hash_hmac('sha256', $newVerifier, APP_PEPPER);
    $newExpiresAt    = time() + REMEMBER_ME_LIFETIME;
    $db->prepare("UPDATE auth_tokens SET verifier_hash = ?, last_used_at = ?, expires_at = ? WHERE id = ?")
        ->execute([$newVerifierHash, time(), $newExpiresAt, (int)$row['id']]);
    _setRememberCookie($selector, $newVerifier, $newExpiresAt);

    cleanupExpiredTokens();
    return (int)$row['user_id'];
}

function _hydrateSessionFromUser(int $userId): bool {
    try {
        $db   = getDB();
        $stmt = $db->prepare("SELECT id, username, is_admin, last_character_id, session_epoch FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
    if (!$user) return false;

    $_SESSION['user_id']       = (int)$user['id'];
    $_SESSION['username']      = $user['username'];
    $_SESSION['is_admin']      = (bool)$user['is_admin'];
    $_SESSION['fingerprint']   = makeFingerprint();
    $_SESSION['session_epoch'] = (int)$user['session_epoch'];

    // Letzten Charakter wiederherstellen, Fallback: erster Charakter.
    $charId = null;
    if (!empty($user['last_character_id'])) {
        $stmt = $db->prepare("SELECT id FROM characters WHERE id = ? AND user_id = ?");
        $stmt->execute([$user['last_character_id'], $user['id']]);
        $crow = $stmt->fetch();
        if ($crow) $charId = (int)$crow['id'];
    }
    if ($charId === null) {
        $stmt = $db->prepare("SELECT id FROM characters WHERE user_id = ? ORDER BY id LIMIT 1");
        $stmt->execute([$user['id']]);
        $crow = $stmt->fetch();
        if ($crow) $charId = (int)$crow['id'];
    }
    $_SESSION['character_id'] = $charId;
    return true;
}

// Versucht eine stille Anmeldung über das Remember-Me-Cookie ohne Redirect.
// Liefert true bei Erfolg (Session ist dann hydratisiert) und false sonst.
// Erwartet, dass startSecureSession() bereits aufgerufen wurde.
function tryAutoLogin(): bool {
    if (!empty($_SESSION['user_id'])) return true;
    $remembered = consumeRememberCookie();
    if ($remembered === null) return false;
    session_regenerate_id(true);
    return _hydrateSessionFromUser($remembered);
}

function requireAuth(): void {
    startSecureSession();

    $userId = null;

    // Pfad 1: aktive Session vorhanden und Fingerprint stimmt.
    if (!empty($_SESSION['user_id'])) {
        if (empty($_SESSION['fingerprint']) || hash_equals($_SESSION['fingerprint'], makeFingerprint())) {
            $userId = (int)$_SESSION['user_id'];
        } else {
            // Manipuliert / anderer Browser → Session weg, danach Remember-Cookie nicht mehr versuchen.
            session_unset();
            session_destroy();
            _redirectToLogin();
        }
    }

    // Pfad 2: Session leer oder abgelaufen → Remember-Me-Cookie probieren.
    if ($userId === null) {
        $remembered = consumeRememberCookie();
        if ($remembered !== null) {
            session_regenerate_id(true);
            if (_hydrateSessionFromUser($remembered)) {
                $userId = $remembered;
            }
        }
    }

    if ($userId === null) {
        _redirectToLogin();
    }

    // User noch in DB vorhanden?
    try {
        $stmt = getDB()->prepare("SELECT id, is_admin, session_epoch FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        $row = null;
    }

    if (!$row) {
        session_unset();
        session_destroy();
        clearRememberCookie();
        _redirectToLogin();
    }

    // Session-Epoch-Vergleich: kippt diese Session sofort, wenn anderswo
    // ein Passwort-Reset/Force-Logout den Epoch-Wert hochgezählt hat.
    $sessionEpoch = isset($_SESSION['session_epoch']) ? (int)$_SESSION['session_epoch'] : 0;
    if ((int)$row['session_epoch'] !== $sessionEpoch) {
        session_unset();
        session_destroy();
        clearRememberCookie();
        _redirectToLogin();
    }

    $_SESSION['is_admin'] = (bool)$row['is_admin'];
    generateCSRFToken();
}

function _redirectToLogin(): void {
    if (isApiRequest()) {
        http_response_code(401);
        echo json_encode(['error' => 'Nicht eingeloggt oder Sitzung abgelaufen']);
        exit;
    }
    header('Location: /index.php');
    exit;
}

function requireAdmin(): void {
    requireAuth();
    if (empty($_SESSION['is_admin'])) {
        if (isApiRequest()) {
            http_response_code(403);
            echo json_encode(['error' => 'Keine Admin-Berechtigung']);
            exit;
        }
        header('Location: /contacts.php');
        exit;
    }
}

// Liefert die Client-IP. Bewusst nur REMOTE_ADDR — X-Forwarded-For wäre auf
// Netcup Shared Hosting spoofbar. Bei direktem Zugriff stimmt REMOTE_ADDR.
function getClientIp(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

// Prüft, ob die aktuelle IP gerade gesperrt ist. Liefert verbleibende Sekunden,
// oder 0 wenn nicht gesperrt.
function ipLockoutRemainingSeconds(string $ip): int {
    try {
        $stmt = getDB()->prepare("SELECT locked_until FROM login_ip_attempts WHERE ip = ?");
        $stmt->execute([$ip]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        return 0;
    }
    if (!$row || empty($row['locked_until'])) return 0;
    $remaining = (int)$row['locked_until'] - time();
    return $remaining > 0 ? $remaining : 0;
}

// Zählt einen Fehlversuch dieser IP. Sperrt nach IP_MAX_FAILED_ATTEMPTS
// innerhalb von IP_ATTEMPT_WINDOW_SECONDS für IP_LOCKOUT_SECONDS.
function recordFailedIpAttempt(string $ip): void {
    try {
        $db   = getDB();
        $now  = time();
        $stmt = $db->prepare("SELECT failed_count, first_failed_at FROM login_ip_attempts WHERE ip = ?");
        $stmt->execute([$ip]);
        $row = $stmt->fetch();

        if (!$row) {
            $db->prepare("INSERT INTO login_ip_attempts (ip, failed_count, first_failed_at) VALUES (?, 1, ?)")
                ->execute([$ip, $now]);
            return;
        }

        $first = (int)($row['first_failed_at'] ?? $now);
        $count = (int)$row['failed_count'];

        // Fenster abgelaufen → neu zählen.
        if ($now - $first > IP_ATTEMPT_WINDOW_SECONDS) {
            $db->prepare("UPDATE login_ip_attempts SET failed_count = 1, first_failed_at = ?, locked_until = NULL WHERE ip = ?")
                ->execute([$now, $ip]);
            return;
        }

        $count++;
        $lockUntil = null;
        if ($count >= IP_MAX_FAILED_ATTEMPTS) {
            $lockUntil = $now + IP_LOCKOUT_SECONDS;
            $count     = 0;
            $first     = $now;
        }
        $db->prepare("UPDATE login_ip_attempts SET failed_count = ?, first_failed_at = ?, locked_until = ? WHERE ip = ?")
            ->execute([$count, $first, $lockUntil, $ip]);
    } catch (Throwable $e) { /* ignore */ }
}

function clearIpAttempts(string $ip): void {
    try {
        getDB()->prepare("DELETE FROM login_ip_attempts WHERE ip = ?")->execute([$ip]);
    } catch (Throwable $e) { /* ignore */ }
}

function attemptLogin(string $username, string $password, bool $remember = false): array {
    if (strlen($username) < 1 || strlen($password) < 1) {
        return ['success' => false, 'error' => 'Benutzername und Passwort erforderlich'];
    }

    // IP-Drossel: vor allem anderen prüfen, damit Credential-Stuffing über
    // viele Usernames hinweg gestoppt wird (per-User-Lockout greift dagegen nicht).
    $ip = getClientIp();
    $ipRemaining = ipLockoutRemainingSeconds($ip);
    if ($ipRemaining > 0) {
        $mins = (int)ceil($ipRemaining / 60);
        return ['success' => false, 'error' => "Zu viele Fehlversuche von dieser IP. Bitte {$mins} Minute(n) warten."];
    }

    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) {
        password_verify('dummy_timing_prevention', '$2y$12$dummyhashvaluethatnevermatch1234');
        recordFailedIpAttempt($ip);
        return ['success' => false, 'error' => 'Ungültige Anmeldedaten'];
    }

    if ($user['locked_until'] && time() < (int)$user['locked_until']) {
        $remaining = (int)ceil(((int)$user['locked_until'] - time()) / 60);
        return ['success' => false, 'error' => "Konto gesperrt. Noch {$remaining} Minute(n) warten."];
    }

    if (!verifyPassword($password, $user['password_hash'])) {
        $attempts = (int)$user['failed_attempts'] + 1;
        $lockUntil = null;
        if ($attempts >= MAX_FAILED_ATTEMPTS) {
            $lockUntil = time() + LOCKOUT_SECONDS;
            $attempts  = 0;
        }
        $db->prepare("UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?")
            ->execute([$attempts, $lockUntil, $user['id']]);
        recordFailedIpAttempt($ip);
        // Identische Meldung wie für nicht existierende User → keine Username-Enumeration.
        return ['success' => false, 'error' => 'Ungültige Anmeldedaten'];
    }

    $db->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?")
        ->execute([$user['id']]);
    // Erfolgreicher Login → IP-Counter dieser IP zurücksetzen.
    clearIpAttempts($ip);

    session_regenerate_id(true);
    $_SESSION['user_id']       = (int)$user['id'];
    $_SESSION['username']      = $user['username'];
    $_SESSION['is_admin']      = (bool)$user['is_admin'];
    $_SESSION['fingerprint']   = makeFingerprint();
    $_SESSION['session_epoch'] = (int)($user['session_epoch'] ?? 0);

    generateCSRFToken();

    // Letzten genutzten Charakter wiederherstellen (falls gesetzt und noch vorhanden)
    $charId = null;
    if (!empty($user['last_character_id'])) {
        $stmt = $db->prepare("SELECT id FROM characters WHERE id = ? AND user_id = ?");
        $stmt->execute([$user['last_character_id'], $user['id']]);
        $row = $stmt->fetch();
        if ($row) $charId = (int)$row['id'];
    }
    // Fallback: ersten Charakter nehmen
    if ($charId === null) {
        $stmt = $db->prepare("SELECT id FROM characters WHERE user_id = ? ORDER BY id LIMIT 1");
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();
        if ($row) $charId = (int)$row['id'];
    }
    $_SESSION['character_id'] = $charId;

    if ($remember) {
        issueRememberToken((int)$user['id']);
    } else {
        // Defensive: ein evtl. vorhandenes altes Remember-Cookie aus diesem Browser entwerten.
        clearRememberCookie();
    }

    return ['success' => true];
}

function registerFirstAdmin(string $username, string $password): array {
    if (!isFirstRun()) {
        return ['success' => false, 'error' => 'Es gibt bereits Benutzer'];
    }
    // Gleiche Whitelist wie api/admin.php create_user.
    if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
        return ['success' => false, 'error' => 'Benutzername darf nur Buchstaben, Ziffern, Punkt, Unter- und Bindestrich enthalten (3–64 Zeichen)'];
    }
    if (strlen($password) < MIN_PASSWORD_LENGTH) {
        return ['success' => false, 'error' => 'Passwort muss mindestens ' . MIN_PASSWORD_LENGTH . ' Zeichen haben'];
    }

    $hash = hashPassword($password);
    $db   = getDB();
    $db->prepare("INSERT INTO users (username, password_hash, is_admin, created_at) VALUES (?, ?, 1, UNIX_TIMESTAMP())")
        ->execute([$username, $hash]);

    return attemptLogin($username, $password);
}

function logoutUser(): void {
    clearRememberCookie();
    session_unset();
    session_destroy();
}

function getCurrentUser(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $stmt = getDB()->prepare("SELECT id, username, is_admin FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function getCharactersForUser(int $userId): array {
    $stmt = getDB()->prepare("SELECT id, name, server, avatar_color, hidden_modules FROM characters WHERE user_id = ? ORDER BY name");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $hm = json_decode($r['hidden_modules'] ?? '[]', true);
        $r['hidden_modules'] = is_array($hm) ? array_values(array_filter($hm, 'is_string')) : [];
    }
    return $rows;
}

function getCurrentCharId(): ?int {
    return isset($_SESSION['character_id']) ? (int)$_SESSION['character_id'] : null;
}

function requireCharacterId(): int {
    $id = getCurrentCharId();
    if ($id === null) {
        if (isApiRequest()) {
            http_response_code(400);
            echo json_encode(['error' => 'Kein Charakter ausgewählt']);
            exit;
        }
        header('Location: /contacts.php');
        exit;
    }
    return $id;
}

function hasCharacterAccess(int $charId): bool {
    if (empty($_SESSION['user_id'])) return false;
    $stmt = getDB()->prepare("SELECT 1 FROM characters WHERE id = ? AND user_id = ?");
    $stmt->execute([$charId, $_SESSION['user_id']]);
    return (bool)$stmt->fetch();
}

function requireCharacterAccess(int $charId): void {
    if (!hasCharacterAccess($charId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Kein Zugriff auf diesen Charakter']);
        exit;
    }
}
