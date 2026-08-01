<?php
require_once __DIR__ . '/config/config.php';

if (isLoggedIn()) {
    logActivity('auth', 'logout', 'User logged out');
    logoutUser();
}
redirect(BASE_URL . '/login');
