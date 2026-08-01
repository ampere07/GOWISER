<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && hasRole(ROLE_SUPERADMIN)) {
    $_SESSION['settings_alert_dismissed'] = true;
}
http_response_code(204);
