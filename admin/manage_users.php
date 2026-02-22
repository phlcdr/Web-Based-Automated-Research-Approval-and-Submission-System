<?php
session_start();

include_once '../config/database.php';
include_once '../includes/submission_functions.php';  
include_once '../includes/functions.php';

// Check if user is logged in and is an admin
is_logged_in();
check_role(['admin']);

// College-Department mapping
$college_departments = [
    'College of Engineering' => [
        'Computer Engineering',
        'Civil Engineering', 
        'Electrical Engineering'
    ],
    'College of Nursing and Allied Sciences' => [
        'Nursing',
        'Midwifery',
        'Nutrition and Dietetics'
    ],
    'College of Computer Studies' => [
        'Information Technology',
        'Computer Science',
        'Entertainment and Multimedia Computing',
        'Associate in Computer Technology'
    ],
    'College of Education' => [
        'Secondary Education',
        'Elementary Education'
    ],
    'College of Business and Management' => [
        'Business Administration',
        'Hospitality Management',
        'Tourism Management',
        'Accountancy',
        'Entrepreneurship'
    ],
    'College of Arts and Sciences' => [
        'Biology',
        'Political Science'
    ],
    'College of Agriculture and Fisheries' => [
        'Agriculture',
        'Fisheries'
    ]
];
$success = '';
$error = '';
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
            $stmt->execute([$notification_id, $_SESSION['user_id']]);
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
    $stmt->execute([$_SESSION['user_id']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode(['count' => $result['count']]);
    exit;
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
$stmt->execute([$_SESSION['user_id']]);
$recent_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total_unviewed = count($recent_notifications);

// Handle AJAX request to get user data for editing
if (isset($_GET['get_user']) && isset($_GET['user_id'])) {
    $user_id = intval($_GET['user_id']);
    $stmt = $conn->prepare("SELECT user_id, username, email, first_name, last_name, role, college, department, is_active FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo json_encode(['success' => true, 'user' => $user]);
    } else {
        echo json_encode(['success' => false, 'error' => 'User not found']);
    }
    exit;
}

// Handle AJAX request to update user
if (isset($_POST['ajax_update_user'])) {
    $user_id = intval($_POST['user_id']);
    $username = validate_input($_POST['username']);
    $email = validate_input($_POST['email']);
    $first_name = validate_input($_POST['first_name']);
    $last_name = validate_input($_POST['last_name']);
    $role = validate_input($_POST['role']);
    $college = validate_input($_POST['college']);
    $department = validate_input($_POST['department']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Validate required fields
    if (empty($username) || empty($email) || empty($first_name) || empty($last_name) || empty($role)) {
        echo json_encode(['success' => false, 'error' => 'All required fields must be filled out']);
        exit;
    }
    
    // Validate college-department relationship
    if (!empty($college) && !empty($department)) {
        if (!isset($college_departments[$college]) || !in_array($department, $college_departments[$college])) {
            echo json_encode(['success' => false, 'error' => 'Selected department does not belong to the selected college']);
            exit;
        }
    }
    
    // Check if username already exists (excluding current user)
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ?");
    $stmt->execute([$username, $user_id]);
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => false, 'error' => 'Username already exists']);
        exit;
    }
    
    // Check if email already exists (excluding current user)
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
    $stmt->execute([$email, $user_id]);
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => false, 'error' => 'Email already exists']);
        exit;
    }
    
    // Handle password update
    $password_update = '';
    $password_params = [];
    if (!empty($_POST['password'])) {
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        
        if ($password !== $confirm_password) {
            echo json_encode(['success' => false, 'error' => 'Passwords do not match']);
            exit;
        } elseif (strlen($password) < 6) {
            echo json_encode(['success' => false, 'error' => 'Password must be at least 6 characters long']);
            exit;
        }
        
        $password_update = ', password = ?';
        $password_params[] = password_hash($password, PASSWORD_DEFAULT);
    }
    
    // Update user
    $sql = "UPDATE users SET 
            username = ?, 
            email = ?, 
            first_name = ?, 
            last_name = ?, 
            role = ?, 
            college = ?, 
            department = ?,
            is_active = ?
            $password_update
            WHERE user_id = ?";
    
    $params = array_merge(
        [$username, $email, $first_name, $last_name, $role, $college, $department, $is_active],
        $password_params,
        [$user_id]
    );
    
    $stmt = $conn->prepare($sql);
    
    if ($stmt->execute($params)) {
        echo json_encode(['success' => true, 'message' => 'User updated successfully!']);
    } else {
        echo json_encode(['success' => false, 'error' => 'An error occurred during user update']);
    }
    exit;
}
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


// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_user'])) {
        $user_id = intval($_POST['user_id']);
        $stmt = $conn->prepare("UPDATE users SET registration_status = 'approved' WHERE user_id = ? AND registration_status = 'pending'");
        if ($stmt->execute([$user_id])) {
            $success = "User registration approved successfully!";
        } else {
            $error = "Failed to approve registration.";
        }
    } elseif (isset($_POST['reject_user'])) {
        $user_id = intval($_POST['user_id']);
        
        try {
            // Get user details before deletion for feedback message
            $stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ? AND registration_status = 'pending'");
            $stmt->execute([$user_id]);
            $user_to_reject = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user_to_reject) {
                $conn->beginTransaction();
                
                try {
                    // Delete all related data first
                    $stmt = $conn->prepare("DELETE FROM group_memberships WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    
                    $stmt = $conn->prepare("DELETE FROM notifications WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    
                    $stmt = $conn->prepare("UPDATE research_groups SET lead_student_id = NULL WHERE lead_student_id = ?");
                    $stmt->execute([$user_id]);
                    
                    $stmt = $conn->prepare("UPDATE research_groups SET adviser_id = NULL WHERE adviser_id = ?");
                    $stmt->execute([$user_id]);
                    
                    $stmt = $conn->prepare("DELETE FROM reviews WHERE reviewer_id = ?");
                    $stmt->execute([$user_id]);
                    
                    $stmt = $conn->prepare("DELETE FROM assignments WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    
                    $stmt = $conn->prepare("DELETE FROM messages WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    
                    
                    $stmt = $conn->prepare("DELETE FROM login_attempts WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    
                    // Finally delete the user
                    $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    
                    $conn->commit();
                    
                    $user_name = htmlspecialchars($user_to_reject['first_name'] . ' ' . $user_to_reject['last_name']);
                    $success = "User registration for '$user_name' has been rejected and the account has been permanently deleted.";
                    
                } catch (PDOException $e) {
                    $conn->rollback();
                    $error = "Failed to reject and delete user: " . $e->getMessage();
                }
            } else {
                $error = "User not found or already processed.";
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    } elseif (isset($_POST['toggle_active_status'])) {
        $user_id = intval($_POST['user_id']);
        $new_status = intval($_POST['new_status']);
        
        if ($user_id == $_SESSION['user_id']) {
            $error = "You cannot deactivate your own account";
        } else {
            $action = $new_status ? 'activated' : 'deactivated';
            $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE user_id = ?");
            if ($stmt->execute([$new_status, $user_id])) {
                $success = "User account has been $action successfully!";
            } else {
                $error = "Failed to update account status.";
            }
        }
    }
}

// Handle user deletion (for approved users) - FIXED VERSION
if (isset($_GET['delete']) && isset($_GET['user_id'])) {
    $delete_user_id = intval($_GET['user_id']);
    
    if ($delete_user_id == $_SESSION['user_id']) {
        $error = "You cannot delete your own account";
    } else {
        try {
            // Get user details before deletion
            $stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
            $stmt->execute([$delete_user_id]);
            $user_to_delete = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user_to_delete) {
                // CRITICAL FIX: Check and close any existing transaction
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                
                // Start fresh transaction
                $conn->beginTransaction();
                
                try {
                    // ========== NEW: Get all research groups where user is involved ==========
                    $stmt = $conn->prepare("
                        SELECT DISTINCT rg.group_id 
                        FROM research_groups rg
                        LEFT JOIN group_memberships gm ON rg.group_id = gm.group_id
                        WHERE rg.lead_student_id = ? 
                           OR rg.adviser_id = ? 
                           OR gm.user_id = ?
                    ");
                    $stmt->execute([$delete_user_id, $delete_user_id, $delete_user_id]);
                    $user_groups = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    // If user has research groups, delete all related data
                    if (!empty($user_groups)) {
                        $group_ids_placeholder = implode(',', array_fill(0, count($user_groups), '?'));
                        
                        // 1. Delete thesis_discussions for those groups
                        $stmt = $conn->prepare("DELETE FROM thesis_discussions WHERE group_id IN ($group_ids_placeholder)");
                        $stmt->execute($user_groups);
                        
                        // 2. Delete submissions for those groups
                        $stmt = $conn->prepare("DELETE FROM submissions WHERE group_id IN ($group_ids_placeholder)");
                        $stmt->execute($user_groups);
                        
                        // 3. Delete group_memberships for those groups
                        $stmt = $conn->prepare("DELETE FROM group_memberships WHERE group_id IN ($group_ids_placeholder)");
                        $stmt->execute($user_groups);
                        
                        // 4. Delete the research_groups themselves
                        $stmt = $conn->prepare("DELETE FROM research_groups WHERE group_id IN ($group_ids_placeholder)");
                        $stmt->execute($user_groups);
                    }
                    // ========== END NEW CODE ==========
                    
                    // Update settings to remove user references
                    $stmt = $conn->prepare("UPDATE settings SET created_by = NULL WHERE created_by = ?");
                    $stmt->execute([$delete_user_id]);
                    
                    $stmt = $conn->prepare("UPDATE settings SET updated_by = NULL WHERE updated_by = ?");
                    $stmt->execute([$delete_user_id]);
                    
                    // Delete remaining group_memberships (if any)
                    $stmt = $conn->prepare("DELETE FROM group_memberships WHERE user_id = ?");
                    $stmt->execute([$delete_user_id]);
                    
                    // Delete from notifications
                    $stmt = $conn->prepare("DELETE FROM notifications WHERE user_id = ?");
                    $stmt->execute([$delete_user_id]);
                    
                    // Delete from reviews
                    $stmt = $conn->prepare("DELETE FROM reviews WHERE reviewer_id = ?");
                    $stmt->execute([$delete_user_id]);
                    
                    // Delete from assignments
                    $stmt = $conn->prepare("DELETE FROM assignments WHERE user_id = ?");
                    $stmt->execute([$delete_user_id]);
                    
                    // Delete from messages
                    $stmt = $conn->prepare("DELETE FROM messages WHERE user_id = ?");
                    $stmt->execute([$delete_user_id]);
                    
                    // Delete from login_attempts
                    $stmt = $conn->prepare("DELETE FROM login_attempts WHERE user_id = ?");
                    $stmt->execute([$delete_user_id]);
                    
                    // Finally delete the user
                    $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
                    $stmt->execute([$delete_user_id]);
                    
                    // Commit the transaction
                    $conn->commit();
                    
                    $success = "User '" . htmlspecialchars($user_to_delete['first_name'] . ' ' . $user_to_delete['last_name']) . "' has been permanently deleted along with all their research data.";
                    header("Location: manage_users.php?success=" . urlencode($success));
                    exit();
                    
                } catch (PDOException $e) {
                    if ($conn->inTransaction()) {
                        $conn->rollBack();
                    }
                    $error = "Failed to delete user: " . $e->getMessage();
                }
            } else {
                $error = "User not found";
            }
        } catch (PDOException $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Get filter parameters
$role_filter = isset($_GET['role']) ? $_GET['role'] : 'all';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$active_filter = isset($_GET['active']) ? $_GET['active'] : 'all';
$search = isset($_GET['search']) ? validate_input($_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Get statistics
$stats = ['total_users' => 0, 'pending' => 0, 'active' => 0, 'inactive' => 0, 'students' => 0, 'advisers' => 0, 'panel' => 0, 'admins' => 0];

try {
    $stats_sql = "SELECT 
                    COUNT(*) as total_users,
                    SUM(CASE WHEN registration_status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive,
                    SUM(CASE WHEN role = 'student' THEN 1 ELSE 0 END) as students,
                    SUM(CASE WHEN role = 'adviser' THEN 1 ELSE 0 END) as advisers,
                    SUM(CASE WHEN role = 'panel' THEN 1 ELSE 0 END) as panel,
                    SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admins
                  FROM users";
    $stats_stmt = $conn->query($stats_sql);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error retrieving statistics: " . $e->getMessage();
}

// Build WHERE conditions for filtering
$where_conditions = [];
$params = [];

if ($role_filter !== 'all') {
    $where_conditions[] = "role = ?";
    $params[] = $role_filter;
}

if ($status_filter !== 'all') {
    $where_conditions[] = "registration_status = ?";
    $params[] = $status_filter;
}

if ($active_filter !== 'all') {
    $where_conditions[] = "is_active = ?";
    $params[] = intval($active_filter);
}

if (!empty($search)) {
    $where_conditions[] = "(first_name LIKE ? OR last_name LIKE ? OR username LIKE ? OR email LIKE ? OR student_id LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count for pagination
$count_sql = "SELECT COUNT(*) FROM users $where_clause";
$count_stmt = $conn->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $limit);

// Get users with pagination
$users_sql = "SELECT * FROM users 
             $where_clause
             ORDER BY 
                 CASE 
                     WHEN registration_status = 'pending' THEN 1 
                     WHEN registration_status = 'approved' THEN 2 
                     WHEN registration_status = 'rejected' THEN 3 
                     ELSE 4 
                 END,
                 is_active DESC,
                 created_at DESC
             LIMIT $limit OFFSET $offset";
$users_stmt = $conn->prepare($users_sql);
$users_stmt->execute($params);
$users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpg" href="../assets/images/essu logo.png">
    <title>Manage Users - Research Approval System</title>
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

        .modal-dialog-scrollable .modal-body {
            max-height: calc(100vh - 200px);
            overflow-y: auto;
        }

        .modal-dialog-scrollable .modal-content {
            max-height: calc(100vh - 60px);
        }

        .password-toggle {
            position: relative;
        }

        .toggle-password {
            position: absolute;
            top: 50%;
            right: 15px;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--text-secondary);
            z-index: 10;
        }

        .toggle-password:hover {
            color: var(--university-blue);
        }

        .password-toggle input {
            padding-right: 45px;
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
        .academic-card.gold { border-left-color: var(--university-gold); }

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
        .stat-icon.gold { background: rgba(245, 158, 11, 0.1); color: var(--university-gold); }

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

        /* Updated User Card - Cleaner Design */
        .user-card {
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

        .user-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: var(--success-green);
            transition: all 0.3s ease;
        }

        .user-card.pending::before { background: var(--warning-orange); }
        .user-card.inactive::before { background: var(--text-secondary); }
        .user-card.approved::before { background: var(--success-green); }

        .user-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.12);
            transform: translateY(-2px);
        }

        /* User Card Header */
        .user-card-header {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-light);
        }

        .user-profile-pic {
            width: 3.5rem;
            height: 3.5rem;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--university-blue), var(--university-gold));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1rem;
            flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(30, 64, 175, 0.2);
        }

        .user-main-info {
            flex: 1;
            min-width: 0;
        }

        .user-name-section {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.5rem;
            flex-wrap: wrap;
        }

        .user-name-section h6 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .user-role-badges {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Improved Academic Badges */
        .academic-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            white-space: nowrap;
        }

        .academic-badge i {
            font-size: 0.75rem;
        }

        /* User Info Grid */
        .user-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .user-info-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        .user-info-item i {
            font-size: 0.9rem;
            color: var(--university-blue);
            opacity: 0.7;
        }

        .user-info-item strong {
            color: var(--text-primary);
        }

        /* User Card Footer */
        .user-card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-light);
        }

        .user-meta-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        /* Cleaner Action Buttons */
        .user-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            white-space: nowrap;
        }

        .action-btn i {
            font-size: 0.9rem;
        }

        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }

        .action-btn:active {
            transform: translateY(0);
        }

        .action-btn.btn-approve { 
            background: var(--success-green); 
            color: white; 
        }

        .action-btn.btn-approve:hover { 
            background: #047857; 
        }

        .action-btn.btn-reject { 
            background: var(--danger-red); 
            color: white; 
        }

        .action-btn.btn-reject:hover { 
            background: #b91c1c; 
        }

        .action-btn.btn-edit { 
            background: var(--university-blue); 
            color: white; 
        }

        .action-btn.btn-edit:hover { 
            background: #1e3a8a; 
        }

        .action-btn.btn-toggle { 
            background: var(--warning-orange); 
            color: white; 
        }

        .action-btn.btn-toggle:hover { 
            background: #b45309; 
        }

        .action-btn.btn-delete { 
            background: var(--danger-red); 
            color: white; 
        }

        .action-btn.btn-delete:hover { 
            background: #991b1b; 
        }

        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .user-info-grid {
                grid-template-columns: 1fr;
                gap: 0.5rem;
            }
            
            .user-card-footer {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .user-actions {
                width: 100%;
                justify-content: flex-start;
            }
        }

        @media (max-width: 576px) {
            .user-card {
                padding: 1rem;
            }
            
            .user-card-header {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }
            
            .user-profile-pic {
                width: 3rem;
                height: 3rem;
                font-size: 0.9rem;
            }
            
            .user-main-info {
                width: 100%;
                text-align: center;
            }
            
            .user-name-section {
                flex-direction: column;
                align-items: center;
            }
            
            .user-role-badges {
                justify-content: center;
            }
            
            .user-actions {
                flex-direction: column;
            }
            
            .action-btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 450px) {
            .user-name-section h6 {
                font-size: 1rem;
            }
            
            .academic-badge {
                font-size: 0.65rem;
                padding: 0.3rem 0.6rem;
            }
            
            .user-info-item {
                font-size: 0.8rem;
            }
            
            .action-btn {
                padding: 0.45rem 0.75rem;
                font-size: 0.8rem;
            }
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

        .notification-badge.updating {
            animation: spin 0.5s linear;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

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
            .user-card { padding: 1rem; }
            .user-profile-pic { width: 2.5rem; height: 2.5rem; font-size: 0.85rem; }
            .user-details h6 { font-size: 0.95rem; }
            .user-details .text-muted { font-size: 0.8rem; }
            .action-btn { padding: 0.35rem 0.6rem; font-size: 0.75rem; }
        }

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
            .nav-tabs .nav-link { flex: 1; min-width: auto; text-align: center; padding: 1rem 0.5rem; font-size: 0.9rem; display: flex; flex-direction: column; align-items: center; gap: 0.25rem; white-space: nowrap; color: var(--text-secondary); position: relative; }
            .nav-tabs .nav-link i { font-size: 1.3rem; margin: 0; }
            .nav-tabs .nav-link span { font-size: 0.75rem; white-space: nowrap; }
            .nav-tabs .nav-link .d-none.d-sm-inline { display: inline !important; }
            .nav-tabs .nav-link.active { color: var(--university-blue); background: white; }
            .nav-tabs .nav-link.active::after { content: ''; position: absolute; bottom: -3px; left: 0; right: 0; height: 3px; background: var(--university-gold); }
            .nav-tabs .nav-link:hover:not(.active) { color: var(--university-blue); background: rgba(30, 64, 175, 0.05); }
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
            .user-card { padding: 1rem; }
            .user-meta { flex-direction: column; align-items: flex-start; margin-bottom: 1rem; }
            .user-profile-pic { width: 2.5rem; height: 2.5rem; font-size: 0.85rem; margin-right: 0; margin-bottom: 0.75rem; }
            .user-details { width: 100%; margin-bottom: 0.5rem; }
            .user-details h6 { font-size: 0.9rem; margin-bottom: 0.5rem; }
            .user-details .text-muted { font-size: 0.75rem; line-height: 1.5; }
            .user-badges { width: 100%; margin-left: 0; text-align: left; }
            .academic-badge { font-size: 0.65rem; padding: 0.25rem 0.5rem; margin: 0.125rem 0.125rem 0.125rem 0; }
            .user-card > .d-flex { flex-direction: column; gap: 0.75rem; }
            .user-card > .d-flex > small { width: 100%; }
            .user-actions { width: 100%; display: flex; flex-wrap: wrap; gap: 0.25rem; }
            .action-btn { padding: 0.4rem 0.6rem; font-size: 0.7rem; flex: 1 1 auto; min-width: fit-content; }
            .action-btn i { font-size: 0.75rem; }
            .modal-dialog { margin: 0.5rem; }
            .modal-header, .modal-body, .modal-footer { padding: 1rem; }
            .modal-title { font-size: 1rem; }
            .pagination { font-size: 0.85rem; }
            .pagination .page-link { padding: 0.375rem 0.5rem; }
        }

        @media (max-width: 450px) {
            .university-name { font-size: 0.75rem; line-height: 1.2; }
            .university-logo { width: 30px; height: 30px; margin-right: 6px; }
            .page-title { font-size: 1.1rem; }
            .page-subtitle { font-size: 0.8rem; }
            .nav-tabs .nav-link { padding: 0.75rem 0.25rem; font-size: 0.8rem; }
            .nav-tabs .nav-link i { font-size: 1.1rem; }
            .nav-tabs .nav-link span { font-size: 0.7rem; }
            .section-header { font-size: 0.8rem; padding: 0.65rem 0.75rem; }
            .stat-number { font-size: 1.2rem; }
            .stat-label { font-size: 0.6rem; }
            .user-details h6 { font-size: 0.85rem; }
            .user-details .text-muted { font-size: 0.7rem; }
            .academic-badge { font-size: 0.6rem; padding: 0.2rem 0.4rem; }
            .action-btn { padding: 0.35rem 0.5rem; font-size: 0.65rem; }
            .filter-section .form-label { font-size: 0.8rem; }
            .filter-section .form-select, .filter-section .form-control, .filter-section .btn { font-size: 0.8rem; padding: 0.4rem; }
        }

        @media (max-width: 768px) and (orientation: landscape) {
            .university-header { padding: 0.5rem 0; }
            .page-header { padding: 1rem; margin-bottom: 1rem; }
            .main-content { padding: 1rem 0; }
            .nav-tabs .nav-link { padding: 0.75rem 1rem; }
            .modal-dialog { max-width: 90%; }
        }

        @media (max-width: 576px) {
            .dropdown-menu { max-width: 90vw; }
            .dropdown-menu.notification-dropdown { min-width: 90vw; }
        }

        @media (max-height: 700px) {
            .modal-dialog-scrollable .modal-body { max-height: calc(100vh - 150px); }
        }

        @media (hover: none) and (pointer: coarse) {
            .action-btn, .btn, .nav-link, .notification-item, .user-card { min-height: 44px; display: inline-flex; align-items: center; justify-content: center; }
            .action-btn { padding: 0.5rem 0.75rem; }
        }

        @media print {
            .university-header, .main-nav, .notification-section, .user-actions, .action-btn, .filter-section, .pagination { display: none !important; }
            .page-header { border-left: none; box-shadow: none; }
            .user-card { break-inside: avoid; page-break-inside: avoid; }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation-duration: 0.01ms !important; animation-iteration-count: 1 !important; transition-duration: 0.01ms !important; }
        }

        @media (prefers-contrast: high) {
            .academic-card, .user-card, .filter-section, .dashboard-section { border: 2px solid var(--text-primary); }
            .notification-badge { border-width: 3px; }
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
                    <a class="nav-link active" href="manage_users.php">
                        <i class="bi bi-people me-1 me-md-2"></i>
                        <span class="d-none d-sm-inline">Users</span>
                        <?php if ($stats['pending'] > 0): ?>
                            <span class="badge-notification"><?php echo $stats['pending']; ?></span>
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
        <div class="page-header d-flex justify-content-between align-items-start flex-wrap">
            <div>
                <h1 class="page-title">User Management</h1>
                <p class="page-subtitle">Manage user accounts, registrations, and permissions</p>
            </div>
            <div class="mt-3 mt-md-0">
                <a href="add_user.php" class="btn btn-primary">
                    <i class="bi bi-person-plus me-1"></i>Add New User
                </a>
            </div>
        </div>

        <!-- Success/Error Messages -->
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i>
                <?php echo htmlspecialchars($_GET['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php elseif (!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i>
                <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

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
                <div class="academic-card warning">
                    <div class="stat-icon warning">
                        <i class="bi bi-clock"></i>
                    </div>
                    <div class="stat-number" style="color: var(--warning-orange)"><?php echo $stats['pending']; ?></div>
                    <div class="stat-label">Pending</div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="academic-card success">
                    <div class="stat-icon success">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <div class="stat-number" style="color: var(--success-green)"><?php echo $stats['active']; ?></div>
                    <div class="stat-label">Active</div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="academic-card gold">
                    <div class="stat-icon gold">
                        <i class="bi bi-mortarboard"></i>
                    </div>
                    <div class="stat-number" style="color: var(--university-gold)"><?php echo $stats['students']; ?></div>
                    <div class="stat-label">Students</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-section">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label for="role" class="form-label fw-semibold">Role</label>
                    <select class="form-select" id="role" name="role">
                        <option value="all" <?php echo $role_filter === 'all' ? 'selected' : ''; ?>>All Roles</option>
                        <option value="student" <?php echo $role_filter === 'student' ? 'selected' : ''; ?>>Students</option>
                        <option value="adviser" <?php echo $role_filter === 'adviser' ? 'selected' : ''; ?>>Advisers</option>
                        <option value="panel" <?php echo $role_filter === 'panel' ? 'selected' : ''; ?>>Panel</option>
                        <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>Admins</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="status" class="form-label fw-semibold">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="active" class="form-label fw-semibold">Account</label>
                    <select class="form-select" id="active" name="active">
                        <option value="all" <?php echo $active_filter === 'all' ? 'selected' : ''; ?>>All Accounts</option>
                        <option value="1" <?php echo $active_filter === '1' ? 'selected' : ''; ?>>Active</option>
                        <option value="0" <?php echo $active_filter === '0' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="search" class="form-label fw-semibold">Search</label>
                    <input type="text" class="form-control" id="search" name="search" 
                           value="<?php echo htmlspecialchars($search); ?>" 
                           placeholder="Search by name, username, email, or ID">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-search me-1"></i>Filter
                    </button>
                </div>
            </form>
        </div>

        <!-- Users List -->
        <div class="dashboard-section">
            <div class="section-header">
                <i class="bi bi-people me-2"></i>Users (<?php echo $total_records; ?> total)
            </div>
            <div class="section-body">
                <?php if (empty($users)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-people" style="font-size: 4rem; color: var(--text-secondary); opacity: 0.5;"></i>
                        <p class="mt-3 text-muted fs-5">No users found.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($users as $user): ?>
                        <div class="user-card <?php 
                            if (($user['registration_status'] ?? 'approved') === 'pending') echo 'pending';
                            elseif (!($user['is_active'] ?? 1)) echo 'inactive';
                            else echo 'approved';
                        ?>">
                            <!-- Card Header -->
                            <div class="user-card-header">
                                <div class="user-profile-pic">
                                    <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                                </div>
                                
                                <div class="user-main-info">
                                    <div class="user-name-section">
                                        <h6><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h6>
                                        <div class="user-role-badges">
                                            <span class="academic-badge <?php
                                                echo $user['role'] === 'admin' ? 'danger' : 
                                                    ($user['role'] === 'adviser' ? 'success' : 
                                                    ($user['role'] === 'panel' ? 'primary' : 'primary'));
                                            ?>">
                                                <i class="bi bi-person-badge"></i>
                                                <?php echo ucfirst($user['role']); ?>
                                            </span>
                                            
                                            <?php
                                            $reg_status = $user['registration_status'] ?? 'approved';
                                            $badge_class = $reg_status === 'pending' ? 'warning' : 
                                                        ($reg_status === 'rejected' ? 'danger' : 'success');
                                            ?>
                                            <span class="academic-badge <?php echo $badge_class; ?>">
                                                <i class="bi bi-<?php echo $reg_status === 'approved' ? 'check-circle' : 'clock'; ?>"></i>
                                                <?php echo ucfirst($reg_status); ?>
                                            </span>
                                            
                                            <span class="academic-badge <?php echo ($user['is_active'] ?? 1) ? 'success' : 'secondary'; ?>">
                                                <i class="bi bi-<?php echo ($user['is_active'] ?? 1) ? 'power' : 'dash-circle'; ?>"></i>
                                                <?php echo ($user['is_active'] ?? 1) ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <!-- User Info Grid -->
                                    <div class="user-info-grid">
                                        <div class="user-info-item">
                                            <i class="bi bi-envelope"></i>
                                            <span><?php echo htmlspecialchars($user['email']); ?></span>
                                        </div>
                                        
                                        <?php if (!empty($user['student_id'])): ?>
                                        <div class="user-info-item">
                                            <i class="bi bi-card-text"></i>
                                            <span>ID: <strong><?php echo htmlspecialchars($user['student_id']); ?></strong></span>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($user['college'])): ?>
                                        <div class="user-info-item">
                                            <i class="bi bi-building"></i>
                                            <span><?php echo htmlspecialchars($user['college']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($user['department'])): ?>
                                        <div class="user-info-item">
                                            <i class="bi bi-diagram-3"></i>
                                            <span><?php echo htmlspecialchars($user['department']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Card Footer -->
                            <div class="user-card-footer">
                                <div class="user-meta-info">
                                    <i class="bi bi-calendar3"></i>
                                    <span>Joined: <?php echo date('M j, Y', strtotime($user['created_at'])); ?></span>
                                </div>
                                
                                <div class="user-actions">
                                    <?php if (($user['registration_status'] ?? 'approved') === 'pending'): ?>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                            <button type="submit" name="approve_user" class="action-btn btn-approve" 
                                                    onclick="return confirm('Approve this user registration?')">
                                                <i class="bi bi-check-lg"></i>Approve
                                            </button>
                                        </form>
                                        
                                        <button type="button" class="action-btn btn-reject" 
                                                onclick="rejectUser(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name'], ENT_QUOTES); ?>')">
                                            <i class="bi bi-x-lg"></i>Reject
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                        <?php if ($user['is_active'] ?? 1): ?>
                                            <button type="button" class="action-btn btn-toggle" 
                                                    onclick="toggleAccountStatus(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name'], ENT_QUOTES); ?>', 0)">
                                                <i class="bi bi-pause-circle"></i>Deactivate
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="action-btn btn-toggle" style="background: var(--success-green);"
                                                    onclick="toggleAccountStatus(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name'], ENT_QUOTES); ?>', 1)">
                                                <i class="bi bi-play-circle"></i>Activate
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <button type="button" class="action-btn btn-edit" 
                                            onclick="editUser(<?php echo $user['user_id']; ?>)">
                                        <i class="bi bi-pencil"></i>Edit
                                    </button>
                                    
                                    <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                        <button type="button" class="action-btn btn-delete" 
                                                onclick="confirmDelete(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name'], ENT_QUOTES); ?>')">
                                            <i class="bi bi-trash"></i>Delete
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <nav aria-label="Page navigation" class="mt-4">
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $page - 1; ?>&role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>&active=<?php echo $active_filter; ?>&search=<?php echo urlencode($search); ?>">Previous</a>
                            </li>
                            
                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>&active=<?php echo $active_filter; ?>&search=<?php echo urlencode($search); ?>"><?php echo $i; ?></a>
                            </li>
                            <?php endfor; ?>
                            
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $page + 1; ?>&role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>&active=<?php echo $active_filter; ?>&search=<?php echo urlencode($search); ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Account Status Toggle Modal -->
    <div class="modal fade" id="statusModal" tabindex="-1" aria-labelledby="statusModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="statusModalLabel">Confirm Account Status Change</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex align-items-center mb-3">
                        <i id="statusIcon" class="fs-2 me-3"></i>
                        <div>
                            <h6 id="statusTitle" class="mb-1"></h6>
                            <p id="statusMessage" class="mb-0 text-muted"></p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" id="confirmStatusBtn" class="btn">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <!-- User Rejection Modal -->
    <div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="rejectModalLabel">Reject User Registration</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex align-items-center mb-3">
                        <i class="bi bi-x-circle-fill text-danger fs-2 me-3"></i>
                        <div>
                            <h6 id="rejectUserName" class="mb-1"></h6>
                            <p class="mb-0 text-muted">This user's registration will be rejected and their account will be permanently deleted.</p>
                        </div>
                    </div>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Warning:</strong> This action cannot be undone. The user will be permanently removed from the system.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" id="confirmRejectBtn" class="btn btn-danger">Reject & Delete</button>
                </div>
            </div>
        </div>
    </div>

    <!-- User Deletion Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteModalLabel">Delete User Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex align-items-center mb-3">
                        <i class="bi bi-trash-fill text-danger fs-2 me-3"></i>
                        <div>
                            <h6 id="deleteUserName" class="mb-1"></h6>
                            <p class="mb-0 text-muted">This action cannot be undone.</p>
                        </div>
                    </div>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Warning:</strong> This will permanently delete the user and all related data including research groups, notifications, and reviews.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" id="confirmDeleteBtn" class="btn btn-danger">Delete Permanently</button>
                </div>
            </div>
        </div>
    </div>
    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editUserModalLabel">
                        <i class="bi bi-pencil-square me-2"></i>Edit User
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="editUserForm">
                    <div class="modal-body">
                        <input type="hidden" id="edit_user_id" name="user_id">
                        
                        <!-- Personal Information -->
                        <div class="mb-4">
                            <h6 class="text-primary mb-3">
                                <i class="bi bi-person me-2"></i>Personal Information
                            </h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="edit_first_name" class="form-label">First Name *</label>
                                    <input type="text" class="form-control" id="edit_first_name" name="first_name" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="edit_last_name" class="form-label">Last Name *</label>
                                    <input type="text" class="form-control" id="edit_last_name" name="last_name" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="edit_username" class="form-label">Username *</label>
                                    <input type="text" class="form-control" id="edit_username" name="username" required>
                                    <div class="form-text">Username cannot contain spaces</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="edit_email" class="form-label">Email Address *</label>
                                    <input type="email" class="form-control" id="edit_email" name="email" required>
                                </div>
                            </div>
                        </div>

                        <!-- Security Information -->
                        <div class="mb-4">
                            <h6 class="text-primary mb-3">
                                <i class="bi bi-shield-lock me-2"></i>Security Information
                            </h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="edit_password" class="form-label">New Password</label>
                                    <div class="password-toggle">
                                        <input type="password" class="form-control" id="edit_password" name="password">
                                        <i class="bi bi-eye-slash toggle-password" onclick="togglePasswordField('edit_password', this)" title="Show/Hide Password"></i>
                                    </div>
                                    <div class="form-text">Leave blank to keep current password</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="edit_confirm_password" class="form-label">Confirm New Password</label>
                                    <div class="password-toggle">
                                        <input type="password" class="form-control" id="edit_confirm_password" name="confirm_password">
                                        <i class="bi bi-eye-slash toggle-password" onclick="togglePasswordField('edit_confirm_password', this)" title="Show/Hide Password"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Academic Information -->
                        <div class="mb-4">
                            <h6 class="text-primary mb-3">
                                <i class="bi bi-mortarboard me-2"></i>Academic Information
                            </h6>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label for="edit_role" class="form-label">Role *</label>
                                    <select class="form-select" id="edit_role" name="role" required>
                                        <option value="">Select role</option>
                                        <option value="student">Student</option>
                                        <option value="adviser">Adviser</option>
                                        <option value="panel">Panel Member</option>
                                        <option value="admin">Administrator</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="edit_college" class="form-label">College</label>
                                    <select class="form-select" id="edit_college" name="college">
                                        <option value="">Select college</option>
                                        <?php 
                                        $colleges = array_keys($college_departments);
                                        foreach ($colleges as $college): ?>
                                            <option value="<?php echo htmlspecialchars($college); ?>">
                                                <?php echo htmlspecialchars($college); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="edit_department" class="form-label">Department</label>
                                    <select class="form-select" id="edit_department" name="department">
                                        <option value="">Select college first</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active">
                                    <label class="form-check-label" for="edit_is_active">
                                        <strong>Active User</strong>
                                    </label>
                                    <div class="form-text">Uncheck to deactivate this user account</div>
                                </div>
                            </div>
                        </div>

                        <!-- Alert for messages -->
                        <div id="editUserAlert" class="alert d-none" role="alert"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-lg me-1"></i>Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Update User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Global variables for modal functionality
        let currentUserId = null;
        let currentUserName = null;
        let currentAction = null;

        function rejectUser(userId, userName) {
            currentUserId = userId;
            currentUserName = userName;
            
            const modal = new bootstrap.Modal(document.getElementById('rejectModal'));
            document.getElementById('rejectUserName').textContent = `Reject registration for ${userName}?`;
            
            modal.show();
        }

        // Function to activate/deactivate account with modal
        function toggleAccountStatus(userId, userName, newStatus) {
            currentUserId = userId;
            currentUserName = userName;
            currentAction = newStatus;
            
            const modal = new bootstrap.Modal(document.getElementById('statusModal'));
            const statusIcon = document.getElementById('statusIcon');
            const statusTitle = document.getElementById('statusTitle');
            const statusMessage = document.getElementById('statusMessage');
            const confirmBtn = document.getElementById('confirmStatusBtn');
            
            if (newStatus === 1) {
                // Activating account
                statusIcon.className = 'bi bi-play-circle-fill text-success fs-2 me-3';
                statusTitle.textContent = `Activate ${userName}?`;
                statusMessage.textContent = 'This will allow the user to log in and access the system.';
                confirmBtn.className = 'btn btn-success';
                confirmBtn.textContent = 'Activate Account';
            } else {
                // Deactivating account  
                statusIcon.className = 'bi bi-pause-circle-fill text-warning fs-2 me-3';
                statusTitle.textContent = `Deactivate ${userName}?`;
                statusMessage.textContent = 'This will prevent the user from logging in until reactivated.';
                confirmBtn.className = 'btn btn-warning';
                confirmBtn.textContent = 'Deactivate Account';
            }
            
            modal.show();
        }

        function confirmDelete(userId, userName) {
            currentUserId = userId;
            currentUserName = userName;
            
            const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
            document.getElementById('deleteUserName').textContent = `Delete user: ${userName}?`;
            
            modal.show();
        }

        // Event listeners for modal confirm buttons
        document.getElementById('confirmStatusBtn').addEventListener('click', function() {
            const form = document.createElement('form');
            form.method = 'post';
            form.style.display = 'none';
            
            const userIdInput = document.createElement('input');
            userIdInput.type = 'hidden';
            userIdInput.name = 'user_id';
            userIdInput.value = currentUserId;
            
            const statusInput = document.createElement('input');
            statusInput.type = 'hidden';
            statusInput.name = 'new_status';
            statusInput.value = currentAction;
            
            const submitInput = document.createElement('input');
            submitInput.type = 'hidden';
            submitInput.name = 'toggle_active_status';
            submitInput.value = '1';
            
            form.appendChild(userIdInput);
            form.appendChild(statusInput);
            form.appendChild(submitInput);
            
            document.body.appendChild(form);
            form.submit();
        });

        document.getElementById('confirmRejectBtn').addEventListener('click', function() {
            const form = document.createElement('form');
            form.method = 'post';
            form.style.display = 'none';
            
            const userIdInput = document.createElement('input');
            userIdInput.type = 'hidden';
            userIdInput.name = 'user_id';
            userIdInput.value = currentUserId;
            
            const submitInput = document.createElement('input');
            submitInput.type = 'hidden';
            submitInput.name = 'reject_user';
            submitInput.value = '1';
            
            form.appendChild(userIdInput);
            form.appendChild(submitInput);
            
            document.body.appendChild(form);
            form.submit();
        });

        document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
            window.location.href = 'manage_users.php?delete=1&user_id=' + currentUserId;
        });

        // Mobile-friendly interactions
        document.addEventListener('DOMContentLoaded', function() {
            // Add hover effects to user cards
            const userCards = document.querySelectorAll('.user-card');
            userCards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-2px)';
                });
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });
        });
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
        // College-Department mapping
        const collegeDepartments = <?php echo json_encode($college_departments); ?>;

        // Edit User Function
        function editUser(userId) {
            fetch(`?get_user=1&user_id=${userId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const user = data.user;
                        
                        document.getElementById('edit_user_id').value = user.user_id;
                        document.getElementById('edit_first_name').value = user.first_name;
                        document.getElementById('edit_last_name').value = user.last_name;
                        document.getElementById('edit_username').value = user.username;
                        document.getElementById('edit_email').value = user.email;
                        document.getElementById('edit_role').value = user.role;
                        document.getElementById('edit_college').value = user.college || '';
                        document.getElementById('edit_is_active').checked = user.is_active == 1;
                        
                        document.getElementById('edit_password').value = '';
                        document.getElementById('edit_confirm_password').value = '';
                        
                        populateDepartmentDropdown(user.college, user.department);
                        
                        const alert = document.getElementById('editUserAlert');
                        alert.className = 'alert d-none';
                        alert.textContent = '';
                        
                        const modal = new bootstrap.Modal(document.getElementById('editUserModal'));
                        modal.show();
                    } else {
                        alert('Error: ' + (data.error || 'Failed to load user data'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while loading user data');
                });
        }

        function populateDepartmentDropdown(selectedCollege, selectDepartment = null) {
            const departmentSelect = document.getElementById('edit_department');
            departmentSelect.innerHTML = '<option value="">Select department</option>';
            
            if (selectedCollege && collegeDepartments[selectedCollege]) {
                departmentSelect.disabled = false;
                
                collegeDepartments[selectedCollege].forEach(function(department) {
                    const option = document.createElement('option');
                    option.value = department;
                    option.textContent = department;
                    if (selectDepartment && department === selectDepartment) {
                        option.selected = true;
                    }
                    departmentSelect.appendChild(option);
                });
            } else {
                departmentSelect.disabled = true;
                departmentSelect.innerHTML = '<option value="">Select college first</option>';
            }
        }

        document.getElementById('edit_college').addEventListener('change', function() {
            populateDepartmentDropdown(this.value);
        });

        document.getElementById('edit_username').addEventListener('keypress', function(e) {
            if (e.key === ' ' || e.keyCode === 32) {
                e.preventDefault();
            }
        });

        document.getElementById('edit_username').addEventListener('input', function(e) {
            this.value = this.value.replace(/\s/g, '');
        });

        function togglePasswordField(fieldId, icon) {
            const field = document.getElementById(fieldId);
            const type = field.getAttribute('type') === 'password' ? 'text' : 'password';
            field.setAttribute('type', type);
            
            if (type === 'text') {
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            } else {
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            }
        }

        document.getElementById('edit_confirm_password').addEventListener('input', function() {
            const password = document.getElementById('edit_password').value;
            const confirmPassword = this.value;
            
            if (password && confirmPassword && password !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });

        document.getElementById('edit_password').addEventListener('input', function() {
            const confirmPassword = document.getElementById('edit_confirm_password');
            if (this.value && confirmPassword.value && this.value !== confirmPassword.value) {
                confirmPassword.setCustomValidity('Passwords do not match');
            } else {
                confirmPassword.setCustomValidity('');
            }
        });

        document.getElementById('editUserForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('ajax_update_user', '1');
            
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Updating...';
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                const alert = document.getElementById('editUserAlert');
                
                if (data.success) {
                    alert.className = 'alert alert-success';
                    alert.innerHTML = '<i class="bi bi-check-circle me-2"></i>' + data.message;
                    alert.classList.remove('d-none');
                    
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    alert.className = 'alert alert-danger';
                    alert.innerHTML = '<i class="bi bi-exclamation-triangle me-2"></i>' + data.error;
                    alert.classList.remove('d-none');
                    
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnText;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                const alert = document.getElementById('editUserAlert');
                alert.className = 'alert alert-danger';
                alert.innerHTML = '<i class="bi bi-exclamation-triangle me-2"></i>An error occurred while updating the user';
                alert.classList.remove('d-none');
                
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnText;
            });
        });
    </script>
</body>
</html>