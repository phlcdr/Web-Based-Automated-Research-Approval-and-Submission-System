<?php
header('Content-Type: application/json');
session_start();

include_once '../config/database.php';
include_once '../includes/submission_functions.php';
include_once '../includes/functions.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Validate group_id parameter
if (!isset($_GET['group_id']) || !is_numeric($_GET['group_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid group ID']);
    exit();
}

$group_id = intval($_GET['group_id']);

try {
    // Get group details with statistics
    $group_sql = "SELECT 
                    rg.*,
                    CONCAT(lead.first_name, ' ', lead.last_name) as lead_name,
                    lead.email as lead_email,
                    lead.student_id as lead_student_id,
                    CONCAT(adviser.first_name, ' ', adviser.last_name) as adviser_name,
                    adviser.email as adviser_email,
                    COUNT(DISTINCT CASE WHEN s.submission_type = 'title' AND s.status = 'approved' THEN s.submission_id END) as approved_titles,
                    COUNT(DISTINCT CASE WHEN s.submission_type = 'chapter' AND s.status = 'approved' THEN s.submission_id END) as approved_chapters,
                    COUNT(DISTINCT CASE WHEN s.status = 'pending' THEN s.submission_id END) as pending_submissions,
                    COUNT(DISTINCT s.submission_id) as total_submissions
                  FROM research_groups rg
                  LEFT JOIN users lead ON rg.lead_student_id = lead.user_id
                  LEFT JOIN users adviser ON rg.adviser_id = adviser.user_id
                  LEFT JOIN submissions s ON rg.group_id = s.group_id
                  WHERE rg.group_id = ?
                  GROUP BY rg.group_id";
    
    $group_stmt = $conn->prepare($group_sql);
    $group_stmt->execute([$group_id]);
    $group = $group_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$group) {
        echo json_encode(['success' => false, 'message' => 'Group not found']);
        exit();
    }
    
    // Get group members
    $members_sql = "SELECT 
                      CASE 
                          WHEN gm.is_registered_user = 1 THEN CONCAT(u.first_name, ' ', u.last_name)
                          ELSE gm.member_name
                      END as member_name,
                      CASE 
                          WHEN gm.is_registered_user = 1 THEN u.student_id
                          ELSE gm.student_number
                      END as student_number,
                      CASE 
                          WHEN rg.lead_student_id = gm.user_id AND gm.is_registered_user = 1 THEN 1
                          ELSE 0
                      END as is_leader,
                      gm.is_registered_user,
                      u.user_id,
                      u.email
                    FROM group_memberships gm
                    LEFT JOIN users u ON gm.user_id = u.user_id AND gm.is_registered_user = 1
                    JOIN research_groups rg ON gm.group_id = rg.group_id
                    WHERE gm.group_id = ?
                    ORDER BY is_leader DESC, member_name";
    
    $members_stmt = $conn->prepare($members_sql);
    $members_stmt->execute([$group_id]);
    $members = $members_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get group submissions
    $submissions_sql = "SELECT 
                          s.*,
                          CASE 
                              WHEN s.chapter_number IS NOT NULL THEN CONCAT('Chapter ', s.chapter_number, ': ', s.title)
                              ELSE s.title
                          END as display_title
                        FROM submissions s
                        WHERE s.group_id = ?
                        ORDER BY s.created_at DESC";
    
    $submissions_stmt = $conn->prepare($submissions_sql);
    $submissions_stmt->execute([$group_id]);
    $submissions = $submissions_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format dates for better display
    foreach ($submissions as &$submission) {
        $submission['submission_date_formatted'] = date('M j, Y g:i A', strtotime($submission['created_at']));
        if ($submission['approval_date']) {
            $submission['approval_date_formatted'] = date('M j, Y g:i A', strtotime($submission['approval_date']));
        }
        // Use display_title instead of title for better formatting
        $submission['title'] = $submission['display_title'];
    }
    
    // Return success response with all data
    echo json_encode([
        'success' => true,
        'group' => $group,
        'members' => $members,
        'submissions' => $submissions
    ]);
    
} catch (PDOException $e) {
    error_log("Error fetching group progress: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred while fetching group details'
    ]);
} catch (Exception $e) {
    error_log("General error in get_group_progress.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while processing your request'
    ]);
}
?>