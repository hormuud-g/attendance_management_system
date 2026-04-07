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

// ✅ Access Control
$allowed_roles = ['super_admin', 'faculty_admin', 'teacher', 'student', 'parent'];
if (!isset($_SESSION['user']) || !in_array(strtolower($_SESSION['user']['role']), $allowed_roles)) {
    session_destroy();
    header("Location: ../login.php?error=unauthorized");
    exit;
}

date_default_timezone_set('Africa/Nairobi');
$message = "";
$type = "";

// Current user info
$current_user_id = $_SESSION['user']['user_id'] ?? 0;
$current_user_role = strtolower($_SESSION['user']['role'] ?? '');
$current_user_name = $_SESSION['user']['username'] ?? $_SESSION['user']['full_name'] ?? 'User';

// Get faculty_id if user is faculty_admin
$faculty_id = 0;
$faculty_name = '';
if ($current_user_role === 'faculty_admin') {
    $faculty_id = $_SESSION['user']['linked_id'] ?? 0;
    if ($faculty_id > 0) {
        $stmt = $pdo->prepare("SELECT faculty_name FROM faculties WHERE faculty_id = ?");
        $stmt->execute([$faculty_id]);
        $faculty = $stmt->fetch(PDO::FETCH_ASSOC);
        $faculty_name = $faculty['faculty_name'] ?? '';
    }
}

// Get student ID if user is student
$student_id = 0;
$student_info = [];
if ($current_user_role === 'student') {
    $stmt = $pdo->prepare("SELECT student_id, full_name, reg_no, email FROM students WHERE user_id = ?");
    $stmt->execute([$current_user_id]);
    $student_info = $stmt->fetch(PDO::FETCH_ASSOC);
    $student_id = $student_info['student_id'] ?? 0;
}

// Get teacher ID if user is teacher
$teacher_id = 0;
$teacher_info = [];
if ($current_user_role === 'teacher') {
    $stmt = $pdo->prepare("SELECT teacher_id, teacher_name, email FROM teachers WHERE user_id = ?");
    $stmt->execute([$current_user_id]);
    $teacher_info = $stmt->fetch(PDO::FETCH_ASSOC);
    $teacher_id = $teacher_info['teacher_id'] ?? 0;
}

/* ================= CREATE ATTENDANCE_LOCK TABLE ================= */
function createAttendanceLockTable($pdo) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS attendance_lock (
                lock_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                teacher_id INT UNSIGNED NOT NULL,
                subject_id INT UNSIGNED NOT NULL,
                lock_date DATE NOT NULL,
                locked_by INT UNSIGNED NOT NULL,
                unlocked_by INT UNSIGNED DEFAULT NULL,
                locked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                unlocked_at DATETIME DEFAULT NULL,
                is_locked BOOLEAN DEFAULT TRUE,
                reason VARCHAR(255) DEFAULT NULL,
                notification_sent BOOLEAN DEFAULT FALSE,
                notification_date DATETIME DEFAULT NULL,
                
                INDEX idx_lock_status (is_locked, lock_date),
                INDEX idx_teacher_locks (teacher_id, is_locked),
                UNIQUE KEY uk_teacher_subject_date (teacher_id, subject_id, lock_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // Add locked column to attendance table if not exists
        $check_col = $pdo->query("SHOW COLUMNS FROM attendance LIKE 'locked'")->rowCount();
        if ($check_col == 0) {
            $pdo->exec("
                ALTER TABLE attendance 
                ADD COLUMN locked BOOLEAN DEFAULT FALSE,
                ADD COLUMN lock_reason VARCHAR(255) DEFAULT NULL,
                ADD INDEX idx_attendance_locked (locked)
            ");
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error creating attendance_lock table: " . $e->getMessage());
        return false;
    }
}

createAttendanceLockTable($pdo);

/* ================= CREATE ATTENDANCE_CORRECTION TABLE ================= */
function createAttendanceCorrectionTable($pdo) {
    try {
        $pdo->exec("ALTER TABLE teachers CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS attendance_correction (
                leave_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                student_id INT UNSIGNED NOT NULL,
                subject_id INT UNSIGNED DEFAULT NULL,
                teacher_id INT UNSIGNED DEFAULT NULL,
                academic_term_id INT UNSIGNED NOT NULL,
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
                
                INDEX idx_student_status (student_id, status),
                INDEX idx_teacher_status (teacher_id, status),
                INDEX idx_subject_term (subject_id, academic_term_id),
                INDEX idx_dates (start_date, end_date),
                INDEX idx_status_date (status, created_at),
                UNIQUE KEY uk_pending_request (student_id, subject_id, start_date, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        return true;
    } catch (Exception $e) {
        error_log("Error creating attendance_correction table: " . $e->getMessage());
        return false;
    }
}

createAttendanceCorrectionTable($pdo);

/* ================= EMAIL SENDING FUNCTION ================= */
function sendEmail($to, $subject, $message, $record_id = null, $pdo = null, $type = 'correction') {
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
        $mail->Body    = $message;

        $sent = $mail->send();
        
        if ($sent && $record_id && $pdo) {
            if ($type === 'correction') {
                $stmt = $pdo->prepare("
                    UPDATE attendance_correction 
                    SET notification_sent = TRUE, notification_date = NOW() 
                    WHERE leave_id = ?
                ");
            } else {
                $stmt = $pdo->prepare("
                    UPDATE attendance_lock 
                    SET notification_sent = TRUE, notification_date = NOW() 
                    WHERE lock_id = ?
                ");
            }
            $stmt->execute([$record_id]);
        }
        
        return $sent;
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

        /* ========== STUDENT REQUEST LEAVE ========== */
        if ($action === 'request_leave') {
            if ($current_user_role !== 'student') {
                throw new Exception("Only students can request leave!");
            }
            
            $student_id = intval($_POST['student_id'] ?? 0);
            $subject_id = intval($_POST['subject_id'] ?? 0);
            $teacher_id = intval($_POST['teacher_id'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            $reason_details = trim($_POST['reason_details'] ?? '');
            $start_date = trim($_POST['start_date'] ?? '');
            $days_count = intval($_POST['days_count'] ?? 1);
            
            if (!$student_id || !$subject_id || !$start_date || !$reason) {
                throw new Exception("All required fields must be filled!");
            }
            
            if ($days_count < 1 || $days_count > 30) {
                throw new Exception("Leave days must be between 1 and 30!");
            }
            
            if (strtotime($start_date) < strtotime(date('Y-m-d'))) {
                throw new Exception("Cannot request leave for past dates!");
            }
            
            // Get student and teacher info
            $info_stmt = $pdo->prepare("
                SELECT s.student_id, s.full_name, s.email, s.class_id,
                       t.teacher_id, t.teacher_name, t.email as teacher_email,
                       sub.subject_name
                FROM students s
                JOIN timetable tt ON s.class_id = tt.class_id
                JOIN teachers t ON tt.teacher_id = t.teacher_id
                JOIN subject sub ON tt.subject_id = sub.subject_id
                WHERE s.student_id = ? 
                AND tt.subject_id = ?
                AND s.status = 'active'
                LIMIT 1
            ");
            $info_stmt->execute([$student_id, $subject_id]);
            $student_info = $info_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$student_info) {
                throw new Exception("Student not found or not enrolled in this subject!");
            }
            
            // Check for overlapping requests
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
                )
            ");
            $check_stmt->execute([
                $student_id, $subject_id,
                $start_date, $start_date, $days_count,
                $start_date, $start_date, $days_count
            ]);
            $overlap = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($overlap['overlap_count'] > 0) {
                throw new Exception("You already have a pending or approved leave request for this period!");
            }
            
            // Insert leave request
            $insert_stmt = $pdo->prepare("
                INSERT INTO attendance_correction 
                (student_id, subject_id, teacher_id, academic_term_id, 
                 requested_by, reason, reason_details, start_date, days_count, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            $insert_stmt->execute([
                $student_id, $subject_id, $teacher_id, $academic_term_id,
                $current_user_id, $reason, $reason_details, $start_date, $days_count
            ]);
            
            $leave_id = $pdo->lastInsertId();
            
            // Send email to teacher
            if (!empty($student_info['teacher_email'])) {
                $teacher_subject = "New Leave Request - {$student_info['full_name']}";
                $teacher_message = "
                    <html>
                    <head><style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                        .header { background: #4CAF50; color: white; padding: 20px; text-align: center; }
                        .content { padding: 30px; background: #f9f9f9; }
                        .details { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; }
                        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
                    </style></head>
                    <body>
                        <div class='header'>
                            <h2>📋 New Leave Request</h2>
                        </div>
                        <div class='content'>
                            <p>Dear {$student_info['teacher_name']},</p>
                            <p>You have a new leave request from student:</p>
                            
                            <div class='details'>
                                <h3>📌 Request Details:</h3>
                                <table width='100%'>
                                    <tr><td><strong>Student:</strong></td><td>{$student_info['full_name']}</td></tr>
                                    <tr><td><strong>Subject:</strong></td><td>{$student_info['subject_name']}</td></tr>
                                    <tr><td><strong>Period:</strong></td><td>{$start_date} for {$days_count} day(s)</td></tr>
                                    <tr><td><strong>Reason:</strong></td><td>" . ucfirst($reason) . "</td></tr>
                                    <tr><td><strong>Details:</strong></td><td>" . ($reason_details ?: 'No details provided') . "</td></tr>
                                </table>
                            </div>
                            
                            <p style='text-align: center; margin-top: 30px;'>
                                <a href='http://localhost/attendance_management_system/attendance_correction.php' 
                                   style='background: #4CAF50; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px;'>
                                    Review Request
                                </a>
                            </p>
                        </div>
                        <div class='footer'>
                            <p>This is an automated message from the Attendance Management System.</p>
                        </div>
                    </body>
                    </html>
                ";
                
                sendEmail($student_info['teacher_email'], $teacher_subject, $teacher_message, $leave_id, $pdo, 'correction');
            }
            
            $message = "✅ Leave request submitted successfully! The teacher has been notified.";
            $type = "success";
            
        /* ========== TEACHER REQUEST UNLOCK ========== */
        } elseif ($action === 'request_unlock') {
            if ($current_user_role !== 'teacher') {
                throw new Exception("Only teachers can request unlock!");
            }
            
            $subject_id = intval($_POST['subject_id'] ?? 0);
            $lock_date = trim($_POST['lock_date'] ?? '');
            $reason = trim($_POST['reason'] ?? '');
            
            if (!$subject_id || !$lock_date) {
                throw new Exception("Subject and date are required!");
            }
            
            if (strtotime($lock_date) > strtotime(date('Y-m-d'))) {
                throw new Exception("Cannot request unlock for future dates!");
            }
            
            // Check if already locked
            $check_stmt = $pdo->prepare("
                SELECT lock_id FROM attendance_lock 
                WHERE teacher_id = ? AND subject_id = ? AND lock_date = ? AND is_locked = 1
            ");
            $check_stmt->execute([$teacher_id, $subject_id, $lock_date]);
            
            if ($check_stmt->fetch()) {
                throw new Exception("Unlock request already exists for this subject and date!");
            }
            
            // Get subject info
            $subj_stmt = $pdo->prepare("
                SELECT subject_name FROM subject WHERE subject_id = ?
            ");
            $subj_stmt->execute([$subject_id]);
            $subject = $subj_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Insert unlock request
            $insert_stmt = $pdo->prepare("
                INSERT INTO attendance_lock 
                (teacher_id, subject_id, lock_date, locked_by, reason, is_locked)
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            $insert_stmt->execute([$teacher_id, $subject_id, $lock_date, $current_user_id, $reason]);
            
            $lock_id = $pdo->lastInsertId();
            
            // Also lock the attendance records
            $lock_attendance = $pdo->prepare("
                UPDATE attendance 
                SET locked = 1, lock_reason = ?
                WHERE teacher_id = ? AND subject_id = ? AND attendance_date = ?
            ");
            $lock_attendance->execute([$reason, $teacher_id, $subject_id, $lock_date]);
            
            $message = "✅ Unlock request submitted successfully! Administrators have been notified.";
            $type = "success";
            
        /* ========== TEACHER APPROVE/REJECT LEAVE ========== */
        } elseif ($action === 'approve_leave' || $action === 'reject_leave') {
            if (!in_array($current_user_role, ['super_admin', 'faculty_admin', 'teacher'])) {
                throw new Exception("Unauthorized action!");
            }
            
            $leave_id = intval($_POST['leave_id'] ?? 0);
            $decision_notes = trim($_POST['decision_notes'] ?? '');
            
            if (!$leave_id) {
                throw new Exception("Invalid leave request!");
            }
            
            if ($action === 'reject_leave' && empty($decision_notes)) {
                throw new Exception("Decision notes are required for rejection!");
            }
            
            // Get leave details with proper joins
            $leave_stmt = $pdo->prepare("
                SELECT ac.*, 
                       s.full_name as student_name,
                       s.email as student_email,
                       s.reg_no,
                       s.class_id,
                       t.teacher_id, 
                       t.teacher_name, 
                       t.email as teacher_email,
                       sub.subject_name,
                       sub.subject_code,
                       c.class_name,
                       f.faculty_id, 
                       f.faculty_name,
                       u.username as requested_by_name
                FROM attendance_correction ac
                JOIN students s ON ac.student_id = s.student_id
                LEFT JOIN teachers t ON ac.teacher_id = t.teacher_id
                LEFT JOIN subject sub ON ac.subject_id = sub.subject_id
                LEFT JOIN classes c ON s.class_id = c.class_id
                LEFT JOIN faculties f ON c.faculty_id = f.faculty_id
                LEFT JOIN users u ON ac.requested_by = u.user_id
                WHERE ac.leave_id = ?
            ");
            $leave_stmt->execute([$leave_id]);
            $leave = $leave_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$leave) {
                throw new Exception("Leave request not found!");
            }
            
            // Role-based access control
            if ($current_user_role === 'teacher') {
                if ($leave['teacher_id'] != $teacher_id) {
                    throw new Exception("You can only approve leave requests for your own classes!");
                }
            } elseif ($current_user_role === 'faculty_admin') {
                if ($leave['faculty_id'] != $faculty_id) {
                    throw new Exception("You can only approve leave requests from your faculty!");
                }
            }
            
            $new_status = ($action === 'approve_leave') ? 'approved' : 'rejected';
            $status_text = ($new_status === 'approved') ? 'APPROVED' : 'REJECTED';
            
            // Update leave status
            $update_stmt = $pdo->prepare("
                UPDATE attendance_correction 
                SET status = ?, 
                    approved_by = ?, 
                    reason_details = CONCAT(COALESCE(reason_details, ''), ' | Teacher Notes: ', ?),
                    is_closed = 1,
                    updated_at = NOW()
                WHERE leave_id = ?
            ");
            $update_stmt->execute([$new_status, $current_user_id, $decision_notes, $leave_id]);
            
            // If approved, create attendance records
            if ($new_status === 'approved') {
                $current_date = $leave['start_date'];
                $counter = 0;
                
                while ($counter < $leave['days_count']) {
                    // Check if attendance record exists
                    $att_check = $pdo->prepare("
                        SELECT attendance_id FROM attendance 
                        WHERE student_id = ? AND subject_id = ? AND attendance_date = ?
                    ");
                    $att_check->execute([$leave['student_id'], $leave['subject_id'], $current_date]);
                    $existing = $att_check->fetch();
                    
                    if ($existing) {
                        // Update existing
                        $pdo->prepare("
                            UPDATE attendance 
                            SET status = 'excused', 
                                remarks = CONCAT(COALESCE(remarks, ''), ' | Approved leave: " . addslashes($decision_notes) . "'),
                                updated_at = NOW()
                            WHERE attendance_id = ?
                        ")->execute([$existing['attendance_id']]);
                    } else {
                        // Insert new
                        $pdo->prepare("
                            INSERT INTO attendance 
                            (student_id, subject_id, class_id, teacher_id, academic_term_id, 
                             attendance_date, status, remarks, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, 'excused', ?, NOW())
                        ")->execute([
                            $leave['student_id'],
                            $leave['subject_id'],
                            $leave['class_id'],
                            $leave['teacher_id'],
                            $academic_term_id,
                            $current_date,
                            'Approved leave: ' . $decision_notes
                        ]);
                    }
                    
                    $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
                    $counter++;
                }
            }
            
            // Send email to student
            if (!empty($leave['student_email'])) {
                $student_subject = "Leave Request {$status_text} - {$leave['subject_name']}";
                
                $student_message = "
                    <html>
                    <head><style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                        .header { background: " . ($new_status === 'approved' ? '#4CAF50' : '#f44336') . "; color: white; padding: 20px; text-align: center; }
                        .content { padding: 30px; background: #f9f9f9; }
                        .details { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; }
                        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
                    </style></head>
                    <body>
                        <div class='header'>
                            <h2>📋 Leave Request {$status_text}</h2>
                        </div>
                        <div class='content'>
                            <p>Dear {$leave['student_name']},</p>
                            <p>Your leave request has been <strong>{$status_text}</strong>.</p>
                            
                            <div class='details'>
                                <h3>📌 Request Details:</h3>
                                <table width='100%'>
                                    <tr><td><strong>Subject:</strong></td><td>{$leave['subject_name']}</td></tr>
                                    <tr><td><strong>Teacher:</strong></td><td>{$leave['teacher_name']}</td></tr>
                                    <tr><td><strong>Period:</strong></td><td>{$leave['start_date']} for {$leave['days_count']} day(s)</td></tr>
                                    <tr><td><strong>Reason:</strong></td><td>" . ucfirst($leave['reason']) . "</td></tr>
                                </table>
                            </div>
                            
                            <div class='details'>
                                <h3>📝 Decision Notes:</h3>
                                <p>{$decision_notes}</p>
                                <p><strong>Processed by:</strong> {$current_user_name}</p>
                            </div>
                            
                            " . ($new_status === 'approved' ? "
                            <div style='background: #E8F5E9; padding: 20px; border-radius: 8px; margin-top: 20px;'>
                                <p style='color: #4CAF50; font-weight: bold; margin: 0;'>
                                    ✅ Your attendance will be marked as EXCUSED for this period. No penalty will be applied.
                                </p>
                            </div>
                            " : "
                            <div style='background: #FFEBEE; padding: 20px; border-radius: 8px; margin-top: 20px;'>
                                <p style='color: #f44336; font-weight: bold; margin: 0;'>
                                    ❌ Your request has been rejected. Please attend classes as scheduled.
                                </p>
                            </div>
                            ") . "
                        </div>
                        <div class='footer'>
                            <p>This is an automated message from the Attendance Management System.</p>
                        </div>
                    </body>
                    </html>
                ";
                
                sendEmail($leave['student_email'], $student_subject, $student_message, $leave_id, $pdo, 'correction');
            }
            
            // Send email to parent if exists
            $parent_stmt = $pdo->prepare("
                SELECT p.email, p.full_name 
                FROM parents p
                JOIN students s ON p.parent_id = s.parent_id
                WHERE s.student_id = ?
            ");
            $parent_stmt->execute([$leave['student_id']]);
            $parent = $parent_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($parent && !empty($parent['email'])) {
                $parent_subject = "Student Leave Request Update - {$leave['student_name']}";
                $parent_message = "
                    <html>
                    <body style='font-family: Arial, sans-serif;'>
                        <h2 style='color: #333;'>Dear {$parent['full_name']},</h2>
                        <p>Your child's leave request has been <strong>{$status_text}</strong>.</p>
                        
                        <div style='background: #f5f5f5; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                            <p><strong>Student:</strong> {$leave['student_name']}</p>
                            <p><strong>Subject:</strong> {$leave['subject_name']}</p>
                            <p><strong>Period:</strong> {$leave['start_date']} for {$leave['days_count']} day(s)</p>
                            <p><strong>Status:</strong> {$status_text}</p>
                            <p><strong>Teacher Notes:</strong> {$decision_notes}</p>
                        </div>
                        
                        <p>Best regards,<br>Academic Affairs Department</p>
                    </body>
                    </html>
                ";
                
                sendEmail($parent['email'], $parent_subject, $parent_message, $leave_id, $pdo, 'correction');
            }
            
            $action_text = ($new_status === 'approved') ? 'approved' : 'rejected';
            $message = "✅ Leave request {$action_text} successfully! Notifications sent.";
            $type = "success";
            
        /* ========== ADMIN APPROVE/REJECT UNLOCK ========== */
        } elseif ($action === 'approve_unlock' || $action === 'reject_unlock') {
            if (!in_array($current_user_role, ['super_admin', 'faculty_admin'])) {
                throw new Exception("Only administrators can approve unlock requests!");
            }
            
            $lock_id = intval($_POST['lock_id'] ?? 0);
            $decision_notes = trim($_POST['decision_notes'] ?? '');
            
            if (!$lock_id) {
                throw new Exception("Invalid unlock request!");
            }
            
            if ($action === 'reject_unlock' && empty($decision_notes)) {
                throw new Exception("Decision notes are required for rejection!");
            }
            
            // Get unlock request details
            $lock_stmt = $pdo->prepare("
                SELECT al.*, 
                       t.teacher_name, t.email as teacher_email,
                       sub.subject_name,
                       u.username as requested_by_name,
                       c.class_id,
                       c.class_name,
                       c.faculty_id
                FROM attendance_lock al
                JOIN teachers t ON al.teacher_id = t.teacher_id
                JOIN subject sub ON al.subject_id = sub.subject_id
                JOIN users u ON al.locked_by = u.user_id
                LEFT JOIN timetable tt ON al.teacher_id = tt.teacher_id AND al.subject_id = tt.subject_id
                LEFT JOIN classes c ON tt.class_id = c.class_id
                WHERE al.lock_id = ? AND al.is_locked = 1
                GROUP BY al.lock_id
            ");
            $lock_stmt->execute([$lock_id]);
            $lock = $lock_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$lock) {
                throw new Exception("Unlock request not found or already processed!");
            }
            
            // Verify faculty admin has access to this request
            if ($current_user_role === 'faculty_admin' && $lock['faculty_id'] != $faculty_id) {
                throw new Exception("You don't have permission to process this request!");
            }
            
            $new_status = ($action === 'approve_unlock') ? 'approved' : 'rejected';
            $status_text = ($new_status === 'approved') ? 'APPROVED' : 'REJECTED';
            
            if ($action === 'approve_unlock') {
                // Approve unlock - set is_locked = 0
                $update_stmt = $pdo->prepare("
                    UPDATE attendance_lock 
                    SET is_locked = 0, 
                        unlocked_by = ?, 
                        unlocked_at = NOW()
                    WHERE lock_id = ?
                ");
                $update_stmt->execute([$current_user_id, $lock_id]);
                
                // Unlock attendance records
                $pdo->prepare("
                    UPDATE attendance 
                    SET locked = 0, 
                        remarks = CONCAT(COALESCE(remarks, ''), ' | Unlocked by admin: ', ?)
                    WHERE teacher_id = ? AND subject_id = ? AND attendance_date = ?
                ")->execute([$decision_notes, $lock['teacher_id'], $lock['subject_id'], $lock['lock_date']]);
                
            } else {
                // Reject unlock - keep is_locked = 1 but add notes
                $pdo->prepare("
                    UPDATE attendance_lock 
                    SET reason = CONCAT(COALESCE(reason, ''), ' | Rejected: ', ?)
                    WHERE lock_id = ?
                ")->execute([$decision_notes, $lock_id]);
            }
            
            // Send email to teacher
            if (!empty($lock['teacher_email'])) {
                $teacher_subject = "Unlock Request {$status_text} - {$lock['subject_name']}";
                
                $teacher_message = "
                    <html>
                    <head><style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                        .header { background: " . ($new_status === 'approved' ? '#4CAF50' : '#f44336') . "; color: white; padding: 20px; text-align: center; }
                        .content { padding: 30px; background: #f9f9f9; }
                        .details { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; }
                        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
                    </style></head>
                    <body>
                        <div class='header'>
                            <h2>🔓 Unlock Request {$status_text}</h2>
                        </div>
                        <div class='content'>
                            <p>Dear {$lock['teacher_name']},</p>
                            <p>Your unlock request has been <strong>{$status_text}</strong>.</p>
                            
                            <div class='details'>
                                <h3>📌 Request Details:</h3>
                                <table width='100%'>
                                    <tr><td><strong>Subject:</strong></td><td>{$lock['subject_name']}</td></tr>
                                    <tr><td><strong>Date:</strong></td><td>" . date('F d, Y', strtotime($lock['lock_date'])) . "</td></tr>
                                    <tr><td><strong>Reason:</strong></td><td>{$lock['reason']}</td></tr>
                                </table>
                            </div>
                            
                            <div class='details'>
                                <h3>📝 Admin Notes:</h3>
                                <p>{$decision_notes}</p>
                                <p><strong>Processed by:</strong> {$current_user_name}</p>
                            </div>
                            
                            " . ($new_status === 'approved' ? "
                            <div style='background: #E8F5E9; padding: 20px; border-radius: 8px; margin-top: 20px;'>
                                <p style='color: #4CAF50; font-weight: bold; margin: 0;'>
                                    ✅ Your attendance records have been unlocked. You can now edit attendance for this date.
                                </p>
                            </div>
                            " : "
                            <div style='background: #FFEBEE; padding: 20px; border-radius: 8px; margin-top: 20px;'>
                                <p style='color: #f44336; font-weight: bold; margin: 0;'>
                                    ❌ Your request has been rejected. Contact your faculty admin for more information.
                                </p>
                            </div>
                            ") . "
                        </div>
                        <div class='footer'>
                            <p>This is an automated message from the Attendance Management System.</p>
                        </div>
                    </body>
                    </html>
                ";
                
                sendEmail($lock['teacher_email'], $teacher_subject, $teacher_message, $lock_id, $pdo, 'unlock');
            }
            
            $message = "✅ Unlock request {$status_text} successfully! Teacher notified.";
            $type = "success";
            
        /* ========== STUDENT CANCEL LEAVE ========== */
        } elseif ($action === 'cancel_leave') {
            if ($current_user_role !== 'student') {
                throw new Exception("Only students can cancel leave requests!");
            }
            
            $leave_id = intval($_POST['leave_id'] ?? 0);
            
            if (!$leave_id) {
                throw new Exception("Invalid leave request!");
            }
            
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

$term_info = $pdo->query("
    SELECT at.*, ay.year_name 
    FROM academic_term at
    JOIN academic_year ay ON at.academic_year_id = ay.academic_year_id
    WHERE at.status = 'active'
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

// For students - Get their subjects
$student_subjects = [];
if ($current_user_role === 'student' && $student_id) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT 
            sub.subject_id,
            sub.subject_name,
            sub.subject_code,
            t.teacher_id,
            t.teacher_name,
            t.email as teacher_email,
            c.class_name,
            tt.day_of_week,
            tt.start_time,
            tt.end_time,
            tt.room_id,
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
        WHERE tt.class_id IN (SELECT class_id FROM students WHERE student_id = ?)
        AND (tt.academic_term_id = ? OR tt.academic_term_id IS NULL)
        ORDER BY sub.subject_name
    ");
    $stmt->execute([$student_id, $academic_term_id, $student_id, $academic_term_id]);
    $student_subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// For teachers - Get their subjects, pending leaves, and unlock requests
$teacher_subjects = [];
$teacher_pending_leaves = [];
$teacher_unlock_requests = [];
$teacher_unlock_history = [];
if ($current_user_role === 'teacher' && $teacher_id > 0) {
    // Get teacher's subjects for unlock requests
    $subj_stmt = $pdo->prepare("
        SELECT DISTINCT 
            sub.subject_id,
            sub.subject_name,
            sub.subject_code,
            c.class_id,
            c.class_name,
            c.class_level,
            COUNT(DISTINCT s.student_id) as total_students,
            tt.day_of_week,
            tt.start_time,
            tt.end_time,
            tt.room_id
        FROM subject sub
        JOIN timetable tt ON sub.subject_id = tt.subject_id
        JOIN classes c ON tt.class_id = c.class_id
        LEFT JOIN students s ON c.class_id = s.class_id AND s.status = 'active'
        WHERE tt.teacher_id = ?
        AND (tt.academic_term_id = ? OR tt.academic_term_id IS NULL)
        GROUP BY sub.subject_id, c.class_id
        ORDER BY c.class_name, sub.subject_name
    ");
    $subj_stmt->execute([$teacher_id, $academic_term_id]);
    $teacher_subjects = $subj_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get pending leave requests
    $pending_stmt = $pdo->prepare("
        SELECT 
            ac.*,
            s.full_name as student_name,
            s.reg_no,
            s.email as student_email,
            s.phone_number,
            s.class_id,
            sub.subject_name,
            sub.subject_code,
            c.class_name,
            c.class_level,
            u.username as requested_by_name,
            DATEDIFF(ac.start_date, CURDATE()) as days_until_start
        FROM attendance_correction ac
        JOIN students s ON ac.student_id = s.student_id
        JOIN subject sub ON ac.subject_id = sub.subject_id
        JOIN classes c ON s.class_id = c.class_id
        JOIN users u ON ac.requested_by = u.user_id
        WHERE ac.status = 'pending'
        AND ac.teacher_id = ?
        AND ac.academic_term_id = ?
        ORDER BY ac.start_date ASC
    ");
    $pending_stmt->execute([$teacher_id, $academic_term_id]);
    $teacher_pending_leaves = $pending_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get teacher's own unlock requests
    $unlock_stmt = $pdo->prepare("
        SELECT 
            al.*,
            sub.subject_name,
            sub.subject_code,
            u.username as processed_by_name,
            CASE 
                WHEN al.lock_date < CURDATE() AND al.is_locked = 1 THEN 'overdue'
                WHEN al.lock_date = CURDATE() AND al.is_locked = 1 THEN 'today'
                ELSE 'pending'
            END as request_status
        FROM attendance_lock al
        JOIN subject sub ON al.subject_id = sub.subject_id
        LEFT JOIN users u ON al.unlocked_by = u.user_id
        WHERE al.teacher_id = ?
        ORDER BY al.locked_at DESC
    ");
    $unlock_stmt->execute([$teacher_id]);
    $teacher_unlock_requests = $unlock_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// For faculty_admin - ADDED UNLOCK REQUESTS
$faculty_pending_leaves = [];
$faculty_pending_unlocks = [];
$faculty_teachers = [];
$faculty_students = [];
if ($current_user_role === 'faculty_admin' && $faculty_id > 0) {
    // Get pending leave requests
    $leave_stmt = $pdo->prepare("
        SELECT 
            ac.*,
            s.full_name as student_name,
            s.reg_no,
            s.email as student_email,
            sub.subject_name,
            t.teacher_name,
            t.email as teacher_email,
            c.class_name,
            u.username as requested_by_name,
            DATEDIFF(ac.start_date, CURDATE()) as days_until_start
        FROM attendance_correction ac
        JOIN students s ON ac.student_id = s.student_id
        JOIN classes c ON s.class_id = c.class_id
        JOIN subject sub ON ac.subject_id = sub.subject_id
        JOIN teachers t ON ac.teacher_id = t.teacher_id
        JOIN users u ON ac.requested_by = u.user_id
        WHERE ac.status = 'pending'
        AND c.faculty_id = ?
        AND ac.academic_term_id = ?
        ORDER BY ac.start_date ASC
    ");
    $leave_stmt->execute([$faculty_id, $academic_term_id]);
    $faculty_pending_leaves = $leave_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get pending unlock requests - NEW
    $unlock_stmt = $pdo->prepare("
        SELECT 
            al.*,
            t.teacher_name,
            t.email as teacher_email,
            sub.subject_name,
            sub.subject_code,
            u.username as requested_by_name,
            DATEDIFF(al.lock_date, CURDATE()) as days_until,
            c.class_name
        FROM attendance_lock al
        JOIN teachers t ON al.teacher_id = t.teacher_id
        JOIN subject sub ON al.subject_id = sub.subject_id
        JOIN users u ON al.locked_by = u.user_id
        LEFT JOIN timetable tt ON al.teacher_id = tt.teacher_id AND al.subject_id = tt.subject_id
        LEFT JOIN classes c ON tt.class_id = c.class_id
        WHERE al.is_locked = 1
        AND c.faculty_id = ?
        ORDER BY al.lock_date ASC
    ");
    $unlock_stmt->execute([$faculty_id]);
    $faculty_pending_unlocks = $unlock_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get teachers in faculty
    $teacher_stmt = $pdo->prepare("
        SELECT DISTINCT
            t.teacher_id,
            t.teacher_name,
            t.email,
            t.phone_number,
            COUNT(DISTINCT sub.subject_id) as subjects_count,
            COUNT(DISTINCT s.student_id) as students_count
        FROM teachers t
        JOIN timetable tt ON t.teacher_id = tt.teacher_id
        JOIN classes c ON tt.class_id = c.class_id
        LEFT JOIN subject sub ON tt.subject_id = sub.subject_id
        LEFT JOIN students s ON c.class_id = s.class_id AND s.status = 'active'
        WHERE c.faculty_id = ?
        AND (tt.academic_term_id = ? OR tt.academic_term_id IS NULL)
        GROUP BY t.teacher_id
        ORDER BY t.teacher_name
    ");
    $teacher_stmt->execute([$faculty_id, $academic_term_id]);
    $faculty_teachers = $teacher_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get student statistics in this faculty
    $student_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_students,
            SUM(CASE WHEN s.status = 'active' THEN 1 ELSE 0 END) as active_students,
            COUNT(DISTINCT c.class_id) as total_classes
        FROM students s
        JOIN classes c ON s.class_id = c.class_id
        WHERE c.faculty_id = ?
    ");
    $student_stmt->execute([$faculty_id]);
    $faculty_students = $student_stmt->fetch(PDO::FETCH_ASSOC);
}

// For super_admin
$all_pending_leaves = [];
$all_pending_unlocks = [];
if ($current_user_role === 'super_admin') {
    $leave_stmt = $pdo->prepare("
        SELECT 
            ac.*,
            s.full_name as student_name,
            s.reg_no,
            s.email as student_email,
            sub.subject_name,
            t.teacher_name,
            c.class_name,
            f.faculty_name,
            u.username as requested_by_name,
            DATEDIFF(ac.start_date, CURDATE()) as days_until_start
        FROM attendance_correction ac
        JOIN students s ON ac.student_id = s.student_id
        JOIN classes c ON s.class_id = c.class_id
        JOIN faculties f ON c.faculty_id = f.faculty_id
        JOIN subject sub ON ac.subject_id = sub.subject_id
        JOIN teachers t ON ac.teacher_id = t.teacher_id
        JOIN users u ON ac.requested_by = u.user_id
        WHERE ac.status = 'pending'
        AND ac.academic_term_id = ?
        ORDER BY f.faculty_name, ac.start_date ASC
    ");
    $leave_stmt->execute([$academic_term_id]);
    $all_pending_leaves = $leave_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $unlock_stmt = $pdo->prepare("
        SELECT 
            al.*,
            t.teacher_name,
            t.email as teacher_email,
            sub.subject_name,
            sub.subject_code,
            u.username as requested_by_name,
            c.class_name,
            f.faculty_name
        FROM attendance_lock al
        JOIN teachers t ON al.teacher_id = t.teacher_id
        JOIN subject sub ON al.subject_id = sub.subject_id
        JOIN users u ON al.locked_by = u.user_id
        LEFT JOIN timetable tt ON al.teacher_id = tt.teacher_id AND al.subject_id = tt.subject_id
        LEFT JOIN classes c ON tt.class_id = c.class_id
        LEFT JOIN faculties f ON c.faculty_id = f.faculty_id
        WHERE al.is_locked = 1
        ORDER BY al.lock_date ASC
    ");
    $unlock_stmt->execute();
    $all_pending_unlocks = $unlock_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get leave history
$leave_history = [];

if ($current_user_role === 'student' && $student_id) {
    $hist_stmt = $pdo->prepare("
        SELECT 
            ac.*,
            sub.subject_name,
            t.teacher_name,
            c.class_name,
            u.username as approved_by_name,
            CASE 
                WHEN ac.status = 'approved' AND CURDATE() BETWEEN ac.start_date AND ac.end_date THEN 'active'
                WHEN ac.status = 'approved' AND CURDATE() > ac.end_date THEN 'expired'
                ELSE ac.status
            END as display_status
        FROM attendance_correction ac
        LEFT JOIN subject sub ON ac.subject_id = sub.subject_id
        LEFT JOIN teachers t ON ac.teacher_id = t.teacher_id
        LEFT JOIN classes c ON (SELECT class_id FROM students WHERE student_id = ac.student_id) = c.class_id
        LEFT JOIN users u ON ac.approved_by = u.user_id
        WHERE ac.requested_by = ?
        ORDER BY ac.created_at DESC
    ");
    $hist_stmt->execute([$current_user_id]);
    $leave_history = $hist_stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($current_user_role === 'teacher' && $teacher_id > 0) {
    $hist_stmt = $pdo->prepare("
        SELECT 
            ac.*,
            sub.subject_name,
            s.full_name as student_name,
            s.reg_no,
            c.class_name,
            u.username as requested_by_name,
            u2.username as approved_by_name
        FROM attendance_correction ac
        JOIN students s ON ac.student_id = s.student_id
        LEFT JOIN subject sub ON ac.subject_id = sub.subject_id
        LEFT JOIN classes c ON s.class_id = c.class_id
        LEFT JOIN users u ON ac.requested_by = u.user_id
        LEFT JOIN users u2 ON ac.approved_by = u2.user_id
        WHERE ac.teacher_id = ?
        ORDER BY ac.created_at DESC
    ");
    $hist_stmt->execute([$teacher_id]);
    $leave_history = $hist_stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($current_user_role === 'faculty_admin' && $faculty_id > 0) {
    $hist_stmt = $pdo->prepare("
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
        JOIN classes c ON s.class_id = c.class_id
        LEFT JOIN subject sub ON ac.subject_id = sub.subject_id
        LEFT JOIN teachers t ON ac.teacher_id = t.teacher_id
        LEFT JOIN users u ON ac.requested_by = u.user_id
        LEFT JOIN users u2 ON ac.approved_by = u2.user_id
        WHERE c.faculty_id = ?
        ORDER BY ac.created_at DESC
    ");
    $hist_stmt->execute([$faculty_id]);
    $leave_history = $hist_stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Super admin
    $hist_stmt = $pdo->prepare("
        SELECT 
            ac.*,
            sub.subject_name,
            s.full_name as student_name,
            s.reg_no,
            t.teacher_name,
            c.class_name,
            f.faculty_name,
            u.username as requested_by_name,
            u2.username as approved_by_name
        FROM attendance_correction ac
        JOIN students s ON ac.student_id = s.student_id
        JOIN classes c ON s.class_id = c.class_id
        JOIN faculties f ON c.faculty_id = f.faculty_id
        LEFT JOIN subject sub ON ac.subject_id = sub.subject_id
        LEFT JOIN teachers t ON ac.teacher_id = t.teacher_id
        LEFT JOIN users u ON ac.requested_by = u.user_id
        LEFT JOIN users u2 ON ac.approved_by = u2.user_id
        ORDER BY ac.created_at DESC
    ");
    $hist_stmt->execute();
    $leave_history = $hist_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Statistics
$stats = [
    'total' => count($leave_history),
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'unlock_pending' => 0
];

foreach ($leave_history as $record) {
    if (isset($record['status'])) {
        $stats[$record['status']]++;
    }
}

if ($current_user_role === 'teacher') {
    $stats['unlock_pending'] = count(array_filter($teacher_unlock_requests, function($r) { return $r['is_locked'] == 1; }));
} elseif ($current_user_role === 'faculty_admin') {
    $stats['unlock_pending'] = count($faculty_pending_unlocks);
} elseif ($current_user_role === 'super_admin') {
    $stats['unlock_pending'] = count($all_pending_unlocks);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance System - <?php echo ucfirst($current_user_role); ?></title>
    <link rel="icon" type="image/png" href="../images.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
    /* Modern CSS Design */
    :root {
        --primary: #2c3e50;
        --primary-dark: #1a252f;
        --primary-light: #34495e;
        --secondary: #3498db;
        --secondary-dark: #2980b9;
        --success: #27ae60;
        --danger: #e74c3c;
        --warning: #f39c12;
        --info: #3498db;
        --dark: #2c3e50;
        --gray: #7f8c8d;
        --light-gray: #ecf0f1;
        --white: #ffffff;
        --sidebar-width: 260px;
        --sidebar-collapsed-width: 70px;
        --header-height: 70px;
        --shadow: 0 2px 10px rgba(0,0,0,0.1);
        --shadow-hover: 0 5px 20px rgba(52, 152, 219, 0.15);
        --transition: all 0.3s ease;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
        font-family: 'Segoe UI', 'Inter', sans-serif;
    }

    body {
        background: #f5f5f5;
        min-height: 100vh;
        overflow-x: hidden;
    }

    /* ==============================
       SIDEBAR STYLES
    ============================== */
    .sidebar {
        position: fixed;
        top: 0;
        left: 0;
        height: 100vh;
        width: var(--sidebar-width);
        background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        color: var(--white);
        transition: var(--transition);
        z-index: 1000;
        overflow-y: auto;
        box-shadow: 2px 0 20px rgba(0,0,0,0.2);
    }

    .sidebar.collapsed {
        width: var(--sidebar-collapsed-width);
    }

    .sidebar-header {
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 15px;
        border-bottom: 1px solid rgba(255,255,255,0.1);
        margin-bottom: 20px;
    }

    .sidebar-header img {
        width: 40px;
        height: 40px;
        border-radius: 10px;
    }

    .sidebar-header h3 {
        font-size: 16px;
        font-weight: 600;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .sidebar-header .university-name {
        font-size: 12px;
        opacity: 0.8;
        margin-top: 2px;
    }

    .sidebar.collapsed .sidebar-header h3,
    .sidebar.collapsed .sidebar-header .university-name {
        display: none;
    }

    .sidebar-menu {
        list-style: none;
        padding: 0 15px;
    }

    .sidebar-menu li {
        margin-bottom: 5px;
    }

    .sidebar-menu a {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 12px 15px;
        color: rgba(255,255,255,0.8);
        text-decoration: none;
        border-radius: 10px;
        transition: var(--transition);
        white-space: nowrap;
    }

    .sidebar-menu a:hover {
        background: rgba(255,255,255,0.1);
        color: var(--white);
    }

    .sidebar-menu a.active {
        background: var(--secondary);
        color: var(--white);
        box-shadow: 0 4px 15px rgba(52, 152, 219, 0.3);
    }

    .sidebar-menu a i {
        font-size: 20px;
        min-width: 30px;
        text-align: center;
    }

    .sidebar-menu a span {
        font-size: 14px;
        font-weight: 500;
    }

    .sidebar.collapsed .sidebar-menu a span {
        display: none;
    }

    .notification-badge {
        background: var(--danger);
        color: white;
        border-radius: 50%;
        width: 20px;
        height: 20px;
        font-size: 11px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        margin-left: auto;
    }

    .sidebar.collapsed .notification-badge {
        position: absolute;
        right: 5px;
        top: 5px;
    }

    .sidebar-footer {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        padding: 20px;
        border-top: 1px solid rgba(255,255,255,0.1);
    }

    .sidebar.collapsed .sidebar-footer {
        text-align: center;
    }

    .sidebar-footer .user-info {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .sidebar.collapsed .user-info .user-details {
        display: none;
    }

    .user-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: var(--secondary);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 18px;
    }

    .toggle-btn {
        position: fixed;
        top: 20px;
        left: var(--sidebar-width);
        transform: translateX(-50%);
        width: 30px;
        height: 30px;
        background: var(--white);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        z-index: 1001;
        box-shadow: var(--shadow);
        transition: var(--transition);
        border: none;
    }

    .toggle-btn:hover {
        background: var(--secondary);
        color: var(--white);
    }

    .sidebar.collapsed + .toggle-btn {
        left: var(--sidebar-collapsed-width);
    }

    /* ==============================
       MAIN CONTENT
    ============================== */
    .main-content {
        margin-left: var(--sidebar-width);
        padding: 20px;
        transition: var(--transition);
        min-height: 100vh;
    }

    .main-content.expanded {
        margin-left: var(--sidebar-collapsed-width);
    }

    /* ==============================
       HEADER
    ============================== */
    .top-header {
        background: var(--white);
        border-radius: 12px;
        padding: 15px 25px;
        margin-bottom: 25px;
        box-shadow: var(--shadow);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
    }

    .logo-area {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .logo-area img {
        height: 40px;
    }

    .logo-area h2 {
        font-size: 20px;
        color: var(--primary);
        font-weight: 600;
    }

    .user-profile {
        display: flex;
        align-items: center;
        gap: 20px;
    }

    .profile-info {
        text-align: right;
    }

    .profile-info .name {
        font-weight: 600;
        color: var(--dark);
    }

    .profile-info .role {
        font-size: 12px;
        color: var(--gray);
    }

    .profile-info .faculty {
        font-size: 11px;
        color: var(--secondary);
    }

    .profile-avatar {
        width: 45px;
        height: 45px;
        border-radius: 50%;
        background: var(--secondary);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 18px;
    }

    .logout-btn {
        background: none;
        border: 1px solid var(--danger);
        color: var(--danger);
        padding: 8px 15px;
        border-radius: 8px;
        cursor: pointer;
        transition: var(--transition);
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
    }

    .logout-btn:hover {
        background: var(--danger);
        color: white;
    }

    /* ==============================
       PAGE HEADER
    ============================== */
    .page-header {
        background: linear-gradient(135deg, #3498db, #2c3e50);
        border-radius: 12px;
        padding: 25px;
        margin-bottom: 25px;
        color: white;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
    }

    .page-header h1 {
        font-size: 24px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .faculty-badge {
        background: rgba(255,255,255,0.2);
        padding: 5px 15px;
        border-radius: 20px;
        font-size: 13px;
        display: flex;
        align-items: center;
        gap: 5px;
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
        border-radius: 12px;
        padding: 20px;
        box-shadow: var(--shadow);
        display: flex;
        align-items: center;
        gap: 15px;
        transition: var(--transition);
        border-left: 4px solid;
    }

    .stat-card:hover {
        transform: translateY(-3px);
        box-shadow: var(--shadow-hover);
    }

    .stat-card.total { border-left-color: var(--primary); }
    .stat-card.pending { border-left-color: var(--warning); }
    .stat-card.approved { border-left-color: var(--success); }
    .stat-card.rejected { border-left-color: var(--danger); }
    .stat-card.unlock { border-left-color: var(--secondary); }

    .stat-icon {
        width: 50px;
        height: 50px;
        border-radius: 10px;
        background: rgba(52, 152, 219, 0.1);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--secondary);
        font-size: 24px;
    }

    .stat-info h3 {
        font-size: 28px;
        font-weight: 700;
        color: var(--dark);
        line-height: 1.2;
        margin-bottom: 5px;
    }

    .stat-info p {
        color: var(--gray);
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
        box-shadow: var(--shadow);
        flex-wrap: wrap;
    }

    .tab-btn {
        flex: 1;
        min-width: 150px;
        background: transparent;
        color: var(--gray);
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
        transition: var(--transition);
        position: relative;
    }

    .tab-btn:hover {
        background: rgba(52, 152, 219, 0.08);
        color: var(--secondary);
    }

    .tab-btn.active {
        background: linear-gradient(135deg, var(--secondary), var(--primary));
        color: white;
        box-shadow: 0 4px 15px rgba(52, 152, 219, 0.3);
    }

    .notification-badge-tab {
        position: absolute;
        top: -8px;
        right: 5px;
        background: var(--danger);
        color: white;
        border-radius: 50%;
        width: 22px;
        height: 22px;
        font-size: 11px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
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
       CARDS
    ============================== */
    .card {
        background: var(--white);
        border-radius: 12px;
        padding: 25px;
        margin-bottom: 25px;
        box-shadow: var(--shadow);
        border-left: 4px solid var(--secondary);
    }

    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 2px solid var(--light-gray);
    }

    .card-header h3 {
        font-size: 18px;
        font-weight: 600;
        color: var(--dark);
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .card-header h3 i {
        color: var(--secondary);
    }

    /* ==============================
       TABLES
    ============================== */
    .table-container {
        overflow-x: auto;
        border-radius: 10px;
        background: white;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }

    table {
        width: 100%;
        border-collapse: collapse;
        min-width: 1000px;
    }

    thead {
        background: linear-gradient(135deg, #f8fafc, #f1f5f9);
    }

    thead th {
        padding: 15px;
        font-weight: 600;
        color: var(--dark);
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 2px solid #e2e8f0;
    }

    tbody td {
        padding: 15px;
        border-bottom: 1px solid #f1f5f9;
        color: var(--gray);
        font-size: 14px;
    }

    tbody tr:hover {
        background: rgba(52, 152, 219, 0.02);
    }

    /* ==============================
       BADGES
    ============================== */
    .badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 30px;
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.3px;
    }

    .badge-pending {
        background: rgba(243, 156, 18, 0.1);
        color: #f39c12;
        border: 1px solid rgba(243, 156, 18, 0.2);
    }

    .badge-approved {
        background: rgba(39, 174, 96, 0.1);
        color: #27ae60;
        border: 1px solid rgba(39, 174, 96, 0.2);
    }

    .badge-rejected {
        background: rgba(231, 76, 60, 0.1);
        color: #e74c3c;
        border: 1px solid rgba(231, 76, 60, 0.2);
    }

    .badge-locked {
        background: rgba(52, 152, 219, 0.1);
        color: #3498db;
        border: 1px solid rgba(52, 152, 219, 0.2);
    }

    .badge-unlocked {
        background: rgba(46, 204, 113, 0.1);
        color: #27ae60;
        border: 1px solid rgba(46, 204, 113, 0.2);
    }

    /* ==============================
       BUTTONS
    ============================== */
    .btn {
        padding: 8px 15px;
        border: none;
        border-radius: 8px;
        font-weight: 500;
        font-size: 12px;
        cursor: pointer;
        transition: var(--transition);
        display: inline-flex;
        align-items: center;
        gap: 5px;
        background: white;
        color: var(--dark);
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    }

    .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }

    .btn-success {
        background: linear-gradient(135deg, #27ae60, #229954);
        color: white;
    }

    .btn-danger {
        background: linear-gradient(135deg, #e74c3c, #c0392b);
        color: white;
    }

    .btn-warning {
        background: linear-gradient(135deg, #f39c12, #e67e22);
        color: white;
    }

    .btn-sm {
        padding: 5px 10px;
        font-size: 11px;
    }

    .actions {
        display: flex;
        gap: 5px;
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
        align-items: center;
        justify-content: center;
    }

    .modal-content {
        background: white;
        border-radius: 12px;
        padding: 30px;
        max-width: 500px;
        width: 90%;
        max-height: 80vh;
        overflow-y: auto;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        animation: slideUp 0.4s ease;
    }

    @keyframes slideUp {
        from { transform: translateY(40px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }

    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 15px;
        border-bottom: 2px solid var(--light-gray);
    }

    .modal-header h3 {
        color: var(--dark);
        font-size: 18px;
        font-weight: 600;
    }

    .modal-close {
        background: none;
        border: none;
        font-size: 24px;
        cursor: pointer;
        color: var(--gray);
        transition: var(--transition);
    }

    .modal-close:hover {
        color: var(--danger);
    }

    /* ==============================
       FORM ELEMENTS
    ============================== */
    .form-group {
        margin-bottom: 20px;
    }

    .form-label {
        display: block;
        margin-bottom: 8px;
        font-weight: 500;
        color: var(--dark);
        font-size: 14px;
    }

    .form-control, .form-select {
        width: 100%;
        padding: 10px 15px;
        border: 2px solid var(--light-gray);
        border-radius: 8px;
        font-size: 14px;
        transition: var(--transition);
        background: white;
    }

    .form-control:focus, .form-select:focus {
        outline: none;
        border-color: var(--secondary);
        box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
    }

    textarea.form-control {
        min-height: 100px;
        resize: vertical;
    }

    /* ==============================
       ALERT
    ============================== */
    .alert {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 10000;
        padding: 15px 25px;
        border-radius: 8px;
        font-weight: 500;
        box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        animation: slideInRight 0.5s ease;
        display: flex;
        align-items: center;
        gap: 15px;
        max-width: 400px;
        color: white;
    }

    .alert-success {
        background: linear-gradient(135deg, #27ae60, #229954);
    }

    .alert-error {
        background: linear-gradient(135deg, #e74c3c, #c0392b);
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
        padding: 40px 20px;
    }

    .empty-state i {
        font-size: 60px;
        color: var(--light-gray);
        margin-bottom: 15px;
    }

    .empty-state h4 {
        color: var(--dark);
        margin-bottom: 5px;
    }

    .empty-state p {
        color: var(--gray);
    }

    /* ==============================
       FOOTER
    ============================== */
    .footer {
        margin-top: 30px;
        padding: 20px;
        background: var(--white);
        border-radius: 12px;
        text-align: center;
        color: var(--gray);
        font-size: 13px;
        box-shadow: var(--shadow);
    }

    .footer .university-name {
        font-weight: 600;
        color: var(--primary);
        margin-bottom: 5px;
    }

    .footer .motto {
        font-size: 11px;
        color: var(--gray);
        margin-bottom: 10px;
    }

    .footer .copyright {
        font-size: 11px;
        opacity: 0.8;
    }

    /* ==============================
       RESPONSIVE
    ============================== */
    @media (max-width: 1024px) {
        .sidebar {
            left: -260px;
        }
        
        .sidebar.active {
            left: 0;
        }
        
        .main-content {
            margin-left: 0;
        }
        
        .toggle-btn {
            left: 20px;
            transform: none;
        }
    }

    @media (max-width: 768px) {
        .top-header {
            flex-direction: column;
            align-items: stretch;
        }
        
        .user-profile {
            justify-content: space-between;
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
    }

    @media (max-width: 480px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
        
        .btn {
            width: 100%;
            justify-content: center;
        }
        
        .actions {
            flex-direction: column;
        }
    }
    </style>
</head>
<body>

        <?php include('../includes/header.php'); ?>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
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
        <!-- Page Header -->
        <div class="page-header">
            <h1>
                <i class="fas fa-calendar-check"></i>
                Attendance Correction System
                <?php if ($active_term_name): ?>
                    <span style="font-size: 14px; background: rgba(255,255,255,0.2); padding: 5px 15px; border-radius: 20px; margin-left: 15px;">
                        <?php echo htmlspecialchars($active_term_name); ?>
                    </span>
                <?php endif; ?>
            </h1>
            <div class="faculty-badge">
                <i class="fas fa-university"></i> <?php echo htmlspecialchars($faculty_name); ?>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-icon">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['total']; ?></h3>
                    <p>Total Requests</p>
                </div>
            </div>
            <div class="stat-card pending">
                <div class="stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo count($faculty_pending_leaves); ?></h3>
                    <p>Pending</p>
                </div>
            </div>
            <div class="stat-card unlock">
                <div class="stat-icon">
                    <i class="fas fa-unlock-alt"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo count($faculty_pending_unlocks); ?></h3>
                    <p>Unlock Requests</p>
                </div>
            </div>
            <div class="stat-card approved">
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $stats['approved']; ?></h3>
                    <p>Approved</p>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab-btn active" data-tab="tab1">
                <i class="fas fa-tasks"></i>
                Faculty Pending Requests
                <?php if (count($faculty_pending_leaves) > 0): ?>
                    <span class="notification-badge-tab"><?php echo count($faculty_pending_leaves); ?></span>
                <?php endif; ?>
            </button>
            <button class="tab-btn" data-tab="tab2">
                <i class="fas fa-unlock-alt"></i>
                Unlock Requests
                <?php if (count($faculty_pending_unlocks) > 0): ?>
                    <span class="notification-badge-tab"><?php echo count($faculty_pending_unlocks); ?></span>
                <?php endif; ?>
            </button>
            <button class="tab-btn" data-tab="tab3">
                <i class="fas fa-history"></i>
                Leave History
            </button>
            <button class="tab-btn" data-tab="tab4">
                <i class="fas fa-chart-bar"></i>
                Faculty Overview
            </button>
        </div>

        <!-- TAB 1: FACULTY PENDING REQUESTS -->
        <div id="tab1" class="tab-content active">
            <div class="card">
                <div class="card-header">
                    <h3>
                        <i class="fas fa-tasks"></i>
                        Faculty Pending Requests
                        <span class="badge badge-pending" style="margin-left: 10px;"><?php echo count($faculty_pending_leaves); ?> requests</span>
                    </h3>
                </div>

                <?php if (!empty($faculty_pending_leaves)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Teacher</th>
                                    <th>Student</th>
                                    <th>Subject</th>
                                    <th>Class</th>
                                    <th>Leave Period</th>
                                    <th>Reason</th>
                                    <th>Days Left</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($faculty_pending_leaves as $leave): 
                                    $days_left = $leave['days_until_start'] ?? 0;
                                    $urgency = $days_left <= 0 ? 'style="background: rgba(231, 76, 60, 0.05);"' : ($days_left <= 2 ? 'style="background: rgba(243, 156, 18, 0.05);"' : '');
                                ?>
                                    <tr <?php echo $urgency; ?>>
                                        <td>
                                            <strong><?php echo htmlspecialchars($leave['teacher_name']); ?></strong>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($leave['student_name']); ?></strong>
                                            <br>
                                            <small style="color: #666;"><?php echo htmlspecialchars($leave['reg_no']); ?></small>
                                            <br>
                                            <small><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($leave['student_email']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($leave['subject_name']); ?></td>
                                        <td><?php echo htmlspecialchars($leave['class_name']); ?></td>
                                        <td>
                                            <strong><?php echo date('M d, Y', strtotime($leave['start_date'])); ?></strong>
                                            <br>
                                            <small><?php echo $leave['days_count']; ?> day(s) | Ends: <?php echo date('M d, Y', strtotime($leave['end_date'])); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge badge-pending"><?php echo ucfirst($leave['reason']); ?></span>
                                            <?php if (!empty($leave['reason_details'])): ?>
                                                <br><small><?php echo htmlspecialchars(substr($leave['reason_details'], 0, 30)) . '...'; ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($days_left > 0): ?>
                                                <span style="color: #27ae60;"><?php echo $days_left; ?> days</span>
                                            <?php else: ?>
                                                <span style="color: #e74c3c; font-weight: bold;">Starts today!</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="actions">
                                                <button class="btn btn-success btn-sm" 
                                                        onclick="openLeaveModal(<?php echo $leave['leave_id']; ?>, 'approve')">
                                                    <i class="fas fa-check"></i> Approve
                                                </button>
                                                <button class="btn btn-danger btn-sm" 
                                                        onclick="openLeaveModal(<?php echo $leave['leave_id']; ?>, 'reject')">
                                                    <i class="fas fa-times"></i> Reject
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
                        <i class="fas fa-check-circle" style="color: #27ae60;"></i>
                        <h4>No Pending Requests</h4>
                        <p>Your faculty has no pending leave requests at this time.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB 2: UNLOCK REQUESTS -->
        <div id="tab2" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <h3>
                        <i class="fas fa-unlock-alt"></i>
                        Teacher Unlock Requests
                        <span class="badge badge-locked" style="margin-left: 10px;"><?php echo count($faculty_pending_unlocks); ?> requests</span>
                    </h3>
                </div>

                <?php if (!empty($faculty_pending_unlocks)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Teacher</th>
                                    <th>Subject</th>
                                    <th>Class</th>
                                    <th>Date</th>
                                    <th>Reason</th>
                                    <th>Requested</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($faculty_pending_unlocks as $unlock): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($unlock['teacher_name']); ?></strong>
                                            <br>
                                            <small><?php echo htmlspecialchars($unlock['teacher_email']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($unlock['subject_name']); ?></td>
                                        <td><?php echo htmlspecialchars($unlock['class_name'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php echo date('M d, Y', strtotime($unlock['lock_date'])); ?>
                                            <?php if ($unlock['lock_date'] < date('Y-m-d')): ?>
                                                <br><span class="badge badge-rejected">Past</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($unlock['reason']); ?></td>
                                        <td>
                                            <?php echo date('M d, Y', strtotime($unlock['locked_at'])); ?>
                                            <br>
                                            <small>by <?php echo htmlspecialchars($unlock['requested_by_name']); ?></small>
                                        </td>
                                        <td>
                                            <div class="actions">
                                                <button class="btn btn-success btn-sm" 
                                                        onclick="openUnlockModal(<?php echo $unlock['lock_id']; ?>, 'approve')">
                                                    <i class="fas fa-unlock-alt"></i> Approve
                                                </button>
                                                <button class="btn btn-danger btn-sm" 
                                                        onclick="openUnlockModal(<?php echo $unlock['lock_id']; ?>, 'reject')">
                                                    <i class="fas fa-times"></i> Reject
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
                        <h4>No Unlock Requests</h4>
                        <p>No teacher unlock requests at this time.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB 3: LEAVE HISTORY -->
        <div id="tab3" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <h3>
                        <i class="fas fa-history"></i>
                        Leave Request History
                    </h3>
                </div>

                <?php if (!empty($leave_history)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Teacher</th>
                                    <th>Subject</th>
                                    <th>Class</th>
                                    <th>Leave Period</th>
                                    <th>Reason</th>
                                    <th>Status</th>
                                    <th>Processed By</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($leave_history as $history): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($history['student_name'] ?? $history['full_name']); ?></strong>
                                            <br>
                                            <small><?php echo htmlspecialchars($history['reg_no'] ?? ''); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($history['teacher_name']); ?></td>
                                        <td><?php echo htmlspecialchars($history['subject_name']); ?></td>
                                        <td><?php echo htmlspecialchars($history['class_name']); ?></td>
                                        <td>
                                            <?php echo date('M d, Y', strtotime($history['start_date'])); ?> - 
                                            <?php echo date('M d, Y', strtotime($history['end_date'])); ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-pending"><?php echo ucfirst($history['reason']); ?></span>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo $history['status']; ?>">
                                                <?php echo ucfirst($history['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($history['approved_by_name'] ?? 'N/A'); ?>
                                        </td>
                                        <td>
                                            <?php echo date('M d, Y', strtotime($history['updated_at'] ?? $history['created_at'])); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <h4>No History Found</h4>
                        <p>No leave requests have been processed yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB 4: FACULTY OVERVIEW -->
        <div id="tab4" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <h3>
                        <i class="fas fa-chart-bar"></i>
                        Faculty Overview
                    </h3>
                </div>

                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px;">
                    <div style="background: linear-gradient(135deg, #3498db, #2980b9); color: white; padding: 20px; border-radius: 10px;">
                        <i class="fas fa-users" style="font-size: 32px; margin-bottom: 10px;"></i>
                        <h3 style="font-size: 28px;"><?php echo $faculty_students['total_students'] ?? 0; ?></h3>
                        <p>Total Students</p>
                        <small><?php echo $faculty_students['active_students'] ?? 0; ?> active</small>
                    </div>
                    <div style="background: linear-gradient(135deg, #e67e22, #d35400); color: white; padding: 20px; border-radius: 10px;">
                        <i class="fas fa-chalkboard-teacher" style="font-size: 32px; margin-bottom: 10px;"></i>
                        <h3 style="font-size: 28px;"><?php echo count($faculty_teachers); ?></h3>
                        <p>Total Teachers</p>
                    </div>
                    <div style="background: linear-gradient(135deg, #27ae60, #229954); color: white; padding: 20px; border-radius: 10px;">
                        <i class="fas fa-school" style="font-size: 32px; margin-bottom: 10px;"></i>
                        <h3 style="font-size: 28px;"><?php echo $faculty_students['total_classes'] ?? 0; ?></h3>
                        <p>Total Classes</p>
                    </div>
                </div>

                <?php if (!empty($faculty_teachers)): ?>
                    <h4 style="margin: 20px 0;">Teachers in Faculty</h4>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Teacher Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Subjects</th>
                                    <th>Students</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($faculty_teachers as $teacher): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($teacher['teacher_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($teacher['email']); ?></td>
                                        <td><?php echo htmlspecialchars($teacher['phone_number']); ?></td>
                                        <td><?php echo $teacher['subjects_count']; ?></td>
                                        <td><?php echo $teacher['students_count']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <div class="university-name">Hormuud University</div>
            <div class="motto">Excellence in Education & Innovation</div>
            <div class="copyright">Hormuud University © <?php echo date('Y'); ?> — All Rights Reserved.</div>
            <div style="margin-top: 10px; font-size: 10px;">Developed by BSE1 Student</div>
        </div>
    </div>

    <!-- Leave Decision Modal -->
    <div id="leaveModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="leaveModalTitle">Process Leave Request</h3>
                <button class="modal-close" onclick="closeLeaveModal()">&times;</button>
            </div>
            
            <form id="leaveDecisionForm" method="POST">
                <input type="hidden" id="leaveModalId" name="leave_id">
                <input type="hidden" id="leaveModalAction" name="action">
                
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-comment-dots"></i> Decision Notes
                    </label>
                    <textarea name="decision_notes" id="leaveDecisionNotes" class="form-control" 
                              rows="4" required></textarea>
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn btn-danger" onclick="closeLeaveModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-success" id="leaveModalSubmitBtn">
                        <i class="fas fa-check"></i> Process
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Unlock Decision Modal -->
    <div id="unlockModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="unlockModalTitle">Process Unlock Request</h3>
                <button class="modal-close" onclick="closeUnlockModal()">&times;</button>
            </div>
            
            <form id="unlockDecisionForm" method="POST">
                <input type="hidden" id="unlockModalId" name="lock_id">
                <input type="hidden" id="unlockModalAction" name="action">
                
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-comment-dots"></i> Decision Notes
                    </label>
                    <textarea name="decision_notes" id="unlockDecisionNotes" class="form-control" 
                              rows="4" required></textarea>
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn btn-danger" onclick="closeUnlockModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-success" id="unlockModalSubmitBtn">
                        <i class="fas fa-check"></i> Process
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const toggleBtn = document.getElementById('toggleSidebar');
        const toggleIcon = toggleBtn.querySelector('i');

        toggleBtn.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
            
            if (sidebar.classList.contains('collapsed')) {
                toggleIcon.className = 'fas fa-chevron-right';
            } else {
                toggleIcon.className = 'fas fa-chevron-left';
            }
        });

        // Tab switching from sidebar
        document.querySelectorAll('.sidebar-menu a').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                
                document.querySelectorAll('.sidebar-menu a').forEach(l => l.classList.remove('active'));
                this.classList.add('active');
                
                const tabId = this.dataset.tab;
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                document.getElementById(tabId).classList.add('active');
            });
        });

        // Tab switching from top tabs
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                const tabId = this.dataset.tab;
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                document.getElementById(tabId).classList.add('active');
            });
        });

        // Leave Modal Functions
        function openLeaveModal(leaveId, action) {
            const title = action === 'approve' ? 'Approve Leave Request' : 'Reject Leave Request';
            const buttonText = action === 'approve' ? 'Approve' : 'Reject';
            const buttonClass = action === 'approve' ? 'btn-success' : 'btn-danger';
            
            document.getElementById('leaveModalTitle').textContent = title;
            document.getElementById('leaveModalId').value = leaveId;
            document.getElementById('leaveModalAction').value = action + '_leave';
            
            const submitBtn = document.getElementById('leaveModalSubmitBtn');
            submitBtn.innerHTML = `<i class="fas fa-${action === 'approve' ? 'check' : 'times'}"></i> ${buttonText}`;
            submitBtn.className = `btn ${buttonClass}`;
            
            document.getElementById('leaveModal').style.display = 'flex';
            document.getElementById('leaveDecisionNotes').focus();
        }

        function closeLeaveModal() {
            document.getElementById('leaveModal').style.display = 'none';
            document.getElementById('leaveDecisionForm').reset();
        }

        // Unlock Modal Functions
        function openUnlockModal(lockId, action) {
            const title = action === 'approve' ? 'Approve Unlock Request' : 'Reject Unlock Request';
            const buttonText = action === 'approve' ? 'Approve' : 'Reject';
            const buttonClass = action === 'approve' ? 'btn-success' : 'btn-danger';
            
            document.getElementById('unlockModalTitle').textContent = title;
            document.getElementById('unlockModalId').value = lockId;
            document.getElementById('unlockModalAction').value = action + '_unlock';
            
            const submitBtn = document.getElementById('unlockModalSubmitBtn');
            submitBtn.innerHTML = `<i class="fas fa-${action === 'approve' ? 'check' : 'times'}"></i> ${buttonText}`;
            submitBtn.className = `btn ${buttonClass}`;
            
            document.getElementById('unlockModal').style.display = 'flex';
            document.getElementById('unlockDecisionNotes').focus();
        }

        function closeUnlockModal() {
            document.getElementById('unlockModal').style.display = 'none';
            document.getElementById('unlockDecisionForm').reset();
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target === document.getElementById('leaveModal')) {
                closeLeaveModal();
            }
            if (event.target === document.getElementById('unlockModal')) {
                closeUnlockModal();
            }
        }

        // Auto-close alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => alert.remove());
        }, 5000);
    </script>
</body>
    <?php include('../includes/footer.php'); ?>

</html>
<?php ob_end_flush(); ?>