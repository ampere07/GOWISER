<?php
ob_start();
require_once dirname(__DIR__) . '/config/config.php';
ob_end_clean();
ini_set('display_errors', '0'); // never leak PHP error HTML into a JSON response
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

requireLogin();
session_write_close(); // release session lock — read-only endpoint, no session writes needed
require_once BASE_PATH . '/lib/RouterosAPI.php';

$requestedRouterId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$routerId = $requestedRouterId > 0 ? scopedRouterId($requestedRouterId) : 0;

// Live TCP connect is available to roles that can open the router status dashboards.
$dashboardRoles = array_unique(array_merge(
    MODULE_ACCESS['dashboard'] ?? [],
    MODULE_ACCESS['dashboard2'] ?? []
));
$useLive = isset($_GET['live']) && in_array(currentRole(), $dashboardRoles, true);
// quick=1: lightweight live — CPU+counts only. user_counts=1 counts configured PPP/Hotspot/User Manager users.
$quick   = isset($_GET['quick']);
$useUserCounts = isset($_GET['user_counts']);

if ($useLive) {
    // quick mode: 3 s socket + lightweight calls. Full mode: 20 s socket + session/interface payloads.
    if ($quick) {
        @set_time_limit($useUserCounts ? 25 : 20);
    } else {
        $routerCount = $routerId > 0 ? 1 : (currentRole() === ROLE_SUPERADMIN ? (int)db()->query("SELECT COUNT(*) FROM routers")->fetchColumn() : 1);
        $perRouter   = $routerId > 0 ? 25 : 4;
        @set_time_limit(max(30, $routerCount * $perRouter + 10));
    }
}

try {
    if ($requestedRouterId > 0 && $routerId !== $requestedRouterId && currentRole() !== ROLE_SUPERADMIN) {
        jsonResponse(false, 'Router not found');
    }

    if ($routerId > 0) {
        $stmt = db()->prepare("SELECT * FROM routers WHERE router_id = ?");
        $stmt->execute([$routerId]);
        $router = $stmt->fetch();
        if (!$router) jsonResponse(false, 'Router not found');

        // quick+live → lightweight fetch (3 calls, 3 s socket). full live → all data. cached → DB only.
        $status            = $useLive ? getRouterStatusLive($router, !$quick, $useUserCounts) : getRouterStatusCached($router);
        $status['success'] = true;
        echo json_encode($status);
        exit;
    }

    // All routers for superadmin only; other roles are limited to their selected router.
    if (currentRole() === ROLE_SUPERADMIN) {
        $routers = db()->query("SELECT * FROM routers ORDER BY name")->fetchAll();
    } else {
        $routerId = selectedRouterId();
        if (!$routerId) jsonResponse(false, 'Router not selected');
        $stmt = db()->prepare("SELECT * FROM routers WHERE router_id = ? ORDER BY name");
        $stmt->execute([$routerId]);
        $routers = $stmt->fetchAll();
    }
    $result  = [];
    foreach ($routers as $router) {
        $result[] = $useLive ? getRouterStatusLive($router, false, $useUserCounts) : getRouterStatusCached($router);
    }
    echo json_encode(['success' => true, 'routers' => $result]);

} catch (PDOException $e) {
    jsonResponse(false, 'Database error');
}

// ── Cached mode: DB only, no TCP connection ───────────────────
function getRouterStatusCached(array $router): array {
    return [
        'router_id'      => $router['router_id'],
        'name'           => $router['name'],
        'host'           => $router['host'],
        'db_status'      => $router['status'],
        'online'         => $router['status'] === 'online',
        'is_cached'      => true,
        'last_sync'      => $router['last_sync'],
        'error'          => null,
        'identity'       => $router['name'],
        'cpu_load'       => isset($router['cpu_load'])       ? (int)$router['cpu_load']       : null,
        'uptime'         => null,
        'ppp_active'     => isset($router['ppp_active'])     ? (int)$router['ppp_active']     : null,
        'hotspot_active' => isset($router['hotspot_active']) ? (int)$router['hotspot_active'] : null,
        'radius_active'  => isset($router['radius_active'])  ? (int)$router['radius_active']  : null,
    ];
}

// ── Live mode: real TCP connection (admin only) ───────────────
function getRouterStatusLive(array $router, bool $full = true, bool $useUserCounts = false): array {
    $isRadius  = (($router['auth_type'] ?? 'local') === 'radius');
    $timeout   = $full ? 20 : (($useUserCounts || $isRadius) ? 8 : 3); // RADIUS/User Manager calls can take longer
    $api       = new RouterosAPI($router['host'], (int)($router['api_port'] ?: $router['port']), $timeout);
    $connected = $api->connect($router['username'], decryptData($router['password']));

    $base = [
        'router_id'   => $router['router_id'],
        'name'        => $router['name'],
        'host'        => $router['host'],
        'db_status'   => $router['status'],
        'online'      => $connected,
        'is_cached'   => false,
        'last_sync'   => appNow(),
        'error'       => $connected ? null : $api->error,
    ];

    if (!$connected) {
        db()->prepare("UPDATE routers SET status = 'offline', last_sync = ?, ppp_active = 0, hotspot_active = 0, radius_active = 0 WHERE router_id = ?")
            ->execute([appNow(), $router['router_id']]);
        $base['db_status'] = 'offline';
        return array_merge($base, [
            'ppp_active'     => 0,
            'hotspot_active' => 0,
            'radius_active'  => 0,
            'cpu_load'       => null,
            'uptime'         => null,
            'identity'       => $router['name'],
            'ppp_user_count'     => 0,
            'hotspot_user_count' => 0,
            'radius_user_count'  => 0,
        ]);
    }

    $result = array_merge($base, ['db_status' => 'online']);

    if ($full) {
        $resource   = $api->getSystemResource();
        $identity   = $api->getIdentity();
        $pppProplist = 'name,address,uptime,service,rx-bytes,tx-bytes,rx-byte,tx-byte,bytes-in,bytes-out,bytes';
        $hsProplist  = 'user,address,uptime,bytes-in,bytes-out,rx-bytes,tx-bytes,rx-byte,tx-byte,bytes';
        $pppActive  = $api->query('/ppp/active/print', ['.proplist' => $pppProplist]);
        $hsActive   = $api->query('/ip/hotspot/active/print', ['.proplist' => $hsProplist]);
        $interfaces = $api->getInterfaces();
        $cpuLoad    = isset($resource['cpu-load']) ? (int)$resource['cpu-load'] : null;
        $pppCnt     = count($pppActive);
        $hsCnt      = count($hsActive);

        $umSessions = [];
        if (($router['auth_type'] ?? 'local') === 'radius') {
            // Pre-load cached UM prefix so the detection round-trip is skipped on repeat requests
            if (array_key_exists('um_prefix', $router) && $router['um_prefix'] !== null) {
                $api->setUmPrefix($router['um_prefix']);
            }
            try { $umSessions = $api->getUserManagerActiveSessions(); } catch (Throwable $e) {}
        }
        $radiusCnt  = countUniqueRadiusSessions($umSessions, $pppActive, $hsActive);

        $pppActive = array_map(function (array $s): array {
            $bytes = routerosSessionBytes(
                $s,
                ['rx-bytes', 'bytes-in', 'rx-byte'],
                ['tx-bytes', 'bytes-out', 'tx-byte'],
                ['bytes']
            );
            $s['bytes-in']  = $bytes['bytes_in'];
            $s['bytes-out'] = $bytes['bytes_out'];
            return $s;
        }, $pppActive);

        $hsActive = array_map(function (array $s): array {
            $bytes = routerosSessionBytes(
                $s,
                ['bytes-in', 'rx-bytes', 'rx-byte'],
                ['bytes-out', 'tx-bytes', 'tx-byte'],
                ['bytes']
            );
            $username = trim((string)($s['user'] ?? ($s['username'] ?? ($s['name'] ?? ''))));
            if ($username !== '') {
                $s['user'] = $username;
                $s['username'] = $username;
                $s['name'] = $username;
            }
            $s['type'] = 'hotspot';
            $s['bytes-in']  = $bytes['bytes_in'];
            $s['bytes-out'] = $bytes['bytes_out'];
            return $s;
        }, $hsActive);

        $umSessions = array_map(function (array $s): array {
            $bytes = routerosSessionBytes(
                $s,
                ['acct-input-octets', 'input-octets', 'upload', 'upload-used', 'bytes-in', 'rx-bytes', 'rx-byte'],
                ['acct-output-octets', 'output-octets', 'download', 'download-used', 'bytes-out', 'tx-bytes', 'tx-byte'],
                ['bytes']
            );
            $s['bytes-in']  = $bytes['bytes_in'];
            $s['bytes-out'] = $bytes['bytes_out'];
            return $s;
        }, $umSessions);

        // Persist detected prefix so the next request skips the probe entirely
        if (($router['auth_type'] ?? 'local') === 'radius' && (!array_key_exists('um_prefix', $router) || $router['um_prefix'] === null)) {
            $detected = $api->getDetectedUmPrefix();
            if ($detected !== null) {
                db()->prepare("UPDATE routers SET um_prefix = ? WHERE router_id = ?")
                    ->execute([$detected, $router['router_id']]);
            }
        }

        db()->prepare("UPDATE routers SET status = 'online', last_sync = ?, cpu_load = ?, ppp_active = ?, hotspot_active = ?, radius_active = ? WHERE router_id = ?")
            ->execute([appNow(), $cpuLoad, $pppCnt, $hsCnt, $radiusCnt, $router['router_id']]);
        $result = array_merge($result, [
            'identity'         => $identity,
            'cpu_load'         => $cpuLoad,
            'uptime'           => $resource['uptime']       ?? null,
            'free_memory'      => $resource['free-memory']  ?? null,
            'total_memory'     => $resource['total-memory'] ?? null,
            'ros_version'      => $resource['version']      ?? null,
            'ppp_active'       => $pppCnt,
            'hotspot_active'   => $hsCnt,
            'radius_active'    => $radiusCnt,
            'ppp_sessions'     => $pppActive,
            'hotspot_sessions' => $hsActive,
            'radius_sessions'  => array_slice($umSessions, 0, 50),
            'interfaces'       => $interfaces,
        ]);
    } else {
        $resource  = $api->getSystemResource();
        $cpuLoad   = isset($resource['cpu-load']) ? (int)$resource['cpu-load'] : null;
        $identity  = $api->getIdentity();

        if ($useUserCounts) {
            $userCounts = getConfiguredMikrotikUserCounts($api, $router);
            $pppCnt     = isset($router['ppp_active']) ? (int)$router['ppp_active'] : 0;
            $hsCnt      = isset($router['hotspot_active']) ? (int)$router['hotspot_active'] : 0;
            $radiusCnt  = isset($router['radius_active']) ? (int)$router['radius_active'] : 0;
        } else {
            $activeCounts = getMikrotikActiveSessionCounts($api, $router);
            $pppCnt       = $activeCounts['ppp_active'];
            $hsCnt        = $activeCounts['hotspot_active'];
            $radiusCnt    = $activeCounts['radius_active'];
        }

        if ($useUserCounts) {
            db()->prepare("UPDATE routers SET status = 'online', last_sync = ?, cpu_load = ? WHERE router_id = ?")
                ->execute([appNow(), $cpuLoad, $router['router_id']]);
        } else {
            db()->prepare("UPDATE routers SET status = 'online', last_sync = ?, cpu_load = ?, ppp_active = ?, hotspot_active = ?, radius_active = ? WHERE router_id = ?")
                ->execute([appNow(), $cpuLoad, $pppCnt, $hsCnt, $radiusCnt, $router['router_id']]);
        }
        $result = array_merge($result, [
            'identity'       => $identity,
            'cpu_load'       => $cpuLoad,
            'uptime'         => $resource['uptime'] ?? null,
            'ppp_active'     => $pppCnt,
            'hotspot_active' => $hsCnt,
            'radius_active'  => $radiusCnt,
        ]);

        if ($useUserCounts) {
            $result = array_merge($result, $userCounts);
        }
    }

    $api->disconnect();
    return $result;
}

function getMikrotikActiveSessionCounts(RouterosAPI $api, array $router): array {
    $counts = [
        'ppp_active'     => 0,
        'hotspot_active' => 0,
        'radius_active'  => 0,
    ];

    $pppActive = [];
    $hsActive  = [];
    try {
        $pppActive = $api->query('/ppp/active/print', ['.proplist' => 'name']);
        $counts['ppp_active'] = count($pppActive);
    } catch (Throwable $e) {}

    try {
        $hsActive = $api->query('/ip/hotspot/active/print', ['.proplist' => 'user']);
        $counts['hotspot_active'] = count($hsActive);
    } catch (Throwable $e) {}

    if (($router['auth_type'] ?? 'local') === 'radius') {
        if (array_key_exists('um_prefix', $router) && $router['um_prefix'] !== null) {
            $api->setUmPrefix($router['um_prefix']);
        }

        try {
            $radiusActive = $api->getUserManagerActiveSessions();
            $counts['radius_active'] = countUniqueRadiusSessions($radiusActive, $pppActive, $hsActive);
        } catch (Throwable $e) {
            $counts['radius_active'] = isset($router['radius_active']) ? (int)$router['radius_active'] : 0;
        }

        persistDetectedUmPrefix($api, $router);
    }

    return $counts;
}

function countUniqueRadiusSessions(array $radiusActive, array $pppActive, array $hsActive): int {
    $seenUsers = [];
    foreach ($pppActive as $session) {
        $username = trim((string)($session['name'] ?? ''));
        if ($username !== '') $seenUsers[strtolower($username)] = true;
    }
    foreach ($hsActive as $session) {
        $username = trim((string)($session['user'] ?? ''));
        if ($username !== '') $seenUsers[strtolower($username)] = true;
    }

    $count = 0;
    foreach ($radiusActive as $session) {
        $username = trim((string)($session['user'] ?? ($session['username'] ?? '')));
        if ($username === '') continue;
        $key = strtolower($username);
        if (isset($seenUsers[$key])) continue;
        $seenUsers[$key] = true;
        $count++;
    }

    return $count;
}

function getConfiguredMikrotikUserCounts(RouterosAPI $api, array $router): array {
    $counts = [
        'ppp_user_count'     => 0,
        'hotspot_user_count' => 0,
        'radius_user_count'  => 0,
    ];

    try {
        $counts['ppp_user_count'] = count($api->query('/ppp/secret/print', ['.proplist' => '.id']));
    } catch (Throwable $e) {}

    try {
        $counts['hotspot_user_count'] = count($api->query('/ip/hotspot/user/print', ['.proplist' => '.id']));
    } catch (Throwable $e) {}

    if (array_key_exists('um_prefix', $router) && $router['um_prefix'] !== null) {
        $api->setUmPrefix($router['um_prefix']);
    }
    try {
        $prefix = $api->umPrefix();
        if ($prefix !== '') {
            $counts['radius_user_count'] = count($api->query($prefix . '/user/print', ['.proplist' => '.id']));
        }
    } catch (Throwable $e) {}

    persistDetectedUmPrefix($api, $router);

    return $counts;
}

function persistDetectedUmPrefix(RouterosAPI $api, array $router): void {
    if (array_key_exists('um_prefix', $router) && $router['um_prefix'] !== null) {
        return;
    }

    $detected = $api->getDetectedUmPrefix();
    if ($detected !== null) {
        db()->prepare("UPDATE routers SET um_prefix = ? WHERE router_id = ?")
            ->execute([$detected, $router['router_id']]);
    }
}
