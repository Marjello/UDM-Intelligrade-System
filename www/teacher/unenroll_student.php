<?php
session_start();
require_once '../config/db.php'; // This will now establish a PDO connection to SQLite
require_once '../includes/auth.php';

// Check if the user is logged in and is a teacher
if (!isLoggedIn()) {
    header("Location: ../public/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = $_POST['student_id'] ?? null;
    $class_id = $_POST['class_id'] ?? null;
    $teacher_id = $_SESSION['teacher_id']; // Get teacher ID from session

    if (!$student_id || !$class_id) {
        $_SESSION['error_message'] = "Invalid request. Missing student ID or class ID.";
        header("Location: enroll_students.php?class_id=" . urlencode($class_id));
        exit();
    }

    // Verify that the teacher has permission to modify this class's enrollments
    // This is crucial for security to prevent unauthorized deletions.
    $stmt_verify_permission = $conn->prepare("SELECT class_id FROM classes WHERE class_id = ? AND teacher_id = ?");
    $stmt_verify_permission->execute([$class_id, $teacher_id]); // Use array for execute with PDO
    $result_permission = $stmt_verify_permission->fetch(PDO::FETCH_ASSOC); // Fetch to check if a row exists

    if (!$result_permission) { // If no row is fetched, permission is denied
        $_SESSION['error_message'] = "You do not have permission to unenroll students from this class.";
        header("Location: enroll_students.php?class_id=" . urlencode($class_id));
        exit();
    }
    $stmt_verify_permission = null; // Close statement by setting to null

    // Prepare and execute the DELETE statement
    $stmt = $conn->prepare("DELETE FROM enrollments WHERE student_id = ? AND class_id = ?");
    
    if ($stmt->execute([$student_id, $class_id])) { // Use array for execute with PDO
        if ($stmt->rowCount() > 0) { // Use rowCount for affected rows with PDO
            $_SESSION['success_message'] = "Student unenrolled successfully.";
        } else {
            $_SESSION['info_message'] = "Student was not found in this class's enrollment.";
        }
    } else {
        $errorInfo = $stmt->errorInfo(); // Get error information for PDO
        $_SESSION['error_message'] = "Error unenrolling student: " . $errorInfo[2];
    }

    $stmt = null; // Close statement by setting to null
    $conn = null; // Close connection by setting to null

    // Redirect back to the enroll_students page for the same class
    header("Location: enroll_students.php?class_id=" . urlencode($class_id));
    exit();
} else {
    // If someone tries to access this page directly without a POST request
    header("Location: ../public/dashboard.php");
    exit();
}
?>