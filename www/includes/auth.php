<?php
/**
 * Authentication functions for the UDM Class Record System
 */

// Make sure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if a user is logged in
 * 
 * @return bool True if user is logged in, false otherwise
 */
function isLoggedIn() {
    return isset($_SESSION['teacher_id']) && !empty($_SESSION['teacher_id']);
}

/**
 * Log a user in and set their session variables
 * 
 * @param array $user User data from database
 * @return void
 */
function loginUser($user) {
    $_SESSION['teacher_id'] = $user['teacher_id'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['logged_in'] = true;
}

/**
 * Log out the current user
 * 
 * @return void
 */
function logoutUser() {
    // Unset all session variables
    $_SESSION = array();
    
    // Delete the session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    // Destroy the session
    session_destroy();
}