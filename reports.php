<?php
$page_title = 'Print Reports';
require_once 'includes/header.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';

$auth = new Auth();
$auth->requireRole('receptionist');

$db = new Database();

// Search parameters
$search_term = $_POST['search_term'] ?? '';
$date_filter = $_POST['date_filter'] ?? '';
$is_searching = !empty($search_term) || !empty($date_filter);

// Build search conditions
$where_conditions = [];
$params = [];

if (!empty($search_term)) {
    $where_conditions[] = '(o.order_number LIKE :search OR p.full_name LIKE :search OR p.phone LIKE :search OR r.report_number LIKE :search)';
    $params[':search'] = '%' . $search_term . '%';
}

if ($date_filter == 'today') {
    $where_conditions[] = 'DATE(o.order_date) = CURDATE()';
} elseif ($date_filter == 'yesterday') {
    $where_conditions[] = 'DATE(o.order_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)';
} elseif ($date_filter == 'week') {
    $where_conditions[] = 'o.order_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)';
}

$where_sql = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

$sql = "SELECT 
            r.*,
            o.id as order_id,
            o.order_number,
            o.order_date,
            o.status as order_status,
            o.result_ready_date,
            p.full_name,
            p.patient_code,
            p.phone,
            p.email,
            s.full_name as generated_by_name,
            (SELECT COUNT(*) FROM order_tests ot WHERE ot.order_id = o.id) as test_count,
            (SELECT COUNT(*) FROM order_tests ot WHERE ot.order_id = o.id AND ot.status IN ('completed', 'verified')) as completed_tests
        FROM reports r
        JOIN orders o ON r.order_id = o.id
        JOIN patients p ON o.patient_id = p.id
        JOIN staff s ON r.generated_by = s.id
        $where_sql
        ORDER BY r.generated_at DESC
        LIMIT 100";

$db->query($sql);
foreach ($params as $key => $value) {
    $db->bind($key, $value);
}
$generated_reports = $db->resultSet();

// Fetch completed orders WITHOUT reports (for information only - not for action)
$sql_ready = "SELECT 
                o.id as order_id,
                o.order_number,
                o.order_date,
                o.result_ready_date,
                p.full_name,
                p.patient_code,
                p.phone,
                p.email,
                (SELECT COUNT(*) FROM order_tests ot WHERE ot.order_id = o.id) as test_count,
                (SELECT COUNT(*) FROM order_tests ot WHERE ot.order_id = o.id AND ot.status IN ('completed', 'verified')) as completed_tests
            FROM orders o 
            JOIN patients p ON o.patient_id = p.id
            WHERE o.status = 'completed' 
            AND o.result_ready_date IS NOT NULL
            AND NOT EXISTS (SELECT 1 FROM reports r WHERE r.order_id = o.id)
            ORDER BY o.result_ready_date DESC
            LIMIT 10";

$db->query($sql_ready);
$ready_orders = $db->resultSet();

// Statistics
$db->query('SELECT 
                COUNT(*) as total_reports,
                COALESCE(SUM(CASE WHEN DATE(generated_at) = CURDATE() THEN 1 ELSE 0 END), 0) as today_reports,
                COALESCE(SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END), 0) as delivered_reports
            FROM reports');
$stats = $db->single();

// Count of completed orders (for information)
$db->query('SELECT COUNT(*) as completed_orders 
            FROM orders o 
            WHERE o.status = "completed"');
$order_stats = $db->single();
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header bg-success text-white">
                <h5>Print & Deliver Reports</h5>
            </div>
            <div class="card-body">
                <!-- Statistics -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <h6>Total Reports</h6>
                                <h3><?php echo $stats['total_reports']; ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <h6>Today's Reports</h6>
                                <h3><?php echo $stats['today_reports']; ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <h6>Completed Orders</h6>
                                <h3><?php echo $order_stats['completed_orders']; ?></h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body text-center">
                                <h6>Delivered</h6>
                                <h3><?php echo $stats['delivered_reports']; ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Search Form -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="POST" action="" class="row g-3">
                            <div class="col-md-6">
                                <input type="text" class="form-control" name="search_term" 
                                       placeholder="Search by order number, patient name, phone, or report number..."
                                       value="<?php echo htmlspecialchars($search_term); ?>">
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" name="date_filter">
                                    <option value="">All Dates</option>
                                    <option value="today" <?php echo $date_filter == 'today' ? 'selected' : ''; ?>>Today Only</option>
                                    <option value="yesterday" <?php echo $date_filter == 'yesterday' ? 'selected' : ''; ?>>Yesterday</option>
                                    <option value="week" <?php echo $date_filter == 'week' ? 'selected' : ''; ?>>Last 7 Days</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <button type="submit" name="search" class="btn btn-primary w-100">
                                    <i class="bi bi-search"></i> Search Reports
                                </button>
                                <?php if ($is_searching): ?>
                                <a href="reports.php" class="btn btn-outline-secondary w-100 mt-2">
                                    <i class="bi bi-x-circle"></i> Clear Search
                                </a>
                                <?php endif; ?>
                            </div>
                        </form>
                        <?php if ($is_searching): ?>
                            <div class="mt-2 text-muted small">
                                <i class="bi bi-info-circle"></i> Showing search results. 
                                <a href="reports.php">Show all reports</a>
                            </div>
                        <?php else: ?>
                            <div class="mt-2 text-muted small">
                                <i class="bi bi-info-circle"></i> Showing generated reports ready for printing.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Information Section: Completed Orders without Reports -->
                <?php if (!empty($ready_orders)): ?>
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        <h6>Automatic Report Generation Status 
                            <span class="badge bg-white text-info"><?php echo count($ready_orders); ?> orders</span>
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            <strong>Automatic System:</strong> Reports are generated automatically when orders are completed by the lab manager. 
                            The following orders have been completed and reports will be generated soon:
                        </div>
                        <div class="row">
                            <?php foreach ($ready_orders as $order): ?>
                            <div class="col-md-6 mb-2">
                                <div class="card border-info">
                                    <div class="card-body p-3">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong><?php echo $order['order_number']; ?></strong><br>
                                                <small><?php echo $order['full_name']; ?></small>
                                            </div>
                                            <div class="text-end">
                                                <span class="badge bg-warning">Processing</span><br>
                                                <small>Completed: <?php echo date('d M', strtotime($order['result_ready_date'])); ?></small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Main Section: Generated Reports -->
                <div class="card">
                    <div class="card-header">
                        <h6>Generated Reports Ready for Delivery 
                            <span class="badge bg-primary"><?php echo count($generated_reports); ?></span>
                            <?php if ($is_searching): ?>
                                <small class="text-muted">(Search Results)</small>
                            <?php endif; ?>
                        </h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($generated_reports)): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> 
                                <?php if ($is_searching): ?>
                                    No reports found matching your search criteria.
                                <?php else: ?>
                                    No reports have been generated yet. Reports appear here automatically when lab manager completes orders.
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Report #</th>
                                            <th>Order #</th>
                                            <th>Patient</th>
                                            <th>Generated On</th>
                                            <th>Contact Info</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($generated_reports as $report): 
                                            $report_file = 'reports/' . $report['report_path'];
                                            $file_exists = file_exists($report_file);
                                        ?>
                                        <tr>
                                            <td><strong><?php echo $report['report_number']; ?></strong></td>
                                            <td><?php echo $report['order_number']; ?></td>
                                            <td>
                                                <?php echo $report['full_name']; ?><br>
                                                <small class="text-muted"><?php echo $report['patient_code']; ?></small>
                                            </td>
                                            <td>
                                                <?php echo date('d M Y', strtotime($report['generated_at'])); ?><br>
                                                <small><?php echo date('h:i A', strtotime($report['generated_at'])); ?></small>
                                            </td>
                                            <td>
                                                <?php if ($report['email']): ?>
                                                    <div><i class="bi bi-envelope"></i> <?php echo $report['email']; ?></div>
                                                <?php endif; ?>
                                                <?php if ($report['phone']): ?>
                                                    <div><i class="bi bi-phone"></i> <?php echo $report['phone']; ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($report['delivered_at']): ?>
                                                    <span class="badge bg-success">Delivered</span><br>
                                                    <small><?php echo date('d M Y', strtotime($report['delivered_at'])); ?></small>
                                                <?php else: ?>
                                                    <span class="badge bg-warning">Pending Delivery</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
    <div class="btn-group btn-group-sm">
        <?php if ($file_exists): ?>
            <a href="reports/<?php echo $report['report_path']; ?>" 
               target="_blank" class="btn btn-outline-primary" title="View Report">
                <i class="bi bi-eye"></i>
            </a>
            
            <!-- DIRECT PRINT BUTTON -->
            <button class="btn btn-outline-warning print-report-btn" 
                    data-report-path="reports/<?php echo $report['report_path']; ?>"
                    data-report-name="<?php echo $report['report_number']; ?>"
                    title="Print Report">
                <i class="bi bi-printer"></i>
            </button>
            
            <!-- FALLBACK DOWNLOAD BUTTON (hidden) -->
            <a href="reports/<?php echo $report['report_path']; ?>" 
               download class="btn btn-outline-success download-report-btn" 
               style="display: none;"
               title="Download PDF">
                <i class="bi bi-download"></i>
            </a>
            
        <?php else: ?>
            <span class="btn btn-outline-danger" title="Report file missing">
                <i class="bi bi-exclamation-triangle"></i>
            </span>
        <?php endif; ?>
        
        <?php if (!$report['delivered_at']): ?>
            <button class="btn btn-outline-primary" 
                    onclick="markDelivered(<?php echo $report['id']; ?>)"
                    title="Mark as Delivered">
                <i class="bi bi-check-lg"></i>
            </button>
        <?php endif; ?>
    </div>
</td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Auto-Generation Info -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h6>Automatic Report Generation System</h6>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-success">
                            <i class="bi bi-info-circle"></i>
                            <strong>System Note:</strong> Reports are generated automatically when the lab manager approves test results. 
                            No manual generation is required by receptionists.
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="card text-center h-100">
                                    <div class="card-body">
                                        <i class="bi bi-robot" style="font-size: 3rem; color: #28a745;"></i>
                                        <h5>Automatic Generation</h5>
                                        <p>Reports are created automatically when orders are completed by the manager.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card text-center h-100">
                                    <div class="card-body">
                                        <i class="bi bi-download" style="font-size: 3rem; color: #0d6efd;"></i>
                                        <h5>Download & Print</h5>
                                        <p>Receptionists can download and print reports from this page.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card text-center h-100">
                                    <div class="card-body">
                                        <i class="bi bi-check-lg" style="font-size: 3rem; color: #ffc107;"></i>
                                        <h5>Mark Delivered</h5>
                                        <p>After printing, mark the report as delivered when handed to patient.</p>
                                    </div>
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
    // Add tooltips
    $('[title]').tooltip({
        trigger: 'hover',
        placement: 'top'
    });
    
    // Handle print button click
    $('.print-report-btn').click(function(e) {
        e.preventDefault();
        const reportPath = $(this).data('report-path');
        const reportName = $(this).data('report-name');
        
        // Extract order ID from the path
        const orderId = extractOrderIdFromPath(reportPath);
        
        if (orderId) {
            attemptDirectPrint(orderId, reportName);
        } else {
            // Fallback to direct PDF opening
            window.open(reportPath, '_blank');
        }
    });
});

function extractOrderIdFromPath(path) {
    // Extract order ID from filename like report_ORD-00001_20260105_114956.pdf
    const match = path.match(/report_ORD-(\d+)_/);
    return match ? match[1] : null;
}

function attemptDirectPrint(orderId, reportName) {
    // Create iframe for printing
    const iframe = document.createElement('iframe');
    iframe.style.display = 'none';
    iframe.src = `print_report.php?order_id=${orderId}&mode=print`;
    
    iframe.onload = function() {
        try {
            // Try to print
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
            
            // Show success message
            showPrintStatus('success', `Print dialog opened for ${reportName}`);
            
            // Check if print was successful (this is tricky in browsers)
            setTimeout(() => {
                // Remove iframe after 30 seconds
                iframe.remove();
            }, 30000);
            
        } catch (error) {
            console.error('Print failed:', error);
            // Fallback to download
            showPrintStatus('warning', 'Print dialog failed. Downloading report...');
            triggerDownload(orderId);
        }
    };
    
    iframe.onerror = function() {
        console.error('Failed to load PDF');
        showPrintStatus('danger', 'Failed to load report. Downloading instead...');
        triggerDownload(orderId);
    };
    
    document.body.appendChild(iframe);
}

function triggerDownload(orderId) {
    // Show download button
    $(`[data-order-id="${orderId}"] .download-report-btn`).show();
    
    // Alternatively, auto-trigger download
    window.location.href = `print_report.php?order_id=${orderId}&mode=download`;
}

function showPrintStatus(type, message) {
    // Create status alert
    const alert = document.createElement('div');
    alert.className = `alert alert-${type} alert-dismissible fade show`;
    alert.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    // Insert at top of page
    const container = document.querySelector('.card-body');
    container.insertBefore(alert, container.firstChild);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        alert.remove();
    }, 5000);
}

function markDelivered(reportId) {
    if (confirm('Mark this report as delivered?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'mark_delivered.php';
        
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'id';
        input.value = reportId;
        
        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
    }
}

// Alternative: Simple print function (less reliable)
function printPDF(url) {
    const printWindow = window.open(url, '_blank');
    if (printWindow) {
        printWindow.onload = function() {
            printWindow.print();
        };
    } else {
        // Popup blocked, fallback to download
        alert('Popup blocked. Please allow popups for this site or use download button.');
        window.location.href = url;
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>