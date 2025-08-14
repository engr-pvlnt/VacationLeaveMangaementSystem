<?php
// index.php - Login check and role-based redirection
session_start();
include 'config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    // User is not logged in, redirect to login page
    header('Location: auth/login');
    exit();
}

// User is logged in, get user information
$user_id = $_SESSION['user_id'];

// Prepare statement to prevent SQL injection
$stmt = $conn->prepare("SELECT role_id FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    $role_id = $user['role_id'];
    
    // Role-based redirection
    if ($role_id == 1) {
        // Admin role - redirect to admin dashboard
        header('Location: admin/');
        exit();
    } else {
        // All other roles - redirect to employee dashboard
        header('Location: employee/');
        exit();
    }
} else {
    // User not found in database, destroy session and redirect to login
    session_destroy();
    header('Location: auth/login');
    exit();
}

$stmt->close();
$conn->close();
?>