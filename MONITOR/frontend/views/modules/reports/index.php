<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireRole(ROLE_CASHIER, ROLE_ADMIN, ROLE_SUPERADMIN);

const SESS_RPT = 'filter_reports';

$isCashierOnly = currentRole() === ROLE_CASHIER;

// Clamp a date range to a max of 7 days (cashier limit).
// Returns [$from, $to] with $from adjusted if needed.
function cashierClampRange(string $from, string $to): array {
    $fromTs = strtotime($from);
    $toTs   = strtotime($to);
    if ($fromTs === false || $toTs === false) return [$from, $to];
    if ($toTs < $fromTs) $fromTs = $toTs;
    if (($toTs - $fromTs) > 6 * 86400) {
        $fromTs = $toTs - 6 * 86400;
    }
    return [date('Y-m-d', $fromTs), date('Y-m-d', $toTs)];
}

if (isset($_GET['_clear'])) {
    unset($_SESSION[SESS_RPT]);
    redirect(BASE_URL . '/modules/reports/');
}
if (isset($_GET['_filter'])) {
    $saveFrom = $_GET['date_from'] ?? '';
    $saveTo   = $_GET['date_to']   ?? '';
    if ($isCashierOnly && $saveFrom !== '' && $saveTo !== '') {
        [$saveFrom, $saveTo] = cashierClampRange($saveFrom, $saveTo);
    }
    $_SESSION[SESS_RPT] = ['date_from' => $saveFrom, 'date_to' => $saveTo];
    redirect(BASE_URL . '/modules/reports/');
}

$f = $_SESSION[SESS_RPT] ?? [];
if ($isCashierOnly) {
    // Default: last 7 days (today − 6 → today)
    $dateTo   = ($f['date_to']   ?? '') ?: appToday();
    $dateFrom = ($f['date_from'] ?? '') ?: date('Y-m-d', strtotime('-6 days'));
    [$dateFrom, $dateTo] = cashierClampRange($dateFrom, $dateTo);
} else {
    $dateFrom = ($f['date_from'] ?? '') ?: appToday('Y-m-01');
    $dateTo   = ($f['date_to']   ?? '') ?: appToday();
}
if (isset($_GET['print'])) {
    $dateFrom = $_GET['date_from'] ?? $dateFrom;
    $dateTo   = $_GET['date_to']   ?? $dateTo;
    if ($isCashierOnly) {
        [$dateFrom, $dateTo] = cashierClampRange($dateFrom, $dateTo);
    }
    requireCanPrintSensitiveRecords();
}
$canPrintSensitive = canPrintSensitiveRecords();

// Router scope
$routerId   = selectedRouterId();
$routerName = selectedRouterName();
$rJoin      = $routerId ? " JOIN subscribers s ON s.subscriber_id = py.subscriber_id" : "";
$rWhere     = $routerId ? " AND s.router_id = ?" : "";
$rOwhere    = $routerId ? " AND s.router_id = ?" : "";
$rP         = $routerId ? [$routerId] : [];
$eWhere     = $routerId ? " AND e.router_id = ?" : "";
$eP         = $routerId ? [$routerId] : [];
$expensePeriod = reportPeriodFromDateRange($dateFrom, $dateTo);
$ePeriodWhere  = expensePeriodTypeSql($expensePeriod, 'e');

// ── Expense totals (for KPI cards and chart) ──────────────
$hasExpAccess = in_array(currentRole(), MODULE_ACCESS['expenses'] ?? []);
$grandTotal   = 0.0;
$expCount     = 0;

if ($hasExpAccess) {
    $expTotStmt = db()->prepare("
        SELECT COALESCE(SUM(e.amount),0) AS total, COUNT(*) AS cnt
        FROM expenses e
        WHERE DATE(e.expense_date) BETWEEN ? AND ?
        {$ePeriodWhere}
        {$eWhere}
    ");
    $expTotStmt->execute(array_merge([$dateFrom, $dateTo], $eP));
    $expTot     = $expTotStmt->fetch();
    $grandTotal = (float)$expTot['total'];
    $expCount   = (int)$expTot['cnt'];
}

// ── Revenue stats ─────────────────────────────────────────
$revStmt = db()->prepare("
    SELECT COUNT(*) AS cnt,
           COALESCE(SUM(py.amount), 0) AS total,
           COALESCE(AVG(py.amount), 0) AS avg_amount,
           COALESCE(MAX(py.amount), 0) AS max_amount
    FROM payments py{$rJoin}
    WHERE py.status = 'paid' AND DATE(py.payment_date) BETWEEN ? AND ?
    {$rWhere}
");
$revStmt->execute(array_merge([$dateFrom, $dateTo], $rP));
$revStats     = $revStmt->fetch();
$totalRevenue = (float)$revStats['total'];
$payCount     = (int)$revStats['cnt'];
$avgPayment   = (float)$revStats['avg_amount'];
$maxPayment   = (float)$revStats['max_amount'];

// ── Revenue by method ─────────────────────────────────────
$byMethodStmt = db()->prepare("
    SELECT py.method, COUNT(*) AS cnt, SUM(py.amount) AS total
    FROM payments py{$rJoin}
    WHERE py.status = 'paid' AND DATE(py.payment_date) BETWEEN ? AND ?
    {$rWhere}
    GROUP BY py.method ORDER BY total DESC
");
$byMethodStmt->execute(array_merge([$dateFrom, $dateTo], $rP));
$byMethod = $byMethodStmt->fetchAll();

// ── Daily revenue chart ───────────────────────────────────
$dailyRevStmt = db()->prepare("
    SELECT DATE_FORMAT(py.payment_date,'%Y-%m-%d') AS day, SUM(py.amount) AS revenue
    FROM payments py{$rJoin}
    WHERE py.status = 'paid' AND DATE(py.payment_date) BETWEEN ? AND ?
    {$rWhere}
    GROUP BY day ORDER BY day ASC
");
$dailyRevStmt->execute(array_merge([$dateFrom, $dateTo], $rP));
$dailyRev = $dailyRevStmt->fetchAll();

// ── Daily expenses chart ──────────────────────────────────
$dailyExp = [];
if ($hasExpAccess) {
    $dailyExpStmt = db()->prepare("
        SELECT DATE_FORMAT(e.expense_date,'%Y-%m-%d') AS day, SUM(e.amount) AS expenses
        FROM expenses e
        WHERE DATE(e.expense_date) BETWEEN ? AND ?
        {$ePeriodWhere}
        {$eWhere}
        GROUP BY day ORDER BY day ASC
    ");
    $dailyExpStmt->execute(array_merge([$dateFrom, $dateTo], $eP));
    $dailyExp = $dailyExpStmt->fetchAll();
}

// ── Subscriber status breakdown ───────────────────────────
$subStatusStmt = db()->prepare("SELECT status, COUNT(*) AS cnt FROM subscribers s WHERE 1=1 {$rOwhere} GROUP BY status");
$subStatusStmt->execute($rP);
$subStatusRows = $subStatusStmt->fetchAll();
$subByStatus   = array_column($subStatusRows, 'cnt', 'status');
$totalSubs     = (int)array_sum(array_column($subStatusRows, 'cnt'));
$activeSubs    = (int)($subByStatus['active']    ?? 0);
$expiredCount  = (int)($subByStatus['expired']   ?? 0);
$suspSubs      = (int)($subByStatus['suspended'] ?? 0);
$pendSubs      = (int)($subByStatus['pending']   ?? 0);

// ── New subscribers in period ─────────────────────────────
$newSubsStmt = db()->prepare("
    SELECT COUNT(*) FROM subscribers s
    WHERE DATE(created_at) BETWEEN ? AND ? {$rOwhere}
");
$newSubsStmt->execute(array_merge([$dateFrom, $dateTo], $rP));
$newSubsCount = (int)$newSubsStmt->fetchColumn();

// ── Expected MRC & collection rate ───────────────────────
$mrcStmt = db()->prepare("
    SELECT COALESCE(SUM(p.amount), 0)
    FROM subscribers s LEFT JOIN plans p ON p.plan_id = s.plan_id
    WHERE s.status = 'active' {$rOwhere}
");
$mrcStmt->execute($rP);
$expectedMRC    = (float)$mrcStmt->fetchColumn();
$collectionRate = $expectedMRC > 0 ? min(999, round($totalRevenue / $expectedMRC * 100, 1)) : 0;

// ── Revenue by plan ───────────────────────────────────────
$byPlanStmt = db()->prepare("
    SELECT p.title, COUNT(py.payment_id) AS cnt, SUM(py.amount) AS total
    FROM payments py
    JOIN subscribers s ON s.subscriber_id = py.subscriber_id
    JOIN plans p ON p.plan_id = s.plan_id
    WHERE py.status = 'paid' AND DATE(py.payment_date) BETWEEN ? AND ?
    " . ($routerId ? "AND s.router_id = ?" : "") . "
    GROUP BY p.plan_id, p.title ORDER BY total DESC
");
$byPlanStmt->execute(array_merge([$dateFrom, $dateTo], $rP));
$byPlan = $byPlanStmt->fetchAll();


// ── Payments with notes (grouped by note text) ───────────
$notesStmt = db()->prepare("
    SELECT py.notes,
           COUNT(*)                        AS note_count,
           COUNT(DISTINCT py.subscriber_id) AS sub_count,
           COALESCE(SUM(py.amount), 0)     AS total_amount
    FROM payments py{$rJoin}
    WHERE py.status = 'paid'
      AND py.notes IS NOT NULL AND py.notes != ''
      AND DATE(py.payment_date) BETWEEN ? AND ?
    {$rWhere}
    GROUP BY py.notes
    ORDER BY total_amount DESC
");
$notesStmt->execute(array_merge([$dateFrom, $dateTo], $rP));
$paymentNotes = $notesStmt->fetchAll();

// ── Overdue accounts (with search/filter/pagination) ──────
if (isset($_GET['_od_clear'])) {
    unset($_SESSION['filter_overdue']);
    redirect(BASE_URL . '/modules/reports/');
}
if (isset($_GET['_od_filter'])) {
    $_SESSION['filter_overdue'] = [
        'q'        => trim($_GET['od_q']        ?? ''),
        'plan_id'  => (int)($_GET['od_plan_id'] ?? 0),
        'overdue'  => $_GET['od_overdue']        ?? '',
    ];
    redirect(BASE_URL . '/modules/reports/');
}
$odF       = $_SESSION['filter_overdue'] ?? [];
$odSearch  = $odF['q']       ?? '';
$odPlanId  = (int)($odF['plan_id'] ?? 0);
$odOverdue = $odF['overdue'] ?? '';
$odPage    = max(1, (int)($_GET['od_page'] ?? 1));
$odPerPage = 25;

$odToday  = appToday();
$odWhere  = ["s.status = 'expired'"];
$odParams = [];
if ($routerId) { $odWhere[] = 's.router_id = ?'; $odParams[] = $routerId; }
if ($odSearch !== '') {
    $odWhere[]  = '(s.firstname LIKE ? OR s.lastname LIKE ? OR s.account_number LIKE ? OR s.contact_number LIKE ?)';
    $odLike     = '%' . $odSearch . '%';
    $odParams   = array_merge($odParams, [$odLike, $odLike, $odLike, $odLike]);
}
if ($odPlanId) { $odWhere[] = 's.plan_id = ?'; $odParams[] = $odPlanId; }
if ($odOverdue === '30') {
    $odWhere[] = 's.subscription_end < ?';
    $odParams[] = date('Y-m-d', strtotime('-30 days'));
}
if ($odOverdue === '7') {
    $odWhere[] = 's.subscription_end BETWEEN ? AND ?';
    $odParams[] = date('Y-m-d', strtotime('-7 days'));
    $odParams[] = date('Y-m-d', strtotime('-1 day'));
}
if ($odOverdue === '8_30') {
    $odWhere[] = 's.subscription_end BETWEEN ? AND ?';
    $odParams[] = date('Y-m-d', strtotime('-30 days'));
    $odParams[] = date('Y-m-d', strtotime('-8 days'));
}
$odWhereStr = implode(' AND ', $odWhere);

$odTotalStmt = db()->prepare("SELECT COUNT(*) FROM subscribers s WHERE {$odWhereStr}");
$odTotalStmt->execute($odParams);
$odTotal      = (int)$odTotalStmt->fetchColumn();
$odTotalPages = max(1, (int)ceil($odTotal / $odPerPage));
$odPage       = min($odPage, $odTotalPages);
$odOffset     = ($odPage - 1) * $odPerPage;

$overdueStmt = db()->prepare("
    SELECT s.subscriber_id, s.account_number, s.firstname, s.lastname, s.contact_number,
           s.subscription_end, p.title AS plan_title, p.amount AS plan_amount,
           DATEDIFF(?, s.subscription_end) AS days_overdue
    FROM subscribers s LEFT JOIN plans p ON p.plan_id = s.plan_id
    WHERE {$odWhereStr}
    ORDER BY days_overdue DESC
    LIMIT {$odPerPage} OFFSET {$odOffset}
");
$overdueStmt->execute(array_merge([$odToday], $odParams));
$overdue = $overdueStmt->fetchAll();

$odPlans = db()->query("
    SELECT DISTINCT p.plan_id, p.title FROM plans p
    INNER JOIN subscribers s ON s.plan_id = p.plan_id
    WHERE s.status = 'expired'
    ORDER BY p.title
")->fetchAll();

// ── Company settings ──────────────────────────────────────
$companyName    = getSetting('company_name',    'ISP Company');
$companyAddress = getSetting('company_address', '');
$companyContact = getSetting('company_contact', '');
$companyEmail   = getSetting('company_email',   '');
$companyTin     = getSetting('company_tin',     '');
$companyLogo    = getSetting('company_logo',    '');
$currSym        = getSetting('currency_symbol', '₱');
$routerManager  = '';
if ($routerId) {
    $mgrStmt = db()->prepare("SELECT manager, address, municipality, province, region FROM routers WHERE router_id = ?");
    $mgrStmt->execute([$routerId]);
    $routerInfo = $mgrStmt->fetch() ?: [];
    $routerManager = trim((string)($routerInfo['manager'] ?? ''));
    $routerAddressParts = array_filter(array_map('trim', [
        (string)($routerInfo['address'] ?? ''),
        (string)($routerInfo['municipality'] ?? ''),
        (string)($routerInfo['province'] ?? ''),
        (string)($routerInfo['region'] ?? ''),
    ]));
    if ($routerAddressParts) {
        $companyAddress = implode(', ', $routerAddressParts);
    }
}

// ── Standalone print view ─────────────────────────────────
if (isset($_GET['print'])) {
    $netAmount  = $totalRevenue - $grandTotal;
    $cu         = currentUser();
    $preparedBy = strtoupper(trim(($cu['firstname'] ?? '') . ' ' . ($cu['lastname'] ?? '')));
    $prepRole   = ucfirst($cu['role'] ?? '');

    // Expense employee/payee list for the period
    $printExpStmt = db()->prepare("
        SELECT e.expense_date, et.name AS type_name, e.employee, e.amount, e.remark,
               u.firstname AS rec_first, u.lastname AS rec_last
        FROM expenses e
        LEFT JOIN expense_types et ON et.type_id = e.expense_type_id
        LEFT JOIN users u ON u.user_id = e.user_id
        WHERE DATE(e.expense_date) BETWEEN ? AND ?
        {$ePeriodWhere}
        {$eWhere}
        ORDER BY e.expense_date ASC, e.expense_id ASC
    ");
    $printExpStmt->execute(array_merge([$dateFrom, $dateTo], $eP));
    $printExpenses = $printExpStmt->fetchAll();

    // Subscriber payment list for the period
    $printPayStmt = db()->prepare("
        SELECT py.payment_date, py.or_number, py.amount, py.method, py.status,
               s.account_number, s.firstname, s.lastname,
               pt.name AS type_name,
               u.firstname AS cashier_first, u.lastname AS cashier_last
        FROM payments py
        JOIN subscribers s ON s.subscriber_id = py.subscriber_id
        LEFT JOIN payment_types pt ON pt.type_id = py.payment_type_id
        LEFT JOIN users u ON u.user_id = py.user_id
        WHERE py.status = 'paid'
          AND DATE(py.payment_date) BETWEEN ? AND ?
          " . ($routerId ? "AND s.router_id = ?" : "") . "
        ORDER BY py.payment_date ASC, py.payment_id ASC
    ");
    $printPayParams = $routerId ? [$dateFrom, $dateTo, $routerId] : [$dateFrom, $dateTo];
    $printPayStmt->execute($printPayParams);
    $printPayments = $printPayStmt->fetchAll();

    // Payments with notes for the period (grouped by note text)
    $printNotesStmt = db()->prepare("
        SELECT py.notes,
               COUNT(*)                        AS note_count,
               COUNT(DISTINCT py.subscriber_id) AS sub_count,
               COALESCE(SUM(py.amount), 0)     AS total_amount
        FROM payments py
        " . ($routerId ? "JOIN subscribers s ON s.subscriber_id = py.subscriber_id" : "") . "
        WHERE py.status = 'paid'
          AND py.notes IS NOT NULL AND py.notes != ''
          AND DATE(py.payment_date) BETWEEN ? AND ?
          " . ($routerId ? "AND s.router_id = ?" : "") . "
        GROUP BY py.notes
        ORDER BY total_amount DESC
    ");
    $printNotesStmt->execute($printPayParams);
    $printNotes = $printNotesStmt->fetchAll();
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Financial Report — <?= e($routerManager ?: $companyName) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Courier New',Courier,monospace;font-size:12px;color:#1a1a1a;background:#fff;line-height:1.4;padding:20px;counter-reset:print-page;}
.report{width:100%;max-width:780px;margin:0 auto;}
.header{text-align:center;margin-bottom:14px;padding-bottom:8px;border-bottom:2px solid #1a1a1a;}
.company-name{font-size:18px;font-weight:700;margin:0;line-height:1.2;}
.company-meta{font-size:10px;color:#555;margin:0;line-height:1.3;}
.report-title{font-size:15px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;margin:6px 0 0;}
.date-range{font-size:11px;color:#555;font-weight:700;margin:0;line-height:1.3;}
h3.section-title{font-size:12px;font-weight:700;text-transform:uppercase;background:#1a1a1a;color:#fff;padding:6px 10px;margin:18px 0 10px;}
h4.sub-title{font-size:11px;font-weight:700;color:#333;margin:12px 0 6px;text-transform:uppercase;letter-spacing:.04em;}
table{width:100%;border-collapse:collapse;margin-bottom:14px;font-size:11px;}
table th{background:#1a1a1a;color:#fff;padding:7px 8px;text-align:left;border:1px solid #333;font-weight:700;font-size:10px;text-transform:uppercase;letter-spacing:.03em;}
table td{padding:5px 8px;border:1px solid #ccc;vertical-align:middle;}
.text-right{text-align:right;}
.text-center{text-align:center;}
tr.subtotal td{background:#e8f4fd;font-weight:700;}
tr.grand td{background:#1a1a1a;color:#fff;font-weight:700;}
tr.grand td.income{color:#86efac;}
tr.grand td.expense{color:#fca5a5;}
tr.grand td.net-pos{color:#86efac;}
tr.grand td.net-neg{color:#fca5a5;}
.income{color:#16a34a;font-weight:700;}
.expense{color:#dc2626;font-weight:700;}
.net-pos{color:#1d4ed8;font-weight:700;}
.net-neg{color:#dc2626;font-weight:700;}
.summary-box{margin-top:18px;padding:10px 14px;background:#f8f8f8;border:1px solid #ccc;font-size:11px;line-height:1.7;}
.signatures{display:flex;justify-content:space-between;margin-top:48px;font-size:11px;}
.sig-block{width:45%;}
.sig-line{border-top:1px solid #1a1a1a;margin-top:36px;padding-top:4px;text-align:center;}
.print-footer{position:fixed;bottom:0;left:0;right:0;font-size:9px;color:#555;text-align:center;border-top:1px solid #ddd;padding-top:4px;counter-increment:print-page;}
.page-number:after{content:"Page " counter(print-page);}
@media print{
    @page{size:A4 portrait;margin:12mm 14mm;}
    body{padding:0 0 18px;}
    .no-print{display:none!important;}
}
</style>
</head>
<body onload="window.print()">
<div class="report">

    <div class="header">
        <?php if ($companyLogo): ?>
        <img src="<?= e($companyLogo) ?>" alt="" style="height:52px;object-fit:contain;display:block;margin:0 auto 4px;">
        <?php endif; ?>
        <div class="company-name"><?= e($companyName) ?></div>
        <?php if ($companyAddress): ?>
        <div class="company-meta"><?= nl2br(e($companyAddress)) ?></div>
        <?php endif; ?>
        <div class="report-title">Financial Report</div>
        <div class="date-range">
            <?= formatDate($dateFrom, 'F j, Y') ?>
            <?= $dateFrom !== $dateTo ? ' &ndash; ' . formatDate($dateTo, 'F j, Y') : '' ?>
        </div>
    </div>

    <!-- INCOME SECTION -->
    <h3 class="section-title">Income</h3>

    <?php if (!empty($byMethod)): ?>
    <h4 class="sub-title">Breakdown by Payment Method</h4>
    <table>
        <thead>
            <tr>
                <th>Payment Method</th>
                <th class="text-center">Transactions</th>
                <th class="text-right">Amount (<?= e($currSym) ?>)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($byMethod as $bm): ?>
            <tr>
                <td><?= e(ucfirst($bm['method'])) ?></td>
                <td class="text-center"><?= number_format($bm['cnt']) ?></td>
                <td class="text-right"><?= number_format((float)$bm['total'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="subtotal">
                <td colspan="2">Total Income</td>
                <td class="text-right income"><?= e($currSym) ?><?= number_format($totalRevenue, 2) ?></td>
            </tr>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- PAYMENT NOTES SECTION -->
    <?php if (!empty($printNotes)): ?>
    <h3 class="section-title">Payment Notes</h3>
    <table>
        <thead>
            <tr>
                <th>Note</th> 
                <th class="text-center">Subscribers</th>
                <th class="text-right">Amount (<?= e($currSym) ?>)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($printNotes as $i => $pn): ?>
            <tr <?= $i % 2 === 1 ? 'style="background:#f9f9f9;"' : '' ?>>
                <td><?= e($pn['notes']) ?></td> 
                <td class="text-center"><?= number_format($pn['sub_count']) ?></td>
                <td class="text-right"><?= number_format((float)$pn['total_amount'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="subtotal">
                <td><strong>Total</strong></td> 
                <td class="text-center"><strong><?= number_format(array_sum(array_column($printNotes, 'sub_count'))) ?></strong></td>
                <td class="text-right income"><strong><?= number_format(array_sum(array_column($printNotes, 'total_amount')), 2) ?></strong></td>
            </tr>
        </tbody>
    </table>
    <?php endif; ?>


    <!-- SUBSCRIBER PAYMENTS -->
    <h3 class="section-title">Subscriber Payments</h3>
    <table>
        <thead>
            <tr>
                <th>OR #</th>
                <th>Account #</th>
                <th>Subscriber</th>
                <th>Type</th>
                <th>Method</th>
                <th class="text-right">Amount (<?= e($currSym) ?>)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($printPayments)): ?>
            <tr><td colspan="6" class="text-center" style="color:#888;font-style:italic;">No payments for this period.</td></tr>
            <?php else: ?>
            <?php foreach ($printPayments as $i => $py): ?>
            <tr <?= $i % 2 === 1 ? 'style="background:#f9f9f9;"' : '' ?>>
                <td class="text-center"><?= e($py['or_number'] ?? '—') ?></td>
                <td><?= e($py['account_number']) ?></td>
                <td><?= e($py['firstname'] . ' ' . $py['lastname']) ?></td>
                <td><?= e($py['type_name'] ?? '—') ?></td>
                <td class="text-center"><?= e(strtoupper($py['method'] ?? '—')) ?></td>
                <td class="text-right"><?= number_format((float)$py['amount'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="subtotal">
                <td colspan="5"><strong>Total</strong></td>
                <td class="text-right income"><strong><?= number_format($totalRevenue, 2) ?></strong></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- EXPENSES SECTION -->
    <h3 class="section-title">Expenses — Employee / Payee</h3>
    <table>
        <thead>
            <tr>
                <th>Type</th>
                <th>Employee / Payee</th>
                <th>Remark</th>
                <th class="text-right">Amount (<?= e($currSym) ?>)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($printExpenses)): ?>
            <tr><td colspan="4" class="text-center" style="color:#888;font-style:italic;">No expenses for this period.</td></tr>
            <?php else: ?>
            <?php foreach ($printExpenses as $i => $ex): ?>
            <tr <?= $i % 2 === 1 ? 'style="background:#f9f9f9;"' : '' ?>>
                <td><?= e($ex['type_name'] ?? '—') ?></td>
                <td><?= e($ex['employee'] ?: '—') ?></td>
                <td style="max-width:160px;"><?= e($ex['remark'] ?: '—') ?></td>
                <td class="text-right"><?= number_format((float)$ex['amount'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="subtotal">
                <td colspan="3"><strong>Total</strong></td>
                <td class="text-right expense"><strong><?= number_format($grandTotal, 2) ?></strong></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- FINANCIAL SUMMARY -->
    <h3 class="section-title">Financial Summary</h3>
    <table>
        <tbody>
            <tr>
                <td><strong>Total Income</strong></td>
                <td class="text-right income"><?= e($currSym) ?><?= number_format($totalRevenue, 2) ?></td>
            </tr>
            <tr>
                <td><strong>Total Expenses</strong></td>
                <td class="text-right expense"><?= e($currSym) ?><?= number_format($grandTotal, 2) ?></td>
            </tr>
            <tr class="grand">
                <td><strong>NET <?= $netAmount >= 0 ? 'PROFIT' : 'LOSS' ?></strong></td>
                <td class="text-right <?= $netAmount >= 0 ? 'net-pos' : 'net-neg' ?>">
                    <?= e($currSym) ?><?= number_format(abs($netAmount), 2) ?>
                    <?= $netAmount < 0 ? ' (DEFICIT)' : '' ?>
                </td>
            </tr>
        </tbody>
    </table>

    <div class="summary-box">
        <strong>REPORT SUMMARY</strong><br>
        &bull; Report Period: <?= formatDate($dateFrom, 'F j, Y') ?><?= $dateFrom !== $dateTo ? ' &ndash; ' . formatDate($dateTo, 'F j, Y') : '' ?><br>
        &bull; Total Payments Collected: <?= number_format($payCount) ?><br>
        &bull; Total Expense Records: <?= number_format($expCount) ?><br>
        <?php if ($routerName): ?>
        &bull; Router / Branch: <?= e($routerName) ?><br>
        <?php endif; ?>
        &bull; Generated: <?= date('F j, Y g:i A') ?>
    </div>

    <div class="signatures">
        <div class="sig-block">
            Prepared by:
            <div class="sig-line">
                <strong><?= e($preparedBy) ?></strong><br>
                <small><?= e($prepRole) ?></small>
            </div>
        </div>
        <div class="sig-block" style="text-align:right;">
            Noted by:
            <div class="sig-line">
                <strong><?= e($routerManager ?: 'BRANCH MANAGER') ?></strong><br>
                <small>Authorized Signatory / Branch Manager</small>
            </div>
        </div>
    </div>

    <div class="print-footer">
        <span class="page-number"></span>
    </div>

</div>
</body>
</html>
    <?php
    exit;
}

$pageTitle   = 'Reports';
$breadcrumbs = [['label' => 'Reports']];
$extraHead   = <<<'CSS'
<style>
.report-actions {
    gap: .75rem;
}
.report-actions .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .35rem;
    padding: .55rem .9rem;
    min-height: 40px;
}
@media (max-width: 575.98px) {
    .report-actions {
        width: 100%;
    }
    .report-actions .btn {
        flex: 1 1 100%;
    }
}
@media print {
    @page { size: A4 portrait; margin: 12mm 14mm; }

    nav, .navbar, aside, .sidebar, .no-print,
    .btn, form, .d-print-none { display: none !important; }

    .d-print-block { display: block  !important; }
    .d-print-flex  { display: flex   !important; }

    body           { font-size: 11px !important; color: #000 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .card          { box-shadow: none !important; border: 1px solid #ccc !important; break-inside: avoid; }
    .card-body     { padding: .5rem .75rem !important; }
    .card-header   { padding: .35rem .75rem !important; background: #f5f5f5 !important; }

    table          { width: 100% !important; font-size: 10px !important; border-collapse: collapse !important; }
    th, td         { padding: 3px 6px !important; border: 1px solid #ccc !important; }
    thead          { background: #ebebeb !important; }

    .row           { display: block !important; }
    [class*="col-"]{ width: 100% !important; max-width: 100% !important; float: none !important; }

    .progress-bar  { -webkit-print-color-adjust: exact; print-color-adjust: exact; }

    .page-break    { page-break-before: always; }
    .no-break      { break-inside: avoid; page-break-inside: avoid; }

    canvas         { max-width: 100% !important; }
}
</style>
CSS;
include BASE_PATH . '/includes/header.php';
?>

<!-- ═══════════════════════════════════════════════════
     PRINT-ONLY HEADER
     ═══════════════════════════════════════════════════ -->
<div class="d-none d-print-block mb-3">
    <div style="display:flex;align-items:center;gap:14px;border-bottom:2.5px solid #000;padding-bottom:10px;margin-bottom:12px;">
        <?php if ($companyLogo): ?>
        <img src="<?= e($companyLogo) ?>" alt="Logo" style="height:56px;width:auto;object-fit:contain;">
        <?php endif; ?>
        <div style="flex:1;">
            <div style="font-size:17px;font-weight:700;line-height:1.2;"><?= e($companyName) ?></div>
            <?php if ($companyAddress): ?>
            <div style="font-size:10px;color:#555;white-space:pre-line;"><?= e($companyAddress) ?></div>
            <?php endif; ?>
            <div style="font-size:10px;color:#555;">
                <?= $companyContact ? e($companyContact) : '' ?>
                <?= $companyEmail   ? ' · ' . e($companyEmail) : '' ?>
                <?= $companyTin     ? ' · TIN: ' . e($companyTin) : '' ?>
            </div>
        </div>
        <div style="text-align:right;min-width:140px;">
            <div style="font-size:14px;font-weight:700;">Revenue &amp; Operations Report</div>
            <?php if ($routerName): ?>
            <div style="font-size:10px;color:#0d6efd;font-weight:600;margin-top:2px;">
                Router: <?= e($routerName) ?>
            </div>
            <?php endif; ?>
            <div style="font-size:10px;color:#555;margin-top:3px;">
                Period: <strong><?= formatDate($dateFrom) ?> – <?= formatDate($dateTo) ?></strong>
            </div>
            <div style="font-size:10px;color:#555;">Generated: <?= date('M d, Y h:i A') ?></div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════
     SCREEN HEADER
     ═══════════════════════════════════════════════════ -->
<div class="d-flex align-items-center justify-content-between mb-3 d-print-none flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">Reports 
        </h4>
        <p class="text-muted small mb-0">Revenue &amp; Operations Overview</p>
    </div>
    <div class="report-actions d-flex flex-wrap">
        <a href="financial" class="btn btn-outline-secondary">
            <i class="bi bi-bar-chart-line"></i>Financial Summary
        </a>
        <?php if ($canPrintSensitive): ?>
        <a href="?print=1&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>" target="_blank" class="btn btn-primary">
            <i class="bi bi-printer"></i>Print Report
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Date filter -->
<div class="filter-bar mb-3 d-print-none">
    <?php if ($isCashierOnly): ?>
    <div class="alert alert-warning py-2 px-3 mb-2 small d-flex align-items-center gap-2">
        <i class="bi bi-lock-fill"></i>
        <span>Your role is limited to a <strong>7-day</strong> date range (daily &amp; weekly view). Monthly and yearly reports are restricted to Admin and above.</span>
    </div>
    <?php endif; ?>
    <form method="GET" class="row g-2 align-items-end" id="reportFilterForm">
        <input type="hidden" name="_filter" value="1">
        <div class="col-auto d-flex align-items-center">
            <span class="text-muted small fw-medium">Period:</span>
        </div>
        <div class="col-sm-auto">
            <input type="date" name="date_from" id="rptDateFrom" class="form-control form-control-sm"
                   value="<?= e($dateFrom) ?>"
                   <?php if ($isCashierOnly): ?>max="<?= e(appToday()) ?>"<?php endif; ?>>
        </div>
        <div class="col-sm-auto">
            <input type="date" name="date_to" id="rptDateTo" class="form-control form-control-sm"
                   value="<?= e($dateTo) ?>"
                   <?php if ($isCashierOnly): ?>max="<?= e(appToday()) ?>"<?php endif; ?>>
        </div>
        <div class="col-auto d-flex gap-1 align-items-center">
            <span class="text-muted small me-2"><?= formatDate($dateFrom) ?> – <?= formatDate($dateTo) ?></span>
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i></button>
            <a href="?_clear=1" class="btn btn-sm btn-outline-secondary" title="Reset"><i class="bi bi-x-lg"></i></a>
        </div>
    </form>
    <?php if ($isCashierOnly): ?>
    <script>
    (function () {
        const MAX_DAYS = 6; // 0-indexed span → 7 days total
        const from = document.getElementById('rptDateFrom');
        const to   = document.getElementById('rptDateTo');
        if (!from || !to) return;

        function clamp() {
            const fTs = from.value ? new Date(from.value).getTime() : null;
            const tTs = to.value   ? new Date(to.value).getTime()   : null;
            if (!fTs || !tTs) return;
            const spanDays = (tTs - fTs) / 86400000;
            if (spanDays > MAX_DAYS) {
                // Push from-date forward to keep range at 7 days
                const clamped = new Date(tTs - MAX_DAYS * 86400000);
                from.value = clamped.toISOString().slice(0, 10);
            }
            // Keep from min-date = to-date minus 6 days
            const minFrom = new Date(tTs - MAX_DAYS * 86400000);
            from.min = minFrom.toISOString().slice(0, 10);
        }

        to.addEventListener('change', clamp);
        from.addEventListener('change', function () {
            const fTs = from.value ? new Date(from.value).getTime() : null;
            const tTs = to.value   ? new Date(to.value).getTime()   : null;
            if (!fTs || !tTs) return;
            if (fTs > tTs) to.value = from.value;
            clamp();
        });
    })();
    </script>
    <?php endif; ?>
</div>

<?php $netAmount = $totalRevenue - $grandTotal; ?>
<!-- KPI — INCOME / EXPENSES / NET -->
<div class="row g-3 mb-3">
    <div class="col-sm-4">
        <div class="card kpi-card kpi-l-success h-100">
            <div class="card-body">
                <div class="text-muted small mb-1"><i class="bi bi-arrow-down-circle me-1"></i>Income</div>
                <div class="kpi-value text-success"><?= formatMoney($totalRevenue) ?></div>
                <div class="text-muted small"><?= number_format($payCount) ?> payment<?= $payCount !== 1 ? 's' : '' ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card kpi-card kpi-l-danger h-100">
            <div class="card-body">
                <div class="text-muted small mb-1"><i class="bi bi-arrow-up-circle me-1"></i>Expenses</div>
                <div class="kpi-value text-danger"><?= formatMoney($grandTotal) ?></div>
                <div class="text-muted small"><?= number_format($expCount) ?> record<?= $expCount !== 1 ? 's' : '' ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card kpi-card <?= $netAmount >= 0 ? 'kpi-l-success' : 'kpi-l-danger' ?> h-100">
            <div class="card-body">
                <div class="text-muted small mb-1"><i class="bi bi-wallet2 me-1"></i>Net</div>
                <div class="kpi-value <?= $netAmount >= 0 ? 'text-success' : 'text-danger' ?>">
                    <?= ($netAmount < 0 ? '−' : '') . formatMoney(abs($netAmount)) ?>
                </div>
                <div class="text-muted small"><?= $netAmount >= 0 ? 'surplus' : 'deficit' ?> this period</div>
            </div>
        </div>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════
     CHARTS ROW
     ═══════════════════════════════════════════════════ -->
<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card border-0 h-100 no-break">
            <div class="card-header bg-transparent border-bottom py-3">
                <h6 class="mb-0 fw-semibold">Income vs Expenses</h6>
            </div>
            <div class="card-body">
                <canvas id="dailyChart" height="115"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 h-100 no-break">
            <div class="card-header bg-transparent border-bottom py-3">
                <h6 class="mb-0 fw-semibold">Subscriber Status</h6>
            </div>
            <div class="card-body d-flex flex-column justify-content-center">
                <canvas id="statusChart" height="145"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════
     REVENUE BREAKDOWN
     ═══════════════════════════════════════════════════ -->
<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card border-0 h-100 no-break">
            <div class="card-header bg-transparent border-bottom py-3">
                <h6 class="mb-0 fw-semibold">Revenue by Plan</h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Plan</th>
                            <th class="text-center">Payments</th>
                            <th class="pe-3 text-end">Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($byPlan as $bp): ?>
                        <tr>
                            <td class="ps-3 small"><?= e($bp['title']) ?></td>
                            <td class="text-center small"><?= number_format($bp['cnt']) ?></td>
                            <td class="pe-3 text-end small fw-semibold text-success"><?= formatMoney($bp['total']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($byPlan)): ?>
                        <tr><td colspan="3" class="text-center text-muted py-3 small">No data for this period.</td></tr>
                        <?php else: ?>
                        <tr class="table-light fw-semibold">
                            <td class="ps-3 small">Total</td>
                            <td class="text-center small"><?= number_format(array_sum(array_column($byPlan, 'cnt'))) ?></td>
                            <td class="pe-3 text-end small text-success"><?= formatMoney(array_sum(array_column($byPlan, 'total'))) ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card border-0 h-100 no-break">
            <div class="card-header bg-transparent border-bottom py-3">
                <h6 class="mb-0 fw-semibold">Revenue by Payment Method</h6>
            </div>
            <div class="card-body">
                <?php if (empty($byMethod)): ?>
                <div class="text-center text-muted py-3 small">No data for this period.</div>
                <?php else: ?>
                <?php foreach ($byMethod as $bm): ?>
                <?php $pct = $totalRevenue > 0 ? round($bm['total'] / $totalRevenue * 100) : 0; ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="fw-medium"><?= ucfirst(e($bm['method'])) ?></span>
                        <span class="text-success fw-semibold"><?= formatMoney($bm['total']) ?> <span class="text-muted fw-normal">(<?= $pct ?>%)</span></span>
                    </div>
                    <div class="progress" style="height:7px;">
                        <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
                    </div>
                    <div class="text-muted" style="font-size:.7rem;"><?= number_format($bm['cnt']) ?> transaction<?= $bm['cnt'] != 1 ? 's' : '' ?></div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════
     PAYMENT NOTES
     ═══════════════════════════════════════════════════ -->
<div class="card border-0 mb-4 no-break">
    <div class="card-header bg-transparent border-bottom py-3">
        <h6 class="mb-0 fw-semibold">Payment Notes</h6>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Note</th> 
                    <th class="text-center">Subscribers</th>
                    <th class="pe-3 text-end">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($paymentNotes)): ?>
                <tr>
                    <td colspan="3" class="text-center text-muted py-4 small">No payments with notes for this period.</td>
                </tr>
                <?php else: ?>
                <?php foreach ($paymentNotes as $pn): ?>
                <tr>
                    <td class="ps-3 small fw-medium"><?= e($pn['notes']) ?></td>
                    <td class="text-center small"><?= number_format($pn['sub_count']) ?></td>
                    <td class="pe-3 text-end small fw-semibold text-success"><?= formatMoney($pn['total_amount']) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="table-light fw-semibold">
                    <td class="ps-3 small">Total</td>
                    <td class="text-center small"><?= number_format(array_sum(array_column($paymentNotes, 'sub_count'))) ?></td>
                    <td class="pe-3 text-end small text-success"><?= formatMoney(array_sum(array_column($paymentNotes, 'total_amount'))) ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════
     OVERDUE ACCOUNTS
     ═══════════════════════════════════════════════════ -->
<div class="d-flex align-items-center justify-content-between mb-3 mt-4 flex-wrap gap-2">
    <div>
        <h5 class="fw-bold mb-0">Overdue / Expired Accounts</h5>
        <p class="text-muted small mb-0"><?= number_format($odTotal) ?> account<?= $odTotal !== 1 ? 's' : '' ?> found</p>
    </div>
</div>

<!-- Overdue filter bar -->
<div class="filter-bar mb-3 d-print-none">
    <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="_od_filter" value="1">
        <div class="col-sm-6 col-lg-3">
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-search text-muted"></i></span>
                <input type="text" name="od_q" class="form-control form-control-sm"
                       placeholder="Name, account, contact…" value="<?= e($odSearch) ?>">
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <select name="od_plan_id" class="form-select form-select-sm">
                <option value="">All Plans</option>
                <?php foreach ($odPlans as $pl): ?>
                <option value="<?= $pl['plan_id'] ?>" <?= $odPlanId === (int)$pl['plan_id'] ? 'selected' : '' ?>>
                    <?= e($pl['title']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-6 col-lg-3">
            <select name="od_overdue" class="form-select form-select-sm">
                <option value="">All Overdue</option>
                <option value="7"    <?= $odOverdue === '7'    ? 'selected' : '' ?>>1–7 days</option>
                <option value="8_30" <?= $odOverdue === '8_30' ? 'selected' : '' ?>>8–30 days</option>
                <option value="30"   <?= $odOverdue === '30'   ? 'selected' : '' ?>>Over 30 days</option>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
            <a href="?_od_clear=1" class="btn btn-sm btn-outline-secondary" title="Clear"><i class="bi bi-x-lg"></i></a>
        </div>
    </form>
</div>

<div class="card border-0 mb-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Account #</th>
                        <th>Subscriber</th>
                        <th>Contact</th>
                        <th>Plan</th>
                        <th class="text-end">MRC</th>
                        <th class="text-end">Expired On</th>
                        <th class="pe-3 text-end">Days Overdue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($overdue)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="bi bi-check-circle d-block fs-2 mb-2 text-success"></i>
                            No overdue accounts found.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($overdue as $od): ?>
                    <?php $days = (int)$od['days_overdue']; ?>
                    <tr>
                        <td class="ps-3 font-monospace small">
                            <a href="<?= BASE_URL ?>/modules/subscribers/view/<?= $od['subscriber_id'] ?? '' ?>"
                               class="text-decoration-none"><?= e($od['account_number']) ?></a>
                        </td>
                        <td class="small fw-medium"><?= e($od['firstname'] . ' ' . $od['lastname']) ?></td>
                        <td class="small text-muted"><?= e($od['contact_number'] ?? '—') ?></td>
                        <td class="small"><?= e($od['plan_title'] ?? '—') ?></td>
                        <td class="text-end small"><?= isset($od['plan_amount']) ? formatMoney($od['plan_amount']) : '—' ?></td>
                        <td class="text-end small text-danger"><?= $od['subscription_end'] ? formatDate($od['subscription_end'], 'M d, Y') : '—' ?></td>
                        <td class="pe-3 text-end">
                            <span class="badge <?= $days > 30 ? 'bg-danger' : ($days > 7 ? 'bg-warning text-dark' : 'bg-secondary') ?>">
                                <?= number_format($days) ?>d
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($odTotalPages > 1): ?>
    <div class="card-footer bg-transparent border-top d-flex align-items-center justify-content-between">
        <small class="text-muted">
            Showing <?= number_format($odOffset + 1) ?>–<?= number_format(min($odOffset + $odPerPage, $odTotal)) ?>
            of <?= number_format($odTotal) ?> accounts
        </small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= $odPage <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?od_page=<?= $odPage - 1 ?>">‹</a>
                </li>
                <?php for ($p = max(1, $odPage - 2); $p <= min($odTotalPages, $odPage + 2); $p++): ?>
                <li class="page-item <?= $p === $odPage ? 'active' : '' ?>">
                    <a class="page-link" href="?od_page=<?= $p ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
                <li class="page-item <?= $odPage >= $odTotalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?od_page=<?= $odPage + 1 ?>">›</a>
                </li>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>


<!-- ═══════════════════════════════════════════════════
     PRINT-ONLY FOOTER
     ═══════════════════════════════════════════════════ -->
<div class="d-none d-print-block mt-4" style="border-top:1.5px solid #000;padding-top:12px;">
    <div style="display:flex;justify-content:space-between;align-items:flex-end;font-size:10px;color:#555;">
        <div>
            <div style="margin-bottom:24px;font-style:italic;">Prepared by:</div>
            <div style="border-top:1px solid #000;width:170px;padding-top:3px;text-align:center;">Signature over Printed Name</div>
        </div>
        <div style="text-align:center;">
            <div style="margin-bottom:24px;font-style:italic;">Noted by:</div>
            <div style="border-top:1px solid #000;width:170px;padding-top:3px;text-align:center;">Signature over Printed Name</div>
        </div>
        <div style="text-align:right;">
            <div style="font-weight:600;font-size:11px;"><?= e($companyName) ?></div>
            <div>Report period: <?= formatDate($dateFrom) ?> – <?= formatDate($dateTo) ?></div>
            <div>Generated: <?= date('M d, Y h:i A') ?></div>
            <div style="margin-top:4px;font-style:italic;font-size:9px;">This is a system-generated report.</div>
        </div>
    </div>
</div>

<?php
$dailyJson  = json_encode($dailyRev);
$dailyExpJson = json_encode($dailyExp);
$statusJson = json_encode([
    'active'    => $activeSubs,
    'expired'   => $expiredCount,
    'suspended' => $suspSubs,
    'pending'   => $pendSubs,
]);
$symJs = json_encode($currSym);

$extraScripts = <<<JS
<script>
(function () {
const sym = {$symJs};

// ── Income vs Expenses Chart ──────────────────────────────
const daily    = {$dailyJson};
const dailyExp = {$dailyExpJson};

// Build merged sorted label set
const labelSet = [...new Set([
    ...daily.map(d => d.day),
    ...dailyExp.map(d => d.day)
])].sort();

const incMap = Object.fromEntries(daily.map(d => [d.day, parseFloat(d.revenue)]));
const expMap = Object.fromEntries(dailyExp.map(d => [d.day, parseFloat(d.expenses)]));

new Chart(document.getElementById('dailyChart'), {
    type: 'bar',
    data: {
        labels: labelSet,
        datasets: [{
            label: 'Income',
            data: labelSet.map(d => incMap[d] ?? 0),
            backgroundColor: 'rgba(25,135,84,.25)',
            borderColor: '#198754',
            borderWidth: 2,
            borderRadius: 4,
            order: 2,
        },{
            label: 'Expenses',
            data: labelSet.map(d => expMap[d] ?? 0),
            backgroundColor: 'rgba(220,53,69,.2)',
            borderColor: '#dc3545',
            borderWidth: 2,
            borderRadius: 4,
            order: 3,
        },{
            label: 'Net',
            data: labelSet.map(d => (incMap[d] ?? 0) - (expMap[d] ?? 0)),
            type: 'line',
            borderColor: '#0d6efd',
            borderWidth: 2,
            pointRadius: 2,
            fill: false,
            tension: 0.4,
            order: 1,
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: true, position: 'top', labels: { boxWidth: 12, padding: 16, font: { size: 11 } } },
            tooltip: { callbacks: { label: c => ' ' + c.dataset.label + ': ' + sym + parseFloat(c.raw).toLocaleString('en', {minimumFractionDigits:2}) } }
        },
        scales: {
            y: { ticks: { callback: v => sym + v.toLocaleString() }, grid: { color: 'rgba(0,0,0,.05)' } },
            x: { grid: { display: false } }
        }
    }
});

// ── Subscriber Status Doughnut ────────────────────────────
const sd = {$statusJson};
new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: ['Active', 'Expired', 'Suspended', 'Pending'],
        datasets: [{
            data: [sd.active, sd.expired, sd.suspended, sd.pending],
            backgroundColor: ['#198754','#dc3545','#6c757d','#ffc107'],
            borderWidth: 2,
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'bottom', labels: { padding: 10, boxWidth: 12, font: { size: 11 } } },
            tooltip: { callbacks: { label: c => ' ' + c.label + ': ' + c.parsed.toLocaleString() } }
        },
        cutout: '58%',
    }
});

// ── Canvas → image for print ──────────────────────────────
window.addEventListener('beforeprint', function () {
    document.querySelectorAll('canvas').forEach(canvas => {
        const img  = document.createElement('img');
        img.src    = canvas.toDataURL('image/png');
        img.style.maxWidth = '100%';
        img.className = '_print_chart_img';
        canvas.parentNode.insertBefore(img, canvas);
        canvas.style.display = 'none';
    });
});
window.addEventListener('afterprint', function () {
    document.querySelectorAll('._print_chart_img').forEach(img => img.remove());
    document.querySelectorAll('canvas').forEach(c => c.style.display = '');
});

})();
</script>
JS;


include BASE_PATH . '/includes/footer.php';
