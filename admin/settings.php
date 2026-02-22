<?php
session_start(); // ADDED THIS LINE TO FIX SESSION ISSUES

include_once '../config/database.php';
include_once '../includes/submission_functions.php';  
include_once '../includes/functions.php';

// Check if user is logged in and is an admin
is_logged_in();
check_role(['admin']);

$success_message = '';
$error_message = '';

// Initialize settings table if it doesn't exist
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS settings (
        id INT PRIMARY KEY AUTO_INCREMENT,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    
    // Insert default settings if they don't exist
    $default_settings = [
        'site_title' => 'Research Approval System',
        'admin_email' => 'admin@essu.edu.ph',
        'max_file_size' => '10',
        'allowed_file_types' => 'doc,docx,pdf',
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_user' => '',
        'smtp_pass' => '',
        'min_password_length' => '8',
        'require_special_chars' => '1',
        'session_timeout' => '30',
        'max_login_attempts' => '5',
        'lockout_duration' => '15',
        'require_admin_approval' => '1'
    ];
    
    foreach ($default_settings as $key => $value) {
        $stmt = $conn->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)");
        $stmt->execute([$key, $value]);
    }
} catch (PDOException $e) {
    $error_message = "Error initializing settings: " . $e->getMessage();
}

// Function to get setting value
function get_setting($conn, $key, $default = '') {
    try {
        $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch(PDO::FETCH_COLUMN);
        return $result !== false ? $result : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

// Function to update setting
function update_setting($conn, $key, $value) {
    try {
        $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) 
                               ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        return $stmt->execute([$key, $value]);
    } catch (PDOException $e) {
        return false;
    }
}

// Helper function to determine notification redirect URL
function getNotificationRedirectUrl($notification) {
    switch($notification['type']) {
        case 'user_registration':
            return 'manage_users.php';
        case 'title_submission':
        case 'chapter_submission':
            return 'manage_research.php';
        case 'reviewer_assignment':
            return 'manage_research.php';
        case 'discussion_update':
            return 'manage_research.php';
        default:
            return 'dashboard.php';
    }
}

// Handle AJAX request to mark notification as read
if (isset($_GET['mark_read']) && isset($_GET['notification_id'])) {
    try {
        $stmt = $conn->prepare("UPDATE notifications SET is_viewed = 1 WHERE notification_id = ? AND user_id = ?");
        $stmt->execute([$_GET['notification_id'], $_SESSION['user_id']]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Handle AJAX request to get notification count
if (isset($_GET['get_count'])) {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_viewed = 0");
        $stmt->execute([$_SESSION['user_id']]);
        $count = $stmt->fetchColumn();
        echo json_encode(['count' => (int)$count]);
    } catch (PDOException $e) {
        echo json_encode(['count' => 0]);
    }
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // System settings
        if (isset($_POST['update_system_settings'])) {
            update_setting($conn, 'site_title', validate_input($_POST['site_title']));
            update_setting($conn, 'admin_email', validate_input($_POST['admin_email']));
            update_setting($conn, 'max_file_size', intval($_POST['max_file_size']));
            update_setting($conn, 'allowed_file_types', validate_input($_POST['allowed_file_types']));
            $success_message = "System settings updated successfully";
        }

        // Email settings
        if (isset($_POST['update_email_settings'])) {
            update_setting($conn, 'smtp_host', validate_input($_POST['smtp_host']));
            update_setting($conn, 'smtp_port', intval($_POST['smtp_port']));
            update_setting($conn, 'smtp_user', validate_input($_POST['smtp_user']));
            update_setting($conn, 'smtp_pass', $_POST['smtp_pass']);
            $success_message = "Email settings updated successfully";
        }

        // Security settings
        if (isset($_POST['update_security_settings'])) {
            update_setting($conn, 'min_password_length', intval($_POST['min_password_length']));
            update_setting($conn, 'require_special_chars', isset($_POST['require_special_chars']) ? '1' : '0');
            update_setting($conn, 'session_timeout', intval($_POST['session_timeout']));
            update_setting($conn, 'max_login_attempts', intval($_POST['max_login_attempts']));
            update_setting($conn, 'lockout_duration', intval($_POST['lockout_duration']));
            update_setting($conn, 'require_admin_approval', isset($_POST['require_admin_approval']) ? '1' : '0');
            $success_message = "Security settings updated successfully";
        }
    } catch (Exception $e) {
        $error_message = "Error updating settings: " . $e->getMessage();
    }
}

// Get current settings
$settings = [
    'site_title' => get_setting($conn, 'site_title', 'Research Approval System'),
    'admin_email' => get_setting($conn, 'admin_email', 'admin@essu.edu.ph'),
    'max_file_size' => get_setting($conn, 'max_file_size', '10'),
    'allowed_file_types' => get_setting($conn, 'allowed_file_types', 'doc,docx,pdf'),
    'smtp_host' => get_setting($conn, 'smtp_host'),
    'smtp_port' => get_setting($conn, 'smtp_port', '587'),
    'smtp_user' => get_setting($conn, 'smtp_user'),
    'smtp_pass' => get_setting($conn, 'smtp_pass'),
    'min_password_length' => get_setting($conn, 'min_password_length', '8'),
    'require_special_chars' => get_setting($conn, 'require_special_chars', '1'),
    'session_timeout' => get_setting($conn, 'session_timeout', '30'),
    'max_login_attempts' => get_setting($conn, 'max_login_attempts', '5'),
    'lockout_duration' => get_setting($conn, 'lockout_duration', '15'),
    'require_admin_approval' => get_setting($conn, 'require_admin_approval', '1')
];

// Fetch notifications for admin
$recent_notifications = [];
$total_unviewed = 0;

try {
    // Get unviewed count
    $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_viewed = 0");
    $stmt->execute([$_SESSION['user_id']]);
    $total_unviewed = (int)$stmt->fetchColumn();
    
    // Get recent notifications (last 10)
    $stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY submission_date DESC LIMIT 10");
    $stmt->execute([$_SESSION['user_id']]);
    $recent_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Silently fail - notifications are not critical for settings page
    $total_unviewed = 0;
    $recent_notifications = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpg" href="../assets/images/essu logo.png">
    <title>System Settings - Research Approval System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --university-blue: #1e40af;
            --university-gold: #f59e0b;
            --academic-gray: #374151;
            --light-gray: #f8fafc;
            --border-light: #e5e7eb;
            --text-primary: #111827;
            --text-secondary: #6b7280;
            --success-green: #059669;
            --warning-orange: #d97706;
            --danger-red: #dc2626;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--light-gray);
            color: var(--text-primary);
            overflow-x: hidden;
        }

        /* Header Section */
        .university-header {
            background: linear-gradient(135deg, var(--university-blue) 0%, #1e3a8a 100%);
            color: white;
            padding: 1rem 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .university-brand {
            display: flex;
            align-items: center;
            color: white !important;
            text-decoration: none;
            flex: 1;
        }

        .university-brand:hover {
            color: white !important;
        }

        .university-logo {
            width: 50px;
            height: 50px;
            margin-right: 15px;
            border-radius: 8px;
            flex-shrink: 0;
        }

        .university-name {
            font-size: 1.3rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .university-tagline {
            font-size: 0.85rem;
            opacity: 0.9;
            font-weight: 400;
        }

        /* Notification Styles */
        .notification-section {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .notification-bell {
            position: relative;
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            padding: 0.5rem;
            border-radius: 50%;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .notification-bell:hover {
            background: rgba(255,255,255,0.1);
            color: white;
            transform: scale(1.1);
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--danger-red);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: pulse 2s infinite;
            border: 2px solid white;
            transition: all 0.3s ease;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        @keyframes shake {
            0%, 50%, 100% { transform: rotate(0deg); }
            10%, 30% { transform: rotate(-10deg); }
            20%, 40% { transform: rotate(10deg); }
        }

        .notification-dropdown {
            min-width: 380px;
            max-width: 400px;
            max-height: 500px;
            overflow-y: auto;
            border: none;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            border-radius: 12px;
        }

        .notification-header {
            background: linear-gradient(135deg, var(--university-blue), #1e3a8a);
            color: white;
            padding: 1rem 1.25rem;
            border-radius: 12px 12px 0 0;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .notification-item {
            padding: 0.875rem 1.25rem;
            border-bottom: 1px solid var(--border-light);
            transition: all 0.2s ease;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: block;
        }

        .notification-item:hover {
            background: rgba(30, 64, 175, 0.05);
            color: inherit;
            text-decoration: none;
        }

        .notification-item.viewed {
            opacity: 0.6;
            background: rgba(0,0,0,0.02);
        }

        .notification-item.removing {
            animation: slideOutLeft 0.3s ease forwards;
        }

        @keyframes slideOutLeft {
            from {
                opacity: 1;
                transform: translateX(0);
            }
            to {
                opacity: 0;
                transform: translateX(-100%);
            }
        }

        .notification-icon {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
            margin-right: 0.75rem;
            flex-shrink: 0;
        }

        .notification-icon.user_registration { 
            background: rgba(245, 158, 11, 0.1); 
            color: var(--university-gold); 
        }

        .notification-icon.title_submission { 
            background: rgba(30, 64, 175, 0.1); 
            color: var(--university-blue); 
        }

        .notification-icon.chapter_submission { 
            background: rgba(5, 150, 105, 0.1); 
            color: var(--success-green); 
        }

        .notification-icon.reviewer_assignment { 
            background: rgba(2, 132, 199, 0.1); 
            color: #0284c7; 
        }

        .notification-icon.discussion_update { 
            background: rgba(217, 119, 6, 0.1); 
            color: var(--warning-orange); 
        }

        .notification-content {
            flex: 1;
        }

        .notification-title {
            font-weight: 600;
            font-size: 0.875rem;
            margin-bottom: 0.25rem;
            color: var(--text-primary);
        }

        .notification-description {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-bottom: 0.25rem;
        }

        .notification-time {
            font-size: 0.7rem;
            color: var(--text-secondary);
        }

        .notification-summary {
            padding: 0.75rem 1.25rem;
            background: rgba(248, 250, 252, 0.8);
            border-top: 1px solid var(--border-light);
            font-size: 0.875rem;
            color: var(--text-secondary);
            text-align: center;
            position: sticky;
            bottom: 0;
        }

        /* Navigation */
        .main-nav {
            background: white;
            border-bottom: 3px solid var(--university-gold);
            padding: 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .nav-tabs {
            border-bottom: none;
            margin-bottom: 0;
        }

        .nav-tabs .nav-link {
            color: var(--text-secondary);
            font-weight: 500;
            border: none;
            padding: 1rem 1.5rem;
            border-radius: 0;
            position: relative;
            transition: all 0.3s ease;
        }

        .nav-tabs .nav-link:hover {
            color: var(--university-blue);
            background: rgba(30, 64, 175, 0.05);
            border-color: transparent;
        }

        .nav-tabs .nav-link.active {
            color: var(--university-blue);
            background: white;
            border-color: transparent;
        }

        .nav-tabs .nav-link.active::after {
            content: '';
            position: absolute;
            bottom: -3px;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--university-gold);
        }

        /* Main Content */
        .main-content {
            padding: 2rem 0;
        }

        .page-header {
            background: white;
            border-radius: 8px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border-left: 4px solid var(--university-blue);
        }

        .page-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--university-blue);
            margin-bottom: 0.5rem;
        }

        .page-subtitle {
            color: var(--text-secondary);
            font-size: 1rem;
        }

        /* User Profile */
        .user-profile {
            display: flex;
            align-items: center;
            padding: 0.5rem 1rem;
            color: white !important;
            text-decoration: none;
            border-radius: 6px;
            transition: background 0.2s ease;
        }

        .user-profile:hover {
            background: rgba(255,255,255,0.1);
            color: white !important;
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--university-gold);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            margin-right: 0.75rem;
            color: white;
            font-size: 0.8rem;
        }

        .user-info {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 600;
            font-size: 0.85rem;
        }

        .user-role {
            font-size: 0.7rem;
            opacity: 0.8;
        }

        /* Settings Sections */
        .settings-nav {
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            padding: 1rem;
            margin-bottom: 2rem;
        }

        .settings-nav .list-group {
            border: none;
        }

        .settings-nav .list-group-item {
            border: none;
            padding: 0.75rem 1rem;
            border-radius: 6px;
            margin-bottom: 0.25rem;
            color: var(--text-secondary);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .settings-nav .list-group-item:hover {
            background: rgba(30, 64, 175, 0.05);
            color: var(--university-blue);
        }

        .settings-nav .list-group-item.active {
            background: var(--university-blue);
            color: white;
            border-color: var(--university-blue);
        }

        /* Form Sections */
        .form-section {
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            overflow: hidden;
        }

        .section-header {
            background: linear-gradient(90deg, var(--university-blue), #1e3a8a);
            color: white;
            padding: 1rem 1.5rem;
            font-weight: 600;
            font-size: 1rem;
        }

        .section-body {
            padding: 2rem;
        }

        /* Form Controls */
        .form-label {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .form-control, .form-select {
            border: 1px solid var(--border-light);
            border-radius: 6px;
            padding: 0.75rem;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--university-blue);
            box-shadow: 0 0 0 0.2rem rgba(30, 64, 175, 0.25);
        }

        .form-check-input:checked {
            background-color: var(--university-blue);
            border-color: var(--university-blue);
        }

        /* Buttons */
        .btn-primary {
            background: var(--university-blue);
            border-color: var(--university-blue);
            font-weight: 500;
            padding: 0.75rem 1.5rem;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            background: #1e3a8a;
            border-color: #1e3a8a;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        /* Alert Messages */
        .alert-success {
            background: rgba(5, 150, 105, 0.1);
            border-color: var(--success-green);
            color: var(--success-green);
            border-radius: 8px;
        }

        .alert-danger {
            background: rgba(220, 38, 38, 0.1);
            border-color: var(--danger-red);
            color: var(--danger-red);
            border-radius: 8px;
        }

        /* Loading animation */
        .notification-badge.updating {
            animation: spin 0.5s linear;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Dropdown Menus */
        .dropdown-menu {
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            border: 1px solid var(--border-light);
        }

        .dropdown-item {
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }

        .dropdown-item:hover {
            background-color: rgba(30, 64, 175, 0.05);
        }

        /* RESPONSIVE - TABLET */
        @media (max-width: 768px) {
            .notification-dropdown { 
                min-width: 320px; 
                max-width: 350px; 
            }
            
            .university-header {
                padding: 0.75rem 0;
            }
            
            .university-name { 
                font-size: 1rem; 
            }
            
            .university-tagline {
                font-size: 0.75rem;
            }
            
            .university-logo {
                width: 40px;
                height: 40px;
                margin-right: 10px;
            }
            
            .page-title { 
                font-size: 1.5rem; 
            }
            
            .page-header { 
                padding: 1.5rem; 
            }
            
            .nav-tabs .nav-link { 
                padding: 0.85rem 0.6rem; 
                font-size: 0.85rem; 
            }
            
            .main-content { 
                padding: 1rem 0; 
            }
            
            .section-body { 
                padding: 1.5rem; 
            }

            .settings-nav {
                margin-bottom: 1.5rem;
            }
        }

        /* RESPONSIVE - MOBILE */
        @media (max-width: 576px) {
            .university-header {
                padding: 0.75rem 0;
            }
            
            .university-brand {
                flex: 0 1 auto;
                max-width: 60%;
            }
            
            .university-name { 
                font-size: 0.85rem; 
                line-height: 1.2;
            }
            
            .university-logo { 
                width: 35px; 
                height: 35px; 
                margin-right: 8px;
            }
            
            .notification-section {
                gap: 0.5rem;
                flex-shrink: 0;
            }
            
            .notification-bell {
                font-size: 1.25rem;
                padding: 0.25rem;
            }
            
            .user-avatar {
                width: 32px;
                height: 32px;
                font-size: 0.75rem;
            }
            
            .user-info { 
                display: none !important;
            }
            
            /* Responsive Navigation Tabs */
            .nav-tabs {
                display: flex;
                justify-content: space-between;
                flex-wrap: nowrap;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
            }
            
            .nav-tabs::-webkit-scrollbar { 
                display: none; 
            }
            
            .nav-tabs .nav-link {
                flex: 1;
                min-width: auto;
                text-align: center;
                padding: 1rem 0.5rem;
                font-size: 0.9rem;
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 0.25rem;
                white-space: nowrap;
                color: var(--text-secondary);
                position: relative;
            }
            
            .nav-tabs .nav-link i { 
                font-size: 1.3rem; 
                margin: 0; 
            }
            
            .nav-tabs .nav-link span { 
                font-size: 0.75rem; 
                white-space: nowrap; 
            }
            
            .nav-tabs .nav-link .d-none.d-sm-inline { 
                display: inline !important; 
            }
            
            .nav-tabs .nav-link.active { 
                color: var(--university-blue); 
                background: white; 
            }
            
            .nav-tabs .nav-link.active::after {
                content: '';
                position: absolute;
                bottom: -3px;
                left: 0;
                right: 0;
                height: 3px;
                background: var(--university-gold);
            }
            
            .nav-tabs .nav-link:hover:not(.active) {
                color: var(--university-blue);
                background: rgba(30, 64, 175, 0.05);
            }
            
            .page-header { 
                padding: 1rem; 
                margin-bottom: 1rem; 
            }
            
            .page-title { 
                font-size: 1.25rem; 
            }
            
            .page-subtitle { 
                font-size: 0.85rem; 
            }
            
            .section-header { 
                padding: 0.75rem 1rem; 
                font-size: 0.85rem; 
            }
            
            .section-body { 
                padding: 1rem; 
            }
            
            .main-content { 
                padding: 1rem 0; 
            }
            
            .notification-dropdown { 
                min-width: 300px; 
                max-width: 320px; 
            }
            
            .notification-item { 
                padding: 0.75rem 1rem; 
            }
            
            .notification-icon { 
                width: 30px; 
                height: 30px; 
                font-size: 0.75rem; 
                margin-right: 0.5rem; 
            }
            
            .notification-title { 
                font-size: 0.8rem; 
            }
            
            .notification-description { 
                font-size: 0.7rem; 
            }
            
            .notification-time { 
                font-size: 0.65rem; 
            }

            .settings-nav {
                padding: 0.75rem;
                margin-bottom: 1rem;
            }

            .settings-nav .list-group-item {
                padding: 0.6rem 0.75rem;
                font-size: 0.85rem;
            }

            .btn-primary {
                padding: 0.65rem 1.25rem;
                font-size: 0.9rem;
                width: 100%;
            }
        }

        /* EXTRA SMALL */
        @media (max-width: 374px) {
            .university-name { 
                font-size: 0.75rem; 
            }
            
            .university-logo {
                width: 30px;
                height: 30px;
                margin-right: 6px;
            }
            
            .page-title { 
                font-size: 1.1rem; 
            }
            
            .nav-tabs .nav-link { 
                padding: 0.75rem 0.25rem; 
            }
            
            .nav-tabs .nav-link i { 
                font-size: 1.1rem; 
            }
            
            .nav-tabs .nav-link span { 
                font-size: 0.7rem; 
            }
            
            .section-header { 
                font-size: 0.8rem; 
                padding: 0.65rem 0.75rem; 
            }
        }

        /* LANDSCAPE */
        @media (max-width: 768px) and (orientation: landscape) {
            .university-header {
                padding: 0.5rem 0;
            }
            
            .page-header { 
                padding: 1rem; 
                margin-bottom: 1rem; 
            }
            
            .main-content { 
                padding: 1rem 0; 
            }
            
            .nav-tabs .nav-link { 
                padding: 0.75rem 1rem; 
            }
        }

        /* Fix for dropdowns on mobile */
        @media (max-width: 576px) {
            .dropdown-menu {
                max-width: 90vw;
            }
        }
    </style>
</head>

<body>
    <!-- University Header -->
    <header class="university-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <a href="#" class="university-brand">
                    <img src="../assets/images/essu logo.png" alt="ESSU Logo" class="university-logo">
                    <div>
                        <div class="university-name">EASTERN SAMAR STATE UNIVERSITY</div>
                    </div>
                </a>
                
                <div class="notification-section">
                    <!-- Notification Bell -->
                    <div class="dropdown">
                        <button class="notification-bell" data-bs-toggle="dropdown" aria-expanded="false" id="notificationBell">
                            <i class="bi bi-bell"></i>
                            <?php if ($total_unviewed > 0): ?>
                                <span class="notification-badge" id="notificationBadge"><?php echo min($total_unviewed, 99); ?></span>
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end notification-dropdown">
                            <li class="notification-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-bell me-2"></i>Admin Notifications</span>
                                    <span class="badge bg-light text-dark" id="notificationCount"><?php echo $total_unviewed; ?> new</span>
                                </div>
                            </li>
                            
                            <div id="notificationList">
                                <?php if (count($recent_notifications) > 0): ?>
                                    <?php foreach ($recent_notifications as $notif): ?>
                                        <li>
                                            <a href="<?php echo getNotificationRedirectUrl($notif); ?>" 
                                            class="notification-item<?php echo $notif['is_viewed'] ? ' viewed' : ''; ?>" 
                                            data-notification-id="<?php echo $notif['notification_id']; ?>">
                                                <div class="d-flex align-items-start">
                                                    <div class="notification-icon <?php echo $notif['type']; ?>">
                                                        <i class="bi bi-<?php 
                                                            switch($notif['type']) {
                                                                case 'user_registration': echo 'person-plus'; break;
                                                                case 'title_submission': echo 'journal-check'; break;
                                                                case 'chapter_submission': echo 'file-earmark-check'; break;
                                                                case 'reviewer_assignment': echo 'people'; break;
                                                                case 'discussion_update': echo 'chat-square-text'; break;
                                                                default: echo 'info-circle'; break;
                                                            }
                                                        ?>"></i>
                                                    </div>
                                                    <div class="notification-content">
                                                        <div class="notification-title">
                                                            <?php echo htmlspecialchars($notif['notification_title']); ?>
                                                        </div>
                                                        <div class="notification-description">
                                                            <?php echo htmlspecialchars(substr($notif['message'], 0, 60)) . (strlen($notif['message']) > 60 ? '...' : ''); ?>
                                                        </div>
                                                        <div class="notification-time">
                                                            <?php echo date('M d, g:i A', strtotime($notif['submission_date'])); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <li class="notification-item text-center py-4">
                                        <i class="bi bi-bell-slash text-muted" style="font-size: 2rem; opacity: 0.5;"></i>
                                        <div class="text-muted mt-2">No new notifications</div>
                                    </li>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($total_unviewed > 0): ?>
                                <li class="notification-summary">
                                    <strong><?php echo $total_unviewed; ?></strong> new notification<?php echo $total_unviewed > 1 ? 's' : ''; ?>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <!-- User Profile -->
                    <div class="dropdown">
                        <a href="#" class="user-profile dropdown-toggle" data-bs-toggle="dropdown">
                            <div class="user-avatar">
                                <?php echo strtoupper(substr($_SESSION['full_name'], 0, 2)); ?>
                            </div>
                            <div class="user-info d-none d-md-block">
                                <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                                <div class="user-role">System Administrator</div>
                            </div>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>My Profile</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Sign Out</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Navigation -->
    <nav class="main-nav">
        <div class="container">
            <ul class="nav nav-tabs">
                <li class="nav-item">
                    <a class="nav-link" href="dashboard.php">
                        <i class="bi bi-house-door me-1 me-md-2"></i>
                        <span class="d-none d-sm-inline">Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="manage_users.php">
                        <i class="bi bi-people me-1 me-md-2"></i>
                        <span class="d-none d-sm-inline">Users</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="manage_research.php">
                        <i class="bi bi-journal-check me-1 me-md-2"></i>
                        <span class="d-none d-sm-inline">Research</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="settings.php">
                        <i class="bi bi-sliders me-1 me-md-2"></i>
                        <span class="d-none d-sm-inline">Settings</span>
                    </a>
                </li>
            </ul>
        </div>
    </nav>

    <!-- Main Content -->
<div class="container main-content">
    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title">System Settings</h1>
        <p class="page-subtitle">Configure system parameters, security settings, and administrative preferences</p>
    </div>

    <!-- Success/Error Messages -->
    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i>
            <?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-3">
            <div class="settings-nav">
                <div class="list-group">
                    <button class="list-group-item list-group-item-action active" data-bs-toggle="pill" data-bs-target="#system-settings">
                        <i class="bi bi-gear me-2"></i>System Settings
                    </button>
                    <button class="list-group-item list-group-item-action" data-bs-toggle="pill" data-bs-target="#security-settings">
                        <i class="bi bi-shield-lock me-2"></i>Security Settings
                    </button>
                </div>
            </div>
        </div>

        <div class="col-md-9">
            <div class="tab-content">
                <!-- System Settings -->
                <div class="tab-pane fade show active" id="system-settings">
                    <div class="form-section">
                        <div class="section-header">
                            <i class="bi bi-gear me-2"></i>General System Configuration
                        </div>
                        <div class="section-body">
                            <form method="post">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="site_title" class="form-label">Site Title *</label>
                                        <input type="text" class="form-control" id="site_title" name="site_title"
                                            value="<?php echo htmlspecialchars($settings['site_title']); ?>" required>
                                        <div class="form-text">The name displayed in the application header</div>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="admin_email" class="form-label">Admin Email *</label>
                                        <input type="email" class="form-control" id="admin_email" name="admin_email"
                                            value="<?php echo htmlspecialchars($settings['admin_email']); ?>" required>
                                        <div class="form-text">Primary contact email for system notifications</div>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="max_file_size" class="form-label">Maximum File Upload Size (MB) *</label>
                                        <input type="number" class="form-control" id="max_file_size" name="max_file_size"
                                            value="<?php echo htmlspecialchars($settings['max_file_size']); ?>" min="1" max="100" required>
                                        <div class="form-text">Maximum size for document uploads</div>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="allowed_file_types" class="form-label">Allowed File Types *</label>
                                        <input type="text" class="form-control" id="allowed_file_types" name="allowed_file_types"
                                            value="<?php echo htmlspecialchars($settings['allowed_file_types']); ?>" required>
                                        <div class="form-text">Comma-separated list of file extensions (e.g., doc,docx,pdf)</div>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <button type="submit" name="update_system_settings" class="btn btn-primary">
                                        <i class="bi bi-check-lg me-1"></i>Save System Settings
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Security Settings -->
                <div class="tab-pane fade" id="security-settings">
                    <div class="form-section">
                        <div class="section-header">
                            <i class="bi bi-shield-lock me-2"></i>Security Configuration
                        </div>
                        <div class="section-body">
                            <form method="post">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="min_password_length" class="form-label">Minimum Password Length *</label>
                                        <input type="number" class="form-control" id="min_password_length" name="min_password_length"
                                            value="<?php echo htmlspecialchars($settings['min_password_length']); ?>" min="6" max="20" required>
                                        <div class="form-text">Minimum number of characters required for passwords</div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label for="session_timeout" class="form-label">Session Timeout (minutes) *</label>
                                        <input type="number" class="form-control" id="session_timeout" name="session_timeout"
                                            value="<?php echo htmlspecialchars($settings['session_timeout']); ?>" min="5" max="480" required>
                                        <div class="form-text">How long users stay logged in without activity</div>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="max_login_attempts" class="form-label">Maximum Login Attempts *</label>
                                        <input type="number" class="form-control" id="max_login_attempts" name="max_login_attempts"
                                            value="<?php echo htmlspecialchars($settings['max_login_attempts']); ?>" min="3" max="10" required>
                                        <div class="form-text">Number of failed login attempts before account lockout</div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label for="lockout_duration" class="form-label">Lockout Duration (minutes) *</label>
                                        <input type="number" class="form-control" id="lockout_duration" name="lockout_duration"
                                            value="<?php echo htmlspecialchars($settings['lockout_duration']); ?>" min="5" max="60" required>
                                        <div class="form-text">How long to lock account after max attempts reached</div>
                                    </div>
                                </div>

                                <div class="row g-3 mt-2">
                                    <div class="col-12">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input" id="require_special_chars" name="require_special_chars"
                                                <?php echo $settings['require_special_chars'] == '1' ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="require_special_chars">
                                                <strong>Require Special Characters in Passwords</strong>
                                            </label>
                                            <div class="form-text">When enabled, passwords must contain at least one special character (!@#$%^&*)</div>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input" id="require_admin_approval" name="require_admin_approval"
                                                <?php echo $settings['require_admin_approval'] == '1' ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="require_admin_approval">
                                                <strong>Require Admin Approval for New Registrations</strong>
                                            </label>
                                            <div class="form-text">When enabled, new user registrations must be approved by an administrator before they can login</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <button type="submit" name="update_security_settings" class="btn btn-primary">
                                        <i class="bi bi-check-lg me-1"></i>Save Security Settings
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function markNotificationAsRead(notificationId) {
        return fetch(`?mark_read=1&notification_id=${notificationId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateNotificationCount();
                }
                return data;
            })
            .catch(error => {
                console.error('Error marking notification as read:', error);
                return { success: false };
            });
    }

    function updateNotificationCount() {
        return fetch('?get_count=1')
            .then(response => response.json())
            .then(data => {
                const badge = document.getElementById('notificationBadge');
                const countElement = document.getElementById('notificationCount');
                
                if (data.count === 0) {
                    if (badge) {
                        badge.style.opacity = '0';
                        setTimeout(() => badge.style.display = 'none', 300);
                    }
                    if (countElement) {
                        countElement.textContent = '0 new';
                    }
                } else {
                    if (badge) {
                        badge.style.display = 'flex';
                        badge.style.opacity = '1';
                        badge.textContent = Math.min(data.count, 99);
                    }
                    if (countElement) {
                        countElement.textContent = data.count + ' new';
                    }
                }
                return data.count;
            })
            .catch(error => {
                console.error('Error updating notification count:', error);
                return null;
            });
    }

    // Handle notification clicks
    document.addEventListener('click', function(e) {
        const notificationItem = e.target.closest('.notification-item');
        if (notificationItem && notificationItem.dataset.notificationId) {
            e.preventDefault();
            
            const notificationId = notificationItem.dataset.notificationId;
            const href = notificationItem.href;
            
            markNotificationAsRead(notificationId).then(() => {
                notificationItem.classList.add('viewed');
                setTimeout(() => {
                    window.location.href = href;
                }, 200);
            });
        }
    });

    // Auto-update notification count every 30 seconds
    setInterval(updateNotificationCount, 30000);
</script>