<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/db.php';
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    header("Location: ../public/login.php");
    exit();
}

$teacher_id = $_SESSION['teacher_id'];
$full_name = $_SESSION['full_name'] ?? 'Teacher';

// PDO connection check
if (!isset($conn) || $conn === null) {
    error_log("Database connection failed in your_classes.php");
    die("Database connection not established. Please check your '../config/db.php' file.");
}

function tableExists($conn, $tableName) {
    $query = "SELECT name FROM sqlite_master WHERE type='table' AND name=?";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->execute([$tableName]);
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }
    error_log("Warning: Table existence check failed for '$tableName'. Error: " . implode(" ", $conn->errorInfo()));
    return false;
}

/**
 * Safely deletes records from a table.
 * Logs detailed errors if deletion fails.
 */
function safeDeleteFromTable($conn, $tableName, $whereClause, $params) {
    if (!tableExists($conn, $tableName)) {
        error_log("Warning: Table '$tableName' does not exist. Skipping deletion.");
        return true;
    }
    
    $query = "DELETE FROM `$tableName` WHERE $whereClause";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Failed to prepare statement for table '$tableName': " . implode(" ", $conn->errorInfo()) . ". Query: $query");
        return false;
    }

    try {
        $success = $stmt->execute($params);
        if (!$success) {
            $error_detail = "Error: " . implode(" ", $stmt->errorInfo()) . ". SQL: " . $query . ". Params: " . json_encode($params);
            error_log("Failed to execute delete for table '$tableName'. Details: " . $error_detail);
        }
        return $success;
    } catch (PDOException $e) {
        error_log("PDO Exception in safeDeleteFromTable: " . $e->getMessage());
        return false;
    }
}

if (isset($_POST['delete_class']) && isset($_POST['class_id'])) {
    $class_id = (int)$_POST['class_id'];
    $conn->beginTransaction();
    try {
        $allSuccessful = true;
        $enrollment_ids = [];

        // Fetch enrollment_ids associated with the class
        if (tableExists($conn, 'enrollments')) {
            $stmt_enrollments = $conn->prepare("SELECT enrollment_id FROM enrollments WHERE class_id = ?");
            if ($stmt_enrollments) {
                $stmt_enrollments->execute([$class_id]);
                while ($row = $stmt_enrollments->fetch(PDO::FETCH_ASSOC)) {
                    $enrollment_ids[] = $row['enrollment_id'];
                }
            } else {
                $allSuccessful = false;
                error_log("Failed to prepare statement for fetching enrollment_ids for class_id $class_id: " . implode(" ", $conn->errorInfo()));
            }
        }

        // Delete records from tables related by enrollment_id
        if ($allSuccessful && !empty($enrollment_ids)) {
            $placeholders = implode(',', array_fill(0, count($enrollment_ids), '?'));
            $enrollment_related_tables = ['student_grades', 'final_grades'];

            foreach ($enrollment_related_tables as $table) {
                if (!safeDeleteFromTable($conn, $table, "enrollment_id IN ($placeholders)", $enrollment_ids)) {
                    $allSuccessful = false;
                    error_log("Failed to delete records from '$table' for class_id $class_id.");
                    break;
                }
            }
        }

        // Delete records from tables directly related by class_id
        if ($allSuccessful) {
            $direct_delete_tables = [
                ['grade_components', 'class_id = ?', [$class_id]],
                ['enrollments', 'class_id = ?', [$class_id]],
                ['grades', 'class_id = ?', [$class_id]],
                ['class_calendar_notes', 'class_id = ?', [$class_id]]
            ];
            foreach ($direct_delete_tables as $item) {
                if (!safeDeleteFromTable($conn, $item[0], $item[1], $item[2])) {
                    $allSuccessful = false;
                    error_log("Failed to delete records from '$item[0]' for class_id $class_id.");
                    break;
                }
            }
        }

        // Finally, delete the class itself
        if ($allSuccessful) {
            if (tableExists($conn, 'classes')) {
                $delete_class_sql = "DELETE FROM classes WHERE class_id = ? AND teacher_id = ?";
                $stmt = $conn->prepare($delete_class_sql);
                if ($stmt) {
                    if ($stmt->execute([$class_id, $teacher_id])) {
                        if ($stmt->rowCount() > 0) {
                            // Successfully deleted
                        } else {
                            $allSuccessful = false;
                            $_SESSION['error_message'] = "Class not found under your account, or it was already deleted. No changes made.";
                            error_log("Attempt to delete class_id $class_id by teacher_id $teacher_id resulted in 0 affected rows.");
                        }
                    } else {
                        $allSuccessful = false;
                        error_log("Failed to execute delete for 'classes' table. Class ID: $class_id, Teacher ID: $teacher_id. Error: " . implode(" ", $stmt->errorInfo()));
                        $_SESSION['error_message'] = "Database error: Failed to delete the main class entry. Please check server logs.";
                    }
                } else {
                    $allSuccessful = false;
                    error_log("Failed to prepare delete statement for 'classes' table. Class ID: $class_id. Error: " . implode(" ", $conn->errorInfo()));
                    $_SESSION['error_message'] = "Database error: Failed to prepare to delete the main class entry. Please check server logs.";
                }
            } else {
                $allSuccessful = false;
                error_log("Critical error: 'classes' table does not exist. Cannot complete deletion for class_id $class_id.");
                $_SESSION['error_message'] = "Critical error: The main 'classes' table is missing. Deletion failed.";
            }
        }

        if ($allSuccessful) {
            $conn->commit();
            $_SESSION['success_message'] = "Class deleted successfully!";
        } else {
            $conn->rollBack();
            if (!isset($_SESSION['error_message'])) {
                $_SESSION['error_message'] = "Failed to delete class due to issues with related records or permissions. Please check server logs for details.";
            }
            error_log("Class deletion rolled back for class_id $class_id due to errors.");
        }

    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Exception during class deletion for class_id $class_id: " . $e->getMessage());
        $_SESSION['error_message'] = "An unexpected error occurred while deleting the class: " . htmlspecialchars($e->getMessage());
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Function to determine the URL for inputting grades based on grading system type
function getGradesInputUrl($class_id, $grading_system_type) {
    return "../teacher/" . ($grading_system_type === 'numerical' ? "input_grades_numerical.php" : "input_grades_final_only.php") . "?class_id=" . $class_id;
}

// Fetch classes for the logged-in teacher
$classes = [];
if (tableExists($conn, 'classes') && tableExists($conn, 'subjects') && tableExists($conn, 'sections')) {
    $sql = "SELECT c.class_id, s.subject_code, s.subject_name, sec.section_name, sec.academic_year, sec.semester, c.grading_system_type 
            FROM classes c 
            JOIN subjects s ON c.subject_id = s.subject_id 
            JOIN sections sec ON c.section_id = sec.section_id 
            WHERE c.teacher_id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->execute([$teacher_id]);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        error_log("Failed to prepare statement for getting classes: " . implode(" ", $conn->errorInfo()));
        $_SESSION['error_message'] = "Database error: Unable to prepare for retrieving classes.";
    }
} else {
    $_SESSION['error_message'] = "Database tables essential for displaying classes are missing. Please ensure your database is properly set up (classes, subjects, or sections might be missing).";
    error_log("One or more critical tables (classes, subjects, sections) are missing.");
}

// *** Removed the student grade fetching logic as it will be replaced by the calendar ***
// $students_with_grades = [];
// $grading_system_type = 'N/A';
// $selected_class_id = null; // Initialize selected_class_id
// ... (rest of the removed student grade fetching code)
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UDM IntelliGrade - Your Classes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css' rel='stylesheet' />
    <style>
        body { background-color: #f5f3e1; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-wrapper { display: flex; min-height: 100vh; }
        .sidebar { width: 280px; background-color: #006400; color: #E7E7E7; padding: 0; position: fixed; top: 0; left: 0; height: 100vh; z-index: 1030; overflow-y: auto; transition: width 0.3s ease; display: flex; flex-direction: column; }
        .sidebar-header { padding: 1rem; border-bottom: 1px solid #008000; display: flex; align-items: center; justify-content: flex-start; min-height: 70px; background-color: #004d00; }
        .logo-image { max-height: 40px; }
        .logo-text { overflow: hidden; }
        .logo-text h5.uni-name { margin: 0; font-size: 0.9rem; font-weight: 600; color: #FFFFFF; line-height: 1.1; white-space: nowrap; }
        .logo-text p.tagline { margin: 0; font-size: 0.7rem; font-weight: 300; color: #E7E7E7; line-height: 1; white-space: nowrap; }
        .sidebar .nav-menu { padding: 1rem; flex-grow: 1; display: flex; flex-direction: column; }
        .sidebar .nav-link { color: #E7E7E7; padding: 0.85rem 1.25rem; font-size: 0.95rem; border-radius: 0.3rem; margin-bottom: 0.25rem; transition: background-color 0.2s ease, color 0.2s ease; display: flex; align-items: center; white-space: nowrap; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: #FFFFFF; background-color: #008000; }
        .sidebar .nav-link .bi { margin-right: 0.85rem; font-size: 1.1rem; vertical-align: middle; width: 20px; text-align: center; }
        .sidebar .nav-link span { flex-grow: 1; overflow: hidden; text-overflow: ellipsis; }
        .sidebar .logout-item { margin-top: auto; }
        .sidebar .logout-item hr { border-color: #008000; margin-top: 1rem; margin-bottom:1rem; }
        .content-area { margin-left: 280px; flex-grow: 1; padding: 2.5rem; width: calc(100% - 280px); transition: margin-left 0.3s ease, width 0.3s ease; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2.5rem; padding-bottom: 1.25rem; border-bottom: 1px solid #d6d0b8; }
        .page-header h2 { margin: 0; font-weight: 500; font-size: 1.75rem; color: #006400; }
        .card { border: 1px solid #d6d0b8; box-shadow: 0 0.25rem 0.75rem rgba(0, 0, 0, 0.05); border-radius: 0.5rem; background-color: #fcfbf7; }
        .card-header { background-color: #e9e5d0; border-bottom: 1px solid #d6d0b8; padding: 1rem 1.25rem; font-weight: 500; color: #006400; }
        .table th { background-color: #e9e5d0; font-weight: 500; color: #006400; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .table td { vertical-align: middle; font-size: 0.95rem; background-color: #fcfbf7; }
        .table .btn-action-group .btn { margin-right: 0.3rem; }
        .table .btn-action-group .btn:last-child { margin-right: 0; }
        .btn-primary { background-color: #006400; border-color: #006400; }
        .btn-primary:hover { background-color: #004d00; border-color: #004d00; }
        .btn-outline-primary { color: #006400; border-color: #006400; }
        .btn-outline-primary:hover { background-color: #006400; border-color: #006400; color: white; }
        .btn-outline-secondary, .btn-outline-success, .btn-outline-info { color: #006400; border-color: #006400; }
        .btn-outline-secondary:hover, .btn-outline-success:hover, .btn-outline-info:hover { background-color: #006400; border-color: #006400; color: white; }
        .btn-outline-warning { color: #856404; border-color: #856404; }
        .btn-outline-warning:hover { background-color: #856404; border-color: #856404; color: white; }
        .btn-outline-danger { color: #dc3545; border-color: #dc3545; }
        .btn-outline-danger:hover { background-color: #dc3545; border-color: #dc3545; color: white; }
        .alert-info { background-color: #e7f3e7; border-color: #d0ffd0; color: #006400; }
        .footer { padding: 1.5rem 0; margin-top: 2rem; font-size: 0.875rem; color: #006400; border-top: 1px solid #d6d0b8; }
        @media (max-width: 992px) {
            .sidebar { width: 80px; }
            .sidebar .logo-text { display: none; }
            .sidebar .sidebar-header { justify-content: center; padding: 1.25rem 0.5rem; }
            .sidebar .logo-image { margin-right: 0; }
            .sidebar .nav-link span { display: none; }
            .sidebar .nav-link .bi { margin-right: 0; display: block; text-align: center; font-size: 1.5rem; }
            .sidebar:hover { width: 280px; }
            .sidebar:hover .logo-text { display: block; }
            .sidebar:hover .sidebar-header { justify-content: flex-start; padding: 1rem; }
            .sidebar:hover .logo-image { margin-right: 0.5rem; }
            .sidebar:hover .nav-link span { display: inline; }
            .sidebar:hover .nav-link .bi { margin-right: 0.85rem; display: inline-block; text-align: center; font-size: 1.1rem; }
            .content-area { margin-left: 80px; width: calc(100% - 80px); }
            .sidebar:hover + .content-area { margin-left: 280px; width: calc(100% - 280px); }
        }
        @media (max-width: 768px) {
            .sidebar { width: 100%; height: auto; position: static; z-index: auto; flex-direction: column; }
            .sidebar .logo-text { display: block; }
            .sidebar .sidebar-header { justify-content: flex-start; padding: 1rem; }
            .sidebar .logo-image { margin-right: 0.5rem; }
            .sidebar .nav-link span { display: inline; }
            .sidebar .nav-link .bi { margin-right: 0.85rem; font-size: 1.1rem; display: inline-block; text-align: center; }
            .sidebar .nav-menu { flex-grow: 0; }
            .sidebar .logout-item { margin-top: 1rem; }
            .content-area { margin-left: 0; width: 100%; padding: 1.5rem; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .page-header h2 { font-size: 1.5rem; }
            .page-header .btn { margin-top: 1rem; }
            .table-responsive { overflow-x: auto; }
            .btn-action-group { white-space: nowrap; }
             }

        /* Chatbot specific styles (retained) */
        .chatbot-container {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1050;
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
            max-width: 350px;
        }

        .popover-header {
            background-color: #006400;
            color: white;
            font-weight: bold;
        }

        .popover-body {
            padding: 15px;
            max-height: 400px;
            overflow-y: auto;
        }

        .chatbot-messages {
            height: 200px;
            overflow-y: auto;
            border: 1px solid #ddd;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 10px;
            background-color: #f9f9f9;
            display: flex;
            flex-direction: column;
        }

        .message-container {
            display: flex;
            margin-bottom: 8px;
            max-width: 90%;
        }

        .user-message {
            align-self: flex-end;
            background-color: #e0f7fa;
            border-radius: 15px 15px 0 15px;
            padding: 8px 12px;
            margin-left: auto;
        }

        .isla-message {
            align-self: flex-start;
            background-color: #e7f3e7;
            border-radius: 15px 15px 15px 0;
            padding: 8px 12px;
            margin-right: auto;
        }

        .message-container strong {
            font-weight: bold;
            margin-bottom: 2px;
            display: block;
        }
        .user-message strong {
             color: #0056b3;
        }
        .isla-message strong {
             color: #006400;
        }

        .message-container p {
            margin: 0;
            line-height: 1.4;
            word-wrap: break-word;
            white-space: pre-wrap;
        }

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

        /* FullCalendar specific styling adjustments for better integration */
        .fc .fc-toolbar-title {
            color: #006400; /* Dark green for calendar title */
        }
        .fc .fc-button-primary {
            background-color: #006400;
            border-color: #006400;
            color: white;
        }
        .fc .fc-button-primary:hover {
            background-color: #004d00;
            border-color: #004d00;
        }
        .fc-event {
            background-color: #8BC34A; /* A lighter green for events */
            border-color: #7CB342;
            color: #333; /* Darker text for readability on lighter background */
            padding: 2px 5px;
            border-radius: 3px;
            font-size: 0.85em;
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
            <li class="nav-item"><a class="nav-link" href="../teacher/create_class.php"><i class="bi bi-plus-square-dotted"></i> <span>Create New Class</span></a></li>
            <li class="nav-item"><a class="nav-link active" aria-current="page" href="your_classes.php"><i class="bi bi-person-workspace"></i> <span>Your Classes</span></a></li>
            <li class="nav-item"><a class="nav-link" href="../public/manage_backup.php"><i class="bi bi-cloud-arrow-down-fill"></i> <span>Manage Backup</span></a></li>
            <li class="nav-item"><a class="nav-link" href="../public/gradingsystem.php"><i class="bi bi-calculator"></i> <span>Grading System</span></a></li>
            <li class="nav-item logout-item"><hr><a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#logoutModal"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a></li>
        </ul>
    </nav>

    <main class="content-area">
        <header class="page-header"><h2>Your Classes</h2></header>

        <?php if (isset($_SESSION['success_message'])) { echo '<div class="alert alert-success alert-dismissible fade show" role="alert">' . htmlspecialchars($_SESSION['success_message']) . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>'; unset($_SESSION['success_message']); } ?>
        <?php if (isset($_SESSION['error_message'])) { echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">' . htmlspecialchars($_SESSION['error_message']) . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>'; unset($_SESSION['error_message']); } ?>

        <?php if (empty($classes)): ?>
            <div class="card text-center shadow-sm">
                <div class="card-body p-5">
                    <i class="bi bi-info-circle-fill text-success" style="font-size: 3rem; margin-bottom: 1rem; color: #006400;"></i>
                    <h5 class="card-title" style="color: #006400;">No Classes Yet</h5>
                    <p class="card-text text-muted">You have not created or been assigned to any classes. <br>Get started by creating your first class.</p>
                    <a href="../teacher/create_class.php" class="btn btn-lg btn-primary mt-3"><i class="bi bi-plus-circle-fill"></i> Create Your First Class</a>
                </div>
            </div>
        <?php else: ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header d-flex align-items-center"><i class="bi bi-list-task me-2"></i> Class Overview</div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th><i class="bi bi-book me-1"></i> Subject</th>
                                    <th><i class="bi bi-people me-1"></i> Section</th>
                                    <th><i class="bi bi-bar-chart-steps me-1"></i> Grading Type</th>
                                    <th><i class="bi bi-gear me-1"></i> Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($classes as $class): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($class['subject_code']) ?> - <?= htmlspecialchars($class['subject_name']) ?></td>
                                        <td><?= htmlspecialchars($class['section_name']) ?> (<?= htmlspecialchars($class['academic_year']) ?> - <?= htmlspecialchars($class['semester']) ?>)</td>
                                        <td><?= htmlspecialchars($class['grading_system_type'] === 'numerical' ? 'Numerical' : 'A/NA-Based') ?></td>
                                        <td class="btn-action-group" style="white-space: nowrap;">
                                            <a href="../teacher/enroll_students.php?class_id=<?= $class['class_id'] ?>" class="btn btn-sm btn-outline-primary" title="Enroll Students"><i class="bi bi-person-plus-fill"></i> <span class="d-none d-lg-inline">Enroll</span></a>
                                            <a href="../teacher/manage_components.php?class_id=<?= $class['class_id'] ?>" class="btn btn-sm btn-outline-secondary" title="Manage Components"><i class="bi bi-sliders"></i> <span class="d-none d-lg-inline">Components</span></a>
                                            <a href="<?= getGradesInputUrl($class['class_id'], $class['grading_system_type']) ?>" class="btn btn-sm btn-outline-success" title="Input Grades"><i class="bi bi-pencil-square"></i> <span class="d-none d-lg-inline">Grades</span></a>
                                            <a href="<?= ($class['grading_system_type'] === 'numerical') ? '../teacher/class_record_numerical_computed.php?class_id=' . $class['class_id'] : '../teacher/view_class_record.php?class_id=' . $class['class_id'] ?>" class="btn btn-sm btn-outline-info" title="View Class Record"><i class="bi bi-eye-fill"></i> <span class="d-none d-lg-inline">View</span></a>
                                            <a href="../teacher/edit_class.php?class_id=<?= $class['class_id'] ?>" class="btn btn-sm btn-outline-warning" title="Edit Class"><i class="bi bi-pencil"></i> <span class="d-none d-lg-inline">Edit</span></a>
                                            <button type="button" class="btn btn-sm btn-outline-danger" title="Delete Class" onclick="confirmDelete(<?= $class['class_id'] ?>, '<?= htmlspecialchars($class['subject_code'] . ' - ' . $class['subject_name'] . ' (' . $class['section_name'] . ')', ENT_QUOTES) ?>')"><i class="bi bi-trash"></i> <span class="d-none d-lg-inline">Delete</span></button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mt-4">
                <div class="card-header d-flex align-items-center"><i class="bi bi-calendar-event me-2"></i> Class Calendar & Notes</div>
                <div class="card-body">
                    <div class="mb-4">
                        <label for="classCalendarSelect" class="form-label">Select Class Calendar:</label>
                        <select class="form-select" id="classCalendarSelect">
                            <option value="">-- Choose a Class --</option>
                            <?php foreach ($classes as $class_item): ?>
                                <option value="<?= $class_item['class_id'] ?>">
                                    <?= htmlspecialchars($class_item['subject_code'] . ' - ' . $class_item['subject_name'] . ' (' . $class_item['section_name'] . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="calendar" style="display: none;">
                        </div>

                    <div id="calendar-info-message" class="alert alert-info mt-3" role="alert">
                        Please select a class from the dropdown to view its calendar and add notes.
                    </div>
                </div>
            </div>
            <?php endif; ?>

        <footer class="footer text-center">&copy; <?= date('Y') ?> Universidad De Manila - IntelliGrade System. All rights reserved.</footer>
    </main>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../public/js/logout-handler.js"></script>
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js'></script>
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales-all.min.js'></script>
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/core/locales/en-gb.global.min.js'></script>

<div class="modal fade" id="noteModal" tabindex="-1" aria-labelledby="noteModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="noteModalLabel">Add/Edit Note</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="noteForm">
          <input type="hidden" id="noteId" name="note_id">
          <input type="hidden" id="noteClassId" name="class_id">
          <div class="mb-3">
            <label for="noteDate" class="form-label">Date</label>
            <input type="date" class="form-control" id="noteDate" name="note_date" readonly>
          </div>
          <div class="mb-3">
            <label for="noteTitle" class="form-label">Title</label>
            <input type="text" class="form-control" id="noteTitle" name="note_title" required>
          </div>
          <div class="mb-3">
            <label for="noteDescription" class="form-label">Description</label>
            <textarea class="form-control" id="noteDescription" name="note_description" rows="3"></textarea>
          </div>
          <div class="mb-3">
            <label for="noteType" class="form-label">Type</label>
            <select class="form-select" id="noteType" name="note_type">
              <option value="activity">Activity</option>
              <option value="quiz">Quiz</option>
              <option value="exam">Exam</option>
              <option value="other">Other</option>
            </select>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="deleteNoteBtn" style="display: none;">Delete</button>
        <button type="button" class="btn btn-primary" id="saveNoteBtn">Save Note</button>
      </div>
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

<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger-subtle">
                <h5 class="modal-title" id="deleteConfirmModalLabel"><i class="bi bi-exclamation-triangle-fill me-2"></i> Confirm Delete Class</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-danger"><strong>⚠️ Warning:</strong> This action is critical and will permanently delete:</p>
                <ul>
                    <li>The class itself.</li>
                    <li>All student enrollments in this class.</li>
                    <li>All associated grades (prelim, midterm, final).</li>
                    <li>All defined grade components for this class.</li>
                    <li>All other class-related data.</li>
                    <li>All calendar notes for this class.</li> </ul>
                <p>This action cannot be undone.</p>
                <p>Are you sure you want to delete the class: <strong id="deleteClassName"></strong>?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form id="deleteClassForm" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" method="POST" class="d-inline">
                    <input type="hidden" name="class_id" id="deleteClassId">
                    <button type="submit" name="delete_class" class="btn btn-danger">Yes, Delete This Class</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteNoteConfirmModal" tabindex="-1" aria-labelledby="deleteNoteConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="deleteNoteConfirmModalLabel"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Delete Note</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this note?</p>
                <p class="text-danger mb-0"><small>This action cannot be undone.</small></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteNoteBtn">Delete Note</button>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(classId, className) {
    document.getElementById('deleteClassId').value = classId;
    document.getElementById('deleteClassName').textContent = className;
    var deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
    deleteModal.show();
}

// --- Calendar Initialization and Logic ---
document.addEventListener('DOMContentLoaded', function() {
    const classCalendarSelect = document.getElementById('classCalendarSelect');
    const calendarEl = document.getElementById('calendar');
    const calendarInfoMessage = document.getElementById('calendar-info-message');
    const noteModal = new bootstrap.Modal(document.getElementById('noteModal'));
    const noteForm = document.getElementById('noteForm');
    const noteIdInput = document.getElementById('noteId');
    const noteClassIdInput = document.getElementById('noteClassId');
    const noteDateInput = document.getElementById('noteDate');
    const noteTitleInput = document.getElementById('noteTitle');
    const noteDescriptionInput = document.getElementById('noteDescription');
    const noteTypeSelect = document.getElementById('noteType');
    const saveNoteBtn = document.getElementById('saveNoteBtn');
    const deleteNoteBtn = document.getElementById('deleteNoteBtn');

    let calendarInstance; // To hold the FullCalendar instance
    let currentClassId = null;

    // Function to initialize or re-render the calendar
    function initializeCalendar(classId) {
        if (calendarInstance) {
            calendarInstance.destroy(); // Destroy existing calendar if any
        }

        calendarEl.style.display = 'block'; // Show the calendar div
        calendarInfoMessage.style.display = 'none'; // Hide info message

        calendarInstance = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay'
            },
            editable: true, // Allow dragging/resizing if needed
            selectable: true, // Allow selecting dates
            events: {
                url: '../teacher/fetch_calendar_notes.php', // PHP endpoint to fetch notes for the selected class
                method: 'GET',
                extraParams: function() {
                    return {
                        class_id: classId,
                        teacher_id: <?php echo $teacher_id; ?>
                    };
                },
                failure: function() {
                    alert('There was an error while fetching events!');
                }
            },
            dateClick: function(info) {
                // Handle click on a date to add a new note
                noteForm.reset();
                noteIdInput.value = ''; // Clear note ID for new note
                noteClassIdInput.value = classId;
                noteDateInput.value = info.dateStr;
                noteModal.show();
                document.getElementById('noteModalLabel').textContent = 'Add Note';
                deleteNoteBtn.style.display = 'none'; // Hide delete button for new notes
            },
            eventClick: function(info) {
                // Handle click on an existing event to edit/delete
                // Fetch full note details if needed, or rely on event object data
                const event = info.event;
                noteIdInput.value = event.id; // Assuming event.id is note_id
                noteClassIdInput.value = classId;
                noteDateInput.value = event.startStr;
                noteTitleInput.value = event.title;
                noteDescriptionInput.value = event.extendedProps.description || '';
                noteTypeSelect.value = event.extendedProps.type || 'other';

                noteModal.show();
                document.getElementById('noteModalLabel').textContent = 'Edit Note';
                deleteNoteBtn.style.display = 'block'; // Show delete button for existing notes
            },
            eventDrop: function(info) {
                // Handle event drag-and-drop to update date
                const event = info.event;
                updateNote(event.id, event.startStr, event.title, event.extendedProps.description, event.extendedProps.type);
            }
        });
        calendarInstance.render();
    }

    // Event listener for class selection dropdown
    classCalendarSelect.addEventListener('change', function() {
        currentClassId = this.value;
        if (currentClassId) {
            initializeCalendar(currentClassId);
        } else {
            if (calendarInstance) {
                calendarInstance.destroy();
            }
            calendarEl.style.display = 'none';
            calendarInfoMessage.style.display = 'block';
        }
    });

    // Save/Update Note button handler
    saveNoteBtn.addEventListener('click', function() {
        const noteId = noteIdInput.value;
        const classId = noteClassIdInput.value;
        const noteDate = noteDateInput.value;
        const noteTitle = noteTitleInput.value.trim();
        const noteDescription = noteDescriptionInput.value.trim();
        const noteType = noteTypeSelect.value;

        if (!noteTitle) {
            alert('Note title cannot be empty.');
            return;
        }

        const data = {
            note_id: noteId,
            class_id: classId,
            note_date: noteDate,
            note_title: noteTitle,
            note_description: noteDescription,
            note_type: noteType,
            action: noteId ? 'update' : 'create' // Determine action based on noteId
        };

        fetch('../teacher/handle_calendar_note.php', { // New PHP endpoint for CRUD operations
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(result => {
            if (result.status === 'success') {
                alert(result.message);
                noteModal.hide();
                if (calendarInstance) {
                    calendarInstance.refetchEvents(); // Refresh calendar to show new/updated note
                }
            } else {
                alert('Error: ' + result.message);
            }
        })
        .catch(error => {
            console.error('Error saving note:', error);
            alert('An error occurred while saving the note.');
        });
    });

    // Delete Note button handler
    deleteNoteBtn.addEventListener('click', function() {
        const noteId = noteIdInput.value;
        if (!noteId) {
            alert('No note selected for deletion.');
            return;
        }

        // Show the delete confirmation modal
        const deleteNoteModal = new bootstrap.Modal(document.getElementById('deleteNoteConfirmModal'));
        deleteNoteModal.show();

        // Handle the actual deletion when confirmed
        document.getElementById('confirmDeleteNoteBtn').onclick = function() {
            // Disable the delete button to prevent double-clicks
            deleteNoteBtn.disabled = true;
            saveNoteBtn.disabled = true;
            this.disabled = true; // Disable the confirm button too

            fetch('../teacher/handle_calendar_note.php', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ 
                    note_id: noteId, 
                    action: 'delete' 
                })
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(result => {
                if (result.status === 'success') {
                    // Close both modals
                    deleteNoteModal.hide();
                    noteModal.hide();
                    // Then show success message
                    alert(result.message);
                    // Finally refresh the calendar
                    if (calendarInstance) {
                        calendarInstance.refetchEvents();
                    }
                } else {
                    alert('Error: ' + result.message);
                }
            })
            .catch(error => {
                console.error('Error deleting note:', error);
                alert('An error occurred while deleting the note. Please try again.');
            })
            .finally(() => {
                // Re-enable the buttons
                deleteNoteBtn.disabled = false;
                saveNoteBtn.disabled = false;
                this.disabled = false; // Re-enable the confirm button
            });
        };
    });


    // --- Helper function for updating notes (e.g., from drag-and-drop) ---
    function updateNote(noteId, newDate, newTitle, newDescription, newType) {
        const data = {
            note_id: noteId,
            note_date: newDate,
            note_title: newTitle,
            note_description: newDescription,
            note_type: newType,
            action: 'update'
        };

        fetch('../teacher/handle_calendar_note.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(result => {
            if (result.status === 'success') {
                // Success, calendar automatically updates
            } else {
                alert('Error updating note: ' + result.message);
                calendarInstance.refetchEvents(); // Revert if update failed
            }
        })
        .catch(error => {
            console.error('Error updating note:', error);
            alert('An error occurred while updating the note.');
            calendarInstance.refetchEvents(); // Revert if update failed
        });
    }
}); // End of DOMContentLoaded for calendar script


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
        chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
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

        fetch('../teacher/handle_calendar_note.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                note_id: noteNumber,
                action: 'delete'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                appendMessage('Isla', data.message, false);
                // Refresh calendar if it exists
                if (typeof calendarInstance !== 'undefined' && calendarInstance) {
                    calendarInstance.refetchEvents();
                }
            } else {
                appendMessage('Isla', `Error: ${data.message}`, false);
            }
            chatbotInput.disabled = false;
            if (chatbotSend) chatbotSend.disabled = false;
            chatbotInput.focus();
            saveConversation();
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
            chatbotMessages.innerHTML = `
                <div class="message-container isla-message">
                    <p><strong>Isla:</strong> Hi there! How can I help you today?</p>
                </div>
            `;
            localStorage.removeItem(CHAT_STORAGE_KEY);
            chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
        }
    }

    document.getElementById('logoutButton').addEventListener('click', function() {
        localStorage.removeItem(CHAT_STORAGE_KEY);
    });
});


</script>
</body>
</html>