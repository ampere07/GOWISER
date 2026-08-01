<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once BASE_PATH . '/lib/RouterosAPI.php';
requireMinRole(ROLE_ADMIN);

$allRouters = db()->query("
    SELECT router_id, name, host, port, api_port, username, password,
           COALESCE(auth_type,'local') AS auth_type,
           brand, address AS location, status AS db_status
    FROM routers ORDER BY name
")->fetchAll();

// Run diagnostics on every router
$results = [];
foreach ($allRouters as $r) {
    $entry = [
        'router_id'    => $r['router_id'],
        'router_name'  => $r['name'],
        'router_host'  => $r['host'],
        'auth_type'    => $r['auth_type'],
        'brand'        => $r['brand'] ?? '',
        'location'     => $r['location'] ?? '',
        'db_status'    => $r['db_status'] ?? '',
        'reachable'    => false,
        'um_installed' => false,
        'um_enabled'   => false,
        'um_settings'  => [],
        'radius_clients'=> [],
        'groups'          => [],
        'active_sessions' => [],
        'active_count'    => 0,
        'identity'     => null,
        'sysinfo'      => [],
        'error'        => null,
    ];

    $api = new RouterosAPI($r['host'], (int)($r['api_port'] ?: $r['port']), 5);
    if (!$api->connect($r['username'], decryptData($r['password']))) {
        $entry['error'] = $api->error ?: 'Connection failed';
        $results[] = $entry;
        continue;
    }

    $entry['reachable'] = true;
    $entry['identity']  = $api->getIdentity();
    $entry['sysinfo']   = $api->getSystemResource();
    $entry['um_installed'] = $api->isUserManagerInstalled();

    if ($entry['um_installed']) {
        $settings = $api->getUserManagerSettings();
        $entry['um_settings'] = $settings;
        $entry['um_enabled']  = ($settings['enabled'] ?? 'yes') !== 'no';

        $entry['groups'] = $api->getUserManagerGroups();

        // Active sessions (full rows, not just count)
        $entry['active_sessions'] = $api->getUserManagerActiveSessions();
        $entry['active_count']    = count($entry['active_sessions']);
    }

    $entry['radius_clients'] = $api->getRadiusClients();
    $api->disconnect();

    $results[] = $entry;
}

$pageTitle   = 'RADIUS / User Manager';
$breadcrumbs = [['label' => 'RADIUS / User Manager']];
include BASE_PATH . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">RADIUS / User Manager</h4>
        <p class="text-muted small mb-0">Connection test and group list for all routers</p>
    </div>
    <a href="javascript:location.reload()" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-clockwise me-1"></i>Refresh
    </a>
</div>

<?php foreach ($results as $r): ?>
<?php
    $isRadius = $r['auth_type'] === 'radius';
    $hasUM    = $r['reachable'] && $r['um_installed'];
?>

<div class="card border-0 mb-4">
    <div class="card-header bg-transparent border-bottom py-2 d-flex align-items-center gap-2 flex-wrap">
        <i class="bi bi-router text-muted"></i>
        <strong><?= e($r['router_name']) ?></strong>
        <span class="text-muted small"><?= e($r['router_host']) ?></span>

        <?php if (!$r['reachable']): ?>
        <span class="badge bg-danger ms-auto">Offline</span>
        <?php elseif (!$r['um_installed']): ?>
        <span class="badge bg-secondary ms-auto">No User Manager</span>
        <?php elseif (!$r['um_enabled']): ?>
        <span class="badge bg-warning text-dark ms-auto">UM Disabled</span>
        <?php else: ?>
        <span class="badge bg-success ms-auto">User Manager OK</span>
        <?php endif; ?>

        <?php if ($isRadius): ?>
        <span class="badge bg-primary">auth: RADIUS</span>
        <?php else: ?>
        <span class="badge bg-secondary">auth: Local</span>
        <?php endif; ?>
    </div>

    <div class="card-body py-3">

        <?php if ($r['error']): ?>
        <p class="text-danger small mb-0"><i class="bi bi-exclamation-circle me-1"></i><?= e($r['error']) ?></p>

        <?php else: ?>

        <!-- Router info panel -->
        <?php
            $sys      = $r['sysinfo'];
            $freeMem  = isset($sys['free-memory'])  ? round((int)$sys['free-memory']  / 1048576, 1) : null;
            $totalMem = isset($sys['total-memory']) ? round((int)$sys['total-memory'] / 1048576, 1) : null;
            $freeHdd  = isset($sys['free-hdd-space'])  ? round((int)$sys['free-hdd-space']  / 1048576, 1) : null;
            $totalHdd = isset($sys['total-hdd-space']) ? round((int)$sys['total-hdd-space'] / 1048576, 1) : null;
            $cpuLoad  = $sys['cpu-load'] ?? null;
            $memPct   = ($totalMem && $totalMem > 0) ? round((($totalMem - $freeMem) / $totalMem) * 100) : null;
            $hddPct   = ($totalHdd && $totalHdd > 0) ? round((($totalHdd - $freeHdd) / $totalHdd) * 100) : null;
        ?>
        <div class="row g-2 mb-3 small">
            <?php if ($r['identity']): ?>
            <div class="col-6 col-md-3">
                <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;">Identity</div>
                <div class="fw-semibold font-monospace"><?= e($r['identity']) ?></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($sys['board-name'])): ?>
            <div class="col-6 col-md-3">
                <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;">Board</div>
                <div class="fw-semibold"><?= e($sys['board-name']) ?></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($sys['version'])): ?>
            <div class="col-6 col-md-3">
                <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;">RouterOS</div>
                <div class="fw-semibold font-monospace"><?= e($sys['version']) ?></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($sys['uptime'])): ?>
            <div class="col-6 col-md-3">
                <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;">Uptime</div>
                <div class="fw-semibold font-monospace"><?= e($sys['uptime']) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($r['brand'] || $r['location']): ?>
            <?php if ($r['brand']): ?>
            <div class="col-6 col-md-3">
                <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;">Brand</div>
                <div class="fw-semibold"><?= e($r['brand']) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($r['location']): ?>
            <div class="col-6 col-md-3">
                <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;">Location</div>
                <div class="fw-semibold"><?= e($r['location']) ?></div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            <?php if ($cpuLoad !== null): ?>
            <div class="col-6 col-md-3">
                <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;">CPU Load</div>
                <div class="d-flex align-items-center gap-1">
                    <div class="progress flex-grow-1" style="height:6px;">
                        <div class="progress-bar <?= $cpuLoad > 80 ? 'bg-danger' : ($cpuLoad > 50 ? 'bg-warning' : 'bg-success') ?>"
                             style="width:<?= min(100,(int)$cpuLoad) ?>%"></div>
                    </div>
                    <span class="fw-semibold"><?= (int)$cpuLoad ?>%</span>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($memPct !== null): ?>
            <div class="col-6 col-md-3">
                <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;">Memory</div>
                <div class="d-flex align-items-center gap-1">
                    <div class="progress flex-grow-1" style="height:6px;">
                        <div class="progress-bar <?= $memPct > 80 ? 'bg-danger' : ($memPct > 60 ? 'bg-warning' : 'bg-info') ?>"
                             style="width:<?= min(100,$memPct) ?>%"></div>
                    </div>
                    <span class="fw-semibold"><?= $memPct ?>%</span>
                </div>
                <div class="text-muted" style="font-size:.7rem;"><?= $freeMem ?> / <?= $totalMem ?> MB free</div>
            </div>
            <?php endif; ?>
            <?php if ($hddPct !== null): ?>
            <div class="col-6 col-md-3">
                <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;">Storage</div>
                <div class="d-flex align-items-center gap-1">
                    <div class="progress flex-grow-1" style="height:6px;">
                        <div class="progress-bar <?= $hddPct > 80 ? 'bg-danger' : ($hddPct > 60 ? 'bg-warning' : 'bg-secondary') ?>"
                             style="width:<?= min(100,$hddPct) ?>%"></div>
                    </div>
                    <span class="fw-semibold"><?= $hddPct ?>%</span>
                </div>
                <div class="text-muted" style="font-size:.7rem;"><?= $freeHdd ?> / <?= $totalHdd ?> MB free</div>
            </div>
            <?php endif; ?>
        </div>
        <hr class="my-2">

        <!-- Status row -->
        <div class="row g-2 mb-3">
            <div class="col-auto">
                <span class="badge <?= $r['reachable'] ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-danger-subtle text-danger border border-danger-subtle' ?>">
                    <i class="bi bi-<?= $r['reachable'] ? 'check-circle' : 'x-circle' ?> me-1"></i>
                    API <?= $r['reachable'] ? 'Reachable' : 'Unreachable' ?>
                </span>
            </div>
            <div class="col-auto">
                <span class="badge <?= $r['um_installed'] ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-secondary-subtle text-secondary border border-secondary-subtle' ?>">
                    <i class="bi bi-<?= $r['um_installed'] ? 'check-circle' : 'dash-circle' ?> me-1"></i>
                    User Manager <?= $r['um_installed'] ? 'Installed' : 'Not Found' ?>
                </span>
            </div>
            <?php if ($r['um_installed']): ?>
            <div class="col-auto">
                <span class="badge <?= $r['um_enabled'] ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-warning-subtle text-warning border border-warning-subtle' ?>">
                    <i class="bi bi-<?= $r['um_enabled'] ? 'check-circle' : 'exclamation-circle' ?> me-1"></i>
                    UM <?= $r['um_enabled'] ? 'Enabled' : 'Disabled' ?>
                </span>
            </div>
            <div class="col-auto">
                <span class="badge bg-info-subtle text-info border border-info-subtle">
                    <i class="bi bi-people me-1"></i>
                    <?= $r['active_count'] ?> Active Session<?= $r['active_count'] !== 1 ? 's' : '' ?>
                </span>
            </div>
            <?php endif; ?>
            <?php if (!empty($r['radius_clients'])): ?>
            <div class="col-auto">
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                    <i class="bi bi-hdd-network me-1"></i>
                    <?= count($r['radius_clients']) ?> RADIUS Client<?= count($r['radius_clients']) !== 1 ? 's' : '' ?>
                </span>
            </div>
            <?php endif; ?>
        </div>

        <!-- RADIUS clients -->
        <?php if (!empty($r['radius_clients'])): ?>
        <p class="text-muted small fw-semibold mb-1">RADIUS Clients (/radius)</p>
        <table class="table table-sm table-bordered align-middle mb-3" style="font-size:.82rem;">
            <thead class="table-light">
                <tr>
                    <th>Service</th>
                    <th>Address</th>
                    <th>Port</th>
                    <th>Disabled</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($r['radius_clients'] as $rc): ?>
                <tr class="<?= ($rc['disabled'] ?? 'false') === 'true' ? 'text-muted' : '' ?>">
                    <td class="font-monospace"><?= e($rc['service'] ?? '—') ?></td>
                    <td class="font-monospace"><?= e($rc['address'] ?? '—') ?></td>
                    <td><?= e($rc['authentication-port'] ?? $rc['port'] ?? '1812') ?></td>
                    <td>
                        <?php if (($rc['disabled'] ?? 'false') === 'true'): ?>
                        <span class="text-warning small">yes</span>
                        <?php else: ?>
                        <span class="text-success small">no</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- Groups -->
        <?php if ($r['um_installed']): ?>
        <p class="text-muted small fw-semibold mb-1">
            User Manager Groups
            <?php if (!empty($r['groups'])): ?>
            <span class="fw-normal">(<?= count($r['groups']) ?>)</span>
            <?php endif; ?>
        </p>
        <?php if (empty($r['groups'])): ?>
        <p class="text-muted small">No groups found. Create groups in Winbox › Tools › User Manager › Groups.</p>
        <?php else: ?>
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">#</th>
                    <th>Group Name</th>
                    <th>Outer Auth</th>
                    <th>Inner Auth</th>
                    <th>Rate Limit</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($r['groups'] as $i => $g): ?>
                <tr>
                    <td class="ps-3 text-muted small"><?= $i + 1 ?></td>
                    <td class="fw-medium font-monospace"><?= e($g['name'] ?? '—') ?></td>
                    <td class="text-muted small font-monospace"><?= e($g['outer-auths'] ?? '—') ?></td>
                    <td class="text-muted small font-monospace"><?= e($g['inner-auths'] ?? '—') ?></td>
                    <td class="font-monospace small"><?= e($g['rate-limit'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <!-- Active Sessions -->
        <?php if ($r['um_installed'] && !empty($r['active_sessions'])): ?>
        <?php
            $sessions = $r['active_sessions'];
            $preferred = ['user','caller-id','nas-ip-address','framed-ip-address',
                          'calling-station-id','session-time','upload','download','status'];
            $allKeys = [];
            foreach ($sessions as $row) {
                foreach (array_keys($row) as $k) {
                    if (!in_array($k, $allKeys)) $allKeys[] = $k;
                }
            }
            $ordered = array_values(array_filter($preferred, fn($k) => in_array($k, $allKeys)));
            foreach ($allKeys as $k) { if (!in_array($k, $ordered)) $ordered[] = $k; }
        ?>
        <?php $sessId = 'sessions_' . $r['router_id']; ?>
        <button class="btn btn-sm btn-outline-secondary mt-3 mb-1 d-flex align-items-center gap-2"
                type="button" data-bs-toggle="collapse" data-bs-target="#<?= $sessId ?>"
                aria-expanded="false" aria-controls="<?= $sessId ?>">
            <i class="bi bi-people me-1"></i>
            Active Sessions
            <span class="badge bg-secondary"><?= number_format(count($sessions)) ?></span>
            <i class="bi bi-chevron-down toggle-icon" style="font-size:.75rem;transition:transform .2s;"></i>
        </button>
        <div class="collapse" id="<?= $sessId ?>">
        <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0" style="font-size:.8rem;">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <?php foreach ($ordered as $k): ?><th><?= e($k) ?></th><?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($sessions as $i => $s): ?>
            <tr>
                <td class="text-muted"><?= number_format($i + 1) ?></td>
                <?php foreach ($ordered as $k): ?>
                <?php $v = $s[$k] ?? ''; ?>
                <?php if (in_array($k, ['upload','download'])): ?>
                    <td><code><?= $v !== '' ? e(formatBytes((int)$v)) : '—' ?></code></td>
                <?php elseif ($k === 'status'): ?>
                    <td><span class="badge <?= strtolower($v) === 'start' ? 'bg-success' : 'bg-secondary' ?>"><?= e($v) ?></span></td>
                <?php else: ?>
                    <td class="font-monospace"><?= $v !== '' ? e($v) : '<span class="text-muted">—</span>' ?></td>
                <?php endif; ?>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        </div>
        <script>
        document.querySelector('[data-bs-target="#<?= $sessId ?>"]')?.addEventListener('click', function() {
            const icon = this.querySelector('.toggle-icon');
            const expanded = this.getAttribute('aria-expanded') === 'true';
            if (icon) icon.style.transform = expanded ? '' : 'rotate(180deg)';
        });
        </script>
        <?php endif; ?>

        <?php elseif ($r['reachable']): ?>
        <p class="text-muted small mb-0">
            <i class="bi bi-info-circle me-1"></i>
            User Manager is not installed on this router. In RouterOS v6 install the
            <strong>user-manager</strong> package. In RouterOS v7 it is built-in — enable it under
            <strong>Tools › User Manager</strong>.
        </p>
        <?php endif; ?>

        <?php endif; // end no-error ?>
    </div>
</div>

<?php endforeach; ?>

<?php if (empty($allRouters)): ?>
<?= inlineToasts([['message' => 'No routers configured yet.', 'type' => 'info']]) ?>
<?php endif; ?>

<?php include BASE_PATH . '/includes/footer.php'; ?>
