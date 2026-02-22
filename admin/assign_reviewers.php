<?php
session_start();
include_once '../config/database.php';
include_once '../includes/functions.php';

is_logged_in();
check_role(['admin']);

header('Content-Type: application/json');

$submission_id = isset($_POST['submission_id']) ? intval($_POST['submission_id']) : 0;
$reviewers = isset($_POST['reviewers']) ? $_POST['reviewers'] : [];

if (!$submission_id) {
    echo json_encode(['success' => false, 'message' => 'Missing submission ID']);
    exit;
}

if (empty($reviewers)) {
    echo json_encode(['success' => false, 'message' => 'Please select at least one reviewer']);
    exit;
}

try {
    $conn->beginTransaction();
    
    // Get submission details
    $stmt = $conn->prepare("
        SELECT s.*, rg.group_name, rg.lead_student_id
        FROM submissions s
        JOIN research_groups rg ON s.group_id = rg.group_id
        WHERE s.submission_id = ?
    ");
    $stmt->execute([$submission_id]);
    $submission = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$submission) {
        throw new Exception('Submission not found');
    }
    
    // Get current reviewer count
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM assignments 
        WHERE context_id = ? 
            AND context_type = 'submission' 
            AND assignment_type = 'reviewer'
            AND is_active = 1
    ");
    $stmt->execute([$submission_id]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    $currentCount = $current['count'];
    
    // Check if total will be at least 3
    if ($currentCount + count($reviewers) < 3) {
        throw new Exception('Total reviewers must be at least 3. Currently assigned: ' . $currentCount);
    }
    
    // Insert new reviewer assignments (without deactivating existing ones)
    $stmt = $conn->prepare("
        INSERT INTO assignments 
        (assignment_type, context_type, context_id, user_id, role, is_active, assigned_date)
        VALUES ('reviewer', 'submission', ?, ?, ?, 1, NOW())
    ");
    
    $addedCount = 0;
    foreach ($reviewers as $reviewer_id) {
        $reviewer_id = intval($reviewer_id);
        
        // Check if already assigned
        $check = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM assignments 
            WHERE context_id = ? 
                AND context_type = 'submission' 
                AND assignment_type = 'reviewer'
                AND user_id = ?
                AND is_active = 1
        ");
        $check->execute([$submission_id, $reviewer_id]);
        $exists = $check->fetch(PDO::FETCH_ASSOC);
        
        if ($exists['count'] > 0) {
            continue; // Skip if already assigned
        }
        
        // Get reviewer's role
        $role_stmt = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
        $role_stmt->execute([$reviewer_id]);
        $reviewer = $role_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($reviewer) {
            $stmt->execute([
                $submission_id,
                $reviewer_id,
                $reviewer['role']
            ]);
            
            $addedCount++;
            
            // Create notification for reviewer
            $notif_stmt = $conn->prepare("
                INSERT INTO notifications 
                (user_id, title, message, type, context_type, context_id, created_at)
                VALUES (?, ?, ?, 'title_assignment', 'submission', ?, NOW())
            ");
            $notif_stmt->execute([
                $reviewer_id,
                'New Research Title for Review',
                "The administrator has assigned you to review a research title: {$submission['title']}",
                $submission_id
            ]);
        }
    }
    
    // Notify student if new reviewers were added
    if ($addedCount > 0 && $submission['lead_student_id']) {
        $totalReviewers = $currentCount + $addedCount;
        $student_notif = $conn->prepare("
            INSERT INTO notifications 
            (user_id, title, message, type, context_type, context_id, created_at)
            VALUES (?, ?, ?, 'reviewer_assigned', 'submission', ?, NOW())
        ");
        $message = $currentCount > 0 
            ? "New reviewers have been assigned to your research title by the administrator. Your existing approvals ($currentCount) remain valid."
            : "Reviewers have been assigned to your research title by the administrator.";
        
        $student_notif->execute([
            $submission['lead_student_id'],
            'Reviewers Assigned',
            $message,
            $submission_id
        ]);
    }
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => "$addedCount reviewer(s) added successfully",
        'total_reviewers' => $currentCount + $addedCount
    ]);
    
} catch (Exception $e) {
    $conn->rollBack();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>