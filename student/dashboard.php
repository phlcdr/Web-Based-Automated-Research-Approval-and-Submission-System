<?php
session_start();
include_once '../config/database.php';
include_once '../includes/functions.php';

// Check if user is logged in and is a student
is_logged_in();
check_role(['student']);

$user_id = $_SESSION['user_id'];
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

// Get current user information
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

// Get student research group
$stmt = $conn->prepare("SELECT * FROM research_groups WHERE lead_student_id = ?");
$stmt->execute([$user_id]);
$group = $stmt->fetch(PDO::FETCH_ASSOC);

// Get research title if group exists
$title = null;
if ($group) {
    $stmt = $conn->prepare("SELECT * FROM submissions WHERE group_id = ? AND submission_type = 'title' ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$group['group_id']]);
    $title = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get chapters if group exists
$chapters = [];
if ($group) {
    $stmt = $conn->prepare("SELECT * FROM submissions WHERE group_id = ? AND submission_type = 'chapter' ORDER BY chapter_number ASC");
    $stmt->execute([$group['group_id']]);
    $chapters = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get unread notifications
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

// Get adviser info
$adviser = null;
if ($group && $group['adviser_id']) {
    $stmt = $conn->prepare("SELECT first_name, last_name, email FROM users WHERE user_id = ?");
    $stmt->execute([$group['adviser_id']]);
    $adviser = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get group members
$members = [];
if ($group) {
    $stmt = $conn->prepare("
        SELECT 
            CASE 
                WHEN gm.is_registered_user = 1 THEN CONCAT(u.first_name, ' ', u.last_name)
                ELSE gm.member_name
            END as full_name,
            CASE 
                WHEN gm.is_registered_user = 1 THEN u.first_name
                ELSE SUBSTRING_INDEX(gm.member_name, ' ', 1)
            END as first_name,
            CASE 
                WHEN gm.is_registered_user = 1 THEN u.last_name
                ELSE SUBSTRING_INDEX(gm.member_name, ' ', -1)
            END as last_name,
            CASE 
                WHEN rg.lead_student_id = gm.user_id AND gm.is_registered_user = 1 THEN 1
                ELSE 0
            END as is_leader
        FROM group_memberships gm
        LEFT JOIN users u ON gm.user_id = u.user_id AND gm.is_registered_user = 1
        JOIN research_groups rg ON gm.group_id = rg.group_id
        WHERE gm.group_id = ?
        ORDER BY is_leader DESC, full_name
    ");
    $stmt->execute([$group['group_id']]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpg" href="../assets/images/essu logo.png">
    <title>Student Dashboard - ESSU Research System</title>
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

        .notification-icon.title { 
            background: rgba(30, 64, 175, 0.1); 
            color: var(--university-blue); 
        }

        .notification-icon.chapter { 
            background: rgba(5, 150, 105, 0.1); 
            color: var(--success-green); 
        }

        .notification-icon.discussion { 
            background: rgba(2, 132, 199, 0.1); 
            color: #0284c7; 
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

        .academic-card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border-left: 4px solid transparent;
            transition: all 0.3s ease;
            height: 100%;
        }

        .academic-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .academic-card.primary { border-left-color: var(--university-blue); }
        .academic-card.success { border-left-color: var(--success-green); }
        .academic-card.warning { border-left-color: var(--warning-orange); }
        .academic-card.gold { border-left-color: var(--university-gold); }

        .stat-icon {
            width: 3rem;
            height: 3rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            margin-bottom: 1rem;
        }

        .stat-icon.primary { background: rgba(30, 64, 175, 0.1); color: var(--university-blue); }
        .stat-icon.success { background: rgba(5, 150, 105, 0.1); color: var(--success-green); }
        .stat-icon.warning { background: rgba(217, 119, 6, 0.1); color: var(--warning-orange); }
        .stat-icon.gold { background: rgba(245, 158, 11, 0.1); color: var(--university-gold); }

        .stat-number {
            font-size: 2.2rem;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
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

        .progress-step {
            background: white;
            border: 2px solid var(--border-light);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }

        .progress-step.completed {
            border-color: var(--success-green);
            background: #f0fdf4;
        }

        .progress-step.current {
            border-color: var(--university-blue);
            background: #eff6ff;
        }

        .progress-step.pending {
            border-color: var(--warning-orange);
            background: #fffbeb;
        }

        .progress-step.rejected {
            border-color: var(--danger-red);
            background: #fef2f2;
        }

        .step-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .step-title {
            font-weight: 600;
            color: var(--text-primary);
        }

        .step-status {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-completed { background: #d1fae5; color: #065f46; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-rejected { background: #fee2e2; color: #991b1b; }
        .status-not-started { background: #f1f5f9; color: #475569; }

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

        .btn-outline-primary {
            border: 1px solid var(--university-blue);
            color: var(--university-blue);
            background: transparent;
        }

        .btn-outline-primary:hover {
            background: var(--university-blue);
            color: white;
        }

        .alert {
            border-radius: 8px;
            border: none;
            padding: 1rem;
        }

        .alert-info {
            background: #eff6ff;
            color: #1d4ed8;
            border-left: 4px solid var(--university-blue);
        }

        .alert-warning {
            background: #fffbeb;
            color: #d97706;
            border-left: 4px solid var(--warning-orange);
        }

        .alert-success {
            background: #f0fdf4;
            color: #059669;
            border-left: 4px solid var(--success-green);
        }

        .member-badge {
            display: inline-block;
            background: var(--university-blue);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .member-badge.leader {
            background: var(--success-green);
        }

        .author-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--university-blue) 0%, #1d4ed8 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1rem;
            overflow: hidden;
        }

        .author-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .group-info {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .quick-actions {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

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
            .notification-dropdown { min-width: 320px; max-width: 350px; }
            .university-name { font-size: 1rem; line-height: 1.3; }
            .university-logo { width: 40px; height: 40px; margin-right: 10px; }
            .page-title { font-size: 1.5rem; }
            .page-subtitle { font-size: 0.9rem; }
            .page-header { padding: 1.5rem; }
            .nav-tabs .nav-link { padding: 0.85rem 0.6rem; font-size: 0.85rem; }
            .stat-number { font-size: 1.8rem; }
            .stat-label { font-size: 0.75rem; }
            .academic-card { padding: 1rem; }
            .section-header { padding: 0.75rem 1rem; font-size: 0.9rem; }
            .section-body { padding: 1rem; }
            .group-info { padding: 0.75rem; }
            .member-badge { font-size: 0.75rem; padding: 0.2rem 0.5rem; }
            .progress-step { padding: 0.75rem; }
            .step-title { font-size: 0.9rem; }
            .step-status { font-size: 0.7rem; padding: 0.2rem 0.5rem; }
            .main-content { padding: 1rem 0; }
            .quick-actions { flex-direction: column; }
            .quick-actions .btn { width: 100%; }
        }

        /* RESPONSIVE - MOBILE */
        @media (max-width: 576px) {
            .user-info { display: none; }
            .notification-section { gap: 0.5rem; }
            .university-header { padding: 0.75rem 0; }
            .university-name { font-size: 0.85rem; }
            .university-logo { width: 35px; height: 35px; }
            .notification-bell { font-size: 1.25rem; padding: 0.25rem; }
            .user-avatar { width: 32px; height: 32px; }
            
            .nav-tabs {
                display: flex;
                justify-content: space-between;
                flex-wrap: nowrap;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
            }
            .nav-tabs::-webkit-scrollbar { display: none; }
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
            .nav-tabs .nav-link i { font-size: 1.3rem; margin: 0; }
            .nav-tabs .nav-link span { font-size: 0.75rem; white-space: nowrap; }
            .nav-tabs .nav-link .d-none.d-sm-inline { display: inline !important; }
            .nav-tabs .nav-link.active { color: var(--university-blue); background: white; }
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
            
            .page-header { padding: 1rem; margin-bottom: 1rem; }
            .page-title { font-size: 1.25rem; }
            .page-subtitle { font-size: 0.85rem; }
            .stat-icon { width: 2.5rem; height: 2.5rem; font-size: 1rem; }
            .stat-number { font-size: 1.5rem; }
            .stat-label { font-size: 0.7rem; }
            .author-avatar { width: 35px; height: 35px; font-size: 0.9rem; }
            .section-header { padding: 0.75rem 1rem; font-size: 0.85rem; }
            .section-body { padding: 1rem; }
            .group-info { padding: 0.75rem; }
            .member-badge { font-size: 0.75rem; padding: 0.2rem 0.5rem; }
            .progress-step { padding: 0.75rem; }
            .step-title { font-size: 0.9rem; }
            .step-status { font-size: 0.7rem; padding: 0.2rem 0.5rem; }
            .main-content { padding: 1rem 0; }
            .quick-actions { flex-direction: column; }
            .quick-actions .btn { width: 100%; }
            .academic-card { padding: 1rem; }
            .alert { padding: 0.75rem; font-size: 0.875rem; }
            .notification-dropdown { min-width: 300px; max-width: 320px; }
            .notification-item { padding: 0.75rem 1rem; }
            .notification-icon { width: 30px; height: 30px; font-size: 0.75rem; margin-right: 0.5rem; }
            .notification-title { font-size: 0.8rem; }
            .notification-description { font-size: 0.7rem; }
            .notification-time { font-size: 0.65rem; }
        }

        /* EXTRA SMALL */
        @media (max-width: 374px) {
            .university-name { font-size: 0.75rem; }
            .page-title { font-size: 1.1rem; }
            .nav-tabs .nav-link { padding: 0.75rem 0.25rem; }
            .nav-tabs .nav-link i { font-size: 1.1rem; }
            .nav-tabs .nav-link span { font-size: 0.7rem; }
            .section-header { font-size: 0.8rem; padding: 0.65rem 0.75rem; }
        }

        /* LANDSCAPE */
        @media (max-width: 768px) and (orientation: landscape) {
            .page-header { padding: 1rem; margin-bottom: 1rem; }
            .main-content { padding: 1rem 0; }
            .nav-tabs .nav-link { padding: 0.75rem 1rem; }
        }
    </style>
</head>

<body>
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
                                            <a href="<?php echo get_notification_redirect_url($notif, 'student'); ?>" 
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
                                                    <div class="notification-content">
                                                        <div class="notification-title">
                                                            <?php echo htmlspecialchars($notif['notification_title']); ?>
                                                        </div>
                                                        <div class="notification-description">
                                                            <?php echo htmlspecialchars(substr($notif['message'], 0, 60)) . (strlen($notif['message']) > 60 ? '...' : ''); ?>
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
                                    <strong><?php echo $total_unviewed; ?></strong> new notification<?php echo $total_unviewed > 1 ? 's' : ''; ?>
                                </li>
                            <?php endif; ?>
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
        </div>
    </header>

    <nav class="main-nav">
        <div class="container">
            <ul class="nav nav-tabs">
                <li class="nav-item">
                    <a class="nav-link active" href="dashboard.php">
                        <i class="bi bi-house-door me-1 me-md-2"></i>
                        <span class="d-none d-sm-inline">Dashboard</span>
                    </a>
                </li>
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

    <div class="container main-content">
        <div class="page-header">
            <h1 class="page-title">Student Dashboard</h1>
            <p class="page-subtitle">Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?>! Track your research progress and submissions.</p>
        </div>

        <?php if (!$group): ?>
            <div class="row g-3 g-md-4 mb-4">
                <div class="col-6 col-lg-3">
                    <div class="academic-card warning">
                        <div class="stat-icon warning">
                            <i class="bi bi-exclamation-triangle"></i>
                        </div>
                        <div class="stat-number" style="color: var(--warning-orange)">0</div>
                        <div class="stat-label">Research Groups</div>
                    </div>
                </div>
            </div>

            <div class="dashboard-section">
                <div class="section-header">
                    <i class="bi bi-people me-2"></i>Setup Required
                </div>
                <div class="section-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-info-circle me-2"></i>
                        You need to create a research group before you can start submitting your research.
                    </div>
                    
                    <div class="row">
                        <div class="col-md-8">
                            <h5 class="mb-3">Create Research Group</h5>
                            <p class="mb-3">Connect with your adviser and group members to begin your research journey.</p>
                            <ul class="list-unstyled mb-0">
                                <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i>Choose your research adviser</li>
                                <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i>Add your group members</li>
                                <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i>Set your college and program</li>
                            </ul>
                        </div>
                        <div class="col-md-4 text-center">
                            <a href="create_group.php" class="btn btn-primary btn-lg">
                                <i class="bi bi-plus-circle me-2"></i>Create Group
                            </a>
                        </div>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <div class="row g-3 g-md-4 mb-4">
                <div class="col-6 col-lg-3">
                    <div class="academic-card primary">
                        <div class="stat-icon primary">
                            <i class="bi bi-journal-text"></i>
                        </div>
                        <div class="stat-number text-primary"><?php echo $title ? '1' : '0'; ?></div>
                        <div class="stat-label">Research Titles</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="academic-card success">
                        <div class="stat-icon success">
                            <i class="bi bi-file-text"></i>
                        </div>
                        <div class="stat-number" style="color: var(--success-green)"><?php echo count($chapters); ?></div>
                        <div class="stat-label">Chapters Submitted</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="academic-card warning">
                        <div class="stat-icon warning">
                            <i class="bi bi-people"></i>
                        </div>
                        <div class="stat-number" style="color: var(--warning-orange)"><?php echo count($members); ?></div>
                        <div class="stat-label">Group Members</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="academic-card gold">
                        <div class="stat-icon gold">
                            <i class="bi bi-graph-up"></i>
                        </div>
                        <?php
                        $total_steps = 6;
                        $completed_steps = 0;
                        if ($title && $title['status'] == 'approved') {
                            $completed_steps = 1;
                            foreach ($chapters as $chapter) {
                                if ($chapter['status'] == 'approved') {
                                    $completed_steps++;
                                }
                            }
                        }
                        $progress = ($completed_steps / $total_steps) * 100;
                        ?>
                        <div class="stat-number" style="color: var(--university-gold)"><?php echo round($progress); ?>%</div>
                        <div class="stat-label">Progress</div>
                    </div>
                </div>
            </div>

            <div class="dashboard-section">
                <div class="section-header">
                    <i class="bi bi-people me-2"></i>Research Group: "<?php echo htmlspecialchars($group['group_name']); ?>"
                </div>
                <div class="section-body">
                    <div class="group-info">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="text-muted mb-2">PROGRAM DETAILS</h6>
                                <p class="mb-1"><strong>College:</strong> <?php echo htmlspecialchars($group['college']); ?></p>
                                <p class="mb-1"><strong>Program:</strong> <?php echo htmlspecialchars($group['program']); ?></p>
                                <p class="mb-3"><strong>Year Level:</strong> <?php echo htmlspecialchars($group['year_level']); ?></p>
                                
                                <h6 class="text-muted mb-2">GROUP MEMBERS</h6>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php foreach ($members as $index => $member): ?>
                                        <span class="member-badge <?php echo $index === 0 ? 'leader' : ''; ?>">
                                            <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>
                                            <?php echo $index === 0 ? ' (Leader)' : ''; ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-muted mb-2">RESEARCH ADVISER</h6>
                                <?php if ($adviser): ?>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="author-avatar">
                                            <?php echo strtoupper(substr($adviser['first_name'], 0, 1) . substr($adviser['last_name'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <p class="mb-0"><strong>Dr. <?php echo htmlspecialchars($adviser['first_name'] . ' ' . $adviser['last_name']); ?></strong></p>
                                            <small class="text-muted"><?php echo htmlspecialchars($adviser['email']); ?></small>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted">Not assigned</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="dashboard-section">
                <div class="section-header">
                    <i class="bi bi-graph-up me-2"></i>Research Progress
                </div>
                <div class="section-body">
                    <?php if (!$title): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            No research title submitted yet. Start your research journey by submitting your title.
                        </div>
                        <div class="quick-actions">
                            <a href="submit_title.php" class="btn btn-primary">
                                <i class="bi bi-plus-circle me-2"></i>Submit Research Title
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="progress-step <?php echo $title['status'] == 'approved' ? 'completed' : ($title['status'] == 'rejected' ? 'rejected' : 'pending'); ?>">
                            <div class="step-header">
                                <div>
                                    <div class="step-title">
                                        <i class="bi bi-journal-text me-2"></i>Research Title
                                    </div>
                                    <small class="text-muted"><?php echo htmlspecialchars($title['title']); ?></small>
                                </div>
                                <span class="step-status status-<?php echo $title['status'] == 'approved' ? 'completed' : ($title['status'] == 'rejected' ? 'rejected' : 'pending'); ?>">
                                    <?php echo ucfirst($title['status']); ?>
                                </span>
                            </div>
                            <small class="text-muted">Submitted: <?php echo date('M d, Y', strtotime($title['created_at'])); ?></small>
                        </div>

                        <div class="row">
                            <?php 
                            $chapter_names = [
                                1 => 'Introduction',
                                2 => 'Literature Review',
                                3 => 'Methodology',
                                4 => 'Results & Discussion',
                                5 => 'Summary & Conclusion'
                            ];
                            
                            for ($i = 1; $i <= 5; $i++): 
                                $chapter = array_filter($chapters, function ($ch) use ($i) {
                                    return $ch['chapter_number'] == $i;
                                });
                                $chapter = reset($chapter);

                                $can_submit = false;
                                if ($title['status'] == 'approved') {
                                    if ($i == 1) {
                                        $can_submit = true;
                                    } elseif ($i > 1) {
                                        $prev_chapter = array_filter($chapters, function ($ch) use ($i) {
                                            return $ch['chapter_number'] == ($i - 1);
                                        });
                                        $prev_chapter = reset($prev_chapter);
                                        $can_submit = $prev_chapter && $prev_chapter['status'] == 'approved';
                                    }
                                }

                                $status = 'not-started';
                                $status_text = 'Not Started';
                                
                                if ($chapter) {
                                    if ($chapter['status'] == 'approved') {
                                        $status = 'completed';
                                        $status_text = 'Approved';
                                    } elseif ($chapter['status'] == 'rejected') {
                                        $status = 'rejected';
                                        $status_text = 'Needs Revision';
                                    } else {
                                        $status = 'pending';
                                        $status_text = 'Under Review';
                                    }
                                } elseif ($can_submit) {
                                    $status = 'current';
                                    $status_text = 'Ready to Submit';
                                }
                            ?>
                                <div class="col-md-4 mb-3">
                                    <div class="progress-step <?php echo $status; ?>">
                                        <div class="step-header">
                                            <div>
                                                <div class="step-title">
                                                    <i class="bi bi-<?php echo $i; ?>-circle me-2"></i>Chapter <?php echo $i; ?>
                                                </div>
                                                <small class="text-muted"><?php echo $chapter_names[$i]; ?></small>
                                            </div>
                                            <span class="step-status status-<?php echo $status == 'completed' ? 'completed' : ($status == 'rejected' ? 'rejected' : 'pending'); ?>">
                                                <?php echo $status_text; ?>
                                            </span>
                                        </div>
                                        <div class="mt-2">
                                            <?php if ($chapter): ?>
                                                <small class="text-muted">
                                                    Submitted: <?php echo date('M d, Y', strtotime($chapter['created_at'])); ?>
                                                </small>
                                            <?php elseif ($can_submit): ?>
                                                <a href="submit_chapter.php?chapter=<?php echo $i; ?>" class="btn btn-sm btn-primary">
                                                    <i class="bi bi-upload me-1"></i>Submit
                                                </a>
                                            <?php else: ?>
                                                <small class="text-muted">
                                                    <?php echo $title['status'] != 'approved' ? 'Title approval needed' : 'Previous chapter needed'; ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endfor; ?>
                        </div>

                        <div class="mt-4">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Overall Progress</h6>
                                <span class="text-muted"><?php echo $completed_steps; ?> of <?php echo $total_steps; ?> completed</span>
                            </div>
                            <div class="progress" style="height: 10px;">
                                <div class="progress-bar bg-<?php echo $progress == 100 ? 'success' : 'primary'; ?>"
                                    style="width: <?php echo $progress; ?>%"></div>
                            </div>
                        </div>

                        <div class="mt-4">
                            <h6 class="mb-3">Quick Actions</h6>
                            <div class="quick-actions">
                                <?php if ($title['status'] == 'rejected'): ?>
                                    <a href="submit_title.php" class="btn btn-warning">
                                        <i class="bi bi-arrow-clockwise me-2"></i>Revise Title
                                    </a>
                                <?php endif; ?>
                                
                                <a href="submit_title.php" class="btn btn-outline-primary">
                                    <i class="bi bi-journal-plus me-2"></i>View Title Details
                                </a>
                                
                                <?php if (count($chapters) > 0): ?>
                                    <a href="submit_chapter.php" class="btn btn-outline-primary">
                                        <i class="bi bi-file-text me-2"></i>View Chapters
                                    </a>
                                <?php endif; ?>

                                <a href="thesis_discussion.php" class="btn btn-outline-primary">
                                    <i class="bi bi-chat-square-text me-2"></i>Thesis Discussion
                                </a>
                            </div>
                        </div>

                        <?php if ($progress == 100): ?>
                            <div class="alert alert-success mt-4">
                                <i class="bi bi-trophy me-2"></i>
                                <strong>Congratulations!</strong> Research completed successfully!
                            </div>
                        <?php elseif ($title['status'] == 'rejected'): ?>
                            <div class="alert alert-warning mt-4">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                <strong>Action Required:</strong> Your research title needs revision.
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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
    </script>
</body>
</html>