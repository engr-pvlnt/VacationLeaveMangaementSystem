<?php
session_start();
include '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login');
    exit();
}

$result = $conn->query("SELECT u.*, d.name AS department, r.name AS role FROM users u LEFT JOIN departments d ON u.department_id=d.id LEFT JOIN roles r ON u.role_id=r.id WHERE u.role_id != 1"); // Exclude admin
?>

<!DOCTYPE html>
<html>
<head>
<title>Employees</title>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css"/>
</head>
<body>
<div class="container mt-4">
<h2>Employee List</h2>
<a href="add_employee" class="btn btn-primary mb-3">Add Employee</a>
<table class="table table-bordered">
<thead>
<tr>
<th>ID</th>
<th>Name</th>
<th>Employee ID</th>
<th>Email</th>
<th>Department</th>
<th>Role</th>
<th>Status</th>
<th>Actions</th>
</tr>
</thead>
<tbody>
<?php while($emp = $result->fetch_assoc()): ?>
<tr>
<td><?php echo $emp['id']; ?></td>
<td><?php echo $emp['first_name'] . ' ' . $emp['last_name']; ?></td>
<td><?php echo $emp['employee_id']; ?></td>
<td><?php echo $emp['email']; ?></td>
<td><?php echo $emp['department']; ?></td>
<td><?php echo $emp['role']; ?></td>
<td><?php echo ucfirst($emp['status']); ?></td>
<td>
<a href="edit_employee?id=<?php echo $emp['id']; ?>" class="btn btn-sm btn-info">Edit</a>
<a href="delete_employee?id=<?php echo $emp['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?')">Delete</a>
</td>
</tr>
<?php endwhile; ?>
</tbody>
</table>
<a href="../dashboard" class="btn btn-secondary">Back</a>
</div>
</body>
</html>