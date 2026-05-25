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
            $stmt = $db->prepare("SELECT * FROM storage WHERE character_id = ? ORDER BY storage_name");
            $stmt->execute([$charId]);
            jsonResponse($stmt->fetchAll());
            break;

        case 'POST':
            verifyCSRF();
            $d = getJsonBody();
            requireParam($d, 'storage_name');
            $db->prepare("INSERT INTO storage (character_id,storage_name,owner,location,storage_number,pin,notes) VALUES (?,?,?,?,?,?,?)")
                ->execute([$charId, trim($d['storage_name']), trim($d['owner']??''), trim($d['location']??''),
                           trim($d['storage_number']??''), trim($d['pin']??''), trim($d['notes']??'')]);
            $id   = (int)$db->lastInsertId();
            $stmt = $db->prepare("SELECT * FROM storage WHERE id=?");
            $stmt->execute([$id]);
            jsonResponse($stmt->fetch(), 201);
            break;

        case 'PUT':
            verifyCSRF();
            $d    = getJsonBody();
            $id   = (int)($d['id'] ?? 0);
            $stmt = $db->prepare("SELECT character_id FROM storage WHERE id=?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row || (int)$row['character_id'] !== $charId) apiError('Nicht gefunden', 404);
            $db->prepare("UPDATE storage SET storage_name=?,owner=?,location=?,storage_number=?,pin=?,notes=? WHERE id=?")
                ->execute([trim($d['storage_name']), trim($d['owner']??''), trim($d['location']??''),
                           trim($d['storage_number']??''), trim($d['pin']??''), trim($d['notes']??''), $id]);
            jsonResponse(['success' => true]);
            break;

        case 'DELETE':
            verifyCSRF();
            $d    = getJsonBody();
            $id   = (int)($d['id'] ?? 0);
            $stmt = $db->prepare("SELECT character_id FROM storage WHERE id=?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row || (int)$row['character_id'] !== $charId) apiError('Nicht gefunden', 404);
            $db->prepare("DELETE FROM storage WHERE id=?")->execute([$id]);
            jsonResponse(['success' => true]);
            break;

        default: http_response_code(405); echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Throwable $e) {
    apiServerError($e);
}
