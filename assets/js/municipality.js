'use strict';

(() => {
    function getPolygons(feature) {
        const geometry = feature && feature.geometry;
        if (!geometry || !['Polygon', 'MultiPolygon'].includes(geometry.type)) {
            throw new Error('El límite municipal no tiene una geometría válida.');
        }

        const polygons = geometry.type === 'Polygon' ? [geometry.coordinates] : geometry.coordinates;
        if (
            !Array.isArray(polygons)
            || polygons.length === 0
            || polygons.some(rings => !Array.isArray(rings) || rings.length === 0
                || rings.some(ring => !Array.isArray(ring) || ring.length < 4
                    || ring.some(point => !Array.isArray(point)
                        || !Number.isFinite(Number(point[0]))
                        || !Number.isFinite(Number(point[1])))))
        ) {
            throw new Error('El límite municipal no tiene coordenadas válidas.');
        }
        return polygons;
    }

    async function load(url) {
        const response = await fetch(url, { cache: 'no-cache' });
        const feature = await response.json();
        if (!response.ok || !feature || feature.type !== 'Feature') {
            throw new Error('No se pudo cargar el límite municipal de General Escobedo.');
        }
        getPolygons(feature);
        return feature;
    }

    function isInsideRing(latitude, longitude, ring) {
        let inside = false;
        for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
            const [x1, y1] = ring[j];
            const [x2, y2] = ring[i];
            const cross = (longitude - x1) * (y2 - y1) - (latitude - y1) * (x2 - x1);
            if (
                Math.abs(cross) < 1e-10
                && longitude >= Math.min(x1, x2) - 1e-10
                && longitude <= Math.max(x1, x2) + 1e-10
                && latitude >= Math.min(y1, y2) - 1e-10
                && latitude <= Math.max(y1, y2) + 1e-10
            ) {
                return true;
            }

            if ((y1 > latitude) !== (y2 > latitude)) {
                const crossingLongitude = ((x2 - x1) * (latitude - y1)) / (y2 - y1) + x1;
                if (longitude < crossingLongitude) inside = !inside;
            }
        }
        return inside;
    }

    function contains(feature, latitude, longitude) {
        const lat = Number(latitude);
        const lng = Number(longitude);
        if (
            !feature
            || !Number.isFinite(lat)
            || !Number.isFinite(lng)
            || lat < -90 || lat > 90
            || lng < -180 || lng > 180
        ) {
            return false;
        }

        for (const rings of getPolygons(feature)) {
            if (!isInsideRing(lat, lng, rings[0])) continue;
            if (!rings.slice(1).some(ring => isInsideRing(lat, lng, ring))) return true;
        }
        return false;
    }

    function addMapBoundary(map, feature) {
        const boundsLayer = L.geoJSON(feature);
        const bounds = boundsLayer.getBounds();
        if (!bounds.isValid()) throw new Error('El límite municipal no contiene un área visible.');

        const outerBounds = bounds.pad(1);
        const southWest = outerBounds.getSouthWest();
        const northEast = outerBounds.getNorthEast();
        const outerRing = [
            [southWest.lat, southWest.lng],
            [southWest.lat, northEast.lng],
            [northEast.lat, northEast.lng],
            [northEast.lat, southWest.lng],
            [southWest.lat, southWest.lng]
        ];
        const boundaryRings = getPolygons(feature).flatMap(rings =>
            rings.map(ring => ring.map(([longitude, latitude]) => [latitude, longitude]))
        );

        map.createPane('escobedoMaskPane');
        map.getPane('escobedoMaskPane').style.zIndex = '250';
        map.getPane('escobedoMaskPane').style.pointerEvents = 'none';
        map.createPane('escobedoBoundaryPane');
        map.getPane('escobedoBoundaryPane').style.zIndex = '660';
        map.getPane('escobedoBoundaryPane').style.pointerEvents = 'none';

        L.polygon([outerRing, ...boundaryRings], {
            pane: 'escobedoMaskPane',
            color: '#f4f2ee',
            weight: 0,
            fillColor: '#f4f2ee',
            fillOpacity: 0.72,
            fillRule: 'evenodd',
            interactive: false
        }).addTo(map);

        L.geoJSON(feature, {
            pane: 'escobedoBoundaryPane',
            style: { color: '#6b1426', weight: 3, fill: false },
            interactive: false
        }).addTo(map);

        map.setMinZoom(map.getBoundsZoom(bounds));
        map.fitBounds(bounds);
        map.setMaxBounds(bounds);
        return bounds;
    }

    window.RSEscobedoBoundary = Object.freeze({ load, contains, addMapBoundary });
})();
