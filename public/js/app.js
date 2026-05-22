/* ============================================================
   Mobilité Stéphanoise — carte (front)
   Markers mode-aware (tram / bus / vélivert) + bottom sheet mobile.
   ============================================================ */

const SAINT_ETIENNE = [45.4397, 4.3872];
const CHIP_ZOOM_THRESHOLD = 16;          // ≥ ce zoom → chips détaillés, sinon dot
const MOBILE_BREAKPOINT = '(max-width: 900px)';

const state = {
    map: null,
    areaLayer: null,
    velivertLayer: null,
    selectedArea: null,
    selectionMarker: null,
    refreshTimer: null,
    layers: { tram: true, bus: true, velivert: true },
    sheet: { current: 'peek' },
    user: {
        marker: null,
        accuracyCircle: null,
        lastFix: null,         // { lat, lon, accuracy }
        watchId: null,
        firstFixHandled: false,
        userInteracted: false, // a-t-il pané/zoomé avant le 1er fix ?
    },
};

const isMobile = () => window.matchMedia(MOBILE_BREAKPOINT).matches;

/* ============================================================
   Bottom sheet (mobile)
   ============================================================ */

function setSheetState(next) {
    if (!['peek', 'half', 'full'].includes(next)) return;
    state.sheet.current = next;
    const root = document.getElementById('mp-root');
    if (root) root.dataset.sheetState = next;
}

function initSheet() {
    const root = document.getElementById('mp-root');
    const aside = document.getElementById('mp-aside');
    const handle = document.getElementById('mp-sheet-handle');
    const fab = document.getElementById('mp-fab');
    const scrim = document.getElementById('mp-scrim');
    if (!root || !aside || !handle) return;

    setSheetState('peek');

    fab?.addEventListener('click', () => setSheetState('half'));
    scrim?.addEventListener('click', () => setSheetState('peek'));

    // Drag — pointer events, capture so we keep tracking outside the handle
    let pointerId = null;
    let startY = 0;
    let startTranslate = 0;
    let currentTranslate = 0;
    let lastY = 0;
    let lastT = 0;
    let velocity = 0;

    const computePx = (state) => {
        const h = aside.getBoundingClientRect().height;
        const peek = parseInt(getComputedStyle(document.documentElement)
            .getPropertyValue('--sheet-peek')) || 160;
        if (state === 'peek') return h - peek;
        if (state === 'half') return h - h * 0.62;
        return 0;
    };

    const onDown = (e) => {
        if (!isMobile()) return;
        pointerId = e.pointerId;
        handle.setPointerCapture(pointerId);
        startY = e.clientY;
        lastY = e.clientY;
        lastT = performance.now();
        velocity = 0;
        startTranslate = computePx(state.sheet.current);
        currentTranslate = startTranslate;
        root.dataset.dragging = 'true';
        aside.style.transform = `translateY(${startTranslate}px)`;
    };

    const onMove = (e) => {
        if (pointerId === null) return;
        const now = performance.now();
        const dt = Math.max(1, now - lastT);
        velocity = (e.clientY - lastY) / dt;     // px/ms
        lastY = e.clientY;
        lastT = now;

        const dy = e.clientY - startY;
        const h = aside.getBoundingClientRect().height;
        currentTranslate = Math.max(0, Math.min(h - 60, startTranslate + dy));
        aside.style.transform = `translateY(${currentTranslate}px)`;
    };

    const onUp = () => {
        if (pointerId === null) return;
        try { handle.releasePointerCapture(pointerId); } catch (_) {}
        pointerId = null;
        delete root.dataset.dragging;

        const snaps = [
            { name: 'peek', y: computePx('peek') },
            { name: 'half', y: computePx('half') },
            { name: 'full', y: computePx('full') },
        ];

        let target;
        if (velocity < -0.5) {
            // Flick up : on monte d'un cran (snap au-dessus le plus proche)
            const above = snaps.filter(s => s.y < currentTranslate - 1);
            target = above.length
                ? above.reduce((a, s) => (s.y > a.y ? s : a))
                : snaps.reduce((a, s) => (s.y < a.y ? s : a));
        } else if (velocity > 0.5) {
            // Flick down : on descend d'un cran (snap en-dessous le plus proche)
            const below = snaps.filter(s => s.y > currentTranslate + 1);
            target = below.length
                ? below.reduce((a, s) => (s.y < a.y ? s : a))
                : snaps.reduce((a, s) => (s.y > a.y ? s : a));
        } else {
            // Settle au plus proche
            target = snaps.reduce((a, s) =>
                Math.abs(s.y - currentTranslate) < Math.abs(a.y - currentTranslate) ? s : a
            );
        }

        aside.style.transform = '';   // remettre la main au CSS / data-sheet-state
        setSheetState(target.name);
    };

    handle.addEventListener('pointerdown', onDown);
    handle.addEventListener('pointermove', onMove);
    handle.addEventListener('pointerup', onUp);
    handle.addEventListener('pointercancel', onUp);

    // Tap sur la poignée (pas de drag) = toggle peek ↔ half
    handle.addEventListener('click', (e) => {
        // ignore les clicks générés par un drag (le drag a déjà set le state)
        if (Math.abs(currentTranslate - startTranslate) > 4) return;
        if (state.sheet.current === 'peek') setSheetState('half');
        else if (state.sheet.current === 'half') setSheetState('full');
        else setSheetState('peek');
    });

    // Quand la search prend le focus on remonte automatiquement
    document.getElementById('stop-search')?.addEventListener('focus', () => {
        if (isMobile() && state.sheet.current === 'peek') setSheetState('half');
    });
}

/* ============================================================
   Marker building — mode-aware
   ============================================================ */

function passesLayerFilter(area) {
    const modes = area.modes ?? [];
    const hasTram = modes.includes('tram');
    const hasBus  = modes.includes('bus') || modes.includes('trolley');
    if (hasTram && state.layers.tram) return true;
    if (hasBus  && state.layers.bus)  return true;
    if (!hasTram && !hasBus) return state.layers.tram || state.layers.bus;
    return false;
}

function dotMode(area) {
    const modes = area.modes ?? [];
    const hasTram = modes.includes('tram');
    const hasBus  = modes.includes('bus') || modes.includes('trolley');
    if (hasTram && hasBus) return 'multi';
    if (hasTram) return 'tram';
    if (modes.includes('trolley') && !modes.includes('bus')) return 'trolley';
    if (hasBus) return 'bus';
    return null;
}

function chipHtml(route) {
    const t = route.type;
    const cls = t === 'tram' ? 'mp-marker__chip--tram'
              : t === 'trolley' ? 'mp-marker__chip--trolley'
              : 'mp-marker__chip--bus';
    const label = route.short_name ?? '·';
    return `<span class="mp-marker__chip ${cls}">${escapeHtml(label)}</span>`;
}

function buildAreaIcon(area, zoom) {
    const mode = dotMode(area);
    if (!mode) return null;

    if (zoom < CHIP_ZOOM_THRESHOLD) {
        return L.divIcon({
            className: 'mp-marker mp-marker--dot',
            html: `<span class="mp-marker__dot mp-marker__dot--${mode}"></span>`,
            iconSize: [16, 16],
        });
    }

    const routes = area.routes ?? [];
    const MAX = 3;
    const overflow = Math.max(0, routes.length - MAX);
    let html = routes.slice(0, MAX).map(chipHtml).join('');
    if (overflow > 0) {
        html += `<span class="mp-marker__chip mp-marker__chip--more">+${overflow}</span>`;
    }
    // Si pas de routes, fallback dot
    if (!html) {
        return L.divIcon({
            className: 'mp-marker mp-marker--dot',
            html: `<span class="mp-marker__dot mp-marker__dot--${mode}"></span>`,
            iconSize: [16, 16],
        });
    }
    return L.divIcon({
        className: 'mp-marker',
        html,
        iconSize: null,
    });
}

/* ============================================================
   Selection pin (vert ASSE — ne conflicte plus avec le rouge tram)
   ============================================================ */
function buildSelectionIcon() {
    return L.divIcon({
        className: 'mp-selection-pin',
        html: `
            <svg viewBox="0 0 24 36" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M12 0C5.4 0 0 5.4 0 12c0 9 12 24 12 24s12-15 12-24c0-6.6-5.4-12-12-12z"
                      fill="#14a05e" stroke="rgba(0,0,0,.4)" stroke-width="0.6"/>
                <circle cx="12" cy="12" r="4.4" fill="#fff"/>
            </svg>`,
        iconSize: [34, 50],
        iconAnchor: [17, 50],
        tooltipAnchor: [0, -46],
    });
}

function setSelectionPin(area) {
    if (!state.map || area?.lat == null || area?.lon == null) return;
    if (state.selectionMarker) state.map.removeLayer(state.selectionMarker);
    state.selectionMarker = L.marker([area.lat, area.lon], {
        icon: buildSelectionIcon(),
        interactive: false,
        keyboard: false,
        zIndexOffset: 1000,
    }).addTo(state.map);
}

/* ============================================================
   Géolocalisation utilisateur — point bleu + recentrage
   ============================================================ */
function buildUserIcon() {
    return L.divIcon({
        className: 'mp-user-dot',
        html: `<span class="mp-user-dot__core"></span>`,
        iconSize: [22, 22],
        iconAnchor: [11, 11],
    });
}

function setLocateBtnState(s) {
    const btn = document.getElementById('mp-locate-btn');
    if (btn) btn.dataset.state = s;
}

function updateUserMarker(lat, lon, accuracy) {
    if (!state.map) return;

    if (!state.user.marker) {
        state.user.marker = L.marker([lat, lon], {
            icon: buildUserIcon(),
            interactive: false,
            keyboard: false,
            zIndexOffset: 1100,
        }).addTo(state.map);
    } else {
        state.user.marker.setLatLng([lat, lon]);
    }

    const radius = Math.max(5, Math.min(500, accuracy || 30));
    if (!state.user.accuracyCircle) {
        state.user.accuracyCircle = L.circle([lat, lon], {
            radius,
            interactive: false,
            color: '#1a8cff',
            fillColor: '#1a8cff',
            fillOpacity: 0.12,
            opacity: 0.55,
            weight: 1,
        }).addTo(state.map);
    } else {
        state.user.accuracyCircle.setLatLng([lat, lon]);
        state.user.accuracyCircle.setRadius(radius);
    }
}

function recenterOnUser({ zoom } = {}) {
    const fix = state.user.lastFix;
    if (!fix || !state.map) return;
    const z = zoom ?? Math.max(state.map.getZoom(), 16);
    state.map.setView([fix.lat, fix.lon], z, { animate: true });
}

function initGeolocation() {
    const btn = document.getElementById('mp-locate-btn');

    if (!('geolocation' in navigator)) {
        setLocateBtnState('error');
        btn?.setAttribute('title', 'Géolocalisation non supportée');
        return;
    }

    // Si l'utilisateur pan/zoom avant le premier fix, on ne fera pas d'auto-zoom
    state.map.on('dragstart', () => { state.user.userInteracted = true; });
    state.map.on('zoomstart', (e) => {
        // les zooms programmatiques (recenterOnUser) déclenchent aussi zoomstart,
        // mais arrivent APRÈS firstFixHandled donc ce flag est sans effet à ce moment-là
        if (state.user.firstFixHandled) return;
        state.user.userInteracted = true;
    });

    setLocateBtnState('seeking');

    state.user.watchId = navigator.geolocation.watchPosition(
        (pos) => {
            const { latitude: lat, longitude: lon, accuracy } = pos.coords;
            state.user.lastFix = { lat, lon, accuracy };
            updateUserMarker(lat, lon, accuracy);
            setLocateBtnState('active');

            if (!state.user.firstFixHandled) {
                state.user.firstFixHandled = true;
                if (!state.user.userInteracted) {
                    state.map.setView([lat, lon], 16, { animate: true });
                }
            }
        },
        (err) => {
            // Permission refusée ou position indisponible — on reste sur Saint-Étienne
            console.warn('[geo]', err.message);
            setLocateBtnState(err.code === err.PERMISSION_DENIED ? 'idle' : 'error');
        },
        {
            enableHighAccuracy: true,
            maximumAge: 15_000,
            timeout: 20_000,
        }
    );

    // Click → recentre sur la dernière position connue, ou relance une requête si rien
    btn?.addEventListener('click', () => {
        if (state.user.lastFix) {
            recenterOnUser();
            return;
        }
        setLocateBtnState('seeking');
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                const { latitude: lat, longitude: lon, accuracy } = pos.coords;
                state.user.lastFix = { lat, lon, accuracy };
                updateUserMarker(lat, lon, accuracy);
                setLocateBtnState('active');
                state.map.setView([lat, lon], 16, { animate: true });
            },
            (err) => {
                console.warn('[geo]', err.message);
                setLocateBtnState(err.code === err.PERMISSION_DENIED ? 'idle' : 'error');
            },
            { enableHighAccuracy: true, timeout: 15_000 }
        );
    });
}

/* ============================================================
   Map bootstrap
   ============================================================ */
function initMap() {
    state.map = L.map('map', {
        zoomControl: false,
        attributionControl: true,
        zoomSnap: 0.5,
    }).setView(SAINT_ETIENNE, 14);

    L.control.zoom({ position: 'bottomright' }).addTo(state.map);

    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_nolabels/{z}/{x}/{y}{r}.png', {
        maxZoom: 19,
        subdomains: 'abcd',
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OSM</a> · &copy; <a href="https://carto.com/attributions">Carto</a>',
    }).addTo(state.map);

    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_only_labels/{z}/{x}/{y}{r}.png', {
        maxZoom: 19,
        subdomains: 'abcd',
        pane: 'shadowPane',
        opacity: 0.85,
    }).addTo(state.map);

    state.areaLayer = L.layerGroup().addTo(state.map);
    state.velivertLayer = L.layerGroup().addTo(state.map);

    state.map.on('moveend', refreshAreasInView);

    // Tap sur la carte (hors marker) → on rabaisse la feuille
    state.map.on('click', () => {
        if (isMobile() && state.sheet.current !== 'peek') setSheetState('peek');
    });
}

/* ============================================================
   Stop areas
   ============================================================ */
async function refreshAreasInView() {
    if (!state.layers.tram && !state.layers.bus) {
        state.areaLayer.clearLayers();
        return;
    }
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
    const zoom = state.map.getZoom();
    for (const a of results) {
        if (!passesLayerFilter(a)) continue;
        const icon = buildAreaIcon(a, zoom);
        if (!icon) continue;

        const lines = (a.routes ?? []).map(r => r.short_name).filter(Boolean).join(' · ');
        const tooltip = lines ? `${a.name} — ${lines}` : a.name;

        const marker = L.marker([a.lat, a.lon], { icon })
            .bindTooltip(tooltip, { direction: 'top', offset: [0, -8] });
        marker.on('click', () => {
            loadDepartures(a);
            if (isMobile()) setSheetState('half');
        });
        state.areaLayer.addLayer(marker);
    }
}

function setBoardHint(text, fresh = false) {
    const el = document.getElementById('board-hint');
    if (!el) return;
    el.textContent = text;
    el.style.color = fresh ? 'var(--asse-bright)' : '';
}

async function loadDepartures(area) {
    state.selectedArea = area;
    setSelectionPin(area);
    setBoardHint(`→ ${area.name}`, true);
    const list = document.getElementById('board-list');
    list.innerHTML = '<li class="mp-dep--loading">Chargement…</li>';

    const res = await fetch(`/api/areas/${encodeURIComponent(area.id)}/departures?window=90&limit=15`);
    if (!res.ok) {
        list.innerHTML = '<li class="mp-dep--error">Erreur de chargement</li>';
        return;
    }
    const { departures } = await res.json();

    if (departures.length === 0) {
        list.innerHTML = '<li class="mp-dep--empty">Plus de passage dans les 90 prochaines minutes</li>';
        return;
    }

    list.innerHTML = '';
    const tpl = document.getElementById('tpl-departure');
    for (const d of departures) {
        const li = tpl.content.firstElementChild.cloneNode(true);
        const route = li.querySelector('[data-route]');
        route.textContent = d.routeShortName ?? '·';
        route.dataset.type = d.routeTypeLabel;
        // On laisse la palette CSS appliquer le code STAS officiel,
        // les couleurs GTFS bizarres (orange tram, etc.) sont écrasées.
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

/* ============================================================
   Search
   ============================================================ */
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
                li.setAttribute('role', 'option');
                li.addEventListener('click', () => {
                    loadDepartures(a);
                    results.hidden = true;
                    input.value = a.name;
                    if (isMobile()) setSheetState('half');
                });
                results.appendChild(li);
            }
            results.hidden = areas.length === 0;
        }, 200);
    });

    document.addEventListener('click', e => {
        if (!e.target.closest('.mp-search')) results.hidden = true;
    });
}

/* ============================================================
   Vélivert
   ============================================================ */
async function refreshVelivert() {
    if (!state.layers.velivert) {
        state.velivertLayer.clearLayers();
        const sum = document.getElementById('velivert-summary');
        if (sum) sum.textContent = 'Vélivert : couche désactivée';
        return;
    }

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

        const classes = ['mp-marker__chip', 'mp-marker__chip--velivert'];
        if (offline) classes.push('is-offline');
        else if (empty) classes.push('is-empty');
        else if (full) classes.push('is-full');

        const icon = L.divIcon({
            className: 'mp-marker',
            html: `<span class="${classes.join(' ')}">${s.bikes}</span>`,
            iconSize: null,
        });

        const marker = L.marker([s.lat, s.lon], { icon })
            .bindPopup(velivertPopup(s))
            .bindTooltip(s.name, { direction: 'top', offset: [0, -14] });

        state.velivertLayer.addLayer(marker);
    }

    const sum = document.getElementById('velivert-summary');
    if (sum) {
        sum.textContent = `Vélivert · ${totalBikes} vélos / ${totalDocks} places · ${stations.length} stations`;
    }
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

/* ============================================================
   Layer toggles (Tram · Bus · Vélivert)
   ============================================================ */
function initLayerToggles() {
    document.querySelectorAll('.mp-pill[data-layer]').forEach(pill => {
        pill.addEventListener('click', () => {
            const layer = pill.dataset.layer;
            const isOn = !pill.classList.contains('is-on');
            pill.classList.toggle('is-on', isOn);
            pill.setAttribute('aria-pressed', String(isOn));

            if (layer === 'tram' || layer === 'bus') {
                state.layers[layer] = isOn;
                refreshAreasInView();
            } else if (layer === 'velivert') {
                state.layers.velivert = isOn;
                refreshVelivert();
            }
        });
    });
}

/* ============================================================
   Bootstrap
   ============================================================ */
document.addEventListener('DOMContentLoaded', () => {
    initMap();
    initSearch();
    initLayerToggles();
    initSheet();
    initGeolocation();
    refreshAreasInView();
    refreshVelivert();
    setInterval(refreshVelivert, 60_000);

    // Re-init du sheet quand on bascule mobile ↔ desktop (orientation, resize)
    window.matchMedia(MOBILE_BREAKPOINT).addEventListener('change', () => {
        if (!isMobile()) {
            const root = document.getElementById('mp-root');
            const aside = document.getElementById('mp-aside');
            if (root) delete root.dataset.dragging;
            if (aside) aside.style.transform = '';
        } else {
            setSheetState(state.sheet.current);
        }
    });
});
