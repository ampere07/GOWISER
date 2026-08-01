<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireRole(ROLE_CASHIER, ROLE_ADMIN, ROLE_SUPERADMIN);

$isCashierOnly = currentRole() === ROLE_CASHIER;

function payCashierClamp(string $from, string $to): array {
    $today  = date('Y-m-d');
    $to     = ($to   !== '') ? $to   : $today;
    $from   = ($from !== '') ? $from : $to;
    $toTs   = strtotime($to);
    $fromTs = strtotime($from);
    if ($fromTs === false) $fromTs = $toTs;
    if ($toTs   === false) $toTs   = time();
    if ($toTs < $fromTs) { $tmp = $toTs; $toTs = $fromTs; $fromTs = $tmp; }
    if (($toTs - $fromTs) > 6 * 86400) $fromTs = $toTs - 6 * 86400;
    return [date('Y-m-d', $fromTs), date('Y-m-d', $toTs)];
}

// ── Void payment (SUPERADMIN only) ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['void_payment'])) {
    if (currentRole() !== ROLE_SUPERADMIN) {
        http_response_code(403); die('Forbidden');
    }
    verifyCsrf();
    $voidId     = (int)($_POST['payment_id'] ?? 0);
    $voidReason = trim($_POST['void_reason'] ?? '');
    if ($voidId && $voidReason !== '') {
        $pay = db()->prepare("SELECT * FROM payments WHERE payment_id = ?");
        $pay->execute([$voidId]);
        $pay = $pay->fetch();
        if ($pay && $pay['status'] !== 'cancelled') {
            db()->prepare("UPDATE payments SET status='cancelled', notes=CONCAT(COALESCE(notes,''), ?) WHERE payment_id=?")
                ->execute(["\n[VOIDED: {$voidReason}]", $voidId]);
            logActivity('payments', 'void',
                "Voided payment #{$voidId} OR:{$pay['or_number']} ₱{$pay['amount']} — Reason: {$voidReason}",
                (int)$pay['subscriber_id'],
                ['payment_id' => $voidId, 'or_number' => $pay['or_number'], 'amount' => $pay['amount'], 'status' => $pay['status']],
                ['status' => 'cancelled', 'void_reason' => $voidReason]
            );
            flashMessage('success', "Payment #{$voidId} voided.");
        } else {
            flashMessage('danger', 'Payment not found or already voided.');
        }
    } else {
        flashMessage('danger', 'Void reason is required.');
    }
    redirect(BASE_URL . '/modules/payments/');
}

// ── Session-persisted filters ─────────────────────────────────
const SESS_PAY = 'filter_payments';

if (isset($_GET['_clear'])) {
    unset($_SESSION[SESS_PAY]);
    redirect(BASE_URL . '/modules/payments/');
}
if (isset($_GET['_filter'])) {
    $fFrom = $_GET['date_from'] ?? '';
    $fTo   = $_GET['date_to']   ?? '';
    if ($isCashierOnly && ($fFrom !== '' || $fTo !== '')) {
        [$fFrom, $fTo] = payCashierClamp($fFrom, $fTo);
    }
    $_SESSION[SESS_PAY] = [
        'q'         => trim($_GET['q']       ?? ''),
        'method'    => $_GET['method']        ?? '',
        'status'    => $_GET['status']        ?? '',
        'date_from' => $fFrom,
        'date_to'   => $fTo,
    ];
    redirect(BASE_URL . '/modules/payments/');
}

$f        = $_SESSION[SESS_PAY] ?? [];
$search   = $f['q']         ?? '';
$method   = $f['method']    ?? '';
$status   = $f['status']    ?? '';
$dateFrom = $f['date_from'] ?? '';
$dateTo   = $f['date_to']   ?? '';

if ($isCashierOnly && ($dateFrom !== '' || $dateTo !== '')) {
    [$dateFrom, $dateTo] = payCashierClamp($dateFrom, $dateTo);
}

$page = max(1, (int)($_GET['page'] ?? 1));

// Release session lock so concurrent AJAX requests (sidebar poll, live status) are not blocked
// while this page runs its DB queries. Session data is already read above; no writes remain.
session_write_close();

// Ensure payment_types table exists before any JOIN uses it
$paymentTypes = getPaymentTypes();

$routerId   = selectedRouterId();
$routerName = selectedRouterName();

// Detect whether any filter is active
$hasFilter = $search !== '' || $method !== '' || $status !== '' || $dateFrom !== '' || $dateTo !== '';

$where  = ['1=1'];
$params = [];

// Scope to selected router (superadmin context)
if ($routerId) {
    $where[]  = 's.router_id = ?';
    $params[] = $routerId;
}

if ($search) {
    $where[]  = '(s.firstname LIKE ? OR s.lastname LIKE ? OR CONCAT(s.firstname, \' \', s.lastname) LIKE ? OR s.account_number LIKE ? OR py.reference_number LIKE ? OR py.or_number LIKE ?)';
    $like      = '%' . $search . '%';
    $params    = array_merge($params, [$like, $like, $like, $like, $like, $like]);
}
if ($method)   { $where[] = 'py.method = ?';              $params[] = $method; }
if ($status)   { $where[] = 'py.status = ?';              $params[] = $status; }
if ($dateFrom) { $where[] = 'DATE(py.payment_date) >= ?'; $params[] = $dateFrom; }
if ($dateTo)   { $where[] = 'DATE(py.payment_date) <= ?'; $params[] = $dateTo; }

$today    = date('Y-m-d');
$isPrint  = isset($_GET['print']);
$canPrintSensitive = canPrintSensitiveRecords();

if ($isPrint && !$canPrintSensitive) {
    forbidden();
}

// No filter → default to today's payments
if (!$hasFilter) {
    $where[]  = 'DATE(py.payment_date) = ?';
    $params[] = $today;
}

$whereStr = implode(' AND ', $where);

$total = db()->prepare("
    SELECT COUNT(*) FROM payments py
    JOIN subscribers s ON s.subscriber_id = py.subscriber_id
    WHERE {$whereStr}
");
$total->execute($params);
$totalCount = (int)$total->fetchColumn();

if ($isPrint) {
    // Print mode: fetch ALL records, no pagination
    $payments = db()->prepare("
        SELECT py.*, s.firstname, s.lastname, s.account_number,
               u.firstname  AS cashier_first,  u.lastname  AS cashier_last,
               ue.firstname AS editor_first,   ue.lastname AS editor_last,
               pt.name AS type_name
        FROM payments py
        JOIN subscribers s ON s.subscriber_id = py.subscriber_id
        LEFT JOIN users u  ON u.user_id  = py.user_id
        LEFT JOIN users ue ON ue.user_id = py.updated_by
        LEFT JOIN payment_types pt ON pt.type_id = py.payment_type_id
        WHERE {$whereStr}
        ORDER BY py.payment_date DESC
    ");
    $payments->execute($params);
    $payments = $payments->fetchAll();
} else {
    $pag = paginate($totalCount, $page);
    $payments = db()->prepare("
        SELECT py.*, s.firstname, s.lastname, s.account_number,
               u.firstname  AS cashier_first,  u.lastname  AS cashier_last,
               ue.firstname AS editor_first,   ue.lastname AS editor_last,
               pt.name AS type_name
        FROM payments py
        JOIN subscribers s ON s.subscriber_id = py.subscriber_id
        LEFT JOIN users u  ON u.user_id  = py.user_id
        LEFT JOIN users ue ON ue.user_id = py.updated_by
        LEFT JOIN payment_types pt ON pt.type_id = py.payment_type_id
        WHERE {$whereStr}
        ORDER BY py.payment_date DESC
        LIMIT {$pag['per_page']} OFFSET {$pag['offset']}
    ");
    $payments->execute($params);
    $payments = $payments->fetchAll();
}

// For totals, rebuild WHERE without status so the revenue bar always shows paid totals.
// (The list respects the status filter; the total strip always counts paid.)
$totalWhere  = ['1=1', 'py.status = \'paid\''];
$totalParams = [];
if ($routerId)  { $totalWhere[] = 's.router_id = ?';              $totalParams[] = $routerId; }
if ($search)    { $totalWhere[] = '(s.firstname LIKE ? OR s.lastname LIKE ? OR CONCAT(s.firstname, \' \', s.lastname) LIKE ? OR s.account_number LIKE ? OR py.reference_number LIKE ?)';
                  $totalParams  = array_merge($totalParams, array_fill(0, 5, '%'.$search.'%')); }
if ($method)    { $totalWhere[] = 'py.method = ?';                $totalParams[] = $method; }
if ($dateFrom)  { $totalWhere[] = 'DATE(py.payment_date) >= ?';   $totalParams[] = $dateFrom; }
if ($dateTo)    { $totalWhere[] = 'DATE(py.payment_date) <= ?';   $totalParams[] = $dateTo; }
if (!$hasFilter){ $totalWhere[] = 'DATE(py.payment_date) = ?';    $totalParams[] = $today; }
$totalWhereStr = implode(' AND ', $totalWhere);

$totalRev = db()->prepare("
    SELECT COALESCE(SUM(py.amount), 0)
    FROM payments py
    JOIN subscribers s ON s.subscriber_id = py.subscriber_id
    WHERE {$totalWhereStr}
");
$totalRev->execute($totalParams);
$totalRevenue = (float)$totalRev->fetchColumn();

// Breakdown by method (paid only, same scope)
$methodBreakdown = db()->prepare("
    SELECT py.method, COUNT(*) AS cnt, COALESCE(SUM(py.amount), 0) AS total
    FROM payments py
    JOIN subscribers s ON s.subscriber_id = py.subscriber_id
    WHERE {$totalWhereStr}
    GROUP BY py.method
    ORDER BY total DESC
");
$methodBreakdown->execute($totalParams);
$methodBreakdown = $methodBreakdown->fetchAll();

// ── Print view — standalone A4 page ──────────────────────────
if ($isPrint):
    $companyName = getSetting('company_name', defined('APP_NAME') ? APP_NAME : 'NetManager');
    $companyLogo = getSetting('company_logo', '');
    $printedAt   = date('F d, Y  h:i A');
    $scopeLabel  = $hasFilter ? 'Filtered Results' : 'Daily Collections — ' . date('F d, Y');
    $filterParts = [];
    if ($search)   $filterParts[] = 'Search: "' . htmlspecialchars($search) . '"';
    if ($method)   $filterParts[] = 'Method: ' . strtoupper(htmlspecialchars($method));
    if ($status)   $filterParts[] = 'Status: ' . ucfirst(htmlspecialchars($status));
    if ($dateFrom) $filterParts[] = 'From: ' . htmlspecialchars(date('M d, Y', strtotime($dateFrom)));
    if ($dateTo)   $filterParts[] = 'To: '   . htmlspecialchars(date('M d, Y', strtotime($dateTo)));
    $filterDesc = $filterParts ? implode('  ·  ', $filterParts) : 'No additional filters';
    $perPage    = 15;
    $chunks     = array_chunk($payments, $perPage);
    $totalPages = max(1, count($chunks));
    if (empty($chunks)) $chunks = [[]];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Payment Report — <?= $companyName ?></title>
<style>
/* ── Reset & Base ─────────────────────────── */
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Segoe UI',Arial,Helvetica,sans-serif;font-size:9.5pt;color:#1a1a2e;background:#fff;}
@page{size:A4 landscape;margin:10mm 14mm;}

/* ── Header Band ─────────────────────────── */
.page-header{
    display:flex;justify-content:space-between;align-items:stretch;
    background:#0f2d5c;color:#fff;
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

/* ── Info Row ────────────────────────────── */
.info-row{
    display:flex;align-items:center;gap:0;
    background:#f0f4ff;border:1px solid #d0d9f0;border-radius:5px;
    margin-bottom:10px;overflow:hidden;font-size:8pt;
}
.info-cell{padding:6px 14px;border-right:1px solid #d0d9f0;}
.info-cell:last-child{border-right:none;}
.info-cell .ic-lbl{color:#6b7a99;text-transform:uppercase;font-size:7pt;letter-spacing:.06em;margin-bottom:1px;}
.info-cell .ic-val{font-weight:700;color:#0f2d5c;font-size:9.5pt;}
.info-cell .ic-val.green{color:#166534;}
.info-cell.method-cell{text-align:center;}
.info-cell.method-cell .ic-val{font-size:8.5pt;}
.info-cell.method-cell .ic-cnt{color:#888;font-size:7pt;}
.spacer{flex:1;}
.filter-tag{
    display:inline-block;background:#e7edff;border:1px solid #b3c2ea;
    border-radius:3px;padding:1px 6px;font-size:7.5pt;color:#0f2d5c;margin-right:4px;
}

/* ── Table ───────────────────────────────── */
table{width:100%;border-collapse:collapse;font-size:8.5pt;}
thead tr{background:#0f2d5c;color:#fff;}
thead th{
    padding:6px 8px;text-align:left;font-weight:600;
    font-size:7.8pt;letter-spacing:.04em;text-transform:uppercase;
    border-right:1px solid rgba(255,255,255,.12);
}
thead th:last-child{border-right:none;}
thead th.right{text-align:right;}
tbody tr:nth-child(even){background:#f7f9ff;}
tbody tr:hover{background:#eef2ff;}
tbody td{padding:4.5px 8px;border-bottom:1px solid #e8edf5;vertical-align:middle;}
tbody td.right{text-align:right;}
tfoot td{padding:6px 8px;background:#e8f0fe;border-top:2px solid #0f2d5c;font-weight:700;}
tfoot td.right{text-align:right;}

/* ── Utilities ───────────────────────────── */
.mono{font-family:'Courier New',monospace;}
.fw7{font-weight:700;}
.muted{color:#6b7a99;}
.green{color:#166534;}
.badge{
    display:inline-block;padding:2px 6px;border-radius:20px;
    font-size:7pt;font-weight:700;letter-spacing:.03em;
}
.badge-paid{background:#dcfce7;color:#166534;border:1px solid #bbf7d0;}
.badge-pending{background:#fef9c3;color:#854d0e;border:1px solid #fde68a;}
.badge-cancelled{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}
.badge-refunded{background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;}
.seq{color:#94a3b8;font-size:7.5pt;}

/* ── Footer ──────────────────────────────── */
.page-footer{
    margin-top:8px;padding-top:5px;
    border-top:1px solid #d0d9f0;
    display:flex;justify-content:space-between;
    font-size:7.5pt;color:#94a3b8;
}

/* ── Continuation header (page 2+) ───────── */
.cont-header{
    background:#0f2d5c;color:#fff;border-radius:5px;
    padding:7px 14px;font-size:9pt;margin-bottom:8px;
    display:flex;justify-content:space-between;align-items:center;
}
.cont-header strong{font-size:10pt;}

/* ── Grand total tfoot row ───────────────── */
tfoot .grand-row td{
    background:#0f2d5c !important;color:#fff !important;
    border-top:3px solid #0a1f3e;font-size:8pt;
    -webkit-print-color-adjust:exact;print-color-adjust:exact;
}
tfoot .grand-row td.green{color:#86efac !important;font-size:10pt;}
</style>
</head>
<body>

<!-- ══ Page Header ══════════════════════════════════════════════ -->
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
        <div class="report-title">Payment Report</div>
        <?php if (!$hasFilter): ?>
        <div class="scope-lbl"><?= $scopeLabel ?></div>
        <?php endif; ?>
    </div>
    <div class="ph-right">
        <strong>Printed</strong>
        <?= $printedAt ?>
        <?php if ($routerName): ?><br>Router: <?= htmlspecialchars($routerName) ?><?php endif; ?>
    </div>
</div>

<!-- ══ Summary / Totals ════════════════════════════════════════ -->
<div class="info-row">
    <div class="info-cell">
        <div class="ic-lbl">Total Amount</div>
        <div class="ic-val green"><?= formatMoney($totalRevenue) ?></div>
    </div>
    <div class="info-cell">
        <div class="ic-lbl">Total Records</div>
        <div class="ic-val"><?= number_format($totalCount) ?></div>
    </div>
    <?php if (!empty($methodBreakdown)): ?>
    <div class="info-cell" style="border-right:2px solid #b3c2ea;"></div>
    <?php foreach ($methodBreakdown as $mb): ?>
    <div class="info-cell method-cell">
        <div class="ic-lbl"><?= strtoupper(htmlspecialchars($mb['method'])) ?></div>
        <div class="ic-val green"><?= formatMoney($mb['total']) ?></div>
        <div class="ic-cnt"><?= number_format($mb['cnt']) ?> txn<?= $mb['cnt'] != 1 ? 's' : '' ?></div>
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
    $startIdx     = $pgIdx * $perPage;
    $pageSubtotal = 0;
    foreach ($chunk as $pay) {
        if ($pay['status'] === 'paid') $pageSubtotal += (float)$pay['amount'];
    }
?>

<?php if ($pgIdx > 0): ?>
<div style="page-break-before:always;"></div>
<!-- ══ Continuation Header (page <?= $pgIdx + 1 ?>) ══ -->
<div class="cont-header">
    <span><strong><?= htmlspecialchars($companyName) ?></strong> &mdash; Payment Report (continued)</span>
    <span>Page <?= $pgIdx + 1 ?> of <?= $totalPages ?></span>
</div>
<?php endif; ?>

<!-- ══ Payments Table — Page <?= $pgIdx + 1 ?> ══════════════════ -->
<table>
    <thead>
        <tr>
            <th style="width:28px;">#</th>
            <th style="width:68px;">OR No.</th>
            <th>Subscriber</th>
            <th style="width:90px;">Account #</th>
            <th class="right" style="width:80px;">Amount</th>
            <th style="width:80px;">Type</th>
            <th style="width:60px;">Method</th>
            <th style="width:78px;">Date</th>
            <th style="width:62px;">Status</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($chunk)): ?>
        <tr><td colspan="9" style="text-align:center;padding:24px;color:#94a3b8;">No records found.</td></tr>
    <?php else: ?>
        <?php foreach ($chunk as $i => $pay): ?>
        <tr>
            <td class="seq"><?= number_format($startIdx + $i + 1) ?></td>
            <td class="mono fw7"><?= e($pay['or_number'] ?? str_pad($pay['payment_id'], 6, '0', STR_PAD_LEFT)) ?></td>
            <td class="fw7"><?= e($pay['firstname'] . ' ' . $pay['lastname']) ?></td>
            <td class="mono muted" style="font-size:8pt;"><?= e($pay['account_number']) ?></td>
            <td class="right fw7 green"><?= formatMoney($pay['amount']) ?></td>
            <td class="muted" style="font-size:8pt;"><?= e($pay['type_name'] ?? 'Subscription') ?></td>
            <td><?= strtoupper(e($pay['method'])) ?></td>
            <td style="white-space:nowrap;font-size:8pt;"><?= formatDate($pay['payment_date'], 'M d, Y') ?></td>
            <td><span class="badge badge-<?= e($pay['status']) ?>"><?= strtoupper(e($pay['status'])) ?></span></td>
        </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="4" style="color:#0f2d5c;font-size:8pt;letter-spacing:.04em;">
                PAGE <?= $pgIdx + 1 ?> SUBTOTAL
            </td>
            <td class="right green"><?= formatMoney($pageSubtotal) ?></td>
            <td colspan="3" style="font-size:7.5pt;color:#6b7a99;font-weight:400;">
                <?= number_format(count($chunk)) ?> row<?= count($chunk) !== 1 ? 's' : '' ?> on this page
            </td>
            <td class="right" style="font-size:7.5pt;color:#6b7a99;font-weight:400;white-space:nowrap;">
                Page <?= $pgIdx + 1 ?> / <?= $totalPages ?>
            </td>
        </tr>
        <?php if ($pgIdx === $totalPages - 1 && !empty($payments)): ?>
        <tr class="grand-row">
            <td colspan="4" style="letter-spacing:.04em;">GRAND TOTAL (PAID)</td>
            <td class="right green"><?= formatMoney($totalRevenue) ?></td>
            <td colspan="4"></td>
        </tr>
        <?php endif; ?>
    </tfoot>
</table>

<!-- ══ Page Footer ══════════════════════════════════════════════ -->
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

$pageTitle   = 'Payments';
$breadcrumbs = [['label' => 'Payments']];
include BASE_PATH . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">Payments</h4>
        <p class="text-muted small mb-0">
            <?= $hasFilter ? 'Filtered results' : 'Today — ' . date('F d, Y') ?>
            &nbsp;·&nbsp; <?= number_format($totalCount) ?> record<?= $totalCount !== 1 ? 's' : '' ?>
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap justify-content-end">
        <?php if ($canPrintSensitive): ?>
        <a href="<?= BASE_URL ?>/api/export_payments.php"
           class="btn btn-outline-success" title="Export current filter to CSV">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export CSV
        </a>
        <a href="?print=1" target="_blank" class="btn btn-outline-secondary">
            <i class="bi bi-printer me-1"></i>Print
        </a>
        <?php endif; ?>
        <?php if (canModifyRecords()): ?>
        <a href="add" class="btn btn-success">
            <i class="bi bi-cash-coin me-1"></i>Record Payment
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- ── Totals strip ─────────────────────────────────────────── -->
<div class="card border-0 mb-3 bg-success-subtle">
    <div class="card-body py-2 px-3">
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-cash-stack text-success fs-5"></i>
                <div>
                    <div class="text-muted text-label-xs">
                        <?= $hasFilter ? 'FILTERED TOTAL' : "TODAY'S COLLECTIONS" ?>
                    </div>
                    <div class="fw-bold fs-5 text-success lh-1"><?= formatMoney($totalRevenue) ?></div>
                </div>
            </div>
            <?php if (!empty($methodBreakdown)): ?>
            <div class="vr d-none d-sm-block opacity-25"></div>
            <?php foreach ($methodBreakdown as $mb): ?>
            <div class="text-center">
                <div class="text-muted text-label-xs"><?= strtoupper(e($mb['method'])) ?></div>
                <div class="fw-semibold small text-success"><?= formatMoney($mb['total']) ?></div>
                <div class="text-muted text-label-xs"><?= $mb['cnt'] ?> txn<?= $mb['cnt'] != 1 ? 's' : '' ?></div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Filters -->
<?php if ($isCashierOnly): ?>
<div class="alert alert-warning py-2 small mb-2 d-flex align-items-center gap-2">
    <i class="bi bi-lock-fill"></i>
    <span>Date range is limited to <strong>7 days</strong> maximum. Monthly and yearly views are restricted to Admin and above.</span>
</div>
<?php endif; ?>
<div class="filter-bar mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="_filter" value="1">
        <div class="col-sm-6 col-lg-3">
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-search text-muted"></i></span>
                <input type="text" name="q" class="form-control form-control-sm"
                       placeholder="Subscriber, account, OR#, reference…" value="<?= e($search) ?>">
            </div>
        </div>
        <div class="col-sm-6 col-lg-2">
            <select name="method" class="form-select form-select-sm">
                <option value="">All Methods</option>
                <?php foreach (getPaymentMethods() as $val => $label): ?>
                <option value="<?= e($val) ?>" <?= $method === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-6 col-lg-2">
            <select name="status" class="form-select form-select-sm">
                <option value="">All Status</option>
                <?php foreach (['paid','pending','refunded','cancelled'] as $s): ?>
                <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-6 col-lg-auto">
            <input type="date" name="date_from" id="payDateFrom" class="form-control form-control-sm"
                   value="<?= e($dateFrom) ?>" title="From date"
                   <?= $isCashierOnly ? 'max="' . date('Y-m-d') . '"' : '' ?>>
        </div>
        <div class="col-sm-6 col-lg-auto">
            <input type="date" name="date_to" id="payDateTo" class="form-control form-control-sm"
                   value="<?= e($dateTo) ?>" title="To date"
                   <?= $isCashierOnly ? 'max="' . date('Y-m-d') . '"' : '' ?>>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
            <a href="?_clear=1" class="btn btn-sm btn-outline-secondary" title="Clear"><i class="bi bi-x-lg"></i></a>
        </div>
    </form>
</div>
<?php if ($isCashierOnly): ?>
<script>
(function () {
    const MAX_DAYS = 6;
    const from = document.getElementById('payDateFrom');
    const to   = document.getElementById('payDateTo');
    if (!from || !to) return;
    function clamp() {
        const fTs = from.value ? new Date(from.value).getTime() : null;
        const tTs = to.value   ? new Date(to.value).getTime()   : null;
        if (!fTs || !tTs) return;
        if ((tTs - fTs) / 86400000 > MAX_DAYS) {
            from.value = new Date(tTs - MAX_DAYS * 86400000).toISOString().slice(0, 10);
        }
        from.min = new Date(tTs - MAX_DAYS * 86400000).toISOString().slice(0, 10);
    }
    to.addEventListener('change', clamp);
    from.addEventListener('change', function () {
        const fTs = from.value ? new Date(from.value).getTime() : null;
        const tTs = to.value   ? new Date(to.value).getTime()   : null;
        if (fTs && tTs && fTs > tTs) to.value = from.value;
        clamp();
    });
})();
</script>
<?php endif; ?>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">OR #</th>
                        <th>Subscriber</th>
                        <th>Amount</th>
                        <th class="d-none d-md-table-cell">Type</th>
                        <th>Method</th>
                        <th class="d-none d-lg-table-cell">Period</th>
                        <th>Date</th>
                        <th class="d-none d-md-table-cell">Added / Edited By</th>
                        <th>Status</th>
                        <th class="pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payments)): ?>
                    <tr>
                        <td colspan="10" class="text-center py-5 text-muted">
                            <i class="bi bi-cash-stack d-block fs-2 mb-2"></i>No payment records found.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($payments as $pay): ?>
                    <tr>
                        <td class="ps-3 font-monospace small"><?= e($pay['or_number'] ?? str_pad($pay['payment_id'], 6, '0', STR_PAD_LEFT)) ?></td>
                        <td>
                            <div class="fw-medium small"><?= e($pay['firstname'] . ' ' . $pay['lastname']) ?></div>
                            <div class="text-muted" style="font-size:.75rem;"><?= e($pay['account_number']) ?></div>
                        </td>
                        <td class="fw-semibold text-success"><?= formatMoney($pay['amount']) ?></td>
                        <td class="d-none d-md-table-cell"><span class="badge bg-secondary bg-opacity-10 text-secondary border small"><?= e($pay['type_name'] ?? 'Subscription') ?></span></td>
                        <td>
                            <span class="badge bg-secondary bg-opacity-10 text-secondary border small"><?= ucfirst(e($pay['method'])) ?></span>
                        </td>
                        <td class="d-none d-lg-table-cell small text-muted">
                            <?= formatDate($pay['period_start'], 'M d') ?>–<?= formatDate($pay['period_end'], 'M d, Y') ?>
                        </td>
                        <td class="small"><?= formatDate($pay['payment_date'], 'M d, Y') ?></td>
                        <td class="d-none d-md-table-cell small">
                            <?php
                            $addedBy  = trim(($pay['cashier_first'] ?? '') . ' ' . ($pay['cashier_last'] ?? ''));
                            $editedBy = trim(($pay['editor_first']  ?? '') . ' ' . ($pay['editor_last']  ?? ''));
                            ?>
                            <?= $addedBy ? e($addedBy) : '—' ?>
                            <?php if ($editedBy && $editedBy !== $addedBy): ?>
                            <div class="text-muted" style="font-size:.72rem;">
                                <i class="bi bi-pencil me-1"></i><?= e($editedBy) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td><?= payStatusBadge($pay['status']) ?></td>
                        <td class="pe-3">
                            <div class="d-flex gap-1">
                                <?php if ($canPrintSensitive): ?>
                                <a href="receipt/<?= $pay['payment_id'] ?>"
                                   class="btn btn-sm btn-outline-secondary" title="Receipt" target="_blank">
                                    <i class="bi bi-printer"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (currentRole() === ROLE_SUPERADMIN): ?>
                                <a href="edit/<?= $pay['payment_id'] ?>"
                                   class="btn btn-sm btn-outline-primary" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php if ($pay['status'] !== 'cancelled'): ?>
                                <button type="button"
                                        class="btn btn-sm btn-outline-danger btn-void-payment"
                                        title="Void payment"
                                        data-id="<?= $pay['payment_id'] ?>"
                                        data-or="<?= e($pay['or_number'] ?? str_pad($pay['payment_id'], 6, '0', STR_PAD_LEFT)) ?>"
                                        data-amount="<?= formatMoney($pay['amount']) ?>"
                                        data-name="<?= e($pay['firstname'] . ' ' . $pay['lastname']) ?>">
                                    <i class="bi bi-slash-circle"></i>
                                </button>
                                <?php endif; ?>
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
        <small class="text-muted">Showing <?= number_format($pag['offset'] + 1) ?>–<?= number_format(min($pag['offset'] + $pag['per_page'], $totalCount)) ?> of <?= number_format($totalCount) ?></small>
        <?= renderPagination($pag) ?>
    </div>
    <?php endif; ?>
</div>

<?php if (currentRole() === ROLE_SUPERADMIN): ?>
<!-- Hidden void form (submitted by JS) -->
<form method="POST" id="voidForm" style="display:none;">
    <?= csrfField() ?>
    <input type="hidden" name="void_payment" value="1">
    <input type="hidden" name="payment_id"   id="voidPaymentId">
    <input type="hidden" name="void_reason"  id="voidReasonInput">
</form>

<?php
$extraScripts = <<<'JS'
<script>
document.querySelectorAll('.btn-void-payment').forEach(btn => {
    btn.addEventListener('click', function () {
        const id     = this.dataset.id;
        const or     = this.dataset.or;
        const amount = this.dataset.amount;
        const name   = this.dataset.name;

        Swal.fire({
            title: 'Void Payment?',
            html: `<div class="text-start">
                <div class="mb-3 p-2 bg-light rounded small">
                    <div><strong>${or}</strong> &mdash; ${amount}</div>
                    <div class="text-muted">${name}</div>
                </div>
                <div class="alert alert-danger py-2 small mb-3">
                    This will mark the payment as <strong>Cancelled</strong>. This cannot be undone.
                </div>
                <label class="form-label fw-semibold small" for="swalVoidReason">Reason for voiding <span class="text-danger">*</span></label>
                <textarea id="swalVoidReason" class="form-control form-control-sm" rows="2"
                          placeholder="e.g. Duplicate entry, incorrect amount..."></textarea>
            </div>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: '<i class="bi bi-slash-circle me-1"></i>Void Payment',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#dc3545',
            reverseButtons: true,
            preConfirm: () => {
                const reason = document.getElementById('swalVoidReason').value.trim();
                if (!reason) {
                    Swal.showValidationMessage('Void reason is required.');
                    return false;
                }
                return reason;
            },
        }).then(result => {
            if (!result.isConfirmed) return;
            document.getElementById('voidPaymentId').value  = id;
            document.getElementById('voidReasonInput').value = result.value;
            document.getElementById('voidForm').submit();
        });
    });
});
</script>
JS;
?>
<?php endif; ?>

<?php include BASE_PATH . '/includes/footer.php'; ?>
