<?php
ob_start();
require_once dirname(__DIR__) . '/config/config.php';
ob_end_clean();
ini_set('display_errors', '0'); // never leak PHP error HTML into a JSON response
header('Content-Type: application/json; charset=utf-8');

requireRole(ROLE_LINEMAN, ROLE_CASHIER, ROLE_USER, ROLE_ADMIN, ROLE_SUPERADMIN);
session_write_close(); // release session lock before long-running router connection
require_once BASE_PATH . '/lib/RouterosAPI.php';

$routerId = scopedRouterId((int)($_GET['router_id'] ?? 0));
if (!$routerId) {
    echo json_encode(['success' => false, 'message' => 'No router selected']);
    exit;
}

$limit  = max(10, min(500, (int)($_GET['limit']  ?? 100)));
$offset = max(0, (int)($_GET['offset'] ?? 0));
$search = strtolower(trim($_GET['search'] ?? ''));

$stmt = db()->prepare("SELECT * FROM routers WHERE router_id = ?");
$stmt->execute([$routerId]);
$router = $stmt->fetch();
if (!$router) {
    echo json_encode(['success' => false, 'message' => 'Router not found']);
    exit;
}

// ── File cache (20 s) — shared across concurrent pollers ─────────
$cacheFile = BASE_PATH . '/storage/cache/bw_v2_' . $routerId . '.json';
$cacheAge  = 5;
$sessions  = null;

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheAge) {
    $cached = json_decode(file_get_contents($cacheFile), true);
    if (is_array($cached)) {
        $sessions = $cached['sessions'];
        $ts       = $cached['ts'];
    }
}

if ($sessions === null) {
    // ── Live fetch from router ────────────────────────────────────
    @set_time_limit(30);
    $api = new RouterosAPI($router['host'], (int)($router['api_port'] ?: $router['port']), 20);
    if (!$api->connect($router['username'], decryptData($router['password']))) {
        echo json_encode(['success' => false, 'message' => $api->error ?? 'Connection failed']);
        exit;
    }

    // Only fetch the fields we need — drastically reduces data volume
    // PPP may expose rx/tx counters or a combined bytes=upload/download pair.
    // Hotspot usually exposes bytes-in/bytes-out directly.
    $pppProplist = 'name,address,uptime,rx-bytes,tx-bytes,rx-byte,tx-byte,bytes-in,bytes-out,bytes';
    $hsProplist  = 'user,address,uptime,bytes-in,bytes-out,rx-bytes,tx-bytes,rx-byte,tx-byte,bytes';
    $isRadius    = (($router['auth_type'] ?? 'local') === 'radius');

    try {
        $pppActive    = $api->query('/ppp/active/print',        ['.proplist' => $pppProplist]);
        $hsActive     = $api->query('/ip/hotspot/active/print', ['.proplist' => $hsProplist]);
        $radiusActive = [];
        if ($isRadius) {
            // getUserManagerActiveSessions() auto-detects /user-manager (ROS v7)
            // vs /tool/user-manager (ROS v6) and filters to active-only rows
            $radiusActive = $api->getUserManagerActiveSessions();
        }
        $api->disconnect();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Router error: ' . $e->getMessage()]);
        exit;
    }

    // ── Collect all usernames to look up plan speed from DB ───────
    $usernames = [];
    foreach ($pppActive    as $s) { if ($s['name'] ?? '') $usernames[] = $s['name']; }
    foreach ($hsActive     as $s) { $u = $s['user'] ?? ''; if ($u) $usernames[] = $u; }
    foreach ($radiusActive as $s) { $u = $s['user'] ?? ($s['username'] ?? ''); if ($u) $usernames[] = $u; }
    $usernames = array_values(array_unique(array_filter($usernames)));

    // DB lookup: subscriber name + plan speed (replaces the 50k queue fetch)
    $nameMap = [];
    if (!empty($usernames)) {
        $ph   = implode(',', array_fill(0, count($usernames), '?'));
        $rows = db()->prepare("
            SELECT s.ppp_username, s.firstname, s.lastname, s.subscriber_id,
                   p.speed_mbps
            FROM subscribers s
            LEFT JOIN plans p ON p.plan_id = s.plan_id
            WHERE s.router_id = ? AND s.ppp_username IN ($ph)
        ");
        $rows->execute(array_merge([$routerId], $usernames));
        foreach ($rows->fetchAll() as $r) {
            $bps = (int)$r['speed_mbps'] * 1_000_000;
            $nameMap[$r['ppp_username']] = [
                'name'          => trim($r['firstname'] . ' ' . $r['lastname']),
                'subscriber_id' => (int)$r['subscriber_id'],
                'max_down'      => $bps,
                'max_up'        => $bps,
            ];
        }
    }

    // ── Build session list ────────────────────────────────────────
    $sessions = [];
    $seenUsers = [];
    foreach ($pppActive as $s) {
        $uname = $s['name'] ?? '';
        $info  = $nameMap[$uname] ?? [];
        $bytes = routerosSessionBytes(
            $s,
            ['rx-bytes', 'bytes-in', 'rx-byte'],
            ['tx-bytes', 'bytes-out', 'tx-byte'],
            ['bytes']
        );
        $seenUsers[$uname] = true;
        $sessions[] = [
            'username'      => $uname,
            'subscriber'    => $info['name'] ?? $uname,
            'subscriber_id' => $info['subscriber_id'] ?? null,
            'type'          => 'ppp',
            'address'       => $s['address'] ?? '',
            'uptime'        => $s['uptime']  ?? '',
            'bytes_in'      => $bytes['bytes_in'],
            'bytes_out'     => $bytes['bytes_out'],
            'max_up'        => $info['max_up']   ?? 0,
            'max_down'      => $info['max_down'] ?? 0,
        ];
    }
    foreach ($hsActive as $s) {
        $uname = $s['user'] ?? '';
        $info  = $nameMap[$uname] ?? [];
        $bytes = routerosSessionBytes(
            $s,
            ['bytes-in', 'rx-bytes', 'rx-byte'],
            ['bytes-out', 'tx-bytes', 'tx-byte'],
            ['bytes']
        );
        $seenUsers[$uname] = true;
        $sessions[] = [
            'username'      => $uname,
            'subscriber'    => $info['name'] ?? $uname,
            'subscriber_id' => $info['subscriber_id'] ?? null,
            'type'          => 'hotspot',
            'address'       => $s['address'] ?? '',
            'uptime'        => $s['uptime']  ?? '',
            'bytes_in'      => $bytes['bytes_in'],
            'bytes_out'     => $bytes['bytes_out'],
            'max_up'        => $info['max_up']   ?? 0,
            'max_down'      => $info['max_down'] ?? 0,
        ];
    }
    // Add RADIUS/User Manager sessions not already represented in PPP or Hotspot active
    foreach ($radiusActive as $s) {
        $uname = $s['user'] ?? ($s['username'] ?? '');
        if (!$uname || !empty($seenUsers[$uname])) continue;
        $info  = $nameMap[$uname] ?? [];
        $bytes = routerosSessionBytes(
            $s,
            ['acct-input-octets', 'input-octets', 'upload', 'upload-used', 'bytes-in', 'rx-bytes', 'rx-byte'],
            ['acct-output-octets', 'output-octets', 'download', 'download-used', 'bytes-out', 'tx-bytes', 'tx-byte'],
            ['bytes']
        );
        $sessions[] = [
            'username'      => $uname,
            'subscriber'    => $info['name'] ?? $uname,
            'subscriber_id' => $info['subscriber_id'] ?? null,
            'type'          => 'radius',
            'address'       => $s['caller-id'] ?? ($s['calling-station-id'] ?? ($s['nas-ip-address'] ?? '')),
            'uptime'        => $s['uptime'] ?? ($s['session-time'] ?? ''),
            'bytes_in'      => $bytes['bytes_in'],
            'bytes_out'     => $bytes['bytes_out'],
            'max_up'        => $info['max_up']   ?? 0,
            'max_down'      => $info['max_down'] ?? 0,
        ];
    }

    $ts = microtime(true);

    // Write cache (suppress errors if dir not writable)
    @file_put_contents($cacheFile, json_encode(['ts' => $ts, 'sessions' => $sessions]));
}

// ── Server-side search ────────────────────────────────────────────
$total = count($sessions);
if ($search !== '') {
    $sessions = array_values(array_filter($sessions, function ($s) use ($search) {
        return str_contains(strtolower($s['username']), $search)
            || str_contains(strtolower($s['subscriber']), $search)
            || str_contains($s['address'], $search);
    }));
    $total = count($sessions); // filtered total
}

// ── Pagination ────────────────────────────────────────────────────
$page = array_slice($sessions, $offset, $limit);

// Aggregate KPIs from the full (unsliced) session list
$pppCount    = 0; $hsCount = 0; $radiusCount = 0;
foreach ($sessions as $s) {
    if ($s['type'] === 'ppp')    $pppCount++;
    elseif ($s['type'] === 'radius') $radiusCount++;
    else $hsCount++;
}

echo json_encode([
    'success'      => true,
    'ts'           => $ts,
    'router_id'    => $routerId,
    'router_name'  => $router['name'],
    'total'        => $total,
    'offset'       => $offset,
    'limit'        => $limit,
    'ppp_count'    => $pppCount,
    'hs_count'     => $hsCount,
    'radius_count' => $radiusCount,
    'sessions'     => $page,
    'cached'       => isset($cached),
]);
