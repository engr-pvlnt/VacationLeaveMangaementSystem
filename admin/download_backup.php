<?php
// admin/download_backup.php
session_start();
include '../config/db.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login');
    exit();
}

$user_id = $_SESSION['user_id'];
$result = $conn->query("SELECT * FROM users WHERE id = $user_id");
$user = $result->fetch_assoc();

$role_id = $user['role_id'];
$result = $conn->query("SELECT name FROM roles WHERE id = $role_id");
$user_role = $result->fetch_assoc()['name'];

if ($user_role !== 'Admin') {
    header('HTTP/1.0 403 Forbidden');
    echo 'Access denied';
    exit();
}

if (!isset($_GET['file'])) {
    header('HTTP/1.0 400 Bad Request');
    echo 'File parameter required';
    exit();
}

$filename = $_GET['file'];

// Security check: only allow .sql files and prevent directory traversal
if (pathinfo($filename, PATHINFO_EXTENSION) !== 'sql' || strpos($filename, '..') !== false) {
    header('HTTP/1.0 400 Bad Request');
    echo 'Invalid file';
    exit();
}

// Get backup folder setting
function getSetting($conn, $key, $default = '') {
    $result = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = '$key'");
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc()['setting_value'];
    }
    return $default;
}

$backup_folder = getSetting($conn, 'backup_folder', '../backups/');
$filepath = $backup_folder . $filename;

// Check if file exists
if (!file_exists($filepath)) {
    header('HTTP/1.0 404 Not Found');
    echo 'File not found';
    exit();
}

// Security check: ensure file is within backup folder
$real_backup_path = realpath($backup_folder);
$real_file_path = realpath($filepath);

if (strpos($real_file_path, $real_backup_path) !== 0) {
    header('HTTP/1.0 403 Forbidden');
    echo 'Access denied';
    exit();
}

try {
    // Get file info
    $file_size = filesize($filepath);
    $file_time = filemtime($filepath);
    
    // Set headers for download
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . $file_size);
    header('Cache-Control: private, no-transform, no-store, must-revalidate');
    header('Expires: 0');
    header('Pragma: no-cache');
    
    // Clear output buffer
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Read and output file in chunks to handle large files
    $handle = fopen($filepath, 'rb');
    if ($handle === false) {
        header('HTTP/1.0 500 Internal Server Error');
        echo 'Cannot read file';
        exit();
    }
    
    while (!feof($handle)) {
        $chunk = fread($handle, 8192); // 8KB chunks
        echo $chunk;
        flush();
    }
    
    fclose($handle);
    
} catch (Exception $e) {
    header('HTTP/1.0 500 Internal Server Error');
    echo 'Download failed: ' . $e->getMessage();
}
?>