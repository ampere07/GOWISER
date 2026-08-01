<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireRole(ROLE_LINEMAN, ROLE_CASHIER, ROLE_USER, ROLE_ADMIN, ROLE_SUPERADMIN);

$isSuperadmin = currentRole() === ROLE_SUPERADMIN;
$routers = $isSuperadmin
    ? db()->query("SELECT router_id, name, host FROM routers ORDER BY name")->fetchAll()
    : [];

$selRouterId = selectedRouterId();
if (!$selRouterId && $isSuperadmin) {
    $selRouterId = (int)($_GET['router_id'] ?? ($routers[0]['router_id'] ?? 0));
}

$pageTitle   = 'Bandwidth Monitor';
$breadcrumbs = [['label' => 'Bandwidth Monitor']];
include BASE_PATH . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">Bandwidth Monitor</h4>
        <p class="text-muted small mb-0">Real-time per-subscriber traffic — refreshes every <span id="intervalLabel">5</span>s · cached 5 s on server</p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <?php if ($isSuperadmin && count($routers) > 1): ?>
        <select class="form-select form-select-sm" id="routerSelector" style="min-width:200px;">
            <?php foreach ($routers as $r): ?>
            <option value="<?= $r['router_id'] ?>" <?= (int)$r['router_id'] === $selRouterId ? 'selected' : '' ?>>
                <?= e($r['name']) ?> — <?= e($r['host']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php else: ?>
        <input type="hidden" id="routerSelector" value="<?= (int)$selRouterId ?>">
        <?php endif; ?>
        <select class="form-select form-select-sm" id="refreshInterval" style="width:auto;">
            <option value="3000">3s</option>
            <option value="5000" selected>5s</option>
            <option value="10000">10s</option>
            <option value="30000">30s</option>
            <option value="0">Paused</option>
        </select>
        <button class="btn btn-sm btn-outline-secondary" id="pauseBtn" title="Pause/Resume">
            <i class="bi bi-pause-fill" id="pauseIcon"></i>
        </button>
        <button class="btn btn-sm btn-primary" id="refreshNow">
            <i class="bi bi-arrow-clockwise me-1"></i>Refresh
        </button>
    </div>
</div>

<?php if (!$selRouterId && empty($routers)): ?>
<?= inlineToasts([['message' => 'No routers configured. Go to Routers to add one.', 'type' => 'warning']]) ?>
<?php else: ?>

<!-- Status bar -->
<div class="d-flex align-items-center gap-3 mb-3">
    <div class="spinner-border spinner-border-sm text-success" id="loadingSpinner"></div>
    <span id="statusMsg" class="text-muted small">Connecting…</span>
    <span class="ms-auto text-muted small" id="lastUpdate"></span>
</div>

<!-- KPI cards -->
<div class="row g-3 mb-4" id="kpiRow">
    <div class="col-6 col-lg-3">
        <div class="card kpi-card border-0">
            <div class="card-body py-3">
                <div class="text-muted small mb-1">Active Sessions</div>
                <div class="fw-bold fs-4" id="kpiSessions">—</div>
                <div class="text-muted small" id="kpiTypes">PPP + Hotspot</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card kpi-card border-0">
            <div class="card-body py-3">
                <div class="text-muted small mb-1"><i class="bi bi-arrow-down-circle text-primary me-1"></i>Total Download</div>
                <div class="fw-bold fs-4 text-primary" id="kpiTotalDown">—</div>
                <div class="text-muted small">combined ↓</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card kpi-card border-0">
            <div class="card-body py-3">
                <div class="text-muted small mb-1"><i class="bi bi-arrow-up-circle text-warning me-1"></i>Total Upload</div>
                <div class="fw-bold fs-4 text-warning" id="kpiTotalUp">—</div>
                <div class="text-muted small">combined ↑</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card kpi-card border-0">
            <div class="card-body py-3">
                <div class="text-muted small mb-1"><i class="bi bi-exclamation-triangle text-danger me-1"></i>Overconsumers</div>
                <div class="fw-bold fs-4 text-danger" id="kpiOver">—</div>
                <div class="text-muted small">&gt;85% of limit</div>
            </div>
        </div>
    </div>
</div>

<!-- Session table -->
<div class="card border-0">
    <div class="card-header bg-transparent border-bottom py-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <h6 class="mb-0 fw-semibold">Active Subscribers</h6>
            <span class="text-muted small" id="pageInfo"></span>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="badge bg-success-subtle text-success border border-success-subtle">● Normal</span>
            <span class="badge bg-warning-subtle text-warning border border-warning-subtle">● High</span>
            <span class="badge bg-danger-subtle text-danger border border-danger-subtle">● Overload</span>
            <input type="text" id="searchBox" class="form-control form-control-sm ms-2"
                   placeholder="Search user / IP…" style="width:160px;">
            <select class="form-select form-select-sm" id="limitSelect" style="width:auto;">
                <option value="50">50 / page</option>
                <option value="100" selected>100 / page</option>
                <option value="200">200 / page</option>
            </select>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0" id="bwTable">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3" style="width:30px;">#</th>
                        <th>Subscriber</th>
                        <th>IP</th>
                        <th>Uptime</th>
                        <th class="text-end"><i class="bi bi-arrow-down-circle text-primary"></i> Download</th>
                        <th class="text-end"><i class="bi bi-arrow-up-circle text-warning"></i> Upload</th>
                        <th class="text-end">Total ↓</th>
                        <th class="text-end">Total ↑</th>
                        <th style="min-width:130px;">Usage</th>
                    </tr>
                </thead>
                <tbody id="bwBody">
                    <tr>
                        <td colspan="9" class="text-center text-muted py-5">
                            <div class="spinner-border text-secondary mb-2"></div>
                            <div>Loading sessions…</div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <!-- Pagination bar -->
    <div class="card-footer bg-transparent py-2 d-flex align-items-center justify-content-between" id="paginationBar" style="display:none!important;">
        <span class="text-muted small" id="paginationInfo"></span>
        <div class="d-flex gap-1" id="paginationBtns"></div>
    </div>
</div>

<script>
(function () {
    const API_URL    = '<?= BASE_URL ?>/api/bandwidth_stats.php';
    const SUB_URL    = '<?= BASE_URL ?>/modules/subscribers/';
    let routerId     = <?= (int)$selRouterId ?>;
    let prevData     = {};   // username → {bytes_in, bytes_out, ts}
    let timer        = null;
    let paused       = false;
    let intervalMs   = 5000;
    let searchVal    = '';
    let limitVal     = 100;
    let currentOffset = 0;
    let lastTotal     = 0;

    // ── Helpers ───────────────────────────────────────────────────────

    function fmtRate(bps) {
        if (bps <= 0)          return '<span class="text-muted">—</span>';
        if (bps < 1_000)       return bps.toFixed(0) + ' bps';
        if (bps < 1_000_000)   return (bps / 1_000).toFixed(1) + ' Kbps';
        if (bps < 1_000_000_000) return (bps / 1_000_000).toFixed(2) + ' Mbps';
        return (bps / 1_000_000_000).toFixed(3) + ' Gbps';
    }

    function fmtBytes(b) {
        if (b <= 0)            return '0 B';
        if (b < 1024)          return b + ' B';
        if (b < 1048576)       return (b / 1024).toFixed(1) + ' KB';
        if (b < 1073741824)    return (b / 1048576).toFixed(1) + ' MB';
        return (b / 1073741824).toFixed(2) + ' GB';
    }

    function calcRate(uname, bytesIn, bytesOut, ts) {
        const prev = prevData[uname];
        prevData[uname] = { bytes_in: bytesIn, bytes_out: bytesOut, ts };
        if (!prev) return { rx: 0, tx: 0 };
        const dt = ts - prev.ts;
        if (dt <= 0.1) return { rx: 0, tx: 0 };
        return {
            rx: Math.max(0, ((bytesOut - prev.bytes_out) * 8) / dt), // download to subscriber = bytes_out
            tx: Math.max(0, ((bytesIn  - prev.bytes_in)  * 8) / dt), // upload from subscriber = bytes_in
        };
    }

    function usagePct(rate, max) {
        if (!max || max <= 0) return null;
        return Math.min(100, (rate / max) * 100);
    }

    function usageBar(rxRate, txRate, maxDown, maxUp) {
        const pctDown = usagePct(rxRate, maxDown);
        const pctUp   = usagePct(txRate, maxUp);
        const pct     = pctDown !== null ? pctDown : (pctUp !== null ? pctUp : null);
        if (pct === null) {
            return '<span class="text-muted small">No limit</span>';
        }
        const color = pct > 85 ? 'danger' : pct > 60 ? 'warning' : 'success';
        return `<div class="d-flex align-items-center gap-1">
            <div class="progress flex-grow-1" style="height:6px;">
                <div class="progress-bar bg-${color}" style="width:${pct.toFixed(1)}%"></div>
            </div>
            <span class="small text-${color} fw-semibold" style="width:38px;text-align:right;">${pct.toFixed(0)}%</span>
        </div>`;
    }

    function rowClass(rxRate, maxDown) {
        const pct = usagePct(rxRate, maxDown);
        if (pct === null) return '';
        if (pct > 85) return 'table-danger';
        if (pct > 60) return 'table-warning';
        return '';
    }

    function typeBadge(type) {
        if (type === 'ppp')
            return '<span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size:.68rem;">PPP</span>';
        if (type === 'radius')
            return '<span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size:.68rem;">RADIUS</span>';
        return '<span class="badge bg-info-subtle text-info border border-info-subtle" style="font-size:.68rem;">Hotspot</span>';
    }

    // ── Fetch & render ────────────────────────────────────────────────

    function buildUrl() {
        const params = new URLSearchParams({ limit: limitVal, offset: currentOffset });
        if (routerId)  params.set('router_id', routerId);
        if (searchVal) params.set('search', searchVal);
        return API_URL + '?' + params.toString();
    }

    function fetch() {
        const spinner = document.getElementById('loadingSpinner');
        if (spinner) spinner.style.display = '';

        window.fetch(buildUrl())
            .then(r => r.json())
            .then(data => {
                if (spinner) spinner.style.display = 'none';
                if (!data.success) {
                    document.getElementById('statusMsg').textContent = '⚠ ' + (data.message || 'Error');
                    return;
                }

                const ts       = data.ts;
                const sessions = data.sessions || [];
                lastTotal      = data.total;

                // Rate calc for visible page only
                const decorated = sessions.map(s => {
                    const rate = calcRate(s.username, s.bytes_in, s.bytes_out, ts);
                    return { ...s, rxRate: rate.rx, txRate: rate.tx };
                });

                // KPI from server aggregates
                const totalDown = decorated.reduce((acc, s) => acc + s.rxRate, 0);
                const totalUp   = decorated.reduce((acc, s) => acc + s.txRate, 0);
                const overCount = decorated.filter(s => usagePct(s.rxRate, s.max_down) > 85).length;

                document.getElementById('kpiSessions').textContent = data.total.toLocaleString();
                let kpiTypesText = `PPP: ${data.ppp_count.toLocaleString()} / Hotspot: ${data.hs_count.toLocaleString()}`;
                if (data.radius_count) kpiTypesText += ` / RADIUS: ${data.radius_count.toLocaleString()}`;
                document.getElementById('kpiTypes').textContent = kpiTypesText;
                document.getElementById('kpiTotalDown').innerHTML  = fmtRate(totalDown);
                document.getElementById('kpiTotalUp').innerHTML    = fmtRate(totalUp);
                document.getElementById('kpiOver').textContent     = overCount.toLocaleString();

                const now = new Date();
                document.getElementById('lastUpdate').textContent =
                    'Updated ' + now.toLocaleTimeString() +
                    (data.cached ? ' (cached)' : ' (live)') +
                    ' — ' + data.router_name;
                document.getElementById('statusMsg').textContent =
                    data.total.toLocaleString() + ' active session' + (data.total !== 1 ? 's' : '');

                renderTable(decorated);
                renderPagination(data.total, data.offset, data.limit);
            })
            .catch(err => {
                if (spinner) spinner.style.display = 'none';
                document.getElementById('statusMsg').textContent = '⚠ ' + err.message;
            });
    }

    function renderTable(sessions) {
        const tbody = document.getElementById('bwBody');

        if (sessions.length === 0) {
            tbody.innerHTML = `<tr><td colspan="9" class="text-center text-muted py-5">
                <i class="bi bi-wifi-off d-block fs-2 mb-2"></i>No active sessions</td></tr>`;
            return;
        }

        const rowBase = currentOffset;
        const fragment = document.createDocumentFragment();
        sessions.forEach((s, i) => {
            const tr = document.createElement('tr');
            tr.className = rowClass(s.rxRate, s.max_down);
            const subLink = s.subscriber_id
                ? `<a href="${SUB_URL}view/${s.subscriber_id}" class="text-decoration-none fw-medium small">${esc(s.subscriber)}</a>`
                : `<span class="fw-medium small">${esc(s.subscriber)}</span>`;
            tr.innerHTML = `
                <td class="ps-3 text-muted small">${rowBase + i + 1}</td>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        ${typeBadge(s.type)}
                        <div>${subLink}
                            <div class="text-muted font-monospace" style="font-size:.72rem;">${esc(s.username)}</div>
                        </div>
                    </div>
                </td>
                <td class="font-monospace small text-muted">${esc(s.address)}</td>
                <td class="small text-muted">${esc(s.uptime)}</td>
                <td class="text-end fw-semibold text-primary small">${fmtRate(s.rxRate)}</td>
                <td class="text-end fw-semibold text-warning small">${fmtRate(s.txRate)}</td>
                <td class="text-end text-muted small">${fmtBytes(s.bytes_out)}</td>
                <td class="text-end text-muted small">${fmtBytes(s.bytes_in)}</td>
                <td>${usageBar(s.rxRate, s.txRate, s.max_down, s.max_up)}</td>`;
            fragment.appendChild(tr);
        });
        tbody.replaceChildren(fragment);
    }

    function renderPagination(total, offset, limit) {
        const bar  = document.getElementById('paginationBar');
        const info = document.getElementById('paginationInfo');
        const btns = document.getElementById('paginationBtns');
        if (total <= limit) { bar.style.display = 'none'; return; }
        bar.style.removeProperty('display');

        const from    = offset + 1;
        const to      = Math.min(offset + limit, total);
        const pages   = Math.ceil(total / limit);
        const curPage = Math.floor(offset / limit);

        info.textContent = `Showing ${from.toLocaleString()}–${to.toLocaleString()} of ${total.toLocaleString()} sessions`;

        let html = `<button class="btn btn-sm btn-outline-secondary" ${curPage === 0 ? 'disabled' : ''} data-page="${curPage - 1}">‹ Prev</button>`;
        const start = Math.max(0, curPage - 2), end = Math.min(pages - 1, curPage + 2);
        for (let p = start; p <= end; p++) {
            html += `<button class="btn btn-sm ${p === curPage ? 'btn-primary' : 'btn-outline-secondary'}" data-page="${p}">${p + 1}</button>`;
        }
        html += `<button class="btn btn-sm btn-outline-secondary" ${curPage >= pages - 1 ? 'disabled' : ''} data-page="${curPage + 1}">Next ›</button>`;
        btns.innerHTML = html;

        btns.querySelectorAll('[data-page]').forEach(btn => {
            btn.addEventListener('click', () => {
                currentOffset = parseInt(btn.dataset.page) * limitVal;
                prevData = {};
                fetch();
            });
        });

        document.getElementById('pageInfo').textContent =
            `(page ${curPage + 1} of ${pages})`;
    }

    function esc(str) {
        return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    // ── Controls ──────────────────────────────────────────────────────

    function startTimer() {
        clearTimeout(timer);
        if (!paused && intervalMs > 0) {
            timer = setTimeout(() => { fetch(); startTimer(); }, intervalMs);
        }
    }

    document.getElementById('refreshNow')?.addEventListener('click', () => {
        fetch(); startTimer();
    });

    document.getElementById('pauseBtn')?.addEventListener('click', () => {
        paused = !paused;
        document.getElementById('pauseIcon').className = paused ? 'bi bi-play-fill' : 'bi bi-pause-fill';
        if (!paused) { fetch(); startTimer(); }
    });

    document.getElementById('refreshInterval')?.addEventListener('change', function () {
        intervalMs = parseInt(this.value);
        document.getElementById('intervalLabel').textContent =
            intervalMs > 0 ? (intervalMs / 1000) + '' : '∞';
        startTimer();
    });

    document.getElementById('routerSelector')?.addEventListener('change', function () {
        routerId      = parseInt(this.value);
        currentOffset = 0;
        prevData      = {};
        fetch(); startTimer();
    });

    document.getElementById('limitSelect')?.addEventListener('change', function () {
        limitVal      = parseInt(this.value);
        currentOffset = 0;
        prevData      = {};
        fetch();
    });

    let searchTimer = null;
    document.getElementById('searchBox')?.addEventListener('input', function () {
        clearTimeout(searchTimer);
        const val = this.value.trim();
        searchTimer = setTimeout(() => {
            searchVal     = val;
            currentOffset = 0;
            prevData      = {};
            fetch();
        }, 400);
    });

    // Boot
    fetch(); startTimer();
})();
</script>

<?php endif; ?>
<?php include BASE_PATH . '/includes/footer.php'; ?>
