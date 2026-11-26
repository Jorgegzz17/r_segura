<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$db = getDB();
$db->exec(
    "CREATE TABLE IF NOT EXISTS safe_route_proposals (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        name VARCHAR(120) NOT NULL,
        description TEXT NOT NULL,
        status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
        reviewed_by INT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reviewed_at TIMESTAMP NULL DEFAULT NULL,
        INDEX idx_route_proposals_status_created (status, created_at),
        INDEX idx_route_proposals_user_created (user_id, created_at),
        CONSTRAINT fk_route_proposals_user FOREIGN KEY (user_id)
            REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_route_proposals_admin FOREIGN KEY (reviewed_by)
            REFERENCES admin_users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB"
);
$db->exec(
    "CREATE TABLE IF NOT EXISTS safe_route_proposal_points (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        proposal_id INT UNSIGNED NOT NULL,
        sequence_no SMALLINT UNSIGNED NOT NULL,
        latitude DECIMAL(10,7) NOT NULL,
        longitude DECIMAL(10,7) NOT NULL,
        UNIQUE KEY uq_proposal_sequence (proposal_id, sequence_no),
        CONSTRAINT fk_route_proposal_points_proposal FOREIGN KEY (proposal_id)
            REFERENCES safe_route_proposals(id) ON DELETE CASCADE
    ) ENGINE=InnoDB"
);

fwrite(STDOUT, "Migración de propuestas de rutas seguras completada.\n");
