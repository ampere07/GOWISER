<?php
require_once dirname(__DIR__) . '/config/config.php';

if (isLoggedIn()) {
    redirect(BASE_URL . '/modules/dashboard/');
}

$token = trim($_GET['token'] ?? '');
$error = '';
$done  = false;

if (!$token) {
    redirect(BASE_URL . '/login');
}

$stmt = db()->prepare(
    "SELECT pr.*, u.user_id, u.firstname, u.role
     FROM password_resets pr
     JOIN users u ON u.email = pr.username
     WHERE pr.token = ? AND pr.used = 0 AND pr.expires_at > ? AND u.is_active = 1
     LIMIT 1"
);
$stmt->execute([$token, appNow()]);
$reset = $stmt->fetch();

if (!$reset) {
    $error = 'This reset link is invalid or has expired.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    verifyCsrf();
    $newPass  = $_POST['new_password']  ?? '';
    $newPass2 = $_POST['new_password2'] ?? '';

    if (strlen($newPass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($newPass !== $newPass2) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
        db()->prepare("UPDATE users SET password=?, updated_at=? WHERE user_id=?")
             ->execute([$hash, appNow(), $reset['user_id']]);
        db()->prepare("UPDATE password_resets SET used=1 WHERE token=?")
             ->execute([$token]);
        logActivity('auth', 'password_reset', "Password reset completed for user ID {$reset['user_id']}");
        $done = true;
    }
}

$companyName = getSetting('company_name', defined('APP_NAME') ? APP_NAME : 'NetManager');
$companyDesc = getSetting('company_desc', 'ISP Billing & MikroTik Management');
$companyLogo = getSetting('company_logo', '');
$logoSrc     = $companyLogo ?: (BASE_URL . '/assets/img/logo.png');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password — <?= e($companyName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/fonts/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <style>
        <?php include dirname(__DIR__) . '/assets/css/auth-shared.css.php'; ?>
        .auth-advisory {
            margin-top: 1.5rem;
            background: rgba(255,255,255,.10);
            border: 1px solid rgba(255,255,255,.20);
            border-radius: 10px;
            padding: .65rem .85rem;
            font-size: .8rem;
            color: rgba(255,255,255,.92);
            line-height: 1.45;
            max-width: 320px;
        }
    </style>
</head>
<body class="auth-body">

<div class="auth-page">

    <!-- Left brand panel -->
    <div class="auth-brand">
        <!-- Animated light layer -->
        <div class="auth-orb auth-orb-1" aria-hidden="true"></div>
        <div class="auth-orb auth-orb-2" aria-hidden="true"></div>
        <div class="auth-orb auth-orb-3" aria-hidden="true"></div>
        <div class="auth-orb auth-orb-4" aria-hidden="true"></div>
        <div class="auth-beam auth-beam-1" aria-hidden="true"></div>
        <div class="auth-beam auth-beam-2" aria-hidden="true"></div>
        <div class="auth-scan" aria-hidden="true"></div>

        <div class="auth-brand-inner">
            <div class="auth-logo-ring">
                <div class="auth-logo-wrap">
                    <img src="<?= e($logoSrc) ?>" alt="<?= e($companyName) ?>"
                         class="auth-logo"
                         onerror="this.src='<?= BASE_URL ?>/assets/img/logo.png'">
                </div>
            </div>
            <h2 class="auth-brand-name"><?= e($companyName) ?></h2>
            <?php if ($companyDesc): ?>
            <p class="auth-brand-desc"><?= e($companyDesc) ?></p>
            <?php endif; ?>
            <div class="auth-brand-badges">
                <div class="auth-brand-badge">
                    <span class="auth-brand-badge-dot blue"></span>Subscriber Management
                </div>
                <div class="auth-brand-badge">
                    <span class="auth-brand-badge-dot cyan"></span>MikroTik Integration
                </div>
                <div class="auth-brand-badge">
                    <span class="auth-brand-badge-dot purple"></span>Real-time Billing
                </div>
            </div>

            <?php if (APP_ADVISORY !== ''): ?>
            <div class="auth-advisory">
                <span><?= e(APP_ADVISORY) ?></span>
            </div>
            <?php endif; ?>
        </div>
        <div class="auth-brand-footer">
            &copy; <?= date('Y') ?> <?= e($companyName) ?>
        </div>
    </div>

    <!-- Right form panel -->
    <div class="auth-form-panel">
        <div class="auth-card">

            <!-- Mobile logo -->
            <div class="auth-mobile-logo">
                <img src="<?= e($logoSrc) ?>" alt="<?= e($companyName) ?>"
                     onerror="this.src='<?= BASE_URL ?>/assets/img/logo.png'">
                <span><?= e($companyName) ?></span>
            </div>

            <?php if (MAINTENANCE_MODE): ?>
            <div class="auth-alert auth-alert-warning">
                <span>System is under maintenance. Some features may be temporarily unavailable.</span>
            </div>
            <?php endif; ?>

            <?php if ($done): ?>

            <div class="auth-success-state">
                <div class="auth-success-icon">
                    <i class="bi bi-shield-check-fill"></i>
                </div>
                <div class="auth-success-title">Password updated!</div>
                <p class="auth-success-text">
                    Your password has been reset successfully.<br>
                    You can now sign in with your new password.
                </p>
                <a href="<?= BASE_URL ?>/login" class="auth-btn auth-btn-primary">
                    Go to Login
                </a>
            </div>

            <?php elseif ($error && !$reset): ?>

            <div class="auth-card-header">
                <div class="auth-icon-circle auth-icon-warning">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </div>
                <h4 class="auth-title">Link expired</h4>
                <p class="auth-subtitle">This reset link is no longer valid</p>
            </div>
            <div class="auth-alert auth-alert-danger">
                <span><?= e($error) ?></span>
            </div>
            <a href="<?= BASE_URL ?>/auth/forgot-password" class="auth-btn auth-btn-primary" style="margin-bottom:.75rem;">
                Request New Link
            </a>
            <a href="<?= BASE_URL ?>/login" class="auth-btn auth-btn-outline">
                Back to Login
            </a>

            <?php else: ?>

            <a href="<?= BASE_URL ?>/login" class="auth-back-link">
                <i class="bi bi-arrow-left"></i> Back to Login
            </a>

            <div class="auth-card-header">
                <div class="auth-icon-circle auth-icon-success">
                    <i class="bi bi-shield-lock-fill"></i>
                </div>
                <h4 class="auth-title">Set new password</h4>
                <p class="auth-subtitle">
                    For <strong><?= e($reset['firstname'] ?? 'your account') ?></strong>
                </p>
            </div>

            <?php if ($error): ?>
            <div class="auth-alert auth-alert-danger">
                <span><?= e($error) ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" action="" novalidate id="resetForm">
                <?= csrfField() ?>

                <div class="auth-field">
                    <label class="auth-label" for="newPass">New Password</label>
                    <div class="auth-input-group">
                        <i class="bi bi-lock auth-input-icon"></i>
                        <input type="password" name="new_password" id="newPass"
                               class="auth-input"
                               placeholder="At least 8 characters"
                               minlength="8" required autofocus>
                        <button type="button" class="auth-toggle-btn" id="toggleNewPass" tabindex="-1">
                            <i class="bi bi-eye" id="toggleNewPassIcon"></i>
                        </button>
                    </div>
                    <div class="auth-strength-bar">
                        <div class="auth-strength-fill" id="strengthFill" style="width:0%;background:#e5e7eb;"></div>
                    </div>
                    <div class="auth-strength-label" id="strengthLabel">Enter a password</div>
                </div>

                <div class="auth-field">
                    <label class="auth-label" for="confirmPass">Confirm Password</label>
                    <div class="auth-input-group">
                        <i class="bi bi-lock-fill auth-input-icon"></i>
                        <input type="password" name="new_password2" id="confirmPass"
                               class="auth-input"
                               placeholder="Repeat your password"
                               minlength="8" required>
                        <button type="button" class="auth-toggle-btn" id="toggleConfirmPass" tabindex="-1">
                            <i class="bi bi-eye" id="toggleConfirmPassIcon"></i>
                        </button>
                    </div>
                    <div class="auth-strength-label" id="matchLabel" style="display:none;"></div>
                </div>

                <button type="submit" class="auth-btn auth-btn-success" style="margin-top:.25rem;">
                    Set New Password
                </button>
            </form>

            <?php endif; ?>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    var brand = document.querySelector('.auth-brand');
    if (!brand) return;
    var colors = ['rgba(87,180,70,.6)','rgba(44,154,210,.55)','rgba(22,80,158,.45)','rgba(57,180,70,.4)'];
    function spawn() {
        var p = document.createElement('div');
        p.className = 'auth-particle';
        var size = Math.random() * 4 + 2;
        p.style.cssText = 'left:' + (Math.random()*100) + '%;bottom:-10px;width:' + size + 'px;height:' + size + 'px;background:' + colors[Math.floor(Math.random()*colors.length)] + ';animation-duration:' + (Math.random()*8+6) + 's;animation-delay:' + (Math.random()*2) + 's;';
        brand.appendChild(p);
        p.addEventListener('animationend', function () { p.remove(); });
    }
    for (var i = 0; i < 14; i++) spawn();
    setInterval(spawn, 1000);
})();

// Toggle new password
document.getElementById('toggleNewPass')?.addEventListener('click', function () {
    const inp  = document.getElementById('newPass');
    const icon = document.getElementById('toggleNewPassIcon');
    inp.type   = inp.type === 'password' ? 'text' : 'password';
    icon.className = inp.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
});

// Toggle confirm password
document.getElementById('toggleConfirmPass')?.addEventListener('click', function () {
    const inp  = document.getElementById('confirmPass');
    const icon = document.getElementById('toggleConfirmPassIcon');
    inp.type   = inp.type === 'password' ? 'text' : 'password';
    icon.className = inp.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
});

// Password strength meter
const newPass     = document.getElementById('newPass');
const confirmPass = document.getElementById('confirmPass');
const fill        = document.getElementById('strengthFill');
const label       = document.getElementById('strengthLabel');
const matchLabel  = document.getElementById('matchLabel');

function checkStrength(pw) {
    if (!pw) { fill.style.width = '0%'; fill.style.background = '#e5e7eb'; label.textContent = 'Enter a password'; return; }
    let score = 0;
    if (pw.length >= 8)  score++;
    if (pw.length >= 12) score++;
    if (pw.length >= 16) score++;
    if (/[A-Z]/.test(pw)) score++;
    if (/[a-z]/.test(pw)) score++;
    if (/[0-9]/.test(pw)) score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;

    const tiers = [
        { max: 2, label: 'Too weak',  bg: '#ef4444', pct: 20 },
        { max: 3, label: 'Weak',      bg: '#f97316', pct: 40 },
        { max: 4, label: 'Fair',      bg: '#eab308', pct: 60 },
        { max: 5, label: 'Good',      bg: '#22c55e', pct: 80 },
        { max: 99,label: 'Strong',    bg: '#16a34a', pct: 100 },
    ];
    const tier = tiers.find(t => score <= t.max);
    fill.style.width      = tier.pct + '%';
    fill.style.background = tier.bg;
    label.textContent     = tier.label;
}

function checkMatch() {
    const p = newPass?.value;
    const c = confirmPass?.value;
    if (!c) { matchLabel.style.display = 'none'; return; }
    matchLabel.style.display = 'block';
    if (p === c) {
        matchLabel.textContent = '✓ Passwords match';
        matchLabel.style.color = '#16a34a';
    } else {
        matchLabel.textContent = '✗ Passwords do not match';
        matchLabel.style.color = '#ef4444';
    }
}

newPass?.addEventListener('input', function () { checkStrength(this.value); checkMatch(); });
confirmPass?.addEventListener('input', checkMatch);

document.getElementById('resetForm')?.addEventListener('submit', function (e) {
    if (newPass.value !== confirmPass.value) {
        e.preventDefault();
        matchLabel.style.display = 'block';
        matchLabel.textContent   = '✗ Passwords do not match';
        matchLabel.style.color   = '#ef4444';
        confirmPass.focus();
    }
});
</script>
</body>
</html>
