<?php
ob_start();
require_once dirname(__DIR__) . '/config/config.php';
ob_end_clean();
ini_set('display_errors', '0'); // never leak PHP error HTML into a JSON response
header('Content-Type: application/json; charset=utf-8');

requireLogin();
if (currentRole() !== ROLE_SUPERADMIN) {
    echo json_encode(['success' => false, 'message' => 'Only the Superadmin can switch routers']);
    exit;
}
verifyCsrf();

$routerId = (int)($_GET['router_id'] ?? 0);
if (!$routerId) {
    echo json_encode(['success' => false, 'message' => 'router_id required']);
    exit;
}

$stmt = db()->prepare("SELECT router_id, name FROM routers WHERE router_id = ?");
$stmt->execute([$routerId]);
$router = $stmt->fetch();

if (!$router) {
    echo json_encode(['success' => false, 'message' => 'Router not found']);
    exit;
}

setSelectedRouter((int)$router['router_id'], $router['name']);

echo json_encode(['success' => true, 'router_id' => (int)$router['router_id'], 'name' => $router['name']]);
