<?php
$page_title = 'Orders';
require_once 'includes/header.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';

$auth = new Auth();
$auth->requireRole('receptionist');

$db = new Database();

// Handle status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status'])) {
    $order_id = $_POST['order_id'];
    $status = $_POST['status'];
    
    if ($status == 'sample-collected') {
        $db->query('UPDATE orders SET status = :status, collected_by = :collected_by WHERE id = :id');
        $db->bind(':status', $status);
        $db->bind(':collected_by', $_SESSION['user_id']);
        $db->bind(':id', $order_id);
        $db->execute();
        
        $db->query('UPDATE order_tests SET status = :status, sample_collected_at = NOW() WHERE order_id = :order_id');
        $db->bind(':status', $status);
        $db->bind(':order_id', $order_id);
        $db->execute();
    }
}

// Handle order cancellation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['cancel_order'])) {
    $order_id = $_POST['order_id'];
    
    $db->query('BEGIN TRANSACTION');
    
    try {
        $db->query('DELETE FROM order_tests WHERE order_id = :order_id');
        $db->bind(':order_id', $order_id);
        $db->execute();
        
        $db->query('DELETE FROM orders WHERE id = :order_id');
        $db->bind(':order_id', $order_id);
        $db->execute();
        
        $db->query('COMMIT');
        
        echo '<script>window.location.href = window.location.href;</script>';
        exit();
        
    } catch (Exception $e) {
        $db->query('ROLLBACK');
        $message = '<div class="alert alert-danger">Error canceling order: ' . $e->getMessage() . '</div>';
    }
}

// Search orders
$where_clause = '';
$params = [];
$search_term = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['search'])) {
    $search_term = trim($_POST['search_term']);
    $status_filter = $_POST['status_filter'];
    
    $where_clause = 'WHERE (o.order_number LIKE :search OR p.full_name LIKE :search OR p.phone LIKE :search) AND DATE(o.order_date) >= DATE_SUB(CURDATE(), INTERVAL 2 DAY)';
    $params[':search'] = '%' . $search_term . '%';
    
    if ($status_filter != 'all') {
        $where_clause .= ' AND o.status = :status';
        $params[':status'] = $status_filter;
    }
} else {
    $where_clause = 'WHERE DATE(o.order_date) >= DATE_SUB(CURDATE(), INTERVAL 2 DAY)';
}

// Fetch orders
$sql = "SELECT o.*, p.full_name, p.phone, 
               (SELECT COUNT(*) FROM order_tests WHERE order_id = o.id) as test_count,
               (SELECT COUNT(*) FROM reports WHERE order_id = o.id) as report_count
        FROM orders o 
        JOIN patients p ON o.patient_id = p.id 
        $where_clause 
        ORDER BY o.id DESC 
        LIMIT 100";

$db->query($sql);
foreach ($params as $key => $value) {
    $db->bind($key, $value);
}
$orders = $db->resultSet();
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h5>Orders Management</h5>
            </div>
            <div class="card-body">
                <!-- Search Form -->
                <form method="POST" action="" class="mb-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <input type="text" class="form-control" name="search_term" 
                                   placeholder="Search by order number, patient name, or phone..." 
                                   value="<?php echo htmlspecialchars($search_term); ?>">
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" name="status_filter">
                                <option value="all" <?php echo ($_POST['status_filter'] ?? '') == 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="pending" <?php echo ($_POST['status_filter'] ?? '') == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="sample-collected" <?php echo ($_POST['status_filter'] ?? '') == 'sample-collected' ? 'selected' : ''; ?>>Sample Collected</option>
                                <option value="processing" <?php echo ($_POST['status_filter'] ?? '') == 'processing' ? 'selected' : ''; ?>>Processing</option>
                                <option value="completed" <?php echo ($_POST['status_filter'] ?? '') == 'completed' ? 'selected' : ''; ?>>Completed</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" name="search" class="btn btn-primary w-100">
                                <i class="bi bi-search"></i> Search
                            </button>
                        </div>
                    </div>
                </form>
                
                <!-- Orders Table -->
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Patient</th>
                                <th>Phone</th>
                                <th>Date & Time</th>
                                <th>Tests</th>
                                <th>Report</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($orders)): ?>
                                <tr>
                                    <td colspan="8" class="text-center">No orders found</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($orders as $order): 
                                    $status_color = '';
                                    switch($order['status']) {
                                        case 'pending': $status_color = 'secondary'; break;
                                        case 'sample-collected': $status_color = 'info'; break;
                                        case 'processing': $status_color = 'warning'; break;
                                        case 'completed': $status_color = 'success'; break;
                                        default: $status_color = 'danger';
                                    }
                                ?>
                                <tr>
                                    <td><strong><?php echo $order['order_number']; ?></strong></td>
                                    <td><?php echo $order['full_name']; ?></td>
                                    <td><?php echo $order['phone']; ?></td>
                                    <td><?php echo date('d M Y, h:i A', strtotime($order['order_date'])); ?></td>
                                    <td>
                                        <span class="badge bg-info"><?php echo $order['test_count']; ?> tests</span>
                                    </td>
                                    <td>
                                        <?php if ($order['report_count'] > 0): ?>
                                            <span class="badge bg-success">✓ Generated</span>
                                        <?php elseif ($order['status'] == 'completed'): ?>
                                            <span class="badge bg-warning">Ready</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $status_color; ?>">
                                            <?php echo $order['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="view_order.php?id=<?php echo $order['id']; ?>" 
                                               class="btn btn-outline-primary" title="View Order">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            
                                            <?php if ($order['status'] == 'pending'): ?>
                                                <button type="button" 
                                                        class="btn btn-outline-success collect-sample-btn" 
                                                        data-order-id="<?php echo $order['id']; ?>"
                                                        title="Collect Sample">
                                                    <i class="bi bi-droplet"></i>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($order['status'] == 'pending'): ?>
                                                <a href="order_confirmation.php?id=<?php echo $order['id']; ?>" 
                                                class="btn btn-outline-info" 
                                                title="Print Sample Labels & Receipt">
                                                    <i class="bi bi-tags"></i> <!-- Changed icon from printer to tags -->
                                                </a>
                                            <?php endif; ?>                                            
                                            <?php if ($order['status'] == 'completed'): ?>
                                                <a href="reports.php" 
                                                   class="btn btn-outline-warning" title="Print Report">
                                                    <i class="bi bi-file-earmark-pdf"></i>
                                                </a>
                                            <?php endif; ?>
                                            
                                            <?php if ($order['status'] == 'pending'): ?>
                                                <button type="button" 
                                                        class="btn btn-outline-danger cancel-order-btn" 
                                                        data-order-id="<?php echo $order['id']; ?>"
                                                        data-order-number="<?php echo $order['order_number']; ?>"
                                                        title="Cancel Order">
                                                    <i class="bi bi-x-circle"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Collect Sample Modal -->
<div class="modal fade" id="collectSampleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title">Collect Sample</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Mark sample as collected?</p>
                    <input type="hidden" name="order_id" id="collectOrderId">
                    <input type="hidden" name="status" value="sample-collected">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_status" class="btn btn-success">Collect Sample</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Cancel Order Modal -->
<div class="modal fade" id="cancelOrderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Cancel Order</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>Warning:</strong> This action cannot be undone!
                    </div>
                    <p>Cancel order <strong id="cancelOrderNumber"></strong>?</p>
                    <p class="text-danger">This will permanently delete the order and all test records.</p>
                    <input type="hidden" name="order_id" id="cancelOrderId">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">No, Keep Order</button>
                    <button type="submit" name="cancel_order" class="btn btn-danger">Yes, Cancel Order</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.btn-group .btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}
.btn-group {
    flex-wrap: nowrap;
    white-space: nowrap;
}
.btn-group > .btn {
    min-height: 31px;
}
</style>

<script>
$(document).ready(function() {
    $('.collect-sample-btn').click(function(e) {
        e.preventDefault();
        var orderId = $(this).data('order-id');
        $('#collectOrderId').val(orderId);
        $('#collectSampleModal').modal('show');
    });
    
    $('.cancel-order-btn').click(function(e) {
        e.preventDefault();
        var orderId = $(this).data('order-id');
        var orderNumber = $(this).data('order-number');
        $('#cancelOrderId').val(orderId);
        $('#cancelOrderNumber').text(orderNumber);
        $('#cancelOrderModal').modal('show');
    });
    
    $('[title]').tooltip({
        trigger: 'hover',
        placement: 'top'
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>