<?php
// Script to check backup history and session teacher_id
session_start();
require_once 'config/db.php';

echo "=== Backup History Check ===\n";

// Check session
if (isset($_SESSION['teacher_id'])) {
    $teacher_id = $_SESSION['teacher_id'];
    echo "✅ Session found. Teacher ID: $teacher_id\n";
} else {
    echo "❌ No teacher_id in session\n";
    $teacher_id = 1; // Default for testing
    echo "🔧 Using default teacher_id: $teacher_id\n";
}

// Check backup history table
try {
    // Total records
    $stmt = $conn->prepare("SELECT COUNT(*) FROM backup_history");
    $stmt->execute();
    $total_records = $stmt->fetchColumn();
    echo "📊 Total backup history records: $total_records\n";
    
    // Records for current teacher
    $stmt = $conn->prepare("SELECT COUNT(*) FROM backup_history WHERE teacher_id = ?");
    $stmt->execute([$teacher_id]);
    $teacher_records = $stmt->fetchColumn();
    echo "📊 Records for teacher $teacher_id: $teacher_records\n";
    
    // All teacher IDs in backup_history
    $stmt = $conn->prepare("SELECT DISTINCT teacher_id FROM backup_history");
    $stmt->execute();
    $teacher_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "📊 Teacher IDs in backup_history: " . implode(', ', $teacher_ids) . "\n";
    
    // Show recent records for current teacher
    if ($teacher_records > 0) {
        echo "\n📋 Recent backup history for teacher $teacher_id:\n";
        $stmt = $conn->prepare("SELECT * FROM backup_history WHERE teacher_id = ? ORDER BY action_timestamp DESC LIMIT 5");
        $stmt->execute([$teacher_id]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($records as $record) {
            echo "  - {$record['action_timestamp']} | {$record['action_type']} | {$record['file_name']} | {$record['status']}\n";
        }
    } else {
        echo "\n❌ No backup history records found for teacher $teacher_id\n";
        
        // Show some recent records from all teachers
        echo "\n📋 Recent backup history (all teachers):\n";
        $stmt = $conn->prepare("SELECT * FROM backup_history ORDER BY action_timestamp DESC LIMIT 5");
        $stmt->execute();
        $all_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($all_records as $record) {
            echo "  - Teacher {$record['teacher_id']} | {$record['action_timestamp']} | {$record['action_type']} | {$record['file_name']} | {$record['status']}\n";
        }
    }
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "\n=== Check Complete ===\n";
?> 