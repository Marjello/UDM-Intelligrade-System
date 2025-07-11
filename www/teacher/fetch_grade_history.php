<?php
require_once '../config/db.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['error' => 'Not authenticated.']);
    exit();
}

$teacher_id = $_SESSION['teacher_id'];
$enrollment_id = filter_input(INPUT_GET, 'enrollment_id', FILTER_SANITIZE_NUMBER_INT);
$class_id = filter_input(INPUT_GET, 'class_id', FILTER_SANITIZE_NUMBER_INT);

if (!$enrollment_id || !$class_id) {
    echo json_encode(['error' => 'Missing enrollment ID or class ID.']);
    exit();
}

// Verify that the teacher has permission to access this class and student's grades
// This is a crucial security check.
$stmt = $conn->prepare("SELECT COUNT(*) FROM enrollments e
                        JOIN classes c ON e.class_id = c.class_id
                        WHERE e.enrollment_id = ? AND c.class_id = ? AND c.teacher_id = ?");
$stmt->bindValue(1, $enrollment_id, SQLITE3_INTEGER);
$stmt->bindValue(2, $class_id, SQLITE3_INTEGER);
$stmt->bindValue(3, $teacher_id, SQLITE3_INTEGER);
$result = $stmt->execute();
$is_authorized = $result->fetchArray()[0] > 0;
$stmt->close();

if (!$is_authorized) {
    echo json_encode(['error' => 'Unauthorized access to student or class data.']);
    exit();
}

// Fetch grade history for the specific enrollment and class
$stmt = $conn->prepare("SELECT
                            gh.old_value,
                            gh.new_value,
                            gh.change_timestamp,
                            gc.component_name
                        FROM grade_history gh
                        JOIN grade_components gc ON gh.component_id = gc.component_id
                        WHERE gh.enrollment_id = ? AND gh.class_id = ?
                        ORDER BY gh.change_timestamp DESC");
$stmt->bindValue(1, $enrollment_id, SQLITE3_INTEGER);
$stmt->bindValue(2, $class_id, SQLITE3_INTEGER);
$result = $stmt->execute();

$grade_history = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $grade_history[] = $row;
}
$stmt->close();

echo json_encode($grade_history);
?>