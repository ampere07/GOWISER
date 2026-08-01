<?php
require_once BASE_PATH . '/config/constants.php';

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']) && !empty($_SESSION['user_role']);
}

function currentUser(): ?array {
    if (!isLoggedIn()) return null;
    return [
        'user_id'   => $_SESSION['user_id'],
        'username'  => $_SESSION['username']  ?? '',
        'firstname' => $_SESSION['firstname'] ?? '',
        'lastname'  => $_SESSION['lastname']  ?? '',
        'email'     => $_SESSION['email']     ?? '',
        'role'      => $_SESSION['user_role'] ?? '',
        'router_id' => $_SESSION['user_router_id'] ?? null,
        'avatar'    => $_SESSION['avatar']    ?? '',
    ];
}

function currentUserId(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function currentRole(): string {
    return $_SESSION['user_role'] ?? '';
}

function hasRole(string ...$roles): bool {
    return in_array(currentRole(), $roles, true);
}

function hasMinRole(string $minRole): bool {
    if ($minRole === ROLE_CASHIER) return hasRole(ROLE_CASHIER, ROLE_ADMIN, ROLE_SUPERADMIN);
    if ($minRole === ROLE_USER) return hasRole(ROLE_USER, ROLE_ADMIN, ROLE_SUPERADMIN);
    if ($minRole === ROLE_LINEMAN) return hasRole(ROLE_LINEMAN, ROLE_ADMIN, ROLE_SUPERADMIN);

    $userLevel = ROLE_LEVELS[currentRole()] ?? 0;
    $minLevel  = ROLE_LEVELS[$minRole]      ?? 999;
    return $userLevel >= $minLevel;
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        $uri    = $_SERVER['REQUEST_URI'] ?? '';
        $isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
        if (!$isAjax && !str_contains($uri, '/api/')) {
            $_SESSION['redirect_after_login'] = $uri;
        }
        redirect(BASE_URL . '/login');
    }

    // Enforce server-side idle timeout (default 30 minutes, configurable via SESSION_IDLE_TIMEOUT)
    $idleTimeout = (int)($_ENV['SESSION_IDLE_TIMEOUT'] ?? 1800);
    if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity']) > $idleTimeout) {
        logoutUser();
        $uri    = $_SERVER['REQUEST_URI'] ?? '';
        $isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
        if (!$isAjax && !str_contains($uri, '/api/')) {
            $_SESSION['redirect_after_login'] = $uri;
        }
        redirect(BASE_URL . '/login?timeout=1');
    }
    $_SESSION['last_activity'] = time();

    // Superadmin must have a router selected before accessing any page
    if (currentRole() === ROLE_SUPERADMIN && selectedRouterId() === null) {
        try {
            $routerCount = (int)db()->query("SELECT COUNT(*) FROM routers")->fetchColumn();
            if ($routerCount === 0) return;
        } catch (Throwable) {}

        $uri    = $_SERVER['REQUEST_URI'] ?? '';
        $isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
        if (
            !$isAjax &&
            !str_contains($uri, '/modules/router_select') &&
            !str_contains($uri, '/api/') &&
            !str_contains($uri, '/logout')
        ) {
            $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $fullUri = $scheme . '://' . $_SERVER['HTTP_HOST'] . $uri;
            redirect(BASE_URL . '/modules/router_select?redirect=' . urlencode($fullUri));
        }
    }
}

function requireRole(string ...$roles): void {
    requireLogin();
    if (!hasRole(...$roles)) {
        forbidden();
    }
}

function requireMinRole(string $minRole): void {
    requireLogin();
    if (!hasMinRole($minRole)) {
        forbidden();
    }
}

function forbidden(): never {
    http_response_code(403);
    $pageTitle = 'Access Denied';
    include BASE_PATH . '/includes/header.php';
    echo '<div class="container-fluid py-5 text-center">
            <i class="bi bi-shield-x text-danger" style="font-size:4rem;"></i>
            <h2 class="mt-3">Access Denied</h2>
            <p class="text-muted">You do not have permission to access this page.</p>
            <a href="' . BASE_URL . '/modules/dashboard/" class="btn btn-primary">
                <i class="bi bi-house"></i> Go to Dashboard
            </a>
          </div>';
    include BASE_PATH . '/includes/footer.php';
    exit;
}

function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

function loginUser(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id']   = $user['user_id'];
    $_SESSION['username']  = $user['username'];
    $_SESSION['firstname'] = $user['firstname'];
    $_SESSION['lastname']  = $user['lastname'];
    $_SESSION['email']     = $user['email'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_router_id'] = $user['router_id'] ?? null;
    $_SESSION['avatar']    = $user['avatar'] ?? '';
    $_SESSION['logged_in_at'] = time();

    // Superadmin must re-select a router on each new login
    if ($user['role'] === ROLE_SUPERADMIN) {
        clearSelectedRouter();
    } else {
        $r = defaultRouterForUser($user);
        if ($r) setSelectedRouter((int)$r['router_id'], $r['name']);
    }

    // Update last_login in DB
    try {
        $stmt = db()->prepare("UPDATE users SET last_login = ? WHERE user_id = ?");
        $stmt->execute([appNow(), $user['user_id']]);
    } catch (PDOException $e) { /* non-fatal */ }
}

// ── Router session helpers ────────────────────────────────────
function selectedRouterId(): ?int {
    return isset($_SESSION['selected_router_id']) ? (int)$_SESSION['selected_router_id'] : null;
}

function selectedRouterName(): string {
    return $_SESSION['selected_router_name'] ?? '';
}

function selectedRouter(): ?array {
    $id = selectedRouterId();
    if (!$id) return null;
    return ['router_id' => $id, 'name' => selectedRouterName()];
}

function setSelectedRouter(int $routerId, string $routerName): void {
    $_SESSION['selected_router_id']   = $routerId;
    $_SESSION['selected_router_name'] = $routerName;
}

function clearSelectedRouter(): void {
    unset($_SESSION['selected_router_id'], $_SESSION['selected_router_name']);
}

function logoutUser(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

// ── Brute-force protection ────────────────────────────────────
function checkLoginAttempts(string $identifier): bool {
    $key     = 'login_attempts_' . md5($identifier);
    $lockKey = 'login_locked_' . md5($identifier);

    if (!empty($_SESSION[$lockKey]) && $_SESSION[$lockKey] > time()) {
        return false; // still locked
    }
    return true;
}

function recordLoginAttempt(string $identifier): void {
    $key     = 'login_attempts_' . md5($identifier);
    $lockKey = 'login_locked_' . md5($identifier);

    $_SESSION[$key] = ($_SESSION[$key] ?? 0) + 1;

    if ($_SESSION[$key] >= LOGIN_MAX_ATTEMPTS) {
        $_SESSION[$lockKey] = time() + (LOGIN_LOCKOUT_MINUTES * 60);
        $_SESSION[$key]     = 0;
    }
}

function clearLoginAttempts(string $identifier): void {
    $key     = 'login_attempts_' . md5($identifier);
    $lockKey = 'login_locked_' . md5($identifier);
    unset($_SESSION[$key], $_SESSION[$lockKey]);
}

function getLockoutRemainingSeconds(string $identifier): int {
    $lockKey = 'login_locked_' . md5($identifier);
    if (!empty($_SESSION[$lockKey]) && $_SESSION[$lockKey] > time()) {
        return $_SESSION[$lockKey] - time();
    }
    return 0;
}
