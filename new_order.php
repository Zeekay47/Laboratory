<?php
$page_title = 'Create New Order';
require_once 'includes/header.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';

$auth = new Auth();
$auth->requireRole('receptionist');

$db = new Database();
$message = '';

// Fetch tests for catalog
$db->query('SELECT * FROM tests WHERE is_active = 1 ORDER BY category, test_name');
$tests = $db->resultSet();

// Group tests by category
$tests_by_category = [];
foreach ($tests as $test) {
    $tests_by_category[$test['category']][] = $test;
}

// Handle patient selection
$patient_id = $_GET['patient_id'] ?? null;
$patient = null;
if ($patient_id) {
    $db->query('SELECT * FROM patients WHERE id = :id');
    $db->bind(':id', $patient_id);
    $patient = $db->single();
}

// Process order creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_order'])) {
    if (empty($_POST['selected_tests'])) {
        $message = '<div class="alert alert-danger">Please select at least one test.</div>';
    } else {
        try {
            $db->query('BEGIN TRANSACTION');
            
            // Generate order number - FIXED: Get max order number, not max id
            $db->query('SELECT MAX(CAST(SUBSTRING(order_number, 5) AS UNSIGNED)) as max_order_num FROM orders WHERE order_number LIKE "ORD-%"');
            $result = $db->single();
            $next_order_num = ($result['max_order_num'] ?? 0) + 1;
            $order_number = 'ORD-' . str_pad($next_order_num, 5, '0', STR_PAD_LEFT);
            
            // Create order
            $db->query('INSERT INTO orders (order_number, patient_id, referred_by, clinical_notes, collected_by, status) 
                       VALUES (:order_no, :patient_id, :referred_by, :clinical_notes, :collected_by, :status)');
            $db->bind(':order_no', $order_number);
            $db->bind(':patient_id', $_POST['patient_id']);
            $db->bind(':referred_by', $_POST['referred_by']);
            $db->bind(':clinical_notes', $_POST['clinical_notes']);
            $db->bind(':collected_by', $_SESSION['user_id']);
            $db->bind(':status', 'pending');
            
            $db->execute();
            $order_id = $db->lastInsertId();
            
            // Add selected tests to order - GROUPED BY SAMPLE TYPE
            $selected_tests = $_POST['selected_tests'];
            
            // Get all selected tests with their sample types
            if (!empty($selected_tests)) {
                // Create placeholders for IN clause
                $placeholders = implode(',', array_fill(0, count($selected_tests), '?'));
                $db->query("SELECT id, sample_type FROM tests WHERE id IN ($placeholders)");
                
                foreach ($selected_tests as $index => $test_id) {
                    $db->bind($index + 1, $test_id);
                }
                
                $test_details = $db->resultSet();
                
                // Group test IDs by sample type
                $tests_by_sample = [];
                foreach ($test_details as $test) {
                    $tests_by_sample[$test['sample_type']][] = $test['id'];
                }
                
                // Get the next sample number from existing samples
                $db->query('SELECT MAX(CAST(SUBSTRING(sample_id, 5) AS UNSIGNED)) as max_sample_num FROM order_tests WHERE sample_id LIKE "SMP-%"');
                $result = $db->single();
                $next_sample_num = ($result['max_sample_num'] ?? 0) + 1;
                
                // Insert tests grouped by sample type
                $sample_index = 0;
                foreach ($tests_by_sample as $sample_type => $test_ids) {
                    // Generate sample ID for this sample type group
                    $sample_id = 'SMP-' . str_pad($next_sample_num, 6, '0', STR_PAD_LEFT);
                    
                    // Insert all tests with this sample type using the same sample ID
                    foreach ($test_ids as $test_id) {
                        $db->query('INSERT INTO order_tests (order_id, test_id, sample_id, sample_type, status, priority) 
                                   VALUES (:order_id, :test_id, :sample_id, :sample_type, :status, :priority)');
                        $db->bind(':order_id', $order_id);
                        $db->bind(':test_id', $test_id);
                        $db->bind(':sample_id', $sample_id);
                        $db->bind(':sample_type', $sample_type);
                        $db->bind(':status', 'pending');
                        $db->bind(':priority', 'normal');
                        $db->execute();
                    }
                    
                    $next_sample_num++;
                    $sample_index++;
                }
            }
            
            // Update patient's last visit date
            $db->query('UPDATE patients SET last_visit_date = NOW() WHERE id = :id');
            $db->bind(':id', $_POST['patient_id']);
            $db->execute();
            
            $db->query('COMMIT');
            
            // Log the sample grouping for debugging
            error_log("Order #$order_number created with " . (isset($tests_by_sample) ? count($tests_by_sample) : 0) . " sample groups");
            
            // Redirect to order confirmation
            header('Location: order_confirmation.php?id=' . $order_id);
            exit();
            
        } catch (Exception $e) {
            $db->query('ROLLBACK');
            $message = '<div class="alert alert-danger">Error creating order: ' . $e->getMessage() . '</div>';
            error_log("Order creation error: " . $e->getMessage());
        }
    }
}
?>

<div class="row">
    <div class="col-md-12">
        <?php echo $message; ?>
        
        <div class="card">
            <div class="card-header">
                <h5>Create New Test Order</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" id="orderForm">
                    <!-- Patient Information -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-light">
                                    <h6>Patient Information</h6>
                                </div>
                                <div class="card-body">
                                    <?php if ($patient): ?>
                                        <input type="hidden" name="patient_id" value="<?php echo $patient['id']; ?>">
                                        <p><strong>Name:</strong> <?php echo $patient['full_name']; ?></p>
                                        <p><strong>Code:</strong> <?php echo $patient['patient_code']; ?></p>
                                        <p><strong>Phone:</strong> <?php echo $patient['phone']; ?></p>
                                        <p><strong>Age/Gender:</strong> <?php echo $patient['age']; ?> yrs / <?php echo $patient['gender']; ?></p>
                                        <a href="patients.php" class="btn btn-sm btn-outline-primary">Change Patient</a>
                                    <?php else: ?>
                                        <p class="text-muted">No patient selected</p>
                                        <a href="patients.php" class="btn btn-primary">Select Patient</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-light">
                                    <h6>Order Details</h6>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label for="referred_by" class="form-label">Referred By</label>
                                        <input type="text" class="form-control" id="referred_by" name="referred_by" 
                                               placeholder="Doctor/Clinic name (optional)">
                                    </div>
                                    <div class="mb-3">
                                        <label for="clinical_notes" class="form-label">Clinical Notes</label>
                                        <textarea class="form-control" id="clinical_notes" name="clinical_notes" 
                                                  rows="2" placeholder="Any clinical information..."></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Test Selection -->
                    <div class="row">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-header bg-light">
                                    <h6>Select Tests</h6>
                                    <small class="text-muted">Tests with same sample type will share the same sample container</small>
                                </div>
                                <div class="card-body">
                                    <div class="row" id="testSelection">
                                        <?php foreach ($tests_by_category as $category => $category_tests): ?>
                                            <div class="col-md-6 mb-4">
                                                <h6 class="border-bottom pb-2 mb-3"><?php echo $category; ?></h6>
                                                <?php foreach ($category_tests as $test): ?>
                                                    <div class="form-check mb-2 test-item">
                                                        <input class="form-check-input test-checkbox" 
                                                               type="checkbox" 
                                                               name="selected_tests[]" 
                                                               value="<?php echo $test['id']; ?>"
                                                               id="test_<?php echo $test['id']; ?>"
                                                               data-test-name="<?php echo htmlspecialchars($test['test_name']); ?>"
                                                               data-test-code="<?php echo htmlspecialchars($test['test_code']); ?>"
                                                               data-sample-type="<?php echo htmlspecialchars($test['sample_type']); ?>">
                                                        <label class="form-check-label" for="test_<?php echo $test['id']; ?>">
                                                            <strong><?php echo $test['test_name']; ?></strong>
                                                            <small class="text-muted">(<?php echo $test['test_code']; ?>)</small>
                                                            <br>
                                                            <small class="text-muted">
                                                                <span class="badge bg-info"><?php echo $test['sample_type']; ?></span>
                                                                | Turnaround: <?php echo $test['turnaround_hours']; ?> hours
                                                                <?php if ($test['fasting_required']): ?>
                                                                    | <span class="text-danger">Fasting Required</span>
                                                                <?php endif; ?>
                                                            </small>
                                                        </label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <div class="mt-3">
                                        <button type="button" class="btn btn-outline-primary btn-sm" 
                                                onclick="$('.test-checkbox').prop('checked', true); updateSelectedTests();">
                                            Select All
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" 
                                                onclick="$('.test-checkbox').prop('checked', false); updateSelectedTests();">
                                            Clear All
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Selected Tests Summary -->
                    <div class="row mt-4">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-header bg-light">
                                    <h6>Selected Tests <span id="selectedCount" class="badge bg-primary">0</span></h6>
                                </div>
                                <div class="card-body">
                                    <div id="selectedTestsList" class="mb-3">
                                        <p class="text-muted">No tests selected yet.</p>
                                    </div>
                                    
                                    <div class="d-grid">
                                        <button type="submit" name="create_order" id="createOrderBtn" class="btn btn-success btn-lg" disabled>
                                            <i class="bi bi-check-circle"></i> Create Order
                                        </button>
                                        <small id="orderBtnMessage" class="text-danger mt-1">
                                            <?php if (!$patient): ?>
                                                Please select a patient first
                                            <?php else: ?>
                                                Please select at least one test
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Function to group selected tests by sample type
    function groupTestsBySampleType() {
        var testsBySample = {};
        
        $('.test-checkbox:checked').each(function() {
            var testName = $(this).data('test-name');
            var testCode = $(this).data('test-code');
            var sampleType = $(this).data('sample-type');
            
            if (!testsBySample[sampleType]) {
                testsBySample[sampleType] = [];
            }
            
            testsBySample[sampleType].push({
                name: testName,
                code: testCode
            });
        });
        
        return testsBySample;
    }
    
    // Function to update Create Order button state
    function updateCreateOrderButton() {
        var hasPatient = <?php echo $patient ? 'true' : 'false'; ?>;
        var hasTests = $('.test-checkbox:checked').length > 0;
        var btn = $('#createOrderBtn');
        var message = $('#orderBtnMessage');
        
        if (hasPatient && hasTests) {
            btn.prop('disabled', false);
            message.hide();
        } else {
            btn.prop('disabled', true);
            message.show();
            
            if (!hasPatient) {
                message.text('Please select a patient first');
            } else if (!hasTests) {
                message.text('Please select at least one test');
            }
        }
    }
    
    // Update selected tests count and list
    function updateSelectedTests() {
        var testsBySample = groupTestsBySampleType();
        var totalCount = 0;
        
        // Calculate total tests count
        for (var sampleType in testsBySample) {
            totalCount += testsBySample[sampleType].length;
        }
        
        $('#selectedCount').text(totalCount);
        
        // Update selected tests list
        var listHtml = '';
        if (totalCount > 0) {
            listHtml = '<div class="list-group">';
            var testIndex = 1;
            
            for (var sampleType in testsBySample) {
                listHtml += '<div class="list-group-item list-group-item-secondary">';
                listHtml += '<strong><i class="bi bi-droplet"></i> ' + sampleType + '</strong>';
                listHtml += '<span class="badge bg-info ms-2">' + testsBySample[sampleType].length + ' test(s)</span>';
                listHtml += '</div>';
                
                testsBySample[sampleType].forEach(function(test) {
                    listHtml += '<div class="list-group-item d-flex justify-content-between align-items-center">';
                    listHtml += '<div>';
                    listHtml += '<strong>' + test.name + '</strong>';
                    listHtml += '<small class="text-muted ms-2">(' + test.code + ')</small>';
                    listHtml += '</div>';
                    listHtml += '<span class="badge bg-secondary">#' + (testIndex++) + '</span>';
                    listHtml += '</div>';
                });
            }
            listHtml += '</div>';
        } else {
            listHtml = '<p class="text-muted">No tests selected yet.</p>';
        }
        
        $('#selectedTestsList').html(listHtml);
        
        // Update button state
        updateCreateOrderButton();
    }
    
    // Initialize
    updateSelectedTests();
    updateCreateOrderButton();
    
    // Update on checkbox change
    $('.test-checkbox').change(function() {
        updateSelectedTests();
    });
    
    // Form submission - NO CONFIRMATION ALERT
    $('#orderForm').submit(function(e) {
        var hasTests = $('.test-checkbox:checked').length > 0;
        var hasPatient = <?php echo $patient ? 'true' : 'false'; ?>;
        
        // Final validation (redundant but safe)
        if (!hasPatient || !hasTests) {
            e.preventDefault();
            if (!hasPatient) {
                alert('Please select a patient first.');
            } else if (!hasTests) {
                alert('Please select at least one test.');
            }
            return false;
        }
        
        // Proceed with form submission - NO CONFIRMATION DIALOG
        return true;
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>