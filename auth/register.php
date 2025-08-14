<?php 
// auth/register.php
session_start();
include '../config/db.php';

$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $employee_id = trim($_POST['employee_id']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $date_of_birth = $_POST['date_of_birth'];
    $hire_date = $_POST['hire_date'];
    $department_id = $_POST['department_id'];
    $job_role_id = $_POST['job_role_id'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validation
    if (empty($employee_id) || empty($username) || empty($email) || empty($first_name) || empty($last_name) || empty($password)) {
        $error_message = "Employee ID, Username, Email, First Name, Last Name, and Password are required.";
    } elseif ($password !== $confirm_password) {
        $error_message = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error_message = "Password must be at least 6 characters long.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Please enter a valid email address.";
    } else {
        // Check if employee_id, username or email already exists
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE employee_id = ? OR username = ? OR email = ?");
        $check_stmt->bind_param("sss", $employee_id, $username, $email);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $error_message = "Employee ID, Username or Email already exists.";
        } else {
            // Hash password and insert user
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $full_name = $first_name . ' ' . $last_name;
            $default_role_id = 3; // Employee role
            $default_status = 'inactive'; //Requires admin approval
            
            // Handle optional fields
            $phone = !empty($phone) ? $phone : null;
            $address = !empty($address) ? $address : null;
            $date_of_birth = !empty($date_of_birth) ? $date_of_birth : null;
            $hire_date = !empty($hire_date) ? $hire_date : null;
            $department_id = !empty($department_id) ? $department_id : null;
            $job_role_id = !empty($job_role_id) ? $job_role_id : null;
            
            $insert_stmt = $conn->prepare("INSERT INTO users (employee_id, username, email, password, first_name, last_name, phone, address, date_of_birth, hire_date, department_id, job_role_id, role_id, status, full_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
            $insert_stmt->bind_param("ssssssssssiisss", $employee_id, $username, $email, $hashed_password, $first_name, $last_name, $phone, $address, $date_of_birth, $hire_date, $department_id, $job_role_id, $default_role_id, $default_status, $full_name);
            
            if ($insert_stmt->execute()) {
                // Get the newly inserted user ID
                $new_user_id = $conn->insert_id;
                
                // Insert default leave balance for the new user
                $current_year = date('Y');
                $allocated_days = 42;
                $leave_type_id = 1; //Annual Leave
                $used_days = 0;
                $remaining_days = $allocated_days;
                $carried_forward = 0;
                
                $leave_balance_stmt = $conn->prepare("INSERT INTO leave_balances (user_id, leave_type_id, year, allocated_days, used_days, remaining_days, carried_forward, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                $leave_balance_stmt->bind_param("iiiiiii", $new_user_id, $leave_type_id, $current_year, $allocated_days, $used_days, $remaining_days, $carried_forward);
                
                if ($leave_balance_stmt->execute()) {
                    $success_message = "Registration successful! You can now login with your credentials.";
                } else {
                    // User was created but leave balance insert failed
                    $error_message = "Registration completed but there was an issue setting up leave balance. Please contact administrator.";
                }
                
                $leave_balance_stmt->close();
            } else {
                $error_message = "Registration failed. Please try again.";
            }
            
            $insert_stmt->close();
        }
        
        $check_stmt->close();
    }
}

// Fetch departments and job roles for dropdowns
$departments_query = "SELECT id, name FROM departments WHERE status = 'active' ORDER BY name";
$departments_result = $conn->query($departments_query);

$job_roles_query = "SELECT id, title FROM job_roles ORDER BY title";
$job_roles_result = $conn->query($job_roles_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Leave Management System</title>
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

        .register-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            padding: 48px 40px;
            width: 100%;
            max-width: 600px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            max-height: 90vh;
            overflow-y: auto;
        }

        .register-header {
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

        .register-title {
            font-size: 28px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 8px;
            letter-spacing: -0.025em;
        }

        .register-subtitle {
            font-size: 16px;
            color: #64748b;
            font-weight: 400;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group.full-width {
            grid-column: span 2;
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

        .form-input, .form-select {
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

        .form-select {
            cursor: pointer;
            padding-right: 48px;
        }

        .form-input:focus, .form-select:focus {
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

        .btn-register {
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

        .btn-register:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 24px rgba(102, 126, 234, 0.3);
        }

        .btn-register:active {
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

        .login-link {
            text-align: center;
            margin-top: 24px;
        }

        .login-link a {
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.2s ease;
        }

        .login-link a:hover {
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

        @media (max-width: 640px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .form-group.full-width {
                grid-column: span 1;
            }
            
            .register-container {
                padding: 32px 24px;
            }
            
            .register-title {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-header">
            <div class="logo">
                <i class="fas fa-user-plus"></i>
            </div>
            <h1 class="register-title">Create Account</h1>
            <p class="register-subtitle">VLMS Employee Account Registration</p>
        </div>

        <form method="POST" id="registerForm">
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

            <div class="form-row">
                <div class="form-group">
                    <label for="employee_id" class="form-label">Employee ID<span class="required">*</span></label>
                    <div class="input-wrapper">
                        <input 
                            type="text" 
                            id="employee_id" 
                            name="employee_id" 
                            class="form-input" 
                            required
                            value="<?php echo isset($_POST['employee_id']) ? htmlspecialchars($_POST['employee_id']) : ''; ?>"
                        >
                        <i class="fas fa-id-badge input-icon"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label for="username" class="form-label">Username<span class="required">*</span></label>
                    <div class="input-wrapper">
                        <input 
                            type="text" 
                            id="username" 
                            name="username" 
                            class="form-input" 
                            required
                            value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                        >
                        <i class="fas fa-at input-icon"></i>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="email" class="form-label">Email Address<span class="required">*</span></label>
                <div class="input-wrapper">
                    <input 
                        type="email" 
                        id="email" 
                        name="email" 
                        class="form-input" 
                        required
                        value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                    >
                    <i class="fas fa-envelope input-icon"></i>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="first_name" class="form-label">First Name<span class="required">*</span></label>
                    <div class="input-wrapper">
                        <input 
                            type="text" 
                            id="first_name" 
                            name="first_name" 
                            class="form-input" 
                            required
                            value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>"
                        >
                        <i class="fas fa-user input-icon"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label for="last_name" class="form-label">Last Name<span class="required">*</span></label>
                    <div class="input-wrapper">
                        <input 
                            type="text" 
                            id="last_name" 
                            name="last_name" 
                            class="form-input" 
                            required
                            value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>"
                        >
                        <i class="fas fa-user input-icon"></i>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="phone" class="form-label">Phone Number</label>
                    <div class="input-wrapper">
                        <input 
                            type="tel" 
                            id="phone" 
                            name="phone" 
                            class="form-input"
                            value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>"
                        >
                        <i class="fas fa-phone input-icon"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label for="date_of_birth" class="form-label">Date of Birth</label>
                    <div class="input-wrapper">
                        <input 
                            type="date" 
                            id="date_of_birth" 
                            name="date_of_birth" 
                            class="form-input"
                            value="<?php echo isset($_POST['date_of_birth']) ? htmlspecialchars($_POST['date_of_birth']) : ''; ?>"
                        >
                        <i class="fas fa-calendar input-icon"></i>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="address" class="form-label">Address</label>
                <div class="input-wrapper">
                    <input 
                        type="text" 
                        id="address" 
                        name="address" 
                        class="form-input"
                        value="<?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?>"
                    >
                    <i class="fas fa-map-marker-alt input-icon"></i>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="hire_date" class="form-label">Hire Date</label>
                    <div class="input-wrapper">
                        <input 
                            type="date" 
                            id="hire_date" 
                            name="hire_date" 
                            class="form-input"
                            value="<?php echo isset($_POST['hire_date']) ? htmlspecialchars($_POST['hire_date']) : ''; ?>"
                        >
                        <i class="fas fa-calendar-plus input-icon"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label for="department_id" class="form-label">Department</label>
                    <div class="input-wrapper">
                        <select id="department_id" name="department_id" class="form-select">
                            <option value="">Select Department</option>
                            <?php while($dept = $departments_result->fetch_assoc()): ?>
                                <option value="<?php echo $dept['id']; ?>" 
                                    <?php echo (isset($_POST['department_id']) && $_POST['department_id'] == $dept['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                        <i class="fas fa-building input-icon"></i>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="job_role_id" class="form-label">Job Role</label>
                <div class="input-wrapper">
                    <select id="job_role_id" name="job_role_id" class="form-select">
                        <option value="">Select Job Role</option>
                        <?php while($role = $job_roles_result->fetch_assoc()): ?>
                            <option value="<?php echo $role['id']; ?>" 
                                <?php echo (isset($_POST['job_role_id']) && $_POST['job_role_id'] == $role['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($role['title']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <i class="fas fa-briefcase input-icon"></i>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="password" class="form-label">Password<span class="required">*</span></label>
                    <div class="input-wrapper">
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            class="form-input" 
                            required
                            minlength="6"
                        >
                        <i class="fas fa-lock input-icon"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirm_password" class="form-label">Confirm Password<span class="required">*</span></label>
                    <div class="input-wrapper">
                        <input 
                            type="password" 
                            id="confirm_password" 
                            name="confirm_password" 
                            class="form-input" 
                            required
                            minlength="6"
                        >
                        <i class="fas fa-lock input-icon"></i>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-register">
                <i class="fas fa-user-plus" style="margin-right: 8px;"></i>
                Create Account
            </button>
        </form>

        <div class="login-link">
            <span>Already have an account? </span>
            <a href="login">Sign in here</a>
        </div>

        <div class="footer-text">
            <i class="fas fa-info-circle" style="margin-right: 8px; color: #3b82f6;"></i>
            Please fill in all required fields marked with * <br>
            <i class="fas fa-info-circle" style="margin-right: 8px; color: #3b82f6;"></i>
            This account will be subject for approval by the admin before it can be used.
        </div>
    </div>

    <script>
        // Password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;
            
            if (password !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });

        // Form submission loading state
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('.btn-register');
            const originalText = submitBtn.innerHTML;
            
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right: 8px;"></i>Creating Account...';
            submitBtn.disabled = true;
            
            // Re-enable button after 3 seconds in case of form validation errors
            setTimeout(() => {
                if (submitBtn.disabled) {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }
            }, 3000);
        });

        // Auto-focus first field
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('employee_id').focus();
        });

        // Generate employee ID suggestion
        document.getElementById('first_name').addEventListener('blur', generateEmployeeId);
        document.getElementById('last_name').addEventListener('blur', generateEmployeeId);

        function generateEmployeeId() {
            const firstName = document.getElementById('first_name').value;
            const lastName = document.getElementById('last_name').value;
            const employeeIdField = document.getElementById('employee_id');
            
            if (firstName && lastName && !employeeIdField.value) {
                const suggestion = 'EMP' + (firstName.substring(0,2) + lastName.substring(0,2)).toUpperCase() + Math.floor(Math.random() * 100);
                employeeIdField.value = suggestion;
            }
        }
    </script>
</body>
</html>