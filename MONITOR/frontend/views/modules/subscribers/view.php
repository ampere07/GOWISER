<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireRole(ROLE_CASHIER, ROLE_ADMIN, ROLE_SUPERADMIN);

$id = (int)($_GET['id'] ?? 0);
if (!$id) { flashMessage('danger', 'Invalid subscriber.'); redirect(BASE_URL . '/modules/subscribers/'); }

require_once BASE_PATH . '/lib/RouterosAPI.php';

$selectedRouterId = selectedRouterId();
$scopeSql = '';
$scopeParams = [];
if (currentRole() !== ROLE_SUPERADMIN || $selectedRouterId) {
    if (!$selectedRouterId) {
        flashMessage('danger', 'Router not selected.');
        redirect(BASE_URL . '/modules/subscribers/');
    }
    $scopeSql = ' AND s.router_id = ?';
    $scopeParams[] = $selectedRouterId;
}

$sub = db()->prepare("
    SELECT s.*,
           p.title AS plan_title, p.amount AS plan_amount, p.speed_mbps, p.burst_mbps,
           p.billing_cycle, p.plan_type,
           r.name AS router_name, r.host AS router_host, r.status AS router_status,
           r.api_port, r.port AS router_port, r.username AS r_user, r.password AS r_pass,
           COALESCE(r.auth_type, 'local') AS auth_type
    FROM subscribers s
    LEFT JOIN plans   p ON p.plan_id   = s.plan_id
    LEFT JOIN routers r ON r.router_id = s.router_id
    WHERE s.subscriber_id = ?{$scopeSql}
");
$sub->execute(array_merge([$id], $scopeParams));
$subscriber = $sub->fetch();

if (!$subscriber) { flashMessage('danger', 'Subscriber not found.'); redirect(BASE_URL . '/modules/subscribers/'); }

$canSendSubscriberMessage = canSendMessages();
$canOverrideSubscriberDates = canOverrideDates();
$canResetSubscriberAccount = canResetAccounts();
$canViewSubscriberMap = canViewMap();
$canManageSubscriberRouter = canManageRouterAccounts();
$canDisconnectSubscriberSession = hasRole(ROLE_CASHIER, ROLE_ADMIN, ROLE_SUPERADMIN);
$canPrintSensitive = canPrintSensitiveRecords();
$canViewPaymentPortal = hasRole(ROLE_SUPERADMIN);
$hasConnectedRouter = !empty($subscriber['router_id']) && ($subscriber['router_status'] ?? '') === ROUTER_ONLINE;

$paymentPortalHistory = [];
if ($canViewPaymentPortal && tableExists('payment_portal')) {
    $portalStmt = db()->prepare("
        SELECT pp.*, r.name AS router_name
        FROM payment_portal pp
        LEFT JOIN routers r ON r.router_id = pp.router_id
        WHERE pp.subscriber_id = ?
           OR pp.account_number = ?
        ORDER BY pp.initiated_at DESC, pp.payment_id DESC
        LIMIT 50
    ");
    $portalStmt->execute([$id, $subscriber['account_number']]);
    $paymentPortalHistory = $portalStmt->fetchAll();
}

// ── Quick Pay modal handler ─────────────────────────────── 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_pay'])) {
    requireRole(ROLE_CASHIER, ROLE_ADMIN, ROLE_SUPERADMIN);
    verifyCsrf();

    // Return JSON — the form is submitted via fetch() to avoid mod_rewrite POST body loss
    header('Content-Type: application/json');
    $jsonErr = fn(string $msg) => (print json_encode(['success' => false, 'message' => $msg])) && exit();

    require_once BASE_PATH . '/lib/Mailer.php';
    require_once BASE_PATH . '/lib/SMS.php';

    $qpAmount = (float)($_POST['amount'] ?? 0);
    if ($qpAmount <= 0)     { $jsonErr('Amount must be greater than 0.'); }
    if ($qpAmount > 999999) { $jsonErr('Amount exceeds the maximum allowed (₱999,999).'); }

    $qpAllowedStatuses = hasMinRole(ROLE_ADMIN) ? ['paid','pending','refunded','cancelled'] : ['paid','pending'];
    $qpStatus = in_array($_POST['status'] ?? '', $qpAllowedStatuses) ? $_POST['status'] : 'paid';

    $qpRawDate    = trim($_POST['payment_date'] ?? '');
    $qpParsedDate = $qpRawDate ? date('Y-m-d H:i:s', strtotime($qpRawDate)) : appNow();
    if (!hasMinRole(ROLE_ADMIN)) {
        $qpTs = strtotime($qpParsedDate);
        if ($qpTs < strtotime('-14 days')) { $jsonErr('Payment date cannot be more than 14 days in the past. Contact an administrator to backdate further.'); }
        if ($qpTs > strtotime('+1 day'))   { $jsonErr('Payment date cannot be set in the future.'); }
    }

    // Method whitelist — prevent arbitrary strings being stored as payment method
    $qpValidMethods = array_keys(getPaymentMethods());
    $qpMethod    = strtoupper(trim($_POST['method'] ?? 'CASH'));
    if (!in_array($qpMethod, $qpValidMethods, true)) { $qpMethod = 'CASH'; }
    $qpRefNumber = trim($_POST['reference_number'] ?? '');
    if ($qpMethod !== 'CASH' && $qpRefNumber === '') {
        $jsonErr(ucfirst(strtolower($qpMethod)) . ' payments require a reference number.');
    }

    $qpPeriodStart = trim($_POST['period_start'] ?? '') ?: null;
    $qpPeriodEnd   = trim($_POST['period_end']   ?? '') ?: null;
    if ($qpPeriodStart && $qpPeriodEnd) {
        $pDiff = strtotime($qpPeriodEnd) - strtotime($qpPeriodStart);
        if ($pDiff <= 0 || $pDiff > 366 * 86400) { $jsonErr('Billing period must be between 1 and 366 days.'); }
    }

    // Cashier cannot record less than plan price when extending a subscription period
    if (!hasMinRole(ROLE_ADMIN) && $qpPeriodEnd && (float)($subscriber['plan_amount'] ?? 0) > 0) {
        $qpPlanAmount  = (float)$subscriber['plan_amount'];
        $qpBillingDays = getBillingCycleMonths($subscriber['billing_cycle'] ?? 'monthly') * 30;
        $qpStartTs     = $qpPeriodStart ? strtotime($qpPeriodStart) : strtotime(appToday());
        $qpDaysCovered = max(1, (strtotime($qpPeriodEnd) - $qpStartTs) / 86400);
        $qpCycles      = max(1, (int)round($qpDaysCovered / $qpBillingDays));
        $qpMinRequired = round($qpPlanAmount * $qpCycles, 2);
        if ($qpAmount < $qpMinRequired * 0.99) {
            $jsonErr('Amount ₱' . number_format($qpAmount, 2) . ' is below the minimum ₱' . number_format($qpMinRequired, 2) . ' required for the selected period. Only an administrator can override this.');
        }
    }

    $qpTypes   = getPaymentTypes();
    $qpDefType = 0;
    foreach ($qpTypes as $pt) { if ($pt['is_default']) { $qpDefType = (int)$pt['type_id']; break; } }

    $qpTypeId = (int)($_POST['payment_type_id'] ?? $qpDefType) ?: $qpDefType;
    $orNumber = generateORNumber();
    
    // ─── PAYMENT TYPE DETECTION ────────────────────────────
    $paymentNotes = [];

    // Add user's custom notes first
    if (!empty($_POST['notes'])) {
        $paymentNotes[] = trim($_POST['notes']);
    }

    // Determine payment type — mutually exclusive
    $paymentType = 'Regular Payment';
    if (!empty($subscriber['subscription_end']) && !empty($qpPeriodEnd)) {
        $currentEnd = strtotime($subscriber['subscription_end']);
        $newEnd     = strtotime($qpPeriodEnd);
        $daysAdded  = ($newEnd - $currentEnd) / 86400;

        if ($daysAdded >= 30) {
            $paymentType = 'Advance Payment';
        } elseif ($newEnd > $currentEnd) {
            $paymentType = 'Renewal Payment';
        }
    }

    $paymentNotes[] = $paymentType;

    $finalNotes = implode(' | ', $paymentNotes);
    // ─── END PAYMENT TYPE DETECTION ────────────────────────

    // Insert payment with auto-detected notes
    db()->prepare("
        INSERT INTO payments
            (or_number, subscriber_id, router_id, payment_type_id, user_id, amount,
             method, reference_number, payment_date, period_start, period_end, status, notes, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([
        $orNumber, $id, (int)($subscriber['router_id'] ?? 0) ?: null, $qpTypeId, currentUserId(), $qpAmount,
        $qpMethod,
        $qpRefNumber ?: null,
        $qpParsedDate,
        $qpPeriodStart,
        $qpPeriodEnd,
        $qpStatus,
        $finalNotes, // <- Gumagamit na ng auto-detected notes
        appNow(),
    ]);
    $payId = (int)db()->lastInsertId();

    if ($qpPeriodEnd) {
        db()->prepare("
            UPDATE subscribers SET
                subscription_start = ?,
                subscription_end   = ?,
                status             = 'active',
                updated_at         = ?
            WHERE subscriber_id = ? AND router_id = ?
        ")->execute([$qpPeriodStart ?: appToday(), $qpPeriodEnd, appNow(), $id, (int)$subscriber['router_id']]);

        // Re-activate subscriber's MikroTik account (reverses on_expire policy)
        reactivateSubscriberOnRouter($subscriber);
    }

    logActivity('payments', 'create',
        "Payment OR {$orNumber} for subscriber ID {$id}: ₱" . number_format($qpAmount, 2), $id);

    if (SMS_ENABLED && !empty($_POST['notify_sms']) && !empty($subscriber['contact_number'])) {
        SMS::sendPaymentConfirmation($subscriber, ['payment_id' => $payId, 'amount' => $qpAmount,
            'period_end' => $qpPeriodEnd ?? '', 'or_number' => $orNumber]);
    }

    flashMessage('success', "Payment recorded. OR #{$orNumber}");
    echo json_encode(['success' => true, 'redirect' => BASE_URL . "/modules/payments/receipt/{$payId}"]);
    exit;
}
// Payment year filter
$filterYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$availYears = db()->prepare(
    "SELECT DISTINCT YEAR(payment_date) AS yr FROM payments WHERE subscriber_id = ? ORDER BY yr DESC"
);
$availYears->execute([$id]);
$availYears = $availYears->fetchAll(PDO::FETCH_COLUMN);

// Payment history — default payment type (Subscription / Subscription Fee)
$payWhere  = 'py.subscriber_id = ? AND pt.is_default = 1';
$payParams = [$id];
if ($filterYear) {
    $payWhere   .= ' AND YEAR(py.payment_date) = ?';
    $payParams[] = $filterYear;
}
$payments = db()->prepare("
    SELECT py.*, pt.name AS type_name,
           u.firstname  AS cashier_first,  u.lastname  AS cashier_last,
           ue.firstname AS editor_first,   ue.lastname AS editor_last
    FROM payments py
    INNER JOIN payment_types pt ON pt.type_id = py.payment_type_id
    LEFT JOIN  users u          ON u.user_id  = py.user_id
    LEFT JOIN  users ue         ON ue.user_id = py.updated_by
    WHERE {$payWhere}
    ORDER BY py.payment_date DESC
    LIMIT 100
");
$payments->execute($payParams);
$paymentHistory = $payments->fetchAll();

// Total paid — Subscription type only
// Balance / MRC calculation — scoped to current subscription period so that plan
// changes (upgrade/downgrade) don't produce phantom credits from old-plan payments.
$mrc          = (float)($subscriber['plan_amount'] ?? 0);
$billingCycle = $subscriber['billing_cycle'] ?? 'monthly';
$cycleDays    = match($billingCycle) {
    'quarterly' => 90,
    'annual'    => 365,
    default     => 30,
};

$subStart = $subscriber['subscription_start'];

// Only sum payments made on or after subscription_start (current plan period).
$totalPaidStmt = db()->prepare("
    SELECT COALESCE(SUM(py.amount), 0)
    FROM payments py
    INNER JOIN payment_types pt ON pt.type_id = py.payment_type_id
    WHERE py.subscriber_id = ? AND py.status = 'paid' AND pt.is_default = 1
      AND (? IS NULL OR COALESCE(DATE(py.period_start), DATE(py.payment_date)) >= ?)
");
$totalPaidStmt->execute([$id, $subStart, $subStart]);
$totalPaid = (float)$totalPaidStmt->fetchColumn();

// Use subscription_start as baseline; it is updated to period_start on every renewal,
// so normal renewals continue to count correctly while plan changes reset the clock.
$baseDate    = $subStart;
$startTs     = $baseDate ? strtotime($baseDate) : 0;
$sameDay     = ($startTs > 0 && date('Y-m-d', $startTs) === date('Y-m-d'));

// For expired subscriptions, cap elapsed days at subscription_end rather than today.
// This prevents the day-count formula from charging for cycles beyond what the subscriber
// enrolled in (pay-as-you-go subscribers are not automatically billed after expiry).
$subEndTs     = $subscriber['subscription_end'] ? strtotime($subscriber['subscription_end']) : 0;
$isSubExpired = $subEndTs > 0 && $subEndTs < time();
$effectiveNow = ($isSubExpired && $subEndTs > $startTs) ? $subEndTs : time();
$daysElapsed  = $startTs > 0 ? max(0, ($effectiveNow - $startTs) / 86400) : 0;

// Use floor() for expired subscriptions: calendar months (30-31 days) can cause ceil()
// to round a single paid month up to 2 expected cycles (e.g. ceil(31/30) = 2).
// floor() correctly counts only complete cycles within the subscription period.
if ($isSubExpired) {
    $cyclesStarted = ($startTs > 0 && !$sameDay && $cycleDays > 0)
        ? (int)floor($daysElapsed / $cycleDays)
        : 0;
} else {
    $cyclesStarted = ($startTs > 0 && !$sameDay && $cycleDays > 0)
        ? (int)ceil($daysElapsed / $cycleDays)
        : 0;
}
$expectedAmount = round($cyclesStarted * $mrc, 2);
$balance        = round($totalPaid - $expectedAmount, 2);

// Modal period defaults
$subEndDate       = $subscriber['subscription_end'] ? date('Y-m-d', strtotime($subscriber['subscription_end'])) : '';
$modalPeriodStart = ($subEndDate && !isExpired($subEndDate))
    ? date('Y-m-d', strtotime($subEndDate . ' +1 day'))
    : date('Y-m-d');
$modalPeriodEnd   = match($billingCycle) {
    'quarterly' => date('Y-m-d', strtotime($modalPeriodStart . ' +3 months')),
    'annual'    => date('Y-m-d', strtotime($modalPeriodStart . ' +1 year')),
    default     => date('Y-m-d', strtotime($modalPeriodStart . ' +1 month')),
};

// Payment types for modal
$modalPayTypes  = getPaymentTypes();
$modalDefTypeId = 0;
foreach ($modalPayTypes as $pt) { if ($pt['is_default']) { $modalDefTypeId = (int)$pt['type_id']; break; } }

$routerPolicy = ['on_expire' => 'disable', 'expire_ppp_profile' => '', 'expire_hs_profile' => ''];
if (!empty($subscriber['router_id']) && tableExists('router_policies')) {
    try {
        $policyStmt = db()->prepare("SELECT on_expire, expire_ppp_profile, expire_hs_profile FROM router_policies WHERE router_id = ?");
        $policyStmt->execute([(int)$subscriber['router_id']]);
        $routerPolicy = $policyStmt->fetch() ?: $routerPolicy;
    } catch (Throwable) {}
}

$policyAction = strtolower((string)($routerPolicy['on_expire'] ?? 'disable'));
$policyIsRadius = (($subscriber['auth_type'] ?? 'local') === 'radius');
$policyIsPpp = (($subscriber['connection_type'] ?? 'ppp') === 'ppp');
$policyPppTarget = trim((string)($routerPolicy['expire_ppp_profile'] ?? ''));
$policyHsTarget = trim((string)($routerPolicy['expire_hs_profile'] ?? ''));
$policyTarget = $policyIsRadius
    ? ($policyPppTarget !== '' ? $policyPppTarget : $policyHsTarget)
    : ($policyIsPpp ? $policyPppTarget : $policyHsTarget);
$policyTargetKind = $policyIsRadius ? 'RADIUS group' : 'profile';
$policyTitle = 'Disable account';
$policyBadge = 'danger';
$policyDetail = $policyIsRadius
    ? 'The User Manager account will be disabled after the active session is disconnected.'
    : 'The router account will be disabled after the active session is disconnected.';

if (in_array($policyAction, ['change_profile', 'change_group'], true)) {
    if ($policyTarget !== '') {
        $policyTitle = $policyIsRadius ? 'Change RADIUS group' : 'Change profile';
        $policyBadge = 'warning';
        $policyDetail = 'After disconnecting active sessions, the account will be moved to the expired ' . $policyTargetKind . ' "' . $policyTarget . '".';
    } else {
        $policyTitle = 'Disable account fallback';
        $policyBadge = 'danger';
        $policyDetail = 'The router policy is set to change ' . $policyTargetKind . ', but no expired target is configured for this connection type. The account will be disabled instead.';
    }
}

$disconnectPolicy = [
    'router' => $subscriber['router_name'] ?? '',
    'auth' => $policyIsRadius ? 'RADIUS / User Manager' : 'Local PPP / Hotspot',
    'connection' => strtoupper((string)($subscriber['connection_type'] ?? '')),
    'action' => $policyAction,
    'title' => $policyTitle,
    'badge' => $policyBadge,
    'target_kind' => $policyTargetKind,
    'target' => $policyTarget,
    'detail' => $policyDetail,
];

$currency    = getSetting('currency_symbol', '₱');
$isVipFree   = (strtoupper(trim($subscriber['plan_title'] ?? '')) === 'VIP' && (float)($subscriber['plan_amount'] ?? 0) == 0.0);

$pageTitle   = $subscriber['firstname'] . ' ' . $subscriber['lastname'];
$breadcrumbs = [
    ['label' => 'Subscribers', 'url' => BASE_URL . '/modules/subscribers/'],
    ['label' => $subscriber['account_number']],
];
$extraHead = '
<link rel="stylesheet" href="' . BASE_URL . '/assets/vendor/leaflet/leaflet.css">
<style>
    .leaflet-pane,.leaflet-tile,.leaflet-marker-icon,
    .leaflet-tile-container,.leaflet-overlay-pane svg,
    .leaflet-zoom-box,.leaflet-image-layer,.leaflet-layer{z-index:auto!important;}
</style>';
include BASE_PATH . '/includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between mb-4 gap-2">
    <div class="d-flex align-items-center gap-3">
        <div class="avatar-circle-lg bg-primary">
            <?= strtoupper(substr($subscriber['firstname'], 0, 1)) ?>
        </div>
        <div>
            <h4 class="fw-bold mb-0"><?= e($subscriber['firstname'] . ' ' . $subscriber['lastname']) ?></h4>
            <div class="d-flex align-items-center gap-2 text-muted small flex-wrap">
                <span class="font-monospace"><?= e($subscriber['account_number']) ?></span>
                <span>·</span>
                <?= subStatusBadge($subscriber['status']) ?>
                <span>·</span>
                <span class="badge <?= $subscriber['connection_type'] === 'ppp' ? 'bg-primary' : 'bg-info text-dark' ?>">
                    <?= strtoupper($subscriber['connection_type']) ?>
                </span>
                <?php if (($subscriber['auth_type'] ?? 'local') === 'radius'): ?>
                <span>·</span>
                <span class="badge bg-warning text-dark">RADIUS</span>
                <?php endif; ?>
                <?php if (!empty($subscriber['router_id'])): ?>
                <span>·</span>
                <span id="clientOnlineIndicator" class="badge bg-secondary">
                    <span class="spinner-border spinner-border-sm" style="width:.55rem;height:.55rem;border-width:1px;"></span>
                    Checking…
                </span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="location.reload()" title="Refresh page">
            <i class="bi bi-arrow-clockwise"></i>
        </button>
        <?php if (($subscriber['status'] ?? '') !== SUB_STATUS_ARCHIVED): ?>
        <?php if (canModifyRecords()): ?>
        <a href="<?= BASE_URL ?>/modules/subscribers/edit/<?= $id ?>" class="btn btn-primary btn-sm">
            <i class="bi bi-pencil me-1"></i>Edit
        </a>
        <?php endif; ?>
        <?php if (($subscriber['status'] ?? '') === SUB_STATUS_PENDING && hasRole(ROLE_CASHIER, ROLE_ADMIN, ROLE_SUPERADMIN)): ?>
        <a href="<?= BASE_URL ?>/modules/subscribers/review/<?= $id ?>" class="btn btn-warning btn-sm">
            <i class="bi bi-clipboard-check me-1"></i>Review
        </a>
        <?php endif; ?>
        <?php if (($subscriber['status'] ?? '') === SUB_STATUS_PENDING && hasMinRole(ROLE_ADMIN)): ?>
        <button type="button" class="btn btn-outline-danger btn-sm" id="deletePendingSubscriberBtn"
                data-id="<?= (int)$id ?>"
                data-name="<?= e(trim(($subscriber['firstname'] ?? '') . ' ' . ($subscriber['lastname'] ?? ''))) ?>">
            <i class="bi bi-trash me-1"></i>Delete
        </button>
        <?php endif; ?>
        <?php if (canModifyRecords()): ?>
        <button type="button" class="btn btn-success btn-sm"
                <?= $isVipFree ? 'disabled title="VIP plan — no payment required"' : 'data-bs-toggle="modal" data-bs-target="#recordPaymentModal"' ?>>
            <i class="bi bi-cash-coin me-1"></i>Record Payment
        </button>
        <?php endif; ?>
        <?php if ($canSendSubscriberMessage || $canOverrideSubscriberDates || $canResetSubscriberAccount || $canViewSubscriberMap || $canManageSubscriberRouter || $canDisconnectSubscriberSession || $canViewPaymentPortal || hasMinRole(ROLE_ADMIN)): ?>
        <div class="dropdown">
            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                <i class="bi bi-gear me-1"></i>Actions
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><h6 class="dropdown-header">General</h6></li>
                <?php if ($canSendSubscriberMessage): ?>
                <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#sendSmsModal">
                    <i class="bi bi-chat-dots me-2 text-primary"></i>Send Message
                </a></li>
                <?php endif; ?>
                <?php if ($canOverrideSubscriberDates): ?>
                <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#overrideDateModal">
                    <i class="bi bi-calendar-range me-2 text-info"></i>Override Date
                </a></li>
                <?php endif; ?>
                <?php if ($canResetSubscriberAccount): ?>
                <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#resetAccountModal">
                    <i class="bi bi-key me-2 text-warning"></i>Reset Account
                </a></li>
                <?php endif; ?>
                <?php if ($canViewSubscriberMap): ?>
                <li><a class="dropdown-item <?= (empty($subscriber['latitude']) || empty($subscriber['longitude'])) ? 'disabled text-muted' : '' ?>"
                       href="#" data-bs-toggle="modal" data-bs-target="#viewMapModal"
                       title="<?= (empty($subscriber['latitude']) || empty($subscriber['longitude'])) ? 'No coordinates on record' : 'View on map' ?>">
                    <i class="bi bi-geo-alt me-2 text-success"></i>View in Map
                </a></li>
                <?php endif; ?>
                <?php if ($canViewPaymentPortal): ?>
                <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#paymentPortalModal">
                    <i class="bi bi-window-sidebar me-2 text-primary"></i>Payment Portal
                </a></li>
                <?php endif; ?>
                <?php if (($canManageSubscriberRouter || $canDisconnectSubscriberSession) && $hasConnectedRouter): ?>
                <li><hr class="dropdown-divider"></li>
                <li><h6 class="dropdown-header">Router</h6></li>
                <?php if ($canDisconnectSubscriberSession): ?>
                <li><a class="dropdown-item router-action" href="#"
                       data-action="disconnect" data-id="<?= $id ?>" data-router="<?= $subscriber['router_id'] ?>">
                    <i class="bi bi-x-circle me-2 text-warning"></i>Disconnect Session
                </a></li>
                <?php endif; ?>
                <?php if ($canManageSubscriberRouter): ?>
                <li><hr class="dropdown-divider"></li>
                <?php if ($subscriber['status'] === 'active'): ?>
                <li><a class="dropdown-item router-action" href="#"
                       data-action="suspend" data-id="<?= $id ?>" data-router="<?= $subscriber['router_id'] ?>">
                    <i class="bi bi-pause-circle me-2 text-danger"></i>Suspend Account
                </a></li>
                <?php else: ?>
                <li><a class="dropdown-item router-action" href="#"
                       data-action="activate" data-id="<?= $id ?>" data-router="<?= $subscriber['router_id'] ?>">
                    <i class="bi bi-play-circle me-2 text-success"></i>Activate Account
                </a></li>
                <?php endif; ?>
                <?php endif; ?>
                <?php endif; ?>
                <?php if (hasMinRole(ROLE_ADMIN)): ?>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="#" id="archiveSubscriberBtn"
                       data-id="<?= $id ?>"
                       data-name="<?= e(trim(($subscriber['firstname'] ?? '') . ' ' . ($subscriber['lastname'] ?? ''))) ?>">
                    <i class="bi bi-archive me-2 text-warning"></i>Archive Subscriber
                </a></li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>
        <?php endif; // not archived ?>
    </div>
</div>

<?php if (($subscriber['status'] ?? '') === SUB_STATUS_ARCHIVED): ?>
<?= inlineToasts([['message' => 'Archived Subscriber — This account has been archived.', 'type' => 'warning']]) ?>
<?php endif; ?>

<?php
// Subscription summary bar (placed at top, before balance card)
$subStart   = $subscriber['subscription_start'];
$subEnd     = $subscriber['subscription_end'];
$daysLeftHdr = subscriptionDaysLeft($subEnd);
$isExpiredHdr = $subEnd && isExpired($subEnd);
if (($subStart || $subEnd) && ($subscriber['status'] ?? '') !== SUB_STATUS_ARCHIVED):
    $barBg  = $isExpiredHdr ? 'bg-danger-subtle' : ($daysLeftHdr <= 5 ? 'bg-warning-subtle' : 'bg-info-subtle');
    $barClr = $isExpiredHdr ? 'text-danger' : ($daysLeftHdr <= 5 ? 'text-warning' : 'text-info');
    $barIcon= $isExpiredHdr ? 'bi-calendar-x' : ($daysLeftHdr <= 5 ? 'bi-calendar-exclamation' : 'bi-calendar-check');
?>
<div class="rounded-3 px-3 py-2 mb-3 d-flex flex-wrap align-items-center gap-2 <?= $barBg ?>" style="border:1px solid currentColor;border-color:rgba(0,0,0,.08)!important;">
    <i class="bi <?= $barIcon ?> <?= $barClr ?> fs-6"></i>
    <div class="small">
        <?php if ($subStart): ?>
        <span class="text-muted">Subscription Start:</span>
        <strong><?= formatDate($subStart, 'M d, Y') ?></strong>
        <?php endif; ?>
        <?php if ($subEnd): ?>
        <span class="text-muted ms-2">End:</span>
        <strong class="<?= $isExpiredHdr ? 'text-danger' : ($daysLeftHdr <= 5 ? 'text-warning' : '') ?>">
            <?= formatDate($subEnd, 'M d, Y') ?>
        </strong>
        <span class="ms-2 fw-semibold <?= $isExpiredHdr ? 'text-danger' : ($daysLeftHdr <= 5 ? 'text-warning' : 'text-muted') ?>">
            &mdash; <?= $isExpiredHdr ? 'Expired' : "<strong>{$daysLeftHdr}</strong> day" . ($daysLeftHdr !== 1 ? 's' : '') . ' remaining' ?>
        </span>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($mrc > 0): ?>
<div class="d-flex align-items-center gap-2 mb-4 px-1">
    <?php
        $isNegBal    = $balance < -50;
        $isAdvanceBal = $isNegBal && !$isSubExpired;
        $isOverdueBal = $isNegBal && $isSubExpired;
        $hdrIcon  = $isOverdueBal ? 'bi-exclamation-triangle-fill text-danger'
                  : ($isAdvanceBal ? 'bi-calendar-check-fill text-primary'
                  : ($balance > 50 ? 'bi-check-circle-fill text-success' : 'bi-info-circle-fill text-secondary'));
        $hdrBadge = $isOverdueBal ? 'bg-danger'  : ($isAdvanceBal ? 'bg-primary' : ($balance > 50 ? 'bg-success' : 'bg-secondary'));
        $hdrLabel = $isOverdueBal ? 'Overdue'    : ($isAdvanceBal ? 'Advance Payment' : ($balance > 50 ? 'Credit' : 'Current'));
    ?>
    <i class="bi <?= $hdrIcon ?> small"></i>
    <span class="small text-muted">Balance / MRC</span>
    <span class="fw-bold <?= $balance < 0 ? 'text-danger' : 'text-success' ?>">
        <?= ($balance < 0 ? '−' : '+') . formatMoney(abs($balance)) ?>
    </span>
    <span class="text-muted small">/</span>
    <span class="fw-semibold small"><?= formatMoney($mrc) ?></span>
    <span class="badge <?= $hdrBadge ?> ms-1" style="font-size:.65rem;">
        <?= $hdrLabel ?>
    </span>
</div>
<?php endif; ?>

<div class="row g-4">
    <!-- Left: Info cards -->
    <div class="col-lg-4">
        <!-- Personal Info -->
        <div class="card border-0 mb-3">
            <div class="card-header bg-transparent border-bottom fw-semibold py-3">
                Personal Information
            </div>
            <div class="card-body">
                <?php
                $fields = [
                    'Full Name' => $subscriber['firstname'] . ' ' . ($subscriber['middlename'] ? $subscriber['middlename'].' ' : '') . $subscriber['lastname'],
                    'Contact'   => $subscriber['contact_number'] ?? '—',
                    'Email'     => $subscriber['email'] ?? '—',
                ];
                foreach ($fields as $label => $val):
                ?>
                <div class="d-flex mb-2">
                    <span class="text-muted small me-2" style="min-width:80px;"><?= $label ?></span>
                    <span class="small fw-medium"><?= e($val) ?></span>
                </div>
                <?php endforeach; ?>
                <?php
                $addrFull  = $subscriber['address'] ?? '—';
                $copyParts = array_filter([
                    $subscriber['street']       ?? '',
                    $subscriber['barangay']     ?? '',
                    $subscriber['municipality'] ?? '',
                ]);
                $copyAddr  = implode(', ', $copyParts);
                ?>
                <div class="d-flex mb-2 align-items-start">
                    <span class="text-muted small me-2 flex-shrink-0" style="min-width:80px;">Address</span>
                    <span class="small fw-medium flex-grow-1"><?= e($addrFull) ?></span>
                    <?php if ($copyAddr): ?>
                    <button type="button"
                            class="btn btn-link btn-sm p-0 ms-1 flex-shrink-0 text-muted"
                            title="Copy Street, Barangay, Municipality"
                            onclick="copyAddress(this, <?= htmlspecialchars(json_encode($copyAddr), ENT_QUOTES) ?>)">
                        <i class="bi bi-copy" style="font-size:.8rem;"></i>
                    </button>
                    <?php endif; ?>
                </div>
                <?php
                $hasCoordsPi = !empty($subscriber['latitude']) && !empty($subscriber['longitude']);
                $piLat = $hasCoordsPi ? number_format((float)$subscriber['latitude'],  6) : null;
                $piLng = $hasCoordsPi ? number_format((float)$subscriber['longitude'], 6) : null;
                ?>
                <div class="d-flex mb-2 align-items-center">
                    <span class="text-muted small me-2" style="min-width:80px;">Location</span>
                    <?php if ($hasCoordsPi): ?>
                    <a href="#" data-bs-toggle="modal" data-bs-target="#viewMapModal"
                       class="small fw-medium text-decoration-none d-flex align-items-center gap-1"
                       title="View on map">
                        <i class="bi bi-geo-alt-fill text-success" style="font-size:.8rem;"></i>
                        <span class="font-monospace"><?= $piLat ?>, <?= $piLng ?></span>
                        <i class="bi bi-box-arrow-up-right text-muted" style="font-size:.7rem;"></i>
                    </a>
                    <?php else: ?>
                    <span class="small text-muted">—</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Connection Info -->
        <div class="card border-0 mb-3">
            <div class="card-header bg-transparent border-bottom fw-semibold py-3">
                Connection
            </div>
            <div class="card-body">
                <?php if (!empty($subscriber['router_id'])): ?>
                <div class="d-flex mb-3 align-items-center">
                    <span class="text-muted small me-2" style="min-width:80px;">Status</span>
                    <span id="connStatusBadge" class="badge bg-secondary d-flex align-items-center gap-1" style="font-size:.75rem;">
                        <span class="spinner-border spinner-border-sm" style="width:.55rem;height:.55rem;border-width:1px;"></span>
                        Checking…
                    </span>
                </div>
                <?php endif; ?>
                <div class="d-flex mb-2">
                    <span class="text-muted small me-2" style="min-width:80px;">Type</span>
                    <span class="badge <?= $subscriber['connection_type'] === 'ppp' ? 'bg-primary' : 'bg-info text-dark' ?>">
                        <?= strtoupper($subscriber['connection_type']) ?>
                    </span>
                </div>
                <div class="d-flex mb-2">
                    <span class="text-muted small me-2" style="min-width:80px;">Auth</span>
                    <?php if (($subscriber['auth_type'] ?? 'local') === 'radius'): ?>
                    <span class="badge bg-warning text-dark">RADIUS / User Manager</span>
                    <?php else: ?>
                    <span class="badge bg-secondary">Local</span>
                    <?php endif; ?>
                </div>
                <?php if ($subscriber['ppp_username']): ?>
                <div class="d-flex mb-2 align-items-center">
                    <span class="text-muted small me-2" style="min-width:80px;">Username</span>
                    <span class="small font-monospace fw-medium" id="pppUsernameDisplay"><?= e($subscriber['ppp_username']) ?></span>
                    <button type="button" class="btn btn-link btn-sm p-0 ms-2 text-secondary"
                            id="copyUsernameBtn" title="Copy username">
                        <i class="bi bi-copy" id="copyUsernameIcon"></i>
                    </button>
                </div>
                <?php endif; ?>
                <?php if (!empty($subscriber['ppp_password']) && canModifyRecords()): ?>
                <div class="d-flex mb-2 align-items-center">
                    <span class="text-muted small me-2" style="min-width:80px;">Password</span>
                    <span class="small font-monospace fw-medium text-muted" id="pppPasswordDisplay">••••••••</span>
                    <button type="button" class="btn btn-link btn-sm p-0 ms-2 text-secondary"
                            id="revealPasswordBtn" title="Reveal password">
                        <i class="bi bi-eye" id="revealPasswordIcon"></i>
                    </button>
                    <button type="button" class="btn btn-link btn-sm p-0 ms-1 text-secondary"
                            id="copyPasswordBtn" title="Copy password">
                        <i class="bi bi-copy" id="copyPasswordIcon"></i>
                    </button>
                </div>
                <?php endif; ?>
                <?php if ($subscriber['ppp_profile']): ?>
                <div class="d-flex mb-2">
                    <span class="text-muted small me-2" style="min-width:80px;">Profile</span>
                    <span class="small font-monospace"><?= e($subscriber['ppp_profile']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($subscriber['plan_title']): ?>
                <div class="d-flex mb-2">
                    <span class="text-muted small me-2" style="min-width:80px;">Plan</span>
                    <span class="small"><?= e($subscriber['plan_title']) ?> (<?= $subscriber['speed_mbps'] ?>Mbps)</span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Live Session (if router available) — moved here from right column -->
        <?php if (!empty($subscriber['router_id'])): ?>
        <div class="card border-0 mb-3">
            <div class="card-header bg-transparent border-bottom d-flex justify-content-between align-items-center py-3">
                <div class="d-flex align-items-center gap-2">
                    <span class="fw-semibold">Live Session</span>
                    <span class="badge bg-success-subtle text-success border border-success-subtle align-items-center gap-1 d-none" id="sessionStatusBadge">
                        <span class="status-dot bg-success" style="width:7px;height:7px;"></span>Online
                    </span>
                </div>
                <button class="btn btn-sm btn-outline-secondary" id="refreshSession" title="Refresh">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
            </div>
            <div class="card-body p-3" id="liveSession">
                <div class="text-center text-muted py-4">
                    <div class="spinner-border spinner-border-sm me-2"></div>Loading session data…
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right: Subscription + Payments -->
    <div class="col-lg-8">
        <!-- Subscription Period — moved to top of right column -->
        <?php
        $daysLeft = subscriptionDaysLeft($subscriber['subscription_end']);
        $expPct   = 0;
        if ($subscriber['subscription_start'] && $subscriber['subscription_end']) {
            $total  = strtotime($subscriber['subscription_end']) - strtotime($subscriber['subscription_start']);
            $used   = time() - strtotime($subscriber['subscription_start']);
            $expPct = $total > 0 ? max(0, min(100, round($used / $total * 100))) : 0;
        }
        ?>
        <div class="card border-0 mb-3">
            <div class="card-header bg-transparent border-bottom fw-semibold py-3">
                Subscription
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-4">
                        <div class="text-muted small mb-1">Start</div>
                        <div class="fw-semibold small"><?= formatDate($subscriber['subscription_start']) ?: '—' ?></div>
                    </div>
                    <div class="col-sm-4">
                        <div class="text-muted small mb-1">End</div>
                        <div class="fw-semibold small <?= $daysLeft <= 3 ? 'text-danger' : '' ?>">
                            <?= formatDate($subscriber['subscription_end']) ?: '—' ?>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="text-muted small mb-1">Remaining</div>
                        <div class="fw-semibold small">
                            <?php if ($subscriber['subscription_end']): ?>
                            <?= isExpired($subscriber['subscription_end'])
                                ? '<span class="text-danger">Expired</span>'
                                : "<strong>{$daysLeft}</strong> day" . ($daysLeft !== 1 ? 's' : '') ?>
                            <?php else: ?>—<?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php if ($subscriber['subscription_end']): ?>
                <div class="progress mt-3" style="height:6px;">
                    <div class="progress-bar <?= $expPct > 90 ? 'bg-danger' : ($expPct > 70 ? 'bg-warning' : 'bg-success') ?>"
                         style="width:<?= $expPct ?>%"></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payment History -->
        <div class="card border-0">
            <div class="card-header bg-transparent border-bottom py-3">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span class="fw-semibold">Payment History</span>
                    <div class="d-flex gap-2 align-items-center flex-wrap">
                        <?php if (!empty($availYears)): ?>
                        <form method="GET" action="" class="d-flex gap-1 align-items-center">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <select name="year" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto;">
                                <option value="" <?= !isset($_GET['year']) && !in_array((int)date('Y'), $availYears) ? 'selected' : '' ?>>All Years</option>
                                <?php foreach ($availYears as $yr): ?>
                                <option value="<?= $yr ?>" <?= $filterYear == $yr ? 'selected' : '' ?>><?= $yr ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <?php endif; ?> 
                        <?php if (canModifyRecords()): ?>
                        <button type="button" class="btn btn-sm btn-success"
                                <?= $isVipFree ? 'disabled title="VIP plan — no payment required"' : 'data-bs-toggle="modal" data-bs-target="#recordPaymentModal"' ?>>
                            <i class="bi bi-plus me-1"></i>Record Payment
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="card-body p-0" id="payHistoryPrint">
                <?php if (empty($paymentHistory)): ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-cash d-block fs-3 mb-2"></i>No payment records<?= $filterYear ? " for {$filterYear}" : '' ?>.
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">OR #</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Period</th>
                                <th>Added / Edited By</th>
                                <th>Status</th>
                                <th class="no-print"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($paymentHistory as $pay): ?>
                            <tr>
                                <td class="ps-3 font-monospace small"><?= e($pay['or_number'] ?? str_pad($pay['payment_id'], 6, '0', STR_PAD_LEFT)) ?></td>
                                <td class="small"><?= formatDate($pay['payment_date'], 'M d, Y h:i A') ?></td>
                                <td class="fw-semibold text-success small"><?= formatMoney($pay['amount']) ?></td>
                                <td class="small"><?= ucfirst(e($pay['method'])) ?></td>
                                <td class="small text-muted">
                                    <?= formatDate($pay['period_start'], 'M d') ?> –
                                    <?= formatDate($pay['period_end'], 'M d, Y') ?>
                                </td>
                                <td class="small">
                                    <?php
                                    $addedBy  = trim(($pay['cashier_first'] ?? '') . ' ' . ($pay['cashier_last'] ?? ''));
                                    $editedBy = trim(($pay['editor_first']  ?? '') . ' ' . ($pay['editor_last']  ?? ''));
                                    ?>
                                    <?= $addedBy ? e($addedBy) : '—' ?>
                                    <?php if ($editedBy && $editedBy !== $addedBy): ?>
                                    <div class="text-muted" style="font-size:.72rem;">
                                        <i class="bi bi-pencil me-1"></i><?= e($editedBy) ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= payStatusBadge($pay['status']) ?></td>
                                <td class="no-print">
                                    <?php if ($canPrintSensitive): ?>
                                    <a href="<?= BASE_URL ?>/modules/payments/receipt/<?= $pay['payment_id'] ?>"
                                       class="btn btn-sm btn-outline-secondary" target="_blank" title="Receipt">
                                        <i class="bi bi-printer"></i>
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── Record Payment Modal ────────────────────────────────── -->
<?php if (canModifyRecords()): ?>
<div class="modal fade" id="recordPaymentModal" tabindex="-1" aria-labelledby="recordPaymentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle d-flex align-items-center justify-content-center bg-success-subtle" style="width:36px;height:36px;flex-shrink:0;">
                        <i class="bi bi-cash-coin text-success"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-semibold mb-0" id="recordPaymentModalLabel">Record Payment</h5>
                        <div class="small text-muted text-truncate"><?= e($subscriber['firstname'] . ' ' . $subscriber['lastname']) ?></div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="quickPayForm">
                <?= csrfField() ?>
                <input type="hidden" name="_pay" value="1">
                <div class="modal-body">

                    <?php if ($mrc > 0): ?>
                    <?php
                        $isNegModal  = $balance < -50;
                        $isAdvModal  = $isNegModal && !$isSubExpired;
                        $isOvdModal  = $isNegModal && $isSubExpired;
                        $balBg  = $isOvdModal ? 'bg-danger-subtle border-danger'  : ($isAdvModal ? 'bg-primary-subtle border-primary' : ($balance > 50 ? 'bg-success-subtle border-success' : 'bg-light border-secondary'));
                        $balBdr = $isOvdModal ? '#dc3545' : ($isAdvModal ? '#0d6efd' : ($balance > 50 ? '#198754' : '#6c757d'));
                        $balLbl = $isOvdModal ? 'Overdue' : ($isAdvModal ? 'Advance Payment' : ($balance > 50 ? 'Credit' : 'Current'));
                        $balClr = $isOvdModal ? 'text-danger' : ($isAdvModal ? 'text-primary' : ($balance > 50 ? 'text-success' : 'text-secondary'));
                    ?>
                    <div class="rounded-3 border <?= $balBg ?> mb-3 p-3" style="border-left:4px solid <?= $balBdr ?> !important;">
                        <div class="d-flex flex-wrap gap-4">
                            <div>
                                <div class="text-muted fw-medium" style="font-size:.68rem;letter-spacing:.04em;">MRC</div>
                                <div class="fw-bold fs-6"><?= formatMoney($mrc) ?><span class="text-muted fw-normal small"> / <?= e($billingCycle) ?></span></div>
                            </div>
                            <div>
                                <div class="text-muted fw-medium" style="font-size:.68rem;letter-spacing:.04em;">BALANCE</div>
                                <div class="fw-bold fs-6 <?= $balClr ?>">
                                    <?= ($balance < 0 ? '−' : '+') . formatMoney(abs($balance)) ?>
                                    <span class="badge <?= $isOvdModal ? 'bg-danger' : ($isAdvModal ? 'bg-primary' : ($balance > 50 ? 'bg-success' : 'bg-secondary')) ?> ms-1" style="font-size:.65rem;"><?= $balLbl ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="row g-3">
                        <?php if (count($modalPayTypes) > 1): ?>
                        <div class="col-12">
                            <label class="form-label fw-medium" for="qpPaymentType">Payment Type</label>
                            <select name="payment_type_id" id="qpPaymentType" class="form-select">
                                <?php foreach ($modalPayTypes as $pt): ?>
                                <?php if (!$pt['is_active'] || !$pt['is_default']) continue; ?>
                                <option value="<?= $pt['type_id'] ?>" <?= (int)$pt['type_id'] === $modalDefTypeId ? 'selected' : '' ?>>
                                    <?= e($pt['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php else: ?>
                        <input type="hidden" name="payment_type_id" value="<?= $modalDefTypeId ?>">
                        <?php endif; ?>

                        <div class="col-md-6">
                            <label class="form-label fw-medium d-flex align-items-center gap-2" for="qpAmount">
                                Amount (<?= e($currency) ?>) <span class="text-danger">*</span>
                                <?php if ($mrc > 0): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle fw-normal" style="font-size:.72rem;">MRC <?= formatMoney($mrc) ?></span>
                                <?php endif; ?>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text fw-bold text-success"><?= e($currency) ?></span>
                                <input type="number" name="amount" id="qpAmount" class="form-control form-control-lg fw-bold"
                                       step="0.01" min="0.01"
                                       value="<?= number_format($mrc ?: 0, 2, '.', '') ?>" required>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="qpPaymentDate">Payment Date <span class="text-danger">*</span></label>
                            <input type="datetime-local" name="payment_date" id="qpPaymentDate" class="form-control"
                                   value="<?= date('Y-m-d\TH:i') ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="qpMethod">Payment Method <span class="text-danger">*</span></label>
                            <select name="method" class="form-select" id="qpMethod">
                                <?php foreach (getPaymentMethods() as $val => $label): ?>
                                <option value="<?= e($val) ?>" <?= $val === 'cash' ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6" id="qpRefGroup" style="opacity:.5;">
                            <label class="form-label fw-medium" for="qpRefNumber">Reference Number</label>
                            <input type="text" name="reference_number" id="qpRefNumber" class="form-control font-monospace"
                                   placeholder="GCash ref, bank ref…">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="qpPeriodStart">Period Start</label>
                            <input type="date" name="period_start" id="qpPeriodStart" class="form-control"
                                   value="<?= e($modalPeriodStart) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="qpPeriodEnd">Period End</label>
                            <input type="date" name="period_end" id="qpPeriodEnd" class="form-control"
                                   value="<?= e($modalPeriodEnd) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="qpStatus">Status</label>
                            <select name="status" id="qpStatus" class="form-select">
                                <option value="paid" selected>Paid</option>
                                <option value="pending">Pending</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-medium" for="qpNotes">Notes</label>
                            <textarea name="notes" id="qpNotes" class="form-control" rows="2" placeholder="Optional…"></textarea>
                        </div>
                    </div>

                    <hr class="my-3">
                    <?php if (!empty($subscriber['contact_number'])): ?>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="notify_sms" id="qpNotifySms" <?= !SMS_ENABLED ? 'disabled' : '' ?>>
                        <label class="form-check-label" for="qpNotifySms">Send SMS confirmation</label>
                        <?php if (!SMS_ENABLED): ?><div class="form-text">SMS is disabled in configuration.</div><?php endif; ?>
                    </div>
                    <?php endif; ?>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success fw-semibold px-4">
                        <i class="bi bi-cash-coin me-1"></i>Record Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Payment Portal Modal (Superadmin) ───────────────────── -->
<?php if ($canViewPaymentPortal): ?>
<div class="modal fade" id="paymentPortalModal" tabindex="-1" aria-labelledby="paymentPortalModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle d-flex align-items-center justify-content-center bg-primary-subtle" style="width:36px;height:36px;flex-shrink:0;">
                        <i class="bi bi-window-sidebar text-primary"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-semibold mb-0" id="paymentPortalModalLabel">Payment Portal Activity</h5>
                        <div class="small text-muted"><?= e($subscriber['account_number']) ?> · <?= e($subscriber['firstname'] . ' ' . $subscriber['lastname']) ?></div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <?php if (empty($paymentPortalHistory)): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-window-sidebar fs-2 d-block mb-2"></i>
                    No payment portal records found for this subscriber.
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">ID</th>
                                <th>Router</th>
                                <th>Plan</th>
                                <th>Mode</th>
                                <th>Amount</th>
                                <th>Channel</th>
                                <th>Status</th>
                                <th>OR #</th>
                                <th>Initiated</th>
                                <th class="text-end pe-3">Invoice</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($paymentPortalHistory as $pp): ?>
                            <?php
                                [$ppBadge, $ppLabel] = match($pp['status'] ?? '') {
                                    'completed' => ['bg-success', 'Completed'],
                                    'pending'   => ['bg-warning text-dark', 'Pending'],
                                    'failed'    => ['bg-danger', 'Failed'],
                                    'expired'   => ['bg-secondary', 'Expired'],
                                    default     => ['bg-info text-dark', ucfirst($pp['status'] ?? 'Initiated')],
                                };
                                $ppAmount = (float)($pp['amount'] ?? 0);
                            ?>
                            <tr>
                                <td class="ps-3 font-monospace small">#<?= (int)$pp['payment_id'] ?></td>
                                <td class="small"><?= e($pp['router_name'] ?? '—') ?></td>
                                <td class="small"><?= e($pp['plan_name'] ?? '—') ?></td>
                                <td><span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?= e(ucfirst($pp['payment_mode'] ?? '—')) ?></span></td>
                                <td class="fw-semibold small"><?= e($pp['currency'] ?? 'PHP') ?> <?= number_format($ppAmount, 2) ?></td>
                                <td class="small"><?= e($pp['payment_channel'] ?? $pp['payment_gateway'] ?? '—') ?></td>
                                <td><span class="badge <?= $ppBadge ?>"><?= e($ppLabel) ?></span></td>
                                <td class="font-monospace small"><?= e($pp['or_number'] ?? '—') ?></td>
                                <td class="small"><?= formatDate($pp['initiated_at'] ?? null, 'M d, Y h:i A') ?></td>
                                <td class="text-end pe-3">
                                    <?php if (!empty($pp['xendit_invoice_url'])): ?>
                                    <a href="<?= e($pp['xendit_invoice_url']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Open invoice">
                                        <i class="bi bi-box-arrow-up-right"></i>
                                    </a>
                                    <?php else: ?>
                                    <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if (!empty($pp['error_message']) || !empty($pp['notes'])): ?>
                            <tr>
                                <td></td>
                                <td colspan="9" class="small text-muted pb-3">
                                    <?= !empty($pp['error_message']) ? '<span class="text-danger fw-semibold">Error:</span> ' . e($pp['error_message']) : '' ?>
                                    <?= !empty($pp['notes']) ? '<span class="ms-2">' . e($pp['notes']) . '</span>' : '' ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Send SMS Modal ──────────────────────────────────────── -->
<div class="modal fade" id="sendSmsModal" tabindex="-1" aria-labelledby="sendSmsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle d-flex align-items-center justify-content-center bg-primary-subtle" style="width:36px;height:36px;flex-shrink:0;">
                        <i class="bi bi-chat-text text-primary"></i>
                    </div>
                    <h5 class="modal-title fw-semibold mb-0" id="sendSmsModalLabel">Send SMS Message</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border py-2 mb-3 small">
                    Sending to: <strong><?= e($subscriber['firstname'] . ' ' . $subscriber['lastname']) ?></strong><br>
                    <span class="font-monospace text-muted"><?= e($subscriber['contact_number'] ?? 'No phone on record') ?></span>
                </div>
                <?php if (empty($subscriber['contact_number'])): ?>
                <div class="alert alert-warning py-2 small">
                    No contact number on record. Please edit the subscriber first.
                </div>
                <?php endif; ?>
                <div class="mb-1">
                    <label class="form-label fw-semibold" for="smsMessage">Message <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="smsMessage" rows="4" maxlength="160"
                              placeholder="Type your message here..."></textarea>
                    <div class="d-flex justify-content-between mt-1">
                        <small class="text-muted">Max 160 characters (1 SMS)</small>
                        <small class="text-muted"><span id="smsCharCount">0</span>/160</small>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary fw-semibold" id="sendSmsBtn"
                        <?= empty($subscriber['contact_number']) ? 'disabled' : '' ?>>
                    <i class="bi bi-send me-1"></i>Send SMS
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── View in Map Modal ──────────────────────────────────── -->
<?php
$hasCoords  = !empty($subscriber['latitude']) && !empty($subscriber['longitude']);
$subLat     = $hasCoords ? (float)$subscriber['latitude']  : 0;
$subLng     = $hasCoords ? (float)$subscriber['longitude'] : 0;
$subName    = e($subscriber['firstname'] . ' ' . $subscriber['lastname']);
$subAcct    = e($subscriber['account_number']);
$subAddr    = e($subscriber['address'] ?? '');
?>
<div class="modal fade" id="viewMapModal" tabindex="-1" aria-labelledby="viewMapModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle d-flex align-items-center justify-content-center bg-primary-subtle" style="width:36px;height:36px;flex-shrink:0;">
                        <i class="bi bi-geo-alt text-primary"></i>
                    </div>
                    <h5 class="modal-title fw-semibold mb-0" id="viewMapModalLabel"><?= $subName ?> — Location</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <?php if ($hasCoords): ?>
                <div class="px-3 pt-3 pb-2 d-flex align-items-center gap-3 border-bottom">
                    <div>
                        <div class="fw-semibold"><?= $subName ?></div>
                        <div class="text-muted small font-monospace"><?= $subAcct ?></div>
                        <?php if ($subAddr): ?>
                        <div class="text-muted small"><?= $subAddr ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="ms-auto text-end small text-muted font-monospace">
                        <div><?= $subLat ?></div>
                        <div><?= $subLng ?></div>
                    </div>
                </div>
                <div id="subscriberMap" style="height:420px;width:100%;"></div>
                <?php else: ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-geo-alt fs-1 d-block mb-3 text-secondary"></i>
                    <p class="mb-0">No coordinates on record for this subscriber.</p>
                    <p class="small">Edit the subscriber to add their location.</p>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <?php if ($hasCoords): ?>
                <a href="https://www.google.com/maps?q=<?= $subLat ?>,<?= $subLng ?>"
                   target="_blank" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-box-arrow-up-right me-1"></i>Open in Google Maps
                </a>
                <?php endif; ?>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ── Override Date Modal ─────────────────────────────────── -->
<div class="modal fade" id="overrideDateModal" tabindex="-1" aria-labelledby="overrideDateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle d-flex align-items-center justify-content-center bg-info-subtle" style="width:36px;height:36px;flex-shrink:0;">
                        <i class="bi bi-calendar-range text-info"></i>
                    </div>
                    <h5 class="modal-title fw-semibold mb-0" id="overrideDateModalLabel">Override Subscription Date</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning py-2 small mb-3">
                    This overrides the subscription period directly in the database. Use with care.
                </div>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold" for="overrideStart">Start Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="overrideStart"
                               value="<?= $subscriber['subscription_start'] ? date('Y-m-d', strtotime($subscriber['subscription_start'])) : '' ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold" for="overrideEnd">End Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="overrideEnd"
                               value="<?= $subscriber['subscription_end'] ? date('Y-m-d', strtotime($subscriber['subscription_end'])) : '' ?>">
                    </div>
                </div>
                <div class="mt-3 p-2 bg-light rounded small text-muted" id="datePreview">
                    Select both dates to preview the duration.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-info text-white fw-semibold" id="saveDateBtn">
                    <i class="bi bi-check-circle me-1"></i>Save Dates
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── Reset Account Modal ─────────────────────────────────── -->
<div class="modal fade" id="resetAccountModal" tabindex="-1" aria-labelledby="resetAccountModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle d-flex align-items-center justify-content-center bg-warning-subtle" style="width:36px;height:36px;flex-shrink:0;">
                        <i class="bi bi-key text-warning"></i>
                    </div>
                    <h5 class="modal-title fw-semibold mb-0" id="resetAccountModalLabel">Reset Account Password</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">

                <!-- New password -->
                <div class="mb-2">
                    <label class="form-label fw-semibold small text-muted text-uppercase" for="raNewPasswordInput" style="letter-spacing:.04em;">New Password</label>
                    <div class="input-group">
                        <input type="text" id="raNewPasswordInput" class="form-control font-monospace fw-medium"
                               placeholder="Type or generate a password…" autocomplete="new-password">
                        <button type="button" class="btn btn-outline-secondary" id="raToggleNewBtn" title="Show / hide">
                            <i class="bi bi-eye" id="raToggleNewIcon"></i>
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="raCopyNewBtn" title="Copy">
                            <i class="bi bi-clipboard" id="raCopyNewIcon"></i>
                        </button>
                    </div>
                </div>

                <!-- Strength bar -->
                <div class="mb-3">
                    <div class="progress mb-1" style="height:5px;">
                        <div class="progress-bar" id="raStrengthBar" style="width:0%;transition:width .25s,background .25s;"></div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted" id="raStrengthLabel">Enter a password to check strength</small>
                        <small class="text-muted" id="raCharCount">0 chars</small>
                    </div>
                </div>

                <!-- Generate button -->
                <button type="button" class="btn btn-outline-primary btn-sm w-100" id="raGenerateBtn">
                    <i class="bi bi-shuffle me-1"></i>Generate Strong Password
                </button>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning fw-semibold px-4" id="raSaveBtn">
                    <i class="bi bi-floppy me-1"></i>Save Password
                </button>
            </div>
        </div>
    </div>
</div>

<?php
$subscriberId  = $id;
$routerId      = (int)$subscriber['router_id'];
$connType      = $subscriber['connection_type'];
$pppUser       = $subscriber['ppp_username'] ?? '';
$baseUrl       = BASE_URL;

// JSON-encode all user-supplied strings before heredoc interpolation
$jsConnType = json_encode($connType);
$jsPppUser  = json_encode($pppUser);
$jsSubName  = json_encode(($subscriber['firstname'] ?? '') . ' ' . ($subscriber['lastname'] ?? ''));
$jsSubAcct  = json_encode($subscriber['account_number'] ?? '');
$jsSubAddr  = json_encode($subscriber['address'] ?? '');
$jsDisconnectPolicy = json_encode(
    $disconnectPolicy,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);

$extraScripts  = <<<JS
<script>
const subscriberId = {$subscriberId};
const routerId     = {$routerId};
const connType     = {$jsConnType};
const pppUser      = {$jsPppUser};
const disconnectPolicy = {$jsDisconnectPolicy};

function copyAddress(btn, text) {
    navigator.clipboard.writeText(text).then(function () {
        const icon = btn.querySelector('i');
        icon.className = 'bi bi-check-lg text-success';
        btn.title = 'Copied!';
        setTimeout(function () {
            icon.className = 'bi bi-copy';
            btn.title = 'Copy Street, Barangay, Municipality';
        }, 2000);
    }).catch(function () {
        showToast('Could not copy address.', 'danger');
    });
}

if (typeof window.escHtml !== 'function') {
    window.escHtml = function (str) {
        return String(str ?? '').replace(/[&<>"']/g, ch => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;',
        }[ch]));
    };
}

function fmtBytes(bytes) {
    const b = parseInt(bytes) || 0;
    if (b >= 1073741824) return (b / 1073741824).toFixed(2) + ' GB';
    if (b >= 1048576)    return (b / 1048576).toFixed(2) + ' MB';
    if (b >= 1024)       return (b / 1024).toFixed(2) + ' KB';
    return b + ' B';
}

function setSessionOffline() {
    const dot  = '<span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#dc3545;margin-right:4px;"></span>';
    const html = dot + 'Offline';
    const ih = document.getElementById('clientOnlineIndicator');
    const ic = document.getElementById('connStatusBadge');
    const sb = document.getElementById('sessionStatusBadge');
    if (ih) { ih.className = 'badge bg-danger';   ih.innerHTML = html; }
    if (ic) { ic.className = 'badge bg-danger';   ic.innerHTML = html; }
    if (sb) { sb.classList.add('d-none'); sb.classList.remove('d-flex'); }
}

function setSessionOnline() {
    const dot  = '<span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#198754;margin-right:4px;"></span>';
    const html = dot + 'Online';
    const ih = document.getElementById('clientOnlineIndicator');
    const ic = document.getElementById('connStatusBadge');
    const sb = document.getElementById('sessionStatusBadge');
    if (ih) { ih.className = 'badge bg-success'; ih.innerHTML = html; }
    if (ic) { ic.className = 'badge bg-success'; ic.innerHTML = html; }
    if (sb) { sb.classList.remove('d-none'); sb.classList.add('d-flex'); }
}

function loadLiveSession() {
    if (!routerId) {
        setSessionOffline();
        return;
    }
    fetch(BASE_URL + '/api/subscriber_status.php?subscriber_id=' + subscriberId)
        .then(function(r) { return r.json(); })
        .then(function(d) {
            const el = document.getElementById('liveSession');
            if (!d.success) {
                setSessionOffline();
                if (el) el.innerHTML = '<div class="text-center text-muted py-4"><i class="bi bi-router fs-4 d-block mb-2"></i>' + (d.message || 'Router unavailable') + '</div>';
                return;
            }
            if (!d.online || !d.session) {
                setSessionOffline();
                if (el) el.innerHTML = '<div class="text-center text-muted py-4"><i class="bi bi-wifi-off fs-4 d-block mb-2"></i>No active session</div>';
                return;
            }
            setSessionOnline();

            const s      = d.session;
            const ip     = s.address  || '—';
            const mac    = s['mac-address'] || s['caller-id'] || '—';
            const uptime = s.uptime   || '—';
            const ipHtml = ip !== '—'
                ? `<a href="http://\${encodeURIComponent(ip)}" target="_blank" rel="noopener noreferrer" class="text-success text-decoration-none">\${escHtml(ip)}</a>`
                : escHtml(ip);
            // PHP normalises all session types to bytes-in (upload) / bytes-out (download)
            const dlB    = parseInt(s['bytes-out'] || 0) || 0;
            const ulB    = parseInt(s['bytes-in']  || 0) || 0;
            const dl     = fmtBytes(dlB);
            const ul     = fmtBytes(ulB);
            const total  = fmtBytes(dlB + ulB);

            el.innerHTML = `
                <div class="row g-3">
                    <!-- IP & MAC -->
                    <div class="col-sm-6">
                        <div class="p-3 bg-light rounded h-100">
                            <div class="text-muted small mb-1">IP Address</div>
                            <div class="fw-bold font-monospace fs-6">\${ipHtml}</div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-3 bg-light rounded h-100">
                            <div class="text-muted small mb-1">MAC Address</div>
                            <div class="fw-bold font-monospace">\${escHtml(mac)}</div>
                        </div>
                    </div>

                    <!-- Uptime -->
                    <div class="col-12">
                        <div class="p-3 bg-light rounded">
                            <div class="text-muted small">Uptime</div>
                            <div class="fw-bold fs-6">\${escHtml(uptime)}</div>
                        </div>
                    </div>

                    <!-- Traffic -->
                    <div class="col-12">
                        <div class="p-3 bg-light rounded">
                            <div class="text-muted small mb-3">Traffic Statistics</div>
                            <div class="row g-3 text-center">
                                <div class="col-12 col-sm-4">
                                    <div class="fw-bold text-primary fs-6">\${dl}</div>
                                    <div class="text-muted small mt-1">Download</div>
                                </div>
                                <div class="col-12 col-sm-4">
                                    <div class="fw-bold text-warning fs-6">\${ul}</div>
                                    <div class="text-muted small mt-1">Upload</div>
                                </div>
                                <div class="col-12 col-sm-4">
                                    <div class="fw-bold text-success fs-6">\${total}</div>
                                    <div class="text-muted small mt-1">Total</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>`;
        })
        .catch(function() {
            setSessionOffline();
            const el = document.getElementById('liveSession');
            if (el) el.innerHTML = '<div class="text-center text-muted py-4"><i class="bi bi-exclamation-circle me-2"></i>Could not load session data</div>';
        });
}

document.getElementById('refreshSession')?.addEventListener('click', loadLiveSession);
loadLiveSession();
setInterval(loadLiveSession, 5000);

// ── Reveal PPP/Hotspot password ───────────────────────────────
(function () {
    const btn     = document.getElementById('revealPasswordBtn');
    const display = document.getElementById('pppPasswordDisplay');
    const icon    = document.getElementById('revealPasswordIcon');
    if (!btn) return;

    let revealed = false;
    let cached   = null;

    btn.addEventListener('click', function () {
        if (!revealed) {
            btn.disabled = true;
            const fd = new FormData();
            fd.append('action', 'view_password');
            fd.append('subscriber_id', subscriberId);
            fd.append(CSRF_TOKEN_NAME, CSRF_TOKEN);

            fetch(BASE_URL + '/api/subscriber_action.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    btn.disabled = false;
                    if (d.success) {
                        cached   = d.password;
                        revealed = true;
                        display.textContent = cached;
                        display.classList.remove('text-muted');
                        icon.className = 'bi bi-eye-slash';
                    } else {
                        showToast(d.message || 'Could not retrieve password.', 'danger');
                    }
                })
                .catch(function() {
                    btn.disabled = false;
                    showToast('Network error.', 'danger');
                });
        } else {
            // Toggle show/hide after first reveal
            if (display.textContent === cached) {
                display.textContent = '••••••••';
                display.classList.add('text-muted');
                icon.className = 'bi bi-eye';
            } else {
                display.textContent = cached;
                display.classList.remove('text-muted');
                icon.className = 'bi bi-eye-slash';
            }
        }
    });
})();

// ── Copy Username ─────────────────────────────────────────────
(function () {
    const btn  = document.getElementById('copyUsernameBtn');
    const icon = document.getElementById('copyUsernameIcon');
    if (!btn) return;

    btn.addEventListener('click', function () {
        const text = document.getElementById('pppUsernameDisplay').textContent.trim();
        navigator.clipboard.writeText(text).then(function () {
            icon.className = 'bi bi-check2 text-success';
            setTimeout(function () { icon.className = 'bi bi-copy'; }, 2000);

            const fd = new FormData();
            fd.append('action', 'copy_username');
            fd.append('subscriber_id', subscriberId);
            fd.append(CSRF_TOKEN_NAME, CSRF_TOKEN);
            fetch(BASE_URL + '/api/subscriber_action.php', { method: 'POST', body: fd });
        }).catch(function () {
            showToast('Could not copy username.', 'danger');
        });
    });
})();

// ── Copy Password ─────────────────────────────────────────────
(function () {
    const btn     = document.getElementById('copyPasswordBtn');
    const icon    = document.getElementById('copyPasswordIcon');
    const reveal  = document.getElementById('revealPasswordBtn');
    if (!btn) return;

    function logCopyPassword() {
        const fd = new FormData();
        fd.append('action', 'copy_password');
        fd.append('subscriber_id', subscriberId);
        fd.append(CSRF_TOKEN_NAME, CSRF_TOKEN);
        fetch(BASE_URL + '/api/subscriber_action.php', { method: 'POST', body: fd });
    }

    function doCopy(text) {
        navigator.clipboard.writeText(text).then(function () {
            icon.className = 'bi bi-check2 text-success';
            setTimeout(function () { icon.className = 'bi bi-copy'; }, 2000);
            logCopyPassword();
        }).catch(function () {
            showToast('Could not copy password.', 'danger');
        });
    }

    btn.addEventListener('click', function () {
        // If the reveal block has already cached the password, reuse it
        const display = document.getElementById('pppPasswordDisplay');
        if (display && display.textContent !== '••••••••') {
            doCopy(display.textContent.trim());
            return;
        }

        // Otherwise fetch the password (also reveals it)
        btn.disabled = true;
        const fd = new FormData();
        fd.append('action', 'copy_password');
        fd.append('subscriber_id', subscriberId);
        fd.append(CSRF_TOKEN_NAME, CSRF_TOKEN);

        fetch(BASE_URL + '/api/subscriber_action.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                btn.disabled = false;
                if (d.success) {
                    navigator.clipboard.writeText(d.password).then(function () {
                        icon.className = 'bi bi-check2 text-success';
                        setTimeout(function () { icon.className = 'bi bi-copy'; }, 2000);
                    }).catch(function () {
                        showToast('Could not copy password.', 'danger');
                    });
                } else {
                    showToast(d.message || 'Could not retrieve password.', 'danger');
                }
            })
            .catch(function () {
                btn.disabled = false;
                showToast('Network error.', 'danger');
            });
    });
})();

// Router actions
document.querySelectorAll('.router-action').forEach(el => {
    el.addEventListener('click', function(e) {
        e.preventDefault();
        const action = this.dataset.action;
        const labels = {
            suspend:    'Suspend this account on the router?',
            activate:   'Activate this account on the router?',
            disconnect: 'Disconnect active session?',
        };

        let confirmHtml;
        if (action === 'disconnect') {
            const policyTargetHtml = disconnectPolicy.target
                ? '<div class="small mt-2"><span class="text-muted">' + escHtml(disconnectPolicy.target_kind || 'Target') + ':</span> <span class="font-monospace fw-semibold">' + escHtml(disconnectPolicy.target) + '</span></div>'
                : '';
            const policyRouterHtml = disconnectPolicy.router
                ? '<div class="small mt-1"><span class="text-muted">Router:</span> <span class="fw-semibold">' + escHtml(disconnectPolicy.router) + '</span></div>'
                : '';
            confirmHtml = `<div class="text-start">
                <p class="mb-3">This will disconnect/close the active PPP, Hotspot, and RADIUS/User Manager session first. Router policy is applied after that only when the subscription is expired.</p>
                <div class="border rounded bg-light p-3">
                    <div class="fw-semibold mb-2"><i class="bi bi-shield-check me-1 text-primary"></i>Router Policy</div>
                    <div class="d-flex flex-wrap gap-2 mb-2">
                        <span class="badge bg-secondary-subtle text-secondary border">\${escHtml(disconnectPolicy.connection || 'Account')}</span>
                        <span class="badge bg-info-subtle text-info border">\${escHtml(disconnectPolicy.auth || 'Router')}</span>
                        <span class="badge bg-\${disconnectPolicy.badge || 'secondary'}">\${escHtml(disconnectPolicy.title || 'Disable account')}</span>
                    </div>
                    <div class="small text-muted">\${escHtml(disconnectPolicy.detail || '')}</div>
                    \${policyTargetHtml}
                    \${policyRouterHtml}
                </div>
            </div>`;
        }

        Swal.fire({
            title: labels[action] || 'Confirm?',
            icon: 'question',
            html: confirmHtml,
            showCancelButton: true,
            confirmButtonText: 'Yes, proceed',
        }).then(r => {
            if (!r.isConfirmed) return;
            const fd = new FormData();
            fd.append('action', action);
            fd.append('subscriber_id', subscriberId);
            fd.append('router_id', routerId);
            fd.append(CSRF_TOKEN_NAME || '_csrf_token', CSRF_TOKEN || '');

            Swal.fire({
                title: 'Processing...',
                text: 'Please wait while the router action is running.',
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: () => Swal.showLoading(),
            });

            fetch(BASE_URL + '/api/mikrotik_action.php', { method: 'POST', body: fd })
                .then(async response => {
                    const text = await response.text();
                    let data = {};
                    try {
                        data = text ? JSON.parse(text) : {};
                    } catch (err) {
                        throw new Error(text ? text.slice(0, 500) : 'Invalid server response.');
                    }
                    if (!response.ok || data.success === false) {
                        const detail = data.message || data.error || data.detail || data.reason
                            || (text ? text.slice(0, 500) : '');
                        throw new Error(detail || 'Router action failed. No details were returned by the server.');
                    }
                    return data;
                })
                .then(d => {
                    Swal.fire('Done!', d.message || 'Router action completed.', 'success')
                        .then(() => location.reload());
                })
                .catch(err => {
                    Swal.fire('Error', err.message || 'Could not reach the router action endpoint.', 'error');
                });
        });
    });
});

// Permanently delete pending subscriber
document.getElementById('deletePendingSubscriberBtn')?.addEventListener('click', function () {
    const id = this.dataset.id;
    const name = this.dataset.name || 'this pending subscriber';

    Swal.fire({
        title: 'Delete Pending Subscriber?',
        html: 'Are you sure you want to delete <strong>' + name.replace(/</g, '&lt;') + '</strong>?<br>'
            + '<small class="text-danger">This permanently removes the pending subscriber from the database and cannot be undone.</small>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, Delete Permanently',
    }).then(result => {
        if (!result.isConfirmed) return;

        const fd = new URLSearchParams();
        fd.append('action', 'delete');
        fd.append('subscriber_id', id);
        fd.append(CSRF_TOKEN_NAME, CSRF_TOKEN);

        fetch(BASE_URL + '/modules/subscribers/ajax_actions', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: fd.toString(),
        })
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    Swal.fire('Deleted!', d.message, 'success')
                        .then(() => location.href = BASE_URL + '/modules/subscribers/');
                } else {
                    Swal.fire('Error', d.message, 'error');
                }
            })
            .catch(() => Swal.fire('Error', 'Could not reach the server.', 'error'));
    });
});

// ── Archive subscriber ────────────────────────────────────────
document.getElementById('archiveSubscriberBtn')?.addEventListener('click', function (e) {
    e.preventDefault();
    const id   = this.dataset.id;
    const name = this.dataset.name || 'this subscriber';

    Swal.fire({
        title: 'Archive Subscriber?',
        html: '<div class="text-start">'
            + '<p>You are about to archive <strong>' + escHtml(name) + '</strong>.</p>'
            + '<ul class="small mb-0">'
            + '<li>Status will be set to <strong>Archived</strong>.</li>'
            + '</ul>'
            + '</div>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#343a40',
        confirmButtonText: 'Yes, Archive',
    }).then(result => {
        if (!result.isConfirmed) return;

        Swal.fire({
            title: 'Archiving...',
            text: 'Archiving subscriber...',
            allowOutsideClick: false,
            allowEscapeKey: false,
            didOpen: () => Swal.showLoading(),
        });

        const fd = new URLSearchParams();
        fd.append('action', 'archive');
        fd.append('subscriber_id', id);
        fd.append(CSRF_TOKEN_NAME, CSRF_TOKEN);

        fetch(BASE_URL + '/modules/subscribers/ajax_actions', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: fd.toString(),
        })
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    Swal.fire('Archived!', d.message, 'success')
                        .then(() => location.reload());
                } else {
                    Swal.fire('Error', d.message, 'error');
                }
            })
            .catch(() => Swal.fire('Error', 'Could not reach the server.', 'error'));
    });
});

// ── Send SMS ──────────────────────────────────────────────────
const smsMsg     = document.getElementById('smsMessage');
const smsCounter = document.getElementById('smsCharCount');
const sendBtn    = document.getElementById('sendSmsBtn');

smsMsg?.addEventListener('input', function () {
    const len = this.value.length;
    smsCounter.textContent = len;
    smsCounter.className   = len > 140 ? 'text-danger fw-semibold' : 'text-muted';
});

sendBtn?.addEventListener('click', function () {
    const msg = smsMsg.value.trim();
    if (!msg) { smsMsg.focus(); return; }

    sendBtn.disabled    = true;
    sendBtn.innerHTML   = '<span class="spinner-border spinner-border-sm me-1"></span>Sending…';

    const fd = new FormData();
    fd.append('action', 'send_sms');
    fd.append('subscriber_id', subscriberId);
    fd.append('message', msg);
    fd.append('_csrf_token', CSRF_TOKEN);

    fetch(BASE_URL + '/api/subscriber_action.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            bootstrap.Modal.getInstance(document.getElementById('sendSmsModal')).hide();
            smsMsg.value = '';
            smsCounter.textContent = '0';
            if (d.success) {
                Swal.fire({ title: 'Sent!', text: d.message, icon: 'success', timer: 2500, showConfirmButton: false });
            } else {
                Swal.fire('Failed', d.message, 'error');
            }
        })
        .catch(() => Swal.fire('Error', 'Network error. Please try again.', 'error'))
        .finally(() => {
            sendBtn.disabled  = false;
            sendBtn.innerHTML = '<i class="bi bi-send me-1"></i>Send SMS';
        });
});

// ── Override Date ─────────────────────────────────────────────
const startInput   = document.getElementById('overrideStart');
const endInput     = document.getElementById('overrideEnd');
const datePreview  = document.getElementById('datePreview');
const saveDateBtn  = document.getElementById('saveDateBtn');

function updateDatePreview() {
    const s = startInput?.value;
    const e = endInput?.value;
    if (!s || !e) { datePreview.innerHTML = 'Select both dates to preview the duration.'; return; }
    const diff  = Math.round((new Date(e) - new Date(s)) / 86400000);
    if (diff < 0) {
        datePreview.innerHTML = '<span class="text-danger">End date must be after start date.</span>';
    } else {
        datePreview.innerHTML = `Duration: <strong>\${diff} day(s)</strong> &mdash; \${new Date(s).toLocaleDateString('en-PH', {month:'short',day:'numeric',year:'numeric'})} to \${new Date(e).toLocaleDateString('en-PH', {month:'short',day:'numeric',year:'numeric'})}`;
    }
}

startInput?.addEventListener('change', updateDatePreview);
endInput?.addEventListener('change', updateDatePreview);

saveDateBtn?.addEventListener('click', function () {
    const s = startInput.value;
    const e = endInput.value;
    if (!s || !e) { Swal.fire('Required', 'Please select both start and end dates.', 'warning'); return; }
    if (new Date(e) < new Date(s)) { Swal.fire('Invalid', 'End date must be after start date.', 'warning'); return; }

    saveDateBtn.disabled  = true;
    saveDateBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';

    const fd = new FormData();
    fd.append('action', 'override_date');
    fd.append('subscriber_id', subscriberId);
    fd.append('subscription_start', s);
    fd.append('subscription_end', e);
    fd.append('_csrf_token', CSRF_TOKEN);

    fetch(BASE_URL + '/api/subscriber_action.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            bootstrap.Modal.getInstance(document.getElementById('overrideDateModal')).hide();
            if (d.success) {
                Swal.fire({ title: 'Saved!', text: d.message, icon: 'success', timer: 2000, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                Swal.fire('Error', d.message, 'error');
            }
        })
        .catch(() => Swal.fire('Error', 'Network error. Please try again.', 'error'))
        .finally(() => {
            saveDateBtn.disabled  = false;
            saveDateBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Save Dates';
        });
});

// Quick Pay — use fetch so mod_rewrite doesn't swallow the POST body on clean URLs
document.getElementById('quickPayForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const btn     = this.querySelector('button[type=submit]');
    const origHtml = btn.innerHTML;
    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Processing…';

    fetch(window.location.href, { method: 'POST', body: new FormData(this) })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                window.location.href = d.redirect;
            } else {
                btn.disabled  = false;
                btn.innerHTML = origHtml;
                Swal.fire({ icon: 'error', title: 'Payment Error', text: d.message || 'Something went wrong.' });
            }
        })
        .catch(() => {
            btn.disabled  = false;
            btn.innerHTML = origHtml;
            Swal.fire({ icon: 'error', title: 'Network Error', text: 'Could not reach the server. Please try again.' });
        });
});
</script>
<script src="{$baseUrl}/assets/vendor/leaflet/leaflet.js"></script>
<script>
(function () {
    const LAT = {$subLat};
    const LNG = {$subLng};
    if (!LAT || !LNG) return;

    let subMap = null;

    document.getElementById('viewMapModal')?.addEventListener('shown.bs.modal', function () {
        if (subMap) { subMap.invalidateSize(); return; }

        subMap = L.map('subscriberMap').setView([LAT, LNG], 16);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            maxZoom: 19,
        }).addTo(subMap);

        const icon = L.divIcon({
            className: '',
            html: '<div style="background:#198754;width:18px;height:18px;border-radius:50%;border:3px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.4);"></div>',
            iconSize:   [18, 18],
            iconAnchor: [9, 9],
        });

        const _popupName = {$jsSubName};
        const _popupAcct = {$jsSubAcct};
        const _popupAddr = {$jsSubAddr};
        function _esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

        L.marker([LAT, LNG], { icon })
            .addTo(subMap)
            .bindPopup('<strong>' + _esc(_popupName) + '</strong><br><span class="font-monospace small">' + _esc(_popupAcct) + '</span>' + (_popupAddr ? '<br><small>' + _esc(_popupAddr) + '</small>' : ''))
            .openPopup();
    });
})();

// ── Quick Pay modal ───────────────────────────────────────
document.getElementById('qpMethod')?.addEventListener('change', function () {
    const ref = document.getElementById('qpRefGroup');
    if (ref) ref.style.opacity = this.value === 'cash' ? '0.5' : '1';
});

// ── Reveal Account Password ───────────────────────────────
(function () {
    const btn     = document.getElementById('revealAcctPasswordBtn');
    const display = document.getElementById('acctPasswordDisplay');
    const icon    = document.getElementById('revealAcctPasswordIcon');
    if (!btn) return;

    let revealed = false;
    let cached   = null;

    btn.addEventListener('click', function () {
        if (!revealed) {
            btn.disabled = true;
            const fd = new FormData();
            fd.append('action', 'view_account_password');
            fd.append('subscriber_id', subscriberId);
            fd.append(CSRF_TOKEN_NAME, CSRF_TOKEN);

            fetch(BASE_URL + '/api/subscriber_action.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    btn.disabled = false;
                    if (d.success) {
                        cached   = d.password;
                        revealed = true;
                        display.textContent = cached;
                        display.classList.remove('text-muted');
                        icon.className = 'bi bi-eye-slash';
                    } else {
                        showToast(d.message || 'Could not retrieve password.', 'danger');
                    }
                })
                .catch(function() {
                    btn.disabled = false;
                    showToast('Network error.', 'danger');
                });
        } else {
            if (display.textContent === cached) {
                display.textContent = '••••••••';
                display.classList.add('text-muted');
                icon.className = 'bi bi-eye';
            } else {
                display.textContent = cached;
                display.classList.remove('text-muted');
                icon.className = 'bi bi-eye-slash';
            }
        }
    });
})();

// ── Reset Account ────────────────────────────────────────
(function () {
    const modal      = document.getElementById('resetAccountModal');
    const input      = document.getElementById('raNewPasswordInput');
    const strBar     = document.getElementById('raStrengthBar');
    const strLabel   = document.getElementById('raStrengthLabel');
    const charCount  = document.getElementById('raCharCount');
    const toggleBtn  = document.getElementById('raToggleNewBtn');
    const toggleIcon = document.getElementById('raToggleNewIcon');
    const copyNewBtn = document.getElementById('raCopyNewBtn');
    const copyNewIco = document.getElementById('raCopyNewIcon');
    const genBtn     = document.getElementById('raGenerateBtn');
    const saveBtn    = document.getElementById('raSaveBtn');

    // ── Strength checker ──────────────────────────────────
    const STRENGTH = [
        { min: 0,  label: 'Too short',  cls: 'bg-danger',  pct: 10 },
        { min: 6,  label: 'Weak',       cls: 'bg-danger',  pct: 25 },
        { min: 8,  label: 'Fair',       cls: 'bg-warning', pct: 50 },
        { min: 10, label: 'Good',       cls: 'bg-info',    pct: 75 },
        { min: 12, label: 'Strong',     cls: 'bg-success', pct: 90 },
        { min: 16, label: 'Very Strong',cls: 'bg-success', pct: 100 },
    ];

    function checkStrength(pw) {
        if (!pw) {
            strBar.style.width = '0%';
            strBar.className = 'progress-bar';
            strLabel.textContent = 'Enter a password to check strength';
            charCount.textContent = '0 chars';
            return;
        }
        let score = 0;
        if (pw.length >= 8)  score++;
        if (pw.length >= 12) score++;
        if (pw.length >= 16) score++;
        if (/[A-Z]/.test(pw)) score++;
        if (/[a-z]/.test(pw)) score++;
        if (/[0-9]/.test(pw)) score++;
        if (/[^A-Za-z0-9]/.test(pw)) score++;

        let tier;
        if (pw.length < 6)       tier = STRENGTH[0];
        else if (pw.length < 8)  tier = STRENGTH[1];
        else if (score <= 3)     tier = STRENGTH[2];
        else if (score <= 4)     tier = STRENGTH[3];
        else if (score <= 5)     tier = STRENGTH[4];
        else                     tier = STRENGTH[5];

        strBar.style.width   = tier.pct + '%';
        strBar.className     = 'progress-bar ' + tier.cls;
        strLabel.textContent = tier.label;
        charCount.textContent = pw.length + ' chars';
    }

    // ── Generator ────────────────────────────────────────
    function generateStrongPassword() {
        const upper   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        const lower   = 'abcdefghjkmnpqrstuvwxyz';
        const digits  = '23456789';
        const symbols = '!@#$%&*?';
        const all     = upper + lower + digits + symbols;
        const len     = 16;
        const arr     = new Uint32Array(len + 4);
        crypto.getRandomValues(arr);

        const pw = [
            upper[arr[0]   % upper.length],
            lower[arr[1]   % lower.length],
            digits[arr[2]  % digits.length],
            symbols[arr[3] % symbols.length],
        ];
        for (let i = 4; i < len; i++) pw.push(all[arr[i] % all.length]);

        // Fisher-Yates shuffle using fresh random values
        const shuf = new Uint32Array(pw.length);
        crypto.getRandomValues(shuf);
        for (let i = pw.length - 1; i > 0; i--) {
            const j = shuf[i] % (i + 1);
            [pw[i], pw[j]] = [pw[j], pw[i]];
        }
        return pw.join('');
    }

    // ── Clipboard helper ─────────────────────────────────
    function copyToClipboard(text, iconEl) {
        navigator.clipboard.writeText(text).then(() => {
            const orig = iconEl.className;
            iconEl.className = 'bi bi-check text-success';
            setTimeout(() => { iconEl.className = orig; }, 1500);
        });
    }

    modal?.addEventListener('show.bs.modal', function () {
        input.value = '';
        input.type  = 'text';
        toggleIcon.className = 'bi bi-eye';
        checkStrength('');
    });

    // New password field interactions
    input?.addEventListener('input', function () { checkStrength(this.value); });

    toggleBtn?.addEventListener('click', function () {
        const hide = input.type === 'text';
        input.type = hide ? 'password' : 'text';
        toggleIcon.className = hide ? 'bi bi-eye-slash' : 'bi bi-eye';
    });

    copyNewBtn?.addEventListener('click', function () {
        if (input.value) copyToClipboard(input.value, copyNewIco);
    });

    genBtn?.addEventListener('click', function () {
        const pw = generateStrongPassword();
        input.value = pw;
        input.type  = 'text';
        toggleIcon.className = 'bi bi-eye-slash';
        checkStrength(pw);
        input.focus();
    });

    // Save
    saveBtn?.addEventListener('click', function () {
        const pw = input.value.trim();
        if (!pw) {
            input.focus();
            showToast('Please enter or generate a password first.', 'warning');
            return;
        }
        if (pw.length < 8) {
            showToast('Password must be at least 8 characters.', 'warning');
            return;
        }

        saveBtn.disabled  = true;
        saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';

        const fd = new FormData();
        fd.append('action', 'reset_account');
        fd.append('subscriber_id', subscriberId);
        fd.append('new_password', pw);
        fd.append(CSRF_TOKEN_NAME, CSRF_TOKEN);

        fetch(BASE_URL + '/api/subscriber_action.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    const savedPassword = String(d.password || pw);
                    bootstrap.Modal.getInstance(modal).hide();
                    showToast(d.message || 'Account password reset successfully.', 'success');
                    Swal.fire({
                        title: 'Password Saved!',
                        html: 'New account password for <strong>' + {$jsSubName} + '</strong>:<br><br>'
                            + '<span class="font-monospace fw-bold fs-5">' + escHtml(savedPassword) + '</span>',
                        icon: 'success',
                        confirmButtonText: 'Done',
                    }).then(() => location.reload());
                } else {
                    saveBtn.disabled  = false;
                    saveBtn.innerHTML = '<i class="bi bi-floppy me-1"></i>Save Password';
                    Swal.fire('Error', d.message, 'error');
                }
            })
            .catch(() => {
                saveBtn.disabled  = false;
                saveBtn.innerHTML = '<i class="bi bi-floppy me-1"></i>Save Password';
                Swal.fire('Error', 'Network error. Please try again.', 'error');
            });
    });
})();
</script>
JS;

include BASE_PATH . '/includes/footer.php';
