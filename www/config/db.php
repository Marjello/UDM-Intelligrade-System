<?php

// config/db.php

// Define the database file path. Ensure this directory is not web-accessible.
// For example, if your web root is `www`, and `config` is inside `www`,
// then `../database/schema.db` points to `www/database/schema.db`.
$databaseFile = __DIR__ . '/../database/schema.db';

// Ensure the database directory exists
$databaseDir = dirname($databaseFile);
if (!is_dir($databaseDir)) {
    // Attempt to create the directory with read/write/execute permissions for owner, group, and others
    if (!mkdir($databaseDir, 0777, true)) {
        // Log the error and stop execution if directory creation fails
        error_log("Failed to create database directory: " . $databaseDir);
        die("Server configuration error: Database directory could not be created.");
    }
}

try {
    // Create a PDO connection to the SQLite database
    // The variable is named $conn for consistency with usage across the app
    $conn = new PDO("sqlite:" . $databaseFile);

    // Set error mode to exception for better error handling in PDO operations
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Set default fetch mode to associative arrays for easier data access
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Create the 'teachers' table if it doesn't exist.
    // Ensure column names like 'email' and 'password_hash' match your usage.
    // 'teacher_id' is for primary key, 'email' is used for password reset.
    $conn->exec("
        CREATE TABLE IF NOT EXISTS teachers (
            teacher_id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT,
            username TEXT NOT NULL UNIQUE,
            email TEXT UNIQUE, -- Added or confirmed email column for password reset
            password_hash TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // Create the 'password_resets' table if it doesn't exist.
    // This table stores temporary tokens for password reset functionality.
    $conn->exec("
        CREATE TABLE IF NOT EXISTS password_resets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            token TEXT NOT NULL UNIQUE,
            expires_at TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // Create the 'subjects' table if it doesn't exist
    $conn->exec("
        CREATE TABLE IF NOT EXISTS subjects (
            subject_id INTEGER PRIMARY KEY AUTOINCREMENT,
            subject_name TEXT NOT NULL UNIQUE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // Create the 'sections' table if it doesn't exist
    $conn->exec("
        CREATE TABLE IF NOT EXISTS sections (
            section_id INTEGER PRIMARY KEY AUTOINCREMENT,
            section_name TEXT NOT NULL UNIQUE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // Create the 'classes' table if it doesn't exist
    // Links teachers, subjects, and sections
    $conn->exec("
        CREATE TABLE IF NOT EXISTS classes (
            class_id INTEGER PRIMARY KEY AUTOINCREMENT,
            teacher_id INTEGER NOT NULL,
            subject_id INTEGER NOT NULL,
            section_id INTEGER NOT NULL,
            class_name TEXT NOT NULL,
            academic_year TEXT NOT NULL,
            semester TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (teacher_id) REFERENCES teachers(teacher_id) ON DELETE CASCADE,
            FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE RESTRICT,
            FOREIGN KEY (section_id) REFERENCES sections(section_id) ON DELETE RESTRICT,
            UNIQUE (teacher_id, subject_id, section_id, academic_year, semester) -- Prevents duplicate classes
        );
    ");

    // Create the 'students' table if it doesn't exist
    $conn->exec("
        CREATE TABLE IF NOT EXISTS students (
            student_id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_uid TEXT NOT NULL UNIQUE, -- e.g., student ID number
            first_name TEXT NOT NULL,
            middle_name TEXT,
            last_name TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // Create the 'enrollments' table if it doesn't exist
    // Links students to classes
    $conn->exec("
        CREATE TABLE IF NOT EXISTS enrollments (
            enrollment_id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL,
            class_id INTEGER NOT NULL,
            enrollment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
            FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE CASCADE,
            UNIQUE (student_id, class_id) -- A student can only be enrolled in a class once
        );
    ");

    // Create the 'grade_components' table if it doesn't exist
    // Defines grading criteria for each class
    $conn->exec("
        CREATE TABLE IF NOT EXISTS grade_components (
            component_id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER NOT NULL,
            component_name TEXT NOT NULL,
            max_score REAL, -- Max score for numerical grades, NULL for attendance
            is_attendance_based BOOLEAN NOT NULL DEFAULT 0, -- 1 for attendance, 0 for numerical
            weight REAL, -- Weight of this component in overall grade calculation (e.g., 0.20 for 20%)
            display_order INTEGER NOT NULL DEFAULT 0, -- Order for display in UI
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE CASCADE,
            UNIQUE (class_id, component_name) -- Component names must be unique within a class
        );
    ");

    // Create the 'student_grades' table if it doesn't exist
    // Stores the actual grades for each student for each component
    $conn->exec("
        CREATE TABLE IF NOT EXISTS student_grades (
            grade_id INTEGER PRIMARY KEY AUTOINCREMENT,
            enrollment_id INTEGER NOT NULL,
            component_id INTEGER, -- Keep this for numerical grades if still needed for other components
            score TEXT, -- Use TEXT for numerical values for other components
            attendance_status_prelim TEXT, -- Added: Stores 'A'/'NA' for preliminary attendance
            attendance_status_midterm TEXT, -- Added: Stores 'A'/'NA' for midterm attendance
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (enrollment_id) REFERENCES enrollments(enrollment_id) ON DELETE CASCADE,
            FOREIGN KEY (component_id) REFERENCES grade_components(component_id) ON DELETE CASCADE,
            UNIQUE (enrollment_id, component_id) -- This unique constraint still applies to non-attendance components
        );
    ");


    // Create the 'grade_history' table if it doesn't exist
    // Logs changes to student grades for auditing purposes
    $conn->exec("
        CREATE TABLE IF NOT EXISTS grade_history (
            history_id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER NOT NULL,
            enrollment_id INTEGER NOT NULL,
            component_id INTEGER NOT NULL,
            old_value TEXT,
            new_value TEXT,
            grade_type TEXT NOT NULL, -- e.g., 'numerical', 'attendance'
            changed_by_teacher_id INTEGER, -- Optional: if you want to track who changed it
            change_timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE CASCADE,
            FOREIGN KEY (enrollment_id) REFERENCES enrollments(enrollment_id) ON DELETE CASCADE,
            FOREIGN KEY (component_id) REFERENCES grade_components(component_id) ON DELETE CASCADE,
            FOREIGN KEY (changed_by_teacher_id) REFERENCES teachers(teacher_id) ON DELETE SET NULL
        );
    ");

    // Create the 'class_calendar_notes' table if it doesn't exist
    // Stores calendar notes/events for each class
    $conn->exec("
        CREATE TABLE IF NOT EXISTS class_calendar_notes (
            calendar_note_id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER NOT NULL,
            teacher_id INTEGER NOT NULL,
            calendar_note_date DATE NOT NULL,
            calendar_note_title TEXT NOT NULL,
            calendar_note_description TEXT,
            calendar_note_type TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE CASCADE,
            FOREIGN KEY (teacher_id) REFERENCES teachers(teacher_id) ON DELETE CASCADE
        );
    ");

    // Create the 'notes' table if it doesn't exist
    // Stores general notes for teachers
    $conn->exec("
        CREATE TABLE IF NOT EXISTS notes (
            note_id INTEGER PRIMARY KEY AUTOINCREMENT,
            teacher_id INTEGER NOT NULL,
            note_content TEXT NOT NULL,
            reg_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (teacher_id) REFERENCES teachers(teacher_id) ON DELETE CASCADE
        );
    ");

    // Create the 'backup_history' table if it doesn't exist
    // Logs backup and restore actions for auditing purposes
    $conn->exec("
        CREATE TABLE IF NOT EXISTS backup_history (
            id               INTEGER  PRIMARY KEY AUTOINCREMENT,
            teacher_id       INTEGER  NOT NULL,
            action_timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            action_type      TEXT     CHECK (action_type IN ('export', 'import') ) 
                                      NOT NULL,
            file_name        TEXT     NOT NULL,
            status           TEXT     CHECK (status IN ('success', 'failed') ) 
                                      NOT NULL,
            message          TEXT,
            FOREIGN KEY (teacher_id) REFERENCES teachers(teacher_id) ON DELETE CASCADE
        );
    ");

} catch (PDOException $e) {
    // Log the database connection error for server-side debugging
    error_log("Database connection error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    // Display a user-friendly error message
    die("A database connection error occurred. Please try again later.");
}

?>