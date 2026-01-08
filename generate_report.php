<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'includes/header.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';
require_once 'includes/AutoReportGenerator.php';

$auth = new Auth();
$auth->canAccess(['receptionist', 'manager']);

$order_id = $_GET['order_id'] ?? 0;
$action = $_GET['action'] ?? 'download';

if (!$order_id) {
    $user_role = $_SESSION['role'];
    header('Location: ' . ($user_role == 'manager' ? 'all_orders.php' : 'orders.php'));
    exit();
}

$generator = new AutoReportGenerator();

if ($action == 'download') {
    $result = $generator->downloadReport($order_id);
    
    if ($result['success']) {
        // Serve the file for download
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
        header('Content-Length: ' . filesize($result['filepath']));
        readfile($result['filepath']);
        exit();
    } else {
        // Show error
        die('<!DOCTYPE html><html><head><title>Error</title></head><body>
            <div style="padding:20px;color:red;">' . $result['message'] . '</div>
            <a href="reports.php">Back to Reports</a></body></html>');
    }
}

// If no action specified, redirect to reports page
header('Location: reports.php');
exit();
?>