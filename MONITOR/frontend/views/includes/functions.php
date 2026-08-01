<?php
// ── Safe redirect helper ──────────────────────────────────────
function safeRedirectUrl(string $url, string $default = ''): string {
    if ($url === '') return $default ?: (BASE_URL . '/modules/dashboard/');
    $parsed = parse_url($url);
    // Allow relative URLs (no host component)
    if (!isset($parsed['host'])) {
        return $url;
    }
    // For absolute URLs, host must match BASE_URL's host
    $base = parse_url(BASE_URL);
    if (($parsed['host'] ?? '') === ($base['host'] ?? '')) {
        return $url;
    }
    return $default ?: (BASE_URL . '/modules/dashboard/');
}

// ── System settings ───────────────────────────────────────────
function getSettings(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $pdo = db();
        $defaults = [
            'company_name'    => defined('APP_NAME') ? APP_NAME : 'NetManager',
            'company_desc'    => 'Internet Service Provider',
            'portal_name'     => defined('APP_NAME') ? APP_NAME : 'NetManager',
            'company_address' => '',
            'company_latitude' => '',
            'company_longitude' => '',
            'company_tin'     => '',
            'company_email'   => '',
            'company_contact' => '',
            'terms_conditions' => '',
            'timezone'        => 'Asia/Manila',
            'currency_symbol' => '₱',
        ];
        $ins = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)");
        foreach ($defaults as $k => $v) $ins->execute([$k, $v, appNow()]);
        $cache = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        $cache = [];
    }
    return $cache;
}

function getSetting(string $key, string $default = ''): string {
    return getSettings()[$key] ?? $default;
}

function saveSetting(string $key, string $value): void {
    $now = appNow();
    db()->prepare(
        "INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = ?"
    )->execute([$key, $value, $now, $now]);
}

// ── PHP time helpers ─────────────────────────────────────────
function appNow(string $format = 'Y-m-d H:i:s'): string {
    return date($format);
}

function appToday(string $format = 'Y-m-d'): string {
    return date($format);
}

function appDayStart(?string $date = null): string {
    return ($date ?: appToday()) . ' 00:00:00';
}

function appDayEnd(?string $date = null): string {
    return ($date ?: appToday()) . ' 23:59:59';
}

// ── Output helpers ────────────────────────────────────────────
function titleCase(string $str): string {
    return mb_convert_case(trim($str), MB_CASE_TITLE, 'UTF-8');
}

function e(mixed $val): string {
    return htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function jsonResponse(bool $success, string $message, array $data = []): never {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => $success, 'message' => $message, 'csrf_token' => csrfToken()], $data));
    exit;
}

function flashMessage(string $type, string $message): void {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function getFlashMessages(): array {
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

function renderFlash(): string {
    $messages = getFlashMessages();
    if (empty($messages)) return '';
    $json = json_encode($messages, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return '<script id="flashData" type="application/json">' . $json . '</script>';
}

function inlineToasts(array $msgs, string $defaultType = 'danger'): string {
    if (empty($msgs)) return '';
    $data = array_map(fn($m) => is_array($m) ? $m : ['message' => $m, 'type' => $defaultType], $msgs);
    $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return '<script class="nm-page-toasts" type="application/json">' . $json . '</script>';
}

// ── String helpers ────────────────────────────────────────────
function generateAccountNumber(): string {
    // Kept for legacy compatibility — use generateUniqueAccountNumber() instead
    return date('Y') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

function generatePassword(int $length = 6): string {
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    return substr(str_shuffle(str_repeat($chars, 4)), 0, $length);
}

function maskPassword(string $password): string {
    return str_repeat('*', strlen($password));
}

function formatDate(?string $date, string $format = 'M d, Y'): string {
    if (!$date) return '—';
    try {
        return (new DateTime($date))->format($format);
    } catch (Exception $e) {
        return '—';
    }
}

function formatMoney(float|string $amount): string {
    return getSetting('currency_symbol', '₱') . number_format((float)$amount, 2);
}

function expensePeriodTypesForReport(string $period): array {
    return match ($period) {
        'monthly' => ['daily', 'monthly'],
        'yearly'  => ['daily', 'monthly', 'yearly'],
        default   => ['daily'],
    };
}

function expensePeriodTypeSql(string $period, string $alias = 'e'): string {
    $types = array_map(
        fn(string $type): string => "'" . str_replace("'", "''", $type) . "'",
        expensePeriodTypesForReport($period)
    );
    $prefix = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) ? "{$alias}." : '';

    return " AND COALESCE({$prefix}period_type, 'daily') IN (" . implode(',', $types) . ")";
}

function reportPeriodFromDateRange(string $from, string $to): string {
    $fromTs = strtotime($from);
    $toTs   = strtotime($to);
    if ($fromTs === false || $toTs === false) return 'daily';
    if ($toTs < $fromTs) [$fromTs, $toTs] = [$toTs, $fromTs];

    $fromYmd = date('Y-m-d', $fromTs);
    $toYmd   = date('Y-m-d', $toTs);
    if ($fromYmd === $toYmd) return 'daily';

    if (date('Y', $fromTs) === date('Y', $toTs)
        && date('m-d', $fromTs) === '01-01'
        && date('m-d', $toTs) === '12-31') {
        return 'yearly';
    }

    if (date('Y-m', $fromTs) === date('Y-m', $toTs)
        && date('d', $fromTs) === '01') {
        return 'monthly';
    }

    return (($toTs - $fromTs) >= 365 * 86400) ? 'yearly' : 'daily';
}

function formatBytes(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 2)    . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 2)       . ' KB';
    return $bytes . ' B';
}

function routerosByteValue(mixed $value): int {
    if ($value === null || $value === '' || $value === '—') return 0;
    if (is_int($value)) return max(0, $value);
    if (is_float($value)) return max(0, (int)$value);

    $raw = trim((string)$value);
    if ($raw === '') return 0;

    $plain = str_replace([',', ' '], '', $raw);
    if (is_numeric($plain)) return max(0, (int)$plain);

    if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*([kmgtp]?i?b?)$/i', $raw, $m)) {
        $num  = (float)$m[1];
        $unit = strtolower($m[2] ?? '');
        $pow  = match ($unit[0] ?? '') {
            'k' => 1,
            'm' => 2,
            'g' => 3,
            't' => 4,
            'p' => 5,
            default => 0,
        };
        return max(0, (int)round($num * (1024 ** $pow)));
    }

    return 0;
}

function routerosFirstByteValue(array $row, array $keys): int {
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== '') {
            return routerosByteValue($row[$key]);
        }
    }
    return 0;
}

function routerosBytePair(mixed $value): ?array {
    if ($value === null || $value === '') return null;
    $parts = preg_split('/\s*\/\s*/', trim((string)$value));
    if (!$parts || count($parts) < 2) return null;

    return [
        routerosByteValue($parts[0]),
        routerosByteValue($parts[1]),
    ];
}

function routerosSessionBytes(array $row, array $inKeys, array $outKeys, array $pairKeys = ['bytes']): array {
    $bytesIn  = routerosFirstByteValue($row, $inKeys);
    $bytesOut = routerosFirstByteValue($row, $outKeys);

    if ($bytesIn === 0 && $bytesOut === 0) {
        foreach ($pairKeys as $key) {
            $pair = routerosBytePair($row[$key] ?? null);
            if ($pair !== null) {
                [$bytesIn, $bytesOut] = $pair;
                break;
            }
        }
    }

    return ['bytes_in' => $bytesIn, 'bytes_out' => $bytesOut];
}

function timeDiffHuman(string $datetime): string {
    try {
        $diff = (new DateTime())->diff(new DateTime($datetime));
        if ($diff->y > 0)  return $diff->y . 'y ago';
        if ($diff->m > 0)  return $diff->m . 'mo ago';
        if ($diff->d > 0)  return $diff->d . 'd ago';
        if ($diff->h > 0)  return $diff->h . 'h ago';
        if ($diff->i > 0)  return $diff->i . 'm ago';
        return 'Just now';
    } catch (Exception $e) {
        return '—';
    }
}

function subscriptionDaysLeft(?string $endDate): int {
    if (!$endDate) return 0;
    try {
        $end  = new DateTime($endDate);
        $now  = new DateTime();
        $diff = $now->diff($end);
        return $diff->invert ? 0 : $diff->days;
    } catch (Exception $e) {
        return 0;
    }
}

function daysUntilExpiry(?string $endDate): int {
    return subscriptionDaysLeft($endDate);
}

function isExpired(?string $endDate): bool {
    if (!$endDate) return false;
    return strtotime($endDate) < time();
}

function isExpiringSoon(?string $endDate, int $days = 3): bool {
    if (!$endDate) return false;
    $end = strtotime($endDate);
    return $end > time() && $end <= strtotime("+{$days} days");
}

function getBillingCycleMonths(string $cycle): int {
    return match($cycle) {
        'quarterly' => 3,
        'annual'    => 12,
        default     => 1,
    };
}

// ── IP / Security helpers ─────────────────────────────────────
function getClientIp(): string {
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

// ── Google reCAPTCHA ──────────────────────────────────────────
function verifyRecaptcha(string $response): bool {
    if (!defined('RECAPTCHA_ENABLED') || !RECAPTCHA_ENABLED || empty(RECAPTCHA_SECRET_KEY)) {
        return true;
    }
    if (empty($response)) return false;

    $payload = http_build_query([
        'secret'   => RECAPTCHA_SECRET_KEY,
        'response' => $response,
        'remoteip' => getClientIp(),
    ]);
    $ch = curl_init(RECAPTCHA_VERIFY_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);

    if (!$result) return false;
    $data = json_decode($result, true);
    return !empty($data['success']);
}

function canOverrideDates(): bool {
    return hasRole(ROLE_SUPERADMIN, ROLE_ADMIN);
}

function canSendMessages(): bool {
    return hasRole(ROLE_SUPERADMIN, ROLE_ADMIN, ROLE_CASHIER);
}

function canResetAccounts(): bool {
    return hasRole(ROLE_SUPERADMIN, ROLE_ADMIN, ROLE_CASHIER);
}

function canManageRouterAccounts(): bool {
    return hasRole(ROLE_SUPERADMIN, ROLE_ADMIN);
}

function subscriberSubscriptionExpired(array $subscriber): bool {
    return ($subscriber['status'] ?? '') === SUB_STATUS_EXPIRED
        || isExpired($subscriber['subscription_end'] ?? null);
}

function canViewMap(): bool {
    return hasRole(ROLE_SUPERADMIN, ROLE_ADMIN, ROLE_CASHIER, ROLE_USER, ROLE_LINEMAN);
}

function canEditSubscriberProtectedFields(array $subscriber): bool {
    if (hasRole(ROLE_SUPERADMIN)) return true;
    if (!hasRole(ROLE_ADMIN, ROLE_CASHIER)) return false;

    // Pending subscribers are fully editable — no time lock
    if (($subscriber['status'] ?? '') === SUB_STATUS_PENDING) return true;

    $createdAt = $subscriber['created_at'] ?? null;
    if (!$createdAt) return false;

    $createdTs = strtotime((string)$createdAt);
    return $createdTs !== false && $createdTs >= (time() - 600);
}

function telegramActivityEnabled(): bool {
    return defined('TELEGRAM_ENABLED') && TELEGRAM_ENABLED
        && defined('TELEGRAM_TOKEN') && TELEGRAM_TOKEN !== ''
        && defined('TELEGRAM_CHAT_ID') && TELEGRAM_CHAT_ID !== '';
}

function sendTelegramMessage(string $message): bool {
    if (!telegramActivityEnabled()) return false;

    $message = mb_substr($message, 0, 3900);
    $ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_TOKEN . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'chat_id' => TELEGRAM_CHAT_ID,
            'text'    => $message,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error || $httpCode < 200 || $httpCode >= 300) {
        error_log('Telegram activity send failed: ' . ($error ?: 'HTTP ' . $httpCode));
        return false;
    }

    $data = json_decode((string)$response, true);
    return !empty($data['ok']);
}

function sendTelegramActivity(string $module, string $action, string $description): void {
    if (!telegramActivityEnabled()) return;

    try {
        $user = currentUser();
        $actor = $user
            ? trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '')) . ' (@' . ($user['username'] ?? 'user') . ')'
            : 'System';
        $role = $user['role'] ?? 'system';
        $message = implode("\n", [
            APP_NAME . ' Activity',
            'Time: ' . appNow(),
            'Actor: ' . trim($actor),
            'Role: ' . $role,
            'Module: ' . $module,
            'Action: ' . $action,
            'IP: ' . getClientIp(),
            'Details: ' . $description,
        ]);
        sendTelegramMessage($message);
    } catch (Throwable $e) {
        error_log('Telegram activity error: ' . $e->getMessage());
    }
}

// ── Activity logging ──────────────────────────────────────────
function logActivity(
    string  $module,
    string  $action,
    string  $description,
    ?int    $subscriberId = null,
    ?array  $oldValue     = null,
    ?array  $newValue     = null
): void {
    try {
        // Strip newlines to prevent log injection
        $description = str_replace(["\r\n", "\r", "\n"], ' ', $description);
        $loggedAt = appNow();
        $description = buildActivityDescription($module, $action, $description, $subscriberId, $oldValue, $newValue, $loggedAt);
        $stmt = db()->prepare("
            INSERT INTO activity_log
                (user_id, subscriber_id, module, action, description, old_value, new_value, ip_address, user_agent, logged_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            currentUserId() ?: null,
            $subscriberId,
            $module,
            $action,
            $description,
            $oldValue  ? json_encode($oldValue,  JSON_UNESCAPED_UNICODE) : null,
            $newValue  ? json_encode($newValue,  JSON_UNESCAPED_UNICODE) : null,
            getClientIp(),
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512),
            $loggedAt,
        ]);
        sendTelegramActivity($module, $action, $description);
    } catch (PDOException $e) {
        try { sendTelegramActivity($module, $action, $description); } catch (Throwable) {}
    }
}

function buildActivityDescription(
    string $module,
    string $action,
    string $details,
    ?int $subscriberId = null,
    ?array $oldValue = null,
    ?array $newValue = null,
    ?string $loggedAt = null
): string {
    $loggedAt ??= appNow();
    $actor = currentUser();
    $actorName = $actor
        ? trim(($actor['firstname'] ?? '') . ' ' . ($actor['lastname'] ?? '')) . ' (@' . ($actor['username'] ?? 'user') . ')'
        : 'System / automated task';
    $actorRole = $actor['role'] ?? 'system';

    $lines = [
        'Time: ' . $loggedAt,
        'Actor: ' . trim($actorName),
        'Actor role: ' . (ROLES[$actorRole] ?? ucfirst((string)$actorRole)),
        'Module: ' . $module,
        'Action: ' . $action,
        'IP address: ' . getClientIp(),
        'Details: ' . trim($details),
    ];

    if ($subscriberId) {
        $lines[] = 'Subscriber ID: ' . $subscriberId;
        try {
            $stmt = db()->prepare("SELECT account_number, firstname, lastname FROM subscribers WHERE subscriber_id = ? LIMIT 1");
            $stmt->execute([$subscriberId]);
            $subscriber = $stmt->fetch();
            if ($subscriber) {
                $lines[] = 'Subscriber: ' . trim(($subscriber['account_number'] ?? '') . ' - ' . ($subscriber['firstname'] ?? '') . ' ' . ($subscriber['lastname'] ?? ''));
            }
        } catch (Throwable $e) {}
    }

    $changes = describeActivityChanges($oldValue, $newValue);
    if ($changes !== '') {
        $lines[] = 'Changed fields:';
        $lines[] = $changes;
    }

    return implode("\n", $lines);
}

function describeActivityChanges(?array $oldValue, ?array $newValue): string {
    if (!$oldValue && !$newValue) return '';
    $keys = array_unique(array_merge(array_keys($oldValue ?? []), array_keys($newValue ?? [])));
    $lines = [];
    foreach ($keys as $key) {
        $old = $oldValue[$key] ?? null;
        $new = $newValue[$key] ?? null;
        if (is_array($old) || is_object($old)) $old = json_encode($old, JSON_UNESCAPED_UNICODE);
        if (is_array($new) || is_object($new)) $new = json_encode($new, JSON_UNESCAPED_UNICODE);
        $oldText = $old === null || $old === '' ? 'blank' : (string)$old;
        $newText = $new === null || $new === '' ? 'blank' : (string)$new;
        if ($oldValue && $newValue && $oldText === $newText) continue;
        $lines[] = '- ' . $key . ': ' . ($oldValue ? $oldText : 'blank') . ' -> ' . ($newValue ? $newText : 'blank');
    }
    return implode("\n", $lines);
}

// ── Pagination ────────────────────────────────────────────────
function paginate(int $total, int $currentPage, int $perPage = DEFAULT_PER_PAGE): array {
    $totalPages = (int)ceil($total / $perPage);
    $currentPage = max(1, min($currentPage, $totalPages));
    return [
        'total'        => $total,
        'per_page'     => $perPage,
        'current_page' => $currentPage,
        'total_pages'  => $totalPages,
        'offset'       => ($currentPage - 1) * $perPage,
        'has_prev'     => $currentPage > 1,
        'has_next'     => $currentPage < $totalPages,
        'prev_page'    => $currentPage - 1,
        'next_page'    => $currentPage + 1,
    ];
}

function renderPagination(array $pag, string $baseUrl = '', string $anchor = ''): string {
    if ($pag['total_pages'] <= 1) return '';

    $sep  = str_contains($baseUrl, '?') ? '&' : '?';
    $hash = $anchor ? '#' . ltrim($anchor, '#') : '';
    $html = '<nav aria-label="Pagination"><ul class="pagination pagination-sm mb-0">';

    if ($pag['has_prev']) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . $sep . 'page=' . $pag['prev_page'] . $hash . '">
                    <i class="bi bi-chevron-left"></i></a></li>';
    }

    $start = max(1, $pag['current_page'] - 2);
    $end   = min($pag['total_pages'], $pag['current_page'] + 2);
    for ($i = $start; $i <= $end; $i++) {
        $active = $i === $pag['current_page'] ? ' active' : '';
        $html .= '<li class="page-item' . $active . '"><a class="page-link" href="' . $baseUrl . $sep . 'page=' . $i . $hash . '">' . $i . '</a></li>';
    }

    if ($pag['has_next']) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . $sep . 'page=' . $pag['next_page'] . $hash . '">
                    <i class="bi bi-chevron-right"></i></a></li>';
    }

    $html .= '</ul></nav>';
    return $html;
}

// ── Status badge helpers ──────────────────────────────────────
function subStatusBadge(string $status): string {
    $map = [
        'active'    => 'success',
        'suspended' => 'warning',
        'expired'   => 'danger',
        'pending'   => 'secondary',
        'archived'  => 'dark',
    ];
    $color = $map[$status] ?? 'secondary';
    return '<span class="badge bg-' . $color . '">' . ucfirst(e($status)) . '</span>';
}

function payStatusBadge(string $status): string {
    $map = [
        'paid'      => 'success',
        'pending'   => 'warning',
        'refunded'  => 'info',
        'cancelled' => 'danger',
    ];
    $color = $map[$status] ?? 'secondary';
    return '<span class="badge bg-' . $color . '">' . ucfirst(e($status)) . '</span>';
}

function routerStatusBadge(string $status): string {
    $map = [
        'online'      => 'success',
        'offline'     => 'danger',
        'maintenance' => 'warning',
    ];
    $color = $map[$status] ?? 'secondary';
    $dot   = '<span class="status-dot bg-' . $color . '"></span>';
    return $dot . ' <span class="badge bg-' . $color . '">' . ucfirst(e($status)) . '</span>';
}

// ── Encrypt / Decrypt for router passwords ───────────────────
function encryptData(string $data): string {
    $key   = substr(hash('sha256', APP_KEY), 0, 32);
    $iv    = random_bytes(16);
    $enc   = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
    return base64_encode($iv . $enc);
}

function decryptData(string $data): string {
    $key  = substr(hash('sha256', APP_KEY), 0, 32);
    $raw  = base64_decode($data);
    $iv   = substr($raw, 0, 16);
    $enc  = substr($raw, 16);
    return openssl_decrypt($enc, 'AES-256-CBC', $key, 0, $iv) ?: '';
}

// ── Validation helpers ────────────────────────────────────────
function validatePhone(string $phone): bool {
    return (bool)preg_match('/^(09|\+639)\d{9}$/', preg_replace('/\s/', '', $phone));
}

function sanitizePhone(string $phone): string {
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    if (str_starts_with($phone, '09')) {
        $phone = '+63' . substr($phone, 1);
    }
    return $phone;
}

function isValidIp(string $ip): bool {
    return (bool)filter_var($ip, FILTER_VALIDATE_IP);
}

// ── Unique account number ─────────────────────────────────────
function generateUniqueAccountNumber(): string {
    $pdo  = db();
    $year = date('Y');
    try {
        $pdo->beginTransaction();
        // Lock all standard YYYY-NNNN rows so concurrent requests queue up here
        $stmt = $pdo->prepare(
            "SELECT CAST(SUBSTRING_INDEX(account_number, '-', -1) AS UNSIGNED) AS seq
             FROM subscribers
             WHERE account_number LIKE ?
               AND account_number REGEXP ?
             FOR UPDATE"
        );
        $stmt->execute([$year . '-%', "^{$year}-[0-9]+\$"]);
        // Build a lookup of all used sequence numbers
        $used = array_flip(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

        // Find the lowest gap starting from 1 (fills holes before appending)
        $next = 1;
        while (isset($used[$next])) {
            $next++;
        }

        $number = $year . '-' . str_pad($next, 4, '0', STR_PAD_LEFT);
        $pdo->commit();
        return $number;
    } catch (PDOException $e) {
        try { $pdo->rollBack(); } catch (PDOException $re) {}
        return $year . '-T' . date('His') . mt_rand(10, 99);
    }
}

// ── Get router count ──────────────────────────────────────────
function getRouterCount(): int {
    return (int)db()->query("SELECT COUNT(*) FROM routers")->fetchColumn();
}

// ── OR / Receipt number ───────────────────────────────────────
function generateORNumber(): string {
    $pdo  = db();
    $year = date('Y');
    try {
        // Lock the table row so concurrent requests can't generate the same number
        $pdo->beginTransaction();
        // REGEXP matches both legacy 'OR-YYYY-NNNNNN' and new 'YYYY-NNNNNN' formats
        $stmt = $pdo->prepare(
            "SELECT or_number FROM payments WHERE or_number REGEXP ? ORDER BY payment_id DESC LIMIT 1 FOR UPDATE"
        );
        $stmt->execute(["^(OR-)?{$year}-[0-9]{6}$"]);
        $last = $stmt->fetchColumn();
        $seq  = $last ? ((int)substr($last, -6) + 1) : 1;
        $or   = $year . '-' . str_pad($seq, 6, '0', STR_PAD_LEFT);
        $pdo->commit();
        return $or;
    } catch (PDOException $e) {
        try { $pdo->rollBack(); } catch (PDOException $re) {}
        return $year . '-' . date('His') . substr((string)mt_rand(10, 99), 0, 2);
    }
}

// ── Payment methods (DB-backed, falls back to constants) ──────
function getPaymentTypes(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $pdo = db();
        if ((int)$pdo->query("SELECT COUNT(*) FROM payment_types")->fetchColumn() === 0) {
            $ins = $pdo->prepare("INSERT INTO payment_types (name, description, is_default, sort_order, created_at) VALUES (?,?,?,?,?)");
            foreach ([
                ['Subscription',                'Monthly/annual internet subscription',  1, 0],
                ['Installation Fee',            'One-time connection setup fee',          0, 1],
                ['Modem / Equipment',           'Hardware replacement or deposit',        0, 2],
                ['Relocation & Reconnection',   'Address change or re-activation fee',   0, 3],
                ['Service Charge / Late Penalty','Technician visit or overdue fee',       0, 4],
                ['Other',                       'Miscellaneous charge',                   0, 5],
            ] as $row) {
                $ins->execute([...$row, appNow()]);
            }
        }
        $cache = $pdo->query(
            "SELECT type_id, name, description, is_active, is_default, sort_order FROM payment_types ORDER BY sort_order, name"
        )->fetchAll();
    } catch (PDOException $e) {
        $cache = [];
    }
    return $cache;
}

function getPaymentMethods(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $pdo = db();
        $count = (int)$pdo->query("SELECT COUNT(*) FROM payment_methods")->fetchColumn();
        if ($count === 0) {
            $ins = $pdo->prepare("INSERT IGNORE INTO payment_methods (name, code, sort_order) VALUES (?, ?, ?)");
            foreach ([['CASH','CASH',0],['GCASH','GCASH',1],['CHEQUE','CHEQUE',2],
                      ['BPI','BPI',3],['PNB','PNB',4],['BDO','BDO',5],
                      ['METROBANK','METROBANK',6],['EASTWEST','EASTWEST',7],['XENDIT','XENDIT',8]] as $row) {
                $ins->execute($row);
            }
        }
        $cache = $pdo->query(
            "SELECT code, name FROM payment_methods WHERE is_active = 1 ORDER BY sort_order, name"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        $cache = PAY_METHODS;
    }
    return $cache;
}

function routerOptions(): array {
    try {
        return db()->query("SELECT router_id, name, host FROM routers ORDER BY name")->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

function defaultRouterForUser(array $user): ?array {
    $routerId = (int)($user['router_id'] ?? 0);
    try {
        if ($routerId > 0) {
            $stmt = db()->prepare("SELECT router_id, name FROM routers WHERE router_id = ? LIMIT 1");
            $stmt->execute([$routerId]);
            $router = $stmt->fetch();
            if ($router) return $router;
        }

        $router = db()->query("SELECT router_id, name FROM routers ORDER BY name LIMIT 1")->fetch();
        if ($router && !empty($user['user_id'])) {
            db()->prepare("UPDATE users SET router_id = ?, updated_at = ? WHERE user_id = ? AND role <> ?")
               ->execute([(int)$router['router_id'], appNow(), (int)$user['user_id'], ROLE_SUPERADMIN]);
        }
        return $router ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

function scopedRouterId(?int $requested = null): int {
    $selected = (int)(selectedRouterId() ?: 0);
    if (currentRole() === ROLE_SUPERADMIN) {
        return (int)($requested ?: $selected);
    }
    return $selected;
}

function routerIsConnected(int $routerId): bool {
    if ($routerId <= 0) return false;
    try {
        $stmt = db()->prepare("SELECT status FROM routers WHERE router_id = ? LIMIT 1");
        $stmt->execute([$routerId]);
        return $stmt->fetchColumn() === ROUTER_ONLINE;
    } catch (PDOException $e) {
        return false;
    }
}

function routerPortalEnabled(int $routerId): bool {
    if ($routerId <= 0) return false;
    try {
        $stmt = db()->prepare("SELECT portal_enabled FROM routers WHERE router_id = ? AND status = ? LIMIT 1");
        $stmt->execute([$routerId, ROUTER_ONLINE]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

function subscriberPortalAccessAllowed(int $subscriberId): bool {
    if ($subscriberId <= 0) return false;
    try {
        $stmt = db()->prepare("
            SELECT r.portal_enabled
            FROM subscribers s
            INNER JOIN routers r ON r.router_id = s.router_id
            INNER JOIN plans p ON p.plan_id = s.plan_id
            WHERE s.subscriber_id = ?
              AND r.status = ?
              AND r.portal_enabled = 1
              AND p.portal_enabled = 1
            LIMIT 1
        ");
        $stmt->execute([$subscriberId, ROUTER_ONLINE]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

function isReadOnlyUser(): bool {
    return in_array(currentRole(), [ROLE_USER, ROLE_LINEMAN], true);
}

function canModifyRecords(): bool {
    return isLoggedIn() && !isReadOnlyUser();
}

function canPrintSensitiveRecords(): bool {
    return hasRole(ROLE_CASHIER, ROLE_ADMIN, ROLE_SUPERADMIN);
}

function requireCanPrintSensitiveRecords(): void {
    requireLogin();
    if (!canPrintSensitiveRecords()) forbidden();
}

function requireCanModify(): void {
    requireLogin();
    if (!canModifyRecords()) forbidden();
}

// ── Router security helpers ───────────────────────────────────

/**
 * Validate a router host value — must be a valid IPv4 or RFC-1123 hostname.
 * Rejects URL schemes (SSRF guard), null bytes, and overly long values.
 */
function validateRouterHost(string $host): bool {
    if (empty($host) || strlen($host) > 253) return false;
    if (preg_match('/^[a-zA-Z][a-zA-Z0-9+\-.]*:\/\//', $host)) return false; // reject URL schemes
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $host)) return false; // reject control chars
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return true;
    return (bool)preg_match(
        '/^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$/',
        $host
    );
}


// ── SMS OTP helper for 2FA ────────────────────────────────────
function generateOTP(int $length = 6): string {
    return str_pad((string)random_int(0, (int)str_repeat('9', $length)), $length, '0', STR_PAD_LEFT);
}

function routerosRowIsDisabled(array $row): bool {
    return in_array(strtolower((string)($row['disabled'] ?? 'no')), ['yes', 'true', '1'], true);
}

// ── Router re-activation after payment ───────────────────────
// For renewals: if the router user is disabled, enable it. If already
// enabled, restore the normal plan group/profile from ppp_profile.
// Expects subscriber row fields aliased as in view.php router JOIN:
//   router_id, ppp_username, ppp_profile, connection_type,
//   router_host, api_port, router_port, r_user, r_pass,
//   auth_type, router_status
function reactivateSubscriberOnRouter(array $sub): void {
    if (!class_exists('RouterosAPI')) return;
    if (empty($sub['router_id']) || empty($sub['ppp_username'])) return;
    if (($sub['router_status'] ?? '') === 'maintenance') return;

    $host    = $sub['router_host'] ?? '';
    $apiPort = (int)($sub['api_port'] ?: ($sub['router_port'] ?? 8728));
    $rUser   = $sub['r_user'] ?? '';
    $rPass   = decryptData($sub['r_pass'] ?? '');

    if (!$host || !$rUser || !$rPass) return;

    try {
        $api = new RouterosAPI($host, $apiPort, 3);
        if (!$api->connect($rUser, $rPass)) return;

        $isPPP    = ($sub['connection_type'] ?? 'ppp') === 'ppp';
        $isRadius = ($sub['auth_type'] ?? 'local') === 'radius';
        $uname    = $sub['ppp_username'];
        $profile  = trim((string)($sub['ppp_profile'] ?? ''));

        if ($isRadius) {
            $user = $api->findUserManagerUserByNameRobust($uname);
            if (!empty($user['.id']) && routerosRowIsDisabled($user)) {
                $api->setUserManagerUserDisabledRobust($uname, false);
            } elseif (!empty($user['.id']) && $profile !== '') {
                $api->setUserManagerUserGroupRobust($uname, $profile, true);
            }
        } elseif ($isPPP) {
            $rows = $api->query('/ppp/secret/print', [], ['?name=' . $uname]);
            $user = $rows[0] ?? [];
            if (!empty($user['.id']) && routerosRowIsDisabled($user)) {
                $api->setPPPSecretDisabled($uname, false);
            } elseif (!empty($user['.id']) && $profile !== '') {
                $api->setPPPSecretProfile($uname, $profile, true);
            }
        } else {
            $rows = $api->query('/ip/hotspot/user/print', [], ['?name=' . $uname]);
            $user = $rows[0] ?? [];
            if (!empty($user['.id']) && routerosRowIsDisabled($user)) {
                $api->setHotspotUserDisabled($uname, false);
            } elseif (!empty($user['.id']) && $profile !== '') {
                $api->setHotspotUserProfile($uname, $profile, true);
            }
        }

        if ($isRadius) {
            $api->disconnectUserManagerSessionsRobust($uname);
        }
        $isPPP
            ? $api->disconnectPPPSessionsByNameRobust($uname)
            : $api->disconnectHotspotSessionsByUserRobust($uname);

        $api->disconnect();
    } catch (Throwable) {}
}
