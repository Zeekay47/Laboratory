<?php
// Start fresh - clean all output buffers
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Start output buffering
ob_start();

// Turn on error display for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Initialize response
$response = [
    'success' => false,
    'message' => '',
    'marked_delivered' => false
];

try {
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Session expired. Please login again.');
    }
    
    // Include database configuration
    require_once 'config/database.php';
    
    // Include necessary files AFTER database config
    require_once 'includes/Database.php';
    require_once 'includes/Auth.php';
    
    // Check user role
    $auth = new Auth();
    $auth->requireRole('receptionist');
    
    // Initialize database
    $db = new Database();

    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    
    // Get POST data
    $report_id = isset($_POST['report_id']) ? (int)$_POST['report_id'] : 0;
    $send_email = isset($_POST['send_email']) && $_POST['send_email'] == 'on';
    
    if (!$report_id) {
        throw new Exception('Report ID is required');
    }
    
    if (!$send_email) {
        throw new Exception('Please select email delivery method');
    }
    
    // Fetch report details
    $db->query('SELECT r.*, o.order_number, p.full_name, p.email, p.phone 
                FROM reports r 
                JOIN orders o ON r.order_id = o.id 
                JOIN patients p ON o.patient_id = p.id 
                WHERE r.id = :id');
    $db->bind(':id', $report_id);
    $report = $db->single();
    
    if (!$report) {
        throw new Exception('Report not found');
    }
    
    // Get report path
    $report_path = isset($_POST['report_path']) ? $_POST['report_path'] : 'reports/' . $report['report_path'];
    $full_report_path = realpath(dirname(__FILE__) . '/' . $report_path);
    
    if (!$full_report_path || !file_exists($full_report_path)) {
        throw new Exception('Report file not found: ' . basename($report_path));
    }
    
    // Get email details
    $email_to = isset($_POST['email_to']) ? $_POST['email_to'] : $report['email'];
    $email_subject = isset($_POST['email_subject']) ? $_POST['email_subject'] : 'Your Lab Report - ' . $report['order_number'];
    $email_message = isset($_POST['email_message']) ? $_POST['email_message'] : '';
    
    if (empty($email_to)) {
        throw new Exception('Email address is required for email delivery');
    }
    
    // Validate email format
    if (!filter_var($email_to, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email address format: ' . $email_to);
    }
    
    // Send email
    $email_sent = sendEmail($email_to, $email_subject, $email_message, $full_report_path);
    
    if (!$email_sent) {
        throw new Exception('Failed to send email. Please check your email configuration.');
    }
    
    // Mark as delivered in reports table if not already
    if (!$report['delivered_at']) {
        $db->query('UPDATE reports SET delivered_at = NOW(), delivered_by = :user_id, delivery_method = :method WHERE id = :id');
        $db->bind(':id', $report_id);
        $db->bind(':user_id', $_SESSION['user_id']);
        $db->bind(':method', 'email');
        $db->execute();
        $response['marked_delivered'] = true;
    }
    
    $response['success'] = true;
    $response['message'] = 'Email sent successfully to ' . $email_to;
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    error_log("Send Report Error: " . $e->getMessage());
}

// Clean the output buffer
ob_clean();

// Set content type to JSON
header('Content-Type: application/json; charset=utf-8');

// Output JSON
echo json_encode($response);

// Ensure nothing else is output
exit;

function sendEmail($to, $subject, $message, $attachment_path) {
    // Load PHPMailer files
    $phpmailer_path = dirname(__FILE__) . '/includes/PHPMailer/';
    
    // Check if PHPMailer files exist
    if (!file_exists($phpmailer_path . 'PHPMailer.php')) {
        error_log("PHPMailer files not found in: " . $phpmailer_path);
        return false;
    }
    
    // Include PHPMailer files
    require_once $phpmailer_path . 'PHPMailer.php';
    require_once $phpmailer_path . 'SMTP.php';
    require_once $phpmailer_path . 'Exception.php';
    
    try {
        // Create PHPMailer instance
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'zahid.khan.evil@gmail.com';
        $mail->Password = 'mntw wapr tkcx nfjs';
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        // Disable debugging for production
        $mail->SMTPDebug = 0;
        
        // Timeout settings
        $mail->Timeout = 30;
        
        // TLS options
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        // Recipients
        $mail->setFrom('zahid.khan.evil@gmail.com', 'DTC Lab Management');
        $mail->addAddress($to);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = nl2br(htmlspecialchars($message));
        $mail->AltBody = strip_tags($message);
        
        // Add attachment
        $attachment_name = 'Lab_Report_' . basename($attachment_path);
        if (!$mail->addAttachment($attachment_path, $attachment_name)) {
            error_log("Failed to add attachment: $attachment_path");
            return false;
        }
        
        // Send the email
        return $mail->send();
        
    } catch (Exception $e) {
        error_log("PHPMailer Exception: " . $e->getMessage());
        return false;
    }
}
?>