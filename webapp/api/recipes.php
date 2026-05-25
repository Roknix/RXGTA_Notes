<?php
// Kompatibilitäts-Shim: arbeitet auf der neuen items / item_components Struktur,
// liefert aber das alte JSON-Format an das bestehende Frontend.
// Ein 'recipe' ist hier ein Item mit Rezept-Charakter (Komponenten oder Crafting-Metadaten),
// eine 'ingredient' ein Item ohne diesen Charakter.
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
            $action = $_GET['action'] ?? '';

            if ($action === 'ingredients') {
                // „Zutaten" = Items ohne Rezept-Charakter.
                $stmt = $db->prepare("
                    SELECT it.id, it.name, it.source
                    FROM items it
                    WHERE it.character_id = ?
                      AND NOT " . ITEM_IS_RECIPE_SQL . "
                    ORDER BY it.name
                ");
                $stmt->execute([$charId]);
                jsonResponse($stmt->fetchAll());
            }

            // „Rezepte" = Items mit Rezept-Charakter.
            $stmt = $db->prepare("
                SELECT it.id, it.character_id, it.name, it.location_id, it.work_table, it.danger_level,
                       l.name AS location_name
                FROM items it
                LEFT JOIN locations l ON it.location_id = l.id
                WHERE it.character_id = ?
                  AND " . ITEM_IS_RECIPE_SQL . "
                ORDER BY it.name
            ");
            $stmt->execute([$charId]);
            $recipes = $stmt->fetchAll();
            foreach ($recipes as &$recipe) {
                $recipe['ingredients'] = getRecipeComponentList($db, (int)$recipe['id']);
            }
            jsonResponse($recipes);
            break;

        case 'POST':
            verifyCSRF();
            $d = getJsonBody();
            $action = $d['action'] ?? '';

            if ($action === 'add_ingredient') {
                requireParam($d, 'name');
                $name   = trim($d['name']);
                $source = trim($d['source'] ?? '');
                $itemId = getOrCreateItem($db, $charId, $name);
                if ($itemId <= 0) apiError('Konnte Zutat nicht anlegen');
                $db->prepare("UPDATE items SET source = NULLIF(?, '') WHERE id = ?")
                    ->execute([$source, $itemId]);
                $stmt = $db->prepare("SELECT id, name, source FROM items WHERE id = ?");
                $stmt->execute([$itemId]);
                jsonResponse($stmt->fetch(), 201);
            }
            if ($action === 'update_ingredient') {
                $id   = (int)($d['id'] ?? 0);
                requireItemOwnership($db, $id, $charId);
                if (isItemRecipe($db, $id)) {
                    apiError('Dieses Item ist ein Rezept und kann nicht über die Zutaten-Aktion bearbeitet werden');
                }
                $name = trim($d['name'] ?? '');
                if ($name === '') apiError('Pflichtfeld fehlt: name');
                // Namens-Konflikt mit anderem Item desselben Charakters?
                $check = $db->prepare("SELECT id FROM items WHERE character_id = ? AND name = ? AND id <> ?");
                $check->execute([$charId, $name, $id]);
                if ($check->fetchColumn()) apiError('Ein anderes Item mit diesem Namen existiert bereits');
                $db->prepare("UPDATE items SET name = ?, source = NULLIF(TRIM(?), '') WHERE id = ?")
                    ->execute([$name, $d['source'] ?? '', $id]);
                jsonResponse(['success' => true]);
            }
            if ($action === 'delete_ingredient') {
                $id = (int)($d['id'] ?? 0);
                requireItemOwnership($db, $id, $charId);
                if (isItemRecipe($db, $id)) {
                    apiError('Dieses Item ist ein Rezept und kann nicht über die Zutaten-Aktion gelöscht werden');
                }
                $db->prepare("DELETE FROM items WHERE id = ?")->execute([$id]);
                jsonResponse(['success' => true]);
            }

            // Rezept anlegen ODER bestehendes Item zum Rezept aufwerten.
            // Wenn ein Item mit diesem Namen bereits existiert, übernehmen wir seine id und
            // setzen Rezept-Metadaten + Komponenten neu — das ist genau die „Upgrade"-Semantik
            // (eine Zutat wird zum Rezept).
            requireParam($d, 'name');
            $validDanger = ['Keine','Gering','Mittel','Hoch'];
            $danger = in_array($d['danger_level'] ?? '', $validDanger, true) ? $d['danger_level'] : 'Keine';
            $locId  = !empty($d['location_id']) ? (int)$d['location_id'] : null;
            if ($locId !== null) requireLocationOwnership($db, $locId, $charId);
            $table  = trim($d['work_table'] ?? '');
            $tableNorm = $table === '' ? null : $table;
            $name   = trim($d['name']);

            $existingStmt = $db->prepare("SELECT id FROM items WHERE character_id = ? AND name = ?");
            $existingStmt->execute([$charId, $name]);
            $existingId = (int)($existingStmt->fetchColumn() ?: 0);

            $db->beginTransaction();
            try {
                if ($existingId > 0) {
                    $db->prepare("
                        UPDATE items SET location_id = ?, work_table = ?, danger_level = ?
                        WHERE id = ?
                    ")->execute([$locId, $tableNorm, $danger, $existingId]);
                    $db->prepare("DELETE FROM item_components WHERE item_id = ?")->execute([$existingId]);
                    $recipeId = $existingId;
                } else {
                    $db->prepare("
                        INSERT INTO items (character_id, name, location_id, work_table, danger_level)
                        VALUES (?, ?, ?, ?, ?)
                    ")->execute([$charId, $name, $locId, $tableNorm, $danger]);
                    $recipeId = (int)$db->lastInsertId();
                }

                saveRecipeComponents($db, $charId, $recipeId, $d['ingredients'] ?? []);
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }

            jsonResponse(loadRecipeWithIngredients($db, $recipeId), $existingId > 0 ? 200 : 201);
            break;

        case 'PUT':
            verifyCSRF();
            $d  = getJsonBody();
            $id = (int)($d['id'] ?? 0);
            requireItemOwnership($db, $id, $charId);

            requireParam($d, 'name');
            $validDanger = ['Keine','Gering','Mittel','Hoch'];
            $danger = in_array($d['danger_level'] ?? '', $validDanger, true) ? $d['danger_level'] : 'Keine';
            $locId  = !empty($d['location_id']) ? (int)$d['location_id'] : null;
            if ($locId !== null) requireLocationOwnership($db, $locId, $charId);
            $table  = trim($d['work_table'] ?? '');
            $name   = trim($d['name']);

            // Namens-Konflikt mit anderem Item desselben Charakters?
            $check = $db->prepare("SELECT id FROM items WHERE character_id = ? AND name = ? AND id <> ?");
            $check->execute([$charId, $name, $id]);
            if ($check->fetchColumn()) apiError('Ein anderes Item mit diesem Namen existiert bereits');

            $db->beginTransaction();
            try {
                $db->prepare("
                    UPDATE items SET name = ?, location_id = ?, work_table = ?, danger_level = ?
                    WHERE id = ?
                ")->execute([$name, $locId, $table === '' ? null : $table, $danger, $id]);
                $db->prepare("DELETE FROM item_components WHERE item_id = ?")->execute([$id]);
                saveRecipeComponents($db, $charId, $id, $d['ingredients'] ?? []);
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }

            jsonResponse(['success' => true]);
            break;

        case 'DELETE':
            verifyCSRF();
            $d  = getJsonBody();
            $id = (int)($d['id'] ?? 0);
            requireItemOwnership($db, $id, $charId);
            // Wenn das Item kein Rezept ist, würde delete_ingredient korrekter passen — aber wir
            // erlauben es trotzdem, weil das Frontend hier einen Rezept-Löschen-Button hat.
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

function isItemRecipe(PDO $db, int $itemId): bool {
    $stmt = $db->prepare("SELECT 1 FROM items it WHERE it.id = ? AND " . ITEM_IS_RECIPE_SQL . " LIMIT 1");
    $stmt->execute([$itemId]);
    return (bool)$stmt->fetchColumn();
}

function requireLocationOwnership(PDO $db, int $locId, int $charId): void {
    $stmt = $db->prepare("SELECT 1 FROM locations WHERE id = ? AND character_id = ?");
    $stmt->execute([$locId, $charId]);
    if (!$stmt->fetchColumn()) apiError('Ungültiger Ort für diesen Charakter');
}

// Speichert die Komponenten eines Rezept-Items. Akzeptiert Array von {ingredient_name|name, quantity}.
// Fehlende Items werden als „Zutat" angelegt (ohne Rezept-Metadaten). Zyklen werden abgewiesen.
function saveRecipeComponents(PDO $db, int $charId, int $recipeId, array $components): void {
    if (!$components) return;
    $ins  = $db->prepare("INSERT IGNORE INTO item_components (item_id, component_id, quantity) VALUES (?, ?, ?)");
    foreach ($components as $c) {
        $name = trim((string)($c['ingredient_name'] ?? $c['name'] ?? ''));
        $qty  = trim((string)($c['quantity'] ?? '1'));
        if ($name === '') continue;
        $componentId = getOrCreateItem($db, $charId, $name);
        if ($componentId <= 0) continue;
        if ($componentId === $recipeId) continue; // Selbstreferenz still überspringen
        assertNoItemCycle($db, $recipeId, $componentId);
        $ins->execute([$recipeId, $componentId, $qty === '' ? '1' : $qty]);
    }
}

// Komponenten eines Rezept-Items im alten ingredient-Format zurückliefern.
function getRecipeComponentList(PDO $db, int $recipeId): array {
    $stmt = $db->prepare("
        SELECT ic.quantity,
               c.id     AS ingredient_id,
               c.name   AS ingredient_name,
               c.source AS ingredient_source
        FROM item_components ic
        JOIN items c ON ic.component_id = c.id
        WHERE ic.item_id = ?
        ORDER BY c.name
    ");
    $stmt->execute([$recipeId]);
    return $stmt->fetchAll();
}

function loadRecipeWithIngredients(PDO $db, int $recipeId): array {
    $stmt = $db->prepare("
        SELECT it.id, it.character_id, it.name, it.location_id, it.work_table, it.danger_level,
               l.name AS location_name
        FROM items it
        LEFT JOIN locations l ON it.location_id = l.id
        WHERE it.id = ?
    ");
    $stmt->execute([$recipeId]);
    $recipe = $stmt->fetch();
    if (!$recipe) return [];
    $recipe['ingredients'] = getRecipeComponentList($db, $recipeId);
    return $recipe;
}
