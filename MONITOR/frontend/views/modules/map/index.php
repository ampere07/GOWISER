<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireLogin();

$pageTitle   = 'Subscriber Map';
$breadcrumbs = [['label' => 'Subscriber Map']];

$extraHead = <<<'HTML'
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css">
<style>
/* ── Map ────────────────────────────────────────────────────── */
#subscriberMap {
    height: calc(100vh - 210px);
    min-height: 480px;
    border-radius: 10px;
    z-index: 0;
    box-shadow: 0 2px 12px rgba(0,0,0,.10);
    position: relative;
}
#mapWrap { position: relative; }

/* ── Controls ───────────────────────────────────────────────── */
#mapControls {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 10px;
}
.map-filter-btn {
    font-size: .78rem;
    padding: 4px 10px;
    border-radius: 20px;
    border: 2px solid transparent;
    font-weight: 600;
    cursor: pointer;
    background: #fff;
    color: #555;
    transition: all .15s;
    white-space: nowrap;
}
[data-bs-theme="dark"] .map-filter-btn { background: #2b2b3b; color: #ccc; }
.map-filter-btn.active { color: #fff !important; }
.map-filter-btn[data-status="all"]          { border-color:#6c757d; }
.map-filter-btn[data-status="all"].active   { background:#6c757d; }
.map-filter-btn[data-status="active"]       { border-color:#198754; }
.map-filter-btn[data-status="active"].active{ background:#198754; }
.map-filter-btn[data-status="expired"]       { border-color:#dc3545; }
.map-filter-btn[data-status="expired"].active{ background:#dc3545; }
.map-filter-btn[data-status="suspended"]       { border-color:#fd7e14; }
.map-filter-btn[data-status="suspended"].active{ background:#fd7e14; }
.map-filter-btn[data-status="online"]        { border-color:#00c851; }
.map-filter-btn[data-status="online"].active { background:#00c851; color:#fff !important; }
.map-filter-count {
    display: inline-block;
    background: rgba(0,0,0,.15);
    border-radius: 10px;
    font-size: .65rem;
    padding: 0 5px;
    margin-left: 3px;
    font-weight: 700;
}
.legend-dot {
    display: inline-block;
    width: 11px; height: 11px;
    border-radius: 50%;
    border: 2px solid rgba(255,255,255,.6);
    margin-right: 5px;
    vertical-align: middle;
    flex-shrink: 0;
}

/* ── Legend ─────────────────────────────────────────────────── */
#mapLegend {
    position: absolute;
    bottom: 30px;
    right: 10px;
    z-index: 999;
    background: rgba(255,255,255,.95);
    border-radius: 8px;
    padding: 10px 14px;
    box-shadow: 0 2px 10px rgba(0,0,0,.18);
    font-size: .78rem;
    min-width: 145px;
    pointer-events: none;
}
[data-bs-theme="dark"] #mapLegend { background: rgba(28,33,43,.95); color:#ddd; }
.legend-row { display:flex; align-items:center; margin-bottom:5px; }
.legend-row:last-child { margin-bottom:0; }
.legend-cnt { margin-left:auto; padding-left:10px; font-weight:700; font-size:.72rem; color:#888; }

/* ── Online-now ring (pulsing) ──────────────────────────────── */
@keyframes online-pulse {
    0%   { transform:translate(-50%,-50%) scale(1);   opacity:.7; }
    70%  { transform:translate(-50%,-50%) scale(2.6); opacity:0;  }
    100% { transform:translate(-50%,-50%) scale(2.6); opacity:0;  }
}
.marker-online-wrap { position:relative; width:18px; height:18px; }
.marker-online-ring {
    position:absolute;
    top:50%; left:50%;
    width:18px; height:18px;
    border-radius:50%;
    background:rgba(0,200,81,.45);
    animation:online-pulse 2s ease-out infinite;
    pointer-events:none;
}

/* ── Live search dropdown ───────────────────────────────────── */
#mapSearchResults {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    z-index: 1500;
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 0 0 8px 8px;
    box-shadow: 0 6px 18px rgba(0,0,0,.12);
    max-height: 280px;
    overflow-y: auto;
    display: none;
}
[data-bs-theme="dark"] #mapSearchResults { background: #1e2230; border-color: #3a3f55; }
.search-result-item {
    padding: 8px 12px;
    cursor: pointer;
    border-bottom: 1px solid #f0f0f0;
    font-size: .82rem;
}
[data-bs-theme="dark"] .search-result-item { border-bottom-color: #2a2f45; }
.search-result-item:last-child { border-bottom: none; }
.search-result-item:hover { background: #eef2ff; }
[data-bs-theme="dark"] .search-result-item:hover { background: #262c42; }
.search-result-item .sri-name { font-weight: 600; }
.search-result-item .sri-acc  { font-size: .72rem; color: #6c757d; }
[data-bs-theme="dark"] .search-result-item .sri-acc { color: #8b949e; }
.search-result-item .sri-no-loc { font-size: .68rem; color: #adb5bd; }

/* ── Router live badge in controls ─────────────────────────── */
#liveRouterBadge {
    font-size:.72rem;
    padding:3px 8px;
    border-radius:20px;
    white-space:nowrap;
}
.map-theme-btn {
    min-width: 38px;
}
#subscriberMap.map-night .leaflet-control-zoom a,
#subscriberMap.map-night .leaflet-control-attribution {
    background: rgba(17,24,39,.9);
    color: #d1d5db;
    border-color: rgba(148,163,184,.25);
}
#subscriberMap.map-night .leaflet-control-zoom a:hover {
    background: rgba(31,41,55,.95);
    color: #fff;
}
#subscriberMap.map-night .leaflet-control-attribution a {
    color: #93c5fd;
}

/* ── Leaflet popup wrapper — light ──────────────────────────── */
.leaflet-popup-content-wrapper,
.leaflet-popup-tip {
    background: #ffffff;
    color: #212529;
    box-shadow: 0 4px 16px rgba(0,0,0,.18);
}
.leaflet-popup-close-button {
    color: #6c757d !important;
    font-size: 18px !important;
    top: 6px !important;
    right: 8px !important;
}
.leaflet-popup-close-button:hover { color: #212529 !important; }

/* ── Leaflet popup wrapper — dark ───────────────────────────── */
[data-bs-theme="dark"] .leaflet-popup-content-wrapper,
[data-bs-theme="dark"] .leaflet-popup-tip {
    background: #1e2230;
    color: #e2e8f0;
    box-shadow: 0 4px 20px rgba(0,0,0,.5);
}
[data-bs-theme="dark"] .leaflet-popup-close-button {
    color: #8b949e !important;
}
[data-bs-theme="dark"] .leaflet-popup-close-button:hover {
    color: #e2e8f0 !important;
}

/* ── Popup content ──────────────────────────────────────────── */
.sub-popup { min-width: 215px; font-size: .83rem; }
.sub-popup-status {
    font-size:.62rem; font-weight:700; letter-spacing:.06em;
    text-transform:uppercase; padding:2px 8px; border-radius:20px; color:#fff;
}
.sub-popup-online {
    font-size:.62rem; font-weight:700; letter-spacing:.04em;
    color:#00c851; border:1px solid #00c851; border-radius:20px;
    padding:1px 7px; margin-left:5px;
}
.sub-popup-name {
    font-weight:700; font-size:.92rem; line-height:1.2; margin-top:6px;
    color: #111827;
}
[data-bs-theme="dark"] .sub-popup-name { color: #f1f5f9; }

.sub-popup-acc { color:#6c757d; font-size:.72rem; }
[data-bs-theme="dark"] .sub-popup-acc { color:#8b949e; }

.sub-popup-row { display:flex; align-items:flex-start; gap:6px; margin-top:5px; color:#4b5563; }
[data-bs-theme="dark"] .sub-popup-row { color:#94a3b8; }
.sub-popup-row i { font-size:.8rem; margin-top:1px; flex-shrink:0; }

.sub-popup-link {
    display:block; margin-top:10px; text-align:center;
    background:#0d6efd; color:#fff !important; border-radius:6px;
    padding:5px; text-decoration:none !important; font-size:.78rem; font-weight:600;
}
.sub-popup-link:hover { background:#0b5ed7; color:#fff !important; }

/* ── Cluster overrides ──────────────────────────────────────── */
.marker-cluster-small  div,
.marker-cluster-medium div,
.marker-cluster-large  div { font-size:11px; font-weight:700; color:#fff; }
.marker-cluster-small  { background-color:rgba(110,142,255,.6); }
.marker-cluster-small div  { background-color:rgba(110,142,255,.9); }
.marker-cluster-medium { background-color:rgba(253,130,15,.6); }
.marker-cluster-medium div { background-color:rgba(253,130,15,.9); }
.marker-cluster-large  { background-color:rgba(220,53,69,.6); }
.marker-cluster-large  div { background-color:rgba(220,53,69,.9); }
</style>
HTML;

include BASE_PATH . '/includes/header.php';
?>

<!-- Page Header -->
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">Subscriber Map</h4>
        <p class="text-muted small mb-0">Geographic distribution &amp; live connection status</p>
    </div>
    <div class="d-flex align-items-center gap-3 flex-wrap">
        <span class="text-muted small">
            Showing <strong id="visibleCount">—</strong> of <strong id="totalMapped">—</strong> mapped
        </span>
    </div>
</div>

<!-- Controls -->
<div id="mapControls">
    <!-- Search -->
    <div style="position:relative; max-width:270px; flex-shrink:0;">
        <div class="input-group input-group-sm">
            <span class="input-group-text"><i class="bi bi-search text-muted"></i></span>
            <input type="text" id="mapSearch" class="form-control" placeholder="Name or account number…" autocomplete="off">
            <button class="btn btn-outline-secondary" id="mapSearchClear" style="display:none;" title="Clear">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div id="mapSearchResults"></div>
    </div>

    <!-- Status filters -->
    <button class="map-filter-btn active" data-status="all">
        All <span class="map-filter-count" id="fc-all">0</span>
    </button>
    <button class="map-filter-btn" data-status="active">
        <span class="legend-dot" style="background:#198754;border-color:#198754;"></span>Active
        <span class="map-filter-count" id="fc-active">0</span>
    </button>
    <button class="map-filter-btn" data-status="expired">
        <span class="legend-dot" style="background:#dc3545;border-color:#dc3545;"></span>Expired
        <span class="map-filter-count" id="fc-expired">0</span>
    </button>
    <button class="map-filter-btn" data-status="suspended">
        <span class="legend-dot" style="background:#fd7e14;border-color:#fd7e14;"></span>Suspended
        <span class="map-filter-count" id="fc-suspended">0</span>
    </button>
    <button class="map-filter-btn" data-status="online" id="onlineFilterBtn" style="display:none;">
        <span class="legend-dot" style="background:#00c851;border-color:#00c851;"></span>Online Now
        <span class="map-filter-count" id="fc-online">0</span>
    </button>

    <!-- Live router badge + refresh -->
    <div class="ms-auto d-flex align-items-center gap-2">
        <span id="liveRouterBadge" class="badge bg-secondary-subtle text-secondary border d-none">
            <i class="bi bi-circle-fill me-1" style="font-size:.5rem;"></i><span id="liveRouterName"></span>
        </span>
        <span id="liveLoadingBadge" class="badge bg-warning-subtle text-warning border d-none">
            <span class="spinner-border spinner-border-sm me-1" style="width:.6rem;height:.6rem;"></span>Connecting to router…
        </span>
        <button class="btn btn-sm btn-outline-secondary map-theme-btn" id="mapThemeToggle" type="button" title="Night map">
            <i class="bi bi-moon-stars"></i>
        </button>
        <button class="btn btn-sm btn-outline-secondary" id="mapRefresh" title="Reload">
            <i class="bi bi-arrow-clockwise me-1"></i>Refresh
        </button>
    </div>
</div>

<!-- Map -->
<div id="mapWrap">
    <div id="subscriberMap"></div>

    <!-- Legend -->
    <div id="mapLegend">
        <div class="fw-semibold mb-2" style="font-size:.72rem;letter-spacing:.06em;text-transform:uppercase;">Legend</div>
        <div class="legend-row">
            <span class="legend-dot" style="background:#198754;border-color:#198754;"></span>Active
            <span class="legend-cnt" id="lc-active">0</span>
        </div>
        <div class="legend-row">
            <span class="legend-dot" style="background:#dc3545;border-color:#dc3545;"></span>Expired
            <span class="legend-cnt" id="lc-expired">0</span>
        </div>
        <div class="legend-row">
            <span class="legend-dot" style="background:#fd7e14;border-color:#fd7e14;"></span>Suspended
            <span class="legend-cnt" id="lc-suspended">0</span>
        </div>
        <div class="legend-row" id="lc-online-row" style="display:none;">
            <span style="display:inline-flex;align-items:center;margin-right:5px;">
                <span class="legend-dot" style="background:#00c851;border-color:#00c851;position:relative;"></span>
            </span>Online Now
            <span class="legend-cnt" id="lc-online">0</span>
        </div>
        <hr style="margin:7px 0;opacity:.2;">
        <div class="legend-row" style="font-size:.7rem;color:#888;">
            <span style="width:18px;height:18px;border-radius:50%;border:2px solid #00c851;display:inline-block;margin-right:6px;flex-shrink:0;"></span>
            Pulsing = live
        </div>
    </div>
</div>

<?php
$extraScripts = <<<'JS'
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
<script>
(function () {

const STATUS_COLORS = {
    active:    '#198754',
    expired:   '#dc3545',
    suspended: '#fd7e14',
};

// ── SVG circle icon ──────────────────────────────────────────
function makeIcon(status, onlineNow) {
    const color = STATUS_COLORS[status] || '#6c757d';
    const ring  = onlineNow
        ? `<div class="marker-online-ring"></div>` : '';
    const svg   = `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 18 18">
        <circle cx="9" cy="9" r="7" fill="${color}" stroke="white" stroke-width="2.2"/>
    </svg>`;
    return L.divIcon({
        html: `<div class="marker-online-wrap">${ring}${svg}</div>`,
        className: '',
        iconSize:   [18, 18],
        iconAnchor: [9, 9],
        popupAnchor:[0, -12],
    });
}

// ── Init map ─────────────────────────────────────────────────
const map = L.map('subscriberMap', {
    center: [12.8797, 121.7740],
    zoom: 6,
    zoomControl: true,
});
const mapEl = document.getElementById('subscriberMap');
const baseLayers = {
    day: L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }),
    night: L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
        maxZoom: 20,
        attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> © <a href="https://carto.com/attributions">CARTO</a>',
    }),
};
let activeMapTheme = localStorage.getItem('netmanager_map_theme') === 'night' ? 'night' : 'day';
let activeTileLayer = null;

function applyMapTheme(theme) {
    activeMapTheme = theme === 'night' ? 'night' : 'day';
    if (activeTileLayer) map.removeLayer(activeTileLayer);
    activeTileLayer = baseLayers[activeMapTheme].addTo(map);
    mapEl?.classList.toggle('map-night', activeMapTheme === 'night');
    localStorage.setItem('netmanager_map_theme', activeMapTheme);

    const btn = document.getElementById('mapThemeToggle');
    const icon = btn?.querySelector('i');
    if (btn && icon) {
        icon.className = activeMapTheme === 'night' ? 'bi bi-sun' : 'bi bi-moon-stars';
        btn.title = activeMapTheme === 'night' ? 'Day map' : 'Night map';
        btn.setAttribute('aria-label', btn.title);
        btn.classList.toggle('btn-dark', activeMapTheme === 'night');
        btn.classList.toggle('btn-outline-secondary', activeMapTheme !== 'night');
    }
}
applyMapTheme(activeMapTheme);

const clusterGroup = L.markerClusterGroup({
    maxClusterRadius: 50,
    spiderfyOnMaxZoom: true,
    showCoverageOnHover: false,
    zoomToBoundsOnClick: true,
    disableClusteringAtZoom: 17,
});
map.addLayer(clusterGroup);

// ── State ────────────────────────────────────────────────────
let allMarkers   = [];   // {marker, data, onlineNow}
let onlineIdSet  = new Set();
let activeStatus = 'all';
let searchTerm   = '';

// ── Build popup HTML ─────────────────────────────────────────
function buildPopup(s, onlineNow) {
    const color    = STATUS_COLORS[s.status] || '#6c757d';
    const location = [s.barangay, s.municipality].filter(Boolean).join(', ') || s.address || '—';
    const expiry   = s.subscription_end
        ? new Date(s.subscription_end).toLocaleDateString('en-PH', {month:'short',day:'numeric',year:'numeric'})
        : '—';
    const onlineBadge = onlineNow
        ? `<span class="sub-popup-online"><i class="bi bi-circle-fill" style="font-size:.5rem;"></i> Online</span>` : '';
    return `<div class="sub-popup">
        <div style="display:flex;align-items:center;flex-wrap:wrap;gap:4px;">
            <span class="sub-popup-status" style="background:${color};">${s.status}</span>
            ${onlineBadge}
        </div>
        <div class="sub-popup-name">${escHtml(s.firstname + ' ' + s.lastname)}</div>
        <div class="sub-popup-acc">${escHtml(s.account_number)}</div>
        <div class="sub-popup-row"><i class="bi bi-grid-fill text-primary"></i>
            ${escHtml(s.plan_name || '—')}${s.speed_mbps ? ' · ' + s.speed_mbps + ' Mbps' : ''}
        </div>
        <div class="sub-popup-row"><i class="bi bi-geo-alt-fill text-danger"></i>${escHtml(location)}</div>
        <div class="sub-popup-row"><i class="bi bi-calendar-event text-warning"></i>Expires: ${expiry}</div>
        <a href="${BASE_URL}/modules/subscribers/view/${s.subscriber_id}"
           class="sub-popup-link" target="_blank">
            View Profile <i class="bi bi-arrow-up-right-square ms-1"></i>
        </a>
    </div>`;
}

// ── Phase 1: load subscribers from DB ───────────────────────
function loadSubscribers() {
    setRefreshState(true);

    fetch(BASE_URL + '/api/map_subscribers.php')
        .then(r => r.json())
        .then(d => {
            if (!d.success) { setRefreshState(false); return; }

            clusterGroup.clearLayers();
            allMarkers = [];
            const bounds = [];

            (d.subscribers || []).forEach(s => {
                const lat = parseFloat(s.latitude);
                const lng = parseFloat(s.longitude);
                if (!lat || !lng) return;

                const online = onlineIdSet.has(parseInt(s.subscriber_id));
                const marker = L.marker([lat, lng], { icon: makeIcon(s.status, online) });
                marker.bindPopup(buildPopup(s, online), { maxWidth: 265 });
                allMarkers.push({ marker, data: s, onlineNow: online });
                bounds.push([lat, lng]);
            });

            // Update counts
            const byStatus  = d.mapped_by_status || {};
            const totalsAll = d.totals_all || {};
            const total     = d.total_mapped || 0;

            document.getElementById('totalMapped').textContent  = total.toLocaleString();
            document.getElementById('fc-all').textContent       = total.toLocaleString();
            document.getElementById('fc-active').textContent    = (byStatus.active    || 0).toLocaleString();
            document.getElementById('fc-expired').textContent   = (byStatus.expired   || 0).toLocaleString();
            document.getElementById('fc-suspended').textContent = (byStatus.suspended || 0).toLocaleString();
            document.getElementById('lc-active').textContent    = (byStatus.active    || 0).toLocaleString();
            document.getElementById('lc-expired').textContent   = (byStatus.expired   || 0).toLocaleString();
            document.getElementById('lc-suspended').textContent = (byStatus.suspended || 0).toLocaleString();

            if (bounds.length) map.fitBounds(bounds, { padding:[40,40], maxZoom:14 });

            applyFilters();
            setRefreshState(false);

            // Phase 2: live router sessions
            loadOnlineStatus();
            startOnlinePolling();
        })
        .catch(() => setRefreshState(false));
}

// ── Phase 2: fetch live sessions (PPP + Hotspot + RADIUS) ────
let onlinePolling = false;
let onlineTimer   = null;

function loadOnlineStatus() {
    if (onlinePolling) return;
    onlinePolling = true;

    const loadingBadge = document.getElementById('liveLoadingBadge');
    if (loadingBadge) loadingBadge.classList.remove('d-none');

    fetchWithTimeout(BASE_URL + '/api/map_online.php', {}, 40000)
        .then(r => r.json())
        .then(d => {
            onlinePolling = false;
            if (loadingBadge) loadingBadge.classList.add('d-none');

            if (!d.success) {
                // Show error in badge but keep retrying
                const badge = document.getElementById('liveRouterBadge');
                if (badge) {
                    document.getElementById('liveRouterName').textContent = d.message || 'Router unreachable';
                    badge.className = 'badge bg-danger-subtle text-danger border';
                    badge.classList.remove('d-none');
                }
                return;
            }

            onlineIdSet = new Set(d.online_ids || []);
            const onlineCnt = d.total_online || 0;
            const src       = d.sources || {};

            // Build source breakdown tooltip: PPP:3 HS:1 RADIUS:5
            const srcParts = [];
            if ((src.ppp     || 0) > 0) srcParts.push('PPP:' + src.ppp);
            if ((src.hotspot || 0) > 0) srcParts.push('HS:' + src.hotspot);
            if ((src.radius  || 0) > 0) srcParts.push('RADIUS:' + src.radius);
            const srcTip = srcParts.length ? ' (' + srcParts.join(' · ') + ')' : '';

            // Online Now filter button
            const onlineBtn = document.getElementById('onlineFilterBtn');
            if (onlineBtn) {
                document.getElementById('fc-online').textContent = onlineCnt.toLocaleString();
                onlineBtn.style.display = '';
            }

            // Legend row
            document.getElementById('lc-online').textContent = onlineCnt.toLocaleString();
            const lcRow = document.getElementById('lc-online-row');
            if (lcRow) lcRow.style.display = '';

            // Router live badge
            const badge = document.getElementById('liveRouterBadge');
            if (badge && d.router_name) {
                const now = new Date().toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
                document.getElementById('liveRouterName').textContent =
                    d.router_name + ' · ' + onlineCnt + ' online' + srcTip + ' · ' + now;
                badge.className = 'badge bg-success-subtle text-success border';
                badge.classList.remove('d-none');
            }

            // Update marker icons + popups
            allMarkers.forEach(m => {
                const isOnline = onlineIdSet.has(parseInt(m.data.subscriber_id));
                if (isOnline !== m.onlineNow) {
                    m.onlineNow = isOnline;
                    m.marker.setIcon(makeIcon(m.data.status, isOnline));
                    m.marker.setPopupContent(buildPopup(m.data, isOnline));
                }
            });

            applyFilters();
        })
        .catch(() => {
            onlinePolling = false;
            if (loadingBadge) loadingBadge.classList.add('d-none');
        });
}

function startOnlinePolling() {
    clearInterval(onlineTimer);
    onlineTimer = setInterval(loadOnlineStatus, 30000); // refresh every 30 s
}

// ── Filter + search ──────────────────────────────────────────
function matchesSearch(data, q) {
    if (!q) return true;
    return (data.firstname + ' ' + data.lastname).toLowerCase().includes(q)
        || (data.account_number || '').toLowerCase().includes(q);
}

function applyFilters() {
    clusterGroup.clearLayers();
    const q = searchTerm.toLowerCase().trim();
    let visible = 0;

    allMarkers.forEach(({ marker, data, onlineNow }) => {
        const matchStatus = activeStatus === 'all'
            || (activeStatus === 'online' && onlineNow)
            || data.status === activeStatus;

        if (matchStatus && matchesSearch(data, q)) {
            clusterGroup.addLayer(marker);
            visible++;
        }
    });

    document.getElementById('visibleCount').textContent = visible.toLocaleString();
}

// ── Live search dropdown ──────────────────────────────────────
function showSearchDropdown(q) {
    const el = document.getElementById('mapSearchResults');
    if (!q) { el.style.display = 'none'; return; }

    const hits = allMarkers
        .filter(({ data }) => matchesSearch(data, q))
        .slice(0, 8);

    if (!hits.length) {
        el.innerHTML = '<div class="search-result-item text-muted">No results found</div>';
    } else {
        el.innerHTML = hits.map(({ data }, i) => {
            const name   = escHtml(data.firstname + ' ' + data.lastname);
            const acc    = escHtml(data.account_number || '');
            const hasLoc = parseFloat(data.latitude) && parseFloat(data.longitude);
            const noLoc  = hasLoc ? '' : '<span class="sri-no-loc">· no pin</span>';
            return `<div class="search-result-item" data-idx="${i}">
                <div class="sri-name">${name} ${noLoc}</div>
                <div class="sri-acc">${acc}</div>
            </div>`;
        }).join('');

        el.querySelectorAll('.search-result-item').forEach(item => {
            item.addEventListener('click', () => {
                const { marker, data } = hits[parseInt(item.dataset.idx)];
                searchInput.value = data.firstname + ' ' + data.lastname;
                searchTerm        = searchInput.value;
                searchClear.style.display = '';
                el.style.display  = 'none';
                applyFilters();

                const lat = parseFloat(data.latitude);
                const lng = parseFloat(data.longitude);
                if (lat && lng) {
                    clusterGroup.zoomToShowLayer(marker, () => marker.openPopup());
                }
            });
        });
    }

    el.style.display = '';
}

// Hide dropdown when clicking outside
document.addEventListener('click', e => {
    if (!e.target.closest('#mapSearch') && !e.target.closest('#mapSearchResults')) {
        document.getElementById('mapSearchResults').style.display = 'none';
    }
});

// ── UI helpers ───────────────────────────────────────────────
function setRefreshState(loading) {
    const btn = document.getElementById('mapRefresh');
    if (!btn) return;
    btn.disabled = loading;
    btn.querySelector('i').classList.toggle('spinning', loading);
}

// Filter buttons
document.querySelectorAll('.map-filter-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.map-filter-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        activeStatus = this.dataset.status;
        applyFilters();
    });
});

// Search
const searchInput   = document.getElementById('mapSearch');
const searchClear   = document.getElementById('mapSearchClear');
const searchResults = document.getElementById('mapSearchResults');
searchInput?.addEventListener('input', function () {
    searchTerm = this.value;
    searchClear.style.display = searchTerm ? '' : 'none';
    applyFilters();
    showSearchDropdown(searchTerm.toLowerCase().trim());
});
searchInput?.addEventListener('focus', function () {
    if (searchTerm) showSearchDropdown(searchTerm.toLowerCase().trim());
});
searchClear?.addEventListener('click', function () {
    searchInput.value    = '';
    searchTerm           = '';
    this.style.display   = 'none';
    searchResults.style.display = 'none';
    applyFilters();
});

// Refresh
document.getElementById('mapRefresh')?.addEventListener('click', loadSubscribers);
document.getElementById('mapThemeToggle')?.addEventListener('click', function () {
    applyMapTheme(activeMapTheme === 'night' ? 'day' : 'night');
});

// Boot
document.addEventListener('DOMContentLoaded', loadSubscribers);

})();
</script>
JS;

include BASE_PATH . '/includes/footer.php';
