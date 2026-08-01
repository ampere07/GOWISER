<?php
ob_start();
require_once dirname(__DIR__) . '/config/config.php';
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

        // Strict date validation — only accept YYYY-MM-DD format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            jsonResponse(false, 'Invalid date format. Use YYYY-MM-DD.');
        }
        $startTs = strtotime($start);
        $endTs   = strtotime($end);

        if (!$startTs || !$endTs) {
            jsonResponse(false, 'Invalid date');
        }

        // Restrict to a reasonable range: 2 years past → 5 years future
        $minTs = strtotime('-2 years');
        $maxTs = strtotime('+5 years');
        if ($startTs < $minTs || $startTs > $maxTs) {
            jsonResponse(false, 'Start date is out of the allowed range (2 years ago – 5 years ahead)');
        }
        if ($endTs < $minTs || $endTs > $maxTs) {
            jsonResponse(false, 'End date is out of the allowed range (2 years ago – 5 years ahead)');
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

    // ── Copy Username ─────────────────────────────────────────────
    case 'copy_username':
        if (empty($subscriber['ppp_username'])) {
            jsonResponse(false, 'No username on record for this subscriber');
        }
        logActivity('subscribers', 'copy_username',
            "Copied PPP/Hotspot username for {$subscriber['account_number']}",
            $subscriberId
        );
        jsonResponse(true, 'OK');

    // ── Copy Password ─────────────────────────────────────────────
    case 'copy_password':
        $plain = decryptData($subscriber['ppp_password'] ?? '');
        if ($plain === '' || $plain === false) {
            jsonResponse(false, 'No password on record for this subscriber');
        }
        logActivity('subscribers', 'copy_password',
            "Copied PPP/Hotspot password for {$subscriber['account_number']}",
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
        if (!preg_match('/[A-Za-z]/', $newPass) || !preg_match('/[0-9]/', $newPass)) {
            jsonResponse(false, 'Password must contain at least one letter and one number');
        }

        $hashed = password_hash($newPass, PASSWORD_DEFAULT);
        db()->prepare("UPDATE subscribers SET password = ?, updated_at = ? WHERE subscriber_id = ? AND router_id = ?")
            ->execute([$hashed, appNow(), $subscriberId, (int)$subscriber['router_id']]);
        logActivity('subscribers', 'reset_account',
            "Reset account password for {$subscriber['account_number']}",
            $subscriberId
        );
        jsonResponse(true, 'Account password reset successfully', ['password' => $newPass]);

    default:
        jsonResponse(false, 'Unknown action');
}
