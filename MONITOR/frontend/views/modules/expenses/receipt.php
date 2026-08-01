<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireCanPrintSensitiveRecords();
if (!in_array(currentRole(), MODULE_ACCESS['expenses'])) {
    http_response_code(403); exit;
}

if (!selectedRouterId() && currentRole() !== ROLE_SUPERADMIN) {
    $router = defaultRouterForUser(currentUser() ?? []);
    if ($router) setSelectedRouter((int)$router['router_id'], $router['name']);
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(404); exit; }

$stmt = db()->prepare("SELECT receipt, receipt_mime, receipt_name FROM expenses WHERE expense_id = ? AND router_id = ?");
$stmt->execute([$id, (int)(selectedRouterId() ?: 0)]);
$row = $stmt->fetch();

if (!$row || !$row['receipt']) { http_response_code(404); exit; }

$filePath = BASE_PATH . '/' . $row['receipt'];
if (!file_exists($filePath)) { http_response_code(404); exit; }

$mime     = $row['receipt_mime'] ?: 'application/octet-stream';
$filename = $row['receipt_name'] ?: 'receipt';
$inline   = str_starts_with($mime, 'image/') || $mime === 'application/pdf';

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . rawurlencode($filename) . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=3600');
readfile($filePath);
