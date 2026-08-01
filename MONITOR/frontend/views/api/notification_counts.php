<?php
ob_start();
require_once dirname(__DIR__) . '/config/config.php';
ob_end_clean();
ini_set('display_errors', '0'); // never leak PHP error HTML into a JSON response
header('Content-Type: application/json; charset=utf-8');

requireLogin();
if (!canSendMessages()) {
    jsonResponse(false, 'You are not allowed to send messages.');
}
session_write_close();

$routerId = scopedRouterId((int)($_GET['router_id'] ?? 0));
$rWhere   = $routerId ? ' AND router_id = ?' : '';
$rParams  = $routerId ? [$routerId] : [];

$hasPhone = "contact_number IS NOT NULL AND contact_number != ''";

// All
$s = db()->prepare("SELECT COUNT(*) FROM subscribers WHERE {$hasPhone}{$rWhere}");
$s->execute($rParams);
$countAll = (int)$s->fetchColumn();

// Expired today
$s = db()->prepare("SELECT COUNT(*) FROM subscribers WHERE {$hasPhone} AND DATE(subscription_end) = ?{$rWhere}");
$s->execute(array_merge([appToday()], $rParams));
$countExpiredToday = (int)$s->fetchColumn();

// Barangays — distinct address words (simple: group by trimmed address)
$s = db()->prepare("
    SELECT address, COUNT(*) AS cnt
    FROM subscribers
    WHERE {$hasPhone} AND address IS NOT NULL AND address != ''{$rWhere}
    GROUP BY address
    ORDER BY address ASC
");
$s->execute($rParams);
$rawAddresses = $s->fetchAll(PDO::FETCH_ASSOC);

// Extract unique barangay tokens (first meaningful word group)
$barangays = [];
foreach ($rawAddresses as $row) {
    $addr = trim($row['address']);
    if ($addr) {
        $barangays[$addr] = (int)$row['cnt'];
    }
}

jsonResponse(true, 'OK', [
    'count_all'           => $countAll,
    'count_expired_today' => $countExpiredToday,
    'barangays'           => $barangays,
]);
