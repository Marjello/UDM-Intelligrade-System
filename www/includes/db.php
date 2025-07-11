<?php
try {
    // SQLite database file path
    $dbFile = __DIR__ . '/../database/udm_class_record_db.db';
    
    // Create connection to SQLite database
    $conn = new PDO('sqlite:' . $dbFile);
    
    // Set error mode to exception
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Enable foreign keys
    $conn->exec('PRAGMA foreign_keys = ON;');
    
} catch(PDOException $e) {
    // Log the error
    error_log("Database Connection Error: " . $e->getMessage());
    
    // Return error response
    header('Content-Type: application/json');
    echo json_encode(['error' => "Database connection failed. Please try again later."]);
    exit();
} 