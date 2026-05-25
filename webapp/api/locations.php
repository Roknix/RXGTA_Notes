<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

startSecureSession();
requireAuth();
header('Content-Type: application/json; charset=utf-8');

try {
    $charId = requireCharacterId();
    $db     = getDB();
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            $stmt = $db->prepare("SELECT * FROM locations WHERE character_id = ? ORDER BY name");
            $stmt->execute([$charId]);
            jsonResponse($stmt->fetchAll());
            break;

        case 'POST':
            verifyCSRF();
            $d = getJsonBody();
            requireParam($d, 'name');
            $db->prepare("INSERT INTO locations (character_id,name,zip,illegal,requires,description) VALUES (?,?,?,?,?,?)")
                ->execute([$charId, trim($d['name']), trim($d['zip']??''), empty($d['illegal'])?0:1,
                           trim($d['requires']??''), trim($d['description']??'')]);
            $id   = (int)$db->lastInsertId();
            $stmt = $db->prepare("SELECT * FROM locations WHERE id=?");
            $stmt->execute([$id]);
            jsonResponse($stmt->fetch(), 201);
            break;

        case 'PUT':
            verifyCSRF();
            $d    = getJsonBody();
            $id   = (int)($d['id'] ?? 0);
            $stmt = $db->prepare("SELECT character_id FROM locations WHERE id=?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row || (int)$row['character_id'] !== $charId) apiError('Nicht gefunden', 404);
            $db->prepare("UPDATE locations SET name=?,zip=?,illegal=?,requires=?,description=? WHERE id=?")
                ->execute([trim($d['name']), trim($d['zip']??''), empty($d['illegal'])?0:1,
                           trim($d['requires']??''), trim($d['description']??''), $id]);
            jsonResponse(['success' => true]);
            break;

        case 'DELETE':
            verifyCSRF();
            $d    = getJsonBody();
            $id   = (int)($d['id'] ?? 0);
            $stmt = $db->prepare("SELECT character_id FROM locations WHERE id=?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row || (int)$row['character_id'] !== $charId) apiError('Nicht gefunden', 404);
            $db->prepare("DELETE FROM locations WHERE id=?")->execute([$id]);
            jsonResponse(['success' => true]);
            break;

        default: http_response_code(405); echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Throwable $e) {
    apiServerError($e);
}
