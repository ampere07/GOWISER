<?php
ob_start();
require_once dirname(__DIR__) . '/config/config.php';
ob_end_clean();
ini_set('display_errors', '0'); // never leak PHP error HTML into a JSON response
header('Content-Type: application/json; charset=utf-8');

requireLogin();
require_once BASE_PATH . '/lib/RouterosAPI.php';

$subscriberId = (int)($_GET['subscriber_id'] ?? 0);
$debug        = !empty($_GET['debug']) && hasMinRole(ROLE_ADMIN);

if (!$subscriberId) jsonResponse(false, 'Missing subscriber_id');

$routerId = selectedRouterId();
$where = ['s.subscriber_id = ?'];
$params = [$subscriberId];
if (currentRole() !== ROLE_SUPERADMIN || $routerId) {
    if (!$routerId) jsonResponse(false, 'Router not selected');
    $where[] = 's.router_id = ?';
    $params[] = $routerId;
}
$whereSql = implode(' AND ', $where);

$stmt = db()->prepare("
    SELECT s.subscriber_id, s.ppp_username, s.connection_type,
           r.host, r.port, r.api_port, r.username AS r_user, r.password AS r_pass,
           r.status AS r_status, COALESCE(r.auth_type, 'local') AS auth_type
    FROM subscribers s
    LEFT JOIN routers r ON r.router_id = s.router_id
    WHERE {$whereSql}
");
$stmt->execute($params);
$sub = $stmt->fetch();

if (!$sub)                              jsonResponse(false, 'Subscriber not found');
if (empty($sub['host']))               jsonResponse(false, 'No router assigned');
if ($sub['r_status'] === 'maintenance') jsonResponse(false, 'Router is in maintenance');
if ($sub['r_status'] !== ROUTER_ONLINE) jsonResponse(false, 'Router is not connected');

$connType = $sub['connection_type'];
$authType = $sub['auth_type'];
$username = trim($sub['ppp_username'] ?? '');

if ($username === '' || !in_array($connType, ['ppp', 'hotspot'], true)) {
    echo json_encode(['success' => true, 'online' => false, 'session' => null,
                      'router_up' => null, 'auth_type' => $authType]);
    exit;
}

@set_time_limit(15);
$api = new RouterosAPI($sub['host'], (int)($sub['api_port'] ?: $sub['port']), 5);

if (!$api->connect($sub['r_user'], decryptData($sub['r_pass']))) {
    jsonResponse(false, 'Router offline: ' . $api->error);
}

// ── Seconds → "1d2h3m4s" ─────────────────────────────────────
function secsToUptime(int $s): string {
    if ($s <= 0) return '0s';
    $d = intdiv($s, 86400); $s %= 86400;
    $h = intdiv($s, 3600);  $s %= 3600;
    $m = intdiv($s, 60);    $s %= 60;
    return ($d ? "{$d}d" : '') . ($h ? "{$h}h" : '') . ($m ? "{$m}m" : '') . ($s || (!$d && !$h && !$m) ? "{$s}s" : '');
}

// ── Is this UM session currently active? ─────────────────────
function isUmActive(array $row): bool {
    if (isset($row['active']))     return $row['active'] === 'yes' || $row['active'] === 'true';
    if (isset($row['status']))     return in_array(strtolower($row['status']), ['active', 'accounting', 'start']);
    if (isset($row['terminated'])) return $row['terminated'] === 'never' || $row['terminated'] === '';
    return true;
}

// ── First value found in $row for a list of candidate keys ───
function firstVal(array $row, array $keys, mixed $default = ''): mixed {
    foreach ($keys as $k) {
        if (isset($row[$k]) && $row[$k] !== '') return $row[$k];
    }
    return $default;
}

$session   = null;
$debugData = [];

// ── Step 1: Active session table (PPP or Hotspot) ────────────
// Works for both local and RADIUS — RADIUS still creates a PPP/hotspot session on the router
if ($connType === 'ppp') {
    $cmd    = '/ppp/active/print';
    $filter = '?name=';
} else {
    $cmd    = '/ip/hotspot/active/print';
    $filter = '?user=';
}

$activeRows = $api->query($cmd, [], [$filter . $username]);
if ($debug) $debugData['active_rows'] = $activeRows;

if (!empty($activeRows)) {
    $row = $activeRows[0];
    $bytes = $connType === 'ppp'
        ? routerosSessionBytes(
            $row,
            ['rx-bytes', 'bytes-in', 'rx-byte'],
            ['tx-bytes', 'bytes-out', 'tx-byte'],
            ['bytes']
        )
        : routerosSessionBytes(
            $row,
            ['bytes-in', 'rx-bytes', 'rx-byte'],
            ['bytes-out', 'tx-bytes', 'tx-byte'],
            ['bytes']
        );
    $session = [
        'address'     => firstVal($row, ['address'], ''),
        'mac-address' => firstVal($row, ['caller-id', 'mac-address'], '—'),
        'uptime'      => firstVal($row, ['uptime'], '—'),
        'bytes-in'    => (string)$bytes['bytes_in'],
        'bytes-out'   => (string)$bytes['bytes_out'],
    ];
}

// ── Step 2: RADIUS — User Manager session (supplement / fallback) ──
if ($authType === 'radius') {
    $umPrefix = $api->umPrefix();
    $umRow    = null;

    // Try targeted user filter first, then active=yes filter, then full list
    $umRows = $api->query($umPrefix . '/session/print', [], ['?user=' . $username]);
    if (empty($umRows)) {
        $umRows = $api->query($umPrefix . '/session/print', [], ['?active=yes']);
    }
    if (empty($umRows)) {
        $umRows = $api->query($umPrefix . '/session/print');
    }

    if ($debug) {
        $debugData['um_prefix']   = $umPrefix;
        $debugData['um_sessions'] = $umRows;
    }

    foreach ($umRows as $r) {
        if (($r['user'] ?? $r['name'] ?? $r['username'] ?? '') === $username && isUmActive($r)) {
            $umRow = $r;
            break;
        }
    }

    if ($debug) $debugData['um_matched'] = $umRow;

    if ($umRow !== null) {
        // IP
        $umIp = firstVal($umRow, ['user-address', 'framed-ip-address', 'from-ip'], '');
        if ($umIp === '0.0.0.0') $umIp = '';

        // Uptime: numeric session-time → convert; else calculate from started; else string uptime
        $rawUp = firstVal($umRow, ['session-time', 'acct-session-time'], '');
        if (($rawUp === '' || $rawUp === '0') && !empty($umRow['started'])) {
            $ts = strtotime($umRow['started']);
            if ($ts > 0) $rawUp = (string)(time() - $ts);
        }
        $umUptime = is_numeric($rawUp) && $rawUp > 0
            ? secsToUptime((int)$rawUp)
            : (firstVal($umRow, ['uptime'], '') ?: '—');

        // MAC
        $umMac = firstVal($umRow, ['calling-station-id', 'caller-id', 'mac-address'], '—');

        // Bytes: UM upload = from user (bytes-in), download = to user (bytes-out)
        $umBytes = routerosSessionBytes(
            $umRow,
            ['acct-input-octets', 'input-octets', 'upload', 'upload-used', 'bytes-in', 'rx-bytes', 'rx-byte'],
            ['acct-output-octets', 'output-octets', 'download', 'download-used', 'bytes-out', 'tx-bytes', 'tx-byte'],
            ['bytes']
        );
        $umBytesIn  = (string)$umBytes['bytes_in'];
        $umBytesOut = (string)$umBytes['bytes_out'];

        if ($session !== null) {
            // Supplement missing fields from UM
            if (empty($session['address']) || $session['address'] === '0.0.0.0') $session['address']     = $umIp     ?: '—';
            if ($session['uptime']      === '—')                                  $session['uptime']      = $umUptime;
            if ($session['mac-address'] === '—' && $umMac !== '—')               $session['mac-address'] = $umMac;
            if ((int)$session['bytes-in'] === 0 && (int)$umBytesIn > 0) {
                $session['bytes-in']  = $umBytesIn;
                $session['bytes-out'] = $umBytesOut;
            }
        } else {
            $session = [
                'address'     => $umIp     ?: '—',
                'mac-address' => $umMac,
                'uptime'      => $umUptime,
                'bytes-in'    => $umBytesIn,
                'bytes-out'   => $umBytesOut,
            ];
        }
    }
}

$api->disconnect();

if ($session !== null && (empty($session['address']) || $session['address'] === '0.0.0.0')) {
    $session['address'] = '—';
}

$response = [
    'success'   => true,
    'online'    => $session !== null,
    'session'   => $session,
    'router_up' => true,
    'auth_type' => $authType,
];
if ($debug) $response['debug'] = $debugData;

echo json_encode($response);
