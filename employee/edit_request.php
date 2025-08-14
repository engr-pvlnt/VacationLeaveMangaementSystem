<?php
session_start();
include '../config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login');
    exit();
}

$user_id = $_SESSION['user_id'];
$request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch the leave request
$request_query = "SELECT lr.*, lt.name as leave_type_name 
                  FROM leave_requests lr 
                  JOIN leave_types lt ON lr.leave_type_id = lt.id 
                  WHERE lr.id = ? AND lr.user_id = ? AND lr.status = 'pending'";
$stmt = $conn->prepare($request_query);
$stmt->bind_param("ii", $request_id, $user_id);
$stmt->execute();
$request_result = $stmt->get_result();

if ($request_result->num_rows === 0) {
    header('Location: pending_requests');
    exit();
}

$request = $request_result->fetch_assoc();

// Fetch all leave types
$leave_types_query = "SELECT * FROM leave_types ORDER BY name";
$leave_types_result = $conn->query($leave_types_query);

// Handle form submission
if ($_POST) {
    $leave_type_id = (int)$_POST['leave_type_id'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $reason = trim($_POST['reason']);
    
    $errors = [];
    
    // Validation
    if ($leave_type_id <= 0) {
        $errors[] = "Please select a leave type.";
    }
    
    if (empty($start_date)) {
        $errors[] = "Please select a start date.";
    }
    
    if (empty($end_date)) {
        $errors[] = "Please select an end date.";
    }
    
    if ($start_date && $end_date && strtotime($start_date) > strtotime($end_date)) {
        $errors[] = "End date must be after start date.";
    }
    
    if ($start_date && strtotime($start_date) < strtotime(date('Y-m-d'))) {
        $errors[] = "Start date cannot be in the past.";
    }
    
    if (empty($errors)) {
        // Update the leave request
        $update_query = "UPDATE leave_requests SET 
                        leave_type_id = ?, 
                        start_date = ?, 
                        end_date = ?, 
                        reason = ?,
                        updated_at = NOW()
                        WHERE id = ? AND user_id = ? AND status = 'pending'";
        
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("isssii", $leave_type_id, $start_date, $end_date, $reason, $request_id, $user_id);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Leave request updated successfully!";
            header('Location: pending_requests');
            exit();
        } else {
            $errors[] = "Error updating request. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>VLMS - Edit Request</title>
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
            max-width: 800px;
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
        }

        .page-title {
            font-size: 28px;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .page-subtitle {
            color: #666;
            font-size: 16px;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            color: #666;
            font-size: 14px;
        }

        .breadcrumb a {
            color: #667eea;
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        /* Form Styles */
        .form-section {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 30px;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: #fafbfc;
        }

        .form-control:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        select.form-control {
            cursor: pointer;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 120px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .btn {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
            color: white;
            text-decoration: none;
        }

        .btn-secondary {
            background: #6b7280;
        }

        .btn-secondary:hover {
            background: #4b5563;
            box-shadow: 0 4px 15px rgba(107, 114, 128, 0.4);
        }

        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            padding-top: 30px;
            border-top: 2px solid #f3f4f6;
        }

        /* Alert Styles */
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-danger {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .alert ul {
            margin: 0;
            padding-left: 20px;
        }

        .alert ul li {
            margin-bottom: 5px;
        }

        /* Current Request Info */
        .current-info {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
        }

        .current-info h3 {
            color: #334155;
            font-size: 16px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #64748b;
            font-size: 14px;
        }

        .info-item i {
            color: #667eea;
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

            .form-row {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .info-grid {
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
                <a href="index">
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
        <section class="page-header">
            <div class="breadcrumb">
                <a href="index">Dashboard</a>
                <i class="fas fa-chevron-right"></i>
                <a href="pending_requests">Pending Requests</a>
                <i class="fas fa-chevron-right"></i>
                <span>Edit Request</span>
            </div>
            <h1 class="page-title">
                <i class="fas fa-edit"></i>
                Edit Leave Request
            </h1>
            <p class="page-subtitle">Update your leave request details</p>
        </section>

        <!-- Current Request Info -->
        <div class="current-info">
            <h3>
                <i class="fas fa-info-circle"></i>
                Current Request Details
            </h3>
            <div class="info-grid">
                <div class="info-item">
                    <i class="fas fa-tag"></i>
                    <strong>Type:</strong> <?php echo htmlspecialchars($request['leave_type_name']); ?>
                </div>
                <div class="info-item">
                    <i class="fas fa-calendar-alt"></i>
                    <strong>Start:</strong> <?php echo date('M d, Y', strtotime($request['start_date'])); ?>
                </div>
                <div class="info-item">
                    <i class="fas fa-calendar-alt"></i>
                    <strong>End:</strong> <?php echo date('M d, Y', strtotime($request['end_date'])); ?>
                </div>
                <div class="info-item">
                    <i class="fas fa-clock"></i>
                    <strong>Submitted:</strong> <?php echo date('M d, Y', strtotime($request['created_at'])); ?>
                </div>
            </div>
        </div>

        <!-- Form Section -->
        <section class="form-section">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <strong>Please fix the following errors:</strong>
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label for="leave_type_id">
                        <i class="fas fa-tag"></i>
                        Leave Type *
                    </label>
                    <select name="leave_type_id" id="leave_type_id" class="form-control" required>
                        <option value="">Select leave type...</option>
                        <?php while ($type = $leave_types_result->fetch_assoc()): ?>
                            <option value="<?php echo $type['id']; ?>" 
                                    <?php echo ($type['id'] == $request['leave_type_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="start_date">
                            <i class="fas fa-calendar-alt"></i>
                            Start Date *
                        </label>
                        <input type="date" 
                               name="start_date" 
                               id="start_date" 
                               class="form-control" 
                               value="<?php echo $request['start_date']; ?>"
                               min="<?php echo date('Y-m-d'); ?>" 
                               required>
                    </div>

                    <div class="form-group">
                        <label for="end_date">
                            <i class="fas fa-calendar-alt"></i>
                            End Date *
                        </label>
                        <input type="date" 
                               name="end_date" 
                               id="end_date" 
                               class="form-control" 
                               value="<?php echo $request['end_date']; ?>"
                               min="<?php echo date('Y-m-d'); ?>" 
                               required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="reason">
                        <i class="fas fa-comment"></i>
                        Reason (Optional)
                    </label>
                    <textarea name="reason" 
                              id="reason" 
                              class="form-control" 
                              placeholder="Please provide a reason for your leave request..."><?php echo htmlspecialchars($request['reason']); ?></textarea>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn">
                        <i class="fas fa-save"></i>
                        Update Request
                    </button>
                    <a href="pending_requests" class="btn btn-secondary">
                        <i class="fas fa-times"></i>
                        Cancel
                    </a>
                </div>
            </form>
        </section>
    </div>

    <script>
        // Auto-update end date minimum when start date changes
        document.getElementById('start_date').addEventListener('change', function() {
            const startDate = this.value;
            const endDateInput = document.getElementById('end_date');
            endDateInput.min = startDate;
            
            // If end date is before start date, reset it
            if (endDateInput.value && endDateInput.value < startDate) {
                endDateInput.value = startDate;
            }
        });
    </script>
</body>

</html>