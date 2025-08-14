<?php
// user_management.php
session_start();
include '../config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login');
    exit();
}

// Handle role switching for admins
if (isset($_POST['switch_role'])) {
    if ($_SESSION['original_role'] === 'Admin') {
        $_SESSION['current_role'] = $_POST['switch_role'];
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Handle returning to admin role
if (isset($_POST['return_admin'])) {
    if (isset($_SESSION['original_role']) && $_SESSION['original_role'] === 'Admin') {
        $_SESSION['current_role'] = 'Admin';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Fetch user info
$user_id = $_SESSION['user_id'];
$result = $conn->query("SELECT * FROM users WHERE id = $user_id");
$user = $result->fetch_assoc();

// Get original role
$role_id = $user['role_id'];
$result = $conn->query("SELECT name FROM roles WHERE id = $role_id");
$original_role = $result->fetch_assoc()['name'];

// Store original role in session if not set
if (!isset($_SESSION['original_role'])) {
    $_SESSION['original_role'] = $original_role;
}

// Determine current role
$current_role = isset($_SESSION['current_role']) ? $_SESSION['current_role'] : $original_role;
$role = $current_role;

// Check if user is browsing as a different role
$is_role_switching = ($original_role === 'Admin' && $current_role !== 'Admin');

// Check permissions - only Admin and HR can access user management
if ($role !== 'Admin' && $role !== 'HR') {
    header('Location: dashboard');
    exit();
}

// Handle user operations
$message = '';
$message_type = '';
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    
    // Clear the message from session after displaying
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

// Add new user
if (isset($_POST['add_user'])) {
    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $email = $_POST['email'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $department_id = $_POST['department_id'];
    $job_role_id = $_POST['job_role_id'];
    $role_id = $_POST['role_id'];
    
    $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, email, password, department_id, job_role_id, role_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssii", $first_name, $last_name, $email, $password, $department_id, $job_role_id, $role_id);
    
    if ($stmt->execute()) {
        $_SESSION['message'] = "User added successfully!";
        $_SESSION['message_type'] = "success";
    } else {
        $_SESSION['message'] = "Error adding user: " . $conn->error;
        $_SESSION['message_type'] = "error";
    }
    
    // Redirect to prevent resubmission
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Update user
if (isset($_POST['update_user'])) {
    $update_id = $_POST['user_id'];
    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $email = $_POST['email'];
    $department_id = $_POST['department_id'];
    $job_role_id = $_POST['job_role_id'];
    $role_id = $_POST['role_id'];
    
    $stmt = $conn->prepare("UPDATE users SET first_name=?, last_name=?, email=?, department_id=?, job_role_id=?, role_id=? WHERE id=?");
    $stmt->bind_param("sssssii", $first_name, $last_name, $email, $department_id, $job_role_id, $role_id, $update_id);
    
    if ($stmt->execute()) {
        $_SESSION['message'] = "User updated successfully!";
        $_SESSION['message_type'] = "success";
    } else {
        $_SESSION['message'] = "Error updating user: " . $conn->error;
        $_SESSION['message_type'] = "error";
    }
    
    // Redirect to prevent resubmission
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Delete user
if (isset($_POST['delete_user'])) {
    $delete_id = $_POST['user_id'];
    
    $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
    $stmt->bind_param("i", $delete_id);
    
    if ($stmt->execute()) {
        $_SESSION['message'] = "User deleted successfully!";
        $_SESSION['message_type'] = "success";
    } else {
        $_SESSION['message'] = "Error deleting user: " . $conn->error;
        $_SESSION['message_type'] = "error";
    }
    
    // Redirect to prevent resubmission
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Fetch all users with their department and role info
$users_query = "
    SELECT u.*, d.name as department_name, jr.title as job_role_title, r.name as role_name 
    FROM users u 
    LEFT JOIN departments d ON u.department_id = d.id 
    LEFT JOIN job_roles jr ON u.job_role_id = jr.id 
    LEFT JOIN roles r ON u.role_id = r.id 
    ORDER BY u.id
";
$users_result = $conn->query($users_query);

// Fetch departments, job roles, and roles for dropdowns
$departments = $conn->query("SELECT * FROM departments ORDER BY name");
$job_roles = $conn->query("SELECT * FROM job_roles ORDER BY title");
$roles = $conn->query("SELECT * FROM roles ORDER BY name");

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VLMS - User Management</title>
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

        /* Admin role switching bar */
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

        .employee-role-bar {
            background: linear-gradient(135deg, #667eea, #764ba2);
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

        .hr-role-bar {
            background: linear-gradient(135deg, #f093fb, #f5576c);
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

        .admin-role-controls {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .role-control-btn {
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.3s ease;
            cursor: pointer;
            white-space: nowrap;
        }

        .role-control-btn:hover {
            background: rgba(255, 255, 255, 0.5);
            transform: translateY(-1px);
            color: white;
        }

        .role-control-btn.active {
            background: rgba(255, 255, 255, 0.9);
            color: #333;
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
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Page header */
        .page-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            animation: slideUp 0.8s ease-out;
        }

        .page-title {
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea, #764ba2);
            background-clip: text;
            color: transparent;
            margin-bottom: 0.5rem;
        }

        .page-subtitle {
            color: #666;
            font-size: 1rem;
        }

        /* Message alerts */
        .message {
            padding: 1rem 1.5rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            animation: slideDown 0.5s ease-out;
        }

        .message.success {
            background: rgba(34, 197, 94, 0.8);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #f6faf9ff;
        }

        .message.error {
            background: rgba(239, 68, 68, 0.8);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #f7f3f3ff;
        }

        /* Form styles */
        .form-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            animation: slideUp 0.8s ease-out 0.1s both;
        }

        .form-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: #333;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #374151;
        }

        .form-input {
            padding: 0.75rem;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: white;
        }

        .form-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 10px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(239, 68, 68, 0.4);
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(16, 185, 129, 0.4);
        }

        /* Table styles */
        .table-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            animation: slideUp 0.8s ease-out 0.2s both;
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .table th {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            border: none;
        }

        .table th:first-child {
            border-radius: 10px 0 0 0;
        }

        .table th:last-child {
            border-radius: 0 10px 0 0;
        }

        .table td {
            padding: 1rem;
            border-bottom: 1px solid #e5e7eb;
            background: white;
        }

        .table tr:last-child td:first-child {
            border-radius: 0 0 0 10px;
        }

        .table tr:last-child td:last-child {
            border-radius: 0 0 10px 0;
        }

        .table tr:hover td {
            background: #f8fafc;
        }

        .actions-cell {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 2rem;
            border-radius: 20px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
            animation: modalSlideIn 0.3s ease-out;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .close {
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            color: #999;
        }

        .close:hover {
            color: #333;
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

            .form-grid {
                grid-template-columns: 1fr;
            }

            .actions-cell {
                flex-direction: column;
            }

            .page-title {
                font-size: 1.5rem;
            }

            .table-container {
                padding: 1rem;
            }

            .admin-role-bar, .employee-role-bar, .hr-role-bar {
                padding: 0.5rem 1rem;
                flex-direction: column;
                align-items: flex-start;
            }

            .admin-role-controls {
                width: 100%;
                justify-content: flex-start;
            }
        }
    </style>
</head>

<body>
    <!-- Animated background particles -->
    <div class="bg-particles" id="particles"></div>

    <!-- Admin role switching bar -->
    <?php if ($original_role === 'Admin') { ?>
        <?php
        $class = '';
        if ($current_role === 'Admin') {
            $class = 'admin-role-bar';
        } elseif ($current_role === 'Employee') {
            $class = 'employee-role-bar';
        } elseif ($current_role === 'HR') {
            $class = 'hr-role-bar';
        }
        ?>
    <?php } ?>

    <!-- Navigation -->
    <nav class="navbar">
        <a class="navbar-brand" href="dashboard">
            <i class="fas fa-calendar-alt"></i>
            Vacation Leave Management System
        </a>
        <div class="navbar-nav">
            <a href="../admin/index" class="nav-link">
                <i class="fas fa-home"></i> Dashboard
            </a>
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
        <!-- Page header -->
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-users-cog"></i>
                User Management
            </h1>
            <p class="page-subtitle">Manage system users, roles, and permissions</p>
        </div>

        <!-- Messages -->
        <?php if (!empty($message)) { ?>
            <div class="message <?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <?php echo $message; ?>
            </div>
        <?php } ?>

        <!-- Add User Form -->
        <div class="form-container">
            <h2 class="form-title">
                <i class="fas fa-user-plus"></i>
                Add New User
            </h2>
            <form method="post">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">First Name</label>
                        <input type="text" name="first_name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Last Name</label>
                        <input type="text" name="last_name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Department</label>
                        <select name="department_id" class="form-input" required>
                            <option value="">Select Department</option>
                            <?php 
                            $departments->data_seek(0);
                            while ($dept = $departments->fetch_assoc()) { ?>
                                <option value="<?php echo $dept['id']; ?>"><?php echo $dept['name']; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Job Role</label>
                        <select name="job_role_id" class="form-input" required>
                            <option value="">Select Job Role</option>
                            <?php 
                            $job_roles->data_seek(0);
                            while ($job = $job_roles->fetch_assoc()) { ?>
                                <option value="<?php echo $job['id']; ?>"><?php echo $job['title']; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">System Role</label>
                        <select name="role_id" class="form-input" required>
                            <option value="">Select Role</option>
                            <?php 
                            $roles->data_seek(0);
                            while ($r = $roles->fetch_assoc()) { ?>
                                <option value="<?php echo $r['id']; ?>"><?php echo $r['name']; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>
                <button type="submit" name="add_user" class="btn btn-success">
                    <i class="fas fa-plus"></i>
                    Add User
                </button>
            </form>
        </div>

        <!-- Users Table -->
        <div class="table-container">
            <h2 class="form-title">
                <i class="fas fa-list"></i>
                All Users
            </h2>
            <div style="overflow-x: auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Department</th>
                            <th>Job Role</th>
                            <th>System Role</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($u = $users_result->fetch_assoc()) { ?>
                            <tr>
                                <td><?php echo $u['id']; ?></td>
                                <td><?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($u['email']); ?></td>
                                <td><?php echo htmlspecialchars($u['department_name']); ?></td>
                                <td><?php echo htmlspecialchars($u['job_role_title']); ?></td>
                                <td>
                                    <span class="role-badge role-<?php echo strtolower($u['role_name']); ?>">
                                        <?php echo $u['role_name']; ?>
                                    </span>
                                </td>
                                <td class="actions-cell">
                                    <button onclick="editUser(<?php echo htmlspecialchars(json_encode($u)); ?>)" 
                                            class="btn btn-primary" style="font-size: 0.8rem; padding: 0.4rem 0.8rem;">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php if ($u['id'] != $user_id) { ?>
                                        <form method="post" style="display: inline;" 
                                              onsubmit="return confirm('Are you sure you want to delete this user?');">
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <button type="submit" name="delete_user" 
                                                    class="btn btn-danger" style="font-size: 0.8rem; padding: 0.4rem 0.8rem;">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                        <?php } ?>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2 class="form-title">
                <i class="fas fa-user-edit"></i>
                Edit User
            </h2>
            <form method="post" id="editForm">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">First Name</label>
                        <input type="text" name="first_name" id="edit_first_name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Last Name</label>
                        <input type="text" name="last_name" id="edit_last_name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="edit_email" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Department</label>
                        <select name="department_id" id="edit_department_id" class="form-input" required>
                            <option value="">Select Department</option>
                            <?php 
                            $departments->data_seek(0);
                            while ($dept = $departments->fetch_assoc()) { ?>
                                <option value="<?php echo $dept['id']; ?>"><?php echo $dept['name']; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Job Role</label>
                        <select name="job_role_id" id="edit_job_role_id" class="form-input" required>
                            <option value="">Select Job Role</option>
                            <?php 
                            $job_roles->data_seek(0);
                            while ($job = $job_roles->fetch_assoc()) { ?>
                                <option value="<?php echo $job['id']; ?>"><?php echo $job['title']; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">System Role</label>
                        <select name="role_id" id="edit_role_id" class="form-input" required>
                            <option value="">Select Role</option>
                            <?php 
                            $roles->data_seek(0);
                            while ($r = $roles->fetch_assoc()) { ?>
                                <option value="<?php echo $r['id']; ?>"><?php echo $r['name']; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>
                <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem;">
                    <button type="button" onclick="closeModal()" class="btn" style="background: #6b7280; color: white;">
                        Cancel
                    </button>
                    <button type="submit" name="update_user" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        Update User
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Background particles animation
        function createParticles() {
            const particlesContainer = document.getElementById('particles');
            const particleCount = 50;

            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                particle.className = 'particle';
                particle.style.left = Math.random() * 100 + '%';
                particle.style.animationDelay = Math.random() * 20 + 's';
                particle.style.animationDuration = (Math.random() * 10 + 10) + 's';
                particlesContainer.appendChild(particle);
            }
        }

        // Modal functionality
        const modal = document.getElementById('editModal');
        const span = document.getElementsByClassName('close')[0];

        function editUser(user) {
            document.getElementById('edit_user_id').value = user.id;
            document.getElementById('edit_first_name').value = user.first_name;
            document.getElementById('edit_last_name').value = user.last_name;
            document.getElementById('edit_email').value = user.email;
            document.getElementById('edit_department_id').value = user.department_id;
            document.getElementById('edit_job_role_id').value = user.job_role_id;
            document.getElementById('edit_role_id').value = user.role_id;
            modal.style.display = 'block';
        }

        function closeModal() {
            modal.style.display = 'none';
        }

        span.onclick = function() {
            closeModal();
        }

        window.onclick = function(event) {
            if (event.target == modal) {
                closeModal();
            }
        }

        // Initialize particles on page load
        document.addEventListener('DOMContentLoaded', function() {
            createParticles();
        });

        // Auto-hide messages after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const messages = document.querySelectorAll('.message');
            messages.forEach(function(message) {
                setTimeout(function() {
                    message.style.opacity = '0';
                    message.style.transform = 'translateY(-20px)';
                    setTimeout(function() {
                        message.style.display = 'none';
                    }, 300);
                }, 5000);
            });
        });
        
    </script>

    <style>
        .role-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .role-admin {
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            color: white;
        }

        .role-hr {
            background: linear-gradient(135deg, #f093fb, #f5576c);
            color: white;
        }

        .role-employee {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
    </style>
</body>
</html>