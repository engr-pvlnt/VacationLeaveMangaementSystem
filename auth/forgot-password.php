<?php 
// auth/forgot-password.php
session_start();
include '../config/db.php';

// Include PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require '../vendor/autoload.php'; // Adjust path based on your composer installation

$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    
    // Validation
    if (empty($email)) {
        $error_message = "Email address is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Please enter a valid email address.";
    } else {
        // Check if email exists in database
        $check_stmt = $conn->prepare("SELECT id, username, first_name, last_name FROM users WHERE email = ? AND status = 'active'");
        $check_stmt->bind_param("s", $email);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            
            // Generate reset token
            $reset_token = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour')); // Token expires in 1 hour
            
            // Store it in the user sessions table with a different purpose
            $token_stmt = $conn->prepare("INSERT INTO user_sessions (user_id, session_token, expires_at, created_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE session_token = ?, expires_at = ?");
            $token_stmt->bind_param("issss", $user['id'], $reset_token, $expires_at, $reset_token, $expires_at);
            
            if ($token_stmt->execute()) {
                // Send reset email using PHPMailer
                $mail = new PHPMailer(true);
                
                try {
                    // Server settings
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com'; 
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'your-email@gmail.com'; 
                    $mail->Password   = 'your-app-password';     
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;
                    
                    // Recipients
                    $mail->setFrom('your-email@gmail.com', 'VLMS - Leave Management System');
                    $mail->addAddress($email, $user['first_name'] . ' ' . $user['last_name']);
                    
                    // Content
                    $mail->isHTML(true);
                    $mail->Subject = 'Password Reset Request - VLMS';
                    
                    $reset_link = "http://192.168.51.17/lms/auth/reset-password.php?token=" . $reset_token;
                    
                    $mail->Body = "
                    <html>
                    <head>
                        <style>
                            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                            .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                            .button { display: inline-block; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 12px 30px; text-decoration: none; border-radius: 8px; margin: 20px 0; }
                            .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
                        </style>
                    </head>
                    <body>
                        <div class='container'>
                            <div class='header'>
                                <h2>Password Reset Request</h2>
                            </div>
                            <div class='content'>
                                <p>Hello " . htmlspecialchars($user['first_name']) . ",</p>
                                <p>We received a request to reset your password for your VLMS account.</p>
                                <p>Click the button below to reset your password:</p>
                                <p><a href='" . $reset_link . "' class='button'>Reset Password</a></p>
                                <p>Or copy and paste this link into your browser:</p>
                                <p><a href='" . $reset_link . "'>" . $reset_link . "</a></p>
                                <p><strong>This link will expire in 1 hour.</strong></p>
                                <p>If you didn't request this password reset, please ignore this email.</p>
                                <p>Best regards,<br>VLMS Team</p>
                            </div>
                            <div class='footer'>
                                <p>This is an automated email. Please do not reply to this message.</p>
                            </div>
                        </div>
                    </body>
                    </html>";
                    
                    $mail->AltBody = "Hello " . $user['first_name'] . ",\n\nWe received a request to reset your password for your VLMS account.\n\nPlease visit the following link to reset your password:\n" . $reset_link . "\n\nThis link will expire in 1 hour.\n\nIf you didn't request this password reset, please ignore this email.\n\nBest regards,\nVLMS Team";
                    
                    $mail->send();
                    $success_message = "Password reset instructions have been sent to your email address.";
                } catch (Exception $e) {
                    $error_message = "Failed to send reset email. Please try again later.";
                }
            } else {
                $error_message = "Failed to generate reset token. Please try again.";
            }
            
            $token_stmt->close();
        } else {
            // Don't reveal if email exists or not for security
            $success_message = "If an account with that email exists, password reset instructions have been sent.";
        }
        
        $check_stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Leave Management System</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .forgot-password-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            padding: 48px 40px;
            width: 100%;
            max-width: 500px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .forgot-password-header {
            text-align: center;
            margin-bottom: 32px;
        }

        .logo {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            box-shadow: 0 8px 32px rgba(102, 126, 234, 0.3);
        }

        .logo i {
            color: white;
            font-size: 36px;
        }

        .forgot-password-title {
            font-size: 28px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 8px;
            letter-spacing: -0.025em;
        }

        .forgot-password-subtitle {
            font-size: 16px;
            color: #64748b;
            font-weight: 400;
            line-height: 1.5;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }

        .form-label .required {
            color: #dc2626;
            margin-left: 4px;
        }

        .input-wrapper {
            position: relative;
        }

        .form-input {
            width: 100%;
            padding: 16px 48px 16px 16px;
            font-size: 16px;
            font-weight: 400;
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            transition: all 0.2s ease;
            color: #1a202c;
        }

        .form-input:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        }

        .input-icon {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 18px;
            pointer-events: none;
        }

        .btn-reset {
            width: 100%;
            padding: 16px;
            font-size: 16px;
            font-weight: 600;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-top: 8px;
        }

        .btn-reset:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 24px rgba(102, 126, 234, 0.3);
        }

        .btn-reset:active {
            transform: translateY(0);
        }

        .alert {
            padding: 16px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-error {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .alert-success {
            background: #f0fdf4;
            color: #16a34a;
            border: 1px solid #bbf7d0;
        }

        .back-to-login {
            text-align: center;
            margin-top: 24px;
        }

        .back-to-login a {
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .back-to-login a:hover {
            color: #5a67d8;
        }

        .footer-text {
            text-align: center;
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid #e2e8f0;
            font-size: 14px;
            color: #64748b;
        }

        .info-box {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 24px;
            color: #0c4a6e;
            font-size: 14px;
            line-height: 1.5;
        }

        .info-box i {
            color: #0284c7;
            margin-right: 8px;
        }

        @media (max-width: 640px) {
            .forgot-password-container {
                padding: 32px 24px;
            }
            
            .forgot-password-title {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="forgot-password-container">
        <div class="forgot-password-header">
            <div class="logo">
                <i class="fas fa-key"></i>
            </div>
            <h1 class="forgot-password-title">Forgot Password</h1>
            <p class="forgot-password-subtitle">Enter your email address and we'll send you instructions to reset your password</p>
        </div>

        <?php if($error_message): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <?php if($success_message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <?php if(!$success_message): ?>
            <div class="info-box">
                <i class="fas fa-info-circle"></i>
                Please enter the email address associated with your VLMS account. You will receive a password reset link that expires in 1 hour.
            </div>

            <form method="POST" id="forgotPasswordForm">
                <div class="form-group">
                    <label for="email" class="form-label">Email Address <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            class="form-input" 
                            placeholder="Enter your email address"
                            value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                            required
                        >
                        <i class="fas fa-envelope input-icon"></i>
                    </div>
                </div>

                <button type="submit" class="btn-reset">
                    <i class="fas fa-paper-plane" style="margin-right: 8px;"></i>
                    Send Reset Instructions
                </button>
            </form>
        <?php endif; ?>

        <div class="back-to-login">
            <a href="login.php">
                <i class="fas fa-arrow-left"></i>
                Back to Login
            </a>
        </div>

        <div class="footer-text">
            <p>&copy; 2025 VLMS - Leave Management System. All rights reserved.</p>
        </div>
    </div>

    <script>
        // Form validation
        document.getElementById('forgotPasswordForm')?.addEventListener('submit', function(e) {
            const email = document.getElementById('email').value.trim();
            
            if (!email) {
                e.preventDefault();
                alert('Please enter your email address.');
                return;
            }
            
            // Basic email validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                e.preventDefault();
                alert('Please enter a valid email address.');
                return;
            }
        });

        // Auto-hide success message after 10 seconds
        <?php if($success_message): ?>
            setTimeout(function() {
                const successAlert = document.querySelector('.alert-success');
                if (successAlert) {
                    successAlert.style.opacity = '0';
                    successAlert.style.transform = 'translateY(-10px)';
                    setTimeout(function() {
                        successAlert.style.display = 'none';
                    }, 300);
                }
            }, 10000);
        <?php endif; ?>
    </script>
</body>
</html>