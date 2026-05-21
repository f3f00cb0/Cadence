/* ============================================================
   Mobilité Stéphanoise — board mode controller
   vanilla ES module — no framework
   ============================================================ */

const SAINT_ETIENNE = { lat: 45.4397, lon: 4.3872 };
const FAV_KEY = 'mobilite.favorites.v1';
const SETTINGS_KEY = 'mobilite.settings.v1';
const GEO_CACHE_MS = 5 * 60_000;
const MAX_FAVORITES = 20;
const PULL_THRESHOLD = 80;
const SEARCH_DEBOUNCE_MS = 200;
const SEARCH_MAX_RESULTS = 8;
const SUGGESTED_CHIPS = ['Châteaucreux', 'Hôtel de Ville', 'Bellevue'];

const $ = (id) => document.getElementById(id);
const escapeHtml = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
}[c]));

const state = {
    position: null,
    nearbyAreas: [],
    favorites: loadFavorites(),
    settings: loadSettings(),
    sheetArea: null,
    sheetFilter: 'all',
    lastSync: null,
    syncTimer: null,
};

/* ---------- Persistence ---------- */
function loadFavorites() {
    try {
        const raw = localStorage.getItem(FAV_KEY);
        return raw ? JSON.parse(raw) : [];
    } catch { return []; }
}

function saveFavorites() {
    try { localStorage.setItem(FAV_KEY, JSON.stringify(state.favorites)); } catch {}
}

function loadSettings() {
    try {
        const raw = localStorage.getItem(SETTINGS_KEY);
        return Object.assign(
            { allowGeoloc: true, showTram: true, showBus: true, showVelo: true },
            raw ? JSON.parse(raw) : {},
        );
    } catch {
        return { allowGeoloc: true, showTram: true, showBus: true, showVelo: true };
    }
}

function saveSettings() {
    try { localStorage.setItem(SETTINGS_KEY, JSON.stringify(state.settings)); } catch {}
}

/* ---------- Geolocation ---------- */
function getPosition() {
    if (state.position && Date.now() - state.position.at < GEO_CACHE_MS) {
        return Promise.resolve(state.position);
    }
    if (!state.settings.allowGeoloc || !('geolocation' in navigator)) {
        return Promise.reject(new Error('geoloc-disabled'));
    }
    return new Promise((resolve, reject) => {
        navigator.geolocation.getCurrentPosition(
            (p) => {
                state.position = { lat: p.coords.latitude, lon: p.coords.longitude, at: Date.now() };
                resolve(state.position);
            },
            reject,
            { enableHighAccuracy: false, maximumAge: GEO_CACHE_MS, timeout: 8000 },
        );
    });
}

async function geocodeFallback(query) {
    const url = `https://nominatim.openstreetmap.org/search?format=json&limit=1&q=${encodeURIComponent(query + ', Saint-Étienne, France')}`;
    try {
        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
        if (!res.ok) return null;
        const arr = await res.json();
        if (!Array.isArray(arr) || arr.length === 0) return null;
        return { lat: parseFloat(arr[0].lat), lon: parseFloat(arr[0].lon), at: Date.now() };
    } catch { return null; }
}

/* ---------- Header / clock ---------- */
const FR_DAYS = ['DIM', 'LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM'];
const FR_MONTHS = ['JANV', 'FÉVR', 'MARS', 'AVR', 'MAI', 'JUIN', 'JUIL', 'AOÛT', 'SEPT', 'OCT', 'NOV', 'DÉC'];

function tickClock() {
    const d = new Date();
    const hh = String(d.getHours()).padStart(2, '0');
    const mm = String(d.getMinutes()).padStart(2, '0');
    const ss = String(d.getSeconds()).padStart(2, '0');
    const clockEl = $('bd-clock');
    if (clockEl) clockEl.innerHTML = `${hh}:${mm}<span class="head__clock-sec">:${ss}</span>`;
    const dateEl = $('bd-date');
    if (dateEl) dateEl.textContent = `${FR_DAYS[d.getDay()]} · ${d.getDate()} ${FR_MONTHS[d.getMonth()]}`;
}

function updateSyncLabel() {
    const el = $('bd-sync');
    if (!el) return;
    if (!state.lastSync) {
        el.textContent = '— jamais synchronisé';
        el.dataset.fresh = 'false';
        return;
    }
    const ago = Math.round((Date.now() - state.lastSync) / 1000);
    el.dataset.fresh = ago < 30 ? 'true' : 'false';
    el.textContent = ago < 5 ? '✓ TEMPS RÉEL'
                  : ago < 60 ? `✓ ${ago}s`
                  : `${Math.floor(ago / 60)} MIN`;
}

/* ---------- Data fetchers ---------- */
async function fetchNearbyAreas(lat, lon) {
    const res = await fetch(`/api/areas/nearby?lat=${lat}&lon=${lon}&limit=3&radius=2000`);
    if (!res.ok) return [];
    const { results } = await res.json();
    return results;
}

async function fetchBatchDepartures(ids) {
    if (ids.length === 0) return {};
    const res = await fetch('/api/areas/batch-departures', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ids, window: 50, limit: 4 }),
    });
    if (!res.ok) return {};
    const { results } = await res.json();
    return results;
}

async function fetchNearbyVelivert(lat, lon) {
    const res = await fetch(`/api/velivert/nearby?lat=${lat}&lon=${lon}&limit=4&radius=3000`);
    if (!res.ok) return [];
    const { results } = await res.json();
    return results;
}

async function fetchAreaDetails(id, window = 90, limit = 20) {
    const res = await fetch(`/api/areas/${encodeURIComponent(id)}/departures?window=${window}&limit=${limit}`);
    if (!res.ok) return null;
    return await res.json();
}

async function searchAreas(query) {
    if (!query || query.length < 2) return [];
    const res = await fetch(`/api/areas/search?q=${encodeURIComponent(query)}`);
    if (!res.ok) return [];
    const { results } = await res.json();
    return results ?? [];
}

/* ---------- Format helpers ---------- */
function formatDistance(m) {
    if (m < 1000) return `${m} m`;
    return `${(m / 1000).toFixed(1)} km`;
}

function walkMinutes(distanceM) {
    return Math.max(1, Math.round(distanceM / 80));
}

function pad2(n) { return String(n).padStart(2, '0'); }

function scheduledLabel(d) {
    if (d.scheduledTime) return `${d.scheduledTime} · prévu`;
    return '—';
}

function routeTypeAllowed(label) {
    if (label === 'tram' && !state.settings.showTram) return false;
    if ((label === 'bus' || label === 'trolley') && !state.settings.showBus) return false;
    return true;
}

/* ---------- Card rendering ---------- */
function buildAreaCard(area, departures, opts = {}) {
    const tpl = $('tpl-area-card');
    const node = tpl.content.firstElementChild.cloneNode(true);
    node.querySelector('[data-name]').textContent = area.name;

    const distance = opts.distance ?? area.distance_m;
    const sub = node.querySelector('[data-sub]');
    sub.innerHTML = '';
    const subParts = [];
    if (distance != null) {
        subParts.push(`${formatDistance(distance)}`);
        subParts.push(`${walkMinutes(distance)} min à pied`);
    }
    if (opts.extra) subParts.push(opts.extra);
    subParts.forEach((part, i) => {
        if (i > 0) {
            const sep = document.createElement('span');
            sep.className = 'card__sub-sep';
            sub.appendChild(sep);
        }
        const span = document.createElement('span');
        span.textContent = part;
        sub.appendChild(span);
    });

    const favBtn = node.querySelector('[data-card-fav]');
    const fav = isFavorite(area.id);
    favBtn.dataset.fav = fav ? 'true' : 'false';
    favBtn.textContent = fav ? '★' : '☆';
    favBtn.setAttribute('aria-label', fav ? 'Retirer des favoris' : 'Ajouter aux favoris');
    favBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        toggleFavorite(area);
        const nowFav = isFavorite(area.id);
        favBtn.dataset.fav = nowFav ? 'true' : 'false';
        favBtn.textContent = nowFav ? '★' : '☆';
    });

    const depsContainer = node.querySelector('[data-deps]');
    const filtered = (departures ?? []).filter(d => routeTypeAllowed(d.routeTypeLabel));

    if (filtered.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'dep dep--empty';
        const msg = document.createElement('span');
        msg.className = 'dep__msg';
        msg.textContent = 'Aucun passage dans les 50 prochaines minutes';
        empty.appendChild(msg);
        depsContainer.appendChild(empty);
    } else {
        const depTpl = $('tpl-area-card-dep');
        for (const d of filtered.slice(0, 4)) {
            const dep = depTpl.content.firstElementChild.cloneNode(true);
            const r = dep.querySelector('[data-route]');
            r.textContent = d.routeShortName ?? '·';
            r.dataset.type = d.routeTypeLabel;
            if (d.routeColor) { r.style.background = d.routeColor; r.style.color = '#111'; }

            const sign = dep.querySelector('[data-sign]');
            sign.textContent = d.direction_label ?? d.headsign ?? '';
            const time = document.createElement('span');
            time.className = 'dep__sign-time';
            time.textContent = scheduledLabel(d);
            sign.appendChild(time);

            const eta = dep.querySelector('[data-eta]');
            const minutes = d.minutesUntil;
            if (minutes === 0) {
                eta.textContent = 'à quai';
                eta.dataset.now = 'true';
            } else {
                eta.innerHTML = `${minutes}<span class="dep__eta-unit">′</span>`;
                if (minutes <= 3) eta.dataset.soon = 'true';
            }
            depsContainer.appendChild(dep);
        }
    }

    node.addEventListener('click', () => openAreaSheet(area));
    node.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openAreaSheet(area); }
    });
    return node;
}

async function renderNearby() {
    const section = $('bd-nearby-section');
    const cards = $('bd-nearby-cards');
    const chip = $('bd-nearby-chip');

    const position = await getPosition().catch(() => null);
    if (!position) {
        section.dataset.disabled = 'true';
        cards.innerHTML = `
            <div class="empty">
                <p class="empty__title">Position non détectée</p>
                <p class="empty__sub">Activez la géolocalisation pour voir les arrêts proches.</p>
            </div>`;
        chip.innerHTML = '<span class="section__meta-pin">⊙</span> —';
        showGeoFallback();
        return;
    }
    section.dataset.disabled = 'false';

    const areas = await fetchNearbyAreas(position.lat, position.lon);
    state.nearbyAreas = areas;
    if (areas.length === 0) {
        cards.innerHTML = `
            <div class="empty">
                <p class="empty__title">Aucun arrêt à proximité</p>
                <p class="empty__sub">Aucun quai dans un rayon de 2 km.</p>
            </div>`;
        chip.innerHTML = '<span class="section__meta-pin">⊙</span> —';
        return;
    }
    chip.innerHTML = `<span class="section__meta-pin">⊙</span> ${formatDistance(areas[0].distance_m)}`;

    const byId = await fetchBatchDepartures(areas.map(a => a.id));
    cards.innerHTML = '';
    for (const area of areas) {
        const deps = byId[area.id]?.departures ?? [];
        cards.appendChild(buildAreaCard(area, deps, { distance: area.distance_m }));
    }
}

async function renderFavorites() {
    const cards = $('bd-favorites-cards');
    const countEl = $('bd-favorites-count');
    const n = state.favorites.length;
    countEl.textContent = n === 0 ? 'AUCUN' : `${n} ÉPINGLÉ${n > 1 ? 'S' : ''}`;

    if (n === 0) {
        cards.innerHTML = `
            <div class="empty">
                <p class="empty__title">Aucun favori</p>
                <p class="empty__sub">Épinglez vos arrêts depuis l'icône <span class="empty__kbd">★</span> dans le détail.</p>
            </div>`;
        renderChips();
        return;
    }
    const ids = state.favorites.map(f => f.areaId);
    const byId = await fetchBatchDepartures(ids);
    cards.innerHTML = '';
    for (const fav of state.favorites) {
        const info = byId[fav.areaId];
        const area = info?.area ?? { id: fav.areaId, name: fav.name, lat: fav.lat, lon: fav.lon };
        const distance = state.position
            ? Math.round(haversine(state.position.lat, state.position.lon, area.lat, area.lon))
            : null;
        cards.appendChild(buildAreaCard(area, info?.departures ?? [], { distance }));
    }
    renderChips();
}

async function renderVelivert() {
    const list = $('bd-velivert-list');
    const chip = $('bd-velivert-chip');
    if (!state.settings.showVelo) {
        list.innerHTML = `
            <div class="empty">
                <p class="empty__title">Vélivert masqué</p>
                <p class="empty__sub">Activez Vélivert dans les réglages.</p>
            </div>`;
        chip.textContent = '—';
        return;
    }
    const pos = await getPosition().catch(() => null);
    if (!pos) {
        list.innerHTML = `
            <div class="empty">
                <p class="empty__title">Position non détectée</p>
                <p class="empty__sub">Activez la géolocalisation pour voir les Vélivert proches.</p>
            </div>`;
        chip.textContent = '—';
        return;
    }
    const stations = await fetchNearbyVelivert(pos.lat, pos.lon);
    if (stations.length === 0) {
        list.innerHTML = `
            <div class="empty">
                <p class="empty__title">Aucune station à proximité</p>
                <p class="empty__sub">Aucune station Vélivert dans un rayon de 3 km.</p>
            </div>`;
        chip.textContent = '0';
        return;
    }
    chip.textContent = `${stations.length} STATIONS · ${formatDistance(stations[0].distance_m)}`;

    const tpl = $('tpl-velivert-row');
    const wrap = document.createElement('div');
    wrap.className = 'velo-card';
    for (const s of stations) {
        const row = tpl.content.firstElementChild.cloneNode(true);
        row.querySelector('[data-name]').textContent = s.name;
        const ratio = s.capacity > 0 ? Math.round((s.bikes / s.capacity) * 100) : 0;
        row.querySelector('[data-meta]').textContent =
            `${formatDistance(s.distance_m)} · ${s.walk_minutes} min · ${s.bikes} / ${s.capacity}`;
        const count = row.querySelector('[data-count]');
        const fill = row.querySelector('[data-fill]');
        count.textContent = String(s.bikes);
        fill.style.width = `${ratio}%`;
        if (s.bikes === 0) {
            count.dataset.empty = 'true';
            fill.dataset.empty = 'true';
        } else if (s.bikes <= 2) {
            count.dataset.warn = 'true';
            fill.dataset.warn = 'true';
        }
        row.addEventListener('click', () => openVelivertSheet(s));
        wrap.appendChild(row);
    }
    list.innerHTML = '';
    list.appendChild(wrap);
}

/* ---------- Chips (favoris + suggestions) ---------- */
function renderChips() {
    const root = $('bd-chips');
    if (!root) return;
    root.innerHTML = '';
    const favs = state.favorites.slice(0, 5);
    const favNames = new Set(favs.map(f => f.name));

    for (const f of favs) {
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'chip';
        chip.innerHTML = `<span class="chip__star">★</span>${escapeHtml(f.name)}`;
        chip.addEventListener('click', () => openAreaSheet({ id: f.areaId, name: f.name, lat: f.lat, lon: f.lon }));
        root.appendChild(chip);
    }
    for (const name of SUGGESTED_CHIPS) {
        if (favNames.has(name)) continue;
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'chip';
        chip.textContent = name;
        chip.addEventListener('click', async () => {
            const input = $('bd-search-input');
            if (input) {
                input.value = name;
                input.dispatchEvent(new Event('input'));
                input.focus();
            }
        });
        root.appendChild(chip);
    }
}

/* ---------- Search ---------- */
function initSearch() {
    const wrap = $('bd-search-wrap');
    const input = $('bd-search-input');
    const clear = $('bd-search-clear');
    const results = $('bd-search-results');
    if (!wrap || !input || !results) return;

    let debounceTimer = null;
    let lastQuery = '';

    const closeDropdown = () => {
        results.hidden = true;
        results.innerHTML = '';
    };

    const renderResults = (items) => {
        results.innerHTML = '';
        if (items.length === 0) {
            const empty = document.createElement('li');
            empty.className = 'search__empty';
            empty.textContent = 'Aucun arrêt trouvé.';
            results.appendChild(empty);
            results.hidden = false;
            return;
        }
        for (const area of items.slice(0, SEARCH_MAX_RESULTS)) {
            const li = document.createElement('li');
            li.className = 'search__result';
            li.tabIndex = 0;
            li.setAttribute('role', 'option');
            const name = document.createElement('span');
            name.className = 'search__result-name';
            name.textContent = area.name;
            const meta = document.createElement('span');
            meta.className = 'search__result-meta';
            const bits = [];
            if (area.lat != null && area.lon != null) {
                bits.push(`${area.lat.toFixed(3)}°N · ${area.lon.toFixed(3)}°E`);
            }
            if (state.position) {
                const d = Math.round(haversine(state.position.lat, state.position.lon, area.lat, area.lon));
                bits.unshift(formatDistance(d));
            }
            meta.textContent = bits.join(' · ');
            li.appendChild(name);
            if (bits.length) li.appendChild(meta);
            const onPick = () => {
                closeDropdown();
                input.value = '';
                wrap.dataset.hasValue = 'false';
                input.blur();
                openAreaSheet(area);
            };
            li.addEventListener('click', onPick);
            li.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onPick(); }
            });
            results.appendChild(li);
        }
        results.hidden = false;
    };

    input.addEventListener('input', () => {
        const q = input.value.trim();
        wrap.dataset.hasValue = (input.value !== '').toString();
        if (debounceTimer) clearTimeout(debounceTimer);
        if (q.length < 2) {
            closeDropdown();
            lastQuery = '';
            return;
        }
        debounceTimer = setTimeout(async () => {
            lastQuery = q;
            const items = await searchAreas(q);
            if (q !== lastQuery) return;
            renderResults(items);
        }, SEARCH_DEBOUNCE_MS);
    });

    clear?.addEventListener('click', () => {
        input.value = '';
        wrap.dataset.hasValue = 'false';
        closeDropdown();
        input.focus();
    });

    input.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeDropdown();
            input.blur();
        }
    });

    document.addEventListener('click', (e) => {
        if (!wrap.contains(e.target) && !results.contains(e.target)) {
            closeDropdown();
        }
    });
}

/* ---------- Geoloc fallback banner ---------- */
function showGeoFallback() {
    const banner = $('bd-geo-banner');
    if (banner) banner.hidden = false;
}

$('bd-geo-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const value = $('bd-geo-input').value.trim();
    let pos;
    if (value) pos = await geocodeFallback(value);
    state.position = pos ?? { ...SAINT_ETIENNE, at: Date.now() };
    $('bd-geo-banner').hidden = true;
    refreshAll();
});

/* ---------- Sheet: area detail ---------- */
async function openAreaSheet(area) {
    state.sheetArea = area;
    state.sheetFilter = 'all';
    const sheet = $('bd-sheet-area');
    sheet.hidden = false;
    document.body.style.overflow = 'hidden';

    $('bd-sheet-title').textContent = area.name;
    updateFavButton();
    setActiveTab('all');
    $('bd-deps').innerHTML = '<li class="bd-dep" style="color:var(--ink-dim);padding:14px;border:0">Chargement…</li>';
    $('bd-quays-list').innerHTML = '';

    const details = await fetchAreaDetails(area.id, 90, 20);
    if (!details) {
        $('bd-deps').innerHTML = '<li class="bd-dep" style="color:var(--danger);padding:14px;border:0">Erreur de chargement</li>';
        return;
    }
    state.sheetArea = details.area;
    renderSheetDeps(details.departures);
    renderQuays(details.area.stops ?? []);
}

function renderSheetDeps(departures) {
    const list = $('bd-deps');
    list.innerHTML = '';
    const tpl = $('tpl-sheet-dep');
    let shown = 0;
    for (const d of departures) {
        if (state.sheetFilter === 'tram' && d.routeTypeLabel !== 'tram') continue;
        if (state.sheetFilter === 'bus' && d.routeTypeLabel !== 'bus' && d.routeTypeLabel !== 'trolley') continue;
        if (!routeTypeAllowed(d.routeTypeLabel)) continue;
        const li = tpl.content.firstElementChild.cloneNode(true);
        const r = li.querySelector('[data-route]');
        r.textContent = d.routeShortName ?? '·';
        r.dataset.type = d.routeTypeLabel;
        if (d.routeColor) { r.style.background = d.routeColor; r.style.color = '#111'; }
        li.querySelector('[data-sign]').textContent = d.direction_label ?? d.headsign ?? '';
        li.querySelector('[data-time]').textContent = d.scheduledTime;
        const eta = li.querySelector('[data-eta]');
        eta.textContent = d.minutesUntil === 0 ? 'à quai' : `${d.minutesUntil}′`;
        if (d.minutesUntil <= 3) eta.dataset.soon = 'true';
        list.appendChild(li);
        shown++;
    }
    if (shown === 0) {
        list.innerHTML = '<li class="bd-dep" style="color:var(--ink-dim);padding:14px;border:0">Aucun passage correspondant.</li>';
    }
}

function renderQuays(stops) {
    const list = $('bd-quays-list');
    list.innerHTML = '';
    for (const s of stops) {
        const li = document.createElement('li');
        const name = document.createElement('span');
        name.textContent = s.name;
        const coords = document.createElement('span');
        coords.style.color = 'var(--ink-dim)';
        coords.textContent = `${s.lat.toFixed(4)}, ${s.lon.toFixed(4)}`;
        li.appendChild(name); li.appendChild(coords);
        list.appendChild(li);
    }
}

function setActiveTab(filter) {
    state.sheetFilter = filter;
    document.querySelectorAll('#bd-tabs .bd-tab').forEach(btn => {
        btn.classList.toggle('is-active', btn.dataset.filter === filter);
    });
}

$('bd-tabs')?.addEventListener('click', async (e) => {
    const btn = e.target.closest('.bd-tab');
    if (!btn || !state.sheetArea) return;
    setActiveTab(btn.dataset.filter);
    const details = await fetchAreaDetails(state.sheetArea.id, 90, 20);
    if (details) renderSheetDeps(details.departures);
});

/* ---------- Favorites ---------- */
function isFavorite(areaId) { return state.favorites.some(f => f.areaId === areaId); }

function toggleFavorite(area) {
    const id = area.id;
    if (isFavorite(id)) {
        state.favorites = state.favorites.filter(f => f.areaId !== id);
    } else {
        if (state.favorites.length >= MAX_FAVORITES) {
            alert(`Maximum ${MAX_FAVORITES} favoris.`);
            return;
        }
        state.favorites.push({
            areaId: id,
            name: area.name,
            lat: area.lat,
            lon: area.lon,
            addedAt: new Date().toISOString(),
        });
    }
    saveFavorites();
    renderFavorites();
    if (state.sheetArea && state.sheetArea.id === id) updateFavButton();
}

function updateFavButton() {
    const btn = $('bd-fav-btn');
    if (!btn) return;
    const active = state.sheetArea && isFavorite(state.sheetArea.id);
    btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    btn.querySelector('.bd-fav__label').textContent = active ? 'Épinglé' : 'Favori';
}

$('bd-fav-btn')?.addEventListener('click', () => {
    if (!state.sheetArea) return;
    toggleFavorite(state.sheetArea);
});

/* ---------- Velivert sheet ---------- */
function openVelivertSheet(s) {
    const sheet = $('bd-sheet-velivert');
    sheet.hidden = false;
    document.body.style.overflow = 'hidden';
    $('bd-sheet-velivert-title').textContent = s.name;
    $('bd-velivert-body').innerHTML = `
        <div class="bd-sheet__row"><span>Vélos disponibles</span><strong>${s.bikes}</strong></div>
        <div class="bd-sheet__row"><span>Places libres</span><strong>${s.docks}</strong></div>
        <div class="bd-sheet__row"><span>Capacité</span><strong>${s.capacity}</strong></div>
        <div class="bd-sheet__row"><span>Distance</span><strong>${formatDistance(s.distance_m)} · ${s.walk_minutes} min</strong></div>
        <div class="bd-sheet__row"><span>État</span><strong>${s.operational ? 'opérationnelle' : 'hors service'}</strong></div>
        ${s.address ? `<div class="bd-sheet__row"><span>Adresse</span><strong>${escapeHtml(s.address)}</strong></div>` : ''}
    `;
    $('bd-velivert-go').href = `https://www.openstreetmap.org/?mlat=${s.lat}&mlon=${s.lon}#map=18/${s.lat}/${s.lon}`;
}

/* ---------- Sheet close handling ---------- */
document.querySelectorAll('[data-sheet-close]').forEach(el => {
    el.addEventListener('click', closeAllSheets);
});

function closeAllSheets() {
    document.querySelectorAll('.bd-sheet').forEach(s => s.hidden = true);
    document.body.style.overflow = '';
}

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeAllSheets();
});

/* ---------- Pull-to-refresh ---------- */
function initPullToRefresh() {
    const root = $('bd-root');
    const bar = document.querySelector('.bd-pull__bar');
    if (!root || !bar) return;
    let startY = null;
    let pulling = false;

    root.addEventListener('touchstart', (e) => {
        if (window.scrollY > 0) return;
        startY = e.touches[0].clientY;
        pulling = true;
    }, { passive: true });

    root.addEventListener('touchmove', (e) => {
        if (!pulling || startY === null) return;
        const dy = e.touches[0].clientY - startY;
        if (dy > 0) {
            const ratio = Math.min(1, dy / PULL_THRESHOLD);
            bar.style.transform = `scaleX(${ratio})`;
        }
    }, { passive: true });

    root.addEventListener('touchend', (e) => {
        if (!pulling) return;
        const dy = (e.changedTouches?.[0]?.clientY ?? 0) - (startY ?? 0);
        bar.style.transform = 'scaleX(0)';
        startY = null;
        pulling = false;
        if (dy >= PULL_THRESHOLD) refreshAll();
    });
}

/* ---------- Refresh wiring ---------- */
async function refreshAll() {
    state.position = null;
    try {
        await Promise.all([renderNearby(), renderFavorites(), renderVelivert()]);
        state.lastSync = Date.now();
    } finally {
        updateSyncLabel();
    }
}

function haversine(lat1, lon1, lat2, lon2) {
    const r = 6371000;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = Math.sin(dLat / 2) ** 2
        + Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * Math.sin(dLon / 2) ** 2;
    return 2 * r * Math.asin(Math.min(1, Math.sqrt(a)));
}

/* ---------- Boot ---------- */
function boot() {
    tickClock();
    setInterval(tickClock, 1000);

    initPullToRefresh();
    initSearch();
    renderChips();

    refreshAll();
    state.syncTimer = setInterval(updateSyncLabel, 5000);
    setInterval(refreshAll, 60_000);
}

document.addEventListener('DOMContentLoaded', boot);
