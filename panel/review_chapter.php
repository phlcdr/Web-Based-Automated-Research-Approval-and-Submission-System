<?php
// panel/review_chapter.php - Fixed notification system to match my_groups.php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'adviser') {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../config/database.php';

$user_id = $_SESSION['user_id'];
$chapter_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($chapter_id <= 0) {
    header("Location: review_chapters.php?error=invalid_chapter");
    exit();
}

$success = '';
$error = '';
$total_unviewed = 0;

// ============================================================================
// NOTIFICATION HANDLERS (Updated to match my_groups.php)
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
// NOTIFICATION DATA FETCHING (Updated to match my_groups.php)
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

// SIMPLIFIED REVIEW SUBMISSION LOGIC
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    $comments = trim($_POST['comments'] ?? '');
    $decision = trim($_POST['decision'] ?? '');
    
    if (empty($comments)) {
        $error = "Please provide feedback comments";
    } elseif (!in_array($decision, ['approve', 'reject'])) {
        $error = "Please select a valid decision";
    } else {
        try {
            $conn->beginTransaction();
            
            // Insert review
            $stmt = $conn->prepare("INSERT INTO reviews (submission_id, reviewer_id, comments, decision) VALUES (?, ?, ?, ?)");
            $result = $stmt->execute([$chapter_id, $user_id, $comments, $decision]);
            
            if ($result) {
                // Update submission status
                $new_status = ($decision === 'approve') ? 'approved' : 'rejected';
                $approval_date = ($decision === 'approve') ? date('Y-m-d H:i:s') : null;
                
                $stmt = $conn->prepare("UPDATE submissions SET status = ?, approval_date = ? WHERE submission_id = ?");
                $stmt->execute([$new_status, $approval_date, $chapter_id]);
                
                // Insert review as a message in the messages table
                $decision_text = ($decision === 'approve') ? 'APPROVED' : 'Needs Revision';
                $message_text = "Review Desicion: {$decision_text}\n\n{$comments}";
                
                $stmt = $conn->prepare("
                    INSERT INTO messages (context_type, context_id, user_id, message_type, message_text) 
                    VALUES ('submission', ?, ?, 'text', ?)
                ");
                $stmt->execute([$chapter_id, $user_id, $message_text]);
                
                // Create notification for student
                try {
                    $stmt = $conn->prepare("SELECT rg.lead_student_id FROM submissions s JOIN research_groups rg ON s.group_id = rg.group_id WHERE s.submission_id = ?");
                    $stmt->execute([$chapter_id]);
                    $student = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($student) {
                        $notification_title = ($decision === 'approve') ? 'Chapter Approved' : 'Chapter Needs Revision';
                        $notification_message = ($decision === 'approve') ? 'Your chapter has been approved.' : 'Your chapter needs revision. Please check the feedback.';
                        
                        $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, context_type, context_id) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$student['lead_student_id'], $notification_title, $notification_message, 'chapter_review', 'submission', $chapter_id]);
                    }
                } catch (Exception $e) {
                    // Continue even if notification fails
                }
                
                $conn->commit();
                $success = "Chapter review submitted successfully!";
            } else {
                $conn->rollBack();
                $error = "Failed to submit review";
            }
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "Error: " . $e->getMessage();
        }
    }
}

// Get chapter info with authorization - Updated to use submissions table
$stmt = $conn->prepare("
    SELECT s.*, rg.group_name, rg.adviser_id, rg.group_id,
        CONCAT(u.first_name, ' ', u.last_name) as student_name,
        rg.college, rg.program
    FROM submissions s
    JOIN research_groups rg ON s.group_id = rg.group_id
    JOIN users u ON rg.lead_student_id = u.user_id
    WHERE s.submission_id = ? AND s.submission_type = 'chapter' AND rg.adviser_id = ?
");
$stmt->execute([$chapter_id, $user_id]);
$chapter = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$chapter) {
    die("Chapter not found or not authorized");
}

// Get reviews
$stmt = $conn->prepare("
    SELECT r.*, CONCAT(u.first_name, ' ', u.last_name) as reviewer_name
    FROM reviews r
    JOIN users u ON r.reviewer_id = u.user_id
    WHERE r.submission_id = ?
    ORDER BY r.review_date DESC
");
$stmt->execute([$chapter_id]);
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

$can_review = ($chapter['status'] === 'pending');

// Chapter titles
$chapter_titles = [
    1 => 'Introduction',
    2 => 'Literature Review', 
    3 => 'Methodology',
    4 => 'Results and Discussion',
    5 => 'Summary, Conclusions and Recommendations'
];

// Clean document path
$clean_document_path = '';
if (!empty($chapter['document_path'])) {
    $path = $chapter['document_path'];
    if (strpos($path, './') === 0) $path = substr($path, 2);
    if (strpos($path, '/') === 0) $path = substr($path, 1);
    $clean_document_path = $path;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpg" href="../assets/images/essu logo.png">
    <title>Review Chapter - ESSU Research System</title>
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
        .notification-icon.submission { background: rgba(30, 64, 175, 0.1); color: var(--university-blue); }
        .notification-icon.general { background: rgba(107, 114, 128, 0.1); color: var(--text-secondary); }

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

        .main-content {
            padding: 2rem 0;
        }

        .academic-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .card-header-academic {
            background: linear-gradient(90deg, var(--university-blue), #1e3a8a);
            color: white;
            padding: 1rem 1.5rem;
            font-weight: 600;
            font-size: 1rem;
        }

        .card-body-academic {
            padding: 2rem;
        }

        .academic-badge {
            padding: 0.4rem 0.8rem;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .academic-badge.primary { background: rgba(30, 64, 175, 0.1); color: var(--university-blue); }
        .academic-badge.success { background: rgba(5, 150, 105, 0.1); color: var(--success-green); }
        .academic-badge.warning { background: rgba(217, 119, 6, 0.1); color: var(--warning-orange); }
        .academic-badge.danger { background: rgba(220, 38, 38, 0.1); color: var(--danger-red); }
        .academic-badge.info { background: rgba(2, 132, 199, 0.1); color: var(--info-blue); }

        .alert-academic {
            border: none;
            border-radius: 8px;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
        }

        .alert-academic.success {
            background: rgba(5, 150, 105, 0.1);
            color: var(--success-green);
            border-left: 4px solid var(--success-green);
        }

        .alert-academic.danger {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger-red);
            border-left: 4px solid var(--danger-red);
        }

        .document-preview-card {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border: 2px dashed var(--border-light);
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .document-preview-card:hover {
            border-color: var(--university-blue);
            background: linear-gradient(135deg, rgba(30, 64, 175, 0.05), rgba(30, 64, 175, 0.1));
        }

        .document-icon {
            font-size: 4rem;
            color: var(--university-blue);
            margin-bottom: 1rem;
            opacity: 0.7;
        }

        .pdf-modal {
            --bs-modal-width: 95vw;
            --bs-modal-height: 90vh;
        }

        .pdf-modal .modal-dialog {
            max-width: var(--bs-modal-width);
            width: var(--bs-modal-width);
            height: var(--bs-modal-height);
            margin: 2.5vh auto;
            display: flex;
            align-items: center;
        }

        .pdf-modal .modal-content {
            width: 100%;
            height: 100%;
            border: none;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }

        .pdf-modal .modal-body {
            padding: 0;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .pdf-viewer-container {
            height: 100%;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .pdf-toolbar {
            background: linear-gradient(135deg, var(--university-blue), #1e3a8a);
            color: white;
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .pdf-toolbar-title {
            display: flex;
            align-items: center;
            flex: 1;
            min-width: 0;
        }

        .pdf-toolbar-title span {
            margin-left: 0.5rem;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .pdf-toolbar-actions {
            display: flex;
            gap: 0.5rem;
            flex-shrink: 0;
        }

        .pdf-viewer {
            flex: 1;
            min-height: 0;
            background: #f8f9fa;
            position: relative;
        }

        .pdf-viewer iframe {
            width: 100%;
            height: 100%;
            border: none;
            background: white;
        }

        .pdf-viewer object {
            width: 100%;
            height: 100%;
            border: none;
        }

        .pdf-fallback {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 2rem;
            background: white;
            color: var(--text-secondary);
        }

        .form-control {
            border-radius: 8px;
        }

        .form-control:focus {
            border-color: var(--university-blue);
            box-shadow: 0 0 0 0.2rem rgba(30, 64, 175, 0.1);
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
            
            .nav-tabs .nav-link { 
                padding: 0.85rem 0.6rem; 
                font-size: 0.85rem; 
            }
            
            .main-content { 
                padding: 1rem 0; 
            }

            .pdf-modal {
                --bs-modal-width: 95vw;
            }

            .pdf-modal .modal-body {
                height: 70vh;
            }

            .card-body-academic {
                padding: 1.5rem;
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

            .card-body-academic {
                padding: 1rem;
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
                    <a class="nav-link active" href="review_chapters.php">
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
                    <a class="nav-link" href="my_groups.php">
                        <i class="bi bi-people me-1 me-md-2"></i>
                        <span class="d-none d-sm-inline">My Groups</span>
                    </a>
                </li>
            </ul>
        </div>
    </nav>

    <div class="container main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="review_chapters.php" class="text-decoration-none">Review Chapters</a></li>
                        <li class="breadcrumb-item active">Review Chapter</li>
                    </ol>
                </nav>
            </div>
            <a href="review_chapters.php" class="btn btn-outline-primary">
                <i class="bi bi-arrow-left me-1"></i> Back to Chapters
            </a>
        </div>

        <?php if ($success): ?>
            <div class="alert-academic success">
                <i class="bi bi-check-circle me-2"></i> <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert-academic danger">
                <i class="bi bi-exclamation-triangle me-2"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-8">
                <div class="academic-card">
                    <div class="card-header-academic">
                        <i class="bi bi-file-earmark-text me-2"></i>Chapter <?php echo $chapter['chapter_number']; ?> Information
                    </div>
                    <div class="card-body-academic">
                        <h3 class="text-primary mb-3">
                            Chapter <?php echo $chapter['chapter_number']; ?>: <?php echo $chapter_titles[$chapter['chapter_number']] ?? 'Chapter'; ?>
                        </h3>
                        <h5 class="text-muted mb-3"><?php echo htmlspecialchars($chapter['title']); ?></h5>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <h6 class="text-muted mb-2">Submitted by:</h6>
                                <p class="mb-0"><strong><?php echo htmlspecialchars($chapter['student_name']); ?></strong></p>
                                <small class="text-muted"><?php echo htmlspecialchars($chapter['group_name']); ?></small>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-muted mb-2">Academic Program:</h6>
                                <p class="mb-0"><strong><?php echo htmlspecialchars($chapter['college']); ?></strong></p>
                                <small class="text-muted"><?php echo htmlspecialchars($chapter['program']); ?></small>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <h6 class="text-muted mb-2">Submission Date:</h6>
                                <p class="mb-0"><?php echo date('F d, Y', strtotime($chapter['submission_date'])); ?></p>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-muted mb-2">Chapter Status:</h6>
                                <div>
                                    <span class="academic-badge <?php echo $chapter['status'] == 'approved' ? 'success' : ($chapter['status'] == 'rejected' ? 'danger' : 'warning'); ?>">
                                        <?php echo ucfirst($chapter['status']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($clean_document_path): ?>
                <div class="academic-card">
                    <div class="card-header-academic">
                        <i class="bi bi-file-earmark-pdf me-2"></i>Chapter Document
                    </div>
                    <div class="card-body-academic">
                        <?php
                        $full_path = '../' . $clean_document_path;
                        if (file_exists($full_path)) {
                            $file_extension = strtolower(pathinfo($full_path, PATHINFO_EXTENSION));
                            ?>
                            <div class="document-preview-card" onclick="openPDFModal('<?php echo htmlspecialchars($clean_document_path); ?>', '<?php echo htmlspecialchars(basename($clean_document_path)); ?>')">
                                <i class="bi bi-<?php echo $file_extension === 'pdf' ? 'file-earmark-pdf' : 'file-earmark'; ?> document-icon"></i>
                                <h5><?php echo htmlspecialchars(basename($clean_document_path)); ?></h5>
                                <p class="text-muted mb-3">Chapter <?php echo $chapter['chapter_number']; ?> • <?php echo strtoupper($file_extension); ?></p>
                                <div class="d-flex gap-2 justify-content-center">
                                    <button type="button" class="btn btn-primary">
                                        <i class="bi bi-eye me-1"></i>View Document
                                    </button>
                                    <a href="../<?php echo htmlspecialchars($clean_document_path); ?>" target="_blank" class="btn btn-outline-primary">
                                        <i class="bi bi-box-arrow-up-right me-1"></i>Open in New Tab
                                    </a>
                                    <a href="../<?php echo htmlspecialchars($clean_document_path); ?>" download class="btn btn-outline-secondary">
                                        <i class="bi bi-download me-1"></i>Download
                                    </a>
                                </div>
                            </div>
                            <?php
                        } else {
                            ?>
                            <div class="text-center py-4">
                                <i class="bi bi-file-x text-muted" style="font-size: 3rem; opacity: 0.3;"></i>
                                <h5 class="mt-3 text-muted">Document Not Found</h5>
                                <p class="text-muted mb-0">The chapter document could not be located on the server.</p>
                            </div>
                            <?php
                        }
                        ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="academic-card">
                    <div class="card-header-academic">
                        <i class="bi bi-chat-square-text me-2"></i>Review History (<?php echo count($reviews); ?> reviews)
                    </div>
                    <div class="card-body-academic">
                        <?php if (count($reviews) > 0): ?>
                            <?php foreach ($reviews as $review): ?>
                                <div class="border rounded p-3 mb-3 <?php echo $review['reviewer_id'] == $user_id ? 'bg-light' : ''; ?>">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div>
                                            <h6 class="mb-1">
                                                <?php echo htmlspecialchars($review['reviewer_name']); ?>
                                                <?php if ($review['reviewer_id'] == $user_id): ?>
                                                    <span class="academic-badge primary">Your Review</span>
                                                <?php endif; ?>
                                            </h6>
                                            <span class="academic-badge success">Reviewer</span>
                                        </div>
                                        <div class="text-end">
                                            <span class="academic-badge <?php echo $review['decision'] == 'approve' ? 'success' : 'danger'; ?>">
                                                <?php echo $review['decision'] == 'approve' ? 'Approved' : 'Needs Revision'; ?>
                                            </span>
                                            <div class="small text-muted mt-1">
                                                <?php echo date('M d, Y \a\t h:i A', strtotime($review['review_date'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div>
                                        <h6 class="text-muted mb-2">Review Comments:</h6>
                                        <p class="mb-0"><?php echo nl2br(htmlspecialchars($review['comments'])); ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="bi bi-clipboard-data text-muted" style="font-size: 3rem; opacity: 0.3;"></i>
                                <h5 class="mt-3 text-muted">No Reviews Yet</h5>
                                <p class="text-muted mb-0">No reviews have been submitted for this chapter.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <?php if ($can_review): ?>
                    <div class="academic-card">
                        <div class="card-header-academic">
                            <i class="bi bi-pen me-2"></i>Submit Your Review
                        </div>
                        <div class="card-body-academic">
                            <form method="POST">
                                <div class="mb-3">
                                    <label for="comments" class="form-label fw-bold">Chapter Feedback</label>
                                    <textarea class="form-control" id="comments" name="comments" rows="8" required 
                                              placeholder="Provide detailed feedback on the chapter content, organization, methodology, writing quality, and academic standards..."></textarea>
                                    <div class="form-text">Focus on content quality, methodology, and academic writing standards.</div>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label fw-bold">Review Decision</label>
                                    <div class="d-grid gap-2">
                                        <div class="form-check p-3 border rounded">
                                            <input class="form-check-input" type="radio" name="decision" id="approve" value="approve" required>
                                            <label class="form-check-label w-100" for="approve">
                                                <i class="bi bi-check-circle text-success me-2"></i>
                                                <strong>Approve Chapter</strong>
                                                <div class="small text-muted mt-1">The chapter meets academic standards and quality requirements.</div>
                                            </label>
                                        </div>
                                        <div class="form-check p-3 border rounded">
                                            <input class="form-check-input" type="radio" name="decision" id="reject" value="reject" required>
                                            <label class="form-check-label w-100" for="reject">
                                                <i class="bi bi-x-circle text-danger me-2"></i>
                                                <strong>Needs Revision</strong>
                                                <div class="small text-muted mt-1">The chapter requires modifications before approval.</div>
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-grid">
                                    <button type="submit" name="submit_review" class="btn btn-primary">
                                        <i class="bi bi-send me-1"></i> Submit Chapter Review
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert-academic <?php echo $chapter['status'] == 'approved' ? 'success' : 'warning'; ?>">
                        <i class="bi bi-<?php echo $chapter['status'] == 'approved' ? 'check-circle' : 'clock'; ?> me-2"></i>
                        <strong><?php echo $chapter['status'] == 'approved' ? 'Chapter Approved' : 'Review Complete'; ?></strong><br>
                        <?php if ($chapter['status'] == 'approved'): ?>
                            This chapter has been approved and is ready for the next phase.
                        <?php else: ?>
                            This chapter is currently under review or has been reviewed.
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="modal fade pdf-modal" id="pdfModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-body">
                    <div class="pdf-viewer-container">
                        <div class="pdf-toolbar">
                            <div class="pdf-toolbar-title">
                                <i class="bi bi-file-earmark-pdf"></i>
                                <span id="pdfFileName">Document Viewer</span>
                            </div>
                            <div class="pdf-toolbar-actions">
                                <button id="downloadPdfBtn" class="btn btn-outline-light btn-sm" title="Download">
                                    <i class="bi bi-download"></i>
                                    <span class="d-none d-sm-inline ms-1">Download</span>
                                </button>
                                <button id="openNewTabBtn" class="btn btn-outline-light btn-sm" title="Open in New Tab">
                                    <i class="bi bi-box-arrow-up-right"></i>
                                    <span class="d-none d-sm-inline ms-1">New Tab</span>
                                </button>
                                <button type="button" class="btn btn-outline-light btn-sm" data-bs-dismiss="modal" title="Close">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                        </div>
                        <div class="pdf-viewer">
                            <iframe id="pdfViewer" allowfullscreen title="PDF Viewer" loading="lazy"></iframe>
                            <object id="pdfObject" type="application/pdf" style="display: none;">
                                <p>Your browser does not support PDFs. Please download the PDF to view it.</p>
                            </object>
                            <div class="pdf-fallback" id="pdfFallback">
                                <i class="bi bi-file-earmark-pdf" style="font-size: 4rem; opacity: 0.3; color: var(--university-blue);"></i>
                                <h4 class="mt-3 mb-2">PDF Viewer</h4>
                                <p class="mb-4">Choose how you'd like to view this document:</p>
                                <div class="d-flex flex-column flex-sm-row gap-2 justify-content-center">
                                    <button id="fallbackDownload" class="btn btn-primary">
                                        <i class="bi bi-download me-2"></i>Download PDF
                                    </button>
                                    <button id="fallbackNewTab" class="btn btn-outline-primary">
                                        <i class="bi bi-box-arrow-up-right me-2"></i>Open in New Tab
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Quick Message Section for Adviser -->
    <?php if ($chapter): ?>
        <div class="academic-card">
            <div class="card-header-academic">
                <i class="bi bi-chat-square-dots me-2"></i>Discussion with Student
            </div>
            <div class="card-body-academic text-center">
                <i class="bi bi-chat-dots text-primary" style="font-size: 3rem; opacity: 0.7;"></i>
                <h5 class="mt-3">Have questions or need to discuss?</h5>
                <p class="text-muted mb-3">Open a direct chat with the student about this chapter.</p>
                <button type="button" class="btn btn-primary" onclick="openChat(<?php echo $chapter_id; ?>, <?php echo $chapter['chapter_number']; ?>)">
                    <i class="bi bi-chat-dots me-2"></i>Open Chat with <?php echo htmlspecialchars($chapter['student_name']); ?>
                </button>
            </div>
        </div>
    <?php endif; ?>
    <!-- Chat Popup Modal -->
    <div class="modal fade" id="chatModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content" style="height: 600px;">
                <div class="modal-header" style="background: linear-gradient(135deg, var(--university-blue), #1e3a8a); color: white;">
                    <h5 class="modal-title">
                        <i class="bi bi-chat-dots me-2"></i>
                        <span id="chatTitle">Discussion</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0 d-flex flex-column" style="height: 100%;">
                    <!-- Messages Container -->
                    <div id="chatMessages" class="flex-grow-1 p-3" style="overflow-y: auto; background: #f8f9fa;">
                        <div class="text-center py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Message Input -->
                    <div class="p-3 bg-white border-top">
                        <div id="filePreviewContainer" style="display: none;"></div>
                        <form id="chatForm" class="d-flex gap-2">
                            <input type="hidden" id="chatSubmissionId" value="">
                            <input type="file" id="chatFileInput" accept="image/jpeg,image/png,image/gif,image/webp,.pdf,.doc,.docx" style="display: none;">
                            
                            <div class="chat-input-wrapper flex-grow-1 position-relative">
                                <button type="button" class="chat-file-btn" id="chatFileBtn" title="Attach file">
                                    <i class="bi bi-paperclip"></i>
                                </button>
                                <input type="text" 
                                    id="chatMessageInput" 
                                    class="form-control chat-input-with-file" 
                                    placeholder="Type your message..." 
                                    autocomplete="off">
                            </div>
                            
                            <button type="submit" class="btn btn-primary" id="chatSendBtn">
                                <i class="bi bi-send"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- File/Image Lightbox -->
    <div class="file-lightbox" id="fileLightbox">
        <div class="lightbox-content">
            <button class="lightbox-close" onclick="closeLightbox()">
                <i class="bi bi-x-lg"></i>
            </button>
            <div id="lightboxContent"></div>
        </div>
    </div>
    <script>
        // Updated Chat functionality with file support
        let chatSubmissionId = null;
        let chatPollInterval = null;
        let lastMessageId = 0;
        let selectedFile = null;

        function openChat(submissionId, chapterNumber) {
            chatSubmissionId = submissionId;
            document.getElementById('chatSubmissionId').value = submissionId;
            document.getElementById('chatTitle').textContent = 'Chapter ' + chapterNumber + ' Discussion';
            
            const chatModal = new bootstrap.Modal(document.getElementById('chatModal'));
            chatModal.show();
            
            loadChatMessages();
            
            if (chatPollInterval) {
                clearInterval(chatPollInterval);
            }
            chatPollInterval = setInterval(loadChatMessages, 3000);
        }

        function loadChatMessages() {
            if (!chatSubmissionId) return;
            
            fetch(`chapter_messages_handler.php?action=get_messages&submission_id=${chatSubmissionId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayChatMessages(data.messages, data.current_user_id);
                    }
                })
                .catch(error => console.error('Error loading messages:', error));
        }

        function displayChatMessages(messages, currentUserId) {
            const container = document.getElementById('chatMessages');
            const wasScrolledToBottom = container.scrollHeight - container.scrollTop <= container.clientHeight + 100;
            
            // Check if we need to update - only if message count changed or new messages
            const existingMessages = container.querySelectorAll('.chat-message');
            let needsUpdate = false;
            
            // Check if we have new messages
            messages.forEach(msg => {
                if (msg.message_id > lastMessageId) {
                    lastMessageId = msg.message_id;
                    needsUpdate = true;
                }
            });
            
            // If no new messages and count is same, don't update
            if (!needsUpdate && existingMessages.length === messages.length) {
                return;
            }
            
            let html = '';
            let lastDate = '';
            
            messages.forEach(msg => {
                const msgDate = new Date(msg.created_at).toLocaleDateString();
                if (msgDate !== lastDate) {
                    lastDate = msgDate;
                    const dateLabel = isToday(new Date(msg.created_at)) ? 'Today' : 
                                    isYesterday(new Date(msg.created_at)) ? 'Yesterday' : msgDate;
                    html += `<div class="chat-date-separator"><span>${dateLabel}</span></div>`;
                }
                
                const isOwnMessage = msg.user_id == currentUserId;
                const avatarClass = msg.role === 'adviser' ? 'adviser' : 'student';
                const initials = msg.sender_name.split(' ').map(n => n[0]).join('').toUpperCase();
                
                html += `
                    <div class="chat-message ${isOwnMessage ? 'own-message' : ''}" data-message-id="${msg.message_id}">
                        <div class="chat-avatar ${avatarClass}">
                            ${msg.profile_picture ? 
                                `<img src="../uploads/profile_pictures/${msg.profile_picture}" alt="">` : 
                                initials}
                        </div>
                        <div class="chat-message-content">
                            ${!isOwnMessage ? `<div class="chat-sender">${msg.sender_name}</div>` : ''}
                            <div class="chat-bubble">
                                ${renderMessageContent(msg)}
                            </div>
                            <div class="chat-time">${formatChatTime(msg.created_at)}</div>
                        </div>
                    </div>
                `;
            });
            
            if (messages.length === 0) {
                html = '<div class="text-center text-muted py-5"><i class="bi bi-chat-dots" style="font-size: 3rem; opacity: 0.3;"></i><p class="mt-3">No messages yet. Start the conversation!</p></div>';
            }
            
            container.innerHTML = html;
            
            if (wasScrolledToBottom) {
                container.scrollTop = container.scrollHeight;
            }
        }

        function renderMessageContent(msg) {
            let content = '';
            
            if (msg.message_type === 'image' && msg.file_path) {
                content += `<div class="chat-file-container chat-image-container" onclick="openLightbox('../${msg.file_path}', 'image')">
                    <img src="../${msg.file_path}" alt="Shared image" loading="lazy">
                </div>`;
            } else if (msg.message_type === 'file' && msg.file_path) {
                const ext = msg.original_filename.split('.').pop().toLowerCase();
                const iconClass = ext === 'pdf' ? 'pdf' : 'word';
                const icon = ext === 'pdf' ? 'bi-file-earmark-pdf' : 'bi-file-earmark-word';
                
                content += `<div class="chat-file-container chat-doc-container" onclick="openLightbox('../${msg.file_path}', 'document', '${msg.original_filename}')">
                    <div class="doc-icon ${iconClass}">
                        <i class="bi ${icon}"></i>
                    </div>
                    <div class="doc-info">
                        <div class="doc-name">${escapeHtml(msg.original_filename)}</div>
                        <div class="doc-size">${getFileExtension(msg.original_filename).toUpperCase()} Document</div>
                    </div>
                    <i class="bi bi-download"></i>
                </div>`;
            }
            
            if (msg.message_text && !['Sent an image', 'Sent a document'].includes(msg.message_text)) {
                content += `<div class="chat-text">${escapeHtml(msg.message_text)}</div>`;
            }
            
            return content;
        }

        function sendChatMessage(event) {
            event.preventDefault();
            
            const input = document.getElementById('chatMessageInput');
            const message = input.value.trim();
            const sendBtn = document.getElementById('chatSendBtn');
            
            if (!message && !selectedFile) {
                return;
            }
            
            sendBtn.disabled = true;
            sendBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            
            const formData = new FormData();
            formData.append('action', 'send_message');
            formData.append('submission_id', chatSubmissionId);
            formData.append('message', message);
            
            if (selectedFile) {
                formData.append('file', selectedFile);
            }
            
            fetch('chapter_messages_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    input.value = '';
                    clearFilePreview();
                    loadChatMessages();
                } else {
                    alert('Failed to send message: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to send message');
            })
            .finally(() => {
                sendBtn.disabled = false;
                sendBtn.innerHTML = '<i class="bi bi-send"></i>';
                input.focus();
            });
        }

        // File handling
        document.getElementById('chatFileBtn').addEventListener('click', function() {
            document.getElementById('chatFileInput').click();
        });

        document.getElementById('chatFileInput').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 
                                    'application/pdf', 'application/msword', 
                                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                const allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx'];
                const fileExtension = file.name.split('.').pop().toLowerCase();
                
                if (!allowedTypes.includes(file.type) && !allowedExtensions.includes(fileExtension)) {
                    alert('Please select a valid file (Images: JPEG, PNG, GIF, WebP | Documents: PDF, DOC, DOCX)');
                    return;
                }
                
                // Check file size
                const isImage = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(file.type) || 
                            ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(fileExtension);
                const maxSize = isImage ? 5 * 1024 * 1024 : 10 * 1024 * 1024; // 5MB for images, 10MB for docs
                
                if (file.size > maxSize) {
                    alert(`File size too large. Maximum size is ${isImage ? '5MB' : '10MB'}.`);
                    return;
                }
                
                selectedFile = file;
                showFilePreview(file);
            }
        });

        function showFilePreview(file) {
            const container = document.getElementById('filePreviewContainer');
            container.style.display = 'block';
            
            const fileExtension = file.name.split('.').pop().toLowerCase();
            const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(fileExtension);
            
            if (isImage) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    container.innerHTML = `
                        <div class="chat-file-preview-container">
                            <img src="${e.target.result}" class="chat-file-preview" alt="Preview">
                            <button type="button" class="remove-file-btn" onclick="clearFilePreview()">
                                <i class="bi bi-x"></i>
                            </button>
                        </div>
                    `;
                };
                reader.readAsDataURL(file);
            } else {
                const iconClass = fileExtension === 'pdf' ? 'pdf' : 'word';
                const icon = fileExtension === 'pdf' ? 'bi-file-earmark-pdf' : 'bi-file-earmark-word';
                container.innerHTML = `
                    <div class="chat-file-preview-container">
                        <div class="chat-doc-preview">
                            <div class="doc-icon ${iconClass}">
                                <i class="bi ${icon}"></i>
                            </div>
                            <div class="doc-info">
                                <div class="doc-name">${escapeHtml(file.name)}</div>
                                <div class="doc-size">${formatFileSize(file.size)}</div>
                            </div>
                            <button type="button" class="remove-file-btn" onclick="clearFilePreview()" style="position: static; margin-left: auto;">
                                <i class="bi bi-x"></i>
                            </button>
                        </div>
                    </div>
                `;
            }
        }

        function clearFilePreview() {
            selectedFile = null;
            document.getElementById('chatFileInput').value = '';
            document.getElementById('filePreviewContainer').style.display = 'none';
            document.getElementById('filePreviewContainer').innerHTML = '';
        }

        function openLightbox(filePath, type, filename) {
            const lightbox = document.getElementById('fileLightbox');
            const content = document.getElementById('lightboxContent');
            
            if (type === 'image') {
                content.innerHTML = `<img src="${filePath}" alt="Full size image">`;
            } else {
                // For PDF and DOC files
                const ext = filename.split('.').pop().toLowerCase();
                if (ext === 'pdf') {
                    content.innerHTML = `<iframe src="${filePath}#view=FitH&toolbar=1"></iframe>`;
                } else {
                    // For DOC/DOCX, offer download
                    content.innerHTML = `
                        <div style="background: white; padding: 3rem; border-radius: 12px; text-align: center;">
                            <i class="bi bi-file-earmark-word text-primary" style="font-size: 4rem;"></i>
                            <h5 class="mt-3 mb-3">${escapeHtml(filename)}</h5>
                            <p class="text-muted mb-4">Word documents cannot be previewed in browser</p>
                            <a href="${filePath}" download class="btn btn-primary">
                                <i class="bi bi-download me-2"></i>Download Document
                            </a>
                        </div>
                    `;
                }
            }
            
            lightbox.classList.add('active');
        }

        function closeLightbox() {
            const lightbox = document.getElementById('fileLightbox');
            lightbox.classList.remove('active');
            document.getElementById('lightboxContent').innerHTML = '';
        }

        // Utility functions
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML.replace(/\n/g, '<br>');
        }

        function formatChatTime(dateString) {
            const date = new Date(dateString);
            return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
        }

        function formatFileSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        }

        function getFileExtension(filename) {
            return filename.split('.').pop();
        }

        function isToday(date) {
            const today = new Date();
            return date.toDateString() === today.toDateString();
        }

        function isYesterday(date) {
            const yesterday = new Date();
            yesterday.setDate(yesterday.getDate() - 1);
            return date.toDateString() === yesterday.toDateString();
        }

        // Event listeners
        document.getElementById('chatForm').addEventListener('submit', sendChatMessage);

        document.getElementById('chatModal').addEventListener('hidden.bs.modal', function() {
            if (chatPollInterval) {
                clearInterval(chatPollInterval);
                chatPollInterval = null;
            }
            chatSubmissionId = null;
            lastMessageId = 0;
            clearFilePreview();
        });

        // Close lightbox on background click
        document.getElementById('fileLightbox').addEventListener('click', function(e) {
            if (e.target === this) {
                closeLightbox();
            }
        });

        // Close lightbox on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeLightbox();
            }
        });
    </script>

    <style>
    /* Chat Message Styles */
    .chat-message {
        margin-bottom: 1rem;
        display: flex;
        gap: 0.75rem;
        animation: fadeIn 0.3s ease;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .chat-message.own-message {
        flex-direction: row-reverse;
    }

    .chat-avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--university-blue), #1d4ed8);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 0.875rem;
        flex-shrink: 0;
        overflow: hidden;
    }

    .chat-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .chat-avatar.adviser {
        background: linear-gradient(135deg, var(--success-green), #047857);
    }

    .chat-message-content {
        max-width: 70%;
    }

    .chat-message.own-message .chat-message-content {
        text-align: right;
    }

    .chat-bubble {
        background: white;
        border-radius: 12px;
        padding: 0.75rem 1rem;
        box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        display: inline-block;
        text-align: left;
    }

    .chat-message.own-message .chat-bubble {
        background: var(--university-blue);
        color: white;
    }

    .chat-sender {
        font-size: 0.75rem;
        font-weight: 600;
        margin-bottom: 0.25rem;
        color: var(--text-secondary);
    }

    .chat-message.own-message .chat-sender {
        color: var(--university-blue);
    }

    .chat-text {
        font-size: 0.9rem;
        line-height: 1.4;
        word-wrap: break-word;
    }

    .chat-time {
        font-size: 0.7rem;
        color: var(--text-secondary);
        margin-top: 0.25rem;
    }

    .chat-message.own-message .chat-time {
        color: var(--university-blue);
    }

    .chat-date-separator {
        text-align: center;
        margin: 1.5rem 0;
        position: relative;
    }

    .chat-date-separator span {
        background: #f8f9fa;
        padding: 0.25rem 1rem;
        border-radius: 20px;
        font-size: 0.75rem;
        color: var(--text-secondary);
        font-weight: 500;
        display: inline-block;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }

    /* File attachment styles */
    .chat-file-container {
        max-width: 300px;
        margin-top: 0.5rem;
        border-radius: 8px;
        overflow: hidden;
        transition: transform 0.2s ease;
    }

    .chat-image-container {
        cursor: pointer;
    }

    .chat-image-container:hover {
        transform: scale(1.02);
    }

    .chat-image-container img {
        width: 100%;
        height: auto;
        display: block;
        border-radius: 8px;
    }

    .chat-doc-container {
        background: rgba(0,0,0,0.05);
        padding: 0.75rem;
        border-radius: 8px;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        cursor: pointer;
        transition: background 0.2s ease;
    }

    .chat-doc-container:hover {
        background: rgba(0,0,0,0.1);
    }

    .chat-message.own-message .chat-doc-container {
        background: rgba(255,255,255,0.2);
    }

    .chat-message.own-message .chat-doc-container:hover {
        background: rgba(255,255,255,0.3);
    }

    .doc-icon {
        width: 40px;
        height: 40px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        flex-shrink: 0;
    }

    .doc-icon.pdf {
        background: #dc2626;
        color: white;
    }

    .doc-icon.word {
        background: #2563eb;
        color: white;
    }

    .doc-info {
        flex-grow: 1;
        min-width: 0;
    }

    .doc-name {
        font-weight: 600;
        font-size: 0.875rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .doc-size {
        font-size: 0.75rem;
        opacity: 0.7;
    }

    /* File preview styles */
    .chat-file-preview-container {
        position: relative;
        display: inline-block;
        margin-bottom: 0.5rem;
    }

    .chat-file-preview {
        max-width: 100px;
        max-height: 100px;
        border-radius: 8px;
        border: 2px solid var(--border-light);
    }

    .chat-doc-preview {
        background: #f3f4f6;
        padding: 0.75rem;
        border-radius: 8px;
        border: 2px solid var(--border-light);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .remove-file-btn {
        position: absolute;
        top: -8px;
        right: -8px;
        background: var(--danger-red);
        color: white;
        border: none;
        border-radius: 50%;
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 0.75rem;
        padding: 0;
        line-height: 1;
    }

    .remove-file-btn:hover {
        background: #b91c1c;
    }

    .chat-input-wrapper {
        position: relative;
    }

    .chat-file-btn {
        position: absolute;
        left: 0.5rem;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        color: var(--text-secondary);
        font-size: 1.25rem;
        cursor: pointer;
        padding: 0.25rem;
        border-radius: 4px;
        transition: all 0.2s ease;
    }

    .chat-file-btn:hover {
        color: var(--university-blue);
        background: rgba(30, 64, 175, 0.1);
    }

    .chat-input-with-file {
        padding-left: 2.5rem !important;
    }

    /* Lightbox styles */
    .file-lightbox {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.9);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        padding: 2rem;
    }

    .file-lightbox.active {
        display: flex;
    }

    .lightbox-content {
        max-width: 90%;
        max-height: 90%;
        position: relative;
    }

    .lightbox-content img {
        max-width: 100%;
        max-height: 90vh;
        border-radius: 8px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
    }

    .lightbox-content iframe {
        width: 90vw;
        height: 90vh;
        border: none;
        border-radius: 8px;
        background: white;
    }

    .lightbox-close {
        position: absolute;
        top: -40px;
        right: 0;
        background: white;
        color: var(--text-primary);
        border: none;
        border-radius: 50%;
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 1.25rem;
    }

    .lightbox-close:hover {
        background: var(--light-gray);
    }

    #chatMessages::-webkit-scrollbar {
        width: 8px;
    }

    #chatMessages::-webkit-scrollbar-track {
        background: #f1f1f1;
    }

    #chatMessages::-webkit-scrollbar-thumb {
        background: #888;
        border-radius: 4px;
    }

    #chatMessages::-webkit-scrollbar-thumb:hover {
        background: #555;
    }
    </style>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function openPDFModal(filePath, fileName) {
            const modal = new bootstrap.Modal(document.getElementById('pdfModal'), {
                backdrop: 'static',
                keyboard: false
            });
            
            const iframe = document.getElementById('pdfViewer');
            const pdfObject = document.getElementById('pdfObject');
            const fallback = document.getElementById('pdfFallback');
            const fileNameElement = document.getElementById('pdfFileName');
            const downloadBtn = document.getElementById('downloadPdfBtn');
            const newTabBtn = document.getElementById('openNewTabBtn');
            const fallbackDownload = document.getElementById('fallbackDownload');
            const fallbackNewTab = document.getElementById('fallbackNewTab');
            
            const fullPath = '../' + filePath;
            
            // Set filename
            fileNameElement.textContent = fileName;
            
            // Reset all elements
            iframe.style.display = 'block';
            pdfObject.style.display = 'none';
            fallback.style.display = 'none';
            
            // Detect if we're likely to have PDF support
            const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
            const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
            const hasNativePDFSupport = 'plugins' in navigator && navigator.plugins['Chrome PDF Plugin'];
            
            // For better mobile experience, especially iOS, try Google Docs Viewer or direct embedding
            let pdfLoadAttempts = 0;
            const maxAttempts = 3;
            
            function tryLoadPDF() {
                pdfLoadAttempts++;
                
                if (pdfLoadAttempts === 1) {
                    // First attempt: Direct PDF with viewer parameters
                    let pdfUrl = fullPath;
                    
                    // Add viewer parameters for better display
                    if (!isMobile) {
                        pdfUrl += '#toolbar=1&navpanes=1&scrollbar=1&page=1&view=FitH&zoom=page-width';
                    } else {
                        pdfUrl += '#page=1&zoom=page-fit';
                    }
                    
                    iframe.src = pdfUrl;
                    
                    // Give more time for mobile devices to load
                    const loadTimeout = isMobile ? 4000 : 2000;
                    
                    iframe.onload = function() {
                        setTimeout(() => {
                            try {
                                if (iframe.contentDocument && iframe.contentDocument.body.innerHTML.includes('does not support')) {
                                    console.log('PDF plugin not available, trying alternative method');
                                    tryLoadPDF();
                                }
                            } catch (e) {
                                // Cross-origin - PDF likely loaded successfully
                                console.log('PDF loaded (cross-origin detected)');
                            }
                        }, loadTimeout);
                    };
                    
                    iframe.onerror = function() {
                        console.log('Iframe failed, trying object tag');
                        tryLoadPDF();
                    };
                    
                    // Fallback timer for mobile
                    setTimeout(() => {
                        if (pdfLoadAttempts === 1 && isMobile) {
                            // On mobile, if no success after timeout, try next method
                            tryLoadPDF();
                        }
                    }, loadTimeout);
                    
                } else if (pdfLoadAttempts === 2) {
                    // Second attempt: Use object tag
                    iframe.style.display = 'none';
                    pdfObject.style.display = 'block';
                    pdfObject.data = fullPath;
                    pdfObject.type = 'application/pdf';
                    
                    setTimeout(() => {
                        // Check if object loaded content
                        if (pdfObject.offsetHeight < 100) {
                            console.log('Object tag failed, trying Google Docs viewer');
                            tryLoadPDF();
                        }
                    }, 3000);
                    
                } else if (pdfLoadAttempts === 3) {
                    // Third attempt: Google Docs Viewer (works well on mobile)
                    iframe.style.display = 'block';
                    pdfObject.style.display = 'none';
                    
                    const encodedUrl = encodeURIComponent(window.location.origin + '/' + fullPath);
                    const googleViewerUrl = `https://docs.google.com/viewer?url=${encodedUrl}&embedded=true`;
                    
                    iframe.src = googleViewerUrl;
                    
                    setTimeout(() => {
                        // If Google Viewer doesn't work, show fallback
                        try {
                            if (iframe.contentWindow.location.href.includes('error')) {
                                showFallback();
                            }
                        } catch (e) {
                            // If we can't check, assume it worked (cross-origin)
                            console.log('Google Docs Viewer loaded (cross-origin)');
                        }
                    }, 4000);
                    
                } else {
                    // All attempts failed, show fallback
                    showFallback();
                }
            }
            
            function showFallback() {
                iframe.style.display = 'none';
                pdfObject.style.display = 'none';
                fallback.style.display = 'flex';
                console.log('Showing PDF fallback options');
                
                // Update fallback message based on device
                const fallbackTitle = fallback.querySelector('h4');
                const fallbackText = fallback.querySelector('p');
                
                if (isMobile) {
                    fallbackTitle.textContent = 'Mobile PDF Viewer';
                    fallbackText.textContent = 'For the best viewing experience on mobile, please use one of these options:';
                } else {
                    fallbackTitle.textContent = 'PDF Viewer Options';
                    fallbackText.textContent = 'Choose how you\'d like to view this document:';
                }
            }
            
            // Start loading attempts
            tryLoadPDF();
            
            // Set up button handlers
            function handleDownload() {
                const link = document.createElement('a');
                link.href = fullPath;
                link.download = fileName;
                link.style.display = 'none';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
            
            function handleNewTab() {
                window.open(fullPath, '_blank', 'noopener,noreferrer');
            }
            
            // Attach event handlers
            downloadBtn.onclick = handleDownload;
            newTabBtn.onclick = handleNewTab;
            fallbackDownload.onclick = handleDownload;
            fallbackNewTab.onclick = handleNewTab;
            
            // Show modal
            modal.show();
            
            // Clean up when modal closes
            const modalElement = document.getElementById('pdfModal');
            modalElement.addEventListener('hidden.bs.modal', function() {
                iframe.src = '';
                pdfObject.data = '';
                iframe.style.display = 'block';
                pdfObject.style.display = 'none';
                fallback.style.display = 'none';
                pdfLoadAttempts = 0;
            }, { once: true });
        }

        // Notification system functionality (Updated to match my_groups.php)
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
        document.addEventListener('DOMContentLoaded', function() {
            const radioButtons = document.querySelectorAll('input[name="decision"]');
            radioButtons.forEach(function(radio) {
                radio.addEventListener('change', function() {
                    document.querySelectorAll('.form-check').forEach(function(check) {
                        check.classList.remove('border-success', 'bg-light', 'border-danger');
                    });
                    
                    const parent = this.closest('.form-check');
                    if (this.value === 'approve') {
                        parent.classList.add('border-success', 'bg-light');
                    } else {
                        parent.classList.add('border-danger', 'bg-light');
                    }
                });
            });

            // Initialize notification polling and bell animation
            const bellButton = document.getElementById('notificationBell');
            if (bellButton && <?php echo $total_unviewed; ?> > 0) {
                bellButton.style.animation = 'shake 0.5s ease-in-out';
            }
            
            setInterval(updateNotificationCount, 30000);

            // Handle responsive navigation scrolling
            const navContainer = document.querySelector('.nav-tabs');
            const activeLink = document.querySelector('.nav-link.active');
            
            if (navContainer && activeLink && window.innerWidth <= 768) {
                activeLink.scrollIntoView({
                    behavior: 'smooth',
                    block: 'nearest',
                    inline: 'center'
                });
            }
        });
    </script>
</body>
</html>