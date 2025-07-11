<?php
// Start session to access teacher_id
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database connection
require_once '../config/db.php';
require_once '../includes/auth.php'; // Include auth for isLoggedIn check

// Set header to return JSON content
header('Content-Type: application/json');

// Check if the user is logged in as a teacher
if (!isLoggedIn() || !isset($_SESSION['teacher_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please log in as a teacher.']);
    exit();
}

$teacher_id = $_SESSION['teacher_id'];

// Get the raw POST data
$input = file_get_contents('php://input');
$data = json_decode($input, true); // Decode JSON data

// Check if JSON decoding was successful
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON data received.', 'error_code' => json_last_error_msg()]);
    exit();
}

$action = $data['action'] ?? ''; // 'create', 'update', 'delete'

// Ensure database connection is established
if (!isset($conn)) {
    error_log("Database connection failed in handle_calendar_note.php");
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    exit();
}

try {
    switch ($action) {
        case 'create':
            $class_id = (int)($data['class_id'] ?? 0);
            $note_date = $data['note_date'] ?? '';
            $note_title = trim($data['note_title'] ?? '');
            $note_description = trim($data['note_description'] ?? '');
            $note_type = $data['note_type'] ?? 'other';

            if ($class_id === 0 || empty($note_date) || empty($note_title)) {
                echo json_encode(['status' => 'error', 'message' => 'Missing required fields for creating a note.']);
                exit();
            }

            $sql = "INSERT INTO class_calendar_notes (class_id, teacher_id, calendar_note_date, calendar_note_title, calendar_note_description, calendar_note_type)
                    VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);

            if ($stmt) {
                if ($stmt->execute([$class_id, $teacher_id, $note_date, $note_title, $note_description, $note_type])) {
                    echo json_encode(['status' => 'success', 'message' => 'Note created successfully!', 'note_id' => $conn->lastInsertId()]);
                } else {
                    error_log("Error creating note: " . implode(" ", $stmt->errorInfo()));
                    echo json_encode(['status' => 'error', 'message' => 'Failed to create note: ' . implode(" ", $stmt->errorInfo())]);
                }
            } else {
                error_log("Failed to prepare create statement: " . implode(" ", $conn->errorInfo()));
                echo json_encode(['status' => 'error', 'message' => 'Database error preparing create statement.']);
            }
            break;

        case 'update':
            $note_id = (int)($data['note_id'] ?? 0);
            $note_date = $data['note_date'] ?? '';
            $note_title = trim($data['note_title'] ?? '');
            $note_description = trim($data['note_description'] ?? '');
            $note_type = $data['note_type'] ?? 'other';

            if ($note_id === 0 || empty($note_date) || empty($note_title)) {
                echo json_encode(['status' => 'error', 'message' => 'Missing required fields for updating a note.']);
                exit();
            }

            // Ensure the teacher owns this note before updating
            $sql_check_owner = "SELECT teacher_id FROM class_calendar_notes WHERE calendar_note_id = ?";
            $stmt_check = $conn->prepare($sql_check_owner);
            if (!$stmt_check) {
                error_log("Failed to prepare owner check statement: " . implode(" ", $conn->errorInfo()));
                echo json_encode(['status' => 'error', 'message' => 'Database error checking note ownership.']);
                exit();
            }
            
            $stmt_check->execute([$note_id]);
            $row = $stmt_check->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['teacher_id'] != $teacher_id) {
                echo json_encode(['status' => 'error', 'message' => 'Unauthorized: You do not own this note.']);
                exit();
            }

            $sql = "UPDATE class_calendar_notes
                    SET calendar_note_date = ?, calendar_note_title = ?, calendar_note_description = ?, calendar_note_type = ?
                    WHERE calendar_note_id = ? AND teacher_id = ?";
            $stmt = $conn->prepare($sql);

            if ($stmt) {
                if ($stmt->execute([$note_date, $note_title, $note_description, $note_type, $note_id, $teacher_id])) {
                    if ($stmt->rowCount() > 0) {
                        echo json_encode(['status' => 'success', 'message' => 'Note updated successfully!']);
                    } else {
                        echo json_encode(['status' => 'info', 'message' => 'Note found but no changes made, or you do not own this note.']);
                    }
                } else {
                    error_log("Error updating note: " . implode(" ", $stmt->errorInfo()));
                    echo json_encode(['status' => 'error', 'message' => 'Failed to update note: ' . implode(" ", $stmt->errorInfo())]);
                }
            } else {
                error_log("Failed to prepare update statement: " . implode(" ", $conn->errorInfo()));
                echo json_encode(['status' => 'error', 'message' => 'Database error preparing update statement.']);
            }
            break;

        case 'delete':
            $note_id = (int)($data['note_id'] ?? 0);

            if ($note_id === 0) {
                echo json_encode(['status' => 'error', 'message' => 'Note ID is missing for deletion.']);
                exit();
            }

            $sql = "DELETE FROM class_calendar_notes WHERE calendar_note_id = ? AND teacher_id = ?";
            $stmt = $conn->prepare($sql);

            if ($stmt) {
                if ($stmt->execute([$note_id, $teacher_id])) {
                    if ($stmt->rowCount() > 0) {
                        echo json_encode(['status' => 'success', 'message' => 'Note deleted successfully!']);
                    } else {
                        echo json_encode(['status' => 'info', 'message' => 'Note not found or you do not own this note.']);
                    }
                } else {
                    error_log("Error deleting note: " . implode(" ", $stmt->errorInfo()));
                    echo json_encode(['status' => 'error', 'message' => 'Failed to delete note: ' . implode(" ", $stmt->errorInfo())]);
                }
            } else {
                error_log("Failed to prepare delete statement: " . implode(" ", $conn->errorInfo()));
                echo json_encode(['status' => 'error', 'message' => 'Database error preparing delete statement.']);
            }
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action specified.']);
            break;
    }
} catch (Exception $e) {
    error_log("Exception in handle_calendar_note.php: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'An unexpected error occurred: ' . $e->getMessage()]);
}
?>