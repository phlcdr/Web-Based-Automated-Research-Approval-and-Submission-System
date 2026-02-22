<?php
session_start();

include_once '../config/database.php';
include_once '../includes/functions.php';

// Log the logout event
if (isset($_SESSION['user_id'])) {
    log_security_event($conn, 'logout', 'User logged out', $_SESSION['user_id']);
    
    // Clear session expiration from database
    try {
        $stmt = $conn->prepare("UPDATE users SET session_expires_at = NULL WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
    } catch (PDOException $e) {
        error_log("Error clearing session from database: " . $e->getMessage());
    }
}

// Destroy the session properly - FIXED: Use correct function name
destroy_user_session($conn);

// Redirect to login page with logout message
session_start();
$_SESSION['login_message'] = 'You have been successfully logged out.';
header("Location: login.php");
exit();
?>