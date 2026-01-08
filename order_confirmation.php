<?php
$page_title = 'Order Confirmation';
require_once 'includes/header.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';

$auth = new Auth();
$auth->requireRole('receptionist');

$db = new Database();

$order_id = $_GET['id'] ?? 0;
if (!$order_id) {
    header('Location: index.php');
    exit();
}

// Fetch order details
$db->query('SELECT o.*, p.* FROM orders o 
           JOIN patients p ON o.patient_id = p.id 
           WHERE o.id = :id');
$db->bind(':id', $order_id);
$order = $db->single();

// Fetch order tests
$db->query('SELECT ot.*, t.test_name, t.test_code, t.sample_type 
           FROM order_tests ot 
           JOIN tests t ON ot.test_id = t.id 
           WHERE ot.order_id = :order_id');
$db->bind(':order_id', $order_id);
$tests = $db->resultSet();

// Calculate total amount
$total_amount = count($tests) * 500; // Example: 500 per test
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header bg-success text-white">
                <h5>Order Created Successfully!</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-success">
                    <i class="bi bi-check-circle-fill"></i> Order has been created successfully. 
                    Please print sample labels and receipt.
                </div>

                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <h6>Order Information</h6>
                                <p><strong>Order Number:</strong> <?php echo $order['order_number']; ?></p>
                                <p><strong>Date & Time:</strong> <?php echo date('d M Y, h:i A', strtotime($order['order_date'])); ?></p>
                                <p><strong>Status:</strong> <span class="badge bg-warning"><?php echo $order['status']; ?></span></p>
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

                <!-- Tests List -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h6>Ordered Tests (<?php echo count($tests); ?>)</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th>Test Name</th>
                                        <th>Test Code</th>
                                        <th>Sample Type</th>
                                        <th>Sample ID</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tests as $test): ?>
                                    <tr>
                                        <td><?php echo $test['test_name']; ?></td>
                                        <td><code><?php echo $test['test_code']; ?></code></td>
                                        <td><?php echo $test['sample_type']; ?></td>
                                        <td><strong class="text-primary"><?php echo $test['sample_id']; ?></strong></td>
                                        <td><span class="badge bg-secondary">Pending</span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="row">
                    <div class="col-md-3 mb-2">
                        <a href="orders.php" class="btn btn-outline-secondary w-100">
                            <i class="bi bi-list-ul"></i> Back to Orders
                        </a>
                    </div>
                    <div class="col-md-3 mb-2">
                        <a href="new_order.php" class="btn btn-outline-primary w-100">
                            <i class="bi bi-plus-circle"></i> New Order
                        </a>
                    </div>
                    <div class="col-md-3 mb-2">
                        <button class="btn btn-primary w-100" onclick="printLabels()">
                            <i class="bi bi-printer"></i> Print Labels
                        </button>
                    </div>
                    <div class="col-md-3 mb-2">
                        <button class="btn btn-success w-100" onclick="printReceipt()">
                            <i class="bi bi-receipt"></i> Print Receipt
                        </button>
                    </div>
                </div>
                
                <!-- Sample Labels Preview (Hidden) -->
                <div id="labelsContent" style="display: none;">
                    <div style="font-family: Arial, sans-serif; padding: 20px;">
                        <h4 style="text-align: center;">DTC Laboratory - Sample Labels</h4>
                        <hr>
                        <?php foreach ($tests as $test): ?>
                        <div style="border: 1px solid #000; padding: 10px; margin: 10px 0; page-break-inside: avoid;">
                            <h5>Sample ID: <strong><?php echo $test['sample_id']; ?></strong></h5>
                            <p><strong>Patient:</strong> <?php echo $order['full_name']; ?></p>
                            <p><strong>Test:</strong> <?php echo $test['test_name']; ?> (<?php echo $test['test_code']; ?>)</p>
                            <p><strong>Sample Type:</strong> <?php echo $test['sample_type']; ?></p>
                            <p><strong>Order #:</strong> <?php echo $order['order_number']; ?></p>
                            <p><strong>Date:</strong> <?php echo date('d/m/Y H:i'); ?></p>
                            <div style="text-align: center; margin-top: 10px;">
                                <barcode code="<?php echo $test['sample_id']; ?>" type="C128" />
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Receipt Preview (Hidden) -->
                <div id="receiptContent" style="display: none;">
                    <div style="font-family: 'Courier New', monospace; width: 80mm; padding: 10px;">
                        <h4 style="text-align: center;">DTC DIAGNOSTIC CENTER</h4>
                        <p style="text-align: center;">123 Medical Street, City</p>
                        <p style="text-align: center;">Phone: (123) 456-7890</p>
                        <hr>
                        <p><strong>RECEIPT</strong></p>
                        <p>Order #: <?php echo $order['order_number']; ?></p>
                        <p>Date: <?php echo date('d/m/Y H:i'); ?></p>
                        <p>Patient: <?php echo $order['full_name']; ?></p>
                        <hr>
                        <table style="width: 100%;">
                            <tr>
                                <td>Test</td>
                                <td>Amount</td>
                            </tr>
                            <?php foreach ($tests as $test): ?>
                            <tr>
                                <td><?php echo $test['test_name']; ?></td>
                                <td>500.00</td>
                            </tr>
                            <?php endforeach; ?>
                            <tr>
                                <td><strong>TOTAL</strong></td>
                                <td><strong><?php echo number_format($total_amount, 2); ?></strong></td>
                            </tr>
                        </table>
                        <hr>
                        <p style="text-align: center;">Thank you for choosing DTC Laboratory</p>
                        <p style="text-align: center;">Report will be ready in 24 hours</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function printLabels() {
    var content = document.getElementById('labelsContent').innerHTML;
    var printWindow = window.open('', '_blank');
    printWindow.document.write('<html><head><title>Print Labels</title>');
    printWindow.document.write('<style>@media print { body { margin: 0; } }</style>');
    printWindow.document.write('</head><body>');
    printWindow.document.write(content);
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.print();
}

function printReceipt() {
    var content = document.getElementById('receiptContent').innerHTML;
    var printWindow = window.open('', '_blank');
    printWindow.document.write('<html><head><title>Print Receipt</title>');
    printWindow.document.write('<style>body { font-family: "Courier New", monospace; }</style>');
    printWindow.document.write('</head><body>');
    printWindow.document.write(content);
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.print();
}
</script>

<?php require_once 'includes/footer.php'; ?>