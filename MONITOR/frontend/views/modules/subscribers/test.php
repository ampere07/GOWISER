<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireLogin();
requireMinRole(ROLE_ADMIN);

class LocalRouterosAPI {
    private mixed $socket = null;
    private bool $connected = false;
    public string $error = '';

    public function __construct(
        private string $host,
        private int $port = 8728,
        private int $timeout = 5
    ) {}

    public function connect(string $username, string $password): bool {
        $this->error = '';
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        if (!$this->socket) {
            $this->error = "Cannot connect to {$this->host}:{$this->port} - {$errstr} ({$errno})";
            return false;
        }

        stream_set_timeout($this->socket, $this->timeout);
        stream_set_blocking($this->socket, true);

        try {
            $response = $this->rawCommand(['/login', '=name=' . $username, '=password=' . $password]);
            if ($this->hasDone($response)) {
                $this->connected = true;
                return true;
            }

            $challenge = $this->firstAttribute($response, 'ret');
            if ($challenge !== null) {
                $hash = md5("\x00" . $password . pack('H*', $challenge));
                $response = $this->rawCommand(['/login', '=name=' . $username, '=response=00' . $hash]);
                if ($this->hasDone($response)) {
                    $this->connected = true;
                    return true;
                }
            }

            $this->error = 'Login failed: ' . ($this->firstAttribute($response, 'message') ?? 'Unknown error');
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
        }

        $this->disconnect();
        return false;
    }

    public function disconnect(): void {
        if ($this->socket) {
            @fclose($this->socket);
        }
        $this->socket = null;
        $this->connected = false;
    }

    public function query(string $command, array $params = [], array $queries = []): array {
        if (!$this->socket) {
            $this->error = 'Not connected';
            return [];
        }

        $sentence = [$command];
        foreach ($params as $key => $value) {
            $sentence[] = '=' . ltrim((string)$key, '=') . '=' . (string)$value;
        }
        foreach ($queries as $query) {
            $sentence[] = (string)$query;
        }

        $raw = $this->rawCommand($sentence);
        $rows = [];
        foreach ($raw as $sentenceWords) {
            $reply = $sentenceWords[0] ?? '';
            if ($reply === '!trap' || $reply === '!fatal') {
                $message = $this->sentenceAttribute($sentenceWords, 'message') ?? $reply;
                throw new RuntimeException($message);
            }
            if ($reply !== '!re') continue;

            $row = [];
            foreach ($sentenceWords as $word) {
                if (!str_starts_with($word, '=')) continue;
                $parts = explode('=', substr($word, 1), 2);
                $row[$parts[0]] = $parts[1] ?? '';
            }
            $rows[] = $row;
        }
        return $rows;
    }

    public function umPrefix(): string {
        foreach (['/user-manager', '/tool/user-manager'] as $prefix) {
            try {
                $this->query($prefix . '/print');
                return $prefix;
            } catch (Throwable $e) {
                continue;
            }
        }
        return '';
    }

    public function findUserManagerActiveSessionsByUserRobust(string $username): array {
        $prefix = $this->umPrefix();
        if ($prefix === '') return [];

        $base = $prefix . '/session/print';
        foreach (['user', 'name', 'username', 'user-name', 'login'] as $field) {
            try {
                $rows = $this->query($base, [], ['?' . $field . '=' . $username]);
            } catch (Throwable $e) {
                $rows = [];
            }
            $matches = array_values(array_filter($rows, fn($row) =>
                isActiveUmSession($row) && localRouterosUserMatches($row, ['user', 'user-name', 'username', 'name', 'login'], $username)
            ));
            if (!empty($matches)) return $matches;
        }

        try {
            $rows = $this->query($base, [], ['?active=yes']);
        } catch (Throwable $e) {
            $rows = [];
        }
        $matches = array_values(array_filter($rows, fn($row) =>
            isActiveUmSession($row) && localRouterosUserMatches($row, ['user', 'user-name', 'username', 'name', 'login'], $username)
        ));
        if (!empty($matches)) return $matches;

        try {
            $rows = $this->query($base);
        } catch (Throwable $e) {
            return [];
        }
        return array_values(array_filter($rows, fn($row) =>
            isActiveUmSession($row) && localRouterosUserMatches($row, ['user', 'user-name', 'username', 'name', 'login'], $username)
        ));
    }

    private function rawCommand(array $sentence): array {
        foreach ($sentence as $word) {
            $this->writeWord((string)$word);
        }
        $this->writeWord('');

        $sentences = [];
        while (true) {
            $words = [];
            while (true) {
                $word = $this->readWord();
                if ($word === '') break;
                $words[] = $word;
            }
            if (empty($words)) continue;
            $sentences[] = $words;
            if (($words[0] ?? '') === '!done' || ($words[0] ?? '') === '!fatal') {
                break;
            }
        }
        return $sentences;
    }

    private function writeWord(string $word): void {
        $this->writeBytes($this->encodeLength(strlen($word)) . $word);
    }

    private function readWord(): string {
        $length = $this->readLength();
        if ($length === 0) return '';
        return $this->readBytes($length);
    }

    private function writeBytes(string $bytes): void {
        $length = strlen($bytes);
        $written = 0;
        while ($written < $length) {
            $n = @fwrite($this->socket, substr($bytes, $written));
            if ($n === false || $n === 0) {
                throw new RuntimeException('RouterOS socket write failed');
            }
            $written += $n;
        }
    }

    private function readBytes(int $length): string {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = @fread($this->socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                $meta = is_resource($this->socket) ? stream_get_meta_data($this->socket) : [];
                $suffix = !empty($meta['timed_out']) ? ' (timeout)' : '';
                throw new RuntimeException('RouterOS socket read failed' . $suffix);
            }
            $data .= $chunk;
        }
        return $data;
    }

    private function readLength(): int {
        $c = ord($this->readBytes(1));
        if (($c & 0x80) === 0x00) return $c;
        if (($c & 0xC0) === 0x80) return (($c & ~0xC0) << 8) + ord($this->readBytes(1));
        if (($c & 0xE0) === 0xC0) {
            $b = array_map('ord', str_split($this->readBytes(2)));
            return (($c & ~0xE0) << 16) + ($b[0] << 8) + $b[1];
        }
        if (($c & 0xF0) === 0xE0) {
            $b = array_map('ord', str_split($this->readBytes(3)));
            return (($c & ~0xF0) << 24) + ($b[0] << 16) + ($b[1] << 8) + $b[2];
        }
        $bytes = $this->readBytes(4);
        $unpacked = unpack('Nlength', $bytes);
        return (int)$unpacked['length'];
    }

    private function encodeLength(int $length): string {
        if ($length < 0x80) return chr($length);
        if ($length < 0x4000) return pack('n', $length | 0x8000);
        if ($length < 0x200000) {
            $length |= 0xC00000;
            return chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        }
        if ($length < 0x10000000) return pack('N', $length | 0xE0000000);
        return chr(0xF0) . pack('N', $length);
    }

    private function hasDone(array $sentences): bool {
        foreach ($sentences as $sentence) {
            if (($sentence[0] ?? '') === '!done') return true;
        }
        return false;
    }

    private function firstAttribute(array $sentences, string $name): ?string {
        foreach ($sentences as $sentence) {
            $value = $this->sentenceAttribute($sentence, $name);
            if ($value !== null) return $value;
        }
        return null;
    }

    private function sentenceAttribute(array $sentence, string $name): ?string {
        $prefix = '=' . $name . '=';
        foreach ($sentence as $word) {
            if (str_starts_with($word, $prefix)) {
                return substr($word, strlen($prefix));
            }
        }
        return null;
    }
}

/**
 * Small adapter so this test page can use the older $API->comm(...) style.
 * Query filters like "?user" become RouterOS query words, while ".id" and
 * ".proplist" become normal command parameters.
 */
function routerosComm(LocalRouterosAPI $api, string $command, array $params = []): array {
    $commandParams = [];
    $queries = [];

    foreach ($params as $key => $value) {
        $key = (string)$key;
        if (str_starts_with($key, '?')) {
            $queries[] = '?' . ltrim($key, '?') . '=' . $value;
        } else {
            $commandParams[$key] = $value;
        }
    }

    return $api->query($command, $commandParams, $queries);
}

function firstSessionValue(array $row, array $keys, string $default = ''): string {
    foreach ($keys as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
            return trim((string)$row[$key]);
        }
    }
    return $default;
}

function localRouterosUserMatches(array $row, array $keys, string $username): bool {
    $username = strtolower(trim($username));
    foreach ($keys as $key) {
        $value = strtolower(trim((string)($row[$key] ?? '')));
        if ($value !== '' && $value === $username) return true;
    }
    return false;
}

function isActiveUmSession(array $row): bool {
    if (isset($row['active'])) {
        return in_array(strtolower((string)$row['active']), ['yes', 'true', '1'], true);
    }
    if (isset($row['status'])) {
        return in_array(strtolower((string)$row['status']), ['active', 'accounting', 'start', 'started'], true);
    }
    if (isset($row['terminated'])) {
        return in_array(strtolower((string)$row['terminated']), ['never', '', 'no', 'false'], true);
    }
    return true;
}

function routerosBitRateValue(mixed $value): ?float {
    if ($value === null || $value === '') return null;
    if (is_int($value) || is_float($value)) return max(0, (float)$value);

    $raw = trim((string)$value);
    if ($raw === '') return null;

    $plain = str_replace([',', ' '], '', $raw);
    if (is_numeric($plain)) return max(0, (float)$plain);

    if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*([kmgtp]?)(?:bit|bits|bps|b\/s)?$/i', $raw, $m)) {
        $num = (float)$m[1];
        $pow = match (strtolower($m[2] ?? '')) {
            'k' => 1,
            'm' => 2,
            'g' => 3,
            't' => 4,
            'p' => 5,
            default => 0,
        };
        return max(0, $num * (1000 ** $pow));
    }

    return null;
}

function findClientInterfaceName(LocalRouterosAPI $api, string $targetUser): string {
    $rows = routerosComm($api, '/interface/print', [
        '.proplist' => '.id,name,type,running,dynamic',
    ]);
    $target = strtolower($targetUser);
    $candidates = [];

    foreach ($rows as $row) {
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') continue;

        $lower = strtolower($name);
        $score = 0;
        if ($lower === $target) {
            $score = 100;
        } elseif ($lower === '<pppoe-' . $target . '>' || $lower === 'pppoe-' . $target) {
            $score = 95;
        } elseif (str_contains($lower, $target)) {
            $score = 70;
        }

        if ($score > 0) {
            if (($row['running'] ?? '') === 'true') $score += 5;
            if (($row['dynamic'] ?? '') === 'true') $score += 3;
            $candidates[] = ['name' => $name, 'score' => $score];
        }
    }

    usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);
    return $candidates[0]['name'] ?? '';
}

function monitorClientInterface(LocalRouterosAPI $api, string $interfaceName): array {
    if ($interfaceName === '') {
        return ['download_bps' => null, 'upload_bps' => null, 'source' => ''];
    }

    $rows = routerosComm($api, '/interface/monitor-traffic', [
        'interface' => $interfaceName,
        'once'      => '',
    ]);
    $row = $rows[0] ?? [];
    if (empty($row)) {
        return ['download_bps' => null, 'upload_bps' => null, 'source' => ''];
    }

    $upload = routerosBitRateValue($row['rx-bits-per-second'] ?? null);
    $download = routerosBitRateValue($row['tx-bits-per-second'] ?? null);

    return [
        'download_bps' => $download,
        'upload_bps'   => $upload,
        'source'       => 'interface:' . $interfaceName,
    ];
}

function normalizeClientSession(array $row, string $type, string $fallbackUser): array {
    if ($type === 'ppp') {
        $bytes = routerosSessionBytes(
            $row,
            ['rx-bytes', 'bytes-in', 'rx-byte'],
            ['tx-bytes', 'bytes-out', 'tx-byte'],
            ['bytes']
        );
        $username = firstSessionValue($row, ['name', 'user', 'username'], $fallbackUser);
    } elseif ($type === 'hotspot') {
        $bytes = routerosSessionBytes(
            $row,
            ['bytes-in', 'rx-bytes', 'rx-byte'],
            ['bytes-out', 'tx-bytes', 'tx-byte'],
            ['bytes']
        );
        $username = firstSessionValue($row, ['user', 'name', 'username'], $fallbackUser);
    } else {
        $bytes = routerosSessionBytes(
            $row,
            ['acct-input-octets', 'input-octets', 'upload', 'upload-used', 'bytes-in', 'rx-bytes', 'rx-byte'],
            ['acct-output-octets', 'output-octets', 'download', 'download-used', 'bytes-out', 'tx-bytes', 'tx-byte'],
            ['bytes']
        );
        $username = firstSessionValue($row, ['user', 'user-name', 'username', 'name', 'login'], $fallbackUser);
    }

    return [
        'id'             => firstSessionValue($row, ['.id'], ''),
        'type'           => $type,
        'username'       => $username,
        'address'        => firstSessionValue($row, ['address', 'user-address', 'framed-ip-address', 'from-ip'], ''),
        'mac_address'    => firstSessionValue($row, ['caller-id', 'calling-station-id', 'mac-address'], ''),
        'uptime'         => firstSessionValue($row, ['uptime', 'session-time', 'acct-session-time'], ''),
        'bytes_in'       => $bytes['bytes_in'],
        'bytes_out'      => $bytes['bytes_out'],
        'download_bps'   => null,
        'upload_bps'     => null,
        'rate_source'    => '',
        'upload_label'   => formatBytes((int)$bytes['bytes_in']),
        'download_label' => formatBytes((int)$bytes['bytes_out']),
    ];
}

function collectClientSpeedSample(array $router, string $targetUser): array {
    $details = [
        'Router ID: ' . (int)$router['router_id'],
        'Target username: ' . $targetUser,
    ];

    $api = new LocalRouterosAPI(
        $router['host'],
        (int)($router['api_port'] ?: $router['port']),
        5
    );

    if (!$api->connect($router['username'], decryptData($router['password']))) {
        return [
            'success' => false,
            'message' => 'Cannot connect to router: ' . $api->error,
            'details' => $details,
        ];
    }

    try {
        $sessions = [];
        $seenTransportUsers = [];
        $pppProplist = '.id,name,address,uptime,caller-id,rx-bytes,tx-bytes,rx-byte,tx-byte,bytes-in,bytes-out,bytes';
        $hsProplist  = '.id,user,address,uptime,mac-address,bytes-in,bytes-out,rx-bytes,tx-bytes,rx-byte,tx-byte,bytes';
        $monitorRates = ['download_bps' => null, 'upload_bps' => null, 'source' => ''];
        try {
            $clientInterface = findClientInterfaceName($api, $targetUser);
            $monitorRates = monitorClientInterface($api, $clientInterface);
        } catch (Throwable $monitorError) {
            $details[] = 'Interface monitor error: ' . $monitorError->getMessage();
        }

        $pppRows = routerosComm($api, '/ppp/active/print', [
            '.proplist' => $pppProplist,
            '?name'     => $targetUser,
        ]);
        if (empty($pppRows)) {
            $allPpp = routerosComm($api, '/ppp/active/print', ['.proplist' => $pppProplist]);
            $pppRows = array_values(array_filter($allPpp, function (array $row) use ($targetUser): bool {
                $name = firstSessionValue($row, ['name', 'user', 'username']);
                return strcasecmp($name, $targetUser) === 0;
            }));
        }

        foreach ($pppRows as $row) {
            $session = normalizeClientSession($row, 'ppp', $targetUser);
            if ($monitorRates['source'] !== '') {
                $session['download_bps'] = $monitorRates['download_bps'];
                $session['upload_bps'] = $monitorRates['upload_bps'];
                $session['rate_source'] = $monitorRates['source'];
            }
            $seenTransportUsers[strtolower($session['username'])] = true;
            $sessions[] = $session;
        }

        $hsRows = routerosComm($api, '/ip/hotspot/active/print', [
            '.proplist' => $hsProplist,
            '?user'     => $targetUser,
        ]);
        if (empty($hsRows)) {
            $allHs = routerosComm($api, '/ip/hotspot/active/print', ['.proplist' => $hsProplist]);
            $hsRows = array_values(array_filter($allHs, function (array $row) use ($targetUser): bool {
                $name = firstSessionValue($row, ['user', 'name', 'username']);
                return strcasecmp($name, $targetUser) === 0;
            }));
        }

        foreach ($hsRows as $row) {
            $session = normalizeClientSession($row, 'hotspot', $targetUser);
            $seenTransportUsers[strtolower($session['username'])] = true;
            $sessions[] = $session;
        }

        $prefix = $api->umPrefix();
        $details[] = 'User Manager prefix: ' . ($prefix ?: 'not available');
        if ($prefix !== '') {
            $umRows = $api->findUserManagerActiveSessionsByUserRobust($targetUser);
            foreach ($umRows as $row) {
                if (!isActiveUmSession($row)) continue;
                $session = normalizeClientSession($row, 'radius', $targetUser);
                if (!empty($seenTransportUsers[strtolower($session['username'])])) continue;
                $sessions[] = $session;
            }
        }

        if (empty($sessions) && $monitorRates['source'] !== '') {
            $sessions[] = [
                'id'             => $monitorRates['source'],
                'type'           => 'interface',
                'username'       => $targetUser,
                'address'        => '',
                'mac_address'    => '',
                'uptime'         => '',
                'bytes_in'       => 0,
                'bytes_out'      => 0,
                'download_bps'   => $monitorRates['download_bps'],
                'upload_bps'     => $monitorRates['upload_bps'],
                'rate_source'    => $monitorRates['source'],
                'upload_label'   => '0 B',
                'download_label' => '0 B',
            ];
            $details[] = 'No active session row matched, but a client interface matched.';
        }

        $api->disconnect();

        $details[] = 'PPP active rows: ' . count($pppRows);
        $details[] = 'Hotspot active rows: ' . count($hsRows);
        $details[] = 'Interface monitor: ' . ($monitorRates['source'] ?: 'not available');
        $details[] = 'Total matching sessions: ' . count($sessions);
        $details[] = 'Download uses bytes-out/acct-output-octets; upload uses bytes-in/acct-input-octets.';

        return [
            'success' => true,
            'message' => empty($sessions)
                ? "No active client session found for '{$targetUser}'."
                : "Found " . count($sessions) . " active session" . (count($sessions) === 1 ? '' : 's') . " for '{$targetUser}'.",
            'router' => [
                'id' => (int)$router['router_id'],
                'name' => $router['name'] ?: $router['host'],
                'host' => $router['host'],
            ],
            'target_user' => $targetUser,
            'ts' => microtime(true),
            'sessions' => $sessions,
            'details' => $details,
        ];
    } catch (Throwable $e) {
        $api->disconnect();
        return [
            'success' => false,
            'message' => str_contains(strtolower($e->getMessage()), 'permission')
                ? 'Router API user has no read permission. Live speed needs a RouterOS account with api + read policy.'
                : 'Router error: ' . $e->getMessage(),
            'details' => array_merge($details, [
                'Router error: ' . $e->getMessage(),
                'Required RouterOS policies for live speed: api, read.',
                'A write-only account cannot read PPP active sessions, interfaces, or monitor-traffic.',
            ]),
        ];
    }
}

$routersStmt = db()->query("
    SELECT router_id, name, host, api_port, port, username, password, status, COALESCE(auth_type, 'local') AS auth_type
    FROM routers
    WHERE router_id = 17
    ORDER BY name ASC, router_id ASC
");
$routers = $routersStmt->fetchAll();

$selectedRouterId = 17;
$rawTargetUser = (string)($_POST['target_user'] ?? $_GET['target_user'] ?? '');
$targetUser = preg_replace('/\s+/', '', trim($rawTargetUser));
$message = '';
$details = [];
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    session_write_close();

    $router = null;
    foreach ($routers as $row) {
        if ((int)$row['router_id'] === $selectedRouterId) {
            $router = $row;
            break;
        }
    }

    $action = (string)($_POST['action'] ?? 'check');
    if ($action === 'sample') {
        header('Content-Type: application/json; charset=utf-8');
        if (!$router) {
            echo json_encode(['success' => false, 'message' => 'Router ID 17 was not found in the database.']);
            exit;
        }
        if ($targetUser === '') {
            echo json_encode(['success' => false, 'message' => 'Please enter a PPP/User Manager username.']);
            exit;
        }
        echo json_encode(collectClientSpeedSample($router, $targetUser));
        exit;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Client Speed Test</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f5f7fb;
            color: #111827;
        }
        .wrap {
            max-width: 980px;
            margin: 36px auto;
            padding: 0 18px;
        }
        .panel {
            background: #fff;
            border: 1px solid #d7dde8;
            border-radius: 8px;
            padding: 22px;
        }
        h1 {
            margin: 0 0 16px;
            font-size: 22px;
        }
        h2 {
            margin: 22px 0 12px;
            font-size: 18px;
        }
        label {
            display: block;
            margin: 14px 0 6px;
            font-weight: 700;
        }
        input {
            width: 100%;
            box-sizing: border-box;
            padding: 10px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 15px;
        }
        button {
            margin-top: 18px;
            padding: 10px 16px;
            border: 0;
            border-radius: 6px;
            background: #2563eb;
            color: #fff;
            font-weight: 700;
            cursor: pointer;
        }
        button.secondary {
            background: #475569;
        }
        button:disabled {
            cursor: not-allowed;
            opacity: .65;
        }
        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .actions button {
            margin-top: 18px;
        }
        .msg {
            margin: 0 0 16px;
            padding: 12px 14px;
            border-radius: 6px;
            border: 1px solid transparent;
        }
        .success { background: #ecfdf3; border-color: #86efac; color: #166534; }
        .danger { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
        .warning { background: #fffbeb; border-color: #fde68a; color: #92400e; }
        .info { background: #eff6ff; border-color: #bfdbfe; color: #1e40af; }
        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 12px;
            margin: 16px 0;
        }
        .card {
            border: 1px solid #d7dde8;
            border-radius: 8px;
            padding: 14px;
            background: #f8fafc;
        }
        .card small {
            display: block;
            color: #64748b;
            font-weight: 700;
            margin-bottom: 6px;
            text-transform: uppercase;
        }
        .metric {
            font-size: 24px;
            font-weight: 800;
            line-height: 1.2;
        }
        .table-wrap {
            overflow-x: auto;
            border: 1px solid #d7dde8;
            border-radius: 8px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 760px;
        }
        th,
        td {
            padding: 10px 12px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            font-size: 14px;
        }
        th {
            background: #f8fafc;
            color: #475569;
            font-size: 12px;
            text-transform: uppercase;
        }
        tr:last-child td {
            border-bottom: 0;
        }
        .muted {
            color: #64748b;
        }
        .pill {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 999px;
            background: #e0f2fe;
            color: #075985;
            font-weight: 700;
            font-size: 12px;
        }
        pre {
            overflow: auto;
            background: #0f172a;
            color: #e2e8f0;
            padding: 14px;
            border-radius: 6px;
            font-size: 13px;
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="panel">
        <h1>Client Download Speed Test - Router 17</h1>

        <?php if ($message !== ''): ?>
            <div class="msg <?= e($messageType) ?>"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if (empty($routers)): ?>
            <div class="msg warning">Router ID 17 was not found.</div>
        <?php else: ?>
            <form method="post" id="speedForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="sample">

                <?php $router = $routers[0]; ?>
                <div class="msg info">
                    Router 17: <?= e($router['name'] ?: $router['host']) ?> - <?= e($router['host']) ?> - <?= e(strtoupper($router['auth_type'])) ?> - DB status: <?= e($router['status']) ?>
                </div>
                <div class="msg warning">
                    RouterOS API user must have <strong>api + read</strong> policy. A write-only account cannot read live speed.
                </div>

                <label for="target_user">PPP / User Manager Username</label>
                <input id="target_user" name="target_user" value="<?= e($targetUser) ?>" placeholder="AdrianAtilano-2026-2711" autocomplete="off" required>

                <div class="actions">
                    <button type="submit" id="startBtn">Start Live Test</button>
                    <button type="button" class="secondary" id="stopBtn" disabled>Stop</button>
                </div>
            </form>

            <section id="speedResults" aria-live="polite">
                <h2>Live Speed</h2>
                <div class="msg info" id="liveStatus">Enter the username, then start the live test.</div>
                <div class="cards">
                    <div class="card">
                        <small>Download speed</small>
                        <div class="metric" id="downloadRate">0 bps</div>
                    </div>
                    <div class="card">
                        <small>Upload speed</small>
                        <div class="metric" id="uploadRate">0 bps</div>
                    </div>
                    <div class="card">
                        <small>Total downloaded</small>
                        <div class="metric" id="downloadTotal">0 B</div>
                    </div>
                    <div class="card">
                        <small>Total uploaded</small>
                        <div class="metric" id="uploadTotal">0 B</div>
                    </div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>User</th>
                                <th>IP / MAC</th>
                                <th>Uptime</th>
                                <th>Download rate</th>
                                <th>Upload rate</th>
                                <th>Total downloaded</th>
                                <th>Total uploaded</th>
                            </tr>
                        </thead>
                        <tbody id="sessionRows">
                            <tr><td colspan="8" class="muted">No live test running.</td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="muted" id="lastUpdated"></p>
                <h2>Live Debug</h2>
                <pre id="liveDebug">No sample yet.</pre>
            </section>
        <?php endif; ?>

        <?php if (!empty($details)): ?>
            <h2>Debug Details</h2>
            <pre><?= e(implode("\n", $details)) ?></pre>
        <?php endif; ?>
    </div>
</div>

<script>
const csrfToken = <?= json_encode(csrfToken()) ?>;
const csrfTokenName = <?= json_encode(CSRF_TOKEN_NAME) ?>;
const pollMs = 3000;
let previousSample = null;
let pollTimer = null;
let liveActive = false;
let inFlight = false;

const speedForm = document.getElementById('speedForm');
const usernameInput = document.getElementById('target_user');
const startBtn = document.getElementById('startBtn');
const stopBtn = document.getElementById('stopBtn');
const liveStatus = document.getElementById('liveStatus');

function sessionKey(session) {
    return [session.type, session.id || '', session.username || '', session.address || ''].join('|');
}

function formatBytes(bytes) {
    bytes = Math.max(0, Number(bytes) || 0);
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
    if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
    return Math.round(bytes) + ' B';
}

function formatRate(bps) {
    bps = Math.max(0, Number(bps) || 0);
    if (bps >= 1000000000) return (bps / 1000000000).toFixed(2) + ' Gbps';
    if (bps >= 1000000) return (bps / 1000000).toFixed(2) + ' Mbps';
    if (bps >= 1000) return (bps / 1000).toFixed(2) + ' Kbps';
    return Math.round(bps) + ' bps';
}

function calculateRates(current, previous) {
    const prevMap = new Map();
    if (previous && Array.isArray(previous.sessions)) {
        previous.sessions.forEach(session => prevMap.set(sessionKey(session), session));
    }

    const dt = previous && current.ts > previous.ts ? current.ts - previous.ts : 0;
    let totalDownRate = 0;
    let totalUpRate = 0;
    let totalDownBytes = 0;
    let totalUpBytes = 0;
    let hasDirectRates = false;

    const rows = (current.sessions || []).map(session => {
        const prev = prevMap.get(sessionKey(session));
        const downBytes = Number(session.bytes_out) || 0;
        const upBytes = Number(session.bytes_in) || 0;
        totalDownBytes += downBytes;
        totalUpBytes += upBytes;

        const directDown = session.download_bps === null || session.download_bps === undefined ? null : Number(session.download_bps);
        const directUp = session.upload_bps === null || session.upload_bps === undefined ? null : Number(session.upload_bps);
        let downRate = Number.isFinite(directDown) ? Math.max(0, directDown) : null;
        let upRate = Number.isFinite(directUp) ? Math.max(0, directUp) : null;

        if (downRate !== null || upRate !== null) {
            hasDirectRates = true;
            totalDownRate += downRate || 0;
            totalUpRate += upRate || 0;
        } else if (prev && dt > 0) {
            downRate = Math.max(0, ((downBytes - (Number(prev.bytes_out) || 0)) * 8) / dt);
            upRate = Math.max(0, ((upBytes - (Number(prev.bytes_in) || 0)) * 8) / dt);
            totalDownRate += downRate;
            totalUpRate += upRate;
        }

        return { ...session, downRate, upRate };
    });

    return { rows, totalDownRate, totalUpRate, totalDownBytes, totalUpBytes, hasRates: hasDirectRates || dt > 0 };
}

function renderSample(sample) {
    const metrics = calculateRates(sample, previousSample);
    document.getElementById('downloadRate').textContent = metrics.hasRates ? formatRate(metrics.totalDownRate) : 'Waiting...';
    document.getElementById('uploadRate').textContent = metrics.hasRates ? formatRate(metrics.totalUpRate) : 'Waiting...';
    document.getElementById('downloadTotal').textContent = formatBytes(metrics.totalDownBytes);
    document.getElementById('uploadTotal').textContent = formatBytes(metrics.totalUpBytes);

    const tbody = document.getElementById('sessionRows');
    if (!metrics.rows.length) {
        tbody.innerHTML = '<tr><td colspan="8" class="muted">No active session found.</td></tr>';
    } else {
        tbody.innerHTML = metrics.rows.map(session => `
            <tr>
                <td><span class="pill">${escapeHtml(session.type || '')}</span></td>
                <td>${escapeHtml(session.username || '')}</td>
                <td>
                    ${escapeHtml(session.address || '-')}
                    <div class="muted">${escapeHtml(session.mac_address || '')}</div>
                </td>
                <td>${escapeHtml(session.uptime || '-')}</td>
                <td>
                    ${session.downRate === null ? '<span class="muted">Waiting...</span>' : escapeHtml(formatRate(session.downRate))}
                    ${session.rate_source ? '<div class="muted">' + escapeHtml(session.rate_source) + '</div>' : ''}
                </td>
                <td>${session.upRate === null ? '<span class="muted">Waiting...</span>' : escapeHtml(formatRate(session.upRate))}</td>
                <td>${escapeHtml(formatBytes(session.bytes_out))}</td>
                <td>${escapeHtml(formatBytes(session.bytes_in))}</td>
            </tr>
        `).join('');
    }

    const updated = new Date();
    document.getElementById('lastUpdated').textContent = 'Last sampled: ' + updated.toLocaleTimeString();
    liveStatus.className = (sample.sessions || []).length ? 'msg success' : 'msg warning';
    liveStatus.textContent = sample.message || 'Sample received.';
    document.getElementById('liveDebug').textContent = (sample.details || []).join("\n") || 'No debug details.';
    previousSample = sample;
}

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, char => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    }[char]));
}

function resetLiveDisplay(message) {
    previousSample = null;
    document.getElementById('downloadRate').textContent = '0 bps';
    document.getElementById('uploadRate').textContent = '0 bps';
    document.getElementById('downloadTotal').textContent = '0 B';
    document.getElementById('uploadTotal').textContent = '0 B';
    document.getElementById('sessionRows').innerHTML = '<tr><td colspan="8" class="muted">Waiting for live sample...</td></tr>';
    document.getElementById('lastUpdated').textContent = '';
    document.getElementById('liveDebug').textContent = 'No sample yet.';
    liveStatus.className = 'msg info';
    liveStatus.textContent = message;
}

async function pollSample() {
    if (!liveActive || inFlight) return;

    const targetUser = ((usernameInput && usernameInput.value) || '').replace(/\s+/g, '').trim();
    if (!targetUser) {
        stopLive('Please enter a PPP/User Manager username.');
        liveStatus.className = 'msg danger';
        return;
    }

    inFlight = true;
    const body = new FormData();
    body.append('action', 'sample');
    body.append('target_user', targetUser);
    body.append(csrfTokenName, csrfToken);

    try {
        const res = await fetch(location.pathname, {
            method: 'POST',
            headers: {'X-CSRF-TOKEN': csrfToken},
            body
        });
        const text = await res.text();
        let sample = null;
        try {
            sample = JSON.parse(text);
        } catch (parseError) {
            const plain = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
            throw new Error(plain.substring(0, 350) || 'Server returned a non-JSON response.');
        }
        if (!res.ok) {
            throw new Error(sample.message || ('HTTP ' + res.status));
        }
        if (sample.success) {
            renderSample(sample);
        } else {
            liveStatus.className = 'msg danger';
            liveStatus.textContent = sample.message || 'Could not read client speed.';
            document.getElementById('liveDebug').textContent = (sample.details || []).join("\n") || 'No debug details.';
        }
    } catch (error) {
        liveStatus.className = 'msg danger';
        liveStatus.textContent = 'Could not read client speed: ' + error.message;
        document.getElementById('liveDebug').textContent = error.message;
    } finally {
        inFlight = false;
        if (liveActive) {
            pollTimer = window.setTimeout(pollSample, pollMs);
        }
    }
}

function startLive() {
    if (!speedForm || !usernameInput) return;
    const targetUser = usernameInput.value.replace(/\s+/g, '').trim();
    usernameInput.value = targetUser;
    if (!targetUser) {
        liveStatus.className = 'msg danger';
        liveStatus.textContent = 'Please enter a PPP/User Manager username.';
        usernameInput.focus();
        return;
    }

    window.clearTimeout(pollTimer);
    liveActive = true;
    startBtn.disabled = true;
    stopBtn.disabled = false;
    resetLiveDisplay('Live test running. First sample is loading...');
    pollSample();
}

function stopLive(message = 'Live test stopped.') {
    liveActive = false;
    window.clearTimeout(pollTimer);
    if (startBtn) startBtn.disabled = false;
    if (stopBtn) stopBtn.disabled = true;
    if (liveStatus) {
        liveStatus.className = 'msg info';
        liveStatus.textContent = message;
    }
}

if (speedForm) {
    speedForm.addEventListener('submit', function (event) {
        event.preventDefault();
        startLive();
    });
}

if (stopBtn) {
    stopBtn.addEventListener('click', function () {
        stopLive();
    });
}

<?php if ($targetUser !== ''): ?>
startLive();
<?php endif; ?>
</script>
</body>
</html>
