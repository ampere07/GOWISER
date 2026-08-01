<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once BASE_PATH . '/lib/RouterosAPI.php';
requireRole(ROLE_CASHIER, ROLE_ADMIN, ROLE_SUPERADMIN);

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/subscribers/');

$selectedRouterId = selectedRouterId();
$scopeSql = '';
$scopeParams = [];
if (currentRole() !== ROLE_SUPERADMIN || $selectedRouterId) {
    if (!$selectedRouterId) {
        flashMessage('danger', 'Router not selected.');
        redirect(BASE_URL . '/modules/subscribers/');
    }
    $scopeSql = ' AND router_id = ?';
    $scopeParams[] = $selectedRouterId;
}

$sub = db()->prepare("SELECT * FROM subscribers WHERE subscriber_id = ?{$scopeSql}");
$sub->execute(array_merge([$id], $scopeParams));
$subscriber = $sub->fetch();
if (!$subscriber) { flashMessage('danger', 'Subscriber not found.'); redirect(BASE_URL . '/modules/subscribers/'); }
$originalSubscriber = $subscriber;

$errors = [];
$canEditProtectedSubscriberFields = canEditSubscriberProtectedFields($originalSubscriber);
$canChangeSubscriberStatus = canManageRouterAccounts();
$canChangeExpiredSubscriberPlan = hasRole(ROLE_CASHIER, ROLE_ADMIN, ROLE_SUPERADMIN)
    && subscriberSubscriptionExpired($originalSubscriber);
$isActiveSub = ($originalSubscriber['status'] === SUB_STATUS_ACTIVE);

// Plans scoped to subscriber's router (plus universal plans with NULL router)
$subRouterId = (int)($subscriber['router_id'] ?? 0);
if ($subRouterId) {
    $pStmt = db()->prepare("SELECT * FROM plans WHERE is_active = 1 AND ppp_profile IS NOT NULL AND ppp_profile <> '' AND (router_id = ? OR router_id IS NULL) ORDER BY amount");
    $pStmt->execute([$subRouterId]);
    $plans = $pStmt->fetchAll();
} else {
    $plans = db()->query("SELECT * FROM plans WHERE is_active = 1 AND ppp_profile IS NOT NULL AND ppp_profile <> '' ORDER BY amount")->fetchAll();
}

// Router display info
$subRouterName = 'None';
$subRouterStatus = null;
$subRouterConnected = false;
$subRouterIsRadius = false;
$routerAddrRegion = '';
$routerAddrProv   = '';
$routerAddrMuni   = '';
if ($subRouterId) {
    $rInfo = db()->prepare("SELECT name, host, status, COALESCE(auth_type, 'local') AS auth_type, region, province, municipality FROM routers WHERE router_id = ?");
    $rInfo->execute([$subRouterId]);
    $rInfo = $rInfo->fetch();
    if ($rInfo) {
        $subRouterName    = e($rInfo['name']) . ' (' . e($rInfo['host']) . ')';
        $subRouterStatus  = $rInfo['status'] ?? null;
        $subRouterConnected = $subRouterStatus === ROUTER_ONLINE;
        $subRouterIsRadius  = ($rInfo['auth_type'] ?? 'local') === 'radius';
        $routerAddrRegion = $rInfo['region']       ?? '';
        $routerAddrProv   = $rInfo['province']     ?? '';
        $routerAddrMuni   = $rInfo['municipality'] ?? '';
    }
}
$planAccountTerm = $subRouterIsRadius ? 'User Manager group' : 'MikroTik profile';

$addrRegions = db()->query("SELECT DISTINCT region FROM address ORDER BY region")->fetchAll(PDO::FETCH_COLUMN);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $data = [
        'firstname'       => mb_strtoupper(trim($_POST['firstname']    ?? '')),
        'lastname'        => mb_strtoupper(trim($_POST['lastname']   ?? '')),
        'middlename'      => mb_strtoupper(trim($_POST['middlename'] ?? '')),
        // Address fields
        'street'          => titleCase($_POST['street']     ?? ''),
        'barangay'        => titleCase($_POST['barangay']   ?? ''),
        'municipality'    => titleCase($_POST['municipality'] ?? ''),
        'province'        => titleCase($_POST['province']   ?? ''),
        'region'          => trim($_POST['region']          ?? ''),
        'zip'             => trim($_POST['zip']             ?? ''),
        'longitude'       => trim($_POST['longitude']       ?? ''),
        'latitude'        => trim($_POST['latitude']        ?? ''),
        'contact_number'  => trim($_POST['contact_number']  ?? ''),
        'email'           => trim($_POST['email']           ?? ''),
        'connection_type' => $_POST['connection_type']      ?? $subscriber['connection_type'],
        'ppp_username'    => trim($_POST['ppp_username']    ?? ''),
        'plan_id'         => (int)($_POST['plan_id']        ?? 0) ?: null,
        'router_id'       => (int)($_POST['router_id']      ?? 0) ?: null,
        'subscription_start' => $_POST['subscription_start'] ?? '',
        'subscription_end'   => $_POST['subscription_end']   ?? '',
        'status'          => $_POST['status']               ?? $subscriber['status'],
        'remarks'         => trim($_POST['remarks']         ?? ''),
    ];

    if (!$canEditProtectedSubscriberFields) {
        $data['firstname'] = $originalSubscriber['firstname'];
        $data['lastname'] = $originalSubscriber['lastname'];
        $data['subscription_start'] = $originalSubscriber['subscription_start'];
        $data['subscription_end'] = $originalSubscriber['subscription_end'];
    }

    if ($isActiveSub) {
        $data['connection_type'] = $originalSubscriber['connection_type'];
        $data['ppp_username']    = $originalSubscriber['ppp_username'] ?? '';
        if (!hasRole(ROLE_SUPERADMIN)) {
            $data['plan_id']            = $originalSubscriber['plan_id'];
            $data['subscription_start'] = $originalSubscriber['subscription_start'];
            $data['subscription_end']   = $originalSubscriber['subscription_end'];
        }
    }

    if (!$canChangeSubscriberStatus) {
        $data['status'] = $originalSubscriber['status'];
    } else {
        $allowedStatuses = ['active', 'pending', 'suspended'];
        $isPreservingExpiredStatus = ($originalSubscriber['status'] ?? '') === SUB_STATUS_EXPIRED
            && $data['status'] === SUB_STATUS_EXPIRED;
        if (!$isPreservingExpiredStatus && !in_array($data['status'], $allowedStatuses, true)) {
            $data['status'] = $originalSubscriber['status'] ?? SUB_STATUS_ACTIVE;
        }
    }

    $isExpiredPlanChange = $canChangeExpiredSubscriberPlan
        && (int)$data['plan_id'] !== (int)($originalSubscriber['plan_id'] ?? 0);

    if ((!$data['router_id'] || !routerIsConnected((int)$data['router_id'])) && !$isExpiredPlanChange) {
        $errors[] = 'Cannot update this subscriber because the assigned router is not connected.';
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

    // Password update (optional)
    $newPlainPassword  = trim($_POST['new_ppp_password'] ?? '');
    $encryptedPassword = $subscriber['ppp_password'];
    if ($newPlainPassword !== '') {
        $encryptedPassword = encryptData($newPlainPassword);
    }

    // Build combined address
    $parts = array_filter([$data['street'], $data['barangay'], $data['municipality'], $data['province'], $data['region']]);
    $combinedAddress = implode(', ', $parts);

    // Derive ppp_profile from plan
    $planProfile = $subscriber['ppp_profile'] ?? '';
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
        $old = $subscriber;
        db()->prepare("
            UPDATE subscribers SET
                firstname=?, lastname=?, middlename=?,
                address=?, street=?, barangay=?, municipality=?, province=?, region=?, zip=?,
                longitude=?, latitude=?,
                contact_number=?, email=?,
                connection_type=?, ppp_profile=?, ppp_username=?, ppp_password=?,
                plan_id=?, router_id=?,
                subscription_start=?, subscription_end=?, status=?, remarks=?,
                updated_at=?
            WHERE subscriber_id=? AND (router_id <=> ?)
        ")->execute([
            $data['firstname'], $data['lastname'],
            $data['middlename'] ?: null,
            $combinedAddress    ?: null,
            $data['street']     ?: null,
            $data['barangay']   ?: null,
            $data['municipality'] ?: null,
            $data['province']   ?: null,
            $data['region']     ?: null,
            $data['zip']        ?: null,
            $data['longitude'] !== '' ? (float)$data['longitude'] : null,
            $data['latitude']  !== '' ? (float)$data['latitude']  : null,
            $data['contact_number'] ? sanitizePhone($data['contact_number']) : null,
            $data['email'] ?: null,
            $data['connection_type'],
            $planProfile ?: null,
            $data['ppp_username'] ?: null,
            $encryptedPassword,
            $data['plan_id'], $data['router_id'],
            $data['subscription_start'] ?: null,
            $data['subscription_end']   ?: null,
            $data['status'],
            $data['remarks'] ?: null,
            appNow(), $id, $originalSubscriber['router_id'] ?? null,
        ]);

        logActivity('subscribers', 'update',
            "Updated subscriber {$subscriber['account_number']}", $id, $old, $data
        );

        // ── Router sync ───────────────────────────────────────
        if (canManageRouterAccounts()) {
        $oldRouterId = (int)($old['router_id'] ?? 0);
        $newRouterId = (int)($data['router_id'] ?? 0);
        $oldUname    = $old['ppp_username'] ?? '';
        $newUname    = $data['ppp_username'] ?: $oldUname;
        $syncPass    = $newPlainPassword !== '' ? $newPlainPassword : decryptData($old['ppp_password'] ?? '');
        $profile     = $planProfile ?: 'default';
        $disabled    = $data['status'] !== 'active';
        $connType    = $data['connection_type'];

        // Clean up old router if router assignment changed
        if ($oldRouterId && $oldRouterId !== $newRouterId && $oldUname) {
            $rStmt = db()->prepare("SELECT * FROM routers WHERE router_id = ?");
            $rStmt->execute([$oldRouterId]);
            $oldRouter = $rStmt->fetch();
            if ($oldRouter && ($oldRouter['status'] ?? '') === ROUTER_ONLINE) {
                try {
                    $apiOld = new RouterosAPI($oldRouter['host'], (int)($oldRouter['api_port'] ?: $oldRouter['port']), 3);
                    if ($apiOld->connect($oldRouter['username'], decryptData($oldRouter['password']))) {
                        $oldAuthType = $oldRouter['auth_type'] ?? 'local';
                        if ($oldAuthType === 'radius') {
                            $apiOld->removeUserManagerUser($oldUname);
                        } elseif ($old['connection_type'] === 'ppp') {
                            $apiOld->removePPPSecret($oldUname);
                        } else {
                            $apiOld->removeHotspotUser($oldUname);
                        }
                        $apiOld->disconnect();
                    }
                } catch (Exception $e) { /* non-fatal */ }
            }
        }

        // Sync to current (new) router
        if ($newRouterId && $newUname) {
            $rStmt = db()->prepare("SELECT * FROM routers WHERE router_id = ?");
            $rStmt->execute([$newRouterId]);
            $routerRow = $rStmt->fetch();
            if ($routerRow && ($routerRow['status'] ?? '') === ROUTER_ONLINE) {
                try {
                    $api      = new RouterosAPI($routerRow['host'], (int)($routerRow['api_port'] ?: $routerRow['port']), 5);
                    $authType = $routerRow['auth_type'] ?? 'local';
                    if ($api->connect($routerRow['username'], decryptData($routerRow['password']))) {
                        if ($authType === 'radius') {
                            $existing = $api->getUserManagerUser($oldUname ?: $newUname);
                            if (empty($existing)) {
                                $api->addUserManagerUser(['name' => $newUname, 'password' => $syncPass, 'group' => $profile]);
                            } else {
                                $fields = ['.id' => $existing['.id'], 'password' => $syncPass, 'group' => $profile, 'disabled' => $disabled ? 'yes' : 'no'];
                                if ($oldUname && $oldUname !== $newUname) $fields['name'] = $newUname;
                                // umPrefix() auto-detects /user-manager (ROS v7) vs /tool/user-manager (ROS v6)
                                $api->query($api->umPrefix() . '/user/set', $fields);
                            }
                        } elseif ($connType === 'ppp') {
                            $lookup = ($oldRouterId === $newRouterId) ? ($oldUname ?: $newUname) : $newUname;
                            $rows   = $api->query('/ppp/secret/print', [], ['?name=' . $lookup]);
                            if (empty($rows[0]['.id'])) {
                                $api->addPPPSecret(['name' => $newUname, 'password' => $syncPass, 'profile' => $profile, 'service' => 'pppoe', 'disabled' => $disabled ? 'yes' : 'no']);
                            } else {
                                $fields = ['.id' => $rows[0]['.id'], 'password' => $syncPass, 'profile' => $profile, 'disabled' => $disabled ? 'yes' : 'no'];
                                if ($oldUname && $oldUname !== $newUname) $fields['name'] = $newUname;
                                $api->query('/ppp/secret/set', $fields);
                                if ($disabled) {
                                    $active = $api->query('/ppp/active/print', [], ['?name=' . ($oldUname ?: $newUname)]);
                                    if (!empty($active[0]['.id'])) $api->disconnectPPPSession($active[0]['.id']);
                                }
                            }
                        } else {
                            $lookup = ($oldRouterId === $newRouterId) ? ($oldUname ?: $newUname) : $newUname;
                            $rows   = $api->query('/ip/hotspot/user/print', [], ['?name=' . $lookup]);
                            if (empty($rows[0]['.id'])) {
                                $api->addHotspotUser(['name' => $newUname, 'password' => $syncPass, 'profile' => $profile, 'disabled' => $disabled ? 'yes' : 'no']);
                            } else {
                                $fields = ['.id' => $rows[0]['.id'], 'password' => $syncPass, 'profile' => $profile, 'disabled' => $disabled ? 'yes' : 'no'];
                                if ($oldUname && $oldUname !== $newUname) $fields['name'] = $newUname;
                                $api->query('/ip/hotspot/user/set', $fields);
                                if ($disabled) {
                                    $api->disconnectHotspotSession($oldUname ?: $newUname);
                                }
                            }
                        }
                        $api->disconnect();
                    }
                } catch (Exception $e) { /* non-fatal — router may be offline */ }
            }
        }
        }
        // ── End router sync ───────────────────────────────────

        flashMessage('success', 'Subscriber updated successfully.');
        redirect(BASE_URL . '/modules/subscribers/view/' . $id);
    }
}

// Merge POST back on error
if (!empty($_POST)) {
    $subscriber = array_merge($subscriber, $_POST);
    if (!$canEditProtectedSubscriberFields) {
        foreach (['firstname', 'lastname', 'subscription_start', 'subscription_end'] as $lockedField) {
            $subscriber[$lockedField] = $originalSubscriber[$lockedField] ?? null;
        }
    }
    if ($isActiveSub) {
        foreach (['connection_type', 'ppp_username'] as $lockedField) {
            $subscriber[$lockedField] = $originalSubscriber[$lockedField] ?? null;
        }
        if (!hasRole(ROLE_SUPERADMIN)) {
            foreach (['plan_id', 'subscription_start', 'subscription_end'] as $lockedField) {
                $subscriber[$lockedField] = $originalSubscriber[$lockedField] ?? null;
            }
        }
    }
    if (!$canChangeSubscriberStatus) {
        $subscriber['status'] = $originalSubscriber['status'] ?? $subscriber['status'];
    }
}

$currency    = getSetting('currency_symbol', '₱');
$pageTitle   = 'Edit Subscriber';
$breadcrumbs = [
    ['label' => 'Subscribers', 'url' => BASE_URL . '/modules/subscribers/'],
    ['label' => $subscriber['account_number'], 'url' => BASE_URL . '/modules/subscribers/view/' . $id],
    ['label' => 'Edit'],
];
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
    <h4 class="fw-bold mb-0">Edit Subscriber — <?= e($subscriber['account_number']) ?></h4>
    <a href="<?= BASE_URL ?>/modules/subscribers/view/<?= $id ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>

<?= inlineToasts($errors) ?>

<?php if (!$subRouterConnected && !$canChangeExpiredSubscriberPlan): ?>
<?= inlineToasts([['message' => 'Assigned router is not connected. Subscriber updates are disabled until this router is online.', 'type' => 'warning']]) ?>
<?php endif; ?>
<?php if (!$subRouterConnected && $canChangeExpiredSubscriberPlan): ?>
<?= inlineToasts([['message' => 'Expired account plan changes are allowed. Other router actions still require the assigned router to be online.', 'type' => 'info']]) ?>
<?php endif; ?>

<form method="POST" novalidate>
    <?= csrfField() ?>
    <div class="row g-4">
        <div class="col-lg-8">
            <!-- Personal Info -->
            <div class="card border-0 mb-4">
                <div class="card-header bg-transparent border-bottom fw-semibold py-3">
                    Personal Information
                </div>
                <div class="card-body">
                    <?php if (!$canEditProtectedSubscriberFields && !$isActiveSub): ?>
                    <?= inlineToasts([['message' => 'First name, last name, and subscription dates are locked after the first 10 minutes for Admin and Cashier roles.', 'type' => 'warning']]) ?>
                    <?php endif; ?>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-medium" for="subFirstname">First Name <span class="text-danger">*</span></label>
                            <input type="text" name="firstname" id="subFirstname" class="form-control text-uppercase" required
                                   value="<?= e($subscriber['firstname']) ?>"
                                   <?= !$canEditProtectedSubscriberFields ? 'readonly' : '' ?>
                                   autocapitalize="characters" spellcheck="false">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium" for="subLastname">Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="lastname" id="subLastname" class="form-control text-uppercase" required
                                   value="<?= e($subscriber['lastname']) ?>"
                                   <?= !$canEditProtectedSubscriberFields ? 'readonly' : '' ?>
                                   autocapitalize="characters" spellcheck="false">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium" for="subMiddlename">Middle Name</label>
                            <input type="text" name="middlename" id="subMiddlename" class="form-control text-uppercase"
                                   value="<?= e($subscriber['middlename'] ?? '') ?>"
                                   autocapitalize="characters" spellcheck="false">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="subContact">Contact Number</label>
                            <input type="tel" name="contact_number" id="subContact" class="form-control"
                                   value="<?= e($subscriber['contact_number'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="subEmail">Email</label>
                            <input type="email" name="email" id="subEmail" class="form-control"
                                   value="<?= e($subscriber['email'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Address -->
            <div class="card border-0 mb-4">
                <div class="card-header bg-transparent border-bottom fw-semibold py-3">
                    Address
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-medium" for="fStreet">Street / House No. <span class="text-danger">*</span></label>
                            <input type="text" name="street" id="fStreet" class="form-control" required
                                   value="<?= e($subscriber['street'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="fRegion">Region <span class="text-danger">*</span></label>
                            <select name="region" id="fRegion" class="form-select" required>
                                <option value="">— Select Region —</option>
                                <?php
                                $displayRegion = ($subscriber['region'] ?? '') ?: $routerAddrRegion;
                                foreach ($addrRegions as $r): ?>
                                <option value="<?= e($r) ?>" <?= $displayRegion === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="fProvince">Province</label>
                            <select name="province" id="fProvince" class="form-select" disabled>
                                <option value="">— Select Region first —</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="fMunicipality">Municipality / City</label>
                            <select name="municipality" id="fMunicipality" class="form-select" disabled>
                                <option value="">— Select Province first —</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium" for="fBarangay">Barangay <span class="text-danger">*</span></label>
                            <select name="barangay" id="fBarangay" class="form-select" disabled>
                                <option value="">— Select Municipality first —</option>
                            </select>
                            <input type="text" name="barangay" id="fBarangayText" class="form-control d-none mt-1"
                                   placeholder="Type barangay name"
                                   value="<?= e($subscriber['barangay'] ?? '') ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-medium" for="fZip">ZIP</label>
                            <input type="text" name="zip" id="fZip" class="form-control" maxlength="10"
                                   value="<?= e($subscriber['zip'] ?? '') ?>">
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
                            <label class="form-label fw-medium small" for="latInput">Latitude</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text"><i class="bi bi-geo"></i></span>
                                <input type="text" name="latitude" id="latInput" class="form-control font-monospace"
                                       inputmode="decimal" placeholder="e.g. 10.31234 or 10.31234, 123.90123"
                                       value="<?= e($subscriber['latitude'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium small" for="lngInput">Longitude</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text"><i class="bi bi-geo"></i></span>
                                <input type="text" name="longitude" id="lngInput" class="form-control font-monospace"
                                       inputmode="decimal" placeholder="e.g. 123.90123"
                                       value="<?= e($subscriber['longitude'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Connection -->
            <div class="card border-0 mb-4">
                <div class="card-header bg-transparent border-bottom fw-semibold py-3">
                    Connection
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="subConnType">Connection Type</label>
                            <select name="connection_type" id="subConnType" class="form-select" <?= $isActiveSub ? 'disabled' : '' ?>>
                                <option value="ppp"     <?= $subscriber['connection_type'] === 'ppp'     ? 'selected' : '' ?>>PPP / PPPoE</option>
                                <option value="hotspot" <?= $subscriber['connection_type'] === 'hotspot' ? 'selected' : '' ?>>Hotspot</option>
                            </select>
                            <?php if ($isActiveSub): ?>
                            <input type="hidden" name="connection_type" value="<?= e($originalSubscriber['connection_type']) ?>">
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-text text-muted small"><?= ucfirst($planAccountTerm) ?> comes from the assigned plan.<?= $subscriber['ppp_profile'] ? ' Current: <strong>' . e($subscriber['ppp_profile']) . '</strong>.' : '' ?></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="subPppUsername">Username</label>
                            <input type="text" name="ppp_username" id="subPppUsername" class="form-control font-monospace"
                                   value="<?= e($subscriber['ppp_username'] ?? '') ?>"
                                   <?= $isActiveSub ? 'readonly' : '' ?>>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="newPassInput">New Password
                                <small class="text-muted fw-normal">(blank = keep current)</small>
                            </label>
                            <div class="input-group">
                                <input type="text" name="new_ppp_password" class="form-control font-monospace"
                                       id="newPassInput" placeholder="Leave blank to keep current">
                                <button type="button" class="btn btn-outline-secondary" id="genPassBtn" title="Generate">
                                    <i class="bi bi-arrow-repeat"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Subscription -->
            <div class="card border-0">
                <div class="card-header bg-transparent border-bottom fw-semibold py-3">
                    Subscription
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="subStart">Start Date</label>
                            <input type="date" name="subscription_start" id="subStart" class="form-control"
                                   <?= (!$canEditProtectedSubscriberFields || ($isActiveSub && !hasRole(ROLE_SUPERADMIN))) ? 'readonly' : '' ?>
                                   value="<?= e($subscriber['subscription_start'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="subEnd">End Date <span class="text-danger">*</span></label>
                            <input type="date" name="subscription_end" id="subEnd" class="form-control" required
                                   <?= (!$canEditProtectedSubscriberFields || ($isActiveSub && !hasRole(ROLE_SUPERADMIN))) ? 'readonly' : '' ?>
                                   value="<?= e($subscriber['subscription_end'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium" for="subRemarks">Remarks</label>
                            <textarea name="remarks" id="subRemarks" class="form-control" rows="2"><?= e($subscriber['remarks'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 mb-4">
                <div class="card-header bg-transparent border-bottom fw-semibold py-3">
                    Account
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-medium" for="subAccountNum">Account Number</label>
                        <input type="text" id="subAccountNum" class="form-control font-monospace"
                               value="<?= e($subscriber['account_number']) ?>" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium" for="subStatus">Status</label>
                        <?php if (($subscriber['status'] ?? '') === SUB_STATUS_EXPIRED): ?>
                        <input type="text" id="subStatus" class="form-control" value="Expired" disabled>
                        <input type="hidden" name="status" value="<?= e(SUB_STATUS_EXPIRED) ?>">
                        <div class="form-text">Expired status is automatic based on the subscription end date.</div>
                        <?php else: ?>
                        <select name="status" id="subStatus" class="form-select" <?= !$canChangeSubscriberStatus ? 'disabled' : '' ?>>
                            <?php foreach (['active','pending','suspended'] as $s): ?>
                            <option value="<?= $s ?>" <?= $subscriber['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$canChangeSubscriberStatus): ?>
                        <input type="hidden" name="status" value="<?= e($subscriber['status']) ?>">
                        <div class="form-text">Only Superadmin and Admin can suspend or reactivate accounts.</div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card border-0 mb-4">
                <div class="card-header bg-transparent border-bottom fw-semibold py-3">
                    Plan
                </div>
                <div class="card-body">
                    <select name="plan_id" class="form-select" required <?= ($isActiveSub && !hasRole(ROLE_SUPERADMIN)) ? 'disabled' : '' ?>>
                        <option value="">— Select Plan — *</option>
                        <?php foreach ($plans as $plan): ?>
                        <option value="<?= $plan['plan_id'] ?>"
                                <?= (int)$subscriber['plan_id'] === (int)$plan['plan_id'] ? 'selected' : '' ?>>
                            <?= e($plan['title']) ?> — <?= e($currency) ?><?= number_format($plan['amount'], 2) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($isActiveSub && !hasRole(ROLE_SUPERADMIN)): ?>
                    <input type="hidden" name="plan_id" value="<?= (int)($originalSubscriber['plan_id'] ?? 0) ?>">
                    <?php endif; ?>
                </div>
            </div>

            <div class="card border-0 mb-4">
                <div class="card-header bg-transparent border-bottom fw-semibold py-3">
                    Router
                </div>
                <div class="card-body">
                    <input type="hidden" name="router_id" value="<?= $subRouterId ?>">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-router text-primary"></i>
                        <span class="fw-medium"><?= $subRouterName ?></span>
                        <?php if ($subRouterConnected): ?>
                        <span class="badge bg-success-subtle text-success border">Online</span>
                        <?php else: ?>
                        <span class="badge bg-danger-subtle text-danger border"><?= e(ucfirst($subRouterStatus ?? 'not connected')) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="form-text mt-1">Router is fixed for this subscriber.</div>
                </div>
            </div>

            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary" <?= (!$subRouterConnected && !$canChangeExpiredSubscriberPlan) ? 'disabled title="Router is not connected"' : '' ?>>
                    <i class="bi bi-check-lg me-2"></i>Save Changes
                </button>
                <a href="<?= BASE_URL ?>/modules/subscribers/view/<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </div>
    </div>
</form>

<?php
$baseUrl      = BASE_URL;
$extraScripts = '<script src="' . $baseUrl . '/assets/vendor/leaflet/leaflet.js"></script>' . "\n" . <<<'JS'
<script>
// Generate 6-char password
document.getElementById('genPassBtn')?.addEventListener('click', function () {
    const chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    let pass = '';
    for (let i = 0; i < 6; i++) pass += chars[Math.floor(Math.random() * chars.length)];
    document.getElementById('newPassInput').value = pass;
    showToast('Password generated', 'info');
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

    map.on('click', function (e) { placeMarker(e.latlng.lat, e.latlng.lng, true); });
}

function placeMarker(lat, lng, updateInputs) {
    if (marker) { marker.setLatLng([lat, lng]); }
    else {
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

[latInput, lngInput].forEach(el => {
    el?.addEventListener('change', function () {
        parseCombinedCoordinates(latInput.value);
        const lat = parseFloat(latInput.value);
        const lng = parseFloat(lngInput.value);
        if (!isNaN(lat) && !isNaN(lng)) { placeMarker(lat, lng, false); map.setView([lat, lng], 15); }
    });
});

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
        showToast('Fill at least one address field first.', 'warning'); return;
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
            } else { showToast('Address not found — try being more specific.', 'warning'); }
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-search me-1"></i>Find from Address';
            showToast('Geocoding request failed.', 'danger');
        });
});

document.addEventListener('DOMContentLoaded', initMap);

document.querySelector('form[method="POST"]').addEventListener('submit', function (e) {
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

// ── Cascade address JS with pre-populated values ──────────────
// Fallback to router's location if subscriber has none saved
$subRegion  = $subscriber['region']       ?? '';
$subProv    = $subscriber['province']     ?? '';
$subMuni    = $subscriber['municipality'] ?? '';
$jsRegion   = json_encode($subRegion  ?: $routerAddrRegion);
$jsProvince = json_encode($subProv    ?: ($subRegion  ? '' : $routerAddrProv));
$jsMuni     = json_encode($subMuni    ?: ($subRegion  ? '' : $routerAddrMuni));
$jsBrgy     = json_encode($subscriber['barangay'] ?? '');
$baseUrl    = BASE_URL;

$extraScripts .= <<<JS
<script>
(function () {
    const BASE        = '{$baseUrl}';
    const INIT_REGION = {$jsRegion};
    const INIT_PROV   = {$jsProvince};
    const INIT_MUNI   = {$jsMuni};
    const INIT_BRGY   = {$jsBrgy};

    const selRegion = document.getElementById('fRegion');
    const selProv   = document.getElementById('fProvince');
    const selMuni   = document.getElementById('fMunicipality');
    const selBrgy   = document.getElementById('fBarangay');
    const txtBrgy   = document.getElementById('fBarangayText');
    const inpZip    = document.getElementById('fZip');

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

    // Live cascade events
    selRegion?.addEventListener('change', function () {
        resetSelect(selProv, '— Select Region first —',       true);
        resetSelect(selMuni, '— Select Province first —',     true);
        resetSelect(selBrgy, '— Select Municipality first —', true);
        txtBrgy.value = '';
        showBarangayMode(true);
        if (!this.value) return;
        loadOptions(selProv, BASE + '/api/address.php?level=provinces&p=' + encodeURIComponent(this.value), '— Select Province —', null);
    });

    selProv?.addEventListener('change', function () {
        resetSelect(selMuni, '— Select Province first —',     true);
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
    });

    // Pre-populate from existing subscriber data (chain-load on page init)
    if (INIT_REGION) {
        loadOptions(selProv, BASE + '/api/address.php?level=provinces&p=' + encodeURIComponent(INIT_REGION), '— Select Province —', INIT_PROV, function () {
            if (!INIT_PROV) return;
            loadOptions(selMuni, BASE + '/api/address.php?level=municipalities&p=' + encodeURIComponent(INIT_PROV), '— Select Municipality —', INIT_MUNI, function () {
                if (!INIT_MUNI) return;
                const prov = INIT_PROV;
                loadOptions(selBrgy, BASE + '/api/address.php?level=barangays&p=' + encodeURIComponent(INIT_MUNI) + '&prov=' + encodeURIComponent(prov), '— Select Barangay —', INIT_BRGY, function (rows) {
                    showBarangayMode(rows.length > 0, INIT_BRGY);
                    if (rows.length === 0) selBrgy.disabled = true;
                });
            });
        });
    }
})();
</script>
JS;

include BASE_PATH . '/includes/footer.php';
