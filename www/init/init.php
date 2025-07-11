<?php

ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_error.log');
error_reporting(E_ALL);

try {
    // Define path to your SQLite DB
    $db_file = __DIR__ . '/db/udm_class_record_db.db';

    // Ensure the db folder exists
    $db_dir = dirname($db_file);
    if (!is_dir($db_dir)) {
        mkdir($db_dir, 0777, true);
    }

    // Connect to SQLite
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Check if schema already exists by checking for 'teachers' table
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='teachers'");
    $tableExists = $stmt->fetch();

    if (!$tableExists) {
        // Load and apply schema if needed
        $schemaFile = __DIR__ . '/db/schema.db';
        if (file_exists($schemaFile)) {
            $sql = file_get_contents($schemaFile);

            // Split SQL statements by semicolon
            $queries = explode(';', $sql);
            foreach ($queries as $query) {
                $query = trim($query);
                // Skip empty queries and unsupported statements
                if ($query && stripos($query, 'CREATE DATABASE') !== 0 && stripos($query, 'USE ') !== 0) {
                    error_log("Executing: " . $query); // Log for debug
                    try {
                        $pdo->exec($query);
                    } catch (PDOException $e) {
                        error_log("Error executing SQL query: " . $query . " - " . $e->getMessage());
                        throw $e; // Re-throw to stop execution
                    }
                }
            }
        } else {
            error_log("SQLite schema file not found: " . $schemaFile);
            throw new Exception("Database schema file missing.");
        }
    }

    // Success
    // Optional: assign $pdo to global or export to db.php
    // global $pdo;

} catch (PDOException $e) {
    error_log("Database setup failed: " . $e->getMessage());
    exit("Database initialization failed.");
} catch (Exception $e) {
    error_log("Application setup failed: " . $e->getMessage());
    exit("Application initialization failed: " . $e->getMessage());
}
?>
