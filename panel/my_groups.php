<?php
// panel/my_groups.php - Complete Fixed Version with Working Notifications
include_once '../config/database.php';
include_once '../includes/functions.php';

is_logged_in();
check_role(['adviser', 'panel']);

$user_id = $_SESSION['user_id'];

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
        created_at as created_at
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
// MAIN DATA FETCHING
// ============================================================================

// Get assigned groups for this adviser
$stmt = $conn->prepare("
    SELECT rg.*, 
        CONCAT(u.first_name, ' ', u.last_name) as lead_student_name,
        u.email as student_email,
        (SELECT COUNT(*) FROM submissions WHERE group_id = rg.group_id AND submission_type = 'title' AND status = 'approved') as approved_titles,
        (SELECT COUNT(*) FROM submissions WHERE group_id = rg.group_id AND submission_type = 'chapter') as total_chapters,
        (SELECT COUNT(*) FROM submissions WHERE group_id = rg.group_id AND submission_type = 'chapter' AND status = 'approved') as approved_chapters,
        (SELECT COUNT(*) FROM submissions WHERE group_id = rg.group_id AND submission_type = 'chapter' AND status = 'pending') as pending_chapters,
        (SELECT MAX(s.created_at) FROM submissions s WHERE s.group_id = rg.group_id) as last_activity
    FROM research_groups rg
    JOIN users u ON rg.lead_student_id = u.user_id
    WHERE rg.adviser_id = ?
    ORDER BY rg.created_at DESC
");
$stmt->execute([$user_id]);
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$total_groups = count($groups);
$active_groups = count(array_filter($groups, function($g) { return $g['approved_titles'] > 0; }));
$completed_groups = count(array_filter($groups, function($g) { return min($g['approved_chapters'], 5) >= 5; }));
$pending_groups = count(array_filter($groups, function($g) { 
    return $g['approved_titles'] == 0 || $g['pending_chapters'] > 0; 
}));

// Get recent chapter submissions
$stmt = $conn->prepare("
    SELECT s.*, rg.group_name,
        CONCAT(u.first_name, ' ', u.last_name) as student_name
    FROM submissions s
    JOIN research_groups rg ON s.group_id = rg.group_id
    JOIN users u ON rg.lead_student_id = u.user_id
    WHERE rg.adviser_id = ? AND s.submission_type = 'chapter'
    ORDER BY s.created_at DESC
    LIMIT 5
");
$stmt->execute([$user_id]);
$recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpg" href="../assets/images/essu logo.png">
    <title>My Research Groups - ESSU Research System</title>
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
            float: right;
            margin-top: -0.5rem;
        }

        .stat-icon.primary { background: rgba(30, 64, 175, 0.1); color: var(--university-blue); }
        .stat-icon.success { background: rgba(5, 150, 105, 0.1); color: var(--success-green); }
        .stat-icon.warning { background: rgba(217, 119, 6, 0.1); color: var(--warning-orange); }
        .stat-icon.gold { background: rgba(245, 158, 11, 0.1); color: var(--university-gold); }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 300;
            line-height: 1;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .stat-description {
            font-size: 0.85rem;
            color: var(--text-secondary);
            opacity: 0.9;
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
            padding: 0;
        }

        /* Table Styling */
        .table-academic {
            margin: 0;
        }

        .table-academic thead th {
            background: var(--academic-gray);
            color: white;
            font-weight: 600;
            border: none;
            padding: 1rem;
        }

        .table-academic tbody tr {
            border-bottom: 1px solid var(--border-light);
            transition: background-color 0.2s ease;
        }

        .table-academic tbody tr:hover {
            background: rgba(30, 64, 175, 0.02);
        }

        .table-academic tbody td {
            padding: 1rem;
            border: none;
            vertical-align: middle;
        }

        /* Academic Badges */
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
        .academic-badge.warning { background: rgba(217, 119, 6, 0.1); color: var(--warning-orange); }
        .academic-badge.gold { background: rgba(245, 158, 11, 0.1); color: var(--university-gold); }

        /* Member Items */
        .member-item {
            background: var(--light-gray);
            border: 1px solid var(--border-light);
            transition: all 0.3s ease;
        }

        .member-item:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .member-item.leader-item {
            border-left: 4px solid var(--success-green);
        }

        .member-progress-bar {
            height: 8px;
            background-color: var(--border-light);
            border-radius: 4px;
            overflow: hidden;
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
            
            .academic-card {
                padding: 1rem;
            }
            
            .stat-number {
                font-size: 1.8rem;
            }
            
            .stat-icon {
                width: 2.5rem;
                height: 2.5rem;
                font-size: 1.1rem;
            }
            
            .table-responsive {
                font-size: 0.85rem;
            }
            
            .table-academic thead th,
            .table-academic tbody td {
                padding: 0.75rem 0.5rem;
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
            
            .academic-card {
                padding: 1rem;
                margin-bottom: 1rem;
            }
            
            .stat-number {
                font-size: 1.5rem;
            }
            
            .stat-label {
                font-size: 0.9rem;
            }
            
            .stat-description {
                font-size: 0.75rem;
            }
            
            .stat-icon {
                width: 2rem;
                height: 2rem;
                font-size: 1rem;
            }
            
            .section-header {
                padding: 0.75rem 1rem;
                font-size: 0.9rem;
            }
            
            .section-body .p-4,
            .section-body .p-5 {
                padding: 1rem !important;
            }
            
            .table-responsive {
                font-size: 0.75rem;
            }
            
            .table-academic thead th,
            .table-academic tbody td {
                padding: 0.5rem 0.25rem;
                font-size: 0.75rem;
            }
            
            .table-academic tbody td small {
                font-size: 0.7rem;
            }
            
            .modal-dialog {
                margin: 0.5rem;
            }
            
            .member-item {
                padding: 0.75rem !important;
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
            
            .stat-number {
                font-size: 1.3rem;
            }
            
            .stat-label {
                font-size: 0.85rem;
            }
            
            .stat-description {
                font-size: 0.7rem;
            }
            
            .stat-icon {
                width: 1.8rem;
                height: 1.8rem;
                font-size: 0.9rem;
            }
            
            .academic-card {
                padding: 0.75rem;
            }
            
            .table-academic thead th,
            .table-academic tbody td {
                padding: 0.4rem 0.2rem;
                font-size: 0.7rem;
            }
            
            .btn-sm {
                font-size: 0.7rem;
                padding: 0.25rem 0.4rem;
            }
            
            .section-header {
                font-size: 0.85rem;
                padding: 0.6rem 0.75rem;
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
                                <?php echo strtoupper(substr($_SESSION['full_name'], 0, 2)); ?>
                            </div>
                            <div class="user-info d-none d-md-block">
                                <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                                <div class="user-role">Research Adviser</div>
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
                <li class="nav-item">
                    <a class="nav-link active" href="my_groups.php">
                        <i class="bi bi-people me-1 me-md-2"></i>
                        <span class="d-none d-sm-inline">My Groups</span>
                    </a>
                </li>
            </ul>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">My Research Groups</h1>
            <p class="page-subtitle">Manage and monitor your assigned research groups and student progress</p>
        </div>

        <!-- Statistics Cards -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="academic-card primary">
                    <div class="stat-icon primary">
                        <i class="bi bi-people"></i>
                    </div>
                    <div class="p-3">
                        <div class="stat-number text-primary"><?php echo $total_groups; ?></div>
                        <div class="stat-label">Total Groups</div>
                        <div class="stat-description">Groups under your supervision</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="academic-card success">
                    <div class="stat-icon success">
                        <i class="bi bi-play-circle"></i>
                    </div>
                    <div class="p-3">
                        <div class="stat-number text-success"><?php echo $active_groups; ?></div>
                        <div class="stat-label">Active Groups</div>
                        <div class="stat-description">Groups with approved titles</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="academic-card gold">
                    <div class="stat-icon gold">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <div class="p-3">
                        <div class="stat-number" style="color: var(--university-gold)"><?php echo $completed_groups; ?></div>
                        <div class="stat-label">Completed</div>
                        <div class="stat-description">Groups with all chapters approved</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="academic-card warning">
                    <div class="stat-icon warning">
                        <i class="bi bi-clock"></i>
                    </div>
                    <div class="p-3">
                        <div class="stat-number text-warning"><?php echo $pending_groups; ?></div>
                        <div class="stat-label">Pending</div>
                        <div class="stat-description">Groups needing attention</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Groups List -->
        <div class="dashboard-section">
            <div class="section-header">
                <i class="bi bi-people me-2"></i>Research Groups Overview
            </div>
            <div class="section-body">
                <?php if (count($groups) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-academic">
                            <thead>
                                <tr>
                                    <th style="width: 25%;">Group Information</th>
                                    <th style="width: 25%;">Lead Student</th>
                                    <th style="width: 20%;">Academic Program</th>
                                    <th style="width: 15%;">Chapter Progress</th>
                                    <th style="width: 10%;">Status</th>
                                    <th style="width: 5%;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($groups as $group): ?>
                                    <tr>
                                        <td>
                                            <div class="mb-1">
                                                <strong class="text-primary"><?php echo htmlspecialchars($group['group_name']); ?></strong>
                                            </div>
                                            <small class="text-muted">
                                                <i class="bi bi-calendar me-1"></i>
                                                Created: <?php echo date('M d, Y', strtotime($group['created_at'])); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="mb-1">
                                                <strong><?php echo htmlspecialchars($group['lead_student_name']); ?></strong>
                                            </div>
                                            <small class="text-muted">
                                                <i class="bi bi-envelope me-1"></i>
                                                <?php echo htmlspecialchars($group['student_email']); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="mb-1">
                                                <strong><?php echo htmlspecialchars($group['college']); ?></strong>
                                            </div>
                                            <small class="text-muted"><?php echo htmlspecialchars($group['program']); ?></small>
                                        </td>
                                        <td>
                                            <?php 
                                            $displayed_approved = min($group['approved_chapters'], 5);
                                            $progress_percent = ($displayed_approved / 5) * 100;
                                            ?>
                                            <div class="mb-1">
                                                <span class="academic-badge <?php echo $displayed_approved >= 5 ? 'success' : 'primary'; ?>">
                                                    <?php echo $displayed_approved; ?>/5 Chapters
                                                </span>
                                            </div>
                                            <?php if ($group['pending_chapters'] > 0): ?>
                                                <small class="text-warning mt-1 d-block">
                                                    <i class="bi bi-clock me-1"></i><?php echo $group['pending_chapters']; ?> Pending
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($group['approved_chapters'] >= 5): ?>
                                                <span class="academic-badge success">Completed</span>
                                            <?php elseif ($group['approved_titles'] > 0): ?>
                                                <span class="academic-badge primary">In Progress</span>
                                            <?php else: ?>
                                                <span class="academic-badge warning">Not Started</span>
                                            <?php endif; ?>
                                            
                                            <?php if ($group['last_activity']): ?>
                                                <div class="small text-muted mt-1">
                                                    Last: <?php echo date('M d', strtotime($group['last_activity'])); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-outline-primary btn-sm" 
                                                    onclick="viewGroupMembers(<?php echo $group['group_id']; ?>, '<?php echo addslashes($group['group_name']); ?>')"
                                                    title="View Group Members">
                                                <i class="bi bi-people"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="p-5 text-center">
                        <i class="bi bi-people text-muted" style="font-size: 4rem; opacity: 0.3;"></i>
                        <h4 class="mt-3 text-muted">No Research Groups Assigned</h4>
                        <p class="text-muted mb-0">You haven't been assigned to supervise any research groups yet. Check back later for new assignments.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Activities -->
        <?php if (count($recent_activities) > 0): ?>
            <div class="dashboard-section">
                <div class="section-header">
                    <i class="bi bi-activity me-2"></i>Recent Group Activities
                </div>
                <div class="section-body p-4">
                    <?php foreach ($recent_activities as $activity): ?>
                        <div class="d-flex align-items-start mb-3 pb-3 border-bottom">
                            <div class="me-3">
                                <div class="rounded-circle bg-<?php echo $activity['status'] == 'approved' ? 'success' : ($activity['status'] == 'rejected' ? 'danger' : 'warning'); ?>" 
                                     style="width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;">
                                    <i class="bi bi-file-earmark-text text-white"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="mb-1">Chapter <?php echo $activity['chapter_number']; ?> Submission</h6>
                                        <p class="mb-1 text-muted">
                                            <strong><?php echo htmlspecialchars($activity['student_name']); ?></strong> 
                                            from <strong><?php echo htmlspecialchars($activity['group_name']); ?></strong>
                                        </p>
                                        <small class="text-muted">
                                            <i class="bi bi-calendar me-1"></i>
                                            <?php echo date('M d, Y \a\t h:i A', strtotime($activity['date'])); ?>
                                        </small>
                                    </div>
                                    <span class="academic-badge <?php echo $activity['status'] == 'approved' ? 'success' : ($activity['status'] == 'rejected' ? 'warning' : 'primary'); ?>">
                                        <?php echo ucfirst($activity['status']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Group Members Modal -->
    <div class="modal fade" id="groupMembersModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(90deg, var(--university-blue), #1e3a8a); color: white;">
                    <h5 class="modal-title" id="groupMembersModalLabel">
                        <i class="bi bi-people me-2"></i>Group Members
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="membersContent">
                        <div class="text-center py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
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

        function viewGroupMembers(groupId, groupName) {
            document.getElementById('groupMembersModalLabel').innerHTML = 
                '<i class="bi bi-people me-2"></i>Members of "' + groupName + '"';
            
            document.getElementById('membersContent').innerHTML = `
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            `;
            
            const modal = new bootstrap.Modal(document.getElementById('groupMembersModal'));
            modal.show();
            
            fetch('get_group_members.php?group_id=' + groupId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayGroupMembers(data.members);
                    } else {
                        document.getElementById('membersContent').innerHTML = 
                            '<div class="alert alert-danger">Error loading group members: ' + data.message + '</div>';
                    }
                })
                .catch(error => {
                    document.getElementById('membersContent').innerHTML = 
                        '<div class="alert alert-danger">Error loading group members. Please try again.</div>';
                });
        }
        
        function displayGroupMembers(members) {
            let html = '';
            
            if (members.length === 0) {
                html = '<div class="alert alert-info">No members found for this group.</div>';
            } else {
                html = '<div class="row g-3">';
                
                members.forEach(member => {
                    const isLeader = member.role === 'Leader';
                    const progressPercent = (member.approved_chapters / 5) * 100;
                    
                    html += `
                        <div class="col-md-6">
                            <div class="member-item ${isLeader ? 'leader-item' : ''} p-3 rounded">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h6 class="mb-1">
                                            ${member.first_name} ${member.last_name}
                                            ${isLeader ? '<span class="academic-badge success ms-2">Leader</span>' : '<span class="academic-badge primary ms-2">Member</span>'}
                                        </h6>
                                        <small class="text-muted">${member.email}</small>
                                        ${member.student_id ? '<br><small class="text-muted">ID: ' + member.student_id + '</small>' : ''}
                                    </div>
                                </div>
                                
                                <div class="mb-2">
                                    <small class="text-muted d-block">Research Progress</small>
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <div class="progress member-progress-bar flex-fill me-2">
                                            <div class="progress-bar bg-${progressPercent >= 100 ? 'success' : (progressPercent > 0 ? 'primary' : 'secondary')}" 
                                                 style="width: ${Math.min(progressPercent, 100)}%">
                                            </div>
                                        </div>
                                        <small class="text-muted">${member.approved_chapters}/5</small>
                                    </div>
                                    ${!member.is_registered_user ? '<small class="text-info"><i class="bi bi-info-circle me-1"></i>Information only (not registered)</small>' : ''}
                                </div>
                                
                                <div class="d-flex justify-content-between">
                                    <small class="text-muted">
                                        <i class="bi bi-file-text me-1"></i>
                                        ${member.approved_titles} Title(s)
                                    </small>
                                    <small class="text-muted">
                                        <i class="bi bi-calendar-check me-1"></i>
                                        Joined ${new Date(member.join_date).toLocaleDateString()}
                                    </small>
                                </div>
                                
                                ${member.pending_chapters > 0 ? `
                                    <div class="mt-2">
                                        <small class="text-warning">
                                            <i class="bi bi-clock me-1"></i>
                                            ${member.pending_chapters} pending chapter(s)
                                        </small>
                                    </div>
                                ` : ''}
                            </div>
                        </div>
                    `;
                });
                
                html += '</div>';
            }
            
            document.getElementById('membersContent').innerHTML = html;
        }

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