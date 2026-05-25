<?php
require_once __DIR__ . '/config.php';

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
        PDO::ATTR_EMULATE_PREPARES   => false,
        Pdo\Mysql::ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ]);

    initSchema($pdo);
    runMigrations($pdo);
    return $pdo;
}

function initSchema(PDO $db): void {
    // Hinweis: TEXT-Spalten dürfen in MySQL keine DEFAULT-Werte haben. Deshalb sind
    // Felder mit "JSON-Array"-Default als VARCHAR mit ausreichender Länge modelliert.
    // Timestamps sind BIGINT UNSIGNED (Unix-Sekunden), in INSERTs explizit per
    // UNIX_TIMESTAMP() oder PHP time() befüllt.

    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id                INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
            username          VARCHAR(64)   NOT NULL UNIQUE,
            password_hash     VARCHAR(255)  NOT NULL,
            is_admin          TINYINT(1)    NOT NULL DEFAULT 0,
            failed_attempts   INT UNSIGNED  NOT NULL DEFAULT 0,
            locked_until      BIGINT UNSIGNED NULL,
            last_character_id INT UNSIGNED  NULL,
            session_epoch     INT UNSIGNED  NOT NULL DEFAULT 0,
            locale            VARCHAR(8)    NOT NULL DEFAULT 'en',
            created_at        BIGINT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS characters (
            id             INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
            user_id        INT UNSIGNED  NOT NULL,
            name           VARCHAR(255)  NOT NULL,
            server         VARCHAR(255)  NULL,
            description    TEXT          NULL,
            avatar_color   VARCHAR(16)   NOT NULL DEFAULT '#7c3aed',
            hidden_modules VARCHAR(2000) NOT NULL DEFAULT '[]',
            created_at     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS character_profile (
            character_id    INT UNSIGNED  PRIMARY KEY,
            birthday        VARCHAR(64)   NULL,
            birthplace      VARCHAR(255)  NULL,
            nationality     VARCHAR(255)  NULL,
            occupation      VARCHAR(255)  NULL,
            height          VARCHAR(64)   NULL,
            appearance      TEXT          NULL,
            family          TEXT          NULL,
            biography       MEDIUMTEXT    NULL,
            public_fields   VARCHAR(1000) NOT NULL DEFAULT '[]',
            public_enabled  TINYINT(1)    NOT NULL DEFAULT 0,
            public_token    VARCHAR(64)   NULL,
            updated_at      BIGINT UNSIGNED NOT NULL DEFAULT 0,
            UNIQUE KEY uniq_public_token (public_token),
            FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS vehicles (
            id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
            character_id  INT UNSIGNED  NOT NULL,
            name          VARCHAR(255)  NOT NULL,
            make          VARCHAR(255)  NULL,
            model         VARCHAR(255)  NULL,
            plate         VARCHAR(64)   NULL,
            color         VARCHAR(64)   NULL,
            modifications TEXT          NULL,
            hideouts      TEXT          NULL,
            insurance     TEXT          NULL,
            next_service  VARCHAR(64)   NULL,
            notes         TEXT          NULL,
            FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS contacts (
            id           INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
            character_id INT UNSIGNED  NOT NULL,
            name         VARCHAR(255)  NOT NULL,
            phone        VARCHAR(64)   NULL,
            email        VARCHAR(255)  NULL,
            company      VARCHAR(255)  NULL,
            `grouping`   VARCHAR(255)  NULL,
            known_via    VARCHAR(255)  NULL,
            role_job     VARCHAR(255)  NULL,
            notes        TEXT          NULL,
            FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS locations (
            id           INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
            character_id INT UNSIGNED  NOT NULL,
            name         VARCHAR(255)  NOT NULL,
            zip          VARCHAR(32)   NULL,
            illegal      TINYINT(1)    NOT NULL DEFAULT 0,
            requires     TEXT          NULL,
            description  TEXT          NULL,
            FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS item_categories (
            id           INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
            character_id INT UNSIGNED  NOT NULL,
            name         VARCHAR(255)  NOT NULL,
            UNIQUE KEY uniq_char_cat (character_id, name),
            FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS items (
            id           INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
            character_id INT UNSIGNED  NOT NULL,
            name         VARCHAR(255)  NOT NULL,
            source       VARCHAR(255)  NULL,
            location_id  INT UNSIGNED  NULL,
            work_table   VARCHAR(255)  NULL,
            danger_level VARCHAR(16)   NOT NULL DEFAULT 'Keine',
            category_id  INT UNSIGNED  NULL,
            UNIQUE KEY uniq_char_item (character_id, name),
            CONSTRAINT items_danger_chk CHECK (danger_level IN ('Keine','Gering','Mittel','Hoch')),
            FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE,
            FOREIGN KEY (location_id)  REFERENCES locations(id)  ON DELETE SET NULL,
            FOREIGN KEY (category_id)  REFERENCES item_categories(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS item_components (
            id           INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
            item_id      INT UNSIGNED  NOT NULL,
            component_id INT UNSIGNED  NOT NULL,
            quantity     VARCHAR(64)   NOT NULL DEFAULT '1',
            UNIQUE KEY uniq_item_comp (item_id, component_id),
            CONSTRAINT item_comp_no_self CHECK (item_id <> component_id),
            FOREIGN KEY (item_id)      REFERENCES items(id) ON DELETE CASCADE,
            FOREIGN KEY (component_id) REFERENCES items(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS buyers (
            id           INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
            character_id INT UNSIGNED  NOT NULL,
            contact_id   INT UNSIGNED  NULL,
            name         VARCHAR(255)  NOT NULL,
            company      VARCHAR(255)  NULL,
            needs        TEXT          NULL,
            priority     TINYINT       NOT NULL DEFAULT 2,
            CONSTRAINT buyers_prio_chk CHECK (priority IN (1,2,3)),
            FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE,
            FOREIGN KEY (contact_id)   REFERENCES contacts(id)   ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS storage (
            id             INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
            character_id   INT UNSIGNED  NOT NULL,
            storage_name   VARCHAR(255)  NOT NULL,
            owner          VARCHAR(255)  NULL,
            location       VARCHAR(255)  NULL,
            storage_number VARCHAR(64)   NULL,
            pin            VARCHAR(64)   NULL,
            notes          TEXT          NULL,
            FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS liabilities (
            id           INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
            character_id INT UNSIGNED  NOT NULL,
            contact_id   INT UNSIGNED  NULL,
            name         VARCHAR(255)  NOT NULL,
            amount       VARCHAR(64)   NULL,
            settled      TINYINT(1)    NOT NULL DEFAULT 0,
            date         VARCHAR(64)   NULL,
            priority     TINYINT       NOT NULL DEFAULT 2,
            CONSTRAINT liab_prio_chk CHECK (priority IN (1,2,3)),
            FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE,
            FOREIGN KEY (contact_id)   REFERENCES contacts(id)   ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS claims (
            id           INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
            character_id INT UNSIGNED  NOT NULL,
            contact_id   INT UNSIGNED  NULL,
            name         VARCHAR(255)  NOT NULL,
            amount       VARCHAR(64)   NULL,
            settled      TINYINT(1)    NOT NULL DEFAULT 0,
            date         VARCHAR(64)   NULL,
            priority     TINYINT       NOT NULL DEFAULT 2,
            CONSTRAINT claims_prio_chk CHECK (priority IN (1,2,3)),
            FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE,
            FOREIGN KEY (contact_id)   REFERENCES contacts(id)   ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS companies (
            id           INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
            character_id INT UNSIGNED  NOT NULL,
            name         VARCHAR(255)  NOT NULL,
            notes        TEXT          NULL,
            UNIQUE KEY uniq_char_company (character_id, name),
            FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS work_orders (
            id               INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
            character_id     INT UNSIGNED  NOT NULL,
            contact_id       INT UNSIGNED  NULL,
            name             VARCHAR(255)  NOT NULL,
            what             TEXT          NULL,
            how_much         VARCHAR(64)   NULL,
            until_when       VARCHAR(64)   NULL,
            description      TEXT          NULL,
            done             TINYINT(1)    NOT NULL DEFAULT 0,
            priority         TINYINT       NOT NULL DEFAULT 2,
            is_company_order TINYINT(1)    NOT NULL DEFAULT 0,
            company_id       INT UNSIGNED  NULL,
            CONSTRAINT wo_prio_chk CHECK (priority IN (1,2,3)),
            FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE,
            FOREIGN KEY (contact_id)   REFERENCES contacts(id)   ON DELETE SET NULL,
            FOREIGN KEY (company_id)   REFERENCES companies(id)  ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS work_order_items (
            id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
            work_order_id INT UNSIGNED  NOT NULL,
            item_id       INT UNSIGNED  NOT NULL,
            quantity      VARCHAR(64)   NOT NULL DEFAULT '1',
            UNIQUE KEY uniq_wo_item (work_order_id, item_id),
            FOREIGN KEY (work_order_id) REFERENCES work_orders(id) ON DELETE CASCADE,
            FOREIGN KEY (item_id)       REFERENCES items(id)       ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS notes (
            id           INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
            character_id INT UNSIGNED  NOT NULL UNIQUE,
            content      MEDIUMTEXT    NOT NULL,
            updated_at   BIGINT UNSIGNED NOT NULL DEFAULT 0,
            FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Persistent-Login-Tokens (Remember-Me, Selector+Verifier-Pattern).
    // verifier_hash = HMAC-SHA256(verifier, APP_PEPPER). Klartext-Verifier nie speichern.
    $db->exec("
        CREATE TABLE IF NOT EXISTS auth_tokens (
            id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
            user_id       INT UNSIGNED  NOT NULL,
            selector      VARCHAR(64)   NOT NULL UNIQUE,
            verifier_hash VARCHAR(255)  NOT NULL,
            fingerprint   VARCHAR(255)  NOT NULL,
            user_agent    VARCHAR(500)  NULL,
            expires_at    BIGINT UNSIGNED NOT NULL,
            last_used_at  BIGINT UNSIGNED NULL,
            created_at    BIGINT UNSIGNED NOT NULL DEFAULT 0,
            KEY idx_auth_tokens_user    (user_id),
            KEY idx_auth_tokens_expires (expires_at),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS login_ip_attempts (
            ip              VARCHAR(64)   PRIMARY KEY,
            failed_count    INT UNSIGNED  NOT NULL DEFAULT 0,
            first_failed_at BIGINT UNSIGNED NULL,
            locked_until    BIGINT UNSIGNED NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

// ===== Migration System (MySQL) =====
// Identische Semantik wie zuvor in SQLite: fehlende Spalten/Tabellen werden idempotent
// angelegt, ohne bestehende Daten zu verlieren. Backend über INFORMATION_SCHEMA.

function addColIfMissing(PDO $db, string $table, string $col, string $def): void {
    $stmt = $db->prepare("
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
    ");
    $stmt->execute([$table, $col]);
    if (!$stmt->fetchColumn()) {
        $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}");
    }
}

function tableExists(PDO $db, string $name): bool {
    $stmt = $db->prepare("
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = ?
    ");
    $stmt->execute([$name]);
    return (bool)$stmt->fetchColumn();
}

function runMigrations(PDO $db): void {
    // Wird bei JEDEM Request über getDB() aufgerufen. Idempotent: nur was fehlt
    // wird ergänzt. Sorgt dafür, dass bestehende Installationen automatisch auf den
    // aktuellen Schema-Stand kommen, wenn nur die PHP-Files aktualisiert wurden.
    //
    // === Pattern bei Schema-Änderungen ===
    //
    // 1) Neue Spalte in bestehender Tabelle:
    //      addColIfMissing($db, 'characters', 'theme', "VARCHAR(16) NOT NULL DEFAULT 'dark'");
    //
    // 2) Neue Tabelle:
    //      $db->exec("
    //          CREATE TABLE IF NOT EXISTS character_tags (
    //              id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    //              character_id INT UNSIGNED NOT NULL,
    //              tag          VARCHAR(64) NOT NULL,
    //              UNIQUE KEY (character_id, tag),
    //              FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE
    //          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    //      ");
    //
    // WICHTIG: Bei jeder Schema-Änderung BEIDES pflegen:
    //   - initSchema()    → für Neuinstallationen (CREATE TABLE mit allen aktuellen Spalten)
    //   - runMigrations() → für bestehende Installationen (addColIfMissing / IF NOT EXISTS)

    // ----------------------------------------------------------------------
    // 2026-05-25 — Mehrsprachigkeit: users.locale
    // Bestehende Instanzen waren bis hierhin nur deutsch, deshalb kriegen alle
    // bereits angelegten User initial 'de'. Neuinstallationen (keine User in DB)
    // bekommen sofort 'en' als Default. Der Spalten-DEFAULT bleibt am Ende immer
    // 'en' für künftige INSERTs — neue Accounts starten also auf Englisch.
    $stmt = $db->prepare("
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'locale'
    ");
    $stmt->execute();
    if (!$stmt->fetchColumn()) {
        $hasUsers = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn() > 0;
        $initial  = $hasUsers ? 'de' : 'en';
        $db->exec("ALTER TABLE users ADD COLUMN locale VARCHAR(8) NOT NULL DEFAULT '{$initial}'");
        if ($hasUsers) {
            // Spalten-Default für künftige INSERTs auf 'en' korrigieren.
            // Bestehende Rows behalten ihren bereits gesetzten Wert ('de').
            $db->exec("ALTER TABLE users ALTER COLUMN locale SET DEFAULT 'en'");
        }
    }
}

function isFirstRun(): bool {
    try {
        $count = getDB()->query("SELECT COUNT(*) FROM users")->fetchColumn();
        return (int)$count === 0;
    } catch (Throwable $e) {
        return true;
    }
}
