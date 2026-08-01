<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';

if (!isLoggedIn()) {
    redirect(BASE_URL . '/login');
}
redirect(BASE_URL . '/modules/dashboard/');
