<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'get_messages':
            $submission_id = (int)($_GET['submission_id'] ?? 0);
            
            if (!$submission_id) {
                echo json_encode(['success' => false, 'error' => 'Invalid submission']);
                exit;
            }
            
            // Verify user has access to this submission
            $stmt = $conn->prepare("
                SELECT s.*, rg.lead_student_id, rg.adviser_id 
                FROM submissions s
                JOIN research_groups rg ON s.group_id = rg.group_id
                WHERE s.submission_id = ?
            ");
            $stmt->execute([$submission_id]);
            $submission = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$submission) {
                echo json_encode(['success' => false, 'error' => 'Submission not found']);
                exit;
            }
            
            // Check if user has access (student lead or adviser)
            $has_access = false;
            if ($user_role === 'student' && $submission['lead_student_id'] == $user_id) {
                $has_access = true;
            } elseif ($user_role === 'adviser' && $submission['adviser_id'] == $user_id) {
                $has_access = true;
            }
            
            if (!$has_access) {
                echo json_encode(['success' => false, 'error' => 'Access denied']);
                exit;
            }
            
            // Get messages - include text, image, and file messages
            $stmt = $conn->prepare("
                SELECT m.*, 
                    CONCAT(u.first_name, ' ', u.last_name) as sender_name,
                    u.role,
                    u.profile_picture
                FROM messages m
                JOIN users u ON m.user_id = u.user_id
                WHERE m.context_type = 'submission' 
                    AND m.context_id = ?
                    AND m.message_type IN ('text', 'image', 'file')
                    AND (m.message_text IS NOT NULL OR m.file_path IS NOT NULL)
                ORDER BY m.created_at ASC
            ");
            $stmt->execute([$submission_id]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'messages' => $messages,
                'current_user_id' => $user_id
            ]);
            break;
            
        case 'send_message':
            $submission_id = (int)($_POST['submission_id'] ?? 0);
            $message_text = trim($_POST['message'] ?? '');
            
            if (!$submission_id) {
                echo json_encode(['success' => false, 'error' => 'Invalid submission']);
                exit;
            }
            
            // Verify access
            $stmt = $conn->prepare("
                SELECT s.*, rg.lead_student_id, rg.adviser_id, rg.group_name
                FROM submissions s
                JOIN research_groups rg ON s.group_id = rg.group_id
                WHERE s.submission_id = ?
            ");
            $stmt->execute([$submission_id]);
            $submission = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$submission) {
                echo json_encode(['success' => false, 'error' => 'Submission not found']);
                exit;
            }
            
            // Check if user has access
            $has_access = false;
            if ($user_role === 'student' && $submission['lead_student_id'] == $user_id) {
                $has_access = true;
            } elseif ($user_role === 'adviser' && $submission['adviser_id'] == $user_id) {
                $has_access = true;
            }
            
            if (!$has_access) {
                echo json_encode(['success' => false, 'error' => 'Access denied']);
                exit;
            }
            
            $conn->beginTransaction();
            
            try {
                $message_type = 'text';
                $file_path = null;
                $original_filename = null;
                
                // Check if file was uploaded (image or document)
                if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                    $file_type = $_FILES['file']['type'];
                    $file_size = $_FILES['file']['size'];
                    $file_info = pathinfo($_FILES['file']['name']);
                    $file_extension = strtolower($file_info['extension']);
                    
                    // Define allowed types
                    $image_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    $doc_types = ['application/pdf', 'application/msword', 
                                  'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                    $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    $doc_extensions = ['pdf', 'doc', 'docx'];
                    
                    // Determine file type and validate
                    if (in_array($file_type, $image_types) || in_array($file_extension, $image_extensions)) {
                        // Image file
                        if ($file_size > 5 * 1024 * 1024) { // 5MB limit for images
                            throw new Exception('Image size too large. Maximum size is 5MB.');
                        }
                        $message_type = 'image';
                        $upload_dir = '../uploads/chat_images/';
                        $filename = 'chat_' . $submission_id . '_' . time() . '_' . uniqid() . '.' . $file_extension;
                        
                    } elseif (in_array($file_type, $doc_types) || in_array($file_extension, $doc_extensions)) {
                        // Document file
                        if ($file_size > 10 * 1024 * 1024) { // 10MB limit for documents
                            throw new Exception('Document size too large. Maximum size is 10MB.');
                        }
                        $message_type = 'file';
                        $upload_dir = '../uploads/chat_files/';
                        $filename = 'chat_doc_' . $submission_id . '_' . time() . '_' . uniqid() . '.' . $file_extension;
                        
                    } else {
                        throw new Exception('Invalid file type. Only images (JPEG, PNG, GIF, WebP) and documents (PDF, DOC, DOCX) are allowed.');
                    }
                    
                    // Create upload directory if it doesn't exist
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    $target_path = $upload_dir . $filename;
                    $relative_path = str_replace('../', '', $upload_dir) . $filename;
                    
                    // Move uploaded file
                    if (move_uploaded_file($_FILES['file']['tmp_name'], $target_path)) {
                        $file_path = $relative_path;
                        $original_filename = $_FILES['file']['name'];
                        
                        // Set default message text if empty
                        if (empty($message_text)) {
                            $message_text = $message_type === 'image' ? 'Sent an image' : 'Sent a document';
                        }
                    } else {
                        throw new Exception('Failed to upload file');
                    }
                }
                
                // Validate that we have either text or file
                if (empty($message_text) && !$file_path) {
                    throw new Exception('Please provide a message or file');
                }
                
                // Insert message
                $stmt = $conn->prepare("
                    INSERT INTO messages (context_type, context_id, user_id, message_type, message_text, file_path, original_filename) 
                    VALUES ('submission', ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$submission_id, $user_id, $message_type, $message_text, $file_path, $original_filename]);
                
                // Create notification for the other party
                $recipient_id = null;
                $notification_title = '';
                $notification_message = '';
                
                if ($user_role === 'student') {
                    // Student sending to adviser
                    $recipient_id = $submission['adviser_id'];
                    $notification_title = 'New Chapter Message';
                    $notification_message = $_SESSION['full_name'] . ' sent you a message about Chapter ' . $submission['chapter_number'];
                } elseif ($user_role === 'adviser') {
                    // Adviser sending to student
                    $recipient_id = $submission['lead_student_id'];
                    $notification_title = 'New Chapter Message';
                    $notification_message = 'Your adviser sent you a message about Chapter ' . $submission['chapter_number'];
                }
                
                if ($recipient_id) {
                    $stmt = $conn->prepare("
                        INSERT INTO notifications (user_id, title, message, type, context_type, context_id) 
                        VALUES (?, ?, ?, 'chapter_message', 'submission', ?)
                    ");
                    $stmt->execute([$recipient_id, $notification_title, $notification_message, $submission_id]);
                }
                
                $conn->commit();
                echo json_encode(['success' => true]);
                
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}