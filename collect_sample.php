<?php
// Start session and include only necessary files
session_start();

// Define database constants if not defined
if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'dtc_lab');
    define('DB_USER', 'root');
    define('DB_PASS', '');
}

require_once 'includes/Database.php';
require_once 'includes/Auth.php';

$auth = new Auth();

// Check if user is logged in and has correct role
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    echo '<div class="alert alert-danger">Access denied. Please log in.</div>';
    echo '<button onclick="window.parent.postMessage(\'closeModal\', \'*\')" class="btn btn-secondary">Close</button>';
    exit();
}

// Check if user has receptionist role
if ($_SESSION['role'] != 'receptionist') {
    echo '<div class="alert alert-danger">Access denied. Receptionist role required.</div>';
    echo '<button onclick="window.parent.postMessage(\'closeModal\', \'*\')" class="btn btn-secondary">Close</button>';
    exit();
}

$db = new Database();

$order_id = $_GET['order_id'] ?? $_POST['order_id'] ?? '';

if (!$order_id) {
    echo '<div class="alert alert-danger">Order ID is required!</div>';
    echo '<button onclick="window.parent.postMessage(\'closeModal\', \'*\')" class="btn btn-secondary">Close</button>';
    exit();
}

// Get order details
$db->query('SELECT o.*, p.full_name, p.phone, p.age, p.gender 
            FROM orders o 
            JOIN patients p ON o.patient_id = p.id
            WHERE o.id = :order_id');
$db->bind(':order_id', $order_id);
$order = $db->single();

if (!$order) {
    echo '<div class="alert alert-danger">Order not found!</div>';
    echo '<button onclick="window.parent.postMessage(\'closeModal\', \'*\')" class="btn btn-secondary">Close</button>';
    exit();
}

// Handle sample collection
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['collect_samples'])) {
    $collected_samples = $_POST['collected_samples'] ?? [];
    
    if (empty($collected_samples)) {
        echo '<div class="alert alert-warning">No samples selected for collection!</div>';
    } else {
        try {
            $db->query('BEGIN TRANSACTION');
            
            // Update each selected sample
            foreach ($collected_samples as $sample_id) {
                $db->query('UPDATE order_tests SET status = "sample-collected", 
                           sample_collected_at = NOW() 
                           WHERE sample_id = :sample_id');
                $db->bind(':sample_id', $sample_id);
                $db->execute();
            }
            
            // Check if all samples in this order are now collected
            $db->query('SELECT COUNT(*) as total, 
                               SUM(CASE WHEN status = "sample-collected" THEN 1 ELSE 0 END) as collected
                        FROM order_tests 
                        WHERE order_id = :order_id');
            $db->bind(':order_id', $order_id);
            $status = $db->single();
            
            // Update order status if all samples collected
            if ($status['total'] == $status['collected']) {
                $db->query('UPDATE orders SET status = "sample-collected", 
                           collected_by = :collected_by 
                           WHERE id = :id');
                $db->bind(':collected_by', $_SESSION['user_id']);
                $db->bind(':id', $order_id);
                $db->execute();
            } else {
                // Update order status to indicate partial collection
                $db->query('UPDATE orders SET status = "sample-collected" WHERE id = :id');
                $db->bind(':id', $order_id);
                $db->execute();
            }
            
            $db->query('COMMIT');
            
            // Show success message
            echo '<!DOCTYPE html>
            <html>
            <head>
                <title>Sample Collection</title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
                <style>
                    body { padding: 20px; background-color: #f8f9fa; }
                </style>
            </head>
            <body>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle"></i>
                    <strong>Success!</strong> Selected samples collected successfully.
                </div>
                <div class="text-center mt-3">
                    <button onclick="window.parent.postMessage(\'closeModal\', \'*\')" class="btn btn-success">
                        <i class="bi bi-check"></i> Done
                    </button>
                </div>
                <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
                <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
            </body>
            </html>';
            exit();
            
        } catch (Exception $e) {
            $db->query('ROLLBACK');
            echo '<div class="alert alert-danger">Error collecting samples: ' . $e->getMessage() . '</div>';
        }
    }
}

// Get all samples for this order
$db->query('SELECT ot.sample_id, ot.sample_type, ot.status, 
                   GROUP_CONCAT(DISTINCT t.test_name ORDER BY t.test_name SEPARATOR ", ") as test_names,
                   COUNT(DISTINCT t.id) as test_count
            FROM order_tests ot
            JOIN tests t ON ot.test_id = t.id
            WHERE ot.order_id = :order_id
            GROUP BY ot.sample_id, ot.sample_type, ot.status
            ORDER BY ot.sample_id');
$db->bind(':order_id', $order_id);
$samples = $db->resultSet();

$total_samples = count($samples);
$collected_samples = 0;
foreach ($samples as $sample) {
    if ($sample['status'] == 'sample-collected') {
        $collected_samples++;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Collect Samples - Order <?php echo $order['order_number']; ?></title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            padding: 15px;
            background-color: #f8f9fa;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        
        .card {
            border: none;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            margin-bottom: 1rem;
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .table tbody tr:hover {
            background-color: rgba(0,123,255,0.05);
        }
        
        .form-check-input {
            margin-top: 0;
            cursor: pointer;
        }
        
        .form-check-input:checked {
            background-color: #198754;
            border-color: #198754;
        }
        
        .badge {
            font-weight: 500;
        }
        
        .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            font-weight: 600;
        }
        
        .alert {
            border-radius: 0.375rem;
        }
        
        .btn {
            border-radius: 0.375rem;
        }
        
        .table th {
            font-weight: 600;
            background-color: #f8f9fa;
        }
        
        .table-success {
            background-color: rgba(25, 135, 84, 0.05) !important;
        }
        
        .table-success:hover {
            background-color: rgba(25, 135, 84, 0.1) !important;
        }
        
        /* Make clickable rows more obvious */
        tbody tr:not(.table-success) {
            cursor: pointer;
        }
        
        tbody tr:not(.table-success):hover {
            background-color: rgba(0, 123, 255, 0.1);
        }
        
        /* Disable click on already collected rows */
        tbody tr.table-success {
            cursor: default;
        }
    </style>
</head>
<body>
    <!-- Order Header -->
    <div class="card mb-3">
        <div class="card-header">
            <h5 class="mb-0">Collect Samples - Order: <?php echo $order['order_number']; ?></h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <p class="mb-1"><strong>Patient:</strong> <?php echo $order['full_name']; ?></p>
                    <p class="mb-1"><strong>Phone:</strong> <?php echo $order['phone']; ?></p>
                </div>
                <div class="col-md-6">
                    <p class="mb-1"><strong>Age/Gender:</strong> <?php echo $order['age']; ?> yrs / <?php echo $order['gender']; ?></p>
                    <p class="mb-0"><strong>Samples:</strong> 
                        <span class="badge bg-info"><?php echo $total_samples; ?> total</span>
                        <span class="badge bg-success"><?php echo $collected_samples; ?> collected</span>
                        <span class="badge bg-warning"><?php echo $total_samples - $collected_samples; ?> pending</span>
                    </p>
                </div>
            </div>
        </div>
    </div>
    
    <?php if (empty($samples)): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle"></i>
            No samples found for this order.
        </div>
        <div class="text-center">
            <button onclick="window.parent.postMessage('closeModal', '*')" class="btn btn-secondary">
                <i class="bi bi-x-circle"></i> Close
            </button>
        </div>
    <?php else: ?>
        <form method="POST" action="" id="collectForm">
            <input type="hidden" name="order_id" value="<?php echo $order_id; ?>">
            
            <!-- Samples Table -->
            <div class="card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">Select Samples to Collect</h6>
                        <div>
                            <div class="form-check form-check-inline mb-0">
                                <input class="form-check-input" type="checkbox" id="selectAll">
                                <label class="form-check-label" for="selectAll">Select All</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th width="5%">#</th>
                                    <th width="20%">Sample ID</th>
                                    <th width="20%">Sample Type</th>
                                    <th width="45%">Tests</th>
                                    <th width="10%">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($samples as $index => $sample): 
                                    $is_collected = $sample['status'] == 'sample-collected';
                                ?>
                                <tr class="<?php echo $is_collected ? 'table-success' : ''; ?>" 
                                    <?php if (!$is_collected): ?>data-sample-id="<?php echo $sample['sample_id']; ?>"<?php endif; ?>>
                                    <td>
                                        <?php if (!$is_collected): ?>
                                            <input type="checkbox" 
                                                   class="form-check-input sample-checkbox" 
                                                   name="collected_samples[]" 
                                                   value="<?php echo $sample['sample_id']; ?>"
                                                   id="sample_<?php echo $sample['sample_id']; ?>">
                                        <?php else: ?>
                                            <i class="bi bi-check-circle text-success"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <label for="sample_<?php echo $sample['sample_id']; ?>" class="mb-0" style="cursor: pointer;">
                                            <strong><?php echo $sample['sample_id']; ?></strong>
                                        </label>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?php echo $sample['sample_type']; ?></span>
                                    </td>
                                    <td>
                                        <small><?php echo $sample['test_names']; ?></small>
                                        <?php if ($sample['test_count'] > 1): ?>
                                            <span class="badge bg-secondary ms-1"><?php echo $sample['test_count']; ?> tests</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($is_collected): ?>
                                            <span class="badge bg-success">
                                                <i class="bi bi-check-circle"></i> Collected
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="text-muted">
                                <span id="selectedCount">0</span> of <?php echo $total_samples - $collected_samples; ?> pending samples selected
                            </span>
                        </div>
                        <div>
                            <button type="button" onclick="window.parent.postMessage('closeModal', '*')" 
                                    class="btn btn-outline-secondary me-2">
                                <i class="bi bi-x-circle"></i> Cancel
                            </button>
                            <button type="submit" name="collect_samples" class="btn btn-success">
                                <i class="bi bi-droplet"></i> Collect Selected Samples
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    <?php endif; ?>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    $(document).ready(function() {
        // Select all checkbox
        $('#selectAll').change(function() {
            var isChecked = $(this).prop('checked');
            $('.sample-checkbox').prop('checked', isChecked).trigger('change');
            updateSelectedCount();
        });
        
        // Update selected count
        function updateSelectedCount() {
            var selected = $('.sample-checkbox:checked').length;
            $('#selectedCount').text(selected);
        }
        
        // Initialize count
        updateSelectedCount();
        
        // Update count when checkboxes change
        $('.sample-checkbox').change(updateSelectedCount);
        
        // Form submission
        $('#collectForm').submit(function(e) {
            var selectedCount = $('.sample-checkbox:checked').length;
            
            if (selectedCount === 0) {
                e.preventDefault();
                alert('Please select at least one sample to collect.');
                return false;
            }
            
            return confirm('Are you sure you want to collect ' + selectedCount + ' sample(s)?');
        });
        
        // Make entire row clickable for checkboxes
        $('tbody tr[data-sample-id]').click(function(e) {
            // Don't trigger if clicking on checkbox, button, or link
            if ($(e.target).is('input, button, a, label') || 
                $(e.target).parent().is('label') ||
                $(e.target).hasClass('badge')) {
                return;
            }
            
            var checkbox = $(this).find('.sample-checkbox');
            if (checkbox.length && checkbox.is(':visible')) {
                checkbox.prop('checked', !checkbox.prop('checked')).trigger('change');
            }
        });
        
        // Prevent label clicks from triggering twice
        $('label[for^="sample_"]').click(function(e) {
            e.stopPropagation();
        });
    });
    </script>
</body>
</html>