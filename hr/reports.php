<?php
// hr/reports.php
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
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'overview';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-01-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-12-31');
$department_filter = isset($_GET['department']) ? $_GET['department'] : 'all';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';

// Build WHERE clause for filtering
$where_conditions = [];
$params = [];
$types = '';

// Always include date filter
$where_conditions[] = "lr.applied_date BETWEEN ? AND ?";
$params[] = $date_from;
$params[] = $date_to . ' 23:59:59';
$types .= 'ss';

if ($department_filter != 'all') {
    $where_conditions[] = "u.department_id = ?";
    $params[] = $department_filter;
    $types .= 'i';
}

if ($status_filter != 'all') {
    $where_conditions[] = "lr.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// Overview Statistics
$overview_stats_query = "SELECT 
                            COUNT(DISTINCT u.id) as total_employees,
                            COUNT(lr.id) as total_requests,
                            SUM(CASE WHEN lr.status = 'pending' THEN 1 ELSE 0 END) as pending_requests,
                            SUM(CASE WHEN lr.status = 'approved' THEN 1 ELSE 0 END) as approved_requests,
                            SUM(CASE WHEN lr.status = 'rejected' THEN 1 ELSE 0 END) as rejected_requests,
                            SUM(CASE WHEN lr.status = 'approved' THEN lr.total_days ELSE 0 END) as total_leave_days,
                            AVG(CASE WHEN lr.status = 'approved' THEN lr.total_days ELSE NULL END) as avg_leave_duration
                        FROM users u
                        LEFT JOIN leave_requests lr ON u.id = lr.user_id
                        LEFT JOIN departments d ON u.department_id = d.id
                        $where_clause";

$stmt = $conn->prepare($overview_stats_query);
if ($stmt === false) {
    die('Prepare failed: ' . htmlspecialchars($conn->error));
}

if (!empty($params)) {
    bind_params($stmt, $types, $params);
}

$stmt->execute();
$overview_stats = $stmt->get_result()->fetch_assoc();

// Leave Type Statistics
$leave_types_query = "SELECT 
                        lt.name as leave_type,
                        lt.color,
                        COUNT(lr.id) as request_count,
                        SUM(CASE WHEN lr.status = 'approved' THEN lr.total_days ELSE 0 END) as total_days,
                        AVG(CASE WHEN lr.status = 'approved' THEN lr.total_days ELSE NULL END) as avg_days
                    FROM leave_types lt
                    LEFT JOIN leave_requests lr ON lt.id = lr.leave_type_id
                    LEFT JOIN users u ON lr.user_id = u.id
                    LEFT JOIN departments d ON u.department_id = d.id
                    $where_clause
                    GROUP BY lt.id, lt.name, lt.color
                    ORDER BY request_count DESC";

$stmt = $conn->prepare($leave_types_query);
if ($stmt === false) {
    die('Prepare failed: ' . htmlspecialchars($conn->error));
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$leave_types_stats = $stmt->get_result();

// Department Statistics
$department_stats_query = "SELECT 
                            d.name as department_name,
                            COUNT(DISTINCT u.id) as employee_count,
                            COUNT(lr.id) as request_count,
                            SUM(CASE WHEN lr.status = 'approved' THEN lr.total_days ELSE 0 END) as total_leave_days,
                            AVG(CASE WHEN lr.status = 'approved' THEN lr.total_days ELSE NULL END) as avg_leave_days
                        FROM departments d
                        LEFT JOIN users u ON d.id = u.department_id
                        LEFT JOIN leave_requests lr ON u.id = lr.user_id
                        WHERE d.status = 'active' AND lr.applied_date BETWEEN ? AND ?
                        GROUP BY d.id, d.name
                        ORDER BY request_count DESC";

$dept_stmt = $conn->prepare($department_stats_query);
bind_params($dept_stmt, "ss", [$date_from, $date_to . ' 23:59:59']);
$dept_stmt->execute();
$department_stats = $dept_stmt->get_result();

// Monthly Leave Trends
$monthly_trends_query = "SELECT 
                            DATE_FORMAT(lr.applied_date, '%Y-%m') as month,
                            COUNT(lr.id) as request_count,
                            SUM(CASE WHEN lr.status = 'approved' THEN lr.total_days ELSE 0 END) as approved_days,
                            SUM(CASE WHEN lr.status = 'approved' THEN 1 ELSE 0 END) as approved_count
                        FROM leave_requests lr
                        LEFT JOIN users u ON lr.user_id = u.id
                        LEFT JOIN departments d ON u.department_id = d.id
                        $where_clause
                        GROUP BY DATE_FORMAT(lr.applied_date, '%Y-%m')
                        ORDER BY month DESC
                        LIMIT 12";

$stmt = $conn->prepare($monthly_trends_query);
if ($stmt === false) {
    die('Prepare failed: ' . htmlspecialchars($conn->error));
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$monthly_trends = $stmt->get_result();

// Top Leave Requesters
$top_requesters_query = "SELECT 
                            u.employee_id,
                            CONCAT(u.first_name, ' ', u.last_name) as employee_name,
                            d.name as department_name,
                            COUNT(lr.id) as request_count,
                            SUM(CASE WHEN lr.status = 'approved' THEN lr.total_days ELSE 0 END) as total_leave_days,
                            u.profile_image
                        FROM users u
                        LEFT JOIN leave_requests lr ON u.id = lr.user_id
                        LEFT JOIN departments d ON u.department_id = d.id
                        $where_clause
                        GROUP BY u.id, u.employee_id, u.first_name, u.last_name, d.name, u.profile_image
                        HAVING request_count > 0
                        ORDER BY request_count DESC, total_leave_days DESC
                        LIMIT 10";

$stmt = $conn->prepare($top_requesters_query);
if ($stmt === false) {
    die('Prepare failed: ' . htmlspecialchars($conn->error));
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$top_requesters = $stmt->get_result();

// Recent Leave Requests for detailed view
$recent_requests_query = "SELECT 
                            lr.id,
                            lr.start_date,
                            lr.end_date,
                            lr.total_days,
                            lr.status,
                            lr.applied_date,
                            CONCAT(u.first_name, ' ', u.last_name) as employee_name,
                            u.employee_id,
                            lt.name as leave_type,
                            lt.color as leave_color,
                            d.name as department_name,
                            CONCAT(a.first_name, ' ', a.last_name) as approved_by_name
                        FROM leave_requests lr
                        LEFT JOIN users u ON lr.user_id = u.id
                        LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id
                        LEFT JOIN departments d ON u.department_id = d.id
                        LEFT JOIN users a ON lr.approved_by = a.id
                        $where_clause
                        ORDER BY lr.applied_date DESC
                        LIMIT 50";

$stmt = $conn->prepare($recent_requests_query);
if ($stmt === false) {
    die('Prepare failed: ' . htmlspecialchars($conn->error));
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$recent_requests = $stmt->get_result();

// Fetch departments for filter dropdown
$departments_query = "SELECT id, name FROM departments WHERE status = 'active' ORDER BY name";
$departments_result = $conn->query($departments_query);

function bind_params($stmt, $types, $params) {
    $refs = [];
    foreach ($params as $key => $value) {
        $refs[$key] = &$params[$key];
    }
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>VLMS - Reports & Analytics</title>
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/chart.js/3.9.1/chart.min.js"></script>
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
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
        .stat-icon.teal { background: linear-gradient(135deg, #00d2ff, #3a7bd5); }

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

        /* Report Sections */
        .reports-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        .report-section {
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

        .chart-container {
            position: relative;
            height: 300px;
            margin: 20px 0;
        }

        /* Tables */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .data-table th {
            background: #f8fafc;
            color: #333;
            font-weight: 600;
            padding: 15px 10px;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
            font-size: 14px;
        }

        .data-table td {
            padding: 15px 10px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
            font-size: 14px;
        }

        .data-table tbody tr:hover {
            background-color: #f8fafc;
        }

        /* Status badges */
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            text-transform: capitalize;
            display: inline-block;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-approved {
            background: #d1fae5;
            color: #065f46;
        }

        .status-rejected {
            background: #fee2e2;
            color: #dc2626;
        }

        .leave-type-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            color: white;
            display: inline-block;
        }

        .employee-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .employee-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 12px;
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
            font-size: 13px;
        }

        .employee-id {
            font-size: 11px;
            color: #666;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .no-data i {
            font-size: 48px;
            color: #ddd;
            margin-bottom: 15px;
        }

        /* Single column layout for smaller sections */
        .single-column {
            grid-column: 1 / -1;
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

            .reports-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .report-section {
                padding: 20px;
            }

            .data-table {
                font-size: 12px;
            }

            .data-table th,
            .data-table td {
                padding: 10px 8px;
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
                <a href="../hr/employee_management">
                    <i class="fas fa-users"></i>
                    Employees
                </a>
                <a href="../hr/all_requests">
                    <i class="fas fa-clipboard-list"></i>
                    Leave Requests
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
                    <i class="fas fa-chart-bar"></i>
                </div>
                <div>
                    <h1>Reports & Analytics</h1>
                    <p style="color: #666; margin-top: 5px;">Comprehensive leave management insights and statistics</p>
                </div>
            </div>
            <a href="#" onclick="window.print()" class="btn-primary">
                <i class="fas fa-print"></i>
                Export Report
            </a>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon info">
                    <i class="fas fa-clipboard-list"></i>
                </div>
                <div class="stat-value"><?php echo $overview_stats['total_requests'] ?: 0; ?></div>
                <div class="stat-label">Total Leave Requests</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon warning">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value"><?php echo $overview_stats['pending_requests'] ?: 0; ?></div>
                <div class="stat-label">Pending Requests</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value"><?php echo $overview_stats['approved_requests'] ?: 0; ?></div>
                <div class="stat-label">Approved Requests</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon danger">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="stat-value"><?php echo $overview_stats['rejected_requests'] ?: 0; ?></div>
                <div class="stat-label">Rejected Requests</div>
            </div>
            <!--<div class="stat-card">
                <div class="stat-icon purple">
                    <i class="fas fa-calendar-days"></i>
                </div>
                <div class="stat-value"><?php echo $overview_stats['total_leave_days'] ?: 0; ?></div>
                <div class="stat-label">Total Leave Days</div>
            </div>-->
            <div class="stat-card">
                <div class="stat-icon teal">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-value"><?php echo round($overview_stats['avg_leave_duration'] ?: 0, 1); ?></div>
                <div class="stat-label">Avg Leave Duration</div>
            </div>
        </div>

        <!-- Filters Section -->
        <div class="filters-section">
            <form class="filters-form" method="GET">
                <div class="filter-group">
                    <label for="date_from">From Date</label>
                    <input type="date" name="date_from" id="date_from" value="<?php echo $date_from; ?>">
                </div>
                <div class="filter-group">
                    <label for="date_to">To Date</label>
                    <input type="date" name="date_to" id="date_to" value="<?php echo $date_to; ?>">
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
                    <label for="status">Status</label>
                    <select name="status" id="status">
                        <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $status_filter == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>
                <button type="submit" class="btn-secondary">
                    <i class="fas fa-filter"></i>
                    Apply Filters
                </button>
                <a href="reports" class="btn-secondary">
                    <i class="fas fa-times"></i>
                    Clear
                </a>
            </form>
        </div>

        <!-- Reports Grid -->
        <div class="reports-grid">
            <!-- Leave Types Chart -->
            <div class="report-section">
                <h3 class="section-title">
                    <i class="fas fa-chart-pie"></i>
                    Leave Types Distribution
                </h3>
                <div class="chart-container">
                    <canvas id="leaveTypesChart"></canvas>
                </div>
            </div>

            <!-- Top Requesters -->
            <div class="report-section">
                <h3 class="section-title">
                    <i class="fas fa-trophy"></i>
                    Top Leave Requesters
                </h3>
                <?php if ($top_requesters->num_rows > 0): ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Requests</th>
                                <th>Days</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($requester = $top_requesters->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <div class="employee-info">
                                            <div class="employee-avatar">
                                                <?php if ($requester['profile_image']): ?>
                                                    <img src="../<?php echo htmlspecialchars($requester['profile_image']); ?>" alt="Profile">
                                                <?php else: ?>
                                                    <?php echo strtoupper(substr($requester['employee_name'], 0, 2)); ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="employee-details">
                                                <div class="employee-name"><?php echo htmlspecialchars($requester['employee_name']); ?></div>
                                                <div class="employee-id"><?php echo htmlspecialchars($requester['employee_id']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo $requester['request_count']; ?></td>
                                    <td><?php echo $requester['total_leave_days']; ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-chart-bar"></i>
                        <p>No data available for the selected period</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Department Statistics -->
        <div class="report-section single-column">
            <h3 class="section-title">
                <i class="fas fa-building"></i>
                Department-wise Leave Statistics
            </h3>
            <?php if ($department_stats->num_rows > 0): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Department</th>
                            <th>Employees</th>
                            <th>Total Requests</th>
                            <th>Leave Days Used</th>
                            <th>Avg Days per Request</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($dept_stat = $department_stats->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($dept_stat['department_name']); ?></strong>
                                </td>
                                <td><?php echo $dept_stat['employee_count']; ?></td>
                                <td><?php echo $dept_stat['request_count']; ?></td>
                                <td><?php echo $dept_stat['total_leave_days']; ?></td>
                                <td><?php echo round($dept_stat['avg_leave_days'] ?: 0, 1); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-building"></i>
                    <p>No department data available</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Monthly Trends -->
        <div class="reports-grid">
            <div class="report-section">
                <h3 class="section-title">
                    <i class="fas fa-chart-line"></i>
                    Monthly Leave Trends
                </h3>
                <div class="chart-container">
                    <canvas id="monthlyTrendsChart"></canvas>
                </div>
            </div>

            <!-- Leave Types Statistics Table -->
            <div class="report-section">
                <h3 class="section-title">
                    <i class="fas fa-list"></i>
                    Leave Types Summary
                </h3>
                <?php if ($leave_types_stats->num_rows > 0): ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Leave Type</th>
                                <th>Requests</th>
                                <th>Days</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $leave_types_stats->data_seek(0);
                            while ($leave_type = $leave_types_stats->fetch_assoc()): 
                            ?>
                                <tr>
                                    <td>
                                        <span class="leave-type-badge" style="background-color: <?php echo $leave_type['color']; ?>;">
                                            <?php echo htmlspecialchars($leave_type['leave_type']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $leave_type['request_count']; ?></td>
                                    <td><?php echo $leave_type['total_days']; ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-list"></i>
                        <p>No leave type data available</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Leave Requests -->
        <div class="report-section single-column">
            <h3 class="section-title">
                <i class="fas fa-clock"></i>
                Recent Leave Requests
            </h3>
            <?php if ($recent_requests->num_rows > 0): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Leave Type</th>
                            <th>Period</th>
                            <th>Days</th>
                            <th>Status</th>
                            <th>Applied Date</th>
                            <th>Approved By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($request = $recent_requests->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div class="employee-details">
                                        <div class="employee-name"><?php echo htmlspecialchars($request['employee_name']); ?></div>
                                        <div class="employee-id"><?php echo htmlspecialchars($request['employee_id']); ?></div>
                                    </div>
                                </td>
                                <td>
                                    <span class="leave-type-badge" style="background-color: <?php echo $request['leave_color']; ?>;">
                                        <?php echo htmlspecialchars($request['leave_type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo date('M d', strtotime($request['start_date'])); ?> - 
                                    <?php echo date('M d, Y', strtotime($request['end_date'])); ?>
                                </td>
                                <td><?php echo $request['total_days']; ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $request['status']; ?>">
                                        <?php echo ucfirst($request['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($request['applied_date'])); ?></td>
                                <td><?php echo $request['approved_by_name'] ?: '-'; ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-clipboard-list"></i>
                    <p>No recent leave requests found</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Prepare data for charts
        const leaveTypesData = [
            <?php 
            $leave_types_stats->data_seek(0);
            $chart_data = [];
            $chart_labels = [];
            $chart_colors = [];
            while ($leave_type = $leave_types_stats->fetch_assoc()): 
                $chart_data[] = $leave_type['request_count'];
                $chart_labels[] = "'" . addslashes($leave_type['leave_type']) . "'";
                $chart_colors[] = "'" . $leave_type['color'] . "'";
            endwhile;
            ?>
        ];

        const monthlyTrendsData = [
            <?php 
            $monthly_trends->data_seek(0);
            $trend_labels = [];
            $trend_requests = [];
            $trend_approved = [];
            while ($trend = $monthly_trends->fetch_assoc()): 
                $trend_labels[] = "'" . date('M Y', strtotime($trend['month'] . '-01')) . "'";
                $trend_requests[] = $trend['request_count'];
                $trend_approved[] = $trend['approved_count'];
            endwhile;
            // Reverse arrays to show chronological order
            $trend_labels = array_reverse($trend_labels);
            $trend_requests = array_reverse($trend_requests);
            $trend_approved = array_reverse($trend_approved);
            ?>
        ];

        // Leave Types Pie Chart
        const ctx1 = document.getElementById('leaveTypesChart').getContext('2d');
        new Chart(ctx1, {
            type: 'doughnut',
            data: {
                labels: [<?php echo implode(',', $chart_labels); ?>],
                datasets: [{
                    data: [<?php echo implode(',', $chart_data); ?>],
                    backgroundColor: [<?php echo implode(',', $chart_colors); ?>],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true
                        }
                    }
                }
            }
        });

        // Monthly Trends Line Chart
        const ctx2 = document.getElementById('monthlyTrendsChart').getContext('2d');
        new Chart(ctx2, {
            type: 'line',
            data: {
                labels: [<?php echo implode(',', $trend_labels); ?>],
                datasets: [{
                    label: 'Total Requests',
                    data: [<?php echo implode(',', $trend_requests); ?>],
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    tension: 0.4,
                    fill: true
                }, {
                    label: 'Approved',
                    data: [<?php echo implode(',', $trend_approved); ?>],
                    borderColor: '#56ab2f',
                    backgroundColor: 'rgba(86, 171, 47, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });

        // Print functionality
        window.addEventListener('beforeprint', function() {
            // Resize charts for printing
            Chart.helpers.each(Chart.instances, function(instance) {
                instance.resize();
            });
        });
    </script>
</body>

</html>