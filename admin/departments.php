<?php
// departments.php
session_start();
include '../config/db.php'; // Assuming this includes MySQLi connection as $conn

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

$message = '';
$message_type = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $name = trim($_POST['name']);
                $description = trim($_POST['description']);
                $manager_id = !empty($_POST['manager_id']) ? $_POST['manager_id'] : NULL;
                $status = $_POST['status'];
                
                if (!empty($name)) {
                    // Check if department name already exists
                    $check_stmt = $conn->prepare("SELECT id FROM departments WHERE name = ?");
                    $check_stmt->bind_param("s", $name);
                    $check_stmt->execute();
                    $result = $check_stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        $_SESSION['message'] = "Department name already exists!";
                        $_SESSION['message_type'] = "error";
                    } else {
                        if ($manager_id === NULL) {
                            $stmt = $conn->prepare("INSERT INTO departments (name, description, manager_id, status) VALUES (?, ?, NULL, ?)");
                            $stmt->bind_param("sss", $name, $description, $status);
                        } else {
                            $stmt = $conn->prepare("INSERT INTO departments (name, description, manager_id, status) VALUES (?, ?, ?, ?)");
                            $stmt->bind_param("ssis", $name, $description, $manager_id, $status);
                        }
                        
                        if ($stmt->execute()) {
                            $_SESSION['message'] = "Department added successfully!";
                            $_SESSION['message_type'] = "success";
                        } else {
                            $_SESSION['message'] = "Error adding department: " . $conn->error;
                            $_SESSION['message_type'] = "error";
                        }
                        $stmt->close();
                    }
                    $check_stmt->close();
                } else {
                    $_SESSION['message'] = "Department name is required!";
                    $_SESSION['message_type'] = "error";
                }
                
                // Redirect to prevent resubmission
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit();
                break;
                
            case 'edit':
                $id = $_POST['id'];
                $name = trim($_POST['name']);
                $description = trim($_POST['description']);
                $manager_id = !empty($_POST['manager_id']) ? $_POST['manager_id'] : NULL;
                $status = $_POST['status'];
                
                if (!empty($name)) {
                    // Check if department name already exists (excluding current record)
                    $check_stmt = $conn->prepare("SELECT id FROM departments WHERE name = ? AND id != ?");
                    $check_stmt->bind_param("si", $name, $id);
                    $check_stmt->execute();
                    $result = $check_stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        $_SESSION['message'] = "Department name already exists!";
                        $_SESSION['message_type'] = "error";
                    } else {
                        if ($manager_id === NULL) {
                            $stmt = $conn->prepare("UPDATE departments SET name = ?, description = ?, manager_id = NULL, status = ? WHERE id = ?");
                            $stmt->bind_param("sssi", $name, $description, $status, $id);
                        } else {
                            $stmt = $conn->prepare("UPDATE departments SET name = ?, description = ?, manager_id = ?, status = ? WHERE id = ?");
                            $stmt->bind_param("ssisi", $name, $description, $manager_id, $status, $id);
                        }
                        
                        if ($stmt->execute()) {
                            $_SESSION['message'] = "Department updated successfully!";
                            $_SESSION['message_type'] = "success";
                        } else {
                            $_SESSION['message'] = "Error updating department: " . $conn->error;
                            $_SESSION['message_type'] = "error";
                        }
                        $stmt->close();
                    }
                    $check_stmt->close();
                } else {
                    $_SESSION['message'] = "Department name is required!";
                    $_SESSION['message_type'] = "error";
                }
                
                // Redirect to prevent resubmission
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit();
                break;
                
            case 'delete':
                $id = $_POST['id'];
                
                // Check if department has employees
                $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE department_id = ?");
                $check_stmt->bind_param("i", $id);
                $check_stmt->execute();
                $result = $check_stmt->get_result();
                $row = $result->fetch_assoc();
                $employee_count = $row['count'];
                
                if ($employee_count > 0) {
                    $_SESSION['message'] = "Cannot delete department. It has $employee_count employees assigned to it.";
                    $_SESSION['message_type'] = "error";
                } else {
                    $stmt = $conn->prepare("DELETE FROM departments WHERE id = ?");
                    $stmt->bind_param("i", $id);
                    
                    if ($stmt->execute()) {
                        $_SESSION['message'] = "Department deleted successfully!";
                        $_SESSION['message_type'] = "success";
                    } else {
                        $_SESSION['message'] = "Error deleting department: " . $conn->error;
                        $_SESSION['message_type'] = "error";
                    }
                    $stmt->close();
                }
                $check_stmt->close();
                
                // Redirect to prevent resubmission
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit();
                break;
        }
    }
}

// Get messages from session
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

// Fetch all departments with manager information
$query = "
    SELECT d.*, 
           CONCAT(u.first_name, ' ', u.last_name) as manager_name,
           (SELECT COUNT(*) FROM users WHERE department_id = d.id) as employee_count
    FROM departments d 
    LEFT JOIN users u ON d.manager_id = u.id 
    ORDER BY d.id
";
$result = $conn->query($query);
$departments = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $departments[] = $row;
    }
}

$query = "SELECT u.id, u.first_name, u.last_name, u.email, jr.title
            FROM users u
            INNER JOIN job_roles jr ON u.role_id = jr.id
            WHERE (
                    UPPER(jr.title) IN ('ADMIN', 'ADMINISTRATOR', 'HR', 'HUMAN RESOURCES', 'MANAGER')
                    OR jr.title LIKE '%admin%'
                    OR jr.title LIKE '%manager%'
                    OR jr.title LIKE '%hr%'
                )
            AND u.status = 'active'
            ORDER BY u.first_name, u.last_name;";
$result = $conn->query($query);
$potential_managers = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $potential_managers[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VLMS - Department Management</title>
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

        /* Animated background particles */
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

        /* Form styles */
        .form-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            animation: slideUp 0.8s ease-out 0.1s both;
        }

        .form-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: #333;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #374151;
        }

        .form-input {
            padding: 0.75rem;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: white;
        }

        .form-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-textarea {
            min-height: 100px;
            resize: vertical;
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

        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(239, 68, 68, 0.4);
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(16, 185, 129, 0.4);
        }

        .btn-warning {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(245, 158, 11, 0.4);
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

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .status-active {
            background: rgba(34, 197, 94, 0.1);
            color: #059669;
            border: 1px solid rgba(34, 197, 94, 0.3);
        }

        .status-inactive {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
            border: 1px solid rgba(239, 68, 68, 0.3);
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
            max-width: 600px;
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

            .form-grid {
                grid-template-columns: 1fr;
            }

            .actions-cell {
                flex-direction: column;
            }

            .page-title {
                font-size: 1.5rem;
            }

            .table-container {
                padding: 1rem;
            }
        }
    </style>
</head>

<body>
    <!-- Animated background particles -->
    <div class="bg-particles" id="particles"></div>

    <!-- Navigation -->
    <nav class="navbar">
        <a class="navbar-brand" href="dashboard.php">
            <i class="fas fa-calendar-alt"></i>
            Vacation Leave Management System
        </a>
        <div class="navbar-nav">
            <a href="../admin/index.php" class="nav-link">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <a href="../auth/profile.php" class="nav-link">
                <i class="fas fa-user"></i> Profile
            </a>
            <a href="../auth/logout.php" class="nav-link">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </nav>

    <!-- Main content -->
    <div class="main-container">
        <!-- Page header -->
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-building"></i>
                Department Management
            </h1>
            <p class="page-subtitle">Manage company departments and their information</p>
        </div>

        <!-- Messages -->
        <?php if (!empty($message)) { ?>
            <div class="message <?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <?php echo $message; ?>
            </div>
        <?php } ?>

        <!-- Add Department Form -->
        <div class="form-container">
            <h2 class="form-title">
                <i class="fas fa-plus-circle"></i>
                Add New Department
            </h2>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label" for="name">Department Name *</label>
                        <input type="text" class="form-input" id="name" name="name" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="manager_id">Department Manager</label>
                        <select class="form-input" id="manager_id" name="manager_id">
                            <option value="">Select Manager (Optional)</option>
                            <?php foreach ($potential_managers as $manager) { ?>
                                <option value="<?php echo $manager['id']; ?>">
                                    <?php echo htmlspecialchars($manager['first_name'] . ' ' . $manager['last_name'] . ' (' . $manager['email'] . ')'); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="status">Status</label>
                        <select class="form-input" id="status" name="status" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="description">Description</label>
                    <textarea class="form-input form-textarea" id="description" name="description" placeholder="Enter department description..."></textarea>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-plus"></i>
                    Add Department
                </button>
            </form>
        </div>

        <!-- Departments Table -->
        <div class="table-container">
            <h2 class="form-title">
                <i class="fas fa-list"></i>
                Existing Departments (<?php echo count($departments); ?>)
            </h2>
            
            <?php if (empty($departments)) { ?>
                <div style="text-align: center; padding: 3rem; color: #666;">
                    <i class="fas fa-building" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.3;"></i>
                    <p>No departments found. Add your first department using the form above.</p>
                </div>
            <?php } else { ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Department Name</th>
                            <th>Description</th>
                            <th>Manager</th>
                            <th>Employees</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($departments as $department) { ?>
                            <tr>
                                <td><?php echo $department['id']; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($department['name']); ?></strong>
                                </td>
                                <td>
                                    <?php echo $department['description'] ? htmlspecialchars(substr($department['description'], 0, 100)) . (strlen($department['description']) > 100 ? '...' : '') : '<em>No description</em>'; ?>
                                </td>
                                <td>
                                    <?php echo $department['manager_name'] ? htmlspecialchars($department['manager_name']) : '<em>No manager assigned</em>'; ?>
                                </td>
                                <td>
                                    <span>
                                        <?php echo $department['employee_count']; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $department['status']; ?>">
                                        <?php echo ucfirst($department['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($department['created_at'])); ?></td>
                                <td class="actions-cell">
                                    <button class="btn btn-warning" onclick="editDepartment(<?php echo htmlspecialchars(json_encode($department)); ?>)">
                                        <i class="fas fa-edit"></i>
                                        Edit
                                    </button>
                                    <button class="btn btn-danger" onclick="deleteDepartment(<?php echo $department['id']; ?>, '<?php echo htmlspecialchars($department['name']); ?>', <?php echo $department['employee_count']; ?>)">
                                        <i class="fas fa-trash"></i>
                                        Delete
                                    </button>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            <?php } ?>
        </div>
    </div>

    <!-- Edit Department Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2 class="form-title">
                <i class="fas fa-edit"></i>
                Edit Department
            </h2>
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" id="edit_id" name="id">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label" for="edit_name">Department Name *</label>
                        <input type="text" class="form-input" id="edit_name" name="name" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_manager_id">Department Manager</label>
                        <select class="form-input" id="edit_manager_id" name="manager_id">
                            <option value="">Select Manager (Optional)</option>
                            <?php foreach ($potential_managers as $manager) { ?>
                                <option value="<?php echo $manager['id']; ?>">
                                    <?php echo htmlspecialchars($manager['first_name'] . ' ' . $manager['last_name'] . ' (' . $manager['email'] . ')'); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="edit_status">Status</label>
                        <select class="form-input" id="edit_status" name="status" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="edit_description">Description</label>
                    <textarea class="form-input form-textarea" id="edit_description" name="description" placeholder="Enter department description..."></textarea>
                </div>
                <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1rem;">
                    <button type="button" class="btn btn-danger" onclick="closeModal()">
                        <i class="fas fa-times"></i>
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i>
                        Update Department
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeDeleteModal()">&times;</span>
            <h2 class="form-title" style="color: #dc2626;">
                <i class="fas fa-exclamation-triangle"></i>
                Confirm Deletion
            </h2>
            <div style="margin-bottom: 2rem;">
                <p>Are you sure you want to delete the department "<strong id="delete_department_name"></strong>"?</p>
                <div id="employee_warning" style="margin-top: 1rem; padding: 1rem; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 10px; color: #dc2626; display: none;">
                    <i class="fas fa-exclamation-circle"></i>
                    <strong>Warning:</strong> This department has <span id="employee_count"></span> employee(s) assigned to it. You cannot delete this department until all employees are reassigned or removed.
                </div>
            </div>
            <form method="POST" action="" id="deleteForm">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" id="delete_id" name="id">
                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                    <button type="button" class="btn btn-primary" onclick="closeDeleteModal()">
                        <i class="fas fa-times"></i>
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-danger" id="confirmDeleteBtn">
                        <i class="fas fa-trash"></i>
                        Delete Department
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Create animated background particles
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

        // Initialize particles on page load
        document.addEventListener('DOMContentLoaded', createParticles);

        // Edit department function
        function editDepartment(department) {
            document.getElementById('edit_id').value = department.id;
            document.getElementById('edit_name').value = department.name;
            document.getElementById('edit_description').value = department.description || '';
            document.getElementById('edit_status').value = department.status;
            document.getElementById('edit_manager_id').value = department.manager_id || '';
            document.getElementById('editModal').style.display = 'block';
        }

        // Delete department function
        function deleteDepartment(id, name, employeeCount) {
            document.getElementById('delete_id').value = id;
            document.getElementById('delete_department_name').textContent = name;
            
            const employeeWarning = document.getElementById('employee_warning');
            const confirmBtn = document.getElementById('confirmDeleteBtn');
            
            if (employeeCount > 0) {
                document.getElementById('employee_count').textContent = employeeCount;
                employeeWarning.style.display = 'block';
                confirmBtn.disabled = true;
                confirmBtn.style.opacity = '0.5';
                confirmBtn.style.cursor = 'not-allowed';
            } else {
                employeeWarning.style.display = 'none';
                confirmBtn.disabled = false;
                confirmBtn.style.opacity = '1';
                confirmBtn.style.cursor = 'pointer';
            }
            
            document.getElementById('deleteModal').style.display = 'block';
        }

        // Close modals
        function closeModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const editModal = document.getElementById('editModal');
            const deleteModal = document.getElementById('deleteModal');
            
            if (event.target === editModal) {
                editModal.style.display = 'none';
            }
            if (event.target === deleteModal) {
                deleteModal.style.display = 'none';
            }
        }

        // Auto-hide messages after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const messages = document.querySelectorAll('.message');
            messages.forEach(function(message) {
                setTimeout(function() {
                    message.style.opacity = '0';
                    message.style.transform = 'translateY(-20px)';
                    setTimeout(function() {
                        message.style.display = 'none';
                    }, 300);
                }, 5000);
            });
        });
    </script>
</body>
</html>