<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

startSecureSession();
requireAuth();

header('Content-Type: application/json; charset=utf-8');

const BIO_FIELDS = ['birthday','birthplace','nationality','occupation','height','appearance','family','biography'];

function urlsafeToken(int $bytes = 24): string {
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function ensureProfileRow(PDO $db, int $charId): void {
    $db->prepare("INSERT IGNORE INTO character_profile (character_id, public_fields, updated_at) VALUES (?, '[]', UNIX_TIMESTAMP())")->execute([$charId]);
}

function fetchProfile(PDO $db, int $charId): array {
    ensureProfileRow($db, $charId);
    $stmt = $db->prepare("SELECT * FROM character_profile WHERE character_id = ?");
    $stmt->execute([$charId]);
    $row = $stmt->fetch();
    if (!$row) {
        // race-safe fallback
        return [
            'character_id'   => $charId,
            'public_fields'  => [],
            'public_enabled' => 0,
            'public_token'   => null,
        ];
    }
    $publicFields = json_decode($row['public_fields'] ?? '[]', true);
    if (!is_array($publicFields)) $publicFields = [];
    // Nur bekannte Felder akzeptieren
    $publicFields = array_values(array_intersect($publicFields, BIO_FIELDS));
    $row['public_fields']  = $publicFields;
    $row['public_enabled'] = (int)$row['public_enabled'];
    return $row;
}

try {
    $charId = requireCharacterId();
    $db     = getDB();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        jsonResponse(fetchProfile($db, $charId));
    }

    if ($method === 'PUT') {
        verifyCSRF();
        $data = getJsonBody();
        ensureProfileRow($db, $charId);

        $values = [];
        foreach (BIO_FIELDS as $f) {
            $v = $data[$f] ?? null;
            if ($v !== null) $v = trim((string)$v);
            $values[$f] = ($v === '' ? null : $v);
        }

        // public_fields: nur valide Slugs übernehmen
        $publicFields = $data['public_fields'] ?? [];
        if (!is_array($publicFields)) $publicFields = [];
        $publicFields = array_values(array_unique(array_intersect($publicFields, BIO_FIELDS)));

        $sql = "UPDATE character_profile SET
                    birthday=?, birthplace=?, nationality=?, occupation=?,
                    height=?, appearance=?, family=?, biography=?,
                    public_fields=?, updated_at=UNIX_TIMESTAMP()
                WHERE character_id=?";
        $db->prepare($sql)->execute([
            $values['birthday'], $values['birthplace'], $values['nationality'], $values['occupation'],
            $values['height'], $values['appearance'], $values['family'], $values['biography'],
            json_encode($publicFields, JSON_UNESCAPED_UNICODE),
            $charId,
        ]);
        jsonResponse(fetchProfile($db, $charId));
    }

    if ($method === 'POST') {
        verifyCSRF();
        $data   = getJsonBody();
        $action = $data['action'] ?? '';
        ensureProfileRow($db, $charId);

        if ($action === 'enable_public') {
            // Token erzeugen, falls noch nicht vorhanden.
            $stmt = $db->prepare("SELECT public_token FROM character_profile WHERE character_id = ?");
            $stmt->execute([$charId]);
            $existing = (string)$stmt->fetchColumn();
            if ($existing === '') {
                $token = urlsafeToken();
                $db->prepare("UPDATE character_profile SET public_token=?, public_enabled=1 WHERE character_id=?")
                    ->execute([$token, $charId]);
            } else {
                $db->prepare("UPDATE character_profile SET public_enabled=1 WHERE character_id=?")
                    ->execute([$charId]);
            }
            jsonResponse(fetchProfile($db, $charId));
        }

        if ($action === 'disable_public') {
            $db->prepare("UPDATE character_profile SET public_enabled=0 WHERE character_id=?")->execute([$charId]);
            jsonResponse(fetchProfile($db, $charId));
        }

        if ($action === 'rotate_token') {
            // Versuche bis zu 3x bei Kollision (extrem unwahrscheinlich, aber UNIQUE-safe).
            for ($i = 0; $i < 3; $i++) {
                try {
                    $token = urlsafeToken();
                    $db->prepare("UPDATE character_profile SET public_token=? WHERE character_id=?")
                        ->execute([$token, $charId]);
                    break;
                } catch (PDOException $e) {
                    if ($i === 2) throw $e;
                }
            }
            jsonResponse(fetchProfile($db, $charId));
        }

        apiError('Unbekannte Aktion');
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (Throwable $e) {
    apiServerError($e);
}
