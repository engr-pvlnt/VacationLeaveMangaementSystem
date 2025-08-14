<?php
// admin/create_backup.php - IMPROVED VERSION

// Prevent any output before JSON
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors to prevent HTML output

session_start();
include '../config/db.php';

// Clean any output buffer and set JSON header
ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

// Function to send JSON response and exit
function sendJsonResponse($success, $message, $extra_data = []) {
    $response = array_merge(['success' => $success, 'message' => $message], $extra_data);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
}

// Function to log errors
function logError($message) {
    error_log("[Create Backup] " . $message);
}

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id'])) {
    sendJsonResponse(false, 'Unauthorized access');
}

$user_id = intval($_SESSION['user_id']);
if ($user_id <= 0) {
    sendJsonResponse(false, 'Invalid user session');
}

// Verify user exists and get role using prepared statements
$stmt = $conn->prepare("SELECT u.id, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
if (!$stmt) {
    logError("Failed to prepare user query: " . $conn->error);
    sendJsonResponse(false, 'Database error');
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    sendJsonResponse(false, 'User not found');
}

if ($user['role_name'] !== 'Admin') {
    sendJsonResponse(false, 'Admin access required');
}

// Improved getSetting function with prepared statements
function getSetting($conn, $key, $default = '') {
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    if (!$stmt) {
        return $default;
    }
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $result = $stmt->get_result();
    $value = $default;
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $value = $row['setting_value'];
    }
    $stmt->close();
    return $value;
}

// Format bytes function
function formatBytes($size, $precision = 2) {
    if ($size == 0) return '0 B';
    $base = log($size, 1024);
    $suffixes = array('B', 'KB', 'MB', 'GB', 'TB');
    return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)];
}

try {
    // Get backup folder setting
    $backup_folder = getSetting($conn, 'backup_folder', '../backups/');
    $backup_folder = rtrim($backup_folder, '/\\') . DIRECTORY_SEPARATOR;

    // Ensure backup folder exists
    if (!file_exists($backup_folder)) {
        if (!mkdir($backup_folder, 0755, true)) {
            logError("Could not create backup folder: $backup_folder");
            sendJsonResponse(false, 'Could not create backup folder');
        }
    }

    // Check if folder is writable
    if (!is_writable($backup_folder)) {
        logError("Backup folder is not writable: $backup_folder");
        sendJsonResponse(false, 'Backup folder is not writable');
    }

    // Get database name safely
    $db_name = '';
    $result = $conn->query("SELECT DATABASE()");
    if ($result) {
        $row = $result->fetch_row();
        $db_name = $row[0];
    }
    
    if (empty($db_name)) {
        logError("Could not determine database name");
        sendJsonResponse(false, 'Could not determine database name');
    }
    
    // Generate backup filename with better timestamp
    $timestamp = date('Y-m-d_H-i-s');
    $filename = "vlms_backup_{$timestamp}.sql";
    $filepath = $backup_folder . $filename;
    
    // Check if file already exists (unlikely but possible)
    if (file_exists($filepath)) {
        $timestamp = date('Y-m-d_H-i-s') . '_' . uniqid();
        $filename = "vlms_backup_{$timestamp}.sql";
        $filepath = $backup_folder . $filename;
    }
    
    // Start building SQL dump
    $sql_dump = "-- VLMS Database Backup\n";
    $sql_dump .= "-- Generated on: " . date('Y-m-d H:i:s') . "\n";
    $sql_dump .= "-- Database: {$db_name}\n";
    $sql_dump .= "-- Created by: User ID {$user_id}\n\n";
    
    $sql_dump .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
    $sql_dump .= "SET AUTOCOMMIT = 0;\n";
    $sql_dump .= "START TRANSACTION;\n";
    $sql_dump .= "SET time_zone = \"+00:00\";\n";
    $sql_dump .= "SET NAMES utf8mb4;\n\n";
    
    // Get all tables
    $tables_result = $conn->query("SHOW TABLES");
    if (!$tables_result) {
        logError("Could not get table list: " . $conn->error);
        sendJsonResponse(false, 'Could not retrieve database tables');
    }
    
    $tables = [];
    while ($row = $tables_result->fetch_row()) {
        $tables[] = $row[0];
    }
    
    if (empty($tables)) {
        logError("No tables found in database");
        sendJsonResponse(false, 'No tables found in database');
    }
    
    // Process each table
    foreach ($tables as $table) {
        // Escape table name
        $escaped_table = "`" . str_replace("`", "``", $table) . "`";
        
        // Add table structure
        $sql_dump .= "\n-- --------------------------------------------------------\n";
        $sql_dump .= "-- Table structure for table {$escaped_table}\n";
        $sql_dump .= "-- --------------------------------------------------------\n\n";
        
        $sql_dump .= "DROP TABLE IF EXISTS {$escaped_table};\n";
        
        // Get table structure
        $create_table_result = $conn->query("SHOW CREATE TABLE {$escaped_table}");
        if (!$create_table_result) {
            logError("Could not get table structure for $table: " . $conn->error);
            continue;
        }
        
        $create_table_row = $create_table_result->fetch_row();
        $sql_dump .= $create_table_row[1] . ";\n\n";
        
        // Add table data
        $sql_dump .= "-- Dumping data for table {$escaped_table}\n\n";
        
        // Get row count first
        $count_result = $conn->query("SELECT COUNT(*) FROM {$escaped_table}");
        $row_count = 0;
        if ($count_result) {
            $count_row = $count_result->fetch_row();
            $row_count = intval($count_row[0]);
        }
        
        if ($row_count > 0) {
            // Get column information
            $columns_result = $conn->query("SHOW COLUMNS FROM {$escaped_table}");
            if (!$columns_result) {
                logError("Could not get columns for $table: " . $conn->error);
                continue;
            }
            
            $columns = [];
            while ($column = $columns_result->fetch_assoc()) {
                $columns[] = "`" . str_replace("`", "``", $column['Field']) . "`";
            }
            
            if (empty($columns)) {
                continue;
            }
            
            // Get table data
            $data_result = $conn->query("SELECT * FROM {$escaped_table}");
            if (!$data_result) {
                logError("Could not get data for $table: " . $conn->error);
                continue;
            }
            
            if ($data_result->num_rows > 0) {
                $sql_dump .= "INSERT INTO {$escaped_table} (" . implode(', ', $columns) . ") VALUES\n";
                
                $rows = [];
                while ($row = $data_result->fetch_assoc()) {
                    $values = [];
                    foreach ($row as $value) {
                        if ($value === null) {
                            $values[] = 'NULL';
                        } else {
                            $values[] = "'" . $conn->real_escape_string($value) . "'";
                        }
                    }
                    $rows[] = '(' . implode(', ', $values) . ')';
                }
                
                $sql_dump .= implode(",\n", $rows) . ";\n\n";
            }
        } else {
            $sql_dump .= "-- No data to dump for this table\n\n";
        }
    }
    
    $sql_dump .= "COMMIT;\n";
    $sql_dump .= "\n-- End of backup\n";
    
    // Write to file
    $bytes_written = file_put_contents($filepath, $sql_dump);
    if ($bytes_written === false) {
        logError("Could not write backup file: $filepath");
        sendJsonResponse(false, 'Could not write backup file');
    }
    
    // Verify file was created and has content
    if (!file_exists($filepath) || filesize($filepath) == 0) {
        logError("Backup file was not created properly: $filepath");
        sendJsonResponse(false, 'Backup file was not created properly');
    }
    
    // Get file size
    $file_size = filesize($filepath);
    $file_size_formatted = formatBytes($file_size);
    
    // Log backup creation in system settings
    $backup_log_stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, created_at, updated_at) 
                                      VALUES ('last_backup_date', ?, NOW(), NOW()) 
                                      ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");
    
    $current_datetime = date('Y-m-d H:i:s');
    if ($backup_log_stmt) {
        $backup_log_stmt->bind_param("ss", $current_datetime, $current_datetime);
        $backup_log_stmt->execute();
        $backup_log_stmt->close();
    }
    
    
    // Send success response
    sendJsonResponse(true, "Database backup created successfully", [
        'filename' => $filename,
        'size' => $file_size_formatted,
        'file_size_bytes' => $file_size,
        'tables_count' => count($tables),
        'created_at' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    logError("Exception in backup creation: " . $e->getMessage());
    sendJsonResponse(false, 'Backup failed: An unexpected error occurred');
} catch (Error $e) {
    logError("Fatal error in backup creation: " . $e->getMessage());
    sendJsonResponse(false, 'Backup failed: A system error occurred');
}
?>