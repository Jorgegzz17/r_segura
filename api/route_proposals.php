<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/municipality.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? 'approved';
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($action === 'approved') {
        if ($method !== 'GET') respond(405, ['ok' => false, 'error' => 'Método no permitido']);
        listApprovedRouteProposals();
    }

    if ($action === 'pending') {
        if ($method !== 'GET') respond(405, ['ok' => false, 'error' => 'Método no permitido']);
        require_once __DIR__ . '/../config/admin_auth.php';
        requireAdminApiAccess();
        listPendingRouteProposals();
    }

    if ($action === 'create') {
        if ($method !== 'POST') respond(405, ['ok' => false, 'error' => 'Método no permitido']);
        require_once __DIR__ . '/../config/user_auth.php';
        requireUserApiAccess();
        createRouteProposal(getJsonBody());
    }

    if ($action === 'review') {
        if ($method !== 'POST') respond(405, ['ok' => false, 'error' => 'Método no permitido']);
        require_once __DIR__ . '/../config/admin_auth.php';
        requireAdminApiAccess();
        reviewRouteProposal(getJsonBody());
    }

    respond(400, ['ok' => false, 'error' => 'Acción no válida']);
} catch (Throwable $error) {
    error_log('Rutas Seguras route proposals API: ' . $error->getMessage());
    respond(500, ['ok' => false, 'error' => 'Error interno del servidor']);
}

function listApprovedRouteProposals(): void
{
    $rows = getDB()->query(
        "SELECT p.id, p.name, p.description, p.created_at, pt.latitude, pt.longitude
         FROM safe_route_proposals p
         LEFT JOIN safe_route_proposal_points pt ON pt.proposal_id = p.id
         WHERE p.status = 'approved'
         ORDER BY p.created_at DESC, pt.sequence_no"
    )->fetchAll();

    respond(200, ['ok' => true, 'data' => ['proposals' => groupRouteProposalRows($rows)]]);
}

function listPendingRouteProposals(): void
{
    $rows = getDB()->query(
        "SELECT p.id, p.name, p.description, p.created_at, u.username,
                pt.latitude, pt.longitude
         FROM safe_route_proposals p
         INNER JOIN users u ON u.id = p.user_id
         LEFT JOIN safe_route_proposal_points pt ON pt.proposal_id = p.id
         WHERE p.status = 'pending'
         ORDER BY p.created_at DESC, pt.sequence_no"
    )->fetchAll();

    respond(200, ['ok' => true, 'data' => ['proposals' => groupRouteProposalRows($rows, true)]]);
}

function groupRouteProposalRows(array $rows, bool $includeUsername = false): array
{
    $proposals = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        if (!isset($proposals[$id])) {
            $proposals[$id] = [
                'id' => $id,
                'name' => (string) $row['name'],
                'description' => (string) $row['description'],
                'created_at' => (string) $row['created_at'],
                'points' => [],
            ];
            if ($includeUsername) $proposals[$id]['username'] = (string) $row['username'];
        }
        if ($row['latitude'] !== null && $row['longitude'] !== null) {
            $proposals[$id]['points'][] = [
                'latitude' => (float) $row['latitude'],
                'longitude' => (float) $row['longitude'],
            ];
        }
    }

    return array_values($proposals);
}

function createRouteProposal(array $body): void
{
    $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
    $description = is_string($body['description'] ?? null) ? trim($body['description']) : '';
    $points = $body['points'] ?? null;
    $errors = [];

    if (textLength($name) < 2 || textLength($name) > 120) {
        $errors[] = 'El nombre debe tener entre 2 y 120 caracteres';
    }
    if (textLength($description) > 1000) {
        $errors[] = 'La descripción no puede superar 1000 caracteres';
    }
    if (!is_array($points) || count($points) < 1 || count($points) > 199) {
        $errors[] = 'La propuesta debe incluir entre 1 y 199 puntos válidos';
    }

    $validatedPoints = [];
    if (is_array($points)) {
        foreach ($points as $point) {
            if (!is_array($point)) {
                $errors[] = 'La ruta contiene un punto no válido';
                break;
            }
            $latitude = filter_var($point['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
            $longitude = filter_var($point['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
            if (
                $latitude === false || $latitude < -90 || $latitude > 90
                || $longitude === false || $longitude < -180 || $longitude > 180
            ) {
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

    if ($errors) respond(422, ['ok' => false, 'error' => implode('. ', array_unique($errors))]);

    $db = getDB();
    $campus = $db->query('SELECT latitude, longitude FROM campus_location WHERE id = 1')->fetch();
    if (!$campus) {
        respond(422, ['ok' => false, 'error' => 'Primero guarda la ubicación principal del plantel']);
    }
    if (!isInsideEscobedo((float) $campus['latitude'], (float) $campus['longitude'])) {
        respond(422, ['ok' => false, 'error' => 'La ubicación principal del plantel está fuera de General Escobedo']);
    }

    $campusPoint = [(float) $campus['latitude'], (float) $campus['longitude']];
    $lastPoint = $validatedPoints[count($validatedPoints) - 1];
    if (
        abs($lastPoint[0] - $campusPoint[0]) > 1e-7
        || abs($lastPoint[1] - $campusPoint[1]) > 1e-7
    ) {
        $validatedPoints[] = $campusPoint;
    }

    $db->beginTransaction();
    try {
        $proposal = $db->prepare(
            'INSERT INTO safe_route_proposals (user_id, name, description)
             VALUES (:user_id, :name, :description)'
        );
        $proposal->execute([
            ':user_id' => (int) $_SESSION['user_id'],
            ':name' => $name,
            ':description' => $description,
        ]);
        $proposalId = (int) $db->lastInsertId();

        $pointStatement = $db->prepare(
            'INSERT INTO safe_route_proposal_points
             (proposal_id, sequence_no, latitude, longitude)
             VALUES (:proposal_id, :sequence_no, :latitude, :longitude)'
        );
        foreach ($validatedPoints as $index => [$latitude, $longitude]) {
            $pointStatement->execute([
                ':proposal_id' => $proposalId,
                ':sequence_no' => $index,
                ':latitude' => $latitude,
                ':longitude' => $longitude,
            ]);
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }

    respond(201, ['ok' => true, 'id' => $proposalId]);
}

function reviewRouteProposal(array $body): void
{
    $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
    $status = $body['status'] ?? null;
    if (!$id || $id < 1) respond(422, ['ok' => false, 'error' => 'ID no válido']);
    if (!is_string($status) || !in_array($status, ['approved', 'rejected'], true)) {
        respond(422, ['ok' => false, 'error' => 'Decisión no válida']);
    }

    $db = getDB();
    $statement = $db->prepare(
        "UPDATE safe_route_proposals
         SET status = :status, reviewed_by = :reviewed_by, reviewed_at = CURRENT_TIMESTAMP
         WHERE id = :id AND status = 'pending'"
    );
    $statement->execute([
        ':status' => $status,
        ':reviewed_by' => (int) $_SESSION['admin_id'],
        ':id' => $id,
    ]);
    if ($statement->rowCount() === 0) {
        $exists = $db->prepare('SELECT status FROM safe_route_proposals WHERE id = :id');
        $exists->execute([':id' => $id]);
        $currentStatus = $exists->fetchColumn();
        if ($currentStatus === false) respond(404, ['ok' => false, 'error' => 'Propuesta no encontrada']);
        respond(409, ['ok' => false, 'error' => 'La propuesta ya fue revisada']);
    }

    respond(200, ['ok' => true, 'status' => $status]);
}

function getJsonBody(): array
{
    $raw = file_get_contents('php://input');
    $body = $raw === false ? null : json_decode($raw, true);
    if (!is_array($body)) respond(400, ['ok' => false, 'error' => 'JSON inválido']);
    return $body;
}

function textLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
