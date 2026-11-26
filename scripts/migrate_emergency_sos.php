<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$db = getDB();
$column = $db->query("SHOW COLUMNS FROM users LIKE 'emergency_phone'")->fetch();
if (!$column) {
    $db->exec('ALTER TABLE users ADD COLUMN emergency_phone VARCHAR(16) NULL AFTER username');
}

$db->exec(
    'CREATE TABLE IF NOT EXISTS sos_sessions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
        latitude DECIMAL(10,7) NULL,
        longitude DECIMAL(10,7) NULL,
        expires_at DATETIME NOT NULL,
        stopped_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_sos_user_active (user_id, stopped_at, expires_at),
        CONSTRAINT fk_sos_sessions_user FOREIGN KEY (user_id)
            REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB'
);

fwrite(STDOUT, "Migración de contactos y seguimiento SOS completada.\n");
