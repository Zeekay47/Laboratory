<?php
$page_title = 'Verify Results';
require_once 'includes/header.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';

$auth = new Auth();
$auth->requireRole('manager');

$db = new Database();

// Handle verification
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['verify'])) {
    $sample_id = $_POST['sample_id'];
    $action = $_POST['verify'];
    $notes = $_POST['verification_notes'];
    
    try {
        $db->query('BEGIN TRANSACTION');
        
        if ($action == 'approve') {
            // Update order test status
            $db->query('UPDATE order_tests SET status = "verified" WHERE sample_id = :sample_id');
            $db->bind(':sample_id', $sample_id);
            $db->execute();
            
            // Update test results verification
            $db->query('UPDATE test_results tr 
                       JOIN order_tests ot ON tr.order_test_id = ot.id
                       SET tr.verified_by = :user_id, tr.verified_at = NOW()
                       WHERE ot.sample_id = :sample_id');
            $db->bind(':user_id', $_SESSION['user_id']);
            $db->bind(':sample_id', $sample_id);
            $db->execute();
            
            // Check if all tests in order are verified
            $db->query('SELECT order_id FROM order_tests WHERE sample_id = :sample_id');
            $db->bind(':sample_id', $sample_id);
            $order_test = $db->single();
            
            $db->query('SELECT COUNT(*) as total, 
                               SUM(CASE WHEN status = "verified" THEN 1 ELSE 0 END) as verified
                        FROM order_tests 
                        WHERE order_id = :order_id');
            $db->bind(':order_id', $order_test['order_id']);
            $status = $db->single();
            
            if ($status['total'] == $status['verified']) {
                // Update order as completed
                $db->query('UPDATE orders SET status = "completed", result_ready_date = NOW() 
                           WHERE id = :id');
                $db->bind(':id', $order_test['order_id']);
                $db->execute();
                
                // AUTO-GENERATE REPORT HERE
                require_once 'includes/AutoReportGenerator.php';
                $generator = new AutoReportGenerator($db);
                $report_result = $generator->checkAndGenerate($order_test['order_id'], $_SESSION['user_id']);
                
                if ($report_result['success'] && !isset($report_result['exists'])) {
                    $_SESSION['report_generated'] = 'Report #' . $report_result['report_number'] . ' generated automatically!';
                }
            }
            
            $message = '<div class="alert alert-success">Results approved successfully!</div>';
            
        } elseif ($action == 'reject') {
            // Reject and send back to technician
            $db->query('UPDATE order_tests SET status = "processing", notes = CONCAT(notes, "\n", :notes) 
                       WHERE sample_id = :sample_id');
            $db->bind(':notes', "Rejected by manager: " . $notes);
            $db->bind(':sample_id', $sample_id);
            $db->execute();
            
            $message = '<div class="alert alert-warning">Results rejected and sent back to technician.</div>';
        }
        
        $db->query('COMMIT');
        
        // Show success message
        if (isset($_SESSION['report_generated'])) {
            $message = '<div class="alert alert-success">Results approved successfully! ' . 
                      $_SESSION['report_generated'] . '</div>';
            unset($_SESSION['report_generated']);
        }
        
        // REDIRECT AFTER SUCCESSFUL VERIFICATION
        header("Location: verify_results.php");
        exit();
        
    } catch (Exception $e) {
        $db->query('ROLLBACK');
        $message = '<div class="alert alert-danger">Error: ' . $e->getMessage() . '</div>';
    }
}

// Show success message from session if exists
if (isset($_SESSION['report_generated'])) {
    $message = '<div class="alert alert-success">' . $_SESSION['report_generated'] . '</div>';
    unset($_SESSION['report_generated']);
}

// Handle sample selection
$sample_id = $_GET['sample_id'] ?? '';
$order_test = null;
$test_results = [];
$order_id = 0; // Store order ID for back button

if ($sample_id) {
    // Get order test details - include order_id in the query
    $db->query('SELECT ot.*, t.test_name, t.test_code, p.full_name, p.age, p.gender, o.id as order_id
                FROM order_tests ot
                JOIN tests t ON ot.test_id = t.id
                JOIN orders o ON ot.order_id = o.id
                JOIN patients p ON o.patient_id = p.id
                WHERE ot.sample_id = :sample_id');
    $db->bind(':sample_id', $sample_id);
    $order_test = $db->single();
    
    if ($order_test) {
        $order_id = $order_test['order_id']; // Store the order ID
        
        // Get test results
        $db->query('SELECT tr.*, tp.parameter_name, tp.parameter_code, tp.unit
                   FROM test_results tr
                   JOIN test_parameters tp ON tr.parameter_id = tp.id
                   WHERE tr.order_test_id = :order_test_id
                   ORDER BY tp.sort_order');
        $db->bind(':order_test_id', $order_test['id']);
        $test_results = $db->resultSet();
    }
}

// Determine back URL
$back_url = 'verify_results.php'; // Default to verification list

// If we have an order ID, go back to the order view
if ($order_id > 0) {
    $back_url = "view_order.php?id=" . $order_id;
} else {
    // Otherwise stay on verification list
    $back_url = "verify_results.php";
}

?>

<div class="row">
    <div class="col-md-12">
        <?php echo $message ?? ''; ?>
        
        <?php if (!$sample_id || !$order_test): ?>
            <!-- Sample Selection -->
            <div class="card">
                <div class="card-header bg-warning">
                    <h5>Select Sample for Verification</h5>
                </div>
                <div class="card-body">
                    <form method="GET" action="" class="mb-4">
                        <div class="input-group">
                            <input type="text" class="form-control" name="sample_id" 
                                   placeholder="Enter Sample ID" required>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search"></i> Load Results
                            </button>
                        </div>
                    </form>
                    
                    <h6>Results Awaiting Verification</h6>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Sample ID</th>
                                    <th>Test</th>
                                    <th>Patient</th>
                                    <th>Entered By</th>
                                    <th>Entered At</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $db->query('SELECT ot.sample_id, t.test_name, p.full_name, 
                                                   s.full_name as technician, tr.entered_at
                                           FROM order_tests ot
                                           JOIN tests t ON ot.test_id = t.id
                                           JOIN orders o ON ot.order_id = o.id
                                           JOIN patients p ON o.patient_id = p.id
                                           JOIN test_results tr ON ot.id = tr.order_test_id
                                           JOIN staff s ON tr.entered_by = s.id
                                           WHERE ot.status = "results-entered"
                                           GROUP BY ot.id
                                           ORDER BY tr.entered_at DESC LIMIT 20');
                                $pending = $db->resultSet();
                                
                                if (empty($pending)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center">No results awaiting verification</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($pending as $item): ?>
                                    <tr>
                                        <td><code><?php echo $item['sample_id']; ?></code></td>
                                        <td><?php echo $item['test_name']; ?></td>
                                        <td><?php echo $item['full_name']; ?></td>
                                        <td><?php echo $item['technician']; ?></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($item['entered_at'])); ?></td>
                                        <td>
                                            <a href="verify_results.php?sample_id=<?php echo $item['sample_id']; ?>" 
                                               class="btn btn-sm btn-primary">
                                                <i class="bi bi-eye"></i> Review
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
            <!-- Results Verification Form -->
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5>Verify Results for Sample: <?php echo $sample_id; ?></h5>
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
                                    <p><strong>Status:</strong> <span class="badge bg-primary">Results Entered</span></p>
                                    <p><strong>Technician:</strong> 
                                        <?php
                                        $db->query('SELECT s.full_name FROM test_results tr 
                                                   JOIN staff s ON tr.entered_by = s.id 
                                                   WHERE tr.order_test_id = :id LIMIT 1');
                                        $db->bind(':id', $order_test['id']);
                                        $tech = $db->single();
                                        echo $tech['full_name'] ?? 'Unknown';
                                        ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Results Table -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h6>Test Results</h6>
                        </div>
                        <div class="card-body">
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
                                        <?php foreach ($test_results as $result): ?>
                                        <tr class="<?php echo $result['flag'] != 'Normal' ? 'table-warning' : ''; ?>">
                                            <td>
                                                <strong><?php echo $result['parameter_name']; ?></strong><br>
                                                <small class="text-muted"><?php echo $result['parameter_code']; ?></small>
                                            </td>
                                            <td><?php echo $result['result_value']; ?></td>
                                            <td><?php echo $result['result_unit']; ?></td>
                                            <td><?php echo $result['reference_range']; ?></td>
                                            <td>
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
                                            </td>
                                            <td><?php echo $result['notes']; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Verification Form -->
                    <form method="POST" action="">
                        <input type="hidden" name="sample_id" value="<?php echo $sample_id; ?>">
                        
                        <div class="card">
                            <div class="card-header">
                                <h6>Verification</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="verification_notes" class="form-label">Verification Notes</label>
                                    <textarea class="form-control" id="verification_notes" name="verification_notes" 
                                              rows="3" placeholder="Add any verification notes or comments..."></textarea>
                                </div>
                                
                                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                    <button type="submit" name="verify" value="reject" 
                                            class="btn btn-danger me-md-2"
                                            onclick="return confirm('Reject these results and send back to technician?')">
                                        <i class="bi bi-x-circle"></i> Reject & Return
                                    </button>
                                    <button type="submit" name="verify" value="approve" 
                                            class="btn btn-success"
                                            onclick="return confirm('Approve these results? This will mark the test as verified.')">
                                        <i class="bi bi-check-circle"></i> Approve Results
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                    
                    <div class="mt-3">
                        <a href="<?php echo $back_url; ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> <?php echo ($order_id > 0) ? 'Back to Order' : 'Back to List'; ?>
                        </a>
                        
                        <?php if ($order_id > 0): ?>
                            <a href="view_results.php?sample_id=<?php echo $sample_id; ?>" class="btn btn-info ms-2">
                                <i class="bi bi-eye"></i> View Results
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>