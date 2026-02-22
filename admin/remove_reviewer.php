<?php
session_start();
include_once '../config/database.php';
include_once '../includes/functions.php';

is_logged_in();
check_role(['admin']);

header('Content-Type: application/json');

$submission_id = isset($_POST['submission_id']) ? intval($_POST['submission_id']) : 0;
$user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;

if (!$submission_id || !$user_id) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

try {
    $conn->beginTransaction();
    
    // Check current reviewer count
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
    
    if ($current['count'] <= 3) {
        throw new Exception('Cannot remove reviewer. Minimum 3 reviewers required.');
    }
    
    // Get submission and user details for notification
    $stmt = $conn->prepare("
        SELECT s.title, s.group_id, rg.lead_student_id
        FROM submissions s
        JOIN research_groups rg ON s.group_id = rg.group_id
        WHERE s.submission_id = ?
    ");
    $stmt->execute([$submission_id]);
    $submission = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Remove the reviewer assignment
    $stmt = $conn->prepare("
        UPDATE assignments 
        SET is_active = 0 
        WHERE context_id = ? 
            AND context_type = 'submission' 
            AND assignment_type = 'reviewer'
            AND user_id = ?
    ");
    $stmt->execute([$submission_id, $user_id]);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception('Reviewer assignment not found');
    }
    
    // Notify the removed reviewer
    $notif_stmt = $conn->prepare("
        INSERT INTO notifications 
        (user_id, title, message, type, context_type, context_id, created_at)
        VALUES (?, ?, ?, 'reviewer_removed', 'submission', ?, NOW())
    ");
    $notif_stmt->execute([
        $user_id,
        'Removed from Review Assignment',
        "You have been removed as a reviewer for: {$submission['title']}",
        $submission_id
    ]);
    
    // Notify student
    if ($submission['lead_student_id']) {
        $student_notif = $conn->prepare("
            INSERT INTO notifications 
            (user_id, title, message, type, context_type, context_id, created_at)
            VALUES (?, ?, ?, 'reviewer_updated', 'submission', ?, NOW())
        ");
        $student_notif->execute([
            $submission['lead_student_id'],
            'Reviewer Removed',
            'A reviewer has been removed from your research title by the administrator.',
            $submission_id
        ]);
    }
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Reviewer removed successfully',
        'remaining_count' => $current['count'] - 1,
        'group_id' => $submission['group_id']
    ]);
    
} catch (Exception $e) {
    $conn->rollBack();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>