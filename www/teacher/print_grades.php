<?php
require_once '../config/db.php';
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    header("Location: ../public/login.php");
    exit();
}

$teacher_id = $_SESSION['teacher_id'];
$class_id = (int)($_GET['class_id'] ?? 0);

if ($class_id === 0) {
    exit("Error: No class ID provided.");
}

// Fetch class info
$stmt = $conn->prepare("SELECT c.*, s.subject_name, sec.section_name
                        FROM classes c
                        JOIN subjects s ON c.subject_id = s.subject_id
                        JOIN sections sec ON c.section_id = sec.section_id
                        WHERE c.class_id = :class_id AND c.teacher_id = :teacher_id");
$stmt->bindValue(':class_id', $class_id, PDO::PARAM_INT);
$stmt->bindValue(':teacher_id', $teacher_id, PDO::PARAM_INT);
$stmt->execute();
$class = $stmt->fetch(PDO::FETCH_ASSOC);
$stmt->closeCursor();

if (!$class) {
    exit("Error: Class not found or access denied.");
}

// Fetch enrolled students
$stmt = $conn->prepare("SELECT e.enrollment_id, s.student_number, s.first_name, s.last_name
                        FROM enrollments e 
                        JOIN students s ON s.student_id = e.student_id
                        WHERE e.class_id = :class_id 
                        ORDER BY s.last_name, s.first_name");
$stmt->bindValue(':class_id', $class_id, PDO::PARAM_INT);
$stmt->execute();
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt->closeCursor();

// Fetch grade components
$stmt = $conn->prepare("SELECT component_id, component_name, max_score, period, is_attendance_based 
                        FROM grade_components 
                        WHERE class_id = :class_id 
                        ORDER BY period, component_name");
$stmt->bindValue(':class_id', $class_id, PDO::PARAM_INT);
$stmt->execute();
$components = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt->closeCursor();

// Group components by period
$grouped_components = [];
foreach ($components as $component) {
    $period = $component['period'] ?? 'Other';
    $grouped_components[$period][] = $component;
}

// Fetch existing grades
$existing_grades = [];
if (!empty($students) && !empty($components)) {
    $stmt = $conn->prepare("SELECT sg.enrollment_id, sg.component_id, sg.score, sg.attendance_status
                            FROM student_grades sg
                            JOIN enrollments e ON sg.enrollment_id = e.enrollment_id
                            WHERE e.class_id = :class_id");
    $stmt->bindValue(':class_id', $class_id, PDO::PARAM_INT);
    $stmt->execute();
    while ($grade = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (isset($grade['score'])) {
            $existing_grades[$grade['enrollment_id']][$grade['component_id']] = $grade['score'];
        } elseif (isset($grade['attendance_status'])) {
            $existing_grades[$grade['enrollment_id']][$grade['component_id']] = $grade['attendance_status'];
        }
    }
    $stmt->closeCursor();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Grades - <?= htmlspecialchars($class['subject_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print {
            body { margin: 0; padding: 15px; }
            .no-print { display: none !important; }
            .table { font-size: 12px; }
            .table th, .table td { padding: 4px; }
            .page-break { page-break-after: always; }
        }
        body { font-family: Arial, sans-serif; }
        .header { text-align: center; margin-bottom: 20px; }
        .header h1 { font-size: 24px; margin: 0; }
        .header p { font-size: 14px; margin: 5px 0; }
        .table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .table th, .table td { border: 1px solid #ddd; padding: 8px; }
        .table th { background-color: #f8f9fa; }
        .period-header { background-color: #e9ecef; font-weight: bold; }
        .student-info { width: 200px; }
        .grade-cell { text-align: center; width: 80px; }
        .footer { text-align: center; margin-top: 30px; font-size: 12px; }
        .signature-line { margin-top: 50px; border-top: 1px solid #000; width: 200px; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="no-print mb-3">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="bi bi-printer"></i> Print
            </button>
            <button onclick="window.close()" class="btn btn-secondary">
                <i class="bi bi-x-circle"></i> Close
            </button>
        </div>

        <div class="header">
            <h1>UNIVERSIDAD DE MANILA</h1>
            <p>Former City College of Manila</p>
            <h2><?= htmlspecialchars($class['subject_name']) ?></h2>
            <p>Section: <?= htmlspecialchars($class['section_name']) ?></p>
            <p>Academic Year: <?= htmlspecialchars($class['academic_year']) ?></p>
        </div>

        <table class="table table-bordered">
            <thead>
                <tr>
                    <th rowspan="2" class="student-info">Student Name</th>
                    <?php foreach ($grouped_components as $period => $period_components): ?>
                        <th colspan="<?= count($period_components) ?>" class="text-center period-header">
                            <?= htmlspecialchars($period) ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <?php foreach ($grouped_components as $period_components): ?>
                        <?php foreach ($period_components as $component): ?>
                            <th class="text-center">
                                <?= htmlspecialchars($component['component_name']) ?>
                                <br>
                                <small>Max: <?= htmlspecialchars($component['max_score']) ?></small>
                            </th>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($students as $student): ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars($student['last_name'] . ", " . $student['first_name']) ?>
                            <br>
                            <small><?= htmlspecialchars($student['student_number']) ?></small>
                        </td>
                        <?php foreach ($components as $component): ?>
                            <td class="grade-cell">
                                <?php
                                $grade = $existing_grades[$student['enrollment_id']][$component['component_id']] ?? '';
                                echo htmlspecialchars($grade);
                                ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="footer">
            <div class="row">
                <div class="col-6">
                    <div class="signature-line"></div>
                    <p>Teacher's Signature</p>
                </div>
                <div class="col-6">
                    <div class="signature-line"></div>
                    <p>Date</p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 