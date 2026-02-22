<?php
session_start();

include_once '../config/database.php';
include_once '../includes/functions.php';

// Check if user is logged in and is a student
is_logged_in();
check_role(['student']);

$success = '';
$error = '';

// Get user's college and department information
$stmt = $conn->prepare("SELECT college, department FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user_info = $stmt->fetch(PDO::FETCH_ASSOC);
$user_college = $user_info['college'] ?? '';
$user_department = $user_info['department'] ?? '';

// Get available advisers - filter by user's college (locked to user's registered college)
if (!empty($user_college)) {
    $stmt = $conn->prepare("SELECT user_id, first_name, last_name, college FROM users WHERE role = 'adviser' AND college = ? AND is_active = 1 ORDER BY first_name, last_name");
    $stmt->execute([$user_college]);
    $advisers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $advisers = [];
    $error = "Please update your profile with your college information first.";
}

// Year levels
$year_levels = ['Second Year','Third Year', 'Fourth Year'];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $group_name = trim($_POST['group_name'] ?? '');
    $adviser_id = $_POST['adviser_id'] ?? '';
    $year_level = $_POST['year_level'] ?? '';
    
    // Use user's registered college and department (not from form to prevent manipulation)
    $college = $user_college;
    $program = $user_department;

    if (empty($group_name) || empty($adviser_id) || empty($college) || empty($program) || empty($year_level)) {
        $error = "All required fields must be filled. Please ensure your profile has college and program information.";
    } else {
        // Validate adviser is from same college (user's registered college)
        $stmt = $conn->prepare("SELECT college FROM users WHERE user_id = ? AND role = 'adviser'");
        $stmt->execute([$adviser_id]);
        $adviser_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$adviser_info || $adviser_info['college'] !== $college) {
            $error = "Selected adviser must be from the same college as your group";
        } else {
            try {
                $conn->beginTransaction();

                // Insert research group
                $sql = "INSERT INTO research_groups (group_name, lead_student_id, adviser_id, college, program, year_level, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$group_name, $_SESSION['user_id'], $adviser_id, $college, $program, $year_level]);

                $group_id = $conn->lastInsertId();

                // Insert group leader as member using group_memberships table
                $sql = "INSERT INTO group_memberships (group_id, user_id, is_registered_user, join_date) VALUES (?, ?, 1, NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$group_id, $_SESSION['user_id']]);

                // Handle additional members - store as information only (no user accounts)
                if (isset($_POST['member_names']) && is_array($_POST['member_names'])) {
                    $member_student_numbers = $_POST['member_student_numbers'] ?? [];
                    
                    foreach ($_POST['member_names'] as $index => $name) {
                        $name = trim($name);
                        $student_number = trim($member_student_numbers[$index] ?? '');
                        
                        if (!empty($name) && !empty($student_number)) {
                            // Check if this student_number already exists in users table
                            $check_user_sql = "SELECT user_id, first_name, last_name FROM users WHERE student_id = ?";
                            $check_stmt = $conn->prepare($check_user_sql);
                            $check_stmt->execute([$student_number]);
                            $existing_user = $check_stmt->fetch();

                            if ($existing_user) {
                                $conn->rollBack();
                                $error = "Student number $student_number is already registered to " . 
                                         $existing_user['first_name'] . " " . $existing_user['last_name'] . 
                                         ". Please verify the correct student number.";
                                break;
                            }

                            // Check if this student_number already exists in group_memberships table
                            $check_member_sql = "SELECT member_name FROM group_memberships WHERE student_number = ?";
                            $check_member_stmt = $conn->prepare($check_member_sql);
                            $check_member_stmt->execute([$student_number]);
                            $existing_member = $check_member_stmt->fetch();

                            if ($existing_member) {
                                $conn->rollBack();
                                $error = "Student number $student_number is already assigned to another group member (" . 
                                         $existing_member['member_name'] . "). Please verify the correct student number.";
                                break;
                            }

                            // Store member information without creating user accounts
                            $sql = "INSERT INTO group_memberships (group_id, user_id, member_name, student_number, is_registered_user, join_date) VALUES (?, NULL, ?, ?, 0, NOW())";
                            $stmt = $conn->prepare($sql);
                            $stmt->execute([$group_id, $name, $student_number]);
                        } elseif (!empty($name)) {
                            // Store member information without student number
                            $sql = "INSERT INTO group_memberships (group_id, user_id, member_name, student_number, is_registered_user, join_date) VALUES (?, NULL, ?, NULL, 0, NOW())";
                            $stmt = $conn->prepare($sql);
                            $stmt->execute([$group_id, $name]);
                        }
                    }
                }

                // Only proceed if no errors occurred during member validation
                if (empty($error)) {
                    // Create notification for adviser
                    $sql = "INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (?, ?, ?, ?, NOW())";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        $adviser_id,
                        'New Research Group Assigned',
                        'You have been assigned as adviser to a new research group: ' . $group_name,
                        'group_assignment'
                    ]);

                    $conn->commit();
                    $success = "Research group created successfully!";

                    echo "<script>setTimeout(function() { window.location.href = 'dashboard.php?success=group_created'; }, 2000);</script>";
                }
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Error creating group: " . $e->getMessage();
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
    <title>Create Research Group - ESSU Research System</title>
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

        /* Dashboard Sections */
        .dashboard-section {
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            overflow: hidden;
        }

        .section-header {
            background: linear-gradient(90deg, var(--success-green), #047857);
            color: white;
            padding: 1rem 1.5rem;
            font-weight: 600;
            font-size: 1rem;
        }

        .section-body {
            padding: 1.5rem;
        }

        /* Form Styling */
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

        /* Buttons */
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

        .btn-outline-danger {
            border: 1px solid var(--danger-red);
            color: var(--danger-red);
            background: transparent;
        }

        .btn-outline-danger:hover {
            background: var(--danger-red);
            color: white;
        }

        /* Alerts */
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

        .alert-danger {
            background: #fef2f2;
            color: #dc2626;
            border-left: 4px solid var(--danger-red);
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

        /* College Info Box */
        .college-info {
            background: #f0f9ff;
            border-left: 4px solid #0ea5e9;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        /* Member Fields */
        .member-field {
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid var(--border-light);
            margin-bottom: 0.75rem;
            padding: 0.5rem;
        }

        /* RESPONSIVE - TABLET (768px and below) */
        @media (max-width: 768px) {
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
            
            .member-field {
                padding: 0.75rem;
            }
            
            .member-field .row {
                row-gap: 0.5rem;
            }
            
            .college-info {
                padding: 0.875rem;
            }
        }

        /* RESPONSIVE - MOBILE (576px and below) */
        @media (max-width: 576px) {
            .university-brand {
                flex-direction: row;
                text-align: left;
            }
            
            .university-logo {
                width: 35px;
                height: 35px;
                margin-right: 10px;
            }
            
            .university-name {
                font-size: 0.85rem;
            }
            
            .user-info {
                display: none;
            }
            
            .user-avatar {
                width: 32px;
                height: 32px;
            }
            
            /* Enhanced Navigation Tabs - Same as other pages */
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
            
            .alert {
                font-size: 0.85rem;
                padding: 0.75rem;
            }
            
            .college-info {
                padding: 0.75rem;
            }
            
            .college-info h6 {
                font-size: 0.95rem;
            }
            
            .college-info p {
                font-size: 0.85rem;
            }
            
            .college-info small {
                font-size: 0.75rem;
            }
            
            /* Member fields responsive */
            .member-field {
                padding: 0.75rem;
                margin-bottom: 0.5rem;
            }
            
            .member-field .row {
                row-gap: 0.5rem;
            }
            
            .member-field .col-md-8,
            .member-field .col-md-3,
            .member-field .col-md-1 {
                width: 100%;
                padding-right: 0;
                padding-left: 0;
            }
            
            .member-field .input-group-text {
                padding: 0.5rem 0.75rem;
            }
            
            .member-field .remove-member {
                width: 100%;
                margin-top: 0.25rem;
            }
            
            /* Form buttons responsive */
            .d-flex.gap-2.justify-content-between {
                flex-direction: column;
                gap: 0.5rem !important;
            }
            
            .d-flex.gap-2.justify-content-between .btn {
                width: 100%;
            }
            
            /* Year level, college, program row */
            .row.mb-4 .col-md-4 {
                margin-bottom: 1rem !important;
            }
            
            .form-text {
                font-size: 0.8rem;
            }
            
            .duplicate-error {
                font-size: 0.75rem;
            }
        }

        /* EXTRA SMALL (450px and below) */
        @media (max-width: 450px) {
            .page-title {
                font-size: 1.1rem;
            }
            
            .page-subtitle {
                font-size: 0.8rem;
            }
            
            .university-name {
                font-size: 0.8rem;
            }
            
            .section-header {
                font-size: 0.8rem;
                padding: 0.65rem 0.85rem;
            }
            
            .form-label {
                font-size: 0.85rem;
            }
            
            .form-control, .form-select {
                font-size: 0.85rem;
                padding: 0.55rem;
            }
            
            .btn {
                font-size: 0.85rem;
                padding: 0.55rem 0.9rem;
            }
            
            .btn i {
                font-size: 0.9rem;
            }
            
            .alert {
                font-size: 0.8rem;
                padding: 0.65rem;
            }
            
            .college-info h6 {
                font-size: 0.9rem;
            }
            
            .college-info p {
                font-size: 0.8rem;
            }
            
            .member-field {
                padding: 0.65rem;
            }
            
            .input-group-text {
                font-size: 0.85rem;
            }
        }

        /* LANDSCAPE MODE */
        @media (max-width: 768px) and (orientation: landscape) {
            .page-header {
                padding: 1rem;
                margin-bottom: 0.75rem;
            }
            
            .main-content {
                padding: 0.75rem 0;
            }
            
            .section-body {
                padding: 0.875rem;
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
                
                <div class="dropdown">
                    <a href="#" class="user-profile dropdown-toggle" data-bs-toggle="dropdown">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($_SESSION['full_name'], 0, 2)); ?>
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
            </ul>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Create Research Group</h1>
            <p class="page-subtitle">Set up your research team and connect with your adviser</p>
        </div>

        <?php if (!empty($user_college) && !empty($user_department)): ?>
            <div class="college-info">
                <h6 class="text-info mb-2">
                    <i class="bi bi-building me-2"></i>Your Academic Information
                </h6>
                <p class="mb-1">College: <strong><?php echo htmlspecialchars($user_college); ?></strong></p>
                <p class="mb-0">Program: <strong><?php echo htmlspecialchars($user_department); ?></strong></p>
                <small class="text-muted">Only advisers from your college will be available for selection.</small>
            </div>
        <?php elseif (!empty($user_college)): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <strong>Incomplete Profile Information</strong><br>
                Your profile has college information but is missing program details. Please update your profile to include your program/department before creating a group.
                <a href="profile.php" class="btn btn-outline-warning btn-sm ms-2">Update Profile</a>
            </div>
        <?php else: ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <strong>Missing Profile Information</strong><br>
                Please update your profile with your college and program information before creating a research group.
                <a href="profile.php" class="btn btn-outline-warning btn-sm ms-2">Update Profile</a>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i><?php echo $success; ?>
                <p class="mb-0 mt-2"><small>Redirecting to dashboard...</small></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (empty($advisers) && !empty($user_college)): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <strong>No Advisers Available</strong><br>
                There are currently no active advisers available from your college (<?php echo htmlspecialchars($user_college); ?>). 
                Please contact the administration to assign advisers to your college.
            </div>
        <?php endif; ?>

        <?php if (!empty($advisers) && !empty($user_college) && !empty($user_department)): ?>
        <div class="dashboard-section">
            <div class="section-header">
                <i class="bi bi-plus-circle me-2"></i>Create New Research Group
            </div>
            <div class="section-body">
                <form method="POST" id="groupForm">
                    <div class="mb-4">
                        <label for="group_name" class="form-label">
                            <i class="bi bi-tag me-1"></i>Group Name <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="group_name" name="group_name"
                            placeholder="e.g., AI Innovation Team" required
                            value="<?php echo htmlspecialchars($_POST['group_name'] ?? ''); ?>">
                        <div class="form-text">Choose a descriptive name for your research group</div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-4">
                            <label for="college" class="form-label">
                                <i class="bi bi-building me-1"></i>College <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($user_college); ?>" 
                                   readonly style="background-color: #f8f9fa; cursor: not-allowed;">
                            <input type="hidden" name="college" value="<?php echo htmlspecialchars($user_college); ?>">
                        </div>
                        <div class="col-md-4 mb-4">
                            <label for="program" class="form-label">
                                <i class="bi bi-mortarboard me-1"></i>Program <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($user_department); ?>" 
                                   readonly style="background-color: #f8f9fa; cursor: not-allowed;">
                            <input type="hidden" name="program" value="<?php echo htmlspecialchars($user_department); ?>">
                        </div>
                        <div class="col-md-4 mb-4">
                            <label for="year_level" class="form-label">
                                <i class="bi bi-calendar-check me-1"></i>Year Level <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" id="year_level" name="year_level" required>
                                <option value="">Select year level</option>
                                <?php foreach ($year_levels as $year): ?>
                                    <option value="<?php echo htmlspecialchars($year); ?>"
                                        <?php echo (isset($_POST['year_level']) && $_POST['year_level'] == $year) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($year); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="adviser_id" class="form-label">
                            <i class="bi bi-person-badge me-1"></i>Research Adviser <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" id="adviser_id" name="adviser_id" required>
                            <option value="">Choose your research adviser</option>
                            <?php foreach ($advisers as $adviser): ?>
                                <option value="<?php echo $adviser['user_id']; ?>"
                                    <?php echo (isset($_POST['adviser_id']) && $_POST['adviser_id'] == $adviser['user_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($adviser['first_name'] . ' ' . $adviser['last_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Only advisers from your college are shown</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">
                            <i class="bi bi-people me-1"></i>Group Members (excluding yourself)
                        </label>
                        <div id="members-container">
                            <div class="member-field">
                                <div class="row">
                                    <div class="col-md-8">
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-0">
                                                <i class="bi bi-person"></i>
                                            </span>
                                            <input type="text" class="form-control border-0" name="member_names[]"
                                                placeholder="Full Name of Group Member">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <input type="text" class="form-control" name="member_student_numbers[]"
                                            placeholder="Student Number">
                                    </div>
                                    <div class="col-md-1">
                                        <button type="button" class="btn btn-outline-danger remove-member w-100" disabled>
                                            <i class="bi bi-dash-circle"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-outline-primary btn-sm" id="add-member">
                            <i class="bi bi-plus-circle me-1"></i>Add Another Member
                        </button>
                        <div class="form-text">Add as many members as needed for your research group</div>
                    </div>

                    <div class="d-flex gap-2 justify-content-between">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="bi bi-check-circle me-2"></i>Create Research Group
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.getElementById('members-container');
            const addButton = document.getElementById('add-member');
            const groupForm = document.getElementById('groupForm');

            // Function to check for duplicates
            function checkDuplicates() {
                const memberFields = container.querySelectorAll('.member-field');
                const names = [];
                const studentNumbers = [];
                let hasDuplicates = false;

                // Clear previous error states
                memberFields.forEach(field => {
                    const nameInput = field.querySelector('input[name="member_names[]"]');
                    const numberInput = field.querySelector('input[name="member_student_numbers[]"]');
                    const errorDiv = field.querySelector('.duplicate-error');
                    
                    nameInput.classList.remove('is-invalid');
                    numberInput.classList.remove('is-invalid');
                    if (errorDiv) errorDiv.remove();
                });

                // Check each field for duplicates
                memberFields.forEach((field, index) => {
                    const nameInput = field.querySelector('input[name="member_names[]"]');
                    const numberInput = field.querySelector('input[name="member_student_numbers[]"]');
                    const name = nameInput.value.trim().toLowerCase();
                    const studentNumber = numberInput.value.trim();

                    // Check for duplicate names
                    if (name && names.includes(name)) {
                        nameInput.classList.add('is-invalid');
                        showError(field, 'Duplicate name found');
                        hasDuplicates = true;
                    } else if (name) {
                        names.push(name);
                    }

                    // Check for duplicate student numbers
                    if (studentNumber && studentNumbers.includes(studentNumber)) {
                        numberInput.classList.add('is-invalid');
                        showError(field, 'Duplicate student number found');
                        hasDuplicates = true;
                    } else if (studentNumber) {
                        studentNumbers.push(studentNumber);
                    }
                });

                return !hasDuplicates;
            }

            // Function to show error message
            function showError(field, message) {
                let errorDiv = field.querySelector('.duplicate-error');
                if (!errorDiv) {
                    errorDiv = document.createElement('div');
                    errorDiv.className = 'duplicate-error text-danger small mt-1';
                    field.appendChild(errorDiv);
                }
                errorDiv.textContent = message;
            }

            // Function to validate on input
            function validateInput(input) {
                setTimeout(checkDuplicates, 100); // Small delay to allow input to update
            }

            // Add event listeners to existing inputs
            function addValidationListeners() {
                const nameInputs = container.querySelectorAll('input[name="member_names[]"]');
                const numberInputs = container.querySelectorAll('input[name="member_student_numbers[]"]');
                
                nameInputs.forEach(input => {
                    input.removeEventListener('input', validateInput);
                    input.addEventListener('input', validateInput);
                });
                
                numberInputs.forEach(input => {
                    input.removeEventListener('input', validateInput);
                    input.addEventListener('input', validateInput);
                });
            }

            if (addButton) {
                addButton.addEventListener('click', function() {
                    const div = document.createElement('div');
                    div.className = 'member-field';
                    div.innerHTML = `
                        <div class="row">
                            <div class="col-md-8">
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-0">
                                        <i class="bi bi-person"></i>
                                    </span>
                                    <input type="text" class="form-control border-0" name="member_names[]"
                                        placeholder="Full Name of Group Member">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <input type="text" class="form-control" name="member_student_numbers[]"
                                    placeholder="Student Number">
                            </div>
                            <div class="col-md-1">
                                <button type="button" class="btn btn-outline-danger remove-member w-100">
                                    <i class="bi bi-dash-circle"></i>
                                </button>
                            </div>
                        </div>
                    `;
                    container.appendChild(div);
                    updateRemoveButtons();
                    addValidationListeners();
                });

                container.addEventListener('click', function(e) {
                    if (e.target.closest('.remove-member')) {
                        const memberField = e.target.closest('.member-field');
                        memberField.remove();
                        updateRemoveButtons();
                        checkDuplicates(); // Recheck after removal
                    }
                });

                function updateRemoveButtons() {
                    const memberFields = container.querySelectorAll('.member-field');
                    const removeButtons = container.querySelectorAll('.remove-member');

                    removeButtons.forEach(button => {
                        button.disabled = memberFields.length <= 1;
                    });
                }

                // Add validation to initial field
                addValidationListeners();
            }

            if (groupForm) {
                groupForm.addEventListener('submit', function(e) {
                    const isValid = checkDuplicates();
                    
                    if (!isValid) {
                        e.preventDefault();
                        
                        // Show alert message
                        let alertDiv = document.querySelector('.duplicate-alert');
                        if (!alertDiv) {
                            alertDiv = document.createElement('div');
                            alertDiv.className = 'alert alert-danger alert-dismissible fade show duplicate-alert';
                            alertDiv.innerHTML = `
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                Please fix duplicate names or student numbers before submitting.
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            `;
                            groupForm.insertBefore(alertDiv, groupForm.firstChild);
                        }
                        
                        // Scroll to top to show error
                        alertDiv.scrollIntoView({ behavior: 'smooth' });
                        return false;
                    }
                    
                    // Remove any existing duplicate alert
                    const existingAlert = document.querySelector('.duplicate-alert');
                    if (existingAlert) {
                        existingAlert.remove();
                    }
                    
                    const submitBtn = this.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Creating Group...';
                    }
                });
            }
        });
        
    </script>
</body>
</html>