<?php
// hr/employee_management.php
session_start();
include '../config/db.php';

// Check if user is logged in and has HR or Admin role
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login');
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch current user data and role
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

$role_stmt = $conn->prepare("SELECT r.name FROM roles r JOIN users u ON u.role_id = r.id WHERE u.id = ?");
$role_stmt->bind_param("i", $user_id);
$role_stmt->execute();
$role_result = $role_stmt->get_result();
$role = $role_result->fetch_assoc();

// Check if user has HR or Admin role
if ($role['name'] != 'HR' && $role['name'] != 'Admin') {
    header('Location: ../employee/index');
    exit();
}

// Handle filters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$department_filter = isset($_GET['department']) ? $_GET['department'] : 'all';
$role_filter = isset($_GET['role']) ? $_GET['role'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build WHERE clause for filtering
$where_conditions = [];
$params = [];
$types = '';

if ($status_filter != 'all') {
    $where_conditions[] = "u.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if ($department_filter != 'all') {
    $where_conditions[] = "u.department_id = ?";
    $params[] = $department_filter;
    $types .= 'i';
}

if ($role_filter != 'all') {
    $where_conditions[] = "u.role_id = ?";
    $params[] = $role_filter;
    $types .= 'i';
}

if (!empty($search)) {
    $where_conditions[] = "(CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR u.employee_id LIKE ? OR u.email LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'sss';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Fetch all employees with department, role, and manager details
$employees_query = "SELECT 
                        u.id,
                        u.employee_id,
                        u.first_name,
                        u.last_name,
                        u.email,
                        u.phone,
                        u.hire_date,
                        u.status,
                        u.last_login,
                        u.created_at,
                        d.name as department_name,
                        jr.title as job_title,
                        r.name as role_name,
                        CONCAT(m.first_name, ' ', m.last_name) as manager_name,
                        u.profile_image
                    FROM users u
                    LEFT JOIN departments d ON u.department_id = d.id
                    LEFT JOIN job_roles jr ON u.job_role_id = jr.id
                    LEFT JOIN roles r ON u.role_id = r.id
                    LEFT JOIN users m ON u.manager_id = m.id
                    $where_clause
                    ORDER BY u.created_at DESC";

$stmt = $conn->prepare($employees_query);
if ($stmt === false) {
    die('Prepare failed: ' . htmlspecialchars($conn->error));
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$employees_result = $stmt->get_result();

// Calculate statistics
$stats_query = "SELECT 
                    COUNT(*) as total_employees,
                    SUM(CASE WHEN u.status = 'active' THEN 1 ELSE 0 END) as active_count,
                    SUM(CASE WHEN u.status = 'inactive' THEN 1 ELSE 0 END) as inactive_count,
                    SUM(CASE WHEN u.status = 'suspended' THEN 1 ELSE 0 END) as suspended_count,
                    COUNT(DISTINCT u.department_id) as departments_count,
                    SUM(CASE WHEN u.last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as recent_logins
                FROM users u";

$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

// Fetch departments for filter dropdown
$departments_query = "SELECT id, name FROM departments WHERE status = 'active' ORDER BY name";
$departments_result = $conn->query($departments_query);

// Fetch roles for filter dropdown
$roles_query = "SELECT id, name FROM roles ORDER BY name";
$roles_result = $conn->query($roles_query);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>VLMS - Employee Management</title>
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
            max-width: 1400px;
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
        }

        /* Page Title Section */
        .page-header {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-title {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .page-title h1 {
            font-size: 28px;
            font-weight: 600;
            color: #333;
        }

        .page-title .title-icon {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
            color: white;
            text-decoration: none;
        }

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 16px;
            padding: 25px;
            backdrop-filter: blur(10px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            text-align: center;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            margin: 0 auto 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
        }

        .stat-icon.info { background: linear-gradient(135deg, #4facfe, #00f2fe); }
        .stat-icon.success { background: linear-gradient(135deg, #56ab2f, #a8e6cf); }
        .stat-icon.warning { background: linear-gradient(135deg, #f093fb, #f5576c); }
        .stat-icon.danger { background: linear-gradient(135deg, #ff6b6b, #ee5a5a); }
        .stat-icon.purple { background: linear-gradient(135deg, #667eea, #764ba2); }
        .stat-icon.orange { background: linear-gradient(135deg, #ff9a56, #ff6b95); }

        .stat-value {
            font-size: 24px;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #666;
            font-size: 14px;
        }

        /* Filters Section */
        .filters-section {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 30px;
            backdrop-filter: blur(10px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }

        .filters-form {
            display: flex;
            gap: 20px;
            align-items: end;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .filter-group label {
            font-weight: 500;
            color: #333;
            font-size: 14px;
        }

        .filter-group select,
        .filter-group input {
            padding: 10px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        .filter-group select:focus,
        .filter-group input:focus {
            outline: none;
            border-color: #667eea;
        }

        .btn-secondary {
            background: #f8fafc;
            color: #333;
            border: 2px solid #e2e8f0;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .btn-secondary:hover {
            background: #e2e8f0;
            text-decoration: none;
            color: #333;
        }

        /* Employees Table */
        .employees-section {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 30px;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .section-title {
            font-size: 20px;
            font-weight: 600;
            color: #333;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .employees-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .employees-table th {
            background: #f8fafc;
            color: #333;
            font-weight: 600;
            padding: 15px 10px;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
            font-size: 14px;
        }

        .employees-table td {
            padding: 15px 10px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
            font-size: 14px;
        }

        .employees-table tbody tr:hover {
            background-color: #f8fafc;
        }

        .employee-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .employee-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 14px;
            flex-shrink: 0;
        }

        .employee-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }

        .employee-details {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .employee-name {
            font-weight: 500;
            color: #333;
        }

        .employee-id {
            font-size: 12px;
            color: #666;
        }

        .employee-email {
            font-size: 12px;
            color: #888;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            text-transform: capitalize;
            display: inline-block;
        }

        .status-active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-inactive {
            background: #f3f4f6;
            color: #374151;
        }

        .status-suspended {
            background: #fee2e2;
            color: #dc2626;
        }

        .role-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            color: white;
            display: inline-block;
        }

        .role-admin { background: linear-gradient(135deg, #667eea, #764ba2); }
        .role-hr { background: linear-gradient(135deg, #56ab2f, #a8e6cf); }
        .role-manager { background: linear-gradient(135deg, #ff9a56, #ff6b95); }
        .role-employee { background: linear-gradient(135deg, #4facfe, #00f2fe); }

        .department-info {
            font-size: 13px;
            color: #555;
            line-height: 1.4;
        }

        .job-title {
            font-weight: 500;
            color: #333;
        }

        .department-name {
            color: #666;
            font-size: 12px;
        }

        .contact-info {
            font-size: 13px;
            color: #555;
            line-height: 1.4;
        }

        .hire-date {
            font-size: 13px;
            color: #666;
        }

        .last-login {
            font-size: 12px;
            color: #888;
            margin-top: 4px;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn-sm {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 4px;
            transition: all 0.3s ease;
        }

        .btn-info {
            background: #3b82f6;
            color: white;
        }

        .btn-info:hover {
            background: #2563eb;
            color: white;
            text-decoration: none;
        }

        .btn-warning {
            background: #f59e0b;
            color: white;
        }

        .btn-warning:hover {
            background: #d97706;
            color: white;
            text-decoration: none;
        }

        .btn-success {
            background: #10b981;
            color: white;
        }

        .btn-success:hover {
            background: #059669;
            color: white;
            text-decoration: none;
        }

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
            color: white;
            text-decoration: none;
        }

        .no-employees {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .no-employees i {
            font-size: 48px;
            color: #ddd;
            margin-bottom: 15px;
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

            .page-header {
                flex-direction: column;
                gap: 20px;
                text-align: center;
                padding: 20px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }

            .filters-form {
                flex-direction: column;
                align-items: stretch;
            }

            .employees-section {
                padding: 20px;
                overflow-x: auto;
            }

            .employees-table {
                min-width: 900px;
            }

            .employees-table th,
            .employees-table td {
                padding: 10px 8px;
                font-size: 12px;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Header -->
        <header class="header">
            <div class="logo">
                <i class="fas fa-calendar-check"></i>
                Vacation Leave Management System
            </div>
            <nav class="user-nav">
                <a href="../admin/index">
                    <i class="fas fa-dashboard"></i>
                    Dashboard
                </a>
                <a href="../hr/all_requests">
                    <i class="fas fa-clipboard-list"></i>
                    Leave Requests
                </a>
                <a href="../hr/reports">
                    <i class="fas fa-chart-bar"></i>
                    Report
                </a>
                <a href="../auth/logout">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </a>
            </nav>
        </header>

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-title">
                <div class="title-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div>
                    <h1>Employee Management</h1>
                    <p style="color: #666; margin-top: 5px;">Manage and monitor all employees in the system</p>
                </div>
            </div>
            <a href="add_employee" class="btn-primary">
                <i class="fas fa-plus"></i>
                Add New Employee
            </a>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon info">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-value"><?php echo $stats['total_employees']; ?></div>
                <div class="stat-label">Total Employees</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon success">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-value"><?php echo $stats['active_count']; ?></div>
                <div class="stat-label">Active Employees</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon warning">
                    <i class="fas fa-user-times"></i>
                </div>
                <div class="stat-value"><?php echo $stats['inactive_count']; ?></div>
                <div class="stat-label">Inactive</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon danger">
                    <i class="fas fa-user-slash"></i>
                </div>
                <div class="stat-value"><?php echo $stats['suspended_count']; ?></div>
                <div class="stat-label">Suspended</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple">
                    <i class="fas fa-building"></i>
                </div>
                <div class="stat-value"><?php echo $stats['departments_count']; ?></div>
                <div class="stat-label">Departments</div>
            </div>
            <!--<div class="stat-card">
                <div class="stat-icon orange">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value"><?php echo $stats['recent_logins']; ?></div>
                <div class="stat-label">Recent Logins (30d)</div>
            </div>-->
        </div>

        <!-- Filters Section -->
        <div class="filters-section">
            <form class="filters-form" method="GET">
                <div class="filter-group">
                    <label for="status">Status</label>
                    <select name="status" id="status">
                        <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="suspended" <?php echo $status_filter == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="department">Department</label>
                    <select name="department" id="department">
                        <option value="all" <?php echo $department_filter == 'all' ? 'selected' : ''; ?>>All Departments</option>
                        <?php 
                        $departments_result->data_seek(0);
                        while ($dept = $departments_result->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $dept['id']; ?>" <?php echo $department_filter == $dept['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="role">Role</label>
                    <select name="role" id="role">
                        <option value="all" <?php echo $role_filter == 'all' ? 'selected' : ''; ?>>All Roles</option>
                        <?php 
                        $roles_result->data_seek(0);
                        while ($role_data = $roles_result->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $role_data['id']; ?>" <?php echo $role_filter == $role_data['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($role_data['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="search">Search</label>
                    <input type="text" name="search" id="search" placeholder="Name, ID, or email..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <button type="submit" class="btn-secondary">
                    <i class="fas fa-filter"></i>
                    Apply Filters
                </button>
                <a href="employee_management" class="btn-secondary">
                    <i class="fas fa-times"></i>
                    Clear
                </a>
            </form>
        </div>

        <!-- Employees Table -->
        <div class="employees-section">
            <div class="section-title">
                <i class="fas fa-table"></i>
                Employees
            </div>

            <?php if ($employees_result->num_rows > 0): ?>
                <div style="overflow-x: auto;">
                    <table class="employees-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Role</th>
                                <th>Department & Position</th>
                                <th>Contact</th>
                                <th>Status</th>
                                <th>Hire Date</th>
                                <th>Manager</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($employee = $employees_result->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <div class="employee-info">
                                            <div class="employee-avatar">
                                                <?php if ($employee['profile_image']): ?>
                                                    <img src="../<?php echo htmlspecialchars($employee['profile_image']); ?>" alt="Profile">
                                                <?php else: ?>
                                                    <?php echo strtoupper(substr($employee['first_name'], 0, 1) . substr($employee['last_name'], 0, 1)); ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="employee-details">
                                                <div class="employee-name"><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></div>
                                                <div class="employee-id">ID: <?php echo htmlspecialchars($employee['employee_id']); ?></div>
                                                <div class="employee-email"><?php echo htmlspecialchars($employee['email']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="role-badge role-<?php echo strtolower($employee['role_name']); ?>">
                                            <?php echo htmlspecialchars($employee['role_name']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="department-info">
                                            <?php if ($employee['job_title']): ?>
                                                <div class="job-title"><?php echo htmlspecialchars($employee['job_title']); ?></div>
                                            <?php endif; ?>
                                            <?php if ($employee['department_name']): ?>
                                                <div class="department-name"><?php echo htmlspecialchars($employee['department_name']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="contact-info">
                                            <?php if ($employee['phone']): ?>
                                                <div><i class="fas fa-phone" style="width: 12px;"></i> <?php echo htmlspecialchars($employee['phone']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $employee['status']; ?>">
                                            <?php echo ucfirst($employee['status']); ?>
                                        </span>
                                        <?php if ($employee['last_login']): ?>
                                            <div class="last-login">Last: <?php echo date('M j, Y', strtotime($employee['last_login'])); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="hire-date"><?php echo date('M j, Y', strtotime($employee['hire_date'])); ?></div>
                                    </td>
                                    <td>
                                        <?php if ($employee['manager_name']): ?>
                                            <div style="font-size: 13px; color: #555;"><?php echo htmlspecialchars($employee['manager_name']); ?></div>
                                        <?php else: ?>
                                            <span style="color: #999; font-size: 12px;">No Manager</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="view_employee?id=<?php echo $employee['id']; ?>" class="btn-sm btn-info" title="View Details">
                                                <i class="fas fa-eye"></i>
                                                View
                                            </a>
                                            <a href="edit_employee?id=<?php echo $employee['id']; ?>" class="btn-sm btn-warning" title="Edit Employee">
                                                <i class="fas fa-edit"></i>
                                                Edit
                                            </a>
                                            <?php if ($employee['status'] == 'active'): ?>
                                                <a href="suspend_employee?id=<?php echo $employee['id']; ?>" class="btn-sm btn-danger" title="Suspend Employee" 
                                                   onclick="return confirm('Are you sure you want to suspend this employee?')">
                                                    <i class="fas fa-user-slash"></i>
                                                    Suspend
                                                </a>
                                            <?php elseif ($employee['status'] == 'suspended'): ?>
                                                <a href="activate_employee?id=<?php echo $employee['id']; ?>" class="btn-sm btn-success" title="Activate Employee"
                                                   onclick="return confirm('Are you sure you want to activate this employee?')">
                                                    <i class="fas fa-user-check"></i>
                                                    Activate
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="no-employees">
                    <i class="fas fa-users"></i>
                    <h3>No employees found</h3>
                    <p>No employees match your current filter criteria.</p>
                    <a href="employee_management" class="btn-primary" style="margin-top: 15px;">
                        <i class="fas fa-refresh"></i>
                        Clear Filters
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Auto-submit form when filters change
        document.querySelectorAll('select[name="status"], select[name="department"], select[name="role"]').forEach(function(select) {
            select.addEventListener('change', function() {
                this.form.submit();
            });
        });

        // Search functionality with debounce
        let searchTimeout;
        document.getElementById('search').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                this.form.submit();
            }, 500);
        });

        // Confirmation dialogs for actions
        document.querySelectorAll('.btn-danger, .btn-success').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                if (!this.onclick) {
                    const action = this.textContent.trim().toLowerCase();
                    if (!confirm(`Are you sure you want to ${action} this employee?`)) {
                        e.preventDefault();
                    }
                }
            });
        });

        // Table responsive enhancements
        window.addEventListener('resize', function() {
            const table = document.querySelector('.employees-table');
            const container = document.querySelector('.employees-section');
            
            if (window.innerWidth <= 768) {
                container.style.overflowX = 'auto';
                table.style.minWidth = '900px';
            } else {
                container.style.overflowX = 'visible';
                table.style.minWidth = 'auto';
            }
        });

        // Initialize responsive table
        window.dispatchEvent(new Event('resize'));
    </script>
</body>
</html>