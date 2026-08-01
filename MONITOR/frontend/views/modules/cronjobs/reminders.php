<?php
/**
 * Expiry Reminder Cron Script
 *
 * Sends an email to every active subscriber whose subscription expires
 * within the next 7 days.
 *
 * CLI (crontab):
 *   30 23 * * * /usr/bin/php /path/to/modules/cronjobs/reminders.php
 *
 * HTTP (token-secured):
 *   GET /modules/cronjobs/reminders.php
 *   Header: X-Cron-Token: REMINDER_CRON_TOKEN (or CRON_TOKEN)
 */

ob_start();

if (PHP_SAPI !== 'cli') {
    define('BASE_PATH', dirname(dirname(__DIR__)));

    function remCronLoadEnv(string $path): void {
        if (!file_exists($path)) return;
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            [$name, $value] = explode('=', $line, 2);
            $name = trim($name); $value = trim($value);
            if (preg_match('/^"(.*)"$/s', $value, $m) || preg_match("/^'(.*)'$/s", $value, $m)) $value = $m[1];
            if (!array_key_exists($name, $_ENV)) { $_ENV[$name] = $value; putenv("$name=$value"); }
        }
    }
    remCronLoadEnv(BASE_PATH . '/.env');

    define('APP_KEY',  $_ENV['APP_KEY']  ?? '');
    define('APP_NAME', $_ENV['APP_NAME'] ?? 'NetManager');
    define('APP_TIMEZONE', $_ENV['APP_TIMEZONE'] ?? 'Asia/Manila');
    define('MAIL_ENABLED', filter_var($_ENV['MAIL_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN));
    date_default_timezone_set(APP_TIMEZONE);

    $expectedToken = $_ENV['REMINDER_CRON_TOKEN'] ?? ($_ENV['CRON_TOKEN'] ?? '');
    $headerToken = $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
    if ($expectedToken === '' || $headerToken === '' || !hash_equals($expectedToken, $headerToken)) {
        http_response_code(403); die('Forbidden');
    }

    require_once BASE_PATH . '/config/database.php';

    $__va = BASE_PATH . '/vendor/autoload.php';
    if (file_exists($__va)) require_once $__va;
} else {
    define('BASE_PATH', dirname(dirname(__DIR__)));
    require_once BASE_PATH . '/config/config.php';
}

require_once BASE_PATH . '/lib/Mailer.php';

// Fallback helpers (available via config.php in CLI; defined here for HTTP mode)
if (!function_exists('getSetting')) {
    function getSetting(string $key, string $default = ''): string {
        static $cache = [];
        if (isset($cache[$key])) return $cache[$key];
        try {
            $stmt = db()->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            return $cache[$key] = (string)($stmt->fetchColumn() ?: $default);
        } catch (Exception $e) { return $default; }
    }
}
if (!function_exists('formatDate')) {
    function formatDate(string $date, string $fmt = 'M d, Y'): string {
        return $date ? date($fmt, strtotime($date)) : '—';
    }
}
if (!function_exists('appNow')) {
    function appNow(string $format = 'Y-m-d H:i:s'): string { return date($format); }
}
if (!function_exists('appToday')) {
    function appToday(string $format = 'Y-m-d'): string { return date($format); }
}

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

$pdo = Database::getInstance(); 

// Active subscribers expiring in 1–7 days with an email address,
// skipping those who already received a reminder today.
$expiring = $pdo->query("
    SELECT s.*,
           DATEDIFF(s.subscription_end, " . $pdo->quote(appToday()) . ") AS days_left,
           p.title AS plan_title
    FROM subscribers s
    LEFT JOIN plans p ON p.plan_id = s.plan_id
    WHERE s.status = 'active'
      AND s.email IS NOT NULL
      AND s.email <> ''
      AND s.subscription_end BETWEEN " . $pdo->quote(appToday()) . " AND DATE_ADD(" . $pdo->quote(appToday()) . ", INTERVAL 7 DAY)
    ORDER BY s.subscription_end ASC
")->fetchAll();

// Count-only mode for the confirmation modal
if (($_GET['mode'] ?? '') === 'count') {
    echo json_encode([
        'success'        => true,
        'eligible_count' => count($expiring),
    ]);
    exit;
}

$sent    = 0;
$errors  = [];
$details = [];

foreach ($expiring as $sub) {
    $daysLeft = (int)$sub['days_left'];
    $result   = Mailer::sendSubscriptionExpiry($sub, $daysLeft);
    $ok       = $result['success'];

    // Activity log
    try {
        $loggedAt = appNow();
        $desc = implode("\n", [
            'Time: ' . $loggedAt,
            'Actor: System / automated task',
            'Actor role: system',
            'Module: subscribers',
            'Action: reminder',
            'IP address: cron',
            'Details: ' . ($ok ? 'Subscription expiry reminder sent.' : 'Subscription expiry reminder failed.'),
            'Subscriber ID: ' . $sub['subscriber_id'],
            'Subscriber: ' . trim(($sub['account_number'] ?? '') . ' - ' . ($sub['firstname'] ?? '') . ' ' . ($sub['lastname'] ?? '')),
            'Email: ' . ($sub['email'] ?? ''),
            'Days left: ' . $daysLeft,
            'Subscription end: ' . ($sub['subscription_end'] ?? ''),
            'Send result: ' . ($ok ? 'success' : ($result['error'] ?? 'failed')),
        ]);
        $pdo->prepare("
            INSERT INTO activity_log (module, action, description, subscriber_id, ip_address, logged_at)
            VALUES ('subscribers', 'reminder', ?, ?, 'cron', ?)
        ")->execute([
            $desc,
            $sub['subscriber_id'],
            $loggedAt,
        ]);
    } catch (Exception $e) {}

    if ($ok) {
        $sent++;
        $details[] = [
            'account_number'   => $sub['account_number'],
            'name'             => trim(($sub['firstname'] ?? '') . ' ' . ($sub['lastname'] ?? '')),
            'email'            => $sub['email'],
            'days_left'        => $daysLeft,
            'subscription_end' => $sub['subscription_end'],
        ];
    } else {
        $errors[] = $sub['account_number'] . ': ' . ($result['error'] ?? 'Send failed');
    }
}

try {
    $pdo->prepare("
        INSERT INTO cronjob (run_type, ran_at, processed, errors_count, errors_json, expired_json)
        VALUES ('reminder', ?, ?, ?, ?, ?)
    ")->execute([
        appNow(),
        $sent,
        count($errors),
        $errors  ? json_encode($errors)  : null,
        $details ? json_encode($details) : null,
    ]);
} catch (Exception $e) {}

echo json_encode([
    'success'   => true,
    'sent'      => $sent,
    'skipped'   => 0,
    'errors'    => $errors,
    'timestamp' => appNow(),
]);
