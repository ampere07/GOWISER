<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireLogin();
requireRole(ROLE_LINEMAN, ROLE_CASHIER, ROLE_USER, ROLE_ADMIN, ROLE_SUPERADMIN);

$pageTitle   = 'Subscribers';
$breadcrumbs = [['label' => 'Subscribers']];

// ── Session-persisted filters ─────────────────────────────────
const SESS_SUBS = 'filter_subscribers';

if (isset($_GET['_clear'])) {
    unset($_SESSION[SESS_SUBS]);
    redirect(BASE_URL . '/modules/subscribers/');
}
if (isset($_GET['_filter'])) {
    $_SESSION[SESS_SUBS] = [
        'q'         => trim($_GET['q']   ?? ''),
        'status'    => $_GET['status']   ?? '',
        'conn_type' => $_GET['conn_type'] ?? '',
        'exp_date'  => $_GET['exp_date'] ?? '',
        'plan_id'   => (int)($_GET['plan_id'] ?? 0),
    ];
    redirect(BASE_URL . '/modules/subscribers/');
}

$f        = $_SESSION[SESS_SUBS] ?? [];
$search   = $f['q']         ?? '';
$connType = $f['conn_type'] ?? '';
$expDate  = $f['exp_date']  ?? '';
$planId   = (int)($f['plan_id'] ?? 0);

// Only allow active/suspended/expired in the main list
$allowedStatuses = [SUB_STATUS_ACTIVE, SUB_STATUS_SUSPENDED, SUB_STATUS_EXPIRED];
$status = in_array($f['status'] ?? '', $allowedStatuses, true) ? ($f['status'] ?? '') : '';
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = DEFAULT_PER_PAGE;
$canDisconnectSubscriberSession = hasRole(ROLE_CASHIER, ROLE_ADMIN, ROLE_SUPERADMIN);
$isReadOnly = isReadOnlyUser();

// Auto-apply currently selected router
$selRouterId = selectedRouterId();

// Resolve exp_date: keyword or raw YYYY-MM-DD
$expDateResolved = match($expDate) {
    'yesterday' => date('Y-m-d', strtotime('-1 day')),
    'today'     => date('Y-m-d'),
    'tomorrow'  => date('Y-m-d', strtotime('+1 day')),
    default     => preg_match('/^\d{4}-\d{2}-\d{2}$/', $expDate) ? $expDate : '',
};

$expDateLabel = match($expDate) {
    'yesterday' => 'Expired Yesterday',
    'today'     => 'Expiring Today',
    'tomorrow'  => 'Expiring Tomorrow',
    default     => $expDateResolved ? 'Expiry: ' . date('M d, Y', strtotime($expDateResolved)) : '',
};

// Pre-populate date input (convert keywords to actual date)
$expDateForInput = $expDateResolved;

$routerPolicySelect = '';
$routerPolicyJoin = '';
if (tableExists('router_policies')) {
    $routerPolicySelect = ",
           rp.on_expire AS policy_on_expire,
           rp.expire_ppp_profile AS policy_expire_ppp_profile,
           rp.expire_hs_profile AS policy_expire_hs_profile";
    $routerPolicyJoin = "LEFT JOIN router_policies rp ON rp.router_id = s.router_id";
}

$buildDisconnectPolicy = static function (array $sub): array {
    $policyAction = strtolower((string)($sub['policy_on_expire'] ?? 'disable'));
    $policyIsRadius = (($sub['auth_type'] ?? 'local') === 'radius');
    $policyIsPpp = (($sub['connection_type'] ?? 'ppp') === 'ppp');
    $policyPppTarget = trim((string)($sub['policy_expire_ppp_profile'] ?? ''));
    $policyHsTarget = trim((string)($sub['policy_expire_hs_profile'] ?? ''));
    $policyTarget = $policyIsRadius
        ? ($policyPppTarget !== '' ? $policyPppTarget : $policyHsTarget)
        : ($policyIsPpp ? $policyPppTarget : $policyHsTarget);
    $policyTargetKind = $policyIsRadius ? 'RADIUS group' : 'profile';
    $policyTitle = 'Disable account';
    $policyBadge = 'danger';
    $policyDetail = $policyIsRadius
        ? 'The User Manager account will be disabled after the active session is disconnected.'
        : 'The router account will be disabled after the active session is disconnected.';

    if (in_array($policyAction, ['change_profile', 'change_group'], true)) {
        if ($policyTarget !== '') {
            $policyTitle = $policyIsRadius ? 'Change RADIUS group' : 'Change profile';
            $policyBadge = 'warning';
            $policyDetail = 'After disconnecting active sessions, the account will be moved to the expired ' . $policyTargetKind . ' "' . $policyTarget . '".';
        } else {
            $policyTitle = 'Disable account fallback';
            $policyBadge = 'danger';
            $policyDetail = 'The router policy is set to change ' . $policyTargetKind . ', but no expired target is configured for this connection type. The account will be disabled instead.';
        }
    }

    return [
        'router' => $sub['router_name'] ?? '',
        'auth' => $policyIsRadius ? 'RADIUS / User Manager' : 'Local PPP / Hotspot',
        'connection' => strtoupper((string)($sub['connection_type'] ?? '')),
        'action' => $policyAction,
        'title' => $policyTitle,
        'badge' => $policyBadge,
        'target_kind' => $policyTargetKind,
        'target' => $policyTarget,
        'detail' => $policyDetail,
    ];
};

$where  = ['s.status NOT IN (?, ?)'];
$params = [SUB_STATUS_PENDING, SUB_STATUS_ARCHIVED];

if ($search !== '') {
    $where[]  = '(s.firstname LIKE ? OR s.lastname LIKE ? OR CONCAT(s.firstname, \' \', s.lastname) LIKE ? OR s.account_number LIKE ? OR s.ppp_username LIKE ?)';
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like, $like, $like, $like]);
}
if ($status) {
    $where[]  = 's.status = ?';
    $params[] = $status;
}
if ($connType) {
    $where[]  = 's.connection_type = ?';
    $params[] = $connType;
}
if ($selRouterId) {
    $where[]  = 's.router_id = ?';
    $params[] = $selRouterId;
}
if ($expDateResolved) {
    $where[]  = 'DATE(s.subscription_end) = ?';
    $params[] = $expDateResolved;
}
if ($planId > 0) {
    $where[]  = 's.plan_id = ?';
    $params[] = $planId;
}

$whereStr = implode(' AND ', $where);

$plansStmt = $selRouterId
    ? db()->prepare("SELECT plan_id, title FROM plans WHERE is_active = 1 AND (router_id = ? OR router_id IS NULL) ORDER BY title")
    : db()->prepare("SELECT plan_id, title FROM plans WHERE is_active = 1 ORDER BY title");
if ($selRouterId) $plansStmt->execute([$selRouterId]);
else $plansStmt->execute();
$plans = $plansStmt->fetchAll();

$total = db()->prepare("SELECT COUNT(*) FROM subscribers s WHERE {$whereStr}");
$total->execute($params);
$totalCount = (int)$total->fetchColumn();

$pag  = paginate($totalCount, $page, $perPage);

$stmt = db()->prepare("
    SELECT s.*,
           p.title AS plan_title, p.amount AS plan_amount, p.speed_mbps, p.billing_cycle,
           r.name  AS router_name, r.status AS router_status,
           COALESCE(r.auth_type, 'local') AS auth_type,
           (SELECT COALESCE(SUM(py.amount), 0)
            FROM payments py
            INNER JOIN payment_types pt ON pt.type_id = py.payment_type_id
            WHERE py.subscriber_id = s.subscriber_id AND py.status = 'paid' AND pt.is_default = 1
              AND (s.subscription_start IS NULL OR COALESCE(DATE(py.period_start), DATE(py.payment_date)) >= s.subscription_start)
           ) AS total_paid
           {$routerPolicySelect}
    FROM subscribers s
    LEFT JOIN plans   p ON p.plan_id   = s.plan_id
    LEFT JOIN routers r ON r.router_id = s.router_id
    {$routerPolicyJoin}
    WHERE {$whereStr}
    ORDER BY s.created_at DESC
    LIMIT {$pag['per_page']} OFFSET {$pag['offset']}
");
$stmt->execute($params);
$subscribers = $stmt->fetchAll();

$batchDisconnectSubscribers = [];
if ($canDisconnectSubscriberSession && $status === 'expired' && $expDateResolved !== '' && $expDateResolved < date('Y-m-d')) {
    $batchStmt = db()->prepare("
        SELECT s.subscriber_id, s.account_number, s.firstname, s.lastname,
               s.connection_type, s.router_id,
               r.name AS router_name, r.status AS router_status,
               COALESCE(r.auth_type, 'local') AS auth_type
               {$routerPolicySelect}
        FROM subscribers s
        LEFT JOIN routers r ON r.router_id = s.router_id
        {$routerPolicyJoin}
        WHERE {$whereStr}
          AND s.router_id IS NOT NULL
          AND r.status = ?
        ORDER BY s.created_at DESC
    ");
    $batchStmt->execute(array_merge($params, [ROUTER_ONLINE]));
    foreach ($batchStmt->fetchAll() as $batchSub) {
        $batchDisconnectSubscribers[] = [
            'id' => (int)$batchSub['subscriber_id'],
            'router_id' => (int)$batchSub['router_id'],
            'name' => trim(($batchSub['firstname'] ?? '') . ' ' . ($batchSub['lastname'] ?? '')),
            'account' => (string)($batchSub['account_number'] ?? ''),
            'policy' => $buildDisconnectPolicy($batchSub),
        ];
    }
}
$batchDisconnectCount = count($batchDisconnectSubscribers);

include BASE_PATH . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">Subscribers</h4>
        <p class="text-muted small mb-0"><?= number_format($totalCount) ?> subscriber<?= $totalCount !== 1 ? 's' : '' ?></p>
    </div>
    <div class="d-flex gap-2 flex-wrap justify-content-end">
        <?php if (canPrintSensitiveRecords()): ?>
        <a href="<?= BASE_URL ?>/api/export_subscribers.php"
           class="btn btn-sm btn-outline-success" title="Export current filter to CSV">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export CSV
        </a>
        <?php endif; ?>
        <?php if (canModifyRecords()): ?>
        <a href="<?= BASE_URL ?>/modules/subscribers/add" class="btn btn-primary">
            <i class="bi bi-person-plus me-1"></i>Add Subscriber
        </a>
        <?php endif; ?>
    </div>
</div>


<?php if ($status === 'expired' && $expDate === 'yesterday'): ?>
<?= inlineToasts([['message' => 'Subscribers expired yesterday.', 'type' => 'warning']]) ?>
<?php if ($batchDisconnectCount > 0): ?>
<div class="d-flex justify-content-end mb-3">
    <button type="button" class="btn btn-sm btn-warning fw-semibold" id="batchDisconnectSessions">
        <i class="bi bi-x-circle me-1"></i>Disconnect Sessions (<?= number_format($batchDisconnectCount) ?>)
    </button>
</div>
<?php endif; ?>
<?php elseif ($status === 'expired' && $expDateLabel !== ''): ?>
<?= inlineToasts([['message' => e($expDateLabel) . ' subscribers.', 'type' => 'warning']]) ?>
<?php if ($batchDisconnectCount > 0): ?>
<div class="d-flex justify-content-end mb-3">
    <button type="button" class="btn btn-sm btn-warning fw-semibold" id="batchDisconnectSessions">
        <i class="bi bi-x-circle me-1"></i>Disconnect Sessions (<?= number_format($batchDisconnectCount) ?>)
    </button>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- Filters -->
<div class="filter-bar mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="_filter" value="1">
        <div class="col-sm-6 col-lg-3">
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-search text-muted"></i></span>
                <input type="text" name="q" class="form-control form-control-sm"
                       placeholder="Name, account, PPP user…" value="<?= e($search) ?>">
            </div>
        </div>
        <div class="col-sm-6 col-lg-2">
            <select name="status" id="statusFilter" class="form-select form-select-sm">
                <option value="">All Status</option>
                <?php foreach ([SUB_STATUS_ACTIVE, SUB_STATUS_SUSPENDED, SUB_STATUS_EXPIRED] as $s): ?>
                <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-6 col-lg-2">
            <select name="conn_type" class="form-select form-select-sm">
                <option value="">All Types</option>
                <option value="ppp"     <?= $connType === 'ppp'     ? 'selected' : '' ?>>PPP</option>
                <option value="hotspot" <?= $connType === 'hotspot' ? 'selected' : '' ?>>Hotspot</option>
            </select>
        </div>
        <div class="col-sm-6 col-lg-2">
            <select name="plan_id" class="form-select form-select-sm">
                <option value="0">All Plans</option>
                <?php foreach ($plans as $pl): ?>
                <option value="<?= $pl['plan_id'] ?>" <?= $planId === (int)$pl['plan_id'] ? 'selected' : '' ?>><?= e($pl['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-6 col-lg-2" id="expDateWrap" <?= $status !== 'expired' ? 'style="display:none;"' : '' ?>>
            <input type="date" name="exp_date" id="expDateInput"
                   class="form-control form-control-sm"
                   title="Filter by expiry date"
                   value="<?= e($status === 'expired' ? $expDateForInput : '') ?>">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
            <a href="?_clear=1" class="btn btn-sm btn-outline-secondary" title="Clear"><i class="bi bi-x-lg"></i></a>
        </div>
    </form>
</div>

<!-- Table -->
<div class="card" id="list">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Account #</th>
                        <th>Subscriber</th>
                        <th>Type</th>
                        <th class="d-none">Router</th>
                        <th>Expiry</th>
                        <th>Status</th>
                        <?php if (!$isReadOnly): ?>
                        <th class="text-end">Balance / MRC</th>
                        <th class="text-end pe-3">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($subscribers)): ?>
                    <tr>
                        <td colspan="<?= $isReadOnly ? 6 : 8 ?>" class="text-center py-5 text-muted">
                            <i class="bi bi-person-x d-block fs-2 mb-2"></i>
                            No subscribers found.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($subscribers as $sub): ?>
                    <?php
                        $daysLeft  = subscriptionDaysLeft($sub['subscription_end']);
                        $expiring  = isExpiringSoon($sub['subscription_end'], 3);
                        $expired   = isExpired($sub['subscription_end']) && $sub['status'] !== 'expired';
                        $rowClass  = $expired ? 'table-danger' : ($expiring ? 'table-warning' : '');

                        // Balance — scoped to current subscription period (same logic as view page).
                        $subMrc       = (float)($sub['plan_amount'] ?? 0);
                        $subCycleDays = match($sub['billing_cycle'] ?? 'monthly') {
                            'quarterly' => 90, 'annual' => 365, default => 30,
                        };
                        $subTotalPaid = (float)($sub['total_paid'] ?? 0);
                        $subStartTs   = $sub['subscription_start'] ? strtotime($sub['subscription_start']) : 0;
                        $subSameDay   = ($subStartTs > 0 && date('Y-m-d', $subStartTs) === date('Y-m-d'));
                        $subEndTs     = $sub['subscription_end'] ? strtotime($sub['subscription_end']) : 0;
                        $isSubExpired = $subEndTs > 0 && $subEndTs < time();
                        $effectiveNow = ($isSubExpired && $subEndTs > $subStartTs) ? $subEndTs : time();
                        $subElapsed   = $subStartTs > 0 ? max(0, ($effectiveNow - $subStartTs) / 86400) : 0;
                        $subCycles    = ($subStartTs > 0 && !$subSameDay && $subCycleDays > 0)
                                        ? ($isSubExpired ? (int)floor($subElapsed / $subCycleDays)
                                                         : (int)ceil($subElapsed  / $subCycleDays))
                                        : 0;
                        $subExpected  = round($subCycles * $subMrc, 2);
                        $subBalance   = round($subTotalPaid - $subExpected, 2);
                    ?>
                    <tr class="<?= $rowClass ?>">
                        <td class="ps-3">
                            <?php if (!$isReadOnly): ?>
                            <a href="view/<?= $sub['subscriber_id'] ?>" class="fw-medium text-decoration-none">
                                <?= e($sub['account_number']) ?>
                            </a>
                            <?php else: ?>
                            <span class="fw-medium"><?= e($sub['account_number']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="fw-medium"><?= e($sub['firstname'] . ' ' . $sub['lastname']) ?></div>
                            <?php if (!empty($sub['address'])): ?>
                            <div class="text-muted small text-truncate" style="max-width:200px;"><?= e($sub['address']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= $sub['connection_type'] === 'ppp' ? 'bg-primary' : 'bg-info text-dark' ?>">
                                <?= strtoupper(e($sub['connection_type'])) ?>
                            </span>
                        </td>
                        <td class="small d-none"><?= e($sub['router_name'] ?? '—') ?></td>
                        <td>
                            <?php if ($sub['subscription_end']): ?>
                            <div class="small"><?= formatDate($sub['subscription_end']) ?></div>
                            <div class="<?= $daysLeft <= 3 ? 'text-danger' : ($daysLeft <= 7 ? 'text-warning' : 'text-muted') ?> text-xs">
                                <?= $expired ? 'Expired' : ($daysLeft . 'd left') ?>
                            </div>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><?= subStatusBadge($sub['status']) ?></td>
                        <?php if (!$isReadOnly): ?>
                        <td class="text-end">
                            <?php if ($subMrc > 0): ?>
                            <div class="fw-semibold <?= $subBalance < 0 ? 'text-danger' : 'text-success' ?>" style="font-size:.82rem;">
                                <?= ($subBalance < 0 ? '−' : '+') . formatMoney(abs($subBalance)) ?>
                            </div>
                            <div class="text-muted text-xs"><?= formatMoney($subMrc) ?></div>
                            <?php else: ?>
                            <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-3">
                            <div class="d-flex gap-1 justify-content-end">
                                <a href="view/<?= $sub['subscriber_id'] ?>" class="btn btn-sm btn-outline-secondary" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?php if (canModifyRecords()): ?>
                                <a href="<?= BASE_URL ?>/modules/subscribers/edit/<?= $sub['subscriber_id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($pag['total_pages'] > 1): ?>
    <div class="card-footer bg-transparent border-top d-flex align-items-center justify-content-between">
        <small class="text-muted">
            Showing <?= number_format($pag['offset'] + 1) ?>–<?= number_format(min($pag['offset'] + $pag['per_page'], $totalCount)) ?>
            of <?= number_format($totalCount) ?> results
        </small>
        <?= renderPagination($pag, '', 'list') ?>
    </div>
    <?php endif; ?>
</div>

<?php
$batchDisconnectSubscribersJson = json_encode(
    $batchDisconnectSubscribers,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);

$extraScripts = '<script>const batchDisconnectSubscribers = ' . ($batchDisconnectSubscribersJson ?: '[]') . ';</script>' . <<<'JS'
<script>
if (typeof window.escHtml !== 'function') {
    window.escHtml = function (str) {
        return String(str ?? '').replace(/[&<>"']/g, ch => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;',
        }[ch]));
    };
}

// Show/hide expiry date column when status = "expired"
(function () {
    const statusSel = document.getElementById('statusFilter');
    const dateWrap  = document.getElementById('expDateWrap');  // the col div
    const dateInput = document.getElementById('expDateInput'); // the input inside it

    function toggleDateFilter() {
        const show = statusSel?.value === 'expired';
        if (dateWrap)  dateWrap.style.display  = show ? '' : 'none';
        if (!show && dateInput) dateInput.value = '';
    }

    statusSel?.addEventListener('change', toggleDateFilter);
})();

function batchDisconnectProgressHtml(done, total, currentName, errors) {
    const pct = total > 0 ? Math.round((done / total) * 100) : 0;
    const errorHtml = errors.length
        ? '<div class="text-danger small mt-2">' + errors.length.toLocaleString() + ' error(s) so far.</div>'
        : '';
    return '<div class="text-start">'
        + '<div class="d-flex justify-content-between small mb-1">'
        + '<span>' + done.toLocaleString() + ' / ' + total.toLocaleString() + ' processed</span>'
        + '<span>' + pct + '%</span>'
        + '</div>'
        + '<div class="progress mb-2" style="height:8px;">'
        + '<div class="progress-bar" style="width:' + pct + '%"></div>'
        + '</div>'
        + '<div class="small text-muted">Current: ' + escHtml(currentName || 'Preparing...') + '</div>'
        + errorHtml
        + '</div>';
}

async function runDisconnectAction(item) {
    const fd = new FormData();
    fd.append('action', 'disconnect');
    fd.append('subscriber_id', item.id);
    fd.append('router_id', item.router_id || '');
    fd.append(CSRF_TOKEN_NAME || '_csrf_token', CSRF_TOKEN || '');

    const response = await fetch(BASE_URL + '/api/mikrotik_action.php', { method: 'POST', body: fd });
    const text = await response.text();
    let data = {};
    try {
        data = text ? JSON.parse(text) : {};
    } catch (err) {
        throw new Error(text ? text.slice(0, 500) : 'Invalid server response.');
    }
    if (!response.ok || data.success === false) {
        const detail = data.message || data.error || data.detail || data.reason
            || (text ? text.slice(0, 500) : '');
        throw new Error(detail || 'Router action failed. No details were returned by the server.');
    }
    return data;
}

document.getElementById('batchDisconnectSessions')?.addEventListener('click', function() {
    const total = batchDisconnectSubscribers.length;
    if (!total) {
        Swal.fire('No Subscribers', 'No online-router subscribers are available for this filtered date.', 'info');
        return;
    }

    const preview = batchDisconnectSubscribers.slice(0, 8).map(item => {
        const label = (item.name || 'Subscriber') + (item.account ? ' (' + item.account + ')' : '');
        return '<li>' + escHtml(label) + '</li>';
    }).join('');
    const more = total > 8
        ? '<div class="small text-muted mt-1">+' + (total - 8).toLocaleString() + ' more subscriber(s)</div>'
        : '';

    Swal.fire({
        title: 'Disconnect filtered sessions?',
        icon: 'question',
        html: '<div class="text-start">'
            + '<p class="mb-2">This will process <strong>' + total.toLocaleString() + '</strong> subscriber(s) from the current expired date filter.</p>'
            + '<p class="small text-muted mb-2">Each account will use the same Disconnect Session flow from the subscriber view page: apply expiry policy for expired accounts, then remove active PPP, Hotspot, or RADIUS/User Manager sessions.</p>'
            + '<ul class="small mb-0">' + preview + '</ul>'
            + more
            + '</div>',
        showCancelButton: true,
        confirmButtonText: 'Yes, disconnect batch',
    }).then(async result => {
        if (!result.isConfirmed) return;

        let processed = 0;
        let success = 0;
        const errors = [];

        Swal.fire({
            title: 'Processing batch...',
            html: batchDisconnectProgressHtml(0, total, '', errors),
            allowOutsideClick: false,
            allowEscapeKey: false,
            didOpen: () => Swal.showLoading(),
        });

        for (const item of batchDisconnectSubscribers) {
            const currentName = item.name || item.account || ('Subscriber #' + item.id);
            Swal.update({
                html: batchDisconnectProgressHtml(processed, total, currentName, errors),
            });

            try {
                await runDisconnectAction(item);
                success++;
            } catch (err) {
                errors.push((item.account || item.id) + ': ' + (err.message || 'Failed'));
            }
            processed++;

            Swal.update({
                html: batchDisconnectProgressHtml(processed, total, currentName, errors),
            });
        }

        const errorHtml = errors.length
            ? '<div class="text-start mt-3"><div class="fw-semibold text-danger mb-1">Errors</div><ul class="small mb-0">' + errors.slice(0, 10).map(e => '<li>' + escHtml(e) + '</li>').join('') + '</ul>' + (errors.length > 10 ? '<div class="small text-muted mt-1">+' + (errors.length - 10).toLocaleString() + ' more error(s)</div>' : '') + '</div>'
            : '';

        Swal.fire({
            title: errors.length ? 'Batch completed with errors' : 'Batch completed',
            icon: errors.length ? 'warning' : 'success',
            html: '<div><strong>' + success.toLocaleString() + '</strong> successful, <strong>' + errors.length.toLocaleString() + '</strong> error(s).</div>' + errorHtml,
            confirmButtonText: 'Reload',
        }).then(() => location.reload());
    });
});
</script>
JS;

include BASE_PATH . '/includes/footer.php';
