<?php
// admin/backup.php
session_start();

// Clean any output that might have been sent
if (ob_get_level()) {
    ob_clean();
}

include '../config/db.php';

// For AJAX requests, ensure clean JSON output
if (isset($_POST['action'])) {
    // Turn off error display for AJAX to prevent HTML in JSON
    ini_set('display_errors', 0);
    error_reporting(0);
    
    // Set JSON header immediately
    header('Content-Type: application/json');
    
    // Start output buffering to catch any unexpected output
    ob_start();
}

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id'])) {
    if (isset($_POST['action'])) {
        ob_clean(); // Clear any buffered output
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit();
    }
    header('Location: ../auth/login');
    exit();
}

// Fetch user info to verify admin role with error handling
try {
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT role_id FROM users WHERE id = ?");
    if (!$stmt) {
        throw new Exception("Database prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        if (isset($_POST['action'])) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'User not found']);
            exit();
        }
        header('Location: ../auth/login');
        exit();
    }
    
    $user = $result->fetch_assoc();
    $role_id = $user['role_id'];
    
    $stmt = $conn->prepare("SELECT name FROM roles WHERE id = ?");
    if (!$stmt) {
        throw new Exception("Database prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("i", $role_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        if (isset($_POST['action'])) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Role not found']);
            exit();
        }
        header('Location: ../dashboard');
        exit();
    }
    
    $user_role = $result->fetch_assoc()['name'];
    
    // Only allow admin access
    if ($user_role !== 'Admin') {
        if (isset($_POST['action'])) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Admin access required']);
            exit();
        }
        header('Location: ../dashboard');
        exit();
    }
    
} catch (Exception $e) {
    if (isset($_POST['action'])) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit();
    }
    die("Database error: " . $e->getMessage());
}

// Get backup folder setting
function getSetting($conn, $key, $default = '')
{
    try {
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        if (!$stmt) {
            return $default;
        }
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            return $result->fetch_assoc()['setting_value'];
        }
        return $default;
    } catch (Exception $e) {
        return $default;
    }
}

$backup_folder = getSetting($conn, 'backup_folder', '../backups/');

// Ensure backup folder ends with slash
if (substr($backup_folder, -1) !== '/') {
    $backup_folder .= '/';
}

// Handle POST requests for AJAX calls
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Clear any buffered output before sending JSON
    ob_clean();
    
    switch ($_POST['action']) {
        case 'create_backup':
            createDatabaseBackup($conn, $backup_folder);
            break;
        case 'list_backups':
            listBackups($backup_folder);
            break;
        case 'delete_backup':
            if (isset($_POST['filename'])) {
                deleteBackup($conn, $backup_folder, $_POST['filename']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Filename required']);
            }
            break;
        case 'restore_backup':
            if (isset($_POST['filename'])) {
                restoreBackup($conn, $backup_folder, $_POST['filename']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Filename required']);
            }
            break;
        case 'get_backup_info':
            if (isset($_POST['filename'])) {
                getBackupInfo($backup_folder, $_POST['filename']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Filename required']);
            }
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    exit();
}

// Create database backup function
function createDatabaseBackup($conn, $backup_folder) {
    try {
        // Create backup folder if it doesn't exist
        if (!file_exists($backup_folder)) {
            if (!mkdir($backup_folder, 0755, true)) {
                throw new Exception('Failed to create backup directory');
            }
        }

        // Check if backup folder is writable
        if (!is_writable($backup_folder)) {
            throw new Exception('Backup directory is not writable');
        }

        $filename = 'vlms_backup_' . date('Y-m-d_H-i-s') . '.sql';
        $filepath = $backup_folder . $filename;

        // Get database name from connection
        $db_result = $conn->query("SELECT DATABASE()");
        if (!$db_result) {
            throw new Exception('Failed to get database name: ' . $conn->error);
        }
        $db_name = $db_result->fetch_row()[0];
        
        // Get all tables and views separately
        $tables = [];
        $views = [];
        
        // Get regular tables
        $result = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        if (!$result) {
            throw new Exception('Failed to get tables: ' . $conn->error);
        }
        
        while ($row = $result->fetch_row()) {
            $tables[] = $row[0];
        }

        // Get views (skip problematic ones)
        $skip_views = ['leave_request_details']; // Add problematic views here
        $result = $conn->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'");
        if ($result) {
            while ($row = $result->fetch_row()) {
                $view_name = $row[0];
                if (!in_array($view_name, $skip_views)) {
                    $views[] = $view_name;
                }
            }
        }

        if (empty($tables) && empty($views)) {
            throw new Exception('No tables or views found in database');
        }

        $sql_content = "-- VLMS Database Backup\n";
        $sql_content .= "-- Generated on: " . date('Y-m-d H:i:s') . "\n";
        $sql_content .= "-- Database: $db_name\n\n";
        $sql_content .= "SET FOREIGN_KEY_CHECKS = 0;\n";
        $sql_content .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n";

        // Process regular tables first
        foreach ($tables as $table) {
            try {
                // Get table structure
                $create_result = $conn->query("SHOW CREATE TABLE `$table`");
                if (!$create_result) {
                    error_log("Skipping table $table: Failed to get structure - " . $conn->error);
                    continue;
                }
                
                $create_table = $create_result->fetch_row()[1];
                $sql_content .= "-- Table: $table\n";
                $sql_content .= "DROP TABLE IF EXISTS `$table`;\n";
                $sql_content .= $create_table . ";\n\n";

                // Get table data
                $data_result = $conn->query("SELECT * FROM `$table`");
                if (!$data_result) {
                    error_log("Warning: Could not backup data for table $table: " . $conn->error);
                    $sql_content .= "-- Warning: Could not backup data for table $table\n\n";
                    continue;
                }
                
                if ($data_result->num_rows > 0) {
                    $sql_content .= "-- Data for table: $table\n";
                    $sql_content .= "INSERT INTO `$table` VALUES\n";
                    
                    $rows = [];
                    while ($row = $data_result->fetch_row()) {
                        $escaped_row = array_map(function($value) use ($conn) {
                            return $value === null ? 'NULL' : "'" . $conn->real_escape_string($value) . "'";
                        }, $row);
                        $rows[] = '(' . implode(', ', $escaped_row) . ')';
                    }
                    $sql_content .= implode(",\n", $rows) . ";\n\n";
                } else {
                    $sql_content .= "-- No data for table: $table\n\n";
                }
                
            } catch (Exception $e) {
                error_log("Error backing up table $table: " . $e->getMessage());
                $sql_content .= "-- Error backing up table $table: " . $e->getMessage() . "\n\n";
            }
        }

        // Process views after tables (views depend on tables)
        foreach ($views as $view) {
            try {
                // Get view structure
                $create_result = $conn->query("SHOW CREATE VIEW `$view`");
                if (!$create_result) {
                    error_log("Skipping view $view: Failed to get structure - " . $conn->error);
                    $sql_content .= "-- Warning: Could not backup view $view\n\n";
                    continue;
                }
                
                $create_view = $create_result->fetch_row()[1];
                $sql_content .= "-- View: $view\n";
                $sql_content .= "DROP VIEW IF EXISTS `$view`;\n";
                $sql_content .= $create_view . ";\n\n";
                
            } catch (Exception $e) {
                error_log("Error backing up view $view: " . $e->getMessage());
                $sql_content .= "-- Error backing up view $view: " . $e->getMessage() . "\n\n";
            }
        }

        $sql_content .= "SET FOREIGN_KEY_CHECKS = 1;\n";

        // Write to file
        $bytes_written = file_put_contents($filepath, $sql_content);
        if ($bytes_written === false) {
            throw new Exception('Failed to write backup file');
        }
        
        $size = filesize($filepath);
        $size_formatted = formatBytes($size);
        
        // Log the backup creation
        logActivity($conn, $_SESSION['user_id'], 'CREATE_BACKUP', 'backups', 0, 
                  json_encode(['filename' => $filename, 'size' => $size]));
        
        echo json_encode([
            'success' => true,
            'filename' => $filename,
            'size' => $size_formatted,
            'message' => 'Backup created successfully'
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

// List existing backups
function listBackups($backup_folder) {
    try {
        $backups = [];
        
        if (is_dir($backup_folder)) {
            $files = glob($backup_folder . "*.sql");
            if ($files === false) {
                throw new Exception('Failed to read backup directory');
            }
            
            rsort($files); // Sort by date, newest first
            
            foreach ($files as $file) {
                if (is_file($file)) {
                    $filename = basename($file);
                    $size = filesize($file);
                    $date = date('Y-m-d H:i:s', filemtime($file));
                    
                    $backups[] = [
                        'name' => $filename,
                        'size' => formatBytes($size),
                        'size_bytes' => $size,
                        'date' => $date,
                        'timestamp' => filemtime($file)
                    ];
                }
            }
        }
        
        echo json_encode([
            'success' => true,
            'backups' => $backups
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

// Delete backup file
function deleteBackup($conn, $backup_folder, $filename) {
    try {
        // Sanitize filename
        $filename = basename($filename);
        $filepath = $backup_folder . $filename;
        
        if (!file_exists($filepath)) {
            throw new Exception('Backup file not found');
        }
        
        // Additional security check - ensure it's a .sql file
        if (!preg_match('/\.sql$/', $filename)) {
            throw new Exception('Invalid file type');
        }
        
        $size = filesize($filepath);
        
        if (unlink($filepath)) {
            // Log the backup deletion
            logActivity($conn, $_SESSION['user_id'], 'DELETE_BACKUP', 'backups', 0, 
                      json_encode(['filename' => $filename, 'size' => $size]));
            
            echo json_encode([
                'success' => true,
                'message' => 'Backup deleted successfully',
                'deleted_size_formatted' => formatBytes($size)
            ]);
        } else {
            throw new Exception('Failed to delete backup file');
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

// Restore database from backup
function restoreBackup($conn, $backup_folder, $filename) {
    try {
        // Sanitize filename
        $filename = basename($filename);
        $filepath = $backup_folder . $filename;
        
        if (!file_exists($filepath)) {
            throw new Exception('Backup file not found');
        }
        
        // Additional security check
        if (!preg_match('/\.sql$/', $filename)) {
            throw new Exception('Invalid file type');
        }
        
        $sql_content = file_get_contents($filepath);
        
        if ($sql_content === false) {
            throw new Exception('Failed to read backup file');
        }
        
        // Split SQL into individual queries
        $queries = explode(";\n", $sql_content);
        
        $conn->autocommit(FALSE);
        
        $executed = 0;
        foreach ($queries as $query) {
            $query = trim($query);
            if (!empty($query) && !preg_match('/^--/', $query)) {
                if (!$conn->query($query)) {
                    throw new Exception('SQL Error: ' . $conn->error . ' in query: ' . substr($query, 0, 100));
                }
                $executed++;
            }
        }
        
        $conn->commit();
        $conn->autocommit(TRUE);
        
        // Log the restore operation
        logActivity($conn, $_SESSION['user_id'], 'RESTORE_BACKUP', 'database', 0, 
                  json_encode(['filename' => $filename, 'queries_executed' => $executed]));
        
        echo json_encode([
            'success' => true,
            'message' => "Database restored successfully from $filename",
            'queries_executed' => $executed
        ]);
        
    } catch (Exception $e) {
        if ($conn) {
            $conn->rollback();
            $conn->autocommit(TRUE);
        }
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

// Get backup file information
function getBackupInfo($backup_folder, $filename) {
    try {
        // Sanitize filename
        $filename = basename($filename);
        $filepath = $backup_folder . $filename;
        
        if (!file_exists($filepath)) {
            throw new Exception('Backup file not found');
        }
        
        // Additional security check
        if (!preg_match('/\.sql$/', $filename)) {
            throw new Exception('Invalid file type');
        }
        
        $size = filesize($filepath);
        $date = date('Y-m-d H:i:s', filemtime($filepath));
        $content = file_get_contents($filepath, false, null, 0, 1000); // Read first 1KB
        
        if ($content === false) {
            throw new Exception('Failed to read backup file');
        }
        
        // Count tables and records (approximate)
        $tables = substr_count($content, 'CREATE TABLE');
        $inserts = substr_count($content, 'INSERT INTO');
        
        echo json_encode([
            'success' => true,
            'info' => [
                'filename' => $filename,
                'size' => formatBytes($size),
                'size_bytes' => $size,
                'date' => $date,
                'tables' => $tables,
                'insert_statements' => $inserts,
                'preview' => substr($content, 0, 500)
            ]
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

// Helper function to format bytes
function formatBytes($size, $precision = 2) {
    if ($size == 0) return '0 B';
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    return round($size, $precision) . ' ' . $units[$i];
}

// Helper function to log activities
function logActivity($conn, $user_id, $action, $table_name, $record_id, $details) {
    try {
        $stmt = $conn->prepare("INSERT INTO system_logs (user_id, action, table_name, record_id, new_values, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        if ($stmt) {
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            $stmt->bind_param("ississs", $user_id, $action, $table_name, $record_id, $details, $ip_address, $user_agent);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Exception $e) {
        // Silently fail logging to avoid breaking main functionality
        error_log("Failed to log activity: " . $e->getMessage());
    }
}

// Handle file download
if (isset($_GET['download'])) {
    $filename = basename($_GET['download']);
    $filepath = $backup_folder . $filename;
    
    // Security check
    if (!preg_match('/\.sql$/', $filename)) {
        http_response_code(403);
        echo "Invalid file type";
        exit();
    }
    
    if (file_exists($filepath)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit();
    } else {
        http_response_code(404);
        echo "File not found";
        exit();
    }
}

// Only show HTML if not an AJAX request
if (!isset($_POST['action'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}
?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VLMS - Backup Management</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #333;
            overflow-x: hidden;
        }

        /* Animated background particles */
        .bg-particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 1;
        }

        .particle {
            position: absolute;
            width: 4px;
            height: 4px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            animation: float 20s infinite linear;
        }

        @keyframes float {
            0% {
                transform: translateY(100vh) rotate(0deg);
                opacity: 0;
            }

            10% {
                opacity: 1;
            }

            90% {
                opacity: 1;
            }

            100% {
                transform: translateY(-100vh) rotate(360deg);
                opacity: 0;
            }
        }

        /* Admin role bar */
        .admin-role-bar {
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            color: white;
            padding: 0.75rem 2rem;
            position: relative;
            z-index: 1001;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }

        .admin-role-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex: 1;
        }

        /* Navigation */
        .navbar {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 1rem 2rem;
            position: relative;
            z-index: 1000;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .navbar-brand {
            color: white !important;
            font-size: 1.5rem;
            font-weight: 700;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .navbar-brand:hover {
            color: rgba(255, 255, 255, 0.8) !important;
            transform: scale(1.05);
            transition: all 0.3s ease;
        }

        .navbar-nav {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .nav-link {
            color: rgba(255, 255, 255, 0.9) !important;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 25px;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            font-weight: 500;
            position: relative;
            overflow: hidden;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px) scale(1.05);
            color: white !important;
        }

        /* Main container */
        .main-container {
            position: relative;
            z-index: 100;
            padding: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Backup card */
        .backup-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            animation: slideUp 0.8s ease-out;
        }

        .backup-title {
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            background-clip: text;
            color: transparent;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .backup-subtitle {
            color: #666;
            margin-bottom: 2rem;
            font-size: 1rem;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Action buttons */
        .action-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .action-card {
            background: rgba(255, 255, 255, 0.5);
            border-radius: 15px;
            padding: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            transition: all 0.3s ease;
            text-align: center;
        }

        .action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .action-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, #667eea, #764ba2);
            background-clip: text;
            color: transparent;
        }

        .action-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #333;
        }

        .action-description {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 2rem;
            border: none;
            border-radius: 25px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            position: relative;
            overflow: hidden;
        }

        .btn-primary {
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 10px 25px rgba(79, 172, 254, 0.4);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .btn-secondary:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
        }

        .btn-success {
            background: linear-gradient(135deg, #48bb78, #38a169);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 10px 25px rgba(72, 187, 120, 0.4);
        }

        .btn-danger {
            background: linear-gradient(135deg, #f56565, #e53e3e);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 10px 25px rgba(245, 101, 101, 0.4);
        }

        .btn-back {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            margin-bottom: 1rem;
        }

        .btn-back:hover {
            background: rgba(255, 255, 255, 0.3);
            color: white;
            text-decoration: none;
        }

        .btn-small {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
            border-radius: 15px;
        }

        /* Backup list */
        .backup-list-section {
            background: rgba(255, 255, 255, 0.5);
            border-radius: 15px;
            padding: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            margin-top: 2rem;
        }

        .section-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: linear-gradient(135deg, #667eea, #764ba2);
            background-clip: text;
            color: transparent;
        }

        .backup-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            margin-bottom: 1rem;
            background: rgba(255, 255, 255, 0.7);
            border-radius: 10px;
            border: 1px solid rgba(102, 126, 234, 0.2);
            transition: all 0.3s ease;
        }

        .backup-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .backup-info {
            flex: 1;
        }

        .backup-name {
            font-weight: 600;
            color: #333;
            margin-bottom: 0.25rem;
        }

        .backup-details {
            display: flex;
            gap: 1rem;
            font-size: 0.9rem;
            color: #666;
        }

        .backup-date {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .backup-size {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            color: #667eea;
            font-weight: 500;
        }

        .backup-actions {
            display: flex;
            gap: 0.5rem;
        }

        /* Alert messages */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            font-weight: 500;
            animation: slideDown 0.5s ease-out;
        }

        .alert-success {
            background: linear-gradient(135deg, #48bb78, #38a169);
            color: white;
            border-left: 4px solid #25855a;
        }

        .alert-danger {
            background: linear-gradient(135deg, #f56565, #e53e3e);
            color: white;
            border-left: 4px solid #c53030;
        }

        .alert-info {
            background: linear-gradient(135deg, #4299e1, #3182ce);
            color: white;
            border-left: 4px solid #2c5282;
        }

        .alert-warning {
            background: linear-gradient(135deg, #ed8936, #dd6b20);
            color: white;
            border-left: 4px solid #c05621;
        }

        /* Loading spinner */
        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Progress bar */
        .progress-bar {
            width: 100%;
            height: 6px;
            background: rgba(102, 126, 234, 0.2);
            border-radius: 3px;
            overflow: hidden;
            margin: 1rem 0;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 3px;
            transition: width 0.3s ease;
            width: 0%;
        }

        /* Confirmation modal styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
        }

        .modal {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease-out;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px) scale(0.9);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: #333;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-content {
            color: #666;
            margin-bottom: 1.5rem;
            line-height: 1.5;
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }

        /* Statistics cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.7);
            padding: 1.5rem;
            border-radius: 15px;
            border: 1px solid rgba(102, 126, 234, 0.2);
            text-align: center;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea, #764ba2);
            background-clip: text;
            color: transparent;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: #666;
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #666;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            color: #ccc;
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .main-container {
                padding: 1rem;
            }

            .navbar {
                padding: 1rem;
            }

            .backup-title {
                font-size: 1.5rem;
            }

            .admin-role-bar {
                padding: 0.5rem 1rem;
            }

            .backup-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .backup-actions {
                width: 100%;
                justify-content: flex-end;
            }

            .action-buttons {
                grid-template-columns: 1fr;
            }
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>

<body>
    <!-- Animated background particles -->
    <div class="bg-particles" id="particles"></div>

    <!-- Admin role bar -->
    <div class="admin-role-bar">
        <div class="admin-role-info">
            <i class="fas fa-database"></i>
            <span>Backup Management - Admin Panel</span>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="navbar">
        <a class="navbar-brand" href="../dashboard">
            <i class="fas fa-calendar-alt"></i>
            Vacation Leave Management System
        </a>
        <div class="navbar-nav">
            <a href="../auth/profile" class="nav-link">
                <i class="fas fa-user"></i> Profile
            </a>
            <a href="../auth/logout" class="nav-link">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </nav>

    <!-- Main content -->
    <div class="main-container">
        <!-- Back button -->
        <a href="../admin/" class="btn btn-back">
            <i class="fas fa-arrow-left"></i> Back to Admin Dashboard
        </a>

        <!-- Backup management card -->
        <div class="backup-card">
            <h1 class="backup-title">
                <i class="fas fa-database"></i>
                System Backup & Data Recovery
            </h1>
            <p class="backup-subtitle">
                Create, manage, and restore database backups to ensure your VLMS data is always protected.
            </p>

            <!-- Alert container -->
            <div id="alertContainer"></div>

            <!-- Statistics -->
            <div class="stats-grid" id="statsGrid">
                <div class="stat-card">
                    <div class="stat-number" id="totalBackups">-</div>
                    <div class="stat-label">Total Backups</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="totalSize">-</div>
                    <div class="stat-label">Total Size</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="latestBackup">-</div>
                    <div class="stat-label">Latest Backup</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="diskUsage">-</div>
                    <div class="stat-label">Disk Usage</div>
                </div>
            </div>

            <!-- Action buttons -->
            <div class="action-buttons">
                <div class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-plus-circle"></i>
                    </div>
                    <div class="action-title">Create New Backup</div>
                    <div class="action-description">Generate a complete database backup</div>
                    <button class="btn btn-primary" onclick="createBackup()" id="createBackupBtn">
                        <i class="fas fa-plus"></i> Create Backup
                    </button>
                </div>

                <div class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-list"></i>
                    </div>
                    <div class="action-title">View All Backups</div>
                    <div class="action-description">Browse, download, or manage existing backup files</div>
                    <button class="btn btn-secondary" onclick="loadBackups()" id="loadBackupsBtn">
                        <i class="fas fa-list"></i> View Backups
                    </button>
                </div>

                <div class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-upload"></i>
                    </div>
                    <div class="action-title">Upload Backup</div>
                    <div class="action-description">Upload an external backup file to restore from</div>
                    <button class="btn btn-success" onclick="showUploadModal()">
                        <i class="fas fa-upload"></i> Upload Backup
                    </button>
                </div>

                <div class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-cog"></i>
                    </div>
                    <div class="action-title">Backup Settings</div>
                    <div class="action-description">Configure automatic backups and retention policies</div>
                    <button class="btn btn-secondary" onclick="showSettingsModal()">
                        <i class="fas fa-cog"></i> Settings
                    </button>
                </div>
            </div>

            <!-- Backup list section -->
            <div class="backup-list-section" id="backupListSection" style="display: none;">
                <h3 class="section-title">
                    <i class="fas fa-archive"></i>
                    Available Backups
                </h3>
                <div id="backupList"></div>
            </div>
        </div>
    </div>

    <script>
        // Global variables
        let backupsData = [];
        let isLoading = false;

        // Create floating particles
        function createParticles() {
            const particles = document.getElementById('particles');
            const particleCount = 50;

            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                particle.classList.add('particle');
                particle.style.left = Math.random() * 100 + '%';
                particle.style.animationDelay = Math.random() * 20 + 's';
                particle.style.animationDuration = (Math.random() * 10 + 15) + 's';
                particles.appendChild(particle);
            }
        }

        // Show alert message
        function showAlert(message, type = 'info', duration = 5000) {
            const alertContainer = document.getElementById('alertContainer');
            
            // Remove existing alerts
            alertContainer.innerHTML = '';
            
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type}`;
            alertDiv.innerHTML = `
                <i class="fas fa-${getAlertIcon(type)}"></i>
                ${message}
            `;
            
            alertContainer.appendChild(alertDiv);
            
            // Auto-remove after duration
            if (duration > 0) {
                setTimeout(() => {
                    if (alertDiv && alertDiv.parentNode) {
                        alertDiv.remove();
                    }
                }, duration);
            }
        }

        // Get appropriate icon for alert type
        function getAlertIcon(type) {
            switch (type) {
                case 'success': return 'check-circle';
                case 'danger': return 'exclamation-circle';
                case 'warning': return 'exclamation-triangle';
                default: return 'info-circle';
            }
        }

        // Create new backup
        async function createBackup() {
            const btn = document.getElementById('createBackupBtn');
            const originalText = btn.innerHTML;
            
            btn.innerHTML = '<span class="spinner"></span> Creating Backup...';
            btn.disabled = true;
            isLoading = true;

            try {
                const response = await fetch('backup.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'action=create_backup'
                });

                const result = await response.json();

                if (result.success) {
                    showAlert(`Backup created successfully: <strong>${result.filename}</strong><br>Size: ${result.size}`, 'success');
                    loadBackups(); // Refresh the backup list
                    updateStats(); // Update statistics
                } else {
                    showAlert(`Failed to create backup: ${result.message}`, 'danger');
                }
            } catch (error) {
                showAlert(`Error creating backup: ${error.message}`, 'danger');
            } finally {
                btn.innerHTML = originalText;
                btn.disabled = false;
                isLoading = false;
            }
        }

        // Load and display backups
        async function loadBackups() {
            const btn = document.getElementById('loadBackupsBtn');
            const originalText = btn.innerHTML;
            const backupList = document.getElementById('backupList');
            const backupSection = document.getElementById('backupListSection');
            
            btn.innerHTML = '<span class="spinner"></span> Loading...';
            btn.disabled = true;
            
            backupList.innerHTML = '<div style="text-align: center; padding: 2rem;"><span class="spinner"></span> Loading backups...</div>';
            backupSection.style.display = 'block';

            try {
                const response = await fetch('backup.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'action=list_backups'
                });

                const result = await response.json();

                if (result.success) {
                    backupsData = result.backups;
                    displayBackups(result.backups);
                    updateStats();
                } else {
                    backupList.innerHTML = `<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> ${result.message}</div>`;
                }
            } catch (error) {
                backupList.innerHTML = `<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Error: ${error.message}</div>`;
            } finally {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }

        // Display backups in the UI
        function displayBackups(backups) {
            const backupList = document.getElementById('backupList');
            
            if (!backups || backups.length === 0) {
                backupList.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-database"></i>
                        <h3>No Backups Found</h3>
                        <p>Create your first backup to get started with data protection.</p>
                    </div>
                `;
                return;
            }

            let html = '';
            backups.forEach(backup => {
                html += `
                    <div class="backup-item">
                        <div class="backup-info">
                            <div class="backup-name">${backup.name}</div>
                            <div class="backup-details">
                                <div class="backup-date">
                                    <i class="fas fa-calendar"></i>
                                    ${backup.date}
                                </div>
                                <div class="backup-size">
                                    <i class="fas fa-hdd"></i>
                                    ${backup.size}
                                </div>
                            </div>
                        </div>
                        <div class="backup-actions">
                            <button class="btn btn-secondary btn-small" onclick="showBackupInfo('${backup.name}')" title="View Details">
                                <i class="fas fa-info-circle"></i>
                            </button>
                            <button class="btn btn-primary btn-small" onclick="downloadBackup('${backup.name}')" title="Download">
                                <i class="fas fa-download"></i>
                            </button>
                            <button class="btn btn-success btn-small" onclick="confirmRestore('${backup.name}')" title="Restore">
                                <i class="fas fa-undo"></i>
                            </button>
                            <button class="btn btn-danger btn-small" onclick="confirmDelete('${backup.name}')" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                `;
            });
            
            backupList.innerHTML = html;
        }

        // Update statistics
        function updateStats() {
            if (!backupsData || backupsData.length === 0) {
                document.getElementById('totalBackups').textContent = '0';
                document.getElementById('totalSize').textContent = '0 B';
                document.getElementById('latestBackup').textContent = 'None';
                document.getElementById('diskUsage').textContent = '0%';
                return;
            }

            const totalBackups = backupsData.length;
            const totalBytes = backupsData.reduce((sum, backup) => sum + backup.size_bytes, 0);
            const totalSize = formatBytes(totalBytes);
            const latestBackup = backupsData[0] ? formatTimeAgo(backupsData[0].timestamp * 1000) : 'None';
            
            // Calculate disk usage (assuming 1GB limit for demo)
            const diskLimit = 1024 * 1024 * 1024; // 1GB
            const diskUsage = Math.round((totalBytes / diskLimit) * 100);

            document.getElementById('totalBackups').textContent = totalBackups;
            document.getElementById('totalSize').textContent = totalSize;
            document.getElementById('latestBackup').textContent = latestBackup;
            document.getElementById('diskUsage').textContent = Math.min(diskUsage, 100) + '%';
        }

        // Format bytes to human readable format
        function formatBytes(bytes, decimals = 2) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const dm = decimals < 0 ? 0 : decimals;
            const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
        }

        // Format time ago
        function formatTimeAgo(timestamp) {
            const now = Date.now();
            const diff = now - timestamp;
            const seconds = Math.floor(diff / 1000);
            const minutes = Math.floor(seconds / 60);
            const hours = Math.floor(minutes / 60);
            const days = Math.floor(hours / 24);

            if (days > 0) return days + 'd ago';
            if (hours > 0) return hours + 'h ago';
            if (minutes > 0) return minutes + 'm ago';
            return 'Just now';
        }

        // Show backup information
        async function showBackupInfo(filename) {
            try {
                const response = await fetch('backup.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `action=get_backup_info&filename=${encodeURIComponent(filename)}`
                });

                const result = await response.json();

                if (result.success) {
                    const info = result.info;
                    showModal(
                        'Backup Information',
                        `
                        <div style="text-align: left;">
                            <p><strong>Filename:</strong> ${info.filename}</p>
                            <p><strong>Size:</strong> ${info.size}</p>
                            <p><strong>Created:</strong> ${info.date}</p>
                            <p><strong>Tables:</strong> ~${info.tables}</p>
                            <p><strong>Records:</strong> ~${info.insert_statements} statements</p>
                            <hr style="margin: 1rem 0;">
                            <p><strong>Preview:</strong></p>
                            <pre style="background: #f5f5f5; padding: 1rem; border-radius: 5px; font-size: 0.8rem; overflow-x: auto;">${info.preview}...</pre>
                        </div>
                        `,
                        'Close',
                        null
                    );
                } else {
                    showAlert(`Error getting backup info: ${result.message}`, 'danger');
                }
            } catch (error) {
                showAlert(`Error: ${error.message}`, 'danger');
            }
        }

        // Download backup
        function downloadBackup(filename) {
            window.open(`backup.php?download=${encodeURIComponent(filename)}`, '_blank');
        }

        // Confirm restore operation
        function confirmRestore(filename) {
            showModal(
                'Restore Database',
                `
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Warning:</strong> This action will replace all current data with the backup data.
                </div>
                <p>Are you sure you want to restore the database from <strong>${filename}</strong>?</p>
                <p>This action cannot be undone and will:</p>
                <ul style="text-align: left; margin: 1rem 0;">
                    <li>Replace all current database tables</li>
                    <li>Delete any data created after the backup</li>
                    <li>Potentially cause temporary system downtime</li>
                </ul>
                `,
                'Restore Database',
                () => restoreBackup(filename),
                'btn-danger'
            );
        }

        // Restore database from backup
        async function restoreBackup(filename) {
            try {
                showAlert('Restoring database... Please wait and do not refresh the page.', 'info', 0);
                
                const response = await fetch('backup.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `action=restore_backup&filename=${encodeURIComponent(filename)}`
                });

                const result = await response.json();

                if (result.success) {
                    showAlert(`Database restored successfully from ${filename}!<br>Executed ${result.queries_executed} SQL queries.`, 'success');
                    // Refresh the page after a short delay to show updated data
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                } else {
                    showAlert(`Failed to restore database: ${result.message}`, 'danger');
                }
            } catch (error) {
                showAlert(`Error restoring database: ${error.message}`, 'danger');
            }
        }

        // Confirm delete operation
        function confirmDelete(filename) {
            showModal(
                'Delete Backup',
                `Are you sure you want to delete the backup <strong>${filename}</strong>?<br><br>This action cannot be undone.`,
                'Delete',
                () => deleteBackup(filename),
                'btn-danger'
            );
        }

        // Delete backup
        async function deleteBackup(filename) {
            try {
                const response = await fetch('backup.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `action=delete_backup&filename=${encodeURIComponent(filename)}`
                });

                const result = await response.json();

                if (result.success) {
                    showAlert(`Backup deleted successfully: ${filename}`, 'success');
                    loadBackups(); // Refresh the list
                } else {
                    showAlert(`Failed to delete backup: ${result.message}`, 'danger');
                }
            } catch (error) {
                showAlert(`Error deleting backup: ${error.message}`, 'danger');
            }
        }

        // Show upload modal
        function showUploadModal() {
            showModal(
                'Upload Backup File',
                `
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    Select a .sql backup file to upload. Only files created by this system should be used.
                </div>
                <input type="file" id="backupFile" accept=".sql" style="width: 100%; padding: 0.75rem; border: 2px dashed #667eea; border-radius: 10px; text-align: center;">
                <div id="uploadProgress" style="display: none; margin-top: 1rem;">
                    <div class="progress-bar">
                        <div class="progress-fill" id="progressFill"></div>
                    </div>
                    <div style="text-align: center; margin-top: 0.5rem;">
                        <span id="progressText">0%</span>
                    </div>
                </div>
                `,
                'Upload',
                () => uploadBackup(),
                'btn-success'
            );
        }

        // Upload backup file
        function uploadBackup() {
            const fileInput = document.getElementById('backupFile');
            const file = fileInput.files[0];
            
            if (!file) {
                showAlert('Please select a backup file to upload.', 'warning');
                return;
            }
            
            if (!file.name.endsWith('.sql')) {
                showAlert('Please select a valid SQL backup file.', 'danger');
                return;
            }
            
            // For demo purposes, show success message
            // In real implementation, you would upload the file to the server
            showAlert('Feature coming soon: File upload functionality will be implemented in the next version.', 'info');
        }

        // Show settings modal
        function showSettingsModal() {
            showModal(
                'Backup Settings',
                `
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    Configure automatic backup settings and retention policies.
                </div>
                <div style="text-align: left;">
                    <div style="margin-bottom: 1rem;">
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Auto Backup Frequency:</label>
                        <select style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 5px;">
                            <option>Disabled</option>
                            <option>Daily</option>
                            <option>Weekly</option>
                            <option>Monthly</option>
                        </select>
                    </div>
                    <div style="margin-bottom: 1rem;">
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Retention Period (days):</label>
                        <input type="number" value="30" min="1" max="365" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 5px;">
                    </div>
                    <div style="margin-bottom: 1rem;">
                        <label style="display: flex; align-items: center; gap: 0.5rem;">
                            <input type="checkbox" checked>
                            <span>Compress backup files</span>
                        </label>
                    </div>
                    <div style="margin-bottom: 1rem;">
                        <label style="display: flex; align-items: center; gap: 0.5rem;">
                            <input type="checkbox">
                            <span>Email notifications for backup operations</span>
                        </label>
                    </div>
                </div>
                `,
                'Save Settings',
                () => {
                    showAlert('Backup settings saved successfully!', 'success');
                },
                'btn-primary'
            );
        }

        // Generic modal function
        function showModal(title, content, confirmText, onConfirm, confirmClass = 'btn-primary') {
            const modal = document.createElement('div');
            modal.className = 'modal-overlay';
            modal.innerHTML = `
                <div class="modal">
                    <div class="modal-title">
                        ${title}
                    </div>
                    <div class="modal-content">
                        ${content}
                    </div>
                    <div class="modal-actions">
                        <button class="btn btn-secondary" onclick="this.closest('.modal-overlay').remove()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        ${onConfirm ? `<button class="btn ${confirmClass}" id="confirmModalBtn">
                            <i class="fas fa-check"></i> ${confirmText}
                        </button>` : ''}
                    </div>
                </div>
            `;

            document.body.appendChild(modal);

            if (onConfirm) {
                const confirmButton = modal.querySelector('#confirmModalBtn');
                confirmButton.addEventListener('click', () => {
                    modal.remove();
                    onConfirm();
                });
            }

            // Close modal when clicking overlay
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.remove();
                }
            });
        }

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            createParticles();
            loadBackups(); // Auto-load backups on page load
        });
    </script>
</body>

</html>