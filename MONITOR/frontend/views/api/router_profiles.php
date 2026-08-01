<?php
ob_start();
require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/lib/RouterosAPI.php';
requireMinRole(ROLE_ADMIN);
ob_end_clean();
ini_set('display_errors', '0'); // never leak PHP error HTML into a JSON response
header('Content-Type: application/json; charset=utf-8');

$routerId = (int)($_GET['router_id'] ?? 0);
$type     = $_GET['type'] ?? 'ppp'; // ppp | hotspot | both

if (!$routerId) {
    echo json_encode(['success' => false, 'profiles' => []]);
    exit;
}

$row = db()->prepare("SELECT host, api_port, port, username, password, status, COALESCE(auth_type,'local') AS auth_type FROM routers WHERE router_id = ?");
$row->execute([$routerId]);
$router = $row->fetch();

if (!$router) {
    echo json_encode(['success' => false, 'error' => 'Router not found', 'profiles' => []]);
    exit;
}
if (($router['status'] ?? '') !== ROUTER_ONLINE) {
    echo json_encode(['success' => false, 'error' => 'Router is not connected', 'profiles' => []]);
    exit;
}

@set_time_limit(10);
$api = new RouterosAPI($router['host'], (int)($router['api_port'] ?: $router['port']), 3);
if (!$api->connect($router['username'], decryptData($router['password']))) {
    echo json_encode(['success' => false, 'error' => 'Cannot connect to router', 'profiles' => []]);
    exit;
}

$profiles = [];

if ($type === 'radius' || $router['auth_type'] === 'radius') {
    // getUserManagerGroups() auto-detects /user-manager (ROS v7) vs /tool/user-manager (ROS v6)
    $rows = $api->getUserManagerGroups();
    foreach ($rows as $r) {
        if (!empty($r['name'])) $profiles[] = $r['name'];
    }
    // Fallback: some ROS 7.x builds use /user-manager/profile instead of /user-manager/user/group
    if (empty($profiles)) {
        $rows = $api->query($api->umPrefix() . '/profile/print');
        foreach ($rows as $r) {
            if (!empty($r['name'])) $profiles[] = $r['name'];
        }
    }
} else {
    if ($type === 'ppp' || $type === 'both') {
        $rows = $api->getPPPProfiles();
        foreach ($rows as $r) {
            if (!empty($r['name'])) $profiles[] = $r['name'];
        }
    }

    if ($type === 'hotspot' || $type === 'both') {
        $rows = $api->getHotspotUserProfiles();
        foreach ($rows as $r) {
            if (!empty($r['name'])) $profiles[] = $r['name'];
        }
    }
}

$api->disconnect();

$profiles = array_values(array_unique($profiles));
sort($profiles);

echo json_encode(['success' => true, 'profiles' => $profiles, 'auth_type' => $router['auth_type']]);
