<?php
session_start();
include_once '../config/database.php';
include_once '../includes/functions.php';

is_logged_in();
check_role(['admin']);

header('Content-Type: application/json');

$group_id = isset($_GET['group_id']) ? intval($_GET['group_id']) : 0;

if (!$group_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid group ID']);
    exit;
}

try {
    // Get title submissions for this group WITH college info
    $stmt = $conn->prepare("
        SELECT 
            s.submission_id,
            s.title,
            s.created_at,
            s.status,
            rg.college,
            COUNT(DISTINCT a.assignment_id) as reviewer_count
        FROM submissions s
        JOIN research_groups rg ON s.group_id = rg.group_id
        LEFT JOIN assignments a ON s.submission_id = a.context_id 
            AND a.context_type = 'submission' 
            AND a.assignment_type = 'reviewer'
            AND a.is_active = 1
        WHERE s.group_id = ? AND s.submission_type = 'title'
        GROUP BY s.submission_id
        ORDER BY s.created_at DESC
    ");
    $stmt->execute([$group_id]);
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'submissions' => $submissions
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>