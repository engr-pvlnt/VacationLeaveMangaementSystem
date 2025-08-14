<?php
// admin/system_settings.php
session_start();
include '../config/db.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login');
    exit();
}

// Fetch user info to verify admin role
$user_id = $_SESSION['user_id'];
$result = $conn->query("SELECT * FROM users WHERE id = $user_id");
$user = $result->fetch_assoc();

$role_id = $user['role_id'];
$result = $conn->query("SELECT name FROM roles WHERE id = $role_id");
$user_role = $result->fetch_assoc()['name'];

// Only allow admin access
if ($user_role !== 'Admin') {
    header('Location: ../dashboard');
    exit();
}

// Use prepared statements consistently for the getSetting function
function getSetting($conn, $key, $default = '')
{
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc()['setting_value'];
    }
    return $default;
}

// Initialize current_settings BEFORE the POST handling
$current_settings = [
    'app_name' => getSetting($conn, 'app_name', 'Vacation Leave Management System'),
    'company_name' => getSetting($conn, 'company_name', ''),
    'company_address' => getSetting($conn, 'company_address', ''),
    'company_phone' => getSetting($conn, 'company_phone', ''),
    'company_email' => getSetting($conn, 'company_email', ''),
    'backup_folder' => getSetting($conn, 'backup_folder', '../backups/'),
    'email_host' => getSetting($conn, 'email_host', 'smtp.gmail.com'),
    'email_port' => getSetting($conn, 'email_port', '587'),
    'email_username' => getSetting($conn, 'email_username', ''),
    'email_password' => getSetting($conn, 'email_password', ''),
    'email_encryption' => getSetting($conn, 'email_encryption', 'tls'),
    'email_from_name' => getSetting($conn, 'email_from_name', ''),
    'email_from_address' => getSetting($conn, 'email_from_address', '')
];

// Handle form submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    try {
        // Disable foreign key checks
        $conn->query("SET FOREIGN_KEY_CHECKS = 0");
        $conn->autocommit(FALSE); // Start transaction

        // Collect old settings for logging
        $old_settings = [];
        foreach ($current_settings as $key => $value) {
            $old_settings[$key] = $value;
        }

        // Add proper validation
        if (empty($_POST['app_name']) || empty($_POST['company_name'])) {
            throw new Exception("Application name and company name are required.");
        }

        // Sanitize inputs
        $app_name = mysqli_real_escape_string($conn, $_POST['app_name']);
        $company_name = mysqli_real_escape_string($conn, $_POST['company_name']);
        $company_address = mysqli_real_escape_string($conn, $_POST['company_address']);
        $company_phone = mysqli_real_escape_string($conn, $_POST['company_phone']);
        $company_email = mysqli_real_escape_string($conn, $_POST['company_email']);
        $backup_folder = mysqli_real_escape_string($conn, $_POST['backup_folder']);

        // Email settings
        $email_host = mysqli_real_escape_string($conn, $_POST['email_host']);
        $email_port = (int)$_POST['email_port'];
        $email_username = mysqli_real_escape_string($conn, $_POST['email_username']);
        $email_password = mysqli_real_escape_string($conn, $_POST['email_password']);
        $email_encryption = mysqli_real_escape_string($conn, $_POST['email_encryption']);
        $email_from_name = mysqli_real_escape_string($conn, $_POST['email_from_name']);
        $email_from_address = mysqli_real_escape_string($conn, $_POST['email_from_address']);

        // Array of settings to update
        $settings = [
            'app_name' => $app_name,
            'company_name' => $company_name,
            'company_address' => $company_address,
            'company_phone' => $company_phone,
            'company_email' => $company_email,
            'backup_folder' => $backup_folder,
            'email_host' => $email_host,
            'email_port' => $email_port,
            'email_username' => $email_username,
            'email_password' => $email_password,
            'email_encryption' => $email_encryption,
            'email_from_name' => $email_from_name,
            'email_from_address' => $email_from_address
        ];

        // Update or insert each setting
        foreach ($settings as $key => $value) {
            // Check if setting exists
            $check_stmt = $conn->prepare("SELECT id FROM system_settings WHERE setting_key = ?");
            $check_stmt->bind_param("s", $key);
            $check_stmt->execute();
            $result = $check_stmt->get_result();

            if ($result->num_rows > 0) {
                // Update existing setting
                $stmt = $conn->prepare("UPDATE system_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?");
                $stmt->bind_param("ss", $value, $key);
            } else {
                // Insert new setting
                $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");
                $stmt->bind_param("ss", $key, $value);
            }

            if (!$stmt->execute()) {
                throw new Exception("Execute failed for $key: " . $stmt->error);
            }
            $stmt->close();
            $check_stmt->close();
        }

        // Prepare log details
        $user_id = $_SESSION['user_id'];
        $action = "UPDATE_SETTINGS";
        $table_name = "system_settings";

        // For record_id, you may choose to log a fixed value or omit if not applicable
        $record_id = 0; // or null, if your schema allows

        // Encode old and new values as JSON
        $old_values_json = json_encode($old_settings);
        $new_values_json = json_encode($settings);

        // Get IP and user agent
        $ip_address = $_SERVER['REMOTE_ADDR'];
        $user_agent = $_SERVER['HTTP_USER_AGENT'];

        // Prepare log insert
        $log_stmt = $conn->prepare("INSERT INTO system_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");

        // Bind parameters
        $log_stmt->bind_param(
            "ississss",
            $user_id,
            $action,
            $table_name,
            $record_id,
            $old_values_json,
            $new_values_json,
            $ip_address,
            $user_agent
        );

        // Execute log insert
        $log_stmt->execute();
        $log_stmt->close();

        $conn->commit(); // Commit transaction
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        $success_message = "System settings updated successfully!";

        // Refresh current settings after update
        $current_settings = [
            'app_name' => getSetting($conn, 'app_name', 'Vacation Leave Management System'),
            'company_name' => getSetting($conn, 'company_name', ''),
            'company_address' => getSetting($conn, 'company_address', ''),
            'company_phone' => getSetting($conn, 'company_phone', ''),
            'company_email' => getSetting($conn, 'company_email', ''),
            'backup_folder' => getSetting($conn, 'backup_folder', '../backups/'),
            'email_host' => getSetting($conn, 'email_host', 'smtp.gmail.com'),
            'email_port' => getSetting($conn, 'email_port', '587'),
            'email_username' => getSetting($conn, 'email_username', ''),
            'email_password' => getSetting($conn, 'email_password', ''),
            'email_encryption' => getSetting($conn, 'email_encryption', 'tls'),
            'email_from_name' => getSetting($conn, 'email_from_name', ''),
            'email_from_address' => getSetting($conn, 'email_from_address', '')
        ];
    } catch (Exception $e) {
        $conn->rollback(); // Rollback on error
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        $error_message = "Error updating settings: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VLMS - System Settings</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #333;
            overflow-x: hidden;
        }

        /* Animated background particles */
        .bg-particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 1;
        }

        .particle {
            position: absolute;
            width: 4px;
            height: 4px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            animation: float 20s infinite linear;
        }

        @keyframes float {
            0% {
                transform: translateY(100vh) rotate(0deg);
                opacity: 0;
            }

            10% {
                opacity: 1;
            }

            90% {
                opacity: 1;
            }

            100% {
                transform: translateY(-100vh) rotate(360deg);
                opacity: 0;
            }
        }

        /* Admin role bar */
        .admin-role-bar {
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            color: white;
            padding: 0.75rem 2rem;
            position: relative;
            z-index: 1001;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }

        .admin-role-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex: 1;
        }

        /* Navigation */
        .navbar {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 1rem 2rem;
            position: relative;
            z-index: 1000;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .navbar-brand {
            color: white !important;
            font-size: 1.5rem;
            font-weight: 700;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .navbar-brand:hover {
            color: rgba(255, 255, 255, 0.8) !important;
            transform: scale(1.05);
            transition: all 0.3s ease;
        }

        .navbar-nav {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .nav-link {
            color: rgba(255, 255, 255, 0.9) !important;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 25px;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            font-weight: 500;
            position: relative;
            overflow: hidden;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px) scale(1.05);
            color: white !important;
        }

        /* Main container */
        .main-container {
            position: relative;
            z-index: 100;
            padding: 2rem;
            max-width: 1000px;
            margin: 0 auto;
        }

        /* Settings card */
        .settings-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            animation: slideUp 0.8s ease-out;
        }

        .settings-title {
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            background-clip: text;
            color: transparent;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .settings-subtitle {
            color: #666;
            margin-bottom: 2rem;
            font-size: 1rem;
        }

        @keyframes slideUp {
            from {
                opacity: 1;
                transform: translateY(0);
            }

            to {
                opacity: 0;
                transform: translateY(-20px);
            }
        }

        /* Form styles */
        .form-section {
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: rgba(255, 255, 255, 0.5);
            border-radius: 15px;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .section-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: linear-gradient(135deg, #667eea, #764ba2);
            background-clip: text;
            color: transparent;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #333;
        }

        input[type="text"],
        input[type="email"],
        input[type="number"],
        input[type="password"],
        select,
        textarea {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid rgba(102, 126, 234, 0.2);
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
            color: #333;
        }

        input[type="text"]:focus,
        input[type="email"]:focus,
        input[type="number"]:focus,
        input[type="password"]:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            transform: translateY(-2px);
        }

        textarea {
            resize: vertical;
            min-height: 100px;
        }

        /* Alert messages */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            font-weight: 500;
            animation: slideDown 0.5s ease-out;
        }

        .alert-success {
            background: linear-gradient(135deg, #48bb78, #38a169);
            color: white;
            border-left: 4px solid #25855a;
        }

        .alert-danger {
            background: linear-gradient(135deg, #f56565, #e53e3e);
            color: white;
            border-left: 4px solid #c53030;
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 2rem;
            border: none;
            border-radius: 25px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            position: relative;
            overflow: hidden;
        }

        .btn-primary {
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 10px 25px rgba(79, 172, 254, 0.4);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .btn-secondary:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
        }

        .btn-back {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            margin-bottom: 1rem;
        }

        .btn-back:hover {
            background: rgba(255, 255, 255, 0.3);
            color: white;
            text-decoration: none;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(102, 126, 234, 0.2);
        }

        /* Animations */
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .main-container {
                padding: 1rem;
            }

            .navbar {
                padding: 1rem;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .settings-title {
                font-size: 1.5rem;
            }

            .admin-role-bar {
                padding: 0.5rem 1rem;
            }
        }

        /* Password toggle */
        .password-field {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #667eea;
            cursor: pointer;
            font-size: 1rem;
        }

        .password-toggle:hover {
            color: #4facfe;
        }

        /* System Information Styles */
        .system-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .info-item {
            background: rgba(255, 255, 255, 0.7);
            padding: 1rem;
            border-radius: 10px;
            border: 1px solid rgba(102, 126, 234, 0.2);
            transition: all 0.3s ease;
        }

        .info-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .info-label {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .info-value {
            font-size: 1rem;
            font-weight: 600;
            color: #333;
        }

        /* Backup Actions */
        .backup-actions {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .backup-list {
            background: rgba(255, 255, 255, 0.7);
            border-radius: 10px;
            padding: 1rem;
            border: 1px solid rgba(102, 126, 234, 0.2);
        }

        .backup-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            border-bottom: 1px solid rgba(102, 126, 234, 0.1);
            transition: all 0.3s ease;
        }

        .backup-item:last-child {
            border-bottom: none;
        }

        .backup-item:hover {
            background: rgba(102, 126, 234, 0.05);
            border-radius: 8px;
        }

        .backup-info {
            flex: 1;
        }

        .backup-name {
            font-weight: 600;
            color: #333;
        }

        .backup-date {
            font-size: 0.9rem;
            color: #666;
        }

        .backup-size {
            font-size: 0.9rem;
            color: #667eea;
            font-weight: 500;
        }

        .backup-actions-item {
            display: flex;
            gap: 0.5rem;
        }

        .btn-small {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
            border-radius: 15px;
        }

        /* Loading spinner */
        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Progress bar */
        .progress-bar {
            width: 100%;
            height: 6px;
            background: rgba(102, 126, 234, 0.2);
            border-radius: 3px;
            overflow: hidden;
            margin: 1rem 0;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 3px;
            transition: width 0.3s ease;
            width: 0%;
        }

        /* Confirmation modal styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
        }

        .modal {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease-out;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px) scale(0.9);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: #333;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-content {
            color: #666;
            margin-bottom: 1.5rem;
            line-height: 1.5;
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }

        .btn-danger {
            background: linear-gradient(135deg, #f56565, #e53e3e);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px) scale(1.05);
            box-shadow: 0 10px 25px rgba(245, 101, 101, 0.4);
        }
    </style>
</head>

<body>
    <!-- Animated background particles -->
    <div class="bg-particles" id="particles"></div>

    <!-- Admin role bar -->
    <div class="admin-role-bar">
        <div class="admin-role-info">
            <i class="fas fa-cogs"></i>
            <span>System Settings - Admin Panel</span>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="navbar">
        <a class="navbar-brand" href="../dashboard">
            <i class="fas fa-calendar-alt"></i>
            Vacation Leave Management System
        </a>
        <div class="navbar-nav">
            <a href="../auth/profile" class="nav-link">
                <i class="fas fa-user"></i> Profile
            </a>
            <a href="../auth/logout" class="nav-link">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </nav>

    <!-- Main content -->
    <div class="main-container">
        <!-- Back button -->
        <a href="../admin/" class="btn btn-back">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>

        <!-- Settings card -->
        <div class="settings-card">
            <h1 class="settings-title">
                <i class="fas fa-cogs"></i>
                System Settings
            </h1>
            <p class="settings-subtitle">
                Configure system-wide settings and email configuration for your VLMS application.
            </p>

            <!-- Alert messages -->
            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $success_message; ?>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error_message; ?>
                </div>
            <?php endif; ?>

            <!-- Settings form -->
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                <!-- Application Settings -->
                <div class="form-section">
                    <h3 class="section-title">
                        <i class="fas fa-laptop"></i>
                        Application Settings
                    </h3>

                    <div class="form-group">
                        <label for="app_name">Application Name</label>
                        <input type="text" id="app_name" name="app_name"
                            value="<?php echo htmlspecialchars($current_settings['app_name']); ?>"
                            required>
                    </div>
                </div>

                <!-- Company Information -->
                <div class="form-section">
                    <h3 class="section-title">
                        <i class="fas fa-building"></i>
                        Company Information
                    </h3>

                    <div class="form-group">
                        <label for="company_name">Company Name</label>
                        <input type="text" id="company_name" name="company_name"
                            value="<?php echo htmlspecialchars($current_settings['company_name']); ?>"
                            required>
                    </div>

                    <div class="form-group">
                        <label for="company_address">Company Address</label>
                        <textarea id="company_address" name="company_address"
                            placeholder="Enter complete company address"><?php echo htmlspecialchars($current_settings['company_address']); ?></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="company_phone">Contact Number</label>
                            <input type="text" id="company_phone" name="company_phone"
                                value="<?php echo htmlspecialchars($current_settings['company_phone']); ?>"
                                placeholder="+966 (50) 123-4567">
                        </div>

                        <div class="form-group">
                            <label for="company_email">Company Email</label>
                            <input type="email" id="company_email" name="company_email"
                                value="<?php echo htmlspecialchars($current_settings['company_email']); ?>"
                                placeholder="company@email.com">
                        </div>
                    </div>
                </div>

                <!-- System Configuration -->
                <div class="form-section">
                    <h3 class="section-title">
                        <i class="fas fa-server"></i>
                        System Configuration
                    </h3>

                    <div class="form-group">
                        <label for="backup_folder">Backup Folder Path</label>
                        <input type="text" id="backup_folder" name="backup_folder"
                            value="<?php echo htmlspecialchars($current_settings['backup_folder']); ?>"
                            placeholder="../backups/">
                        <small style="color: #666; font-size: 0.9rem; margin-top: 0.5rem; display: block;">
                            <i class="fas fa-info-circle"></i>
                            Path where system backups will be stored (relative to application root)
                        </small>
                    </div>
                </div>

                <!-- Email Settings -->
                <div class="form-section">
                    <h3 class="section-title">
                        <i class="fas fa-envelope"></i>
                        Email Settings (PHPMailer Configuration)
                    </h3>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="email_host">SMTP Host</label>
                            <input type="text" id="email_host" name="email_host"
                                value="<?php echo htmlspecialchars($current_settings['email_host']); ?>"
                                placeholder="smtp.gmail.com">
                        </div>

                        <div class="form-group">
                            <label for="email_port">SMTP Port</label>
                            <input type="number" id="email_port" name="email_port"
                                value="<?php echo htmlspecialchars($current_settings['email_port']); ?>"
                                placeholder="587">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="email_username">SMTP Username</label>
                            <input type="text" id="email_username" name="email_username"
                                value="<?php echo htmlspecialchars($current_settings['email_username']); ?>"
                                placeholder="your-email@gmail.com">
                        </div>

                        <div class="form-group">
                            <label for="email_password">SMTP Password</label>
                            <div class="password-field">
                                <input type="password" id="email_password" name="email_password"
                                    value="<?php echo htmlspecialchars($current_settings['email_password']); ?>"
                                    placeholder="Your app password">
                                <button type="button" class="password-toggle" onclick="togglePassword('email_password')">
                                    <i class="fas fa-eye" id="email_password_icon"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="email_encryption">Encryption</label>
                        <select id="email_encryption" name="email_encryption">
                            <option value="tls" <?php echo ($current_settings['email_encryption'] === 'tls') ? 'selected' : ''; ?>>TLS</option>
                            <option value="ssl" <?php echo ($current_settings['email_encryption'] === 'ssl') ? 'selected' : ''; ?>>SSL</option>
                            <option value="" <?php echo ($current_settings['email_encryption'] === '') ? 'selected' : ''; ?>>None</option>
                        </select>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="email_from_name">From Name</label>
                            <input type="text" id="email_from_name" name="email_from_name"
                                value="<?php echo htmlspecialchars($current_settings['email_from_name']); ?>"
                                placeholder="VLMS System">
                        </div>

                        <div class="form-group">
                            <label for="email_from_address">From Email Address</label>
                            <input type="email" id="email_from_address" name="email_from_address"
                                value="<?php echo htmlspecialchars($current_settings['email_from_address']); ?>"
                                placeholder="noreply@company.com">
                        </div>
                    </div>

                    <div style="background: rgba(255, 193, 7, 0.1); border: 1px solid rgba(255, 193, 7, 0.3); border-radius: 8px; padding: 1rem; margin-top: 1rem;">
                        <div style="color: #856404; font-weight: 500; margin-bottom: 0.5rem;">
                            <i class="fas fa-exclamation-triangle"></i> Important Notes:
                        </div>
                        <ul style="color: #856404; font-size: 0.9rem; margin-left: 1rem;">
                            <li>For Gmail, use App Passwords instead of your regular password</li>
                            <li>Make sure "Less secure app access" is enabled for your email account</li>
                            <li>Test email functionality after saving these settings</li>
                            <li>Keep your SMTP credentials secure and never share them</li>
                        </ul>
                    </div>
                </div>

                <!-- Test Email Section -->
                <div class="form-section">
                    <h3 class="section-title">
                        <i class="fas fa-paper-plane"></i>
                        Test Email Configuration
                    </h3>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="test_email">Test Email Address</label>
                            <input type="email" id="test_email" name="test_email"
                                placeholder="Enter email to send test message">
                        </div>
                        <div class="form-group" style="display: flex; align-items: end;">
                            <button type="button" class="btn btn-secondary" onclick="sendTestEmail()" id="testEmailBtn">
                                <i class="fas fa-paper-plane"></i> Send Test Email
                            </button>
                        </div>
                    </div>

                    <div id="testEmailResult" style="margin-top: 1rem; display: none;"></div>
                </div>

                <!-- System Information -->
                <div class="form-section">
                    <h3 class="section-title">
                        <i class="fas fa-info-circle"></i>
                        System Information
                    </h3>

                    <div class="system-info-grid">
                        <div class="info-item">
                            <div class="info-label">PHP Version</div>
                            <div class="info-value"><?php echo PHP_VERSION; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Server Software</div>
                            <div class="info-value"><?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">MySQL Version</div>
                            <div class="info-value"><?php echo $conn->server_info; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Max Upload Size</div>
                            <div class="info-value"><?php echo ini_get('upload_max_filesize'); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Memory Limit</div>
                            <div class="info-value"><?php echo ini_get('memory_limit'); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Max Execution Time</div>
                            <div class="info-value"><?php echo ini_get('max_execution_time'); ?>s</div>
                        </div>
                    </div>
                </div>

                <!-- Backup Management -->
                <!--<div class="form-section">
                    <h3 class="section-title">
                        <i class="fas fa-database"></i>
                        Backup Management
                    </h3>

                    <div class="backup-actions">
                        <button type="button" class="btn btn-secondary" onclick="createBackup()" id="backupBtn">
                            <i class="fas fa-download"></i> Create Database Backup
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="viewBackups()">
                            <i class="fas fa-folder-open"></i> View Existing Backups
                        </button>
                    </div>

                    <div id="deleteResult" style="margin-top: 1rem; display: none;"></div>
                    <div id="backupResult" style="margin-top: 1rem; display: none;"></div>
                    <div id="backupList" style="margin-top: 1rem; display: none;"></div>
                </div>-->

                <!-- Form actions -->
                <div class="form-actions">
                    <!--<button type="button" class="btn btn-secondary" onclick="resetForm()">
                        <i class="fas fa-undo"></i> Reset to Default
                    </button>-->
                    <button type="button" class="btn btn-secondary" onclick="window.location.href='../admin/'">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" name="update_settings" value="1" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Settings
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Create floating particles
        function createParticles() {
            const particles = document.getElementById('particles');
            const particleCount = 50;

            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                particle.classList.add('particle');
                particle.style.left = Math.random() * 100 + '%';
                particle.style.animationDelay = Math.random() * 20 + 's';
                particle.style.animationDuration = (Math.random() * 10 + 15) + 's';
                particles.appendChild(particle);
            }
        }

        // Password toggle function
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const icon = document.getElementById(fieldId + '_icon');

            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Initialize particles when page loads
        document.addEventListener('DOMContentLoaded', function() {
            createParticles();
        });

        // Test email functionality
        async function sendTestEmail() {
            const testEmailInput = document.getElementById('test_email');
            const testEmail = testEmailInput.value.trim();
            const testBtn = document.getElementById('testEmailBtn');
            const resultDiv = document.getElementById('testEmailResult');

            if (!testEmail) {
                showAlert('Please enter a test email address', 'danger');
                return;
            }

            // Show loading state
            testBtn.innerHTML = '<span class="spinner"></span> Sending...';
            testBtn.disabled = true;

            try {
                const response = await fetch('test_email.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `test_email=${encodeURIComponent(testEmail)}`
                });


                // Check if response is OK
                if (!response.ok) {
                    throw new Error(`Network response was not ok (${response.status})`);
                }

                // Optionally verify content type
                const contentType = response.headers.get('Content-Type');
                if (!contentType || !contentType.includes('application/json')) {
                    throw new Error('Invalid response format');
                }

                const result = await response.json();

                if (result.success) {
                    resultDiv.innerHTML = `<div class="alert alert-success"><i class="fas fa-check-circle"></i> Test email sent successfully to ${testEmail}</div>`;
                } else {
                    resultDiv.innerHTML = `<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Failed to send test email: ${result.message}</div>`;
                }
                resultDiv.style.display = 'block';

            } catch (error) {
                resultDiv.innerHTML = `<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Error: ${error.message}</div>`;
                resultDiv.style.display = 'block';
            } finally {
                testBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Test Email';
                testBtn.disabled = false;
            }
        }

        // Create database backup
        async function createBackup() {
            const backupBtn = document.getElementById('backupBtn');
            const resultDiv = document.getElementById('backupResult');

            // Show loading state
            backupBtn.innerHTML = '<span class="spinner"></span> Creating Backup...';
            backupBtn.disabled = true;

            try {
                const response = await fetch('create_backup', {
                    method: 'POST'
                });

                const result = await response.json();

                if (result.success) {
                    resultDiv.innerHTML = `
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> 
                            Backup created successfully: <strong>${result.filename}</strong>
                            <br>Size: ${result.size}
                        </div>`;
                } else {
                    resultDiv.innerHTML = `<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Failed to create backup: ${result.message}</div>`;
                }

                resultDiv.style.display = 'block';
            } catch (error) {
                resultDiv.innerHTML = `<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Error: ${error.message}</div>`;
                resultDiv.style.display = 'block';
            } finally {
                backupBtn.innerHTML = '<i class="fas fa-download"></i> Create Database Backup';
                backupBtn.disabled = false;
            }
            setTimeout(() => {
                resultDiv.remove();
            }, 2000);
            viewBackups(); // Refresh backup list
        }

        // View existing backups
        async function viewBackups() {
            const listDiv = document.getElementById('backupList');

            listDiv.innerHTML = '<div style="text-align: center; padding: 1rem;"><span class="spinner"></span> Loading backups...</div>';
            listDiv.style.display = 'block';

            try {
                const response = await fetch('list_backups');
                const result = await response.json();

                if (result.success && result.backups.length > 0) {
                    let html = '<div class="backup-list"><h4 style="margin-bottom: 1rem; color: #333;">Existing Backups</h4>';

                    result.backups.forEach(backup => {
                        html += `
                            <div class="backup-item">
                                <div class="backup-info">
                                    <div class="backup-name">${backup.name}</div>
                                    <div class="backup-date">${backup.date}</div>
                                </div>
                                <div class="backup-size" style="margin-right:20px;">${backup.size}</div>
                                <div class="backup-actions-item">
                                    <button class="btn btn-secondary btn-small" onclick="downloadBackup('${backup.name}')">
                                        <i class="fas fa-download"></i> Download
                                    </button>
                                    <button class="btn btn-danger btn-small" >
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                            </div>`;
                    });

                    html += '</div>';
                    listDiv.innerHTML = html;
                } else {
                    listDiv.innerHTML = '<div class="alert alert-info"><i class="fas fa-info-circle"></i> No backups found</div>';
                }
            } catch (error) {
                listDiv.innerHTML = `<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Error loading backups: ${error.message}</div>`;
            }
        }

        // Download backup
        function downloadBackup(filename) {
            window.open(`download_backup?file=${encodeURIComponent(filename)}`, '_blank');
        }

        // Delete backup with confirmation
        function deleteBackup(filename) {
            showConfirmModal(
                'Delete Backup',
                `Are you sure you want to delete the backup "${filename}"? This action cannot be undone.`,
                'Delete',
                function() {
                    performDeleteBackup(filename);
                }
            );
        }

        // Perform backup deletion
        async function performDeleteBackup(filename) {
            try {
                // Create FormData for better handling
                const formData = new FormData();
                const resultDiv = document.getElementById('deleteResult');
                formData.append('filename', filename);

                const response = await fetch('delete_backup.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                });

                // Check if response is OK
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                // Check content type
                const contentType = response.headers.get('Content-Type');
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await response.text();
                    throw new Error(`Invalid response format. Got: ${text.substring(0, 100)}`);
                }

                const result = await response.json();

                if (result.success) {
                    resultDiv.innerHTML = `
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> 
                            Backup deleted successfully: <strong>${filename}</strong>
                            <br>Size: ${result.deleted_size_formatted}
                        </div>`;
                } else {
                    resultDiv.innerHTML = `<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Failed to create backup: ${result.message}</div>`;
                }

                resultDiv.style.display = 'block';
                setTimeout(() => {
                    resultDiv.remove();
                }, 2000);
                viewBackups(); // Refresh backup list            
            } catch (error) {
                console.error('Delete backup error:', error);
                showAlert(`Error deleting backup: ${error.message}`, 'danger');
            }

        }


        // Reset form to defaults
        function resetForm() {
            showConfirmModal(
                'Reset to Defaults',
                'Are you sure you want to reset all settings to their default values? This will overwrite your current configuration.',
                'Reset',
                () => {
                    // Reset form values to defaults
                    document.getElementById('app_name').value = 'Vacation Leave Management System';
                    document.getElementById('company_name').value = '';
                    document.getElementById('company_address').value = '';
                    document.getElementById('company_phone').value = '';
                    document.getElementById('company_email').value = '';
                    document.getElementById('backup_folder').value = '../backups/';
                    document.getElementById('email_host').value = 'smtp.gmail.com';
                    document.getElementById('email_port').value = '587';
                    document.getElementById('email_username').value = '';
                    document.getElementById('email_password').value = '';
                    document.getElementById('email_encryption').value = 'tls';
                    document.getElementById('email_from_name').value = '';
                    document.getElementById('email_from_address').value = '';

                    showAlert('Form reset to default values', 'success');
                }
            );
        }

        // Show confirmation modal
        function showConfirmModal(title, message, confirmText, onConfirm) {
            const modal = document.createElement('div');
            modal.className = 'modal-overlay';
            modal.innerHTML = `
        <div class="modal">
            <div class="modal-title">
                <i class="fas fa-exclamation-triangle" style="color: #f59e0b;"></i>
                ${title}
            </div>
            <div class="modal-content">
                ${message}
            </div>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="this.closest('.modal-overlay').remove()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button class="btn btn-danger" id="confirmButton">
                    <i class="fas fa-check"></i> ${confirmText}
                </button>
            </div>
        </div>
    `;

            document.body.appendChild(modal);

            const confirmButton = modal.querySelector('#confirmButton');
            confirmButton.addEventListener('click', () => {
                modal.remove();
                onConfirm(); // Call the function directly
            });

            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.remove();
                }
            });
        }

        // Show alert message
        function showAlert(message, type) {
            // Remove any existing dynamic alerts
            const existingAlerts = document.querySelectorAll('.dynamic-alert');
            existingAlerts.forEach(alert => alert.remove());

            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} dynamic-alert`;
            alertDiv.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${message}`;
            alertDiv.style.marginBottom = '1rem';
            alertDiv.style.display = 'block';

            // Insert right after the settings subtitle
            const settingsCard = document.querySelector('.settings-card');
            const subtitle = settingsCard.querySelector('.settings-subtitle');

            if (subtitle) {
                subtitle.insertAdjacentElement('afterend', alertDiv);
            } else {
                // Fallback: insert at the beginning of settings card
                settingsCard.insertBefore(alertDiv, settingsCard.firstElementChild.nextElementSibling);
            }

            // Auto-remove success messages after 5 seconds
            if (type === 'success') {
                setTimeout(() => {
                    if (alertDiv && alertDiv.parentNode) {
                        alertDiv.remove();
                    }
                }, 5000);
            }

            // Scroll to show the alert
            alertDiv.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
        }

        // Auto-save form data to localStorage (for draft purposes)
        function autoSave() {
            const formData = new FormData(document.querySelector('form'));
            const data = {};
            for (let [key, value] of formData.entries()) {
                if (key !== 'update_settings') {
                    data[key] = value;
                }
            }
            localStorage.setItem('vlms_settings_draft', JSON.stringify(data));
        }

        // Load draft data
        function loadDraft() {
            const draft = localStorage.getItem('vlms_settings_draft');
            if (draft) {
                const data = JSON.parse(draft);
                Object.keys(data).forEach(key => {
                    const field = document.getElementById(key);
                    if (field && field.value === '') {
                        field.value = data[key];
                    }
                });
            }
        }

        // Set up auto-save on form changes
        document.addEventListener('DOMContentLoaded', function() {
            // Add event listener for dynamically created delete buttons
            document.addEventListener('click', function(e) {
                if (e.target.closest('.btn-danger') && e.target.closest('.backup-actions-item')) {
                    e.preventDefault();
                    const button = e.target.closest('.btn-danger');
                    const backupItem = button.closest('.backup-item');
                    const filename = backupItem.querySelector('.backup-name').textContent;

                    deleteBackup(filename);
                }
            });
        });
    </script>
</body>

</html>