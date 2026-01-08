<?php
require_once 'config/database.php';
require_once 'includes/Auth.php';

$auth = new Auth();
$auth->requireAuth();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?>DTC Laboratory</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        .sidebar {
            min-height: calc(100vh - 56px);
            background: #f8f9fa;
            border-right: 1px solid #dee2e6;
        }
        .sidebar .nav-link {
            color: #333;
            padding: 10px 15px;
            margin: 2px 0;
        }
        .sidebar .nav-link:hover {
            background: #e9ecef;
        }
        .sidebar .nav-link.active {
            background: #0d6efd;
            color: white;
        }
        .content-header {
            background: white;
            padding: 15px;
            margin: -15px -15px 15px -15px;
            border-bottom: 1px solid #dee2e6;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">
                <i class="bi bi-hospital"></i> DTC Laboratory
            </a>
            <div class="d-flex align-items-center text-white">
                <span class="me-3"><i class="bi bi-person-circle"></i> <?php echo $_SESSION['full_name']; ?> (<?php echo $_SESSION['role']; ?>)</span>
                <a href="logout.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 sidebar d-md-block">
                <div class="position-sticky pt-3">
                    <ul class="nav flex-column">
                        <?php if ($_SESSION['role'] == 'receptionist'): ?>
                            <li class="nav-item">
                                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>" href="index.php">
                                    <i class="bi bi-speedometer2"></i> Dashboard
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'patients.php' ? 'active' : ''; ?>" href="patients.php">
                                    <i class="bi bi-people"></i> Patients
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'new_order.php' ? 'active' : ''; ?>" href="new_order.php">
                                    <i class="bi bi-plus-circle"></i> New Order
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'orders.php' ? 'active' : ''; ?>" href="orders.php">
                                    <i class="bi bi-clipboard-check"></i> Orders
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>" href="reports.php">
                                    <i class="bi bi-printer"></i> Print Reports
                                </a>
                            </li>
                        <?php elseif ($_SESSION['role'] == 'technician'): ?>
                            <li class="nav-item">
                                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'technician.php' ? 'active' : ''; ?>" href="technician.php">
                                    <i class="bi bi-speedometer2"></i> Dashboard
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'pending_tests.php' ? 'active' : ''; ?>" href="pending_tests.php">
                                    <i class="bi bi-hourglass-split"></i> Pending Tests
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'enter_results.php' ? 'active' : ''; ?>" href="enter_results.php">
                                    <i class="bi bi-clipboard-data"></i> Enter Results
                                </a>
                            </li>
                        <?php elseif ($_SESSION['role'] == 'manager'): ?>
                            <li class="nav-item">
                                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'manager.php' ? 'active' : ''; ?>" href="manager.php">
                                    <i class="bi bi-speedometer2"></i> Dashboard
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'all_orders.php' ? 'active' : ''; ?>" href="all_orders.php">
                                    <i class="bi bi-clipboard-data"></i> All Orders
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'verify_results.php' ? 'active' : ''; ?>" href="verify_results.php">
                                    <i class="bi bi-check-circle"></i> Verify Results
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'test_catalog.php' ? 'active' : ''; ?>" href="test_catalog.php">
                                    <i class="bi bi-card-list"></i> Test Catalog
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'staff.php' ? 'active' : ''; ?>" href="staff.php">
                                    <i class="bi bi-person-badge"></i> Staff
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <!-- Main content -->
            <div class="col-md-9 col-lg-10 ms-sm-auto px-md-4 pt-3">
                <div class="content-header">
                    <h4><?php echo $page_title ?? 'Dashboard'; ?></h4>
                </div>

            <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>