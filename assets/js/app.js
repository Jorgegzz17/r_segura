/* ============================================================
   Rutas Seguras CBTis 258 - Lógica de la aplicación
   ============================================================ */

'use strict';

/* ---------------- Estado global ---------------- */
let map;
let currentTypeFilter = '';
let searchQuery = '';
let searchDebounce = null;
let reports = [];
let selectedSeverity = 'media';
let pickedCoords = null;
let tempMarker = null;
let pickingLocation = false;
let reportsLayerGroup;
let campusLayerGroup;
let safePointsLayerGroup;
let safeRoutesLayerGroup;
let editingReportId = null;

const API_URL = 'api/reports.php';
const CBTIS_COORDS = [25.7877647, -100.3296582];
let campusCoords = CBTIS_COORDS;
let campusMarker = null;

const SEVERITY_COLORS = { baja: '#10b981', media: '#f59e0b', alta: '#ef4444' };
const SEVERITY_LABELS = { baja: 'Baja', media: 'Media', alta: 'Alta' };

/* ============================================================
   Inicialización
   ============================================================ */
document.addEventListener('DOMContentLoaded', () => {
    initTheme();
    initMap();
    loadReports();
    loadMapLocations();
    loadStats();
    if (new URLSearchParams(window.location.search).get('report') === '1' && window.RS_USER_AUTHENTICATED) {
        openReportModal();
    }
});

/* ============================================================
   Tema oscuro / claro
   ============================================================ */
function initTheme() {
    const saved = localStorage.getItem('rs-theme');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    if (saved === 'dark' || (!saved && prefersDark)) {
        document.documentElement.classList.add('dark');
    }
    updateThemeIcon();
}

function toggleDarkMode() {
    document.documentElement.classList.toggle('dark');
    localStorage.setItem('rs-theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
    updateThemeIcon();
}

function updateThemeIcon() {
    const icon = document.getElementById('theme-icon');
    const isDark = document.documentElement.classList.contains('dark');
    icon.className = isDark ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
}

/* ============================================================
   Mapa y ubicaciones de reportes
   ============================================================ */
function initMap() {
    map = L.map('map', { zoomControl: true }).setView(CBTIS_COORDS, 16);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap contributors</a> | CBTis 258 Rutas Seguras'
    }).addTo(map);

    reportsLayerGroup = L.layerGroup().addTo(map);
    campusLayerGroup = L.layerGroup().addTo(map);
    safePointsLayerGroup = L.layerGroup().addTo(map);
    safeRoutesLayerGroup = L.layerGroup().addTo(map);
    map.on('click', event => {
        if (!pickingLocation) return;
        setPickedCoords(event.latlng.lat, event.latlng.lng);
        stopMapPick();
        openReportModal();
    });
}

async function loadMapLocations() {
    try {
        const response = await fetch('api/locations.php?action=list');
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || 'No se pudieron cargar las ubicaciones');

        campusLayerGroup.clearLayers();
        safePointsLayerGroup.clearLayers();
        safeRoutesLayerGroup.clearLayers();

        const campus = result.data.campus;
        if (campus) {
            const coords = [Number(campus.latitude), Number(campus.longitude)];
            campusCoords = coords;
            campusMarker = L.marker(coords, { icon: createCustomIcon('fa-school', '#6b1426') }).addTo(campusLayerGroup);
            campusMarker.bindPopup(`
                <div class="p-2">
                    <strong>${escapeHtml(campus.name)}</strong>
                    <p class="text-xs mt-1">${escapeHtml(campus.address)}</p>
                    ${campus.description ? `<p class="text-xs mt-1">${escapeHtml(campus.description)}</p>` : ''}
                </div>
            `);
            map.setView(coords, 16);
        }

        result.data.safePoints.forEach(point => {
            const marker = L.marker([Number(point.latitude), Number(point.longitude)], {
                icon: createCustomIcon('fa-shield-halved', '#15803d')
            }).addTo(safePointsLayerGroup);
            marker.bindPopup(`
                <div class="p-2">
                    <strong>${escapeHtml(point.name)}</strong>
                    <p class="text-xs mt-1">${escapeHtml(point.address)}</p>
                    ${point.description ? `<p class="text-xs mt-1">${escapeHtml(point.description)}</p>` : ''}
                </div>
            `);
        });

        const alignmentResults = await Promise.all(result.data.routes.map(async route => {
            const line = L.polyline(
                route.points.map(point => [Number(point.latitude), Number(point.longitude)]),
                { color: route.color, weight: 5, opacity: 0.85, lineCap: 'round', lineJoin: 'round' }
            ).addTo(safeRoutesLayerGroup);
            line.bindPopup(`
                <div class="p-2">
                    <strong>${escapeHtml(route.name)}</strong>
                    ${route.description ? `<p class="text-xs mt-1">${escapeHtml(route.description)}</p>` : ''}
                    <p class="text-xs mt-1">Ruta segura al plantel</p>
                </div>
            `);

            try {
                const alignedPoints = await window.RSRouteRouting.align(route.points);
                line.setLatLngs(alignedPoints.map(point => [point.latitude, point.longitude]));
                return true;
            } catch (error) {
                console.warn(`No se pudo ajustar la ruta "${route.name}" a las calles:`, error);
                return false;
            }
        }));

        const failedAlignments = alignmentResults.filter(aligned => !aligned).length;
        if (failedAlignments > 0) {
            showToast(
                `No se pudieron ajustar ${failedAlignments} ruta${failedAlignments === 1 ? '' : 's'} a las calles; se muestra el trazado guardado.`,
                'error'
            );
        }
    } catch (error) {
        showToast(error.message, 'error');
    }
}

function createCustomIcon(iconClass, bgColor, extraClass = '') {
    return L.divIcon({
        html: `
            <div style="background-color: ${bgColor};" class="w-8 h-8 rounded-full flex items-center justify-center text-white shadow-lg border-2 border-white ${extraClass}">
                <i class="fa-solid ${iconClass} text-xs"></i>
            </div>
        `,
        className: 'custom-leaflet-marker',
        iconSize: [32, 32],
        iconAnchor: [16, 32],
        popupAnchor: [0, -28]
    });
}

function drawReportMarkers() {
    reportsLayerGroup.clearLayers();
    reports.forEach(report => {
        const color = report.status === 'resuelto' ? '#94a3b8' : (SEVERITY_COLORS[report.severity] || '#f59e0b');
        const marker = L.marker([report.latitude, report.longitude], {
            icon: createCustomIcon(
                report.status === 'resuelto' ? 'fa-check' : reportTypeMarkerIcon(report.type),
                color
            )
        }).addTo(reportsLayerGroup);

        marker.bindTooltip(escapeHtml(report.type), {
            permanent: true,
            direction: 'top',
            offset: [0, -30],
            className: 'report-map-tooltip',
            opacity: 1
        });
        const media = Array.isArray(report.media) ? report.media : [];
        const photoHtml = media.length ? `
            <button type="button" class="report-popup-photo mt-2 block h-28 w-full overflow-hidden rounded-lg border border-slate-200"
                onclick="openImageLightbox('${escapeHtml(media[0].file_path)}', '${escapeHtml(media[0].file_name)}')"
                aria-label="Ampliar fotografía del reporte">
                <img src="${escapeHtml(media[0].file_path)}" alt="${escapeHtml(media[0].file_name)}" class="h-full w-full object-cover">
            </button>` : '';
        marker.bindPopup(`
            <div class="p-2">
                <span class="bg-amber-100 text-amber-800 font-bold text-[10px] uppercase px-1.5 py-0.5 rounded">${escapeHtml(report.type)}</span>
                <h4 class="font-bold text-xs text-slate-900 mt-1">${escapeHtml(report.location)}</h4>
                <p class="text-[11px] text-slate-600 mt-1">${escapeHtml(report.description)}</p>
                ${photoHtml}
                <div class="mt-2 pt-2 border-t border-slate-100 flex items-center justify-between text-[10px] text-slate-400">
                    <span>${timeAgo(report.created_at)}</span>
                    <span class="text-amber-600 font-bold"><i class="fa-solid fa-thumbs-up mr-1"></i>${report.likes} apoyo</span>
                </div>
            </div>
        `);
    });
}

function reportTypeMarkerIcon(type) {
    const icons = {
        'Luz Fundida / Zona Oscura': 'fa-bolt',
        'Presencia Escasa de Vía': 'fa-road',
        'Zona Solitaria / Sospechosa': 'fa-location-dot',
        'Tráfico / Banqueta Obstruida': 'fa-car-side',
        'Acoso / Robo': 'fa-shield-halved',
        Otro: 'fa-circle-question'
    };
    return icons[type] || 'fa-triangle-exclamation';
}

/* ============================================================
   API - Reportes
   ============================================================ */
async function loadReports() {
    const showResolvedOnly = document.getElementById('show-resolved').checked;
    const params = new URLSearchParams({ action: 'list' });
    params.set('status', showResolvedOnly ? 'resuelto' : 'activo');
    if (currentTypeFilter) params.set('type', currentTypeFilter);
    if (searchQuery) params.set('q', searchQuery);

    const container = document.getElementById('reports-container');
    container.innerHTML = `
        <div class="flex justify-center py-8 text-slate-400">
            <i class="fa-solid fa-circle-notch fa-spin text-xl"></i>
        </div>`;

    try {
        const res = await fetch(`${API_URL}?${params}`);
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'Error al cargar');
        reports = json.data;
        renderReports();
        drawReportMarkers();
    } catch (err) {
        container.innerHTML = `
            <div class="text-center py-6">
                <i class="fa-solid fa-plug-circle-xmark text-slate-300 text-2xl mb-2"></i>
                <p class="text-xs text-slate-400">No se pudieron cargar los reportes.<br>Verifica la conexión con la base de datos.</p>
            </div>`;
        showToast('Error al cargar reportes', 'error');
    }
}

async function loadStats() {
    try {
        const res = await fetch(`${API_URL}?action=stats`);
        const json = await res.json();
        if (!json.ok) return;
        document.getElementById('stat-total').textContent = json.data.total;
        document.getElementById('stat-activos').textContent = json.data.activos;
        document.getElementById('stat-resueltos').textContent = json.data.resueltos;
    } catch (err) { /* silencioso */ }
}

async function loadAccountReports() {
    const emptyState = document.getElementById('account-empty-state');
    const list = document.getElementById('account-report-list');
    if (!emptyState || !list) return;

    list.replaceChildren();
    emptyState.classList.remove('hidden');
    emptyState.setAttribute('role', 'status');
    emptyState.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin text-xl"></i><p class="mt-2">Cargando historial…</p>';

    try {
        const response = await fetch('account.php?modal_data=1', {
            headers: { Accept: 'application/json' },
            cache: 'no-store'
        });
        const result = await response.json();
        if (!response.ok || !result.ok || !Array.isArray(result.reports)) {
            throw new Error(result.error || 'No se pudo cargar tu historial.');
        }

        if (!result.reports.length) {
            emptyState.innerHTML = '<i class="fa-solid fa-clipboard-list text-2xl text-slate-300"></i><p class="font-bold mt-2">Aún no tienes reportes</p><p class="text-sm text-slate-500 mt-1">Tus reportes aparecerán aquí después de publicarlos.</p>';
            return;
        }

        emptyState.classList.add('hidden');
        const notifications = Array.isArray(result.notifications) ? result.notifications : [];
        const notificationsContainer = document.getElementById('account-notifications');
        if (notificationsContainer) {
            if (notifications.length) {
                notificationsContainer.classList.remove('hidden');
                notificationsContainer.innerHTML = `
                    <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3">
                        <p class="text-xs font-bold text-emerald-800"><i class="fa-solid fa-bell mr-1"></i>Notificaciones</p>
                        ${notifications.map(notification => `<p class="mt-1 text-xs text-emerald-700">${escapeHtml(notification.message)}</p>`).join('')}
                    </div>`;
            } else {
                notificationsContainer.classList.add('hidden');
                notificationsContainer.replaceChildren();
            }
        }
        result.reports.forEach((report) => {
            const resolved = report.status === 'resuelto';
            const severity = String(report.severity || '');
            const item = document.createElement('article');
            item.className = 'brand-panel brand-report-card p-4';
            const updates = Array.isArray(report.updates) ? report.updates : [];
            const media = Array.isArray(report.media) ? report.media : [];
            const updateHtml = updates.length
                ? `<div class="mt-3 space-y-2"><p class="text-xs font-bold text-slate-600">Seguimiento</p>${updates.map(update => `
                    <div class="rounded-lg border border-slate-200 px-3 py-2 text-xs">
                        <span class="font-bold">${update.author_type === 'admin' ? 'Administrador' : 'Usuario'}</span>
                        <span class="text-slate-400"> · ${escapeHtml(update.created_at)}</span>
                        <p class="mt-1 text-slate-600">${escapeHtml(update.message)}</p>
                    </div>`).join('')}</div>`
                : '';
            const mediaHtml = media.length
                ? `<div class="mt-3 flex flex-wrap gap-2">${media.map(photo => `
                    <a href="${escapeHtml(photo.file_path)}" target="_blank" rel="noopener" class="block h-20 w-20 overflow-hidden rounded-lg border border-slate-200">
                        <img src="${escapeHtml(photo.file_path)}" alt="${escapeHtml(photo.file_name)}" class="h-full w-full object-cover">
                    </a>`).join('')}</div>`
                : '';
            item.innerHTML = `
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h4 class="font-bold">${escapeHtml(report.type)}</h4>
                        <p class="text-sm text-slate-600 mt-1">${escapeHtml(report.location)}</p>
                    </div>
                    <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-bold ${resolved ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'}">
                        ${resolved ? 'Resuelto' : 'En revisión'}
                    </span>
                </div>
                <p class="text-sm text-slate-500 mt-2">${escapeHtml(report.description)}</p>
                <p class="text-xs text-slate-400 mt-3">Riesgo ${escapeHtml(severity.charAt(0).toUpperCase() + severity.slice(1))} · ${escapeHtml(report.created_at)}</p>
                ${mediaHtml}
                ${updateHtml}
                ${resolved ? '' : `
                    <div class="mt-3 flex gap-2">
                        <button type="button" class="brand-secondary-button rounded-lg px-3 py-2 text-xs font-bold" onclick="editOwnReport(${Number(report.id)})">
                            <i class="fa-solid fa-pencil mr-1"></i>Editar
                        </button>
                        <button type="button" class="rounded-lg border border-red-200 px-3 py-2 text-xs font-bold text-red-700" onclick="deleteOwnReport(${Number(report.id)})">
                            <i class="fa-solid fa-trash mr-1"></i>Eliminar
                        </button>
                    </div>`}
            `;
            list.appendChild(item);
        });
    } catch (error) {
        const message = error instanceof Error ? error.message : 'No se pudo cargar tu historial.';
        emptyState.setAttribute('role', 'alert');
        emptyState.innerHTML = `<i class="fa-solid fa-circle-exclamation text-xl"></i><p class="mt-2">${escapeHtml(message)}</p><button type="button" class="brand-link mt-2 text-sm font-bold" onclick="loadAccountReports()">Reintentar</button>`;
    }
}

async function handleReportSubmit(e) {
    e.preventDefault();

    if (!pickedCoords) {
        showToast('Selecciona una ubicación con GPS o marcando el mapa', 'error');
        return;
    }

    const btn = document.getElementById('submit-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin mr-1"></i> Publicando...';

    const payload = {
        type: document.getElementById('report-type').value,
        location: document.getElementById('report-location').value.trim(),
        description: document.getElementById('report-description').value.trim(),
        severity: selectedSeverity,
        latitude: pickedCoords[0],
        longitude: pickedCoords[1]
    };

    try {
        const action = editingReportId ? 'update' : 'create';
        if (editingReportId) payload.id = editingReportId;
        const res = await fetch(`${API_URL}?action=${action}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.RS_USER_CSRF_TOKEN
            },
            body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'Error al guardar');

        const reportId = editingReportId || json.data.id;
        const photoInput = document.getElementById('report-photo');
        if (photoInput.files.length > 0) {
            const photo = photoInput.files[0];
            if (photo.size > 5 * 1024 * 1024) throw new Error('La fotografía no debe exceder 5 MB');
            const formData = new FormData();
            formData.set('report_id', String(reportId));
            formData.set('photo', photo);
            const uploadResponse = await fetch(`${API_URL}?action=upload-photo`, {
                method: 'POST',
                headers: { 'X-CSRF-Token': window.RS_USER_CSRF_TOKEN },
                body: formData
            });
            const uploadResult = await uploadResponse.json();
            if (!uploadResponse.ok || !uploadResult.ok) throw new Error(uploadResult.error || 'No se pudo cargar la fotografía');
        }

        showToast(editingReportId ? 'Reporte actualizado' : 'Reporte publicado. ¡Gracias por ayudar!', 'success');
        closeReportModal();
        document.getElementById('report-form').reset();
        setSeverity('media');
        clearPickedCoords();
        editingReportId = null;
        await loadReports();
        loadStats();
        if (json.data) moveMapTo([json.data.latitude, json.data.longitude], 17);
    } catch (err) {
        showToast(err.message, 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = 'Publicar Reporte';
    }
}

async function likeReport(e, id) {
    e.stopPropagation();
    try {
        const res = await fetch(`${API_URL}?action=like`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.RS_USER_CSRF_TOKEN
            },
            body: JSON.stringify({ id })
        });
        const json = await res.json();
        if (!res.ok || !json.ok) throw new Error(json.error || 'No se pudo registrar el apoyo');

        const report = reports.find(r => Number(r.id) === Number(id));
        if (report) {
            report.likes = json.likes;
            report.liked_by_me = true;
        }
        renderReports();
        drawReportMarkers();
    } catch (err) {
        showToast(err instanceof Error ? err.message : 'No se pudo registrar el apoyo', 'error');
    }
}

async function editOwnReport(id) {
    const response = await fetch('account.php?modal_data=1', {
        headers: { Accept: 'application/json' },
        cache: 'no-store'
    });
    const result = await response.json();
    if (!response.ok || !result.ok) return showToast(result.error || 'No se pudo abrir el reporte', 'error');
    const report = result.reports.find(item => Number(item.id) === Number(id));
    if (!report) return showToast('Reporte no encontrado', 'error');

    editingReportId = id;
    document.getElementById('report-type').value = report.type;
    updateReportTypeIcon(report.type);
    document.getElementById('report-location').value = report.location;
    document.getElementById('report-description').value = report.description;
    setSeverity(report.severity);
    setPickedCoords(Number(report.latitude), Number(report.longitude));
    closeAccountModal();
    openReportModal();
}

async function deleteOwnReport(id) {
    if (!window.confirm('¿Eliminar este reporte? Esta acción no se puede deshacer.')) return;
    try {
        const response = await fetch(`${API_URL}?action=delete`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.RS_USER_CSRF_TOKEN
            },
            body: JSON.stringify({ id })
        });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || 'No se pudo eliminar');
        showToast('Reporte eliminado', 'success');
        await loadAccountReports();
        await loadReports();
        loadStats();
    } catch (error) {
        showToast(error.message, 'error');
    }
}

/* ============================================================
   Renderizado de la lista
   ============================================================ */
function renderReports() {
    const container = document.getElementById('reports-container');
    container.innerHTML = '';

    if (reports.length === 0) {
        container.innerHTML = `
            <div class="text-center py-8">
                <i class="fa-solid fa-shield-heart text-emerald-300 text-3xl mb-2"></i>
            <p class="text-xs text-slate-400">Todavía no hay reportes. Puedes agregar una ubicación verificada.</p>
            </div>`;
        return;
    }

    reports.forEach(report => {
        const sevColor = SEVERITY_COLORS[report.severity] || '#f59e0b';
        const isResolved = report.status === 'resuelto';
        const media = Array.isArray(report.media) ? report.media : [];
        const photoHtml = media.length ? `
            <button type="button" class="report-card-photo mt-2 h-32 w-full overflow-hidden rounded-lg border border-slate-200"
                onclick="event.stopPropagation(); openImageLightbox('${escapeHtml(media[0].file_path)}', '${escapeHtml(media[0].file_name)}')"
                aria-label="Ampliar fotografía del reporte">
                <img src="${escapeHtml(media[0].file_path)}" alt="${escapeHtml(media[0].file_name)}" class="h-full w-full object-cover">
            </button>` : '';

        const card = document.createElement('div');
        card.className = `p-3 bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl shadow-sm hover:border-slate-300 dark:hover:border-slate-600 transition cursor-pointer ${isResolved ? 'opacity-60' : ''}`;
        card.onclick = () => {
            moveMapTo([report.latitude, report.longitude], 18);
            if (window.innerWidth < 768) closeSidebar();
        };

        card.innerHTML = `
            <div class="flex items-start justify-between gap-2">
                <span class="text-[10px] font-bold px-2 py-0.5 rounded-md border" style="color: ${sevColor}; background-color: ${sevColor}15; border-color: ${sevColor}40">
                    ${escapeHtml(report.type)}
                </span>
                <span class="text-[10px] text-slate-400 shrink-0">${timeAgo(report.created_at)}</span>
            </div>
            <h4 class="text-xs font-bold text-slate-800 dark:text-slate-100 mt-1.5">${escapeHtml(report.location)}</h4>
            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5 line-clamp-2">${escapeHtml(report.description)}</p>
            ${photoHtml}
            <div class="mt-2 flex items-center justify-between text-xs pt-1">
                <div class="flex items-center space-x-3">
                <button type="button" onclick="likeReport(event, ${Number(report.id)})" ${Number(report.liked_by_me) === 1 ? 'disabled aria-pressed="true" title="Ya diste apoyo a este reporte"' : 'aria-pressed="false"'}
                    class="text-[11px] ${Number(report.liked_by_me) === 1 ? 'text-rose-800 cursor-default' : 'text-slate-500 hover:text-cbtis-wine'} font-medium flex items-center space-x-1">
                    <i class="fa-solid fa-thumbs-up"></i><span>${Number(report.liked_by_me) === 1 ? 'Ya apoyaste' : 'Útil'} (${Number(report.likes)})</span>
                    </button>
                    ${isResolved ? '<span class="text-[11px] text-emerald-600 font-semibold"><i class="fa-solid fa-circle-check mr-1"></i>Resuelto</span>' : ''}
                </div>
                <span class="text-[10px] font-bold flex items-center" style="color: ${sevColor}">
                    Riesgo ${SEVERITY_LABELS[report.severity] || 'Media'}
                </span>
            </div>
        `;
        container.appendChild(card);
    });
}

/* ============================================================
   Filtros y búsqueda
   ============================================================ */
function onSearchInput(value) {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
        searchQuery = value.trim();
        loadReports();
    }, 350);
}

function setTypeFilter(type) {
    currentTypeFilter = type;
    document.querySelectorAll('.type-filter-btn').forEach(btn => {
        const active = btn.dataset.type === type;
        btn.className = `type-filter-btn shrink-0 text-[10px] font-semibold px-2.5 py-1 rounded-full ${active
            ? 'bg-cbtis-wine text-white'
            : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300'}`;
    });
    loadReports();
}

function toggleMapLayer(layer) {
    const groups = {
        reports: reportsLayerGroup,
        routes: safeRoutesLayerGroup,
        campus: campusLayerGroup,
        safePoints: safePointsLayerGroup
    };
    const ids = { reports: 'layer-reports', routes: 'layer-routes', campus: 'layer-campus', safePoints: 'layer-safe-points' };
    const checked = document.getElementById(ids[layer]).checked;
    checked ? map.addLayer(groups[layer]) : map.removeLayer(groups[layer]);
}

function moveMapTo(coords, zoom) {
    map.flyTo(coords, zoom);
}

function updateReportTypeIcon(type) {
    const icons = {
        'Luz Fundida / Zona Oscura': 'fa-bolt',
        'Presencia Escasa de Vía': 'fa-road',
        'Zona Solitaria / Sospechosa': 'fa-map-location-dot',
        'Tráfico / Banqueta Obstruida': 'fa-car-side',
        'Acoso / Robo': 'fa-shield-halved',
        Otro: 'fa-circle-question'
    };
    const icon = document.getElementById('report-type-icon');
    icon.className = `fa-solid ${icons[type] || 'fa-circle-question'} absolute left-3 top-1/2 -translate-y-1/2 text-cbtis-wine`;
}

function setSeverity(severity) {
    if (!Object.prototype.hasOwnProperty.call(SEVERITY_LABELS, severity)) {
        throw new Error('El nivel de riesgo seleccionado no es válido.');
    }

    selectedSeverity = severity;
    document.querySelectorAll('#severity-group .severity-btn').forEach(button => {
        const isSelected = button.dataset.severity === severity;
        button.classList.toggle('is-selected', isSelected);
        button.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
    });
}

function setPickedCoords(lat, lng) {
    pickedCoords = [lat, lng];
    document.getElementById('coords-value').textContent = `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
    if (tempMarker) map.removeLayer(tempMarker);
    tempMarker = L.marker(pickedCoords, {
        icon: createCustomIcon('fa-location-dot', '#6b1426', 'temp-marker-pulse')
    }).addTo(map);
}

function clearPickedCoords() {
    pickedCoords = null;
    document.getElementById('coords-value').textContent = 'Ubicación requerida: selecciona GPS o marca el mapa';
    if (tempMarker) { map.removeLayer(tempMarker); tempMarker = null; }
}

function startMapPick() {
    pickingLocation = true;
    document.body.classList.add('picking-location');
    closeReportModal();
    showToast('Toca el mapa para marcar la ubicación del reporte', 'info');
    if (window.innerWidth < 768) closeSidebar();
}

function stopMapPick() {
    pickingLocation = false;
    document.body.classList.remove('picking-location');
}

function useMyLocation() {
    if (!navigator.geolocation) {
        showToast('Tu navegador no soporta geolocalización', 'error');
        return;
    }
    showToast('Obteniendo tu ubicación...', 'info');
    navigator.geolocation.getCurrentPosition(
        pos => {
            setPickedCoords(pos.coords.latitude, pos.coords.longitude);
            moveMapTo(pickedCoords, 17);
            showToast('Ubicación detectada', 'success');
        },
        () => showToast('No se pudo obtener tu ubicación', 'error'),
        { enableHighAccuracy: true, timeout: 8000 }
    );
}

function goToCampus() {
    moveMapTo(campusCoords, 17);
    if (campusMarker) campusMarker.openPopup();
}

/* ============================================================
   Modales y panel lateral
   ============================================================ */
function openReportModal() {
    if (!window.RS_USER_AUTHENTICATED) {
        window.location.href = 'account.php?next=report';
        return;
    }
    document.getElementById('report-modal').classList.remove('hidden');
}

function closeReportModal() {
    document.getElementById('report-modal').classList.add('hidden');
}

function openImageLightbox(path, name) {
    const lightbox = document.getElementById('image-lightbox');
    if (!lightbox) return;
    document.getElementById('lightbox-image').src = path;
    document.getElementById('lightbox-image').alt = name || 'Fotografía del reporte';
    lightbox.classList.remove('hidden');
    document.body.classList.add('overflow-hidden');
    document.getElementById('lightbox-close').focus();
}

function closeImageLightbox() {
    const lightbox = document.getElementById('image-lightbox');
    if (!lightbox) return;
    lightbox.classList.add('hidden');
    document.getElementById('lightbox-image').removeAttribute('src');
    document.body.classList.remove('overflow-hidden');
}

document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
        const lightbox = document.getElementById('image-lightbox');
        if (lightbox && !lightbox.classList.contains('hidden')) closeImageLightbox();
    }
});

function openAccountModal(event) {
    if (event) event.preventDefault();

    const modal = document.getElementById('account-modal');
    if (!modal) return;

    modal.classList.remove('hidden');
    document.body.classList.add('overflow-hidden');
    document.getElementById('account-open-button').setAttribute('aria-expanded', 'true');
    document.getElementById('account-close-button').focus();
    loadAccountReports();
}

function closeAccountModal() {
    const modal = document.getElementById('account-modal');
    if (!modal || modal.classList.contains('hidden')) return;

    modal.classList.add('hidden');
    document.body.classList.remove('overflow-hidden');
    const trigger = document.getElementById('account-open-button');
    trigger.setAttribute('aria-expanded', 'false');
    trigger.focus();
}

function closeAccountModalFromBackdrop(event) {
    if (event.target === event.currentTarget) closeAccountModal();
}

function toggleSOSModal() {
    document.getElementById('sos-modal').classList.toggle('hidden');
}

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const icon = document.getElementById('sidebar-handle-icon');
    sidebar.classList.toggle('open');
    icon.className = sidebar.classList.contains('open') ? 'fa-solid fa-chevron-down' : 'fa-solid fa-chevron-up';
}

function closeSidebar() {
    const sidebar = document.getElementById('sidebar');
    sidebar.classList.remove('open');
    document.getElementById('sidebar-handle-icon').className = 'fa-solid fa-chevron-up';
}


// Cerrar modales con Escape
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeAccountModal();
        closeReportModal();
        document.getElementById('sos-modal').classList.add('hidden');
        stopMapPick();
    }
});

/* ============================================================
   Utilidades
   ============================================================ */
function showToast(message, type = 'info') {
    const container = document.getElementById('toast-container');
    const styles = {
        success: { icon: 'fa-circle-check', cls: 'bg-emerald-600' },
        error:   { icon: 'fa-circle-xmark', cls: 'bg-red-600' },
        info:    { icon: 'fa-circle-info',  cls: 'bg-slate-800' }
    };
    const s = styles[type] || styles.info;

    const toast = document.createElement('div');
    toast.className = `toast ${s.cls} text-white text-xs font-semibold px-4 py-3 rounded-xl shadow-lg flex items-center space-x-2`;
    toast.innerHTML = `<i class="fa-solid ${s.icon}"></i><span>${escapeHtml(message)}</span>`;
    container.appendChild(toast);

    setTimeout(() => {
        toast.style.transition = 'opacity .3s';
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 300);
    }, 3200);
}

function timeAgo(dateStr) {
    if (!dateStr) return '';
    const date = new Date(dateStr.replace(' ', 'T'));
    const seconds = Math.floor((Date.now() - date.getTime()) / 1000);

    if (seconds < 60) return 'Justo ahora';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `Hace ${minutes} min`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `Hace ${hours} h`;
    const days = Math.floor(hours / 24);
    if (days < 7) return `Hace ${days} d`;
    return date.toLocaleDateString('es-MX', { day: 'numeric', month: 'short' });
}

function escapeHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
