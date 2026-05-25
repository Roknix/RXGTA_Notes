<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

startSecureSession();
requireAuth();
header('Content-Type: application/json; charset=utf-8');

try {
    $db     = getDB();
    $userId = (int)$_SESSION['user_id'];
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? getJsonBody()['action'] ?? '';

    if ($method === 'GET' && $action === 'list_sessions') {
        $stmt = $db->prepare(
            "SELECT id, selector, user_agent, created_at, last_used_at, expires_at
             FROM auth_tokens
             WHERE user_id = ? AND expires_at > ?
             ORDER BY COALESCE(last_used_at, created_at) DESC"
        );
        $stmt->execute([$userId, time()]);
        $rows    = $stmt->fetchAll();
        $current = getCurrentRememberSelector();
        foreach ($rows as &$r) {
            $r['is_current'] = ($current !== null && hash_equals((string)$r['selector'], $current));
            unset($r['selector']);
        }
        unset($r); // Foreach-Reference auflösen, sonst dangling reference auf letztes Element.
        jsonResponse($rows);
    }

    verifyCSRF();
    $d = getJsonBody();
    $action = $action ?: ($d['action'] ?? '');

    switch ($action) {
        case 'change_password':
            requireParam($d, 'current_password', 'new_password');
            if (strlen($d['new_password']) < MIN_PASSWORD_LENGTH) {
                apiError('Neues Passwort muss mindestens ' . MIN_PASSWORD_LENGTH . ' Zeichen haben');
            }
            $stmt = $db->prepare("SELECT password_hash FROM users WHERE id=?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            if (!$user) apiError('Benutzer nicht gefunden', 404);
            if (!verifyPassword($d['current_password'], $user['password_hash'])) {
                apiError('Aktuelles Passwort ist falsch');
            }
            $db->prepare("UPDATE users SET password_hash=? WHERE id=?")
                ->execute([hashPassword($d['new_password']), $userId]);

            // Alle Remember-Me-Tokens dieses Users widerrufen UND session_epoch
            // hochzählen → kippt parallele Browser-Sessions auf anderen Geräten
            // sofort. Für DIESEN Browser den neuen Epoch in die Session
            // übernehmen, damit der aktuelle Tab eingeloggt bleibt.
            $hadRemember = !empty($_COOKIE[REMEMBER_ME_COOKIE]);
            invalidateAllTokensForUser($userId);
            $newEpoch = bumpSessionEpoch($userId);
            $_SESSION['session_epoch'] = $newEpoch;
            if ($hadRemember) {
                issueRememberToken($userId);
            } else {
                clearRememberCookie();
            }
            jsonResponse(['success' => true]);
            break;

        case 'revoke_session':
            $tokenId = (int)($d['token_id'] ?? 0);
            if ($tokenId <= 0) apiError('Ungültige Token-ID');
            $db->prepare("DELETE FROM auth_tokens WHERE id = ? AND user_id = ?")
                ->execute([$tokenId, $userId]);
            jsonResponse(['success' => true]);
            break;

        case 'revoke_others':
            $current = getCurrentRememberSelector();
            if ($current !== null) {
                $db->prepare("DELETE FROM auth_tokens WHERE user_id = ? AND selector <> ?")
                    ->execute([$userId, $current]);
            } else {
                $db->prepare("DELETE FROM auth_tokens WHERE user_id = ?")
                    ->execute([$userId]);
            }
            // Epoch bump → kippt parallele Browser-Sessions sofort. Eigene Session
            // bleibt erhalten, indem wir den neuen Epoch hier übernehmen.
            $_SESSION['session_epoch'] = bumpSessionEpoch($userId);
            jsonResponse(['success' => true]);
            break;

        case 'revoke_all':
            invalidateAllTokensForUser($userId);
            // Auch parallele Browser-Sessions kippen. Eigene Session bleibt zwar
            // technisch in PHP, aber das Remember-Cookie wird hier gelöscht und
            // wir nehmen den neuen Epoch in $_SESSION mit auf — Konsistenz mit
            // dem "alles abmelden"-Anspruch der Funktion.
            $_SESSION['session_epoch'] = bumpSessionEpoch($userId);
            clearRememberCookie();
            jsonResponse(['success' => true]);
            break;

        case 'set_locale':
            // Eingabe NUR über tryLocale() — sonst landet beliebiger String in
            // users.locale und später als Dateiname in i18n require.
            $locale = tryLocale($d['locale'] ?? null);
            if ($locale === null) apiError(__('msg.invalid_locale'));
            $db->prepare("UPDATE users SET locale = ? WHERE id = ?")
                ->execute([$locale, $userId]);
            setLocaleCookie($locale);
            jsonResponse(['success' => true, 'locale' => $locale]);
            break;

        default:
            apiError('Unknown action');
    }
} catch (Throwable $e) {
    apiServerError($e);
}
