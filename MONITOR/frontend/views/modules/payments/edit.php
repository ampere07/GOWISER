<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireLogin();

if (currentRole() !== ROLE_SUPERADMIN) {
    flashMessage('danger', 'Only Super Administrator can edit payments.');
    redirect(BASE_URL . '/modules/payments/');
}

$id   = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare("
    SELECT py.*,
           s.firstname, s.lastname, s.account_number,
           CONCAT(ua.firstname, ' ', ua.lastname) AS added_by_name,
           ua.username                             AS added_by_username,
           CONCAT(ue.firstname, ' ', ue.lastname) AS edited_by_name,
           ue.username                             AS edited_by_username
    FROM payments py
    JOIN subscribers s ON s.subscriber_id = py.subscriber_id
    LEFT JOIN users ua ON ua.user_id = py.user_id
    LEFT JOIN users ue ON ue.user_id = py.updated_by
    WHERE py.payment_id = ?
");
$stmt->execute([$id]);
$payment = $stmt->fetch();
if (!$payment) {
    flashMessage('danger', 'Payment not found.');
    redirect(BASE_URL . '/modules/payments/');
}

// Load payment types
$paymentTypes  = getPaymentTypes();
$defaultTypeId = 0;
foreach ($paymentTypes as $pt) { if ($pt['is_default']) { $defaultTypeId = (int)$pt['type_id']; break; } }

// Tax settings (for defaults / name display)
$taxName = getSetting('tax_name', 'VAT');
$taxRate = (float)getSetting('tax_rate', '0');
$taxType = getSetting('tax_type', 'included');

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $postTaxType  = in_array($_POST['tax_type'] ?? '', ['included', 'excluded'])
                     ? $_POST['tax_type'] : $taxType;
    // Clamp tax rate 0–100 (bypassing HTML max= is trivial via devtools)
    $postTaxRate  = min(100.0, max(0.0, (float)($_POST['tax_rate'] ?? 0)));
    $baseAmount   = (float)($_POST['amount']   ?? 0);

    $calcTaxAmount = 0;
    if (!empty($_POST['tax_apply']) && $postTaxRate > 0) {
        if ($postTaxType === 'excluded') {
            $calcTaxAmount = $baseAmount * $postTaxRate / 100;
        } else {
            $calcTaxAmount = $baseAmount - ($baseAmount / (1 + $postTaxRate / 100));
        }
    }

    $data = [
        'payment_type_id'  => (int)($_POST['payment_type_id'] ?? $payment['payment_type_id'] ?? $defaultTypeId) ?: $defaultTypeId,
        'amount'           => $baseAmount,
        'tax_rate'         => !empty($_POST['tax_apply']) ? $postTaxRate : 0,
        'tax_type'         => $postTaxType,
        'tax_amount'       => round($calcTaxAmount, 2),
        'method'           => trim($_POST['method']           ?? ''),
        'reference_number' => trim($_POST['reference_number'] ?? ''),
        'payment_date'     => trim($_POST['payment_date']     ?? ''),
        'period_start'     => trim($_POST['period_start']     ?? ''),
        'period_end'       => trim($_POST['period_end']       ?? ''),
        // Whitelist status — cashiers cannot set refunded/cancelled
        'status'           => (function($raw) {
            $allowed = hasMinRole(ROLE_ADMIN)
                ? ['paid','pending','refunded','cancelled']
                : ['paid','pending'];
            return in_array($raw, $allowed) ? $raw : 'paid';
        })(trim($_POST['status'] ?? 'paid')),
        'notes'            => trim($_POST['notes']            ?? ''),
    ];

    if ($data['amount'] <= 0)        $errors[] = 'Amount must be greater than 0.';
    if ($data['amount'] > 999999)    $errors[] = 'Amount exceeds the maximum allowed (₱999,999).';
    if (empty($data['payment_date'])) $errors[] = 'Payment date is required.';

    if (empty($errors)) {
        db()->prepare("
            UPDATE payments SET
                payment_type_id=?, amount=?, tax_rate=?, tax_type=?, tax_amount=?,
                method=?, reference_number=?, payment_date=?,
                period_start=?, period_end=?, status=?, notes=?,
                updated_at=?, updated_by=?
            WHERE payment_id=?
        ")->execute([
            $data['payment_type_id'],
            $data['amount'],
            $data['tax_rate'],
            $data['tax_type'],
            $data['tax_amount'],
            $data['method'],
            $data['reference_number'] ?: null,
            $data['payment_date'],
            $data['period_start']     ?: null,
            $data['period_end']       ?: null,
            $data['status'],
            $data['notes']            ?: null,
            appNow(),
            currentUserId(),
            $id,
        ]);

        logActivity('payments', 'edit',
            "Edited payment {$payment['or_number']} for {$payment['account_number']}", null,
            ['amount' => $payment['amount'], 'tax_rate' => $payment['tax_rate'], 'status' => $payment['status']],
            ['amount' => $data['amount'],    'tax_rate' => $data['tax_rate'],    'status' => $data['status']]
        );

        flashMessage('success', "Payment {$payment['or_number']} updated.");
        redirect(BASE_URL . '/modules/payments/');
    }
    $payment = array_merge($payment, $data);
}

// Resolve display values (POST re-fill or DB values)
$curTaxRate   = (float)($payment['tax_rate']   ?? 0);
$curTaxType   = $payment['tax_type']   ?? $taxType;
$curTaxApply  = $curTaxRate > 0;

$pageTitle   = 'Edit Payment';
$breadcrumbs = [
    ['label' => 'Payments', 'url' => BASE_URL . '/modules/payments/'],
    ['label' => 'Edit Payment'],
];
include BASE_PATH . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">Edit Payment</h4>
        <p class="text-muted small mb-0">
            OR# <strong class="font-monospace"><?= e($payment['or_number'] ?? '') ?></strong>
            — <?= e($payment['firstname'] . ' ' . $payment['lastname']) ?>
            (<?= e($payment['account_number']) ?>)
        </p>
        <p class="text-muted small mb-0 mt-1">
            <i class="bi bi-person-plus me-1"></i>Added by:
            <strong><?= $payment['added_by_name'] ? e(trim($payment['added_by_name'])) . ' (@' . e($payment['added_by_username']) . ')' : '—' ?></strong>
            <?php if ($payment['created_at']): ?>
                <span class="text-secondary">· <?= e(formatDate($payment['created_at'], 'M d, Y g:i A')) ?></span>
            <?php endif; ?>
            <?php if ($payment['edited_by_name']): ?>
                &nbsp;<i class="bi bi-pencil ms-2 me-1"></i>Edited by:
                <strong><?= e(trim($payment['edited_by_name'])) . ' (@' . e($payment['edited_by_username']) . ')' ?></strong>
                <?php if ($payment['updated_at']): ?>
                    <span class="text-secondary">· <?= e(formatDate($payment['updated_at'], 'M d, Y g:i A')) ?></span>
                <?php endif; ?>
            <?php endif; ?>
        </p>
    </div>
    <a href="<?= BASE_URL ?>/modules/payments/" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<?= inlineToasts($errors) ?>

<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card border-0">
            <div class="card-body">
                <form method="POST" novalidate>
                    <?= csrfField() ?>
                    <div class="row g-3">

                        <!-- Payment Type -->
                        <div class="col-12">
                            <label class="form-label fw-medium" for="paymentTypeSelect">Payment Type <span class="text-danger">*</span></label>
                            <select name="payment_type_id" class="form-select" id="paymentTypeSelect">
                                <?php
                                $curTypeId = (int)($_POST['payment_type_id'] ?? $payment['payment_type_id'] ?? $defaultTypeId);
                                foreach ($paymentTypes as $pt):
                                    if (!$pt['is_active'] && (int)$pt['type_id'] !== $curTypeId) continue;
                                ?>
                                <option value="<?= $pt['type_id'] ?>"
                                        data-default="<?= $pt['is_default'] ? '1' : '0' ?>"
                                        <?= $curTypeId === (int)$pt['type_id'] ? 'selected' : '' ?>>
                                    <?= e($pt['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Amount -->
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="amountInput">Amount <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text fw-bold text-success"><?= e(getSetting('currency_symbol', '₱')) ?></span>
                                <input type="number" name="amount" id="amountInput"
                                       class="form-control fw-bold"
                                       step="0.01" min="0.01"
                                       value="<?= e($payment['amount']) ?>" required>
                            </div>
                        </div>

                        <!-- Tax -->
                        <div class="col-12">
                            <div class="card border bg-light rounded-3 p-3">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <span class="fw-medium"><i class="bi bi-percent me-2 text-warning"></i>Tax</span>
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" name="tax_apply"
                                               id="taxApply" role="switch"
                                               <?= $curTaxApply ? 'checked' : '' ?>>
                                        <label class="form-check-label small text-muted" for="taxApply">Apply tax</label>
                                    </div>
                                </div>
                                <div id="taxFields" class="row g-2 <?= $curTaxApply ? '' : 'd-none' ?>">
                                    <div class="col-sm-4">
                                        <label class="form-label small fw-medium" for="taxNameDisplayEdit">Tax Name</label>
                                        <input type="text" name="tax_name_display" id="taxNameDisplayEdit" class="form-control form-control-sm"
                                               value="<?= e($taxName) ?>"
                                               placeholder="VAT" maxlength="30" readonly>
                                    </div>
                                    <div class="col-sm-4">
                                        <label class="form-label small fw-medium" for="taxRateInput">Rate (%)</label>
                                        <div class="input-group input-group-sm">
                                            <input type="number" name="tax_rate" id="taxRateInput"
                                                   class="form-control form-control-sm"
                                                   value="<?= e($curTaxRate ?: $taxRate) ?>"
                                                   step="0.01" min="0" max="100">
                                            <span class="input-group-text">%</span>
                                        </div>
                                    </div>
                                    <div class="col-sm-4">
                                        <label class="form-label small fw-medium" for="taxTypeSelect">Type</label>
                                        <select name="tax_type" id="taxTypeSelect" class="form-select form-select-sm">
                                            <option value="excluded" <?= $curTaxType === 'excluded' ? 'selected' : '' ?>>Excluded (added on top)</option>
                                            <option value="included" <?= $curTaxType === 'included' ? 'selected' : '' ?>>Included (within price)</option>
                                        </select>
                                    </div>
                                    <div class="col-12 mt-1" id="taxCalcDisplay">
                                        <div class="text-muted small">— enter amount to see tax breakdown —</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Method -->
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="editMethodSelect">Payment Method <span class="text-danger">*</span></label>
                            <select name="method" id="editMethodSelect" class="form-select">
                                <?php foreach (getPaymentMethods() as $val => $label): ?>
                                <option value="<?= e($val) ?>" <?= $payment['method'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Reference -->
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="editRefInput">Reference Number</label>
                            <input type="text" name="reference_number" id="editRefInput" class="form-control font-monospace"
                                   value="<?= e($payment['reference_number'] ?? '') ?>">
                        </div>

                        <!-- Date -->
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="editPaymentDate">Payment Date <span class="text-danger">*</span></label>
                            <input type="datetime-local" name="payment_date" id="editPaymentDate" class="form-control"
                                   value="<?= e(str_replace(' ', 'T', $payment['payment_date'])) ?>" required>
                        </div>

                        <!-- Period -->
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="editPeriodStart">Period Start</label>
                            <input type="date" name="period_start" id="editPeriodStart" class="form-control"
                                   value="<?= e($payment['period_start'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="editPeriodEnd">Period End</label>
                            <input type="date" name="period_end" id="editPeriodEnd" class="form-control"
                                   value="<?= e($payment['period_end'] ?? '') ?>">
                        </div>

                        <!-- Status -->
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="editStatus">Status</label>
                            <select name="status" id="editStatus" class="form-select">
                                <?php foreach (['paid','pending','refunded','cancelled'] as $s): ?>
                                <option value="<?= $s ?>" <?= $payment['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Notes -->
                        <div class="col-12">
                            <label class="form-label fw-medium" for="editNotes">Notes</label>
                            <textarea name="notes" id="editNotes" class="form-control" rows="2"><?= e($payment['notes'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <?= inlineToasts([['message' => 'Editing a payment creates an audit log entry. The OR number cannot be changed.', 'type' => 'warning']]) ?>

                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Save Changes
                        </button>
                        <a href="<?= BASE_URL ?>/modules/payments/" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
$taxConfig = json_encode([
    'name' => $taxName,
    'rate' => $taxRate,
    'type' => $taxType,
]);
$sym = getSetting('currency_symbol', '₱');
$extraScripts = <<<JS
<script>
const TAX = {$taxConfig};
const sym = '{$sym}';

function fmt(v) {
    return sym + parseFloat(v).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
}

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
    return {
        taxAmt: Math.round(taxAmt * 100) / 100,
        base:   Math.round(base   * 100) / 100,
        total:  Math.round(total  * 100) / 100
    };
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
        ? `Base \${fmt(base)} + \${taxName} \${fmt(taxAmt)} = Total \${fmt(total)}`
        : `Total \${fmt(total)} includes \${taxName} \${fmt(taxAmt)} (Base \${fmt(base)})`;
    el.innerHTML = `<div class="alert alert-info py-1 px-2 mb-0 small">\${typeLabel}</div>`;
}

// Toggle tax fields
document.getElementById('taxApply')?.addEventListener('change', function () {
    document.getElementById('taxFields').classList.toggle('d-none', !this.checked);
    updateTaxDisplay();
});

// Live recalc
['amountInput', 'taxRateInput'].forEach(id => {
    document.getElementById(id)?.addEventListener('input', updateTaxDisplay);
});
document.getElementById('taxTypeSelect')?.addEventListener('change', updateTaxDisplay);

// Init display on load
updateTaxDisplay();
</script>
JS;

include BASE_PATH . '/includes/footer.php';
