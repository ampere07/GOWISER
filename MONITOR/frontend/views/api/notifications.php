<?php
ob_start();
require_once dirname(__DIR__) . '/config/config.php';
ob_end_clean();
ini_set('display_errors', '0'); // never leak PHP error HTML into a JSON response
header('Content-Type: application/json; charset=utf-8');

requireLogin();
session_write_close(); // read-only endpoint — release lock so other requests aren't blocked

$today     = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$tomorrow  = date('Y-m-d', strtotime('+1 day'));

$routerId  = selectedRouterId();
$rWhere    = $routerId ? " AND router_id = ?" : "";
$rP        = $routerId ? [$routerId] : [];

$stmt = db()->prepare("
    SELECT DATE(subscription_end) AS exp_day, COUNT(*) AS cnt
    FROM subscribers
    WHERE DATE(subscription_end) IN (?, ?, ?)
    {$rWhere}
    GROUP BY exp_day
");
$stmt->execute([$yesterday, $today, $tomorrow, ...$rP]);
$byDay = [];
foreach ($stmt->fetchAll() as $row) {
    $byDay[$row['exp_day']] = (int)$row['cnt'];
}

$expStmt = db()->prepare(
    "SELECT COUNT(*) FROM subscribers WHERE status = 'expired' {$rWhere}"
);
$expStmt->execute($rP);
$totalExpired = (int)$expStmt->fetchColumn();

$groups = [];
if (!empty($byDay[$yesterday])) {
    $groups[] = ['key' => 'yesterday', 'label' => 'expired yesterday', 'count' => $byDay[$yesterday], 'color' => 'secondary'];
}
if (!empty($byDay[$today])) {
    $groups[] = ['key' => 'today',     'label' => 'expiring today',    'count' => $byDay[$today],     'color' => 'danger'];
}
if (!empty($byDay[$tomorrow])) {
    $groups[] = ['key' => 'tomorrow',  'label' => 'expiring tomorrow', 'count' => $byDay[$tomorrow],  'color' => 'warning'];
}

$badge = array_sum(array_column($groups, 'count'));

jsonResponse(true, 'OK', [
    'groups'        => $groups,
    'total_expired' => $totalExpired,
    'badge'         => $badge,
]);
