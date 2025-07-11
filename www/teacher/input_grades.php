<?php
// File: input_grades.php

session_start();
require_once '../config/db.php';
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    header("Location: ../public/login.php");
    exit();
}

if (!isset($_GET['class_id'])) {
    die("Class ID is required.");
}

$class_id = $_GET['class_id'];
$teacher_id = $_SESSION['teacher_id'];

// Fetch class and grading type
$class_stmt = $conn->prepare("SELECT * FROM classes WHERE class_id = ? AND teacher_id = ?");
$class_stmt->execute([$class_id, $teacher_id]);
$class = $class_stmt->fetch();

if (!$class) {
    die("Class not found or access denied.");
}

$grading_type = $class['grading_system_type'];

if ($grading_type === 'numerical') {
    include 'input_grades_numerical.php';
} elseif ($grading_type === 'final_only_numerical' || $grading_type === 'attendance' || $grading_type === 'A/NA') {
    // Modified: If grading_system_type is 'final_only_numerical', 'attendance', or 'A/NA' (for A/NA based grades),
    // it will include input_grades_final_only.php.
    include 'input_grades_final_only.php';
} else {
    // This message will now only show if grading_system_type is none of the expected values.
    echo "Invalid grading system type: " . htmlspecialchars($grading_type) . ". Please check your class configuration.";
}
?>