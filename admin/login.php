<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/admin_auth.php';

startAdminSession();
if (isAdminAuthenticated()) {
    header('Location: index.php');
} else {
    header('Location: ../index.php');
}
exit;
