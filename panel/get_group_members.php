<?php
// panel/get_group_members.php - Updated to work with normalized database
session_start();

include_once '../config/database.php';
include_once '../includes/functions.php';

// Check if user is logged in and has appropriate role
is_logged_in();
check_role(['adviser', 'panel']);

// Set content type to JSON
header('Content-Type: application/json');

try {
    $group_id = $_GET['group_id'] ?? null;
    
    if (!$group_id || !is_numeric($group_id)) {
        throw new Exception('Invalid group ID provided');
    }
    
    // Verify that this adviser/panel member has access to this group
    $user_id = $_SESSION['user_id'];
    $role = $_SESSION['role'];
    
    if ($role === 'adviser') {
        $stmt = $conn->prepare("SELECT 1 FROM research_groups WHERE group_id = ? AND adviser_id = ?");
        $stmt->execute([$group_id, $user_id]);
        if (!$stmt->fetch()) {
            throw new Exception('Access denied to this research group');
        }
    }
    
    // Get group members - Updated to use group_memberships table
    $stmt = $conn->prepare("
        SELECT 
            CASE 
                WHEN gm.is_registered_user = 1 THEN u.user_id 
                ELSE NULL 
            END as user_id,
            CASE 
                WHEN gm.is_registered_user = 1 THEN u.first_name 
                ELSE SUBSTRING_INDEX(gm.member_name, ' ', 1)
            END as first_name,
            CASE 
                WHEN gm.is_registered_user = 1 THEN u.last_name 
                ELSE CASE 
                    WHEN LOCATE(' ', gm.member_name) > 0 
                    THEN SUBSTRING(gm.member_name, LOCATE(' ', gm.member_name) + 1)
                    ELSE ''
                END
            END as last_name,
            CASE 
                WHEN gm.is_registered_user = 1 THEN u.email 
                ELSE 'Not registered in system'
            END as email,
            CASE 
                WHEN gm.is_registered_user = 1 THEN u.student_id 
                ELSE gm.student_number
            END as student_id,
            gm.join_date,
            CASE 
                WHEN rg.lead_student_id = gm.user_id AND gm.is_registered_user = 1 THEN 'Leader' 
                ELSE 'Member' 
            END as role,
            (SELECT COUNT(*) FROM submissions s 
             WHERE s.group_id = ? AND s.submission_type = 'chapter' AND s.status = 'approved') as approved_chapters,
            (SELECT COUNT(*) FROM submissions s 
             WHERE s.group_id = ? AND s.submission_type = 'chapter' AND s.status = 'pending') as pending_chapters,
            (SELECT COUNT(*) FROM submissions s
             WHERE s.group_id = ? AND s.submission_type = 'title' AND s.status = 'approved') as approved_titles,
            CASE 
                WHEN gm.is_registered_user = 1 THEN u.is_active 
                ELSE 1
            END as is_active,
            gm.is_registered_user,
            CASE 
                WHEN gm.is_registered_user = 1 THEN u.college 
                ELSE rg.college
            END as college
        FROM group_memberships gm
        LEFT JOIN users u ON gm.user_id = u.user_id AND gm.is_registered_user = 1
        LEFT JOIN research_groups rg ON gm.group_id = rg.group_id
        WHERE gm.group_id = ?
        ORDER BY 
            CASE WHEN rg.lead_student_id = gm.user_id AND gm.is_registered_user = 1 THEN 0 ELSE 1 END,
            CASE WHEN gm.is_registered_user = 1 THEN u.first_name ELSE gm.member_name END
    ");
    
    $stmt->execute([$group_id, $group_id, $group_id, $group_id]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convert numeric strings to integers for JSON
    foreach ($members as &$member) {
        $member['approved_chapters'] = (int)$member['approved_chapters'];
        $member['pending_chapters'] = (int)$member['pending_chapters'];
        $member['approved_titles'] = (int)$member['approved_titles'];
        $member['is_active'] = (bool)$member['is_active'];
        $member['is_registered_user'] = (bool)$member['is_registered_user'];
    }
    
    // Get group information
    $stmt = $conn->prepare("
        SELECT rg.group_name, rg.college, rg.program, rg.created_at,
               CONCAT(u.first_name, ' ', u.last_name) as adviser_name
        FROM research_groups rg
        LEFT JOIN users u ON rg.adviser_id = u.user_id
        WHERE rg.group_id = ?
    ");
    $stmt->execute([$group_id]);
    $group_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'members' => $members,
        'group_info' => $group_info,
        'total_members' => count($members),
        'registered_members' => count(array_filter($members, function($m) { return $m['is_registered_user']; })),
        'message' => 'Group members retrieved successfully'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => 'GROUP_MEMBERS_ERROR'
    ]);
}
?>