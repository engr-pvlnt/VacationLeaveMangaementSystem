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

if ($request_id <= 0) {
    $_SESSION['error_message'] = "Invalid request ID.";
    header('Location: pending_requests');
    exit();
}

// Verify that the request belongs to the current user and is pending
$verify_query = "SELECT id, start_date, end_date FROM leave_requests 
                 WHERE id = ? AND user_id = ? AND status = 'pending'";
$stmt = $conn->prepare($verify_query);
$stmt->bind_param("ii", $request_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error_message'] = "Request not found or cannot be cancelled.";
    header('Location: pending_requests');
    exit();
}

$request = $result->fetch_assoc();

// Handle POST request for confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['confirm_cancel'])) {
        // Update the request status to 'cancelled'
        $cancel_query = "UPDATE leave_requests SET 
                        status = 'cancelled', 
                        updated_at = NOW() 
                        WHERE id = ? AND user_id = ? AND status = 'pending'";
        
        $stmt = $conn->prepare($cancel_query);
        $stmt->bind_param("ii", $request_id, $user_id);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $_SESSION['success_message'] = "Leave request has been cancelled successfully.";
            header('Location: pending_requests');
            exit();
        } else {
            $_SESSION['error_message'] = "Failed to cancel the request. Please try again.";
            header('Location: pending_requests');
            exit();
        }
    } else {
        // User chose not to cancel
        header('Location: pending_requests');
        exit();
    }
}

// If it's a GET request, show confirmation page
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>VLMS - Cancel Request</title>
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
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .container {
            max-width: 600px;
            width: 100%;
        }

        /* Confirmation Modal */
        .confirmation-modal {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 20px;
            padding: 40px;
            backdrop-filter: blur(10px);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .confirmation-modal::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }

        .modal-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
            border: 3px solid #fecaca;
        }

        .modal-icon i {
            font-size: 36px;
            color: #dc2626;
        }

        .modal-title {
            font-size: 24px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 15px;
        }

        .modal-subtitle {
            color: #6b7280;
            font-size: 16px;
            margin-bottom: 30px;
            line-height: 1.5;
        }

        /* Request Details */
        .request-details {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 25px;
            margin: 30px 0;
            text-align: left;
        }

        .request-details h3 {
            color: #374155;
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .detail-label {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-value {
            font-size: 14px;
            color: #1e293b;
            font-weight: 500;
        }

        /* Warning Box */
        .warning-box {
            background: #fffbeb;
            border: 2px solid #fed7aa;
            border-radius: 12px;
            padding: 20px;
            margin: 25px 0;
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }

        .warning-box i {
            color: #d97706;
            font-size: 20px;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .warning-content {
            color: #92400e;
            font-size: 14px;
            line-height: 1.5;
        }

        .warning-content strong {
            color: #78350f;
        }

        /* Action Buttons */
        .modal-actions {
            display: flex;
            gap: 15px;
            margin-top: 35px;
            justify-content: center;
        }

        .btn {
            padding: 12px 28px;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            text-decoration: none;
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
            color: white;
            text-decoration: none;
        }

        .btn-secondary {
            background: #f8fafc;
            color: #475569;
            border: 2px solid #e2e8f0;
        }

        .btn-secondary:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
            transform: translateY(-1px);
            color: #334155;
            text-decoration: none;
        }

        /* Header Link */
        .header-link {
            position: absolute;
            top: 20px;
            left: 20px;
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            transition: background-color 0.3s ease;
            font-weight: 500;
        }

        .header-link:hover {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            text-decoration: none;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }

            .confirmation-modal {
                padding: 25px;
            }

            .modal-actions {
                flex-direction: column;
            }

            .detail-grid {
                grid-template-columns: 1fr;
            }

            .header-link {
                position: relative;
                top: auto;
                left: auto;
                margin-bottom: 20px;
                display: inline-flex;
            }
        }

        /* Animation */
        .confirmation-modal {
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
    </style>
</head>

<body>
    <a href="pending_requests" class="header-link">
        <i class="fas fa-arrow-left"></i>
        Back to Pending Requests
    </a>

    <div class="container">
        <div class="confirmation-modal">
            <div class="modal-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>

            <h1 class="modal-title">Cancel Leave Request?</h1>
            <p class="modal-subtitle">
                Are you sure you want to cancel this leave request? This action cannot be undone.
            </p>

            <div class="request-details">
                <h3>
                    <i class="fas fa-calendar-alt"></i>
                    Request Details
                </h3>
                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-label">Status</span>
                        <span class="detail-value">Pending Approval</span>
                    </div>
                </div>
            </div>

            <div class="warning-box">
                <i class="fas fa-info-circle"></i>
                <div class="warning-content">
                    <strong>Important:</strong> Once you cancel this request, you'll need to submit a new one if you still need time off for these dates. Make sure you really want to proceed.
                </div>
            </div>

            <form method="POST" action="">
                <div class="modal-actions">
                    <button type="submit" name="confirm_cancel" class="btn btn-danger">
                        <i class="fas fa-times-circle"></i>
                        Yes, Cancel Request
                    </button>
                    <a href="pending_requests" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i>
                        No, Keep Request
                    </a>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Add confirmation on form submit for extra safety
        document.querySelector('form').addEventListener('submit', function(e) {
            if (!confirm('Are you absolutely sure you want to cancel this leave request?')) {
                e.preventDefault();
            }
        });

        // Auto-focus on the cancel button for keyboard users
        document.addEventListener('DOMContentLoaded', function() {
            const cancelBtn = document.querySelector('button[name="confirm_cancel"]');
            if (cancelBtn) {
                cancelBtn.focus();
            }
        });
    </script>
</body>

</html>