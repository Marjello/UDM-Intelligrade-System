<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/db.php'; // This will now connect to SQLite
require_once '../includes/auth.php'; // Assuming this provides isLoggedIn()

if (!isLoggedIn()) {
    header("Location: login.php");
    exit();
}

$teacher_id = $_SESSION['teacher_id'];
$full_name = $_SESSION['full_name'] ?? '';
$username = $_SESSION['username'] ?? 'Teacher';

// Database connection check
if (!isset($conn) || $conn === null) {
    die("Database connection not established. Please check your '../config/db.php' file.");
}

// Function to check if table exists - SQLite version
function tableExists($conn, $tableName) {
    $query = "SELECT name FROM sqlite_master WHERE type='table' AND name=?";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        return false;
    }
    $stmt->execute([$tableName]);
    return $stmt->fetchColumn() !== false;
}

// Function to safely delete from table if it exists - SQLite version
function safeDeleteFromTable($conn, $tableName, $whereClause, $params) {
    if (!tableExists($conn, $tableName)) {
        error_log("Warning: Table '$tableName' does not exist. Skipping deletion.");
        return true;
    }

    $query = "DELETE FROM $tableName WHERE $whereClause";
    try {
        $stmt = $conn->prepare($query);
        $success = $stmt->execute($params);
        if (!$success) {
            error_log("Failed to delete from table '$tableName': " . implode(", ", $stmt->errorInfo()));
        }
        return $success;
    } catch (PDOException $e) {
        error_log("Failed to prepare or execute statement for table '$tableName': " . $e->getMessage());
        return false;
    }
}

// Handle class deletion
if (isset($_POST['delete_class']) && isset($_POST['class_id'])) {
    $class_id = (int)$_POST['class_id'];

    // Start transaction for better data integrity
    $conn->beginTransaction();

    try {
        $allSuccessful = true;

        // Delete related records in order (child tables first)
        // Check and delete from grades table
        if (!safeDeleteFromTable($conn, 'grades', 'class_id = ?', [$class_id])) {
            $allSuccessful = false;
        }

        // Check and delete from enrollments table
        if (!safeDeleteFromTable($conn, 'enrollments', 'class_id = ?', [$class_id])) {
            $allSuccessful = false;
        }

        // Check and delete from grade_components table
        if (!safeDeleteFromTable($conn, 'grade_components', 'class_id = ?', [$class_id])) {
            $allSuccessful = false;
        }

        // Check and delete from class_calendar_notes table
        if (!safeDeleteFromTable($conn, 'class_calendar_notes', 'class_id = ?', [$class_id])) {
            $allSuccessful = false;
        }

        // Finally, delete the class
        if ($allSuccessful) {
            if (tableExists($conn, 'classes')) {
                $delete_class = "DELETE FROM classes WHERE class_id = ? AND teacher_id = ?";
                $stmt = $conn->prepare($delete_class);
                if ($stmt) {
                    if ($stmt->execute([$class_id, $teacher_id])) {
                        if ($stmt->rowCount() > 0) {
                            $conn->commit();
                            $_SESSION['success_message'] = "Class deleted successfully!";
                        } else {
                            $conn->rollBack();
                            $_SESSION['error_message'] = "Class not found or you don't have permission to delete it.";
                        }
                    } else {
                        $conn->rollBack();
                        $_SESSION['error_message'] = "Error deleting class: " . implode(", ", $stmt->errorInfo());
                    }
                } else {
                    $conn->rollBack();
                    $_SESSION['error_message'] = "Database error: Failed to prepare delete statement.";
                }
            } else {
                $conn->rollBack();
                $_SESSION['error_message'] = "Database error: Classes table does not exist.";
            }
        } else {
            $conn->rollBack();
            $_SESSION['error_message'] = "Error deleting related records. Class deletion cancelled.";
        }

    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Exception during class deletion: " . $e->getMessage());
        $_SESSION['error_message'] = "An unexpected error occurred while deleting the class.";
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Handle note deletion
if (isset($_POST['delete_note']) && isset($_POST['note_number'])) {
    header('Content-Type: application/json');
    $note_number_to_delete = (int)$_POST['note_number'];

    if (!tableExists($conn, 'notes')) {
        echo json_encode(['status' => 'error', 'message' => 'Notes table does not exist.']);
        exit();
    }

    // Fetch all notes for this teacher first to get their IDs
    $sql_notes = "SELECT note_id FROM notes WHERE teacher_id = ? ORDER BY reg_date DESC";
    $stmt_notes = $conn->prepare($sql_notes);
    
    if (!$stmt_notes) {
        echo json_encode(['status' => 'error', 'message' => 'Error preparing notes query: ' . implode(", ", $conn->errorInfo())]);
        exit();
    }
    
    $stmt_notes->execute([$teacher_id]);
    $note_ids = [];
    while ($row_note = $stmt_notes->fetch(PDO::FETCH_ASSOC)) {
        $note_ids[] = $row_note['note_id'];
    }

    // Check if the provided note number is valid
    if ($note_number_to_delete <= 0 || $note_number_to_delete > count($note_ids)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid note number.']);
        exit();
    }

    // Get the actual note_id to delete based on the 1-indexed number
    $note_id_to_delete = $note_ids[$note_number_to_delete - 1];

    $delete_note_sql = "DELETE FROM notes WHERE note_id = ? AND teacher_id = ?";
    $stmt = $conn->prepare($delete_note_sql);

    if ($stmt) {
        if ($stmt->execute([$note_id_to_delete, $teacher_id])) {
            if ($stmt->rowCount() > 0) {
                echo json_encode(['status' => 'success', 'message' => 'Note deleted successfully!']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Note not found or you do not have permission to delete it.']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Error deleting note: ' . implode(", ", $stmt->errorInfo())]);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database error: Failed to prepare delete note statement.']);
    }
    exit();
}

// Function to get the appropriate grades input URL based on grading system type
function getGradesInputUrl($class_id, $grading_system_type) {
    $base_path = "../teacher/";

    switch ($grading_system_type) {
        case 'numerical':
            return $base_path . "input_grades_numerical.php?class_id=" . $class_id;
        case 'a_na':
        case 'final_only':
        default:
            return $base_path . "input_grades_final_only.php?class_id=" . $class_id;
    }
}

// Get classes - also check if tables exist
$classes = [];
if (tableExists($conn, 'classes') && tableExists($conn, 'subjects') && tableExists($conn, 'sections')) {
    $sql = "SELECT
                c.class_id, s.subject_code, s.subject_name, sec.section_name,
                sec.academic_year, sec.semester, c.grading_system_type
            FROM classes c
            JOIN subjects s ON c.subject_id = s.subject_id
            JOIN sections sec ON c.section_id = sec.section_id
            WHERE c.teacher_id = ?";
    $stmt = $conn->prepare($sql);

    if ($stmt) {
        $stmt->execute([$teacher_id]);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        error_log("Failed to prepare statement for getting classes: " . implode(", ", $conn->errorInfo()));
        $_SESSION['error_message'] = "Database error: Unable to retrieve classes.";
    }
} else {
    $_SESSION['error_message'] = "Database tables are missing. Please ensure your database is properly set up.";
}

// PHP logic to fetch notes for dashboard
$userNotes = [];
if (tableExists($conn, 'notes')) {
    $sql_notes = "SELECT note_id, note_content FROM notes WHERE teacher_id = ? ORDER BY reg_date DESC";
    $stmt_notes = $conn->prepare($sql_notes);

    if ($stmt_notes) {
        $stmt_notes->execute([$teacher_id]);
        while ($row_note = $stmt_notes->fetch(PDO::FETCH_ASSOC)) {
            $userNotes[] = [
                'note_id' => $row_note['note_id'],
                'note_content' => htmlspecialchars($row_note["note_content"])
            ];
        }
    } else {
        error_log("Error preparing notes statement: " . implode(", ", $conn->errorInfo()));
    }
}

// Fetch total number of students per class
$studentsPerClass = [];
if (tableExists($conn, 'enrollments') && tableExists($conn, 'classes') && tableExists($conn, 'subjects') && tableExists($conn, 'sections')) {
    $sql_students_per_class = "SELECT
                                c.class_id,
                                s.subject_code,
                                s.subject_name,
                                sec.section_name,
                                COUNT(e.student_id) AS student_count
                            FROM classes c
                            JOIN subjects s ON c.subject_id = s.subject_id
                            JOIN sections sec ON c.section_id = sec.section_id
                            LEFT JOIN enrollments e ON c.class_id = e.class_id
                            WHERE c.teacher_id = ?
                            GROUP BY c.class_id, s.subject_code, s.subject_name, sec.section_name
                            ORDER BY s.subject_name, sec.section_name";
    $stmt_students = $conn->prepare($sql_students_per_class);

    if ($stmt_students) {
        $stmt_students->execute([$teacher_id]);
        $studentsPerClass = $stmt_students->fetchAll(PDO::FETCH_ASSOC);
    } else {
        error_log("Failed to prepare statement for getting student count per class: " . implode(", ", $conn->errorInfo()));
        $_SESSION['error_message'] = "Database error: Unable to retrieve student counts per class.";
    }
}

// PHP logic to fetch calendar notes per class
$calendarNotesByClass = [];
$required_calendar_tables = ['class_calendar_notes', 'classes', 'subjects', 'sections'];
$all_calendar_tables_exist = true;
foreach ($required_calendar_tables as $table) {
    if (!tableExists($conn, $table)) {
        $all_calendar_tables_exist = false;
        error_log("Missing table for calendar notes: " . $table);
        break;
    }
}

if ($all_calendar_tables_exist) {
    $sql_calendar_notes = "SELECT
                            ccn.calendar_note_id,
                            ccn.class_id,
                            s.subject_code,
                            s.subject_name,
                            sec.section_name,
                            sec.academic_year,
                            sec.semester,
                            ccn.calendar_note_date,
                            ccn.calendar_note_title,
                            ccn.calendar_note_description,
                            ccn.calendar_note_type
                           FROM class_calendar_notes ccn
                           JOIN classes c ON ccn.class_id = c.class_id
                           JOIN subjects s ON c.subject_id = s.subject_id
                           JOIN sections sec ON c.section_id = sec.section_id
                           WHERE c.teacher_id = ?
                           ORDER BY sec.academic_year DESC, sec.semester DESC, s.subject_name ASC, ccn.calendar_note_date ASC";

    $stmt_calendar = $conn->prepare($sql_calendar_notes);

    if ($stmt_calendar) {
        $stmt_calendar->execute([$teacher_id]);
        while ($row = $stmt_calendar->fetch(PDO::FETCH_ASSOC)) {
            $class_display_name = htmlspecialchars($row['subject_code']) . ' - ' . htmlspecialchars($row['subject_name']) . ' (' . htmlspecialchars($row['section_name']) . ' - ' . htmlspecialchars($row['academic_year']) . ' ' . htmlspecialchars($row['semester']) . ')';
            
            if (!isset($calendarNotesByClass[$class_display_name])) {
                $calendarNotesByClass[$class_display_name] = [];
            }
            $calendarNotesByClass[$class_display_name][] = $row;
        }
    } else {
        error_log("Failed to prepare statement for getting calendar notes: " . implode(", ", $conn->errorInfo()));
        $_SESSION['error_message'] = "Database error: Unable to retrieve calendar notes.";
    }
} else {
    $_SESSION['error_message'] = "One or more required tables for calendar notes are missing.";
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UDM IntelliGrade - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            background-color: #f5f3e1; /* Light beige background */
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .main-wrapper {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 280px;
            background-color: #006400; /* Dark green color for sidebar */
            color: #E7E7E7;
            padding: 0;
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            z-index: 1030;
            overflow-y: auto;
            transition: width 0.3s ease;
            display: flex;
            flex-direction: column;
        }

        .sidebar-header {
            padding: 1rem;
            border-bottom: 1px solid #008000; /* Lighter green separator */
            display: flex;
            align-items: center;
            justify-content: flex-start;
            min-height: 70px;
            background-color: #004d00; /* Slightly darker green for header */
        }

        .logo-image {
            max-height: 40px;
        }

        .logo-text {
            overflow: hidden;
        }

        .logo-text h5.uni-name {
            margin: 0;
            font-size: 0.9rem;
            font-weight: 600;
            color: #FFFFFF;
            line-height: 1.1;
            white-space: nowrap;
        }

        .logo-text p.tagline {
            margin: 0;
            font-size: 0.7rem;
            font-weight: 300;
            color: #E7E7E7;
            line-height: 1;
            white-space: nowrap;
        }

        .sidebar .nav-menu {
            padding: 1rem;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }

        .sidebar .nav-link {
            color: #E7E7E7;
            padding: 0.85rem 1.25rem;
            font-size: 0.95rem;
            border-radius: 0.3rem;
            margin-bottom: 0.25rem;
            transition: background-color 0.2s ease, color 0.2s ease;
            display: flex;
            align-items: center;
            white-space: nowrap;
        }

        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            color: #FFFFFF;
            background-color: #008000; /* Lighter green for hover/active */
        }

        .sidebar .nav-link .bi {
            margin-right: 0.85rem;
            font-size: 1.1rem;
            vertical-align: middle;
            width: 20px;
            text-align: center;
        }

        .sidebar .nav-link span {
            flex-grow: 1;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar .logout-item {
             margin-top: auto;
        }
        .sidebar .logout-item hr {
            border-color: #008000; /* Lighter green for separator */
            margin-top: 1rem;
            margin-bottom:1rem;
        }

        .content-area {
            margin-left: 280px;
            flex-grow: 1;
            padding: 2.5rem;
            width: calc(100% - 280px);
            transition: margin-left 0.3s ease, width 0.3s ease;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2.5rem;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid #d6d0b8; /* Matching beige border */
        }

        .page-header h2 {
            margin: 0;
            font-weight: 500;
            font-size: 1.75rem;
            color: #006400; /* Dark green for header text */
        }

        .card {
            border: 1px solid #d6d0b8; /* Matching beige border */
            box-shadow: 0 0.25rem 0.75rem rgba(0, 0, 0, 0.05);
            border-radius: 0.5rem;
            background-color: #fcfbf7; /* Even lighter beige for cards */
        }
        .card-header {
            background-color: #e9e5d0; /* Light beige header */
            border-bottom: 1px solid #d6d0b8;
            padding: 1rem 1.25rem;
            font-weight: 500;
            color: #006400; /* Dark green text */
        }

        .table th {
            background-color: #e9e5d0; /* Light beige header */
            font-weight: 500;
            color: #006400; /* Dark green text */
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .table td {
            vertical-align: middle;
            font-size: 0.95rem;
            background-color: #fcfbf7; /* Even lighter beige for table cells */
        }
        .table .btn-action-group .btn {
            margin-right: 0.3rem;
        }
        .table .btn-action-group .btn:last-child {
            margin-right: 0;
        }

        .btn-primary {
            background-color: #006400; /* Dark green buttons */
            border-color: #006400;
        }
        .btn-primary:hover {
            background-color: #004d00; /* Darker green on hover */
            border-color: #004d00;
        }

        .btn-outline-primary {
            color: #006400;
            border-color: #006400;
        }

        .btn-outline-primary:hover {
            background-color: #006400;
            border-color: #006400;
            color: white;
        }

        .btn-outline-secondary, .btn-outline-success, .btn-outline-info {
            color: #006400;
            border-color: #006400;
        }

        .btn-outline-secondary:hover, .btn-outline-success:hover, .btn-outline-info:hover {
            background-color: #006400;
            border-color: #006400;
            color: white;
        }

        .btn-outline-warning {
            color: #856404;
            border-color: #856404;
        }

        .btn-outline-warning:hover {
            background-color: #856404;
            border-color: #856404;
            color: white;
        }

        .btn-outline-danger {
            color: #dc3545;
            border-color: #dc3545;
        }

        .btn-outline-danger:hover {
            background-color: #dc3545;
            border-color: #dc3545;
            color: white;
        }

        .alert-info {
            background-color: #e7f3e7; /* Light green alert */
            border-color: #d0ffd0;
            color: #006400;
        }

        .footer {
            padding: 1.5rem 0;
            margin-top: 2rem;
            font-size: 0.875rem;
            color: #006400; /* Dark green footer text */
            border-top: 1px solid #d6d0b8; /* Matching beige border */
        }

        /* Responsive adjustments */
        @media (max-width: 992px) {
            .sidebar {
                width: 80px; /* Collapsed sidebar width */
            }
            .sidebar .logo-text {
                display: none;
            }
            .sidebar .sidebar-header {
                justify-content: center;
                padding: 1.25rem 0.5rem;
            }
            .sidebar .logo-image {
                margin-right: 0;
            }
             .sidebar .nav-link span { /* Hide text of nav links */
                display: none;
            }
            .sidebar .nav-link .bi { /* Center icon in nav link */
                 margin-right: 0;
                 display: block;
                 text-align: center;
                 font-size: 1.5rem;
            }
             .sidebar:hover { /* Expand sidebar on hover */
                width: 280px;
            }
            .sidebar:hover .logo-text {
                display: block; /* Show logo text on hover */
            }
             .sidebar:hover .sidebar-header {
                justify-content: flex-start;
                padding: 1rem;
            }
            .sidebar:hover .logo-image {
                margin-right: 0.5rem; /* Add margin back for image */
            }
            .sidebar:hover .nav-link span { /* Show nav link text on hover */
                display: inline;
            }
             .sidebar:hover .nav-link .bi { /* Adjust nav link icon on hover */
                margin-right: 0.85rem;
                display: inline-block;
                text-align: center;
            }

            .content-area {
                margin-left: 80px;
                width: calc(100% - 80px);
            }
            .sidebar:hover + .content-area {
                margin-left: 280px;
                 width: calc(100% - 280px);
            }
        }

        @media (max-width: 768px) {
            .sidebar { /* Sidebar stacks on top on mobile */
                width: 100%;
                height: auto;
                position: static;
                z-index: auto;
                flex-direction: column; /* Ensure it remains a column */
            }
            .sidebar .logo-text { /* Always show logo text on mobile */
                display: block;
            }
            .sidebar .sidebar-header { /* Align logo to start on mobile */
                justify-content: flex-start;
                padding: 1rem;
            }
            .sidebar .logo-image { /* Ensure logo image has margin on mobile */
                margin-right: 0.5rem;
            }
             .sidebar .nav-link span { /* Always show nav link text on mobile */
                display: inline;
            }
             .sidebar .nav-link .bi { /* Adjust nav link icon for mobile */
                margin-right: 0.85rem;
                font-size: 1.1rem;
                display: inline-block;
                text-align: center;
            }
            .sidebar .nav-menu { /* Reset flex-grow for mobile if needed */
                flex-grow: 0;
            }
            .sidebar .logout-item { /* Adjust logout for stacked layout */
                margin-top: 1rem; /* Add some space before logout if it's not auto pushing */
            }

            .content-area {
                margin-left: 0;
                width: 100%;
                padding: 1.5rem;
            }
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .page-header h2 {
                font-size: 1.5rem;
            }
            .page-header .btn {
                margin-top: 1rem;
            }
             .table-responsive {
                overflow-x: auto;
            }
            .btn-action-group {
                white-space: nowrap;
            }
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

    </style>
</head>
<body>

<div class="main-wrapper">
    <nav class="sidebar">
        <div class="sidebar-header">
            <img src="assets\img\udm_logo.png" alt="UDM Logo" class="logo-image me-2">
            <div class="logo-text">
                <h5 class="uni-name mb-0">UNIVERSIDAD DE MANILA</h5>
                <p class="tagline mb-0">Former City College of Manila</p>
            </div>
        </div>
        <ul class="nav flex-column nav-menu">
            <li class="nav-item">
                <a class="nav-link active" aria-current="page" href="#">
                    <i class="bi bi-grid-1x2-fill"></i> <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="../teacher/create_class.php">
                    <i class="bi bi-plus-square-dotted"></i> <span>Create New Class</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="../teacher/your_classes.php">
                    <i class="bi bi-person-workspace"></i> <span>Your Classes</span>
                </a>
            </li>
               <li class="nav-item">
                <a class="nav-link" href="manage_backup.php">
                    <i class="bi bi-cloud-arrow-down-fill"></i> <span>Manage Backup</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="gradingsystem.php">
                    <i class="bi bi-calculator"></i> <span>Grading System</span>
                </a>
            </li>
            <li class="nav-item logout-item">
                 <hr>
                 <a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#logoutModal">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </nav>

    <main class="content-area">
       <header class="page-header">
    <h2>Welcome, Teacher <?= htmlspecialchars($username) ?>!</h2>
</header>

        <?php
        // Display success/error messages
        if (isset($_SESSION['success_message'])) {
            echo '<div class="alert alert-success alert-dismissible fade show" role="alert">';
            echo htmlspecialchars($_SESSION['success_message']);
            echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
            echo '</div>';
            unset($_SESSION['success_message']);
        }

        if (isset($_SESSION['error_message'])) {
            echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">';
            echo htmlspecialchars($_SESSION['error_message']);
            echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
            echo '</div>';
            unset($_SESSION['error_message']);
        }
        ?>

        <div class="row">
            <div class="col-md-6">
                <section class="mb-4">
                    <h4 style="color: #006400;">Students per Class</h4>
                </section>

                <div class="card shadow-sm mb-4">
                    <div class="card-header">
                        <i class="bi bi-person-fill-gear me-2"></i> Student Count Overview
                    </div>
                    <div class="card-body">
                        <?php if (!empty($studentsPerClass)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead>
                                        <tr>
                                            <th><i class="bi bi-book me-1"></i> Subject</th>
                                            <th><i class="bi bi-people me-1"></i> Section</th>
                                            <th><i class="bi bi-person me-1"></i> Number of Students</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($studentsPerClass as $classStudentCount): ?>
                                            <tr>
                                          <td><?= htmlspecialchars((string)($classStudentCount['subject_code'] ?? '')) ?> - <?= htmlspecialchars((string)($classStudentCount['subject_name'] ?? '')) ?></td>


                                                <td><?= htmlspecialchars($classStudentCount['section_name']) ?></td>
                                                <td><?= htmlspecialchars($classStudentCount['student_count']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info" role="alert">
                                No student enrollment data available for your classes.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <section class="mb-4">
                    <h4 style="color: #006400;">Your Personal Notes</h4>
                </section>

                <div class="card shadow-sm mb-4">
                    <div class="card-header">
                        <i class="bi bi-journal-text me-2"></i> Recent Notes
                    </div>
                    <div class="card-body">
                        <?php if (!empty($userNotes)): ?>
                            <p>Here are your personal notes:</p>
                            <?php foreach ($userNotes as $index => $note): ?>
                                <p class="mb-1"><?= ($index + 1) . '. ' . $note['note_content'] ?></p>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="alert alert-info" role="alert">
                                You don't have any personal notes yet. Use the chatbot to create notes!
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <section class="mb-4">
                    <h4 style="color: #006400;">Calendar Summary</h4>
                </section>
                <div class="card shadow-sm mb-4">
                    <div class="card-header">
                        <i class="bi bi-calendar-event me-2"></i> Upcoming Events
                    </div>
                    <div class="card-body">
                        <?php if (!empty($calendarNotesByClass)): ?>
                            <?php foreach ($calendarNotesByClass as $class_name => $notes): ?>
                                <h6 class="mt-3 mb-2 text-primary"><?= $class_name ?></h6>
                                <ul class="list-group list-group-flush">
                                    <?php foreach ($notes as $note): ?>
                                        <li class="list-group-item d-flex justify-content-between align-items-start">
                                            <div class="ms-2 me-auto">
                                                <div class="fw-bold">Title: <?= htmlspecialchars($note['calendar_note_title']) ?></div>
                                                Description: <?= htmlspecialchars($note['calendar_note_description']) ?>
                                                <br><small class="text-muted">Date: <?= htmlspecialchars($note['calendar_note_date']) ?> (Type: <?= htmlspecialchars($note['calendar_note_type']) ?>)</small>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="alert alert-info" role="alert">
                                No upcoming events or important dates scheduled yet for your classes.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <footer class="footer text-center">
            &copy; <?= date('Y') ?> Universidad De Manila - IntelliGrade System. All rights reserved.
        </footer>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/logout-handler.js"></script>

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
        <a href="logout.php" class="btn btn-danger" id="logoutButton">Logout</a>
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
                <p class="text-danger"><strong>⚠️ Warning:</strong> This action cannot be undone!</p>
                <ul>
                    <li>All student enrollments</li>
                    <li>All grades and grade components</li>
                    <li>All class-related data</li>
                </ul>
                <p>Are you sure you want to delete the class: <strong id="deleteClassName"></strong>?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form id="deleteClassForm" method="POST" class="d-inline">
                    <input type="hidden" name="class_id" id="deleteClassId">
                    <button type="submit" name="delete_class" class="btn btn-danger">Yes, Delete Class</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="chatbot-container">
    <button type="button" class="btn btn-primary btn-chatbot" id="chatbotToggle" data-bs-toggle="popover" data-bs-placement="top" title="UDM Isla">
        <i class="bi bi-chat-dots-fill"></i>
    </button>

    <div id="chatbotPopoverContent" style="display: none;">
        <div class="chatbot-messages">
            </div>
        <div class="input-group mb-2"> <input type="text" id="chatbotInput" class="form-control" placeholder="Type your question...">
            <button class="btn btn-primary" type="button" id="chatbotSend">Send</button>
        </div>
        <button class="btn btn-success w-100" type="button" id="chatbotSaveDbButton" style="display: none;">
            <i class="bi bi-download"></i> Save Database Now
        </button>
    </div>
</div>

<script>
// Function to confirm class deletion
function confirmDelete(classId, className) {
    document.getElementById('deleteClassId').value = classId;
    document.getElementById('deleteClassName').textContent = className;

    const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
    deleteModal.show();
}

document.addEventListener('DOMContentLoaded', function() {
    const importForm = document.getElementById('importForm');
    const sqlFileInput = document.getElementById('sql_file');
    const fileDisplayInput = document.getElementById('file_display');
    const triggerImportModalButton = document.getElementById('triggerImportModal');
    const importConfirmModal = new bootstrap.Modal(document.getElementById('importConfirmModal'));
    const confirmImportButton = document.getElementById('confirmImportButton');
    const importFileNameDisplay = document.getElementById('importFileNameDisplay');
    const resultDiv = document.getElementById('result');

    // Make the hidden file input clickable when the "Import Database" button is clicked
    if (triggerImportModalButton) { // Check if element exists
        triggerImportModalButton.addEventListener('click', function() {
            sqlFileInput.click();
        });
    }

    // Update the file display input when a file is selected
    if (sqlFileInput) { // Check if element exists
        sqlFileInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                fileDisplayInput.value = this.files[0].name;
                importFileNameDisplay.textContent = 'Selected file: ' + this.files[0].name;
                importConfirmModal.show(); // Show the confirmation modal after file selection
            } else {
                fileDisplayInput.value = '';
                importFileNameDisplay.textContent = '';
            }
        });
    }

    // Handle the actual import when the "Proceed with Import" button in the modal is clicked
    if (confirmImportButton) { // Check if element exists
        confirmImportButton.addEventListener('click', function() {
            if (sqlFileInput.files.length === 0) {
                resultDiv.innerHTML = '<div class="alert alert-danger" role="alert">Please select an SQL file to import.</div>';
                importConfirmModal.hide(); // Hide the modal if no file selected
                return;
            }

            const form = new FormData(importForm);
            fetch('import_db.php', {
                method: 'POST',
                body: form
            })
            .then(res => res.json())
            .then(data => {
                importConfirmModal.hide(); // Hide the modal after fetch
                resultDiv.innerHTML = `<div class="alert ${data.status === 'success' ? 'alert-success' : 'alert-danger'}" role="alert">${data.message}</div>`;
                // Auto-dismiss the alert
                setTimeout(() => {
                    const currentAlert = resultDiv.querySelector('.alert');
                    if (currentAlert) {
                        new bootstrap.Alert(currentAlert).close();
                    }
                }, 5000);
            })
            .catch(error => {
                importConfirmModal.hide(); // Hide the modal on error
                resultDiv.innerHTML = '<div class="alert alert-danger" role="alert">Error uploading file.</div>';
                // Auto-dismiss the alert
                setTimeout(() => {
                    const currentAlert = resultDiv.querySelector('.alert');
                    if (currentAlert) {
                        new bootstrap.Alert(currentAlert).close();
                    }
                }, 5000);
                console.error('Error:', error);
            });
        });
    }
});
</script>

<div class="modal fade" id="importConfirmModal" tabindex="-1" aria-labelledby="importConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning-subtle">
                <h5 class="modal-title" id="importConfirmModalLabel"><i class="bi bi-exclamation-triangle-fill me-2"></i> Confirm Database Import</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-danger">⚠️ Warning: Importing a database will **OVERWRITE** your current data. Make sure the file you are uploading is up to date, and that all changes have been saved or backed up.</p>
                <p>Are you sure you want to proceed with importing the database?</p>
                <p id="importFileNameDisplay" class="fw-bold"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmImportButton">Proceed with Import</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const chatbotToggle = document.getElementById('chatbotToggle');
    const chatbotPopoverContentTemplate = document.getElementById('chatbotPopoverContent');

    let chatbotMessages = null;
    let chatbotInput = null;
    let chatbotSend = null;
    let chatbotSaveDbButton = null; // New variable for the chatbot save button
    let typingIndicatorElement = null; // Element for the typing indicator

    const CHAT_STORAGE_KEY = 'udm_isla_conversation'; // Key for local storage

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
            chatbotSaveDbButton = activePopover.querySelector('#chatbotSaveDbButton'); // Get the new button

            // Load conversation when popover is shown
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
            // Attach event listener to the new chatbot save button
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

    async function sendMessage() { // Changed to async to await typing effect completion
        if (!chatbotInput || !chatbotMessages) {
            console.error('Chatbot input or messages container not found at sendMessage. Popover not ready?');
            return;
        }

        const userMessage = chatbotInput.value.trim();
        if (userMessage === '') return;

        appendMessage('You', userMessage); // Append user message immediately
        chatbotInput.value = '';
        chatbotInput.disabled = true;
        if (chatbotSend) {
            chatbotSend.disabled = true;
        }

        // Hide the save button if it was previously shown
        if (chatbotSaveDbButton) {
            chatbotSaveDbButton.style.display = 'none';
        }

        showTypingIndicator(); // Show typing indicator

        // Check for "clear chat" command
        if (userMessage.toLowerCase().includes('clear chat') || userMessage.toLowerCase().includes('clear chat history') || userMessage.toLowerCase().includes('reset chat') || userMessage.toLowerCase().includes('start over')) {
            hideTypingIndicator();
            clearChat(); // This already saves the conversation
            await appendMessage('Isla', "Chat history cleared, let's start over!", false); // Await this to ensure it's fully appended
            chatbotInput.disabled = false;
            if (chatbotSend) {
                chatbotSend.disabled = false;
            }
            chatbotInput.focus();
            return;
        }

        // Check for "save database" in user message
        if (userMessage.toLowerCase().includes('save database')) {
            hideTypingIndicator(); // Hide typing indicator for immediate response
            if (chatbotSaveDbButton) {
                chatbotSaveDbButton.style.display = 'block'; // Make the new chatbot button visible
                await appendMessage('Isla', "Click the 'Save Database Now' button below to save your database.", false);
            } else {
                await appendMessage('Isla', "I can't offer a direct save button right now. Please try again later or look for the button on the dashboard.", false);
            }
            chatbotInput.disabled = false;
            if (chatbotSend) {
                chatbotSend.disabled = false;
            }
            chatbotInput.focus();
            chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
            // No need to save conversation here, appendMessage already does it if not typing
            return; // Stop further processing as we've handled the specific command
        }

        // Check for "delete note X" in user message
        const deleteNoteMatch = userMessage.toLowerCase().match(/^delete note (\d+)$/);
        if (deleteNoteMatch) {
            const noteNumber = parseInt(deleteNoteMatch[1]);
            hideTypingIndicator(); // Hide typing indicator for immediate response
            await deleteNoteFromChatbot(noteNumber); // Call new function to handle note deletion, await for completion
            return; // Stop further processing
        }

        // Send message to backend (for other queries)
        fetch('chatbot_response.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'query=' + encodeURIComponent(userMessage) + '&teacher_id=<?php echo $teacher_id; ?>'
        })
        .then(response => response.json())
        .then(data => {
            hideTypingIndicator(); // Hide typing indicator once response is received
            appendMessage('Isla', data.response, true); // Append Isla's message with typing effect
            chatbotInput.disabled = false;
            if (chatbotSend) {
                chatbotSend.disabled = false;
            }
            chatbotInput.focus();
            // saveConversation() is called by appendMessage if typing effect is complete
        })
        .catch(error => {
            console.error('Error fetching chatbot response:', error);
            hideTypingIndicator(); // Hide typing indicator on error
            appendMessage('Isla', "Sorry, I'm having trouble connecting right now. Please try again later.", false); // No typing effect for error
            chatbotInput.disabled = false;
            if (chatbotSend) {
                chatbotSend.disabled = false;
            }
            // saveConversation() is called by appendMessage if no typing effect
        });

        chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
    }

    // New function to handle saving database directly from chatbot
    function saveDatabaseFromChatbot() {
        if (!chatbotMessages || !chatbotInput) {
            console.error('Chatbot messages or input not found for saveDatabaseFromChatbot.');
            return;
        }

        appendMessage('Isla', "Saving your database...", false); // No typing effect for immediate action
        chatbotInput.disabled = true;
        if (chatbotSend) chatbotSend.disabled = true;
        if (chatbotSaveDbButton) chatbotSaveDbButton.disabled = true;

        fetch('export_db.php', {
            method: 'POST',
            // No body needed for a simple export_db.php that just triggers a download
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
                chatbotSaveDbButton.style.display = 'none'; // Hide the button after action
            }
            chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
            chatbotInput.focus();
            // No need to call saveConversation() here, appendMessage already does it if not typing
            location.reload(); // Reload the page to reflect changes
        });
    }

    // New function to handle deleting note directly from chatbot
    async function deleteNoteFromChatbot(noteNumber) {
        if (!chatbotMessages || !chatbotInput) {
            console.error('Chatbot messages or input not found for deleteNoteFromChatbot.');
            return;
        }

        await appendMessage('Isla', `Attempting to delete note number ${noteNumber}...`, false);
        chatbotInput.disabled = true;
        if (chatbotSend) chatbotSend.disabled = true;

        fetch('dashboard.php', { // Send to dashboard.php which now handles note deletion
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
            // No need to call saveConversation() here, appendMessage already does it if not typing
            location.reload(); // Reload the page to reflect changes in notes list
        })
        .catch(error => {
            console.error('Error deleting note:', error);
            appendMessage('Isla', "Sorry, I couldn't delete the note due to a network error. Please try again later.", false);
            chatbotInput.disabled = false;
            if (chatbotSend) chatbotSend.disabled = false;
            // No need to call saveConversation() here, appendMessage already does it if not typing
        });
        chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
    }

    // Modified appendMessage function to handle typing effect and message alignment
    function appendMessage(sender, message, withTypingEffect = false) {
        return new Promise(resolve => {
            if (!chatbotMessages) {
                console.error('Chatbot messages container not found in appendMessage.');
                resolve();
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
                saveConversation(); // Save user message immediately
                resolve();
            } else if (sender === 'Isla') {
                messageContainer.classList.add('isla-message');
                messageContent.innerHTML = `<strong>${sender}:</strong> `; // Start with sender name
                messageContainer.appendChild(messageContent);
                chatbotMessages.appendChild(messageContainer);

                if (withTypingEffect) {
                    let i = 0;
                    const typingSpeed = 7; // milliseconds per character
                    function typeWriter() {
                        if (i < message.length) {
                            messageContent.innerHTML += message.charAt(i);
                            i++;
                            chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
                            setTimeout(typeWriter, typingSpeed);
                        } else {
                            // After typing is complete, save the conversation
                            saveConversation();
                            resolve();
                        }
                    }
                    setTimeout(typeWriter, 300); // Small delay before starting typing
                } else {
                    messageContent.innerHTML += message; // No typing effect
                    saveConversation(); // Save conversation immediately if no typing effect
                    resolve();
                }
            }
            chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
        });
    }

    // Function to save conversation to local storage
    function saveConversation() {
        if (chatbotMessages) {
            localStorage.setItem(CHAT_STORAGE_KEY, chatbotMessages.innerHTML);
        }
    }

    // Function to load conversation from local storage
    function loadConversation() {
        if (chatbotMessages) {
            const savedConversation = localStorage.getItem(CHAT_STORAGE_KEY);
            if (savedConversation) {
                chatbotMessages.innerHTML = savedConversation;
            } else {
                // If no conversation found, add the initial Isla message with commands info
                appendMessage('Isla', "Hi there! How can I help you today? Type 'list all commands' to see all the available commands.", false);
            }
            chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
        }
    }

    // Function to clear chat history
    function clearChat() {
        if (chatbotMessages) {
            chatbotMessages.innerHTML = ''; // Clear content first
            localStorage.removeItem(CHAT_STORAGE_KEY);
            // Re-add the initial message after clearing
            appendMessage('Isla', "Hi there! How can I help you today?", false);
            chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
        }
    }

    // Clear chatbot conversation on logout
    document.getElementById('logoutButton').addEventListener('click', function() {
        localStorage.removeItem(CHAT_STORAGE_KEY);
    });
});
</script>

</body>
</html>