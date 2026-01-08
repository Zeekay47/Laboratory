<?php
$page_title = 'Patient History';
require_once 'includes/header.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';

$auth = new Auth();
$auth->requireAuth();

$db = new Database();

$patient_id = $_GET['id'] ?? 0;
if (!$patient_id) {
    header('Location: patients.php');
    exit();
}

// Get patient details
$db->query('SELECT * FROM patients WHERE id = :id');
$db->bind(':id', $patient_id);
$patient = $db->single();

if (!$patient) {
    echo '<div class="alert alert-danger">Patient not found!</div>';
    require_once 'includes/footer.php';
    exit();
}

// Get patient's order history
$db->query('SELECT o.*, 
                   (SELECT COUNT(*) FROM order_tests WHERE order_id = o.id) as test_count,
                   (SELECT COUNT(*) FROM order_tests WHERE order_id = o.id AND status = "completed") as completed_tests
            FROM orders o 
            WHERE o.patient_id = :patient_id 
            ORDER BY o.order_date DESC');
$db->bind(':patient_id', $patient_id);
$orders = $db->resultSet();

// Get test history
$db->query('SELECT ot.*, t.test_name, t.test_code, t.category, o.order_number, o.order_date
            FROM order_tests ot
            JOIN tests t ON ot.test_id = t.id
            JOIN orders o ON ot.order_id = o.id
            WHERE o.patient_id = :patient_id
            ORDER BY o.order_date DESC, t.test_name');
$db->bind(':patient_id', $patient_id);
$tests_history = $db->resultSet();

// Statistics
$db->query('SELECT 
                COUNT(DISTINCT o.id) as total_orders,
                SUM(CASE WHEN o.status = "completed" THEN 1 ELSE 0 END) as completed_orders,
                COUNT(DISTINCT ot.test_id) as unique_tests
            FROM orders o
            LEFT JOIN order_tests ot ON o.id = ot.order_id
            WHERE o.patient_id = :patient_id');
$db->bind(':patient_id', $patient_id);
$stats = $db->single();

// Calculate additional statistics for display
if ($stats['total_orders'] > 0) {
    $completion_rate = round(($stats['completed_orders'] / $stats['total_orders']) * 100, 1);
} else {
    $completion_rate = 0;
}
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <div class="d-flex justify-content-between align-items-center">
                    <h5>Patient History: <?php echo htmlspecialchars($patient['full_name']); ?></h5>
                    <span class="badge bg-light text-dark">ID: <?php echo htmlspecialchars($patient['patient_code']); ?></span>
                </div>
            </div>
            <div class="card-body">
                <!-- Patient Information -->
                <div class="row mb-4">
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-body">
                                <h6>Patient Details</h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <p><strong>Patient Code:</strong> <?php echo htmlspecialchars($patient['patient_code']); ?></p>
                                        <p><strong>Name:</strong> <?php echo htmlspecialchars($patient['full_name']); ?></p>
                                        <p><strong>Age/Gender:</strong> <?php echo htmlspecialchars($patient['age']); ?> yrs / <?php echo htmlspecialchars($patient['gender']); ?></p>
                                        <?php if (!empty($patient['cnic'])): ?>
                                            <p><strong>CNIC:</strong> <?php echo htmlspecialchars($patient['cnic']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-6">
                                        <p><strong>Phone:</strong> <?php echo htmlspecialchars($patient['phone']); ?></p>
                                        <?php if (!empty($patient['email'])): ?>
                                            <p><strong>Email:</strong> <?php echo htmlspecialchars($patient['email']); ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($patient['registered_date'])): ?>
                                            <p><strong>Registered:</strong> <?php echo date('d M Y', strtotime($patient['registered_date'])); ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($patient['last_visit_date'])): ?>
                                            <p><strong>Last Visit:</strong> <?php echo date('d M Y', strtotime($patient['last_visit_date'])); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if (!empty($patient['address'])): ?>
                                    <p><strong>Address:</strong> <?php echo htmlspecialchars($patient['address']); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body text-center">
                                <h6>Statistics</h6>
                                <div class="row mb-3">
                                    <div class="col-6">
                                        <h3 class="text-primary"><?php echo $stats['total_orders']; ?></h3>
                                        <small class="text-muted">Total Orders</small>
                                    </div>
                                    <div class="col-6">
                                        <h3 class="text-success"><?php echo $stats['completed_orders']; ?></h3>
                                        <small class="text-muted">Completed</small>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-12">
                                        <div class="progress" style="height: 10px;">
                                            <div class="progress-bar bg-success" role="progressbar" 
                                                 style="width: <?php echo $completion_rate; ?>%" 
                                                 aria-valuenow="<?php echo $completion_rate; ?>" 
                                                 aria-valuemin="0" aria-valuemax="100">
                                            </div>
                                        </div>
                                        <small class="text-muted"><?php echo $completion_rate; ?>% completion rate</small>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-12">
                                        <h3 class="text-info"><?php echo $stats['unique_tests']; ?></h3>
                                        <small class="text-muted">Unique Tests</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Order History -->
                <div class="card mb-4">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6>Order History (<?php echo count($orders); ?>)</h6>
                            <?php if (!empty($orders)): ?>
                                <a href="#test-history" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-arrow-down"></i> Jump to Test History
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($orders)): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> No order history found for this patient.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Order #</th>
                                            <th>Date</th>
                                            <th>Tests</th>
                                            <th>Status</th>
                                            <th>Referred By</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($orders as $order): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($order['order_number']); ?></strong></td>
                                            <td><?php echo date('d M Y', strtotime($order['order_date'])); ?></td>
                                            <td>
                                                <span class="badge bg-info"><?php echo $order['test_count']; ?> tests</span><br>
                                                <small class="text-muted"><?php echo $order['completed_tests']; ?> completed</small>
                                            </td>
                                            <td>
                                                <span class="badge bg-success">
                                                    <?php echo htmlspecialchars($order['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo !empty($order['referred_by']) ? htmlspecialchars($order['referred_by']) : '-'; ?></td>
                                            <td>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <a href="view_order.php?id=<?php echo $order['id']; ?>" 
                                                       class="btn btn-outline-primary" title="View Order">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    <?php if ($order['status'] == 'completed'): ?>
                                                        <a href="generate_report.php?order_id=<?php echo $order['id']; ?>" 
                                                           class="btn btn-outline-success" title="Generate Report">
                                                            <i class="bi bi-printer"></i>
                                                        </a>
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

                <!-- Test History -->
                <div class="card" id="test-history">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6>Test History (<?php echo count($tests_history); ?> tests)</h6>
                            <small class="text-muted">Showing latest results first</small>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($tests_history)): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> No test history found.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Test</th>
                                            <th>Category</th>
                                            <th>Order #</th>
                                            <th>Date</th>
                                            <th>Sample ID</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        // Group tests by test_id and order_number to avoid duplicates
                                        $grouped_tests = [];
                                        foreach ($tests_history as $test) {
                                            $key = $test['test_id'] . '-' . $test['order_number'];
                                            if (!isset($grouped_tests[$key])) {
                                                $grouped_tests[$key] = $test;
                                            }
                                        }
                                        ?>
                                        <?php foreach ($grouped_tests as $test): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($test['test_name']); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($test['test_code']); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark"><?php echo htmlspecialchars($test['category']); ?></span>
                                            </td>
                                            <td>
                                                <a href="view_order.php?search=<?php echo urlencode($test['order_number']); ?>" 
                                                   class="text-decoration-none">
                                                    <code><?php echo htmlspecialchars($test['order_number']); ?></code>
                                                </a>
                                            </td>
                                            <td><?php echo date('d M Y', strtotime($test['order_date'])); ?></td>
                                            <td>
                                                <code class="text-primary"><?php echo htmlspecialchars($test['sample_id']); ?></code>
                                            </td>
                                            <td>
                                                <span class="badge bg-success">
                                                    <?php echo htmlspecialchars($test['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($test['status'] == 'verified' || $test['status'] == 'completed'): ?>
                                                    <a href="view_results.php?sample_id=<?php echo urlencode($test['sample_id']); ?>" 
                                                       class="btn btn-sm btn-info" title="View Results">
                                                        <i class="bi bi-eyeglasses"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="mt-4">
                    <div class="d-flex justify-content-between">
                        <div>
                            <a href="patients.php" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left"></i> Back to Patients
                            </a>
                            <a href="new_order.php?patient_id=<?php echo $patient_id; ?>" 
                               class="btn btn-primary">
                                <i class="bi bi-plus-circle"></i> New Order
                            </a>
                        </div>
                        <div>
                            <button class="btn btn-info" onclick="window.print()">
                                <i class="bi bi-printer"></i> Print History
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .sidebar, .navbar, .content-header, .btn, .alert, .btn-group,
    .d-print-none, .no-print {
        display: none !important;
    }
    
    .card {
        border: 1px solid #ddd !important;
        margin-bottom: 20px !important;
        box-shadow: none !important;
        page-break-inside: avoid;
    }
    
    .table {
        border-collapse: collapse !important;
        width: 100% !important;
    }
    
    .table th, .table td {
        border: 1px solid #ddd !important;
        padding: 8px !important;
    }
    
    .table th {
        background-color: #f8f9fa !important;
        color: #000 !important;
    }
    
    .badge {
        border: 1px solid #ccc !important;
        background-color: #f8f9fa !important;
        color: #000 !important;
    }
    
    h5, h6 {
        color: #000 !important;
    }
    
    a[href] {
        text-decoration: none !important;
        color: #000 !important;
    }
    
    code {
        background-color: #f8f9fa !important;
        padding: 2px 4px !important;
        border: 1px solid #ddd !important;
    }
}

.progress {
    background-color: #e9ecef;
    border-radius: 5px;
}

.progress-bar {
    border-radius: 5px;
}

.btn-group-sm .btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

.badge {
    font-size: 0.75em;
}
</style>

<script>
$(document).ready(function() {
    // Add hover effect to table rows
    $('table tbody tr').hover(
        function() {
            $(this).addClass('table-active');
        },
        function() {
            $(this).removeClass('table-active');
        }
    );
    
    // Smooth scroll to test history section
    $('a[href="#test-history"]').click(function(e) {
        e.preventDefault();
        $('html, body').animate({
            scrollTop: $('#test-history').offset().top - 20
        }, 500);
    });
    
    // Add tooltips to buttons
    $('[title]').tooltip({
        trigger: 'hover',
        placement: 'top'
    });
    
    // Simple print function
    $('button[onclick="window.print()"]').click(function(e) {
        e.preventDefault();
        if (confirm('Print patient history?')) {
            window.print();
        }
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>