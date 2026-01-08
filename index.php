<?php
require_once 'config/database.php';
require_once 'includes/Auth.php';

$auth = new Auth();
$auth->requireAuth();

// Check role and redirect if not receptionist
if ($_SESSION['role'] !== 'receptionist') {
    switch($_SESSION['role']) {
        case 'manager':
            header('Location: manager.php');
            exit();
        case 'technician':
            header('Location: technician.php');
            exit();
    }
}

$page_title = 'Receptionist Dashboard';
require_once 'includes/header.php';
require_once 'includes/Database.php';


$db = new Database();
?>

<div class="row">
    <div class="col-md-3">
        <div class="card text-white bg-primary mb-3">
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
                    <i class="bi bi-people-fill" style="font-size: 3rem;"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card text-white bg-success mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title">Today's Orders</h5>
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
        <div class="card text-white bg-warning mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title">Pending Tests</h5>
                        <?php
                        $db->query('SELECT COUNT(*) as count FROM order_tests WHERE status IN ("pending", "sample-collected", "processing")');
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
        <div class="card text-white bg-info mb-3">
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
                    <i class="bi bi-check-circle-fill" style="font-size: 3rem;"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5>Quick Actions</h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="new_order.php" class="btn btn-primary btn-lg">
                        <i class="bi bi-plus-circle"></i> Create New Order
                    </a>
                    <a href="patients.php" class="btn btn-success btn-lg">
                        <i class="bi bi-person-plus"></i> Register New Patient
                    </a>
                    <a href="orders.php" class="btn btn-info btn-lg">
                        <i class="bi bi-search"></i> Search Orders
                    </a>
                    <a href="reports.php" class="btn btn-warning btn-lg">
                        <i class="bi bi-printer"></i> Print Reports
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5>Recent Orders</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Patient</th>
                                <th>Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $db->query('SELECT o.*, p.full_name 
                                       FROM orders o 
                                       JOIN patients p ON o.patient_id = p.id 
                                       ORDER BY o.id DESC LIMIT 10');
                            $orders = $db->resultSet();
                            
                            foreach ($orders as $order): ?>
                            <tr>
                                <td><a href="view_order.php?id=<?php echo $order['id']; ?>"><?php echo $order['order_number']; ?></a></td>
                                <td><?php echo $order['full_name']; ?></td>
                                <td><?php echo date('d M Y', strtotime($order['order_date'])); ?></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        switch($order['status']) {
                                            case 'pending': echo 'secondary'; break;
                                            case 'sample-collected': echo 'info'; break;
                                            case 'processing': echo 'warning'; break;
                                            case 'completed': echo 'success'; break;
                                            default: echo 'danger';
                                        }
                                    ?>">
                                        <?php echo $order['status']; ?>
                                    </span>
                                </td>
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