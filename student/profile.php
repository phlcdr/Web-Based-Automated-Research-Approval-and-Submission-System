<?php
session_start();
include_once '../config/database.php';
include_once '../includes/functions.php';

is_logged_in();
check_role(['student']);

$user_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';
// Handle marking ALL notifications as read
if (isset($_POST['action']) && $_POST['action'] === 'mark_all_read') {
    try {
        $stmt = $conn->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->execute([$user_id]);
        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}
// Handle marking notifications as read
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

// Handle AJAX notification requests - simplified for normalized database
if (isset($_POST['action']) && $_POST['action'] === 'mark_viewed') {
    // Return success - using notifications table for tracking instead
    echo json_encode(['success' => true]);
    exit;
}

if (isset($_GET['get_count'])) {
    $total_unviewed = 0;
    
    // Count unread notifications from notifications table
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM notifications 
        WHERE user_id = ? AND is_read = 0
    ");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_unviewed = $result['count'];
    
    echo json_encode(['count' => $total_unviewed]);
    exit;
}

// Handle profile picture upload
if (isset($_POST['upload_picture'])) {
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == 0) {
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        $max_size = 5 * 1024 * 1024;
        
        if (!in_array($_FILES['profile_picture']['type'], $allowed_types)) {
            $error_message = "Only JPG, PNG, and GIF files are allowed.";
        } elseif ($_FILES['profile_picture']['size'] > $max_size) {
            $error_message = "File size must be less than 5MB.";
        } else {
            $upload_dir = '../uploads/profile_pictures/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_extension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
            $new_filename = 'profile_' . $user_id . '_' . time() . '.' . $file_extension;
            $upload_path = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_path)) {
                $stmt = $conn->prepare("SELECT profile_picture FROM users WHERE user_id = ?");
                $stmt->execute([$user_id]);
                $old_picture = $stmt->fetchColumn();
                
                if ($old_picture && file_exists('../uploads/profile_pictures/' . $old_picture)) {
                    unlink('../uploads/profile_pictures/' . $old_picture);
                }
                
                $stmt = $conn->prepare("UPDATE users SET profile_picture = ? WHERE user_id = ?");
                if ($stmt->execute([$new_filename, $user_id])) {
                    $success_message = "Profile picture updated successfully!";
                } else {
                    $error_message = "Failed to update profile picture in database.";
                }
            } else {
                $error_message = "Failed to upload file.";
            }
        }
    } else {
        $error_message = "Please select a valid image file.";
    }
}

// Handle profile picture removal
if (isset($_POST['remove_picture'])) {
    $stmt = $conn->prepare("SELECT profile_picture FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $current_picture = $stmt->fetchColumn();
    
    if ($current_picture && file_exists('../uploads/profile_pictures/' . $current_picture)) {
        unlink('../uploads/profile_pictures/' . $current_picture);
    }
    
    $stmt = $conn->prepare("UPDATE users SET profile_picture = NULL WHERE user_id = ?");
    if ($stmt->execute([$user_id])) {
        $success_message = "Profile picture removed successfully!";
    } else {
        $error_message = "Failed to remove profile picture.";
    }
}

// Handle profile form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['upload_picture']) && !isset($_POST['remove_picture'])) {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $student_id = trim($_POST['student_id']);
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Note: college and department are now read-only and not included in form processing
    
    if (empty($first_name) || empty($last_name) || empty($email)) {
        $error_message = "First name, last name, and email are required.";
    } else {
        try {
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
            $stmt->execute([$email, $user_id]);
            if ($stmt->rowCount() > 0) {
                $error_message = "Email address is already taken by another user.";
            } else {
                // Update only editable fields (excluding college and department)
                $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, student_id = ? WHERE user_id = ?");
                $stmt->execute([$first_name, $last_name, $email, $student_id, $user_id]);
                
                $_SESSION['full_name'] = $first_name . ' ' . $last_name;
                
                if (!empty($current_password) || !empty($new_password) || !empty($confirm_password)) {
                    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                        $error_message = "All password fields are required to change password.";
                    } elseif ($new_password !== $confirm_password) {
                        $error_message = "New password and confirmation do not match.";
                    } elseif (strlen($new_password) < 6) {
                        $error_message = "New password must be at least 6 characters long.";
                    } else {
                        $stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
                        $stmt->execute([$user_id]);
                        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if (!password_verify($current_password, $user_data['password'])) {
                            $error_message = "Current password is incorrect.";
                        } else {
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

// Get notification data from notifications table
$total_unviewed = 0;
$recent_notifications = [];

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
    LIMIT 5
");
$stmt->execute([$user_id]);
$recent_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total_unviewed = count($recent_notifications);

// Get current user information
$stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Get group information
$stmt = $conn->prepare("SELECT * FROM research_groups WHERE lead_student_id = ?");
$stmt->execute([$user_id]);
$group = $stmt->fetch(PDO::FETCH_ASSOC);

$title = null;
$chapters = [];
if ($group) {
    // Get latest title submission
    $stmt = $conn->prepare("SELECT * FROM submissions WHERE group_id = ? AND submission_type = 'title' ORDER BY submission_date DESC LIMIT 1");
    $stmt->execute([$group['group_id']]);
    $title = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($title) {
        // Get chapter submissions
        $stmt = $conn->prepare("SELECT * FROM submissions WHERE group_id = ? AND submission_type = 'chapter' ORDER BY chapter_number ASC");
        $stmt->execute([$group['group_id']]);
        $chapters = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

function getProfilePictureUrl($profile_picture) {
    if ($profile_picture && file_exists('../uploads/profile_pictures/' . $profile_picture)) {
        return '../uploads/profile_pictures/' . $profile_picture;
    }
    return null;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - ESSU Research System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --university-blue: #1e40af;
            --university-gold: #f59e0b;
            --light-gray: #f8fafc;
            --text-primary: #111827;
            --text-secondary: #6b7280;
            --success-green: #059669;
            --danger-red: #dc2626;
            --border-light: #e5e7eb;
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
        }

        .university-logo {
            width: 50px;
            height: 50px;
            margin-right: 15px;
            border-radius: 8px;
        }

        .university-name {
            font-size: 1.3rem;
            font-weight: 700;
            line-height: 1.2;
        }

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

        #markAllReadBtn {
            border: none;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            transition: all 0.2s ease;
        }

        #markAllReadBtn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.05);
        }

        #markAllReadBtn:active {
            transform: scale(0.95);
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

        .notification-icon.submission { background: rgba(30, 64, 175, 0.1); color: var(--university-blue); }
        .notification-icon.discussion { background: rgba(5, 150, 105, 0.1); color: var(--success-green); }
        .notification-icon.group { background: rgba(245, 158, 11, 0.1); color: var(--university-gold); }
        .notification-icon.general { background: rgba(107, 114, 128, 0.1); color: var(--text-secondary); }

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
            overflow: hidden;
        }

        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
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

        .dashboard-section {
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
            padding: 1.5rem;
        }

        .profile-picture-section {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border-left: 4px solid var(--university-blue);
            text-align: center;
            margin-bottom: 2rem;
        }

        .profile-avatar-large {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--university-blue), #1d4ed8);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 3rem;
            margin: 0 auto 1rem;
            border: 4px solid var(--university-gold);
            overflow: hidden;
        }

        .profile-avatar-large img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .form-control, .form-select {
            border: 2px solid var(--border-light);
            border-radius: 8px;
            padding: 0.75rem;
            transition: all 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--university-blue);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .btn {
            border-radius: 6px;
            font-weight: 500;
            padding: 0.5rem 1rem;
            border: none;
            transition: all 0.2s ease;
        }

        .btn-primary {
            background: var(--university-blue);
            color: white;
        }

        .btn-primary:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
        }

        .alert {
            border-radius: 8px;
            border: none;
            padding: 1rem;
        }

        .alert-success {
            background: #f0fdf4;
            color: #059669;
            border-left: 4px solid var(--success-green);
        }

        .alert-danger {
            background: #fef2f2;
            color: #dc2626;
            border-left: 4px solid var(--danger-red);
        }

        .dropdown-menu {
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            border: 1px solid var(--border-light);
        }

        .password-toggle {
            position: relative;
        }

        .toggle-password {
            position: absolute;
            top: 50%;
            right: 15px;
            transform: translateY(-50%);
            cursor: pointer;
            color: #6c757d;
            z-index: 10;
        }

        .password-toggle input {
            padding-right: 45px;
        }


        /* RESPONSIVE - TABLET (768px and below) */
        @media (max-width: 768px) {
            .notification-dropdown {
                min-width: 320px;
                max-width: 350px;
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
            
            .profile-avatar-large {
                width: 120px;
                height: 120px;
                font-size: 2.5rem;
            }
            
            .section-header {
                padding: 0.75rem 1rem;
                font-size: 0.9rem;
            }
            
            .section-body {
                padding: 1rem;
            }
            
            .profile-picture-section {
                padding: 1rem;
            }
            
            .card-body {
                padding: 1rem !important;
            }
        }

        @media (max-width: 576px) {
            .user-info {
                display: none;
            }
            
            .university-header {
                padding: 0.75rem 0;
            }
            
            .university-name {
                font-size: 0.85rem;
            }
            
            .university-logo {
                width: 35px;
                height: 35px;
            }
            
            .notification-bell {
                font-size: 1.25rem;
                padding: 0.25rem;
            }
            
            .user-avatar {
                width: 32px;
                height: 32px;
            }
            
            /* Enhanced Navigation Tabs - Same as Submit Title and Dashboard */
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
            
            /* Show all navigation text on mobile */
            .nav-tabs .nav-link .d-none.d-sm-inline {
                display: inline !important;
            }
            
            /* Maintain active state styling on mobile */
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
            
            .notification-section {
                gap: 0.5rem;
            }
            
            .profile-avatar-large {
                width: 100px;
                height: 100px;
                font-size: 2rem;
                border: 3px solid var(--university-gold);
            }
            
            .profile-picture-section {
                padding: 1rem;
                margin-bottom: 1rem;
            }
            
            .profile-picture-section h4 {
                font-size: 1.1rem;
            }
            
            .profile-picture-section .badge {
                font-size: 0.8rem !important;
            }
            
            .section-header {
                padding: 0.75rem 1rem;
                font-size: 0.85rem;
            }
            
            .section-body {
                padding: 1rem;
            }
            
            .form-label {
                font-size: 0.9rem;
                margin-bottom: 0.5rem;
            }
            
            .form-control, .form-select {
                font-size: 0.9rem;
                padding: 0.6rem;
            }
            
            .btn {
                font-size: 0.9rem;
                padding: 0.6rem 1rem;
            }
            
            .btn-sm {
                font-size: 0.8rem;
                padding: 0.4rem 0.8rem;
            }
            
            .card {
                margin-bottom: 0.75rem;
            }
            
            .card-body {
                padding: 0.75rem !important;
            }
            
            .member-avatar {
                width: 40px !important;
                height: 40px !important;
                margin-right: 0.5rem !important;
            }
            
            .card-body h6 {
                font-size: 0.95rem;
            }
            
            .card-body .badge {
                font-size: 0.65rem !important;
            }
            
            .card-body .small {
                font-size: 0.8rem !important;
            }
            
            .toggle-password {
                right: 10px;
                font-size: 1.1rem;
            }
            
            .password-toggle input {
                padding-right: 40px;
            }
            
            .alert {
                font-size: 0.85rem;
                padding: 0.75rem;
            }
            
            .stat-card {
                padding: 0.75rem;
                margin-bottom: 0.5rem;
            }
            
            .stat-card h3 {
                font-size: 1.5rem;
            }
            
            .stat-card p {
                font-size: 0.85rem;
            }
            
            .file-input-wrapper {
                margin-bottom: 0.5rem;
            }
            
            .row.mb-3 {
                margin-bottom: 1rem !important;
            }
            
            .d-md-flex.justify-content-md-end {
                display: flex !important;
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .d-md-flex.justify-content-md-end .btn {
                width: 100%;
            }
            
            .alert .row {
                row-gap: 0.75rem;
            }
            
            .alert .col-md-4 {
                font-size: 0.85rem;
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
                                                if (strpos($notif['type'], 'title') !== false) echo 'submit_title.php';
                                                elseif (strpos($notif['type'], 'chapter') !== false) echo 'submit_chapter.php';
                                                else echo 'thesis_discussion.php';
                                            ?>" 
                                            class="notification-item" 
                                            data-notification-id="<?php echo $notif['notification_id']; ?>">
                                                <div class="d-flex align-items-start">
                                                    <div class="notification-icon <?php echo $notif['context_type'] ?? 'general'; ?>">
                                                        <i class="bi bi-<?php 
                                                            switch($notif['context_type']) {
                                                                case 'submission':
                                                                    echo 'journal-check';
                                                                    break;
                                                                case 'discussion':
                                                                    echo 'chat-square-text';
                                                                    break;
                                                                case 'group':
                                                                    echo 'people';
                                                                    break;
                                                                default:
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
                                                            <?php echo $notif['submission_date'] ? date('M d, g:i A', strtotime($notif['submission_date'])) : 'Recently'; ?>
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
                                <?php if ($user['profile_picture'] && getProfilePictureUrl($user['profile_picture'])): ?>
                                    <img src="<?php echo getProfilePictureUrl($user['profile_picture']); ?>" alt="Profile">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($_SESSION['full_name'], 0, 2)); ?>
                                <?php endif; ?>
                            </div>
                            <div class="user-info d-none d-md-block">
                                <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                                <div class="user-role">Student</div>
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
                <?php if (!$group): ?>
                <li class="nav-item">
                    <a class="nav-link" href="create_group.php">
                        <i class="bi bi-people me-1 me-md-2"></i>
                        <span class="d-none d-sm-inline">Create Group</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($group): ?>
                <li class="nav-item">
                    <a class="nav-link" href="submit_title.php">
                        <i class="bi bi-journal-plus me-1 me-md-2"></i>
                        <span class="d-none d-sm-inline">Submit Title</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="submit_chapter.php">
                        <i class="bi bi-file-text me-1 me-md-2"></i>
                        <span class="d-none d-sm-inline">Submit Chapter</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="thesis_discussion.php">
                        <i class="bi bi-chat-square-text me-1 me-md-2"></i>
                        <span class="d-none d-sm-inline">Discussion</span>
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
            <p class="page-subtitle">Manage your account information and research details</p>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle me-2"></i><?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-circle me-2"></i><?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Profile Picture Section -->
            <div class="col-lg-4 mb-4">
                <div class="profile-picture-section">
                    <div class="profile-avatar-large">
                        <?php if ($user['profile_picture'] && getProfilePictureUrl($user['profile_picture'])): ?>
                            <img src="<?php echo getProfilePictureUrl($user['profile_picture']); ?>" alt="Profile Picture">
                        <?php else: ?>
                            <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <h4 class="mb-1"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h4>
                    <p class="text-muted mb-2"><?php echo htmlspecialchars($user['email']); ?></p>
                    <span class="badge bg-primary fs-6">
                        <i class="bi bi-mortarboard me-1"></i><?php echo ucfirst($user['role']); ?>
                    </span>
                    
                    <div class="profile-picture-upload mt-3">
                        <form method="POST" enctype="multipart/form-data" class="d-inline">
                            <div class="file-input-wrapper">
                                <input type="file" id="profile_picture" name="profile_picture" accept="image/*" onchange="this.form.submit()" style="display: none;">
                                <label for="profile_picture" class="btn btn-primary btn-sm">
                                    <i class="bi bi-camera me-1"></i>Upload Photo
                                </label>
                            </div>
                            <input type="hidden" name="upload_picture" value="1">
                        </form>
                        
                        <?php if ($user['profile_picture']): ?>
                        <form method="POST" class="d-inline ms-2">
                            <button type="submit" name="remove_picture" class="btn btn-danger btn-sm" 
                                    onclick="return confirm('Are you sure you want to remove your profile picture?')">
                                <i class="bi bi-trash me-1"></i>Remove
                            </button>
                        </form>
                        <?php endif; ?>
                        
                        <div class="mt-2">
                            <small class="text-muted">
                                <i class="bi bi-info-circle me-1"></i>
                                Max size: 5MB. Formats: JPG, PNG, GIF
                            </small>
                        </div>
                    </div>
                    
                    <hr class="my-3">
                    
                    <div class="row text-center">
                        <div class="col-6">
                            <small class="text-muted">Member Since</small>
                            <div class="fw-bold"><?php echo date('M Y', strtotime($user['created_at'])); ?></div>
                        </div>
                        <div class="col-6">
                            <small class="text-muted">Status</small>
                            <div class="fw-bold text-success">
                                <?php echo ucfirst($user['registration_status']); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Edit Profile Form -->
            <div class="col-lg-8 mb-4">
                <div class="dashboard-section">
                    <div class="section-header">
                        <i class="bi bi-pencil-square me-2"></i>Edit Profile Information
                    </div>
                    <div class="section-body">
                        <form method="POST" action="">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="first_name" name="first_name" 
                                           value="<?php echo htmlspecialchars($user['first_name']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="last_name" name="last_name" 
                                           value="<?php echo htmlspecialchars($user['last_name']); ?>" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="email" name="email" 
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

                            <div class="mb-3">
                                <label for="student_id" class="form-label">Student ID</label>
                                <input type="text" class="form-control" id="student_id" name="student_id" 
                                       value="<?php echo htmlspecialchars($user['student_id'] ?? ''); ?>" 
                                       placeholder="e.g., 22-01307">
                            </div>

                            <hr class="my-4">

                            <h6 class="text-muted mb-3">
                                <i class="bi bi-key me-2"></i>Change Password (Optional)
                            </h6>
                            <p class="small text-muted">Leave password fields empty if you don't want to change your password.</p>

                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <label for="current_password" class="form-label">Current Password</label>
                                    <div class="password-toggle">
                                        <input type="password" class="form-control" id="current_password" name="current_password">
                                        <i class="bi bi-eye-slash toggle-password" onclick="togglePassword('current_password', this)" title="Show/Hide Password"></i>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label for="new_password" class="form-label">New Password</label>
                                    <div class="password-toggle">
                                        <input type="password" class="form-control" id="new_password" name="new_password" minlength="6">
                                        <i class="bi bi-eye-slash toggle-password" onclick="togglePassword('new_password', this)" title="Show/Hide Password"></i>
                                    </div>
                                    <div class="form-text">Minimum 6 characters</div>
                                </div>
                                <div class="col-md-4">
                                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                                    <div class="password-toggle">
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" minlength="6">
                                        <i class="bi bi-eye-slash toggle-password" onclick="togglePassword('confirm_password', this)" title="Show/Hide Password"></i>
                                    </div>
                                </div>
                            </div>

                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <button type="reset" class="btn btn-outline-secondary me-md-2">
                                    <i class="bi bi-arrow-clockwise me-1"></i>Reset
                                </button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-lg me-1"></i>Update Profile
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Research Overview -->
        <?php if ($group): ?>
        <div class="row">
            <div class="col-12">
                <div class="dashboard-section">
                    <div class="section-header">
                        <i class="bi bi-graph-up me-2"></i>Research Overview
                    </div>
                    <div class="section-body">
                        <div class="row text-center g-3">
                            <div class="col-md-4">
                                <div class="stat-card primary">
                                    <h3 class="text-primary"><?php echo $group ? '1' : '0'; ?></h3>
                                    <p class="mb-0">Research Groups</p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="stat-card warning">
                                    <h3 class="text-warning"><?php echo $title ? '1' : '0'; ?></h3>
                                    <p class="mb-0">Research Titles</p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="stat-card success">
                                    <h3 class="text-success"><?php echo count($chapters); ?></h3>
                                    <p class="mb-0">Chapters Submitted</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Group Information and Members -->
        <div class="row">
            <div class="col-12">
                <div class="dashboard-section">
                    <div class="section-header">
                        <i class="bi bi-people me-2"></i>Research Group: "<?php echo htmlspecialchars($group['group_name']); ?>"
                    </div>
                    <div class="section-body">
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <h6 class="text-muted mb-2">GROUP DETAILS</h6>
                                <p class="mb-1"><strong>College:</strong> <?php echo htmlspecialchars($group['college']); ?></p>
                                <p class="mb-1"><strong>Program:</strong> <?php echo htmlspecialchars($group['program']); ?></p>
                                <p class="mb-3"><strong>Year Level:</strong> <?php echo htmlspecialchars($group['year_level']); ?></p>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-muted mb-2">RESEARCH ADVISER</h6>
                                <?php
                                // Get adviser info
                                $stmt = $conn->prepare("SELECT first_name, last_name, email FROM users WHERE user_id = ?");
                                $stmt->execute([$group['adviser_id']]);
                                $adviser = $stmt->fetch(PDO::FETCH_ASSOC);
                                ?>
                                <?php if ($adviser): ?>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="author-avatar" style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--university-blue), #1d4ed8); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600;">
                                            <?php echo strtoupper(substr($adviser['first_name'], 0, 1) . substr($adviser['last_name'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <p class="mb-0"><strong><?php echo htmlspecialchars($adviser['first_name'] . ' ' . $adviser['last_name']); ?></strong></p>
                                            <small class="text-muted"><?php echo htmlspecialchars($adviser['email']); ?></small>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted">Not assigned</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Group Members Section -->
                        <div class="row">
                            <div class="col-12">
                                <h6 class="text-muted mb-3">GROUP MEMBERS</h6>
                                
                                <?php
                                // Get all group members from group_memberships table
                                $stmt = $conn->prepare("
                                    SELECT 
                                        gm.membership_id as member_id,
                                        gm.is_registered_user,
                                        CASE 
                                            WHEN gm.is_registered_user = 1 THEN CONCAT(u.first_name, ' ', u.last_name)
                                            ELSE gm.member_name
                                        END as member_name,
                                        CASE 
                                            WHEN gm.is_registered_user = 1 THEN u.student_id
                                            ELSE gm.student_number
                                        END as student_number,
                                        CASE 
                                            WHEN gm.is_registered_user = 1 THEN u.email
                                            ELSE NULL
                                        END as email,
                                        CASE 
                                            WHEN rg.lead_student_id = gm.user_id AND gm.is_registered_user = 1 THEN 1
                                            ELSE 0
                                        END as is_leader,
                                        CASE 
                                            WHEN gm.is_registered_user = 1 THEN u.profile_picture
                                            ELSE NULL
                                        END as profile_picture
                                    FROM group_memberships gm
                                    LEFT JOIN users u ON gm.user_id = u.user_id AND gm.is_registered_user = 1
                                    JOIN research_groups rg ON gm.group_id = rg.group_id
                                    WHERE gm.group_id = ?
                                    ORDER BY is_leader DESC, member_name
                                ");
                                $stmt->execute([$group['group_id']]);
                                $group_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                ?>
                                
                                <div class="row g-3">
                                    <?php foreach ($group_members as $member): ?>
                                        <div class="col-md-6 col-lg-4">
                                            <div class="card h-100" style="border: 1px solid var(--border-light); border-radius: 8px;">
                                                <div class="card-body p-3">
                                                    <div class="d-flex align-items-center mb-2">
                                                        <div class="member-avatar me-3" style="width: 50px; height: 50px; border-radius: 50%; background: linear-gradient(135deg, <?php echo $member['is_leader'] ? 'var(--university-gold), #f59e0b' : 'var(--university-blue), #1d4ed8'; ?>); display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; overflow: hidden;">
                                                            <?php if ($member['profile_picture'] && $member['is_registered_user'] && getProfilePictureUrl($member['profile_picture'])): ?>
                                                                <img src="<?php echo getProfilePictureUrl($member['profile_picture']); ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover;">
                                                            <?php else: ?>
                                                                <?php echo strtoupper(substr($member['member_name'], 0, 2)); ?>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="flex-grow-1">
                                                            <h6 class="mb-0"><?php echo htmlspecialchars($member['member_name']); ?></h6>
                                                            <?php if ($member['is_leader']): ?>
                                                                <span class="badge bg-warning text-dark" style="font-size: 0.7rem;">
                                                                    <i class="bi bi-star me-1"></i>Group Leader
                                                                </span>
                                                            <?php else: ?>
                                                                <span class="badge bg-primary" style="font-size: 0.7rem;">
                                                                    <i class="bi bi-person me-1"></i>Member
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="member-details">
                                                        <?php if ($member['student_number']): ?>
                                                            <p class="mb-1 small">
                                                                <i class="bi bi-card-text text-muted me-1"></i>
                                                                <strong>Student ID:</strong> <?php echo htmlspecialchars($member['student_number']); ?>
                                                            </p>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($member['email'] && $member['is_registered_user']): ?>
                                                            <p class="mb-1 small">
                                                                <i class="bi bi-envelope text-muted me-1"></i>
                                                                <strong>Email:</strong> <?php echo htmlspecialchars($member['email']); ?>
                                                            </p>
                                                        <?php endif; ?>
                                                        
                                                        <p class="mb-0 small">
                                                            <i class="bi bi-<?php echo $member['is_registered_user'] ? 'check-circle text-success' : 'exclamation-circle text-warning'; ?> me-1"></i>
                                                            <strong>Status:</strong> 
                                                            <?php echo $member['is_registered_user'] ? 'Registered User' : 'Added by Name'; ?>
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <!-- Group Summary -->
                                <div class="alert" style="background: rgba(30, 64, 175, 0.05); border: 1px solid rgba(30, 64, 175, 0.1); border-radius: 8px; margin-top: 1rem;">
                                    <div class="row text-center">
                                        <div class="col-md-4">
                                            <div class="d-flex align-items-center justify-content-center">
                                                <i class="bi bi-people text-primary me-2"></i>
                                                <span><strong><?php echo count($group_members); ?></strong> Total Members</span>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="d-flex align-items-center justify-content-center">
                                                <i class="bi bi-check-circle text-success me-2"></i>
                                                <span><strong><?php echo count(array_filter($group_members, function($m) { return $m['is_registered_user']; })); ?></strong> Registered Users</span>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="d-flex align-items-center justify-content-center">
                                                <i class="bi bi-calendar text-info me-2"></i>
                                                <span>Created <?php echo date('M d, Y', strtotime($group['created_at'])); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Notification Functions
        function markNotificationAsRead(notificationId) {
            return fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=mark_read&notification_id=' + notificationId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateNotificationCount();
                }
                return data.success;
            })
            .catch(error => {
                console.error('Error:', error);
                return false;
            });
        }

        function markAllNotificationsAsRead() {
            const markAllBtn = document.getElementById('markAllReadBtn');
            const notificationList = document.getElementById('notificationList');
            const notificationItems = document.querySelectorAll('.notification-item');
            
            if (markAllBtn) {
                markAllBtn.disabled = true;
                markAllBtn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
            }
            
            return fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=mark_all_read'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Animate and remove all notifications
                    notificationItems.forEach((item, index) => {
                        setTimeout(() => {
                            item.classList.add('removing');
                        }, index * 50);
                    });
                    
                    // After animations complete, show "no notifications" message
                    setTimeout(() => {
                        notificationList.innerHTML = `
                            <li class="notification-item text-center py-4">
                                <i class="bi bi-bell-slash text-muted" style="font-size: 2rem; opacity: 0.5;"></i>
                                <div class="text-muted mt-2">No new notifications</div>
                            </li>
                        `;
                        
                        // Update counts
                        updateNotificationCount();
                        
                        // Hide mark all button
                        if (markAllBtn) {
                            markAllBtn.style.display = 'none';
                        }
                    }, notificationItems.length * 50 + 300);
                } else {
                    if (markAllBtn) {
                        markAllBtn.disabled = false;
                        markAllBtn.innerHTML = '<i class="bi bi-check-all"></i>';
                    }
                }
                return data.success;
            })
            .catch(error => {
                console.error('Error:', error);
                if (markAllBtn) {
                    markAllBtn.disabled = false;
                    markAllBtn.innerHTML = '<i class="bi bi-check-all"></i>';
                }
                return false;
            });
        }

        function removeNotificationFromList(notificationElement) {
            notificationElement.classList.add('removing');
            setTimeout(() => {
                notificationElement.remove();
                
                // Check if there are any notifications left
                const remainingNotifications = document.querySelectorAll('.notification-item:not([class*="text-center"])');
                if (remainingNotifications.length === 0) {
                    const notificationList = document.getElementById('notificationList');
                    notificationList.innerHTML = `
                        <li class="notification-item text-center py-4">
                            <i class="bi bi-bell-slash text-muted" style="font-size: 2rem; opacity: 0.5;"></i>
                            <div class="text-muted mt-2">No new notifications</div>
                        </li>
                    `;
                }
            }, 300);
        }

        function updateNotificationCount() {
            return fetch('?get_count=1')
                .then(response => response.json())
                .then(data => {
                    const badge = document.getElementById('notificationBadge');
                    const countElement = document.getElementById('notificationCount');
                    const markAllBtn = document.getElementById('markAllReadBtn');
                    
                    if (data.count === 0) {
                        if (badge) {
                            badge.style.opacity = '0';
                            setTimeout(() => badge.style.display = 'none', 300);
                        }
                        if (countElement) {
                            countElement.textContent = '0 new';
                        }
                        if (markAllBtn) {
                            markAllBtn.style.display = 'none';
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
                        if (markAllBtn && markAllBtn.style.display === 'none') {
                            markAllBtn.style.display = 'inline-block';
                        }
                    }
                    return data.count;
                })
                .catch(error => {
                    console.error('Error:', error);
                    return null;
                });
        }

        // Event Listeners
        document.addEventListener('click', function(e) {
            const notificationItem = e.target.closest('.notification-item');
            if (notificationItem && notificationItem.dataset.notificationId) {
                e.preventDefault();
                
                const notificationId = notificationItem.dataset.notificationId;
                const href = notificationItem.href;
                
                markNotificationAsRead(notificationId).then((success) => {
                    if (success) {
                        removeNotificationFromList(notificationItem);
                        setTimeout(() => {
                            window.location.href = href;
                        }, 150);
                    }
                });
            }
            
            if (e.target.closest('#markAllReadBtn')) {
                e.preventDefault();
                e.stopPropagation();
                markAllNotificationsAsRead();
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
        const confirmPasswordField = document.getElementById('confirm_password');
        if (confirmPasswordField) {
            confirmPasswordField.addEventListener('input', function() {
                const newPassword = document.getElementById('new_password').value;
                const confirmPassword = this.value;
                
                if (newPassword !== confirmPassword) {
                    this.setCustomValidity('Passwords do not match');
                } else {
                    this.setCustomValidity('');
                }
            });
        }

        // File upload validation
        const profilePictureField = document.getElementById('profile_picture');
        if (profilePictureField) {
            profilePictureField.addEventListener('change', function() {
                const file = this.files[0];
                if (file) {
                    if (file.size > 5 * 1024 * 1024) {
                        alert('File size must be less than 5MB');
                        this.value = '';
                        return;
                    }
                    
                    const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                    if (!allowedTypes.includes(file.type)) {
                        alert('Only JPG, PNG, and GIF files are allowed');
                        this.value = '';
                        return;
                    }
                }
            });
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            const bellButton = document.getElementById('notificationBell');
            const notificationCount = <?php echo $total_unviewed; ?>;
            
            if (bellButton && notificationCount > 0) {
                bellButton.style.animation = 'shake 0.5s ease-in-out';
            }
            
            setInterval(updateNotificationCount, 30000);
        });
    </script>
</body>
</html>