<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireLogin();

if (currentRole() !== ROLE_SUPERADMIN) {
    flashMessage('danger', 'Access denied.');
    redirect(BASE_URL . '/modules/dashboard/');
}

$errors  = [];
$success = false;
$tab     = $_GET['tab'] ?? 'company';

// ── Handle per-router payment portal activation ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['portal_action'])) {
    verifyCsrf();
    $portalAction = $_POST['portal_action'];
    if ($portalAction === 'toggle_router') {
        $routerId = (int)($_POST['router_id'] ?? 0);
        $row = db()->prepare("SELECT name, status, portal_enabled FROM routers WHERE router_id = ?");
        $row->execute([$routerId]);
        $router = $row->fetch();
        if (!$router) {
            flashMessage('danger', 'Router not found.');
        } elseif (($router['status'] ?? '') !== ROUTER_ONLINE) {
            flashMessage('danger', 'Payment portal can only be activated for connected routers.');
        } else {
            $enabled = !empty($router['portal_enabled']) ? 0 : 1;
            db()->prepare("UPDATE routers SET portal_enabled = ?, updated_at = ? WHERE router_id = ?")
                ->execute([$enabled, appNow(), $routerId]);
            logActivity('settings', 'update', "Payment portal " . ($enabled ? 'activated' : 'deactivated') . " for router: {$router['name']}");
            flashMessage('success', 'Router payment portal access updated.');
        }
    }
    redirect(BASE_URL . '/modules/settings/?tab=payments');
}

// ── Handle payment method actions ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pm_action'])) {
    verifyCsrf();
    $pmAction = $_POST['pm_action'];

    if ($pmAction === 'add') {
        $pmName   = trim($_POST['pm_name'] ?? '');
        $pmCode   = strtolower(preg_replace('/[^a-z0-9_]/i', '_', trim($_POST['pm_code'] ?? '')));
        $pmRemark = trim($_POST['pm_remark'] ?? '');
        if ($pmName && $pmCode) {
            try {
                getPaymentMethods();
                $max = (int)db()->query("SELECT COALESCE(MAX(sort_order),0) FROM payment_methods")->fetchColumn();
                db()->prepare("INSERT IGNORE INTO payment_methods (name, code, sort_order, status, remark) VALUES (?, ?, ?, 'active', ?)")
                     ->execute([$pmName, $pmCode, $max + 1, $pmRemark ?: null]);
                logActivity('settings', 'create', "Added payment method: {$pmName} (code: {$pmCode})");
                flashMessage('success', "Payment method '{$pmName}' added.");
            } catch (PDOException $e) {
                flashMessage('danger', 'Code already exists or error occurred.');
            }
        }
    } elseif ($pmAction === 'edit') {
        $pmId     = (int)$_POST['pm_id'];
        $pmName   = trim($_POST['pm_name'] ?? '');
        $pmStatus = in_array($_POST['pm_status'] ?? '', ['active','maintenance','unavailable']) ? $_POST['pm_status'] : 'active';
        $pmRemark = trim($_POST['pm_remark'] ?? '');
        if ($pmId && $pmName) {
            db()->prepare("UPDATE payment_methods SET name = ?, status = ?, remark = ? WHERE pm_id = ?")
                 ->execute([$pmName, $pmStatus, $pmRemark ?: null, $pmId]);
            logActivity('settings', 'update', "Updated payment method #{$pmId}: {$pmName} (status: {$pmStatus})");
            flashMessage('success', "Payment method updated.");
        }
    } elseif ($pmAction === 'toggle') {
        $pmId  = (int)$_POST['pm_id'];
        $pmRow = db()->prepare("SELECT name, is_active FROM payment_methods WHERE pm_id = ?");
        $pmRow->execute([$pmId]);
        $pmRow = $pmRow->fetch();
        db()->prepare("UPDATE payment_methods SET is_active = NOT is_active WHERE pm_id = ?")->execute([$pmId]);
        $newState = $pmRow ? ($pmRow['is_active'] ? 'deactivated' : 'activated') : 'toggled';
        logActivity('settings', 'update', "Payment method " . ($pmRow ? "'{$pmRow['name']}'" : "#{$pmId}") . " {$newState}");
        flashMessage('success', 'Payment method updated.');
    } elseif ($pmAction === 'delete') {
        $pmId  = (int)$_POST['pm_id'];
        $pmRow = db()->prepare("SELECT name FROM payment_methods WHERE pm_id = ?");
        $pmRow->execute([$pmId]);
        $pmRow = $pmRow->fetch();
        db()->prepare("DELETE FROM payment_methods WHERE pm_id = ?")->execute([$pmId]);
        logActivity('settings', 'delete', "Deleted payment method: " . ($pmRow ? "'{$pmRow['name']}'" : "#{$pmId}"));
        flashMessage('success', 'Payment method deleted.');
    }

    redirect(BASE_URL . '/modules/settings/?tab=payments');
}

// ── Handle payment type actions ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pt_action'])) {
    verifyCsrf();
    getPaymentTypes();
    $ptAction = $_POST['pt_action'];

    if ($ptAction === 'add') {
        $ptName = titleCase(trim($_POST['pt_name'] ?? ''));
        $ptDesc = trim($_POST['pt_desc'] ?? '');
        if ($ptName) {
            $max = (int)db()->query("SELECT COALESCE(MAX(sort_order),0) FROM payment_types")->fetchColumn();
            db()->prepare("INSERT INTO payment_types (name, description, sort_order) VALUES (?,?,?)")
                 ->execute([$ptName, $ptDesc ?: null, $max + 1]);
            logActivity('settings', 'create', "Added payment type: {$ptName}");
            flashMessage('success', "Payment type '{$ptName}' added.");
        }
    } elseif ($ptAction === 'toggle') {
        $ptId  = (int)$_POST['pt_id'];
        $ptRow = db()->prepare("SELECT name, is_active FROM payment_types WHERE type_id = ?");
        $ptRow->execute([$ptId]);
        $ptRow = $ptRow->fetch();
        db()->prepare("UPDATE payment_types SET is_active = NOT is_active WHERE type_id = ? AND is_default = 0")
             ->execute([$ptId]);
        $newState = $ptRow ? ($ptRow['is_active'] ? 'deactivated' : 'activated') : 'toggled';
        logActivity('settings', 'update', "Payment type " . ($ptRow ? "'{$ptRow['name']}'" : "#{$ptId}") . " {$newState}");
        flashMessage('success', 'Payment type updated.');
    } elseif ($ptAction === 'delete') {
        $ptId  = (int)$_POST['pt_id'];
        $ptRow = db()->prepare("SELECT name FROM payment_types WHERE type_id = ?");
        $ptRow->execute([$ptId]);
        $ptRow = $ptRow->fetch();
        $used  = (int)db()->query("SELECT COUNT(*) FROM payments WHERE payment_type_id = {$ptId}")->fetchColumn();
        if ($used > 0) {
            flashMessage('danger', 'Cannot delete: type is used by existing payments.');
        } else {
            db()->prepare("DELETE FROM payment_types WHERE type_id = ? AND is_default = 0")->execute([$ptId]);
            logActivity('settings', 'delete', "Deleted payment type: " . ($ptRow ? "'{$ptRow['name']}'" : "#{$ptId}"));
            flashMessage('success', 'Payment type deleted.');
        }
    }
    redirect(BASE_URL . '/modules/settings/?tab=payment_types');
}

// ── Handle expense type actions ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['et_action'])) {
    verifyCsrf();
    $etAction = $_POST['et_action'];

    if ($etAction === 'add') {
        $etName = trim($_POST['et_name'] ?? '');
        $etDesc = trim($_POST['et_desc'] ?? '') ?: null;
        if ($etName) {
            $ck = db()->prepare("SELECT 1 FROM expense_types WHERE name = ?");
            $ck->execute([$etName]);
            if ($ck->fetchColumn()) {
                flashMessage('danger', 'An expense type with this name already exists.');
            } else {
                db()->prepare("INSERT INTO expense_types (name, description) VALUES (?,?)")
                    ->execute([$etName, $etDesc]);
                logActivity('settings', 'create', "Created expense type: {$etName}");
                flashMessage('success', "Type '{$etName}' added.");
            }
        }
    } elseif ($etAction === 'toggle') {
        $etId = (int)($_POST['et_id'] ?? 0);
        if ($etId) {
            $row = db()->prepare("SELECT is_active FROM expense_types WHERE type_id = ?");
            $row->execute([$etId]);
            $row = $row->fetch();
            if ($row) {
                $new = $row['is_active'] ? 0 : 1;
                db()->prepare("UPDATE expense_types SET is_active = ? WHERE type_id = ?")
                    ->execute([$new, $etId]);
                logActivity('settings', 'update', "Expense type #{$etId} " . ($new ? 'activated' : 'deactivated'));
                flashMessage('success', 'Type status updated.');
            }
        }
    } elseif ($etAction === 'edit') {
        $etId   = (int)($_POST['et_id'] ?? 0);
        $etName = trim($_POST['et_name'] ?? '');
        $etDesc = trim($_POST['et_desc'] ?? '') ?: null;
        if ($etId && $etName) {
            $ck = db()->prepare("SELECT 1 FROM expense_types WHERE name = ? AND type_id != ?");
            $ck->execute([$etName, $etId]);
            if ($ck->fetchColumn()) {
                flashMessage('danger', 'Name already in use by another type.');
            } else {
                db()->prepare("UPDATE expense_types SET name=?, description=? WHERE type_id=?")
                    ->execute([$etName, $etDesc, $etId]);
                logActivity('settings', 'update', "Updated expense type ID {$etId}: {$etName}");
                flashMessage('success', 'Type updated.');
            }
        }
    } elseif ($etAction === 'delete') {
        $etId = (int)($_POST['et_id'] ?? 0);
        if ($etId) {
            $used = db()->prepare("SELECT COUNT(*) FROM expenses WHERE expense_type_id = ?");
            $used->execute([$etId]);
            if ((int)$used->fetchColumn() > 0) {
                flashMessage('danger', 'Cannot delete: type is used by existing expenses. Deactivate it instead.');
            } else {
                db()->prepare("DELETE FROM expense_types WHERE type_id = ?")->execute([$etId]);
                logActivity('settings', 'delete', "Deleted expense type ID {$etId}");
                flashMessage('success', 'Type deleted.');
            }
        }
    }
    redirect(BASE_URL . '/modules/settings/?tab=expense_types');
}

// ── Handle tax settings ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_tax'])) {
    verifyCsrf();
    $taxEnabled = isset($_POST['tax_enabled']) ? '1' : '0';
    $taxName    = trim($_POST['tax_name'] ?? 'VAT');
    $taxRate    = (string)(float)($_POST['tax_rate'] ?? 0);
    $taxTypePost = $_POST['tax_type'] ?? '';
    $taxType    = in_array($taxTypePost, ['included','excluded'], true) ? $taxTypePost : 'excluded';
    saveSetting('tax_enabled', $taxEnabled);
    saveSetting('tax_name',    $taxName);
    saveSetting('tax_rate',    $taxRate);
    saveSetting('tax_type',    $taxType);
    logActivity('settings', 'update', "Tax settings updated — " . ($taxEnabled === '1' ? "{$taxName} @ {$taxRate}% ({$taxType})" : "disabled"));
    flashMessage('success', 'Tax settings saved.');
    redirect(BASE_URL . '/modules/settings/?tab=payments');
}

// ── Handle terms and conditions ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_terms'])) {
    verifyCsrf();
    $terms = trim($_POST['terms_conditions'] ?? '');
    $oldTerms = getSetting('terms_conditions', '');
    saveSetting('terms_conditions', $terms);
    logActivity(
        'settings',
        'update',
        $oldTerms === $terms ? 'Terms and Conditions saved (no changes)' : 'Terms and Conditions updated'
    );
    flashMessage('success', 'Terms and Conditions saved.');
    redirect(BASE_URL . '/modules/settings/?tab=terms');
}

// ── Handle logo upload / removal ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_logo'])) {
    verifyCsrf();
    if (isset($_POST['remove_logo'])) {
        saveSetting('company_logo', '');
        logActivity('settings', 'delete', 'Company logo removed');
        flashMessage('success', 'Logo removed.');
        redirect(BASE_URL . '/modules/settings/?tab=company');
    }
    if (!empty($_FILES['company_logo']['name'])) {
        $file    = $_FILES['company_logo'];
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $mime    = mime_content_type($file['tmp_name']);
        if (!in_array($mime, $allowed)) {
            $errors[] = 'Invalid file type. Allowed: JPG, PNG, GIF, WebP.';
        } elseif ($file['size'] > 2 * 1024 * 1024) {
            $errors[] = 'File too large. Maximum 2 MB.';
        } else {
            $dataUri = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($file['tmp_name']));
            saveSetting('company_logo', $dataUri);
            logActivity('settings', 'update', 'Company logo uploaded (' . $mime . ', ' . round($file['size'] / 1024) . ' KB)');
            flashMessage('success', 'Logo updated.');
            redirect(BASE_URL . '/modules/settings/?tab=company');
        }
    }
}

// ── Handle main settings form ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    verifyCsrf();
    $fields = ['company_name','company_desc','portal_name','company_address','company_latitude','company_longitude','company_tin',
               'company_sec','company_bp','company_email','company_contact',
               'timezone','currency_symbol'];
    $old = getSettings();
    if (array_key_exists('company_name', $_POST) && empty(trim($_POST['company_name'] ?? ''))) {
        $errors[] = 'Company Name is required.';
    }
    $tz = trim($_POST['timezone'] ?? '');
    if ($tz && !in_array($tz, DateTimeZone::listIdentifiers(), true)) {
        $errors[] = 'Invalid timezone selected.';
    }
    $email = trim($_POST['company_email'] ?? '');
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address.';
    }
    foreach ([
        'company_latitude'  => ['label' => 'Company latitude',  'min' => -90,  'max' => 90],
        'company_longitude' => ['label' => 'Company longitude', 'min' => -180, 'max' => 180],
    ] as $coordKey => $coord) {
        $coordVal = trim($_POST[$coordKey] ?? '');
        if ($coordVal === '') continue;
        if (!is_numeric($coordVal)) {
            $errors[] = $coord['label'] . ' must be a valid number.';
            continue;
        }
        $coordNum = (float)$coordVal;
        if ($coordNum < $coord['min'] || $coordNum > $coord['max']) {
            $errors[] = $coord['label'] . " must be between {$coord['min']} and {$coord['max']}.";
        }
    }
    if (empty($errors)) {
        $changed = [];
        foreach ($fields as $key) {
            $newVal = array_key_exists($key, $_POST) ? trim($_POST[$key] ?? '') : ($old[$key] ?? '');
            saveSetting($key, $newVal);
            if (($old[$key] ?? '') !== $newVal) $changed[] = $key;
        }
        $desc = empty($changed) ? 'Settings saved (no changes)' : 'Settings updated: ' . implode(', ', $changed);
        logActivity('settings', 'update', $desc);
        flashMessage('success', 'Settings saved successfully.');
        redirect(BASE_URL . '/modules/settings/?tab=' . $tab);
    }
}

$s = getSettings();

$tzGroups = [];
foreach (DateTimeZone::listIdentifiers() as $tz) {
    $parts = explode('/', $tz, 2);
    $tzGroups[$parts[0]][] = $tz;
}
ksort($tzGroups);

getPaymentMethods();
$allPmethods   = db()->query("SELECT * FROM payment_methods ORDER BY sort_order, name")->fetchAll();
$activePmCount = count(array_filter($allPmethods, fn($m) => $m['is_active']));
$portalRouters = db()->query("
    SELECT r.router_id, r.name, r.host, r.status, r.portal_enabled, COUNT(s.subscriber_id) AS subscriber_count
    FROM routers r
    LEFT JOIN subscribers s ON s.router_id = r.router_id
    GROUP BY r.router_id
    ORDER BY r.name
")->fetchAll();
getPaymentTypes();
$allPtypes     = db()->query("SELECT * FROM payment_types ORDER BY sort_order, name")->fetchAll();
$activePtCount = count(array_filter($allPtypes, fn($t) => $t['is_active']));
$allExpenseTypes = db()->query("SELECT et.*, (SELECT COUNT(*) FROM expenses e WHERE e.expense_type_id = et.type_id) AS usage_count FROM expense_types et ORDER BY et.name")->fetchAll();
$activeEtCount   = count(array_filter($allExpenseTypes, fn($t) => $t['is_active']));
$currentLogo   = getSetting('company_logo', '');

$pageTitle   = 'Settings';
$breadcrumbs = $tab === 'expense_types'
    ? [['label' => 'Settings', 'url' => BASE_URL . '/modules/settings/'], ['label' => 'Expense Types']]
    : [['label' => 'Settings']];

$extraHead = <<<CSS
<style>
.settings-nav .nav-link {
    color: var(--bs-body-color);
    border-radius: 8px;
    padding: .6rem 1rem;
    display: flex;
    align-items: center;
    gap: .65rem;
    font-size: .9rem;
    transition: background .15s, color .15s;
    border: none;
}
.settings-nav .nav-link:hover { background: var(--bs-light); }
.settings-nav .nav-link.active {
    background: var(--bs-primary);
    color: #fff !important;
    font-weight: 600;
}
.settings-nav .nav-link .nav-icon {
    width: 30px; height: 30px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 6px;
    background: rgba(255,255,255,.15);
    font-size: 1rem;
    flex-shrink: 0;
}
.settings-nav .nav-link:not(.active) .nav-icon {
    background: var(--bs-primary-bg-subtle);
    color: var(--bs-primary);
}
.settings-section { display: none; }
.settings-section.active { display: block; }
.logo-drop-zone {
    border: 2px dashed #dee2e6;
    border-radius: 12px;
    padding: 1.5rem;
    text-align: center;
    transition: border-color .2s, background .2s;
    cursor: pointer;
    background: #fafafa;
}
.logo-drop-zone:hover, .logo-drop-zone.drag-over {
    border-color: var(--bs-primary);
    background: var(--bs-primary-bg-subtle);
}
.pm-card {
    border: 1px solid #e9ecef;
    border-radius: 10px;
    padding: .85rem 1rem;
    display: flex;
    align-items: center;
    gap: .75rem;
    margin-bottom: .5rem;
    transition: box-shadow .15s;
    background: #fff;
}
.pm-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,.07); }
.pm-card.inactive { opacity: .6; }
.pm-icon {
    width: 38px; height: 38px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem;
    flex-shrink: 0;
}
.section-divider {
    font-size: .7rem;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--bs-secondary);
    margin: 1.5rem 0 .75rem;
    display: flex;
    align-items: center;
    gap: .5rem;
}
.section-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: #e9ecef;
}
.tax-preview {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9f5ec 100%);
    border: 1px solid #c3e6cb;
    border-radius: 10px;
    padding: 1rem 1.25rem;
}
</style>
CSS;

include BASE_PATH . '/includes/header.php';
?>

<!-- Page header -->
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">System Settings</h4>
        <p class="text-muted small mb-0">Super administrator · Configure company info, localization &amp; billing</p>
    </div>
    <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-2">
        <i class="bi bi-shield-lock me-1"></i>Superadmin Only
    </span>
</div>

<?= inlineToasts($errors) ?>

<div class="row g-4">

    <!-- ── Left sidebar nav ─────────────────────────────────────── -->
    <div class="col-lg-3">
        <div class="card border-0 shadow-sm sticky-top" style="top:80px;">
            <div class="card-body p-2">
                <nav class="settings-nav d-flex flex-column gap-1">

                    <a href="?tab=company" class="nav-link <?= $tab === 'company' ? 'active' : '' ?>">
                        <span class="nav-icon"><i class="bi bi-building"></i></span>
                        <div class="flex-grow-1">
                            <div>Company</div>
                            <div class="small opacity-75" style="font-size:.72rem;font-weight:400;">
                                <?= e($s['company_name'] ?? 'Not set') ?>
                            </div>
                        </div>
                    </a>

                    <a href="?tab=localization" class="nav-link <?= $tab === 'localization' ? 'active' : '' ?>">
                        <span class="nav-icon"><i class="bi bi-globe-asia-australia"></i></span>
                        <div class="flex-grow-1">
                            <div>Localization</div>
                            <div class="small opacity-75" style="font-size:.72rem;font-weight:400;">
                                <?= e($s['timezone'] ?? 'UTC') ?> · <?= e($s['currency_symbol'] ?? '₱') ?>
                            </div>
                        </div>
                    </a>

                    <a href="?tab=terms" class="nav-link <?= $tab === 'terms' ? 'active' : '' ?>">
                        <span class="nav-icon"><i class="bi bi-file-text"></i></span>
                        <div class="flex-grow-1">
                            <div>Terms</div>
                            <div class="small opacity-75" style="font-size:.72rem;font-weight:400;">
                                <?= trim($s['terms_conditions'] ?? '') !== '' ? 'Notice set' : 'Not set' ?>
                            </div>
                        </div>
                    </a>

                    <a href="?tab=payments" class="nav-link <?= $tab === 'payments' ? 'active' : '' ?>">
                        <span class="nav-icon"><i class="bi bi-credit-card-2-front"></i></span>
                        <div class="flex-grow-1">
                            <div>Payment Methods</div>
                            <div class="small opacity-75" style="font-size:.72rem;font-weight:400;">
                                <?= $activePmCount ?> active · Tax <?= ($s['tax_enabled'] ?? '0') === '1' ? 'on' : 'off' ?>
                            </div>
                        </div>
                    </a>

                    <a href="?tab=payment_types" class="nav-link <?= $tab === 'payment_types' ? 'active' : '' ?>">
                        <span class="nav-icon"><i class="bi bi-tags"></i></span>
                        <div class="flex-grow-1">
                            <div>Payment Types</div>
                            <div class="small opacity-75" style="font-size:.72rem;font-weight:400;">
                                <?= $activePtCount ?> active type<?= $activePtCount !== 1 ? 's' : '' ?>
                            </div>
                        </div>
                    </a>

                    <a href="?tab=expense_types" class="nav-link <?= $tab === 'expense_types' ? 'active' : '' ?>">
                        <span class="nav-icon"><i class="bi bi-receipt-cutoff"></i></span>
                        <div class="flex-grow-1">
                            <div>Expense Types</div>
                            <div class="small opacity-75" style="font-size:.72rem;font-weight:400;">
                                <?= $activeEtCount ?> active type<?= $activeEtCount !== 1 ? 's' : '' ?>
                            </div>
                        </div>
                    </a>

                </nav>
            </div>
        </div>
    </div>

    <!-- ── Main content ─────────────────────────────────────────── -->
    <div class="col-lg-9">

<!-- ════════════════════════════════════════════════════════════
     COMPANY TAB
════════════════════════════════════════════════════════════ -->
<?php if ($tab === 'company'): ?>

        <!-- Logo card -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent border-bottom py-3 d-flex align-items-center gap-2">
                <h6 class="mb-0 fw-semibold">Company Logo</h6>
                <?php if ($currentLogo): ?>
                <span class="badge bg-success-subtle text-success border border-success-subtle ms-auto">Logo set</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="row g-4 align-items-center">
                    <div class="col-auto">
                        <div id="logoPreviewWrap" style="width:110px;height:110px;border-radius:12px;overflow:hidden;background:#f1f3f5;display:flex;align-items:center;justify-content:center;box-shadow:inset 0 0 0 1px #dee2e6;">
                            <?php if ($currentLogo): ?>
                            <img id="logoPreview" src="<?= e($currentLogo) ?>" alt="Logo"
                                 style="max-width:100%;max-height:100%;object-fit:contain;">
                            <?php else: ?>
                            <i class="bi bi-image text-muted fs-1" id="logoPlaceholder"></i>
                            <img id="logoPreview" src="" alt="" style="max-width:100%;max-height:100%;object-fit:contain;display:none;">
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col">
                        <form method="POST" enctype="multipart/form-data">
                            <?= csrfField() ?>
                            <input type="hidden" name="save_logo" value="1">
                            <label class="logo-drop-zone d-block mb-3" id="logoDropZone" for="logoFileInput">
                                <i class="bi bi-cloud-upload fs-3 text-primary mb-2 d-block"></i>
                                <div class="fw-medium small">Drop image here or <span class="text-primary">browse</span></div>
                                <div class="text-muted" style="font-size:.75rem;">JPG, PNG, GIF, WebP — max 2 MB</div>
                                <input type="file" name="company_logo" id="logoFileInput"
                                       class="d-none" accept="image/jpeg,image/png,image/gif,image/webp">
                            </label>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="bi bi-upload me-1"></i>Upload
                                </button>
                                <?php if ($currentLogo): ?>
                                <button type="submit" name="remove_logo" value="1"
                                        class="btn btn-outline-danger btn-sm"
                                        onclick="return confirm('Remove the current logo?')">
                                    <i class="bi bi-trash me-1"></i>Remove
                                </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Company info form -->
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="save_settings" value="1">

            <!-- Basic Info -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom py-3 d-flex align-items-center gap-2">
                    <h6 class="mb-0 fw-semibold">Basic Information</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label fw-medium" for="fCompanyName">Company Name <span class="text-danger">*</span></label>
                            <input type="text" name="company_name" id="fCompanyName" class="form-control"
                                   value="<?= e($s['company_name'] ?? '') ?>" required maxlength="100"
                                   placeholder="e.g. ALSAM Internet Services">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-medium" for="fCompanyDesc">Short Description</label>
                            <input type="text" name="company_desc" id="fCompanyDesc" class="form-control"
                                   value="<?= e($s['company_desc'] ?? '') ?>" maxlength="200"
                                   placeholder="e.g. Your Trusted ISP Provider">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium" for="fCompanyAddress">Full Address</label>
                            <textarea name="company_address" id="fCompanyAddress" class="form-control" rows="2"
                                      maxlength="500" placeholder="Street, Barangay, Municipality, Province"><?= e($s['company_address'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="fCompanyLatitude">Latitude</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-geo-alt"></i></span>
                                <input type="number" name="company_latitude" id="fCompanyLatitude"
                                       class="form-control font-monospace" step="any" min="-90" max="90"
                                       value="<?= e($s['company_latitude'] ?? '') ?>"
                                       placeholder="e.g. 14.599512">
                            </div>
                            <div class="form-text">Company map coordinate, -90 to 90.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="fCompanyLongitude">Longitude</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-map"></i></span>
                                <input type="number" name="company_longitude" id="fCompanyLongitude"
                                       class="form-control font-monospace" step="any" min="-180" max="180"
                                       value="<?= e($s['company_longitude'] ?? '') ?>"
                                       placeholder="e.g. 120.984222">
                            </div>
                            <div class="form-text">Company map coordinate, -180 to 180.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contact -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom py-3 d-flex align-items-center gap-2">
                    <h6 class="mb-0 fw-semibold">Contact Details</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="fCompanyEmail">Email Address</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                <input type="email" name="company_email" id="fCompanyEmail" class="form-control"
                                       value="<?= e($s['company_email'] ?? '') ?>"
                                       maxlength="150" placeholder="info@company.com">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="fCompanyContact">Contact Number</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                                <input type="text" name="company_contact" id="fCompanyContact" class="form-control"
                                       value="<?= e($s['company_contact'] ?? '') ?>"
                                       maxlength="30" placeholder="09XX-XXX-XXXX">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Legal Registration -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom py-3 d-flex align-items-center gap-2">
                    <h6 class="mb-0 fw-semibold">Legal Registration</h6>
                    <span class="text-muted small ms-auto">Printed on official receipts</span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-medium" for="fCompanyTin">TIN</label>
                            <input type="text" name="company_tin" id="fCompanyTin" class="form-control font-monospace"
                                   value="<?= e($s['company_tin'] ?? '') ?>" maxlength="30"
                                   placeholder="000-000-000-000">
                            <div class="form-text">Tax Identification Number</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium" for="fCompanySec">SEC Reg. No.</label>
                            <input type="text" name="company_sec" id="fCompanySec" class="form-control font-monospace"
                                   value="<?= e($s['company_sec'] ?? '') ?>" maxlength="50"
                                   placeholder="CS201800001">
                            <div class="form-text">SEC Registration</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium" for="fCompanyBp">Business Permit No.</label>
                            <input type="text" name="company_bp" id="fCompanyBp" class="form-control font-monospace"
                                   value="<?= e($s['company_bp'] ?? '') ?>" maxlength="50"
                                   placeholder="BP-2024-00123">
                            <div class="form-text">Mayor's Permit</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Receipt preview card -->
            <div class="card border-0 shadow-sm mb-4" style="border-left:4px solid var(--bs-primary)!important;">
                <div class="card-header bg-transparent border-bottom py-3 d-flex align-items-center gap-2">
                    <h6 class="mb-0 fw-semibold">Receipt Header Preview</h6>
                    <span class="badge bg-info-subtle text-info ms-auto">Live</span>
                </div>
                <div class="card-body">
                    <div class="p-3 rounded" style="background:#f8f9fa;font-family:'Courier New',monospace;font-size:.82rem;line-height:1.6;text-align:center;">
                        <?php if ($currentLogo): ?>
                        <div class="mb-2"><img src="<?= e($currentLogo) ?>" style="max-height:50px;object-fit:contain;"></div>
                        <?php endif; ?>
                        <div class="fw-bold text-uppercase" id="prev_company_name" style="font-size:.95rem;"><?= e($s['company_name'] ?? 'Company Name') ?></div>
                        <div class="text-muted" id="prev_company_desc" style="font-size:.78rem;"><?= e($s['company_desc'] ?? '') ?></div>
                        <div id="prev_company_address" style="font-size:.76rem;white-space:pre-line;"><?= e($s['company_address'] ?? '') ?></div>
                        <?php if ($s['company_tin'] ?? ''): ?><div id="prev_company_tin" style="font-size:.74rem;">TIN: <?= e($s['company_tin']) ?></div><?php else: ?><div id="prev_company_tin" style="font-size:.74rem;"></div><?php endif; ?>
                        <div id="prev_company_contact" style="font-size:.74rem;"><?= e($s['company_contact'] ?? '') ?></div>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary px-4">
                    <i class="bi bi-check-circle me-2"></i>Save Company Info
                </button>
                <a href="?tab=company" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>

<!-- ════════════════════════════════════════════════════════════
     LOCALIZATION TAB
════════════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'localization'): ?>

        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="save_settings" value="1">
            <?php foreach (['company_name','company_desc','company_address','company_latitude','company_longitude','company_tin','company_sec','company_bp','company_email','company_contact'] as $f): ?>
            <input type="hidden" name="<?= $f ?>" value="<?= e($s[$f] ?? '') ?>">
            <?php endforeach; ?>

            <!-- Timezone -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom py-3 d-flex align-items-center gap-2">
                    <h6 class="mb-0 fw-semibold">Timezone</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-medium" for="tzSelect">System Timezone</label>
                            <select name="timezone" class="form-select" id="tzSelect">
                                <?php foreach ($tzGroups as $region => $zones): ?>
                                <optgroup label="<?= e($region) ?>">
                                    <?php foreach ($zones as $tz): ?>
                                    <option value="<?= e($tz) ?>" <?= ($s['timezone'] ?? '') === $tz ? 'selected' : '' ?>>
                                        <?= e($tz) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <p class="form-label fw-medium mb-1">Current Server Time</p>
                            <div class="form-control bg-light font-monospace small"
                                 style="height:auto;padding:.5rem .75rem;line-height:1.5;">
                                <?= date('Y-m-d') ?><br>
                                <strong><?= date('H:i:s') ?> <?= date('T') ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Currency -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom py-3 d-flex align-items-center gap-2">
                    <h6 class="mb-0 fw-semibold">Currency</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-5">
                            <label class="form-label fw-medium" for="currencySelect">Currency Symbol</label>
                            <select class="form-select" id="currencySelect">
                                <?php foreach (CURRENCY_OPTIONS as $sym => $label): ?>
                                <option value="<?= e($sym) ?>" <?= ($s['currency_symbol'] ?? '₱') === $sym ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                                <?php endforeach; ?>
                                <option value="custom" <?= !isset(CURRENCY_OPTIONS[$s['currency_symbol'] ?? '₱']) ? 'selected' : '' ?>>
                                    Custom…
                                </option>
                            </select>
                        </div>
                        <div class="col-md-4" id="customCurrencyGroup"
                             style="display:<?= !isset(CURRENCY_OPTIONS[$s['currency_symbol'] ?? '₱']) ? 'block' : 'none' ?>;">
                            <label class="form-label fw-medium" for="currencyCustomInput">Custom Symbol</label>
                            <div class="input-group">
                                <span class="input-group-text fw-bold" id="currencyPreview"><?= e($s['currency_symbol'] ?? '₱') ?></span>
                                <input type="text" name="currency_symbol_custom" id="currencyCustomInput"
                                       class="form-control" maxlength="5"
                                       value="<?= !isset(CURRENCY_OPTIONS[$s['currency_symbol'] ?? '₱']) ? e($s['currency_symbol'] ?? '') : '' ?>"
                                       placeholder="e.g. ₱">
                            </div>
                        </div>
                        <input type="hidden" name="currency_symbol" id="currencyHidden" value="<?= e($s['currency_symbol'] ?? '₱') ?>">
                        <div class="col-md-3">
                            <div class="p-3 rounded text-center" style="background:var(--bs-success-bg-subtle);border:1px solid var(--bs-success-border-subtle);">
                                <div class="text-muted small mb-1">Preview</div>
                                <div class="fw-bold fs-5 text-success" id="amountPreview"><?= e($s['currency_symbol'] ?? '₱') ?>1,234.00</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary px-4">
                <i class="bi bi-check-circle me-2"></i>Save Localization
            </button>
        </form>

<!-- ════════════════════════════════════════════════════════════
     TERMS TAB
════════════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'terms'): ?>

        <?php
        $termsText = $s['terms_conditions'] ?? '';
        $termsParagraphs = trim($termsText) === ''
            ? 0
            : count(array_filter(preg_split('/\R{2,}/', trim($termsText)) ?: []));
        ?>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="save_terms" value="1">

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom py-3 d-flex align-items-center gap-2">
                    <h6 class="mb-0 fw-semibold">Terms and Conditions Notice</h6>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle ms-auto">
                        <?= $termsParagraphs ?> paragraph<?= $termsParagraphs !== 1 ? 's' : '' ?>
                    </span>
                </div>
                <div class="card-body">
                    <label class="form-label fw-medium" for="termsConditionsInput">Notice Content</label>
                    <textarea name="terms_conditions" id="termsConditionsInput"
                              class="form-control"
                              rows="20"
                              style="min-height:420px;line-height:1.6;"
                              placeholder="Enter the full terms and conditions notice. Separate paragraphs with a blank line."><?= e($termsText) ?></textarea>
                    <div class="form-text">
                        Supports long notices. Use blank lines to separate paragraphs for easier reading.
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom py-3">
                    <h6 class="mb-0 fw-semibold">Preview</h6>
                </div>
                <div class="card-body">
                    <div class="p-3 rounded border bg-light"
                         id="termsPreview"
                         style="white-space:pre-wrap;line-height:1.65;max-height:420px;overflow:auto;"><?= e($termsText ?: 'No terms and conditions notice set yet.') ?></div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary px-4">
                    <i class="bi bi-check-circle me-2"></i>Save Terms
                </button>
                <a href="?tab=terms" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>

<!-- ════════════════════════════════════════════════════════════
     PAYMENT METHODS TAB
════════════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'payments'): ?>

        <!-- Portal branding -->
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="save_settings" value="1">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom py-3 d-flex align-items-center gap-2">
                    <h6 class="mb-0 fw-semibold">Portal Branding</h6>
                    <span class="badge bg-info-subtle text-info border border-info-subtle ms-auto">Subscriber-facing</span>
                </div>
                <div class="card-body">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-8">
                            <label class="form-label fw-medium" for="fPortalName">Portal Name</label>
                            <input type="text" name="portal_name" id="fPortalName" class="form-control"
                                   value="<?= e($s['portal_name'] ?? ($s['company_name'] ?? '')) ?>"
                                   maxlength="100"
                                   placeholder="e.g. My ISP Payment Portal">
                            <div class="form-text">Used as the display name for the subscriber payment portal.</div>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-check-circle me-2"></i>Save Portal Name
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <!-- Router portal access -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent border-bottom py-3 d-flex align-items-center gap-2">
                <h6 class="mb-0 fw-semibold">Subscriber Payment Portal</h6>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle ms-auto">Router gated</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($portalRouters)): ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-router d-block fs-2 mb-2 opacity-30"></i>
                    No routers configured.
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3 ps-sm-4">Router</th>
                                <th class="d-none d-sm-table-cell">Connection</th>
                                <th class="d-none d-md-table-cell text-center">Subscribers</th>
                                <th class="text-end pe-3 pe-sm-4">Portal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($portalRouters as $router): ?>
                            <?php
                                $routerOnline = ($router['status'] ?? '') === ROUTER_ONLINE;
                                $portalOn = !empty($router['portal_enabled']) && $routerOnline;
                            ?>
                            <tr>
                                <td class="ps-3 ps-sm-4">
                                    <div class="fw-semibold"><?= e($router['name']) ?></div>
                                    <div class="small text-muted font-monospace"><?= e($router['host']) ?></div>
                                    <div class="d-sm-none mt-1">
                                        <span class="badge <?= $routerOnline ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-danger-subtle text-danger border border-danger-subtle' ?>">
                                            <?= e(ucfirst($router['status'] ?? 'unknown')) ?>
                                        </span>
                                        <span class="badge bg-secondary-subtle text-secondary border ms-1"><?= number_format((int)$router['subscriber_count']) ?> subs</span>
                                    </div>
                                </td>
                                <td class="d-none d-sm-table-cell">
                                    <span class="badge <?= $routerOnline ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-danger-subtle text-danger border border-danger-subtle' ?>">
                                        <?= e(ucfirst($router['status'] ?? 'unknown')) ?>
                                    </span>
                                </td>
                                <td class="d-none d-md-table-cell text-center small fw-semibold"><?= number_format((int)$router['subscriber_count']) ?></td>
                                <td class="text-end pe-3 pe-sm-4">
                                    <form method="POST" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="portal_action" value="toggle_router">
                                        <input type="hidden" name="router_id" value="<?= (int)$router['router_id'] ?>">
                                        <button type="submit"
                                                class="btn btn-sm <?= $portalOn ? 'btn-success' : 'btn-outline-secondary' ?>"
                                                <?= !$routerOnline ? 'disabled title="Router must be online before portal activation"' : '' ?>>
                                            <i class="bi bi-<?= $portalOn ? 'toggle-on' : 'toggle-off' ?>"></i>
                                            <span class="d-none d-sm-inline ms-1"><?= $portalOn ? 'Active' : 'Inactive' ?></span>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payment Methods list -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent border-bottom py-3 d-flex align-items-center gap-2">
                <h6 class="mb-0 fw-semibold">Payment Methods</h6>
                <span class="badge bg-primary ms-auto"><?= count($allPmethods) ?> total</span>
            </div>
            <div class="card-body">
                <?php if (empty($allPmethods)): ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-credit-card d-block fs-2 mb-2 opacity-30"></i>
                    No payment methods configured yet.
                </div>
                <?php else: ?>
                <?php
                $pmIcons = ['cash'=>'bi-cash-coin','gcash'=>'bi-phone','maya'=>'bi-phone-fill',
                            'bank'=>'bi-bank','wire'=>'bi-diagram-3','check'=>'bi-file-earmark-check',
                            'online'=>'bi-laptop','card'=>'bi-credit-card'];
                $pmColors= ['cash'=>'#28a745','gcash'=>'#007bff','maya'=>'#17a2b8',
                            'bank'=>'#6f42c1','wire'=>'#fd7e14','check'=>'#20c997'];
                ?>
                <?php foreach ($allPmethods as $pm): ?>
                <?php
                    $code = $pm['code'];
                    $icon = 'bi-credit-card-2-front';
                    foreach ($pmIcons as $k => $ico) { if (str_contains($code, $k)) { $icon = $ico; break; } }
                    $color = $pmColors[$code] ?? '#6c757d';
                    $pmStatus = $pm['status'] ?? 'active';
                    [$statusBadgeCls, $statusLabel] = match($pmStatus) {
                        'maintenance' => ['bg-warning-subtle text-warning border border-warning-subtle', 'Maintenance'],
                        'unavailable' => ['bg-danger-subtle text-danger border border-danger-subtle',   'Unavailable'],
                        default       => ['bg-success-subtle text-success border border-success-subtle', 'Active'],
                    };
                ?>
                <div class="pm-card <?= $pm['is_active'] ? '' : 'inactive' ?>" style="align-items:flex-start;">
                    <div class="pm-icon mt-1" style="background:<?= $color ?>20;color:<?= $color ?>;">
                        <i class="bi <?= $icon ?>"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="fw-semibold small"><?= e($pm['name']) ?></div>
                        <div class="font-monospace text-muted" style="font-size:.72rem;"><?= e($pm['code']) ?></div>
                        <?php if (!empty($pm['remark'])): ?>
                        <div class="text-muted fst-italic mt-1" style="font-size:.72rem;"><i class="bi bi-chat-left-text me-1"></i><?= e($pm['remark']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
                        <span class="badge <?= $statusBadgeCls ?>"><?= $statusLabel ?></span>
                        <?php if (!$pm['is_active']): ?>
                        <span class="badge bg-secondary-subtle text-secondary border">Disabled</span>
                        <?php endif; ?>
                        <button type="button" class="btn btn-sm btn-outline-primary"
                                title="Edit"
                                data-pm-id="<?= $pm['pm_id'] ?>"
                                data-pm-name="<?= e(htmlspecialchars($pm['name'], ENT_QUOTES)) ?>"
                                data-pm-status="<?= e($pmStatus) ?>"
                                data-pm-remark="<?= e(htmlspecialchars($pm['remark'] ?? '', ENT_QUOTES)) ?>"
                                onclick="openPmEdit(this)">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form method="POST" class="d-inline" title="<?= $pm['is_active'] ? 'Disable' : 'Enable' ?>">
                            <?= csrfField() ?>
                            <input type="hidden" name="pm_action" value="toggle">
                            <input type="hidden" name="pm_id" value="<?= $pm['pm_id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-<?= $pm['is_active'] ? 'warning' : 'success' ?>"
                                    title="<?= $pm['is_active'] ? 'Disable (hide from dropdowns)' : 'Enable (show in dropdowns)' ?>">
                                <i class="bi bi-<?= $pm['is_active'] ? 'pause' : 'play' ?>-fill"></i>
                            </button>
                        </form>
                        <form method="POST" class="d-inline"
                              data-confirm="Delete '<?= e(addslashes($pm['name'])) ?>'?">
                            <?= csrfField() ?>
                            <input type="hidden" name="pm_action" value="delete">
                            <input type="hidden" name="pm_id" value="<?= $pm['pm_id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                <i class="bi bi-trash3"></i>
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Add new method -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent border-bottom py-3 d-flex align-items-center gap-2">
                <h6 class="mb-0 fw-semibold">Add New Payment Method</h6>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="pm_action" value="add">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label fw-medium" for="fPmName">Display Name <span class="text-danger">*</span></label>
                            <input type="text" name="pm_name" id="fPmName" class="form-control"
                                   required placeholder="e.g. GCash, Maya, Bank Transfer" maxlength="100">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium" for="fPmCode">Code <span class="text-danger">*</span></label>
                            <input type="text" name="pm_code" id="fPmCode" class="form-control font-monospace"
                                   required placeholder="e.g. gcash" maxlength="50" pattern="[a-zA-Z0-9_]+">
                            <div class="form-text">Letters, numbers, underscores only.</div>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-success w-100">
                                <i class="bi bi-plus-circle me-1"></i>Add Method
                            </button>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium" for="fPmRemark">Remark <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="text" name="pm_remark" id="fPmRemark" class="form-control"
                                   placeholder="e.g. For online payments only" maxlength="255">
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tax Settings -->
        <?php
        $taxEnabled = ($s['tax_enabled'] ?? '0') === '1';
        $taxRate    = (float)($s['tax_rate'] ?? 0);
        $taxName    = $s['tax_name'] ?? 'VAT';
        $taxType    = $s['tax_type'] ?? 'excluded';
        $sampleAmt  = 1000;
        $taxAmt     = round($sampleAmt * $taxRate / 100, 2);
        $totalAmt   = $taxType === 'excluded' ? $sampleAmt + $taxAmt : $sampleAmt;
        $baseAmt    = $taxType === 'included' ? $sampleAmt - $taxAmt : $sampleAmt;
        $sym        = $s['currency_symbol'] ?? '₱';
        ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent border-bottom py-3 d-flex align-items-center gap-2">
                <h6 class="mb-0 fw-semibold">Tax / VAT Settings</h6>
                <span class="badge ms-auto <?= $taxEnabled ? 'bg-warning text-dark' : 'bg-secondary' ?>">
                    <?= $taxEnabled ? "Tax ON · {$taxRate}%" : 'Tax OFF' ?>
                </span>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="save_tax" value="1">
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="tax_enabled"
                                       id="taxEnabled" role="switch"
                                       <?= $taxEnabled ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="taxEnabled">
                                    Enable Tax on Payments
                                </label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium" for="taxNameInput">Tax Name</label>
                            <input type="text" name="tax_name" class="form-control"
                                   value="<?= e($taxName) ?>" id="taxNameInput"
                                   placeholder="e.g. VAT, GST, Sales Tax" maxlength="30">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium" for="taxRateInput">Tax Rate</label>
                            <div class="input-group">
                                <input type="number" name="tax_rate" class="form-control"
                                       value="<?= e($s['tax_rate'] ?? '0') ?>" id="taxRateInput"
                                       step="0.01" min="0" max="100" placeholder="12">
                                <span class="input-group-text fw-bold">%</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium" for="taxTypeSelect">Tax Application</label>
                            <select name="tax_type" class="form-select" id="taxTypeSelect">
                                <option value="excluded" <?= $taxType === 'excluded' ? 'selected' : '' ?>>Excluded (added on top)</option>
                                <option value="included" <?= $taxType === 'included' ? 'selected' : '' ?>>Included (within price)</option>
                            </select>
                        </div>

                        <!-- Tax preview -->
                        <div class="col-12">
                            <div class="tax-preview">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <i class="bi bi-calculator text-success"></i>
                                    <span class="fw-semibold small">Live Calculation Preview</span>
                                    <span class="text-muted small ms-auto">Based on <?= e($sym) ?>1,000.00 payment</span>
                                </div>
                                <div class="row g-2 text-center" id="taxPreviewRow">
                                    <div class="col-4">
                                        <div class="small text-muted">Base Amount</div>
                                        <div class="fw-bold fs-6 text-body" id="prevBase"><?= e($sym) . number_format($baseAmt, 2) ?></div>
                                    </div>
                                    <div class="col-4">
                                        <div class="small text-muted" id="prevTaxLabel"><?= e($taxName) ?> (<?= $taxRate ?>%)</div>
                                        <div class="fw-bold fs-6 text-warning" id="prevTax"><?= e($sym) . number_format($taxAmt, 2) ?></div>
                                    </div>
                                    <div class="col-4">
                                        <div class="small text-muted">Total</div>
                                        <div class="fw-bold fs-5 text-success" id="prevTotal"><?= e($sym) . number_format($totalAmt, 2) ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-warning mt-3 px-4">
                        <i class="bi bi-check-circle me-2"></i>Save Tax Settings
                    </button>
                </form>
            </div>
        </div>

<!-- ════════════════════════════════════════════════════════════
     PAYMENT TYPES TAB
════════════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'payment_types'): ?>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent border-bottom py-3 d-flex align-items-center gap-2">
                <h6 class="mb-0 fw-semibold">Payment Types</h6>
                <span class="badge bg-primary ms-auto"><?= count($allPtypes) ?> total</span>
                <span class="text-muted small">Categorize payment transactions</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($allPtypes)): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-tags d-block fs-2 mb-2 opacity-30"></i>No payment types configured.
                </div>
                <?php else: ?>
                <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4" style="width:36px;">#</th>
                            <th>Type Name</th>
                            <th>Description</th>
                            <th class="text-center">Status</th>
                            <th class="pe-4 text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allPtypes as $i => $pt): ?>
                        <tr class="<?= !$pt['is_active'] ? 'opacity-60' : '' ?>">
                            <td class="ps-4 text-muted small"><?= $i + 1 ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="fw-semibold"><?= e($pt['name']) ?></span>
                                    <?php if ($pt['is_default']): ?>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle"
                                          style="font-size:.65rem;">Default</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="small text-muted"><?= e($pt['description'] ?? '—') ?></td>
                            <td class="text-center">
                                <?php if ($pt['is_active']): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                                <?php else: ?>
                                <span class="badge bg-secondary-subtle text-secondary border">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="pe-4 text-end">
                                <?php if (!$pt['is_default']): ?>
                                <div class="d-flex gap-1 justify-content-end">
                                    <form method="POST" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="pt_action" value="toggle">
                                        <input type="hidden" name="pt_id" value="<?= $pt['type_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-<?= $pt['is_active'] ? 'warning' : 'success' ?>">
                                            <i class="bi bi-<?= $pt['is_active'] ? 'pause' : 'play' ?>-fill"></i>
                                            <?= $pt['is_active'] ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                    </form>
                                    <form method="POST" class="d-inline"
                                          data-confirm="Delete '<?= e(addslashes($pt['name'])) ?>'?">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="pt_action" value="delete">
                                        <input type="hidden" name="pt_id" value="<?= $pt['type_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash3"></i>
                                        </button>
                                    </form>
                                </div>
                                <?php else: ?>
                                <span class="text-muted small"><i class="bi bi-lock"></i> Protected</span>
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

        <!-- Add new type -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent border-bottom py-3 d-flex align-items-center gap-2">
                <h6 class="mb-0 fw-semibold">Add Payment Type</h6>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="pt_action" value="add">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label fw-medium" for="fPtName">Type Name <span class="text-danger">*</span></label>
                            <input type="text" name="pt_name" id="fPtName" class="form-control" required
                                   placeholder="e.g. Monthly Subscription, Reconnection" maxlength="100">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-medium" for="fPtDesc">Description <span class="text-muted small">(optional)</span></label>
                            <input type="text" name="pt_desc" id="fPtDesc" class="form-control"
                                   placeholder="Brief description" maxlength="255">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-success w-100">
                                <i class="bi bi-plus-circle me-1"></i>Add
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

<!-- ════════════════════════════════════════════════════════════
     EXPENSE TYPES TAB
════════════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'expense_types'): ?>

        <div class="row g-4">

            <!-- Add Type Form -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-transparent border-bottom py-3">
                        <h6 class="mb-0 fw-semibold"><i class="bi bi-plus-circle me-1 text-primary"></i>Add Expense Type</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="et_action" value="add">
                            <div class="mb-3">
                                <label class="form-label fw-medium" for="fEtName">Name <span class="text-danger">*</span></label>
                                <input type="text" name="et_name" id="fEtName" class="form-control" required
                                       placeholder="e.g. Office Supplies, Utilities" maxlength="100">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-medium" for="fEtDesc">Description</label>
                                <input type="text" name="et_desc" id="fEtDesc" class="form-control"
                                       placeholder="Optional description" maxlength="255">
                            </div>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-plus-lg me-1"></i>Add Type
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Types List -->
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-transparent border-bottom py-3 d-flex align-items-center gap-2">
                        <h6 class="mb-0 fw-semibold">All Expense Types</h6>
                        <span class="badge bg-primary ms-auto"><?= count($allExpenseTypes) ?> total</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($allExpenseTypes)): ?>
                        <div class="text-center text-muted py-5">
                            <i class="bi bi-receipt-cutoff fs-2 d-block mb-2 opacity-30"></i>No expense types yet.
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                        <table class="table table-hover table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Name</th>
                                    <th>Description</th>
                                    <th class="text-center">Used</th>
                                    <th class="text-center">Status</th>
                                    <th class="pe-3 text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allExpenseTypes as $et): ?>
                                <tr>
                                    <td class="ps-3 fw-medium small"><?= e($et['name']) ?></td>
                                    <td class="text-muted small"><?= $et['description'] ? e($et['description']) : '—' ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-secondary-subtle text-secondary border"><?= $et['usage_count'] ?></span>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($et['is_active']): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary border">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="pe-3 text-end text-nowrap">
                                        <div class="d-flex gap-1 justify-content-end">
                                            <button type="button" class="btn btn-sm btn-outline-secondary btn-edit-et"
                                                    title="Edit"
                                                    data-et-id="<?= $et['type_id'] ?>"
                                                    data-et-name="<?= e($et['name']) ?>"
                                                    data-et-desc="<?= e($et['description'] ?? '') ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="POST" class="d-inline">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="et_action" value="toggle">
                                                <input type="hidden" name="et_id" value="<?= $et['type_id'] ?>">
                                                <button type="submit"
                                                        class="btn btn-sm btn-outline-<?= $et['is_active'] ? 'warning' : 'success' ?>"
                                                        title="<?= $et['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                                    <i class="bi bi-<?= $et['is_active'] ? 'toggle-on' : 'toggle-off' ?>"></i>
                                                </button>
                                            </form>
                                            <?php if ($et['usage_count'] == 0): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger btn-delete-et"
                                                    title="Delete"
                                                    data-et-id="<?= $et['type_id'] ?>"
                                                    data-et-name="<?= e($et['name']) ?>">
                                                <i class="bi bi-trash3"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
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

        <!-- Hidden delete form -->
        <form method="POST" id="deleteEtForm" style="display:none;">
            <?= csrfField() ?>
            <input type="hidden" name="et_action" value="delete">
            <input type="hidden" name="et_id" id="deleteEtId">
            <button type="submit"></button>
        </form>

<?php endif; ?>

    </div><!-- /col-lg-9 -->
</div><!-- /row -->

<?php
$extraScripts = <<<'JS'
<script>
// Logo file → instant preview + drag-drop
const logoInput   = document.getElementById('logoFileInput');
const logoPreview = document.getElementById('logoPreview');
const logoHolder  = document.getElementById('logoPlaceholder');
const dropZone    = document.getElementById('logoDropZone');

function showLogoPreview(file) {
    if (!file || !logoPreview) return;
    const reader = new FileReader();
    reader.onload = e => {
        logoPreview.src = e.target.result;
        logoPreview.style.display = '';
        if (logoHolder) logoHolder.style.display = 'none';
    };
    reader.readAsDataURL(file);
}
logoInput?.addEventListener('change', () => showLogoPreview(logoInput.files[0]));
dropZone?.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
dropZone?.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
dropZone?.addEventListener('drop', e => {
    e.preventDefault(); dropZone.classList.remove('drag-over');
    const file = e.dataTransfer.files[0];
    if (file && logoInput) {
        const dt = new DataTransfer(); dt.items.add(file);
        logoInput.files = dt.files;
        showLogoPreview(file);
    }
});

// Company info → receipt preview sync
const previewMap = {
    'company_name':    'prev_company_name',
    'company_desc':    'prev_company_desc',
    'company_address': 'prev_company_address',
    'company_tin':     'prev_company_tin',
    'company_contact': 'prev_company_contact',
};
Object.entries(previewMap).forEach(([name, id]) => {
    const inp  = document.querySelector(`[name="${name}"]`);
    const prev = document.getElementById(id);
    if (!inp || !prev) return;
    const ev = inp.tagName === 'TEXTAREA' || inp.tagName === 'INPUT' ? 'input' : 'change';
    inp.addEventListener(ev, () => {
        const v = inp.value.trim();
        if (name === 'company_tin') {
            prev.textContent = v ? 'TIN: ' + v : '';
        } else {
            prev.textContent = v || '';
        }
    });
});

// Terms → preview sync
const termsInput = document.getElementById('termsConditionsInput');
const termsPreview = document.getElementById('termsPreview');
termsInput?.addEventListener('input', () => {
    if (!termsPreview) return;
    termsPreview.textContent = termsInput.value.trim() || 'No terms and conditions notice set yet.';
});

// Currency select logic
const currSel    = document.getElementById('currencySelect');
const custGroup  = document.getElementById('customCurrencyGroup');
const custInput  = document.getElementById('currencyCustomInput');
const hiddenInp  = document.getElementById('currencyHidden');
const amtPreview = document.getElementById('amountPreview');
const curPreview = document.getElementById('currencyPreview');

function updateCurrency() {
    if (!currSel) return;
    const val = currSel.value;
    if (val === 'custom') {
        if (custGroup) custGroup.style.display = 'block';
        if (hiddenInp) hiddenInp.value = custInput?.value || '';
    } else {
        if (custGroup) custGroup.style.display = 'none';
        if (hiddenInp) hiddenInp.value = val;
    }
    const sym = hiddenInp?.value || '₱';
    if (amtPreview) amtPreview.textContent = sym + '1,234.00';
    if (curPreview) curPreview.textContent = sym;
}
currSel?.addEventListener('change', updateCurrency);
custInput?.addEventListener('input', () => {
    if (hiddenInp) hiddenInp.value = custInput.value;
    const sym = custInput.value || '₱';
    if (amtPreview) amtPreview.textContent = sym + '1,234.00';
    if (curPreview) curPreview.textContent = sym;
});

// Tax preview calculation
const taxNameInp = document.getElementById('taxNameInput');
const taxRateInp = document.getElementById('taxRateInput');
const taxTypeSel = document.getElementById('taxTypeSelect');

function updateTaxPreview() {
    const name = taxNameInp?.value || 'Tax';
    const rate = parseFloat(taxRateInp?.value) || 0;
    const type = taxTypeSel?.value || 'excluded';
    const sample = 1000;
    let base, taxAmt, total;
    if (type === 'excluded') {
        base = sample; taxAmt = sample * rate / 100; total = base + taxAmt;
    } else {
        total = sample; taxAmt = sample * rate / (100 + rate); base = total - taxAmt;
    }
    const sym = document.getElementById('currencyHidden')?.value || '₱';
    const fmt = n => sym + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const el = id => document.getElementById(id);
    if (el('prevBase'))     el('prevBase').textContent     = fmt(base);
    if (el('prevTaxLabel')) el('prevTaxLabel').textContent = `${name} (${rate}%)`;
    if (el('prevTax'))      el('prevTax').textContent      = fmt(taxAmt);
    if (el('prevTotal'))    el('prevTotal').textContent    = fmt(total);
}
taxNameInp?.addEventListener('input', updateTaxPreview);
taxRateInp?.addEventListener('input', updateTaxPreview);
taxTypeSel?.addEventListener('change', updateTaxPreview);
</script>
JS;
?>

<!-- ── Edit Payment Method Modal ──────────────────────────── -->
<div class="modal fade" id="pmEditModal" tabindex="-1" aria-labelledby="pmEditModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="pm_action" value="edit">
                <input type="hidden" name="pm_id" id="editPmId">
                <div class="modal-header">
                    <div class="d-flex align-items-center gap-2">
                        <div class="rounded-circle d-flex align-items-center justify-content-center bg-primary-subtle" style="width:36px;height:36px;flex-shrink:0;">
                            <i class="bi bi-pencil-square text-primary"></i>
                        </div>
                        <h5 class="modal-title fw-semibold mb-0" id="pmEditModalLabel">Edit Payment Method</h5>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-medium" for="editPmName">Display Name <span class="text-danger">*</span></label>
                        <input type="text" name="pm_name" id="editPmName" class="form-control" required maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium" for="editPmStatus">Status</label>
                        <select name="pm_status" id="editPmStatus" class="form-select">
                            <option value="active">Active</option>
                            <option value="maintenance">Maintenance</option>
                            <option value="unavailable">Unavailable</option>
                        </select>
                        <div class="form-text">Controls the display label. Use the pause button to hide from payment dropdowns.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium" for="editPmRemark">Remark</label>
                        <input type="text" name="pm_remark" id="editPmRemark" class="form-control" maxlength="255"
                               placeholder="Optional note about this payment method">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-semibold">
                        <i class="bi bi-check-circle me-1"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function openPmEdit(btn) {
    document.getElementById('editPmId').value     = btn.dataset.pmId;
    document.getElementById('editPmName').value   = btn.dataset.pmName;
    document.getElementById('editPmStatus').value = btn.dataset.pmStatus || 'active';
    document.getElementById('editPmRemark').value = btn.dataset.pmRemark;
    new bootstrap.Modal(document.getElementById('pmEditModal')).show();
}
</script>

<!-- ── Edit Expense Type Modal ──────────────────────────── -->
<div class="modal fade" id="etEditModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="et_action" value="edit">
                <input type="hidden" name="et_id" id="editEtId">
                <div class="modal-header">
                    <div class="d-flex align-items-center gap-2">
                        <div class="rounded-circle d-flex align-items-center justify-content-center bg-primary-subtle" style="width:36px;height:36px;flex-shrink:0;">
                            <i class="bi bi-pencil-square text-primary"></i>
                        </div>
                        <h5 class="modal-title fw-semibold mb-0">Edit Expense Type</h5>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Name <span class="text-danger">*</span></label>
                        <input type="text" name="et_name" id="editEtName" class="form-control" required maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Description</label>
                        <input type="text" name="et_desc" id="editEtDesc" class="form-control" maxlength="255">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
document.querySelectorAll('.btn-edit-et').forEach(btn => {
    btn.addEventListener('click', function () {
        document.getElementById('editEtId').value   = this.dataset.etId;
        document.getElementById('editEtName').value = this.dataset.etName;
        document.getElementById('editEtDesc').value = this.dataset.etDesc;
        new bootstrap.Modal(document.getElementById('etEditModal')).show();
    });
});

document.querySelectorAll('.btn-delete-et').forEach(btn => {
    btn.addEventListener('click', function () {
        const id   = this.dataset.etId;
        const name = this.dataset.etName;
        Swal.fire({
            title: 'Delete Expense Type?',
            html: `<div class="text-start">
                <div class="mb-3 p-2 bg-light rounded small fw-medium">${escHtml(name)}</div>
                <div class="alert alert-danger py-2 small mb-0">
                    This action <strong>cannot be undone</strong>.
                </div>
            </div>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: '<i class="bi bi-trash3 me-1"></i>Delete',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#dc3545',
            reverseButtons: true,
        }).then(result => {
            if (!result.isConfirmed) return;
            document.getElementById('deleteEtId').value = id;
            document.getElementById('deleteEtForm').submit();
        });
    });
});
</script>

<?php include BASE_PATH . '/includes/footer.php'; ?>
