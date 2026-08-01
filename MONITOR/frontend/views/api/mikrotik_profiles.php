<?php
ob_start();
require_once dirname(__DIR__) . '/config/config.php';
ob_end_clean();
ini_set('display_errors', '0'); // never leak PHP error HTML into a JSON response
header('Content-Type: application/json; charset=utf-8');

requireMinRole(ROLE_USER);
require_once BASE_PATH . '/lib/RouterosAPI.php';

$routerId = (int)($_GET['router_id'] ?? 0);
$type     = $_GET['type'] ?? 'ppp'; // 'ppp' or 'hotspot'

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

@set_time_limit(10);
try {
    $api = new RouterosAPI($router['host'], (int)($router['api_port'] ?: $router['port']), 3);
    if (!$api->connect($router['username'], decryptData($router['password']))) {
        echo json_encode(['success' => false, 'message' => 'Cannot connect: ' . $api->error]);
        exit;
    }

    $profiles = $type === 'hotspot'
        ? $api->getHotspotUserProfiles()
        : $api->getPPPProfiles();

    $api->disconnect();

    $names = array_map(fn($p) => $p['name'] ?? '', $profiles);
    $names = array_values(array_filter($names));

    echo json_encode(['success' => true, 'profiles' => $names]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
