<?php
ob_start();
require_once dirname(__DIR__) . '/config/config.php';
ob_end_clean();
ini_set('display_errors', '0'); // never leak PHP error HTML into a JSON response
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

requireLogin();
session_write_close();
require_once BASE_PATH . '/lib/RouterosAPI.php';

@set_time_limit(40);

$routerId = selectedRouterId();
if (!$routerId) {
    echo json_encode(['success' => false, 'message' => 'No router selected', 'online_ids' => [], 'total_online' => 0]);
    exit;
}

$stmt = db()->prepare("SELECT * FROM routers WHERE router_id = ?");
$stmt->execute([$routerId]);
$router = $stmt->fetch();

if (!$router) {
    echo json_encode(['success' => false, 'message' => 'Router not found', 'online_ids' => [], 'total_online' => 0]);
    exit;
}

try {
    $api = new RouterosAPI($router['host'], (int)($router['api_port'] ?: $router['port']), 10);
    if (!$api->connect($router['username'], decryptData($router['password']))) {
        echo json_encode([
            'success'      => false,
            'message'      => $api->error ?? 'Cannot connect to router',
            'online_ids'   => [],
            'total_online' => 0,
        ]);
        exit;
    }

    // ── 1. PPP local active sessions (ppp/active/print) ──────────
    $pppSessions     = $api->getPPPActive();

    // ── 2. Hotspot active sessions (ip/hotspot/active/print) ─────
    $hotspotSessions = $api->getHotspotActive();

    // ── 3. RADIUS / User Manager active sessions ──────────────────
    $radiusSessions  = $api->getUserManagerActiveSessions();

    $api->disconnect();

    // Collect unique usernames from all three sources
    $activeUsernames = [];

    foreach ($pppSessions as $s) {           // PPP local: field is "name"
        $u = trim($s['name'] ?? '');
        if ($u !== '') $activeUsernames[] = strtolower($u);
    }
    foreach ($hotspotSessions as $s) {       // Hotspot: field is "user" (fallback "name")
        $u = trim($s['user'] ?? ($s['name'] ?? ''));
        if ($u !== '') $activeUsernames[] = strtolower($u);
    }
    foreach ($radiusSessions as $s) {        // RADIUS/UM: field is "user" (fallback "username")
        $u = trim($s['user'] ?? ($s['username'] ?? ''));
        if ($u !== '') $activeUsernames[] = strtolower($u);
    }

    $activeUsernames = array_values(array_unique($activeUsernames));

    $sources = [
        'ppp'     => count($pppSessions),
        'hotspot' => count($hotspotSessions),
        'radius'  => count($radiusSessions),
    ];

    if (empty($activeUsernames)) {
        echo json_encode([
            'success'      => true,
            'online_ids'   => [],
            'total_online' => 0,
            'router_name'  => $router['name'],
            'sources'      => $sources,
        ]);
        exit;
    }

    // Match lower-cased usernames → subscriber_ids
    // LOWER() on ppp_username to make the match case-insensitive
    $placeholders = implode(',', array_fill(0, count($activeUsernames), '?'));
    $matchStmt = db()->prepare("
        SELECT subscriber_id
        FROM subscribers
        WHERE LOWER(ppp_username) IN ({$placeholders})
    ");
    $matchStmt->execute($activeUsernames);
    $onlineIds = $matchStmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'success'      => true,
        'online_ids'   => array_map('intval', $onlineIds),
        'total_online' => count($onlineIds),
        'router_name'  => $router['name'],
        'sources'      => $sources,
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success'      => false,
        'message'      => $e->getMessage(),
        'online_ids'   => [],
        'total_online' => 0,
    ]);
}
