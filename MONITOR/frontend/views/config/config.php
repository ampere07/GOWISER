<?php
define('BASE_PATH', dirname(__DIR__));

// ── Load .env ────────────────────────────────────────────────
function loadEnv(string $path): void {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$name, $value] = explode('=', $line, 2);
        $name  = trim($name);
        $value = trim($value);
        if (preg_match('/^"(.*)"$/s', $value, $m) || preg_match("/^'(.*)'$/s", $value, $m)) {
            $value = $m[1];
        }
        if (!array_key_exists($name, $_ENV)) {
            $_ENV[$name] = $value;
            putenv("$name=$value");
        }
    }
}
loadEnv(BASE_PATH . '/.env');

function envEnabled(string $key, bool $default = false): bool {
    if (!array_key_exists($key, $_ENV)) return $default;
    return filter_var($_ENV[$key], FILTER_VALIDATE_BOOLEAN);
}

// ── Application constants ────────────────────────────────────
define('APP_NAME',     $_ENV['APP_NAME']     ?? 'NetManager');
define('APP_URL',      rtrim($_ENV['APP_URL'] ?? '', '/'));
define('BASE_URL',     APP_URL);
define('APP_ENV',          $_ENV['APP_ENV']      ?? 'production');
define('APP_DEBUG',        filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN));
define('MAINTENANCE_MODE', APP_ENV === 'maintenance');
define('APP_ADVISORY',    trim($_ENV['APP_ADVISORY'] ?? '', '"; '));
define('APP_TIMEZONE', 'Asia/Manila'); // fallback only; real TZ is loaded from settings table below
define('APP_KEY',      $_ENV['APP_KEY']      ?? '');

// ── Security headers (non-CLI only) ─────────────────────────
if (PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// ── Error reporting ──────────────────────────────────────────
if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}
date_default_timezone_set(APP_TIMEZONE);

// ── Session ──────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    $sessName     = $_ENV['SESSION_NAME']     ?? 'netmanager_sess';
    $sessLifetime = (int)($_ENV['SESSION_LIFETIME'] ?? 7200);
    ini_set('session.gc_maxlifetime', (string)$sessLifetime);
    session_name($sessName);
    session_set_cookie_params([
        'lifetime' => $sessLifetime,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ── Business constants ───────────────────────────────────────
define('MAX_ROUTERS',          (int)($_ENV['MAX_ROUTERS']          ?? 5));
define('CSRF_TOKEN_NAME',      $_ENV['CSRF_TOKEN_NAME']            ?? '_csrf_token');
define('LOGIN_MAX_ATTEMPTS',   (int)($_ENV['LOGIN_MAX_ATTEMPTS']   ?? 5));
define('LOGIN_LOCKOUT_MINUTES',(int)($_ENV['LOGIN_LOCKOUT_MINUTES']?? 15));

// ── Google OAuth ─────────────────────────────────────────────
define('GOOGLE_ENABLED',       envEnabled('GOOGLE_ENABLED', !empty($_ENV['GOOGLE_CLIENT_ID'] ?? '')));
define('GOOGLE_CLIENT_ID',     $_ENV['GOOGLE_CLIENT_ID']     ?? '');
define('GOOGLE_CLIENT_SECRET', $_ENV['GOOGLE_CLIENT_SECRET'] ?? '');
define('GOOGLE_REDIRECT_URI',  $_ENV['GOOGLE_REDIRECT_URI']  ?? '');
define('GOOGLE_AUTH_URL',      'https://accounts.google.com/o/oauth2/v2/auth');
define('GOOGLE_TOKEN_URL',     'https://oauth2.googleapis.com/token');
define('GOOGLE_USERINFO_URL',  'https://www.googleapis.com/oauth2/v3/userinfo');

// ── reCAPTCHA ────────────────────────────────────────────────
define('RECAPTCHA_ENABLED',    envEnabled('RECAPTCHA_ENABLED', !empty($_ENV['RECAPTCHA_SECRET_KEY'] ?? '')));
define('RECAPTCHA_SITE_KEY',   $_ENV['RECAPTCHA_SITE_KEY']   ?? '');
define('RECAPTCHA_SECRET_KEY', $_ENV['RECAPTCHA_SECRET_KEY'] ?? '');
define('RECAPTCHA_VERIFY_URL', 'https://www.google.com/recaptcha/api/siteverify');

// ── Mail ─────────────────────────────────────────────────────
define('MAIL_ENABLED',         envEnabled('MAIL_ENABLED', false));

// ── SMS ──────────────────────────────────────────────────────
define('SMS_ENABLED',          filter_var($_ENV['SMS_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN));
define('SMS_API_URL',          $_ENV['SMS_API_URL']   ?? '');
define('SMS_API_KEY',          $_ENV['SMS_API_KEY']   ?? '');
define('SMS_SENDER_ID',        $_ENV['SMS_SENDER_ID'] ?? '');

// ── Telegram ─────────────────────────────────────────────────
define('TELEGRAM_ENABLED',     envEnabled('TELEGRAM_ENABLED', false));
define('TELEGRAM_TOKEN',       $_ENV['TELEGRAM_TOKEN']   ?? '');
define('TELEGRAM_CHAT_ID',     $_ENV['TELEGRAM_CHAT_ID'] ?? '');

// ── Autoload vendor (PHPMailer) ──────────────────────────────
$vendorAutoload = BASE_PATH . '/vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
}

// ── Core helpers ─────────────────────────────────────────────
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/constants.php';
require_once BASE_PATH . '/includes/csrf.php';
require_once BASE_PATH . '/includes/functions.php';
require_once BASE_PATH . '/includes/auth.php';

// ── Override timezone from DB settings (if configured) ───────
try {
    $dbTz = getSetting('timezone');
    if ($dbTz && $dbTz !== APP_TIMEZONE) {
        date_default_timezone_set($dbTz);
    }
} catch (Throwable) {}

// ── Auto-expire past-due subscribers on every app entry point ─
// Database-only status sync: if subscription_end was yesterday or older,
// mark active/suspended subscribers as expired before pages render.
function autoExpirePastDueSubscribers(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        db()->prepare("
            UPDATE subscribers
               SET status = ?, updated_at = ?
             WHERE status IN (?, ?)
               AND subscription_end IS NOT NULL
               AND subscription_end < ?
        ")->execute([
            SUB_STATUS_EXPIRED,
            appNow(),
            SUB_STATUS_ACTIVE,
            SUB_STATUS_SUSPENDED,
            appToday(),
        ]);
    } catch (Throwable $e) {
        error_log('Auto-expire subscribers failed: ' . $e->getMessage());
    }
}
autoExpirePastDueSubscribers();

// ── Force-disconnect: re-enforce router policy on expired subscribers ─
// Safety net for expired subscribers whose router sessions are still online.
// Spawns once per calendar day in background.
function maybeRunForceDisconnect(): void {
    static $triggered = false;
    if ($triggered) return;
    $triggered = true;
    if (PHP_SAPI === 'cli') return; // already inside a spawned script — don't recurse
    $sessionKey = 'force_disconnect_checked_' . date('Ymd');
    if (!empty($_SESSION[$sessionKey])) return;
    $_SESSION[$sessionKey] = true;
    try {
        try {
            $ran = db()->query(
                "SELECT ran_at FROM cronjob WHERE run_type='force_disconnect' ORDER BY ran_at DESC LIMIT 1"
            )->fetchColumn();
            if ($ran && date('Y-m-d', strtotime($ran)) === date('Y-m-d')) return;
        } catch (Throwable) {}
        $phpBinary = $_ENV['PHP_BINARY_PATH'] ?? PHP_BINARY;
        if (!is_file($phpBinary) || !is_executable($phpBinary)) {
            error_log("Force disconnect skipped: PHP binary is not executable ({$phpBinary})");
            return;
        }
        $logDir = BASE_PATH . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $php    = escapeshellarg($phpBinary);
        $script = escapeshellarg(BASE_PATH . '/modules/cronjobs/force_disconnect.php');
        $log    = escapeshellarg($logDir . '/force_disconnect.log');
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen("start /B {$php} {$script} >> {$log} 2>&1", 'r'));
        } else {
            exec("{$php} {$script} >> {$log} 2>&1 &");
        }
    } catch (Throwable $e) {
        error_log('Force disconnect spawn failed: ' . $e->getMessage());
    }
}
maybeRunForceDisconnect();
