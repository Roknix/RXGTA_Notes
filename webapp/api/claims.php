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
                SELECT cl.*, c.phone AS contact_phone, c.`grouping` AS contact_grouping
                FROM claims cl
                LEFT JOIN contacts c ON cl.contact_id = c.id
                WHERE cl.character_id = ?
                ORDER BY cl.settled ASC, cl.priority ASC, cl.name
            ");
            $stmt->execute([$charId]);
            jsonResponse($stmt->fetchAll());
            break;

        case 'POST':
            verifyCSRF();
            $d = getJsonBody();
            requireParam($d, 'name');
            $prio   = in_array((int)($d['priority']??2),[1,2,3])?(int)$d['priority']:2;
            $contId = resolveContactId($db, $charId, $d['contact_id'] ?? null);
            $db->prepare("INSERT INTO claims (character_id,contact_id,name,amount,settled,date,priority) VALUES (?,?,?,?,?,?,?)")
                ->execute([$charId,$contId,trim($d['name']),trim($d['amount']??''),empty($d['settled'])?0:1,
                           trim($d['date']??''),$prio]);
            $id   = (int)$db->lastInsertId();
            $stmt = $db->prepare("SELECT cl.*, c.phone AS contact_phone FROM claims cl LEFT JOIN contacts c ON cl.contact_id=c.id WHERE cl.id=?");
            $stmt->execute([$id]);
            jsonResponse($stmt->fetch(), 201);
            break;

        case 'PUT':
            verifyCSRF();
            $d    = getJsonBody();
            $id   = (int)($d['id'] ?? 0);
            $stmt = $db->prepare("SELECT character_id FROM claims WHERE id=?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row || (int)$row['character_id'] !== $charId) apiError('Nicht gefunden', 404);
            $prio   = in_array((int)($d['priority']??2),[1,2,3])?(int)$d['priority']:2;
            $contId = resolveContactId($db, $charId, $d['contact_id'] ?? null);
            $db->prepare("UPDATE claims SET contact_id=?,name=?,amount=?,settled=?,date=?,priority=? WHERE id=?")
                ->execute([$contId,trim($d['name']),trim($d['amount']??''),empty($d['settled'])?0:1,
                           trim($d['date']??''),$prio,$id]);
            jsonResponse(['success' => true]);
            break;

        case 'DELETE':
            verifyCSRF();
            $d    = getJsonBody();
            $id   = (int)($d['id'] ?? 0);
            $stmt = $db->prepare("SELECT character_id FROM claims WHERE id=?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row || (int)$row['character_id'] !== $charId) apiError('Nicht gefunden', 404);
            $db->prepare("DELETE FROM claims WHERE id=?")->execute([$id]);
            jsonResponse(['success' => true]);
            break;

        default: http_response_code(405); echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Throwable $e) {
    apiServerError($e);
}
