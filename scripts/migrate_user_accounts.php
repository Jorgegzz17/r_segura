<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$db = getDB();
$db->exec(
    "CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(80) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB"
);

$userIdColumn = $db->query("SHOW COLUMNS FROM reports LIKE 'user_id'")->fetch();
if (!$userIdColumn) {
    $db->exec(
        'ALTER TABLE reports
            ADD COLUMN user_id INT UNSIGNED NULL AFTER id,
            ADD INDEX idx_reports_user_created (user_id, created_at),
            ADD CONSTRAINT fk_reports_user FOREIGN KEY (user_id)
                REFERENCES users(id) ON DELETE SET NULL'
    );
}

$db->exec(
    "CREATE TABLE IF NOT EXISTS report_likes (
        report_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (report_id, user_id),
        INDEX idx_report_likes_user (user_id),
        CONSTRAINT fk_report_likes_report FOREIGN KEY (report_id)
          REFERENCES reports(id) ON DELETE CASCADE,
        CONSTRAINT fk_report_likes_user FOREIGN KEY (user_id)
          REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB"
);

$db->exec(
    "CREATE TABLE IF NOT EXISTS report_media (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        report_id INT UNSIGNED NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        mime_type VARCHAR(100) NOT NULL,
        file_size INT UNSIGNED NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_report_media_report (report_id),
        CONSTRAINT fk_report_media_report FOREIGN KEY (report_id)
          REFERENCES reports(id) ON DELETE CASCADE
    ) ENGINE=InnoDB"
);
$db->exec(
    "CREATE TABLE IF NOT EXISTS report_updates (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        report_id INT UNSIGNED NOT NULL,
        author_type ENUM('admin','user') NOT NULL,
        author_id INT UNSIGNED NULL,
        message VARCHAR(1000) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_report_updates_report_created (report_id, created_at),
        CONSTRAINT fk_report_updates_report FOREIGN KEY (report_id)
          REFERENCES reports(id) ON DELETE CASCADE
    ) ENGINE=InnoDB"
);
$db->exec(
    "CREATE TABLE IF NOT EXISTS report_notifications (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        report_id INT UNSIGNED NOT NULL,
        message VARCHAR(255) NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_report_notifications_user_read (user_id, is_read, created_at),
        CONSTRAINT fk_report_notifications_user FOREIGN KEY (user_id)
          REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_report_notifications_report FOREIGN KEY (report_id)
          REFERENCES reports(id) ON DELETE CASCADE
    ) ENGINE=InnoDB"
);

fwrite(STDOUT, "Migración de cuentas de usuario completada. Los reportes existentes se conservaron.\n");
