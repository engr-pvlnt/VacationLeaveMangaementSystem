<?php
session_start();
include '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}
$request_id = $_GET['id'];
$approver_id = $_SESSION['user_id'];

// Update leave request
$conn->query("UPDATE leave_requests SET status='rejected', approved_by=$approver_id, approved_date=NOW() WHERE id=$request_id");

header('Location: pending_requests.php');
?>