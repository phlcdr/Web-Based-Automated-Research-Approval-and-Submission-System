<?php
/** * Session Manager - Handles session timeout and validation
 * Include this file in all protected pages
 */
// Secure session configuration - MUST be before session_start()
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 0); // Set to 0 if not using HTTPS
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.sid_length', 48);
ini_set('session.sid_bits_per_character', 6);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Prevent session fixation
if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
    $_SESSION['created_at'] = time();
}

include_once dirname(__FILE__) . '/../config/database.php';

// Function to get setting value
function get_setting_value($conn, $key, $default = '') {
    try {
        $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch(PDO::FETCH_COLUMN);
        return $result !== false ? $result : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

// Generate session fingerprint
function get_session_fingerprint() {
    $fingerprint = $_SERVER['HTTP_USER_AGENT'] . 
                   $_SERVER['REMOTE_ADDR'] . 
                   ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    return hash('sha256', $fingerprint);
}

// Function to check if session is valid and not expired
function validate_session($conn) {
    // Rate limit validation checks
    if (!isset($_SESSION['last_validation'])) {
        $_SESSION['last_validation'] = time();
    } else {
        if (time() - $_SESSION['last_validation'] < 1) {
            return true;
        }
    }
    $_SESSION['last_validation'] = time();
    
    // Check if user is logged in
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        return false;
    }
    
    // Bind session to IP address and User Agent
    if (!isset($_SESSION['ip_address'])) {
        $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
    } else {
        // Validate IP hasn't changed
        if ($_SESSION['ip_address'] !== $_SERVER['REMOTE_ADDR']) {
            destroy_session($conn);
            return false;
        }
        
        // Validate User Agent hasn't changed
        if ($_SESSION['user_agent'] !== $_SERVER['HTTP_USER_AGENT']) {
            destroy_session($conn);
            return false;
        }
    }
    
    // Validate session fingerprint
    if (!isset($_SESSION['fingerprint'])) {
        $_SESSION['fingerprint'] = get_session_fingerprint();
    } elseif ($_SESSION['fingerprint'] !== get_session_fingerprint()) {
        destroy_session($conn);
        return false;
    }
    
    // Absolute timeout - max 12 hours regardless of activity
    $absolute_timeout = 12 * 60 * 60; // 12 hours
    if (isset($_SESSION['created_at'])) {
        if (time() - $_SESSION['created_at'] > $absolute_timeout) {
            destroy_session($conn);
            return false;
        }
    }
    
    // Check if session has expired (idle timeout)
    if (isset($_SESSION['expires_at']) && time() > $_SESSION['expires_at']) {
        destroy_session($conn);
        return false;
    }
    
    // Check against database session expiration
    try {
        $stmt = $conn->prepare("SELECT session_expires_at FROM users WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $db_expires = $stmt->fetch(PDO::FETCH_COLUMN);
        
        if ($db_expires && strtotime($db_expires) < time()) {
            destroy_session($conn);
            return false;
        }
    } catch (PDOException $e) {
        error_log("Session validation error: " . $e->getMessage());
        return false;
    }
    
    // Update session expiration if close to expiring (extend session)
    $session_timeout = intval(get_setting_value($conn, 'session_timeout', 30));
    $time_left = $_SESSION['expires_at'] - time();
    
    // If less than 5 minutes left, extend the session
    if ($time_left < 300) {
        extend_session($conn, $session_timeout);
    }
    
    return true;
}

// Function to extend current session
function extend_session($conn, $session_timeout_minutes) {
    $new_expires_at = time() + ($session_timeout_minutes * 60);
    $_SESSION['expires_at'] = $new_expires_at;
    
    // Update database
    try {
        $stmt = $conn->prepare("UPDATE users SET session_expires_at = ? WHERE user_id = ?");
        $stmt->execute([date('Y-m-d H:i:s', $new_expires_at), $_SESSION['user_id']]);
    } catch (PDOException $e) {
        error_log("Session extension error: " . $e->getMessage());
    }
}

// Function to destroy session properly
function destroy_session($conn) {
    // Clear session expiration from database
    if (isset($_SESSION['user_id'])) {
        try {
            $stmt = $conn->prepare("UPDATE users SET session_expires_at = NULL WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
        } catch (PDOException $e) {
            error_log("Session cleanup error: " . $e->getMessage());
        }
    }
    
    // Destroy session
    session_unset();
    session_destroy();
}

// Function to redirect to login with message
function redirect_to_login($message = "Your session has expired. Please log in again.") {
    $current_path = $_SERVER['REQUEST_URI'];
    
    if (strpos($current_path, '/admin/') !== false) {
        $auth_path = '../auth/login.php';
    } elseif (strpos($current_path, '/student/') !== false) {
        $auth_path = '../auth/login.php';
    } elseif (strpos($current_path, '/panel/') !== false) {
        $auth_path = '../auth/login.php';
    } else {
        $auth_path = 'auth/login.php';
    }
    
    session_start();
    $_SESSION['login_message'] = $message;
    
    header("Location: " . $auth_path);
    exit();
}

// Main session check function
function check_session($conn) {
    if (!validate_session($conn)) {
        destroy_session($conn);
        redirect_to_login("Your session has expired. Please log in again.");
    }
}

// Clean up expired sessions from database
function cleanup_expired_sessions($conn) {
    try {
        $stmt = $conn->prepare("UPDATE users SET session_expires_at = NULL WHERE session_expires_at < NOW()");
        $stmt->execute();
        
        $stmt = $conn->prepare("DELETE FROM login_attempts WHERE attempt_time < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $stmt->execute();
    } catch (PDOException $e) {
        error_log("Session cleanup error: " . $e->getMessage());
    }
}

// Auto-run cleanup occasionally
if (rand(1, 100) === 1) {
    cleanup_expired_sessions($conn);
}

// Check session validity
$current_script = basename($_SERVER['PHP_SELF']);
if ($current_script !== 'login.php' && $current_script !== 'register.php' && isset($conn)) {
    check_session($conn);
}
?>