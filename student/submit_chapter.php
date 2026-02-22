<?php
session_start();
include_once '../config/database.php';
include_once '../includes/functions.php';

// Check if user is logged in and is a student
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

// Handle AJAX notification requests - simplified for normalized database
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

// Get chapter number from query string (1-5 only)
$chapter_number = isset($_GET['chapter']) ? intval($_GET['chapter']) : 1;
if ($chapter_number < 1 || $chapter_number > 5) {
    $chapter_number = 1;
}

// Check if user is part of a group
$stmt = $conn->prepare("SELECT * FROM research_groups WHERE lead_student_id = ?");
$stmt->execute([$user_id]);
$group = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$group) {
    header("Location: create_group.php?error=need_group");
    exit();
}

// FIXED: Get latest approved research title from submissions table
$stmt = $conn->prepare("
    SELECT * FROM submissions 
    WHERE group_id = ? AND submission_type = 'title' AND status = 'approved' 
    ORDER BY approval_date DESC 
    LIMIT 1
");
$stmt->execute([$group['group_id']]);
$title = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$title) {
    header("Location: submit_title.php?error=need_approved_title");
    exit();
}

// FIXED: Check chapter progression - previous chapter must be approved
$can_access_chapter = true;
$progression_error = '';

if ($chapter_number > 1) {
    // Check if previous chapter exists and is approved
    $stmt = $conn->prepare("
        SELECT * FROM submissions 
        WHERE group_id = ? AND submission_type = 'chapter' AND chapter_number = ?
    ");
    $stmt->execute([$group['group_id'], $chapter_number - 1]);
    $previous_chapter = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$previous_chapter) {
        $can_access_chapter = false;
        $progression_error = "You must submit Chapter " . ($chapter_number - 1) . " first.";
    } elseif ($previous_chapter['status'] !== 'approved') {
        $can_access_chapter = false;
        $progression_error = "Chapter " . ($chapter_number - 1) . " must be approved before you can access Chapter " . $chapter_number . ".";
    }
}

// If cannot access this chapter, redirect to the appropriate chapter
if (!$can_access_chapter) {
    // Find the highest accessible chapter
    $accessible_chapter = 1;
    for ($i = 1; $i < $chapter_number; $i++) {
        $stmt = $conn->prepare("
            SELECT status FROM submissions 
            WHERE group_id = ? AND submission_type = 'chapter' AND chapter_number = ?
        ");
        $stmt->execute([$group['group_id'], $i]);
        $check_chapter = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$check_chapter) {
            // This chapter doesn't exist, so this is where they should be
            $accessible_chapter = $i;
            break;
        } elseif ($check_chapter['status'] === 'rejected') {
            // This chapter was rejected, so they need to resubmit it
            $accessible_chapter = $i;
            break;
        } elseif ($check_chapter['status'] === 'approved') {
            // This chapter is approved, can continue to next
            $accessible_chapter = $i + 1;
            continue;
        } else {
            // This chapter is pending, they must wait
            $accessible_chapter = $i;
            break;
        }
    }
    
    header("Location: submit_chapter.php?chapter=" . $accessible_chapter . "&error=progression");
    exit();
}

// FIXED: Check if current chapter exists in submissions table
$stmt = $conn->prepare("
    SELECT * FROM submissions 
    WHERE group_id = ? AND submission_type = 'chapter' AND chapter_number = ?
");
$stmt->execute([$group['group_id'], $chapter_number]);
$current_chapter = $stmt->fetch(PDO::FETCH_ASSOC);

// Get adviser info
$adviser = null;
if ($group['adviser_id']) {
    $stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
    $stmt->execute([$group['adviser_id']]);
    $adviser = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Check if this chapter can be submitted/resubmitted
$can_submit = true;
$submit_message = '';

if ($current_chapter) {
    if ($current_chapter['status'] === 'approved') {
        $can_submit = false;
        $submit_message = 'This chapter has been approved.';
    } elseif ($current_chapter['status'] === 'pending') {
        $can_submit = false;
        $submit_message = 'This chapter is currently under review.';
    } elseif ($current_chapter['status'] === 'rejected') {
        $can_submit = true;
        $submit_message = 'This chapter needs revision. Please submit an updated version.';
    }
} else {
    $can_submit = true;
    $submit_message = 'Ready to submit Chapter ' . $chapter_number . '.';
}

// Process chapter submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_submit && !isset($_POST['action'])) {
    if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK && !empty($_FILES['document']['tmp_name'])) {
        // Create upload directory if it doesn't exist
        $upload_dir = '../uploads/chapters/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        // Validate file type
        $allowed_types = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $file_type = $_FILES['document']['type'];
        $file_info = pathinfo($_FILES['document']['name']);
        $file_extension = strtolower($file_info['extension']);

        if (!in_array($file_type, $allowed_types) && !in_array($file_extension, ['pdf', 'doc', 'docx'])) {
            $error = "Invalid file type. Please upload PDF, DOC, or DOCX files only.";
        } elseif ($_FILES['document']['size'] > 10 * 1024 * 1024) { // 10MB limit
            $error = "File size too large. Maximum size is 10MB.";
        } else {
            // Generate unique filename
            $filename = 'chapter_' . $chapter_number . '_' . $group['group_id'] . '_' . time() . '.' . $file_extension;
            $target_path = $upload_dir . $filename;
            $relative_path = 'uploads/chapters/' . $filename;

            if (move_uploaded_file($_FILES['document']['tmp_name'], $target_path)) {
                try {
                    $conn->beginTransaction();

                    if ($current_chapter) {
                        // Update existing chapter (resubmission)
                        $sql = "UPDATE submissions SET document_path = ?, status = 'pending', created_at = NOW() WHERE submission_id = ?";
                        $stmt = $conn->prepare($sql);
                        $stmt->execute([$relative_path, $current_chapter['submission_id']]);
                        $submission_id = $current_chapter['submission_id'];
                    } else {
                        // FIXED: Insert new chapter into submissions table
                        $chapter_title = "Chapter " . $chapter_number;
                        $sql = "INSERT INTO submissions (group_id, submission_type, title, chapter_number, document_path, status, created_at) VALUES (?, 'chapter', ?, ?, ?, 'pending', NOW())";
                        $stmt = $conn->prepare($sql);
                        $stmt->execute([$group['group_id'], $chapter_title, $chapter_number, $relative_path]);
                        $submission_id = $conn->lastInsertId();
                    }

                    // Add submission message to messages table
                    $message_text = "Chapter $chapter_number " . ($current_chapter ? "resubmitted" : "submitted") . " for review.";
                    if (!empty($_POST['notes'])) {
                        $message_text .= "\n\nNotes: " . $_POST['notes'];
                    }

                    $stmt = $conn->prepare("
                        INSERT INTO messages (context_type, context_id, user_id, message_type, message_text, file_path, original_filename) 
                        VALUES ('submission', ?, ?, 'file', ?, ?, ?)
                    ");
                    $stmt->execute([$submission_id, $user_id, $message_text, $relative_path, $_FILES['document']['name']]);

                    // Create notification for adviser
                    if ($group['adviser_id']) {
                        $notification_title = $current_chapter ? 'Chapter Resubmitted' : 'New Chapter Submitted';
                        $notification_message = 'Chapter ' . $chapter_number . ' has been ' . ($current_chapter ? 'resubmitted' : 'submitted') . ' by ' . $_SESSION['full_name'];
                        
                        $stmt = $conn->prepare("
                            INSERT INTO notifications (user_id, title, message, type, context_type, context_id) 
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $group['adviser_id'],
                            $notification_title,
                            $notification_message,
                            'chapter_submission',
                            'submission',
                            $submission_id
                        ]);
                    }

                    $conn->commit();
                    $success = "Chapter $chapter_number " . ($current_chapter ? "resubmitted" : "submitted") . " successfully!";

                    // Refresh page to show new submission
                    header("Location: submit_chapter.php?chapter=$chapter_number&success=submitted");
                    exit();
                } catch (Exception $e) {
                    $conn->rollBack();
                    $error = "Database error: " . $e->getMessage();
                }
            } else {
                $error = "Failed to upload file. Please try again.";
            }
        }
    } else {
        $error = "Please select a file to upload.";
    }
}

// FIXED: Get all chapter messages for this chapter from messages table
$messages = [];
if ($current_chapter) {
    $stmt = $conn->prepare("
        SELECT m.*, CONCAT(u.first_name, ' ', u.last_name) as sender_name, u.role
        FROM messages m
        JOIN users u ON m.user_id = u.user_id
        WHERE m.context_type = 'submission' AND m.context_id = ?
        ORDER BY m.created_at ASC
    ");
    $stmt->execute([$current_chapter['submission_id']]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// FIXED: Get chapter statuses for navigation from submissions table
$chapter_statuses = [];
for ($i = 1; $i <= 5; $i++) {
    $stmt = $conn->prepare("
        SELECT status FROM submissions 
        WHERE group_id = ? AND submission_type = 'chapter' AND chapter_number = ?
    ");
    $stmt->execute([$group['group_id'], $i]);
    $chapter = $stmt->fetch(PDO::FETCH_ASSOC);
    $chapter_statuses[$i] = $chapter ? $chapter['status'] : null;
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
        created_at
    FROM notifications 
    WHERE user_id = ? AND is_read = 0
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute([$user_id]);
$recent_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total_unviewed = count($recent_notifications);

// Chapter info array
$chapter_info = [
    1 => [
        'title' => 'Introduction',
        'description' => 'Present your research background, problem statement, and objectives'
    ],
    2 => [
        'title' => 'Literature Review',
        'description' => 'Comprehensive review of related studies and theoretical framework'
    ],
    3 => [
        'title' => 'Methodology',
        'description' => 'Detailed research methodology and design'
    ],
    4 => [
        'title' => 'Results and Discussion',
        'description' => 'Present your findings and analyze the results'
    ],
    5 => [
        'title' => 'Summary, Conclusions and Recommendations',
        'description' => 'Summarize findings, draw conclusions, and provide recommendations'
    ]
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpg" href="../assets/images/essu logo.png">
    <title>Submit Chapter <?php echo $chapter_number; ?> - ESSU Research System</title>
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

        .university-brand:hover {
            color: white !important;
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

        .notification-icon.title { background: rgba(30, 64, 175, 0.1); color: var(--university-blue); }
        .notification-icon.chapter { background: rgba(5, 150, 105, 0.1); color: var(--success-green); }

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

        .chapter-nav {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        @media (min-width: 768px) {
            .chapter-nav {
                grid-template-columns: repeat(5, 1fr);
            }
        }

        .chapter-nav-btn {
            position: relative;
            padding: 0.75rem;
            border-radius: 8px;
            text-decoration: none;
            text-align: center;
            font-weight: 500;
            transition: all 0.3s ease;
            border: 2px solid var(--border-light);
        }

        .chapter-nav-btn.current {
            background: var(--university-blue);
            color: white;
            border-color: var(--university-blue);
        }

        .chapter-nav-btn.approved {
            background: var(--success-green);
            color: white;
            border-color: var(--success-green);
        }

        .chapter-nav-btn.pending {
            background: var(--warning-orange);
            color: white;
            border-color: var(--warning-orange);
        }

        .chapter-nav-btn.rejected {
            background: var(--danger-red);
            color: white;
            border-color: var(--danger-red);
        }

        .chapter-nav-btn.disabled {
            background: #f3f4f6;
            color: #9ca3af;
            border-color: #e5e7eb;
            cursor: not-allowed;
        }

        .status-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-approved {
            background: #d1fae5;
            color: #065f46;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .discussion-message {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            border-left: 4px solid var(--border-light);
        }

        .discussion-message.from-adviser {
            border-left-color: var(--success-green);
            background: #f0fdf4;
        }

        .discussion-message.from-student {
            border-left-color: var(--university-blue);
            background: #eff6ff;
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

        .dropdown-item {
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }

        .dropdown-item:hover {
            background-color: rgba(30, 64, 175, 0.05);
        }

        /* Chat Styles */
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
            flex: 1;
            min-width: 0;
            max-width: calc(100% - 42px);
            display: flex;
            flex-direction: column;
        }
        .chat-messages-container {
            overflow-y: auto;
            overflow-x: hidden;
            background: #f8f9fa;
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
            
            .chapter-nav {
                grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            }
            
            .chapter-nav-btn {
                padding: 0.6rem;
                font-size: 0.85rem;
            }
            
            .chat-message-content {
                max-width: 80%;
            }
        }


        /* RESPONSIVE - MOBILE (576px and below) */
        @media (max-width: 576px) {
            .user-info {
                display: none;
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
            
            /* Enhanced Navigation Tabs - Same as Submit Title and Dashboard */
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
            
            .notification-section {
                gap: 0.5rem;
            }
            
            .profile-avatar-large {
                width: 100px;
                height: 100px;
                font-size: 2rem;
                border: 3px solid var(--university-gold);
            }
            
            .profile-picture-section {
                padding: 1rem;
                margin-bottom: 1rem;
            }
            
            .profile-picture-section h4 {
                font-size: 1.1rem;
            }
            
            .profile-picture-section .badge {
                font-size: 0.8rem !important;
            }
            
            .section-header {
                padding: 0.75rem 1rem;
                font-size: 0.85rem;
            }
            
            .section-body {
                padding: 1rem;
            }
            
            .form-label {
                font-size: 0.9rem;
                margin-bottom: 0.5rem;
            }
            
            .form-control, .form-select {
                font-size: 0.9rem;
                padding: 0.6rem;
            }
            
            .btn {
                font-size: 0.9rem;
                padding: 0.6rem 1rem;
            }
            
            .btn-sm {
                font-size: 0.8rem;
                padding: 0.4rem 0.8rem;
            }
            
            .card {
                margin-bottom: 0.75rem;
            }
            
            .card-body {
                padding: 0.75rem !important;
            }
            
            .member-avatar {
                width: 40px !important;
                height: 40px !important;
                margin-right: 0.5rem !important;
            }
            
            .card-body h6 {
                font-size: 0.95rem;
            }
            
            .card-body .badge {
                font-size: 0.65rem !important;
            }
            
            .card-body .small {
                font-size: 0.8rem !important;
            }
            
            .toggle-password {
                right: 10px;
                font-size: 1.1rem;
            }
            
            .password-toggle input {
                padding-right: 40px;
            }
            
            .alert {
                font-size: 0.85rem;
                padding: 0.75rem;
            }
            
            .stat-card {
                padding: 0.75rem;
                margin-bottom: 0.5rem;
            }
            
            .stat-card h3 {
                font-size: 1.5rem;
            }
            
            .stat-card p {
                font-size: 0.85rem;
            }
            
            .file-input-wrapper {
                margin-bottom: 0.5rem;
            }
            
            .row.mb-3 {
                margin-bottom: 1rem !important;
            }
            
            .d-md-flex.justify-content-md-end {
                display: flex !important;
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .d-md-flex.justify-content-md-end .btn {
                width: 100%;
            }
            
            .alert .row {
                row-gap: 0.75rem;
            }
            
            .alert .col-md-4 {
                font-size: 0.85rem;
            }
        }
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
            flex-shrink: 0;
            align-items: center;
        }

        .pdf-modal .modal-title {
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            margin-bottom: 0;
        }

        .pdf-modal .modal-title i {
            margin-right: 0.5rem;
            font-size: 1.25rem;
        }

        .pdf-modal .modal-header .header-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .pdf-modal .modal-header .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.875rem;
            border-radius: 6px;
            display: flex;
            align-items: center;
            gap: 0.375rem;
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

        /* TABLET (768px and below) */
        @media (max-width: 768px) {
            .pdf-modal .modal-dialog {
                max-width: 95vw;
                width: 95vw;
                height: 90vh;
                margin: 1rem auto;
            }
            
            .pdf-modal .modal-content {
                border-radius: 8px;
            }
            
            .pdf-modal .modal-header {
                padding: 0.875rem 1rem;
            }
            
            .pdf-modal .modal-title {
                font-size: 1rem;
            }
            
            .pdf-modal .modal-title i {
                font-size: 1.1rem;
            }
            
            .pdf-modal .modal-header .btn-sm {
                font-size: 0.8rem;
                padding: 0.35rem 0.65rem;
            }
            
            .pdf-modal .modal-body {
                height: calc(90vh - 65px);
            }
        }

        /* MOBILE (576px and below) */
        @media (max-width: 576px) {
            .pdf-modal .modal-dialog {
                max-width: 100vw;
                width: 100vw;
                height: 100vh;
                margin: 0;
            }
            
            .pdf-modal .modal-content {
                border-radius: 0;
                height: 100vh;
            }
            
            .pdf-modal .modal-header {
                padding: 0.75rem 1rem;
                flex-wrap: wrap;
            }
            
            .pdf-modal .modal-title {
                font-size: 0.9rem;
                flex: 1 1 100%;
                margin-bottom: 0.5rem;
            }
            
            .pdf-modal .modal-title i {
                font-size: 1rem;
                margin-right: 0.375rem;
            }
            
            .pdf-modal .modal-header .header-actions {
                flex: 1 1 100%;
                width: 100%;
                gap: 0.5rem;
            }
            
            .pdf-modal .modal-header .btn-sm {
                flex: 1;
                font-size: 0.75rem;
                padding: 0.375rem 0.5rem;
                justify-content: center;
            }
            
            .pdf-modal .modal-header .btn-sm .btn-text {
                display: none;
            }
            
            .pdf-modal .modal-header .btn-sm i {
                margin: 0;
                font-size: 0.9rem;
            }
            
            .pdf-modal .modal-header .btn-close {
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
        }

        /* EXTRA SMALL (400px and below) */
        @media (max-width: 400px) {
            .pdf-modal .modal-title {
                font-size: 0.85rem;
            }
            
            .pdf-modal .modal-header .btn-sm {
                font-size: 0.7rem;
                padding: 0.3rem 0.4rem;
            }
            
            .pdf-modal .modal-header .btn-sm i {
                font-size: 0.85rem;
            }
        }

        /* LANDSCAPE MODE */
        @media (max-width: 768px) and (orientation: landscape) {
            .pdf-modal .modal-dialog {
                height: 100vh;
                margin: 0 auto;
            }
            
            .pdf-modal .modal-content {
                height: 100vh;
            }
            
            .pdf-modal .modal-header {
                padding: 0.5rem 1rem;
            }
            
            .pdf-modal .modal-title {
                font-size: 0.85rem;
            }
            
            .pdf-modal .modal-body {
                height: calc(100vh - 55px);
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
              <div class="nav-container">
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
                        <a class="nav-link active" href="submit_chapter.php">
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
                </ul>                      
              </div>                      
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Chapter <?php echo $chapter_number; ?>: <?php echo $chapter_info[$chapter_number]['title']; ?></h1>
            <p class="page-subtitle"><?php echo $chapter_info[$chapter_number]['description']; ?></p>
        </div>

        <!-- Success/Error Messages -->
        <?php if (isset($_GET['success']) && $_GET['success'] == 'submitted'): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i>
                Chapter <?php echo $chapter_number; ?> submitted successfully!
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error']) && $_GET['error'] == 'progression'): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle me-2"></i>
                You must complete chapters in order. Please complete the current chapter before proceeding.
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

        <!-- Research Title Info -->
        <div class="dashboard-section">
            <div class="section-header">
                <i class="bi bi-info-circle me-2"></i>Research Information
            </div>
            <div class="section-body">
                <h6 class="text-primary"><?php echo htmlspecialchars($title['title']); ?></h6>
                <p class="mb-1"><strong>Adviser:</strong> <?php echo $adviser ? $adviser['first_name'] . ' ' . $adviser['last_name'] : 'Not assigned'; ?></p>
                <p class="mb-0"><strong>Group:</strong> <?php echo htmlspecialchars($group['group_name']); ?></p>
            </div>
        </div>

        <!-- Chapter Navigation with Status -->
        <div class="dashboard-section">
            <div class="section-header">
                <i class="bi bi-list-ol me-2"></i>Chapter Progress
            </div>
            <div class="section-body">
                <div class="chapter-nav">
                    <?php for ($i = 1; $i <= 5; $i++): 
                        $status = $chapter_statuses[$i];
                        $is_current = ($i == $chapter_number);
                        $is_accessible = true;
                        
                        // Check if chapter is accessible
                        if ($i > 1) {
                            $prev_status = $chapter_statuses[$i - 1];
                            if (!$prev_status || $prev_status !== 'approved') {
                                $is_accessible = false;
                            }
                        }
                        
                        $btn_class = 'chapter-nav-btn ';
                        if ($is_current) {
                            $btn_class .= 'current';
                        } elseif (!$is_accessible) {
                            $btn_class .= 'disabled';
                        } elseif ($status == 'approved') {
                            $btn_class .= 'approved';
                        } elseif ($status == 'pending') {
                            $btn_class .= 'pending';
                        } elseif ($status == 'rejected') {
                            $btn_class .= 'rejected';
                        } else {
                            $btn_class .= 'btn-outline-secondary';
                        }
                    ?>
                        <?php if ($is_accessible): ?>
                            <a href="submit_chapter.php?chapter=<?php echo $i; ?>" class="<?php echo $btn_class; ?>">
                        <?php else: ?>
                            <div class="<?php echo $btn_class; ?>">
                        <?php endif; ?>
                            <i class="bi bi-<?php echo $i; ?>-circle me-1"></i>
                            Chapter <?php echo $i; ?>
                            <?php if ($status): ?>
                                <br><small><?php echo ucfirst($status); ?></small>
                            <?php endif; ?>
                        <?php if ($is_accessible): ?>
                            </a>
                        <?php else: ?>
                            </div>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
            </div>
        </div>

        <!-- Current Chapter Status -->
        <div class="dashboard-section">
            <div class="section-header">
                <i class="bi bi-flag me-2"></i>Chapter <?php echo $chapter_number; ?> Status
            </div>
            <div class="section-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    <?php echo $submit_message; ?>
                </div>
                
                <?php if ($current_chapter): ?>
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6>Current Status</h6>
                            <span class="status-badge status-<?php echo $current_chapter['status'] == 'approved' ? 'approved' : ($current_chapter['status'] == 'rejected' ? 'rejected' : 'pending'); ?>">
                                <?php echo $current_chapter['status'] == 'approved' ? 'Approved' : ($current_chapter['status'] == 'rejected' ? 'Needs Revision' : 'Under Review'); ?>
                            </span>
                        </div>
                        <div class="text-end">
                            <small class="text-muted">Submitted</small><br>
                            <strong><?php echo date('M d, Y', strtotime($current_chapter['created_at'])); ?></strong>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Chapter Submission Form -->
        <?php if ($can_submit): ?>
            <div class="dashboard-section">
                <div class="section-header">
                    <i class="bi bi-upload me-2"></i>
                    <?php echo $current_chapter && $current_chapter['status'] == 'rejected' ? 'Resubmit' : 'Submit'; ?> 
                    Chapter <?php echo $chapter_number; ?>
                </div>
                <div class="section-body">
                    <?php if ($current_chapter && $current_chapter['status'] == 'rejected'): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <strong>Revision Required:</strong> Your chapter needs revision. Please check the feedback below and resubmit.
                        </div>
                    <?php endif; ?>

                    <form method="post" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label for="document" class="form-label">
                                <i class="bi bi-file-earmark-text me-1"></i>Chapter Document <span class="text-danger">*</span>
                            </label>
                            <input type="file" class="form-control" name="document" id="document" accept=".pdf,.doc,.docx" required>
                            <div class="form-text">Supports PDF, DOC, DOCX files (Max: 10MB)</div>
                        </div>

                        <div class="mb-3">
                            <label for="notes" class="form-label">
                                <i class="bi bi-chat-text me-1"></i>Notes (Optional)
                            </label>
                            <textarea class="form-control" id="notes" name="notes" rows="3" 
                                      placeholder="Any notes for your adviser..."></textarea>
                        </div>

                        <div class="d-flex gap-2 justify-content-end">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="bi bi-upload me-2"></i>
                                <?php echo $current_chapter ? 'Resubmit Chapter' : 'Submit Chapter'; ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Chapter Document Viewer -->
        <?php if ($current_chapter && $current_chapter['document_path']): ?>
            <div class="dashboard-section">
                <div class="section-header">
                    <i class="bi bi-file-earmark me-2"></i>Submitted Chapter Document
                </div>
                <div class="section-body">
                    <?php
                    $full_path = '../' . $current_chapter['document_path'];
                    if (file_exists($full_path)) {
                        $file_extension = strtolower(pathinfo($current_chapter['document_path'], PATHINFO_EXTENSION));
                        $filename = basename($current_chapter['document_path']);
                        
                        if ($file_extension === 'pdf') {
                            // PDF: Show preview button and download option
                            echo '<div class="d-flex align-items-center gap-3 flex-wrap">
                                    <div class="d-flex align-items-center gap-2 flex-grow-1">
                                        <i class="bi bi-file-earmark-pdf text-danger" style="font-size: 2rem;"></i>
                                        <div>
                                            <strong>' . htmlspecialchars($filename) . '</strong><br>
                                            <small class="text-muted">PDF Document</small>
                                        </div>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#pdfModal" data-pdf-url="../' . htmlspecialchars($current_chapter['document_path']) . '">
                                            <i class="bi bi-eye me-1"></i>Preview
                                        </button>
                                        <a href="../' . htmlspecialchars($current_chapter['document_path']) . '" target="_blank" class="btn btn-outline-primary btn-sm">
                                            <i class="bi bi-download me-1"></i>Download
                                        </a>
                                    </div>
                                  </div>';
                        } else {
                            // DOCX/DOC: Show download only
                            echo '<div class="d-flex align-items-center gap-2">
                                    <i class="bi bi-file-earmark-text text-primary" style="font-size: 2rem;"></i>
                                    <div>
                                        <strong>' . htmlspecialchars($filename) . '</strong><br>
                                        <small class="text-muted">' . strtoupper($file_extension) . ' Document</small>
                                    </div>
                                    <a href="../' . htmlspecialchars($current_chapter['document_path']) . '" target="_blank" class="btn btn-outline-primary btn-sm ms-auto">
                                        <i class="bi bi-download me-1"></i>Download
                                    </a>
                                  </div>';
                        }
                    } else {
                        echo '<div class="alert alert-warning">
                                <i class="bi bi-file-x"></i> Chapter document file not found on server
                              </div>';
                    }
                    ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Discussion Thread -->
        <?php if ($current_chapter && count($messages) > 0): ?>
            <div class="dashboard-section">
                <div class="section-header">
                    <i class="bi bi-chat-square-text me-2"></i>Discussion with Adviser
                </div>
                <div class="section-body">
                    <div style="max-height: 600px; overflow-y: auto;">
                        <?php foreach ($messages as $msg): ?>
                            <div class="discussion-message <?php echo $msg['role'] == 'student' ? 'from-student' : 'from-adviser'; ?>">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="author-avatar" style="width: 32px; height: 32px; font-size: 0.875rem;">
                                            <?php echo strtoupper(substr($msg['sender_name'], 0, 2)); ?>
                                        </div>
                                        <div>
                                            <strong class="<?php echo $msg['role'] == 'student' ? 'text-primary' : 'text-success'; ?>">
                                                <?php echo htmlspecialchars($msg['sender_name']); ?>
                                            </strong>
                                            <span class="badge bg-<?php echo $msg['role'] == 'student' ? 'primary' : 'success'; ?> ms-2">
                                                <?php echo ucfirst($msg['role']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <small class="text-muted"><?php echo date('M d, Y g:i A', strtotime($msg['created_at'])); ?></small>
                                </div>
                                
                                <?php if ($msg['message_text']): ?>
                                    <p class="mb-2"><?php echo nl2br(htmlspecialchars($msg['message_text'])); ?></p>
                                <?php endif; ?>
                                
                                <?php if ($msg['file_path']): ?>
                                    <div class="bg-light p-2 rounded">
                                        <div class="d-flex align-items-center">
                                            <i class="bi bi-file-earmark-pdf text-danger me-2"></i>
                                            <span class="me-auto"><?php echo htmlspecialchars($msg['original_filename']); ?></span>
                                            <a href="../<?php echo $msg['file_path']; ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                                                <i class="bi bi-download"></i>
                                            </a>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Next Steps -->
        <?php if ($current_chapter && $current_chapter['status'] == 'approved' && $chapter_number < 5): ?>
            <div class="dashboard-section">
                <div class="section-header">
                    <i class="bi bi-arrow-right me-2"></i>Next Steps
                </div>
                <div class="section-body text-center">
                    <i class="bi bi-check-circle text-success" style="font-size: 3rem;"></i>
                    <h5 class="mt-3">Chapter <?php echo $chapter_number; ?> Approved!</h5>
                    <p class="text-muted mb-3">You can now proceed to the next chapter.</p>
                    <a href="submit_chapter.php?chapter=<?php echo $chapter_number + 1; ?>" class="btn btn-primary">
                        <i class="bi bi-arrow-right me-1"></i>Continue to Chapter <?php echo $chapter_number + 1; ?>
                    </a>
                </div>
            </div>
        <?php elseif ($current_chapter && $current_chapter['status'] == 'approved' && $chapter_number == 5): ?>
            <div class="dashboard-section">
                <div class="section-header">
                    <i class="bi bi-trophy me-2"></i>Thesis Complete
                </div>
                <div class="section-body text-center">
                    <i class="bi bi-trophy text-warning" style="font-size: 3rem;"></i>
                    <h5 class="mt-3">Congratulations!</h5>
                    <p class="text-muted mb-3">You have successfully completed all thesis chapters.</p>
                    <a href="dashboard.php" class="btn btn-success">
                        <i class="bi bi-house me-1"></i>Return to Dashboard
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <!-- Quick Message Section -->
        <?php if ($current_chapter && $adviser): ?>
            <div class="dashboard-section">
                <div class="section-header">
                    <i class="bi bi-chat-square-dots me-2"></i>Quick Message
                </div>
                <div class="section-body text-center">
                    <p class="text-muted mb-3">Have questions or need clarification? Send a message to your adviser.</p>
                    <button type="button" class="btn btn-primary" onclick="openChat(<?php echo $current_chapter['submission_id']; ?>, <?php echo $chapter_number; ?>)">
                        <i class="bi bi-chat-dots me-2"></i>Open Chat with <?php echo htmlspecialchars($adviser['first_name'] . ' ' . $adviser['last_name']); ?>
                    </button>
                </div>
            </div>
        <?php endif; ?>

    <!-- PDF Modal -->
    <div class="modal fade pdf-modal" id="pdfModal" tabindex="-1" aria-labelledby="pdfModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="pdfModalLabel">
                        <i class="bi bi-file-earmark-pdf"></i>
                        <span id="pdfModalTitle">Document Preview</span>
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
                <div class="modal-body">
                    <div class="pdf-loading">
                        <div class="spinner-border text-light" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading PDF...</p>
                    </div>
                    <iframe id="pdfModalFrame" style="display: none;"></iframe>
                </div>
            </div>
        </div>
    </div>
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
    <div class="file-lightbox" id="fileLightbox">
        <div class="lightbox-content">
            <button class="lightbox-close" onclick="closeLightbox()">
                <i class="bi bi-x-lg"></i>
            </button>
            <div id="lightboxContent"></div>
        </div>
    </div>

    <style>
    .chat-message {
        margin-bottom: 0.875rem;
        display: flex;
        gap: 0.5rem;
        animation: fadeIn 0.3s ease;
        align-items: flex-start;
        max-width: 100%;
        overflow: hidden;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .chat-message.own-message {
        flex-direction: row-reverse;
        justify-content: flex-start;
    }
    /* Chat Modal Responsive */
    @media (max-width: 768px) {
        #chatModal .modal-dialog {
            margin: 0;
            max-width: 100%;
        }
        
        #chatModal .modal-content {
            height: 100vh;
            border-radius: 0;
        }
        
        #chatModal .modal-body {
            height: calc(100vh - 60px);
        }
        
        .chat-message-content {
            max-width: 75%;
        }
    }
    
    @media (max-width: 576px) {
        #chatModal .modal-header {
            padding: 0.75rem 1rem;
        }
        
        #chatModal .modal-title {
            font-size: 0.95rem;
        }
        
        .chat-message {
            margin-bottom: 0.75rem;
        }
        
        .chat-avatar {
            width: 32px;
            height: 32px;
            font-size: 0.8rem;
        }
        
        .chat-bubble {
            padding: 0.6rem 0.85rem;
            font-size: 0.875rem;
        }
        
        .chat-message-content {
            max-width: 80%;
        }
        
        .chat-file-container {
            max-width: 250px;
        }
        
        #chatMessages {
            padding: 0.75rem;
        }
        
        #chatForm {
            padding: 0.75rem;
        }
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
    
    /* Lightbox styles */
    .file-lightbox {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.95);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        padding: 1rem;
    }

    .file-lightbox.active {
        display: flex;
    }

    .lightbox-content {
        max-width: 90%;
        max-height: 90%;
        position: relative;
    }
    
    @media (max-width: 576px) {
        .lightbox-content {
            max-width: 95%;
            max-height: 95%;
        }
    }

    .lightbox-content img {
        max-width: 100%;
        max-height: 85vh;
        border-radius: 8px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
    }

    .lightbox-content iframe {
        width: 90vw;
        height: 85vh;
        border: none;
        border-radius: 8px;
        background: white;
    }
    
    @media (max-width: 576px) {
        .lightbox-content iframe {
            width: 95vw;
            height: 80vh;
        }
    }

    .lightbox-close {
        position: absolute;
        top: -45px;
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
        font-size: 1.125rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    }
    
    @media (max-width: 576px) {
        .lightbox-close {
            top: -40px;
            width: 32px;
            height: 32px;
            font-size: 1rem;
        }
    }

    .lightbox-close:hover {
        background: var(--light-gray);
    }

    #chatMessages::-webkit-scrollbar {
        width: 6px;
    }
    
    @media (max-width: 576px) {
        #chatMessages::-webkit-scrollbar {
            width: 4px;
        }
    }
    </style>
    

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Form submission with loading state
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form[method="post"]');
            if (form) {
                form.addEventListener('submit', function() {
                    const submitBtn = this.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Uploading...';
                    }
                });
            }

            // Initialize notification polling
            setInterval(updateNotificationCount, 30000);

            // PDF Modal functionality - Responsive
            const pdfModal = document.getElementById('pdfModal');
            const pdfModalFrame = document.getElementById('pdfModalFrame');
            const pdfModalTitle = document.getElementById('pdfModalTitle');
            const pdfDownloadBtn = document.getElementById('pdfDownloadBtn');
            const pdfOpenBtn = document.getElementById('pdfOpenBtn');
            const pdfLoading = document.querySelector('.pdf-loading');

            if (pdfModal) {
                // When modal is about to show
                pdfModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    const pdfUrl = button.getAttribute('data-pdf-url') || button.getAttribute('data-bs-pdf-url');
                    
                    if (pdfUrl) {
                        const filename = pdfUrl.split('/').pop();
                        
                        // Update modal content
                        pdfModalTitle.textContent = filename;
                        pdfDownloadBtn.href = pdfUrl;
                        pdfDownloadBtn.download = filename;
                        pdfOpenBtn.href = pdfUrl;
                        
                        // Show loading state
                        pdfLoading.style.display = 'block';
                        pdfModalFrame.style.display = 'none';
                        
                        // Load PDF
                        pdfModalFrame.src = pdfUrl + '#view=FitH&toolbar=1&navpanes=0&scrollbar=1';
                    }
                });

                // When iframe loads
                pdfModalFrame.addEventListener('load', function() {
                    pdfLoading.style.display = 'none';
                    pdfModalFrame.style.display = 'block';
                });

                // Clear iframe when modal closes
                pdfModal.addEventListener('hidden.bs.modal', function () {
                    pdfModalFrame.src = '';
                    pdfModalFrame.style.display = 'none';
                    pdfLoading.style.display = 'block';
                });
            }
        });

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

        document.addEventListener('DOMContentLoaded', function() {
            const navContainer = document.querySelector('.nav-container');
            const activeLink = document.querySelector('.nav-link.active');
            const bellButton = document.getElementById('notificationBell');
            const notificationCount = <?php echo $total_unviewed; ?>;

            if (bellButton && notificationCount > 0) {
                bellButton.style.animation = 'shake 0.5s ease-in-out';
            }
            if (navContainer && activeLink && window.innerWidth <= 768) {
                activeLink.scrollIntoView({
                    behavior: 'smooth',
                    block: 'nearest',
                    inline: 'center'
                });
            }
        });
    </script>
    <script>
        let chatSubmissionId = null;
        let chatPollInterval = null;
        let lastMessageId = 0;
        let selectedFile = null;

        function openChat(submissionId, chapterNumber) {
            chatSubmissionId = submissionId;
            lastMessageId = 0; // Reset lastMessageId when opening chat
            document.getElementById('chatSubmissionId').value = submissionId;
            document.getElementById('chatTitle').textContent = 'Chapter ' + chapterNumber + ' Discussion';
            
            // Clear existing messages to force fresh load
            const container = document.getElementById('chatMessages');
            container.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>';
            
            const chatModal = new bootstrap.Modal(document.getElementById('chatModal'));
            chatModal.show();
            
            loadChatMessages();
            
            if (chatPollInterval) {
                clearInterval(chatPollInterval);
            }
            chatPollInterval = setInterval(loadChatMessages, 5000); // Changed to 5 seconds to reduce flicker
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
            
            // Check if this is the initial load
            const existingMessages = container.querySelectorAll('.chat-message');
            const isInitialLoad = existingMessages.length === 0;
            
            if (isInitialLoad) {
                // Initial load - render all messages
                renderAllMessages(messages, currentUserId, container);
            } else {
                // Incremental update - only add new messages
                const newMessages = messages.filter(msg => msg.message_id > lastMessageId);
                
                if (newMessages.length > 0) {
                    appendNewMessages(newMessages, currentUserId, container);
                    newMessages.forEach(msg => {
                        if (msg.message_id > lastMessageId) {
                            lastMessageId = msg.message_id;
                        }
                    });
                }
            }
            
            if (wasScrolledToBottom) {
                container.scrollTop = container.scrollHeight;
            }
        }

        function renderAllMessages(messages, currentUserId, container) {
            let html = '';
            let lastDate = '';
            
            messages.forEach(msg => {
                if (msg.message_id > lastMessageId) {
                    lastMessageId = msg.message_id;
                }
                
                const msgDate = new Date(msg.created_at).toLocaleDateString();
                if (msgDate !== lastDate) {
                    lastDate = msgDate;
                    const dateLabel = isToday(new Date(msg.created_at)) ? 'Today' : 
                                    isYesterday(new Date(msg.created_at)) ? 'Yesterday' : msgDate;
                    html += `<div class="chat-date-separator"><span>${dateLabel}</span></div>`;
                }
                
                html += createMessageHTML(msg, currentUserId);
            });
            
            if (messages.length === 0) {
                html = '<div class="text-center text-muted py-5"><i class="bi bi-chat-dots" style="font-size: 3rem; opacity: 0.3;"></i><p class="mt-3">No messages yet. Start the conversation!</p></div>';
            }
            
            container.innerHTML = html;
        }

        function appendNewMessages(newMessages, currentUserId, container) {
            const lastExistingMessage = container.querySelector('.chat-message:last-child');
            let lastDate = lastExistingMessage ? 
                new Date(lastExistingMessage.dataset.createdAt || '').toLocaleDateString() : '';
            
            newMessages.forEach(msg => {
                const msgDate = new Date(msg.created_at).toLocaleDateString();
                
                // Add date separator if date changed
                if (msgDate !== lastDate) {
                    lastDate = msgDate;
                    const dateLabel = isToday(new Date(msg.created_at)) ? 'Today' : 
                                    isYesterday(new Date(msg.created_at)) ? 'Yesterday' : msgDate;
                    const dateSeparator = document.createElement('div');
                    dateSeparator.className = 'chat-date-separator';
                    dateSeparator.innerHTML = `<span>${dateLabel}</span>`;
                    container.appendChild(dateSeparator);
                }
                
                // Create and append new message
                const messageDiv = document.createElement('div');
                messageDiv.innerHTML = createMessageHTML(msg, currentUserId);
                container.appendChild(messageDiv.firstElementChild);
            });
        }

        function createMessageHTML(msg, currentUserId) {
            const isOwnMessage = msg.user_id == currentUserId;
            const avatarClass = msg.role === 'adviser' ? 'adviser' : 'student';
            const initials = msg.sender_name.split(' ').map(n => n[0]).join('').toUpperCase();
            
            return `
                <div class="chat-message ${isOwnMessage ? 'own-message' : ''}" data-message-id="${msg.message_id}" data-created-at="${msg.created_at}">
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
            lastMessageId = 0; // Reset lastMessageId
            clearFilePreview();
            
            // Clear messages container
            const container = document.getElementById('chatMessages');
            container.innerHTML = '';
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
    </script>
</body>
</html>