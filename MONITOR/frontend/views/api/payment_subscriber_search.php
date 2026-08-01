<?php
ob_start();
require_once dirname(__DIR__) . '/config/config.php';
ob_end_clean();
ini_set('display_errors', '0'); // never leak PHP error HTML into a JSON response
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

requireLogin();
requireRole(ROLE_CASHIER, ROLE_ADMIN, ROLE_SUPERADMIN);
session_write_close();

$q  = trim($_GET['q'] ?? '');
$id = (int)($_GET['id'] ?? 0);
$selectedRouterId = (int)(selectedRouterId() ?: 0);
$requestedRouterId = (int)($_GET['router_id'] ?? 0);
$routerId = $selectedRouterId > 0 ? $selectedRouterId : $requestedRouterId;

$cols = "
    SELECT s.subscriber_id, s.account_number, s.firstname, s.lastname,
           s.plan_id, s.subscription_start, s.subscription_end,
           p.amount AS plan_amount, p.billing_cycle,
           r.name AS router_name,
           (SELECT COALESCE(SUM(py2.amount),0)
            FROM payments py2
            INNER JOIN payment_types pt2 ON pt2.type_id = py2.payment_type_id
            WHERE py2.subscriber_id = s.subscriber_id AND py2.status = 'paid' AND pt2.is_default = 1
              AND (s.subscription_start IS NULL OR COALESCE(DATE(py2.period_start), DATE(py2.payment_date)) >= s.subscription_start)
           ) AS total_paid,
           (SELECT MIN(py3.payment_date)
            FROM payments py3
            INNER JOIN payment_types pt3 ON pt3.type_id = py3.payment_type_id
            WHERE py3.subscriber_id = s.subscriber_id AND py3.status = 'paid' AND pt3.is_default = 1
              AND (s.subscription_start IS NULL OR COALESCE(DATE(py3.period_start), DATE(py3.payment_date)) >= s.subscription_start)
           ) AS first_pay_date
    FROM subscribers s
    LEFT JOIN plans p ON p.plan_id = s.plan_id
    LEFT JOIN routers r ON r.router_id = s.router_id";

// Single subscriber by ID (used to restore selection on POST validation failure)
if ($id) {
    $routerSql = $routerId > 0 ? " AND s.router_id = ?" : "";
    $stmt = db()->prepare($cols . "
        WHERE s.subscriber_id = ? AND s.status IN ('active','suspended','expired')
        {$routerSql}
    ");
    $params = [$id];
    if ($routerId > 0) $params[] = $routerId;
    $stmt->execute($params);
    $row = $stmt->fetch();
    echo json_encode(['data' => $row ? [$row] : []]);
    exit;
}

if (strlen($q) < 2) {
    echo json_encode(['data' => []]);
    exit;
}

$like = '%' . $q . '%';
$routerSql = $routerId > 0 ? " AND s.router_id = ?" : "";
$stmt = db()->prepare($cols . "
    WHERE s.status IN ('active','suspended','expired')
      AND (s.account_number LIKE ? OR s.firstname LIKE ? OR s.lastname LIKE ?
           OR CONCAT(s.firstname,' ',s.lastname) LIKE ?)
      {$routerSql}
    ORDER BY s.account_number
    LIMIT 25
");
$params = [$like, $like, $like, $like];
if ($routerId > 0) $params[] = $routerId;
$stmt->execute($params);
$rows = $stmt->fetchAll();

echo json_encode(['data' => $rows, 'router_id' => $routerId ?: null]);
