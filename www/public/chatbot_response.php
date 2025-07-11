<?php
header('Content-Type: application/json');

// Start the session at the very beginning of the script
session_start();

// Include the main database configuration instead of creating a new connection
require_once '../config/db.php';

// Knowledge Base for the Chatbot (remains the same)
$knowledgeBase = [
    [
        'question' => "How do I create a new class?",
        'answer' => "To create a new class, click on 'Create New Class' in the sidebar menu. You will be prompted to enter the class name, section, and academic year."
    ],
    [
        'question' => "Can I make a class?",
        'answer' => "Yes, go to the sidebar and click 'Create New Class'. Fill in the required information like class name and section."
    ],
    [
        'question' => "How do I enroll students?",
        'answer' => "You can enroll students by clicking the 'Enroll' button next to the class in the 'Your Classes' table. You will then enter each student's details or upload a CSV file."
    ],
    [
        'question' => "Can I import student lists?",
        'answer' => "Yes, when enrolling students, you have the option to upload a CSV file containing student information."
    ],
    [
        'question' => "Where can I manage grade components?",
        'answer' => "Grade components can be managed by clicking the 'Components' button for a specific class. Here, you can add, edit, or delete components and set their weight."
    ],
    [
        'question' => "Can I change the weight of a component?",
        'answer' => "Yes, go to the 'Components' section of your class and edit the weights directly."
    ],
    [
        'question' => "How do I input grades?",
        'answer' => "To input grades, click the 'Grades' button for the relevant class. The interface will adapt based on whether you're using numerical or A/NA grading."
    ],
    [
        'question' => "Can I enter grades manually?",
        'answer' => "Yes, grades can be entered manually through the 'Grades' section."
    ],
    [
        'question' => "How do I view class records?",
        'answer' => "You can view class records by clicking the 'View' button next to your desired class. This will show student data, grades, and analytics."
    ],
    [
        'question' => "Where can I see all class data?",
        'answer' => "Click the 'View' button next to your class to access a complete overview including grades and student lists."
    ],
    [
        'question' => "How do I edit a class?",
        'answer' => "To edit class details, click the 'Edit' button next to the class. You can change the class name, section, and academic year."
    ],
    [
        'question' => "Can I rename a class?",
        'answer' => "Yes, use the 'Edit' button next to the class name to rename it."
    ],
    [
        'question' => "How do I delete a class?",
        'answer' => "To delete a class, click the 'Delete' button next to it. Be careful—this action is permanent and cannot be undone!"
    ],
    [
        'question' => "Can I recover a deleted class?",
        'answer' => "No, deleted classes are permanently removed. Be sure to back up your data before deleting."
    ],
    [
        'question' => "How can I save my database?",
        'answer' => "Click the 'Save Database' button to download a backup SQL file. <br><button type='button' class='btn btn-success mt-2' id='chatSaveDatabaseButton'><i class='bi bi-download'></i> Save Database Now</button>"
    ],
    [
        'question' => "How do I back up the system?",
        'answer' => "Use the 'Save Database' button to create a backup SQL file. You can also back it up to Google Drive."
    ],
    [
        'question' => "How do I import my database?",
        'answer' => "Click the 'Import Database' button, choose your backup SQL file, and confirm. Warning: this will overwrite existing data."
    ],
    [
        'question' => "How do I use Google Drive for database backup?",
        'answer' => "Install Google Drive for Desktop, create a folder named 'classrecorddb', and save your backups there. The files will sync automatically to your Google Drive."
    ],
    [
        'question' => "What is the grading system type A/NA?",
        'answer' => "A/NA-based grading means students receive either 'Approved' or 'Not Approved' statuses instead of numerical scores."
    ],
    [
        'question' => "What is numerical grading?",
        'answer' => "Numerical grading uses numeric scores for each assessment. The system calculates the final grade based on weighted components."
    ],
    [
        'question' => "Who created this system?",
        'answer' => "The UDM IntelliGrade system was created by Dr. Leila Gano and Engr. Jonathan De Leon."
    ],
    [
        'question' => "Who developed this system?",
        'answer' => "This system was developed by Erik Josef Pallasigue and Marvin Angelo Dela Cruz."
    ],
    [
        'question' => "What is the current academic year?",
        'answer' => "The academic year is displayed with your classes in the 'Your Classes' section."
    ],
    [
        'question' => "Can I customize grade components?",
        'answer' => "Yes, each class allows you to add or modify grading components using the 'Components' button."
    ],
    [
        'question' => "How can I backup my database?",
        'answer' => "Click the 'Save Database' button to download a backup SQL file. <br><button type='button' class='btn btn-success mt-2' id='chatSaveDatabaseButton'><i class='bi bi-download'></i> Save Database Now</button>"
    ],
    [
        'question' => "How can I backup my files?",
        'answer' => "Click the 'Save Database' button to download a backup SQL file. <br><button type='button' class='btn btn-success mt-2' id='chatSaveDatabaseButton'><i class='bi bi-download'></i> Save Database Now</button>"
    ],
    [
        'question' => "Hello Isla",
        'answer' => "Hi, I'm Isla, IntelliGrade System Lecturer's Assistant. How can I help you today?"
    ],
    [
        'question' => "Hi Isla",
        'answer' => "Hi, I'm Isla, IntelliGrade System Lecturer's Assistant. How can I help you today?"
    ],
    [
        'question' => "What is IntelliGrade?",
        'answer' => "IntelliGrade is a class record management system designed to help teachers manage classes, enroll students, and input grades efficiently."
    ],
    [
        'question' => "How to Clear Chat?",
        'answer' => "Just say, 'Clear chat' or 'Reset chat', and I will clear our conversation history."
    ],
    // New "What can you do?" question
    [
        'question' => "What can this system do?",
        'answer' => "I am Isla, the IntelliGrade System Lecturer's Assistant. I can help you with:
        \n- Creating, editing, and deleting classes
        \n- Enrolling students and importing student lists
        \n- Managing grade components and their weights
        \n- Inputting and viewing grades (both numerical and A/NA)
        \n- Viewing class records and analytics
        \n- Backing up and importing your database
        \n- Setting up Google Drive for database backups
        \n- Taking and showing your notes
        \n- Showing your calendar notes"
    ],
    [
        'question' => "What are this system's capabilities?",
        'answer' => "I am Isla, the IntelliGrade System Lecturer's Assistant. I can help you with:
        \n- Creating, editing, and deleting classes
        \n- Enrolling students and importing student lists
        \n- Managing grade components and their weights
        \n- Inputting and viewing grades (both numerical and A/NA)
        \n- Viewing class records and analytics
        \n- Backing up and importing your database
        \n- Setting up Google Drive for database backups
        \n- Taking and showing your notes
        \n- Showing your calendar notes"
    ],
    [
        'question' => "Tell me what this system can do.",
        'answer' => "I am Isla, the IntelliGrade System Lecturer's Assistant. I can help you with:
        \n- Creating, editing, and deleting classes
        \n- Enrolling students and importing student lists
        \n- Managing grade components and their weights
        \n- Inputting and viewing grades (both numerical and A/NA)
        \n- Viewing class records and analytics
        \n- Backing up and importing your database
        \n- Setting up Google Drive for database backups
        \n- Taking and showing your notes
        \n- Showing your calendar notes"
    ],
    [
        'question' => "Isla, note that",
        'answer' => "Please tell me what you want to note. For example: 'Isla, note that the meeting is on Friday.'"
    ],
    [
        'question' => "Isla note that",
        'answer' => "Please tell me what you'd like to note. For example: 'Isla note that project submission is next Monday.'"
    ],
    [
        'question' => "Note that",
        'answer' => "Please tell me what you want to note. For example: 'Note that my appointment is at 2 PM tomorrow.'"
    ],
    [
        'question' => "Isla, add a note",
        'answer' => "Sure! What would you like me to add? For example: 'Isla, add a note about the upcoming exam.'"
    ],
    [
        'question' => "Isla add a note",
        'answer' => "Please tell me the note you want to add. For example: 'Isla add a note: pick up groceries after work.'"
    ],
    [
        'question' => "Add a note",
        'answer' => "What would you like me to add? For example: 'Add a note about the dentist appointment at 10 AM.'"
    ],
    [
        'question' => "Isla, remember this",
        'answer' => "I'm ready. What should I remember? For example: 'Isla, remember this: submit the report by Friday.'"
    ],
    [
        'question' => "Isla remember this",
        'answer' => "Sure! Please tell me what to remember. For example: 'Isla remember this: call Mom tonight.'"
    ],
    [
        'question' => "Remember this",
        'answer' => "Got it. What should I remember? For example: 'Remember this: check the mail before leaving.'"
    ],
    [
        'question' => "Isla, take note of this",
        'answer' => "Okay, what should I take note of? For example: 'Isla, take note of this: meeting moved to Room B.'"
    ],
    [
        'question' => "Isla take note of this",
        'answer' => "Sure, what would you like me to note? For example: 'Isla take note of this: buy flowers on Sunday.'"
    ],
    [
        'question' => "Take note of this",
        'answer' => "Sure. What should I take note of? For example: 'Take note of this: lunch with Alex at noon.'"
    ],
    [
        'question' => "Isla, write this down",
        'answer' => "Ready to write! What's the note? For example: 'Isla, write this down: project deadline is next week.'"
    ],
    [
        'question' => "Isla write this down",
        'answer' => "Sure, what should I write down? For example: 'Isla write this down: get milk after work.'"
    ],
    [
        'question' => "Write this down",
        'answer' => "Okay. Please tell me what to write. For example: 'Write this down: check inventory tomorrow.'"
    ],
    [
        'question' => "Isla, jot this down",
        'answer' => "Sure! What do you want me to jot down? For example: 'Isla, jot this down: start research on Monday.'"
    ],
    [
        'question' => "Jot this down",
        'answer' => "Sure. What's the note? For example: 'Jot this down: review the contract before Friday.'"
    ],
    [
        'question' => "Isla, make a note",
        'answer' => "Of course! What should I note? For example: 'Isla, make a note: call the electrician at 4 PM.'"
    ],
    [
        'question' => "Make a note",
        'answer' => "Please tell me what you'd like to note. For example: 'Make a note: schedule eye exam next week.'"
    ],
    [
        'question' => "Isla, save this note",
        'answer' => "Sure! What's the note you'd like me to save? For example: 'Isla, save this note: finalize the agenda.'"
    ],
    [
        'question' => "Save this note",
        'answer' => "Alright. What's the note? For example: 'Save this note: lunch meeting at 1 PM.'"
    ],
    [
        'question' => "Isla, keep this in mind",
        'answer' => "Got it. What should I keep in mind? For example: 'Isla, keep this in mind: order supplies tomorrow.'"
    ],
    [
        'question' => "Keep this in mind",
        'answer' => "Sure. What's the reminder? For example: 'Keep this in mind: follow up on the email.'"
    ],
    [
        'question' => "Isla, please note",
        'answer' => "Absolutely! What should I note? For example: 'Isla, please note: team meeting at 9 AM.'"
    ],
    [
        'question' => "Please note",
        'answer' => "Sure. Please tell me the note. For example: 'Please note: new password is updated.'"
    ],
    [
        'question' => "Isla, could you remember",
        'answer' => "Yes! What should I remember? For example: 'Isla, could you remember: book the venue.'"
    ],
    [
        'question' => "Could you remember",
        'answer' => "Sure. What would you like me to remember? For example: 'Could you remember: call Sarah at 3 PM.'"
    ],
    [
        'question' => "Isla, take this down",
        'answer' => "I'm listening! What should I take down? For example: 'Isla, take this down: budget is finalized.'"
    ],
    [
        'question' => "Take this down",
        'answer' => "Okay. What do you want me to take down? For example: 'Take this down: new office layout.'"
    ],
    [
        'question' => "Isla, record this",
        'answer' => "Ready! What would you like me to record? For example: 'Isla, record this: plan trip for July.'"
    ],
    [
        'question' => "Record this",
        'answer' => "Of course. What should I record? For example: 'Record this: buy dog food tomorrow.'"
    ],
    [
        'question' => "Isla, mark this",
        'answer' => "What would you like me to mark? For example: 'Isla, mark this: today's session was productive.'"
    ],
    [
        'question' => "Mark this",
        'answer' => "Sure. What would you like me to mark? For example: 'Mark this: water bill due Friday.'"
    ],
    [
        'question' => "Isla, I want to remember",
        'answer' => "I'm here to help! What do you want to remember? For example: 'Isla, I want to remember: keys are in the drawer.'"
    ],
    [
        'question' => "I want to remember",
        'answer' => "Sure. What would you like me to remember? For example: 'I want to remember: check thermostat settings.'"
    ],
    [
        'question' => "Isla, log this",
        'answer' => "Sure. What should I log? For example: 'Isla, log this: expenses for the month are finalized.'"
    ],
    [
        'question' => "Log this",
        'answer' => "Okay. What would you like me to log? For example: 'Log this: completed module 3 today.'"
    ],
    [
        'question' => "Show me my notes",
        'answer' => "Here are your notes: " // This will be dynamically populated
    ],
    [
        'question' => "What notes do I have?",
        'answer' => "Here are your notes: " // This will be dynamically populated
    ],
    [
        'question' => "Can you show my notes?",
        'answer' => "Here are your notes: " // This will be dynamically populated
    ],
    [
        'question' => "List all my notes",
        'answer' => "Here are your notes: " // This will be dynamically populated
    ],
    [
        'question' => "List my notes",
        'answer' => "Here are your notes: " // This will be dynamically populated
    ],
    [
        'question' => "Show all my notes",
        'answer' => "Here are your notes: " // This will be dynamically populated
    ],
    [
        'question' => "Display my notes",
        'answer' => "Here are your notes: " // This will be dynamically populated
    ],
    [
        'question' => "Get my notes",
        'answer' => "Here are your notes: " // This will be dynamically populated
    ],
    [
        'question' => "Fetch my notes",
        'answer' => "Here are your notes: " // This will be dynamically populated
    ],
    // New questions for deleting individual notes
    [
        'question' => "Delete note",
        'answer' => "Here are your notes (type the number to delete): " // Dynamically populated
    ],
    [
        'question' => "Remove note",
        'answer' => "Here are your notes (type the number to delete): " // Dynamically populated
    ],
    [
        'question' => "Delete my note",
        'answer' => "Here are your notes (type the number to delete): " // Dynamically populated
    ],
    [
        'question' => "Remove my note",
        'answer' => "Here are your notes (type the number to delete): " // Dynamically populated
    ],
    [
        'question' => "Delete a note",
        'answer' => "Here are your notes (type the number to delete): " // Dynamically populated
    ],
    [
        'question' => "Remove a note",
        'answer' => "Here are your notes (type the number to delete): " // Dynamically populated
    ],
    // New questions for deleting notes
    [
        'question' => "Delete all notes",
        'answer' => "Are you sure you want to delete all your notes? This action cannot be undone. Please type 'yes' to confirm or 'no' to cancel."
    ],
    [
        'question' => "Clear all notes",
        'answer' => "Are you sure you want to clear all your notes? This action cannot be undone. Please type 'yes' to confirm or 'no' to cancel."
    ],
    [
        'question' => "Remove all notes",
        'answer' => "Are you sure you want to remove all your notes? This action cannot be undone. Please type 'yes' to confirm or 'no' to cancel."
    ],
    [
        'question' => "Erase all notes",
        'answer' => "Are you sure you want to erase all your notes? This action cannot be undone. Please type 'yes' to confirm or 'no' to cancel."
    ],
    [
        'question' => "Delete my notes",
        'answer' => "Are you sure you want to delete all your notes? This action cannot be undone. Please type 'yes' to confirm or 'no' to cancel."
    ],
    [
        'question' => "Clear my notes",
        'answer' => "Are you sure you want to clear all your notes? This action cannot be undone. Please type 'yes' to confirm or 'no' to cancel."
    ],
    [
        'question' => "Remove my notes",
        'answer' => "Are you sure you want to remove all your notes? This action cannot be undone. Please type 'yes' to confirm or 'no' to cancel."
    ],
    [
        'question' => "Erase my notes",
        'answer' => "Are you sure you want to erase all your notes? This action cannot be undone. Please type 'yes' to confirm or 'no' to cancel."
    ],
    [
        'question' => "Thank you Isla",
        'answer' => "You're welcome! If you have any more questions or need assistance, feel free to ask."
    ],
    // New questions for fetching calendar notes
    [
        'question' => "Show me my calendar notes",
        'answer' => "Here are your calendar notes: " // Dynamically populated
    ],
    [
        'question' => "What calendar notes do I have?",
        'answer' => "Here are your calendar notes: " // Dynamically populated
    ],
    [
        'question' => "Can you show my calendar information?",
        'answer' => "Here is your calendar information: " // Dynamically populated
    ],
    [
        'question' => "Show my calendar events",
        'answer' => "Here are your calendar events: " // Dynamically populated
    ],
    [
        'question' => "What's on my calendar?",
        'answer' => "Here's what's on your calendar: " // Dynamically populated
    ],
    [
        'question' => "Fetch my calendar notes",
        'answer' => "Here are your calendar notes: " // Dynamically populated
    ],
    [
        'question' => "Give me my schedule",
        'answer' => "Here's your schedule based on calendar notes: " // Dynamically populated
    ],
    // New questions for deleting individual calendar notes
    [
        'question' => "Delete calendar note",
        'answer' => "Here are your calendar notes (type the number to delete): " // Dynamically populated
    ],
    [
        'question' => "Remove calendar note",
        'answer' => "Here are your calendar notes (type the number to delete): " // Dynamically populated
    ],
    [
        'question' => "Delete my calendar note",
        'answer' => "Here are your calendar notes (type the number to delete): " // Dynamically populated
    ],
    [
        'question' => "Remove my calendar note",
        'answer' => "Here are your calendar notes (type the number to delete): " // Dynamically populated
    ],
    [
        'question' => "Delete a calendar note",
        'answer' => "Here are your calendar notes (type the number to delete): " // Dynamically populated
    ],
    [
        'question' => "Remove a calendar note",
        'answer' => "Here are your calendar notes (type the number to delete): " // Dynamically populated
    ],
    [
        'question' => "What are your commands?",
        'answer' => "Here are my available commands (type the number to execute):\n\n1. Create a new class\n2. Enroll students\n3. Manage grade components\n4. Input grades\n5. View class records\n6. Edit a class\n7. Delete a class\n8. Save database\n9. Import database\n10. Take a note\n11. Show my notes\n12. Delete a note\n13. Show calendar notes\n14. Delete calendar note\n15. Clear chat\n\nType the number of the command you want to execute."
    ],
    [
        'question' => "List commands",
        'answer' => "Here are my available commands (type the number to execute):\n\n1. Create a new class\n2. Enroll students\n3. Manage grade components\n4. Input grades\n5. View class records\n6. Edit a class\n7. Delete a class\n8. Save database\n9. Import database\n10. Take a note\n11. Show my notes\n12. Delete a note\n13. Show calendar notes\n14. Delete calendar note\n15. Clear chat\n\nType the number of the command you want to execute."
    ],
    [
        'question' => "Show commands",
        'answer' => "Here are my available commands (type the number to execute):\n\n1. Create a new class\n2. Enroll students\n3. Manage grade components\n4. Input grades\n5. View class records\n6. Edit a class\n7. Delete a class\n8. Save database\n9. Import database\n10. Take a note\n11. Show my notes\n12. Delete a note\n13. Show calendar notes\n14. Delete calendar note\n15. Clear chat\n\nType the number of the command you want to execute."
    ],
];

// Create class_calendar_notes table if it doesn't exist in the main database
$sql_create_calendar_table = "CREATE TABLE IF NOT EXISTS class_calendar_notes (
    calendar_note_id INTEGER PRIMARY KEY AUTOINCREMENT,
    class_id INTEGER NOT NULL,
    teacher_id INTEGER NOT NULL,
    calendar_note_date DATE NOT NULL,
    calendar_note_title TEXT NOT NULL,
    calendar_note_description TEXT,
    calendar_note_type TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES teachers(teacher_id) ON DELETE CASCADE
)";
try {
    $conn->exec($sql_create_calendar_table);
    error_log("Calendar notes table check/creation completed");
} catch (PDOException $e) {
    error_log("Error creating calendar notes table: " . $e->getMessage());
}

// Create notes table if it doesn't exist in the main database
$sql_create_notes_table = "CREATE TABLE IF NOT EXISTS notes (
    note_id INTEGER PRIMARY KEY AUTOINCREMENT,
    teacher_id INTEGER NOT NULL,
    note_content TEXT NOT NULL,
    reg_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES teachers(teacher_id) ON DELETE CASCADE
)";
try {
    $conn->exec($sql_create_notes_table);
    error_log("Notes table check/creation completed");
} catch (PDOException $e) {
    error_log("Error creating notes table: " . $e->getMessage());
}

// Debug connection
error_log("Using main database connection: " . $databaseFile);
error_log("Database connection successful");

$userQuery = isset($_POST['query']) ? trim($_POST['query']) : '';
$teacherId = isset($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : 1;

$botResponse = "I'm sorry, I don't understand that question. Please try rephrasing, or ask about creating classes, managing grades, or database backups.";

// Initialize session variables if they don't exist
$_SESSION['chat_state'] = $_SESSION['chat_state'] ?? 'idle'; // 'idle', 'expecting_note', 'delete_note_by_number', 'delete_another_note', 'delete_calendar_note_by_number', 'delete_another_calendar_note', 'command_selection'
$_SESSION['temp_note_content'] = $_SESSION['temp_note_content'] ?? ''; // Buffer for multi-turn notes
$_SESSION['notes_list'] = $_SESSION['notes_list'] ?? []; // Store notes for deletion by number
$_SESSION['calendar_notes_list'] = $_SESSION['calendar_notes_list'] ?? []; // Store calendar notes for deletion by number

if (!empty($userQuery)) {
    $userQueryLower = strtolower($userQuery);
    $bestMatchScore = 0;
    $threshold = 60; // Percentage similarity threshold for general knowledge base
    $noteCommandThreshold = 75; // Higher threshold for note commands to be more precise

    error_log("User Query: " . $userQuery);
    error_log("User Query Lower: " . $userQueryLower);
    error_log("Teacher ID: " . $teacherId);
    error_log("Current Chat State: " . $_SESSION['chat_state']);
    error_log("Temp Note Content (before processing): " . $_SESSION['temp_note_content']);

    // Check if we're in command selection mode
    if ($_SESSION['chat_state'] === 'command_selection') {
        if (strtolower($userQuery) === 'done') {
            $_SESSION['chat_state'] = 'idle';
            $botResponse = "Command selection mode ended. How else can I help you?";
        } else if (is_numeric($userQuery) && $userQuery >= 1 && $userQuery <= 15) {
            $commandMap = [
                1 => "How do I create a new class?",
                2 => "How do I enroll students?",
                3 => "Where can I manage grade components?",
                4 => "How do I input grades?",
                5 => "How do I view class records?",
                6 => "How do I edit a class?",
                7 => "How do I delete a class?",
                8 => "How can I save my database?",
                9 => "How do I import my database?",
                10 => "Isla, note that",
                11 => "Show me my notes",
                12 => "Delete note",
                13 => "Show me my calendar notes",
                14 => "Delete calendar note",
                15 => "How to Clear Chat?"
            ];
            
            // Set the user query to the corresponding command
            $userQuery = $commandMap[$userQuery];
            $userQueryLower = strtolower($userQuery);
            
            // Process the command immediately
            $matchedQuestion = '';
            $bestMatchScore = 0;
            foreach ($knowledgeBase as $item) {
                $questionLower = strtolower($item['question']);
                similar_text($userQueryLower, $questionLower, $percent);

                if ($percent > $bestMatchScore && $percent >= $threshold) {
                    $bestMatchScore = $percent;
                    $botResponse = $item['answer'];
                    $matchedQuestion = $item['question'];
                }
            }
            
            // Add reminder about command selection mode
            $botResponse .= "\n\nYou're still in command selection mode. Type another number (1-15) to execute another command, or type 'done' to exit.";
        } else {
            $botResponse = "Please enter a valid number between 1 and 15 to select a command, or type 'done' to exit command selection mode.";
        }
    } else {
        // Check for command list queries
        $commandListKeywords = ["what are your commands?", "list commands", "show commands"];
        $isCommandListQuery = false;
        foreach ($commandListKeywords as $keyword) {
            similar_text($userQueryLower, strtolower($keyword), $percent);
            if ($percent >= 85) {
                $isCommandListQuery = true;
                break;
            }
        }

        if ($isCommandListQuery) {
            $_SESSION['chat_state'] = 'command_selection';
            $botResponse = "Here are my available commands (type the number to execute):\n\n1. Create a new class\n2. Enroll students\n3. Manage grade components\n4. Input grades\n5. View class records\n6. Edit a class\n7. Delete a class\n8. Save database\n9. Import database\n10. Take a note\n11. Show my notes\n12. Delete a note\n13. Show calendar notes\n14. Delete calendar note\n15. Clear chat\n\nType the number of the command you want to execute, or type 'done' to exit command selection mode.";
        } else {
            // --- Handle multi-turn note taking state ---
            if ($_SESSION['chat_state'] === 'expecting_note') {
                // Check for 'done' command (fuzzy match)
                $doneKeywords = ["done", "i'm done", "finished", "that's all", "save note, save this note, save"];
                $isDoneCommand = false;
                foreach ($doneKeywords as $doneKeyword) {
                    similar_text($userQueryLower, strtolower($doneKeyword), $percentDone);
                    if ($percentDone >= 80) { // High threshold for 'done'
                        $isDoneCommand = true;
                        break;
                    }
                }

                // Check for 'cancel' command (fuzzy match)
                $cancelKeywords = ["cancel", "never mind", "stop note"];
                $isCancelCommand = false;
                foreach ($cancelKeywords as $cancelKeyword) {
                    similar_text($userQueryLower, strtolower($cancelKeyword), $percentCancel);
                    if ($percentCancel >= 80) { // High threshold for 'cancel'
                        $isCancelCommand = true;
                        break;
                    }
                }

                if ($isDoneCommand) {
                    if (!empty($_SESSION['temp_note_content'])) {
                        $noteToSave = trim($_SESSION['temp_note_content']);
                        try {
                        $stmt = $conn->prepare("INSERT INTO notes (teacher_id, note_content) VALUES (?, ?)");
                            $stmt->execute([$teacherId, $noteToSave]);
                            $botResponse = "I've saved your note: \"" . htmlspecialchars($noteToSave) . "\" for you.";
                        } catch (PDOException $e) {
                            $botResponse = "Sorry, I couldn't save your note due to a database error.";
                            error_log("Error saving multi-turn note: " . $e->getMessage());
                        }
                    } else {
                        $botResponse = "You didn't type anything for the note. Note session ended.";
                    }
                    // Reset state
                    $_SESSION['chat_state'] = 'idle';
                    $_SESSION['temp_note_content'] = '';

                } elseif ($isCancelCommand) {
                    $_SESSION['chat_state'] = 'idle';
                    $_SESSION['temp_note_content'] = '';
                    $botResponse = "Okay, I've cancelled the note-taking. What else can I help you with?";
                } else {
                    // User is continuing to type the note
                    // Append with a space to separate words from different turns
                    $_SESSION['temp_note_content'] .= ($userQuery . " ");
                    $botResponse = "Okay, continue with your note, or type 'done' to save it. You can also say 'cancel' to stop.";
                }
            }
            // --- Handle delete note by number state ---
            else if ($_SESSION['chat_state'] === 'delete_note_by_number') {
                if (is_numeric($userQuery) && $userQuery > 0) {
                    $noteNumber = (int)$userQuery;
                    if (isset($_SESSION['notes_list'][$noteNumber - 1])) {
                        $noteToDelete = $_SESSION['notes_list'][$noteNumber - 1];
                        try {
                            $stmt = $conn->prepare("DELETE FROM notes WHERE note_id = ? AND teacher_id = ?");
                            $stmt->execute([$noteToDelete['note_id'], $teacherId]);
                            if ($stmt->rowCount() > 0) {
                                $botResponse = "Note #{$noteNumber} has been successfully deleted.";
                                
                                // Remove the deleted note from the session list
                                unset($_SESSION['notes_list'][$noteNumber - 1]);
                                $_SESSION['notes_list'] = array_values($_SESSION['notes_list']); // Re-index array
                                
                                // Check if there are more notes to delete
                                if (count($_SESSION['notes_list']) > 0) {
                                    $_SESSION['chat_state'] = 'delete_another_note';
                                    $botResponse .= "\n\nDelete another note? (yes/no)";
                                } else {
                                    $botResponse .= "\n\nNo more notes to delete.";
                                    $_SESSION['chat_state'] = 'idle'; // Reset state after action
                                    $_SESSION['notes_list'] = []; // Clear the stored list
                                }
                            } else {
                                $botResponse = "Note #{$noteNumber} was not found or you don't have permission to delete it.";
                                $_SESSION['chat_state'] = 'idle'; // Reset state after action
                                $_SESSION['notes_list'] = []; // Clear the stored list
                            }
                        } catch (PDOException $e) {
                            $botResponse = "Sorry, I couldn't delete the note due to a database error.";
                            error_log("Error deleting note: " . $e->getMessage());
                            $_SESSION['chat_state'] = 'idle'; // Reset state after action
                            $_SESSION['notes_list'] = []; // Clear the stored list
                        }
                    } else {
                        $botResponse = "Invalid note number. Please enter a valid number from the list above.";
                    }
                } elseif (strtolower($userQuery) === 'cancel' || strtolower($userQuery) === 'no') {
                    $botResponse = "Okay, I've cancelled the deletion.";
                    $_SESSION['chat_state'] = 'idle'; // Reset state after cancellation
                    $_SESSION['notes_list'] = []; // Clear the stored list
                } else {
                    $botResponse = "Please enter a valid number from the list above, or type 'cancel' to stop.";
                }
            }
            // --- Handle delete another note state ---
            else if ($_SESSION['chat_state'] === 'delete_another_note') {
                if (strtolower($userQuery) === 'yes' || strtolower($userQuery) === 'y') {
                    // Show the updated list of remaining notes
                    if (count($_SESSION['notes_list']) > 0) {
                        $_SESSION['chat_state'] = 'delete_note_by_number';
                        $notesText = "Here are your remaining notes (type the number to delete):\n\n";
                        $counter = 1;
                        
                        foreach($_SESSION['notes_list'] as $note) {
                            $notesText .= $counter . ". " . htmlspecialchars($note['note_content']) . "\n";
                            $counter++;
                        }
                        $notesText .= "Type the number of the note you want to delete, or 'cancel' to stop.";
                        $botResponse = $notesText;
                    } else {
                        $botResponse = "No more notes to delete.";
                        $_SESSION['chat_state'] = 'idle'; // Reset state
                        $_SESSION['notes_list'] = []; // Clear the stored list
                    }
                } elseif (strtolower($userQuery) === 'no' || strtolower($userQuery) === 'n') {
                    $botResponse = "Okay, note deletion completed.";
                    $_SESSION['chat_state'] = 'idle'; // Reset state after completion
                    $_SESSION['notes_list'] = []; // Clear the stored list
                } else {
                    $botResponse = "Please say 'yes' to delete another note or 'no' to finish.";
                }
            }
            // --- Handle delete calendar note by number state ---
            else if ($_SESSION['chat_state'] === 'delete_calendar_note_by_number') {
                if (is_numeric($userQuery) && $userQuery > 0) {
                    $noteNumber = (int)$userQuery;
                    if (isset($_SESSION['calendar_notes_list'][$noteNumber - 1])) {
                        $noteToDelete = $_SESSION['calendar_notes_list'][$noteNumber - 1];
                        try {
                            $stmt = $conn->prepare("DELETE FROM class_calendar_notes WHERE calendar_note_id = ? AND teacher_id = ?");
                            $stmt->execute([$noteToDelete['calendar_note_id'], $teacherId]);
                            if ($stmt->rowCount() > 0) {
                                $botResponse = "Calendar note #{$noteNumber} has been successfully deleted.";
                                
                                // Remove the deleted note from the session list
                                unset($_SESSION['calendar_notes_list'][$noteNumber - 1]);
                                $_SESSION['calendar_notes_list'] = array_values($_SESSION['calendar_notes_list']); // Re-index array
                                
                                // Check if there are more notes to delete
                                if (count($_SESSION['calendar_notes_list']) > 0) {
                                    $_SESSION['chat_state'] = 'delete_another_calendar_note';
                                    $botResponse .= "\n\nDelete another calendar note? (yes/no)";
                                } else {
                                    $botResponse .= "\n\nNo more calendar notes to delete.";
                                    $_SESSION['chat_state'] = 'idle'; // Reset state after action
                                    $_SESSION['calendar_notes_list'] = []; // Clear the stored list
                                }
                            } else {
                                $botResponse = "Calendar note #{$noteNumber} was not found or you don't have permission to delete it.";
                                $_SESSION['chat_state'] = 'idle'; // Reset state after action
                                $_SESSION['calendar_notes_list'] = []; // Clear the stored list
                            }
                        } catch (PDOException $e) {
                            $botResponse = "Sorry, I couldn't delete the calendar note due to a database error.";
                            error_log("Error deleting calendar note: " . $e->getMessage());
                            $_SESSION['chat_state'] = 'idle'; // Reset state after action
                            $_SESSION['calendar_notes_list'] = []; // Clear the stored list
                        }
                    } else {
                        $botResponse = "Invalid note number. Please enter a valid number from the list above.";
                    }
                } elseif (strtolower($userQuery) === 'cancel' || strtolower($userQuery) === 'no') {
                    $botResponse = "Okay, I've cancelled the deletion.";
                    $_SESSION['chat_state'] = 'idle'; // Reset state after cancellation
                    $_SESSION['calendar_notes_list'] = []; // Clear the stored list
                } else {
                    $botResponse = "Please enter a valid number from the list above, or type 'cancel' to stop.";
                }
            }
            // --- Handle delete another calendar note state ---
            else if ($_SESSION['chat_state'] === 'delete_another_calendar_note') {
                if (strtolower($userQuery) === 'yes' || strtolower($userQuery) === 'y') {
                    // Show the updated list of remaining notes
                    if (count($_SESSION['calendar_notes_list']) > 0) {
                        $_SESSION['chat_state'] = 'delete_calendar_note_by_number';
                        $calendarNotesText = "Here are your remaining calendar notes (type the number to delete):\n\n";
                        $counter = 1;
                        
                        foreach($_SESSION['calendar_notes_list'] as $note) {
                            // Format the date
                            $formattedDate = "";
                            $dateTime = DateTime::createFromFormat('Y-m-d', $note["calendar_note_date"]);
                            if ($dateTime) {
                                $formattedDate = $dateTime->format('F d, Y');
                            } else {
                                $formattedDate = $note["calendar_note_date"];
                            }

                            $calendarNotesText .= $counter . ". Date: " . $formattedDate . "\n";
                            $calendarNotesText .= "   Title: " . htmlspecialchars($note["calendar_note_title"]) . "\n";
                            
                            if (!empty($note["calendar_note_description"])) {
                                $calendarNotesText .= "   Description: " . htmlspecialchars($note["calendar_note_description"]) . "\n";
                            }
                            
                            if (!empty($note["calendar_note_type"])) {
                                $calendarNotesText .= "   Type: " . htmlspecialchars($note["calendar_note_type"]) . "\n";
                            }

                            // Try to get class information if class_id exists
                            if (!empty($note["class_id"])) {
                                try {
                                    $classQuery = "SELECT class_name FROM classes WHERE class_id = :class_id";
                                    $classStmt = $conn->prepare($classQuery);
                                    $classStmt->bindValue(':class_id', $note["class_id"], PDO::PARAM_INT);
                                    $classStmt->execute();
                                    $classInfo = $classStmt->fetch(PDO::FETCH_ASSOC);
                                    
                                    if ($classInfo && !empty($classInfo["class_name"])) {
                                        $calendarNotesText .= "   Class: " . htmlspecialchars($classInfo["class_name"]) . "\n";
                                    }
                                } catch (PDOException $e) {
                                    error_log("Error fetching class info: " . $e->getMessage());
                                    // Continue without class info
                                }
                            }
                            
                            $calendarNotesText .= "\n";
                            $counter++;
                        }
                        $calendarNotesText .= "Type the number of the note you want to delete, or 'cancel' to stop.";
                        $botResponse = $calendarNotesText;
                    } else {
                        $botResponse = "No more calendar notes to delete.";
                        $_SESSION['chat_state'] = 'idle'; // Reset state
                        $_SESSION['calendar_notes_list'] = []; // Clear the stored list
                    }
                } elseif (strtolower($userQuery) === 'no' || strtolower($userQuery) === 'n') {
                    $botResponse = "Okay, calendar note deletion completed.";
                    $_SESSION['chat_state'] = 'idle'; // Reset state after completion
                    $_SESSION['calendar_notes_list'] = []; // Clear the stored list
                } else {
                    $botResponse = "Please say 'yes' to delete another note or 'no' to finish.";
                }
            }
            // --- Not in multi-turn note taking or delete confirmation state, process as initial command or general query ---
            else {
                // Original note command detection
                $noteKeywords = [
                    // Added 'isla, note' and 'note' to directly trigger multi-turn if no content follows
                    "isla, note", "note",
                    "isla, note that", "isla note that", "note that",
                    "isla, add a note", "isla add a note", "add a note",
                    "isla, remember this", "isla remember this", "remember this",
                    "isla, take note of this", "isla take note of", "isla take note", "take note of this", "take note of",
                    "isla, write this down", "isla write this down", "write this down",
                    "isla, jot this down", "isla jot this down", "jot this down",
                    "isla, make a note", "isla make a note", "make a note",
                    "isla, save this note", "isla save this note", "save this note",
                    "isla, keep this in mind", "isla keep this in mind", "keep this in mind",
                    "isla, please note", "isla please note", "please note",
                    "isla, could you remember", "isla could you remember", "could you remember",
                    "isla, take this down", "isla take this down", "take this down",
                    "isla, record this", "isla record this", "record this",
                    "isla, mark this", "isla mark this", "mark this",
                    "isla, I want to remember", "isla I want to remember", "I want to remember",
                    "isla, log this", "isla log this", "log this",
                    "isla, take a note", "isla take a note", "take a note",
                    "isla, write a note", "isla write a note", "write a note",
                    "isla, jot down a note", "isla jot down a note", "jot down a note",
                    "isla, make a note of this", "isla make a note of this", "make a note of this",
                    "isla, save a note", "isla save a note", "save a note",
                    "isla, keep a note", "isla keep a note", "keep a note",
                    "isla, remember a note", "isla remember a note", "remember a note",
                    "isla, note this", "isla note this", "note this",
                    "isla, write this", "isla write this", "write this",
                    "isla, jot this", "isla jot this", "jot this",
                    "isla, make this note", "isla make this note", "make this note",
                    "isla, take a note that", "isla take a note that", "take a note that",
                    "isla, record this note", "isla record this note", "record this note",
                    "isla, mark this note", "isla mark this note", "mark this note",
                    "isla, I want to note", "isla I want to note", "I want to note",
                    "isla, log this note", "isla log this note", "log this note",
                    "isla, take this note", "isla take this note", "take this note",
                    "isla, write this note", "isla write this note", "write this note",
                    "isla, jot this note", "isla jot this note", "jot this note",
                    "isla, make this note", "isla make this note", "make this note",
                    "isla, save this note", "isla save this note", "save this note",
                    "isla, keep this note", "isla keep this note", "keep this note",
                    "isla, remember this note", "isla remember this note", "remember this note",
                    "isla, note this down", "isla note this down", "note this down",
                    "isla, write this down", "isla write this down", "write this down",
                    "isla, jot this down", "isla jot this down", "jot this down",
                    "isla, make a note of this", "isla make a note of this", "make a note of this",
                    "isla, save a note of this", "isla save a note of this", "save a note of this",
                    "isla, keep a note of this", "isla keep a note of this", "keep a note of this",
                    "isla, remember a note of this", "isla remember a note of this", "remember a note of this",
                    "isla, note this down",
                ];

                $bestNoteMatchScore = 0;
                $bestNoteKeyword = '';

                foreach ($noteKeywords as $keyword) {
                    $lenKeyword = strlen($keyword);
                    $userQueryPrefix = substr($userQueryLower, 0, $lenKeyword);

                    similar_text($userQueryPrefix, $keyword, $percent);

                    if ($percent > $bestNoteMatchScore && $percent >= $noteCommandThreshold) {
                        $bestNoteMatchScore = $percent;
                        $bestNoteKeyword = $keyword;
                    }
                }

                if ($bestNoteMatchScore >= $noteCommandThreshold && !empty($bestNoteKeyword)) {
                    $noteContent = trim(substr($userQuery, strlen($bestNoteKeyword)));

                    if (empty($noteContent) || strlen($noteContent) < 3) {
                        $_SESSION['chat_state'] = 'expecting_note';
                        $_SESSION['temp_note_content'] = '';
                        $botResponse = "Tell me what's on your mind.";
                    } else {
                        try {
                        $stmt = $conn->prepare("INSERT INTO notes (teacher_id, note_content) VALUES (?, ?)");
                            $stmt->execute([$teacherId, $noteContent]);
                            $botResponse = "I've noted that: \"" . htmlspecialchars($noteContent) . "\" for you.";
                        } catch (PDOException $e) {
                            $botResponse = "Sorry, I couldn't save the note due to a database error.";
                            error_log("Error saving direct note: " . $e->getMessage());
                        }
                    }
                } else {
                    // General knowledge base lookup
                    $matchedQuestion = ''; // Reset matched question for general lookup
                    foreach ($knowledgeBase as $item) {
                        $questionLower = strtolower($item['question']);
                        similar_text($userQueryLower, $questionLower, $percent);

                        if ($percent > $bestMatchScore && $percent >= $threshold) {
                            $bestMatchScore = $percent;
                            $botResponse = $item['answer'];
                            $matchedQuestion = $item['question']; // Store the matched question
                        }
                    }

                    // If a "show notes" query was matched, fetch and display notes for the specific teacher
                    if (strpos($matchedQuestion, "Show me my notes") !== false ||
                        strpos($matchedQuestion, "What notes do I have?") !== false ||
                        strpos($matchedQuestion, "Can you show my notes?") !== false ||
                        strpos($matchedQuestion, "List all my notes") !== false ||
                        strpos($matchedQuestion, "List my notes") !== false ||
                        strpos($matchedQuestion, "Show all my notes") !== false ||
                        strpos($matchedQuestion, "Display my notes") !== false ||
                        strpos($matchedQuestion, "Get my notes") !== false ||
                        strpos($matchedQuestion, "Fetch my notes") !== false) {

                        try {
                            $stmt = $conn->prepare("SELECT note_content FROM notes WHERE teacher_id = ? ORDER BY reg_date DESC");
                            $stmt->execute([$teacherId]);
                            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

                            if (count($result) > 0) {
                            $notesText = "Here are your notes:\n\n";
                            $counter = 1;
                                foreach($result as $row) {
                                $notesText .= $counter . ". " . htmlspecialchars($row["note_content"]) . "\n";
                                $counter++;
                            }
                            $botResponse = $notesText;
                        } else {
                            $botResponse = "You don't have any notes yet.";
                        }
                        } catch (PDOException $e) {
                            error_log("SQL SELECT Error (personal notes): " . $e->getMessage());
                            $botResponse = "Sorry, I encountered an error while trying to retrieve your notes from the database. (SQL Error)";
                        }
                    }
                    // New logic for fetching calendar notes with class name and grouping
                    else if (strpos($matchedQuestion, "Show me my calendar notes") !== false ||
                             strpos($matchedQuestion, "What calendar notes do I have?") !== false ||
                             strpos($matchedQuestion, "Can you show my calendar information?") !== false ||
                             strpos($matchedQuestion, "Show my calendar events") !== false ||
                             strpos($matchedQuestion, "What's on my calendar?") !== false ||
                             strpos($matchedQuestion, "Fetch my calendar notes") !== false ||
                             strpos($matchedQuestion, "Give me my schedule") !== false) {

                        try {
                            // Debug the teacher_id
                            error_log("Fetching calendar notes for teacher_id: " . $teacherId);

                            // Simple direct query to get calendar notes
                            $sql = "SELECT 
                                calendar_note_id,
                                class_id,
                                teacher_id,
                                calendar_note_date,
                                calendar_note_title,
                                calendar_note_description,
                                calendar_note_type,
                                created_at
                            FROM class_calendar_notes 
                            WHERE teacher_id = :teacher_id 
                            ORDER BY calendar_note_date ASC, created_at ASC";

                            error_log("Executing query: " . $sql);
                            
                            $stmt = $conn->prepare($sql);
                            $stmt->bindValue(':teacher_id', $teacherId, PDO::PARAM_INT);
                            $stmt->execute();
                            
                            $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            error_log("Found " . count($notes) . " calendar notes");

                            if (count($notes) > 0) {
                                $calendarNotesText = "Here is your calendar information:\n\n";
                                $counter = 1;
                                
                                foreach($notes as $note) {
                                    // Format the date
                                    $formattedDate = "";
                                    $dateTime = DateTime::createFromFormat('Y-m-d', $note["calendar_note_date"]);
                                    if ($dateTime) {
                                        $formattedDate = $dateTime->format('F d, Y');
                                    } else {
                                        $formattedDate = $note["calendar_note_date"];
                                    }

                                    $calendarNotesText .= $counter . ". Date: " . $formattedDate . "\n";
                                    $calendarNotesText .= "   Title: " . htmlspecialchars($note["calendar_note_title"]) . "\n";
                                    
                                    if (!empty($note["calendar_note_description"])) {
                                        $calendarNotesText .= "   Description: " . htmlspecialchars($note["calendar_note_description"]) . "\n";
                                    }
                                    
                                    if (!empty($note["calendar_note_type"])) {
                                        $calendarNotesText .= "   Type: " . htmlspecialchars($note["calendar_note_type"]) . "\n";
                                    }

                                    // Try to get class information if class_id exists
                                    if (!empty($note["class_id"])) {
                                        try {
                                            $classQuery = "SELECT class_name FROM classes WHERE class_id = :class_id";
                                            $classStmt = $conn->prepare($classQuery);
                                            $classStmt->bindValue(':class_id', $note["class_id"], PDO::PARAM_INT);
                                            $classStmt->execute();
                                            $classInfo = $classStmt->fetch(PDO::FETCH_ASSOC);
                                            
                                            if ($classInfo && !empty($classInfo["class_name"])) {
                                                $calendarNotesText .= "   Class: " . htmlspecialchars($classInfo["class_name"]) . "\n";
                                            }
                                        } catch (PDOException $e) {
                                            error_log("Error fetching class info: " . $e->getMessage());
                                            // Continue without class info
                                        }
                                    }
                                    
                                    $calendarNotesText .= "\n";
                                    $counter++;
                                }
                                $botResponse = $calendarNotesText;
                            } else {
                                // Debug information
                                $allNotes = $conn->query("SELECT COUNT(*) as count FROM class_calendar_notes")->fetch(PDO::FETCH_ASSOC);
                                error_log("Total notes in database: " . $allNotes['count']);
                                
                                $teachers = $conn->query("SELECT DISTINCT teacher_id FROM class_calendar_notes")->fetchAll(PDO::FETCH_COLUMN);
                                error_log("Available teacher_ids: " . print_r($teachers, true));
                                
                                $botResponse = "You don't have any calendar notes yet.";
                            }
                        } catch (PDOException $e) {
                            error_log("SQL Error in calendar notes: " . $e->getMessage());
                            error_log("SQL State: " . $e->getCode());
                            error_log("Error trace: " . $e->getTraceAsString());
                            $botResponse = "Sorry, I encountered an error while trying to retrieve your calendar information. Error: " . $e->getMessage();
                        }
                    }
                    // Handle individual calendar note deletion queries
                    else if (strpos($matchedQuestion, "Delete calendar note") !== false ||
                             strpos($matchedQuestion, "Remove calendar note") !== false ||
                             strpos($matchedQuestion, "Delete my calendar note") !== false ||
                             strpos($matchedQuestion, "Remove my calendar note") !== false ||
                             strpos($matchedQuestion, "Delete a calendar note") !== false ||
                             strpos($matchedQuestion, "Remove a calendar note") !== false) {

                        try {
                            // Debug the teacher_id
                            error_log("Fetching calendar notes for deletion for teacher_id: " . $teacherId);

                            // Query to get calendar notes for deletion
                            $sql = "SELECT 
                                calendar_note_id,
                                class_id,
                                teacher_id,
                                calendar_note_date,
                                calendar_note_title,
                                calendar_note_description,
                                calendar_note_type
                            FROM class_calendar_notes 
                            WHERE teacher_id = :teacher_id 
                            ORDER BY calendar_note_date ASC, created_at ASC";

                            $stmt = $conn->prepare($sql);
                            $stmt->bindValue(':teacher_id', $teacherId, PDO::PARAM_INT);
                            $stmt->execute();
                            
                            $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            error_log("Found " . count($notes) . " calendar notes for deletion");

                            if (count($notes) > 0) {
                                // Store notes in session for deletion by number
                                $_SESSION['calendar_notes_list'] = $notes;
                                $_SESSION['chat_state'] = 'delete_calendar_note_by_number';
                                
                                $calendarNotesText = "Here are your calendar notes (type the number to delete):\n\n";
                                $counter = 1;
                                
                                foreach($notes as $note) {
                                    // Format the date
                                    $formattedDate = "";
                                    $dateTime = DateTime::createFromFormat('Y-m-d', $note["calendar_note_date"]);
                                    if ($dateTime) {
                                        $formattedDate = $dateTime->format('F d, Y');
                                    } else {
                                        $formattedDate = $note["calendar_note_date"];
                                    }

                                    $calendarNotesText .= $counter . ". Date: " . $formattedDate . "\n";
                                    $calendarNotesText .= "   Title: " . htmlspecialchars($note["calendar_note_title"]) . "\n";
                                    
                                    if (!empty($note["calendar_note_description"])) {
                                        $calendarNotesText .= "   Description: " . htmlspecialchars($note["calendar_note_description"]) . "\n";
                                    }
                                    
                                    if (!empty($note["calendar_note_type"])) {
                                        $calendarNotesText .= "   Type: " . htmlspecialchars($note["calendar_note_type"]) . "\n";
                                    }

                                    // Try to get class information if class_id exists
                                    if (!empty($note["class_id"])) {
                                        try {
                                            $classQuery = "SELECT class_name FROM classes WHERE class_id = :class_id";
                                            $classStmt = $conn->prepare($classQuery);
                                            $classStmt->bindValue(':class_id', $note["class_id"], PDO::PARAM_INT);
                                            $classStmt->execute();
                                            $classInfo = $classStmt->fetch(PDO::FETCH_ASSOC);
                                            
                                            if ($classInfo && !empty($classInfo["class_name"])) {
                                                $calendarNotesText .= "   Class: " . htmlspecialchars($classInfo["class_name"]) . "\n";
                                            }
                                        } catch (PDOException $e) {
                                            error_log("Error fetching class info: " . $e->getMessage());
                                            // Continue without class info
                                        }
                                    }
                                    
                                    $calendarNotesText .= "\n";
                                    $counter++;
                                }
                                $calendarNotesText .= "Type the number of the note you want to delete, or 'cancel' to stop.";
                                $botResponse = $calendarNotesText;
                            } else {
                                $botResponse = "You don't have any calendar notes to delete.";
                            }
                        } catch (PDOException $e) {
                            error_log("SQL Error in calendar notes deletion: " . $e->getMessage());
                            $botResponse = "Sorry, I encountered an error while trying to retrieve your calendar notes. Error: " . $e->getMessage();
                        }
                    }
                }
            }
        }
    }
}

echo json_encode(['response' => $botResponse]);

?>