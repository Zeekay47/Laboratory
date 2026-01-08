<?php
$page_title = 'Reprint Sample Labels';
require_once 'includes/header.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';

$auth = new Auth();
$auth->requireRole('receptionist');

$db = new Database();

$order_id = $_GET['id'] ?? 0;
if (!$order_id) {
    header('Location: orders.php');
    exit();
}

// Fetch order details
$db->query('SELECT o.*, p.* FROM orders o 
           JOIN patients p ON o.patient_id = p.id 
           WHERE o.id = :id');
$db->bind(':id', $order_id);
$order = $db->single();

if (!$order) {
    header('Location: orders.php');
    exit();
}

// Fetch order tests
$db->query('SELECT ot.*, t.test_name, t.test_code, t.sample_type 
           FROM order_tests ot 
           JOIN tests t ON ot.test_id = t.id 
           WHERE ot.order_id = :order_id');
$db->bind(':order_id', $order_id);
$tests = $db->resultSet();

if (empty($tests)) {
    header('Location: orders.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reprint Labels - Order #<?php echo $order['order_number']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <!-- Include barcode library if you have one, or use simple text -->
    <style>
        @media print {
            body * {
                visibility: hidden;
            }
            #labelsContent, #labelsContent * {
                visibility: visible;
            }
            #labelsContent {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
            }
            .no-print {
                display: none !important;
            }
            .label-container {
                page-break-inside: avoid;
            }
        }
        
        .label-container {
            border: 2px solid #000;
            padding: 15px;
            margin: 10px 0;
            min-height: 180px;
            background: white;
            font-family: Arial, sans-serif;
        }
        
        .barcode-placeholder {
            text-align: center;
            margin-top: 10px;
            padding: 5px;
            border: 1px dashed #ccc;
            font-family: monospace;
            font-weight: bold;
            font-size: 16px;
            letter-spacing: 2px;
        }
        
        .watermark {
            color: rgba(0,0,0,0.1);
            position: absolute;
            font-size: 40px;
            transform: rotate(-45deg);
            top: 50%;
            left: 10%;
            white-space: nowrap;
        }
        
        @media screen {
            .label-container {
                width: 80mm;
                margin: 10px auto;
            }
        }
    </style>
</head>
<body>
    <!-- Control Panel (hidden when printing) -->
    <div class="container-fluid mt-3 no-print">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5><i class="bi bi-printer"></i> Reprint Sample Labels</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> 
                            Reprinting labels for <strong>Order #<?php echo $order['order_number']; ?></strong>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body">
                                        <h6>Order Information</h6>
                                        <p><strong>Order #:</strong> <?php echo $order['order_number']; ?></p>
                                        <p><strong>Date:</strong> <?php echo date('d M Y, h:i A', strtotime($order['order_date'])); ?></p>
                                        <p><strong>Status:</strong> 
                                            <span class="badge bg-<?php echo $order['status'] == 'pending' ? 'warning' : 'info'; ?>">
                                                <?php echo $order['status']; ?>
                                            </span>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body">
                                        <h6>Patient Information</h6>
                                        <p><strong>Name:</strong> <?php echo $order['full_name']; ?></p>
                                        <p><strong>Patient Code:</strong> <?php echo $order['patient_code']; ?></p>
                                        <p><strong>Phone:</strong> <?php echo $order['phone']; ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card mb-3">
                            <div class="card-header">
                                <h6>Tests to Print (<?php echo count($tests); ?>)</h6>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Test Name</th>
                                                <th>Sample Type</th>
                                                <th>Sample ID</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($tests as $index => $test): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td><?php echo $test['test_name']; ?></td>
                                                <td><?php echo $test['sample_type']; ?></td>
                                                <td><strong><?php echo $test['sample_id']; ?></strong></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2 d-md-flex justify-content-md-center">
                            <button class="btn btn-primary btn-lg" onclick="printLabels()">
                                <i class="bi bi-printer"></i> Print All Labels
                            </button>
                            <a href="orders.php" class="btn btn-outline-secondary btn-lg">
                                <i class="bi bi-arrow-left"></i> Back to Orders
                            </a>
                        </div>
                        
                        <div class="mt-3 text-center text-muted">
                            <small><i class="bi bi-info-circle"></i> Labels will open in a new window for printing</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Labels Content (hidden on screen, visible when printing) -->
    <div id="labelsContent" style="display: none;">
        <div style="font-family: Arial, sans-serif; padding: 20px;">
            <div style="text-align: center; margin-bottom: 20px;">
                <h4 style="margin: 0;">DTC DIAGNOSTIC CENTER</h4>
                <p style="margin: 5px 0; font-size: 14px;">Sample Labels - Reprint</p>
                <p style="margin: 5px 0; font-size: 12px; color: #666;">Order #<?php echo $order['order_number']; ?> - <?php echo date('d/m/Y H:i'); ?></p>
                <hr style="margin: 10px 0;">
            </div>
            
            <?php foreach ($tests as $test): ?>
            <div class="label-container">
                <div style="margin-bottom: 10px;">
                    <div style="font-size: 18px; font-weight: bold; color: #0066cc;">
                        SAMPLE ID: <?php echo $test['sample_id']; ?>
                    </div>
                </div>
                
                <div style="margin-bottom: 8px;">
                    <strong>Patient:</strong> <?php echo $order['full_name']; ?>
                </div>
                
                <div style="margin-bottom: 8px;">
                    <strong>Test:</strong> <?php echo $test['test_name']; ?> 
                    <small>(<?php echo $test['test_code']; ?>)</small>
                </div>
                
                <div style="margin-bottom: 8px;">
                    <strong>Sample Type:</strong> <?php echo $test['sample_type']; ?>
                </div>
                
                <div style="margin-bottom: 8px;">
                    <strong>Order #:</strong> <?php echo $order['order_number']; ?>
                </div>
                
                <div style="margin-bottom: 8px;">
                    <strong>Date:</strong> <?php echo date('d/m/Y H:i'); ?>
                </div>
                
                <div class="barcode-placeholder">
                    *<?php echo $test['sample_id']; ?>*
                </div>
                
                <?php if ($order['status'] != 'pending'): ?>
                <div class="watermark">
                    REPRINT
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Add page break after every 2 labels for printing -->
            <?php if (($index + 1) % 2 == 0): ?>
            <div style="page-break-after: always;"></div>
            <?php endif; ?>
            
            <?php endforeach; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function printLabels() {
        var content = document.getElementById('labelsContent').innerHTML;
        var printWindow = window.open('', '_blank', 'width=800,height=600');
        
        printWindow.document.write('<html><head><title>Print Labels - Order #<?php echo $order['order_number']; ?></title>');
        printWindow.document.write('<style>');
        printWindow.document.write('@media print {');
        printWindow.document.write('  @page { margin: 0.5cm; }');
        printWindow.document.write('  body { margin: 0; padding: 0; font-family: Arial, sans-serif; }');
        printWindow.document.write('  .label-container { border: 2px solid #000; padding: 15px; margin: 10px 0; min-height: 180px; background: white; page-break-inside: avoid; }');
        printWindow.document.write('  .watermark { color: rgba(0,0,0,0.1); position: absolute; font-size: 40px; transform: rotate(-45deg); top: 50%; left: 10%; white-space: nowrap; }');
        printWindow.document.write('  .barcode-placeholder { text-align: center; margin-top: 10px; padding: 5px; border: 1px dashed #ccc; font-family: monospace; font-weight: bold; font-size: 16px; letter-spacing: 2px; }');
        printWindow.document.write('}');
        printWindow.document.write('</style>');
        printWindow.document.write('</head><body>');
        printWindow.document.write(content);
        printWindow.document.write('</body></html>');
        
        printWindow.document.close();
        
        // Wait a moment for content to load, then print
        setTimeout(function() {
            printWindow.print();
            printWindow.close();
        }, 500);
    }
    
    // Auto-print when page loads (optional - uncomment if you want)
    // window.onload = function() {
    //     printLabels();
    // };
    </script>
</body>
</html>