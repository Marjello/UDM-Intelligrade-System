<?php
require_once '../config/db.php';
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    header("Location: ../public/login.php");
    exit();
}

$teacher_id = $_SESSION['teacher_id'];
$full_name = $_SESSION['full_name'] ?? 'Teacher'; // Used for print header
$class_id = (int)($_GET['class_id'] ?? 0);

if ($class_id === 0) {
    // Consider a more user-friendly error page or redirect
    exit("Error: No class ID provided. Please select a class.");
}

// Fetch class info and implicitly check permission
$query = "SELECT c.*, s.subject_name, sec.section_name
          FROM classes c
          JOIN subjects s ON c.subject_id = s.subject_id
          JOIN sections sec ON c.section_id = sec.section_id
          WHERE c.class_id = " . $class_id . " AND c.teacher_id = " . $teacher_id;
$result = $conn->query($query);
$class = $result->fetch(PDO::FETCH_ASSOC);

if (!$class) {
    // Check if class exists at all to give a more specific error.
    $check_query = "SELECT class_id FROM classes WHERE class_id = " . $class_id;
    $check_result = $conn->query($check_query);
    $exists = $check_result->fetch(PDO::FETCH_ASSOC) !== false;
    // Consider a more user-friendly error page or redirect
    exit($exists ? "Access Denied: You don't have permission to access this class." : "Error: Class not found.");
}

// Fetch enrolled students
$query = "SELECT e.enrollment_id, s.student_number, s.first_name, s.last_name
          FROM enrollments e JOIN students s ON s.student_id = e.student_id
          WHERE e.class_id = :class_id ORDER BY s.last_name, s.first_name";
$stmt = $conn->prepare($query);
$stmt->bindParam(':class_id', $class_id, PDO::PARAM_INT);
$stmt->execute();
$students_array = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch grade components
$query = "SELECT component_id, class_id, component_name, max_score, period, is_attendance_based 
          FROM grade_components 
          WHERE class_id = :class_id 
          ORDER BY period, component_name";
$stmt = $conn->prepare($query);
$stmt->bindParam(':class_id', $class_id, PDO::PARAM_INT);
$stmt->execute();
$component_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_grades'])) {
    try {
        error_log("=== STARTING GRADE SAVE PROCESS ===");
        error_log("POST data received: " . print_r($_POST, true));
        
        if (!isset($_POST['grades']) || !is_array($_POST['grades'])) {
            throw new Exception("Invalid grades data received");
        }
        
        $conn->beginTransaction();
        
        $grades = $_POST['grades'];
        $class_id = (int)$_POST['class_id'];
        
        error_log("Processing grades for class_id: $class_id");
        error_log("Grades array: " . print_r($grades, true));
        
        // First, verify the class exists and teacher has access
        $class_check = $conn->prepare("
            SELECT class_id FROM classes 
            WHERE class_id = ? AND teacher_id = ?
        ");
        $class_check->execute([$class_id, $teacher_id]);
        if (!$class_check->fetch()) {
            throw new Exception("Invalid class or unauthorized access");
        }
        
        // Prepare statements
        $update_stmt = $conn->prepare("
            UPDATE student_grades 
            SET score = :score 
            WHERE enrollment_id = :enrollment_id 
            AND component_id = :component_id
        ");
        
        $insert_stmt = $conn->prepare("
            INSERT INTO student_grades (enrollment_id, component_id, score)
            SELECT :enrollment_id, :component_id, :score
            WHERE NOT EXISTS (
                SELECT 1 FROM student_grades 
                WHERE enrollment_id = :enrollment_id 
                AND component_id = :component_id
            )
        ");
        
        if (!$update_stmt || !$insert_stmt) {
            throw new Exception("Failed to prepare statements: " . print_r($conn->errorInfo(), true));
        }

        $history_stmt = $conn->prepare("
            INSERT INTO grade_history (
                enrollment_id, class_id, teacher_id, component_id, 
                old_value, new_value, grade_type, change_timestamp
            ) VALUES (
                :enrollment_id, :class_id, :teacher_id, :component_id,
                :old_value, :new_value, 'numerical', CURRENT_TIMESTAMP
            )
        ");
        
        if (!$history_stmt) {
            throw new Exception("Failed to prepare history statement: " . print_r($conn->errorInfo(), true));
        }

        // Get existing grades for comparison
        $existing_grades = [];
        $enrollment_ids = array_keys($grades);
        if (!empty($enrollment_ids)) {
            $placeholders = str_repeat('?,', count($enrollment_ids) - 1) . '?';
            $existing_query = $conn->prepare("
                SELECT enrollment_id, component_id, score 
                FROM student_grades 
                WHERE enrollment_id IN ($placeholders)
            ");
            
            if (!$existing_query) {
                throw new Exception("Failed to prepare existing grades query: " . print_r($conn->errorInfo(), true));
            }
            
            $existing_query->execute($enrollment_ids);
            while ($row = $existing_query->fetch(PDO::FETCH_ASSOC)) {
                $existing_grades[$row['enrollment_id']][$row['component_id']] = $row['score'];
            }
        }
        
        error_log("Existing grades before update: " . print_r($existing_grades, true));

        // Process each grade
        $saved_count = 0;
        $errors = [];
        
        foreach ($grades as $enrollment_id => $component_grades) {
            foreach ($component_grades as $component_id => $score) {
                error_log("Processing grade - Enrollment: $enrollment_id, Component: $component_id, Score: $score");
                
                if ($score === '') {
                    error_log("Skipping empty score for enrollment_id: $enrollment_id, component_id: $component_id");
                    continue;
                }

                // Validate score is numeric
                if (!is_numeric($score)) {
                    $errors[] = "Invalid score value: $score for enrollment_id: $enrollment_id, component_id: $component_id";
                    continue;
                }

                $old_value = isset($existing_grades[$enrollment_id][$component_id]) ? 
                            (string)$existing_grades[$enrollment_id][$component_id] : null;
                $new_value = (string)$score;
                
                error_log("Grade comparison - Old: $old_value, New: $new_value");
                
                try {
                    // Try to update first
                    $params = [
                        ':enrollment_id' => $enrollment_id,
                        ':component_id' => $component_id,
                        ':score' => (float)$score
                    ];
                    
                    error_log("Executing update with params: " . print_r($params, true));
                    
                    $update_result = $update_stmt->execute($params);
                    
                    // If no rows were updated, try to insert
                    if ($update_stmt->rowCount() === 0) {
                        error_log("No existing grade found, attempting insert");
                        $insert_result = $insert_stmt->execute($params);
                        
                        if (!$insert_result) {
                            $error = $insert_stmt->errorInfo();
                            error_log("Insert failed: " . print_r($error, true));
                            throw new Exception("Failed to insert grade: " . $error[2]);
                        }
                    } else if (!$update_result) {
                        $error = $update_stmt->errorInfo();
                        error_log("Update failed: " . print_r($error, true));
                        throw new Exception("Failed to update grade: " . $error[2]);
                    }
                    
                    $saved_count++;
                    error_log("Successfully saved grade for enrollment_id: $enrollment_id, component_id: $component_id");

                    // Record history if changed
                    if ($old_value !== $new_value) {
                        error_log("Recording history - Old: $old_value, New: $new_value");
                        $history_params = [
                            ':enrollment_id' => $enrollment_id,
                            ':class_id' => $class_id,
                            ':teacher_id' => $teacher_id,
                            ':component_id' => $component_id,
                            ':old_value' => $old_value,
                            ':new_value' => $new_value
                        ];
                        
                        $result = $history_stmt->execute($history_params);
                        
                        if (!$result) {
                            $error = $history_stmt->errorInfo();
                            error_log("History recording failed: " . print_r($error, true));
                            throw new Exception("Failed to record history: " . $error[2]);
                        }
                        error_log("Successfully recorded history");
                    }
                } catch (Exception $e) {
                    error_log("Error processing individual grade: " . $e->getMessage());
                    $errors[] = $e->getMessage();
                }
            }
        }

        if (!empty($errors)) {
            error_log("Errors occurred during save: " . print_r($errors, true));
            throw new Exception("Some grades could not be saved: " . implode(", ", $errors));
        }

        if ($saved_count === 0) {
            throw new Exception("No valid grades were provided to save");
        }

        $conn->commit();
        error_log("Successfully saved $saved_count grades");
        error_log("=== GRADE SAVE PROCESS COMPLETED ===");
        
        $message = "Successfully saved $saved_count grades!";
        $message_type = "success";
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Error saving grades: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        $message = "Error saving grades: " . $e->getMessage();
        $message_type = "danger";
    }
}

// Handle save success message
$message = (isset($_GET['success']) && $_GET['success'] == 1) ? "Grades saved successfully!" : "";
$message_type = $message ? "success" : "";

// Group components by period
$grouped_components = [];
if (!empty($component_list)) {
    foreach ($component_list as $component) {
        $period = $component['period'] ?? 'Other'; // Default to 'Other' if period is null
        $grouped_components[$period][] = $component;
    }
}

// Fetch existing grades for all students (only if there are students and components)
$existing_grades = [];
if (!empty($students_array) && !empty($component_list)) {
    $query = "SELECT sg.enrollment_id, sg.component_id, sg.score
              FROM student_grades sg
              JOIN enrollments e ON sg.enrollment_id = e.enrollment_id
              WHERE e.class_id = " . $class_id;
    $grades_result = $conn->query($query);
    while ($grade = $grades_result->fetch(PDO::FETCH_ASSOC)) {
        $existing_grades[$grade['enrollment_id']][$grade['component_id']] = $grade['score'];
    }
}

// --- NEW: Fetch Grade History ---
$grade_history = [];
if ($class_id) {
    $query = "SELECT 
                gh.history_id,
                gh.change_timestamp,
                gh.old_value,
                gh.new_value,
                gh.grade_type,
                s.first_name,
                s.last_name,
                s.student_number,
                gc.component_name,
                gc.max_score
              FROM grade_history gh
              JOIN enrollments e ON gh.enrollment_id = e.enrollment_id
              JOIN students s ON e.student_id = s.student_id
              JOIN grade_components gc ON gh.component_id = gc.component_id
              WHERE gh.class_id = " . $class_id . "
              ORDER BY gh.change_timestamp DESC
              LIMIT 200";
    $history_result = $conn->query($query);
    if ($history_result) {
        while ($row = $history_result->fetch(PDO::FETCH_ASSOC)) {
            $grade_history[] = $row;
        }
    } else {
        error_log("Failed to execute history query: " . $conn->errorInfo()[2]);
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Input Grades - <?= htmlspecialchars($class['subject_name'] ?? 'Class') ?> - Universidad De Manila</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        /* CSS ( 그대로 유지 - 외부 파일로 옮기는 것을 권장 ) */
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
        .card { border: 1px solid #d6d0b8; box-shadow: 0 0.25rem 0.75rem rgba(0, 0, 0, 0.05); border-radius: 0.5rem; background-color: #fcfbf7; }
        .card-header { background-color: #e9e5d0; border-bottom: 1px solid #d6d0b8; padding: 1rem 1.25rem; font-weight: 500; color: #006400; }
        .btn-primary { background-color: #006400; border-color: #006400; }
        .btn-primary:hover { background-color: #004d00; border-color: #004d00; }
        .btn-outline-primary { color: #006400; border-color: #006400; }
        .btn-outline-primary:hover { background-color: #006400; border-color: #006400; color: white; }
        .alert-danger { background-color: #f8d7da; border-color: #f5c6cb; color: #721c24; }
        .alert-success { background-color: #d4edda; border-color: #c3e6cb; color: #155724; }
        .footer { padding: 1.5rem 0; margin-top: 2rem; font-size: 0.875rem; color: #006400; border-top: 1px solid #d6d0b8; }
        .table { background-color: #ffffff; border-radius: 0.375rem; overflow: hidden; }
        .table thead { background-color: #e9e5d0; color: #006400; }
        .table th { font-weight: 500; border-bottom-width: 1px; }
        .table td, .table th { padding: 0.75rem 1rem; vertical-align: middle; }
        .period-header { background-color: #f3f0e0; font-weight: 500; color: #006400; }
        .grades-table .form-control, .grades-table .form-select { padding: 0.4rem 0.5rem; font-size: 0.95rem; }
        .grades-table .student-name { white-space: nowrap; font-weight: 500; }
        .grades-table .student-id { font-size: 0.85rem; color: #666; }
        .grades-table .border-left { border-left: 2px solid #e9e5d0; }
        .table-responsive { overflow-x: auto; max-height: calc(100vh - 320px); /* Adjusted for sticky bar */ }
        .table-sticky thead th { position: sticky; top: 0; z-index: 10; background-color: #e9e5d0; }
        .table-sticky tbody tr:first-child td { border-top: none; }
        input[type="number"].grade-input, select.grade-select { max-width: 80px; }
        .max-score-label { display: block; font-size: 0.75rem; color: #666; text-align: center; margin-top: 0.25rem; }
        .sticky-action-bar { position: sticky; bottom: 0; background-color: rgba(245, 243, 225, 0.95); padding: 1rem 0; border-top: 1px solid #d6d0b8; z-index: 1000; }

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
        }
        @media (max-width: 768px) {
            .main-wrapper { flex-direction: column; } /* Ensure content flows below sidebar */
            .sidebar { width: 100%; height: auto; position: relative; /* Changed from static for potential z-index issues if any */ z-index: 1031; /* Higher than content if overlaps needed */ flex-direction: column;}
            .sidebar .logo-text { display: block; }
            .sidebar .sidebar-header { justify-content: flex-start; padding: 1rem; }
            .sidebar .logo-image { margin-right: 0.5rem; }
            .sidebar .nav-link span { display: inline; }
            .sidebar .nav-link .bi { margin-right: 0.85rem; font-size: 1.1rem; display: inline-block; text-align: center; }
            .sidebar .nav-menu { flex-grow: 0; } .sidebar .logout-item { margin-top: 1rem; }
            .content-area { margin-left: 0; width: 100%; padding: 1.5rem; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .page-header h2 { font-size: 1.5rem; margin-bottom: 1rem; }
            .page-header .btn, .page-header .d-flex.gap-2 > * { width: 100%; margin-top: 0.5rem; } /* Ensure all header buttons stack */
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

        /* Styles for Grade History Dropdown */
        .dropdown-menu-scroll {
            max-height: 400px;
            overflow-y: auto;
            width: 350px; /* Adjust width as needed */
        }
        .dropdown-item-history {
            white-space: normal; /* Allow text to wrap */
            padding: 0.5rem 1rem;
            border-bottom: 1px solid #eee;
        }
        .dropdown-item-history:last-child {
            border-bottom: none;
        }
        .history-meta {
            font-size: 0.75rem;
            color: #888;
        }
        .history-change {
            font-size: 0.9rem;
            font-weight: 500;
        }
        .history-old {
            color: #dc3545; /* Red for old value */
        }
        .history-new {
            color: #28a745; /* Green for new value */
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
            #printGradesButtonNumerical /* Hide print button itself when printing */
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
                text-align: center; 
            }
            .table-responsive {
                overflow-x: visible !important;
                max-height: none !important;
            }
            .table, .table th, .table td {
                border: 1px solid #000 !important;
                color: #000 !important;
                font-size: 8pt; /* Smaller font for potentially wide tables */
            }
            .table thead {
                background-color: #eee !important;
            }
            .grades-table .student-name { font-weight: normal; font-size: 9pt; } 
            .grades-table .student-id { font-size: 7pt; }
            .grades-table .max-score-label { display: none; } /* Hide max score label on print */
            .grades-table .period-header { font-size: 9pt; }
             .grades-table th { font-size: 8pt; }
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
            <li class="nav-item logout-item"><hr><a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#logoutModal" id="logoutButton"> <i class="bi bi-box-arrow-right"></i>
    <span>Logout</span>
</a></li>
        </ul>
    </nav>

    <main class="content-area">
        <header class="page-header">
            <div>
                <h2>Input Grades</h2>
                <p class="text-muted mb-0">
                    <i class="bi bi-book me-1"></i> <?= htmlspecialchars($class['subject_name']) ?> -
                    <i class="bi bi-people me-1"></i> <?= htmlspecialchars($class['section_name']) ?>
                </p>
            </div>
        
            <div class="d-flex gap-2 flex-wrap">
                <a href="manage_components.php?class_id=<?= $class_id ?>" class="btn btn-outline-primary"><i class="bi bi-list-check"></i> Manage Components</a>
               
                
                 <div class="dropdown">
                    <button class="btn btn-outline-info dropdown-toggle" type="button" id="gradeHistoryDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-clock-history me-1"></i> Grade History
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-scroll" aria-labelledby="gradeHistoryDropdown" id="gradeHistoryList">
                        <?php if (empty($grade_history)): ?>
                            <li><span class="dropdown-item-text text-muted">No grade history available for this class.</span></li>
                        <?php else: ?>
                            <?php foreach ($grade_history as $history_item): ?>
                                <li>
                                    <span class="dropdown-item dropdown-item-history">
                                        <div class="history-meta">
                                            <?= htmlspecialchars($history_item['first_name'] . ' ' . $history_item['last_name']) ?> (<?= htmlspecialchars($history_item['student_number']) ?>) - 
                                            <?= htmlspecialchars($history_item['component_name']) ?>
                                            <br>
                                            <small><?= date('M d, Y h:i A', strtotime($history_item['change_timestamp'])) ?></small>
                                        </div>
                                        <div class="history-change">
                                            Changed from <span class="history-old">
                                                <?= htmlspecialchars($history_item['old_value'] === null ? 'N/A' : ($history_item['grade_type'] === 'numerical' ? number_format($history_item['old_value'], 2) : $history_item['old_value'])) ?>
                                            </span> to <span class="history-new">
                                                <?= htmlspecialchars($history_item['new_value'] === null ? 'N/A' : ($history_item['grade_type'] === 'numerical' ? number_format($history_item['new_value'], 2) : $history_item['new_value'])) ?>
                                            </span>
                                        </div>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
                <button type="button" class="btn btn btn-outline-dark" id="printGradesButtonNumerical"><i class="bi bi-printer"></i> Print Grades</button>
                
                <a href="../teacher/your_classes.php" class="btn btn-outline-secondary"><i class="bi bi-person-workspace"></i> Your Classes</a>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="alert alert-<?= htmlspecialchars($message_type ?: 'info') ?> alert-dismissible fade show" role="alert" id="statusMessage"> <i class="bi bi-<?= ($message_type === 'success') ? 'check-circle-fill' : 'info-circle-fill' ?> me-2"></i> <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (empty($component_list)): ?>
            <div class="card shadow-sm">
                <div class="card-body text-center p-5">
                    <i class="bi bi-exclamation-circle text-warning" style="font-size: 3rem;"></i>
                    <h4 class="mt-3 mb-2">No Grade Components Found</h4>
                    <p class="text-muted">You need to create grade components before you can input grades.</p>
                    <a href="manage_components.php?class_id=<?= $class_id ?>" class="btn btn-primary mt-3"><i class="bi bi-plus-circle me-2"></i> Add Grade Components</a>
                </div>
            </div>
        <?php elseif (empty($students_array)): ?>
            <div class="card shadow-sm">
                <div class="card-body text-center p-5">
                    <i class="bi bi-people-fill text-warning" style="font-size: 3rem;"></i>
                    <h4 class="mt-3 mb-2">No Students Enrolled</h4>
                    <p class="text-muted">There are no students enrolled in this class yet.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div><i class="bi bi-calculator me-2"></i> Numerical Grading</div>
                    <span class="badge bg-primary rounded-pill"><?= count($students_array) ?> Student<?= count($students_array) > 1 ? 's' : '' ?></span>
                </div>
                <div class="card-body p-0">
                    <form method="POST" id="gradesForm">
                        <input type="hidden" name="class_id" value="<?= $class_id ?>">
                        <input type="hidden" name="save_grades" value="1">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover table-sticky grades-table mb-0" id="numericalGradesInputTable">
                                <thead>
                                    <tr>
                                        <th style="width: 250px; min-width:200px;" class="align-middle">Student Information</th>
                                        <?php foreach ($grouped_components as $period => $period_components): ?>
                                            <th colspan="<?= count($period_components) ?>" class="text-center period-header"><?= htmlspecialchars($period) ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                    <tr>
                                        <th>Name &amp; ID</th>
                                        <?php foreach ($grouped_components as $period_components): ?>
                                            <?php foreach ($period_components as $component): ?>
                                                <th class="text-center" style="min-width: 100px;">
                                                    <?= htmlspecialchars($component['component_name']) ?>
                                                    <span class="max-score-label">Max: <?= htmlspecialchars($component['max_score']) ?></span>
                                                </th>
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($students_array as $student): ?>
                                        <tr>
                                            <td>
                                                <div class="student-name"><?= htmlspecialchars($student['last_name'] . ", " . $student['first_name']) ?></div>
                                                <div class="student-id"><small><i class="bi bi-person-badge me-1"></i><?= htmlspecialchars($student['student_number']) ?></small></div>
                                            </td>
                                            <?php
                                            $last_processed_period = null;
                                            foreach ($component_list as $component_idx => $component_item):
                                                $existing_value = $existing_grades[$student['enrollment_id']][$component_item['component_id']] ?? '';
                                                $current_item_period = $component_item['period'] ?? 'Other';
                                                $apply_border_class = '';
                                                if ($component_idx === 0 || $last_processed_period !== $current_item_period) {
                                                    // Check if this is the first component overall, or the first component of a new period group
                                                    $is_first_in_group = true;
                                                    if ($component_idx > 0) {
                                                        $prev_component_period = $component_list[$component_idx-1]['period'] ?? 'Other';
                                                        if ($prev_component_period === $current_item_period) {
                                                            $is_first_in_group = false;
                                                        }
                                                    }
                                                    if ($is_first_in_group) {
                                                        $apply_border_class = 'border-left';
                                                    }
                                                }
                                            ?>
                                                <td class="text-center <?= $apply_border_class ?>">
                                                    <?php if ($component_item['is_attendance_based']): ?>
                                                        <select class="form-select form-select-sm grade-select" name="grades[<?= $student['enrollment_id'] ?>][<?= $component_item['component_id'] ?>]">
                                                            <option value="">--</option>
                                                            <option value="A" <?= $existing_value === 'A' ? 'selected' : '' ?>>A</option>
                                                            <option value="NA" <?= $existing_value === 'NA' ? 'selected' : '' ?>>NA</option>
                                                        </select>
                                                    <?php else: ?>
                                                        <input type="number" class="form-control form-control-sm grade-input"
                                                               name="grades[<?= $student['enrollment_id'] ?>][<?= $component_item['component_id'] ?>]"
                                                               min="0" max="<?= htmlspecialchars($component_item['max_score']) ?>" step="any" value="<?= htmlspecialchars($existing_value) ?>">
                                                    <?php endif; ?>
                                                </td>
                                            <?php
                                                $last_processed_period = $current_item_period;
                                            endforeach; // End component_list loop
                                            ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="sticky-action-bar text-end">
                            <div class="container-fluid px-4">
                                <a href="class_record_numerical_computed.php?class_id=<?= $class_id ?>" class="btn btn-outline-primary">
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
        <?php endif; ?>

        <footer class="footer text-center">
            &copy; <?= date('Y') ?> Universidad De Manila - Teacher Portal. All rights reserved.
        </footer>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../public/js/logout-handler.js"></script>
<script>
    // JavaScript ( 그대로 유지 - 외부 파일로 옮기는 것을 권장 )
    document.addEventListener('DOMContentLoaded', function() {
        const alerts = document.querySelectorAll('.alert.alert-dismissible'); // More specific selector for alerts
        alerts.forEach(function(alert) {
            setTimeout(function() {
                // Use Bootstrap's Alert instance to close it, ensuring proper handling of fade effects etc.
                var alertInstance = bootstrap.Alert.getOrCreateInstance(alert);
                if (alertInstance) {
                    alertInstance.close();
                }
            }, 5000);
        });

        const inputs = document.querySelectorAll('input.grade-input, select.grade-select');
        inputs.forEach(function(input) {
            const originalValue = input.value;
            input.addEventListener('input', function() { 
                if (this.value !== originalValue) {
                    this.classList.add('border-success'); // Bootstrap class for green border
                    this.style.backgroundColor = '#f0fff4'; 
                } else {
                    this.classList.remove('border-success');
                    this.style.backgroundColor = '';
                }
            });
             // Add blur event to validate max score for number inputs
            if (input.type === 'number' && input.hasAttribute('max')) {
                input.addEventListener('blur', function() {
                    const max = parseFloat(this.max);
                    const value = parseFloat(this.value);
                    if (!isNaN(value) && value > max) {
                        this.value = max; // Correct to max value
                        // Optionally, show an alert or a small message next to the input
                        showAlert('warning', `Score for ${this.closest('td').previousElementSibling.textContent.trim()} cannot exceed ${max}. Value has been adjusted.`);
                    } else if (!isNaN(value) && value < 0) {
                         this.value = 0; // Correct to min value (0)
                         showAlert('warning', `Score cannot be negative. Value has been adjusted to 0.`);
                    }
                });
            }
        });

        // Function to show a temporary alert message at the top of the content area
        function showAlert(type, message) {
            const existingAlert = document.getElementById('statusMessage');
            if(existingAlert) existingAlert.remove();

            const alertContainer = document.querySelector('.content-area'); // Or a more specific container if you prefer
            if (!alertContainer) return;

            const icon = type === 'success' ? 'check-circle-fill' : (type === 'warning' ? 'exclamation-triangle-fill' : 'info-circle-fill');
            const alertHtml = `
                <div class="alert alert-${type} alert-dismissible fade show" role="alert" id="statusMessage">
                    <i class="bi bi-${icon} me-2"></i> ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>`;
            // Insert after the page header
            const pageHeader = alertContainer.querySelector('.page-header');
            if (pageHeader) {
                pageHeader.insertAdjacentHTML('afterend', alertHtml);
            } else {
                alertContainer.insertAdjacentHTML('afterbegin', alertHtml);
            }
            
            const newAlert = document.getElementById('statusMessage');
            if (newAlert) {
                 setTimeout(function() {
                    var alertInstance = bootstrap.Alert.getOrCreateInstance(newAlert);
                    if (alertInstance) {
                        alertInstance.close();
                    }
                }, 7000); // Longer display for warnings
            }
        }


        // Print Grades Functionality for Numerical Grades
        const printButtonNumerical = document.getElementById('printGradesButtonNumerical');
        if (printButtonNumerical) {
            printButtonNumerical.addEventListener('click', function() {
                printNumericalGradesTable();
            });
        }

        function printNumericalGradesTable() {
            const tableToPrint = document.getElementById('numericalGradesInputTable');
            if (!tableToPrint) {
                showAlert('danger', 'Numerical grades table not found!');
                return;
            }

            const subjectName = "<?= htmlspecialchars($class['subject_name'], ENT_QUOTES, 'UTF-8') ?>";
            const sectionName = "<?= htmlspecialchars($class['section_name'], ENT_QUOTES, 'UTF-8') ?>";
            const teacherName = "<?= htmlspecialchars(isset($_SESSION['teacher_name']) ? $_SESSION['teacher_name'] : 'N/A', ENT_QUOTES, 'UTF-8') ?>"; // Assuming teacher name is in session
            const printWindow = window.open('', '_blank');
            printWindow.document.write('<html><head><title>Print Numerical Grades - ' + subjectName + ' - ' + sectionName + '</title>');
            printWindow.document.write('<style>');
            printWindow.document.write('body { margin: 15px; font-family: Arial, sans-serif; font-size: 9pt; }');
            printWindow.document.write('.print-header { text-align: center; margin-bottom: 10px; }');
            printWindow.document.write('.print-header h2 { margin: 0 0 5px 0; font-size: 14pt;}');
            printWindow.document.write('.print-header p { margin: 0; font-size: 11pt;}');
            printWindow.document.write('.info-table { width: 100%; margin-bottom: 15px; border-collapse: collapse; font-size: 9pt; }');
            printWindow.document.write('.info-table td { padding: 3px; }');
            printWindow.document.write('table { width: 100%; border-collapse: collapse; margin-top: 5px; }');
            printWindow.document.write('th, td { border: 1px solid #000; padding: 4px 6px; text-align: left; vertical-align: middle; }');
            printWindow.document.write('thead th { background-color: #e9e9e9; font-weight: bold; text-align: center; font-size: 8pt; }');
            printWindow.document.write('tbody td { font-size: 8pt; }');
            printWindow.document.write('.student-name { font-weight: normal; font-size: 9pt;}');
            printWindow.document.write('.student-id { font-size: 0.8em; color: #333; }');
            printWindow.document.write('.text-center { text-align: center !important; }');
            printWindow.document.write('.period-header { background-color: #e0e0e0 !important; font-size: 9pt !important; }');
            printWindow.document.write('.max-score-label { display: block; font-size: 0.8em; color: #555; text-align: center; margin-top: 2px; font-weight:normal; }');
            printWindow.document.write('</style></head><body>');
            
            printWindow.document.write('<div class="print-header">');
            printWindow.document.write('<h2>Grade Sheet - Numerical</h2>');
            printWindow.document.write('<p>Universidad De Manila</p>');
            printWindow.document.write('</div>');

            printWindow.document.write('<table class="info-table">');
            printWindow.document.write('<tr><td style="width: 50%;"><strong>Subject:</strong> ' + subjectName + '</td>');
            printWindow.document.write('<td style="width: 50%;"><strong>Section:</strong> ' + sectionName + '</td></tr>');
            printWindow.document.write('<tr><td><strong>Teacher:</strong> ' + teacherName + '</td>');
            printWindow.document.write('<td><strong>Date Printed:</strong> ' + new Date().toLocaleDateString() + '</td></tr>');
            printWindow.document.write('</table>');

            const clonedTable = tableToPrint.cloneNode(true);

            // Remove icons from student ID for cleaner print
            clonedTable.querySelectorAll('.student-id .bi-person-badge').forEach(icon => icon.remove());
            
            // Ensure Max score label is visible and correctly formatted in cloned table's TH if needed, or keep it as is.
            // The current CSS for print media already includes `.max-score-label` styles.

            // For select elements (attendance), replace them with their selected text value
            clonedTable.querySelectorAll('select.grade-select').forEach(select => {
                const selectedOption = select.options[select.selectedIndex];
                const cell = select.closest('td');
                if (cell) {
                    cell.textContent = selectedOption && selectedOption.value !== "" ? selectedOption.text : 'N/A';
                    cell.style.textAlign = 'center';
                }
            });

            // For input number elements, replace them with their value as text
            clonedTable.querySelectorAll('input.grade-input[type="number"]').forEach(input => {
                const cell = input.closest('td');
                if (cell) {
                    cell.textContent = input.value !== '' ? input.value : 'N/A';
                    cell.style.textAlign = 'center';
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
                // printWindow.close(); // Optional: close window after print
            };
        }

        // Update the JavaScript to handle the form submission
        const gradesForm = document.getElementById('gradesForm');
        if (gradesForm) {
            // Store all grades in memory
            let allGrades = {};
            
            // Function to update our stored grades
            function updateStoredGrades() {
                const inputs = document.querySelectorAll('input.grade-input, select.grade-select');
                inputs.forEach(input => {
                    const matches = input.name.match(/grades\[(\d+)\]\[(\d+)\]/);
                    if (matches) {
                        const [, enrollmentId, componentId] = matches;
                        if (!allGrades[enrollmentId]) {
                            allGrades[enrollmentId] = {};
                        }
                        allGrades[enrollmentId][componentId] = input.value;
                    }
                });
            }
            
            // Initialize stored grades from existing values
            updateStoredGrades();
            
            // Update stored grades when any input changes
            document.querySelectorAll('input.grade-input, select.grade-select').forEach(input => {
                input.addEventListener('change', function() {
                    updateStoredGrades();
                });
            });
            
            gradesForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Update our stored grades one last time
                updateStoredGrades();
                
                // Create FormData with ALL grades
                const formData = new FormData();
                formData.append('save_grades', '1');
                formData.append('class_id', document.querySelector('input[name="class_id"]').value);
                
                // Add all stored grades to the form data
                for (const enrollmentId in allGrades) {
                    for (const componentId in allGrades[enrollmentId]) {
                        const value = allGrades[enrollmentId][componentId];
                        if (value !== '') {  // Only include non-empty values
                            formData.append(`grades[${enrollmentId}][${componentId}]`, value);
                        }
                    }
                }
                
                // Validate that we have at least one grade to save
                let hasValidGrades = false;
                for (const enrollmentId in allGrades) {
                    for (const componentId in allGrades[enrollmentId]) {
                        const value = allGrades[enrollmentId][componentId];
                        if (value !== '' && !isNaN(value)) {
                            hasValidGrades = true;
                            break;
                        }
                    }
                    if (hasValidGrades) break;
                }
                
                if (!hasValidGrades) {
                    showAlert('warning', 'Please enter at least one valid grade before saving.');
                    return;
                }
                
                // Show loading state
                const submitButton = this.querySelector('button[type="submit"]');
                const originalButtonText = submitButton.innerHTML;
                submitButton.disabled = true;
                submitButton.innerHTML = '<i class="bi bi-hourglass-split me-2"></i> Saving...';
                
                // Log the data being sent
                console.log('Submitting all grades:', allGrades);
                
                // Submit the form
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.text();
                })
                .then(html => {
                    console.log('Server response:', html);
                    
                    // Check if the response contains an error message
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const errorMessage = doc.querySelector('.alert-danger');
                    const successMessage = doc.querySelector('.alert-success');
                    
                    if (errorMessage) {
                        throw new Error(errorMessage.textContent.trim());
                    }
                    
                    if (successMessage) {
                        // Show success message
                        showAlert('success', successMessage.textContent.trim());
                        
                        // Update the visual state of the inputs
                        const inputs = document.querySelectorAll('input.grade-input, select.grade-select');
                        inputs.forEach(input => {
                            // Add a temporary success highlight
                            input.classList.add('border-success');
                            input.style.backgroundColor = '#d4edda';
                            
                            // Remove the highlight after 2 seconds
                            setTimeout(() => {
                                input.classList.remove('border-success');
                                input.style.backgroundColor = '';
                            }, 2000);
                        });
                    }
                    
                    // Reset button state
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalButtonText;
                })
                .catch(error => {
                    console.error('Error:', error);
                    showAlert('danger', error.message || 'Failed to save grades. Please try again.');
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalButtonText;
                });
            });
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
            chatbotMessages.innerHTML = `
                <div class="message-container isla-message">
                    <p><strong>Isla:</strong> Hi there! How can I help you today?</p>
                </div>
            `;
            localStorage.removeItem(CHAT_STORAGE_KEY);
            chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
        }
    }
    
    // Corrected Logout Button Event Listener for Chat History Clearing
    const logoutButton = document.getElementById('logoutButton'); // Get the button by its ID
    if(logoutButton) {
        logoutButton.addEventListener('click', function(event) {
            // This event listener is for the link that opens the modal.
            // The actual logout action which should clear storage would be on the "Logout" button inside the modal.
            // However, clearing here is fine as a pre-emptive measure if the user confirms logout.
        });
    }
    // More accurately, if clearing should happen upon clicking the final logout button in the modal:
    const finalLogoutLink = document.querySelector('#logoutModal .btn-danger[href="../public/logout.php"]');
    if(finalLogoutLink) {
        finalLogoutLink.addEventListener('click', function() {
            localStorage.removeItem(CHAT_STORAGE_KEY);
        });
    }

});
</script>
</body>
</html>