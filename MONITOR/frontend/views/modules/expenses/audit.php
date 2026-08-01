<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireRole(ROLE_CASHIER, ROLE_ADMIN, ROLE_SUPERADMIN);
if (!in_array(currentRole(), MODULE_ACCESS['expenses'])) forbidden();

if (!selectedRouterId() && currentRole() !== ROLE_SUPERADMIN) {
    $router = defaultRouterForUser(currentUser() ?? []);
    if ($router) setSelectedRouter((int)$router['router_id'], $router['name']);
}

$routerId = (int)(selectedRouterId() ?: 0);
$name     = trim($_GET['name'] ?? '');
$year     = (int)($_GET['year'] ?? 0);
$month    = (int)($_GET['month'] ?? 0);
if ($month < 1 || $month > 12) $month = 0;

$where  = ['e.router_id = ?'];
$params = [$routerId];

if ($name !== '') {
    $where[] = '(
        e.employee LIKE ?
        OR e.remark LIKE ?
        OR et.name LIKE ?
        OR eu.firstname LIKE ?
        OR eu.lastname LIKE ?
        OR CONCAT(eu.firstname, " ", eu.lastname) LIKE ?
        OR u.firstname LIKE ?
        OR u.lastname LIKE ?
        OR CONCAT(u.firstname, " ", u.lastname) LIKE ?
    )';
    $like = '%' . $name . '%';
    for ($i = 0; $i < 9; $i++) $params[] = $like;
}
if ($year) {
    $where[]  = 'YEAR(e.expense_date) = ?';
    $params[] = $year;
}
if ($month) {
    $where[]  = 'MONTH(e.expense_date) = ?';
    $params[] = $month;
}

$whereStr = implode(' AND ', $where);

$years = [];
if ($routerId) {
    $yearStmt = db()->prepare("
        SELECT DISTINCT YEAR(expense_date) AS yr
        FROM expenses
        WHERE router_id = ? AND expense_date IS NOT NULL
        ORDER BY yr DESC
    ");
    $yearStmt->execute([$routerId]);
    $years = array_filter(array_map('intval', $yearStmt->fetchAll(PDO::FETCH_COLUMN)));
}

$rowsStmt = db()->prepare("
    SELECT e.*,
           et.name AS type_name,
           eu.firstname AS exp_user_first, eu.lastname AS exp_user_last,
           u.firstname AS rec_first, u.lastname AS rec_last
    FROM expenses e
    LEFT JOIN expense_types et ON et.type_id = e.expense_type_id
    LEFT JOIN users eu ON eu.user_id = e.expense_user_id
    LEFT JOIN users u ON u.user_id = e.user_id
    WHERE {$whereStr}
    ORDER BY e.expense_date ASC, e.expense_id ASC
");
$rowsStmt->execute($params);
$expenses = $rowsStmt->fetchAll();

$monthStmt = db()->prepare("
    SELECT DATE_FORMAT(e.expense_date, '%Y-%m') AS ym,
           DATE_FORMAT(e.expense_date, '%M %Y') AS month_label,
           COUNT(*) AS cnt,
           COALESCE(SUM(e.amount), 0) AS total
    FROM expenses e
    LEFT JOIN expense_types et ON et.type_id = e.expense_type_id
    LEFT JOIN users eu ON eu.user_id = e.expense_user_id
    LEFT JOIN users u ON u.user_id = e.user_id
    WHERE {$whereStr}
    GROUP BY YEAR(e.expense_date), MONTH(e.expense_date), ym, month_label
    ORDER BY YEAR(e.expense_date) ASC, MONTH(e.expense_date) ASC
");
$monthStmt->execute($params);
$monthlyTotals = $monthStmt->fetchAll();

$grandTotal = array_sum(array_map(fn($row) => (float)$row['amount'], $expenses));

$pageTitle   = 'Expense Audit';
$breadcrumbs = [
    ['label' => 'Expenses', 'url' => BASE_URL . '/modules/expenses/'],
    ['label' => 'Audit'],
];
include BASE_PATH . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">Expense Audit</h4>
        <p class="text-muted small mb-0">
            <?= number_format(count($expenses)) ?> record<?= count($expenses) !== 1 ? 's' : '' ?>
            &nbsp;·&nbsp; Grand total <?= formatMoney($grandTotal) ?>
        </p>
    </div>
    <a href="<?= BASE_URL ?>/modules/expenses/" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to Expenses
    </a>
</div>

<?php if (!$routerId): ?>
<?= inlineToasts([['message' => 'Please select a router before reviewing expense audit records.', 'type' => 'warning']]) ?>
<?php endif; ?>

<div class="filter-bar mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-sm-6 col-lg-4">
            <label class="form-label small text-muted mb-1" for="auditName">Name</label>
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-search text-muted"></i></span>
                <input type="text" name="name" id="auditName" class="form-control"
                       placeholder="User, payee, type, remark…" value="<?= e($name) ?>">
            </div>
        </div>
        <div class="col-sm-6 col-lg-2">
            <label class="form-label small text-muted mb-1" for="auditYear">Year</label>
            <select name="year" id="auditYear" class="form-select form-select-sm">
                <option value="">All Years</option>
                <?php foreach ($years as $yr): ?>
                <option value="<?= $yr ?>" <?= $year === $yr ? 'selected' : '' ?>><?= $yr ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-6 col-lg-2">
            <label class="form-label small text-muted mb-1" for="auditMonth">Month</label>
            <select name="month" id="auditMonth" class="form-select form-select-sm">
                <option value="">All Months</option>
                <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $month === $m ? 'selected' : '' ?>>
                    <?= date('F', mktime(0, 0, 0, $m, 1)) ?>
                </option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary">
                <i class="bi bi-funnel me-1"></i>Filter
            </button>
            <a href="<?= BASE_URL ?>/modules/expenses/audit" class="btn btn-sm btn-outline-secondary" title="Clear">
                <i class="bi bi-x-lg"></i>
            </a>
        </div>
    </form>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card border-0 bg-danger-subtle h-100">
            <div class="card-body">
                <div class="text-muted text-label-xs">GRAND TOTAL</div>
                <div class="fs-4 fw-bold text-danger"><?= formatMoney($grandTotal) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 bg-primary-subtle h-100">
            <div class="card-body">
                <div class="text-muted text-label-xs">AUDIT RECORDS</div>
                <div class="fs-4 fw-bold text-primary"><?= number_format(count($expenses)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 bg-success-subtle h-100">
            <div class="card-body">
                <div class="text-muted text-label-xs">MONTHS WITH EXPENSES</div>
                <div class="fs-4 fw-bold text-success"><?= number_format(count($monthlyTotals)) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 mb-3">
    <div class="card-header bg-transparent fw-semibold">
        <i class="bi bi-calendar3 me-2 text-primary"></i>Monthly Subtotals
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Month</th>
                        <th class="text-end">Records</th>
                        <th class="text-end">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($monthlyTotals)): ?>
                    <tr><td colspan="3" class="text-center text-muted py-4">No monthly totals found.</td></tr>
                    <?php else: ?>
                    <?php foreach ($monthlyTotals as $mt): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($mt['month_label'] ?: $mt['ym']) ?></td>
                        <td class="text-end"><?= number_format((int)$mt['cnt']) ?></td>
                        <td class="text-end fw-bold text-danger"><?= formatMoney($mt['total']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th>Grand Total</th>
                        <th class="text-end"><?= number_format(count($expenses)) ?></th>
                        <th class="text-end text-danger"><?= formatMoney($grandTotal) ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<div class="card border-0">
    <div class="card-header bg-transparent fw-semibold">
        <i class="bi bi-list-check me-2 text-primary"></i>Expense Records
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Name / Payee</th>
                        <th>Type</th>
                        <th>Period</th>
                        <th>Recorded By</th>
                        <th>Remark</th>
                        <th class="text-end">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($expenses)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No expense records found.</td></tr>
                    <?php else: ?>
                    <?php foreach ($expenses as $exp): ?>
                    <?php
                        $expenseUser = trim(($exp['exp_user_first'] ?? '') . ' ' . ($exp['exp_user_last'] ?? ''));
                        $recordedBy  = trim(($exp['rec_first'] ?? '') . ' ' . ($exp['rec_last'] ?? ''));
                    ?>
                    <tr>
                        <td class="text-nowrap"><?= formatDate($exp['expense_date']) ?></td>
                        <td class="fw-semibold"><?= e($expenseUser ?: ($exp['employee'] ?: '—')) ?></td>
                        <td><?= e($exp['type_name'] ?: 'Uncategorized') ?></td>
                        <td><?= e(ucfirst($exp['period_type'] ?: 'daily')) ?></td>
                        <td><?= e($recordedBy ?: '—') ?></td>
                        <td class="text-muted small"><?= e($exp['remark'] ?: '—') ?></td>
                        <td class="text-end fw-bold text-danger"><?= formatMoney($exp['amount']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include BASE_PATH . '/includes/footer.php'; ?>
