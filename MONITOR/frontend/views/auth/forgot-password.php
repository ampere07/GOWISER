<?php
require_once dirname(__DIR__) . '/config/config.php';

if (isLoggedIn()) {
    redirect(BASE_URL . '/modules/dashboard/');
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $username = trim($_POST['username'] ?? '');

    if (empty($username)) {
        $error = 'Please enter your username or email.';
    } else {
        // Rate-limit: max 3 reset requests per 15 minutes per session/IP
        $rlKey   = 'pwd_reset_' . md5(getClientIp());
        $rlTimes = array_filter($_SESSION[$rlKey] ?? [], fn($t) => $t > time() - 900);
        if (count($rlTimes) >= 3) {
            $error = 'Too many password reset requests. Please wait 15 minutes before trying again.';
        }
    }

    if (empty($error) && !empty($username)) {
        $stmt = db()->prepare(
            "SELECT user_id, firstname, lastname, email, role FROM users
             WHERE (username = ? OR email = ?) AND is_active = 1 LIMIT 1"
        );
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();

        if (!$user) {
            $error = 'No active account found with that username or email.';
        } else {
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+30 minutes'));

            db()->prepare("DELETE FROM password_resets WHERE username = ?")->execute([$user['email']]);
            db()->prepare("INSERT INTO password_resets (username, token, expires_at, created_at) VALUES (?, ?, ?, ?)")
                ->execute([$user['email'], $token, $expires, appNow()]);

            $resetUrl  = BASE_URL . '/auth/reset-password?token=' . $token;
            $emailSent = false;

            if (!empty($user['email'])) {
                try {
                    require_once BASE_PATH . '/lib/Mailer.php';
                    $emailSent = Mailer::sendPasswordReset($user, $resetUrl);
                } catch (Throwable $e) {
                    $emailSent = false;
                }
            }

            logActivity('auth', 'password_reset_request', "Password reset requested for user: {$user['email']}");

            // Record this attempt toward the rate limit
            $rlTimes[] = time();
            $_SESSION[$rlKey] = array_values($rlTimes);

            if ($emailSent) {
                $success = 'sent';
                $successEmail = e($user['email']);
            } else {
                $success    = 'link';
                $successUrl = e($resetUrl);
            }
        }
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
    <title>Forgot Password — <?= e($companyName) ?></title>
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

            <a href="<?= BASE_URL ?>/login" class="auth-back-link">
                <i class="bi bi-arrow-left"></i> Back to Login
            </a>

            <?php if (MAINTENANCE_MODE): ?>
            <div class="auth-alert auth-alert-warning">
                <span>System is under maintenance. Some features may be temporarily unavailable.</span>
            </div>
            <?php endif; ?>

            <?php if ($success === 'sent'): ?>

            <div class="auth-success-state">
                <div class="auth-success-icon">
                    <i class="bi bi-envelope-check-fill"></i>
                </div>
                <div class="auth-success-title">Check your email</div>
                <p class="auth-success-text">
                    We sent a password reset link to<br>
                    <strong><?= $successEmail ?></strong><br>
                    The link expires in <strong>30 minutes</strong>.
                </p>
                <a href="<?= BASE_URL ?>/login" class="auth-btn auth-btn-outline">
                    Return to Login
                </a>
            </div>

            <?php elseif ($success === 'link'): ?>

            <div class="auth-card-header">
                <div class="auth-icon-circle auth-icon-warning">
                    <i class="bi bi-link-45deg"></i>
                </div>
                <h4 class="auth-title">Reset link generated</h4>
                <p class="auth-subtitle">Email not configured — copy this link directly</p>
            </div>
            <div class="auth-alert auth-alert-warning">
                <div>
                    <div class="mb-1">No email configured. Use this link:</div>
                    <code class="user-select-all"><?= $successUrl ?></code>
                    <div class="mt-1" style="font-size:.78rem;opacity:.75;">Expires in 30 minutes.</div>
                </div>
            </div>
            <a href="<?= BASE_URL ?>/login" class="auth-btn auth-btn-outline">
                Return to Login
            </a>

            <?php else: ?>

            <div class="auth-card-header">
                <div class="auth-icon-circle auth-icon-warning">
                    <i class="bi bi-key-fill"></i>
                </div>
                <h4 class="auth-title">Forgot your password?</h4>
                <p class="auth-subtitle">Enter your username or email to receive a reset link</p>
            </div>

            <?php if ($error): ?>
            <div class="auth-alert auth-alert-danger">
                <span><?= e($error) ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" action="">
                <?= csrfField() ?>
                <div class="auth-field">
                    <label class="auth-label" for="forgotUsername">Username or Email</label>
                    <div class="auth-input-group">
                        <i class="bi bi-person auth-input-icon"></i>
                        <input type="text" name="username" id="forgotUsername"
                               class="auth-input"
                               placeholder="Enter your username or email"
                               value="<?= e($_POST['username'] ?? '') ?>"
                               autocomplete="username" required autofocus>
                    </div>
                </div>

                <div class="auth-info-box">
                    Password reset is available for <strong>active accounts</strong> only.
                    The link expires in <strong>30 minutes</strong>.
                </div>

                <button type="submit" class="auth-btn auth-btn-primary">
                    Send Reset Link
                </button>
            </form>

            <?php endif; ?>

        </div>
    </div>
</div>

<!-- Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:9999;">
    <div id="loginToast" class="toast align-items-center border-0 text-white" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body fw-medium" id="loginToastMsg"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<?= renderFlash() ?>
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

function showLoginToast(msg, type) {
    const el  = document.getElementById('loginToast');
    const txt = document.getElementById('loginToastMsg');
    if (!el || !txt) return;
    el.className = 'toast align-items-center border-0 text-white bg-' + (type || 'danger');
    txt.textContent = msg;
    bootstrap.Toast.getOrCreateInstance(el, { delay: 7000 }).show();
}
document.addEventListener('DOMContentLoaded', function () {
    const el = document.getElementById('flashData');
    if (!el) return;
    try { JSON.parse(el.textContent).forEach(f => showLoginToast(f.message, f.type)); } catch(e) {}
});
</script>
</body>
</html>
