<?php
// admin/test_email.php

session_start();
include '../config/db.php';

require '../PHPMailer/src/Exception.php';
require '../PHPMailer/src/PHPMailer.php';
require '../PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json; charset=utf-8');

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$user_role = $user['role_name'];

if ($user_role !== 'Admin') {
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit();
}

if (!isset($_POST['test_email'])) {
    echo json_encode(['success' => false, 'message' => 'Test email address required']);
    exit();
}

$test_email = $_POST['test_email'];

// Validate email
if (!filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email address']);
    exit();
}

// Get email settings from database
function getSetting($conn, $key, $default = '') {
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc()['setting_value'];
    }
    return $default;
}

$email_host = getSetting($conn, 'email_host', 'smtp.gmail.com');
$email_port = getSetting($conn, 'email_port', '587');
$email_username = getSetting($conn, 'email_username', '');
$email_password = getSetting($conn, 'email_password', '');
$email_encryption = getSetting($conn, 'email_encryption', 'tls');
$email_from_name = getSetting($conn, 'email_from_name', 'VLMS System');
$email_from_address = getSetting($conn, 'email_from_address', '');
$company_name = getSetting($conn, 'company_name', 'Your Company');

// Check if required settings are configured
if (empty($email_username) || empty($email_password) || empty($email_from_address)) {
    echo json_encode([
        'success' => false, 
        'message' => 'Email settings are not fully configured. Please check SMTP username, password, and from address.'
    ]);
    exit();
}

try {

    $mail = new PHPMailer(true);
    
    // Server settings
    $mail->isSMTP();
    $mail->Host = $email_host;
    $mail->SMTPAuth = true;
    $mail->Username = $email_username;
    $mail->Password = $email_password;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->CharSet = "UTF-8";
    $mail->Port = (int)$email_port;
    $mail->SMTPDebug = 0; // Keep this as 0 for production
    $mail->Priority = 1;
    
    // FIX: Better encryption handling
    if (!empty($email_encryption)) {
        if ($email_encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($email_encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        }
    }
    
    // Recipients
    $mail->setFrom($email_from_address, $email_from_name);
    $mail->addAddress($test_email);
    
    // Content
    $mail->isHTML(true);
    $mail->Subject = 'VLMS Email Configuration Test';
    $mail->Body = '
    <html>
    <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
        <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 10px; text-align: center;">
                <h1 style="margin: 0; font-size: 24px;">Email Test Successful!</h1>
            </div>
            
            <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin: 20px 0;">
                <h2 style="color: #667eea; margin-top: 0;">VLMS Email Configuration Test</h2>
                <p>Congratulations! Your email configuration is working correctly.</p>
                
                <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid #667eea;">
                    <strong>Test Details:</strong><br>
                    <strong>From:</strong> ' . $email_from_name . ' (' . $email_from_address . ')<br>
                    <strong>SMTP Host:</strong> ' . $email_host . ':' . $email_port . '<br>
                    <strong>Encryption:</strong> ' . strtoupper($email_encryption) . '<br>
                    <strong>Test Date:</strong> ' . date('F j, Y \a\t g:i A') . '<br>
                    <strong>Company:</strong> ' . $company_name . '
                </div>
                
                <p style="margin-top: 20px;">
                    <strong>What this means:</strong><br>
                    - SMTP connection established successfully<br>
                    - Authentication credentials are valid<br>
                    - Email delivery is working<br>
                    - Your VLMS system can now send notifications
                </p>
            </div>
            
            <div style="text-align: center; color: #666; font-size: 14px; margin-top: 20px;">
                <p>This is an automated test message from your Vacation Leave Management System.</p>
                <p>If you received this email in error, please ignore it.</p>
            </div>
        </div>
    </body>
    </html>';
    
    $mail->AltBody = "VLMS Email Configuration Test\n\nCongratulations! Your email configuration is working correctly.\n\nTest Details:\nFrom: $email_from_name ($email_from_address)\nSMTP Host: $email_host:$email_port\nEncryption: " . strtoupper($email_encryption) . "\nTest Date: " . date('F j, Y \a\t g:i A') . "\nCompany: $company_name\n\nThis is an automated test message from your Vacation Leave Management System.";
    
    $mail->send();
    echo json_encode(['success' => true, 'message' => 'Test email sent successfully']);
} catch (Exception $e) {
    // FIX: Better error reporting
    $error_message = $mail->ErrorInfo;
    
    // Log the error for debugging (optional)
    error_log("VLMS Email Test Error: " . $error_message);
    
    // Return sanitized error message
    if (strpos($error_message, 'Authentication failed') !== false) {
        $error_message = 'SMTP Authentication failed. Please check your username and password.';
    } elseif (strpos($error_message, 'Connection refused') !== false) {
        $error_message = 'Cannot connect to SMTP server. Please check host and port settings.';
    }
    
    echo json_encode(['success' => false, 'message' => $error_message]);
}
exit();
?>