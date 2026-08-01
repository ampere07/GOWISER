<?php
ob_start();
require_once dirname(__DIR__) . '/config/config.php';
ob_end_clean();
ini_set('display_errors', '0'); // never leak PHP error HTML into a JSON response
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isLoggedIn()) {
    echo json_encode(['valid' => false, 'remaining' => 0]);
    exit;
}

// Update last activity then release the session lock immediately
$_SESSION['last_activity'] = time();
session_write_close();

$timeout   = (int)(ini_get('session.gc_maxlifetime') ?: 1440);
$remaining = $timeout; // Session is valid, return the full timeout

echo json_encode([
    'valid'     => true,
    'remaining' => $remaining,
    'user'      => currentUserId(),
]);
