<?php
// hr/edit_employee.php
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

// Initialize variables
$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
  $first_name = trim($_POST['first_name']);
  $last_name = trim($_POST['last_name']);
  $email = trim($_POST['email']);
  $phone = trim($_POST['phone']);
  $date_of_birth = $_POST['date_of_birth'] ?: null;
  $address = trim($_POST['address']);
  $employee_id_field = trim($_POST['employee_id']);
  $department_id = $_POST['department_id'] ?: null;
  $job_role_id = $_POST['job_role_id'] ?: null;
  $manager_id = $_POST['manager_id'] ?: null;
  $status = $_POST['status'] ?: null;
  $salary = $_POST['salary'] ? floatval($_POST['salary']) : null;
  $hire_date = $_POST['hire_date'] ?: null;
  $emergency_contact_name = trim($_POST['emergency_contact_name']);
  $emergency_contact_phone = trim($_POST['emergency_contact_phone']);
  $emergency_contact_relationship = trim($_POST['emergency_contact_relationship']);

  // Validation
  if (empty($first_name) || empty($last_name) || empty($email) || empty($employee_id_field)) {
    $error_message = "Please fill in all required fields.";
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error_message = "Please enter a valid email address.";
  } else {
    // Check if email is unique (excluding current employee)
    $email_check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $email_check->bind_param("si", $email, $employee_id);
    $email_check->execute();
    $email_result = $email_check->get_result();

    // Check if employee ID is unique (excluding current employee)
    $id_check = $conn->prepare("SELECT id FROM users WHERE employee_id = ? AND id != ?");
    $id_check->bind_param("si", $employee_id_field, $employee_id);
    $id_check->execute();
    $id_result = $id_check->get_result();

    if ($email_result->num_rows > 0) {
      $error_message = "Email address is already in use by another employee.";
    } elseif ($id_result->num_rows > 0) {
      $error_message = "Employee ID is already in use.";
    } else {
      // Handle profile image upload
      $profile_image_path = null;
      if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == 0) {
        $upload_dir = '../uploads/profiles/';
        if (!is_dir($upload_dir)) {
          mkdir($upload_dir, 0755, true);
        }

        $file_extension = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];

        if (in_array($file_extension, $allowed_extensions)) {
          $new_filename = 'profile_' . $employee_id . '_' . time() . '.' . $file_extension;
          $upload_path = $upload_dir . $new_filename;

          if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_path)) {
            $profile_image_path = 'uploads/profiles/' . $new_filename;
          }
        }
      }

      // Update employee
      $update_query = "UPDATE users SET 
                    first_name = ?, 
                    last_name = ?, 
                    email = ?, 
                    phone = ?, 
                    date_of_birth = ?, 
                    address = ?, 
                    employee_id = ?, 
                    department_id = ?, 
                    job_role_id = ?, 
                    manager_id = ?, 
                    status = ?, 
                    salary = ?, 
                    hire_date = ?,
                    emergency_contact_name = ?, 
                    emergency_contact_phone = ?, 
                    emergency_contact_relationship = ?";

      $params = [
        $first_name,
        $last_name,
        $email,
        $phone,
        $date_of_birth,
        $address,
        $employee_id_field,
        $department_id,
        $job_role_id,
        $manager_id,
        $status,
        $salary,
        $hire_date,
        $emergency_contact_name,
        $emergency_contact_phone,
        $emergency_contact_relationship
      ];
      //$types = "ssssssssssddsss";
      $types = "ssssssiiiisdssss";

      if ($profile_image_path) {
        $update_query .= ", profile_image = ?";
        $params[] = $profile_image_path;
        $types .= "s";
      }

      $update_query .= " WHERE id = ?";
      $params[] = $employee_id;
      $types .= "i";

      $stmt = $conn->prepare($update_query);
      $stmt->bind_param($types, ...$params);

      if ($stmt->execute()) {
        $success_message = "Employee information updated successfully!";
      } else {
        $error_message = "Error updating employee: " . $conn->error;
      }
    }
  }
}

// Fetch employee details
$employee_query = "SELECT 
                        u.*,
                        d.name as department_name,
                        jr.title as job_title,
                        r.name as role_name,
                        CONCAT(m.first_name, ' ', m.last_name) as manager_name
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

// Fetch departments
$departments_query = "SELECT id, name FROM departments WHERE status = 'active' ORDER BY name";
$departments_result = $conn->query($departments_query);

// Fetch job roles
$job_roles_query = "SELECT id, title FROM job_roles ORDER BY title";
$job_roles_result = $conn->query($job_roles_query);

// Fetch roles
$roles_query = "SELECT id, name FROM roles ORDER BY name";
$roles_result = $conn->query($roles_query);

// Fetch potential managers (exclude current employee and subordinates)
$managers_query = "SELECT id, CONCAT(first_name, ' ', last_name) as full_name, employee_id 
                   FROM users 
                   WHERE id != ? AND status = 'active' 
                   ORDER BY first_name, last_name";
$managers_stmt = $conn->prepare($managers_query);
$managers_stmt->bind_param("i", $employee_id);
$managers_stmt->execute();
$managers_result = $managers_stmt->get_result();

?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>VLMS - Edit Employee</title>
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
      max-width: 1000px;
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

    /* Alert Messages */
    .alert {
      padding: 15px 20px;
      border-radius: 12px;
      margin-bottom: 25px;
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .alert-success {
      background: #d1fae5;
      color: #065f46;
      border-left: 4px solid #10b981;
    }

    .alert-error {
      background: #fee2e2;
      color: #dc2626;
      border-left: 4px solid #ef4444;
    }

    /* Form Container */
    .form-container {
      background: rgba(255, 255, 255, 0.95);
      border-radius: 20px;
      padding: 40px;
      backdrop-filter: blur(10px);
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
    }

    .form-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 25px;
      margin-bottom: 30px;
    }

    .form-section {
      background: #f8fafc;
      border-radius: 16px;
      padding: 25px;
      border: 2px solid #e2e8f0;
    }

    .section-title {
      font-size: 18px;
      font-weight: 600;
      color: #333;
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .section-title i {
      background: linear-gradient(135deg, #667eea, #764ba2);
      color: white;
      width: 30px;
      height: 30px;
      border-radius: 6px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 14px;
    }

    .form-group {
      margin-bottom: 20px;
    }

    .form-group label {
      display: block;
      font-weight: 500;
      color: #333;
      margin-bottom: 8px;
      font-size: 14px;
    }

    .required {
      color: #ef4444;
    }

    .form-control {
      width: 100%;
      padding: 12px 16px;
      border: 2px solid #e2e8f0;
      border-radius: 10px;
      font-size: 14px;
      transition: all 0.3s ease;
      background: white;
    }

    .form-control:focus {
      outline: none;
      border-color: #667eea;
      box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }

    .form-control:invalid {
      border-color: #ef4444;
    }

    select.form-control {
      cursor: pointer;
    }

    textarea.form-control {
      resize: vertical;
      min-height: 80px;
    }

    /* Profile Image Section */
    .profile-section {
      grid-column: 1 / -1;
      text-align: center;
      margin-bottom: 30px;
    }

    .current-avatar {
      width: 100px;
      height: 100px;
      border-radius: 50%;
      background: linear-gradient(135deg, #667eea, #764ba2);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-weight: 600;
      font-size: 36px;
      margin-bottom: 15px;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
    }

    .current-avatar img {
      width: 100%;
      height: 100%;
      border-radius: 50%;
      object-fit: cover;
    }

    .file-input-wrapper {
      position: relative;
      display: inline-block;
      margin-top: 10px;
    }

    .file-input {
      position: absolute;
      left: -9999px;
    }

    .file-input-label {
      background: #667eea;
      color: white;
      padding: 10px 20px;
      border-radius: 8px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-weight: 500;
      transition: all 0.3s ease;
    }

    .file-input-label:hover {
      background: #5a67d8;
      transform: translateY(-2px);
    }

    /* Button Styles */
    .btn {
      padding: 12px 24px;
      border-radius: 10px;
      font-weight: 500;
      font-size: 14px;
      cursor: pointer;
      transition: all 0.3s ease;
      border: none;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }

    .btn-primary {
      background: linear-gradient(135deg, #667eea, #764ba2);
      color: white;
    }

    .btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
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

    .btn-danger {
      background: #ef4444;
      color: white;
    }

    .btn-danger:hover {
      background: #dc2626;
      transform: translateY(-2px);
    }

    .form-actions {
      display: flex;
      gap: 15px;
      justify-content: flex-end;
      margin-top: 30px;
      padding-top: 30px;
      border-top: 2px solid #e2e8f0;
    }

    /* Status and Role Badges */
    .status-preview,
    .role-preview {
      display: inline-block;
      padding: 6px 12px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 500;
      margin-top: 5px;
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

    .role-admin {
      background: linear-gradient(135deg, #667eea, #764ba2);
      color: white;
    }

    .role-hr {
      background: linear-gradient(135deg, #56ab2f, #a8e6cf);
      color: white;
    }

    .role-manager {
      background: linear-gradient(135deg, #ff9a56, #ff6b95);
      color: white;
    }

    .role-employee {
      background: linear-gradient(135deg, #4facfe, #00f2fe);
      color: white;
    }

    /* Help Text */
    .help-text {
      font-size: 12px;
      color: #666;
      margin-top: 5px;
    }

    /* Responsive Design */
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

      .form-container {
        padding: 25px;
      }

      .form-grid {
        grid-template-columns: 1fr;
        gap: 20px;
      }

      .form-section {
        padding: 20px;
      }

      .form-actions {
        flex-direction: column;
      }
    }

    /* Loading State */
    .btn.loading {
      pointer-events: none;
      opacity: 0.7;
    }

    .btn.loading i {
      animation: spin 1s linear infinite;
    }

    @keyframes spin {
      from {
        transform: rotate(0deg);
      }

      to {
        transform: rotate(360deg);
      }
    }

    /* Validation Styles */
    .form-control.is-invalid {
      border-color: #ef4444;
      box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
    }

    .form-control.is-valid {
      border-color: #10b981;
      box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
    }

    .invalid-feedback {
      color: #ef4444;
      font-size: 12px;
      margin-top: 5px;
    }

    .valid-feedback {
      color: #10b981;
      font-size: 12px;
      margin-top: 5px;
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
      <a href="view_employee?id=<?php echo $employee['id']; ?>"><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></a>
      <span>/</span>
      <span>Edit</span>
    </div>

    <!-- Page Header -->
    <div class="page-header">
      <div class="page-title">
        <div class="title-icon">
          <i class="fas fa-user-edit"></i>
        </div>
        <div>
          <h1>Edit Employee</h1>
          <p style="color: #666; margin-top: 5px;">Update employee information and settings</p>
        </div>
      </div>
    </div>

    <!-- Alert Messages -->
    <?php if ($success_message): ?>
      <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <?php echo $success_message; ?>
      </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
      <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <?php echo $error_message; ?>
      </div>
    <?php endif; ?>

    <!-- Edit Form -->
    <div class="form-container">
      <form method="POST" enctype="multipart/form-data" id="editEmployeeForm">
        <!-- Profile Image Section -->
        <div class="profile-section">
          <div class="current-avatar">
            <?php if ($employee['profile_image']): ?>
              <img src="../<?php echo htmlspecialchars($employee['profile_image']); ?>" alt="Profile">
            <?php else: ?>
              <?php echo strtoupper(substr($employee['first_name'], 0, 1) . substr($employee['last_name'], 0, 1)); ?>
            <?php endif; ?>
          </div>
          <!--<div class="file-input-wrapper">
            <input type="file" id="profile_image" name="profile_image" class="file-input" accept="image/*">
            <label for="profile_image" class="file-input-label">
              <i class="fas fa-camera"></i>
              Change Photo
            </label>
          </div>
          <div class="help-text">Supported formats: JPG, PNG, GIF. Max size: 2MB</div>-->
        </div>

        <div class="form-grid">
          <!-- Personal Information -->
          <div class="form-section">
            <div class="section-title">
              <i class="fas fa-user"></i>
              Personal Information
            </div>

            <div class="form-group">
              <label for="first_name">First Name <span class="required">*</span></label>
              <input type="text" id="first_name" name="first_name" class="form-control"
                value="<?php echo htmlspecialchars($employee['first_name']); ?>" required>
            </div>

            <div class="form-group">
              <label for="last_name">Last Name <span class="required">*</span></label>
              <input type="text" id="last_name" name="last_name" class="form-control"
                value="<?php echo htmlspecialchars($employee['last_name']); ?>" required>
            </div>

            <div class="form-group">
              <label for="email">Email Address <span class="required">*</span></label>
              <input type="email" id="email" name="email" class="form-control"
                value="<?php echo htmlspecialchars($employee['email']); ?>" required>
            </div>

            <div class="form-group">
              <label for="phone">Phone Number</label>
              <input type="tel" id="phone" name="phone" class="form-control"
                value="<?php echo htmlspecialchars($employee['phone']); ?>">
            </div>

            <div class="form-group">
              <label for="date_of_birth">Date of Birth</label>
              <input type="date" id="date_of_birth" name="date_of_birth" class="form-control"
                value="<?php echo $employee['date_of_birth']; ?>">
            </div>

            <div class="form-group">
              <label for="address">Address</label>
              <textarea id="address" name="address" class="form-control" rows="3"><?php echo htmlspecialchars($employee['address']); ?></textarea>
            </div>
          </div>

          <!-- Employment Information -->
          <div class="form-section">
            <div class="section-title">
              <i class="fas fa-briefcase"></i>
              Employment Information
            </div>

            <div class="form-group">
              <label for="employee_id">Employee ID <span class="required">*</span></label>
              <input type="text" id="employee_id" name="employee_id" class="form-control"
                value="<?php echo htmlspecialchars($employee['employee_id']); ?>" required>
            </div>

            <div class="form-group">
              <label for="department_id">Department</label>
              <select id="department_id" name="department_id" class="form-control">
                <option value="">Select Department</option>
                <?php while ($dept = $departments_result->fetch_assoc()): ?>
                  <option value="<?php echo $dept['id']; ?>"
                    <?php echo $employee['department_id'] == $dept['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($dept['name']); ?>
                  </option>
                <?php endwhile; ?>
              </select>
            </div>

            <div class="form-group">
              <label for="job_role_id">Job Title</label>
              <select id="job_role_id" name="job_role_id" class="form-control">
                <option value="">Select Job Title</option>
                <?php while ($job = $job_roles_result->fetch_assoc()): ?>
                  <option value="<?php echo $job['id']; ?>"
                    <?php echo $employee['job_role_id'] == $job['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($job['title']); ?>
                  </option>
                <?php endwhile; ?>
              </select>
            </div>

            <div class="form-group">
              <label for="manager_id">Manager</label>
              <select id="manager_id" name="manager_id" class="form-control">
                <option value="">Select Manager</option>
                <?php while ($manager = $managers_result->fetch_assoc()): ?>
                  <option value="<?php echo $manager['id']; ?>"
                    <?php echo $employee['manager_id'] == $manager['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($manager['full_name']); ?> (<?php echo htmlspecialchars($manager['employee_id']); ?>)
                  </option>
                <?php endwhile; ?>
              </select>
            </div>

            <div class="form-group">
              <label for="date_of_birth">Hire Date</label>
              <input type="date" id="hire_date" name="hire_date" class="form-control"
                value="<?php echo $employee['hire_date']; ?>">
            </div>

            <div class="form-group">
              <label for="salary">Salary</label>
              <input type="number" id="salary" name="salary" class="form-control" step="0.01" min="0"
                value="<?php echo $employee['salary']; ?>">
              <div class="help-text">Optional - Leave blank if not applicable</div>
            </div>
          </div>

          <div class="form-section" style="grid-column: 1 / -1;">
            <div class="section-title">
              <i class="fas fa-phone"></i>
              Account Status
            </div>
            <div class="form-group">
              <label for="status">Status <span class="required">*</span></label>
              <select id="status" name="status" class="form-control" required>
                <option value="active" <?php echo $employee['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo $employee['status'] == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                <option value="suspended" <?php echo $employee['status'] == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
              </select>
              <div id="status-preview" class="status-preview"></div>
            </div>
          </div>

          <!-- Emergency Contact Information -->
          <div class="form-section" style="grid-column: 1 / -1;">
            <div class="section-title">
              <i class="fas fa-phone"></i>
              Emergency Contact Information
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
              <div class="form-group">
                <label for="emergency_contact_name">Emergency Contact Name</label>
                <input type="text" id="emergency_contact_name" name="emergency_contact_name" class="form-control"
                  value="<?php echo htmlspecialchars($employee['emergency_contact_name']); ?>">
              </div>

              <div class="form-group">
                <label for="emergency_contact_phone">Emergency Contact Phone</label>
                <input type="tel" id="emergency_contact_phone" name="emergency_contact_phone" class="form-control"
                  value="<?php echo htmlspecialchars($employee['emergency_contact_phone']); ?>">
              </div>

              <div class="form-group">
                <label for="emergency_contact_relationship">Relationship</label>
                <input type="text" id="emergency_contact_relationship" name="emergency_contact_relationship" class="form-control"
                  value="<?php echo htmlspecialchars($employee['emergency_contact_relationship']); ?>"
                  placeholder="e.g., Spouse, Parent, Sibling">
              </div>
            </div>
          </div>
        </div>

        <!-- Form Actions -->
        <div class="form-actions">
          <a href="view_employee?id=<?php echo $employee['id']; ?>" class="btn btn-secondary">
            <i class="fas fa-times"></i>
            Cancel
          </a>
          <button type="submit" class="btn btn-primary" id="submitBtn">
            <i class="fas fa-save"></i>
            Update Employee
          </button>
        </div>
      </form>
    </div>
  </div>

  <script>
    // Preview status badge
    function updateStatusPreview() {
      const statusSelect = document.getElementById('status');
      const statusPreview = document.getElementById('status-preview');
      const status = statusSelect.value;

      statusPreview.className = 'status-preview status-' + status;
      statusPreview.textContent = status.charAt(0).toUpperCase() + status.slice(1);
    }

    // Preview role badge
    function updateRolePreview() {
      const roleSelect = document.getElementById('role_id');
      const rolePreview = document.getElementById('role-preview');
      const selectedOption = roleSelect.options[roleSelect.selectedIndex];
      const roleName = selectedOption.text.toLowerCase();

      rolePreview.className = 'role-preview role-' + roleName;
      rolePreview.textContent = selectedOption.text;
    }

    // Form validation
    function validateForm() {
      let isValid = true;
      const requiredFields = ['first_name', 'last_name', 'email', 'employee_id', 'role_id'];

      requiredFields.forEach(fieldId => {
        const field = document.getElementById(fieldId);
        const value = field.value.trim();

        if (!value) {
          field.classList.add('is-invalid');
          isValid = false;
        } else {
          field.classList.remove('is-invalid');
          field.classList.add('is-valid');
        }
      });

      // Email validation
      const emailField = document.getElementById('email');
      const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (emailField.value && !emailRegex.test(emailField.value)) {
        emailField.classList.add('is-invalid');
        isValid = false;
      }

      return isValid;
    }

    // Form submission handling
    document.getElementById('editEmployeeForm').addEventListener('submit', function(e) {
      if (!validateForm()) {
        e.preventDefault();
        return;
      }

      const submitBtn = document.getElementById('submitBtn');
      submitBtn.classList.add('loading');
      submitBtn.innerHTML = '<i class="fas fa-spinner"></i> Updating...';
    });

    // Real-time validation
    document.querySelectorAll('.form-control').forEach(field => {
      field.addEventListener('blur', function() {
        if (this.hasAttribute('required')) {
          if (this.value.trim()) {
            this.classList.remove('is-invalid');
            this.classList.add('is-valid');
          } else {
            this.classList.add('is-invalid');
            this.classList.remove('is-valid');
          }
        }
      });
    });

    // File input preview
    document.getElementById('profile_image').addEventListener('change', function(e) {
      const file = e.target.files[0];
      if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
          const avatar = document.querySelector('.current-avatar');
          avatar.innerHTML = `<img src="${e.target.result}" alt="Profile Preview">`;
        };
        reader.readAsDataURL(file);
      }
    });

    // Initialize previews
    document.addEventListener('DOMContentLoaded', function() {
      updateStatusPreview();
      updateRolePreview();

      document.getElementById('status').addEventListener('change', updateStatusPreview);
      document.getElementById('role_id').addEventListener('change', updateRolePreview);
    });

    // Responsive emergency contact grid
    function adjustEmergencyContactGrid() {
      const emergencyGrid = document.querySelector('.form-section[style*="grid-column"] > div[style*="grid-template-columns"]');
      if (window.innerWidth <= 768) {
        emergencyGrid.style.gridTemplateColumns = '1fr';
      } else {
        emergencyGrid.style.gridTemplateColumns = '1fr 1fr 1fr';
      }
    }

    window.addEventListener('resize', adjustEmergencyContactGrid);
    adjustEmergencyContactGrid();
  </script>
</body>

</html>