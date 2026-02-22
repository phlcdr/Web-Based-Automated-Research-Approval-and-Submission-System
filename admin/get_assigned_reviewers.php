<?php
session_start();
include_once '../config/database.php';
include_once '../includes/functions.php';

is_logged_in();
check_role(['admin']);

header('Content-Type: application/json');

$submission_id = isset($_GET['submission_id']) ? intval($_GET['submission_id']) : 0;

if (!$submission_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid submission ID']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT 
            u.user_id,
            u.first_name,
            u.last_name,
            u.email,
            a.role
        FROM assignments a
        JOIN users u ON a.user_id = u.user_id
        WHERE a.context_id = ?
            AND a.context_type = 'submission'
            AND a.assignment_type = 'reviewer'
            AND a.is_active = 1
        ORDER BY a.role DESC, u.last_name, u.first_name
    ");
    $stmt->execute([$submission_id]);
    $reviewers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'reviewers' => $reviewers
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>