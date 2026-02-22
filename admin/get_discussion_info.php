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
    // Get group info INCLUDING adviser_id
    $stmt = $conn->prepare("SELECT * FROM research_groups WHERE group_id = ?");
    $stmt->execute([$group_id]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$group) {
        throw new Exception('Group not found');
    }
    
    // Store the primary adviser_id for later use
    $primary_adviser_id = $group['adviser_id'];
    
    // Check if Chapter 3 is approved
    $stmt = $conn->prepare("
        SELECT * FROM submissions 
        WHERE group_id = ? 
        AND submission_type = 'chapter' 
        AND chapter_number = 3 
        AND status = 'approved'
    ");
    $stmt->execute([$group_id]);
    $chapter3 = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$chapter3) {
        echo json_encode([
            'success' => false,
            'message' => 'Discussion cannot be managed until Chapter 3 is approved.'
        ]);
        exit;
    }
    
    // Get or create discussion
    $stmt = $conn->prepare("SELECT * FROM thesis_discussions WHERE group_id = ?");
    $stmt->execute([$group_id]);
    $discussion = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$discussion) {
        echo json_encode([
            'success' => false,
            'message' => 'No discussion has been created yet. It will be auto-created when a student accesses the discussion page.'
        ]);
        exit;
    }
    
    // CRITICAL: Add the primary adviser_id to the discussion data
    $discussion['adviser_id'] = $primary_adviser_id;
    
    // Get current participants with explicit user_id
    $stmt = $conn->prepare("
        SELECT 
            a.assignment_id,
            a.user_id,
            u.first_name, 
            u.last_name, 
            u.role as user_role
        FROM assignments a
        JOIN users u ON a.user_id = u.user_id
        WHERE a.context_type = 'discussion'
        AND a.context_id = ?
        AND a.is_active = 1
        ORDER BY u.role, u.first_name
    ");
    $stmt->execute([$discussion['discussion_id']]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get available participants (advisers and panel from same college, not already added)
    $current_ids = array_column($participants, 'user_id');
    $placeholders = !empty($current_ids) ? str_repeat('?,', count($current_ids) - 1) . '?' : '';
    
    if (!empty($current_ids)) {
        $stmt = $conn->prepare("
            SELECT user_id, first_name, last_name, role, college
            FROM users
            WHERE (role = 'adviser' OR role = 'panel')
            AND college = ?
            AND is_active = 1
            AND registration_status = 'approved'
            AND user_id NOT IN ($placeholders)
            ORDER BY role DESC, first_name
        ");
        $params = array_merge([$group['college']], $current_ids);
        $stmt->execute($params);
    } else {
        $stmt = $conn->prepare("
            SELECT user_id, first_name, last_name, role, college
            FROM users
            WHERE (role = 'adviser' OR role = 'panel')
            AND college = ?
            AND is_active = 1
            AND registration_status = 'approved'
            ORDER BY role DESC, first_name
        ");
        $stmt->execute([$group['college']]);
    }
    
    $available = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'discussion' => $discussion,
        'participants' => $participants,
        'available' => $available
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>