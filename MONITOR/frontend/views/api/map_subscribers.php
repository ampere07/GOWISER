<?php
ob_start();
require_once dirname(__DIR__) . '/config/config.php';
ob_end_clean();
ini_set('display_errors', '0'); // never leak PHP error HTML into a JSON response
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

requireLogin();
session_write_close();

$routerId = selectedRouterId();

$where  = ['s.latitude IS NOT NULL', 's.longitude IS NOT NULL',
           's.latitude != 0',        's.longitude != 0',
           "s.status NOT IN ('pending','archived')"];
$params = [];

if ($routerId) {
    $where[]  = 's.router_id = ?';
    $params[] = $routerId;
}

$whereStr = implode(' AND ', $where);

$stmt = db()->prepare("
    SELECT s.subscriber_id, s.account_number,
           s.firstname, s.lastname,
           s.latitude, s.longitude,
           s.status, s.connection_type,
           s.barangay, s.municipality, s.street, s.address,
           s.subscription_end,
           p.title   AS plan_name,
           p.speed_mbps
    FROM subscribers s
    LEFT JOIN plans p ON p.plan_id = s.plan_id
    WHERE {$whereStr}
    ORDER BY s.status, s.lastname, s.firstname
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Summary counts for the same router scope (all subscribers, not just mapped)
$totalWhere = [];
$totalParams = [];
if ($routerId) {
    $totalWhere[] = 'router_id = ?';
    $totalParams[] = $routerId;
}
$totalWhereSql = $totalWhere ? 'WHERE ' . implode(' AND ', $totalWhere) : '';
$totalStmt = db()->prepare("
    SELECT status, COUNT(*) AS cnt FROM subscribers {$totalWhereSql} GROUP BY status
");
$totalStmt->execute($totalParams);
$totals = $totalStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$mappedByStatus = [];
foreach ($rows as $r) {
    $mappedByStatus[$r['status']] = ($mappedByStatus[$r['status']] ?? 0) + 1;
}

echo json_encode([
    'success'          => true,
    'subscribers'      => $rows,
    'total_mapped'     => count($rows),
    'totals_all'       => $totals,
    'mapped_by_status' => $mappedByStatus,
]);
