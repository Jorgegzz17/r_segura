<?php
/**
 * API REST sencilla para los reportes de la comunidad.
 *
 *  GET    api/reports.php?action=list[&status=activo][&type=...][&q=texto]
 *  POST   api/reports.php?action=create   { type, location, description, latitude, longitude, severity }
 *  POST   api/reports.php?action=like     { id }
 *  POST   api/reports.php?action=resolve  { id }
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/municipality.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($action) {
        case 'list':
            if ($method !== 'GET') {
                respond(405, ['ok' => false, 'error' => 'Método no permitido']);
            }
            listReports();
            break;

        case 'create':
            if ($method !== 'POST') {
                respond(405, ['ok' => false, 'error' => 'Método no permitido']);
            }
            require_once __DIR__ . '/../config/user_auth.php';
            requireUserApiAccess();
            createReport();
            break;

        case 'like':
            if ($method !== 'POST') {
                respond(405, ['ok' => false, 'error' => 'Método no permitido']);
            }
            require_once __DIR__ . '/../config/user_auth.php';
            requireUserApiAccess();
            likeReport();
            break;

        case 'resolve':
            if ($method !== 'POST') {
                respond(405, ['ok' => false, 'error' => 'Método no permitido']);
            }
            require_once __DIR__ . '/../config/admin_auth.php';
            requireAdminApiAccess();
            resolveReport();
            break;

        case 'update':
            if ($method !== 'POST') {
                respond(405, ['ok' => false, 'error' => 'Método no permitido']);
            }
            require_once __DIR__ . '/../config/user_auth.php';
            requireUserApiAccess();
            updateReport();
            break;

        case 'delete':
            if ($method !== 'POST') {
                respond(405, ['ok' => false, 'error' => 'Método no permitido']);
            }
            require_once __DIR__ . '/../config/user_auth.php';
            requireUserApiAccess();
            deleteReport();
            break;

        case 'upload-photo':
            if ($method !== 'POST') {
                respond(405, ['ok' => false, 'error' => 'Método no permitido']);
            }
            require_once __DIR__ . '/../config/user_auth.php';
            requireUserApiAccess();
            uploadReportPhoto();
            break;

        case 'add-update':
            if ($method !== 'POST') {
                respond(405, ['ok' => false, 'error' => 'Método no permitido']);
            }
            require_once __DIR__ . '/../config/admin_auth.php';
            requireAdminApiAccess();
            addReportUpdate();
            break;

        case 'stats':
            if ($method !== 'GET') {
                respond(405, ['ok' => false, 'error' => 'Método no permitido']);
            }
            getStats();
            break;

        default:
            respond(400, ['ok' => false, 'error' => 'Acción no válida']);
    }
} catch (Throwable $e) {
    error_log('Rutas Seguras API: ' . $e->getMessage());
    respond(500, ['ok' => false, 'error' => 'Error interno del servidor']);
}

/* ============================================================
 *  Acciones
 * ========================================================== */

function listReports(): void
{
    require_once __DIR__ . '/../config/user_auth.php';
    startUserSession();
    $db     = getDB();
    $where  = [];
    $params = [':like_user_id' => isUserAuthenticated() ? (int) $_SESSION['user_id'] : 0];

    $status = $_GET['status'] ?? '';
    if (is_string($status) && in_array($status, ['activo', 'resuelto'], true)) {
        $where[]           = 'r.status = :status';
        $params[':status'] = $status;
    }

    $type = $_GET['type'] ?? '';
    if (is_string($type) && $type !== '') {
        $where[]          = 'r.type = :type';
        $params[':type']  = $type;
    }

    $query = $_GET['q'] ?? '';
    if (is_string($query) && trim($query) !== '') {
        $where[]       = '(r.location LIKE :q OR r.description LIKE :q)';
        $params[':q']  = '%' . textSlice(trim($query), 100) . '%';
    }

    $sql = 'SELECT r.id, r.type, r.location, r.description, r.latitude, r.longitude,
                   r.severity, r.status, r.likes, r.created_at,
                   CASE WHEN rl.user_id IS NULL THEN 0 ELSE 1 END AS liked_by_me
            FROM reports r
            LEFT JOIN report_likes rl
                ON rl.report_id = r.id AND rl.user_id = :like_user_id';

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY r.created_at DESC LIMIT 100';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll();

    foreach ($reports as &$report) {
        $mediaStmt = $db->prepare(
            'SELECT file_name, file_path, mime_type
             FROM report_media WHERE report_id = :report_id ORDER BY created_at ASC LIMIT 10'
        );
        $mediaStmt->execute([':report_id' => (int) $report['id']]);
        $report['media'] = $mediaStmt->fetchAll();

        $updatesStmt = $db->prepare(
            'SELECT author_type, message, created_at
             FROM report_updates WHERE report_id = :report_id ORDER BY created_at ASC'
        );
        $updatesStmt->execute([':report_id' => (int) $report['id']]);
        $report['updates'] = $updatesStmt->fetchAll();
    }
    unset($report);

    respond(200, ['ok' => true, 'data' => $reports]);
}

function createReport(): void
{
    $body = getJsonBody();

    $typeValue = $body['type'] ?? null;
    $locationValue = $body['location'] ?? null;
    $descriptionValue = $body['description'] ?? null;
    $type = is_string($typeValue) ? trim($typeValue) : '';
    $location = is_string($locationValue) ? trim($locationValue) : '';
    $description = is_string($descriptionValue) ? trim($descriptionValue) : '';
    $severityValue = $body['severity'] ?? 'media';
    $requestedSeverity = is_string($severityValue) ? $severityValue : '';
    $severity = $type === 'Acoso / Robo' ? 'alta' : $requestedSeverity;
    $lat = filter_var($body['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $lng = filter_var($body['longitude'] ?? null, FILTER_VALIDATE_FLOAT);

    $allowedTypes = [
        'Luz Fundida / Zona Oscura',
        'Presencia Escasa de Vía',
        'Zona Solitaria / Sospechosa',
        'Tráfico / Banqueta Obstruida',
        'Acoso / Robo',
        'Otro',
    ];

    $errors = [];
    if (!in_array($type, $allowedTypes, true))            $errors[] = 'Tipo de reporte no válido';
    if (textLength($location) < 3 || textLength($location) > 255) $errors[] = 'La ubicación debe tener entre 3 y 255 caracteres';
    if (textLength($description) < 5 || textLength($description) > 1000) $errors[] = 'La descripción debe tener entre 5 y 1000 caracteres';
    if ($lat === false || $lat < -90  || $lat > 90)       $errors[] = 'Latitud no válida';
    if ($lng === false || $lng < -180 || $lng > 180)      $errors[] = 'Longitud no válida';
    if (!in_array($severity, ['baja', 'media', 'alta'], true)) $errors[] = 'Severidad no válida';

    if ($lat !== false && $lng !== false && !isInsideEscobedo((float) $lat, (float) $lng)) {
        $errors[] = 'La ubicación debe estar dentro del municipio de General Escobedo';
    }

    if ($errors) {
        respond(422, ['ok' => false, 'error' => implode('. ', $errors)]);
    }

    $db   = getDB();
    $stmt = $db->prepare(
        'INSERT INTO reports (user_id, type, location, description, latitude, longitude, severity)
         VALUES (:user_id, :type, :location, :description, :lat, :lng, :severity)'
    );
    $stmt->execute([
        ':user_id'     => (int) $_SESSION['user_id'],
        ':type'        => $type,
        ':location'    => $location,
        ':description' => $description,
        ':lat'         => $lat,
        ':lng'         => $lng,
        ':severity'    => $severity,
    ]);

    $id = (int) $db->lastInsertId();
    $stmt = $db->prepare(
        'SELECT id, type, location, description, latitude, longitude, severity, status, likes, created_at
         FROM reports WHERE id = :id'
    );
    $stmt->execute([':id' => $id]);

    respond(201, ['ok' => true, 'data' => $stmt->fetch()]);
}

function likeReport(): void
{
    $body = getJsonBody();
    $id   = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);

    if (!$id) {
        respond(422, ['ok' => false, 'error' => 'ID no válido']);
    }

    $db = getDB();
    $exists = $db->prepare('SELECT id FROM reports WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetchColumn()) {
        respond(404, ['ok' => false, 'error' => 'Reporte no encontrado']);
    }

    try {
        $db->beginTransaction();
        $like = $db->prepare(
            'INSERT INTO report_likes (report_id, user_id) VALUES (:report_id, :user_id)'
        );
        $like->execute([
            ':report_id' => $id,
            ':user_id' => (int) $_SESSION['user_id'],
        ]);
        $increment = $db->prepare('UPDATE reports SET likes = likes + 1 WHERE id = :id');
        $increment->execute([':id' => $id]);
        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            respond(409, ['ok' => false, 'error' => 'Ya diste apoyo a este reporte']);
        }
        throw $e;
    }

    $stmt = $db->prepare('SELECT likes FROM reports WHERE id = :id');
    $stmt->execute([':id' => $id]);

    respond(200, ['ok' => true, 'likes' => (int) $stmt->fetchColumn(), 'liked_by_me' => true]);
}

function resolveReport(): void
{
    $body = getJsonBody();
    $id   = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);

    if (!$id) {
        respond(422, ['ok' => false, 'error' => 'ID no válido']);
    }

    $db = getDB();
    $stmt = $db->prepare(
        "UPDATE reports SET status = IF(status = 'activo', 'resuelto', 'activo') WHERE id = :id"
    );
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) {
        respond(404, ['ok' => false, 'error' => 'Reporte no encontrado']);
    }

    $stmt = $db->prepare('SELECT status FROM reports WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $status = (string) $stmt->fetchColumn();
    if ($status === 'resuelto') {
        $ownerStmt = $db->prepare('SELECT user_id FROM reports WHERE id = :id');
        $ownerStmt->execute([':id' => $id]);
        $ownerId = (int) $ownerStmt->fetchColumn();
        if ($ownerId > 0) {
            $db->prepare(
                'INSERT INTO report_notifications (user_id, report_id, message)
                 VALUES (:user_id, :report_id, :message)'
            )->execute([
                ':user_id' => $ownerId,
                ':report_id' => $id,
                ':message' => 'Tu reporte fue marcado como resuelto.',
            ]);
        }
    }
    respond(200, ['ok' => true, 'status' => $status]);
}

function updateReport(): void
{
    $body = getJsonBody();
    $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) respond(422, ['ok' => false, 'error' => 'ID no válido']);

    $db = getDB();
    $owner = $db->prepare('SELECT status, user_id FROM reports WHERE id = :id');
    $owner->execute([':id' => $id]);
    $report = $owner->fetch();
    if (!$report) respond(404, ['ok' => false, 'error' => 'Reporte no encontrado']);
    if ((int) $report['user_id'] !== (int) $_SESSION['user_id']) respond(403, ['ok' => false, 'error' => 'Solo puedes editar tus propios reportes']);
    if ($report['status'] === 'resuelto') respond(422, ['ok' => false, 'error' => 'No puedes editar un reporte resuelto']);

    $location = is_string($body['location'] ?? null) ? trim($body['location']) : '';
    $description = is_string($body['description'] ?? null) ? trim($body['description']) : '';
    $type = is_string($body['type'] ?? null) ? trim($body['type']) : '';
    $requestedSeverity = is_string($body['severity'] ?? null) ? $body['severity'] : '';
    $severity = $type === 'Acoso / Robo' ? 'alta' : $requestedSeverity;
    $lat = filter_var($body['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $lng = filter_var($body['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $allowedTypes = ['Luz Fundida / Zona Oscura', 'Presencia Escasa de Vía', 'Zona Solitaria / Sospechosa', 'Tráfico / Banqueta Obstruida', 'Acoso / Robo', 'Otro'];
    $errors = [];
    if (!in_array($type, $allowedTypes, true)) $errors[] = 'Tipo de reporte no válido';
    if (textLength($location) < 3 || textLength($location) > 255) $errors[] = 'Ubicación no válida';
    if (textLength($description) < 5 || textLength($description) > 1000) $errors[] = 'Descripción no válida';
    if ($lat === false || $lat < -90 || $lat > 90) $errors[] = 'Latitud no válida';
    if ($lng === false || $lng < -180 || $lng > 180) $errors[] = 'Longitud no válida';
    if (!in_array($severity, ['baja', 'media', 'alta'], true)) $errors[] = 'Severidad no válida';
    if ($lat !== false && $lng !== false && !isInsideEscobedo((float) $lat, (float) $lng)) $errors[] = 'La ubicación debe estar dentro del municipio de General Escobedo';
    if ($errors) respond(422, ['ok' => false, 'error' => implode('. ', $errors)]);

    $stmt = $db->prepare(
        'UPDATE reports SET type = :type, location = :location, description = :description,
         latitude = :lat, longitude = :lng, severity = :severity
         WHERE id = :id AND user_id = :user_id'
    );
    $stmt->execute([
        ':type' => $type, ':location' => $location, ':description' => $description,
        ':lat' => $lat, ':lng' => $lng, ':severity' => $severity,
        ':id' => $id, ':user_id' => (int) $_SESSION['user_id'],
    ]);
    $db->prepare(
        "INSERT INTO report_updates (report_id, author_type, author_id, message)
         VALUES (:report_id, 'user', :author_id, 'El usuario actualizó la información del reporte.')"
    )->execute([':report_id' => $id, ':author_id' => (int) $_SESSION['user_id']]);
    respond(200, ['ok' => true, 'id' => $id]);
}

function deleteReport(): void
{
    $body = getJsonBody();
    $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) respond(422, ['ok' => false, 'error' => 'ID no válido']);

    $db = getDB();
    $media = $db->prepare('SELECT file_path FROM report_media WHERE report_id = :id');
    $media->execute([':id' => $id]);
    $files = $media->fetchAll();
    $stmt = $db->prepare('DELETE FROM reports WHERE id = :id AND user_id = :user_id');
    $stmt->execute([':id' => $id, ':user_id' => (int) $_SESSION['user_id']]);
    if ($stmt->rowCount() === 0) respond(404, ['ok' => false, 'error' => 'Reporte no encontrado o no te pertenece']);
    foreach ($files as $file) {
        $fullPath = dirname(__DIR__) . '/' . ltrim((string) $file['file_path'], '/');
        if (is_file($fullPath)) unlink($fullPath);
    }
    respond(200, ['ok' => true]);
}

function uploadReportPhoto(): void
{
    $reportId = filter_var($_POST['report_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$reportId) respond(422, ['ok' => false, 'error' => 'ID no válido']);
    if (empty($_FILES['photo']) || !is_uploaded_file($_FILES['photo']['tmp_name'])) respond(422, ['ok' => false, 'error' => 'Selecciona una fotografía válida']);
    $file = $_FILES['photo'];
    if ((int) $file['error'] !== UPLOAD_ERR_OK) respond(422, ['ok' => false, 'error' => 'No se pudo cargar la fotografía']);
    if ((int) $file['size'] > 5 * 1024 * 1024) respond(422, ['ok' => false, 'error' => 'La fotografía no debe exceder 5 MB']);

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $image = @getimagesize($file['tmp_name']);
    if (!isset($allowed[$mime]) || $image === false) respond(422, ['ok' => false, 'error' => 'Formato no válido; usa JPG, PNG o WebP']);

    $db = getDB();
    $owner = $db->prepare('SELECT user_id FROM reports WHERE id = :id');
    $owner->execute([':id' => $reportId]);
    $ownerId = (int) $owner->fetchColumn();
    if ($ownerId !== (int) $_SESSION['user_id']) respond(403, ['ok' => false, 'error' => 'Solo puedes agregar fotos a tus propios reportes']);

    $directory = dirname(__DIR__) . '/uploads/reports';
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) respond(500, ['ok' => false, 'error' => 'No se pudo preparar el almacenamiento']);
    $storedName = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $target = $directory . '/' . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $target)) respond(500, ['ok' => false, 'error' => 'No se pudo guardar la fotografía']);

    $relativePath = 'uploads/reports/' . $storedName;
    $db->prepare(
        'INSERT INTO report_media (report_id, file_name, file_path, mime_type, file_size)
         VALUES (:report_id, :file_name, :file_path, :mime_type, :file_size)'
    )->execute([
        ':report_id' => $reportId,
        ':file_name' => basename($file['name']),
        ':file_path' => $relativePath,
        ':mime_type' => $mime,
        ':file_size' => (int) $file['size'],
    ]);
    respond(201, ['ok' => true, 'path' => $relativePath]);
}

function addReportUpdate(): void
{
    $body = getJsonBody();
    $reportId = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
    $message = is_string($body['message'] ?? null) ? trim($body['message']) : '';
    if (!$reportId) respond(422, ['ok' => false, 'error' => 'ID no válido']);
    if (textLength($message) < 2 || textLength($message) > 1000) respond(422, ['ok' => false, 'error' => 'El seguimiento debe tener entre 2 y 1000 caracteres']);
    $db = getDB();
    $exists = $db->prepare('SELECT id, user_id FROM reports WHERE id = :id');
    $exists->execute([':id' => $reportId]);
    $report = $exists->fetch();
    if (!$report) respond(404, ['ok' => false, 'error' => 'Reporte no encontrado']);
    $db->prepare(
        "INSERT INTO report_updates (report_id, author_type, author_id, message)
         VALUES (:report_id, 'admin', :author_id, :message)"
    )->execute([':report_id' => $reportId, ':author_id' => (int) $_SESSION['admin_id'], ':message' => $message]);
    if ((int) $report['user_id'] > 0) {
        $db->prepare(
            'INSERT INTO report_notifications (user_id, report_id, message)
             VALUES (:user_id, :report_id, :message)'
        )->execute([':user_id' => (int) $report['user_id'], ':report_id' => $reportId, ':message' => 'El administrador añadió seguimiento a tu reporte.']);
    }
    respond(201, ['ok' => true]);
}

function getStats(): void
{
    $db = getDB();

    respond(200, [
        'ok'   => true,
        'data' => [
            'total'     => (int) $db->query('SELECT COUNT(*) FROM reports')->fetchColumn(),
            'activos'   => (int) $db->query("SELECT COUNT(*) FROM reports WHERE status = 'activo'")->fetchColumn(),
            'resueltos' => (int) $db->query("SELECT COUNT(*) FROM reports WHERE status = 'resuelto'")->fetchColumn(),
            'altas'     => (int) $db->query("SELECT COUNT(*) FROM reports WHERE severity = 'alta' AND status = 'activo'")->fetchColumn(),
        ],
    ]);
}

/* ============================================================
 *  Utilidades
 * ========================================================== */

function getJsonBody(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== 0) {
        respond(415, ['ok' => false, 'error' => 'El contenido debe enviarse como JSON']);
    }

    $raw  = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);

    if (!is_array($data)) {
        respond(400, ['ok' => false, 'error' => 'JSON no válido']);
    }

    return $data;
}

function textLength(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    $count = preg_match_all('/./us', $value);
    return $count === false ? strlen($value) : $count;
}

function textSlice(string $value, int $length): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $length, 'UTF-8');
    }

    if (preg_match('/^.{0,' . $length . '}/us', $value, $matches) === 1) {
        return $matches[0];
    }

    return substr($value, 0, $length);
}

function respond(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
