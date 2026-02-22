<?php
/**
 * Submission Functions for Normalized Database
 * Contains all helper functions for the new unified submission system
 */

/**
 * Get submission by ID with all related data (FIXED to include adviser_id)
 */
function get_submission_by_id($conn, $submission_id) {
    $stmt = $conn->prepare("
        SELECT s.*, 
               rg.group_name,
               rg.college,
               rg.program,
               rg.year_level,
               rg.adviser_id,
               CONCAT(u.first_name, ' ', u.last_name) as submitter_name,
               u.email as submitter_email,
               u.role as submitter_role
        FROM submissions s
        LEFT JOIN research_groups rg ON s.group_id = rg.group_id
        LEFT JOIN users u ON rg.lead_student_id = u.user_id
        WHERE s.submission_id = ?
    ");
    $stmt->execute([$submission_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get all submissions with filtering
 */
function get_submissions($conn, $filters = []) {
    $where_conditions = [];
    $params = [];
    
    $sql = "SELECT s.*, 
               rg.group_name,
               rg.college,
               rg.program,
               rg.adviser_id,
               CONCAT(u.first_name, ' ', u.last_name) as submitter_name,
               u.email as submitter_email
            FROM submissions s
            LEFT JOIN research_groups rg ON s.group_id = rg.group_id
            LEFT JOIN users u ON rg.lead_student_id = u.user_id";
    
    // Apply filters
    if (!empty($filters['status']) && $filters['status'] !== 'all') {
        $where_conditions[] = "s.status = ?";
        $params[] = $filters['status'];
    }
    
    if (!empty($filters['type']) && $filters['type'] !== 'all') {
        $type_value = $filters['type'] === 'titles' ? 'title' : 'chapter';
        $where_conditions[] = "s.submission_type = ?";
        $params[] = $type_value;
    }
    
    if (!empty($filters['search'])) {
        $where_conditions[] = "(s.title LIKE ? OR s.description LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
        $search_param = "%{$filters['search']}%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    if (!empty($where_conditions)) {
        $sql .= " WHERE " . implode(" AND ", $where_conditions);
    }
    
    $sql .= " ORDER BY s.submission_date DESC";
    
    // Add pagination if provided
    if (!empty($filters['limit'])) {
        $sql .= " LIMIT " . intval($filters['limit']);
        if (!empty($filters['offset'])) {
            $sql .= " OFFSET " . intval($filters['offset']);
        }
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get submission statistics
 */
function get_submission_statistics($conn) {
    try {
        $stmt = $conn->query("
            SELECT 
                COUNT(CASE WHEN submission_type = 'title' THEN 1 END) as total_titles,
                COUNT(CASE WHEN submission_type = 'chapter' THEN 1 END) as total_chapters,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
                COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved,
                COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected
            FROM submissions
        ");
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return ['total_titles' => 0, 'total_chapters' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
    }
}

/**
 * Submit new research title
 */
function submit_research_title($conn, $group_id, $title, $description, $document_path = null) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO submissions (group_id, submission_type, title, description, document_path) 
            VALUES (?, 'title', ?, ?, ?)
        ");
        $stmt->execute([$group_id, $title, $description, $document_path]);
        return $conn->lastInsertId();
    } catch (PDOException $e) {
        error_log("Error submitting title: " . $e->getMessage());
        return false;
    }
}

/**
 * Submit new chapter
 */
function submit_chapter($conn, $group_id, $chapter_number, $title, $document_path = null) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO submissions (group_id, submission_type, title, chapter_number, document_path) 
            VALUES (?, 'chapter', ?, ?, ?)
        ");
        $stmt->execute([$group_id, $title, $chapter_number, $document_path]);
        return $conn->lastInsertId();
    } catch (PDOException $e) {
        error_log("Error submitting chapter: " . $e->getMessage());
        return false;
    }
}

/**
 * Get reviews for a submission
 */
function get_submission_reviews($conn, $submission_id) {
    $stmt = $conn->prepare("
        SELECT r.*, 
               CONCAT(u.first_name, ' ', u.last_name) as reviewer_name,
               u.role as reviewer_role,
               u.email as reviewer_email
        FROM reviews r
        JOIN users u ON r.reviewer_id = u.user_id
        WHERE r.submission_id = ?
        ORDER BY r.review_date DESC
    ");
    $stmt->execute([$submission_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Add review to submission
 */
function add_submission_review($conn, $submission_id, $reviewer_id, $comments, $decision) {
    try {
        $conn->beginTransaction();
        
        // Add the review
        $stmt = $conn->prepare("
            INSERT INTO reviews (submission_id, reviewer_id, comments, decision) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$submission_id, $reviewer_id, $comments, $decision]);
        
        // Update submission status based on decision
        if ($decision === 'approve') {
            // Check if we have enough approvals
            $stmt = $conn->prepare("
                SELECT COUNT(*) as approved_count, s.required_approvals
                FROM reviews r
                JOIN submissions s ON r.submission_id = s.submission_id
                WHERE r.submission_id = ? AND r.decision = 'approve'
                GROUP BY s.required_approvals
            ");
            $stmt->execute([$submission_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result && $result['approved_count'] >= $result['required_approvals']) {
                $stmt = $conn->prepare("
                    UPDATE submissions 
                    SET status = 'approved', approval_date = NOW() 
                    WHERE submission_id = ?
                ");
                $stmt->execute([$submission_id]);
            }
        } elseif ($decision === 'reject') {
            // Single rejection rejects the entire submission
            $stmt = $conn->prepare("
                UPDATE submissions 
                SET status = 'rejected' 
                WHERE submission_id = ?
            ");
            $stmt->execute([$submission_id]);
        }
        
        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error adding review: " . $e->getMessage());
        return false;
    }
}

/**
 * Get messages for a submission
 */
function get_submission_messages($conn, $submission_id) {
    $stmt = $conn->prepare("
        SELECT m.*, 
               CONCAT(u.first_name, ' ', u.last_name) as sender_name,
               u.role as sender_role
        FROM messages m
        JOIN users u ON m.user_id = u.user_id
        WHERE m.context_type = 'submission' AND m.context_id = ?
        ORDER BY m.created_at ASC
    ");
    $stmt->execute([$submission_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Add message to submission
 */
function add_submission_message($conn, $submission_id, $user_id, $message_text, $file_path = null, $original_filename = null) {
    try {
        $message_type = !empty($file_path) ? 'file' : 'text';
        
        $stmt = $conn->prepare("
            INSERT INTO messages (context_type, context_id, user_id, message_type, message_text, file_path, original_filename) 
            VALUES ('submission', ?, ?, ?, ?, ?, ?)
        ");
        return $stmt->execute([$submission_id, $user_id, $message_type, $message_text, $file_path, $original_filename]);
    } catch (PDOException $e) {
        error_log("Error adding message: " . $e->getMessage());
        return false;
    }
}

/**
 * Get user's group membership
 */
function get_user_group($conn, $user_id) {
    $stmt = $conn->prepare("
        SELECT rg.*, 
               CONCAT(adviser.first_name, ' ', adviser.last_name) as adviser_name,
               CASE WHEN rg.lead_student_id = ? THEN 1 ELSE 0 END as is_leader
        FROM group_memberships gm
        JOIN research_groups rg ON gm.group_id = rg.group_id
        LEFT JOIN users adviser ON rg.adviser_id = adviser.user_id
        WHERE gm.user_id = ? AND gm.is_registered_user = 1
        LIMIT 1
    ");
    $stmt->execute([$user_id, $user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get group members
 */
function get_group_members($conn, $group_id) {
    $stmt = $conn->prepare("
        SELECT 
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
            u.user_id
        FROM group_memberships gm
        LEFT JOIN users u ON gm.user_id = u.user_id AND gm.is_registered_user = 1
        JOIN research_groups rg ON gm.group_id = rg.group_id
        WHERE gm.group_id = ?
        ORDER BY is_leader DESC, member_name
    ");
    $stmt->execute([$group_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Record user activity
 */
function record_user_activity($conn, $user_id, $activity_type, $context_type, $context_id) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO user_activity (user_id, activity_type, context_type, context_id) 
            VALUES (?, ?, ?, ?)
        ");
        return $stmt->execute([$user_id, $activity_type, $context_type, $context_id]);
    } catch (PDOException $e) {
        error_log("Error recording activity: " . $e->getMessage());
        return false;
    }
}

/**
 * Get recent activities for dashboard
 */
function get_recent_activities($conn, $limit = 10) {
    $stmt = $conn->prepare("
        SELECT s.submission_type as type, 
               s.title as item_name, 
               s.submission_date as date,
               CONCAT(u.first_name, ' ', u.last_name) as student_name, 
               s.status
        FROM submissions s
        JOIN research_groups rg ON s.group_id = rg.group_id
        JOIN users u ON rg.lead_student_id = u.user_id
        ORDER BY s.submission_date DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Enhanced notification function
 */
function create_notification($conn, $user_id, $title, $message, $type, $context_type = null, $context_id = null) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, type, context_type, context_id) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        return $stmt->execute([$user_id, $title, $message, $type, $context_type, $context_id]);
    } catch (PDOException $e) {
        error_log("Error creating notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if user can access submission
 */
function can_user_access_submission($conn, $user_id, $submission_id) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM submissions s
        JOIN research_groups rg ON s.group_id = rg.group_id
        LEFT JOIN group_memberships gm ON rg.group_id = gm.group_id
        LEFT JOIN assignments a ON s.submission_id = a.context_id AND a.context_type = 'submission'
        WHERE s.submission_id = ? AND (
            rg.lead_student_id = ? OR 
            rg.adviser_id = ? OR 
            gm.user_id = ? OR 
            a.user_id = ? OR
            (SELECT role FROM users WHERE user_id = ?) = 'admin'
        )
    ");
    $stmt->execute([$submission_id, $user_id, $user_id, $user_id, $user_id, $user_id]);
    return $stmt->fetchColumn() > 0;
}
?>