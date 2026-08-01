<?php
ob_start();
require_once dirname(__DIR__) . '/config/config.php';
ob_end_clean();
ini_set('display_errors', '0'); // never leak PHP error HTML into a JSON response
header('Content-Type: application/json; charset=utf-8');

requireLogin();
session_write_close();

$isLineman    = hasRole(ROLE_LINEMAN);
$isCashierOnly = currentRole() === ROLE_CASHIER;

// Geo filter for barangay widget
$geoRegion    = trim($_GET['geo_region']    ?? '');
$geoProv      = trim($_GET['geo_prov']      ?? '');
$geoMuni      = trim($_GET['geo_muni']      ?? '');
$period       = $_GET['period']             ?? 'monthly';
$branchPeriod = $_GET['branch_period']      ?? 'monthly';
$branchYear   = (int)($_GET['branch_year']  ?? date('Y'));
$kpiPeriod    = in_array($_GET['kpi_period'] ?? '', ['daily','weekly','monthly','yearly']) ? $_GET['kpi_period'] : 'daily';

// Cashier may only query daily/weekly periods — clamp any attempts to request wider data
if ($isCashierOnly) {
    if (!in_array($period,       ['daily', 'weekly'])) $period       = 'daily';
    if (!in_array($branchPeriod, ['daily', 'weekly'])) $branchPeriod = 'daily';
    if (!in_array($kpiPeriod,    ['daily', 'weekly'])) $kpiPeriod    = 'daily';
}

// 30-second file cache — role included in key to prevent cross-role data leakage
$cacheDir  = BASE_PATH . '/storage/cache';
$routerId  = selectedRouterId();
$userRole  = currentRole();
$cacheKey  = md5(implode('|', ['v4', $routerId, $period, $branchPeriod, $branchYear, $geoRegion, $geoProv, $geoMuni, $kpiPeriod, $userRole]));
$cacheFile = $cacheDir . '/dash_' . $cacheKey . '.json';
$forceRefresh = isset($_GET['_refresh']);

if (!$forceRefresh && file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 30) {
    echo file_get_contents($cacheFile);
    exit;
}

try {
    $pdo = db();

    // ── PHP-timezone date anchors (never use CURDATE()/NOW() in SQL) ──────
    $today     = appToday();                                         // Y-m-d
    $thisYear  = (int)date('Y');
    $thisMonth = (int)date('n');
    $plus3d    = date('Y-m-d', strtotime('+3 days'));
    $plus7d    = date('Y-m-d', strtotime('+7 days'));
    $minus7d   = date('Y-m-d', strtotime('-7 days'));
    $minus30d  = date('Y-m-d', strtotime('-30 days'));
    $minus12w  = date('Y-m-d', strtotime('-84 days'));               // 12 weeks
    $minus12m  = date('Y-m-d', strtotime('-12 months'));
    $minus30dDt = date('Y-m-d H:i:s', strtotime('-30 days'));        // for created_at

    // Router scope — superadmin session value scopes all queries below
    $rJoin   = $routerId ? " JOIN subscribers s ON s.subscriber_id = py.subscriber_id" : "";
    $rWhere  = $routerId ? " AND s.router_id = ?" : "";
    $rOwhere = $routerId ? " AND router_id = ?" : "";
    $rP      = $routerId ? [$routerId] : [];
    $eWhere  = $routerId ? " AND e.router_id = ?" : "";
    $eP      = $routerId ? [$routerId] : [];

    // ── Subscriber counts + expiry metrics ────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*)                                                                    AS total,
            SUM(status = 'active')                                                     AS active,
            SUM(status = 'suspended')                                                  AS suspended,
            SUM(status = 'expired')                                                    AS expired,
            SUM(status = 'pending')                                                    AS pending,
            SUM(status = 'active' AND subscription_end BETWEEN ? AND ?)                AS expiring_3,
            SUM(status = 'active' AND subscription_end BETWEEN ? AND ?)                AS expiring_7,
            SUM(created_at >= ?)                                                        AS new_30day
        FROM subscribers
        WHERE 1=1 {$rOwhere}
    ");
    $stmt->execute(array_merge([$today, $plus3d, $today, $plus7d, $minus30dDt], $rP));
    $subRow = $stmt->fetch();

    $subCounts = $subRow;
    $expiring3 = (int)$subRow['expiring_3'];
    $expiring7 = (int)$subRow['expiring_7'];
    $newSubs30 = (int)$subRow['new_30day'];

    // Financial defaults — overridden below for non-lineman roles
    $revToday = $revMonth = 0.0;
    $monthlyRev = $monthlyExp = [];
    $recentPayments = [];
    $branchReports  = []; $branchYears = [(int)date('Y')]; $periodLabel = '';
    $kpiInc    = ['income'=>0,'cnt'=>0,'office_income'=>0,'office_count'=>0,'portal_income'=>0,'portal_count'=>0];
    $kpiOfficeByType = [];
    $kpiExpRow = ['expenses'=>0,'cnt'=>0];

    if (!$isLineman):

    // ── Revenue today + this month ─────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN DATE(py.payment_date) = ? THEN py.amount ELSE 0 END), 0) AS today,
            COALESCE(SUM(py.amount), 0)                                                      AS month
        FROM payments py{$rJoin}
        WHERE py.status = 'paid'
          AND YEAR(py.payment_date)  = ?
          AND MONTH(py.payment_date) = ?
          {$rWhere}
    ");
    $stmt->execute(array_merge([$today, $thisYear, $thisMonth], $rP));
    $revRow   = $stmt->fetch();
    $revToday = (float)$revRow['today'];
    $revMonth = (float)$revRow['month'];

    // ── Revenue chart ─────────────────────────────────────────────────────
    switch ($period) {
        case 'daily':
            $stmt = $pdo->prepare("
                SELECT DATE_FORMAT(py.payment_date, '%b %d') AS month, SUM(py.amount) AS revenue
                FROM payments py{$rJoin}
                WHERE py.status = 'paid' AND py.payment_date >= ?
                  {$rWhere}
                GROUP BY DATE(py.payment_date)
                ORDER BY DATE(py.payment_date) ASC
            ");
            $stmt->execute(array_merge([$minus30d], $rP));
            break;
        case 'weekly':
            $stmt = $pdo->prepare("
                SELECT CONCAT('Wk', LPAD(WEEK(py.payment_date,3),2,'0'), ' ', YEAR(py.payment_date)) AS month,
                       SUM(py.amount) AS revenue
                FROM payments py{$rJoin}
                WHERE py.status = 'paid' AND py.payment_date >= ?
                  {$rWhere}
                GROUP BY YEAR(py.payment_date), WEEK(py.payment_date,3)
                ORDER BY YEAR(py.payment_date) ASC, WEEK(py.payment_date,3) ASC
            ");
            $stmt->execute(array_merge([$minus12w], $rP));
            break;
        case 'yearly':
            $stmt = $pdo->prepare("
                SELECT YEAR(py.payment_date) AS month, SUM(py.amount) AS revenue
                FROM payments py{$rJoin}
                WHERE py.status = 'paid'
                  {$rWhere}
                GROUP BY YEAR(py.payment_date)
                ORDER BY month ASC
                LIMIT 10
            ");
            $stmt->execute($rP);
            break;
        default: // monthly
            $stmt = $pdo->prepare("
                SELECT DATE_FORMAT(py.payment_date, '%Y-%m') AS month, SUM(py.amount) AS revenue
                FROM payments py{$rJoin}
                WHERE py.status = 'paid'
                  AND py.payment_date >= ?
                  {$rWhere}
                GROUP BY month
                ORDER BY month ASC
            ");
            $stmt->execute(array_merge([$minus12m], $rP));
    }
    $monthlyRev = $stmt->fetchAll();

    // ── Expenses chart ────────────────────────────────────────────────────
    switch ($period) {
        case 'daily':
            $expPeriodWhere = expensePeriodTypeSql('daily', 'e');
            $expChartStmt = $pdo->prepare("
                SELECT DATE_FORMAT(e.expense_date, '%b %d') AS month, SUM(e.amount) AS expenses
                FROM expenses e
                WHERE e.expense_date >= ?
                  {$expPeriodWhere}
                  {$eWhere}
                GROUP BY DATE(e.expense_date)
                ORDER BY DATE(e.expense_date) ASC
            ");
            $expChartStmt->execute(array_merge([$minus30d], $eP));
            break;
        case 'weekly':
            $expPeriodWhere = expensePeriodTypeSql('weekly', 'e');
            $expChartStmt = $pdo->prepare("
                SELECT CONCAT('Wk', LPAD(WEEK(e.expense_date,3),2,'0'), ' ', YEAR(e.expense_date)) AS month,
                       SUM(e.amount) AS expenses
                FROM expenses e
                WHERE e.expense_date >= ?
                  {$expPeriodWhere}
                  {$eWhere}
                GROUP BY YEAR(e.expense_date), WEEK(e.expense_date,3)
                ORDER BY YEAR(e.expense_date) ASC, WEEK(e.expense_date,3) ASC
            ");
            $expChartStmt->execute(array_merge([$minus12w], $eP));
            break;
        case 'yearly':
            $expPeriodWhere = expensePeriodTypeSql('yearly', 'e');
            $expChartStmt = $pdo->prepare("
                SELECT YEAR(e.expense_date) AS month, SUM(e.amount) AS expenses
                FROM expenses e
                WHERE 1=1
                  {$expPeriodWhere}
                  {$eWhere}
                GROUP BY YEAR(e.expense_date)
                ORDER BY month ASC
                LIMIT 10
            ");
            $expChartStmt->execute($eP);
            break;
        default: // monthly
            $expPeriodWhere = expensePeriodTypeSql('monthly', 'e');
            $expChartStmt = $pdo->prepare("
                SELECT DATE_FORMAT(e.expense_date, '%Y-%m') AS month, SUM(e.amount) AS expenses
                FROM expenses e
                WHERE e.expense_date >= ?
                  {$expPeriodWhere}
                  {$eWhere}
                GROUP BY month
                ORDER BY month ASC
            ");
            $expChartStmt->execute(array_merge([$minus12m], $eP));
    }
    $monthlyExp = $expChartStmt->fetchAll();

    // ── Recent payments ───────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT py.payment_id, py.amount, py.method, py.payment_date, py.status,
               s.firstname, s.lastname, s.account_number
        FROM payments py
        JOIN subscribers s ON s.subscriber_id = py.subscriber_id
        WHERE 1=1 {$rWhere}
        ORDER BY py.created_at DESC
        LIMIT 10
    ");
    $stmt->execute($rP);
    $recentPayments = $stmt->fetchAll();

    endif; // !$isLineman (revenue/expense charts + recent payments)

    // ── Plans distribution ────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT p.title, COUNT(s.subscriber_id) AS cnt
        FROM plans p
        LEFT JOIN subscribers s ON s.plan_id = p.plan_id
            AND s.status = 'active'
            " . ($routerId ? "AND s.router_id = ?" : "") . "
        GROUP BY p.plan_id, p.title
        ORDER BY cnt DESC
        LIMIT 10
    ");
    $stmt->execute($rP);
    $plansDist = $stmt->fetchAll();

    // ── Recent activity ───────────────────────────────────────────────────
    $recentActivity = $pdo->query("
        SELECT al.action, al.module, al.description, al.logged_at,
               u.firstname, u.lastname
        FROM activity_log al
        LEFT JOIN users u ON u.user_id = al.user_id
        ORDER BY al.logged_at DESC
        LIMIT 10
    ")->fetchAll();

    // ── Top 10 barangays ──────────────────────────────────────────────────
    $brgWhere  = ["barangay IS NOT NULL", "barangay <> ''"];
    $brgParams = [];
    if ($routerId)  { $brgWhere[] = 'router_id = ?';    $brgParams[] = $routerId; }
    if ($geoRegion) { $brgWhere[] = 'region = ?';       $brgParams[] = $geoRegion; }
    if ($geoProv)   { $brgWhere[] = 'province = ?';     $brgParams[] = $geoProv; }
    if ($geoMuni)   { $brgWhere[] = 'municipality = ?'; $brgParams[] = $geoMuni; }
    $brgWhereStr = implode(' AND ', $brgWhere);
    $stmt = $pdo->prepare("
        SELECT barangay, municipality, province,
               COUNT(*) AS cnt,
               SUM(status = 'active')  AS active_count,
               SUM(status = 'expired') AS expired_count
        FROM subscribers
        WHERE {$brgWhereStr}
        GROUP BY barangay, municipality, province
        ORDER BY cnt DESC
        LIMIT 10
    ");
    $stmt->execute($brgParams);
    $topBarangays = $stmt->fetchAll();

    // ── Router stats ──────────────────────────────────────────────────────
    $routerStats = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(status = 'online')  AS online,
            SUM(status = 'offline') AS offline
        FROM routers
    ")->fetch();

    if (!$isLineman):

    // ── Router collection report ──────────────────────────────────────────
    switch ($branchPeriod) {
        case 'daily':
            $periodWhere  = "AND DATE(py.payment_date) = ?";
            $periodLabel  = 'Today (' . date('M d, Y') . ')';
            $branchParams = [$today];
            break;
        case 'weekly':
            $periodWhere  = "AND py.payment_date >= ?";
            $periodLabel  = 'Last 7 Days';
            $branchParams = [$minus7d];
            break;
        case 'yearly':
            $periodWhere  = "AND YEAR(py.payment_date) = ?";
            $periodLabel  = (string)$branchYear;
            $branchParams = [$branchYear];
            break;
        default: // monthly
            $periodWhere  = "AND YEAR(py.payment_date) = ? AND MONTH(py.payment_date) = ?";
            $periodLabel  = date('F Y');
            $branchParams = [$thisYear, $thisMonth];
    }

    $branchStmt = $pdo->prepare("
        SELECT r.router_id, r.name AS branch_name, r.address,
               r.region, r.province, r.municipality,
               COALESCE(SUM(py.amount), 0) AS collection,
               COUNT(DISTINCT s.subscriber_id) AS subscriber_count
        FROM routers r
        LEFT JOIN subscribers s ON s.router_id = r.router_id
        LEFT JOIN payments py ON py.subscriber_id = s.subscriber_id
            AND py.status = 'paid'
            {$periodWhere}
        GROUP BY r.router_id, r.name, r.address, r.region, r.province, r.municipality
        ORDER BY collection DESC
    ");
    $branchStmt->execute($branchParams);
    $branchReports = $branchStmt->fetchAll();

    // ── Available years for yearly filter ────────────────────────────────
    $branchYears = $pdo->query("
        SELECT DISTINCT YEAR(payment_date) AS yr FROM payments
        WHERE status = 'paid' ORDER BY yr DESC
    ")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array(date('Y'), $branchYears)) array_unshift($branchYears, (int)date('Y'));

    // ── Financial KPI ─────────────────────────────────────────────────────
    switch ($kpiPeriod) {
        case 'weekly':
            $kpiPayWhere  = "py.payment_date >= ?";
            $kpiExpWhere  = "e.expense_date >= ?";
            $kpiPayParams = [$minus7d];
            $kpiExpParams = [$minus7d];
            break;
        case 'monthly':
            $kpiPayWhere  = "YEAR(py.payment_date) = ? AND MONTH(py.payment_date) = ?";
            $kpiExpWhere  = "YEAR(e.expense_date) = ? AND MONTH(e.expense_date) = ?";
            $kpiPayParams = [$thisYear, $thisMonth];
            $kpiExpParams = [$thisYear, $thisMonth];
            break;
        case 'yearly':
            $kpiPayWhere  = "YEAR(py.payment_date) = ?";
            $kpiExpWhere  = "YEAR(e.expense_date) = ?";
            $kpiPayParams = [$thisYear];
            $kpiExpParams = [$thisYear];
            break;
        default: // daily
            $kpiPayWhere  = "DATE(py.payment_date) = ?";
            $kpiExpWhere  = "DATE(e.expense_date) = ?";
            $kpiPayParams = [$today];
            $kpiExpParams = [$today];
    }

    $kpiIncStmt = $pdo->prepare("
        SELECT COALESCE(SUM(py.amount),0) AS income,
               COUNT(*) AS cnt,
               COALESCE(SUM(CASE
                   WHEN UPPER(COALESCE(pm.remark, '')) LIKE '%PORTAL%' THEN py.amount
                   ELSE 0
               END),0) AS portal_income,
               SUM(CASE
                   WHEN UPPER(COALESCE(pm.remark, '')) LIKE '%PORTAL%' THEN 1
                   ELSE 0
               END) AS portal_count,
               COALESCE(SUM(CASE
                   WHEN UPPER(COALESCE(pm.remark, '')) LIKE '%PORTAL%' THEN 0
                   ELSE py.amount
               END),0) AS office_income,
               SUM(CASE
                   WHEN UPPER(COALESCE(pm.remark, '')) LIKE '%PORTAL%' THEN 0
                   ELSE 1
               END) AS office_count
        FROM payments py{$rJoin}
        LEFT JOIN payment_methods pm
          ON UPPER(pm.code COLLATE utf8mb4_unicode_ci) = UPPER(py.method COLLATE utf8mb4_unicode_ci)
        WHERE py.status = 'paid' AND {$kpiPayWhere}{$rWhere}
    ");
    $kpiIncStmt->execute(array_merge($kpiPayParams, $rP));
    $kpiInc = $kpiIncStmt->fetch();

    if ($kpiPeriod === 'daily') {
        $kpiOfficeTypeStmt = $pdo->prepare("
            SELECT COALESCE(NULLIF(pt.name, ''), 'Subscription') AS type_name,
                   COUNT(*) AS cnt,
                   COALESCE(SUM(py.amount), 0) AS total
            FROM payments py{$rJoin}
            LEFT JOIN payment_types pt ON pt.type_id = py.payment_type_id
            LEFT JOIN payment_methods pm
              ON UPPER(pm.code COLLATE utf8mb4_unicode_ci) = UPPER(py.method COLLATE utf8mb4_unicode_ci)
            WHERE py.status = 'paid'
              AND {$kpiPayWhere}{$rWhere}
              AND UPPER(COALESCE(pm.remark, '')) NOT LIKE '%PORTAL%'
            GROUP BY COALESCE(NULLIF(pt.name, ''), 'Subscription')
            ORDER BY total DESC, type_name ASC
        ");
        $kpiOfficeTypeStmt->execute(array_merge($kpiPayParams, $rP));
        $kpiOfficeByType = $kpiOfficeTypeStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $kpiExpPeriodWhere = expensePeriodTypeSql($kpiPeriod, 'e');
    $kpiExpStmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.amount),0) AS expenses, COUNT(*) AS cnt
        FROM expenses e WHERE {$kpiExpWhere}{$kpiExpPeriodWhere}{$eWhere}
    ");
    $kpiExpStmt->execute(array_merge($kpiExpParams, $eP));
    $kpiExpRow = $kpiExpStmt->fetch();

    endif; // !$isLineman (router collection + financial KPI)

    $out = json_encode([
        'success'         => true,
        'subscribers'     => $subCounts,
        'revenue_month'   => $revMonth,
        'revenue_today'   => $revToday,
        'expiring_3day'   => $expiring3,
        'expiring_7day'   => $expiring7,
        'new_subs_30day'  => $newSubs30,
        'monthly_revenue'  => $monthlyRev,
        'monthly_expenses' => $monthlyExp,
        'plans_dist'      => $plansDist,
        'recent_payments' => $recentPayments,
        'recent_activity' => $recentActivity,
        'routers'         => $routerStats,
        'top_barangays'   => $topBarangays,
        'branch_reports'  => $branchReports,
        'branch_years'    => $branchYears,
        'branch_year'     => $branchYear,
        'branch_period'   => $branchPeriod,
        'branch_label'    => $periodLabel,
        'router_id'          => $routerId,
        'router_name'        => selectedRouterName(),
        'kpi_income'         => (float)$kpiInc['income'],
        'kpi_income_count'   => (int)$kpiInc['cnt'],
        'kpi_office_income'  => (float)$kpiInc['office_income'],
        'kpi_office_count'   => (int)$kpiInc['office_count'],
        'kpi_office_by_type' => array_map(static fn($row) => [
            'type_name' => $row['type_name'] ?? 'Subscription',
            'total'     => (float)($row['total'] ?? 0),
            'count'     => (int)($row['cnt'] ?? 0),
        ], $kpiOfficeByType),
        'kpi_portal_income'  => (float)$kpiInc['portal_income'],
        'kpi_portal_count'   => (int)$kpiInc['portal_count'],
        'kpi_expenses'       => (float)$kpiExpRow['expenses'],
        'kpi_expenses_count' => (int)$kpiExpRow['cnt'],
        'kpi_net'            => (float)$kpiInc['income'] - (float)$kpiExpRow['expenses'],
        'kpi_period'         => $kpiPeriod,
    ]);
    @file_put_contents($cacheFile, $out);
    echo $out;

} catch (PDOException $e) {
    jsonResponse(false, 'Stats error: ' . (APP_DEBUG ? $e->getMessage() : 'DB error'));
}
