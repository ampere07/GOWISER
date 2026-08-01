<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireRole(ROLE_LINEMAN, ROLE_ADMIN, ROLE_SUPERADMIN);

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/installations/');

$isLineman = hasRole(ROLE_LINEMAN);
$myId      = currentUserId();

// Load installation
$instStmt = db()->prepare("
    SELECT i.*, u.firstname AS lm_first, u.lastname AS lm_last
    FROM installations i
    LEFT JOIN users u ON u.user_id = i.user_id
    WHERE i.installation_id = ?
");
$instStmt->execute([$id]);
$install = $instStmt->fetch();
if (!$install) {
    flashMessage('danger', 'Installation not found.');
    redirect(BASE_URL . '/modules/installations/');
}

// Lineman can only view their own jobs
if ($isLineman && (int)$install['user_id'] !== $myId) {
    flashMessage('danger', 'Access denied.');
    redirect(BASE_URL . '/modules/installations/');
}

// Load subscriber
$subStmt = db()->prepare("SELECT * FROM subscribers WHERE subscriber_id = ?");
$subStmt->execute([$install['subscriber_id']]);
$subscriber = $subStmt->fetch();
if (!$subscriber) {
    flashMessage('danger', 'Subscriber record not found.');
    redirect(BASE_URL . '/modules/installations/');
}

$installStatus = $install['status']; // Pending / Ongoing / Approved
$canEdit       = in_array($installStatus, ['Pending', 'Ongoing'], true);

// Admin can change status in any direction; lineman can only move forward
$statusTransitions = [];
if ($installStatus === 'Pending')  $statusTransitions = ['Ongoing', 'Approved'];
if ($installStatus === 'Ongoing')  $statusTransitions = ['Pending', 'Approved'];
if ($installStatus === 'Approved') $statusTransitions = ['Pending', 'Ongoing'];
if ($isLineman) {
    // Lineman can only move forward
    $statusTransitions = match($installStatus) {
        'Pending' => ['Ongoing'],
        'Ongoing' => ['Approved'],
        default   => [],
    };
}

$errors   = [];
$success  = false;

// ── Address regions for cascading dropdowns ───────────────────
$addrRegions = db()->query("SELECT DISTINCT region FROM address ORDER BY region")->fetchAll(PDO::FETCH_COLUMN);

// ── Plans scoped to subscriber's router ──────────────────────
$subRouterId = (int)($subscriber['router_id'] ?? 0);
if ($subRouterId) {
    $pStmt = db()->prepare("SELECT plan_id, title FROM plans WHERE is_active = 1 AND (router_id = ? OR router_id IS NULL) ORDER BY title");
    $pStmt->execute([$subRouterId]);
} else {
    $pStmt = db()->query("SELECT plan_id, title FROM plans WHERE is_active = 1 ORDER BY title");
}
$plans = $pStmt->fetchAll();

// ── POST handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ── Change status ─────────────────────────────────────────
    if ($action === 'update_status') {
        $newStatus = $_POST['new_status'] ?? '';
        $allowed   = $isLineman
            ? match($installStatus) { 'Pending' => ['Ongoing'], 'Ongoing' => ['Approved'], default => [] }
            : ['Pending', 'Ongoing', 'Approved'];

        if (!in_array($newStatus, $allowed, true)) {
            flashMessage('danger', 'Invalid status transition.');
            redirect(BASE_URL . '/modules/installations/view?id=' . $id);
        }

        $remarkUp = trim($_POST['status_remark'] ?? $install['remark'] ?? '');
        db()->prepare("UPDATE installations SET status = ?, remark = ?, updated_at = NOW() WHERE installation_id = ?")
            ->execute([$newStatus, $remarkUp ?: null, $id]);
        logActivity('installations', 'update', "Installation #{$id} status changed from {$installStatus} to {$newStatus}");
        flashMessage('success', "Status updated to <strong>{$newStatus}</strong>.");
        redirect(BASE_URL . '/modules/installations/view?id=' . $id);
    }

    // ── Update subscriber info ────────────────────────────────
    if ($action === 'update_subscriber') {
        if (!$canEdit) {
            flashMessage('danger', 'Cannot edit subscriber when installation is Approved.');
            redirect(BASE_URL . '/modules/installations/view?id=' . $id);
        }

        $data = [
            'firstname'       => mb_strtoupper(trim($_POST['firstname']      ?? '')),
            'lastname'        => mb_strtoupper(trim($_POST['lastname']       ?? '')),
            'middlename'      => mb_strtoupper(trim($_POST['middlename']     ?? '')),
            'contact_number'  => trim($_POST['contact_number']  ?? ''),
            'email'           => trim($_POST['email']           ?? ''),
            'street'          => titleCase($_POST['street']     ?? ''),
            'barangay'        => titleCase($_POST['barangay']   ?? ''),
            'municipality'    => titleCase($_POST['municipality'] ?? ''),
            'province'        => titleCase($_POST['province']   ?? ''),
            'region'          => trim($_POST['region']          ?? ''),
            'zip'             => trim($_POST['zip']             ?? ''),
            'longitude'       => trim($_POST['longitude']       ?? ''),
            'latitude'        => trim($_POST['latitude']        ?? ''),
            'ppp_username'    => str_replace(' ', '', trim($_POST['ppp_username'] ?? '')),
            'plan_id'         => (int)($_POST['plan_id'] ?? 0) ?: null,
            'lcp_no'          => ($_POST['lcp_no']  ?? '') !== '' ? (int)$_POST['lcp_no']  : null,
            'nap_no'          => ($_POST['nap_no']  ?? '') !== '' ? (int)$_POST['nap_no']  : null,
            'port_no'         => ($_POST['port_no'] ?? '') !== '' ? (int)$_POST['port_no'] : null,
            'remarks'         => trim($_POST['remarks'] ?? ''),
        ];


        if (empty($data['firstname']))        $errors[] = 'First name is required.';
        if (empty($data['lastname']))         $errors[] = 'Last name is required.';
        if (empty($data['contact_number']))   $errors[] = 'Contact Number is required.';
        if (empty($data['street']))           $errors[] = 'Street / House No. is required.';
        if (empty($data['barangay']))         $errors[] = 'Barangay is required.';
        if (empty($data['municipality']))     $errors[] = 'Municipality / City is required.';
        if (empty($data['province']))         $errors[] = 'Province is required.';
        if (empty($data['region']))           $errors[] = 'Region is required.';
        if (empty($data['zip']))              $errors[] = 'ZIP Code is required.';
        if (empty($data['plan_id']))          $errors[] = 'Plan is required.';
        if (empty($data['ppp_username']))     $errors[] = 'PPP Username is required.';
        if (empty($data['lcp_no']))           $errors[] = 'LCP No. is required.';
        if (empty($data['nap_no']))           $errors[] = 'NAP No. is required.';
        if (empty($data['port_no']))          $errors[] = 'Port No. is required.';
        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL))
            $errors[] = 'Invalid email address.';

        if (empty($errors)) {
            $parts = array_filter([$data['street'], $data['barangay'], $data['municipality'], $data['province'], $data['region']]);
            $combinedAddress = implode(', ', $parts);

            $old = $subscriber;
            db()->prepare("
                UPDATE subscribers SET
                    firstname = ?, lastname = ?, middlename = ?,
                    contact_number = ?, email = ?,
                    address = ?, street = ?, barangay = ?, municipality = ?, province = ?, region = ?, zip = ?,
                    longitude = ?, latitude = ?,
                    ppp_username = ?,
                    plan_id = ?,
                        lcp_no = ?, nap_no = ?, port_no = ?,
                    remarks = ?, updated_at = ?
                WHERE subscriber_id = ?
            ")->execute([
                $data['firstname'], $data['lastname'], $data['middlename'],
                $data['contact_number'], $data['email'],
                $combinedAddress, $data['street'], $data['barangay'],
                $data['municipality'], $data['province'], $data['region'], $data['zip'],
                $data['longitude'] ?: null, $data['latitude'] ?: null,
                $data['ppp_username'],
                $data['plan_id'],
                $data['lcp_no'], $data['nap_no'], $data['port_no'],
                $data['remarks'],
                appNow(),
                $subscriber['subscriber_id'],
            ]);

            logActivity('subscribers', 'update',
                "Installation #{$id}: subscriber #{$subscriber['subscriber_id']} info updated by " . currentRole(),
                null, $old, array_merge($old, $data));

            flashMessage('success', 'Subscriber information updated.');
            redirect(BASE_URL . '/modules/installations/view?id=' . $id);
        }

        // Re-merge POST data for redisplay on error
        $subscriber = array_merge($subscriber, $data);
    }
}

// Reload fresh after any redirect would have happened
$linemanName = trim(($install['lm_first'] ?? '') . ' ' . ($install['lm_last'] ?? ''));

$pageTitle   = 'Installation #' . $id;
$breadcrumbs = [
    ['label' => 'Installations', 'url' => BASE_URL . '/modules/installations/'],
    ['label' => $installStatus, 'url' => BASE_URL . '/modules/installations/?status=' . $installStatus],
    ['label' => '#' . $id],
];

$statusColor = match($installStatus) {
    'Approved' => 'success',
    'Ongoing'  => 'warning',
    default    => 'secondary',
};
$statusIcon = match($installStatus) {
    'Approved' => '',
    'Ongoing'  => '',
    default    => '',
};

// JS init values for cascading dropdowns
$jsInitRegion = json_encode($subscriber['region']       ?? '');
$jsInitProv   = json_encode($subscriber['province']     ?? '');
$jsInitMuni   = json_encode($subscriber['municipality'] ?? '');
$jsInitBrgy   = json_encode($subscriber['barangay']     ?? '');

include BASE_PATH . '/includes/header.php';
?>

<!-- Validation errors -->
<?php if (!empty($errors)): ?>
<div class="alert alert-danger mb-3">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <strong>Please fix the following:</strong>
    <ul class="mb-0 mt-1">
        <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="row g-3">

    <!-- ── Left: Installation card ───────────────────────────── -->
    <div class="col-lg-4">

        <!-- Job info card -->
        <div class="card mb-3">
            <div class="card-body">
                <div class="mb-2 d-flex align-items-center gap-2">
                    <span class="fw-medium text-muted small" style="min-width:80px;">Status</span>
                    <span class="badge bg-<?= $statusColor ?>-subtle text-<?= $statusColor ?> border border-<?= $statusColor ?>-subtle fs-6 px-3 py-1">
                        <i class="bi bi-<?= $statusIcon ?> me-1"></i><?= $installStatus ?>
                    </span>
                </div>
                <div class="mb-2 d-flex gap-2">
                    <span class="fw-medium text-muted small" style="min-width:80px;">Lineman</span>
                    <span class="small"><?= e($linemanName ?: '—') ?></span>
                </div>
                <div class="mb-2 d-flex gap-2">
                    <span class="fw-medium text-muted small" style="min-width:80px;">Assigned</span>
                    <span class="small"><?= formatDate($install['created_at'], 'M d, Y') ?></span>
                </div>
                <?php if (!empty($install['remark'])): ?>
                <div class="mb-2 d-flex gap-2">
                    <span class="fw-medium text-muted small" style="min-width:80px;">Remark</span>
                    <span class="small"><?= e($install['remark']) ?></span>
                </div>
                <?php endif; ?>
                <div class="mb-0 d-flex gap-2">
                    <span class="fw-medium text-muted small" style="min-width:80px;">Updated</span>
                    <span class="small"><?= formatDate($install['updated_at'], 'M d, Y h:i A') ?></span>
                </div>
            </div>
        </div>

        <!-- Change status card -->
        <?php if (!empty($statusTransitions)): ?>
        <div class="card">
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_status">
                    <div class="mb-3">
                        <label for="jo-new-status" class="form-label fw-medium small">New Status</label>
                        <select id="jo-new-status" name="new_status" class="form-select form-select-sm" required>
                            <option value="">— Select —</option>
                            <?php foreach ($statusTransitions as $ts): ?>
                            <option value="<?= $ts ?>"><?= $ts ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="jo-status-remark" class="form-label fw-medium small">Remark <span class="text-muted fw-normal">(optional)</span></label>
                        <textarea id="jo-status-remark" name="status_remark" class="form-control form-control-sm" rows="2"
                                  placeholder="Update notes…"><?= e($install['remark'] ?? '') ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary w-100">
                        <i class="bi bi-arrow-repeat me-1"></i>Update Status
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- ── Right: Subscriber info ─────────────────────────────── -->
    <div class="col-lg-8">
        <div class="card">
            <!-- View mode -->
            <div id="viewMode" class="card-body">
                <?php if ($canEdit): ?>
                <div class="d-flex justify-content-end mb-2">
                    <button class="btn btn-sm btn-outline-primary" id="toggleEditBtn" type="button">
                        <i class="bi bi-pencil me-1"></i>Edit
                    </button>
                </div>
                <?php endif; ?>
                <?php
                $planName = '—';
                if (!empty($subscriber['plan_id'])) {
                    $pr = db()->prepare("SELECT title FROM plans WHERE plan_id = ?");
                    $pr->execute([$subscriber['plan_id']]);
                    $planName = $pr->fetchColumn() ?: '—';
                }
                $routerName = '—';
                if (!empty($subscriber['router_id'])) {
                    $rr = db()->prepare("SELECT name FROM routers WHERE router_id = ?");
                    $rr->execute([$subscriber['router_id']]);
                    $routerName = $rr->fetchColumn() ?: '—';
                }
                $fullName = trim(
                    ($subscriber['firstname'] ?? '') . ' ' .
                    ($subscriber['middlename'] ? $subscriber['middlename'] . ' ' : '') .
                    ($subscriber['lastname'] ?? '')
                );

                $groups = [
                    'Personal Information' => [
                        'Account #'  => $subscriber['account_number'] ?? '—',
                        'Full Name'  => $fullName,
                        'Contact'    => $subscriber['contact_number'] ?? '—',
                        'Email'      => $subscriber['email']          ?? '—',
                    ],
                    'Address' => [
                        'Street'       => $subscriber['street']       ?? '—',
                        'Barangay'     => $subscriber['barangay']     ?? '—',
                        'Municipality' => $subscriber['municipality'] ?? '—',
                        'Province'     => $subscriber['province']     ?? '—',
                        'Region'       => $subscriber['region']       ?? '—',
                        'ZIP'          => $subscriber['zip']          ?? '—',
                    ],
                    'Location Coordinates' => [
                        'Longitude' => $subscriber['longitude'] ?: '—',
                        'Latitude'  => $subscriber['latitude']  ?: '—',
                    ],
                    'Plan & Connection' => [
                        'Plan'         => $planName,
                        'Connection'   => strtoupper($subscriber['connection_type'] ?? '—'),
                        'PPP Username' => $subscriber['ppp_username'] ?? '—',
                        'Router'       => $routerName,
                    ],
                    'Installation Details' => [
                        'LCP No.'  => $subscriber['lcp_no']  ?? '—',
                        'NAP No.'  => $subscriber['nap_no']  ?? '—',
                        'Port No.' => $subscriber['port_no'] ?? '—',
                    ],
                    'Remarks' => [
                        'Remarks' => $subscriber['remarks'] ?: '—',
                    ],
                ];
                ?>
                <?php foreach ($groups as $groupLabel => $fields): ?>
                <div class="mb-1">
                    <div class="px-2 py-1" style="background:var(--bs-tertiary-bg,#f8f9fa);">
                        <span class="fw-semibold text-muted" style="font-size:.68rem; text-transform:uppercase; letter-spacing:.07em;">
                            <?= e($groupLabel) ?>
                        </span>
                    </div>
                    <div class="row g-0">
                        <?php foreach ($fields as $label => $val): ?>
                        <div class="col-sm-6 border-bottom py-2 px-2">
                            <div class="text-muted small"><?= e($label) ?></div>
                            <div class="fw-medium small"><?= e($val) ?></div>
                        </div>
                        <?php endforeach; ?>
                        <?php if ($groupLabel === 'Plan & Connection' && !empty($subscriber['ppp_password'])): ?>
                        <div class="col-sm-6 border-bottom py-2 px-2">
                            <div class="text-muted small">PPP Password</div>
                            <div class="fw-medium small d-flex align-items-center gap-1">
                                <span id="joPwdDisplay" class="font-monospace text-muted">••••••••</span>
                                <button type="button" class="btn btn-link btn-sm p-0 ms-1 text-secondary" id="joPwdRevealBtn" title="Reveal">
                                    <i class="bi bi-eye" id="joPwdRevealIcon"></i>
                                </button>
                                <button type="button" class="btn btn-link btn-sm p-0 ms-0 text-secondary" id="joPwdCopyBtn" title="Copy">
                                    <i class="bi bi-copy" id="joPwdCopyIcon"></i>
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
            </div>

            <!-- Edit mode (hidden by default) -->
            <?php if ($canEdit): ?>
            <div id="editMode" class="card-body d-none">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_subscriber">

                    <h6 class="fw-semibold text-muted mb-3 small text-uppercase">Personal</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-4">
                            <label for="jo-firstname" class="form-label fw-medium small">First Name <span class="text-danger">*</span></label>
                            <input type="text" id="jo-firstname" name="firstname" class="form-control form-control-sm"
                                   value="<?= e($subscriber['firstname'] ?? '') ?>" required>
                        </div>
                        <div class="col-sm-4">
                            <label for="jo-lastname" class="form-label fw-medium small">Last Name <span class="text-danger">*</span></label>
                            <input type="text" id="jo-lastname" name="lastname" class="form-control form-control-sm"
                                   value="<?= e($subscriber['lastname'] ?? '') ?>" required>
                        </div>
                        <div class="col-sm-4">
                            <label for="jo-middlename" class="form-label fw-medium small">Middle Name</label>
                            <input type="text" id="jo-middlename" name="middlename" class="form-control form-control-sm"
                                   value="<?= e($subscriber['middlename'] ?? '') ?>">
                        </div>
                        <div class="col-sm-6">
                            <label for="jo-contact" class="form-label fw-medium small">Contact Number <span class="text-danger">*</span></label>
                            <input type="text" id="jo-contact" name="contact_number" class="form-control form-control-sm"
                                   value="<?= e($subscriber['contact_number'] ?? '') ?>" required>
                        </div>
                        <div class="col-sm-6">
                            <label for="jo-email" class="form-label fw-medium small">Email</label>
                            <input type="email" id="jo-email" name="email" class="form-control form-control-sm"
                                   value="<?= e($subscriber['email'] ?? '') ?>">
                        </div>
                    </div>

                    <h6 class="fw-semibold text-muted mb-3 small text-uppercase">Address</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label for="joRegion" class="form-label fw-medium small">Region <span class="text-danger">*</span></label>
                            <select name="region" id="joRegion" class="form-select form-select-sm" required>
                                <option value="">— Select Region —</option>
                                <?php foreach ($addrRegions as $reg): ?>
                                <option value="<?= e($reg) ?>" <?= ($subscriber['region'] ?? '') === $reg ? 'selected' : '' ?>>
                                    <?= e($reg) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label for="joProvince" class="form-label fw-medium small">Province <span class="text-danger">*</span></label>
                            <select name="province" id="joProvince" class="form-select form-select-sm" required>
                                <option value="">— Select Province —</option>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label for="joMunicipality" class="form-label fw-medium small">Municipality / City <span class="text-danger">*</span></label>
                            <select name="municipality" id="joMunicipality" class="form-select form-select-sm" required>
                                <option value="">— Select Municipality —</option>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label for="joBarangay" class="form-label fw-medium small">Barangay <span class="text-danger">*</span></label>
                            <div id="joBrgyWrap">
                                <select name="barangay" id="joBarangay" class="form-select form-select-sm">
                                    <option value="">— Select Barangay —</option>
                                </select>
                                <input type="text" name="barangay" id="joBarangayText" class="form-control form-control-sm d-none"
                                       placeholder="Type barangay…" value="<?= e($subscriber['barangay'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <label for="jo-street" class="form-label fw-medium small">Street / House No. <span class="text-danger">*</span></label>
                            <input type="text" id="jo-street" name="street" class="form-control form-control-sm"
                                   value="<?= e($subscriber['street'] ?? '') ?>" required>
                        </div>
                        <div class="col-sm-6">
                            <label for="jo-zip" class="form-label fw-medium small">ZIP Code <span class="text-danger">*</span></label>
                            <input type="text" id="jo-zip" name="zip" class="form-control form-control-sm"
                                   value="<?= e($subscriber['zip'] ?? '') ?>" required>
                        </div>
                    </div>

                    <h6 class="fw-semibold text-muted mb-3 small text-uppercase">Plan &amp; PPP</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label for="jo-plan" class="form-label fw-medium small">Plan <span class="text-danger">*</span></label>
                            <select id="jo-plan" name="plan_id" class="form-select form-select-sm" required>
                                <option value="">— No Plan —</option>
                                <?php foreach ($plans as $pl): ?>
                                <option value="<?= $pl['plan_id'] ?>" <?= (int)($subscriber['plan_id'] ?? 0) === (int)$pl['plan_id'] ? 'selected' : '' ?>>
                                    <?= e($pl['title']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label for="jo-ppp-username" class="form-label fw-medium small">PPP Username <span class="text-danger">*</span></label>
                            <input type="text" id="jo-ppp-username" name="ppp_username" class="form-control form-control-sm font-monospace"
                                   value="<?= e($subscriber['ppp_username'] ?? '') ?>" autocomplete="off" required>
                        </div>
                    </div>

                    <h6 class="fw-semibold text-muted mb-3 small text-uppercase">Connection Details</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-4">
                            <label for="jo-lcp" class="form-label fw-medium small">LCP No. <span class="text-danger">*</span></label>
                            <input type="number" id="jo-lcp" name="lcp_no" class="form-control form-control-sm"
                                   min="1" value="<?= e($subscriber['lcp_no'] ?? '') ?>" placeholder="e.g. 101" required>
                        </div>
                        <div class="col-sm-4">
                            <label for="jo-nap" class="form-label fw-medium small">NAP No. <span class="text-danger">*</span></label>
                            <select id="jo-nap" name="nap_no" class="form-select form-select-sm" required>
                                <option value="">— Select —</option>
                                <?php for ($n = 1; $n <= 8; $n++): ?>
                                <option value="<?= $n ?>" <?= (int)($subscriber['nap_no'] ?? 0) === $n ? 'selected' : '' ?>><?= $n ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-sm-4">
                            <label for="jo-port" class="form-label fw-medium small">Port No. <span class="text-danger">*</span></label>
                            <select id="jo-port" name="port_no" class="form-select form-select-sm" required>
                                <option value="">— Select —</option>
                                <?php for ($p = 1; $p <= 16; $p++): ?>
                                <option value="<?= $p ?>" <?= (int)($subscriber['port_no'] ?? 0) === $p ? 'selected' : '' ?>><?= $p ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>

                    <h6 class="fw-semibold text-muted mb-3 small text-uppercase">Location Coordinates</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label for="jo-longitude" class="form-label fw-medium small">Longitude</label>
                            <input type="text" id="jo-longitude" name="longitude" class="form-control form-control-sm"
                                   value="<?= e($subscriber['longitude'] ?? '') ?>" placeholder="e.g. 122.0797">
                        </div>
                        <div class="col-sm-6">
                            <label for="jo-latitude" class="form-label fw-medium small">Latitude</label>
                            <input type="text" id="jo-latitude" name="latitude" class="form-control form-control-sm"
                                   value="<?= e($subscriber['latitude'] ?? '') ?>" placeholder="e.g. 6.9214">
                        </div>
                    </div>

                    <h6 class="fw-semibold text-muted mb-3 small text-uppercase">Notes &amp; Notifications</h6>
                    <div class="mb-3">
                        <label for="jo-remarks" class="form-label fw-medium small">Remarks</label>
                        <textarea id="jo-remarks" name="remarks" class="form-control form-control-sm" rows="2"><?= e($subscriber['remarks'] ?? '') ?></textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Save Changes
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="cancelEditBtn">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$jsHasErrors  = json_encode(!empty($errors));
$jsPwd        = json_encode(decryptData($subscriber['ppp_password'] ?? ''));
$extraScripts = <<<JS
<script>
// ── PPP password reveal / copy (view mode) ───────────────────
(function () {
    var revealBtn  = document.getElementById('joPwdRevealBtn');
    var copyBtn    = document.getElementById('joPwdCopyBtn');
    var display    = document.getElementById('joPwdDisplay');
    var revealIcon = document.getElementById('joPwdRevealIcon');
    var copyIcon   = document.getElementById('joPwdCopyIcon');
    if (!revealBtn) return;

    var plain    = {$jsPwd};
    var revealed = false;

    revealBtn.addEventListener('click', function () {
        revealed = !revealed;
        display.textContent = revealed ? plain : '••••••••';
        display.classList.toggle('text-muted', !revealed);
        revealIcon.className = revealed ? 'bi bi-eye-slash' : 'bi bi-eye';
    });

    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            navigator.clipboard.writeText(plain).then(function () {
                copyIcon.className = 'bi bi-check-lg text-success';
                setTimeout(function () { copyIcon.className = 'bi bi-copy'; }, 2000);
            }).catch(function () { showToast('Could not copy password.', 'danger'); });
        });
    }
})();

// ── Edit/view toggle ──────────────────────────────────────────
(function () {
    var toggleBtn  = document.getElementById('toggleEditBtn');
    var cancelBtn  = document.getElementById('cancelEditBtn');
    var viewMode   = document.getElementById('viewMode');
    var editMode   = document.getElementById('editMode');
    if (!toggleBtn) return;

    var showErrors = {$jsHasErrors};
    if (showErrors) {
        viewMode.classList.add('d-none');
        editMode.classList.remove('d-none');
        toggleBtn.textContent = 'Cancel';
    }

    toggleBtn.addEventListener('click', function () {
        var editing = !editMode.classList.contains('d-none');
        if (editing) {
            editMode.classList.add('d-none');
            viewMode.classList.remove('d-none');
            toggleBtn.innerHTML = '<i class="bi bi-pencil me-1"></i>Edit';
        } else {
            viewMode.classList.add('d-none');
            editMode.classList.remove('d-none');
            toggleBtn.innerHTML = '<i class="bi bi-x-lg me-1"></i>Cancel';
        }
    });
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function () {
            editMode.classList.add('d-none');
            viewMode.classList.remove('d-none');
            toggleBtn.innerHTML = '<i class="bi bi-pencil me-1"></i>Edit';
        });
    }
})();

// ── Cascading address dropdowns ───────────────────────────────
(function () {
    var INIT_REGION = {$jsInitRegion};
    var INIT_PROV   = {$jsInitProv};
    var INIT_MUNI   = {$jsInitMuni};
    var INIT_BRGY   = {$jsInitBrgy};
    var BASE        = BASE_URL;

    var selRegion = document.getElementById('joRegion');
    var selProv   = document.getElementById('joProvince');
    var selMuni   = document.getElementById('joMunicipality');
    var selBrgy   = document.getElementById('joBarangay');
    var txtBrgy   = document.getElementById('joBarangayText');
    if (!selRegion) return;

    function resetSelect(sel, placeholder, disabled) {
        sel.innerHTML = '<option value="">' + placeholder + '</option>';
        sel.disabled  = disabled;
    }

    function loadOptions(sel, url, placeholder, preselect, onDone) {
        sel.innerHTML = '<option value="">Loading…</option>';
        sel.disabled  = true;
        fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (d) {
                resetSelect(sel, placeholder, false);
                (d.data || []).forEach(function (v) {
                    var val = (typeof v === 'object') ? v.barangay : v;
                    var opt = document.createElement('option');
                    opt.value       = val;
                    opt.textContent = val;
                    if (preselect && val === preselect) opt.selected = true;
                    sel.appendChild(opt);
                });
                if (onDone) onDone(d.data || []);
            })
            .catch(function () { resetSelect(sel, placeholder, false); });
    }

    function showBrgyMode(hasList, preselect) {
        if (hasList) {
            txtBrgy.classList.add('d-none');
            txtBrgy.name = '';
            selBrgy.classList.remove('d-none');
            selBrgy.name = 'barangay';
        } else {
            selBrgy.classList.add('d-none');
            selBrgy.name = '';
            txtBrgy.classList.remove('d-none');
            txtBrgy.name = 'barangay';
            if (preselect) txtBrgy.value = preselect;
        }
    }

    selRegion.addEventListener('change', function () {
        resetSelect(selProv, '— Select Province —',      true);
        resetSelect(selMuni, '— Select Municipality —',  true);
        resetSelect(selBrgy, '— Select Barangay —',      true);
        txtBrgy.value = '';
        showBrgyMode(true);
        if (!this.value) return;
        loadOptions(selProv, BASE + '/api/address.php?level=provinces&p=' + encodeURIComponent(this.value), '— Select Province —', null);
    });

    selProv.addEventListener('change', function () {
        resetSelect(selMuni, '— Select Municipality —', true);
        resetSelect(selBrgy, '— Select Barangay —',     true);
        txtBrgy.value = '';
        showBrgyMode(true);
        if (!this.value) return;
        loadOptions(selMuni, BASE + '/api/address.php?level=municipalities&p=' + encodeURIComponent(this.value), '— Select Municipality —', null);
    });

    selMuni.addEventListener('change', function () {
        resetSelect(selBrgy, '— Select Barangay —', true);
        txtBrgy.value = '';
        if (!this.value) return;
        var prov = selProv.value;
        loadOptions(selBrgy,
            BASE + '/api/address.php?level=barangays&p=' + encodeURIComponent(this.value) + '&prov=' + encodeURIComponent(prov),
            '— Select Barangay —', null,
            function (rows) { showBrgyMode(rows.length > 0, null); });
    });

    // Init chain
    if (INIT_REGION) {
        loadOptions(selProv,
            BASE + '/api/address.php?level=provinces&p=' + encodeURIComponent(INIT_REGION),
            '— Select Province —', INIT_PROV,
            function () {
                if (!INIT_PROV) return;
                loadOptions(selMuni,
                    BASE + '/api/address.php?level=municipalities&p=' + encodeURIComponent(INIT_PROV),
                    '— Select Municipality —', INIT_MUNI,
                    function () {
                        if (!INIT_MUNI) return;
                        loadOptions(selBrgy,
                            BASE + '/api/address.php?level=barangays&p=' + encodeURIComponent(INIT_MUNI) + '&prov=' + encodeURIComponent(INIT_PROV),
                            '— Select Barangay —', INIT_BRGY,
                            function (rows) { showBrgyMode(rows.length > 0, INIT_BRGY); });
                    });
            });
    }
})();
</script>
JS;

include BASE_PATH . '/includes/footer.php';
