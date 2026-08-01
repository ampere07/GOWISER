<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireRole(ROLE_CASHIER, ROLE_ADMIN, ROLE_SUPERADMIN);

require_once BASE_PATH . '/lib/Mailer.php';
require_once BASE_PATH . '/lib/SMS.php';
require_once BASE_PATH . '/lib/RouterosAPI.php';

$errors   = [];
$preSubId = (int)($_GET['subscriber_id'] ?? $_POST['subscriber_id'] ?? 0);

// Load payment types
$paymentTypes = getPaymentTypes();
$defaultTypeId = 0;
$paymentTypeDefaults = [];
$activePaymentTypeIds = [];
foreach ($paymentTypes as $pt) {
    $typeId = (int)$pt['type_id'];
    $paymentTypeDefaults[$typeId] = !empty($pt['is_default']);
    if (!empty($pt['is_active'])) {
        $activePaymentTypeIds[$typeId] = true;
    }
    if (!empty($pt['is_default'])) {
        $defaultTypeId = $typeId;
    }
}

// Load tax settings
$taxEnabled = getSetting('tax_enabled', '0') === '1';
$taxName    = getSetting('tax_name', 'VAT');
$taxRate    = (float)getSetting('tax_rate', '0');
$taxType    = getSetting('tax_type', 'included'); // included | excluded
$currency   = getSetting('currency_symbol', '₱');
$currentRouterId   = (int)(selectedRouterId() ?: 0);
$currentRouterName = selectedRouterName() ?: 'All routers';

// Pre-load a single subscriber for the select — AJAX handles search at runtime
$preSub = null;
if ($preSubId) {
    $stmt = db()->prepare("
        SELECT s.subscriber_id, s.account_number, s.firstname, s.lastname,
               s.plan_id, s.subscription_start, s.subscription_end,
               p.amount AS plan_amount, p.billing_cycle,
               r.name AS router_name,
               (SELECT COALESCE(SUM(py2.amount),0)
                FROM payments py2
                INNER JOIN payment_types pt2 ON pt2.type_id = py2.payment_type_id
                WHERE py2.subscriber_id = s.subscriber_id AND py2.status = 'paid' AND pt2.is_default = 1
                  AND (s.subscription_start IS NULL OR DATE(py2.payment_date) >= s.subscription_start)
               ) AS total_paid,
               (SELECT MIN(py3.payment_date)
                FROM payments py3
                INNER JOIN payment_types pt3 ON pt3.type_id = py3.payment_type_id
                WHERE py3.subscriber_id = s.subscriber_id AND py3.status = 'paid' AND pt3.is_default = 1
                  AND (s.subscription_start IS NULL OR DATE(py3.payment_date) >= s.subscription_start)
               ) AS first_pay_date
        FROM subscribers s
        LEFT JOIN plans p ON p.plan_id = s.plan_id
        LEFT JOIN routers r ON r.router_id = s.router_id
        WHERE s.subscriber_id = ? AND s.status IN ('active','suspended','expired')
          " . ($currentRouterId > 0 ? "AND s.router_id = ?" : "") . "
    ");
    $params = [$preSubId];
    if ($currentRouterId > 0) $params[] = $currentRouterId;
    $stmt->execute($params);
    $preSub = $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $postTaxType = in_array($_POST['tax_type'] ?? '', ['included','excluded'])
                    ? $_POST['tax_type'] : $taxType;
    // Clamp tax rate to 0–100; HTML max=100 is bypassable
    $postTaxRate = min(100.0, max(0.0, (float)($_POST['tax_rate'] ?? $taxRate)));
    $baseAmount  = (float)($_POST['amount'] ?? 0);

    // Calculate tax amount
    $calcTaxAmount = 0;
    if (!empty($_POST['tax_apply']) && $postTaxRate > 0) {
        if ($postTaxType === 'excluded') {
            $calcTaxAmount = $baseAmount * $postTaxRate / 100;
        } else { // included
            $calcTaxAmount = $baseAmount - ($baseAmount / (1 + $postTaxRate / 100));
        }
    }

    // Whitelist status — cashier may only record paid or pending
    $allowedStatuses = hasMinRole(ROLE_ADMIN) ? ['paid','pending','refunded','cancelled'] : ['paid','pending'];
    $rawStatus = $_POST['status'] ?? 'paid';

    // Validate and normalise payment_date
    $rawDate    = trim($_POST['payment_date'] ?? '');
    $parsedTs   = $rawDate ? strtotime($rawDate) : false;
    $parsedDate = $parsedTs ? date('Y-m-d H:i:s', $parsedTs) : appNow();

    // Validate period dates
    $rawPeriodStart = trim($_POST['period_start'] ?? '');
    $rawPeriodEnd   = trim($_POST['period_end']   ?? '');

    $postedPaymentTypeId = (int)($_POST['payment_type_id'] ?? $defaultTypeId);
    if (!$postedPaymentTypeId || !isset($activePaymentTypeIds[$postedPaymentTypeId])) {
        $postedPaymentTypeId = $defaultTypeId;
    }
    $selectedPaymentTypeIsDefault = !empty($paymentTypeDefaults[$postedPaymentTypeId]);
    if (!$selectedPaymentTypeIsDefault) {
        $rawPeriodStart = '';
        $rawPeriodEnd = '';
    }

    $data = [
        'subscriber_id'    => (int)($_POST['subscriber_id']    ?? 0),
        'payment_type_id'  => $postedPaymentTypeId,
        'amount'           => $baseAmount,
        'tax_rate'         => !empty($_POST['tax_apply']) ? $postTaxRate : 0,
        'tax_type'         => $postTaxType,
        'tax_amount'       => round($calcTaxAmount, 2),
        'method'           => $_POST['method']                 ?? 'CASH',
        'reference_number' => trim($_POST['reference_number']  ?? ''),
        'payment_date'     => $parsedDate,
        'period_start'     => $rawPeriodStart,
        'period_end'       => $rawPeriodEnd,
        'status'           => in_array($rawStatus, $allowedStatuses) ? $rawStatus : 'paid',
        'notes'            => trim($_POST['notes']             ?? ''),
        'notify_sms'       => !empty($_POST['notify_sms']),
        'notify_email'     => !empty($_POST['notify_email']),
    ];

    if (!SMS_ENABLED) {
        $data['notify_sms'] = false;
    }
    if (!MAIL_ENABLED) {
        $data['notify_email'] = false;
    }

    if (!$data['subscriber_id']) $errors[] = 'Please select a subscriber.';
    if ($data['amount'] <= 0)    $errors[] = 'Amount must be greater than 0.';
    if ($data['amount'] > 999999) $errors[] = 'Amount exceeds the maximum allowed (₱999,999).';

    // Payment date must not be more than 14 days in the past (prevents buried backdating)
    if (!hasMinRole(ROLE_ADMIN)) {
        $payTs = strtotime($data['payment_date']);
        if ($payTs < strtotime('-14 days')) {
            $errors[] = 'Payment date cannot be more than 14 days in the past. Contact an administrator to backdate further.';
        }
        if ($payTs > strtotime('+1 day')) {
            $errors[] = 'Payment date cannot be set more than 1 day in the future.';
        }
    }

    // Default/subscription payments may extend validity; other payment types are one-off only.
    if ($selectedPaymentTypeIsDefault && $data['period_start'] && $data['period_end']) {
        $pStart = strtotime($data['period_start']);
        $pEnd   = strtotime($data['period_end']);
        if ($pEnd <= $pStart) {
            $errors[] = 'Period end must be after period start.';
        } elseif (($pEnd - $pStart) > 366 * 86400) {
            $errors[] = 'Billing period cannot exceed 366 days. Use multiple payments for longer periods.';
        }
    }

    // Non-cash methods require a reference number
    $nonCashMethods = ['GCASH','CHEQUE','BPI','PNB','BDO','METROBANK','EASTWEST','XENDIT'];
    if (in_array($data['method'], $nonCashMethods) && $data['reference_number'] === '') {
        $errors[] = ucfirst(str_replace('_', ' ', $data['method'])) . ' payments require a reference number.';
    }

    $paymentRouterId = null;
    if ($data['subscriber_id']) {
        $routerStmt = db()->prepare("
            SELECT s.router_id, p.amount AS plan_amount, p.billing_cycle
            FROM subscribers s
            LEFT JOIN plans p ON p.plan_id = s.plan_id
            WHERE s.subscriber_id = ?
        ");
        $routerStmt->execute([$data['subscriber_id']]);
        $subInfo = $routerStmt->fetch();
        $subscriberRouterId = $subInfo ? $subInfo['router_id'] : false;
        if ($subscriberRouterId === false) {
            $errors[] = 'Selected subscriber was not found.';
        } else {
            $paymentRouterId = (int)$subscriberRouterId ?: null;
            if ($currentRouterId > 0 && (int)$subscriberRouterId !== $currentRouterId) {
                $errors[] = 'Selected subscriber does not belong to the current router.';
            }
            // Cashier cannot record less than plan price when extending a subscription period
            if (!hasMinRole(ROLE_ADMIN) && $selectedPaymentTypeIsDefault
                && $data['period_start'] && $data['period_end']
                && (float)($subInfo['plan_amount'] ?? 0) > 0
            ) {
                $aPlanAmount  = (float)$subInfo['plan_amount'];
                $aBillingDays = getBillingCycleMonths($subInfo['billing_cycle'] ?? 'monthly') * 30;
                $aDaysCovered = max(1, (strtotime($data['period_end']) - strtotime($data['period_start'])) / 86400);
                $aCycles      = max(1, (int)round($aDaysCovered / $aBillingDays));
                $aMinRequired = round($aPlanAmount * $aCycles, 2);
                if ($data['amount'] < $aMinRequired * 0.99) {
                    $errors[] = 'Amount ₱' . number_format($data['amount'], 2) . ' is below the minimum ₱' . number_format($aMinRequired, 2) . ' required for the selected billing period. Contact an administrator to override.';
                }
            }
        }
    }

    if (empty($errors)) {
        $pdo = db();
        $orNumber = generateORNumber(); // must run outside the transaction — it opens its own
        try {
            $pdo->beginTransaction();

            // Insert payment
            $pdo->prepare("
                INSERT INTO payments
                    (or_number, subscriber_id, router_id, payment_type_id, user_id, amount, tax_rate, tax_type, tax_amount,
                     method, reference_number, payment_date, period_start, period_end, status, notes, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $orNumber,
                $data['subscriber_id'],
                $paymentRouterId,
                $data['payment_type_id'],
                currentUserId(),
                $data['amount'],
                $data['tax_rate'],
                $data['tax_type'],
                $data['tax_amount'],
                $data['method'],
                $data['reference_number'] ?: null,
                $data['payment_date'],
                $data['period_start'] ?: null,
                $data['period_end']   ?: null,
                $data['status'],
                $data['notes']        ?: null,
                appNow(),
            ]);
            $payId = (int)$pdo->lastInsertId();

            // Update subscriber subscription dates when period_end is provided
            if (!empty($data['period_end'])) {
                $pdo->prepare("
                    UPDATE subscribers SET
                        subscription_start = ?,
                        subscription_end   = ?,
                        status             = 'active',
                        updated_at         = ?
                    WHERE subscriber_id = ?
                ")->execute([
                    $data['period_start'] ?: appToday(),
                    $rawPeriodEnd,
                    appNow(),
                    $data['subscriber_id'],
                ]);
            }

            $pdo->commit();
        } catch (PDOException $e) {
            try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (PDOException $re) {}
            $errors[] = APP_DEBUG
                ? 'Database error: ' . $e->getMessage()
                : 'Database error — payment not saved. Please try again.';
        }

        if (empty($errors)) {
            // Fetch subscriber (+ router fields) for notification, MRC log, and router reactivation
            $subStmt = db()->prepare("
                SELECT s.*,
                       r.host AS router_host, r.status AS router_status,
                       r.api_port, r.port AS router_port,
                       r.username AS r_user, r.password AS r_pass,
                       COALESCE(r.auth_type, 'local') AS auth_type
                FROM subscribers s
                LEFT JOIN routers r ON r.router_id = s.router_id
                WHERE s.subscriber_id = ?
            ");
            $subStmt->execute([$data['subscriber_id']]);
            $subRow = $subStmt->fetch();

            // Re-activate subscriber on router if payment covers a billing period
            if ($subRow && !empty($data['period_end'])) {
                reactivateSubscriberOnRouter($subRow);
            }

            // Log plan MRC vs. recorded amount for anomaly detection
            $planRow = null;
            if ($subRow && $subRow['plan_id']) {
                $planRow = db()->prepare("SELECT title, amount FROM plans WHERE plan_id = ?");
                $planRow->execute([$subRow['plan_id']]);
                $planRow = $planRow->fetch();
            }
            $mrcNote = $planRow ? ' (Plan MRC: ₱' . number_format($planRow['amount'], 2) . ')' : '';

            logActivity('payments', 'create',
                "Recorded payment {$orNumber} for subscriber ID {$data['subscriber_id']}: ₱" . number_format($data['amount'], 2) . $mrcNote,
                $data['subscriber_id']
            );

            if ($data['notify_sms'] && !empty($subRow['contact_number'])) {
                SMS::sendPaymentConfirmation($subRow, array_merge($data, ['payment_id' => $payId]));
            }
            if ($data['notify_email'] && !empty($subRow['email'])) {
                Mailer::sendPaymentConfirmation($subRow, array_merge($data, ['payment_id' => $payId]));
            }

            flashMessage('success', "Payment recorded. OR No: {$orNumber}");
            redirect(BASE_URL . '/modules/payments/receipt?id=' . $payId);
        }
    }
}

$pageTitle   = 'Record Payment';
$breadcrumbs = [
    ['label' => 'Payments', 'url' => BASE_URL . '/modules/payments/'],
    ['label' => 'Record Payment'],
];
$extraHead = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css">
<style>
[data-bs-theme="dark"] .ts-wrapper .ts-control,
[data-bs-theme="dark"] .ts-dropdown {
    background-color: var(--bs-body-bg);
    color: var(--bs-body-color);
    border-color: var(--bs-border-color);
}
[data-bs-theme="dark"] .ts-dropdown .option:hover,
[data-bs-theme="dark"] .ts-dropdown .option.active {
    background-color: var(--bs-primary);
    color: #fff;
}
[data-bs-theme="dark"] .ts-wrapper .ts-control input::placeholder {
    color: var(--bs-secondary-color);
}
</style>';
include BASE_PATH . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <h4 class="fw-bold mb-0">Record Payment</h4>
    <a href="./" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<?= inlineToasts($errors) ?>

<div class="row">
    <div class="col-lg-7">
        <form method="POST" id="paymentForm" novalidate>
            <?= csrfField() ?>

            <!-- Subscriber selection -->
            <div class="card border-0 mb-4">
                <div class="card-header bg-transparent border-bottom fw-semibold py-3">
                    <i class="bi bi-person me-2 text-success"></i>Subscriber
                </div>
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                        <label class="form-label fw-medium mb-0" for="subscriberSelect">Search Subscriber</label>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                            <i class="bi bi-router me-1"></i><?= e($currentRouterName) ?>
                        </span>
                    </div>
                    <select name="subscriber_id" id="subscriberSelect" required>
                        <option value="">— Search current router by name or account number —</option>
                    </select>
                    <div class="form-text">Search results are scoped to the currently selected router.</div>
                    <div id="subInfo" class="mt-2 small text-muted"></div>
                    <div id="balanceCard" class="d-none mt-3"></div>
                </div>
            </div>

            <!-- Payment details -->
            <div class="card border-0 mb-4">
                <div class="card-header bg-transparent border-bottom fw-semibold py-3">
                    <i class="bi bi-cash-coin me-2 text-success"></i>Payment Details
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-medium" for="paymentTypeSelect">Payment Type <span class="text-danger">*</span></label>
                            <select name="payment_type_id" class="form-select" id="paymentTypeSelect">
                                <?php foreach ($paymentTypes as $pt): ?>
                                <?php if (!$pt['is_active']) continue; ?>
                                <option value="<?= $pt['type_id'] ?>"
                                        data-default="<?= $pt['is_default'] ? '1' : '0' ?>"
                                        <?= (int)($_POST['payment_type_id'] ?? $defaultTypeId) === (int)$pt['type_id'] ? 'selected' : '' ?>>
                                    <?= e($pt['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium d-flex align-items-center gap-2 flex-wrap" for="amountInput">
                                <span>Amount (<?= e($currency) ?>) <span class="text-danger">*</span></span>
                                <span id="mrcBadge" class="d-none badge bg-success-subtle text-success border border-success-subtle fw-normal" style="font-size:.75rem;"></span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text fw-bold text-success"><?= e($currency) ?></span>
                                <input type="number" name="amount" id="amountInput"
                                       class="form-control form-control-lg fw-bold"
                                       step="0.01" min="0.01" max="999999"
                                       value="<?= e($_POST['amount'] ?? '') ?>" required>
                            </div>
                            <div id="amountWarning" class="form-text text-warning d-none">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                Amount is below the plan's monthly rate. Reason required in Notes.
                            </div>
                        </div>
                        <!-- Tax row -->
                        <div class="col-12">
                            <div class="card border bg-light rounded-3 p-3">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <span class="fw-medium"><i class="bi bi-percent me-2 text-warning"></i>Tax</span>
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" name="tax_apply"
                                               id="taxApply" role="switch"
                                               <?= ($taxEnabled || !empty($_POST['tax_apply'])) ? 'checked' : '' ?>>
                                        <label class="form-check-label small text-muted" for="taxApply">Apply tax</label>
                                    </div>
                                </div>
                                <div id="taxFields" class="row g-2 <?= ($taxEnabled || !empty($_POST['tax_apply'])) ? '' : 'd-none' ?>">
                                    <div class="col-sm-4">
                                        <label class="form-label small fw-medium" for="taxNameDisplay">Tax Name</label>
                                        <input type="text" name="tax_name_display" id="taxNameDisplay" class="form-control form-control-sm"
                                               value="<?= e($_POST['tax_name_display'] ?? $taxName) ?>"
                                               placeholder="VAT" maxlength="30" readonly>
                                    </div>
                                    <div class="col-sm-4">
                                        <label class="form-label small fw-medium" for="taxRateInput">Rate (%)</label>
                                        <div class="input-group input-group-sm">
                                            <input type="number" name="tax_rate" id="taxRateInput"
                                                   class="form-control form-control-sm"
                                                   value="<?= e($_POST['tax_rate'] ?? $taxRate) ?>"
                                                   step="0.01" min="0" max="100">
                                            <span class="input-group-text">%</span>
                                        </div>
                                    </div>
                                    <div class="col-sm-4">
                                        <label class="form-label small fw-medium" for="taxTypeSelect">Type</label>
                                        <select name="tax_type" id="taxTypeSelect" class="form-select form-select-sm">
                                            <option value="excluded" <?= ($_POST['tax_type'] ?? $taxType) === 'excluded' ? 'selected' : '' ?>>Excluded (added on top)</option>
                                            <option value="included" <?= ($_POST['tax_type'] ?? $taxType) === 'included' ? 'selected' : '' ?>>Included (within price)</option>
                                        </select>
                                    </div>
                                    <div class="col-12 mt-1" id="taxCalcDisplay">
                                        <div class="text-muted small">— enter amount to see tax breakdown —</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="methodSelect">Payment Method <span class="text-danger">*</span></label>
                            <select name="method" class="form-select" id="methodSelect">
                                <?php foreach (getPaymentMethods() as $val => $label): ?>
                                <option value="<?= e($val) ?>" <?= ($_POST['method'] ?? 'cash') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6" id="refGroup">
                            <label class="form-label fw-medium" id="refLabel" for="refInput">Reference Number</label>
                            <input type="text" name="reference_number" id="refInput" class="form-control font-monospace"
                                   placeholder="GCash ref, bank ref..."
                                   value="<?= e($_POST['reference_number'] ?? '') ?>">
                            <div id="refRequiredMsg" class="form-text text-danger d-none">Required for non-cash payments.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="paymentDate">Payment Date <span class="text-danger">*</span></label>
                            <input type="datetime-local" name="payment_date" id="paymentDate" class="form-control"
                                   value="<?= e($_POST['payment_date'] ?? date('Y-m-d\TH:i')) ?>" required>
                        </div>
                        <div class="col-md-6 sub-only-field">
                            <label class="form-label fw-medium" for="periodStart">Period Start</label>
                            <input type="date" name="period_start" class="form-control" id="periodStart"
                                   value="<?= e($_POST['period_start'] ?? date('Y-m-d')) ?>">
                        </div>
                        <div class="col-md-6 sub-only-field">
                            <label class="form-label fw-medium" for="periodEnd">Period End</label>
                            <input type="date" name="period_end" class="form-control" id="periodEnd"
                                   value="<?= e($_POST['period_end'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="paymentStatus">Payment Status</label>
                            <select name="status" id="paymentStatus" class="form-select">
                                <?php foreach (['paid','pending'] as $s): ?>
                                <option value="<?= $s ?>" <?= ($_POST['status'] ?? 'paid') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium" for="paymentNotes">Notes</label>
                            <textarea name="notes" id="paymentNotes" class="form-control" rows="2"><?= e($_POST['notes'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Options -->
            <div class="card border-0 mb-4">
                <div class="card-header bg-transparent border-bottom fw-semibold py-3">
                    <i class="bi bi-gear me-2 text-success"></i>Options
                </div>
                <div class="card-body">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="notify_sms" id="notifySms" <?= !SMS_ENABLED ? 'disabled' : '' ?>>
                        <label class="form-check-label" for="notifySms">Send SMS payment confirmation</label>
                        <?php if (!SMS_ENABLED): ?><div class="form-text">SMS is disabled in configuration.</div><?php endif; ?>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="notify_email" id="notifyEmail" <?= !MAIL_ENABLED ? 'disabled' : '' ?>>
                        <label class="form-check-label" for="notifyEmail">Send email receipt</label>
                        <?php if (!MAIL_ENABLED): ?><div class="form-text">Email is disabled in configuration.</div><?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-success btn-lg">
                    <i class="bi bi-cash-coin me-2"></i>Record Payment
                </button>
                <a href="./" class="btn btn-outline-secondary btn-lg">Cancel</a>
            </div>
        </form>
    </div>

    <!-- Summary panel -->
    <div class="col-lg-5">
        <div class="card border-0 sticky-top" style="top:80px;">
            <div class="card-header bg-success text-white py-3">
                <h6 class="mb-0 fw-semibold">Payment Summary</h6>
            </div>
            <div class="card-body" id="paymentSummary">
                <div class="text-center text-muted py-3">Select a subscriber to see summary</div>
            </div>
        </div>
    </div>
</div>

<?php
$plansData  = json_encode(
    array_column(
        db()->query("SELECT plan_id, title, amount, billing_cycle FROM plans WHERE is_active = 1")->fetchAll(),
        null,
        'plan_id'
    )
);
$taxConfig = json_encode([
    'enabled' => $taxEnabled,
    'name'    => $taxName,
    'rate'    => $taxRate,
    'type'    => $taxType,
]);
$defaultTypeIdJs = $defaultTypeId;
$currentRouterNameJs = json_encode($currentRouterName);

// Pre-selected subscriber data for Tom Select initialization
$preSubJs = $preSub ? json_encode([
    'subscriber_id'      => (int)$preSub['subscriber_id'],
    'display_text'       => $preSub['account_number'] . ' — ' . $preSub['firstname'] . ' ' . $preSub['lastname'],
    'plan_id'            => $preSub['plan_id'],
    'plan_amount'        => (float)($preSub['plan_amount'] ?? 0),
    'billing_cycle'      => $preSub['billing_cycle'] ?? 'monthly',
    'router_name'         => $preSub['router_name'] ?? $currentRouterName,
    'subscription_start' => $preSub['subscription_start'] ?? '',
    'subscription_end'   => $preSub['subscription_end']   ?? '',
    'total_paid'         => (float)($preSub['total_paid']  ?? 0),
    'first_pay_date'     => $preSub['first_pay_date'] ?? '',
]) : 'null';

$extraScripts = <<<JS
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script>
const plansData       = {$plansData};
const TAX             = {$taxConfig};
const DEFAULT_TYPE_ID = {$defaultTypeIdJs};
const PRE_SUB         = {$preSubJs};
const CURRENT_ROUTER_ID = {$currentRouterId};
const CURRENT_ROUTER_NAME = {$currentRouterNameJs};
const methods   = {CASH:'CASH',GCASH:'GCASH',CHEQUE:'CHEQUE',BPI:'BPI',PNB:'PNB',BDO:'BDO',METROBANK:'METROBANK',EASTWEST:'EASTWEST',XENDIT:'XENDIT'};
const sym       = (typeof CURRENCY_SYMBOL !== 'undefined' ? CURRENCY_SYMBOL : '₱');

// ── Payment type → enable/disable subscription period fields ──
function isDefaultPaymentType() {
    const sel = document.getElementById('paymentTypeSelect');
    return sel && sel.options[sel.selectedIndex]?.dataset.default === '1';
}

function applyTypeVisibility() {
    const isDefault = isDefaultPaymentType();
    document.querySelectorAll('.sub-only-field').forEach(el => {
        el.classList.toggle('opacity-50', !isDefault);
        el.querySelectorAll('input, select, textarea').forEach(input => {
            input.disabled = !isDefault;
            if (!isDefault) input.value = '';
        });
    });
    if (isDefault && currentSub) onSubscriberChange(currentSub);
    updateSummary();
}
document.getElementById('paymentTypeSelect')?.addEventListener('change', applyTypeVisibility);

function fmt(v) {
    return sym + parseFloat(v).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
}

// ── Tax toggle ────────────────────────────────────────────────
document.getElementById('taxApply')?.addEventListener('change', function () {
    const fields = document.getElementById('taxFields');
    fields.classList.toggle('d-none', !this.checked);
    updateTaxDisplay();
    updateSummary();
});

// ── Tax calc display ──────────────────────────────────────────
function calcTax(amount, rate, type) {
    if (!rate || !amount) return { taxAmt: 0, base: amount, total: amount };
    let taxAmt, base, total;
    if (type === 'excluded') {
        taxAmt = amount * rate / 100;
        base   = amount;
        total  = amount + taxAmt;
    } else {
        taxAmt = amount - (amount / (1 + rate / 100));
        base   = amount / (1 + rate / 100);
        total  = amount;
    }
    return { taxAmt: Math.round(taxAmt * 100) / 100, base: Math.round(base * 100) / 100, total: Math.round(total * 100) / 100 };
}

function updateTaxDisplay() {
    const apply   = document.getElementById('taxApply')?.checked;
    const amount  = parseFloat(document.getElementById('amountInput').value) || 0;
    const rate    = parseFloat(document.getElementById('taxRateInput').value) || 0;
    const type    = document.getElementById('taxTypeSelect').value;
    const taxName = document.querySelector('[name="tax_name_display"]')?.value || TAX.name;
    const el      = document.getElementById('taxCalcDisplay');
    if (!el) return;
    if (!apply || !amount || !rate) {
        el.innerHTML = '<div class="text-muted small">Enter amount and rate to preview tax.</div>';
        return;
    }
    const { taxAmt, base, total } = calcTax(amount, rate, type);
    const typeLabel = type === 'excluded'
        ? '<span class="badge bg-warning text-dark">Excluded</span>'
        : '<span class="badge bg-info text-dark">Included</span>';
    el.innerHTML = `
        <div class="d-flex flex-wrap gap-3 small">
            <span class="text-muted">Base: <strong class="text-body">\${fmt(base)}</strong></span>
            <span class="text-warning fw-medium">\${taxName} (\${rate}%) \${typeLabel}: <strong>\${fmt(taxAmt)}</strong></span>
            <span class="text-success fw-bold">Total: \${fmt(total)}</span>
        </div>`;
}

['amountInput','taxRateInput','taxTypeSelect'].forEach(id => {
    document.getElementById(id)?.addEventListener('input', () => { updateTaxDisplay(); updateSummary(); });
    document.getElementById(id)?.addEventListener('change', () => { updateTaxDisplay(); updateSummary(); });
});

// ── Helpers ───────────────────────────────────────────────────
function toDateOnly(str) {
    return str ? str.split(' ')[0].split('T')[0] : '';
}

// Format a Date as YYYY-MM-DD using local (browser) timezone, not UTC
function localISO(d) {
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

function addCycle(isoDate, cycle) {
    const d = new Date(toDateOnly(isoDate) + 'T00:00:00');
    if (cycle === 'quarterly') d.setMonth(d.getMonth() + 3);
    else if (cycle === 'annual') d.setFullYear(d.getFullYear() + 1);
    else d.setMonth(d.getMonth() + 1);
    return localISO(d);
}

function computeBalance(subStart, firstPayDate, totalPaid, mrc, cycle, subEnd) {
    if (!mrc) return null;
    const cycleDays = { quarterly: 90, annual: 365 }[cycle] || 30;
    // Always use subscription_start as baseline so plan changes don't produce
    // phantom credits from payments made under a different (old) plan.
    const baseDate  = toDateOnly(subStart);
    if (!baseDate) return null;
    const todayStr  = localISO(new Date());
    if (baseDate === todayStr) return Math.round(totalPaid * 100) / 100;
    const startMs    = new Date(baseDate + 'T00:00:00').getTime();
    const subEndStr  = toDateOnly(subEnd || '');
    const nowMs      = Date.now();
    const subEndMs   = subEndStr ? new Date(subEndStr + 'T00:00:00').getTime() : 0;
    const isExpired  = subEndMs > 0 && subEndMs < nowMs;
    // For expired subs, cap elapsed days at subscription_end and use floor to avoid
    // calendar-month rounding (e.g. ceil(31/30)=2 for a single paid month).
    const effectiveMs = isExpired ? subEndMs : nowMs;
    const daysElap    = Math.max(0, (effectiveMs - startMs) / 86400000);
    const cycles      = isExpired ? Math.floor(daysElap / cycleDays) : Math.ceil(daysElap / cycleDays);
    return Math.round((totalPaid - cycles * mrc) * 100) / 100;
}

// ── Balance card (reads plain object, not DOM dataset) ────────
function renderBalanceCard(sub) {
    const balCard = document.getElementById('balanceCard');
    if (!sub) { balCard.className = 'd-none mt-3'; balCard.innerHTML = ''; return; }

    const mrc          = parseFloat(sub.plan_amount) || 0;
    const cycle        = sub.billing_cycle || 'monthly';
    const paid         = parseFloat(sub.total_paid) || 0;
    const subStart     = toDateOnly(sub.subscription_start || '');
    const firstPayDate = toDateOnly(sub.first_pay_date || '');
    const subEnd       = toDateOnly(sub.subscription_end   || '');

    if (!mrc) { balCard.className = 'd-none mt-3'; balCard.innerHTML = ''; return; }

    const balance       = computeBalance(subStart, firstPayDate, paid, mrc, cycle, subEnd);
    const subEndMs      = subEnd ? new Date(subEnd + 'T00:00:00').getTime() : 0;
    const isSubActive   = subEndMs > 0 && subEndMs >= Date.now();
    const isNegBal      = balance !== null && balance < -50;
    const isOverdue     = isNegBal && !isSubActive;
    const isAdvance     = isNegBal && isSubActive;
    const isCredit      = balance !== null && balance > 50;
    const borderClr = isOverdue ? '#dc3545' : (isAdvance ? '#0d6efd' : (isCredit ? '#198754' : '#6c757d'));
    const bgClass   = isOverdue ? 'bg-danger-subtle' : (isAdvance ? 'bg-primary-subtle' : (isCredit ? 'bg-success-subtle' : 'bg-light'));
    const icon      = isOverdue ? 'bi-exclamation-triangle-fill text-danger'
                                : (isAdvance ? 'bi-calendar-check-fill text-primary'
                                : (isCredit ? 'bi-check-circle-fill text-success' : 'bi-info-circle-fill text-secondary'));
    const label     = isOverdue ? 'Account Overdue' : (isAdvance ? 'Advance Payment' : (isCredit ? 'Account in Credit' : 'Account Current'));
    const labelCls  = isOverdue ? 'text-danger' : (isAdvance ? 'text-primary' : (isCredit ? 'text-success' : 'text-secondary'));
    const balStr    = balance === null ? '—' : ((balance < 0 ? '−' : '+') + fmt(Math.abs(balance)));
    const endFmt    = subEnd
        ? new Date(subEnd + 'T00:00:00').toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'})
        : '—';

    balCard.className = 'mt-3 rounded-3 border ' + bgClass;
    balCard.style.borderLeft = '4px solid ' + borderClr;
    balCard.innerHTML = `
        <div class="p-3">
            <div class="d-flex align-items-center gap-2 mb-2">
                <i class="bi \${icon}"></i>
                <span class="fw-semibold small \${labelCls}">\${label}</span>
            </div>
            <div class="d-flex flex-wrap gap-4">
                <div>
                    <div class="text-muted fw-medium" style="font-size:.68rem;letter-spacing:.04em;">BALANCE</div>
                    <div class="fw-bold fs-6 \${balance < 0 ? 'text-danger' : 'text-success'}">\${balStr}</div>
                </div>
                <div>
                    <div class="text-muted fw-medium" style="font-size:.68rem;letter-spacing:.04em;">MRC</div>
                    <div class="fw-bold fs-6">\${fmt(mrc)}<span class="text-muted fw-normal small"> / \${cycle}</span></div>
                </div>
                <div>
                    <div class="text-muted fw-medium" style="font-size:.68rem;letter-spacing:.04em;">TOTAL PAID</div>
                    <div class="fw-bold fs-6 text-success">\${fmt(paid)}</div>
                </div>
                <div>
                    <div class="text-muted fw-medium" style="font-size:.68rem;letter-spacing:.04em;">EXPIRES</div>
                    <div class="fw-bold fs-6">\${endFmt}</div>
                </div>
            </div>
        </div>`;
}

// ── Current subscriber state ──────────────────────────────────
let currentSub = null;

function onSubscriberChange(sub) {
    currentSub = sub || null;
    const balCard = document.getElementById('balanceCard');

    if (!currentSub) {
        balCard.className = 'd-none mt-3';
        balCard.innerHTML = '';
        const _badge = document.getElementById('mrcBadge');
        if (_badge) { _badge.textContent = ''; _badge.classList.add('d-none'); }
        updateSummary();
        return;
    }

    const plan   = plansData[currentSub.plan_id];
    const subEnd = toDateOnly(currentSub.subscription_end || '');
    const mrc    = parseFloat(currentSub.plan_amount) || (plan ? parseFloat(plan.amount) : 0);
    const cycle  = currentSub.billing_cycle || (plan ? plan.billing_cycle : 'monthly') || 'monthly';

    // Auto-fill period_start from subscription_end
    let periodStartVal;
    const today = new Date(); today.setHours(0,0,0,0);
    if (subEnd) {
        const endDate = new Date(subEnd + 'T00:00:00');
        if (endDate >= today) {
            endDate.setDate(endDate.getDate() + 1);
            periodStartVal = localISO(endDate);
        } else {
            periodStartVal = localISO(today);
        }
    } else {
        periodStartVal = localISO(today);
    }
    if (isDefaultPaymentType()) {
        document.getElementById('periodStart').value = periodStartVal;
        document.getElementById('periodEnd').value   = addCycle(periodStartVal, cycle);
    }

    if (mrc) {
        document.getElementById('amountInput').value = mrc.toFixed(2);
        updateTaxDisplay();
    }
    const mrcBadge = document.getElementById('mrcBadge');
    if (mrcBadge && mrc) {
        mrcBadge.textContent = 'MRC: ' + fmt(mrc) + ' / ' + cycle;
        mrcBadge.classList.remove('d-none');
    }

    renderBalanceCard(currentSub);
    checkAmountWarning();
    updateSummary();
}

const nonCashMethods = ['GCASH','CHEQUE','BPI','PNB','BDO','METROBANK','EASTWEST','XENDIT'];

function checkAmountWarning() {
    const mrc    = parseFloat(currentSub?.plan_amount) || 0;
    const amount = parseFloat(document.getElementById('amountInput')?.value) || 0;
    const warn   = document.getElementById('amountWarning');
    if (warn && mrc > 0 && amount > 0 && amount < mrc * 0.5) {
        warn.classList.remove('d-none');
    } else if (warn) {
        warn.classList.add('d-none');
    }
}

function checkRefRequired() {
    const method   = document.getElementById('methodSelect')?.value;
    const refGroup = document.getElementById('refGroup');
    const refMsg   = document.getElementById('refRequiredMsg');
    const refLabel = document.getElementById('refLabel');
    const required = nonCashMethods.includes(method);
    if (refGroup) refGroup.style.opacity = required ? '1' : '0.5';
    if (refMsg)   refMsg.classList.toggle('d-none', !required);
    if (refLabel) {
        refLabel.innerHTML = required
            ? 'Reference Number <span class="text-danger">*</span>'
            : 'Reference Number';
    }
}

document.getElementById('amountInput')?.addEventListener('input', () => { checkAmountWarning(); updateSummary(); });
document.getElementById('methodSelect')?.addEventListener('change', function () {
    checkRefRequired();
    updateSummary();
});
document.getElementById('periodEnd')?.addEventListener('change', updateSummary);
checkRefRequired();

// ── Summary panel ─────────────────────────────────────────────
function updateSummary() {
    if (!currentSub) {
        document.getElementById('paymentSummary').innerHTML = '<div class="text-center text-muted py-3">Select a subscriber to see summary</div>';
        return;
    }

    const amount  = parseFloat(document.getElementById('amountInput').value) || 0;
    const method  = document.getElementById('methodSelect').value;
    const isDefaultType = isDefaultPaymentType();
    const end     = isDefaultType ? document.getElementById('periodEnd').value : '';
    const plan    = plansData[currentSub.plan_id];
    const apply   = document.getElementById('taxApply')?.checked;
    const rate    = parseFloat(document.getElementById('taxRateInput')?.value) || 0;
    const type    = document.getElementById('taxTypeSelect')?.value || 'excluded';
    const taxLbl  = document.querySelector('[name="tax_name_display"]')?.value || TAX.name;

    const { taxAmt, base, total } = apply && rate ? calcTax(amount, rate, type) : { taxAmt: 0, base: amount, total: amount };
    const typeLabel = type === 'excluded' ? 'Excluded' : 'Included';

    let taxRows = '';
    if (apply && rate > 0) {
        taxRows = `
        <div class="d-flex justify-content-between mb-1 text-warning small">
            <span>\${taxLbl} \${rate}% (\${typeLabel})</span>
            <span>+ \${fmt(taxAmt)}</span>
        </div>
        <hr class="my-2">
        <div class="d-flex justify-content-between mb-2">
            <span class="text-muted">Total</span>
            <span class="fw-bold text-success fs-5">\${fmt(total)}</span>
        </div>`;
    }

    const mrcVal        = parseFloat(currentSub.plan_amount) || 0;
    const cycleVal      = currentSub.billing_cycle || 'monthly';
    const paidVal       = parseFloat(currentSub.total_paid) || 0;
    const firstPayVal   = toDateOnly(currentSub.first_pay_date || '');
    const bal           = computeBalance(toDateOnly(currentSub.subscription_start || ''), firstPayVal, paidVal, mrcVal, cycleVal, toDateOnly(currentSub.subscription_end || ''));
    const balColor = bal === null ? '' : (bal < -50 ? 'text-danger' : (bal > 50 ? 'text-success' : 'text-secondary'));
    const balStr   = bal === null ? '—' : ((bal < 0 ? '−' : '+') + fmt(Math.abs(bal)));

    document.getElementById('paymentSummary').innerHTML = `
        <div class="mb-3">
            <div class="text-muted small">Subscriber</div>
            <div class="fw-semibold">\${currentSub.display_text || ''}</div>
        </div>
        <div class="mb-3">
            <div class="text-muted small">Plan</div>
            <div class="fw-semibold">\${plan ? plan.title : '—'}</div>
        </div>
        <div class="d-flex justify-content-between mb-2">
            <span class="text-muted small">Current Balance</span>
            <span class="fw-semibold \${balColor}">\${balStr}</span>
        </div>
        <div class="d-flex justify-content-between mb-3">
            <span class="text-muted small">MRC</span>
            <span class="fw-semibold">\${mrcVal ? fmt(mrcVal) + ' / ' + cycleVal : '—'}</span>
        </div>
        <hr>
        <div class="d-flex justify-content-between mb-2">
            <span class="text-muted">\${apply && rate && type === 'excluded' ? 'Base Amount' : 'Amount'}</span>
            <span class="fw-bold text-success fs-5">\${fmt(amount)}</span>
        </div>
        \${taxRows}
        <div class="d-flex justify-content-between mb-2">
            <span class="text-muted">Method</span>
            <span>\${methods[method] || method}</span>
        </div>
        <div class="d-flex justify-content-between">
            <span class="text-muted">Valid Until</span>
            <span class="fw-medium">\${isDefaultType ? (end ? new Date(end + 'T00:00:00').toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}) : '—') : 'Not applied'}</span>
        </div>
    `;
}

// ── Tom Select: AJAX subscriber search ────────────────────────
new TomSelect('#subscriberSelect', {
    maxItems: 1,
    valueField: 'subscriber_id',
    labelField: 'display_text',
    searchField: ['display_text'],
    preload: false,
    shouldLoad: function(q) { return q.length >= 2; },
    load: function(query, callback) {
        const params = new URLSearchParams({ q: query });
        if (CURRENT_ROUTER_ID > 0) params.set('router_id', String(CURRENT_ROUTER_ID));
        fetch(BASE_URL + '/api/payment_subscriber_search.php?' + params.toString(), { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(json) {
                callback((json.data || []).map(function(s) {
                    return {
                        subscriber_id:      s.subscriber_id,
                        display_text:       s.account_number + ' — ' + s.firstname + ' ' + s.lastname,
                        plan_id:            s.plan_id,
                        plan_amount:        parseFloat(s.plan_amount) || 0,
                        billing_cycle:      s.billing_cycle || 'monthly',
                        router_name:         s.router_name || CURRENT_ROUTER_NAME,
                        subscription_start: s.subscription_start || '',
                        subscription_end:   s.subscription_end   || '',
                        total_paid:         parseFloat(s.total_paid) || 0,
                        first_pay_date:     s.first_pay_date || '',
                    };
                }));
            })
            .catch(function() { callback(); });
    },
    render: {
        option: function(item, escape) {
            return '<div class="py-1 px-1">'
                + '<div>' + escape(item.display_text) + '</div>'
                + '<div class="text-muted small"><i class="bi bi-router me-1"></i>' + escape(item.router_name || CURRENT_ROUTER_NAME || 'Current router') + '</div>'
                + '</div>';
        },
        item: function(item, escape) {
            return '<div>' + escape(item.display_text) + '</div>';
        },
        no_results: function(data, escape) {
            return '<div class="no-results px-3 py-2 text-muted fst-italic">No subscriber found for “' + escape(data.input) + '”</div>';
        },
        not_loading: function(data) {
            if (!data.input || data.input.length < 2) {
                return '<div class="no-results px-3 py-2 text-muted small">Type at least 2 characters to search…</div>';
            }
        },
        loading: function() {
            return '<div class="px-3 py-2 text-muted small">Searching…</div>';
        },
    },
    onChange: function(value) {
        onSubscriberChange(value ? (this.options[value] || null) : null);
    },
    options: PRE_SUB ? [PRE_SUB] : [],
    items:   PRE_SUB ? [String(PRE_SUB.subscriber_id)] : [],
});

// Init
updateTaxDisplay();
checkRefRequired();
if (PRE_SUB) onSubscriberChange(PRE_SUB);
applyTypeVisibility();
</script>
JS;

include BASE_PATH . '/includes/footer.php';
