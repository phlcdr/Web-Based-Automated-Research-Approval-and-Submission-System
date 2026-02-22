<?php
// panel/review_titles.php - Fixed to work with normalized database
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['adviser', 'panel'])) {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../config/database.php';

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$titles = [];
$error = '';
$total_unviewed = 0;

// ============================================================================
// NOTIFICATION HANDLERS (Based on my_groups.php pattern)
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
// NOTIFICATION DATA FETCHING (Based on my_groups.php pattern)
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

// Get titles data using submissions table
try {
    if ($user_role == 'adviser') {
        $stmt = $conn->prepare("
            SELECT DISTINCT s.*, rg.group_name, 
                CONCAT(u.first_name, ' ', u.last_name) as student_name, 
                rg.college, rg.program,
                CASE WHEN EXISTS (
                    SELECT 1 FROM reviews 
                    WHERE submission_id = s.submission_id AND reviewer_id = ?
                ) THEN 'reviewed' ELSE 'not_reviewed' END as review_status,
                (SELECT COUNT(*) FROM reviews 
                 WHERE submission_id = s.submission_id AND decision = 'approve') as current_approvals,
                COALESCE(s.required_approvals, 3) as required_approvals,
                'Group Adviser' as access_type
            FROM submissions s
            JOIN research_groups rg ON s.group_id = rg.group_id
            JOIN users u ON rg.lead_student_id = u.user_id
            WHERE s.submission_type = 'title' 
            AND (rg.adviser_id = ? OR EXISTS(
                SELECT 1 FROM assignments a 
                WHERE a.context_type = 'submission' 
                AND a.context_id = s.submission_id 
                AND a.user_id = ? 
                AND a.assignment_type = 'reviewer'
            ))
            ORDER BY 
                CASE WHEN (SELECT COUNT(*) FROM reviews WHERE submission_id = s.submission_id AND decision = 'approve') < COALESCE(s.required_approvals, 3) THEN 0 ELSE 1 END,
                s.submission_date DESC
        ");
        $stmt->execute([$user_id, $user_id, $user_id]);
        $titles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $conn->prepare("
            SELECT s.*, rg.group_name, 
                CONCAT(u.first_name, ' ', u.last_name) as student_name, 
                CONCAT(adv.first_name, ' ', adv.last_name) as adviser_name, 
                rg.college, rg.program, a.assigned_date,
                CASE WHEN EXISTS (
                    SELECT 1 FROM reviews 
                    WHERE submission_id = s.submission_id AND reviewer_id = ?
                ) THEN 'reviewed' ELSE 'not_reviewed' END as review_status,
                (SELECT COUNT(*) FROM reviews 
                 WHERE submission_id = s.submission_id AND decision = 'approve') as current_approvals,
                COALESCE(s.required_approvals, 3) as required_approvals,
                'Assigned Reviewer' as access_type
            FROM submissions s
            JOIN research_groups rg ON s.group_id = rg.group_id
            JOIN users u ON rg.lead_student_id = u.user_id
            LEFT JOIN users adv ON rg.adviser_id = adv.user_id
            JOIN assignments a ON s.submission_id = a.context_id 
                AND a.context_type = 'submission' AND a.assignment_type = 'reviewer'
            WHERE s.submission_type = 'title' AND a.user_id = ?
            ORDER BY 
                CASE WHEN (SELECT COUNT(*) FROM reviews WHERE submission_id = s.submission_id AND decision = 'approve') < COALESCE(s.required_approvals, 3) THEN 0 ELSE 1 END,
                a.assigned_date DESC
        ");
        $stmt->execute([$user_id, $user_id]);
        $titles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $error = "Database error: " . $e->getMessage();
    $titles = [];
}

// Calculate counts for dashboard
$pending_count = count(array_filter($titles, function ($t) {
    return ($t['current_approvals'] ?? 0) < ($t['required_approvals'] ?? 3);
}));
$pending_reviews = count(array_filter($titles, function ($t) {
    return $t['review_status'] == 'not_reviewed' && ($t['current_approvals'] ?? 0) < ($t['required_approvals'] ?? 3);
}));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpg" href="../assets/images/essu logo.png">
    <title>Review Research Titles - ESSU Research System</title>
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

        .badge-notification {
            background: var(--danger-red);
            color: white;
            font-size: 0.7rem;
            padding: 0.2rem 0.4rem;
            border-radius: 10px;
            margin-left: 0.5rem;
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

        .academic-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .card-header-academic {
            background: linear-gradient(90deg, var(--university-blue), #1e3a8a);
            color: white;
            padding: 1rem 1.5rem;
            font-weight: 600;
            font-size: 1rem;
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
        .academic-badge.warning { background: rgba(217, 119, 6, 0.1); color: var(--warning-orange); }
        .academic-badge.info { background: rgba(2, 132, 199, 0.1); color: var(--info-blue); }

        .table-academic tbody tr:hover {
            background: rgba(30, 64, 175, 0.02);
        }

        /* Fixed Progress Bar */
        .progress-academic {
            height: 8px;
            border-radius: 4px;
            background: var(--border-light);
            width: 50px;
        }

        .progress-bar-academic {
            border-radius: 4px;
            transition: width 0.3s ease;
            height: 100%;
        }

        .user-profile {
            display: flex;
            align-items: center;
            padding: 0.5rem 1rem;
            color: white !important;
            text-decoration: none;
            border-radius: 6px;
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
            
            .academic-card { 
                padding: 1rem; 
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
            
            /* Make table responsive */
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            .table-academic {
                font-size: 0.85rem;
            }
            
            .table-academic th,
            .table-academic td {
                padding: 0.75rem !important;
                white-space: nowrap;
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
            
            .card-header-academic {
                padding: 0.75rem 1rem;
                font-size: 0.9rem;
            }
            
            /* Mobile Table Adjustments */
            .table-responsive {
                border: 1px solid var(--border-light);
                border-radius: 8px;
                margin-bottom: 1rem;
            }
            
            .table-academic {
                font-size: 0.8rem;
                margin-bottom: 0;
            }
            
            .table-academic th,
            .table-academic td {
                padding: 0.5rem !important;
                font-size: 0.75rem;
            }
            
            .table-academic th {
                font-size: 0.7rem;
                font-weight: 600;
            }
            
            /* Stack table cells vertically on very small screens */
            .table-academic tbody tr {
                display: block;
                margin-bottom: 1rem;
                border: 1px solid var(--border-light);
                border-radius: 8px;
                padding: 0.75rem;
            }
            
            .table-academic tbody td {
                display: block;
                text-align: left !important;
                padding: 0.5rem 0 !important;
                border: none !important;
            }
            
            .table-academic tbody td::before {
                content: attr(data-label);
                font-weight: 600;
                display: block;
                margin-bottom: 0.25rem;
                color: var(--text-secondary);
                font-size: 0.7rem;
                text-transform: uppercase;
            }
            
            .table-academic thead {
                display: none;
            }
            
            /* Adjust progress bars on mobile */
            .progress-academic {
                width: 100%;
                max-width: 150px;
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
            
            .card-header-academic { 
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
                                        Total: <strong><?php echo $total_unviewed; ?></strong> unread items
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
                            <div class="d-none d-md-block">
                                <div style="font-size: 0.85rem; font-weight: 600;"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                                <div style="font-size: 0.7rem; opacity: 0.8;"><?php echo $user_role === 'adviser' ? 'Research Adviser' : 'Panel Member'; ?></div>
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
                        <i class="bi bi-house-door me-2"></i>Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="review_titles.php" style="position: relative;">
                        <i class="bi bi-journal-check me-2"></i>Review Titles
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="review_chapters.php">
                        <i class="bi bi-file-earmark-text me-2"></i>Review Chapters
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="thesis_inbox.php">
                        <i class="bi bi-chat-square-text me-2"></i>Thesis Inbox
                    </a>
                </li>
                <?php if ($user_role === 'adviser'): ?>
                <li class="nav-item">
                    <a class="nav-link" href="my_groups.php">
                        <i class="bi bi-people me-2"></i>My Groups
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container" style="padding: 2rem 0;">
        <!-- Page Header -->
        <div style="background: white; border-radius: 8px; padding: 2rem; margin-bottom: 2rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-left: 4px solid var(--university-blue);">
            <h1 style="font-size: 2rem; font-weight: 700; color: var(--university-blue); margin-bottom: 0.5rem;">Research Title Reviews</h1>
            <p style="color: var(--text-secondary); font-size: 1rem;">Evaluate and provide feedback on submitted research titles from students</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle me-2"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <!-- Research Titles Table -->
        <div class="academic-card">
            <div class="card-header-academic d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-journal-check me-2"></i>Research Titles for Review
                </div>
                <span class="academic-badge warning">
                    <?php echo $pending_reviews; ?> Pending Reviews
                </span>
            </div>
            <div class="p-0">
                <?php if (count($titles) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-academic mb-0">
                            <thead style="background: var(--academic-gray); color: white;">
                                <tr>
                                    <th style="padding: 1rem; border: none;">Research Title</th>
                                    <th style="padding: 1rem; border: none;">Research Group</th>
                                    <?php if ($user_role == 'panel'): ?>
                                        <th style="padding: 1rem; border: none;">Adviser</th>
                                    <?php endif; ?>
                                    <th style="padding: 1rem; border: none;">Submission Date</th>
                                    <th style="padding: 1rem; border: none;">Review Progress</th>
                                    <th style="padding: 1rem; border: none;">Status</th>
                                    <th style="padding: 1rem; border: none;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($titles as $title): ?>
                                    <tr style="border-bottom: 1px solid var(--border-light);">
                                        <td style="padding: 1rem; border: none; vertical-align: middle;">
                                            <div class="mb-2">
                                                <strong style="color: var(--university-blue);"><?php echo htmlspecialchars($title['title']); ?></strong>
                                            </div>
                                            <small style="color: var(--text-secondary);">
                                                <i class="bi bi-person me-1"></i>
                                                By: <?php echo htmlspecialchars($title['student_name']); ?>
                                            </small>
                                        </td>
                                        <td style="padding: 1rem; border: none; vertical-align: middle;">
                                            <div class="mb-1">
                                                <strong><?php echo htmlspecialchars($title['group_name']); ?></strong>
                                            </div>
                                            <small style="color: var(--text-secondary);"><?php echo htmlspecialchars($title['college']); ?></small>
                                        </td>
                                        <?php if ($user_role == 'panel'): ?>
                                            <td style="padding: 1rem; border: none; vertical-align: middle;">
                                                <strong><?php echo isset($title['adviser_name']) ? htmlspecialchars($title['adviser_name']) : 'Not Assigned'; ?></strong>
                                                <?php if (isset($title['assigned_date'])): ?>
                                                    <br><small style="color: var(--text-secondary);">Assigned: <?php echo date('M d', strtotime($title['assigned_date'])); ?></small>
                                                <?php endif; ?>
                                            </td>
                                        <?php endif; ?>
                                        <td style="padding: 1rem; border: none; vertical-align: middle;">
                                            <div class="mb-1">
                                                <?php echo date('M d, Y', strtotime($title['submission_date'])); ?>
                                            </div>
                                            <small style="color: var(--text-secondary);">
                                                <?php echo date('h:i A', strtotime($title['submission_date'])); ?>
                                            </small>
                                        </td>
                                        <td style="padding: 1rem; border: none; vertical-align: middle;">
                                            <div class="d-flex align-items-center mb-1">
                                                <div class="progress-academic me-2">
                                                    <div class="progress-bar-academic bg-<?php echo ($title['current_approvals'] >= ($title['required_approvals'] ?? 3)) ? 'success' : 'primary'; ?>" 
                                                         style="width: <?php echo (($title['current_approvals'] / ($title['required_approvals'] ?? 3)) * 100); ?>%"></div>
                                                </div>
                                                <small style="color: var(--text-secondary);">
                                                    <?php echo $title['current_approvals']; ?>/<?php echo $title['required_approvals'] ?? 3; ?>
                                                </small>
                                            </div>
                                        </td>
                                        <td style="padding: 1rem; border: none; vertical-align: middle;">
                                            <?php if ($title['current_approvals'] >= ($title['required_approvals'] ?? 3)): ?>
                                                <span class="academic-badge success">Approved</span>
                                            <?php elseif ($title['review_status'] == 'reviewed'): ?>
                                                <span class="academic-badge info">Reviewed</span>
                                            <?php else: ?>
                                                <span class="academic-badge warning">Pending</span>
                                            <?php endif; ?>
                                            <br>
                                            <span class="academic-badge info" style="margin-top: 0.25rem;">
                                                Reviewer
                                            </span>
                                        </td>
                                        <td style="padding: 1rem; border: none; vertical-align: middle;">
                                            <a href="review_title.php?id=<?php echo $title['submission_id']; ?>" 
                                               class="btn btn-<?php echo $title['review_status'] == 'not_reviewed' ? 'primary' : 'outline-primary'; ?> btn-sm">
                                                <i class="bi bi-<?php echo $title['review_status'] == 'not_reviewed' ? 'pen' : 'eye'; ?> me-1"></i>
                                                <?php echo $title['review_status'] == 'not_reviewed' ? 'Review' : 'View'; ?>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="p-5 text-center">
                        <i class="bi bi-journal-x text-muted" style="font-size: 4rem; opacity: 0.3;"></i>
                        <h4 class="mt-3 text-muted">No Research Titles Found</h4>
                        <p class="text-muted mb-0">
                            <?php if ($user_role == 'panel'): ?>
                                <td style="padding: 1rem; border: none; vertical-align: middle;">
                                    <strong><?php echo isset($title['adviser_name']) ? htmlspecialchars($title['adviser_name']) : 'Not Assigned'; ?></strong>
                                    <?php if (isset($title['assigned_date'])): ?>
                                        <br><small style="color: var(--text-secondary);">Assigned: <?php echo date('M d', strtotime($title['assigned_date'])); ?></small>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Notification system functionality (Based on my_groups.php)
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

        document.addEventListener('DOMContentLoaded', function() {
            // Fixed progress bar animation
            const progressBars = document.querySelectorAll('.progress-bar-academic');
            progressBars.forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0%';
                setTimeout(() => {
                    bar.style.width = width;
                }, 100);
            });

            const bellButton = document.getElementById('notificationBell');
            if (bellButton && <?php echo $total_unviewed; ?> > 0) {
                bellButton.style.animation = 'shake 0.5s ease-in-out';
            }
            
            // Auto-update notifications every 30 seconds
            setInterval(updateNotificationCount, 30000);
        });

        // Add shake animation styles
        const style = document.createElement('style');
        style.textContent = `
            @keyframes shake {
                0%, 50%, 100% { transform: rotate(0deg); }
                10%, 30% { transform: rotate(-10deg); }
                20%, 40% { transform: rotate(10deg); }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>