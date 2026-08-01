<?php
require_once dirname(__DIR__) . '/config/config.php';

do {
    if (MAINTENANCE_MODE) {
        flashMessage('warning', 'The system is currently under maintenance. New logins are temporarily disabled.');
        break;
    }

    if (!GOOGLE_ENABLED || empty(GOOGLE_CLIENT_ID)) {
        flashMessage('warning', 'Google login is disabled.');
        break;
    }

    // Validate state
    $state = $_GET['state'] ?? '';
    if (empty($state) || $state !== ($_SESSION['oauth_state'] ?? '')) {
        flashMessage('danger', 'Google login failed — invalid OAuth state. Please try again.');
        break;
    }
    unset($_SESSION['oauth_state']);

    // Check for error from Google
    if (isset($_GET['error'])) {
        flashMessage('warning', 'Google login was cancelled or denied.');
        break;
    }

    $code = $_GET['code'] ?? '';
    if (empty($code)) {
        flashMessage('danger', 'Google login failed — no authorization code received.');
        break;
    }

    // Exchange code for token
    $tokenData = http_build_query([
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'grant_type'    => 'authorization_code',
    ]);

    $ch = curl_init(GOOGLE_TOKEN_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $tokenData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $tokenResponse = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr || !$tokenResponse) {
        flashMessage('danger', 'Google login failed — could not communicate with Google. Please try again.');
        break;
    }

    $tokens = json_decode($tokenResponse, true);
    if (empty($tokens['access_token'])) {
        flashMessage('danger', 'Google login failed — could not obtain access token.');
        break;
    }

    // Fetch user info
    $ch = curl_init(GOOGLE_USERINFO_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $tokens['access_token']],
    ]);
    $userInfoResponse = curl_exec($ch);
    curl_close($ch);

    $googleUser = json_decode($userInfoResponse, true);
    if (empty($googleUser['email'])) {
        flashMessage('danger', 'Google login failed — could not retrieve your Google account info.');
        break;
    }

    // Find matching user by email (must already exist in system)
    $stmt = db()->prepare("
        SELECT user_id, firstname, lastname, username, email, role, router_id, is_active
        FROM users
        WHERE email = ? AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$googleUser['email']]);
    $user = $stmt->fetch();

    if (!$user) {
        flashMessage('danger',
            'Authentication Failed — No active account found for this Google email (' . $googleUser['email'] . '). Please contact your administrator.'
        );
        break;
    }

    // Login
    $user['avatar'] = $googleUser['picture'] ?? '';
    loginUser($user);
    logActivity('auth', 'login', 'User logged in via Google OAuth');

    $afterLogin = $_SESSION['redirect_after_login'] ?? '';
    unset($_SESSION['redirect_after_login']);
    $afterLogin = safeRedirectUrl($afterLogin);
    if (currentRole() === ROLE_SUPERADMIN) {
        redirect(BASE_URL . '/modules/router_select?redirect=' . urlencode($afterLogin));
    } else {
        redirect($afterLogin);
    }

} while (false);

redirect(BASE_URL . '/login');
