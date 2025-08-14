<?php
// admin/list_backups.php
session_start();
include '../config/db.php';

header('Content-Type: application/json');

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$user_id = $_SESSION['user_id'];
$result = $conn->query("SELECT * FROM users WHERE id = $user_id");
$user = $result->fetch_assoc();

$role_id = $user['role_id'];
$result = $conn->query("SELECT name FROM roles WHERE id = $role_id");
$user_role = $result->fetch_assoc()['name'];

if ($user_role !== 'Admin') {
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
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

// Check if backup folder exists
if (!file_exists($backup_folder)) {
    echo json_encode(['success' => false, 'message' => 'Backup folder does not exist']);
    exit();
}

try {
    $backups = [];
    $files = scandir($backup_folder);
    
    foreach ($files as $file) {
        if ($file != '.' && $file != '..' && pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
            $filepath = $backup_folder . $file;
            $filesize = filesize($filepath);
            $filedate = filemtime($filepath);
            
            $backups[] = [
                'name' => $file,
                'size' => formatBytes($filesize),
                'date' => date('M j, Y \a\t g:i A', $filedate),
                'timestamp' => $filedate
            ];
        }
    }
    
    // Sort by date (newest first)
    usort($backups, function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });
    
    echo json_encode([
        'success' => true,
        'backups' => $backups
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error listing backups: ' . $e->getMessage()]);
}

function formatBytes($size, $precision = 2) {
    if ($size === 0) return '0 B';
    
    $base = log($size, 1024);
    $suffixes = array('B', 'KB', 'MB', 'GB', 'TB');
    return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)];
}
?>