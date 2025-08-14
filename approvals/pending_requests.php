<?php
session_start();
include '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}

// For simplicity, fetch all pending requests
$result = $conn->query("SELECT lr.*, u.first_name, u.last_name, lt.name AS leave_type FROM leave_requests lr JOIN users u ON lr.user_id=u.id JOIN leave_types lt ON lr.leave_type_id=lt.id WHERE lr.status='pending'");
?>

<!DOCTYPE html>
<html>
<head>
<title>Pending Leave Requests</title>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css"/>
</head>
<body>
<div class="container mt-4">
<h2>Pending Leave Requests</h2>
<table class="table table-bordered">
<thead>
<tr>
<th>ID</th>
<th>Employee</th>
<th>Type</th>
<th>Start</th>
<th>End</th>
<th>Days</th>
<th>Actions</th>
</tr>
</thead>
<tbody>
<?php while($row = $result->fetch_assoc()): ?>
<tr>
<td><?php echo $row['id']; ?></td>
<td><?php echo $row['first_name'] . ' ' . $row['last_name']; ?></td>
<td><?php echo $row['leave_type']; ?></td>
<td><?php echo $row['start_date']; ?></td>
<td><?php echo $row['end_date']; ?></td>
<td><?php echo $row['total_days']; ?></td>
<td>
<a href="approve_request.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-success">Approve</a>
<a href="reject_request.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-danger">Reject</a>
</td>
</tr>
<?php endwhile; ?>
</tbody>
</table>
<a href="../dashboard.php" class="btn btn-secondary">Back</a>
</div>
</body>
</html>