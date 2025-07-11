<?php
require_once '../config/db.php';
require_once '../includes/auth.php';

// --- Helper Functions ---

/** Logs grade changes to the history table. */
function log_history($conn, $class_id, $enrollment_id, $teacher_id, $grade_type, $old_value, $new_value) {
    $stmt = $conn->prepare("INSERT INTO grade_history (class_id, enrollment_id, teacher_id, grade_type, old_value, new_value) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bindParam(1, $class_id, PDO::PARAM_INT);
    $stmt->bindParam(2, $enrollment_id, PDO::PARAM_INT);
    $stmt->bindParam(3, $teacher_id, PDO::PARAM_INT);
    $stmt->bindParam(4, $grade_type, PDO::PARAM_STR);
    $stmt->bindParam(5, $old_value, PDO::PARAM_STR);
    $stmt->bindParam(6, $new_value, PDO::PARAM_STR);
    $stmt->execute();
    $stmt->closeCursor();
}

/** Updates or inserts student attendance, logging changes. */
function update_attendance($conn, $enrollment_id, $component_id, $new_status, $class_id, $teacher_id, $period) {
    error_log("update_attendance called - enrollment_id: $enrollment_id, component_id: $component_id, new_status: $new_status, period: $period");
    
    $old_prelim_status = null;
    $old_midterm_status = null;

    // Fetch existing attendance statuses for this enrollment_id and component_id from student_grades table
    $stmt_old_data = $conn->prepare("SELECT attendance_status_prelim, attendance_status_midterm FROM student_grades WHERE enrollment_id = ? AND component_id = ?");
    $stmt_old_data->bindParam(1, $enrollment_id, PDO::PARAM_INT);
    $stmt_old_data->bindParam(2, $component_id, PDO::PARAM_INT);
    $stmt_old_data->execute();
    if ($row = $stmt_old_data->fetch(PDO::FETCH_ASSOC)) {
        $old_prelim_status = $row['attendance_status_prelim'];
        $old_midterm_status = $row['attendance_status_midterm'];
    }
    $stmt_old_data->closeCursor();

    error_log("Old values - prelim: " . ($old_prelim_status ?? 'null') . ", midterm: " . ($old_midterm_status ?? 'null'));

    $current_prelim_status = $old_prelim_status;
    $current_midterm_status = $old_midterm_status;
    $grade_type_for_log = '';
    $old_value_for_log = '';

    if ($period === 'Preliminary') {
        $current_prelim_status = $new_status;
        $grade_type_for_log = "Attendance - Preliminary";
        $old_value_for_log = $old_prelim_status;
    } elseif ($period === 'Mid-Term') {
        $current_midterm_status = $new_status;
        $grade_type_for_log = "Attendance - Mid-Term";
        $old_value_for_log = $old_midterm_status;
    } else {
        error_log("Invalid period provided for attendance update: " . $period);
        return;
    }

    error_log("Current values - prelim: " . ($current_prelim_status ?? 'null') . ", midterm: " . ($current_midterm_status ?? 'null'));

    // Check if the specific status for the current period actually changed
    $status_changed = false;
    if ($period === 'Preliminary' && (string)$old_prelim_status !== (string)$new_status) {
        $status_changed = true;
        error_log("Preliminary attendance changed from '$old_prelim_status' to '$new_status'");
    } elseif ($period === 'Mid-Term' && (string)$old_midterm_status !== (string)$new_status) {
        $status_changed = true;
        error_log("Mid-term attendance changed from '$old_midterm_status' to '$new_status'");
    } else {
        error_log("No change detected for $period attendance");
    }

    if ($status_changed) {
        // Check if a record already exists for this enrollment_id and component_id combination
        $stmt_check = $conn->prepare("SELECT COUNT(*) as count FROM student_grades WHERE enrollment_id = ? AND component_id = ?");
        $stmt_check->bindParam(1, $enrollment_id, PDO::PARAM_INT);
        $stmt_check->bindParam(2, $component_id, PDO::PARAM_INT);
        $stmt_check->execute();
        $exists = $stmt_check->fetch(PDO::FETCH_ASSOC)['count'] > 0;
        $stmt_check->closeCursor();

        error_log("Record exists for enrollment_id $enrollment_id and component_id $component_id: " . ($exists ? 'true' : 'false'));

        if ($exists) {
            // Update existing record
            $stmt_update = $conn->prepare("
                UPDATE student_grades 
                SET attendance_status_prelim = ?, attendance_status_midterm = ?
                WHERE enrollment_id = ? AND component_id = ?
            ");
            $stmt_update->bindParam(1, $current_prelim_status, PDO::PARAM_STR);
            $stmt_update->bindParam(2, $current_midterm_status, PDO::PARAM_STR);
            $stmt_update->bindParam(3, $enrollment_id, PDO::PARAM_INT);
            $stmt_update->bindParam(4, $component_id, PDO::PARAM_INT);
            $success = $stmt_update->execute();
            error_log("Update query executed successfully: " . ($success ? 'true' : 'false'));
            $stmt_update->closeCursor();
        } else {
            // Insert new record
            $stmt_insert = $conn->prepare("
                INSERT INTO student_grades (enrollment_id, component_id, attendance_status_prelim, attendance_status_midterm)
                VALUES (?, ?, ?, ?)
            ");
            $stmt_insert->bindParam(1, $enrollment_id, PDO::PARAM_INT);
            $stmt_insert->bindParam(2, $component_id, PDO::PARAM_INT);
            $stmt_insert->bindParam(3, $current_prelim_status, PDO::PARAM_STR);
            $stmt_insert->bindParam(4, $current_midterm_status, PDO::PARAM_STR);
            $success = $stmt_insert->execute();
            error_log("Insert query executed successfully: " . ($success ? 'true' : 'false'));
            $stmt_insert->closeCursor();
        }

        log_history($conn, $class_id, $enrollment_id, $teacher_id, $grade_type_for_log, $old_value_for_log, $new_status);
        error_log("History logged for $grade_type_for_log");
    }
}

/** Calculates remarks based on grade. */
function calculate_remarks($grade) {
    if ($grade >= 97) return 'Excellent';
    if ($grade >= 92) return 'Outstanding';
    if ($grade >= 86) return 'Very Satisfactory';
    if ($grade >= 80) return 'Satisfactory';
    if ($grade >= 76) return 'Fair';
    if ($grade >= 75) return 'Passed';
    return 'Failed';
}

/** Updates or inserts final grade, logging changes. */
function update_final_grade($conn, $enrollment_id, $new_final_grade, $class_id, $teacher_id) {
    $new_final_grade = floatval($new_final_grade);
    $remarks = calculate_remarks($new_final_grade);
    $old_final_grade = null;
    $stmt_old = $conn->prepare("SELECT overall_final_grade FROM final_grades WHERE enrollment_id = ? AND class_id = ?");
    $stmt_old->bindParam(1, $enrollment_id, PDO::PARAM_INT);
    $stmt_old->bindParam(2, $class_id, PDO::PARAM_INT);
    $stmt_old->execute();
    if ($row = $stmt_old->fetch(PDO::FETCH_ASSOC)) $old_final_grade = $row['overall_final_grade'];
    $stmt_old->closeCursor();

    if ((string)$old_final_grade !== (string)$new_final_grade || ($old_final_grade === null && $new_final_grade !== 0.0)) {
        // Use SQLite's INSERT OR REPLACE instead of MySQL's INSERT ... ON DUPLICATE KEY UPDATE
        $stmt_op = $conn->prepare("
            INSERT OR REPLACE INTO final_grades (enrollment_id, class_id, overall_final_grade, remarks)
            VALUES (?, ?, ?, ?)
        ");
        $stmt_op->bindParam(1, $enrollment_id, PDO::PARAM_INT);
        $stmt_op->bindParam(2, $class_id, PDO::PARAM_INT);
        $stmt_op->bindParam(3, $new_final_grade, PDO::PARAM_STR);
        $stmt_op->bindParam(4, $remarks, PDO::PARAM_STR);
        $stmt_op->execute();
        $stmt_op->closeCursor();
        log_history($conn, $class_id, $enrollment_id, $teacher_id, "Final Numerical Grade", $old_final_grade, $new_final_grade);
    }
}

/** Redirects with a message. */
function redirect_with_message($location, $class_id, $message, $type) {
    header("Location: {$location}?class_id={$class_id}&message_type={$type}&message=" . urlencode($message));
    exit();
}

// --- Main Script ---

if (!isLoggedIn()) {
    header("Location: ../public/login.php");
    exit();
}

$teacher_id = $_SESSION['teacher_id'];
$class_id = (int)($_GET['class_id'] ?? 0);

if ($class_id === 0) exit("Error: No class ID provided.");

$message = $_GET['message'] ?? '';
$message_type = $_GET['message_type'] ?? '';

// Fetch class info and check permission
$stmt = $conn->prepare("SELECT c.*, s.subject_name, sec.section_name FROM classes c JOIN subjects s ON c.subject_id = s.subject_id JOIN sections sec ON c.section_id = sec.section_id WHERE c.class_id = ? AND c.teacher_id = ?");
$stmt->bindParam(1, $class_id, PDO::PARAM_INT);
$stmt->bindParam(2, $teacher_id, PDO::PARAM_INT);
$stmt->execute();
$class = $stmt->fetch(PDO::FETCH_ASSOC);
$stmt->closeCursor();

if (!$class) exit("Access Denied or Class not found.");

// Define & Ensure required components exist
$required_components_config = [
    'Preliminary_Attendance' => ['period' => 'Preliminary', 'is_attendance_based' => 1, 'name' => 'Attendance - Preliminary', 'type' => 'Attendance', 'max_score' => 0, 'default_locked' => 0],
    'Mid_Term_Attendance'    => ['period' => 'Mid-Term', 'is_attendance_based' => 1, 'name' => 'Attendance - Mid-Term', 'type' => 'Attendance', 'max_score' => 0, 'default_locked' => 1],
    'Pre_Final_Grade'        => ['period' => 'Pre-Final', 'is_attendance_based' => 0, 'name' => 'Pre-Final Grade', 'type' => 'Class Standing', 'max_score' => 100, 'default_locked' => 1]
];

$grade_components_status = [];
foreach ($required_components_config as $key => $config) {
    $stmt = $conn->prepare("SELECT component_id, is_locked FROM grade_components WHERE class_id = ? AND period = ? AND is_attendance_based = ?");
    $stmt->bindParam(1, $class_id, PDO::PARAM_INT);
    $stmt->bindParam(2, $config['period'], PDO::PARAM_STR);
    $stmt->bindParam(3, $config['is_attendance_based'], PDO::PARAM_INT);
    $stmt->execute();
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $grade_components_status[$key] = ['component_id' => $row['component_id'], 'is_locked' => (bool)$row['is_locked']];
    } else {
        $stmt_insert = $conn->prepare("INSERT INTO grade_components (class_id, component_name, period, type, max_score, is_attendance_based, is_locked) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt_insert->bindParam(1, $class_id, PDO::PARAM_INT);
        $stmt_insert->bindParam(2, $config['name'], PDO::PARAM_STR);
        $stmt_insert->bindParam(3, $config['period'], PDO::PARAM_STR);
        $stmt_insert->bindParam(4, $config['type'], PDO::PARAM_STR);
        $stmt_insert->bindParam(5, $config['max_score'], PDO::PARAM_INT);
        $stmt_insert->bindParam(6, $config['is_attendance_based'], PDO::PARAM_INT);
        $stmt_insert->bindParam(7, $config['default_locked'], PDO::PARAM_INT);
        $stmt_insert->execute();
        $grade_components_status[$key] = ['component_id' => $conn->lastInsertId(), 'is_locked' => (bool)$config['default_locked']];
        $stmt_insert->closeCursor();
    }
    $stmt->closeCursor();
}

// --- Handle AJAX lock/unlock request ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_lock_component'])) {
    header('Content-Type: application/json');
    
    // Debug logging
    error_log("Lock/Unlock Request - POST data: " . print_r($_POST, true));
    error_log("Current class_id: " . $class_id);
    error_log("Current teacher_id: " . $teacher_id);
    
    $component_id = (int)$_POST['component_id'];
    $is_locked = (int)$_POST['is_locked'];
    
    error_log("Parsed component_id: " . $component_id);
    error_log("Parsed is_locked: " . $is_locked);

    // First verify the component exists and belongs to this teacher's class
    $verify_sql = "
        SELECT gc.component_id, gc.class_id, c.teacher_id 
        FROM grade_components gc 
        JOIN classes c ON gc.class_id = c.class_id 
        WHERE gc.component_id = ? 
        AND c.class_id = ? 
        AND c.teacher_id = ?
    ";
    error_log("Verification SQL: " . $verify_sql);
    
    $stmt_verify = $conn->prepare($verify_sql);
    $stmt_verify->bindParam(1, $component_id, PDO::PARAM_INT);
    $stmt_verify->bindParam(2, $class_id, PDO::PARAM_INT);
    $stmt_verify->bindParam(3, $teacher_id, PDO::PARAM_INT);
    $stmt_verify->execute();
    
    $verify_result = $stmt_verify->fetch(PDO::FETCH_ASSOC);
    error_log("Verification result: " . print_r($verify_result, true));
    
    if (!$verify_result) {
        error_log("Lock/Unlock verification failed - Component ID: $component_id, Class ID: $class_id, Teacher ID: $teacher_id");
        echo json_encode([
            'success' => false, 
            'message' => 'Unauthorized or component not found.',
            'debug' => [
                'component_id' => $component_id,
                'class_id' => $class_id,
                'teacher_id' => $teacher_id
            ]
        ]);
        exit();
    }
    $stmt_verify->closeCursor();

    // Update the lock status
    $update_sql = "UPDATE grade_components SET is_locked = ? WHERE component_id = ?";
    error_log("Update SQL: " . $update_sql);
    
    $stmt_update = $conn->prepare($update_sql);
    $stmt_update->bindParam(1, $is_locked, PDO::PARAM_INT);
    $stmt_update->bindParam(2, $component_id, PDO::PARAM_INT);
    
    try {
        $success = $stmt_update->execute();
        error_log("Update execution result: " . ($success ? 'true' : 'false'));
        
        if ($success) {
            $response = [
                'success' => true, 
                'is_locked' => (bool)$is_locked,
                'message' => 'Component ' . ($is_locked ? 'locked' : 'unlocked') . ' successfully.'
            ];
        } else {
            $error = $stmt_update->errorInfo();
            error_log("Update error: " . print_r($error, true));
            $response = [
                'success' => false, 
                'message' => 'Failed to update lock status: ' . implode(" ", $error),
                'debug' => $error
            ];
        }
    } catch (PDOException $e) {
        error_log("Lock/Unlock PDO error: " . $e->getMessage());
        $response = [
            'success' => false, 
            'message' => 'Database error occurred while updating lock status.',
            'debug' => $e->getMessage()
        ];
    }
    
    $stmt_update->closeCursor();
    echo json_encode($response);
    exit();
}

// --- Handle form submission (POST request) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['toggle_lock_component'])) {
    $conn->beginTransaction(); // Changed from begin_transaction() to beginTransaction()
    try {
        $current_lock_status = [];
        $component_ids = [];
        $stmt_locks = $conn->prepare("SELECT component_id, period, is_attendance_based, is_locked FROM grade_components WHERE class_id = ?");
        $stmt_locks->bindParam(1, $class_id, PDO::PARAM_INT);
        $stmt_locks->execute();
        while($row = $stmt_locks->fetch(PDO::FETCH_ASSOC)) {
            $key = ($row['is_attendance_based'] ? ($row['period'] . '_Attendance') : ($row['period'] === 'Pre-Final' ? 'Pre_Final_Grade' : ''));
            if ($key) {
                $current_lock_status[$key] = (bool)$row['is_locked'];
                $component_ids[$key] = $row['component_id'];
            }
        }
        $stmt_locks->closeCursor();

        foreach ($_POST['grades'] as $enrollment_id => $data) {
            foreach (['Preliminary', 'Mid-Term'] as $period) {
                $key = $period . '_Attendance';
                $comp_id_for_attendance = $component_ids[$key] ?? null;
                $is_locked = $current_lock_status[$key] ?? true;
                
                if ($comp_id_for_attendance && !$is_locked && isset($data[$period])) {
                    update_attendance($conn, $enrollment_id, $comp_id_for_attendance, $data[$period], $class_id, $teacher_id, $period);
                }
            }
            $key = 'Pre_Final_Grade';
            if (!($current_lock_status[$key] ?? true) && isset($data['Pre-Final']) && $data['Pre-Final'] !== '') {
                update_final_grade($conn, $enrollment_id, $data['Pre-Final'], $class_id, $teacher_id);
            }
        }
        $conn->commit();
        redirect_with_message($_SERVER['PHP_SELF'], $class_id, "Grades saved successfully!", "success");

    } catch (Exception $e) {
        $conn->rollBack(); // Changed from rollback() to rollBack() for consistency
        error_log("Grade saving error: " . $e->getMessage());
        redirect_with_message($_SERVER['PHP_SELF'], $class_id, "Error saving grades: " . $e->getMessage(), "danger");
    }
}

// --- Data fetching for display ---
$stmt = $conn->prepare("SELECT e.enrollment_id, s.student_number, s.first_name, s.last_name FROM enrollments e JOIN students s ON s.student_id = e.student_id WHERE e.class_id = ? ORDER BY s.last_name, s.first_name");
$stmt->bindParam(1, $class_id, PDO::PARAM_INT);
$stmt->execute();
$students_result = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt->closeCursor();

$existing_attendance = [];
// Get the component IDs for attendance components
$prelim_component_id = $grade_components_status['Preliminary_Attendance']['component_id'] ?? null;
$midterm_component_id = $grade_components_status['Mid_Term_Attendance']['component_id'] ?? null;

if ($prelim_component_id) {
    $stmt = $conn->prepare("
        SELECT
            sg.enrollment_id,
            sg.attendance_status_prelim
        FROM
            student_grades sg
        JOIN
            enrollments e ON sg.enrollment_id = e.enrollment_id
        WHERE
            e.class_id = ? AND sg.component_id = ? AND sg.attendance_status_prelim IS NOT NULL
    ");
    $stmt->bindParam(1, $class_id, PDO::PARAM_INT);
    $stmt->bindParam(2, $prelim_component_id, PDO::PARAM_INT);
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existing_attendance[$row['enrollment_id']]['Preliminary'] = $row['attendance_status_prelim'];
    }
    $stmt->closeCursor();
}

if ($midterm_component_id) {
    $stmt = $conn->prepare("
        SELECT
            sg.enrollment_id,
            sg.attendance_status_midterm
        FROM
            student_grades sg
        JOIN
            enrollments e ON sg.enrollment_id = e.enrollment_id
        WHERE
            e.class_id = ? AND sg.component_id = ? AND sg.attendance_status_midterm IS NOT NULL
    ");
    $stmt->bindParam(1, $class_id, PDO::PARAM_INT);
    $stmt->bindParam(2, $midterm_component_id, PDO::PARAM_INT);
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existing_attendance[$row['enrollment_id']]['Mid-Term'] = $row['attendance_status_midterm'];
    }
    $stmt->closeCursor();
}

$existing_final_grades = [];
$existing_final_grade_timestamps = [];
$stmt = $conn->prepare("SELECT enrollment_id, overall_final_grade, final_change_timestamp FROM final_grades WHERE class_id = ?");
$stmt->bindParam(1, $class_id, PDO::PARAM_INT);
$stmt->execute();
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $existing_final_grades[$row['enrollment_id']] = $row['overall_final_grade'];
    $existing_final_grade_timestamps[$row['enrollment_id']] = $row['final_change_timestamp'];
}
$stmt->closeCursor();

$raw_history_logs = [];
$stmt = $conn->prepare("SELECT gh.*, s.first_name, s.last_name, s.student_number FROM grade_history gh JOIN enrollments e ON gh.enrollment_id = e.enrollment_id JOIN students s ON e.student_id = s.student_id WHERE gh.class_id = ? ORDER BY gh.change_timestamp DESC");
$stmt->bindParam(1, $class_id, PDO::PARAM_INT);
$stmt->execute();
$raw_history_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt->closeCursor();

$student_grade_history = [];
foreach ($raw_history_logs as $log) {
    $enrollment_id = $log['enrollment_id'];
    if (!isset($student_grade_history[$enrollment_id])) {
        $student_grade_history[$enrollment_id] = [
            'first_name' => $log['first_name'], 'last_name' => $log['last_name'], 'student_number' => $log['student_number'],
            'prelim_attendance_history' => [], // Changed to array
            'midterm_attendance_history' => [], // Changed to array
            'final_grade_edits' => [],
            'final_grade_current_timestamp' => $existing_final_grade_timestamps[$enrollment_id] ?? 'N/A',
        ];
    }
    $entry = ['old_value' => $log['old_value'] ?? 'N/A (Initial)', 'new_value' => $log['new_value'], 'timestamp' => $log['change_timestamp']];
    if ($log['grade_type'] === 'Attendance - Preliminary') {
        $student_grade_history[$enrollment_id]['prelim_attendance_history'][] = $entry; // Append to array
    } elseif ($log['grade_type'] === 'Attendance - Mid-Term') {
        $student_grade_history[$enrollment_id]['midterm_attendance_history'][] = $entry; // Append to array
    } elseif ($log['grade_type'] === 'Final Numerical Grade') {
        $student_grade_history[$enrollment_id]['final_grade_edits'][] = $entry;
    }
}

$history_logs_for_display = [];
foreach ($student_grade_history as $enrollment_id => $data) {
    // Sort attendance histories by timestamp (latest first)
    usort($data['prelim_attendance_history'], fn($a, $b) => strtotime($b['timestamp']) - strtotime($a['timestamp']));
    usort($data['midterm_attendance_history'], fn($a, $b) => strtotime($b['timestamp']) - strtotime($a['timestamp']));
    usort($data['final_grade_edits'], fn($a, $b) => strtotime($b['timestamp']) - strtotime($a['timestamp']));

    // Determine current values for display in the table
    $latest_prelim_attendance = !empty($data['prelim_attendance_history']) ? $data['prelim_attendance_history'][0] : ['new_value' => 'N/A', 'timestamp' => 'N/A'];
    $latest_midterm_attendance = !empty($data['midterm_attendance_history']) ? $data['midterm_attendance_history'][0] : ['new_value' => 'N/A', 'timestamp' => 'N/A'];

    $history_logs_for_display[] = [
        'enrollment_id' => $enrollment_id, 'first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'student_number' => $data['student_number'],
        'prelim_attendance_latest_value' => $latest_prelim_attendance['new_value'], // Latest value for direct display
        'prelim_attendance_latest_timestamp' => $latest_prelim_attendance['timestamp'], // Latest timestamp for direct display
        'prelim_attendance_all_edits' => $data['prelim_attendance_history'], // All edits for dropdown

        'midterm_attendance_latest_value' => $latest_midterm_attendance['new_value'], // Latest value for direct display
        'midterm_attendance_latest_timestamp' => $latest_midterm_attendance['timestamp'], // Latest timestamp for direct display
        'midterm_attendance_all_edits' => $data['midterm_attendance_history'], // All edits for dropdown

        'final_grade_edits' => array_slice($data['final_grade_edits'], 0, 5), 'final_grade_current_timestamp' => $data['final_grade_current_timestamp'],
        'final_grade_current_value' => $existing_final_grades[$enrollment_id] ?? 'N/A', // Ensure current final grade is available
    ];
}
usort($history_logs_for_display, fn($a, $b) => strcmp($a['last_name'], $b['last_name']));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Input Attendance & Final Grades - <?= htmlspecialchars($class['subject_name'] ?? 'Class') ?> - UDM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background-color: #f5f3e1; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-wrapper { display: flex; min-height: 10vh; }
        .sidebar { width: 280px; background-color: #006400; color: #E7E7E7; padding: 0; position: fixed; top: 0; left: 0; height: 100vh; z-index: 1030; overflow-y: auto; transition: width 0.3s ease; display: flex; flex-direction: column; }
        .sidebar-header { padding: 1rem; border-bottom: 1px solid #008000; display: flex; align-items: center; justify-content: flex-start; min-height: 70px; background-color: #004d00; }
        .logo-image { max-height: 40px; }
        .logo-text { overflow: hidden; }
        .logo-text h5 { margin: 0; font-size: 0.9rem; font-weight: 600; color: #FFFFFF; line-height: 1.1; white-space: nowrap; }
        .logo-text p { margin: 0; font-size: 0.7rem; font-weight: 300; color: #E7E7E7; line-height: 1; white-space: nowrap; }
        .sidebar .nav-menu { padding: 1rem; flex-grow: 1; display: flex; flex-direction: column; }
        .sidebar .nav-link { color: #E7E7E7; padding: 0.85rem 1.25rem; font-size: 0.95rem; border-radius: 0.3rem; margin-bottom: 0.25rem; transition: background-color 0.2s ease, color 0.2s ease; display: flex; align-items: center; white-space: nowrap; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: #FFFFFF; background-color: #008000; }
        .sidebar .nav-link .bi { margin-right: 0.85rem; font-size: 1.1rem; width: 20px; text-align: center; }
        .sidebar .nav-link span { flex-grow: 1; overflow: hidden; text-overflow: ellipsis; }
        .sidebar .logout-item { margin-top: auto; } .sidebar .logout-item hr { border-color: #008000; margin: 1rem 0; }
        .content-area { margin-left: 280px; flex-grow: 1; padding: 2.5rem; width: calc(100% - 280px); transition: margin-left 0.3s ease, width 0.3s ease; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2.5rem; padding-bottom: 1.25rem; border-bottom: 1px solid #d6d0b8; }
        .page-header h2 { margin: 0; font-weight: 500; font-size: 1.75rem; color: #006400; }
        .card { border: 1px solid #d6d0b8; box-shadow: 0 0.25rem 0.75rem rgba(0, 0, 0, 0.05); border-radius: 0.5rem; background-color: #fcfbf7; }
        .card-header { background-color: #e9e5d0; border-bottom: 1px solid #d6d0b8; padding: 1rem 1.25rem; font-weight: 500; color: #006400; }
        .btn-primary { background-color: #006400; border-color: #006400; } .btn-primary:hover { background-color: #004d00; border-color: #004d00; }
        .btn-outline-primary { color: #006400; border-color: #006400; } .btn-outline-primary:hover { background-color: #006400; color: white; }
        .btn-outline-info { color: #0d6efd; border-color: #0d6efd; } .btn-outline-info:hover { background-color: #0d6efd; color: white; }
        .alert-danger { background-color: #f8d7da; border-color: #f5c6cb; color: #721c24; }
        .alert-success { background-color: #d4edda; border-color: #c3e6cb; color: #155724; }
        .footer { padding: 1.5rem 0; margin-top: 2rem; font-size: 0.875rem; color: #006400; border-top: 1px solid #d6d0b8; }
        .table { background-color: #ffffff; border-radius: 0.375rem; overflow: hidden; } .table thead { background-color: #e9e5d0; color: #006400; }
        .table th { font-weight: 500; border-bottom-width: 1px; } .table td, .table th { padding: 0.75rem 1rem; vertical-align: middle; }
        .period-header { background-color: #f3f0e0; font-weight: 500; color: #006400; }
        .grades-table .form-control, .grades-table .form-select { padding: 0.4rem 0.5rem; font-size: 0.95rem; }
        .grades-table .student-name { white-space: nowrap; font-weight: 500; } .grades-table .student-id { font-size: 0.85rem; color: #666; }
        .table-responsive { overflow-x: auto; max-height: calc(100vh - 320px); }
        .table-sticky thead th { position: sticky; top: 0; z-index: 10; background-color: #e9e5d0; }
        input[type="number"].grade-input, select.grade-select { max-width: 100px; }
        .sticky-action-bar { position: sticky; bottom: 0; background-color: rgba(245, 243, 225, 0.95); padding: 1rem 0; border-top: 1px solid #d6d0b8; z-index: 1000; }
        .modal-header.bg-info-subtle { background-color: #cfe2ff !important; }
        .lock-button { cursor: pointer; font-size: 0.9rem; margin-left: 5px; color: #6c757d; transition: color 0.2s ease; }
        .lock-button.locked { color: #dc3545; } .lock-button:hover { color: #000; }
        .header-with-lock { display: flex; align-items: center; justify-content: center; white-space: nowrap; }
        @media (max-width: 992px) {
            .sidebar { width: 80px; } .sidebar .logo-text, .sidebar .nav-link span { display: none; }
            .sidebar .sidebar-header { justify-content: center; padding: 1.25rem 0.5rem; }
            .sidebar .nav-link .bi { margin-right: 0; display: block; text-align: center; font-size: 1.5rem; }
            .sidebar:hover { width: 280px; } .sidebar:hover .logo-text, .sidebar:hover .nav-link span { display: inline; }
            .sidebar:hover .sidebar-header { justify-content: flex-start; padding: 1rem; }
            .sidebar:hover .nav-link .bi { margin-right: 0.85rem; font-size: 1.1rem; }
            .content-area { margin-left: 80px; width: calc(100% - 80px); }
            .sidebar:hover + .content-area { margin-left: 280px; width: calc(100% - 280px); }
        }
        @media (max-width: 768px) {
            .main-wrapper { flex-direction: column; } .sidebar { width: 100%; height: auto; position: relative; }
            .sidebar .logo-text, .sidebar .nav-link span { display: inline; }
            .sidebar .sidebar-header { justify-content: flex-start; padding: 1rem; }
            .sidebar .nav-link .bi { margin-right: 0.85rem; font-size: 1.1rem; }
            .content-area { margin-left: 0; width: 100%; padding: 1.5rem; }
        }
        
        /* Chatbot specific styles */
        .chatbot-container {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1050; /* Ensure it's above other elements like modals */
        }

        .btn-chatbot {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            font-size: 1.8rem;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }

        .popover {
            max-width: 350px; /* This limits the popover width */
        }

        .popover-header {
            background-color: #006400; /* Dark green header */
            color: white;
            font-weight: bold;
        }

        .popover-body {
            /* Existing padding */
            padding: 15px;
            /* Added styles to constrain popover body's height */
            max-height: 400px; /* Adjust this value as needed */
            overflow-y: auto; /* Adds scrollbar to popover body if content exceeds max-height */
        }

        .chatbot-messages {
            height: 200px; /* Fixed height for the message area */
            overflow-y: auto; /* Enable vertical scrolling */
            border: 1px solid #ddd;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 10px;
            background-color: #f9f9f9;
            display: flex;
            flex-direction: column;
        }

        /* Message containers */
        .message-container {
            display: flex;
            margin-bottom: 8px;
            max-width: 90%; /* Limit message width */
        }

        .user-message {
            align-self: flex-end; /* Align user messages to the right */
            background-color: #e0f7fa; /* Light blue for user messages */
            border-radius: 15px 15px 0 15px;
            padding: 8px 12px;
            margin-left: auto; /* Push to the right */
        }

        .isla-message {
            align-self: flex-start; /* Align Isla messages to the left */
            background-color: #e7f3e7; /* Light green for Isla messages */
            border-radius: 15px 15px 15px 0;
            padding: 8px 12px;
            margin-right: auto; /* Push to the left */
        }

        .message-container strong {
            font-weight: bold;
            margin-bottom: 2px;
            display: block; /* Make sender name a block to separate from message */
        }
        .user-message strong {
             color: #0056b3; /* Darker blue for user name */
        }
        .isla-message strong {
             color: #006400; /* Darker green for Isla name */
        }

        .message-container p {
            margin: 0;
            line-height: 1.4;
            /* Added styles for robust text wrapping */
            word-wrap: break-word; /* Ensures long words break and wrap */
            white-space: pre-wrap; /* Preserves whitespace and wraps text */
        }

        

        /* Typing indicator */
        .typing-indicator {
            display: flex;
            align-items: center;
            padding: 8px 12px;
            background-color: #f0f0f0;
            border-radius: 15px 15px 15px 0;
            max-width: fit-content;
            align-self: flex-start;
        }

        .typing-indicator span {
            width: 8px;
            height: 8px;
            background-color: #888;
            border-radius: 50%;
            margin: 0 2px;
            animation: bounce 1.4s infinite ease-in-out both;
        }

        .typing-indicator span:nth-child(1) { animation-delay: -0.32s; }
        .typing-indicator span:nth-child(2) { animation-delay: -0.16s; }
        .typing-indicator span:nth-child(3) { animation-delay: 0s; }

        @keyframes bounce {
            0%, 80%, 100% { transform: scale(0); }
            40% { transform: scale(1); }
        }

        /* Print-specific styles */
        @media print {
            body {
                background-color: #fff !important;
                font-family: Arial, sans-serif;
                font-size: 10pt;
            }
            .main-wrapper > .sidebar,
            .main-wrapper > .content-area > .page-header .d-flex.gap-2, /* Hide action buttons in header */
            .main-wrapper > .content-area > .sticky-action-bar,
            .main-wrapper > .content-area > .footer,
            .modal,
            .alert,
            .chatbot-container,
            .lock-button, /* Hide lock buttons in the main page if directly printed */
            .grades-table .form-select, 
            .grades-table .form-control.grade-input,
            #printGradesButton /* Hide print button itself when printing */
            {
                display: none !important;
            }
            .content-area {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 0 !important;
            }
            .card {
                border: none !important;
                box-shadow: none !important;
            }
            .card-header {
                background-color: #fff !important;
                border-bottom: 1px solid #000 !important;
                color: #000 !important;
                text-align: center; /* Center align header for print */
            }
            .table-responsive {
                overflow-x: visible !important;
                max-height: none !important;
            }
            .table, .table th, .table td {
                border: 1px solid #000 !important;
                color: #000 !important;
                font-size: 9pt; /* Adjust font size for print */
            }
            .table thead {
                background-color: #eee !important;
            }
            /* Display only the selected option text for selects when printing main page */
            .grades-table select option { display: none; }
            .grades-table select option[selected] { display: inline; }
             .grades-table .student-name { font-weight: normal; } 
             .grades-table .student-id { font-size: 8pt; }
        }

        /* Hide spinner for number inputs (Chrome, Safari, Edge) */
        input[type=number].grade-input::-webkit-inner-spin-button, 
        input[type=number].grade-input::-webkit-outer-spin-button { 
          -webkit-appearance: none;
          margin: 0;
        }

        /* Hide spinner for number inputs (Firefox) */
        input[type=number].grade-input {
          -moz-appearance: textfield;
        }
    </style>
</head>
<body>

<div class="main-wrapper">
    <nav class="sidebar">
        <div class="sidebar-header">
            <img src="../public/assets/img/udm_logo.png" alt="UDM Logo" class="logo-image me-2">
            <div class="logo-text">
                <h5 class="uni-name mb-0">UNIVERSIDAD DE MANILA</h5>
                <p class="tagline mb-0">Former City College of Manila</p>
            </div>
        </div>
        <ul class="nav flex-column nav-menu">
            <li class="nav-item"><a class="nav-link" href="../public/dashboard.php"><i class="bi bi-grid-1x2-fill"></i> <span>Dashboard</span></a></li>
           <li class="nav-item">
                <a class="nav-link" href="create_class.php">
                    <i class="bi bi-plus-square-dotted"></i> <span>Create New Class</span>
                </a>
            </li>
             <li class="nav-item">
                <a class="nav-link active" aria-current="page" href="your_classes.php">
                    <i class="bi bi-person-workspace"></i> <span>Your Classes</span>
                </a>
            </li>
               <li class="nav-item">
                <a class="nav-link" href="../public/manage_backup.php">
                    <i class="bi bi-cloud-arrow-down-fill"></i> <span>Manage Backup</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="../public/gradingsystem.php">
                    <i class="bi bi-calculator"></i> <span>Grading System</span>
                </a>
            </li>
            <li class="nav-item logout-item"><hr><a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#logoutModal"><i class="bi bi-box-arrow-right"></i> <span>Logout</span></a></li>
        </ul>
    </nav>

    <main class="content-area">
        <header class="page-header">
            <div>
                <h2>Input Attendance & Final Grades</h2>
                <p class="text-muted mb-0"><i class="bi bi-book me-1"></i> <?= htmlspecialchars($class['subject_name']) ?> - <i class="bi bi-people me-1"></i> <?= htmlspecialchars($class['section_name']) ?></p>
            </div>
            <div class="d-flex gap-2 flex-wrap">

                <button type="button" class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#historyModal"><i class="bi bi-clock-history"></i> Grade History</button>
                
                <button type="button" class="btn btn-outline-dark" id="printGradesButton"><i class="bi bi-printer"></i> Print Grades</button>
                <a href="../teacher/your_classes.php" class="btn btn-outline-secondary"><i class="bi bi-person-workspace"></i> Your Classes</a>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="alert alert-<?= htmlspecialchars($message_type ?: 'info') ?> alert-dismissible fade show" role="alert" id="statusMessage">
                <i class="bi bi-<?= ($message_type === 'success') ? 'check-circle-fill' : 'info-circle-fill' ?> me-2"></i> <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (empty($students_result)): ?>
            <div class="card shadow-sm"><div class="card-body text-center p-5"><i class="bi bi-people-fill text-warning" style="font-size: 3rem;"></i><h4 class="mt-3 mb-2">No Students Enrolled</h4><p class="text-muted">There are no students enrolled in this class yet.</p></div></div>
        <?php else: ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div><i class="bi bi-calendar-check me-2"></i> Attendance & Final Grade Entry</div>
                    <span class="badge bg-primary rounded-pill"><?= count($students_result) ?> Student<?= count($students_result) > 1 ? 's' : '' ?></span>
                </div>
                <div class="card-body p-0">
                    <form action="<?= $_SERVER['PHP_SELF'] ?>?class_id=<?= $class_id ?>" method="POST" id="gradesForm">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover table-sticky grades-table mb-0" id="gradesInputTable">
                                <thead>
                                    <tr>
                                        <th style="width: 250px; min-width: 200px;">Student Information</th>
                                        <?php foreach (['Preliminary_Attendance' => 'Preliminary', 'Mid_Term_Attendance' => 'Mid-Term', 'Pre_Final_Grade' => 'Pre-Final'] as $key => $label):
                                            $comp_id = $grade_components_status[$key]['component_id'] ?? null;
                                            $locked = $grade_components_status[$key]['is_locked'] ?? false;
                                        ?>
                                        <th class="text-center period-header">
                                            <div class="header-with-lock">
                                                <?= $label ?>
                                                <?php if ($comp_id): ?>
                                                <i class="lock-button bi bi-<?= $locked ? 'lock-fill locked' : 'unlock-fill' ?>"
                                                   data-component-id="<?= $comp_id ?>" data-period="<?= $key ?>"
                                                   data-locked="<?= (int)$locked ?>" title="<?= $locked ? 'Unlock' : 'Lock' ?> <?= str_replace('_', ' ', $key) ?>"></i>
                                                <?php endif; ?>
                                            </div>
                                        </th>
                                        <?php endforeach; ?>
                                    </tr>
                                    <tr>
                                        <th>Name & ID</th>
                                        <th class="text-center">Attendance</th>
                                        <th class="text-center">Attendance</th>
                                        <th class="text-center">Final Numerical Grade</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // Remove mysqli_data_seek since we're using an array
                                    foreach ($students_result as $student):
                                        $eid = $student['enrollment_id'];
                                        $pre_att = $existing_attendance[$eid]['Preliminary'] ?? 'A';
                                        $mid_att = $existing_attendance[$eid]['Mid-Term'] ?? 'A';
                                        $fin_grd = $existing_final_grades[$eid] ?? '';
                                        $pre_lock = $grade_components_status['Preliminary_Attendance']['is_locked'] ?? false;
                                        $mid_lock = $grade_components_status['Mid_Term_Attendance']['is_locked'] ?? false;
                                        $fin_lock = $grade_components_status['Pre_Final_Grade']['is_locked'] ?? false;
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="student-name"><?= htmlspecialchars($student['last_name'] . ", " . $student['first_name']) ?></div>
                                                <div class="student-id"><small><i class="bi bi-person-badge me-1"></i><?= htmlspecialchars($student['student_number']) ?></small></div>
                                            </td>
                                            <td class="text-center">
                                               <select class="form-select form-select-sm grade-select" name="grades[<?= $eid ?>][Preliminary]" data-period="Preliminary_Attendance" <?= $pre_lock ? 'disabled' : '' ?>>
                                                <option value="A" <?= $pre_att === 'A' ? 'selected' : '' ?>>Attended</option>
                                                <option value="NA" <?= $pre_att === 'NA' ? 'selected' : '' ?>>Not Attended</option>
                                               </select>
                                            </td>
                                            <td class="text-center">
                                                <select class="form-select form-select-sm grade-select" name="grades[<?= $eid ?>][Mid-Term]" data-period="Mid_Term_Attendance" <?= $mid_lock ? 'disabled' : '' ?>>
                                                 <option value="A" <?= $mid_att === 'A' ? 'selected' : '' ?>>Attended</option>
                                                 <option value="NA" <?= $mid_att === 'NA' ? 'selected' : '' ?>>Not Attended</option>
                                                </select>
                                            </td>
                                            <td class="text-center">
                                                <input type="number" class="form-control form-control-sm grade-input" name="grades[<?= $eid ?>][Pre-Final]" data-period="Pre_Final_Grade" min="0" max="100" step="0.01" value="<?= htmlspecialchars($fin_grd) ?>" <?= $fin_lock ? 'disabled' : '' ?>>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="sticky-action-bar text-end">
                            <div class="container-fluid px-4">
                                <a href="view_class_record.php?class_id=<?= $class_id ?>" class="btn btn-outline-primary me-2">
                                    <i class="bi bi-eye me-2"></i> View Grades
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save me-2"></i> Save All Grades
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header"><i class="bi bi-info-circle me-2"></i> Grading Scale Reference</div>
                <div class="card-body"><div class="table-responsive"><table class="table table-sm table-bordered">
                    <thead><tr><th>Grade Range</th><th>Remarks</th></tr></thead>
                    <tbody>
                        <tr><td>97-100</td><td>Excellent</td></tr> <tr><td>92-96</td><td>Outstanding</td></tr>
                        <tr><td>86-91</td><td>Very Satisfactory</td></tr> <tr><td>80-85</td><td>Satisfactory</td></tr>
                        <tr><td>76-79</td><td>Fair</td></tr> <tr><td>75</td><td>Passed</td></tr>
                        <tr><td>Below 75</td><td>Failed</td></tr>
                    </tbody>
                </table></div></div>
            </div>
        <?php endif; ?>

        <footer class="footer text-center">&copy; <?= date('Y') ?> Universidad De Manila - Teacher Portal.</footer>
    </main>
</div>

<div class="modal fade" id="historyModal" tabindex="-1" aria-labelledby="historyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-info-subtle">
                <h5 class="modal-title" id="historyModalLabel"><i class="bi bi-clock-history me-2"></i> Grade Change History</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if (empty($history_logs_for_display)): ?>
                    <p class="text-center text-muted">No grade change history found.</p>
                <?php else: ?>
                    <div class="table-responsive"><table class="table table-bordered table-striped table-sm">
                        <thead><tr><th>Student Name (ID)</th><th>Preliminary Attendance</th><th>Mid-Term Attendance</th><th>Final Numerical Grade History</th></tr></thead>
                        <tbody>
                        <?php foreach ($history_logs_for_display as $log): ?>
                            <tr>
                                <td><?= htmlspecialchars($log['last_name'] . ", " . $log['first_name']) ?> <br><small class="text-muted">(<?= htmlspecialchars($log['student_number']) ?>)</small></td>
                                <td>
                                    <?php if (!empty($log['prelim_attendance_all_edits'])): ?>
                                        <p class="mb-1">Current: <strong><?= htmlspecialchars($log['prelim_attendance_latest_value']) ?></strong><br><small class="text-muted">(Last Edited: <?= htmlspecialchars($log['prelim_attendance_latest_timestamp']) ?>)</small></p>
                                        <?php if (count($log['prelim_attendance_all_edits']) > 1): ?>
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-outline-info dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                    History (<?= count($log['prelim_attendance_all_edits']) ?>)
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <?php foreach ($log['prelim_attendance_all_edits'] as $edit): ?>
                                                        <li><a class="dropdown-item small" href="#">
                                                            <small class="text-muted"><?= htmlspecialchars($edit['timestamp']) ?></small><br>
                                                            <strong class="text-danger"><?= htmlspecialchars($edit['old_value']) ?></strong> &rarr; <strong class="text-success"><?= htmlspecialchars($edit['new_value']) ?></strong>
                                                        </a></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: echo 'N/A'; endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($log['midterm_attendance_all_edits'])): ?>
                                        <p class="mb-1">Current: <strong><?= htmlspecialchars($log['midterm_attendance_latest_value']) ?></strong><br><small class="text-muted">(Last Edited: <?= htmlspecialchars($log['midterm_attendance_latest_timestamp']) ?>)</small></p>
                                        <?php if (count($log['midterm_attendance_all_edits']) > 1): ?>
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-outline-info dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                    History (<?= count($log['midterm_attendance_all_edits']) ?>)
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <?php foreach ($log['midterm_attendance_all_edits'] as $edit): ?>
                                                        <li><a class="dropdown-item small" href="#">
                                                            <small class="text-muted"><?= htmlspecialchars($edit['timestamp']) ?></small><br>
                                                            <strong class="text-danger"><?= htmlspecialchars($edit['old_value']) ?></strong> &rarr; <strong class="text-success"><?= htmlspecialchars($edit['new_value']) ?></strong>
                                                        </a></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: echo 'N/A'; endif; ?>
                                </td>
                                <td>
                                    <p class="mb-1">Current: <strong><?= htmlspecialchars($log['final_grade_current_value'] ?? 'N/A') ?></strong> <br><small class="text-muted">(<?= htmlspecialchars($log['final_grade_current_timestamp']) ?>)</small></p>
                                    <?php if (!empty($log['final_grade_edits'])): ?>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-info dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">History (<?= count($log['final_grade_edits']) ?>)</button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                        <?php foreach ($log['final_grade_edits'] as $edit): ?>
                                            <li><a class="dropdown-item small" href="#"><small class="text-muted"><?= htmlspecialchars($edit['timestamp']) ?></small><br>
                                                <strong class="text-danger"><?= htmlspecialchars($edit['old_value']) ?></strong> &rarr; <strong class="text-success"><?= htmlspecialchars($edit['new_value']) ?></strong></a></li>
                                        <?php endforeach; ?>
                                        </ul>
                                    </div>
                                    <?php else: echo 'No History'; endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>

<div class="modal fade" id="logoutModal" tabindex="-1" aria-labelledby="logoutModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-warning-subtle">
        <h5 class="modal-title" id="logoutModalLabel">Confirm Logout</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>Would you like to save the database before logging out?</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-success" id="saveDbFromLogoutBtn">
            <i class="bi bi-floppy-fill me-2"></i>Save Database
        </button>
        <a href="../public/logout.php" class="btn btn-danger" id="logoutButton">Logout</a>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </div>
  </div>
</div>

<!-- Database Save Success Modal -->
<div class="modal fade" id="dbSaveSuccessModal" tabindex="-1" aria-labelledby="dbSaveSuccessModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title" id="dbSaveSuccessModalLabel">
            <i class="bi bi-check-circle-fill me-2"></i>Database Saved Successfully
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p><i class="bi bi-cloud-check-fill me-2 text-success"></i>Your database has been successfully saved to your Google Drive folder.</p>
        <p class="mb-0"><strong>File location:</strong> <span id="savedFilePath"></span></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-success" data-bs-dismiss="modal">OK</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../public/js/logout-handler.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Auto-dismiss alerts
        document.querySelectorAll('.alert-dismissible').forEach(alert => setTimeout(() => bootstrap.Alert.getOrCreateInstance(alert)?.close(), 5000));

        // Visual feedback on change
        document.querySelectorAll('input.grade-input, select.grade-select').forEach(input => {
            input.dataset.originalValue = input.value;
            input.addEventListener('input', function() {
                this.style.backgroundColor = (this.value !== this.dataset.originalValue) ? '#f0fff4' : '';
            });
        });

        // Show Alert Helper
        function showAlert(type, message) {
            document.getElementById('statusMessage')?.remove();
            const alertContainer = document.querySelector('.content-area');
            const icon = type === 'success' ? 'check-circle-fill' : 'info-circle-fill';
            const alertHtml = `
                <div class="alert alert-${type} alert-dismissible fade show" role="alert" id="statusMessage">
                    <i class="bi bi-${icon} me-2"></i> ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>`;
            alertContainer.insertAdjacentHTML('afterbegin', alertHtml);
            setTimeout(() => document.getElementById('statusMessage')?.remove(), 5000);
        }

        // Lock/Unlock Functionality
        document.querySelectorAll('.lock-button').forEach(button => {
            button.addEventListener('click', function() {
                const componentId = this.dataset.componentId;
                const period = this.dataset.period;
                const newLockStatus = parseInt(this.dataset.locked, 10) ? 0 : 1;
                
                console.log('Lock/Unlock clicked:', {
                    componentId,
                    period,
                    currentLockStatus: this.dataset.locked,
                    newLockStatus
                });

                // Disable the button while processing
                this.disabled = true;

                const formData = new URLSearchParams({ 
                    toggle_lock_component: '1', 
                    component_id: componentId, 
                    is_locked: newLockStatus 
                });
                
                console.log('Sending request with data:', formData.toString());

                fetch('<?= $_SERVER['PHP_SELF'] ?>?class_id=<?= $class_id ?>', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: formData
                })
                .then(response => {
                    console.log('Response status:', response.status);
                    return response.json();
                })
                .then(data => {
                    console.log('Response data:', data);
                    
                    if (data.success) {
                        this.dataset.locked = newLockStatus;
                        this.classList.toggle('bi-unlock-fill', !newLockStatus);
                        this.classList.toggle('bi-lock-fill', newLockStatus);
                        this.classList.toggle('locked', newLockStatus);
                        this.title = `${newLockStatus ? 'Unlock' : 'Lock'} ${period.replace(/_/g, ' ')}`;
                        
                        // Update all inputs for this component
                        document.querySelectorAll(`[data-period="${period}"]`).forEach(input => {
                            input.disabled = newLockStatus;
                        });
                        
                        showAlert('success', data.message || `Component ${period.replace(/_/g, ' ')} ${newLockStatus ? 'LOCKED' : 'UNLOCKED'}.`);
                    } else {
                        console.error('Lock/Unlock failed:', data);
                        showAlert('danger', data.message || 'Failed to update lock status.');
                        // Revert the button state on failure
                        this.dataset.locked = !newLockStatus;
                    }
                })
                .catch(error => {
                    console.error('Lock/Unlock error:', error);
                    showAlert('danger', 'An unexpected error occurred.');
                    // Revert the button state on error
                    this.dataset.locked = !newLockStatus;
                })
                .finally(() => {
                    // Re-enable the button
                    this.disabled = false;
                });
            });
        });

        // Print Grades Functionality
        const printButton = document.getElementById('printGradesButton');
        if (printButton) {
            printButton.addEventListener('click', function() {
                printGradesTable();
            });
        }

        function printGradesTable() {
            const tableToPrint = document.getElementById('gradesInputTable');
            if (!tableToPrint) {
                showAlert('danger', 'Grades table not found!');
                return;
            }

            const classInfo = "<?= htmlspecialchars($class['subject_name'] . ' - ' . $class['section_name'], ENT_QUOTES, 'UTF-8') ?>";
            const teacherName = "<?= htmlspecialchars(isset($_SESSION['teacher_name']) ? $_SESSION['teacher_name'] : 'N/A', ENT_QUOTES, 'UTF-8') ?>"; // Assuming teacher name is in session

            const printWindow = window.open('', '_blank');
            printWindow.document.write('<html><head><title>Print Grades - ' + classInfo + '</title>');
            printWindow.document.write('<style>');
            printWindow.document.write('body { margin: 20px; font-family: Arial, sans-serif; font-size: 10pt; }');
            printWindow.document.write('.print-header { text-align: center; margin-bottom: 10px; }');
            printWindow.document.write('.print-header h2 { margin: 0 0 5px 0; font-size: 16pt;}');
            printWindow.document.write('.print-header p { margin: 0; font-size: 12pt;}');
            printWindow.document.write('.info-table { width: 100%; margin-bottom: 15px; border-collapse: collapse; }');
            printWindow.document.write('.info-table td { padding: 4px; font-size: 10pt; }');
            printWindow.document.write('table { width: 100%; border-collapse: collapse; margin-top: 5px; }');
            printWindow.document.write('th, td { border: 1px solid #000; padding: 5px 7px; text-align: left; vertical-align: middle; }');
            printWindow.document.write('thead th { background-color: #e0e0e0; font-weight: bold; text-align: center;}');
            printWindow.document.write('tbody td { font-size: 9pt; }');
            printWindow.document.write('.student-name { font-weight: normal; }'); // Changed from bold for less emphasis on print
            printWindow.document.write('.student-id { font-size: 0.85em; color: #333; }');
            printWindow.document.write('.text-center { text-align: center !important; }');
            printWindow.document.write('.period-header { background-color: #e9e5d0 !important; }'); // Match original style a bit
            printWindow.document.write('</style></head><body>');
            
            printWindow.document.write('<div class="print-header">');
            printWindow.document.write('<h2>Grade Sheet</h2>');
            printWindow.document.write('<p>Universidad De Manila</p>');
            printWindow.document.write('</div>');

            printWindow.document.write('<table class="info-table">');
            printWindow.document.write('<tr><td><strong>Subject:</strong> ' + "<?= htmlspecialchars($class['subject_name'], ENT_QUOTES, 'UTF-8') ?>" + '</td>');
            printWindow.document.write('<td><strong>Section:</strong> ' + "<?= htmlspecialchars($class['section_name'], ENT_QUOTES, 'UTF-8') ?>" + '</td></tr>');
            // Add more info if needed, e.g., teacher name, date
            printWindow.document.write('<tr><td><strong>Teacher:</strong> ' + teacherName + '</td>');
            printWindow.document.write('<td><strong>Date Printed:</strong> ' + new Date().toLocaleDateString() + '</td></tr>');
            printWindow.document.write('</table>');


            const clonedTable = tableToPrint.cloneNode(true);

            // Remove lock icons from header for printing
            clonedTable.querySelectorAll('.lock-button').forEach(icon => icon.remove());
            
            // Remove icons from student ID for cleaner print
            clonedTable.querySelectorAll('.student-id .bi-person-badge').forEach(icon => icon.remove());


            // For select elements, replace them with their selected text value
            clonedTable.querySelectorAll('select.grade-select').forEach(select => {
                const selectedOption = select.options[select.selectedIndex];
                const cell = select.closest('td');
                if (cell) {
                    cell.textContent = selectedOption ? selectedOption.text : 'N/A';
                    cell.style.textAlign = 'center'; // Ensure text is centered like the select
                }
            });

            // For input number elements, replace them with their value as text
            clonedTable.querySelectorAll('input.grade-input[type="number"]').forEach(input => {
                const cell = input.closest('td');
                if (cell) {
                    cell.textContent = input.value !== '' ? input.value : 'N/A';
                     cell.style.textAlign = 'center'; // Ensure text is centered
                }
            });
            
            // Ensure headers that were centered remain so
            clonedTable.querySelectorAll('thead th.text-center, thead th.period-header').forEach(th => {
                 th.style.textAlign = 'center';
            });


            printWindow.document.write(clonedTable.outerHTML);
            printWindow.document.write('</body></html>');
            printWindow.document.close();
            printWindow.focus(); 

            printWindow.onload = function() {
                printWindow.print();
                // printWindow.close(); // You can uncomment this to close the window automatically after print
            };
        }

        // Keyboard navigation for grade inputs
        const gradeInputs = Array.from(document.querySelectorAll('input.grade-input, select.grade-select'));

        gradeInputs.forEach((input, idx) => {
            input.addEventListener('keydown', function(e) {
                const current = e.target;
                const currentCell = current.closest('td');
                const currentRow = current.closest('tr');
                const allRows = Array.from(currentRow.parentNode.children);
                const currentRowIdx = allRows.indexOf(currentRow);
                const allCells = Array.from(currentRow.children);
                const currentCellIdx = allCells.indexOf(currentCell);

                // Only handle arrow keys
                if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
                    e.preventDefault();

                    let targetInput = null;

                    if (e.key === 'ArrowLeft' && currentCellIdx > 0) {
                        // Move to previous cell in the same row
                        targetInput = allCells[currentCellIdx - 1].querySelector('input.grade-input, select.grade-select');
                    } else if (e.key === 'ArrowRight' && currentCellIdx < allCells.length - 1) {
                        // Move to next cell in the same row
                        targetInput = allCells[currentCellIdx + 1].querySelector('input.grade-input, select.grade-select');
                    } else if (e.key === 'ArrowUp' && currentRowIdx > 0) {
                        // Move to the same cell in the previous row
                        const prevRow = allRows[currentRowIdx - 1];
                        const prevCell = prevRow.children[currentCellIdx];
                        if (prevCell) {
                            targetInput = prevCell.querySelector('input.grade-input, select.grade-select');
                        }
                    } else if (e.key === 'ArrowDown' && currentRowIdx < allRows.length - 1) {
                        // Move to the same cell in the next row
                        const nextRow = allRows[currentRowIdx + 1];
                        const nextCell = nextRow.children[currentCellIdx];
                        if (nextCell) {
                            targetInput = nextCell.querySelector('input.grade-input, select.grade-select');
                        }
                    }

                    if (targetInput) {
                        targetInput.focus();
                        if (targetInput.select) targetInput.select();
                    }
                }
            });
        });
    });
</script>

<div class="chatbot-container">
    <button type="button" class="btn btn-primary btn-chatbot" id="chatbotToggle" data-bs-toggle="popover" data-bs-placement="top" title="UDM Isla">
        <i class="bi bi-chat-dots-fill"></i>
    </button>

    <div id="chatbotPopoverContent" style="display: none;">
        <div class="chatbot-messages">
        </div>
        <div class="input-group mb-2">
            <input type="text" id="chatbotInput" class="form-control" placeholder="Type your question...">
            <button class="btn btn-primary" type="button" id="chatbotSend">Send</button>
        </div>
        <button class="btn btn-success w-100" type="button" id="chatbotSaveDbButton" style="display: none;">
            <i class="bi bi-download"></i> Save Database Now
        </button>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const chatbotToggle = document.getElementById('chatbotToggle');
    const chatbotPopoverContentTemplate = document.getElementById('chatbotPopoverContent');

    let chatbotMessages = null;
    let chatbotInput = null;
    let chatbotSend = null;
    let chatbotSaveDbButton = null;
    let typingIndicatorElement = null;

    const CHAT_STORAGE_KEY = 'udm_isla_conversation';

    const popover = new bootstrap.Popover(chatbotToggle, {
        html: true,
        content: function() {
            const contentClone = chatbotPopoverContentTemplate.cloneNode(true);
            contentClone.style.display = 'block';
            return contentClone.innerHTML;
        },
        sanitize: false
    });

    chatbotToggle.addEventListener('shown.bs.popover', function () {
        const activePopover = document.querySelector('.popover.show');
        if (activePopover) {
        // Move popover slightly to the left (e.g., 20px)
        const currentLeft = parseFloat(window.getComputedStyle(activePopover).left) || 0;
        activePopover.style.left = `${currentLeft - 70}px`;
            chatbotMessages = activePopover.querySelector('.chatbot-messages');
            chatbotInput = activePopover.querySelector('#chatbotInput');
            chatbotSend = activePopover.querySelector('#chatbotSend');
            chatbotSaveDbButton = activePopover.querySelector('#chatbotSaveDbButton');

            loadConversation();

            if (chatbotSend) {
                chatbotSend.removeEventListener('click', sendMessage);
                chatbotSend.addEventListener('click', sendMessage);
            }
            if (chatbotInput) {
                chatbotInput.removeEventListener('keypress', handleKeyPress);
                chatbotInput.addEventListener('keypress', handleKeyPress);
                chatbotInput.focus();
            }
            if (chatbotSaveDbButton) {
                chatbotSaveDbButton.removeEventListener('click', saveDatabaseFromChatbot);
                chatbotSaveDbButton.addEventListener('click', saveDatabaseFromChatbot);
            }

            if (chatbotMessages) {
                chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
            }
        }
    });

    function handleKeyPress(e) {
        if (e.key === 'Enter') {
            sendMessage();
        }
    }

    function showTypingIndicator() {
        if (!chatbotMessages) return;
        typingIndicatorElement = document.createElement('div');
        typingIndicatorElement.classList.add('message-container', 'typing-indicator');
        typingIndicatorElement.innerHTML = `
            <span></span>
            <span></span>
            <span></span>
        `;
        chatbotMessages.appendChild(typingIndicatorElement);
        chatbotMessages.scrollTop = chatbotMessages.scrollTop;
    }

    function hideTypingIndicator() {
        if (typingIndicatorElement && chatbotMessages) {
            chatbotMessages.removeChild(typingIndicatorElement);
            typingIndicatorElement = null;
        }
    }

    function sendMessage() {
        if (!chatbotInput || !chatbotMessages) {
            console.error('Chatbot input or messages container not found at sendMessage. Popover not ready?');
            return;
        }

        const userMessage = chatbotInput.value.trim();
        if (userMessage === '') return;

        appendMessage('You', userMessage);
        chatbotInput.value = '';
        chatbotInput.disabled = true;
        if (chatbotSend) {
            chatbotSend.disabled = true;
        }

        if (chatbotSaveDbButton) {
            chatbotSaveDbButton.style.display = 'none';
        }

        showTypingIndicator();

        // Check for "clear chat" command
        if (userMessage.toLowerCase() === 'clear chat') {
            hideTypingIndicator();
            clearChat();
            appendMessage('Isla', "Chat history cleared!", false);
            chatbotInput.disabled = false;
            if (chatbotSend) {
                chatbotSend.disabled = false;
            }
            chatbotInput.focus();
            saveConversation();
            return;
        }

        if (userMessage.toLowerCase().includes('save database')) {
            hideTypingIndicator();
            if (chatbotSaveDbButton) {
                chatbotSaveDbButton.style.display = 'block';
                appendMessage('Isla', "Click the 'Save Database Now' button below to save your database.", false);
            } else {
                appendMessage('Isla', "I can't offer a direct save button right now. Please try again later or look for the button on the dashboard.", false);
            }
            chatbotInput.disabled = false;
            if (chatbotSend) {
                chatbotSend.disabled = false;
            }
            chatbotInput.focus();
            chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
            saveConversation();
            return;
        }

        const deleteNoteMatch = userMessage.toLowerCase().match(/^delete note (\d+)$/);
        if (deleteNoteMatch) {
            const noteNumber = parseInt(deleteNoteMatch[1]);
            hideTypingIndicator();
            deleteNoteFromChatbot(noteNumber);
            return;
        }

        fetch('../public/chatbot_response.php', { // Adjusted path for chatbot_response.php
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'query=' + encodeURIComponent(userMessage)
        })
        .then(response => response.json())
        .then(data => {
            hideTypingIndicator();
            appendMessage('Isla', data.response, true);
            chatbotInput.disabled = false;
            if (chatbotSend) {
                chatbotSend.disabled = false;
            }
            chatbotInput.focus();
            saveConversation();
        })
        .catch(error => {
            console.error('Error fetching chatbot response:', error);
            hideTypingIndicator();
            appendMessage('Isla', "Sorry, I'm having trouble connecting right now. Please try again later.", false);
            chatbotInput.disabled = false;
            if (chatbotSend) {
                chatbotSend.disabled = false;
            }
            saveConversation();
        });

        chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
    }

    function saveDatabaseFromChatbot() {
        if (!chatbotMessages || !chatbotInput) {
            console.error('Chatbot messages or input not found for saveDatabaseFromChatbot.');
            return;
        }

        appendMessage('Isla', "Saving your database...", false);
        chatbotInput.disabled = true;
        if (chatbotSend) chatbotSend.disabled = true;
        if (chatbotSaveDbButton) chatbotSaveDbButton.disabled = true;

        fetch('../public/export_db.php', { // Adjusted path for export_db.php
            method: 'POST',
        })
        .then(response => {
            if (response.ok) {
                appendMessage('Isla', "Database saved successfully! It should be downloaded to your Google Drive folder.", false);
            } else {
                return response.text().then(text => {
                    throw new Error(`Database save failed: ${text}`);
                });
            }
        })
        .catch(error => {
            console.error('Error saving database:', error);
            appendMessage('Isla', `Failed to save database: ${error.message}. Please try again.`, false);
        })
        .finally(() => {
            chatbotInput.disabled = false;
            if (chatbotSend) chatbotSend.disabled = false;
            if (chatbotSaveDbButton) {
                chatbotSaveDbButton.disabled = false;
                chatbotSaveDbButton.style.display = 'none';
            }
            chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
            chatbotInput.focus();
            saveConversation();
            // Optional: location.reload(); // Uncomment if you want to force a page reload after save
        });
    }

    function deleteNoteFromChatbot(noteNumber) {
        if (!chatbotMessages || !chatbotInput) {
            console.error('Chatbot messages or input not found for deleteNoteFromChatbot.');
            return;
        }

        appendMessage('Isla', `Attempting to delete note number ${noteNumber}...`, false);
        chatbotInput.disabled = true;
        if (chatbotSend) chatbotSend.disabled = true;

        fetch('../public/dashboard.php', { // Note: Deleting notes is handled by dashboard.php
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `delete_note=1&note_number=${noteNumber}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                appendMessage('Isla', data.message, false);
            } else {
                appendMessage('Isla', `Error: ${data.message}`, false);
            }
            chatbotInput.disabled = false;
            if (chatbotSend) chatbotSend.disabled = false;
            chatbotInput.focus();
            saveConversation();
            // Optional: location.reload(); // Uncomment if you want to force a page reload after delete
        })
        .catch(error => {
            console.error('Error deleting note:', error);
            appendMessage('Isla', "Sorry, I couldn't delete the note due to a network error. Please try again later.", false);
            chatbotInput.disabled = false;
            if (chatbotSend) chatbotSend.disabled = false;
            saveConversation();
        });
        chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
    }

    function appendMessage(sender, message, withTypingEffect = false) {
        if (!chatbotMessages) {
            console.error('Chatbot messages container not found in appendMessage.');
            return;
        }

        const messageContainer = document.createElement('div');
        messageContainer.classList.add('message-container');

        const messageContent = document.createElement('p');

        if (sender === 'You') {
            messageContainer.classList.add('user-message');
            messageContent.innerHTML = `<strong>${sender}:</strong> ${message}`;
            messageContainer.appendChild(messageContent);
            chatbotMessages.appendChild(messageContainer);
        } else if (sender === 'Isla') {
            messageContainer.classList.add('isla-message');
            messageContent.innerHTML = `<strong>${sender}:</strong> `;
            messageContainer.appendChild(messageContent);
            chatbotMessages.appendChild(messageContainer);

            if (withTypingEffect) {
                let i = 0;
                const typingSpeed = 7;
                function typeWriter() {
                    if (i < message.length) {
                        messageContent.innerHTML += message.charAt(i);
                        i++;
                        chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
                        setTimeout(typeWriter, typingSpeed);
                    } else {
                        saveConversation();
                    }
                }
                setTimeout(typeWriter, 300);
            } else {
                messageContent.innerHTML += message;
                saveConversation();
            }
        }
        chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
    }

    function saveConversation() {
        if (chatbotMessages) {
            localStorage.setItem(CHAT_STORAGE_KEY, chatbotMessages.innerHTML);
        }
    }

    function loadConversation() {
        if (chatbotMessages) {
            const savedConversation = localStorage.getItem(CHAT_STORAGE_KEY);
            if (savedConversation) {
                chatbotMessages.innerHTML = savedConversation;
            } else {
                chatbotMessages.innerHTML = `
                    <div class="message-container isla-message">
                        <p><strong>Isla:</strong> Hi there! How can I help you today? Type 'list all commands' to see all the available commands.</p>
                    </div>
                `;
            }
            chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
        }
    }
    
    function clearChat() {
        if (chatbotMessages) {
            // Reset to initial welcome message
            chatbotMessages.innerHTML = `
                <div class="message-container isla-message">
                    <p><strong>Isla:</strong> Hi there! How can I help you today?</p>
                </div>
            `;
            localStorage.removeItem(CHAT_STORAGE_KEY); // Clear from storage
            chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
        }
    }


    // Check if logout button exists and add event listener
    // Assuming your logout button in the sidebar modal has an ID or can be selected
    // For this example, I'll assume there's a general logout link/button you might want to target
    // This is a generic selector, you might need to make it more specific
    const logoutButton = document.querySelector('a[href="../public/logout.php"]');
    if (logoutButton && logoutButton.closest('.modal')) { // Ensure it's the one in the modal
         logoutButton.addEventListener('click', function() {
            localStorage.removeItem(CHAT_STORAGE_KEY);
        });
    }
    // Also, if your sidebar logout has an ID like 'logoutLinkInSidebar'
    // const sidebarLogoutButton = document.getElementById('logoutLinkInSidebar');
    // if (sidebarLogoutButton) {
    //     sidebarLogoutButton.addEventListener('click', function() {
    //         localStorage.removeItem(CHAT_STORAGE_KEY);
    //     });
    // }

});
</script>
</body>
</html>