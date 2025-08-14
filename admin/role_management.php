<?php
session_start();
include '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login');
    exit();
}

// Handle role switching for admins
if (isset($_POST['switch_role'])) {
    if ($_SESSION['original_role'] === 'Admin') {
        $_SESSION['current_role'] = $_POST['switch_role'];
        // Redirect to prevent form resubmission
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Handle returning to admin role
if (isset($_POST['return_admin'])) {
    if (isset($_SESSION['original_role']) && $_SESSION['original_role'] === 'Admin') {
        $_SESSION['current_role'] = 'Admin';
        // Redirect to prevent form resubmission
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}

$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_role':
                $title = trim($_POST['title']);
                $description = trim($_POST['description']);
                
                if (!empty($title)) {
                    try {
                        $stmt = $conn->prepare("INSERT INTO job_roles (title, description, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");
                        $stmt->bind_param("ss", $title, $description);
                        
                        if ($stmt->execute()) {
                            $message = "Role added successfully!";
                            $messageType = "success";
                        } else {
                            $message = "Error adding role: " . $conn->error;
                            $messageType = "error";
                        }
                        $stmt->close();
                    } catch (Exception $e) {
                        $message = "Error: " . $e->getMessage();
                        $messageType = "error";
                    }
                } else {
                    $message = "Role title is required!";
                    $messageType = "error";
                }
                break;
                
            case 'update_role':
                $roleId = intval($_POST['role_id']);
                $title = trim($_POST['title']);
                $description = trim($_POST['description']);
                
                if (!empty($title) && $roleId > 0) {
                    try {
                        $stmt = $conn->prepare("UPDATE job_roles SET title = ?, description = ?, updated_at = NOW() WHERE id = ?");
                        $stmt->bind_param("ssi", $title, $description, $roleId);
                        
                        if ($stmt->execute()) {
                            $message = "Role updated successfully!";
                            $messageType = "success";
                        } else {
                            $message = "Error updating role: " . $conn->error;
                            $messageType = "error";
                        }
                        $stmt->close();
                    } catch (Exception $e) {
                        $message = "Error: " . $e->getMessage();
                        $messageType = "error";
                    }
                } else {
                    $message = "Invalid role data!";
                    $messageType = "error";
                }
                break;
                
            case 'delete_role':
                $roleId = intval($_POST['role_id']);
                
                if ($roleId > 0) {
                    try {
                        // Check if role is being used by any users
                        $stmt = $conn->prepare("SELECT COUNT(*) as user_count FROM users WHERE role_id = ?");
                        $stmt->bind_param("i", $roleId);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $row = $result->fetch_assoc();
                        $stmt->close();
                        
                        if ($row['user_count'] > 0) {
                            $message = "Cannot delete role: It is currently assigned to " . $row['user_count'] . " user(s).";
                            $messageType = "error";
                        } else {
                            $stmt = $conn->prepare("DELETE FROM job_roles WHERE id = ?");
                            $stmt->bind_param("i", $roleId);
                            
                            if ($stmt->execute()) {
                                $message = "Role deleted successfully!";
                                $messageType = "success";
                            } else {
                                $message = "Error deleting role: " . $conn->error;
                                $messageType = "error";
                            }
                            $stmt->close();
                        }
                    } catch (Exception $e) {
                        $message = "Error: " . $e->getMessage();
                        $messageType = "error";
                    }
                } else {
                    $message = "Invalid role ID!";
                    $messageType = "error";
                }
                break;
        }
    }
}

// Fetch all roles from database
try {
    $stmt = $conn->prepare("SELECT * FROM job_roles ORDER BY id ASC");
    $stmt->execute();
    $rolesResult = $stmt->get_result();
    $roles = [];
    while ($row = $rolesResult->fetch_assoc()) {
        $roles[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    $message = "Error fetching roles: " . $e->getMessage();
    $messageType = "error";
    $roles = [];
}

// Handle AJAX requests for getting role data
if (isset($_GET['action']) && $_GET['action'] === 'get_role' && isset($_GET['id'])) {
    $roleId = intval($_GET['id']);
    try {
        $stmt = $conn->prepare("SELECT * FROM job_roles WHERE id = ?");
        $stmt->bind_param("i", $roleId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($role = $result->fetch_assoc()) {
            header('Content-Type: application/json');
            echo json_encode($role);
            exit();
        }
        $stmt->close();
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VLMS - Role Management</title>
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
            font-family: inherit;
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

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
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

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .table-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #333;
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
            margin: 2% auto;
            padding: 2rem;
            border-radius: 20px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
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
                flex-direction: column;
                gap: 1rem;
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
        <a class="navbar-brand" href="dashboard">
            <i class="fas fa-calendar-alt"></i>
            Vacation Leave Management System
        </a>
        <div class="navbar-nav">
            <a href="../admin/index" class="nav-link">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <a href="user_management" class="nav-link">
                <i class="fas fa-users"></i> Users
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
                <i class="fas fa-shield-alt"></i>
                Role Management
            </h1>
            <p class="page-subtitle">Manage system roles and user positions</p>
        </div>

        <!-- Messages -->
        <?php if (!empty($message)): ?>
        <div class="message <?php echo $messageType; ?>">
            <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>

        <!-- Add/Edit Role Form -->
        <div class="form-container">
            <h2 class="form-title" id="formTitle">
                <i class="fas fa-plus-circle"></i>
                Add New Role
            </h2>
            <form id="roleForm" method="POST">
                <input type="hidden" name="action" id="formAction" value="add_role">
                <input type="hidden" id="roleId" name="role_id">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="roleTitle" class="form-label">Role Title *</label>
                        <input type="text" id="roleTitle" name="title" class="form-input" required placeholder="e.g., Manager, Employee, HR">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="roleDescription" class="form-label">Description</label>
                    <textarea id="roleDescription" name="description" class="form-input form-textarea" rows="3" placeholder="Brief description of the role and its responsibilities"></textarea>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i>
                    Save Role
                </button>
                <button type="button" class="btn btn-danger" id="cancelBtn" style="display: none;" onclick="resetForm()">
                    <i class="fas fa-times"></i>
                    Cancel
                </button>
            </form>
        </div>

        <!-- Roles Table -->
        <div class="table-container">
            <div class="table-header">
                <h2 class="table-title">
                    <i class="fas fa-list"></i>
                    System Roles
                </h2>
            </div>
            
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Role Title</th>
                        <th>Description</th>
                        <th>Created Date</th>
                        <th>Last Updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($roles)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: #666;">No roles found. Add a new role to get started.</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($roles as $role): ?>
                        <tr>
                            <td><?php echo $role['id']; ?></td>
                            <td><strong><?php echo htmlspecialchars($role['title']); ?></strong></td>
                            <td><?php echo htmlspecialchars($role['description'] ?: 'No description'); ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($role['created_at'])); ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($role['updated_at'])); ?></td>
                            <td class="actions-cell">
                                <button class="btn btn-warning btn-sm" onclick="editRole(<?php echo $role['id']; ?>)">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button class="btn btn-danger btn-sm" onclick="deleteRole(<?php echo $role['id']; ?>)">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeDeleteModal()">&times;</span>
            <h2>Confirm Deletion</h2>
            <p>Are you sure you want to delete this role? This action cannot be undone and may affect users assigned to this role.</p>
            <div style="margin-top: 1.5rem;">
                <form id="deleteForm" method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="delete_role">
                    <input type="hidden" id="deleteRoleId" name="role_id">
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash"></i>
                        Delete Role
                    </button>
                </form>
                <button class="btn btn-primary" onclick="closeDeleteModal()">
                    <i class="fas fa-times"></i>
                    Cancel
                </button>
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
                particle.style.animationDuration = (Math.random() * 10 + 10) + 's';
                particlesContainer.appendChild(particle);
            }
        }

        // Edit role function
        function editRole(roleId) {
            fetch(`?action=get_role&id=${roleId}`)
                .then(response => response.json())
                .then(role => {
                    // Populate form
                    document.getElementById('formAction').value = 'update_role';
                    document.getElementById('roleId').value = role.id;
                    document.getElementById('roleTitle').value = role.title;
                    document.getElementById('roleDescription').value = role.description || '';
                    
                    // Update form title and buttons
                    document.getElementById('formTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Role';
                    document.getElementById('cancelBtn').style.display = 'inline-flex';
                    
                    // Scroll to form
                    document.querySelector('.form-container').scrollIntoView({ behavior: 'smooth' });
                })
                .catch(error => {
                    console.error('Error fetching role data:', error);
                    alert('Error loading role data');
                });
        }

        // Delete role function
        function deleteRole(roleId) {
            document.getElementById('deleteRoleId').value = roleId;
            document.getElementById('deleteModal').style.display = 'block';
        }

        // Close delete modal
        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Reset form to add mode
        function resetForm() {
            document.getElementById('roleForm').reset();
            document.getElementById('formAction').value = 'add_role';
            document.getElementById('roleId').value = '';
            document.getElementById('formTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Add New Role';
            document.getElementById('cancelBtn').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('deleteModal');
            if (event.target === modal) {
                closeDeleteModal();
            }
        }

        // Initialize particles on page load
        document.addEventListener('DOMContentLoaded', function() {
            createParticles();
            
            // Auto-hide messages after 5 seconds
            const messages = document.querySelectorAll('.message');
            messages.forEach(message => {
                setTimeout(() => {
                    message.style.opacity = '0';
                    setTimeout(() => {
                        message.remove();
                    }, 300);
                }, 5000);
            });
        });

        // Form validation
        document.getElementById('roleForm').addEventListener('submit', function(e) {
            const roleTitle = document.getElementById('roleTitle').value.trim();
            if (!roleTitle) {
                e.preventDefault();
                alert('Role title is required!');
                return false;
            }
        });
    </script>
</body>
</html>