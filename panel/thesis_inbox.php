<?php
// panel/thesis_inbox.php - Fixed for existing database schema with corrected SQL queries
session_start();

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/submission_functions.php';

is_logged_in();
check_role(['adviser', 'panel']);

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

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

// Get thesis discussions available to this user - FIXED: using existing database structure
$discussions = [];
try {
    // UNIFIED QUERY for both advisers and panel members
    $stmt = $conn->prepare("
        SELECT DISTINCT td.*, 
            rg.group_name, rg.college, rg.program,
            CONCAT(u.first_name, ' ', u.last_name) as student_name,
            CONCAT(adv.first_name, ' ', adv.last_name) as adviser_name,
            (SELECT COUNT(*) FROM messages WHERE context_type = 'discussion' AND context_id = td.discussion_id) as message_count,
            (SELECT COUNT(*) FROM messages m WHERE m.context_type = 'discussion' AND m.context_id = td.discussion_id 
             AND m.user_id != ? AND m.created_at > NOW() - INTERVAL 1 DAY) as recent_messages,
            (SELECT MAX(created_at) FROM messages WHERE context_type = 'discussion' AND context_id = td.discussion_id) as last_message_time
        FROM thesis_discussions td
        JOIN research_groups rg ON td.group_id = rg.group_id
        JOIN users u ON rg.lead_student_id = u.user_id
        LEFT JOIN users adv ON rg.adviser_id = adv.user_id
        WHERE (
            rg.adviser_id = ?  -- Primary adviser
            OR td.discussion_id IN (  -- OR assigned as participant
                SELECT context_id 
                FROM assignments 
                WHERE user_id = ? 
                AND context_type = 'discussion' 
                AND is_active = 1
            )
        )
        ORDER BY td.updated_at DESC
    ");
    $stmt->execute([$user_id, $user_id, $user_id]);
    $discussions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $discussions = [];
    $error = "Database error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpg" href="../assets/images/essu logo.png">
    <title>Thesis Discussions - ESSU Research System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- All the complete CSS styles here -->
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

        .discussion-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            border-left: 4px solid #e2e8f0;
            overflow: hidden;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        
        .discussion-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            border-left-color: var(--university-blue);
            color: inherit;
            text-decoration: none;
        }
        
        .discussion-card.has-new-messages {
            border-left-color: var(--success-green);
            background: linear-gradient(45deg, rgba(5, 150, 105, 0.02), #ffffff);
        }

        .discussion-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-light);
        }

        .discussion-body {
            padding: 1.5rem;
        }

        .discussion-footer {
            padding: 1rem 1.5rem;
            background: rgba(248, 250, 252, 0.5);
            border-top: 1px solid var(--border-light);
        }
        
        .participant-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
            margin: 0 auto;
        }
        
        .participant-student { background: var(--university-blue); }
        .participant-adviser { background: var(--success-green); }
        .participant-panel { background: var(--info-blue); }
        
        .message-count-badge {
            background: linear-gradient(135deg, var(--university-blue), #1e3a8a);
            color: white;
            padding: 0.4rem 0.8rem;
            border-radius: 15px;
            font-size: 0.875rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
        }
        
        .new-message-indicator {
            background: var(--success-green);
            animation: pulse 2s infinite;
            color: white;
        }

        .academic-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .academic-badge.primary { background: rgba(30, 64, 175, 0.1); color: var(--university-blue); }
        .academic-badge.success { background: rgba(5, 150, 105, 0.1); color: var(--success-green); }
        .academic-badge.info { background: rgba(2, 132, 199, 0.1); color: var(--info-blue); }
        .academic-badge.warning { background: rgba(217, 119, 6, 0.1); color: var(--warning-orange); }

        .instructions-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .instructions-header {
            background: linear-gradient(90deg, var(--info-blue), #0369a1);
            color: white;
            padding: 1rem 1.5rem;
            font-weight: 600;
            font-size: 1rem;
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
            
            .discussion-header, 
            .discussion-body,
            .discussion-footer {
                padding: 1rem;
            }
            
            .participant-avatar {
                width: 32px;
                height: 32px;
                font-size: 0.75rem;
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
            
            .academic-badge {
                font-size: 0.65rem;
                padding: 0.2rem 0.4rem;
            }
            
            .btn {
                font-size: 0.875rem;
                padding: 0.5rem 0.75rem;
            }
            
            .discussion-header,
            .discussion-body,
            .discussion-footer {
                padding: 1rem;
            }
            
            .participant-avatar {
                width: 32px;
                height: 32px;
                font-size: 0.75rem;
            }
            
            .instructions-card .p-4 {
                padding: 1rem !important;
            }
            
            .instructions-card .col-md-4 {
                margin-bottom: 1rem;
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
        
        @keyframes fadeIn {
            from { 
                opacity: 0; 
                transform: translateY(10px); 
            }
            to { 
                opacity: 1; 
                transform: translateY(0); 
            }
        }
        
        .discussion-card:active {
            transform: scale(0.98) !important;
        }
        
        .discussion-card.loading {
            pointer-events: none;
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
                    <!-- Updated Notification Bell -->
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
                    <a class="nav-link active" href="thesis_inbox.php">
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
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h1 class="page-title">
                        <i class="bi bi-chat-square-text me-2"></i>Thesis Discussion Inbox
                    </h1>
                    <p class="page-subtitle">Review, discuss, and provide guidance on student thesis submissions</p>
                </div>
                <div class="d-flex gap-2">
                    <span class="academic-badge info"><?php echo count($discussions); ?> Total Discussions</span>
                    <?php
                    $active_discussions = count(array_filter($discussions, function($d) {
                        return $d['recent_messages'] > 0;
                    }));
                    if ($active_discussions > 0):
                    ?>
                        <span class="academic-badge success new-message-indicator"><?php echo $active_discussions; ?> Active</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (isset($error)): ?>
            <div class="alert alert-warning border-0 rounded-3 p-3 mb-4" style="background: rgba(217, 119, 6, 0.1); color: var(--warning-orange); border-left: 4px solid var(--warning-orange);">
                <i class="bi bi-exclamation-triangle me-2"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <!-- Thesis Discussions List -->
        <div class="row g-4">
            <?php if (count($discussions) > 0): ?>
                <?php foreach ($discussions as $discussion): ?>
                    <div class="col-lg-6 col-xl-4">
                        <a href="thesis_discussion.php?id=<?php echo $discussion['discussion_id']; ?>" 
                           class="discussion-card <?php echo $discussion['recent_messages'] > 0 ? 'has-new-messages' : ''; ?> position-relative"
                           data-discussion-id="<?php echo $discussion['discussion_id']; ?>">
                            
                            <!-- Discussion Header -->
                            <div class="discussion-header">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h6 class="mb-0 fw-bold text-primary" style="line-height: 1.3;">
                                        <?php echo htmlspecialchars($discussion['title']); ?>
                                    </h6>
                                    <?php if ($discussion['recent_messages'] > 0): ?>
                                        <span class="academic-badge success">
                                            <i class="bi bi-dot"></i>New
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <small class="text-muted">
                                    <i class="bi bi-person me-1"></i>
                                    Student: <strong><?php echo htmlspecialchars($discussion['student_name']); ?></strong>
                                </small>
                            </div>
                            
                            <!-- Discussion Body -->
                            <div class="discussion-body">
                                <!-- Participants Section -->
                                <div class="mb-3">
                                    <div class="row text-center g-3">
                                        <div class="col-6">
                                            <div class="participant-avatar participant-student mb-2">
                                                <?php echo strtoupper(substr($discussion['student_name'], 0, 2)); ?>
                                            </div>
                                            <div class="small fw-medium"><?php echo htmlspecialchars(explode(' ', $discussion['student_name'])[0]); ?></div>
                                            <div class="small text-muted">Student</div>
                                        </div>
                                        <div class="col-6">
                                            <div class="participant-avatar participant-<?php echo $user_role; ?> mb-2">
                                                <?php echo strtoupper(substr($_SESSION['full_name'], 0, 2)); ?>
                                            </div>
                                            <div class="small fw-medium">You</div>
                                            <div class="small text-muted"><?php echo ucfirst($user_role); ?></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Research Group Info -->
                                <div class="mb-3">
                                    <div class="small text-muted mb-1">
                                        <i class="bi bi-building me-1"></i><?php echo htmlspecialchars($discussion['college']); ?>
                                    </div>
                                    <div class="small text-muted mb-1">
                                        <i class="bi bi-people me-1"></i><?php echo htmlspecialchars($discussion['group_name']); ?>
                                    </div>
                                    <?php if (isset($discussion['adviser_name']) && $user_role == 'panel'): ?>
                                        <div class="small text-muted">
                                            <i class="bi bi-person-badge me-1"></i>Adviser: <?php echo htmlspecialchars($discussion['adviser_name']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Message Statistics -->
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="message-count-badge">
                                            <i class="bi bi-chat-dots me-1"></i><?php echo $discussion['message_count']; ?> messages
                                        </span>
                                        <?php if ($discussion['recent_messages'] > 0): ?>
                                            <div class="small text-success mt-1">
                                                <i class="bi bi-arrow-up-circle me-1"></i><?php echo $discussion['recent_messages']; ?> new today
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-end">
                                        <div class="small text-muted">
                                            <?php if ($discussion['last_message_time']): ?>
                                                Last activity:<br><?php echo date('M d, g:i A', strtotime($discussion['last_message_time'])); ?>
                                            <?php else: ?>
                                                Created:<br><?php echo date('M d, Y', strtotime($discussion['created_at'])); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Discussion Footer -->
                            <div class="discussion-footer">
                                <div class="d-grid">
                                    <div class="btn btn-primary btn-sm">
                                        <i class="bi bi-chat-square-text me-2"></i>Open Discussion
                                        <?php if ($discussion['recent_messages'] > 0): ?>
                                            <span class="badge bg-light text-primary ms-2"><?php echo $discussion['recent_messages']; ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-12">
                    <div class="discussion-card">
                        <div class="discussion-body text-center py-5">
                            <i class="bi bi-inbox text-muted" style="font-size: 4rem; opacity: 0.3;"></i>
                            <h4 class="mt-3 text-muted">No Thesis Discussions Found</h4>
                            <p class="text-muted mb-0">
                                <?php if ($user_role == 'adviser'): ?>
                                    Students in your assigned groups haven't started thesis discussions yet. 
                                    Once they begin their thesis work, discussions will appear here.
                                <?php else: ?>
                                    You haven't been added to any thesis discussions yet. 
                                    Students and advisers will invite you to relevant academic conversations.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Instructions and Guidelines -->
        <div class="instructions-card mt-4">
            <div class="instructions-header">
                <i class="bi bi-info-circle me-2"></i>Discussion Guidelines
            </div>
            <div class="p-4">
                <div class="row">
                    <div class="col-md-4">
                        <div class="text-center p-3 rounded border">
                            <i class="bi bi-upload text-primary" style="font-size: 2rem;"></i>
                            <h6 class="mt-2 mb-1">File Sharing</h6>
                            <small class="text-muted">Upload documents, images, and PDFs (max 25MB per file)</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-center p-3 rounded border">
                            <i class="bi bi-eye text-success" style="font-size: 2rem;"></i>
                            <h6 class="mt-2 mb-1">PDF Viewer</h6>
                            <small class="text-muted">View PDF documents directly within the discussion interface</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-center p-3 rounded border">
                            <i class="bi bi-bell text-warning" style="font-size: 2rem;"></i>
                            <h6 class="mt-2 mb-1">Notifications</h6>
                            <small class="text-muted">Receive real-time alerts for new messages and important updates</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Updated notification system functionality to match my_groups.php
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

        // Page functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Add loading state on card click
            const discussionCards = document.querySelectorAll('.discussion-card');
            discussionCards.forEach(card => {
                card.addEventListener('click', function(e) {
                    if (this.classList.contains('loading')) {
                        e.preventDefault();
                        return false;
                    }
                    
                    this.classList.add('loading');
                    
                    const overlay = document.createElement('div');
                    overlay.className = 'loading-overlay';
                    overlay.innerHTML = '<div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>';
                    
                    this.appendChild(overlay);
                });
            });

            // Highlight new discussions with animation
            const newDiscussions = document.querySelectorAll('.has-new-messages');
            newDiscussions.forEach((discussion, index) => {
                setTimeout(() => {
                    discussion.style.animation = 'fadeIn 0.5s ease-in-out';
                }, index * 100);
            });

            // Auto-refresh indicator every 30 seconds
            setInterval(function() {
                const activeIndicators = document.querySelectorAll('.new-message-indicator');
                activeIndicators.forEach(indicator => {
                    indicator.style.animation = 'none';
                    setTimeout(() => {
                        indicator.style.animation = 'pulse 2s infinite';
                    }, 10);
                });
            }, 30000);

            // Notification bell shake animation
            const bellButton = document.getElementById('notificationBell');
            if (bellButton && <?php echo $total_unviewed; ?> > 0) {
                bellButton.style.animation = 'shake 0.5s ease-in-out';
            }
            
            // Auto-refresh notification count every 30 seconds
            setInterval(updateNotificationCount, 30000);
        });

        // Add CSS for animations
        const style = document.createElement('style');
        style.textContent = `
            @keyframes fadeIn {
                from { 
                    opacity: 0; 
                    transform: translateY(10px); 
                }
                to { 
                    opacity: 1; 
                    transform: translateY(0); 
                }
            }
            
            .discussion-card:active {
                transform: scale(0.98) !important;
            }
            
            .discussion-card.loading {
                pointer-events: none;
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>