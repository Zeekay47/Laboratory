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
                
                // Check if this was the only test using this sample_id
                $db->query('SELECT COUNT(*) as remaining_tests FROM order_tests WHERE sample_id = 
                           (SELECT sample_id FROM order_tests WHERE order_id = :order_id AND test_id = :test_id)');
                $db->bind(':order_id', $order_id);
                $db->bind(':test_id', $test_id);
                $result = $db->single();
                
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
        ?>
        
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
                        <div class="d-flex justify-content-between align-items-center">
                            <h6>Tests (<?php echo count($tests); ?>)</h6>
                            <?php if ($can_edit): ?>
                                <small class="text-muted">Click <i class="bi bi-x-circle text-danger"></i> to remove tests</small>
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
                                        <th>Results</th>
                                        <th>Actions</th>
                                        <?php if ($can_edit): ?>
                                            <th style="width: 40px;">Remove</th>
                                        <?php endif; ?>
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
                                                <span class="badge bg-<?php echo $test['result_count'] > 0 ? 'success' : 'secondary'; ?>">
                                                    <?php echo $test['result_count']; ?> results
                                                </span>
                                            </td>
                                            
                                            <?php if ($index === 0): // Only show actions for first row ?>
                                                <td rowspan="<?php echo $sample_test_count; ?>">
                                                    <div class="btn-group btn-group-sm">
                                                        <?php if ($user_role == 'receptionist' && $first_test['status'] == 'pending'): ?>
                                                            <!-- Collect button for receptionist - for entire sample -->
                                                            <form method="POST" action="collect_sample.php" style="display: inline;">
                                                                <input type="hidden" name="sample_id" value="<?php echo $sample_id; ?>">
                                                                <input type="hidden" name="order_id" value="<?php echo $order_id; ?>">
                                                                <input type="hidden" name="all_test_ids" value="<?php 
                                                                    echo implode(',', array_column($sample_tests, 'id')); 
                                                                ?>">
                                                                <button type="submit" class="btn btn-success btn-sm"
                                                                        onclick="return confirm('Mark sample <?php echo $sample_id; ?> (containing <?php echo $sample_test_count; ?> tests) as collected?')">
                                                                    <i class="bi bi-droplet"></i> Collect Sample
                                                                </button>
                                                            </form>
                                                        <?php elseif ($user_role == 'technician' && 
                                                                    ($first_test['status'] == 'sample-collected' || 
                                                                    $first_test['status'] == 'processing')): ?>
                                                            <!-- Enter Results button for technician - one per test in sample -->
                                                            <?php if ($sample_test_count == 1): ?>
                                                                <!-- Single test in sample -->
                                                                <a href="enter_results.php?sample_id=<?php echo $sample_id; ?>&test_id=<?php echo $first_test['test_id']; ?>" 
                                                                class="btn btn-primary btn-sm">
                                                                    <i class="bi bi-pencil"></i> Enter Results
                                                                </a>
                                                            <?php else: ?>
                                                                <!-- Multiple tests in sample -->
                                                                <div class="dropdown">
                                                                    <button class="btn btn-primary btn-sm dropdown-toggle" 
                                                                            type="button" 
                                                                            data-bs-toggle="dropdown">
                                                                        <i class="bi bi-pencil"></i> Enter Results
                                                                    </button>
                                                                    <ul class="dropdown-menu">
                                                                        <?php foreach ($sample_tests as $st): ?>
                                                                            <li>
                                                                                <a class="dropdown-item" 
                                                                                   href="enter_results.php?sample_id=<?php echo $sample_id; ?>&test_id=<?php echo $st['test_id']; ?>">
                                                                                    <?php echo $st['test_name']; ?>
                                                                                </a>
                                                                            </li>
                                                                        <?php endforeach; ?>
                                                                    </ul>
                                                                </div>
                                                            <?php endif; ?>
                                                        <?php elseif ($user_role == 'manager' && $first_test['status'] == 'results-entered'): ?>
                                                            <!-- Verify button for manager -->
                                                            <?php if ($sample_test_count == 1): ?>
                                                                <a href="verify_results.php?sample_id=<?php echo $sample_id; ?>&test_id=<?php echo $first_test['test_id']; ?>" 
                                                                class="btn btn-success btn-sm">
                                                                    <i class="bi bi-check-circle"></i> Verify
                                                                </a>
                                                            <?php else: ?>
                                                                <div class="dropdown">
                                                                    <button class="btn btn-success btn-sm dropdown-toggle" 
                                                                            type="button" 
                                                                            data-bs-toggle="dropdown">
                                                                        <i class="bi bi-check-circle"></i> Verify
                                                                    </button>
                                                                    <ul class="dropdown-menu">
                                                                        <?php foreach ($sample_tests as $st): ?>
                                                                            <li>
                                                                                <a class="dropdown-item" 
                                                                                   href="verify_results.php?sample_id=<?php echo $sample_id; ?>&test_id=<?php echo $st['test_id']; ?>">
                                                                                    <?php echo $st['test_name']; ?>
                                                                                </a>
                                                                            </li>
                                                                        <?php endforeach; ?>
                                                                    </ul>
                                                                </div>
                                                            <?php endif; ?>
                                                        <?php elseif ($first_test['status'] == 'verified' || $first_test['status'] == 'completed'): ?>
                                                            <!-- View Results button -->
                                                            <?php if ($sample_test_count == 1): ?>
                                                                <a href="view_results.php?sample_id=<?php echo $sample_id; ?>&test_id=<?php echo $first_test['test_id']; ?>" 
                                                                class="btn btn-info btn-sm">
                                                                    <i class="bi bi-eye"></i> View Results
                                                                </a>
                                                            <?php else: ?>
                                                                <div class="dropdown">
                                                                    <button class="btn btn-info btn-sm dropdown-toggle" 
                                                                            type="button" 
                                                                            data-bs-toggle="dropdown">
                                                                        <i class="bi bi-eye"></i> View Results
                                                                    </button>
                                                                    <ul class="dropdown-menu">
                                                                        <?php foreach ($sample_tests as $st): ?>
                                                                            <li>
                                                                                <a class="dropdown-item" 
                                                                                   href="view_results.php?sample_id=<?php echo $sample_id; ?>&test_id=<?php echo $st['test_id']; ?>">
                                                                                    <?php echo $st['test_name']; ?>
                                                                                </a>
                                                                            </li>
                                                                        <?php endforeach; ?>
                                                                    </ul>
                                                                </div>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            <?php endif; ?>
                                            
                                            <?php if ($can_edit): ?>
                                                <td>
                                                    <?php if ($index === 0 && $sample_test_count == 1): ?>
                                                        <!-- Remove button for single test sample -->
                                                        <form method="POST" action="" style="display: inline;">
                                                            <input type="hidden" name="remove_test" value="1">
                                                            <input type="hidden" name="test_id" value="<?php echo $test['test_id']; ?>">
                                                            <button type="submit" class="btn btn-outline-danger btn-sm border-0"
                                                                    onclick="return confirm('Remove <?php echo htmlspecialchars($test['test_name']); ?> from order?')"
                                                                    title="Remove this test">
                                                                <i class="bi bi-x-circle"></i>
                                                            </button>
                                                        </form>
                                                    <?php elseif ($index === 0 && $sample_test_count > 1): ?>
                                                        <!-- Remove button for shared sample - shows which test is being removed -->
                                                        <div class="dropdown">
                                                            <button class="btn btn-outline-danger btn-sm border-0 dropdown-toggle" 
                                                                    type="button" 
                                                                    data-bs-toggle="dropdown"
                                                                    title="Remove a test from this sample">
                                                                <i class="bi bi-x-circle"></i>
                                                            </button>
                                                            <ul class="dropdown-menu">
                                                                <?php foreach ($sample_tests as $st): ?>
                                                                    <li>
                                                                        <form method="POST" action="" style="display: inline;">
                                                                            <input type="hidden" name="remove_test" value="1">
                                                                            <input type="hidden" name="test_id" value="<?php echo $st['test_id']; ?>">
                                                                            <button type="submit" class="dropdown-item text-danger" 
                                                                                    onclick="return confirm('Remove <?php echo htmlspecialchars($st['test_name']); ?> from order?')">
                                                                                Remove <?php echo $st['test_name']; ?>
                                                                            </button>
                                                                        </form>
                                                                    </li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endif; ?>
                                            
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

<?php 
// Clear the referrer session variable
unset($_SESSION['order_list_referrer']);
require_once 'includes/footer.php'; 
?>