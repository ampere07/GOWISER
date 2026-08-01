<?php
require_once dirname(__DIR__) . '/config/config.php';
header('Content-Type: application/json; charset=utf-8');

requireLogin();
if (!canSendMessages()) {
    jsonResponse(false, 'You are not allowed to send messages.');
}
verifyCsrf();

require_once BASE_PATH . '/lib/SMS.php';

if (!SMS_ENABLED) {
    jsonResponse(false, 'SMS is disabled in configuration');
}

$targetType = trim($_POST['target_type'] ?? '');
$message    = trim($_POST['message']     ?? '');
$routerId   = scopedRouterId((int)($_POST['router_id'] ?? 0));
$barangay   = trim($_POST['barangay']   ?? '');
$subId      = (int)($_POST['subscriber_id'] ?? 0);

if (!$message) { echo json_encode(['success' => false, 'message' => 'Message is required.']); exit; }

$allowed = ['all', 'barangay', 'expired_today', 'single'];
if (!in_array($targetType, $allowed, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid target type.']); exit;
}

// ── Build recipient list ──────────────────────────────────────
$where  = ["contact_number IS NOT NULL AND contact_number != ''"];
$params = [];

if ($routerId) {
    $where[]  = 'router_id = ?';
    $params[] = $routerId;
}

switch ($targetType) {
    case 'barangay':
        if (!$barangay) { echo json_encode(['success' => false, 'message' => 'Barangay is required.']); exit; }
        $where[]  = 'address LIKE ?';
        $params[] = '%' . $barangay . '%';
        $targetLabel = $barangay;
        break;

    case 'expired_today':
        $where[]  = "DATE(subscription_end) = ?";
        $params[] = appToday();
        $targetLabel = 'Expired Today (' . appToday('M d, Y') . ')';
        break;

    case 'single':
        if (!$subId) { echo json_encode(['success' => false, 'message' => 'Subscriber is required.']); exit; }
        $where[]  = 'subscriber_id = ?';
        $params[] = $subId;
        $targetLabel = null; // filled after fetch
        break;

    default: // all
        $targetLabel = 'All Subscribers' . ($routerId ? ' (Router #' . $routerId . ')' : '');
        break;
}

$stmt = db()->prepare('SELECT s.subscriber_id, s.firstname, s.lastname, s.account_number,
    s.contact_number, s.subscription_end,
    DATEDIFF(s.subscription_end, ?) AS days_left,
    p.title AS plan_title
FROM subscribers s
LEFT JOIN plans p ON p.plan_id = s.plan_id
WHERE ' . implode(' AND ', $where));
$stmt->execute(array_merge([appToday()], $params));
$recipients = $stmt->fetchAll();

if (empty($recipients)) {
    echo json_encode(['success' => false, 'message' => 'No subscribers with contact numbers found for this selection.']);
    exit;
}

if ($targetType === 'single' && !empty($recipients[0])) {
    $targetLabel = $recipients[0]['account_number'] . ' — ' . $recipients[0]['firstname'] . ' ' . $recipients[0]['lastname'];
}

// ── Insert notification log header ────────────────────────────
db()->prepare("
    INSERT INTO notifications (router_id, sent_by, target_type, target_label, message)
    VALUES (?, ?, ?, ?, ?)
")->execute([$routerId ?: null, currentUserId(), $targetType, $targetLabel, $message]);
$notifId = (int)db()->lastInsertId();

// ── Variable substitution ─────────────────────────────────────
function sanitizeSmsValue(string $val): string {
    // Strip newlines and control characters to prevent SMS content injection
    return preg_replace('/[\r\n\x00-\x1F\x7F]/', ' ', trim($val));
}

function resolveVars(string $tpl, array $sub): string {
    $expDate  = !empty($sub['subscription_end'])
        ? date('M d, Y', strtotime($sub['subscription_end'])) : '—';
    $daysLeft = max(0, (int)($sub['days_left'] ?? 0));
    return str_replace(
        ['{firstname}', '{lastname}', '{fullname}', '{account_number}',
         '{subscription_end}', '{days_left}', '{plan}', '{contact_number}'],
        [
            sanitizeSmsValue($sub['firstname']      ?? ''),
            sanitizeSmsValue($sub['lastname']       ?? ''),
            sanitizeSmsValue(trim(($sub['firstname'] ?? '') . ' ' . ($sub['lastname'] ?? ''))),
            sanitizeSmsValue($sub['account_number'] ?? ''),
            $expDate,
            (string)$daysLeft,
            sanitizeSmsValue($sub['plan_title']     ?? ''),
            sanitizeSmsValue($sub['contact_number'] ?? ''),
        ],
        $tpl
    );
}

// ── Send and record each recipient ───────────────────────────
$sent   = 0;
$failed = 0;
$errors = [];

$insRecipient = db()->prepare("
    INSERT INTO notification_recipients (notification_id, subscriber_id, contact_number, status, error_message)
    VALUES (?, ?, ?, ?, ?)
");

foreach ($recipients as $sub) {
    $personalizedMsg = resolveVars($message, $sub);
    $result = SMS::send($sub['contact_number'], $personalizedMsg);
    if ($result['success']) {
        $sent++;
        $insRecipient->execute([$notifId, $sub['subscriber_id'], $sub['contact_number'], 'sent', null]);
    } else {
        $failed++;
        $errMsg = $result['error'] ?? 'Unknown error';
        $errors[] = $sub['account_number'] . ': ' . $errMsg;
        $insRecipient->execute([$notifId, $sub['subscriber_id'], $sub['contact_number'], 'failed', substr($errMsg, 0, 255)]);
    }
}

// ── Update totals ─────────────────────────────────────────────
db()->prepare("UPDATE notifications SET total_sent = ?, total_failed = ? WHERE notification_id = ?")
    ->execute([$sent, $failed, $notifId]);

logActivity('notifications', 'create',
    "SMS blast [{$targetType}] — {$sent} sent, {$failed} failed. Message: " . substr($message, 0, 80));

echo json_encode([
    'success'  => true,
    'sent'     => $sent,
    'failed'   => $failed,
    'total'    => count($recipients),
    'errors'   => array_slice($errors, 0, 5),
    'message'  => "{$sent} of " . count($recipients) . " message(s) sent successfully.",
]);
