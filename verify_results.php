<?php
$page_title = 'Verify Results';
require_once 'includes/header.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';

$auth = new Auth();
$auth->requireRole('manager');

$db = new Database();
$message = '';

// Handle verification
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['verify'])) {
    $sample_id = $_POST['sample_id'];
    $test_id = $_POST['test_id'] ?? 0;
    $action = $_POST['verify'];
    $notes = $_POST['verification_notes'] ?? '';
    $verification_scope = $_POST['verification_scope'] ?? 'single';
    
    try {
        $db->query('BEGIN TRANSACTION');
        
        if ($action == 'approve') {
            if ($verification_scope == 'single') {
                // Approve single test
                $db->query('UPDATE order_tests SET status = "verified" WHERE sample_id = :sample_id AND test_id = :test_id');
                $db->bind(':sample_id', $sample_id);
                $db->bind(':test_id', $test_id);
                $db->execute();
                
                // Update test results verification
                $db->query('UPDATE test_results tr 
                           JOIN order_tests ot ON tr.order_test_id = ot.id
                           SET tr.verified_by = :user_id, tr.verified_at = NOW()
                           WHERE ot.sample_id = :sample_id AND ot.test_id = :test_id');
                $db->bind(':user_id', $_SESSION['user_id']);
                $db->bind(':sample_id', $sample_id);
                $db->bind(':test_id', $test_id);
                $db->execute();
                
                // Get test name for message
                $db->query('SELECT test_name FROM tests WHERE id = :test_id');
                $db->bind(':test_id', $test_id);
                $test = $db->single();
                $test_name = $test['test_name'] ?? 'Test';
                $_SESSION['success'] = "Test '{$test_name}' approved successfully!";
                
            } else {
                // Approve all pending tests in sample
                $db->query('UPDATE order_tests SET status = "verified" 
                           WHERE sample_id = :sample_id AND status = "results-entered"');
                $db->bind(':sample_id', $sample_id);
                $db->execute();
                
                // Update all test results verification
                $db->query('UPDATE test_results tr 
                           JOIN order_tests ot ON tr.order_test_id = ot.id
                           SET tr.verified_by = :user_id, tr.verified_at = NOW()
                           WHERE ot.sample_id = :sample_id AND ot.status = "verified"');
                $db->bind(':user_id', $_SESSION['user_id']);
                $db->bind(':sample_id', $sample_id);
                $db->execute();
                
                // Count how many tests were approved
                $db->query('SELECT COUNT(*) as count FROM order_tests 
                           WHERE sample_id = :sample_id AND status = "verified"');
                $db->bind(':sample_id', $sample_id);
                $count_result = $db->single();
                $_SESSION['success'] = "All pending tests ({$count_result['count']}) approved successfully!";
            }
            
            // Check if all tests in order are verified
            $db->query('SELECT order_id FROM order_tests WHERE sample_id = :sample_id LIMIT 1');
            $db->bind(':sample_id', $sample_id);
            $order_test = $db->single();
            
            if ($order_test) {
                $db->query('SELECT COUNT(*) as total, 
                                   SUM(CASE WHEN status = "verified" THEN 1 ELSE 0 END) as verified
                            FROM order_tests 
                            WHERE order_id = :order_id');
                $db->bind(':order_id', $order_test['order_id']);
                $status = $db->single();
                
                if ($status['total'] == $status['verified']) {
                    // All tests verified, mark order as completed
                    $db->query('UPDATE orders SET status = "completed", result_ready_date = NOW() 
                               WHERE id = :id');
                    $db->bind(':id', $order_test['order_id']);
                    $db->execute();
                    
                    // Auto-generate report
                    require_once 'includes/AutoReportGenerator.php';
                    $generator = new AutoReportGenerator($db);
                    $report_result = $generator->checkAndGenerate($order_test['order_id'], $_SESSION['user_id']);
                    
                    if ($report_result['success'] && !isset($report_result['exists'])) {
                        $_SESSION['report_generated'] = 'Report #' . $report_result['report_number'] . ' generated automatically!';
                    }
                }
            }
            
        } elseif ($action == 'reject') {
            if ($verification_scope == 'single') {
                // Reject single test
                $db->query('UPDATE order_tests SET status = "processing", 
                           notes = CONCAT(COALESCE(notes, ""), "\n", :notes) 
                           WHERE sample_id = :sample_id AND test_id = :test_id');
                $db->bind(':notes', "Rejected by manager: " . $notes);
                $db->bind(':sample_id', $sample_id);
                $db->bind(':test_id', $test_id);
                $db->execute();
                
                // Get test name for message
                $db->query('SELECT test_name FROM tests WHERE id = :test_id');
                $db->bind(':test_id', $test_id);
                $test = $db->single();
                $test_name = $test['test_name'] ?? 'Test';
                $_SESSION['success'] = "Test '{$test_name}' rejected successfully!";
                
            } else {
                // Reject all pending tests
                $db->query('UPDATE order_tests SET status = "processing", 
                           notes = CONCAT(COALESCE(notes, ""), "\n", :notes) 
                           WHERE sample_id = :sample_id AND status = "results-entered"');
                $db->bind(':notes', "Rejected by manager: " . $notes);
                $db->bind(':sample_id', $sample_id);
                $db->execute();
                
                // Count how many tests were rejected
                $db->query('SELECT COUNT(*) as count FROM order_tests 
                           WHERE sample_id = :sample_id AND status = "processing" AND notes LIKE "%Rejected by manager%"');
                $db->bind(':sample_id', $sample_id);
                $count_result = $db->single();
                $_SESSION['success'] = "All pending tests ({$count_result['count']}) rejected successfully!";
            }
        }
        
        $db->query('COMMIT');
        
        // Add report generation message if exists
        if (isset($_SESSION['report_generated'])) {
            $_SESSION['success'] .= ' ' . $_SESSION['report_generated'];
            unset($_SESSION['report_generated']);
        }
        
        header("Location: verify_results.php");
        exit();
        
    } catch (Exception $e) {
        $db->query('ROLLBACK');
        $message = '<div class="alert alert-danger">Error: ' . $e->getMessage() . '</div>';
    }
}

// Show success message from session
if (isset($_SESSION['success'])) {
    $message = '<div class="alert alert-success">' . $_SESSION['success'] . '</div>';
    unset($_SESSION['success']);
}

// Get sample ID from URL
$sample_id = $_GET['sample_id'] ?? '';
$test_id = $_GET['test_id'] ?? 0;

// Variables for test data
$current_test = null;
$test_results = [];
$tests_in_sample = [];
$order_info = null;
$patient_info = null;
$tests_needing_verification = [];

if ($sample_id) {
    // Get all tests for this sample
    $db->query('SELECT ot.*, t.test_name, t.test_code, t.id as test_id
                FROM order_tests ot
                JOIN tests t ON ot.test_id = t.id
                WHERE ot.sample_id = :sample_id
                ORDER BY t.test_name');
    $db->bind(':sample_id', $sample_id);
    $tests_in_sample = $db->resultSet();
    
    if (!empty($tests_in_sample)) {
        // Get patient and order info
        $first_test = $tests_in_sample[0];
        $db->query('SELECT o.*, p.full_name, p.age, p.gender, p.phone
                    FROM orders o
                    JOIN patients p ON o.patient_id = p.id
                    WHERE o.id = :order_id');
        $db->bind(':order_id', $first_test['order_id']);
        $order_info = $db->single();
        $patient_info = $order_info;
        
        // Get tests needing verification
        foreach ($tests_in_sample as $test) {
            if ($test['status'] == 'results-entered') {
                $tests_needing_verification[] = $test;
            }
        }
        
        // Get specific test if test_id provided
        if ($test_id > 0) {
            foreach ($tests_in_sample as $test) {
                if ($test['test_id'] == $test_id) {
                    $current_test = $test;
                    break;
                }
            }
        } else {
            // Default to first test needing verification
            if (!empty($tests_needing_verification)) {
                $current_test = $tests_needing_verification[0];
            } else {
                $current_test = $tests_in_sample[0];
            }
        }
        
        if ($current_test) {
            // Get test results
            $db->query('SELECT tr.*, tp.parameter_name, tp.parameter_code, tp.unit
                       FROM test_results tr
                       JOIN test_parameters tp ON tr.parameter_id = tp.id
                       WHERE tr.order_test_id = :order_test_id
                       ORDER BY tp.sort_order');
            $db->bind(':order_test_id', $current_test['id']);
            $test_results = $db->resultSet();
        }
    }
}
?>

<div class="row">
    <div class="col-md-12">
        <?php echo $message; ?>
        
        <?php if (!$sample_id): ?>
            <!-- Sample Selection Page -->
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Select Sample for Verification</h5>
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
                    
                    <h6 class="mb-3">Results Awaiting Verification</h6>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Sample ID</th>
                                    <th>Patient</th>
                                    <th>Tests</th>
                                    <th>Entered At</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // Get samples with pending verification
                                $db->query('SELECT DISTINCT ot.sample_id, p.full_name,
                                           COUNT(DISTINCT ot.test_id) as test_count,
                                           MAX(tr.entered_at) as entered_at
                                           FROM order_tests ot
                                           JOIN orders o ON ot.order_id = o.id
                                           JOIN patients p ON o.patient_id = p.id
                                           LEFT JOIN test_results tr ON ot.id = tr.order_test_id
                                           WHERE ot.status = "results-entered"
                                           GROUP BY ot.sample_id, p.full_name
                                           ORDER BY entered_at DESC
                                           LIMIT 20');
                                $samples = $db->resultSet();
                                
                                if (empty($samples)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">
                                            <i class="bi bi-check-circle display-6"></i>
                                            <p class="mt-3">No results awaiting verification</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($samples as $sample): ?>
                                    <tr>
                                        <td><code><?php echo $sample['sample_id']; ?></code></td>
                                        <td><?php echo $sample['full_name']; ?></td>
                                        <td>
                                            <span class="badge bg-info"><?php echo $sample['test_count']; ?> test(s)</span>
                                        </td>
                                        <td><?php echo date('d M Y, H:i', strtotime($sample['entered_at'])); ?></td>
                                        <td>
                                            <a href="verify_results.php?sample_id=<?php echo $sample['sample_id']; ?>" 
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
            <!-- Verification Page -->
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Verify Results - Sample: <?php echo $sample_id; ?></h5>
                        <div>
                            <?php if (count($tests_in_sample) > 1): ?>
                                <div class="dropdown d-inline-block me-2">
                                    <button class="btn btn-light btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                        <i class="bi bi-list"></i> Switch Test
                                    </button>
                                    <ul class="dropdown-menu">
                                        <?php foreach ($tests_in_sample as $test): ?>
                                            <li>
                                                <a class="dropdown-item <?php echo $test['test_id'] == $current_test['test_id'] ? 'active' : ''; ?>" 
                                                   href="verify_results.php?sample_id=<?php echo $sample_id; ?>&test_id=<?php echo $test['test_id']; ?>">
                                                    <?php echo $test['test_name']; ?>
                                                    <?php if ($test['test_id'] == $current_test['test_id']): ?>
                                                        <i class="bi bi-check ms-2"></i>
                                                    <?php endif; ?>
                                                    <?php if ($test['status'] == 'verified'): ?>
                                                        <span class="badge bg-success float-end">Verified</span>
                                                    <?php elseif ($test['status'] == 'results-entered'): ?>
                                                        <span class="badge bg-warning float-end">Pending</span>
                                                    <?php endif; ?>
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                            <a href="verify_results.php" class="btn btn-light btn-sm">
                                <i class="bi bi-arrow-left"></i> Back
                            </a>
                        </div>
                    </div>
                </div>
                
                <div class="card-body">
                    <!-- Patient and Sample Info -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h6 class="card-title">Patient Information</h6>
                                    <p class="mb-1"><strong>Name:</strong> <?php echo $patient_info['full_name']; ?></p>
                                    <p class="mb-1"><strong>Phone:</strong> <?php echo $patient_info['phone']; ?></p>
                                    <p class="mb-1"><strong>Age/Gender:</strong> <?php echo $patient_info['age']; ?> yrs / <?php echo $patient_info['gender']; ?></p>
                                    <p class="mb-0"><strong>Sample ID:</strong> <code><?php echo $sample_id; ?></code></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h6 class="card-title">Sample Tests</h6>
                                    <div class="d-flex flex-wrap gap-2 mb-3">
                                        <?php 
                                        $verified_count = 0;
                                        $pending_count = 0;
                                        
                                        foreach ($tests_in_sample as $test): 
                                            $status_class = 'bg-secondary';
                                            if ($test['status'] == 'verified') {
                                                $status_class = 'bg-success';
                                                $verified_count++;
                                            } elseif ($test['status'] == 'results-entered') {
                                                $status_class = 'bg-warning';
                                                $pending_count++;
                                            }
                                        ?>
                                            <span class="badge <?php echo $status_class; ?> p-2">
                                                <?php echo $test['test_name']; ?>
                                                <?php if ($test['status'] == 'verified'): ?>
                                                    <i class="bi bi-check-circle ms-1"></i>
                                                <?php endif; ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="row">
                                        <div class="col">
                                            <span class="badge bg-success"><?php echo $verified_count; ?> verified</span>
                                        </div>
                                        <div class="col">
                                            <span class="badge bg-warning"><?php echo $pending_count; ?> pending</span>
                                        </div>
                                        <div class="col">
                                            <span class="badge bg-info"><?php echo count($tests_in_sample); ?> total</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($current_test): ?>
                        <!-- Test Information -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0">
                                        <?php echo $current_test['test_name']; ?>
                                        <?php if (!empty($current_test['test_code'])): ?>
                                            <small class="text-muted">(<?php echo $current_test['test_code']; ?>)</small>
                                        <?php endif; ?>
                                    </h6>
                                    <div>
                                        <?php if ($current_test['status'] == 'verified'): ?>
                                            <span class="badge bg-success">
                                                <i class="bi bi-check-circle"></i> Verified
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-primary">
                                                <i class="bi bi-clock"></i> Results Entered
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($current_test['status'] != 'verified'): ?>
                                <!-- Results Table -->
                                <div class="card-body">
                                    <?php if (empty($test_results)): ?>
                                        <div class="alert alert-warning">
                                            <i class="bi bi-exclamation-triangle"></i>
                                            No results found for this test.
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-bordered">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th width="30%">Parameter</th>
                                                        <th width="20%">Result</th>
                                                        <th width="25%">Reference Range</th>
                                                        <th width="10%">Flag</th>
                                                        <th width="15%">Notes</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($test_results as $result): 
                                                        // Format reference range to show unit only once
                                                        $reference_range = $result['reference_range'];
                                                        if (!empty($result['result_unit']) && strpos($reference_range, $result['result_unit']) === false) {
                                                            $reference_range .= ' ' . $result['result_unit'];
                                                        }
                                                    ?>
                                                    <tr class="<?php echo $result['flag'] != 'Normal' ? 'table-warning' : ''; ?>">
                                                        <td>
                                                            <strong><?php echo $result['parameter_name']; ?></strong>
                                                            <?php if (!empty($result['parameter_code'])): ?>
                                                                <br><small class="text-muted"><?php echo $result['parameter_code']; ?></small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php echo $result['result_value']; ?>
                                                            <?php if (!empty($result['result_unit']) && !strpos($result['result_value'], $result['result_unit'])): ?>
                                                                <br><small class="text-muted"><?php echo $result['result_unit']; ?></small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php echo $reference_range; ?>
                                                        </td>
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
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Verification Form -->
                                <div class="card-footer">
                                    <form method="POST" action="">
                                        <input type="hidden" name="sample_id" value="<?php echo $sample_id; ?>">
                                        <input type="hidden" name="test_id" value="<?php echo $current_test['test_id']; ?>">
                                        
                                        <div class="row">
                                            <div class="col-md-8">
                                                <div class="mb-3">
                                                    <label for="verification_notes" class="form-label">Verification Notes</label>
                                                    <textarea class="form-control" id="verification_notes" name="verification_notes" 
                                                              rows="2" placeholder="Add any comments or notes..."></textarea>
                                                </div>
                                                
                                                <?php if (count($tests_needing_verification) > 1): ?>
                                                    <div class="mb-3">
                                                        <label class="form-label">Verification Scope</label>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="verification_scope" 
                                                                   id="scope_single" value="single" checked>
                                                            <label class="form-check-label" for="scope_single">
                                                                Verify this test only
                                                            </label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="radio" name="verification_scope" 
                                                                   id="scope_all" value="all">
                                                            <label class="form-check-label" for="scope_all">
                                                                Verify all <?php echo count($tests_needing_verification); ?> pending tests in this sample
                                                            </label>
                                                        </div>
                                                    </div>
                                                <?php else: ?>
                                                    <input type="hidden" name="verification_scope" value="single">
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="d-grid gap-2 h-100">
                                                    <button type="submit" name="verify" value="reject" 
                                                            class="btn btn-danger btn-lg"
                                                            onclick="return confirmReject()">
                                                        <i class="bi bi-x-circle"></i> Reject
                                                    </button>
                                                    <button type="submit" name="verify" value="approve" 
                                                            class="btn btn-success btn-lg"
                                                            onclick="return confirmApprove()">
                                                        <i class="bi bi-check-circle"></i> Approve
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                                
                            <?php else: ?>
                                <!-- Already Verified Message -->
                                <div class="card-body">
                                    <div class="alert alert-success">
                                        <i class="bi bi-check-circle"></i>
                                        This test has already been verified. No further action required.
                                    </div>
                                    <div class="text-center">
                                        <a href="verify_results.php?sample_id=<?php echo $sample_id; ?>" 
                                           class="btn btn-outline-primary">
                                            View other tests in this sample
                                        </a>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.badge {
    font-weight: 500;
    padding: 0.5em 1em;
}

.table th {
    background-color: #f8f9fa;
    font-weight: 600;
    white-space: nowrap;
}

.card-header h5, .card-header h6 {
    font-weight: 600;
}

.btn-lg {
    padding: 0.75rem 1.5rem;
    font-size: 1rem;
}

.table-warning {
    background-color: rgba(255, 193, 7, 0.1) !important;
}

.table-warning td {
    border-color: rgba(255, 193, 7, 0.2);
}

.table td {
    vertical-align: middle;
}

.dropdown-menu .badge {
    font-size: 0.75rem;
    padding: 0.25em 0.5em;
}
</style>

<script>
$(document).ready(function() {
    // Confirmation functions
    window.confirmApprove = function() {
        var scope = $('input[name="verification_scope"]:checked').val();
        var pendingCount = <?php echo count($tests_needing_verification); ?>;
        
        if (scope === 'all' && pendingCount > 1) {
            return confirm('Are you sure you want to approve ALL ' + pendingCount + ' pending tests in this sample?');
        } else {
            var testName = '<?php echo addslashes($current_test['test_name'] ?? 'this test'); ?>';
            return confirm('Are you sure you want to approve "' + testName + '"?');
        }
    };
    
    window.confirmReject = function() {
        var scope = $('input[name="verification_scope"]:checked').val();
        var pendingCount = <?php echo count($tests_needing_verification); ?>;
        
        if (scope === 'all' && pendingCount > 1) {
            return confirm('Are you sure you want to reject ALL ' + pendingCount + ' pending tests in this sample?');
        } else {
            var testName = '<?php echo addslashes($current_test['test_name'] ?? 'this test'); ?>';
            return confirm('Are you sure you want to reject "' + testName + '"?');
        }
    };
    
    // Update button text based on scope selection
    $('input[name="verification_scope"]').change(function() {
        var scope = $(this).val();
        var pendingCount = <?php echo count($tests_needing_verification); ?>;
        
        var approveBtn = $('button[name="verify"][value="approve"]');
        var rejectBtn = $('button[name="verify"][value="reject"]');
        
        if (scope === 'all' && pendingCount > 1) {
            approveBtn.html('<i class="bi bi-check-circle"></i> Approve All (' + pendingCount + ')');
            rejectBtn.html('<i class="bi bi-x-circle"></i> Reject All (' + pendingCount + ')');
        } else {
            approveBtn.html('<i class="bi bi-check-circle"></i> Approve');
            rejectBtn.html('<i class="bi bi-x-circle"></i> Reject');
        }
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>