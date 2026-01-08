<?php
// fix_reports.php - One-click fix for missing reports
session_start();

// Simple check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Database connection
$host = 'localhost';
$dbname = 'dtc_lab';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Handle regeneration
if (isset($_GET['regenerate_all'])) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Regenerating Reports</title></head><body>';
    echo '<h2>Regenerating Missing Reports...</h2>';
    
    $stmt = $pdo->query('SELECT DISTINCT order_id FROM reports');
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($reports as $report) {
        echo "Processing Order #{$report['order_id']}... ";
        echo '<a href="generate_report.php?order_id=' . $report['order_id'] . '&action=download" target="_blank">Download</a><br>';
        flush();
    }
    
    echo '<hr><h3>Done!</h3>';
    echo '<a href="reports.php">Back to Reports</a>';
    echo '</body></html>';
    exit();
}

// Get missing reports count
$stmt = $pdo->query('SELECT COUNT(*) as missing FROM reports r 
                     WHERE NOT EXISTS (SELECT 1 FROM orders o WHERE o.id = r.order_id AND o.report_path IS NOT NULL) 
                     OR r.report_path = "" OR r.report_path IS NULL');
$missing_count = $stmt->fetch(PDO::FETCH_ASSOC)['missing'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fix Missing Reports</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-header bg-danger text-white">
                        <h4 class="mb-0">Fix Missing Reports</h4>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-warning">
                            <h5><i class="bi bi-exclamation-triangle"></i> Problem Detected</h5>
                            <p>Some reports exist in the database but the PDF files are missing from the server.</p>
                            <p class="mb-0"><strong>Missing Files:</strong> <?php echo $missing_count; ?> reports</p>
                        </div>
                        
                        <h5>Quick Fix Options:</h5>
                        
                        <div class="row mt-4">
                            <div class="col-md-6">
                                <div class="card h-100">
                                    <div class="card-body text-center">
                                        <h5>Option 1: One-Click Fix</h5>
                                        <p>Automatically regenerate all missing reports.</p>
                                        <a href="fix_reports.php?regenerate_all=1" class="btn btn-primary btn-lg">
                                            <i class="bi bi-play-circle"></i> Fix All Reports
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card h-100">
                                    <div class="card-body text-center">
                                        <h5>Option 2: Manual Fix</h5>
                                        <p>Download each report individually.</p>
                                        <a href="#report-list" class="btn btn-success btn-lg">
                                            <i class="bi bi-list"></i> View & Download
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <hr id="report-list">
                        
                        <h5>Reports List:</h5>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Report #</th>
                                        <th>Order #</th>
                                        <th>Patient</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $stmt = $pdo->query('SELECT r.*, o.order_number, p.full_name 
                                                         FROM reports r 
                                                         JOIN orders o ON r.order_id = o.id 
                                                         JOIN patients p ON o.patient_id = p.id 
                                                         ORDER BY r.generated_at DESC 
                                                         LIMIT 20');
                                    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                    
                                    foreach ($reports as $report) {
                                        $file_exists = file_exists('reports/' . $report['report_path']);
                                        echo '<tr>';
                                        echo '<td>' . htmlspecialchars($report['report_number']) . '</td>';
                                        echo '<td>' . htmlspecialchars($report['order_number']) . '</td>';
                                        echo '<td>' . htmlspecialchars($report['full_name']) . '</td>';
                                        echo '<td>';
                                        if ($file_exists) {
                                            echo '<span class="badge bg-success">OK</span>';
                                        } else {
                                            echo '<span class="badge bg-danger">Missing</span>';
                                        }
                                        echo '</td>';
                                        echo '<td>';
                                        echo '<a href="generate_report.php?order_id=' . $report['order_id'] . '&action=download" 
                                               class="btn btn-sm ' . ($file_exists ? 'btn-outline-success' : 'btn-warning') . '">
                                              ' . ($file_exists ? 'Download' : 'Regenerate') . '
                                              </a>';
                                        echo '</td>';
                                        echo '</tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="mt-4 text-center">
                            <a href="reports.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Back to Reports
                            </a>
                            <button onclick="location.reload()" class="btn btn-outline-primary">
                                <i class="bi bi-arrow-clockwise"></i> Refresh List
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>