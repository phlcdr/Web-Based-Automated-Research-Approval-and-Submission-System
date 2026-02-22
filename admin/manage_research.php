<?php
session_start(); // Add session start

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

$success_message = '';
$error_message = '';

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$type_filter = isset($_GET['type']) ? $_GET['type'] : 'all';
$search = isset($_GET['search']) ? validate_input($_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Initialize variables
$total_records = 0;
$total_pages = 1;
$research_items = [];
$research_groups = [];
$stats = ['total_titles' => 0, 'total_chapters' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];

try {
    // FIXED: Statistics query using the actual 'submissions' table
    $stats_sql = "SELECT 
                    COUNT(CASE WHEN submission_type = 'title' THEN 1 END) as total_titles,
                    COUNT(CASE WHEN submission_type = 'chapter' THEN 1 END) as total_chapters,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
                    COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved,
                    COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected
                  FROM submissions";
    $stats_stmt = $conn->query($stats_sql);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    // Get all research groups with their progress
    $groups_sql = "SELECT 
                    rg.*,
                    CONCAT(lead.first_name, ' ', lead.last_name) as lead_name,
                    CONCAT(adviser.first_name, ' ', adviser.last_name) as adviser_name,
                    adviser.email as adviser_email,
                    COUNT(DISTINCT CASE WHEN s.submission_type = 'title' AND s.status = 'approved' THEN s.submission_id END) as approved_titles,
                    COUNT(DISTINCT CASE WHEN s.submission_type = 'chapter' AND s.status = 'approved' THEN s.submission_id END) as approved_chapters,
                    COUNT(DISTINCT CASE WHEN s.status = 'pending' THEN s.submission_id END) as pending_submissions,
                    COUNT(DISTINCT s.submission_id) as total_submissions
                   FROM research_groups rg
                   LEFT JOIN users lead ON rg.lead_student_id = lead.user_id
                   LEFT JOIN users adviser ON rg.adviser_id = adviser.user_id
                   LEFT JOIN submissions s ON rg.group_id = s.group_id
                   GROUP BY rg.group_id
                   ORDER BY rg.created_at DESC";
    $groups_stmt = $conn->query($groups_sql);
    $research_groups = $groups_stmt->fetchAll(PDO::FETCH_ASSOC);

    // FIXED: Single unified query for all submissions using actual table structure
    $where_conditions = [];
    $count_params = [];
    
    if ($status_filter !== 'all') {
        $where_conditions[] = "s.status = ?";
        $count_params[] = $status_filter;
    }
    
    if ($type_filter !== 'all') {
        $type_value = $type_filter === 'titles' ? 'title' : 'chapter';
        $where_conditions[] = "s.submission_type = ?";
        $count_params[] = $type_value;
    }
    
    if (!empty($search)) {
        $where_conditions[] = "(s.title LIKE ? OR s.description LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
        $search_param = "%$search%";
        $count_params[] = $search_param;
        $count_params[] = $search_param;
        $count_params[] = $search_param;
    }
    
    $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
    
    // Get total count first
    $count_sql = "SELECT COUNT(*) FROM submissions s
                  LEFT JOIN research_groups rg ON s.group_id = rg.group_id
                  LEFT JOIN users u ON rg.lead_student_id = u.user_id
                  $where_clause";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->execute($count_params);
    $total_records = $count_stmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);
    
    // Get paginated data using actual table structure
    $research_sql = "SELECT 
                        s.submission_id as id,
                        s.title,
                        s.description,
                        s.submission_type as item_type,
                        s.status,
                        s.submission_date as submitted_at,
                        s.approval_date,
                        s.document_path as file_path,
                        CONCAT(u.first_name, ' ', u.last_name) as submitter_name,
                        u.email,
                        u.college,
                        u.department,
                        rg.group_name
                    FROM submissions s
                    LEFT JOIN research_groups rg ON s.group_id = rg.group_id
                    LEFT JOIN users u ON rg.lead_student_id = u.user_id
                    $where_clause
                    ORDER BY s.submission_date DESC
                    LIMIT $limit OFFSET $offset";
    
    $stmt = $conn->prepare($research_sql);
    $stmt->execute($count_params);
    $research_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error_message = "Error retrieving research data: " . $e->getMessage();
}

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

// Function to get status badge class (preserving original function)
function getStatusBadgeClass($status) {
    switch (strtolower($status)) {
        case 'approved':
            return 'success';
        case 'rejected':
            return 'danger';
        case 'pending':
            return 'warning';
        default:
            return 'secondary';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpg" href="../assets/images/essu logo.png">
    <title>Manage Research - Research Approval System</title>
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

        .notification-icon.user_registration { background: rgba(245, 158, 11, 0.1); color: var(--university-gold); }
        .notification-icon.title_submission { background: rgba(30, 64, 175, 0.1); color: var(--university-blue); }
        .notification-icon.chapter_submission { background: rgba(5, 150, 105, 0.1); color: var(--success-green); }
        .notification-icon.reviewer_assignment { background: rgba(2, 132, 199, 0.1); color: #0284c7; }
        .notification-icon.discussion_update { background: rgba(217, 119, 6, 0.1); color: var(--warning-orange); }

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

        .academic-card {
            background: white;
            border-radius: 8px;
            padding: 1.2rem;
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
        .academic-card.info { border-left-color: #06b6d4; }
        .academic-card.danger { border-left-color: var(--danger-red); }

        .stat-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            margin-bottom: 0.75rem;
        }

        .stat-icon.primary { background: rgba(30, 64, 175, 0.1); color: var(--university-blue); }
        .stat-icon.success { background: rgba(5, 150, 105, 0.1); color: var(--success-green); }
        .stat-icon.warning { background: rgba(217, 119, 6, 0.1); color: var(--warning-orange); }
        .stat-icon.info { background: rgba(6, 182, 212, 0.1); color: #06b6d4; }
        .stat-icon.danger { background: rgba(220, 38, 38, 0.1); color: var(--danger-red); }

        .stat-number {
            font-size: 1.8rem;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.8rem;
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
        /* COMPLETELY REDESIGNED GROUP CARD - CLEAN & RESPONSIVE */
        .group-card {
            background: white;
            border-radius: 12px;
            padding: 0;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid var(--border-light);
            transition: all 0.3s ease;
            overflow: hidden;
        }

        .group-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.12);
            transform: translateY(-2px);
        }

        /* Card Header with Group Name */
        .group-card-title {
            background: linear-gradient(135deg, var(--university-blue), #1e3a8a);
            color: white;
            padding: 1rem 1.5rem;
            font-size: 1.15rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .group-card-title i {
            font-size: 1.3rem;
        }

        /* Card Body */
        .group-card-body {
            padding: 1.5rem;
        }

        /* Information Section */
        .group-info-section {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid var(--border-light);
        }

        .group-info-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .group-info-label {
            font-size: 0.7rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.05em;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .group-info-label i {
            color: var(--university-blue);
            font-size: 0.9rem;
        }

        .group-info-value {
            font-size: 0.95rem;
            color: var(--text-primary);
            font-weight: 600;
            line-height: 1.3;
        }

        /* Statistics Grid */
        .group-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.75rem;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: linear-gradient(135deg, rgba(30, 64, 175, 0.03), rgba(6, 182, 212, 0.03));
            border-radius: 8px;
            border: 1px solid rgba(30, 64, 175, 0.1);
        }

        .group-stat-item {
            text-align: center;
            padding: 0.5rem;
        }

        .group-stat-number {
            font-size: 1.75rem;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 0.35rem;
        }

        .group-stat-number.blue { color: var(--university-blue); }
        .group-stat-number.cyan { color: #06b6d4; }
        .group-stat-number.orange { color: var(--warning-orange); }
        .group-stat-number.gray { color: var(--text-secondary); }

        .group-stat-label {
            font-size: 0.65rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.05em;
            line-height: 1.2;
        }

        /* Action Buttons */
        .group-actions-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.75rem;
        }

        .group-action-btn {
            padding: 0.75rem 1rem;
            border: none;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.2s ease;
            cursor: pointer;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            text-align: center;
            color: white;
        }

        .group-action-btn i {
            font-size: 1.25rem;
        }

        .group-action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }

        .group-action-btn.btn-view {
            background: linear-gradient(135deg, var(--university-blue), #1e3a8a);
        }

        .group-action-btn.btn-manage {
            background: linear-gradient(135deg, var(--success-green), #047857);
        }

        .group-action-btn.btn-discussion {
            background: linear-gradient(135deg, #06b6d4, #0891b2);
        }

        /* RESPONSIVE - TABLET */
        @media (max-width: 768px) {
            .group-card-body {
                padding: 1.25rem;
            }
            
            .group-info-section {
                grid-template-columns: 1fr;
                gap: 0.75rem;
                margin-bottom: 1rem;
                padding-bottom: 1rem;
            }
            
            .group-stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.5rem;
                padding: 0.75rem;
            }
            
            .group-stat-number {
                font-size: 1.5rem;
            }
            
            .group-stat-label {
                font-size: 0.6rem;
            }
            
            .group-actions-row {
                grid-template-columns: 1fr;
                gap: 0.5rem;
            }
            
            .group-action-btn {
                flex-direction: row;
                justify-content: center;
                padding: 0.65rem;
            }
            
            .group-action-btn i {
                font-size: 1.1rem;
            }
        }

        /* RESPONSIVE - MOBILE */
        @media (max-width: 576px) {
            .group-card-title {
                font-size: 1rem;
                padding: 0.85rem 1rem;
            }
            
            .group-card-title i {
                font-size: 1.1rem;
            }
            
            .group-card-body {
                padding: 1rem;
            }
            
            .group-info-section {
                gap: 0.65rem;
                margin-bottom: 1rem;
                padding-bottom: 1rem;
            }
            
            .group-info-label {
                font-size: 0.65rem;
            }
            
            .group-info-label i {
                font-size: 0.8rem;
            }
            
            .group-info-value {
                font-size: 0.9rem;
            }
            
            .group-stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.5rem;
                padding: 0.65rem;
            }
            
            .group-stat-item {
                padding: 0.35rem;
            }
            
            .group-stat-number {
                font-size: 1.4rem;
            }
            
            .group-stat-label {
                font-size: 0.6rem;
            }
            
            .group-actions-row {
                gap: 0.5rem;
            }
            
            .group-action-btn {
                padding: 0.6rem;
                font-size: 0.8rem;
            }
        }

        /* RESPONSIVE - EXTRA SMALL */
        @media (max-width: 450px) {
            .group-card-title {
                font-size: 0.95rem;
                padding: 0.75rem;
            }
            
            .group-card-body {
                padding: 0.85rem;
            }
            
            .group-info-label {
                font-size: 0.6rem;
            }
            
            .group-info-value {
                font-size: 0.85rem;
            }
            
            .group-stats-grid {
                padding: 0.5rem;
                gap: 0.4rem;
            }
            
            .group-stat-number {
                font-size: 1.2rem;
            }
            
            .group-stat-label {
                font-size: 0.55rem;
            }
            
            .group-action-btn {
                padding: 0.55rem;
                font-size: 0.75rem;
            }
            
            .group-action-btn i {
                font-size: 1rem;
            }
        }

        /* RESPONSIVE - VERY WIDE SCREENS */
        @media (min-width: 1400px) {
            .group-info-section {
                grid-template-columns: repeat(3, 1fr);
            }
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

        .filter-section {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-light);
        }

        .research-card, .group-card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-light);
            transition: all 0.2s ease;
        }

        .research-card:hover, .group-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transform: translateY(-1px);
        }

        .research-meta {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 1rem;
        }

        .research-type-badge {
            padding: 0.3rem 0.6rem;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
        }

        .research-type-badge.title { background: var(--university-blue); color: white; }
        .research-type-badge.chapter { background: #06b6d4; color: white; }

        .academic-badge {
            padding: 0.3rem 0.6rem;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-right: 0.25rem;
            display: inline-block;
        }

        .academic-badge.success { background: var(--success-green); color: white; }
        .academic-badge.warning { background: var(--warning-orange); color: white; }
        .academic-badge.danger { background: var(--danger-red); color: white; }
        .academic-badge.secondary { background: var(--text-secondary); color: white; }

        .group-card {
            cursor: pointer;
        }

        .progress-bar-custom {
            height: 8px;
            border-radius: 4px;
            background: #e5e7eb;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--university-blue), #06b6d4);
            transition: width 0.3s ease;
        }

        .custom-tabs {
            border-bottom: 2px solid var(--border-light);
            margin-bottom: 2rem;
        }

        .custom-tabs .nav-link {
            border: none;
            color: var(--text-secondary);
            padding: 1rem 1.5rem;
            font-weight: 500;
            border-radius: 0;
            position: relative;
        }

        .custom-tabs .nav-link.active {
            color: var(--university-blue);
            background: transparent;
        }

        .custom-tabs .nav-link.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--university-blue);
        }

        .form-label {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .form-control, .form-select {
            border: 1px solid var(--border-light);
            border-radius: 6px;
            padding: 0.75rem;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--university-blue);
            box-shadow: 0 0 0 0.2rem rgba(30, 64, 175, 0.25);
        }

        .btn-primary {
            background: var(--university-blue);
            border-color: var(--university-blue);
            font-weight: 500;
        }

        .btn-primary:hover {
            background: #1e3a8a;
            border-color: #1e3a8a;
        }

        .modal-header {
            background: linear-gradient(90deg, var(--university-blue), #1e3a8a);
            color: white;
            border-bottom: none;
        }

        .modal-header .btn-close {
            filter: invert(1);
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

        .pagination .page-link {
            color: var(--university-blue);
            border-color: var(--border-light);
        }

        .pagination .page-link:hover {
            color: var(--university-blue);
            background-color: rgba(30, 64, 175, 0.05);
        }

        .pagination .page-item.active .page-link {
            background-color: var(--university-blue);
            border-color: var(--university-blue);
        }

        /* RESPONSIVE - TABLET */
        @media (max-width: 768px) {
            .notification-dropdown { min-width: 320px; max-width: 350px; }
            .university-header { padding: 0.75rem 0; }
            .university-name { font-size: 1rem; }
            .university-tagline { font-size: 0.75rem; }
            .university-logo { width: 40px; height: 40px; margin-right: 10px; }
            .page-header { padding: 1.5rem; }
            .page-title { font-size: 1.5rem; }
            .page-subtitle { font-size: 0.9rem; }
            .nav-tabs .nav-link { padding: 0.85rem 0.6rem; font-size: 0.85rem; }
            .stat-number { font-size: 1.5rem; }
            .stat-label { font-size: 0.7rem; }
            .academic-card { padding: 1rem; }
            .main-content { padding: 1rem 0; }
            .section-header { padding: 0.75rem 1rem; font-size: 0.9rem; }
            .section-body { padding: 1rem; }
            .filter-section { padding: 1rem; }
            .research-card, .group-card { padding: 1rem; }
            .custom-tabs .nav-link { padding: 0.75rem 1rem; font-size: 0.85rem; }
        }

        /* RESPONSIVE - MOBILE */
        @media (max-width: 576px) {
            .university-header { padding: 0.75rem 0; }
            .university-brand { flex: 0 1 auto; max-width: 60%; }
            .university-name { font-size: 0.85rem; line-height: 1.2; }
            .university-logo { width: 35px; height: 35px; margin-right: 8px; }
            .notification-section { gap: 0.5rem; flex-shrink: 0; }
            .notification-bell { font-size: 1.25rem; padding: 0.25rem; }
            .user-avatar { width: 32px; height: 32px; font-size: 0.75rem; margin-right: 0.5rem; }
            .user-info { display: none !important; }
            
            .nav-tabs { display: flex; justify-content: space-between; flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch; scrollbar-width: none; }
            .nav-tabs::-webkit-scrollbar { display: none; }
            .nav-tabs .nav-link { flex: 1; min-width: auto; text-align: center; padding: 1rem 0.5rem; font-size: 0.9rem; display: flex; flex-direction: column; align-items: center; gap: 0.25rem; white-space: nowrap; }
            .nav-tabs .nav-link i { font-size: 1.3rem; margin: 0; }
            .nav-tabs .nav-link span { font-size: 0.75rem; }
            .nav-tabs .nav-link .d-none.d-sm-inline { display: inline !important; }
            
            .page-header { padding: 1rem; margin-bottom: 1rem; }
            .page-title { font-size: 1.25rem; }
            .page-subtitle { font-size: 0.85rem; }
            
            .stat-number { font-size: 1.3rem; }
            .stat-label { font-size: 0.65rem; }
            .stat-icon { width: 2rem; height: 2rem; font-size: 0.9rem; }
            
            .section-header { padding: 0.75rem 1rem; font-size: 0.85rem; }
            .section-body { padding: 1rem; }
            .main-content { padding: 1rem 0; }
            .academic-card { padding: 1rem; }
            
            .notification-dropdown { min-width: 300px; max-width: 320px; }
            .notification-item { padding: 0.75rem 1rem; }
            .notification-icon { width: 30px; height: 30px; font-size: 0.75rem; margin-right: 0.5rem; }
            .notification-title { font-size: 0.8rem; }
            .notification-description { font-size: 0.7rem; }
            .notification-time { font-size: 0.65rem; }
            
            .filter-section { padding: 1rem; }
            .filter-section .row { row-gap: 0.75rem; }
            .filter-section .form-label { font-size: 0.85rem; margin-bottom: 0.25rem; }
            .filter-section .form-select, .filter-section .form-control { font-size: 0.85rem; padding: 0.5rem; }
            .filter-section .btn { font-size: 0.85rem; padding: 0.5rem; }
            
            .research-card, .group-card { padding: 1rem; }
            .research-meta { flex-direction: column; align-items: flex-start; }
            
            .custom-tabs { margin-bottom: 1rem; }
            .custom-tabs .nav-link { padding: 0.75rem 0.5rem; font-size: 0.8rem; }
            
            .modal-dialog { margin: 0.5rem; }
            .modal-header, .modal-body, .modal-footer { padding: 1rem; }
            .modal-title { font-size: 1rem; }
            
            .pagination { font-size: 0.85rem; }
            .pagination .page-link { padding: 0.375rem 0.5rem; }
            
            .group-card h5 { font-size: 1rem; }
            .group-card .row { row-gap: 0.75rem; }
            .group-card .btn { font-size: 0.85rem; padding: 0.5rem; }
        }

        /* EXTRA SMALL */
        @media (max-width: 450px) {
            .university-name { font-size: 0.75rem; }
            .university-logo { width: 30px; height: 30px; margin-right: 6px; }
            .page-title { font-size: 1.1rem; }
            .page-subtitle { font-size: 0.8rem; }
            .nav-tabs .nav-link { padding: 0.75rem 0.25rem; font-size: 0.8rem; }
            .nav-tabs .nav-link i { font-size: 1.1rem; }
            .nav-tabs .nav-link span { font-size: 0.7rem; }
            .section-header { font-size: 0.8rem; padding: 0.65rem 0.75rem; }
            .stat-number { font-size: 1.2rem; }
            .stat-label { font-size: 0.6rem; }
            .academic-badge { font-size: 0.6rem; padding: 0.2rem 0.4rem; }
            .filter-section .form-label { font-size: 0.8rem; }
            .filter-section .form-select, .filter-section .form-control, .filter-section .btn { font-size: 0.8rem; padding: 0.4rem; }
            .custom-tabs .nav-link { padding: 0.65rem 0.4rem; font-size: 0.75rem; }
        }

        /* LANDSCAPE */
        @media (max-width: 768px) and (orientation: landscape) {
            .university-header { padding: 0.5rem 0; }
            .page-header { padding: 1rem; margin-bottom: 1rem; }
            .main-content { padding: 1rem 0; }
            .nav-tabs .nav-link { padding: 0.75rem 1rem; }
            .modal-dialog { max-width: 90%; }
        }

        /* Fix for dropdowns on mobile */
        @media (max-width: 576px) {
            .dropdown-menu { max-width: 90vw; }
            .dropdown-menu.notification-dropdown { min-width: 90vw; }
        }

        /* Ensure modals are scrollable on small screens */
        @media (max-height: 700px) {
            .modal-dialog-scrollable .modal-body { max-height: calc(100vh - 150px); }
        }

        /* Touch-friendly improvements */
        @media (hover: none) and (pointer: coarse) {
            .btn, .nav-link, .notification-item, .group-card, .research-card { min-height: 44px; display: inline-flex; align-items: center; justify-content: center; }
        }

        /* Print styles */
        @media print {
            .university-header, .main-nav, .notification-section, .filter-section, .pagination { display: none !important; }
            .page-header { border-left: none; box-shadow: none; }
            .research-card, .group-card { break-inside: avoid; page-break-inside: avoid; }
        }

        /* Accessibility improvements */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation-duration: 0.01ms !important; animation-iteration-count: 1 !important; transition-duration: 0.01ms !important; }
        }
        /* CLEANER GROUP CARD DESIGN */
        .group-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid var(--border-light);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .group-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: linear-gradient(135deg, var(--university-blue), #06b6d4);
        }

        .group-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.12);
            transform: translateY(-2px);
        }

        .group-card-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--border-light);
        }

        .group-info-main {
            flex: 1;
        }

        .group-name {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--university-blue);
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .group-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .group-detail-item {
            display: flex;
            align-items: start;
            gap: 0.5rem;
        }

        .group-detail-item i {
            color: var(--university-blue);
            font-size: 1rem;
            margin-top: 0.2rem;
            flex-shrink: 0;
        }

        .group-detail-label {
            font-size: 0.75rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.05em;
            margin-bottom: 0.25rem;
        }

        .group-detail-value {
            font-size: 0.95rem;
            color: var(--text-primary);
            font-weight: 600;
        }

        .group-stats-row {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: linear-gradient(135deg, rgba(30, 64, 175, 0.05), rgba(6, 182, 212, 0.05));
            border-radius: 8px;
        }

        .group-stat-box {
            flex: 1;
            text-align: center;
            padding: 0.5rem;
        }

        .group-stat-number {
            font-size: 1.75rem;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 0.25rem;
        }

        .group-stat-number.blue { color: var(--university-blue); }
        .group-stat-number.cyan { color: #06b6d4; }
        .group-stat-number.orange { color: var(--warning-orange); }
        .group-stat-number.gray { color: var(--text-secondary); }

        .group-stat-label {
            font-size: 0.7rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.05em;
        }

        .group-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .group-btn {
            padding: 0.6rem 1.25rem;
            border: none;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 600;
            transition: all 0.2s ease;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .group-btn i {
            font-size: 1rem;
        }

        .group-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }

        .group-btn.btn-view {
            background: var(--university-blue);
            color: white;
        }

        .group-btn.btn-view:hover {
            background: #1e3a8a;
        }

        .group-btn.btn-manage {
            background: var(--success-green);
            color: white;
        }

        .group-btn.btn-manage:hover {
            background: #047857;
        }

        .group-btn.btn-discussion {
            background: #06b6d4;
            color: white;
        }

        .group-btn.btn-discussion:hover {
            background: #0891b2;
        }

        /* CLEANER RESEARCH CARD DESIGN */
        .research-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid var(--border-light);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .research-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
        }

        .research-card.title::before {
            background: var(--university-blue);
        }

        .research-card.chapter::before {
            background: #06b6d4;
        }

        .research-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.12);
            transform: translateY(-2px);
        }

        .research-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--border-light);
        }

        .research-type-indicator {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }

        .research-type-badge {
            padding: 0.4rem 0.9rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .research-type-badge.title {
            background: linear-gradient(135deg, var(--university-blue), #1e3a8a);
            color: white;
        }

        .research-type-badge.chapter {
            background: linear-gradient(135deg, #06b6d4, #0891b2);
            color: white;
        }

        .research-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
            line-height: 1.4;
        }

        .research-description {
            font-size: 0.9rem;
            color: var(--text-secondary);
            line-height: 1.6;
            margin-bottom: 1rem;
        }

        .research-status-badge {
            padding: 0.4rem 0.9rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .research-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .research-info-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .research-info-label {
            font-size: 0.7rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.05em;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .research-info-label i {
            font-size: 0.85rem;
            color: var(--university-blue);
        }

        .research-info-value {
            font-size: 0.95rem;
            color: var(--text-primary);
            font-weight: 600;
        }

        .research-info-value small {
            display: block;
            font-size: 0.8rem;
            color: var(--text-secondary);
            font-weight: 400;
            margin-top: 0.15rem;
        }

        /* RESPONSIVE ADJUSTMENTS */
        @media (max-width: 768px) {
            .group-card, .research-card {
                padding: 1.25rem;
            }
            
            .group-name, .research-title {
                font-size: 1rem;
            }
            
            .group-stats-row {
                gap: 0.5rem;
            }
            
            .group-stat-number {
                font-size: 1.5rem;
            }
            
            .group-stat-label {
                font-size: 0.65rem;
            }
            
            .group-details-grid, .research-info-grid {
                grid-template-columns: 1fr;
                gap: 0.5rem;
            }
            
            .group-actions {
                flex-direction: column;
            }
            
            .group-btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 576px) {
            .group-card, .research-card {
                padding: 1rem;
            }
            
            .group-card-header, .research-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .group-stats-row {
                flex-wrap: wrap;
            }
            
            .group-stat-box {
                flex: 1 1 45%;
            }
            
            .group-btn {
                padding: 0.5rem 1rem;
                font-size: 0.8rem;
            }
            
            .research-type-badge, .research-status-badge {
                font-size: 0.7rem;
                padding: 0.35rem 0.75rem;
            }
        }

        @media (max-width: 450px) {
            .group-name, .research-title {
                font-size: 0.95rem;
            }
            
            .group-stat-number {
                font-size: 1.3rem;
            }
            
            .group-stat-label {
                font-size: 0.6rem;
            }
            
            .group-btn {
                font-size: 0.75rem;
                padding: 0.5rem 0.75rem;
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
                                                                case 'user_registration': echo 'person-plus'; break;
                                                                case 'title_submission': echo 'journal-check'; break;
                                                                case 'chapter_submission': echo 'file-earmark-check'; break;
                                                                case 'reviewer_assignment': echo 'people'; break;
                                                                case 'discussion_update': echo 'chat-square-text'; break;
                                                                default: echo 'info-circle'; break;
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
                    <a class="nav-link" href="dashboard.php">
                        <i class="bi bi-house-door me-1 me-md-2"></i>
                        <span class="d-none d-sm-inline">Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="manage_users.php">
                        <i class="bi bi-people me-1 me-md-2"></i>
                        <span class="d-none d-sm-inline">Users</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="manage_research.php">
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
            <h1 class="page-title">Research Records</h1>
            <p class="page-subtitle">Record of titles and chapter</p>
        </div>

        <!-- Success/Error Messages -->
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i>
                <?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statistics Overview -->
        <div class="row g-3 g-md-4 mb-4">
            <div class="col-6 col-lg-2">
                <div class="academic-card primary">
                    <div class="stat-icon primary">
                        <i class="bi bi-journal-text"></i>
                    </div>
                    <div class="stat-number text-primary"><?php echo $stats['total_titles']; ?></div>
                    <div class="stat-label">Research Titles</div>
                </div>
            </div>
            <div class="col-6 col-lg-2">
                <div class="academic-card info">
                    <div class="stat-icon info">
                        <i class="bi bi-file-text"></i>
                    </div>
                    <div class="stat-number" style="color: #06b6d4"><?php echo $stats['total_chapters']; ?></div>
                    <div class="stat-label">Chapters</div>
                </div>
            </div>
            <div class="col-4 col-lg-3">
                <div class="academic-card warning">
                    <div class="stat-icon warning">
                        <i class="bi bi-clock"></i>
                    </div>
                    <div class="stat-number" style="color: var(--warning-orange)"><?php echo $stats['pending']; ?></div>
                    <div class="stat-label">Pending Review</div>
                </div>
            </div>
            <div class="col-4 col-lg-2">
                <div class="academic-card success">
                    <div class="stat-icon success">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <div class="stat-number" style="color: var(--success-green)"><?php echo $stats['approved']; ?></div>
                    <div class="stat-label">Approved</div>
                </div>
            </div>
            <div class="col-4 col-lg-3">
                <div class="academic-card danger">
                    <div class="stat-icon danger">
                        <i class="bi bi-x-circle"></i>
                    </div>
                    <div class="stat-number" style="color: var(--danger-red)"><?php echo $stats['rejected']; ?></div>
                    <div class="stat-label">Rejected</div>
                </div>
            </div>
        </div>

        <!-- Tab Navigation -->
        <ul class="nav nav-tabs custom-tabs" id="researchTabs">
            <li class="nav-item">
                <a class="nav-link active" id="groups-tab" data-bs-toggle="tab" href="#groups" role="tab">
                    <i class="bi bi-people me-2"></i>Research Groups
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="submissions-tab" data-bs-toggle="tab" href="#submissions" role="tab">
                    <i class="bi bi-journal-text me-2"></i>All Submissions
                </a>
            </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content" id="researchTabsContent">
            <!-- Groups Tab -->
            <div class="tab-pane fade show active" id="groups" role="tabpanel">
                <div class="dashboard-section">
                    <div class="section-header">
                        <i class="bi bi-people me-2"></i>Research Groups (<?php echo count($research_groups); ?> total)
                    </div>
                    <div class="section-body">
                        <?php if (empty($research_groups)): ?>
                            <div class="text-center py-5">
                                <i class="bi bi-people" style="font-size: 4rem; color: var(--text-secondary); opacity: 0.5;"></i>
                                <p class="mt-3 text-muted fs-5">No research groups found.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($research_groups as $group): ?>
                                <div class="group-card">
                                    <!-- Card Header -->
                                    <div class="group-card-title">
                                        <i class="bi bi-people-fill"></i>
                                        <span><?php echo htmlspecialchars($group['group_name']); ?></span>
                                    </div>
                                    
                                    <!-- Card Body -->
                                    <div class="group-card-body">
                                        <!-- Information Section -->
                                        <div class="group-info-section">
                                            <div class="group-info-item">
                                                <div class="group-info-label">
                                                    <i class="bi bi-building"></i>
                                                    <span>College</span>
                                                </div>
                                                <div class="group-info-value"><?php echo htmlspecialchars($group['college']); ?></div>
                                            </div>
                                            
                                            <div class="group-info-item">
                                                <div class="group-info-label">
                                                    <i class="bi bi-book"></i>
                                                    <span>Program</span>
                                                </div>
                                                <div class="group-info-value"><?php echo htmlspecialchars($group['program']); ?></div>
                                            </div>
                                            
                                            <div class="group-info-item">
                                                <div class="group-info-label">
                                                    <i class="bi bi-person-badge"></i>
                                                    <span>Group Leader</span>
                                                </div>
                                                <div class="group-info-value"><?php echo htmlspecialchars($group['lead_name'] ?: 'Not assigned'); ?></div>
                                            </div>
                                            
                                            <div class="group-info-item">
                                                <div class="group-info-label">
                                                    <i class="bi bi-person-workspace"></i>
                                                    <span>Adviser</span>
                                                </div>
                                                <div class="group-info-value"><?php echo htmlspecialchars($group['adviser_name'] ?: 'Not assigned'); ?></div>
                                            </div>
                                            
                                            <div class="group-info-item">
                                                <div class="group-info-label">
                                                    <i class="bi bi-calendar-check"></i>
                                                    <span>Year Level</span>
                                                </div>
                                                <div class="group-info-value">Year <?php echo htmlspecialchars($group['year_level']); ?></div>
                                            </div>
                                            
                                            <div class="group-info-item">
                                                <div class="group-info-label">
                                                    <i class="bi bi-clock-history"></i>
                                                    <span>Created</span>
                                                </div>
                                                <div class="group-info-value"><?php echo date('M j, Y', strtotime($group['created_at'])); ?></div>
                                            </div>
                                        </div>

                                        <!-- Statistics Grid -->
                                        <div class="group-stats-grid">
                                            <div class="group-stat-item">
                                                <div class="group-stat-number blue"><?php echo $group['approved_titles']; ?></div>
                                                <div class="group-stat-label">Approved Titles</div>
                                            </div>
                                            <div class="group-stat-item">
                                                <div class="group-stat-number cyan"><?php echo $group['approved_chapters']; ?></div>
                                                <div class="group-stat-label">Approved Chapters</div>
                                            </div>
                                            <div class="group-stat-item">
                                                <div class="group-stat-number orange"><?php echo $group['pending_submissions']; ?></div>
                                                <div class="group-stat-label">Pending</div>
                                            </div>
                                            <div class="group-stat-item">
                                                <div class="group-stat-number gray"><?php echo $group['total_submissions']; ?></div>
                                                <div class="group-stat-label">Total</div>
                                            </div>
                                        </div>

                                        <!-- Action Buttons -->
                                        <div class="group-actions-row">
                                            <button type="button" class="group-action-btn btn-view" onclick="showGroupProgress(<?php echo $group['group_id']; ?>)">
                                                <i class="bi bi-eye"></i>
                                                <span>View Progress</span>
                                            </button>
                                            <button type="button" class="group-action-btn btn-manage" onclick="manageGroupSubmissions(<?php echo $group['group_id']; ?>)">
                                                <i class="bi bi-clipboard-check"></i>
                                                <span>Manage Submissions</span>
                                            </button>
                                            <button type="button" class="group-action-btn btn-discussion" onclick="manageDiscussionParticipants(<?php echo $group['group_id']; ?>)">
                                                <i class="bi bi-chat-square-text"></i>
                                                <span>Discussion</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Submissions Tab -->
            <div class="tab-pane fade" id="submissions" role="tabpanel">
                <!-- Filters -->
                <div class="filter-section">
                    <form method="GET" class="row g-3 align-items-end">
                        <input type="hidden" name="tab" value="submissions">
                        <div class="col-md-3">
                            <label for="type" class="form-label fw-semibold">Filter by Type</label>
                            <select class="form-select" id="type" name="type">
                                <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                                <option value="titles" <?php echo $type_filter === 'titles' ? 'selected' : ''; ?>>Research Titles</option>
                                <option value="chapters" <?php echo $type_filter === 'chapters' ? 'selected' : ''; ?>>Chapters</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="status" class="form-label fw-semibold">Filter by Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="search" class="form-label fw-semibold">Search</label>
                            <input type="text" class="form-control" id="search" name="search" 
                                   value="<?php echo htmlspecialchars($search); ?>" 
                                   placeholder="Search by title or researcher name">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-search me-1"></i>Filter
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Research Items List -->
                <div class="dashboard-section">
                    <div class="section-header">
                        <i class="bi bi-journal-text me-2"></i>Research Items (<?php echo $total_records; ?> total)
                    </div>
                    <div class="section-body">
                        <?php if (empty($research_items)): ?>
                            <div class="text-center py-5">
                                <i class="bi bi-journal-x" style="font-size: 4rem; color: var(--text-secondary); opacity: 0.5;"></i>
                                <p class="mt-3 text-muted fs-5">No research items found.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($research_items as $item): ?>
                                <div class="research-card <?php echo $item['item_type']; ?>">
                                    <div class="research-header">
                                        <div class="flex-grow-1">
                                            <div class="research-type-indicator">
                                                <span class="research-type-badge <?php echo $item['item_type']; ?>">
                                                    <i class="bi bi-<?php echo $item['item_type'] == 'title' ? 'journal-text' : 'file-earmark-text'; ?>"></i>
                                                    <?php echo ucfirst($item['item_type']); ?>
                                                </span>
                                            </div>
                                            <h5 class="research-title"><?php echo htmlspecialchars($item['title']); ?></h5>
                                            <?php if ($item['description'] && $item['item_type'] == 'title'): ?>
                                                <p class="research-description">
                                                    <?php echo strlen($item['description']) > 200 ? 
                                                        htmlspecialchars(substr($item['description'], 0, 200)) . '...' : 
                                                        htmlspecialchars($item['description']); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <span class="research-status-badge academic-badge <?php echo getStatusBadgeClass($item['status']); ?>">
                                                <i class="bi bi-<?php echo $item['status'] == 'approved' ? 'check-circle' : ($item['status'] == 'pending' ? 'clock' : 'x-circle'); ?>"></i>
                                                <?php echo ucfirst($item['status']); ?>
                                            </span>
                                        </div>
                                    </div>

                                    <div class="research-info-grid">
                                        <div class="research-info-item">
                                            <div class="research-info-label">
                                                <i class="bi bi-person"></i>Submitter
                                            </div>
                                            <div class="research-info-value">
                                                <?php echo htmlspecialchars($item['submitter_name']); ?>
                                                <small><?php echo htmlspecialchars($item['email']); ?></small>
                                            </div>
                                        </div>
                                        
                                        <div class="research-info-item">
                                            <div class="research-info-label">
                                                <i class="bi bi-people"></i>Research Group
                                            </div>
                                            <div class="research-info-value">
                                                <?php echo htmlspecialchars($item['group_name']); ?>
                                                <small><?php echo htmlspecialchars($item['college']); ?></small>
                                            </div>
                                        </div>
                                        
                                        <div class="research-info-item">
                                            <div class="research-info-label">
                                                <i class="bi bi-calendar3"></i>Submitted
                                            </div>
                                            <div class="research-info-value">
                                                <?php echo date('M j, Y', strtotime($item['submitted_at'])); ?>
                                                <small><?php echo date('g:i A', strtotime($item['submitted_at'])); ?></small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <!-- Pagination -->
                            <?php if ($total_pages > 1): ?>
                            <nav aria-label="Page navigation" class="mt-4">
                                <ul class="pagination justify-content-center">
                                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?tab=submissions&page=<?php echo $page - 1; ?>&status=<?php echo $status_filter; ?>&type=<?php echo $type_filter; ?>&search=<?php echo urlencode($search); ?>">Previous</a>
                                    </li>
                                    
                                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?tab=submissions&page=<?php echo $i; ?>&status=<?php echo $status_filter; ?>&type=<?php echo $type_filter; ?>&search=<?php echo urlencode($search); ?>"><?php echo $i; ?></a>
                                    </li>
                                    <?php endfor; ?>
                                    
                                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?tab=submissions&page=<?php echo $page + 1; ?>&status=<?php echo $status_filter; ?>&type=<?php echo $type_filter; ?>&search=<?php echo urlencode($search); ?>">Next</a>
                                    </li>
                                </ul>
                            </nav>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modals (keeping original modals from the original file) -->
    <!-- Group Progress Modal -->
    <div class="modal fade" id="groupProgressModal" tabindex="-1" aria-labelledby="groupProgressModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="groupProgressModalLabel">
                        <i class="bi bi-people me-2"></i>Group Progress Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="groupProgressContent">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading group details...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Other modals from original file -->
    <div class="modal fade" id="submissionsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-clipboard-check me-2"></i>Manage Title Submissions
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="submissionsModalContent">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="reviewerSelectionModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-person-plus-fill me-2"></i>Assign Reviewers
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="reviewerSelectionContent">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="viewReviewersModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-eye me-2"></i>Assigned Reviewers
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="viewReviewersContent">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="discussionModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-people me-2"></i>Manage Discussion Participants
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="discussionModalContent">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
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

        // Fix modal backdrop issue - CRITICAL FIX
        document.addEventListener('DOMContentLoaded', function() {
            // Handle all modals on the page
            const modals = document.querySelectorAll('.modal');
            
            modals.forEach(modal => {
                modal.addEventListener('hidden.bs.modal', function() {
                    // Remove any lingering backdrops
                    const backdrops = document.querySelectorAll('.modal-backdrop');
                    backdrops.forEach(backdrop => backdrop.remove());
                    
                    // Remove modal-open class from body
                    document.body.classList.remove('modal-open');
                    
                    // Reset body styles
                    document.body.style.overflow = '';
                    document.body.style.paddingRight = '';
                });
            });
        });

        // Handle tab switching based on URL parameter
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const activeTab = urlParams.get('tab');
            
            if (activeTab === 'submissions') {
                const submissionsTab = new bootstrap.Tab(document.getElementById('submissions-tab'));
                submissionsTab.show();
            }

            // Add hover effects to cards
            const cards = document.querySelectorAll('.research-card, .group-card');
            cards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    if (!this.classList.contains('group-card') || !this.onclick) {
                        this.style.transform = 'translateY(-2px)';
                    }
                });
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });
        });

        // Function to show group progress modal
        function showGroupProgress(groupId) {
            const modal = new bootstrap.Modal(document.getElementById('groupProgressModal'));
            const content = document.getElementById('groupProgressContent');
            
            // Show loading state
            content.innerHTML = `
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading group details...</p>
                </div>
            `;
            
            modal.show();
            
            // Fetch group details via AJAX
            fetch(`get_group_progress.php?group_id=${groupId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayGroupProgress(data.group, data.submissions, data.members);
                    } else {
                        content.innerHTML = `
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                Error loading group details: ${data.message || 'Unknown error'}
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    content.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            Failed to load group details. Please try again.
                        </div>
                    `;
                });
        }

        // Function to display group progress in modal
        function displayGroupProgress(group, submissions, members) {
            const content = document.getElementById('groupProgressContent');
            
            // Update modal title
            document.getElementById('groupProgressModalLabel').innerHTML = `
                <i class="bi bi-people me-2"></i>${group.group_name}
            `;
            
            // Create progress content
            let html = `
                <!-- Group Information -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h6 class="text-primary mb-3">Group Information</h6>
                        <table class="table table-sm">
                            <tr>
                                <td class="text-muted">College:</td>
                                <td><strong>${group.college}</strong></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Program:</td>
                                <td><strong>${group.program}</strong></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Year Level:</td>
                                <td><strong>${group.year_level}</strong></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Adviser:</td>
                                <td><strong>${group.adviser_name || 'Not assigned'}</strong></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-primary mb-3">Group Members</h6>
                        <div class="list-group list-group-flush">
            `;
            
            members.forEach(member => {
                html += `
                    <div class="list-group-item px-0 py-2 border-0">
                        <div class="d-flex align-items-center">
                            <div class="me-3">
                                <i class="bi bi-person-circle text-muted" style="font-size: 1.5rem;"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="fw-semibold">${member.member_name}</div>
                                <small class="text-muted">${member.student_number || 'No ID'}</small>
                                ${member.is_leader ? '<span class="badge bg-primary ms-2">Leader</span>' : ''}
                            </div>
                        </div>
                    </div>
                `;
            });
            
            html += `
                        </div>
                    </div>
                </div>

                <!-- Progress Summary -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="text-center p-3 bg-primary bg-opacity-10 rounded">
                            <div class="fs-4 fw-bold text-primary">${group.approved_titles}</div>
                            <small class="text-muted">Approved Titles</small>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="text-center p-3 bg-info bg-opacity-10 rounded">
                            <div class="fs-4 fw-bold text-info">${group.approved_chapters}</div>
                            <small class="text-muted">Approved Chapters</small>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="text-center p-3 bg-warning bg-opacity-10 rounded">
                            <div class="fs-4 fw-bold text-warning">${group.pending_submissions}</div>
                            <small class="text-muted">Pending</small>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="text-center p-3 bg-secondary bg-opacity-10 rounded">
                            <div class="fs-4 fw-bold text-secondary">${group.total_submissions}</div>
                            <small class="text-muted">Total</small>
                        </div>
                    </div>
                </div>

                <!-- Submissions History -->
                <h6 class="text-primary mb-3">Submissions History</h6>
            `;
            
            if (submissions.length === 0) {
                html += `
                    <div class="text-center py-4">
                        <i class="bi bi-journal-x text-muted" style="font-size: 3rem; opacity: 0.5;"></i>
                        <p class="mt-2 text-muted">No submissions yet.</p>
                    </div>
                `;
            } else {
                submissions.forEach(submission => {
                    const statusClass = getStatusBadgeClass(submission.status);
                    const typeClass = submission.submission_type === 'title' ? 'primary' : 'info';
                    
                    html += `
                        <div class="card mb-3">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div class="flex-grow-1">
                                        <div class="d-flex align-items-center mb-2">
                                            <span class="badge bg-${typeClass} me-2">${submission.submission_type.toUpperCase()}</span>
                                            <span class="badge bg-${statusClass}">${submission.status.toUpperCase()}</span>
                                        </div>
                                        <h6 class="mb-1">${submission.title}</h6>
                                        ${submission.description ? `<p class="text-muted small mb-2">${submission.description.substring(0, 100)}${submission.description.length > 100 ? '...' : ''}</p>` : ''}
                                    </div>
                                </div>
                                <div class="row text-muted small">
                                    <div class="col-md-6">
                                        <i class="bi bi-calendar3 me-1"></i>
                                        Submitted: ${new Date(submission.submission_date).toLocaleDateString()}
                                    </div>
                                    ${submission.approval_date ? `
                                        <div class="col-md-6">
                                            <i class="bi bi-check-circle me-1"></i>
                                            Approved: ${new Date(submission.approval_date).toLocaleDateString()}
                                        </div>
                                    ` : ''}
                                </div>
                            </div>
                        </div>
                    `;
                });
            }
            
            content.innerHTML = html;
        }

        // Helper function to get status badge class
        function getStatusBadgeClass(status) {
            switch (status.toLowerCase()) {
                case 'approved': return 'success';
                case 'rejected': return 'danger';
                case 'pending': return 'warning';
                default: return 'secondary';
            }
        }

        // Manage Group Submissions - Show titles that need reviewer assignment
        function manageGroupSubmissions(groupId) {
            fetch(`get_group_submissions.php?group_id=${groupId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showSubmissionsModal(data.submissions, groupId);
                    } else {
                        alert('Error loading submissions: ' + (data.message || 'Unknown error'));
                    }
                })
                .catch(error => {
                    alert('Failed to load submissions. Please try again.');
                    console.error('Error:', error);
                });
        }
        function showSubmissionsModal(submissions, groupId) {
            const modal = new bootstrap.Modal(document.getElementById('submissionsModal'));
            const content = document.getElementById('submissionsModalContent');
            
            let html = '<div class="list-group">';
            
            if (submissions.length === 0) {
                html += '<div class="text-center py-4 text-muted">No title submissions found for this group.</div>';
            } else {
                submissions.forEach(submission => {
                    const hasReviewers = submission.reviewer_count > 0;
                    const statusBadge = hasReviewers 
                        ? `<span class="badge bg-success">${submission.reviewer_count} Reviewers Assigned</span>`
                        : `<span class="badge bg-warning">No Reviewers</span>`;
                    
                    html += `
                        <div class="list-group-item">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div class="flex-grow-1">
                                    <h6 class="mb-1">${submission.title}</h6>
                                    <small class="text-muted">Submitted: ${new Date(submission.submission_date).toLocaleDateString()}</small>
                                </div>
                                <div>
                                    ${statusBadge}
                                </div>
                            </div>
                            <button class="btn btn-sm btn-primary" onclick="assignReviewers(${submission.submission_id}, '${submission.college || 'College of Computer Studies'}')">
                                <i class="bi bi-person-plus-fill me-1"></i>${hasReviewers ? 'Add More' : 'Assign'} Reviewers
                            </button>
                        </div>
                    `;
                });
            }
            
            html += '</div>';
            content.innerHTML = html;
            modal.show();
        }

        function assignReviewers(submissionId, college) {
            fetch(`get_available_reviewers.php?college=${encodeURIComponent(college)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showReviewerSelectionModal(submissionId, data.reviewers);
                    } else {
                        alert('Error loading reviewers: ' + (data.message || 'Unknown error'));
                    }
                })
                .catch(error => {
                    alert('Failed to load reviewers. Please try again.');
                    console.error('Error:', error);
                });
        }

        function showReviewerSelectionModal(submissionId, reviewers) {
            const modal = new bootstrap.Modal(document.getElementById('reviewerSelectionModal'));
            const content = document.getElementById('reviewerSelectionContent');
            
            // First, get currently assigned reviewers
            fetch(`get_assigned_reviewers.php?submission_id=${submissionId}`)
                .then(response => response.json())
                .then(data => {
                    let html = '';
                    
                    // Show currently assigned reviewers first
                    if (data.success && data.reviewers.length > 0) {
                        html += `
                            <div class="alert alert-info">
                                <h6 class="mb-2"><i class="bi bi-info-circle me-2"></i>Currently Assigned Reviewers (${data.reviewers.length})</h6>
                                <div class="list-group mb-0" id="assignedReviewersList">
                        `;
                        
                        data.reviewers.forEach(reviewer => {
                            html += `
                                <div class="list-group-item d-flex justify-content-between align-items-center py-2" id="reviewer-${reviewer.user_id}">
                                    <div>
                                        <strong>${reviewer.first_name} ${reviewer.last_name}</strong>
                                        <br><small class="text-muted">${reviewer.email}</small>
                                    </div>
                                    <div>
                                        <span class="badge bg-success me-2">ASSIGNED</span>
                                        <button type="button" class="btn btn-sm btn-outline-danger" 
                                                onclick="removeReviewer(${submissionId}, ${reviewer.user_id}, '${reviewer.first_name} ${reviewer.last_name}', ${data.reviewers.length})"
                                                ${data.reviewers.length <= 3 ? 'disabled title="Cannot remove: minimum 3 reviewers required"' : ''}>
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            `;
                        });
                        
                        html += `
                                </div>
                                ${data.reviewers.length <= 3 ? '<small class="text-muted mt-2 d-block"><i class="bi bi-info-circle me-1"></i>Minimum 3 reviewers required. Cannot remove reviewers.</small>' : ''}
                            </div>
                            <hr>
                            <h6 class="text-primary mb-3">Add More Reviewers:</h6>
                        `;
                    } else {
                        html += '<h6 class="text-primary mb-3">Select Reviewers to Assign:</h6>';
                    }
                    
                    // Filter out already assigned reviewers
                    const assignedIds = data.success ? data.reviewers.map(r => r.user_id) : [];
                    const availableReviewers = reviewers.filter(r => !assignedIds.includes(r.user_id));
                    
                    html += `<form id="assignReviewersForm" onsubmit="submitReviewerAssignment(event, ${submissionId})">`;
                    
                    if (availableReviewers.length === 0) {
                        html += '<div class="alert alert-warning">All available reviewers from this college have been assigned.</div>';
                    } else {
                        html += '<div class="row mb-3">';
                        
                        availableReviewers.forEach(reviewer => {
                            html += `
                                <div class="col-md-6 mb-2">
                                    <div class="form-check">
                                        <input class="form-check-input reviewer-check" type="checkbox" name="reviewers[]" value="${reviewer.user_id}" id="rev_${reviewer.user_id}">
                                        <label class="form-check-label" for="rev_${reviewer.user_id}">
                                            <strong>${reviewer.first_name} ${reviewer.last_name}</strong>
                                            <br><small class="text-muted">${reviewer.email}</small>
                                        </label>
                                    </div>
                                </div>
                            `;
                        });
                        
                        const currentCount = data.success ? data.reviewers.length : 0;
                        const minRequired = Math.max(0, 3 - currentCount);
                        
                        html += `
                                </div>
                                <div class="mt-3">
                                    <small class="text-muted">
                                        <span id="selectedCount">0</span> reviewer(s) selected
                                        ${minRequired > 0 ? `<span id="requiredText"> (minimum ${minRequired} more required)</span>` : ''}
                                    </small>
                                </div>
                                <div class="mt-3">
                                    <button type="submit" class="btn btn-primary" id="submitReviewersBtn" ${minRequired > 0 ? 'disabled' : ''}>
                                        <i class="bi bi-plus-lg me-1"></i>Add Reviewers
                                    </button>
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                </div>
                        `;
                    }
                    
                    html += '</form>';
                    content.innerHTML = html;
                    modal.show();
                    
                    // Add event listeners for checkbox counting
                    setTimeout(() => {
                        const currentCount = data.success ? data.reviewers.length : 0;
                        const minRequired = Math.max(0, 3 - currentCount);
                        
                        document.querySelectorAll('.reviewer-check').forEach(checkbox => {
                            checkbox.addEventListener('change', function() {
                                const checked = document.querySelectorAll('.reviewer-check:checked').length;
                                document.getElementById('selectedCount').textContent = checked;
                                const totalReviewers = currentCount + checked;
                                document.getElementById('submitReviewersBtn').disabled = totalReviewers < 3;
                            });
                        });
                    }, 100);
                })
                .catch(error => {
                    content.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="bi bi-x-circle me-2"></i>
                            Failed to load current reviewers. Please try again.
                        </div>
                    `;
                    console.error('Error:', error);
                });
    
        }
        function removeReviewer(submissionId, userId, reviewerName, currentCount) {
            if (currentCount <= 3) {
                alert('Cannot remove reviewer. Minimum 3 reviewers required.');
                return;
            }
            
            // Create confirmation modal
            const confirmModal = document.createElement('div');
            confirmModal.className = 'modal fade';
            confirmModal.innerHTML = `
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-exclamation-triangle text-warning me-2"></i>Confirm Removal
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p>Are you sure you want to remove <strong>${reviewerName}</strong> as a reviewer?</p>
                            <div class="alert alert-warning mb-0">
                                <small><i class="bi bi-info-circle me-1"></i>Any reviews they have submitted will remain, but they will no longer have access to review this submission.</small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-danger" id="confirmRemoveReviewerBtn">
                                <i class="bi bi-trash me-1"></i>Remove Reviewer
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.appendChild(confirmModal);
            const modal = new bootstrap.Modal(confirmModal);
            modal.show();
            
            // Handle confirmation
            document.getElementById('confirmRemoveReviewerBtn').addEventListener('click', function() {
                modal.hide();
                
                // Show loading in the reviewer item
                const reviewerItem = document.getElementById(`reviewer-${userId}`);
                if (reviewerItem) {
                    reviewerItem.innerHTML = `
                        <div class="d-flex align-items-center">
                            <div class="spinner-border spinner-border-sm text-danger me-2" role="status">
                                <span class="visually-hidden">Removing...</span>
                            </div>
                            <span class="text-muted">Removing reviewer...</span>
                        </div>
                    `;
                }
                
                // Perform the removal
                fetch('remove_reviewer.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `submission_id=${submissionId}&user_id=${userId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Show success message
                        const content = document.getElementById('reviewerSelectionContent');
                        const alert = document.createElement('div');
                        alert.className = 'alert alert-success alert-dismissible fade show';
                        alert.innerHTML = `
                            <i class="bi bi-check-circle me-2"></i>
                            Reviewer removed successfully! Refreshing list...
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        `;
                        content.insertBefore(alert, content.firstChild);
                        
                        // Reload the reviewer selection modal after a short delay
                        setTimeout(() => {
                            // Get the college from the current form or fetch it
                            fetch(`get_group_submissions.php?group_id=${data.group_id || ''}`)
                                .then(response => response.json())
                                .then(groupData => {
                                    const college = groupData.submissions && groupData.submissions[0] 
                                        ? groupData.submissions[0].college 
                                        : 'College of Computer Studies';
                                    
                                    // Refresh the modal
                                    assignReviewers(submissionId, college);
                                })
                                .catch(() => {
                                    // Fallback: just reload the page
                                    location.reload();
                                });
                        }, 1000);
                        
                    } else {
                        // Show error
                        if (reviewerItem) {
                            reviewerItem.innerHTML = `
                                <div class="alert alert-danger mb-0">
                                    <i class="bi bi-x-circle me-2"></i>
                                    Failed to remove reviewer: ${data.message || 'Unknown error'}
                                </div>
                            `;
                        }
                        setTimeout(() => location.reload(), 2000);
                    }
                })
                .catch(error => {
                    if (reviewerItem) {
                        reviewerItem.innerHTML = `
                            <div class="alert alert-danger mb-0">
                                <i class="bi bi-x-circle me-2"></i>
                                Failed to remove reviewer. Please try again.
                            </div>
                        `;
                    }
                    console.error('Error:', error);
                    setTimeout(() => location.reload(), 2000);
                });
            });
            
            // Clean up modal when hidden
            confirmModal.addEventListener('hidden.bs.modal', function() {
                confirmModal.remove();
            });
        }

        function updateReviewerCount() {
            const checked = document.querySelectorAll('.reviewer-check:checked').length;
            document.getElementById('selectedCount').textContent = checked;
            document.getElementById('submitReviewersBtn').disabled = checked < 3;
        }

        function submitReviewerAssignment(event, submissionId) {
            event.preventDefault();
            
            const formData = new FormData(event.target);
            formData.append('submission_id', submissionId);
            
            // Show loading
            const reviewerContent = document.getElementById('reviewerSelectionContent');
            reviewerContent.innerHTML = `
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Assigning reviewers...</span>
                    </div>
                    <p class="mt-2">Assigning reviewers...</p>
                </div>
            `;
            
            fetch('assign_reviewers.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Create success modal
                    const successModal = document.createElement('div');
                    successModal.className = 'modal fade';
                    successModal.innerHTML = `
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">
                                        <i class="bi bi-check-circle-fill text-success me-2"></i>Success
                                    </h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body text-center py-4">
                                    <i class="bi bi-person-check text-success" style="font-size: 4rem;"></i>
                                    <h5 class="mt-3 mb-2">Reviewers Assigned Successfully!</h5>
                                    <p class="text-muted mb-0">The selected reviewers have been notified and can now review this submission.</p>
                                </div>
                                <div class="modal-footer justify-content-center">
                                    <button type="button" class="btn btn-primary" id="reviewerSuccessOkBtn">
                                        <i class="bi bi-check-lg me-1"></i>OK
                                    </button>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    document.body.appendChild(successModal);
                    const modal = new bootstrap.Modal(successModal);
                    
                    // Hide previous modals
                    bootstrap.Modal.getInstance(document.getElementById('reviewerSelectionModal')).hide();
                    bootstrap.Modal.getInstance(document.getElementById('submissionsModal')).hide();
                    
                    // Show success modal
                    modal.show();
                    
                    // Handle OK button
                    document.getElementById('reviewerSuccessOkBtn').addEventListener('click', function() {
                        modal.hide();
                        location.reload();
                    });
                    
                    // Clean up
                    successModal.addEventListener('hidden.bs.modal', function() {
                        successModal.remove();
                        location.reload();
                    });
                    
                } else {
                    reviewerContent.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="bi bi-x-circle me-2"></i>
                            <strong>Error:</strong> ${data.message || 'Failed to assign reviewers'}
                        </div>
                        <div class="text-center mt-3">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    `;
                }
            })
            .catch(error => {
                reviewerContent.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="bi bi-x-circle me-2"></i>
                        <strong>Error:</strong> Failed to assign reviewers. Please try again.
                    </div>
                    <div class="text-center mt-3">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                `;
                console.error('Error:', error);
            });
        }

        function viewAssignedReviewers(submissionId) {
            fetch(`get_assigned_reviewers.php?submission_id=${submissionId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showAssignedReviewersModal(data.reviewers);
                    } else {
                        alert('Error loading reviewers: ' + (data.message || 'Unknown error'));
                    }
                })
                .catch(error => {
                    alert('Failed to load reviewers. Please try again.');
                    console.error('Error:', error);
                });
        }

        function showAssignedReviewersModal(reviewers) {
            const modal = new bootstrap.Modal(document.getElementById('viewReviewersModal'));
            const content = document.getElementById('viewReviewersContent');
            
            let html = '<div class="list-group">';
            reviewers.forEach(reviewer => {
                html += `
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong>${reviewer.first_name} ${reviewer.last_name}</strong>
                                <br><small class="text-muted">${reviewer.email}</small>
                            </div>
                            <span class="badge bg-primary">REVIEWER</span>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            
            content.innerHTML = html;
            modal.show();
        }

        function manageDiscussionParticipants(groupId) {
            const modal = new bootstrap.Modal(document.getElementById('discussionModal'));
            const content = document.getElementById('discussionModalContent');
            
            // Show loading state
            content.innerHTML = `
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            `;
            modal.show();
            
            fetch(`get_discussion_info.php?group_id=${groupId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showDiscussionManagementModal(data.discussion, data.participants, data.available, groupId);
                    } else {
                        content.innerHTML = `
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                <strong>Cannot Manage Discussion</strong>
                                <p class="mb-0 mt-2">${data.message}</p>
                            </div>
                            <div class="text-center mt-3">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    content.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="bi bi-x-circle me-2"></i>
                            <strong>Error</strong>
                            <p class="mb-0 mt-2">Failed to load discussion information. Please try again.</p>
                        </div>
                        <div class="text-center mt-3">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    `;
                    console.error('Error:', error);
                });
        }

        function showDiscussionManagementModal(discussion, participants, available, groupId) {
            const modal = new bootstrap.Modal(document.getElementById('discussionModal'));
            const content = document.getElementById('discussionModalContent');
            
            if (!discussion) {
                content.innerHTML = `
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        This group doesn't have an active discussion yet. Discussion will be created automatically when Chapter 3 is approved.
                    </div>
                `;
                modal.show();
                return;
            }
            
            // Debug: Log the data to console
            console.log('Discussion data:', discussion);
            console.log('Participants:', participants);
            
            let html = `
                <h6 class="mb-3">Current Participants:</h6>
                <div class="list-group mb-4">
            `;
            
            participants.forEach(p => {
                // Check both user_id match and if role is 'adviser' in the database
                const isPrimaryAdviser = (p.user_id == discussion.adviser_id) || 
                                        (p.user_role === 'adviser' && discussion.adviser_id == p.user_id);
                const isStudent = p.user_role === 'student';
                
                // Determine badge label and color
                let badgeLabel = '';
                let badgeColor = '';
                
                if (isStudent) {
                    badgeLabel = 'STUDENT';
                    badgeColor = 'primary';
                } else if (isPrimaryAdviser) {
                    badgeLabel = 'ADVISER';
                    badgeColor = 'success';
                } else {
                    // All other participants (panels and other advisers) are labeled as PANEL
                    badgeLabel = 'PANEL';
                    badgeColor = 'info';
                }
                
                // Can only remove if NOT primary adviser AND NOT student
                const canRemove = !isPrimaryAdviser && !isStudent;
                
                html += `
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong>${p.first_name} ${p.last_name}</strong> 
                            <span class="badge bg-${badgeColor}">${badgeLabel}</span>
                        </div>
                        ${canRemove ? `
                            <button class="btn btn-sm btn-outline-danger" onclick="removeDiscussionParticipant(${discussion.discussion_id}, ${p.assignment_id})">
                                <i class="bi bi-x-circle"></i> Remove
                            </button>
                        ` : ''}
                    </div>
                `;
            });
            
            html += '</div>';
            
            if (available.length > 0) {
                html += `
                    <h6 class="mb-3">Add Participants:</h6>
                    <form id="addParticipantForm" onsubmit="addDiscussionParticipants(event, ${discussion.discussion_id})">
                        <div class="row mb-3">
                `;
                
                available.forEach(person => {
                    // All available participants (advisers and panels) will be added as PANEL
                    html += `
                        <div class="col-md-6 mb-2">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="participants[]" value="${person.user_id}" id="part_${person.user_id}">
                                <label class="form-check-label" for="part_${person.user_id}">
                                    ${person.first_name} ${person.last_name}
                                    <span class="badge bg-info">PANEL</span>
                                    <br><small class="text-muted">${person.college}</small>
                                </label>
                            </div>
                        </div>
                    `;
                });
                
                html += `
                        </div>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-person-plus me-1"></i>Add Selected Participants
                        </button>
                    </form>
                `;
            } else {
                html += '<div class="alert alert-info">All available participants have been added.</div>';
            }
            
            content.innerHTML = html;
            modal.show();
        }

        function addDiscussionParticipants(event, discussionId) {
            event.preventDefault();
            
            const formData = new FormData(event.target);
            formData.append('discussion_id', discussionId);
            
            // Show loading in the modal
            const discussionContent = document.getElementById('discussionModalContent');
            discussionContent.innerHTML = `
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Adding participants...</span>
                    </div>
                    <p class="mt-2">Adding participants...</p>
                </div>
            `;
            
            fetch('add_discussion_participants.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Create and show success modal
                    const successModal = document.createElement('div');
                    successModal.className = 'modal fade';
                    successModal.innerHTML = `
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">
                                        <i class="bi bi-check-circle-fill text-success me-2"></i>Success
                                    </h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body text-center py-4">
                                    <i class="bi bi-check-circle text-success" style="font-size: 4rem;"></i>
                                    <h5 class="mt-3 mb-2">Participants Added Successfully!</h5>
                                    <p class="text-muted mb-0">The selected participants have been added to the discussion.</p>
                                </div>
                                <div class="modal-footer justify-content-center">
                                    <button type="button" class="btn btn-primary" id="successOkBtn">
                                        <i class="bi bi-check-lg me-1"></i>OK
                                    </button>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    document.body.appendChild(successModal);
                    const modal = new bootstrap.Modal(successModal);
                    
                    // Hide discussion modal first
                    bootstrap.Modal.getInstance(document.getElementById('discussionModal')).hide();
                    
                    // Show success modal
                    modal.show();
                    
                    // Handle OK button click
                    document.getElementById('successOkBtn').addEventListener('click', function() {
                        modal.hide();
                        location.reload();
                    });
                    
                    // Clean up when hidden
                    successModal.addEventListener('hidden.bs.modal', function() {
                        successModal.remove();
                        location.reload();
                    });
                    
                } else {
                    // Show error in the modal
                    discussionContent.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="bi bi-x-circle me-2"></i>
                            <strong>Error:</strong> ${data.message || 'Failed to add participants'}
                        </div>
                        <div class="text-center mt-3">
                            <button type="button" class="btn btn-secondary" onclick="location.reload()">
                                <i class="bi bi-arrow-clockwise me-1"></i>Try Again
                            </button>
                        </div>
                    `;
                }
            })
            .catch(error => {
                discussionContent.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="bi bi-x-circle me-2"></i>
                        <strong>Error:</strong> Failed to add participants. Please try again.
                    </div>
                    <div class="text-center mt-3">
                        <button type="button" class="btn btn-secondary" onclick="location.reload()">
                            <i class="bi bi-arrow-clockwise me-1"></i>Try Again
                        </button>
                    </div>
                `;
                console.error('Error:', error);
            });
        }

        function removeDiscussionParticipant(discussionId, assignmentId) {
            // Create and show custom confirmation modal
            const confirmModal = document.createElement('div');
            confirmModal.className = 'modal fade';
            confirmModal.innerHTML = `
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-exclamation-triangle me-2"></i>Confirm Removal
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-0">Are you sure you want to remove this participant from the discussion?</p>
                            <div class="alert alert-warning mt-3 mb-0">
                                <small><i class="bi bi-info-circle me-1"></i>This action cannot be undone. The participant will lose access to the discussion.</small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="bi bi-x-lg me-1"></i>Cancel
                            </button>
                            <button type="button" class="btn btn-danger" id="confirmRemoveBtn">
                                <i class="bi bi-trash me-1"></i>Remove Participant
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.appendChild(confirmModal);
            const modal = new bootstrap.Modal(confirmModal);
            modal.show();
            
            // Handle confirmation
            document.getElementById('confirmRemoveBtn').addEventListener('click', function() {
                modal.hide();
                
                // Show loading state
                const discussionContent = document.getElementById('discussionModalContent');
                discussionContent.innerHTML = `
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Removing...</span>
                        </div>
                        <p class="mt-2">Removing participant...</p>
                    </div>
                `;
                
                // Perform the removal
                fetch('remove_discussion_participant.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `discussion_id=${discussionId}&assignment_id=${assignmentId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Show success message
                        discussionContent.innerHTML = `
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle me-2"></i>
                                <strong>Success!</strong> Participant removed successfully.
                            </div>
                        `;
                        
                        // Reload after a short delay
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        discussionContent.innerHTML = `
                            <div class="alert alert-danger">
                                <i class="bi bi-x-circle me-2"></i>
                                <strong>Error:</strong> ${data.message || 'Failed to remove participant'}
                            </div>
                            <div class="text-center mt-3">
                                <button type="button" class="btn btn-secondary" onclick="location.reload()">
                                    <i class="bi bi-arrow-clockwise me-1"></i>Reload Page
                                </button>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    discussionContent.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="bi bi-x-circle me-2"></i>
                            <strong>Error:</strong> Failed to remove participant. Please try again.
                        </div>
                        <div class="text-center mt-3">
                            <button type="button" class="btn btn-secondary" onclick="location.reload()">
                                <i class="bi bi-arrow-clockwise me-1"></i>Reload Page
                            </button>
                        </div>
                    `;
                    console.error('Error:', error);
                });
            });
            
            // Clean up modal when hidden
            confirmModal.addEventListener('hidden.bs.modal', function() {
                confirmModal.remove();
            });
        }
    </script>
</body>
</html>