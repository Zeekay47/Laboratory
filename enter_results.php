<?php
$page_title = 'Enter Test Results';
require_once 'includes/header.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';

$auth = new Auth();
$auth->requireRole('technician');

$db = new Database();
$user_id = $_SESSION['user_id'];
$message = '';

// Display session messages
if (isset($_SESSION['success'])) {
    $message = '<div class="alert alert-success">' . $_SESSION['success'] . '</div>';
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $message = '<div class="alert alert-danger">' . $_SESSION['error'] . '</div>';
    unset($_SESSION['error']);
}

// Handle sample selection and test selection
$sample_id = $_GET['sample_id'] ?? '';
$test_id = $_GET['test_id'] ?? 0;

$order_test = null;
$test_parameters = [];
$tests_in_sample = [];
$all_results_entered = false;

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
        // Get patient information
        $first_test = $tests_in_sample[0];
        $db->query('SELECT p.* 
                   FROM orders o
                   JOIN patients p ON o.patient_id = p.id
                   WHERE o.id = :order_id');
        $db->bind(':order_id', $first_test['order_id']);
        $patient_info = $db->single();
        
        // Select specific test or default to first
        if ($test_id) {
            foreach ($tests_in_sample as $test) {
                if ($test['test_id'] == $test_id) {
                    $order_test = $test;
                    break;
                }
            }
        }
        
        if (!$order_test) {
            $order_test = $tests_in_sample[0];
        }
        
        // Add patient info to order_test
        $order_test['patient_name'] = $patient_info['full_name'];
        $order_test['age'] = $patient_info['age'];
        $order_test['gender'] = $patient_info['gender'];
        $order_test['cnic'] = $patient_info['cnic'] ?? '';
        
        // Get test parameters
        $db->query('SELECT * FROM test_parameters 
                   WHERE test_id = :test_id AND is_active = 1 
                   ORDER BY sort_order');
        $db->bind(':test_id', $order_test['test_id']);
        $test_parameters = $db->resultSet();
        
        // Check if all parameters have results
        $entered_params = 0;
        foreach ($test_parameters as $parameter) {
            $db->query('SELECT COUNT(*) as count FROM test_results 
                       WHERE order_test_id = :order_test_id AND parameter_id = :parameter_id');
            $db->bind(':order_test_id', $order_test['id']);
            $db->bind(':parameter_id', $parameter['id']);
            $result = $db->single();
            
            if ($result['count'] > 0) {
                $entered_params++;
            }
        }
        
        $all_results_entered = ($entered_params == count($test_parameters) && count($test_parameters) > 0);
        
        // Update status if needed
        if ($all_results_entered && $order_test['status'] == 'processing') {
            $db->query('UPDATE order_tests SET status = "results-entered" WHERE id = :id');
            $db->bind(':id', $order_test['id']);
            $db->execute();
            $order_test['status'] = 'results-entered';
        } elseif (!$all_results_entered && $order_test['status'] == 'sample-collected') {
            $db->query('UPDATE order_tests SET status = "processing" WHERE id = :id');
            $db->bind(':id', $order_test['id']);
            $db->execute();
            $order_test['status'] = 'processing';
        }
    }
}

// Handle result submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_results'])) {
    $order_test_id = $_POST['order_test_id'];
    $current_sample_id = $_POST['sample_id'];
    $results = $_POST['results'] ?? [];
    $validation_errors = [];
    
    // Validate results
    foreach ($results as $parameter_id => $result_data) {
        $result_value = trim($result_data['value']);
        
        if (!empty($result_value) && is_numeric($result_value)) {
            $db->query('SELECT min_possible, max_possible FROM test_parameters WHERE id = :id');
            $db->bind(':id', $parameter_id);
            $parameter = $db->single();
            
            $value = floatval($result_value);
            
            if ($parameter['min_possible'] !== null && $value < $parameter['min_possible']) {
                $validation_errors[] = "Value is below minimum allowed ({$parameter['min_possible']})";
            }
            
            if ($parameter['max_possible'] !== null && $value > $parameter['max_possible']) {
                $validation_errors[] = "Value exceeds maximum allowed ({$parameter['max_possible']})";
            }
        }
    }
    
    if (!empty($validation_errors)) {
        $_SESSION['error'] = 'Validation errors: ' . implode(', ', $validation_errors);
        header("Location: enter_results.php?sample_id=$current_sample_id&test_id=" . ($order_test['test_id'] ?? 0));
        exit();
    }
    
    try {
        $db->query('BEGIN TRANSACTION');
        
        foreach ($results as $parameter_id => $result_data) {
            $result_value = trim($result_data['value']);
            $notes = trim($result_data['notes'] ?? '');
            
            if (!empty($result_value)) {
                // Get parameter details
                $db->query('SELECT * FROM test_parameters WHERE id = :id');
                $db->bind(':id', $parameter_id);
                $parameter = $db->single();
                
                // Determine reference range and flag
                $flag = 'Normal';
                $reference_range = '';
                
                if (is_numeric($result_value)) {
                    $value = floatval($result_value);
                    
                    // Get gender-specific or normal range
                    $min = $parameter['normal_min'] ?? null;
                    $max = $parameter['normal_max'] ?? null;
                    $unit = $parameter['unit'] ?? '';
                    
                    if ($order_test['gender'] == 'Male' && $parameter['male_min'] !== null) {
                        $min = $parameter['male_min'];
                        $max = $parameter['male_max'];
                    } elseif ($order_test['gender'] == 'Female' && $parameter['female_min'] !== null) {
                        $min = $parameter['female_min'];
                        $max = $parameter['female_max'];
                    }
                    
                    if ($min !== null && $max !== null) {
                        $reference_range = "$min - $max $unit";
                        
                        if ($value < $min) $flag = 'Low';
                        elseif ($value > $max) $flag = 'High';
                    }
                } else {
                    $flag = 'N/A';
                    $reference_range = 'Qualitative';
                }
                
                // Insert or update result
                $db->query('INSERT INTO test_results 
                           (order_test_id, parameter_id, result_value, flag, reference_range, notes, entered_by)
                           VALUES (:order_test_id, :parameter_id, :result_value, :flag, :reference_range, :notes, :entered_by)
                           ON DUPLICATE KEY UPDATE
                           result_value = VALUES(result_value),
                           flag = VALUES(flag),
                           reference_range = VALUES(reference_range),
                           notes = VALUES(notes)');
                
                $db->bind(':order_test_id', $order_test_id);
                $db->bind(':parameter_id', $parameter_id);
                $db->bind(':result_value', $result_value);
                $db->bind(':flag', $flag);
                $db->bind(':reference_range', $reference_range);
                $db->bind(':notes', $notes);
                $db->bind(':entered_by', $user_id);
                $db->execute();
            }
        }
        
        // Update order test status
        $db->query('UPDATE order_tests SET status = "results-entered" WHERE id = :id');
        $db->bind(':id', $order_test_id);
        $db->execute();
        
        // Check for next test in sample
        $db->query('SELECT ot.test_id 
                   FROM order_tests ot
                   WHERE ot.sample_id = :sample_id 
                   AND ot.status = "processing"
                   AND ot.id != :current_id
                   LIMIT 1');
        $db->bind(':sample_id', $current_sample_id);
        $db->bind(':current_id', $order_test_id);
        $next_test = $db->single();
        
        $db->query('COMMIT');
        
        $_SESSION['success'] = 'Results saved successfully!';
        
        if ($next_test) {
            header("Location: enter_results.php?sample_id=$current_sample_id&test_id=" . $next_test['test_id']);
        } else {
            header("Location: enter_results.php");
        }
        exit();
        
    } catch (Exception $e) {
        $db->query('ROLLBACK');
        $_SESSION['error'] = 'Error saving results: ' . $e->getMessage();
        header("Location: enter_results.php?sample_id=$current_sample_id&test_id=" . ($order_test['test_id'] ?? 0));
        exit();
    }
}
?>

<div class="row">
    <div class="col-md-12">
        <?php echo $message; ?>
        
        <?php if (!$sample_id || !$order_test): ?>
            <!-- Sample Selection Page -->
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Select Sample to Process</h5>
                </div>
                <div class="card-body">
                    <form method="GET" action="" class="mb-4">
                        <div class="input-group">
                            <input type="text" class="form-control" name="sample_id" 
                                   placeholder="Enter Sample ID" required>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search"></i> Load Sample
                            </button>
                        </div>
                    </form>
                    
                    <h6 class="mb-3">Pending Tests</h6>
                    <div class="table-responsive">
                        <table class="table table-hover">
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
                                $db->query('SELECT DISTINCT ot.sample_id, t.test_name, p.full_name, ot.status
                                           FROM order_tests ot
                                           JOIN tests t ON ot.test_id = t.id
                                           JOIN orders o ON ot.order_id = o.id
                                           JOIN patients p ON o.patient_id = p.id
                                           WHERE ot.status IN ("processing", "sample-collected")
                                           ORDER BY ot.id DESC
                                           LIMIT 15');
                                $samples = $db->resultSet();
                                
                                if (empty($samples)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">
                                            <i class="bi bi-check-circle display-6"></i>
                                            <p class="mt-3">No pending tests to process</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($samples as $sample): ?>
                                    <tr>
                                        <td><code><?php echo $sample['sample_id']; ?></code></td>
                                        <td><?php echo $sample['test_name']; ?></td>
                                        <td><?php echo $sample['full_name']; ?></td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                switch($sample['status']) {
                                                    case 'sample-collected': echo 'info'; break;
                                                    case 'processing': echo 'warning'; break;
                                                    default: echo 'secondary';
                                                }
                                            ?>">
                                                <?php echo $sample['status']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="enter_results.php?sample_id=<?php echo $sample['sample_id']; ?>" 
                                               class="btn btn-sm btn-primary">
                                                <i class="bi bi-pencil"></i> Enter Results
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
            <!-- Results Entry Page -->
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Enter Test Results</h5>
                        <div>
                            <?php if (count($tests_in_sample) > 1): ?>
                                <div class="dropdown d-inline-block me-2">
                                    <button class="btn btn-light btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                        <i class="bi bi-list"></i> Switch Test
                                    </button>
                                    <ul class="dropdown-menu">
                                        <?php foreach ($tests_in_sample as $test): 
                                            // Check test completion
                                            $db->query('SELECT COUNT(*) as total, 
                                                      (SELECT COUNT(*) FROM test_results WHERE order_test_id = :id) as entered
                                                      FROM test_parameters WHERE test_id = :test_id');
                                            $db->bind(':id', $test['id']);
                                            $db->bind(':test_id', $test['test_id']);
                                            $status = $db->single();
                                            $complete = ($status['total'] == $status['entered'] && $status['total'] > 0);
                                        ?>
                                            <li>
                                                <a class="dropdown-item <?php echo $test['test_id'] == $order_test['test_id'] ? 'active' : ''; ?>" 
                                                   href="enter_results.php?sample_id=<?php echo $sample_id; ?>&test_id=<?php echo $test['test_id']; ?>">
                                                    <?php echo $test['test_name']; ?>
                                                    <?php if ($test['test_id'] == $order_test['test_id']): ?>
                                                        <i class="bi bi-check ms-2"></i>
                                                    <?php endif; ?>
                                                    <?php if ($complete): ?>
                                                        <span class="badge bg-success float-end">Complete</span>
                                                    <?php endif; ?>
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                            <a href="enter_results.php" class="btn btn-light btn-sm">
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
                                    <p class="mb-1"><strong>Name:</strong> <?php echo $order_test['patient_name']; ?></p>
                                    <p class="mb-1"><strong>Age/Gender:</strong> <?php echo $order_test['age']; ?> yrs / <?php echo $order_test['gender']; ?></p>
                                    <?php if (!empty($order_test['cnic'])): ?>
                                        <p class="mb-1"><strong>CNIC:</strong> <?php echo $order_test['cnic']; ?></p>
                                    <?php endif; ?>
                                    <p class="mb-0"><strong>Sample ID:</strong> <code><?php echo $sample_id; ?></code></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h6 class="card-title">Test Information</h6>
                                    <p class="mb-1"><strong>Test:</strong> 
                                        <?php echo $order_test['test_name']; ?>
                                        <?php if (!empty($order_test['test_code'])): ?>
                                            <small class="text-muted">(<?php echo $order_test['test_code']; ?>)</small>
                                        <?php endif; ?>
                                    </p>
                                    <p class="mb-1"><strong>Status:</strong> 
                                        <span class="badge bg-<?php 
                                            switch($order_test['status']) {
                                                case 'processing': echo 'warning'; break;
                                                case 'results-entered': echo 'success'; break;
                                                default: echo 'secondary';
                                            }
                                        ?>">
                                            <?php echo $order_test['status']; ?>
                                            <?php if ($all_results_entered): ?>
                                                <i class="bi bi-check-circle ms-1"></i>
                                            <?php endif; ?>
                                        </span>
                                    </p>
                                    <p class="mb-0"><strong>Technician:</strong> <?php echo $_SESSION['full_name']; ?></p>
                                    
                                    <?php if (count($tests_in_sample) > 1): ?>
                                        <div class="mt-2">
                                            <small class="text-muted">
                                                <i class="bi bi-info-circle"></i>
                                                This sample contains <?php echo count($tests_in_sample); ?> tests
                                            </small>
                                            <div class="d-flex flex-wrap gap-1 mt-1">
                                                <?php foreach ($tests_in_sample as $test): 
                                                    $is_current = $test['test_id'] == $order_test['test_id'];
                                                    $badge_class = $is_current ? 'bg-primary' : 'bg-secondary';
                                                ?>
                                                    <span class="badge <?php echo $badge_class; ?>">
                                                        <?php echo $test['test_name']; ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($all_results_entered): ?>
                        <!-- Results Already Entered -->
                        <div class="alert alert-success">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-check-circle-fill fs-4 me-3"></i>
                                <div>
                                    <h5 class="mb-1">Results Already Entered</h5>
                                    <p class="mb-0">All results for this test have been entered and are ready for verification.</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="text-center mt-4">
                            <?php if (count($tests_in_sample) > 1): ?>
                                <a href="enter_results.php?sample_id=<?php echo $sample_id; ?>" class="btn btn-outline-primary me-2">
                                    View Other Tests
                                </a>
                            <?php endif; ?>
                            <a href="enter_results.php" class="btn btn-primary">
                                <i class="bi bi-arrow-left"></i> Back to Sample List
                            </a>
                        </div>
                        
                    <?php else: ?>
                        <!-- Results Entry Form -->
                        <form method="POST" action="" id="resultsForm">
                            <input type="hidden" name="order_test_id" value="<?php echo $order_test['id']; ?>">
                            <input type="hidden" name="sample_id" value="<?php echo $sample_id; ?>">
                            
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="mb-0">
                                        Enter Results for <?php echo $order_test['test_name']; ?>
                                        <?php if (!empty($order_test['test_code'])): ?>
                                            <small class="text-muted">(<?php echo $order_test['test_code']; ?>)</small>
                                        <?php endif; ?>
                                    </h6>
                                </div>
                                
                                <div class="card-body">
                                    <?php if (empty($test_parameters)): ?>
                                        <div class="alert alert-warning">
                                            <i class="bi bi-exclamation-triangle"></i>
                                            No parameters configured for this test.
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
                                                    <?php 
                                                    $total_params = count($test_parameters);
                                                    $entered_count = 0;
                                                    
                                                    foreach ($test_parameters as $parameter): 
                                                        // Get existing result
                                                        $db->query('SELECT * FROM test_results 
                                                                   WHERE order_test_id = :order_test_id AND parameter_id = :parameter_id');
                                                        $db->bind(':order_test_id', $order_test['id']);
                                                        $db->bind(':parameter_id', $parameter['id']);
                                                        $existing = $db->single();
                                                        
                                                        if ($existing) {
                                                            $entered_count++;
                                                        }
                                                        
                                                        // Determine reference range with unit
                                                        $reference_range = '';
                                                        $unit = $parameter['unit'] ?? '';
                                                        
                                                        if (!empty($parameter['parameter_options'])) {
                                                            $reference_range = 'Qualitative';
                                                        } elseif ($order_test['gender'] == 'Male' && $parameter['male_min'] !== null) {
                                                            $reference_range = $parameter['male_min'] . ' - ' . $parameter['male_max'] . ' ' . $unit;
                                                        } elseif ($order_test['gender'] == 'Female' && $parameter['female_min'] !== null) {
                                                            $reference_range = $parameter['female_min'] . ' - ' . $parameter['female_max'] . ' ' . $unit;
                                                        } elseif ($parameter['normal_min'] !== null) {
                                                            $reference_range = $parameter['normal_min'] . ' - ' . $parameter['normal_max'] . ' ' . $unit;
                                                        }
                                                    ?>
                                                    <tr class="<?php echo $existing ? 'table-success' : ''; ?>">
                                                        <td>
                                                            <strong><?php echo $parameter['parameter_name']; ?></strong>
                                                            <?php if (!empty($parameter['parameter_code'])): ?>
                                                                <br><small class="text-muted"><?php echo $parameter['parameter_code']; ?></small>
                                                            <?php endif; ?>
                                                            <?php if ($existing): ?>
                                                                <br><small class="text-success"><i class="bi bi-check-circle"></i> Entered</small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if (!empty($parameter['parameter_options'])): ?>
                                                                <!-- Dropdown for qualitative parameters -->
                                                                <select class="form-control" 
                                                                        name="results[<?php echo $parameter['id']; ?>][value]"
                                                                        <?php echo $existing ? 'disabled' : 'required'; ?>
                                                                        style="<?php echo $existing ? 'background-color: #e8f5e9;' : ''; ?>">
                                                                    <option value="">Select...</option>
                                                                    <?php
                                                                    $options = explode(',', $parameter['parameter_options']);
                                                                    foreach ($options as $option):
                                                                        $option = trim($option);
                                                                    ?>
                                                                        <option value="<?php echo htmlspecialchars($option); ?>"
                                                                            <?php echo ($existing['result_value'] ?? '') == $option ? 'selected' : ''; ?>>
                                                                            <?php echo htmlspecialchars($option); ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                                <?php if ($existing): ?>
                                                                    <input type="hidden" name="results[<?php echo $parameter['id']; ?>][value]" 
                                                                           value="<?php echo $existing['result_value'] ?? ''; ?>">
                                                                <?php endif; ?>
                                                            <?php else: ?>
                                                                <!-- Numeric input for quantitative parameters -->
                                                                <input type="number" 
                                                                       step="any"
                                                                       class="form-control result-value" 
                                                                       name="results[<?php echo $parameter['id']; ?>][value]"
                                                                       value="<?php echo $existing['result_value'] ?? ''; ?>"
                                                                       <?php if ($parameter['min_possible'] !== null): ?>
                                                                           min="<?php echo $parameter['min_possible']; ?>"
                                                                       <?php endif; ?>
                                                                       <?php if ($parameter['max_possible'] !== null): ?>
                                                                           max="<?php echo $parameter['max_possible']; ?>"
                                                                       <?php endif; ?>
                                                                       placeholder="Enter value"
                                                                       <?php echo $existing ? 'readonly' : 'required'; ?>
                                                                       style="<?php echo $existing ? 'background-color: #e8f5e9;' : ''; ?>"
                                                                       data-param-id="<?php echo $parameter['id']; ?>">
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php echo $reference_range; ?>
                                                            <?php if (!empty($parameter['min_possible']) && !empty($parameter['max_possible']) && empty($parameter['parameter_options'])): ?>
                                                                <br><small class="text-muted">
                                                                    Allowed: <?php echo $parameter['min_possible']; ?> - <?php echo $parameter['max_possible']; ?>
                                                                </small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if (!empty($parameter['parameter_options'])): ?>
                                                                <span class="badge bg-secondary">N/A</span>
                                                            <?php else: ?>
                                                                <?php if ($existing): ?>
                                                                    <span class="badge bg-<?php 
                                                                        switch($existing['flag']) {
                                                                            case 'Normal': echo 'success'; break;
                                                                            case 'Low': echo 'warning'; break;
                                                                            case 'High': echo 'danger'; break;
                                                                            default: echo 'secondary';
                                                                        }
                                                                    ?>">
                                                                        <?php echo $existing['flag']; ?>
                                                                    </span>
                                                                <?php else: ?>
                                                                    <span class="badge bg-light text-dark">-</span>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <input type="text" 
                                                                   class="form-control" 
                                                                   name="results[<?php echo $parameter['id']; ?>][notes]"
                                                                   value="<?php echo $existing['notes'] ?? ''; ?>"
                                                                   placeholder="Optional notes"
                                                                   <?php echo $existing ? 'readonly' : ''; ?>
                                                                   style="<?php echo $existing ? 'background-color: #e8f5e9;' : ''; ?>">
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        
                                        <!-- Progress and Validation Info -->
                                        <div class="row mt-4">
                                            <div class="col-md-8">
                                                <div class="alert alert-info">
                                                    <i class="bi bi-info-circle"></i>
                                                    <strong>Progress:</strong> <?php echo $entered_count; ?> of <?php echo $total_params; ?> parameters entered.
                                                    <?php if (!empty($test_parameters) && $entered_count == $total_params): ?>
                                                        <br><span class="text-success">All results entered! Ready for verification.</span>
                                                    <?php endif; ?>
                                                    <br><small>Numeric values are validated against allowed ranges shown in reference column.</small>
                                                </div>
                                            </div>
                                            <div class="col-md-4 text-end">
                                                <div class="d-flex align-items-center justify-content-end h-100">
                                                    <div class="text-muted">
                                                        <small>Flag Legend:</small>
                                                        <div class="d-flex gap-2 mt-1">
                                                            <span class="badge bg-success">Normal</span>
                                                            <span class="badge bg-warning">Low</span>
                                                            <span class="badge bg-danger">High</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- Action Buttons -->
                                    <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                                        <a href="enter_results.php" class="btn btn-outline-secondary me-2">
                                            <i class="bi bi-x-circle"></i> Cancel
                                        </a>
                                        <?php if (!empty($test_parameters)): ?>
                                            <button type="submit" name="save_results" class="btn btn-primary">
                                                <i class="bi bi-save"></i> Save Results
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </form>
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

.result-value:valid {
    border-color: #198754;
    background-color: #f8fff9;
}

.result-value:invalid {
    border-color: #dc3545;
    background-color: #fff8f8;
}

.card-header h5, .card-header h6 {
    font-weight: 600;
}

.table-success {
    background-color: rgba(25, 135, 84, 0.05);
}

.table-success td {
    border-color: rgba(25, 135, 84, 0.1);
}

.table td {
    vertical-align: middle;
}
</style>

<script>
$(document).ready(function() {
    // Real-time validation for numeric inputs
    $('.result-value').on('input', function() {
        var input = $(this);
        var value = parseFloat(input.val());
        var min = parseFloat(input.attr('min'));
        var max = parseFloat(input.attr('max'));
        
        if (!isNaN(value)) {
            if ((!isNaN(min) && value < min) || (!isNaN(max) && value > max)) {
                input.addClass('is-invalid').removeClass('is-valid');
            } else {
                input.removeClass('is-invalid').addClass('is-valid');
            }
        } else {
            input.removeClass('is-invalid is-valid');
        }
    });
    
    // Form submission validation
    $('#resultsForm').on('submit', function(e) {
        var hasInvalid = false;
        var errorMessages = [];
        
        $('.result-value').each(function() {
            var input = $(this);
            var value = parseFloat(input.val());
            var min = parseFloat(input.attr('min'));
            var max = parseFloat(input.attr('max'));
            var paramName = input.closest('tr').find('td:first-child strong').text();
            
            if (!isNaN(value)) {
                if (!isNaN(min) && value < min) {
                    hasInvalid = true;
                    errorMessages.push(paramName + ': Value below minimum (' + min + ')');
                }
                if (!isNaN(max) && value > max) {
                    hasInvalid = true;
                    errorMessages.push(paramName + ': Value above maximum (' + max + ')');
                }
            }
        });
        
        if (hasInvalid) {
            e.preventDefault();
            var errorHtml = '<div class="alert alert-danger"><h6>Validation Errors:</h6><ul>';
            errorMessages.forEach(function(msg) {
                errorHtml += '<li>' + msg + '</li>';
            });
            errorHtml += '</ul></div>';
            
            // Show error at top of form
            $('#resultsForm').prepend(errorHtml);
            window.scrollTo(0, 0);
        }
    });
    
    // Auto-calculate flag when numeric value changes
    $('.result-value').on('change', function() {
        if ($(this).prop('readonly')) return;
        
        var input = $(this);
        var value = parseFloat(input.val());
        var row = input.closest('tr');
        var refRangeText = row.find('td:nth-child(3)').text().split('\n')[0].trim(); // Get only first line
        
        if (!isNaN(value)) {
            // Parse reference range (format: "min - max unit")
            var match = refRangeText.match(/(\d+\.?\d*)\s*-\s*(\d+\.?\d*)/);
            if (match) {
                var min = parseFloat(match[1]);
                var max = parseFloat(match[2]);
                var flagBadge = row.find('.badge');
                
                // Remove all flag classes
                flagBadge.removeClass('bg-success bg-warning bg-danger bg-secondary bg-light text-dark');
                
                // Set appropriate flag
                if (value < min) {
                    flagBadge.addClass('bg-warning').text('Low');
                } else if (value > max) {
                    flagBadge.addClass('bg-danger').text('High');
                } else {
                    flagBadge.addClass('bg-success').text('Normal');
                }
            }
        }
    });
    
    // Initialize validation on page load
    $('.result-value').each(function() {
        if ($(this).val()) {
            $(this).trigger('input');
        }
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>