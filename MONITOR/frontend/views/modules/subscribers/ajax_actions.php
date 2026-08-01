<?php
ob_start();
require_once dirname(__DIR__, 2) . '/config/config.php';
ob_end_clean();
ini_set('display_errors', '0'); // never leak PHP error HTML into a JSON response
header('Content-Type: application/json; charset=utf-8');

requireRole(ROLE_CASHIER, ROLE_ADMIN, ROLE_SUPERADMIN);
verifyCsrf();

require_once BASE_PATH . '/lib/SMS.php';

$action       = $_POST['action']        ?? '';
$subscriberId = (int)($_POST['subscriber_id'] ?? 0);

if (!$subscriberId) {
    jsonResponse(false, 'Missing subscriber_id');
}

$selectedRouterId = selectedRouterId();
$scopeSql = '';
$scopeParams = [];
if (currentRole() !== ROLE_SUPERADMIN || $selectedRouterId) {
    if (!$selectedRouterId) {
        jsonResponse(false, 'Router not selected');
    }
    $scopeSql = ' AND router_id = ?';
    $scopeParams[] = $selectedRouterId;
}

$sub = db()->prepare("SELECT * FROM subscribers WHERE subscriber_id = ?{$scopeSql}");
$sub->execute(array_merge([$subscriberId], $scopeParams));
$subscriber = $sub->fetch();

if (!$subscriber) {
    jsonResponse(false, 'Subscriber not found');
}

switch ($action) {

    // ── Permanently delete pending subscriber ─────────────────
    case 'delete':
        requireRole(ROLE_ADMIN, ROLE_SUPERADMIN);

        if (($subscriber['status'] ?? '') !== SUB_STATUS_PENDING) {
            jsonResponse(false, 'Only pending subscribers can be permanently deleted.');
        }

        try {
            $label = trim(($subscriber['account_number'] ?? '') . ' - ' . ($subscriber['firstname'] ?? '') . ' ' . ($subscriber['lastname'] ?? ''));
            db()->prepare("DELETE FROM subscribers WHERE subscriber_id = ? AND status = ? AND router_id = ?")
                ->execute([$subscriberId, SUB_STATUS_PENDING, (int)$subscriber['router_id']]);

            logActivity('subscribers', 'delete',
                "Permanently deleted pending subscriber {$label}",
                null,
                $subscriber,
                null
            );

            jsonResponse(true, 'Pending subscriber permanently deleted.');
        } catch (Throwable $e) {
            jsonResponse(false, APP_DEBUG ? $e->getMessage() : 'Could not delete subscriber.');
        }

    // ── Send SMS ──────────────────────────────────────────────
    case 'send_sms':
        if (!canSendMessages()) {
            jsonResponse(false, 'You are not allowed to send messages.');
        }
        $message = trim($_POST['message'] ?? '');
        if (empty($message)) {
            jsonResponse(false, 'Message cannot be empty');
        }
        if (mb_strlen($message) > 160) {
            jsonResponse(false, 'Message exceeds 160 characters');
        }
        if (empty($subscriber['contact_number'])) {
            jsonResponse(false, 'Subscriber has no contact number on record');
        }
        if (!SMS_ENABLED) {
            jsonResponse(false, 'SMS is disabled in configuration');
        }

        $result = SMS::send($subscriber['contact_number'], $message);

        if ($result['success']) {
            logActivity('subscribers', 'send_sms',
                "Sent SMS to {$subscriber['account_number']} ({$subscriber['contact_number']}): " . mb_substr($message, 0, 50) . (mb_strlen($message) > 50 ? '…' : ''),
                $subscriberId
            );
            jsonResponse(true, 'SMS sent to ' . $subscriber['contact_number']);
        }

        jsonResponse(false, 'SMS failed: ' . ($result['error'] ?? 'Unknown error'));

    // ── Override Subscription Dates ───────────────────────────
    case 'override_date':
        if (!canOverrideDates()) {
            jsonResponse(false, 'You are not allowed to override subscription dates.');
        }
        $start = $_POST['subscription_start'] ?? '';
        $end   = $_POST['subscription_end']   ?? '';

        if (empty($start) || empty($end)) {
            jsonResponse(false, 'Both start and end dates are required');
        }

        // Basic date validation
        $startTs = strtotime($start);
        $endTs   = strtotime($end);

        if (!$startTs || !$endTs) {
            jsonResponse(false, 'Invalid date format');
        }
        if ($endTs < $startTs) {
            jsonResponse(false, 'End date must be after start date');
        }

        $startFmt = date('Y-m-d', $startTs);
        $endFmt   = date('Y-m-d', $endTs);

        db()->prepare("
            UPDATE subscribers
            SET subscription_start = ?, subscription_end = ?, updated_at = ?
            WHERE subscriber_id = ? AND router_id = ?
        ")->execute([$startFmt, $endFmt, appNow(), $subscriberId, (int)$subscriber['router_id']]);

        logActivity('subscribers', 'override_date',
            "Overrode subscription dates for {$subscriber['account_number']}: {$startFmt} → {$endFmt}",
            $subscriberId
        );

        jsonResponse(true, "Subscription period updated: {$startFmt} to {$endFmt}");

    // ── View Password ─────────────────────────────────────────────
    case 'view_password':
        $plain = decryptData($subscriber['ppp_password'] ?? '');
        if ($plain === '' || $plain === false) {
            jsonResponse(false, 'No password on record for this subscriber');
        }
        logActivity('subscribers', 'view_password',
            "Viewed PPP/Hotspot password for {$subscriber['account_number']}",
            $subscriberId
        );
        jsonResponse(true, 'OK', ['password' => $plain]);

    // ── View Account Password ─────────────────────────────────────
    case 'view_account_password':
        jsonResponse(false, 'Password cannot be displayed (stored as a secure hash).');

    // ── Reset Account Password ────────────────────────────────────
    case 'reset_account':
        if (!canResetAccounts()) {
            jsonResponse(false, 'You are not allowed to reset accounts.');
        }
        $newPass = trim($_POST['new_password'] ?? '');

        if ($newPass === '') {
            jsonResponse(false, 'Password is required');
        }
        if (strlen($newPass) < 8) {
            jsonResponse(false, 'Password must be at least 8 characters');
        }
        if (strlen($newPass) > 255) {
            jsonResponse(false, 'Password is too long');
        }

        $hashed = password_hash($newPass, PASSWORD_DEFAULT);
        db()->prepare("UPDATE subscribers SET password = ?, updated_at = ? WHERE subscriber_id = ? AND router_id = ?")
            ->execute([$hashed, appNow(), $subscriberId, (int)$subscriber['router_id']]);
        logActivity('subscribers', 'reset_account',
            "Reset account password for {$subscriber['account_number']}",
            $subscriberId
        );
        jsonResponse(true, 'Account password reset successfully', ['password' => $newPass]);

    // ── Archive subscriber ────────────────────────────────────────
    case 'archive':
        requireRole(ROLE_ADMIN, ROLE_SUPERADMIN);

        if (($subscriber['status'] ?? '') === SUB_STATUS_ARCHIVED) {
            jsonResponse(false, 'Subscriber is already archived.');
        }

        require_once BASE_PATH . '/lib/RouterosAPI.php';

        $username  = $subscriber['ppp_username'] ?? '';
        $routerLog = '';

        if (!empty($subscriber['router_id']) && $username !== '') {
            $router = db()->prepare("
                SELECT host, port, api_port, username AS r_user, password AS r_pass,
                       COALESCE(auth_type,'local') AS auth_type, status AS r_status
                FROM routers WHERE router_id = ?
            ");
            $router->execute([(int)$subscriber['router_id']]);
            $routerRow = $router->fetch();

            if ($routerRow && $routerRow['r_status'] === ROUTER_ONLINE) {
                $routerPass = decryptData($routerRow['r_pass']);
                if ($routerPass !== '') {
                    $api = new RouterosAPI(
                        $routerRow['host'],
                        (int)($routerRow['api_port'] ?: $routerRow['port']),
                        5
                    );
                    if ($api->connect($routerRow['r_user'], $routerPass)) {
                        try {
                            $isRadius  = ($routerRow['auth_type'] === 'radius');
                            $isHotspot = ($subscriber['connection_type'] ?? 'ppp') === 'hotspot';
                            if ($isRadius) {
                                $api->setUserManagerUserDisabledRobust($username, true);
                                $api->setUserManagerUserComment($username, 'SUBSCRIBER ARCHIVED');
                            } elseif ($isHotspot) {
                                $api->setHotspotUserDisabled($username, true);
                                $api->setHotspotUserComment($username, 'SUBSCRIBER ARCHIVED');
                            } else {
                                $api->setPPPSecretDisabled($username, true);
                                $api->setPPPSecretComment($username, 'SUBSCRIBER ARCHIVED');
                            }
                            $routerLog = 'Router user disabled and comment updated.';
                        } catch (Throwable $e) {
                            error_log('Archive: router comment error for ' . $username . ': ' . $e->getMessage());
                        }
                        $api->disconnect();
                    }
                }
            }
        }

        try {
            db()->prepare("
                UPDATE subscribers
                SET status = ?, updated_at = ?
                WHERE subscriber_id = ?
            ")->execute([SUB_STATUS_ARCHIVED, appNow(), $subscriberId]);

            $label = trim(($subscriber['account_number'] ?? '') . ' - ' . ($subscriber['firstname'] ?? '') . ' ' . ($subscriber['lastname'] ?? ''));
            logActivity('subscribers', 'archive',
                "Archived subscriber {$label}." . ($routerLog ? " {$routerLog}" : ''),
                $subscriberId
            );

            jsonResponse(true, 'Subscriber archived.');
        } catch (Throwable $e) {
            jsonResponse(false, APP_DEBUG ? $e->getMessage() : 'Could not archive subscriber.');
        }

    default:
        jsonResponse(false, 'Unknown action');
}
