<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireRole(ROLE_ADMIN, ROLE_SUPERADMIN);

// ── Last run per job type ──────────────────────────────────────
$lastReminder   = db()->query("SELECT * FROM cronjob WHERE run_type='reminder'         ORDER BY ran_at DESC LIMIT 1")->fetch();
$lastForceDis   = db()->query("SELECT * FROM cronjob WHERE run_type='force_disconnect' ORDER BY ran_at DESC LIMIT 1")->fetch();

// ── Expiring in next 7 days with notification status ───────────
$_cjToday  = appToday();
$_cjPlus7d = date('Y-m-d', strtotime('+7 days'));
$_cjStmt   = db()->prepare("
    SELECT s.subscriber_id, s.account_number, s.firstname, s.lastname,
           s.email, s.subscription_end,
           p.title AS plan_title,
           r.name  AS router_name,
           DATEDIFF(s.subscription_end, ?) AS days_left
    FROM subscribers s
    LEFT JOIN plans   p ON p.plan_id   = s.plan_id
    LEFT JOIN routers r ON r.router_id = s.router_id
    WHERE s.status = 'active'
      AND s.subscription_end BETWEEN ? AND ?
    ORDER BY s.subscription_end ASC
    LIMIT 200
");
$_cjStmt->execute([$_cjToday, $_cjToday, $_cjPlus7d]);
$upcoming = $_cjStmt->fetchAll();

// ── Combined run history ───────────────────────────────────────
$recentRuns = db()->query("SELECT * FROM cronjob WHERE run_type IN ('reminder', 'force_disconnect') ORDER BY ran_at DESC LIMIT 30")->fetchAll();

// ── Stats ──────────────────────────────────────────────────────
$expiredCount = (int)db()->query("SELECT COUNT(*) FROM subscribers WHERE status='expired'")->fetchColumn();
$totalRuns    = (int)db()->query("SELECT COUNT(*) FROM cronjob")->fetchColumn();

// ── Paths for setup instructions ───────────────────────────────
$phpBin  = PHP_BINARY ?: '/usr/bin/php';
$appUrl  = rtrim(defined('APP_URL') ? APP_URL : BASE_URL, '/');
$selectedRouterId = (int)(selectedRouterId() ?: 0);
$remToken = $_ENV['REMINDER_CRON_TOKEN'] ?? ($_ENV['CRON_TOKEN'] ?? '');
$disToken = $_ENV['FORCE_DISCONNECT_TOKEN'] ?? ($_ENV['CRON_TOKEN'] ?? '');
$remUrl  = $appUrl . '/modules/cronjobs/reminders.php';
$disUrl  = $appUrl . '/modules/cronjobs/force_disconnect.php';
if ($selectedRouterId > 0) {
    $disUrl .= '?router_id=' . $selectedRouterId;
}
$disCliArg = $selectedRouterId > 0 ? ' --router_id=' . $selectedRouterId : '';
$remPath = BASE_PATH . '/modules/cronjobs/reminders.php';
$disPath = BASE_PATH . '/modules/cronjobs/force_disconnect.php';
$remCurl = "curl -fsS -H 'X-Cron-Token: REMINDER_CRON_TOKEN' " . escapeshellarg($remUrl);
$disCurl = "curl -fsS -H 'X-Cron-Token: FORCE_DISCONNECT_TOKEN' " . escapeshellarg($disUrl);

function cronTimeAgo(?array $row): string {
    if (!$row) return '—';
    $s = time() - strtotime($row['ran_at']);
    if ($s < 120)      return $s . 's ago';
    if ($s < 7200)     return round($s / 60) . 'min ago';
    if ($s < 86400)    return round($s / 3600) . 'h ago';
    return round($s / 86400) . 'd ago';
}

$pageTitle   = 'Cron Jobs';
$breadcrumbs = [['label' => 'Cron Jobs']];
include BASE_PATH . '/includes/header.php';
?>

<!-- Page header -->
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">Cron Jobs</h4>
        <p class="text-muted small mb-0">Scheduled daily — reminders and router policy enforcement</p>
    </div>
    <?php if (hasMinRole(ROLE_ADMIN)): ?>
    <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-sm btn-outline-primary" id="runRemindersBtn"
                data-url="<?= e($remUrl) ?>"
                data-token="<?= e($remToken) ?>">
            <i class="bi bi-envelope-fill me-1"></i>Run Reminders Now
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="runForceDisBtn"
                data-url="<?= e($disUrl) ?>"
                data-token="<?= e($disToken) ?>"
                data-router-name="<?= e($selectedRouterId > 0 ? selectedRouterName() : 'all routers') ?>">
            <i class="bi bi-plug-fill me-1"></i>Run Force-Disconnect Now
        </button>
    </div>
    <?php endif; ?>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card kpi-card kpi-l-primary h-100">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small mb-1">Last Reminder Run</div>
                        <?php if ($lastReminder): ?>
                        <div class="kpi-value"><?= (int)$lastReminder['processed'] ?> sent</div>
                        <div class="small text-muted">
                            <?= date('M d, Y H:i', strtotime($lastReminder['ran_at'])) ?>
                            <span class="badge bg-secondary ms-1"><?= cronTimeAgo($lastReminder) ?></span>
                        </div>
                        <?php else: ?>
                        <div class="kpi-value text-muted">—</div>
                        <div class="small text-danger">Never run</div>
                        <?php endif; ?>
                    </div>
                    <div class="kpi-icon bg-primary-subtle text-primary">
                        <i class="bi bi-envelope-check"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card kpi-card kpi-l-warning h-100">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small mb-1">Expiring in 7 Days</div>
                        <div class="kpi-value <?= count($upcoming) > 0 ? 'text-warning' : '' ?>">
                            <?= count($upcoming) ?>
                        </div>
                        <div class="small text-muted">Active subscribers</div>
                    </div>
                    <div class="kpi-icon bg-warning-subtle text-warning">
                        <i class="bi bi-bell-fill"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card kpi-card kpi-l-secondary h-100">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small mb-1">Last Force-Disconnect Run</div>
                        <?php if ($lastForceDis): ?>
                        <div class="kpi-value"><?= (int)$lastForceDis['processed'] ?> disconnected</div>
                        <div class="small text-muted">
                            <?= date('M d, Y H:i', strtotime($lastForceDis['ran_at'])) ?>
                            <span class="badge bg-secondary ms-1"><?= cronTimeAgo($lastForceDis) ?></span>
                        </div>
                        <?php else: ?>
                        <div class="kpi-value text-muted">—</div>
                        <div class="small text-danger">Never run</div>
                        <?php endif; ?>
                    </div>
                    <div class="kpi-icon bg-secondary-subtle text-secondary">
                        <i class="bi bi-plug-fill"></i>
                    </div>
                </div>
            </div>
        </div>
    </div> 
</div>

<!-- Upcoming expirations table -->
<div class="card mb-4">
    <div class="card-header py-3">
        <h6 class="mb-0 fw-semibold">
            <i class="bi bi-calendar-event me-2 text-warning"></i>
            Expiring in Next 7 Days
            <?php if (count($upcoming)): ?>
            <span class="badge bg-warning text-dark ms-1"><?= count($upcoming) ?></span>
            <?php endif; ?>
        </h6>
    </div>
    <div class="card-body p-0">
        <?php if (empty($upcoming)): ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-check-circle d-block fs-2 mb-2 text-success"></i>
            No active subscribers expiring in the next 7 days.
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Account #</th>
                        <th>Subscriber</th>
                        <th>Email</th>
                        <th>Plan</th>
                        <th>Router</th>
                        <th>Expiry Date</th>
                        <th>Days Left</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($upcoming as $sub): ?>
                    <?php $dLeft = (int)$sub['days_left']; ?>
                    <tr class="<?= $dLeft === 0 ? 'table-danger' : ($dLeft <= 3 ? 'table-warning' : '') ?>">
                        <td class="ps-3 font-monospace small">
                            <a href="<?= BASE_URL ?>/modules/subscribers/view/<?= (int)$sub['subscriber_id'] ?>"
                               class="text-decoration-none fw-medium">
                                <?= e($sub['account_number']) ?>
                            </a>
                        </td>
                        <td class="fw-medium small"><?= e($sub['firstname'] . ' ' . $sub['lastname']) ?></td>
                        <td class="small text-muted"><?= e($sub['email'] ?? '—') ?></td>
                        <td class="small"><?= e($sub['plan_title'] ?? '—') ?></td>
                        <td class="small text-muted"><?= e($sub['router_name'] ?? '—') ?></td>
                        <td class="small"><?= formatDate($sub['subscription_end']) ?></td>
                        <td>
                            <span class="badge <?= $dLeft === 0 ? 'bg-danger' : ($dLeft <= 3 ? 'bg-warning text-dark' : 'bg-secondary') ?>">
                                <?= $dLeft === 0 ? 'Today' : $dLeft . 'd' ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Run history -->
<div class="card mb-4">
    <div class="card-header py-3">
        <h6 class="mb-0 fw-semibold">
            <i class="bi bi-journal-text me-2 text-primary"></i>Recent Cron Run History
        </h6>
    </div>
    <div class="card-body p-0">
        <?php if (empty($recentRuns)): ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-clock d-block fs-2 mb-2"></i>
            No cron runs recorded yet. Set up the cron job using the instructions below.
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Job</th>
                        <th>Ran At</th>
                        <th>Processed</th>
                        <th>Errors</th>
                        <th class="pe-3">Result</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentRuns as $run): ?>
                    <tr>
                        <td class="ps-3">
                            <?php if ($run['run_type'] === 'reminder'): ?>
                            <span class="badge bg-primary bg-opacity-75">
                                <i class="bi bi-envelope me-1"></i>Reminder
                            </span>
                            <?php elseif ($run['run_type'] === 'force_disconnect'): ?>
                            <span class="badge bg-secondary bg-opacity-75">
                                <i class="bi bi-plug-fill me-1"></i>Force-Disconnect
                            </span>
                            <?php else: ?>
                            <span class="badge bg-dark bg-opacity-75">
                                <i class="bi bi-gear me-1"></i><?= e(ucwords(str_replace('_', ' ', $run['run_type']))) ?>
                            </span>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <div><?= date('M d, Y', strtotime($run['ran_at'])) ?></div>
                            <div class="text-muted"><?= date('H:i:s', strtotime($run['ran_at'])) ?></div>
                        </td>
                        <td>
                            <?php if ((int)$run['processed'] > 0): ?>
                            <span class="badge <?= match($run['run_type']) {
                                'reminder' => 'bg-primary',
                                'force_disconnect' => 'bg-secondary',
                                default => 'bg-dark',
                            } ?>">
                                <?= (int)$run['processed'] ?>
                                <?= match($run['run_type']) {
                                    'reminder' => 'sent',
                                    'force_disconnect' => 'disconnected',
                                    default => 'processed',
                                } ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted small">None</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int)$run['errors_count'] > 0): ?>
                            <?php $errArr = json_decode($run['errors_json'] ?? '[]', true) ?: []; ?>
                            <span class="badge bg-warning text-dark"
                                  title="<?= e(implode('; ', $errArr)) ?>">
                                <?= (int)$run['errors_count'] ?> error<?= $run['errors_count'] > 1 ? 's' : '' ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="pe-3">
                            <?php
                                $proc = (int)$run['processed'];
                                $errs = (int)$run['errors_count'];
                            ?>
                            <?php if ($errs > 0 && $proc === 0): ?>
                            <span class="badge bg-danger">Failed</span>
                            <?php elseif ($errs > 0): ?>
                            <span class="badge bg-warning text-dark">With Errors</span>
                            <?php elseif ($proc > 0): ?>
                            <span class="badge bg-success">OK</span>
                            <?php else: ?>
                            <span class="badge bg-secondary bg-opacity-50 text-muted">Nothing to do</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Setup instructions (collapsible) -->
<div class="card">
    <div class="card-header py-2">
        <button class="btn btn-link text-start fw-semibold text-decoration-none w-100 p-0 d-flex align-items-center gap-2 text-body"
                data-bs-toggle="collapse" data-bs-target="#cronSetupBody" aria-expanded="false">
            <i class="bi bi-gear text-secondary"></i>
            Cron Job Setup Instructions
            <i class="bi bi-chevron-down ms-auto small"></i>
        </button>
    </div>
    <div class="collapse" id="cronSetupBody">
        <div class="card-body">
            <p class="text-muted small mb-3">
                Reminders notify subscribers 1–7 days before expiry. Force-disconnect
                marks past-due subscribers as expired and enforces the selected router policy.
            </p>

            <div class="mb-4">
                <h6 class="fw-semibold small">Linux / cPanel crontab — Asia/Manila timezone</h6>
                <div class="bg-dark text-success p-3 rounded font-monospace small">
                    # Expiry Reminders — 11:30 PM daily<br>
                    30 23 * * * <?= htmlspecialchars($phpBin) ?> <?= htmlspecialchars($remPath) ?><br><br>
                    # Force-Disconnect — 11:45 PM daily (marks expired and enforces router policy)<br>
                    45 23 * * * <?= htmlspecialchars($phpBin) ?> <?= htmlspecialchars($disPath . $disCliArg) ?>
                </div>
                <p class="text-muted small mt-2 mb-1">If server timezone is UTC (15:30 UTC = 23:30 PHT):</p>
                <div class="bg-dark text-success p-3 rounded font-monospace small">
                    30 15 * * * <?= htmlspecialchars($phpBin) ?> <?= htmlspecialchars($remPath) ?><br>
                    45 15 * * * <?= htmlspecialchars($phpBin) ?> <?= htmlspecialchars($disPath . $disCliArg) ?>
                </div>
            </div>

            <div class="mb-4">
                <h6 class="fw-semibold small">HTTP commands (token-secured — use with external cron services)</h6>
                <p class="text-muted small mb-1">Reminders:</p>
                <div class="bg-dark text-success p-3 rounded font-monospace small text-break mb-3">
                    <?= htmlspecialchars($remCurl) ?>
                </div>
                <p class="text-muted small mb-1">Force-Disconnect:</p>
                <div class="bg-dark text-success p-3 rounded font-monospace small text-break">
                    <?= htmlspecialchars($disCurl) ?>
                </div>
                <p class="text-muted small mt-2 mb-0">
                    Set <code>REMINDER_CRON_TOKEN</code> and <code>FORCE_DISCONNECT_TOKEN</code> in <code>.env</code>,
                    or use one shared <code>CRON_TOKEN</code>.
                </p>
            </div>

            <?= inlineToasts([['message' => 'Ensure SMTP is configured in .env (MAIL_HOST, MAIL_USERNAME, MAIL_PASSWORD) for reminder emails to be delivered.', 'type' => 'info']]) ?>
        </div>
    </div>
</div>

<!-- Toast: outside scheduled window -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:1100;">
    <div id="cronTimeToast" class="toast align-items-center text-bg-warning border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body">
                <i class="bi bi-clock-history me-2"></i>
                <strong>Outside scheduled window.</strong>
                Cron jobs auto-run at <strong>11:30 PM PHT</strong>.
                Running now is fine — results may differ from the nightly run.
            </div>
            <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script>
const cronJobs = [
    {
        id:    'runRemindersBtn',
        label: '<i class="bi bi-envelope-fill me-1"></i>Run Reminders Now',
        title: 'Reminders Complete',
        result: d => `<strong>${d.sent ?? 0}</strong> reminder(s) sent.`,
    },
    {
        id:    'runForceDisBtn',
        label: '<i class="bi bi-plug-fill me-1"></i>Run Force-Disconnect Now',
        title: 'Force-Disconnect Complete',
        result: d => `<strong>${d.processed ?? 0}</strong> expired subscriber(s) enforced on router.`,
    },
];

function cronEscapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    }[ch]));
}

async function cronFetchJson(url, token) {
    const response = await fetch(url, {
        headers: {
            'X-Cron-Token': token || '',
        },
    });
    const text = await response.text();
    let data = {};
    try {
        data = text ? JSON.parse(text) : {};
    } catch (e) {
        throw new Error(text ? text.slice(0, 500) : `HTTP ${response.status}`);
    }
    if (!response.ok || data.success === false) {
        throw new Error(data.message || `HTTP ${response.status}`);
    }
    return data;
}

// ── Reminders progress modal ─────────────────────────────────
function remindersProgressHtml(total) {
    return `
        <div class="text-start">
            <div class="d-flex justify-content-between small mb-2">
                <span>Sending reminder emails…</span>
                <strong>${total.toLocaleString()} subscriber(s)</strong>
            </div>
            <div class="progress mb-3" style="height:18px;">
                <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary"
                     role="progressbar" style="width:100%"
                     aria-valuenow="100" aria-valuemin="0" aria-valuemax="100"></div>
            </div>
            <div class="small text-muted">Please wait — sending emails, this may take a moment.</div>
        </div>
    `;
}

async function runReminders(btn, label, title, result) {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Checking…';

    try {
        const token    = btn.dataset.token || '';
        const countUrl = new URL(btn.dataset.url, window.location.href);
        countUrl.searchParams.set('mode', 'count');
        const summary = await cronFetchJson(countUrl, token);
        const total   = Number(summary.eligible_count || 0);

        if (total <= 0) {
            await Swal.fire('Nothing to send',
                'No eligible subscribers found — no one has an email address and an active subscription expiring within 7 days.',
                'info');
            return;
        }

        const confirmed = await Swal.fire({
            icon: 'question',
            title: 'Run Reminders Now?',
            html: `<div class="text-start">
                <p>This will send expiry reminder emails to
                   <strong>${total.toLocaleString()}</strong> eligible subscriber(s)
                   whose subscription expires within the next 7 days.</p>
                <p class="text-muted small mb-0">Only subscribers with a valid email address and active status are included.</p>
            </div>`,
            showCancelButton: true,
            confirmButtonText: '<i class="bi bi-envelope-fill me-1"></i>Send Reminders',
            confirmButtonColor: '#0d6efd',
        });
        if (!confirmed.isConfirmed) return;

        Swal.fire({
            title: 'Sending Reminders…',
            html: remindersProgressHtml(total),
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
        });

        const data   = await cronFetchJson(btn.dataset.url, token);
        const sent   = Number(data.sent || 0);
        const errors = data.errors || [];
        const errHtml = errors.length
            ? `<br><small class="text-danger">${errors.length} error(s):<br>${errors.map(cronEscapeHtml).join('<br>')}</small>`
            : '';

        await Swal.fire({
            icon: errors.length > 0 && sent === 0 ? 'error' : errors.length > 0 ? 'warning' : 'success',
            title,
            html: result(data) + errHtml,
            confirmButtonText: 'OK',
        });
        location.reload();
    } catch (err) {
        await Swal.fire('Error', err.message || 'Could not reach the server.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = label;
    }
}

function forceDisRouterPolicyHtml(policies) {
    if (!policies || !policies.length) {
        return '<div class="text-muted small border rounded p-2 bg-light">Router policy: Disable account</div>';
    }

    return `
        <div class="border rounded bg-light overflow-auto mt-2" style="max-height: 190px;">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Router</th>
                        <th>Policy</th>
                        <th class="text-end">Affected</th>
                    </tr>
                </thead>
                <tbody>
                    ${policies.map(policy => {
                        const isRadius = policy.auth_type === 'radius';
                        const isChange = ['change_profile', 'change_group'].includes(policy.policy_action);
                        const action = isChange
                            ? (isRadius ? 'Change RADIUS group' : 'Change profile')
                            : 'Disable account';
                        const ppp = policy.expire_ppp_profile
                            ? `<div class="text-muted small">PPP ${isRadius ? 'group' : 'profile'}: ${cronEscapeHtml(policy.expire_ppp_profile)}</div>`
                            : '';
                        const hs = policy.expire_hs_profile
                            ? `<div class="text-muted small">Hotspot ${isRadius ? 'group' : 'profile'}: ${cronEscapeHtml(policy.expire_hs_profile)}</div>`
                            : '';
                        const badge = isChange ? 'bg-warning text-dark' : 'bg-secondary';
                        return `
                            <tr>
                                <td>
                                    <div>${cronEscapeHtml(policy.router_name || 'Router #' + policy.router_id)}</div>
                                    <div class="text-muted small">${isRadius ? 'RADIUS / User Manager' : 'Local PPP / Hotspot'}</div>
                                </td>
                                <td>
                                    <span class="badge ${badge}">${action}</span>
                                    ${isChange ? (ppp || hs ? ppp + hs : `<div class="text-danger small">No expired ${isRadius ? 'group' : 'profile'} configured</div>`) : ''}
                                </td>
                                <td class="text-end">${Number(policy.affected_count || 0).toLocaleString()}</td>
                            </tr>
                        `;
                    }).join('')}
                </tbody>
            </table>
        </div>
    `;
}

function forceDisPolicyLabel(user) {
    const result = user.policy_result || 'disable';
    if (result === 'change_group') return 'Change group';
    if (result === 'change_profile') return 'Change profile';
    return 'Disabled';
}

function forceDisPolicyBadge(user) {
    if (!user.policy_applied) return 'bg-danger';
    if (user.policy_result === 'change_group') return 'bg-info text-dark';
    if (user.policy_result === 'change_profile') return 'bg-primary';
    return 'bg-secondary';
}

function forceDisAffectedUsersHtml(users) {
    if (!users.length) {
        return '<div class="text-muted small border rounded p-3 bg-light">Waiting for the first enforced users...</div>';
    }

    return `
        <div class="border rounded bg-light overflow-auto" style="max-height: 260px;">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light sticky-top">
                    <tr>
                        <th>Account</th>
                        <th>Username</th>
                        <th>Action</th>
                        <th class="text-end">Sessions</th>
                    </tr>
                </thead>
                <tbody>
                    ${users.slice(-80).reverse().map(user => {
                        const target = user.policy_target
                            ? `<div class="text-muted small">${cronEscapeHtml(user.policy_target)}</div>`
                            : '';
                        const failed = user.policy_applied ? '' : '<div class="text-danger small">Policy failed</div>';
                        return `
                            <tr>
                                <td>
                                    <div class="font-monospace small">${cronEscapeHtml(user.account_number || '-')}</div>
                                    <div class="text-muted small text-truncate" style="max-width: 150px;">${cronEscapeHtml(user.subscriber || user.router || '')}</div>
                                </td>
                                <td class="font-monospace small">${cronEscapeHtml(user.username || '-')}</td>
                                <td>
                                    <span class="badge ${forceDisPolicyBadge(user)}">${forceDisPolicyLabel(user)}</span>
                                    ${target}
                                    ${failed}
                                </td>
                                <td class="text-end">${Number(user.sessions_disconnected || 0).toLocaleString()}</td>
                            </tr>
                        `;
                    }).join('')}
                </tbody>
            </table>
        </div>
        ${users.length > 80 ? `<div class="text-muted small mt-1">Showing latest 80 of ${users.length.toLocaleString()} enforced users.</div>` : ''}
    `;
}

function forceDisProgressHtml(checked, total, processed, sessions, errors, affectedUsers) {
    const pct = total > 0 ? Math.min(100, Math.round((checked / total) * 100)) : 100;
    return `
        <div class="text-start">
            <div class="d-flex justify-content-between small mb-2">
                <span>${checked.toLocaleString()} of ${total.toLocaleString()} subscribers checked</span>
                <strong>${pct}%</strong>
            </div>
            <div class="progress mb-3" style="height: 18px;">
                <div class="progress-bar progress-bar-striped progress-bar-animated bg-danger"
                     role="progressbar" style="width:${pct}%"
                     aria-valuenow="${pct}" aria-valuemin="0" aria-valuemax="100"></div>
            </div>
            <div class="small text-muted">
                ${processed.toLocaleString()} enforced on router ·
                ${sessions.toLocaleString()} active session(s) disconnected ·
                ${errors.length.toLocaleString()} error(s)
            </div>
            <div class="mt-3">
                <div class="fw-semibold small mb-2">Disabled / Change Group Users</div>
                ${forceDisAffectedUsersHtml(affectedUsers || [])}
            </div>
        </div>
    `;
}

async function runForceDisconnect(btn, label, title, result) {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Checking…';

    try {
        const token = btn.dataset.token || '';
        const countUrl = new URL(btn.dataset.url, window.location.href);
        countUrl.searchParams.set('mode', 'count');
        const summary = await cronFetchJson(countUrl, token);
        const total = Number(summary.affected_count || 0);
        const routerName = btn.dataset.routerName || 'current router';
        const dateRange = summary.oldest_expiry && summary.newest_expiry
            ? `${cronEscapeHtml(summary.newest_expiry)} back to ${cronEscapeHtml(summary.oldest_expiry)}`
            : `${cronEscapeHtml(summary.newest_expired_date || 'yesterday')} and older`;

        if (total <= 0) {
            await Swal.fire('Nothing to disconnect', `No past-due subscribers were found for ${cronEscapeHtml(routerName)}.`, 'info');
            return;
        }

        const confirm = await Swal.fire({
            icon: 'warning',
            title: 'Confirm Force-Disconnect',
            html: `
                <div class="text-start">
                    <p class="mb-2">This will enforce the expiry policy for <strong>${total.toLocaleString()}</strong> expired subscriber(s).</p>
                    <div class="small text-muted">Scope: ${cronEscapeHtml(routerName)}</div>
                    <div class="small text-muted">Expiry order: ${dateRange}</div>
                    <div class="fw-semibold small mt-3">Router Policy</div>
                    ${forceDisRouterPolicyHtml(summary.policy_summary || [])}
                    <hr>
                    <div class="small">Active PPP, Hotspot, and RADIUS sessions for these users will be terminated.</div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Start Force-Disconnect',
            confirmButtonColor: '#dc3545',
        });
        if (!confirm.isConfirmed) return;

        let afterId = 0;
        let afterDate = '';
        let checked = 0;
        let processed = 0;
        let sessions = 0;
        let errors = [];
        let affectedUsers = [];
        let hasMore = true;

        Swal.fire({
            title: 'Force-Disconnect Running',
            html: forceDisProgressHtml(0, total, 0, 0, [], []),
            width: '900px',
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: () => Swal.showLoading(),
        });

        while (hasMore && checked < total) {
            const runUrl = new URL(btn.dataset.url, window.location.href);
            runUrl.searchParams.set('limit', '25');
            runUrl.searchParams.set('max_seconds', '20');
            runUrl.searchParams.set('after_id', String(afterId));
            if (afterDate) runUrl.searchParams.set('after_date', afterDate);

            const data = await cronFetchJson(runUrl, token);
            const batchChecked = Number(data.checked || 0);
            if (Boolean(data.has_more) && batchChecked <= 0) {
                throw new Error('The batch stopped before any subscriber was checked. Please try again with a smaller batch or check router connectivity.');
            }
            checked = Math.min(total, checked + batchChecked);
            processed += Number(data.processed || 0);
            sessions += Number(data.sessions_disconnected || 0);
            errors = errors.concat(data.errors || []);
            affectedUsers = affectedUsers.concat(data.affected_users || []);
            afterId = Number(data.after_id || afterId);
            afterDate = data.after_date || afterDate;
            hasMore = Boolean(data.has_more) && batchChecked > 0;

            Swal.update({
                html: forceDisProgressHtml(checked, total, processed, sessions, errors, affectedUsers),
            });
        }

        const errHtml = errors.length
            ? `<br><small class="text-danger">${errors.length} error(s):<br>${errors.map(cronEscapeHtml).join('<br>')}</small>`
            : '';

        await Swal.fire({
            icon: errors.length ? 'warning' : 'success',
            title,
            width: '900px',
            html: result({
                processed,
                sessions_disconnected: sessions,
            }) + `<br><strong>${sessions}</strong> active session(s) disconnected.`
              + `<div class="text-start mt-3"><div class="fw-semibold small mb-2">Disabled / Change Group Users</div>${forceDisAffectedUsersHtml(affectedUsers)}</div>`
              + errHtml,
            confirmButtonText: 'OK',
        });
        location.reload();
    } catch (err) {
        await Swal.fire('Error', err.message || 'Could not reach the server.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = label;
    }
}

cronJobs.forEach(({ id, label, title, result }) => {
    const btn = document.getElementById(id);
    if (!btn) return;
    btn.addEventListener('click', function () {
        if (id === 'runRemindersBtn') {
            runReminders(btn, label, title, result);
        } else if (id === 'runForceDisBtn') {
            runForceDisconnect(btn, label, title, result);
        }
    });
});
</script>

<?php include BASE_PATH . '/includes/footer.php'; ?>
