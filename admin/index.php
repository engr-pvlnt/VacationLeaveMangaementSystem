<?php
// dashboard.php
session_start();
include '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login');
    exit();
}

// Handle role switching for admins
if (isset($_POST['switch_role'])) {
    if ($_SESSION['original_role'] === 'Admin') {
        $_SESSION['current_role'] = $_POST['switch_role'];
        // Redirect to prevent form resubmission
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Handle returning to admin role
if (isset($_POST['return_admin'])) {
    if (isset($_SESSION['original_role']) && $_SESSION['original_role'] === 'Admin') {
        $_SESSION['current_role'] = 'Admin';
        // Redirect to prevent form resubmission
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Fetch user info
$user_id = $_SESSION['user_id'];
$result = $conn->query("SELECT * FROM users WHERE id = $user_id");
$user = $result->fetch_assoc();

// Fetch user's department name
$department_id = $user['department_id'];
$department_result = $conn->query("SELECT name FROM departments WHERE id = $department_id");
$department = $department_result->fetch_assoc()['name'];

// Fetch user's job role title
$job_role_id = $user['job_role_id'];
$job_role_result = $conn->query("SELECT title FROM job_roles WHERE id = $job_role_id");
$job_role = $job_role_result->fetch_assoc()['title'];

// Get original role
$role_id = $user['role_id'];
$result = $conn->query("SELECT name FROM roles WHERE id = $role_id");
$original_role = $result->fetch_assoc()['name'];

// Store original role in session if not set
if (!isset($_SESSION['original_role'])) {
    $_SESSION['original_role'] = $original_role;
}

// Determine current role (for display and functionality)
$current_role = isset($_SESSION['current_role']) ? $_SESSION['current_role'] : $original_role;
$role = $current_role;

// Check if user is browsing as a different role
$is_role_switching = ($original_role === 'Admin' && $current_role !== 'Admin');

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VLMS - Dashboard</title>
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

        /* Admin role switching bar - always visible for admins */
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

        .role-control-btn.return {
            background: rgba(255, 255, 255, 0.9);
            color: #333;
        }

        .role-control-btn.return:hover {
            background: white;
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
            position: relative;
            overflow: hidden;
        }

        .nav-link::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.1);
            transition: left 0.3s ease;
            border-radius: 25px;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px) scale(1.05);
            color: white !important;
        }

        .profile-image-container {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            overflow: hidden;
            border: 2px solid #2a4286ff;
            flex-shrink: 0;
            animation: bounce 5s infinite;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.4);
        }

        @keyframes bounce {

            0%,
            100% {
                transform: translateY(0);
                animation-timing-function: cubic-bezier(0.8, 0, 1, 1);
            }

            50% {
                transform: translateY(-5px);
                animation-timing-function: cubic-bezier(0, 0, 0.2, 1);
            }
        }

        /* Profile Image Styles */
        .profile-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-image-placeholder {
            width: 100%;
            height: 100%;
            background: #1e3a8a;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 40px;
        }

        /* Main container */
        .main-container {
            position: relative;
            z-index: 100;
            padding: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Welcome card */
        .welcome-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            animation: slideUp 0.8s ease-out;
        }

        .welcome-title {
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea, #764ba2);
            background-clip: text;
            color: transparent;
            margin-bottom: 0.5rem;
        }

        .role-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 25px;
            font-size: 0.9rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .role-employee {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .role-hr {
            background: linear-gradient(135deg, #f093fb, #f5576c);
            color: white;
        }

        .role-admin {
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            color: white;
        }

        /* Role switching section - only show when Admin is in normal view */
        .role-switch-section {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            animation: slideUp 0.8s ease-out 0.2s both;
        }

        .role-switch-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: #333;
        }

        .role-switch-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .role-switch-btn {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 25px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.9rem;
            position: relative;
            overflow: hidden;
        }

        .role-switch-btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.3s ease, height 0.3s ease;
        }

        .role-switch-btn:hover::before {
            width: 300px;
            height: 300px;
        }

        .role-switch-btn:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
        }

        .role-switch-btn.hr {
            background: linear-gradient(135deg, #f093fb, #f5576c);
        }

        .role-switch-btn.hr:hover {
            box-shadow: 0 10px 25px rgba(240, 147, 251, 0.4);
        }

        .role-switch-btn.employee {
            background: linear-gradient(135deg, #667eea, #764ba2);
        }

        .role-switch-btn.employee:hover {
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
        }

        /* Menu grid */
        .menu-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }

        .menu-item {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 15px;
            padding: 1.5rem;
            text-decoration: none;
            color: #333;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            border: 1px solid rgba(255, 255, 255, 0.3);
            position: relative;
            overflow: hidden;
            animation: fadeInUp 0.8s ease-out forwards;
            opacity: 0;
            transform: translateY(20px);
        }

        .menu-item:nth-child(1) {
            animation-delay: 0.1s;
        }

        .menu-item:nth-child(2) {
            animation-delay: 0.2s;
        }

        .menu-item:nth-child(3) {
            animation-delay: 0.3s;
        }

        .menu-item:nth-child(4) {
            animation-delay: 0.4s;
        }

        .menu-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.6s;
        }

        .menu-item:hover::before {
            left: 100%;
        }

        .menu-item:hover {
            transform: translateY(-10px) scale(1.03);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            text-decoration: none;
            color: #333;
        }

        .menu-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
            font-size: 1.5rem;
            color: white;
            transition: all 0.4s ease;
            position: relative;
            z-index: 2;
        }

        .menu-item:hover .menu-icon {
            transform: rotate(10deg) scale(1.1);
            animation: pulse 2s infinite;
        }

        @keyframes pulseEmployee {
            0% {
                box-shadow: 0 0 0 0 rgba(102, 126, 234, 0.7);
            }

            50% {
                box-shadow: 0 0 0 15px rgba(102, 126, 234, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(102, 126, 234, 0);
            }
        }

        @keyframes pulseHR {
            0% {
                box-shadow: 0 0 0 0 rgba(240, 147, 251, 0.7);
            }

            50% {
                box-shadow: 0 0 0 15px rgba(240, 147, 251, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(240, 147, 251, 0);
            }
        }

        @keyframes pulseAdmin {
            0% {
                box-shadow: 0 0 0 0 rgba(79, 172, 254, 0.7);
            }

            50% {
                box-shadow: 0 0 0 15px rgba(79, 172, 254, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(79, 172, 254, 0);
            }
        }

        .menu-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            transition: color 0.3s ease;
        }

        .menu-item:hover .menu-title {
            color: #667eea;
        }

        .menu-item.hr:hover .menu-title {
            color: #f093fb;
        }

        .menu-item.admin:hover .menu-title {
            color: #4facfe;
        }

        .menu-description {
            font-size: 0.9rem;
            color: #666;
            line-height: 1.4;
            transition: color 0.3s ease;
        }

        .menu-item:hover .menu-description {
            color: #555;
        }

        /* Role-specific colors */
        .employee .menu-icon {
            background: linear-gradient(135deg, #667eea, #764ba2);
        }

        .hr .menu-icon {
            background: linear-gradient(135deg, #f093fb, #f5576c);
        }

        .admin .menu-icon {
            background: linear-gradient(135deg, #4facfe, #00f2fe);
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

        @keyframes fadeInUp {
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

            .menu-grid {
                grid-template-columns: 1fr;
            }

            .welcome-title {
                font-size: 1.5rem;
            }

            .role-switch-buttons {
                flex-direction: column;
            }

            .admin-role-bar {
                padding: 0.5rem 1rem;
                flex-direction: column;
                align-items: flex-start;
            }

            .admin-role-controls {
                width: 100%;
                justify-content: flex-start;
            }

            .role-control-btn {
                font-size: 0.8rem;
                padding: 0.3rem 0.6rem;
            }
        }

        /* Smooth scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
        }

        ::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.3);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.5);
        }
    </style>
</head>

<body>
    <!-- Animated background particles -->
    <div class="bg-particles" id="particles"></div>

    <!-- Admin role switching bar - always visible for admins -->
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
        <div class="<?php echo $class; ?>">
            <div class="admin-role-info">
                <i class="fas fa-user-shield"></i>
                <span>
                    <?php if ($is_role_switching) { ?>
                        Currently browsing as <strong><?php echo $current_role; ?></strong> (Admin View)
                    <?php } else { ?>
                        Admin Dashboard - Switch to view as other roles
                    <?php } ?>
                </span>
            </div>
            <div class="admin-role-controls">
                <form method="post" style="display: inline;">
                    <button type="submit" name="return_admin"
                        class="role-control-btn <?php echo ($current_role === 'Admin') ? 'active' : ''; ?>">
                        <i class="fas fa-crown"></i> Admin
                    </button>
                </form>
                <form method="post" style="display: inline;">
                    <button type="submit" name="switch_role" value="Employee"
                        class="role-control-btn <?php echo ($current_role === 'Employee') ? 'active' : ''; ?>">
                        <i class="fas fa-user"></i> Employee
                    </button>
                </form>
                <form method="post" style="display: inline;">
                    <button type="submit" name="switch_role" value="HR"
                        class="role-control-btn <?php echo ($current_role === 'HR') ? 'active' : ''; ?>">
                        <i class="fas fa-users"></i> HR
                    </button>
                </form>
            </div>
        </div>
    <?php } ?>

    <!-- Navigation -->
    <nav class="navbar">
        <a class="navbar-brand" href="#">
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
        <!-- Welcome card -->
        <div class="welcome-card">
            <div style="display: flex; align-items: center; gap: 1rem;">
                <div class="profile-image-container">
                    <?php if (!empty($user['profile_image']) && file_exists('../' . $user['profile_image'])): ?>
                        <img src="../<?php echo htmlspecialchars($user['profile_image']); ?>"
                            alt="Profile Picture"
                            class="profile-image">
                    <?php else: ?>
                        <div class="profile-image-placeholder">
                            <i class="fas fa-user"></i>
                        </div>
                    <?php endif; ?>
                </div>
                <div>
                    <h1 class="welcome-title">
                        Welcome back, <?php echo htmlspecialchars($user['first_name']); ?>!
                    </h1>
                    <span class="role-badge role-<?php echo strtolower($role); ?>">
                        <?php echo $role; ?>
                        <?php if ($is_role_switching) { ?>
                            <i class="fas fa-eye" style="margin-left: 0.5rem;" title="Browsing as different role"></i>
                        <?php } ?>
                    </span>
                    <p style="margin-top: 1rem; color: #666;">
                        <?php echo htmlspecialchars($department); ?> / <?php echo htmlspecialchars($job_role); ?>
                    </p>
                    <p style="margin-top: 0.2rem; color: #666;">
                        <i class="fa fa-id-card" aria-hidden="true" style="color: #798879ff;"></i>
                        <small><?php echo htmlspecialchars($user['employee_id']); ?></small>
                    </p>
                </div>
            </div>
        </div>

        <!-- Menu grid -->
        <div class="menu-grid">
            <?php if ($role == 'Employee') { ?>
                <a href="../employee/leave_request" class="menu-item employee">
                    <div class="menu-icon">
                        <i class="fas fa-plus-circle"></i>
                    </div>
                    <div class="menu-title">New Leave Request</div>
                    <div class="menu-description">Submit a new leave request for approval</div>
                </a>

                <a href="../employee/my_requests" class="menu-item employee">
                    <div class="menu-icon">
                        <i class="fas fa-list"></i>
                    </div>
                    <div class="menu-title">My Requests</div>
                    <div class="menu-description">View and manage your leave requests</div>
                </a>

                <a href="../employee/pending_requests" class="menu-item employee">
                    <div class="menu-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="menu-title">Pending Approvals</div>
                    <div class="menu-description">Requests awaiting your approval</div>
                </a>

                <a href="../employee/approval_history" class="menu-item employee">
                    <div class="menu-icon">
                        <i class="fas fa-history"></i>
                    </div>
                    <div class="menu-title">Approval History</div>
                    <div class="menu-description">View your approval history and decisions</div>
                </a>
            <?php } elseif ($role == 'HR') { ?>
                <a href="../hr/all_requests" class="menu-item hr">
                    <div class="menu-icon">
                        <i class="fas fa-inbox"></i>
                    </div>
                    <div class="menu-title">All Requests</div>
                    <div class="menu-description">Overview of all employee leave requests</div>
                </a>

                <a href="../hr/employee_management" class="menu-item hr">
                    <div class="menu-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="menu-title">Employee Management</div>
                    <div class="menu-description">Manage employee profiles and settings</div>
                </a>

                <a href="../hr/reports" class="menu-item hr">
                    <div class="menu-icon">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <div class="menu-title">Reports & Analytics</div>
                    <div class="menu-description">Generate detailed leave reports and insights</div>
                </a>
            <?php } elseif ($role == 'Admin') { ?>
                <a href="../admin/user_management" class="menu-item admin">
                    <div class="menu-icon">
                        <i class="fas fa-users-cog"></i>
                    </div>
                    <div class="menu-title">User Management</div>
                    <div class="menu-description">Manage system users and permissions</div>
                </a>
                <a href="../admin/role_management" class="menu-item admin">
                    <div class="menu-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div class="menu-title">Role Management</div>
                    <div class="menu-description">Configure user roles and job responsibilities</div>
                </a>
                <a href="../admin/departments" class="menu-item admin">
                    <div class="menu-icon">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="menu-title">Departments</div>
                    <div class="menu-description">Manage organizational departments</div>
                </a>

                <a href="../admin/audit_logs" class="menu-item admin">
                    <div class="menu-icon">
                        <i class="fas fa-search"></i>
                    </div>
                    <div class="menu-title">Audit Logs</div>
                    <div class="menu-description">Track system activity and security logs</div>
                </a>

                <a href="../admin/system_settings" class="menu-item admin">
                    <div class="menu-icon">
                        <i class="fas fa-cog"></i>
                    </div>
                    <div class="menu-title">System Settings</div>
                    <div class="menu-description">Configure system-wide settings and preferences</div>
                </a>

                <a href="../admin/backup" class="menu-item admin">
                    <div class="menu-icon">
                        <i class="fas fa-database"></i>
                    </div>
                    <div class="menu-title">Backup & Restore</div>
                    <div class="menu-description">Manage system backups and data recovery</div>
                </a>
            <?php } ?>
        </div>
    </div>

    <script>
        // Create animated background particles
        function createParticles() {
            const particlesContainer = document.getElementById('particles');
            const particleCount = 50;

            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                particle.className = 'particle';
                particle.style.left = Math.random() * 100 + '%';
                particle.style.animationDelay = Math.random() * 20 + 's';
                particle.style.animationDuration = (Math.random() * 10 + 15) + 's';
                particlesContainer.appendChild(particle);
            }
        }

        // Initialize particles when page loads
        document.addEventListener('DOMContentLoaded', createParticles);

        // Add hover effects to menu items
        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('mouseenter', function() {
                const icon = this.querySelector('.menu-icon');
                const role = this.classList.contains('hr') ? 'HR' :
                    this.classList.contains('admin') ? 'Admin' : 'Employee';

                if (role === 'Employee') {
                    icon.style.animation = 'pulseEmployee 2s infinite';
                } else if (role === 'HR') {
                    icon.style.animation = 'pulseHR 2s infinite';
                } else {
                    icon.style.animation = 'pulseAdmin 2s infinite';
                }
            });

            item.addEventListener('mouseleave', function() {
                const icon = this.querySelector('.menu-icon');
                icon.style.animation = '';
            });
        });

        // Add smooth scroll behavior
        document.documentElement.style.scrollBehavior = 'smooth';
    </script>
</body>

</html>