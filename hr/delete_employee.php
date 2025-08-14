<?php
session_start();
include '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login');
    exit();
}

$employee_id = $_GET['id'] ?? null;

if (!$employee_id) {
    header('Location: employees');
    exit();
}

// Delete employee record
$conn->query("DELETE FROM users WHERE id=$employee_id");

// Redirect back to employees list
header('Location: employees');
exit();
?>