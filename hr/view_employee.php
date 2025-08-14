<?php
// hr/view_employee.php
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

// Get employee ID from URL
if (!isset($_GET['id'])) {
    header('Location: employee_management');
    exit();
}

$employee_id = $_GET['id'];

// Fetch employee details with all related information
$employee_query = "SELECT 
                        u.id,
                        u.employee_id,
                        u.first_name,
                        u.last_name,
                        u.email,
                        u.phone,
                        u.date_of_birth,
                        u.address,
                        u.hire_date,
                        u.status,
                        u.last_login,
                        u.created_at,
                        u.profile_image,
                        u.emergency_contact_name,
                        u.emergency_contact_phone,
                        u.emergency_contact_relationship,
                        u.salary,
                        d.name as department_name,
                        jr.title as job_title,
                        jr.description as job_description,
                        r.name as role_name,
                        CONCAT(m.first_name, ' ', m.last_name) as manager_name,
                        m.email as manager_email
                    FROM users u
                    LEFT JOIN departments d ON u.department_id = d.id
                    LEFT JOIN job_roles jr ON u.job_role_id = jr.id
                    LEFT JOIN roles r ON u.role_id = r.id
                    LEFT JOIN users m ON u.manager_id = m.id
                    WHERE u.id = ?";

$stmt = $conn->prepare($employee_query);
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    header('Location: employee_management');
    exit();
}

$employee = $result->fetch_assoc();

// Fetch employee's leave requests
$leave_requests_query = "SELECT 
                            lr.id,
                            lr.start_date,
                            lr.end_date,
                            lr.total_days,
                            lr.reason,
                            lr.status,
                            lr.created_at,
                            lt.name as leave_type_name,
                            lt.color as leave_type_color
                        FROM leave_requests lr
                        LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id
                        WHERE lr.user_id = ?
                        ORDER BY lr.created_at DESC
                        LIMIT 10";

$leave_stmt = $conn->prepare($leave_requests_query);
$leave_stmt->bind_param("i", $employee_id);
$leave_stmt->execute();
$leave_requests = $leave_stmt->get_result();

// Calculate leave statistics
$leave_stats_query = "SELECT 
                        COUNT(*) as total_requests,
                        SUM(CASE WHEN lr.status = 'approved' THEN lr.total_days ELSE 0 END) as approved_days,
                        SUM(CASE WHEN lr.status = 'pending' THEN 1 ELSE 0 END) as pending_requests,
                        SUM(CASE WHEN lr.status = 'rejected' THEN 1 ELSE 0 END) as rejected_requests
                    FROM leave_requests lr
                    WHERE lr.user_id = ? AND YEAR(lr.start_date) = YEAR(CURDATE())";

$stats_stmt = $conn->prepare($leave_stats_query);
$stats_stmt->bind_param("i", $employee_id);
$stats_stmt->execute();
$leave_stats = $stats_stmt->get_result()->fetch_assoc();

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>VLMS - View Employee</title>
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

        /* Breadcrumb */
        .breadcrumb {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 25px;
            backdrop-filter: blur(10px);
        }

        .breadcrumb a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .breadcrumb span {
            color: #666;
            margin: 0 8px;
        }

        /* Page Header */
        .page-header {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .employee-header {
            display: flex;
            align-items: center;
            gap: 25px;
            margin-bottom: 20px;
        }

        .employee-avatar-large {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 36px;
            flex-shrink: 0;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }

        .employee-avatar-large img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }

        .employee-title {
            flex: 1;
        }

        .employee-name {
            font-size: 32px;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }

        .employee-subtitle {
            color: #666;
            font-size: 16px;
            margin-bottom: 10px;
        }

        .employee-meta {
            display: flex;
            gap: 25px;
            align-items: center;
            flex-wrap: wrap;
        }

        .status-badge {
            padding: 8px 16px;
            border-radius: 25px;
            font-size: 14px;
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
            padding: 8px 16px;
            border-radius: 25px;
            font-size: 14px;
            font-weight: 500;
            color: white;
            display: inline-block;
        }

        .role-admin { background: linear-gradient(135deg, #667eea, #764ba2); }
        .role-hr { background: linear-gradient(135deg, #56ab2f, #a8e6cf); }
        .role-manager { background: linear-gradient(135deg, #ff9a56, #ff6b95); }
        .role-employee { background: linear-gradient(135deg, #4facfe, #00f2fe); }

        .header-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
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
        }

        .btn-secondary:hover {
            background: #e2e8f0;
            text-decoration: none;
            color: #333;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        .info-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 30px;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .card-title {
            font-size: 20px;
            font-weight: 600;
            color: #333;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-title i {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            width: 35px;
            height: 35px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        .info-group {
            margin-bottom: 20px;
        }

        .info-label {
            font-weight: 500;
            color: #555;
            font-size: 14px;
            margin-bottom: 5px;
        }

        .info-value {
            color: #333;
            font-size: 16px;
            word-break: break-word;
        }

        .info-value.empty {
            color: #999;
            font-style: italic;
        }

        /* Leave Statistics */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-item {
            text-align: center;
            padding: 20px;
            background: #f8fafc;
            border-radius: 12px;
            border: 2px solid #e2e8f0;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #666;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Leave Requests Table */
        .leave-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .leave-table th {
            background: #f8fafc;
            color: #333;
            font-weight: 600;
            padding: 12px 10px;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
            font-size: 14px;
        }

        .leave-table td {
            padding: 12px 10px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
        }

        .leave-table tbody tr:hover {
            background-color: #f8fafc;
        }

        .leave-type-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            color: white;
            display: inline-block;
        }

        .leave-status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            text-transform: capitalize;
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

        .no-records {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .no-records i {
            font-size: 48px;
            color: #ddd;
            margin-bottom: 15px;
        }

        /* Full Width Card */
        .full-width-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 30px;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
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
                padding: 20px;
            }

            .employee-header {
                flex-direction: column;
                text-align: center;
                gap: 20px;
            }

            .employee-name {
                font-size: 24px;
            }

            .employee-meta {
                justify-content: center;
            }

            .content-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .info-card,
            .full-width-card {
                padding: 20px;
            }

            .leave-table {
                font-size: 12px;
            }

            .leave-table th,
            .leave-table td {
                padding: 8px 6px;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .employee-meta {
                flex-direction: column;
                gap: 10px;
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
                    Reports
                </a>
                <a href="../auth/logout">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </a>
            </nav>
        </header>

        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="../admin/index">Dashboard</a>
            <span>/</span>
            <a href="employee_management">Employee Management</a>
            <span>/</span>
            <span><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></span>
        </div>

        <!-- Employee Header -->
        <div class="page-header">
            <div class="employee-header">
                <div class="employee-avatar-large">
                    <?php if ($employee['profile_image']): ?>
                        <img src="../<?php echo htmlspecialchars($employee['profile_image']); ?>" alt="Profile">
                    <?php else: ?>
                        <?php echo strtoupper(substr($employee['first_name'], 0, 1) . substr($employee['last_name'], 0, 1)); ?>
                    <?php endif; ?>
                </div>
                
                <div class="employee-title">
                    <h1 class="employee-name"><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></h1>
                    <div class="employee-subtitle">
                        <?php if ($employee['job_title']): ?>
                            <?php echo htmlspecialchars($employee['job_title']); ?>
                            <?php if ($employee['department_name']): ?>
                                • <?php echo htmlspecialchars($employee['department_name']); ?>
                            <?php endif; ?>
                        <?php elseif ($employee['department_name']): ?>
                            <?php echo htmlspecialchars($employee['department_name']); ?>
                        <?php endif; ?>
                    </div>
                    
                    <div class="employee-meta">
                        <span class="status-badge status-<?php echo $employee['status']; ?>">
                            <?php echo ucfirst($employee['status']); ?>
                        </span>
                        <span class="role-badge role-<?php echo strtolower($employee['role_name']); ?>">
                            <?php echo htmlspecialchars($employee['role_name']); ?>
                        </span>
                        <span style="color: #666; font-size: 14px;">
                            <i class="fas fa-id-card"></i> <?php echo htmlspecialchars($employee['employee_id']); ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="header-actions">
                <a href="edit_employee?id=<?php echo $employee['id']; ?>" class="btn-primary">
                    <i class="fas fa-edit"></i>
                    Edit Employee
                </a>
                <a href="employee_management" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    Back to List
                </a>
            </div>
        </div>

        <!-- Employee Information Grid -->
        <div class="content-grid">
            <!-- Personal Information -->
            <div class="info-card">
                <div class="card-title">
                    <i class="fas fa-user"></i>
                    Personal Information
                </div>

                <div class="info-group">
                    <div class="info-label">Full Name</div>
                    <div class="info-value"><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></div>
                </div>

                <div class="info-group">
                    <div class="info-label">Email Address</div>
                    <div class="info-value">
                        <a href="mailto:<?php echo htmlspecialchars($employee['email']); ?>" style="color: #667eea; text-decoration: none;">
                            <?php echo htmlspecialchars($employee['email']); ?>
                        </a>
                    </div>
                </div>

                <div class="info-group">
                    <div class="info-label">Phone Number</div>
                    <div class="info-value <?php echo empty($employee['phone']) ? 'empty' : ''; ?>">
                        <?php echo $employee['phone'] ? htmlspecialchars($employee['phone']) : 'Not provided'; ?>
                    </div>
                </div>

                <div class="info-group">
                    <div class="info-label">Date of Birth</div>
                    <div class="info-value <?php echo empty($employee['date_of_birth']) ? 'empty' : ''; ?>">
                        <?php echo $employee['date_of_birth'] ? date('F j, Y', strtotime($employee['date_of_birth'])) : 'Not provided'; ?>
                    </div>
                </div>

                <div class="info-group">
                    <div class="info-label">Address</div>
                    <div class="info-value <?php echo empty($employee['address']) ? 'empty' : ''; ?>">
                        <?php echo $employee['address'] ? htmlspecialchars($employee['address']) : 'Not provided'; ?>
                    </div>
                </div>
            </div>

            <!-- Employment Information -->
            <div class="info-card">
                <div class="card-title">
                    <i class="fas fa-briefcase"></i>
                    Employment Information
                </div>

                <div class="info-group">
                    <div class="info-label">Employee ID</div>
                    <div class="info-value"><?php echo htmlspecialchars($employee['employee_id']); ?></div>
                </div>

                <div class="info-group">
                    <div class="info-label">Job Title</div>
                    <div class="info-value <?php echo empty($employee['job_title']) ? 'empty' : ''; ?>">
                        <?php echo $employee['job_title'] ? htmlspecialchars($employee['job_title']) : 'Not assigned'; ?>
                    </div>
                </div>

                <div class="info-group">
                    <div class="info-label">Department</div>
                    <div class="info-value <?php echo empty($employee['department_name']) ? 'empty' : ''; ?>">
                        <?php echo $employee['department_name'] ? htmlspecialchars($employee['department_name']) : 'Not assigned'; ?>
                    </div>
                </div>

                <div class="info-group">
                    <div class="info-label">Role</div>
                    <div class="info-value"><?php echo htmlspecialchars($employee['role_name']); ?></div>
                </div>

                <div class="info-group">
                    <div class="info-label">Manager</div>
                    <div class="info-value <?php echo empty($employee['manager_name']) ? 'empty' : ''; ?>">
                        <?php if ($employee['manager_name']): ?>
                            <?php echo htmlspecialchars($employee['manager_name']); ?>
                            <?php if ($employee['manager_email']): ?>
                                <br>
                                <small style="color: #666;">
                                    <a href="mailto:<?php echo htmlspecialchars($employee['manager_email']); ?>" style="color: #667eea; text-decoration: none;">
                                        <?php echo htmlspecialchars($employee['manager_email']); ?>
                                    </a>
                                </small>
                            <?php endif; ?>
                        <?php else: ?>
                            No manager assigned
                        <?php endif; ?>
                    </div>
                </div>

                <div class="info-group">
                    <div class="info-label">Hire Date</div>
                    <div class="info-value"><?php echo date('F j, Y', strtotime($employee['hire_date'])); ?></div>
                </div>

                <?php if ($employee['salary']): ?>
                <div class="info-group">
                    <div class="info-label">Salary</div>
                    <div class="info-value">SAR <?php echo number_format($employee['salary'], 2); ?></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Emergency Contact -->
            <div class="info-card">
                <div class="card-title">
                    <i class="fas fa-phone"></i>
                    Emergency Contact
                </div>

                <div class="info-group">
                    <div class="info-label">Contact Name</div>
                    <div class="info-value <?php echo empty($employee['emergency_contact_name']) ? 'empty' : ''; ?>">
                        <?php echo $employee['emergency_contact_name'] ? htmlspecialchars($employee['emergency_contact_name']) : 'Not provided'; ?>
                    </div>
                </div>

                <div class="info-group">
                    <div class="info-label">Contact Phone</div>
                    <div class="info-value <?php echo empty($employee['emergency_contact_phone']) ? 'empty' : ''; ?>">
                        <?php echo $employee['emergency_contact_phone'] ? htmlspecialchars($employee['emergency_contact_phone']) : 'Not provided'; ?>
                    </div>
                </div>

                <div class="info-group">
                    <div class="info-label">Relationship</div>
                    <div class="info-value <?php echo empty($employee['emergency_contact_relationship']) ? 'empty' : ''; ?>">
                        <?php echo $employee['emergency_contact_relationship'] ? htmlspecialchars($employee['emergency_contact_relationship']) : 'Not provided'; ?>
                    </div>
                </div>
            </div>

            <!-- Account Information -->
            <div class="info-card">
                <div class="card-title">
                    <i class="fas fa-cog"></i>
                    Account Information
                </div>

                <div class="info-group">
                    <div class="info-label">Account Status</div>
                    <div class="info-value">
                        <span class="status-badge status-<?php echo $employee['status']; ?>">
                            <?php echo ucfirst($employee['status']); ?>
                        </span>
                    </div>
                </div>

                <div class="info-group">
                    <div class="info-label">Last Login</div>
                    <div class="info-value <?php echo empty($employee['last_login']) ? 'empty' : ''; ?>">
                        <?php echo $employee['last_login'] ? date('F j, Y \a\t g:i A', strtotime($employee['last_login'])) : 'Never logged in'; ?>
                    </div>
                </div>

                <div class="info-group">
                    <div class="info-label">Account Created</div>
                    <div class="info-value"><?php echo date('F j, Y \a\t g:i A', strtotime($employee['created_at'])); ?></div>
                </div>

                <?php if ($employee['job_description']): ?>
                <div class="info-group">
                    <div class="info-label">Job Description</div>
                    <div class="info-value"><?php echo nl2br(htmlspecialchars($employee['job_description'])); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Leave History -->
        <div class="full-width-card">
            <div class="card-title">
                <i class="fas fa-calendar-alt"></i>
                Leave History & Statistics
            </div>

            <!-- Leave Statistics -->
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-value"><?php echo $leave_stats['total_requests']; ?></div>
                    <div class="stat-label">Total Requests</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $leave_stats['approved_days']; ?></div>
                    <div class="stat-label">Approved Days</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $leave_stats['pending_requests']; ?></div>
                    <div class="stat-label">Pending Requests</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $leave_stats['rejected_requests']; ?></div>
                    <div class="stat-label">Rejected Requests</div>
                </div>
            </div>

            <!-- Recent Leave Requests -->
            <?php if ($leave_requests->num_rows > 0): ?>
                <div style="overflow-x: auto;">
                    <table class="leave-table">
                        <thead>
                            <tr>
                                <th>Leave Type</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Days</th>
                                <th>Status</th>
                                <th>Reason</th>
                                <th>Applied On</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($leave = $leave_requests->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <span class="leave-type-badge" style="background-color: <?php echo $leave['leave_type_color'] ?: '#667eea'; ?>;">
                                            <?php echo htmlspecialchars($leave['leave_type_name'] ?: 'Unknown'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($leave['start_date'])); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($leave['end_date'])); ?></td>
                                    <td>
                                        <strong><?php echo $leave['total_days']; ?></strong>
                                        <?php echo $leave['total_days'] == 1 ? 'day' : 'days'; ?>
                                    </td>
                                    <td>
                                        <span class="leave-status-badge status-<?php echo $leave['status']; ?>">
                                            <?php echo ucfirst($leave['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" 
                                             title="<?php echo htmlspecialchars($leave['reason']); ?>">
                                            <?php echo htmlspecialchars($leave['reason']); ?>
                                        </div>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($leave['created_at'])); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                
                <div style="text-align: center; margin-top: 20px;">
                    <a href="../hr/employee_leave_history?id=<?php echo $employee['id']; ?>" class="btn-secondary">
                        <i class="fas fa-history"></i>
                        View Full Leave History
                    </a>
                </div>
            <?php else: ?>
                <div class="no-records">
                    <i class="fas fa-calendar-times"></i>
                    <h3>No Leave Requests</h3>
                    <p>This employee hasn't submitted any leave requests yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Add smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelector(this.getAttribute('href')).scrollIntoView({
                    behavior: 'smooth'
                });
            });
        });

        // Add tooltip functionality for truncated text
        document.querySelectorAll('[title]').forEach(element => {
            element.addEventListener('mouseenter', function() {
                const tooltip = document.createElement('div');
                tooltip.className = 'tooltip';
                tooltip.textContent = this.getAttribute('title');
                tooltip.style.cssText = `
                    position: absolute;
                    background: #333;
                    color: white;
                    padding: 8px 12px;
                    border-radius: 4px;
                    font-size: 12px;
                    z-index: 1000;
                    max-width: 300px;
                    word-wrap: break-word;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
                `;
                document.body.appendChild(tooltip);
                
                const rect = this.getBoundingClientRect();
                tooltip.style.left = rect.left + 'px';
                tooltip.style.top = (rect.top - tooltip.offsetHeight - 5) + 'px';
                
                this.tooltipElement = tooltip;
            });
            
            element.addEventListener('mouseleave', function() {
                if (this.tooltipElement) {
                    document.body.removeChild(this.tooltipElement);
                    this.tooltipElement = null;
                }
            });
        });

        // Print functionality
        function printEmployee() {
            window.print();
        }

        // Add print styles
        const printStyles = document.createElement('style');
        printStyles.textContent = `
            @media print {
                body {
                    background: white !important;
                    padding: 0 !important;
                }
                .header, .breadcrumb, .header-actions, .btn-primary, .btn-secondary {
                    display: none !important;
                }
                .page-header, .info-card, .full-width-card {
                    background: white !important;
                    box-shadow: none !important;
                    border: 1px solid #ddd !important;
                }
                .content-grid {
                    grid-template-columns: 1fr !important;
                }
            }
        `;
        document.head.appendChild(printStyles);

        // Add keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+E or Cmd+E to edit
            if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
                e.preventDefault();
                window.location.href = 'edit_employee?id=<?php echo $employee['id']; ?>';
            }
            
            // Ctrl+P or Cmd+P to print (default browser behavior)
            
            // Escape to go back
            if (e.key === 'Escape') {
                window.location.href = 'employee_management';
            }
        });

        // Add loading animation for actions
        document.querySelectorAll('.btn-primary, .btn-secondary').forEach(button => {
            button.addEventListener('click', function(e) {
                if (this.href && !this.href.includes('#')) {
                    const originalHTML = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
                    this.style.pointerEvents = 'none';
                    
                    // Reset after 3 seconds if page doesn't change
                    setTimeout(() => {
                        this.innerHTML = originalHTML;
                        this.style.pointerEvents = 'auto';
                    }, 3000);
                }
            });
        });

        // Responsive table handling
        function handleResponsiveTable() {
            const table = document.querySelector('.leave-table');
            const container = table?.parentElement;
            
            if (table && container) {
                if (window.innerWidth <= 768) {
                    container.style.overflowX = 'auto';
                    table.style.minWidth = '700px';
                } else {
                    container.style.overflowX = 'visible';
                    table.style.minWidth = 'auto';
                }
            }
        }

        // Initialize responsive table
        handleResponsiveTable();
        window.addEventListener('resize', handleResponsiveTable);

        // Add animation to cards on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Observe all cards
        document.querySelectorAll('.info-card, .full-width-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            observer.observe(card);
        });
    </script>
</body>
</html>