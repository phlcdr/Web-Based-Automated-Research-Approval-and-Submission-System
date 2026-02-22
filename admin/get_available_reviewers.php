<?php
session_start();
include_once '../config/database.php';
include_once '../includes/functions.php';

is_logged_in();
check_role(['admin']);

header('Content-Type: application/json');

$college = isset($_GET['college']) ? $_GET['college'] : '';

if (empty($college)) {
    echo json_encode(['success' => false, 'message' => 'College not specified']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT user_id, first_name, last_name, email, role
        FROM users
        WHERE (role = 'adviser' OR role = 'panel')
        AND college = ?
        AND is_active = 1
        AND registration_status = 'approved'
        ORDER BY role DESC, last_name, first_name
    ");
    $stmt->execute([$college]);
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