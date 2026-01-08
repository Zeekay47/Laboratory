<?php
require_once 'config/database.php';
require_once 'includes/Auth.php';
require_once 'includes/Database.php';

$auth = new Auth();
$auth->requireRole('receptionist');

$db = new Database();

// Check for report ID in both POST and GET (for flexibility)
$report_id = $_POST['id'] ?? $_POST['report_id'] ?? $_GET['id'] ?? 0;

if (!$report_id) {
    header('Location: reports.php');
    exit();
}

// Get report details
$db->query('SELECT * FROM reports WHERE id = :id');
$db->bind(':id', $report_id);
$report = $db->single();

if (!$report) {
    header('Location: reports.php');
    exit();
}

// Handle delivery marking
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $db->query('UPDATE reports SET 
               delivered_by = :delivered_by, 
               delivered_at = NOW()
               WHERE id = :id');
    
    $db->bind(':delivered_by', $_SESSION['user_id']);
    $db->bind(':id', $report_id);
    
    if ($db->execute()) {
        header('Location: reports.php?message=delivered');
        exit();
    } else {
        header('Location: reports.php?message=error');
        exit();
    }
}

// If GET request, show form
$page_title = 'Mark Report Delivered';
require_once 'includes/header.php';
?>

<div class="row">
    <div class="col-md-6 offset-md-3">
        <div class="card">
            <div class="card-header bg-success text-white">
                <h5>Mark Report as Delivered</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i>
                    Report: <strong><?php echo $report['report_number']; ?></strong><br>
                    Order: <strong><?php 
                        // You might want to get order number too
                        $db->query('SELECT order_number FROM orders WHERE id = :order_id');
                        $db->bind(':order_id', $report['order_id']);
                        $order = $db->single();
                        echo $order['order_number'] ?? 'N/A';
                    ?></strong>
                </div>
                
                <form method="POST" action="">
                    <input type="hidden" name="report_id" value="<?php echo $report_id; ?>">
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="confirm_delivery" 
                                   name="confirm_delivery" value="1" required>
                            <label class="form-check-label" for="confirm_delivery">
                                <strong>Confirm Report Delivery</strong><br>
                                <small class="text-muted">I confirm that this report has been delivered to the patient</small>
                            </label>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <p class="text-muted">
                            <small>
                                <i class="bi bi-exclamation-triangle"></i>
                                This action will mark the report as delivered. Date and time will be recorded automatically.
                            </small>
                        </p>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle"></i> Mark as Delivered
                        </button>
                        <a href="reports.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>