<?php
session_start();
include '../config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login');
    exit();
}

$user_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $leave_type_id = $_POST['leave_type_id'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $start_work_date = $_POST['start_work_date'];
    $reliever_id = !empty($_POST['reliever_id']) ? $_POST['reliever_id'] : NULL;
    $reason = $_POST['reason'];
    $comments = !empty($_POST['comments']) ? $_POST['comments'] : NULL;
    $status = 'Pending';
    $applied_date = date('Y-m-d H:i:s');

    // Handle file upload
    $attachment = NULL;
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/leave_attachments/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $file_extension = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
        $filename = 'leave_' . $user_id . '_' . time() . '.' . $file_extension;
        $upload_path = $upload_dir . $filename;

        if (move_uploaded_file($_FILES['attachment']['tmp_name'], $upload_path)) {
            $attachment = 'uploads/leave_attachments/' . $filename;
        }
    }

    // Validate dates
    if ($start_date > $end_date) {
        $error_message = 'Start date cannot be after end date.';
    } elseif ($start_work_date < $end_date) {
        $error_message = 'Start work date must be after or equal to end date.';
    } else {
        // Calculate total days
        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        $total_days = $start->diff($end)->days + 1;

        // First, let's check if the table structure matches our query
        $check_table = $conn->query("DESCRIBE leave_requests");
        if (!$check_table) {
            $error_message = 'Database table structure error: ' . $conn->error;
        } else {
            // Prepare the INSERT statement with proper error handling
            $sql = "INSERT INTO leave_requests (
                        user_id, 
                        leave_type_id, 
                        start_date, 
                        end_date, 
                        start_work_date, 
                        reliever_id, 
                        total_days, 
                        reason, 
                        status, 
                        applied_date, 
                        comments, 
                        attachment, 
                        created_at, 
                        updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

            $stmt = $conn->prepare($sql);

            if (!$stmt) {
                $error_message = 'SQL prepare error: ' . $conn->error;
            } else {
                // Bind parameters - note the corrected type string
                $stmt->bind_param(
                    "iisssissssss",
                    $user_id,
                    $leave_type_id,
                    $start_date,
                    $end_date,
                    $start_work_date,
                    $reliever_id,
                    $total_days,
                    $reason,
                    $status,
                    $applied_date,
                    $comments,
                    $attachment
                );

                if ($stmt->execute()) {
                    $success_message = 'Leave request submitted successfully!';
                    // Clear form data after successful submission
                    $_POST = array();
                } else {
                    $error_message = 'Error submitting leave request: ' . $stmt->error;
                }
                $stmt->close();
            }
        }
    }
}

// Fetch current user data with error handling
$user_query = "SELECT * FROM users WHERE id = ?";
$user_stmt = $conn->prepare($user_query);
if (!$user_stmt) {
    die("Error preparing user query: " . $conn->error);
}
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user = $user_result->fetch_assoc();
$user_stmt->close();

if (!$user) {
    die("User not found");
}

// Fetch role description with prepared statement
$role_query = "SELECT r.name FROM roles r 
               JOIN users u ON u.role_id = r.id 
               WHERE u.id = ?";
$role_stmt = $conn->prepare($role_query);
if (!$role_stmt) {
    die("Error preparing role query: " . $conn->error);
}
$role_stmt->bind_param("i", $user_id);
$role_stmt->execute();
$role_result = $role_stmt->get_result();
$role = $role_result->fetch_assoc();
$role_stmt->close();

// Fetch user's department name
$department_id = $user['department_id'];
$department_query = "SELECT name FROM departments WHERE id = ?";
$dept_stmt = $conn->prepare($department_query);
if (!$dept_stmt) {
    die("Error preparing department query: " . $conn->error);
}
$dept_stmt->bind_param("i", $department_id);
$dept_stmt->execute();
$dept_result = $dept_stmt->get_result();
$department_row = $dept_result->fetch_assoc();
$department = $department_row ? $department_row['name'] : 'Unknown';
$dept_stmt->close();

// Fetch user's job role title
$job_role_id = $user['job_role_id'];
$job_role_query = "SELECT title FROM job_roles WHERE id = ?";
$job_stmt = $conn->prepare($job_role_query);
if (!$job_stmt) {
    die("Error preparing job role query: " . $conn->error);
}
$job_stmt->bind_param("i", $job_role_id);
$job_stmt->execute();
$job_result = $job_stmt->get_result();
$job_role_row = $job_result->fetch_assoc();
$job_role = $job_role_row ? $job_role_row['title'] : 'Unknown';
$job_stmt->close();

// Fetch leave types from database
$leave_types_result = $conn->query("SELECT id, name FROM leave_types ORDER BY name");
$leave_types = [];
if ($leave_types_result) {
    while ($row = $leave_types_result->fetch_assoc()) {
        $leave_types[] = $row;
    }
}

// Fetch potential relievers (other employees in same department)
$reliever_query = "SELECT u.id, u.first_name, u.last_name, jr.title 
                   FROM users u 
                   JOIN job_roles jr ON u.job_role_id = jr.id 
                   WHERE u.department_id = ? AND u.id != ? AND u.status = 'Active'
                   ORDER BY u.first_name, u.last_name";
$reliever_stmt = $conn->prepare($reliever_query);
if (!$reliever_stmt) {
    die("Error preparing reliever query: " . $conn->error);
}
$reliever_stmt->bind_param("ii", $department_id, $user_id);
$reliever_stmt->execute();
$reliever_result = $reliever_stmt->get_result();
$relievers = [];
while ($row = $reliever_result->fetch_assoc()) {
    $relievers[] = $row;
}
$reliever_stmt->close();


$current_year = date('Y');

// Fetch leave balance data from leave_balances table
$balance_query = "SELECT allocated_days, used_days, remaining_days, carried_forward 
                  FROM leave_balances 
                  WHERE user_id = ? AND year = ? 
                  ORDER BY created_at DESC LIMIT 1";
$balance_stmt = $conn->prepare($balance_query);
if (!$balance_stmt) {
    die("Error preparing balance query: " . $conn->error);
}
$balance_stmt->bind_param("ii", $user_id, $current_year);
$balance_stmt->execute();
$balance_result = $balance_stmt->get_result();
$balance_data = $balance_result->fetch_assoc();
$balance_stmt->close();

// Set default values if no balance record exists
if ($balance_data) {
    $annual_leave_days = $balance_data['allocated_days'];
    $days_used = $balance_data['used_days'];
    $available_days = $balance_data['remaining_days'];
    $carried_forward = $balance_data['carried_forward'];
} //else {
// Fallback if no balance record exists
//$annual_leave_days = 42; // Default value
//$days_used = 0;
//$available_days = $annual_leave_days;
//$carried_forward = 0;
//}

// Pending Requests Count
$pending_query = "SELECT COUNT(*) as pending_count 
                 FROM leave_requests 
                 WHERE user_id = ? AND status = 'Pending'";
$pending_stmt = $conn->prepare($pending_query);
$pending_stmt->bind_param("i", $user_id);
$pending_stmt->execute();
$pending_result = $pending_stmt->get_result();
$pending_data = $pending_result->fetch_assoc();
$pending_requests = $pending_data['pending_count'];
$pending_stmt->close();

// Approved Requests This Year Count
$approved_count_query = "SELECT COUNT(*) as approved_count 
                        FROM leave_requests 
                        WHERE user_id = ? AND status = 'Approved' 
                        AND YEAR(start_date) = ?";
$approved_count_stmt = $conn->prepare($approved_count_query);
$approved_count_stmt->bind_param("ii", $user_id, $current_year);
$approved_count_stmt->execute();
$approved_count_result = $approved_count_stmt->get_result();
$approved_count_data = $approved_count_result->fetch_assoc();
$approved_this_year = $approved_count_data['approved_count'];
$approved_count_stmt->close();

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>VLMS - Leave Request</title>
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

        .header-right {
            text-align: right;
        }

        .header-description {
            margin-top: 8px;
            font-size: 14px;
            color: #666;
            font-style: italic;
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

        /* Form Container */
        .form-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 40px;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            animation: fadeInUp 0.6s ease-out;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
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

        /* Form Styles */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-bottom: 30px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        label {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        label i {
            color: #667eea;
        }

        input[type="text"],
        input[type="date"],
        select,
        textarea {
            padding: 15px;
            border: 2px solid #e1e5e9;
            border-radius: 12px;
            font-size: 16px;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s ease;
            background: white;
        }

        input[type="text"]:focus,
        input[type="date"]:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            transform: translateY(-2px);
        }

        textarea {
            resize: vertical;
            min-height: 120px;
        }

        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
            animation: slideDown 0.5s ease-out;
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

        .alert.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Button Styles */
        .btn {
            padding: 15px 30px;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            justify-content: center;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        .btn.primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .btn.secondary {
            background: #6c757d;
            color: white;
        }

        .btn-group {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 30px;
        }

        /* Duration Display */
        .duration-display {
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            font-weight: 600;
            color: #495057;
        }

        /* File Input Styles */
        input[type="file"] {
            padding: 12px !important;
            border: 2px dashed #e1e5e9 !important;
            background: #f8f9fa !important;
            border-radius: 12px !important;
            transition: all 0.3s ease;
        }

        input[type="file"]:hover {
            border-color: #667eea !important;
            background: #f0f2ff !important;
        }

        input[type="file"]:focus {
            outline: none;
            border-color: #667eea !important;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        /* User Info Panel */
        .user-info-panel {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            border-left: 4px solid #667eea;
        }

        .user-info-panel h4 {
            color: #333;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .user-info-panel p {
            color: #666;
            margin-bottom: 5px;
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

            .form-container {
                padding: 20px;
            }

            .form-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .btn-group {
                flex-direction: column;
            }

            .user-nav {
                flex-direction: row;
                gap: 15px;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .user-nav {
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
                    <i class="fas fa-calendar-plus"></i>
                </div>
                <div class="title-text">
                    <h1>Request Leave</h1>
                    <p class="header-description">Submit a new leave request for approval</p>
                </div>
            </div>
            <a href="my_requests" class="btn-primary">
                <i class="fas fa-list-alt"></i>
                My Requests
            </a>
        </div>


        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon success">
                    <i class="fas fa-calendar-days"></i>
                </div>
                <div class="stat-value"><?php echo $available_days; ?></div>
                <div class="stat-label">Available Days</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon primary">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value"><?php echo $pending_requests; ?></div>
                <div class="stat-label">Pending Requests</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon info">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value"><?php echo $approved_this_year; ?></div>
                <div class="stat-label">Approved This Year</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon warning">
                    <i class="fas fa-calendar-xmark"></i>
                </div>
                <div class="stat-value"><?php echo $days_used; ?></div>
                <div class="stat-label">Days Used</div>
            </div>
            <?php if ($carried_forward > 0): ?>
                <div class="stat-card">
                    <div class="stat-icon info">
                        <i class="fas fa-arrow-right"></i>
                    </div>
                    <div class="stat-value"><?php echo $carried_forward; ?></div>
                    <div class="stat-label">Carried Forward</div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Form Container -->
        <div class="form-container">
            <!-- User Info Panel -->
            <div class="user-info-panel">
                <h4>
                    <i class="fas fa-user-circle"></i>
                    Employee Information
                </h4>
                <p><strong>Name:</strong> <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></p>
                <p><strong>Role:</strong> <?php echo htmlspecialchars($role['name'] ?? 'Unknown'); ?></p>
                <p><strong>Department:</strong> <?php echo htmlspecialchars($department); ?></p>
                <p><strong>Job Position:</strong> <?php echo htmlspecialchars($job_role); ?></p>
            </div>

            <!-- Alert Messages -->
            <?php if ($success_message): ?>
                <div class="alert success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <!-- Leave Request Form -->
            <form method="POST" action="" enctype="multipart/form-data">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="leave_type_id">
                            <i class="fas fa-list"></i>
                            Leave Type *
                        </label>
                        <select name="leave_type_id" id="leave_type_id" required>
                            <option value="">Select leave type</option>
                            <?php foreach ($leave_types as $type): ?>
                                <option value="<?php echo $type['id']; ?>" <?php echo (isset($_POST['leave_type_id']) && $_POST['leave_type_id'] == $type['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="reliever_id">
                            <i class="fas fa-user-friends"></i>
                            Reliever (Optional)
                        </label>
                        <select name="reliever_id" id="reliever_id">
                            <option value="">Select a reliever (optional)</option>
                            <?php foreach ($relievers as $reliever): ?>
                                <option value="<?php echo $reliever['id']; ?>" <?php echo (isset($_POST['reliever_id']) && $_POST['reliever_id'] == $reliever['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($reliever['first_name'] . ' ' . $reliever['last_name'] . ' (' . $reliever['title'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="start_date">
                            <i class="fas fa-calendar-alt"></i>
                            Start Date *
                        </label>
                        <input type="date" name="start_date" id="start_date" required
                            value="<?php echo isset($_POST['start_date']) ? $_POST['start_date'] : ''; ?>"
                            min="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="form-group">
                        <label for="end_date">
                            <i class="fas fa-calendar-alt"></i>
                            End Date *
                        </label>
                        <input type="date" name="end_date" id="end_date" required
                            value="<?php echo isset($_POST['end_date']) ? $_POST['end_date'] : ''; ?>"
                            min="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="form-group">
                        <label for="start_work_date">
                            <i class="fas fa-calendar-check"></i>
                            Work Resume Date *
                        </label>
                        <input type="date" name="start_work_date" id="start_work_date" required
                            value="<?php echo isset($_POST['start_work_date']) ? $_POST['start_work_date'] : ''; ?>"
                            min="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="form-group">
                        <label for="total_days">
                            <i class="fas fa-clock"></i>
                            Total Days
                        </label>
                        <div class="duration-display" id="total_days">
                            Select dates to calculate total days
                        </div>
                    </div>

                    <div class="form-group full-width">
                        <label for="reason">
                            <i class="fas fa-comment"></i>
                            Reason for Leave *
                        </label>
                        <textarea name="reason" id="reason" required
                            placeholder="Please provide a detailed reason for your leave request..."><?php echo isset($_POST['reason']) ? htmlspecialchars($_POST['reason']) : ''; ?></textarea>
                    </div>

                    <div class="form-group full-width">
                        <label for="comments">
                            <i class="fas fa-sticky-note"></i>
                            Additional Comments (Optional)
                        </label>
                        <textarea name="comments" id="comments"
                            placeholder="Any additional information or special requests..."><?php echo isset($_POST['comments']) ? htmlspecialchars($_POST['comments']) : ''; ?></textarea>
                    </div>

                    <div class="form-group full-width">
                        <label for="attachment">
                            <i class="fas fa-paperclip"></i>
                            Attachment (Optional)
                        </label>
                        <input type="file" name="attachment" id="attachment"
                            accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif"
                            style="padding: 10px; border: 2px dashed #e1e5e9; border-radius: 12px; background: #f8f9fa;">
                        <small style="color: #666; font-size: 12px; margin-top: 5px; display: block;">
                            Accepted formats: PDF, DOC, DOCX, JPG, PNG, GIF (Max size: 5MB)
                        </small>
                    </div>
                </div>

                <div class="btn-group">
                    <button type="submit" class="btn primary">
                        <i class="fas fa-paper-plane"></i>
                        Submit Request
                    </button>
                    <a href="index" class="btn secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to Dashboard
                    </a>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Calculate total days when dates change
        function calculateTotalDays() {
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            const startWorkDate = document.getElementById('start_work_date').value;
            const totalDaysElement = document.getElementById('total_days');

            if (startDate && endDate) {
                const start = new Date(startDate);
                const end = new Date(endDate);

                if (end >= start) {
                    const totalDays = Math.ceil((end - start) / (1000 * 60 * 60 * 24)) + 1;
                    totalDaysElement.textContent = totalDays + ' day' + (totalDays !== 1 ? 's' : '');
                    totalDaysElement.style.color = '#28a745';

                    // Auto-set start work date to the day after end date if not set
                    if (!startWorkDate) {
                        const nextDay = new Date(end);
                        nextDay.setDate(nextDay.getDate() + 1);
                        document.getElementById('start_work_date').value = nextDay.toISOString().split('T')[0];
                    }
                } else {
                    totalDaysElement.textContent = 'End date must be after start date';
                    totalDaysElement.style.color = '#dc3545';
                }
            } else {
                totalDaysElement.textContent = 'Select dates to calculate total days';
                totalDaysElement.style.color = '#6c757d';
            }
        }

        // Validate start work date
        function validateStartWorkDate() {
            const endDate = document.getElementById('end_date').value;
            const startWorkDate = document.getElementById('start_work_date').value;

            if (endDate && startWorkDate) {
                const end = new Date(endDate);
                const startWork = new Date(startWorkDate);

                if (startWork < end) {
                    alert('Start work date must be after or equal to the end date of your leave.');
                    document.getElementById('start_work_date').value = '';
                }
            }
        }

        // Add event listeners
        document.getElementById('start_date').addEventListener('change', function() {
            calculateTotalDays();
            // Set minimum date for end date
            document.getElementById('end_date').min = this.value;
        });

        document.getElementById('end_date').addEventListener('change', function() {
            calculateTotalDays();
            // Set minimum date for start work date (day after end date)
            const endDate = new Date(this.value);
            endDate.setDate(endDate.getDate() + 1);
            document.getElementById('start_work_date').min = endDate.toISOString().split('T')[0];
        });

        document.getElementById('start_work_date').addEventListener('change', validateStartWorkDate);

        // File upload validation
        document.getElementById('attachment').addEventListener('change', function() {
            const file = this.files[0];
            const maxSize = 5 * 1024 * 1024; // 5MB
            const allowedTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'image/jpeg', 'image/jpg', 'image/png', 'image/gif'];

            if (file) {
                if (file.size > maxSize) {
                    alert('File size must be less than 5MB');
                    this.value = '';
                    return;
                }

                if (!allowedTypes.includes(file.type)) {
                    alert('Please select a valid file type (PDF, DOC, DOCX, JPG, PNG, GIF)');
                    this.value = '';
                    return;
                }
            }
        });

        // Auto-hide success message after 5 seconds
        const successAlert = document.querySelector('.alert.success');
        if (successAlert) {
            setTimeout(() => {
                successAlert.style.opacity = '0';
                setTimeout(() => {
                    successAlert.style.display = 'none';
                }, 300);
            }, 5000);
        }

        // Form validation before submission
        document.querySelector('form').addEventListener('submit', function(e) {
            const startDate = new Date(document.getElementById('start_date').value);
            const endDate = new Date(document.getElementById('end_date').value);
            const startWorkDate = new Date(document.getElementById('start_work_date').value);

            if (startDate > endDate) {
                e.preventDefault();
                alert('Start date cannot be after end date.');
                return;
            }

            if (startWorkDate < endDate) {
                e.preventDefault();
                alert('Start work date must be after or equal to the end date.');
                return;
            }
        });
    </script>
</body>

</html>