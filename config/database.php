<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'dtc_lab');

// Site configuration
define('SITE_NAME', 'DTC Laboratory');
define('SITE_URL', 'http://localhost/dtc-lab/');

// File paths
define('UPLOAD_PATH', __DIR__ . '/../uploads/');
define('REPORT_PATH', __DIR__ . '/../reports/');

// Create directories if they don't exist
if (!is_dir(UPLOAD_PATH)) mkdir(UPLOAD_PATH, 0777, true);
if (!is_dir(REPORT_PATH)) mkdir(REPORT_PATH, 0777, true);

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
?>