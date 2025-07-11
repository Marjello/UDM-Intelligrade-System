<?php
session_start();
require_once '../config/db.php';
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    header("Location: ../public/login.php");
    exit();
}

$teacher_id = $_SESSION['teacher_id'];
$full_name = $_SESSION['full_name'] ?? 'Teacher';

$class_id = $_GET['class_id'] ?? null;
if (!$class_id) {
    echo "Class ID is missing.";
    exit();
}

// Get class details for the page header
$class_query = $conn->prepare("SELECT s.subject_name, sec.section_name 
                              FROM classes c 
                              JOIN subjects s ON c.subject_id = s.subject_id 
                              JOIN sections sec ON c.section_id = sec.section_id 
                              WHERE c.class_id = :class_id AND c.teacher_id = :teacher_id");
$class_query->execute([
    ':class_id' => $class_id,
    ':teacher_id' => $teacher_id
]);
$class_details = $class_query->fetch(PDO::FETCH_ASSOC);

if (!$class_details) {
    echo "Class not found or you don't have permission to access it.";
    exit();
}

$message = "";
$message_type = ""; // Initialize message_type

// Check for success message from redirect
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $message = "Component added successfully.";
    $message_type = "success";
}
if (isset($_GET['success_edit']) && $_GET['success_edit'] == '1') {
    $message = "Component updated successfully.";
    $message_type = "success";
}

// Handle delete request
if (isset($_GET['delete_id']) && is_numeric($_GET['delete_id'])) {
    $delete_id = $_GET['delete_id'];
    
    $check_query = $conn->prepare("SELECT gc.component_id FROM grade_components gc 
                                  JOIN classes c ON gc.class_id = c.class_id 
                                  WHERE gc.component_id = :delete_id AND gc.class_id = :class_id AND c.teacher_id = :teacher_id");
    $check_query->execute([
        ':delete_id' => $delete_id,
        ':class_id' => $class_id,
        ':teacher_id' => $teacher_id
    ]);
    
    if ($check_query->fetch()) {
        try {
            $delete_stmt = $conn->prepare("DELETE FROM grade_components WHERE component_id = :delete_id");
            $delete_stmt->execute([':delete_id' => $delete_id]);
            
            $message = "Component deleted successfully.";
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Error deleting component. It may have associated student grades.";
            $message_type = "danger";
        }
    }
}

// Handle form submission for ADD or EDIT
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['edit_component_id']) && is_numeric($_POST['edit_component_id'])) {
        // EDIT COMPONENT LOGIC
        $edit_id = $_POST['edit_component_id'];
        $edit_component_name = trim($_POST['edit_component_name']);
        $edit_max_score = $_POST['edit_max_score'];
        $edit_period = !empty($_POST['edit_period']) ? $_POST['edit_period'] : null;
        $edit_type = !empty($_POST['edit_type']) ? $_POST['edit_type'] : null;
        $edit_is_attendance_based = isset($_POST['edit_is_attendance_based']) ? 1 : 0;
        $edit_weight = isset($_POST['edit_weight']) && is_numeric($_POST['edit_weight']) ? (float)$_POST['edit_weight'] : 0.00;

        $check_query = $conn->prepare("SELECT gc.component_id FROM grade_components gc
                                      JOIN classes c ON gc.class_id = c.class_id
                                      WHERE gc.component_id = :edit_id AND gc.class_id = :class_id AND c.teacher_id = :teacher_id");
        $check_query->execute([
            ':edit_id' => $edit_id,
            ':class_id' => $class_id,
            ':teacher_id' => $teacher_id
        ]);

        if ($check_query->fetch()) {
            $name_check = $conn->prepare("SELECT component_id FROM grade_components
                                          WHERE class_id = :class_id AND component_name = :component_name AND component_id != :edit_id");
            $name_check->execute([
                ':class_id' => $class_id,
                ':component_name' => $edit_component_name,
                ':edit_id' => $edit_id
            ]);

            if ($name_check->fetch()) {
                $message = "A component with this name already exists.";
                $message_type = "danger";
            } else {
                try {
                    $update_stmt = $conn->prepare("UPDATE grade_components
                                                 SET component_name = :component_name, 
                                                     max_score = :max_score,
                                                     period = :period, 
                                                     type = :type, 
                                                     is_attendance_based = :is_attendance_based, 
                                                     weight = :weight
                                                 WHERE component_id = :edit_id");
                    $update_stmt->execute([
                        ':component_name' => $edit_component_name,
                        ':max_score' => $edit_max_score,
                        ':period' => $edit_period,
                        ':type' => $edit_type,
                        ':is_attendance_based' => $edit_is_attendance_based,
                        ':weight' => $edit_weight,
                        ':edit_id' => $edit_id
                    ]);

                    header("Location: manage_components.php?class_id=$class_id&success_edit=1");
                    exit();
                } catch (PDOException $e) {
                    $message = "Error updating component.";
                    $message_type = "danger";
                }
            }
        } else {
            $message = "Component not found or you don't have permission to edit it.";
            $message_type = "danger";
        }

    } else {
        // ADD NEW COMPONENT LOGIC
        $component_name = trim($_POST['component_name']);
        $max_score = $_POST['max_score'];
        $period = !empty($_POST['period']) ? $_POST['period'] : null;
        $type = !empty($_POST['type']) ? $_POST['type'] : null;
        $is_attendance_based = isset($_POST['is_attendance_based']) ? 1 : 0;
        $weight = isset($_POST['weight']) && is_numeric($_POST['weight']) ? (float)$_POST['weight'] : 0.00;

        $check = $conn->prepare("SELECT * FROM grade_components WHERE class_id = :class_id AND component_name = :component_name");
        $check->execute([
            ':class_id' => $class_id,
            ':component_name' => $component_name
        ]);

        if ($check->fetch()) {
            $message = "A component with this name already exists.";
            $message_type = "danger";
        } else {
            try {
                $stmt = $conn->prepare("INSERT INTO grade_components (class_id, component_name, max_score, period, type, is_attendance_based, weight) 
                                      VALUES (:class_id, :component_name, :max_score, :period, :type, :is_attendance_based, :weight)");
                $stmt->execute([
                    ':class_id' => $class_id,
                    ':component_name' => $component_name,
                    ':max_score' => $max_score,
                    ':period' => $period,
                    ':type' => $type,
                    ':is_attendance_based' => $is_attendance_based,
                    ':weight' => $weight
                ]);

                header("Location: manage_components.php?class_id=$class_id&success=1");
                exit();
            } catch (PDOException $e) {
                $message = "Error adding component.";
                $message_type = "danger";
            }
        }
    }
}

// Get existing components
$stmt = $conn->prepare("SELECT * FROM grade_components WHERE class_id = :class_id ORDER BY period, component_name");
$stmt->execute([':class_id' => $class_id]);
$components = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group components by Period for better display
$grouped_components = [];
if (!empty($components)) {
    foreach ($components as $component) {
        $period_group = !empty($component['period']) ? htmlspecialchars($component['period']) : 'General Components';
        $grouped_components[$period_group][] = $component;
    }
} else {
    $grouped_components = [];
}

// Note for grading scheme:
// The 'weight' field for each component represents its percentage contribution.
// These weights will be used in the overall grade calculation as per your defined structure:
// - Semester Grade: Prelims (30%), Midterms (30%), Finals (40%).
// - Per Period Grade: Student Assessment/Class Standing (60%), Exam Assessment (40%).
// Ensure the sum of weights for components under "Student Assessment" types (Quiz, Assignment, etc.)
// and "Exam Assessment" (Type: Exam) for each period aligns with your desired distribution
// for the 60% and 40% categories respectively. The actual aggregation and scaling
// of these weights into final grades should be handled by your grading calculation logic elsewhere.
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UDM IntelliGrade - Manage Components</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        /* ... (your existing CSS) ... */
        body { background-color: #f5f3e1; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-wrapper { display: flex; min-height: 100vh; }
        .sidebar {
            width: 280px; background-color: #006400; color: #E7E7E7; padding: 0; 
            position: fixed; top: 0; left: 0; height: 100vh; z-index: 1030;
            overflow-y: auto; transition: width 0.3s ease; display: flex; flex-direction: column;
        }
        .sidebar-header {
            padding: 1rem; border-bottom: 1px solid #008000; 
            display: flex; align-items: center; justify-content: flex-start;
            min-height: 70px; background-color: #004d00;
        }
        .logo-image { max-height: 40px; }
        .logo-text { overflow: hidden; }
        .logo-text h5.uni-name { margin: 0; font-size: 0.9rem; font-weight: 600; color: #FFFFFF; line-height: 1.1; white-space: nowrap; }
        .logo-text p.tagline { margin: 0; font-size: 0.7rem; font-weight: 300; color: #E7E7E7; line-height: 1; white-space: nowrap; }
        .sidebar .nav-menu { padding: 1rem; flex-grow: 1; display: flex; flex-direction: column; }
        .sidebar .nav-link {
            color: #E7E7E7; padding: 0.85rem 1.25rem; font-size: 0.95rem;
            border-radius: 0.3rem; margin-bottom: 0.25rem;
            transition: background-color 0.2s ease, color 0.2s ease;
            display: flex; align-items: center; white-space: nowrap;
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: #FFFFFF; background-color: #008000; }
        .sidebar .nav-link .bi { margin-right: 0.85rem; font-size: 1.1rem; vertical-align: middle; width: 20px; text-align: center; }
        .sidebar .nav-link span { flex-grow: 1; overflow: hidden; text-overflow: ellipsis; }
        .sidebar .logout-item { margin-top: auto; }
        .sidebar .logout-item hr { border-color: #008000; margin-top: 1rem; margin-bottom:1rem; }
        .content-area {
            margin-left: 280px; flex-grow: 1; padding: 2.5rem;
            width: calc(100% - 280px); transition: margin-left 0.3s ease, width 0.3s ease;
        }
        .page-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 2.5rem; padding-bottom: 1.25rem; border-bottom: 1px solid #d6d0b8;
        }
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
        .form-label { color: #006400; font-weight: 500; }
        .form-control:focus, .form-select:focus { border-color: #008000; box-shadow: 0 0 0 0.25rem rgba(0, 100, 0, 0.25); }
        .table { background-color: #ffffff; border-radius: 0.375rem; overflow: hidden; }
        .table thead { background-color: #e9e5d0; color: #006400; }
        .table th { font-weight: 500; border-bottom-width: 1px; }
        .table td, .table th { padding: 0.75rem 1rem; vertical-align: middle; }
        .category-header { background-color: #f3f0e0; font-weight: 500; color: #006400; }

        /* Responsive adjustments */
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
            .page-header h2 { font-size: 1.5rem; margin-bottom: 1rem; }
            .page-header .btn { width: 100%; margin-top: 0.5rem; }
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
            <img src="../public/assets/img/udm_logo.png" alt="UDM Logo" class="logo-image me-2">
            <div class="logo-text">
                <h5 class="uni-name mb-0">UNIVERSIDAD DE MANILA</h5>
                <p class="tagline mb-0">Former City College of Manila</p>
            </div>
        </div>
        <ul class="nav flex-column nav-menu">
            <li class="nav-item">
                <a class="nav-link" href="../public/dashboard.php">
                    <i class="bi bi-grid-1x2-fill"></i> <span>Dashboard</span>
                </a>
            </li>
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
            <li class="nav-item logout-item"> 
                <hr>
                <a href="#" class="nav-link" data-bs-toggle="modal" data-bs-target="#logoutModal">
                    <i class="bi bi-box-arrow-right"></i> <span>Logout</span>
                </a>
            </li>
        </ul>
    </nav>

    <main class="content-area">
        <header class="page-header">
            <div>
                <h2>Manage Grade Components</h2>
                <p class="text-muted mb-0">
                    <i class="bi bi-book me-1"></i> <?= htmlspecialchars($class_details['subject_name']) ?> - 
                    <i class="bi bi-people me-1"></i> <?= htmlspecialchars($class_details['section_name']) ?>
                </p>
            </div>
            <div>
                <a href="../teacher/your_classes.php" class="btn btn-outline-secondary">
                    <i class="bi bi-person-workspace"></i> Your Classes
                </a>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="alert alert-<?= htmlspecialchars($message_type ?: 'danger') ?> alert-dismissible fade show" role="alert">
                <i class="bi bi-<?= ($message_type ?? '') === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?> me-2"></i> <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-lg-5 mb-4">
                <div class="card shadow-sm">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-plus-circle-fill me-2"></i> Add New Component
                    </div>
                    <div class="card-body">
                        <form method="post" class="needs-validation" novalidate>
                            <div class="mb-3">
                                <label for="component_name" class="form-label">Component Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="component_name" name="component_name" 
                                       placeholder="e.g. Midterm Exam, Quiz 1, Assignment 3" required>
                                <div class="invalid-feedback">Please provide a component name.</div>
                            </div>
                            <div class="mb-3">
                                <label for="period" class="form-label">Period</label>
                                <select class="form-select" id="period" name="period">
                                    <option value="">Select Period</option>
                                    <option value="Preliminary">Prelim</option>
                                    <option value="Mid-Term">Midterm</option>
                                    <option value="Pre-Final">Final</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="type" class="form-label">Type</label>
                                <select class="form-select" id="type" name="type">
                                    <option value="">Select Type</option>
                                    <option value="Quiz">Quiz</option>
                                    <option value="Exam">Exam (Major exam for the period)</option>
                                    <option value="Assignment">Assignment/Report</option>
                                    <option value="Project">Project/Group Work</option>
                                    <option value="Recitation">Recitation</option>
                                    <option value="Participation">Participation/Attendance</option>
                                    <option value="Other">Other</option>
                                </select>
                                <div class="form-text text-muted"><i class="bi bi-info-circle-fill me-1"></i>
                                    "Exam" type components typically contribute to the 40% Exam Assessment. Others contribute to the 60% Student Assessment.
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="max_score" class="form-label">Max Score <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="max_score" name="max_score" 
                                       min="1" max="1000" value="100" required> <div class="form-text text-muted"><i class="bi bi-info-circle-fill me-1"></i> Maximum points possible for this component.</div>
                                <div class="invalid-feedback">Please provide a valid total score (minimum 1).</div>
                            </div>
                            <div class="mb-3">
                                <label for="weight" class="form-label">Weight (%) <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" class="form-control" id="weight" name="weight" 
                                       min="0" max="100" placeholder="e.g. 10 or 12.5" required>
                                <div class="form-text text-muted"><i class="bi bi-info-circle-fill me-1"></i> Percentage weight of this component within its assessment category for the period.</div>
                                <div class="invalid-feedback">Please provide a valid weight (0-100).</div>
                            </div>
                            <div class="mb-4 form-check">
                                <input type="checkbox" class="form-check-input" id="is_attendance_based" name="is_attendance_based">
                                <label class="form-check-label" for="is_attendance_based">Attendance-based component</label>
                                <div class="form-text">Check this if this component is based on attendance/participation.</div>
                            </div>
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Add Component</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-7">
                <div class="card shadow-sm">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <div><i class="bi bi-list-check me-2"></i> Existing Components</div>
                         <span class="badge bg-primary rounded-pill"><?= count($components) ?> Total Components</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($components)): ?>
                            <div class="text-center p-4">
                                <i class="bi bi-clipboard-x text-muted" style="font-size: 2rem;"></i>
                                <p class="text-muted mt-2">No grade components added yet for this class.</p>
                                <p class="text-muted small">Add your first component using the form.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover table-striped mb-0">
                                    <thead>
                                        <tr>
                                            <th>Component Name</th>
                                            <th>Type</th>
                                            <th>Max Score</th>
                                            <th>Weight (%)</th> <th>Attendance</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($grouped_components as $period_group => $group_components): ?>
                                            <tr class="category-header">
                                                <td colspan="6"> 
                                                    <i class="bi bi-bookmark-fill me-2"></i><?= htmlspecialchars($period_group) ?>
                                                    <span class="badge bg-secondary rounded-pill ms-2"><?= count($group_components) ?></span>
                                                </td>
                                            </tr>
                                            <?php foreach ($group_components as $component): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($component['component_name']) ?></td>
                                                    <td><?= htmlspecialchars($component['type'] ?? 'N/A') ?></td>
                                                    <td><?= htmlspecialchars($component['max_score']) ?> pts</td>
                                                    <td><?= htmlspecialchars(number_format((float)($component['weight'] ?? 0), 2)) ?>%</td>
                                                    <td>
                                                        <?php if ($component['is_attendance_based']): ?>
                                                            <span class="badge bg-success"><i class="bi bi-check-lg"></i> Yes</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary"><i class="bi bi-x-lg"></i> No</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group btn-group-sm">
                                                           <button type="button" class="btn btn-outline-primary edit-btn" 
        data-bs-toggle="modal" data-bs-target="#editComponentModal"
        data-id="<?= $component['component_id'] ?>"
        data-name="<?= htmlspecialchars($component['component_name']) ?>"
        data-period="<?= htmlspecialchars($component['period']) ?>"
        data-type="<?= htmlspecialchars($component['type']) ?>"
        data-max-score="<?= $component['max_score'] ?>"
        data-attendance="<?= $component['is_attendance_based'] ?>"
        data-weight="<?= htmlspecialchars(number_format((float)($component['weight'] ?? 0), 2)) ?>"> 
                                                                <i class="bi bi-pencil-square"></i>
                                                            </button>
                                                            <button type="button" class="btn btn-outline-danger delete-btn" 
                                                               data-bs-toggle="modal" data-bs-target="#deleteComponentModal"
                                                               data-component-id="<?= $component['component_id'] ?>"
                                                               data-component-name="<?= htmlspecialchars($component['component_name']) ?>"
                                                               data-component-type="<?= htmlspecialchars($component['type'] ?? 'N/A') ?>"
                                                               data-component-period="<?= htmlspecialchars($component['period'] ?? 'General') ?>">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>
                                    </tbody>

                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                     <div class="card-footer text-muted small">
                        <i class="bi bi-info-circle"></i> 
                        Component weights are used to calculate period grades (Prelim, Midterm, Final) based on your school's grading policy (e.g., Class Standing 60%, Periodical Exam 40%). Ensure weights within each period and category are set appropriately.
                    </div>
                </div>
            </div>
        </div>

        <footer class="footer text-center">
            &copy; <?= date('Y') ?> Universidad De Manila - IntelliGrade System. All rights reserved.
        </footer>
    </main>
</div>

<div class="modal fade" id="editComponentModal" tabindex="-1" aria-labelledby="editComponentModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editComponentModalLabel"><i class="bi bi-pencil-fill me-2"></i>Edit Component</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="editComponentForm" method="post" class="needs-validation" novalidate>
                    <input type="hidden" id="edit_component_id" name="edit_component_id">
                    <div class="mb-3">
                        <label for="edit_component_name" class="form-label">Component Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_component_name" name="edit_component_name" required>
                        <div class="invalid-feedback">Please provide a component name.</div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_period" class="form-label">Period</label>
                        <select class="form-select" id="edit_period" name="edit_period">
                            <option value="">Select Period</option>
                            <option value="Preliminary">Prelim</option>
                            <option value="Mid-Term">Midterm</option>
                            <option value="Pre-Final">Final</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_type" class="form-label">Type</label>
                        <select class="form-select" id="edit_type" name="edit_type">
                             <option value="">Select Type</option>
                             <option value="Quiz">Quiz</option>
                             <option value="Exam">Exam (Major exam for the period)</option>
                             <option value="Assignment">Assignment/Report</option>
                             <option value="Project">Project/Group Work</option>
                             <option value="Recitation">Recitation</option>
                             <option value="Participation">Participation/Attendance</option>
                             <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_max_score" class="form-label">Max Score <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="edit_max_score" name="edit_max_score" min="1" max="1000" required>
                        <div class="invalid-feedback">Please provide a valid score (minimum 1).</div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_weight" class="form-label">Weight (%) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" class="form-control" id="edit_weight" name="edit_weight" 
                               min="0" max="100" required>
                        <div class="invalid-feedback">Please provide a valid weight (0-100).</div>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="edit_is_attendance_based" name="edit_is_attendance_based">
                        <label class="form-check-label" for="edit_is_attendance_based">Attendance-based component</label>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Changes</button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteComponentModal" tabindex="-1" aria-labelledby="deleteComponentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="deleteComponentModalLabel">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Delete
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <i class="bi bi-exclamation-triangle text-danger" style="font-size: 3rem;"></i>
                </div>
                <p class="text-center mb-2"><strong>Are you sure you want to delete this component?</strong></p>
                <p class="text-muted text-center mb-0" id="deleteComponentDetails"></p>
                <div class="alert alert-warning mt-3" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <strong>Warning:</strong> This action cannot be undone. If this component has associated student grades, they will also be affected.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i>Cancel
                </button>
                <a href="#" class="btn btn-danger" id="confirmDeleteBtn">
                    <i class="bi bi-trash me-1"></i>Delete Component
                </a>
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


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../public/js/logout-handler.js"></script>
<script>
    (function () {
        'use strict';
        var forms = document.querySelectorAll('.needs-validation');
        Array.prototype.slice.call(forms).forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                form.classList.add('was-validated');
            }, false);
        });
    })();
    
    document.addEventListener('DOMContentLoaded', function() {
        const editModal = document.getElementById('editComponentModal');
        const deleteModal = document.getElementById('deleteComponentModal');
        
        // Edit Modal functionality
        if (editModal) {
            editModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                const componentId = button.getAttribute('data-id');
                const componentName = button.getAttribute('data-name');
                const componentPeriod = button.getAttribute('data-period');
                const componentType = button.getAttribute('data-type');
                const componentMaxScore = button.getAttribute('data-max-score');
                const componentAttendance = button.getAttribute('data-attendance') === '1';
                // New: Get weight from data attribute
                const componentWeight = button.getAttribute('data-weight'); 
                
                const modalForm = editModal.querySelector('#editComponentForm');
                modalForm.querySelector('#edit_component_id').value = componentId;
                modalForm.querySelector('#edit_component_name').value = componentName;
                
                const periodSelect = modalForm.querySelector('#edit_period');
if (componentPeriod) {
    periodSelect.value = componentPeriod;
} else {
    periodSelect.value = "";
}

const typeSelect = modalForm.querySelector('#edit_type');
if (componentType) {
    typeSelect.value = componentType;
} else {
    typeSelect.value = "";
}
                
                modalForm.querySelector('#edit_max_score').value = componentMaxScore;
                modalForm.querySelector('#edit_is_attendance_based').checked = componentAttendance;
                // New: Populate weight field
                modalForm.querySelector('#edit_weight').value = componentWeight; 
            });
        }

        // Delete Modal functionality
        if (deleteModal) {
            deleteModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                const componentId = button.getAttribute('data-component-id');
                const componentName = button.getAttribute('data-component-name');
                const componentType = button.getAttribute('data-component-type');
                const componentPeriod = button.getAttribute('data-component-period');
                
                // Populate modal with component details
                const detailsElement = deleteModal.querySelector('#deleteComponentDetails');
                detailsElement.textContent = `"${componentName}" (${componentType}) - ${componentPeriod}`;
                
                // Set the delete URL for the confirm button
                const confirmBtn = deleteModal.querySelector('#confirmDeleteBtn');
                confirmBtn.href = `?class_id=<?= $class_id ?>&delete_id=${componentId}`;
            });
        }

        // Clear form validation on modal close
        if (editModal) {
            editModal.addEventListener('hidden.bs.modal', function () {
                const form = editModal.querySelector('#editComponentForm');
                form.classList.remove('was-validated');
                // You might want to reset form fields too if needed, e.g., form.reset();
            });
        }
        const addComponentForm = document.querySelector('.col-lg-5 .needs-validation');
        if(addComponentForm) {
            // If there was a server-side validation error for add, the form might be pre-filled.
            // If not, and you want to clear it after a successful add (which now redirects), this part is less critical.
            // However, if you didn't redirect, you'd clear it here.
        }

    });
</script>
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

    document.getElementById('logoutButton').addEventListener('click', function() {
        localStorage.removeItem(CHAT_STORAGE_KEY);
    });
});
</script>
</body>
</html>