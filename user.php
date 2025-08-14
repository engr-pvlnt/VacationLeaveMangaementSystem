<?php
session_start();
include 'config/db.php';
$hashed_password = password_hash('admin123', PASSWORD_BCRYPT);

// Now use this hash in your database insert
// Example:
$employee_id = 'EMP003';
$username = 'u1171';
$email = 'u1171@company.com';
$first_name = 'Nhed';
$last_name = 'Ocampo';
$department_id = 1;
$role_id = 3;
$hire_date = date('Y-m-d'); // or CURDATE()

// Prepare your insert statement
$stmt = $conn->prepare("INSERT INTO users (employee_id, username, email, password, first_name, last_name, department_id, role_id, hire_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param(
    "sssssisis",
    $employee_id,
    $username,
    $email,
    $hashed_password,
    $first_name,
    $last_name,
    $department_id,
    $role_id,
    $hire_date
);
$stmt->execute();
?>