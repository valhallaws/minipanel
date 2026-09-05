import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

const checksFor = (checkId) => {
    const payload = document.querySelector(`[data-dns-map-payload="${checkId}"]`);

    return payload ? JSON.parse(atob(payload.dataset.checks)) : [];
};

const updateMarkers = (mapElement, checkId) => {
    const mapState = mapElement?.__dnsMap;

    if (!mapState) return;

    mapState.markers.clearLayers();

    checksFor(checkId)
        .filter((check) => Number.isFinite(Number(check.latitude)) && Number.isFinite(Number(check.longitude)))
        .forEach((check) => {
            const hasTarget = check.expected_target !== null;
            const resolved = hasTarget ? check.matches_expected : check.status_code === 'NOERROR' && check.answer;
            const color = check.status === 'in-progress' ? '#b7791f' : resolved ? '#16734f' : '#bb3030';
            const marker = L.circleMarker([Number(check.latitude), Number(check.longitude)], {
                radius: 8, color, fillColor: color, fillOpacity: 0.85, weight: 2,
            });

            marker.bindPopup(`<strong>${escapeHtml(check.location)}</strong><br>${escapeHtml(check.answer || check.status_code || check.error || 'Sin respuesta')}<br><small>${escapeHtml(check.network || '')}</small>`);
            mapState.markers.addLayer(marker);
        });
};

window.initDnsMap = (mapElement, checkId) => {
    if (mapElement.__dnsMap) {
        updateMarkers(mapElement, checkId);
        return;
    }

    const map = L.map(mapElement, { scrollWheelZoom: false }).setView([18, -20], 2);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19, attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);

    mapElement.__dnsMap = { map, markers: L.layerGroup().addTo(map) };
    updateMarkers(mapElement, checkId);
    setTimeout(() => map.invalidateSize(), 0);
};

document.addEventListener('livewire:init', () => {
    window.Livewire.hook('morph.updated', ({ el }) => {
        if (!el.matches?.('[data-dns-map-payload]')) return;

        const mapElement = document.querySelector(`[data-dns-map-for="${el.dataset.dnsMapPayload}"] .dns-map-canvas`);
        updateMarkers(mapElement, el.dataset.dnsMapPayload);
    });
});
