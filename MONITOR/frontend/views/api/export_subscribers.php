<?php
require_once dirname(__DIR__) . '/config/config.php';
requireLogin();
requireCanPrintSensitiveRecords();
session_write_close();

$isSuperAdmin = currentRole() === ROLE_SUPERADMIN;
$isAdmin      = hasMinRole(ROLE_ADMIN); // true for both admin & superadmin

$f        = $_SESSION['filter_subscribers'] ?? [];
$search   = $f['q']         ?? '';
$status   = $f['status']    ?? '';
$connType = $f['conn_type'] ?? '';
$expDate  = $f['exp_date']  ?? '';
$planId   = (int)($f['plan_id'] ?? 0);

$selRouterId = selectedRouterId();

$expDateResolved = match($expDate) {
    'yesterday' => date('Y-m-d', strtotime('-1 day')),
    'today'     => date('Y-m-d'),
    'tomorrow'  => date('Y-m-d', strtotime('+1 day')),
    default     => preg_match('/^\d{4}-\d{2}-\d{2}$/', $expDate) ? $expDate : '',
};

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[]  = '(s.firstname LIKE ? OR s.lastname LIKE ? OR CONCAT(s.firstname, \' \', s.lastname) LIKE ? OR s.account_number LIKE ? OR s.ppp_username LIKE ?)';
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like, $like, $like, $like]);
}
if ($status)          { $where[] = 's.status = ?';                  $params[] = $status; }
if ($connType)        { $where[] = 's.connection_type = ?';         $params[] = $connType; }
if ($selRouterId)     { $where[] = 's.router_id = ?';               $params[] = $selRouterId; }
if ($expDateResolved) { $where[] = 'DATE(s.subscription_end) = ?';  $params[] = $expDateResolved; }
if ($planId > 0)      { $where[] = 's.plan_id = ?';                 $params[] = $planId; }

$whereStr = implode(' AND ', $where);

$stmt = db()->prepare("
    SELECT s.account_number, s.firstname, s.lastname, s.status, s.connection_type,
           p.title      AS plan_title,
           p.speed_mbps,
           p.amount     AS plan_amount,
           r.name       AS router_name,
           s.ppp_username,
           s.subscription_start, s.subscription_end,
           s.address, s.barangay, s.municipality, s.province,
           s.contact_number, s.email,
           s.latitude, s.longitude,
           s.created_at
    FROM subscribers s
    LEFT JOIN plans   p ON p.plan_id   = s.plan_id
    LEFT JOIN routers r ON r.router_id = s.router_id
    WHERE {$whereStr}
    ORDER BY s.created_at DESC
    LIMIT 10000
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filename = 'subscribers_' . date('Ymd_His') . '.csv';

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
        'Account #', 'First Name', 'Last Name', 'Status', 'Connection Type',
        'Plan', 'Speed (Mbps)', 'Monthly Rate',
        'Router', 'PPP Username',
        'Subscription Start', 'Subscription End',
        'Address', 'Barangay', 'Municipality', 'Province',
        'Contact Number', 'Email',
        'Latitude', 'Longitude',
        'Created At',
    ]);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['account_number'],
            $row['firstname'],
            $row['lastname'],
            ucfirst($row['status'] ?? ''),
            strtoupper($row['connection_type'] ?? ''),
            $row['plan_title'] ?? '',
            $row['speed_mbps'] ?? '',
            $row['plan_amount'] ?? '',
            $row['router_name'] ?? '',
            $row['ppp_username'] ?? '',
            $row['subscription_start'] ?? '',
            $row['subscription_end'] ?? '',
            $row['address'] ?? '',
            $row['barangay'] ?? '',
            $row['municipality'] ?? '',
            $row['province'] ?? '',
            $row['contact_number'] ?? '',
            $row['email'] ?? '',
            $row['latitude'] ?? '',
            $row['longitude'] ?? '',
            $row['created_at'] ?? '',
        ]);
    }
} elseif ($isAdmin) {
    // ── Admin: basic info (no PPP credentials, email, coordinates) ─
    fputcsv($out, [
        'Account #', 'First Name', 'Last Name', 'Status', 'Connection Type',
        'Plan', 'Speed (Mbps)',
        'Router',
        'Subscription Start', 'Subscription End',
        'Address', 'Barangay', 'Municipality',
        'Contact Number',
        'Created At',
    ]);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['account_number'],
            $row['firstname'],
            $row['lastname'],
            ucfirst($row['status'] ?? ''),
            strtoupper($row['connection_type'] ?? ''),
            $row['plan_title'] ?? '',
            $row['speed_mbps'] ?? '',
            $row['router_name'] ?? '',
            $row['subscription_start'] ?? '',
            $row['subscription_end'] ?? '',
            $row['address'] ?? '',
            $row['barangay'] ?? '',
            $row['municipality'] ?? '',
            $row['contact_number'] ?? '',
            $row['created_at'] ?? '',
        ]);
    }
} else {
    // ── Cashier: account number and name only ────────────────────
    fputcsv($out, ['Account #', 'First Name', 'Last Name']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['account_number'],
            $row['firstname'],
            $row['lastname'],
        ]);
    }
}

fclose($out);
exit;
