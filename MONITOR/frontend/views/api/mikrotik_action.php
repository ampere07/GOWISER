<?php
ob_start();
require_once dirname(__DIR__) . '/config/config.php';
ob_end_clean();
ini_set('display_errors', '0'); // never leak PHP error HTML into a JSON response
header('Content-Type: application/json; charset=utf-8');

$action      = $_POST['action']        ?? '';
$subscriberId= (int)($_POST['subscriber_id'] ?? 0);
$routerId    = (int)($_POST['router_id']     ?? 0);

if ($action === 'disconnect') {
    requireRole(ROLE_CASHIER, ROLE_ADMIN, ROLE_SUPERADMIN);
} else {
    requireMinRole(ROLE_ADMIN);
}
verifyCsrf();

require_once BASE_PATH . '/lib/RouterosAPI.php';

if (!$subscriberId || !$routerId) {
    jsonResponse(false, 'Missing parameters');
}

// Load subscriber + router policy
$sub = db()->prepare("
    SELECT s.*, p.title AS plan_title, p.speed_mbps, p.plan_type,
           r.host, r.port, r.api_port, r.username AS r_user, r.password AS r_pass,
           COALESCE(r.auth_type,'local') AS auth_type,
           COALESCE(r.status,'online') AS r_status,
           COALESCE(rp.on_expire,'disable') AS policy_action,
           rp.expire_ppp_profile,
           rp.expire_hs_profile
    FROM subscribers s
    LEFT JOIN plans p          ON p.plan_id   = s.plan_id
    LEFT JOIN routers r        ON r.router_id = s.router_id
    LEFT JOIN router_policies rp ON rp.router_id = s.router_id
    WHERE s.subscriber_id = ? AND s.router_id = ?
");
$sub->execute([$subscriberId, $routerId]);
$subscriber = $sub->fetch();

if (!$subscriber) {
    jsonResponse(false, 'Subscriber/router not found');
}

if ($subscriber['r_status'] === 'maintenance') {
    jsonResponse(false, 'Router is under maintenance — actions are temporarily disabled.');
}
if ($subscriber['r_status'] !== ROUTER_ONLINE) {
    jsonResponse(false, 'Router is not connected — router actions are disabled.');
}

$routerPass = decryptData($subscriber['r_pass']);
if ($routerPass === '') {
    jsonResponse(false, 'Router password could not be decrypted — re-save the router credentials and try again.');
}

$api = new RouterosAPI(
    $subscriber['host'],
    (int)($subscriber['api_port'] ?: $subscriber['port']),
    5
);

if (!$api->connect($subscriber['r_user'], $routerPass)) {
    jsonResponse(false, 'Cannot connect to router: ' . $api->error);
}

try {
    $result = match ($action) {
        'suspend'   => actionSuspend($api, $subscriber),
        'activate'  => actionActivate($api, $subscriber),
        'disconnect'=> actionDisconnect($api, $subscriber),
        default     => ['success' => false, 'message' => 'Unknown action']
    };
} catch (Throwable $e) {
    error_log('Router action error: ' . $e->getMessage());
    $result = ['success' => false, 'message' => 'Router action error: ' . $e->getMessage()];
}

if (!is_array($result)) {
    $result = ['success' => false, 'message' => 'Router action returned an invalid response.'];
}
$result['success'] = (bool)($result['success'] ?? false);
if (trim((string)($result['message'] ?? '')) === '') {
    $result['message'] = $api->error ?: 'Router action failed. Please check RouterOS API permissions and try again.';
}

if (in_array($action, ['suspend', 'disconnect'], true)) {
    clearRouterSessionCaches((int)$subscriber['router_id']);
}

$api->disconnect();
echo json_encode($result);

// ── Action handlers ───────────────────────────────────────────

function clearRouterSessionCaches(int $routerId): void {
    if ($routerId <= 0) return;
    $cacheDir = BASE_PATH . '/storage/cache';
    @unlink($cacheDir . '/bw_v2_' . $routerId . '.json');
    foreach (glob($cacheDir . '/dash_*.json') ?: [] as $file) {
        @unlink($file);
    }
}

function manualSessionBackend(array $sub): string {
    if (($sub['auth_type'] ?? 'local') === 'radius') return 'user_manager';
    return ($sub['connection_type'] ?? 'ppp') === 'hotspot' ? 'hotspot' : 'ppp';
}

function manualSessionBackendLabel(string $backend): string {
    return match ($backend) {
        'user_manager' => 'User Manager',
        'hotspot'      => 'Hotspot',
        default        => 'PPP',
    };
}

function manualActiveSessionKeys(RouterosAPI $api, string $username, string $backend): array {
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

function disconnectManualActiveSessions(RouterosAPI $api, string $username, string $backend): int {
    return match ($backend) {
        'user_manager' => $api->disconnectUserManagerSessionsRobust($username),
        'hotspot'      => $api->disconnectHotspotSessionsByUserRobust($username),
        default        => $api->disconnectPPPSessionsByNameRobust($username),
    };
}

function actionSuspend(RouterosAPI $api, array $sub): array {
    $ok       = false;
    $username = $sub['ppp_username'] ?? '';
    if (empty($username)) return ['success' => false, 'message' => 'No username configured'];
    $isRadius = ($sub['auth_type'] ?? 'local') === 'radius';
    $backend = manualSessionBackend($sub);
    $backendLabel = manualSessionBackendLabel($backend);
    $activeBeforeKeys = manualActiveSessionKeys($api, $username, $backend);

    if ($isRadius) {
        $ok = $api->setUserManagerUserDisabledRobust($username, true);
    } elseif ($sub['connection_type'] === 'ppp') {
        $ok = $api->setPPPSecretDisabled($username, true);
    } elseif ($sub['connection_type'] === 'hotspot') {
        $ok = $api->setHotspotUserDisabled($username, true);
    }

    if ($ok) {
        $sessionsDisconnected = disconnectManualActiveSessions($api, $username, $backend);
        usleep(350000);
        $activeAfterKeys = manualActiveSessionKeys($api, $username, $backend);
        $stillOriginal = array_intersect($activeBeforeKeys, $activeAfterKeys);

        if (!empty($stillOriginal) || !empty($activeAfterKeys)) {
            $sessionsDisconnected += disconnectManualActiveSessions($api, $username, $backend);
            usleep(350000);
            $activeAfterKeys = manualActiveSessionKeys($api, $username, $backend);
            $stillOriginal = array_intersect($activeBeforeKeys, $activeAfterKeys);
        }

        db()->prepare("UPDATE subscribers SET status = 'suspended', updated_at = ? WHERE subscriber_id = ?")
            ->execute([appNow(), $sub['subscriber_id']]);

        if (!empty($stillOriginal) || !empty($activeAfterKeys)) {
            $remaining = count($activeAfterKeys);
            $message = "{$backendLabel} account was suspended, but {$remaining} active session" . ($remaining === 1 ? ' is' : 's are') . " still online for '{$username}'.";
            logActivity('subscribers', 'suspend', "Suspended account but session still online for {$sub['account_number']}: {$message}", $sub['subscriber_id']);
            return ['success' => false, 'message' => $message . ' Please check RouterOS API permissions for PPP/Hotspot/User Manager active remove.'];
        }

        $sessionMsg = $sessionsDisconnected > 0
            ? " {$sessionsDisconnected} active session" . ($sessionsDisconnected === 1 ? '' : 's') . ' disconnected.'
            : '';
        logActivity('subscribers', 'suspend', "Suspended subscriber {$sub['account_number']} on {$backendLabel}.{$sessionMsg}", $sub['subscriber_id']);
        return ['success' => true, 'message' => "{$backendLabel} account suspended successfully." . $sessionMsg];
    }
    return ['success' => false, 'message' => 'Failed to suspend: ' . $api->error];
}

function actionActivate(RouterosAPI $api, array $sub): array {
    $ok       = false;
    $username = $sub['ppp_username'] ?? '';
    if (empty($username)) return ['success' => false, 'message' => 'No username configured'];

    if ($sub['auth_type'] === 'radius') {
        $ok = $api->setUserManagerUserDisabledRobust($username, false);
    } elseif ($sub['connection_type'] === 'ppp') {
        $ok = $api->setPPPSecretDisabled($username, false);
    } elseif ($sub['connection_type'] === 'hotspot') {
        $ok = $api->setHotspotUserDisabled($username, false);
    }

    if ($ok) {
        db()->prepare("UPDATE subscribers SET status = 'active', updated_at = ? WHERE subscriber_id = ?")
            ->execute([appNow(), $sub['subscriber_id']]);
        logActivity('subscribers', 'activate', "Activated subscriber {$sub['account_number']} on router", $sub['subscriber_id']);
        return ['success' => true, 'message' => 'Subscriber activated successfully'];
    }
    return ['success' => false, 'message' => 'Failed to activate: ' . $api->error];
}

function actionDisconnect(RouterosAPI $api, array $sub): array {
    $username = $sub['ppp_username'] ?? '';
    if (empty($username)) {
        return ['success' => false, 'message' => 'No username configured for this subscriber'];
    }

    $isPPP    = $sub['connection_type'] === 'ppp';
    $isRadius = $sub['auth_type'] === 'radius';
    $backend = manualSessionBackend($sub);
    $backendLabel = manualSessionBackendLabel($backend);
    $status   = strtolower((string)($sub['status'] ?? ''));
    $endDate  = trim((string)($sub['subscription_end'] ?? ''));
    $dateExpired = $endDate !== '' && date('Y-m-d', strtotime($endDate)) < appToday();
    $statusExpired = $status === 'expired';

    $action   = strtolower((string)($sub['policy_action'] ?? 'disable'));   // 'disable', 'change_profile', or 'change_group'
    $pppProf  = $sub['expire_ppp_profile'] ?? '';
    $hsProf   = $sub['expire_hs_profile']  ?? '';
    $targetProfile = trim((string)($isRadius ? ($pppProf ?: $hsProf) : ($isPPP ? $pppProf : $hsProf)));

    $isExpired = $dateExpired || $statusExpired;

    // Step 1 — check subscription state first. Expired accounts get the router
    // policy before the active session is kicked so the client cannot reconnect
    // using the old profile/account state.
    $policyMsg = 'Subscription is still active; router profile/group/account was not changed';
    $policyApplied = true;
    $policyResult = 'none';

    if ($isExpired) {
        $policyMsg = '';
        $policyApplied = false;
        $policyResult = 'disable';

        if (in_array($action, ['change_profile', 'change_group'], true) && $targetProfile !== '') {
            if ($isRadius) {
                $policyApplied = $api->setUserManagerUserGroupRobust($username, $targetProfile, false);
                $policyResult = 'change_group';
                $policyMsg = "RADIUS group/profile changed to '{$targetProfile}'";
            } else {
                if ($isPPP) {
                    $policyApplied = $api->setPPPSecretProfile($username, $targetProfile, false);
                } else {
                    $policyApplied = $api->setHotspotUserProfile($username, $targetProfile, false);
                }
                $policyResult = 'change_profile';
                $policyMsg = "Profile changed to '{$targetProfile}'";
            }
        } else {
            // Policy: disable, or fallback when change_profile/change_group has no target profile configured.
            if ($isRadius) {
                $policyApplied = $api->setUserManagerUserDisabledRobust($username, true);
                $policyMsg = in_array($action, ['change_profile', 'change_group'], true)
                    ? 'RADIUS user disabled (no expired group configured)'
                    : 'RADIUS user disabled';
            } elseif ($isPPP) {
                $policyApplied = $api->setPPPSecretDisabled($username, true);
                $policyMsg = in_array($action, ['change_profile', 'change_group'], true)
                    ? 'Account disabled (no expired profile configured)'
                    : 'Account disabled';
            } else {
                $policyApplied = $api->setHotspotUserDisabled($username, true);
                $policyMsg = in_array($action, ['change_profile', 'change_group'], true)
                    ? 'Account disabled (no expired profile configured)'
                    : 'Account disabled';
            }
        }

        if (!$policyApplied) {
            $failedAction = $policyResult === 'change_group'
                ? 'change RADIUS group/profile'
                : ($policyResult === 'change_profile' ? 'change profile' : 'disable account');
            logActivity('subscribers', 'disconnect',
                "Force-disconnect incomplete for {$sub['account_number']}: Failed to {$failedAction}.",
                $sub['subscriber_id']);
            return [
                'success' => false,
                'message' => "Subscription is expired, but failed to {$failedAction}. " . ($api->error ?: 'Please check the router policy and target group/profile.'),
            ];
        }
    }

    // Step 2 — disconnect the active session, then prove it is gone.
    $activeBeforeKeys = manualActiveSessionKeys($api, $username, $backend);
    $activeBefore = count($activeBeforeKeys);
    if ($activeBefore === 0) {
        $message = "No matching {$backendLabel} active session found on the router for username '{$username}'.";
        $summary = $isExpired
            ? $policyMsg . '. ' . $message
            : $message . ' ' . $policyMsg . '.';
        logActivity('subscribers', 'disconnect',
            "Disconnect skipped for {$sub['account_number']}: {$summary}",
            $sub['subscriber_id']);
        return ['success' => $isExpired, 'message' => $summary];
    }

    $sessionsDisconnected = disconnectManualActiveSessions($api, $username, $backend);
    usleep(350000);
    $activeAfterKeys = manualActiveSessionKeys($api, $username, $backend);
    $stillOriginal = array_intersect($activeBeforeKeys, $activeAfterKeys);

    if (!empty($stillOriginal) && $sessionsDisconnected > 0) {
        $sessionsDisconnected += disconnectManualActiveSessions($api, $username, $backend);
        usleep(350000);
        $activeAfterKeys = manualActiveSessionKeys($api, $username, $backend);
        $stillOriginal = array_intersect($activeBeforeKeys, $activeAfterKeys);
    }

    if (!empty($stillOriginal)) {
        $remaining = count($stillOriginal);
        $message = "{$remaining} original {$backendLabel} active session" . ($remaining === 1 ? ' is' : 's are') . " still online for '{$username}'.";
        $summary = $isExpired
            ? $policyMsg . ', but ' . lcfirst($message)
            : $message . ' No profile/account change was applied.';
        logActivity('subscribers', 'disconnect',
            "Disconnect failed for {$sub['account_number']}: {$summary}",
            $sub['subscriber_id']);
        return ['success' => false, 'message' => $summary . ' Please check RouterOS permissions for PPP/Hotspot/User Manager active remove.'];
    }

    $reconnected = count($activeAfterKeys) > 0;

    $sessionMsg = $sessionsDisconnected > 0
        ? "{$sessionsDisconnected} active session" . ($sessionsDisconnected === 1 ? '' : 's') . ' disconnected. '
        : "{$activeBefore} active session" . ($activeBefore === 1 ? '' : 's') . ' closed. ';

    // Step 3 — return the result based on the subscription state checked above.
    if (!$isExpired) {
        $summary = $sessionMsg
            . ($reconnected
                ? 'The device reconnected immediately because the subscription is still active; router profile/group/account was not changed.'
                : $policyMsg . '.');
        logActivity('subscribers', 'disconnect',
            "Disconnected session only for {$sub['account_number']}: {$summary}",
            $sub['subscriber_id']);
        return ['success' => true, 'message' => $summary];
    }

    $summary = $sessionMsg . $policyMsg . '.';
    logActivity('subscribers', 'disconnect',
        "Force-disconnected {$sub['account_number']}: {$summary}",
        $sub['subscriber_id']);

    return ['success' => true, 'message' => $summary];
}
