/* ============================================================
   Mobilité Stéphanoise — front-end controller
   ============================================================ */

const SAINT_ETIENNE = [45.4397, 4.3872];

const state = {
    map: null,
    areaLayer: null,
    velivertLayer: null,
    selectedArea: null,
    refreshTimer: null,
};

/* ---------- Map bootstrap ---------- */
function initMap() {
    state.map = L.map('map', { zoomControl: true, attributionControl: false })
        .setView(SAINT_ETIENNE, 14);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
    }).addTo(state.map);

    state.areaLayer = L.layerGroup().addTo(state.map);
    state.velivertLayer = L.layerGroup().addTo(state.map);

    state.map.on('moveend', refreshAreasInView);
}

/* ---------- Stop areas ---------- */
async function refreshAreasInView() {
    if (state.map.getZoom() < 14) {
        state.areaLayer.clearLayers();
        return;
    }
    const b = state.map.getBounds();
    const url = `/api/areas/in-bbox?minLat=${b.getSouth()}&maxLat=${b.getNorth()}&minLon=${b.getWest()}&maxLon=${b.getEast()}`;
    const res = await fetch(url);
    if (!res.ok) return;
    const { results } = await res.json();

    state.areaLayer.clearLayers();
    for (const a of results) {
        const marker = L.marker([a.lat, a.lon], {
            icon: L.divIcon({ className: 'area-marker', iconSize: [14, 14] }),
        }).bindTooltip(a.name, { direction: 'top' });
        marker.on('click', () => loadDepartures(a));
        state.areaLayer.addLayer(marker);
    }
}

async function loadDepartures(area) {
    state.selectedArea = area;
    document.getElementById('board-hint').textContent = `→ ${area.name}`;
    const list = document.getElementById('board-list');
    list.innerHTML = '<li class="dep" style="opacity:.5">Chargement…</li>';

    const res = await fetch(`/api/areas/${encodeURIComponent(area.id)}/departures?window=90&limit=15`);
    if (!res.ok) {
        list.innerHTML = '<li class="dep" style="color:var(--danger)">Erreur de chargement</li>';
        return;
    }
    const { departures } = await res.json();

    if (departures.length === 0) {
        list.innerHTML = '<li class="dep" style="color:var(--ink-dim)">Plus de passage dans les 90 prochaines minutes</li>';
        return;
    }

    list.innerHTML = '';
    const tpl = document.getElementById('tpl-departure');
    for (const d of departures) {
        const li = tpl.content.firstElementChild.cloneNode(true);
        const route = li.querySelector('[data-route]');
        route.textContent = d.routeShortName ?? '·';
        route.dataset.type = d.routeTypeLabel;
        if (d.routeColor) {
            route.style.background = d.routeColor;
            route.style.color = '#111';
        }
        li.querySelector('[data-sign]').textContent = d.direction_label ?? d.headsign ?? '';
        li.querySelector('[data-time]').textContent = d.scheduledTime;
        const eta = li.querySelector('[data-eta]');
        eta.textContent = d.minutesUntil === 0 ? 'à quai' : `${d.minutesUntil}′`;
        if (d.minutesUntil <= 3) eta.dataset.soon = 'true';
        list.appendChild(li);
    }

    state.map.setView([area.lat, area.lon], Math.max(state.map.getZoom(), 15));

    clearTimeout(state.refreshTimer);
    state.refreshTimer = setTimeout(() => state.selectedArea && loadDepartures(state.selectedArea), 30_000);
}

/* ---------- Search ---------- */
function initSearch() {
    const input = document.getElementById('stop-search');
    const results = document.getElementById('search-results');
    let timer;

    input.addEventListener('input', () => {
        clearTimeout(timer);
        const q = input.value.trim();
        if (q.length < 2) {
            results.hidden = true;
            return;
        }
        timer = setTimeout(async () => {
            const res = await fetch(`/api/areas/search?q=${encodeURIComponent(q)}`);
            if (!res.ok) return;
            const { results: areas } = await res.json();
            results.innerHTML = '';
            for (const a of areas) {
                const li = document.createElement('li');
                li.textContent = a.name;
                li.addEventListener('click', () => {
                    loadDepartures(a);
                    results.hidden = true;
                    input.value = a.name;
                });
                results.appendChild(li);
            }
            results.hidden = areas.length === 0;
        }, 200);
    });

    document.addEventListener('click', e => {
        if (!e.target.closest('.search')) results.hidden = true;
    });
}

/* ---------- Vélivert ---------- */
async function refreshVelivert() {
    const res = await fetch('/api/velivert/stations');
    if (!res.ok) return;
    const { stations } = await res.json();

    state.velivertLayer.clearLayers();
    let totalBikes = 0;
    let totalDocks = 0;

    for (const s of stations) {
        totalBikes += s.bikes;
        totalDocks += s.docks;

        const empty = s.bikes === 0;
        const full = s.docks === 0 && s.capacity > 0;
        const offline = !s.operational;

        const classes = ['velivert-marker'];
        if (offline) classes.push('is-offline');
        else if (empty) classes.push('is-empty');
        else if (full) classes.push('is-full');

        const icon = L.divIcon({
            className: classes.join(' '),
            html: `<span>${s.bikes}</span>`,
            iconSize: [28, 28],
        });

        const marker = L.marker([s.lat, s.lon], { icon })
            .bindPopup(velivertPopup(s));

        state.velivertLayer.addLayer(marker);
    }

    document.getElementById('velivert-summary').textContent =
        `Vélivert : ${totalBikes} vélos / ${totalDocks} places sur ${stations.length} stations`;
}

function velivertPopup(s) {
    return `
        <span class="popup-title">${escapeHtml(s.name)}</span>
        <div class="popup-row"><span>Vélos disponibles</span><strong>${s.bikes}</strong></div>
        <div class="popup-row"><span>Places libres</span><strong>${s.docks}</strong></div>
        <div class="popup-row"><span>Capacité</span><strong>${s.capacity}</strong></div>
        <div class="popup-row"><span>État</span><strong>${s.operational ? 'opérationnelle' : 'hors service'}</strong></div>
    `;
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
}

/* ---------- Clock ---------- */
function initClock() {
    const el = document.getElementById('clock');
    const tick = () => {
        const d = new Date();
        el.textContent = d.toLocaleTimeString('fr-FR', { hour12: false });
    };
    tick();
    setInterval(tick, 1000);
}

/* ---------- Bootstrap ---------- */
document.addEventListener('DOMContentLoaded', () => {
    initMap();
    initSearch();
    initClock();
    refreshAreasInView();
    refreshVelivert();
    setInterval(refreshVelivert, 60_000);
});
