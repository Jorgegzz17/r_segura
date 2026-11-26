<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/municipality.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($action === 'list') {
        if ($method !== 'GET') {
            respond(405, ['ok' => false, 'error' => 'Método no permitido']);
        }
        listLocations();
    }

    if ($method !== 'POST') {
        respond(405, ['ok' => false, 'error' => 'Método no permitido']);
    }

    require_once __DIR__ . '/../config/admin_auth.php';
    requireAdminApiAccess();
    if ($action === 'campus-upload-photo') {
        uploadCampusPhoto();
    }
    if ($action === 'safe-point-upload-photo') {
        uploadSafePointPhoto();
    }
    $body = getJsonBody();

    switch ($action) {
        case 'campus-save':
            saveCampusLocation($body);
            break;
        case 'safe-point-create':
            createSafePoint($body);
            break;
        case 'safe-point-delete':
            deleteSafePoint($body);
            break;
        case 'safe-point-remove-photo':
            removeSafePointPhoto($body);
            break;
        case 'route-create':
            createSafeRoute($body);
            break;
        case 'route-delete':
            deleteSafeRoute($body);
            break;
        default:
            respond(400, ['ok' => false, 'error' => 'Acción no válida']);
    }
} catch (Throwable $e) {
    error_log('Rutas Seguras locations API: ' . $e->getMessage());
    respond(500, ['ok' => false, 'error' => 'Error interno del servidor']);
}

function listLocations(): void
{
    $db = getDB();
    $campus = $db->query(
        'SELECT name, address, description, latitude, longitude, image_path, updated_at
         FROM campus_location WHERE id = 1'
    )->fetch();
    $safePoints = $db->query(
        'SELECT id, name, address, description, latitude, longitude, image_path, created_at
         FROM safe_points ORDER BY name'
    )->fetchAll();
    $routes = $db->query(
        'SELECT id, name, description, color, created_at FROM safe_routes ORDER BY name'
    )->fetchAll();
    $routePoints = $db->query(
        'SELECT route_id, latitude, longitude FROM safe_route_points ORDER BY route_id, sequence_no'
    )->fetchAll();
    $pointsByRoute = [];
    foreach ($routePoints as $point) {
        $routeId = (int) $point['route_id'];
        $pointsByRoute[$routeId][] = [
            'latitude' => (float) $point['latitude'],
            'longitude' => (float) $point['longitude'],
        ];
    }
    foreach ($routes as &$route) {
        $route['points'] = $pointsByRoute[(int) $route['id']] ?? [];
    }
    unset($route);

    respond(200, [
        'ok' => true,
        'data' => [
            'campus' => $campus ?: null,
            'safePoints' => $safePoints,
            'routes' => $routes,
        ],
    ]);
}

function saveCampusLocation(array $body): void
{
    $location = validateLocation($body);
    $db = getDB();
    $stmt = $db->prepare(
        'INSERT INTO campus_location (id, name, address, description, latitude, longitude)
         VALUES (1, :name, :address, :description, :latitude, :longitude)
         ON DUPLICATE KEY UPDATE name = VALUES(name), address = VALUES(address),
             description = VALUES(description), latitude = VALUES(latitude), longitude = VALUES(longitude)'
    );
    $stmt->execute($location);

    respond(200, ['ok' => true]);
}

function uploadCampusPhoto(): void
{
    if (
        empty($_FILES['photo'])
        || !isset($_FILES['photo']['tmp_name'])
        || !is_uploaded_file($_FILES['photo']['tmp_name'])
    ) {
        respond(422, ['ok' => false, 'error' => 'Selecciona una fotografía válida']);
    }

    $file = $_FILES['photo'];
    if ((int) $file['error'] !== UPLOAD_ERR_OK) respond(422, ['ok' => false, 'error' => 'No se pudo cargar la fotografía']);
    if ((int) $file['size'] > 5 * 1024 * 1024) respond(422, ['ok' => false, 'error' => 'La fotografía no debe exceder 5 MB']);

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === false) {
        respond(422, ['ok' => false, 'error' => 'Formato no válido; usa JPG, PNG o WebP']);
    }

    $db = getDB();
    $oldImagePath = $db->query('SELECT image_path FROM campus_location WHERE id = 1')->fetchColumn();
    if ($oldImagePath === false) respond(404, ['ok' => false, 'error' => 'Primero guarda la ubicación principal del plantel']);

    $directory = dirname(__DIR__) . '/uploads/campus';
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
        respond(500, ['ok' => false, 'error' => 'No se pudo preparar el almacenamiento de fotografías']);
    }

    $storedName = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $target = $directory . '/' . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        respond(500, ['ok' => false, 'error' => 'No se pudo guardar la fotografía']);
    }

    $imagePath = 'uploads/campus/' . $storedName;
    try {
        $update = $db->prepare('UPDATE campus_location SET image_path = :image_path WHERE id = 1');
        $update->execute([':image_path' => $imagePath]);
    } catch (Throwable $error) {
        if (is_file($target)) unlink($target);
        throw $error;
    }

    deleteCampusImageFile(is_string($oldImagePath) ? $oldImagePath : null);
    respond(200, ['ok' => true, 'image_path' => $imagePath]);
}

function deleteCampusImageFile(?string $imagePath): void
{
    if ($imagePath === null || $imagePath === '') return;
    $filename = basename($imagePath);
    $file = dirname(__DIR__) . '/uploads/campus/' . $filename;
    if (is_file($file) && !unlink($file)) {
        error_log('Rutas Seguras: no se pudo eliminar una fotografía del plantel.');
    }
}

function createSafePoint(array $body): void
{
    $location = validateLocation($body);
    $db = getDB();
    $stmt = $db->prepare(
        'INSERT INTO safe_points (name, address, description, latitude, longitude, image_path)
         VALUES (:name, :address, :description, :latitude, :longitude, NULL)'
    );
    $stmt->execute($location);

    respond(201, ['ok' => true, 'id' => (int) $db->lastInsertId()]);
}

function deleteSafePoint(array $body): void
{
    $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) {
        respond(422, ['ok' => false, 'error' => 'ID no válido']);
    }

    $db = getDB();
    $imageStmt = $db->prepare('SELECT image_path FROM safe_points WHERE id = :id');
    $imageStmt->execute([':id' => $id]);
    $imagePath = $imageStmt->fetchColumn();
    if ($imagePath === false) {
        respond(404, ['ok' => false, 'error' => 'Punto seguro no encontrado']);
    }

    $stmt = $db->prepare('DELETE FROM safe_points WHERE id = :id');
    $stmt->execute([':id' => $id]);
    deleteSafePointImageFile(is_string($imagePath) ? $imagePath : null);

    respond(200, ['ok' => true]);
}

function uploadSafePointPhoto(): void
{
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) respond(422, ['ok' => false, 'error' => 'ID no válido']);
    if (
        empty($_FILES['photo'])
        || !isset($_FILES['photo']['tmp_name'])
        || !is_uploaded_file($_FILES['photo']['tmp_name'])
    ) {
        respond(422, ['ok' => false, 'error' => 'Selecciona una fotografía válida']);
    }

    $file = $_FILES['photo'];
    if ((int) $file['error'] !== UPLOAD_ERR_OK) respond(422, ['ok' => false, 'error' => 'No se pudo cargar la fotografía']);
    if ((int) $file['size'] > 5 * 1024 * 1024) respond(422, ['ok' => false, 'error' => 'La fotografía no debe exceder 5 MB']);

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === false) {
        respond(422, ['ok' => false, 'error' => 'Formato no válido; usa JPG, PNG o WebP']);
    }

    $db = getDB();
    $pointStmt = $db->prepare('SELECT image_path FROM safe_points WHERE id = :id');
    $pointStmt->execute([':id' => $id]);
    $oldImagePath = $pointStmt->fetchColumn();
    if ($oldImagePath === false) respond(404, ['ok' => false, 'error' => 'Punto seguro no encontrado']);

    $directory = dirname(__DIR__) . '/uploads/safe-points';
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
        respond(500, ['ok' => false, 'error' => 'No se pudo preparar el almacenamiento de fotografías']);
    }

    $storedName = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $target = $directory . '/' . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        respond(500, ['ok' => false, 'error' => 'No se pudo guardar la fotografía']);
    }

    $imagePath = 'uploads/safe-points/' . $storedName;
    try {
        $update = $db->prepare('UPDATE safe_points SET image_path = :image_path WHERE id = :id');
        $update->execute([':image_path' => $imagePath, ':id' => $id]);
    } catch (Throwable $error) {
        if (is_file($target)) unlink($target);
        throw $error;
    }

    deleteSafePointImageFile(is_string($oldImagePath) ? $oldImagePath : null);
    respond(200, ['ok' => true, 'image_path' => $imagePath]);
}

function removeSafePointPhoto(array $body): void
{
    $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) respond(422, ['ok' => false, 'error' => 'ID no válido']);

    $db = getDB();
    $stmt = $db->prepare('SELECT image_path FROM safe_points WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $imagePath = $stmt->fetchColumn();
    if ($imagePath === false) respond(404, ['ok' => false, 'error' => 'Punto seguro no encontrado']);
    if (!is_string($imagePath) || $imagePath === '') {
        respond(404, ['ok' => false, 'error' => 'El punto seguro no tiene fotografía']);
    }

    $db->prepare('UPDATE safe_points SET image_path = NULL WHERE id = :id')->execute([':id' => $id]);
    deleteSafePointImageFile($imagePath);
    respond(200, ['ok' => true]);
}

function deleteSafePointImageFile(?string $imagePath): void
{
    if ($imagePath === null || $imagePath === '') return;
    $filename = basename($imagePath);
    $file = dirname(__DIR__) . '/uploads/safe-points/' . $filename;
    if (is_file($file) && !unlink($file)) {
        error_log('Rutas Seguras: no se pudo eliminar una fotografía de punto seguro.');
    }
}

function createSafeRoute(array $body): void
{
    $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
    $description = is_string($body['description'] ?? null) ? trim($body['description']) : '';
    $color = is_string($body['color'] ?? null) ? trim($body['color']) : '#059669';
    $points = $body['points'] ?? null;

    $errors = [];
    if (textLength($name) < 2 || textLength($name) > 120) $errors[] = 'El nombre debe tener entre 2 y 120 caracteres';
    if (textLength($description) > 1000) $errors[] = 'La descripción no puede superar 1000 caracteres';
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $errors[] = 'Color no válido';
    if (!is_array($points) || count($points) < 1 || count($points) > 199) $errors[] = 'Traza entre 1 y 199 puntos antes de guardar';

    $validatedPoints = [];
    if (is_array($points)) {
        foreach ($points as $point) {
            if (!is_array($point)) {
                $errors[] = 'La ruta contiene un punto no válido';
                break;
            }
            $latitude = filter_var($point['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
            $longitude = filter_var($point['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($latitude === false || $latitude < -90 || $latitude > 90 || $longitude === false || $longitude < -180 || $longitude > 180) {
                $errors[] = 'La ruta contiene coordenadas no válidas';
                break;
            }
            if (!isInsideEscobedo((float) $latitude, (float) $longitude)) {
                $errors[] = 'La ruta contiene puntos fuera de General Escobedo';
                break;
            }
            $validatedPoints[] = [(float) $latitude, (float) $longitude];
        }
    }

    if ($errors) {
        respond(422, ['ok' => false, 'error' => implode('. ', array_unique($errors))]);
    }

    $db = getDB();
    $campus = $db->query('SELECT latitude, longitude FROM campus_location WHERE id = 1')->fetch();
    if (!$campus) {
        respond(422, ['ok' => false, 'error' => 'Primero guarda la ubicación principal del plantel']);
    }
    if (!isInsideEscobedo((float) $campus['latitude'], (float) $campus['longitude'])) {
        respond(422, ['ok' => false, 'error' => 'La ubicación principal del plantel está fuera de General Escobedo']);
    }
    $validatedPoints[] = [(float) $campus['latitude'], (float) $campus['longitude']];

    $db->beginTransaction();
    try {
        $stmt = $db->prepare('INSERT INTO safe_routes (name, description, color) VALUES (:name, :description, :color)');
        $stmt->execute([':name' => $name, ':description' => $description, ':color' => $color]);
        $routeId = (int) $db->lastInsertId();
        $pointStmt = $db->prepare(
            'INSERT INTO safe_route_points (route_id, sequence_no, latitude, longitude)
             VALUES (:route_id, :sequence_no, :latitude, :longitude)'
        );
        foreach ($validatedPoints as $index => [$latitude, $longitude]) {
            $pointStmt->execute([
                ':route_id' => $routeId,
                ':sequence_no' => $index,
                ':latitude' => $latitude,
                ':longitude' => $longitude,
            ]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    respond(201, ['ok' => true, 'id' => $routeId]);
}

function deleteSafeRoute(array $body): void
{
    $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) {
        respond(422, ['ok' => false, 'error' => 'ID no válido']);
    }

    $stmt = getDB()->prepare('DELETE FROM safe_routes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() === 0) {
        respond(404, ['ok' => false, 'error' => 'Ruta segura no encontrada']);
    }

    respond(200, ['ok' => true]);
}

function validateLocation(array $body): array
{
    $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
    $address = is_string($body['address'] ?? null) ? trim($body['address']) : '';
    $description = is_string($body['description'] ?? null) ? trim($body['description']) : '';
    $latitude = filter_var($body['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $longitude = filter_var($body['longitude'] ?? null, FILTER_VALIDATE_FLOAT);

    $errors = [];
    if (textLength($name) < 2 || textLength($name) > 120) $errors[] = 'El nombre debe tener entre 2 y 120 caracteres';
    if (textLength($address) < 3 || textLength($address) > 255) $errors[] = 'La dirección debe tener entre 3 y 255 caracteres';
    if (textLength($description) > 1000) $errors[] = 'La descripción no puede superar 1000 caracteres';
    if ($latitude === false || $latitude < -90 || $latitude > 90) $errors[] = 'Latitud no válida';
    if ($longitude === false || $longitude < -180 || $longitude > 180) $errors[] = 'Longitud no válida';
    if (
        $latitude !== false
        && $longitude !== false
        && $latitude >= -90 && $latitude <= 90
        && $longitude >= -180 && $longitude <= 180
        && !isInsideEscobedo((float) $latitude, (float) $longitude)
    ) {
        $errors[] = 'La ubicación debe estar dentro de General Escobedo';
    }

    if ($errors) {
        respond(422, ['ok' => false, 'error' => implode('. ', $errors)]);
    }

    return [
        ':name' => $name,
        ':address' => $address,
        ':description' => $description,
        ':latitude' => $latitude,
        ':longitude' => $longitude,
    ];
}

function getJsonBody(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== 0) {
        respond(415, ['ok' => false, 'error' => 'El contenido debe enviarse como JSON']);
    }

    $data = json_decode(file_get_contents('php://input') ?: '', true);
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

function respond(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}