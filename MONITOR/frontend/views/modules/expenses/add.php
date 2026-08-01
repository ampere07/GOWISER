<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireRole(ROLE_CASHIER, ROLE_ADMIN, ROLE_SUPERADMIN);
if (!in_array(currentRole(), MODULE_ACCESS['expenses'])) forbidden();

if (!selectedRouterId() && currentRole() !== ROLE_SUPERADMIN) {
    $router = defaultRouterForUser(currentUser() ?? []);
    if ($router) setSelectedRouter((int)$router['router_id'], $router['name']);
}

$errors       = [];
$expenseTypes = db()->query("SELECT * FROM expense_types WHERE is_active = 1 ORDER BY name")->fetchAll();
$currency     = getSetting('currency_symbol', '₱');
$routerId     = (int)(selectedRouterId() ?: 0);

$expenseUsers = [];
if ($routerId) {
    $uStmt = db()->prepare("
        SELECT user_id, firstname, lastname, username, role, router_id
        FROM users
        WHERE is_active = 1
          AND (
              router_id = ?
              OR (role = ? AND (router_id IS NULL OR router_id = 0))
          )
        ORDER BY role = ? DESC, firstname, lastname
    ");
    $uStmt->execute([$routerId, ROLE_SUPERADMIN, ROLE_SUPERADMIN]);
    $expenseUsers = $uStmt->fetchAll();
}

// Defaults
$post = $_POST ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $data = [
        'expense_type_id' => (int)($post['expense_type_id'] ?? 0) ?: null,
        'expense_user_id' => (int)($post['expense_user_id'] ?? 0) ?: null,
        'employee'        => trim($post['employee']     ?? '') ?: null,
        'amount'          => (float)($post['amount']    ?? 0),
        'remark'          => trim($post['remark']       ?? '') ?: null,
        'expense_date'    => trim($post['expense_date'] ?? ''),
        'period_type'     => $post['period_type'] ?? 'daily',
    ];

    if ($data['amount'] <= 0)         $errors[] = 'Amount must be greater than 0.';
    if ($data['amount'] > 9999999)    $errors[] = 'Amount exceeds maximum allowed.';
    if (empty($data['expense_date'])) $errors[] = 'Expense date is required.';
    if (!in_array($data['period_type'], ['daily','monthly','yearly'])) $data['period_type'] = 'daily';
    if (!$routerId) $errors[] = 'Please select a router before recording an expense.';

    if ($data['expense_user_id']) {
        $userStmt = db()->prepare("
            SELECT user_id, firstname, lastname
            FROM users
            WHERE user_id = ?
              AND is_active = 1
              AND (
                  router_id = ?
                  OR (role = ? AND (router_id IS NULL OR router_id = 0))
              )
            LIMIT 1
        ");
        $userStmt->execute([$data['expense_user_id'], $routerId, ROLE_SUPERADMIN]);
        $selectedExpenseUser = $userStmt->fetch();
        if (!$selectedExpenseUser) {
            $errors[] = 'Selected user is not available for this router.';
        } elseif ($data['employee'] === null) {
            $data['employee'] = trim(($selectedExpenseUser['firstname'] ?? '') . ' ' . ($selectedExpenseUser['lastname'] ?? '')) ?: null;
        }
    }

    // Receipt upload
    $receiptPath = null; $receiptMime = null; $receiptName = null;

    if (!empty($_FILES['receipt']['name'])) {
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
        db()->prepare("
            INSERT INTO expenses
                (expense_type_id, employee, amount, receipt, receipt_mime, receipt_name,
                 remark, expense_date, period_type, router_id, expense_user_id, user_id, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
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
            $routerId ?: null,
            $data['expense_user_id'],
            currentUserId(),
            appNow(),
        ]);
        $typeName = null;
        if ($data['expense_type_id']) {
            foreach ($expenseTypes as $typeRow) {
                if ((int)$typeRow['type_id'] === (int)$data['expense_type_id']) {
                    $typeName = $typeRow['name'];
                    break;
                }
            }
        }
        logActivity(
            'expenses',
            'create',
            "Recorded new expense for router #" . ($routerId ?: 'none') . ": amount {$currency}" . number_format($data['amount'], 2) . ", date {$data['expense_date']}, period {$data['period_type']}, type " . ($typeName ?: 'Uncategorized') . ", payee " . ($data['employee'] ?: 'not specified') . ", receipt " . ($receiptName ?: 'none') . ", remark " . ($data['remark'] ?: 'none') . ".",
            null,
            null,
            [
                'expense_type' => $typeName ?: 'Uncategorized',
                'expense_type_id' => $data['expense_type_id'],
                'router_id' => $routerId ?: null,
                'selected_user_id' => $data['expense_user_id'],
                'payee' => $data['employee'],
                'amount' => number_format($data['amount'], 2, '.', ''),
                'expense_date' => $data['expense_date'],
                'period_type' => $data['period_type'],
                'receipt_name' => $receiptName,
                'remark' => $data['remark'],
            ]
        );
        flashMessage('success', 'Expense recorded successfully.');
        redirect(BASE_URL . '/modules/expenses/');
    }
}

$pageTitle   = 'New Expense';
$breadcrumbs = [
    ['label' => 'Expenses', 'url' => BASE_URL . '/modules/expenses/'],
    ['label' => 'New Expense'],
];
$extraHead = '
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
<style>
.select2-container .select2-selection--single { height: 38px; border-color: var(--bs-border-color); }
.select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 36px; color: var(--bs-body-color); }
.select2-container--default .select2-selection--single .select2-selection__arrow { height: 36px; }
[data-bs-theme="dark"] .select2-container--default .select2-selection--single,
[data-bs-theme="dark"] .select2-dropdown { background-color: var(--bs-body-bg); color: var(--bs-body-color); border-color: var(--bs-border-color); }
[data-bs-theme="dark"] .select2-container--default .select2-results__option--selected { background-color: var(--bs-secondary-bg); }
</style>';
include BASE_PATH . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <h4 class="fw-bold mb-0">New Expense</h4>
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
                                    <?= (int)($post['expense_type_id'] ?? 0) === (int)$et['type_id'] ? 'selected' : '' ?>>
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
                                   value="<?= e($post['expense_date'] ?? date('Y-m-d')) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="periodType">
                                Period Type <span class="text-danger">*</span>
                            </label>
                            <select name="period_type" id="periodType" class="form-select" required>
                                <option value="daily"   <?= ($post['period_type'] ?? 'daily') === 'daily'   ? 'selected' : '' ?>>Daily</option>
                                <option value="monthly" <?= ($post['period_type'] ?? '')      === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                <option value="yearly"  <?= ($post['period_type'] ?? '')      === 'yearly'  ? 'selected' : '' ?>>Yearly</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-medium d-flex align-items-center gap-2" for="expAmount">
                                Amount (<?= e($currency) ?>) <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text fw-bold text-primary"><?= e($currency) ?></span>
                                <input type="number" name="amount" id="expAmount"
                                       class="form-control form-control-lg fw-bold"
                                       step="0.01" min="0.01" max="9999999"
                                       value="<?= e($post['amount'] ?? '') ?>"
                                       placeholder="0.00" required>
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
                            <label class="form-label fw-medium" for="expenseUserSelect">
                                Select User
                                <small class="text-muted fw-normal">(optional)</small>
                            </label>
                            <select name="expense_user_id" id="expenseUserSelect" class="form-select">
                                <option value="">— Search user —</option>
                                <?php foreach ($expenseUsers as $user): ?>
                                <?php
                                    $fullName = trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? ''));
                                    $roleLabel = ROLES[$user['role']] ?? ucfirst($user['role']);
                                ?>
                                <option value="<?= (int)$user['user_id'] ?>"
                                        data-full-name="<?= e($fullName) ?>"
                                        <?= (int)($post['expense_user_id'] ?? 0) === (int)$user['user_id'] ? 'selected' : '' ?>>
                                    <?= e($fullName ?: $user['username']) ?> — <?= e($roleLabel) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Shows active users for the selected router, plus Super Administrators without a router assignment.</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-medium" for="expEmployee">
                                Employee / Payee
                                <small class="text-muted fw-normal">(optional)</small>
                            </label>
                            <input type="text" name="employee" id="expEmployee" class="form-control"
                                   placeholder="Full name of employee or payee"
                                   value="<?= e($post['employee'] ?? '') ?>">
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-medium" for="expReceipt">
                                Receipt
                                <small class="text-muted fw-normal">(optional · JPG, PNG, PDF · max 5 MB)</small>
                            </label>
                            <input type="file" name="receipt" id="expReceipt" class="form-control"
                                   accept="image/jpeg,image/png,image/gif,image/webp,application/pdf">
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
                              placeholder="Optional notes about this expense"><?= e($post['remark'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="bi bi-check-lg me-2"></i>Save Expense
                </button>
                <a href="<?= BASE_URL ?>/modules/expenses/" class="btn btn-outline-secondary btn-lg">Cancel</a>
            </div>
        </form>
    </div>

    <!-- ── Right: Summary panel ────────────────────────────── -->
    <div class="col-lg-5">
        <div class="card border-0 sticky-top" style="top:80px;">
            <div class="card-header bg-primary text-white py-3">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-receipt me-2"></i>Expense Summary</h6>
            </div>
            <div class="card-body" id="expenseSummary">
                <dl class="row mb-0 small" id="summaryList">
                    <dt class="col-5 text-muted">Type</dt>
                    <dd class="col-7 fw-medium" id="sumType">—</dd>

                    <dt class="col-5 text-muted">Period</dt>
                    <dd class="col-7" id="sumPeriod">—</dd>

                    <dt class="col-5 text-muted">Date</dt>
                    <dd class="col-7" id="sumDate">—</dd>

                    <dt class="col-5 text-muted">User</dt>
                    <dd class="col-7" id="sumUser">—</dd>

                    <dt class="col-5 text-muted">Employee</dt>
                    <dd class="col-7" id="sumEmployee">—</dd>

                    <dt class="col-5 text-muted">Receipt</dt>
                    <dd class="col-7" id="sumReceipt">—</dd>

                    <dt class="col-5 text-muted">Remark</dt>
                    <dd class="col-7 text-muted" id="sumRemark">—</dd>
                </dl>
                <hr>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">Total Amount</span>
                    <span class="fs-5 fw-bold text-primary" id="sumAmount"><?= e($currency) ?>0.00</span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$sym = json_encode($currency);
$extraScripts = <<<JS
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
(function () {
const sym = {$sym};
const userSelect = document.getElementById('expenseUserSelect');
function applySelectedUser() {
    const employee = document.getElementById('expEmployee');
    const selected = userSelect?.options[userSelect.selectedIndex];
    if (employee && !employee.value.trim() && selected?.dataset.fullName) {
        employee.value = selected.dataset.fullName;
    }
    updateSummary();
}
if (window.jQuery && jQuery.fn.select2 && userSelect) {
    jQuery(userSelect).select2({
        width: '100%',
        placeholder: 'Search user',
        allowClear: true
    }).on('change', applySelectedUser);
}
function fmt(v) {
    return sym + parseFloat(v || 0).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
}
function updateSummary() {
    const type     = document.getElementById('expType');
    const period   = document.getElementById('periodType');
    const date     = document.getElementById('expDate');
    const user     = document.getElementById('expenseUserSelect');
    const employee = document.getElementById('expEmployee');
    const amount   = document.getElementById('expAmount');
    const remark   = document.getElementById('expRemark');
    const file     = document.getElementById('expReceipt');

    document.getElementById('sumType').textContent     = type?.options[type.selectedIndex]?.text?.replace('— Select Expense Type —','—') || '—';
    document.getElementById('sumPeriod').textContent   = period ? (period.value.charAt(0).toUpperCase() + period.value.slice(1)) : '—';
    document.getElementById('sumDate').textContent     = date?.value || '—';
    document.getElementById('sumUser').textContent     = user?.options[user.selectedIndex]?.text?.replace('— Search user —','—') || '—';
    document.getElementById('sumEmployee').textContent = employee?.value || '—';
    document.getElementById('sumReceipt').textContent  = file?.files[0]?.name || '—';
    document.getElementById('sumAmount').textContent   = fmt(amount?.value);
    const rm = remark?.value?.trim();
    document.getElementById('sumRemark').textContent   = rm ? (rm.length > 40 ? rm.slice(0,40) + '…' : rm) : '—';
}

['expType','periodType','expDate','expenseUserSelect','expEmployee','expAmount','expRemark'].forEach(id => {
    document.getElementById(id)?.addEventListener('input',  updateSummary);
    document.getElementById(id)?.addEventListener('change', updateSummary);
});
userSelect?.addEventListener('change', applySelectedUser);
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

updateSummary();
})();
</script>
JS;
include BASE_PATH . '/includes/footer.php';
?>
