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
            $stmt = $db->prepare("SELECT * FROM vehicles WHERE character_id = ? ORDER BY name");
            $stmt->execute([$charId]);
            jsonResponse($stmt->fetchAll());
            break;

        case 'POST':
            verifyCSRF();
            $d = getJsonBody();
            requireParam($d, 'name');
            $db->prepare("INSERT INTO vehicles (character_id,name,make,model,plate,color,modifications,hideouts,insurance,next_service,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$charId,
                    trim($d['name']),
                    trim($d['make']??''),
                    trim($d['model']??''),
                    trim($d['plate']??''),
                    trim($d['color']??''),
                    trim($d['modifications']??''),
                    trim($d['hideouts']??''),
                    trim($d['insurance']??''),
                    trim($d['next_service']??''),
                    trim($d['notes']??''),
                ]);
            $id   = (int)$db->lastInsertId();
            $stmt = $db->prepare("SELECT * FROM vehicles WHERE id=?");
            $stmt->execute([$id]);
            jsonResponse($stmt->fetch(), 201);
            break;

        case 'PUT':
            verifyCSRF();
            $d    = getJsonBody();
            $id   = (int)($d['id'] ?? 0);
            $stmt = $db->prepare("SELECT character_id FROM vehicles WHERE id=?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row || (int)$row['character_id'] !== $charId) apiError('Nicht gefunden', 404);
            requireParam($d, 'name');
            $db->prepare("UPDATE vehicles SET name=?,make=?,model=?,plate=?,color=?,modifications=?,hideouts=?,insurance=?,next_service=?,notes=? WHERE id=?")
                ->execute([
                    trim($d['name']),
                    trim($d['make']??''),
                    trim($d['model']??''),
                    trim($d['plate']??''),
                    trim($d['color']??''),
                    trim($d['modifications']??''),
                    trim($d['hideouts']??''),
                    trim($d['insurance']??''),
                    trim($d['next_service']??''),
                    trim($d['notes']??''),
                    $id,
                ]);
            jsonResponse(['success' => true]);
            break;

        case 'DELETE':
            verifyCSRF();
            $d    = getJsonBody();
            $id   = (int)($d['id'] ?? 0);
            $stmt = $db->prepare("SELECT character_id FROM vehicles WHERE id=?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row || (int)$row['character_id'] !== $charId) apiError('Nicht gefunden', 404);
            $db->prepare("DELETE FROM vehicles WHERE id=?")->execute([$id]);
            jsonResponse(['success' => true]);
            break;

        default: http_response_code(405); echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Throwable $e) {
    apiServerError($e);
}
