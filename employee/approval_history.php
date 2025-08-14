<?php
//approval_history.php
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

// Fetch approval history for the current user's leave requests
$approval_history_query = "SELECT 
                              lr.id as request_id,
                              lr.start_date,
                              lr.end_date,
                              lr.reason,
                              lr.status,
                              lr.created_at as request_date,
                              lr.approved_at,
                              lr.rejected_at,
                              lt.name as leave_type_name,
                              lt.color as leave_type_color,
                              approver.first_name as approver_first_name,
                              approver.last_name as approver_last_name,
                              lr.approval_notes,
                              DATEDIFF(lr.end_date, lr.start_date) + 1 as duration_days
                          FROM leave_requests lr 
                          LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id 
                          LEFT JOIN users approver ON lr.approved_by = approver.id
                          WHERE lr.user_id = ? AND lr.status != 'pending'
                          ORDER BY lr.created_at DESC";

$stmt = $conn->prepare($approval_history_query);
if ($stmt === false) {
    die('Prepare failed: ' . htmlspecialchars($conn->error));
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$approval_history_result = $stmt->get_result();

// Calculate approval statistics
$approval_stats_query = "SELECT 
                            COUNT(*) as total_processed,
                            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_count,
                            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
                            SUM(CASE WHEN status = 'approved' THEN DATEDIFF(end_date, start_date) + 1 ELSE 0 END) as approved_days,
                            ROUND(AVG(CASE WHEN status = 'approved' AND approved_at IS NOT NULL 
                                          THEN DATEDIFF(approved_at, created_at) ELSE NULL END), 1) as avg_approval_time
                        FROM leave_requests 
                        WHERE user_id = ? AND status != 'pending'";

$stats_stmt = $conn->prepare($approval_stats_query);
if ($stats_stmt === false) {
    die('Stats prepare failed: ' . htmlspecialchars($conn->error));
}
$stats_stmt->bind_param("i", $user_id);
$stats_stmt->execute();
$approval_stats = $stats_stmt->get_result()->fetch_assoc();

// Calculate approval rate
$approval_rate = $approval_stats['total_processed'] > 0 
                ? round(($approval_stats['approved_count'] / $approval_stats['total_processed']) * 100, 1) 
                : 0;

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>VLMS - Approval History</title>
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

        .stat-icon.success { background: linear-gradient(135deg, #56ab2f, #a8e6cf); }
        .stat-icon.danger { background: linear-gradient(135deg, #ff6b6b, #ee5a5a); }
        .stat-icon.info { background: linear-gradient(135deg, #4facfe, #00f2fe); }
        .stat-icon.warning { background: linear-gradient(135deg, #f093fb, #f5576c); }
        .stat-icon.purple { background: linear-gradient(135deg, #667eea, #764ba2); }

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

        /* Approval History Table */
        .history-section {
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

        .history-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .history-table th {
            background: #f8fafc;
            color: #333;
            font-weight: 600;
            padding: 15px;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
        }

        .history-table td {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }

        .history-table tbody tr:hover {
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
            line-height: 1.4;
        }

        .duration {
            font-weight: 500;
            color: #333;
            text-align: center;
        }

        .approver-info {
            font-size: 14px;
            color: #555;
            font-weight: 500;
        }

        .approval-date {
            font-size: 13px;
            color: #666;
        }

        .approval-notes {
            max-width: 250px;
            word-wrap: break-word;
            font-size: 14px;
            line-height: 1.4;
            font-style: italic;
            color: #666;
        }

        .reason-cell {
            max-width: 200px;
            word-wrap: break-word;
            font-size: 14px;
            line-height: 1.4;
        }

        .no-history {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .no-history i {
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

            .history-section {
                padding: 20px;
            }

            .history-table {
                font-size: 14px;
            }

            .history-table th,
            .history-table td {
                padding: 10px 8px;
            }

            .reason-cell,
            .approval-notes {
                max-width: 150px;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .history-table {
                font-size: 12px;
            }

            .history-table th,
            .history-table td {
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
                <a href="my_requests">
                    <i class="fas fa-list"></i>
                    My Requests
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
                    <i class="fas fa-history"></i>
                </div>
                <div>
                    <h1>Approval History</h1>
                    <p style="color: #666; margin-top: 5px;">View your processed leave requests</p>
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
                <div class="stat-icon success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value"><?php echo $approval_stats['approved_count']; ?></div>
                <div class="stat-label">Approved Requests</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon danger">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="stat-value"><?php echo $approval_stats['rejected_count']; ?></div>
                <div class="stat-label">Rejected Requests</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon info">
                    <i class="fas fa-percentage"></i>
                </div>
                <div class="stat-value"><?php echo $approval_rate; ?>%</div>
                <div class="stat-label">Approval Rate</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon warning">
                    <i class="fas fa-calendar-days"></i>
                </div>
                <div class="stat-value"><?php echo $approval_stats['approved_days'] ?: '0'; ?></div>
                <div class="stat-label">Approved Days</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value"><?php echo $approval_stats['avg_approval_time'] ?: '0'; ?></div>
                <div class="stat-label">Avg. Processing Days</div>
            </div>
        </div>

        <!-- Approval History Table -->
        <div class="history-section">
            <div class="section-title">
                <i class="fas fa-clipboard-list"></i>
                Processed Leave Requests
            </div>

            <?php if ($approval_history_result->num_rows > 0): ?>
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>Leave Type</th>
                            <th>Date Range</th>
                            <th>Duration</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Processed By</th>
                            <th>Processing Date</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($history = $approval_history_result->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <span class="leave-type-badge" style="background-color: <?php echo $history['leave_type_color'] ?: '#667eea'; ?>">
                                        <?php echo htmlspecialchars($history['leave_type_name'] ?: 'N/A'); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="date-range">
                                        <strong>From:</strong> <?php echo date('M d, Y', strtotime($history['start_date'])); ?><br>
                                        <strong>To:</strong> <?php echo date('M d, Y', strtotime($history['end_date'])); ?>
                                    </div>
                                </td>
                                <td class="duration">
                                    <?php echo $history['duration_days']; ?> 
                                    <?php echo $history['duration_days'] == 1 ? 'day' : 'days'; ?>
                                </td>
                                <td class="reason-cell">
                                    <?php echo htmlspecialchars($history['reason']); ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $history['status']; ?>">
                                        <i class="fas fa-<?php echo $history['status'] == 'approved' ? 'check' : 'times'; ?>"></i>
                                        <?php echo ucfirst($history['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($history['approver_first_name']): ?>
                                        <div class="approver-info">
                                            <?php echo htmlspecialchars($history['approver_first_name'] . ' ' . $history['approver_last_name']); ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #999;">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $processing_date = $history['status'] == 'approved' ? $history['approved_at'] : $history['rejected_at'];
                                    if ($processing_date): 
                                    ?>
                                        <div class="approval-date">
                                            <?php echo date('M d, Y', strtotime($processing_date)); ?><br>
                                            <small><?php echo date('g:i A', strtotime($processing_date)); ?></small>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #999;">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td class="approval-notes">
                                    <?php echo htmlspecialchars($history['approval_notes']) ?: '<em>No notes</em>'; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="no-history">
                    <i class="fas fa-history"></i>
                    <h3>No Approval History Found</h3>
                    <p>You don't have any processed leave requests yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>