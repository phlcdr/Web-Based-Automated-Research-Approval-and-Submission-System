<?php
session_start();
include_once '../config/database.php';
include_once '../includes/functions.php';

// Basic validation
$user_id = $_SESSION['user_id'] ?? 0;
$user_role = $_SESSION['role'] ?? '';
$user_name = $_SESSION['full_name'] ?? 'Unknown User';
$discussion_id = $_GET['id'] ?? 0;

if (!$user_id || !$discussion_id || !in_array($user_role, ['adviser', 'panel'])) {
    die('Invalid access');
}

// ============================================================================
// NOTIFICATION HANDLERS - Updated to match my_groups.php
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
// NOTIFICATION DATA FETCHING - Updated to match my_groups.php
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
        created_at
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

// Get discussion details - FIXED to include adviser_id
$stmt = $conn->prepare("
    SELECT 
        td.*, 
        rg.group_name, 
        rg.college, 
        rg.program,
        rg.lead_student_id,
        rg.adviser_id,
        CONCAT(student.first_name, ' ', student.last_name) as student_name,
        COALESCE(s.title, 'No Title Submitted Yet') as research_title,
        COALESCE(s.status, 'pending') as title_status
    FROM thesis_discussions td
    JOIN research_groups rg ON td.group_id = rg.group_id 
    JOIN users student ON rg.lead_student_id = student.user_id
    LEFT JOIN submissions s ON (rg.group_id = s.group_id AND s.submission_type = 'title' AND s.status = 'approved')
    WHERE td.discussion_id = ?
");
$stmt->execute([$discussion_id]);
$discussion = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$discussion) {
    die('Discussion not found');
}

// Handle form submission
if ($_POST && isset($_POST['message'])) {
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
        $allowed_extensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'webp'];
        $max_file_size = 25 * 1024 * 1024; // 25MB
        
        if (in_array($file_extension, $allowed_extensions) && $_FILES['attachment']['size'] <= $max_file_size) {
            $filename = 'discussion_' . $discussion_id . '_' . time() . '_' . uniqid() . '.' . $file_extension;
            $target_path = $upload_dir . $filename;
            $file_path = 'uploads/thesis_discussion/' . $filename;
            
            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $target_path)) {
                $original_filename = $_FILES['attachment']['name'];
            } else {
                $file_error = "Failed to upload file. Please try again.";
            }
        } else {
$file_error = "Invalid file type or size. Only PDF, DOC, DOCX, and image files (JPG, PNG, GIF, WebP) under 25MB are allowed.";
        }
    }
    
    // Only require either message OR file, not both
    if (!empty($message) || !empty($file_path)) {
        try {
            $sql = "INSERT INTO messages (context_type, context_id, user_id, message_text, file_path, original_filename, created_at) VALUES ('discussion', ?, ?, ?, ?, ?, NOW())";
            $stmt = $conn->prepare($sql);
            $result = $stmt->execute([$discussion_id, $user_id, $message, $file_path, $original_filename]);
            
            if ($result) {
                // Create notification for other participants - FIXED
                $notify_stmt = $conn->prepare("
                    SELECT DISTINCT u.user_id, CONCAT(u.first_name, ' ', u.last_name) as full_name
                    FROM (
                        SELECT rg.adviser_id as user_id 
                        FROM thesis_discussions td 
                        JOIN research_groups rg ON td.group_id = rg.group_id
                        WHERE td.discussion_id = ?
                        UNION
                        SELECT rg.lead_student_id as user_id 
                        FROM thesis_discussions td
                        JOIN research_groups rg ON td.group_id = rg.group_id
                        WHERE td.discussion_id = ?
                        UNION 
                        SELECT a.user_id 
                        FROM assignments a 
                        WHERE a.context_type = 'discussion' AND a.context_id = ? AND a.is_active = 1
                    ) participants
                    JOIN users u ON participants.user_id = u.user_id
                    WHERE u.user_id != ?
                ");
                $notify_stmt->execute([$discussion_id, $discussion_id, $discussion_id, $user_id]);
                $notify_stmt->execute([$discussion_id, $discussion_id, $discussion_id, $user_id]);
                $participants_to_notify = $notify_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($participants_to_notify as $participant) {
                    $notification_sql = "INSERT INTO notifications (user_id, title, message, type, context_type, context_id, created_at) VALUES (?, ?, ?, 'discussion', 'discussion', ?, NOW())";
                    $notification_stmt = $conn->prepare($notification_sql);
                    $notification_stmt->execute([
                        $participant['user_id'],
                        'New Discussion Message',
                        $user_name . ' posted a new message in the thesis discussion',
                        $discussion_id
                    ]);
                }
                
                header("Location: " . $_SERVER['PHP_SELF'] . "?id=" . $discussion_id . "&sent=1");
                exit; 
            }
        } catch (Exception $e) {
            $db_error = "Error sending message: " . $e->getMessage();
        }
    } else {
        $message_error = "Please enter a message or attach a file.";
    }
}

// Get participants - FIXED for normalized database
$stmt = $conn->prepare("
    SELECT 
        u.user_id,
        CONCAT(u.first_name, ' ', u.last_name) as participant_name,
        u.role as user_role,
        CASE 
            WHEN rg.adviser_id = u.user_id THEN 'Primary Adviser'
            WHEN rg.lead_student_id = u.user_id THEN 'Student Researcher'
            ELSE 'Panel Member'
        END as participant_role,
        u.email
    FROM thesis_discussions td
    JOIN research_groups rg ON td.group_id = rg.group_id
    JOIN users u ON (
        u.user_id = rg.adviser_id OR 
        u.user_id = rg.lead_student_id OR 
        u.user_id IN (
            SELECT DISTINCT a.user_id 
            FROM assignments a 
            WHERE a.context_type = 'discussion' 
            AND a.context_id = td.discussion_id 
            AND a.is_active = 1
        )
    )
    WHERE td.discussion_id = ?
    GROUP BY u.user_id
    ORDER BY 
        CASE u.role 
            WHEN 'adviser' THEN 1 
            WHEN 'panel' THEN 2 
            WHEN 'student' THEN 3 
            ELSE 4 
        END, 
        participant_name
");
$stmt->execute([$discussion_id]);
$participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get messages with improved query
$sql = "SELECT 
            m.*, 
            u.first_name, 
            u.last_name, 
            u.role,
            CONCAT(u.first_name, ' ', u.last_name) as sender_name
        FROM messages m 
        JOIN users u ON m.user_id = u.user_id 
        WHERE m.context_type = 'discussion' AND m.context_id = ? 
        ORDER BY m.created_at ASC";
$stmt = $conn->prepare($sql);
$stmt->execute([$discussion_id]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpg" href="../assets/images/essu logo.png">
    <title>Thesis Discussion - ESSU Research System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-blue: #1e40af;
            --primary-gold: #f59e0b;
            --light-bg: #f8fafc;
            --danger-red: #dc2626;
            --success-green: #059669;
            --info-blue: #0284c7;
            --text-secondary: #6b7280;
            --border-light: #e5e7eb;
        }

        * {
            box-sizing: border-box;
        }
        
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: var(--light-bg);
            line-height: 1.6;
            overflow-x: hidden;
        }
        
        /* University Header */
        .university-header {
            background: linear-gradient(135deg, var(--primary-blue) 0%, #1e3a8a 100%);
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
            background: linear-gradient(135deg, var(--primary-blue), #1e3a8a);
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

        .notification-icon.title { background: rgba(30, 64, 175, 0.1); color: var(--primary-blue); }
        .notification-icon.chapter { background: rgba(5, 150, 105, 0.1); color: var(--success-green); }
        .notification-icon.discussion { background: rgba(2, 132, 199, 0.1); color: var(--info-blue); }

        .notification-content {
            flex: 1;
        }

        .notification-title {
            font-weight: 600;
            font-size: 0.875rem;
            margin-bottom: 0.25rem;
            color: #111827;
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
            background: var(--primary-gold);
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
            border-left: 4px solid var(--primary-blue);
        }

        .page-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-blue);
            margin-bottom: 0;
        }

        .page-subtitle {
            color: var(--text-secondary);
            font-size: 1rem;
        }

        .header-title-wrapper {
            flex: 1;
        }

        .header-actions {
            flex-shrink: 0;
        }
        
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
            transition: box-shadow 0.3s ease;
        }

        .card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .card-header {
            background: linear-gradient(90deg, var(--primary-blue), #1e3a8a);
            color: white;
            border-radius: 12px 12px 0 0 !important;
            font-weight: 600;
            padding: 1rem 1.25rem;
        }
        
        .chat-container { 
            max-height: 500px; 
            overflow-y: auto; 
            padding: 1rem;
            background: #f8fafc;
            border-radius: 0 0 12px 12px;
        }
        /* Modal header fixes */
        .modal-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: nowrap;
        }

        .modal-header .modal-title {
            flex: 0 1 auto;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .modal-header .btn {
            flex-shrink: 0;
        }

        .modal-header .btn-close {
            flex-shrink: 0;
            margin: 0;
        }
        
        .message { 
            margin-bottom: 1rem; 
            display: flex;
            flex-direction: column;
            animation: fadeInUp 0.3s ease;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .message.own { align-items: flex-end; }
        .message.other { align-items: flex-start; }
        
        .message-info {
            font-size: 0.8rem;
            color: #6b7280;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        
        .message-bubble { 
            padding: 0.75rem 1rem; 
            border-radius: 16px; 
            max-width: 75%;
            word-wrap: break-word;
            position: relative;
        }
        
        .message.own .message-bubble { 
            background: var(--primary-blue); 
            color: white;
            border-bottom-right-radius: 4px;
        }
        
        .message.other .message-bubble { 
            background: white; 
            color: #111827;
            border: 1px solid #e5e7eb;
            border-bottom-left-radius: 4px;
        }
        
        .file-attachment {
            margin-top: 0.5rem;
            padding: 0.75rem;
            background: rgba(255,255,255,0.2);
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            transition: background 0.2s ease;
        }

        .file-attachment:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .message.other .file-attachment {
            background: #f1f5f9;
        }

        .message.other .file-attachment:hover {
            background: #e2e8f0;
        }
        
        .research-info {
            background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
            border-left: 4px solid var(--primary-blue);
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .research-title {
            color: var(--primary-blue);
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            line-height: 1.4;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-badge.approved { background: #dcfce7; color: #166534; }
        .status-badge.pending { background: #fef3c7; color: #d97706; }
        .status-badge.rejected { background: #fee2e2; color: #dc2626; }
        
        .participant-tag {
            display: inline-block;
            padding: 0.4rem 0.8rem;
            border-radius: 15px;
            font-size: 0.85rem;
            margin: 0.2rem;
            transition: transform 0.2s ease;
        }

        .participant-tag:hover {
            transform: translateY(-1px);
        }
        
        .participant-tag.student { background: #dbeafe; color: #1d4ed8; }
        .participant-tag.adviser { background: #dcfce7; color: #166534; }
        .participant-tag.panel { background: #f0f9ff; color: #0284c7; }

        .stat-label {
            font-size: 0.875rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .alert {
            border-radius: 8px;
            border: none;
        }

        .btn {
            border-radius: 8px;
            transition: all 0.2s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .form-control {
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .form-control:focus {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 0.2rem rgba(30, 64, 175, 0.25);
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 3rem;
            opacity: 0.5;
            margin-bottom: 1rem;
        }

        /* Scroll to bottom button */
        .scroll-to-bottom {
            position: absolute;
            bottom: 20px;
            right: 20px;
            background: var(--primary-blue);
            color: white;
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: none;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
            z-index: 10;
        }

        .scroll-to-bottom:hover {
            background: #1e3a8a;
            transform: scale(1.1);
        }

        .scroll-to-bottom.show {
            display: flex;
        }

        /* Image Preview Styles */
        .image-preview-container {
            position: relative;
        }

        .image-preview-container img {
            transition: transform 0.2s ease;
            border: 2px solid rgba(255,255,255,0.1);
        }

        .image-preview-container img:hover {
            transform: scale(1.02);
            border-color: rgba(255,255,255,0.3);
        }

        .message-bubble.other .image-preview-container img {
            border-color: rgba(0,0,0,0.1);
        }

        .message-bubble.other .image-preview-container img:hover {
            border-color: rgba(0,0,0,0.2);
        }

        /* Fix close button visibility */
        .modal-header .btn-close {
            position: relative;
            z-index: 1060;
            opacity: 1;
            margin-left: 0.5rem;
        }

        .modal-header .btn-close:hover {
            opacity: 0.8;
        }

        .modal-header {
            position: relative;
            z-index: 1055;
        }

        /* PDF Modal fixes */
        #pdfModal .modal-dialog {
            max-width: 95%;
            margin: 1rem auto;
        }

        #pdfModal .modal-body {
            position: relative;
            padding: 0;
            height: 85vh;
            min-height: 500px;
        }

        #pdfModalFrame {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border: none;
            z-index: 5;
        }

        /* Image Modal fixes */
        #imageModal .modal-dialog {
            max-width: 1200px;
            margin: 0.5rem auto;
        }

        #imageModal .modal-body {
            padding: 0;
            background: #f8f9fa;
            min-height: 300px;
            max-height: 85vh;
            overflow: auto;
            display: flex;
            align-items: flex-start;
            justify-content: center;
        }

        #imageModalImg {
            width: 100%;
            height: auto;
            max-width: 100%;
            object-fit: contain;
            display: block;
        }

        /* Modal Button Styles - Icon Only */
        .modal-header .btn-group {
            display: flex;
            gap: 0.5rem;
        }

        .modal-icon-btn {
            padding: 0.5rem;
            border: none;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border-radius: 6px;
            transition: all 0.2s ease;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
        }

        .modal-icon-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            color: white;
            transform: scale(1.05);
        }

        .modal-icon-btn i {
            font-size: 1.1rem;
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

            .main-content { 
                padding: 1rem 0; 
            }

            .page-header { 
                padding: 1.5rem; 
            }
            
            .page-title { 
                font-size: 1.5rem; 
            }
            
            .page-subtitle { 
                font-size: 0.9rem; 
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

            .message-bubble {
                max-width: 85%;
            }
            
            .research-info {
                padding: 1rem;
            }

            .research-title {
                font-size: 1rem;
            }

            .card-header {
                padding: 0.875rem 1rem;
                font-size: 0.95rem;
            }

            .chat-container {
                max-height: 400px;
                padding: 0.75rem;
            }

            .participant-tag {
                font-size: 0.75rem;
                padding: 0.3rem 0.6rem;
            }

            /* Modal responsiveness for tablet */
            #pdfModal .modal-dialog,
            #imageModal .modal-dialog {
                max-width: 95%;
                margin: 1rem auto;
            }

            #pdfModal .modal-body {
                height: 75vh;
                min-height: 400px;
            }

            #imageModal .modal-body {
                max-height: 75vh;
                min-height: 400px;
            }

            #imageModalImg {
                max-height: 75vh;
            }

            .modal-header h5 {
                font-size: 0.9rem;
            }

            .modal-header .btn-group {
                flex-wrap: nowrap;
                gap: 0.25rem;
            }

            .modal-header .btn {
                font-size: 0.8rem;
                padding: 0.4rem 0.6rem;
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

            .container {
                padding-left: 0.75rem;
                padding-right: 0.75rem;
            }

            .main-content { 
                padding: 1rem 0; 
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

            .header-title-wrapper {
                width: 100%;
                margin-bottom: 0.75rem;
            }

            .header-actions {
                width: 100%;
                display: flex;
                justify-content: flex-end;
            }

            .btn {
                font-size: 0.875rem;
                padding: 0.5rem 0.75rem;
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

            .card {
                margin-bottom: 1rem;
            }

            .card-header {
                padding: 0.75rem 1rem;
                font-size: 0.9rem;
            }

            .card-body {
                padding: 1rem;
            }

            .research-info {
                padding: 0.875rem;
            }

            .research-title {
                font-size: 0.95rem;
            }

            .status-badge {
                font-size: 0.65rem;
                padding: 0.2rem 0.5rem;
            }

            .participant-tag {
                font-size: 0.7rem;
                padding: 0.25rem 0.5rem;
                margin: 0.15rem;
            }

            .chat-container {
                max-height: 350px;
                padding: 0.75rem;
            }

            .message-bubble {
                max-width: 90%;
                padding: 0.625rem 0.875rem;
                font-size: 0.9rem;
            }

            .message-info {
                font-size: 0.75rem;
            }

            .file-attachment {
                padding: 0.625rem;
                gap: 0.5rem;
            }

            .file-attachment i {
                font-size: 1.25rem;
            }

            .file-attachment .btn {
                padding: 0.375rem 0.5rem;
                font-size: 0.8rem;
            }

            .image-preview-container {
                max-width: 100% !important;
            }

            .image-preview-container img {
                max-height: 200px !important;
            }

            .form-control {
                font-size: 0.9rem;
            }

            textarea.form-control {
                min-height: 80px;
            }

            .form-text {
                font-size: 0.75rem;
            }

            .alert {
                font-size: 0.875rem;
                padding: 0.75rem 1rem;
            }

            .scroll-to-bottom {
                width: 36px;
                height: 36px;
                bottom: 15px;
                right: 15px;
            }

            .modal-header {
                padding: 0.75rem 1rem;
            }

            .modal-header h5 {
                font-size: 0.95rem;
            }

            .modal-icon-btn {
                width: 32px;
                height: 32px;
                padding: 0.375rem;
            }

            .modal-icon-btn i {
                font-size: 1rem;
            }

            #pdfModal .modal-body {
                height: 70vh;
                min-height: 350px;
            }

            #imageModal .modal-body {
                min-height: 250px;
                max-height: 75vh;
                padding: 0;
            }

            #pdfModal .modal-dialog,
            #imageModal .modal-dialog {
                max-width: calc(100% - 1rem);
                margin: 0.5rem;
            }

            #imageModalImg {
                width: 100%;
                height: auto;
                max-height: none;
            }

            .modal-header .modal-title {
                font-size: 0.85rem;
                max-width: 40%;
            }

            .modal-header .btn span {
                display: none !important;
            }

            .modal-header .btn {
                padding: 0.375rem 0.5rem;
            }

            /* Make modal buttons stack on very small screens */
            .modal-header .btn-group {
                flex-direction: row;
                gap: 0.25rem;
            }

            .modal-header .btn-group .btn {
                font-size: 0.75rem;
                padding: 0.3rem 0.5rem;
                white-space: nowrap;
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

            #imageModal .modal-body {
                padding: 0.25rem;
                min-height: 250px;
            }

            #imageModalImg {
                max-height: 60vh;
            }

            .card-header {
                font-size: 0.85rem;
                padding: 0.65rem 0.875rem;
            }

            .message-bubble {
                font-size: 0.85rem;
            }

            .participant-tag {
                font-size: 0.65rem;
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

            .chat-container {
                max-height: 250px;
            }

            #pdfModal .modal-body {
                height: 80vh;
            }

            #imageModal .modal-body {
                max-height: 80vh;
            }

            #imageModalImg {
                max-height: 75vh;
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
    <!-- Header -->
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
                            <i class="bi bi-bell-fill"></i>
                            <?php if ($total_unviewed > 0): ?>
                                <span class="notification-badge" id="notificationBadge"><?php echo min($total_unviewed, 99); ?></span>
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end notification-dropdown">
                            <li class="notification-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-bell me-2"></i>Notifications</span>
                                    <span class="badge bg-light text-dark" id="notificationCount"><?php echo $total_unviewed; ?> new</span>
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
                                                            <?php echo date('M d, g:i A', strtotime($notif['created_at'])); ?>
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
                                <?php echo strtoupper(substr($user_name, 0, 2)); ?>
                            </div>
                            <div class="user-info d-none d-md-block">
                                <div class="user-name"><?php echo htmlspecialchars($user_name); ?></div>
                                <div class="user-role"><?php echo $user_role === 'adviser' ? 'Research Adviser' : 'Panel Member'; ?></div>
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

    <div class="container main-content">
        <!-- Page Header -->
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-start flex-wrap">
                <div class="header-title-wrapper">
                    <h1 class="page-title mb-1">
                        <i class="bi bi-chat-square-text me-2"></i>Thesis Discussion
                    </h1>
                    <p class="page-subtitle mb-0">Collaborate and discuss research progress</p>
                </div>
                <div class="header-actions">
                    <a href="thesis_inbox.php" class="btn btn-outline-primary">
                        <i class="bi bi-arrow-left me-1"></i><span class="d-none d-sm-inline"> Back to</span> Inbox
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Success/Error Messages -->
        <?php if (isset($_GET['sent'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i>Message sent successfully!
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($file_error)): ?>
            <div class="alert alert-warning alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($file_error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($message_error)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-x-circle me-2"></i><?= htmlspecialchars($message_error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($db_error)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-x-circle me-2"></i><?= htmlspecialchars($db_error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Research Information -->
        <?php if ($discussion): ?>
        <div class="card">
            <div class="card-header">
                <i class="bi bi-journal-text me-2"></i>Research Information
            </div>
            <div class="card-body">
                <div class="research-info">
                    <div class="research-title">
                        <?= htmlspecialchars($discussion['research_title']) ?>
                        <span class="status-badge <?= $discussion['title_status'] ?> ms-2">
                            <?= ucfirst($discussion['title_status']) ?>
                        </span>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <strong><i class="bi bi-person me-1"></i>Student:</strong> <?= htmlspecialchars($discussion['student_name']) ?><br>
                            <strong><i class="bi bi-people me-1"></i>Group:</strong> <?= htmlspecialchars($discussion['group_name']) ?>
                        </div>
                        <div class="col-md-6">
                            <strong><i class="bi bi-building me-1"></i>College:</strong> <?= htmlspecialchars($discussion['college']) ?><br>
                            <strong><i class="bi bi-mortarboard me-1"></i>Program:</strong> <?= htmlspecialchars($discussion['program']) ?>
                        </div>
                    </div>
                </div>
                
                
                <h6 class="mt-3 mb-2">
                    <i class="bi bi-people me-2"></i>Participants (<?= count($participants) ?>):
                </h6>
                <div>
                    <?php foreach ($participants as $p): ?>
                        <?php 
                        // Determine the role badge
                        $isStudent = ($p['user_role'] == 'student');
                        $isPrimaryAdviser = ($p['user_role'] == 'adviser' && $p['user_id'] == $discussion['adviser_id']);
                        
                        // Display role and badge class
                        if ($isStudent) {
                            $displayRole = 'Student';
                            $badgeClass = 'student';
                        } elseif ($isPrimaryAdviser) {
                            $displayRole = 'Adviser';
                            $badgeClass = 'adviser';
                        } else {
                            $displayRole = 'Panel';
                            $badgeClass = 'panel';
                        }
                        ?>
                        <span class="participant-tag <?php echo $badgeClass; ?>">
                            <i class="bi bi-person me-1"></i>
                            <?php echo htmlspecialchars($p['participant_name']); ?> 
                            (<?php echo $displayRole; ?>)
                            <?php if ($p['user_id'] == $user_id): ?> 
                                <span class="badge bg-light text-dark ms-1">You</span>
                            <?php endif; ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Messages -->
        <div class="card position-relative">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-chat-square-text me-2"></i>Messages (<?= count($messages) ?>)
                </div>
            </div>
            <div class="card-body p-0">
                <div class="chat-container" id="chatArea">
                    <?php if (empty($messages)): ?>
                        <div class="empty-state">
                            <i class="bi bi-chat-dots text-muted"></i>
                            <h6 class="text-muted">No messages yet</h6>
                            <p class="text-muted">Start the conversation below to begin discussing the thesis!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($messages as $msg): ?>
                            <div class="message <?= ($msg['user_id'] ?? 0) == $user_id ? 'own' : 'other' ?>" data-message-id="<?= $msg['message_id'] ?? '' ?>">
                                <div class="message-info">
                                    <strong>
                                        <?= ($msg['user_id'] ?? 0) == $user_id ? 'You' : htmlspecialchars($msg['sender_name'] ?? 'Unknown User') ?>
                                    </strong>
                                    <span class="text-muted">
                                        • <?= date('M d, g:i A', strtotime($msg['created_at'] ?? 'now')) ?>
                                    </span>
                                </div>
                                <div class="message-bubble">
                                    <?php if (!empty($msg['message_text'])): ?>
                                        <?= nl2br(htmlspecialchars($msg['message_text'])) ?>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($msg['file_path']) && file_exists('../' . $msg['file_path'])): ?>
                                        <?php
                                        $file_extension = strtolower(pathinfo($msg['file_path'], PATHINFO_EXTENSION));
                                        $file_size_mb = round(filesize('../' . $msg['file_path']) / (1024 * 1024), 2);
                                        $is_image = in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                                        ?>
                                        
                                        <?php if ($is_image): ?>
                                            <!-- IMAGE DISPLAY -->
                                            <div class="file-attachment">
                                                <div class="image-preview-container" style="max-width: 300px; margin-top: 0.5rem;">
                                                    <img src="../<?= htmlspecialchars($msg['file_path']) ?>" 
                                                        alt="<?= htmlspecialchars($msg['original_filename'] ?? 'Image') ?>"
                                                        class="img-fluid rounded"
                                                        style="cursor: pointer; max-height: 300px; object-fit: contain;"
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#imageModal"
                                                        data-image-url="../<?= htmlspecialchars($msg['file_path']) ?>"
                                                        data-image-name="<?= htmlspecialchars($msg['original_filename'] ?? 'Image') ?>">
                                                    <div class="mt-1">
                                                        <a href="../<?= htmlspecialchars($msg['file_path']) ?>" download 
                                                        class="btn btn-sm btn-dark" title="Download">
                                                            <i class="bi bi-download"></i> Download
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php elseif ($file_extension === 'pdf'): ?>
                                            <!-- Keep existing PDF code -->
                                            <div class="file-attachment">
                                                <i class="bi bi-file-earmark-pdf text-danger" style="font-size: 1.5rem;"></i>
                                                <div class="flex-grow-1">
                                                    <div class="fw-medium"><?= htmlspecialchars($msg['original_filename'] ?? 'Unknown File') ?></div>
                                                    <small class="text-muted"><?= $file_size_mb ?> MB • PDF Document</small>
                                                </div>
                                                <div class="btn-group" role="group">
                                                    <button type="button" class="btn btn-sm btn-dark" 
                                                            data-bs-toggle="modal" data-bs-target="#pdfModal"
                                                            data-pdf-url="../<?= htmlspecialchars($msg['file_path']) ?>"
                                                            data-pdf-name="<?= htmlspecialchars($msg['original_filename'] ?? 'Document') ?>"
                                                            title="View PDF">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <a href="../<?= htmlspecialchars($msg['file_path']) ?>" download 
                                                    class="btn btn-sm btn-dark" title="Download">
                                                        <i class="bi bi-download"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <!-- Keep existing Word document code -->
                                            <div class="file-attachment">
                                                <i class="bi bi-file-earmark-word text-primary" style="font-size: 1.5rem;"></i>
                                                <div class="flex-grow-1">
                                                    <div class="fw-medium"><?= htmlspecialchars($msg['original_filename'] ?? 'Unknown File') ?></div>
                                                    <small class="text-muted"><?= $file_size_mb ?> MB • Word Document</small>
                                                </div>
                                                <a href="../<?= htmlspecialchars($msg['file_path']) ?>" download 
                                                class="btn btn-sm btn-dark" title="Download">
                                                    <i class="bi bi-download"></i>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <button class="scroll-to-bottom" id="scrollToBottomBtn" onclick="scrollToBottom()" title="Scroll to bottom">
                    <i class="bi bi-arrow-down"></i>
                </button>
            </div>
        </div>
        
        <!-- Send Message Form -->
        <div class="card">
            <div class="card-header">
                <i class="bi bi-send me-2"></i>Send Message
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" id="messageForm">
                    <div class="mb-3">
                        <label for="message" class="form-label">Message</label>
                        <textarea name="message" id="message" class="form-control" rows="4" 
                                  placeholder="Share feedback, guidance, ask questions, or provide updates..."></textarea>
                        <div class="form-text">
                            <i class="bi bi-info-circle me-1"></i>
                            You can send just a file attachment without a message, or include both.
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="attachment" class="form-label">Attachment</label>
                        <input type="file" name="attachment" id="attachment" class="form-control" 
                            accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp,image/*">
                        <div class="form-text">
                            <i class="bi bi-paperclip me-1"></i>
                            PDF, DOC, DOCX, and image files (JPG, PNG, GIF, WebP) • Maximum 25MB
                        </div>
                        <div id="filePreview" class="mt-2" style="display: none;">
                            <div class="alert alert-info">
                                <i class="bi bi-file-earmark me-2"></i>
                                <span id="fileName"></span>
                                <button type="button" class="btn-close float-end" onclick="clearFile()"></button>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end align-items-center">
                        <button type="submit" class="btn btn-primary" id="sendBtn">
                            <i class="bi bi-send me-2"></i>Send Message
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- PDF Modal -->
    <div class="modal fade" id="pdfModal" tabindex="-1" aria-labelledby="pdfModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="pdfModalLabel">
                        <i class="bi bi-file-earmark-pdf me-2"></i>
                        <span id="pdfModalTitle">PDF Document</span>
                    </h5>
                    <div class="btn-group ms-auto me-2">
                        <a id="pdfDownloadBtn" href="#" download class="btn btn-sm btn-light">
                            <i class="bi bi-download"></i>
                            <span class="d-none d-sm-inline ms-1">Download</span>
                        </a>
                        <a id="pdfOpenBtn" href="#" target="_blank" class="btn btn-sm btn-light">
                            <i class="bi bi-arrow-up-right-square"></i>
                            <span class="d-none d-sm-inline ms-1">New Tab</span>
                        </a>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0">
                    <iframe id="pdfModalFrame" src=""></iframe>
                </div>
            </div>
        </div>
    </div>
    <!-- Image Modal -->
    <div class="modal fade" id="imageModal" tabindex="-1" aria-labelledby="imageModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title me-auto" id="imageModalLabel">
                        <i class="bi bi-image me-2"></i>
                        <span id="imageModalTitle">Image</span>
                    </h5>
                    <a id="imageDownloadBtn" href="#" download class="btn btn-sm btn-light me-2">
                        <i class="bi bi-download"></i>
                        <span class="d-none d-sm-inline ms-1">Download</span>
                    </a>
                    <a id="imageOpenBtn" href="#" target="_blank" class="btn btn-sm btn-light me-2">
                        <i class="bi bi-arrow-up-right-square"></i>
                        <span class="d-none d-sm-inline ms-1">New Tab</span>
                    </a>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <img id="imageModalImg" src="" alt="Image" class="img-fluid">
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Enhanced notification system functionality
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

        // Chat functionality
        function scrollToBottom() {
            const chat = document.getElementById('chatArea');
            chat.scrollTo({
                top: chat.scrollHeight,
                behavior: 'smooth'
            });
        }

        function scrollToTop() {
            const chat = document.getElementById('chatArea');
            chat.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }


        function clearForm() {
            document.getElementById('messageForm').reset();
            clearFile();
        }

        function clearFile() {
            document.getElementById('attachment').value = '';
            document.getElementById('filePreview').style.display = 'none';
        }


        // File upload preview
        document.getElementById('attachment').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const preview = document.getElementById('filePreview');
            const fileName = document.getElementById('fileName');
            
            if (file) {
                const fileSize = (file.size / (1024 * 1024)).toFixed(2);
                fileName.textContent = `${file.name} (${fileSize} MB)`;
                preview.style.display = 'block';
            } else {
                preview.style.display = 'none';
            }
        });

        // Form submission handling
        document.getElementById('messageForm').addEventListener('submit', function(e) {
            const sendBtn = document.getElementById('sendBtn');
            const message = document.getElementById('message').value.trim();
            const attachment = document.getElementById('attachment').files[0];
            
            // Allow submission with just attachment, no message required
            if (!message && !attachment) {
                e.preventDefault();
                alert('Please enter a message or attach a file.');
                return;
            }
            
            sendBtn.disabled = true;
            sendBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Sending...';
        });

        // PDF Modal functionality - WORKING VERSION (replace the existing PDF modal section only)
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
        // Image Modal functionality
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

                // Update modal content
                imageModalTitle.textContent = imageName;
                imageModalImg.src = imageUrl;
                imageDownloadBtn.href = imageUrl;
                imageOpenBtn.href = imageUrl;
            });

            imageModal.addEventListener('hidden.bs.modal', function() {
                // Clear image when modal is closed
                imageModalImg.src = '';
            });
        }

        // Enhanced notification system functionality
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

        // Chat functionality
        function scrollToBottom() {
            const chat = document.getElementById('chatArea');
            chat.scrollTo({
                top: chat.scrollHeight,
                behavior: 'smooth'
            });
        }

        function scrollToTop() {
            const chat = document.getElementById('chatArea');
            chat.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }

        function clearForm() {
            document.getElementById('messageForm').reset();
            clearFile();
        }

        function clearFile() {
            document.getElementById('attachment').value = '';
            document.getElementById('filePreview').style.display = 'none';
        }

        // File upload preview
        document.getElementById('attachment').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const preview = document.getElementById('filePreview');
            const fileName = document.getElementById('fileName');
            
            if (file) {
                const fileSize = (file.size / (1024 * 1024)).toFixed(2);
                fileName.textContent = `${file.name} (${fileSize} MB)`;
                preview.style.display = 'block';
            } else {
                preview.style.display = 'none';
            }
        });

        // Form submission handling
        document.getElementById('messageForm').addEventListener('submit', function(e) {
            const sendBtn = document.getElementById('sendBtn');
            const message = document.getElementById('message').value.trim();
            const attachment = document.getElementById('attachment').files[0];
            
            // Allow submission with just attachment, no message required
            if (!message && !attachment) {
                e.preventDefault();
                alert('Please enter a message or attach a file.');
                return;
            }
            
            sendBtn.disabled = true;
            sendBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Sending...';
        });

        // Scroll to bottom button visibility
        function checkScrollPosition() {
            const chatArea = document.getElementById('chatArea');
            const scrollBtn = document.getElementById('scrollToBottomBtn');
            
            if (chatArea.scrollTop < chatArea.scrollHeight - chatArea.clientHeight - 100) {
                scrollBtn.classList.add('show');
            } else {
                scrollBtn.classList.remove('show');
            }
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            const chat = document.getElementById('chatArea');
            
            // Auto scroll to bottom on load
            scrollToBottom();
            
            // Notification bell animation
            const bellButton = document.getElementById('notificationBell');
            if (bellButton && <?php echo $total_unviewed; ?> > 0) {
                bellButton.style.animation = 'shake 0.5s ease-in-out';
            }
            
            // Auto-refresh notification count every 30 seconds
            setInterval(updateNotificationCount, 30000);
            
            // Monitor scroll position for scroll-to-bottom button
            chat.addEventListener('scroll', checkScrollPosition);
            
            // Focus on message textarea
            document.getElementById('message').focus();
            
            // Auto-resize textarea
            const textarea = document.getElementById('message');
            textarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + Enter to send message
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('messageForm').dispatchEvent(new Event('submit'));
            }
            
            // Escape to clear form
            if (e.key === 'Escape') {
                clearForm();
            }
        });
    </script>
</body>
</html>