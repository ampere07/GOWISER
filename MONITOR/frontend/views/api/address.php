<?php
ob_start();
require_once dirname(__DIR__) . '/config/config.php';
ob_end_clean();
ini_set('display_errors', '0'); // never leak PHP error HTML into a JSON response
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

requireLogin();

$level    = $_GET['level']    ?? '';
$parent   = trim($_GET['p']   ?? '');
$province = trim($_GET['prov'] ?? '');

try {
    switch ($level) {

        case 'regions':
            $rows = db()->query(
                "SELECT DISTINCT region FROM address ORDER BY region"
            )->fetchAll(PDO::FETCH_COLUMN);
            echo json_encode(['success' => true, 'data' => $rows]);
            break;

        case 'provinces':
            $stmt = db()->prepare(
                "SELECT DISTINCT province FROM address WHERE region = ? ORDER BY province"
            );
            $stmt->execute([$parent]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
            break;

        case 'municipalities':
            $stmt = db()->prepare(
                "SELECT DISTINCT municipality FROM address WHERE province = ? ORDER BY municipality"
            );
            $stmt->execute([$parent]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
            break;

        case 'barangays':
            // Returns barangay rows for a municipality+province pair
            // Only returns rows where barangay is actually set (not the municipality stubs)
            $stmt = db()->prepare(
                "SELECT address_id, barangay, zipcode
                 FROM address
                 WHERE municipality = ? AND province = ?
                   AND barangay IS NOT NULL AND barangay != ''
                 ORDER BY barangay"
            );
            $stmt->execute([$parent, $province]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid level']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
