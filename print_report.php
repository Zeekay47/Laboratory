<?php
// print_report.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/database.php';
require_once 'includes/Auth.php';
require_once 'includes/Database.php';

$auth = new Auth();
$auth->canAccess(['receptionist', 'manager']);

$order_id = $_GET['order_id'] ?? 0;
$mode = $_GET['mode'] ?? 'print'; 

if (!$order_id) {
    die('Order ID required');
}

// Get report details
$db = new Database();
$db->query('SELECT r.* FROM reports r WHERE order_id = :order_id ORDER BY generated_at DESC LIMIT 1');
$db->bind(':order_id', $order_id);
$report = $db->single();

if (!$report) {
    die('Report not found');
}

$filepath = __DIR__ . '/reports/' . $report['report_path'];

if (!file_exists($filepath)) {
    die('Report file not found');
}

if ($mode === 'print') {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . basename($filepath) . '"');
    header('Content-Length: ' . filesize($filepath));
    readfile($filepath);
    exit();
} else {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
    header('Content-Length: ' . filesize($filepath));
    readfile($filepath);
    exit();
}
?>