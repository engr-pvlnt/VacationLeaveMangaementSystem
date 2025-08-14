<?php
// config/db.php
$conn = new mysqli('localhost', 'root', '', 'leave_management_system');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>

<script>
  document.addEventListener('contextmenu', event => event.preventDefault());
</script>