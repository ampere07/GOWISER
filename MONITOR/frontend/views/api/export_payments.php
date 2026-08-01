<?php
require_once dirname(__DIR__) . '/config/config.php';
requireRole(ROLE_CASHIER, ROLE_ADMIN, ROLE_SUPERADMIN);
requireCanPrintSensitiveRecords();
session_write_close();

$isSuperAdmin = currentRole() === ROLE_SUPERADMIN;
$isAdmin      = hasMinRole(ROLE_ADMIN); // true for both admin & superadmin

$f        = $_SESSION['filter_payments'] ?? [];
$search   = $f['q']         ?? '';
$method   = $f['method']    ?? '';
$status   = $f['status']    ?? '';
$dateFrom = $f['date_from'] ?? '';
$dateTo   = $f['date_to']   ?? '';

$routerId  = selectedRouterId();
$hasFilter = $search !== '' || $method !== '' || $status !== '' || $dateFrom !== '' || $dateTo !== '';

$where  = ['1=1'];
$params = [];

if ($routerId) {
    $where[]  = 's.router_id = ?';
    $params[] = $routerId;
}
if ($search) {
    $where[]  = '(s.firstname LIKE ? OR s.lastname LIKE ? OR CONCAT(s.firstname, \' \', s.lastname) LIKE ? OR s.account_number LIKE ? OR py.reference_number LIKE ? OR py.or_number LIKE ?)';
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like, $like, $like, $like, $like]);
}
if ($method)   { $where[] = 'py.method = ?';              $params[] = $method; }
if ($status)   { $where[] = 'py.status = ?';              $params[] = $status; }
if ($dateFrom) { $where[] = 'DATE(py.payment_date) >= ?'; $params[] = $dateFrom; }
if ($dateTo)   { $where[] = 'DATE(py.payment_date) <= ?'; $params[] = $dateTo; }

// Mirror the list default: no filter = today only
if (!$hasFilter) {
    $where[]  = 'DATE(py.payment_date) = ?';
    $params[] = date('Y-m-d');
}

$whereStr = implode(' AND ', $where);

$stmt = db()->prepare("
    SELECT py.or_number,
           s.account_number, s.firstname, s.lastname,
           py.amount, pt.name AS type_name,
           py.method, py.reference_number,
           py.payment_date, py.period_start, py.period_end,
           py.status, py.notes,
           CONCAT(u.firstname,  ' ', u.lastname)  AS added_by,
           CONCAT(ue.firstname, ' ', ue.lastname)  AS edited_by,
           r.name AS router_name
    FROM payments py
    JOIN subscribers s         ON s.subscriber_id  = py.subscriber_id
    LEFT JOIN users u          ON u.user_id         = py.user_id
    LEFT JOIN users ue         ON ue.user_id        = py.updated_by
    LEFT JOIN payment_types pt ON pt.type_id        = py.payment_type_id
    LEFT JOIN routers r        ON r.router_id       = s.router_id
    WHERE {$whereStr}
    ORDER BY py.payment_date DESC, py.payment_id DESC
    LIMIT 10000
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filename = 'payments_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

if ($isSuperAdmin) {
    // ── SuperAdmin: all columns ──────────────────────────────────
    fputcsv($out, [
        'OR #', 'Account #', 'Subscriber',
        'Amount', 'Type', 'Method', 'Reference #',
        'Payment Date', 'Period Start', 'Period End',
        'Status', 'Notes',
        'Added By', 'Edited By', 'Router',
    ]);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['or_number'] ?? '',
            $row['account_number'],
            trim($row['firstname'] . ' ' . $row['lastname']),
            $row['amount'],
            $row['type_name'] ?? 'Subscription',
            strtoupper($row['method'] ?? ''),
            $row['reference_number'] ?? '',
            $row['payment_date'] ?? '',
            $row['period_start'] ?? '',
            $row['period_end'] ?? '',
            ucfirst($row['status'] ?? ''),
            $row['notes'] ?? '',
            trim($row['added_by'] ?? ''),
            trim($row['edited_by'] ?? ''),
            $row['router_name'] ?? '',
        ]);
    }
} elseif ($isAdmin) {
    // ── Admin: basic info (no reference #, notes, audit trail) ───
    fputcsv($out, [
        'OR #', 'Account #', 'Subscriber',
        'Amount', 'Type', 'Method',
        'Payment Date', 'Period Start', 'Period End',
        'Status',
    ]);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['or_number'] ?? '',
            $row['account_number'],
            trim($row['firstname'] . ' ' . $row['lastname']),
            $row['amount'],
            $row['type_name'] ?? 'Subscription',
            strtoupper($row['method'] ?? ''),
            $row['payment_date'] ?? '',
            $row['period_start'] ?? '',
            $row['period_end'] ?? '',
            ucfirst($row['status'] ?? ''),
        ]);
    }
} else {
    // ── Cashier: account number and name only ────────────────────
    fputcsv($out, ['Account #', 'Subscriber']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['account_number'],
            trim($row['firstname'] . ' ' . $row['lastname']),
        ]);
    }
}

fclose($out);
exit;
