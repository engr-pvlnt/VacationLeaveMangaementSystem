<?php
// admin/delete_backup.php - IMPROVED VERSION

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
    error_log("[Delete Backup] " . $message);
}

try {
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        sendJsonResponse(false, 'Unauthorized access');
    }

    $user_id = intval($_SESSION['user_id']);
    if ($user_id <= 0) {
        sendJsonResponse(false, 'Invalid user session');
    }

    // Verify user exists and get role
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

    // Check admin role
    if ($user['role_name'] !== 'Admin') {
        sendJsonResponse(false, 'Admin access required');
    }

    // Check if POST request and filename provided
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(false, 'POST request required');
    }

    if (!isset($_POST['filename']) || empty(trim($_POST['filename']))) {
        sendJsonResponse(false, 'Filename required');
    }

    $filename = trim($_POST['filename']);

    // Enhanced filename validation
    if (!preg_match('/^[a-zA-Z0-9_\-\.]+\.sql$/', $filename)) {
        sendJsonResponse(false, 'Invalid filename format');
    }

    // Security checks - prevent directory traversal
    $dangerous_patterns = ['..', '/', '\\', '<', '>', '|', ':', '*', '?', '"'];
    foreach ($dangerous_patterns as $pattern) {
        if (strpos($filename, $pattern) !== false) {
            sendJsonResponse(false, 'Invalid characters in filename');
        }
    }

    // Improved getSetting function
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

    $backup_folder = getSetting($conn, 'backup_folder', '../backups/');

    // Normalize backup folder path
    $backup_folder = rtrim($backup_folder, '/\\') . DIRECTORY_SEPARATOR;

    // Create backup directory if it doesn't exist
    if (!is_dir($backup_folder)) {
        if (!mkdir($backup_folder, 0755, true)) {
            logError("Failed to create backup directory: $backup_folder");
            sendJsonResponse(false, 'Backup directory does not exist');
        }
    }

    $filepath = $backup_folder . $filename;

    // Enhanced path validation using realpath
    $real_backup_path = realpath($backup_folder);
    if (!$real_backup_path) {
        logError("Invalid backup directory path: $backup_folder");
        sendJsonResponse(false, 'Invalid backup directory');
    }

    // Check if file exists first
    if (!file_exists($filepath)) {
        sendJsonResponse(false, 'Backup file not found');
    }

    $real_file_path = realpath($filepath);
    if (!$real_file_path) {
        logError("Invalid file path: $filepath");
        sendJsonResponse(false, 'Invalid file path');
    }

    // Security check - ensure file is within backup directory
    if (strpos($real_file_path, $real_backup_path) !== 0) {
        logError("Path traversal attempt: $real_file_path not in $real_backup_path");
        sendJsonResponse(false, 'File is outside backup directory');
    }

    // Check file permissions
    if (!is_readable($filepath)) {
        sendJsonResponse(false, 'Cannot access backup file');
    }

    if (!is_writable($filepath)) {
        sendJsonResponse(false, 'No permission to delete backup file');
    }

    // Get file size before deletion
    $file_size = filesize($filepath);
    if ($file_size === false) {
        $file_size = 0;
    }

    // Attempt to delete the file
    if (unlink($filepath)) {
        // Log successful deletion
        logError("Backup deleted successfully: $filename by user ID: $user_id (Size: $file_size bytes)");
        
        sendJsonResponse(true, "✅ Backup file '$filename' has been permanently deleted from the server", [
            'deleted_size' => $file_size,
            'deleted_size_formatted' => number_format($file_size / 1024, 2) . ' KB'
        ]);
    } else {
        // Get last error
        $error = error_get_last();
        $error_msg = $error ? $error['message'] : 'Unknown error';
        logError("Failed to delete backup $filename: $error_msg");
        sendJsonResponse(false, 'Failed to delete backup file');
    }

} catch (Exception $e) {
    logError("Exception in delete backup: " . $e->getMessage());
    sendJsonResponse(false, 'An unexpected error occurred');
} catch (Error $e) {
    logError("Fatal error in delete backup: " . $e->getMessage());
    sendJsonResponse(false, 'A system error occurred');
}
?>

<!-- JavaScript Frontend Function -->
<script>
async function performDeleteBackup(filename) {
    // Input validation
    if (!filename || typeof filename !== 'string' || filename.trim() === '') {
        showAlert('Invalid filename provided', 'danger');
        return;
    }

    // Show confirmation dialog
    if (!confirm(`Are you sure you want to delete backup "${filename}"? This action cannot be undone.`)) {
        return;
    }

    // Show loading state
    const loadingAlert = showAlert('Deleting backup...', 'info');

    try {
        // Create FormData for better handling
        const formData = new FormData();
        formData.append('filename', filename.trim());

        const response = await fetch('delete_backup.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest' // Indicates AJAX request
            }
        });

        // Hide loading alert
        if (loadingAlert && loadingAlert.remove) {
            loadingAlert.remove();
        }

        // Check if response is OK
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status} - ${response.statusText}`);
        }

        // Check content type to ensure it's JSON
        const contentType = response.headers.get('Content-Type');
        if (!contentType || !contentType.includes('application/json')) {
            const text = await response.text();
            console.error('Non-JSON response:', text);
            
            // Check if it's an HTML error page
            if (text.includes('<html>') || text.includes('<!DOCTYPE')) {
                throw new Error('Server returned an error page instead of JSON. Check server logs.');
            }
            
            throw new Error(`Invalid response format. Expected JSON, got: ${contentType}`);
        }

        const result = await response.json();

        if (result.success) {
            // Show success message with file size info if available
            let message = `🗑️ Backup "${filename}" has been successfully deleted`;
            if (result.deleted_size_formatted) {
                message += ` (Freed up ${result.deleted_size_formatted} of storage space)`;
            }
            
            showAlert(message, 'success');
            
            // Refresh the backup list
            if (typeof viewBackups === 'function') {
                viewBackups();
            }
            
            // Optional: Update any file count or size totals
            if (typeof updateBackupStats === 'function') {
                updateBackupStats();
            }
            
        } else {
            showAlert(`Failed to delete backup: ${result.message}`, 'danger');
        }

    } catch (error) {
        console.error('Delete backup error:', error);
        
        // Hide loading alert if still showing
        if (loadingAlert && loadingAlert.remove) {
            loadingAlert.remove();
        }
        
        // Show user-friendly error message
        let errorMessage = 'Error deleting backup';
        if (error.message.includes('NetworkError') || error.message.includes('Failed to fetch')) {
            errorMessage += ': Network connection problem';
        } else if (error.message.includes('JSON')) {
            errorMessage += ': Server response error';
        } else {
            errorMessage += `: ${error.message}`;
        }
        
        showAlert(errorMessage, 'danger');
    }
}

// Helper function to show alerts (implement based on your UI framework)
function showAlert(message, type = 'info') {
    // Example implementation - adapt to your alert system
    console.log(`[${type.toUpperCase()}] ${message}`);
    
    // If using Bootstrap alerts:
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    // Add to alert container
    const container = document.getElementById('alert-container') || document.body;
    container.appendChild(alertDiv);
    
    // Auto-remove after 5 seconds for success/info alerts
    if (type === 'success' || type === 'info') {
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.remove();
            }
        }, 5000);
    }
    
    return alertDiv;
}

// Optional: Batch delete function
async function deleteMultipleBackups(filenames) {
    if (!Array.isArray(filenames) || filenames.length === 0) {
        showAlert('No files selected for deletion', 'warning');
        return;
    }

    if (!confirm(`Are you sure you want to delete ${filenames.length} backup(s)? This action cannot be undone.`)) {
        return;
    }

    let successCount = 0;
    let errorCount = 0;

    for (const filename of filenames) {
        try {
            await performDeleteBackup(filename);
            successCount++;
        } catch (error) {
            errorCount++;
            console.error(`Failed to delete ${filename}:`, error);
        }
    }

    if (successCount > 0) {
        showAlert(`✅ Successfully deleted ${successCount} backup file(s) from the server`, 'success');
    }
    if (errorCount > 0) {
        showAlert(`❌ Failed to delete ${errorCount} backup file(s)`, 'danger');
    }
}
</script>