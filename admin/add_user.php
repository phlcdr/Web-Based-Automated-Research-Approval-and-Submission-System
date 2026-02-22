<?php
session_start();

include_once '../config/database.php';
include_once '../includes/submission_functions.php';  
include_once '../includes/functions.php';

// Check if user is logged in and is an admin
is_logged_in();
check_role(['admin']);

$success = '';
$error = '';

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
    // Silently fail - notifications are not critical
    $total_unviewed = 0;
    $recent_notifications = [];
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

// Function to validate password strength
function validate_password($password, $conn) {
    $min_length = intval(get_setting($conn, 'min_password_length', 8));
    $require_special = get_setting($conn, 'require_special_chars', '1') == '1';
    
    $errors = [];
    
    if (strlen($password) < $min_length) {
        $errors[] = "Password must be at least {$min_length} characters long";
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number";
    }
    
    if ($require_special && !preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = "Password must contain at least one special character";
    }
    
    return $errors;
}

// Updated colleges and departments mapping
$college_departments = [
    'College of Engineering' => [
        'Computer Engineering',
        'Civil Engineering', 
        'Electrical Engineering'
    ],
    'College of Nursing and Allied Sciences' => [
        'Nursing',
        'Midwifery',
        'Nutrition and Dietetics'
    ],
    'College of Computer Studies' => [
        'Information Technology',
        'Computer Science',
        'Entertainment and Multimedia Computing',
        'Associate in Computer Technology'
    ],
    'College of Education' => [
        'Secondary Education',
        'Elementary Education'
    ],
    'College of Business and Management' => [
        'Business Administration',
        'Hospitality Management',
        'Tourism Management',
        'Accountancy',
        'Entrepreneurship'
    ],
    'College of Arts and Sciences' => [
        'Biology',
        'Political Science'
    ],
    'College of Agriculture and Fisheries' => [
        'Agriculture',
        'Fisheries'
    ]
];

// Get colleges for dropdown
$colleges = array_keys($college_departments);

// Check database connection
if (!$conn) {
    $error = "Database connection failed";
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = validate_input($_POST['username'] ?? '');
    $email = validate_input($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $first_name = validate_input($_POST['first_name'] ?? '');
    $last_name = validate_input($_POST['last_name'] ?? '');
    $role = validate_input($_POST['role'] ?? '');
    $college = validate_input($_POST['college'] ?? '');
    $department = validate_input($_POST['department'] ?? '');
    $student_id = validate_input($_POST['student_id'] ?? '');

    // Basic validation
    if (empty($username)) {
        $error = "Username is required";
    } elseif (empty($email)) {
        $error = "Email is required";
    } elseif (empty($password)) {
        $error = "Password is required";
    } elseif (empty($first_name)) {
        $error = "First name is required";
    } elseif (empty($last_name)) {
        $error = "Last name is required";
    } elseif (empty($role)) {
        $error = "Role is required";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match";
    } else {
        // Validate college and department relationship if both are provided
        if (!empty($college) && !empty($department)) {
            if (!isset($college_departments[$college]) || !in_array($department, $college_departments[$college])) {
                $error = "Selected department does not belong to the selected college";
            }
        }
        
        if (empty($error)) {
            // Validate password strength
            $password_errors = validate_password($password, $conn);
            if (!empty($password_errors)) {
                $error = implode(". ", $password_errors);
            } else {
                // Check if username already exists
                try {
                    $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
                    $stmt->execute([$username]);
                    if ($stmt->rowCount() > 0) {
                        $error = "Username already exists";
                    } else {
                        // Check if email already exists
                        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
                        $stmt->execute([$email]);
                        if ($stmt->rowCount() > 0) {
                            $error = "Email already exists";
                        } else {
                            // Check if student ID already exists (if provided)
                            if (!empty($student_id)) {
                                $stmt = $conn->prepare("SELECT user_id FROM users WHERE student_id = ?");
                                $stmt->execute([$student_id]);
                                if ($stmt->rowCount() > 0) {
                                    $error = "Student ID already exists";
                                }
                            }
                            
                            if (empty($error)) {
                                try {
                                    // Hash password
                                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                                    // Insert new user - admin-created users are always approved
                                    $sql = "INSERT INTO users (username, password, email, first_name, last_name, role, college, department, student_id, is_active, registration_status) 
                                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'approved')";
                                    $stmt = $conn->prepare($sql);

                                    $result = $stmt->execute([
                                        $username, 
                                        $hashed_password, 
                                        $email, 
                                        $first_name, 
                                        $last_name, 
                                        $role, 
                                        $college, 
                                        $department, 
                                        $student_id
                                    ]);

                                    if ($result) {
                                        $success = "User created successfully! They can login immediately.";
                                        // Clear form data on success
                                        $_POST = array();
                                    } else {
                                        $errorInfo = $stmt->errorInfo();
                                        $error = "Database error during user creation";
                                        error_log("SQL Error: " . print_r($errorInfo, true));
                                    }
                                } catch (Exception $e) {
                                    $error = "User creation failed. Please try again.";
                                    error_log("Add user exception: " . $e->getMessage());
                                }
                            }
                        }
                    }
                } catch (PDOException $e) {
                    $error = "Database error. Please try again.";
                    error_log("Database error in add_user.php: " . $e->getMessage());
                }
            }
        }
    }
}

// Get password requirements for display
$min_length = get_setting($conn, 'min_password_length', 8);
$require_special = get_setting($conn, 'require_special_chars', '1') == '1';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpg" href="../assets/images/essu logo.png">
    <title>Add User - Research Approval System</title>
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

        /* Password Toggle */
        .password-toggle {
            position: relative;
        }

        .toggle-password {
            position: absolute;
            top: 50%;
            right: 15px;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--text-secondary);
            z-index: 10;
        }

        .toggle-password:hover {
            color: var(--university-blue);
        }

        .password-toggle input {
            padding-right: 45px;
        }

        /* Password Requirements */
        .password-requirements {
            font-size: 0.875rem;
            color: var(--text-secondary);
            background: rgba(30, 64, 175, 0.05);
            padding: 1rem;
            border-radius: 6px;
            border-left: 4px solid var(--university-blue);
        }

        .password-requirements ul {
            margin: 0;
            padding-left: 1.2rem;
        }

        .password-strength {
            margin-top: 0.5rem;
            font-size: 0.875rem;
        }

        .strength-weak { color: var(--danger-red); }
        .strength-medium { color: var(--warning-orange); }
        .strength-strong { color: var(--success-green); }

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

        .btn-outline-secondary {
            color: var(--text-secondary);
            border-color: var(--border-light);
            padding: 0.75rem 1.5rem;
        }

        .btn-outline-secondary:hover {
            background: var(--text-secondary);
            border-color: var(--text-secondary);
        }

        .btn-outline-primary {
            color: var(--university-blue);
            border-color: var(--university-blue);
            padding: 0.75rem 1.5rem;
        }

        .btn-outline-primary:hover {
            background: var(--university-blue);
            border-color: var(--university-blue);
        }

        /* Alert Messages */
        .alert-info {
            background: rgba(30, 64, 175, 0.1);
            border-color: var(--university-blue);
            color: var(--university-blue);
            border-radius: 8px;
        }

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

            .btn-primary, .btn-outline-secondary, .btn-outline-primary {
                padding: 0.65rem 1.25rem;
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

            .btn-primary, .btn-outline-secondary, .btn-outline-primary {
                padding: 0.65rem 1rem;
                font-size: 0.9rem;
                width: 100%;
            }

            .d-flex.gap-3 {
                flex-direction: column;
                gap: 0.75rem !important;
            }

            .password-requirements {
                padding: 0.75rem;
                font-size: 0.8rem;
            }

            .password-requirements ul {
                padding-left: 1rem;
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
                <a class="nav-link active" href="manage_users.php">
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
                <a class="nav-link" href="settings.php">
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
    <div class="page-header d-flex justify-content-between align-items-start flex-wrap">
        <div>
            <h1 class="page-title">Add New User</h1>
            <p class="page-subtitle">Create a new user account with system access</p>
        </div>
        <div class="mt-3 mt-md-0">
            <a href="manage_users.php" class="btn btn-outline-primary">
                <i class="bi bi-arrow-left me-1"></i>Back to Users
            </a>
        </div>
    </div>

    <!-- Info Alert -->
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>
        <strong>Note:</strong> Users created by administrators are automatically approved and can login immediately.
    </div>

    <!-- Success/Error Messages -->
    <?php if (!empty($success)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i>
            <?php echo $success; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- User Form -->
    <form method="post" autocomplete="off">
        <!-- Personal Information -->
        <div class="form-section">
            <div class="section-header">
                <i class="bi bi-person me-2"></i>Personal Information
            </div>
            <div class="section-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="first_name" class="form-label">First Name *</label>
                        <input type="text" class="form-control" id="first_name" name="first_name" 
                               value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>" 
                               required autocomplete="off">
                    </div>
                    <div class="col-md-6">
                        <label for="last_name" class="form-label">Last Name *</label>
                        <input type="text" class="form-control" id="last_name" name="last_name" 
                               value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>" 
                               required autocomplete="off">
                    </div>
                    <div class="col-md-6">
                        <label for="username" class="form-label">Username *</label>
                        <input type="text" class="form-control" id="username" name="username" 
                               value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" 
                               required autocomplete="off">
                        <div class="form-text">Username cannot contain spaces</div>
                    </div>
                    <div class="col-md-6">
                        <label for="email" class="form-label">Email Address *</label>
                        <input type="email" class="form-control" id="email" name="email" 
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" 
                               required autocomplete="off">
                    </div>
                    <div class="col-md-6">
                        <label for="student_id" class="form-label">Student ID</label>
                        <input type="text" class="form-control" id="student_id" name="student_id"
                               value="<?php echo isset($_POST['student_id']) ? htmlspecialchars($_POST['student_id']) : ''; ?>" 
                               autocomplete="off">
                        <div class="form-text">Optional - only for students</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Security Information -->
        <div class="form-section">
            <div class="section-header">
                <i class="bi bi-shield-lock me-2"></i>Security Information
            </div>
            <div class="section-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="password" class="form-label">Password *</label>
                        <div class="password-toggle">
                            <input type="password" class="form-control" id="password" name="password" required autocomplete="new-password">
                            <i class="bi bi-eye-slash toggle-password" onclick="togglePassword('password', this)" title="Show/Hide Password"></i>
                        </div>
                        <div class="password-strength" id="passwordStrength"></div>
                    </div>
                    <div class="col-md-6">
                        <label for="confirm_password" class="form-label">Confirm Password *</label>
                        <div class="password-toggle">
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required autocomplete="new-password">
                            <i class="bi bi-eye-slash toggle-password" onclick="togglePassword('confirm_password', this)" title="Show/Hide Password"></i>
                        </div>
                        <div id="passwordMatch"></div>
                    </div>
                    <div class="col-12">
                        <div class="password-requirements">
                            <strong><i class="bi bi-info-circle me-1"></i>Password Requirements:</strong>
                            <ul class="mt-2 mb-0">
                                <li>At least <?php echo $min_length; ?> characters long</li>
                                <li>Contains uppercase letter</li>
                                <li>Contains lowercase letter</li>
                                <li>Contains number</li>
                                <?php if ($require_special): ?>
                                    <li>Contains special character</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Academic Information -->
        <div class="form-section">
            <div class="section-header">
                <i class="bi bi-mortarboard me-2"></i>Academic Information
            </div>
            <div class="section-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="role" class="form-label">Role *</label>
                        <select class="form-select" id="role" name="role" required>
                            <option value="" disabled <?php echo !isset($_POST['role']) ? 'selected' : ''; ?>>Select role</option>
                            <option value="student" <?php echo (isset($_POST['role']) && $_POST['role'] == 'student') ? 'selected' : ''; ?>>Student</option>
                            <option value="adviser" <?php echo (isset($_POST['role']) && $_POST['role'] == 'adviser') ? 'selected' : ''; ?>>Adviser&Panel</option>
                            <option value="panel" <?php echo (isset($_POST['role']) && $_POST['role'] == 'panel') ? 'selected' : ''; ?>>Panel Member Only</option>
                            <option value="admin" <?php echo (isset($_POST['role']) && $_POST['role'] == 'admin') ? 'selected' : ''; ?>>Administrator</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="college" class="form-label">College</label>
                        <select class="form-select" id="college" name="college">
                            <option value="" disabled <?php echo !isset($_POST['college']) ? 'selected' : ''; ?>>Select college</option>
                            <?php foreach ($colleges as $college_option): ?>
                                <option value="<?php echo htmlspecialchars($college_option); ?>" 
                                        <?php echo (isset($_POST['college']) && $_POST['college'] == $college_option) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($college_option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="department" class="form-label">Department</label>
                        <select class="form-select" id="department" name="department" disabled>
                            <option value="">Select college first</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Form Actions -->
        <div class="d-flex gap-3 justify-content-end">
            <button type="reset" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-clockwise me-1"></i>Reset
            </button>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-person-plus me-1"></i>Create User
            </button>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Notification functions
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

    // College-Department mapping from PHP
    const collegeDepartments = <?php echo json_encode($college_departments); ?>;

    // Username space prevention
    function preventSpaceInUsername() {
        const usernameField = document.getElementById('username');
        
        // Prevent space on keypress
        usernameField.addEventListener('keypress', function(e) {
            if (e.key === ' ' || e.keyCode === 32) {
                e.preventDefault();
                const feedback = document.createElement('div');
                feedback.className = 'text-danger small mt-1';
                feedback.textContent = 'Username cannot contain spaces';
                feedback.style.position = 'absolute';
                
                const existingFeedback = usernameField.parentNode.querySelector('.text-danger');
                if (existingFeedback && existingFeedback !== usernameField.nextElementSibling) {
                    existingFeedback.remove();
                }
                
                usernameField.parentNode.appendChild(feedback);
                
                setTimeout(() => {
                    if (feedback.parentNode) {
                        feedback.remove();
                    }
                }, 2000);
            }
        });
        
        usernameField.addEventListener('input', function(e) {
            this.value = this.value.replace(/\s/g, '');
        });
        
        usernameField.addEventListener('paste', function(e) {
            setTimeout(() => {
                this.value = this.value.replace(/\s/g, '');
            }, 1);
        });
    }

    // Toggle password visibility
    function togglePassword(fieldId, icon) {
        const field = document.getElementById(fieldId);
        const type = field.getAttribute('type') === 'password' ? 'text' : 'password';
        field.setAttribute('type', type);
        
        if (type === 'text') {
            icon.classList.remove('bi-eye-slash');
            icon.classList.add('bi-eye');
        } else {
            icon.classList.remove('bi-eye');
            icon.classList.add('bi-eye-slash');
        }
    }

    // Cascading dropdown functionality
    function setupCascadingDropdowns() {
        const collegeSelect = document.getElementById('college');
        const departmentSelect = document.getElementById('department');

        collegeSelect.addEventListener('change', function() {
            const selectedCollege = this.value;
            
            departmentSelect.innerHTML = '<option value="">Select department</option>';
            
            if (selectedCollege && collegeDepartments[selectedCollege]) {
                departmentSelect.disabled = false;
                
                collegeDepartments[selectedCollege].forEach(function(department) {
                    const option = document.createElement('option');
                    option.value = department;
                    option.textContent = department;
                    departmentSelect.appendChild(option);
                });
            } else {
                departmentSelect.disabled = true;
                departmentSelect.innerHTML = '<option value="">Select college first</option>';
            }
        });

        document.querySelector('button[type="reset"]').addEventListener('click', function() {
            setTimeout(() => {
                departmentSelect.disabled = true;
                departmentSelect.innerHTML = '<option value="">Select college first</option>';
            }, 10);
        });

        if (collegeSelect.value) {
            collegeSelect.dispatchEvent(new Event('change'));
            
            const selectedDepartment = '<?php echo isset($_POST['department']) ? htmlspecialchars($_POST['department']) : ''; ?>';
            if (selectedDepartment) {
                setTimeout(() => {
                    departmentSelect.value = selectedDepartment;
                }, 100);
            }
        }
    }

    // Password strength checker
    document.getElementById('password').addEventListener('input', function() {
        const password = this.value;
        const strengthDiv = document.getElementById('passwordStrength');
        const minLength = <?php echo $min_length; ?>;
        const requireSpecial = <?php echo $require_special ? 'true' : 'false'; ?>;
        
        let strength = 0;
        let message = '';
        
        if (password.length >= minLength) strength++;
        if (/[A-Z]/.test(password)) strength++;
        if (/[a-z]/.test(password)) strength++;
        if (/[0-9]/.test(password)) strength++;
        if (requireSpecial && /[^A-Za-z0-9]/.test(password)) strength++;
        
        const maxStrength = requireSpecial ? 5 : 4;
        
        if (password.length === 0) {
            message = '';
            strengthDiv.className = 'password-strength';
        } else if (strength < 2) {
            message = 'Weak password';
            strengthDiv.className = 'password-strength strength-weak';
        } else if (strength < maxStrength) {
            message = 'Medium password';
            strengthDiv.className = 'password-strength strength-medium';
        } else {
            message = 'Strong password';
            strengthDiv.className = 'password-strength strength-strong';
        }
        
        strengthDiv.textContent = message;
    });
    
    // Password confirmation check
    document.getElementById('confirm_password').addEventListener('input', function() {
        const password = document.getElementById('password').value;
        const confirmPassword = this.value;
        const matchDiv = document.getElementById('passwordMatch');
        
        if (confirmPassword) {
            if (password === confirmPassword) {
                matchDiv.innerHTML = '<small class="text-success"><i class="bi bi-check-circle"></i> Passwords match</small>';
            } else {
                matchDiv.innerHTML = '<small class="text-danger"><i class="bi bi-x-circle"></i> Passwords do not match</small>';
            }
        } else {
            matchDiv.innerHTML = '';
        }
    });

    // Form validation before submit
    document.querySelector('form').addEventListener('submit', function(e) {
        const requiredFields = ['first_name', 'last_name', 'username', 'email', 'password', 'confirm_password', 'role'];
        let missingFields = [];
        
        requiredFields.forEach(function(fieldName) {
            const field = document.getElementById(fieldName);
            if (!field.value.trim()) {
                missingFields.push(field.previousElementSibling.textContent.replace(' *', ''));
            }
        });
        
        if (missingFields.length > 0) {
            e.preventDefault();
            alert('Please fill in all required fields:\n- ' + missingFields.join('\n- '));
            return false;
        }
        
        const password = document.getElementById('password').value;
        const confirmPassword = document.getElementById('confirm_password').value;
        
        if (password !== confirmPassword) {
            e.preventDefault();
            alert('Passwords do not match. Please check your password fields.');
            return false;
        }
    });

    // Initialize all functionality when page loads
    document.addEventListener('DOMContentLoaded', function() {
        preventSpaceInUsername();
        setupCascadingDropdowns();
    });
</script>
</body>
</html>
