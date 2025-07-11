<?php
// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include authentication files
require_once '../includes/auth.php';
require_once '../config/db.php'; // Use the correct database connection

// Check if the user is logged in
if (!isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'You must be logged in to perform this action.']);
    exit();
}

$teacher_id = $_SESSION['teacher_id'];

// Attempt to detect Google Drive "My Drive" folder
$driveLetters = range('C', 'Z');
$googleDriveFolder = null;

// Normalize the folder name to support "My Drive" or "Google Drive/My Drive"
foreach ($driveLetters as $letter) {
    $basePath = $letter . ':/';
    $pathsToCheck = [
        $basePath . 'My Drive',
        $basePath . 'Google Drive/My Drive',
        $basePath . 'Google Drive' // fallback
    ];
    
    foreach ($pathsToCheck as $path) {
        if (is_dir($path)) {
            $googleDriveFolder = $path;
            break 2; // Exit both loops
        }
    }
}

if (!$googleDriveFolder) {
    // Log failed backup attempt
    date_default_timezone_set('Asia/Manila');
    $manila_now = date('Y-m-d H:i:s');
    try {
        $stmt = $conn->prepare("INSERT INTO backup_history (teacher_id, action_timestamp, action_type, file_name, status, message) VALUES (?, ?, 'export', ?, 'failed', ?)");
        $stmt->execute([$teacher_id, $manila_now, 'N/A', 'Google Drive folder not found']);
    } catch (PDOException $e) {
        error_log("Failed to log backup action: " . $e->getMessage());
    }
    
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Google Drive folder not found. Please ensure it is synced locally.']);
    exit();
}

// Ensure the backup directory exists
$backupDir = $googleDriveFolder . '/classrecorddb';
if (!is_dir($backupDir)) {
    if (!mkdir($backupDir, 0777, true)) {
        // Log failed backup attempt
        date_default_timezone_set('Asia/Manila');
        $manila_now = date('Y-m-d H:i:s');
        try {
            $stmt = $conn->prepare("INSERT INTO backup_history (teacher_id, action_timestamp, action_type, file_name, status, message) VALUES (?, ?, 'export', ?, 'failed', ?)");
            $stmt->execute([$teacher_id, $manila_now, 'N/A', 'Failed to create backup directory in Google Drive']);
        } catch (PDOException $e) {
            error_log("Failed to log backup action: " . $e->getMessage());
        }
        
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Failed to create backup directory in Google Drive.']);
        exit();
    }
}

// Create the backup file name
$fileName = 'UDM IntelliGrade Backup (' . date('Y-m-d') . ') at (' . date('h-i-s-A') . ').db';
$backupFile = $backupDir . '/' . $fileName;

// Get the database file path from db.php
$dbFile = __DIR__ . '/../database/schema.db';

// SQLite backup: Direct file copy
if (!copy($dbFile, $backupFile)) {
    $errorMessage = "Failed to copy SQLite database file: " . error_get_last()['message'];
    error_log($errorMessage);
    
    // Log failed backup attempt
    date_default_timezone_set('Asia/Manila');
    $manila_now = date('Y-m-d H:i:s');
    try {
        $stmt = $conn->prepare("INSERT INTO backup_history (teacher_id, action_timestamp, action_type, file_name, status, message) VALUES (?, ?, 'export', ?, 'failed', ?)");
        $stmt->execute([$teacher_id, $manila_now, $fileName, $errorMessage]);
    } catch (PDOException $e) {
        error_log("Failed to log backup action: " . $e->getMessage());
    }
    
    header('Content-Type: application/json');
    echo json_encode(['error' => $errorMessage]);
    exit();
}

// Log the successful backup
try {
    date_default_timezone_set('Asia/Manila');
    $manila_now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("INSERT INTO backup_history (teacher_id, action_timestamp, action_type, file_name, status, message) VALUES (?, ?, 'export', ?, 'success', ?)");
    $message = "Database successfully exported to Google Drive: " . $backupFile;
    $stmt->execute([$teacher_id, $manila_now, $fileName, $message]);
} catch (PDOException $e) {
    error_log("Failed to log backup action: " . $e->getMessage());
}

// Return success response
header('Content-Type: application/json');
echo json_encode(['success' => true, 'message' => 'Database successfully exported to Google Drive: ' . $backupFile]);
?>