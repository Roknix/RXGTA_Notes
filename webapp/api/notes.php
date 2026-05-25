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
            $stmt = $db->prepare("SELECT content, updated_at FROM notes WHERE character_id = ?");
            $stmt->execute([$charId]);
            $row = $stmt->fetch();
            jsonResponse(['content' => $row ? $row['content'] : '', 'updated_at' => $row ? (int)$row['updated_at'] : null]);
            break;

        case 'POST':
            verifyCSRF();
            $d       = getJsonBody();
            $content = $d['content'] ?? '';
            $db->prepare("
                INSERT INTO notes (character_id, content, updated_at)
                VALUES (?, ?, UNIX_TIMESTAMP())
                ON DUPLICATE KEY UPDATE content = VALUES(content), updated_at = UNIX_TIMESTAMP()
            ")->execute([$charId, $content]);
            jsonResponse(['success' => true, 'updated_at' => time()]);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Throwable $e) {
    apiServerError($e);
}
