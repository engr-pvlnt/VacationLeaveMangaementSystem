<?php
// system_logs.php
session_start();

// Include database connection
include '../config/db.php'; 

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login');
    exit();
}

$message = '';
$message_type = '';

// Get messages from session
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

// Pagination variables
$records_per_page = 20;
$page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Filter variables
$user_filter = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? $_GET['user_id'] : '';
$action_filter = isset($_GET['action']) && $_GET['action'] !== '' ? $_GET['action'] : '';
$table_filter = isset($_GET['table_name']) && $_GET['table_name'] !== '' ? $_GET['table_name'] : '';
$date_from = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) && $_GET['date_to'] !== '' ? $_GET['date_to'] : '';

// Build WHERE clause for filters
$where_conditions = [];
$count_params = [];
$query_params = [];
$count_param_types = '';
$query_param_types = '';

if (!empty($user_filter)) {
    $where_conditions[] = "al.user_id = ?";
    $count_params[] = $user_filter;
    $query_params[] = $user_filter;
    $count_param_types .= 'i';
    $query_param_types .= 'i';
}

if (!empty($action_filter)) {
    $where_conditions[] = "al.action LIKE ?";
    $filter_value = '%' . $action_filter . '%';
    $count_params[] = $filter_value;
    $query_params[] = $filter_value;
    $count_param_types .= 's';
    $query_param_types .= 's';
}

if (!empty($table_filter)) {
    $where_conditions[] = "al.table_name = ?";
    $count_params[] = $table_filter;
    $query_params[] = $table_filter;
    $count_param_types .= 's';
    $query_param_types .= 's';
}

if (!empty($date_from)) {
    $where_conditions[] = "DATE(al.created_at) >= ?";
    $count_params[] = $date_from;
    $query_params[] = $date_from;
    $count_param_types .= 's';
    $query_param_types .= 's';
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(al.created_at) <= ?";
    $count_params[] = $date_to;
    $query_params[] = $date_to;
    $count_param_types .= 's';
    $query_param_types .= 's';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Count total records for pagination
$count_query = "
    SELECT COUNT(*) as total 
    FROM system_logs al 
    LEFT JOIN users u ON al.user_id = u.id 
    $where_clause
";

$total_records = 0;
if (!empty($count_params)) {
    $count_stmt = $conn->prepare($count_query);
    if ($count_stmt) {
        $count_stmt->bind_param($count_param_types, ...$count_params);
        $count_stmt->execute();
        $count_result = $count_stmt->get_result();
        if ($count_result) {
            $total_records = $count_result->fetch_assoc()['total'];
        }
        $count_stmt->close();
    }
} else {
    $count_result = $conn->query($count_query);
    if ($count_result) {
        $total_records = $count_result->fetch_assoc()['total'];
    }
}

$total_pages = ceil($total_records / $records_per_page);

// Ensure page is within valid range
if ($page > $total_pages && $total_pages > 0) {
    $page = $total_pages;
    $offset = ($page - 1) * $records_per_page;
}

// Fetch audit logs with user information
$query = "
    SELECT al.*, 
           CONCAT(u.first_name, ' ', u.last_name) as user_name,
           u.username,
           u.email
    FROM system_logs al 
    LEFT JOIN users u ON al.user_id = u.id 
    $where_clause
    ORDER BY al.created_at DESC 
    LIMIT ? OFFSET ?
";

// Add pagination parameters to query params
$query_params[] = $records_per_page;
$query_params[] = $offset;
$query_param_types .= 'ii';

$system_logs = [];
$stmt = $conn->prepare($query);
if ($stmt) {
    if (!empty($query_params)) {
        $stmt->bind_param($query_param_types, ...$query_params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $system_logs[] = $row;
        }
    }
    $stmt->close();
}

// Get unique users for filter dropdown
$users_query = "SELECT DISTINCT u.id, CONCAT(u.first_name, ' ', u.last_name) as full_name 
                FROM users u 
                INNER JOIN system_logs al ON u.id = al.user_id 
                ORDER BY full_name";
$users_result = $conn->query($users_query);
$users_for_filter = [];
if ($users_result) {
    while ($row = $users_result->fetch_assoc()) {
        $users_for_filter[] = $row;
    }
}

// Get unique actions for filter dropdown
$actions_query = "SELECT DISTINCT action FROM system_logs ORDER BY action";
$actions_result = $conn->query($actions_query);
$actions_for_filter = [];
if ($actions_result) {
    while ($row = $actions_result->fetch_assoc()) {
        $actions_for_filter[] = $row['action'];
    }
}

// Get unique table names for filter dropdown
$tables_query = "SELECT DISTINCT table_name FROM system_logs WHERE table_name IS NOT NULL ORDER BY table_name";
$tables_result = $conn->query($tables_query);
$tables_for_filter = [];
if ($tables_result) {
    while ($row = $tables_result->fetch_assoc()) {
        $tables_for_filter[] = $row['table_name'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VLMS - Audit Logs</title>
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

        /* Filter styles */
        .filter-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            animation: slideUp 0.8s ease-out 0.1s both;
        }

        .filter-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: #333;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-label {
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #374151;
        }

        .filter-input {
            padding: 0.75rem;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: white;
        }

        .filter-input:focus {
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

        .btn-secondary {
            background: linear-gradient(135deg, #6b7280, #4b5563);
            color: white;
        }

        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(107, 114, 128, 0.4);
        }

        .btn-info {
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
            color: white;
        }

        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(14, 165, 233, 0.4);
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
            vertical-align: top;
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

        .action-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .action-create {
            background: rgba(34, 197, 94, 0.1);
            color: #059669;
            border: 1px solid rgba(34, 197, 94, 0.3);
        }

        .action-update {
            background: rgba(245, 158, 11, 0.1);
            color: #d97706;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .action-delete {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .action-login {
            background: rgba(59, 130, 246, 0.1);
            color: #2563eb;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }

        .action-logout {
            background: rgba(107, 114, 128, 0.1);
            color: #4b5563;
            border: 1px solid rgba(107, 114, 128, 0.3);
        }

        .json-data {
            max-width: 200px;
            overflow-x: auto;
            font-family: 'Courier New', monospace;
            font-size: 0.8rem;
            background: #f8fafc;
            padding: 0.5rem;
            border-radius: 5px;
            white-space: pre-wrap;
        }

        /* Pagination styles */
        .pagination-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 1.5rem;
            margin-top: 2rem;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 1rem;
        }

        .pagination {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .pagination a, .pagination span {
            padding: 0.5rem 1rem;
            border-radius: 8px;
            text-decoration: none;
            color: #667eea;
            border: 1px solid #e5e7eb;
            transition: all 0.3s ease;
        }

        .pagination a:hover {
            background: #667eea;
            color: white;
            transform: translateY(-2px);
        }

        .pagination .current {
            background: #667eea;
            color: white;
            border-color: #667eea;
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
            max-width: 800px;
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

        .modal-title {
            margin-bottom: 1rem;
            color: #333;
            font-size: 1.5rem;
            font-weight: 600;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 1rem 2rem;
            margin-bottom: 1.5rem;
        }

        .detail-label {
            font-weight: 600;
            color: #374151;
        }

        .detail-value {
            color: #6b7280;
        }

        .json-viewer {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 1rem;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            white-space: pre-wrap;
            overflow-x: auto;
            max-height: 300px;
            overflow-y: auto;
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

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .actions-cell {
                flex-direction: column;
            }

            .page-title {
                font-size: 1.5rem;
            }

            .table-container, .filter-container {
                padding: 1rem;
            }

            .pagination-container {
                flex-direction: column;
            }
        }

    </style>
</head>

<body>
    <!-- Animated background particles -->
    <div class="bg-particles" id="particles"></div>

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
                <i class="fas fa-clipboard-list"></i>
                Audit Logs
            </h1>
            <p class="page-subtitle">View system activity and user actions audit trail</p>
        </div>

        <!-- Messages -->
        <?php if (!empty($message)) { ?>
            <div class="message <?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <?php echo $message; ?>
            </div>
        <?php } ?>

        <!-- Filters -->
        <div class="filter-container">
            <h2 class="filter-title">
                <i class="fas fa-filter"></i>
                Filter Audit Logs
            </h2>
            <form method="GET" action="">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label class="filter-label">User:</label>
                        <select name="user_id" class="filter-input">
                            <option value="">All Users</option>
                            <?php foreach ($users_for_filter as $user) { ?>
                                <option value="<?php echo $user['id']; ?>" <?php echo $user_filter == $user['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['full_name']); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Action:</label>
                        <select name="action" class="filter-input">
                            <option value="">All Actions</option>
                            <?php foreach ($actions_for_filter as $action) { ?>
                                <option value="<?php echo htmlspecialchars($action); ?>" <?php echo $action_filter == $action ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($action); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Table:</label>
                        <select name="table_name" class="filter-input">
                            <option value="">All Tables</option>
                            <?php foreach ($tables_for_filter as $table) { ?>
                                <option value="<?php echo htmlspecialchars($table); ?>" <?php echo $table_filter == $table ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($table); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Date From:</label>
                        <input type="date" name="date_from" class="filter-input" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Date To:</label>
                        <input type="date" name="date_to" class="filter-input" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                </div>
                
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i>
                        Apply Filters
                    </button>
                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-secondary">
                        <i class="fas fa-undo"></i>
                        Clear Filters
                    </a>
                </div>
            </form>
        </div>

        <!-- Audit logs table -->
        <div class="table-container">
            <h2 class="filter-title">
                <i class="fas fa-list"></i>
                Audit Log Records (<?php echo number_format($total_records); ?> total)
            </h2>
            
            <?php if (empty($system_logs)) { ?>
                <div style="text-align: center; padding: 2rem; color: #666;">
                    <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.3;"></i>
                    <p>No audit logs found matching your criteria.</p>
                </div>
            <?php } else { ?>
                <div style="overflow-x: auto;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Date/Time</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Table</th>
                                <th>Record ID</th>
                                <th>IP Address</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($system_logs as $log) { ?>
                                <tr>
                                    <td><?php echo $log['id']; ?></td>
                                    <td>
                                        <div style="font-weight: 500;">
                                            <?php echo date('M j, Y', strtotime($log['created_at'])); ?>
                                        </div>
                                        <div style="font-size: 0.85rem; color: #666;">
                                            <?php echo date('g:i:s A', strtotime($log['created_at'])); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($log['user_name']) { ?>
                                            <div style="font-weight: 500;">
                                                <?php echo htmlspecialchars($log['user_name']); ?>
                                            </div>
                                            <div style="font-size: 0.85rem; color: #666;">
                                                <?php echo htmlspecialchars($log['username']); ?>
                                            </div>
                                        <?php } else { ?>
                                            <span style="color: #999; font-style: italic;">System</span>
                                        <?php } ?>
                                    </td>
                                    <td>
                                        <?php
                                        $action_class = 'action-badge ';
                                        $action_lower = strtolower($log['action']);
                                        if (strpos($action_lower, 'create') !== false || strpos($action_lower, 'insert') !== false) {
                                            $action_class .= 'action-create';
                                        } elseif (strpos($action_lower, 'update') !== false || strpos($action_lower, 'edit') !== false) {
                                            $action_class .= 'action-update';
                                        } elseif (strpos($action_lower, 'delete') !== false) {
                                            $action_class .= 'action-delete';
                                        } elseif (strpos($action_lower, 'login') !== false) {
                                            $action_class .= 'action-login';
                                        } elseif (strpos($action_lower, 'logout') !== false) {
                                            $action_class .= 'action-logout';
                                        } else {
                                            $action_class .= 'action-create';
                                        }
                                        ?>
                                        <span class="<?php echo $action_class; ?>">
                                            <?php echo htmlspecialchars($log['action']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo $log['table_name'] ? htmlspecialchars($log['table_name']) : '<span style="color: #999;">N/A</span>'; ?>
                                    </td>
                                    <td>
                                        <?php echo $log['record_id'] ? $log['record_id'] : '<span style="color: #999;">N/A</span>'; ?>
                                    </td>
                                    <td>
                                        <span style="font-family: monospace; font-size: 0.9rem;">
                                            <?php echo htmlspecialchars($log['ip_address'] ?: 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-info" onclick="showDetails(<?php echo $log['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                            View Details
                                        </button>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            <?php } ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1) { ?>
            <div class="pagination-container">
                <div class="pagination">
                    <?php
                    // Build query string for pagination links
                    $query_params_for_pagination = [];
                    if (!empty($user_filter)) $query_params_for_pagination['user_id'] = $user_filter;
                    if (!empty($action_filter)) $query_params_for_pagination['action'] = $action_filter;
                    if (!empty($table_filter)) $query_params_for_pagination['table_name'] = $table_filter;
                    if (!empty($date_from)) $query_params_for_pagination['date_from'] = $date_from;
                    if (!empty($date_to)) $query_params_for_pagination['date_to'] = $date_to;
                    
                    $query_string = !empty($query_params_for_pagination) ? '&' . http_build_query($query_params_for_pagination) : '';
                    
                    // Previous page
                    if ($page > 1) {
                        echo '<a href="?page=' . ($page - 1) . $query_string . '"><i class="fas fa-chevron-left"></i> Previous</a>';
                    }
                    
                    // Page numbers
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    
                    if ($start_page > 1) {
                        echo '<a href="?page=1' . $query_string . '">1</a>';
                        if ($start_page > 2) {
                            echo '<span>...</span>';
                        }
                    }
                    
                    for ($i = $start_page; $i <= $end_page; $i++) {
                        if ($i == $page) {
                            echo '<span class="current">' . $i . '</span>';
                        } else {
                            echo '<a href="?page=' . $i . $query_string . '">' . $i . '</a>';
                        }
                    }
                    
                    if ($end_page < $total_pages) {
                        if ($end_page < $total_pages - 1) {
                            echo '<span>...</span>';
                        }
                        echo '<a href="?page=' . $total_pages . $query_string . '">' . $total_pages . '</a>';
                    }
                    
                    // Next page
                    if ($page < $total_pages) {
                        echo '<a href="?page=' . ($page + 1) . $query_string . '">Next <i class="fas fa-chevron-right"></i></a>';
                    }
                    ?>
                </div>
                <div style="color: #666; font-size: 0.9rem;">
                    Showing <?php echo (($page - 1) * $records_per_page) + 1; ?> to 
                    <?php echo min($page * $records_per_page, $total_records); ?> of 
                    <?php echo number_format($total_records); ?> records
                </div>
            </div>
        <?php } ?>
    </div>

    <!-- Details Modal -->
    <div id="detailsModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2 class="modal-title">Audit Log Details</h2>
            <div id="modalContent">
                <!-- Content will be loaded here -->
            </div>
        </div>
    </div>

    <script>
        // Create animated particles
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

        // Show details modal
        function showDetails(logId) {
            const modal = document.getElementById('detailsModal');
            const modalContent = document.getElementById('modalContent');
            
            // Find the log data
            const logs = <?php echo json_encode($system_logs); ?>;
            const log = logs.find(l => l.id == logId);
            
            if (!log) {
                modalContent.innerHTML = '<p>Error: Log details not found.</p>';
                modal.style.display = 'block';
                return;
            }
            
            let content = '<div class="detail-grid">';
            content += '<div class="detail-label">ID:</div><div class="detail-value">' + log.id + '</div>';
            content += '<div class="detail-label">Date/Time:</div><div class="detail-value">' + new Date(log.created_at).toLocaleString() + '</div>';
            content += '<div class="detail-label">User:</div><div class="detail-value">' + (log.user_name || 'System') + '</div>';
            
            if (log.username) {
                content += '<div class="detail-label">Username:</div><div class="detail-value">' + log.username + '</div>';
            }
            if (log.email) {
                content += '<div class="detail-label">Email:</div><div class="detail-value">' + log.email + '</div>';
            }
            
            content += '<div class="detail-label">Action:</div><div class="detail-value">' + log.action + '</div>';
            content += '<div class="detail-label">Table:</div><div class="detail-value">' + (log.table_name || 'N/A') + '</div>';
            content += '<div class="detail-label">Record ID:</div><div class="detail-value">' + (log.record_id || 'N/A') + '</div>';
            content += '<div class="detail-label">IP Address:</div><div class="detail-value">' + (log.ip_address || 'N/A') + '</div>';
            content += '</div>';
            
            if (log.user_agent) {
                content += '<div style="margin-top: 1.5rem;"><strong>User Agent:</strong></div>';
                content += '<div class="json-viewer">' + log.user_agent + '</div>';
            }
            
            if (log.old_values) {
                content += '<div style="margin-top: 1.5rem;"><strong>Old Values:</strong></div>';
                try {
                    const oldValues = JSON.parse(log.old_values);
                    content += '<div class="json-viewer">' + JSON.stringify(oldValues, null, 2) + '</div>';
                } catch (e) {
                    content += '<div class="json-viewer">' + log.old_values + '</div>';
                }
            }
            
            if (log.new_values) {
                content += '<div style="margin-top: 1.5rem;"><strong>New Values:</strong></div>';
                try {
                    const newValues = JSON.parse(log.new_values);
                    content += '<div class="json-viewer">' + JSON.stringify(newValues, null, 2) + '</div>';
                } catch (e) {
                    content += '<div class="json-viewer">' + log.new_values + '</div>';
                }
            }
            
            modalContent.innerHTML = content;
            modal.style.display = 'block';
        }

        // Close modal
        function closeModal() {
            document.getElementById('detailsModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('detailsModal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }

        // Initialize particles when page loads
        document.addEventListener('DOMContentLoaded', function() {
            createParticles();
        });
    </script>
</body>

</html>