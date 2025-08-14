<?php
// hr/all_requests.php
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

// Handle status filter
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build WHERE clause for filtering
$where_conditions = [];
$params = [];
$types = '';

if ($status_filter != 'all') {
    $where_conditions[] = "lr.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if (!empty($search)) {
    $where_conditions[] = "(CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR u.employee_id LIKE ? OR lr.reason LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'sss';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Fetch all leave requests with employee and leave type details
$all_requests_query = "SELECT 
                          lr.id as request_id,
                          lr.start_date,
                          lr.end_date,
                          lr.reason,
                          lr.status,
                          lr.created_at as request_date,
                          lr.approved_at,
                          lr.rejected_at,
                          lr.rejection_reason,
                          lr.approval_notes,
                          lr.total_days,
                          lr.start_work_date,
                          u.first_name,
                          u.last_name,
                          u.employee_id,
                          u.email,
                          d.name as department_name,
                          jr.title as job_title,
                          lt.name as leave_type_name,
                          lt.color as leave_type_color,
                          approver.first_name as approver_first_name,
                          approver.last_name as approver_last_name,
                          reliever.first_name as reliever_first_name,
                          reliever.last_name as reliever_last_name
                      FROM leave_requests lr 
                      LEFT JOIN users u ON lr.user_id = u.id
                      LEFT JOIN departments d ON u.department_id = d.id
                      LEFT JOIN job_roles jr ON u.job_role_id = jr.id
                      LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id 
                      LEFT JOIN users approver ON lr.approved_by = approver.id
                      LEFT JOIN users reliever ON lr.reliever_id = reliever.id
                      $where_clause
                      ORDER BY lr.created_at DESC";

$stmt = $conn->prepare($all_requests_query);
if ($stmt === false) {
    die('Prepare failed: ' . htmlspecialchars($conn->error));
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$requests_result = $stmt->get_result();

// Calculate statistics for all requests, including average processing time
$stats_query = "SELECT 
                    COUNT(*) as total_requests,
                    SUM(CASE WHEN lr.status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                    SUM(CASE WHEN lr.status = 'approved' THEN 1 ELSE 0 END) as approved_count,
                    SUM(CASE WHEN lr.status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
                    SUM(CASE WHEN lr.status = 'approved' THEN lr.total_days ELSE 0 END) as total_approved_days,
                    ROUND(AVG(CASE WHEN lr.status = 'approved' AND lr.approved_at IS NOT NULL 
                                THEN DATEDIFF(lr.approved_at, lr.created_at) ELSE NULL END), 1) as avg_processing_time
                FROM leave_requests lr
                LEFT JOIN users u ON lr.user_id = u.id;";

$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

// Calculate approval rate
$processed_requests = $stats['approved_count'] + $stats['rejected_count'];
$approval_rate = $processed_requests > 0 ? round(($stats['approved_count'] / $processed_requests) * 100, 1) : 0;

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>VLMS - All Leave Requests</title>
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

        .stat-icon.warning { background: linear-gradient(135deg, #f093fb, #f5576c); }
        .stat-icon.success { background: linear-gradient(135deg, #56ab2f, #a8e6cf); }
        .stat-icon.danger { background: linear-gradient(135deg, #ff6b6b, #ee5a5a); }
        .stat-icon.info { background: linear-gradient(135deg, #4facfe, #00f2fe); }
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

        /* Requests Table */
        .requests-section {
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

        .requests-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .requests-table th {
            background: #f8fafc;
            color: #333;
            font-weight: 600;
            padding: 15px 10px;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
            font-size: 14px;
        }

        .requests-table td {
            padding: 15px 10px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
            font-size: 14px;
        }

        .requests-table tbody tr:hover {
            background-color: #f8fafc;
        }

        .employee-info {
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

        .employee-dept {
            font-size: 12px;
            color: #888;
        }

        .leave-type-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            color: white;
            display: inline-block;
        }

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

        .status-cancelled {
            background: #f3f4f6;
            color: #374151;
        }

        .date-range {
            font-size: 13px;
            color: #555;
            line-height: 1.4;
        }

        .duration {
            font-weight: 500;
            color: #333;
            text-align: center;
        }

        .reason-cell {
            max-width: 200px;
            word-wrap: break-word;
            font-size: 13px;
            line-height: 1.4;
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

        .btn-info {
            background: #3b82f6;
            color: white;
        }

        .btn-info:hover {
            background: #2563eb;
            color: white;
            text-decoration: none;
        }

        .no-requests {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .no-requests i {
            font-size: 48px;
            color: #ddd;
            margin-bottom: 15px;
        }

        .request-date {
            font-size: 13px;
            color: #666;
        }

        .processing-info {
            font-size: 12px;
            color: #888;
            margin-top: 4px;
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

            .requests-section {
                padding: 20px;
                overflow-x: auto;
            }

            .requests-table {
                min-width: 800px;
            }

            .requests-table th,
            .requests-table td {
                padding: 10px 8px;
                font-size: 12px;
            }

            .reason-cell {
                max-width: 150px;
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
                    Employee Management
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
                    <i class="fas fa-clipboard-list"></i>
                </div>
                <div>
                    <h1>All Leave Requests</h1>
                    <p style="color: #666; margin-top: 5px;">Manage and monitor all employee leave requests</p>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon info">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="stat-value"><?php echo $stats['total_requests']; ?></div>
                <div class="stat-label">Total Requests</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon warning">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value"><?php echo $stats['pending_count']; ?></div>
                <div class="stat-label">Pending Requests</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value"><?php echo $stats['approved_count']; ?></div>
                <div class="stat-label">Approved</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon danger">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="stat-value"><?php echo $stats['rejected_count']; ?></div>
                <div class="stat-label">Rejected</div>
            </div>
            <!--<div class="stat-card">
                <div class="stat-icon purple">
                    <i class="fas fa-percentage"></i>
                </div>
                <div class="stat-value"><?php echo $approval_rate; ?>%</div>
                <div class="stat-label">Approval Rate</div>
            </div>-->
            <!--<div class="stat-card">
                <div class="stat-icon orange">
                    <i class="fas fa-calendar-day"></i>
                </div>
                <div class="stat-value"><?php echo $stats['total_approved_days'] ?: 0; ?></div>
                <div class="stat-label">Days Approved</div>
            </div>-->
            <div class="stat-card">
                <div class="stat-icon purple">
                    <i class="fas fa-calendar"></i>
                </div>
                <div class="stat-value"><?php echo $stats['avg_processing_time']; ?> days</div>
                <div class="stat-label">Average Processing Time</div>
            </div>
        </div>

        <!-- Filters Section -->
        <div class="filters-section">
            <form class="filters-form" method="GET">
                <div class="filter-group">
                    <label for="status">Status</label>
                    <select name="status" id="status">
                        <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $status_filter == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="search">Search</label>
                    <input type="text" name="search" id="search" placeholder="Employee name, ID, or reason..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <button type="submit" class="btn-secondary">
                    <i class="fas fa-filter"></i>
                    Apply Filters
                </button>
                <a href="all_requests" class="btn-secondary">
                    <i class="fas fa-times"></i>
                    Clear
                </a>
            </form>
        </div>

        <!-- Requests Table -->
        <div class="requests-section">
            <div class="section-title">
                <i class="fas fa-table"></i>
                Leave Requests
            </div>

            <?php if ($requests_result->num_rows > 0): ?>
                <div style="overflow-x: auto;">
                    <table class="requests-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Leave Type</th>
                                <th>Date Range</th>
                                <th>Days</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th>Applied Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($request = $requests_result->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <div class="employee-info">
                                            <div class="employee-name"><?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?></div>
                                            <div class="employee-id">ID: <?php echo htmlspecialchars($request['employee_id']); ?></div>
                                            <?php if ($request['department_name']): ?>
                                                <div class="employee-dept"><?php echo htmlspecialchars($request['department_name']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="leave-type-badge" style="background-color: <?php echo htmlspecialchars($request['leave_type_color']); ?>">
                                            <?php echo htmlspecialchars($request['leave_type_name']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="date-range">
                                            <strong>From:</strong> <?php echo date('M d, Y', strtotime($request['start_date'])); ?><br>
                                            <strong>To:</strong> <?php echo date('M d, Y', strtotime($request['end_date'])); ?>
                                            <?php if ($request['start_work_date']): ?>
                                                <br><strong>Resume:</strong> <?php echo date('M d, Y', strtotime($request['start_work_date'])); ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="duration"><?php echo $request['total_days']; ?></div>
                                    </td>
                                    <td>
                                        <div class="reason-cell"><?php echo htmlspecialchars($request['reason']); ?></div>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $request['status']; ?>">
                                            <?php echo ucfirst($request['status']); ?>
                                        </span>
                                        <?php if ($request['status'] == 'approved' && $request['approver_first_name']): ?>
                                            <div class="processing-info">
                                                By: <?php echo htmlspecialchars($request['approver_first_name'] . ' ' . $request['approver_last_name']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="request-date">
                                            <?php echo date('M d, Y', strtotime($request['request_date'])); ?>
                                        </div>
                                        <div class="processing-info">
                                            <?php echo date('H:i', strtotime($request['request_date'])); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($request['status'] == 'pending'): ?>
                                                <a href="approve_request?id=<?php echo $request['request_id']; ?>" class="btn-sm btn-success">
                                                    <i class="fas fa-check"></i>
                                                    Approve
                                                </a>
                                                <a href="reject_request?id=<?php echo $request['request_id']; ?>" class="btn-sm btn-danger">
                                                    <i class="fas fa-times"></i>
                                                    Reject
                                                </a>
                                            <?php endif; ?>
                                            <a href="view_request?id=<?php echo $request['request_id']; ?>" class="btn-sm btn-info">
                                                <i class="fas fa-eye"></i>
                                                View
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="no-requests">
                    <i class="fas fa-inbox"></i>
                    <h3>No requests found</h3>
                    <p>No leave requests match your current filters.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>