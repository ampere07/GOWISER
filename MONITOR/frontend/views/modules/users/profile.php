<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireLogin();

// Super admin can view/edit another user's profile via ?user_id=X
$targetId       = (int)($_GET['user_id'] ?? 0);
$isAdminEditing = $targetId && $targetId !== currentUserId() && hasRole(ROLE_SUPERADMIN);

if ($isAdminEditing) {
    $id = $targetId;
} else {
    $id = currentUserId();
    $isAdminEditing = false;
}

$stmt = db()->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    flashMessage('danger', 'User not found.');
    redirect(BASE_URL . '/modules/users/');
}

// Non-superadmin cannot edit a superadmin's profile
if ($isAdminEditing && $user['role'] === ROLE_SUPERADMIN && !hasRole(ROLE_SUPERADMIN)) {
    flashMessage('danger', 'Insufficient permissions.');
    redirect(BASE_URL . '/modules/users/');
}

$errors  = [];
$success = '';
$tab     = $_GET['tab'] ?? 'profile';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ── Update profile ─────────────────────────────────────────
    if ($action === 'update_profile') {
        $data = [
            'firstname' => mb_strtoupper(trim($_POST['firstname'] ?? '')),
            'lastname'  => mb_strtoupper(trim($_POST['lastname']  ?? '')),
            'email'     => trim($_POST['email']     ?? ''),
        ];
        if (empty($data['firstname']))                           $errors[] = 'First name required.';
        if (empty($data['lastname']))                            $errors[] = 'Last name required.';
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email.';

        if (empty($errors)) {
            $ck = db()->prepare("SELECT 1 FROM users WHERE email = ? AND user_id != ?");
            $ck->execute([$data['email'], $id]);
            if ($ck->fetchColumn()) {
                $errors[] = 'Email already in use.';
            } else {
                db()->prepare("UPDATE users SET firstname=?,lastname=?,email=?,updated_at=? WHERE user_id=?")
                    ->execute([$data['firstname'], $data['lastname'], $data['email'], appNow(), $id]);
                if ($isAdminEditing) {
                    logActivity('users', 'profile_update', "Admin updated profile of: {$user['username']}");
                } else {
                    $_SESSION['firstname'] = $data['firstname'];
                    $_SESSION['lastname']  = $data['lastname'];
                    $_SESSION['email']     = $data['email'];
                    logActivity('users', 'profile_update', 'Updated own profile');
                }
                $success = 'Profile updated successfully.';
                $user = array_merge($user, $data);
            }
        }

    // ── Change password ────────────────────────────────────────
    } elseif ($action === 'change_password') {
        $current  = $_POST['current_password'] ?? '';
        $newPass  = $_POST['new_password']      ?? '';
        $newPass2 = $_POST['new_password2']     ?? '';

        if (!$isAdminEditing && !password_verify($current, $user['password'])) $errors[] = 'Current password is incorrect.';
        if (strlen($newPass) < 8)                          $errors[] = 'New password must be at least 8 characters.';
        if ($newPass !== $newPass2)                        $errors[] = 'New passwords do not match.';

        if (empty($errors)) {
            $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
            db()->prepare("UPDATE users SET password=?,updated_at=? WHERE user_id=?")->execute([$hash, appNow(), $id]);
            if ($isAdminEditing) {
                logActivity('users', 'password_reset', "Admin reset password for: {$user['username']}");
            } else {
                logActivity('users', 'password_change', 'Changed own password');
            }
            $success = 'Password changed successfully.';
        }
        $tab = 'security';

    // ── 2FA: save phone ───────────────────────────────────────
    } elseif ($action === 'save_2fa_phone') {
        $phone = trim($_POST['two_factor_phone'] ?? '');
        if (!empty($phone) && !validatePhone($phone)) {
            $errors[] = 'Invalid phone number format. Use 09XXXXXXXXX.';
        }
        if (empty($errors)) {
            db()->prepare("UPDATE users SET two_factor_phone=? WHERE user_id=?")
                ->execute([$phone ?: null, $id]);
            $success = 'Phone number saved.';
            $user['two_factor_phone'] = $phone;
        }
        $tab = 'security';

    // ── 2FA: send OTP ─────────────────────────────────────────
    } elseif ($action === 'send_2fa_otp') {
        $phone = $user['two_factor_phone'] ?? '';
        if (empty($phone)) {
            $errors[] = 'Please save a phone number first.';
        } else {
            $otp = generateOTP(6);
            $exp = date('Y-m-d H:i:s', strtotime('+5 minutes'));
            db()->prepare("UPDATE users SET two_factor_otp=?, two_factor_otp_exp=? WHERE user_id=?")
                ->execute([$otp, $exp, $id]);
            try {
                require_once BASE_PATH . '/lib/SMS.php';
                SMS::sendRaw($phone, "Your NetManager 2FA code: {$otp}. Valid for 5 minutes.");
                $success = "OTP sent to {$phone}.";
            } catch (Throwable $e) {
                $success = "OTP generated: <strong class='font-monospace'>{$otp}</strong> (SMS not configured — copy this code).";
            }
        }
        $tab = 'security';

    // ── 2FA: verify OTP and toggle ─────────────────────────────
    } elseif ($action === 'verify_2fa_otp') {
        $otp = trim($_POST['otp_code'] ?? '');
        if (empty($otp)) {
            $errors[] = 'Enter the OTP code.';
        } else {
            $row = db()->prepare("SELECT two_factor_otp, two_factor_otp_exp FROM users WHERE user_id = ?");
            $row->execute([$id]);
            $row = $row->fetch();
            if (!$row || $row['two_factor_otp'] !== $otp) {
                $errors[] = 'Invalid OTP.';
            } elseif (strtotime($row['two_factor_otp_exp']) < time()) {
                $errors[] = 'OTP has expired. Please send a new one.';
            } else {
                db()->prepare("UPDATE users SET two_factor_enabled=1, two_factor_otp=NULL, two_factor_otp_exp=NULL WHERE user_id=?")
                    ->execute([$id]);
                $success = '2FA has been enabled successfully.';
                $user['two_factor_enabled'] = 1;
            }
        }
        $tab = 'security';

    // ── 2FA: disable ──────────────────────────────────────────
    } elseif ($action === 'disable_2fa') {
        db()->prepare("UPDATE users SET two_factor_enabled=0, two_factor_otp=NULL, two_factor_otp_exp=NULL WHERE user_id=?")
            ->execute([$id]);
        $success = '2FA disabled.';
        $user['two_factor_enabled'] = 0;
        $tab = 'security';
    }
}

// Reload user after changes
$stmt->execute([$id]);
$user = $stmt->fetch() ?: $user;

if ($isAdminEditing) {
    $pageTitle   = 'Edit Profile — ' . e($user['firstname'] . ' ' . $user['lastname']);
    $breadcrumbs = [
        ['label' => 'Users', 'url' => BASE_URL . '/modules/users/'],
        ['label' => 'Edit Profile'],
    ];
} else {
    $pageTitle   = 'My Profile';
    $breadcrumbs = [['label' => 'My Profile']];
}
include BASE_PATH . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <?php if ($isAdminEditing): ?>
        <?= inlineToasts([['message' => 'You are editing the profile of @' . e($user['username']) . ' as Super Administrator.', 'type' => 'warning']]) ?>
        <?php endif; ?>

        <!-- Avatar + name header -->
        <div class="d-flex align-items-center gap-3 mb-4">
            <div class="avatar-circle-lg bg-primary" style="width:56px;height:56px;font-size:1.5rem;display:flex;align-items:center;justify-content:center;border-radius:50%;color:#fff;">
                <?= strtoupper(substr($user['firstname'] ?? 'U', 0, 1)) ?>
            </div>
            <div>
                <h4 class="fw-bold mb-0"><?= e($user['firstname'] . ' ' . $user['lastname']) ?></h4>
                <div class="text-muted small"><?= e(ROLES[$user['role']] ?? ucfirst($user['role'])) ?> — @<?= e($user['username']) ?></div>
            </div>
        </div>

        <?php
        // When admin edits via /profile/{id}, user_id is in the path (passed by .htaccess).
        // Tab links only need ?tab=X — the clean URL preserves the user_id segment.
        // When own profile (/profile, no path segment), ?tab=X works as before.
        $tabBase = $isAdminEditing
            ? BASE_URL . '/modules/users/profile/' . $id
            : BASE_URL . '/modules/users/profile';
        ?>
        <!-- Tabs -->
        <ul class="nav nav-tabs mb-4">
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'profile' ? 'active' : '' ?>" href="<?= $tabBase ?>?tab=profile">
                    Profile
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'security' ? 'active' : '' ?>" href="<?= $tabBase ?>?tab=security">
                    Security
                </a>
            </li>
        </ul>

        <?= !empty($success) ? inlineToasts([['message' => $success, 'type' => 'success']]) : '' ?>
        <?= inlineToasts($errors) ?>

        <?php if ($tab === 'profile'): ?>
        <!-- ── Profile Info ─────────────────────────────────────────── -->
        <div class="card border-0">
            <div class="card-header bg-transparent border-bottom fw-semibold py-3">
                Personal Information
            </div>
            <div class="card-body">
                <form method="POST" novalidate>
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_profile">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="profileFirstname">First Name <span class="text-danger">*</span></label>
                            <input type="text" name="firstname" id="profileFirstname" class="form-control text-uppercase" required
                                   value="<?= e($_POST['firstname'] ?? $user['firstname']) ?>"
                                   autocapitalize="characters" spellcheck="false">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="profileLastname">Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="lastname" id="profileLastname" class="form-control text-uppercase" required
                                   autocapitalize="characters" spellcheck="false"
                                   value="<?= e($_POST['lastname'] ?? $user['lastname']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium" for="profileEmail">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" id="profileEmail" class="form-control" required
                                   value="<?= e($_POST['email'] ?? $user['email']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="profileUsername">Username</label>
                            <input type="text" id="profileUsername" class="form-control" value="<?= e($user['username']) ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="profileRole">Role</label>
                            <input type="text" id="profileRole" class="form-control" value="<?= e(ROLES[$user['role']] ?? ucfirst($user['role'])) ?>" readonly>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary mt-3">
                        <i class="bi bi-check-lg me-1"></i>Update Profile
                    </button>
                </form>
            </div>
        </div>

        <?php else: ?>
        <!-- ── Security Tab ─────────────────────────────────────────── -->
        <!-- Change Password -->
        <div class="card border-0 mb-4">
            <div class="card-header bg-transparent border-bottom fw-semibold py-3">
                <?= $isAdminEditing ? 'Reset Password' : 'Change Password' ?>
            </div>
            <div class="card-body">
                <form method="POST" novalidate>
                    <?= csrfField() ?>
                    <?php if ($isAdminEditing): ?>
                    <input type="hidden" name="user_id" value="<?= $id ?>">
                    <?php endif; ?>
                    <input type="hidden" name="action" value="change_password">
                    <div class="row g-3">
                        <?php if (!$isAdminEditing): ?>
                        <div class="col-12">
                            <label class="form-label fw-medium" for="currentPw">Current Password <span class="text-danger">*</span></label>
                            <input type="password" name="current_password" id="currentPw" class="form-control" required>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="newPw">New Password <span class="text-danger">*</span> <small class="text-muted">(min 8 chars)</small></label>
                            <input type="password" name="new_password" id="newPw" class="form-control" minlength="8" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="confirmPw">Confirm Password <span class="text-danger">*</span></label>
                            <input type="password" name="new_password2" id="confirmPw" class="form-control" minlength="8" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-warning mt-3">
                        <i class="bi bi-lock me-1"></i><?= $isAdminEditing ? 'Reset Password' : 'Change Password' ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- 2FA via SMS -->
        <div class="card border-0">
            <div class="card-header bg-transparent border-bottom py-3 d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Two-Factor Authentication (SMS)</span>
                <?php if ($user['two_factor_enabled']): ?>
                <span class="badge bg-success">Enabled</span>
                <?php else: ?>
                <span class="badge bg-secondary">Disabled</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($user['two_factor_enabled']): ?>
                <?= inlineToasts([['message' => '2FA is active. Phone: ' . e($user['two_factor_phone'] ?? '(not set)') . '.', 'type' => 'success']]) ?>
                <form method="POST" class="mt-2">
                    <?= csrfField() ?>
                    <?php if ($isAdminEditing): ?><input type="hidden" name="user_id" value="<?= $id ?>"><?php endif; ?>
                    <input type="hidden" name="action" value="disable_2fa">
                    <button type="submit" class="btn btn-outline-danger btn-sm"
                            data-confirm="Disable two-factor authentication?">
                        <i class="bi bi-shield-x me-1"></i>Disable 2FA
                    </button>
                </form>

                <?php elseif ($isAdminEditing): ?>
                <p class="text-muted small mb-0">
                    
                    2FA is not enabled for this user. Only the user can enable 2FA from their own profile.
                </p>

                <?php else: ?>
                <p class="text-muted small mb-3">
                    Protect your account with SMS verification. Each login will require a code sent to your phone.
                </p>

                <!-- Step 1: Phone -->
                <form method="POST" class="mb-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_2fa_phone">
                    <label class="form-label fw-medium small" for="tfaPhone">Step 1 — Enter Your Phone Number</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-phone"></i></span>
                        <input type="tel" name="two_factor_phone" id="tfaPhone" class="form-control"
                               placeholder="09XXXXXXXXX"
                               value="<?= e($user['two_factor_phone'] ?? '') ?>">
                        <button type="submit" class="btn btn-outline-primary">Save Phone</button>
                    </div>
                </form>

                <?php if ($user['two_factor_phone']): ?>
                <!-- Step 2: Send OTP -->
                <form method="POST" class="mb-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="send_2fa_otp">
                    <p class="form-label fw-medium small mb-2">Step 2 — Send OTP to <?= e($user['two_factor_phone']) ?></p>
                    <button type="submit" class="btn btn-sm btn-outline-success">
                        <i class="bi bi-send me-1"></i>Send OTP Code
                    </button>
                </form>

                <!-- Step 3: Verify OTP -->
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="verify_2fa_otp">
                    <label class="form-label fw-medium small" for="otpCode">Step 3 — Enter OTP to Activate</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-key"></i></span>
                        <input type="text" name="otp_code" id="otpCode" class="form-control font-monospace"
                               placeholder="6-digit code" maxlength="6" required>
                        <button type="submit" class="btn btn-success">Verify &amp; Enable</button>
                    </div>
                </form>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include BASE_PATH . '/includes/footer.php'; ?>
