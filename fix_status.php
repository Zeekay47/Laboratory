<?php
require_once 'config/database.php';
require_once 'includes/Database.php';

$db = new Database();

echo "<h3>Fixing Order Statuses</h3>";

// Fix 1: Update all pending orders with collected samples
$db->query("UPDATE order_tests SET status = 'sample-collected' 
            WHERE status = 'pending' 
            AND sample_id IS NOT NULL");
echo "Updated pending tests with samples: " . $db->rowCount() . "<br>";

// Fix 2: Update orders based on their tests
$db->query("UPDATE orders o SET o.status = 'sample-collected' 
            WHERE o.status = 'pending' 
            AND EXISTS (SELECT 1 FROM order_tests ot WHERE ot.order_id = o.id AND ot.status = 'sample-collected')");
echo "Updated orders with collected samples: " . $db->rowCount() . "<br>";

// Fix 3: Show current status counts
$db->query("SELECT status, COUNT(*) as count FROM orders GROUP BY status");
$order_stats = $db->resultSet();

$db->query("SELECT status, COUNT(*) as count FROM order_tests GROUP BY status");
$test_stats = $db->resultSet();

echo "<h4>Current Order Status:</h4>";
foreach ($order_stats as $stat) {
    echo $stat['status'] . ": " . $stat['count'] . "<br>";
}

echo "<h4>Current Test Status:</h4>";
foreach ($test_stats as $stat) {
    echo $stat['status'] . ": " . $stat['count'] . "<br>";
}

echo "<br><a href='orders.php'>Go to Orders</a>";
?>