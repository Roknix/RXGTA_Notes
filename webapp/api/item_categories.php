<?php
// CRUD für Item-Kategorien, charakter-gebunden.
// Beim Löschen einer Kategorie wird category_id der zugehörigen items via FK auf NULL gesetzt.
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
            // Liefert Kategorien inkl. Anzahl zugeordneter Items — Frontend kann sie anzeigen.
            $stmt = $db->prepare("
                SELECT c.id, c.name,
                       (SELECT COUNT(*) FROM items i WHERE i.category_id = c.id) AS item_count
                FROM item_categories c
                WHERE c.character_id = ?
                ORDER BY c.name
            ");
            $stmt->execute([$charId]);
            jsonResponse($stmt->fetchAll());
            break;

        case 'POST':
            verifyCSRF();
            $d = getJsonBody();
            requireParam($d, 'name');
            $name = trim($d['name']);

            $check = $db->prepare("SELECT id FROM item_categories WHERE character_id = ? AND name = ?");
            $check->execute([$charId, $name]);
            if ($existing = $check->fetchColumn()) {
                // Idempotent: bereits vorhanden → bestehende zurückgeben statt 409.
                $stmt = $db->prepare("SELECT id, name FROM item_categories WHERE id = ?");
                $stmt->execute([$existing]);
                jsonResponse($stmt->fetch(), 200);
            }

            $db->prepare("INSERT INTO item_categories (character_id, name) VALUES (?, ?)")
                ->execute([$charId, $name]);
            $id   = (int)$db->lastInsertId();
            $stmt = $db->prepare("SELECT id, name FROM item_categories WHERE id = ?");
            $stmt->execute([$id]);
            jsonResponse($stmt->fetch(), 201);
            break;

        case 'PUT':
            verifyCSRF();
            $d  = getJsonBody();
            $id = (int)($d['id'] ?? 0);
            requireParam($d, 'name');

            $stmt = $db->prepare("SELECT character_id FROM item_categories WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row || (int)$row['character_id'] !== $charId) apiError('Kategorie nicht gefunden', 404);

            $name = trim($d['name']);
            $check = $db->prepare("SELECT id FROM item_categories WHERE character_id = ? AND name = ? AND id <> ?");
            $check->execute([$charId, $name, $id]);
            if ($check->fetchColumn()) apiError('Eine andere Kategorie mit diesem Namen existiert bereits', 409);

            $db->prepare("UPDATE item_categories SET name = ? WHERE id = ?")->execute([$name, $id]);
            jsonResponse(['id' => $id, 'name' => $name]);
            break;

        case 'DELETE':
            verifyCSRF();
            $d  = getJsonBody();
            $id = (int)($d['id'] ?? 0);
            $stmt = $db->prepare("SELECT character_id FROM item_categories WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row || (int)$row['character_id'] !== $charId) apiError('Kategorie nicht gefunden', 404);

            // items.category_id wird via FK ON DELETE SET NULL automatisch gelöst.
            $db->prepare("DELETE FROM item_categories WHERE id = ?")->execute([$id]);
            jsonResponse(['success' => true]);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Throwable $e) {
    apiServerError($e);
}
