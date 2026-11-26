<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$db = getDB();
$column = $db->query("SHOW COLUMNS FROM campus_location LIKE 'image_path'")->fetch();
if (!$column) {
    $db->exec('ALTER TABLE campus_location ADD COLUMN image_path VARCHAR(255) NULL AFTER longitude');
}

fwrite(STDOUT, "Migración de fotografías del plantel completada.\n");
