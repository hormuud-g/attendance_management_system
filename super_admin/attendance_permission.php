<?php
/**
 * ATTENDANCE CORRECTION & UNLOCK SYSTEM - SUPER ADMIN PANEL
 * Full design for managing all pending attendance correction requests
 * Can approve/reject requests from both students and teachers
 * Also manages teacher unlock requests
 */

// ============================================
// INITIALIZATION & CONFIGURATION
// ============================================
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

// Database connection
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Email Configuration
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'abdirahmanmohamedabdulle10@gmail.com');
define('SMTP_PASSWORD', 'cjtenwiqxfnrjqcn');
define('SMTP_FROM_EMAIL', 'abdirahmanmohamedabdulle10@gmail.com');
define('SMTP_FROM_NAME', 'Academic Affairs Department');

// ✅ PHPMailer
require_once(__DIR__ . '/../lib/PHPMailer-master/src/Exception.php');
require_once(__DIR__ . '/../lib/PHPMailer-master/src/SMTP.php');
require_once(__DIR__ . '/../lib/PHPMailer-master/src/PHPMailer.php');

// ✅ Access Control - Super Admin only
if (!isset($_SESSION['user']) || strtolower($_SESSION['user']['role']) !== 'super_admin') {
    session_destroy();
    header("Location: ../login.php?error=unauthorized");
    exit;
}

date_default_timezone_set('Africa/Nairobi');
$message = "";
$type = "";

// Current user info
$current_user_id = $_SESSION['user']['user_id'] ?? 0;
$current_user_name = $_SESSION['user']['username'] ?? 'Super Admin';

/* ================= FIX COLLATIONS ================= */
try {
    // Fix teachers table collation
    $pdo->exec("ALTER TABLE teachers CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    // Fix attendance_correction table collation
    $pdo->exec("ALTER TABLE attendance_correction CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    // Fix attendance_lock table collation
    $pdo->exec("ALTER TABLE attendance_lock CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (Exception $e) {
    // Tables might not exist, ignore
}

/* ================= EMAIL SENDING FUNCTION ================= */
function sendEmail($to, $subject, $message, $pdo) {
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = nl2br($message);

        return $mail->send();
    } catch (Exception $e) {
        error_log("Email error: " . $e->getMessage());
        return false;
    }
}

/* ================= ACTIVE TERM ================= */
$term = $pdo->query("
    SELECT at.academic_term_id, at.term_name, ay.year_name 
    FROM academic_term at
    JOIN academic_year ay ON at.academic_year_id = ay.academic_year_id
    WHERE at.status = 'active'
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
$academic_term_id = $term['academic_term_id'] ?? null;
$active_term_name = ($term['term_name'] ?? '') . ' - ' . ($term['year_name'] ?? '');

/* ================= HANDLE POST ACTIONS ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $pdo->beginTransaction();
        $action = $_POST['action'];

        /* ========== ATTENDANCE CORRECTION APPROVAL ========== */
        if ($action === 'approve_correction' || $action === 'reject_correction') {
            $leave_id = intval($_POST['leave_id'] ?? 0);
            $decision_notes = trim($_POST['decision_notes'] ?? '');
            $bypass_teacher = isset($_POST['bypass_teacher']) ? true : false;
            
            if (!$leave_id) {
                throw new Exception("Invalid correction request!");
            }
            
            if ($action === 'reject_correction' && empty($decision_notes)) {
                throw new Exception("Decision notes are required for rejection!");
            }
            
            // Get correction details
            $stmt = $pdo->prepare("
                SELECT 
                    ac.*,
                    s.full_name as student_name,
                    s.reg_no,
                    s.email as student_email,
                    p.full_name as parent_name,
                    p.email as parent_email,
                    sub.subject_name,
                    sub.subject_code,
                    t.teacher_name,
                    t.email as teacher_email,
                    c.class_name,
                    u.username as requested_by_name,
                    u.role as requested_by_role
                FROM attendance_correction ac
                LEFT JOIN students s ON ac.student_id = s.student_id
                LEFT JOIN parents p ON s.parent_id = p.parent_id
                LEFT JOIN subject sub ON ac.subject_id = sub.subject_id
                LEFT JOIN teachers t ON ac.teacher_id = t.teacher_id
                LEFT JOIN classes c ON s.class_id = c.class_id
                LEFT JOIN users u ON ac.requested_by = u.user_id
                WHERE ac.leave_id = ?
            ");
            $stmt->execute([$leave_id]);
            $correction = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$correction) {
                throw new Exception("Correction request not found!");
            }
            
            // Parse original and corrected status from reason_details
            $original_status = 'absent';
            $corrected_status = 'present';
            $reason_details = $correction['reason_details'] ?? '';
            
            if (preg_match('/original_status:(\w+)/', $reason_details, $original_match)) {
                $original_status = $original_match[1];
            }
            if (preg_match('/corrected_status:(\w+)/', $reason_details, $corrected_match)) {
                $corrected_status = $corrected_match[1];
            }
            if (preg_match('/details:(.*)/s', $reason_details, $details_match)) {
                $reason_details = trim($details_match[1]);
            }
            
            $new_status = ($action === 'approve_correction') ? 'approved' : 'rejected';
            
            // If approving, update the attendance record
            if ($new_status === 'approved') {
                // Check if attendance record exists
                $attendance_check = $pdo->prepare("
                    SELECT attendance_id FROM attendance 
                    WHERE student_id = ? AND subject_id = ? AND attendance_date = ?
                ");
                $attendance_check->execute([
                    $correction['student_id'],
                    $correction['subject_id'],
                    $correction['start_date']
                ]);
                $existing = $attendance_check->fetch();
                
                if ($existing) {
                    // Update existing attendance
                    $update = $pdo->prepare("
                        UPDATE attendance 
                        SET status = ?, updated_at = NOW(), locked = 0
                        WHERE attendance_id = ?
                    ");
                    $update->execute([$corrected_status, $existing['attendance_id']]);
                } else {
                    // Insert new attendance record
                    $insert = $pdo->prepare("
                        INSERT INTO attendance 
                        (student_id, subject_id, class_id, teacher_id, academic_term_id, 
                         attendance_date, status, remarks, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $insert->execute([
                        $correction['student_id'],
                        $correction['subject_id'],
                        $correction['class_id'] ?? null,
                        $correction['teacher_id'],
                        $academic_term_id,
                        $correction['start_date'],
                        $corrected_status,
                        "Corrected from {$original_status} to {$corrected_status} by admin"
                    ]);
                }
                
                // Update absence count
                if ($original_status === 'absent' && $corrected_status === 'present') {
                    // Recalculate absence count
                    $count_stmt = $pdo->prepare("
                        SELECT COUNT(*) as absence_count 
                        FROM attendance 
                        WHERE student_id = ? 
                        AND subject_id = ? 
                        AND academic_term_id = ? 
                        AND status = 'absent'
                    ");
                    $count_stmt->execute([
                        $correction['student_id'],
                        $correction['subject_id'],
                        $academic_term_id
                    ]);
                    $count_result = $count_stmt->fetch(PDO::FETCH_ASSOC);
                    $new_absence_count = $count_result['absence_count'];
                    
                    // If less than 5, remove from recourse if exists
                    if ($new_absence_count < 5) {
                        $removeRecourse = $pdo->prepare("
                            UPDATE recourse_student 
                            SET status = 'cancelled',
                                updated_at = NOW()
                            WHERE student_id = ? 
                            AND subject_id = ?
                            AND academic_term_id = ?
                            AND status = 'active'
                        ");
                        $removeRecourse->execute([
                            $correction['student_id'],
                            $correction['subject_id'],
                            $academic_term_id
                        ]);
                    }
                }
            }
            
            // Update correction status
            $update_correction = $pdo->prepare("
                UPDATE attendance_correction 
                SET status = ?, 
                    approved_by = ?, 
                    reason_details = CONCAT(COALESCE(reason_details, ''), ' | Admin Notes: ', ?),
                    is_closed = 1,
                    updated_at = NOW()
                WHERE leave_id = ?
            ");
            $update_correction->execute([$new_status, $current_user_id, $decision_notes, $leave_id]);
            
            // Send email notifications
            $status_text = ($new_status === 'approved') ? 'APPROVED' : 'REJECTED';
            
            // Email to student
            if (!empty($correction['student_email'])) {
                $student_subject = "Attendance Correction {$status_text} - {$correction['subject_name']}";
                
                $student_message = "Dear {$correction['student_name']},\n\n";
                $student_message .= "Your attendance correction request has been {$status_text}.\n\n";
                $student_message .= "Subject: {$correction['subject_name']}\n";
                $student_message .= "Date: " . date('F d, Y', strtotime($correction['start_date'])) . "\n";
                $student_message .= "Original Status: " . ucfirst($original_status) . "\n";
                $student_message .= "Corrected Status: " . ucfirst($corrected_status) . "\n";
                $student_message .= "Admin Notes: {$decision_notes}\n\n";
                
                sendEmail($correction['student_email'], $student_subject, $student_message, $pdo);
            }
            
            // Email to parent
            if (!empty($correction['parent_email'])) {
                $parent_subject = "Attendance Correction Update - {$correction['student_name']}";
                
                $parent_message = "Dear {$correction['parent_name']},\n\n";
                $parent_message .= "An attendance correction request for your child has been {$status_text}.\n\n";
                $parent_message .= "Student: {$correction['student_name']}\n";
                $parent_message .= "Subject: {$correction['subject_name']}\n";
                $parent_message .= "Date: " . date('F d, Y', strtotime($correction['start_date'])) . "\n";
                $parent_message .= "Status: {$status_text}\n\n";
                
                sendEmail($correction['parent_email'], $parent_subject, $parent_message, $pdo);
            }
            
            // Email to teacher if not bypassed
            if (!$bypass_teacher && !empty($correction['teacher_email'])) {
                $teacher_subject = "Attendance Correction {$status_text} - {$correction['student_name']}";
                
                $teacher_message = "Dear {$correction['teacher_name']},\n\n";
                $teacher_message .= "An attendance correction request for your student has been {$status_text}.\n\n";
                $teacher_message .= "Student: {$correction['student_name']}\n";
                $teacher_message .= "Subject: {$correction['subject_name']}\n";
                $teacher_message .= "Date: " . date('F d, Y', strtotime($correction['start_date'])) . "\n";
                $teacher_message .= "Decision: {$status_text}\n\n";
                
                sendEmail($correction['teacher_email'], $teacher_subject, $teacher_message, $pdo);
            }
            
            $message = "✅ Correction request {$status_text} successfully!";
            $type = "success";
        }
        
        /* ========== BULK CORRECTION APPROVAL ========== */
        if ($action === 'bulk_approve' || $action === 'bulk_reject') {
            $leave_ids = $_POST['leave_ids'] ?? [];
            $decision_notes = trim($_POST['decision_notes'] ?? 'Bulk action');
            $bypass_teacher = isset($_POST['bypass_teacher']) ? true : false;
            
            if (empty($leave_ids)) {
                throw new Exception("No correction requests selected!");
            }
            
            $success_count = 0;
            $error_count = 0;
            $new_status = ($action === 'bulk_approve') ? 'approved' : 'rejected';
            
            foreach ($leave_ids as $leave_id) {
                try {
                    // Get correction details
                    $stmt = $pdo->prepare("
                        SELECT ac.*, s.email as student_email, s.full_name as student_name,
                               sub.subject_name, t.email as teacher_email
                        FROM attendance_correction ac
                        LEFT JOIN students s ON ac.student_id = s.student_id
                        LEFT JOIN subject sub ON ac.subject_id = sub.subject_id
                        LEFT JOIN teachers t ON ac.teacher_id = t.teacher_id
                        WHERE ac.leave_id = ? AND ac.status = 'pending'
                    ");
                    $stmt->execute([$leave_id]);
                    $correction = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$correction) continue;
                    
                    // Parse original and corrected status
                    $original_status = 'absent';
                    $corrected_status = 'present';
                    if (preg_match('/original_status:(\w+)/', $correction['reason_details'] ?? '', $original_match)) {
                        $original_status = $original_match[1];
                    }
                    if (preg_match('/corrected_status:(\w+)/', $correction['reason_details'] ?? '', $corrected_match)) {
                        $corrected_status = $corrected_match[1];
                    }
                    
                    // If approving, update attendance
                    if ($new_status === 'approved') {
                        $attendance_check = $pdo->prepare("
                            SELECT attendance_id FROM attendance 
                            WHERE student_id = ? AND subject_id = ? AND attendance_date = ?
                        ");
                        $attendance_check->execute([
                            $correction['student_id'],
                            $correction['subject_id'],
                            $correction['start_date']
                        ]);
                        $existing = $attendance_check->fetch();
                        
                        if ($existing) {
                            $update = $pdo->prepare("
                                UPDATE attendance 
                                SET status = ?, updated_at = NOW(), locked = 0
                                WHERE attendance_id = ?
                            ");
                            $update->execute([$corrected_status, $existing['attendance_id']]);
                        } else {
                            $insert = $pdo->prepare("
                                INSERT INTO attendance 
                                (student_id, subject_id, class_id, teacher_id, academic_term_id, 
                                 attendance_date, status, remarks, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                            ");
                            $insert->execute([
                                $correction['student_id'],
                                $correction['subject_id'],
                                $correction['class_id'] ?? null,
                                $correction['teacher_id'],
                                $academic_term_id,
                                $correction['start_date'],
                                $corrected_status,
                                "Corrected from {$original_status} to {$corrected_status} by admin (bulk)"
                            ]);
                        }
                    }
                    
                    // Update correction status
                    $update_correction = $pdo->prepare("
                        UPDATE attendance_correction 
                        SET status = ?, 
                            approved_by = ?, 
                            reason_details = CONCAT(COALESCE(reason_details, ''), ' | Admin Notes: ', ?),
                            is_closed = 1,
                            updated_at = NOW()
                        WHERE leave_id = ?
                    ");
                    $update_correction->execute([$new_status, $current_user_id, $decision_notes, $leave_id]);
                    
                    $success_count++;
                    
                } catch (Exception $e) {
                    $error_count++;
                    error_log("Bulk action error for leave_id {$leave_id}: " . $e->getMessage());
                }
            }
            
            $message = "✅ Bulk action completed! {$success_count} requests processed, {$error_count} failed.";
            $type = "success";
        }
        
        /* ========== TEACHER UNLOCK APPROVAL ========== */
        if ($action === 'approve_unlock' || $action === 'reject_unlock') {
            $lock_id = intval($_POST['lock_id'] ?? 0);
            $decision_notes = trim($_POST['decision_notes'] ?? '');
            
            if (!$lock_id) {
                throw new Exception("Invalid unlock request!");
            }
            
            if ($action === 'reject_unlock' && empty($decision_notes)) {
                throw new Exception("Decision notes are required for rejection!");
            }
            
            // Get unlock request details
            $stmt = $pdo->prepare("
                SELECT al.*, 
                       t.teacher_name, t.email as teacher_email,
                       sub.subject_name,
                       u.username as requested_by_name
                FROM attendance_lock al
                LEFT JOIN teachers t ON al.teacher_id = t.teacher_id
                LEFT JOIN subject sub ON al.subject_id = sub.subject_id
                LEFT JOIN users u ON al.locked_by = u.user_id
                WHERE al.lock_id = ? AND al.is_locked = 1
            ");
            $stmt->execute([$lock_id]);
            $unlock = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$unlock) {
                throw new Exception("Unlock request not found or already processed!");
            }
            
            if ($action === 'approve_unlock') {
                // Approve unlock - set is_locked = 0 and record unlocked_by/unlocked_at
                $update = $pdo->prepare("
                    UPDATE attendance_lock 
                    SET is_locked = 0, 
                        unlocked_by = ?, 
                        unlocked_at = NOW()
                    WHERE lock_id = ?
                ");
                $update->execute([$current_user_id, $lock_id]);
                
                // Also update any locked attendance records
                $update_attendance = $pdo->prepare("
                    UPDATE attendance 
                    SET locked = 0, remarks = CONCAT(COALESCE(remarks, ''), ' | Unlocked by admin')
                    WHERE teacher_id = ? AND subject_id = ? AND attendance_date = ?
                ");
                $update_attendance->execute([
                    $unlock['teacher_id'],
                    $unlock['subject_id'],
                    $unlock['lock_date']
                ]);
                
            } else {
                // Reject unlock - we don't have a notes column, so we'll just keep is_locked = 1
                // The teacher will be notified via email
                // No update needed
            }
            
            $status_text = ($action === 'approve_unlock') ? 'APPROVED' : 'REJECTED';
            
            // Send email to teacher
            if (!empty($unlock['teacher_email'])) {
                $teacher_subject = "Unlock Request {$status_text} - {$unlock['subject_name']}";
                
                $teacher_message = "Dear {$unlock['teacher_name']},\n\n";
                $teacher_message .= "Your unlock request has been {$status_text}.\n\n";
                $teacher_message .= "Subject: {$unlock['subject_name']}\n";
                $teacher_message .= "Date: " . date('F d, Y', strtotime($unlock['lock_date'])) . "\n";
                $teacher_message .= "Decision: {$status_text}\n";
                $teacher_message .= "Admin Notes: {$decision_notes}\n\n";
                
                if ($action === 'approve_unlock') {
                    $teacher_message .= "You can now edit attendance for this date.\n";
                }
                
                sendEmail($unlock['teacher_email'], $teacher_subject, $teacher_message, $pdo);
            }
            
            $message = "✅ Unlock request {$status_text} successfully!";
            $type = "success";
        }

        $pdo->commit();

    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "❌ Error: " . $e->getMessage();
        $type = "error";
    }
}

/* ================= FETCH PENDING CORRECTIONS ================= */
$pending_corrections = $pdo->prepare("
    SELECT 
        ac.leave_id,
        ac.student_id,
        ac.subject_id,
        ac.reason,
        ac.reason_details,
        ac.start_date,
        ac.days_count,
        ac.end_date,
        ac.created_at,
        
        s.full_name as student_name,
        s.reg_no,
        s.email as student_email,
        s.phone_number as student_phone,
        
        p.full_name as parent_name,
        p.email as parent_email,
        p.phone as parent_phone,
        
        sub.subject_name,
        sub.subject_code,
        
        t.teacher_name,
        t.email as teacher_email,
        
        c.class_name,
        cam.campus_name,
        
        u.username as requested_by_name,
        u.role as requested_by_role,
        
        CASE 
            WHEN u.role = 'student' THEN 'Student Request'
            WHEN u.role = 'teacher' THEN 'Teacher Request'
            ELSE 'System Request'
        END as request_type,
        
        CASE 
            WHEN ac.start_date < CURDATE() THEN 'past'
            WHEN ac.start_date = CURDATE() THEN 'today'
            ELSE 'future'
        END as date_status
         
    FROM attendance_correction ac
    LEFT JOIN students s ON ac.student_id = s.student_id
    LEFT JOIN parents p ON s.parent_id = p.parent_id
    LEFT JOIN subject sub ON ac.subject_id = sub.subject_id
    LEFT JOIN teachers t ON ac.teacher_id = t.teacher_id
    LEFT JOIN classes c ON s.class_id = c.class_id
    LEFT JOIN campus cam ON c.campus_id = cam.campus_id
    LEFT JOIN users u ON ac.requested_by = u.user_id
    WHERE ac.status = 'pending'
    ORDER BY 
        CASE 
            WHEN ac.start_date < CURDATE() THEN 1
            WHEN ac.start_date = CURDATE() THEN 2
            ELSE 3
        END,
        ac.start_date ASC
");

$pending_corrections->execute();
$pending = $pending_corrections->fetchAll(PDO::FETCH_ASSOC);

// Parse original and corrected status for each pending request
foreach ($pending as &$corr) {
    $original_status = 'absent';
    $corrected_status = 'present';
    $details = '';
    
    if (preg_match('/original_status:(\w+)/', $corr['reason_details'] ?? '', $original_match)) {
        $original_status = $original_match[1];
    }
    if (preg_match('/corrected_status:(\w+)/', $corr['reason_details'] ?? '', $corrected_match)) {
        $corrected_status = $corrected_match[1];
    }
    if (preg_match('/details:(.*)/s', $corr['reason_details'] ?? '', $details_match)) {
        $details = trim($details_match[1]);
    }
    
    $corr['original_status'] = $original_status;
    $corr['corrected_status'] = $corrected_status;
    $corr['clean_details'] = $details;
}

/* ================= FETCH PENDING UNLOCK REQUESTS ================= */
$pending_unlocks = $pdo->prepare("
    SELECT 
        al.lock_id,
        al.teacher_id,
        al.subject_id,
        al.lock_date,
        al.locked_at,
        
        t.teacher_name,
        t.email as teacher_email,
        t.phone_number as teacher_phone,
        
        sub.subject_name,
        sub.subject_code,
        
        u.username as requested_by_name,
        
        CASE 
            WHEN al.lock_date < CURDATE() THEN 'past'
            WHEN al.lock_date = CURDATE() THEN 'today'
            ELSE 'future'
        END as date_status,
        
        (SELECT COUNT(*) FROM attendance 
         WHERE teacher_id = al.teacher_id 
         AND subject_id = al.subject_id 
         AND attendance_date = al.lock_date) as attendance_count
         
    FROM attendance_lock al
    LEFT JOIN teachers t ON al.teacher_id = t.teacher_id
    LEFT JOIN subject sub ON al.subject_id = sub.subject_id
    LEFT JOIN users u ON al.locked_by = u.user_id
    WHERE al.is_locked = 1
    ORDER BY 
        CASE 
            WHEN al.lock_date < CURDATE() THEN 1
            WHEN al.lock_date = CURDATE() THEN 2
            ELSE 3
        END,
        al.lock_date ASC
");

$pending_unlocks->execute();
$unlocks = $pending_unlocks->fetchAll(PDO::FETCH_ASSOC);

/* ================= FETCH APPROVED/REJECTED HISTORY ================= */
$history = $pdo->prepare("
    (SELECT 
        CAST(ac.leave_id AS CHAR) as id,
        'correction' as type,
        ac.status,
        ac.reason_details,
        ac.start_date as action_date,
        ac.updated_at as processed_at,
        s.full_name as student_name,
        s.reg_no,
        sub.subject_name,
        u1.username as requested_by,
        u2.username as processed_by
    FROM attendance_correction ac
    LEFT JOIN students s ON ac.student_id = s.student_id
    LEFT JOIN subject sub ON ac.subject_id = sub.subject_id
    LEFT JOIN users u1 ON ac.requested_by = u1.user_id
    LEFT JOIN users u2 ON ac.approved_by = u2.user_id
    WHERE ac.status IN ('approved', 'rejected'))
    
    UNION ALL
    
    (SELECT 
        CAST(al.lock_id AS CHAR) as id,
        'unlock' as type,
        CASE WHEN al.is_locked = 0 THEN 'approved' ELSE 'rejected' END as status,
        '' as reason_details,
        al.lock_date as action_date,
        al.unlocked_at as processed_at,
        t.teacher_name as student_name,
        '' as reg_no,
        sub.subject_name,
        u1.username as requested_by,
        u2.username as processed_by
    FROM attendance_lock al
    LEFT JOIN teachers t ON al.teacher_id = t.teacher_id
    LEFT JOIN subject sub ON al.subject_id = sub.subject_id
    LEFT JOIN users u1 ON al.locked_by = u1.user_id
    LEFT JOIN users u2 ON al.unlocked_by = u2.user_id
    WHERE al.is_locked = 0)
    
    ORDER BY processed_at DESC
    LIMIT 50
");
$history->execute();
$recent_history = $history->fetchAll(PDO::FETCH_ASSOC);

// Parse status for history
foreach ($recent_history as &$hist) {
    if ($hist['type'] === 'correction') {
        $original_status = 'absent';
        $corrected_status = 'present';
        
        if (preg_match('/original_status:(\w+)/', $hist['reason_details'] ?? '', $original_match)) {
            $original_status = $original_match[1];
        }
        if (preg_match('/corrected_status:(\w+)/', $hist['reason_details'] ?? '', $corrected_match)) {
            $corrected_status = $corrected_match[1];
        }
        
        $hist['original_status'] = $original_status;
        $hist['corrected_status'] = $corrected_status;
    }
}

// Statistics
$stats = $pdo->query("
    SELECT 
        (SELECT COUNT(*) FROM attendance_correction WHERE status = 'pending') as pending_corrections,
        (SELECT COUNT(*) FROM attendance_lock WHERE is_locked = 1) as pending_unlocks,
        (SELECT COUNT(*) FROM attendance_correction WHERE status = 'pending' AND start_date < CURDATE()) as past_corrections,
        (SELECT COUNT(*) FROM attendance_lock WHERE is_locked = 1 AND lock_date < CURDATE()) as past_unlocks
")->fetch(PDO::FETCH_ASSOC);

$total_pending = ($stats['pending_corrections'] ?? 0) + ($stats['pending_unlocks'] ?? 0);
$total_past = ($stats['past_corrections'] ?? 0) + ($stats['past_unlocks'] ?? 0);

// Function to safely encode data for JavaScript
function safeJsonEncode($data) {
    return htmlspecialchars(json_encode($data), ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Management | Super Admin</title>
    <link rel="icon" type="image/png" href="../images.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
    /* ===========================================
       CSS VARIABLES
    =========================================== */
    :root {
        --primary-color: #00843D;
        --secondary-color: #0072CE;
        --light-color: #00A651;
        --dark-color: #333333;
        --light-gray: #F5F9F7;
        --danger-color: #C62828;
        --warning-color: #FFB400;
        --white: #FFFFFF;
        --border-color: #E0E0E0;
        --shadow-color: rgba(0, 0, 0, 0.08);
        --transition: all 0.3s ease;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
        font-family: 'Segoe UI', 'Poppins', sans-serif;
    }

    body {
        background: var(--light-gray);
        color: var(--dark-color);
        min-height: 100vh;
        overflow-x: hidden;
    }

    .main-content {
        margin-top: 65px;
        margin-left: 240px;
        padding: 25px;
        transition: margin-left 0.3s ease;
        min-height: calc(100vh - 115px);
    }

    body.sidebar-collapsed .main-content {
        margin-left: 60px;
    }

    /* ==============================
       PAGE HEADER
    ============================== */
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        padding: 20px;
        background: var(--white);
        border-radius: 12px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        border-left: 4px solid var(--primary-color);
    }

    .page-header h1 {
        color: var(--secondary-color);
        font-size: 26px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .page-header h1 i {
        color: var(--primary-color);
        background: rgba(0, 132, 61, 0.1);
        padding: 10px;
        border-radius: 10px;
    }

    .term-badge {
        background: var(--secondary-color);
        color: white;
        padding: 6px 15px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 500;
        margin-left: 15px;
    }

    /* ==============================
       STATS CARDS
    ============================== */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 25px;
    }

    .stat-card {
        background: var(--white);
        border-radius: 10px;
        padding: 20px;
        box-shadow: 0 3px 10px rgba(0,0,0,0.05);
        display: flex;
        align-items: center;
        gap: 15px;
        border-left: 4px solid;
        transition: var(--transition);
    }

    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 5px 20px rgba(0,0,0,0.1);
    }

    .stat-card.correction {
        border-left-color: var(--warning-color);
    }
    .stat-card.unlock {
        border-left-color: #9C27B0;
    }
    .stat-card.past {
        border-left-color: var(--danger-color);
    }
    .stat-card.total {
        border-left-color: var(--primary-color);
    }

    .stat-icon {
        width: 50px;
        height: 50px;
        border-radius: 10px;
        background: rgba(0, 132, 61, 0.1);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--primary-color);
        font-size: 24px;
    }

    .stat-info h3 {
        font-size: 28px;
        font-weight: 700;
        color: var(--dark-color);
        line-height: 1.2;
        margin-bottom: 5px;
    }

    .stat-info p {
        color: #666;
        font-size: 13px;
        margin: 0;
    }

    /* ==============================
       TABS
    ============================== */
    .tabs {
        display: flex;
        gap: 5px;
        margin-bottom: 25px;
        background: white;
        padding: 8px;
        border-radius: 12px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.06);
        flex-wrap: wrap;
    }

    .tab-btn {
        flex: 1;
        min-width: 150px;
        background: transparent;
        color: #666;
        border: none;
        padding: 14px 18px;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 600;
        font-size: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        transition: all 0.3s ease;
        position: relative;
    }

    .tab-btn:hover {
        background: rgba(0,132,61,0.08);
        color: var(--primary-color);
    }

    .tab-btn.active {
        background: linear-gradient(135deg, var(--primary-color), var(--light-color));
        color: white;
        box-shadow: 0 4px 15px rgba(0,132,61,0.2);
    }

    .notification-badge {
        position: absolute;
        top: -8px;
        right: -8px;
        background: var(--danger-color);
        color: white;
        border-radius: 50%;
        width: 22px;
        height: 22px;
        font-size: 11px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        box-shadow: 0 2px 8px rgba(198,40,40,0.2);
    }

    .tab-content {
        display: none;
        animation: fadeIn 0.5s ease;
    }

    .tab-content.active {
        display: block;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* ==============================
       TABLE
    ============================== */
    .table-wrapper {
        background: var(--white);
        border-radius: 10px;
        overflow: hidden;
        box-shadow: 0 3px 15px rgba(0, 0, 0, 0.08);
        margin-bottom: 30px;
    }

    .table-header {
        padding: 20px;
        border-bottom: 1px solid #eee;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
    }

    .table-header h2 {
        color: var(--dark-color);
        font-size: 18px;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .bulk-actions {
        display: flex;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap;
    }

    .btn {
        padding: 10px 20px;
        border: none;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
    }

    .btn-success {
        background: var(--primary-color);
        color: white;
    }

    .btn-success:hover {
        background: var(--light-color);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,132,61,0.2);
    }

    .btn-danger {
        background: var(--danger-color);
        color: white;
    }

    .btn-danger:hover {
        background: #a81f1f;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(198,40,40,0.2);
    }

    .btn-secondary {
        background: #6c757d;
        color: white;
    }

    .btn-secondary:hover {
        background: #5a6268;
    }

    .btn-info {
        background: var(--secondary-color);
        color: white;
    }

    .btn-info:hover {
        background: #005fa3;
    }

    .btn-warning {
        background: var(--warning-color);
        color: #333;
    }

    .btn-warning:hover {
        background: #e0a800;
    }

    .table-container {
        overflow-x: auto;
        padding: 0 20px 20px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
        min-width: 1200px;
    }

    th {
        background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
        color: white;
        padding: 15px;
        font-weight: 600;
        font-size: 13px;
        position: sticky;
        top: 0;
        z-index: 10;
    }

    td {
        padding: 15px;
        border-bottom: 1px solid #eee;
        color: #2d3748;
    }

    tr:hover td {
        background: #f8f9fa;
    }

    tr.past-date {
        background: rgba(198, 40, 40, 0.05);
    }

    tr.today-date {
        background: rgba(0, 132, 61, 0.05);
    }

    /* ==============================
       BADGES
    ============================== */
    .status-badge {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        display: inline-block;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .status-pending {
        background: #fff3cd;
        color: #856404;
    }

    .status-approved {
        background: #d4edda;
        color: #155724;
    }

    .status-rejected {
        background: #f8d7da;
        color: #721c24;
    }

    .status-unlock {
        background: #e3f2fd;
        color: #1565c0;
    }

    .status-change {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 5px 12px;
        border-radius: 20px;
        background: #f8f9fa;
        font-size: 12px;
        font-weight: 600;
    }

    .status-change .from {
        color: var(--danger-color);
    }

    .status-change .to {
        color: var(--primary-color);
    }

    .status-change .arrow {
        color: #6c757d;
        font-size: 10px;
    }

    .request-badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
    }

    .request-student {
        background: #e3f2fd;
        color: #1565c0;
    }

    .request-teacher {
        background: #fff3e0;
        color: #ef6c00;
    }

    .date-badge {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
    }

    .date-past {
        background: #ffebee;
        color: #c62828;
    }

    .date-today {
        background: #e8f5e9;
        color: #2e7d32;
    }

    .date-future {
        background: #e3f2fd;
        color: #1565c0;
    }

    .type-badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
    }

    .type-correction {
        background: #e8f5e9;
        color: #2e7d32;
    }

    .type-unlock {
        background: #fff3e0;
        color: #ef6c00;
    }

    /* ==============================
       ACTION BUTTONS
    ============================== */
    .actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .action-btn {
        min-width: 36px;
        height: 36px;
        border-radius: 6px;
        border: none;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
        font-size: 14px;
        color: white;
    }

    .action-btn.approve {
        background: var(--primary-color);
    }

    .action-btn.approve:hover {
        background: var(--light-color);
        transform: translateY(-2px);
    }

    .action-btn.reject {
        background: var(--danger-color);
    }

    .action-btn.reject:hover {
        background: #a81f1f;
        transform: translateY(-2px);
    }

    .action-btn.view {
        background: var(--secondary-color);
    }

    .action-btn.view:hover {
        background: #005fa3;
        transform: translateY(-2px);
    }

    /* ==============================
       MODAL
    ============================== */
    .modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        z-index: 9999;
        justify-content: center;
        align-items: center;
    }

    .modal-content {
        background: white;
        border-radius: 12px;
        padding: 30px;
        width: 90%;
        max-width: 600px;
        max-height: 80vh;
        overflow-y: auto;
        position: relative;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        animation: slideUp 0.4s ease;
    }

    @keyframes slideUp {
        from { transform: translateY(50px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }

    .modal-close {
        position: absolute;
        top: 15px;
        right: 20px;
        font-size: 28px;
        color: #888;
        cursor: pointer;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        transition: all 0.3s ease;
        background: rgba(0,0,0,0.05);
    }

    .modal-close:hover {
        background: rgba(0,0,0,0.1);
        color: var(--danger-color);
        transform: rotate(90deg);
    }

    .modal-content h2 {
        color: var(--secondary-color);
        margin-bottom: 20px;
        font-size: 22px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .modal-content h2 i {
        color: var(--primary-color);
        background: rgba(0,132,61,0.1);
        padding: 10px;
        border-radius: 10px;
    }

    .details-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
        margin-bottom: 20px;
        background: #f8f9fa;
        padding: 15px;
        border-radius: 8px;
    }

    .detail-item {
        margin-bottom: 8px;
    }

    .detail-label {
        font-size: 12px;
        color: #666;
        margin-bottom: 3px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .detail-value {
        font-size: 14px;
        font-weight: 600;
        color: var(--dark-color);
        word-break: break-word;
    }

    .detail-value small {
        font-size: 12px;
        color: #666;
        font-weight: normal;
    }

    .status-box {
        display: inline-block;
        padding: 6px 12px;
        border-radius: 20px;
        font-weight: 600;
        font-size: 12px;
    }

    .status-box.original {
        background: #ffebee;
        color: #c62828;
    }

    .status-box.corrected {
        background: #e8f5e9;
        color: #2e7d32;
    }

    .modal-actions {
        display: flex;
        gap: 10px;
        margin-top: 20px;
        padding-top: 20px;
        border-top: 2px solid #f0f0f0;
    }

    .modal-actions .btn {
        flex: 1;
        justify-content: center;
        padding: 12px;
    }

    /* ==============================
       ALERT
    ============================== */
    .alert {
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 15px 25px;
        border-radius: 8px;
        color: white;
        font-weight: 600;
        z-index: 10000;
        animation: slideInRight 0.3s ease;
        box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        display: flex;
        align-items: center;
        gap: 15px;
        max-width: 400px;
    }

    .alert-success {
        background: var(--primary-color);
    }

    .alert-error {
        background: var(--danger-color);
    }

    @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }

    /* ==============================
       EMPTY STATE
    ============================== */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #666;
    }

    .empty-state i {
        font-size: 80px;
        color: #ddd;
        margin-bottom: 20px;
    }

    .empty-state h3 {
        color: #888;
        margin-bottom: 15px;
    }

    .empty-state p {
        color: #999;
        max-width: 400px;
        margin: 0 auto;
    }

    /* ==============================
       RESPONSIVE
    ============================== */
    @media (max-width: 1024px) {
        .main-content {
            margin-left: 240px;
        }
        body.sidebar-collapsed .main-content {
            margin-left: 60px;
        }
    }

    @media (max-width: 768px) {
        .main-content {
            margin-left: 0 !important;
            padding: 15px;
        }
        
        .page-header {
            flex-direction: column;
            align-items: stretch;
            gap: 15px;
            padding: 15px;
        }
        
        .page-header h1 {
            font-size: 22px;
            flex-direction: column;
            align-items: flex-start;
        }
        
        .term-badge {
            margin-left: 0;
        }
        
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .tabs {
            flex-direction: column;
        }
        
        .tab-btn {
            width: 100%;
        }
        
        .table-header {
            flex-direction: column;
            align-items: stretch;
        }
        
        .bulk-actions {
            flex-direction: column;
        }
        
        .bulk-actions .btn {
            width: 100%;
        }
        
        .details-grid {
            grid-template-columns: 1fr;
        }
        
        .modal-content {
            padding: 20px 15px;
        }
        
        .modal-actions {
            flex-direction: column;
        }
    }

    @media (max-width: 480px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
        
        .actions {
            flex-direction: column;
        }
        
        .action-btn {
            width: 100%;
        }
    }

    /* ==============================
       SCROLLBAR
    ============================== */
    ::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }

    ::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 10px;
    }

    ::-webkit-scrollbar-thumb {
        background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
        border-radius: 10px;
    }

    ::-webkit-scrollbar-thumb:hover {
        background: linear-gradient(135deg, #005fa3, #006b30);
    }
    </style>
</head>
<body>
    <?php include('../includes/header.php'); ?>

    <div class="main-content">
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $type === 'success' ? 'success' : 'error'; ?>">
                <i class="fas <?php echo $type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> fa-2x"></i>
                <div>
                    <strong><?php echo htmlspecialchars($message); ?></strong>
                    <div style="font-size: 12px; margin-top: 5px;">
                        <?php echo date('H:i:s'); ?>
                    </div>
                </div>
            </div>
            <script>
                setTimeout(() => {
                    const alert = document.querySelector('.alert');
                    if (alert) alert.remove();
                }, 5000);
            </script>
        <?php endif; ?>

        <!-- PAGE HEADER -->
        <div class="page-header">
            <h1>
                <i class="fas fa-clipboard-check"></i>
                Attendance Management
                <?php if ($active_term_name): ?>
                    <span class="term-badge">
                        <i class="fas fa-calendar-alt"></i> <?php echo htmlspecialchars($active_term_name); ?>
                    </span>
                <?php endif; ?>
            </h1>
        </div>

        <!-- STATS CARDS -->
        <div class="stats-grid">
            <div class="stat-card correction">
                <div class="stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['pending_corrections'] ?? 0; ?></h3>
                    <p>Pending Corrections</p>
                </div>
            </div>
            <div class="stat-card unlock">
                <div class="stat-icon">
                    <i class="fas fa-unlock-alt"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['pending_unlocks'] ?? 0; ?></h3>
                    <p>Unlock Requests</p>
                </div>
            </div>
            <div class="stat-card past">
                <div class="stat-icon">
                    <i class="fas fa-calendar-times"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $total_past; ?></h3>
                    <p>Past Dates</p>
                </div>
            </div>
            <div class="stat-card total">
                <div class="stat-icon">
                    <i class="fas fa-tasks"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $total_pending; ?></h3>
                    <p>Total Pending</p>
                </div>
            </div>
        </div>

        <!-- TABS -->
        <div class="tabs">
            <button class="tab-btn active" data-tab="tab1">
                <i class="fas fa-clock"></i>
                Pending Corrections
                <?php if (!empty($pending)): ?>
                    <span class="notification-badge"><?php echo count($pending); ?></span>
                <?php endif; ?>
            </button>
            <button class="tab-btn" data-tab="tab2">
                <i class="fas fa-unlock-alt"></i>
                Unlock Requests
                <?php if (!empty($unlocks)): ?>
                    <span class="notification-badge"><?php echo count($unlocks); ?></span>
                <?php endif; ?>
            </button>
            <button class="tab-btn" data-tab="tab3">
                <i class="fas fa-history"></i>
                History
            </button>
        </div>

        <!-- TAB 1: PENDING CORRECTIONS -->
        <div id="tab1" class="tab-content active">
            <div class="table-wrapper">
                <div class="table-header">
                    <h2>
                        <i class="fas fa-clock"></i>
                        Pending Attendance Corrections
                        <span style="font-size: 14px; color: #666; font-weight: normal; margin-left: 10px;">
                            (<?php echo count($pending); ?> total)
                        </span>
                    </h2>
                    <div class="bulk-actions">
                        <button class="btn btn-success" onclick="openBulkModal('correction', 'approve')" <?php echo empty($pending) ? 'disabled' : ''; ?>>
                            <i class="fas fa-check-double"></i> Bulk Approve
                        </button>
                        <button class="btn btn-danger" onclick="openBulkModal('correction', 'reject')" <?php echo empty($pending) ? 'disabled' : ''; ?>>
                            <i class="fas fa-times-double"></i> Bulk Reject
                        </button>
                    </div>
                </div>

                <?php if (!empty($pending)): ?>
                <div class="table-container">
                    <form id="bulkCorrectionForm" method="POST">
                        <input type="hidden" name="action" id="bulkCorrectionAction" value="">
                        <input type="hidden" name="bypass_teacher" id="bulkCorrectionBypass" value="0">
                        <textarea name="decision_notes" id="bulkCorrectionNotes" style="display: none;"></textarea>
                        
                        <table>
                            <thead>
                                <tr>
                                    <th style="width: 40px;">
                                        <input type="checkbox" id="selectAllCorrection" onclick="toggleAll('correction')">
                                    </th>
                                    <th>Student</th>
                                    <th>Subject</th>
                                    <th>Type</th>
                                    <th>Correction</th>
                                    <th>Date</th>
                                    <th>Reason</th>
                                    <th>Requested</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending as $corr): 
                                    $row_class = '';
                                    if ($corr['date_status'] === 'past') $row_class = 'past-date';
                                    elseif ($corr['date_status'] === 'today') $row_class = 'today-date';
                                ?>
                                    <tr class="<?php echo $row_class; ?>">
                                        <td style="text-align: center;">
                                            <input type="checkbox" name="leave_ids[]" value="<?php echo $corr['leave_id']; ?>" 
                                                   class="select-item-correction" onchange="updateSelectAll('correction')">
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($corr['student_name']); ?></strong>
                                            <br>
                                            <small style="color: #666;">
                                                <?php echo htmlspecialchars($corr['reg_no']); ?> | 
                                                <?php echo htmlspecialchars($corr['class_name'] ?? 'N/A'); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($corr['subject_name']); ?></strong>
                                            <br>
                                            <small style="color: #666;">
                                                Teacher: <?php echo htmlspecialchars($corr['teacher_name'] ?? 'N/A'); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="request-badge request-<?php echo $corr['requested_by_role']; ?>">
                                                <i class="fas <?php echo $corr['requested_by_role'] === 'student' ? 'fa-user-graduate' : 'fa-chalkboard-teacher'; ?>"></i>
                                                <?php echo $corr['request_type']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="status-change">
                                                <span class="from"><?php echo ucfirst($corr['original_status']); ?></span>
                                                <span class="arrow"><i class="fas fa-arrow-right"></i></span>
                                                <span class="to"><?php echo ucfirst($corr['corrected_status']); ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="date-badge date-<?php echo $corr['date_status']; ?>">
                                                <i class="fas fa-calendar"></i>
                                                <?php echo date('M d, Y', strtotime($corr['start_date'])); ?>
                                                <?php if ($corr['days_count'] > 1): ?>
                                                    (+<?php echo $corr['days_count'] - 1; ?>)
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <strong><?php echo ucfirst($corr['reason']); ?></strong>
                                            <?php if ($corr['clean_details']): ?>
                                                <br>
                                                <small style="color: #666;">
                                                    <?php echo htmlspecialchars(substr($corr['clean_details'], 0, 50)); ?>
                                                    <?php echo strlen($corr['clean_details']) > 50 ? '...' : ''; ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div><?php echo date('M d, Y', strtotime($corr['created_at'])); ?></div>
                                            <small style="color: #666;">
                                                <?php echo date('H:i', strtotime($corr['created_at'])); ?> | 
                                                <?php echo htmlspecialchars($corr['requested_by_name']); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="actions">
                                                <button class="action-btn approve" title="Approve" 
                                                        onclick="openCorrectionModal(<?php echo $corr['leave_id']; ?>, 'approve', '<?php echo htmlspecialchars(addslashes($corr['student_name'])); ?>', '<?php echo htmlspecialchars(addslashes($corr['subject_name'])); ?>', '<?php echo $corr['start_date']; ?>')">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button class="action-btn reject" title="Reject" 
                                                        onclick="openCorrectionModal(<?php echo $corr['leave_id']; ?>, 'reject', '<?php echo htmlspecialchars(addslashes($corr['student_name'])); ?>', '<?php echo htmlspecialchars(addslashes($corr['subject_name'])); ?>', '<?php echo $corr['start_date']; ?>')">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                                <button class="action-btn view" title="View Details" 
                                                        onclick='viewCorrectionDetails(<?php echo safeJsonEncode($corr); ?>); event.preventDefault(); event.stopPropagation();'>
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </form>
                </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <h3>No Pending Corrections</h3>
                        <p>All attendance correction requests have been processed.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB 2: UNLOCK REQUESTS -->
        <div id="tab2" class="tab-content">
            <div class="table-wrapper">
                <div class="table-header">
                    <h2>
                        <i class="fas fa-unlock-alt"></i>
                        Teacher Unlock Requests
                        <span style="font-size: 14px; color: #666; font-weight: normal; margin-left: 10px;">
                            (<?php echo count($unlocks); ?> total)
                        </span>
                    </h2>
                </div>

                <?php if (!empty($unlocks)): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Teacher</th>
                                <th>Subject</th>
                                <th>Date</th>
                                <th>Attendance</th>
                                <th>Requested</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($unlocks as $unlock): 
                                $row_class = '';
                                if ($unlock['date_status'] === 'past') $row_class = 'past-date';
                                elseif ($unlock['date_status'] === 'today') $row_class = 'today-date';
                            ?>
                                <tr class="<?php echo $row_class; ?>">
                                    <td>
                                        <strong><?php echo htmlspecialchars($unlock['teacher_name']); ?></strong>
                                        <br>
                                        <small style="color: #666;">
                                            <?php echo htmlspecialchars($unlock['teacher_email']); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($unlock['subject_name']); ?></strong>
                                        <br>
                                        <small style="color: #666;">
                                            <?php echo htmlspecialchars($unlock['subject_code']); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="date-badge date-<?php echo $unlock['date_status']; ?>">
                                            <i class="fas fa-calendar"></i>
                                            <?php echo date('M d, Y', strtotime($unlock['lock_date'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge status-pending">
                                            <i class="fas fa-users"></i>
                                            <?php echo $unlock['attendance_count']; ?> students
                                        </span>
                                    </td>
                                    <td>
                                        <div><?php echo date('M d, Y', strtotime($unlock['locked_at'])); ?></div>
                                        <small style="color: #666;">
                                            <?php echo date('H:i', strtotime($unlock['locked_at'])); ?> | 
                                            <?php echo htmlspecialchars($unlock['requested_by_name']); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="actions">
                                            <button class="action-btn approve" title="Approve Unlock" 
                                                    onclick="openUnlockModal(<?php echo $unlock['lock_id']; ?>, 'approve', '<?php echo htmlspecialchars(addslashes($unlock['teacher_name'])); ?>', '<?php echo htmlspecialchars(addslashes($unlock['subject_name'])); ?>', '<?php echo $unlock['lock_date']; ?>')">
                                                <i class="fas fa-unlock-alt"></i>
                                            </button>
                                            <button class="action-btn reject" title="Reject Unlock" 
                                                    onclick="openUnlockModal(<?php echo $unlock['lock_id']; ?>, 'reject', '<?php echo htmlspecialchars(addslashes($unlock['teacher_name'])); ?>', '<?php echo htmlspecialchars(addslashes($unlock['subject_name'])); ?>', '<?php echo $unlock['lock_date']; ?>')">
                                                <i class="fas fa-times"></i>
                                            </button>
                                            <button class="action-btn view" title="View Details" 
                                                    onclick='viewUnlockDetails(<?php echo safeJsonEncode($unlock); ?>); event.preventDefault(); event.stopPropagation();'>
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-unlock-alt"></i>
                        <h3>No Unlock Requests</h3>
                        <p>All teacher unlock requests have been processed.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB 3: HISTORY -->
        <div id="tab3" class="tab-content">
            <div class="table-wrapper">
                <div class="table-header">
                    <h2>
                        <i class="fas fa-history"></i>
                        Recent Activity (Last 50)
                    </h2>
                </div>

                <?php if (!empty($recent_history)): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Name</th>
                                <th>Subject</th>
                                <th>Details</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Processed By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_history as $hist): ?>
                                <tr>
                                    <td>
                                        <span class="type-badge type-<?php echo $hist['type']; ?>">
                                            <?php echo $hist['type'] === 'correction' ? 'Correction' : 'Unlock'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($hist['student_name']); ?></strong>
                                        <?php if (!empty($hist['reg_no'])): ?>
                                            <br>
                                            <small style="color: #666;"><?php echo htmlspecialchars($hist['reg_no']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($hist['subject_name']); ?>
                                    </td>
                                    <td>
                                        <?php if ($hist['type'] === 'correction' && isset($hist['original_status'])): ?>
                                            <div class="status-change">
                                                <span class="from"><?php echo ucfirst($hist['original_status']); ?></span>
                                                <span class="arrow"><i class="fas fa-arrow-right"></i></span>
                                                <span class="to"><?php echo ucfirst($hist['corrected_status']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <small style="color: #666;">
                                            <?php echo htmlspecialchars(substr($hist['reason_details'] ?? '', 0, 50)); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $hist['status']; ?>">
                                            <?php echo ucfirst($hist['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div><?php echo date('M d, Y', strtotime($hist['action_date'])); ?></div>
                                        <small style="color: #666;">
                                            <?php echo date('H:i', strtotime($hist['processed_at'])); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($hist['processed_by'] ?? 'N/A'); ?></strong>
                                        <br>
                                        <small style="color: #666;">
                                            Req: <?php echo htmlspecialchars($hist['requested_by'] ?? 'N/A'); ?>
                                        </small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <h3>No History Found</h3>
                        <p>No requests have been processed yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- CORRECTION DECISION MODAL -->
    <div id="correctionModal" class="modal-overlay">
        <div class="modal-content">
            <span class="modal-close" onclick="closeCorrectionModal()">&times;</span>
            <h2 id="correctionModalTitle">
                <i class="fas fa-check-circle"></i>
                Process Correction Request
            </h2>
            
            <div id="correctionSummary" class="details-grid" style="display: none;">
                <!-- Will be filled by JS -->
            </div>
            
            <form id="correctionDecisionForm" method="POST">
                <input type="hidden" name="action" id="correctionModalAction" value="">
                <input type="hidden" name="leave_id" id="correctionModalLeaveId" value="">
                
                <div class="form-group">
                    <label for="correctionDecisionNotes">
                        <i class="fas fa-sticky-note"></i> Decision Notes
                        <span style="color: var(--danger-color);">*</span>
                    </label>
                    <textarea name="decision_notes" id="correctionDecisionNotes" class="form-control" 
                              rows="3" placeholder="Enter notes for this decision..."></textarea>
                </div>
                
                <div class="checkbox">
                    <input type="checkbox" name="bypass_teacher" id="correctionBypassTeacher" value="1">
                    <label for="correctionBypassTeacher">
                        <i class="fas fa-envelope"></i> Skip teacher notification
                    </label>
                </div>
                
                <div class="modal-actions">
                    <button type="submit" class="btn btn-success" id="correctionModalSubmitBtn">
                        <i class="fas fa-check"></i> Approve
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="closeCorrectionModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- CORRECTION VIEW DETAILS MODAL -->
    <div id="correctionViewModal" class="modal-overlay">
        <div class="modal-content">
            <span class="modal-close" onclick="closeCorrectionViewModal()">&times;</span>
            <h2>
                <i class="fas fa-info-circle"></i>
                Correction Request Details
            </h2>
            
            <div id="correctionViewDetails" class="details-grid">
                <!-- Will be filled by JS -->
            </div>
            
            <div class="modal-actions">
                <button class="btn btn-success" id="viewApproveBtn" onclick="">
                    <i class="fas fa-check"></i> Approve
                </button>
                <button class="btn btn-danger" id="viewRejectBtn" onclick="">
                    <i class="fas fa-times"></i> Reject
                </button>
                <button class="btn btn-secondary" onclick="closeCorrectionViewModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <!-- UNLOCK DECISION MODAL -->
    <div id="unlockModal" class="modal-overlay">
        <div class="modal-content">
            <span class="modal-close" onclick="closeUnlockModal()">&times;</span>
            <h2 id="unlockModalTitle">
                <i class="fas fa-unlock-alt"></i>
                Process Unlock Request
            </h2>
            
            <div id="unlockSummary" class="details-grid" style="display: none;">
                <!-- Will be filled by JS -->
            </div>
            
            <form id="unlockDecisionForm" method="POST">
                <input type="hidden" name="action" id="unlockModalAction" value="">
                <input type="hidden" name="lock_id" id="unlockModalLockId" value="">
                
                <div class="form-group">
                    <label for="unlockDecisionNotes">
                        <i class="fas fa-sticky-note"></i> Decision Notes
                        <span style="color: var(--danger-color);">*</span>
                    </label>
                    <textarea name="decision_notes" id="unlockDecisionNotes" class="form-control" 
                              rows="3" placeholder="Enter notes for this decision..."></textarea>
                </div>
                
                <div class="modal-actions">
                    <button type="submit" class="btn btn-success" id="unlockModalSubmitBtn">
                        <i class="fas fa-unlock-alt"></i> Approve Unlock
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="closeUnlockModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- UNLOCK VIEW DETAILS MODAL -->
    <div id="unlockViewModal" class="modal-overlay">
        <div class="modal-content">
            <span class="modal-close" onclick="closeUnlockViewModal()">&times;</span>
            <h2>
                <i class="fas fa-info-circle"></i>
                Unlock Request Details
            </h2>
            
            <div id="unlockViewDetails" class="details-grid">
                <!-- Will be filled by JS -->
            </div>
            
            <div class="modal-actions">
                <button class="btn btn-success" id="unlockViewApproveBtn" onclick="">
                    <i class="fas fa-unlock-alt"></i> Approve Unlock
                </button>
                <button class="btn btn-danger" id="unlockViewRejectBtn" onclick="">
                    <i class="fas fa-times"></i> Reject
                </button>
                <button class="btn btn-secondary" onclick="closeUnlockViewModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <!-- BULK CORRECTION MODAL -->
    <div id="bulkCorrectionModal" class="modal-overlay">
        <div class="modal-content">
            <span class="modal-close" onclick="closeBulkCorrectionModal()">&times;</span>
            <h2 id="bulkCorrectionModalTitle">
                <i class="fas fa-check-double"></i>
                Bulk Process Corrections
            </h2>
            
            <div id="selectedCorrectionCount" style="background: #e3f2fd; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                <strong><span id="selectedCorrectionCountNumber">0</span> requests selected</strong>
            </div>
            
            <form id="bulkCorrectionDecisionForm" method="POST" onsubmit="return submitBulkCorrectionForm()">
                <input type="hidden" name="action" id="bulkCorrectionModalAction" value="">
                <input type="hidden" name="bypass_teacher" id="bulkCorrectionModalBypass" value="0">
                
                <div class="form-group">
                    <label for="bulkCorrectionDecisionNotes">
                        <i class="fas fa-sticky-note"></i> Decision Notes
                    </label>
                    <textarea name="decision_notes" id="bulkCorrectionDecisionNotes" class="form-control" 
                              rows="3" placeholder="Enter notes for this bulk action..."></textarea>
                </div>
                
                <div class="checkbox">
                    <input type="checkbox" name="bypass_teacher" id="bulkCorrectionBypassTeacher" value="1">
                    <label for="bulkCorrectionBypassTeacher">
                        <i class="fas fa-envelope"></i> Skip teacher notifications
                    </label>
                </div>
                
                <div class="modal-actions">
                    <button type="submit" class="btn btn-success" id="bulkCorrectionModalSubmitBtn">
                        <i class="fas fa-check-double"></i> Process
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="closeBulkCorrectionModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Tab switching
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                document.getElementById(this.dataset.tab).classList.add('active');
            });
        });

        // Select All for corrections
        function toggleAll(type) {
            const checked = document.getElementById('selectAllCorrection').checked;
            document.querySelectorAll('.select-item-correction').forEach(cb => {
                cb.checked = checked;
            });
        }

        function updateSelectAll(type) {
            const total = document.querySelectorAll('.select-item-correction').length;
            const checked = document.querySelectorAll('.select-item-correction:checked').length;
            document.getElementById('selectAllCorrection').checked = total === checked;
        }

        // Correction Modal
        function openCorrectionModal(leaveId, action, studentName, subjectName, date) {
            document.getElementById('correctionModalTitle').innerHTML = `<i class="fas fa-${action === 'approve' ? 'check' : 'times'}-circle"></i> ${action === 'approve' ? 'Approve' : 'Reject'} Correction`;
            document.getElementById('correctionModalAction').value = action + '_correction';
            document.getElementById('correctionModalLeaveId').value = leaveId;
            
            const btnText = action === 'approve' ? '<i class="fas fa-check"></i> Approve' : '<i class="fas fa-times"></i> Reject';
            const btnClass = action === 'approve' ? 'btn-success' : 'btn-danger';
            document.getElementById('correctionModalSubmitBtn').innerHTML = btnText;
            document.getElementById('correctionModalSubmitBtn').className = 'btn ' + btnClass;
            
            // Show summary
            let summary = '';
            summary += '<div class="detail-item"><div class="detail-label">Student</div><div class="detail-value">' + studentName + '</div></div>';
            summary += '<div class="detail-item"><div class="detail-label">Subject</div><div class="detail-value">' + subjectName + '</div></div>';
            summary += '<div class="detail-item"><div class="detail-label">Date</div><div class="detail-value">' + date + '</div></div>';
            
            document.getElementById('correctionSummary').innerHTML = summary;
            document.getElementById('correctionSummary').style.display = 'grid';
            
            document.getElementById('correctionDecisionNotes').value = '';
            document.getElementById('correctionModal').style.display = 'flex';
        }

        function closeCorrectionModal() {
            document.getElementById('correctionModal').style.display = 'none';
            document.getElementById('correctionSummary').style.display = 'none';
        }

        // Correction View Details
        function viewCorrectionDetails(data) {
            // If data is a string, parse it
            if (typeof data === 'string') {
                try {
                    data = JSON.parse(data);
                } catch (e) {
                    console.error('Error parsing data:', e);
                    return;
                }
            }
            
            let details = '';
            details += '<div class="detail-item"><div class="detail-label">Student</div><div class="detail-value">' + (data.student_name || 'N/A') + '<br><small>' + (data.reg_no || '') + '</small></div></div>';
            details += '<div class="detail-item"><div class="detail-label">Subject</div><div class="detail-value">' + (data.subject_name || 'N/A') + '<br><small>' + (data.subject_code || '') + '</small></div></div>';
            details += '<div class="detail-item"><div class="detail-label">Teacher</div><div class="detail-value">' + (data.teacher_name || 'N/A') + '</div></div>';
            details += '<div class="detail-item"><div class="detail-label">Class</div><div class="detail-value">' + (data.class_name || 'N/A') + '<br><small>' + (data.campus_name || '') + '</small></div></div>';
            details += '<div class="detail-item"><div class="detail-label">Request Date</div><div class="detail-value">' + (data.start_date || 'N/A') + (data.days_count > 1 ? ' (+' + (data.days_count-1) + ' days)' : '') + '</div></div>';
            details += '<div class="detail-item"><div class="detail-label">Status Change</div><div class="detail-value"><span class="status-box original">' + (data.original_status || 'absent') + '</span> → <span class="status-box corrected">' + (data.corrected_status || 'present') + '</span></div></div>';
            details += '<div class="detail-item"><div class="detail-label">Reason</div><div class="detail-value">' + (data.reason || 'N/A') + '</div></div>';
            details += '<div class="detail-item"><div class="detail-label">Details</div><div class="detail-value">' + (data.clean_details || 'No details provided') + '</div></div>';
            details += '<div class="detail-item"><div class="detail-label">Request Type</div><div class="detail-value"><span class="request-badge request-' + (data.requested_by_role || '') + '">' + (data.request_type || 'N/A') + '</span></div></div>';
            details += '<div class="detail-item"><div class="detail-label">Requested By</div><div class="detail-value">' + (data.requested_by_name || 'N/A') + '<br><small>' + (data.requested_by_role || '') + '</small></div></div>';
            details += '<div class="detail-item"><div class="detail-label">Requested On</div><div class="detail-value">' + (data.created_at || 'N/A') + '</div></div>';
            
            document.getElementById('correctionViewDetails').innerHTML = details;
            
            // Set up approve/reject buttons
            document.getElementById('viewApproveBtn').onclick = function() {
                closeCorrectionViewModal();
                openCorrectionModal(data.leave_id, 'approve', data.student_name, data.subject_name, data.start_date);
            };
            document.getElementById('viewRejectBtn').onclick = function() {
                closeCorrectionViewModal();
                openCorrectionModal(data.leave_id, 'reject', data.student_name, data.subject_name, data.start_date);
            };
            
            document.getElementById('correctionViewModal').style.display = 'flex';
        }

        function closeCorrectionViewModal() {
            document.getElementById('correctionViewModal').style.display = 'none';
        }

        // Unlock Modal
        function openUnlockModal(lockId, action, teacherName, subjectName, date) {
            document.getElementById('unlockModalTitle').innerHTML = `<i class="fas fa-${action === 'approve' ? 'unlock-alt' : 'times'}-circle"></i> ${action === 'approve' ? 'Approve' : 'Reject'} Unlock`;
            document.getElementById('unlockModalAction').value = action + '_unlock';
            document.getElementById('unlockModalLockId').value = lockId;
            
            const btnText = action === 'approve' ? '<i class="fas fa-unlock-alt"></i> Approve Unlock' : '<i class="fas fa-times"></i> Reject';
            const btnClass = action === 'approve' ? 'btn-success' : 'btn-danger';
            document.getElementById('unlockModalSubmitBtn').innerHTML = btnText;
            document.getElementById('unlockModalSubmitBtn').className = 'btn ' + btnClass;
            
            // Show summary
            let summary = '';
            summary += '<div class="detail-item"><div class="detail-label">Teacher</div><div class="detail-value">' + teacherName + '</div></div>';
            summary += '<div class="detail-item"><div class="detail-label">Subject</div><div class="detail-value">' + subjectName + '</div></div>';
            summary += '<div class="detail-item"><div class="detail-label">Date</div><div class="detail-value">' + date + '</div></div>';
            
            document.getElementById('unlockSummary').innerHTML = summary;
            document.getElementById('unlockSummary').style.display = 'grid';
            
            document.getElementById('unlockDecisionNotes').value = '';
            document.getElementById('unlockModal').style.display = 'flex';
        }

        function closeUnlockModal() {
            document.getElementById('unlockModal').style.display = 'none';
            document.getElementById('unlockSummary').style.display = 'none';
        }

        // Unlock View Details
        function viewUnlockDetails(data) {
            // If data is a string, parse it
            if (typeof data === 'string') {
                try {
                    data = JSON.parse(data);
                } catch (e) {
                    console.error('Error parsing data:', e);
                    return;
                }
            }
            
            let details = '';
            details += '<div class="detail-item"><div class="detail-label">Teacher</div><div class="detail-value">' + (data.teacher_name || 'N/A') + '<br><small>' + (data.teacher_email || '') + '</small></div></div>';
            details += '<div class="detail-item"><div class="detail-label">Subject</div><div class="detail-value">' + (data.subject_name || 'N/A') + '<br><small>' + (data.subject_code || '') + '</small></div></div>';
            details += '<div class="detail-item"><div class="detail-label">Date</div><div class="detail-value">' + (data.lock_date || 'N/A') + '</div></div>';
            details += '<div class="detail-item"><div class="detail-label">Attendance Count</div><div class="detail-value"><span class="status-badge status-pending"><i class="fas fa-users"></i> ' + (data.attendance_count || 0) + ' students</span></div></div>';
            details += '<div class="detail-item"><div class="detail-label">Requested By</div><div class="detail-value">' + (data.requested_by_name || 'N/A') + '</div></div>';
            details += '<div class="detail-item"><div class="detail-label">Requested On</div><div class="detail-value">' + (data.locked_at || 'N/A') + '</div></div>';
            
            document.getElementById('unlockViewDetails').innerHTML = details;
            
            // Set up approve/reject buttons
            document.getElementById('unlockViewApproveBtn').onclick = function() {
                closeUnlockViewModal();
                openUnlockModal(data.lock_id, 'approve', data.teacher_name, data.subject_name, data.lock_date);
            };
            document.getElementById('unlockViewRejectBtn').onclick = function() {
                closeUnlockViewModal();
                openUnlockModal(data.lock_id, 'reject', data.teacher_name, data.subject_name, data.lock_date);
            };
            
            document.getElementById('unlockViewModal').style.display = 'flex';
        }

        function closeUnlockViewModal() {
            document.getElementById('unlockViewModal').style.display = 'none';
        }

        // Bulk Correction Modal
        function openBulkModal(type, action) {
            if (type !== 'correction') return;
            
            const selected = document.querySelectorAll('.select-item-correction:checked').length;
            
            if (selected === 0) {
                alert('Please select at least one request to process.');
                return;
            }
            
            document.getElementById('bulkCorrectionModalAction').value = 'bulk_' + action;
            document.getElementById('selectedCorrectionCountNumber').textContent = selected;
            
            const title = action === 'approve' ? 'Bulk Approve Corrections' : 'Bulk Reject Corrections';
            const btnText = action === 'approve' ? '<i class="fas fa-check-double"></i> Bulk Approve' : '<i class="fas fa-times-double"></i> Bulk Reject';
            const btnClass = action === 'approve' ? 'btn-success' : 'btn-danger';
            
            document.getElementById('bulkCorrectionModalTitle').innerHTML = `<i class="fas fa-${action === 'approve' ? 'check' : 'times'}-double"></i> ${title}`;
            document.getElementById('bulkCorrectionModalSubmitBtn').innerHTML = btnText;
            document.getElementById('bulkCorrectionModalSubmitBtn').className = 'btn ' + btnClass;
            
            document.getElementById('bulkCorrectionDecisionNotes').value = '';
            document.getElementById('bulkCorrectionModal').style.display = 'flex';
        }

        function closeBulkCorrectionModal() {
            document.getElementById('bulkCorrectionModal').style.display = 'none';
        }

        function submitBulkCorrectionForm() {
            const notes = document.getElementById('bulkCorrectionDecisionNotes').value;
            const action = document.getElementById('bulkCorrectionModalAction').value;
            
            if (action === 'bulk_reject' && !notes) {
                alert('Please provide decision notes for rejection.');
                return false;
            }
            
            document.getElementById('bulkCorrectionAction').value = action;
            document.getElementById('bulkCorrectionNotes').value = notes;
            document.getElementById('bulkCorrectionBypass').value = 
                document.getElementById('bulkCorrectionBypassTeacher').checked ? '1' : '0';
            
            document.getElementById('bulkCorrectionForm').submit();
            return false;
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target === document.getElementById('correctionModal')) {
                closeCorrectionModal();
            }
            if (event.target === document.getElementById('correctionViewModal')) {
                closeCorrectionViewModal();
            }
            if (event.target === document.getElementById('unlockModal')) {
                closeUnlockModal();
            }
            if (event.target === document.getElementById('unlockViewModal')) {
                closeUnlockViewModal();
            }
            if (event.target === document.getElementById('bulkCorrectionModal')) {
                closeBulkCorrectionModal();
            }
        }

        // Auto-close alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => alert.remove());
        }, 5000);
    </script>
        <?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>