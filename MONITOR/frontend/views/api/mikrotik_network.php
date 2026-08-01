<?php
ob_start();
require_once dirname(__DIR__) . '/config/config.php';
ob_end_clean();
ini_set('display_errors', '0'); // never leak PHP error HTML into a JSON response
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

requireRole(ROLE_ADMIN, ROLE_SUPERADMIN);
require_once BASE_PATH . '/lib/RouterosAPI.php';

@set_time_limit(15);

$routerId = scopedRouterId((int)($_GET['router_id'] ?? 0));
if (!$routerId) {
    echo json_encode(['success' => false, 'message' => 'router_id required']);
    exit;
}

$stmt = db()->prepare("SELECT * FROM routers WHERE router_id = ?");
$stmt->execute([$routerId]);
$router = $stmt->fetch();

if (!$router) {
    echo json_encode(['success' => false, 'message' => 'Router not found']);
    exit;
}

try {
    $api = new RouterosAPI($router['host'], (int)($router['api_port'] ?: $router['port']), 5);
    if (!$api->connect($router['username'], decryptData($router['password']))) {
        echo json_encode(['success' => false, 'message' => $api->error]);
        exit;
    }

    $pools        = $api->getIPPools();
    $simpleQueues = $api->getSimpleQueues();
    $queueTree    = $api->getQueueTree();
    $api->disconnect();

    echo json_encode([
        'success'       => true,
        'ip_pools'      => $pools,
        'simple_queues' => $simpleQueues,
        'queue_tree'    => $queueTree,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
