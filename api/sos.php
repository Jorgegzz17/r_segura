<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/user_auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($action === 'track') {
        if ($method !== 'GET') {
            respond(405, ['ok' => false, 'error' => 'Método no permitido']);
        }
        trackSOS();
    }

    if (!in_array($action, ['contact', 'start', 'active', 'update', 'stop'], true)) {
        respond(400, ['ok' => false, 'error' => 'Acción no válida']);
    }

    if (($action === 'contact' || $action === 'active') && $method === 'GET') {
        requireUserApiAccess();
        $userId = (int) $_SESSION['user_id'];
        session_write_close();
        cleanupExpiredSOS(getDB());
        if ($action === 'contact') {
            getEmergencyContact($userId);
        }
        getActiveSOS($userId);
    }

    if ($action === 'active') {
        respond(405, ['ok' => false, 'error' => 'Método no permitido']);
    }

    if ($method !== 'POST') {
        respond(405, ['ok' => false, 'error' => 'Método no permitido']);
    }

    requireUserApiAccess();
    $userId = (int) $_SESSION['user_id'];
    session_write_close();
    $body = getSosJsonBody();
    $db = getDB();
    cleanupExpiredSOS($db);

    switch ($action) {
        case 'contact':
            saveEmergencyContact($db, $userId, $body);
            break;
        case 'start':
            startSOS($db, $userId, $body);
            break;
        case 'update':
            updateSOSLocation($db, $userId, $body);
            break;
        case 'stop':
            stopSOS($db, $userId, $body);
            break;
    }
} catch (Throwable $error) {
    error_log('Rutas Seguras SOS API: ' . $error->getMessage());
    respond(500, ['ok' => false, 'error' => 'No se pudo completar la solicitud SOS.']);
}

function getEmergencyContact(int $userId): void
{
    $stmt = getDB()->prepare('SELECT emergency_phone FROM users WHERE id = :user_id');
    $stmt->execute([':user_id' => $userId]);
    $phone = $stmt->fetchColumn();
    if ($phone === false) {
        respond(404, ['ok' => false, 'error' => 'No se encontró la cuenta de usuario']);
    }
    respond(200, ['ok' => true, 'phone' => $phone]);
}

function saveEmergencyContact(PDO $db, int $userId, array $body): void
{
    $phoneValue = $body['phone'] ?? '';
    $phone = is_string($phoneValue) ? trim($phoneValue) : '';
    if ($phone !== '' && preg_match('/^\+[1-9]\d{7,14}$/', $phone) !== 1) {
        respond(422, ['ok' => false, 'error' => 'Usa el formato internacional E.164, por ejemplo +528112345678']);
    }

    $db->beginTransaction();
    try {
        $currentStmt = $db->prepare('SELECT emergency_phone FROM users WHERE id = :user_id FOR UPDATE');
        $currentStmt->execute([':user_id' => $userId]);
        $currentPhone = $currentStmt->fetchColumn();
        if ($currentPhone === false) {
            $db->rollBack();
            respond(404, ['ok' => false, 'error' => 'No se encontró la cuenta de usuario']);
        }
        $newPhone = $phone === '' ? null : $phone;
        $stoppedSOS = false;
        if ($currentPhone !== $newPhone) {
            $db->prepare('UPDATE users SET emergency_phone = :phone WHERE id = :user_id')->execute([
                ':phone' => $newPhone,
                ':user_id' => $userId,
            ]);
            $stop = $db->prepare(
                'UPDATE sos_sessions
                 SET stopped_at = UTC_TIMESTAMP(), latitude = NULL, longitude = NULL
                 WHERE user_id = :user_id AND stopped_at IS NULL'
            );
            $stop->execute([':user_id' => $userId]);
            $stoppedSOS = $stop->rowCount() > 0;
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }

    respond(200, ['ok' => true, 'phone' => $phone, 'stopped_sos' => $stoppedSOS]);
}

function startSOS(PDO $db, int $userId, array $body): void
{
    $latitude = filter_var($body['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $longitude = filter_var($body['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
    if (
        $latitude === false || $latitude < -90 || $latitude > 90
        || $longitude === false || $longitude < -180 || $longitude > 180
    ) {
        respond(422, ['ok' => false, 'error' => 'No se recibió una ubicación GPS válida']);
    }

    $contactStmt = $db->prepare('SELECT emergency_phone FROM users WHERE id = :user_id');
    $contactStmt->execute([':user_id' => $userId]);
    $phone = $contactStmt->fetchColumn();
    if (!is_string($phone) || $phone === '') {
        respond(422, ['ok' => false, 'error' => 'Registra primero un número de contacto de emergencia en tu cuenta']);
    }

    $settings = getTwilioSettings();
    if ($settings === null) {
        respond(503, ['ok' => false, 'error' => 'El envío SOS no está configurado. Contacta al administrador del sitio.']);
    }

    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $trackingUrl = $settings['base_url'] . '/track.php#token=' . rawurlencode($token);
    $message = 'ALERTA SOS: Estoy en peligro y necesito ayuda. Mi ubicación se actualizará en tiempo real durante 2 horas: '
        . $trackingUrl
        . ' Si no respondo, llama al 911.';

    $db->beginTransaction();
    try {
        $db->prepare(
            'UPDATE sos_sessions
             SET stopped_at = UTC_TIMESTAMP(), latitude = NULL, longitude = NULL
             WHERE user_id = :user_id AND stopped_at IS NULL'
        )->execute([':user_id' => $userId]);
        $stmt = $db->prepare(
            'INSERT INTO sos_sessions
                (user_id, token_hash, latitude, longitude, expires_at, created_at, updated_at)
             VALUES
                (:user_id, :token_hash, :latitude, :longitude,
                 DATE_ADD(UTC_TIMESTAMP(), INTERVAL 2 HOUR), UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':token_hash' => hash('sha256', $token),
            ':latitude' => $latitude,
            ':longitude' => $longitude,
        ]);
        $sosId = (int) $db->lastInsertId();
        $expiresStmt = $db->prepare(
            "SELECT DATE_FORMAT(expires_at, '%Y-%m-%dT%H:%i:%sZ') FROM sos_sessions WHERE id = :id"
        );
        $expiresStmt->execute([':id' => $sosId]);
        $expiresAt = (string) $expiresStmt->fetchColumn();
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }

    try {
        sendTwilioSms($settings, $phone, $message);
    } catch (Throwable $error) {
        $stop = $db->prepare(
            'UPDATE sos_sessions
             SET stopped_at = UTC_TIMESTAMP(), latitude = NULL, longitude = NULL
             WHERE id = :id AND user_id = :user_id'
        );
        $stop->execute([':id' => $sosId, ':user_id' => $userId]);
        respond(502, ['ok' => false, 'error' => 'No se pudo enviar el SMS SOS. La ubicación no quedó compartiéndose.']);
    }

    respond(201, [
        'ok' => true,
        'data' => ['id' => $sosId, 'expires_at' => $expiresAt],
    ]);
}

function getActiveSOS(int $userId): void
{
    $stmt = getDB()->prepare(
        "SELECT id, DATE_FORMAT(expires_at, '%Y-%m-%dT%H:%i:%sZ') AS expires_at
         FROM sos_sessions
         WHERE user_id = :user_id AND stopped_at IS NULL AND expires_at > UTC_TIMESTAMP()
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([':user_id' => $userId]);
    respond(200, ['ok' => true, 'data' => $stmt->fetch() ?: null]);
}

function updateSOSLocation(PDO $db, int $userId, array $body): void
{
    $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
    $latitude = filter_var($body['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $longitude = filter_var($body['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
    if (
        !$id || $id < 1
        || $latitude === false || $latitude < -90 || $latitude > 90
        || $longitude === false || $longitude < -180 || $longitude > 180
    ) {
        respond(422, ['ok' => false, 'error' => 'La ubicación o la sesión SOS no son válidas']);
    }

    $stmt = $db->prepare(
        'UPDATE sos_sessions
         SET latitude = :latitude, longitude = :longitude, updated_at = UTC_TIMESTAMP()
         WHERE id = :id AND user_id = :user_id AND stopped_at IS NULL AND expires_at > UTC_TIMESTAMP()'
    );
    $stmt->execute([
        ':latitude' => $latitude,
        ':longitude' => $longitude,
        ':id' => $id,
        ':user_id' => $userId,
    ]);
    if ($stmt->rowCount() === 0) {
        $exists = $db->prepare(
            'SELECT id FROM sos_sessions
             WHERE id = :id AND user_id = :user_id AND stopped_at IS NULL AND expires_at > UTC_TIMESTAMP()'
        );
        $exists->execute([':id' => $id, ':user_id' => $userId]);
        if (!$exists->fetchColumn()) {
            respond(410, ['ok' => false, 'error' => 'El seguimiento SOS ya no está activo']);
        }
    }
    respond(200, ['ok' => true]);
}

function stopSOS(PDO $db, int $userId, array $body): void
{
    $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) {
        respond(422, ['ok' => false, 'error' => 'La sesión SOS no es válida']);
    }

    $stmt = $db->prepare(
        'UPDATE sos_sessions
         SET stopped_at = UTC_TIMESTAMP(), latitude = NULL, longitude = NULL
         WHERE id = :id AND user_id = :user_id AND stopped_at IS NULL'
    );
    $stmt->execute([':id' => $id, ':user_id' => $userId]);
    respond(200, ['ok' => true]);
}

function trackSOS(): void
{
    $token = $_SERVER['HTTP_X_SOS_TRACKING_TOKEN'] ?? '';
    if (!is_string($token) || preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
        respond(404, ['ok' => false, 'error' => 'El enlace de seguimiento no es válido']);
    }

    $db = getDB();
    cleanupExpiredSOS($db);
    $stmt = $db->prepare(
        "SELECT latitude, longitude,
                DATE_FORMAT(updated_at, '%Y-%m-%dT%H:%i:%sZ') AS updated_at,
                DATE_FORMAT(expires_at, '%Y-%m-%dT%H:%i:%sZ') AS expires_at
         FROM sos_sessions
         WHERE token_hash = :token_hash
           AND stopped_at IS NULL
           AND expires_at > UTC_TIMESTAMP()
           AND latitude IS NOT NULL AND longitude IS NOT NULL
         LIMIT 1"
    );
    $stmt->execute([':token_hash' => hash('sha256', $token)]);
    $location = $stmt->fetch();
    if (!$location) {
        respond(410, ['ok' => false, 'error' => 'El seguimiento SOS terminó o el enlace venció']);
    }
    respond(200, ['ok' => true, 'data' => $location]);
}

function cleanupExpiredSOS(PDO $db): void
{
    $db->exec(
        'UPDATE sos_sessions
         SET stopped_at = UTC_TIMESTAMP(), latitude = NULL, longitude = NULL
         WHERE stopped_at IS NULL AND expires_at <= UTC_TIMESTAMP()'
    );
}

function getTwilioSettings(): ?array
{
    $accountSid = getenv('TWILIO_ACCOUNT_SID') ?: '';
    $authToken = getenv('TWILIO_AUTH_TOKEN') ?: '';
    $fromNumber = getenv('TWILIO_FROM_NUMBER') ?: '';
    $baseUrl = rtrim(getenv('RS_PUBLIC_BASE_URL') ?: '', '/');
    $urlParts = parse_url($baseUrl);

    if (
        preg_match('/^AC[a-f0-9]{32}$/i', $accountSid) !== 1
        || $authToken === ''
        || preg_match('/^\+[1-9]\d{7,14}$/', $fromNumber) !== 1
        || filter_var($baseUrl, FILTER_VALIDATE_URL) === false
        || !is_array($urlParts)
        || strtolower((string) ($urlParts['scheme'] ?? '')) !== 'https'
        || empty($urlParts['host'])
        || isset($urlParts['user'])
        || isset($urlParts['pass'])
        || isset($urlParts['query'])
        || isset($urlParts['fragment'])
    ) {
        return null;
    }

    return [
        'account_sid' => $accountSid,
        'auth_token' => $authToken,
        'from_number' => $fromNumber,
        'base_url' => $baseUrl,
    ];
}

function sendTwilioSms(array $settings, string $to, string $message): void
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('La extensión cURL de PHP no está disponible.');
    }

    $url = 'https://api.twilio.com/2010-04-01/Accounts/'
        . rawurlencode($settings['account_sid']) . '/Messages.json';
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'To' => $to,
            'From' => $settings['from_number'],
            'Body' => $message,
        ]),
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $settings['account_sid'] . ':' . $settings['auth_token'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($response === false || $status < 200 || $status >= 300) {
        $details = is_string($response) ? json_decode($response, true) : null;
        $providerCode = is_array($details) && isset($details['code']) ? (string) $details['code'] : 'sin código';
        error_log('Rutas Seguras Twilio SMS error: HTTP ' . $status . ', ' . $providerCode);
        throw new RuntimeException('El proveedor no aceptó el SMS.');
    }
}

function getSosJsonBody(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== 0) {
        respond(415, ['ok' => false, 'error' => 'El contenido debe enviarse como JSON']);
    }

    $body = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($body)) {
        respond(400, ['ok' => false, 'error' => 'JSON no válido']);
    }
    return $body;
}

function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
