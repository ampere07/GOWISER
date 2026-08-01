<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireLogin();

$canViewFinance = hasRole(ROLE_CASHIER, ROLE_ADMIN, ROLE_SUPERADMIN);
$isCashierOnly  = currentRole() === ROLE_CASHIER;

$pageTitle   = 'Dashboard 1';
$breadcrumbs = [['label' => 'Dashboard 1']];

$selRouterId      = selectedRouterId();
$isSuperadmin     = hasRole(ROLE_SUPERADMIN);
$dashboardRouters = [];
if ($isSuperadmin) {
    $dashboardRouters = db()->query("SELECT router_id, name, host FROM routers ORDER BY name")->fetchAll();
} elseif ($selRouterId) {
    $stmt = db()->prepare("SELECT router_id, name, host FROM routers WHERE router_id = ? LIMIT 1");
    $stmt->execute([$selRouterId]);
    $dashboardRouters = $stmt->fetchAll();
}
$totalRouters = count($dashboardRouters);

// ── Lineman Report ────────────────────────────────────────────────────────────
$showLinemanReport = hasRole(ROLE_LINEMAN, ROLE_ADMIN, ROLE_SUPERADMIN);
$lnIsLineman = false;
$lnRows = []; $lnTotal = 0; $lnTotalPages = 1; $lnPage = 1; $lnOffset = 0;
$lnPeriod = 'monthly'; $lnUsers = []; $lnFilterId = 0; $lnPerPage = 20;
if ($showLinemanReport) {
    $lnIsLineman = currentRole() === ROLE_LINEMAN;
    $lnMyId      = currentUserId();

    $lnPeriod = $_GET['ln_period'] ?? 'monthly';
    if (!in_array($lnPeriod, ['daily', 'weekly', 'monthly'], true)) $lnPeriod = 'monthly';

    $lnFilterId = $lnIsLineman ? 0 : (int)($_GET['ln_lineman'] ?? 0);

    $lnDateCond = match($lnPeriod) {
        'daily'  => "DATE(i.created_at) = CURDATE()",
        'weekly' => "YEARWEEK(i.created_at, 1) = YEARWEEK(CURDATE(), 1)",
        default  => "YEAR(i.created_at) = YEAR(CURDATE()) AND MONTH(i.created_at) = MONTH(CURDATE())",
    };

    $lnWhere  = [$lnDateCond];
    $lnParams = [];
    if ($lnIsLineman)    { $lnWhere[] = 'i.user_id = ?'; $lnParams[] = $lnMyId; }
    elseif ($lnFilterId) { $lnWhere[] = 'i.user_id = ?'; $lnParams[] = $lnFilterId; }
    if ($selRouterId)    { $lnWhere[] = 's.router_id = ?'; $lnParams[] = $selRouterId; }

    $lnWhereStr = implode(' AND ', $lnWhere);

    $lnTotalStmt = db()->prepare("
        SELECT COUNT(*)
        FROM installations i
        JOIN subscribers s ON s.subscriber_id = i.subscriber_id
        WHERE {$lnWhereStr}
    ");
    $lnTotalStmt->execute($lnParams);
    $lnTotal      = (int)$lnTotalStmt->fetchColumn();
    $lnTotalPages = max(1, (int)ceil($lnTotal / $lnPerPage));
    $lnPage       = max(1, min($lnTotalPages, (int)($_GET['ln_page'] ?? 1)));
    $lnOffset     = ($lnPage - 1) * $lnPerPage;

    $lnStmt = db()->prepare("
        SELECT s.subscriber_id, s.firstname, s.lastname,
               s.address, s.street, s.barangay, s.municipality,
               p.title AS plan_title,
               u.firstname AS lm_first, u.lastname AS lm_last,
               i.created_at AS assigned_at
        FROM installations i
        JOIN subscribers s ON s.subscriber_id = i.subscriber_id
        LEFT JOIN plans p ON p.plan_id = s.plan_id
        LEFT JOIN users u ON u.user_id = i.user_id
        WHERE {$lnWhereStr}
        ORDER BY i.created_at DESC
        LIMIT {$lnPerPage} OFFSET {$lnOffset}
    ");
    $lnStmt->execute($lnParams);
    $lnRows = $lnStmt->fetchAll();

    if (!$lnIsLineman) {
        $lnUsers = db()->query("
            SELECT user_id, firstname, lastname FROM users
            WHERE role = 'lineman' AND is_active = 1
            ORDER BY firstname, lastname
        ")->fetchAll();
    }
}

// ── Settings completeness check (superadmin only, once per session) ──
$showSettingsAlert = false;
$missingSettings   = [];
if (hasRole(ROLE_SUPERADMIN) && empty($_SESSION['settings_alert_dismissed'])) {
    $requiredSettings = [
        'company_name'    => 'Company Name',
        'company_email'   => 'Company Email',
        'company_contact' => 'Company Contact',
        'company_address' => 'Company Address',
        'timezone'        => 'Timezone',
        'currency_symbol' => 'Currency Symbol',
    ];
    $allSettings = getSettings();
    foreach ($requiredSettings as $key => $label) {
        if (empty(trim($allSettings[$key] ?? ''))) {
            $missingSettings[] = $label;
        }
    }
    $showSettingsAlert = !empty($missingSettings);
}

include BASE_PATH . '/includes/header.php';
?>

<?php if ($showSettingsAlert): ?>
<!-- Settings Incomplete Modal -->
<div class="modal fade" id="settingsAlertModal" tabindex="-1" aria-labelledby="settingsAlertModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle d-flex align-items-center justify-content-center bg-warning-subtle" style="width:36px;height:36px;flex-shrink:0;">
                        <i class="bi bi-exclamation-triangle-fill text-warning"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-semibold mb-0" id="settingsAlertModalLabel">Setup Required</h5>
                        <div class="small text-muted">Some required information is missing</div>
                    </div>
                </div>
            </div>
            <div class="modal-body pt-3">
                <p class="text-body mb-2">The following settings are empty and need to be filled out before using the system:</p>
                <ul class="list-group list-group-flush mb-3">
                    <?php foreach ($missingSettings as $label): ?>
                    <li class="list-group-item px-0 py-1 d-flex align-items-center gap-2 border-0">
                        <i class="bi bi-x-circle-fill text-danger small"></i>
                        <span class="small fw-medium"><?= e($label) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <div class="alert alert-info py-2 small mb-0">
                    You will be redirected to the <strong>Settings</strong> page to complete the setup.
                </div>
            </div>
            <div class="modal-footer border-top-0 pt-0 gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="settingsAlertDismiss">
                    <i class="bi bi-clock me-1"></i>Remind Me Later
                </button>
                <a href="<?= BASE_URL ?>/modules/settings/" class="btn btn-warning btn-sm fw-semibold">
                    <i class="bi bi-gear-fill me-1"></i>Go to Settings
                </a>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = new bootstrap.Modal(document.getElementById('settingsAlertModal'));
    modal.show();

    document.getElementById('settingsAlertDismiss').addEventListener('click', function () {
        fetch('<?= BASE_URL ?>/modules/dashboard/dismiss_settings_alert.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        modal.hide();
    });
});
</script>
<?php endif; ?>

<!-- Page Header -->
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">Dashboard 1</h4>
        <p class="text-muted small mb-0">Billing Overview — <?= date('l, F d Y') ?></p>
    </div>
    <div class="d-flex gap-2 flex-wrap justify-content-end">
        <?php if (hasRole(ROLE_ADMIN, ROLE_SUPERADMIN)): ?>
        <a href="<?= BASE_URL ?>/modules/dashboard/mikrotik" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-router me-1"></i>MikroTik Live
        </a>
        <?php endif; ?>
        <button class="btn btn-sm btn-outline-secondary" id="refreshDashboard">
            <i class="bi bi-arrow-clockwise me-1"></i>Refresh
        </button>
        <?php if (hasMinRole(ROLE_ADMIN)): ?>
        <a href="<?= BASE_URL ?>/modules/reports/" class="btn btn-sm btn-primary">
            <i class="bi bi-bar-chart me-1"></i>Reports
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4" id="kpiCards">
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card kpi-card h-100">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small mb-1">Active</div>
                        <div class="kpi-value text-success" id="kpiActive">—</div>
                    </div>
                    <div class="kpi-icon bg-success-subtle text-success">
                        <i class="bi bi-person-check-fill"></i>
                    </div>
                </div>
                <div class="mt-2 small text-muted">
                    Total: <span class="fw-medium text-body" id="kpiTotal">—</span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card kpi-card h-100">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small mb-1">Pending</div>
                        <div class="kpi-value text-secondary" id="kpiPending">—</div>
                    </div>
                    <div class="kpi-icon bg-secondary-subtle text-secondary">
                        <i class="bi bi-hourglass-split"></i>
                    </div>
                </div>
                <div class="mt-2 small text-muted">awaiting activation</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card kpi-card h-100">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small mb-1">Suspended</div>
                        <div class="kpi-value text-warning" id="kpiSuspended">—</div>
                    </div>
                    <div class="kpi-icon bg-warning-subtle text-warning">
                        <i class="bi bi-person-dash-fill"></i>
                    </div>
                </div>
                <div class="mt-2 small text-muted">on hold</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card kpi-card h-100">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small mb-1">Expired</div>
                        <div class="kpi-value text-danger" id="kpiExpired">—</div>
                    </div>
                    <div class="kpi-icon bg-danger-subtle text-danger">
                        <i class="bi bi-person-x-fill"></i>
                    </div>
                </div>
                <div class="mt-2 small text-muted">needs renewal</div>
            </div>
        </div>
    </div>
</div>

<!-- Financial KPI Cards -->
<?php if ($canViewFinance): ?>
<div class="card border-0 mb-4">
    <div class="card-body py-3">
        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
            <h6 class="fw-semibold mb-0">Financial Summary</h6>
            <div class="btn-group btn-group-sm" role="group" id="kpiPeriodFilter">
                <button type="button" class="btn btn-secondary active" data-kpi-period="daily">Daily</button>
                <button type="button" class="btn btn-outline-secondary" data-kpi-period="weekly">Weekly</button>
                <?php if (!$isCashierOnly): ?>
                <button type="button" class="btn btn-outline-secondary" data-kpi-period="monthly">Monthly</button>
                <button type="button" class="btn btn-outline-secondary" data-kpi-period="yearly">Yearly</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="row g-3">
            <div class="col-12 col-sm-4">
                <div class="card kpi-card kpi-l-success h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small mb-1">Income</div>
                                <div class="kpi-value text-success" id="kpiIncome">—</div>
                            </div>
                            <div class="kpi-icon bg-success-subtle text-success">
                                <i class="bi bi-cash-stack"></i>
                            </div>
                        </div>
                        <div class="mt-2 small text-muted">
                            <span id="kpiIncomeCount">—</span> payments
                        </div>
                        <div class="kpi-income-meter mt-3" aria-hidden="true">
                            <span id="kpiOfficeIncomeBar"></span>
                            <span id="kpiPortalIncomeBar"></span>
                        </div>
                        <div class="row g-2 mt-2 kpi-income-split">
                            <div class="col-6">
	                                <div class="kpi-income-tile">
	                                    <div class="text-muted text-xs">Office Collection</div>
	                                    <div class="fw-semibold text-success" id="kpiOfficeIncome">—</div>
	                                    <div class="text-muted text-xs"><span id="kpiOfficeCount">—</span> payments</div>
	                                    <div class="kpi-office-types mt-2 d-none" id="kpiOfficeTypeBreakdown"></div>
	                                </div>
                            </div>
                            <div class="col-6">
                                <div class="kpi-income-tile">
                                    <div class="text-muted text-xs">Portal Collection</div>
                                    <div class="fw-semibold text-primary" id="kpiPortalIncome">—</div>
                                    <div class="text-muted text-xs"><span id="kpiPortalCount">—</span> payments</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-4">
                <div class="card kpi-card kpi-l-danger h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small mb-1">Expenses</div>
                                <div class="kpi-value text-danger" id="kpiExpenses">—</div>
                            </div>
                            <div class="kpi-icon bg-danger-subtle text-danger">
                                <i class="bi bi-credit-card-fill"></i>
                            </div>
                        </div>
                        <div class="mt-2 small text-muted">
                            <span id="kpiExpensesCount">—</span> records
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-4">
                <div class="card kpi-card h-100" id="kpiNetCard">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small mb-1">Net</div>
                                <div class="kpi-value" id="kpiNet">—</div>
                            </div>
                            <div class="kpi-icon" id="kpiNetIcon">
                                <i class="bi bi-graph-up-arrow"></i>
                            </div>
                        </div>
                        <div class="mt-2 small text-muted" id="kpiNetLabel">this period</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canViewFinance): ?>
<!-- Router Status Cards -->
<div class="mb-4">
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <h6 class="fw-semibold mb-0">
            Router Status
            <span class="badge bg-secondary ms-2" id="routerOnlineCount">Loading...</span>
        </h6>
        <span class="text-muted small" id="lastSyncTime">—</span>
    </div>
    <div class="row g-3" id="routerCards">
        <?php if ($totalRouters == 0): ?>
        <?= inlineToasts([['message' => 'No routers configured. Go to Routers to add one.', 'type' => 'info']]) ?>
        <?php else: ?>
        <div class="col-12 text-center py-4 text-muted" id="routerLoadingMsg">
            <div class="spinner-border spinner-border-sm me-2"></div>Connecting to routers...
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Charts + Recent Activity -->
<div class="row g-4">
    <!-- Revenue Chart -->
    <?php if ($canViewFinance): ?>
    <div class="col-lg-8">
        <div class="card border-0 h-100">
            <div class="card-header bg-transparent border-bottom d-flex align-items-center justify-content-between py-3 flex-wrap gap-2">
                <h6 class="mb-0 fw-semibold" id="revenueChartTitle">Income vs Expenses (Last 12 Months)</h6>
                <div class="btn-group btn-group-sm" role="group" id="revPeriodFilter">
                    <?php if ($isCashierOnly): ?>
                    <button type="button" class="btn btn-secondary active" data-period="daily">Daily</button>
                    <button type="button" class="btn btn-outline-secondary" data-period="weekly">Weekly</button>
                    <?php else: ?>
                    <button type="button" class="btn btn-outline-secondary" data-period="daily">Daily</button>
                    <button type="button" class="btn btn-secondary active" data-period="monthly">Monthly</button>
                    <button type="button" class="btn btn-outline-secondary" data-period="weekly">Weekly</button>
                    <button type="button" class="btn btn-outline-secondary" data-period="yearly">Yearly</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <canvas id="revenueChart" height="100"></canvas>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Plans Distribution -->
    <?php if ($canViewFinance): ?>
    <div class="col-lg-4">
        <div class="card border-0 h-100">
            <div class="card-header bg-transparent border-bottom py-3">
                <h6 class="mb-0 fw-semibold">Active Subscribers by Plan</h6>
            </div>
            <div class="card-body">
                <canvas id="plansChart"></canvas>
            </div>
        </div>
    </div>
    <?php endif; ?>


    <!-- Top 10 Barangays -->
    <div class="col-12">
        <div class="card border-0">
            <div class="card-header bg-transparent border-bottom py-2">
                <h6 class="mb-0 fw-semibold">
                    Top 10 Barangays by Subscribers
                </h6>
            </div>
            <div class="card-body p-0" id="topBarangays">
                <div class="text-center text-muted py-3"><div class="spinner-border spinner-border-sm"></div></div>
            </div>
        </div>
    </div>

    <!-- Router Reports -->
    <?php if ($isSuperadmin): ?>
    <div class="col-12">
        <div class="card border-0">
            <div class="card-header bg-transparent border-bottom py-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
                <h6 class="mb-0 fw-semibold">
                    Router Reports
                    <span class="badge bg-primary-subtle text-primary fw-normal ms-2 small" id="branchPeriodLabel"></span>
                </h6>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <div class="btn-group btn-group-sm" role="group" id="branchPeriodGroup">
                        <button type="button" class="btn btn-outline-secondary branch-period-btn" data-period="daily">Daily</button>
                        <button type="button" class="btn btn-outline-secondary branch-period-btn" data-period="weekly">Weekly</button>
                        <button type="button" class="btn btn-secondary branch-period-btn active" data-period="monthly">Monthly</button>
                        <button type="button" class="btn btn-outline-secondary branch-period-btn" data-period="yearly">Yearly</button>
                    </div>
                    <select id="branchYearSelect" class="form-select form-select-sm d-none" style="width:auto;">
                        <option value="<?= date('Y') ?>"><?= date('Y') ?></option>
                    </select>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-4 align-items-start">
                    <div class="col-lg-8" id="branchTableWrap">
                        <div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm"></div></div>
                    </div>
                    <div class="col-lg-4">
                        <p class="fw-semibold text-center small mb-2 text-muted" id="branchPieTitle">Collection by Router</p>
                        <canvas id="branchPieChart" height="260"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════
     LINEMAN REPORT
     ═══════════════════════════════════════════════════ -->
<?php if ($showLinemanReport): ?>
<?php
$lnPeriodLabels = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'];
$lnColCount     = $lnIsLineman ? 5 : 6;
?>
<div class="row g-4 mt-0">
    <div class="col-12">
        <div class="card border-0">
            <div class="card-header bg-transparent border-bottom py-3 d-flex align-items-start justify-content-between flex-wrap gap-2">
                <div>
                    <h6 class="mb-0 fw-semibold">Lineman Report</h6>
                    <div class="small text-muted mt-1">
                        <?= number_format($lnTotal) ?> subscriber<?= $lnTotal !== 1 ? 's' : '' ?> assigned
                        — <?= $lnPeriodLabels[$lnPeriod] ?>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <?php if (!$lnIsLineman && !empty($lnUsers)): ?>
                    <form method="GET" class="d-flex gap-1 align-items-center">
                        <input type="hidden" name="ln_period" value="<?= e($lnPeriod) ?>">
                        <select name="ln_lineman" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
                            <option value="">All Linemen</option>
                            <?php foreach ($lnUsers as $lu): ?>
                            <option value="<?= (int)$lu['user_id'] ?>" <?= $lnFilterId === (int)$lu['user_id'] ? 'selected' : '' ?>>
                                <?= e(trim($lu['firstname'] . ' ' . $lu['lastname'])) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <?php endif; ?>
                    <div class="btn-group btn-group-sm" role="group">
                        <?php foreach ($lnPeriodLabels as $p => $l): ?>
                        <a href="?ln_period=<?= $p ?>&amp;ln_lineman=<?= $lnFilterId ?>"
                           class="btn <?= $lnPeriod === $p ? 'btn-secondary active' : 'btn-outline-secondary' ?>">
                            <?= $l ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3" style="width:2.5rem;">#</th>
                                <th>Subscriber Name</th>
                                <th>Address</th>
                                <th>Plan</th>
                                <?php if (!$lnIsLineman): ?><th>Lineman</th><?php endif; ?>
                                <th class="pe-3">Assigned</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($lnRows)): ?>
                            <tr>
                                <td colspan="<?= $lnColCount ?>" class="text-center text-muted py-5">
                                    <i class="bi bi-person-workspace d-block fs-3 mb-2 opacity-50"></i>
                                    No assignments found for this period.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($lnRows as $i => $ln):
                                $addrParts   = array_filter([$ln['street'] ?? '', $ln['barangay'] ?? '', $ln['municipality'] ?? '']);
                                $displayAddr = !empty($addrParts)
                                    ? implode(', ', $addrParts)
                                    : ($ln['address'] ?? '');
                            ?>
                            <tr>
                                <td class="ps-3 text-muted small"><?= $lnOffset + $i + 1 ?></td>
                                <td class="small fw-medium">
                                    <a href="<?= BASE_URL ?>/modules/subscribers/view/<?= (int)$ln['subscriber_id'] ?>"
                                       class="text-decoration-none">
                                        <?= e(trim($ln['firstname'] . ' ' . $ln['lastname'])) ?>
                                    </a>
                                </td>
                                <td class="small text-muted"><?= e($displayAddr ?: '—') ?></td>
                                <td class="small"><?= e($ln['plan_title'] ?? '—') ?></td>
                                <?php if (!$lnIsLineman): ?>
                                <td class="small"><?= e(trim(($ln['lm_first'] ?? '') . ' ' . ($ln['lm_last'] ?? '')) ?: '—') ?></td>
                                <?php endif; ?>
                                <td class="pe-3 small text-muted"><?= $ln['assigned_at'] ? formatDate($ln['assigned_at'], 'M d, Y') : '—' ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="table-light fw-semibold">
                                <td class="ps-3 small" colspan="<?= $lnColCount - 1 ?>">Total</td>
                                <td class="pe-3 small text-end"><?= number_format($lnTotal) ?></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($lnTotalPages > 1): ?>
            <div class="card-footer bg-transparent border-top d-flex align-items-center justify-content-between flex-wrap gap-2">
                <small class="text-muted">
                    Showing <?= number_format($lnOffset + 1) ?>–<?= number_format(min($lnOffset + $lnPerPage, $lnTotal)) ?>
                    of <?= number_format($lnTotal) ?>
                </small>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $lnPage <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?ln_period=<?= $lnPeriod ?>&amp;ln_lineman=<?= $lnFilterId ?>&amp;ln_page=<?= $lnPage - 1 ?>">‹</a>
                        </li>
                        <?php for ($p = max(1, $lnPage - 2); $p <= min($lnTotalPages, $lnPage + 2); $p++): ?>
                        <li class="page-item <?= $p === $lnPage ? 'active' : '' ?>">
                            <a class="page-link" href="?ln_period=<?= $lnPeriod ?>&amp;ln_lineman=<?= $lnFilterId ?>&amp;ln_page=<?= $p ?>"><?= $p ?></a>
                        </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $lnPage >= $lnTotalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?ln_period=<?= $lnPeriod ?>&amp;ln_lineman=<?= $lnFilterId ?>&amp;ln_page=<?= $lnPage + 1 ?>">›</a>
                        </li>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$selRouterId = selectedRouterId();
$dashboardRouterIds = array_map('intval', array_column($dashboardRouters, 'router_id'));
$extraScripts = '<script>window.SEL_ROUTER_ID = ' . (int)$selRouterId . '; window.DASHBOARD_ROUTER_IDS = ' . json_encode($dashboardRouterIds) . '; window.CASHIER_ONLY = ' . ($isCashierOnly ? 'true' : 'false') . ';</script>' . "\n";
$extraScripts .= <<<'JS'
<script>
let revenueChart = null, plansChart = null;
let activePeriod    = window.CASHIER_ONLY ? 'daily' : 'monthly';
let activeKpiPeriod = 'daily';

const PERIOD_LABELS = {
    daily:   'Income vs Expenses (Last 30 Days)',
    weekly:  'Income vs Expenses (Last 12 Weeks)',
    monthly: 'Income vs Expenses (Last 12 Months)',
    yearly:  'Income vs Expenses (Yearly)',
};

function formatMoney(v) {
    return (window.CURRENCY_SYMBOL || '₱') + parseFloat(v).toLocaleString('en-PH', {minimumFractionDigits: 2});
}

// ── Load dashboard stats ──────────────────────────────────────
let activeBranchPeriod = window.CASHIER_ONLY ? 'daily' : 'monthly';

function loadStats(period, branchYear, forceRefresh) {
    if (period) activePeriod = period;
    if (!branchYear) branchYear = document.getElementById('branchYearSelect')?.value || '';
    const qs = new URLSearchParams({ period: activePeriod, branch_period: activeBranchPeriod, kpi_period: activeKpiPeriod });
    if (branchYear) qs.set('branch_year', branchYear);
    if (forceRefresh) qs.set('_refresh', Date.now());
    return fetch(BASE_URL + '/api/dashboard_stats.php?' + qs.toString())
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                showToast(d.message || 'Unable to refresh dashboard stats.', 'danger');
                return;
            }

            // KPIs
            const _el = id => document.getElementById(id);
            if (_el('kpiTotal'))     _el('kpiTotal').textContent     = parseInt(d.subscribers.total     || 0).toLocaleString();
            if (_el('kpiActive'))    _el('kpiActive').textContent    = parseInt(d.subscribers.active    || 0).toLocaleString();
            if (_el('kpiPending'))   _el('kpiPending').textContent   = parseInt(d.subscribers.pending   || 0).toLocaleString();
            if (_el('kpiSuspended')) _el('kpiSuspended').textContent = parseInt(d.subscribers.suspended || 0).toLocaleString();
            if (_el('kpiExpired'))   _el('kpiExpired').textContent   = parseInt(d.subscribers.expired   || 0).toLocaleString();
            // Financial KPI cards
            const inc     = parseFloat(d.kpi_income   || 0);
            const exp     = parseFloat(d.kpi_expenses || 0);
            const net     = parseFloat(d.kpi_net      ?? (inc - exp));
            const office  = parseFloat(d.kpi_office_income || 0);
            const portal  = parseFloat(d.kpi_portal_income || 0);
            const officePct = inc > 0 ? Math.round(office / inc * 100) : 0;
            const portalPct = inc > 0 ? Math.max(0, 100 - officePct) : 0;
            const surplus = net >= 0;
            const renderOfficeTypes = (rows, period) => {
                const wrap = _el('kpiOfficeTypeBreakdown');
                if (!wrap) return;
                if (period !== 'daily' || !Array.isArray(rows) || rows.length === 0) {
                    wrap.classList.add('d-none');
                    wrap.innerHTML = '';
                    return;
                }
                wrap.classList.remove('d-none');
                wrap.innerHTML = rows.map(row => {
                    const label = escHtml(row.type_name || 'Subscription');
                    const total = formatMoney(parseFloat(row.total || 0));
                    const count = parseInt(row.count || 0).toLocaleString();
                    return `<div class="kpi-office-type-row">
                        <span class="kpi-office-type-name">${label}</span>
                        <span class="kpi-office-type-value">${total}</span>
                        <span class="kpi-office-type-count">${count}</span>
                    </div>`;
                }).join('');
            };
            if (_el('kpiIncome'))       _el('kpiIncome').textContent       = formatMoney(inc);
            if (_el('kpiIncomeCount'))  _el('kpiIncomeCount').textContent  = parseInt(d.kpi_income_count  || 0).toLocaleString();
            if (_el('kpiOfficeIncome')) _el('kpiOfficeIncome').textContent = formatMoney(office);
            if (_el('kpiOfficeCount'))  _el('kpiOfficeCount').textContent  = parseInt(d.kpi_office_count  || 0).toLocaleString();
            if (_el('kpiPortalIncome')) _el('kpiPortalIncome').textContent = formatMoney(portal);
            if (_el('kpiPortalCount'))  _el('kpiPortalCount').textContent  = parseInt(d.kpi_portal_count  || 0).toLocaleString();
            renderOfficeTypes(d.kpi_office_by_type || [], d.kpi_period || activeKpiPeriod);
            if (_el('kpiOfficeIncomeBar')) _el('kpiOfficeIncomeBar').style.width = officePct + '%';
            if (_el('kpiPortalIncomeBar')) _el('kpiPortalIncomeBar').style.width = portalPct + '%';
            if (_el('kpiExpenses'))     _el('kpiExpenses').textContent     = formatMoney(exp);
            if (_el('kpiExpensesCount'))_el('kpiExpensesCount').textContent= parseInt(d.kpi_expenses_count|| 0).toLocaleString();
            if (_el('kpiNet')) {
                _el('kpiNet').textContent  = (surplus ? '' : '−') + formatMoney(Math.abs(net));
                _el('kpiNet').className    = 'kpi-value ' + (surplus ? 'text-success' : 'text-danger');
            }
            if (_el('kpiNetLabel'))  _el('kpiNetLabel').textContent  = surplus ? 'surplus this period' : 'deficit this period';
            if (_el('kpiNetCard'))   _el('kpiNetCard').className      = 'card kpi-card h-100 ' + (surplus ? 'kpi-l-success' : 'kpi-l-danger');
            if (_el('kpiNetIcon'))  { _el('kpiNetIcon').className     = 'kpi-icon ' + (surplus ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger');
                                      _el('kpiNetIcon').innerHTML     = surplus ? '<i class="bi bi-graph-up-arrow"></i>' : '<i class="bi bi-graph-down-arrow"></i>'; }

            // Revenue chart
            const titleEl = document.getElementById('revenueChartTitle');
            if (titleEl) titleEl.textContent = PERIOD_LABELS[activePeriod] || PERIOD_LABELS.monthly;

            const incMap     = Object.fromEntries((d.monthly_revenue  || []).map(r => [r.month, parseFloat(r.revenue)]));
            const expMap     = Object.fromEntries((d.monthly_expenses || []).map(r => [r.month, parseFloat(r.expenses)]));
            const allLabels  = [...new Set([...Object.keys(incMap), ...Object.keys(expMap)])];
            const incData    = allLabels.map(l => incMap[l] ?? 0);
            const expData    = allLabels.map(l => expMap[l] ?? 0);
            const netData    = allLabels.map((_, i) => incData[i] - expData[i]);

            if (revenueChart) revenueChart.destroy();
            const revenueCtxEl = document.getElementById('revenueChart');
            if (revenueCtxEl) {
            const ctx = revenueCtxEl.getContext('2d');
            revenueChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: allLabels,
                    datasets: [
                        {
                            label: 'Income',
                            data: incData,
                            backgroundColor: 'rgba(25,135,84,0.7)',
                            borderColor: '#198754',
                            borderWidth: 1,
                            borderRadius: 4,
                            order: 2,
                        },
                        {
                            label: 'Expenses',
                            data: expData,
                            backgroundColor: 'rgba(220,53,69,0.7)',
                            borderColor: '#dc3545',
                            borderWidth: 1,
                            borderRadius: 4,
                            order: 3,
                        },
                        {
                            label: 'Net',
                            data: netData,
                            type: 'line',
                            borderColor: '#0d6efd',
                            backgroundColor: 'rgba(13,110,253,0.08)',
                            borderWidth: 2,
                            pointRadius: 3,
                            fill: false,
                            tension: 0.3,
                            order: 1,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { display: true, position: 'top', labels: { font: { size: 11 }, boxWidth: 12, padding: 16 } },
                        tooltip: {
                            callbacks: {
                                label: ctx => ` ${ctx.dataset.label}: ${formatMoney(ctx.raw)}`
                            }
                        }
                    },
                    scales: {
                        y: {
                            ticks: { callback: v => (window.CURRENCY_SYMBOL || '₱') + v.toLocaleString() },
                            grid: { color: 'rgba(0,0,0,.05)' }
                        }
                    }
                }
            });
            } // end if (revenueCtxEl)

            // Plans donut chart
            const plansCtxEl = document.getElementById('plansChart');
            if (plansCtxEl) {
            const planLabels = d.plans_dist.map(p => p.title);
            const planData   = d.plans_dist.map(p => parseInt(p.cnt));
            const palette = ['#0d6efd','#6f42c1','#d63384','#fd7e14','#20c997','#0dcaf0','#ffc107'];
            if (plansChart) plansChart.destroy();
            plansChart = new Chart(plansCtxEl.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: planLabels,
                    datasets: [{ data: planData, backgroundColor: palette, borderWidth: 2 }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } }
                }
            });
            } // end if (plansCtxEl)

            // Branch reports
            renderBranchReports(d);

            // Top 10 barangays
            const barangays = d.top_barangays || [];
            const maxCnt    = barangays.length ? parseInt(barangays[0].cnt) : 1;
            const rankColors = ['#FFD700','#C0C0C0','#CD7F32'];
            let brgHtml = '';
            if (barangays.length === 0) {
                brgHtml = '<div class="text-center text-muted py-4 small">No barangay data available.</div>';
            } else {
                brgHtml = '<div class="table-responsive"><table class="table table-sm table-hover mb-0" style="font-size:.82rem;">'
                    + '<thead class="table-light"><tr>'
                    + '<th style="width:2rem;">#</th>'
                    + '<th>Barangay</th>'
                    + '<th class="d-none d-md-table-cell text-muted">Municipality</th>'
                    + '<th class="text-center text-success" title="Active">Act.</th>'
                    + '<th class="text-center text-danger" title="Expired">Exp.</th>'
                    + '<th class="text-end">Total</th>'
                    + '<th style="width:80px;"></th>'
                    + '</tr></thead><tbody>';
                barangays.forEach((b, i) => {
                    const cnt     = parseInt(b.cnt);
                    const active  = parseInt(b.active_count  || 0);
                    const expired = parseInt(b.expired_count || 0);
                    const pct     = Math.round(cnt / maxCnt * 100);
                    const rankCol = rankColors[i] || '#6c757d';
                    const rankBg  = i < 3 ? `background:${rankCol};color:#000;` : 'background:#e9ecef;color:#495057;';
                    brgHtml += `<tr>
                        <td><span class="badge rounded-pill" style="${rankBg}font-size:.7rem;min-width:1.5rem;">${i + 1}</span></td>
                        <td class="fw-medium text-truncate" style="max-width:130px;">${escHtml(b.barangay)}</td>
                        <td class="d-none d-md-table-cell text-muted text-truncate" style="max-width:110px;">${escHtml(b.municipality || '')}</td>
                        <td class="text-center text-success">${active}</td>
                        <td class="text-center text-danger">${expired}</td>
                        <td class="text-end fw-semibold">${cnt}</td>
                        <td>
                            <div class="progress mt-1" style="height:5px;">
                                <div class="progress-bar bg-primary" style="width:${pct}%;border-radius:3px;"></div>
                            </div>
                        </td>
                    </tr>`;
                });
                brgHtml += '</tbody></table></div>';
            }
            document.getElementById('topBarangays').innerHTML = brgHtml;
        })
        .catch(() => showToast('Unable to refresh dashboard stats.', 'danger'));
}

// ── Router reports renderer ───────────────────────────────────
let branchPieChart = null;
const BRANCH_PALETTE = ['#0d6efd','#20c997','#6f42c1','#fd7e14','#d63384','#0dcaf0','#ffc107','#dc3545'];

function renderBranchReports(d) {
    const branches = d.branch_reports || [];
    const period   = d.branch_period  || 'monthly';
    const label    = d.branch_label   || '';

    // Period label badge
    const labelEl = document.getElementById('branchPeriodLabel');
    if (labelEl) labelEl.textContent = label;

    // Year select — only visible for yearly period
    const sel = document.getElementById('branchYearSelect');
    if (sel) {
        sel.classList.toggle('d-none', period !== 'yearly');
        if (period === 'yearly' && d.branch_years) {
            const cur = sel.value || String(d.branch_year || new Date().getFullYear());
            sel.innerHTML = d.branch_years.map(y =>
                `<option value="${y}" ${String(y) === cur ? 'selected' : ''}>${y}</option>`
            ).join('');
        }
    }

    // Table
    const wrap = document.getElementById('branchTableWrap');
    if (!wrap) return;

    if (!branches.length) {
        wrap.innerHTML = '<div class="text-muted small text-center py-4">No payment data found.</div>';
        return;
    }

    const totalCollection = branches.reduce((s, b) => s + parseFloat(b.collection), 0);

    let rows = branches.map((b, i) => {
        const col  = parseFloat(b.collection);
        const pct  = totalCollection > 0 ? (col / totalCollection * 100).toFixed(1) : '0.0';
        const dot  = `<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${BRANCH_PALETTE[i % BRANCH_PALETTE.length]};margin-right:6px;"></span>`;
        const locParts = [b.municipality, b.province, b.region].filter(Boolean);
        const locStr   = locParts.length ? locParts.join(', ') : (b.address || '');
        return `<tr>
            <td class="py-3">
                <div class="fw-semibold">${dot}${escHtml(b.branch_name)}</div>
                ${locStr ? `<div class="text-muted small"><i class="bi bi-geo-alt me-1"></i>${escHtml(locStr)}</div>` : ''}
            </td>
            <td class="py-3 text-success fw-semibold">${formatMoney(col)}</td>
            <td class="py-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="progress flex-grow-1" style="height:6px;">
                        <div class="progress-bar" style="width:${pct}%;background:${BRANCH_PALETTE[i % BRANCH_PALETTE.length]};"></div>
                    </div>
                    <span class="text-muted small" style="min-width:36px;">${pct}%</span>
                </div>
            </td>
        </tr>`;
    }).join('');

    // Totals row
    rows += `<tr class="table-light fw-bold border-top">
        <td class="py-2">Total</td>
        <td class="py-2 text-success">${formatMoney(totalCollection)}</td>
        <td class="py-2"></td>
    </tr>`;

    wrap.innerHTML = `<div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="fw-semibold">Router</th>
                    <th class="fw-semibold">Collection</th>
                    <th class="fw-semibold">Share</th>
                </tr>
            </thead>
            <tbody>${rows}</tbody>
        </table>
    </div>`;

    // Pie chart
    const ctx = document.getElementById('branchPieChart')?.getContext('2d');
    if (!ctx) return;
    if (branchPieChart) branchPieChart.destroy();
    branchPieChart = new Chart(ctx, {
        type: 'pie',
        data: {
            labels: branches.map(b => b.branch_name),
            datasets: [{
                data: branches.map(b => parseFloat(b.collection)),
                backgroundColor: branches.map((_, i) => BRANCH_PALETTE[i % BRANCH_PALETTE.length]),
                borderWidth: 2,
                borderColor: '#fff',
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { font: { size: 11 }, padding: 10, boxWidth: 12 }
                },
                tooltip: {
                    callbacks: {
                        label: ctx => {
                            const val = parseFloat(ctx.raw);
                            const pct = totalCollection > 0 ? (val / totalCollection * 100).toFixed(1) : 0;
                            return ` ${formatMoney(val)} (${pct}%)`;
                        }
                    }
                }
            }
        }
    });
}

// ── Load router status ────────────────────────────────────────
// live=true  → ?live=1  (real TCP status + live session counts)
// live=false → cached DB status (instant)
function loadLiveRouterCounts(routerId) {
    const qs = new URLSearchParams({ router_id: routerId, limit: '10', offset: '0' });
    return fetchWithTimeout(BASE_URL + '/api/bandwidth_stats.php?' + qs.toString(), {}, 25000)
        .then(r => r.json())
        .then(data => {
            if (!data.success) return null;
            return {
                ppp_active: data.ppp_count,
                hotspot_active: data.hs_count,
                radius_active: data.radius_count,
                session_counts_live: true,
            };
        })
        .catch(() => null);
}

function renderRouterCards(routers, live) {
    document.getElementById('routerLoadingMsg')?.remove();
    const container = document.getElementById('routerCards');
    if (!container) return;

    if (!routers.length) {
        container.innerHTML = '';
        showToast('No router selected.', 'warning');
        document.getElementById('routerOnlineCount').textContent = '—';
        return;
    }

    const countLabel = value => {
        const n = Number.parseInt(value, 10);
        return Number.isFinite(n) ? n.toLocaleString() : '—';
    };

    const onlineRouters = routers.filter(r => r.online);
    if (!onlineRouters.length) {
        container.innerHTML = '';
        showToast('No routers online right now.', 'warning');
        document.getElementById('routerOnlineCount').textContent = `0/${routers.length} online`;
        const syncType = live ? 'Live' : 'Cached';
        document.getElementById('lastSyncTime').textContent = syncType + ': ' + new Date().toLocaleTimeString();
        return;
    }

    container.innerHTML = onlineRouters.map(r => {
        const cpuNum = Number.parseInt(r.cpu_load, 10);
        const cpu    = Number.isFinite(cpuNum) ? cpuNum + '%' : '—';
        const ppp    = countLabel(r.ppp_active);
        const hs     = countLabel(r.hotspot_active);
        const radius = countLabel(r.radius_active);
        const statusClass = r.online ? 'border-success' : 'border-danger';
        const statusBadge = r.online
            ? '<span class="badge bg-success"><i class="bi bi-circle-fill me-1" style="font-size:.5rem;"></i>Online</span>'
            : '<span class="badge bg-danger"><i class="bi bi-circle-fill me-1" style="font-size:.5rem;"></i>Offline</span>';
        const syncLabel = r.is_cached
            ? `<span class="badge bg-secondary bg-opacity-25 text-secondary border" style="font-size:.6rem;">Cached${r.last_sync ? ' · ' + new Date(r.last_sync).toLocaleTimeString() : ''}</span>`
            : `<span class="badge bg-success bg-opacity-25 text-success border" style="font-size:.6rem;">Live · ${new Date().toLocaleTimeString()}</span>`;

        return `<div class="col-12 col-sm-6 col-xl-4">
            <div class="card border-2 ${statusClass} h-100">
                <div class="card-body pb-2">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <div class="fw-semibold">${escHtml(r.name)}</div>
                            <div class="text-muted small">${escHtml(r.identity ?? r.host)}</div>
                        </div>
                        ${statusBadge}
                    </div>
                    <div class="row g-2 text-center mt-1">
                        <div class="col-3"><div class="bg-light rounded p-1"><div class="fw-bold small">${ppp}</div><div class="text-muted" style="font-size:.7rem;">PPP</div></div></div>
                        <div class="col-3"><div class="bg-light rounded p-1"><div class="fw-bold small">${hs}</div><div class="text-muted" style="font-size:.7rem;">Hotspot</div></div></div>
                        <div class="col-3"><div class="bg-light rounded p-1"><div class="fw-bold small">${radius}</div><div class="text-muted" style="font-size:.7rem;">RADIUS</div></div></div>
                        <div class="col-3"><div class="bg-light rounded p-1"><div class="fw-bold small ${r.online && cpuNum > 80 ? 'text-danger' : ''}">${cpu}</div><div class="text-muted" style="font-size:.7rem;">CPU</div></div></div>
                    </div>
                    ${r.uptime ? `<div class="text-muted mt-2" style="font-size:.7rem;">Uptime: ${r.uptime}</div>` : ''}
                    <div class="mt-1">${syncLabel}</div>
                </div>
            </div>
        </div>`;
    }).join('');

    document.getElementById('routerOnlineCount').textContent = `${onlineRouters.length}/${routers.length} online`;
    const syncType = live ? 'Live' : 'Cached';
    document.getElementById('lastSyncTime').textContent = syncType + ': ' + new Date().toLocaleTimeString();
}

function loadOneRouterStatus(routerId, live, forceRefresh) {
    const qs = new URLSearchParams({ id: routerId });
    if (live) { qs.set('live', '1'); qs.set('quick', '1'); }
    if (forceRefresh) qs.set('_refresh', Date.now());
    const timeout = live ? 12000 : 6000;

    return fetchWithTimeout(BASE_URL + '/api/router_status.php?' + qs.toString(), {}, timeout)
        .then(r => r.json())
        .then(d => {
            if (!d.success) return null;
            if (!live || !d.online) return d;
            return loadLiveRouterCounts(routerId).then(counts => counts ? {...d, ...counts} : d);
        })
        .catch(() => null);
}

function loadRouterStatus(live, forceRefresh) {
    const routerIds = Array.isArray(window.DASHBOARD_ROUTER_IDS) ? window.DASHBOARD_ROUTER_IDS.filter(Boolean) : [];
    if (!routerIds.length) {
        document.getElementById('routerLoadingMsg')?.remove();
        const container = document.getElementById('routerCards');
        if (container) { container.innerHTML = ''; showToast('No router selected.', 'warning'); }
        const ocEl = document.getElementById('routerOnlineCount');
        if (ocEl) ocEl.textContent = '—';
        return Promise.resolve();
    }

    return Promise.all(routerIds.map(id => loadOneRouterStatus(id, live, forceRefresh)))
        .then(results => renderRouterCards(results.filter(Boolean), live))
        .catch(err => {
            document.getElementById('routerLoadingMsg')?.remove();
            if (err.name === 'AbortError') showReloadToast('Router is taking too long to respond. Reload to try again.');
            else showToast('Unable to refresh router status.', 'danger');
        });
}

// ── Init ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    loadStats();
    loadRouterStatus(false); // cached on load — instant, shows last-known metrics
    loadRouterStatus(true);  // live fetch fires immediately; updates PPP/Hotspot/CPU when it returns

    // Keep the router card live; cached refresh can overwrite session counts with stale DB values.
    setInterval(() => loadRouterStatus(true), 15000);
    setInterval(loadStats, 60000);

    document.getElementById('refreshDashboard')?.addEventListener('click', function () {
        const icon = this.querySelector('i');
        this.disabled = true;
        icon?.classList.add('spinning');
        Promise.allSettled([
            loadStats(null, null, true),
            loadRouterStatus(true, true), // live on manual refresh button
        ]).finally(() => {
            this.disabled = false;
            icon?.classList.remove('spinning');
        });
    });

    // Financial KPI period filter
    document.getElementById('kpiPeriodFilter')?.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-kpi-period]');
        if (!btn) return;
        this.querySelectorAll('button').forEach(b => {
            b.classList.remove('active', 'btn-secondary');
            b.classList.add('btn-outline-secondary');
        });
        btn.classList.add('active', 'btn-secondary');
        btn.classList.remove('btn-outline-secondary');
        activeKpiPeriod = btn.dataset.kpiPeriod;
        loadStats();
    });

    // Revenue period filter
    document.getElementById('revPeriodFilter')?.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-period]');
        if (!btn) return;
        this.querySelectorAll('button').forEach(b => {
            b.classList.remove('active', 'btn-secondary');
            b.classList.add('btn-outline-secondary');
        });
        btn.classList.add('active', 'btn-secondary');
        btn.classList.remove('btn-outline-secondary');
        loadStats(btn.dataset.period);
    });

    // Router reports — period toggle
    document.getElementById('branchPeriodGroup')?.addEventListener('click', function (e) {
        const btn = e.target.closest('.branch-period-btn');
        if (!btn) return;
        this.querySelectorAll('.branch-period-btn').forEach(b => {
            b.classList.remove('active', 'btn-secondary');
            b.classList.add('btn-outline-secondary');
        });
        btn.classList.add('active', 'btn-secondary');
        btn.classList.remove('btn-outline-secondary');
        activeBranchPeriod = btn.dataset.period;
        loadStats();
    });

    // Router reports — year select (yearly period only)
    document.getElementById('branchYearSelect')?.addEventListener('change', function () {
        loadStats(null, this.value);
    });

});
</script>
JS;

include BASE_PATH . '/includes/footer.php';
