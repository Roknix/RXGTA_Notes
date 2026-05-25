<?php
// Sauberer Endpoint für das Items-Modell.
// Items vereinen Rezepte + Zutaten in einer Tabelle. Ein Item ist „Rezept", wenn es
// Komponenten hat oder Crafting-Metadaten (Ort/Tisch/Gefahr ≠ Keine), sonst „Zutat".
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

startSecureSession();
requireAuth();

header('Content-Type: application/json; charset=utf-8');
$method = $_SERVER['REQUEST_METHOD'];

try {
    $charId = requireCharacterId();
    $db     = getDB();

    switch ($method) {
        case 'GET':
            jsonResponse(loadItemsForCharacter($db, $charId));
            break;

        case 'POST':
            verifyCSRF();
            $d = getJsonBody();
            requireParam($d, 'name');

            $name = trim($d['name']);
            // Namens-Konflikt? → 409, anstatt zu überschreiben.
            $check = $db->prepare("SELECT id FROM items WHERE character_id = ? AND name = ?");
            $check->execute([$charId, $name]);
            if ($check->fetchColumn()) {
                apiError('Es existiert bereits ein Produkt mit diesem Namen', 409);
            }

            [$source, $locId, $workTable, $danger, $catId] = normalizeItemFields($db, $charId, $d);

            $db->beginTransaction();
            try {
                $db->prepare("
                    INSERT INTO items (character_id, name, source, location_id, work_table, danger_level, category_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ")->execute([$charId, $name, $source, $locId, $workTable, $danger, $catId]);
                $itemId = (int)$db->lastInsertId();
                replaceItemComponents($db, $charId, $itemId, $d['components'] ?? []);
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }
            jsonResponse(loadOneItem($db, $charId, $itemId), 201);
            break;

        case 'PUT':
            verifyCSRF();
            $d  = getJsonBody();
            $id = (int)($d['id'] ?? 0);
            requireItemOwnership($db, $id, $charId);
            requireParam($d, 'name');

            $name = trim($d['name']);
            $check = $db->prepare("SELECT id FROM items WHERE character_id = ? AND name = ? AND id <> ?");
            $check->execute([$charId, $name, $id]);
            if ($check->fetchColumn()) {
                apiError('Ein anderes Produkt mit diesem Namen existiert bereits', 409);
            }

            [$source, $locId, $workTable, $danger, $catId] = normalizeItemFields($db, $charId, $d);

            $db->beginTransaction();
            try {
                $db->prepare("
                    UPDATE items SET name = ?, source = ?, location_id = ?, work_table = ?, danger_level = ?, category_id = ?
                    WHERE id = ?
                ")->execute([$name, $source, $locId, $workTable, $danger, $catId, $id]);
                $db->prepare("DELETE FROM item_components WHERE item_id = ?")->execute([$id]);
                replaceItemComponents($db, $charId, $id, $d['components'] ?? []);
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }
            jsonResponse(loadOneItem($db, $charId, $id));
            break;

        case 'DELETE':
            verifyCSRF();
            $d  = getJsonBody();
            $id = (int)($d['id'] ?? 0);
            requireItemOwnership($db, $id, $charId);
            $db->prepare("DELETE FROM items WHERE id = ?")->execute([$id]);
            jsonResponse(['success' => true]);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Throwable $e) {
    apiServerError($e);
}

// === Helpers ===

// Liefert alle Items des Charakters inkl. unmittelbarer Komponenten und is_recipe-Flag.
// Drilldowns werden im Frontend aus der vollen Liste rekursiv aufgebaut.
function loadItemsForCharacter(PDO $db, int $charId): array {
    $stmt = $db->prepare("
        SELECT it.id, it.character_id, it.name, it.source,
               it.location_id, l.name AS location_name,
               it.work_table, it.danger_level,
               it.category_id, cat.name AS category_name,
               (CASE WHEN " . ITEM_IS_RECIPE_SQL . " THEN 1 ELSE 0 END) AS is_recipe
        FROM items it
        LEFT JOIN locations l ON it.location_id = l.id
        LEFT JOIN item_categories cat ON it.category_id = cat.id
        WHERE it.character_id = ?
        ORDER BY it.name
    ");
    $stmt->execute([$charId]);
    $items = $stmt->fetchAll();

    if (!$items) return [];

    // is_recipe einer Komponente wird im PHP aus der geladenen Items-Map ergänzt — sauberer
    // als das ITEM_IS_RECIPE_SQL-Fragment auf einen anderen Alias zu zwingen.
    $compStmt = $db->prepare("
        SELECT ic.item_id, ic.component_id, c.name AS component_name,
               c.source AS component_source, ic.quantity
        FROM item_components ic
        JOIN items c ON ic.component_id = c.id
        WHERE c.character_id = ?
        ORDER BY c.name
    ");
    $compStmt->execute([$charId]);
    $componentsByItem = [];
    $isRecipeById     = [];
    foreach ($items as $it) {
        $isRecipeById[(int)$it['id']] = (bool)$it['is_recipe'];
    }
    foreach ($compStmt->fetchAll() as $c) {
        $itemId = (int)$c['item_id'];
        $componentsByItem[$itemId][] = [
            'component_id'        => (int)$c['component_id'],
            'component_name'      => $c['component_name'],
            'component_source'    => $c['component_source'],
            'component_is_recipe' => $isRecipeById[(int)$c['component_id']] ?? false,
            'quantity'            => $c['quantity'],
        ];
    }

    foreach ($items as &$it) {
        $it['is_recipe']  = (bool)$it['is_recipe'];
        $it['components'] = $componentsByItem[(int)$it['id']] ?? [];
    }
    return $items;
}

function loadOneItem(PDO $db, int $charId, int $itemId): array {
    foreach (loadItemsForCharacter($db, $charId) as $it) {
        if ((int)$it['id'] === $itemId) return $it;
    }
    return [];
}

// Validiert und normalisiert die Item-Felder aus dem Request-Body.
function normalizeItemFields(PDO $db, int $charId, array $d): array {
    $source    = trim((string)($d['source'] ?? ''));
    $source    = $source === '' ? null : $source;

    $locId     = !empty($d['location_id']) ? (int)$d['location_id'] : null;
    if ($locId !== null) {
        $stmt = $db->prepare("SELECT 1 FROM locations WHERE id = ? AND character_id = ?");
        $stmt->execute([$locId, $charId]);
        if (!$stmt->fetchColumn()) apiError('Ungültiger Ort für diesen Charakter');
    }

    $workTable = trim((string)($d['work_table'] ?? ''));
    $workTable = $workTable === '' ? null : $workTable;

    $validDanger = ['Keine','Gering','Mittel','Hoch'];
    $danger      = in_array($d['danger_level'] ?? '', $validDanger, true) ? $d['danger_level'] : 'Keine';

    // Kategorie: explizite ID (geprüft) ODER Freitext-Name (on-the-fly anlegen) ODER null.
    $catId = null;
    if (!empty($d['category_id'])) {
        $catId = (int)$d['category_id'];
        $stmt  = $db->prepare("SELECT 1 FROM item_categories WHERE id = ? AND character_id = ?");
        $stmt->execute([$catId, $charId]);
        if (!$stmt->fetchColumn()) apiError('Ungültige Kategorie für diesen Charakter');
    } elseif (!empty($d['category_name'])) {
        $catName = trim((string)$d['category_name']);
        if ($catName !== '') {
            // Existierende mit gleichem Namen wiederverwenden, sonst neu anlegen.
            $stmt = $db->prepare("SELECT id FROM item_categories WHERE character_id = ? AND name = ?");
            $stmt->execute([$charId, $catName]);
            $existing = $stmt->fetchColumn();
            if ($existing) {
                $catId = (int)$existing;
            } else {
                $db->prepare("INSERT INTO item_categories (character_id, name) VALUES (?, ?)")
                    ->execute([$charId, $catName]);
                $catId = (int)$db->lastInsertId();
            }
        }
    }

    return [$source, $locId, $workTable, $danger, $catId];
}

// Ersetzt die Komponentenliste eines Items. Akzeptiert Array von
// {component_id, quantity} ODER {name, quantity}. Fehlende Items werden angelegt.
// Jeder Eintrag wird mit Zyklus-Check verifiziert.
function replaceItemComponents(PDO $db, int $charId, int $itemId, array $components): void {
    if (!$components) return;
    $ins  = $db->prepare("INSERT IGNORE INTO item_components (item_id, component_id, quantity) VALUES (?, ?, ?)");
    $seen = [];
    foreach ($components as $c) {
        $qty = trim((string)($c['quantity'] ?? '1'));
        if ($qty === '') $qty = '1';

        $componentId = 0;
        if (!empty($c['component_id'])) {
            $componentId = (int)$c['component_id'];
            // Ownership der Komponente sicherstellen (cross-character verhindern).
            $stmt = $db->prepare("SELECT 1 FROM items WHERE id = ? AND character_id = ?");
            $stmt->execute([$componentId, $charId]);
            if (!$stmt->fetchColumn()) apiError('Komponente gehört nicht zu diesem Charakter');
        } else {
            $name = trim((string)($c['name'] ?? $c['component_name'] ?? ''));
            if ($name === '') continue;
            $componentId = getOrCreateItem($db, $charId, $name);
        }
        if ($componentId <= 0) continue;
        if ($componentId === $itemId) {
            apiError('Produkt kann nicht sich selbst als Komponente haben');
        }
        if (isset($seen[$componentId])) continue; // doppelte Zeile → still überspringen
        assertNoItemCycle($db, $itemId, $componentId);
        $ins->execute([$itemId, $componentId, $qty]);
        $seen[$componentId] = true;
    }
}
