<?php
require_once dirname(__DIR__) . '/config/config.php';

if (empty($_SESSION['2fa_pending_user_id'])) {
    redirect(BASE_URL . '/login');
}

$pendingId = (int)$_SESSION['2fa_pending_user_id'];
$phone     = $_SESSION['2fa_phone'] ?? '';
$masked    = strlen($phone) > 6
    ? substr($phone, 0, 2) . str_repeat('*', strlen($phone) - 5) . substr($phone, -3)
    : $phone;

$error   = '';
$success = '';

// ── Rate-limit helpers (session-scoped to this pending user) ──
$_2faAttemptKey = '2fa_attempts_' . $pendingId;
$_2faLockKey    = '2fa_locked_'   . $pendingId;
$_2faResendKey  = '2fa_resends_'  . $pendingId;

function tfa_is_locked(int $id): bool {
    global $_2faLockKey;
    return !empty($_SESSION[$_2faLockKey]) && $_SESSION[$_2faLockKey] > time();
}

function tfa_lock_remaining(int $id): int {
    global $_2faLockKey;
    return max(0, (int)(($_SESSION[$_2faLockKey] ?? 0) - time()));
}

function tfa_record_fail(int $id): void {
    global $_2faAttemptKey, $_2faLockKey;
    $_SESSION[$_2faAttemptKey] = ($_SESSION[$_2faAttemptKey] ?? 0) + 1;
    if ($_SESSION[$_2faAttemptKey] >= 5) {
        $_SESSION[$_2faLockKey]    = time() + 15 * 60;
        $_SESSION[$_2faAttemptKey] = 0;
        // Invalidate the OTP in the DB so it can't be reused after lockout lifts
        db()->prepare("UPDATE users SET two_factor_otp=NULL, two_factor_otp_exp=NULL WHERE user_id=?")
             ->execute([$id]);
    }
}

function tfa_clear(int $id): void {
    global $_2faAttemptKey, $_2faLockKey, $_2faResendKey;
    unset($_SESSION[$_2faAttemptKey], $_SESSION[$_2faLockKey], $_SESSION[$_2faResendKey]);
}

function tfa_resend_allowed(int $id): bool {
    global $_2faResendKey;
    $times = array_filter($_SESSION[$_2faResendKey] ?? [], fn($t) => $t > time() - 300);
    return count($times) < 3;
}

function tfa_record_resend(int $id): void {
    global $_2faResendKey;
    $times   = array_filter($_SESSION[$_2faResendKey] ?? [], fn($t) => $t > time() - 300);
    $times[] = time();
    $_SESSION[$_2faResendKey] = array_values($times);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'verify') {
        if (tfa_is_locked($pendingId)) {
            $mins  = ceil(tfa_lock_remaining($pendingId) / 60);
            $error = "Too many failed attempts. Please wait {$mins} minute(s) or log in again.";
        } else {
            $otp = trim($_POST['otp_code'] ?? '');
            if (empty($otp)) {
                $error = 'Please enter the OTP code.';
            } else {
                $row = db()->prepare("SELECT two_factor_otp, two_factor_otp_exp FROM users WHERE user_id = ?");
                $row->execute([$pendingId]);
                $row = $row->fetch();

                if (!$row || $row['two_factor_otp'] === null || !hash_equals((string)$row['two_factor_otp'], $otp)) {
                    tfa_record_fail($pendingId);
                    if (tfa_is_locked($pendingId)) {
                        $error = 'Too many failed attempts. The OTP has been invalidated. Please log in again.';
                        unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_phone']);
                    } else {
                        $left  = 5 - ($_SESSION[$_2faAttemptKey] ?? 0);
                        $error = "Invalid OTP code. {$left} attempt(s) remaining.";
                    }
                } elseif (strtotime($row['two_factor_otp_exp']) < time()) {
                    $error = 'OTP has expired. Please resend a new code.';
                } else {
                    tfa_clear($pendingId);
                    db()->prepare("UPDATE users SET two_factor_otp=NULL, two_factor_otp_exp=NULL WHERE user_id=?")
                       ->execute([$pendingId]);

                    $stmt = db()->prepare("SELECT user_id, firstname, lastname, username, email, password, role, router_id, is_active FROM users WHERE user_id = ? AND is_active = 1");
                    $stmt->execute([$pendingId]);
                    $user = $stmt->fetch();

                    if (!$user) {
                        unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_phone']);
                        redirect(BASE_URL . '/login');
                    }

                    unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_phone']);

                    $user['avatar'] = '';
                    loginUser($user);
                    logActivity('auth', 'login_2fa', 'User logged in via credentials + 2FA SMS');

                    $afterLogin = $_SESSION['redirect_after_login'] ?? '';
                    unset($_SESSION['redirect_after_login']);
                    $afterLogin = safeRedirectUrl($afterLogin);
                    if (currentRole() === ROLE_SUPERADMIN) {
                        redirect(BASE_URL . '/modules/router_select?redirect=' . urlencode($afterLogin));
                    } else {
                        redirect($afterLogin);
                    }
                }
            }
        }

    } elseif ($action === 'resend') {
        if (!tfa_resend_allowed($pendingId)) {
            $error = 'Too many resend requests. Please wait a few minutes before trying again.';
        } else {
            tfa_record_resend($pendingId);
            $otp = generateOTP(6);
            $exp = date('Y-m-d H:i:s', strtotime('+5 minutes'));
            db()->prepare("UPDATE users SET two_factor_otp=?, two_factor_otp_exp=? WHERE user_id=?")
               ->execute([$otp, $exp, $pendingId]);
            try {
                require_once BASE_PATH . '/lib/SMS.php';
                SMS::sendRaw($phone, "Your " . getSetting('company_name', APP_NAME) . " login code: {$otp}. Valid 5 min.");
                $success = "A new OTP has been sent to {$masked}.";
            } catch (Throwable $e) {
                $success = 'A new OTP has been generated. Please check with your administrator if SMS is not configured.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['cancel'])) {
    unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_phone']);
    redirect(BASE_URL . '/login');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['resend'])) {
    if (!tfa_resend_allowed($pendingId)) {
        $error = 'Too many resend requests. Please wait a few minutes before trying again.';
    } else {
        tfa_record_resend($pendingId);
        $otp = generateOTP(6);
        $exp = date('Y-m-d H:i:s', strtotime('+5 minutes'));
        db()->prepare("UPDATE users SET two_factor_otp=?, two_factor_otp_exp=? WHERE user_id=?")
           ->execute([$otp, $exp, $pendingId]);
        try {
            require_once BASE_PATH . '/lib/SMS.php';
            SMS::sendRaw($phone, "Your " . getSetting('company_name', APP_NAME) . " login code: {$otp}. Valid 5 min.");
            $success = "New OTP sent to {$masked}.";
        } catch (Throwable $e) {
            $success = 'A new OTP has been generated. Please check with your administrator if SMS is not configured.';
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
    <title>Verification — <?= e($companyName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/fonts/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <style>
        <?php include dirname(__DIR__) . '/assets/css/auth-shared.css.php'; ?>
        .otp-input {
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: .65rem;
            text-align: center;
            font-family: 'Courier New', monospace;
            height: 68px;
            border: 2px solid #e2e8f0;
            border-radius: 14px;
            background: #f8fafc;
            color: #0f172a;
            width: 100%;
            outline: none;
            padding: 0 1rem;
            transition: border-color .25s, box-shadow .25s;
        }
        .otp-input:focus {
            border-color: #57B446;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(87,180,70,.12);
        }
        .otp-input::placeholder { color: #cbd5e1; letter-spacing: .3rem; font-size: 1.4rem; }
        .otp-countdown { font-size: .8rem; color: #94a3b8; text-align: center; margin-top: .5rem; }
        .otp-countdown.expired { color: #ef4444; }
        .resend-btn {
            background: none;
            border: none;
            color: #57B446;
            font-size: .85rem;
            font-weight: 600;
            cursor: pointer;
            padding: 0;
            text-decoration: underline;
            text-decoration-color: transparent;
            transition: color .2s, text-decoration-color .2s;
        }
        .resend-btn:hover { color: #347026; text-decoration-color: #347026; }
        .phone-badge {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            background: #f4fbf3;
            border: 1px solid rgba(87,180,70,.2);
            border-radius: 8px;
            padding: .3rem .75rem;
            font-size: .85rem;
            font-weight: 600;
            color: #1e293b;
            margin: .5rem 0 1.25rem;
        }
        .phone-badge i { color: #57B446; }
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

            <a href="<?= BASE_URL ?>/auth/verify-2fa?cancel=1" class="auth-back-link">
                <i class="bi bi-arrow-left"></i> Back to Login
            </a>

            <?php if (MAINTENANCE_MODE): ?>
            <div class="auth-alert auth-alert-warning">
                <span>System is under maintenance. Some features may be temporarily unavailable.</span>
            </div>
            <?php endif; ?>

            <div class="auth-card-header">
                <div class="auth-icon-circle auth-icon-primary">
                    <i class="bi bi-shield-lock-fill"></i>
                </div>
                <h4 class="auth-title">Verify your identity</h4>
                <p class="auth-subtitle">Enter the 6-digit code sent to</p>
                <div class="phone-badge">
                    <i class="bi bi-phone-fill"></i>
                    <?= e($masked) ?>
                </div>
            </div>

            <?php if ($error): ?>
            <div class="auth-alert auth-alert-danger">
                <span><?= $error ?></span>
            </div>
            <?php endif; ?>
            <?php if ($success): ?>
            <div class="auth-alert auth-alert-success">
                <span><?= $success ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" id="otpForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="verify">

                <div class="auth-field">
                    <label class="auth-label" for="otpInput">OTP Code</label>
                    <input type="text" name="otp_code" id="otpInput"
                           class="otp-input"
                           placeholder="· · · · · ·"
                           maxlength="6" inputmode="numeric" pattern="[0-9]{6}"
                           autocomplete="one-time-code" autofocus required>
                    <div class="otp-countdown" id="otpCountdown"></div>
                </div>

                <button type="submit" class="auth-btn auth-btn-primary" style="margin-bottom:.75rem;">
                    Verify Code
                </button>
            </form>

            <div style="text-align:center; font-size:.85rem; color:#64748b;">
                Didn't receive it?&nbsp;
                <form method="POST" style="display:inline;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="resend">
                    <button type="submit" class="resend-btn">Resend OTP</button>
                </form>
            </div>

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

// Auto-submit when 6 digits entered
document.getElementById('otpInput')?.addEventListener('input', function () {
    this.value = this.value.replace(/\D/g, '').slice(0, 6);
    if (this.value.length === 6) {
        document.getElementById('otpForm').submit();
    }
});

// Countdown timer (5 minutes)
var remaining = 5 * 60;
var countEl   = document.getElementById('otpCountdown');
function tick() {
    remaining--;
    if (remaining <= 0) {
        clearInterval(timer);
        countEl.textContent = 'OTP expired — please resend.';
        countEl.classList.add('expired');
    } else {
        var m = String(Math.floor(remaining / 60)).padStart(2, '0');
        var s = String(remaining % 60).padStart(2, '0');
        countEl.textContent = 'Code expires in ' + m + ':' + s;
    }
}
var timer = setInterval(tick, 1000);
tick();
</script>
</body>
</html>
