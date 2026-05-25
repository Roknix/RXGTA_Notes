<?php
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// $data type hint 'mixed' requires PHP 8.0 — removed for 7.4 compat
function jsonResponse($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function apiError(string $message, int $code = 400): void {
    jsonResponse(['error' => $message], $code);
}

// Interne Fehler intern loggen, dem Client nur generische Meldung zeigen.
function apiServerError(Throwable $e): void {
    error_log('[API ' . ($_SERVER['REQUEST_URI'] ?? '?') . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => __('msg.server_error')]);
    exit;
}

function getJsonBody(): array {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function requireParam(array $data, string ...$keys): void {
    foreach ($keys as $key) {
        if (!isset($data[$key]) || (is_string($data[$key]) && trim($data[$key]) === '')) {
            apiError(__('msg.required_field', $key));
        }
    }
}

// match() requires PHP 8.0 — replaced with if/else for 7.4 compat
function priorityText(int $p): string {
    return __('enum.priority.' . ($p === 1 ? '1' : ($p === 3 ? '3' : '2')));
}

function priorityClass(int $p): string {
    if ($p === 1) return 'badge-danger';
    if ($p === 3) return 'badge-success';
    return 'badge-warning';
}

function dangerClass(string $d): string {
    if ($d === 'Hoch')   return 'badge-danger';
    if ($d === 'Mittel') return 'badge-warning';
    if ($d === 'Gering') return 'badge-success';
    return 'badge-muted';
}

function avatarInitials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $ini   = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $ini .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $ini ?: '?';
}

function avatarBgColor(string $name): string {
    $colors = ['#7c3aed','#2563eb','#059669','#dc2626','#d97706','#0891b2','#be185d','#0f766e'];
    return $colors[abs(crc32($name)) % count($colors)];
}

// Stellt sicher, dass eine optionale contact_id zum aktuellen Charakter gehört.
// Gibt den geprüften Wert zurück (oder null bei leerem Input). Bricht mit 400 ab, wenn fremd.
function resolveContactId(PDO $db, int $charId, $rawContactId): ?int {
    if (empty($rawContactId)) return null;
    $cid = (int)$rawContactId;
    if ($cid <= 0) return null;
    $stmt = $db->prepare("SELECT 1 FROM contacts WHERE id = ? AND character_id = ?");
    $stmt->execute([$cid, $charId]);
    if (!$stmt->fetchColumn()) {
        apiError('Ungültiger Kontakt für diesen Charakter');
    }
    return $cid;
}

// ===== Items-Helper =====
// SQL-Fragment: "ist dieses Item ein Rezept?". Spalten-qualifiziert via Alias `it`.
const ITEM_IS_RECIPE_SQL = "(
    EXISTS (SELECT 1 FROM item_components ic WHERE ic.item_id = it.id)
    OR it.location_id IS NOT NULL
    OR (it.work_table IS NOT NULL AND TRIM(it.work_table) <> '')
    OR it.danger_level <> 'Keine'
)";

// Erstellt ein Item, falls noch nicht vorhanden, und liefert dessen id.
// Per Default ohne Rezept-Metadaten — Anrufer kann diese danach setzen.
function getOrCreateItem(PDO $db, int $charId, string $name): int {
    $name = trim($name);
    if ($name === '') return 0;
    $db->prepare("INSERT IGNORE INTO items (character_id, name) VALUES (?, ?)")
        ->execute([$charId, $name]);
    $stmt = $db->prepare("SELECT id FROM items WHERE character_id = ? AND name = ?");
    $stmt->execute([$charId, $name]);
    return (int)$stmt->fetchColumn();
}

// Prüft, ob das Item zum Charakter gehört. Bricht via apiError ab, wenn nicht.
function requireItemOwnership(PDO $db, int $itemId, int $charId): void {
    $stmt = $db->prepare("SELECT character_id FROM items WHERE id = ?");
    $stmt->execute([$itemId]);
    $row = $stmt->fetch();
    if (!$row || (int)$row['character_id'] !== $charId) {
        apiError('Produkt nicht gefunden', 404);
    }
}

// Wirft eine Exception (mit klarer Meldung), wenn das Hinzufügen von $componentId zu $itemId
// einen Zyklus erzeugen würde. Selbstreferenz wird ebenfalls geprüft.
function assertNoItemCycle(PDO $db, int $itemId, int $componentId): void {
    if ($itemId === $componentId) {
        apiError('Produkt kann nicht sich selbst als Komponente haben');
    }
    // Wenn componentId (transitiv) bereits itemId als Nachfahren hat → würde Kreis erzeugen.
    $stmt = $db->prepare("
        WITH RECURSIVE descendants(id) AS (
            SELECT component_id FROM item_components WHERE item_id = ?
            UNION
            SELECT ic.component_id
            FROM item_components ic
            JOIN descendants d ON ic.item_id = d.id
        )
        SELECT 1 FROM descendants WHERE id = ? LIMIT 1
    ");
    $stmt->execute([$componentId, $itemId]);
    if ($stmt->fetchColumn()) {
        apiError('Würde einen Kreis erzeugen — Komponente abgelehnt');
    }
}
