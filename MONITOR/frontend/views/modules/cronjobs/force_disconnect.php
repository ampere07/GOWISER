<?php
/**
 * Force-disconnect expired subscribers who may still have active router sessions.
 *
 * Safety net for expired subscribers whose router sessions were never cut.
 *
 * Targets: active/suspended/expired subscribers with subscription_end <= yesterday.
 * Starts with yesterday, then walks backward through previous expired dates.
 * Re-applies the router policy (change_profile/group or disable) per router.
 *
 * CLI:  php /path/to/modules/cronjobs/force_disconnect.php --router_id=1
 * HTTP: GET /modules/cronjobs/force_disconnect.php?router_id=1
 *       Header: X-Cron-Token: FORCE_DISCONNECT_TOKEN (or CRON_TOKEN)
 */

ob_start();

if (PHP_SAPI !== 'cli') {
    // ── HTTP mode: minimal bootstrap + token gate ─────────────
    defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));

    // Load .env to get cron token and DB credentials
    (function () {
        $path = BASE_PATH . '/.env';
        if (!file_exists($path)) return;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            [$name, $val] = explode('=', $line, 2);
            $name = trim($name); $val = trim($val);
            if (preg_match('/^"(.*)"$/s', $val, $m) || preg_match("/^'(.*)'$/s", $val, $m)) $val = $m[1];
            if (!array_key_exists($name, $_ENV)) { $_ENV[$name] = $val; putenv("$name=$val"); }
        }
    })();

    defined('APP_KEY') || define('APP_KEY', $_ENV['APP_KEY'] ?? '');
    defined('APP_TIMEZONE') || define('APP_TIMEZONE', $_ENV['APP_TIMEZONE'] ?? 'Asia/Manila');
    date_default_timezone_set(APP_TIMEZONE);

    $expectedToken = $_ENV['FORCE_DISCONNECT_TOKEN'] ?? ($_ENV['CRON_TOKEN'] ?? '');
    $headerToken = $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
    if ($expectedToken === '' || $headerToken === '' || !hash_equals($expectedToken, $headerToken)) {
        if (ob_get_length()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        die(json_encode(['success' => false, 'message' => 'Forbidden']));
    }

    ini_set('display_errors', '0');

    require_once BASE_PATH . '/config/database.php';
} else {
    // ── CLI mode: full bootstrap ──────────────────────────────
    defined('BASE_PATH') || define('BASE_PATH', dirname(dirname(__DIR__)));
    require_once BASE_PATH . '/config/config.php';
}

require_once BASE_PATH . '/lib/RouterosAPI.php';

// decryptData fallback for HTTP mode (CLI gets it via config.php → functions.php)
if (!function_exists('decryptData')) {
    function decryptData(string $data): string {
        $key = substr(hash('sha256', APP_KEY), 0, 32);
        $raw = base64_decode($data);
        $iv  = substr($raw, 0, 16);
        $enc = substr($raw, 16);
        return openssl_decrypt($enc, 'AES-256-CBC', $key, 0, $iv) ?: '';
    }
}
if (!function_exists('appNow')) {
    function appNow(string $format = 'Y-m-d H:i:s'): string { return date($format); }
}
if (!function_exists('appToday')) {
    function appToday(string $format = 'Y-m-d'): string { return date($format); }
}
function forceDisconnectParamInt(string $key): int {
    if (PHP_SAPI !== 'cli') {
        return max(0, (int)($_GET[$key] ?? 0));
    }
    global $argv;
    foreach (($argv ?? []) as $arg) {
        if (preg_match('/^--?' . preg_quote($key, '/') . '=(\d+)$/', $arg, $m)) {
            return max(0, (int)$m[1]);
        }
    }
    return 0;
}

function forceDisconnectParamDate(string $key): string {
    $value = '';
    if (PHP_SAPI !== 'cli') {
        $value = (string)($_GET[$key] ?? '');
    } else {
        global $argv;
        foreach (($argv ?? []) as $arg) {
            if (preg_match('/^--?' . preg_quote($key, '/') . '=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
                $value = $m[1];
                break;
            }
        }
    }
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
}

function forceDisconnectSessionBackend(array $sub): string {
    if (($sub['auth_type'] ?? 'local') === 'radius') return 'user_manager';
    return ($sub['connection_type'] ?? 'ppp') === 'hotspot' ? 'hotspot' : 'ppp';
}

function forceDisconnectActiveSessionKeys(RouterosAPI $api, string $username, string $backend): array {
    $keys = [];
    if ($backend === 'user_manager') {
        foreach ($api->findUserManagerActiveSessionsByUserRobust($username) as $row) {
            if (!empty($row['.id'])) $keys[] = 'um:' . $row['.id'];
        }
    } elseif ($backend === 'hotspot') {
        foreach ($api->findHotspotActiveSessionsByUserRobust($username) as $row) {
            if (!empty($row['.id'])) $keys[] = 'hotspot:' . $row['.id'];
        }
    } else {
        foreach ($api->findPPPActiveSessionsByNameRobust($username) as $row) {
            if (!empty($row['.id'])) $keys[] = 'ppp:' . $row['.id'];
        }
    }
    return array_values(array_unique($keys));
}

function forceDisconnectActiveSessions(RouterosAPI $api, string $username, string $backend): int {
    return match ($backend) {
        'user_manager' => $api->disconnectUserManagerSessionsRobust($username),
        'hotspot'      => $api->disconnectHotspotSessionsByUserRobust($username),
        default        => $api->disconnectPPPSessionsByNameRobust($username),
    };
}

function forceDisconnectRowIsDisabled(array $row): bool {
    return in_array(strtolower((string)($row['disabled'] ?? 'no')), ['yes', 'true', '1'], true);
}

function forceDisconnectEnableIfDisabled(RouterosAPI $api, string $username, bool $isRadius, bool $isPPP): void {
    if ($isRadius) {
        $user = $api->findUserManagerUserByNameRobust($username);
        if (!empty($user['.id']) && forceDisconnectRowIsDisabled($user)) {
            $api->setUserManagerUserDisabledRobust($username, false);
        }
        return;
    }

    if ($isPPP) {
        $rows = $api->query('/ppp/secret/print', [], ['?name=' . $username]);
        $user = $rows[0] ?? [];
        if (!empty($user['.id']) && forceDisconnectRowIsDisabled($user)) {
            $api->setPPPSecretDisabled($username, false);
        }
        return;
    }

    $rows = $api->query('/ip/hotspot/user/print', [], ['?name=' . $username]);
    $user = $rows[0] ?? [];
    if (!empty($user['.id']) && forceDisconnectRowIsDisabled($user)) {
        $api->setHotspotUserDisabled($username, false);
    }
}

$pdo = db();

$isHttp = PHP_SAPI !== 'cli';
if ($isHttp) {
    @set_time_limit(30);
}

$maxSeconds = $isHttp ? (int)($_GET['max_seconds'] ?? 20) : 0;
if ($isHttp) {
    $maxSeconds = max(5, min(55, $maxSeconds));
}
$deadline = $maxSeconds > 0 ? microtime(true) + $maxSeconds : 0;

$limit = $isHttp ? (int)($_GET['limit'] ?? 50) : (int)($_GET['limit'] ?? 0);
if ($limit < 0) $limit = 0;
if ($isHttp) $limit = max(1, min(200, $limit));
$fetchLimit = $limit > 0 ? $limit + 1 : 0;
$limitSql = $fetchLimit > 0 ? " LIMIT {$fetchLimit}" : '';
$todayDate = appToday();
$newestExpiredDate = date('Y-m-d', strtotime($todayDate . ' -1 day'));
$routerScopeId = forceDisconnectParamInt('router_id');
$afterId = forceDisconnectParamInt('after_id');
$afterDate = forceDisconnectParamDate('after_date');
$mode = PHP_SAPI !== 'cli' ? (string)($_GET['mode'] ?? '') : '';
$routerWhere = $routerScopeId > 0 ? " AND s.router_id = {$routerScopeId}" : '';
$cursorWhere = ($afterDate !== '' && $afterId > 0)
    ? " AND (DATE(s.subscription_end) < " . $pdo->quote($afterDate) . "
              OR (DATE(s.subscription_end) = " . $pdo->quote($afterDate) . " AND s.subscriber_id < {$afterId}))"
    : '';

if ($isHttp && $mode === 'count') {
    $summary = $pdo->query("
        SELECT COUNT(*) AS affected_count,
               MIN(DATE(s.subscription_end)) AS oldest_expiry,
               MAX(DATE(s.subscription_end)) AS newest_expiry
        FROM subscribers s
        INNER JOIN routers r ON r.router_id = s.router_id
        WHERE s.status IN ('active','suspended','expired')
          AND DATE(s.subscription_end) <= " . $pdo->quote($newestExpiredDate) . "
          AND s.ppp_username IS NOT NULL
          AND s.ppp_username != ''
          AND r.host IS NOT NULL
          AND r.host != ''
          {$routerWhere}
    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    $policySummary = $pdo->query("
        SELECT r.router_id,
               r.name AS router_name,
               COALESCE(r.auth_type, 'local') AS auth_type,
               COALESCE(rp.on_expire, 'disable') AS policy_action,
               rp.expire_ppp_profile,
               rp.expire_hs_profile,
               COUNT(*) AS affected_count
        FROM subscribers s
        INNER JOIN routers r ON r.router_id = s.router_id
        LEFT JOIN router_policies rp ON rp.router_id = s.router_id
        WHERE s.status IN ('active','suspended','expired')
          AND DATE(s.subscription_end) <= " . $pdo->quote($newestExpiredDate) . "
          AND s.ppp_username IS NOT NULL
          AND s.ppp_username != ''
          AND r.host IS NOT NULL
          AND r.host != ''
          {$routerWhere}
        GROUP BY r.router_id, r.name, r.auth_type, rp.on_expire, rp.expire_ppp_profile, rp.expire_hs_profile
        ORDER BY r.name
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (ob_get_length()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'        => true,
        'affected_count' => (int)($summary['affected_count'] ?? 0),
        'oldest_expiry'  => $summary['oldest_expiry'] ?? null,
        'newest_expiry'  => $summary['newest_expiry'] ?? null,
        'policy_summary' => array_map(static fn($row) => [
            'router_id' => (int)$row['router_id'],
            'router_name' => $row['router_name'] ?? '',
            'auth_type' => $row['auth_type'] ?? 'local',
            'policy_action' => $row['policy_action'] ?? 'disable',
            'expire_ppp_profile' => $row['expire_ppp_profile'] ?? '',
            'expire_hs_profile' => $row['expire_hs_profile'] ?? '',
            'affected_count' => (int)($row['affected_count'] ?? 0),
        ], $policySummary),
        'cutoff_date'    => $todayDate,
        'newest_expired_date' => $newestExpiredDate,
        'router_id'      => $routerScopeId ?: null,
        'timestamp'      => appNow(),
    ]);
    exit;
}

$rows = $pdo->query("
    SELECT s.subscriber_id, s.account_number, s.firstname, s.lastname, s.status, s.subscription_end, s.ppp_username, s.connection_type,
           r.router_id, r.name AS router_name, r.host, r.port, r.api_port, r.username AS r_user, r.password AS r_pass,
           COALESCE(r.auth_type, 'local')       AS auth_type,
           COALESCE(r.status, 'online')          AS r_status,
           COALESCE(rp.on_expire, 'disable')     AS policy_action,
           rp.expire_ppp_profile,
           rp.expire_hs_profile
    FROM subscribers s
    INNER JOIN routers        r  ON r.router_id  = s.router_id
    LEFT JOIN  router_policies rp ON rp.router_id = s.router_id
    WHERE s.status IN ('active','suspended','expired')
      AND DATE(s.subscription_end) <= " . $pdo->quote($newestExpiredDate) . "
      AND s.ppp_username IS NOT NULL
      AND s.ppp_username != ''
      AND r.host IS NOT NULL
      AND r.host != ''
      {$routerWhere}
      {$cursorWhere}
    ORDER BY DATE(s.subscription_end) DESC, s.subscriber_id DESC
    {$limitSql}
")->fetchAll(PDO::FETCH_ASSOC);

$hasMore = false;
if ($limit > 0 && count($rows) > $limit) {
    $hasMore = true;
    array_pop($rows);
}

$processed   = 0;
$sessionsDisconnected = 0;
$errors      = [];
$affectedUsers = [];
$timedOut    = false;
$checkedThisBatch = 0;
$lastSeenSubscriberId = $afterId;
$lastSeenExpiryDate = $afterDate;
$stmtRecheck = $pdo->prepare("SELECT status, subscription_end FROM subscribers WHERE subscriber_id = ?");
$stmtMarkExpired = $pdo->prepare("UPDATE subscribers SET status = 'expired', updated_at = ? WHERE subscriber_id = ? AND status <> 'expired'");

/** @var array<int, RouterosAPI> $apisByRouter */
$apisByRouter = [];
$routerFailures = [];

try {
foreach ($rows as $sub) {
    if ($deadline > 0 && microtime(true) >= $deadline) {
        $timedOut = true;
        break;
    }

    $lastSeenSubscriberId = (int)$sub['subscriber_id'];
    $lastSeenExpiryDate = date('Y-m-d', strtotime((string)$sub['subscription_end']));
    $checkedThisBatch++;

    $stmtRecheck->execute([$sub['subscriber_id']]);
    $current = $stmtRecheck->fetch(PDO::FETCH_ASSOC);
    if (!$current) continue;

    $currentEnd = trim((string)($current['subscription_end'] ?? ''));
    if (
        !in_array(($current['status'] ?? ''), ['active', 'suspended', 'expired'], true) ||
        $currentEnd === '' ||
        date('Y-m-d', strtotime($currentEnd)) > $newestExpiredDate
    ) {
        continue;
    }

    $routerId = (int)$sub['router_id'];
    $routerName = $sub['router_name'] ?? ('Router #' . $routerId);
    if (($sub['r_status'] ?? '') === 'maintenance') {
        if (empty($routerFailures[$routerId])) {
            $errors[] = $routerName . ': Router is under maintenance';
            $routerFailures[$routerId] = true;
        }
        continue;
    }

    if (isset($routerFailures[$routerId])) {
        continue;
    }

    if (!isset($apisByRouter[$routerId])) {
        $routerPass = decryptData($sub['r_pass'] ?? '');
        if ($routerPass === '') {
            $errors[] = $routerName . ': Router password could not be decrypted';
            $routerFailures[$routerId] = true;
            continue;
        }

        $apiTimeout = $isHttp ? 2 : 3;
        $api = new RouterosAPI($sub['host'], (int)($sub['api_port'] ?: $sub['port']), $apiTimeout);
        if (!$api->connect($sub['r_user'], $routerPass)) {
            $errors[] = $routerName
                . ': Router unreachable'
                . (!empty($api->error) ? ' (' . $api->error . ')' : '');
            $routerFailures[$routerId] = true;
            continue;
        }
        $apisByRouter[$routerId] = $api;
    }

    $api = $apisByRouter[$routerId];
    $stmtMarkExpired->execute([appNow(), $sub['subscriber_id']]);
    $sub['status'] = 'expired';

    try {
        $isPPP    = ($sub['connection_type'] ?? 'ppp') === 'ppp';
        $isRadius = ($sub['auth_type'] ?? 'local') === 'radius';
        $uname    = $sub['ppp_username'];
        $action   = $sub['policy_action'];
        $pppProf  = $sub['expire_ppp_profile'] ?? '';
        $hsProf   = $sub['expire_hs_profile']  ?? '';

        $targetProfile = trim((string)($isRadius ? ($pppProf ?: $hsProf) : ($isPPP ? $pppProf : $hsProf)));
        $policyApplied = false;
        $policyResult  = 'disable';
        $policyTarget  = '';

        // Same order as the manual Disconnect Session flow: apply the expired account
        // policy first, then kick active sessions so reconnects inherit the expired state.
        if (in_array($action, ['change_profile', 'change_group'], true) && $targetProfile !== '') {
            if ($isRadius) {
                $policyApplied = $api->setUserManagerUserGroupRobust($uname, $targetProfile, false);
                $policyResult  = 'change_group';
            } elseif ($isPPP) {
                $policyApplied = $api->setPPPSecretProfile($uname, $targetProfile, false);
                $policyResult  = 'change_profile';
            } else {
                $policyApplied = $api->setHotspotUserProfile($uname, $targetProfile, false);
                $policyResult  = 'change_profile';
            }
            $policyTarget = $targetProfile;
        } else {
            if ($isRadius) {
                $policyApplied = $api->setUserManagerUserDisabledRobust($uname, true);
            } elseif ($isPPP) {
                $policyApplied = $api->setPPPSecretDisabled($uname, true);
            } else {
                $policyApplied = $api->setHotspotUserDisabled($uname, true);
            }
        }

        if (!$policyApplied) {
            $label = $policyResult === 'change_group'
                ? 'change group/profile'
                : ($policyResult === 'change_profile' ? 'change profile' : 'disable');
            $errors[] = ($sub['account_number'] ?? 'ID ' . $sub['subscriber_id'])
                . ': Failed to ' . $label . ' user ' . $uname
                . (!empty($api->error) ? ' (' . $api->error . ')' : '');
            continue;
        }

        $backend = forceDisconnectSessionBackend($sub);
        $activeBeforeKeys = forceDisconnectActiveSessionKeys($api, $uname, $backend);
        $userSessionsDisconnected = 0;
        if (!empty($activeBeforeKeys)) {
            $userSessionsDisconnected = forceDisconnectActiveSessions($api, $uname, $backend);
            usleep(350000);
            $activeAfterKeys = forceDisconnectActiveSessionKeys($api, $uname, $backend);
            $stillOriginal = array_intersect($activeBeforeKeys, $activeAfterKeys);

            if (!empty($stillOriginal) && $userSessionsDisconnected > 0) {
                $userSessionsDisconnected += forceDisconnectActiveSessions($api, $uname, $backend);
                usleep(350000);
                $activeAfterKeys = forceDisconnectActiveSessionKeys($api, $uname, $backend);
                $stillOriginal = array_intersect($activeBeforeKeys, $activeAfterKeys);
            }

            if (!empty($stillOriginal)) {
                $errors[] = ($sub['account_number'] ?? 'ID ' . $sub['subscriber_id'])
                    . ': Policy was applied, but active session is still online for user ' . $uname;
            }
        }
        $sessionsDisconnected += $userSessionsDisconnected;

        $subscriberLabel = trim(($sub['firstname'] ?? '') . ' ' . ($sub['lastname'] ?? ''));
        $affectedUsers[] = [
            'subscriber_id' => (int)$sub['subscriber_id'],
            'account_number' => $sub['account_number'] ?? '',
            'subscriber' => $subscriberLabel,
            'username' => $uname,
            'router' => $routerName,
            'connection_type' => $sub['connection_type'] ?? '',
            'auth_type' => $sub['auth_type'] ?? 'local',
            'policy_result' => $policyResult,
            'policy_target' => $policyTarget,
            'policy_applied' => $policyApplied,
            'sessions_disconnected' => $userSessionsDisconnected,
        ];

        $processed++;
    } catch (Throwable $e) {
        $errors[] = ($sub['account_number'] ?? 'ID ' . $sub['subscriber_id']) . ': ' . $e->getMessage();
        if ($deadline > 0 && microtime(true) >= $deadline) {
            $timedOut = true;
            break;
        }
    }
}
} finally {
    foreach ($apisByRouter as $api) {
        try {
            $api->disconnect();
        } catch (Throwable) {}
    }
}

if ($timedOut) {
    $hasMore = true;
    $errors[] = 'Run stopped before the web server timeout. Run Force-Disconnect again to continue the next batch.';
}

try {
    $pdo->prepare("
        INSERT INTO cronjob (run_type, ran_at, processed, errors_count, errors_json)
        VALUES ('force_disconnect', ?, ?, ?, ?)
    ")->execute([
        appNow(),
        $processed,
        count($errors),
        $errors ? json_encode($errors) : null,
    ]);
} catch (Throwable) {}

if (PHP_SAPI !== 'cli') {
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
}

echo json_encode([
    'success'     => true,
    'processed'   => $processed,
    'checked'     => $checkedThisBatch,
    'sessions_disconnected' => $sessionsDisconnected,
    'affected_users' => $affectedUsers,
    'errors'      => $errors,
    'has_more'    => $hasMore,
    'after_id'    => $lastSeenSubscriberId,
    'after_date'  => $lastSeenExpiryDate,
    'batch_limit' => $limit,
    'cutoff_date' => $todayDate,
    'newest_expired_date' => $newestExpiredDate,
    'router_id'   => $routerScopeId ?: null,
    'timestamp'   => appNow(),
]);
