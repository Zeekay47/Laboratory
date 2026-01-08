<?php
$page_title = 'View Results';
require_once 'includes/header.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';

$auth = new Auth();
$auth->requireAuth(); // Changed from requireRole('manager') to allow all authenticated users

$db = new Database();
$message = '';

// Handle sample selection
$sample_id = $_GET['sample_id'] ?? '';
$order_test = null;
$test_results = [];
$order_id = 0; // Store order ID for back button

if ($sample_id) {
    // Get order test details
    $db->query('SELECT ot.*, t.test_name, t.test_code, p.full_name, p.age, p.gender, o.id as order_id
                FROM order_tests ot
                JOIN tests t ON ot.test_id = t.id
                JOIN orders o ON ot.order_id = o.id
                JOIN patients p ON o.patient_id = p.id
                WHERE ot.sample_id = :sample_id');
    $db->bind(':sample_id', $sample_id);
    $order_test = $db->single();
    
    if ($order_test) {
        $order_id = $order_test['order_id']; // Get the order ID
        
        // Get test results
        $db->query('SELECT tr.*, tp.parameter_name, tp.parameter_code, tp.unit, tp.parameter_options
                   FROM test_results tr
                   JOIN test_parameters tp ON tr.parameter_id = tp.id
                   WHERE tr.order_test_id = :order_test_id
                   ORDER BY tp.sort_order');
        $db->bind(':order_test_id', $order_test['id']);
        $test_results = $db->resultSet();
        
        // Get verification info if test is verified
        if ($order_test['status'] == 'verified') {
            $db->query('SELECT s.full_name as verifier_name, tr.verified_at 
                       FROM test_results tr
                       JOIN staff s ON tr.verified_by = s.id
                       WHERE tr.order_test_id = :order_test_id
                       LIMIT 1');
            $db->bind(':order_test_id', $order_test['id']);
            $verification_info = $db->single();
        }
    }
}

// Determine back URL
$back_url = 'index.php'; // Default fallback
if ($order_id > 0) {
    // If we have an order ID, go back to the order view
    $back_url = "view_order.php?id=" . $order_id;
} else {
    // Fallback based on user role
    $user_role = $_SESSION['role'] ?? 'receptionist';
    $back_url = ($user_role == 'manager') ? 'all_orders.php' : 'orders.php';
}
?>

<div class="row">
    <div class="col-md-12">
        <?php echo $message; ?>
        
        <?php if (!$sample_id || !$order_test): ?>
            <!-- Sample Selection -->
            <div class="card">
                <div class="card-header bg-primary">
                    <h5>View Test Results</h5>
                </div>
                <div class="card-body">
                    <form method="GET" action="" class="mb-4">
                        <div class="input-group">
                            <input type="text" class="form-control" name="sample_id" 
                                   placeholder="Enter Sample ID" required>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search"></i> View Results
                            </button>
                        </div>
                    </form>
                    
                    <h6>Recent Tests</h6>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Sample ID</th>
                                    <th>Test</th>
                                    <th>Patient</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // Fixed query - removed ot.updated_at since column doesn't exist
                                $db->query('SELECT ot.sample_id, t.test_name, p.full_name, ot.status, ot.order_id
                                           FROM order_tests ot
                                           JOIN tests t ON ot.test_id = t.id
                                           JOIN orders o ON ot.order_id = o.id
                                           JOIN patients p ON o.patient_id = p.id
                                           WHERE ot.status IN ("verified", "results-entered", "processing")
                                           ORDER BY o.order_date DESC LIMIT 20');
                                $tests = $db->resultSet();
                                
                                if (empty($tests)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center">No tests found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($tests as $item): 
                                        $status_badge = '';
                                        switch($item['status']) {
                                            case 'verified': $status_badge = 'success'; break;
                                            case 'results-entered': $status_badge = 'warning'; break;
                                            case 'processing': $status_badge = 'info'; break;
                                            default: $status_badge = 'secondary';
                                        }
                                    ?>
                                    <tr>
                                        <td><code><?php echo $item['sample_id']; ?></code></td>
                                        <td><?php echo $item['test_name']; ?></td>
                                        <td><?php echo $item['full_name']; ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $status_badge; ?>">
                                                <?php echo ucfirst(str_replace('-', ' ', $item['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="view_results.php?sample_id=<?php echo $item['sample_id']; ?>" 
                                               class="btn btn-sm btn-primary">
                                                <i class="bi bi-eye"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Results View -->
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5>
                        <?php if ($order_test['status'] == 'verified'): ?>
                            <i class="bi bi-check-circle-fill"></i> 
                        <?php endif; ?>
                        Test Results for Sample: <?php echo $sample_id; ?>
                    </h5>
                </div>
                <div class="card-body">
                    <!-- Patient and Test Info -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-body">
                                    <h6>Patient Information</h6>
                                    <p><strong>Name:</strong> <?php echo $order_test['full_name']; ?></p>
                                    <p><strong>Age/Gender:</strong> <?php echo $order_test['age']; ?> yrs / <?php echo $order_test['gender']; ?></p>
                                    <p><strong>Sample ID:</strong> <?php echo $sample_id; ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-body">
                                    <h6>Test Information</h6>
                                    <p><strong>Test:</strong> <?php echo $order_test['test_name']; ?> (<?php echo $order_test['test_code']; ?>)</p>
                                    <p><strong>Status:</strong> 
                                        <span class="badge bg-<?php 
                                            switch($order_test['status']) {
                                                case 'verified': echo 'success'; break;
                                                case 'results-entered': echo 'warning'; break;
                                                case 'processing': echo 'info'; break;
                                                default: echo 'secondary';
                                            }
                                        ?>">
                                            <?php echo ucfirst(str_replace('-', ' ', $order_test['status'])); ?>
                                        </span>
                                    </p>
                                    <p><strong>Technician:</strong> 
                                        <?php
                                        $db->query('SELECT s.full_name FROM test_results tr 
                                                   JOIN staff s ON tr.entered_by = s.id 
                                                   WHERE tr.order_test_id = :id LIMIT 1');
                                        $db->bind(':id', $order_test['id']);
                                        $tech = $db->single();
                                        echo $tech['full_name'] ?? 'Not entered yet';
                                        ?>
                                    </p>
                                    
                                    <?php if ($order_test['status'] == 'verified' && isset($verification_info)): ?>
                                    <p><strong>Verified By:</strong> <?php echo $verification_info['verifier_name'] ?? 'Unknown'; ?></p>
                                    <p><strong>Verified At:</strong> <?php echo date('d/m/Y H:i', strtotime($verification_info['verified_at'] ?? '')); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Results Table -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h6>
                                Test Results 
                                <?php if ($order_test['status'] == 'verified'): ?>
                                    <span class="badge bg-success ms-2"><i class="bi bi-check-circle"></i> Verified</span>
                                <?php endif; ?>
                            </h6>
                        </div>
                        <div class="card-body">
                            <?php if (empty($test_results)): ?>
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle"></i> No results have been entered yet for this test.
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Parameter</th>
                                                <th>Result</th>
                                                <th>Unit</th>
                                                <th>Reference Range</th>
                                                <th>Flag</th>
                                                <th>Notes</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($test_results as $result): 
                                                // Check if this is a qualitative parameter
                                                $is_qualitative = !empty($result['parameter_options']);
                                            ?>
                                            <tr class="<?php echo (!$is_qualitative && $result['flag'] != 'Normal') ? 'table-warning' : ''; ?>">
                                                <td>
                                                    <strong><?php echo $result['parameter_name']; ?></strong><br>
                                                    <small class="text-muted"><?php echo $result['parameter_code']; ?></small>
                                                    <?php if ($is_qualitative): ?>
                                                        <br><small class="text-info"><i>Qualitative</i></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo $result['result_value']; ?></td>
                                                <td><?php echo $result['result_unit']; ?></td>
                                                <td><?php echo $result['reference_range']; ?></td>
                                                <td>
                                                    <?php if ($is_qualitative): ?>
                                                        <span class="badge bg-secondary">N/A</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-<?php 
                                                            switch($result['flag']) {
                                                                case 'Normal': echo 'success'; break;
                                                                case 'Low': echo 'warning'; break;
                                                                case 'High': echo 'danger'; break;
                                                                default: echo 'secondary';
                                                            }
                                                        ?>">
                                                            <?php echo $result['flag']; ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo $result['notes']; ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Show verification form only if results are entered but not verified -->
                    <?php 
                    $user_role = $_SESSION['role'] ?? 'receptionist';
                    if ($order_test['status'] == 'results-entered' && $user_role == 'manager'): ?>
                        <!-- Link to verify_results.php for verification -->
                        <div class="card mb-4">
                            <div class="card-header bg-warning">
                                <h6>Verification Required</h6>
                            </div>
                            <div class="card-body">
                                <p>This test has results entered but requires verification.</p>
                                <a href="verify_results.php?sample_id=<?php echo $sample_id; ?>" 
                                   class="btn btn-primary">
                                    <i class="bi bi-check-circle"></i> Go to Verification Page
                                </a>
                            </div>
                        </div>
                    <?php elseif ($order_test['status'] == 'processing'): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-hourglass-split"></i> This test is currently being processed by a technician.
                        </div>
                    <?php endif; ?>
                    
                    <div class="mt-3">
                        <a href="<?php echo $back_url; ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Back to Order
                        </a>
                        
                        <?php if ($order_test['status'] == 'verified'): ?>
                            <a href="generate_report.php?order_id=<?php echo $order_id; ?>" 
                               class="btn btn-success ms-2">
                                <i class="bi bi-printer"></i> Generate Full Report
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>