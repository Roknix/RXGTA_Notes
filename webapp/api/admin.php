<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

startSecureSession();
requireAdmin();
header('Content-Type: application/json; charset=utf-8');

try {
    $db     = getDB();
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? getJsonBody()['action'] ?? '';

    if ($method === 'GET') {
        if ($action === 'users') {
            $stmt = $db->query("SELECT id, username, is_admin, failed_attempts, locked_until, created_at FROM users ORDER BY id");
            jsonResponse($stmt->fetchAll());
        }
        if ($action === 'characters') {
            $userId = (int)($_GET['user_id'] ?? 0);
            $stmt = $db->prepare("SELECT c.id, c.name, c.description FROM characters c WHERE c.user_id=? ORDER BY c.name");
            $stmt->execute([$userId]);
            jsonResponse($stmt->fetchAll());
        }
        apiError('Unbekannte Aktion');
    }

    verifyCSRF();
    $d = getJsonBody();

    switch ($action) {
        case 'create_user':
            requireParam($d, 'username', 'password');
            $username = trim($d['username']);
            // Whitelist: A-Z a-z 0-9 . _ - · 3–64 Zeichen. Verhindert u.a. Anführungszeichen
            // im Username, die sonst beim Rendern der Admin-Userliste aus JS-Stringliteralen
            // ausbrechen könnten.
            if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
                apiError('Benutzername darf nur Buchstaben, Ziffern, Punkt, Unter- und Bindestrich enthalten (3–64 Zeichen)');
            }
            if (strlen($d['password']) < MIN_PASSWORD_LENGTH) apiError('Passwort zu kurz (min. ' . MIN_PASSWORD_LENGTH . ')');
            $isAdmin = !empty($d['is_admin']) ? 1 : 0;
            $hash = hashPassword($d['password']);
            try {
                $db->prepare("INSERT INTO users (username, password_hash, is_admin, created_at) VALUES (?, ?, ?, UNIX_TIMESTAMP())")
                    ->execute([$username, $hash, $isAdmin]);
                jsonResponse(['success' => true, 'id' => (int)$db->lastInsertId()]);
            } catch (PDOException $e) {
                // SQLSTATE 23000 = Integritätsverletzung (UNIQUE/FK). Funktioniert für SQLite und MySQL.
                if ($e->getCode() === '23000') apiError('Benutzername bereits vergeben');
                throw $e;
            }
            break;

        case 'reset_password':
            requireParam($d, 'user_id', 'password');
            if (strlen($d['password']) < MIN_PASSWORD_LENGTH) apiError('Passwort zu kurz (min. ' . MIN_PASSWORD_LENGTH . ')');
            $hash = hashPassword($d['password']);
            $targetId = (int)$d['user_id'];
            $db->prepare("UPDATE users SET password_hash=?, failed_attempts=0, locked_until=NULL WHERE id=?")
                ->execute([$hash, $targetId]);
            // Alle Remember-Me-Tokens des Ziel-Users widerrufen + session_epoch
            // hochzählen → aktive Browser-Sessions des Ziel-Users kippen sofort.
            invalidateAllTokensForUser($targetId);
            bumpSessionEpoch($targetId);
            jsonResponse(['success' => true]);
            break;

        case 'force_logout_user':
            $targetId = (int)($d['user_id'] ?? 0);
            if ($targetId <= 0) apiError('Ungültige Benutzer-ID');
            invalidateAllTokensForUser($targetId);
            bumpSessionEpoch($targetId);
            jsonResponse(['success' => true]);
            break;

        case 'unlock_user':
            $db->prepare("UPDATE users SET failed_attempts=0, locked_until=NULL WHERE id=?")
                ->execute([(int)($d['user_id'] ?? 0)]);
            jsonResponse(['success' => true]);
            break;

        case 'toggle_admin':
            $userId = (int)($d['user_id'] ?? 0);
            if ($userId === (int)$_SESSION['user_id']) apiError('Eigene Admin-Rechte können nicht geändert werden');
            $stmt = $db->prepare("SELECT is_admin FROM users WHERE id=?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            if (!$user) apiError('Benutzer nicht gefunden', 404);
            $newAdmin = (int)$user['is_admin'] === 1 ? 0 : 1;
            $db->prepare("UPDATE users SET is_admin=? WHERE id=?")->execute([$newAdmin, $userId]);
            jsonResponse(['success' => true, 'is_admin' => $newAdmin]);
            break;

        case 'delete_user':
            $userId = (int)($d['user_id'] ?? 0);
            if ($userId === (int)$_SESSION['user_id']) apiError('Eigener Account kann nicht gelöscht werden');
            $db->prepare("DELETE FROM users WHERE id=?")->execute([$userId]);
            jsonResponse(['success' => true]);
            break;

        default:
            apiError('Unbekannte Aktion');
    }
} catch (Throwable $e) {
    apiServerError($e);
}
