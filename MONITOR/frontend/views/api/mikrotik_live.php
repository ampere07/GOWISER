<?php
ob_start();
require_once dirname(__DIR__) . '/config/config.php';
ob_end_clean();
ini_set('display_errors', '0'); // never leak PHP error HTML into a JSON response
header('Content-Type: application/json; charset=utf-8');

requireRole(ROLE_ADMIN, ROLE_SUPERADMIN);
session_write_close(); // release session lock immediately — this endpoint only reads session, never writes
require_once BASE_PATH . '/lib/RouterosAPI.php';

@set_time_limit(20);

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
    $api = new RouterosAPI($router['host'], (int)($router['api_port'] ?: $router['port']), 8);
    if (!$api->connect($router['username'], decryptData($router['password']))) {
        echo json_encode(['success' => false, 'message' => $api->error]);
        exit;
    }

    $res        = $api->getSystemResource();
    $ifaces     = $api->getInterfaces();
    $pppActive  = $api->getPPPActive();
    $hsActive   = $api->getHotspotActive();
    $umActive   = $api->getUserManagerActiveSessions();
    $api->disconnect();

    $cpu      = (int)($res['cpu-load']     ?? 0);
    $freeMem  = (int)($res['free-memory']  ?? 0);
    $totalMem = (int)($res['total-memory'] ?? 1);
    $memPct   = $totalMem > 0 ? round(($totalMem - $freeMem) / $totalMem * 100) : 0;

    $totalRx   = 0;
    $totalTx   = 0;
    $ifaceData = [];
    foreach ($ifaces as $iface) {
        if (($iface['disabled'] ?? 'true') === 'false') {
            $rx = (int)($iface['rx-byte'] ?? 0);
            $tx = (int)($iface['tx-byte'] ?? 0);
            $totalRx += $rx;
            $totalTx += $tx;
            $ifaceData[] = ['name' => $iface['name'] ?? '', 'rx' => $rx, 'tx' => $tx];
        }
    }

    // Per-session byte counters for bandwidth rate calculation in JS
    $pppData = [];
    foreach ($pppActive as $s) {
        $bytes = routerosSessionBytes(
            $s,
            ['rx-bytes', 'bytes-in', 'rx-byte'],
            ['tx-bytes', 'bytes-out', 'tx-byte'],
            ['bytes']
        );
        $pppData[] = [
            'name'      => $s['name'] ?? '',
            'bytes_in'  => $bytes['bytes_in'],
            'bytes_out' => $bytes['bytes_out'],
        ];
    }
    $hsData = [];
    foreach ($hsActive as $s) {
        $bytes = routerosSessionBytes(
            $s,
            ['bytes-in', 'rx-bytes', 'rx-byte'],
            ['bytes-out', 'tx-bytes', 'tx-byte'],
            ['bytes']
        );
        $hsData[] = [
            'name'      => $s['user'] ?? ($s['name'] ?? ''),
            'bytes_in'  => $bytes['bytes_in'],
            'bytes_out' => $bytes['bytes_out'],
        ];
    }
    $radiusData = [];
    foreach (array_slice($umActive, 0, 50) as $s) {
        $bytes = routerosSessionBytes(
            $s,
            ['acct-input-octets', 'input-octets', 'upload', 'upload-used', 'bytes-in', 'rx-bytes', 'rx-byte'],
            ['acct-output-octets', 'output-octets', 'download', 'download-used', 'bytes-out', 'tx-bytes', 'tx-byte'],
            ['bytes']
        );
        $radiusData[] = [
            'name'      => $s['user'] ?? ($s['username'] ?? ''),
            'bytes_in'  => $bytes['bytes_in'],
            'bytes_out' => $bytes['bytes_out'],
        ];
    }

    echo json_encode([
        'success'          => true,
        'ts'               => microtime(true),
        'cpu'              => $cpu,
        'mem_pct'          => $memPct,
        'total_rx'         => $totalRx,
        'total_tx'         => $totalTx,
        'interfaces'       => $ifaceData,
        'ppp_sessions'     => $pppData,
        'hotspot_sessions' => $hsData,
        'radius_sessions'  => $radiusData,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
