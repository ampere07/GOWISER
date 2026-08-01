<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireRole(ROLE_ADMIN, ROLE_SUPERADMIN);

$isSuperadmin = currentRole() === ROLE_SUPERADMIN;
$selId   = selectedRouterId();
if ($isSuperadmin) {
    $routers = db()->query("SELECT router_id, name, host, port, api_port, brand FROM routers ORDER BY name")->fetchAll();
} elseif ($selId) {
    $stmt = db()->prepare("SELECT router_id, name, host, port, api_port, brand FROM routers WHERE router_id = ?");
    $stmt->execute([$selId]);
    $routers = $stmt->fetchAll();
} else {
    $routers = [];
}

// Default to first router if none selected in session
if (!$selId && !empty($routers)) {
    $selId = (int)$routers[0]['router_id'];
}

$pageTitle   = 'Dashboard 2';
$breadcrumbs = [['label' => 'Dashboard 2']];
include BASE_PATH . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">Dashboard 2</h4>
        <p class="text-muted small mb-0">Real-time router monitoring — auto-refreshes every 15 s</p>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <?php if ($isSuperadmin): ?>
        <select class="form-select form-select-sm" id="routerSelector" style="min-width:220px;">
            <?php if (empty($routers)): ?>
            <option value="">No routers configured</option>
            <?php else: ?>
            <?php foreach ($routers as $r): ?>
            <option value="<?= $r['router_id'] ?>" <?= (int)$r['router_id'] === $selId ? 'selected' : '' ?>>
                <?= e($r['name']) ?> (<?= e($r['host']) ?>)
            </option>
            <?php endforeach; ?>
            <?php endif; ?>
        </select>
        <?php else: ?>
        <input type="hidden" id="routerSelector" value="<?= (int)$selId ?>">
        <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
            <i class="bi bi-router me-1"></i><?= e(selectedRouterName() ?: 'Default Router') ?>
        </span>
        <?php endif; ?>
        <button class="btn btn-sm btn-primary" id="refreshBtn">
            <i class="bi bi-arrow-clockwise me-1"></i>Refresh
        </button>
        <a href="<?= BASE_URL ?>/modules/dashboard/" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-bar-chart me-1"></i>DB Stats
        </a>
    </div>
</div>

<?php if (empty($routers)): ?>
<?= inlineToasts([['message' => 'No routers configured. Go to Routers to add one.', 'type' => 'warning']]) ?>
<?php else: ?>

<!-- Status bar -->
<div class="card border-0 mb-4 px-3 py-2">
    <div class="d-flex align-items-center gap-3">
        <div class="spinner-border spinner-border-sm text-primary" id="loadingSpinner"></div>
        <span id="statusMsg" class="text-muted small">Connecting to router…</span>
        <span id="lastRefresh" class="ms-auto text-muted small"></span>
        <span id="autoRefreshBadge" class="badge bg-success-subtle text-success d-none">
            <i class="bi bi-arrow-repeat me-1"></i>Auto-refresh on
        </span>
    </div>
</div>

<!-- Resource KPIs -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="card kpi-card h-100">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small mb-1">Router Identity</div>
                        <div class="fw-bold lh-sm" id="resIdentity">—</div>
                        <div class="text-muted small mt-1" id="resVersion">—</div>
                    </div>
                    <div class="kpi-icon bg-primary-subtle text-primary"><i class="bi bi-router-fill"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card kpi-card h-100">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div>
                        <div class="text-muted small mb-1">CPU Load</div>
                        <div class="kpi-value" id="resCpu">—</div>
                    </div>
                    <div class="kpi-icon bg-warning-subtle text-warning"><i class="bi bi-cpu-fill"></i></div>
                </div>
                <div class="progress resource-bar">
                    <div class="progress-bar" id="cpuBar" style="width:0%"></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card kpi-card h-100">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div>
                        <div class="text-muted small mb-1">Memory Used</div>
                        <div class="kpi-value" id="resMem">—</div>
                        <div class="text-muted small mt-1" id="resMemTotal">—</div>
                    </div>
                    <div class="kpi-icon bg-info-subtle text-info"><i class="bi bi-memory"></i></div>
                </div>
                <div class="progress resource-bar">
                    <div class="progress-bar bg-info" id="memBar" style="width:0%"></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card kpi-card h-100">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small mb-1">Uptime</div>
                        <div class="fw-bold lh-sm" id="resUptime">—</div>
                        <div class="mt-1 small text-muted">
                            Sessions: <span class="fw-semibold text-success" id="totalActive">—</span>
                        </div>
                    </div>
                    <div class="kpi-icon bg-success-subtle text-success"><i class="bi bi-clock-history"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Live Charts -->
<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card border-0 h-100">
            <div class="card-header bg-transparent border-bottom py-3">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <h6 class="mb-0 fw-semibold">
                        <i class="bi bi-graph-up me-2 text-primary"></i>Network Traffic
                        <span class="badge bg-success-subtle text-success ms-1" style="font-size:.65rem;">Real-time</span>
                    </h6>
                    <div class="d-flex gap-3 small">
                        <span class="text-muted">↓ Download <span class="fw-semibold text-primary" id="liveDown">0 B/s</span></span>
                        <span class="text-muted">↑ Upload <span class="fw-semibold text-success" id="liveUp">0 B/s</span></span>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <canvas id="trafficChart" height="130"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 h-100">
            <div class="card-header bg-transparent border-bottom py-3">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <h6 class="mb-0 fw-semibold">
                        <i class="bi bi-activity me-2 text-warning"></i>System Resources
                        <span class="badge bg-success-subtle text-success ms-1" style="font-size:.65rem;">Live</span>
                    </h6>
                    <div class="d-flex gap-3 small">
                        <span class="text-muted">CPU Usage <span class="fw-semibold text-warning" id="liveCpu">—</span></span>
                        <span class="text-muted">Memory Usage <span class="fw-semibold text-info" id="liveMem">—</span></span>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <canvas id="resourceChart" height="130"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Active Sessions -->
<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card border-0 h-100">
            <div class="card-header bg-transparent border-bottom py-3">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <h6 class="mb-0 fw-semibold">
                        <i class="bi bi-broadcast-pin me-2 text-primary"></i>Active Sessions
                    </h6>
                    <ul class="nav nav-tabs border-0" id="pppTabNav" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active py-1 px-2 small" data-bs-toggle="tab"
                                    data-bs-target="#pppTab" type="button" role="tab">
                                PPP <span class="badge bg-primary ms-1" id="pppCount">0</span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link py-1 px-2 small" data-bs-toggle="tab"
                                    data-bs-target="#radiusTab" type="button" role="tab">
                                RADIUS <span class="badge bg-success ms-1" id="radiusCount">0</span>
                            </button>
                        </li>
                    </ul>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="pppTab" role="tabpanel">
                        <div id="pppTable" style="max-height:360px;overflow-y:auto;">
                            <div class="text-center text-muted py-5 small"><div class="spinner-border spinner-border-sm me-2"></div>Loading…</div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="radiusTab" role="tabpanel">
                        <div id="radiusTable" style="max-height:360px;overflow-y:auto;">
                            <div class="text-center text-muted py-5 small"><div class="spinner-border spinner-border-sm me-2"></div>Loading…</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 h-100">
            <div class="card-header bg-transparent border-bottom py-3">
                <h6 class="mb-0 fw-semibold">
                    <i class="bi bi-wifi me-2 text-info"></i>Active Hotspot Sessions
                    <span class="badge bg-info text-dark ms-1" id="hotspotCount">0</span>
                </h6>
            </div>
            <div class="card-body p-0" id="hotspotTable" style="max-height:360px;overflow-y:auto;">
                <div class="text-center text-muted py-5 small"><div class="spinner-border spinner-border-sm me-2"></div>Loading…</div>
            </div>
        </div>
    </div>
</div>

<!-- Interfaces -->
<div class="card border-0 mb-4">
    <div class="card-header bg-transparent border-bottom py-3">
        <div class="d-flex align-items-center justify-content-between gap-2">
            <h6 class="mb-0 fw-semibold">
                <i class="bi bi-hdd-network me-2 text-success"></i>Network Interfaces
            </h6>
            <button class="btn btn-sm btn-outline-secondary js-collapse-toggle"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#interfacesCollapse"
                    aria-expanded="false"
                    aria-controls="interfacesCollapse">
                <i class="bi bi-chevron-down me-1"></i><span>Expand</span>
            </button>
        </div>
    </div>
    <div class="collapse" id="interfacesCollapse">
        <div class="card-body p-0" id="ifaceTable">
            <div class="text-center text-muted py-5 small"><div class="spinner-border spinner-border-sm me-2"></div>Loading…</div>
        </div>
    </div>
</div>

<!-- IP Pools -->
<div class="card border-0 mb-4">
    <div class="card-header bg-transparent border-bottom py-3">
        <div class="d-flex align-items-center justify-content-between gap-2">
            <h6 class="mb-0 fw-semibold">
                <i class="bi bi-grid-3x3 me-2 text-warning"></i>IP Pools
                <span class="badge bg-warning-subtle text-warning ms-1" id="ipPoolCount">0</span>
            </h6>
            <button class="btn btn-sm btn-outline-secondary js-collapse-toggle"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#ipPoolsCollapse"
                    aria-expanded="false"
                    aria-controls="ipPoolsCollapse">
                <i class="bi bi-chevron-down me-1"></i><span>Expand</span>
            </button>
        </div>
    </div>
    <div class="collapse" id="ipPoolsCollapse">
        <div class="card-body p-0" id="ipPoolTable">
            <div class="text-center text-muted py-4 small"><div class="spinner-border spinner-border-sm me-2"></div>Loading…</div>
        </div>
    </div>
</div>

<!-- Queue Management -->
<div class="card border-0 mb-4">
    <div class="card-header bg-transparent border-bottom py-3">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <h6 class="mb-0 fw-semibold">
                <i class="bi bi-stack me-2 text-info"></i>Queue Management
            </h6>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <ul class="nav nav-tabs border-0" id="queueTabNav" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active py-1 px-2 small" data-bs-toggle="tab"
                                data-bs-target="#simpleQueuesTab" type="button" role="tab">
                            Simple <span class="badge bg-secondary ms-1" id="simpleQueueCount">0</span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link py-1 px-2 small" data-bs-toggle="tab"
                                data-bs-target="#queueTreeTab" type="button" role="tab">
                            Tree <span class="badge bg-secondary ms-1" id="queueTreeCount">0</span>
                        </button>
                    </li>
                </ul>
                <button class="btn btn-sm btn-outline-secondary js-collapse-toggle"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#queueCollapse"
                        aria-expanded="false"
                        aria-controls="queueCollapse">
                    <i class="bi bi-chevron-down me-1"></i><span>Expand</span>
                </button>
            </div>
        </div>
    </div>
    <div class="collapse" id="queueCollapse">
        <div class="card-body p-0">
            <div class="tab-content">
                <div class="tab-pane fade show active" id="simpleQueuesTab" role="tabpanel">
                    <div id="simpleQueueTable">
                        <div class="text-center text-muted py-4 small"><div class="spinner-border spinner-border-sm me-2"></div>Loading…</div>
                    </div>
                </div>
                <div class="tab-pane fade" id="queueTreeTab" role="tabpanel">
                    <div id="queueTreeTable">
                        <div class="text-center text-muted py-4 small"><div class="spinner-border spinner-border-sm me-2"></div>Loading…</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<?php
$extraScripts = <<<'JS'
<script>
let refreshTimer = null;
let trafficChart = null, resourceChart = null, liveTimer = null;
let prevRx = null, prevTx = null, prevTs = null;
let livePolling  = false; // guard: concurrent mikrotik_live requests
let isLoadingMain = false; // guard: concurrent loadRouterData requests
let prevIfaceBytes   = {};
let prevSessionBytes = {};
let prevDashboardBw  = {};
const MAX_POINTS  = 60;
const chartLabels = [], rxBpsArr = [], txBpsArr = [], cpuArr = [], memArr = [];

function fmtBps(v) {
    if (v >= 1e9) return (v / 1e9).toFixed(2) + ' Gbps';
    if (v >= 1e6) return (v / 1e6).toFixed(2) + ' Mbps';
    if (v >= 1e3) return (v / 1e3).toFixed(1) + ' Kbps';
    return Math.round(v) + ' bps';
}

function fmtByterate(bps) {
    const B = bps / 8;
    if (B >= 1073741824) return (B / 1073741824).toFixed(1) + ' GB/s';
    if (B >= 1048576)    return (B / 1048576).toFixed(2) + ' MB/s';
    if (B >= 1024)       return (B / 1024).toFixed(1) + ' KB/s';
    return Math.round(B) + ' B/s';
}

function initCharts() {
    const ctxT = document.getElementById('trafficChart')?.getContext('2d');
    if (ctxT) {
        if (trafficChart) trafficChart.destroy();
        trafficChart = new Chart(ctxT, {
            type: 'line',
            data: {
                labels: chartLabels,
                datasets: [
                    { label: 'Download', data: rxBpsArr, borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,0.07)', borderWidth: 1.5, fill: true, tension: 0.4, pointRadius: 0 },
                    { label: 'Upload',   data: txBpsArr, borderColor: '#198754', backgroundColor: 'rgba(25,135,84,0.05)',  borderWidth: 1.5, fill: true, tension: 0.4, pointRadius: 0 },
                ]
            },
            options: {
                responsive: true, animation: false, interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top', labels: { font: { size: 11 }, boxWidth: 12 } },
                    tooltip: { callbacks: { label: ctx => ' ' + ctx.dataset.label + ': ' + fmtBps(ctx.raw) } }
                },
                scales: {
                    x: { title: { display: true, text: 'Time', font: { size: 10 } }, ticks: { maxTicksLimit: 6, font: { size: 10 } } },
                    y: { title: { display: true, text: 'Bits per second', font: { size: 10 } }, min: 0,
                         ticks: { callback: v => fmtBps(v), font: { size: 10 }, maxTicksLimit: 5 } }
                }
            }
        });
    }

    const ctxR = document.getElementById('resourceChart')?.getContext('2d');
    if (ctxR) {
        if (resourceChart) resourceChart.destroy();
        resourceChart = new Chart(ctxR, {
            type: 'line',
            data: {
                labels: chartLabels,
                datasets: [
                    { label: 'CPU',    data: cpuArr, borderColor: '#ffc107', backgroundColor: 'rgba(255,193,7,0.07)',  borderWidth: 1.5, fill: true, tension: 0.4, pointRadius: 0 },
                    { label: 'Memory', data: memArr, borderColor: '#0dcaf0', backgroundColor: 'rgba(13,202,240,0.07)', borderWidth: 1.5, fill: true, tension: 0.4, pointRadius: 0 },
                ]
            },
            options: {
                responsive: true, animation: false, interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top', labels: { font: { size: 11 }, boxWidth: 12 } },
                    tooltip: { callbacks: { label: ctx => ' ' + ctx.dataset.label + ': ' + ctx.raw.toFixed(1) + '%' } }
                },
                scales: {
                    x: { title: { display: true, text: 'Time', font: { size: 10 } }, ticks: { maxTicksLimit: 6, font: { size: 10 } } },
                    y: { title: { display: true, text: 'Usage (%)', font: { size: 10 } }, min: 0, max: 100,
                         ticks: { callback: v => v + '%', font: { size: 10 }, maxTicksLimit: 5 } }
                }
            }
        });
    }
}

function resetLiveData() {
    prevRx = prevTx = prevTs = null;
    prevIfaceBytes   = {};
    prevSessionBytes = {};
    prevDashboardBw  = {};
    chartLabels.length = rxBpsArr.length = txBpsArr.length = cpuArr.length = memArr.length = 0;
    trafficChart?.update('none');
    resourceChart?.update('none');
    ['liveDown','liveUp'].forEach(id => { const el = document.getElementById(id); if (el) el.textContent = '0 B/s'; });
    ['liveCpu','liveMem'].forEach(id => { const el = document.getElementById(id); if (el) el.textContent = '—'; });
}

function stopLivePolling() {
    clearInterval(liveTimer);
    liveTimer = null;
}

function startLivePolling() {
    stopLivePolling();
    resetLiveData();
    pollLive();
    liveTimer = setInterval(pollLive, 5000); // 5 s — was 3 s; gives router connections time to close
}

function pollLive() {
    const id = document.getElementById('routerSelector')?.value;
    if (!id || livePolling) return; // skip if a request is still in flight
    livePolling = true;

    fetchWithTimeout(BASE_URL + '/api/mikrotik_live.php?router_id=' + encodeURIComponent(id), {}, 22000)
        .then(r => r.json())
        .then(d => {
            livePolling = false;
            if (!d.success) return;

            let rxBps = 0, txBps = 0;
            if (prevRx !== null && prevTs !== null && d.total_rx >= prevRx && d.total_tx >= prevTx) {
                const elapsed = d.ts - prevTs;
                if (elapsed > 0) {
                    rxBps = ((d.total_rx - prevRx) * 8) / elapsed;
                    txBps = ((d.total_tx - prevTx) * 8) / elapsed;
                }
            }
            prevRx = d.total_rx;
            prevTx = d.total_tx;
            prevTs = d.ts;

            // Update chart header badges
            const dEl = document.getElementById('liveDown');
            const uEl = document.getElementById('liveUp');
            const cEl = document.getElementById('liveCpu');
            const mEl = document.getElementById('liveMem');
            if (dEl) dEl.textContent = fmtByterate(rxBps);
            if (uEl) uEl.textContent = fmtByterate(txBps);
            if (cEl) cEl.textContent = d.cpu + '%';
            if (mEl) mEl.textContent = d.mem_pct + '%';

            // Keep KPI cards live
            const cpuKpiEl = document.getElementById('resCpu');
            const cpuBarEl = document.getElementById('cpuBar');
            if (cpuKpiEl) cpuKpiEl.textContent = d.cpu + '%';
            if (cpuBarEl) {
                cpuBarEl.style.width = d.cpu + '%';
                cpuBarEl.className  = 'progress-bar ' + (d.cpu > 80 ? 'bg-danger' : d.cpu > 60 ? 'bg-warning' : 'bg-success');
            }
            const memKpiEl = document.getElementById('resMem');
            const memBarEl = document.getElementById('memBar');
            if (memKpiEl) memKpiEl.textContent = d.mem_pct + '%';
            if (memBarEl) {
                memBarEl.style.width = d.mem_pct + '%';
                memBarEl.className  = 'progress-bar ' + (d.mem_pct > 85 ? 'bg-danger' : 'bg-info');
            }

            // Sync CPU to sidebar router status panel (same data, no extra request)
            const sbCpuBar  = document.querySelector('#sidebarRouterStatus .sb-cpu-bar-fill');
            const sbCpuTxt  = document.querySelector('#sidebarRouterStatus .sb-router-row-cpu');
            const cpuPctLive = d.cpu ?? 0;
            if (sbCpuBar) {
                sbCpuBar.style.width = cpuPctLive + '%';
                sbCpuBar.className = 'sb-cpu-bar-fill' + (cpuPctLive >= 80 ? ' cpu-crit' : cpuPctLive >= 50 ? ' cpu-warn' : '');
            }
            if (sbCpuTxt) sbCpuTxt.textContent = cpuPctLive + '%';

            // Update last-refresh timestamp
            const lrEl = document.getElementById('lastRefresh');
            if (lrEl) lrEl.textContent = 'Live: ' + new Date().toLocaleTimeString();

            // Push data points
            const now = new Date().toLocaleTimeString('en-US', { hour12: false });
            chartLabels.push(now);
            rxBpsArr.push(rxBps);
            txBpsArr.push(txBps);
            cpuArr.push(d.cpu   ?? 0);
            memArr.push(d.mem_pct ?? 0);
            if (chartLabels.length > MAX_POINTS) {
                chartLabels.shift(); rxBpsArr.shift(); txBpsArr.shift(); cpuArr.shift(); memArr.shift();
            }

            trafficChart?.update('none');
            resourceChart?.update('none');

            // Per-interface live rates
            if (d.interfaces) {
                d.interfaces.forEach(iface => {
                    const prev = prevIfaceBytes[iface.name];
                    if (prev && d.ts > prev.ts) {
                        const elapsed = d.ts - prev.ts;
                        if (elapsed > 0 && iface.rx >= prev.rx && iface.tx >= prev.tx) {
                            const rxRate = ((iface.rx - prev.rx) * 8) / elapsed;
                            const txRate = ((iface.tx - prev.tx) * 8) / elapsed;
                            const safeKey = iface.name.replace(/[^a-zA-Z0-9]/g, '_');
                            const rateEl = document.getElementById('if-rate-' + safeKey);
                            if (rateEl) {
                                rateEl.innerHTML = `<span class="traffic-down">${fmtByterate(rxRate)}</span><span class="text-muted mx-1">/</span><span class="traffic-up">${fmtByterate(txRate)}</span>`;
                            }
                        }
                    }
                    prevIfaceBytes[iface.name] = {rx: iface.rx, tx: iface.tx, ts: d.ts};
                });
            }

            // Per-PPP-session bandwidth rates
            if (d.ppp_sessions) {
                d.ppp_sessions.forEach(s => {
                    const key  = 'ppp_' + s.name.replace(/[^a-zA-Z0-9]/g, '_');
                    const prev = prevSessionBytes[key];
                    if (prev && d.ts > prev.ts && s.bytes_in >= prev.bi && s.bytes_out >= prev.bo) {
                        const elapsed = d.ts - prev.ts;
                        if (elapsed > 0) {
                            const rxBps = (s.bytes_in  - prev.bi) * 8 / elapsed;
                            const txBps = (s.bytes_out - prev.bo) * 8 / elapsed;
                            const downCell = document.getElementById('ses-down-' + key);
                            const upCell   = document.getElementById('ses-up-' + key);
                            if (downCell) downCell.innerHTML = fmtBps(txBps);
                            if (upCell)   upCell.innerHTML   = fmtBps(rxBps);
                        }
                    }
                    prevSessionBytes[key] = {bi: s.bytes_in, bo: s.bytes_out, ts: d.ts};
                });
            }

            // Per-Hotspot-session bandwidth rates
            if (d.hotspot_sessions) {
                d.hotspot_sessions.forEach(s => {
                    const uname = s.user || s.username || s.name || '';
                    if (!uname) return;
                    const key  = 'hs_' + uname.replace(/[^a-zA-Z0-9]/g, '_');
                    const prev = prevSessionBytes[key];
                    if (prev && d.ts > prev.ts && s.bytes_in >= prev.bi && s.bytes_out >= prev.bo) {
                        const elapsed = d.ts - prev.ts;
                        if (elapsed > 0) {
                            const rxBps = (s.bytes_in  - prev.bi) * 8 / elapsed;
                            const txBps = (s.bytes_out - prev.bo) * 8 / elapsed;
                            const downCell = document.getElementById('ses-down-' + key);
                            const upCell   = document.getElementById('ses-up-' + key);
                            if (downCell) downCell.innerHTML = fmtBps(txBps);
                            if (upCell)   upCell.innerHTML   = fmtBps(rxBps);
                        }
                    }
                    prevSessionBytes[key] = {bi: s.bytes_in, bo: s.bytes_out, ts: d.ts};
                });
            }

            // Per-RADIUS-session bandwidth rates
            if (d.radius_sessions) {
                d.radius_sessions.forEach(s => {
                    if (!s.name) return;
                    const key  = 'rm_' + s.name.replace(/[^a-zA-Z0-9]/g, '_');
                    const prev = prevSessionBytes[key];
                    if (prev && d.ts > prev.ts && s.bytes_in >= prev.bi && s.bytes_out >= prev.bo) {
                        const elapsed = d.ts - prev.ts;
                        if (elapsed > 0) {
                            const rxBps = (s.bytes_in  - prev.bi) * 8 / elapsed;
                            const txBps = (s.bytes_out - prev.bo) * 8 / elapsed;
                            const downCell = document.getElementById('ses-down-' + key);
                            const upCell   = document.getElementById('ses-up-' + key);
                            if (downCell) downCell.innerHTML = fmtBps(txBps);
                            if (upCell)   upCell.innerHTML   = fmtBps(rxBps);
                        }
                    }
                    prevSessionBytes[key] = {bi: s.bytes_in, bo: s.bytes_out, ts: d.ts};
                });
            }
        })
        .catch(err => {
            livePolling = false;
            if (err.name === 'AbortError') showReloadToast('Live router data timed out. Reload to try again.');
        });
}

function fmtBytes(b) {
    if (b == null || b === '' || b === '—') return '—';
    b = parseInt(b);
    if (b >= 1073741824) return (b / 1073741824).toFixed(1) + ' GB';
    if (b >= 1048576)    return (b / 1048576).toFixed(1) + ' MB';
    if (b >= 1024)       return (b / 1024).toFixed(1) + ' KB';
    return b + ' B';
}

function fmtUptime(u) {
    if (!u) return '—';
    return u.replace(/w/g,'w ').replace(/d/g,'d ').replace(/h/g,'h ').replace(/m(?!s)/g,'m ').trim();
}

function countFromPayload(value, sessions) {
    const n = Number.parseInt(value, 10);
    return Number.isFinite(n) && n >= sessions.length ? n : sessions.length;
}

function sessionBytesIn(s) {
    return s['bytes-in'] ?? s.bytes_in ?? s['rx-bytes'] ?? s['rx-byte'] ?? 0;
}

function sessionBytesOut(s) {
    return s['bytes-out'] ?? s.bytes_out ?? s['tx-bytes'] ?? s['tx-byte'] ?? 0;
}

function sessionUsername(s) {
    return s.username || s.user || s.name || '';
}

function sessionSubscriber(s) {
    return s.subscriber || sessionUsername(s) || '—';
}

function sessionType(s, fallback = '') {
    return s.type || fallback;
}

function sessionRateKey(s, fallbackType = '') {
    return (sessionType(s, fallbackType) || 'session') + '_' + sessionUsername(s).replace(/[^a-zA-Z0-9]/g, '_');
}

function calcSessionRate(s, ts, fallbackType = '') {
    const key = sessionRateKey(s, fallbackType);
    if (!key || !ts) return { rx: null, tx: null };
    const bytesIn = Number(sessionBytesIn(s) || 0);
    const bytesOut = Number(sessionBytesOut(s) || 0);
    const prev = prevDashboardBw[key];
    prevDashboardBw[key] = { bytes_in: bytesIn, bytes_out: bytesOut, ts };
    if (!prev) return { rx: 0, tx: 0 };
    const dt = ts - prev.ts;
    if (dt <= 0.1) return { rx: 0, tx: 0 };
    return {
        rx: Math.max(0, ((bytesOut - prev.bytes_out) * 8) / dt), // download to subscriber
        tx: Math.max(0, ((bytesIn  - prev.bytes_in)  * 8) / dt), // upload from subscriber
    };
}

function fmtRateMaybe(v) {
    return v == null ? '<span class="text-muted">—</span>' : fmtBps(v);
}

function usagePercent(rate, max) {
    const limit = Number(max || 0);
    if (!limit || rate == null) return null;
    return Math.min(100, (rate / limit) * 100);
}

function usageBar(rxRate, txRate, maxDown, maxUp) {
    const downPct = usagePercent(rxRate, maxDown);
    const upPct   = usagePercent(txRate, maxUp);
    const pct     = downPct !== null ? downPct : upPct;
    if (pct === null) return '<span class="text-muted small">No limit</span>';
    const color = pct > 85 ? 'danger' : pct > 60 ? 'warning' : 'success';
    return `<div class="d-flex align-items-center gap-1">
        <div class="progress flex-grow-1" style="height:6px;min-width:72px;">
            <div class="progress-bar bg-${color}" style="width:${pct.toFixed(1)}%"></div>
        </div>
        <span class="small text-${color} fw-semibold" style="width:38px;text-align:right;">${pct.toFixed(0)}%</span>
    </div>`;
}

function sessionRateContext(s, fallbackType = '') {
    const rates = calcSessionRate(s, s.ts || window.dashboardSessionTs || 0, fallbackType);
    return {
        rx: rates.rx,
        tx: rates.tx,
        usage: usageBar(rates.rx, rates.tx, s.max_down, s.max_up),
    };
}

function loadRouterData() {
    if (isLoadingMain) return;
    isLoadingMain = true;
    const sel = document.getElementById('routerSelector');
    const id  = sel?.value;
    if (!id) { isLoadingMain = false; return; }

    document.getElementById('loadingSpinner')?.classList.remove('d-none');
    document.getElementById('statusMsg').textContent = 'Fetching live data…';
    setSessionLoading();

    fetchWithTimeout(BASE_URL + '/api/router_status.php?id=' + encodeURIComponent(id) + '&live=1', {}, 25000)
        .then(r => r.json())
        .then(d => {
            isLoadingMain = false;
            document.getElementById('loadingSpinner')?.classList.add('d-none');
            document.getElementById('lastRefresh').textContent = 'Updated ' + new Date().toLocaleTimeString();

            if (!d.success || !d.online) {
                const errMsg = d.error ? escHtml(d.error) : 'Router offline or unreachable';
                document.getElementById('statusMsg').innerHTML =
                    `<i class="bi bi-x-circle text-danger me-1"></i><span class="text-danger">${errMsg}</span>`;
                clearAll();
                return;
            }

            document.getElementById('statusMsg').innerHTML =
                `<i class="bi bi-circle-fill text-success me-1" style="font-size:.5rem;vertical-align:middle;"></i>
                 Connected to <strong>${escHtml(d.identity || d.name)}</strong>`;

            // Resources
            const cpu    = d.cpu_load != null ? parseInt(d.cpu_load) : null;
            const cpuPct = cpu != null ? cpu : 0;
            document.getElementById('resIdentity').textContent = d.identity || d.name || '—';
            document.getElementById('resVersion').textContent  = d.ros_version ? 'RouterOS ' + d.ros_version : '—';
            document.getElementById('resCpu').textContent      = cpu != null ? cpu + '%' : '—';
            const cpuBar = document.getElementById('cpuBar');
            cpuBar.style.width  = cpuPct + '%';
            cpuBar.className    = 'progress-bar ' + (cpuPct > 80 ? 'bg-danger' : cpuPct > 60 ? 'bg-warning' : 'bg-success');

            if (d.free_memory && d.total_memory) {
                const used   = parseInt(d.total_memory) - parseInt(d.free_memory);
                const pct    = Math.round(used / parseInt(d.total_memory) * 100);
                document.getElementById('resMem').textContent      = pct + '%';
                document.getElementById('resMemTotal').textContent = fmtBytes(used) + ' / ' + fmtBytes(d.total_memory);
                const memBar = document.getElementById('memBar');
                memBar.style.width = pct + '%';
                memBar.className   = 'progress-bar ' + (pct > 85 ? 'bg-danger' : 'bg-info');
            }

            const pppSessions     = Array.isArray(d.ppp_sessions) ? d.ppp_sessions : [];
            const hotspotSessions = Array.isArray(d.hotspot_sessions) ? d.hotspot_sessions : [];
            const radiusSessions  = Array.isArray(d.radius_sessions) ? d.radius_sessions : [];
            const pppCount        = countFromPayload(d.ppp_active, pppSessions);
            const hotspotCount    = countFromPayload(d.hotspot_active, hotspotSessions);
            const radiusCount     = countFromPayload(d.radius_active, radiusSessions);

            document.getElementById('resUptime').textContent    = fmtUptime(d.uptime);
            document.getElementById('totalActive').textContent  = (pppCount + hotspotCount + radiusCount).toLocaleString();

            renderPPP(pppSessions, pppCount);
            renderHotspot(hotspotSessions, hotspotCount);
            renderRadius(radiusSessions, radiusCount);
            if (d.interfaces) renderIfaces(d.interfaces);

            loadBandwidthSessions(id);
        })
        .catch(err => {
            isLoadingMain = false;
            document.getElementById('loadingSpinner')?.classList.add('d-none');
            if (err.name === 'AbortError') {
                document.getElementById('statusMsg').innerHTML =
                    '<i class="bi bi-clock text-warning me-1"></i><span class="text-warning">Router timed out</span>';
            } else {
                document.getElementById('statusMsg').innerHTML =
                    '<i class="bi bi-exclamation-triangle text-danger me-1"></i><span class="text-danger">Request failed — check network</span>';
            }
        });
}

function setSessionLoading() {
    const loading = '<div class="text-center text-muted py-5 small"><div class="spinner-border spinner-border-sm me-2"></div>Loading active sessions…</div>';
    ['pppTable','hotspotTable','radiusTable'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.innerHTML = loading;
    });
}

function loadBandwidthSessions(routerId) {
    const params = new URLSearchParams({ router_id: routerId, limit: '500', offset: '0' });
    return fetchWithTimeout(BASE_URL + '/api/bandwidth_stats.php?' + params.toString(), {}, 25000)
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            window.dashboardSessionTs = data.ts || (Date.now() / 1000);

            const sessions = Array.isArray(data.sessions) ? data.sessions : [];
            const pppSessions = sessions.filter(s => s.type === 'ppp');
            const hotspotSessions = sessions.filter(s => s.type === 'hotspot');
            const radiusSessions = sessions.filter(s => s.type === 'radius');

            const pppCount = countFromPayload(data.ppp_count, pppSessions);
            const hotspotCount = countFromPayload(data.hs_count, hotspotSessions);
            const radiusCount = countFromPayload(data.radius_count, radiusSessions);

            document.getElementById('totalActive').textContent = (pppCount + hotspotCount + radiusCount).toLocaleString();
            if (pppSessions.length || pppCount === 0) renderPPP(pppSessions, pppCount);
            if (hotspotSessions.length || hotspotCount === 0) renderHotspot(hotspotSessions, hotspotCount);
            if (radiusSessions.length || radiusCount === 0) renderRadius(radiusSessions, radiusCount);
        })
        .catch(() => {});
}

function clearAll() {
    const empty = '<div class="text-center text-muted py-5 small"><i class="bi bi-slash-circle me-2"></i>Router offline</div>';
    ['pppTable','hotspotTable','radiusTable','ifaceTable','ipPoolTable','simpleQueueTable','queueTreeTable'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.innerHTML = empty;
    });
    ['pppCount','hotspotCount','radiusCount'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.textContent = '0';
    });
    document.getElementById('totalActive').textContent = '—';
}

function renderPPP(sessions, count) {
    document.getElementById('pppCount').textContent = count.toLocaleString();
    const el = document.getElementById('pppTable');
    if (!sessions.length) {
        el.innerHTML = '<div class="text-center text-muted py-5 small"><i class="bi bi-broadcast-pin me-2"></i>No active PPP sessions</div>';
        return;
    }
    let html = `<table class="table table-sm table-hover align-middle session-table mb-0">
        <thead class="table-light thead-sticky">
            <tr>
                <th class="ps-3">Subscriber</th>
                <th>IP Address</th>
                <th>Duration</th>
                <th class="text-end">Download</th>
                <th class="text-end">Upload</th>
                <th class="text-end">Total ↓</th>
                <th class="text-end">Total ↑</th>
                <th>Usage</th>
            </tr>
        </thead><tbody>`;
    sessions.forEach(s => {
        const uname = sessionUsername(s);
        const safeKey = 'ppp_' + uname.replace(/[^a-zA-Z0-9]/g, '_');
        const rate = sessionRateContext(s, 'ppp');
        html += `<tr>
            <td class="ps-3">
                <div class="fw-medium font-monospace">${escHtml(uname || '—')}</div>
                <div class="text-muted small">${escHtml(sessionSubscriber(s))}</div>
            </td>
            <td class="text-muted small">${escHtml(s.address || '—')}</td>
            <td class="text-muted small">${escHtml(fmtUptime(s.uptime) || '—')}</td>
            <td class="text-end small traffic-down" id="ses-down-${safeKey}">${fmtRateMaybe(rate.rx)}</td>
            <td class="text-end small traffic-up" id="ses-up-${safeKey}">${fmtRateMaybe(rate.tx)}</td>
            <td class="text-end small">${fmtBytes(sessionBytesOut(s))}</td>
            <td class="text-end small">${fmtBytes(sessionBytesIn(s))}</td>
            <td class="small" id="ses-usage-${safeKey}">${rate.usage}</td>
        </tr>`;
    });
    el.innerHTML = html + '</tbody></table>';
}

function renderHotspot(sessions, count) {
    document.getElementById('hotspotCount').textContent = count.toLocaleString();
    const el = document.getElementById('hotspotTable');
    if (!sessions.length) {
        el.innerHTML = '<div class="text-center text-muted py-5 small"><i class="bi bi-wifi-off me-2"></i>No active Hotspot sessions</div>';
        return;
    }
    let html = `<table class="table table-sm table-hover align-middle session-table mb-0">
        <thead class="table-light thead-sticky">
            <tr>
                <th class="ps-3">Subscriber</th>
                <th>IP Address</th>
                <th>Duration</th>
                <th class="text-end">Download</th>
                <th class="text-end">Upload</th>
                <th class="text-end">Total ↓</th>
                <th class="text-end">Total ↑</th>
                <th>Usage</th>
            </tr>
        </thead><tbody>`;
    sessions.forEach(s => {
        const uname   = sessionUsername(s);
        const safeKey = 'hs_' + uname.replace(/[^a-zA-Z0-9]/g, '_');
        const rate = sessionRateContext(s, 'hotspot');
        html += `<tr>
            <td class="ps-3">
                <div class="fw-medium font-monospace">${escHtml(uname || '—')}</div>
                <div class="text-muted small">${escHtml(sessionSubscriber(s))}</div>
            </td>
            <td class="text-muted small">${escHtml(s.address || '—')}</td>
            <td class="text-muted small">${escHtml(fmtUptime(s.uptime) || '—')}</td>
            <td class="text-end small traffic-down" id="ses-down-${safeKey}">${fmtRateMaybe(rate.rx)}</td>
            <td class="text-end small traffic-up" id="ses-up-${safeKey}">${fmtRateMaybe(rate.tx)}</td>
            <td class="text-end small">${fmtBytes(sessionBytesOut(s))}</td>
            <td class="text-end small">${fmtBytes(sessionBytesIn(s))}</td>
            <td class="small" id="ses-usage-${safeKey}">${rate.usage}</td>
        </tr>`;
    });
    el.innerHTML = html + '</tbody></table>';
}

function fmtSeconds(n) {
    n = parseInt(n) || 0;
    if (!n) return '—';
    const d = Math.floor(n / 86400), h = Math.floor((n % 86400) / 3600), m = Math.floor((n % 3600) / 60), s = n % 60;
    return ((d ? d + 'd ' : '') + (h ? h + 'h ' : '') + (m ? m + 'm ' : '') + (!d && !h ? s + 's' : '')).trim() || '0s';
}

function renderRadius(sessions, count) {
    document.getElementById('radiusCount').textContent = count > 50 ? count.toLocaleString() + ' (top 50 shown)' : count.toLocaleString();
    const el = document.getElementById('radiusTable');
    if (!sessions.length) {
        el.innerHTML = '<div class="text-center text-muted py-5 small"><i class="bi bi-shield-x me-2"></i>No active RADIUS sessions</div>';
        return;
    }
    let html = `<table class="table table-sm table-hover align-middle session-table mb-0">
        <thead class="table-light thead-sticky">
            <tr>
                <th class="ps-3">Subscriber</th>
                <th>IP / Caller</th>
                <th>Duration</th>
                <th class="text-end">Download</th>
                <th class="text-end">Upload</th>
                <th class="text-end">Total ↓</th>
                <th class="text-end">Total ↑</th>
                <th>Usage</th>
            </tr>
        </thead><tbody>`;
    sessions.forEach(s => {
        const uname   = sessionUsername(s);
        const safeKey = 'rm_' + uname.replace(/[^a-zA-Z0-9]/g, '_');
        const ip      = s.address || s['framed-ip-address'] || s['from-ip'] || s['nas-ip-address'] || '—';
        const mac     = s['calling-station-id'] || s['caller-id'] || '—';
        const dur     = s['session-time'] ? fmtSeconds(parseInt(s['session-time'])) : fmtUptime(s.uptime || '');
        const bIn     = s['acct-input-octets']  ?? sessionBytesIn(s);
        const bOut    = s['acct-output-octets'] ?? sessionBytesOut(s);
        const rate = sessionRateContext({...s, bytes_in: bIn, bytes_out: bOut}, 'radius');
        html += `<tr>
            <td class="ps-3">
                <div class="fw-medium font-monospace">${escHtml(uname || '—')}</div>
                <div class="text-muted small">${escHtml(sessionSubscriber(s))}</div>
            </td>
            <td>
                <div class="text-muted small">${escHtml(ip)}</div>
                <div class="font-monospace text-muted small">${escHtml(mac)}</div>
            </td>
            <td class="text-muted small">${escHtml(dur || '—')}</td>
            <td class="text-end small traffic-down" id="ses-down-${safeKey}">${fmtRateMaybe(rate.rx)}</td>
            <td class="text-end small traffic-up" id="ses-up-${safeKey}">${fmtRateMaybe(rate.tx)}</td>
            <td class="text-end small">${fmtBytes(bOut)}</td>
            <td class="text-end small">${fmtBytes(bIn)}</td>
            <td class="small" id="ses-usage-${safeKey}">${rate.usage}</td>
        </tr>`;
    });
    el.innerHTML = html + '</tbody></table>';
}

function renderIfaces(ifaces) {
    const el = document.getElementById('ifaceTable');
    if (!ifaces.length) {
        el.innerHTML = '<div class="text-center text-muted py-5 small">No interfaces found</div>';
        return;
    }
    let html = `<div class="table-responsive"><table class="table table-sm table-hover align-middle session-table mb-0">
        <thead class="table-light">
            <tr><th class="ps-3">Name</th><th>Type</th><th>MAC Address</th><th>MTU</th><th>Total TX / RX</th><th>Live ↓ / ↑ Rate</th><th>Status</th></tr>
        </thead><tbody>`;
    ifaces.forEach(i => {
        const disabled = i.disabled === 'true';
        const running  = i.running  !== 'false' && i.running !== undefined;
        const statusBadge = disabled
            ? '<span class="badge bg-secondary">Disabled</span>'
            : (running ? '<span class="badge bg-success">Up</span>' : '<span class="badge bg-danger">Down</span>');
        const safeKey = (i.name || '').replace(/[^a-zA-Z0-9]/g, '_');
        html += `<tr>
            <td class="ps-3 fw-medium">${escHtml(i.name || '—')}</td>
            <td class="text-muted small">${escHtml(i.type || '—')}</td>
            <td class="font-monospace text-muted small">${escHtml(i['mac-address'] || '—')}</td>
            <td class="text-muted small">${escHtml(i.mtu || '—')}</td>
            <td class="small">
                <span class="traffic-up">${fmtBytes(i['tx-byte'])}</span>
                <span class="text-muted mx-1">/</span>
                <span class="traffic-down">${fmtBytes(i['rx-byte'])}</span>
            </td>
            <td class="small" id="if-rate-${safeKey}"><span class="text-muted">—</span></td>
            <td>${statusBadge}</td>
        </tr>`;
    });
    el.innerHTML = html + '</tbody></table></div>';
}

// ── IP Pools + Queue Management ───────────────────────────────────────────
function loadNetworkData() {
    const id = document.getElementById('routerSelector')?.value;
    if (!id) return;

    ['ipPoolTable', 'simpleQueueTable', 'queueTreeTable'].forEach(tid => {
        const el = document.getElementById(tid);
        if (el) el.innerHTML = '<div class="text-center text-muted py-4 small"><div class="spinner-border spinner-border-sm me-2"></div>Loading…</div>';
    });

    fetchWithTimeout(BASE_URL + '/api/mikrotik_network.php?router_id=' + encodeURIComponent(id), {}, 20000)
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                const errHtml = `<div class="text-center text-muted py-4 small"><i class="bi bi-exclamation-triangle me-1 text-danger"></i>${escHtml(d.message || 'Failed to load')}</div>`;
                ['ipPoolTable', 'simpleQueueTable', 'queueTreeTable'].forEach(tid => {
                    const el = document.getElementById(tid);
                    if (el) el.innerHTML = errHtml;
                });
                return;
            }
            renderIPPools(d.ip_pools || []);
            renderSimpleQueues(d.simple_queues || []);
            renderQueueTree(d.queue_tree || []);
        })
        .catch(() => {
            const errHtml = '<div class="text-center text-muted py-4 small"><i class="bi bi-slash-circle me-1"></i>Request failed</div>';
            ['ipPoolTable', 'simpleQueueTable', 'queueTreeTable'].forEach(tid => {
                const el = document.getElementById(tid);
                if (el) el.innerHTML = errHtml;
            });
        });
}

function renderIPPools(pools) {
    const countEl = document.getElementById('ipPoolCount');
    if (countEl) countEl.textContent = pools.length;
    const el = document.getElementById('ipPoolTable');
    if (!el) return;
    if (!pools.length) {
        el.innerHTML = '<div class="text-center text-muted py-4 small"><i class="bi bi-database-x me-2"></i>No IP pools configured</div>';
        return;
    }
    let html = `<div class="table-responsive"><table class="table table-sm table-hover align-middle session-table mb-0">
        <thead class="table-light"><tr>
            <th class="ps-3">Pool Name</th><th>Ranges</th><th>Next Pool (fallback)</th>
        </tr></thead><tbody>`;
    pools.forEach(p => {
        html += `<tr>
            <td class="ps-3 fw-medium font-monospace">${escHtml(p.name || '—')}</td>
            <td class="text-muted small font-monospace">${escHtml(p.ranges || '—')}</td>
            <td class="text-muted small">${escHtml(p['next-pool'] && p['next-pool'] !== 'none' ? p['next-pool'] : '—')}</td>
        </tr>`;
    });
    el.innerHTML = html + '</tbody></table></div>';
}

function renderSimpleQueues(queues) {
    const countEl = document.getElementById('simpleQueueCount');
    if (countEl) countEl.textContent = queues.length;
    const el = document.getElementById('simpleQueueTable');
    if (!el) return;
    if (!queues.length) {
        el.innerHTML = '<div class="text-center text-muted py-4 small"><i class="bi bi-stack me-2"></i>No simple queues configured</div>';
        return;
    }
    let html = `<div class="table-responsive"><table class="table table-sm table-hover align-middle session-table mb-0">
        <thead class="table-light"><tr>
            <th class="ps-3">Name</th><th>Target</th><th>Max Limit ↑/↓</th><th>Burst Limit ↑/↓</th><th>Status</th>
        </tr></thead><tbody>`;
    queues.forEach(q => {
        const disabled = q.disabled === 'true';
        const badge = disabled
            ? '<span class="badge bg-secondary">Disabled</span>'
            : '<span class="badge bg-success">Active</span>';
        html += `<tr>
            <td class="ps-3 fw-medium">${escHtml(q.name || '—')}</td>
            <td class="text-muted small font-monospace">${escHtml(q.target || '—')}</td>
            <td class="small font-monospace">${escHtml(q['max-limit'] || '—')}</td>
            <td class="text-muted small font-monospace">${escHtml(q['burst-limit'] || '—')}</td>
            <td>${badge}</td>
        </tr>`;
    });
    el.innerHTML = html + '</tbody></table></div>';
}

function renderQueueTree(queues) {
    const countEl = document.getElementById('queueTreeCount');
    if (countEl) countEl.textContent = queues.length;
    const el = document.getElementById('queueTreeTable');
    if (!el) return;
    if (!queues.length) {
        el.innerHTML = '<div class="text-center text-muted py-4 small"><i class="bi bi-diagram-3 me-2"></i>No queue tree entries configured</div>';
        return;
    }
    let html = `<div class="table-responsive"><table class="table table-sm table-hover align-middle session-table mb-0">
        <thead class="table-light"><tr>
            <th class="ps-3">Name</th><th>Parent</th><th>Packet Mark</th><th>Max Limit</th><th>Priority</th><th>Status</th>
        </tr></thead><tbody>`;
    queues.forEach(q => {
        const disabled = q.disabled === 'true';
        const badge = disabled
            ? '<span class="badge bg-secondary">Disabled</span>'
            : '<span class="badge bg-success">Active</span>';
        html += `<tr>
            <td class="ps-3 fw-medium">${escHtml(q.name || '—')}</td>
            <td class="text-muted small">${escHtml(q.parent || '—')}</td>
            <td class="text-muted small font-monospace">${escHtml(q['packet-mark'] || '—')}</td>
            <td class="small font-monospace">${escHtml(q['max-limit'] || '—')}</td>
            <td class="text-muted small">${escHtml(q.priority || '—')}</td>
            <td>${badge}</td>
        </tr>`;
    });
    el.innerHTML = html + '</tbody></table></div>';
}

// Router selector change → persist to session then reload
document.getElementById('routerSelector')?.addEventListener('change', function () {
    const id = this.value;
    clearInterval(refreshTimer);
    stopLivePolling();
    // Save selection to session so other pages (bandwidth, etc.) stay in sync
    fetchWithTimeout(BASE_URL + '/api/set_router.php?router_id=' + encodeURIComponent(id), {
        headers: { 'X-CSRF-Token': window.CSRF_TOKEN || '' }
    }, 5000).catch(() => {});
    loadRouterData();
    loadNetworkData();
    startLivePolling();
    refreshTimer = setInterval(loadRouterData, 15000);
});

// Manual refresh
document.getElementById('refreshBtn')?.addEventListener('click', function () {
    const icon = this.querySelector('i');
    icon?.classList.add('spinning');
    loadRouterData();
    setTimeout(() => icon?.classList.remove('spinning'), 1000);
});

function initCollapseToggles() {
    document.querySelectorAll('.js-collapse-toggle').forEach(btn => {
        const targetSelector = btn.dataset.bsTarget;
        const target = targetSelector ? document.querySelector(targetSelector) : null;
        const icon = btn.querySelector('i');
        const label = btn.querySelector('span');
        if (!target || !label) return;

        target.addEventListener('shown.bs.collapse', () => {
            label.textContent = 'Collapse';
            icon?.classList.remove('bi-chevron-down');
            icon?.classList.add('bi-chevron-up');
        });
        target.addEventListener('hidden.bs.collapse', () => {
            label.textContent = 'Expand';
            icon?.classList.remove('bi-chevron-up');
            icon?.classList.add('bi-chevron-down');
        });
    });
}

// Auto-start on page load
document.addEventListener('DOMContentLoaded', function () {
    initCollapseToggles();
    initCharts();
    if (document.getElementById('routerSelector')?.value) {
        loadRouterData();
        loadNetworkData();
        startLivePolling();
        refreshTimer = setInterval(loadRouterData, 15000);
        const badge = document.getElementById('autoRefreshBadge');
        if (badge) badge.classList.remove('d-none');
    }
});
</script>
JS;

include BASE_PATH . '/includes/footer.php';
