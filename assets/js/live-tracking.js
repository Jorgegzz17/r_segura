'use strict';

(() => {
    const token = new URLSearchParams(window.location.hash.slice(1)).get('token') || '';
    const status = document.getElementById('tracking-status');
    const latitude = document.getElementById('tracking-latitude');
    const longitude = document.getElementById('tracking-longitude');
    const updated = document.getElementById('tracking-updated');
    const mapLink = document.getElementById('tracking-map-link');
    let pollTimer = null;
    let isExpired = false;

    if (!token) {
        status.textContent = 'El enlace no contiene un código de seguimiento válido.';
        return;
    }

    async function refreshLocation() {
        if (isExpired) return;
        try {
            const response = await fetch('api/sos.php?action=track', {
                headers: { 'X-SOS-Tracking-Token': token },
                cache: 'no-store'
            });
            const result = await response.json();
            if (response.status === 410 || response.status === 404) {
                isExpired = true;
                window.clearInterval(pollTimer);
                status.textContent = result.error || 'El seguimiento SOS terminó.';
                latitude.textContent = '—';
                longitude.textContent = '—';
                updated.textContent = 'La ubicación ya no está disponible.';
                mapLink.setAttribute('aria-disabled', 'true');
                return;
            }
            if (!response.ok || !result.ok || !result.data) {
                throw new Error(result.error || 'No se pudo consultar la ubicación.');
            }

            const lat = Number(result.data.latitude);
            const lng = Number(result.data.longitude);
            latitude.textContent = lat.toFixed(6);
            longitude.textContent = lng.toFixed(6);
            mapLink.href = `https://www.google.com/maps?q=${encodeURIComponent(`${lat},${lng}`)}`;
            mapLink.setAttribute('aria-disabled', 'false');

            const updatedAtValue = String(result.data.updated_at);
            const updatedAt = new Date(updatedAtValue.endsWith('Z') ? updatedAtValue : `${updatedAtValue}Z`);
            const ageSeconds = Math.max(0, Math.floor((Date.now() - updatedAt.getTime()) / 1000));
            const freshness = ageSeconds > 60
                ? `La última ubicación se recibió hace ${Math.floor(ageSeconds / 60)} min; puede no ser actual.`
                : `Última actualización: ${updatedAt.toLocaleTimeString('es-MX')}.`;
            updated.textContent = freshness;
            status.textContent = ageSeconds > 60
                ? 'El enlace sigue activo, pero el dispositivo no ha enviado una ubicación reciente.'
                : 'SOS activo · consultando ubicación en vivo cada 5 segundos.';
        } catch (error) {
            status.textContent = 'No se pudo actualizar la ubicación. Se conserva la última posición recibida.';
            console.error('Error al consultar ubicación SOS:', error);
        }
    }

    refreshLocation();
    pollTimer = window.setInterval(refreshLocation, 5000);
})();
