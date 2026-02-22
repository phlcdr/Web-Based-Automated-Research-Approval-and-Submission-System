<?php
session_start();
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

// Get user's college for filtering
$user_college = $current_user['college'];

// Check if user is part of a group
$stmt = $conn->prepare("SELECT * FROM research_groups WHERE lead_student_id = ?");
$stmt->execute([$user_id]);
$group = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$group) {
    header("Location: create_group.php?error=need_group");
    exit();
}

// Get current title from submissions table
$current_title = null;
$stmt = $conn->prepare("SELECT * FROM submissions WHERE group_id = ? AND submission_type = 'title' ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$group['group_id']]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);
if ($result) {
    $current_title = $result;
}

// Get all title submissions for history
$all_titles = [];
try {
    $stmt = $conn->prepare("
        SELECT s.*, 
               COUNT(DISTINCT r.review_id) as total_reviews,
               COUNT(DISTINCT CASE WHEN r.decision = 'approve' THEN r.review_id END) as approvals,
               COUNT(DISTINCT a.assignment_id) as total_assigned_reviewers
        FROM submissions s
        LEFT JOIN reviews r ON s.submission_id = r.submission_id
        LEFT JOIN assignments a ON s.submission_id = a.context_id 
            AND a.context_type = 'submission' 
            AND a.assignment_type = 'reviewer' 
            AND a.is_active = 1
        WHERE s.group_id = ? AND s.submission_type = 'title'
        GROUP BY s.submission_id
        ORDER BY s.created_at DESC
    ");
    $stmt->execute([$group['group_id']]);
    $all_titles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching title history: " . $e->getMessage());
    $all_titles = [];
}

// Get adviser info
$adviser = null;
if ($group['adviser_id']) {
    $stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
    $stmt->execute([$group['adviser_id']]);
    $adviser = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get notification data
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
        created_at
    FROM notifications 
    WHERE user_id = ? AND is_read = 0
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute([$user_id]);
$recent_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total_unviewed = count($recent_notifications);

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    $title = validate_input($_POST['title']);
    $description = validate_input($_POST['description']);

    // Handle file upload
    $document_path = '';
    $file_upload_error = '';
    
    if (isset($_FILES['document']) && $_FILES['document']['error'] == UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/titles/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $allowed_types = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $file_type = $_FILES['document']['type'];
        $file_info = pathinfo($_FILES['document']['name']);
        $file_extension = strtolower($file_info['extension']);
        
        if (!in_array($file_type, $allowed_types) && !in_array($file_extension, ['pdf', 'doc', 'docx'])) {
            $file_upload_error = "Invalid file type. Please upload PDF, DOC, or DOCX files only.";
        } elseif ($_FILES['document']['size'] > 10 * 1024 * 1024) {
            $file_upload_error = "File size too large. Maximum size is 10MB.";
        } else {
            $filename = 'title_' . $group['group_id'] . '_' . time() . '.' . $file_extension;
            $target_path = $upload_dir . $filename;
            $relative_path = 'uploads/titles/' . $filename;

            if (move_uploaded_file($_FILES['document']['tmp_name'], $target_path)) {
                $document_path = $relative_path;
            } else {
                $file_upload_error = "Failed to upload file. Please try again.";
            }
        }
    }

    // Validation
    if (empty($title) || empty($description)) {
        $error = "Title and description are required";
    } elseif (!empty($file_upload_error)) {
        $error = $file_upload_error;
    }

    // If no errors, save to database
    if (empty($error)) {
        try {
            $conn->beginTransaction();

            // Insert into submissions table (required_approvals will be set when reviewers are assigned)
            $sql = "INSERT INTO submissions (group_id, submission_type, title, description, document_path, status, created_at) VALUES (?, 'title', ?, ?, ?, 'pending', NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$group['group_id'], $title, $description, $document_path]);
            $submission_id = $conn->lastInsertId();

            // Notify admins to assign reviewers
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE role = 'admin' AND is_active = 1");
            $stmt->execute();
            $admins = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($admins as $admin_id) {
                $stmt = $conn->prepare("
                    INSERT INTO notifications (user_id, title, message, type, context_type, context_id) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $admin_id,
                    'New Title Needs Reviewer Assignment',
                    'A new research title "' . $title . '" has been submitted by ' . $_SESSION['full_name'] . ' and needs reviewers assigned.',
                    'title_submission',
                    'submission',
                    $submission_id
                ]);
            }

            $conn->commit();
            header("Location: submit_title.php?success=submitted");
            exit();
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "An error occurred: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpg" href="../assets/images/essu logo.png">
    <title>Submit Research Title - ESSU Research System</title>
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

        .form-control,
        .form-select {
            border: 2px solid var(--border-light);
            border-radius: 8px;
            padding: 0.75rem;
            transition: all 0.3s ease;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--university-blue);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .form-label {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .form-text {
            color: var(--text-secondary);
            font-size: 0.875rem;
        }

        .timeline {
            position: relative;
            padding-left: 2rem;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 0.75rem;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--border-light);
        }

        .timeline-item {
            position: relative;
            margin-bottom: 2rem;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -2.25rem;
            top: 0.5rem;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--university-blue);
        }

        .comment-item {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            border-left: 4px solid var(--university-blue);
        }

        .author-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--university-blue), #1d4ed8);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1rem;
        }

        .status-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-approved {
            background: #d1fae5;
            color: #065f46;
        }

        .status-rejected {
            background: #fee2e2;
            color: #991b1b;
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

        .btn-success {
            background: var(--success-green);
            color: white;
        }

        .btn-success:hover {
            background: #047857;
            transform: translateY(-1px);
        }

        .btn-outline-primary {
            border: 1px solid var(--university-blue);
            color: var(--university-blue);
            background: transparent;
        }

        .btn-outline-primary:hover {
            background: var(--university-blue);
            color: white;
        }

        .btn-outline-secondary {
            border: 1px solid var(--text-secondary);
            color: var(--text-secondary);
            background: transparent;
        }

        .btn-outline-secondary:hover {
            background: var(--text-secondary);
            color: white;
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

        .dropdown-menu {
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            border: 1px solid var(--border-light);
        }

        /* PDF Viewer Modal Styles */
        .pdf-modal .modal-dialog {
            max-width: 90vw;
            height: 90vh;
        }

        .pdf-modal .modal-content {
            height: 100%;
        }

        .pdf-modal .modal-body {
            padding: 0;
            height: calc(100vh - 120px);  /* Adjust this value */
            min-height: 500px;
            background: #525659;
            overflow: hidden;
        }

        .pdf-modal iframe {
            width: 100%;
            height: 100%;
            border: none;
        }

        /* ============================================= */
        /* RESPONSIVE STYLES - TABLET (768px and below) */
        /* ============================================= */
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
            
            .university-header {
                padding: 0.75rem 0;
            }
            
            .page-title {
                font-size: 1.5rem;
            }
            
            .page-subtitle {
                font-size: 0.9rem;
            }
            
            .page-header {
                padding: 1.5rem;
                margin-bottom: 1.5rem;
            }
            
            .nav-tabs .nav-link {
                padding: 0.85rem 0.6rem;
                font-size: 0.85rem;
            }
            
            .section-header {
                padding: 0.75rem 1rem;
                font-size: 0.9rem;
            }
            
            .section-body {
                padding: 1rem;
            }
            
            .main-content {
                padding: 1rem 0;
            }
            
            .timeline {
                padding-left: 1.5rem;
            }
            
            .timeline::before {
                left: 0.5rem;
            }
            
            .timeline-item::before {
                left: -1.75rem;
                width: 8px;
                height: 8px;
            }
            
            .comment-item {
                padding: 0.75rem;
            }
            
            .author-avatar {
                width: 35px;
                height: 35px;
                font-size: 0.9rem;
            }
            
            .status-badge {
                font-size: 0.75rem;
                padding: 0.25rem 0.5rem;
            }
            
            .btn {
                padding: 0.4rem 0.75rem;
                font-size: 0.875rem;
            }
            
            .form-control,
            .form-select {
                padding: 0.6rem;
                font-size: 0.9rem;
            }
            
            .card-header {
                padding: 0.75rem;
            }
            
            .card-body {
                padding: 0.75rem;
            }
            
            .progress-bar {
                font-size: 0.75rem;
            }
            
            .row.mb-3 {
                margin-bottom: 1rem !important;
            }
            
            .row.mb-3 .col-md-4 {
                margin-bottom: 0.5rem;
            }
        }

        /* ============================================ */
        /* RESPONSIVE STYLES - MOBILE (576px and below) */
        /* ============================================ */
        @media (max-width: 576px) {
            .user-info {
                display: none;
            }
            .pdf-modal .modal-body {
                height: calc(100vh - 60px) !important;
                min-height: calc(100vh - 60px) !important;
            }
            
            .notification-section {
                gap: 0.5rem;
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
            
            /* Enhanced Navigation Tabs - Same as Dashboard */
            .nav-tabs {
                display: flex;
                justify-content: space-between;
                flex-wrap: nowrap;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none; /* Firefox */
            }
            
            .nav-tabs::-webkit-scrollbar {
                display: none; /* Chrome, Safari */
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
                padding: 0.75rem;
            }
            
            .form-label {
                font-size: 0.9rem;
            }
            
            .form-text {
                font-size: 0.8rem;
            }
            
            .form-control,
            .form-select {
                font-size: 0.875rem;
            }
            
            textarea.form-control {
                min-height: 120px;
            }
            
            .alert {
                padding: 0.75rem;
                font-size: 0.875rem;
            }
            
            .timeline {
                padding-left: 1rem;
            }
            
            .timeline::before {
                left: 0.25rem;
            }
            
            .timeline-item {
                margin-bottom: 1.5rem;
            }
            
            .timeline-item::before {
                left: -1.5rem;
                width: 6px;
                height: 6px;
            }
            
            .card {
                margin-bottom: 1rem;
            }
            
            .card-header {
                padding: 0.75rem;
                font-size: 0.9rem;
                flex-direction: column;
                align-items: flex-start !important;
                gap: 0.5rem;
            }
            
            .card-header h6 {
                font-size: 0.9rem;
                margin-bottom: 0.25rem;
            }
            
            .card-header small {
                font-size: 0.75rem;
            }
            
            .card-header .d-flex.gap-2 {
                width: 100%;
                flex-direction: column;
                gap: 0.5rem !important;
            }
            
            .card-header .badge {
                font-size: 0.75rem;
                align-self: flex-start;
            }
            
            .card-header .btn {
                width: 100%;
            }
            
            .card-body {
                padding: 0.75rem;
                font-size: 0.875rem;
            }
            
            .card-body p {
                font-size: 0.875rem;
            }
            
            .row.mb-3 {
                margin-bottom: 0.75rem !important;
            }
            
            .row.mb-3 > div {
                margin-bottom: 0.5rem;
            }
            
            .row.mb-3 small,
            .row.mb-3 strong {
                font-size: 0.8rem;
            }
            
            .comment-item {
                padding: 0.75rem;
                margin-bottom: 0.75rem;
            }
            
            .author-avatar {
                width: 32px;
                height: 32px;
                font-size: 0.8rem;
            }
            
            .comment-item .d-flex {
                gap: 0.5rem;
            }
            
            .comment-item strong {
                font-size: 0.85rem;
            }
            
            .comment-item .badge {
                font-size: 0.7rem;
                padding: 0.2rem 0.4rem;
            }
            
            .comment-item small {
                font-size: 0.7rem;
            }
            
            .comment-item p {
                font-size: 0.85rem;
            }
            
            .status-badge {
                font-size: 0.7rem;
                padding: 0.2rem 0.5rem;
            }
            
            .btn {
                font-size: 0.85rem;
                padding: 0.5rem 0.75rem;
            }
            
            .btn-lg {
                font-size: 0.9rem;
                padding: 0.6rem 1rem;
            }
            
            .btn i {
                font-size: 0.9rem;
            }
            
            .d-flex.gap-2 {
                flex-direction: column;
                gap: 0.5rem !important;
            }
            
            .d-flex.gap-2.justify-content-between {
                flex-direction: column;
            }
            
            .d-flex.gap-2 .btn {
                width: 100%;
            }
            
            iframe {
                height: 300px !important;
            }
            
            .pdf-modal .modal-dialog {
                max-width: 100vw !important;
                height: 100vh !important;
                margin: 0 !important;
            }
            
            .pdf-modal .modal-content {
                height: 100vh !important;
                border-radius: 0 !important;
            }
            
            .pdf-modal .modal-header {
                padding: 0.75rem 1rem !important;
                height: 56px !important;
                flex-shrink: 0 !important;
            }
            
            .pdf-modal .modal-body {
                height: calc(100vh - 56px) !important;
                min-height: calc(100vh - 56px) !important;
                padding: 0 !important;
                overflow: hidden !important;
            }
            
            /* ADD THIS - Force iframe to fill */
            .pdf-modal iframe {
                height: 100% !important;
                min-height: calc(100vh - 56px) !important;
                width: 100% !important;
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
        }

        /* ================================================= */
        /* EXTRA SMALL DEVICES (portrait phones, less than 375px) */
        /* ================================================= */
        @media (max-width: 374px) {
            .university-name {
                font-size: 0.75rem;
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

        /* ============================================== */
        /* LANDSCAPE ORIENTATION ADJUSTMENTS */
        /* ============================================== */
        @media (max-width: 768px) and (orientation: landscape) {
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
                                                            <?php echo $notif['created_at'] ? date('M d, g:i A', strtotime($notif['created_at'])) : 'Recently'; ?>
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
                    <a class="nav-link active" href="submit_title.php">
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
            <h1 class="page-title">Research Title Submission</h1>
            <p class="page-subtitle">Submit your research title for review - Reviewers will be assigned by administrators</p>
        </div>

        <!-- Success/Error Messages -->
        <?php if (isset($_GET['success']) && $_GET['success'] == 'submitted'): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i>
                Research title submitted successfully! Administrators will assign reviewers shortly.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle me-2"></i>
                <?php echo $success; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <!-- New Submission Form -->
        <div class="dashboard-section">
            <div class="section-header">
                <i class="bi bi-plus-circle me-2"></i>
                <?php if ($current_title && $current_title['status'] == 'rejected'): ?>
                    Submit Revised Title
                <?php elseif ($current_title && $current_title['status'] == 'approved'): ?>
                    Submit New Research Title
                <?php elseif ($current_title && $current_title['status'] == 'pending'): ?>
                    Submit Additional Research Title
                <?php else: ?>
                    New Research Title Submission
                <?php endif; ?>
            </div>
            <div class="section-body">
                <?php if (is_array($all_titles) && count($all_titles) > 0): ?>
                    <?php 
                    $latest_title = $all_titles[0];
                    $total_reviewers = (int)$latest_title['total_assigned_reviewers'];
                    $required_approvals = $total_reviewers > 0 ? floor($total_reviewers / 2) + 1 : 3;
                    $current_approvals = (int)$latest_title['approvals'];
                    ?>
                    <?php if ($current_approvals >= $required_approvals || $latest_title['status'] === 'approved'): ?>
                    <?php endif; ?>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data" id="titleForm">
                    <div class="mb-4">
                        <label for="title" class="form-label">
                            <i class="bi bi-type me-1"></i>Research Title <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="title" name="title" placeholder="Enter your research title..." required>
                        <div class="form-text">Be specific and descriptive about your research focus</div>
                    </div>

                    <div class="mb-4">
                        <label for="description" class="form-label">
                            <i class="bi bi-text-paragraph me-1"></i>Short Description <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control" id="description" name="description" rows="6" placeholder="Provide a brief abstract or description of your research..." required></textarea>
                        <div class="form-text">Explain the purpose, methodology, and expected outcomes</div>
                    </div>

                    <!-- Reviewer Assignment Info -->
                    <div class="mb-4">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Note:</strong> After you submit your title, the system administrator will assign reviewers from your college to evaluate your research proposal. You will receive notifications once reviewers are assigned and when they provide feedback.
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="document" class="form-label">
                            <i class="bi bi-file-earmark-arrow-up me-1"></i>Research Proposal Document
                        </label>
                        <input type="file" class="form-control" name="document" id="document" accept=".pdf,.doc,.docx">
                        <div class="form-text">
                            <i class="bi bi-info-circle me-1"></i>
                            Upload PDF, DOC, or DOCX files (max 10MB). This will provide more context for your research.
                        </div>
                    </div>

                    <div class="d-flex gap-2 justify-content-end">
                        <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                            <i class="bi bi-send me-2"></i>Submit for Review
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Submission History with Reviews -->
        <?php if (is_array($all_titles) && count($all_titles) > 0): ?>
            <div class="dashboard-section">
                <div class="section-header">
                    <i class="bi bi-clock-history me-2"></i>Submission History & Reviews
                </div>
                <div class="section-body">
                    <div class="timeline">
                        <?php foreach ($all_titles as $index => $title_item): ?>
                            <?php 
                            $total_reviewers = (int)$title_item['total_assigned_reviewers'];
                            $required_approvals = $total_reviewers > 0 ? floor($total_reviewers / 2) + 1 : 3;
                            $approvals = (int)$title_item['approvals'];
                            $approval_percentage = $required_approvals > 0 ? min((($approvals / $required_approvals) * 100), 100) : 0;
                            $is_approved = $approvals >= $required_approvals || $title_item['status'] === 'approved';
                            ?>
                            <div class="timeline-item">
                                <div class="card border-0 shadow-sm">
                                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1">
                                                <i class="bi bi-file-text text-<?php echo $is_approved ? 'success' : 'primary'; ?> me-2"></i>
                                                "<?php echo htmlspecialchars($title_item['title']); ?>"
                                            </h6>
                                            <small class="text-muted">
                                                Submitted <?php echo date('F d, Y', strtotime($title_item['created_at'])); ?>
                                                <?php if ($index === 0): ?>
                                                    <span class="badge bg-primary ms-1">Latest</span>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                        <div class="d-flex gap-2 align-items-center">
                                            <span class="badge bg-<?php echo $is_approved ? 'success' : 'warning'; ?> fs-6">
                                                <?php echo $approvals; ?>/<?php echo $required_approvals; ?> Approvals
                                            </span>
                                            <?php if ($is_approved): ?>
                                                <a href="submit_chapter.php?chapter=1" class="btn btn-sm btn-success">
                                                    <i class="bi bi-arrow-right me-1"></i>Submit Chapter 1
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <p class="mb-3"><?php echo nl2br(htmlspecialchars($title_item['description'])); ?></p>

                                        <!-- Progress bar -->
                                        <div class="progress mb-3" style="height: 8px;">
                                            <div class="progress-bar bg-<?php echo $is_approved ? 'success' : 'primary'; ?>" 
                                                 style="width: <?php echo $approval_percentage; ?>%"></div>
                                        </div>

                                        <div class="row mb-3">
                                            <div class="col-md-4">
                                                <small class="text-muted">Approvals:</small><br>
                                                <strong class="text-success"><?php echo $approvals; ?>/<?php echo $required_approvals; ?></strong>
                                            </div>
                                            <div class="col-md-4">
                                                <small class="text-muted">Total Reviewers:</small><br>
                                                <strong><?php echo $total_reviewers; ?></strong>
                                            </div>
                                            <div class="col-md-4">
                                                <small class="text-muted">Status:</small><br>
                                                <span class="status-badge status-<?php echo $is_approved ? 'approved' : 'pending'; ?>">
                                                    <?php echo $is_approved ? 'Approved' : 'Under Review'; ?>
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Document Viewer with PDF Support -->
                                        <?php if (!empty($title_item['document_path'])): ?>
                                            <hr>
                                            <h6 class="text-primary mb-3">
                                                <i class="bi bi-file-earmark me-2"></i>Research Document
                                            </h6>
                                            <?php
                                            $full_path = '../' . $title_item['document_path'];
                                            if (file_exists($full_path)) {
                                                $file_extension = strtolower(pathinfo($title_item['document_path'], PATHINFO_EXTENSION));
                                                if ($file_extension === 'pdf') {
                                                    echo '<div class="mb-3">
                                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                                <span><strong>' . htmlspecialchars(basename($title_item['document_path'])) . '</strong></span>
                                                                <button type="button" class="btn btn-primary btn-sm" onclick="openPdfModal(\'' . htmlspecialchars($title_item['document_path']) . '\')">
                                                                    <i class="bi bi-arrows-fullscreen me-1"></i>View Full Screen
                                                                </button>
                                                            </div>
                                                            <iframe src="../' . htmlspecialchars($title_item['document_path']) . '" 
                                                                    width="100%" 
                                                                    height="400px" 
                                                                    style="border: 1px solid #e2e8f0; border-radius: 8px;">
                                                                <p>Your browser does not support PDFs. 
                                                                   <a href="../' . htmlspecialchars($title_item['document_path']) . '" target="_blank">Download the PDF</a>.
                                                                </p>
                                                            </iframe>
                                                          </div>';
                                                } else {
                                                    echo '<div class="d-flex align-items-center gap-2 mb-3">
                                                            <i class="bi bi-file-earmark-text text-primary" style="font-size: 2rem;"></i>
                                                            <div>
                                                                <strong>' . htmlspecialchars(basename($title_item['document_path'])) . '</strong><br>
                                                                <small class="text-muted">' . strtoupper($file_extension) . ' Document</small>
                                                            </div>
                                                            <a href="../' . htmlspecialchars($title_item['document_path']) . '" target="_blank" class="btn btn-outline-primary btn-sm ms-auto">
                                                                <i class="bi bi-download me-1"></i>Download
                                                            </a>
                                                          </div>';
                                                }
                                            } else {
                                                echo '<div class="alert alert-warning">
                                                        <i class="bi bi-file-x"></i> Document file not found on server
                                                      </div>';
                                            }
                                            ?>
                                        <?php endif; ?>

                                        <!-- Display Reviews -->
                                        <?php
                                        try {
                                            $stmt = $conn->prepare("
                                                SELECT r.*, u.first_name, u.last_name
                                                FROM reviews r
                                                JOIN users u ON r.reviewer_id = u.user_id
                                                WHERE r.submission_id = ?
                                                ORDER BY r.review_date DESC
                                            ");
                                            $stmt->execute([$title_item['submission_id']]);
                                            $title_reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                        } catch (Exception $e) {
                                            $title_reviews = [];
                                        }
                                        ?>

                                        <?php if (is_array($title_reviews) && count($title_reviews) > 0): ?>
                                            <hr>
                                            <h6 class="text-primary mb-3">
                                                <i class="bi bi-chat-square-text me-2"></i>
                                                Reviewer Feedback (<?php echo count($title_reviews); ?> reviews)
                                            </h6>

                                            <?php foreach ($title_reviews as $review): ?>
                                                <div class="comment-item">
                                                    <div class="d-flex align-items-start gap-3">
                                                        <div class="author-avatar">
                                                            <?php echo strtoupper(substr($review['first_name'], 0, 1) . substr($review['last_name'], 0, 1)); ?>
                                                        </div>
                                                        <div class="flex-grow-1">
                                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                                <div>
                                                                    <strong><?php echo htmlspecialchars($review['first_name'] . ' ' . $review['last_name']); ?></strong>
                                                                </div>
                                                                <div class="text-end">
                                                                    <span class="badge bg-<?php echo $review['decision'] === 'approve' ? 'success' : 'danger'; ?>">
                                                                        <?php echo ucfirst($review['decision']); ?>
                                                                    </span>
                                                                    <br><small class="text-muted">
                                                                        <?php echo date('M d, Y h:i A', strtotime($review['review_date'])); ?>
                                                                    </small>
                                                                </div>
                                                            </div>
                                                            <p class="mb-0"><?php echo nl2br(htmlspecialchars($review['comments'])); ?></p>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php elseif ($total_reviewers > 0): ?>
                                            <div class="alert alert-info">
                                                <i class="bi bi-clock me-2"></i>
                                                Reviewers have been assigned. Waiting for their feedback...
                                            </div>
                                        <?php else: ?>
                                            <div class="alert alert-warning">
                                                <i class="bi bi-hourglass-split me-2"></i>
                                                Waiting for administrators to assign reviewers...
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="dashboard-section">
                <div class="section-body">
                    <div class="text-center py-4">
                        <i class="bi bi-file-text text-muted" style="font-size: 3rem;"></i>
                        <h5 class="text-muted mt-2">No Research Titles Submitted</h5>
                        <p class="text-muted">Submit your first research title to get started with the approval process.</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- PDF Viewer Modal -->
    <div class="modal fade pdf-modal" id="pdfViewerModal" tabindex="-1" aria-labelledby="pdfViewerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="pdfViewerModalLabel">
                        <i class="bi bi-file-pdf me-2"></i>Research Document
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <iframe id="pdfViewerFrame" src="" width="100%" height="100%"></iframe>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // PDF Modal Function
        function openPdfModal(pdfPath) {
            const modal = new bootstrap.Modal(document.getElementById('pdfViewerModal'));
            const iframe = document.getElementById('pdfViewerFrame');
            iframe.src = '../' + pdfPath;
            modal.show();
        }

        // Clean up iframe when modal closes
        document.getElementById('pdfViewerModal').addEventListener('hidden.bs.modal', function () {
            document.getElementById('pdfViewerFrame').src = '';
        });

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

        document.addEventListener('DOMContentLoaded', function() {
            const bellButton = document.getElementById('notificationBell');
            const notificationCount = <?php echo $total_unviewed; ?>;
            
            if (bellButton && notificationCount > 0) {
                bellButton.style.animation = 'shake 0.5s ease-in-out';
            }
            
            setInterval(updateNotificationCount, 30000);
        });

        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('titleForm');
            const submitBtn = document.getElementById('submitBtn');

            if (form && submitBtn) {
                // Form submission with loading state
                form.addEventListener('submit', function() {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Submitting...';
                });
            }

            // Auto-expand textarea
            const textarea = document.getElementById('description');
            if (textarea) {
                textarea.addEventListener('input', function() {
                    this.style.height = 'auto';
                    this.style.height = Math.min(this.scrollHeight, 200) + 'px';
                });
            }

            // Initialize notification polling
            setInterval(updateNotificationCount, 30000);
        });
    </script>
</body>
</html>