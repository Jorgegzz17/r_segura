<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/admin_auth.php';

startAdminSession();
if (!isAdminAuthenticated()) {
    header('Location: ../index.php');
    exit;
}

$csrf = createAdminCsrfToken();
$stmt = getDB()->query(
    'SELECT id, type, location, description, latitude, longitude, severity, status, likes, created_at
     FROM reports ORDER BY created_at DESC LIMIT 100'
);
$reports = $stmt->fetchAll();
foreach ($reports as &$report) {
    $mediaStmt = getDB()->prepare(
        'SELECT file_name, file_path, mime_type
         FROM report_media WHERE report_id = :report_id ORDER BY created_at ASC'
    );
    $mediaStmt->execute([':report_id' => (int) $report['id']]);
    $report['media'] = $mediaStmt->fetchAll();

    $updatesStmt = getDB()->prepare(
        'SELECT author_type, message, created_at
         FROM report_updates WHERE report_id = :report_id ORDER BY created_at ASC'
    );
    $updatesStmt->execute([':report_id' => (int) $report['id']]);
    $report['updates'] = $updatesStmt->fetchAll();
}
unset($report);
$reportMapData = array_map(static function (array $report): array {
    return [
        'id' => (int) $report['id'],
        'type' => $report['type'],
        'location' => $report['location'],
        'latitude' => (float) $report['latitude'],
        'longitude' => (float) $report['longitude'],
        'severity' => $report['severity'],
        'status' => $report['status'],
    ];
}, $reports);

$routeProposalRows = getDB()->query(
    "SELECT p.id, p.name, p.description, p.created_at, u.username,
            pt.latitude, pt.longitude
     FROM safe_route_proposals p
     INNER JOIN users u ON u.id = p.user_id
     LEFT JOIN safe_route_proposal_points pt ON pt.proposal_id = p.id
     WHERE p.status = 'pending'
     ORDER BY p.created_at DESC, pt.sequence_no"
)->fetchAll();
$routeProposalsById = [];
foreach ($routeProposalRows as $row) {
    $proposalId = (int) $row['id'];
    if (!isset($routeProposalsById[$proposalId])) {
        $routeProposalsById[$proposalId] = [
            'id' => $proposalId,
            'name' => (string) $row['name'],
            'description' => (string) $row['description'],
            'created_at' => (string) $row['created_at'],
            'username' => (string) $row['username'],
            'points' => [],
        ];
    }
    if ($row['latitude'] !== null && $row['longitude'] !== null) {
        $routeProposalsById[$proposalId]['points'][] = [
            'latitude' => (float) $row['latitude'],
            'longitude' => (float) $row['longitude'],
        ];
    }
}
$routeProposals = array_values($routeProposalsById);

$stats = getDB()->query(
    "SELECT COUNT(*) AS total,
        SUM(status = 'activo') AS activos,
        SUM(status = 'resuelto') AS resueltos
     FROM reports"
)->fetch();

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administración | Rutas Seguras</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="../assets/js/municipality.js?v=<?= filemtime(__DIR__ . '/../assets/js/municipality.js') ?>"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/brand-theme.css?v=<?= filemtime(__DIR__ . '/../assets/css/brand-theme.css') ?>">
    <style>
        body { font-family: Inter, sans-serif; }
        #admin-reports-map {
            flex: 1;
            min-height: 0;
            border-radius: 8px;
            background: #e9e5df;
        }
        .admin-report-marker {
            display: flex;
            width: 34px;
            height: 34px;
            align-items: center;
            justify-content: center;
            border: 2px solid #fff;
            border-radius: 50%;
            color: #fff;
            background: var(--marker-color);
            box-shadow: 0 2px 6px rgba(0, 0, 0, .35);
            font-size: 13px;
        }
    </style>
</head>
<body class="brand-page brand-admin-page min-h-screen bg-slate-100 text-slate-800">
    <header class="brand-header">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-1 flex items-center justify-between gap-4 min-h-16">
            <div class="flex items-center gap-3">
                <img src="../punto_seguro.png" alt="" class="brand-logo">
                <div>
                    <h1 class="font-bold">Rutas Seguras</h1>
                    <p class="text-xs">Moderación de reportes · CBTis 258</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span class="admin-session-pill hidden sm:inline-flex items-center gap-2 rounded-full px-3 py-2 text-xs font-semibold">
                    <i class="fa-solid fa-user"></i><?= h((string) ($_SESSION['admin_username'] ?? 'Administrador')) ?>
                </span>
                <form method="post" action="logout.php">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <button class="brand-secondary-button rounded-full px-3 py-2 text-xs font-semibold" type="submit">
                        <i class="fa-solid fa-right-from-bracket mr-1"></i><span class="hidden sm:inline">Salir</span>
                    </button>
                </form>
            </div>
        </div>
    </header>

    <main class="admin-dashboard-layout max-w-7xl mx-auto">
        <aside class="admin-dashboard-sidebar">
            <section aria-labelledby="admin-summary-title">
                <h2 id="admin-summary-title" class="admin-section-label">
                    <i class="fa-solid fa-chart-simple mr-2"></i>Resumen
                </h2>
                <div class="grid grid-cols-3 gap-2">
                    <div class="brand-stat-card p-3">
                        <p class="text-[10px] uppercase font-bold text-slate-500">Todos</p>
                        <p class="text-xl font-extrabold mt-1"><?= (int) $stats['total'] ?></p>
                    </div>
                    <div class="brand-stat-card brand-stat-active p-3">
                        <p class="text-[10px] uppercase font-bold text-amber-700">Activos</p>
                        <p class="text-xl font-extrabold text-amber-700 mt-1"><?= (int) $stats['activos'] ?></p>
                    </div>
                    <div class="brand-stat-card brand-stat-resolved p-3">
                        <p class="text-[10px] uppercase font-bold text-emerald-700">Resueltos</p>
                        <p class="text-xl font-extrabold text-emerald-700 mt-1"><?= (int) $stats['resueltos'] ?></p>
                    </div>
                </div>
            </section>

            <section class="admin-dashboard-help">
                <h2 class="admin-section-label">
                    <i class="fa-solid fa-list-check mr-2"></i>Moderación
                </h2>
                <p class="text-sm text-slate-600 leading-6">
                    Revisa cada reporte y actualiza su estado cuando la situación haya sido atendida.
                </p>
            </section>

            <section aria-labelledby="route-proposals-title">
                <div class="flex items-center justify-between gap-2">
                    <h2 id="route-proposals-title" class="admin-section-label mb-0">Propuestas de rutas</h2>
                    <span id="route-proposals-count" class="rounded-full bg-amber-100 px-2 py-1 text-[10px] font-bold text-amber-800"><?= count($routeProposals) ?></span>
                </div>
                <div id="route-proposals-list" class="mt-2 max-h-64 space-y-2 overflow-y-auto custom-scrollbar">
                    <p id="route-proposals-empty" class="<?= $routeProposals ? 'hidden' : '' ?> text-xs text-slate-400">No hay propuestas pendientes.</p>
                    <?php foreach ($routeProposals as $proposal): ?>
                        <article data-route-proposal-card class="brand-panel rounded-lg p-3">
                            <h3 class="text-sm font-bold"><?= h($proposal['name']) ?></h3>
                            <?php if ($proposal['description'] !== ''): ?>
                                <p class="mt-1 whitespace-pre-line text-xs text-slate-600"><?= h($proposal['description']) ?></p>
                            <?php endif; ?>
                            <p class="mt-2 text-[11px] text-slate-500">
                                De <?= h($proposal['username']) ?> · <?= count($proposal['points']) ?> puntos · <?= h($proposal['created_at']) ?>
                            </p>
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                <button type="button" data-route-proposal-id="<?= (int) $proposal['id'] ?>" data-route-proposal-status="approved"
                                    class="route-proposal-review-btn brand-primary-button rounded-lg px-2 py-2 text-xs font-bold">
                                    <i class="fa-solid fa-check mr-1"></i>Aprobar
                                </button>
                                <button type="button" data-route-proposal-id="<?= (int) $proposal['id'] ?>" data-route-proposal-status="rejected"
                                    class="route-proposal-review-btn brand-secondary-button rounded-lg px-2 py-2 text-xs font-bold">
                                    <i class="fa-solid fa-xmark mr-1"></i>Rechazar
                                </button>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="admin-dashboard-report-section" aria-labelledby="reports-title">
                <div class="admin-dashboard-content-header">
                    <div>
                        <h2 id="reports-title" class="brand-heading text-lg font-extrabold">Últimos reportes</h2>
                        <p class="text-xs text-slate-500 mt-1">Revisa y actualiza cada reporte.</p>
                    </div>
                    <button onclick="location.reload()" aria-label="Actualizar reportes"
                        class="brand-secondary-button shrink-0 rounded-full px-3 py-2 text-xs font-bold">
                        <i class="fa-solid fa-rotate"></i>
                    </button>
                </div>

                <div class="admin-dashboard-report-list custom-scrollbar">
                <?php if (!$reports): ?>
                    <div class="brand-empty-state flex min-h-52 items-center justify-center p-8 text-center text-sm">
                        <div>
                            <i class="fa-solid fa-shield-heart text-2xl text-rose-900/70"></i>
                            <p class="mt-3">Todavía no hay reportes.</p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php foreach ($reports as $report): ?>
                    <?php
                        $resolved = $report['status'] === 'resuelto';
                        $severityColor = ['baja' => 'emerald', 'media' => 'amber', 'alta' => 'red'][$report['severity']] ?? 'slate';
                    ?>
                    <article id="report-<?= (int) $report['id'] ?>" class="brand-panel brand-report-card p-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2 mb-2">
                                <span class="rounded-full px-2.5 py-1 text-[10px] font-bold bg-<?= $severityColor ?>-50 text-<?= $severityColor ?>-700 border border-<?= $severityColor ?>-200">
                                    Riesgo <?= h(ucfirst($report['severity'])) ?>
                                </span>
                                <span class="rounded-full px-2.5 py-1 text-[10px] font-bold <?= $resolved ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' ?>">
                                    <?= $resolved ? 'Resuelto' : 'Activo' ?>
                                </span>
                                <span class="text-[10px] text-slate-400"><?= h($report['created_at']) ?></span>
                            </div>
                            <h3 class="font-bold text-sm"><?= h($report['type']) ?></h3>
                            <p class="text-xs font-semibold text-slate-600 mt-1"><?= h($report['location']) ?></p>
                            <p class="text-sm text-slate-500 mt-2 whitespace-pre-line"><?= h($report['description']) ?></p>
                            <?php if (!empty($report['media'])): ?>
                                <div class="mt-3 grid grid-cols-2 gap-2">
                                    <?php foreach ($report['media'] as $photo): ?>
                                        <?php $photoPath = '../' . ltrim((string) $photo['file_path'], '/'); ?>
                                        <button type="button"
                                            class="report-photo-button block h-24 overflow-hidden rounded-xl border border-slate-200 bg-slate-100"
                                            data-photo="<?= h($photoPath) ?>" data-name="<?= h($photo['file_name']) ?>"
                                            title="Ampliar fotografía: <?= h($photo['file_name']) ?>">
                                            <img src="<?= h($photoPath) ?>" alt="<?= h($photo['file_name']) ?>"
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
                                                <?= h($update['author_type'] === 'admin' ? 'Administrador' : 'Usuario') ?>
                                                · <?= h($update['created_at']) ?>
                                            </p>
                                            <p class="mt-1 whitespace-pre-line text-xs text-slate-700"><?= h($update['message']) ?></p>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <p class="text-[11px] text-slate-400 mt-2"><i class="fa-regular fa-thumbs-up mr-1"></i><?= (int) $report['likes'] ?> apoyos</p>
                        </div>
                        <div class="mt-3 grid gap-2">
                            <button type="button" data-report-id="<?= (int) $report['id'] ?>" data-next-status="<?= $resolved ? 'activo' : 'resuelto' ?>"
                                class="resolve-btn rounded-xl px-3 py-2 text-xs font-bold transition <?= $resolved ? 'brand-secondary-button' : 'brand-primary-button' ?>">
                                <i class="fa-solid <?= $resolved ? 'fa-rotate-left' : 'fa-check' ?> mr-1"></i>
                                <?= $resolved ? 'Reactivar' : 'Marcar resuelto' ?>
                            </button>
                            <form class="update-form flex min-w-0 gap-2" data-report-id="<?= (int) $report['id'] ?>">
                                <input name="message" maxlength="1000" required placeholder="Agregar seguimiento..."
                                    class="brand-input min-w-0 flex-1 rounded-lg border px-2 py-2 text-xs">
                                <button type="submit" aria-label="Enviar seguimiento"
                                    class="brand-secondary-button rounded-lg px-2 py-2 text-xs font-bold">Enviar</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
                </div>
            </section>

            <nav class="admin-dashboard-shortcuts" aria-label="Accesos rápidos">
                <a href="locations.php" class="brand-secondary-button flex items-center gap-3 rounded-xl px-3 py-3 text-sm font-semibold">
                    <i class="fa-solid fa-location-dot"></i>Administrar ubicaciones
                </a>
                <a href="../index.php" class="brand-secondary-button flex items-center gap-3 rounded-xl px-3 py-3 text-sm font-semibold">
                    <i class="fa-solid fa-map"></i>Ver mapa público
                </a>
            </nav>
        </aside>

        <section class="admin-dashboard-map" aria-labelledby="reports-map-title">
            <div class="admin-dashboard-content-header">
                <div>
                    <h2 id="reports-map-title" class="brand-heading text-lg font-extrabold">Mapa de reportes</h2>
                    <p class="text-xs text-slate-500 mt-1">Ubicaciones de los reportes de la comunidad.</p>
                    <p id="reports-map-status" role="status" class="hidden text-xs text-red-600 mt-1"></p>
                </div>
                <div class="flex flex-wrap items-center gap-3 text-[11px] text-slate-500">
                    <span><i class="fa-solid fa-location-dot mr-1 text-red-600"></i>Activos</span>
                    <span><i class="fa-solid fa-location-dot mr-1 text-emerald-600"></i>Resueltos</span>
                    <span><i class="fa-solid fa-route mr-1 text-amber-500"></i>Propuestas</span>
                </div>
            </div>
            <div id="admin-reports-map" role="application" aria-label="Mapa con ubicaciones de reportes"></div>
        </section>
    </main>

    <div id="report-photo-modal" class="fixed inset-0 z-[1200] hidden items-center justify-center bg-slate-950/90 p-4"
        role="dialog" aria-modal="true" aria-labelledby="report-photo-title"
        onclick="if (event.target === this) closeReportPhoto()">
        <button type="button" onclick="closeReportPhoto()" aria-label="Cerrar fotografía"
            class="absolute right-4 top-4 rounded-full bg-white px-4 py-2 text-sm font-bold text-slate-900 shadow-lg">
            <i class="fa-solid fa-xmark mr-1"></i>Cerrar
        </button>
        <div class="flex max-h-[90vh] max-w-[90vw] items-center justify-center">
            <img id="report-photo-modal-image" src="" alt="Fotografía del reporte"
                class="max-h-[86vh] max-w-[90vw] rounded-xl object-contain shadow-2xl">
        </div>
        <span id="report-photo-title" class="sr-only">Fotografía del reporte</span>
    </div>

    <div id="toast" role="status" class="fixed bottom-4 right-4 hidden max-w-sm rounded-xl px-4 py-3 text-sm font-semibold text-white shadow-lg"></div>
    <script>
        const csrfToken = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const reportLocations = <?= json_encode(
            $reportMapData,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) ?>;
        const routeProposals = <?= json_encode(
            $routeProposals,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) ?>;

        const reportPhotoModal = document.getElementById('report-photo-modal');
        const reportPhotoImage = document.getElementById('report-photo-modal-image');

        const reportsMap = L.map('admin-reports-map').setView([25.7877647, -100.3296582], 14);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(reportsMap);

        const routeProposalLayer = L.layerGroup().addTo(reportsMap);
        const routeProposalLines = new Map();
        routeProposals.forEach(proposal => {
            const points = proposal.points.map(point => [Number(point.latitude), Number(point.longitude)]);
            if (points.length < 2) return;
            const line = L.polyline(points, {
                color: '#f59e0b',
                weight: 5,
                opacity: 0.9,
                dashArray: '8, 8',
                lineCap: 'round',
                lineJoin: 'round'
            }).addTo(routeProposalLayer);
            line.bindPopup(
                `<strong>${escapeHtml(proposal.name)}</strong>` +
                `<p>${escapeHtml(proposal.description)}</p>` +
                `<p>Propuesta de ${escapeHtml(proposal.username)}</p>`
            );
            routeProposalLines.set(String(proposal.id), line);
        });

        const reportMarkerPoints = [];
        reportLocations.forEach(report => {
            const latitude = Number(report.latitude);
            const longitude = Number(report.longitude);
            if (!Number.isFinite(latitude) || !Number.isFinite(longitude)
                || latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) {
                return;
            }

            const markerColor = report.status === 'resuelto' ? '#15803d' : '#dc2626';
            const marker = L.marker([latitude, longitude], {
                icon: L.divIcon({
                    html: `<span class="admin-report-marker" style="--marker-color:${markerColor}"><i class="fa-solid fa-triangle-exclamation"></i></span>`,
                    className: 'location-marker',
                    iconSize: [34, 34],
                    iconAnchor: [17, 17]
                })
            }).addTo(reportsMap);
            marker.bindPopup(
                `<strong>${escapeHtml(report.type)}</strong>` +
                `<p>${escapeHtml(report.location)}</p>` +
                `<p>${report.status === 'resuelto' ? 'Resuelto' : 'Activo'} · Riesgo ${escapeHtml(report.severity)}</p>`
            );
            reportMarkerPoints.push([latitude, longitude]);
        });

        async function addMunicipalityBoundary() {
            try {
                const feature = await window.RSEscobedoBoundary.load('../data/escobedo-boundary.geojson');
                window.RSEscobedoBoundary.addMapBoundary(reportsMap, feature);
            } catch (error) {
                console.error('No se pudo cargar el límite municipal en el mapa de reportes:', error);
                const status = document.getElementById('reports-map-status');
                status.textContent = 'No se pudo cargar el límite municipal; se muestran las ubicaciones disponibles.';
                status.classList.remove('hidden');
                if (reportMarkerPoints.length > 1) {
                    reportsMap.fitBounds(reportMarkerPoints, { padding: [32, 32], maxZoom: 16 });
                } else if (reportMarkerPoints.length === 1) {
                    reportsMap.setView(reportMarkerPoints[0], 16);
                }
            }
        }
        addMunicipalityBoundary();

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, character => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
            })[character]);
        }

        function showAdminToast(message, isError = false) {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = `fixed bottom-4 right-4 max-w-sm rounded-xl px-4 py-3 text-sm font-semibold text-white shadow-lg ${isError ? 'bg-red-600' : 'bg-emerald-700'}`;
        }

        async function reviewRouteProposal(button) {
            const proposalId = Number(button.dataset.routeProposalId);
            const status = button.dataset.routeProposalStatus;
            const card = button.closest('[data-route-proposal-card]');
            const cardButtons = card.querySelectorAll('.route-proposal-review-btn');
            cardButtons.forEach(cardButton => { cardButton.disabled = true; });
            try {
                const response = await fetch('../api/route_proposals.php?action=review', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({ id: proposalId, status })
                });
                const result = await response.json();
                if (!response.ok || !result.ok) throw new Error(result.error || 'No se pudo revisar la propuesta');

                const line = routeProposalLines.get(String(proposalId));
                if (line && status === 'approved') {
                    line.setStyle({ color: '#eab308', weight: 6, dashArray: null });
                } else if (line) {
                    routeProposalLayer.removeLayer(line);
                }
                routeProposalLines.delete(String(proposalId));
                card.remove();
                const remaining = document.querySelectorAll('[data-route-proposal-card]').length;
                document.getElementById('route-proposals-count').textContent = String(remaining);
                if (remaining === 0) {
                    document.getElementById('route-proposals-empty').classList.remove('hidden');
                }
                showAdminToast(status === 'approved'
                    ? 'Ruta aprobada; aparecerá en amarillo en el mapa público.'
                    : 'Propuesta rechazada.');
            } catch (error) {
                showAdminToast(error.message, true);
                cardButtons.forEach(cardButton => { cardButton.disabled = false; });
            }
        }

        document.querySelectorAll('.route-proposal-review-btn').forEach(button => {
            button.addEventListener('click', () => reviewRouteProposal(button));
        });

        function openReportPhoto(path, name) {
            reportPhotoImage.src = path;
            reportPhotoImage.alt = name || 'Fotografía del reporte';
            reportPhotoModal.classList.remove('hidden');
            reportPhotoModal.classList.add('flex');
            document.body.style.overflow = 'hidden';
            reportPhotoModal.querySelector('button').focus();
        }

        function closeReportPhoto() {
            reportPhotoModal.classList.add('hidden');
            reportPhotoModal.classList.remove('flex');
            reportPhotoImage.removeAttribute('src');
            document.body.style.overflow = '';
        }

        document.querySelectorAll('.report-photo-button').forEach((button) => {
            button.addEventListener('click', () => openReportPhoto(button.dataset.photo, button.dataset.name));
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !reportPhotoModal.classList.contains('hidden')) {
                closeReportPhoto();
            }
        });

        document.querySelectorAll('.resolve-btn').forEach((button) => {
            button.addEventListener('click', async () => {
                button.disabled = true;
                try {
                    const response = await fetch('../api/reports.php?action=resolve', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrfToken
                        },
                        body: JSON.stringify({ id: Number(button.dataset.reportId) })
                    });
                    const result = await response.json();
                    if (!response.ok || !result.ok) throw new Error(result.error || 'No se pudo actualizar');
                    location.reload();
                } catch (error) {
                    const toast = document.getElementById('toast');
                    toast.textContent = error.message;
                    toast.className = 'fixed bottom-4 right-4 max-w-sm rounded-xl px-4 py-3 text-sm font-semibold text-white shadow-lg bg-red-600';
                } finally {
                    button.disabled = false;
                }
            });
        });

        document.querySelectorAll('.update-form').forEach((form) => {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const button = form.querySelector('button');
                button.disabled = true;
                try {
                    const response = await fetch('../api/reports.php?action=add-update', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrfToken
                        },
                        body: JSON.stringify({
                            id: Number(form.dataset.reportId),
                            message: form.elements.message.value.trim()
                        })
                    });
                    const result = await response.json();
                    if (!response.ok || !result.ok) throw new Error(result.error || 'No se pudo agregar el seguimiento');
                    location.reload();
                } catch (error) {
                    const toast = document.getElementById('toast');
                    toast.textContent = error.message;
                    toast.className = 'fixed bottom-4 right-4 max-w-sm rounded-xl px-4 py-3 text-sm font-semibold text-white shadow-lg bg-red-600';
                } finally {
                    button.disabled = false;
                }
            });
        });
    </script>
</body>
</html>
