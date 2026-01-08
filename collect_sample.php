<?php
$page_title = 'Collect Sample';
require_once 'includes/header.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';

$auth = new Auth();
$auth->requireRole('receptionist');

$db = new Database();

$sample_id = $_POST['sample_id'] ?? $_GET['sample_id'] ?? '';
$order_id = $_POST['order_id'] ?? $_GET['order_id'] ?? '';

if (!$sample_id) {
    header('Location: orders.php');
    exit();
}

// Get sample details
$db->query('SELECT ot.*, t.test_name, t.test_code, p.full_name, o.order_number
            FROM order_tests ot
            JOIN tests t ON ot.test_id = t.id
            JOIN orders o ON ot.order_id = o.id
            JOIN patients p ON o.patient_id = p.id
            WHERE ot.sample_id = :sample_id');
$db->bind(':sample_id', $sample_id);
$sample = $db->single();

if (!$sample) {
    echo '<div class="alert alert-danger">Sample not found!</div>';
    require_once 'includes/footer.php';
    exit();
}

// Handle collection
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirm_collect'])) {
    try {
        $db->query('BEGIN TRANSACTION');
        
        // Update the specific sample
        $db->query('UPDATE order_tests SET status = "sample-collected", 
                   sample_collected_at = NOW() 
                   WHERE sample_id = :sample_id');
        $db->bind(':sample_id', $sample_id);
        $db->execute();
        
        // Check if all samples in this order are now collected
        $db->query('SELECT COUNT(*) as total, 
                           SUM(CASE WHEN status = "sample-collected" THEN 1 ELSE 0 END) as collected
                    FROM order_tests 
                    WHERE order_id = :order_id');
        $db->bind(':order_id', $sample['order_id']);
        $status = $db->single();
        
        // Update order status if all samples collected
        if ($status['total'] == $status['collected']) {
            $db->query('UPDATE orders SET status = "sample-collected", 
                       collected_by = :collected_by 
                       WHERE id = :id');
            $db->bind(':collected_by', $_SESSION['user_id']);
            $db->bind(':id', $sample['order_id']);
            $db->execute();
        }
        
        $db->query('COMMIT');
        
        // Redirect back to order
        header('Location: view_order.php?id=' . $sample['order_id'] . '&message=collected');
        exit();
        
    } catch (Exception $e) {
        $db->query('ROLLBACK');
        $error = 'Error collecting sample: ' . $e->getMessage();
    }
}

// If GET request, show confirmation form
?>

<div class="row">
    <div class="col-md-6 offset-md-3">
        <div class="card">
            <div class="card-header bg-success text-white">
                <h5>Collect Sample</h5>
            </div>
            <div class="card-body">
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i>
                    <strong>Confirm Sample Collection</strong>
                </div>
                
                <div class="card mb-3">
                    <div class="card-body">
                        <h6>Sample Details</h6>
                        <p><strong>Sample ID:</strong> <?php echo $sample['sample_id']; ?></p>
                        <p><strong>Test:</strong> <?php echo $sample['test_name']; ?> (<?php echo $sample['test_code']; ?>)</p>
                        <p><strong>Sample Type:</strong> <?php echo $sample['sample_type']; ?></p>
                        <p><strong>Order:</strong> <?php echo $sample['order_number']; ?></p>
                        <p><strong>Patient:</strong> <?php echo $sample['full_name']; ?></p>
                        <p><strong>Current Status:</strong> 
                            <span class="badge bg-<?php echo $sample['status'] == 'pending' ? 'secondary' : 'warning'; ?>">
                                <?php echo $sample['status']; ?>
                            </span>
                        </p>
                    </div>
                </div>
                
                <form method="POST" action="">
                    <input type="hidden" name="sample_id" value="<?php echo $sample_id; ?>">
                    <input type="hidden" name="order_id" value="<?php echo $sample['order_id']; ?>">
                    
                    <div class="mb-3">
                        <label for="collection_notes" class="form-label">Collection Notes (Optional)</label>
                        <textarea class="form-control" id="collection_notes" name="collection_notes" 
                                  rows="2" placeholder="Any notes about sample collection..."></textarea>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="submit" name="confirm_collect" class="btn btn-success btn-lg">
                            <i class="bi bi-check-circle"></i> Confirm Sample Collection
                        </button>
                        <a href="view_order.php?id=<?php echo $sample['order_id']; ?>" 
                           class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>