<?php
$page_title = 'Manager Dashboard';
require_once 'includes/header.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';

$auth = new Auth();
$auth->requireRole('manager');

$db = new Database();
?>

<div class="row pb-1">
    <div class="col-md-3">
        <div class="card text-white bg-primary mb-3 h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title">Total Orders Today</h5>
                        <?php
                        $db->query('SELECT COUNT(*) as count FROM orders WHERE DATE(order_date) = CURDATE()');
                        $result = $db->single();
                        ?>
                        <h2><?php echo $result['count']; ?></h2>
                    </div>
                    <i class="bi bi-clipboard-check" style="font-size: 3rem;"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card text-white bg-warning mb-3 h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title">Pending Verification</h5>
                        <?php
                        $db->query('SELECT COUNT(*) as count FROM order_tests WHERE status = "results-entered"');
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
        <div class="card text-white bg-success mb-3 h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title">Completed Today</h5>
                        <?php
                        $db->query('SELECT COUNT(*) as count FROM orders WHERE DATE(order_date) = CURDATE() AND status = "completed"');
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
                        <h5 class="card-title">Total Patients</h5>
                        <?php
                        $db->query('SELECT COUNT(*) as count FROM patients');
                        $result = $db->single();
                        ?>
                        <h2><?php echo $result['count']; ?></h2>
                    </div>
                    <i class="bi bi-people" style="font-size: 3rem;"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h5>Quick Actions</h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="verify_results.php" class="btn btn-primary btn-lg">
                        <i class="bi bi-check-circle"></i> Verify Results
                    </a>
                    <a href="all_orders.php" class="btn btn-success btn-lg">
                        <i class="bi bi-clipboard-data"></i> View All Orders
                    </a>
                    <a href="test_catalog.php" class="btn btn-info btn-lg">
                        <i class="bi bi-card-list"></i> Test Catalog
                    </a>
                    <a href="staff.php" class="btn btn-warning btn-lg">
                        <i class="bi bi-person-badge"></i> Staff Management
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h5>Recent Activity</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Activity</th>
                                <th>User</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Get recent results entry
                            // Get recent results entry - one per test
$db->query('SELECT 
                MAX(tr.entered_at) as entered_at,
                s.full_name,
                t.test_name,
                p.full_name as patient_name
            FROM test_results tr
            JOIN staff s ON tr.entered_by = s.id
            JOIN order_tests ot ON tr.order_test_id = ot.id
            JOIN tests t ON ot.test_id = t.id
            JOIN orders o ON ot.order_id = o.id
            JOIN patients p ON o.patient_id = p.id
            GROUP BY ot.id, s.full_name, t.test_name, p.full_name
            ORDER BY entered_at DESC 
            LIMIT 10');
$activities = $db->resultSet();
                            
                            foreach ($activities as $activity): ?>
                            <tr>
                                <td><?php echo date('h:i A', strtotime($activity['entered_at'])); ?></td>
                                <td>
                                    Results entered for <strong><?php echo $activity['test_name']; ?></strong><br>
                                    <small>Patient: <?php echo $activity['patient_name']; ?></small>
                                </td>
                                <td><?php echo $activity['full_name']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>