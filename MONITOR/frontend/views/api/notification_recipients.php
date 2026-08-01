<?php
ob_start();
require_once dirname(__DIR__) . '/config/config.php';
ob_end_clean();
ini_set('display_errors', '0'); // never leak PHP error HTML into a JSON response
header('Content-Type: application/json; charset=utf-8');

requireLogin();
if (!canSendMessages()) {
    jsonResponse(false, 'You are not allowed to view notification recipients.');
}
session_write_close();

$notifId = (int)($_GET['notification_id'] ?? 0);
if (!$notifId) { jsonResponse(false, 'Missing notification_id'); }

$routerId = selectedRouterId();
$where = ['nr.notification_id = ?'];
$params = [$notifId];
if (currentRole() !== ROLE_SUPERADMIN || $routerId) {
    if (!$routerId) {
        jsonResponse(false, 'Router not selected');
    }
    $where[] = 'n.router_id = ?';
    $params[] = $routerId;
}
$whereSql = implode(' AND ', $where);

$stmt = db()->prepare("
    SELECT nr.contact_number, nr.status, nr.error_message,
           s.account_number, s.firstname, s.lastname
    FROM notification_recipients nr
    INNER JOIN notifications n ON n.notification_id = nr.notification_id
    LEFT JOIN subscribers s ON s.subscriber_id = nr.subscriber_id
    WHERE {$whereSql}
    ORDER BY nr.status DESC, s.account_number
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

jsonResponse(true, 'OK', ['data' => $rows]);
