<?php
$page_title = 'Test Catalog Management';
require_once 'includes/header.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';

$auth = new Auth();
$auth->requireRole('manager');

$db = new Database();
$message = '';

// Get all tests for listing
$db->query('SELECT t.*, 
           (SELECT COUNT(*) FROM test_parameters WHERE test_id = t.id AND is_active = 1) as parameter_count,
           (SELECT COUNT(*) FROM order_tests WHERE test_id = t.id) as usage_count
           FROM tests t 
           ORDER BY t.category, t.test_name');
$tests = $db->resultSet();

// Group tests by category
$tests_by_category = [];
foreach ($tests as $test) {
    $tests_by_category[$test['category']][] = $test;
}

// Get test details for editing
$edit_test = null;
$test_parameters = [];
if (isset($_GET['edit'])) {
    $test_id = $_GET['edit'];
    $db->query('SELECT * FROM tests WHERE id = :id');
    $db->bind(':id', $test_id);
    $edit_test = $db->single();
    
    if ($edit_test) {
        $db->query('SELECT * FROM test_parameters WHERE test_id = :test_id ORDER BY sort_order');
        $db->bind(':test_id', $test_id);
        $test_parameters = $db->resultSet();
    }
}

// Get parameter details for editing (if requested)
$edit_parameter = null;
if (isset($_GET['edit_parameter'])) {
    $param_id = $_GET['edit_parameter'];
    $db->query('SELECT * FROM test_parameters WHERE id = :id');
    $db->bind(':id', $param_id);
    $edit_parameter = $db->single();
}

// Check if we should show the add test form
$show_add_test_form = isset($_GET['add_test']) || $edit_test;

// Check if we should show the add parameter form
$show_add_parameter_form = isset($_GET['add_parameter']) || isset($_GET['edit_parameter']);

// Handle test creation/update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_test'])) {
        // Add new test
        $test_code = strtoupper(trim($_POST['test_code']));
        $test_name = trim($_POST['test_name']);
        $category = trim($_POST['category']);
        $sample_type = trim($_POST['sample_type']);
        $fasting_required = isset($_POST['fasting_required']) ? 1 : 0;
        $turnaround_hours = (int)$_POST['turnaround_hours'];
        $instructions = trim($_POST['instructions']);
        
        // Check if test code already exists
        $db->query('SELECT id FROM tests WHERE test_code = :code');
        $db->bind(':code', $test_code);
        $existing = $db->single();
        
        if ($existing) {
            $message = '<div class="alert alert-danger">Test code already exists!</div>';
        } else {
            $db->query('INSERT INTO tests (test_code, test_name, category, sample_type, 
                          fasting_required, turnaround_hours, instructions) 
                       VALUES (:code, :name, :category, :sample_type, :fasting, 
                               :turnaround, :instructions)');
            $db->bind(':code', $test_code);
            $db->bind(':name', $test_name);
            $db->bind(':category', $category);
            $db->bind(':sample_type', $sample_type);
            $db->bind(':fasting', $fasting_required);
            $db->bind(':turnaround', $turnaround_hours);
            $db->bind(':instructions', $instructions);
            
            if ($db->execute()) {
                $test_id = $db->lastInsertId();
                $message = '<div class="alert alert-success">Test added successfully! 
                           <a href="test_catalog.php?edit=' . $test_id . '#parameters">Add parameters now</a></div>';
            } else {
                $message = '<div class="alert alert-danger">Failed to add test.</div>';
            }
        }
    } 
    elseif (isset($_POST['update_test'])) {
        // Update test
        $test_id = $_POST['test_id'];
        $test_name = trim($_POST['test_name']);
        $category = trim($_POST['category']);
        $sample_type = trim($_POST['sample_type']);
        $fasting_required = isset($_POST['fasting_required']) ? 1 : 0;
        $turnaround_hours = (int)$_POST['turnaround_hours'];
        $instructions = trim($_POST['instructions']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        $db->query('UPDATE tests SET 
                   test_name = :name, category = :category, sample_type = :sample_type,
                   fasting_required = :fasting, turnaround_hours = :turnaround,
                   instructions = :instructions, is_active = :active
                   WHERE id = :id');
        
        $db->bind(':name', $test_name);
        $db->bind(':category', $category);
        $db->bind(':sample_type', $sample_type);
        $db->bind(':fasting', $fasting_required);
        $db->bind(':turnaround', $turnaround_hours);
        $db->bind(':instructions', $instructions);
        $db->bind(':active', $is_active);
        $db->bind(':id', $test_id);
        
        if ($db->execute()) {
            // Redirect to main catalog (remove edit parameter)
            header('Location: test_catalog.php');
            exit();
        }
    }
    elseif (isset($_POST['add_parameter'])) {
        // Add test parameter
        $test_id = $_POST['test_id'];
        $parameter_name = trim($_POST['parameter_name']);
        $parameter_code = strtoupper(trim($_POST['parameter_code']));
        $unit = trim($_POST['unit']);
        $parameter_options = trim($_POST['parameter_options']);
        $normal_min = $_POST['normal_min'] ?: null;
        $normal_max = $_POST['normal_max'] ?: null;
        $male_min = $_POST['male_min'] ?: null;
        $male_max = $_POST['male_max'] ?: null;
        $female_min = $_POST['female_min'] ?: null;
        $female_max = $_POST['female_max'] ?: null;
        $min_possible = $_POST['min_possible'] ?: null;
        $max_possible = $_POST['max_possible'] ?: null;
        $sort_order = (int)$_POST['sort_order'];
        
        $db->query('INSERT INTO test_parameters 
                   (test_id, parameter_name, parameter_code, unit, parameter_options, normal_min, normal_max,
                    male_min, male_max, female_min, female_max, min_possible, max_possible, sort_order)
                   VALUES (:test_id, :name, :code, :unit, :options, :nmin, :nmax,
                           :mmin, :mmax, :fmin, :fmax, :min_possible, :max_possible, :sort)');
        
        $db->bind(':test_id', $test_id);
        $db->bind(':name', $parameter_name);
        $db->bind(':code', $parameter_code);
        $db->bind(':unit', $unit);
        $db->bind(':options', $parameter_options);
        $db->bind(':nmin', $normal_min);
        $db->bind(':nmax', $normal_max);
        $db->bind(':mmin', $male_min);
        $db->bind(':mmax', $male_max);
        $db->bind(':fmin', $female_min);
        $db->bind(':fmax', $female_max);
        $db->bind(':min_possible', $min_possible);
        $db->bind(':max_possible', $max_possible);
        $db->bind(':sort', $sort_order);
        
        if ($db->execute()) {
            // Stay on same page but refresh to show new parameter
            header('Location: test_catalog.php?edit=' . $test_id . '#parameters');
            exit();
        }
    }
    elseif (isset($_POST['update_parameter'])) {
        // Update test parameter
        $parameter_id = $_POST['parameter_id'];
        $test_id = $_POST['test_id'];
        $parameter_name = trim($_POST['parameter_name']);
        $parameter_code = strtoupper(trim($_POST['parameter_code']));
        $unit = trim($_POST['unit']);
        $parameter_options = trim($_POST['parameter_options']);
        $normal_min = $_POST['normal_min'] ?: null;
        $normal_max = $_POST['normal_max'] ?: null;
        $male_min = $_POST['male_min'] ?: null;
        $male_max = $_POST['male_max'] ?: null;
        $female_min = $_POST['female_min'] ?: null;
        $female_max = $_POST['female_max'] ?: null;
        $min_possible = $_POST['min_possible'] ?: null;
        $max_possible = $_POST['max_possible'] ?: null;
        $sort_order = (int)$_POST['sort_order'];
        
        $db->query('UPDATE test_parameters SET 
                   parameter_name = :name, parameter_code = :code, unit = :unit, 
                   parameter_options = :options, normal_min = :nmin, normal_max = :nmax,
                   male_min = :mmin, male_max = :mmax, female_min = :fmin, female_max = :fmax,
                   min_possible = :min_possible, max_possible = :max_possible, sort_order = :sort
                   WHERE id = :id');
        
        $db->bind(':name', $parameter_name);
        $db->bind(':code', $parameter_code);
        $db->bind(':unit', $unit);
        $db->bind(':options', $parameter_options);
        $db->bind(':nmin', $normal_min);
        $db->bind(':nmax', $normal_max);
        $db->bind(':mmin', $male_min);
        $db->bind(':mmax', $male_max);
        $db->bind(':fmin', $female_min);
        $db->bind(':fmax', $female_max);
        $db->bind(':min_possible', $min_possible);
        $db->bind(':max_possible', $max_possible);
        $db->bind(':sort', $sort_order);
        $db->bind(':id', $parameter_id);
        
        if ($db->execute()) {
            // Redirect to remove edit_parameter from URL
            header('Location: test_catalog.php?edit=' . $test_id . '#parameters');
            exit();
        }
    }
}

// Handle delete actions
if (isset($_GET['delete_test'])) {
    $test_id = $_GET['delete_test'];
    
    // Check if test has orders
    $db->query('SELECT COUNT(*) as count FROM order_tests WHERE test_id = :id');
    $db->bind(':id', $test_id);
    $has_orders = $db->single()['count'] > 0;
    
    if ($has_orders) {
        $message = '<div class="alert alert-danger">Cannot delete test that has existing orders. Deactivate instead.</div>';
    } else {
        $db->query('DELETE FROM tests WHERE id = :id');
        $db->bind(':id', $test_id);
        if ($db->execute()) {
            header('Location: test_catalog.php');
            exit();
        }
    }
}

if (isset($_GET['delete_parameter'])) {
    $param_id = $_GET['delete_parameter'];
    $test_id = $_GET['edit'] ?? '';
    $db->query('DELETE FROM test_parameters WHERE id = :id');
    $db->bind(':id', $param_id);
    if ($db->execute()) {
        if ($test_id) {
            header('Location: test_catalog.php?edit=' . $test_id . '#parameters');
            exit();
        }
    }
}
?>

<div class="row">
    <div class="col-md-12">
        <?php echo $message; ?>
        
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5>Test Catalog Management</h5>
            </div>
            <div class="card-body">
                <!-- Add New Test Button (Only show when NOT editing a test) -->
                <?php if (!$edit_test && !$show_add_test_form): ?>
                <div class="text-end mb-3">
                    <a href="test_catalog.php?add_test=1" class="btn btn-success">
                        <i class="bi bi-plus-circle"></i> Add New Test
                    </a>
                </div>
                <?php endif; ?>

                <!-- Add/Edit Test Form (Only show when adding or editing a test) -->
                <?php if ($show_add_test_form): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h6><?php echo $edit_test ? 'Edit Test: ' . $edit_test['test_name'] : 'Add New Test'; ?></h6>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <?php if ($edit_test): ?>
                                <input type="hidden" name="test_id" value="<?php echo $edit_test['id']; ?>">
                            <?php endif; ?>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="test_code" class="form-label">Test Code *</label>
                                        <input type="text" class="form-control" id="test_code" 
                                               name="test_code" value="<?php echo $edit_test['test_code'] ?? ''; ?>"
                                               <?php echo $edit_test ? 'readonly' : 'required'; ?>>
                                        <small class="text-muted">Unique code for the test (e.g., CBC, LFT)</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="test_name" class="form-label">Test Name *</label>
                                        <input type="text" class="form-control" id="test_name" 
                                               name="test_name" value="<?php echo $edit_test['test_name'] ?? ''; ?>" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="category" class="form-label">Category *</label>
                                        <input type="text" class="form-control" id="category" 
                                               name="category" value="<?php echo $edit_test['category'] ?? ''; ?>" required>
                                        <small class="text-muted">e.g., Hematology, Biochemistry</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="sample_type" class="form-label">Sample Type *</label>
                                        <input type="text" class="form-control" id="sample_type" 
                                               name="sample_type" value="<?php echo $edit_test['sample_type'] ?? ''; ?>" required>
                                        <small class="text-muted">e.g., Blood, Urine, Serum</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="turnaround_hours" class="form-label">Turnaround Time (hours) *</label>
                                        <input type="number" class="form-control" id="turnaround_hours" 
                                               name="turnaround_hours" value="<?php echo $edit_test['turnaround_hours'] ?? 24; ?>" 
                                               min="1" max="168" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="instructions" class="form-label">Patient Instructions</label>
                                        <textarea class="form-control" id="instructions" name="instructions" 
                                                  rows="2"><?php echo $edit_test['instructions'] ?? ''; ?></textarea>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <div class="form-check mt-4">
                                            <input class="form-check-input" type="checkbox" id="fasting_required" 
                                                   name="fasting_required" value="1"
                                                   <?php echo ($edit_test['fasting_required'] ?? 0) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="fasting_required">
                                                Fasting Required
                                            </label>
                                        </div>
                                        <?php if ($edit_test): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="is_active" 
                                                   name="is_active" value="1"
                                                   <?php echo ($edit_test['is_active'] ?? 1) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="is_active">
                                                Test is Active
                                            </label>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex">
                                <?php if ($edit_test): ?>
                                    <button type="submit" name="update_test" class="btn btn-primary">
                                        <i class="bi bi-save"></i> Update Test
                                    </button>
                                    <a href="test_catalog.php" class="btn btn-outline-secondary">
                                        <i class="bi bi-x-circle"></i> Cancel Edit
                                    </a>
                                <?php else: ?>
                                    <button type="submit" name="add_test" class="btn btn-success">
                                        <i class="bi bi-plus-circle"></i> Add Test
                                    </button>
                                    <a href="test_catalog.php" class="btn btn-outline-secondary">
                                        <i class="bi bi-x-circle"></i> Cancel
                                    </a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Test Catalog List (Only show when NOT adding or editing a test) -->
                <?php if (!$edit_test && !$show_add_test_form): ?>
                <div class="card">
                    <div class="card-header">
                        <h6>Test Catalog (<?php echo count($tests); ?> tests)</h6>
                    </div>
                    <div class="card-body">
                        <?php foreach ($tests_by_category as $category => $category_tests): ?>
                        <h6 class="mt-3 border-bottom pb-2"><?php echo $category; ?></h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Test Code</th>
                                        <th>Test Name</th>
                                        <th>Sample Type</th>
                                        <th>Turnaround</th>
                                        <th>Parameters</th>
                                        <th>Usage</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($category_tests as $test): ?>
                                    <tr class="<?php echo !$test['is_active'] ? 'table-secondary' : ''; ?>">
                                        <td><strong><?php echo $test['test_code']; ?></strong></td>
                                        <td><?php echo $test['test_name']; ?></td>
                                        <td><?php echo $test['sample_type']; ?></td>
                                        <td><?php echo $test['turnaround_hours']; ?> hours</td>
                                        <td>
                                            <span class="badge bg-info"><?php echo $test['parameter_count']; ?> params</span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $test['usage_count'] > 0 ? 'warning' : 'secondary'; ?>">
                                                <?php echo $test['usage_count']; ?> orders
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($test['is_active']): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="test_catalog.php?edit=<?php echo $test['id']; ?>" 
                                                   class="btn btn-outline-primary">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <?php if ($test['usage_count'] == 0): ?>
                                                    <a href="test_catalog.php?delete_test=<?php echo $test['id']; ?>" 
                                                       class="btn btn-outline-danger"
                                                       onclick="return confirm('Delete this test permanently?')">
                                                        <i class="bi bi-trash"></i>
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
                <?php endif; ?>

                <!-- Test Parameters Section (Only when editing a test) -->
                <?php if ($edit_test): ?>
                <div class="card mt-4" id="parameters">
                    <div class="card-header bg-info text-white">
                        <h6>Test Parameters for: <?php echo $edit_test['test_name']; ?></h6>
                    </div>
                    <div class="card-body">
                        <!-- Add New Parameter Button (Only show when NOT adding/editing a parameter) -->
                        <?php if (!$show_add_parameter_form): ?>
                        <div class="text-end mb-3">
                            <a href="test_catalog.php?edit=<?php echo $edit_test['id']; ?>&add_parameter=1#parameters" 
                               class="btn btn-success">
                                <i class="bi bi-plus-circle"></i> Add New Parameter
                            </a>
                        </div>
                        <?php endif; ?>

                        <!-- Add/Edit Parameter Form (Only show when adding or editing a parameter) -->
                        <?php if ($show_add_parameter_form): ?>
                        <div class="card mb-4">
                            <div class="card-body">
                                <h6><?php echo isset($_GET['edit_parameter']) ? 'Edit Parameter' : 'Add New Parameter'; ?></h6>
                                <form method="POST" action="">
                                    <input type="hidden" name="test_id" value="<?php echo $edit_test['id']; ?>">
                                    <?php if (isset($_GET['edit_parameter'])): ?>
                                        <input type="hidden" name="parameter_id" value="<?php echo $_GET['edit_parameter']; ?>">
                                    <?php endif; ?>
                                    
                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="mb-3">
                                                <label for="parameter_name" class="form-label">Parameter Name *</label>
                                                <input type="text" class="form-control" id="parameter_name" 
                                                       name="parameter_name" value="<?php echo $edit_parameter['parameter_name'] ?? ''; ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="mb-3">
                                                <label for="parameter_code" class="form-label">Code *</label>
                                                <input type="text" class="form-control" id="parameter_code" 
                                                       name="parameter_code" value="<?php echo $edit_parameter['parameter_code'] ?? ''; ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="mb-3">
                                                <label for="unit" class="form-label">Unit</label>
                                                <input type="text" class="form-control" id="unit" name="unit"
                                                       value="<?php echo $edit_parameter['unit'] ?? ''; ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="mb-3">
                                                <label for="parameter_options" class="form-label">Dropdown Options</label>
                                                <input type="text" class="form-control" id="parameter_options" 
                                                       name="parameter_options" 
                                                       placeholder="Comma-separated options"
                                                       value="<?php echo $edit_parameter['parameter_options'] ?? ''; ?>">
                                                <small class="text-muted">For qualitative parameters (e.g., Positive,Negative)</small>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="mb-3">
                                                <label for="sort_order" class="form-label">Sort Order</label>
                                                <input type="number" class="form-control" id="sort_order" 
                                                       name="sort_order" value="<?php echo $edit_parameter['sort_order'] ?? 0; ?>">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <h6 class="mt-3">Normal Ranges</h6>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="mb-3">
                                                <label for="normal_min" class="form-label">Normal Min</label>
                                                <input type="number" step="0.01" class="form-control" 
                                                       id="normal_min" name="normal_min"
                                                       value="<?php echo $edit_parameter['normal_min'] ?? ''; ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="mb-3">
                                                <label for="normal_max" class="form-label">Normal Max</label>
                                                <input type="number" step="0.01" class="form-control" 
                                                       id="normal_max" name="normal_max"
                                                       value="<?php echo $edit_parameter['normal_max'] ?? ''; ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="mb-3">
                                                <label for="male_min" class="form-label">Male Min</label>
                                                <input type="number" step="0.01" class="form-control" 
                                                       id="male_min" name="male_min"
                                                       value="<?php echo $edit_parameter['male_min'] ?? ''; ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="mb-3">
                                                <label for="male_max" class="form-label">Male Max</label>
                                                <input type="number" step="0.01" class="form-control" 
                                                       id="male_max" name="male_max"
                                                       value="<?php echo $edit_parameter['male_max'] ?? ''; ?>">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="mb-3">
                                                <label for="female_min" class="form-label">Female Min</label>
                                                <input type="number" step="0.01" class="form-control" 
                                                       id="female_min" name="female_min"
                                                       value="<?php echo $edit_parameter['female_min'] ?? ''; ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="mb-3">
                                                <label for="female_max" class="form-label">Female Max</label>
                                                <input type="number" step="0.01" class="form-control" 
                                                       id="female_max" name="female_max"
                                                       value="<?php echo $edit_parameter['female_max'] ?? ''; ?>">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <h6 class="mt-3">Input Validation Limits</h6>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="mb-3">
                                                <label for="min_possible" class="form-label">Minimum Possible Value *</label>
                                                <input type="number" step="0.01" class="form-control" 
                                                       id="min_possible" name="min_possible"
                                                       value="<?php echo $edit_parameter['min_possible'] ?? ''; ?>" required>
                                                <small class="text-muted">Lowest allowed value in UI input</small>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="mb-3">
                                                <label for="max_possible" class="form-label">Maximum Possible Value *</label>
                                                <input type="number" step="0.01" class="form-control" 
                                                       id="max_possible" name="max_possible"
                                                       value="<?php echo $edit_parameter['max_possible'] ?? ''; ?>" required>
                                                <small class="text-muted">Highest allowed value in UI input</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">&nbsp;</label>
                                                <div class="d-grid gap-2 d-md-flex">
                                                    <?php if (isset($_GET['edit_parameter'])): ?>
                                                        <button type="submit" name="update_parameter" class="btn btn-primary">
                                                            <i class="bi bi-save"></i> Update Parameter
                                                        </button>
                                                        <a href="test_catalog.php?edit=<?php echo $edit_test['id']; ?>" 
                                                           class="btn btn-outline-secondary">
                                                            <i class="bi bi-x-circle"></i> Cancel
                                                        </a>
                                                    <?php else: ?>
                                                        <button type="submit" name="add_parameter" class="btn btn-success">
                                                            <i class="bi bi-plus-circle"></i> Add Parameter
                                                        </button>
                                                        <a href="test_catalog.php?edit=<?php echo $edit_test['id']; ?>" 
                                                           class="btn btn-outline-secondary">
                                                            <i class="bi bi-x-circle"></i> Cancel
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="alert alert-info mt-2">
                                        <small>
                                            <i class="bi bi-info-circle"></i> 
                                            <strong>Example values:</strong><br>
                                            • Hemoglobin: 0-30 g/dL<br>
                                            • WBC: 0-50 ×10⁹/L<br>
                                            • Glucose: 0-1000 mg/dL<br>
                                            • For dropdown parameters, use 0-100
                                        </small>
                                    </div>
                                </form>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Existing Parameters -->
                        <h6>Existing Parameters (<?php echo count($test_parameters); ?>)</h6>
                        <?php if (empty($test_parameters)): ?>
                            <div class="alert alert-info">No parameters defined for this test.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Parameter Name</th>
                                            <th>Code</th>
                                            <th>Unit</th>
                                            <th>Type</th>
                                            <th>Normal Range</th>
                                            <th>Male Range</th>
                                            <th>Female Range</th>
                                            <th>Input Limits</th>
                                            <th>Sort</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($test_parameters as $param): ?>
                                        <tr>
                                            <td><?php echo $param['parameter_name']; ?></td>
                                            <td><code><?php echo $param['parameter_code']; ?></code></td>
                                            <td><?php echo $param['unit']; ?></td>
                                            <td>
                                                <?php if (!empty($param['parameter_options'])): ?>
                                                    <span class="badge bg-info">Dropdown</span><br>
                                                    <small><?php echo $param['parameter_options']; ?></small>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Numeric</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($param['normal_min'] !== null): ?>
                                                    <?php echo $param['normal_min']; ?> - <?php echo $param['normal_max']; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($param['male_min'] !== null): ?>
                                                    <?php echo $param['male_min']; ?> - <?php echo $param['male_max']; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($param['female_min'] !== null): ?>
                                                    <?php echo $param['female_min']; ?> - <?php echo $param['female_max']; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($param['min_possible'] !== null && $param['max_possible'] !== null): ?>
                                                    <span class="badge bg-warning"><?php echo $param['min_possible']; ?> - <?php echo $param['max_possible']; ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Not set</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo $param['sort_order']; ?></td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="test_catalog.php?edit=<?php echo $edit_test['id']; ?>&edit_parameter=<?php echo $param['id']; ?>#parameters" 
                                                       class="btn btn-outline-primary">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    <a href="test_catalog.php?delete_parameter=<?php echo $param['id']; ?>&edit=<?php echo $edit_test['id']; ?>" 
                                                       class="btn btn-outline-danger"
                                                       onclick="return confirm('Delete this parameter?')">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>