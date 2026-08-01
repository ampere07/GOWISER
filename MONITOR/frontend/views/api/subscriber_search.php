<?php
ob_start();
require_once dirname(__DIR__) . '/config/config.php';
ob_end_clean();
ini_set('display_errors', '0'); // never leak PHP error HTML into a JSON response
header('Content-Type: application/json; charset=utf-8');

requireLogin();
requireMinRole(ROLE_ADMIN);
session_write_close();

$q        = trim($_GET['q'] ?? '');
$routerId = (int)($_GET['router_id'] ?? 0);

if (strlen($q) < 2) {
    jsonResponse(false, 'Query too short', ['data' => []]);
}

$where  = ["(s.account_number LIKE ? OR s.firstname LIKE ? OR s.lastname LIKE ?)"];
$params = ["%{$q}%", "%{$q}%", "%{$q}%"];

if ($routerId) {
    $where[]  = 's.router_id = ?';
    $params[] = $routerId;
}

$stmt = db()->prepare("
    SELECT s.subscriber_id, s.account_number, s.firstname, s.lastname, s.contact_number
    FROM subscribers s
    WHERE " . implode(' AND ', $where) . "
    ORDER BY s.account_number
    LIMIT 15
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

jsonResponse(true, 'OK', ['data' => $rows]);
