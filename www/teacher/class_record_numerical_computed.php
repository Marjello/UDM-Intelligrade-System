<?php
require_once '../config/db.php';
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    header("Location: ../public/login.php");
    exit();
}

$teacher_id = $_SESSION['teacher_id'] ?? 0;
$full_name = $_SESSION['full_name'] ?? 'Teacher';
$class_id = (int)($_GET['class_id'] ?? 0);

if ($class_id === 0) {
    exit("Error: No class ID provided. Please select a class.");
}

// Fetch teacher's full name from database
$teacher_stmt = $conn->prepare("SELECT full_name FROM teachers WHERE teacher_id = ?");
$teacher_stmt->bindParam(1, $teacher_id, PDO::PARAM_INT);
$teacher_stmt->execute();
$teacher = $teacher_stmt->fetch(PDO::FETCH_ASSOC);
$teacher_stmt->closeCursor();
$full_name = $teacher['full_name'] ?? $full_name;

// Fetch class info and check permission
$stmt = $conn->prepare("SELECT c.*, s.subject_name, sec.section_name
                        FROM classes c
                        JOIN subjects s ON c.subject_id = s.subject_id
                        JOIN sections sec ON c.section_id = sec.section_id
                        WHERE c.class_id = ? AND c.teacher_id = ?");
$stmt->bindParam(1, $class_id, PDO::PARAM_INT);
$stmt->bindParam(2, $teacher_id, PDO::PARAM_INT);
$stmt->execute();
$class = $stmt->fetch(PDO::FETCH_ASSOC);
$stmt->closeCursor();

if (!$class) {
    $check_stmt = $conn->prepare("SELECT class_id FROM classes WHERE class_id = ?");
    $check_stmt->bindParam(1, $class_id, PDO::PARAM_INT);
    $check_stmt->execute();
    $exists = $check_stmt->rowCount() > 0;
    $check_stmt->closeCursor();
    exit($exists ? "Access Denied: You don't have permission to access this class." : "Error: Class not found.");
}

$grading_type = $class['grading_system_type'] ?? 'numerical'; // Should be 'numerical' for this page

// Filter handling
$filter = $_GET['filter'] ?? '';
$filter_sql = $filter ? "AND (s.student_number LIKE ? OR s.last_name LIKE ? OR s.first_name LIKE ?)" : '';
$filter_params = $filter ? ["%$filter%", "%$filter%", "%$filter%"] : [];

// Fetch students
$students_sql = "
    SELECT e.enrollment_id, s.student_id, s.student_number, s.first_name, s.last_name
    FROM enrollments e
    JOIN students s ON s.student_id = e.student_id
    WHERE e.class_id = ? {$filter_sql}
    ORDER BY s.last_name, s.first_name
";

$students_stmt = $conn->prepare($students_sql);
if ($filter) {
    $students_stmt->bindParam(1, $class_id, PDO::PARAM_INT);
    $students_stmt->bindParam(2, $filter_params[0], PDO::PARAM_STR);
    $students_stmt->bindParam(3, $filter_params[1], PDO::PARAM_STR);
    $students_stmt->bindParam(4, $filter_params[2], PDO::PARAM_STR);
} else {
    $students_stmt->bindParam(1, $class_id, PDO::PARAM_INT);
}
$students_stmt->execute();
$students_result = $students_stmt->fetchAll(PDO::FETCH_ASSOC);
$total_students = count($students_result);
$students_array = $students_result;
$students_stmt->closeCursor();


// Fetch all grade components for the class
$components_sql = "SELECT component_id, component_name, max_score, period, is_attendance_based 
                  FROM grade_components 
                  WHERE class_id = ? 
                  ORDER BY CASE period 
                      WHEN 'Preliminary' THEN 1 
                      WHEN 'Mid-Term' THEN 2 
                      WHEN 'Pre-Final' THEN 3 
                      ELSE 4 
                  END, component_name";
$components_stmt = $conn->prepare($components_sql);
$components_stmt->bindParam(1, $class_id, PDO::PARAM_INT);
$components_stmt->execute();
$all_components_for_class = $components_stmt->fetchAll(PDO::FETCH_ASSOC);
$components_stmt->closeCursor();

// Fetch all student grades for the class, including attendance_status
$student_scores_map = [];
if ($total_students > 0 && !empty($all_components_for_class)) {
    $enrollment_ids = array_map(function($student) {
        return $student['enrollment_id'];
    }, $students_array);

    if (!empty($enrollment_ids)) {
        $placeholders = implode(',', array_fill(0, count($enrollment_ids), '?'));
        
        $grades_sql = "SELECT sg.enrollment_id, sg.component_id, sg.score, sg.attendance_status
                       FROM student_grades sg
                       WHERE sg.enrollment_id IN ($placeholders)";
        
        $grades_stmt = $conn->prepare($grades_sql);
        foreach ($enrollment_ids as $index => $id) {
            $grades_stmt->bindValue($index + 1, $id, PDO::PARAM_INT);
        }
        $grades_stmt->execute();
        while ($grade = $grades_stmt->fetch(PDO::FETCH_ASSOC)) {
            // Prioritize 'score' if it's not null, otherwise use 'attendance_status'
            $student_scores_map[$grade['enrollment_id']][$grade['component_id']] = $grade['score'] ?? $grade['attendance_status'];
        }
        $grades_stmt->closeCursor();
    }
}


// --- UDM Grade Computation Functions ---

/**
 * Calculates the raw percentage grade for a specific period (Option A for attendance).
 * Non-numeric attendance scores (A/NA) are skipped and do not contribute to class standing totals.
 * Assumes "Examination" components have "Exam" or "Examination" in their name.
 */
function calculatePeriodGrade($enrollment_id, $period_name, $student_scores_map, $components_for_class) {
    $period_class_standing_total_score = 0;
    $period_class_standing_total_max_score = 0;
    $period_exam_score = 0;
    $period_exam_max_score = 0;

    $has_class_standing_components = false;
    $has_exam_component = false;

    $period_components = array_filter($components_for_class, function($c) use ($period_name) {
        return isset($c['period']) && $c['period'] === $period_name;
    });

    foreach ($period_components as $comp) {
        $component_id = $comp['component_id'];
        $raw_score_from_db = $student_scores_map[$enrollment_id][$component_id] ?? null; 
        $component_max_score = isset($comp['max_score']) ? (float)$comp['max_score'] : 0; 
        
        $current_achieved_score = 0;

        if ($comp['is_attendance_based']) { 
            if (!is_numeric($raw_score_from_db)) { 
                // Option A: Skip non-numeric attendance scores (A/NA) entirely.
                // They contribute neither to achieved score nor to max possible score for class standing.
                continue; 
            }
            // If attendance score IS numeric, process it.
            $current_achieved_score = (float)$raw_score_from_db;
        } elseif (is_numeric($raw_score_from_db)) {
            // For non-attendance based components with numeric scores
            $current_achieved_score = (float)$raw_score_from_db;
        } else {
            // For non-attendance based components with non-numeric scores (e.g., null if no grade entered, or empty string)
            // Treat as 0 for calculation. The component's max_score still counts towards total max_score.
            $current_achieved_score = 0; 
        }

        // Skip component entirely if its max_score is invalid (0 or less).
        // This ensures it doesn't contribute to any totals if it's not a valid scorable item.
        if ($component_max_score <= 0) { 
            continue; 
        }
        
        // Cap score at component's max_score and ensure it's not negative
        $current_achieved_score = min($current_achieved_score, $component_max_score);
        $current_achieved_score = max(0, $current_achieved_score);

        // Heuristic for identifying examination components
        $is_exam_component = (stripos($comp['component_name'], 'Exam') !== false || stripos($comp['component_name'], 'Examination') !== false); 

        if ($is_exam_component) {
            $period_exam_score += $current_achieved_score;
            $period_exam_max_score += $component_max_score;
            $has_exam_component = true;
        } else { // Class Standing components
            // (Non-numeric attendance was already skipped by 'continue')
            $period_class_standing_total_score += $current_achieved_score;
            $period_class_standing_total_max_score += $component_max_score;
            $has_class_standing_components = true;
        }
    }

    $class_standing_percentage = 0;
    if ($has_class_standing_components && $period_class_standing_total_max_score > 0) {
        $class_standing_percentage = ($period_class_standing_total_score / $period_class_standing_total_max_score) * 100; 
    }

    $exam_percentage = 0;
    if ($has_exam_component && $period_exam_max_score > 0) {
        $exam_percentage = ($period_exam_score / $period_exam_max_score) * 100; 
    }
    
    // UDM formula: 60% Class Standing, 40% Exam
    $calculated_period_grade = ($class_standing_percentage * 0.60) + ($exam_percentage * 0.40); 
    
    return round($calculated_period_grade, 2); 
}


/**
 * Calculates the final computed grade based on raw period grades.
 */
function calculateFinalComputedGrade($prelim_grade_raw, $midterm_grade_raw, $prefinal_grade_raw) {
    // UDM Formula: Prelim (30%), Mid-Term (30%), Pre-Final (40%)
    $final_grade = ($prelim_grade_raw * 0.30) + ($midterm_grade_raw * 0.30) + ($prefinal_grade_raw * 0.40); 
    return round($final_grade, 2); 
}

/**
 * Transmutes a percentage grade to its equivalent point and description.
 */
function getGradeEquivalent($grade) {
    if ($grade === null || !is_numeric($grade)) return ["NGS", "No Grade Yet"];
    if ($grade >= 99) return ["4.00", "Excellent"]; 
    if ($grade >= 97) return ["3.75", "Excellent"]; 
    if ($grade >= 95) return ["3.50", "Outstanding"]; 
    if ($grade >= 92) return ["3.25", "Outstanding"]; 
    if ($grade >= 90) return ["3.00", "Very Satisfactory"]; 
    if ($grade >= 88) return ["2.75", "Very Satisfactory"]; 
    if ($grade >= 86) return ["2.50", "Very Satisfactory"]; 
    if ($grade >= 84) return ["2.25", "Satisfactory"]; 
    if ($grade >= 82) return ["2.00", "Satisfactory"]; 
    if ($grade >= 80) return ["1.75", "Satisfactory"]; 
    if ($grade >= 78) return ["1.50", "Fair"]; 
    if ($grade >= 76) return ["1.25", "Fair"]; 
    if ($grade >= 75) return ["1.00", "Passed"]; 
    if ($grade < 75 && $grade >=0) return ["0.00", "Failed"]; 
    if ($grade < 0) return ["0.00", "Failed"]; 
    return ["NGS", "No Grade Yet"]; 
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UDM IntelliGrade - Computed Class Record - <?= htmlspecialchars($class['subject_name'] ?? 'Class') ?> - Universidad De Manila</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        /* CSS Styles (Copied from provided code, consider moving to a separate CSS file) */
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
        .sidebar .logout-item hr { border-color: #008000; margin: 1rem 0; }
        .content-area { margin-left: 280px; flex-grow: 1; padding: 2.5rem; width: calc(100% - 280px); transition: margin-left 0.3s ease, width 0.3s ease; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2.5rem; padding-bottom: 1.25rem; border-bottom: 1px solid #d6d0b8; }
        .page-header h2 { margin: 0; font-weight: 500; font-size: 1.75rem; color: #006400; }
        .page-header .page-actions { display: flex; align-items: center; gap: 0.5rem; } /* Container for buttons + search */
        .card { border: 1px solid #d6d0b8; box-shadow: 0 0.25rem 0.75rem rgba(0, 0, 0, 0.05); border-radius: 0.5rem; background-color: #fcfbf7; }
        .card-header { background-color: #e9e5d0; border-bottom: 1px solid #d6d0b8; padding: 1rem 1.25rem; font-weight: 500; color: #006400; }
        .btn-primary { background-color: #006400; border-color: #006400; }
        .btn-primary:hover { background-color: #004d00; border-color: #004d00; }
        .btn-outline-primary { color: #006400; border-color: #006400; }
        .btn-outline-primary:hover { background-color: #006400; border-color: #006400; color: white; }
        .btn-outline-info { color: #0d6efd; border-color: #0d6efd;}
        .btn-outline-info:hover { background-color: #0d6efd; border-color: #0d6efd; color: white;}
        .alert-danger { background-color: #f8d7da; border-color: #f5c6cb; color: #721c24; }
        .alert-success { background-color: #d4edda; border-color: #c3e6cb; color: #155724; }
        .footer { padding: 1.5rem 0; margin-top: 2rem; font-size: 0.875rem; color: #006400; border-top: 1px solid #d6d0b8; }
        .table { background-color: #ffffff; border-radius: 0.375rem; overflow: hidden; }
        .table thead { background-color: #e9e5d0; color: #006400; }
        .table th { font-weight: 500; border-bottom-width: 1px; }
        .table td, .table th { padding: 0.75rem 1rem; vertical-align: middle; }
        .table-responsive { overflow-x: auto; max-height: calc(100vh - 320px); /* Adjusted for sticky bar */ }
        .table-sticky thead th { position: sticky; top: 0; z-index: 10; background-color: #e9e5d0; }
        .table-sticky tbody tr:first-child td { border-top: none; }
        .student-name { white-space: nowrap; font-weight: 500; }
        .student-id { font-size: 0.85rem; color: #666; }

        @media (max-width: 992px) {
            .sidebar { width: 80px; } .sidebar .logo-text { display: none; }
            .sidebar .sidebar-header { justify-content: center; padding: 1.25rem 0.5rem; }
            .sidebar .logo-image { margin-right: 0; }
            .sidebar .nav-link span { display: none; }
            .sidebar .nav-link .bi { margin-right: 0; display: block; text-align: center; font-size: 1.5rem; }
            .sidebar:hover { width: 280px; } .sidebar:hover .logo-text { display: block; }
            .sidebar:hover .sidebar-header { justify-content: flex-start; padding: 1rem; }
            .sidebar:hover .logo-image { margin-right: 0.5rem; }
            .sidebar:hover .nav-link span { display: inline; }
            .sidebar:hover .nav-link .bi { margin-right: 0.85rem; display: inline-block; text-align: center; font-size: 1.1rem; }
            .content-area { margin-left: 80px; width: calc(100% - 80px); }
            .sidebar:hover + .content-area { margin-left: 280px; width: calc(100% - 280px); }
            .page-header { flex-direction: column; align-items: flex-start; gap: 1rem; } /* Stack header items on smaller screens */
            .page-header .page-actions { width: 100%; flex-direction: column; gap: 0.5rem; } /* Stack actions */
            .page-header .page-actions form { width: 100%; } /* Make search form full width */
            .page-header .page-actions .btn { width: 100%; } /* Make buttons full width */
        }
        @media (max-width: 768px) {
            .main-wrapper { flex-direction: column; } /* Ensure content flows below sidebar */
            .sidebar { width: 100%; height: auto; position: relative; z-index: 1031; flex-direction: column;}
            .sidebar .logo-text { display: block; }
            .sidebar .sidebar-header { justify-content: flex-start; padding: 1rem; }
            .sidebar .logo-image { margin-right: 0.5rem; }
            .sidebar .nav-link span { display: inline; }
            .sidebar .nav-link .bi { margin-right: 0.85rem; font-size: 1.1rem; display: inline-block; text-align: center; }
            .sidebar .nav-menu { flex-grow: 0; } .sidebar .logout-item { margin-top: 1rem; }
            .content-area { margin-left: 0; width: 100%; padding: 1.5rem; }
            .page-header h2 { font-size: 1.5rem; margin-bottom: 0; } /* Adjust h2 margin */
        }
        
        /* Chatbot specific styles */
        .chatbot-container { position: fixed; bottom: 20px; right: 20px; z-index: 1050; }
        .btn-chatbot { width: 60px; height: 60px; border-radius: 50%; font-size: 1.8rem; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 8px rgba(0,0,0,0.2); }
        .popover { max-width: 350px; }
        .popover-header { background-color: #006400; color: white; font-weight: bold; }
        .popover-body { padding: 15px; max-height: 400px; overflow-y: auto; }
        .chatbot-messages { height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; border-radius: 5px; margin-bottom: 10px; background-color: #f9f9f9; display: flex; flex-direction: column; }
        .message-container { display: flex; margin-bottom: 8px; max-width: 90%; }
        .user-message { align-self: flex-end; background-color: #e0f7fa; border-radius: 15px 15px 0 15px; padding: 8px 12px; margin-left: auto; }
        .isla-message { align-self: flex-start; background-color: #e7f3e7; border-radius: 15px 15px 15px 0; padding: 8px 12px; margin-right: auto; }
        .message-container strong { font-weight: bold; margin-bottom: 2px; display: block; }
        .user-message strong { color: #0056b3; }
        .isla-message strong { color: #006400; }
        .message-container p { margin: 0; line-height: 1.4; word-wrap: break-word; white-space: pre-wrap; }
        .typing-indicator { display: flex; align-items: center; padding: 8px 12px; background-color: #f0f0f0; border-radius: 15px 15px 15px 0; max-width: fit-content; align-self: flex-start; animation: fadeIn 0.3s forwards; }
        .typing-indicator span { width: 8px; height: 8px; background-color: #888; border-radius: 50%; margin: 0 2px; animation: bounce 1.4s infinite ease-in-out both; }
        .typing-indicator span:nth-child(1) { animation-delay: -0.32s; }
        .typing-indicator span:nth-child(2) { animation-delay: -0.16s; }
        .typing-indicator span:nth-child(3) { animation-delay: 0s; }
        @keyframes bounce { 0%, 80%, 100% { transform: scale(0); } 40% { transform: scale(1); } }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        /* Print-specific styles */
        @media print {
            body {
                background-color: #fff !important;
                font-family: Arial, sans-serif;
                font-size: 10pt;
                margin: 15px; /* Add some margin for printing */
            }
            .main-wrapper > .sidebar,
            .main-wrapper > .content-area > .page-header .page-actions, /* Hide all action buttons & search in header */
            .main-wrapper > .content-area > .footer,
            .modal,
            .alert, /* Hide any alerts */
            .chatbot-container,
            #printComputedGradesButton, /* Hide print button itself when printing */
            .page-header form /* Explicitly hide search form if not covered by .page-actions */
            {
                display: none !important;
            }
            .content-area {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 0 !important;
            }
            .page-header {
                border-bottom: 1px solid #000 !important; /* Make header border visible for print */
                margin-bottom: 1rem !important; /* Adjust spacing */
                justify-content: flex-start !important; /* Align title to left */
            }
            .page-header h2 {
                font-size: 1.5rem !important; /* Adjust title size for print */
            }
            .card {
                border: none !important;
                box-shadow: none !important;
                margin-bottom: 0 !important; /* Remove card margin if any */
            }
            .card-header { /* Style for card header (like Students count) if you want it visible */
                background-color: #fff !important;
                border-bottom: 1px solid #ccc !important;
                color: #000 !important;
                text-align: left;
                padding: 0.5rem 0 !important;
            }
            .table-responsive {
                overflow-x: visible !important;
                max-height: none !important;
            }
            .table, .table th, .table td {
                border: 1px solid #000 !important;
                color: #000 !important;
                font-size: 7pt !important; /* Smaller font for potentially wide tables */
            }
            .table thead {
                background-color: #eee !important;
            }
            .table thead th {
                font-weight: bold !important;
                background-color: #e9e9e9 !important;
                text-align: center !important;
                font-size: 7pt !important;
            }
            .table tbody td {
                font-size: 7pt !important;
            }
            .student-name { font-weight: normal !important; font-size: 8pt !important; }
            .student-id { font-size: 6.5pt !important; color: #333 !important; }
            .text-start { text-align: left !important; } /* Ensure student names are left aligned */
            .bg-success-subtle { 
                background-color: #e6ffed !important; /* Light green for print, or transparent */
                color: #000 !important;
            }
            .fw-bold { font-weight: bold !important; }
            .card-body ul.small { display: none; } /* Hide the notes below the table on print */

            /* Header for print output */
            .print-header-container { display: block !important; text-align: center; margin-bottom: 15px; }
            .print-header-container h2 { margin: 0 0 5px 0; font-size: 14pt;}
            .print-header-container p { margin: 0; font-size: 11pt;}
            .print-info-table { width: 100%; margin-bottom: 10px; border-collapse: collapse; font-size: 9pt; }
            .print-info-table td { padding: 3px; border: none; text-align: left; }
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
                    <a class="nav-link active" aria-current="page" href="your_classes.php"> <i class="bi bi-person-workspace"></i> <span>Your Classes</span>
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
                <li class="nav-item logout-item">
                    <hr>
                    <a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#logoutModal" id="logoutButton">
                        <i class="bi bi-box-arrow-right"></i> <span>Logout</span>
                    </a>
                </li>
            </ul>
        </nav>
        <main class="content-area">
            <header class="page-header">
                <div>
                    <h2>Computed Class Record</h2>
                    <p class="text-muted mb-0">
                        <i class="bi bi-book me-1"></i> <?= htmlspecialchars($class['subject_name']) ?> -
                        <i class="bi bi-people me-1"></i> <?= htmlspecialchars($class['section_name']) ?>
                        <span class="badge bg-primary rounded-pill me-2">Numerical Based</span>
                    </p>
                </div>
                <div class="page-actions flex-wrap"> 
                    <a href="input_grades_numerical.php?class_id=<?= $class_id ?>" class="btn btn-primary ms-lg-auto"> <i class="bi bi-pencil-square me-1"></i> Input Grades
                    </a>
                    <a href="manage_components.php?class_id=<?= $class_id ?>" class="btn btn-outline-primary">
                        <i class="bi bi-list-check me-1"></i> Manage Components
                    </a>
                    <a href="../teacher/your_classes.php" class="btn btn-outline-secondary"><i class="bi bi-grid-1x2"></i> Your Classes</a>
                    
                </div>
            </header>

            <div class="card shadow-sm mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center">
        <h5 class="mb-0 me-3"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Students (<?= $total_students ?>)</h5>
        <form method="get" class="d-flex">
            <input type="hidden" name="class_id" value="<?= $class_id ?>">
            <input type="text" name="filter" class="form-control me-2" placeholder="Search student..." value="<?= htmlspecialchars($filter) ?>">
            <button type="submit" class="btn btn-outline-primary"><i class="bi bi-search"></i></button>
        </form>
    </div>
    <div class="d-flex align-items-center">
        
        <button type="button" class="btn btn-outline-info" id="printComputedGradesButton"><i class="bi bi-printer"></i> Print Class Record</button>
    </div>
</div>
                <div class="card-body p-0"> <?php if ($total_students === 0): ?>
                        <div class="alert alert-info m-3" role="alert"> No students enrolled in this class yet. <?php if ($filter) echo "Or no students match your search criteria."; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-bordered align-middle text-center table-sticky mb-0" id="computedGradesTable"> <thead class="table-light">
                                    <tr>
                                        <th rowspan="2" class="text-start" style="width: 220px; min-width:180px;">Student Information</th>
                                        <th colspan="3" class="border-start">Preliminary Period</th>
                                        <th colspan="3" class="border-start">Mid-Term Period</th>
                                        <th colspan="3" class="border-start">Pre-Final Period</th>
                                        <th colspan="3" class="border-start">Final Computed Grade</th>
                                    </tr>
                                    <tr>
                                        <th style="width: 50px;">%</th>
                                        <th style="width: 50px;">Eq.</th>
                                        <th style="min-width: 100px;">Desc.</th>
                                        <th class="border-start" style="width: 50px;">%</th>
                                        <th style="50px;">Eq.</th>
                                        <th style="min-width: 100px;">Desc.</th>
                                        <th class="border-start" style="width: 50px;">%</th>
                                        <th style="width: 50px;">Eq.</th>
                                        <th style="min-width: 100px;">Desc.</th>
                                        <th class="border-start" style="width: 50px;">%</th>
                                        <th style="width: 50px;">Eq.</th>
                                        <th style="min-width: 100px;">Desc.</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($students_array as $student):
                                        $enrollment_id = $student['enrollment_id'];

                                        // Calculate grades for each period
                                        $prelim_grade_raw = calculatePeriodGrade($enrollment_id, 'Preliminary', $student_scores_map, $all_components_for_class);
                                        $midterm_grade_raw = calculatePeriodGrade($enrollment_id, 'Mid-Term', $student_scores_map, $all_components_for_class);
                                        $prefinal_grade_raw = calculatePeriodGrade($enrollment_id, 'Pre-Final', $student_scores_map, $all_components_for_class);

                                        // Calculate final computed grade
                                        $final_computed_grade = calculateFinalComputedGrade($prelim_grade_raw, $midterm_grade_raw, $prefinal_grade_raw);

                                        // Get grade equivalents
                                        list($prelim_eq, $prelim_desc) = getGradeEquivalent($prelim_grade_raw);
                                        list($midterm_eq, $midterm_desc) = getGradeEquivalent($midterm_grade_raw);
                                        list($prefinal_eq, $prefinal_desc) = getGradeEquivalent($prefinal_grade_raw);
                                        list($final_eq, $final_desc) = getGradeEquivalent($final_computed_grade);
                                        ?>
                                        <tr>
                                            <td class="text-start">
                                                <span class="student-name"><?= htmlspecialchars($student['last_name'] . ', ' . $student['first_name']) ?></span><br>
                                                <span class="student-id text-muted">#<?= htmlspecialchars($student['student_number']) ?></span>
                                            </td>
                                            <td class="border-start"><?= is_numeric($prelim_grade_raw) ? sprintf("%.2f", $prelim_grade_raw) . '%' : $prelim_grade_raw ?></td>
                                            <td><?= $prelim_eq ?></td>
                                            <td><?= $prelim_desc ?></td>
                                            <td class="border-start"><?= is_numeric($midterm_grade_raw) ? sprintf("%.2f", $midterm_grade_raw) . '%' : $midterm_grade_raw ?></td>
                                            <td><?= $midterm_eq ?></td>
                                            <td><?= $midterm_desc ?></td>
                                            <td class="border-start"><?= is_numeric($prefinal_grade_raw) ? sprintf("%.2f", $prefinal_grade_raw) . '%' : $prefinal_grade_raw ?></td>
                                            <td><?= $prefinal_eq ?></td>
                                            <td><?= $prefinal_desc ?></td>
                                            <td class="border-start bg-success-subtle fw-bold"><?= is_numeric($final_computed_grade) ? sprintf("%.2f", $final_computed_grade) . '%' : $final_computed_grade ?></td>
                                            <td class="bg-success-subtle fw-bold"><?= $final_eq ?></td>
                                            <td class="bg-success-subtle fw-bold"><?= $final_desc ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="p-3"> <ul class="text-muted small mt-3 mb-0">
                                <li><span class="fw-bold">NGS:</span> Not Graded Yet.</li>
                                <li><span class="fw-bold">Grade Calculation:</span> Each period grade is calculated as 60% Class Standing (sum of all non-exam components) and 40% Exam. Final Grade is 30% Preliminary, 30% Mid-Term, and 40% Pre-Final.</li>
                                <li><span class="fw-bold">Attendance:</span> Non-numeric attendance grades (e.g., A/NA) do not contribute to class standing total. Numeric attendance scores (if any) are included. Blank or non-numeric numerical components are treated as 0. Components with a max score of 0 are not included in calculations.</li>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <footer class="footer text-center">
                &copy; <?= date('Y') ?> Universidad De Manila - IntelliGrade System. All rights reserved.
            </footer>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../public/js/logout-handler.js"></script>

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

    <script>
document.addEventListener('DOMContentLoaded', function() {
    const chatbotToggle = document.getElementById('chatbotToggle');
    // Renamed for clarity and to match the template's actual ID
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
            // Ensure the correct template is cloned
            const contentClone = chatbotPopoverContentTemplate.cloneNode(true); 
            contentClone.style.display = 'block';
            return contentClone.innerHTML;
        },
        sanitize: false
    });

    chatbotToggle.addEventListener('shown.bs.popover', function () {
        const activePopover = document.querySelector('.popover.show');
       if (activePopover) {
        // Move popover slightly to the left (e.g., 70px)
        const currentLeft = parseFloat(window.getComputedStyle(activePopover).left) || 0;
        activePopover.style.left = `${currentLeft - 70}px`;
            chatbotMessages = activePopover.querySelector('.chatbot-messages');
            chatbotInput = activePopover.querySelector('#chatbotInput');
            chatbotSend = activePopover.querySelector('#chatbotSendBtn'); // Corrected ID
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

    async function sendMessage() {
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

        if (userMessage.toLowerCase().includes('clear chat') || userMessage.toLowerCase().includes('clear chat history') || userMessage.toLowerCase().includes('reset chat') || userMessage.toLowerCase().includes('start over')) {
            hideTypingIndicator();
            clearChat(); 
            await appendMessage('Isla', "Chat history cleared, let's start over!", false); 
            chatbotInput.disabled = false;
            if (chatbotSend) {
                chatbotSend.disabled = false;
            }
            chatbotInput.focus();
            return;
        }

        if (userMessage.toLowerCase().includes('save database')) {
            hideTypingIndicator(); 
            if (chatbotSaveDbButton) {
                chatbotSaveDbButton.style.display = 'block'; 
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
            return; 
        }

        const deleteNoteMatch = userMessage.toLowerCase().match(/^delete note (\d+)$/);
        if (deleteNoteMatch) {
            const noteNumber = parseInt(deleteNoteMatch[1]);
            hideTypingIndicator(); 
            await deleteNoteFromChatbot(noteNumber); 
            return; 
        }

        fetch('../public/chatbot_response.php', {
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
        })
        .catch(error => {
            console.error('Error fetching chatbot response:', error);
            hideTypingIndicator(); 
            appendMessage('Isla', "Sorry, I'm having trouble connecting right now. Please try again later.", false); 
            chatbotInput.disabled = false;
            if (chatbotSend) {
                chatbotSend.disabled = false;
            }
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

        fetch('../public/export_db.php', { // Corrected path for export_db.php
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
            location.reload(); 
        });
    }

    async function deleteNoteFromChatbot(noteNumber) {
        if (!chatbotMessages || !chatbotInput) {
            console.error('Chatbot messages or input not found for deleteNoteFromChatbot.');
            return;
        }

        await appendMessage('Isla', `Attempting to delete note number ${noteNumber}...`, false);
        chatbotInput.disabled = true;
        if (chatbotSend) chatbotSend.disabled = true;

        fetch('../public/dashboard.php', { // Corrected path for dashboard.php
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
            location.reload(); 
        })
        .catch(error => {
            console.error('Error deleting note:', error);
            appendMessage('Isla', "Sorry, I couldn't delete the note due to a network error. Please try again later.", false);
            chatbotInput.disabled = false;
            if (chatbotSend) chatbotSend.disabled = false;
        });
        chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
    }

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
                saveConversation(); 
                resolve();
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
                            resolve();
                        }
                    }
                    setTimeout(typeWriter, 300); 
                } else {
                    messageContent.innerHTML += message; 
                    saveConversation(); 
                    resolve();
                }
            }
            chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
        });
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
                appendMessage('Isla', "Hi there! How can I help you today? Type 'list all commands' to see all the available commands.", false);
            }
            chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
        }
    }

    function clearChat() {
        if (chatbotMessages) {
            chatbotMessages.innerHTML = ''; 
            localStorage.removeItem(CHAT_STORAGE_KEY);
            appendMessage('Isla', "Hi there! How can I help you today?", false);
            chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
        }
    }

    document.getElementById('logoutButton').addEventListener('click', function() {
        localStorage.removeItem(CHAT_STORAGE_KEY);
    });
});


        // --- PRINT FUNCTIONALITY ---
        const printButton = document.getElementById('printComputedGradesButton');
        if (printButton) {
            printButton.addEventListener('click', function() {
                printComputedGradesTable();
            });
        }

        function printComputedGradesTable() {
            const tableToPrint = document.getElementById('computedGradesTable');
            if (!tableToPrint) {
                alert('Computed grades table not found!'); 
                return;
            }

            const subjectName = "<?= htmlspecialchars($class['subject_name'], ENT_QUOTES, 'UTF-8') ?>";
            const sectionName = "<?= htmlspecialchars($class['section_name'], ENT_QUOTES, 'UTF-8') ?>";
            const teacherName = "<?= htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8') ?>";
            
            const printWindow = window.open('', '_blank');
            printWindow.document.write('<html><head><title>Print Computed Class Record - ' + subjectName + ' - ' + sectionName + '</title>');
            printWindow.document.write('<style>');
            printWindow.document.write('body { margin: 20px; font-family: Arial, sans-serif; font-size: 10pt; }');
            printWindow.document.write('.print-header-container { text-align: center; margin-bottom: 20px; }');
            printWindow.document.write('.print-header-container h2 { margin: 0 0 5px 0; font-size: 16pt;}');
            printWindow.document.write('.print-header-container p { margin: 0; font-size: 12pt;}');
            printWindow.document.write('.print-info-table { width: 100%; margin-bottom: 15px; border-collapse: collapse; font-size: 10pt; }');
            printWindow.document.write('.print-info-table td { padding: 4px; border: none; text-align:left; }'); 
            printWindow.document.write('table { width: 100%; border-collapse: collapse; margin-top: 10px; }');
            printWindow.document.write('th, td { border: 1px solid #000; padding: 3px 5px; text-align: center; vertical-align: middle; font-size: 8pt; }'); 
            printWindow.document.write('thead th { background-color: #e9e9e9; font-weight: bold; font-size: 8pt; }'); 
            printWindow.document.write('tbody td { font-size: 8pt; }');
            printWindow.document.write('.student-name { font-weight: normal; font-size: 9pt; text-align: left !important; }'); 
            printWindow.document.write('.student-id { font-size: 7.5pt; color: #333; text-align: left !important; display: block; }'); 
            printWindow.document.write('.text-start { text-align: left !important; }'); 
            printWindow.document.write('.bg-success-subtle { background-color: #e6ffed !important; color: #000 !important; }'); 
            printWindow.document.write('.fw-bold { font-weight: bold !important; }');
            printWindow.document.write('</style></head><body>');
            
            printWindow.document.write('<div class="print-header-container">');
            printWindow.document.write('<h2>Computed Class Record</h2>');
            printWindow.document.write('<p>Universidad De Manila</p>');
            printWindow.document.write('</div>');

            printWindow.document.write('<table class="print-info-table">');
            printWindow.document.write('<tr><td style="width: 50%;"><strong>Subject:</strong> ' + subjectName + '</td>');
            printWindow.document.write('<td style="width: 50%;"><strong>Section:</strong> ' + sectionName + '</td></tr>');
            printWindow.document.write('<tr><td><strong>Teacher:</strong> ' + teacherName + '</td>');
            printWindow.document.write('<td><strong>Date Printed:</strong> ' + new Date().toLocaleDateString() + '</td></tr>');
            printWindow.document.write('</table>');

            const clonedTable = tableToPrint.cloneNode(true);

            clonedTable.querySelectorAll('td.text-start').forEach(cell => {
                cell.style.textAlign = 'left';
                const studentNameSpan = cell.querySelector('.student-name');
                if(studentNameSpan) studentNameSpan.style.textAlign = 'left';
                const studentIdSpan = cell.querySelector('.student-id');
                if(studentIdSpan) studentIdSpan.style.textAlign = 'left';
            });
            
            clonedTable.querySelectorAll('thead th').forEach(th => {
                 th.style.textAlign = 'center'; 
                 if (th.classList.contains('text-start')) { 
                     th.style.textAlign = 'left';
                 }
            });


            printWindow.document.write(clonedTable.outerHTML);
            printWindow.document.write('</body></html>');
            printWindow.document.close();
            printWindow.focus(); 

            setTimeout(function() {
                printWindow.print();
            }, 250);
        }
    </script>
<div class="chatbot-container">
    <button type="button" class="btn btn-primary btn-chatbot" id="chatbotToggle" data-bs-toggle="popover" data-bs-placement="top" title="UDM Isla">
        <i class="bi bi-chat-dots-fill"></i>
    </button>

    <div id="chatbotPopoverContent" style="display: none;"> 
        <div class="chatbot-body" style="width: 300px; max-height: 450px; display: flex; flex-direction: column;">
            <div class="chatbot-messages mb-2 overflow-auto" id="chatbotMessages" style="max-height: 250px; border: 1px solid #ccc; padding: 10px; border-radius: 5px; background: #f8f9fa;">
                </div>
            <div class="input-group mb-2">
                <input type="text" id="chatbotInput" class="form-control" placeholder="Type your question..." aria-label="Type your question">
                <button class="btn btn-primary" type="button" id="chatbotSendBtn"><i class="bi bi-send-fill"></i></button>
            </div>
            <button class="btn btn-success w-100" type="button" id="chatbotSaveDbButton" style="display: none;">
                <i class="bi bi-download"></i> Save Database Now
            </button>
        </div>
    </div>
</div>


</body>
</html>