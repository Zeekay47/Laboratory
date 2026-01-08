<?php
$page_title = 'Pending Tests';
require_once 'includes/header.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';

$auth = new Auth();
$auth->requireRole('technician');

$db = new Database();

// Handle status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['start_test'])) {
    $sample_id = $_POST['sample_id'];
    
    $db->query('UPDATE order_tests SET status = "processing" WHERE sample_id = :sample_id');
    $db->bind(':sample_id', $sample_id);
    $db->execute();
    
    // Update order status if all tests are being processed
    $db->query('SELECT order_id FROM order_tests WHERE sample_id = :sample_id');
    $db->bind(':sample_id', $sample_id);
    $order_test = $db->single();
    
    $db->query('UPDATE orders SET status = "processing" WHERE id = :id');
    $db->bind(':id', $order_test['order_id']);
    $db->execute();
    
    header('Location: enter_results.php?sample_id=' . $sample_id);
    exit();
}

// Fetch pending tests
$db->query('SELECT ot.*, t.test_name, t.test_code, t.category, t.sample_type,
                   p.full_name, p.age, p.gender, o.order_number
            FROM order_tests ot
            JOIN tests t ON ot.test_id = t.id
            JOIN orders o ON ot.order_id = o.id
            JOIN patients p ON o.patient_id = p.id
            WHERE ot.status IN ("sample-collected")
            ORDER BY ot.priority DESC, ot.id ASC');
$pending_tests = $db->resultSet();
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header bg-warning text-white">
                <h5>Pending Tests for Processing</h5>
            </div>
            <div class="card-body">
                <?php if (empty($pending_tests)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> No pending tests available for processing.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Sample ID</th>
                                    <th>Test</th>
                                    <th>Patient</th>
                                    <th>Age/Gender</th>
                                    <th>Sample Type</th>
                                    <th>Order #</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_tests as $test): ?>
                                <tr>
                                    <td><strong><?php echo $test['sample_id']; ?></strong></td>
                                    <td>
                                        <strong><?php echo $test['test_name']; ?></strong><br>
                                        <small class="text-muted"><?php echo $test['test_code']; ?></small>
                                    </td>
                                    <td><?php echo $test['full_name']; ?></td>
                                    <td><?php echo $test['age']; ?> yrs / <?php echo $test['gender']; ?></td>
                                    <td><?php echo $test['sample_type']; ?></td>
                                    <td><code><?php echo $test['order_number']; ?></code></td>
                                    <td>
                                        <span class="badge bg-<?php echo $test['priority'] == 'urgent' ? 'danger' : 'secondary'; ?>">
                                            <?php echo $test['priority']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $test['status'] == 'processing' ? 'warning' : 'info'; ?>">
                                            <?php echo $test['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($test['status'] == 'sample-collected'): ?>
                                            <form method="POST" action="">
                                                <input type="hidden" name="sample_id" value="<?php echo $test['sample_id']; ?>">
                                                <button type="submit" name="start_test" class="btn btn-primary btn-sm">
                                                    <i class="bi bi-play-circle"></i> Start Test
                                                </button>
                                            </form>
                                        <?php elseif ($test['status'] == 'processing'): ?>
                                            <a href="enter_results.php?sample_id=<?php echo $test['sample_id']; ?>" 
                                               class="btn btn-warning btn-sm">
                                                <i class="bi bi-pencil"></i> Enter Results
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Statistics -->
        <div class="row mt-4">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body text-center">
                        <h5>Total Pending</h5>
                        <?php
                        $db->query('SELECT COUNT(*) as count FROM order_tests WHERE status = "sample-collected"');
                        $result = $db->single();
                        ?>
                        <h2><?php echo $result['count']; ?></h2>
                        <small class="text-muted">Awaiting processing</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body text-center">
                        <h5>In Progress</h5>
                        <?php
                        $db->query('SELECT COUNT(*) as count FROM order_tests WHERE status = "processing"');
                        $result = $db->single();
                        ?>
                        <h2><?php echo $result['count']; ?></h2>
                        <small class="text-muted">Currently processing</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body text-center">
                        <h5>Urgent Tests</h5>
                        <?php
                        $db->query('SELECT COUNT(*) as count FROM order_tests WHERE priority = "urgent" AND status IN ("sample-collected", "processing")');
                        $result = $db->single();
                        ?>
                        <h2><?php echo $result['count']; ?></h2>
                        <small class="text-muted">Priority tests</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>