<?php
session_start();
include '../config/db.php';

// Verify user authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'User';

// Fetch current user data
$result = $conn->query("SELECT u.*, d.name as department_name, jr.title as job_role_title 
                        FROM users u 
                        LEFT JOIN departments d ON u.department_id = d.id 
                        LEFT JOIN job_roles jr ON u.job_role_id = jr.id 
                        WHERE u.id = $user_id");
$user = $result->fetch_assoc();

// Fetch departments for dropdown
$departments_result = $conn->query("SELECT id, name FROM departments ORDER BY name");
$departments = [];
while ($row = $departments_result->fetch_assoc()) {
    $departments[] = $row;
}

// Fetch job roles for dropdown
$job_roles_result = $conn->query("SELECT id, title FROM job_roles ORDER BY title");
$job_roles = [];
while ($row = $job_roles_result->fetch_assoc()) {
    $job_roles[] = $row;
}
// Initialize messages
$success_message = '';
$error_message = '';
$password_success_message = '';
$password_error_message = '';
$image_success_message = '';
$image_error_message = '';

// Handle profile picture upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile']) && isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] !== UPLOAD_ERR_NO_FILE) {
    $upload_dir = '../uploads/profile_images/';

    // Create directory if it doesn't exist
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $file = $_FILES['profile_image'];
    $file_name = $file['name'];
    $file_tmp = $file['tmp_name'];
    $file_size = $file['size'];
    $file_error = $file['error'];

    if ($file_error === 0) {
        // Get file extension
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed_ext = array('jpg', 'jpeg', 'png', 'gif');

        if (in_array($file_ext, $allowed_ext)) {
            if ($file_size <= 5000000) { // 5MB limit
                // Generate unique filename
                $new_filename = 'profile_' . $user_id . '_' . time() . '.' . $file_ext;
                $file_destination = $upload_dir . $new_filename;

                if (move_uploaded_file($file_tmp, $file_destination)) {
                    // Delete old profile image if exists
                    if (!empty($user['profile_image']) && file_exists('../' . $user['profile_image'])) {
                        unlink('../' . $user['profile_image']);
                    }

                    // Update database with new profile image
                    $profile_image_path = 'uploads/profile_images/' . $new_filename;
                    $stmt = $conn->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
                    $stmt->bind_param("si", $profile_image_path, $user_id);

                    if ($stmt->execute()) {
                        $image_success_message = 'Profile picture updated successfully!';
                    } else {
                        $image_error_message = 'Error updating profile picture in database.';
                    }
                } else {
                    $image_error_message = 'Error uploading file.';
                }
            } else {
                $image_error_message = 'File size too large. Maximum size is 5MB.';
            }
        } else {
            $image_error_message = 'Invalid file type. Only JPG, JPEG, PNG, and GIF are allowed.';
        }
    } else {
        $image_error_message = 'Error uploading file.';
    }
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_profile'])) {
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $address = trim($_POST['address']);
        $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $job_role_id = !empty($_POST['job_role_id']) ? (int)$_POST['job_role_id'] : null;

        // Basic validation
        if (empty($first_name) || empty($last_name) || empty($email)) {
            $error_message = 'First name, last name, and email are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_message = 'Please enter a valid email address.';
        } else {
            // Check if email already exists for another user
            $email_check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $email_check->bind_param("si", $email, $user_id);
            $email_check->execute();
            $email_result = $email_check->get_result();

            if ($email_result->num_rows > 0) {
                $error_message = 'This email address is already in use by another user.';
            } else {
                $stmt = $conn->prepare("UPDATE users SET first_name=?, last_name=?, email=?, phone=?, address=?, department_id=?, job_role_id=? WHERE id=?");
                $stmt->bind_param("ssssssii", $first_name, $last_name, $email, $phone, $address, $department_id, $job_role_id, $user_id);

                if ($stmt->execute()) {
                    $success_message = 'Profile updated successfully!';
                    // Refresh user data
                    $result = $conn->query("SELECT u.*, d.name as department_name, jr.title as job_role_title 
                                          FROM users u 
                                          LEFT JOIN departments d ON u.department_id = d.id 
                                          LEFT JOIN job_roles jr ON u.job_role_id = jr.id 
                                          WHERE u.id = $user_id");
                    $user = $result->fetch_assoc();
                } else {
                    $error_message = 'Error updating profile. Please try again.';
                }
            }
        }
    }

    // Handle password change
    if (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        // Validation
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $password_error_message = 'All password fields are required.';
        } elseif ($new_password !== $confirm_password) {
            $password_error_message = 'New password and confirm password do not match.';
        } elseif (strlen($new_password) < 6) {
            $password_error_message = 'New password must be at least 6 characters long.';
        } else {
            // Verify current password
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user_data = $result->fetch_assoc();

            if (password_verify($current_password, $user_data['password'])) {
                // Hash new password and update
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->bind_param("si", $hashed_password, $user_id);

                if ($stmt->execute()) {
                    $password_success_message = 'Password changed successfully!';
                } else {
                    $password_error_message = 'Error changing password. Please try again.';
                }
            } else {
                $password_error_message = 'Current password is incorrect.';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>User Profile - HRMS</title>
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
        }

        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            color: white;
        }

        .logo {
            display: flex;
            align-items: center;
            font-size: 20px;
            font-weight: 600;
        }

        .logo i {
            margin-right: 10px;
            font-size: 24px;
        }

        .user-nav {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .user-nav a {
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 8px;
            transition: background-color 0.3s ease;
        }

        .user-nav a:hover {
            background-color: rgba(255, 255, 255, 0.1);
            text-decoration: none;
            color: white;
        }

        /* Tab Navigation */
        .tab-navigation {
            display: flex;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px 20px 0 0;
            margin-bottom: 0;
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .tab-button {
            flex: 1;
            padding: 20px;
            background: none;
            border: none;
            font-size: 16px;
            font-weight: 600;
            color: #666;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .tab-button.active {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .tab-button:hover:not(.active) {
            background: rgba(102, 126, 234, 0.1);
            color: #667eea;
        }

        /* Profile Card */
        .profile-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 0 0 20px 20px;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 20px;
        }

        .profile-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 40px;
            text-align: center;
        }

        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 48px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            border: 3px solid rgba(255, 255, 255, 0.3);
        }

        .profile-avatar:hover {
            transform: scale(1.05);
            border-color: rgba(255, 255, 255, 0.6);
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .profile-avatar .upload-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
            border-radius: 50%;
        }

        .profile-avatar:hover .upload-overlay {
            opacity: 1;
        }

        .profile-avatar .upload-overlay i {
            color: white;
            font-size: 24px;
        }

        .profile-avatar .upload-overlay span {
            color: white;
            font-size: 12px;
            position: absolute;
            bottom: 15px;
            white-space: nowrap;
        }

        #profile-image-input {
            display: none;
        }

        .profile-title {
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .profile-subtitle {
            font-size: 16px;
            opacity: 0.9;
        }

        /* Tab Content */
        .tab-content {
            display: none;
            padding: 40px;
        }

        .tab-content.active {
            display: block;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 24px;
        }

        .form-label {
            display: flex;
            align-items: center;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .form-label i {
            margin-right: 8px;
            color: #667eea;
            width: 16px;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e1e5e9;
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-control:read-only {
            background: #f8f9fa;
            color: #6c757d;
        }

        select.form-control {
            background: white;
            color: #333;
            border: 2px solid #e1e5e9;
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 16px;
            transition: all 0.3s ease;
            appearance: none;
        }


        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-help {
            font-size: 12px;
            color: #6c757d;
            margin-top: 4px;
        }

        /* Password Strength Indicator */
        .password-strength {
            margin-top: 8px;
            font-size: 12px;
        }

        .strength-bar {
            height: 4px;
            border-radius: 2px;
            background: #e1e5e9;
            margin: 4px 0;
            overflow: hidden;
        }

        .strength-fill {
            height: 100%;
            width: 0%;
            transition: all 0.3s ease;
        }

        .strength-weak .strength-fill {
            width: 33%;
            background: #dc3545;
        }

        .strength-medium .strength-fill {
            width: 66%;
            background: #ffc107;
        }

        .strength-strong .strength-fill {
            width: 100%;
            background: #28a745;
        }

        /* Buttons */
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 16px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
            color: white;
            text-decoration: none;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
            color: white;
            text-decoration: none;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-2px);
            color: white;
            text-decoration: none;
        }

        .button-group {
            display: flex;
            gap: 16px;
            justify-content: center;
            margin-top: 32px;
        }

        /* Alerts */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            position: relative;
        }

        .alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .alert-danger {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        .alert-close {
            position: absolute;
            top: 16px;
            right: 20px;
            background: none;
            border: none;
            font-size: 18px;
            cursor: pointer;
            opacity: 0.6;
        }

        .alert-close:hover {
            opacity: 1;
        }

        /* Validation */
        .form-control.invalid {
            border-color: #dc3545;
            box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.1);
        }

        .form-control.valid {
            border-color: #28a745;
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
        }

        .invalid-feedback {
            color: #dc3545;
            font-size: 14px;
            margin-top: 4px;
            display: none;
        }

        .valid-feedback {
            color: #28a745;
            font-size: 14px;
            margin-top: 4px;
            display: none;
        }

        .form-control.invalid+.invalid-feedback {
            display: block;
        }

        .form-control.valid+.valid-feedback {
            display: block;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }

            .header {
                flex-direction: column;
                gap: 20px;
                text-align: center;
            }

            .tab-content {
                padding: 20px;
            }

            .form-row {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .button-group {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .profile-header {
                padding: 30px 20px;
            }

            .profile-title {
                font-size: 24px;
            }

            .tab-button {
                padding: 15px 10px;
                font-size: 14px;
            }
        }

        @media (max-width: 480px) {
            .user-nav {
                flex-direction: column;
                gap: 10px;
            }

            .profile-avatar {
                width: 80px;
                height: 80px;
                font-size: 36px;
            }

            .tab-navigation {
                flex-direction: column;
            }
        }

        /* Loading animation */
        .profile-card {
            animation: fadeInUp 0.6s ease-out;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>

<body>

    <div class="container">
        <!-- Header -->
        <header class="header">
            <div class="logo">
                <i class="fas fa-user-circle"></i>
                User Profile
            </div>
            <nav class="user-nav">
                <a href="../index">
                    <i class="fas fa-home"></i>
                    Dashboard
                </a>
                <a href="../auth/logout">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </a>
            </nav>
        </header>

        <!-- Profile Header -->
        <div class="profile-header">
            <div class="profile-avatar" onclick="document.getElementById('profile-image-input').click()">
                <?php if (!empty($user['profile_image']) && file_exists('../' . $user['profile_image'])): ?>
                    <img src="../<?php echo htmlspecialchars($user['profile_image']); ?>" alt="Profile Picture">
                <?php else: ?>
                    <i class="fas fa-user"></i>
                <?php endif; ?>
                <div class="upload-overlay">
                    <i class="fas fa-camera"></i>
                    <span>Click to change</span>
                </div>
            </div>
            <h1 class="profile-title"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h1>
            <p class="profile-subtitle">Manage your personal information</p>
        </div>

        <!-- Tab Navigation -->
        <div class="tab-navigation">
            <button class="tab-button active" onclick="switchTab('profile')">
                <i class="fas fa-user-edit"></i>
                Profile Information
            </button>
            <button class="tab-button" onclick="switchTab('password')">
                <i class="fas fa-lock"></i>
                Change Password
            </button>
        </div>

        <!-- Profile Card -->
        <div class="profile-card">
            <!-- Profile Tab -->
            <div id="profile-tab" class="tab-content active">
                <?php if ($image_success_message): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo $image_success_message; ?>
                        <button type="button" class="alert-close" onclick="this.parentElement.style.display='none'">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                <?php endif; ?>

                <?php if ($image_error_message): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?php echo $image_error_message; ?>
                        <button type="button" class="alert-close" onclick="this.parentElement.style.display='none'">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                <?php endif; ?>

                <?php if ($success_message): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo $success_message; ?>
                        <button type="button" class="alert-close" onclick="this.parentElement.style.display='none'">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?php echo $error_message; ?>
                        <button type="button" class="alert-close" onclick="this.parentElement.style.display='none'">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                <?php endif; ?>

                <form method="POST" class="profile-form" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="update_profile" value="1">
                    <input type="file" id="profile-image-input" name="profile_image" accept="image/*" style="display: none;">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="employee_id" class="form-label">
                                <i class="fas fa-id-badge"></i>Employee ID
                            </label>
                            <input
                                type="text"
                                name="employee_id"
                                id="employee_id"
                                class="form-control"
                                value="<?php echo htmlspecialchars($user['employee_id'] ?? 'Not Assigned'); ?>"
                                readonly />
                            <div class="form-help">Employee ID is assigned by system administrator</div>
                        </div>

                        <div class="form-group">
                            <label for="username" class="form-label">
                                <i class="fas fa-user-tag"></i>Username
                            </label>
                            <input
                                type="text"
                                name="username"
                                id="username"
                                class="form-control"
                                value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>"
                                readonly />
                            <div class="form-help">Username cannot be changed after account creation</div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name" class="form-label">
                                <i class="fas fa-user"></i>First Name
                            </label>
                            <input
                                type="text"
                                name="first_name"
                                id="first_name"
                                class="form-control"
                                value="<?php echo htmlspecialchars($user['first_name']); ?>"
                                required />
                            <div class="invalid-feedback">
                                Please provide a valid first name.
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="last_name" class="form-label">
                                <i class="fas fa-user"></i>Last Name
                            </label>
                            <input
                                type="text"
                                name="last_name"
                                id="last_name"
                                class="form-control"
                                value="<?php echo htmlspecialchars($user['last_name']); ?>"
                                required />
                            <div class="invalid-feedback">
                                Please provide a valid last name.
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="email" class="form-label">
                                <i class="fas fa-envelope"></i>Email Address
                            </label>
                            <input
                                type="email"
                                name="email"
                                id="email"
                                class="form-control"
                                value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>"
                                required />
                            <div class="invalid-feedback">
                                Please provide a valid email address.
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="phone" class="form-label">
                                <i class="fas fa-phone"></i>Phone Number
                            </label>
                            <input
                                type="tel"
                                name="phone"
                                id="phone"
                                class="form-control"
                                value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>"
                                placeholder="Enter your phone number" />
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="department_id" class="form-label">
                                <i class="fas fa-building"></i>Department
                            </label>
                            <select name="department_id" id="department_id" class="form-control">
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $department): ?>
                                    <option value="<?php echo $department['id']; ?>"
                                        <?php echo ($user['department_id'] == $department['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($department['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="job_role_id" class="form-label">
                                <i class="fas fa-briefcase"></i>Job Position
                            </label>
                            <select name="job_role_id" id="job_role_id" class="form-control">
                                <option value="">Select Job Position</option>
                                <?php foreach ($job_roles as $job_role): ?>
                                    <option value="<?php echo $job_role['id']; ?>"
                                        <?php echo ($user['job_role_id'] == $job_role['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($job_role['title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="address" class="form-label">
                            <i class="fas fa-map-marker-alt"></i>Address
                        </label>
                        <textarea
                            name="address"
                            id="address"
                            class="form-control"
                            rows="4"
                            placeholder="Enter your complete address"
                            style="resize: vertical; min-height: 100px;"><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
                    </div>

                    <div class="button-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i>Update Profile
                        </button>
                        <a href="../index" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i>Back to Dashboard
                        </a>
                    </div>
                </form>
            </div>

            <!-- Password Tab -->
            <div id="password-tab" class="tab-content">
                <?php if ($password_success_message): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo $password_success_message; ?>
                        <button type="button" class="alert-close" onclick="this.parentElement.style.display='none'">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                <?php endif; ?>

                <?php if ($password_error_message): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?php echo $password_error_message; ?>
                        <button type="button" class="alert-close" onclick="this.parentElement.style.display='none'">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                <?php endif; ?>

                <form method="POST" class="password-form" novalidate>
                    <input type="hidden" name="change_password" value="1">

                    <div class="form-group">
                        <label for="current_password" class="form-label">
                            <i class="fas fa-lock"></i>Current Password
                        </label>
                        <input
                            type="password"
                            name="current_password"
                            id="current_password"
                            class="form-control"
                            required
                            placeholder="Enter your current password" />
                        <div class="invalid-feedback">
                            Please enter your current password.
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="new_password" class="form-label">
                            <i class="fas fa-key"></i>New Password
                        </label>
                        <input
                            type="password"
                            name="new_password"
                            id="new_password"
                            class="form-control"
                            required
                            placeholder="Enter your new password"
                            minlength="6" />
                        <div class="password-strength">
                            <div class="strength-bar">
                                <div class="strength-fill"></div>
                            </div>
                            <span class="strength-text">Password strength will be shown here</span>
                        </div>
                        <div class="invalid-feedback">
                            Password must be at least 6 characters long.
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password" class="form-label">
                            <i class="fas fa-check-circle"></i>Confirm New Password
                        </label>
                        <input
                            type="password"
                            name="confirm_password"
                            id="confirm_password"
                            class="form-control"
                            required
                            placeholder="Confirm your new password" />
                        <div class="invalid-feedback">
                            Passwords do not match.
                        </div>
                        <div class="valid-feedback">
                            Passwords match!
                        </div>
                    </div>

                    <div class="button-group">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-key"></i>Change Password
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="document.querySelector('.password-form').reset(); resetPasswordValidation();">
                            <i class="fas fa-undo"></i>Reset Form
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Tab switching
            window.switchTab = function(tabName) {
                // Hide all tab contents
                document.querySelectorAll('.tab-content').forEach(content => {
                    content.classList.remove('active');
                });

                // Remove active class from all tab buttons
                document.querySelectorAll('.tab-button').forEach(button => {
                    button.classList.remove('active');
                });

                // Show selected tab content
                document.getElementById(tabName + '-tab').classList.add('active');

                // Add active class to selected button
                event.target.classList.add('active');
            };

            // Profile form validation
            const profileForm = document.querySelector('.profile-form');
            const profileInputs = profileForm.querySelectorAll('input[required]');

            // Password form validation
            const passwordForm = document.querySelector('.password-form');
            const currentPassword = document.getElementById('current_password');
            const newPassword = document.getElementById('new_password');
            const confirmPassword = document.getElementById('confirm_password');

            function validateField(field) {
                const isValid = field.value.trim() !== '';

                if (isValid) {
                    field.classList.remove('invalid');
                    field.classList.add('valid');
                } else {
                    field.classList.add('invalid');
                    field.classList.remove('valid');
                }

                return isValid;
            }

            function validatePasswordMatch() {
                const newPass = newPassword.value;
                const confirmPass = confirmPassword.value;

                if (confirmPass === '') {
                    confirmPassword.classList.remove('valid', 'invalid');
                    return false;
                }

                if (newPass === confirmPass) {
                    confirmPassword.classList.remove('invalid');
                    confirmPassword.classList.add('valid');
                    return true;
                } else {
                    confirmPassword.classList.remove('valid');
                    confirmPassword.classList.add('invalid');
                    return false;
                }
            }

            function checkPasswordStrength(password) {
                const strengthBar = document.querySelector('.password-strength');
                const strengthText = document.querySelector('.strength-text');

                if (password === '') {
                    strengthBar.className = 'password-strength';
                    strengthText.textContent = 'Password strength will be shown here';
                    return;
                }

                let strength = 0;

                // Length check
                if (password.length >= 8) strength++;
                if (password.length >= 12) strength++;

                // Character variety checks
                if (/[a-z]/.test(password)) strength++;
                if (/[A-Z]/.test(password)) strength++;
                if (/[0-9]/.test(password)) strength++;
                if (/[^A-Za-z0-9]/.test(password)) strength++;

                if (strength < 3) {
                    strengthBar.className = 'password-strength strength-weak';
                    strengthText.textContent = 'Weak password';
                } else if (strength < 5) {
                    strengthBar.className = 'password-strength strength-medium';
                    strengthText.textContent = 'Medium strength password';
                } else {
                    strengthBar.className = 'password-strength strength-strong';
                    strengthText.textContent = 'Strong password';
                }
            }

            // Real-time validation for profile form
            profileInputs.forEach(input => {
                input.addEventListener('blur', () => validateField(input));
                input.addEventListener('input', () => {
                    if (input.classList.contains('invalid')) {
                        validateField(input);
                    }
                });
            });

            // Real-time validation for password form
            currentPassword.addEventListener('blur', () => validateField(currentPassword));
            currentPassword.addEventListener('input', () => {
                if (currentPassword.classList.contains('invalid')) {
                    validateField(currentPassword);
                }
            });

            newPassword.addEventListener('input', () => {
                checkPasswordStrength(newPassword.value);
                if (newPassword.value.length >= 6) {
                    newPassword.classList.remove('invalid');
                    newPassword.classList.add('valid');
                } else if (newPassword.value.length > 0) {
                    newPassword.classList.add('invalid');
                    newPassword.classList.remove('valid');
                } else {
                    newPassword.classList.remove('invalid', 'valid');
                }

                // Recheck password match if confirm password has value
                if (confirmPassword.value !== '') {
                    validatePasswordMatch();
                }
            });

            confirmPassword.addEventListener('input', () => {
                validatePasswordMatch();
            });

            // Form submission validation
            profileForm.addEventListener('submit', function(e) {
                let isFormValid = true;

                profileInputs.forEach(input => {
                    if (!validateField(input)) {
                        isFormValid = false;
                    }
                });

                if (!isFormValid) {
                    e.preventDefault();
                    e.stopPropagation();

                    // Focus on first invalid field
                    const firstInvalid = profileForm.querySelector('.invalid');
                    if (firstInvalid) {
                        firstInvalid.focus();
                    }
                }
            });

            passwordForm.addEventListener('submit', function(e) {
                let isFormValid = true;

                // Validate current password
                if (!validateField(currentPassword)) {
                    isFormValid = false;
                }

                // Validate new password
                if (newPassword.value.length < 6) {
                    newPassword.classList.add('invalid');
                    isFormValid = false;
                }

                // Validate password match
                if (!validatePasswordMatch()) {
                    isFormValid = false;
                }

                if (!isFormValid) {
                    e.preventDefault();
                    e.stopPropagation();

                    // Focus on first invalid field
                    const firstInvalid = passwordForm.querySelector('.invalid');
                    if (firstInvalid) {
                        firstInvalid.focus();
                    }
                }
            });

            // Reset password validation
            window.resetPasswordValidation = function() {
                const passwordInputs = passwordForm.querySelectorAll('input');
                passwordInputs.forEach(input => {
                    input.classList.remove('valid', 'invalid');
                });

                const strengthBar = document.querySelector('.password-strength');
                const strengthText = document.querySelector('.strength-text');
                strengthBar.className = 'password-strength';
                strengthText.textContent = 'Password strength will be shown here';
            };

            // Auto-hide alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(-20px)';
                    setTimeout(() => {
                        alert.style.display = 'none';
                    }, 300);
                }, 5000);
            });

            // Add smooth interactions for buttons
            const buttons = document.querySelectorAll('.btn');
            buttons.forEach(button => {
                button.addEventListener('mousedown', function() {
                    this.style.transform = 'translateY(0px) scale(0.98)';
                });

                button.addEventListener('mouseup', function() {
                    this.style.transform = 'translateY(-2px) scale(1)';
                });

                button.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0px) scale(1)';
                });
            });

            // Show/hide password toggle functionality
            const passwordToggles = document.querySelectorAll('.password-toggle');
            passwordToggles.forEach(toggle => {
                toggle.addEventListener('click', function() {
                    const targetInput = document.querySelector(this.getAttribute('data-target'));
                    const icon = this.querySelector('i');

                    if (targetInput.type === 'password') {
                        targetInput.type = 'text';
                        icon.classList.remove('fa-eye');
                        icon.classList.add('fa-eye-slash');
                    } else {
                        targetInput.type = 'password';
                        icon.classList.remove('fa-eye-slash');
                        icon.classList.add('fa-eye');
                    }
                });
            });
        });

        document.getElementById('profile-image-input').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                // Validate file type
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Please select a valid image file (JPG, JPEG, PNG, or GIF)');
                    this.value = '';
                    return;
                }

                // Validate file size (5MB)
                if (file.size > 5 * 1024 * 1024) {
                    alert('File size must be less than 5MB');
                    this.value = '';
                    return;
                }

                // Create preview
                const reader = new FileReader();
                reader.onload = function(e) {
                    const profileAvatar = document.querySelector('.profile-avatar');
                    profileAvatar.innerHTML = `
                <img src="${e.target.result}" alt="Profile Preview">
                <div class="upload-overlay">
                    <i class="fas fa-camera"></i>
                    <span>Click to change</span>
                </div>
            `;
                };
                reader.readAsDataURL(file);
            }
        });
    </script>

</body>

</html>