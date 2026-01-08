<?php
$page_title = 'View Order';
require_once 'includes/header.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';

$auth = new Auth();
$auth->requireAuth();

$db = new Database();

$order_id = $_GET['id'] ?? 0;
if (!$order_id) {
    // Redirect to appropriate dashboard based on role
    $user_role = $_SESSION['role'] ?? 'receptionist';
    if ($user_role == 'manager') {
        header('Location: manager.php');
    } else {
        header('Location: index.php');
    }
    exit();
}

// Store the referrer in session if it's an order list page
if (isset($_SERVER['HTTP_REFERER'])) {
    $referrer = $_SERVER['HTTP_REFERER'];
    // Check if the referrer is an order list page
    if (strpos($referrer, 'all_orders.php') !== false) {
        $_SESSION['order_list_referrer'] = 'all_orders.php';
    } elseif (strpos($referrer, 'orders.php') !== false) {
        $_SESSION['order_list_referrer'] = 'orders.php';
    }
}

// Fetch order details
$db->query('SELECT o.*, p.* FROM orders o 
           JOIN patients p ON o.patient_id = p.id 
           WHERE o.id = :id');
$db->bind(':id', $order_id);
$order = $db->single();

// Fetch order tests with details
$db->query('SELECT ot.*, t.test_name, t.test_code, t.category, t.sample_type,
                   (SELECT COUNT(*) FROM test_results tr WHERE tr.order_test_id = ot.id) as result_count
            FROM order_tests ot 
            JOIN tests t ON ot.test_id = t.id 
            WHERE ot.order_id = :order_id 
            ORDER BY t.category, t.test_name');
$db->bind(':order_id', $order_id);
$tests = $db->resultSet();

// Check if current user can access this order
$user_role = $_SESSION['role'];
$can_edit = ($user_role == 'receptionist' && $order['status'] == 'pending');
$can_verify = ($user_role == 'manager' && $order['status'] == 'processing');
$can_generate_report = ($user_role == 'receptionist' || $user_role == 'manager') && 
                       ($order['status'] == 'completed' || $order['status'] == 'processing');

// Determine the back URL based on user role and referrer
$back_url = 'index.php'; // Default fallback

if (isset($_SESSION['order_list_referrer'])) {
    $back_url = $_SESSION['order_list_referrer'];
} else {
    // Fallback based on user role
    switch ($user_role) {
        case 'manager':
            $back_url = 'all_orders.php';
            break;
        case 'receptionist':
            $back_url = 'orders.php';
            break;
        case 'technician':
            $back_url = 'technician.php';
            break;
        default:
            $back_url = 'index.php';
    }
}
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <div class="d-flex justify-content-between align-items-center">
                    <h5>Order: <?php echo $order['order_number']; ?></h5>
                    <div>
                        <span class="badge bg-light text-dark">Status: <?php echo $order['status']; ?></span>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <!-- Order Information -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <h6>Patient Information</h6>
                                <p><strong>Name:</strong> <?php echo $order['full_name']; ?></p>
                                <p><strong>Patient Code:</strong> <?php echo $order['patient_code']; ?></p>
                                <?php if ($order['cnic']): ?>
                                    <p><strong>CNIC:</strong> <?php echo $order['cnic']; ?></p>
                                <?php endif; ?>
                                <p><strong>Phone:</strong> <?php echo $order['phone']; ?></p>
                                <p><strong>Age/Gender:</strong> <?php echo $order['age']; ?> yrs / <?php echo $order['gender']; ?></p>
                                <?php if ($order['address']): ?>
                                    <p><strong>Address:</strong> <?php echo $order['address']; ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <h6>Order Details</h6>
                                <p><strong>Order Date:</strong> <?php echo date('d M Y, h:i A', strtotime($order['order_date'])); ?></p>
                                <?php if ($order['referred_by']): ?>
                                    <p><strong>Referred By:</strong> <?php echo $order['referred_by']; ?></p>
                                <?php endif; ?>
                                <?php if ($order['clinical_notes']): ?>
                                    <p><strong>Clinical Notes:</strong> <?php echo $order['clinical_notes']; ?></p>
                                <?php endif; ?>
                                <?php if ($order['result_ready_date']): ?>
                                    <p><strong>Result Ready:</strong> <?php echo date('d M Y, h:i A', strtotime($order['result_ready_date'])); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tests Information -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h6>Tests (<?php echo count($tests); ?>)</h6>
                    </div>
                    <div class="card-body">
                        <?php
                        // Group tests by category
                        $tests_by_category = [];
                        foreach ($tests as $test) {
                            $tests_by_category[$test['category']][] = $test;
                        }
                        
                        foreach ($tests_by_category as $category => $category_tests):
                        ?>
                        <h6 class="mt-3"><?php echo $category; ?></h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th>Test Name</th>
                                        <th>Code</th>
                                        <th>Sample ID</th>
                                        <th>Sample Type</th>
                                        <th>Status</th>
                                        <th>Results</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($category_tests as $test): ?>
                                    <tr>
                                        <td><?php echo $test['test_name']; ?></td>
                                        <td><code><?php echo $test['test_code']; ?></code></td>
                                        <td><strong><?php echo $test['sample_id']; ?></strong></td>
                                        <td><?php echo $test['sample_type']; ?></td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                switch($test['status']) {
                                                    case 'pending': echo 'secondary'; break;
                                                    case 'sample-collected': echo 'info'; break;
                                                    case 'processing': echo 'warning'; break;
                                                    case 'results-entered': echo 'primary'; break;
                                                    case 'verified': echo 'success'; break;
                                                    case 'completed': echo 'success'; break;
                                                    default: echo 'danger';
                                                }
                                            ?>">
                                                <?php echo $test['status']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $test['result_count'] > 0 ? 'success' : 'secondary'; ?>">
                                                <?php echo $test['result_count']; ?> results
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <?php if ($user_role == 'receptionist' && $test['status'] == 'pending'): ?>
                                                    <!-- Collect button for receptionist -->
                                                    <form method="POST" action="collect_sample.php" style="display: inline;">
                                                        <input type="hidden" name="sample_id" value="<?php echo $test['sample_id']; ?>">
                                                        <input type="hidden" name="order_id" value="<?php echo $order_id; ?>">
                                                        <button type="submit" class="btn btn-success btn-sm"
                                                                onclick="return confirm('Mark sample <?php echo $test['sample_id']; ?> as collected?')">
                                                            <i class="bi bi-droplet"></i> Collect
                                                        </button>
                                                    </form>
                                                <?php elseif ($user_role == 'technician' && 
                                                            ($test['status'] == 'sample-collected' || 
                                                            $test['status'] == 'processing')): ?>
                                                    <!-- Enter Results button for technician -->
                                                    <a href="enter_results.php?sample_id=<?php echo $test['sample_id']; ?>" 
                                                    class="btn btn-primary btn-sm">
                                                        <i class="bi bi-pencil"></i> Enter Results
                                                    </a>
                                                <?php elseif ($user_role == 'manager' && $test['status'] == 'results-entered'): ?>
                                                    <a href="verify_results.php?sample_id=<?php echo $test['sample_id']; ?>" 
                                                    class="btn btn-success btn-sm">
                                                        <i class="bi bi-check-circle"></i> Verify
                                                    </a>
                                                <?php elseif ($test['status'] == 'verified' || $test['status'] == 'completed'): ?>
                                                    <a href="view_results.php?sample_id=<?php echo $test['sample_id']; ?>" 
                                                    class="btn btn-info btn-sm">
                                                        <i class="bi bi-eye"></i> View Results
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="row">
                    <div class="col-md-12">
                        <div class="d-flex justify-content-between">
                            <div>
                                <a href="<?php echo $back_url; ?>" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-left"></i> Back to List
                                </a>
                            </div>
                            
                            <div>
                                <?php if ($can_edit): ?>
                                    <button class="btn btn-warning" onclick="editOrder()">
                                        <i class="bi bi-pencil"></i> Edit Order
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ($can_generate_report): ?>
                                    <a href="generate_report.php?order_id=<?php echo $order_id; ?>" 
                                       class="btn btn-success">
                                        <i class="bi bi-printer"></i> Generate Report
                                    </a>
                                <?php endif; ?>
                                
                                <?php if ($can_verify): ?>
                                    <a href="verify_results.php?order_id=<?php echo $order_id; ?>" 
                                       class="btn btn-primary">
                                        <i class="bi bi-check-circle"></i> Verify Results
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function editOrder() {
    if (confirm('Are you sure you want to edit this order? This action cannot be undone.')) {
        // Implement edit functionality here
        // You could redirect to an edit page or show a modal
        alert('Edit functionality to be implemented');
    }
}
</script>

<?php 
// Clear the referrer session variable
unset($_SESSION['order_list_referrer']);
require_once 'includes/footer.php'; 
?>