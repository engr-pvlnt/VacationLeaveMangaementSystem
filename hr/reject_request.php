<?php
// hr/reject_request.php
session_start();
include '../config/db.php';

// Check if user is logged in and has HR or Admin role
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login');
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch current user data and role
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

// Get request ID
$request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$request_id) {
    header('Location: all_requests');
    exit();
}

// Fetch request details
$stmt = $conn->prepare("SELECT lr.*, u.first_name, u.last_name, u.employee_id, lt.name as leave_type_name 
                        FROM leave_requests lr 
                        LEFT JOIN users u ON lr.user_id = u.id 
                        LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id 
                        WHERE lr.id = ?");
$stmt->bind_param("i", $request_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    header('Location: all_requests');
    exit();
}

$request = $result->fetch_assoc();

// Check if request is already processed
if ($request['status'] != 'pending') {
    $_SESSION['error'] = "This request has already been processed.";
    header('Location: all_requests');
    exit();
}

$error = '';
$success = '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $rejection_reason = trim($_POST['rejection_reason'] ?? '');
    
    if (empty($rejection_reason)) {
        $error = "Please provide a reason for rejection.";
    } else {
        // Update request status
        $update_stmt = $conn->prepare("UPDATE leave_requests SET 
                                       status = 'rejected', 
                                       rejected_by = ?, 
                                       rejected_at = NOW(), 
                                       rejection_reason = ? 
                                       WHERE id = ?");
        $update_stmt->bind_param("isi", $user_id, $rejection_reason, $request_id);
        
        if ($update_stmt->execute()) {
            $_SESSION['success'] = "Leave request rejected successfully.";
            header('Location: all_requests');
            exit();
        } else {
            $error = "Error rejecting request. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VLMS - Reject Leave Request</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            max-width: 800px;
            margin: 0 auto;
        }

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

        .back-btn {
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 8px;
            transition: background-color 0.3s ease;
        }

        .back-btn:hover {
            background-color: rgba(255, 255, 255, 0.1);
            text-decoration: none;
            color: white;
        }

        .card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 30px;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
        }

        .card-icon {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .card-title {
            font-size: 24px;
            font-weight: 600;
            color: #333;
        }

        .request-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 25px;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .detail-label {
            font-weight: 600;
            color: #666;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-value {
            font-size: 16px;
            color: #333;
        }

        .leave-type-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
            color: white;
            display: inline-block;
            background: linear-gradient(135deg, #667eea, #764ba2);
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
            text-transform: capitalize;
            display: inline-block;
            background: #fef3c7;
            color: #92400e;
        }

        .reason-box {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
        }

        .warning-box {
            background: #fef3c7;
            border: 2px solid #fbbf24;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .warning-icon {
            background: #f59e0b;
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .warning-text {
            color: #92400e;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }

        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            resize: vertical;
            min-height: 120px;
            transition: border-color 0.3s ease;
        }

        .form-group textarea:focus {
            outline: none;
            border-color: #ef4444;
        }

        .form-group textarea.error {
            border-color: #ef4444;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 30px;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }

        .btn-danger:hover {
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

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-error {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }

            .request-details {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .action-buttons {
                flex-direction: column;
            }

            .card {
                padding: 20px;
            }

            .warning-box {
                flex-direction: column;
                text-align: center;
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
            <a href="all_requests" class="back-btn">
                <i class="fas fa-arrow-left"></i>
                Back to Requests
            </a>
        </header>

        <!-- Reject Request Card -->
        <div class="card">
            <div class="card-header">
                <div class="card-icon">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div>
                    <h1 class="card-title">Reject Leave Request</h1>
                    <p style="color: #666; margin-top: 5px;">Review and reject this leave request</p>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- Warning Box -->
            <div class="warning-box">
                <div class="warning-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="warning-text">
                    <strong>Important:</strong> Please provide a clear reason for rejecting this leave request. 
                    The employee will be notified of your decision and the reason provided.
                </div>
            </div>

            <!-- Request Details -->
            <div class="request-details">
                <div class="detail-item">
                    <div class="detail-label">Employee</div>
                    <div class="detail-value"><?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?></div>
                    <div style="font-size: 12px; color: #666;">ID: <?php echo htmlspecialchars($request['employee_id']); ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Leave Type</div>
                    <div class="detail-value">
                        <span class="leave-type-badge"><?php echo htmlspecialchars($request['leave_type_name']); ?></span>
                    </div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Start Date</div>
                    <div class="detail-value"><?php echo date('F d, Y', strtotime($request['start_date'])); ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">End Date</div>
                    <div class="detail-value"><?php echo date('F d, Y', strtotime($request['end_date'])); ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Total Days</div>
                    <div class="detail-value"><?php echo $request['total_days']; ?> days</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Status</div>
                    <div class="detail-value">
                        <span class="status-badge"><?php echo ucfirst($request['status']); ?></span>
                    </div>
                </div>
                <?php if ($request['start_work_date']): ?>
                <div class="detail-item">
                    <div class="detail-label">Resume Work Date</div>
                    <div class="detail-value"><?php echo date('F d, Y', strtotime($request['start_work_date'])); ?></div>
                </div>
                <?php endif; ?>
                <div class="detail-item">
                    <div class="detail-label">Applied Date</div>
                    <div class="detail-value"><?php echo date('F d, Y - H:i', strtotime($request['created_at'])); ?></div>
                </div>
            </div>

            <!-- Reason -->
            <div class="detail-item">
                <div class="detail-label">Reason for Leave</div>
                <div class="reason-box">
                    <?php echo htmlspecialchars($request['reason']); ?>
                </div>
            </div>

            <!-- Rejection Form -->
            <form method="POST">
                <div class="form-group">
                    <label for="rejection_reason">Reason for Rejection <span style="color: #ef4444;">*</span></label>
                    <textarea name="rejection_reason" id="rejection_reason" 
                              placeholder="Please provide a clear and detailed reason for rejecting this leave request..." 
                              required 
                              class="<?php echo $error ? 'error' : ''; ?>"></textarea>
                    <small style="color: #666; font-size: 12px;">
                        This reason will be shared with the employee. Please be professional and constructive.
                    </small>
                </div>

                <div class="action-buttons">
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-times"></i>
                        Reject Request
                    </button>
                    <a href="all_requests" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>