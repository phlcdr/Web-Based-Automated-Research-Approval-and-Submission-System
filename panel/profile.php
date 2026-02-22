<?php
// panel/profile.php - Complete Fixed Version with Working Notifications
session_start();

include_once '../config/database.php';
include_once '../includes/functions.php';

is_logged_in();
check_role(['adviser', 'panel']);

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$success_message = '';
$error_message = '';

// ============================================================================
// NOTIFICATION HANDLERS
// ============================================================================

// Handle AJAX request to mark notifications as read
if (isset($_POST['action']) && $_POST['action'] === 'mark_read') {
    $notification_id = (int)($_POST['notification_id'] ?? 0);
    
    if ($notification_id) {
        try {
            $stmt = $conn->prepare("
                UPDATE notifications 
                SET is_read = 1 
                WHERE notification_id = ? AND user_id = ?
            ");
            $stmt->execute([$notification_id, $user_id]);
            echo json_encode(['success' => true]);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }
    echo json_encode(['success' => false, 'error' => 'Invalid notification ID']);
    exit;
}

// Handle AJAX request to mark notifications as viewed (simplified)
if (isset($_POST['action']) && $_POST['action'] === 'mark_viewed') {
    echo json_encode(['success' => true]);
    exit;
}

// Handle AJAX request to get updated notification count
if (isset($_GET['get_count'])) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM notifications 
        WHERE user_id = ? AND is_read = 0
    ");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['count' => $result['count']]);
    exit;
}

// ============================================================================
// FORM PROCESSING
// ============================================================================

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['action'])) {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $college = trim($_POST['college']);
    $department = trim($_POST['department']);
    $current_password = isset($_POST['current_password']) ? $_POST['current_password'] : '';
    $new_password = isset($_POST['new_password']) ? $_POST['new_password'] : '';
    $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    
    // Validate required fields
    if (empty($first_name) || empty($last_name) || empty($email)) {
        $error_message = "First name, last name, and email are required.";
    } else {
        try {
            // Check if email is already taken by another user
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
            $stmt->execute([$email, $user_id]);
            if ($stmt->rowCount() > 0) {
                $error_message = "Email address is already taken by another user.";
            } else {
                // Update basic profile information
                $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, college = ?, department = ? WHERE user_id = ?");
                $stmt->execute([$first_name, $last_name, $email, $college, $department, $user_id]);
                
                // Update session data
                $_SESSION['full_name'] = $first_name . ' ' . $last_name;
                
                // Handle password change if requested
                if (!empty($current_password) || !empty($new_password) || !empty($confirm_password)) {
                    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                        $error_message = "All password fields are required to change password.";
                    } elseif ($new_password !== $confirm_password) {
                        $error_message = "New password and confirmation do not match.";
                    } elseif (strlen($new_password) < 6) {
                        $error_message = "New password must be at least 6 characters long.";
                    } else {
                        // Verify current password
                        $stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
                        $stmt->execute([$user_id]);
                        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if (!password_verify($current_password, $user_data['password'])) {
                            $error_message = "Current password is incorrect.";
                        } else {
                            // Update password
                            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                            $stmt->execute([$hashed_password, $user_id]);
                            
                            if (empty($error_message)) {
                                $success_message = "Profile updated successfully! Password has been changed.";
                            }
                        }
                    }
                } else {
                    $success_message = "Profile updated successfully!";
                }
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    }
}

// ============================================================================
// NOTIFICATION DATA FETCHING
// ============================================================================

// Get notification data from notifications table
$stmt = $conn->prepare("
    SELECT 
        notification_id,
        title as notification_title,
        message,
        type,
        context_type,
        context_id,
        created_at as submission_date
    FROM notifications 
    WHERE user_id = ? AND is_read = 0
    ORDER BY created_at DESC 
    LIMIT 10
");
$stmt->execute([$user_id]);
$recent_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total_unviewed = count($recent_notifications);

// Get counts for individual types
$stmt = $conn->prepare("
    SELECT 
        COUNT(CASE WHEN type = 'title' THEN 1 END) as titles,
        COUNT(CASE WHEN type = 'chapter' THEN 1 END) as chapters,
        COUNT(CASE WHEN type = 'discussion' THEN 1 END) as discussions
    FROM notifications 
    WHERE user_id = ? AND is_read = 0
");
$stmt->execute([$user_id]);
$counts = $stmt->fetch(PDO::FETCH_ASSOC);
$unviewed_titles_count = $counts['titles'];
$unviewed_chapters_count = $counts['chapters'];
$unviewed_discussions_count = $counts['discussions'];

// ============================================================================
// USER DATA AND STATISTICS
// ============================================================================

// Get current user information
$stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Get user statistics
$stats = [];

// Count assigned groups (for advisers) or reviewed titles (for panel)
if ($user_role === 'adviser') {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM research_groups WHERE adviser_id = ?");
    $stmt->execute([$user_id]);
    $stats['assigned_groups'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
} else {
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT s.submission_id) as count 
        FROM submissions s
        JOIN assignments a ON s.submission_id = a.context_id 
            AND a.context_type = 'submission' AND a.assignment_type = 'reviewer'
        WHERE a.user_id = ?
    ");
    $stmt->execute([$user_id]);
    $stats['assigned_groups'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
}

// Count pending titles for review
if ($user_role === 'adviser') {
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM submissions s
        JOIN research_groups rg ON s.group_id = rg.group_id
        WHERE rg.adviser_id = ? AND s.submission_type = 'title' AND s.status = 'pending'
    ");
    $stmt->execute([$user_id]);
} else {
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT s.submission_id) as count 
        FROM submissions s
        JOIN assignments a ON s.submission_id = a.context_id 
            AND a.context_type = 'submission' AND a.assignment_type = 'reviewer'
        WHERE a.user_id = ? AND s.submission_type = 'title' AND s.status = 'pending'
    ");
    $stmt->execute([$user_id]);
}
$stats['pending_titles'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Count pending chapters for review
if ($user_role === 'adviser') {
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM submissions s
        JOIN research_groups rg ON s.group_id = rg.group_id
        WHERE rg.adviser_id = ? AND s.submission_type = 'chapter' AND s.status = 'pending'
    ");
    $stmt->execute([$user_id]);
} else {
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT s.submission_id) as count 
        FROM submissions s
        JOIN assignments a ON s.submission_id = a.context_id 
            AND a.context_type = 'submission' AND a.assignment_type = 'reviewer'
        WHERE a.user_id = ? AND s.submission_type = 'chapter' AND s.status = 'pending'
    ");
    $stmt->execute([$user_id]);
}
$stats['pending_chapters'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpg" href="../assets/images/essu logo.png">
    <title>My Profile - ESSU Research System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Replace the entire <style> section in profile.php with this: -->
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
            --info-blue: #0284c7;
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

        /* University Header */
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

        /* Notification Bell System */
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
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
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
            position: relative;
        }

        .notification-item:hover {
            background: rgba(30, 64, 175, 0.05);
            color: inherit;
            text-decoration: none;
        }

        .notification-item:last-child {
            border-bottom: none;
        }

        .notification-item.viewed {
            opacity: 0.7;
            background: rgba(0,0,0,0.02);
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

        .notification-icon.title { background: rgba(30, 64, 175, 0.1); color: var(--university-blue); }
        .notification-icon.chapter { background: rgba(5, 150, 105, 0.1); color: var(--success-green); }
        .notification-icon.discussion { background: rgba(2, 132, 199, 0.1); color: var(--info-blue); }

        .notification-content {
            flex: 1;
        }

        .notification-title {
            font-weight: 600;
            font-size: 0.875rem;
            margin-bottom: 0.25rem;
            color: var(--text-primary);
            line-height: 1.3;
        }

        .notification-description {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-bottom: 0.25rem;
            line-height: 1.4;
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
            z-index: 10;
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
            flex-shrink: 0;
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
            flex-shrink: 0;
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
            overflow-x: auto;
            overflow-y: hidden;
            white-space: nowrap;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        .nav-tabs::-webkit-scrollbar {
            display: none;
        }

        .nav-tabs .nav-link {
            color: var(--text-secondary);
            font-weight: 500;
            border: none;
            padding: 1rem 1.5rem;
            border-radius: 0;
            position: relative;
            transition: all 0.3s ease;
            display: inline-block;
            white-space: nowrap;
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

        /* Academic Cards */
        .academic-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .card-header-academic {
            background: linear-gradient(90deg, var(--university-blue), #1e3a8a);
            color: white;
            padding: 1rem 1.5rem;
            font-weight: 600;
            font-size: 1rem;
        }

        .card-body-academic {
            padding: 2rem;
        }

        /* Profile Avatar */
        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--university-blue), var(--university-gold));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            font-weight: 700;
            color: white;
            margin: 0 auto 1.5rem;
        }

        /* Form Styling */
        .form-control-academic {
            border: 2px solid var(--border-light);
            border-radius: 8px;
            padding: 0.75rem 1rem;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }

        .form-control-academic:focus {
            border-color: var(--university-blue);
            box-shadow: 0 0 0 0.2rem rgba(30, 64, 175, 0.1);
        }

        .form-label-academic {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        /* Password Toggle */
        .password-toggle {
            position: relative;
        }

        .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--text-secondary);
            z-index: 10;
            transition: color 0.2s ease;
        }

        .toggle-password:hover {
            color: var(--university-blue);
        }

        .password-toggle input {
            padding-right: 45px;
        }

        /* Stat Cards */
        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border-left: 4px solid;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .stat-card.primary { border-left-color: var(--university-blue); }
        .stat-card.warning { border-left-color: var(--warning-orange); }
        .stat-card.success { border-left-color: var(--success-green); }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--text-secondary);
            font-weight: 500;
        }

        /* Alert Styling */
        .alert-academic {
            border: none;
            border-radius: 8px;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
        }

        .alert-academic.success {
            background: rgba(5, 150, 105, 0.1);
            color: var(--success-green);
            border-left: 4px solid var(--success-green);
        }

        .alert-academic.danger {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger-red);
            border-left: 4px solid var(--danger-red);
        }

        /* Loading animation */
        .notification-badge.updating {
            animation: spin 0.5s linear;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        @keyframes shake {
            0%, 50%, 100% { transform: rotate(0deg); }
            10%, 30% { transform: rotate(-10deg); }
            20%, 40% { transform: rotate(10deg); }
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
                line-height: 1.3; 
            }
            
            .university-logo { 
                width: 40px; 
                height: 40px; 
                margin-right: 10px; 
            }
            
            .page-title { 
                font-size: 1.5rem; 
            }
            
            .page-subtitle { 
                font-size: 0.9rem; 
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
            
            .card-body-academic {
                padding: 1.5rem;
            }
            
            .profile-avatar {
                width: 100px;
                height: 100px;
                font-size: 2rem;
            }
            
            .stat-number {
                font-size: 1.8rem;
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
            
            .card-body-academic {
                padding: 1rem;
            }
            
            .profile-avatar {
                width: 80px;
                height: 80px;
                font-size: 1.5rem;
                margin-bottom: 1rem;
            }
            
            .stat-number {
                font-size: 1.5rem;
            }
            
            .stat-label {
                font-size: 0.8rem;
            }
            
            .btn {
                font-size: 0.875rem;
                padding: 0.5rem 0.75rem;
            }
        }

        /* EXTRA SMALL - 450x689 */
        @media (max-width: 450px) {
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
            
            .page-subtitle {
                font-size: 0.8rem;
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
            
            .profile-avatar {
                width: 70px;
                height: 70px;
                font-size: 1.25rem;
            }
            
            .stat-number {
                font-size: 1.3rem;
            }
            
            .stat-label {
                font-size: 0.75rem;
            }
            
            .card-body-academic {
                padding: 0.75rem;
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
    <!-- University Header - REPLACE THE EXISTING HEADER SECTION -->
    <header class="university-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <a href="dashboard.php" class="university-brand">
                    <img src="../assets/images/essu logo.png" alt="ESSU Logo" class="university-logo">
                    <div>
                        <div class="university-name">EASTERN SAMAR STATE UNIVERSITY</div>
                    </div>
                </a>
                
                <div class="notification-section">
                    <!-- Functional Notification Bell -->
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
                                    <span><i class="bi bi-bell me-2"></i>Notifications</span>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-light text-dark" id="notificationCount"><?php echo $total_unviewed; ?> new</span>
                                        <?php if ($total_unviewed > 0): ?>
                                            <button class="btn btn-sm btn-light" id="markAllReadBtn" title="Mark all as read" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">
                                                <i class="bi bi-check-all"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </li>
                            
                            <div id="notificationList">
                                <?php if (count($recent_notifications) > 0): ?>
                                    <?php foreach ($recent_notifications as $notif): ?>
                                        <li>
                                            <a href="<?php 
                                                if ($notif['type'] == 'title' || ($notif['context_type'] == 'submission' && strpos($notif['message'], 'title') !== false)) {
                                                    echo 'review_titles.php';
                                                } elseif ($notif['type'] == 'chapter' || ($notif['context_type'] == 'submission' && strpos($notif['message'], 'chapter') !== false)) {
                                                    echo 'review_chapters.php';
                                                } elseif ($notif['type'] == 'discussion' || $notif['context_type'] == 'discussion') {
                                                    echo 'thesis_inbox.php';
                                                } else {
                                                    echo 'dashboard.php';
                                                }
                                            ?>" class="notification-item" 
                                            data-notification-id="<?php echo $notif['notification_id']; ?>"
                                            data-type="<?php echo $notif['type']; ?>">
                                                <div class="d-flex align-items-start">
                                                    <div class="notification-icon <?php echo $notif['type']; ?>">
                                                        <i class="bi bi-<?php 
                                                            if ($notif['type'] == 'title' || strpos($notif['message'], 'title') !== false) {
                                                                echo 'journal-text';
                                                            } elseif ($notif['type'] == 'chapter' || strpos($notif['message'], 'chapter') !== false) {
                                                                echo 'file-earmark-text';
                                                            } elseif ($notif['type'] == 'discussion') {
                                                                echo 'chat-square-text';
                                                            } else {
                                                                echo 'bell';
                                                            }
                                                        ?>"></i>
                                                    </div>
                                                    <div class="notification-content">
                                                        <div class="notification-title">
                                                            <?php echo htmlspecialchars($notif['notification_title']); ?>
                                                        </div>
                                                        <div class="notification-description">
                                                            <?php echo htmlspecialchars(substr($notif['message'], 0, 60)); ?><?php echo strlen($notif['message']) > 60 ? '...' : ''; ?>
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
                                    <div class="text-muted">
                                        <strong><?php echo $unviewed_titles_count; ?></strong> titles • 
                                        <strong><?php echo $unviewed_chapters_count; ?></strong> chapters • 
                                        <strong><?php echo $unviewed_discussions_count; ?></strong> discussions
                                    </div>
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
                                <div class="user-role"><?php echo $user_role === 'adviser' ? 'Faculty Member' : 'Panel Member'; ?></div>
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
                    <a class="nav-link" href="review_titles.php">
                        <i class="bi bi-journal-check me-1 me-md-2"></i>
                        <span class="d-none d-sm-inline">Review Titles</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="review_chapters.php">
                        <i class="bi bi-file-earmark-text me-1 me-md-2"></i>
                        <span class="d-none d-sm-inline">Review Chapters</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="thesis_inbox.php">
                        <i class="bi bi-chat-square-text me-1 me-md-2"></i>
                        <span class="d-none d-sm-inline">Thesis Inbox</span>
                    </a>
                </li>
                <?php if ($user_role === 'adviser'): ?>
                <li class="nav-item">
                    <a class="nav-link" href="my_groups.php">
                        <i class="bi bi-people me-1 me-md-2"></i>
                        <span class="d-none d-sm-inline">My Groups</span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">My Profile</h1>
            <p class="page-subtitle">Manage your account information, security settings, and view your academic activity</p>
        </div>

        <?php if ($success_message): ?>
            <div class="alert-academic success">
                <i class="bi bi-check-circle me-2"></i><?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert-academic danger">
                <i class="bi bi-exclamation-circle me-2"></i><?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Profile Information Card -->
            <div class="col-lg-4">
                <div class="academic-card">
                    <div class="card-header-academic">
                        <i class="bi bi-person-badge me-2"></i>Profile Information
                    </div>
                    <div class="card-body-academic text-center">
                        <div class="profile-avatar">
                            <?php echo strtoupper(substr($_SESSION['full_name'], 0, 2)); ?>
                        </div>
                        <h4 class="mb-2"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h4>
                        <p class="text-muted mb-3"><?php echo htmlspecialchars($user['email']); ?></p>
                        <span class="badge bg-<?php echo $user['role'] == 'adviser' ? 'success' : 'info'; ?> fs-6 px-3 py-2">
                            <i class="bi bi-<?php echo $user['role'] == 'adviser' ? 'person-check' : 'people'; ?> me-1"></i>
                            <?php echo $user['role'] === 'adviser' ? 'Research Adviser' : 'Panel Member'; ?>
                        </span>
                        
                        <hr class="my-4">
                        
                        <div class="row text-center">
                            <div class="col-6">
                                <div class="border-end">
                                    <div class="h5 mb-1 text-primary"><?php echo date('M Y', strtotime($user['created_at'])); ?></div>
                                    <small class="text-muted">Member Since</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="h5 mb-1 text-success">
                                    <?php echo ucfirst($user['registration_status']); ?>
                                </div>
                                <small class="text-muted">Account Status</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Edit Profile Form -->
            <div class="col-lg-8">
                <div class="academic-card">
                    <div class="card-header-academic">
                        <i class="bi bi-pencil-square me-2"></i>Edit Profile Information
                    </div>
                    <div class="card-body-academic">
                        <form method="POST" action="">
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label for="first_name" class="form-label-academic">
                                        First Name <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control form-control-academic" 
                                           id="first_name" name="first_name" 
                                           value="<?php echo htmlspecialchars($user['first_name']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="last_name" class="form-label-academic">
                                        Last Name <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control form-control-academic" 
                                           id="last_name" name="last_name" 
                                           value="<?php echo htmlspecialchars($user['last_name']); ?>" required>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label for="email" class="form-label-academic">
                                    Email Address <span class="text-danger">*</span>
                                </label>
                                <input type="email" class="form-control form-control-academic" 
                                       id="email" name="email" 
                                       value="<?php echo htmlspecialchars($user['email']); ?>" required>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="college" class="form-label">College</label>
                                    <input type="text" class="form-control" id="college" name="college" 
                                        value="<?php echo htmlspecialchars($user['college'] ?? 'Not Set'); ?>" 
                                        readonly style="background-color: #f8f9fa; cursor: not-allowed;">
                                    <small class="text-muted">
                                        <i class="bi bi-info-circle me-1"></i>Contact admin to update college
                                    </small>
                                </div>
                                <div class="col-md-6">
                                    <label for="department" class="form-label">Program/Course</label>
                                    <input type="text" class="form-control" id="department" name="department" 
                                        value="<?php echo htmlspecialchars($user['department'] ?? 'Not Set'); ?>" 
                                        readonly style="background-color: #f8f9fa; cursor: not-allowed;">
                                    <small class="text-muted">
                                        <i class="bi bi-info-circle me-1"></i>Contact admin to update program
                                    </small>
                                </div>
                            </div>

                            <hr class="my-4">

                            <h6 class="text-primary mb-3">
                                <i class="bi bi-key me-2"></i>Change Password (Optional)
                            </h6>
                            <p class="small text-muted mb-3">Leave password fields empty if you don't want to change your password.</p>

                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <label for="current_password" class="form-label-academic">Current Password</label>
                                    <div class="password-toggle">
                                        <input type="password" class="form-control form-control-academic" 
                                               id="current_password" name="current_password">
                                        <i class="bi bi-eye-slash toggle-password" 
                                           onclick="togglePassword('current_password', this)" 
                                           title="Show/Hide Password"></i>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label for="new_password" class="form-label-academic">New Password</label>
                                    <div class="password-toggle">
                                        <input type="password" class="form-control form-control-academic" 
                                               id="new_password" name="new_password" minlength="6">
                                        <i class="bi bi-eye-slash toggle-password" 
                                           onclick="togglePassword('new_password', this)" 
                                           title="Show/Hide Password"></i>
                                    </div>
                                    <small class="text-muted">Minimum 6 characters</small>
                                </div>
                                <div class="col-md-4">
                                    <label for="confirm_password" class="form-label-academic">Confirm New Password</label>
                                    <div class="password-toggle">
                                        <input type="password" class="form-control form-control-academic" 
                                               id="confirm_password" name="confirm_password" minlength="6">
                                        <i class="bi bi-eye-slash toggle-password" 
                                           onclick="togglePassword('confirm_password', this)" 
                                           title="Show/Hide Password"></i>
                                    </div>
                                </div>
                            </div>

                            <div class="d-grid d-md-flex justify-content-md-end">
                                <button type="submit" class="btn btn-primary px-4 py-2">
                                    <i class="bi bi-check-lg me-1"></i>Update Profile
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Work Statistics -->
        <div class="row g-4 mt-2">
            <div class="col-12">
                <div class="academic-card">
                    <div class="card-header-academic">
                        <i class="bi bi-bar-chart me-2"></i>Academic Activity Overview
                    </div>
                    <div class="card-body-academic">
                        <div class="row g-4">
                            <div class="col-md-4">
                                <div class="stat-card primary">
                                    <i class="bi bi-people text-primary mb-3" style="font-size: 2rem;"></i>
                                    <div class="stat-number text-primary"><?php echo $stats['assigned_groups']; ?></div>
                                    <div class="stat-label">
                                        <?php echo $user_role === 'adviser' ? 'Assigned Groups' : 'Assigned Reviews'; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="stat-card warning">
                                    <i class="bi bi-journal-text text-warning mb-3" style="font-size: 2rem;"></i>
                                    <div class="stat-number text-warning"><?php echo $stats['pending_titles']; ?></div>
                                    <div class="stat-label">Pending Title Reviews</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="stat-card success">
                                    <i class="bi bi-file-text text-success mb-3" style="font-size: 2rem;"></i>
                                    <div class="stat-number text-success"><?php echo $stats['pending_chapters']; ?></div>
                                    <div class="stat-label">Pending Chapter Reviews</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Account Information -->
        <div class="academic-card">
            <div class="card-header-academic">
                <i class="bi bi-info-circle me-2"></i>Account Information & Settings
            </div>
            <div class="card-body-academic">
                <div class="row g-4">
                    <div class="col-md-3">
                        <div class="border rounded p-3 h-100">
                            <h6 class="text-primary mb-2">User ID</h6>
                            <p class="mb-0 text-muted"><?php echo $user['user_id']; ?></p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded p-3 h-100">
                            <h6 class="text-primary mb-2">Account Created</h6>
                            <p class="mb-0 text-muted"><?php echo date('F j, Y', strtotime($user['created_at'])); ?></p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded p-3 h-100">
                            <h6 class="text-primary mb-2">Registration Status</h6>
                            <span class="badge bg-success"><?php echo ucfirst($user['registration_status']); ?></span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded p-3 h-100">
                            <h6 class="text-primary mb-2">Account Role</h6>
                            <span class="badge bg-<?php echo $user['role'] == 'adviser' ? 'success' : 'info'; ?>">
                                <?php echo $user['role'] === 'adviser' ? 'Research Adviser' : 'Panel Member'; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Notification system functionality
        function markNotificationAsRead(notificationId) {
            return fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=mark_read&notification_id=${notificationId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateNotificationCount();
                }
                return data.success;
            })
            .catch(error => {
                console.error('Error marking notification as read:', error);
                return false;
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
                            setTimeout(() => {
                                badge.style.display = 'none';
                            }, 300);
                        }
                        if (countElement) {
                            countElement.textContent = '0 new';
                        }
                    } else {
                        if (badge) {
                            badge.style.display = 'flex';
                            badge.style.opacity = '1';
                            badge.textContent = Math.min(data.count, 99);
                            
                            badge.classList.add('updating');
                            setTimeout(() => {
                                badge.classList.remove('updating');
                            }, 500);
                        }
                        if (countElement) {
                            countElement.textContent = `${data.count} new`;
                        }
                    }
                    
                    return data.count;
                })
                .catch(error => {
                    console.error('Error updating notification count:', error);
                    return null;
                });
        }

        // Handle notification item clicks
        document.addEventListener('click', function(e) {
            const notificationItem = e.target.closest('.notification-item');
            if (notificationItem) {
                // Check if this is actually a link element with a valid href
                if (!notificationItem.href || notificationItem.href.includes('undefined')) {
                    return; // Don't do anything for non-link notification items
                }
                
                e.preventDefault();
                
                if (notificationItem.dataset.notificationId) {
                    const notificationId = notificationItem.dataset.notificationId;
                    const href = notificationItem.href;
                    
                    markNotificationAsRead(notificationId).then(success => {
                        if (success) {
                            notificationItem.classList.add('viewed');
                            window.location.href = href;
                        } else {
                            window.location.href = href;
                        }
                    });
                } else {
                    window.location.href = notificationItem.href;
                }
            }
        });

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

        // Password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            
            if (newPassword && confirmPassword && newPassword !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
                this.classList.add('is-invalid');
            } else {
                this.setCustomValidity('');
                this.classList.remove('is-invalid');
            }
        });

        // Show/hide password requirements
        document.getElementById('new_password').addEventListener('input', function() {
            const password = this.value;
            const confirmField = document.getElementById('confirm_password');
            
            if (password.length > 0 && password.length < 6) {
                this.setCustomValidity('Password must be at least 6 characters long');
                this.classList.add('is-invalid');
            } else {
                this.setCustomValidity('');
                this.classList.remove('is-invalid');
            }
            
            if (confirmField.value) {
                confirmField.dispatchEvent(new Event('input'));
            }
        });

        // Form submission animation
        document.querySelector('form').addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Updating...';
            submitBtn.disabled = true;
            
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 3000);
        });

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            const bellButton = document.getElementById('notificationBell');
            if (bellButton && <?php echo $total_unviewed; ?> > 0) {
                bellButton.style.animation = 'shake 0.5s ease-in-out';
            }
            
            setInterval(updateNotificationCount, 30000);
        });
    </script>
</body>
</html>