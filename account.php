<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/user_auth.php';

startUserSession();

function accountEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$error = '';
$mode = ($_GET['mode'] ?? '') === 'register' ? 'register' : 'login';
$nextInput = $_POST['next'] ?? $_GET['next'] ?? '';
$next = in_array($nextInput, ['report', 'route_proposal', 'home'], true) ? $nextInput : '';
$successUrl = $next === 'report'
    ? 'index.php?report=1'
    : ($next === 'route_proposal' ? 'index.php?propose_route=1' : ($next === 'home' ? 'index.php' : 'account.php'));
$csrf = createUserCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $sentToken = $_POST['csrf_token'] ?? '';

    if (!is_string($sentToken) || !hash_equals($csrf, $sentToken)) {
        $error = 'La sesión expiró. Recarga la página e inténtalo de nuevo.';
    } elseif ($action === 'logout') {
        if (isUserAuthenticated()) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', [
                    'expires' => time() - 42000,
                    'path' => $params['path'],
                    'secure' => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => 'Strict',
                ]);
            }
            session_destroy();
        }

        header('Location: index.php');
        exit;
    } elseif (!isUserAuthenticated() && in_array($action, ['login', 'register'], true)) {
        $usernameInput = $_POST['username'] ?? '';
        $passwordInput = $_POST['password'] ?? '';
        $username = is_string($usernameInput) ? trim($usernameInput) : '';
        $password = is_string($passwordInput) ? $passwordInput : '';
        $validUsername = preg_match('/^[a-zA-Z0-9._-]{3,80}$/', $username) === 1;
        $validAdminEmail = strlen($username) <= 80 && filter_var($username, FILTER_VALIDATE_EMAIL) !== false;

        if ($action === 'register' && !$validUsername) {
            $error = 'El usuario debe tener de 3 a 80 caracteres: letras, números, punto, guion o guion bajo.';
        } elseif ($action === 'login' && !$validUsername && !$validAdminEmail) {
            $error = 'Ingresa un correo válido o un nombre de usuario.';
        } elseif ($action === 'register') {
            $confirmationInput = $_POST['password_confirmation'] ?? '';
            $confirmation = is_string($confirmationInput) ? $confirmationInput : '';
            if (strlen($password) < 12 || strlen($password) > 72) {
                $error = 'La contraseña debe tener entre 12 y 72 caracteres.';
            } elseif (!hash_equals($password, $confirmation)) {
                $error = 'Las contraseñas no coinciden.';
            } else {
                try {
                    $stmt = getDB()->prepare(
                        'INSERT INTO users (username, password_hash) VALUES (:username, :password_hash)'
                    );
                    $stmt->execute([
                        ':username' => $username,
                        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    ]);

                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int) getDB()->lastInsertId();
                    $_SESSION['user_username'] = $username;
                    unset($_SESSION['user_csrf'], $_SESSION['user_login_attempts'], $_SESSION['user_login_blocked_until']);
                    createUserCsrfToken();
                    header('Location: ' . $successUrl);
                    exit;
                } catch (PDOException $e) {
                    if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                        $error = 'Ese nombre de usuario ya está en uso. Elige otro.';
                    } else {
                        error_log('Rutas Seguras user registration: ' . $e->getMessage());
                        $error = 'No se pudo crear la cuenta. Inténtalo de nuevo más tarde.';
                    }
                }
            }
        } elseif (($_SESSION['user_login_blocked_until'] ?? 0) > time()) {
            $error = 'Demasiados intentos. Espera unos minutos antes de volver a intentarlo.';
        } else {
            require_once __DIR__ . '/config/admin_auth.php';
            ensureDefaultAdminAccount();

            $adminStmt = getDB()->prepare(
                'SELECT id, username, password_hash FROM admin_users WHERE username = :username LIMIT 1'
            );
            $adminStmt->execute([':username' => $username]);
            $admin = $adminStmt->fetch();

            if ($admin) {
                if (password_verify($password, $admin['password_hash'])) {
                    unset($_SESSION['user_login_attempts'], $_SESSION['user_login_blocked_until']);
                    session_write_close();

                    require_once __DIR__ . '/config/admin_auth.php';
                    startAdminSession();
                    session_regenerate_id(true);
                    $_SESSION['admin_id'] = (int) $admin['id'];
                    $_SESSION['admin_username'] = $admin['username'];
                    unset($_SESSION['admin_csrf'], $_SESSION['admin_login_attempts'], $_SESSION['admin_login_blocked_until']);
                    createAdminCsrfToken();
                    header('Location: admin/index.php');
                    exit;
                }
            } else {
                $stmt = getDB()->prepare(
                    'SELECT id, username, password_hash FROM users WHERE username = :username LIMIT 1'
                );
                $stmt->execute([':username' => $username]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password_hash'])) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int) $user['id'];
                    $_SESSION['user_username'] = $user['username'];
                    unset($_SESSION['user_csrf'], $_SESSION['user_login_attempts'], $_SESSION['user_login_blocked_until']);
                    createUserCsrfToken();
                    header('Location: ' . $successUrl);
                    exit;
                }
            }

            $error = 'Correo, usuario o contraseña incorrectos.';
            $_SESSION['user_login_attempts'] = ($_SESSION['user_login_attempts'] ?? 0) + 1;
            if ($_SESSION['user_login_attempts'] >= 5) {
                $_SESSION['user_login_blocked_until'] = time() + 300;
                $_SESSION['user_login_attempts'] = 0;
            }
        }
    } else {
        $error = 'La acción solicitada no es válida.';
    }
}

$authenticated = isUserAuthenticated();
$entryRegistration = !$authenticated && $mode === 'register';
$accountPopupRequest = ($_GET['modal_data'] ?? '') === '1';
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && !$authenticated
    && ($_POST['action'] ?? '') === 'login'
    && $error !== ''
) {
    $attemptedUsername = $_POST['username'] ?? '';
    $_SESSION['entry_login_error'] = $error;
    $_SESSION['entry_login_username'] = is_string($attemptedUsername) ? substr(trim($attemptedUsername), 0, 80) : '';
    header('Location: ' . ($next === 'report' ? 'index.php?report=1' : ($next === 'route_proposal' ? 'index.php?propose_route=1' : 'index.php')));
    exit;
}
if (!$authenticated && $_SERVER['REQUEST_METHOD'] === 'GET' && !$entryRegistration && !$accountPopupRequest) {
    header('Location: ' . ($next === 'report' ? 'index.php?report=1' : ($next === 'route_proposal' ? 'index.php?propose_route=1' : 'index.php')));
    exit;
}
if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'GET' && !$accountPopupRequest) {
    header('Location: index.php?account=1');
    exit;
}

$username = $authenticated ? (string) $_SESSION['user_username'] : '';
$reports = [];
if ($authenticated) {
    $stmt = getDB()->prepare(
        'SELECT id, type, location, description, severity, status, latitude, longitude, created_at
         FROM reports WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 100'
    );
    $stmt->execute([':user_id' => (int) $_SESSION['user_id']]);
    $reports = $stmt->fetchAll();

    foreach ($reports as &$report) {
        $updatesStmt = getDB()->prepare(
            'SELECT author_type, message, created_at
             FROM report_updates WHERE report_id = :report_id ORDER BY created_at ASC'
        );
        $updatesStmt->execute([':report_id' => (int) $report['id']]);
        $report['updates'] = $updatesStmt->fetchAll();

        $mediaStmt = getDB()->prepare(
            'SELECT id, file_name, file_path, mime_type, file_size, created_at
             FROM report_media WHERE report_id = :report_id ORDER BY created_at ASC'
        );
        $mediaStmt->execute([':report_id' => (int) $report['id']]);
        $report['media'] = $mediaStmt->fetchAll();
    }
    unset($report);

    $notificationsStmt = getDB()->prepare(
        'SELECT id, report_id, message, is_read, created_at
         FROM report_notifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 20'
    );
    $notificationsStmt->execute([':user_id' => (int) $_SESSION['user_id']]);
    $notifications = $notificationsStmt->fetchAll();
} else {
    $notifications = [];
}

if (($_GET['modal_data'] ?? '') === '1' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!$authenticated) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Inicia sesión para consultar tu historial.']);
    } else {
        echo json_encode(['ok' => true, 'reports' => $reports, 'notifications' => $notifications], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cuenta de usuario | Rutas Seguras</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <?php if ($entryRegistration): ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,600;0,700;1,500&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="assets/css/brand-theme.css?v=<?= filemtime(__DIR__ . '/assets/css/brand-theme.css') ?>">
    <?php if ($entryRegistration): ?>
    <link rel="stylesheet" href="assets/css/login.css?v=<?= filemtime(__DIR__ . '/assets/css/login.css') ?>">
    <?php endif; ?>
    <style>body{font-family:Inter,sans-serif}</style>
</head>
<body class="brand-page min-h-screen bg-slate-100 text-slate-800 flex flex-col<?= $entryRegistration ? ' entry-page' : '' ?>">
    <?php if ($entryRegistration): ?>
    <div class="map-background" aria-hidden="true">
        <svg viewBox="0 0 1440 900" preserveAspectRatio="xMidYMid slice">
            <path class="street-base" d="M-50 180 420 90 780 340 1150 220 1500 310"></path>
            <path class="street-base" d="M120-40 260 450 180 940"></path>
            <path class="street-base" d="M420 90 510 520 390 920"></path>
            <path class="street-base" d="M-30 560 510 520 920 680 1480 590"></path>
            <path class="street-base" d="M780 340 920 680 860 950"></path>
            <path class="street-base" d="M210 260 640 210"></path>
            <path class="street-base" d="M290 690 710 610"></path>
            <path class="safe-route-line" pathLength="1" d="M-50 180 420 90 510 520 390 920"></path>
            <path class="safe-route-pulse" pathLength="1" d="M-50 180 420 90 510 520 390 920"></path>
            <path class="safe-route-line" pathLength="1" d="M-30 560 510 520 780 340"></path>
            <path class="safe-route-pulse" pathLength="1" d="M-30 560 510 520 780 340"></path>
        </svg>
    </div>

    <header class="login-header">
        <a href="index.php" class="login-brand" aria-label="Rutas Seguras, inicio">
            <span class="login-brand-logo" aria-hidden="true"><i class="fa-solid fa-shield-heart"></i></span>
            <span class="login-brand-text">
                <strong>Rutas Seguras</strong>
                <span>CBTis 258 · General Escobedo</span>
            </span>
        </a>
        <span class="secure-badge"><i class="fa-solid fa-lock" aria-hidden="true"></i> Acceso seguro</span>
    </header>
    <?php else: ?>
    <header class="brand-header bg-white border-b border-slate-200">
        <div class="max-w-5xl mx-auto px-4 py-4 flex items-center justify-between gap-3">
            <a href="index.php" class="flex items-center gap-2 font-extrabold text-slate-900">
                <img src="punto_seguro.png" alt="" class="brand-logo">Rutas Seguras
            </a>
            <a href="index.php" class="brand-link text-sm font-semibold"><i class="fa-solid fa-map mr-1"></i>Volver al mapa</a>
        </div>
    </header>
    <?php endif; ?>

    <main class="<?= $entryRegistration ? 'login-main' : ($authenticated ? 'w-full max-w-4xl mx-auto px-4 py-8 flex-1' : 'w-full max-w-2xl mx-auto px-4 py-8 flex-1') ?>">
        <?php if ($entryRegistration): ?>
            <div class="login-layout">
                <section class="hero-section" aria-labelledby="entry-title">
                    <div>
                        <p class="hero-kicker"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> Comunidad CBTis 258</p>
                        <h1 id="entry-title" class="hero-title">Muévete seguro.<br><em>Regresa tranquilo.</em></h1>
                    </div>
                    <div class="hero-interactive-row">
                        <div class="radar-wrapper" aria-label="Ilustración de puntos seguros">
                            <div class="radar-pill"><span class="live-dot"></span> RED DE APOYO</div>
                            <div class="radar-circle" aria-hidden="true">
                                <div class="radar-grid"></div>
                                <div class="radar-sweep"></div>
                                <span class="safe-node node-1"></span>
                                <span class="safe-node node-2"></span>
                                <span class="safe-node node-3"></span>
                                <span class="safe-node node-4"></span>
                            </div>
                        </div>
                        <p class="hero-support-copy"><i class="fa-solid fa-shield-heart" aria-hidden="true"></i> Rutas y puntos de apoyo para tu comunidad.</p>
                    </div>
                </section>

                <section class="login-card" aria-labelledby="register-title">
                    <div class="card-header">
                        <span class="login-card-icon" aria-hidden="true"><i class="fa-solid fa-user-plus"></i></span>
                        <h2 id="register-title">Crear cuenta</h2>
                        <p>Completa tus datos para publicar reportes y consultar su estado.</p>
                    </div>

                    <?php if ($next === 'report'): ?>
                        <div class="brand-notice mb-5 rounded-xl px-4 py-3 text-sm">
                            Para publicar un reporte, primero crea tu cuenta o inicia sesión.
                        </div>
                    <?php elseif ($next === 'route_proposal'): ?>
                        <div class="brand-notice mb-5 rounded-xl px-4 py-3 text-sm">
                            Para proponer una ruta segura, primero crea tu cuenta o inicia sesión.
                        </div>
                    <?php endif; ?>
                    <?php if ($error !== ''): ?>
                        <div role="alert" class="login-error"><?= accountEscape($error) ?></div>
                    <?php endif; ?>

                    <form method="post" class="login-form">
                        <input type="hidden" name="csrf_token" value="<?= accountEscape($csrf) ?>">
                        <input type="hidden" name="action" value="register">
                        <input type="hidden" name="next" value="<?= accountEscape($next) ?>">
                        <div class="form-group">
                            <label for="username">Nombre de usuario</label>
                            <div class="input-wrapper">
                                <input id="username" name="username" autocomplete="username" required minlength="3" maxlength="80"
                                pattern="[a-zA-Z0-9._-]{3,80}" value="<?= accountEscape((string) ($_POST['username'] ?? '')) ?>"
                                    placeholder="Elige un nombre de usuario">
                                <i class="input-icon fa-regular fa-user" aria-hidden="true"></i>
                            </div>
                            <p class="login-help">3 a 80 caracteres: letras, números, punto, guion o guion bajo.</p>
                        </div>
                        <div class="form-group">
                            <label for="password">Contraseña</label>
                            <div class="input-wrapper">
                                <input id="password" name="password" type="password" autocomplete="new-password" required minlength="12" maxlength="72"
                                    placeholder="Crea una contraseña">
                                <i class="input-icon fa-solid fa-lock" aria-hidden="true"></i>
                                <button type="button" class="toggle-password" data-password-toggle="password" aria-label="Mostrar contraseña" aria-pressed="false">
                                    <i class="fa-regular fa-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                            <p class="login-help">Usa entre 12 y 72 caracteres.</p>
                        </div>
                        <div class="form-group">
                            <label for="password_confirmation">Confirmar contraseña</label>
                            <div class="input-wrapper">
                                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required minlength="12" maxlength="72"
                                    placeholder="Vuelve a escribir tu contraseña">
                                <i class="input-icon fa-solid fa-lock" aria-hidden="true"></i>
                                <button type="button" class="toggle-password" data-password-toggle="password_confirmation" aria-label="Mostrar contraseña" aria-pressed="false">
                                    <i class="fa-regular fa-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                        <button type="submit" class="submit-btn">
                            <span>Crear cuenta</span><i class="fa-solid fa-arrow-right arrow-icon" aria-hidden="true"></i>
                        </button>
                    </form>

                    <p class="register-footer">
                        ¿Ya tienes cuenta?
                        <a href="index.php<?= $next === 'report' ? '?report=1' : ($next === 'route_proposal' ? '?propose_route=1' : '') ?>">Inicia sesión</a>
                    </p>
                </section>
            </div>
        <?php else: ?>
        <?php if ($authenticated): ?>
            <section class="brand-panel p-6 sm:p-8">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-7">
                    <div>
                        <p class="text-xs uppercase tracking-wide font-bold text-rose-900">Cuenta de usuario</p>
                        <h1 class="brand-heading text-2xl font-extrabold mt-1">Hola, <?= accountEscape($username) ?></h1>
                        <p class="text-sm text-slate-500 mt-1">Aquí puedes consultar el estado de tus reportes.</p>
                    </div>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= accountEscape($csrf) ?>">
                        <input type="hidden" name="action" value="logout">
                        <button class="brand-secondary-button rounded-xl px-4 py-2 text-sm font-bold" type="submit">
                            <i class="fa-solid fa-right-from-bracket mr-1"></i>Cerrar sesión
                        </button>
                    </form>
                </div>

                <a href="index.php?report=1" class="brand-primary-button inline-flex items-center gap-2 rounded-xl px-4 py-3 text-sm font-bold">
                    <i class="fa-solid fa-plus"></i>Crear un reporte
                </a>

                <h2 class="brand-heading mt-8 mb-3 text-lg font-extrabold">Mi historial</h2>
                <?php if (!$reports): ?>
                    <div class="brand-empty-state p-6 text-center">
                        <i class="fa-solid fa-clipboard-list text-2xl text-slate-300"></i>
                        <p class="font-bold mt-2">Aún no tienes reportes</p>
                        <p class="text-sm text-slate-500 mt-1">Tus reportes aparecerán aquí después de publicarlos.</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($reports as $report): ?>
                            <article class="brand-panel brand-report-card p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <h3 class="font-bold"><?= accountEscape($report['type']) ?></h3>
                                        <p class="text-sm text-slate-600 mt-1"><?= accountEscape($report['location']) ?></p>
                                    </div>
                                    <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-bold <?= $report['status'] === 'resuelto' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' ?>">
                                        <?= $report['status'] === 'resuelto' ? 'Resuelto' : 'En revisión' ?>
                                    </span>
                                </div>
                                <p class="text-sm text-slate-500 mt-2"><?= accountEscape($report['description']) ?></p>
                                <?php if (!empty($report['media'])): ?>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <?php foreach ($report['media'] as $photo): ?>
                                            <?php $photoPath = ltrim((string) $photo['file_path'], '/'); ?>
                                            <button type="button"
                                                class="account-report-photo block h-24 w-24 overflow-hidden rounded-lg border border-slate-200 bg-slate-100"
                                                data-photo="<?= accountEscape($photoPath) ?>" data-name="<?= accountEscape($photo['file_name']) ?>"
                                                title="Ampliar fotografía: <?= accountEscape($photo['file_name']) ?>">
                                                <img src="<?= accountEscape($photoPath) ?>" alt="<?= accountEscape($photo['file_name']) ?>"
                                                    class="h-full w-full object-cover" loading="lazy">
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($report['updates'])): ?>
                                    <div class="mt-3 space-y-2">
                                        <p class="text-xs font-bold text-slate-600">Seguimiento del administrador</p>
                                        <?php foreach ($report['updates'] as $update): ?>
                                            <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                                                <p class="text-[11px] text-slate-400">
                                                    <?= accountEscape($update['author_type'] === 'admin' ? 'Administrador' : 'Usuario') ?>
                                                    · <?= accountEscape($update['created_at']) ?>
                                                </p>
                                                <p class="mt-1 whitespace-pre-line text-xs text-slate-700"><?= accountEscape($update['message']) ?></p>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <p class="text-xs text-slate-400 mt-3">Riesgo <?= accountEscape(ucfirst($report['severity'])) ?> · <?= accountEscape($report['created_at']) ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <p class="text-xs text-slate-500 mt-6"><i class="fa-solid fa-lock mr-1"></i>Este historial solo es visible desde tu cuenta. Tu nombre no aparece en los reportes públicos.</p>
            </section>
        <?php else: ?>
            <section class="brand-panel p-6 sm:p-8">
                <a href="index.php" class="brand-link inline-flex items-center gap-2 text-sm mb-7">
                    <i class="fa-solid fa-arrow-left"></i>Volver al mapa
                </a>
                <img src="punto_seguro.png" alt="Logo Rutas Seguras CBTis 258" class="account-login-logo mb-5">
                <h1 class="brand-heading text-2xl font-extrabold"><?= $mode === 'register' ? 'Crear cuenta de usuario' : 'Iniciar sesión' ?></h1>
                <p class="text-sm text-slate-500 mt-1 mb-6">
                    <?= $mode === 'register' ? 'Regístrate para publicar reportes y consultar su estado.' : 'Inicia sesión para publicar reportes y ver tu historial.' ?>
                </p>

                <?php if ($next === 'report'): ?>
                    <div class="brand-notice mb-5 rounded-xl px-4 py-3 text-sm">
                        Para publicar un reporte, primero inicia sesión o crea una cuenta.
                    </div>
                <?php elseif ($next === 'route_proposal'): ?>
                    <div class="brand-notice mb-5 rounded-xl px-4 py-3 text-sm">
                        Para proponer una ruta segura, primero inicia sesión o crea una cuenta.
                    </div>
                <?php endif; ?>
                <?php if ($error !== ''): ?>
                    <div role="alert" class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= accountEscape($error) ?></div>
                <?php endif; ?>

                <form method="post" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= accountEscape($csrf) ?>">
                    <input type="hidden" name="action" value="<?= $mode === 'register' ? 'register' : 'login' ?>">
                    <input type="hidden" name="next" value="<?= accountEscape($next) ?>">
                    <div>
                        <label for="username" class="block text-xs font-bold uppercase text-slate-600 mb-1"><?= $mode === 'register' ? 'Nombre de usuario' : 'Correo de administrador o nombre de usuario' ?></label>
                        <input id="username" name="username" autocomplete="username" required maxlength="80"
                            <?= $mode === 'register' ? 'minlength="3" pattern="[a-zA-Z0-9._-]{3,80}"' : '' ?>
                            value="<?= accountEscape((string) ($_POST['username'] ?? '')) ?>"
                            class="brand-input w-full rounded-xl border px-3 py-3 text-sm outline-none">
                        <?php if ($mode === 'register'): ?>
                            <p class="text-xs text-slate-400 mt-1">3 a 80 caracteres: letras, números, punto, guion o guion bajo.</p>
                        <?php else: ?>
                            <p class="text-xs text-slate-400 mt-1">Administradores: correo electrónico. Usuarios: nombre de usuario.</p>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label for="password" class="block text-xs font-bold uppercase text-slate-600 mb-1">Contraseña</label>
                        <input id="password" name="password" type="password" autocomplete="<?= $mode === 'register' ? 'new-password' : 'current-password' ?>" required
                            <?= $mode === 'register' ? 'minlength="12" maxlength="72"' : '' ?>
                            class="brand-input w-full rounded-xl border px-3 py-3 text-sm outline-none">
                        <?php if ($mode === 'register'): ?><p class="text-xs text-slate-400 mt-1">Usa entre 12 y 72 caracteres.</p><?php endif; ?>
                    </div>
                    <?php if ($mode === 'register'): ?>
                        <div>
                            <label for="password_confirmation" class="block text-xs font-bold uppercase text-slate-600 mb-1">Confirmar contraseña</label>
                            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required minlength="12" maxlength="72"
                                class="brand-input w-full rounded-xl border px-3 py-3 text-sm outline-none">
                        </div>
                    <?php endif; ?>
                    <button type="submit" class="brand-primary-button w-full rounded-xl py-3 font-bold text-sm">
                        <?= $mode === 'register' ? 'Crear cuenta' : 'Iniciar sesión' ?>
                    </button>
                </form>

                <p class="text-sm text-center text-slate-600 mt-5">
                    <?php if ($mode === 'register'): ?>
                        ¿Ya tienes cuenta? <a class="brand-link font-bold hover:underline" href="account.php<?= $next !== '' ? '?next=' . rawurlencode($next) : '' ?>">Inicia sesión</a>
                    <?php else: ?>
                        ¿Aún no tienes cuenta? <a class="brand-link font-bold hover:underline" href="account.php?mode=register<?= $next !== '' ? '&next=' . rawurlencode($next) : '' ?>">Crear cuenta</a>
                    <?php endif; ?>
                </p>
            </section>
        <?php endif; ?>
        <?php endif; ?>
    </main>
    <?php if ($entryRegistration): ?>
    <footer class="login-footer">CBTis 258 · General Escobedo, Nuevo León</footer>
    <?php endif; ?>
    <div id="account-photo-modal" class="fixed inset-0 z-[1200] hidden items-center justify-center bg-slate-950/90 p-4"
        role="dialog" aria-modal="true" aria-label="Fotografía del reporte"
        onclick="if (event.target === this) closeAccountPhoto()">
        <button type="button" onclick="closeAccountPhoto()" aria-label="Cerrar fotografía"
            class="absolute right-4 top-4 rounded-full bg-white px-4 py-2 text-sm font-bold text-slate-900 shadow-lg">
            <i class="fa-solid fa-xmark mr-1"></i>Cerrar
        </button>
        <img id="account-photo-modal-image" src="" alt="Fotografía del reporte"
            class="max-h-[86vh] max-w-[90vw] rounded-xl object-contain shadow-2xl">
    </div>
    <script>
        const accountPhotoModal = document.getElementById('account-photo-modal');
        const accountPhotoImage = document.getElementById('account-photo-modal-image');

        function openAccountPhoto(path, name) {
            accountPhotoImage.src = path;
            accountPhotoImage.alt = name || 'Fotografía del reporte';
            accountPhotoModal.classList.remove('hidden');
            accountPhotoModal.classList.add('flex');
            document.body.style.overflow = 'hidden';
            accountPhotoModal.querySelector('button').focus();
        }

        function closeAccountPhoto() {
            accountPhotoModal.classList.add('hidden');
            accountPhotoModal.classList.remove('flex');
            accountPhotoImage.removeAttribute('src');
            document.body.style.overflow = '';
        }

        document.querySelectorAll('.account-report-photo').forEach((button) => {
            button.addEventListener('click', () => openAccountPhoto(button.dataset.photo, button.dataset.name));
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !accountPhotoModal.classList.contains('hidden')) {
                closeAccountPhoto();
            }
        });
    </script>
    <?php if ($entryRegistration): ?>
    <script>
        document.querySelectorAll('[data-password-toggle]').forEach((toggle) => {
            const password = document.getElementById(toggle.dataset.passwordToggle);
            const icon = toggle.querySelector('i');
            if (!password || !icon) return;

            toggle.addEventListener('click', () => {
                const isVisible = password.type === 'password';
                password.type = isVisible ? 'text' : 'password';
                toggle.setAttribute('aria-label', isVisible ? 'Ocultar contraseña' : 'Mostrar contraseña');
                toggle.setAttribute('aria-pressed', String(isVisible));
                icon.className = isVisible ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
            });
        });
    </script>
    <?php endif; ?>
</body>
</html>
