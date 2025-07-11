<?php
require_once '../config/db.php';
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    header("Location: ../public/login.php");
    exit();
}

$teacher_id = $_SESSION['teacher_id'] ?? 0;
$full_name = $_SESSION['full_name'] ?? 'Teacher'; // Used for print header
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
                        WHERE c.class_id = :class_id AND c.teacher_id = :teacher_id");
$stmt->execute(['class_id' => $class_id, 'teacher_id' => $teacher_id]);
$class = $stmt->fetch();

if (!$class) {
    $check_stmt = $conn->prepare("SELECT class_id FROM classes WHERE class_id = :class_id");
    $check_stmt->execute(['class_id' => $class_id]);
    $exists = $check_stmt->rowCount() > 0;
    exit($exists ? "Access Denied: You don't have permission to access this class." : "Error: Class not found.");
}

$grading_type = $class['grading_system_type'] ?? 'numerical';

// Filter handling
$filter = $_GET['filter'] ?? '';
$filter_sql = $filter ? "AND (s.student_number LIKE :filter OR s.last_name LIKE :filter OR s.first_name LIKE :filter)" : '';
$filter_params = $filter ? ['filter' => "%$filter%"] : [];

// Fetch students
$students_sql = "
    SELECT e.enrollment_id, s.student_number, s.first_name, s.last_name
    FROM enrollments e
    JOIN students s ON s.student_id = e.student_id
    WHERE e.class_id = :class_id {$filter_sql}
    ORDER BY s.last_name, s.first_name
";

$students_stmt = $conn->prepare($students_sql);
$params = ['class_id' => $class_id];
if ($filter) {
    $params = array_merge($params, $filter_params);
}
$students_stmt->execute($params);
$students_result = $students_stmt->fetchAll();
$total_students = count($students_result);

// Fetch grade components
$components_sql = "SELECT * FROM grade_components WHERE class_id = :class_id ORDER BY CASE period 
    WHEN 'Preliminary' THEN 1 
    WHEN 'Mid-Term' THEN 2 
    WHEN 'Pre-Final' THEN 3 
    ELSE 4 END";
$components_stmt = $conn->prepare($components_sql);
$components_stmt->execute(['class_id' => $class_id]);
$components_query_result = $components_stmt->fetchAll();

$components = [];
foreach ($components_query_result as $comp) {
    $components[$comp['period']][] = $comp;
}

// Fetch attendance grades from student_grades table
$attendance_grades = [];
if ($total_students > 0) {
    // Get the component IDs for attendance components
    $prelim_component_id = null;
    $midterm_component_id = null;
    
    foreach ($components_query_result as $comp) {
        if ($comp['period'] === 'Preliminary' && $comp['is_attendance_based']) {
            $prelim_component_id = $comp['component_id'];
        } elseif ($comp['period'] === 'Mid-Term' && $comp['is_attendance_based']) {
            $midterm_component_id = $comp['component_id'];
        }
    }
    
    // Fetch preliminary attendance
    if ($prelim_component_id) {
        $attendance_stmt = $conn->prepare("
            SELECT sg.enrollment_id, sg.attendance_status_prelim
            FROM student_grades sg
            JOIN enrollments e ON sg.enrollment_id = e.enrollment_id
            WHERE e.class_id = :class_id AND sg.component_id = :component_id AND sg.attendance_status_prelim IS NOT NULL
        ");
        $attendance_stmt->execute(['class_id' => $class_id, 'component_id' => $prelim_component_id]);
        while ($attendance = $attendance_stmt->fetch()) {
            $attendance_grades[$attendance['enrollment_id']]['Preliminary'] = $attendance['attendance_status_prelim'];
        }
    }
    
    // Fetch midterm attendance
    if ($midterm_component_id) {
        $attendance_stmt = $conn->prepare("
            SELECT sg.enrollment_id, sg.attendance_status_midterm
            FROM student_grades sg
            JOIN enrollments e ON sg.enrollment_id = e.enrollment_id
            WHERE e.class_id = :class_id AND sg.component_id = :component_id AND sg.attendance_status_midterm IS NOT NULL
        ");
        $attendance_stmt->execute(['class_id' => $class_id, 'component_id' => $midterm_component_id]);
        while ($attendance = $attendance_stmt->fetch()) {
            $attendance_grades[$attendance['enrollment_id']]['Mid-Term'] = $attendance['attendance_status_midterm'];
        }
    }
}

// Fetch final grades from final_grades table
$final_grades_map = [];
if ($total_students > 0) {
    $final_stmt = $conn->prepare("
        SELECT fg.enrollment_id, fg.overall_final_grade, fg.remarks
        FROM final_grades fg
        JOIN enrollments e ON fg.enrollment_id = e.enrollment_id
        WHERE fg.class_id = :class_id
    ");
    $final_stmt->execute(['class_id' => $class_id]);
    while ($final_row = $final_stmt->fetch()) {
        $final_grades_map[$final_row['enrollment_id']] = [
            'grade' => $final_row['overall_final_grade'],
            'remarks' => $final_row['remarks']
        ];
    }
}

// Grade computation functions
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
    if ($grade == 75) return ["1.00", "Passed"];
    if ($grade < 75 && $grade >= 0) return ["0.00", "Failed"];
    if ($grade < 0) return ["0.00", "Failed"];
    return ["NGS", "No Grade Yet"];
}

function computeFinalGradeValue($final_grade_value) {
    $prefinal_grade = is_numeric($final_grade_value) ? $final_grade_value : 0;
    return round($prefinal_grade, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UDM IntelliGrade - View Class Record - <?= htmlspecialchars($class['subject_name'] ?? 'Class') ?> </title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        /* CSS Styles */
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
        .page-header .page-actions { display: flex; align-items: center; gap: 0.5rem; } /* Container for buttons */
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
        .period-header { background-color: #f3f0e0; font-weight: 500; color: #006400; }
        .table-responsive { overflow-x: auto; max-height: calc(100vh - 380px); /* Adjusted height */ }
        .table-sticky thead th { position: sticky; top: 0; z-index: 10; background-color: #e9e5d0; }
        .table-sticky tbody tr:first-child td { border-top: none; }
        .student-name { white-space: nowrap; font-weight: 500; }
        .student-id { font-size: 0.85rem; color: #666; }
        .search-container { margin-bottom: 1.5rem; } /* Adjusted margin */
        .search-box { max-width: 400px; }
        .grades-table .student-info { min-width: 220px; text-align: left;}
        .attendance-grade { font-size: 0.85rem; color: #666; }
        .grade-computed { background-color: #e8f5e8; font-weight: 500; }
        .notes-section { font-size: 0.875rem; color: #555; margin-top: 1rem; }


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
            .page-header .page-actions .btn { width: 100%; } /* Make buttons full width */
        }
        @media (max-width: 768px) {
            .main-wrapper { flex-direction: column; }
            .sidebar { width: 100%; height: auto; position: relative; z-index: 1031; flex-direction: column;}
            .sidebar .logo-text { display: block; }
            .sidebar .sidebar-header { justify-content: flex-start; padding: 1rem; }
            .sidebar .logo-image { margin-right: 0.5rem; }
            .sidebar .nav-link span { display: inline; }
            .sidebar .nav-link .bi { margin-right: 0.85rem; font-size: 1.1rem; display: inline-block; text-align: center; }
            .sidebar .nav-menu { flex-grow: 0; } .sidebar .logout-item { margin-top: 1rem; }
            .content-area { margin-left: 0; width: 100%; padding: 1.5rem; }
            /* .page-header h2 { font-size: 1.5rem; margin-bottom: 1rem; } Removed to use stacked gap */
            /* .page-header .btn { width: 100%; margin-top: 0.5rem; } Removed to use stacked gap */
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
                margin: 15px;
            }
            .main-wrapper > .sidebar,
            .main-wrapper > .content-area > .page-header .page-actions, /* Hide all action buttons in header */
            .main-wrapper > .content-area > .card > .card-body > .search-container, /* Hide search container */
            .main-wrapper > .content-area > .footer,
            .modal,
            .alert:not(.alert-info-print), /* Hide alerts unless specifically for print */
            .chatbot-container,
            #printClassRecordButton /* Hide print button itself when printing */
            {
                display: none !important;
            }
            .content-area {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 0 !important;
            }
            .page-header {
                border-bottom: 1px solid #000 !important;
                margin-bottom: 1rem !important;
                justify-content: flex-start !important;
            }
            .page-header h2 {
                font-size: 1.5rem !important;
            }
             .page-header .text-muted .badge { /* Make grading system badge visible for print */
                display: inline-block !important;
                font-size: 10pt !important;
                background-color: #ccc !important; /* Neutral color for print */
                color: #000 !important;
            }
            .card {
                border: none !important;
                box-shadow: none !important;
                margin-bottom: 0 !important;
            }
            .card-header {
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
                font-size: 8pt !important; /* Smaller font for print */
            }
            .table thead {
                background-color: #eee !important;
            }
            .table thead th {
                font-weight: bold !important;
                background-color: #e9e9e9 !important;
                text-align: center !important;
                font-size: 8pt !important;
            }
            .grades-table .student-info, .grades-table .student-info .student-name {
                 text-align: left !important; font-size: 9pt !important;
            }
            .grades-table .student-info .student-id {
                font-size: 7.5pt !important; color: #333 !important;
            }
            .grade-computed {
                background-color: #e0e0e0 !important; /* Light gray for computed grade background */
                font-weight: bold !important;
            }
            .notes-section { display: none; } /* Hide on-screen notes */


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
            <li class="nav-item logout-item"><hr><a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#logoutModal" id="logoutButton"> <i class="bi bi-box-arrow-right"></i>
    <span>Logout</span>
</a></li>
        </ul>
    </nav>

    <main class="content-area">
        <header class="page-header">
            <div>
                <h2>Class Record</h2>
                <p class="text-muted mb-0">
                    <i class="bi bi-book me-1"></i> <?= htmlspecialchars($class['subject_name']) ?> -
                    <i class="bi bi-people me-1"></i> <?= htmlspecialchars($class['section_name']) ?> 
                    <span class="badge bg-primary rounded-pill me-2">A/NA Based</span>
                </p>
            </div>
            <div class="page-actions flex-wrap"> <a href="<?= $grading_type === 'attendance' ? 'input_grades_final_only.php' : ($grading_type === 'numerical' ? 'input_grades_numerical.php' : 'input_grades.php') ?>?class_id=<?= $class_id ?>" class="btn btn-primary ms-lg-auto"><i class="bi bi-pencil-square"></i> Input Grades</a>
                <a href="manage_components.php?class_id=<?= $class_id ?>" class="btn btn-outline-primary"><i class="bi bi-list-check"></i> Manage Components</a>
               
                <a href="../teacher/your_classes.php" class="btn btn-outline-secondary"><i class="bi bi-grid-1x2"></i> Your Classes</a>
            </div>
        </header>
        
        <div class="card shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div><i class="bi bi-table me-2"></i> Students Grade Record</div>
               
                <span class="badge bg-primary rounded-pill"><?= $total_students ?> Student<?= $total_students > 1 ? 's' : '' ?></span>
            </div>
            <div class="card-body">
                <div class="search-container">
                    <form method="GET" class="d-flex align-items-center">
                        <input type="hidden" name="class_id" value="<?= $class_id ?>">
                        <div class="input-group search-box">
                            <input type="text" name="filter" class="form-control" placeholder="Search by name or student number" value="<?= htmlspecialchars($filter) ?>">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Search</button>
                            
                        </div>
                         <?php if ($filter): ?>
                            <a href="view_class_record.php?class_id=<?= $class_id ?>" class="btn btn-outline-secondary ms-2"><i class="bi bi-x-circle"></i> Clear</a>
                        <?php endif; ?>
                          <button type="button" class="btn btn-outline-info" id="printClassRecordButton"><i class="bi bi-printer"></i> Print Class Record</button> 
                    </form>
                </div>

                <?php if (count($students_result) === 0): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i> No students found<?= $filter ? ' matching your search criteria' : ' enrolled in this class' ?>.
                    </div>
                <?php elseif (empty($components) && $grading_type !== 'attendance'): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-circle me-2"></i> No grade components have been defined for this class.
                        <a href="manage_components.php?class_id=<?= $class_id ?>" class="alert-link">Add grade components now</a>.
                    </div>
                 <?php elseif ($grading_type === 'attendance' && (empty($components['Preliminary']) || empty($components['Mid-Term']) || empty($components['Pre-Final']))): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-circle me-2"></i> Ensure 'Preliminary', 'Mid-Term', and 'Pre-Final' attendance components are defined for this class.
                        <a href="manage_components.php?class_id=<?= $class_id ?>" class="alert-link">Manage grade components</a>.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover table-sticky grades-table mb-0" id="classRecordTable"> <thead>
                                <tr>
                                    <th class="student-info">Student Information</th>
                                    <?php 
                                    $period_headers = [];
                                    if ($grading_type === 'attendance') {
                                        // Specific order for attendance
                                        if (isset($components['Preliminary'])) $period_headers['Preliminary'] = $components['Preliminary'];
                                        if (isset($components['Mid-Term'])) $period_headers['Mid-Term'] = $components['Mid-Term'];
                                        if (isset($components['Pre-Final'])) $period_headers['Pre-Final'] = $components['Pre-Final'];
                                    } else {
                                        // Default order based on DB fetch for other types (should be FIELD sorted)
                                        $period_headers = $components;
                                    }

                                    foreach ($period_headers as $period => $comps): ?>
                                        <th class="text-center period-header"><?= htmlspecialchars($period) ?></th>
                                    <?php endforeach; ?>
                                    <th class="text-center">Computed Final Grade</th>
                                    <th class="text-center">Grade Equivalent</th>
                                    <th class="text-center">Description</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php 
                            // No need for mysqli_data_seek since we're using an array
                            foreach ($students_result as $student):
                                $enrollment_id = $student['enrollment_id'];
                                
                                $student_final_grade_value = 0; // Initialize
                                if (isset($final_grades_map[$enrollment_id])) {
                                    $student_final_grade_value = $final_grades_map[$enrollment_id]['grade'];
                                }
                                
                                $computed_grade = computeFinalGradeValue($student_final_grade_value);
                                list($grade_eq, $description) = getGradeEquivalent($computed_grade);
                            ?>
                                <tr>
                                    <td class="student-info"> <div class="student-name"><?= htmlspecialchars($student['last_name'] . ", " . $student['first_name']) ?></div>
                                        <div class="student-id"><small><i class="bi bi-person-badge me-1"></i><?= htmlspecialchars($student['student_number']) ?></small></div>
                                    </td>
                                    
                                    <?php foreach ($period_headers as $period => $comps): ?>
                                    <td class="text-center">
                                        <?php
                                        // Modified logic to display overall_final_grade for 'Pre-Final' column
                                        if ($period === 'Pre-Final') {
                                            if (isset($final_grades_map[$enrollment_id]['grade']) && is_numeric($final_grades_map[$enrollment_id]['grade'])) {
                                                echo number_format((float)$final_grades_map[$enrollment_id]['grade'], 2);
                                            } else {
                                                echo '<span class="text-muted">-</span>';
                                            }
                                        } else if ($grading_type === 'attendance' || (isset($comps[0]['is_attendance_based']) && $comps[0]['is_attendance_based'])) {
                                            // Existing logic for attendance statuses
                                            $period_attendance_key = $period; 
                                            $attendance_value = $attendance_grades[$enrollment_id][$period_attendance_key] ?? null;

                                            if ($attendance_value === 'A') {
                                                echo 'Attended';
                                            } elseif ($attendance_value === 'NA') {
                                                echo 'Not Attended';
                                            } else {
                                                echo '<span class="text-muted">-</span>';
                                            }
                                        } else {
                                            // Placeholder for other grading types' period grades if needed
                                            echo '<span class="text-muted">-</span>';
                                        }
                                        ?>
                                    </td>
                                    <?php endforeach; ?>
                                    
                                    <td class="text-center fw-bold grade-computed"><?= is_numeric($computed_grade) ? number_format($computed_grade, 2) . '%' : $computed_grade ?></td>
                                    <td class="text-center"><?= $grade_eq ?></td>
                                    <td class="text-center"><?= $description ?></td> </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="mt-3 notes-section">
                        <small>
                            <strong>Grade Computation:</strong> 
                            Final Grade is based on Pre-Final numerical score only for 'Attendance' grading type. For other types, it should reflect their respective computation methods. <br>
                            <strong>Attendance:</strong> Present/Absent status is for record keeping purposes.
                        </small>
                    </div>
                <?php endif; ?>
                <?php /* No need to close $students_stmt since we're using PDO */ ?>
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
        fetch('../public/chatbot_response.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'query=' + encodeURIComponent(userMessage)
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
                // If no conversation found, add the initial Isla message
                appendMessage('Isla', "Hi there! How can I help you today?", false);
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


// --- PRINT FUNCTIONALITY ---
    const printButton = document.getElementById('printClassRecordButton');
    if (printButton) {
        printButton.addEventListener('click', function() {
            printClassRecordTable();
        });
    }

    function printClassRecordTable() {
        const tableToPrint = document.getElementById('classRecordTable');
        if (!tableToPrint) {
            alert('Class record table not found!');
            return;
        }

        const subjectName = "<?= htmlspecialchars($class['subject_name'], ENT_QUOTES, 'UTF-8') ?>";
        const sectionName = "<?= htmlspecialchars($class['section_name'], ENT_QUOTES, 'UTF-8') ?>";
        const teacherName = "<?= htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8') ?>"; // Using the updated $full_name
        const gradingSystemType = "<?= strtoupper(htmlspecialchars($grading_type)) ?>";

        const printWindow = window.open('', '_blank');
        printWindow.document.write('<html><head><title>Print Class Record - ' + subjectName + ' - ' + sectionName + '</title>');
        printWindow.document.write('<style>');
        // Basic print styles - these will be complemented by @media print CSS in the main file
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
        printWindow.document.write('.student-info-print { text-align: left !important; }'); // For student name column
        printWindow.document.write('.student-name-print { font-weight: normal; font-size: 9pt !important; display: block; }');
        printWindow.document.write('.student-id-print { font-size: 7.5pt !important; color: #333 !important; display: block; }');
        printWindow.document.write('.grade-computed-print { background-color: #e0e0e0 !important; font-weight: bold !important; }');
        printWindow.document.write('</style></head><body>');
        
        printWindow.document.write('<div class="print-header-container">');
        printWindow.document.write('<h2>Class Record</h2>');
        printWindow.document.write('<p>Universidad De Manila</p>');
        printWindow.document.write('</div>');

        printWindow.document.write('<table class="print-info-table">');
        printWindow.document.write('<tr><td style="width: 50%;"><strong>Subject:</strong> ' + subjectName + '</td>');
        printWindow.document.write('<td style="width: 50%;"><strong>Section:</strong> ' + sectionName + '</td></tr>');
        printWindow.document.write('<tr><td><strong>Teacher:</strong> ' + teacherName + '</td>');
        printWindow.document.write('<tr><td colspan="2"><strong>Date Printed:</strong> ' + new Date().toLocaleDateString() + '</td></tr>');
        printWindow.document.write('</table>');

        const clonedTable = tableToPrint.cloneNode(true);
        
        // Apply specific classes for print styling to the cloned table if needed
        clonedTable.querySelectorAll('tbody .student-info').forEach(cell => {
            cell.classList.add('student-info-print');
            const nameDiv = cell.querySelector('.student-name');
            if (nameDiv) nameDiv.classList.add('student-name-print');
            const idDiv = cell.querySelector('.student-id');
            if (idDiv) idDiv.classList.add('student-id-print');
        });
        clonedTable.querySelectorAll('tbody .grade-computed').forEach(cell => {
            cell.classList.add('grade-computed-print');
        });


        printWindow.document.write(clonedTable.outerHTML);
        printWindow.document.write('</body></html>');
        printWindow.document.close();
        printWindow.focus(); 

        setTimeout(function() {
            printWindow.print();
            // printWindow.close(); // Optional
        }, 250);
    }

</script>

</body>
</html>