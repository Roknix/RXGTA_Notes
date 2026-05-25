<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

startSecureSession();
requireAuth();

header('Content-Type: application/json; charset=utf-8');
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            $chars = getCharactersForUser((int)$_SESSION['user_id']);
            jsonResponse($chars);
            break;

        case 'POST':
            verifyCSRF();
            $data = getJsonBody();
            $action = $data['action'] ?? '';

            if ($action === 'switch') {
                $charId = (int)($data['character_id'] ?? 0);
                if (!hasCharacterAccess($charId)) apiError('Kein Zugriff', 403);
                $_SESSION['character_id'] = $charId;
                // Letzten Charakter für diesen User speichern
                getDB()->prepare("UPDATE users SET last_character_id = ? WHERE id = ?")
                    ->execute([$charId, (int)$_SESSION['user_id']]);
                jsonResponse(['success' => true]);
            }

            if ($action === 'set_hidden_modules') {
                $charId = (int)($data['character_id'] ?? 0);
                if (!hasCharacterAccess($charId)) apiError('Kein Zugriff', 403);
                $mods = $data['hidden_modules'] ?? [];
                if (!is_array($mods)) apiError('hidden_modules muss ein Array sein');
                // Whitelist: nur bekannte Nav-Keys, dashboard/account nie ausblendbar.
                $allowed = ['contacts','items','buyers','locations','storage','liabilities','claims','orders','notes','biography','vehicles'];
                $mods = array_values(array_unique(array_filter(array_map('strval', $mods), function($m) use ($allowed) {
                    return in_array($m, $allowed, true);
                })));
                getDB()->prepare("UPDATE characters SET hidden_modules=? WHERE id=?")
                    ->execute([json_encode($mods, JSON_UNESCAPED_UNICODE), $charId]);
                jsonResponse(['success' => true, 'hidden_modules' => $mods]);
            }

            requireParam($data, 'name');
            $name   = trim($data['name']);
            $server = trim($data['server'] ?? '');
            $desc   = trim($data['description'] ?? '');
            $color  = preg_match('/^#[0-9a-fA-F]{6}$/', $data['color'] ?? '') ? $data['color'] : '#7c3aed';

            $db = getDB();
            $db->prepare("INSERT INTO characters (user_id, name, server, description, avatar_color, created_at) VALUES (?, ?, ?, ?, ?, UNIX_TIMESTAMP())")
                ->execute([(int)$_SESSION['user_id'], $name, $server, $desc, $color]);
            $newId = (int)$db->lastInsertId();
            $_SESSION['character_id'] = $newId;
            $db->prepare("UPDATE users SET last_character_id = ? WHERE id = ?")
                ->execute([$newId, (int)$_SESSION['user_id']]);

            $stmt = $db->prepare("SELECT * FROM characters WHERE id = ?");
            $stmt->execute([$newId]);
            jsonResponse($stmt->fetch(), 201);
            break;

        case 'PUT':
            verifyCSRF();
            $data   = getJsonBody();
            $charId = (int)($data['id'] ?? 0);
            if (!hasCharacterAccess($charId)) apiError('Kein Zugriff', 403);

            requireParam($data, 'name');
            $name   = trim($data['name']);
            $server = trim($data['server'] ?? '');
            $desc   = trim($data['description'] ?? '');
            $color  = preg_match('/^#[0-9a-fA-F]{6}$/', $data['color'] ?? '') ? $data['color'] : '#7c3aed';

            getDB()->prepare("UPDATE characters SET name=?, server=?, description=?, avatar_color=? WHERE id=?")
                ->execute([$name, $server, $desc, $color, $charId]);
            jsonResponse(['success' => true]);
            break;

        case 'DELETE':
            verifyCSRF();
            $data   = getJsonBody();
            $charId = (int)($data['id'] ?? 0);
            if (!hasCharacterAccess($charId)) apiError('Kein Zugriff', 403);

            $db   = getDB();
            $stmt = $db->prepare("SELECT COUNT(*) FROM characters WHERE user_id = ?");
            $stmt->execute([(int)$_SESSION['user_id']]);
            $remaining = (int)$stmt->fetchColumn();
            if ($remaining <= 1) apiError('Der letzte Charakter kann nicht gelöscht werden');

            $db->prepare("DELETE FROM characters WHERE id = ?")->execute([$charId]);
            if ($_SESSION['character_id'] === $charId) {
                $first = $db->prepare("SELECT id FROM characters WHERE user_id = ? ORDER BY id LIMIT 1");
                $first->execute([(int)$_SESSION['user_id']]);
                $_SESSION['character_id'] = (int)$first->fetchColumn();
            }
            jsonResponse(['success' => true]);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Throwable $e) {
    apiServerError($e);
}
