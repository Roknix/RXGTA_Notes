<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

startSecureSession();
requireAuth();

header('Content-Type: application/json; charset=utf-8');
$method = $_SERVER['REQUEST_METHOD'];

try {
    $charId = requireCharacterId();

    switch ($method) {
        case 'GET':
            $stmt = getDB()->prepare("SELECT * FROM contacts WHERE character_id = ? ORDER BY name");
            $stmt->execute([$charId]);
            jsonResponse($stmt->fetchAll());
            break;

        case 'POST':
            verifyCSRF();
            $d = getJsonBody();
            requireParam($d, 'name');
            $db = getDB();
            $db->prepare("INSERT INTO contacts (character_id,name,phone,email,company,`grouping`,known_via,role_job,notes)
                          VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $charId,
                    trim($d['name']),
                    trim($d['phone'] ?? ''),
                    trim($d['email'] ?? ''),
                    trim($d['company'] ?? ''),
                    trim($d['grouping'] ?? ''),
                    trim($d['known_via'] ?? ''),
                    trim($d['role_job'] ?? ''),
                    trim($d['notes'] ?? ''),
                ]);
            $id   = (int)$db->lastInsertId();
            $stmt = $db->prepare("SELECT * FROM contacts WHERE id = ?");
            $stmt->execute([$id]);
            jsonResponse($stmt->fetch(), 201);
            break;

        case 'PUT':
            verifyCSRF();
            $d  = getJsonBody();
            $id = (int)($d['id'] ?? 0);
            requireParam($d, 'name');
            $db   = getDB();
            $stmt = $db->prepare("SELECT character_id FROM contacts WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row || (int)$row['character_id'] !== $charId) apiError('Nicht gefunden', 404);

            $db->prepare("UPDATE contacts SET name=?,phone=?,email=?,company=?,`grouping`=?,known_via=?,role_job=?,notes=? WHERE id=?")
                ->execute([
                    trim($d['name']),
                    trim($d['phone'] ?? ''),
                    trim($d['email'] ?? ''),
                    trim($d['company'] ?? ''),
                    trim($d['grouping'] ?? ''),
                    trim($d['known_via'] ?? ''),
                    trim($d['role_job'] ?? ''),
                    trim($d['notes'] ?? ''),
                    $id,
                ]);
            jsonResponse(['success' => true]);
            break;

        case 'DELETE':
            verifyCSRF();
            $d    = getJsonBody();
            $id   = (int)($d['id'] ?? 0);
            $db   = getDB();
            $stmt = $db->prepare("SELECT character_id FROM contacts WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row || (int)$row['character_id'] !== $charId) apiError('Nicht gefunden', 404);
            $db->prepare("DELETE FROM contacts WHERE id = ?")->execute([$id]);
            jsonResponse(['success' => true]);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Throwable $e) {
    apiServerError($e);
}
