<?php
require_once 'config/database.php';
require_once 'includes/Auth.php';

$auth = new Auth();
$auth->logout();
?>