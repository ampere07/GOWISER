<?php
require_once __DIR__ . '/config/config.php';

if (isLoggedIn()) {
    if (currentRole() === ROLE_SUPERADMIN && selectedRouterId() === null) {
        redirect(BASE_URL . '/modules/router_select');
    }
    redirect(BASE_URL . '/modules/dashboard/');
}

$error   = '';
$success = '';

// ── POST: handle credentials login ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login' && MAINTENANCE_MODE) {
    $error = 'The system is currently under maintenance. New logins are temporarily disabled.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login' && !MAINTENANCE_MODE) {
    verifyCsrf();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $captcha  = $_POST['g-recaptcha-response'] ?? '';

    do {
        if (empty($username) || empty($password)) {
            $error = 'Username and password are required.';
            break;
        }

        if (!checkLoginAttempts($username)) {
            $remaining = getLockoutRemainingSeconds($username);
            $error = 'Too many failed attempts. Please wait ' . ceil($remaining / 60) . ' minute(s).';
            break;
        }

        if (RECAPTCHA_ENABLED && !empty(RECAPTCHA_SECRET_KEY) && !verifyRecaptcha($captcha)) {
            $error = 'reCAPTCHA verification failed. Please try again.';
            break;
        }

        $stmt = db()->prepare("
            SELECT user_id, firstname, lastname, username, email, password, role, router_id, is_active
            FROM users
            WHERE (username = ? OR email = ?) AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            recordLoginAttempt($username);
            $error = 'Invalid username or password.';
            break;
        }

        clearLoginAttempts($username);

        $stmt2fa = db()->prepare("SELECT two_factor_enabled, two_factor_phone FROM users WHERE user_id = ?");
        $stmt2fa->execute([$user['user_id']]);
        $tfRow = $stmt2fa->fetch();

        if ($tfRow && $tfRow['two_factor_enabled'] && !empty($tfRow['two_factor_phone'])) {
            $otp = generateOTP(6);
            $exp = date('Y-m-d H:i:s', strtotime('+5 minutes'));
            db()->prepare("UPDATE users SET two_factor_otp=?, two_factor_otp_exp=? WHERE user_id=?")
               ->execute([$otp, $exp, $user['user_id']]);

            $_SESSION['2fa_pending_user_id'] = $user['user_id'];
            $_SESSION['2fa_phone']           = $tfRow['two_factor_phone'];

            try {
                require_once BASE_PATH . '/lib/SMS.php';
                SMS::sendRaw($tfRow['two_factor_phone'], "Your " . APP_NAME . " login code: {$otp}. Valid 5 min.");
            } catch (Throwable $e) {}

            redirect(BASE_URL . '/auth/verify-2fa');
        }

        $user['avatar'] = '';
        loginUser($user);
        logActivity('auth', 'login', 'User logged in via credentials');

        $afterLogin = $_SESSION['redirect_after_login'] ?? '';
        unset($_SESSION['redirect_after_login']);
        $afterLogin = safeRedirectUrl($afterLogin);
        if (currentRole() === ROLE_SUPERADMIN) {
            redirect(BASE_URL . '/modules/router_select?redirect=' . urlencode($afterLogin));
        } else {
            redirect($afterLogin);
        }

    } while (false);
}

// ── Google OAuth redirect ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['google'])) {
    if (MAINTENANCE_MODE) {
        $error = 'The system is currently under maintenance. New logins are temporarily disabled.';
    } elseif (!GOOGLE_ENABLED || empty(GOOGLE_CLIENT_ID)) {
        $error = 'Google login is not configured.';
    } else {
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state'] = $state;
        $params = http_build_query([
            'client_id'     => GOOGLE_CLIENT_ID,
            'redirect_uri'  => GOOGLE_REDIRECT_URI,
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'access_type'   => 'online',
            'prompt'        => 'select_account',
        ]);
        redirect(GOOGLE_AUTH_URL . '?' . $params);
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
    <title>Login — <?= e($companyName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/fonts/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <?php if (RECAPTCHA_ENABLED && !empty(RECAPTCHA_SITE_KEY)): ?>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <?php endif; ?>
    <style>
        <?php include __DIR__ . '/assets/css/auth-shared.css.php'; ?>
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

            <div class="auth-card-header">
                <div class="auth-icon-circle auth-icon-primary">
                    <i class="bi bi-box-arrow-in-right"></i>
                </div>
                <h4 class="auth-title">Welcome back</h4>
                <p class="auth-subtitle">Sign in to your account</p>
            </div>

            <?php if (MAINTENANCE_MODE): ?>
            <div class="auth-alert auth-alert-warning">
                <span>System is under maintenance. Login is temporarily disabled.</span>
            </div>
            <?php endif; ?>

            <?php if ($error && !MAINTENANCE_MODE): ?>
            <div class="auth-alert auth-alert-danger">
                <span><?= e($error) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($success): ?>
            <div class="auth-alert auth-alert-success">
                <span><?= e($success) ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" action="" id="loginForm" novalidate>
                <?= csrfField() ?>
                <input type="hidden" name="action" value="login">

                <div class="auth-field">
                    <label class="auth-label" for="usernameInput">Username or Email</label>
                    <div class="auth-input-group">
                        <i class="bi bi-person auth-input-icon"></i>
                        <input type="text" name="username" id="usernameInput"
                               class="auth-input"
                               placeholder="Enter username or email"
                               value="<?= e($_POST['username'] ?? '') ?>"
                               autocomplete="username" required autofocus
                               <?= MAINTENANCE_MODE ? 'disabled' : '' ?>>
                    </div>
                </div>

                <div class="auth-field">
                    <div class="d-flex justify-content-between align-items-center">
                        <label class="auth-label" for="passwordInput">Password</label>
                        <a href="<?= BASE_URL ?>/auth/forgot-password" class="auth-link-sm">Forgot password?</a>
                    </div>
                    <div class="auth-input-group">
                        <i class="bi bi-lock auth-input-icon"></i>
                        <input type="password" name="password" id="passwordInput"
                               class="auth-input"
                               placeholder="Enter password"
                               autocomplete="current-password" required
                               <?= MAINTENANCE_MODE ? 'disabled' : '' ?>>
                        <button type="button" class="auth-toggle-btn" id="togglePassword" tabindex="-1">
                            <i class="bi bi-eye" id="togglePasswordIcon"></i>
                        </button>
                    </div>
                </div>

                <?php if (RECAPTCHA_ENABLED && !empty(RECAPTCHA_SITE_KEY)): ?>
                <div class="mb-4 d-flex justify-content-center">
                    <div class="g-recaptcha" data-sitekey="<?= e(RECAPTCHA_SITE_KEY) ?>"></div>
                </div>
                <?php endif; ?>

                <button type="submit" class="auth-btn auth-btn-primary" <?= MAINTENANCE_MODE ? 'disabled' : '' ?>>
                    Sign In
                </button>
            </form>

            <?php if (GOOGLE_ENABLED && !empty(GOOGLE_CLIENT_ID)): ?>
            <div class="auth-divider"><span>or</span></div>
            <a href="<?= MAINTENANCE_MODE ? '#' : '?google=1' ?>"
               class="auth-btn auth-btn-google<?= MAINTENANCE_MODE ? ' disabled' : '' ?>"
               <?= MAINTENANCE_MODE ? 'aria-disabled="true" tabindex="-1" style="pointer-events:none;opacity:.55;"' : '' ?>>
                <img src="https://www.svgrepo.com/show/475656/google-color.svg" width="18" height="18" alt="Google">
                Continue with Google
            </a>
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

document.getElementById('togglePassword')?.addEventListener('click', function() {
    const inp  = document.getElementById('passwordInput');
    const icon = document.getElementById('togglePasswordIcon');
    inp.type   = inp.type === 'password' ? 'text' : 'password';
    icon.className = inp.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
});

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

document.getElementById('loginForm')?.addEventListener('submit', function(e) {
    const captchaWidget = document.querySelector('.g-recaptcha');
    if (!captchaWidget) return;
    if (typeof grecaptcha === 'undefined') {
        e.preventDefault();
        showLoginToast('Cannot contact reCAPTCHA. Check your connection and try again.', 'danger');
        return;
    }
    if (grecaptcha.getResponse() === '') {
        e.preventDefault();
        showLoginToast('Please complete the reCAPTCHA verification before signing in.', 'warning');
    }
});
</script>
</body>
</html>
