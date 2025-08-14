// approve_request.php
<?php
session_start();
include '../config/db.php';

if (!isset($_SESSION['user_id'])) { header('Location: ../auth/login.php'); exit(); }

$request_id = $_GET['id'];
$approver_id = $_SESSION['user_id'];

// Update request status to approved
$conn->query("UPDATE leave_requests SET status='approved', approved_by=$approver_id, approved_date=NOW() WHERE id=$request_id");

// Redirect back
header('Location: pending_approvals.php');
?>