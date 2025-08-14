<?php
session_start();
include '../config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login');
    exit();
}

// Fetch roles and departments for dropdowns
$roles_result = $conn->query("SELECT * FROM roles WHERE status='active'");
$departments_result = $conn->query("SELECT * FROM departments WHERE status='active'");

$roles = [];
while ($row = $roles_result->fetch_assoc()) {
    $roles[] = $row;
}

$departments = [];
while ($row = $departments_result->fetch_assoc()) {
    $departments[] = $row;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Collect form data
    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $employee_id = $_POST['employee_id'];
    $email = $_POST['email'];
    $password = $_POST['password'];
    $role_id = $_POST['role_id'];
    $department_id = $_POST['department_id'];
    $status = $_POST['status'];

    // Hash password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Insert into users table
    $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, employee_id, email, password, role_id, department_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssiii", $first_name, $last_name, $employee_id, $email, $hashed_password, $role_id, $department_id, $status);
    $stmt->execute();

    // Redirect to employees list
    header('Location: employees');
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Add Employee</title>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css"/>
</head>
<body>
<div class="container mt-4">
<h2>Add New Employee</h2>
<form method="POST" action="">
  <div class="form-group">
    <label for="first_name">First Name</label>
    <input type="text" class="form-control" id="first_name" name="first_name" required>
  </div>
  <div class="form-group">
    <label for="last_name">Last Name</label>
    <input type="text" class="form-control" id="last_name" name="last_name" required>
  </div>
  <div class="form-group">
    <label for="employee_id">Employee ID</label>
    <input type="text" class="form-control" id="employee_id" name="employee_id" required>
  </div>
  <div class="form-group">
    <label for="email">Email</label>
    <input type="email" class="form-control" id="email" name="email" required>
  </div>
  <div class="form-group">
    <label for="password">Password</label>
    <input type="password" class="form-control" id="password" name="password" required>
  </div>
  <div class="form-group">
    <label for="role_id">Role</label>
    <select class="form-control" id="role_id" name="role_id" required>
      <option value="">Select Role</option>
      <?php foreach ($roles as $role): ?>
        <option value="<?php echo $role['id']; ?>"><?php echo $role['name']; ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group">
    <label for="department_id">Department</label>
    <select class="form-control" id="department_id" name="department_id" required>
      <option value="">Select Department</option>
      <?php foreach ($departments as $dept): ?>
        <option value="<?php echo $dept['id']; ?>"><?php echo $dept['name']; ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group">
    <label for="status">Status</label>
    <select class="form-control" id="status" name="status" required>
      <option value="active">Active</option>
      <option value="inactive">Inactive</option>
    </select>
  </div>
  <button type="submit" class="btn btn-primary">Add Employee</button>
  <a href="employees" class="btn btn-secondary">Cancel</a>
</form>
</div>
</body>
</html>