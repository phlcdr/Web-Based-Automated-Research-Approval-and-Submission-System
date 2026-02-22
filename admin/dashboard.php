<?php
session_start(); // CRITICAL: This must be the first line

include_once '../config/database.php';
include_once '../includes/submission_functions.php';  
include_once '../includes/functions.php';

// Check if user is logged in and is an admin
is_logged_in();
check_role(['admin']);

$user_id = $_SESSION['user_id'];

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

// Get system statistics
$stats = [];

// Count total users
$stmt = $conn->query("SELECT COUNT(*) as count FROM users");
$stats['total_users'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Count students
$stmt = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'student'");
$stats['total_students'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Count advisers
$stmt = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'adviser'");
$stats['total_advisers'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Count research groups
$stmt = $conn->query("SELECT COUNT(*) as count FROM research_groups");
$stats['total_groups'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// FIXED: Get statistics from actual tables
$submission_stats = $conn->query("SELECT 
    COUNT(CASE WHEN submission_type = 'title' AND status = 'pending' THEN 1 END) as pending_titles,
    COUNT(CASE WHEN submission_type = 'title' AND status = 'approved' THEN 1 END) as approved_titles
    FROM submissions")->fetch(PDO::FETCH_ASSOC);

$stats['pending_titles'] = $submission_stats['pending_titles'];
$stats['approved_titles'] = $submission_stats['approved_titles'];

// Count pending user registrations
$stmt = $conn->query("SELECT COUNT(*) as count FROM users WHERE registration_status = 'pending'");
$stats['pending_registrations'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get recent activities
$stmt = $conn->query("
    SELECT s.submission_type as type, 
           s.title as item_name, 
           s.submission_date as date,
           CONCAT(u.first_name, ' ', u.last_name) as student_name, 
           s.status
    FROM submissions s
    JOIN research_groups rg ON s.group_id = rg.group_id
    JOIN users u ON rg.lead_student_id = u.user_id
        ORDER BY s.submitted_at DESC
    LIMIT 10
");
$recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get admin notifications
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

// Function to get notification redirect URL
function getNotificationRedirectUrl($notification) {
    switch ($notification['type']) {
        case 'user_registration':
            return 'manage_users.php?status=pending';
        case 'title_submission':
        case 'chapter_submission':
            return 'manage_research.php?tab=submissions&status=pending';
        case 'reviewer_assignment':
            return 'manage_research.php?tab=groups';
        case 'discussion_update':
            return 'manage_research.php?tab=groups';
        default:
            return 'dashboard.php';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpg" href="../assets/images/essu logo.png">
    <title>Admin Dashboard - Research Approval System</title>
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

        /* Academic Cards */
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

        /* Dashboard Sections */
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

        /* Activity Feed */
        .activity-feed {
            max-height: 500px;
            overflow-y: auto;
        }

        .activity-item {
            display: flex;
            padding: 1rem 0;
            border-bottom: 1px solid var(--border-light);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            flex-shrink: 0;
        }

        .activity-icon.research { background: rgba(30, 64, 175, 0.1); color: var(--university-blue); }
        .activity-icon.user { background: rgba(5, 150, 105, 0.1); color: var(--success-green); }
        .activity-icon.approval { background: rgba(245, 158, 11, 0.1); color: var(--university-gold); }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
            font-size: 0.9rem;
        }

        .activity-description {
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-bottom: 0.25rem;
        }

        .activity-meta {
            font-size: 0.75rem;
            color: var(--text-secondary);
        }

        /* Academic Badges */
        .academic-badge {
            padding: 0.3rem 0.6rem;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .academic-badge.primary { background: var(--university-blue); color: white; }
        .academic-badge.success { background: var(--success-green); color: white; }
        .academic-badge.warning { background: var(--warning-orange); color: white; }
        .academic-badge.gold { background: var(--university-gold); color: white; }

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

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
        }

        .empty-state-icon {
            font-size: 3rem;
            color: var(--text-secondary);
            opacity: 0.5;
            margin-bottom: 1rem;
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
            
            .stat-number { 
                font-size: 1.8rem; 
            }
            
            .academic-card { 
                padding: 1rem; 
            }
            
            .main-content { 
                padding: 1rem 0; 
            }
            
            .section-body { 
                padding: 1rem; 
            }
            
            .activity-item {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .activity-icon {
                margin-right: 0;
                margin-bottom: 0.5rem;
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
            
            .stat-number { 
                font-size: 1.5rem; 
            }
            
            .stat-label { 
                font-size: 0.7rem; 
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
            
            .academic-card { 
                padding: 1rem; 
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
            
            .activity-item {
                padding: 0.75rem 0;
            }
            
            .activity-icon {
                width: 2rem;
                height: 2rem;
                font-size: 0.85rem;
                margin-right: 0.75rem;
            }
            
            .activity-title {
                font-size: 0.8rem;
            }
            
            .activity-description {
                font-size: 0.7rem;
            }
            
            .activity-meta {
                font-size: 0.65rem;
            }
            
            .academic-badge {
                font-size: 0.65rem;
                padding: 0.2rem 0.4rem;
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
            
            .stat-number {
                font-size: 1.3rem;
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
                                            class="notification-item" 
                                            data-notification-id="<?php echo $notif['notification_id']; ?>">
                                                <div class="d-flex align-items-start">
                                                    <div class="notification-icon <?php echo $notif['type']; ?>">
                                                        <i class="bi bi-<?php 
                                                            switch($notif['type']) {
                                                                case 'user_registration': echo 'person-plus';
                                                                case 'title_submission': echo 'journal-check';
                                                                case 'chapter_submission': echo 'file-earmark-check';
                                                                case 'reviewer_assignment': echo 'people';
                                                                case 'discussion_update': echo 'chat-square-text';
                                                                default: echo 'info-circle';
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
                    <a class="nav-link active" href="dashboard.php">
                        <i class="bi bi-house-door me-1 me-md-2"></i>
                        <span class="d-none d-sm-inline">Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="manage_users.php">
                        <i class="bi bi-people me-1 me-md-2"></i>
                        <span class="d-none d-sm-inline">Users</span>
                        <?php if ($stats['pending_registrations'] > 0): ?>
                            <span class="badge-notification"><?php echo $stats['pending_registrations']; ?></span>
                        <?php endif; ?>
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
        <div class="page-header">
            <h1 class="page-title">Administrative Dashboard</h1>
            <p class="page-subtitle">Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?>! Here's your system overview.</p>
        </div>

        <!-- Statistics Overview -->
        <div class="row g-3 g-md-4 mb-4">
            <div class="col-6 col-lg-3">
                <div class="academic-card primary">
                    <div class="stat-icon primary">
                        <i class="bi bi-people"></i>
                    </div>
                    <div class="stat-number text-primary"><?php echo $stats['total_users']; ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="academic-card success">
                    <div class="stat-icon success">
                        <i class="bi bi-mortarboard"></i>
                    </div>
                    <div class="stat-number" style="color: var(--success-green)"><?php echo $stats['total_students']; ?></div>
                    <div class="stat-label">Students</div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="academic-card warning">
                    <div class="stat-icon warning">
                        <i class="bi bi-person-workspace"></i>
                    </div>
                    <div class="stat-number" style="color: var(--warning-orange)"><?php echo $stats['total_advisers']; ?></div>
                    <div class="stat-label">Advisers</div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="academic-card gold">
                    <div class="stat-icon gold">
                        <i class="bi bi-collection"></i>
                    </div>
                    <div class="stat-number" style="color: var(--university-gold)"><?php echo $stats['total_groups']; ?></div>
                    <div class="stat-label">Research Groups</div>
                </div>
            </div>
        </div>

        <!-- Research Status Overview -->
        <div class="row g-3 g-md-4 mb-4">
            <div class="col-md-6">
                <div class="dashboard-section">
                    <div class="section-header">
                        <i class="bi bi-graph-up me-2"></i>Research Status
                    </div>
                    <div class="section-body">
                        <div class="row text-center g-3">
                            <div class="col-6">
                                <div class="status-card pending">
                                    <h3 style="color: var(--warning-orange)"><?php echo $stats['pending_titles']; ?></h3>
                                    <p class="mb-0">Pending Review</p>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="status-card approved">
                                    <h3 style="color: var(--success-green)"><?php echo $stats['approved_titles']; ?></h3>
                                    <p class="mb-0">Approved</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="dashboard-section">
                    <div class="section-header">
                        <i class="bi bi-person-check me-2"></i>User Registrations
                    </div>
                    <div class="section-body text-center">
                        <div class="status-card pending">
                            <h3 style="color: var(--warning-orange)"><?php echo $stats['pending_registrations']; ?></h3>
                            <p class="mb-0">Pending Approval</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent System Activity -->
        <div class="dashboard-section">
            <div class="section-header">
                <i class="bi bi-activity me-2"></i>Recent Activities
            </div>
            <div class="section-body">
                <?php if (count($recent_activities) > 0): ?>
                    <div class="activity-feed">
                        <?php foreach ($recent_activities as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-icon research">
                                    <i class="bi bi-journal-text"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title"><?php echo ucfirst($activity['type']); ?> Submission</div>
                                    <div class="activity-description">
                                        <?php echo htmlspecialchars($activity['item_name']); ?>
                                    </div>
                                    <div class="activity-meta">
                                        <span class="academic-badge <?php echo $activity['status'] == 'approved' ? 'success' : ($activity['status'] == 'rejected' ? 'warning' : 'primary'); ?>">
                                            <?php echo ucfirst($activity['status']); ?>
                                        </span>
                                        <?php echo htmlspecialchars($activity['student_name']); ?> • <?php echo date('M j, Y', strtotime($activity['date'])); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="bi bi-clock-history empty-state-icon"></i>
                        <p class="text-muted">No recent activities found.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Notification functionality
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
                    console.error('Error:', error);
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

        // Initialize notifications
        document.addEventListener('DOMContentLoaded', function() {
            const bellButton = document.getElementById('notificationBell');
            const notificationCount = <?php echo $total_unviewed; ?>;
            
            // Add shake animation if there are notifications
            if (bellButton && notificationCount > 0) {
                bellButton.style.animation = 'shake 0.5s ease-in-out';
            }
            
            // Auto-refresh notification count every 30 seconds
            setInterval(updateNotificationCount, 30000);
        });
    </script>
</body>
</html>