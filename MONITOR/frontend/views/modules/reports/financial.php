<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireRole(ROLE_CASHIER, ROLE_ADMIN, ROLE_SUPERADMIN);

$hasExpAccess  = in_array(currentRole(), MODULE_ACCESS['expenses'] ?? []);
$isCashierOnly = currentRole() === ROLE_CASHIER;

// ── Reference date (defaults to today) ───────────────────
$refDate = $_GET['ref_date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $refDate)) $refDate = date('Y-m-d');
$refTs = strtotime($refDate);

// ── Compute period ranges ─────────────────────────────────
$dow       = (int)date('N', $refTs);
$weekStart = date('Y-m-d', strtotime('-' . ($dow - 1) . ' days', $refTs));
$weekEnd   = date('Y-m-d', strtotime('+' . (7  - $dow) . ' days', $refTs));

// Cashier may only see daily and weekly periods
$allPeriods = [
    'daily'   => ['label' => 'Daily',   'from' => $refDate,             'to' => $refDate,             'icon' => 'bi-calendar-day',   'accent' => '#0d6efd'],
    'weekly'  => ['label' => 'Weekly',  'from' => $weekStart,           'to' => $weekEnd,             'icon' => 'bi-calendar-week',  'accent' => '#0dcaf0'],
    'monthly' => ['label' => 'Monthly', 'from' => date('Y-m-01',$refTs),'to' => date('Y-m-t',$refTs),'icon' => 'bi-calendar-month', 'accent' => '#fd7e14'],
    'yearly'  => ['label' => 'Yearly',  'from' => date('Y-01-01',$refTs),'to'=> date('Y-12-31',$refTs),'icon'=> 'bi-calendar3',     'accent' => '#198754'],
];

$allowedPeriodKeys = $isCashierOnly ? ['daily', 'weekly'] : ['daily', 'weekly', 'monthly', 'yearly'];
$periods = array_intersect_key($allPeriods, array_flip($allowedPeriodKeys));

// ── Router scope ──────────────────────────────────────────
$routerId   = selectedRouterId();
$routerName = selectedRouterName();
$rJoin  = $routerId ? " JOIN subscribers s ON s.subscriber_id = py.subscriber_id" : "";
$rWhere = $routerId ? " AND s.router_id = ?" : "";
$rP     = $routerId ? [$routerId] : [];
$eWhere = $routerId ? " AND e.router_id = ?" : "";
$eP     = $routerId ? [$routerId] : [];

// ── Data fetch per period ─────────────────────────────────
foreach ($periods as $key => &$p) {
    // Income total
    $stmt = db()->prepare(
        "SELECT COALESCE(SUM(py.amount),0)
         FROM payments py{$rJoin}
         WHERE py.status='paid' AND DATE(py.payment_date) BETWEEN ? AND ?{$rWhere}"
    );
    $stmt->execute(array_merge([$p['from'], $p['to']], $rP));
    $p['income'] = (float)$stmt->fetchColumn();

    // Income by payment method
    $mStmt = db()->prepare(
        "SELECT py.method, COUNT(*) AS cnt, SUM(py.amount) AS total
         FROM payments py{$rJoin}
         WHERE py.status='paid' AND DATE(py.payment_date) BETWEEN ? AND ?{$rWhere}
         GROUP BY py.method ORDER BY total DESC"
    );
    $mStmt->execute(array_merge([$p['from'], $p['to']], $rP));
    $p['by_method'] = $mStmt->fetchAll();

    // Number of payments
    $cStmt = db()->prepare(
        "SELECT COUNT(*) FROM payments py{$rJoin}
         WHERE py.status='paid' AND DATE(py.payment_date) BETWEEN ? AND ?{$rWhere}"
    );
    $cStmt->execute(array_merge([$p['from'], $p['to']], $rP));
    $p['payment_count'] = (int)$cStmt->fetchColumn();

    // Expenses
    $p['expenses']   = 0.0;
    $p['by_exptype'] = [];
    if ($hasExpAccess) {
        try {
            $ePeriodWhere = expensePeriodTypeSql($key, 'e');
            $eStmt = db()->prepare(
                "SELECT COALESCE(SUM(e.amount),0) FROM expenses e WHERE DATE(e.expense_date) BETWEEN ? AND ?{$ePeriodWhere}{$eWhere}"
            );
            $eStmt->execute(array_merge([$p['from'], $p['to']], $eP));
            $p['expenses'] = (float)$eStmt->fetchColumn();

            $etStmt = db()->prepare(
                "SELECT COALESCE(et.name,'(Uncategorized)') AS type_name,
                        COUNT(*) AS cnt, SUM(e.amount) AS total
                 FROM expenses e
                 LEFT JOIN expense_types et ON et.type_id = e.expense_type_id
                 WHERE DATE(e.expense_date) BETWEEN ? AND ?{$ePeriodWhere}{$eWhere}
                 GROUP BY et.name ORDER BY total DESC"
            );
            $etStmt->execute(array_merge([$p['from'], $p['to']], $eP));
            $p['by_exptype'] = $etStmt->fetchAll();
        } catch (PDOException $e) {}
    }

    $p['net'] = $p['income'] - $p['expenses'];
}
unset($p);

// ── Company settings ──────────────────────────────────────
$companyName    = getSetting('company_name',    'ISP Company');
$companyAddress = getSetting('company_address', '');
$companyContact = getSetting('company_contact', '');
$companyEmail   = getSetting('company_email',   '');
$companyTin     = getSetting('company_tin',     '');
$companyLogo    = getSetting('company_logo',    '');
$currSym        = getSetting('currency_symbol', '₱');

$pageTitle   = 'Financial Summary';
$breadcrumbs = [
    ['label' => 'Reports', 'url' => BASE_URL . '/modules/reports/'],
    ['label' => 'Financial Summary'],
];

$extraHead = <<<'CSS'
<style>
.fin-period-card { transition: transform .12s, box-shadow .12s; border-left: 4px solid transparent; }
.fin-period-card:hover { transform: translateY(-2px); box-shadow: 0 .4rem 1.2rem rgba(0,0,0,.1); }
.fin-amount { font-variant-numeric: tabular-nums; }
</style>
CSS;
include BASE_PATH . '/includes/header.php';
?>

<!-- ═══════════════════════════════════════════════════════
     SCREEN — HEADER
     ═══════════════════════════════════════════════════════ -->
<div class="d-flex align-items-center justify-content-between mb-3 d-print-none flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">Financial Summary
        </h4>
        <p class="text-muted small mb-0">Income · Expenses · Net &nbsp;|&nbsp;
            <?php if ($isCashierOnly): ?>
            Daily · Weekly
            <span class="badge bg-warning text-dark ms-1" title="Monthly and yearly reports are restricted to Admin and above">
                <i class="bi bi-lock-fill me-1"></i>Monthly &amp; Yearly hidden
            </span>
            <?php else: ?>
            Daily · Weekly · Monthly · Yearly
            <?php endif; ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/reports/" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════
     SCREEN — SUMMARY OVERVIEW TABLE
     ═══════════════════════════════════════════════════════ -->
<div class="card border-0 mb-4 d-print-none">
    <div class="card-header bg-transparent border-bottom py-3">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-table me-2 text-primary"></i>Overview</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table align-middle mb-0" style="font-size:.9rem;">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3 py-3" style="width:120px;">Period</th>
                        <th class="py-3">Date Range</th>
                        <th class="py-3 text-end text-success">Income</th>
                        <?php if ($hasExpAccess): ?>
                        <th class="py-3 text-end text-danger">Expenses</th>
                        <th class="py-3 text-end">Net</th>
                        <?php endif; ?>
                        <th class="pe-3 py-3" style="width:120px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $maxInc = max(array_column($periods, 'income')) ?: 1;
                    $maxExp = max(array_column($periods, 'expenses')) ?: 1;
                    foreach ($periods as $key => $p):
                        $net    = $p['net'];
                        $netCls = $net > 0 ? 'text-success' : ($net < 0 ? 'text-danger' : 'text-secondary');
                        $pctInc = min(100, round($p['income']   / $maxInc * 100));
                        $pctExp = $hasExpAccess ? min(100, round($p['expenses'] / $maxExp * 100)) : 0;
                        $dateLabel = match($key) {
                            'daily'   => formatDate($p['from'], 'M d, Y'),
                            'weekly'  => formatDate($p['from'], 'M d') . ' – ' . formatDate($p['to'], 'M d, Y'),
                            'monthly' => date('F Y', strtotime($p['from'])),
                            'yearly'  => date('Y',   strtotime($p['from'])),
                        };
                    ?>
                    <tr>
                        <td class="ps-3">
                            <div class="d-flex align-items-center gap-2">
                                <span style="width:10px;height:10px;border-radius:50%;background:<?= $p['accent'] ?>;flex-shrink:0;display:inline-block;"></span>
                                <span class="fw-semibold"><?= $p['label'] ?></span>
                            </div>
                        </td>
                        <td class="small text-muted"><?= $dateLabel ?></td>
                        <td class="text-end fw-bold text-success fin-amount"><?= formatMoney($p['income']) ?></td>
                        <?php if ($hasExpAccess): ?>
                        <td class="text-end fw-bold text-danger fin-amount"><?= formatMoney($p['expenses']) ?></td>
                        <td class="text-end fw-bold fin-amount <?= $netCls ?>"><?= formatMoney($net) ?></td>
                        <?php endif; ?>
                        <td class="pe-3">
                            <div class="d-flex flex-column gap-1">
                                <div class="d-flex align-items-center gap-1">
                                    <span class="text-success" style="font-size:.65rem;width:44px;">Income</span>
                                    <div class="progress flex-grow-1" style="height:5px;">
                                        <div class="progress-bar bg-success" style="width:<?= $pctInc ?>%"></div>
                                    </div>
                                </div>
                                <?php if ($hasExpAccess): ?>
                                <div class="d-flex align-items-center gap-1">
                                    <span class="text-danger" style="font-size:.65rem;width:44px;">Expense</span>
                                    <div class="progress flex-grow-1" style="height:5px;">
                                        <div class="progress-bar bg-danger" style="width:<?= $pctExp ?>%"></div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ── Period Cards (screen) ─────────────────────────────── -->
<div class="row g-3 mb-4 d-print-none">
    <?php foreach ($periods as $key => $p):
        $net    = $p['net'];
        $netCls = $net >= 0 ? 'text-success' : 'text-danger';
        $netBg  = $net >= 0 ? '#d1e7dd' : '#f8d7da';
        $dateLabel = match($key) {
            'daily'   => formatDate($p['from'], 'M d, Y'),
            'weekly'  => formatDate($p['from'], 'M d') . ' – ' . formatDate($p['to'], 'M d, Y'),
            'monthly' => date('F Y', strtotime($p['from'])),
            'yearly'  => date('Y',   strtotime($p['from'])),
        };
    ?>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 fin-period-card h-100"
             style="border-left-color:<?= $p['accent'] ?> !important;">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <div style="width:36px;height:36px;border-radius:8px;background:<?= $p['accent'] ?>1a;
                                display:flex;align-items:center;justify-content:center;">
                        <i class="bi <?= $p['icon'] ?>" style="color:<?= $p['accent'] ?>;font-size:1rem;"></i>
                    </div>
                    <div>
                        <div class="fw-bold"><?= $p['label'] ?></div>
                        <div class="text-muted" style="font-size:.72rem;"><?= $dateLabel ?></div>
                    </div>
                </div>

                <div class="mb-2">
                    <div class="text-muted small">Income</div>
                    <div class="fw-bold text-success fin-amount" style="font-size:1.15rem;">
                        <?= formatMoney($p['income']) ?>
                    </div>
                    <div class="text-muted" style="font-size:.72rem;">
                        <?= number_format($p['payment_count']) ?> payment<?= $p['payment_count'] != 1 ? 's' : '' ?>
                    </div>
                </div>

                <?php if ($hasExpAccess): ?>
                <div class="mb-3">
                    <div class="text-muted small">Expenses</div>
                    <div class="fw-bold text-danger fin-amount" style="font-size:1.15rem;">
                        <?= formatMoney($p['expenses']) ?>
                    </div>
                </div>
                <div class="rounded px-3 py-2" style="background:<?= $netBg ?>;">
                    <div class="small" style="opacity:.7;">Net</div>
                    <div class="fw-bold fin-amount fs-5 <?= $netCls ?>">
                        <?= formatMoney($net) ?>
                    </div>
                    <?php if ($p['income'] > 0): ?>
                    <div style="font-size:.7rem;opacity:.7;">
                        <?= number_format(abs($net) / $p['income'] * 100, 1) ?>% <?= $net >= 0 ? 'margin' : 'loss ratio' ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php include BASE_PATH . '/includes/footer.php'; ?>
