<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireRole(ROLE_CASHIER, ROLE_ADMIN, ROLE_SUPERADMIN);
if (!in_array(currentRole(), MODULE_ACCESS['expenses'])) forbidden();

if (!selectedRouterId() && currentRole() !== ROLE_SUPERADMIN) {
    $router = defaultRouterForUser(currentUser() ?? []);
    if ($router) setSelectedRouter((int)$router['router_id'], $router['name']);
}

// ── Delete (Admin+) ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_expense'])) {
    requireMinRole(ROLE_ADMIN);
    verifyCsrf();
    $delId = (int)($_POST['expense_id'] ?? 0);
    $deleteRouterId = (int)(selectedRouterId() ?: 0);
    if ($delId) {
        $row = db()->prepare("
            SELECT e.*, et.name AS type_name, eu.firstname AS exp_user_first, eu.lastname AS exp_user_last
            FROM expenses e
            LEFT JOIN expense_types et ON et.type_id = e.expense_type_id
            LEFT JOIN users eu ON eu.user_id = e.expense_user_id
            WHERE e.expense_id = ? AND e.router_id = ?
        ");
        $row->execute([$delId, $deleteRouterId]);
        $deletedExpense = $row->fetch();
        if ($deletedExpense) {
            db()->prepare("DELETE FROM expenses WHERE expense_id = ?")->execute([$delId]);
            $deletedUser = trim(($deletedExpense['exp_user_first'] ?? '') . ' ' . ($deletedExpense['exp_user_last'] ?? ''));
            logActivity(
                'expenses',
                'delete',
                "Deleted expense #{$delId}: amount " . formatMoney((float)$deletedExpense['amount']) . ", date {$deletedExpense['expense_date']}, period {$deletedExpense['period_type']}, type " . ($deletedExpense['type_name'] ?: 'Uncategorized') . ", payee " . ($deletedUser ?: ($deletedExpense['employee'] ?: 'not specified')) . ", remark " . ($deletedExpense['remark'] ?: 'none') . ".",
                null,
                [
                    'expense_id' => $delId,
                    'expense_type' => $deletedExpense['type_name'] ?: 'Uncategorized',
                    'router_id' => $deletedExpense['router_id'],
                    'selected_user' => $deletedUser ?: null,
                    'payee' => $deletedExpense['employee'],
                    'amount' => $deletedExpense['amount'],
                    'expense_date' => $deletedExpense['expense_date'],
                    'period_type' => $deletedExpense['period_type'],
                    'receipt_name' => $deletedExpense['receipt_name'],
                    'remark' => $deletedExpense['remark'],
                ],
                null
            );
            flashMessage('success', 'Expense deleted.');
        }
    }
    redirect(BASE_URL . '/modules/expenses/');
}

// ── Session-persisted filters ─────────────────────────────────
const SESS_EXP = 'filter_expenses';

if (isset($_GET['_clear'])) {
    unset($_SESSION[SESS_EXP]);
    redirect(BASE_URL . '/modules/expenses/');
}
if (isset($_GET['_filter'])) {
    $_SESSION[SESS_EXP] = [
        'q'           => trim($_GET['q']           ?? ''),
        'type_id'     => (int)($_GET['type_id']    ?? 0),
        'period_type' => $_GET['period_type']       ?? '',
        'date_from'   => $_GET['date_from']         ?? '',
        'date_to'     => $_GET['date_to']           ?? '',
        'recorded_by' => (int)($_GET['recorded_by'] ?? 0),
    ];
    redirect(BASE_URL . '/modules/expenses/');
}

$f            = $_SESSION[SESS_EXP] ?? [];
$search       = $f['q']            ?? '';
$filterType   = (int)($f['type_id']    ?? 0);
$filterPer    = $f['period_type']  ?? '';
$dateFrom     = ($f['date_from']   ?? '') ?: date('Y-m-01');
$dateTo       = ($f['date_to']     ?? '') ?: date('Y-m-d');
$filterUserId = (int)($f['recorded_by'] ?? 0);
$page         = max(1, (int)($_GET['page'] ?? 1));

$hasFilter = $search !== '' || $filterType || $filterPer !== '' || $filterUserId
          || ($f['date_from'] ?? '') !== '' || ($f['date_to'] ?? '') !== '';

$isPrint = isset($_GET['print']);
$canPrintSensitive = canPrintSensitiveRecords();

if ($isPrint && !$canPrintSensitive) {
    forbidden();
}

session_write_close();

$expenseTypes  = db()->query("SELECT * FROM expense_types WHERE is_active = 1 ORDER BY name")->fetchAll();
$recorderUsers = db()->query("
    SELECT user_id, firstname, lastname, role
    FROM users
    WHERE role IN ('cashier','admin','superadmin') AND is_active = 1
    ORDER BY firstname, lastname
")->fetchAll();
$currency      = getSetting('currency_symbol', '₱');
$routerId      = (int)(selectedRouterId() ?: 0);

// ── Build query ───────────────────────────────────────────────
$where  = ['DATE(e.expense_date) BETWEEN ? AND ?', 'e.router_id = ?'];
$params = [$dateFrom, $dateTo, $routerId];

if ($search !== '') {
    $where[]  = '(e.employee LIKE ? OR e.remark LIKE ? OR et.name LIKE ? OR eu.firstname LIKE ? OR eu.lastname LIKE ? OR CONCAT(eu.firstname, " ", eu.lastname) LIKE ?)';
    $like     = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($filterType) {
    $where[]  = 'e.expense_type_id = ?';
    $params[] = $filterType;
}
if ($filterPer !== '') {
    $where[]  = 'e.period_type = ?';
    $params[] = $filterPer;
}
if ($filterUserId) {
    $where[]  = 'e.user_id = ?';
    $params[] = $filterUserId;
}

$whereStr = implode(' AND ', $where);

$cntStmt = db()->prepare("
    SELECT COUNT(*) FROM expenses e
    LEFT JOIN expense_types et ON et.type_id = e.expense_type_id
    LEFT JOIN users eu ON eu.user_id = e.expense_user_id
    WHERE {$whereStr}
");
$cntStmt->execute($params);
$total = (int)$cntStmt->fetchColumn();

$pag = paginate($total, $page);

$rows = db()->prepare("
    SELECT e.*,
           et.name AS type_name,
           u.firstname AS rec_first, u.lastname AS rec_last,
           eu.firstname AS exp_user_first, eu.lastname AS exp_user_last
    FROM expenses e
    LEFT JOIN expense_types et ON et.type_id = e.expense_type_id
    LEFT JOIN users u ON u.user_id = e.user_id
    LEFT JOIN users eu ON eu.user_id = e.expense_user_id
    WHERE {$whereStr}
    ORDER BY e.expense_date DESC, e.expense_id DESC
    LIMIT {$pag['per_page']} OFFSET {$pag['offset']}
");
$rows->execute($params);
$expenses = $rows->fetchAll();

// Total amount for current filter
$sumStmt = db()->prepare("
    SELECT COALESCE(SUM(e.amount), 0) FROM expenses e
    LEFT JOIN expense_types et ON et.type_id = e.expense_type_id
    LEFT JOIN users eu ON eu.user_id = e.expense_user_id
    WHERE {$whereStr}
");
$sumStmt->execute($params);
$totalAmount = (float)$sumStmt->fetchColumn();

// Breakdown by expense type (mirrors method breakdown in payments)
$typeBreakdown = db()->prepare("
    SELECT COALESCE(et.name, 'Uncategorized') AS name, COUNT(*) AS cnt, COALESCE(SUM(e.amount), 0) AS total
    FROM expenses e
    LEFT JOIN expense_types et ON et.type_id = e.expense_type_id
    LEFT JOIN users eu ON eu.user_id = e.expense_user_id
    WHERE {$whereStr}
    GROUP BY e.expense_type_id, et.name
    ORDER BY total DESC
    LIMIT 5
");
$typeBreakdown->execute($params);
$typeBreakdown = $typeBreakdown->fetchAll();

// ── Print view — standalone A4 page ──────────────────────
if ($isPrint):
    $companyName = getSetting('company_name', defined('APP_NAME') ? APP_NAME : 'NetManager');
    $companyLogo = getSetting('company_logo', '');
    $currSym     = getSetting('currency_symbol', '₱');
    $printedAt   = date('F d, Y  h:i A');
    $scopeLabel  = $hasFilter ? 'Filtered Results' : 'Period: ' . date('F d, Y', strtotime($dateFrom)) . ' – ' . date('F d, Y', strtotime($dateTo));

    $filterParts = [];
    if ($search)    $filterParts[] = 'Search: "' . htmlspecialchars($search) . '"';
    if ($filterType){
        $tn = db()->prepare("SELECT name FROM expense_types WHERE type_id = ?");
        $tn->execute([$filterType]);
        $filterParts[] = 'Type: ' . htmlspecialchars($tn->fetchColumn() ?: $filterType);
    }
    if ($filterPer)    $filterParts[] = 'Period: ' . ucfirst(htmlspecialchars($filterPer));
    if ($dateFrom)     $filterParts[] = 'From: '   . htmlspecialchars(date('M d, Y', strtotime($dateFrom)));
    if ($dateTo)       $filterParts[] = 'To: '     . htmlspecialchars(date('M d, Y', strtotime($dateTo)));
    if ($filterUserId) {
        foreach ($recorderUsers as $ru) {
            if ((int)$ru['user_id'] === $filterUserId) {
                $filterParts[] = 'Recorded By: ' . htmlspecialchars($ru['firstname'] . ' ' . $ru['lastname']);
                break;
            }
        }
    }

    // Fetch ALL records for print (no pagination)
    $printRows = db()->prepare("
        SELECT e.*, et.name AS type_name,
               u.firstname AS rec_first, u.lastname AS rec_last,
               eu.firstname AS exp_user_first, eu.lastname AS exp_user_last
        FROM expenses e
        LEFT JOIN expense_types et ON et.type_id = e.expense_type_id
        LEFT JOIN users u ON u.user_id = e.user_id
        LEFT JOIN users eu ON eu.user_id = e.expense_user_id
        WHERE {$whereStr}
        ORDER BY e.expense_date ASC, e.expense_id ASC
    ");
    $printRows->execute($params);
    $allExpenses = $printRows->fetchAll();

    $perPage    = 20;
    $chunks     = array_chunk($allExpenses, $perPage);
    $totalPages = max(1, count($chunks));
    if (empty($chunks)) $chunks = [[]];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Expense Report — <?= htmlspecialchars($companyName) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Segoe UI',Arial,Helvetica,sans-serif;font-size:9.5pt;color:#1a1a2e;background:#fff;}
@page{size:A4 landscape;margin:10mm 14mm;}

.page-header{
    display:flex;justify-content:space-between;align-items:stretch;
    background:#7f1d1d;color:#fff;
    border-radius:6px;overflow:hidden;margin-bottom:11px;
}
.ph-left{padding:12px 18px;flex:1;display:flex;align-items:center;gap:12px;}
.ph-left .logo-wrap img{max-height:52px;max-width:80px;object-fit:contain;filter:brightness(0) invert(1);}
.ph-left .company{font-size:15pt;font-weight:700;letter-spacing:.02em;line-height:1.2;}
.ph-left .tagline{font-size:8pt;opacity:.75;margin-top:2px;}
.ph-center{padding:12px 18px;text-align:center;border-left:1px solid rgba(255,255,255,.15);border-right:1px solid rgba(255,255,255,.15);}
.ph-center .report-title{font-size:13pt;font-weight:700;letter-spacing:.05em;text-transform:uppercase;}
.ph-center .scope-lbl{font-size:8pt;opacity:.8;margin-top:3px;}
.ph-right{padding:12px 18px;text-align:right;font-size:8pt;opacity:.85;min-width:130px;}
.ph-right strong{display:block;font-size:9pt;opacity:1;margin-bottom:2px;}

.info-row{
    display:flex;align-items:center;gap:0;
    background:#fff5f5;border:1px solid #fecaca;border-radius:5px;
    margin-bottom:10px;overflow:hidden;font-size:8pt;
}
.info-cell{padding:6px 14px;border-right:1px solid #fecaca;}
.info-cell:last-child{border-right:none;}
.info-cell .ic-lbl{color:#991b1b;text-transform:uppercase;font-size:7pt;letter-spacing:.06em;margin-bottom:1px;}
.info-cell .ic-val{font-weight:700;color:#7f1d1d;font-size:9.5pt;}
.info-cell .ic-val.red{color:#dc2626;}
.info-cell.type-cell{text-align:center;}
.info-cell.type-cell .ic-val{font-size:8.5pt;}
.info-cell.type-cell .ic-cnt{color:#888;font-size:7pt;}
.spacer{flex:1;}
.filter-tag{
    display:inline-block;background:#fee2e2;border:1px solid #fca5a5;
    border-radius:3px;padding:1px 6px;font-size:7.5pt;color:#7f1d1d;margin-right:4px;
}

table{width:100%;border-collapse:collapse;font-size:8.5pt;}
thead tr{background:#7f1d1d;color:#fff;}
thead th{
    padding:6px 8px;text-align:left;font-weight:600;
    font-size:7.8pt;letter-spacing:.04em;text-transform:uppercase;
    border-right:1px solid rgba(255,255,255,.12);
}
thead th:last-child{border-right:none;}
thead th.right{text-align:right;}
tbody tr:nth-child(even){background:#fff5f5;}
tbody td{padding:4.5px 8px;border-bottom:1px solid #fee2e2;vertical-align:middle;}
tbody td.right{text-align:right;}
tfoot td{padding:6px 8px;background:#fee2e2;border-top:2px solid #7f1d1d;font-weight:700;}
tfoot td.right{text-align:right;}

.mono{font-family:'Courier New',monospace;}
.fw7{font-weight:700;}
.muted{color:#6b7a99;}
.red{color:#dc2626;}
.seq{color:#94a3b8;font-size:7.5pt;}

.page-footer{
    margin-top:8px;padding-top:5px;
    border-top:1px solid #fecaca;
    display:flex;justify-content:space-between;
    font-size:7.5pt;color:#94a3b8;
}
.cont-header{
    background:#7f1d1d;color:#fff;border-radius:5px;
    padding:7px 14px;font-size:9pt;margin-bottom:8px;
    display:flex;justify-content:space-between;align-items:center;
}
.cont-header strong{font-size:10pt;}
tfoot .grand-row td{
    background:#7f1d1d !important;color:#fff !important;
    border-top:3px solid #450a0a;font-size:8pt;
    -webkit-print-color-adjust:exact;print-color-adjust:exact;
}
tfoot .grand-row td.red{color:#fca5a5 !important;font-size:10pt;}
</style>
</head>
<body>

<div class="page-header">
    <div class="ph-left">
        <?php if ($companyLogo): ?>
        <div class="logo-wrap"><img src="<?= e($companyLogo) ?>" alt="Logo"></div>
        <?php endif; ?>
        <div>
            <div class="company"><?= htmlspecialchars($companyName) ?></div>
            <div class="tagline">Internet Service Provider</div>
        </div>
    </div>
    <div class="ph-center">
        <div class="report-title">Expense Report</div>
        <div class="scope-lbl"><?= $scopeLabel ?></div>
    </div>
    <div class="ph-right">
        <strong>Printed</strong>
        <?= $printedAt ?>
    </div>
</div>

<div class="info-row">
    <div class="info-cell">
        <div class="ic-lbl">Total Expenses</div>
        <div class="ic-val red"><?= $currSym ?><?= number_format($totalAmount, 2) ?></div>
    </div>
    <div class="info-cell">
        <div class="ic-lbl">Total Records</div>
        <div class="ic-val"><?= number_format($total) ?></div>
    </div>
    <?php if (!empty($typeBreakdown)): ?>
    <div class="info-cell" style="border-right:2px solid #fca5a5;"></div>
    <?php foreach ($typeBreakdown as $tb): ?>
    <div class="info-cell type-cell">
        <div class="ic-lbl"><?= htmlspecialchars(strtoupper($tb['name'])) ?></div>
        <div class="ic-val red"><?= $currSym ?><?= number_format((float)$tb['total'], 2) ?></div>
        <div class="ic-cnt"><?= number_format($tb['cnt']) ?> item<?= $tb['cnt'] != 1 ? 's' : '' ?></div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
    <div class="spacer"></div>
    <?php if (!empty($filterParts)): ?>
    <div class="info-cell" style="border-right:none;">
        <div class="ic-lbl">Active Filters</div>
        <div style="margin-top:2px;">
            <?php foreach ($filterParts as $fp): ?>
            <span class="filter-tag"><?= $fp ?></span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php foreach ($chunks as $pgIdx => $chunk):
    $startIdx      = $pgIdx * $perPage;
    $pageSubtotal  = array_sum(array_column($chunk, 'amount'));
?>

<?php if ($pgIdx > 0): ?>
<div style="page-break-before:always;"></div>
<div class="cont-header">
    <span><strong><?= htmlspecialchars($companyName) ?></strong> &mdash; Expense Report (continued)</span>
    <span>Page <?= $pgIdx + 1 ?> of <?= $totalPages ?></span>
</div>
<?php endif; ?>

<table>
    <thead>
        <tr>
            <th style="width:28px;">#</th>
            <th style="width:90px;">Date</th>
            <th>Type</th>
            <th>Employee / Payee</th>
            <th>Period</th>
            <th>Remark</th>
            <th class="right" style="width:90px;">Amount</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($chunk)): ?>
        <tr><td colspan="7" style="text-align:center;padding:24px;color:#94a3b8;">No records found.</td></tr>
    <?php else: ?>
        <?php foreach ($chunk as $i => $exp): ?>
        <tr>
            <td class="seq"><?= $startIdx + $i + 1 ?></td>
            <td class="mono muted" style="font-size:8pt;"><?= formatDate($exp['expense_date'], 'M d, Y') ?></td>
            <td><?= e($exp['type_name'] ?? '—') ?></td>
            <?php $printUserName = trim(($exp['exp_user_first'] ?? '') . ' ' . ($exp['exp_user_last'] ?? '')); ?>
            <td class="fw7"><?= e($printUserName ?: ($exp['employee'] ?: '—')) ?></td>
            <td><?= ucfirst(e($exp['period_type'])) ?></td>
            <td class="muted" style="font-size:8pt;max-width:180px;"><?= e($exp['remark'] ?: '—') ?></td>
            <td class="right fw7 red"><?= $currSym ?><?= number_format((float)$exp['amount'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="6" style="color:#7f1d1d;font-size:8pt;letter-spacing:.04em;">
                PAGE <?= $pgIdx + 1 ?> SUBTOTAL
            </td>
            <td class="right red"><?= $currSym ?><?= number_format($pageSubtotal, 2) ?></td>
        </tr>
        <?php if ($pgIdx === $totalPages - 1 && !empty($allExpenses)): ?>
        <tr class="grand-row">
            <td colspan="6" style="letter-spacing:.04em;">GRAND TOTAL</td>
            <td class="right red"><?= $currSym ?><?= number_format($totalAmount, 2) ?></td>
        </tr>
        <?php endif; ?>
    </tfoot>
</table>

<div class="page-footer">
    <span><?= htmlspecialchars($companyName) ?> &mdash; For Internal Use Only &mdash; Confidential</span>
    <span>Page <?= $pgIdx + 1 ?> of <?= $totalPages ?></span>
</div>

<?php endforeach; ?>

<script>window.onload = function(){ window.print(); };</script>
</body>
</html>
<?php
    exit;
endif;

$pageTitle   = 'Expenses';
$breadcrumbs = [['label' => 'Expenses']];
include BASE_PATH . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">Expenses</h4>
        <p class="text-muted small mb-0">
            <?= $hasFilter ? 'Filtered results' : date('F d, Y', strtotime($dateFrom)) . ' – ' . date('F d, Y', strtotime($dateTo)) ?>
            &nbsp;·&nbsp; <?= number_format($total) ?> record<?= $total !== 1 ? 's' : '' ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/expenses/audit" class="btn btn-outline-primary">
            <i class="bi bi-clipboard-check me-1"></i>Audit
        </a>
        <?php if ($canPrintSensitive): ?>
        <a href="?print=1" target="_blank" class="btn btn-outline-secondary">
            <i class="bi bi-printer me-1"></i>Print
        </a>
        <?php endif; ?>
        <?php if (canModifyRecords()): ?>
        <a href="<?= BASE_URL ?>/modules/expenses/add" class="btn btn-danger">
            <i class="bi bi-plus-lg me-1"></i>New Expense
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- ── Totals strip ─────────────────────────────────────────── -->
<div class="card border-0 mb-3 bg-danger-subtle">
    <div class="card-body py-2 px-3">
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-receipt-cutoff text-danger fs-5"></i>
                <div>
                    <div class="text-muted text-label-xs">
                        <?= $hasFilter ? 'FILTERED TOTAL' : 'PERIOD EXPENSES' ?>
                    </div>
                    <div class="fw-bold fs-5 text-danger lh-1"><?= formatMoney($totalAmount) ?></div>
                </div>
            </div>
            <?php if (!empty($typeBreakdown)): ?>
            <div class="vr d-none d-sm-block opacity-25"></div>
            <?php foreach ($typeBreakdown as $tb): ?>
            <div class="text-center">
                <div class="text-muted text-label-xs"><?= e(strtoupper($tb['name'])) ?></div>
                <div class="fw-semibold small text-danger"><?= formatMoney($tb['total']) ?></div>
                <div class="text-muted text-label-xs"><?= $tb['cnt'] ?> item<?= $tb['cnt'] != 1 ? 's' : '' ?></div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Filters ───────────────────────────────────────────────── -->
<div class="filter-bar mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="_filter" value="1">
        <div class="col-sm-6 col-lg-3">
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-search text-muted"></i></span>
                <input type="text" name="q" class="form-control form-control-sm"
                       placeholder="User, employee, remark, type…" value="<?= e($search) ?>">
            </div>
        </div>
        <div class="col-sm-6 col-lg-2">
            <select name="type_id" class="form-select form-select-sm">
                <option value="">All Types</option>
                <?php foreach ($expenseTypes as $et): ?>
                <option value="<?= $et['type_id'] ?>" <?= $filterType === (int)$et['type_id'] ? 'selected' : '' ?>>
                    <?= e($et['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-6 col-lg-2">
            <select name="period_type" class="form-select form-select-sm">
                <option value="">All Periods</option>
                <option value="daily"   <?= $filterPer === 'daily'   ? 'selected' : '' ?>>Daily</option>
                <option value="monthly" <?= $filterPer === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                <option value="yearly"  <?= $filterPer === 'yearly'  ? 'selected' : '' ?>>Yearly</option>
            </select>
        </div>
        <div class="col-sm-6 col-lg-2">
            <select name="recorded_by" class="form-select form-select-sm">
                <option value="">All Recorders</option>
                <?php foreach ($recorderUsers as $ru): ?>
                <option value="<?= $ru['user_id'] ?>" <?= $filterUserId === (int)$ru['user_id'] ? 'selected' : '' ?>>
                    <?= e($ru['firstname'] . ' ' . $ru['lastname']) ?>
                    <span class="text-muted">(<?= ucfirst($ru['role']) ?>)</span>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-6 col-lg-auto">
            <input type="date" name="date_from" class="form-control form-control-sm"
                   value="<?= e($dateFrom) ?>" title="From date">
        </div>
        <div class="col-sm-6 col-lg-auto">
            <input type="date" name="date_to" class="form-control form-control-sm"
                   value="<?= e($dateTo) ?>" title="To date">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
            <a href="?_clear=1" class="btn btn-sm btn-outline-secondary" title="Clear"><i class="bi bi-x-lg"></i></a>
        </div>
    </form>
</div>

<!-- ── Table ─────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Date</th>
                        <th>Type</th>
                        <th>User / Employee</th>
                        <th>Period</th>
                        <th class="text-end">Amount</th>
                        <th>Remark</th>
                        <th>Receipt</th>
                        <th>Recorded By</th>
                        <th class="pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($expenses)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-5 text-muted">
                            <i class="bi bi-receipt-cutoff d-block fs-2 mb-2"></i>No expenses found for this period.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($expenses as $exp): ?>
                    <?php
                        $periodColors = ['daily' => 'success', 'monthly' => 'primary', 'yearly' => 'warning'];
                        $pc = $periodColors[$exp['period_type']] ?? 'secondary';
                    ?>
                    <tr>
                        <td class="ps-3 font-monospace small text-nowrap"><?= formatDate($exp['expense_date'], 'M d, Y') ?></td>
                        <td>
                            <?php if ($exp['type_name']): ?>
                            <span class="badge bg-secondary bg-opacity-10 text-secondary border small"><?= e($exp['type_name']) ?></span>
                            <?php else: ?>
                            <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?php
                                $expenseUserName = trim(($exp['exp_user_first'] ?? '') . ' ' . ($exp['exp_user_last'] ?? ''));
                                $employeeLabel = $expenseUserName ?: ($exp['employee'] ?? '');
                            ?>
                            <?= $employeeLabel ? e($employeeLabel) : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td>
                            <span class="badge bg-<?= $pc ?>-subtle text-<?= $pc ?> border border-<?= $pc ?>-subtle">
                                <?= ucfirst($exp['period_type']) ?>
                            </span>
                        </td>
                        <td class="text-end fw-semibold text-danger small"><?= formatMoney($exp['amount']) ?></td>
                        <td class="small text-muted" style="max-width:180px;">
                            <span class="d-inline-block text-truncate" style="max-width:175px;" title="<?= e($exp['remark'] ?? '') ?>">
                                <?= $exp['remark'] ? e($exp['remark']) : '—' ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <?php if ($exp['receipt'] && $canPrintSensitive): ?>
                            <button type="button"
                                    class="btn btn-sm btn-outline-secondary btn-view-receipt"
                                    data-id="<?= $exp['expense_id'] ?>"
                                    data-name="<?= e($exp['receipt_name'] ?? 'receipt') ?>"
                                    data-mime="<?= e($exp['receipt_mime'] ?? 'image/jpeg') ?>"
                                    title="View receipt">
                                <i class="bi bi-image"></i>
                            </button>
                            <?php else: ?>
                            <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted">
                            <?= $exp['rec_first'] ? e($exp['rec_first'] . ' ' . $exp['rec_last']) : '—' ?>
                        </td>
                        <td class="pe-3">
                            <div class="d-flex gap-1">
                                <?php if (canModifyRecords()): ?>
                                <a href="<?= BASE_URL ?>/modules/expenses/edit?id=<?= $exp['expense_id'] ?>"
                                   class="btn btn-sm btn-outline-primary" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (hasMinRole(ROLE_ADMIN)): ?>
                                <button type="button"
                                        class="btn btn-sm btn-outline-danger btn-delete-expense"
                                        title="Delete"
                                        data-id="<?= $exp['expense_id'] ?>"
                                        data-desc="<?= e(htmlspecialchars(($exp['type_name'] ?? 'Expense') . ' — ' . formatMoney($exp['amount']), ENT_QUOTES)) ?>">
                                    <i class="bi bi-trash3"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($pag['total_pages'] > 1): ?>
    <div class="card-footer bg-transparent border-top d-flex align-items-center justify-content-between">
        <small class="text-muted">Showing <?= number_format($pag['offset'] + 1) ?>–<?= number_format(min($pag['offset'] + $pag['per_page'], $total)) ?> of <?= number_format($total) ?></small>
        <?= renderPagination($pag) ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($canPrintSensitive): ?>
<!-- ══ Full-screen receipt viewer modal ═══════════════════════════ -->
<div id="receiptModal"
     style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.93);"
     class="flex-column">
    <!-- Top bar -->
    <div class="d-flex align-items-center justify-content-between px-3 py-2"
         style="background:rgba(0,0,0,0.5); min-height:48px; flex-shrink:0;">
        <span id="rmName" class="text-white fw-medium small text-truncate" style="max-width:70vw;"></span>
        <div class="d-flex align-items-center gap-3">
            <span id="rmCounter" class="text-white-50 small"></span>
            <button type="button" id="rmClose" class="btn-close btn-close-white" aria-label="Close"></button>
        </div>
    </div>
    <!-- Viewer area -->
    <div class="position-relative d-flex align-items-center justify-content-center"
         style="flex:1; overflow:hidden;">
        <button id="rmPrev"
                class="btn btn-dark bg-black bg-opacity-50 border-0 position-absolute start-0 ms-2 rounded-circle d-flex align-items-center justify-content-center"
                style="width:44px; height:44px; z-index:2; top:50%; transform:translateY(-50%);">
            <i class="bi bi-chevron-left text-white fs-5"></i>
        </button>
        <img id="rmImg" src="" alt=""
             style="max-width:100%; max-height:100%; object-fit:contain; display:none; user-select:none;">
        <iframe id="rmPdf" src="" style="width:100%; height:100%; border:0; display:none;"></iframe>
        <button id="rmNext"
                class="btn btn-dark bg-black bg-opacity-50 border-0 position-absolute end-0 me-2 rounded-circle d-flex align-items-center justify-content-center"
                style="width:44px; height:44px; z-index:2; top:50%; transform:translateY(-50%);">
            <i class="bi bi-chevron-right text-white fs-5"></i>
        </button>
    </div>
</div>
<?php endif; ?>

<?php if (hasMinRole(ROLE_ADMIN)): ?>
<!-- Hidden delete form (submitted by JS) -->
<form method="POST" id="deleteExpenseForm" style="display:none;">
    <?= csrfField() ?>
    <input type="hidden" name="delete_expense" value="1">
    <input type="hidden" name="expense_id" id="deleteExpenseId">
</form>
<?php endif; ?>

<?php
$receiptBaseUrl = BASE_URL . '/modules/expenses/receipt?id=';
$extraScripts = '<script>const RECEIPT_BASE="' . $receiptBaseUrl . '";</script>' . <<<'JS'
<script>
(function () {
    // ── Receipt viewer ────────────────────────────────────────────
    const modal   = document.getElementById('receiptModal');
    if (!modal) return;

    const rmName    = document.getElementById('rmName');
    const rmCounter = document.getElementById('rmCounter');
    const rmImg     = document.getElementById('rmImg');
    const rmPdf     = document.getElementById('rmPdf');
    const rmPrev    = document.getElementById('rmPrev');
    const rmNext    = document.getElementById('rmNext');
    const rmClose   = document.getElementById('rmClose');

    const receipts = [];
    document.querySelectorAll('.btn-view-receipt').forEach(btn => {
        receipts.push({ id: btn.dataset.id, name: btn.dataset.name, mime: btn.dataset.mime });
        btn.addEventListener('click', () => open(receipts.length - 1));
    });

    let cur = 0;

    function open(idx) {
        cur = idx;
        show();
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function show() {
        const r   = receipts[cur];
        const url = RECEIPT_BASE + r.id;
        rmName.textContent    = r.name;
        rmCounter.textContent = receipts.length > 1 ? (cur + 1) + ' / ' + receipts.length : '';
        rmPrev.style.visibility = cur > 0 ? 'visible' : 'hidden';
        rmNext.style.visibility = cur < receipts.length - 1 ? 'visible' : 'hidden';

        if (r.mime.startsWith('image/')) {
            rmImg.src = url; rmImg.style.display = '';
            rmPdf.style.display = 'none'; rmPdf.src = '';
        } else {
            rmPdf.src = url; rmPdf.style.display = '';
            rmImg.style.display = 'none'; rmImg.src = '';
        }
    }

    function close() {
        modal.style.display = 'none';
        document.body.style.overflow = '';
        rmImg.src = ''; rmPdf.src = '';
    }

    rmClose.addEventListener('click', close);
    modal.addEventListener('click', e => { if (e.target === modal) close(); });
    rmPrev.addEventListener('click', () => { if (cur > 0) { cur--; show(); } });
    rmNext.addEventListener('click', () => { if (cur < receipts.length - 1) { cur++; show(); } });
    document.addEventListener('keydown', e => {
        if (modal.style.display === 'none') return;
        if (e.key === 'Escape')      close();
        if (e.key === 'ArrowLeft')  rmPrev.click();
        if (e.key === 'ArrowRight') rmNext.click();
    });
})();
</script>
JS;

if (hasMinRole(ROLE_ADMIN)) {
    $extraScripts .= <<<'JS'
<script>
document.querySelectorAll('.btn-delete-expense').forEach(btn => {
    btn.addEventListener('click', function () {
        const id   = this.dataset.id;
        const desc = this.dataset.desc;
        Swal.fire({
            title: 'Delete Expense?',
            html: `<div class="text-start">
                <div class="mb-3 p-2 bg-light rounded small">
                    <div class="fw-medium">${desc}</div>
                </div>
                <div class="alert alert-danger py-2 small mb-0">
                    This action <strong>cannot be undone</strong>.
                </div>
            </div>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: '<i class="bi bi-trash3 me-1"></i>Delete',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#dc3545',
            reverseButtons: true,
        }).then(result => {
            if (!result.isConfirmed) return;
            document.getElementById('deleteExpenseId').value = id;
            document.getElementById('deleteExpenseForm').submit();
        });
    });
});
</script>
JS;
}
?>

<?php include BASE_PATH . '/includes/footer.php'; ?>
