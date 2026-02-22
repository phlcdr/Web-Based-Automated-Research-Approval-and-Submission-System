<?php
/**
 * Enhanced functions.php - Fixed for actual database structure
 * All column names and table structures corrected to match your schema
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================================
// DOCUMENT VIEWING FUNCTIONS
// ============================================================================

/**
 * Render document viewer/downloader based on file type
 */
function render_document_viewer($file_path, $filename = null, $options = []) {
    if (!$file_path || !file_exists($file_path)) {
        return '<div class="alert alert-warning"><i class="bi bi-file-x"></i> Document not found</div>';
    }
    
    // Default options
    $defaults = [
        'height' => '600px',
        'width' => '100%',
        'show_download' => true,
        'container_class' => 'document-viewer-container'
    ];
    $options = array_merge($defaults, $options);
    
    // Get file extension
    $file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    $display_filename = $filename ?: basename($file_path);
    
    $html = '<div class="' . $options['container_class'] . '">';
    
    if ($file_extension === 'pdf') {
        // PDF Viewer
        $html .= render_pdf_viewer($file_path, $display_filename, $options);
    } else {
        // DOC/DOCX Download only
        $html .= render_document_download($file_path, $display_filename, $file_extension);
    }
    
    $html .= '</div>';
    
    return $html;
}

/**
 * Render PDF viewer with embed tag and fallback
 */
function render_pdf_viewer($file_path, $filename, $options) {
    $file_url = get_file_url($file_path);
    
    $html = '
    <div class="pdf-viewer-wrapper">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h6 class="mb-0"><i class="bi bi-file-earmark-pdf text-danger me-2"></i>' . htmlspecialchars($filename) . '</h6>
                <small class="text-muted">PDF Document</small>
            </div>';
    
    if ($options['show_download']) {
        $html .= '
            <div>
                <a href="' . $file_url . '" class="btn btn-outline-primary btn-sm me-2" target="_blank">
                    <i class="bi bi-arrows-fullscreen me-1"></i>Full Screen
                </a>
                <a href="' . $file_url . '" class="btn btn-primary btn-sm" download>
                    <i class="bi bi-download me-1"></i>Download PDF
                </a>
            </div>';
    }
    
    $html .= '
        </div>
        <div class="pdf-embed-container" style="width: ' . $options['width'] . '; height: ' . $options['height'] . '; border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
            <embed src="' . $file_url . '" 
                   type="application/pdf" 
                   width="100%" 
                   height="100%"
                   style="border: none;">
            <div class="pdf-fallback text-center p-4" style="display: none;">
                <i class="bi bi-file-earmark-pdf text-danger" style="font-size: 3rem;"></i>
                <p class="mt-2 mb-3">Unable to display PDF in browser.</p>
                <a href="' . $file_url . '" class="btn btn-primary" target="_blank">
                    <i class="bi bi-box-arrow-up-right me-2"></i>Open in New Tab
                </a>
            </div>
        </div>
    </div>
    
    <style>
    .pdf-viewer-wrapper .pdf-embed-container {
        background: #f8f9fa;
        position: relative;
    }
    
    .pdf-viewer-wrapper embed {
        background: white;
    }
    
    @media (max-width: 768px) {
        .pdf-viewer-wrapper .pdf-embed-container {
            height: 400px;
        }
        
        .pdf-viewer-wrapper .d-flex {
            flex-direction: column;
            align-items: flex-start !important;
        }
        
        .pdf-viewer-wrapper .d-flex > div:last-child {
            margin-top: 1rem;
        }
    }
    </style>
    
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        // Check if PDF embed is supported
        const embed = document.querySelector(".pdf-embed-container embed");
        const fallback = document.querySelector(".pdf-fallback");
        
        if (embed && fallback) {
            embed.addEventListener("error", function() {
                embed.style.display = "none";
                fallback.style.display = "block";
            });
            
            // Timeout fallback for browsers that don\'t fire error event
            setTimeout(function() {
                if (embed.offsetHeight === 0 || embed.offsetWidth === 0) {
                    embed.style.display = "none";
                    fallback.style.display = "block";
                }
            }, 3000);
        }
    });
    </script>';
    
    return $html;
}

/**
 * Render document download button for DOC/DOCX files
 */
function render_document_download($file_path, $filename, $extension) {
    $file_url = get_file_url($file_path);
    $icon_class = get_file_icon_class($extension);
    $file_type = strtoupper($extension);
    
    $html = '
    <div class="document-download-wrapper text-center p-4" style="border: 2px dashed #dee2e6; border-radius: 8px; background: #f8f9fa;">
        <div class="mb-3">
            <i class="' . $icon_class . '" style="font-size: 3rem;"></i>
        </div>
        <h6 class="mb-2">' . htmlspecialchars($filename) . '</h6>
        <p class="text-muted mb-3">' . $file_type . ' Document</p>
        <div>
            <a href="' . $file_url . '" class="btn btn-primary" download>
                <i class="bi bi-download me-2"></i>Download Document
            </a>
        </div>
    </div>';
    
    return $html;
}

/**
 * Get appropriate icon class for file types
 */
function get_file_icon_class($extension) {
    switch (strtolower($extension)) {
        case 'pdf':
            return 'bi bi-file-earmark-pdf text-danger';
        case 'doc':
        case 'docx':
            return 'bi bi-file-earmark-word text-primary';
        default:
            return 'bi bi-file-earmark text-secondary';
    }
}

/**
 * Convert file path to URL for browser access
 */
function get_file_url($file_path) {
    // Remove leading ../ if present and ensure proper URL format
    $url = $file_path;
    
    // Handle different path formats
    if (strpos($url, '../') === 0) {
        $url = substr($url, 3); // Remove ../
    }
    
    // Ensure it starts with / for absolute path
    if (strpos($url, '/') !== 0 && strpos($url, 'uploads/') === 0) {
        $url = '/' . $url;
    }
    
    // For relative paths from current directory
    if (strpos($url, 'uploads/') === 0) {
        $url = '../' . $url;
    }
    
    return $url;
}

// ============================================================================
// SECURITY FUNCTIONS
// ============================================================================

/**
 * Get setting value from database
 */
function get_system_setting($conn, $key, $default = '') {
    try {
        $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch(PDO::FETCH_COLUMN);
        return $result !== false ? $result : $default;
    } catch (PDOException $e) {
        error_log("Error getting setting {$key}: " . $e->getMessage());
        return $default;
    }
}

/**
 * Get client IP address
 */
function get_client_ip() {
    $ip_keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($ip_keys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            $ip = $_SERVER[$key];
            if (strpos($ip, ',') !== false) {
                $ip = explode(',', $ip)[0];
            }
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

/**
 * Check if session is valid and not expired
 */
function validate_session($conn) {
    if (!$conn) {
        global $conn;
    }
    
    // Check if user is logged in
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        return false;
    }
    
    // Check if session has expired
    if (isset($_SESSION['expires_at']) && time() > $_SESSION['expires_at']) {
        return false;
    }
    
    // Check against database session expiration
    try {
        $stmt = $conn->prepare("SELECT session_expires_at, is_active, registration_status FROM users WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            return false;
        }
        
        // Check if user is still active
        if (!$user['is_active']) {
            return false;
        }
        
        // Check if user is approved
        if (isset($user['registration_status']) && $user['registration_status'] === 'pending') {
            return false;
        }
        
        // Check database session expiration
        if ($user['session_expires_at'] && strtotime($user['session_expires_at']) < time()) {
            return false;
        }
        
    } catch (PDOException $e) {
        error_log("Session validation error: " . $e->getMessage());
        return false;
    }
    
    // Extend session if close to expiring (less than 5 minutes left)
    if (isset($_SESSION['expires_at'])) {
        $time_left = $_SESSION['expires_at'] - time();
        if ($time_left < 300 && $time_left > 0) {
            extend_user_session($conn);
        }
    }
    
    return true;
}

/**
 * Extend current user session
 */
function extend_user_session($conn) {
    if (!$conn) {
        global $conn;
    }
    
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    
    $session_timeout = intval(get_system_setting($conn, 'session_timeout', 30));
    $new_expires_at = time() + ($session_timeout * 60);
    $_SESSION['expires_at'] = $new_expires_at;
    
    // Update database
    try {
        $stmt = $conn->prepare("UPDATE users SET session_expires_at = ? WHERE user_id = ?");
        $stmt->execute([date('Y-m-d H:i:s', $new_expires_at), $_SESSION['user_id']]);
        return true;
    } catch (PDOException $e) {
        error_log("Session extension error: " . $e->getMessage());
        return false;
    }
}

/**
 * Destroy session properly
 */
function destroy_user_session($conn) {
    if (!$conn) {
        global $conn;
    }
    
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

/**
 * Redirect to login with message
 */
function redirect_to_login($message = "Please log in to access this page.") {
    // Determine the correct path to auth directory
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
    
    // Store message and redirect URL for after login
    session_start();
    $_SESSION['login_message'] = $message;
    if (!isset($_SESSION['redirect_url'])) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    }
    
    header("Location: " . $auth_path);
    exit();
}

/**
 * Log security events
 */
function log_security_event($conn, $event_type, $description, $user_id = null) {
    if (!$conn) {
        global $conn;
    }
    
    try {
        $user_id = $user_id ?? ($_SESSION['user_id'] ?? null);
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        // Log to error log for now (you can create a security_logs table later if needed)
        error_log("Security Event - Type: {$event_type}, User: {$user_id}, IP: {$ip_address}, Description: {$description}");
        
    } catch (Exception $e) {
        error_log("Failed to log security event: " . $e->getMessage());
    }
}

// ============================================================================
// ENHANCED EXISTING FUNCTIONS
// ============================================================================

/**
 * Function to validate user input
 */
function validate_input($data)
{
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

/**
 * Enhanced: Function to check if user is logged in with session validation
 */
function is_logged_in()
{
    global $conn;
    
    // Ensure session is started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Basic check if user session exists
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        redirect_to_login("Please log in to access this page.");
    }
    
    // Enhanced security: Validate session with timeout and database checks
    if (isset($conn) && !validate_session($conn)) {
        destroy_user_session($conn);
        redirect_to_login("Your session has expired. Please log in again.");
    }
    
    // Update last activity (using updated_at column)
    if (isset($conn) && isset($_SESSION['user_id'])) {
        try {
            $stmt = $conn->prepare("UPDATE users SET updated_at = NOW() WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
        } catch (PDOException $e) {
            error_log("Error updating last activity: " . $e->getMessage());
        }
    }
}

/**
 * Enhanced: Function to check user role with security validation
 */
function check_role($allowed_roles)
{
    global $conn;
    
    // Ensure user is logged in first (includes session validation)
    is_logged_in();
    
    // Check role permissions
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
        // Log unauthorized access attempt
        if (isset($conn)) {
            $username = $_SESSION['username'] ?? 'unknown';
            $role = $_SESSION['role'] ?? 'none';
            log_security_event($conn, 'unauthorized_access', "User {$username} attempted to access " . $_SERVER['REQUEST_URI'] . " with role {$role}");
        }
        
        // Redirect based on actual role
        if (isset($_SESSION['role'])) {
            switch ($_SESSION['role']) {
                case 'student':
                    header("Location: ../student/dashboard.php?error=unauthorized");
                    break;
                case 'panel':
                case 'adviser':
                    header("Location: ../panel/dashboard.php?error=unauthorized");
                    break;
                case 'admin':
                    header("Location: ../admin/dashboard.php?error=unauthorized");
                    break;
                default:
                    destroy_user_session($conn);
                    redirect_to_login("Access denied. Please log in with appropriate permissions.");
            }
        } else {
            redirect_to_login("Please log in to access this page.");
        }
        exit();
    }
}

/**
 * Enhanced: Function to upload file with security validation
 */
function upload_file($file, $target_dir, $conn = null)
{
    if (!$conn) {
        global $conn;
    }
    
    // Create directory if it doesn't exist
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0755, true);
    }

    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [
            'success' => false,
            'message' => 'Upload error occurred'
        ];
    }

    // Get system settings for file validation
    $max_file_size_mb = 10;
    $allowed_types = ['doc', 'docx', 'pdf'];
    
    if ($conn) {
        $max_file_size_mb = intval(get_system_setting($conn, 'max_file_size', 10));
        $allowed_types_str = get_system_setting($conn, 'allowed_file_types', 'doc,docx,pdf');
        $allowed_types = explode(',', $allowed_types_str);
        $allowed_types = array_map('trim', $allowed_types);
    }
    
    $file_type = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));

    // Check if file type is allowed
    if (!in_array($file_type, $allowed_types)) {
        return [
            'success' => false,
            'message' => 'Only ' . implode(', ', array_map('strtoupper', $allowed_types)) . ' files are allowed'
        ];
    }

    // Check file size
    $max_size_bytes = $max_file_size_mb * 1024 * 1024;
    if ($file['size'] > $max_size_bytes) {
        return [
            'success' => false,
            'message' => "File size must be less than {$max_file_size_mb}MB"
        ];
    }

    // Additional security: Check MIME type
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowed_mime_types = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/plain'
        ];

        if (!in_array($mime_type, $allowed_mime_types)) {
            return [
                'success' => false,
                'message' => 'Invalid file type detected'
            ];
        }
    }

    // Generate unique filename with timestamp
    $new_filename = date('Y-m-d_H-i-s') . '_' . uniqid() . '.' . $file_type;
    $target_file = $target_dir . $new_filename;

    // Upload file
    if (move_uploaded_file($file["tmp_name"], $target_file)) {
        return [
            'success' => true,
            'path' => $target_file,
            'filename' => $new_filename
        ];
    } else {
        return [
            'success' => false,
            'message' => 'There was an error uploading your file'
        ];
    }
}

// ============================================================================
// HELPER FUNCTIONS (PRESERVED)
// ============================================================================

/**
 * Function to get user full name
 */
function get_user_full_name($user_id, $conn) {
    try {
        $stmt = $conn->prepare("SELECT CONCAT(first_name, ' ', last_name) as full_name FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['full_name'] : 'Unknown User';
    } catch (Exception $e) {
        error_log("Error getting user full name: " . $e->getMessage());
        return 'Unknown User';
    }
}

/**
 * Function to format date
 */
function format_date($date, $format = 'M d, Y') {
    if (!$date) return 'N/A';
    return date($format, strtotime($date));
}

/**
 * Function to get status badge class
 */
function get_status_badge_class($status) {
    switch (strtolower($status)) {
        case 'approved':
            return 'success';
        case 'rejected':
            return 'danger';
        case 'pending':
            return 'warning';
        case 'needs_revision':
            return 'warning';
        default:
            return 'secondary';
    }
}

/**
 * Function to redirect with message
 */
function redirect_with_message($url, $message, $type = 'success') {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
    header("Location: $url");
    exit();
}

/**
 * Function to get and clear flash message
 */
function get_flash_message() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $type = $_SESSION['flash_type'] ?? 'info';
        unset($_SESSION['flash_message']);
        unset($_SESSION['flash_type']);
        return ['message' => $message, 'type' => $type];
    }
    return null;
}

// ============================================================================
// MAINTENANCE AND CLEANUP FUNCTIONS
// ============================================================================

/**
 * Clean up expired sessions and security data
 */
function cleanup_security_data($conn) {
    if (!$conn) {
        global $conn;
    }
    
    try {
        // Clear expired sessions
        $stmt = $conn->prepare("UPDATE users SET session_expires_at = NULL WHERE session_expires_at < NOW()");
        $stmt->execute();
        
        // Remove old login attempts (older than 24 hours)
        $stmt = $conn->prepare("DELETE FROM login_attempts WHERE attempt_time < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $stmt->execute();
        
        // Unlock accounts where lockout period has expired
        $stmt = $conn->prepare("UPDATE users SET account_locked = FALSE, locked_until = NULL WHERE locked_until < NOW()");
        $stmt->execute();
        
    } catch (PDOException $e) {
        error_log("Error during security cleanup: " . $e->getMessage());
    }
}

/**
 * FIXED: Get user submission progress using actual database structure
 */
function get_user_submission_progress($conn, $user_id) {
    try {
        // Check if user has a research group
        $stmt = $conn->prepare("SELECT group_id FROM research_groups WHERE lead_student_id = ?");
        $stmt->execute([$user_id]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$group) {
            return ['has_group' => false, 'message' => 'Create a research group first.'];
        }
        
        // Get submission counts using submissions table
        $stmt = $conn->prepare("
            SELECT 
                COUNT(CASE WHEN submission_type = 'title' AND status = 'approved' THEN 1 END) as approved_titles,
                COUNT(CASE WHEN submission_type = 'chapter' AND status = 'approved' THEN 1 END) as approved_chapters
            FROM submissions WHERE group_id = ?
        ");
        $stmt->execute([$group['group_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'has_group' => true,
            'approved_titles' => $result['approved_titles'],
            'approved_chapters' => $result['approved_chapters'],
            'can_access_discussions' => $result['approved_titles'] > 0
        ];
    } catch (Exception $e) {
        error_log("Error checking submission progress: " . $e->getMessage());
        return ['has_group' => false, 'message' => 'Error checking status'];
    }
}

/**
 * FIXED: Get thesis discussion access status using actual database structure
 */
function get_thesis_discussion_status($conn, $user_id) {
    try {
        // Get student's group
        $stmt = $conn->prepare("SELECT group_id FROM research_groups WHERE lead_student_id = ?");
        $stmt->execute([$user_id]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$group) {
            return [
                'can_access' => false, 
                'reason' => 'no_group', 
                'message' => 'You need to create a research group first.'
            ];
        }
        
        // Get approved research title using submissions table
        $stmt = $conn->prepare("SELECT submission_id FROM submissions WHERE group_id = ? AND submission_type = 'title' AND status = 'approved' ORDER BY approval_date DESC LIMIT 1");
        $stmt->execute([$group['group_id']]);
        $title = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$title) {
            return [
                'can_access' => false, 
                'reason' => 'no_approved_title', 
                'message' => 'You need an approved research title first.'
            ];
        }
        
        return [
            'can_access' => true,
            'reason' => 'approved_title_exists',
            'message' => 'You can access thesis discussions.'
        ];
        
    } catch (Exception $e) {
        error_log("Error checking thesis discussion status: " . $e->getMessage());
        return [
            'can_access' => false, 
            'reason' => 'error', 
            'message' => 'Unable to check access status.'
        ];
    }
}

/**
 * Centralized function to get notification redirection URL based on type and role
 */
function get_notification_redirect_url($notif, $role) {
    $type = $notif['type'] ?? '';
    $context_id = $notif['context_id'] ?? '';
    $message = $notif['message'] ?? '';
    $context_type = $notif['context_type'] ?? '';

    switch ($role) {
        case 'admin':
            switch ($type) {
                case 'user_registration':
                    return 'manage_users.php?status=pending';
                case 'title_submission':
                case 'chapter_submission':
                case 'chapter_review':
                case 'reviewer_assigned':
                    return 'manage_research.php?tab=submissions&status=pending';
                case 'title_assignment':
                case 'group_assignment':
                case 'reviewer_assignment':
                case 'discussion_update':
                case 'discussion_added':
                case 'discussion':
                case 'thesis_message':
                case 'chapter_message':
                    return 'manage_research.php?tab=groups';
                default:
                    return 'dashboard.php';
            }
        case 'student':
            if (strpos($type, 'title') !== false) return 'submit_title.php';
            if (strpos($type, 'chapter') !== false) return 'submit_chapter.php';
            if (strpos($type, 'discussion') !== false || strpos($type, 'message') !== false) return 'thesis_discussion.php';
            return 'dashboard.php';
        case 'panel':
        case 'adviser':
            // If it's a specific submission, go to the review page if context_id exists
            if (($type == 'title' || strpos($message, 'title') !== false) && $context_id && $context_type == 'submission') {
                return "review_title.php?id=$context_id";
            }
            if (($type == 'chapter' || strpos($message, 'chapter') !== false) && $context_id && $context_type == 'submission') {
                return "review_chapter.php?id=$context_id";
            }
            
            // Fallback for general categories
            if ($type == 'title' || strpos($message, 'title') !== false) return 'review_titles.php';
            if ($type == 'chapter' || strpos($message, 'chapter') !== false) return 'review_chapters.php';
            if (strpos($type, 'discussion') !== false || strpos($message, 'discussion') !== false || $context_type == 'discussion') {
                if ($context_id && $context_type == 'discussion') {
                    return "thesis_discussion.php?id=$context_id";
                }
                return 'thesis_inbox.php';
            }
            return 'dashboard.php';
        default:
            return 'dashboard.php';
    }
}

// Auto-cleanup on random page loads (1% chance)
if (isset($conn) && rand(1, 100) === 1) {
    cleanup_security_data($conn);
}
?>