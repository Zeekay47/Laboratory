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

// Handle sample selection
$sample_id = $_GET['sample_id'] ?? '';
$order_test = null;
$test_parameters = [];

if ($sample_id) {
    // Get order test details
    $db->query('SELECT ot.*, t.test_name, t.test_code, p.full_name, p.age, p.gender, o.patient_id
                FROM order_tests ot
                JOIN tests t ON ot.test_id = t.id
                JOIN orders o ON ot.order_id = o.id
                JOIN patients p ON o.patient_id = p.id
                WHERE ot.sample_id = :sample_id');
    $db->bind(':sample_id', $sample_id);
    $order_test = $db->single();
    
    if ($order_test) {
        // Get test parameters with validation limits
        $db->query('SELECT * FROM test_parameters 
                   WHERE test_id = :test_id AND is_active = 1 
                   ORDER BY sort_order');
        $db->bind(':test_id', $order_test['test_id']);
        $test_parameters = $db->resultSet();
        
        // Check if already has results
        $db->query('SELECT COUNT(*) as count FROM test_results WHERE order_test_id = :order_test_id');
        $db->bind(':order_test_id', $order_test['id']);
        $has_results = $db->single()['count'] > 0;
        
        // Update status to processing if not already
        if ($order_test['status'] == 'sample-collected' || $order_test['status'] == 'pending') {
            $db->query('UPDATE order_tests SET status = "processing" WHERE id = :id');
            $db->bind(':id', $order_test['id']);
            $db->execute();
            
            // Update order status to processing if all tests are being processed
            $db->query('UPDATE orders SET status = "processing" WHERE id = :id');
            $db->bind(':id', $order_test['order_id']);
            $db->execute();
        }
    }
}

// Handle result submission with validation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_results'])) {
    $order_test_id = $_POST['order_test_id'];
    $results = $_POST['results'];
    $validation_errors = [];
    
    // Validate all results before saving
    foreach ($results as $parameter_id => $result_data) {
        $result_value = trim($result_data['value']);
        
        if (!empty($result_value) && is_numeric($result_value)) {
            // Get parameter validation limits
            $db->query('SELECT min_possible, max_possible FROM test_parameters WHERE id = :id');
            $db->bind(':id', $parameter_id);
            $parameter = $db->single();
            
            $value = floatval($result_value);
            
            // Check against min_possible
            if ($parameter['min_possible'] !== null && $value < $parameter['min_possible']) {
                $validation_errors[] = "Value for parameter ID $parameter_id is below minimum allowed value ({$parameter['min_possible']})";
            }
            
            // Check against max_possible
            if ($parameter['max_possible'] !== null && $value > $parameter['max_possible']) {
                $validation_errors[] = "Value for parameter ID $parameter_id exceeds maximum allowed value ({$parameter['max_possible']})";
            }
        }
    }
    
    if (!empty($validation_errors)) {
        $message = '<div class="alert alert-danger"><h6>Validation Errors:</h6><ul>';
        foreach ($validation_errors as $error) {
            $message .= '<li>' . $error . '</li>';
        }
        $message .= '</ul></div>';
    } else {
        // Proceed with saving results
        try {
            $db->query('BEGIN TRANSACTION');
            
            foreach ($results as $parameter_id => $result_data) {
                $result_value = $result_data['value'];
                $result_unit = $result_data['unit'] ?? '';
                $notes = $result_data['notes'] ?? '';
                
                if (!empty($result_value)) {
                    // Get parameter details for reference ranges
                    $db->query('SELECT * FROM test_parameters WHERE id = :id');
                    $db->bind(':id', $parameter_id);
                    $parameter = $db->single();
                    
                    // Calculate flag based on reference ranges (for numeric values only)
                    $flag = 'Normal';
                    $reference_range = '';
                    
                    if (is_numeric($result_value)) {
                        $value = floatval($result_value);
                        
                        // Determine appropriate reference range
                        if ($order_test['gender'] == 'Male' && $parameter['male_min'] !== null) {
                            $min = $parameter['male_min'];
                            $max = $parameter['male_max'];
                            $reference_range = $min . ' - ' . $max . ' ' . $parameter['unit'];
                            
                            if ($value < $min) $flag = 'Low';
                            elseif ($value > $max) $flag = 'High';
                        } elseif ($order_test['gender'] == 'Female' && $parameter['female_min'] !== null) {
                            $min = $parameter['female_min'];
                            $max = $parameter['female_max'];
                            $reference_range = $min . ' - ' . $max . ' ' . $parameter['unit'];
                            
                            if ($value < $min) $flag = 'Low';
                            elseif ($value > $max) $flag = 'High';
                        } elseif ($parameter['normal_min'] !== null) {
                            $min = $parameter['normal_min'];
                            $max = $parameter['normal_max'];
                            $reference_range = $min . ' - ' . $max . ' ' . $parameter['unit'];
                            
                            if ($value < $min) $flag = 'Low';
                            elseif ($value > $max) $flag = 'High';
                        }
                    } else {
                        // For qualitative (dropdown) values, show the value without flag
                        $flag = 'N/A';
                        $reference_range = 'Qualitative';
                    }
                    
                    // Insert or update result
                    $db->query('INSERT INTO test_results 
                               (order_test_id, parameter_id, result_value, result_unit, flag, reference_range, notes, entered_by)
                               VALUES (:order_test_id, :parameter_id, :result_value, :result_unit, :flag, :reference_range, :notes, :entered_by)
                               ON DUPLICATE KEY UPDATE
                               result_value = VALUES(result_value),
                               result_unit = VALUES(result_unit),
                               flag = VALUES(flag),
                               reference_range = VALUES(reference_range),
                               notes = VALUES(notes)');
                    
                    $db->bind(':order_test_id', $order_test_id);
                    $db->bind(':parameter_id', $parameter_id);
                    $db->bind(':result_value', $result_value);
                    $db->bind(':result_unit', $result_unit);
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
            
            $db->query('COMMIT');
            
            $message = '<div class="alert alert-success">Results saved successfully!</div>';
            
            // Redirect to view results
            header('Location: view_results.php?sample_id=' . $sample_id);
            exit();
            
        } catch (Exception $e) {
            $db->query('ROLLBACK');
            $message = '<div class="alert alert-danger">Error saving results: ' . $e->getMessage() . '</div>';
        }
    }
}
?>

<div class="row">
    <div class="col-md-12">
        <?php echo $message; ?>
        
        <?php if (!$sample_id || !$order_test): ?>
            <!-- Sample Selection Form -->
            <div class="card">
                <div class="card-header">
                    <h5>Select Sample to Process</h5>
                </div>
                <div class="card-body">
                    <form method="GET" action="">
                        <div class="input-group mb-3">
                            <input type="text" class="form-control" name="sample_id" 
                                   placeholder="Enter Sample ID (e.g., SMP-000001)" required>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search"></i> Load Sample
                            </button>
                        </div>
                    </form>
                    
                    <div class="mt-4">
                        <h6>Pending Samples</h6>
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
                                    $db->query('SELECT ot.sample_id, ot.status, t.test_name, p.full_name
                                        FROM order_tests ot
                                        JOIN tests t ON ot.test_id = t.id
                                        JOIN orders o ON ot.order_id = o.id
                                        JOIN patients p ON o.patient_id = p.id
                                        WHERE ot.status IN ("processing")
                                        ORDER BY ot.id DESC LIMIT 10');
                                    $samples = $db->resultSet();
                                    
                                    if (empty($samples)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center">No pending samples</td>
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
                                                        case 'pending': echo 'secondary'; break;
                                                        case 'sample-collected': echo 'info'; break;
                                                        case 'processing': echo 'warning'; break;
                                                        default: echo 'light';
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
            </div>
        <?php else: ?>
            <!-- Results Entry Form -->
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5>Enter Results for Sample: <?php echo $sample_id; ?></h5>
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
                                    <?php 
                                    // Get CNIC from patient
                                    $db->query('SELECT cnic FROM patients WHERE id = :id');
                                    $db->bind(':id', $order_test['patient_id']);
                                    $patient = $db->single();
                                    if ($patient && $patient['cnic']): ?>
                                        <p><strong>CNIC:</strong> <?php echo $patient['cnic']; ?></p>
                                    <?php endif; ?>
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
                                        <span class="badge bg-warning"><?php echo $order_test['status']; ?></span>
                                    </p>
                                    <p><strong>Technician:</strong> <?php echo $_SESSION['full_name']; ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Results Form -->
                    <form method="POST" action="" id="resultsForm">
                        <input type="hidden" name="order_test_id" value="<?php echo $order_test['id']; ?>">
                        
                        <div class="card">
                            <div class="card-header">
                                <h6>Enter Results</h6>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
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
                                            <?php foreach ($test_parameters as $parameter): 
                                                // Get existing result if any
                                                $db->query('SELECT * FROM test_results 
                                                           WHERE order_test_id = :order_test_id AND parameter_id = :parameter_id');
                                                $db->bind(':order_test_id', $order_test['id']);
                                                $db->bind(':parameter_id', $parameter['id']);
                                                $existing_result = $db->single();
                                                
                                                // Determine reference range for display
                                                $reference_range = '';
                                                if (!empty($parameter['parameter_options'])) {
                                                    $reference_range = 'Dropdown';
                                                } elseif ($order_test['gender'] == 'Male' && $parameter['male_min'] !== null) {
                                                    $reference_range = $parameter['male_min'] . ' - ' . $parameter['male_max'];
                                                } elseif ($order_test['gender'] == 'Female' && $parameter['female_min'] !== null) {
                                                    $reference_range = $parameter['female_min'] . ' - ' . $parameter['female_max'];
                                                } elseif ($parameter['normal_min'] !== null) {
                                                    $reference_range = $parameter['normal_min'] . ' - ' . $parameter['normal_max'];
                                                }
                                                
                                                // Get input limits
                                                $input_limits = '';
                                                if ($parameter['min_possible'] !== null && $parameter['max_possible'] !== null) {
                                                    $input_limits = $parameter['min_possible'] . ' - ' . $parameter['max_possible'];
                                                }
                                            ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo $parameter['parameter_name']; ?></strong><br>
                                                    <small class="text-muted"><?php echo $parameter['parameter_code']; ?></small>
                                                    <?php if (!empty($parameter['parameter_options'])): ?>
                                                        <br><small class="text-info"><i>Qualitative parameter</i></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td width="15%">
                                                    <?php if (!empty($parameter['parameter_options'])): ?>
                                                        <!-- DROPDOWN for qualitative parameters -->
                                                        <select class="form-control result-select" 
                                                                name="results[<?php echo $parameter['id']; ?>][value]"
                                                                required>
                                                            <option value="">Select...</option>
                                                            <?php
                                                            $options = explode(',', $parameter['parameter_options']);
                                                            foreach ($options as $option):
                                                                $option = trim($option);
                                                            ?>
                                                                <option value="<?php echo htmlspecialchars($option); ?>"
                                                                    <?php echo ($existing_result['result_value'] ?? '') == $option ? 'selected' : ''; ?>>
                                                                    <?php echo htmlspecialchars($option); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    <?php else: ?>
                                                        <!-- NUMBER INPUT for quantitative parameters with validation -->
                                                        <input type="number" 
                                                               step="0.01"
                                                               class="form-control result-value" 
                                                               name="results[<?php echo $parameter['id']; ?>][value]"
                                                               value="<?php echo $existing_result['result_value'] ?? ''; ?>"
                                                               <?php if ($parameter['min_possible'] !== null): ?>
                                                                   min="<?php echo $parameter['min_possible']; ?>"
                                                               <?php endif; ?>
                                                               <?php if ($parameter['max_possible'] !== null): ?>
                                                                   max="<?php echo $parameter['max_possible']; ?>"
                                                               <?php endif; ?>
                                                               placeholder="Enter value"
                                                               required
                                                               data-param-id="<?php echo $parameter['id']; ?>"
                                                               data-min-possible="<?php echo $parameter['min_possible'] ?? ''; ?>"
                                                               data-max-possible="<?php echo $parameter['max_possible'] ?? ''; ?>">
                                                        <div class="invalid-feedback" id="error-<?php echo $parameter['id']; ?>">
                                                            Please enter a valid value between <?php echo $parameter['min_possible']; ?> and <?php echo $parameter['max_possible']; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td width="10%">
                                                    <input type="text" 
                                                           class="form-control" 
                                                           name="results[<?php echo $parameter['id']; ?>][unit]"
                                                           value="<?php echo $existing_result['result_unit'] ?? $parameter['unit']; ?>">
                                                </td>
                                                <td width="15%">
                                                    <?php if (!empty($parameter['parameter_options'])): ?>
                                                        <span class="badge bg-info">Qualitative</span>
                                                    <?php else: ?>
                                                        <?php echo $reference_range; ?> <?php echo $parameter['unit']; ?>
                                                    <?php endif; ?>
                                                </td>
                                                
                                                <td width="10%">
                                                    <?php if (!empty($parameter['parameter_options'])): ?>
                                                        <span class="badge bg-secondary">N/A</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-<?php 
                                                            if ($existing_result) {
                                                                switch($existing_result['flag']) {
                                                                    case 'Normal': echo 'success'; break;
                                                                    case 'Low': echo 'warning'; break;
                                                                    case 'High': echo 'danger'; break;
                                                                    default: echo 'secondary';
                                                                }
                                                                echo '">' . $existing_result['flag'];
                                                            } else {
                                                                echo 'secondary">-';
                                                            }
                                                        ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td width="20%">
                                                    <input type="text" 
                                                           class="form-control" 
                                                           name="results[<?php echo $parameter['id']; ?>][notes]"
                                                           value="<?php echo $existing_result['notes'] ?? ''; ?>"
                                                           placeholder="Optional notes...">
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div class="alert alert-info mt-3">
                                    <i class="bi bi-info-circle"></i> 
                                    <strong>Note:</strong> All numeric inputs have validation limits. Values outside the allowed range will be rejected.
                                </div>
                                
                                <div class="mt-4">
                                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                        <a href="enter_results.php" class="btn btn-secondary me-md-2">
                                            <i class="bi bi-arrow-left"></i> Back to Samples
                                        </a>
                                        <button type="submit" name="save_results" class="btn btn-primary">
                                            <i class="bi bi-save"></i> Save All Results
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
$(document).ready(function() {
    // Client-side validation for numeric inputs
    $('input[type="number"].result-value').on('blur', function() {
        var input = $(this);
        var value = parseFloat(input.val());
        var minPossible = parseFloat(input.data('min-possible'));
        var maxPossible = parseFloat(input.data('max-possible'));
        var paramId = input.data('param-id');
        var errorDiv = $('#error-' + paramId);
        
        // Reset validation state
        input.removeClass('is-invalid is-valid');
        errorDiv.hide();
        
        if (!isNaN(value)) {
            // Check min validation
            if (!isNaN(minPossible) && value < minPossible) {
                input.addClass('is-invalid');
                errorDiv.text('Value cannot be less than ' + minPossible);
                errorDiv.show();
                return false;
            }
            
            // Check max validation
            if (!isNaN(maxPossible) && value > maxPossible) {
                input.addClass('is-invalid');
                errorDiv.text('Value cannot exceed ' + maxPossible);
                errorDiv.show();
                return false;
            }
            
            // Value is valid
            input.addClass('is-valid');
        }
        
        // Auto-calculate flag when numeric result value changes
        var refText = $(this).closest('tr').find('td:nth-child(4)').text().trim();
        var refRange = refText.match(/(\d+\.?\d*)\s*-\s*(\d+\.?\d*)/);
        
        if (refRange && refRange.length >= 3) {
            var min = parseFloat(refRange[1]);
            var max = parseFloat(refRange[2]);
            var flagBadge = $(this).closest('tr').find('.badge');
            
            if (value < min) {
                flagBadge.removeClass().addClass('badge bg-warning').text('Low');
            } else if (value > max) {
                flagBadge.removeClass().addClass('badge bg-danger').text('High');
            } else {
                flagBadge.removeClass().addClass('badge bg-success').text('Normal');
            }
        }
        
        return true;
    });
    
    // Form submission validation
    $('#resultsForm').on('submit', function(e) {
        var hasErrors = false;
        
        $('input[type="number"].result-value').each(function() {
            var input = $(this);
            var value = parseFloat(input.val());
            var minPossible = parseFloat(input.data('min-possible'));
            var maxPossible = parseFloat(input.data('max-possible'));
            
            if (!isNaN(value)) {
                if ((!isNaN(minPossible) && value < minPossible) || 
                    (!isNaN(maxPossible) && value > maxPossible)) {
                    input.addClass('is-invalid');
                    hasErrors = true;
                }
            }
        });
        
        if (hasErrors) {
            e.preventDefault();
            alert('Some values are outside the allowed range. Please correct them before submitting.');
            return false;
        }
        
        return true;
    });
    
    // Real-time validation while typing
    $('input[type="number"].result-value').on('input', function() {
        var input = $(this);
        var value = parseFloat(input.val());
        var minPossible = parseFloat(input.data('min-possible'));
        var maxPossible = parseFloat(input.data('max-possible'));
        
        // Only validate if we have a valid number
        if (!isNaN(value)) {
            if ((!isNaN(minPossible) && value < minPossible) || 
                (!isNaN(maxPossible) && value > maxPossible)) {
                input.css('border-color', '#dc3545');
                input.css('background-color', '#fff8f8');
            } else {
                input.css('border-color', '#28a745');
                input.css('background-color', '#f8fff9');
            }
        } else {
            input.css('border-color', '');
            input.css('background-color', '');
        }
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>