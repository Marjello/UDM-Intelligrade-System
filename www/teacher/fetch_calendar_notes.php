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
    echo json_encode(['error' => 'Unauthorized access. Please log in as a teacher.']);
    exit();
}

$teacher_id = $_SESSION['teacher_id'];

// Get class_id from GET request (FullCalendar sends it as an extraParam)
$class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;

if ($class_id === 0) {
    echo json_encode(['error' => 'Class ID is missing or invalid.']);
    exit();
}

// Ensure database connection is established
if (!isset($conn)) {
    error_log("Database connection failed in fetch_calendar_notes.php");
    echo json_encode(['error' => 'Database connection failed.']);
    exit();
}

$notes = [];

try {
    // Prepare the SQL query to fetch calendar notes for the specific class and teacher
    $sql = "SELECT
                calendar_note_id AS id,
                calendar_note_title AS title,
                calendar_note_date AS start,
                calendar_note_description AS description,
                calendar_note_type AS type
            FROM
                class_calendar_notes
            WHERE
                class_id = ? AND teacher_id = ?";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($stmt->execute([$class_id, $teacher_id])) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // Add color based on note type
                $color = '#3788d8'; // Default blue
                switch ($row['type']) {
                    case 'activity':
                        $color = '#28a745'; // Green
                        break;
                    case 'quiz':
                        $color = '#ffc107'; // Yellow
                        break;
                    case 'exam':
                        $color = '#dc3545'; // Red
                        break;
                    case 'other':
                    default:
                        $color = '#3788d8'; // Blue
                        break;
                }
                
                $notes[] = [
                    'id' => $row['id'],
                    'title' => $row['title'],
                    'start' => $row['start'],
                    'description' => $row['description'],
                    'type' => $row['type'],
                    'backgroundColor' => $color,
                    'borderColor' => $color
                ];
            }
            echo json_encode($notes);
        } else {
            error_log("Error fetching notes: " . implode(" ", $stmt->errorInfo()));
            echo json_encode(['error' => 'Failed to fetch notes: ' . implode(" ", $stmt->errorInfo())]);
        }
    } else {
        error_log("Failed to prepare fetch statement: " . implode(" ", $conn->errorInfo()));
        echo json_encode(['error' => 'Database error preparing fetch statement.']);
    }
} catch (Exception $e) {
    error_log("Exception in fetch_calendar_notes.php: " . $e->getMessage());
    echo json_encode(['error' => 'An unexpected error occurred: ' . $e->getMessage()]);
}
?>