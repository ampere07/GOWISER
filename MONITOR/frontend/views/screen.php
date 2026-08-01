        <?php
        require_once __DIR__ . '/config/config.php';

        // ── Screen OTP constants ──────────────────────────────────────
        const SCREEN_OTP_TTL    = 300;        // 5 min
        const SCREEN_COOKIE_TTL = 2592000;    // 30 days
        const SCREEN_MAX_TRIES  = 5;
        const SCREEN_COOKIE     = 'screen_access';
        const SCREEN_SUBSCRIBER_ROUTER_ID = 17;

        function screenCookieSecret(): string {
            static $s;
            if (!$s) $s = hash('sha256', implode('|', [
                defined('APP_KEY')  ? APP_KEY  : '',
                defined('DB_PASS')  ? DB_PASS  : '',
                defined('APP_NAME') ? APP_NAME : '',
                __DIR__,
            ]));
            return $s;
        }

        function screenMakeCookie(int $expires): string {
            $payload = base64_encode(json_encode(['exp' => $expires]));
            $sig     = hash_hmac('sha256', $payload, screenCookieSecret());
            return $payload . '.' . $sig;
        }

        function screenVerifyCookie(string $token): bool {
            $parts = explode('.', $token, 2);
            if (count($parts) !== 2) return false;
            [$payload, $sig] = $parts;
            if (!hash_equals(hash_hmac('sha256', $payload, screenCookieSecret()), $sig)) return false;
            $data = json_decode(base64_decode($payload), true);
            return !empty($data['exp']) && time() < $data['exp'];
        }

        function screenIsAuth(): bool {
            return !empty($_COOKIE[SCREEN_COOKIE]) && screenVerifyCookie($_COOKIE[SCREEN_COOKIE]);
        }

        function screenNormalizedUser(?string $value): string {
            return strtolower(trim((string)$value));
        }

        function screenCollectUsernames(array $rows, array $keys): array {
            $users = [];
            foreach ($rows as $row) {
                foreach ($keys as $key) {
                    $user = screenNormalizedUser($row[$key] ?? '');
                    if ($user !== '') {
                        $users[$user] = true;
                        break;
                    }
                }
            }
            return array_keys($users);
        }

        function screenActiveSubscriberIdsForRouter(int $routerId): array {
            $stmt = db()->prepare("
                SELECT subscriber_id
                FROM subscribers
                WHERE router_id = ?
                AND status = 'active'
                AND (subscription_end IS NULL OR subscription_end >= CURDATE())
            ");
            $stmt->execute([$routerId]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        function screenSubscriberIdsForRouterUsernames(int $routerId, array $usernames, bool $activeOnly = true): array {
            $usernames = array_values(array_unique(array_filter(array_map('screenNormalizedUser', $usernames))));
            if (empty($usernames)) return [];

            $ids = [];
            foreach (array_chunk($usernames, 500) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $sql = "
                    SELECT subscriber_id
                    FROM subscribers
                    WHERE router_id = ?
                    AND LOWER(ppp_username) IN ({$ph})
                ";
                if ($activeOnly) {
                    $sql .= "
                    AND status = 'active'
                    AND (subscription_end IS NULL OR subscription_end >= CURDATE())
                    ";
                }
                $stmt = db()->prepare($sql);
                $stmt->execute(array_merge([$routerId], $chunk));
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    $ids[(int)$id] = true;
                }
            }

            return array_keys($ids);
        }

        function screenLiveMikrotikSubscriberCounts(): array {
            require_once __DIR__ . '/lib/RouterosAPI.php';

            $routers = db()->query("
                SELECT router_id, name, host, api_port, port, username, password,
                    COALESCE(status, '') AS status,
                    COALESCE(auth_type, 'local') AS auth_type,
                    um_prefix
                FROM routers
                WHERE COALESCE(host, '') <> ''
                AND COALESCE(username, '') <> ''
                AND router_id = " . SCREEN_SUBSCRIBER_ROUTER_ID . "
            ")->fetchAll();

            $onlineIds = [];
            $configuredIds = [];

            foreach ($routers as $router) {
                $routerId = (int)$router['router_id'];
                $routerActiveFallback = null;
                $api = null;

                try {
                    $timeout = (($router['auth_type'] ?? 'local') === 'radius') ? 8 : 5;
                    $api = new RouterosAPI($router['host'], (int)($router['api_port'] ?: $router['port']), $timeout);
                    if (!$api->connect($router['username'], decryptData($router['password']))) {
                        foreach (screenActiveSubscriberIdsForRouter($routerId) as $id) {
                            $configuredIds[$id] = true;
                        }
                        continue;
                    }

                    $pppActive = $api->query('/ppp/active/print', ['.proplist' => 'name']);
                    $hsActive  = $api->query('/ip/hotspot/active/print', ['.proplist' => 'user,name']);

                    $activeUsers = array_merge(
                        screenCollectUsernames($pppActive, ['name']),
                        screenCollectUsernames($hsActive, ['user', 'name'])
                    );

                    $configuredUsers = [];
                    if (($router['auth_type'] ?? 'local') === 'radius') {
                        if (($router['um_prefix'] ?? '') !== '') {
                            $api->setUmPrefix($router['um_prefix']);
                        }

                        $umActive = [];
                        try {
                            $umActive = $api->getUserManagerActiveSessions();
                            $activeUsers = array_merge(
                                $activeUsers,
                                screenCollectUsernames($umActive, ['user', 'username', 'name'])
                            );
                        } catch (Throwable $_) {}

                        try {
                            $prefix = $api->umPrefix();
                            if ($prefix !== '') {
                                $umUsers = $api->query($prefix . '/user/print', ['.proplist' => 'name,user,username,disabled']);
                                foreach ($umUsers as $u) {
                                    $disabled = strtolower((string)($u['disabled'] ?? 'false'));
                                    if (in_array($disabled, ['true', 'yes', '1'], true)) continue;
                                    $configuredUsers = array_merge(
                                        $configuredUsers,
                                        screenCollectUsernames([$u], ['name', 'user', 'username'])
                                    );
                                }
                            }
                        } catch (Throwable $_) {}

                        $detected = $api->getDetectedUmPrefix();
                        if (($router['um_prefix'] ?? '') === '' && $detected !== null) {
                            try {
                                db()->prepare("UPDATE routers SET um_prefix = ? WHERE router_id = ?")
                                    ->execute([$detected, $routerId]);
                            } catch (Throwable $_) {}
                        }
                    } else {
                        try {
                            $pppUsers = $api->query('/ppp/secret/print', ['.proplist' => 'name,disabled']);
                            foreach ($pppUsers as $u) {
                                $disabled = strtolower((string)($u['disabled'] ?? 'false'));
                                if (in_array($disabled, ['true', 'yes', '1'], true)) continue;
                                $configuredUsers = array_merge($configuredUsers, screenCollectUsernames([$u], ['name']));
                            }
                        } catch (Throwable $_) {}

                        try {
                            $hsUsers = $api->query('/ip/hotspot/user/print', ['.proplist' => 'name,user,disabled']);
                            foreach ($hsUsers as $u) {
                                $disabled = strtolower((string)($u['disabled'] ?? 'false'));
                                if (in_array($disabled, ['true', 'yes', '1'], true)) continue;
                                $configuredUsers = array_merge($configuredUsers, screenCollectUsernames([$u], ['name', 'user']));
                            }
                        } catch (Throwable $_) {}
                    }

                    $api->disconnect();

                    foreach (screenSubscriberIdsForRouterUsernames($routerId, $activeUsers, true) as $id) {
                        $onlineIds[$id] = true;
                        $configuredIds[$id] = true;
                    }

                    $configuredForRouter = screenSubscriberIdsForRouterUsernames($routerId, $configuredUsers, true);
                    if (empty($configuredForRouter)) {
                        $routerActiveFallback = screenActiveSubscriberIdsForRouter($routerId);
                        $configuredForRouter = $routerActiveFallback;
                    }

                    foreach ($configuredForRouter as $id) {
                        $configuredIds[$id] = true;
                    }
                } catch (Throwable $_) {
                    if ($api instanceof RouterosAPI && $api->isConnected()) {
                        try { $api->disconnect(); } catch (Throwable $_) {}
                    }
                    foreach ($routerActiveFallback ?? screenActiveSubscriberIdsForRouter($routerId) as $id) {
                        $configuredIds[$id] = true;
                    }
                }
            }

            $online = count($onlineIds);
            $offline = 0;
            foreach ($configuredIds as $id => $_) {
                if (!isset($onlineIds[$id])) $offline++;
            }

            return ['online' => $online, 'offline' => $offline];
        }

        // ── Auth AJAX handlers (need session write access) ────────────
        if (isset($_GET['action'])) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            $action = $_GET['action'];

            if ($action === 'send_otp') {
                $lastSent = (int)($_SESSION['screen_otp_sent_at'] ?? 0);
                if (time() - $lastSent < 60) {
                    $wait = 60 - (time() - $lastSent);
                    echo json_encode(['success' => false, 'message' => "Please wait {$wait}s before requesting another code."]);
                    session_write_close(); exit;
                }

                $email = trim(getSetting('company_email', ''));
                if (empty($email)) {
                    echo json_encode(['success' => false, 'message' => 'Company email is not configured. Set it in Settings.']);
                    session_write_close(); exit;
                }

                $otp = sprintf('%06d', random_int(0, 999999));
                $_SESSION['screen_otp'] = [
                    'code'     => password_hash($otp, PASSWORD_DEFAULT),
                    'expires'  => time() + SCREEN_OTP_TTL,
                    'attempts' => 0,
                ];
                $_SESSION['screen_otp_sent_at'] = time();

                require_once __DIR__ . '/lib/Mailer.php';
                $company = getSetting('company_name', APP_NAME);
                $subject = "{$company} Live Screen — Access Code";
                $html    = "<p style='font-family:sans-serif;'>Your Live Screen access code is:</p>"
                        . "<p style='font-family:monospace;font-size:32px;letter-spacing:.15em;font-weight:700;'>{$otp}</p>"
                        . "<p style='font-family:sans-serif;color:#888;'>Expires in 5 minutes. Do not share this code.</p>";
                $result  = Mailer::send($email, $company, $subject, $html, "Access code: {$otp}. Expires in 5 minutes.");

                if (!($result['success'] ?? false)) {
                    echo json_encode(['success' => false, 'message' => 'Failed to send email: ' . ($result['error'] ?? 'Check email settings.')]);
                    session_write_close(); exit;
                }

                $parts   = explode('@', $email, 2);
                $local   = $parts[0];
                $domain  = $parts[1] ?? '';
                $visible = min(2, strlen($local));
                $masked  = substr($local, 0, $visible) . str_repeat('*', max(0, strlen($local) - $visible)) . '@' . $domain;
                echo json_encode(['success' => true, 'masked' => $masked]);
                session_write_close(); exit;
            }

            if ($action === 'verify_otp') {
                $code = preg_replace('/\D/', '', $_POST['otp'] ?? '');

                if (empty($_SESSION['screen_otp'])) {
                    echo json_encode(['success' => false, 'message' => 'Session expired. Please request a new code.']);
                    session_write_close(); exit;
                }

                $stored = $_SESSION['screen_otp'];

                if (time() > ($stored['expires'] ?? 0)) {
                    unset($_SESSION['screen_otp']);
                    echo json_encode(['success' => false, 'message' => 'Code expired. Please request a new one.']);
                    session_write_close(); exit;
                }

                if (($stored['attempts'] ?? 0) >= SCREEN_MAX_TRIES) {
                    unset($_SESSION['screen_otp']);
                    echo json_encode(['success' => false, 'message' => 'Too many attempts. Please request a new code.']);
                    session_write_close(); exit;
                }

                if (!password_verify($code, $stored['code'])) {
                    $_SESSION['screen_otp']['attempts']++;
                    $left = SCREEN_MAX_TRIES - $_SESSION['screen_otp']['attempts'];
                    echo json_encode(['success' => false, 'message' => "Invalid code. {$left} attempt(s) remaining."]);
                    session_write_close(); exit;
                }

                unset($_SESSION['screen_otp']);

                $expires = time() + SCREEN_COOKIE_TTL;
                setcookie(SCREEN_COOKIE, screenMakeCookie($expires), [
                    'expires'  => $expires,
                    'path'     => '/',
                    'httponly' => true,
                    'samesite' => 'Lax',
                    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                ]);

                echo json_encode(['success' => true]);
                session_write_close(); exit;
            }

            if ($action === 'logout') {
                setcookie(SCREEN_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
                echo json_encode(['success' => true]);
                session_write_close(); exit;
            }

            if ($action === 'subscriber_counts') {
                session_write_close();
                if (!screenIsAuth()) {
                    echo json_encode(['success' => false, 'auth' => false]);
                    exit;
                }
                @set_time_limit(60);
                try {
                    // DB: expired + pending
                    $dbStats = db()->query("
                        SELECT
                            SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) AS expired,
                            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending
                        FROM subscribers
                        WHERE router_id = " . SCREEN_SUBSCRIBER_ROUTER_ID . "
                    ")->fetch() ?: ['expired' => 0, 'pending' => 0];

                    // MikroTik live report: active sessions determine online; configured
                    // MikroTik users minus those active sessions determine offline.
                    $mikrotikStats = screenLiveMikrotikSubscriberCounts();

                    echo json_encode([
                        'success' => true,
                        'online'  => $mikrotikStats['online'],
                        'offline' => $mikrotikStats['offline'],
                        'expired' => (int)($dbStats['expired'] ?? 0),
                        'pending' => (int)($dbStats['pending'] ?? 0),
                    ]);
                } catch (Throwable $e) {
                    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                }
                exit;
            }

            echo json_encode(['success' => false, 'message' => 'Unknown action.']);
            session_write_close(); exit;
        }

        // Close session write lock — read-only from this point on
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        header('X-Content-Type-Options: nosniff');

        function screenRange(string $period): array {
            $today = new DateTime(appToday());
            return match ($period) {
                'weekly' => [
                    (clone $today)->modify('monday this week')->format('Y-m-d'),
                    (clone $today)->modify('sunday this week')->format('Y-m-d'),
                    'This Week',
                ],
                'monthly' => [
                    $today->format('Y-m-01'),
                    $today->format('Y-m-t'),
                    $today->format('F Y'),
                ],
                'yearly' => [
                    $today->format('Y-01-01'),
                    $today->format('Y-12-31'),
                    $today->format('Y'),
                ],
                default => [
                    $today->format('Y-m-d'),
                    $today->format('Y-m-d'),
                    'Today',
                ],
            };
        }

        function screenMoney(float $amount): string {
            return getSetting('currency_symbol', '₱') . number_format($amount, 2);
        }

        function screenBranchReport(string $period): array {
            [$start, $end, $label] = screenRange($period);
            $expensePeriodWhere = expensePeriodTypeSql($period, 'e');

            $stmt = db()->prepare("
                SELECT
                    r.router_id,
                    r.name AS router,
                    r.host,
                    r.status,
                    r.cpu_load,
                    COALESCE(pay.collection, 0) AS collection,
                    COALESCE(pay.payment_count, 0) AS payment_count,
                    COALESCE(exp.expenses, 0) AS expenses,
                    COALESCE(exp.expense_count, 0) AS expense_count,
                    COALESCE(pay.collection, 0) - COALESCE(exp.expenses, 0) AS net
                FROM routers r
                LEFT JOIN (
                    SELECT
                        COALESCE(s.router_id, py.router_id) AS router_id,
                        SUM(py.amount) AS collection,
                        COUNT(*) AS payment_count
                    FROM payments py
                    LEFT JOIN subscribers s ON s.subscriber_id = py.subscriber_id
                    WHERE py.status = 'paid'
                    AND DATE(py.payment_date) BETWEEN ? AND ?
                    GROUP BY COALESCE(s.router_id, py.router_id)
                ) pay ON pay.router_id = r.router_id
                LEFT JOIN (
                    SELECT
                        e.router_id,
                        SUM(e.amount) AS expenses,
                        COUNT(*) AS expense_count
                    FROM expenses e
                    WHERE DATE(e.expense_date) BETWEEN ? AND ?
                    {$expensePeriodWhere}
                    GROUP BY e.router_id
                ) exp ON exp.router_id = r.router_id
                ORDER BY net DESC, collection DESC, r.name ASC
            ");
            $stmt->execute([$start, $end, $start, $end]);
            $branches = $stmt->fetchAll();

            $totals = [
                'collection' => 0.0,
                'expenses' => 0.0,
                'net' => 0.0,
                'payment_count' => 0,
                'expense_count' => 0,
            ];

            foreach ($branches as &$row) {
                $row['collection'] = (float)$row['collection'];
                $row['expenses'] = (float)$row['expenses'];
                $row['net'] = (float)$row['net'];
                $row['payment_count'] = (int)$row['payment_count'];
                $row['expense_count'] = (int)$row['expense_count'];
                $totals['collection'] += $row['collection'];
                $totals['expenses'] += $row['expenses'];
                $totals['net'] += $row['net'];
                $totals['payment_count'] += $row['payment_count'];
                $totals['expense_count'] += $row['expense_count'];
            }
            unset($row);

            return [
                'period' => $period,
                'label' => $label,
                'start' => $start,
                'end' => $end,
                'branches' => $branches,
                'totals' => $totals,
            ];
        }

        if (isset($_GET['data'])) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

            if (!screenIsAuth()) {
                echo json_encode(['success' => false, 'auth' => false, 'message' => 'Authentication required.']);
                exit;
            }

            try {
                $periods = [];
                foreach (['daily', 'weekly', 'monthly', 'yearly'] as $period) {
                    $periods[$period] = screenBranchReport($period);
                }

                $routerStats = db()->query("
                    SELECT
                        COUNT(*) AS total,
                        SUM(status = 'online') AS online,
                        SUM(status = 'offline') AS offline,
                        SUM(status = 'maintenance') AS maintenance
                    FROM routers
                ")->fetch() ?: ['total' => 0, 'online' => 0, 'offline' => 0, 'maintenance' => 0];

                /* ── activity log (last 7 days) ─────────── */
                $currency_sym = getSetting('currency_symbol', '₱');
                $actLog = [];

                try {
                    $payRows = db()->query("
                        SELECT
                            TRIM(CONCAT(COALESCE(s.firstname,''), ' ', COALESCE(s.lastname,''))) AS actor,
                            COALESCE(r.name, 'Unknown') AS branch,
                            py.amount,
                            py.payment_date AS at
                        FROM payments py
                        LEFT JOIN subscribers s ON s.subscriber_id = py.subscriber_id
                        LEFT JOIN routers r ON r.router_id = COALESCE(py.router_id, s.router_id)
                        WHERE py.status = 'paid'
                        AND py.payment_date >= NOW() - INTERVAL 7 DAY
                        ORDER BY py.payment_date DESC
                        LIMIT 40
                    ")->fetchAll();

                    foreach ($payRows as $row) {
                        $actLog[] = [
                            'type'   => 'payment',
                            'actor'  => $row['actor'] ?: 'Subscriber',
                            'branch' => $row['branch'],
                            'amount' => (float)$row['amount'],
                            'at'     => date('M d, g:i A', strtotime($row['at'])),
                        ];
                    }
                } catch (Throwable $_) {}

                try {
                    $expRows = db()->query("
                        SELECT
                            COALESCE(et.name, e.employee, e.remark, 'Expense') AS actor,
                            COALESCE(r.name, 'Unknown') AS branch,
                            e.amount,
                            e.expense_date AS at
                        FROM expenses e
                        LEFT JOIN routers r ON r.router_id = e.router_id
                        LEFT JOIN expense_types et ON et.type_id = e.expense_type_id
                        WHERE e.expense_date >= NOW() - INTERVAL 7 DAY
                        ORDER BY e.expense_date DESC
                        LIMIT 20
                    ")->fetchAll();

                    foreach ($expRows as $row) {
                        $actLog[] = [
                            'type'   => 'expense',
                            'actor'  => $row['actor'],
                            'branch' => $row['branch'],
                            'amount' => (float)$row['amount'],
                            'at'     => date('M d, g:i A', strtotime($row['at'])),
                        ];
                    }
                } catch (Throwable $_) {}

                usort($actLog, fn($a, $b) => strcmp($b['at'], $a['at']));

                echo json_encode([
                    'success' => true,
                    'generated_at' => appNow(),
                    'display_time' => date('M d, Y h:i:s A'),
                    'company' => getSetting('company_name', APP_NAME),
                    'currency' => $currency_sym,
                    'periods' => $periods,
                    'routers' => array_map('intval', $routerStats),
                    'activity' => $actLog,
                ]);
            } catch (Throwable $e) {
                echo json_encode([
                    'success' => false,
                    'message' => APP_DEBUG ? $e->getMessage() : 'Unable to load report data.',
                ]);
            }
            exit;
        }

        $isAuth      = screenIsAuth();
        $companyName = getSetting('company_name', APP_NAME);
        $currency    = getSetting('currency_symbol', '₱');
        ?>
        <!doctype html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($companyName) ?></title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
        <script src="https://www.youtube.com/iframe_api"></script>
        <style>
        /* ── NIGHT (default dark) ────────────────────── */
        :root {
            --bg: #050708;
            --s1: #0b0f14;
            --s2: #111720;
            --line: rgba(255,255,255,.09);
            --line2: rgba(255,255,255,.05);
            --text: #eef0eb;
            --muted: #8e99a4;
            --green: #82CD59;   /* Light Moss Green  */
            --blue:  #41A5EE;   /* Bright Sky Blue   */
            --red:   #f04248;
            --gold:  #41A5EE;   /* alias → blue accent */
            --grad-text: #fff;
            --grad-text2: rgba(255,255,255,.5);
        }

        /* ── DAY (light) ─────────────────────────────── */
        html[data-theme="light"] {
            --bg: #eef2f8;
            --s1: #ffffff;
            --s2: #f4f7fb;
            --line: rgba(0,0,0,.09);
            --line2: rgba(0,0,0,.05);
            --text: #0a1628;
            --muted: #6b7585;
            --green: #52B743;   /* Vibrant Lime Green */
            --blue:  #1E73BE;   /* Deep Cerulean Blue */
            --red:   #c81e1e;
            --gold:  #1E73BE;   /* alias → blue accent */
            --grad-text: #0a1628;
            --grad-text2: rgba(10,22,40,.5);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html {
            transition: background .6s ease, color .6s ease;
        }

        html, body {
            height: 100%;
            background: var(--bg);
            color: var(--text);
            font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
            overflow: hidden;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        body {
            background:
                radial-gradient(ellipse 60% 30% at 10% 5%,  rgba(130,205,89,.08) 0%, transparent 100%),
                radial-gradient(ellipse 50% 25% at 90% 95%, rgba(65,165,238,.07) 0%, transparent 100%),
                var(--bg);
            transition: background .6s ease, color .6s ease;
        }

        html[data-theme="light"] body {
            background:
                radial-gradient(ellipse 60% 30% at 10% 5%,  rgba(82,183,67,.07)  0%, transparent 100%),
                radial-gradient(ellipse 50% 25% at 90% 95%, rgba(30,115,190,.06) 0%, transparent 100%),
                var(--bg);
        }

        /* ── SCREEN ──────────────────────────────────── */
        .screen {
            width: 100vw;
            height: 100vh;
            padding: 14px 18px 10px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            overflow: hidden;
        }

        /* ── TOPBAR ──────────────────────────────────── */
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--line);
            flex-shrink: 0;
        }


        .company-wrap {
            position: relative;
            display: inline-block;
        }

        h1.company {
            margin-top: 4px;
            font-size: clamp(32px, 5.2vw, 62px);
            font-weight: 900;
            line-height: .95;
            letter-spacing: -.025em;
            background: linear-gradient(120deg,
                var(--grad-text) 0%,
                #41A5EE          35%,
                #82CD59          55%,
                var(--grad-text2) 100%);
            background-size: 220% auto;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: company-shine 5s linear infinite;
        }

        @keyframes company-shine {
            0%   { background-position: 0% center; }
            100% { background-position: 220% center; }
        }

        .company-bar {
            margin-top: 4px;
            height: 3px;
            border-radius: 99px;
            background: linear-gradient(90deg,
                #52B743  0%,
                #41A5EE  50%,
                #1E73BE  100%);
            background-size: 200% auto;
            animation: company-bar-slide 4s linear infinite;
            opacity: .75;
        }

        @keyframes company-bar-slide {
            0%   { background-position: 0% center; }
            100% { background-position: 200% center; }
        }

        .clock {
            text-align: right;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 4px;
        }

        .clock-time {
            font-size: clamp(36px, 5vw, 58px);
            font-weight: 900;
            line-height: 1;
            font-variant-numeric: tabular-nums;
            letter-spacing: .02em;
            background: linear-gradient(135deg, var(--grad-text) 40%, var(--grad-text2) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .clock-ampm {
            font-size: clamp(14px, 1.6vw, 20px);
            font-weight: 700;
            letter-spacing: .08em;
            color: var(--muted);
            margin-left: 6px;
            -webkit-text-fill-color: var(--muted);
        }

        .clock-date {
            font-size: clamp(13px, 1.4vw, 16px);
            font-weight: 600;
            color: var(--muted);
            letter-spacing: .01em;
        }

        .clock-colon {
            display: inline-block;
            animation: colon-blink 1s step-end infinite;
            -webkit-text-fill-color: var(--green);
            color: var(--green);
            text-shadow: 0 0 14px rgba(130,205,89,.55);
        }

        @keyframes colon-blink {
            0%, 100% { opacity: 1; }
            50%       { opacity: 0; }
        }

        /* ── SUMMARY CARDS ───────────────────────────── */
        .summary {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            flex-shrink: 0;
        }

        .summary-card {
            position: relative;
            padding: 12px 14px 10px;
            background: var(--s1);
            border: 1.5px solid var(--line);
            border-radius: 14px;
            cursor: pointer;
            appearance: none;
            font: inherit;
            color: inherit;
            text-align: left;
            transition: border-color .3s, box-shadow .3s, background .3s, transform .2s;
            overflow: hidden;
        }

        /* top strip — same for all cards */
        .summary-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            border-radius: 14px 14px 0 0;
            background: var(--gold);
            opacity: .4;
            transition: opacity .3s;
        }

        .summary-card.active {
            border-color: var(--blue);
            background: rgba(65,165,238,.07);
            box-shadow: 0 0 0 1px rgba(65,165,238,.2), 0 8px 32px rgba(65,165,238,.12);
        }
        .summary-card.active::before { opacity: 1; }

        .s-period {
            font-size: 13px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .12em;
            line-height: 1;
            color: var(--muted);
        }

        .summary-card.active .s-period { color: var(--gold); }

        .s-net {
            margin-top: 6px;
            font-size: clamp(20px, 2.4vw, 32px);
            font-weight: 900;
            line-height: 1.05;
            letter-spacing: -.02em;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .s-divider {
            margin: 5px 0 4px;
            height: 1px;
            background: var(--line);
        }

        .s-coll, .s-exp {
            font-size: 13px;
            font-weight: 600;
            color: var(--muted);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            margin-top: 3px;
        }

        .s-coll strong { color: var(--green); font-weight: 700; }
        .s-exp strong { color: var(--red); font-weight: 700; }

        .s-pay {
            margin-top: 4px;
            font-size: 13px;
            color: var(--muted);
            font-weight: 600;
            opacity: .8;
        }


        /* ── PERIOD META ─────────────────────────────── */
        /* ── STATS BAR ───────────────────────────────── */
        .stats-bar {
            display: flex;
            align-items: stretch;
            background: var(--s1);
            border: 1px solid var(--line);
            border-radius: 10px;
            overflow: hidden;
            flex-shrink: 0;
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            font-size: 15px;
            font-weight: 700;
            border-right: 1px solid var(--line);
            white-space: nowrap;
        }

        .stat-item:last-child { border-right: none; }
        .stat-item.grow { flex: 1; min-width: 0; }

        .stat-item.c-green { color: var(--green); }
        .stat-item.c-red { color: var(--red); }
        .stat-item.c-gold { color: var(--gold); }
        .stat-item.c-muted { color: var(--muted); }

        .dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            flex-shrink: 0;
            background: var(--green);
            box-shadow: 0 0 8px var(--green), 0 0 18px rgba(130,205,89,.4);
            animation: pulse-g 2.8s ease-in-out infinite;
        }

        .dot.red {
            background: var(--red);
            box-shadow: 0 0 8px rgba(240,66,72,.6);
            animation: none;
        }

        @keyframes pulse-g {
            0%, 100% { box-shadow: 0 0 6px var(--green), 0 0 14px rgba(130,205,89,.3); }
            50% { box-shadow: 0 0 12px var(--green), 0 0 26px rgba(130,205,89,.6); }
        }

        .stat-label { color: var(--muted); font-weight: 600; font-size: 15px; margin-left: 2px; }

        /* ── TABLE SECTION ───────────────────────────── */
        /* ── CONTENT ROW ─────────────────────────────── */
        .content-row {
            flex: 1;
            min-height: 0;
            display: flex;
            gap: 8px;
        }

        /* ── SIDE CARDS ──────────────────────────────── */
        .side-cards {
            flex: 3;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .side-card {
            flex: 1;
            min-height: 0;
            background: var(--s1);
            border: 1.5px solid var(--line);
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 10px 8px;
            position: relative;
            overflow: hidden;
            transition: border-color .3s, background .3s;
        }

        .side-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            border-radius: 12px 12px 0 0;
        }

        .sc-online,
        .sc-offline,
        .sc-expired,
        .sc-pending { border-color: rgba(65,165,238,.25); }

        .sc-online::before,
        .sc-offline::before,
        .sc-expired::before,
        .sc-pending::before { background: var(--gold); }

        .sc-label {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .13em;
            color: var(--muted);
            margin-bottom: 5px;
            white-space: nowrap;
        }

        .sc-count {
            font-size: clamp(26px, 3.2vw, 46px);
            font-weight: 900;
            line-height: 1;
            font-variant-numeric: tabular-nums;
            letter-spacing: -.03em;
        }

        .sc-online  .sc-count,
        .sc-offline .sc-count,
        .sc-expired .sc-count,
        .sc-pending .sc-count { color: var(--gold); text-shadow: 0 0 20px rgba(65,165,238,.3); }

        .sc-sub {
            font-size: 11px;
            font-weight: 600;
            color: var(--muted);
            margin-top: 5px;
            opacity: .75;
            white-space: nowrap;
        }

        /* ── TABLE SECTION ───────────────────────────── */
        .table-section {
            flex: 17;
            min-height: 0;
            display: flex;
            flex-direction: column;
            background: var(--s1);
            border: 1px solid var(--line);
            border-radius: 12px;
            overflow: hidden;
        }

        .table-head-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 16px;
            border-bottom: 1px solid var(--line);
            flex-shrink: 0;
            gap: 12px;
        }

        .table-title {
            font-size: 16px;
            font-weight: 800;
            letter-spacing: .01em;
        }

        .table-sub {
            font-size: 12px;
            color: var(--muted);
            font-weight: 600;
            margin-top: 2px;
        }

        .table-wrap {
            flex: 1;
            min-height: 0;
            overflow: hidden;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        thead th {
            padding: 8px 14px;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--muted);
            background: var(--s2);
            border-bottom: 1px solid var(--line);
            white-space: nowrap;
        }

        tbody tr { transition: background .15s; }

        tbody tr:hover { background: rgba(255,255,255,.02); }

        tbody tr.row-top { background: rgba(240,191,66,.055); }

        tbody tr.row-neg { background: rgba(255,71,76,.04); }

        tbody td {
            padding: 10px 14px;
            font-size: 20px;
            font-weight: 700;
            border-bottom: 1px solid var(--line2);
            vertical-align: middle;
        }

        tbody tr:last-child td { border-bottom: none; }

        .num {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        /* Router cell */
        .router-cell {
            display: flex;
            align-items: center;
            gap: 11px;
            min-width: 0;
        }

        .rank-badge {
            width: 30px;
            height: 30px;
            display: grid;
            place-items: center;
            border-radius: 7px;
            font-size: 13px;
            font-weight: 800;
            flex-shrink: 0;
            background: var(--s2);
            border: 1px solid var(--line);
            color: var(--muted);
        }


        .router-info { min-width: 0; }

        .router-name {
            font-size: 20px;
            font-weight: 800;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .router-meta {
            margin-top: 3px;
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .spill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
            padding: 2px 8px;
            border-radius: 999px;
        }

        .spill.online  { background: rgba(32,206,118,.12); color: var(--green); }
        .spill.offline { background: rgba(255,71,76,.12);  color: var(--red); }
        .spill.maintenance { background: rgba(240,191,66,.12); color: var(--gold); }

        .pip {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .pip.g { background: var(--green); }
        .pip.r { background: var(--red); }
        .pip.y { background: var(--gold); }

        .router-host {
            font-size: 14px;
            font-weight: 600;
            color: var(--muted);
        }


        /* CPU cell */
        .cpu-label {
            font-size: 18px;
            font-weight: 800;
            font-variant-numeric: tabular-nums;
            text-align: right;
        }

        .cpu-bar {
            margin-top: 5px;
            height: 5px;
            border-radius: 999px;
            background: rgba(255,255,255,.08);
            overflow: hidden;
        }

        .cpu-fill {
            height: 100%;
            border-radius: inherit;
            transition: width .9s ease;
            background: var(--green);
        }
        .cpu-fill.warn { background: var(--gold); }
        .cpu-fill.crit {
            background: var(--red);
            animation: crit-blink .9s ease-in-out infinite;
        }

        @keyframes crit-blink {
            0%, 100% { opacity: 1; }
            50% { opacity: .55; }
        }

        /* Money colours */
        .c-green  { color: var(--green); }
        .c-red    { color: var(--red); }
        .c-gold   { color: var(--gold); }
        .c-muted  { color: var(--muted); }

        /* Arrows */
        .arr {
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
            gap: 5px;
        }
        .arr-icon {
            font-size: .75em;
            line-height: 1;
            flex-shrink: 0;
        }

        /* ── REFRESH BAR ─────────────────────────────── */

        .refresh-bar {
            width: 160px;
            height: 4px;
            border-radius: 99px;
            background: rgba(255,255,255,.08);
            overflow: hidden;
        }

        .refresh-fill {
            width: 0%;
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, #52B743, #41A5EE);
            transition: width .6s linear;
        }

        /* ── ERROR ───────────────────────────────────── */
        .error {
            display: none;
            padding: 12px 16px;
            border: 1px solid rgba(255,71,76,.35);
            color: #ffd7d8;
            background: rgba(255,71,76,.09);
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            flex-shrink: 0;
        }

        /* ── EMPTY / LOADING ─────────────────────────── */
        .empty-cell {
            text-align: center;
            color: var(--muted) !important;
            padding: 36px 16px !important;
            font-size: 16px !important;
            font-weight: 600 !important;
        }

        /* ── LOADING OVERLAY ─────────────────────────── */
        .load-overlay {
            position: fixed;
            inset: 0;
            background: var(--bg);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            opacity: 1;
            transition: opacity .5s ease;
        }

        .load-overlay.hidden {
            opacity: 0;
            pointer-events: none;
        }

        .load-box {
            text-align: center;
            width: min(90vw, 480px);
        }

        .load-company {
            font-size: clamp(22px, 3vw, 36px);
            font-weight: 900;
            letter-spacing: -.01em;
            background: linear-gradient(140deg, var(--grad-text) 0%, var(--grad-text2) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 10px;
        }

        .load-bar-wrap { background: var(--line); }

        .load-msg {
            font-size: 17px;
            font-weight: 700;
            color: var(--muted);
            margin-bottom: 28px;
        }

        .load-bar-wrap {
            width: 100%;
            height: 5px;
            border-radius: 999px;
            background: rgba(255,255,255,.08);
            overflow: hidden;
            margin-bottom: 18px;
        }

        .load-bar {
            height: 100%;
            width: 38%;
            border-radius: inherit;
            background: linear-gradient(90deg, #52B743, #41A5EE);
            animation: load-sweep 1.5s ease-in-out infinite;
        }

        @keyframes load-sweep {
            0%   { transform: translateX(-160%); }
            100% { transform: translateX(420%); }
        }

        .load-sub {
            font-size: 13px;
            font-weight: 600;
            color: var(--muted);
            opacity: .7;
        }

        /* ── TICKER ──────────────────────────────────── */
        .ticker-wrap {
            flex-shrink: 0;
            background: var(--s1);
            border: 1px solid var(--line);
            border-radius: 10px;
            overflow: hidden;
            display: flex;
            align-items: center;
            height: 42px;
        }

        .ticker-label {
            flex-shrink: 0;
            padding: 0 14px;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--gold);
            border-right: 1px solid var(--line);
            height: 100%;
            display: flex;
            align-items: center;
            white-space: nowrap;
            background: var(--s2);
        }

        .ticker-viewport {
            flex: 1;
            overflow: hidden;
            position: relative;
            height: 100%;
        }

        .ticker-track {
            display: inline-flex;
            align-items: center;
            gap: 0;
            height: 100%;
            white-space: nowrap;
            will-change: transform;
            animation: ticker-scroll 60s linear infinite;
        }

        .ticker-track:hover { animation-play-state: paused; }

        @keyframes ticker-scroll {
            0%   { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }

        .ticker-item {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 0 28px;
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
            border-right: 1px solid var(--line);
        }

        .ticker-item .t-type {
            font-size: 13px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .ticker-item.t-pay  .t-type { color: var(--green); }
        .ticker-item.t-exp  .t-type { color: var(--red); }

        .ticker-item .t-amt {
            font-weight: 800;
            font-variant-numeric: tabular-nums;
        }

        .ticker-item.t-pay .t-amt { color: var(--green); }
        .ticker-item.t-exp .t-amt { color: var(--red); }

        .ticker-item .t-sep { color: var(--muted); font-size: 12px; }
        .ticker-item .t-at  { color: var(--muted); font-size: 13px; }

        /* ── LOGIN OVERLAY ───────────────────────────── */
        .login-overlay {
            position: fixed;
            inset: 0;
            background: rgba(5,7,8,.97);
            backdrop-filter: blur(28px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 99999;
            transition: opacity .5s ease;
        }

        .login-overlay.fade-out {
            opacity: 0;
            pointer-events: none;
        }

        .login-box {
            width: min(92vw, 500px);
            background: #0b0f14;
            border: 1px solid rgba(65,165,238,.2);
            border-radius: 24px;
            overflow: hidden;
            box-shadow:
                0 0 0 1px rgba(65,165,238,.07),
                0 48px 120px rgba(0,0,0,.75),
                0 0 100px rgba(65,165,238,.06);
            animation: login-appear .4s cubic-bezier(.16,1,.3,1);
        }

        @keyframes login-appear {
            from { opacity: 0; transform: scale(.92) translateY(28px); }
            to   { opacity: 1; transform: none; }
        }

        .login-brand {
            padding: 26px 32px 20px;
            text-align: center;
            border-bottom: 1px solid rgba(65,165,238,.1);
            background: linear-gradient(160deg, #0b1422 0%, #0d1a2e 100%);
        }

        .login-brand-name {
            font-size: clamp(22px, 3.8vw, 32px);
            font-weight: 900;
            letter-spacing: -.02em;
            background: linear-gradient(120deg, #fff 0%, #41A5EE 45%, #82CD59 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .login-tagline {
            margin-top: 7px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: #8e99a4;
        }

        .login-step { padding: 24px 28px 28px; }

        .login-step-title {
            font-size: 20px;
            font-weight: 800;
            color: #eef0eb;
            margin-bottom: 6px;
            text-align: center;
        }

        .login-step-sub {
            font-size: 14px;
            font-weight: 500;
            color: #8e99a4;
            margin-bottom: 22px;
            line-height: 1.55;
            text-align: center;
        }

        .login-step-sub strong { color: #41A5EE; font-weight: 700; }

        .login-btn {
            display: block;
            width: 100%;
            padding: 18px 20px;
            background: linear-gradient(135deg, #1E73BE 0%, #41A5EE 100%);
            border: none;
            border-radius: 14px;
            color: #fff;
            font-family: inherit;
            font-size: 18px;
            font-weight: 800;
            letter-spacing: .03em;
            cursor: pointer;
            transition: opacity .2s, transform .12s;
            margin-bottom: 10px;
        }

        .login-btn:hover  { opacity: .88; }
        .login-btn:active { transform: scale(.97); }
        .login-btn:disabled { opacity: .4; cursor: not-allowed; }

        .login-btn-ghost {
            display: block;
            width: 100%;
            padding: 12px 20px;
            background: transparent;
            border: 1.5px solid rgba(255,255,255,.1);
            border-radius: 12px;
            color: #8e99a4;
            font-family: inherit;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: border-color .2s, color .2s;
            margin-bottom: 12px;
            text-align: center;
        }

        .login-btn-ghost:hover { border-color: rgba(255,255,255,.22); color: #eef0eb; }

        .login-msg {
            font-size: 14px;
            font-weight: 600;
            border-radius: 10px;
            padding: 11px 14px;
            display: none;
            margin-bottom: 10px;
            text-align: center;
        }

        .login-msg.err {
            background: rgba(240,66,72,.12);
            color: #f47376;
            border: 1px solid rgba(240,66,72,.25);
            display: block;
        }

        .login-msg.ok {
            background: rgba(130,205,89,.10);
            color: #82CD59;
            border: 1px solid rgba(130,205,89,.2);
            display: block;
        }

        /* OTP digit display (TV keypad fills these) */
        .otp-display {
            display: flex;
            gap: 8px;
            justify-content: center;
            margin: 0 0 18px;
        }

        .otp-digit {
            width: 58px;
            height: 72px;
            background: #111720;
            border: 2px solid rgba(255,255,255,.09);
            border-radius: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            font-weight: 900;
            font-family: 'Inter', monospace;
            color: rgba(255,255,255,.15);
            transition: border-color .15s, color .15s;
            user-select: none;
        }

        .otp-digit.filled {
            color: #41A5EE;
            border-color: rgba(65,165,238,.45);
        }

        .otp-digit.active {
            border-color: #41A5EE;
            box-shadow: 0 0 0 3px rgba(65,165,238,.15);
        }

        /* On-screen keypad */
        .keypad {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }

        .key {
            padding: 20px 0;
            font-size: 28px;
            font-weight: 700;
            font-family: inherit;
            background: #111720;
            border: 2px solid rgba(255,255,255,.08);
            border-radius: 14px;
            color: #eef0eb;
            cursor: pointer;
            transition: background .1s, border-color .1s, transform .07s;
            line-height: 1;
            user-select: none;
            -webkit-tap-highlight-color: transparent;
        }

        .key:hover  { background: #182030; border-color: rgba(65,165,238,.28); }
        .key:active { transform: scale(.92); background: #0d1420; }
        .key:focus  { outline: none; border-color: #41A5EE; background: #182030; box-shadow: 0 0 0 3px rgba(65,165,238,.3); }

        .key-del {
            font-size: 22px;
            color: #f04248;
            border-color: rgba(240,66,72,.18);
        }

        .key-del:hover { border-color: rgba(240,66,72,.4); background: rgba(240,66,72,.07); }

        .key-ok {
            background: linear-gradient(135deg, #1E73BE, #41A5EE);
            border-color: transparent;
            color: #fff;
            font-size: 22px;
        }

        .key-ok:hover   { opacity: .88; }
        .key-ok:disabled { opacity: .3; cursor: not-allowed; transform: none; }

        .otp-timer {
            text-align: center;
            font-size: 13px;
            font-weight: 600;
            color: #8e99a4;
            margin-top: 14px;
        }

        /* ── RESPONSIVE ──────────────────────────────── */
        @media (max-width: 900px) {
            body { overflow: auto; }
            .screen { height: auto; min-height: 100vh; padding: 14px; }
            .summary { grid-template-columns: 1fr 1fr; }
            .topbar { flex-direction: column; align-items: stretch; }
            .clock { text-align: left; }
            tbody td { font-size: 15px; padding: 11px 12px; }
            thead th { font-size: 10px; padding: 9px 12px; }
            .router-name { font-size: 15px; }
            .stats-bar { flex-wrap: wrap; }
            .stat-item { padding: 10px 14px; font-size: 13px; }
            .s-net { font-size: 20px; }
            .content-row { flex-direction: column; }
            .side-cards { flex-direction: row; flex: none; }
            .side-card { min-height: 80px; }
        }
        </style>
        </head>
        <body>

        <!-- Hidden YouTube music player -->
        <div id="ytWrap" style="position:fixed;width:1px;height:1px;bottom:0;right:0;overflow:hidden;pointer-events:none;opacity:0;" aria-hidden="true">
            <div id="ytPlayer"></div>
        </div>

        <?php if (!$isAuth): ?>
        <div id="loginOverlay" class="login-overlay">
            <div class="login-box">
                <div class="login-brand">
                    <div class="login-brand-name"><?= e($companyName) ?></div>
                    <div class="login-tagline">Live Report &mdash; Secure Access</div>
                </div>

                <!-- Step 1: Send code -->
                <div id="loginStep1" class="login-step">
                    <div class="login-step-title">Access Required</div>
                    <div class="login-step-sub">Press the button to receive a one-time code<br>via email to the company email address.</div>
                    <button type="button" class="login-btn" id="btnSendOtp">
                        <span id="sendOtpLabel">Send Access Code</span>
                    </button>
                    <div class="login-msg" id="loginMsg1"></div>
                </div>

                <!-- Step 2: Keypad entry -->
                <div id="loginStep2" class="login-step" style="display:none;">
                    <div class="login-step-title">Enter the 6-digit code</div>
                    <div class="login-step-sub">Sent via email to <strong id="maskedPhone">company email</strong></div>

                    <div class="otp-display" id="otpDisplay">
                        <div class="otp-digit" id="od0">&#8211;</div>
                        <div class="otp-digit" id="od1">&#8211;</div>
                        <div class="otp-digit" id="od2">&#8211;</div>
                        <div class="otp-digit" id="od3">&#8211;</div>
                        <div class="otp-digit" id="od4">&#8211;</div>
                        <div class="otp-digit" id="od5">&#8211;</div>
                    </div>

                    <div class="login-msg" id="loginMsg2"></div>

                    <div class="keypad">
                        <button type="button" class="key" data-k="1">1</button>
                        <button type="button" class="key" data-k="2">2</button>
                        <button type="button" class="key" data-k="3">3</button>
                        <button type="button" class="key" data-k="4">4</button>
                        <button type="button" class="key" data-k="5">5</button>
                        <button type="button" class="key" data-k="6">6</button>
                        <button type="button" class="key" data-k="7">7</button>
                        <button type="button" class="key" data-k="8">8</button>
                        <button type="button" class="key" data-k="9">9</button>
                        <button type="button" class="key key-del" data-k="del">&#9003;</button>
                        <button type="button" class="key" data-k="0">0</button>
                        <button type="button" class="key key-ok" id="btnVerifyOtp" disabled>&#10003;</button>
                    </div>

                    <button type="button" class="login-btn-ghost" id="btnResendOtp" style="margin-top:14px;">&#8592; Resend code</button>
                    <div class="otp-timer" id="otpTimer"></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div id="loadOverlay" class="load-overlay">
            <div class="load-box">
                <div class="load-company"><?= e($companyName) ?></div>
                <div class="load-msg" id="loadMsg">Loading report&hellip;</div>
                <div class="load-bar-wrap"><div class="load-bar"></div></div>
                <div class="load-sub" id="loadSub">Please wait</div>
            </div>
        </div>

        <main class="screen" data-currency="<?= e($currency) ?>">

            <!-- TOPBAR -->
            <section class="topbar">
                <div class="company-wrap">
                    <h1 class="company"><?= e($companyName) ?></h1>
                    <div class="company-bar"></div>
                </div>
                <div class="clock">
                    <div class="clock-time"><span id="clockH">--</span><span class="clock-colon">:</span><span id="clockM">--</span><span>:</span><span id="clockS">--</span><span class="clock-ampm" id="clockAmpm">--</span></div>
                    <div class="clock-date" id="clockDate">Loading&hellip;</div>
                </div>
            </section>

            <!-- SUMMARY CARDS (period selector + totals) -->
            <section class="summary" id="periodSummary" aria-label="Period summary">
                <!-- populated by JS -->
            </section>

            <!-- STATS BAR -->
            <div class="stats-bar" id="statsBar">
                <div class="stat-item c-green">
                    <span class="dot"></span>
                    <span id="statOnline">0</span>
                    <span class="stat-label">online</span>
                </div>
                <div class="stat-item c-red">
                    <span class="dot red"></span>
                    <span id="statOffline">0</span>
                    <span class="stat-label">offline</span>
                </div>
                <div class="stat-item grow c-muted" id="statTop">
                    <span id="statTopName">Loading&hellip;</span>
                </div>
                <div class="stat-item c-red" id="statCpuWarn" style="display:none;">
                    <span>&#9888;</span>
                    <span id="statCpuWarnText">High CPU</span>
                </div>
                <div class="stat-item" id="themeBadge">
                    <span id="themeIcon">&#9790;</span>
                    <span id="themeLabel" class="stat-label">Night</span>
                </div>
            </div>

            <!-- ERROR -->
            <div class="error" id="errorBox"></div>

            <!-- CONTENT ROW: Table (85%) + Subscriber Cards (15%) -->
            <div class="content-row">

                <!-- TABLE -->
                <section class="table-section">
                    <div class="table-head-bar">
                        <div>
                            <div class="table-title" id="reportTitle">Branch Performance</div>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width:38%; text-align:left;">Router</th>
                                    <th class="num" style="width:11%;">CPU</th>
                                    <th class="num" style="width:17%;">Collection</th>
                                    <th class="num" style="width:17%;">Expenses</th>
                                    <th class="num" style="width:17%;">Net</th>
                                </tr>
                            </thead>
                            <tbody id="branchRows">
                                <tr><td colspan="5" class="empty-cell">Loading live report&hellip;</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- SUBSCRIBER STATUS CARDS -->
                <div class="side-cards">
                    <div class="side-card sc-online">
                        <div class="sc-label">Online</div>
                        <div class="sc-count" id="scOnline">0</div>
                        <div class="sc-sub">subscribers</div>
                    </div>
                    <div class="side-card sc-offline">
                        <div class="sc-label">Offline</div>
                        <div class="sc-count" id="scOffline">0</div>
                        <div class="sc-sub">subscribers</div>
                    </div>
                    <div class="side-card sc-expired">
                        <div class="sc-label">Expired</div>
                        <div class="sc-count" id="scExpired">0</div>
                        <div class="sc-sub">subscribers</div>
                    </div>
                    <div class="side-card sc-pending">
                        <div class="sc-label">Pending</div>
                        <div class="sc-count" id="scPending">0</div>
                        <div class="sc-sub">subscribers</div>
                    </div>
                </div>

            </div>

            <!-- TICKER -->
            <div class="ticker-wrap">
                <div class="ticker-label">&#9679; Activity</div>
                <div class="ticker-viewport">
                    <div class="ticker-track" id="tickerTrack">
                        <span class="ticker-item"><span class="t-type">Loading</span><span class="t-sep">&mdash;</span><span class="t-at">Please wait&hellip;</span></span>
                    </div>
                </div>
                <div style="flex-shrink:0; padding: 0 14px;">
                    <div class="refresh-bar"><div class="refresh-fill" id="refreshFill"></div></div>
                </div>
            </div>

        </main>
        <script>
        const PERIODS  = ['daily', 'weekly', 'monthly', 'yearly'];
        const IS_AUTH  = <?= $isAuth ? 'true' : 'false' ?>;
        const REFRESH_MS = 15000;
        const CYCLE_MS   = 10000;

        const state = {
            period: 'daily',
            data: null,
            lastRefresh: Date.now(),
            currency: document.querySelector('.screen').dataset.currency || '₱'
        };

        /* ── helpers ──────────────────────────────────── */
        function money(v) {
            return state.currency + Number(v || 0).toLocaleString('en-PH', {
                minimumFractionDigits: 2, maximumFractionDigits: 2
            });
        }

        function shortMoney(v) {
            const n = Number(v || 0);
            const abs = Math.abs(n);
            const sign = n < 0 ? '-' : '';
            if (abs >= 1e6) return sign + state.currency + (abs / 1e6).toFixed(1) + 'M';
            if (abs >= 1e3) return sign + state.currency + Math.round(abs / 1e3) + 'K';
            return sign + state.currency + abs.toFixed(0);
        }

        function setText(id, v) {
            const el = document.getElementById(id);
            if (el) el.textContent = v;
        }

        function escHtml(v) {
            return String(v).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
        }

        /* ── clock + progress ─────────────────────────── */
        function updateClock() {
            const now = new Date();
            const h = now.getHours();
            const h12 = h % 12 || 12;
            const mm = String(now.getMinutes()).padStart(2, '0');
            const ss = String(now.getSeconds()).padStart(2, '0');
            const ampm = h < 12 ? 'AM' : 'PM';
            setText('clockH', String(h12));
            setText('clockM', mm);
            setText('clockS', ss);
            setText('clockAmpm', ampm);
            setText('clockDate', now.toLocaleDateString('en-PH', {
                weekday: 'long', month: 'long', day: 'numeric', year: 'numeric'
            }));
            const pct = Math.min(100, ((Date.now() - state.lastRefresh) / REFRESH_MS) * 100);
            const fill = document.getElementById('refreshFill');
            if (fill) fill.style.width = pct + '%';
        }

        /* ── render summary cards ─────────────────────── */
        function renderSummary() {
            const wrap = document.getElementById('periodSummary');
            if (!wrap || !state.data) return;
            wrap.innerHTML = '';

            PERIODS.forEach(p => {
                const d = state.data.periods[p];
                const isActive = p === state.period;
                const netCls = d.totals.net >= 0 ? 'c-gold' : 'c-red';
                const card = document.createElement('button');
                card.type = 'button';
                card.className = 'summary-card' + (isActive ? ' active' : '');
                card.dataset.period = p;
                card.innerHTML =
                    `<div class="s-period">${p}</div>` +
                    `<div class="s-net ${netCls}">${shortMoney(d.totals.net)}</div>` +
                    `<div class="s-divider"></div>` +
                    `<div class="s-coll">C &nbsp;<strong>${shortMoney(d.totals.collection)}</strong></div>` +
                    `<div class="s-exp">E &nbsp;<strong>${shortMoney(d.totals.expenses)}</strong></div>` +
                    `<div class="s-pay">${Number(d.totals.payment_count).toLocaleString()} payments</div>`;
                card.addEventListener('click', () => setPeriod(p));
                wrap.appendChild(card);
            });
        }

        /* ── render current period detail ─────────────── */
        function renderCurrentPeriod() {
            if (!state.data) return;
            const report = state.data.periods[state.period];
            if (!report) return;

            /* router stats */
            const rs = state.data.routers || {};
            setText('statOnline', Number(rs.online || 0));
            setText('statOffline', Number(rs.offline || 0));

            /* top branch */
            const branches = report.branches;
            const topB = branches.find(b => Number(b.net) > 0);
            if (topB) {
                setText('statTopName', 'Top: ' + (topB.router || 'Unnamed') + ' · ' + money(topB.net));
            } else {
                setText('statTopName', branches.length ? (branches[0].router || 'Unnamed') : 'No data');
            }

            /* CPU warnings */
            const hotBranches = branches.filter(b => {
                const cpu = b.cpu_load !== null && b.cpu_load !== undefined ? parseInt(b.cpu_load, 10) : -1;
                return (b.status || '').toLowerCase() === 'online' && cpu >= 80;
            });
            const warnEl = document.getElementById('statCpuWarn');
            if (warnEl) {
                warnEl.style.display = hotBranches.length ? 'flex' : 'none';
                if (hotBranches.length) setText('statCpuWarnText', hotBranches.length + ' high CPU');
            }

            /* table title */
            const cap = s => s.charAt(0).toUpperCase() + s.slice(1);
            setText('reportTitle', cap(state.period) + ' Branch Performance');

            /* branch rows */
            const body = document.getElementById('branchRows');
            body.innerHTML = '';

            if (!branches.length) {
                body.innerHTML = '<tr><td colspan="5" class="empty-cell">No branch data for this period.</td></tr>';
                return;
            }

            branches.forEach((row, i) => {
                const status = (row.status || '').toLowerCase();
                const cpu = (row.cpu_load === null || row.cpu_load === undefined || row.cpu_load === '')
                    ? null
                    : Math.max(0, Math.min(100, parseInt(row.cpu_load, 10) || 0));
                const cpuCls = cpu === null ? '' : cpu >= 80 ? 'crit' : cpu >= 55 ? 'warn' : '';
                const cpuLabel = status === 'online' && cpu !== null ? cpu + '%' : '&mdash;';
                const net = Number(row.net || 0);
                const isTop = i === 0 && net > 0;
                const isNeg = net < 0;


                const spillCls = status === 'online' ? 'online' : status === 'maintenance' ? 'maintenance' : 'offline';
                const pipCls = status === 'online' ? 'g' : status === 'maintenance' ? 'y' : 'r';

                const cpuLabelCls = cpuCls === 'crit' ? 'c-red' : cpuCls === 'warn' ? 'c-gold' : '';

                const coll = Number(row.collection || 0);
                const exp  = Number(row.expenses  || 0);

                const collHtml = `<span class="arr c-green"><span class="arr-icon">${coll > 0 ? '▲' : '&ndash;'}</span>${money(coll)}</span>`;
                const expHtml  = exp > 0
                    ? `<span class="arr c-red"><span class="arr-icon">▼</span>${money(exp)}</span>`
                    : `<span class="arr c-muted"><span class="arr-icon">&ndash;</span>${money(exp)}</span>`;
                const netIcon  = net > 0 ? '▲' : net < 0 ? '▼' : '&ndash;';
                const netHtml  = `<span class="arr ${net >= 0 ? 'c-gold' : 'c-red'}"><span class="arr-icon">${netIcon}</span>${money(net)}</span>`;

                const tr = document.createElement('tr');
                if (isTop) tr.classList.add('row-top');
                if (isNeg) tr.classList.add('row-neg');

                tr.innerHTML =
                    `<td>
                        <div class="router-cell">
                            <div class="rank-badge">${i + 1}</div>
                            <div class="router-info">
                                <div class="router-name">
                                    ${escHtml(row.router || 'Unnamed')}
                                </div>
                                <div class="router-meta">
                                    <span class="spill ${spillCls}"><span class="pip ${pipCls}"></span>${escHtml(status || 'unknown')}</span>
                                    ${row.host ? '<span class="router-host">' + escHtml(row.host) + '</span>' : ''}
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="num">
                        <div class="cpu-label ${cpuLabelCls}">${cpuLabel}</div>
                        <div class="cpu-bar"><div class="cpu-fill ${cpuCls}" style="width:${cpu === null ? 0 : cpu}%"></div></div>
                    </td>
                    <td class="num">${collHtml}</td>
                    <td class="num">${expHtml}</td>
                    <td class="num">${netHtml}</td>`;

                body.appendChild(tr);
            });
        }

        /* ── subscriber cards ─────────────────────────── */
        function renderSubscriberCards(s) {
            setText('scOnline',  Number(s.online  || 0).toLocaleString());
            setText('scOffline', Number(s.offline || 0).toLocaleString());
            setText('scExpired', Number(s.expired || 0).toLocaleString());
            setText('scPending', Number(s.pending || 0).toLocaleString());
        }

        let _scTimer = null;
        async function loadSubscriberCounts() {
            try {
                const res  = await fetch('screen.php?action=subscriber_counts', { cache: 'no-store' });
                const data = await res.json();
                if (data.success) renderSubscriberCards(data);
            } catch (_) {}
            // re-schedule: refresh every 60 s (MikroTik is slow, no need to hammer it)
            _scTimer = setTimeout(loadSubscriberCounts, 60000);
        }

        /* ── ticker ───────────────────────────────────── */
        function renderTicker(activity) {
            const track = document.getElementById('tickerTrack');
            if (!track || !activity || !activity.length) return;

            const items = activity.map(a => {
                const cls   = a.type === 'payment' ? 't-pay' : 't-exp';
                const label = a.type === 'payment' ? '▲ Payment' : '▼ Expense';
                const amt   = state.currency + Number(a.amount).toLocaleString('en-PH', { minimumFractionDigits: 2 });
                return `<span class="ticker-item ${cls}">` +
                    `<span class="t-type">${label}</span>` +
                    `<span class="t-sep">·</span>` +
                    `<span>${escHtml(a.actor)}</span>` +
                    `<span class="t-sep">·</span>` +
                    `<span class="t-amt">${escHtml(amt)}</span>` +
                    `<span class="t-sep">·</span>` +
                    `<span>${escHtml(a.branch)}</span>` +
                    `<span class="t-sep">·</span>` +
                    `<span class="t-at">${escHtml(a.at)}</span>` +
                    `</span>`;
            }).join('');

            /* duplicate for seamless loop */
            track.innerHTML = items + items;

            /* set speed: ~90px/s */
            const totalW = track.scrollWidth / 2;
            track.style.animationDuration = Math.max(20, totalW / 90) + 's';
        }

        /* ── period switch ────────────────────────────── */
        function setPeriod(p) {
            if (!PERIODS.includes(p)) return;
            state.period = p;
            renderSummary();
            renderCurrentPeriod();
        }

        /* ── overlay helpers ──────────────────────────── */
        const overlay  = document.getElementById('loadOverlay');
        const overlayMsg = document.getElementById('loadMsg');
        const overlaySub = document.getElementById('loadSub');

        function showOverlay(msg, sub) {
            if (overlayMsg) overlayMsg.textContent = msg;
            if (overlaySub) overlaySub.textContent = sub;
            if (overlay) overlay.classList.remove('hidden');
        }

        function hideOverlay() {
            if (overlay) overlay.classList.add('hidden');
        }

        /* ── data fetch ───────────────────────────────── */
        const FETCH_TIMEOUT_MS  = 8000;
        const FAIL_RELOAD_AFTER = 5;
        const PAGE_WATCHDOG_MS  = 12000;

        let failCount      = 0;
        let initialLoaded  = false;

        const pageWatchdog = IS_AUTH ? setTimeout(() => {
            if (!initialLoaded) location.reload();
        }, PAGE_WATCHDOG_MS) : null;

        async function loadData() {
            const ctrl = new AbortController();
            const fetchTimer = setTimeout(() => ctrl.abort(), FETCH_TIMEOUT_MS);
            try {
                const url = new URL(window.location.href);
                url.search = '';
                url.searchParams.set('data', '1');
                url.searchParams.set('_', Date.now());
                const res = await fetch(url.toString(), { cache: 'no-store', signal: ctrl.signal });
                clearTimeout(fetchTimer);
                const data = await res.json();
                if (!data.success && data.auth === false) {
                    setTimeout(() => location.reload(), 1500);
                    return;
                }
                if (!data.success) throw new Error(data.message || 'Unable to load report.');

                failCount = 0;
                state.data = data;
                state.currency = data.currency || state.currency;
                state.lastRefresh = Date.now();

                if (!initialLoaded) {
                    initialLoaded = true;
                    clearTimeout(pageWatchdog);
                    hideOverlay();
                } else {
                    hideOverlay();
                }

                document.getElementById('errorBox').style.display = 'none';
                renderSummary();
                renderCurrentPeriod();
                renderTicker(data.activity || []);
            } catch (err) {
                clearTimeout(fetchTimer);
                failCount++;

                if (!initialLoaded || failCount >= 3) {
                    const sub = 'Attempt ' + failCount + (failCount >= FAIL_RELOAD_AFTER ? ' — reloading page…' : '');
                    showOverlay(failCount >= 3 ? 'Reconnecting…' : 'Connecting…', sub);
                }

                const box = document.getElementById('errorBox');
                if (box) { box.textContent = err.name === 'AbortError' ? 'Request timed out.' : (err.message || 'Refresh failed.'); box.style.display = 'block'; }

                if (failCount >= FAIL_RELOAD_AFTER) {
                    setTimeout(() => location.reload(), 2000);
                }
            }
        }

        /* ── day / night theme ────────────────────────── */
        function applyTheme() {
            const h = new Date().getHours();
            const isDay = h >= 6 && h < 18;
            document.documentElement.setAttribute('data-theme', isDay ? 'light' : 'dark');
            setText('themeIcon', isDay ? '☀' : '☾');   /* ☀ / ☾ */
            setText('themeLabel', isDay ? 'Day' : 'Night');
            const badge = document.getElementById('themeBadge');
            if (badge) badge.style.color = isDay ? 'var(--gold)' : 'var(--cyan)';
        }

        applyTheme();
        setInterval(applyTheme, 60 * 1000);
        setInterval(updateClock, 1000);
        updateClock();

        function startDashboard() {
            setInterval(() => {
                setPeriod(PERIODS[(PERIODS.indexOf(state.period) + 1) % PERIODS.length]);
            }, CYCLE_MS);
            setInterval(loadData, REFRESH_MS);
            loadData();
            loadSubscriberCounts(); // async, non-blocking — refreshes every 60 s
        }

        if (IS_AUTH) startDashboard();

        /* ── OTP LOGIN (TV keypad) ────────────────────── */
        (function () {
            const loginOverlay = document.getElementById('loginOverlay');
            if (!loginOverlay) return;

            const step1      = document.getElementById('loginStep1');
            const step2      = document.getElementById('loginStep2');
            const btnSend    = document.getElementById('btnSendOtp');
            const btnVerify  = document.getElementById('btnVerifyOtp');
            const btnResend  = document.getElementById('btnResendOtp');
            const msg1       = document.getElementById('loginMsg1');
            const msg2       = document.getElementById('loginMsg2');
            const maskedEl   = document.getElementById('maskedPhone');
            const sendLabel  = document.getElementById('sendOtpLabel');
            const timerEl    = document.getElementById('otpTimer');
            const digits     = Array.from({length: 6}, (_, i) => document.getElementById('od' + i));

            let entered = [];
            let expiryTimer = null;
            let verifying = false;

            function showMsg(el, text, type) { el.textContent = text; el.className = 'login-msg ' + type; }
            function clearMsg(el) { el.className = 'login-msg'; }

            function refreshDisplay() {
                digits.forEach((d, i) => {
                    if (i < entered.length) {
                        d.textContent = entered[i];
                        d.classList.add('filled');
                        d.classList.remove('active');
                    } else {
                        d.textContent = '–';
                        d.classList.remove('filled');
                        d.classList.toggle('active', i === entered.length);
                    }
                });
                if (btnVerify) btnVerify.disabled = entered.length < 6;
                if (entered.length === 6 && !verifying) verifyOtp();
            }

            // ── Keypad ────────────────────────────────────
            document.querySelectorAll('.key').forEach(btn => {
                btn.addEventListener('click', () => {
                    const k = btn.dataset.k;
                    if (k === 'del') {
                        if (entered.length > 0) entered.pop();
                        clearMsg(msg2);
                    } else if (k === 'ok') {
                        if (entered.length === 6) verifyOtp();
                    } else if (entered.length < 6) {
                        entered.push(k);
                    }
                    refreshDisplay();
                });
            });

            // ── Keypad arrow navigation (TV remote) ──────
            const keyBtns = Array.from(document.querySelectorAll('.keypad .key'));
            let focusedKeyIdx = 0;
            keyBtns.forEach((btn, i) => btn.addEventListener('focus', () => { focusedKeyIdx = i; }));

            // Also accept keyboard + arrow keys for TV remote
            document.addEventListener('keydown', e => {
                if (step2.style.display === 'none') return;

                if (['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(e.key)) {
                    e.preventDefault();
                    const cols = 3;
                    const total = keyBtns.length;
                    let next = focusedKeyIdx;
                    if (e.key === 'ArrowRight') next = Math.min(total - 1, focusedKeyIdx + 1);
                    if (e.key === 'ArrowLeft')  next = Math.max(0, focusedKeyIdx - 1);
                    if (e.key === 'ArrowDown')  next = Math.min(total - 1, focusedKeyIdx + cols);
                    if (e.key === 'ArrowUp')    next = Math.max(0, focusedKeyIdx - cols);
                    keyBtns[next].focus();
                    focusedKeyIdx = next;
                    return;
                }

                if (/^\d$/.test(e.key) && entered.length < 6) {
                    entered.push(e.key); refreshDisplay();
                } else if (e.key === 'Backspace') {
                    if (entered.length > 0) entered.pop(); clearMsg(msg2); refreshDisplay();
                } else if (e.key === 'Enter' && !document.activeElement?.classList.contains('key') && entered.length === 6) {
                    verifyOtp();
                }
            });

            // ── Send OTP ─────────────────────────────────
            btnSend.addEventListener('click', sendOtp);

            async function sendOtp() {
                btnSend.disabled = true;
                sendLabel.textContent = 'Sending…';
                clearMsg(msg1);

                try {
                    const res  = await fetch('?action=send_otp', { method: 'POST' });
                    const data = await res.json();
                    if (!data.success) {
                        showMsg(msg1, data.message || 'Failed to send code.', 'err');
                        btnSend.disabled = false;
                        sendLabel.textContent = 'Send Access Code';
                        return;
                    }
                    step1.style.display = 'none';
                    step2.style.display = 'block';
                    if (maskedEl) maskedEl.textContent = data.masked || 'company email';
                    entered = [];
                    refreshDisplay();
                    startExpiryTimer(300);
                    setTimeout(() => { focusedKeyIdx = 4; keyBtns[4]?.focus(); }, 60);
                } catch {
                    showMsg(msg1, 'Network error. Please try again.', 'err');
                    btnSend.disabled = false;
                    sendLabel.textContent = 'Send Access Code';
                }
            }

            // ── Verify OTP ────────────────────────────────
            btnVerify.addEventListener('click', verifyOtp);

            async function verifyOtp() {
                if (verifying || entered.length < 6) return;
                verifying = true;
                if (btnVerify) btnVerify.disabled = true;
                clearMsg(msg2);

                try {
                    const fd = new FormData();
                    fd.append('otp', entered.join(''));
                    const res  = await fetch('?action=verify_otp', { method: 'POST', body: fd });
                    const data = await res.json();
                    if (!data.success) {
                        showMsg(msg2, data.message || 'Invalid code.', 'err');
                        entered = [];
                        refreshDisplay();
                        verifying = false;
                        return;
                    }
                    clearExpiryTimer();
                    loginOverlay.classList.add('fade-out');
                    setTimeout(() => loginOverlay.remove(), 550);
                    startDashboard();
                } catch {
                    showMsg(msg2, 'Network error. Please try again.', 'err');
                    entered = [];
                    refreshDisplay();
                    verifying = false;
                }
            }

            // ── Resend ────────────────────────────────────
            btnResend.addEventListener('click', () => {
                clearExpiryTimer();
                step2.style.display = 'none';
                step1.style.display = 'block';
                entered = [];
                clearMsg(msg2);
                btnSend.disabled = false;
                sendLabel.textContent = 'Send Access Code';
            });

            // ── Countdown timer ───────────────────────────
            function startExpiryTimer(secs) {
                clearExpiryTimer();
                let remaining = secs;
                function tick() {
                    remaining--;
                    if (!timerEl) return;
                    if (remaining <= 0) {
                        timerEl.textContent = 'Code expired — press Resend.';
                        timerEl.style.color = '#f04248';
                        clearExpiryTimer(); return;
                    }
                    const m = Math.floor(remaining / 60);
                    const s = String(remaining % 60).padStart(2, '0');
                    timerEl.textContent = `Code expires in ${m}:${s}`;
                    timerEl.style.color = '';
                }
                tick();
                expiryTimer = setInterval(tick, 1000);
            }

            function clearExpiryTimer() {
                if (expiryTimer) { clearInterval(expiryTimer); expiryTimer = null; }
                if (timerEl) timerEl.textContent = '';
            }

            refreshDisplay();
        })();

        /* ── Background music (YouTube · loop · can't stop) */
        (function () {
            const VIDEO_ID = 'Tq7523sJc6I';
            let player = null;

            function tryPlay() {
                try { if (player) player.playVideo(); } catch (_) {}
            }

            function ensurePlaying() {
                try {
                    if (!player) return;
                    const s = player.getPlayerState();
                    // 1 = playing, 3 = buffering — anything else: restart
                    if (s !== 1 && s !== 3) tryPlay();
                } catch (_) {}
            }

            window.onYouTubeIframeAPIReady = function () {
                player = new YT.Player('ytPlayer', {
                    videoId: VIDEO_ID,
                    playerVars: {
                        autoplay:       1,
                        loop:           1,
                        playlist:       VIDEO_ID,
                        controls:       0,
                        disablekb:      1,
                        fs:             0,
                        iv_load_policy: 3,
                        modestbranding: 1,
                        rel:            0,
                    },
                    events: {
                        onReady: function (e) {
                            e.target.setVolume(50);
                            e.target.playVideo();
                        },
                        onStateChange: function (e) {
                            // Ended (0) or Paused (2) → restart immediately
                            if (e.data === 0 || e.data === 2) {
                                setTimeout(tryPlay, 300);
                            }
                        },
                        onError: function () {
                            setTimeout(tryPlay, 4000);
                        }
                    }
                });
            };

            // Watchdog: force-play every 5 s regardless
            setInterval(ensurePlaying, 5000);

            // Browsers may block autoplay with audio — unmute on first interaction
            function onFirstTouch() {
                try {
                    if (player) { player.unMute(); player.setVolume(50); player.playVideo(); }
                } catch (_) {}
                document.removeEventListener('click',     onFirstTouch, true);
                document.removeEventListener('keydown',   onFirstTouch, true);
                document.removeEventListener('touchstart',onFirstTouch, true);
            }
            document.addEventListener('click',     onFirstTouch, true);
            document.addEventListener('keydown',   onFirstTouch, true);
            document.addEventListener('touchstart',onFirstTouch, true);
        })();
        </script>
        </body>
        </html>
