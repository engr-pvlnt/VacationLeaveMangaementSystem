<?php
// hr/view_request.php
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

// Get request ID from URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: all_requests');
    exit();
}

$request_id = (int)$_GET['id'];

// Fetch detailed leave request information
$request_query = "SELECT 
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
                      lr.emergency_contact,
                      lr.emergency_phone,
                      u.first_name,
                      u.last_name,
                      u.employee_id,
                      u.email,
                      u.phone,
                      u.profile_image,
                      d.name as department_name,
                      jr.title as job_title,
                      lt.name as leave_type_name,
                      lt.color as leave_type_color,
                      lt.description as leave_type_description,
                      approver.first_name as approver_first_name,
                      approver.last_name as approver_last_name,
                      approver.email as approver_email,
                      reliever.first_name as reliever_first_name,
                      reliever.last_name as reliever_last_name,
                      reliever.email as reliever_email
                  FROM leave_requests lr 
                  LEFT JOIN users u ON lr.user_id = u.id
                  LEFT JOIN departments d ON u.department_id = d.id
                  LEFT JOIN job_roles jr ON u.job_role_id = jr.id
                  LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id 
                  LEFT JOIN users approver ON lr.approved_by = approver.id
                  LEFT JOIN users reliever ON lr.reliever_id = reliever.id
                  WHERE lr.id = ?";

$stmt = $conn->prepare($request_query);
$stmt->bind_param("i", $request_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    header('Location: all_requests');
    exit();
}

$request = $result->fetch_assoc();

// Calculate working days (excluding weekends)
function calculateWorkingDays($start_date, $end_date)
{
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $working_days = 0;

    while ($start <= $end) {
        $day_of_week = $start->format('N'); // 1 = Monday, 7 = Sunday
        if ($day_of_week < 6) { // Monday to Friday
            $working_days++;
        }
        $start->add(new DateInterval('P1D'));
    }

    return $working_days;
}

$working_days = calculateWorkingDays($request['start_date'], $request['end_date']);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>VLMS - View Leave Request</title>
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

        /* Page Header */
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

        .title-icon {
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

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        /* Request Details Card */
        .request-details {
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
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .detail-group {
            margin-bottom: 25px;
        }

        .detail-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-value {
            color: #555;
            font-size: 15px;
            line-height: 1.5;
        }

        .date-range-display {
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            margin: 15px 0;
        }

        .date-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .date-item:last-child {
            border-bottom: none;
        }

        .date-label {
            font-weight: 500;
            color: #666;
        }

        .date-value {
            font-weight: 600;
            color: #333;
        }

        .leave-type-badge {
            padding: 8px 16px;
            border-radius: 25px;
            font-size: 14px;
            font-weight: 500;
            color: white;
            display: inline-block;
        }

        .profile-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-image-placeholder {
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
        }

        .status-badge {
            padding: 8px 16px;
            border-radius: 25px;
            font-size: 14px;
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

        .reason-display {
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            border-left: 4px solid #667eea;
        }

        /* Employee Info Card */
        .employee-info {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 30px;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            height: fit-content;
        }

        .employee-avatar1 {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
            font-weight: 600;
            margin: 0 auto 20px;
        }

        .employee-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            margin: 0 auto 20px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }


        .employee-name {
            text-align: center;
            font-size: 20px;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }

        .employee-id {
            text-align: center;
            color: #666;
            margin-bottom: 20px;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-icon {
            width: 35px;
            height: 35px;
            border-radius: 8px;
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #667eea;
        }

        .info-text {
            flex: 1;
        }

        .info-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-weight: 500;
            color: #333;
            margin-top: 2px;
        }

        /* Action Buttons */
        .action-section {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 30px;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            grid-column: 1 / -1;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-success {
            background: #10b981;
            color: white;
        }

        .btn-success:hover {
            background: #059669;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
            color: white;
            text-decoration: none;
        }

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
            color: white;
            text-decoration: none;
        }

        .btn-secondary {
            background: #f8fafc;
            color: #333;
            border: 2px solid #e2e8f0;
        }

        .btn-secondary:hover {
            background: #e2e8f0;
            text-decoration: none;
            color: #333;
        }

        /* Processing Info */
        .processing-info {
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
        }

        .processing-title {
            font-weight: 600;
            color: #333;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
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

            .content-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .request-details,
            .employee-info,
            .action-section {
                padding: 20px;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn {
                justify-content: center;
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
                <a href="all_requests">
                    <i class="fas fa-clipboard-list"></i>
                    All Requests
                </a>
                <a href="employee_management">
                    <i class="fas fa-users"></i>
                    Employee Management
                </a>
                <a href="reports">
                    <i class="fas fa-chart-bar"></i>
                    Reports
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
                    <i class="fas fa-eye"></i>
                </div>
                <div>
                    <h1>Leave Request Details</h1>
                    <p style="color: #666; margin-top: 5px;">Request ID: #<?php echo $request['request_id']; ?></p>
                </div>
            </div>
            <a href="all_requests" class="btn-primary">
                <i class="fas fa-arrow-left"></i>
                Back to All Requests
            </a>
        </div>

        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Request Details -->
            <div class="request-details">
                <div class="section-title">
                    <i class="fas fa-file-alt"></i>
                    Request Information
                </div>

                <div class="detail-group">
                    <div class="detail-label">Leave Type</div>
                    <div class="detail-value">
                        <span class="leave-type-badge" style="background-color: <?php echo htmlspecialchars($request['leave_type_color']); ?>">
                            <?php echo htmlspecialchars($request['leave_type_name']); ?>
                        </span>
                        <?php if ($request['leave_type_description']): ?>
                            <p style="margin-top: 8px; color: #666; font-size: 14px;">
                                <?php echo htmlspecialchars($request['leave_type_description']); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="detail-group">
                    <div class="detail-label">Current Status</div>
                    <div class="detail-value">
                        <span class="status-badge status-<?php echo $request['status']; ?>">
                            <?php echo ucfirst($request['status']); ?>
                        </span>
                    </div>
                </div>

                <div class="detail-group">
                    <div class="detail-label">Leave Period</div>
                    <div class="date-range-display">
                        <div class="date-item">
                            <span class="date-label">Start Date:</span>
                            <span class="date-value"><?php echo date('l, F d, Y', strtotime($request['start_date'])); ?></span>
                        </div>
                        <div class="date-item">
                            <span class="date-label">End Date:</span>
                            <span class="date-value"><?php echo date('l, F d, Y', strtotime($request['end_date'])); ?></span>
                        </div>
                        <?php if ($request['start_work_date']): ?>
                            <div class="date-item">
                                <span class="date-label">Resume Work:</span>
                                <span class="date-value"><?php echo date('l, F d, Y', strtotime($request['start_work_date'])); ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="date-item">
                            <span class="date-label">Total Days:</span>
                            <span class="date-value"><?php echo $request['total_days']; ?> days</span>
                        </div>
                        <div class="date-item">
                            <span class="date-label">Working Days:</span>
                            <span class="date-value"><?php echo $working_days; ?> days</span>
                        </div>
                    </div>
                </div>

                <div class="detail-group">
                    <div class="detail-label">Reason for Leave</div>
                    <div class="reason-display">
                        <?php echo nl2br(htmlspecialchars($request['reason'])); ?>
                    </div>
                </div>

                <?php if ($request['emergency_contact'] || $request['emergency_phone']): ?>
                    <div class="detail-group">
                        <div class="detail-label">Emergency Contact</div>
                        <div class="detail-value">
                            <?php if ($request['emergency_contact']): ?>
                                <strong>Contact Person:</strong> <?php echo htmlspecialchars($request['emergency_contact']); ?><br>
                            <?php endif; ?>
                            <?php if ($request['emergency_phone']): ?>
                                <strong>Phone:</strong> <?php echo htmlspecialchars($request['emergency_phone']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($request['reliever_first_name']): ?>
                    <div class="detail-group">
                        <div class="detail-label">Work Coverage</div>
                        <div class="detail-value">
                            <strong>Reliever:</strong> <?php echo htmlspecialchars($request['reliever_first_name'] . ' ' . $request['reliever_last_name']); ?>
                            <?php if ($request['reliever_email']): ?>
                                <br><strong>Email:</strong> <?php echo htmlspecialchars($request['reliever_email']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="detail-group">
                    <div class="detail-label">Request Submitted</div>
                    <div class="detail-value">
                        <?php echo date('l, F d, Y \a\t H:i', strtotime($request['request_date'])); ?>
                    </div>
                </div>

                <!-- Processing Information -->
                <?php if ($request['status'] != 'pending'): ?>
                    <div class="processing-info">
                        <div class="processing-title">
                            <i class="fas fa-clock"></i>
                            Processing Details
                        </div>

                        <?php if ($request['status'] == 'approved'): ?>
                            <?php if ($request['approver_first_name']): ?>
                                <p><strong>Approved by:</strong> <?php echo htmlspecialchars($request['approver_first_name'] . ' ' . $request['approver_last_name']); ?></p>
                            <?php endif; ?>
                            <?php if ($request['approved_at']): ?>
                                <p><strong>Approved on:</strong> <?php echo date('F d, Y \a\t H:i', strtotime($request['approved_at'])); ?></p>
                            <?php endif; ?>
                            <?php if ($request['approval_notes']): ?>
                                <p><strong>Approval Notes:</strong></p>
                                <div style="background: white; padding: 15px; border-radius: 8px; margin-top: 8px;">
                                    <?php echo nl2br(htmlspecialchars($request['approval_notes'])); ?>
                                </div>
                            <?php endif; ?>
                        <?php elseif ($request['status'] == 'rejected'): ?>
                            <?php if ($request['rejected_at']): ?>
                                <p><strong>Rejected on:</strong> <?php echo date('F d, Y \a\t H:i', strtotime($request['rejected_at'])); ?></p>
                            <?php endif; ?>
                            <?php if ($request['rejection_reason']): ?>
                                <p><strong>Rejection Reason:</strong></p>
                                <div style="background: white; padding: 15px; border-radius: 8px; margin-top: 8px;">
                                    <?php echo nl2br(htmlspecialchars($request['rejection_reason'])); ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Employee Information -->
            <div class="employee-info">
                <div class="section-title">
                    <i class="fas fa-user"></i>
                    Employee Details
                </div>

                <div class="employee-avatar">
                    <?php if (!empty($request['profile_image']) && file_exists('../' . $request['profile_image'])): ?>
                        <img src="../<?php echo htmlspecialchars($request['profile_image']); ?>"
                            alt="Profile Picture"
                            class="profile-image">
                    <?php else: ?>
                        <div class="profile-image-placeholder">
                            <i class="fas fa-user"></i>
                        </div>
                    <?php endif; ?>
                </div>


                <div class="employee-name">
                    <?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?>
                </div>

                <div class="employee-id">
                    Employee ID: <?php echo htmlspecialchars($request['employee_id']); ?>
                </div>

                <div class="info-item">
                    <div class="info-icon">
                        <i class="fas fa-envelope"></i>
                    </div>
                    <div class="info-text">
                        <div class="info-label">Email</div>
                        <div class="info-value"><?php echo htmlspecialchars($request['email']); ?></div>
                    </div>
                </div>

                <?php if ($request['phone']): ?>
                    <div class="info-item">
                        <div class="info-icon">
                            <i class="fas fa-phone"></i>
                        </div>
                        <div class="info-text">
                            <div class="info-label">Phone</div>
                            <div class="info-value"><?php echo htmlspecialchars($request['phone']); ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($request['department_name']): ?>
                    <div class="info-item">
                        <div class="info-icon">
                            <i class="fas fa-building"></i>
                        </div>
                        <div class="info-text">
                            <div class="info-label">Department</div>
                            <div class="info-value"><?php echo htmlspecialchars($request['department_name']); ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($request['job_title']): ?>
                    <div class="info-item">
                        <div class="info-icon">
                            <i class="fas fa-briefcase"></i>
                        </div>
                        <div class="info-text">
                            <div class="info-label">Job Title</div>
                            <div class="info-value"><?php echo htmlspecialchars($request['job_title']); ?></div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Action Buttons -->
        <?php if ($request['status'] == 'pending'): ?>
            <div class="action-section">
                <div class="section-title">
                    <i class="fas fa-cogs"></i>
                    Actions
                </div>
                <div class="action-buttons">
                    <a href="approve_request?id=<?php echo $request['request_id']; ?>" class="btn btn-success">
                        <i class="fas fa-check"></i>
                        Approve Request
                    </a>
                    <a href="reject_request?id=<?php echo $request['request_id']; ?>" class="btn btn-danger">
                        <i class="fas fa-times"></i>
                        Reject Request
                    </a>
                    <a href="all_requests" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to All Requests
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>

</html>