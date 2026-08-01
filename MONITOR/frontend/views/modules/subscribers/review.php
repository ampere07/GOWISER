<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once BASE_PATH . '/lib/Mailer.php';
require_once BASE_PATH . '/lib/SMS.php';
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
    $scopeSql = ' AND s.router_id = ?';
    $scopeParams[] = $selectedRouterId;
}

$subStmt = db()->prepare("
    SELECT s.*, p.title AS plan_title, p.amount AS plan_amount, p.ppp_profile AS plan_ppp_profile,
           r.name AS router_name, r.host AS router_host, r.status AS router_status,
           r.api_port, r.port AS router_port, r.username AS r_user, r.password AS r_pass,
           COALESCE(r.auth_type, 'local') AS auth_type
    FROM subscribers s
    LEFT JOIN plans p ON p.plan_id = s.plan_id
    LEFT JOIN routers r ON r.router_id = s.router_id
    WHERE s.subscriber_id = ?{$scopeSql}
");
$subStmt->execute(array_merge([$id], $scopeParams));
$subscriber = $subStmt->fetch();
if (!$subscriber) {
    flashMessage('danger', 'Subscriber not found.');
    redirect(BASE_URL . '/modules/subscribers/');
}
if (($subscriber['status'] ?? '') !== SUB_STATUS_PENDING) {
    flashMessage('info', 'Only pending subscribers can be reviewed.');
    redirect(BASE_URL . '/modules/subscribers/view/' . $id);
}

$errors = [];
$subRouterId = (int)($subscriber['router_id'] ?? 0);
$subRouterConnected = $subRouterId && ($subscriber['router_status'] ?? '') === ROUTER_ONLINE;
$subRouterIsRadius = ($subscriber['auth_type'] ?? 'local') === 'radius';
$planAccountTerm = $subRouterIsRadius ? 'User Manager group' : 'MikroTik profile';

if ($subRouterId) {
    $pStmt = db()->prepare("
        SELECT * FROM plans
        WHERE is_active = 1
          AND ppp_profile IS NOT NULL
          AND ppp_profile <> ''
          AND (router_id = ? OR router_id IS NULL)
        ORDER BY amount
    ");
    $pStmt->execute([$subRouterId]);
    $plans = $pStmt->fetchAll();
} else {
    $plans = db()->query("
        SELECT * FROM plans
        WHERE is_active = 1
          AND ppp_profile IS NOT NULL
          AND ppp_profile <> ''
        ORDER BY amount
    ")->fetchAll();
}

$payTypes = getPaymentTypes();
$defTypeId = 0;
foreach ($payTypes as $pt) {
    if ($pt['is_default']) {
        $defTypeId = (int)$pt['type_id'];
        break;
    }
}

$currency = getSetting('currency_symbol', '₱');
$reviewData = $subscriber;
$defaultAmount = (float)($subscriber['plan_amount'] ?? 0);
$appliedAt = $subscriber['created_at'] ?? null;
$appliedDateText = formatDate($appliedAt, 'M d, Y h:i A');
$appliedAgoText = '—';
if ($appliedAt) {
    $appliedTs = strtotime($appliedAt);
    if ($appliedTs) {
        $elapsed = max(0, time() - $appliedTs);
        $units = [
            31536000 => 'year',
            2592000  => 'month',
            86400    => 'day',
            3600     => 'hour',
            60       => 'minute',
        ];
        $appliedAgoText = 'Just now';
        foreach ($units as $seconds => $label) {
            if ($elapsed >= $seconds) {
                $value = (int)floor($elapsed / $seconds);
                $appliedAgoText = $value . ' ' . $label . ($value === 1 ? '' : 's') . ' ago';
                break;
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $data = [
        'firstname'          => mb_strtoupper(trim($_POST['firstname'] ?? '')),
        'lastname'           => mb_strtoupper(trim($_POST['lastname'] ?? '')),
        'middlename'         => mb_strtoupper(trim($_POST['middlename'] ?? '')),
        'street'             => titleCase($_POST['street'] ?? ''),
        'barangay'           => titleCase($_POST['barangay'] ?? ''),
        'municipality'       => titleCase($_POST['municipality'] ?? ''),
        'province'           => titleCase($_POST['province'] ?? ''),
        'region'             => trim($_POST['region'] ?? ''),
        'zip'                => trim($_POST['zip'] ?? ''),
        'longitude'          => trim($_POST['longitude'] ?? ''),
        'latitude'           => trim($_POST['latitude'] ?? ''),
        'contact_number'     => trim($_POST['contact_number'] ?? ''),
        'email'              => trim($_POST['email'] ?? ''),
        'connection_type'    => $_POST['connection_type'] ?? ($subscriber['connection_type'] ?? CONN_PPP),
        'ppp_username'       => str_replace(' ', '', trim($_POST['ppp_username'] ?? '')),
        'new_ppp_password'   => trim($_POST['new_ppp_password'] ?? ''),
        'plan_id'            => (int)($_POST['plan_id'] ?? 0) ?: null,
        'router_id'          => $subRouterId,
        'subscription_start' => $_POST['subscription_start'] ?? appToday(),
        'subscription_end'   => $_POST['subscription_end'] ?? '',
        'remarks'            => trim($_POST['remarks'] ?? ''),
        'notify_sms'         => !empty($_POST['notify_sms']),
        'notify_email'       => !empty($_POST['notify_email']),
    ];

    $payment = [
        'amount'       => (float)($_POST['pay_amount'] ?? 0),
        'type_id'      => (int)($_POST['pay_type_id'] ?? $defTypeId) ?: $defTypeId,
        'method'       => $_POST['pay_method'] ?? 'cash',
        'reference'    => trim($_POST['pay_reference'] ?? ''),
        'date'         => trim($_POST['pay_date'] ?? ''),
        'period_start' => trim($_POST['pay_period_start'] ?? ''),
        'period_end'   => trim($_POST['pay_period_end'] ?? ''),
        'status'       => ($_POST['pay_status'] ?? '') === 'pending' ? 'pending' : 'paid',
        'notes'        => trim($_POST['pay_notes'] ?? ''),
    ];

    if (!$subRouterId || !$subRouterConnected || !routerIsConnected($subRouterId)) {
        $errors[] = 'Cannot review this subscriber because the assigned router is not connected.';
    }
    if (empty($data['firstname'])) $errors[] = 'First name is required.';
    if (empty($data['lastname'])) $errors[] = 'Last name is required.';
    if (empty($data['plan_id'])) $errors[] = 'Plan is required.';
    if (empty($data['subscription_end'])) $errors[] = 'Subscription end date is required.';
    if (empty($data['street']))   $errors[] = 'Street / House No. is required.';
    if (empty($data['region']))   $errors[] = 'Region is required.';
    if (empty($data['barangay'])) $errors[] = 'Barangay is required.';
    if (!in_array($data['connection_type'], [CONN_PPP, CONN_HOTSPOT], true)) {
        $errors[] = 'Invalid connection type.';
    }
    if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address.';
    }
    if (!empty($data['contact_number']) && !validatePhone($data['contact_number'])) {
        $errors[] = 'Invalid contact number. Format: 09XXXXXXXXX or +639XXXXXXXXX.';
    }
    if (!SMS_ENABLED) {
        $data['notify_sms'] = false;
    }
    if (!MAIL_ENABLED) {
        $data['notify_email'] = false;
    }
    if ($data['ppp_username'] === '') {
        $data['ppp_username'] = trim((string)$subscriber['account_number']);
    }

    $planProfile = '';
    $planRow = null;
    if ($data['plan_id']) {
        $planQ = db()->prepare("SELECT * FROM plans WHERE plan_id = ? AND is_active = 1");
        $planQ->execute([$data['plan_id']]);
        $planRow = $planQ->fetch();
        if (!$planRow || trim((string)($planRow['ppp_profile'] ?? '')) === '') {
            $errors[] = 'Selected plan must have an associated ' . $planAccountTerm . '.';
        } else {
            $planProfile = trim((string)$planRow['ppp_profile']);
        }
    }

    if ($payment['amount'] <= 0) {
        $errors[] = 'Payment amount must be greater than 0.';
    } elseif ($payment['amount'] > 999999) {
        $errors[] = 'Payment amount exceeds the maximum allowed.';
    }
    $validMethods = array_keys(getPaymentMethods());
    if (!in_array($payment['method'], $validMethods, true)) {
        $payment['method'] = 'cash';
    }
    $validTypeIds = array_map(fn($pt) => (int)$pt['type_id'], $payTypes);
    if (!in_array($payment['type_id'], $validTypeIds, true)) {
        $payment['type_id'] = $defTypeId;
    }

    $paymentDate = $payment['date'] !== ''
        ? date('Y-m-d H:i:s', strtotime($payment['date']))
        : appNow();
    $periodStart = $payment['period_start'] ?: ($data['subscription_start'] ?: null);
    $periodEnd = $payment['period_end'] ?: ($data['subscription_end'] ?: null);
    if ($periodStart && $periodEnd && strtotime($periodEnd) < strtotime($periodStart)) {
        $errors[] = 'Payment period end must be after period start.';
    }

    $plainPassword = $data['new_ppp_password'];
    if ($plainPassword === '') {
        $plainPassword = decryptData($subscriber['ppp_password'] ?? '');
    }
    if ($plainPassword === '') {
        $plainPassword = generatePassword(12);
    }

    $combinedAddress = implode(', ', array_filter([
        $data['street'],
        $data['barangay'],
        $data['municipality'],
        $data['province'],
        $data['region'],
    ]));

    if (empty($errors)) {
        $routerStmt = db()->prepare("SELECT * FROM routers WHERE router_id = ?");
        $routerStmt->execute([$subRouterId]);
        $routerRow = $routerStmt->fetch();
        if (!$routerRow) {
            $errors[] = 'Assigned router was not found.';
        } elseif (($routerRow['status'] ?? '') !== ROUTER_ONLINE) {
            $errors[] = 'Assigned router is not connected.';
        } else {
            try {
                $api = new RouterosAPI($routerRow['host'], (int)($routerRow['api_port'] ?: $routerRow['port']), 5);
                if (!$api->connect($routerRow['username'], decryptData($routerRow['password']))) {
                    throw new RuntimeException($api->error ?: 'Could not connect to router.');
                }

                $authType = $routerRow['auth_type'] ?? 'local';
                $uname = $data['ppp_username'];
                $oldUname = $subscriber['ppp_username'] ?? $uname;
                $profile = $planProfile ?: 'default';
                $routerComment = APP_NAME . ' | Reviewed | ' . trim($data['firstname'] . ' ' . $data['lastname']) . ' | ' . appToday();

                if ($authType === 'radius') {
                    $existing = $api->getUserManagerUser($oldUname ?: $uname);
                    if (empty($existing)) {
                        $api->addUserManagerUser([
                            'name' => $uname,
                            'password' => $plainPassword,
                            'group' => $profile,
                            'comment' => $routerComment,
                            'disabled' => 'no',
                        ]);
                    } else {
                        $fields = [
                            '.id' => $existing['.id'],
                            'password' => $plainPassword,
                            'group' => $profile,
                            'disabled' => 'no',
                            'comment' => $routerComment,
                        ];
                        if ($oldUname && $oldUname !== $uname) $fields['name'] = $uname;
                        $api->query($api->umPrefix() . '/user/set', $fields);
                    }
                } elseif ($data['connection_type'] === CONN_PPP) {
                    $rows = $api->query('/ppp/secret/print', [], ['?name=' . ($oldUname ?: $uname)]);
                    if (empty($rows[0]['.id'])) {
                        $api->addPPPSecret([
                            'name' => $uname,
                            'password' => $plainPassword,
                            'profile' => $profile,
                            'service' => 'pppoe',
                            'comment' => $routerComment,
                            'disabled' => 'no',
                        ]);
                    } else {
                        $fields = [
                            '.id' => $rows[0]['.id'],
                            'password' => $plainPassword,
                            'profile' => $profile,
                            'disabled' => 'no',
                            'comment' => $routerComment,
                        ];
                        if ($oldUname && $oldUname !== $uname) $fields['name'] = $uname;
                        $api->query('/ppp/secret/set', $fields);
                    }
                } else {
                    $rows = $api->query('/ip/hotspot/user/print', [], ['?name=' . ($oldUname ?: $uname)]);
                    if (empty($rows[0]['.id'])) {
                        $api->addHotspotUser([
                            'name' => $uname,
                            'password' => $plainPassword,
                            'profile' => $profile,
                            'comment' => $routerComment,
                            'disabled' => 'no',
                        ]);
                    } else {
                        $fields = [
                            '.id' => $rows[0]['.id'],
                            'password' => $plainPassword,
                            'profile' => $profile,
                            'disabled' => 'no',
                            'comment' => $routerComment,
                        ];
                        if ($oldUname && $oldUname !== $uname) $fields['name'] = $uname;
                        $api->query('/ip/hotspot/user/set', $fields);
                    }
                }
                $api->disconnect();

                $orNumber = generateORNumber();
                db()->beginTransaction();
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
                    WHERE subscriber_id=?
                ")->execute([
                    $data['firstname'],
                    $data['lastname'],
                    $data['middlename'] ?: null,
                    $combinedAddress ?: null,
                    $data['street'] ?: null,
                    $data['barangay'] ?: null,
                    $data['municipality'] ?: null,
                    $data['province'] ?: null,
                    $data['region'] ?: null,
                    $data['zip'] ?: null,
                    $data['longitude'] !== '' ? (float)$data['longitude'] : null,
                    $data['latitude'] !== '' ? (float)$data['latitude'] : null,
                    $data['contact_number'] ? sanitizePhone($data['contact_number']) : null,
                    $data['email'] ?: null,
                    $data['connection_type'],
                    $planProfile ?: null,
                    $data['ppp_username'],
                    encryptData($plainPassword),
                    $data['plan_id'],
                    $data['router_id'],
                    $data['subscription_start'] ?: null,
                    $data['subscription_end'] ?: null,
                    SUB_STATUS_ACTIVE,
                    $data['remarks'] ?: null,
                    appNow(),
                    $id,
                ]);

                db()->prepare("
                    INSERT INTO payments
                        (or_number, subscriber_id, router_id, payment_type_id, user_id, amount,
                         method, reference_number, payment_date, period_start, period_end, status, notes, created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ")->execute([
                    $orNumber,
                    $id,
                    $data['router_id'],
                    $payment['type_id'],
                    currentUserId(),
                    $payment['amount'],
                    $payment['method'],
                    $payment['reference'] ?: null,
                    $paymentDate,
                    $periodStart,
                    $periodEnd,
                    $payment['status'],
                    $payment['notes'] ?: 'New Connection',
                    appNow(),
                ]);
                db()->commit();

                logActivity('subscribers', 'review',
                    "Reviewed pending subscriber {$subscriber['account_number']} and activated router account.",
                    $id,
                    $subscriber,
                    $data + ['status' => SUB_STATUS_ACTIVE, 'payment_or' => $orNumber]
                );
                logActivity('payments', 'create',
                    "Review payment OR {$orNumber} for {$subscriber['account_number']}: {$currency}" . number_format($payment['amount'], 2),
                    $id
                );

                $notifySubscriber = $data + ['account_number' => $subscriber['account_number']];
                if ($data['notify_sms'] && !empty($data['contact_number'])) {
                    SMS::sendAccountCreated($notifySubscriber);
                }
                if ($data['notify_email'] && !empty($data['email'])) {
                    Mailer::sendWelcome($notifySubscriber, $plainPassword);
                }

                flashMessage('success', "Subscriber reviewed and activated. Payment OR #{$orNumber} recorded.");
                redirect(BASE_URL . '/modules/subscribers/view/' . $id);
            } catch (Throwable $e) {
                if (db()->inTransaction()) db()->rollBack();
                $errors[] = APP_DEBUG ? $e->getMessage() : 'Could not save the subscriber to MikroTik. Please check the router connection.';
            }
        }
    }

    $reviewData = array_merge($subscriber, $data ?? []);
    $defaultAmount = $payment['amount'] > 0 ? $payment['amount'] : (float)($planRow['amount'] ?? $subscriber['plan_amount'] ?? 0);
}

$pageTitle = 'Review Subscriber';
$breadcrumbs = [
    ['label' => 'Subscribers', 'url' => BASE_URL . '/modules/subscribers/'],
    ['label' => $subscriber['account_number'], 'url' => BASE_URL . '/modules/subscribers/view/' . $id],
    ['label' => 'Review'],
];
include BASE_PATH . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">Review Pending Subscriber</h4>
        <p class="text-muted small mb-0">
            <?= e($subscriber['account_number']) ?> · <?= e($subscriber['firstname'] . ' ' . $subscriber['lastname']) ?>
            · Applied <?= e($appliedDateText) ?> (<?= e($appliedAgoText) ?>)
        </p>
    </div>
    <a href="<?= BASE_URL ?>/modules/subscribers/view/<?= $id ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>

<?= inlineToasts($errors) ?>

<?php if (!$subRouterConnected): ?>
<?= inlineToasts([['message' => 'Assigned router is not connected. Review is disabled until this subscriber\'s router is online.', 'type' => 'warning']]) ?>
<?php endif; ?>

<form method="POST" novalidate>
    <?= csrfField() ?>
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border-0 mb-4">
                <div class="card-header bg-transparent border-bottom fw-semibold py-3">Subscriber Information</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-medium" for="reviewFirstname">First Name <span class="text-danger">*</span></label>
                            <input type="text" name="firstname" id="reviewFirstname" class="form-control text-uppercase" required
                                   value="<?= e($reviewData['firstname'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium" for="reviewLastname">Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="lastname" id="reviewLastname" class="form-control text-uppercase" required
                                   value="<?= e($reviewData['lastname'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium" for="reviewMiddlename">Middle Name</label>
                            <input type="text" name="middlename" id="reviewMiddlename" class="form-control text-uppercase"
                                   value="<?= e($reviewData['middlename'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="reviewContact">Contact Number</label>
                            <input type="tel" name="contact_number" id="reviewContact" class="form-control"
                                   value="<?= e($reviewData['contact_number'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="reviewEmail">Email</label>
                            <input type="email" name="email" id="reviewEmail" class="form-control"
                                   value="<?= e($reviewData['email'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 mb-4">
                <div class="card-header bg-transparent border-bottom fw-semibold py-3">Address</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-medium" for="reviewStreet">Street / House No. <span class="text-danger">*</span></label>
                            <input type="text" name="street" id="reviewStreet" class="form-control" required
                                   value="<?= e($reviewData['street'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="reviewBarangay">Barangay <span class="text-danger">*</span></label>
                            <input type="text" name="barangay" id="reviewBarangay" class="form-control" required
                                   value="<?= e($reviewData['barangay'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="reviewMunicipality">Municipality / City</label>
                            <input type="text" name="municipality" id="reviewMunicipality" class="form-control"
                                   value="<?= e($reviewData['municipality'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="reviewProvince">Province</label>
                            <input type="text" name="province" id="reviewProvince" class="form-control"
                                   value="<?= e($reviewData['province'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium" for="reviewRegion">Region <span class="text-danger">*</span></label>
                            <input type="text" name="region" id="reviewRegion" class="form-control" required
                                   value="<?= e($reviewData['region'] ?? '') ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-medium" for="reviewZip">ZIP</label>
                            <input type="text" name="zip" id="reviewZip" class="form-control" maxlength="10"
                                   value="<?= e($reviewData['zip'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium small" for="reviewLat">Latitude</label>
                            <input type="text" name="latitude" id="reviewLat" class="form-control font-monospace"
                                   value="<?= e($reviewData['latitude'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium small" for="reviewLng">Longitude</label>
                            <input type="text" name="longitude" id="reviewLng" class="form-control font-monospace"
                                   value="<?= e($reviewData['longitude'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0">
                <div class="card-header bg-transparent border-bottom fw-semibold py-3">Connection and Subscription</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="reviewConn">Connection Type</label>
                            <select name="connection_type" id="reviewConn" class="form-select">
                                <option value="ppp" <?= ($reviewData['connection_type'] ?? '') === CONN_PPP ? 'selected' : '' ?>>PPP / PPPoE</option>
                                <option value="hotspot" <?= ($reviewData['connection_type'] ?? '') === CONN_HOTSPOT ? 'selected' : '' ?>>Hotspot</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="reviewUsername">Username</label>
                            <input type="text" name="ppp_username" id="reviewUsername" class="form-control font-monospace"
                                   value="<?= e($reviewData['ppp_username'] ?? $subscriber['account_number']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="reviewPassword">Router Password
                                <small class="text-muted fw-normal">(blank = keep or generate)</small>
                            </label>
                            <input type="text" name="new_ppp_password" id="reviewPassword" class="form-control font-monospace"
                                   value="<?= e($_POST['new_ppp_password'] ?? '') ?>"
                                   placeholder="Leave blank to keep existing password">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="reviewPlan">Plan <span class="text-danger">*</span></label>
                            <select name="plan_id" id="reviewPlan" class="form-select" required>
                                <option value="">- Select Plan -</option>
                                <?php foreach ($plans as $plan): ?>
                                <option value="<?= (int)$plan['plan_id'] ?>"
                                        data-amount="<?= e($plan['amount']) ?>"
                                        <?= (int)($reviewData['plan_id'] ?? 0) === (int)$plan['plan_id'] ? 'selected' : '' ?>>
                                    <?= e($plan['title']) ?> - <?= e($currency) ?><?= number_format((float)$plan['amount'], 2) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="reviewStart">Start Date</label>
                            <input type="date" name="subscription_start" id="reviewStart" class="form-control"
                                   value="<?= e($reviewData['subscription_start'] ?? appToday()) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="reviewEnd">End Date <span class="text-danger">*</span></label>
                            <input type="date" name="subscription_end" id="reviewEnd" class="form-control" required
                                   value="<?= e($reviewData['subscription_end'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium" for="reviewRemarks">Remarks</label>
                            <textarea name="remarks" id="reviewRemarks" class="form-control" rows="2"><?= e($reviewData['remarks'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 mb-4">
                <div class="card-header bg-transparent border-bottom fw-semibold py-3">Account</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Account Number</label>
                        <input type="text" class="form-control font-monospace fw-bold" value="<?= e($subscriber['account_number']) ?>" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Date Applied</label>
                        <div class="form-control bg-light">
                            <?= e($appliedDateText) ?>
                            <span class="text-muted small">(<?= e($appliedAgoText) ?>)</span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Review Result</label>
                        <div class="form-control bg-light">Approve and activate subscriber</div>
                    </div>
                </div>
            </div>

            <div class="card border-0 mb-4">
                <div class="card-header bg-transparent border-bottom fw-semibold py-3">Router</div>
                <div class="card-body">
                    <input type="hidden" name="router_id" value="<?= (int)$subRouterId ?>">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-router text-primary"></i>
                        <span class="fw-medium"><?= e($subscriber['router_name'] ?? 'None') ?></span>
                        <?php if ($subRouterConnected): ?>
                        <span class="badge bg-success-subtle text-success border">Online</span>
                        <?php else: ?>
                        <span class="badge bg-danger-subtle text-danger border"><?= e(ucfirst($subscriber['router_status'] ?? 'not connected')) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="form-text mt-1">The router account is created or updated when this review is saved.</div>
                </div>
            </div>

            <div class="card border-0 mb-4">
                <div class="card-header bg-transparent border-bottom fw-semibold py-3">Notifications</div>
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

            <div class="card border-0 mb-4">
                <div class="card-header bg-transparent border-bottom fw-semibold py-3">Payment Details</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-medium" for="reviewPayAmount">Amount (<?= e($currency) ?>) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text fw-bold text-success"><?= e($currency) ?></span>
                            <input type="number" name="pay_amount" id="reviewPayAmount" class="form-control form-control-lg fw-bold"
                                   step="0.01" min="0.01" required
                                   value="<?= e($_POST['pay_amount'] ?? ($defaultAmount > 0 ? number_format($defaultAmount, 2, '.', '') : '')) ?>">
                        </div>
                    </div>
                    <?php if (count($payTypes) > 1): ?>
                    <div class="mb-3">
                        <label class="form-label fw-medium" for="reviewPayType">Payment Type</label>
                        <select name="pay_type_id" id="reviewPayType" class="form-select">
                            <?php foreach ($payTypes as $pt): ?>
                            <?php if (!$pt['is_active']) continue; ?>
                            <option value="<?= (int)$pt['type_id'] ?>" <?= (int)($_POST['pay_type_id'] ?? $defTypeId) === (int)$pt['type_id'] ? 'selected' : '' ?>>
                                <?= e($pt['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="pay_type_id" value="<?= (int)$defTypeId ?>">
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label fw-medium" for="reviewPayMethod">Method</label>
                        <select name="pay_method" id="reviewPayMethod" class="form-select">
                            <?php foreach (getPaymentMethods() as $val => $label): ?>
                            <option value="<?= e($val) ?>" <?= ($_POST['pay_method'] ?? 'cash') === $val ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium" for="reviewPayReference">Reference #</label>
                        <input type="text" name="pay_reference" id="reviewPayReference" class="form-control font-monospace"
                               value="<?= e($_POST['pay_reference'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium" for="reviewPayDate">Payment Date</label>
                        <input type="datetime-local" name="pay_date" id="reviewPayDate" class="form-control"
                               value="<?= e($_POST['pay_date'] ?? date('Y-m-d\TH:i')) ?>">
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label fw-medium" for="reviewPayStart">Period Start</label>
                            <input type="date" name="pay_period_start" id="reviewPayStart" class="form-control"
                                   value="<?= e($_POST['pay_period_start'] ?? ($reviewData['subscription_start'] ?? appToday())) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-medium" for="reviewPayEnd">Period End</label>
                            <input type="date" name="pay_period_end" id="reviewPayEnd" class="form-control"
                                   value="<?= e($_POST['pay_period_end'] ?? ($reviewData['subscription_end'] ?? '')) ?>">
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label fw-medium" for="reviewPayStatus">Payment Status</label>
                        <select name="pay_status" id="reviewPayStatus" class="form-select">
                            <option value="paid" <?= ($_POST['pay_status'] ?? 'paid') === 'paid' ? 'selected' : '' ?>>Paid</option>
                            <option value="pending" <?= ($_POST['pay_status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pending</option>
                        </select>
                    </div>
                    <div class="mt-3">
                        <label class="form-label fw-medium" for="reviewPayNotes">Notes</label>
                        <textarea name="pay_notes" id="reviewPayNotes" class="form-control" rows="2"><?= e($_POST['pay_notes'] ?? 'New Connection') ?></textarea>
                    </div>
                </div>
            </div>

            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-warning" <?= !$subRouterConnected ? 'disabled title="Assigned router is not connected"' : '' ?>>
                    <i class="bi bi-clipboard-check me-2"></i>Approve Review
                </button>
                <a href="<?= BASE_URL ?>/modules/subscribers/view/<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </div>
    </div>
</form>

<?php
$extraScripts = <<<'JS'
<script>
const planSelect = document.getElementById('reviewPlan');
const payAmount = document.getElementById('reviewPayAmount');
const subStart = document.getElementById('reviewStart');
const subEnd = document.getElementById('reviewEnd');
const payStart = document.getElementById('reviewPayStart');
const payEnd = document.getElementById('reviewPayEnd');

planSelect?.addEventListener('change', () => {
    const opt = planSelect.options[planSelect.selectedIndex];
    if (opt?.dataset.amount && (!payAmount.value || Number(payAmount.value) <= 0)) {
        payAmount.value = Number(opt.dataset.amount).toFixed(2);
    }
});
subStart?.addEventListener('change', () => { if (payStart) payStart.value = subStart.value; });
subEnd?.addEventListener('change', () => { if (payEnd) payEnd.value = subEnd.value; });
</script>
JS;

include BASE_PATH . '/includes/footer.php';
?>
