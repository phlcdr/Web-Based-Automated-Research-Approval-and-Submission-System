<?php
// student/thesis_discussion.php - Fixed for normalized database
include_once '../config/database.php';
include_once '../includes/functions.php';

is_logged_in();
check_role(['student']);

$user_id = $_SESSION['user_id'];
$success = '';
$error = '';
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
// Handle AJAX notification requests 
if (isset($_POST['action']) && $_POST['action'] === 'mark_viewed') {
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

// Get current user information including profile picture
$stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$current_user = $stmt->fetch(PDO::FETCH_ASSOC);

// Function to get profile picture URL
function getProfilePictureUrl($profile_picture) {
    if ($profile_picture && file_exists('../uploads/profile_pictures/' . $profile_picture)) {
        return '../uploads/profile_pictures/' . $profile_picture;
    }
    return null;
}

// Get student's group
$stmt = $conn->prepare("SELECT * FROM research_groups WHERE lead_student_id = ?");
$stmt->execute([$user_id]);
$group = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$group) {
    header("Location: create_group.php?error=need_group");
    exit();
}

// Check if Chapter 3 is approved (required for discussion access)
$stmt = $conn->prepare("
    SELECT * FROM submissions 
    WHERE group_id = ? AND submission_type = 'chapter' AND chapter_number = 3 AND status = 'approved' 
    ORDER BY approval_date DESC LIMIT 1
");
$stmt->execute([$group['group_id']]);
$chapter3 = $stmt->fetch(PDO::FETCH_ASSOC);

// Get approved research title for display
$stmt = $conn->prepare("
    SELECT * FROM submissions 
    WHERE group_id = ? AND submission_type = 'title' AND status = 'approved' 
    ORDER BY approval_date DESC LIMIT 1
");
$stmt->execute([$group['group_id']]);
$title = $stmt->fetch(PDO::FETCH_ASSOC);

// Get or create discussion - only if Chapter 3 is approved
$discussion = null;
if ($chapter3) {
    $stmt = $conn->prepare("SELECT * FROM thesis_discussions WHERE group_id = ? LIMIT 1");
    $stmt->execute([$group['group_id']]);
    $discussion = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$discussion) {
        // Create new discussion
        $stmt = $conn->prepare("
            INSERT INTO thesis_discussions (group_id, title_id, title) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([
            $group['group_id'], 
            $title['submission_id'], 
            $title['title']
        ]);
        $discussion_id = $conn->lastInsertId();

        // FIXED: Add participants using assignments table
        $stmt = $conn->prepare("
            INSERT INTO assignments (assignment_type, context_type, context_id, user_id, role) VALUES 
            ('participant', 'discussion', ?, ?, 'student'),
            ('participant', 'discussion', ?, ?, 'adviser')
        ");
        $stmt->execute([$discussion_id, $user_id, $discussion_id, $group['adviser_id']]);

        // Get the created discussion
        $stmt = $conn->prepare("SELECT * FROM thesis_discussions WHERE discussion_id = ?");
        $stmt->execute([$discussion_id]);
        $discussion = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}


// Process message sending
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message']) && $discussion) {
    $message = trim($_POST['message']);
    $file_path = '';
    $original_filename = '';
    
    // Handle file upload
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/thesis_discussion/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_extension = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
        
        if (in_array($file_extension, ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'txt'])) {
            if ($_FILES['attachment']['size'] <= 25 * 1024 * 1024) {
                $filename = 'discussion_' . $discussion['discussion_id'] . '_' . time() . '.' . $file_extension;
                $target_path = $upload_dir . $filename;
                $file_path = 'uploads/thesis_discussion/' . $filename;
                
                if (move_uploaded_file($_FILES['attachment']['tmp_name'], $target_path)) {
                    $original_filename = $_FILES['attachment']['name'];
                } else {
                    $error = "Failed to upload file.";
                }
            } else {
                $error = "File size too large. Maximum size is 25MB.";
            }
        } else {
            $error = "Invalid file type. Allowed: PDF, DOC, DOCX, JPG, PNG, GIF, WebP, TXT";
        }
    }
    
    // FIXED: Insert message using messages table with correct context
    if ((!empty($message) || !empty($file_path)) && empty($error)) {
        $stmt = $conn->prepare("
            INSERT INTO messages (context_type, context_id, user_id, message_text, file_path, original_filename) 
            VALUES ('discussion', ?, ?, ?, ?, ?)
        ");
        
        if ($stmt->execute([$discussion['discussion_id'], $user_id, $message, $file_path, $original_filename])) {
            // FIXED: Notify participants using assignments table
            $stmt = $conn->prepare("SELECT user_id FROM assignments WHERE context_type = 'discussion' AND context_id = ? AND user_id != ? AND is_active = 1");
            $stmt->execute([$discussion['discussion_id'], $user_id]);
            $notify_users = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($notify_users as $notify_user_id) {
                $stmt = $conn->prepare("
                    INSERT INTO notifications (user_id, title, message, type, context_type, context_id) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $notify_user_id,
                    'New Thesis Message',
                    $_SESSION['full_name'] . ' sent a new message in thesis discussion',
                    'thesis_message',
                    'discussion',
                    $discussion['discussion_id']
                ]);
            }
            
            // Redirect to prevent resubmission
            header("Location: thesis_discussion.php?success=sent");
            exit();
        } else {
            $error = "Failed to send message. Please try again.";
        }
    } elseif (empty($error)) {
        $error = "Please enter a message or select a file.";
    }
}

// FIXED: Get notification data from notifications table
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

// Get available panel members and advisers from the same college
$stmt = $conn->prepare("
    SELECT user_id, first_name, last_name, role, college 
    FROM users 
    WHERE (role = 'panel' OR role = 'adviser') AND college = ? 
    ORDER BY role DESC, first_name
");
$stmt->execute([$group['college']]);
$available_participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// FIXED: Get current participants using assignments table
$participants = [];
if ($discussion) {
    $stmt = $conn->prepare("
        SELECT a.*, u.first_name, u.last_name, u.role as user_role, u.college
        FROM assignments a
        JOIN users u ON a.user_id = u.user_id
        WHERE a.context_type = 'discussion' AND a.context_id = ? AND a.is_active = 1
        ORDER BY 
            CASE u.role 
                WHEN 'student' THEN 1 
                WHEN 'adviser' THEN 2 
                WHEN 'panel' THEN 3 
            END,
            u.first_name
    ");
    $stmt->execute([$discussion['discussion_id']]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
}


// FIXED: Get messages using messages table
$messages = [];
if ($discussion) {
    $stmt = $conn->prepare("
        SELECT m.*, u.first_name, u.last_name, u.role
        FROM messages m
        JOIN users u ON m.user_id = u.user_id
        WHERE m.context_type = 'discussion' AND m.context_id = ?
        ORDER BY m.created_at ASC
    ");
    $stmt->execute([$discussion['discussion_id']]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpg" href="../assets/images/essu logo.png">
    <title>Thesis Discussion - ESSU Research System</title>
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

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--light-gray);
            color: var(--text-primary);
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
        /* Hide the small text below images */
        .image-preview-container .mt-2.small.text-muted {
            display: none !important;
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

        .notification-icon.title { background: rgba(30, 64, 175, 0.1); color: var(--university-blue); }
        .notification-icon.chapter { background: rgba(5, 150, 105, 0.1); color: var(--success-green); }
        .notification-icon.discussion { background: rgba(2, 132, 199, 0.1); color: #0284c7; }

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

        .user-profile {
            display: flex;
            align-items: center;
            padding: 0.5rem 1rem;
            color: white !important;
            text-decoration: none;
            border-radius: 6px;
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

        /* ELEGANT CHAT MESSAGES DESIGN */
        .chat-container {
            background: #ffffff;
            padding: 1.5rem;
            max-height: 600px;
            overflow-y: auto;
            border-radius: 0 0 12px 12px;
        }

        .messages-wrapper {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }

        .message-bubble {
            max-width: 70%;
            word-wrap: break-word;
            animation: messageSlideIn 0.3s ease;
        }

        @keyframes messageSlideIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .message-bubble.self {
            align-self: flex-end;
            background: linear-gradient(135deg, #1e40af 0%, #1e3a8a 100%);
            color: white;
            border-radius: 18px 18px 4px 18px;
            padding: 1rem 1.25rem;
            box-shadow: 0 2px 8px rgba(30, 64, 175, 0.3);
        }

        .message-bubble.other {
            align-self: flex-start;
            background: white;
            color: #111827;
            border-radius: 18px 18px 18px 4px;
            padding: 1rem 1.25rem;
            border: 1px solid #e5e7eb;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
        }

        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.6rem;
            gap: 0.75rem;
        }

        .message-author {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
            font-size: 0.875rem;
            flex-wrap: wrap;
        }

        .message-bubble.self .message-header {
            color: rgba(255, 255, 255, 0.95);
        }

        .message-bubble.other .message-header {
            color: #374151;
        }

        .role-badge {
            font-size: 0.7rem;
            padding: 0.2rem 0.6rem;
            border-radius: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .role-badge.role-student {
            background: rgba(219, 234, 254, 0.9);
            color: #1d4ed8;
        }

        .role-badge.role-adviser {
            background: rgba(220, 252, 231, 0.9);
            color: #166534;
        }

        .role-badge.role-panel {
            background: rgba(240, 249, 255, 0.9);
            color: #0284c7;
        }

        .message-bubble.self .role-badge.role-student {
            background: rgba(255, 255, 255, 0.25);
            color: white;
        }

        .message-time {
            font-size: 0.75rem;
            opacity: 0.75;
            white-space: nowrap;
        }

        .message-content {
            line-height: 1.6;
            font-size: 0.95rem;
        }

        .message-bubble.self .message-content {
            color: rgba(255, 255, 255, 0.98);
        }

        .message-bubble.other .message-content {
            color: #111827;
        }

        /* PARTICIPANT BADGES - CLEAN DESIGN */
        .participant-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-weight: 500;
            font-size: 0.9rem;
            margin: 0.25rem;
            position: relative;
            border: 1px solid transparent;
            transition: all 0.2s ease;
        }

        .participant-badge:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .participant-badge.student {
            background: #dbeafe;
            color: #1d4ed8;
            border-color: #93c5fd;
        }

        .participant-badge.adviser {
            background: #dcfce7;
            color: #166534;
            border-color: #86efac;
        }

        .participant-badge.panel {
            background: #f0f9ff;
            color: #0284c7;
            border-color: #7dd3fc;
        }

        .participant-badge.primary-adviser {
            border: 2px solid var(--university-gold);
            font-weight: 600;
            background: #dcfce7;
            color: #166534;
        }

        .participant-badge .badge {
            font-size: 0.7rem;
            padding: 0.15rem 0.4rem;
            margin-left: 0.3rem;
            background: white !important;
            color: #374151 !important;
            border: 1px solid #e5e7eb;
            font-weight: 600;
        }

        .remove-participant-btn {
            font-size: 0.8rem;
            line-height: 1;
            padding: 0;
            margin-left: 0.5rem;
            background: none;
            border: none;
            color: rgba(220, 38, 38, 0.7);
            transition: color 0.2s ease;
            cursor: pointer;
        }

        .remove-participant-btn:hover {
            color: #dc2626;
        }

        .participant-badge i {
            font-size: 0.9rem;
            opacity: 0.8;
        }

        /* FILE ATTACHMENT - CLEAN & ELEGANT */
        .file-attachment {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-top: 0.5rem;
            background: rgba(255, 255, 255, 0.1);
        }

        .message-bubble.other .file-attachment {
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
        }

        .file-icon {
            font-size: 1.75rem;
            opacity: 0.9;
            flex-shrink: 0;
        }

        .file-info {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 0.15rem;
        }

        .file-name {
            font-weight: 600;
            font-size: 0.875rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.3;
        }

        .file-size {
            font-size: 0.75rem;
            opacity: 0.7;
            line-height: 1;
        }

        .file-actions {
            display: flex;
            gap: 0.5rem;
            flex-shrink: 0;
            margin-left: auto;
        }

        .message-bubble.self .file-attachment .btn,
        .message-bubble.self .image-preview-container .btn {
            background: white !important;
            color: #1e40af !important;
            border: 2px solid white !important;
            font-size: 0.85rem;
            padding: 0.45rem 0.85rem;
            font-weight: 700;
            border-radius: 6px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.25);
            transition: all 0.2s ease;
            text-shadow: none;
        }

        .message-bubble.self .file-attachment .btn:hover,
        .message-bubble.self .image-preview-container .btn:hover {
            background: #f8fafc !important;
            color: #1e3a8a !important;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.3);
        }

        .message-bubble.other .file-attachment .btn {
            background: #1e40af !important;
            color: white !important;
            border: 1px solid #1e40af !important;
            font-size: 0.8rem;
            padding: 0.35rem 0.65rem;
            font-weight: 600;
            border-radius: 6px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: all 0.2s ease;
        }

        .message-bubble.other .file-attachment .btn:hover {
            background: #1e3a8a !important;
            transform: translateY(-1px);
            box-shadow: 0 2px 5px rgba(0,0,0,0.15);
        }

        .file-attachment .btn {
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            text-decoration: none;
        }

        .file-attachment .btn i {
            font-size: 0.9rem;
        }

        /* IMAGE PREVIEW */
        .image-preview-container {
            position: relative;
            transition: all 0.3s ease;
        }

        .image-preview-container img {
            transition: transform 0.2s ease;
        }

        .image-preview-container img:hover {
            transform: scale(1.02);
        }

        .message-bubble.self .image-preview-container img {
            border-color: rgba(255,255,255,0.2);
        }

        .message-bubble.self .image-preview-container img:hover {
            border-color: rgba(255,255,255,0.4);
        }

        .message-bubble.other .image-preview-container img {
            border-color: rgba(0,0,0,0.1);
        }

        .message-bubble.other .image-preview-container img:hover {
            border-color: rgba(0,0,0,0.2);
        }

        #imageModal .modal-body {
            padding: 1rem;
            background: #000;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #imageModalImg {
            object-fit: contain;
            max-width: 100%;
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
        }

        .btn-success {
            background: var(--success-green);
            color: white;
        }

        .btn-success:hover {
            background: #047857;
        }

        .form-control {
            border: 2px solid var(--border-light);
            border-radius: 8px;
            padding: 0.75rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            border-color: var(--university-blue);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
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

        .alert-warning {
            background: #fffbeb;
            color: #d97706;
            border-left: 4px solid var(--warning-orange);
        }

        .alert-info {
            background: #eff6ff;
            color: #1d4ed8;
            border-left: 4px solid var(--university-blue);
        }

        .research-info-card {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border-left: 4px solid var(--university-blue);
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .research-title {
            color: var(--university-blue);
            font-weight: 700;
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
            color: var(--text-secondary);
        }

        .empty-state-icon {
            font-size: 3rem;
            opacity: 0.5;
            margin-bottom: 1rem;
        }

        /* RESPONSIVE - TABLET (768px and below) */
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
            
            .section-header {
                padding: 0.75rem 1rem;
                font-size: 0.9rem;
            }
            
            .section-body {
                padding: 1rem;
            }
            
            .message-bubble {
                max-width: 85%;
            }
            
            .participant-badge {
                font-size: 0.85rem;
                padding: 0.4rem 0.85rem;
            }
        }

        /* RESPONSIVE - MOBILE (576px and below) */
        @media (max-width: 576px) {
            .university-brand {
                flex-direction: row;
                text-align: left;
            }
            
            .university-logo {
                width: 35px;
                height: 35px;
                margin-right: 10px;
            }
            
            .university-name {
                font-size: 0.85rem;
            }
            
            .notification-bell {
                font-size: 1.25rem;
                padding: 0.25rem;
            }
            
            .user-info {
                display: none;
            }
            
            .user-avatar {
                width: 32px;
                height: 32px;
            }
            
            .notification-section {
                gap: 0.5rem;
            }
            
            /* Enhanced Navigation Tabs - Same as Submit Chapter */
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
            
            .section-header {
                padding: 0.75rem 1rem;
                font-size: 0.85rem;
            }
            
            .section-body {
                padding: 1rem;
            }
            
            .message-bubble {
                max-width: 90%;
            }
            
            .message-header {
                flex-wrap: wrap;
            }
            
            .message-author {
                font-size: 0.8rem;
            }
            
            .role-badge {
                font-size: 0.65rem;
                padding: 0.15rem 0.5rem;
            }
            
            .message-time {
                font-size: 0.7rem;
            }
            
            .message-content {
                font-size: 0.875rem;
            }
            
            .participant-badge {
                font-size: 0.8rem;
                padding: 0.4rem 0.75rem;
                margin: 0.2rem;
            }
            
            .participant-badge .badge {
                font-size: 0.65rem !important;
            }
            
            .file-attachment {
                padding: 0.65rem 0.85rem;
                gap: 0.5rem;
                flex-wrap: wrap;
            }
            
            .file-icon {
                font-size: 1.5rem;
            }
            
            .file-name {
                font-size: 0.8rem;
            }
            
            .file-actions {
                gap: 0.35rem;
                width: 100%;
                justify-content: flex-end;
                margin-top: 0.5rem;
            }
            
            .file-attachment .btn {
                font-size: 0.75rem;
                padding: 0.3rem 0.5rem;
            }
            
            .form-label {
                font-size: 0.9rem;
            }
            
            .form-control, .form-select {
                font-size: 0.9rem;
                padding: 0.6rem;
            }
            
            .btn {
                font-size: 0.9rem;
                padding: 0.6rem 1rem;
            }
            
            .alert {
                font-size: 0.85rem;
                padding: 0.75rem;
            }
            
            .research-info-card {
                padding: 1rem;
            }
            
            .research-title {
                font-size: 1rem;
            }
            
            .chat-container {
                max-height: 400px;
            }
        }

        /* PDF Modal Styles - Clean Header Design */
        .pdf-modal .modal-dialog {
            max-width: 90vw;
            width: 90vw;
            height: 90vh;
            margin: 1.75rem auto;
        }

        .pdf-modal .modal-content {
            height: 100%;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        }

        .pdf-modal .modal-header {
            background: linear-gradient(135deg, var(--university-blue), #1e3a8a);
            color: white;
            padding: 1rem 1.5rem;
            border-bottom: none;
            align-items: center;
            display: flex;
            justify-content: space-between;
        }

        .pdf-modal .modal-title {
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            margin-bottom: 0;
            flex: 1;
            min-width: 0;
        }

        .pdf-modal .modal-title i {
            margin-right: 0.5rem;
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .pdf-modal .modal-title span {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .pdf-modal .modal-header .header-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex-shrink: 0;
        }

        .pdf-modal .modal-header .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.875rem;
            border-radius: 6px;
            display: flex;
            align-items: center;
            gap: 0.375rem;
            white-space: nowrap;
        }

        .pdf-modal .modal-header .btn-outline-light {
            border-color: rgba(255, 255, 255, 0.5);
            color: white;
        }

        .pdf-modal .modal-header .btn-outline-light:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: white;
        }

        .pdf-modal .modal-header .btn-close {
            filter: brightness(0) invert(1);
            opacity: 1;
            padding: 0.5rem;
            margin-left: 0.5rem;
        }

        .pdf-modal .modal-body {
            padding: 0;
            height: calc(90vh - 70px);
            background: #525659;
            position: relative;
        }

        .pdf-modal iframe {
            width: 100%;
            height: 100%;
            border: none;
            display: block;
        }

        .pdf-loading {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            color: white;
        }

        /* Image Modal Styles */
        #imageModal .modal-dialog {
            max-width: 90vw;
            width: 90vw;
            margin: 1.75rem auto;
        }

        #imageModal .modal-content {
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        }

        #imageModal .modal-header {
            background: linear-gradient(135deg, var(--university-blue), #1e3a8a);
            color: white;
            padding: 1rem 1.5rem;
            border-bottom: none;
            align-items: center;
        }

        #imageModal .modal-title {
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            margin-bottom: 0;
        }

        #imageModal .modal-title i {
            margin-right: 0.5rem;
            font-size: 1.25rem;
        }

        #imageModal .modal-header .header-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        #imageModal .modal-header .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.875rem;
            border-radius: 6px;
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }

        #imageModal .modal-header .btn-outline-light {
            border-color: rgba(255, 255, 255, 0.5);
            color: white;
        }

        #imageModal .modal-header .btn-outline-light:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: white;
        }

        #imageModal .modal-header .btn-close {
            filter: brightness(0) invert(1);
            opacity: 1;
            padding: 0.5rem;
        }

        #imageModal .modal-body {
            background: #000;
            padding: 1rem;
            min-height: 50vh;
            max-height: 80vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #imageModal .modal-body img {
            max-width: 100%;
            max-height: 75vh;
            width: auto;
            height: auto;
            object-fit: contain;
        }

        /* TABLET (768px and below) */
        @media (max-width: 768px) {
            .pdf-modal .modal-dialog,
            #imageModal .modal-dialog {
                max-width: 95vw;
                width: 95vw;
                height: 90vh;
                margin: 1rem auto;
            }
            
            .pdf-modal .modal-content,
            #imageModal .modal-content {
                border-radius: 8px;
            }
            
            .pdf-modal .modal-header,
            #imageModal .modal-header {
                padding: 0.875rem 1rem;
            }
            
            .pdf-modal .modal-title,
            #imageModal .modal-title {
                font-size: 1rem;
            }
            
            .pdf-modal .modal-title i,
            #imageModal .modal-title i {
                font-size: 1.1rem;
            }
            
            .pdf-modal .modal-header .btn-sm,
            #imageModal .modal-header .btn-sm {
                font-size: 0.8rem;
                padding: 0.35rem 0.65rem;
            }
            
            .pdf-modal .modal-body {
                height: calc(90vh - 65px);
            }
            
            #imageModal .modal-body {
                min-height: 40vh;
                max-height: 75vh;
                padding: 0.75rem;
            }
            
            #imageModal .modal-body img {
                max-height: 70vh;
            }
        }

        /* MOBILE (576px and below) */
        @media (max-width: 576px) {
            .pdf-modal .modal-dialog,
            #imageModal .modal-dialog {
                max-width: 100vw;
                width: 100vw;
                height: 100vh;
                margin: 0;
            }
            
            .pdf-modal .modal-content,
            #imageModal .modal-content {
                border-radius: 0;
                height: 100vh;
            }
            
            .pdf-modal .modal-header,
            #imageModal .modal-header {
                padding: 0.75rem 1rem;
                flex-wrap: wrap;
            }
            
            .pdf-modal .modal-title,
            #imageModal .modal-title {
                font-size: 0.9rem;
                flex: 1 1 100%;
                margin-bottom: 0.5rem;
                word-break: break-word;
            }
            
            .pdf-modal .modal-title i,
            #imageModal .modal-title i {
                font-size: 1rem;
                margin-right: 0.375rem;
                flex-shrink: 0;
            }
            
            .pdf-modal .modal-header .header-actions,
            #imageModal .modal-header .header-actions {
                flex: 1 1 100%;
                width: 100%;
                gap: 0.5rem;
            }
            
            .pdf-modal .modal-header .btn-sm,
            #imageModal .modal-header .btn-sm {
                flex: 1;
                font-size: 0.75rem;
                padding: 0.5rem 0.75rem;
                justify-content: center;
            }
            
            /* Hide text, show only icons on mobile */
            .pdf-modal .modal-header .btn-sm .btn-text,
            #imageModal .modal-header .btn-sm .btn-text {
                display: none !important;
            }
            
            .pdf-modal .modal-header .btn-sm i,
            #imageModal .modal-header .btn-sm i {
                margin: 0 !important;
                font-size: 1.1rem;
            }
            
            .pdf-modal .modal-header .btn-close,
            #imageModal .modal-header .btn-close {
                position: absolute;
                top: 0.5rem;
                right: 0.5rem;
                width: 2rem;
                height: 2rem;
                padding: 0.5rem;
            }
            
            .pdf-modal .modal-body {
                height: calc(100vh - 85px);
            }
            
            #imageModal .modal-body {
                height: calc(100vh - 85px);
                min-height: auto;
                max-height: none;
                padding: 0.5rem;
            }
            
            #imageModal .modal-body img {
                max-height: calc(100vh - 95px);
            }
        }

        /* EXTRA SMALL (450px and below) */
        @media (max-width: 450px) {
            .pdf-modal .modal-title,
            #imageModal .modal-title {
                font-size: 0.8rem;
                line-height: 1.2;
            }
            
            .pdf-modal .modal-title i,
            #imageModal .modal-title i {
                font-size: 0.9rem;
            }
            
            .pdf-modal .modal-header .btn-sm,
            #imageModal .modal-header .btn-sm {
                font-size: 0.7rem;
                padding: 0.45rem 0.65rem;
                gap: 0;
            }
            
            /* Ensure text is hidden and icons are visible */
            .pdf-modal .modal-header .btn-sm .btn-text,
            #imageModal .modal-header .btn-sm .btn-text {
                display: none !important;
            }
            
            .pdf-modal .modal-header .btn-sm i,
            #imageModal .modal-header .btn-sm i {
                font-size: 1rem;
                margin: 0 !important;
            }
            
            .pdf-modal .modal-header .btn-close,
            #imageModal .modal-header .btn-close {
                width: 1.75rem;
                height: 1.75rem;
                padding: 0.4rem;
            }
            
            #imageModal .modal-body {
                padding: 0.25rem;
            }
        }

        /* EXTRA SMALL (450px and below) - Specific for your 450x689 screen */
        @media (max-width: 450px) {
            .pdf-modal .modal-title,
            #imageModal .modal-title {
                font-size: 0.8rem;
                line-height: 1.2;
            }
            
            .pdf-modal .modal-title i,
            #imageModal .modal-title i {
                font-size: 0.9rem;
            }
            
            .pdf-modal .modal-header .btn-sm,
            #imageModal .modal-header .btn-sm {
                font-size: 0.7rem;
                padding: 0.3rem 0.4rem;
                gap: 0.25rem;
            }
            
            .pdf-modal .modal-header .btn-sm i,
            #imageModal .modal-header .btn-sm i {
                font-size: 0.85rem;
            }
            
            .pdf-modal .modal-header .btn-close,
            #imageModal .modal-header .btn-close {
                width: 1.75rem;
                height: 1.75rem;
                padding: 0.4rem;
            }
            
            #imageModal .modal-body {
                padding: 0.25rem;
            }
        }

        /* LANDSCAPE MODE */
        @media (max-width: 768px) and (orientation: landscape) {
            .pdf-modal .modal-dialog,
            #imageModal .modal-dialog {
                height: 100vh;
                margin: 0 auto;
            }
            
            .pdf-modal .modal-content,
            #imageModal .modal-content {
                height: 100vh;
            }
            
            .pdf-modal .modal-header,
            #imageModal .modal-header {
                padding: 0.5rem 1rem;
            }
            
            .pdf-modal .modal-title,
            #imageModal .modal-title {
                font-size: 0.85rem;
            }
            
            .pdf-modal .modal-body {
                height: calc(100vh - 55px);
            }
            
            #imageModal .modal-body {
                height: calc(100vh - 55px);
                padding: 0.5rem;
            }
            
            #imageModal .modal-body img {
                max-height: calc(100vh - 65px);
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
                                                    <div class="notification-icon <?php echo $notif['type']; ?>">
                                                        <i class="bi bi-<?php 
                                                            if (strpos($notif['type'], 'title') !== false) echo 'journal-check';
                                                            elseif (strpos($notif['type'], 'chapter') !== false) echo 'file-earmark-check';
                                                            else echo 'chat-square-text';
                                                        ?>"></i>
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
                        </ul>
                    </div>
                
                <div class="dropdown">
                    <a href="#" class="user-profile dropdown-toggle" data-bs-toggle="dropdown">
                        <div class="user-avatar">
                            <?php if ($current_user['profile_picture'] && getProfilePictureUrl($current_user['profile_picture'])): ?>
                                <img src="<?php echo getProfilePictureUrl($current_user['profile_picture']); ?>" alt="Profile">
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
                    <a class="nav-link active" href="thesis_discussion.php">
                        <i class="bi bi-chat-square-text me-1 me-md-2"></i>
                        <span class="d-none d-sm-inline">Discussion</span>
                    </a>
                </li>
            </ul>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Thesis Discussion</h1>
            <p class="page-subtitle">Collaborate with your advisers and panel members from <?php echo htmlspecialchars($group['college']); ?></p>
        </div>
        
        <?php if (isset($_GET['success']) && $_GET['success'] == 'sent'): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i>Message sent successfully!
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php elseif (isset($_GET['success']) && $_GET['success'] == 'participants_added'): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i>Participants added successfully!
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php elseif (isset($_GET['success']) && $_GET['success'] == 'participant_removed'): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i>Participant removed successfully!
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i><?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!$chapter3): ?>
            <!-- No Chapter 3 - Cannot Start Discussion -->
            <div class="dashboard-section">
                <div class="section-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-info-circle me-2"></i>
                        You need an approved Chapter 3 (Methodology) to access thesis discussions.
                        <a href="submit_chapter.php?chapter=3" class="btn btn-warning btn-sm ms-2">Go to Chapter 3</a>
                    </div>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="bi bi-chat-square-dots"></i>
                        </div>
                        <h5 class="text-muted">No Discussion Available</h5>
                        <p class="text-muted">Complete and get approval for Chapter 3 (Methodology) to start collaborating with your advisers and panel members.</p>
                </div>
            </div>
        <?php elseif (!$discussion): ?>
            <div class="alert alert-danger">Unable to create discussion. Please contact system administrator.</div>
        <?php else: ?>

            <!-- Research Information -->
            <div class="dashboard-section">
                <div class="section-header">
                    <i class="bi bi-journal-text me-2"></i>Research Information
                </div>
                <div class="section-body">
                    <div class="research-info-card">
                        <h6 class="research-title"><?php echo htmlspecialchars($title['title']); ?></h6>
                        <div class="row">
                            <div class="col-md-6">
                                <p class="mb-1"><strong>Group:</strong> <?php echo htmlspecialchars($group['group_name']); ?></p>
                                <p class="mb-1"><strong>College:</strong> <?php echo htmlspecialchars($group['college']); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p class="mb-1"><strong>Program:</strong> <?php echo htmlspecialchars($group['program']); ?></p>
                                <p class="mb-0"><strong>Year Level:</strong> <?php echo htmlspecialchars($group['year_level']); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Participants Management -->
            <div class="dashboard-section">
                <div class="section-header">
                    <i class="bi bi-people me-2"></i>Discussion Participants
                </div>
                <div class="section-body">
                    <!-- Current Participants -->
                    <h6 class="mb-3">Discussion Participants:</h6>
                    <div class="mb-4">
                        <?php foreach ($participants as $p): ?>
                            <?php 
                            $isPrimaryAdviser = ($p['user_role'] == 'adviser' && $p['user_id'] == $group['adviser_id']);
                            // Display role: Main adviser shows "Adviser", everyone else shows "Panel"
                            $displayRole = $isPrimaryAdviser ? 'Adviser' : 'Panel';
                            // CSS class: use 'adviser' for primary adviser, 'panel' for everyone else
                            $badgeClass = $isPrimaryAdviser ? 'adviser' : 'panel';
                            ?>
                            <span class="participant-badge <?php echo $badgeClass; ?> <?php echo $isPrimaryAdviser ? 'primary-adviser' : ''; ?>">
                                <i class="bi bi-person me-1"></i>
                                <?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?> 
                                (<?php echo $displayRole; ?>)
                                <?php if ($p['user_id'] == $user_id): ?> - You<?php endif; ?>
                                <?php if ($isPrimaryAdviser): ?> - Primary<?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                    </div>               
                    <?php if (!empty($available)): ?>
                        <form method="POST" class="mt-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="mb-0">Add Advisers & Panel Members from <?php echo htmlspecialchars($group['college']); ?>:</h6>
                                <small class="text-muted">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Only advisers and panel members from your college can be added
                                </small>
                            </div>
                            
                            <div class="row mb-3">
                                <?php 
                                $available_advisers = array_filter($available, function($p) { return $p['role'] == 'adviser'; });
                                $available_panels = array_filter($available, function($p) { return $p['role'] == 'panel'; });
                                ?>
                                
                                <?php if (!empty($available_advisers)): ?>
                                    <div class="col-12 mb-3">
                                        <h6 class="text-success mb-2">
                                            <i class="bi bi-person-check me-1"></i>Available Advisers:
                                        </h6>
                                        <div class="row">
                                            <?php foreach ($available_advisers as $adviser): ?>
                                                <div class="col-md-6 col-lg-4 mb-2">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="new_participants[]" 
                                                               value="<?php echo $adviser['user_id']; ?>" id="adviser_<?php echo $adviser['user_id']; ?>">
                                                        <label class="form-check-label" for="adviser_<?php echo $adviser['user_id']; ?>">
                                                            <i class="bi bi-person-check me-1 text-success"></i>
                                                            <strong><?php echo htmlspecialchars($adviser['first_name'] . ' ' . $adviser['last_name']); ?></strong>
                                                            <br><small class="text-muted">Adviser • <?php echo htmlspecialchars($adviser['college']); ?></small>
                                                        </label>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($available_panels)): ?>
                                    <div class="col-12">
                                        <h6 class="text-primary mb-2">
                                            <i class="bi bi-person-badge me-1"></i>Available Panel Members:
                                        </h6>
                                        <div class="row">
                                            <?php foreach ($available_panels as $panel): ?>
                                                <div class="col-md-6 col-lg-4 mb-2">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="new_participants[]" 
                                                               value="<?php echo $panel['user_id']; ?>" id="panel_<?php echo $panel['user_id']; ?>">
                                                        <label class="form-check-label" for="panel_<?php echo $panel['user_id']; ?>">
                                                            <i class="bi bi-person-badge me-1 text-primary"></i>
                                                            <?php echo htmlspecialchars($panel['first_name'] . ' ' . $panel['last_name']); ?>
                                                            <br><small class="text-muted">Panel Member • <?php echo htmlspecialchars($panel['college']); ?></small>
                                                        </label>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <button type="submit" name="add_participants" class="btn btn-success" id="addParticipantsBtn" disabled>
                                <i class="bi bi-person-plus me-2"></i>Add Selected Participants
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Discussion Messages -->
            <div class="dashboard-section">
                <div class="section-header">
                    <i class="bi bi-chat-square-text me-2"></i>
                    Discussion Messages (<?php echo count($messages); ?>)
                </div>
                <div class="section-body p-0">
                    <div class="chat-container" id="messagesContainer">
                        <?php if (empty($messages)): ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i class="bi bi-chat-dots"></i>
                                </div>
                                <h6 class="text-muted">No messages yet</h6>
                                <p class="text-muted">Start the conversation by sending your first message below!</p>
                            </div>
                        <?php else: ?>
                            <div class="messages-wrapper">
                                <?php foreach ($messages as $msg): ?>
                                    <div class="message-bubble <?php echo $msg['user_id'] == $user_id ? 'self' : 'other'; ?>">
                                        <div class="message-header">
                                            <div class="message-author">
                                                <?php if ($msg['user_id'] == $user_id): ?>
                                                    You
                                                <?php else: ?>
                                                    <?php echo htmlspecialchars($msg['first_name'] . ' ' . $msg['last_name']); ?>
                                                <?php endif; ?>
                                                <?php 
                                                // Check if this adviser is the primary adviser
                                                $isPrimaryAdviser = ($msg['role'] == 'adviser' && $msg['user_id'] == $group['adviser_id']);
                                                $displayRole = $isPrimaryAdviser ? 'Adviser' : ($msg['role'] == 'adviser' ? 'Panel' : ucfirst($msg['role']));
                                                $badgeClass = $isPrimaryAdviser ? 'adviser' : ($msg['role'] == 'adviser' ? 'panel' : $msg['role']);
                                                ?>
                                                <span class="role-badge role-<?php echo $badgeClass; ?>" style="font-size: 0.6rem; padding: 0.1rem 0.4rem;">
                                                    <?php echo $displayRole; ?>
                                                </span>
                                            </div>
                                            <div class="message-time">
                                                <?php echo date('M d, g:i A', strtotime($msg['created_at'])); ?>
                                            </div>
                                        </div>
                                        
                                        <?php if ($msg['message_text']): ?>
                                            <div class="message-content">
                                                <?php echo nl2br(htmlspecialchars($msg['message_text'])); ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($msg['file_path']): ?>
                                            <div class="message-attachment">
                                                <?php
                                                $full_path = '../' . $msg['file_path'];
                                                if (file_exists($full_path)) {
                                                    $file_extension = strtolower(pathinfo($msg['file_path'], PATHINFO_EXTENSION));
                                                    $file_size = filesize($full_path);
                                                    $file_size_mb = round($file_size / (1024 * 1024), 2);
                                                    $is_image = in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                                                    
                                                    if ($is_image) {
                                                        // IMAGE DISPLAY
                                                        echo '<div class="image-preview-container" style="max-width: 300px; margin-top: 0.5rem;">';
                                                        echo '<img src="../' . htmlspecialchars($msg['file_path']) . '" ';
                                                        echo 'alt="' . htmlspecialchars($msg['original_filename']) . '" ';
                                                        echo 'class="img-fluid rounded" ';
                                                        echo 'style="cursor: pointer; max-height: 300px; object-fit: contain; border: 2px solid rgba(255,255,255,0.1);" ';
                                                        echo 'data-bs-toggle="modal" data-bs-target="#imageModal" ';
                                                        echo 'data-image-url="../' . htmlspecialchars($msg['file_path']) . '" ';
                                                        echo 'data-image-name="' . htmlspecialchars($msg['original_filename']) . '">';
                                                        echo '<div class="mt-2 small text-muted">';
                                                        echo '<i class="bi bi-image"></i> ';
                                                        echo htmlspecialchars($msg['original_filename']) . ' (' . $file_size_mb . ' MB)';
                                                        echo '</div>';
                                                        echo '<div class="mt-1">';
                                                        echo '<a href="../' . htmlspecialchars($msg['file_path']) . '" download class="btn btn-sm btn-outline-primary">';
                                                        echo '<i class="bi bi-download"></i> Download';
                                                        echo '</a>';
                                                        echo '</div>';
                                                        echo '</div>';
                                                    } else {
                                                        // NON-IMAGE FILES
                                                        $file_icon = 'file-earmark';
                                                        $icon_color = 'text-primary';
                                                        
                                                        switch ($file_extension) {
                                                            case 'pdf':
                                                                $file_icon = 'file-earmark-pdf';
                                                                $icon_color = 'text-danger';
                                                                break;
                                                            case 'doc':
                                                            case 'docx':
                                                                $file_icon = 'file-earmark-word';
                                                                $icon_color = 'text-primary';
                                                                break;
                                                            case 'txt':
                                                                $file_icon = 'file-earmark-text';
                                                                $icon_color = 'text-info';
                                                                break;
                                                        }
                                                        
                                                        echo '<div class="file-attachment">';
                                                        echo '<i class="bi bi-' . $file_icon . ' file-icon ' . $icon_color . '"></i>';
                                                        echo '<div class="file-info">';
                                                        echo '<div class="file-name">' . htmlspecialchars($msg['original_filename']) . '</div>';
                                                        echo '<div class="file-size">' . $file_size_mb . ' MB</div>';
                                                        echo '</div>';
                                                        
                                                        if ($file_extension === 'pdf') {
                                                            echo '<button type="button" class="btn btn-sm btn-outline-primary me-2" ';
                                                            echo 'data-bs-toggle="modal" data-bs-target="#pdfModal" ';
                                                            echo 'data-pdf-url="../' . htmlspecialchars($msg['file_path']) . '" ';
                                                            echo 'data-pdf-name="' . htmlspecialchars($msg['original_filename']) . '">';
                                                            echo '<i class="bi bi-eye"></i> View';
                                                            echo '</button>';
                                                        }
                                                        
                                                        echo '<a href="../' . htmlspecialchars($msg['file_path']) . '" download class="btn btn-sm btn-outline-primary">';
                                                        echo '<i class="bi bi-download"></i>';
                                                        echo '</a>';
                                                        echo '</div>';
                                                    }
                                                } else {
                                                    echo '<div class="file-attachment text-muted">';
                                                    echo '<i class="bi bi-exclamation-triangle file-icon"></i>';
                                                    echo '<div class="file-info">';
                                                    echo '<div class="file-name">File not found</div>';
                                                    echo '<div class="file-size">' . htmlspecialchars($msg['original_filename']) . '</div>';
                                                    echo '</div>';
                                                    echo '</div>';
                                                }
                                                ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Send Message -->
            <div class="dashboard-section">
                <div class="section-header">
                    <i class="bi bi-send me-2"></i>Send Message
                </div>
                <div class="section-body">
                    <form method="POST" enctype="multipart/form-data" id="messageForm">
                        <div class="mb-3">
                            <label for="messageText" class="form-label">Your Message</label>
                            <textarea class="form-control" name="message" id="messageText" rows="4" 
                                      placeholder="Share updates, ask questions, discuss your research progress, or provide feedback..."></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="fileAttachment" class="form-label">Attach File</label>
                            <input type="file" class="form-control" name="attachment" id="fileAttachment"
                                accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp,.txt,image/*">
                            <div class="form-text">
                                <i class="bi bi-info-circle me-1"></i>
                                Supported formats: PDF, DOC, DOCX, Images (JPG, PNG, GIF, WebP), TXT. Maximum size: 25MB
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-end">
                            <button type="submit" name="send_message" class="btn btn-primary" id="sendButton">
                                <i class="bi bi-send me-2"></i>Send Message
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        <?php endif; ?>
    </div>

    <!-- PDF Modal -->
    <div class="modal fade pdf-modal" id="pdfModal" tabindex="-1" aria-labelledby="pdfModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-fullscreen-md-down modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="pdfModalLabel">
                        <i class="bi bi-file-earmark-pdf"></i>
                        <span id="pdfModalTitle">PDF Document</span>
                    </h5>
                    <div class="header-actions">
                        <a id="pdfDownloadBtn" href="#" download class="btn btn-sm btn-outline-light">
                            <i class="bi bi-download"></i>
                            <span class="btn-text">Download</span>
                        </a>
                        <a id="pdfOpenBtn" href="#" target="_blank" class="btn btn-sm btn-outline-light">
                            <i class="bi bi-box-arrow-up-right"></i>
                            <span class="btn-text">Open</span>
                        </a>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                </div>
                <div class="modal-body p-0">
                    <iframe id="pdfModalFrame" style="width: 100%; height: 80vh; border: none;" src=""></iframe>
                </div>
            </div>
        </div>
    </div>
    <!-- Image Modal -->
    <div class="modal fade" id="imageModal" tabindex="-1" aria-labelledby="imageModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="imageModalLabel">
                        <i class="bi bi-image"></i>
                        <span id="imageModalTitle">Image</span>
                    </h5>
                    <div class="header-actions">
                        <a id="imageDownloadBtn" href="#" download class="btn btn-sm btn-outline-light">
                            <i class="bi bi-download"></i>
                            <span class="btn-text">Download</span>
                        </a>
                        <a id="imageOpenBtn" href="#" target="_blank" class="btn btn-sm btn-outline-light">
                            <i class="bi bi-box-arrow-up-right"></i>
                            <span class="btn-text">Open</span>
                        </a>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                </div>
                <div class="modal-body">
                    <img id="imageModalImg" src="" alt="Image">
                </div>
            </div>
        </div>
    </div>               
    

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-scroll messages to bottom on page load
        document.addEventListener('DOMContentLoaded', function() {
            const messagesContainer = document.getElementById('messagesContainer');
            if (messagesContainer && messagesContainer.scrollHeight > messagesContainer.clientHeight) {
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }
        });


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

        // Event Listeners for notifications
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

        // Add bell shake animation on page load
        document.addEventListener('DOMContentLoaded', function() {
            const bellButton = document.getElementById('notificationBell');
            const notificationCount = <?php echo $total_unviewed; ?>;
            
            if (bellButton && notificationCount > 0) {
                bellButton.style.animation = 'shake 0.5s ease-in-out';
            }
            
            // Auto-scroll messages to bottom
            const messagesContainer = document.getElementById('messagesContainer');
            if (messagesContainer && messagesContainer.scrollHeight > messagesContainer.clientHeight) {
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }
        });


        // Initialize notification polling
        setInterval(updateNotificationCount, 30000);

        // PDF Modal functionality
        const pdfModal = document.getElementById('pdfModal');
        const pdfModalFrame = document.getElementById('pdfModalFrame');
        const pdfModalTitle = document.getElementById('pdfModalTitle');
        const pdfDownloadBtn = document.getElementById('pdfDownloadBtn');
        const pdfOpenBtn = document.getElementById('pdfOpenBtn');

        pdfModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const pdfUrl = button.getAttribute('data-pdf-url');
            const pdfName = button.getAttribute('data-pdf-name');

            // Update modal content
            pdfModalTitle.textContent = pdfName;
            pdfDownloadBtn.href = pdfUrl;
            pdfOpenBtn.href = pdfUrl;
            
            // Load PDF in iframe with enhanced viewing parameters
            pdfModalFrame.src = pdfUrl + '#view=FitH&toolbar=1&navpanes=1&scrollbar=1';
        });

        pdfModal.addEventListener('hidden.bs.modal', function() {
            // Clear iframe when modal is closed to stop loading
            pdfModalFrame.src = '';
        });
        // Image Modal functionality - Responsive
        const imageModal = document.getElementById('imageModal');
        if (imageModal) {
            const imageModalImg = document.getElementById('imageModalImg');
            const imageModalTitle = document.getElementById('imageModalTitle');
            const imageDownloadBtn = document.getElementById('imageDownloadBtn');
            const imageOpenBtn = document.getElementById('imageOpenBtn');

            imageModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const imageUrl = button.getAttribute('data-image-url');
                const imageName = button.getAttribute('data-image-name');

                if (imageUrl && imageName) {
                    // Update modal content
                    imageModalTitle.textContent = imageName;
                    imageModalImg.src = imageUrl;
                    imageDownloadBtn.href = imageUrl;
                    imageDownloadBtn.download = imageName;
                    imageOpenBtn.href = imageUrl;
                }
            });

            imageModal.addEventListener('hidden.bs.modal', function() {
                // Clear image when modal is closed
                imageModalImg.src = '';
                imageModalTitle.textContent = 'Image';
            });
        }
    </script>
</body>
</html>