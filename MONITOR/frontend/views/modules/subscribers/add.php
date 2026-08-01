<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireRole(ROLE_CASHIER, ROLE_ADMIN, ROLE_SUPERADMIN);


require_once BASE_PATH . '/lib/Mailer.php';
require_once BASE_PATH . '/lib/SMS.php';
require_once BASE_PATH . '/lib/RouterosAPI.php';

$errors          = [];
$currentRouterId = selectedRouterId();

// Plans scoped to current router (plus universal plans with NULL router)
if ($currentRouterId) {
    $pStmt = db()->prepare("SELECT * FROM plans WHERE is_active = 1 AND ppp_profile IS NOT NULL AND ppp_profile <> '' AND (router_id = ? OR router_id IS NULL) ORDER BY amount");
    $pStmt->execute([$currentRouterId]);
    $plans = $pStmt->fetchAll();
} else {
    $plans = db()->query("SELECT * FROM plans WHERE is_active = 1 AND ppp_profile IS NOT NULL AND ppp_profile <> '' ORDER BY amount")->fetchAll();
}

// Current router info for display
$currentRouterRow  = null;
$currentRouterName = 'None';
$currentRouterConnected = false;
if ($currentRouterId) {
    $rStmt = db()->prepare("SELECT router_id, name, host, status, COALESCE(auth_type, 'local') AS auth_type, region, province, municipality FROM routers WHERE router_id = ?");
    $rStmt->execute([$currentRouterId]);
    $currentRouterRow  = $rStmt->fetch();
    if ($currentRouterRow) {
        $currentRouterName = $currentRouterRow['name'] . ' (' . $currentRouterRow['host'] . ')';
        $currentRouterConnected = ($currentRouterRow['status'] ?? '') === ROUTER_ONLINE;
    }
}
// Location auto-fill: on first load use router's values; on POST re-display use submitted values
$hasPost      = $_SERVER['REQUEST_METHOD'] === 'POST';
$routerRegion = $currentRouterRow['region']       ?? '';
$routerProv   = $currentRouterRow['province']     ?? '';
$routerMuni   = $currentRouterRow['municipality'] ?? '';
$initRegion   = $hasPost ? ($_POST['region']       ?? '') : $routerRegion;
$initProv     = $hasPost ? ($_POST['province']     ?? '') : $routerProv;
$initMuni     = $hasPost ? ($_POST['municipality'] ?? '') : $routerMuni;
$currentRouterIsRadius = ($currentRouterRow['auth_type'] ?? 'local') === 'radius';
$planAccountTerm = $currentRouterIsRadius ? 'User Manager group' : 'MikroTik profile';

$addrRegions = db()->query("SELECT DISTINCT region FROM address ORDER BY region")->fetchAll(PDO::FETCH_COLUMN);

// Preview next account number
$nextAccNum = generateUniqueAccountNumber();

// Payment types for initial payment card
$payTypes  = getPaymentTypes();
$defTypeId = 0;
foreach ($payTypes as $pt) { if ($pt['is_default']) { $defTypeId = (int)$pt['type_id']; break; } }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if (!$currentRouterConnected) {
        $errors[] = 'Cannot add a subscriber because the current router is not connected.';
    }

    $data = [
        'account_number'     => trim($_POST['account_number']  ?? ''),
        'firstname'          => mb_strtoupper(trim($_POST['firstname']    ?? '')),
        'lastname'           => mb_strtoupper(trim($_POST['lastname']   ?? '')),
        'middlename'         => mb_strtoupper(trim($_POST['middlename'] ?? '')),
        'street'             => titleCase($_POST['street']     ?? ''),
        'barangay'           => titleCase($_POST['barangay']   ?? ''),
        'municipality'       => titleCase($_POST['municipality'] ?? ''),
        'province'           => titleCase($_POST['province']   ?? ''),
        'region'             => trim($_POST['region']          ?? ''),
        'zip'                => trim($_POST['zip']             ?? ''),
        'longitude'          => trim($_POST['longitude']       ?? ''),
        'latitude'           => trim($_POST['latitude']        ?? ''),
        'contact_number'     => trim($_POST['contact_number']  ?? ''),
        'email'              => trim($_POST['email']           ?? ''),
        'connection_type'    => $_POST['connection_type']      ?? 'ppp',
        'ppp_username'       => str_replace(' ', '', trim($_POST['ppp_username'] ?? '')),
        'ppp_password_plain' => trim($_POST['ppp_password']    ?? ''),
        'plan_id'            => (int)($_POST['plan_id']        ?? 0) ?: null,
        'router_id'          => (int)($_POST['router_id']      ?? $currentRouterId) ?: null,
        'subscription_start' => $_POST['subscription_start']   ?? appToday(),
        'subscription_end'   => $_POST['subscription_end']     ?? '',
        'status'             => $_POST['status']               ?? 'active',
        'remarks'            => trim($_POST['remarks']         ?? ''),
        'notify_sms'         => !empty($_POST['notify_sms']),
        'notify_email'       => !empty($_POST['notify_email']),
    ];

    if (!$data['router_id'] || !routerIsConnected((int)$data['router_id'])) {
        $errors[] = 'Cannot add a subscriber because the assigned router is not connected.';
    }

    if (empty($data['firstname']))        $errors[] = 'First name is required.';
    if (empty($data['lastname']))         $errors[] = 'Last name is required.';
    if (empty($data['plan_id']))          $errors[] = 'Plan is required.';
    if (empty($data['subscription_end'])) $errors[] = 'Subscription end date is required.';
    if (empty($data['street']))           $errors[] = 'Street / House No. is required.';
    if (empty($data['region']))           $errors[] = 'Region is required.';
    if (empty($data['barangay']))         $errors[] = 'Barangay is required.';
    if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address.';
    }
    if (!empty($data['contact_number']) && !validatePhone($data['contact_number'])) {
        $errors[] = 'Invalid contact number. Format: 09XXXXXXXXX or +639XXXXXXXXX.';
    }
    $allowedStatuses = canManageRouterAccounts() ? ['active', 'pending', 'suspended'] : ['active', 'pending'];
    if (!in_array($data['status'], $allowedStatuses, true)) {
        $data['status'] = 'active';
    }
    if (!SMS_ENABLED) {
        $data['notify_sms'] = false;
    }
    if (!MAIL_ENABLED) {
        $data['notify_email'] = false;
    }

    // Auto-generate account number (sequential YYYY-NNNNN)
    if (empty($data['account_number'])) {
        $data['account_number'] = generateUniqueAccountNumber();
    } else {
        $ck = db()->prepare("SELECT 1 FROM subscribers WHERE account_number = ? AND router_id = ?");
        $ck->execute([$data['account_number'], $data['router_id']]);
        if ($ck->fetchColumn()) $errors[] = 'Account number already exists on this router.';
    }

    // Username = FirstnameLastname-AccountNumber (e.g. RizzaAlfad-2026-1599)
    if (empty($data['ppp_username'])) {
        $fn = ucfirst(strtolower(str_replace(' ', '', $data['firstname'])));
        $ln = ucfirst(strtolower(str_replace(' ', '', $data['lastname'])));
        $data['ppp_username'] = $fn . $ln . '-' . $data['account_number'];
    }

    // Auto-generate password if blank
    $plainPppPassword = $data['ppp_password_plain'];
    if (empty($plainPppPassword)) {
        $plainPppPassword = generatePassword(12);
    }

    $portalPasswordPlain = strtolower('gowiserph@' . $data['lastname']);
    $portalPasswordHash  = password_hash($portalPasswordPlain, PASSWORD_DEFAULT);

    // Build combined address
    $parts = array_filter([$data['street'], $data['barangay'], $data['municipality'], $data['province'], $data['region']]);
    $data['address'] = implode(', ', $parts);

    // Derive ppp_profile from plan
    $planProfile = '';
    if ($data['plan_id']) {
        $planRow = db()->prepare("SELECT ppp_profile, title FROM plans WHERE plan_id = ? AND is_active = 1");
        $planRow->execute([$data['plan_id']]);
        $planRow = $planRow->fetch();
        if (!$planRow || trim((string)($planRow['ppp_profile'] ?? '')) === '') {
            $errors[] = 'Selected plan must have an associated ' . $planAccountTerm . '.';
        } else {
            $planProfile = trim((string)$planRow['ppp_profile']);
        }
    }

    if (empty($errors)) {
        $isAutoNum = empty(trim($_POST['account_number'] ?? ''));
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $stmt = db()->prepare("
                    INSERT INTO subscribers
                        (account_number, firstname, lastname, middlename, address,
                         street, barangay, municipality, province, region, zip,
                         longitude, latitude,
                         contact_number, email, password, connection_type, ppp_profile, ppp_username,
                         ppp_password, plan_id, router_id, subscription_start,
                         subscription_end, status, remarks, created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ");
                $stmt->execute([
                    $data['account_number'],
                    $data['firstname'],
                    $data['lastname'],
                    $data['middlename']  ?: null,
                    $data['address']     ?: null,
                    $data['street']      ?: null,
                    $data['barangay']    ?: null,
                    $data['municipality'] ?: null,
                    $data['province']    ?: null,
                    $data['region']      ?: null,
                    $data['zip']         ?: null,
                    $data['longitude'] !== '' ? (float)$data['longitude'] : null,
                    $data['latitude']  !== '' ? (float)$data['latitude']  : null,
                    $data['contact_number'] ? sanitizePhone($data['contact_number']) : null,
                    $data['email']       ?: null,
                    $portalPasswordHash,
                    $data['connection_type'],
                    $planProfile ?: null,
                    $data['ppp_username'] ?: null,
                    encryptData($plainPppPassword),
                    $data['plan_id'],
                    $data['router_id'],
                    $data['subscription_start'] ?: null,
                    $data['subscription_end']   ?: null,
                    $data['status'],
                    $data['remarks'] ?: null,
                    appNow(),
                ]);
                $newId = (int)db()->lastInsertId();

                // Auto-record initial payment when checkbox is ticked
                $paymentOrNum = null;
                if (!empty($_POST['record_payment']) && (float)($_POST['pay_amount'] ?? 0) > 0) {
                    $payAmount = min((float)$_POST['pay_amount'], 999999);
                    $payTypeId = (int)($_POST['pay_type_id'] ?? $defTypeId) ?: $defTypeId;
                    $validMethods = array_keys(getPaymentMethods());
                    $payMethod = in_array($_POST['pay_method'] ?? '', $validMethods) ? $_POST['pay_method'] : 'cash';
                    $payRef    = trim($_POST['pay_reference'] ?? '') ?: null;
                    $payDate   = trim($_POST['pay_date']      ?? '') ?: appNow();
                    $payPStart = hasRole(ROLE_SUPERADMIN)
                        ? (trim($_POST['pay_period_start'] ?? '') ?: ($data['subscription_start'] ?: null))
                        : ($data['subscription_start'] ?: null);
                    $payPEnd   = hasRole(ROLE_SUPERADMIN)
                        ? (trim($_POST['pay_period_end'] ?? '') ?: ($data['subscription_end'] ?: null))
                        : ($data['subscription_end'] ?: null);
                    $payNotes  = trim($_POST['pay_notes']     ?? '') ?: 'New Connection';
                    $payStatus = ($_POST['pay_status'] ?? '') === 'pending' ? 'pending' : 'paid';
                    $paymentOrNum = generateORNumber();
                    db()->prepare("
                        INSERT INTO payments
                            (or_number, subscriber_id, router_id, payment_type_id, user_id, amount,
                             method, reference_number, payment_date, period_start, period_end, status, notes, created_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ")->execute([
                        $paymentOrNum, $newId, $data['router_id'], $payTypeId, currentUserId(), $payAmount,
                        $payMethod, $payRef, $payDate, $payPStart, $payPEnd, $payStatus, $payNotes, appNow(),
                    ]);
                    logActivity('payments', 'create',
                        "Initial payment OR {$paymentOrNum} for {$data['account_number']}: ₱" . number_format($payAmount, 2),
                        $newId
                    );
                }

                logActivity('subscribers', 'create',
                    "Created subscriber {$data['account_number']}: {$data['firstname']} {$data['lastname']}",
                    $newId, null, ['account_number' => $data['account_number'], 'connection_type' => $data['connection_type']]
                );

                // Auto-push credentials to MikroTik when subscriber is active.
                if ($data['router_id'] && $data['status'] === 'active') {
                    $rStmt = db()->prepare("SELECT * FROM routers WHERE router_id = ?");
                    $rStmt->execute([$data['router_id']]);
                    $routerRow = $rStmt->fetch();
                    if ($routerRow) {
                        $api = new RouterosAPI($routerRow['host'], (int)($routerRow['api_port'] ?: $routerRow['port']), 5);
                        if ($api->connect($routerRow['username'], decryptData($routerRow['password']))) {
                            $profile  = $planProfile ?: 'default';
                            $uname    = $data['ppp_username'];
                            $authType = $routerRow['auth_type'] ?? 'local';
                            $routerComment = APP_NAME . ' | ' . trim($data['firstname'] . ' ' . $data['lastname']) . ' | ' . appToday();
                            if ($authType === 'radius') {
                                $api->addUserManagerUser(['name' => $uname, 'password' => $plainPppPassword, 'group' => $profile, 'comment' => $routerComment]);
                            } elseif ($data['connection_type'] === 'ppp') {
                                $api->addPPPSecret(['name' => $uname, 'password' => $plainPppPassword, 'profile' => $profile, 'service' => 'pppoe', 'comment' => $routerComment]);
                            } else {
                                $api->addHotspotUser(['name' => $uname, 'password' => $plainPppPassword, 'profile' => $profile, 'comment' => $routerComment]);
                            }
                            $api->disconnect();
                        }
                    }
                }

                if ($data['notify_sms'] && !empty($data['contact_number'])) {
                    SMS::sendAccountCreated($data + ['account_number' => $data['account_number']]);
                }
                if ($data['notify_email'] && !empty($data['email'])) {
                    Mailer::sendWelcome($data + ['account_number' => $data['account_number']], $plainPppPassword);
                }

                $payMsg = $paymentOrNum ? " | Payment OR #{$paymentOrNum} recorded." : '';
                flashMessage('success', "Subscriber {$data['account_number']} created. Username: {$data['ppp_username']} | Password: {$plainPppPassword}{$payMsg}");
                redirect(BASE_URL . '/modules/subscribers/view/' . $newId);

            } catch (PDOException $e) {
                if ((string)$e->getCode() === '23000' && $isAutoNum && $attempt < 3) {
                    // Auto-generated number collided — regenerate and retry silently
                    $data['account_number'] = generateUniqueAccountNumber();
                    $fn = ucfirst(strtolower(str_replace(' ', '', $data['firstname'])));
                    $ln = ucfirst(strtolower(str_replace(' ', '', $data['lastname'])));
                    $data['ppp_username'] = $fn . $ln . '-' . $data['account_number'];
                    continue;
                }
                if ((string)$e->getCode() === '23000') {
                    $errors[] = $isAutoNum
                        ? 'Account number collision due to a concurrent request. Please submit again to get a new number.'
                        : 'Account number "' . e($data['account_number']) . '" is already in use. Please enter a different one.';
                } else {
                    $errors[] = APP_DEBUG ? $e->getMessage() : 'Database error. Please try again.';
                }
                break;
            }
        }
    }
}

$currency    = getSetting('currency_symbol', '₱');
$pageTitle   = 'Add Subscriber';
$breadcrumbs = [
    ['label' => 'Subscribers', 'url' => BASE_URL . '/modules/subscribers/'],
    ['label' => 'Add Subscriber'],
];

$defaultRouterId = selectedRouterId();

$extraHead = '
<link rel="stylesheet" href="' . BASE_URL . '/assets/vendor/leaflet/leaflet.css">
<style>
#locationMap { position:relative; z-index:0; }
.leaflet-pane, .leaflet-tile, .leaflet-marker-icon,
.leaflet-tile-container, .leaflet-overlay-pane svg,
.leaflet-zoom-box, .leaflet-image-layer, .leaflet-layer { z-index:auto !important; }
</style>
';

include BASE_PATH . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <h4 class="fw-bold mb-0">Add Subscriber</h4>
    <a href="./" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back to List
    </a>
</div>

<?= inlineToasts($errors) ?>

<?php if (!$currentRouterConnected): ?>
<?= inlineToasts([['message' => 'Current router is not connected. Subscribers cannot be added until the router is online.', 'type' => 'warning']]) ?>
<?php endif; ?>

<form method="POST" id="addSubForm" novalidate>
    <?= csrfField() ?>
    <div class="row g-4">

        <!-- Left column -->
        <div class="col-lg-8">

            <!-- Personal Info -->
            <div class="card border-0 mb-4">
                <div class="card-header bg-transparent border-bottom fw-semibold py-3">
                    Personal Information
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="fFirstname" class="form-label fw-medium">First Name <span class="text-danger">*</span></label>
                            <input type="text" name="firstname" id="fFirstname" class="form-control text-uppercase"
                                   value="<?= e($_POST['firstname'] ?? '') ?>" required
                                   autocapitalize="characters" spellcheck="false">
                        </div>
                        <div class="col-md-4">
                            <label for="fLastname" class="form-label fw-medium">Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="lastname" id="fLastname" class="form-control text-uppercase"
                                   value="<?= e($_POST['lastname'] ?? '') ?>" required
                                   autocapitalize="characters" spellcheck="false">
                        </div>
                        <div class="col-md-4">
                            <label for="fMiddlename" class="form-label fw-medium">Middle Name</label>
                            <input type="text" name="middlename" id="fMiddlename" class="form-control text-uppercase"
                                   value="<?= e($_POST['middlename'] ?? '') ?>"
                                   autocapitalize="characters" spellcheck="false">
                        </div>
                        <div class="col-md-6">
                            <label for="fContact" class="form-label fw-medium">Contact Number</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-phone"></i></span>
                                <input type="tel" name="contact_number" id="fContact" class="form-control"
                                       placeholder="09XXXXXXXXX"
                                       value="<?= e($_POST['contact_number'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="fEmail" class="form-label fw-medium">Email Address</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                <input type="email" name="email" id="fEmail" class="form-control"
                                       value="<?= e($_POST['email'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Address + Map -->
            <div class="card border-0 mb-4">
                <div class="card-header bg-transparent border-bottom fw-semibold py-3">
                    Address &amp; Location
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label for="fStreet" class="form-label fw-medium">Street / House No. <span class="text-danger">*</span></label>
                            <input type="text" name="street" id="fStreet" class="form-control" required
                                   placeholder="e.g. 123 Mabini St."
                                   value="<?= e($_POST['street'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="fRegion" class="form-label fw-medium">Region <span class="text-danger">*</span></label>
                            <select name="region" id="fRegion" class="form-select" required>
                                <option value="">— Select Region —</option>
                                <?php foreach ($addrRegions as $r): ?>
                                <option value="<?= e($r) ?>" <?= $initRegion === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="fProvince" class="form-label fw-medium">Province</label>
                            <select name="province" id="fProvince" class="form-select" disabled>
                                <option value="">— Select Region first —</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="fMunicipality" class="form-label fw-medium">Municipality / City</label>
                            <select name="municipality" id="fMunicipality" class="form-select" disabled>
                                <option value="">— Select Province first —</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="fBarangay" class="form-label fw-medium">Barangay <span class="text-danger">*</span></label>
                            <select name="barangay" id="fBarangay" class="form-select" disabled>
                                <option value="">— Select Municipality first —</option>
                            </select>
                            <input type="text" name="barangay" id="fBarangayText" class="form-control d-none mt-1"
                                   placeholder="Type barangay name"
                                   value="<?= e($_POST['barangay'] ?? '') ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="fZip" class="form-label fw-medium">ZIP</label>
                            <input type="text" name="zip" id="fZip" class="form-control" maxlength="10"
                                   value="<?= e($_POST['zip'] ?? '') ?>">
                        </div>

                        <!-- Map picker -->
                        <div class="col-12">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <div class="form-label fw-medium mb-0">
                                    Pin on Map
                                    <small class="text-muted fw-normal ms-1">— click map or drag pin</small>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-success" id="geocodeBtn">
                                    <i class="bi bi-search me-1"></i>Find from Address
                                </button>
                            </div>
                            <div id="locationMap" style="height:300px;border-radius:8px;border:1px solid #dee2e6;z-index:0;"></div>
                        </div>

                        <div class="col-md-6">
                            <label for="latInput" class="form-label fw-medium small">Latitude</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text"><i class="bi bi-geo"></i></span>
                                <input type="text" name="latitude" id="latInput" class="form-control font-monospace"
                                       inputmode="decimal" placeholder="e.g. 10.31234 or 10.31234, 123.90123"
                                       value="<?= e($_POST['latitude'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="lngInput" class="form-label fw-medium small">Longitude</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text"><i class="bi bi-geo"></i></span>
                                <input type="text" name="longitude" id="lngInput" class="form-control font-monospace"
                                       inputmode="decimal" placeholder="e.g. 123.90123"
                                       value="<?= e($_POST['longitude'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Connection Details -->
            <div class="card border-0 mb-4">
                <div class="card-header bg-transparent border-bottom fw-semibold py-3">
                    Connection Details
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="connType" class="form-label fw-medium">Connection Type <span class="text-danger">*</span></label>
                            <select name="connection_type" class="form-select" id="connType" required>
                                <option value="ppp"     <?= ($_POST['connection_type'] ?? 'ppp') === 'ppp'     ? 'selected' : '' ?>>PPP / PPPoE</option>
                                <option value="hotspot" <?= ($_POST['connection_type'] ?? '') === 'hotspot' ? 'selected' : '' ?>>Hotspot</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="pppUsername" class="form-label fw-medium">
                                <span id="usernameLabel">PPP Username</span><small class="text-muted fw-normal">(= Account # if blank)</small>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
                                <input type="text" name="ppp_username" class="form-control font-monospace"
                                       id="pppUsername" value="<?= e($_POST['ppp_username'] ?? '') ?>"
                                       placeholder="Auto-fills from account number">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="pppPassword" class="form-label fw-medium">
                                <span id="passwordLabel">PPP Password</span>
                                <small class="text-muted fw-normal">(auto-generated if blank)</small>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-key"></i></span>
                                <input type="text" name="ppp_password" class="form-control font-monospace"
                                       id="pppPassword" value="<?= e($_POST['ppp_password'] ?? '') ?>"
                                       placeholder="Leave blank to auto-generate">
                                <button type="button" class="btn btn-outline-secondary" id="genPasswordBtn" title="Generate password">
                                    <i class="bi bi-arrow-repeat"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <?= inlineToasts([['message' => ucfirst($planAccountTerm) . ' is taken from the selected plan. Credentials are auto-synced to the router when status is Active.', 'type' => 'info']]) ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Subscription -->
            <div class="card border-0">
                <div class="card-header bg-transparent border-bottom fw-semibold py-3">
                    Subscription Period
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="subStart" class="form-label fw-medium">Start Date</label>
                            <input type="date" name="subscription_start" class="form-control" id="subStart"
                                   value="<?= e($_POST['subscription_start'] ?? date('Y-m-d')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="subEnd" class="form-label fw-medium">End Date <span class="text-danger">*</span></label>
                            <input type="date" name="subscription_end" class="form-control" id="subEnd" required
                                   value="<?= e($_POST['subscription_end'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label for="fRemarks" class="form-label fw-medium">Remarks</label>
                            <textarea name="remarks" id="fRemarks" class="form-control" rows="2"><?= e($_POST['remarks'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right column -->
        <div class="col-lg-4">

            <!-- Account -->
            <div class="card border-0 mb-4">
                <div class="card-header bg-transparent border-bottom fw-semibold py-3">
                    Account
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="accountNumber" class="form-label fw-medium">Account Number
                            <small class="text-muted fw-normal">(auto if blank)</small>
                        </label>
                        <input type="text" name="account_number" class="form-control font-monospace fw-bold text-danger"
                               id="accountNumber"
                               placeholder="<?= e($nextAccNum) ?>"
                               value="<?= e($_POST['account_number'] ?? '') ?>"
                               style="font-size:1.45rem;letter-spacing:.06em;line-height:1.6;padding:.55rem .75rem;">
                        <div class="form-text mt-2" style="line-height:1.7;">
                            Next auto: <code class="text-danger fw-semibold"><?= e($nextAccNum) ?></code>
                            <span class="text-muted">— username defaults to this.</span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="fStatus" class="form-label fw-medium">Status</label>
                        <select name="status" id="fStatus" class="form-select">
                            <?php foreach ((canManageRouterAccounts() ? ['active','pending','suspended'] : ['active','pending']) as $s): ?>
                            <option value="<?= $s ?>" <?= ($_POST['status'] ?? 'active') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Plan -->
            <div class="card border-0 mb-4">
                <div class="card-header bg-transparent border-bottom fw-semibold py-3">
                    Plan
                </div>
                <div class="card-body">
                    <select name="plan_id" class="form-select" id="planSelect" required>
                        <option value="">— Select Plan — *</option>
                        <?php foreach ($plans as $plan): ?>
                        <option value="<?= $plan['plan_id'] ?>"
                                data-amount="<?= $plan['amount'] ?>"
                                data-speed="<?= $plan['speed_mbps'] ?>"
                                data-cycle="<?= $plan['billing_cycle'] ?>"
                                data-type="<?= $plan['plan_type'] ?>"
                                <?= (int)($_POST['plan_id'] ?? 0) === (int)$plan['plan_id'] ? 'selected' : '' ?>>
                            <?= e($plan['title']) ?> — <?= e($currency) ?><?= number_format($plan['amount'], 2) ?>/<?= $plan['billing_cycle'] ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="planDetails" class="mt-2 small text-muted"></div>
                </div>
            </div>

            <!-- Router (fixed to current selected router) -->
            <div class="card border-0 mb-4">
                <div class="card-header bg-transparent border-bottom fw-semibold py-3">
                    Router
                </div>
                <div class="card-body">
                    <input type="hidden" name="router_id" value="<?= (int)$currentRouterId ?>">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-router text-primary"></i>
                        <span class="fw-medium"><?= e($currentRouterName) ?></span>
                        <?php if ($currentRouterConnected): ?>
                        <span class="badge bg-success-subtle text-success border">Online</span>
                        <?php else: ?>
                        <span class="badge bg-danger-subtle text-danger border"><?= e(ucfirst($currentRouterRow['status'] ?? 'not connected')) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="form-text mt-1">Assigned from currently selected router.</div>
                </div>
            </div>

            <!-- Notifications -->
            <div class="card border-0 mb-4">
                <div class="card-header bg-transparent border-bottom fw-semibold py-3">
                    Notifications
                </div>
                <div class="card-body">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="notify_sms" id="notifySms"
                               <?= !empty($_POST['notify_sms']) && SMS_ENABLED ? 'checked' : '' ?>
                               <?= !SMS_ENABLED ? 'disabled' : '' ?>>
                        <label class="form-check-label" for="notifySms">Send SMS welcome message</label>
                        <?php if (!SMS_ENABLED): ?><div class="form-text">SMS is disabled in configuration.</div><?php endif; ?>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="notify_email" id="notifyEmail"
                               <?= !empty($_POST['notify_email']) && MAIL_ENABLED ? 'checked' : '' ?>
                               <?= !MAIL_ENABLED ? 'disabled' : '' ?>>
                        <label class="form-check-label" for="notifyEmail">Send email with credentials</label>
                        <?php if (!MAIL_ENABLED): ?><div class="form-text">Email is disabled in configuration.</div><?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Initial Payment (expandable) -->
            <div class="card border-0 mb-4">
                <div class="card-header bg-transparent border-bottom py-3">
                    <div class="form-check form-switch mb-0 d-flex align-items-center gap-2">
                        <input class="form-check-input" type="checkbox" name="record_payment"
                               id="recordPaymentToggle" role="switch"
                               <?= !empty($_POST['record_payment']) ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold mb-0" for="recordPaymentToggle">
                            Record Initial Payment
                        </label>
                    </div>
                </div>
                <div id="initialPaymentBody" class="card-body <?= empty($_POST['record_payment']) ? 'd-none' : '' ?>">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-medium" for="payAmount">
                                Amount (<?= e($currency) ?>) <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text fw-bold text-success"><?= e($currency) ?></span>
                                <input type="number" name="pay_amount" id="payAmount"
                                       class="form-control form-control-lg fw-bold"
                                       step="0.01" min="0.01"
                                       value="<?= e($_POST['pay_amount'] ?? '') ?>"
                                       placeholder="0.00">
                            </div>
                        </div>
                        <?php if (count($payTypes) > 1): ?>
                        <div class="col-12">
                            <label class="form-label fw-medium" for="payTypeId">Payment Type</label>
                            <select name="pay_type_id" id="payTypeId" class="form-select">
                                <?php foreach ($payTypes as $pt): ?>
                                <?php if (!$pt['is_active']) continue; ?>
                                <option value="<?= $pt['type_id'] ?>"
                                    <?= (int)($_POST['pay_type_id'] ?? $defTypeId) === (int)$pt['type_id'] ? 'selected' : '' ?>>
                                    <?= e($pt['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php else: ?>
                        <input type="hidden" name="pay_type_id" value="<?= $defTypeId ?>">
                        <?php endif; ?>
                        <div class="col-12">
                            <label class="form-label fw-medium" for="payMethod">Method</label>
                            <select name="pay_method" id="payMethod" class="form-select">
                                <?php foreach (getPaymentMethods() as $val => $label): ?>
                                <option value="<?= e($val) ?>"
                                    <?= ($_POST['pay_method'] ?? 'cash') === $val ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12" id="payRefGroup" style="<?= ($_POST['pay_method'] ?? 'cash') === 'cash' ? 'opacity:.5' : '' ?>">
                            <label class="form-label fw-medium" for="payReference">Reference #</label>
                            <input type="text" name="pay_reference" id="payReference"
                                   class="form-control font-monospace"
                                   placeholder="GCash ref, bank ref…"
                                   value="<?= e($_POST['pay_reference'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium" for="payDate">Payment Date</label>
                            <input type="datetime-local" name="pay_date" id="payDate"
                                   class="form-control"
                                   value="<?= e($_POST['pay_date'] ?? date('Y-m-d\TH:i')) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-medium" for="payPeriodStart">Period Start</label>
                            <input type="date" name="pay_period_start" id="payPeriodStart"
                                   class="form-control"
                                   <?= !hasRole(ROLE_SUPERADMIN) ? 'readonly' : '' ?>
                                   value="<?= e($_POST['pay_period_start'] ?? ($_POST['subscription_start'] ?? date('Y-m-d'))) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-medium" for="payPeriodEnd">Period End</label>
                            <input type="date" name="pay_period_end" id="payPeriodEnd"
                                   class="form-control"
                                   <?= !hasRole(ROLE_SUPERADMIN) ? 'readonly' : '' ?>
                                   value="<?= e($_POST['pay_period_end'] ?? ($_POST['subscription_end'] ?? '')) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium" for="payStatus">Status</label>
                            <select name="pay_status" id="payStatus" class="form-select">
                                <option value="paid" <?= ($_POST['pay_status'] ?? 'paid') === 'paid' ? 'selected' : '' ?>>Paid</option>
                                <option value="pending" <?= ($_POST['pay_status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pending</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium" for="payNotes">Notes</label>
                            <textarea name="pay_notes" id="payNotes" class="form-control" rows="2"
                                      placeholder="Optional…"><?= e($_POST['pay_notes'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary" <?= !$currentRouterConnected ? 'disabled title="Current router is not connected"' : '' ?>>
                    <i class="bi bi-person-plus me-2"></i>Create Subscriber
                </button>
                <a href="./" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </div>
    </div>
</form>

<?php
$plansJson    = json_encode(array_column($plans, null, 'plan_id'));
$initLat      = e($_POST['latitude']  ?? '');
$initLng      = e($_POST['longitude'] ?? '');
$baseUrl      = BASE_URL;
$jsInitRegion = json_encode($initRegion);
$jsInitProv   = json_encode($initProv);
$jsInitMuni   = json_encode($initMuni);
$jsInitBrgy   = json_encode($_POST['barangay'] ?? '');

$extraScripts = <<<JS
<script src="{$baseUrl}/assets/vendor/leaflet/leaflet.js"></script>
<script>
const plans = {$plansJson};

// ── Cascading address selects ─────────────────────────────────
(function () {
    const BASE       = window.BASE_URL || '';
    const INIT_REGION = {$jsInitRegion};
    const INIT_PROV   = {$jsInitProv};
    const INIT_MUNI   = {$jsInitMuni};
    const INIT_BRGY   = {$jsInitBrgy};

    const selRegion  = document.getElementById('fRegion');
    const selProv    = document.getElementById('fProvince');
    const selMuni    = document.getElementById('fMunicipality');
    const selBrgy    = document.getElementById('fBarangay');
    const txtBrgy    = document.getElementById('fBarangayText');
    const inpZip     = document.getElementById('fZip');

    function resetSelect(sel, placeholder, disable) {
        sel.innerHTML = '<option value="">' + placeholder + '</option>';
        sel.disabled = disable;
    }

    function loadOptions(sel, url, placeholder, preselect, onDone) {
        sel.innerHTML = '<option value="">Loading…</option>';
        sel.disabled = true;
        fetch(url)
            .then(r => r.json())
            .then(d => {
                resetSelect(sel, placeholder, false);
                (d.data || []).forEach(v => {
                    const val = typeof v === 'object' ? v.barangay : v;
                    const opt = document.createElement('option');
                    opt.value = val;
                    opt.textContent = val;
                    if (typeof v === 'object') opt.dataset.zip = v.zipcode || '';
                    if (preselect && val === preselect) opt.selected = true;
                    sel.appendChild(opt);
                });
                if (onDone) onDone(d.data || []);
            })
            .catch(() => resetSelect(sel, placeholder, false));
    }

    function showBarangayMode(hasBrgy, preselect) {
        if (hasBrgy) {
            selBrgy.classList.remove('d-none');
            txtBrgy.classList.add('d-none');
            txtBrgy.name = '';
            selBrgy.name = 'barangay';
        } else {
            selBrgy.classList.add('d-none');
            txtBrgy.classList.remove('d-none');
            selBrgy.name = '';
            txtBrgy.name = 'barangay';
            if (preselect) txtBrgy.value = preselect;
        }
    }

    selRegion?.addEventListener('change', function () {
        resetSelect(selProv,  '— Select Region first —',   true);
        resetSelect(selMuni,  '— Select Province first —', true);
        resetSelect(selBrgy,  '— Select Municipality first —', true);
        txtBrgy.value = '';
        showBarangayMode(true);
        if (!this.value) return;
        loadOptions(selProv, BASE + '/api/address.php?level=provinces&p=' + encodeURIComponent(this.value), '— Select Province —', null);
    });

    selProv?.addEventListener('change', function () {
        resetSelect(selMuni, '— Select Province first —', true);
        resetSelect(selBrgy, '— Select Municipality first —', true);
        txtBrgy.value = '';
        showBarangayMode(true);
        if (!this.value) return;
        loadOptions(selMuni, BASE + '/api/address.php?level=municipalities&p=' + encodeURIComponent(this.value), '— Select Municipality —', null);
    });

    selMuni?.addEventListener('change', function () {
        resetSelect(selBrgy, '— Select Municipality first —', true);
        txtBrgy.value = '';
        if (!this.value) return;
        const prov = selProv.value;
        loadOptions(selBrgy, BASE + '/api/address.php?level=barangays&p=' + encodeURIComponent(this.value) + '&prov=' + encodeURIComponent(prov), '— Select Barangay —', null, function (rows) {
            showBarangayMode(rows.length > 0, INIT_BRGY);
            if (rows.length === 0) selBrgy.disabled = true;
        });
    });

    selBrgy?.addEventListener('change', function () {
        const opt = this.options[this.selectedIndex];
        if (opt && opt.dataset.zip) inpZip.value = opt.dataset.zip;

        // Zoom map to selected barangay
        const brgy = this.value;
        const muni = selMuni.value;
        const prov = selProv.value;
        if (!brgy || !muni) return;

        const query = [brgy, muni, prov, 'Philippines'].filter(Boolean).join(', ');
        fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(query),
              { headers: { 'Accept-Language': 'en' } })
            .then(r => r.json())
            .then(data => {
                if (data.length && typeof map !== 'undefined' && map) {
                    map.setView([parseFloat(data[0].lat), parseFloat(data[0].lon)], 14);
                }
            })
            .catch(() => {});
    });

    // Auto-fill province/municipality from router (or re-populate on validation error)
    if (INIT_REGION) {
        loadOptions(selProv, BASE + '/api/address.php?level=provinces&p=' + encodeURIComponent(INIT_REGION), '— Select Province —', INIT_PROV, function () {
            if (!INIT_PROV) return;
            loadOptions(selMuni, BASE + '/api/address.php?level=municipalities&p=' + encodeURIComponent(INIT_PROV), '— Select Municipality —', INIT_MUNI, function () {
                if (!INIT_MUNI) return;
                loadOptions(selBrgy, BASE + '/api/address.php?level=barangays&p=' + encodeURIComponent(INIT_MUNI) + '&prov=' + encodeURIComponent(INIT_PROV), '— Select Barangay —', INIT_BRGY, function (rows) {
                    showBarangayMode(rows.length > 0, INIT_BRGY);
                    if (rows.length === 0) selBrgy.disabled = true;
                });
            });
        });
    }
})();


// ── Username auto-generation: FirstnameLastname-AccountNumber ─
const accInput  = document.getElementById('accountNumber');
const userInput = document.getElementById('pppUsername');
const fnInput   = document.getElementById('fFirstname');
const lnInput   = document.getElementById('fLastname');

function toTitle(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1).toLowerCase() : ''; }

function buildUsername() {
    if (userInput.dataset.manualEdit) return;
    const fn  = (fnInput?.value  || '').trim().replace(/\s+/g, '');
    const ln  = (lnInput?.value  || '').trim().replace(/\s+/g, '');
    const acc = (accInput?.value || '').trim();
    if (fn && ln && acc) {
        userInput.value = toTitle(fn) + toTitle(ln) + '-' + acc;
    } else if (acc) {
        userInput.value = acc;
    }
}

fnInput?.addEventListener('input',  buildUsername);
lnInput?.addEventListener('input',  buildUsername);
accInput?.addEventListener('input', buildUsername);
userInput?.addEventListener('input', function () {
    this.dataset.manualEdit = this.value ? '1' : '';
});

// ── Generate 6-char password ─────────────────────────────────
function genPassword() {
    const chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    let pass = '';
    for (let i = 0; i < 6; i++) pass += chars[Math.floor(Math.random() * chars.length)];
    return pass;
}
document.getElementById('genPasswordBtn')?.addEventListener('click', function () {
    document.getElementById('pppPassword').value = genPassword();
    showToast('Password generated', 'info');
});

// ── Plan → auto end date + payment amount ────────────────────
document.getElementById('planSelect')?.addEventListener('change', function () {
    const plan = plans[this.value];
    const info = document.getElementById('planDetails');
    if (plan) {
        info.innerHTML = `Speed: <strong>\${plan.speed_mbps} Mbps</strong> · \${plan.billing_cycle}`;
        const start = document.getElementById('subStart').value;
        if (start) {
            const months = {monthly:1, quarterly:3, annual:12}[plan.billing_cycle] || 1;
            const d = new Date(start);
            d.setMonth(d.getMonth() + months);
            document.getElementById('subEnd').value = d.toISOString().split('T')[0];
        }
        // Pre-fill payment amount from plan MRC
        const amtEl = document.getElementById('payAmount');
        if (amtEl && !amtEl.dataset.manualEdit) amtEl.value = parseFloat(plan.amount).toFixed(2);
    } else { info.innerHTML = ''; }
});
document.getElementById('subStart')?.addEventListener('change', () =>
    document.getElementById('planSelect')?.dispatchEvent(new Event('change'))
);

// ── Initial Payment card toggle + date sync ──────────────────
(function () {
    const toggle   = document.getElementById('recordPaymentToggle');
    const body     = document.getElementById('initialPaymentBody');
    const pStart   = document.getElementById('payPeriodStart');
    const pEnd     = document.getElementById('payPeriodEnd');
    const subStart = document.getElementById('subStart');
    const subEnd   = document.getElementById('subEnd');
    const payAmt   = document.getElementById('payAmount');
    const payMeth  = document.getElementById('payMethod');
    const payRef   = document.getElementById('payRefGroup');

    toggle?.addEventListener('change', function () {
        body.classList.toggle('d-none', !this.checked);
    });

    // Sync period dates from subscription dates
    function syncPeriodDates() {
        if (subStart?.value && pStart && !pStart.dataset.manualEdit) pStart.value = subStart.value;
        if (subEnd?.value   && pEnd   && !pEnd.dataset.manualEdit)   pEnd.value   = subEnd.value;
    }
    subStart?.addEventListener('change', syncPeriodDates);
    subEnd?.addEventListener('change',   syncPeriodDates);
    pStart?.addEventListener('input',  function () { this.dataset.manualEdit = '1'; });
    pEnd?.addEventListener('input',    function () { this.dataset.manualEdit = '1'; });
    payAmt?.addEventListener('input',  function () { this.dataset.manualEdit = '1'; });

    // Reference field opacity based on method
    payMeth?.addEventListener('change', function () {
        if (payRef) payRef.style.opacity = this.value === 'cash' ? '0.5' : '1';
    });
})();

// ── Connection type toggle ────────────────────────────────────
document.getElementById('connType')?.addEventListener('change', function () {
    const isPpp = this.value === 'ppp';
    document.getElementById('usernameLabel').textContent = isPpp ? 'PPP Username' : 'Hotspot Username';
    document.getElementById('passwordLabel').textContent = isPpp ? 'PPP Password' : 'Hotspot Password';
});

// ── Leaflet Map ───────────────────────────────────────────────
let map, marker;
const latInput = document.getElementById('latInput');
const lngInput = document.getElementById('lngInput');

function initMap() {
    const initLat = parseFloat(latInput.value) || 12.8797;
    const initLng = parseFloat(lngInput.value) || 121.7740;
    const zoom    = latInput.value ? 15 : 6;

    map = L.map('locationMap').setView([initLat, initLng], zoom);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        maxZoom: 19,
    }).addTo(map);

    if (latInput.value && lngInput.value) placeMarker(initLat, initLng, false);

    map.on('click', function (e) {
        placeMarker(e.latlng.lat, e.latlng.lng, true);
    });
}

function placeMarker(lat, lng, updateInputs) {
    if (marker) {
        marker.setLatLng([lat, lng]);
    } else {
        marker = L.marker([lat, lng], {draggable: true}).addTo(map);
        marker.on('dragend', function (e) {
            const ll = e.target.getLatLng();
            setCoords(ll.lat, ll.lng);
        });
    }
    if (updateInputs) setCoords(lat, lng);
}

function setCoords(lat, lng) {
    latInput.value = lat.toFixed(8);
    lngInput.value = lng.toFixed(8);
}

function parseCombinedCoordinates(value) {
    const match = String(value || '').trim().match(/^\s*(-?\d+(?:\.\d+)?)\s*[,;\s]\s*(-?\d+(?:\.\d+)?)\s*$/);
    if (!match) return false;

    const lat = parseFloat(match[1]);
    const lng = parseFloat(match[2]);
    if (Number.isNaN(lat) || Number.isNaN(lng) || lat < -90 || lat > 90 || lng < -180 || lng > 180) {
        return false;
    }

    setCoords(lat, lng);
    if (map) {
        placeMarker(lat, lng, false);
        map.setView([lat, lng], 15);
    }
    return true;
}

latInput?.addEventListener('paste', function () {
    setTimeout(() => {
        if (parseCombinedCoordinates(latInput.value)) {
            showToast('Coordinates split into latitude and longitude.', 'success');
        }
    }, 0);
});

latInput?.addEventListener('input', function () {
    parseCombinedCoordinates(this.value);
});

// Manual lat/lng → move marker
[latInput, lngInput].forEach(el => {
    el?.addEventListener('change', function () {
        parseCombinedCoordinates(latInput.value);
        const lat = parseFloat(latInput.value);
        const lng = parseFloat(lngInput.value);
        if (!isNaN(lat) && !isNaN(lng)) {
            placeMarker(lat, lng, false);
            map.setView([lat, lng], 15);
        }
    });
});

// Geocode from address fields
document.getElementById('geocodeBtn')?.addEventListener('click', function () {
    const brgy = document.getElementById('fBarangayText')?.classList.contains('d-none')
        ? document.getElementById('fBarangay')?.value
        : document.getElementById('fBarangayText')?.value;
    const parts = [
        document.getElementById('fStreet')?.value,
        brgy,
        document.getElementById('fMunicipality')?.value,
        document.getElementById('fProvince')?.value,
        'Philippines',
    ].filter(Boolean);

    if (parts.filter(p => p !== 'Philippines').length < 1) {
        showToast('Fill at least one address field first.', 'warning');
        return;
    }

    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Searching…';

    fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(parts.join(', ')),
          { headers: { 'Accept-Language': 'en' } })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-search me-1"></i>Find from Address';
            if (data.length) {
                const lat = parseFloat(data[0].lat);
                const lng = parseFloat(data[0].lon);
                placeMarker(lat, lng, true);
                map.setView([lat, lng], 15);
                showToast('Found: ' + data[0].display_name.split(',').slice(0,3).join(','), 'success');
            } else {
                showToast('Address not found — try being more specific.', 'warning');
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-search me-1"></i>Find from Address';
            showToast('Geocoding request failed.', 'danger');
        });
});

document.addEventListener('DOMContentLoaded', initMap);

document.getElementById('addSubForm').addEventListener('submit', function (e) {
    const brgyActive = document.getElementById('fBarangayText').classList.contains('d-none')
        ? document.getElementById('fBarangay').value.trim()
        : document.getElementById('fBarangayText').value.trim();
    if (!brgyActive) {
        e.preventDefault();
        showToast('Barangay is required. Please select or type a barangay.', 'danger');
        document.getElementById('fBarangay').closest('.col-md-4').scrollIntoView({behavior:'smooth', block:'center'});
    }
});
</script>
JS;

include BASE_PATH . '/includes/footer.php';
