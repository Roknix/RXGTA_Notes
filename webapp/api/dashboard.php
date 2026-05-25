<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

startSecureSession();
requireAuth();
header('Content-Type: application/json; charset=utf-8');

try {
    $charId = requireCharacterId();
    $db     = getDB();

    // Statistiken
    $stats = [];
    foreach ([
        'contacts'    => "SELECT COUNT(*) FROM contacts WHERE character_id = ?",
        'recipes'     => "SELECT COUNT(*) FROM items it WHERE character_id = ? AND " . ITEM_IS_RECIPE_SQL,
        'locations'   => "SELECT COUNT(*) FROM locations WHERE character_id = ?",
        'vehicles'    => "SELECT COUNT(*) FROM vehicles WHERE character_id = ?",
        'open_orders' => "SELECT COUNT(*) FROM work_orders WHERE character_id = ? AND done = 0",
        'open_liab'   => "SELECT COUNT(*) FROM liabilities WHERE character_id = ? AND settled = 0",
        'open_claims' => "SELECT COUNT(*) FROM claims WHERE character_id = ? AND settled = 0",
    ] as $key => $sql) {
        $s = $db->prepare($sql);
        $s->execute([$charId]);
        $stats[$key] = (int)$s->fetchColumn();
    }

    // Steckbrief-Status (vorhandene Felder + öffentlich an/aus)
    $bio = [
        'filled_fields'  => 0,
        'public_enabled' => false,
    ];
    $s = $db->prepare("SELECT birthday, birthplace, nationality, occupation, height, appearance, family, biography, public_enabled
                        FROM character_profile WHERE character_id = ?");
    $s->execute([$charId]);
    $bioRow = $s->fetch();
    if ($bioRow) {
        foreach (['birthday','birthplace','nationality','occupation','height','appearance','family','biography'] as $f) {
            if (trim((string)($bioRow[$f] ?? '')) !== '') $bio['filled_fields']++;
        }
        $bio['public_enabled'] = (int)$bioRow['public_enabled'] === 1;
    }

    // Neueste Kontakte (3)
    $s = $db->prepare("SELECT id, name, phone, company, `grouping`, role_job FROM contacts
                        WHERE character_id = ? ORDER BY id DESC LIMIT 3");
    $s->execute([$charId]);
    $recentContacts = $s->fetchAll();

    // Offene Verbindlichkeiten (3, nach Prio)
    $s = $db->prepare("SELECT l.id, l.name, l.amount, l.priority, l.date, c.phone AS contact_phone
                        FROM liabilities l LEFT JOIN contacts c ON l.contact_id = c.id
                        WHERE l.character_id = ? AND l.settled = 0
                        ORDER BY l.priority ASC, l.id DESC LIMIT 3");
    $s->execute([$charId]);
    $openLiab = $s->fetchAll();

    // Offene Forderungen (3, nach Prio)
    $s = $db->prepare("SELECT cl.id, cl.name, cl.amount, cl.priority, cl.date, c.phone AS contact_phone
                        FROM claims cl LEFT JOIN contacts c ON cl.contact_id = c.id
                        WHERE cl.character_id = ? AND cl.settled = 0
                        ORDER BY cl.priority ASC, cl.id DESC LIMIT 3");
    $s->execute([$charId]);
    $openClaims = $s->fetchAll();

    // Offene Aufträge (3, nach Deadline)
    $s = $db->prepare("SELECT wo.id, wo.name, wo.what, wo.how_much, wo.until_when, c.phone AS contact_phone
                        FROM work_orders wo LEFT JOIN contacts c ON wo.contact_id = c.id
                        WHERE wo.character_id = ? AND wo.done = 0
                        ORDER BY
                            CASE WHEN wo.until_when IS NULL OR wo.until_when = '' THEN 1 ELSE 0 END,
                            wo.until_when ASC LIMIT 3");
    $s->execute([$charId]);
    $openOrders = $s->fetchAll();

    jsonResponse([
        'stats'          => $stats,
        'bio'            => $bio,
        'recent_contacts'=> $recentContacts,
        'open_liab'      => $openLiab,
        'open_claims'    => $openClaims,
        'open_orders'    => $openOrders,
    ]);

} catch (Throwable $e) {
    apiServerError($e);
}
