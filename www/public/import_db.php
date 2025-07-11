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
    echo json_encode(['status' => 'error', 'message' => 'Not logged in.']);
    exit();
}

$teacher_id = $_SESSION['teacher_id']; // Get teacher ID from session for logging

$status = 'error'; // Default status for JSON response
$message = 'An unknown error occurred.'; // Default message for JSON response
$uploadedFileName = 'N/A'; // Default for logging if file name isn't available

date_default_timezone_set('Asia/Manila');
$manila_now = date('Y-m-d H:i:s');

if (isset($_FILES['sql_file']) && $_FILES['sql_file']['error'] === UPLOAD_ERR_OK) {
    $uploadedFileName = basename($_FILES['sql_file']['name']);
    $filename_tmp = $_FILES['sql_file']['tmp_name'];
    $destinationDir = 'uploads/';
    $destination = $destinationDir . $uploadedFileName;

    // Create uploads directory if it doesn't exist
    if (!is_dir($destinationDir)) {
        if (!mkdir($destinationDir, 0777, true)) {
            $message = 'Failed to create uploads directory.';
            // Log failed import attempt
            try {
                $stmt = $conn->prepare("INSERT INTO backup_history (teacher_id, action_timestamp, action_type, file_name, status, message) VALUES (?, ?, 'import', ?, 'failed', ?)");
                $stmt->execute([$teacher_id, $manila_now, $uploadedFileName, $message]);
            } catch (PDOException $e) {
                error_log("Failed to log backup action: " . $e->getMessage());
            }
            echo json_encode(['status' => 'error', 'message' => $message]);
            exit();
        }
    }

    // Move uploaded file to a temporary location
    if (move_uploaded_file($filename_tmp, $destination)) {
        // Import logic for SQLite: Replace the existing database file with the uploaded one
        if (pathinfo($uploadedFileName, PATHINFO_EXTENSION) === 'db') {
            try {
                // Close current connection to the database file before overwriting it
                $conn = null; 
                
                // Get the database file path from db.php
                $dbFile = __DIR__ . '/../database/schema.db';
                
                // Overwrite the main database file with the uploaded backup
                if (copy($destination, $dbFile)) {
                    $status = 'success';
                    $message = 'Database imported successfully. The system will now reload.';
                } else {
                    $message = 'Import failed: Could not replace the database file. Check file permissions.';
                }
            } catch (Exception $e) {
                $message = 'Import failed: ' . $e->getMessage();
                error_log("SQLite Import Error: " . $e->getMessage());
            }
        } else {
            $message = 'Import failed: The uploaded file is not a .db database file.';
        }

        // Clean up the uploaded file after import attempt
        if (file_exists($destination)) {
            unlink($destination);
        }

    } else {
        $message = 'Failed to move uploaded file.';
    }
} else {
    // Handle specific upload errors for better logging
    switch ($_FILES['sql_file']['error'] ?? -1) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            $message = 'Uploaded file exceeds max file size.';
            break;
        case UPLOAD_ERR_PARTIAL:
            $message = 'File upload was only partially completed.';
            break;
        case UPLOAD_ERR_NO_FILE:
            $message = 'No file was uploaded.';
            break;
        case UPLOAD_ERR_NO_TMP_DIR:
            $message = 'Missing a temporary folder.';
            break;
        case UPLOAD_ERR_CANT_WRITE:
            $message = 'Failed to write file to disk.';
            break;
        case UPLOAD_ERR_EXTENSION:
            $message = 'A PHP extension stopped the file upload.';
            break;
        default:
            $message = 'No file selected or an unknown file upload error occurred.';
            break;
    }
}

// Re-establish connection for logging if it was closed
if ($conn === null) {
    require_once '../config/db.php';
}

// Log the final status of the import attempt
if ($conn !== null) {
    try {
        $stmt = $conn->prepare("INSERT INTO backup_history (teacher_id, action_timestamp, action_type, file_name, status, message) VALUES (?, ?, 'import', ?, ?, ?)");
        $stmt->execute([$teacher_id, $manila_now, $uploadedFileName, $status, $message]);
        error_log("Import logged successfully: teacher_id=$teacher_id, file=$uploadedFileName, status=$status");
    } catch (PDOException $e) {
        error_log("Failed to log backup action: " . $e->getMessage());
    }
} else {
    error_log("Cannot log backup action: DB connection is null.");
}

// Output the JSON response
echo json_encode(['status' => $status, 'message' => $message]);

// Close the PDO connection if it's still open
$conn = null;
?>