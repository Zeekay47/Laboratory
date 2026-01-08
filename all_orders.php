<?php
$page_title = 'All Orders';
require_once 'includes/header.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';

$auth = new Auth();
$auth->requireRole('manager');

$db = new Database();

// Date range filtering
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-2 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$status_filter = $_GET['status'] ?? 'all';

// Build query
$where_clause = 'WHERE DATE(o.order_date) BETWEEN :start_date AND :end_date';
$params = [
    ':start_date' => $start_date,
    ':end_date' => $end_date
];

if ($status_filter != 'all') {
    $where_clause .= ' AND o.status = :status';
    $params[':status'] = $status_filter;
}

// Get statistics
$db->query('SELECT 
                COUNT(*) as total_orders,
                COALESCE(SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END), 0) as pending,
                COALESCE(SUM(CASE WHEN status = "sample-collected" THEN 1 ELSE 0 END), 0) as collected,
                COALESCE(SUM(CASE WHEN status = "processing" THEN 1 ELSE 0 END), 0) as processing,
                COALESCE(SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END), 0) as completed
            FROM orders 
            WHERE DATE(order_date) BETWEEN :start_date AND :end_date');
$db->bind(':start_date', $start_date);
$db->bind(':end_date', $end_date);
$stats = $db->single();

// Get all orders with fixed completed_tests count
$sql = "SELECT o.*, p.full_name, p.phone, p.patient_code,
               (SELECT COUNT(*) FROM order_tests WHERE order_id = o.id) as test_count,
               (SELECT COUNT(*) FROM order_tests WHERE order_id = o.id 
                AND status IN ('completed', 'verified', 'results-entered')) as completed_tests
        FROM orders o 
        JOIN patients p ON o.patient_id = p.id 
        $where_clause 
        ORDER BY o.order_date DESC 
        LIMIT 200";

$db->query($sql);
foreach ($params as $key => $value) {
    $db->bind($key, $value);
}
$orders = $db->resultSet();

// Handle PDF Export
if (isset($_GET['export']) && $_GET['export'] == 'pdf') {
    try {
        require_once 'includes/vendor/autoload.php';
        
        // Check if mPDF is properly installed
        if (!class_exists('Mpdf\Mpdf')) {
            die('mPDF library not found. Please install it via composer: composer require mpdf/mpdf');
        }
        
        // Create mPDF instance with basic configuration
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'default_font_size' => 10,
            'default_font' => 'dejavusans',
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 20,
            'margin_bottom' => 20,
            'margin_header' => 5,
            'margin_footer' => 5
        ]);
        
        // Enable Asian fonts if needed
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;
        
        // Create HTML content for PDF
        $html = '
        <style>
            body { font-family: DejaVuSans, sans-serif; font-size: 10pt; }
            .header { text-align: center; margin-bottom: 15px; border-bottom: 1px solid #333; padding-bottom: 10px; }
            .title { font-size: 16px; font-weight: bold; margin-bottom: 5px; }
            .subtitle { font-size: 12px; margin-bottom: 10px; }
            .info { font-size: 9pt; margin-bottom: 10px; background-color: #f5f5f5; padding: 8px; border-radius: 3px; }
            table { width: 100%; border-collapse: collapse; font-size: 9pt; margin-top: 10px; }
            th { background-color: #e9ecef; padding: 6px; border: 1px solid #dee2e6; text-align: left; font-weight: bold; }
            td { padding: 5px; border: 1px solid #dee2e6; }
            .summary { margin-top: 20px; font-size: 9pt; padding: 10px; background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 3px; }
            .summary-title { font-weight: bold; margin-bottom: 5px; font-size: 10pt; }
            .footer { margin-top: 30px; font-size: 8pt; text-align: center; color: #6c757d; border-top: 1px solid #dee2e6; padding-top: 10px; }
            .text-center { text-align: center; }
            .text-right { text-align: right; }
            .badge { padding: 2px 6px; border-radius: 3px; font-size: 8pt; }
            .badge-success { background-color: #28a745; color: white; }
            .badge-warning { background-color: #ffc107; color: black; }
            .badge-info { background-color: #17a2b8; color: white; }
            .badge-secondary { background-color: #6c757d; color: white; }
        </style>
        
        <div class="header">
            <div class="title">LAB MANAGEMENT SYSTEM</div>
            <div class="subtitle">Orders Report</div>
        </div>
        
        <div class="info">
            <strong>Period:</strong> ' . date('d M Y', strtotime($start_date)) . ' to ' . date('d M Y', strtotime($end_date)) . '<br>';
        
        if ($status_filter != 'all') {
            $html .= '<strong>Status Filter:</strong> ' . ucfirst($status_filter) . '<br>';
        }
        
        $html .= '<strong>Generated:</strong> ' . date('d M Y h:i A') . '<br>
            <strong>Total Records:</strong> ' . count($orders) . '
        </div>
        
        <table>
            <thead>
                <tr>
                    <th width="15%">Order #</th>
                    <th width="25%">Patient</th>
                    <th width="15%">Patient Code</th>
                    <th width="15%">Date</th>
                    <th width="15%">Tests</th>
                    <th width="15%">Status</th>
                </tr>
            </thead>
            <tbody>';
        
        if (empty($orders)) {
            $html .= '<tr><td colspan="6" class="text-center">No orders found for selected period</td></tr>';
        } else {
            foreach ($orders as $order) {
                // Determine status badge class
                $badge_class = '';
                switch($order['status']) {
                    case 'pending': $badge_class = 'badge-secondary'; break;
                    case 'sample-collected': $badge_class = 'badge-info'; break;
                    case 'processing': $badge_class = 'badge-warning'; break;
                    case 'completed': $badge_class = 'badge-success'; break;
                    default: $badge_class = 'badge-secondary';
                }
                
                $html .= '
                <tr>
                    <td>' . htmlspecialchars($order['order_number']) . '</td>
                    <td>' . htmlspecialchars($order['full_name']) . '</td>
                    <td>' . htmlspecialchars($order['patient_code']) . '</td>
                    <td>' . date('d M Y', strtotime($order['order_date'])) . '</td>
                    <td>' . $order['test_count'] . ' tests<br><small>(' . $order['completed_tests'] . ' completed)</small></td>
                    <td><span class="badge ' . $badge_class . '">' . ucfirst(htmlspecialchars($order['status'])) . '</span></td>
                </tr>';
            }
        }
        
        $html .= '
            </tbody>
        </table>
        
        <div class="summary">
            <div class="summary-title">Summary Statistics:</div>
            <strong>Total Orders:</strong> ' . $stats['total_orders'] . '<br>
            <strong>Pending:</strong> ' . $stats['pending'] . '<br>
            <strong>Sample Collected:</strong> ' . $stats['collected'] . '<br>
            <strong>Processing:</strong> ' . $stats['processing'] . '<br>
            <strong>Completed:</strong> ' . $stats['completed'] . '
        </div>
        
        <div class="footer">
            Page {PAGENO} of {nbpg}<br>
            Generated by Lab Management System on ' . date('d M Y H:i:s') . '
        </div>';
        
        // Write HTML content to PDF
        $mpdf->WriteHTML($html);
        
        // Set PDF metadata
        $mpdf->SetCreator('Lab Management System');
        $mpdf->SetAuthor('Lab Management System');
        $mpdf->SetTitle('Orders Report - ' . date('Y-m-d'));
        $mpdf->SetSubject('Orders Report');
        
        // Output PDF for download
        $filename = 'orders_report_' . date('Y-m-d_H-i') . '.pdf';
        
        // Clear any previous output
        if (ob_get_length()) {
            ob_end_clean();
        }
        
        // Output the PDF
        $mpdf->Output($filename, 'D');
        exit;
        
    } catch (Exception $e) {
        // Log error and show message
        error_log("PDF Generation Error: " . $e->getMessage());
        die("Error generating PDF: " . $e->getMessage());
    }
}
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5>All Orders Management</h5>
            </div>
            <div class="card-body">
                <!-- Filter Form -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" action="" class="row g-3">
                            <div class="col-md-3">
                                <label for="start_date" class="form-label">From Date</label>
                                <input type="date" class="form-control" id="start_date" name="start_date" 
                                       value="<?php echo $start_date; ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="end_date" class="form-label">To Date</label>
                                <input type="date" class="form-control" id="end_date" name="end_date" 
                                       value="<?php echo $end_date; ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Status</option>
                                    <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="sample-collected" <?php echo $status_filter == 'sample-collected' ? 'selected' : ''; ?>>Sample Collected</option>
                                    <option value="processing" <?php echo $status_filter == 'processing' ? 'selected' : ''; ?>>Processing</option>
                                    <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">&nbsp;</label>
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-filter"></i> Filter
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Statistics -->
                <div class="row mb-4">
                    <div class="col-md-2">
                        <div class="card text-white bg-primary">
                            <div class="card-body text-center">
                                <h6>Total Orders</h6>
                                <h3><?php echo $stats['total_orders']; ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card text-white bg-secondary">
                            <div class="card-body text-center">
                                <h6>Pending</h6>
                                <h3><?php echo $stats['pending']; ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card text-white bg-info">
                            <div class="card-body text-center">
                                <h6>Collected</h6>
                                <h3><?php echo $stats['collected']; ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card text-white bg-warning">
                            <div class="card-body text-center">
                                <h6>Processing</h6>
                                <h3><?php echo $stats['processing']; ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card text-white bg-success">
                            <div class="card-body text-center">
                                <h6>Completed</h6>
                                <h3><?php echo $stats['completed']; ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card bg-light">
                            <div class="card-body text-center">
                                <h6>Period</h6>
                                <h6><?php echo date('d M Y', strtotime($start_date)); ?> to <?php echo date('d M Y', strtotime($end_date)); ?></h6>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Orders Table -->
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Order #</th>
                                        <th>Patient</th>
                                        <th>Patient Code</th>
                                        <th>Date & Time</th>
                                        <th>Tests</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($orders)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center">No orders found for selected period</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($orders as $order): ?>
                                        <tr>
                                            <td><strong><?php echo $order['order_number']; ?></strong></td>
                                            <td><?php echo $order['full_name']; ?></td>
                                            <td><?php echo $order['patient_code']; ?></td>
                                            <td>
                                                <?php echo date('d M Y', strtotime($order['order_date'])); ?><br>
                                                <small><?php echo date('h:i A', strtotime($order['order_date'])); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-info"><?php echo $order['test_count']; ?> tests</span><br>
                                                <small><?php echo $order['completed_tests']; ?> completed</small>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    switch($order['status']) {
                                                        case 'pending': echo 'secondary'; break;
                                                        case 'sample-collected': echo 'info'; break;
                                                        case 'processing': echo 'warning'; break;
                                                        case 'completed': echo 'success'; break;
                                                        default: echo 'danger';
                                                    }
                                                ?>">
                                                    <?php echo $order['status']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <!-- View Order Button -->
                                                    <a href="view_order.php?id=<?php echo $order['id']; ?>" 
                                                       class="btn btn-outline-primary" title="View Order Details">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    
                                                    <?php if ($order['status'] == 'completed'): ?>
                                                        <?php
                                                        // Check if report exists
                                                        $db->query('SELECT r.* FROM reports r WHERE order_id = :order_id ORDER BY generated_at DESC LIMIT 1');
                                                        $db->bind(':order_id', $order['id']);
                                                        $existing_report = $db->single();
                                                        
                                                        if ($existing_report):
                                                            $report_file = 'reports/' . $existing_report['report_path'];
                                                            $file_exists = file_exists($report_file);
                                                        ?>
                                                        
                                                        <?php if ($file_exists): ?>
                                                            <!-- Print Report Button (Direct print) -->
                                                            <button class="btn btn-outline-warning print-report-btn" 
                                                                    data-report-path="reports/<?php echo $existing_report['report_path']; ?>"
                                                                    data-report-number="<?php echo $existing_report['report_number']; ?>"
                                                                    title="Print Report">
                                                                <i class="bi bi-printer"></i>
                                                            </button>
                                                            
                                                        <?php else: ?>
                                                            <!-- Report file missing -->
                                                            <span class="btn btn-outline-danger" title="Report file missing from server">
                                                                <i class="bi bi-exclamation-triangle"></i>
                                                            </span>
                                                        <?php endif; ?>
                                                        
                                                        <?php else: ?>
                                                            <!-- No report in database -->
                                                            <span class="btn btn-outline-secondary" title="Report not generated yet">
                                                                <i class="bi bi-clock-history"></i>
                                                            </span>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($order['status'] == 'processing'): ?>
                                                        <!-- Verify Results Button -->
                                                        <a href="verify_results.php?order_id=<?php echo $order['id']; ?>" 
                                                           class="btn btn-outline-warning" title="Verify Test Results">
                                                            <i class="bi bi-check-circle"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Export Options -->
                        <div class="mt-4">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <button class="btn btn-outline-secondary" onclick="window.print()">
                                        <i class="bi bi-printer"></i> Print This Page
                                    </button>
                                </div>
                                <div>
                                    <a href="#" class="btn btn-success" onclick="exportToExcel()">
                                        <i class="bi bi-file-excel"></i> Export to Excel
                                    </a>
                                    <a href="?export=pdf&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&status=<?php echo $status_filter; ?>" 
                                       class="btn btn-danger" onclick="return confirm('Generate PDF report?')">
                                        <i class="bi bi-file-pdf"></i> Export to PDF
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('[title]').tooltip({
        trigger: 'hover',
        placement: 'top'
    });
    
    // Handle print report button click
    $('.print-report-btn').click(function(e) {
        e.preventDefault();
        const reportPath = $(this).data('report-path');
        const reportNumber = $(this).data('report-number');
        
        // Show loading indicator on button
        const button = $(this);
        const originalHtml = button.html();
        button.html('<span class="spinner-border spinner-border-sm"></span>');
        button.prop('disabled', true);
        
        // Create a hidden iframe for printing
        const iframe = document.createElement('iframe');
        iframe.style.display = 'none';
        iframe.src = reportPath;
        document.body.appendChild(iframe);
        
        // Wait for iframe to load
        iframe.onload = function() {
            try {
                // Restore button state
                button.html(originalHtml);
                button.prop('disabled', false);
                
                // Remove iframe from DOM
                setTimeout(function() {
                    document.body.removeChild(iframe);
                }, 1000);
                
                // Trigger print
                iframe.contentWindow.focus();
                iframe.contentWindow.print();
                
                
            } catch (error) {
                // If direct print fails, fallback to opening in new window
                console.log('Direct print failed, using fallback:', error);
                const printWindow = window.open(reportPath, '_blank');
                if (printWindow) {
                    setTimeout(function() {
                        printWindow.print();
                    }, 500);
                } else {
                    alert('warning', 
                        'Popup blocked! Please allow popups for this site to print reports.'
                    );
                }
            }
        };
        
        // Handle iframe errors
        iframe.onerror = function() {
            button.html(originalHtml);
            button.prop('disabled', false);
            document.body.removeChild(iframe);
            alert('danger', 'Failed to load report for printing. Please try again.');
        };
    });
    
    // Export to Excel function
    // Simple CSV Export (no warnings, works everywhere)
    window.exportToExcel = function() {
        var csv = 'Order #,Patient,Patient Code,Date,Tests,Status\r\n';
        
        <?php foreach ($orders as $order): ?>
        csv += '"<?php echo str_replace('"', '""', $order["order_number"]); ?>",' +
               '"<?php echo str_replace('"', '""', $order["full_name"]); ?>",' +
               '"<?php echo str_replace('"', '""', $order["patient_code"]); ?>",' +
               '"<?php echo date("d M Y", strtotime($order["order_date"])); ?>",' +
               '"<?php echo $order["test_count"]; ?> tests (<?php echo $order["completed_tests"]; ?> completed)",' +
               '"<?php echo str_replace('"', '""', $order["status"]); ?>"\r\n';
        <?php endforeach; ?>
        
        csv += '\r\n' +
               'Summary Statistics\r\n' +
               'Total Orders,<?php echo $stats["total_orders"]; ?>\r\n' +
               'Pending,<?php echo $stats["pending"]; ?>\r\n' +
               'Collected,<?php echo $stats["collected"]; ?>\r\n' +
               'Processing,<?php echo $stats["processing"]; ?>\r\n' +
               'Completed,<?php echo $stats["completed"]; ?>\r\n';
        
        // Create download link
        var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'orders_<?php echo date("Y-m-d"); ?>.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    };
    
});
</script>

<?php require_once 'includes/footer.php'; ?>