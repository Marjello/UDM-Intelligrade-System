<?php
session_start();
require_once '../config/db.php'; // This file should establish a PDO connection named $conn
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    header("Location: ../public/login.php");
    exit();
}

$teacher_id = $_SESSION['teacher_id'];
$full_name = $_SESSION['full_name'] ?? 'Teacher';
$message = "";

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject_code = trim($_POST['subject_code']);
    $subject_name = trim($_POST['subject_name']);
    $section_name = trim($_POST['section_name']);
    $academic_year = trim($_POST['academic_year']);
    $semester = trim($_POST['semester']);
    $grading_type = $_POST['grading_system_type'];

    try {
        // Start a transaction for atomicity
        $conn->beginTransaction();

        // Insert subject if it doesn't exist
        $stmt = $conn->prepare("SELECT subject_id FROM subjects WHERE subject_code = ?");
        $stmt->execute([$subject_code]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            $subject_id = $result['subject_id'];
        } else {
            $insert_subject = $conn->prepare("INSERT INTO subjects (subject_code, subject_name) VALUES (?, ?)");
            if ($insert_subject->execute([$subject_code, $subject_name])) {
                $subject_id = $conn->lastInsertId(); // PDO equivalent for last inserted ID
            } else {
                throw new Exception("Error creating subject.");
            }
        }

        // Insert section if it doesn't exist (or fetch existing)
        $stmt = $conn->prepare("SELECT section_id FROM sections WHERE section_name = ? AND academic_year = ? AND semester = ?");
        $stmt->execute([$section_name, $academic_year, $semester]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            $section_id = $result['section_id'];
        } else {
            $insert_section = $conn->prepare("INSERT INTO sections (section_name, academic_year, semester) VALUES (?, ?, ?)");
            if ($insert_section->execute([$section_name, $academic_year, $semester])) {
                $section_id = $conn->lastInsertId(); // PDO equivalent for last inserted ID
            } else {
                throw new Exception("Error creating section.");
            }
        }

        // Check for existing class with same subject, section, and teacher
        $stmt = $conn->prepare("SELECT class_id FROM classes WHERE subject_id = ? AND section_id = ? AND teacher_id = ?");
        $stmt->execute([$subject_id, $section_id, $teacher_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            throw new Exception("A class with this subject and section already exists for you.");
        }

        // Insert new class
        $insert_class = $conn->prepare("INSERT INTO classes (teacher_id, subject_id, section_id, grading_system_type) VALUES (?, ?, ?, ?)");
        if ($insert_class->execute([$teacher_id, $subject_id, $section_id, $grading_type])) {
            $conn->commit(); // Commit the transaction
            $_SESSION['success_message'] = "Class created successfully!";
            header("Location: ../public/dashboard.php");
            exit();
        } else {
            throw new Exception("Error creating class.");
        }

    } catch (Exception $e) {
        $conn->rollBack(); // Rollback on error
        $_SESSION['error_message'] = $e->getMessage() . (isset($conn) && $conn->inTransaction() ? " PDO Error: " . $conn->errorInfo()[2] : "");
        header("Location: create_class.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UDM IntelliGrade - Create Class</title>
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
                <a class="nav-link active" aria-current="page" href="create_class.php">
                    <i class="bi bi-plus-square-dotted"></i> <span>Create New Class</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="your_classes.php">
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
            <h2>Create New Class</h2>
        </header>

        <?php
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

        <div class="card shadow-sm mb-4">
            <div class="card-header">
                <i class="bi bi-folder-plus me-2"></i> Class Details
            </div>
            <div class="card-body">
                <form action="create_class.php" method="POST" class="needs-validation" novalidate>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="subject_code" class="form-label">Subject Code</label>
                            <input type="text" class="form-control" id="subject_code" name="subject_code" required>
                            <div class="invalid-feedback">
                                Please provide a subject code.
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="subject_name" class="form-label">Subject Name</label>
                            <input type="text" class="form-control" id="subject_name" name="subject_name" required>
                            <div class="invalid-feedback">
                                Please provide a subject name.
                            </div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="section_name" class="form-label">Section Name</label>
                            <input type="text" class="form-control" id="section_name" name="section_name" required>
                            <div class="invalid-feedback">
                                Please provide a section name.
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="academic_year" class="form-label">Academic Year</label>
                            <input type="text" class="form-control" id="academic_year" name="academic_year" placeholder="e.g., 2023-2024" required pattern="\d{4}-\d{4}">
                            <div class="invalid-feedback">
                                Please provide a valid academic year (e.g., 2023-2024).
                            </div>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <div class="col-md-6">
                            <label for="semester" class="form-label">Semester</label>
                            <select class="form-select" id="semester" name="semester" required>
                                <option value="" selected disabled>Select Semester</option>
                                <option value="1st Semester">1st Semester</option>
                                <option value="2nd Semester">2nd Semester</option>
                                <option value="Summer">Summer</option>
                            </select>
                            <div class="invalid-feedback">
                                Please select a semester.
                            </div>
                        </div>
                         <div class="col-md-6">
                            <label for="grading_system_type" class="form-label">Grading System Type</label>
                            <select class="form-select" id="grading_system_type" name="grading_system_type" required>
                                <option value="" selected disabled>Select Grading Type</option>
                                <option value="numerical">Numerical (1-100)</option>
                                <option value="final_only_numerical">A/NA Based</option>
                            </select>
                            <div class="invalid-feedback">
                                Please select a grading system type.
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">Create Class</button>
                </form>
            </div>
        </div>

        <footer class="footer text-center">
            &copy; <?= date('Y') ?> Universidad De Manila - IntelliGrade System. All rights reserved.
        </footer>
    </main>
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
    // Form validation
    (function () {
        'use strict'
        
        // Fetch all forms we want to apply validation to
        var forms = document.querySelectorAll('.needs-validation')
        
        // Loop over them and prevent submission
        Array.prototype.slice.call(forms)
            .forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    if (!form.checkValidity()) {
                        event.preventDefault()
                        event.stopPropagation()
                    }
                    
                    form.classList.add('was-validated')
                }, false)
            })
    })()

    // Ensure Academic Year format
    document.getElementById('academic_year').addEventListener('input', function() {
        let value = this.value;
        if (value.length === 4 && !value.includes('-')) {
            this.value = value + '-';
        } else if (value.length > 9) {
            this.value = value.substring(0, 9);
        }
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