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
            $action = $_GET['action'] ?? '';

            if ($action === 'companies') {
                $stmt = $db->prepare("SELECT id, name, notes FROM companies WHERE character_id = ? ORDER BY name");
                $stmt->execute([$charId]);
                jsonResponse($stmt->fetchAll());
            }

            $stmt = $db->prepare("
                SELECT wo.*, c.phone AS contact_phone, c.`grouping` AS contact_grouping,
                       co.name AS company_name
                FROM work_orders wo
                LEFT JOIN contacts  c  ON wo.contact_id = c.id
                LEFT JOIN companies co ON wo.company_id = co.id
                WHERE wo.character_id = ?
                ORDER BY wo.done ASC, wo.priority ASC, wo.until_when ASC, wo.name
            ");
            $stmt->execute([$charId]);
            $orders = $stmt->fetchAll();

            foreach ($orders as &$o) {
                $o['recipes']     = getOrderRecipes($db, (int)$o['id']);
                $o['ingredients'] = getOrderIngredients($db, (int)$o['id']);
            }
            jsonResponse($orders);
            break;

        case 'POST':
            verifyCSRF();
            $d = getJsonBody();
            $action = $d['action'] ?? '';

            if ($action === 'add_company') {
                requireParam($d, 'name');
                $name  = trim($d['name']);
                $notes = trim($d['notes'] ?? '');
                $db->prepare("INSERT IGNORE INTO companies (character_id, name, notes) VALUES (?, ?, ?)")
                    ->execute([$charId, $name, $notes]);
                $db->prepare("UPDATE companies SET notes=? WHERE character_id=? AND name=?")
                    ->execute([$notes, $charId, $name]);
                $stmt = $db->prepare("SELECT id, name, notes FROM companies WHERE character_id=? AND name=?");
                $stmt->execute([$charId, $name]);
                jsonResponse($stmt->fetch(), 201);
            }
            if ($action === 'update_company') {
                $id = (int)($d['id'] ?? 0);
                $stmt = $db->prepare("SELECT character_id FROM companies WHERE id=?");
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if (!$row || (int)$row['character_id'] !== $charId) apiError('Nicht gefunden', 404);
                requireParam($d, 'name');
                $db->prepare("UPDATE companies SET name=?, notes=? WHERE id=?")
                    ->execute([trim($d['name']), trim($d['notes'] ?? ''), $id]);
                jsonResponse(['success' => true]);
            }
            if ($action === 'delete_company') {
                $id = (int)($d['id'] ?? 0);
                $stmt = $db->prepare("SELECT character_id FROM companies WHERE id=?");
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if (!$row || (int)$row['character_id'] !== $charId) apiError('Nicht gefunden', 404);
                $db->prepare("DELETE FROM companies WHERE id=?")->execute([$id]);
                jsonResponse(['success' => true]);
            }

            requireParam($d, 'name');
            $contId   = resolveContactId($db, $charId, $d['contact_id'] ?? null);
            $priority = normalizePriority($d['priority'] ?? 2);
            $isCompany = empty($d['is_company_order']) ? 0 : 1;
            $companyId = $isCompany ? resolveCompanyId($db, $charId, $d['company_id'] ?? null) : null;
            if ($isCompany && $companyId === null) apiError('Firmenauftrag ohne ausgewählte Firma');

            $db->beginTransaction();
            try {
                $db->prepare("INSERT INTO work_orders
                    (character_id,contact_id,name,what,how_much,until_when,description,done,priority,is_company_order,company_id)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$charId, $contId, trim($d['name']),
                               trim($d['what'] ?? ''), trim($d['how_much'] ?? ''),
                               trim($d['until_when'] ?? ''), trim($d['description'] ?? ''),
                               empty($d['done']) ? 0 : 1,
                               $priority, $isCompany, $companyId]);
                $id = (int)$db->lastInsertId();
                saveOrderRecipes($db, $charId, $id, $d['recipes'] ?? []);
                saveOrderIngredients($db, $charId, $id, $d['ingredients'] ?? []);
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }

            $stmt = $db->prepare("
                SELECT wo.*, c.phone AS contact_phone, co.name AS company_name
                FROM work_orders wo
                LEFT JOIN contacts  c  ON wo.contact_id = c.id
                LEFT JOIN companies co ON wo.company_id = co.id
                WHERE wo.id=?");
            $stmt->execute([$id]);
            $order = $stmt->fetch();
            $order['recipes']     = getOrderRecipes($db, $id);
            $order['ingredients'] = getOrderIngredients($db, $id);
            jsonResponse($order, 201);
            break;

        case 'PUT':
            verifyCSRF();
            $d  = getJsonBody();
            $id = (int)($d['id'] ?? 0);
            $stmt = $db->prepare("SELECT character_id FROM work_orders WHERE id=?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row || (int)$row['character_id'] !== $charId) apiError('Nicht gefunden', 404);

            $contId    = resolveContactId($db, $charId, $d['contact_id'] ?? null);
            $priority  = normalizePriority($d['priority'] ?? 2);
            $isCompany = empty($d['is_company_order']) ? 0 : 1;
            $companyId = $isCompany ? resolveCompanyId($db, $charId, $d['company_id'] ?? null) : null;
            if ($isCompany && $companyId === null) apiError('Firmenauftrag ohne ausgewählte Firma');

            $db->beginTransaction();
            try {
                $db->prepare("UPDATE work_orders
                              SET contact_id=?, name=?, what=?, how_much=?, until_when=?, description=?, done=?,
                                  priority=?, is_company_order=?, company_id=?
                              WHERE id=?")
                    ->execute([$contId, trim($d['name']),
                               trim($d['what'] ?? ''), trim($d['how_much'] ?? ''),
                               trim($d['until_when'] ?? ''), trim($d['description'] ?? ''),
                               empty($d['done']) ? 0 : 1,
                               $priority, $isCompany, $companyId, $id]);

                $db->prepare("DELETE FROM work_order_items WHERE work_order_id=?")->execute([$id]);
                saveOrderRecipes($db, $charId, $id, $d['recipes'] ?? []);
                saveOrderIngredients($db, $charId, $id, $d['ingredients'] ?? []);
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }
            jsonResponse(['success' => true]);
            break;

        case 'DELETE':
            verifyCSRF();
            $d    = getJsonBody();
            $id   = (int)($d['id'] ?? 0);
            $stmt = $db->prepare("SELECT character_id FROM work_orders WHERE id=?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row || (int)$row['character_id'] !== $charId) apiError('Nicht gefunden', 404);
            $db->prepare("DELETE FROM work_orders WHERE id=?")->execute([$id]);
            jsonResponse(['success' => true]);
            break;

        default: http_response_code(405); echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Throwable $e) {
    apiServerError($e);
}

function normalizePriority($p): int {
    $p = (int)$p;
    return in_array($p, [1,2,3], true) ? $p : 2;
}

// Stellt sicher, dass eine company_id zum Charakter gehört. Ungültig oder leer → null.
function resolveCompanyId(PDO $db, int $charId, $raw): ?int {
    if (empty($raw)) return null;
    $cid = (int)$raw;
    if ($cid <= 0) return null;
    $stmt = $db->prepare("SELECT 1 FROM companies WHERE id=? AND character_id=?");
    $stmt->execute([$cid, $charId]);
    if (!$stmt->fetchColumn()) apiError('Ungültige Firma für diesen Charakter');
    return $cid;
}

// Speichert die ausgewählten Rezepte als work_order_items. recipe_id muss zu einem
// Item gehören, das zum Charakter gehört UND Rezept-Charakter hat. Menge optional (default '1').
function saveOrderRecipes(PDO $db, int $charId, int $orderId, array $recipes): void {
    if (!$recipes) return;
    $check = $db->prepare("SELECT 1 FROM items it WHERE it.id=? AND it.character_id=? AND " . ITEM_IS_RECIPE_SQL);
    $ins   = $db->prepare("INSERT IGNORE INTO work_order_items (work_order_id, item_id, quantity) VALUES (?, ?, ?)");
    $upd   = $db->prepare("UPDATE work_order_items SET quantity = ? WHERE work_order_id = ? AND item_id = ?");
    $seen  = [];
    foreach ($recipes as $r) {
        $rid = is_array($r) ? (int)($r['recipe_id'] ?? $r['id'] ?? 0) : (int)$r;
        if ($rid <= 0 || isset($seen[$rid])) continue;
        $qty = is_array($r) ? trim((string)($r['quantity'] ?? '1')) : '1';
        if ($qty === '') $qty = '1';
        $check->execute([$rid, $charId]);
        if (!$check->fetchColumn()) continue; // unbekannt, fremd oder kein Rezept → still überspringen
        $ins->execute([$orderId, $rid, $qty]);
        $upd->execute([$qty, $orderId, $rid]); // falls schon vorhanden: Menge nachziehen
        $seen[$rid] = true;
    }
}

// Speichert die ausgewählten Zutaten als work_order_items. Fehlende Items werden
// als „Zutat" angelegt. Wenn der Name zufällig zu einem Rezept-Item passt, wird die
// Verknüpfung trotzdem korrekt gesetzt — beim Anzeigen taucht es dann unter recipes auf.
function saveOrderIngredients(PDO $db, int $charId, int $orderId, array $ingredients): void {
    if (!$ingredients) return;
    $ins = $db->prepare("INSERT IGNORE INTO work_order_items (work_order_id, item_id, quantity) VALUES (?, ?, ?)");
    $upd = $db->prepare("UPDATE work_order_items SET quantity = ? WHERE work_order_id = ? AND item_id = ?");
    foreach ($ingredients as $ing) {
        $name = trim((string)($ing['ingredient_name'] ?? $ing['name'] ?? ''));
        $qty  = trim((string)($ing['quantity'] ?? '1'));
        if ($qty === '') $qty = '1';
        if ($name === '') continue;
        $itemId = getOrCreateItem($db, $charId, $name);
        if ($itemId <= 0) continue;
        $ins->execute([$orderId, $itemId, $qty]);
        $upd->execute([$qty, $orderId, $itemId]); // falls bereits via Rezept verlinkt: Menge nachziehen
    }
}

// Liefert Rezept-Items des Auftrags im alten Format (mit verschachtelten Komponenten).
function getOrderRecipes(PDO $db, int $orderId): array {
    $stmt = $db->prepare("
        SELECT it.id AS recipe_id, it.name AS recipe_name,
               it.location_id, l.name AS location_name,
               it.work_table, it.danger_level,
               woi.quantity
        FROM work_order_items woi
        JOIN items it ON woi.item_id = it.id
        LEFT JOIN locations l ON it.location_id = l.id
        WHERE woi.work_order_id = ?
          AND " . ITEM_IS_RECIPE_SQL . "
        ORDER BY it.name
    ");
    $stmt->execute([$orderId]);
    $recipes = $stmt->fetchAll();

    $compStmt = $db->prepare("
        SELECT c.name AS ingredient_name, c.source AS ingredient_source, ic.quantity
        FROM item_components ic
        JOIN items c ON ic.component_id = c.id
        WHERE ic.item_id = ?
        ORDER BY c.name
    ");
    foreach ($recipes as &$r) {
        $compStmt->execute([(int)$r['recipe_id']]);
        $r['ingredients'] = $compStmt->fetchAll();
    }
    return $recipes;
}

// Liefert Nicht-Rezept-Items des Auftrags im alten Zutat-Format.
function getOrderIngredients(PDO $db, int $orderId): array {
    $stmt = $db->prepare("
        SELECT woi.quantity,
               it.id     AS ingredient_id,
               it.name   AS ingredient_name,
               it.source AS ingredient_source
        FROM work_order_items woi
        JOIN items it ON woi.item_id = it.id
        WHERE woi.work_order_id = ?
          AND NOT " . ITEM_IS_RECIPE_SQL . "
        ORDER BY it.name
    ");
    $stmt->execute([$orderId]);
    return $stmt->fetchAll();
}
