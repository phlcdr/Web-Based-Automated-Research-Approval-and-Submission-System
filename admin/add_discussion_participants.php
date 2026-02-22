<?php
session_start();
include_once '../config/database.php';
include_once '../includes/functions.php';

is_logged_in();
check_role(['admin']);

header('Content-Type: application/json');

$discussion_id = isset($_POST['discussion_id']) ? intval($_POST['discussion_id']) : 0;
$participants = isset($_POST['participants']) ? $_POST['participants'] : [];

if (!$discussion_id || empty($participants)) {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit;
}

try {
    $conn->beginTransaction();
    
    $added_count = 0;
    foreach ($participants as $user_id) {
        // Get user role
        $stmt = $conn->prepare("SELECT role FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $user_role = $stmt->fetchColumn();
        
        if (!$user_role) continue;
        
        // Check if already exists
        $stmt = $conn->prepare("
            SELECT assignment_id, is_active 
            FROM assignments 
            WHERE context_type = 'discussion' 
            AND context_id = ? 
            AND user_id = ?
        ");
        $stmt->execute([$discussion_id, $user_id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            if (!$existing['is_active']) {
                // Reactivate
                $stmt = $conn->prepare("
                    UPDATE assignments 
                    SET is_active = 1, assigned_date = NOW() 
                    WHERE assignment_id = ?
                ");
                $stmt->execute([$existing['assignment_id']]);
                $added_count++;
            }
        } else {
            // Insert new
            $stmt = $conn->prepare("
                INSERT INTO assignments (assignment_type, context_type, context_id, user_id, role) 
                VALUES ('participant', 'discussion', ?, ?, ?)
            ");
            $stmt->execute([$discussion_id, $user_id, $user_role]);
            $added_count++;
        }
        
        // Notify participant
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, type, context_type, context_id) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user_id,
            'Added to Thesis Discussion',
            'You have been added to a thesis discussion by the administrator.',
            'discussion_added',
            'discussion',
            $discussion_id
        ]);
    }
    
    $conn->commit();
    
    echo json_encode(['success' => true, 'message' => "$added_count participant(s) added successfully"]);
} catch (Exception $e) {
    $conn->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>