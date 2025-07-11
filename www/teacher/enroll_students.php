<?php
// Start output buffering as the very first thing to prevent "headers already sent" errors
ob_start();

session_start();
require_once '../config/db.php'; // Assumes db.php now returns a PDO connection
require_once '../includes/auth.php';

// ***** IMPORTANT: Add PhpSpreadsheet autoloader *****
// If you installed PhpSpreadsheet via Composer, this path should work.
// Adjust if your vendor directory is elsewhere.
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
// The 'use PDO;' statement is unnecessary because PDO is a global class,
// not a namespaced one. Removing it resolves the "has no effect" warning.
// use PDO; // REMOVED THIS LINE

if (!isLoggedIn()) {
    header("Location: ../public/login.php");
    exit();
}

$teacher_id = $_SESSION['teacher_id'];
$full_name = $_SESSION['full_name'] ?? 'Teacher';
$class_id = $_GET['class_id'] ?? null;

if (!$class_id) {
    $_SESSION['error_message'] = "Class ID missing. Please select a valid class.";
    header("Location: ../public/dashboard.php");
    exit();
}

// Get class information
// Changed from mysqli to PDO
$stmt = $conn->prepare("
    SELECT c.class_id, s.subject_code, s.subject_name, sec.section_name
    FROM classes c
    JOIN subjects s ON c.subject_id = s.subject_id
    JOIN sections sec ON c.section_id = sec.section_id
    WHERE c.class_id = ? AND c.teacher_id = ?
");
$stmt->execute([$class_id, $teacher_id]);
$class = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$class) { // Check if a class was found
    $_SESSION['error_message'] = "Invalid class selected or you don't have permission to access this class.";
    header("Location: ../public/dashboard.php");
    exit();
}

$message = "";
$message_type = "info"; // Default message type

// Function to process student enrollment
function processStudentData($conn, $student_number, $last_name, $first_name, $class_id, &$enrolled_count, &$error_count, &$skipped_count) {
    // Basic validation
    if (empty(trim($student_number)) || empty(trim($last_name)) || empty(trim($first_name))) {
        if (!empty(trim($student_number)) || !empty(trim($last_name)) || !empty(trim($first_name))) { // only count as error if some data was present
            $error_count++;
        }
        return;
    }

    // Sanitize inputs
    $student_number = trim($student_number);
    $last_name = trim($last_name);
    $first_name = trim($first_name);

    $student_id = null;

    // Check if student exists
    // Changed from mysqli to PDO
    $stmt_check_student = $conn->prepare("SELECT student_id FROM students WHERE student_number = ?");
    $stmt_check_student->execute([$student_number]);
    $res_student = $stmt_check_student->fetch(PDO::FETCH_ASSOC);

    if ($res_student) { // If student exists
        $student_id = $res_student['student_id'];
        // Optional: Update student's name if it differs? For now, we'll just use the existing ID.
    } else {
        // Insert student
        // Changed from mysqli to PDO
        $stmt_insert_student = $conn->prepare("INSERT INTO students (student_number, last_name, first_name) VALUES (?, ?, ?)");
        try {
            if ($stmt_insert_student->execute([$student_number, $last_name, $first_name])) {
                $student_id = $conn->lastInsertId();
            } else {
                $error_count++;
                return; // Cannot proceed if student insert fails
            }
        } catch (PDOException $e) {
            // Handle potential unique constraint violation, or other insert errors
            $error_count++;
            return;
        }
    }

    // Check if already enrolled
    // Changed from mysqli to PDO
    $stmt_check_enrollment = $conn->prepare("SELECT enrollment_id FROM enrollments WHERE student_id = ? AND class_id = ?");
    $stmt_check_enrollment->execute([$student_id, $class_id]);
    $already_enrolled = $stmt_check_enrollment->fetch(PDO::FETCH_ASSOC) !== false; // Check if any row was returned

    // Enroll student if not already enrolled
    if (!$already_enrolled) {
        // Changed from mysqli to PDO
        $stmt_enroll = $conn->prepare("INSERT INTO enrollments (student_id, class_id) VALUES (?, ?)");
        try {
            if ($stmt_enroll->execute([$student_id, $class_id])) {
                $enrolled_count++;
            } else {
                $error_count++;
            }
        } catch (PDOException $e) {
            // Handle potential unique constraint violation if a student is already enrolled in this class (though checked above)
            $error_count++;
        }
    } else {
        $skipped_count++; // Student was already enrolled
    }
}


// Handle copy-paste form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_bulk_text'])) {
    $enrolled_count = 0;
    $error_count = 0;
    $skipped_count = 0; // For already enrolled students
    $lines = explode("\n", trim($_POST['bulk_text']));

    foreach ($lines as $line) {
        $parts = preg_split('/\s+/', trim($line), 3); // Split into max 3 parts
        if (count($parts) >= 3) {
            $student_number = $parts[0];
            $last_name = $parts[1];
            $first_name = $parts[2];
            processStudentData($conn, $student_number, $last_name, $first_name, $class_id, $enrolled_count, $error_count, $skipped_count);
        } else {
            if (!empty(trim($line))) { // Count as error only if the line wasn't empty
                $error_count++;
            }
        }
    }

    if ($enrolled_count > 0) {
        $message .= "<strong>Success!</strong> Enrolled $enrolled_count new student" . ($enrolled_count > 1 ? "s" : "") . ". ";
        $message_type = "success";
    }
    if ($skipped_count > 0) {
        $message .= "Skipped $skipped_count student" . ($skipped_count > 1 ? "s" : "") . " (already enrolled). ";
        if ($message_type !== "success") $message_type = "info";
    }
    if ($error_count > 0) {
        $message .= "<strong>Warning!</strong> Failed to process or enroll $error_count student" . ($error_count > 1 ? "s" : "") . ". Please check format/data. ";
        $message_type = ($message_type === "success" || $message_type === "info") ? "warning" : "danger"; // Keep success if some were enrolled
    }
    
    if (empty($message)) {
        $message = "<strong>Note:</strong> No new students were processed or enrolled from text input.";
        $message_type = "info";
    }
}

// ***** START: Handle Excel file upload *****
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_excel_file'])) {
    $enrolled_count = 0;
    $error_count = 0;
    $skipped_count = 0;
    $excel_message_details = []; // To store detailed messages per row

    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] == UPLOAD_ERR_OK) {
        $file_tmp_path = $_FILES['excel_file']['tmp_name'];
        $file_name = $_FILES['excel_file']['name'];
        $file_size = $_FILES['excel_file']['size'];
        $file_type = $_FILES['excel_file']['type'];
        $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        $allowed_extensions = ['xls', 'xlsx', 'ods']; // Common spreadsheet extensions
        if (in_array($file_extension, $allowed_extensions)) {
            try {
                $spreadsheet = IOFactory::load($file_tmp_path);
                $sheet = $spreadsheet->getActiveSheet();
                // Start from row 2 if you expect a header row, otherwise row 1
                // For robust handling, check if header row exists or define column mapping
                $highestRow = $sheet->getHighestDataRow();

                // Expecting: Column A: Student Number, B: Last Name, C: First Name
                for ($row = 1; $row <= $highestRow; $row++) { // Assuming no header or header is also processed (can be skipped if blank)
                    $student_number = trim($sheet->getCell('A' . $row)->getValue());
                    $last_name = trim($sheet->getCell('B' . $row)->getValue());
                    $first_name = trim($sheet->getCell('C' . $row)->getValue());

                    if (empty($student_number) && empty($last_name) && empty($first_name) && $row > 1) { // Skip empty rows, but not the first if it might be data
                        continue;
                    }
                    
                    processStudentData($conn, $student_number, $last_name, $first_name, $class_id, $enrolled_count, $error_count, $skipped_count);
                }

                if ($enrolled_count > 0) {
                    $message .= "<strong>Excel Success!</strong> Enrolled $enrolled_count new student" . ($enrolled_count > 1 ? "s" : "") . ". ";
                    $message_type = "success";
                }
                if ($skipped_count > 0) {
                    $message .= "Skipped $skipped_count student" . ($skipped_count > 1 ? "s" : "") . " from Excel (already enrolled). ";
                     if ($message_type !== "success") $message_type = "info";
                }
                if ($error_count > 0) {
                    $message .= "<strong>Excel Warning!</strong> Failed to process or enroll $error_count student" . ($error_count > 1 ? "s" : "") . " from Excel. Please check file format/data. ";
                    $message_type = ($message_type === "success" || $message_type === "info") ? "warning" : "danger";
                }
                if (empty($message)) {
                     $message = "<strong>Note:</strong> No new students were processed or enrolled from the Excel file. The file might be empty, students already enrolled, or data was not in the expected format (StudentNumber, LastName, FirstName in first three columns).";
                     $message_type = "info";
                }

            } catch (Exception $e) {
                $message = "<strong>Error!</strong> Failed to read the Excel file: " . htmlspecialchars($e->getMessage());
                $message_type = "danger";
            }
        } else {
            $message = "<strong>Error!</strong> Invalid file type. Please upload an Excel file (xls, xlsx, ods).";
            $message_type = "danger";
        }
    } elseif (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] != UPLOAD_ERR_NO_FILE) {
        // Handle other upload errors
        $upload_errors = [
            UPLOAD_ERR_INI_SIZE => "The uploaded file exceeds the upload_max_filesize directive in php.ini.",
            UPLOAD_ERR_FORM_SIZE => "The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.",
            UPLOAD_ERR_PARTIAL => "The uploaded file was only partially uploaded.",
            UPLOAD_ERR_NO_TMP_DIR => "Missing a temporary folder.",
            UPLOAD_ERR_CANT_WRITE => "Failed to write file to disk.",
            UPLOAD_ERR_EXTENSION => "A PHP extension stopped the file upload."
        ];
        $error_code = $_FILES['excel_file']['error'];
        $message = "<strong>Error uploading file!</strong> " . ($upload_errors[$error_code] ?? "An unknown error occurred.");
        $message_type = "danger";
    } else if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] == UPLOAD_ERR_NO_FILE && isset($_POST['submit_excel_file'])) {
        $message = "<strong>Info:</strong> No Excel file was selected for upload.";
        $message_type = "info";
    }
}
// ***** END: Handle Excel file upload *****


// Fetch enrolled students
// Changed from mysqli to PDO
$stmt_fetch_students = $conn->prepare("
    SELECT s.student_id, s.student_number, s.last_name, s.first_name 
    FROM enrollments e 
    JOIN students s ON e.student_id = s.student_id 
    WHERE e.class_id = ?
    ORDER BY s.last_name, s.first_name
");
$stmt_fetch_students->execute([$class_id]);
$students = $stmt_fetch_students->fetchAll(PDO::FETCH_ASSOC);

// No need to explicitly close PDO connection; it closes when script ends
$conn = null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UDM IntelliGrade - Enroll Students</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
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
            margin-bottom: 2rem;
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
        
        .alert-success {
            background-color: #e7f3e7; /* Light green alert */
            border-color: #d0ffd0;
            color: #006400;
        }
        
        .alert-warning {
            background-color: #fff8e1; /* Light yellow alert */
            border-color: #ffe082;
            color: #856404;
        }
        
        .alert-info {
            background-color: #e1f5fe; /* Light blue alert */
            border-color: #b3e5fc;
            color: #0c5460;
        }
         .alert-danger { /* Added for critical errors */
            background-color: #f8d7da;
            border-color: #f5c6cb;
            color: #721c24;
        }
        
        .footer {
            padding: 1.5rem 0;
            margin-top: 2rem;
            font-size: 0.875rem;
            color: #006400; /* Dark green footer text */
            border-top: 1px solid #d6d0b8; /* Matching beige border */
        }

        /* Form specific styles */
        textarea.form-control {
            min-height: 120px;
        }
        
        .form-control:focus {
            border-color: #008000;
            box-shadow: 0 0 0 0.25rem rgba(0, 100, 0, 0.25);
        }
        
        /* Table Search/Filter */
        #studentSearch {
            border-color: #d6d0b8;
            background-color: #fcfbf7;
        }
        
        #studentSearch:focus {
            border-color: #006400;
            background-color: #ffffff;
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 2rem;
        }
        
        .empty-state i {
            font-size: 3rem;
            color: #d6d0b8;
            margin-bottom: 1rem;
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
                font-size: 1.1rem;
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
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </nav>

    <main class="content-area">
        <header class="page-header">
            <div>
                <h2>Enroll Students</h2>
                <p class="text-muted mb-0">
                    <i class="bi bi-book-fill"></i> <?= htmlspecialchars($class['subject_code']) ?> - <?= htmlspecialchars($class['subject_name']) ?> | 
                    <i class="bi bi-people-fill"></i> <?= htmlspecialchars($class['section_name']) ?>
                </p>
            </div>
            <a href="../teacher/your_classes.php" class="btn btn-outline-primary">
                <i class="bi bi-person-workspace"></i> Your Classes
            </a>
        </header>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= htmlspecialchars($message_type) ?> alert-dismissible fade show" role="alert">
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-lg-5 mb-4">
                <div class="card shadow-sm">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-clipboard-plus-fill me-2"></i> Enroll by Pasting Text
                    </div>
                    <div class="card-body">
                        <form method="post"> <div class="mb-3">
                                <label for="bulk_text" class="form-label">Paste student data (one student per line):</label>
                                <textarea class="form-control" id="bulk_text" name="bulk_text" rows="5" 
                                          placeholder="Format: StudentNumber LastName FirstName&#10;Example:&#10;2023-00123 Cruz Juan&#10;2023-00124 Santos Maria"></textarea>
                                <div class="form-text">
                                    <i class="bi bi-info-circle-fill me-1"></i> Each line: Student Number, Last Name, First Name, separated by spaces.
                                </div>
                            </div>
                            <div class="d-grid">
                                <button type="submit" name="submit_bulk_text" class="btn btn-primary">
                                    <i class="bi bi-person-plus-fill me-2"></i> Enroll from Text
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="card shadow-sm mt-4">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-file-earmark-excel-fill me-2"></i> Enroll by Excel File
                    </div>
                    <div class="card-body">
                        <form method="post" enctype="multipart/form-data"> <div class="mb-3">
                                <label for="excel_file" class="form-label">Upload Excel file (.xlsx recommended, .xls also supported):</label>
                                <input class="form-control" type="file" id="excel_file" name="excel_file" accept=".xlsx,.xls,.ods">
                                <div class="form-text">
                                    <i class="bi bi-info-circle-fill me-1"></i> File should have columns: Student Number (Col A), Last Name (Col B), First Name (Col C). Data should start from the first row or second if a header is present. <strong>Microsoft Excel Worksheet (.xlsx) is recommended.</strong>
                                </div>
                            </div>
                            <div class="d-grid">
                                <button type="submit" name="submit_excel_file" class="btn btn-success">
                                    <i class="bi bi-upload me-2"></i> Upload and Enroll from Excel
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <div class="card shadow-sm mt-4">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-question-circle-fill me-2"></i> Help & Instructions
                    </div>
                    <div class="card-body">
                        <h6 class="card-subtitle mb-2 text-muted">Pasting Text:</h6>
                        <ol class="ps-3 mb-3">
                            <li>Copy student data from your source.</li>
                            <li>Ensure data is: <code>StudentNumber LastName FirstName</code> per line.</li>
                            <li>Paste into the "Enroll by Pasting Text" area.</li>
                            <li>Click "Enroll from Text".</li>
                        </ol>
                        <h6 class="card-subtitle mb-2 text-muted">Uploading Excel File:</h6>
                        <ol class="ps-3 mb-3">
                            <li>Prepare an Excel file (.xlsx recommended, .xls also supported).</li>
                            <li>Column A: Student Number</li>
                            <li>Column B: Last Name</li>
                            <li>Column C: First Name</li>
                            <li>(Optional) First row can be a header, it will be skipped if it doesn't parse as student data.</li>
                            <li>Choose the file and click "Upload and Enroll from Excel".</li>
                        </ol>
                        <div class="alert alert-info mt-3 mb-0 p-2">
                            <small><i class="bi bi-lightbulb-fill me-1"></i> If a student number already exists in the system, their existing record will be used. Students already enrolled in this class will be skipped.</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-7">
                <div class="card shadow-sm">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <div>
                            <i class="bi bi-people-fill me-2"></i> Currently Enrolled Students
                            <span class="badge bg-success ms-2"><?= count($students) ?></span>
                        </div>
                        <div class="input-group input-group-sm" style="width: 200px;">
                            <span class="input-group-text" id="search-addon">
                                <i class="bi bi-search"></i>
                            </span>
                            <input type="text" class="form-control" id="studentSearch" placeholder="Search students..." 
                                   aria-label="Search students" aria-describedby="search-addon">
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($students)): ?>
                            <div class="empty-state p-5">
                                <i class="bi bi-people"></i>
                                <h5 class="text-muted">No Students Enrolled</h5>
                                <p class="text-muted">Use the forms on the left to enroll students in this class.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0" id="studentTable">
                                    <thead>
                                        <tr>
                                            <th scope="col" style="width: 35%">Student Number</th>
                                            <th scope="col">Full Name</th>
                                            <th scope="col" style="width: 15%" class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($students as $s): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($s['student_number']) ?></td>
                                                <td><?= htmlspecialchars($s['last_name']) ?>, <?= htmlspecialchars($s['first_name']) ?></td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                                            onclick="confirmUnenroll(<?= $s['student_id'] ?>, '<?= htmlspecialchars(addslashes($s['last_name'] . ", " . $s['first_name'])) ?>')">
                                                        <i class="bi bi-person-dash"></i> <span class="d-none d-md-inline">Unenroll</span>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    </table>
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

<div class="modal fade" id="unenrollModal" tabindex="-1" aria-labelledby="unenrollModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="unenrollModalLabel">Confirm Unenrollment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to unenroll the following student?</p>
                <p class="fw-bold" id="studentNameModal"></p> <p class="text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>This action cannot be undone. The student will lose all grade records for this class.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form id="unenrollForm" action="unenroll_student.php" method="post" style="display:inline;"> <input type="hidden" name="student_id" id="student_id_modal"> <input type="hidden" name="class_id" value="<?= htmlspecialchars($class_id) ?>">
                    <button type="submit" class="btn btn-danger">Unenroll Student</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../public/js/logout-handler.js"></script>
<script>
    // Search functionality
    const studentSearchInput = document.getElementById('studentSearch');
    if(studentSearchInput) {
        studentSearchInput.addEventListener('keyup', function() {
            let searchValue = this.value.toLowerCase();
            let table = document.getElementById('studentTable');
            if(table) {
                let rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
                
                for (let i = 0; i < rows.length; i++) {
                    let studentNumberCell = rows[i].getElementsByTagName('td')[0];
                    let fullNameCell = rows[i].getElementsByTagName('td')[1];
                    
                    if (studentNumberCell && fullNameCell) {
                        let studentNumber = studentNumberCell.textContent.toLowerCase();
                        let fullName = fullNameCell.textContent.toLowerCase();
                        if (studentNumber.includes(searchValue) || fullName.includes(searchValue)) {
                            rows[i].style.display = '';
                        } else {
                            rows[i].style.display = 'none';
                        }
                    }
                }
            }
        });
    }
    
    // Unenroll confirmation
    function confirmUnenroll(studentId, studentName) {
        const studentNameModalElement = document.getElementById('studentNameModal');
        const studentIdModalElement = document.getElementById('student_id_modal');
        
        if(studentNameModalElement) studentNameModalElement.textContent = studentName;
        if(studentIdModalElement) studentIdModalElement.value = studentId;
        
        const unenrollModal = new bootstrap.Modal(document.getElementById('unenrollModal'));
        unenrollModal.show();
    }

    // Dismiss alerts automatically after some time for better UX
    window.setTimeout(function() {
        let alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(function(alert) {
            if (bootstrap.Alert.getInstance(alert)) { // Check if already initialized
                 new bootstrap.Alert(alert).close();
            } else {
                // For alerts that might not be auto-initialized by BS5, like dynamically added ones
                // This might not be strictly necessary if they are standard BS alerts.
            }
        });
    }, 7000); // 7 seconds

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

        fetch('../public/export_db.php', {
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

        fetch('../public/dashboard.php', {
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