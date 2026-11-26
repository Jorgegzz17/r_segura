<?php
declare(strict_types=1);

const DEFAULT_ADMIN_USERNAME = 'rutas.seguras@cbtis258.edu.mx';
const DEFAULT_ADMIN_PASSWORD_HASH = '$2y$10$RWdYbh6TO7KDdUhU9fTuaum3Q.SxjYqoxJf1lEM1fm0rEBXf5.Oli';

function ensureDefaultAdminAccount(): void
{
    $stmt = getDB()->prepare(
        'INSERT IGNORE INTO admin_users (username, password_hash) VALUES (:username, :password_hash)'
    );
    $stmt->execute([
        ':username' => DEFAULT_ADMIN_USERNAME,
        ':password_hash' => DEFAULT_ADMIN_PASSWORD_HASH,
    ]);
}

function startAdminSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('rutas_seguras_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function createAdminCsrfToken(): string
{
    if (empty($_SESSION['admin_csrf'])) {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['admin_csrf'];
}

function isAdminAuthenticated(): bool
{
    return isset($_SESSION['admin_id']) && (int) $_SESSION['admin_id'] > 0;
}

function requireAdminApiAccess(): void
{
    startAdminSession();

    if (!isAdminAuthenticated()) {
        respond(401, ['ok' => false, 'error' => 'Se requiere una cuenta de administrador']);
    }

    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!isset($_SESSION['admin_csrf']) || !hash_equals($_SESSION['admin_csrf'], $csrf)) {
        respond(403, ['ok' => false, 'error' => 'Token de seguridad no válido']);
    }
}
