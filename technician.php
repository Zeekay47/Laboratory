<?php
$page_title = 'Technician Dashboard';
require_once 'includes/header.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';

$auth = new Auth();
$auth->requireRole('technician');

$db = new Database();
?>

<div class="row pb-1">
    <div class="col-md-3">
        <div class="card text-white bg-primary mb-3 h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title">Pending Tests</h5>
                        <?php
                        $db->query('SELECT COUNT(*) as count FROM order_tests WHERE status = "sample-collected"');
                        $result = $db->single();
                        ?>
                        <h2><?php echo $result['count']; ?></h2>
                    </div>
                    <i class="bi bi-hourglass-split" style="font-size: 3rem;"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card text-white bg-warning mb-3 h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title">In Progress</h5>
                        <?php
                        $db->query('SELECT COUNT(*) as count FROM order_tests WHERE status = "processing"');
                        $result = $db->single();
                        ?>
                        <h2><?php echo $result['count']; ?></h2>
                    </div>
                    <i class="bi bi-gear" style="font-size: 3rem;"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card text-white bg-success mb-3 h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title">Today's Completed</h5>
                        <?php
                        // Count tests with results entered today
                        $db->query('SELECT COUNT(DISTINCT order_test_id) as count 
                                   FROM test_results 
                                   WHERE DATE(entered_at) = CURDATE()');
                        $result = $db->single();
                        ?>
                        <h2><?php echo $result['count']; ?></h2>
                    </div>
                    <i class="bi bi-check-circle" style="font-size: 3rem;"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card text-white bg-info mb-3 h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title">Urgent Tests</h5>
                        <?php
                        $db->query('SELECT COUNT(*) as count FROM order_tests 
                                   WHERE priority = "urgent"
                                   OR status IN ("sample-collected", "processing")');
                        $result = $db->single();
                        ?>
                        <h2><?php echo $result['count']; ?></h2>
                    </div>
                    <i class="bi bi-exclamation-triangle" style="font-size: 3rem;"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h5>Recent Test Activity</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Sample ID</th>
                                <th>Test</th>
                                <th>Patient</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Get recent activities - first try to get from test_results, then from orders
                            $db->query('
                                SELECT 
                                    ot.id,
                                    ot.sample_id,
                                    ot.status,
                                    ot.priority,
                                    t.test_name,
                                    p.full_name,
                                    CASE 
                                        WHEN tr.entered_at IS NOT NULL THEN tr.entered_at
                                        ELSE o.order_date
                                    END as activity_time
                                FROM order_tests ot
                                JOIN tests t ON ot.test_id = t.id
                                JOIN orders o ON ot.order_id = o.id
                                JOIN patients p ON o.patient_id = p.id
                                LEFT JOIN (
                                    SELECT order_test_id, MAX(entered_at) as entered_at 
                                    FROM test_results 
                                    GROUP BY order_test_id
                                ) tr ON ot.id = tr.order_test_id
                                WHERE ot.status IN ("processing", "results-entered")
                                ORDER BY activity_time DESC 
                                LIMIT 10
                            ');
                            $activities = $db->resultSet();
                            
                            if (empty($activities)): ?>
                                <tr>
                                    <td colspan="6" class="text-center">No recent activity</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($activities as $activity): ?>
                                <tr>
                                    <td>
                                        <?php 
                                        if (!empty($activity['activity_time'])) {
                                            echo date('h:i A', strtotime($activity['activity_time']));
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td><code><?php echo $activity['sample_id']; ?></code></td>
                                    <td><?php echo $activity['test_name']; ?></td>
                                    <td><?php echo $activity['full_name']; ?></td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            switch($activity['status']) {
                                                case 'processing': echo 'warning'; break;
                                                case 'results-entered': echo 'primary'; break;
                                                case 'sample-collected': echo 'info'; break;
                                                default: echo 'secondary';
                                            }
                                        ?>">
                                            <?php echo $activity['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($activity['status'] == 'processing'): ?>
                                            <a href="enter_results.php?sample_id=<?php echo $activity['sample_id']; ?>" 
                                               class="btn btn-sm btn-primary">
                                                Enter Results
                                            </a>
                                        <?php elseif ($activity['status'] == 'results-entered'): ?>
                                            <a href="view_results.php?sample_id=<?php echo $activity['sample_id']; ?>" 
                                               class="btn btn-sm btn-info">
                                                View Results
                                            </a>
                                        <?php else: ?>
                                            <a href="enter_results.php?sample_id=<?php echo $activity['sample_id']; ?>" 
                                               class="btn btn-sm btn-secondary">
                                                Process
                                            </a>
                                        <?php endif; ?>
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
    
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h5>Quick Actions</h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="pending_tests.php" class="btn btn-primary btn-lg">
                        <i class="bi bi-hourglass-split"></i> View Pending Tests
                    </a>
                    <a href="enter_results.php" class="btn btn-success btn-lg">
                        <i class="bi bi-clipboard-data"></i> Enter Test Results
                    </a>
                    <form method="GET" action="enter_results.php" class="mt-2">
                        <div class="input-group">
                            <input type="text" class="form-control" name="sample_id" 
                                   placeholder="Enter Sample ID">
                            <button type="submit" class="btn btn-info">
                                <i class="bi bi-search"></i> Go
                            </button>
                        </div>
                    </form>
                </div>
                
                <hr>
                
                <h6>Test Categories</h6>
                <div class="list-group">
                    <?php
                    $db->query('SELECT DISTINCT t.category, COUNT(ot.id) as count
                               FROM order_tests ot
                               JOIN tests t ON ot.test_id = t.id
                               WHERE ot.status IN ("sample-collected", "processing")
                               GROUP BY t.category');
                    $categories = $db->resultSet();
                    
                    if (empty($categories)): ?>
                        <div class="alert alert-info">No pending tests by category</div>
                    <?php else: ?>
                        <?php foreach ($categories as $category): ?>
                        <a href="#" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                            <?php echo $category['category']; ?>
                            <span class="badge bg-primary rounded-pill"><?php echo $category['count']; ?></span>
                        </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>