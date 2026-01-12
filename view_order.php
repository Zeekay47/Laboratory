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

// Process test removal
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['remove_test']) && isset($_POST['test_id'])) {
    if ($_SESSION['role'] == 'receptionist') {
        $test_id = $_POST['test_id'];
        
        // Check if order is still pending
        $db->query('SELECT status FROM orders WHERE id = :id');
        $db->bind(':id', $order_id);
        $order_status = $db->single()['status'];
        
        if ($order_status == 'pending') {
            try {
                $db->query('BEGIN TRANSACTION');
                
                // Remove the test from order_tests
                $db->query('DELETE FROM order_tests WHERE order_id = :order_id AND test_id = :test_id');
                $db->bind(':order_id', $order_id);
                $db->bind(':test_id', $test_id);
                $db->execute();
                
                // Check how many tests remain in this order
                $db->query('SELECT COUNT(*) as test_count FROM order_tests WHERE order_id = :order_id');
                $db->bind(':order_id', $order_id);
                $remaining_tests = $db->single()['test_count'];
                
                // If no tests remain, delete the entire order
                if ($remaining_tests == 0) {
                    $db->query('DELETE FROM orders WHERE id = :id');
                    $db->bind(':id', $order_id);
                    $db->execute();
                    
                    $_SESSION['success'] = 'All tests removed. Order has been deleted.';
                    
                    $db->query('COMMIT');
                    
                    // Redirect to orders list
                    header('Location: orders.php');
                    exit();
                }
                
                $db->query('COMMIT');
                
                $_SESSION['success'] = 'Test removed successfully!';
                
                // Refresh the page to show updated list
                header('Location: view_order.php?id=' . $order_id);
                exit();
                
            } catch (Exception $e) {
                $db->query('ROLLBACK');
                $_SESSION['error'] = 'Error removing test: ' . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = 'Cannot modify order - status is no longer pending';
        }
    }
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

// If order doesn't exist (was deleted), redirect to orders list
if (!$order) {
    $_SESSION['info'] = 'Order not found or has been deleted.';
    header('Location: orders.php');
    exit();
}

// Fetch order tests with details - group by sample_id to identify shared samples
$db->query('SELECT ot.*, t.test_name, t.test_code, t.category, t.sample_type,
                   (SELECT COUNT(*) FROM test_results tr WHERE tr.order_test_id = ot.id) as result_count
            FROM order_tests ot 
            JOIN tests t ON ot.test_id = t.id 
            WHERE ot.order_id = :order_id 
            ORDER BY ot.sample_id, t.category, t.test_name');
$db->bind(':order_id', $order_id);
$tests = $db->resultSet();

// Group tests by sample_id to identify shared samples
$tests_by_sample = [];
foreach ($tests as $test) {
    $sample_id = $test['sample_id'];
    if (!isset($tests_by_sample[$sample_id])) {
        $tests_by_sample[$sample_id] = [];
    }
    $tests_by_sample[$sample_id][] = $test;
}

// Check if current user can access this order
$user_role = $_SESSION['role'];
$can_edit = ($user_role == 'receptionist' && $order['status'] == 'pending');
$can_verify = ($user_role == 'manager' && $order['status'] == 'processing');
$can_generate_report = ($user_role == 'receptionist' || $user_role == 'manager') && 
                       ($order['status'] == 'completed');

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
        <?php 
        // Display success/error messages
        if (isset($_SESSION['success'])) {
            echo '<div class="alert alert-success">' . $_SESSION['success'] . '</div>';
            unset($_SESSION['success']);
        }
        if (isset($_SESSION['error'])) {
            echo '<div class="alert alert-danger">' . $_SESSION['error'] . '</div>';
            unset($_SESSION['error']);
        }
        if (isset($_SESSION['info'])) {
            echo '<div class="alert alert-info">' . $_SESSION['info'] . '</div>';
            unset($_SESSION['info']);
        }
        ?>
        
        <div class="card">
            <div class="card-header bg-primary text-white">
                <div class="d-flex justify-content-between align-items-center">
                    <h5>Order: <?php echo $order['order_number']; ?></h5>
                    <div>
                        <span class="badge bg-light text-dark">Status: <?php echo $order['status']; ?></span>
                        <?php if ($can_edit): ?>
                            <span class="badge bg-warning ms-2"><i class="bi bi-pencil"></i> Editable</span>
                        <?php endif; ?>
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
                        <div class="d-flex justify-content-between align-items-center">
                            <h6>Tests (<?php echo count($tests); ?>)</h6>
                            <?php if ($can_edit): ?>
                                <small class="text-muted">Click buttons to collect samples or remove tests</small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php
                        // Group tests by category first
                        $tests_by_category = [];
                        foreach ($tests as $test) {
                            $tests_by_category[$test['category']][] = $test;
                        }
                        
                        foreach ($tests_by_category as $category => $category_tests):
                            // Now group category tests by sample_id
                            $category_tests_by_sample = [];
                            foreach ($category_tests as $test) {
                                $sample_id = $test['sample_id'];
                                if (!isset($category_tests_by_sample[$sample_id])) {
                                    $category_tests_by_sample[$sample_id] = [];
                                }
                                $category_tests_by_sample[$sample_id][] = $test;
                            }
                        ?>
                        <h6 class="mt-3"><?php echo $category; ?></h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th>Sample ID</th>
                                        <th>Sample Type</th>
                                        <th>Test Name</th>
                                        <th>Code</th>
                                        <th>Status</th>
                                        <th style="width: 150px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $row_counter = 0;
                                    foreach ($category_tests_by_sample as $sample_id => $sample_tests): 
                                        $first_test = $sample_tests[0];
                                        $sample_test_count = count($sample_tests);
                                    ?>
                                    <tr>
                                        <td rowspan="<?php echo $sample_test_count; ?>">
                                            <strong><?php echo $sample_id; ?></strong>
                                        </td>
                                        <td rowspan="<?php echo $sample_test_count; ?>">
                                            <?php echo $first_test['sample_type']; ?>
                                        </td>
                                        
                                        <?php foreach ($sample_tests as $index => $test): ?>
                                            <?php if ($index > 0): ?>
                                                <tr>
                                            <?php endif; ?>
                                            
                                            <td>
                                                <strong><?php echo $test['test_name']; ?></strong>
                                            </td>
                                            <td>
                                                <code><?php echo $test['test_code']; ?></code>
                                            </td>
                                            
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
                                                <div class="d-flex gap-1">
                                                    <?php if ($user_role == 'receptionist' && $first_test['status'] == 'pending'): ?>
                                                        <!-- For receptionist with pending status -->
                                                        <?php if ($index === 0): ?>
                                                            <!-- Collect button for entire sample - only in first row -->
                                                            <form method="POST" action="collect_sample.php" style="display: inline;">
                                                                <input type="hidden" name="sample_id" value="<?php echo $sample_id; ?>">
                                                                <input type="hidden" name="order_id" value="<?php echo $order_id; ?>">
                                                                <input type="hidden" name="all_test_ids" value="<?php 
                                                                    echo implode(',', array_column($sample_tests, 'id')); 
                                                                ?>">
                                                                <button type="submit" class="btn btn-success btn-sm"
                                                                        onclick="return confirm('Mark sample <?php echo $sample_id; ?> (containing <?php echo $sample_test_count; ?> tests) as collected?')"
                                                                        title="Collect this sample"
                                                                        style="min-width: 36px;">
                                                                    <i class="bi bi-droplet"></i>
                                                                </button>
                                                            </form>
                                                        <?php else: ?>
                                                            <!-- Empty space to align buttons -->
                                                            <div style="width: 36px;"></div>
                                                        <?php endif; ?>
                                                        
                                                        <!-- Remove button for each test -->
                                                        <form method="POST" action="" style="display: inline;">
                                                            <input type="hidden" name="remove_test" value="1">
                                                            <input type="hidden" name="test_id" value="<?php echo $test['test_id']; ?>">
                                                            <button type="submit" class="btn btn-outline-danger btn-sm"
                                                                    onclick="return confirm('Remove <?php echo htmlspecialchars($test['test_name']); ?> from order?\n\nIf this is the last test, the entire order will be deleted.')"
                                                                    title="Remove this test"
                                                                    style="min-width: 36px;">
                                                                <i class="bi bi-x"></i>
                                                            </button>
                                                        </form>
                                                        
                                                    <?php elseif ($user_role == 'technician' && 
                                                                ($first_test['status'] == 'sample-collected' || 
                                                                $first_test['status'] == 'processing')): ?>
                                                        <!-- Enter Results button for technician -->
                                                        <a href="enter_results.php?sample_id=<?php echo $sample_id; ?>&test_id=<?php echo $test['test_id']; ?>" 
                                                        class="btn btn-primary btn-sm"
                                                        title="Enter results for <?php echo $test['test_name']; ?>"
                                                        style="min-width: 36px;">
                                                            <i class="bi bi-pencil"></i>
                                                        </a>
                                                        
                                                    <?php elseif ($user_role == 'manager' && $first_test['status'] == 'results-entered'): ?>
                                                        <!-- Verify button for manager -->
                                                        <a href="verify_results.php?sample_id=<?php echo $sample_id; ?>&test_id=<?php echo $test['test_id']; ?>" 
                                                        class="btn btn-success btn-sm"
                                                        title="Verify results for <?php echo $test['test_name']; ?>"
                                                        style="min-width: 36px;">
                                                            <i class="bi bi-check-circle"></i>
                                                        </a>
                                                        
                                                    <?php elseif ($first_test['status'] == 'verified' || $first_test['status'] == 'completed'): ?>
                                                        <!-- View Results button -->
                                                        <a href="view_results.php?sample_id=<?php echo $sample_id; ?>&test_id=<?php echo $test['test_id']; ?>" 
                                                        class="btn btn-info btn-sm"
                                                        title="View results for <?php echo $test['test_name']; ?>"
                                                        style="min-width: 36px;">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                        
                                                    <?php else: ?>
                                                        <!-- For other states, show empty space for alignment -->
                                                        <div style="width: 72px;"></div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            
                                            <?php if ($index > 0): ?>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endforeach; ?>
                        
                        <?php if (empty($tests_by_category)): ?>
                            <div class="alert alert-info">No tests found for this order.</div>
                        <?php endif; ?>
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
                                <?php if ($can_edit && count($tests) > 0): ?>
                                    <!-- Add More Tests Button -->
                                    <a href="add_tests_to_order.php?order_id=<?php echo $order_id; ?>" 
                                       class="btn btn-primary">
                                        <i class="bi bi-plus-circle"></i> Add More Tests
                                    </a>
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
                                        <i class="bi bi-check-circle"></i> Verify All Results
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

<style>
/* Custom styles for better button alignment */
.btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
    line-height: 1.5;
}

.table td {
    vertical-align: middle;
}

.d-flex.gap-1 > * {
    margin-right: 0.25rem;
}

.d-flex.gap-1 > *:last-child {
    margin-right: 0;
}

/* Tooltip styles */
[title] {
    position: relative;
}

[title]:hover:after {
    content: attr(title);
    padding: 4px 8px;
    color: #fff;
    background-color: rgba(0,0,0,0.8);
    border-radius: 4px;
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    white-space: nowrap;
    font-size: 12px;
    z-index: 1000;
    margin-bottom: 5px;
}
</style>

<?php 
// Clear the referrer session variable
unset($_SESSION['order_list_referrer']);
require_once 'includes/footer.php'; 
?>