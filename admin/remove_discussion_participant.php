<?php
session_start();
include_once '../config/database.php';
include_once '../includes/functions.php';

is_logged_in();
check_role(['admin']);

header('Content-Type: application/json');

$discussion_id = isset($_POST['discussion_id']) ? intval($_POST['discussion_id']) : 0;
$assignment_id = isset($_POST['assignment_id']) ? intval($_POST['assignment_id']) : 0;

if (!$discussion_id || !$assignment_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit;
}

try {
    // Deactivate assignment
    $stmt = $conn->prepare("
        UPDATE assignments 
        SET is_active = 0 
        WHERE assignment_id = ? 
        AND context_type = 'discussion' 
        AND context_id = ?
    ");
    $stmt->execute([$assignment_id, $discussion_id]);
    
    echo json_encode(['success' => true, 'message' => 'Participant removed successfully']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>