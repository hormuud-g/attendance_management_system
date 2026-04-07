<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
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

// ✅ Access Control - UPDATED TO INCLUDE DEPARTMENT_ADMIN
$allowed_roles = ['super_admin', 'campus_admin', 'department_admin', 'teacher', 'student', 'parent'];
if (!isset($_SESSION['user']) || !in_array(strtolower($_SESSION['user']['role']), $allowed_roles)) {
    session_destroy();
    header("Location: ../login.php?error=unauthorized");
    exit;
}

date_default_timezone_set('Africa/Nairobi');
$message = ""; $type = "";

// Current user info
$current_user_id = $_SESSION['user']['user_id'] ?? 0;
$current_user_role = strtolower($_SESSION['user']['role'] ?? '');
$current_user_name = $_SESSION['user']['full_name'] ?? '';
$current_campus_id = $_SESSION['user']['campus_id'] ?? null;
$current_department_id = $_SESSION['user']['department_id'] ?? null; // For department admin

// Get student ID if user is student
$student_id = 0;
if ($current_user_role === 'student') {
    $stmt = $pdo->prepare("SELECT student_id FROM students WHERE user_id = ?");
    $stmt->execute([$current_user_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    $student_id = $student['student_id'] ?? 0;
}

// Get department info if user is department admin
$department_info = null;
$department_stats = [];
if ($current_user_role === 'department_admin' && $current_department_id) {
    // Get department information
    $dept_stmt = $pdo->prepare("
        SELECT d.*, f.faculty_name, c.campus_name 
        FROM departments d
        LEFT JOIN faculties f ON d.faculty_id = f.faculty_id
        LEFT JOIN campus c ON d.campus_id = c.campus_id
        WHERE d.department_id = ?
    ");
    $dept_stmt->execute([$current_department_id]);
    $department_info = $dept_stmt->fetch(PDO::FETCH_ASSOC);
}

/* ================= CREATE ATTENDANCE_CORRECTION TABLE ================= */
function createAttendanceCorrectionTable($pdo) {
    try {
        // First, fix teachers table collation
        $pdo->exec("ALTER TABLE teachers CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        // Create the attendance_correction table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS attendance_correction (
                leave_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                
                student_id INT UNSIGNED NOT NULL,
                subject_id INT UNSIGNED DEFAULT NULL,
                teacher_id INT UNSIGNED DEFAULT NULL,
                academic_term_id INT UNSIGNED NOT NULL,
                campus_id INT UNSIGNED DEFAULT NULL,
                department_id INT UNSIGNED DEFAULT NULL, -- ADDED DEPARTMENT ID
                
                requested_by INT UNSIGNED NOT NULL,
                approved_by INT UNSIGNED DEFAULT NULL,
                
                reason ENUM('sick','family','travel','other') NOT NULL,
                reason_details VARCHAR(500) DEFAULT NULL,
                
                start_date DATE NOT NULL,
                days_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
                end_date DATE GENERATED ALWAYS AS (DATE_ADD(start_date, INTERVAL days_count - 1 DAY)) STORED,
                
                status ENUM('pending','approved','rejected') DEFAULT 'pending',
                is_closed BOOLEAN DEFAULT FALSE,
                
                notification_sent BOOLEAN DEFAULT FALSE,
                notification_date DATETIME DEFAULT NULL,
                
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                -- Foreign Keys
                CONSTRAINT fk_attcorrection_student 
                    FOREIGN KEY (student_id) 
                    REFERENCES students(student_id) 
                    ON DELETE CASCADE 
                    ON UPDATE CASCADE,
                
                CONSTRAINT fk_attcorrection_subject 
                    FOREIGN KEY (subject_id) 
                    REFERENCES subject(subject_id) 
                    ON DELETE SET NULL 
                    ON UPDATE CASCADE,
                
                CONSTRAINT fk_attcorrection_teacher 
                    FOREIGN KEY (teacher_id) 
                    REFERENCES teachers(teacher_id) 
                    ON DELETE SET NULL 
                    ON UPDATE CASCADE,
                
                CONSTRAINT fk_attcorrection_term 
                    FOREIGN KEY (academic_term_id) 
                    REFERENCES academic_term(academic_term_id) 
                    ON DELETE CASCADE 
                    ON UPDATE CASCADE,
                
                CONSTRAINT fk_attcorrection_campus 
                    FOREIGN KEY (campus_id) 
                    REFERENCES campus(campus_id) 
                    ON DELETE SET NULL 
                    ON UPDATE CASCADE,
                    
                CONSTRAINT fk_attcorrection_department 
                    FOREIGN KEY (department_id) 
                    REFERENCES departments(department_id) 
                    ON DELETE SET NULL 
                    ON UPDATE CASCADE,
                
                CONSTRAINT fk_attcorrection_requested_by 
                    FOREIGN KEY (requested_by) 
                    REFERENCES users(user_id) 
                    ON DELETE CASCADE 
                    ON UPDATE CASCADE,
                
                CONSTRAINT fk_attcorrection_approved_by 
                    FOREIGN KEY (approved_by) 
                    REFERENCES users(user_id) 
                    ON DELETE SET NULL 
                    ON UPDATE CASCADE,
                
                -- Indexes
                INDEX idx_student_status (student_id, status),
                INDEX idx_teacher_status (teacher_id, status),
                INDEX idx_subject_term (subject_id, academic_term_id),
                INDEX idx_dates (start_date, end_date),
                INDEX idx_status_date (status, created_at),
                INDEX idx_campus (campus_id),
                INDEX idx_department (department_id), -- ADDED INDEX FOR DEPARTMENT
                
                -- Prevent duplicate pending requests
                UNIQUE KEY uk_pending_request (student_id, subject_id, start_date, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // Create trigger for auto-updating attendance
        $pdo->exec("
            CREATE TRIGGER IF NOT EXISTS tr_attendance_correction_approved
            AFTER UPDATE ON attendance_correction
            FOR EACH ROW
            BEGIN
                DECLARE v_current_date DATE;
                DECLARE v_counter INT DEFAULT 0;
                
                IF OLD.status = 'pending' AND NEW.status = 'approved' THEN
                    SET v_current_date = NEW.start_date;
                    
                    WHILE v_counter < NEW.days_count DO
                        INSERT INTO attendance (
                            student_id, class_id, teacher_id, subject_id, 
                            academic_term_id, attendance_date, status, created_at
                        )
                        SELECT 
                            NEW.student_id,
                            tt.class_id,
                            tt.teacher_id,
                            tt.subject_id,
                            NEW.academic_term_id,
                            v_current_date,
                            'excused',
                            NOW()
                        FROM timetable tt
                        WHERE tt.subject_id = NEW.subject_id
                        AND tt.teacher_id = NEW.teacher_id
                        ON DUPLICATE KEY UPDATE 
                            status = 'excused',
                            updated_at = NOW();
                        
                        SET v_current_date = DATE_ADD(v_current_date, INTERVAL 1 DAY);
                        SET v_counter = v_counter + 1;
                    END WHILE;
                    
                    UPDATE attendance_correction 
                    SET notification_sent = TRUE,
                        notification_date = NOW()
                    WHERE leave_id = NEW.leave_id;
                END IF;
            END
        ");
        
        return true;
    } catch (Exception $e) {
        error_log("Error creating attendance_correction table: " . $e->getMessage());
        return false;
    }
}

// Create table if it doesn't exist
createAttendanceCorrectionTable($pdo);

/* ================= EMAIL SENDING FUNCTION ================= */
function sendLeaveEmail($to, $subject, $message, $leave_id, $pdo) {
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

        if ($mail->send()) {
            $stmt = $pdo->prepare("
                UPDATE attendance_correction 
                SET notification_sent = TRUE, notification_date = NOW() 
                WHERE leave_id = ?
            ");
            $stmt->execute([$leave_id]);
            return true;
        }
        return false;
    } catch (Exception $e) {
        error_log("Email error: " . $e->getMessage());
        return false;
    }
}

/* ================= GET DEPARTMENT INFO FOR DEPARTMENT ADMIN ================= */
if ($current_user_role === 'department_admin' && $current_department_id) {
    // Get department statistics
    $dept_stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT t.teacher_id) as total_teachers,
            COUNT(DISTINCT sub.subject_id) as total_subjects,
            COUNT(DISTINCT c.class_id) as total_classes,
            COUNT(DISTINCT se.student_id) as total_students
        FROM departments d
        LEFT JOIN teachers t ON t.department_id = d.department_id AND t.status = 'active'
        LEFT JOIN subject sub ON sub.department_id = d.department_id
        LEFT JOIN classes c ON c.department_id = d.department_id
        LEFT JOIN student_enroll se ON se.class_id = c.class_id AND se.status = 'active'
        WHERE d.department_id = ?
    ");
    $dept_stats_stmt->execute([$current_department_id]);
    $department_stats = $dept_stats_stmt->fetch(PDO::FETCH_ASSOC);
}

/* ================= ACTIVE TERM ================= */
$term = $pdo->query("SELECT academic_term_id FROM academic_term WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$academic_term_id = $term['academic_term_id'] ?? null;

/* ================= HANDLE LEAVE REQUESTS ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $pdo->beginTransaction();
        $action = $_POST['action'];

        if ($action === 'request_leave') {
            // Student requesting leave
            $student_id = intval($_POST['student_id'] ?? 0);
            $subject_id = intval($_POST['subject_id'] ?? 0);
            $teacher_id = intval($_POST['teacher_id'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            $reason_details = trim($_POST['reason_details'] ?? '');
            $start_date = trim($_POST['start_date'] ?? '');
            $days_count = intval($_POST['days_count'] ?? 1);
            
            // Validation
            if (!$student_id || !$subject_id || !$start_date || !$reason) {
                throw new Exception("All required fields must be filled!");
            }
            
            if ($days_count < 1 || $days_count > 30) {
                throw new Exception("Leave days must be between 1 and 30!");
            }
            
            if (strtotime($start_date) < strtotime(date('Y-m-d'))) {
                throw new Exception("Cannot request leave for past dates!");
            }
            
            // Check if student exists and is active
            $stmt = $pdo->prepare("
                SELECT s.student_id, s.full_name, s.email, s.class_id, s.campus_id,
                       t.teacher_id, t.teacher_name, t.email as teacher_email,
                       sub.subject_name, sub.department_id,
                       c.class_name, d.department_name
                FROM students s
                JOIN timetable tt ON s.class_id = tt.class_id
                JOIN teachers t ON tt.teacher_id = t.teacher_id
                JOIN subject sub ON tt.subject_id = sub.subject_id
                JOIN classes c ON s.class_id = c.class_id
                LEFT JOIN departments d ON sub.department_id = d.department_id
                WHERE s.student_id = ? 
                AND tt.subject_id = ?
                AND s.status = 'active'
                LIMIT 1
            ");
            $stmt->execute([$student_id, $subject_id]);
            $student_info = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$student_info) {
                throw new Exception("Student not found or not enrolled in this subject!");
            }
            
            // Check for overlapping leave requests
            $check_stmt = $pdo->prepare("
                SELECT COUNT(*) as overlap_count 
                FROM attendance_correction 
                WHERE student_id = ? 
                AND subject_id = ?
                AND status IN ('pending', 'approved')
                AND (
                    (start_date BETWEEN ? AND DATE_ADD(?, INTERVAL ? - 1 DAY))
                    OR
                    (DATE_ADD(start_date, INTERVAL days_count - 1 DAY) BETWEEN ? AND DATE_ADD(?, INTERVAL ? - 1 DAY))
                    OR
                    (? BETWEEN start_date AND DATE_ADD(start_date, INTERVAL days_count - 1 DAY))
                )
            ");
            $check_stmt->execute([
                $student_id, $subject_id,
                $start_date, $start_date, $days_count,
                $start_date, $start_date, $days_count,
                $start_date
            ]);
            $overlap = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($overlap['overlap_count'] > 0) {
                throw new Exception("You already have a pending or approved leave request for this period!");
            }
            
            // Insert leave request with campus_id and department_id
            $insert_stmt = $pdo->prepare("
                INSERT INTO attendance_correction 
                (student_id, subject_id, teacher_id, academic_term_id, campus_id, department_id,
                 requested_by, reason, reason_details, start_date, days_count, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            $insert_stmt->execute([
                $student_id, $subject_id, $teacher_id, $academic_term_id, 
                $student_info['campus_id'], $student_info['department_id'],
                $current_user_id, $reason, $reason_details, $start_date, $days_count
            ]);
            
            $leave_id = $pdo->lastInsertId();
            
            // Send email to teacher
            if (!empty($student_info['teacher_email'])) {
                $teacher_subject = "New Leave Request - {$student_info['full_name']}";
                $teacher_message = "Dear {$student_info['teacher_name']},\n\n";
                $teacher_message .= "You have a new leave request from student:\n";
                $teacher_message .= "Student: {$student_info['full_name']}\n";
                $teacher_message .= "Subject: {$student_info['subject_name']}\n";
                $teacher_message .= "Period: {$start_date} for {$days_count} day(s)\n";
                $teacher_message .= "Reason: " . ucfirst($reason) . "\n";
                $teacher_message .= "Details: {$reason_details}\n\n";
                $teacher_message .= "Please login to the system to approve or reject this request.\n\n";
                $teacher_message .= "Best regards,\nAcademic Affairs Department";
                
                sendLeaveEmail($student_info['teacher_email'], $teacher_subject, $teacher_message, $leave_id, $pdo);
            }
            
            // Also notify department admin if exists
            if ($student_info['department_id']) {
                $dept_admin_stmt = $pdo->prepare("
                    SELECT u.email, u.full_name 
                    FROM users u 
                    WHERE u.linked_table = 'department' 
                    AND u.linked_id = ?
                    AND u.role = 'department_admin'
                    AND u.status = 'active'
                ");
                $dept_admin_stmt->execute([$student_info['department_id']]);
                $dept_admin = $dept_admin_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($dept_admin && !empty($dept_admin['email'])) {
                    $admin_subject = "New Leave Request - Department {$student_info['department_name']}";
                    $admin_message = "Dear Department Administrator,\n\n";
                    $admin_message .= "A new leave request has been submitted in your department:\n\n";
                    $admin_message .= "Student: {$student_info['full_name']}\n";
                    $admin_message .= "Subject: {$student_info['subject_name']}\n";
                    $admin_message .= "Teacher: {$student_info['teacher_name']}\n";
                    $admin_message .= "Period: {$start_date} for {$days_count} day(s)\n";
                    $admin_message .= "Reason: " . ucfirst($reason) . "\n";
                    $admin_message .= "Details: {$reason_details}\n\n";
                    $admin_message .= "Request ID: {$leave_id}\n\n";
                    $admin_message .= "Best regards,\nAcademic Affairs Department";
                    
                    sendLeaveEmail($dept_admin['email'], $admin_subject, $admin_message, $leave_id, $pdo);
                }
            }
            
            $message = "✅ Leave request submitted successfully! The teacher has been notified.";
            $type = "success";
            
        } elseif ($action === 'approve_leave' || $action === 'reject_leave') {
            // Teacher/Admin approving or rejecting leave
            if (!in_array($current_user_role, ['super_admin', 'campus_admin', 'department_admin', 'teacher'])) {
                throw new Exception("Unauthorized action!");
            }
            
            $leave_id = intval($_POST['leave_id'] ?? 0);
            $decision_notes = trim($_POST['decision_notes'] ?? '');
            
            if (!$leave_id) {
                throw new Exception("Invalid leave request!");
            }
            
            // Get leave details
            $leave_stmt = $pdo->prepare("
                SELECT ac.*, 
                       s.full_name, s.email as student_email, s.phone_number, s.campus_id, s.class_id,
                       t.teacher_id, t.teacher_name, t.email as teacher_email,
                       sub.subject_name, sub.department_id,
                       u.username as requested_by_name,
                       c.campus_name, d.department_name
                FROM attendance_correction ac
                JOIN students s ON ac.student_id = s.student_id
                LEFT JOIN teachers t ON ac.teacher_id = t.teacher_id
                LEFT JOIN subject sub ON ac.subject_id = sub.subject_id
                LEFT JOIN users u ON ac.requested_by = u.user_id
                LEFT JOIN campus c ON s.campus_id = c.campus_id
                LEFT JOIN departments d ON sub.department_id = d.department_id
                WHERE ac.leave_id = ?
            ");
            $leave_stmt->execute([$leave_id]);
            $leave = $leave_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$leave) {
                throw new Exception("Leave request not found!");
            }
            
            // Check authorization based on role
            if ($current_user_role === 'teacher') {
                // Check if teacher teaches this subject
                $teach_check = $pdo->prepare("
                    SELECT COUNT(*) as teaches 
                    FROM timetable 
                    WHERE teacher_id = (
                        SELECT teacher_id FROM teachers WHERE user_id = ?
                    )
                    AND subject_id = ?
                ");
                $teach_check->execute([$current_user_id, $leave['subject_id']]);
                $teaches = $teach_check->fetch(PDO::FETCH_ASSOC);
                
                if (!$teaches['teaches']) {
                    throw new Exception("You can only approve leave requests for your subjects!");
                }
            } elseif ($current_user_role === 'department_admin') {
                // Check if department admin has access to this department
                if ($leave['department_id'] != $current_department_id) {
                    throw new Exception("You can only manage leave requests from your department!");
                }
            } elseif ($current_user_role === 'campus_admin') {
                // Check if campus admin has access to this student's campus
                if ($leave['campus_id'] != $current_campus_id) {
                    throw new Exception("You can only manage leave requests from your campus!");
                }
            }
            
            $new_status = ($action === 'approve_leave') ? 'approved' : 'rejected';
            
            // Update leave status
            $update_stmt = $pdo->prepare("
                UPDATE attendance_correction 
                SET status = ?, 
                    approved_by = ?, 
                    reason_details = CONCAT(COALESCE(reason_details, ''), ' | Decision Notes: ', ?),
                    updated_at = NOW()
                WHERE leave_id = ?
            ");
            $update_stmt->execute([$new_status, $current_user_id, $decision_notes, $leave_id]);
            
            // Send email notification to student
            if (!empty($leave['student_email'])) {
                $status_text = ($new_status === 'approved') ? 'APPROVED' : 'REJECTED';
                $email_subject = "Leave Request {$status_text} - {$leave['subject_name']}";
                
                $email_message = "Dear {$leave['full_name']},\n\n";
                $email_message .= "Your leave request has been {$status_text}.\n\n";
                $email_message .= "📋 Request Details:\n";
                $email_message .= "────────────────────\n";
                $email_message .= "Subject: {$leave['subject_name']}\n";
                $email_message .= "Period: {$leave['start_date']} for {$leave['days_count']} day(s)\n";
                $email_message .= "Reason: " . ucfirst($leave['reason']) . "\n";
                $email_message .= "Original Details: {$leave['reason_details']}\n\n";
                
                if ($new_status === 'approved') {
                    $email_message .= "✅ APPROVAL DETAILS:\n";
                    $email_message .= "Approved by: {$current_user_name}\n";
                    $email_message .= "Your attendance will be marked as 'EXCUSED' for this period.\n";
                    $email_message .= "No penalty will be applied for these absences.\n";
                } else {
                    $email_message .= "❌ REJECTION DETAILS:\n";
                    $email_message .= "Rejected by: {$current_user_name}\n";
                    $email_message .= "Reason: {$decision_notes}\n";
                    $email_message .= "Please attend classes as scheduled.\n";
                }
                
                $email_message .= "\nDecision Notes: {$decision_notes}\n\n";
                $email_message .= "📞 Contact your teacher if you have questions.\n\n";
                $email_message .= "Best regards,\nAcademic Affairs Department";
                
                sendLeaveEmail($leave['student_email'], $email_subject, $email_message, $leave_id, $pdo);
            }
            
            // Also notify parent if available
            $parent_stmt = $pdo->prepare("
                SELECT p.email, p.full_name 
                FROM parents p
                JOIN students s ON p.parent_id = s.parent_id
                WHERE s.student_id = ?
            ");
            $parent_stmt->execute([$leave['student_id']]);
            $parent = $parent_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($parent && !empty($parent['email'])) {
                $parent_subject = "Student Leave Request Update - {$leave['full_name']}";
                $parent_message = "Dear {$parent['full_name']},\n\n";
                $parent_message .= "Your child's leave request has been {$status_text}.\n\n";
                $parent_message .= "Student: {$leave['full_name']}\n";
                $parent_message .= "Subject: {$leave['subject_name']}\n";
                $parent_message .= "Period: {$leave['start_date']} for {$leave['days_count']} day(s)\n";
                $parent_message .= "Status: {$status_text}\n";
                $parent_message .= "Decision Notes: {$decision_notes}\n\n";
                $parent_message .= "Best regards,\nAcademic Affairs Department";
                
                sendLeaveEmail($parent['email'], $parent_subject, $parent_message, $leave_id, $pdo);
            }
            
            $action_text = ($new_status === 'approved') ? 'approved' : 'rejected';
            $message = "✅ Leave request {$action_text} successfully! Notifications sent.";
            $type = "success";
            
        } elseif ($action === 'cancel_leave') {
            // Student canceling their own leave request
            $leave_id = intval($_POST['leave_id'] ?? 0);
            
            if (!$leave_id) {
                throw new Exception("Invalid leave request!");
            }
            
            // Check ownership
            $check_stmt = $pdo->prepare("
                SELECT ac.*, s.full_name, s.email
                FROM attendance_correction ac
                JOIN students s ON ac.student_id = s.student_id
                WHERE ac.leave_id = ? 
                AND ac.requested_by = ?
                AND ac.status = 'pending'
            ");
            $check_stmt->execute([$leave_id, $current_user_id]);
            $leave = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$leave) {
                throw new Exception("Leave request not found or you cannot cancel it!");
            }
            
            // Delete leave request
            $delete_stmt = $pdo->prepare("DELETE FROM attendance_correction WHERE leave_id = ?");
            $delete_stmt->execute([$leave_id]);
            
            $message = "✅ Leave request cancelled successfully!";
            $type = "success";
        }

        $pdo->commit();

    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "❌ Error: " . $e->getMessage();
        $type = "error";
    }
}

/* ================= FETCH DATA BASED ON USER ROLE ================= */

// Get active term info
$term_info = $pdo->query("
    SELECT at.*, ay.year_name 
    FROM academic_term at
    JOIN academic_year ay ON at.academic_year_id = ay.academic_year_id
    WHERE at.status = 'active'
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

// For students: get their subjects
$student_subjects = [];
if ($current_user_role === 'student' && $student_id) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT 
            sub.subject_id,
            sub.subject_name,
            t.teacher_id,
            t.teacher_name,
            t.email as teacher_email,
            c.class_name,
            tt.day_of_week,
            tt.start_time,
            tt.end_time,
            (SELECT COUNT(*) 
             FROM attendance a 
             WHERE a.student_id = ? 
             AND a.subject_id = sub.subject_id 
             AND a.status = 'absent'
             AND a.academic_term_id = ?) as total_absences
        FROM subject sub
        JOIN timetable tt ON sub.subject_id = tt.subject_id
        JOIN teachers t ON tt.teacher_id = t.teacher_id
        JOIN classes c ON tt.class_id = c.class_id
        WHERE tt.class_id IN (
            SELECT class_id FROM student_enroll WHERE student_id = ?
        )
        AND (tt.academic_term_id = ? OR tt.academic_term_id IS NULL)
        ORDER BY sub.subject_name
    ");
    $stmt->execute([$student_id, $academic_term_id, $student_id, $academic_term_id]);
    $student_subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// For teachers: get their subjects and pending requests
$teacher_subjects = [];
$pending_leaves = [];
if ($current_user_role === 'teacher') {
    // Get teacher's subjects
    $stmt = $pdo->prepare("
        SELECT DISTINCT 
            sub.subject_id,
            sub.subject_name,
            c.class_id,
            c.class_name,
            COUNT(DISTINCT se.student_id) as total_students,
            tt.day_of_week,
            tt.start_time,
            tt.end_time
        FROM subject sub
        JOIN timetable tt ON sub.subject_id = tt.subject_id
        JOIN classes c ON tt.class_id = c.class_id
        LEFT JOIN student_enroll se ON c.class_id = se.class_id AND se.status = 'active'
        WHERE tt.teacher_id = (
            SELECT teacher_id FROM teachers WHERE user_id = ?
        )
        AND (tt.academic_term_id = ? OR tt.academic_term_id IS NULL)
        GROUP BY sub.subject_id, c.class_id
        ORDER BY c.class_name, sub.subject_name
    ");
    $stmt->execute([$current_user_id, $academic_term_id]);
    $teacher_subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get pending leave requests
    $leave_stmt = $pdo->prepare("
        SELECT 
            ac.*,
            s.full_name as student_name,
            s.reg_no,
            s.email as student_email,
            s.phone_number,
            sub.subject_name,
            c.class_name,
            u.username as requested_by_name,
            DATEDIFF(ac.start_date, CURDATE()) as days_until_start
        FROM attendance_correction ac
        JOIN students s ON ac.student_id = s.student_id
        JOIN subject sub ON ac.subject_id = sub.subject_id
        JOIN timetable tt ON sub.subject_id = tt.subject_id
        JOIN classes c ON tt.class_id = c.class_id
        JOIN users u ON ac.requested_by = u.user_id
        WHERE ac.status = 'pending'
        AND ac.teacher_id = (
            SELECT teacher_id FROM teachers WHERE user_id = ?
        )
        AND ac.academic_term_id = ?
        ORDER BY ac.start_date ASC, ac.created_at ASC
    ");
    $leave_stmt->execute([$current_user_id, $academic_term_id]);
    $pending_leaves = $leave_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// For department_admin: get pending requests for their department
$department_pending_leaves = [];
if ($current_user_role === 'department_admin' && $current_department_id) {
    $dept_leave_stmt = $pdo->prepare("
        SELECT 
            ac.*,
            s.full_name as student_name,
            s.reg_no,
            s.email as student_email,
            s.phone_number,
            sub.subject_name,
            t.teacher_name,
            c.class_name,
            camp.campus_name,
            u.username as requested_by_name,
            DATEDIFF(ac.start_date, CURDATE()) as days_until_start
        FROM attendance_correction ac
        JOIN students s ON ac.student_id = s.student_id
        LEFT JOIN subject sub ON ac.subject_id = sub.subject_id
        LEFT JOIN teachers t ON ac.teacher_id = t.teacher_id
        LEFT JOIN classes cl ON s.class_id = cl.class_id
        LEFT JOIN users u ON ac.requested_by = u.user_id
        LEFT JOIN campus camp ON s.campus_id = camp.campus_id
        WHERE ac.status = 'pending'
        AND sub.department_id = ?
        AND ac.academic_term_id = ?
        ORDER BY ac.start_date ASC, ac.created_at ASC
    ");
    $dept_leave_stmt->execute([$current_department_id, $academic_term_id]);
    $department_pending_leaves = $dept_leave_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// For campus_admin: get pending requests for their campus
$campus_pending_leaves = [];
if ($current_user_role === 'campus_admin' && $current_campus_id) {
    $campus_leave_stmt = $pdo->prepare("
        SELECT 
            ac.*,
            s.full_name as student_name,
            s.reg_no,
            s.email as student_email,
            s.phone_number,
            sub.subject_name,
            t.teacher_name,
            c.class_name,
            d.department_name,
            u.username as requested_by_name,
            DATEDIFF(ac.start_date, CURDATE()) as days_until_start
        FROM attendance_correction ac
        JOIN students s ON ac.student_id = s.student_id
        LEFT JOIN subject sub ON ac.subject_id = sub.subject_id
        LEFT JOIN teachers t ON ac.teacher_id = t.teacher_id
        LEFT JOIN classes cl ON s.class_id = cl.class_id
        LEFT JOIN departments d ON sub.department_id = d.department_id
        LEFT JOIN users u ON ac.requested_by = u.user_id
        WHERE ac.status = 'pending'
        AND s.campus_id = ?
        AND ac.academic_term_id = ?
        ORDER BY ac.start_date ASC, ac.created_at ASC
    ");
    $campus_leave_stmt->execute([$current_campus_id, $academic_term_id]);
    $campus_pending_leaves = $campus_leave_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get leave history for current user
$history_query = "";
$history_params = [];

if ($current_user_role === 'student') {
    $history_query = "
        SELECT 
            ac.*,
            sub.subject_name,
            t.teacher_name,
            c.class_name,
            u.username as approved_by_name,
            CASE 
                WHEN ac.status = 'approved' AND CURDATE() BETWEEN ac.start_date AND ac.end_date 
                THEN 'active'
                WHEN ac.status = 'approved' AND CURDATE() > ac.end_date 
                THEN 'expired'
                ELSE ac.status
            END as display_status
        FROM attendance_correction ac
        LEFT JOIN subject sub ON ac.subject_id = sub.subject_id
        LEFT JOIN teachers t ON ac.teacher_id = t.teacher_id
        LEFT JOIN timetable tt ON ac.subject_id = tt.subject_id
        LEFT JOIN classes c ON tt.class_id = c.class_id
        LEFT JOIN users u ON ac.approved_by = u.user_id
        WHERE ac.requested_by = ?
        ORDER BY ac.created_at DESC
    ";
    $history_params = [$current_user_id];
} elseif ($current_user_role === 'teacher') {
    $history_query = "
        SELECT 
            ac.*,
            sub.subject_name,
            s.full_name as student_name,
            s.reg_no,
            c.class_name,
            u.username as requested_by_name,
            u2.full_name as approved_by_name
        FROM attendance_correction ac
        JOIN students s ON ac.student_id = s.student_id
        LEFT JOIN subject sub ON ac.subject_id = sub.subject_id
        LEFT JOIN timetable tt ON ac.subject_id = tt.subject_id
        LEFT JOIN classes c ON tt.class_id = c.class_id
        LEFT JOIN users u ON ac.requested_by = u.user_id
        LEFT JOIN users u2 ON ac.approved_by = u2.user_id
        WHERE ac.teacher_id = (
            SELECT teacher_id FROM teachers WHERE user_id = ?
        )
        ORDER BY ac.created_at DESC
    ";
    $history_params = [$current_user_id];
} elseif ($current_user_role === 'department_admin' && $current_department_id) {
    $history_query = "
        SELECT 
            ac.*,
            sub.subject_name,
            s.full_name as student_name,
            s.reg_no,
            t.teacher_name,
            c.class_name,
            camp.campus_name,
            u.username as requested_by_name,
            u2.username as approved_by_name
        FROM attendance_correction ac
        JOIN students s ON ac.student_id = s.student_id
        LEFT JOIN subject sub ON ac.subject_id = sub.subject_id
        LEFT JOIN teachers t ON ac.teacher_id = t.teacher_id
        LEFT JOIN classes c ON s.class_id = c.class_id
        LEFT JOIN campus camp ON s.campus_id = camp.campus_id
        LEFT JOIN users u ON ac.requested_by = u.user_id
        LEFT JOIN users u2 ON ac.approved_by = u2.user_id
        WHERE sub.department_id = ?
        ORDER BY ac.created_at DESC
    ";
    $history_params = [$current_department_id];
} elseif ($current_user_role === 'campus_admin' && $current_campus_id) {
    $history_query = "
        SELECT 
            ac.*,
            sub.subject_name,
            s.full_name as student_name,
            s.reg_no,
            t.teacher_name,
            c.class_name,
            d.department_name,
            u.username as requested_by_name,
            u2.username as approved_by_name
        FROM attendance_correction ac
        JOIN students s ON ac.student_id = s.student_id
        LEFT JOIN subject sub ON ac.subject_id = sub.subject_id
        LEFT JOIN teachers t ON ac.teacher_id = t.teacher_id
        LEFT JOIN classes c ON s.class_id = c.class_id
        LEFT JOIN departments d ON sub.department_id = d.department_id
        LEFT JOIN users u ON ac.requested_by = u.user_id
        LEFT JOIN users u2 ON ac.approved_by = u2.user_id
        WHERE s.campus_id = ?
        ORDER BY ac.created_at DESC
    ";
    $history_params = [$current_campus_id];
} else {
    $history_query = "
        SELECT 
            ac.*,
            sub.subject_name,
            s.full_name as student_name,
            s.reg_no,
            t.teacher_name,
            c.class_name,
            u.username as requested_by_name,
            u2.username as approved_by_name
        FROM attendance_correction ac
        JOIN students s ON ac.student_id = s.student_id
        LEFT JOIN subject sub ON ac.subject_id = sub.subject_id
        LEFT JOIN teachers t ON ac.teacher_id = t.teacher_id
        LEFT JOIN timetable tt ON ac.subject_id = tt.subject_id
        LEFT JOIN classes c ON tt.class_id = c.class_id
        LEFT JOIN users u ON ac.requested_by = u.user_id
        LEFT JOIN users u2 ON ac.approved_by = u2.user_id
        ORDER BY ac.created_at DESC
    ";
}

$history_stmt = $pdo->prepare($history_query);
$history_stmt->execute($history_params);
$leave_history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistics
$stats = [
    'total' => 0,
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0
];

foreach ($leave_history as $record) {
    $stats['total']++;
    $stats[$record['status']]++;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Correction System</title>
    <link rel="icon" type="image/png" href="../images.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
/* ================= GLOBAL STYLES ================= */
:root {
    --primary-color: #00843D;
    --primary-gradient: linear-gradient(135deg, #00843D 0%, #00A651 100%);
    --secondary-color: #0072CE;
    --success-color: #00A651;
    --warning-color: #FFB400;
    --danger-color: #C62828;
    --info-color: #6A11CB;
    --dept-color: #FF6B6B;
    --light-bg: #F5F9F7;
    --white: #FFFFFF;
    --dark-text: #333333;
    --medium-text: #666666;
    --light-text: #999999;
    --border-color: #E0E0E0;
    --shadow-light: 0 4px 12px rgba(0, 0, 0, 0.06);
    --shadow-medium: 0 8px 25px rgba(0, 0, 0, 0.08);
    --shadow-heavy: 0 10px 30px rgba(0, 0, 0, 0.15);
    --border-radius: 12px;
    --border-radius-sm: 8px;
    --transition: all 0.3s ease;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body { 
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
    background: var(--light-bg);
    min-height: 100vh;
    margin: 0; 
    padding: 0;
    color: var(--dark-text);
    line-height: 1.6;
}

/* ================= MAIN CONTENT ================= */
.main-content {
    padding: 20px;
    margin-left: 250px;
    margin-top: 70px;
    transition: margin-left 0.3s ease;
    background: var(--white);
    min-height: calc(100vh - 70px);
    border-radius: 15px 0 0 0;
    box-shadow: -2px 0 20px rgba(0, 0, 0, 0.05);
}

.sidebar.collapsed ~ .main-content {
    margin-left: 70px;
}

@media (max-width: 768px) {
    .main-content {
        margin-left: 0;
        padding: 15px;
        border-radius: 0;
    }
}

/* ================= PAGE HEADER ================= */
.page-header {
    margin-bottom: 30px;
    padding: 25px;
    background: var(--primary-gradient);
    border-radius: var(--border-radius);
    color: var(--white);
    box-shadow: 0 8px 25px rgb(226, 240, 233);
}

.page-header h1 {
    color: var(--white);
    font-weight: 700;
    font-size: 32px;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 15px;
}

.page-header h1 span {
    font-size: 16px;
    opacity: 0.9;
    margin-left: auto;
    font-weight: 500;
}

@media (max-width: 768px) {
    .page-header {
        padding: 20px 15px;
    }
    
    .page-header h1 {
        font-size: 24px;
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    
    .page-header h1 span {
        margin-left: 0;
        font-size: 14px;
    }
}

/* ================= DEPARTMENT HEADER ================= */
.department-header {
    background: linear-gradient(135deg, #FF6B6B 0%, #FF8E53 100%);
    padding: 25px;
    border-radius: var(--border-radius);
    color: var(--white);
    margin-bottom: 25px;
    box-shadow: 0 4px 15px rgb(255, 255, 255);
}

.department-header h2 {
    margin: 0 0 15px 0;
    font-size: 24px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}

.department-header p {
    margin: 8px 0;
    opacity: 0.9;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.department-header p i {
    width: 16px;
    text-align: center;
}

/* ================= CAMPUS HEADER ================= */
.campus-header {
    background: linear-gradient(135deg, #6A11CB 0%, #2575FC 100%);
    padding: 25px;
    border-radius: var(--border-radius);
    color: var(--white);
    margin-bottom: 25px;
    box-shadow: 0 4px 15px rgba(106, 17, 203, 0.2);
}

.campus-header h2 {
    margin: 0 0 15px 0;
    font-size: 24px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}

.campus-header p {
    margin: 8px 0;
    opacity: 0.9;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.campus-header p i {
    width: 16px;
    text-align: center;
}

/* ================= STATISTICS CARDS ================= */
.stats-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: var(--white);
    padding: 25px;
    border-radius: var(--border-radius);
    text-align: center;
    box-shadow: var(--shadow-light);
    transition: var(--transition);
    border-top: 5px solid;
    position: relative;
    overflow: hidden;
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: inherit;
    opacity: 0.1;
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-medium);
}

.stat-card.total { border-color: var(--primary-color); }
.stat-card.pending { border-color: var(--warning-color); }
.stat-card.approved { border-color: var(--success-color); }
.stat-card.rejected { border-color: var(--danger-color); }
.stat-card.campus-admin { border-color: var(--info-color); }
.stat-card.dept-admin { border-color: var(--dept-color); }

.stat-card i {
    font-size: 36px;
    margin-bottom: 15px;
    display: block;
}

.stat-card.total i { color: var(--primary-color); }
.stat-card.pending i { color: var(--warning-color); }
.stat-card.approved i { color: var(--success-color); }
.stat-card.rejected i { color: var(--danger-color); }
.stat-card.campus-admin i { color: var(--info-color); }
.stat-card.dept-admin i { color: var(--dept-color); }

.stat-card .number {
    font-size: 32px;
    font-weight: 700;
    display: block;
    line-height: 1;
    margin: 10px 0;
    color: var(--dark-text);
}

.stat-card .label {
    font-size: 13px;
    color: var(--medium-text);
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 600;
}

/* Department Stats */
.dept-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
    margin-bottom: 25px;
}

.dept-stat-card {
    background: var(--white);
    padding: 20px;
    border-radius: var(--border-radius-sm);
    text-align: center;
    box-shadow: var(--shadow-light);
    transition: var(--transition);
    border-top: 3px solid var(--dept-color);
}

.dept-stat-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-medium);
}

.dept-stat-card .number {
    font-size: 28px;
    font-weight: bold;
    color: var(--dept-color);
    display: block;
    margin-bottom: 8px;
}

.dept-stat-card .label {
    font-size: 12px;
    color: var(--medium-text);
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 600;
}

/* Campus Stats */
.campus-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
    margin-bottom: 25px;
}

.campus-stat-card {
    background: var(--white);
    padding: 20px;
    border-radius: var(--border-radius-sm);
    text-align: center;
    box-shadow: var(--shadow-light);
    transition: var(--transition);
    border-top: 3px solid var(--info-color);
}

.campus-stat-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-medium);
}

.campus-stat-card .number {
    font-size: 28px;
    font-weight: bold;
    color: var(--info-color);
    display: block;
    margin-bottom: 8px;
}

.campus-stat-card .label {
    font-size: 12px;
    color: var(--medium-text);
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 600;
}

@media (max-width: 768px) {
    .stats-cards {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .dept-stats, .campus-stats {
        grid-template-columns: repeat(2, 1fr);
    }
}

/* ================= TABS ================= */
.tabs {
    display: flex;
    gap: 5px;
    margin-bottom: 30px;
    background: var(--white);
    padding: 8px;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-light);
    flex-wrap: wrap;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.tab-btn {
    flex: 1;
    min-width: 160px;
    background: transparent;
    color: var(--medium-text);
    border: none;
    padding: 14px 18px;
    border-radius: var(--border-radius-sm);
    cursor: pointer;
    font-weight: 600;
    font-size: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    transition: var(--transition);
    position: relative;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}

.tab-btn:hover {
    background: rgba(0, 132, 61, 0.08);
    color: var(--primary-color);
    transform: translateY(-1px);
}

.tab-btn.active {
    background: var(--primary-gradient);
    color: var(--white);
    box-shadow: 0 4px 15px rgba(0, 132, 61, 0.2);
}

.notification-badge {
    position: absolute;
    top: -8px;
    right: -8px;
    background: var(--danger-color);
    color: var(--white);
    border-radius: 50%;
    width: 22px;
    height: 22px;
    font-size: 11px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    box-shadow: 0 2px 8px rgba(198, 40, 40, 0.2);
    z-index: 1;
}

@media (max-width: 768px) {
    .tabs {
        flex-direction: column;
    }
    
    .tab-btn {
        width: 100%;
        min-width: auto;
        justify-content: flex-start;
        padding: 12px 16px;
    }
}

/* ================= TAB CONTENT ================= */
.tab-content {
    display: none;
    animation: fadeIn 0.5s ease;
}

.tab-content.active {
    display: block;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(15px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ================= CARDS ================= */
.card {
    background: var(--white);
    padding: 30px;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-medium);
    margin-bottom: 25px;
    border: none;
    border-left: 4px solid var(--secondary-color);
    position: relative;
    overflow: hidden;
}

.card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 4px;
    background: linear-gradient(90deg, var(--secondary-color) 0%, transparent 100%);
}

.card h3 {
    color: var(--dark-text);
    font-weight: 700;
    margin-bottom: 25px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding-bottom: 15px;
    border-bottom: 2px solid var(--light-bg);
}

.card h3 i {
    color: var(--primary-color);
}

@media (max-width: 768px) {
    .card {
        padding: 20px 15px;
    }
}

/* ================= FORMS ================= */
.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    font-weight: 600;
    color: var(--medium-text);
    margin-bottom: 8px;
    display: block;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.form-control, .form-select {
    width: 100%;
    padding: 12px 15px;
    border: 2px solid var(--border-color);
    border-radius: var(--border-radius-sm);
    font-size: 14px;
    transition: var(--transition);
    background: #F9F9F9;
    font-family: inherit;
}

.form-control:focus, .form-select:focus {
    outline: none;
    border-color: var(--secondary-color);
    background: var(--white);
    box-shadow: 0 0 0 3px rgba(0, 114, 206, 0.1);
}

.form-control::placeholder {
    color: var(--light-text);
}

textarea.form-control {
    min-height: 120px;
    resize: vertical;
}

small {
    font-size: 12px;
    color: var(--medium-text);
    display: block;
    margin-top: 5px;
}

/* ================= BUTTONS ================= */
.btn {
    border: none;
    padding: 12px 25px;
    border-radius: var(--border-radius-sm);
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    transition: var(--transition);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-family: inherit;
    text-decoration: none;
    position: relative;
    overflow: hidden;
}

.btn::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 5px;
    height: 5px;
    background: rgba(255, 255, 255, 0.5);
    opacity: 0;
    border-radius: 100%;
    transform: scale(1, 1) translate(-50%);
    transform-origin: 50% 50%;
}

.btn:focus:not(:active)::after {
    animation: ripple 1s ease-out;
}

@keyframes ripple {
    0% {
        transform: scale(0, 0);
        opacity: 0.5;
    }
    100% {
        transform: scale(20, 20);
        opacity: 0;
    }
}

.btn-primary {
    background: var(--primary-gradient);
    color: var(--white);
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0, 132, 61, 0.25);
}

.btn-success {
    background: linear-gradient(135deg, #00A651 0%, #00843D 100%);
    color: var(--white);
}

.btn-success:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0, 166, 81, 0.25);
}

.btn-danger {
    background: linear-gradient(135deg, #C62828 0%, #B71C1C 100%);
    color: var(--white);
}

.btn-danger:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(198, 40, 40, 0.25);
}

.btn-warning {
    background: linear-gradient(135deg, #FFB400 0%, #FF8F00 100%);
    color: var(--white);
}

.btn-warning:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(255, 180, 0, 0.25);
}

.btn-info {
    background: linear-gradient(135deg, #0072CE 0%, #0056A4 100%);
    color: var(--white);
}

.btn-info:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0, 114, 206, 0.25);
}

.btn-sm {
    padding: 8px 16px;
    font-size: 12px;
    border-radius: 6px;
}

/* ================= TABLES ================= */
.table-container {
    overflow: auto;
    border-radius: var(--border-radius-sm);
    box-shadow: var(--shadow-light);
    margin-bottom: 25px;
    border: 1px solid #F0F0F0;
    -webkit-overflow-scrolling: touch;
}

table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    min-width: 800px;
}

thead {
    background: var(--primary-gradient);
}

thead th {
    color: var(--white);
    padding: 18px;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 13px;
    letter-spacing: 1px;
    border: none;
    position: sticky;
    top: 0;
    z-index: 10;
}

thead th:first-child {
    border-top-left-radius: var(--border-radius-sm);
}

thead th:last-child {
    border-top-right-radius: var(--border-radius-sm);
}

tbody tr {
    transition: var(--transition);
    border-bottom: 1px solid #F5F5F5;
}

tbody tr:last-child {
    border-bottom: none;
}

tbody tr:hover {
    background: rgba(0, 132, 61, 0.04);
}

tbody td {
    padding: 16px 18px;
    font-size: 14px;
    vertical-align: middle;
    color: var(--dark-text);
}

tbody td small {
    font-size: 12px;
    color: var(--medium-text);
    margin-top: 4px;
    display: block;
}

/* ================= STATUS BADGES ================= */
.status-badge {
    padding: 7px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: inline-block;
    min-width: 80px;
    text-align: center;
}

.status-pending {
    background: rgba(255, 180, 0, 0.12);
    color: #FF8F00;
    border: 1px solid rgba(255, 180, 0, 0.3);
}

.status-approved {
    background: rgba(0, 166, 81, 0.12);
    color: #00843D;
    border: 1px solid rgba(0, 166, 81, 0.3);
}

.status-rejected {
    background: rgba(198, 40, 40, 0.12);
    color: #C62828;
    border: 1px solid rgba(198, 40, 40, 0.3);
}

/* ================= SUBJECT CARDS ================= */
.subject-card {
    background: var(--white);
    padding: 20px;
    border-radius: var(--border-radius-sm);
    margin-bottom: 15px;
    border-left: 4px solid var(--secondary-color);
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.04);
    transition: var(--transition);
    border-top: 1px solid #F5F5F5;
    position: relative;
    overflow: hidden;
}

.subject-card::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 4px;
    background: var(--secondary-color);
}

.subject-card:hover {
    transform: translateX(5px);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
}

.subject-card h5 {
    margin: 0 0 12px 0;
    color: var(--dark-text);
    font-weight: 600;
    font-size: 16px;
}

.subject-card p {
    margin: 8px 0;
    color: var(--medium-text);
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.subject-card p i {
    width: 16px;
    text-align: center;
    color: var(--secondary-color);
}

/* ================= ALERTS ================= */
.alert {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
    padding: 20px 25px;
    border-radius: var(--border-radius-sm);
    font-weight: 600;
    box-shadow: var(--shadow-heavy);
    animation: slideInRight 0.5s ease;
    max-width: 400px;
    display: flex;
    align-items: center;
    gap: 15px;
    border-left: 5px solid;
    background: var(--white);
}

.alert-success {
    color: var(--primary-color);
    border-left-color: var(--success-color);
    border: 1px solid rgba(0, 166, 81, 0.2);
}

.alert-error {
    color: var(--danger-color);
    border-left-color: var(--danger-color);
    border: 1px solid rgba(198, 40, 40, 0.2);
}

.alert i {
    font-size: 24px;
}

@keyframes slideInRight {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

/* ================= MODAL ================= */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.4);
    z-index: 9998;
    align-items: center;
    justify-content: center;
    animation: fadeIn 0.3s ease;
    padding: 20px;
}

.modal-content {
    background: var(--white);
    border-radius: var(--border-radius);
    padding: 35px;
    max-width: 500px;
    width: 90%;
    max-height: 80vh;
    overflow-y: auto;
    position: relative;
    box-shadow: var(--shadow-heavy);
    animation: slideUp 0.4s ease;
    border-top: 5px solid var(--primary-color);
}

@keyframes slideUp {
    from { transform: translateY(40px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

.modal-close {
    position: absolute;
    top: 15px;
    right: 15px;
    background: none;
    border: none;
    font-size: 28px;
    cursor: pointer;
    color: var(--medium-text);
    transition: var(--transition);
    padding: 5px;
    line-height: 1;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
}

.modal-close:hover {
    color: var(--danger-color);
    background: rgba(198, 40, 40, 0.1);
}

/* ================= ACTIONS ================= */
.actions {
    display: flex;
    gap: 15px;
    justify-content: flex-end;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 2px solid var(--light-bg);
    flex-wrap: wrap;
}

/* ================= BADGES ================= */
.badge {
    padding: 6px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    display: inline-block;
}

/* ================= UTILITY CLASSES ================= */
.text-center {
    text-align: center;
}

.text-muted {
    color: var(--medium-text);
}

.text-success {
    color: var(--success-color);
}

.text-danger {
    color: var(--danger-color);
}

.text-warning {
    color: var(--warning-color);
}

.text-info {
    color: var(--info-color);
}

.mb-0 {
    margin-bottom: 0 !important;
}

.mt-20 {
    margin-top: 20px !important;
}

.mb-20 {
    margin-bottom: 20px !important;
}

.p-0 {
    padding: 0 !important;
}

.w-100 {
    width: 100% !important;
}

.d-flex {
    display: flex !important;
}

.align-items-center {
    align-items: center !important;
}

.justify-content-center {
    justify-content: center !important;
}

.flex-wrap {
    flex-wrap: wrap !important;
}

.gap-10 {
    gap: 10px !important;
}

/* ================= LOADING STATES ================= */
.loading {
    position: relative;
    pointer-events: none;
    opacity: 0.7;
}

.loading::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 20px;
    height: 20px;
    margin: -10px 0 0 -10px;
    border: 2px solid rgba(0, 0, 0, 0.1);
    border-top-color: var(--primary-color);
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* ================= EMPTY STATES ================= */
.empty-state {
    text-align: center;
    padding: 60px 20px;
}

.empty-state i {
    font-size: 80px;
    color: #E0E0E0;
    margin-bottom: 20px;
    display: block;
}

.empty-state h4 {
    color: var(--dark-text);
    margin-bottom: 15px;
    font-weight: 600;
}

.empty-state p {
    color: var(--medium-text);
    max-width: 400px;
    margin: 0 auto;
    line-height: 1.6;
}

/* ================= RESPONSIVE UTILITIES ================= */
@media (max-width: 576px) {
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .actions {
        flex-direction: column;
    }
    
    .actions .btn {
        width: 100%;
        justify-content: center;
    }
    
    .modal-content {
        padding: 25px 20px;
        width: 95%;
    }
}    
    </style>
</head>
<body>
    <?php 
    if (file_exists(__DIR__ . '/../includes/header.php')) {
        include(__DIR__ . '/../includes/header.php');
    }
    ?>
    
    <div class="main-content">
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $type === 'success' ? 'success' : 'error'; ?>">
                <i class="fas <?php echo $type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> fa-2x"></i>
                <div>
                    <strong><?php echo htmlspecialchars($message); ?></strong>
                    <div style="font-size: 12px; opacity: 0.9; margin-top: 5px;">
                        <?php echo date('H:i:s'); ?>
                    </div>
                </div>
            </div>
            <?php include('../includes/footer.php'); ?>
            <script>
                setTimeout(() => {
                    const alert = document.querySelector('.alert');
                    if (alert) alert.remove();
                }, 5000);
            </script>
        <?php endif; ?>

        <!-- Department Admin Header -->
        <?php if ($current_user_role === 'department_admin' && isset($department_info)): ?>
            <div class="department-header">
                <h2><i class="fas fa-building"></i> <?php echo htmlspecialchars($department_info['department_name'] ?? 'Department Administration'); ?></h2>
                <?php if (isset($department_info['faculty_name'])): ?>
                    <p><i class="fas fa-university"></i> Faculty: <?php echo htmlspecialchars($department_info['faculty_name']); ?></p>
                <?php endif; ?>
                <?php if (isset($department_info['campus_name'])): ?>
                    <p><i class="fas fa-map-marker-alt"></i> Campus: <?php echo htmlspecialchars($department_info['campus_name']); ?></p>
                <?php endif; ?>
                <?php if (isset($department_info['department_code'])): ?>
                    <p><i class="fas fa-code"></i> Department Code: <?php echo htmlspecialchars($department_info['department_code']); ?></p>
                <?php endif; ?>
            </div>
            
            <?php if (isset($department_stats)): ?>
                <div class="dept-stats">
                    <div class="dept-stat-card">
                        <span class="number"><?php echo $department_stats['total_teachers'] ?? 0; ?></span>
                        <span class="label">Teachers</span>
                    </div>
                    <div class="dept-stat-card">
                        <span class="number"><?php echo $department_stats['total_subjects'] ?? 0; ?></span>
                        <span class="label">Subjects</span>
                    </div>
                    <div class="dept-stat-card">
                        <span class="number"><?php echo $department_stats['total_classes'] ?? 0; ?></span>
                        <span class="label">Classes</span>
                    </div>
                    <div class="dept-stat-card">
                        <span class="number"><?php echo $department_stats['total_students'] ?? 0; ?></span>
                        <span class="label">Students</span>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Campus Admin Header -->
        <?php if ($current_user_role === 'campus_admin' && isset($campus_info)): ?>
            <div class="campus-header">
                <h2><i class="fas fa-university"></i> <?php echo htmlspecialchars($campus_info['campus_name'] ?? 'Campus Administration'); ?></h2>
                <?php if (isset($campus_info['location'])): ?>
                    <p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($campus_info['location']); ?></p>
                <?php endif; ?>
                <?php if (isset($campus_info['email'])): ?>
                    <p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($campus_info['email']); ?></p>
                <?php endif; ?>
                <?php if (isset($campus_info['phone'])): ?>
                    <p><i class="fas fa-phone"></i> <?php echo htmlspecialchars($campus_info['phone']); ?></p>
                <?php endif; ?>
            </div>
            
            <?php if (isset($campus_stats)): ?>
                <div class="campus-stats">
                    <div class="campus-stat-card">
                        <span class="number"><?php echo $campus_stats['total_students'] ?? 0; ?></span>
                        <span class="label">Students</span>
                    </div>
                    <div class="campus-stat-card">
                        <span class="number"><?php echo $campus_stats['total_teachers'] ?? 0; ?></span>
                        <span class="label">Teachers</span>
                    </div>
                    <div class="campus-stat-card">
                        <span class="number"><?php echo $campus_stats['total_subjects'] ?? 0; ?></span>
                        <span class="label">Subjects</span>
                    </div>
                    <div class="campus-stat-card">
                        <span class="number"><?php echo $campus_stats['total_classes'] ?? 0; ?></span>
                        <span class="label">Classes</span>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="page-header">
            <h1>
                <i class="fas fa-calendar-check"></i>
                Attendance Correction System
                <?php if ($term_info): ?>
                    <span style="font-size: 16px; opacity: 0.9; margin-left: auto;">
                        <?php echo htmlspecialchars($term_info['term_name']); ?> Term - 
                        <?php echo htmlspecialchars($term_info['year_name']); ?>
                    </span>
                <?php endif; ?>
            </h1>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-cards">
            <div class="stat-card total">
                <i class="fas fa-file-alt"></i>
                <span class="number"><?php echo $stats['total']; ?></span>
                <span class="label">Total Requests</span>
            </div>
            <div class="stat-card pending">
                <i class="fas fa-clock"></i>
                <span class="number"><?php echo $stats['pending']; ?></span>
                <span class="label">Pending</span>
            </div>
            <div class="stat-card approved">
                <i class="fas fa-check-circle"></i>
                <span class="number"><?php echo $stats['approved']; ?></span>
                <span class="label">Approved</span>
            </div>
            <div class="stat-card rejected">
                <i class="fas fa-times-circle"></i>
                <span class="number"><?php echo $stats['rejected']; ?></span>
                <span class="label">Rejected</span>
            </div>
            <?php if ($current_user_role === 'department_admin'): ?>
                <div class="stat-card dept-admin">
                    <i class="fas fa-building"></i>
                    <span class="number"><?php echo count($department_pending_leaves ?? []); ?></span>
                    <span class="label">Dept Pending</span>
                </div>
            <?php endif; ?>
            <?php if ($current_user_role === 'campus_admin'): ?>
                <div class="stat-card campus-admin">
                    <i class="fas fa-university"></i>
                    <span class="number"><?php echo count($campus_pending_leaves ?? []); ?></span>
                    <span class="label">Campus Pending</span>
                </div>
            <?php endif; ?>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <?php if ($current_user_role === 'student'): ?>
                <button class="tab-btn active" data-tab="tab1">
                    <i class="fas fa-plus-circle"></i>
                    Request Leave
                </button>
            <?php endif; ?>
            
            <?php if (in_array($current_user_role, ['teacher', 'department_admin', 'campus_admin'])): ?>
                <button class="tab-btn <?php echo ($current_user_role !== 'student' && !isset($_GET['tab'])) ? 'active' : ''; ?>" data-tab="tab2">
                    <i class="fas fa-tasks"></i>
                    Pending Approvals
                    <?php 
                    $pending_count = 0;
                    if ($current_user_role === 'teacher') {
                        $pending_count = count($pending_leaves ?? []);
                    } elseif ($current_user_role === 'department_admin') {
                        $pending_count = count($department_pending_leaves ?? []);
                    } elseif ($current_user_role === 'campus_admin') {
                        $pending_count = count($campus_pending_leaves ?? []);
                    }
                    ?>
                    <?php if ($pending_count > 0): ?>
                        <span class="notification-badge"><?php echo $pending_count; ?></span>
                    <?php endif; ?>
                </button>
            <?php endif; ?>
            
            <button class="tab-btn" data-tab="tab3">
                <i class="fas fa-history"></i>
                Leave History
            </button>
            
            <?php if ($current_user_role === 'student'): ?>
                <button class="tab-btn" data-tab="tab4">
                    <i class="fas fa-book"></i>
                    My Subjects
                </button>
            <?php endif; ?>
            
            <?php if ($current_user_role === 'department_admin'): ?>
                <button class="tab-btn" data-tab="tab5">
                    <i class="fas fa-chart-bar"></i>
                    Department Overview
                </button>
            <?php endif; ?>
            
            <?php if ($current_user_role === 'campus_admin'): ?>
                <button class="tab-btn" data-tab="tab6">
                    <i class="fas fa-chart-bar"></i>
                    Campus Overview
                </button>
            <?php endif; ?>
        </div>

        <!-- Tab 1: Request Leave (Student Only) -->
        <?php if ($current_user_role === 'student'): ?>
        <div id="tab1" class="tab-content active">
            <div class="card">
                <h3><i class="fas fa-paper-plane"></i> Request Attendance Leave</h3>
                
                <?php if (!empty($student_subjects)): ?>
                <form id="leaveRequestForm" method="POST" class="needs-validation">
                    <input type="hidden" name="action" value="request_leave">
                    <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                    <input type="hidden" name="teacher_id" id="teacher_id" value="">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-book"></i> Select Subject *
                            </label>
                            <select name="subject_id" class="form-select" required>
                                <option value="">-- Choose Subject --</option>
                                <?php foreach ($student_subjects as $subject): ?>
                                <option value="<?php echo $subject['subject_id']; ?>" 
                                        data-teacher="<?php echo $subject['teacher_id']; ?>">
                                    <?php echo htmlspecialchars($subject['subject_name']); ?> 
                                    (Teacher: <?php echo htmlspecialchars($subject['teacher_name']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small>Select the subject you need leave for</small>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-calendar-alt"></i> Start Date *
                            </label>
                            <input type="date" name="start_date" class="form-control" 
                                   min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" 
                                   max="<?php echo date('Y-m-d', strtotime('+30 days')); ?>" 
                                   required>
                            <small>Leave cannot start today</small>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-sort-numeric-up-alt"></i> Number of Days *
                            </label>
                            <input type="number" name="days_count" class="form-control" 
                                   min="1" max="30" value="1" required>
                            <small>Maximum 30 days</small>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-tag"></i> Reason *
                            </label>
                            <select name="reason" class="form-select" required>
                                <option value="">-- Select Reason --</option>
                                <option value="sick">Sick/Medical</option>
                                <option value="family">Family Emergency</option>
                                <option value="travel">Travel</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-comment-dots"></i> Additional Details
                        </label>
                        <textarea name="reason_details" class="form-control" 
                                  placeholder="Please provide any additional information..."></textarea>
                        <small>Optional: Add more context for your leave request</small>
                    </div>

                    <div class="actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> Submit Request
                        </button>
                        <button type="reset" class="btn btn-warning">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                    </div>
                </form>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-exclamation-circle"></i>
                    <h4>No Subjects Available</h4>
                    <p>You are not enrolled in any subjects for the current term. Please contact your academic advisor.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Tab 2: Pending Approvals (Teacher, Department Admin, Campus Admin) -->
        <?php if (in_array($current_user_role, ['teacher', 'department_admin', 'campus_admin'])): ?>
        <div id="tab2" class="tab-content <?php echo ($current_user_role !== 'student' && !isset($_GET['tab'])) ? 'active' : ''; ?>">
            <?php 
            $display_leaves = [];
            $pending_title = "";
            if ($current_user_role === 'teacher') {
                $display_leaves = $pending_leaves;
                $pending_title = "Teacher Pending Requests";
            } elseif ($current_user_role === 'department_admin') {
                $display_leaves = $department_pending_leaves;
                $pending_title = "Department Pending Requests";
            } elseif ($current_user_role === 'campus_admin') {
                $display_leaves = $campus_pending_leaves;
                $pending_title = "Campus Pending Requests";
            }
            ?>
            
            <?php if (!empty($display_leaves)): ?>
                <div class="card">
                    <h3>
                        <i class="fas fa-clock"></i> 
                        <?php echo $pending_title; ?>
                        <?php if ($current_user_role === 'department_admin' && isset($department_info)): ?>
                            <span style="font-size: 14px; color: #FF6B6B; margin-left: 10px;">
                                <i class="fas fa-building"></i> <?php echo htmlspecialchars($department_info['department_name'] ?? 'Department'); ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($current_user_role === 'campus_admin' && isset($campus_info)): ?>
                            <span style="font-size: 14px; color: #6A11CB; margin-left: 10px;">
                                <i class="fas fa-university"></i> <?php echo htmlspecialchars($campus_info['campus_name'] ?? 'Campus'); ?>
                            </span>
                        <?php endif; ?>
                        <span class="badge" style="background: #ff9800; color: white; margin-left: 10px;">
                            <?php echo count($display_leaves); ?> requests
                        </span>
                    </h3>
                    
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Subject</th>
                                    <?php if (in_array($current_user_role, ['department_admin', 'campus_admin'])): ?>
                                        <th>Teacher</th>
                                    <?php endif; ?>
                                    <?php if ($current_user_role === 'campus_admin'): ?>
                                        <th>Department</th>
                                    <?php endif; ?>
                                    <th>Leave Period</th>
                                    <th>Reason</th>
                                    <th>Days Left</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($display_leaves as $leave): ?>
                                    <?php
                                    $days_left = $leave['days_until_start'];
                                    $urgency_class = '';
                                    if ($days_left <= 0) {
                                        $urgency_class = 'style="background: rgba(255, 87, 34, 0.1);"';
                                    } elseif ($days_left <= 2) {
                                        $urgency_class = 'style="background: rgba(255, 152, 0, 0.1);"';
                                    }
                                    ?>
                                    <tr <?php echo $urgency_class; ?>>
                                        <td>
                                            <strong><?php echo htmlspecialchars($leave['student_name']); ?></strong><br>
                                            <small style="color: #666;">
                                                <?php echo htmlspecialchars($leave['reg_no']); ?> | 
                                                <?php echo htmlspecialchars($leave['class_name'] ?? 'N/A'); ?>
                                            </small><br>
                                            <?php if ($leave['student_email']): ?>
                                                <small>
                                                    <i class="fas fa-envelope"></i> 
                                                    <?php echo htmlspecialchars($leave['student_email']); ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($leave['subject_name']); ?></strong><br>
                                            <small style="color: #666;">
                                                Requested by: <?php echo htmlspecialchars($leave['requested_by_name']); ?>
                                            </small>
                                        </td>
                                        
                                        <?php if (in_array($current_user_role, ['department_admin', 'campus_admin'])): ?>
                                            <td>
                                                <?php if (!empty($leave['teacher_name'])): ?>
                                                    <?php echo htmlspecialchars($leave['teacher_name']); ?>
                                                <?php else: ?>
                                                    <span style="color: #999;">Not Assigned</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endif; ?>
                                        
                                        <?php if ($current_user_role === 'campus_admin'): ?>
                                            <td>
                                                <?php if (!empty($leave['department_name'])): ?>
                                                    <?php echo htmlspecialchars($leave['department_name']); ?>
                                                <?php else: ?>
                                                    <span style="color: #999;">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endif; ?>
                                        
                                        <td>
                                            <strong><?php echo date('M d, Y', strtotime($leave['start_date'])); ?></strong><br>
                                            <small style="color: #666;">
                                                <?php echo $leave['days_count']; ?> day(s) | 
                                                Ends: <?php echo date('M d, Y', strtotime($leave['end_date'])); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="status-badge status-pending">
                                                <?php echo ucfirst($leave['reason']); ?>
                                            </span><br>
                                            <small style="color: #666; margin-top: 5px; display: block;">
                                                <?php echo htmlspecialchars($leave['reason_details'] ?: 'No details provided'); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php if ($days_left > 0): ?>
                                                <span style="color: #4caf50; font-weight: bold;">
                                                    <i class="fas fa-calendar-check"></i>
                                                    <?php echo $days_left; ?> days
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #f44336; font-weight: bold;">
                                                    <i class="fas fa-exclamation-triangle"></i>
                                                    Starts today!
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                                <button class="btn btn-success btn-sm" 
                                                        onclick="openDecisionModal(<?php echo $leave['leave_id']; ?>, 'approve')">
                                                    <i class="fas fa-check"></i> Approve
                                                </button>
                                                <button class="btn btn-danger btn-sm" 
                                                        onclick="openDecisionModal(<?php echo $leave['leave_id']; ?>, 'reject')">
                                                    <i class="fas fa-times"></i> Reject
                                                </button>
                                                <?php if (in_array($current_user_role, ['department_admin', 'campus_admin'])): ?>
                                                    <button class="btn btn-info btn-sm" 
                                                            onclick="viewStudentInfo(<?php echo $leave['student_id']; ?>)">
                                                        <i class="fas fa-user"></i> Info
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php else: ?>
                <div class="card" style="text-align: center; padding: 60px 20px;">
                    <i class="fas fa-check-circle" style="font-size: 80px; color: #4caf50; margin-bottom: 20px;"></i>
                    <h3 style="color: #333; margin-bottom: 15px;">No Pending Requests</h3>
                    <p style="color: #666; max-width: 500px; margin: 0 auto; line-height: 1.6;">
                        You have no pending leave requests to review. All requests have been processed.
                    </p>
                </div>
            <?php endif; ?>
            
            <?php if ($current_user_role === 'teacher' && !empty($teacher_subjects)): ?>
                <div class="card mt-20">
                    <h3><i class="fas fa-chalkboard-teacher"></i> Your Subjects</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px;">
                        <?php foreach ($teacher_subjects as $subject): ?>
                            <div class="subject-card">
                                <h5><?php echo htmlspecialchars($subject['subject_name']); ?></h5>
                                <p><i class="fas fa-users"></i> Class: <?php echo htmlspecialchars($subject['class_name']); ?></p>
                                <p><i class="fas fa-user-graduate"></i> Students: <?php echo $subject['total_students']; ?></p>
                                <p><i class="fas fa-clock"></i> 
                                    <?php echo ucfirst($subject['day_of_week']); ?>: 
                                    <?php echo $subject['start_time']; ?> - <?php echo $subject['end_time']; ?>
                                </p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Tab 3: Leave History -->
        <div id="tab3" class="tab-content">
            <div class="card">
                <h3><i class="fas fa-history"></i> Leave Request History</h3>
                
                <?php if (!empty($leave_history)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date Requested</th>
                                    <?php if ($current_user_role !== 'student'): ?>
                                        <th>Student</th>
                                    <?php endif; ?>
                                    <th>Subject</th>
                                    <?php if ($current_user_role === 'student'): ?>
                                        <th>Teacher</th>
                                    <?php endif; ?>
                                    <?php if (in_array($current_user_role, ['department_admin', 'campus_admin'])): ?>
                                        <th>Teacher</th>
                                    <?php endif; ?>
                                    <?php if ($current_user_role === 'campus_admin'): ?>
                                        <th>Department</th>
                                    <?php endif; ?>
                                    <th>Leave Period</th>
                                    <th>Reason</th>
                                    <th>Status</th>
                                    <?php if ($current_user_role === 'student'): ?>
                                        <th>Action</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($leave_history as $record): ?>
                                    <?php 
                                    $status_class = '';
                                    $display_status = $record['display_status'] ?? $record['status'];
                                    if ($display_status === 'pending') $status_class = 'status-pending';
                                    elseif ($display_status === 'approved' || $display_status === 'active') $status_class = 'status-approved';
                                    elseif ($display_status === 'rejected') $status_class = 'status-rejected';
                                    elseif ($display_status === 'expired') $status_class = 'status-pending';
                                    ?>
                                    <tr>
                                        <td>
                                            <?php echo date('M d, Y', strtotime($record['created_at'])); ?><br>
                                            <small style="color: #666;"><?php echo date('H:i', strtotime($record['created_at'])); ?></small>
                                        </td>
                                        
                                        <?php if ($current_user_role !== 'student'): ?>
                                            <td>
                                                <strong><?php echo htmlspecialchars($record['student_name'] ?? ''); ?></strong><br>
                                                <small style="color: #666;"><?php echo htmlspecialchars($record['reg_no'] ?? ''); ?></small>
                                            </td>
                                        <?php endif; ?>
                                        
                                        <td>
                                            <strong><?php echo htmlspecialchars($record['subject_name'] ?? 'N/A'); ?></strong>
                                            <?php if (isset($record['class_name'])): ?>
                                                <br><small><?php echo htmlspecialchars($record['class_name']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <?php if ($current_user_role === 'student'): ?>
                                            <td><?php echo htmlspecialchars($record['teacher_name'] ?? 'N/A'); ?></td>
                                        <?php endif; ?>
                                        
                                        <?php if (in_array($current_user_role, ['department_admin', 'campus_admin'])): ?>
                                            <td><?php echo htmlspecialchars($record['teacher_name'] ?? 'N/A'); ?></td>
                                        <?php endif; ?>
                                        
                                        <?php if ($current_user_role === 'campus_admin'): ?>
                                            <td><?php echo htmlspecialchars($record['department_name'] ?? 'N/A'); ?></td>
                                        <?php endif; ?>
                                        
                                        <td>
                                            <strong><?php echo date('M d', strtotime($record['start_date'])); ?></strong> - 
                                            <strong><?php echo date('M d, Y', strtotime($record['end_date'])); ?></strong><br>
                                            <small style="color: #666;"><?php echo $record['days_count']; ?> day(s)</small>
                                        </td>
                                        
                                        <td>
                                            <span class="status-badge <?php echo $status_class; ?>">
                                                <?php echo ucfirst($record['reason']); ?>
                                            </span><br>
                                            <?php if (!empty($record['reason_details'])): ?>
                                                <small style="color: #666;"><?php echo htmlspecialchars(substr($record['reason_details'], 0, 50)) . '...'; ?></small>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <td>
                                            <?php if ($display_status === 'approved'): ?>
                                                <span class="status-badge status-approved">Approved</span>
                                            <?php elseif ($display_status === 'active'): ?>
                                                <span class="status-badge status-approved" style="background: rgba(76, 175, 80, 0.2); color: #2e7d32;">Active</span>
                                            <?php elseif ($display_status === 'expired'): ?>
                                                <span class="status-badge status-pending" style="background: rgba(158, 158, 158, 0.2); color: #616161;">Expired</span>
                                            <?php elseif ($display_status === 'rejected'): ?>
                                                <span class="status-badge status-rejected">Rejected</span>
                                            <?php else: ?>
                                                <span class="status-badge status-pending">Pending</span>
                                            <?php endif; ?>
                                            
                                            <?php if ($record['approved_by']): ?>
                                                <br><small>by: <?php echo htmlspecialchars($record['approved_by_name'] ?? 'System'); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <?php if ($current_user_role === 'student' && $record['status'] === 'pending'): ?>
                                            <td>
                                                <button class="btn btn-danger btn-sm" onclick="cancelLeave(<?php echo $record['leave_id']; ?>)">
                                                    <i class="fas fa-times"></i> Cancel
                                                </button>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <h4>No Leave History</h4>
                        <p>You haven't made any leave requests yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tab 4: My Subjects (Student Only) -->
        <?php if ($current_user_role === 'student'): ?>
        <div id="tab4" class="tab-content">
            <div class="card">
                <h3><i class="fas fa-book-open"></i> My Enrolled Subjects</h3>
                
                <?php if (!empty($student_subjects)): ?>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 20px;">
                        <?php foreach ($student_subjects as $subject): ?>
                            <div class="subject-card">
                                <h5><?php echo htmlspecialchars($subject['subject_name']); ?></h5>
                                <p><i class="fas fa-chalkboard-teacher"></i> Teacher: <?php echo htmlspecialchars($subject['teacher_name']); ?></p>
                                <p><i class="fas fa-users"></i> Class: <?php echo htmlspecialchars($subject['class_name']); ?></p>
                                <p><i class="fas fa-clock"></i> Schedule: <?php echo ucfirst($subject['day_of_week']); ?> (<?php echo $subject['start_time']; ?> - <?php echo $subject['end_time']; ?>)</p>
                                <?php if ($subject['total_absences'] > 0): ?>
                                    <p style="color: #f44336;">
                                        <i class="fas fa-exclamation-triangle"></i> 
                                        Total Absences: <?php echo $subject['total_absences']; ?>
                                    </p>
                                <?php endif; ?>
                                <button class="btn btn-primary btn-sm" 
                                        onclick="quickRequest(<?php echo $subject['subject_id']; ?>, '<?php echo htmlspecialchars($subject['teacher_name']); ?>')">
                                    <i class="fas fa-paper-plane"></i> Quick Leave Request
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-book"></i>
                        <h4>No Subjects Found</h4>
                        <p>You are not enrolled in any subjects for the current term.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Tab 5: Department Overview (Department Admin Only) -->
        <?php if ($current_user_role === 'department_admin'): ?>
        <div id="tab5" class="tab-content">
            <div class="card">
                <h3><i class="fas fa-chart-pie"></i> Department Leave Statistics</h3>
                
                <?php
                // Get department leave statistics
                $dept_statistics_stmt = $pdo->prepare("
                    SELECT 
                        DATE_FORMAT(ac.created_at, '%Y-%m') as month,
                        COUNT(*) as total_requests,
                        SUM(CASE WHEN ac.status = 'approved' THEN 1 ELSE 0 END) as approved,
                        SUM(CASE WHEN ac.status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                        SUM(CASE WHEN ac.status = 'pending' THEN 1 ELSE 0 END) as pending
                    FROM attendance_correction ac
                    JOIN subject sub ON ac.subject_id = sub.subject_id
                    WHERE sub.department_id = ?
                    AND ac.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                    GROUP BY DATE_FORMAT(ac.created_at, '%Y-%m')
                    ORDER BY month DESC
                ");
                $dept_statistics_stmt->execute([$current_department_id]);
                $monthly_stats = $dept_statistics_stmt->fetchAll(PDO::FETCH_ASSOC);
                ?>
                
                <?php if (!empty($monthly_stats)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Month</th>
                                    <th>Total Requests</th>
                                    <th>Approved</th>
                                    <th>Rejected</th>
                                    <th>Pending</th>
                                    <th>Approval Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($monthly_stats as $stat): ?>
                                    <?php
                                    $approval_rate = $stat['total_requests'] > 0 
                                        ? round(($stat['approved'] / $stat['total_requests']) * 100, 1) 
                                        : 0;
                                    ?>
                                    <tr>
                                        <td><?php echo date('F Y', strtotime($stat['month'] . '-01')); ?></td>
                                        <td><?php echo $stat['total_requests']; ?></td>
                                        <td><span style="color: #4caf50;"><?php echo $stat['approved']; ?></span></td>
                                        <td><span style="color: #f44336;"><?php echo $stat['rejected']; ?></span></td>
                                        <td><span style="color: #ff9800;"><?php echo $stat['pending']; ?></span></td>
                                        <td>
                                            <span style="font-weight: bold; color: <?php echo $approval_rate >= 70 ? '#4caf50' : ($approval_rate >= 50 ? '#ff9800' : '#f44336'); ?>">
                                                <?php echo $approval_rate; ?>%
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px 20px;">
                        <i class="fas fa-chart-line" style="font-size: 60px; color: #ddd; margin-bottom: 20px;"></i>
                        <p style="color: #666;">No statistics available yet.</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="card">
                <h3><i class="fas fa-exclamation-triangle"></i> Recent Absences by Department (Non-Excused)</h3>
                
                <?php
                // Get recent non-excused absences for department
                $recent_absences_stmt = $pdo->prepare("
                    SELECT 
                        a.student_id,
                        s.full_name,
                        s.reg_no,
                        c.class_name,
                        sub.subject_name,
                        t.teacher_name,
                        COUNT(*) as absence_count,
                        MAX(a.attendance_date) as last_absence
                    FROM attendance a
                    JOIN students s ON a.student_id = s.student_id
                    LEFT JOIN classes c ON a.class_id = c.class_id
                    LEFT JOIN subject sub ON a.subject_id = sub.subject_id
                    LEFT JOIN teachers t ON a.teacher_id = t.teacher_id
                    WHERE sub.department_id = ?
                    AND a.status = 'absent'
                    AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    AND a.academic_term_id = ?
                    GROUP BY a.student_id, a.subject_id
                    HAVING COUNT(*) >= 3
                    ORDER BY absence_count DESC, last_absence DESC
                    LIMIT 20
                ");
                $recent_absences_stmt->execute([$current_department_id, $academic_term_id]);
                $recent_absences = $recent_absences_stmt->fetchAll(PDO::FETCH_ASSOC);
                ?>
                
                <?php if (!empty($recent_absences)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Class</th>
                                    <th>Subject</th>
                                    <th>Teacher</th>
                                    <th>Absences</th>
                                    <th>Last Absence</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_absences as $absence): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($absence['full_name']); ?></strong><br>
                                            <small style="color: #666;"><?php echo htmlspecialchars($absence['reg_no']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($absence['class_name']); ?></td>
                                        <td><?php echo htmlspecialchars($absence['subject_name']); ?></td>
                                        <td><?php echo htmlspecialchars($absence['teacher_name']); ?></td>
                                        <td>
                                            <span style="color: #f44336; font-weight: bold;">
                                                <?php echo $absence['absence_count']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo date('M d, Y', strtotime($absence['last_absence'])); ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-warning btn-sm" 
                                                    onclick="remindTeacher(<?php echo $absence['student_id']; ?>, '<?php echo htmlspecialchars($absence['teacher_name']); ?>')">
                                                <i class="fas fa-bell"></i> Alert
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px 20px;">
                        <i class="fas fa-check-circle" style="font-size: 60px; color: #4caf50; margin-bottom: 20px;"></i>
                        <p style="color: #666;">No concerning absence patterns found in your department.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Tab 6: Campus Overview (Campus Admin Only) -->
        <?php if ($current_user_role === 'campus_admin'): ?>
        <div id="tab6" class="tab-content">
            <div class="card">
                <h3><i class="fas fa-chart-pie"></i> Campus Leave Statistics</h3>
                
                <?php
                // Get campus leave statistics
                $campus_statistics_stmt = $pdo->prepare("
                    SELECT 
                        DATE_FORMAT(ac.created_at, '%Y-%m') as month,
                        COUNT(*) as total_requests,
                        SUM(CASE WHEN ac.status = 'approved' THEN 1 ELSE 0 END) as approved,
                        SUM(CASE WHEN ac.status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                        SUM(CASE WHEN ac.status = 'pending' THEN 1 ELSE 0 END) as pending
                    FROM attendance_correction ac
                    JOIN students s ON ac.student_id = s.student_id
                    WHERE s.campus_id = ?
                    AND ac.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                    GROUP BY DATE_FORMAT(ac.created_at, '%Y-%m')
                    ORDER BY month DESC
                ");
                $campus_statistics_stmt->execute([$current_campus_id]);
                $monthly_stats = $campus_statistics_stmt->fetchAll(PDO::FETCH_ASSOC);
                ?>
                
                <?php if (!empty($monthly_stats)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Month</th>
                                    <th>Total Requests</th>
                                    <th>Approved</th>
                                    <th>Rejected</th>
                                    <th>Pending</th>
                                    <th>Approval Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($monthly_stats as $stat): ?>
                                    <?php
                                    $approval_rate = $stat['total_requests'] > 0 
                                        ? round(($stat['approved'] / $stat['total_requests']) * 100, 1) 
                                        : 0;
                                    ?>
                                    <tr>
                                        <td><?php echo date('F Y', strtotime($stat['month'] . '-01')); ?></td>
                                        <td><?php echo $stat['total_requests']; ?></td>
                                        <td><span style="color: #4caf50;"><?php echo $stat['approved']; ?></span></td>
                                        <td><span style="color: #f44336;"><?php echo $stat['rejected']; ?></span></td>
                                        <td><span style="color: #ff9800;"><?php echo $stat['pending']; ?></span></td>
                                        <td>
                                            <span style="font-weight: bold; color: <?php echo $approval_rate >= 70 ? '#4caf50' : ($approval_rate >= 50 ? '#ff9800' : '#f44336'); ?>">
                                                <?php echo $approval_rate; ?>%
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px 20px;">
                        <i class="fas fa-chart-line" style="font-size: 60px; color: #ddd; margin-bottom: 20px;"></i>
                        <p style="color: #666;">No statistics available yet.</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="card">
                <h3><i class="fas fa-exclamation-triangle"></i> Recent Absences (Non-Excused)</h3>
                
                <?php
                // Get recent non-excused absences
                $recent_absences_stmt = $pdo->prepare("
                    SELECT 
                        a.student_id,
                        s.full_name,
                        s.reg_no,
                        c.class_name,
                        sub.subject_name,
                        t.teacher_name,
                        d.department_name,
                        COUNT(*) as absence_count,
                        MAX(a.attendance_date) as last_absence
                    FROM attendance a
                    JOIN students s ON a.student_id = s.student_id
                    LEFT JOIN classes c ON a.class_id = c.class_id
                    LEFT JOIN subject sub ON a.subject_id = sub.subject_id
                    LEFT JOIN teachers t ON a.teacher_id = t.teacher_id
                    LEFT JOIN departments d ON sub.department_id = d.department_id
                    WHERE s.campus_id = ?
                    AND a.status = 'absent'
                    AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    AND a.academic_term_id = ?
                    GROUP BY a.student_id, a.subject_id
                    HAVING COUNT(*) >= 3
                    ORDER BY absence_count DESC, last_absence DESC
                    LIMIT 20
                ");
                $recent_absences_stmt->execute([$current_campus_id, $academic_term_id]);
                $recent_absences = $recent_absences_stmt->fetchAll(PDO::FETCH_ASSOC);
                ?>
                
                <?php if (!empty($recent_absences)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Class</th>
                                    <th>Department</th>
                                    <th>Subject</th>
                                    <th>Teacher</th>
                                    <th>Absences</th>
                                    <th>Last Absence</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_absences as $absence): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($absence['full_name']); ?></strong><br>
                                            <small style="color: #666;"><?php echo htmlspecialchars($absence['reg_no']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($absence['class_name']); ?></td>
                                        <td><?php echo htmlspecialchars($absence['department_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($absence['subject_name']); ?></td>
                                        <td><?php echo htmlspecialchars($absence['teacher_name']); ?></td>
                                        <td>
                                            <span style="color: #f44336; font-weight: bold;">
                                                <?php echo $absence['absence_count']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo date('M d, Y', strtotime($absence['last_absence'])); ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-warning btn-sm" 
                                                    onclick="remindTeacher(<?php echo $absence['student_id']; ?>, '<?php echo htmlspecialchars($absence['teacher_name']); ?>')">
                                                <i class="fas fa-bell"></i> Alert
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px 20px;">
                        <i class="fas fa-check-circle" style="font-size: 60px; color: #4caf50; margin-bottom: 20px;"></i>
                        <p style="color: #666;">No concerning absence patterns found.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Decision Modal -->
    <div id="decisionModal" class="modal-overlay">
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal()">&times;</button>
            <h3 id="modalTitle" style="color: #333; margin-bottom: 25px;"></h3>
            
            <form id="decisionForm" method="POST">
                <input type="hidden" id="modalLeaveId" name="leave_id">
                <input type="hidden" id="modalAction" name="action">
                
                <div style="margin-bottom: 25px;">
                    <label class="form-label">
                        <i class="fas fa-comment-dots"></i> Decision Notes *
                    </label>
                    <textarea name="decision_notes" id="decisionNotes" class="form-control" 
                              rows="5" 
                              placeholder="Please provide reason for your decision..."
                              required></textarea>
                    <small style="color: #666; font-size: 12px;">
                        These notes will be sent to the student and parent.
                    </small>
                </div>
                
                <div class="actions">
                    <button type="submit" class="btn" id="modalSubmitBtn"></button>
                    <button type="button" class="btn btn-danger" onclick="closeModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
    $(document).ready(function() {
        // Tab Switching
        $('.tab-btn').click(function() {
            $('.tab-btn').removeClass('active');
            $(this).addClass('active');
            
            $('.tab-content').removeClass('active');
            $('#' + $(this).data('tab')).addClass('active');
        });

        // Update teacher_id when subject is selected
        $('select[name="subject_id"]').change(function() {
            const selectedOption = $(this).find('option:selected');
            $('#teacher_id').val(selectedOption.data('teacher') || '');
        });

        // Auto-calculate end date
        $('input[name="start_date"], input[name="days_count"]').on('input', function() {
            const startDate = $('input[name="start_date"]').val();
            const daysCount = parseInt($('input[name="days_count"]').val()) || 1;
            
            if (startDate) {
                const start = new Date(startDate);
                const end = new Date(start);
                end.setDate(start.getDate() + daysCount - 1);
                
                $('#endDatePreview').remove();
                $('input[name="days_count"]').after(
                    '<small id="endDatePreview" style="color: #667eea; display: block; margin-top: 5px;">' +
                    'Leave ends on: ' + end.toISOString().split('T')[0] +
                    '</small>'
                );
            }
        });

        // Form validation
        $('#leaveRequestForm').submit(function(e) {
            const startDate = new Date($('input[name="start_date"]').val());
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            
            if (startDate < today) {
                alert('Cannot request leave for past dates!');
                e.preventDefault();
                return false;
            }
            
            const daysCount = parseInt($('input[name="days_count"]').val());
            if (daysCount < 1 || daysCount > 30) {
                alert('Leave days must be between 1 and 30!');
                e.preventDefault();
                return false;
            }
            
            return true;
        });
    });

    // Modal Functions
    function openDecisionModal(leaveId, action) {
        const title = action === 'approve' ? 'Approve Leave Request' : 'Reject Leave Request';
        const buttonText = action === 'approve' ? 
            '<i class="fas fa-check"></i> Approve Request' : 
            '<i class="fas fa-times"></i> Reject Request';
        const buttonClass = action === 'approve' ? 'btn-success' : 'btn-danger';
        
        $('#modalTitle').text(title);
        $('#modalLeaveId').val(leaveId);
        $('#modalAction').val(action + '_leave');
        $('#modalSubmitBtn').html(buttonText).attr('class', 'btn ' + buttonClass);
        $('#decisionModal').show();
    }

    function closeModal() {
        $('#decisionModal').hide();
        $('#decisionForm')[0].reset();
    }

    function cancelLeave(leaveId) {
        if (confirm('Are you sure you want to cancel this leave request?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const leaveIdInput = document.createElement('input');
            leaveIdInput.type = 'hidden';
            leaveIdInput.name = 'leave_id';
            leaveIdInput.value = leaveId;
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'cancel_leave';
            
            form.appendChild(leaveIdInput);
            form.appendChild(actionInput);
            document.body.appendChild(form);
            form.submit();
        }
    }

    function viewStudentInfo(studentId) {
        // This could open a modal with student details
        alert('Student ID: ' + studentId + '\nThis feature would show detailed student information.');
    }

    function remindTeacher(studentId, teacherName) {
        if (confirm(`Send reminder to ${teacherName} about student's absences?`)) {
            // You can implement AJAX call here to send reminder
            alert('Reminder sent to teacher!');
        }
    }

    function quickRequest(subjectId, teacherName) {
        // Switch to request tab and pre-fill subject
        $('.tab-btn[data-tab="tab1"]').click();
        $('select[name="subject_id"]').val(subjectId).trigger('change');
        $('html, body').animate({
            scrollTop: $('#leaveRequestForm').offset().top
        }, 500);
    }

    // Close modal when clicking outside
    window.onclick = function(event) {
        if (event.target === document.getElementById('decisionModal')) {
            closeModal();
        }
    }

    // Auto-close alerts after 5 seconds
    setTimeout(() => {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => alert.remove());
    }, 5000);
    </script>
    <?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>