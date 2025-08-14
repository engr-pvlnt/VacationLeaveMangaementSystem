<?php
//my_requests.php
session_start();
include '../config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login');
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch current user data
$result = $conn->query("SELECT * FROM users WHERE id = $user_id");
$user = $result->fetch_assoc();

// Fetch role description
$role_query = "SELECT r.name FROM roles r 
               JOIN users u ON u.role_id = r.id 
               WHERE u.id = $user_id";
$role_result = $conn->query($role_query);
$role = $role_result->fetch_assoc();

// Fetch leave requests for the current user
$leave_requests_query = "SELECT lr.*, lt.name as leave_type_name, lt.color as leave_type_color
                        FROM leave_requests lr 
                        LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id 
                        WHERE lr.user_id = ?
                        ORDER BY lr.created_at DESC";

$stmt = $conn->prepare($leave_requests_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$leave_requests_result = $stmt->get_result();

// Alternative query if the JOIN is causing issues
if ($leave_requests_result->num_rows == 0) {
    $simple_query = "SELECT * FROM leave_requests WHERE user_id = ? ORDER BY created_at DESC";
    $simple_stmt = $conn->prepare($simple_query);
    $simple_stmt->bind_param("i", $user_id);
    $simple_stmt->execute();
    $leave_requests_result = $simple_stmt->get_result();
}

// Calculate statistics
$stats_query = "SELECT 
                    COUNT(*) as total_requests,
                    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                    SUM(CASE WHEN status = 'approved' THEN DATEDIFF(end_date, start_date) + 1 ELSE 0 END) as total_days_taken
                FROM leave_requests 
                WHERE user_id = $user_id";
$stats = $conn->query($stats_query)->fetch_assoc();

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>VLMS - My Leave Requests</title>
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
            max-width: 1200px;
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

        .header-right {
            text-align: right;
        }

        .header-description {
            margin-top: 8px;
            font-size: 14px;
            color: #666;
            font-style: italic;
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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

        .stat-icon.primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
        }

        .stat-icon.success {
            background: linear-gradient(135deg, #56ab2f, #a8e6cf);
        }

        .stat-icon.warning {
            background: linear-gradient(135deg, #f093fb, #f5576c);
        }

        .stat-icon.info {
            background: linear-gradient(135deg, #4facfe, #00f2fe);
        }

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
            padding: 15px;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
        }

        .requests-table td {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }

        .requests-table tbody tr:hover {
            background-color: #f8fafc;
        }

        .leave-type-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            color: white;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            text-transform: capitalize;
        }

        .status-pending {
            background: #fef3c7;
            color: #d97706;
        }

        .status-approved {
            background: #d1fae5;
            color: #065f46;
        }

        .status-rejected {
            background: #fee2e2;
            color: #dc2626;
        }

        .date-range {
            font-size: 14px;
            color: #555;
        }

        .duration {
            font-weight: 500;
            color: #333;
        }

        .reason-cell {
            max-width: 250px;
            word-wrap: break-word;
            font-size: 14px;
            line-height: 1.4;
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

            .requests-section {
                padding: 20px;
            }

            .requests-table {
                font-size: 14px;
            }

            .requests-table th,
            .requests-table td {
                padding: 10px 8px;
            }

            .reason-cell {
                max-width: 150px;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .requests-table {
                font-size: 12px;
            }

            .requests-table th,
            .requests-table td {
                padding: 8px 6px;
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
                <?php
                if ($role['name'] == 'Admin' || $role['name'] == 'HR') {
                    echo '<a href="../admin/index">';
                } else {
                    echo '<a href="index">';
                }
                ?>
                <i class="fas fa-dashboard"></i>
                Dashboard
                </a>
                <a href="../auth/profile">
                    <i class="fas fa-user"></i>
                    Profile
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
                    <i class="fas fa-list-alt"></i>
                </div>
                <div class="title-text">
                    <h1>My Leave Requests</h1>
                    <p class="header-description">View and manage your leave requests</p>
                </div>
            </div>
            <a href="leave_request" class="btn-primary">
                <i class="fas fa-plus"></i>
                New Request
            </a>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon primary">
                    <i class="fas fa-clipboard-list"></i>
                </div>
                <div class="stat-value"><?php echo $stats['total_requests'] ?? 0; ?></div>
                <div class="stat-label">Total Requests</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value"><?php echo $stats['approved'] ?? 0; ?></div>
                <div class="stat-label">Approved</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon warning">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value"><?php echo $stats['pending'] ?? 0; ?></div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon info">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="stat-value"><?php echo $stats['total_days_taken'] ?? 0; ?></div>
                <div class="stat-label">Days Taken</div>
            </div>
        </div>

        <!-- Requests Table -->
        <div class="requests-section">
            <h2 class="section-title">
                <i class="fas fa-history"></i>
                Request History
            </h2>

            <?php if ($leave_requests_result && $leave_requests_result->num_rows > 0): ?>
                <table class="requests-table">
                    <thead>
                        <tr>
                            <th>Leave Type</th>
                            <th>Date Range</th>
                            <th>Duration</th>
                            <th>Status</th>
                            <th>Reason</th>
                            <th>Applied</th>
                            <th>Response</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($request = $leave_requests_result->fetch_assoc()): ?>
                            <?php
                            $start_date = new DateTime($request['start_date']);
                            $end_date = new DateTime($request['end_date']);
                            $duration = $start_date->diff($end_date)->days + 1;
                            $created_date = new DateTime($request['created_at']);

                            // Handle case where leave type might not be found
                            $leave_type_name = $request['leave_type_name'] ?? 'Unknown';
                            $leave_type_color = $request['leave_type_color'] ?? '#6b7280';
                            ?>
                            <tr>
                                <td>
                                    <span class="leave-type-badge" style="background-color: <?php echo htmlspecialchars($leave_type_color); ?>">
                                        <?php echo htmlspecialchars($leave_type_name); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="date-range">
                                        <div><?php echo $start_date->format('M d, Y'); ?></div>
                                        <div>to <?php echo $end_date->format('M d, Y'); ?></div>
                                    </div>
                                </td>
                                <td>
                                    <span class="duration"><?php echo $duration; ?> day<?php echo $duration > 1 ? 's' : ''; ?></span>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $request['status']; ?>">
                                        <?php echo ucfirst($request['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="reason-cell">
                                        <?php echo htmlspecialchars($request['reason']); ?>
                                    </div>
                                </td>
                                <td><?php echo $created_date->format('M d, Y'); ?></td>
                                <td>
                                    <?php if (!empty($request['responded_at'])): ?>
                                        <?php $response_date = new DateTime($request['responded_at']); ?>
                                        <?php echo $response_date->format('M d, Y'); ?>
                                    <?php else: ?>
                                        <span style="color: #999;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="no-requests">
                    <i class="fas fa-inbox"></i>
                    <h3>No Leave Requests Found</h3>
                    <p>You haven't submitted any leave requests yet.</p>

                </div>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>