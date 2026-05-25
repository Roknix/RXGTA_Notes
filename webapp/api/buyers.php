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
            $stmt = $db->prepare("
                SELECT b.*, c.phone AS contact_phone, c.`grouping` AS contact_grouping
                FROM buyers b
                LEFT JOIN contacts c ON b.contact_id = c.id
                WHERE b.character_id = ?
                ORDER BY b.priority ASC, b.name
            ");
            $stmt->execute([$charId]);
            jsonResponse($stmt->fetchAll());
            break;

        case 'POST':
            verifyCSRF();
            $d = getJsonBody();
            requireParam($d, 'name');
            $prio    = in_array((int)($d['priority']??2), [1,2,3]) ? (int)$d['priority'] : 2;
            $contId  = resolveContactId($db, $charId, $d['contact_id'] ?? null);
            $db->prepare("INSERT INTO buyers (character_id,contact_id,name,company,needs,priority) VALUES (?,?,?,?,?,?)")
                ->execute([$charId, $contId, trim($d['name']), trim($d['company']??''), trim($d['needs']??''), $prio]);
            $id   = (int)$db->lastInsertId();
            $stmt = $db->prepare("SELECT b.*, c.phone AS contact_phone FROM buyers b LEFT JOIN contacts c ON b.contact_id=c.id WHERE b.id=?");
            $stmt->execute([$id]);
            jsonResponse($stmt->fetch(), 201);
            break;

        case 'PUT':
            verifyCSRF();
            $d  = getJsonBody();
            $id = (int)($d['id'] ?? 0);
            $stmt = $db->prepare("SELECT character_id FROM buyers WHERE id=?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row || (int)$row['character_id'] !== $charId) apiError('Nicht gefunden', 404);
            $prio   = in_array((int)($d['priority']??2), [1,2,3]) ? (int)$d['priority'] : 2;
            $contId = resolveContactId($db, $charId, $d['contact_id'] ?? null);
            $db->prepare("UPDATE buyers SET contact_id=?,name=?,company=?,needs=?,priority=? WHERE id=?")
                ->execute([$contId, trim($d['name']), trim($d['company']??''), trim($d['needs']??''), $prio, $id]);
            jsonResponse(['success' => true]);
            break;

        case 'DELETE':
            verifyCSRF();
            $d    = getJsonBody();
            $id   = (int)($d['id'] ?? 0);
            $stmt = $db->prepare("SELECT character_id FROM buyers WHERE id=?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row || (int)$row['character_id'] !== $charId) apiError('Nicht gefunden', 404);
            $db->prepare("DELETE FROM buyers WHERE id=?")->execute([$id]);
            jsonResponse(['success' => true]);
            break;

        default: http_response_code(405); echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Throwable $e) {
    apiServerError($e);
}
