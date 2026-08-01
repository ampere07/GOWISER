<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireRole(ROLE_CASHIER, ROLE_ADMIN, ROLE_SUPERADMIN);
if (!in_array(currentRole(), MODULE_ACCESS['expenses'])) forbidden();

if (!selectedRouterId() && currentRole() !== ROLE_SUPERADMIN) {
    $router = defaultRouterForUser(currentUser() ?? []);
    if ($router) setSelectedRouter((int)$router['router_id'], $router['name']);
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) { flashMessage('danger', 'Invalid expense.'); redirect(BASE_URL . '/modules/expenses/'); }
$routerId = (int)(selectedRouterId() ?: 0);

$stmt = db()->prepare("
    SELECT e.*, et.name AS type_name
    FROM expenses e
    LEFT JOIN expense_types et ON et.type_id = e.expense_type_id
    WHERE e.expense_id = ? AND e.router_id = ?
");
$stmt->execute([$id, $routerId]);
$expense = $stmt->fetch();
if (!$expense) { flashMessage('danger', 'Expense not found.'); redirect(BASE_URL . '/modules/expenses/'); }

// Cashiers can only edit their own expenses; admin/super can edit any
if (currentRole() === ROLE_CASHIER && (int)$expense['user_id'] !== currentUserId()) {
    flashMessage('danger', 'You can only edit expenses you recorded.');
    redirect(BASE_URL . '/modules/expenses/');
}

$errors       = [];
$expenseTypes = db()->query("SELECT * FROM expense_types WHERE is_active = 1 ORDER BY name")->fetchAll();
$currency     = getSetting('currency_symbol', '₱');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $data = [
        'expense_type_id' => (int)($_POST['expense_type_id'] ?? 0) ?: null,
        'employee'        => trim($_POST['employee']     ?? '') ?: null,
        'amount'          => (float)($_POST['amount']    ?? 0),
        'remark'          => trim($_POST['remark']       ?? '') ?: null,
        'expense_date'    => trim($_POST['expense_date'] ?? ''),
        'period_type'     => $_POST['period_type'] ?? 'daily',
    ];

    if ($data['amount'] <= 0)         $errors[] = 'Amount must be greater than 0.';
    if ($data['amount'] > 9999999)    $errors[] = 'Amount exceeds maximum allowed.';
    if (empty($data['expense_date'])) $errors[] = 'Expense date is required.';
    if (!in_array($data['period_type'], ['daily','monthly','yearly'])) $data['period_type'] = 'daily';

    // Receipt handling
    $receiptPath  = $expense['receipt'];
    $receiptMime  = $expense['receipt_mime'];
    $receiptName  = $expense['receipt_name'];
    $clearReceipt = !empty($_POST['clear_receipt']);

    if ($clearReceipt) {
        if ($receiptPath && file_exists(BASE_PATH . '/' . $receiptPath)) {
            @unlink(BASE_PATH . '/' . $receiptPath);
        }
        $receiptPath = null; $receiptMime = null; $receiptName = null;
    } elseif (!empty($_FILES['receipt']['name'])) {
        $file     = $_FILES['receipt'];
        $maxBytes = 5 * 1024 * 1024;
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Receipt upload failed (error code ' . $file['error'] . ').';
        } elseif ($file['size'] > $maxBytes) {
            $errors[] = 'Receipt file must be smaller than 5 MB.';
        } else {
            $allowedMimes = ['image/jpeg','image/png','image/gif','image/webp','application/pdf'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!in_array($mime, $allowedMimes)) {
                $errors[] = 'Receipt must be an image (JPG, PNG, GIF, WEBP) or PDF.';
            } else {
                if ($expense['receipt'] && file_exists(BASE_PATH . '/' . $expense['receipt'])) {
                    @unlink(BASE_PATH . '/' . $expense['receipt']);
                }
                $extMap   = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp','application/pdf'=>'pdf'];
                $ext      = $extMap[$mime] ?? 'bin';
                $fname    = bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
                $destPath = BASE_PATH . '/uploads/receipts/' . $fname;
                if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                    $errors[] = 'Failed to save receipt file.';
                } else {
                    $receiptPath = 'uploads/receipts/' . $fname;
                    $receiptMime = $mime;
                    $receiptName = basename($file['name']);
                }
            }
        }
    }

    if (empty($errors)) {
        $oldTypeName = $expense['type_name'] ?: 'Uncategorized';
        $newTypeName = 'Uncategorized';
        foreach ($expenseTypes as $typeRow) {
            if ((int)$typeRow['type_id'] === (int)$data['expense_type_id']) {
                $newTypeName = $typeRow['name'];
                break;
            }
        }
        db()->prepare("
            UPDATE expenses SET
                expense_type_id=?, employee=?, amount=?,
                receipt=?, receipt_mime=?, receipt_name=?,
                remark=?, expense_date=?, period_type=?, updated_at=?
            WHERE expense_id=?
        ")->execute([
            $data['expense_type_id'],
            $data['employee'],
            $data['amount'],
            $receiptPath,
            $receiptMime,
            $receiptName,
            $data['remark'],
            $data['expense_date'],
            $data['period_type'],
            appNow(),
            $id,
        ]);
        logActivity(
            'expenses',
            'update',
            "Updated expense #{$id}: amount changed from " . formatMoney((float)$expense['amount']) . " to " . formatMoney((float)$data['amount']) . "; type {$oldTypeName} to {$newTypeName}; date " . ($expense['expense_date'] ?? 'blank') . " to {$data['expense_date']}; period " . ($expense['period_type'] ?? 'blank') . " to {$data['period_type']}; payee " . ($expense['employee'] ?: 'not specified') . " to " . ($data['employee'] ?: 'not specified') . "; receipt " . ($expense['receipt_name'] ?: 'none') . " to " . ($receiptName ?: 'none') . "; remark " . ($expense['remark'] ?: 'none') . " to " . ($data['remark'] ?: 'none') . ".",
            null,
            [
                'expense_id' => $id,
                'expense_type' => $oldTypeName,
                'expense_type_id' => $expense['expense_type_id'],
                'payee' => $expense['employee'],
                'amount' => $expense['amount'],
                'expense_date' => $expense['expense_date'],
                'period_type' => $expense['period_type'],
                'receipt_name' => $expense['receipt_name'],
                'remark' => $expense['remark'],
            ],
            [
                'expense_id' => $id,
                'expense_type' => $newTypeName,
                'expense_type_id' => $data['expense_type_id'],
                'payee' => $data['employee'],
                'amount' => number_format($data['amount'], 2, '.', ''),
                'expense_date' => $data['expense_date'],
                'period_type' => $data['period_type'],
                'receipt_name' => $receiptName,
                'remark' => $data['remark'],
            ]
        );
        flashMessage('success', 'Expense updated.');
        redirect(BASE_URL . '/modules/expenses/');
    }
    // Merge POST back on error
    $expense = array_merge($expense, $data);
}

$pageTitle   = 'Edit Expense';
$breadcrumbs = [
    ['label' => 'Expenses', 'url' => BASE_URL . '/modules/expenses/'],
    ['label' => 'Edit Expense'],
];
include BASE_PATH . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <h4 class="fw-bold mb-0">Edit Expense <span class="text-muted fs-6 fw-normal">#<?= $id ?></span></h4>
    <a href="<?= BASE_URL ?>/modules/expenses/" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>

<?= inlineToasts($errors) ?>

<div class="row">
    <!-- ── Left: Form ──────────────────────────────────────── -->
    <div class="col-lg-7">
        <form method="POST" id="expenseForm" enctype="multipart/form-data" novalidate>
            <?= csrfField() ?>

            <!-- Expense Details -->
            <div class="card border-0 mb-4">
                <div class="card-header bg-transparent border-bottom fw-semibold py-3">
                    <i class="bi bi-receipt-cutoff me-2 text-primary"></i>Expense Details
                </div>
                <div class="card-body">
                    <div class="row g-3">

                        <div class="col-12">
                            <label class="form-label fw-medium" for="expType">
                                Expense Type
                                <?php if (hasMinRole(ROLE_ADMIN)): ?>
                                <a href="<?= BASE_URL ?>/modules/expenses/types" target="_blank"
                                   class="ms-1 small text-muted" title="Manage types">
                                    <i class="bi bi-plus-circle"></i>
                                </a>
                                <?php endif; ?>
                            </label>
                            <select name="expense_type_id" id="expType" class="form-select">
                                <option value="">— Select Expense Type —</option>
                                <?php foreach ($expenseTypes as $et): ?>
                                <option value="<?= $et['type_id'] ?>"
                                    <?= (int)$expense['expense_type_id'] === (int)$et['type_id'] ? 'selected' : '' ?>>
                                    <?= e($et['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="expDate">
                                Expense Date <span class="text-danger">*</span>
                            </label>
                            <input type="date" name="expense_date" id="expDate" class="form-control" required
                                   value="<?= e($expense['expense_date'] ?? date('Y-m-d')) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="periodType">
                                Period Type <span class="text-danger">*</span>
                            </label>
                            <select name="period_type" id="periodType" class="form-select" required>
                                <option value="daily"   <?= ($expense['period_type'] ?? 'daily') === 'daily'   ? 'selected' : '' ?>>Daily</option>
                                <option value="monthly" <?= ($expense['period_type'] ?? '')      === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                <option value="yearly"  <?= ($expense['period_type'] ?? '')      === 'yearly'  ? 'selected' : '' ?>>Yearly</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-medium" for="expAmount">
                                Amount (<?= e($currency) ?>) <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text fw-bold text-primary"><?= e($currency) ?></span>
                                <input type="number" name="amount" id="expAmount"
                                       class="form-control form-control-lg fw-bold"
                                       step="0.01" min="0.01" max="9999999" required
                                       value="<?= e($expense['amount'] ?? '') ?>"
                                       placeholder="0.00">
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- Employee & Receipt -->
            <div class="card border-0 mb-4">
                <div class="card-header bg-transparent border-bottom fw-semibold py-3">
                    <i class="bi bi-person-badge me-2 text-primary"></i>Employee &amp; Receipt
                </div>
                <div class="card-body">
                    <div class="row g-3">

                        <div class="col-12">
                            <label class="form-label fw-medium" for="expEmployee">
                                Employee / Payee
                                <small class="text-muted fw-normal">(optional)</small>
                            </label>
                            <input type="text" name="employee" id="expEmployee" class="form-control"
                                   placeholder="Full name of employee or payee"
                                   value="<?= e($expense['employee'] ?? '') ?>">
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-medium" for="expReceipt">
                                Receipt
                                <small class="text-muted fw-normal">(optional · JPG, PNG, PDF · max 5 MB)</small>
                            </label>

                            <?php if ($expense['receipt']): ?>
                            <div class="mb-3 p-3 bg-light rounded border">
                                <div class="d-flex align-items-center gap-3 flex-wrap">
                                    <?php if ($expense['receipt_mime'] && str_starts_with($expense['receipt_mime'], 'image/')): ?>
                                    <a href="<?= BASE_URL ?>/modules/expenses/receipt?id=<?= $id ?>" target="_blank">
                                        <img src="<?= BASE_URL ?>/modules/expenses/receipt?id=<?= $id ?>"
                                             alt="Current receipt" class="img-thumbnail" style="max-height:80px;">
                                    </a>
                                    <?php else: ?>
                                    <a href="<?= BASE_URL ?>/modules/expenses/receipt?id=<?= $id ?>" target="_blank"
                                       class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-file-earmark-pdf text-danger me-1"></i>View Current Receipt
                                    </a>
                                    <?php endif; ?>
                                    <div>
                                        <div class="small text-muted"><?= e($expense['receipt_name'] ?? 'receipt') ?></div>
                                        <div class="form-check mt-1">
                                            <input class="form-check-input" type="checkbox"
                                                   name="clear_receipt" id="clearReceipt" value="1">
                                            <label class="form-check-label small text-danger" for="clearReceipt">
                                                Remove current receipt
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <input type="file" name="receipt" id="expReceipt" class="form-control"
                                   accept="image/jpeg,image/png,image/gif,image/webp,application/pdf">
                            <?php if ($expense['receipt']): ?>
                            <div class="form-text">Upload a new file to replace the current receipt.</div>
                            <?php endif; ?>

                            <div id="receiptPreview" class="mt-2 d-none">
                                <img id="receiptImg" src="" alt="Preview"
                                     class="img-thumbnail d-none" style="max-height:140px;">
                                <div id="receiptPdfLabel" class="d-none small text-muted mt-1">
                                    <i class="bi bi-file-earmark-pdf text-danger me-1"></i>PDF selected
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- Remark -->
            <div class="card border-0 mb-4">
                <div class="card-header bg-transparent border-bottom fw-semibold py-3">
                    <i class="bi bi-chat-left-text me-2 text-primary"></i>Remark
                </div>
                <div class="card-body">
                    <textarea name="remark" id="expRemark" class="form-control" rows="3"
                              placeholder="Optional notes about this expense"><?= e($expense['remark'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="bi bi-check-lg me-2"></i>Save Changes
                </button>
                <a href="<?= BASE_URL ?>/modules/expenses/" class="btn btn-outline-secondary btn-lg">Cancel</a>
            </div>
        </form>
    </div>

    <!-- ── Right: Info panel ───────────────────────────────── -->
    <div class="col-lg-5">
        <div class="card border-0 sticky-top" style="top:80px;">
            <div class="card-header bg-primary text-white py-3">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-receipt me-2"></i>Expense Summary</h6>
            </div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted">Type</dt>
                    <dd class="col-7 fw-medium" id="sumType">
                        <?= $expense['type_name'] ? e($expense['type_name']) : '—' ?>
                    </dd>

                    <dt class="col-5 text-muted">Period</dt>
                    <dd class="col-7" id="sumPeriod">
                        <?= ucfirst($expense['period_type'] ?? '—') ?>
                    </dd>

                    <dt class="col-5 text-muted">Date</dt>
                    <dd class="col-7" id="sumDate">
                        <?= e($expense['expense_date'] ?? '—') ?>
                    </dd>

                    <dt class="col-5 text-muted">Employee</dt>
                    <dd class="col-7" id="sumEmployee">
                        <?= $expense['employee'] ? e($expense['employee']) : '—' ?>
                    </dd>

                    <dt class="col-5 text-muted">Receipt</dt>
                    <dd class="col-7">
                        <?php if ($expense['receipt']): ?>
                        <a href="<?= BASE_URL ?>/modules/expenses/receipt?id=<?= $id ?>"
                           target="_blank" class="small">View</a>
                        <?php else: ?>
                        <span class="text-muted" id="sumReceipt">—</span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-5 text-muted">Remark</dt>
                    <dd class="col-7 text-muted" id="sumRemark">
                        <?php $r = $expense['remark'] ?? ''; ?>
                        <?= $r ? e(mb_strimwidth($r, 0, 50, '…')) : '—' ?>
                    </dd>

                    <dt class="col-5 text-muted">Created</dt>
                    <dd class="col-7 text-muted small">
                        <?= formatDate($expense['created_at'], 'M d, Y h:i A') ?>
                    </dd>

                    <?php if ($expense['updated_at']): ?>
                    <dt class="col-5 text-muted">Updated</dt>
                    <dd class="col-7 text-muted small">
                        <?= formatDate($expense['updated_at'], 'M d, Y h:i A') ?>
                    </dd>
                    <?php endif; ?>
                </dl>
                <hr>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">Amount</span>
                    <span class="fs-5 fw-bold text-primary" id="sumAmount">
                        <?= e($currency) ?><?= number_format((float)($expense['amount'] ?? 0), 2) ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$sym = json_encode($currency);
$extraScripts = <<<JS
<script>
(function () {
const sym = {$sym};
function fmt(v) {
    return sym + parseFloat(v || 0).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
}
function updateSummary() {
    const type     = document.getElementById('expType');
    const period   = document.getElementById('periodType');
    const date     = document.getElementById('expDate');
    const employee = document.getElementById('expEmployee');
    const amount   = document.getElementById('expAmount');
    const remark   = document.getElementById('expRemark');
    const file     = document.getElementById('expReceipt');

    const typeEl = document.getElementById('sumType');
    if (typeEl) typeEl.textContent = type?.options[type.selectedIndex]?.text?.replace('— Select Expense Type —','—') || '—';

    const perEl = document.getElementById('sumPeriod');
    if (perEl && period) perEl.textContent = period.value.charAt(0).toUpperCase() + period.value.slice(1);

    const dateEl = document.getElementById('sumDate');
    if (dateEl) dateEl.textContent = date?.value || '—';

    const empEl = document.getElementById('sumEmployee');
    if (empEl) empEl.textContent = employee?.value || '—';

    const amtEl = document.getElementById('sumAmount');
    if (amtEl) amtEl.textContent = fmt(amount?.value);

    const rmEl = document.getElementById('sumRemark');
    if (rmEl) {
        const rm = remark?.value?.trim();
        rmEl.textContent = rm ? (rm.length > 50 ? rm.slice(0,50) + '…' : rm) : '—';
    }

    const rcEl = document.getElementById('sumReceipt');
    if (rcEl && file?.files[0]) rcEl.textContent = file.files[0].name;
}

['expType','periodType','expDate','expEmployee','expAmount','expRemark'].forEach(id => {
    document.getElementById(id)?.addEventListener('input',  updateSummary);
    document.getElementById(id)?.addEventListener('change', updateSummary);
});

document.getElementById('expReceipt')?.addEventListener('change', function () {
    updateSummary();
    const preview  = document.getElementById('receiptPreview');
    const img      = document.getElementById('receiptImg');
    const pdfLabel = document.getElementById('receiptPdfLabel');
    const file     = this.files[0];
    if (!file) { preview.classList.add('d-none'); return; }
    preview.classList.remove('d-none');
    if (file.type.startsWith('image/')) {
        img.classList.remove('d-none');
        pdfLabel.classList.add('d-none');
        const reader = new FileReader();
        reader.onload = e => { img.src = e.target.result; };
        reader.readAsDataURL(file);
    } else {
        img.classList.add('d-none');
        pdfLabel.classList.remove('d-none');
    }
});
})();
</script>
JS;
include BASE_PATH . '/includes/footer.php';
?>
