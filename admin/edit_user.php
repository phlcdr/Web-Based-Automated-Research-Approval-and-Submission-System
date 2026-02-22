<?php
session_start();

include_once '../config/database.php';
include_once '../includes/submission_functions.php';  
include_once '../includes/functions.php';

// Check if user is logged in and is an admin
is_logged_in();
check_role(['admin']);

$success = '';
$error = '';
$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($user_id <= 0) {
    header("Location: manage_users.php?error=invalid_user");
    exit();
}

// Helper function to determine notification redirect URL
function getNotificationRedirectUrl($notification) {
    switch($notification['type']) {
        case 'user_registration':
            return 'manage_users.php';
        case 'title_submission':
        case 'chapter_submission':
            return 'manage_research.php';
        case 'reviewer_assignment':
            return 'manage_research.php';
        case 'discussion_update':
            return 'manage_research.php';
        default:
            return 'dashboard.php';
    }
}

// Handle AJAX request to mark notification as read
if (isset($_GET['mark_read']) && isset($_GET['notification_id'])) {
    try {
        $stmt = $conn->prepare("UPDATE notifications SET is_viewed = 1 WHERE notification_id = ? AND user_id = ?");
        $stmt->execute([$_GET['notification_id'], $_SESSION['user_id']]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Handle AJAX request to get notification count
if (isset($_GET['get_count'])) {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_viewed = 0");
        $stmt->execute([$_SESSION['user_id']]);
        $count = $stmt->fetchColumn();
        echo json_encode(['count' => (int)$count]);
    } catch (PDOException $e) {
        echo json_encode(['count' => 0]);
    }
    exit;
}

// Fetch notifications for admin
$recent_notifications = [];
$total_unviewed = 0;

try {
    // Get unviewed count
    $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_viewed = 0");
    $stmt->execute([$_SESSION['user_id']]);
    $total_unviewed = (int)$stmt->fetchColumn();
    
    // Get recent notifications (last 10)
    $stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY submission_date DESC LIMIT 10");
    $stmt->execute([$_SESSION['user_id']]);
    $recent_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Silently fail - notifications are not critical
    $total_unviewed = 0;
    $recent_notifications = [];
}

// Updated colleges and departments mapping
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

// Get colleges for dropdown
$colleges = array_keys($college_departments);

// Get user data
$stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header("Location: manage_users.php?error=user_not_found");
    exit();
}

// Get user's group information (simplified)
$user_group = null;
if ($user['role'] === 'student') {
    $stmt = $conn->prepare("
        SELECT rg.group_id, rg.group_name, rg.college, rg.program, rg.year_level,
               CONCAT(adviser.first_name, ' ', adviser.last_name) as adviser_name,
               CASE WHEN rg.lead_student_id = ? THEN 1 ELSE 0 END as is_leader
        FROM group_memberships gm
        JOIN research_groups rg ON gm.group_id = rg.group_id
        LEFT JOIN users adviser ON rg.adviser_id = adviser.user_id
        WHERE gm.user_id = ? AND gm.is_registered_user = 1
        LIMIT 1
    ");
    $stmt->execute([$user_id, $user_id]);
    $user_group = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = validate_input($_POST['username']);
    $email = validate_input($_POST['email']);
    $first_name = validate_input($_POST['first_name']);
    $last_name = validate_input($_POST['last_name']);
    $role = validate_input($_POST['role']);
    $college = validate_input($_POST['college']);
    $department = validate_input($_POST['department']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Handle password update
    $password_update = '';
    $password_params = [];
    if (!empty($_POST['password'])) {
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        
        if ($password !== $confirm_password) {
            $error = "Passwords do not match";
        } elseif (strlen($password) < 6) {
            $error = "Password must be at least 6 characters long";
        } else {
            $password_update = ', password = ?';
            $password_params[] = password_hash($password, PASSWORD_DEFAULT);
        }
    }

    // Basic validation
    if (empty($error) && (empty($username) || empty($email) || empty($first_name) || empty($last_name) || empty($role))) {
        $error = "All required fields must be filled out";
    } elseif (empty($error) && !empty($college) && !empty($department)) {
        // Validate that department belongs to selected college
        if (!isset($college_departments[$college]) || !in_array($department, $college_departments[$college])) {
            $error = "Selected department does not belong to the selected college";
        }
    }

    if (empty($error)) {
        // Check if username already exists (excluding current user)
        $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? AND user_id != ?");
        $stmt->execute([$username, $user_id]);
        if ($stmt->rowCount() > 0) {
            $error = "Username already exists";
        } else {
            // Check if email already exists (excluding current user)
            $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? AND user_id != ?");
            $stmt->execute([$email, $user_id]);
            if ($stmt->rowCount() > 0) {
                $error = "Email already exists";
            } else {
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
                    $success = "User updated successfully!";
                    // Refresh user data
                    $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Refresh group information
                    if ($user['role'] === 'student') {
                        $stmt = $conn->prepare("
                            SELECT rg.group_id, rg.group_name, rg.college, rg.program, rg.year_level,
                                   CONCAT(adviser.first_name, ' ', adviser.last_name) as adviser_name,
                                   CASE WHEN rg.lead_student_id = ? THEN 1 ELSE 0 END as is_leader
                            FROM group_memberships gm
                            JOIN research_groups rg ON gm.group_id = rg.group_id
                            LEFT JOIN users adviser ON rg.adviser_id = adviser.user_id
                            WHERE gm.user_id = ? AND gm.is_registered_user = 1
                            LIMIT 1
                        ");
                        $stmt->execute([$user_id, $user_id]);
                        $user_group = $stmt->fetch(PDO::FETCH_ASSOC);
                    }
                } else {
                    $error = "An error occurred during user update";
                }
            }
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
    <title>Edit User - Research Approval System</title>
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

        /* Form Sections */
        .form-section {
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
            padding: 2rem;
        }

        /* Form Controls */
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

        .form-check-input:checked {
            background-color: var(--university-blue);
            border-color: var(--university-blue);
        }

        /* Password Toggle */
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

        /* Buttons */
        .btn-primary {
            background: var(--university-blue);
            border-color: var(--university-blue);
            font-weight: 500;
        }

        .btn-primary:hover {
            background: #1e3a8a;
            border-color: #1e3a8a;
        }

        .btn-outline-secondary {
            color: var(--text-secondary);
            border-color: var(--border-light);
        }

        .btn-outline-secondary:hover {
            background: var(--text-secondary);
            border-color: var(--text-secondary);
        }

        .btn-outline-primary {
            color: var(--university-blue);
            border-color: var(--university-blue);
        }

        .btn-outline-primary:hover {
            background: var(--university-blue);
            border-color: var(--university-blue);
        }

        /* Alert Messages */
        .alert-success {
            background: rgba(5, 150, 105, 0.1);
            border-color: var(--success-green);
            color: var(--success-green);
        }

        .alert-danger {
            background: rgba(220, 38, 38, 0.1);
            border-color: var(--danger-red);
            color: var(--danger-red);
        }

        /* Academic Badges */
        .academic-badge {
            padding: 0.3rem 0.6rem;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            display: inline-block;
        }

        .academic-badge.success { background: var(--success-green); color: white; }
        .academic-badge.primary { background: var(--university-blue); color: white; }
        .academic-badge.warning { background: var(--warning-orange); color: white; }
        .academic-badge.danger { background: var(--danger-red); color: white; }
        .academic-badge.secondary { background: var(--text-secondary); color: white; }
        .academic-badge.info { background: #06b6d4; color: white; }

        /* User Details Cards */
        .detail-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            overflow: hidden;
        }

        .detail-card.success { border-left: 4px solid var(--success-green); }
        .detail-card.info { border-left: 4px solid #06b6d4; }
        .detail-card.light { border-left: 4px solid var(--text-secondary); }

        /* Group Members List */
        .member-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border-light);
        }

        .member-item:last-child {
            border-bottom: none;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .university-header {
                padding: 0.75rem 0;
            }
            
            .university-name {
                font-size: 1rem;
            }
            
            .page-title {
                font-size: 1.5rem;
            }
            
            .page-header {
                padding: 1.5rem;
            }
            
            .nav-tabs .nav-link {
                padding: 0.75rem 0.75rem;
                font-size: 0.85rem;
            }
            
            .main-content {
                padding: 1rem 0;
            }
            
            .section-body {
                padding: 1.5rem;
            }

            .notification-dropdown {
                min-width: 320px;
                max-width: 350px;
            }
        }

        @media (max-width: 576px) {
            .university-brand {
                flex-direction: column;
                text-align: center;
            }
            
            .university-logo {
                margin-right: 0;
                margin-bottom: 0.5rem;
            }
            
            .user-info {
                display: none;
            }
            
            .nav-tabs {
                flex-wrap: wrap;
            }
            
            .nav-tabs .nav-link {
                flex: 1;
                text-align: center;
                padding: 0.5rem;
                font-size: 0.8rem;
            }
            
            .section-body {
                padding: 1rem;
            }
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
                                            class="notification-item<?php echo $notif['is_viewed'] ? ' viewed' : ''; ?>" 
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
                                    <?php endforeach; ?><?php else: ?>
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
                <h1 class="page-title">Edit User</h1>
                <p class="page-subtitle">Update user information: <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></p>
            </div>
            <div class="mt-3 mt-md-0">
                <a href="manage_users.php" class="btn btn-outline-primary">
                    <i class="bi bi-arrow-left me-1"></i>Back to Users
                </a>
            </div>
        </div>

        <!-- Success/Error Messages -->
        <?php if (!empty($success)): ?>
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

        <!-- Edit User Form -->
        <form method="post">
            <!-- Personal Information -->
            <div class="form-section">
                <div class="section-header">
                    <i class="bi bi-person me-2"></i>Personal Information
                </div>
                <div class="section-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="first_name" class="form-label">First Name *</label>
                            <input type="text" class="form-control" id="first_name" name="first_name" 
                                   value="<?php echo htmlspecialchars($user['first_name']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="last_name" class="form-label">Last Name *</label>
                            <input type="text" class="form-control" id="last_name" name="last_name" 
                                   value="<?php echo htmlspecialchars($user['last_name']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="username" class="form-label">Username *</label>
                            <input type="text" class="form-control" id="username" name="username" 
                                   value="<?php echo htmlspecialchars($user['username']); ?>" required>
                            <div class="form-text">Username cannot contain spaces</div>
                        </div>
                        <div class="col-md-6">
                            <label for="email" class="form-label">Email Address *</label>
                            <input type="email" class="form-control" id="email" name="email" 
                                   value="<?php echo htmlspecialchars($user['email']); ?>" required>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Security Information -->
            <div class="form-section">
                <div class="section-header">
                    <i class="bi bi-shield-lock me-2"></i>Security Information
                </div>
                <div class="section-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="password" class="form-label">New Password (leave blank to keep current)</label>
                            <div class="password-toggle">
                                <input type="password" class="form-control" id="password" name="password">
                                <i class="bi bi-eye-slash toggle-password" onclick="togglePassword('password', this)" title="Show/Hide Password"></i>
                            </div>
                            <div class="form-text">Leave blank if you don't want to change the password.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="confirm_password" class="form-label">Confirm New Password</label>
                            <div class="password-toggle">
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password">
                                <i class="bi bi-eye-slash toggle-password" onclick="togglePassword('confirm_password', this)" title="Show/Hide Password"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Academic Information -->
            <div class="form-section">
                <div class="section-header">
                    <i class="bi bi-mortarboard me-2"></i>Academic Information
                </div>
                <div class="section-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="role" class="form-label">Role *</label>
                            <select class="form-select" id="role" name="role" required>
                                <option value="">Select role</option>
                                <option value="student" <?php echo $user['role'] == 'student' ? 'selected' : ''; ?>>Student</option>
                                <option value="adviser" <?php echo $user['role'] == 'adviser' ? 'selected' : ''; ?>>Adviser&Panel</option>
                                <option value="panel" <?php echo $user['role'] == 'panel' ? 'selected' : ''; ?>>Panel Member Only</option>
                                <option value="admin" <?php echo $user['role'] == 'admin' ? 'selected' : ''; ?>>Administrator</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="college" class="form-label">College</label>
                            <select class="form-select" id="college" name="college">
                                <option value="">Select college</option>
                                <?php foreach ($colleges as $college): ?>
                                    <option value="<?php echo htmlspecialchars($college); ?>" <?php echo $user['college'] == $college ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($college); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="department" class="form-label">Department</label>
                            <select class="form-select" id="department" name="department">
                                <option value="">Select college first</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" 
                                   <?php echo (isset($user['is_active']) && $user['is_active']) || !isset($user['is_active']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_active">
                                <strong>Active User</strong>
                            </label>
                            <div class="form-text">Uncheck to deactivate this user account</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="d-flex gap-3 justify-content-end">
                <a href="manage_users.php" class="btn btn-outline-secondary">
                    <i class="bi bi-x-lg me-1"></i>Cancel
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i>Update User
                </button>
            </div>
        </form>

        <!-- User Details -->
        <div class="detail-card info">
            <div class="section-header" style="background: linear-gradient(90deg, #06b6d4, #0891b2);">
                <i class="bi bi-info-circle me-2"></i>User Details
            </div>
            <div class="section-body">
                <div class="row g-4">
                    <div class="col-md-3">
                        <strong>User ID:</strong><br>
                        <span class="text-muted"><?php echo $user['user_id']; ?></span>
                    </div>
                    <div class="col-md-3">
                        <strong>Created:</strong><br>
                        <span class="text-muted"><?php echo isset($user['created_at']) ? date('F d, Y', strtotime($user['created_at'])) : 'Unknown'; ?></span>
                    </div>
                    <div class="col-md-3">
                        <strong>Current Status:</strong><br>
                        <span class="academic-badge <?php echo (isset($user['is_active']) && $user['is_active']) || !isset($user['is_active']) ? 'success' : 'secondary'; ?>">
                            <?php echo (isset($user['is_active']) && $user['is_active']) || !isset($user['is_active']) ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>
                    <div class="col-md-3">
                        <strong>Current Role:</strong><br>
                        <span class="academic-badge <?php echo $user['role'] === 'admin' ? 'danger' : ($user['role'] === 'adviser' ? 'success' : ($user['role'] === 'panel' ? 'info' : 'primary')); ?>">
                            <?php echo ucfirst($user['role']); ?>
                        </span>
                    </div>
                </div>
                
                <div class="row g-4 mt-2">
                    <?php if (!empty($user['student_id'])): ?>
                    <div class="col-md-3">
                        <strong>Student ID:</strong><br>
                        <span class="text-muted"><?php echo htmlspecialchars($user['student_id']); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-3">
                        <strong>College:</strong><br>
                        <span class="text-muted"><?php echo htmlspecialchars($user['college'] ?? 'Not specified'); ?></span>
                    </div>
                    <div class="col-md-3">
                        <strong>Department:</strong><br>
                        <span class="text-muted"><?php echo htmlspecialchars($user['department'] ?? 'Not specified'); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Group Information (if student) -->
        <?php if ($user['role'] === 'student' && $user_group): ?>
        <div class="detail-card success">
            <div class="section-header" style="background: linear-gradient(90deg, var(--success-green), #047857);">
                <i class="bi bi-people me-2"></i>Research Group Information
            </div>
            <div class="section-body">
                <h6 class="text-primary mb-2"><?php echo htmlspecialchars($user_group['group_name']); ?></h6>
                <p class="mb-2">
                    <small class="text-muted">
                        <i class="bi bi-building me-1"></i><?php echo htmlspecialchars($user_group['college']); ?> - 
                        <?php echo htmlspecialchars($user_group['program']); ?> 
                        (<?php echo htmlspecialchars($user_group['year_level']); ?>)
                    </small>
                </p>
                <?php if (!empty($user_group['adviser_name'])): ?>
                    <p class="mb-3">
                        <small class="text-muted">
                            <i class="bi bi-person-badge me-1"></i>Adviser: <?php echo htmlspecialchars($user_group['adviser_name']); ?>
                        </small>
                    </p>
                <?php endif; ?>
                
                <div class="mt-3">
                    <strong class="text-muted">Group Members:</strong>
                    <div class="mt-2">
                        <?php
                        // Get all members of this group
                        $members_stmt = $conn->prepare("
                            SELECT 
                                CASE 
                                    WHEN gm.is_registered_user = 1 THEN CONCAT(u.first_name, ' ', u.last_name)
                                    ELSE gm.member_name
                                END as member_name,
                                CASE 
                                    WHEN gm.is_registered_user = 1 THEN u.student_id
                                    ELSE gm.student_number
                                END as student_number,
                                CASE 
                                    WHEN rg.lead_student_id = gm.user_id AND gm.is_registered_user = 1 THEN 1
                                    ELSE 0
                                END as is_leader
                            FROM group_memberships gm
                            LEFT JOIN users u ON gm.user_id = u.user_id AND gm.is_registered_user = 1
                            JOIN research_groups rg ON gm.group_id = rg.group_id
                            WHERE gm.group_id = ?
                            ORDER BY is_leader DESC, member_name
                        ");
                        $members_stmt->execute([$user_group['group_id']]);
                        $members = $members_stmt->fetchAll(PDO::FETCH_ASSOC);
                        ?>
                        
                        <?php foreach ($members as $member): ?>
                            <div class="member-item">
                                <span>
                                    <i class="bi bi-person me-1"></i>
                                    <?php echo htmlspecialchars($member['member_name']); ?>
                                    <?php if (!empty($member['student_number'])): ?>
                                        <span class="text-muted">(<?php echo htmlspecialchars($member['student_number']); ?>)</span>
                                    <?php endif; ?>
                                </span>
                                <?php if ($member['is_leader']): ?>
                                    <span class="academic-badge primary">Leader</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php elseif ($user['role'] === 'student'): ?>
        <div class="detail-card light">
            <div class="section-header" style="background: linear-gradient(90deg, var(--text-secondary), #4b5563);">
                <i class="bi bi-people me-2"></i>Research Group Information
            </div>
            <div class="section-body text-center">
                <i class="bi bi-people text-muted" style="font-size: 3rem;"></i>
                <p class="text-muted mt-3">Not a member of any research group yet.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Notification functions
        function markNotificationAsRead(notificationId) {
            return fetch(`?mark_read=1&notification_id=${notificationId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateNotificationCount();
                    }
                    return data;
                })
                .catch(error => {
                    console.error('Error marking notification as read:', error);
                    return { success: false };
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
                    console.error('Error updating notification count:', error);
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

        // Auto-update notification count every 30 seconds
        setInterval(updateNotificationCount, 30000);

        // College-Department mapping from PHP
        const collegeDepartments = <?php echo json_encode($college_departments); ?>;
        
        // Current user data
        const currentCollege = '<?php echo htmlspecialchars($user['college'] ?? ''); ?>';
        const currentDepartment = '<?php echo htmlspecialchars($user['department'] ?? ''); ?>';

        // Username space prevention
        function preventSpaceInUsername() {
            const usernameField = document.getElementById('username');
            
            usernameField.addEventListener('keypress', function(e) {
                if (e.key === ' ' || e.keyCode === 32) {
                    e.preventDefault();
                    const feedback = document.createElement('div');
                    feedback.className = 'text-danger small mt-1';
                    feedback.textContent = 'Username cannot contain spaces';
                    feedback.style.position = 'absolute';
                    
                    const existingFeedback = usernameField.parentNode.querySelector('.text-danger');
                    if (existingFeedback && existingFeedback !== usernameField.nextElementSibling) {
                        existingFeedback.remove();
                    }
                    
                    usernameField.parentNode.appendChild(feedback);
                    
                    setTimeout(() => {
                        if (feedback.parentNode) {
                            feedback.remove();
                        }
                    }, 2000);
                }
            });
            
            usernameField.addEventListener('input', function(e) {
                this.value = this.value.replace(/\s/g, '');
            });
            
            usernameField.addEventListener('paste', function(e) {
                setTimeout(() => {
                    this.value = this.value.replace(/\s/g, '');
                }, 1);
            });
        }

        // Toggle password visibility
        function togglePassword(fieldId, icon) {
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

        // Cascading dropdown functionality
        function setupCascadingDropdowns() {
            const collegeSelect = document.getElementById('college');
            const departmentSelect = document.getElementById('department');

            function populateDepartments(selectedCollege, selectDepartment = null) {
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

            collegeSelect.addEventListener('change', function() {
                populateDepartments(this.value);
            });

            // Initialize department dropdown on page load
            if (currentCollege) {
                populateDepartments(currentCollege, currentDepartment);
            }
        }

        // Password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;
            
            if (password && confirmPassword && password !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });

        document.getElementById('password').addEventListener('input', function() {
            const confirmPassword = document.getElementById('confirm_password');
            if (this.value && confirmPassword.value && this.value !== confirmPassword.value) {
                confirmPassword.setCustomValidity('Passwords do not match');
            } else {
                confirmPassword.setCustomValidity('');
            }
        });

        // Initialize all functionality when page loads
        document.addEventListener('DOMContentLoaded', function() {
            preventSpaceInUsername();
            setupCascadingDropdowns();
        });
    </script>
</body>
</html>