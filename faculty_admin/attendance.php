<?php
/**
 * ATTENDANCE MANAGEMENT SYSTEM - FACULTY ADMIN VERSION
 * 
 * Fixed to work with attendance_correction table structure:
 * - Uses leave_id as primary key
 * - Uses start_date for correction_date
 * - Uses is_closed instead of is_executed
 * - Multiple campus support
 * - FIXED: Departments load immediately when campus is selected
 * - FIXED: Automatic teacher timeout works correctly
 * - FIXED: Teacher attendance recording without campus restriction
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

// ✅ PHPMailer Configuration
require_once(__DIR__ . '/../lib/PHPMailer-master/src/Exception.php');
require_once(__DIR__ . '/../lib/PHPMailer-master/src/SMTP.php');
require_once(__DIR__ . '/../lib/PHPMailer-master/src/PHPMailer.php');

// ✅ Access Control - Only Faculty Admin
if (!isset($_SESSION['user']) || strtolower($_SESSION['user']['role']) !== 'faculty_admin') {
    session_destroy();
    header("Location: ../login.php?error=unauthorized");
    exit;
}

// Get faculty ID from session
$faculty_id = $_SESSION['user']['linked_id'] ?? null;
if (!$faculty_id) {
    header("Location: ../login.php?error=invalid_account");
    exit;
}

// Get faculty details
$faculty_name = '';
$faculty_code = '';
$stmt = $pdo->prepare("SELECT faculty_name, faculty_code FROM faculties WHERE faculty_id = ?");
$stmt->execute([$faculty_id]);
$faculty_data = $stmt->fetch(PDO::FETCH_ASSOC);
$faculty_name = $faculty_data['faculty_name'] ?? 'Unknown Faculty';
$faculty_code = $faculty_data['faculty_code'] ?? '';

// Get all campuses for this faculty
$faculty_campuses = [];
$stmt = $pdo->prepare("
    SELECT c.campus_id, c.campus_name, c.campus_code 
    FROM campus c
    JOIN faculty_campus fc ON c.campus_id = fc.campus_id
    WHERE fc.faculty_id = ? AND c.status = 'active'
    ORDER BY c.campus_name
");
$stmt->execute([$faculty_id]);
$faculty_campuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
$campus_ids = array_column($faculty_campuses, 'campus_id');

date_default_timezone_set('Africa/Nairobi');
$message = ""; 
$type = "";

// ============================================
// TABLE CREATION FUNCTION
// ============================================

/* ================= CREATE TEACHER ATTENDANCE TABLE IF NOT EXISTS ================= */
function createTeacherAttendanceTable($pdo) {
    try {
        // Check if table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'teacher_attendance'")->fetch();
        
        if (!$tableCheck) {
            $sql = "CREATE TABLE teacher_attendance (
                id INT AUTO_INCREMENT PRIMARY KEY,
                teacher_id INT NOT NULL,
                academic_term_id INT,
                date DATE NOT NULL,
                time_in DATETIME,
                time_out DATETIME,
                minutes_worked INT,
                notes TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME,
                FOREIGN KEY (teacher_id) REFERENCES teachers(teacher_id) ON DELETE CASCADE,
                INDEX idx_teacher_date (teacher_id, date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
            
            $pdo->exec($sql);
            error_log("Teacher attendance table created successfully");
        }
        return true;
    } catch (Exception $e) {
        error_log("Error creating/checking teacher_attendance table: " . $e->getMessage());
        return false;
    }
}

// Create table if it doesn't exist
createTeacherAttendanceTable($pdo);

// ============================================
// CORE FUNCTIONS
// ============================================

/* ================= FIXED AUTOMATIC TEACHER TIMEOUT FUNCTION ================= */
function autoTimeoutTeachers($pdo, $faculty_id, $campus_ids) {
    date_default_timezone_set('Africa/Nairobi');
    $today = date('Y-m-d');
    $dayName = date('D', strtotime($today));
    $current_time = time();
    $autoTimeoutCount = 0;
    
    // Check if teacher_attendance table exists
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'teacher_attendance'")->fetch();
    if (!$tableCheck) {
        error_log("Teacher attendance table not found for auto-timeout");
        return 0;
    }
    
    // Get all teachers who have clocked in today but not clocked out
    // AND are in this faculty's campuses
    $teachers_query = "
        SELECT DISTINCT ta.teacher_id, ta.id as attendance_id, ta.time_in
        FROM teacher_attendance ta
        INNER JOIN timetable tt ON ta.teacher_id = tt.teacher_id
        INNER JOIN classes c ON tt.class_id = c.class_id
        WHERE ta.date = ?
        AND ta.time_in IS NOT NULL
        AND ta.time_out IS NULL
        AND c.faculty_id = ?
        AND c.campus_id IN (" . implode(',', array_fill(0, count($campus_ids), '?')) . ")
    ";
    
    $params = array_merge([$today, $faculty_id], $campus_ids);
    $stmt = $pdo->prepare($teachers_query);
    $stmt->execute($params);
    $active_teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("Auto-timeout: Found " . count($active_teachers) . " active teachers for faculty $faculty_id");
    
    foreach ($active_teachers as $teacher) {
        $teacher_id = $teacher['teacher_id'];
        $attendance_id = $teacher['attendance_id'];
        $time_in = strtotime($teacher['time_in']);
        
        // Get teacher's last class end time for today
        $last_class_query = "
            SELECT MAX(tt.end_time) as last_end_time
            FROM timetable tt
            INNER JOIN classes c ON tt.class_id = c.class_id
            WHERE tt.teacher_id = ?
            AND tt.day_of_week = ?
            AND c.faculty_id = ?
            AND c.campus_id IN (" . implode(',', array_fill(0, count($campus_ids), '?')) . ")
        ";
        $params = array_merge([$teacher_id, $dayName, $faculty_id], $campus_ids);
        $stmt = $pdo->prepare($last_class_query);
        $stmt->execute($params);
        $last_class = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$last_class || !$last_class['last_end_time']) {
            error_log("Teacher ID $teacher_id has no classes today, skipping auto-timeout");
            continue;
        }
        
        $last_end_time_str = $last_class['last_end_time'];
        $last_end_time = strtotime($today . ' ' . $last_end_time_str);
        
        // Calculate timeout threshold: 30 minutes after last class ends
        $timeout_threshold = $last_end_time + (30 * 60); // 30 minutes after
        
        // Check if teacher has marked attendance for all their classes today
        $classes_today_query = "
            SELECT COUNT(DISTINCT tt.timetable_id) as total_classes,
                   SUM(CASE WHEN a.attendance_id IS NOT NULL THEN 1 ELSE 0 END) as marked_classes
            FROM timetable tt
            LEFT JOIN attendance a ON tt.teacher_id = a.teacher_id 
                AND tt.class_id = a.class_id 
                AND tt.subject_id = a.subject_id 
                AND a.attendance_date = ?
            INNER JOIN classes c ON tt.class_id = c.class_id
            WHERE tt.teacher_id = ?
            AND tt.day_of_week = ?
            AND c.faculty_id = ?
            AND c.campus_id IN (" . implode(',', array_fill(0, count($campus_ids), '?')) . ")
        ";
        $params = array_merge([$today, $teacher_id, $dayName, $faculty_id], $campus_ids);
        $stmt = $pdo->prepare($classes_today_query);
        $stmt->execute($params);
        $class_stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $total_classes = (int)($class_stats['total_classes'] ?? 0);
        $marked_classes = (int)($class_stats['marked_classes'] ?? 0);
        
        // Determine if we should auto-timeout
        $should_timeout = false;
        $timeout_reason = "";
        
        // Condition 1: All classes are marked AND it's past the last class end time
        if ($total_classes > 0 && $marked_classes >= $total_classes && $current_time > $last_end_time) {
            $should_timeout = true;
            $timeout_reason = "All classes marked";
        }
        // Condition 2: It's past the timeout threshold (30 minutes after last class)
        elseif ($current_time > $timeout_threshold) {
            $should_timeout = true;
            $timeout_reason = "Timeout threshold reached";
        }
        
        if ($should_timeout) {
            // Calculate minutes worked
            $minutes_worked = round(($current_time - $time_in) / 60);
            
            // Cap at 8 hours (480 minutes)
            if ($minutes_worked > 480) {
                $minutes_worked = 480;
            }
            
            // Update teacher attendance with auto timeout
            $update_sql = "
                UPDATE teacher_attendance 
                SET time_out = NOW(),
                    minutes_worked = ?,
                    notes = CONCAT(IFNULL(notes, ''), ' | Auto-Out at ', ?, ' (', ?, ')'),
                    updated_at = NOW()
                WHERE id = ?
            ";
            $stmt = $pdo->prepare($update_sql);
            $stmt->execute([
                $minutes_worked,
                date('H:i:s'),
                $timeout_reason,
                $attendance_id
            ]);
            
            if ($stmt->rowCount() > 0) {
                $autoTimeoutCount++;
                error_log("Teacher ID $teacher_id auto-timed out at " . date('H:i:s') . " ($timeout_reason)");
            }
        }
    }
    
    return $autoTimeoutCount;
}

/* ================= EMAIL SENDING FUNCTION ================= */
function sendEmail($to, $subject, $message, $student_id, $message_type, $absence_count, $pdo) {
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        // Recipients
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($to);

        // Content
        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body    = $message;

        $mail->send();
        
        // Create email_logs table if not exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'email_logs'")->fetch();
        if (!$tableCheck) {
            $sql = "CREATE TABLE email_logs (
                log_id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT,
                recipient_email VARCHAR(255),
                subject VARCHAR(255),
                message TEXT,
                message_type VARCHAR(50),
                absence_count INT DEFAULT 0,
                sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                status VARCHAR(20) DEFAULT 'sent',
                error_message TEXT,
                FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
            $pdo->exec($sql);
        }
        
        // Log the email
        $stmt = $pdo->prepare("
            INSERT INTO email_logs 
            (student_id, recipient_email, subject, message, message_type, absence_count, sent_at, status)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), 'sent')
        ");
        $stmt->execute([$student_id, $to, $subject, $message, $message_type, $absence_count]);
        
        return true;
    } catch (Exception $e) {
        // Log the error
        $stmt = $pdo->prepare("
            INSERT INTO email_logs 
            (student_id, recipient_email, subject, message, message_type, absence_count, sent_at, status, error_message)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), 'failed', ?)
        ");
        $stmt->execute([$student_id, $to, $subject, $message, $message_type, $absence_count, $e->getMessage()]);
        
        return false;
    }
}

/* ================= DIRECT ABSENCE EMAIL ================= */
function sendDirectAbsenceEmail($student_id, $subject_name, $absence_count, $pdo) {
    // Get student info
    $stmt = $pdo->prepare("
        SELECT 
            s.full_name AS student_name,
            s.email AS student_email,
            p.email AS parent_email,
            p.full_name AS parent_name
        FROM students s
        LEFT JOIN parents p ON s.parent_id = p.parent_id
        WHERE s.student_id = ?
    ");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) return false;
    
    $student_name  = $student['student_name'];
    $student_email = $student['student_email'];
    $parent_email  = $student['parent_email'];
    $parent_name   = $student['parent_name'] ?? 'Waalid';
    
    // Prepare student message
    $student_subject = "Digniin Maqnaansho Maanta";

    $student_message = "
Mudane/Marwo $student_name,

Tani waa fariin rasmi ah oo ka timid Xafiiska Kuliyadaada.

Diiwaannadeenna Attendance System waxay muujinayaan inaad maanta maqantahay, waxaana tani tahay maqnaanshahaaga $absence_count ee maadada: $subject_name.

Fadlan ogow in haddii maqnaanshahaagu gaaro shan (5) jeer, lagaa joojin doono maadadan ($subject_name) waxaana ay noqon doontaa RECOURSE.

Waxaan ku dhiirigelinaynaa inaad ka soo qayb gasho casharrada haray si aad uga fogaato cawaaqib waxbarasho.

Haddii aad u maleyneyso in digniintani ay khalad tahay, fadlan si degdeg ah ula xiriir Xafiiska Arrimaha Tacliinta.

Mahadsanid,
Xafiiska Arrimaha Tacliinta
";

    // Prepare parent message
    $parent_subject = "Ardaygaagu waa Maqan Yahay $parent_name";

    $parent_message = "
Mudane/Marwo $parent_name,

Tani waa fariin rasmi ah oo ka timid Xafiiska Arrimaha Tacliinta ee jamacadda Hormuud.

Ardaygaaga $student_name maanta waa maqan yahay, waxaana maqnaanshihiisu hadda gaaray $absence_count jeer ee maadada: $subject_name.

Fadlan la soco ka soo qaybgalka casharrada si aad uga fogaato cawaaqib waxbarasho ee ku imaan karto ardaygaaga $student_name.

Mahadsanid,
Xafiiska Arrimaha Tacliinta
";
    
    $emails_sent = 0;
    
    // Send to student
    if (!empty($student_email) && filter_var($student_email, FILTER_VALIDATE_EMAIL)) {
        if (sendEmail($student_email, $student_subject, $student_message, $student_id, 'absence', $absence_count, $pdo)) {
            $emails_sent++;
        }
    }
    
    // Send to parent
    if (!empty($parent_email) && filter_var($parent_email, FILTER_VALIDATE_EMAIL)) {
        if (sendEmail($parent_email, $parent_subject, $parent_message, $student_id, 'absence', $absence_count, $pdo)) {
            $emails_sent++;
        }
    }
    
    return $emails_sent > 0;
}

/* ================= CUMULATIVE ABSENCE EMAIL (RECOURSE) ================= */
function sendCumulativeAbsenceEmail($student_id, $absence_count, $subject_name, $academic_term_id, $pdo) {
    try {
        // Get student details with corrected column aliases
        $stmt = $pdo->prepare("
            SELECT 
                s.full_name as student_name,
                s.email as student_email,
                p.email as parent_email,
                p.full_name as parent_name
            FROM students s
            LEFT JOIN parents p ON s.parent_id = p.parent_id
            WHERE s.student_id = ?
        ");
        $stmt->execute([$student_id]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student) return false;
        
        $student_name = $student['student_name'];
        $student_email = $student['student_email'];
        $parent_email = $student['parent_email'];
        $parent_name = $student['parent_name'] ?? 'Waalid';
        
        // For 5+ absences
        if ($absence_count >= 5) {
            // Check if recourse email already sent for this term and subject
            $check_stmt = $pdo->prepare("
                SELECT COUNT(*) as already_sent 
                FROM email_logs 
                WHERE student_id = ? 
                AND message_type = 'recourse'
                AND absence_count >= 5
                AND DATE(sent_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            ");
            $check_stmt->execute([$student_id]);
            $check = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($check['already_sent'] > 0) {
                return false; // Already sent recently
            }
            
            $email_student_subject = "Digniin Rebitaanka Maadada – $subject_name";
            $email_parent_subject = "Ogaaysiis Rebitaan Cashar – $subject_name";
            
            $email_student_message = "
Mudane/Marwo $student_name,

Tani waa fariin rasmi ah oo ka timid Xafiiska Arrimaha Tacliinta.

Waxaan ku ogeysiinaynaa in maqnaanshahaagu ee maadada $subject_name uu gaaray $absence_count jeer.
Sida ay qabo nidaamka xafiiska, haddii ardaygu gaaro 5 maqnaansho, waxaa la joojinayaa ka qayb-
galka maadadan.

Dhammaadka maadadan, waa inaad dib u qaadataa maadadan RECOURSE.
Haddii aad qabto su'aalo, fadlan la xiriir xafiiska arrimaha tacliinta.

Mahadsanid,
Xafiiska Arrimaha Tacliinta
";
            
            $email_parent_message = "
Mudane/Marwo $parent_name,

Waxaan ku ogeysiinaynaa in ardaygaaga $student_name uu gaaray $absence_count maqnaansho
ee maadada $subject_name.

Sida ay qabo nidaamka xafiiska, haddii ardaygu gaaro 5 maqnaansho, waxaa la joojinayaa ka qayb-
galka maadadan. Ardaygaagu maanta wuu joojinayaa ka qaybgalka maadadan, wuxuuna qaadanayaa
RECOURSE dhammaadka maadadan.

Fadlan noo soo xiriir haddii aad wax su'aalo qabto.

Mahadsanid,
Xafiiska Arrimaha Tacliinta
";
            
            $message_type = 'recourse';
        } else {
            return false;
        }
        
        $emails_sent = 0;
        
        // Send email to student
        if (!empty($student_email) && filter_var($student_email, FILTER_VALIDATE_EMAIL)) {
            if (sendEmail($student_email, $email_student_subject, $email_student_message, $student_id, $message_type, $absence_count, $pdo)) {
                $emails_sent++;
            }
        }
        
        // Send email to parent
        if (!empty($parent_email) && filter_var($parent_email, FILTER_VALIDATE_EMAIL)) {
            if (sendEmail($parent_email, $email_parent_subject, $email_parent_message, $student_id, $message_type, $absence_count, $pdo)) {
                $emails_sent++;
            }
        }
        
        return $emails_sent > 0;
        
    } catch (Exception $e) {
        error_log("Cumulative email error: " . $e->getMessage());
        return false;
    }
}

/* ================= ATTENDANCE CORRECTION FUNCTIONS ================= */
function requestAttendanceCorrection($student_id, $subject_id, $teacher_id, $academic_term_id, $requested_by, $reason, $reason_details, $correction_date, $original_status, $corrected_status, $pdo) {
    try {
        // Create attendance_correction table if not exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'attendance_correction'")->fetch();
        if (!$tableCheck) {
            $sql = "CREATE TABLE attendance_correction (
                leave_id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                subject_id INT NOT NULL,
                teacher_id INT NOT NULL,
                academic_term_id INT,
                requested_by INT NOT NULL,
                reason VARCHAR(255),
                reason_details TEXT,
                start_date DATE,
                days_count INT DEFAULT 1,
                status VARCHAR(20) DEFAULT 'pending',
                is_closed TINYINT(1) DEFAULT 0,
                approved_by INT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME,
                FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
                FOREIGN KEY (subject_id) REFERENCES subject(subject_id) ON DELETE CASCADE,
                FOREIGN KEY (teacher_id) REFERENCES teachers(teacher_id) ON DELETE CASCADE,
                FOREIGN KEY (academic_term_id) REFERENCES academic_term(academic_term_id) ON DELETE SET NULL,
                FOREIGN KEY (requested_by) REFERENCES users(user_id) ON DELETE CASCADE,
                FOREIGN KEY (approved_by) REFERENCES users(user_id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
            $pdo->exec($sql);
        }
        
        // Store original and corrected status in reason_details
        $full_reason_details = "original_status:$original_status, corrected_status:$corrected_status, details:" . $reason_details;
        
        $stmt = $pdo->prepare("
            INSERT INTO attendance_correction 
            (student_id, subject_id, teacher_id, academic_term_id, requested_by, 
             reason, reason_details, start_date, days_count, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 'pending', NOW())
        ");
        
        $stmt->execute([
            $student_id, $subject_id, $teacher_id, $academic_term_id, $requested_by,
            $reason, $full_reason_details, $correction_date
        ]);
        
        return $pdo->lastInsertId();
    } catch (Exception $e) {
        error_log("Correction request error: " . $e->getMessage());
        return false;
    }
}

function approveAttendanceCorrection($leave_id, $approved_by, $pdo) {
    try {
        $pdo->beginTransaction();
        
        // Get correction details
        $stmt = $pdo->prepare("
            SELECT ac.*, s.full_name as student_name
            FROM attendance_correction ac
            JOIN students s ON ac.student_id = s.student_id
            WHERE ac.leave_id = ? AND ac.status = 'pending'
        ");
        $stmt->execute([$leave_id]);
        $correction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$correction) {
            throw new Exception("Correction request not found or already processed");
        }
        
        // Parse original and corrected status from reason_details
        $reason_details = $correction['reason_details'] ?? '';
        $original_status = 'absent';
        $corrected_status = 'present';
        
        // Extract status from reason_details
        if (preg_match('/original_status:(\w+)/', $reason_details, $original_match)) {
            $original_status = $original_match[1];
        }
        if (preg_match('/corrected_status:(\w+)/', $reason_details, $corrected_match)) {
            $corrected_status = $corrected_match[1];
        }
        
        // Update attendance record
        $updateStmt = $pdo->prepare("
            UPDATE attendance 
            SET status = ?, updated_at = NOW()
            WHERE student_id = ? 
            AND subject_id = ? 
            AND attendance_date = ?
            AND academic_term_id = ?
        ");
        
        $updateStmt->execute([
            $corrected_status,
            $correction['student_id'],
            $correction['subject_id'],
            $correction['start_date'],
            $correction['academic_term_id']
        ]);
        
        if ($updateStmt->rowCount() === 0) {
            throw new Exception("Attendance record not found");
        }
        
        // Update correction status
        $updateCorrection = $pdo->prepare("
            UPDATE attendance_correction 
            SET status = 'approved', 
                approved_by = ?, 
                is_closed = 1,
                updated_at = NOW()
            WHERE leave_id = ?
        ");
        
        $updateCorrection->execute([$approved_by, $leave_id]);
        
        // If changing from absent to present, recalculate absence count
        if ($original_status === 'absent' && $corrected_status === 'present') {
            // Get updated absence count
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
                $correction['academic_term_id']
            ]);
            
            $count_result = $count_stmt->fetch(PDO::FETCH_ASSOC);
            $new_absence_count = $count_result['absence_count'];
            
            // Check if student should be removed from recourse
            if ($new_absence_count < 5) {
                // Create recourse_student table if not exists
                $tableCheck = $pdo->query("SHOW TABLES LIKE 'recourse_student'")->fetch();
                if ($tableCheck) {
                    // Remove from recourse if exists
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
                        $correction['academic_term_id']
                    ]);
                }
            }
        }
        
        $pdo->commit();
        
        // Send notification to student
        sendCorrectionApprovalNotification($correction, $original_status, $corrected_status, $pdo);
        
        return [
            'success' => true,
            'student_name' => $correction['student_name'],
            'original_status' => $original_status,
            'corrected_status' => $corrected_status,
            'correction_date' => $correction['start_date']
        ];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Approval error: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

function sendCorrectionApprovalNotification($correction, $original_status, $corrected_status, $pdo) {
    // Get student email
    $stmt = $pdo->prepare("
        SELECT email FROM students WHERE student_id = ?
    ");
    $stmt->execute([$correction['student_id']]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($student && !empty($student['email'])) {
        $subject = "Attendance Correction Approved";
        $message = "Dear Student,\n\n";
        $message .= "Your attendance correction request has been approved.\n";
        $message .= "Date: " . $correction['start_date'] . "\n";
        $message .= "Original Status: " . ucfirst($original_status) . "\n";
        $message .= "Corrected Status: " . ucfirst($corrected_status) . "\n\n";
        $message .= "Your attendance record has been updated accordingly.\n\n";
        $message .= "Regards,\nAcademic Affairs Department";
        
        // Use existing sendEmail function
        sendEmail(
            $student['email'],
            $subject,
            $message,
            $correction['student_id'],
            'correction_approved',
            0,
            $pdo
        );
    }
}

function rejectAttendanceCorrection($leave_id, $rejected_by, $rejection_reason, $pdo) {
    try {
        $stmt = $pdo->prepare("
            UPDATE attendance_correction 
            SET status = 'rejected', 
                approved_by = ?,
                is_closed = 1,
                reason_details = CONCAT(COALESCE(reason_details, ''), ' | Rejection Reason: ', ?),
                updated_at = NOW()
            WHERE leave_id = ? AND status = 'pending'
        ");
        
        $stmt->execute([$rejected_by, $rejection_reason, $leave_id]);
        
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        error_log("Rejection error: " . $e->getMessage());
        return false;
    }
}

// ============================================
// MAIN EXECUTION
// ============================================

// Run auto timeout on page load - FIXED FUNCTION CALL
$autoTimeoutCount = autoTimeoutTeachers($pdo, $faculty_id, $campus_ids);

/* ================= ACTIVE TERM ================= */
$term = $pdo->query("SELECT academic_term_id FROM academic_term WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$academic_term_id = $term['academic_term_id'] ?? null;
if (!$academic_term_id) {
    $message = "❌ Error: No active academic term found!";
    $type = "error";
}

// ===========================================
// AJAX HANDLERS FOR HIERARCHY
// ===========================================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    // GET FACULTIES BY CAMPUS (for faculty admin, only return current faculty)
    if ($_GET['ajax'] == 'get_faculties_by_campus') {
        $campus_id = $_GET['campus_id'] ?? 0;
        
        // Check if campus belongs to this faculty
        if (!in_array($campus_id, $campus_ids)) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied to this campus']);
            exit;
        }
        
        // For faculty admin, only return the current faculty
        $faculties = [
            ['faculty_id' => $faculty_id, 'faculty_name' => $faculty_name]
        ];
        
        echo json_encode(['status' => 'success', 'faculties' => $faculties]);
        exit;
    }
    
    // GET DEPARTMENTS BY FACULTY & CAMPUS (with campus name)
    if ($_GET['ajax'] == 'get_departments_by_faculty') {
        $faculty_id_param = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        // Check if campus belongs to this faculty
        if (!in_array($campus_id, $campus_ids)) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied to this campus']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT department_id, department_name, campus_id
            FROM departments 
            WHERE faculty_id = ? 
            AND campus_id = ?
            AND status = 'active'
            ORDER BY department_name
        ");
        $stmt->execute([$faculty_id, $campus_id]);
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add campus name to each department for display
        foreach ($departments as &$dept) {
            $campus_stmt = $pdo->prepare("SELECT campus_name FROM campus WHERE campus_id = ?");
            $campus_stmt->execute([$dept['campus_id']]);
            $dept['campus_name'] = $campus_stmt->fetchColumn();
            $dept['display_name'] = $dept['department_name'] . ' (' . $dept['campus_name'] . ')';
        }
        
        echo json_encode(['status' => 'success', 'departments' => $departments]);
        exit;
    }
    
    // GET PROGRAMS BY DEPARTMENT, FACULTY & CAMPUS
    if ($_GET['ajax'] == 'get_programs_by_department') {
        $department_id = $_GET['department_id'] ?? 0;
        $faculty_id_param = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        // Check if campus belongs to this faculty
        if (!in_array($campus_id, $campus_ids)) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied to this campus']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT program_id, program_name, program_code
            FROM programs 
            WHERE department_id = ? 
            AND faculty_id = ?
            AND campus_id = ?
            AND status = 'active'
            ORDER BY program_name
        ");
        $stmt->execute([$department_id, $faculty_id, $campus_id]);
        $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'programs' => $programs]);
        exit;
    }
    
    // GET CLASSES BY PROGRAM, DEPARTMENT, FACULTY & CAMPUS (with study mode)
    if ($_GET['ajax'] == 'get_classes_by_program') {
        $program_id = $_GET['program_id'] ?? 0;
        $department_id = $_GET['department_id'] ?? 0;
        $faculty_id_param = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        // Check if campus belongs to this faculty
        if (!in_array($campus_id, $campus_ids)) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied to this campus']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT class_id, class_name, study_mode 
            FROM classes 
            WHERE program_id = ? 
            AND department_id = ?
            AND faculty_id = ?
            AND campus_id = ?
            AND status = 'Active'
            ORDER BY class_name
        ");
        $stmt->execute([$program_id, $department_id, $faculty_id, $campus_id]);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format class names with study mode
        $formatted_classes = [];
        foreach ($classes as $class) {
            $formatted_classes[] = [
                'class_id' => $class['class_id'],
                'class_name' => $class['class_name'],
                'study_mode' => $class['study_mode'],
                'display_name' => $class['class_name'] . ' (' . $class['study_mode'] . ')'
            ];
        }
        
        echo json_encode(['status' => 'success', 'classes' => $formatted_classes]);
        exit;
    }
    
    // GET STUDENTS BY CLASS AND CAMPUS (INCLUDING RECOURSE STUDENTS)
    if ($_GET['ajax'] == 'get_students_by_class') {
        $class_id = $_GET['class_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        // Check if campus belongs to this faculty
        if (!in_array($campus_id, $campus_ids)) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied to this campus']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            (SELECT 
                s.student_id, 
                s.full_name, 
                s.reg_no,
                s.email as student_email,
                s.phone_number as student_phone,
                p.email as parent_email,
                p.phone as parent_phone,
                se.semester_id,
                sem.semester_name,
                'regular' as student_type
            FROM students s
            JOIN student_enroll se ON se.student_id = s.student_id
            LEFT JOIN semester sem ON sem.semester_id = se.semester_id
            LEFT JOIN parents p ON s.parent_id = p.parent_id
            WHERE se.class_id = ? 
            AND se.campus_id = ?
            AND s.status = 'active')
            
            UNION
            
            (SELECT 
                rs.student_id, 
                s.full_name, 
                s.reg_no,
                s.email as student_email,
                s.phone_number as student_phone,
                p.email as parent_email,
                p.phone as parent_phone,
                rs.recourse_semester_id as semester_id,
                sem.semester_name,
                'recourse' as student_type
            FROM recourse_student rs
            JOIN students s ON rs.student_id = s.student_id
            LEFT JOIN semester sem ON sem.semester_id = rs.recourse_semester_id
            LEFT JOIN parents p ON s.parent_id = p.parent_id
            WHERE rs.recourse_class_id = ? 
            AND rs.recourse_campus_id = ?
            AND rs.status = 'active'
            AND (rs.academic_term_id = ? OR rs.academic_term_id IS NULL))
            
            ORDER BY full_name
        ");
        $stmt->execute([$class_id, $campus_id, $class_id, $campus_id, $academic_term_id]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'students' => $students]);
        exit;
    }
    
    // AJAX for attendance correction
    if ($_GET['ajax'] == 'search_students') {
        $query = $_GET['query'] ?? '';
        
        // Only show students from this faculty's campuses
        $stmt = $pdo->prepare("
            SELECT s.student_id, s.full_name, s.reg_no, s.email
            FROM students s
            JOIN student_enroll se ON s.student_id = se.student_id
            WHERE (s.reg_no LIKE ? OR s.full_name LIKE ?)
            AND se.faculty_id = ?
            AND se.campus_id IN (" . implode(',', array_fill(0, count($campus_ids), '?')) . ")
            AND s.status = 'active'
            ORDER BY s.full_name
            LIMIT 10
        ");
        $searchTerm = "%$query%";
        $params = array_merge([$searchTerm, $searchTerm, $faculty_id], $campus_ids);
        $stmt->execute($params);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'students' => $students]);
        exit;
    }
    
    // AJAX for getting student attendance
    if ($_GET['ajax'] == 'get_student_attendance') {
        $student_id = $_GET['student_id'] ?? 0;
        $date = $_GET['date'] ?? date('Y-m-d');
        
        $stmt = $pdo->prepare("
            SELECT 
                a.attendance_id,
                a.attendance_date,
                a.status,
                s.subject_name,
                s.subject_id,
                t.teacher_name,
                c.class_name
            FROM attendance a
            LEFT JOIN subject s ON a.subject_id = s.subject_id
            LEFT JOIN teachers t ON a.teacher_id = t.teacher_id
            LEFT JOIN classes c ON a.class_id = c.class_id
            WHERE a.student_id = ? 
            AND a.attendance_date = ?
            ORDER BY a.attendance_date DESC
        ");
        $stmt->execute([$student_id, $date]);
        $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'attendance' => $attendance]);
        exit;
    }
    
    // AJAX for approving correction
    if ($_GET['ajax'] == 'approve_correction') {
        $leave_id = $_GET['correction_id'] ?? 0;
        $approved_by = $_SESSION['user']['user_id'] ?? 0;
        
        $result = approveAttendanceCorrection($leave_id, $approved_by, $pdo);
        
        echo json_encode($result);
        exit;
    }
    
    // AJAX for rejecting correction
    if ($_GET['ajax'] == 'reject_correction') {
        $leave_id = $_GET['correction_id'] ?? 0;
        $rejected_by = $_SESSION['user']['user_id'] ?? 0;
        $rejection_reason = $_GET['reason'] ?? 'No reason provided';
        
        $result = rejectAttendanceCorrection($leave_id, $rejected_by, $rejection_reason, $pdo);
        
        echo json_encode(['success' => $result]);
        exit;
    }
}

/* ================= HANDLE ACTIONS ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $pdo->beginTransaction();
        $action = $_POST['action'];

        // ✅ Input validation
        $teacher_id = intval($_POST['teacher_id'] ?? 0);
        $class_id   = intval($_POST['class_id'] ?? 0);
        $campus_id  = intval($_POST['campus_id'] ?? 0);
        $date       = trim($_POST['date'] ?? '');
        
        if (!$teacher_id || !$date) throw new Exception("Missing required input fields!");
        if (!$academic_term_id) throw new Exception("No active academic term found!");

        /* ============================================
           🧑‍🎓 STUDENT ATTENDANCE HANDLING
           ============================================ */
        if ($action === 'student_save' || $action === 'student_unlock') {
            
            // Verify campus belongs to this faculty for student attendance
            if (!in_array($campus_id, $campus_ids)) {
                throw new Exception("Unauthorized access to this campus!");
            }
            
            $attendance = $_POST['attendance'] ?? [];
            $day = date('D', strtotime($date));

            // ✅ Fetch timetable record
            $q = $pdo->prepare("
                SELECT subject_id, start_time, end_time 
                FROM timetable 
                WHERE class_id=? 
                AND teacher_id=? 
                AND day_of_week=? 
                LIMIT 1
            ");
            $q->execute([$class_id, $teacher_id, $day]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            
            if (!$row) throw new Exception("No timetable found for this teacher/class on $day");
            $subject_id = $row['subject_id'];
            
            // Get subject name
            $subject_stmt = $pdo->prepare("SELECT subject_name FROM subject WHERE subject_id = ?");
            $subject_stmt->execute([$subject_id]);
            $subject = $subject_stmt->fetch(PDO::FETCH_ASSOC);
            $subject_name = $subject['subject_name'] ?? 'Unknown Subject';

            // 🚫 Prevent past unlock/save
            if (strtotime($date) < strtotime(date('Y-m-d')))
                throw new Exception("You cannot modify attendance for past dates.");

            // ✅ Check teacher Time IN
            $checkTeacherIn = $pdo->prepare("SELECT time_in FROM teacher_attendance WHERE teacher_id=? AND date=?");
            $checkTeacherIn->execute([$teacher_id, $date]);
            $teacherAttendance = $checkTeacherIn->fetch(PDO::FETCH_ASSOC);
            if (!$teacherAttendance || empty($teacherAttendance['time_in']))
                throw new Exception("Teacher must record Time IN before marking student attendance!");

            // 🔓 Unlock attendance
            if ($action === 'student_unlock') {
                $pdo->prepare("DELETE FROM attendance WHERE class_id=? AND teacher_id=? AND subject_id=? AND attendance_date=?")
                    ->execute([$class_id, $teacher_id, $subject_id, $date]);
                $message = "🔓 Student attendance unlocked successfully!";
            } else {
                // 💾 Save attendance
                foreach ($attendance as $student_id => $status) {
                    // Check if attendance already exists
                    $check = $pdo->prepare("SELECT attendance_id, status FROM attendance WHERE student_id=? AND attendance_date=? AND subject_id=?");
                    $check->execute([$student_id, $date, $subject_id]);
                    $existing = $check->fetch();
                    
                    if ($existing) {
                        // Update existing record
                        $update = $pdo->prepare("
                            UPDATE attendance 
                            SET status = ?, updated_at = NOW()
                            WHERE attendance_id = ?
                        ");
                        $update->execute([$status, $existing['attendance_id']]);
                        
                        // If changing from present to absent, send email
                        if ($existing['status'] !== 'absent' && $status === 'absent') {
                            // Get the new absence count
                            $count_stmt = $pdo->prepare("
                                SELECT COUNT(*) as absence_count 
                                FROM attendance 
                                WHERE student_id = ? 
                                AND subject_id = ? 
                                AND academic_term_id = ? 
                                AND status = 'absent'
                            ");
                            $count_stmt->execute([$student_id, $subject_id, $academic_term_id]);
                            $count_result = $count_stmt->fetch(PDO::FETCH_ASSOC);
                            $absence_count = $count_result['absence_count'];
                            
                            sendDirectAbsenceEmail($student_id, $subject_name, $absence_count, $pdo);
                            
                            if ($absence_count >= 5) {
                                sendCumulativeAbsenceEmail($student_id, $absence_count, $subject_name, $academic_term_id, $pdo);
                            }
                        }
                    } else {
                        // Insert new record
                        $insert = $pdo->prepare("
                            INSERT INTO attendance 
                            (student_id, class_id, teacher_id, subject_id, academic_term_id, attendance_date, status, created_at)
                            VALUES (?,?,?,?,?,?,?,NOW())
                        ");
                        $insert->execute([$student_id, $class_id, $teacher_id, $subject_id, $academic_term_id, $date, $status]);
                        
                        // ✅ IMMEDIATE EMAIL FOR ABSENCES
                        if ($status === 'absent') {
                            // Get the new absence count
                            $count_stmt = $pdo->prepare("
                                SELECT COUNT(*) as absence_count 
                                FROM attendance 
                                WHERE student_id = ? 
                                AND subject_id = ? 
                                AND academic_term_id = ? 
                                AND status = 'absent'
                            ");
                            $count_stmt->execute([$student_id, $subject_id, $academic_term_id]);
                            $count_result = $count_stmt->fetch(PDO::FETCH_ASSOC);
                            $absence_count = $count_result['absence_count'];
                            
                            sendDirectAbsenceEmail($student_id, $subject_name, $absence_count, $pdo);
                            
                            if ($absence_count >= 5) {
                                sendCumulativeAbsenceEmail($student_id, $absence_count, $subject_name, $academic_term_id, $pdo);
                            }
                        }
                    }
                }
                $message = "✅ Student attendance saved successfully!";
            }
            $type = "success";
        }

        /* ============================================
           👨‍🏫 TEACHER ATTENDANCE HANDLING - FIXED: NO CAMPUS VERIFICATION
           ============================================ */
        if ($action === 'teacher_save' || $action === 'teacher_unlock') {
            $io_action = $_POST['io_action'] ?? '';
            $note = trim($_POST['notes'] ?? '');
            $day = date('D', strtotime($date));
            
            // FIXED: For teacher attendance, we don't need to verify campus
            // Teachers can work across multiple campuses, so we skip campus verification
            // Just verify the teacher exists and is active
            
            $tcheck = $pdo->prepare("
                SELECT MIN(start_time) AS start_time, MAX(end_time) AS end_time 
                FROM timetable 
                WHERE teacher_id=? AND day_of_week=?
            ");
            $tcheck->execute([$teacher_id, $day]);
            $timetable = $tcheck->fetch(PDO::FETCH_ASSOC);
            if (!$timetable || !$timetable['start_time']) throw new Exception("No timetable found for this teacher today.");

            $start = strtotime($timetable['start_time']) - 600; // 10 minutes before
            $end = strtotime($timetable['end_time']) + 600; // 10 minutes after
            $now = time();
            
            if ($action === 'teacher_save') {
                if ($now < $start || $now > $end) {
                    throw new Exception("You can only record attendance 10 minutes before/after class hours.");
                }
            }
            
            if (strtotime($date) < strtotime(date('Y-m-d'))) throw new Exception("You cannot record or unlock attendance for past days.");

            $check = $pdo->prepare("SELECT id, time_in, time_out FROM teacher_attendance WHERE teacher_id=? AND date=?");
            $check->execute([$teacher_id, $date]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);

            if ($action === 'teacher_unlock') {
                if (!$existing) throw new Exception("No attendance record found to unlock.");
                if ($io_action === 'In') {
                    $pdo->prepare("DELETE FROM teacher_attendance WHERE teacher_id=? AND date=?")->execute([$teacher_id, $date]);
                    $pdo->prepare("DELETE FROM attendance WHERE teacher_id=? AND attendance_date=?")->execute([$teacher_id, $date]);
                    $message = "🔓 All attendance data unlocked successfully!";
                } elseif ($io_action === 'Out') {
                    $pdo->prepare("UPDATE teacher_attendance SET time_out=NULL, minutes_worked=NULL, notes=NULL, updated_at=NOW() WHERE teacher_id=? AND date=?")
                        ->execute([$teacher_id, $date]);
                    $message = "🔓 Time OUT unlocked successfully!";
                } else throw new Exception("Select valid unlock action (In/Out)!");
            } else {
                if ($io_action === 'In') {
                    if ($existing && $existing['time_in']) throw new Exception("Time IN already recorded!");
                    if ($existing) {
                        $pdo->prepare("UPDATE teacher_attendance SET time_in=?, notes=?, updated_at=NOW() WHERE teacher_id=? AND date=?")
                            ->execute([date('Y-m-d H:i:s'), $note, $teacher_id, $date]);
                    } else {
                        $pdo->prepare("
                            INSERT INTO teacher_attendance 
                            (teacher_id, academic_term_id, date, time_in, notes, created_at)
                            VALUES (?,?,?,?,?,NOW())
                        ")->execute([$teacher_id, $academic_term_id, $date, date('Y-m-d H:i:s'), $note]);
                    }
                    $message = "✅ Teacher Time IN recorded successfully!";
                } elseif ($io_action === 'Out') {
                    if (!$existing || !$existing['time_in']) throw new Exception("Time IN not found! Please record IN first.");
                    if ($existing['time_out']) throw new Exception("Time OUT already recorded!");
                    $time_in = strtotime($existing['time_in']);
                    $time_out = time();
                    $minutes = round(($time_out - $time_in) / 60);
                    $pdo->prepare("
                        UPDATE teacher_attendance 
                        SET time_out=?, minutes_worked=?, notes=?, updated_at=NOW()
                        WHERE teacher_id=? AND date=?
                    ")->execute([date('Y-m-d H:i:s'), $minutes, $note, $teacher_id, $date]);
                    $message = "✅ Teacher Time OUT recorded! Worked $minutes minutes.";
                } else throw new Exception("Select valid In/Out action!");
            }
            $type = "success";
        }
        
        /* ============================================
           📝 ATTENDANCE CORRECTION REQUEST
           ============================================ */
        if ($action === 'request_correction') {
            $student_id = intval($_POST['student_id'] ?? 0);
            $subject_id = intval($_POST['subject_id'] ?? 0);
            $reason = $_POST['reason'] ?? '';
            $reason_details = trim($_POST['reason_details'] ?? '');
            $correction_date = $_POST['correction_date'] ?? '';
            $original_status = $_POST['original_status'] ?? '';
            $corrected_status = $_POST['corrected_status'] ?? '';
            $requested_by = $_SESSION['user']['user_id'] ?? 0;
            
            if (!$student_id || !$reason || !$correction_date || !$original_status || !$corrected_status) {
                throw new Exception("All required fields must be filled!");
            }
            
            // Verify student is in this faculty
            $checkStudent = $pdo->prepare("
                SELECT se.campus_id 
                FROM student_enroll se
                WHERE se.student_id = ?
                AND se.faculty_id = ?
                AND se.campus_id IN (" . implode(',', array_fill(0, count($campus_ids), '?')) . ")
                LIMIT 1
            ");
            $params = array_merge([$student_id, $faculty_id], $campus_ids);
            $checkStudent->execute($params);
            $studentCampus = $checkStudent->fetch(PDO::FETCH_ASSOC);
            
            if (!$studentCampus) {
                throw new Exception("Student is not in your authorized campuses!");
            }
            
            // Get teacher_id from attendance record
            $teacherStmt = $pdo->prepare("
                SELECT teacher_id FROM attendance 
                WHERE student_id = ? 
                AND subject_id = ? 
                AND attendance_date = ?
                LIMIT 1
            ");
            $teacherStmt->execute([$student_id, $subject_id, $correction_date]);
            $attendance = $teacherStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$attendance) {
                throw new Exception("Attendance record not found!");
            }
            
            $teacher_id = $attendance['teacher_id'];
            
            // Create correction request
            $correction_id = requestAttendanceCorrection(
                $student_id, $subject_id, $teacher_id, $academic_term_id, 
                $requested_by, $reason, $reason_details, $correction_date, 
                $original_status, $corrected_status, $pdo
            );
            
            if ($correction_id) {
                $message = "✅ Correction request submitted successfully! It will be reviewed by admin.";
                $type = "success";
            } else {
                throw new Exception("Failed to submit correction request.");
            }
        }

        $pdo->commit();

    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "❌ Error: " . $e->getMessage();
        $type = "error";
    }
}

// ============================================
// DATA FETCHING FOR DISPLAY
// ============================================

// ✅ Teachers - Filter teachers by faculty through timetable
$stmt = $pdo->prepare("
    SELECT DISTINCT t.teacher_id, t.teacher_name
    FROM teachers t
    INNER JOIN timetable tt ON t.teacher_id = tt.teacher_id
    INNER JOIN classes c ON tt.class_id = c.class_id
    WHERE t.status = 'active'
    AND c.faculty_id = ?
    AND c.campus_id IN (" . implode(',', array_fill(0, count($campus_ids), '?')) . ")
    ORDER BY t.teacher_name ASC
");
$params = array_merge([$faculty_id], $campus_ids);
$stmt->execute($params);
$teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ Campuses - Only campuses belonging to this faculty
$campuses = $faculty_campuses;

// Get filter values from POST
$selected_campus = $_POST['campus_id'] ?? null;
$selected_faculty = $faculty_id;
$selected_department = $_POST['department_id'] ?? null;
$selected_program = $_POST['program_id'] ?? null;
$selected_class = $_POST['class_id'] ?? null;

// ✅ Cascade filters - THESE WILL BE POPULATED BY AJAX, NOT BY POST
$faculties = $departments = $programs = $classes = [];

// If campus selected, load faculties (but for faculty admin, only one faculty)
if (!empty($selected_campus) && in_array($selected_campus, $campus_ids)) {
    $faculties = [
        ['faculty_id' => $faculty_id, 'faculty_name' => $faculty_name]
    ];
}

/* 👩‍🎓 Load Students if timetable exists */
$students = [];

if (isset($_POST['load_students'])) {
    $class_id   = intval($_POST['class_id'] ?? 0);
    $teacher_id = intval($_POST['teacher_id'] ?? 0);
    $campus_id  = intval($_POST['campus_id'] ?? 0);
    $date       = $_POST['date'] ?? date('Y-m-d');
    $day        = date('D', strtotime($date));
    
    // Verify campus belongs to this faculty
    if (in_array($campus_id, $campus_ids)) {
        // Check if teacher has class on this day
        $chk = $pdo->prepare("
            SELECT subject_id
            FROM timetable
            WHERE class_id = ?
              AND teacher_id = ?
              AND day_of_week = ?
        ");
        $chk->execute([$class_id, $teacher_id, $day]);

        if ($chk->fetch()) {

            $stmt = $pdo->prepare("
                (SELECT 
                    s.student_id,
                    s.full_name,
                    s.reg_no,
                    s.email AS student_email,
                    s.phone_number AS student_phone,
                    p.email AS parent_email,
                    p.phone AS parent_phone,
                    se.semester_id,
                    sem.semester_name,
                    a.status AS attendance_status,
                    'regular' AS student_type
                FROM students s
                JOIN student_enroll se ON se.student_id = s.student_id
                LEFT JOIN semester sem ON sem.semester_id = se.semester_id
                LEFT JOIN parents p ON s.parent_id = p.parent_id
                LEFT JOIN attendance a 
                    ON a.student_id = s.student_id
                   AND a.attendance_date = ?
                   AND a.class_id = ?
                WHERE se.class_id = ?
                  AND se.campus_id = ?
                  AND s.status = 'active')

                UNION

                (SELECT 
                    rs.student_id,
                    s.full_name,
                    s.reg_no,
                    s.email AS student_email,
                    s.phone_number AS student_phone,
                    p.email AS parent_email,
                    p.phone AS parent_phone,
                    rs.recourse_semester_id AS semester_id,
                    sem.semester_name,
                    a.status AS attendance_status,
                    'recourse' AS student_type
                FROM recourse_student rs
                JOIN students s ON rs.student_id = s.student_id
                LEFT JOIN semester sem ON sem.semester_id = rs.recourse_semester_id
                LEFT JOIN parents p ON s.parent_id = p.parent_id
                LEFT JOIN attendance a 
                    ON a.student_id = rs.student_id
                   AND a.attendance_date = ?
                   AND a.class_id = rs.recourse_class_id
                WHERE rs.recourse_class_id = ?
                  AND rs.recourse_campus_id = ?
                  AND rs.status = 'active'
                  AND (rs.academic_term_id = ? OR rs.academic_term_id IS NULL))

                ORDER BY full_name
            ");

            // 8 placeholders
            $stmt->execute([
                // regular students
                $date,
                $class_id,
                $class_id,
                $campus_id,

                // recourse students
                $date,
                $class_id,
                $campus_id,
                $academic_term_id
            ]);

            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

// ✅ Get Email Logs - Only show logs for this faculty's students
try {
    // Create email_logs table if not exists
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'email_logs'")->fetch();
    if (!$tableCheck) {
        $sql = "CREATE TABLE email_logs (
            log_id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT,
            recipient_email VARCHAR(255),
            subject VARCHAR(255),
            message TEXT,
            message_type VARCHAR(50),
            absence_count INT DEFAULT 0,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            status VARCHAR(20) DEFAULT 'sent',
            error_message TEXT,
            FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $pdo->exec($sql);
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            el.log_id,
            el.student_id,
            s.full_name,
            el.recipient_email,
            el.subject,
            el.message_type,
            el.absence_count,
            el.sent_at,
            el.status,
            el.error_message
        FROM email_logs el
        LEFT JOIN students s ON s.student_id = el.student_id
        LEFT JOIN student_enroll se ON s.student_id = se.student_id
        WHERE se.faculty_id = ?
        AND se.campus_id IN (" . implode(',', array_fill(0, count($campus_ids), '?')) . ")
        ORDER BY el.sent_at DESC
        LIMIT 50
    ");
    $params = array_merge([$faculty_id], $campus_ids);
    $stmt->execute($params);
    $email_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $email_logs = [];
}

// ✅ Attendance Views (INCLUDING RECOURSE STUDENTS) - Filter by faculty
$stmt = $pdo->prepare("
    SELECT 
        a.attendance_date, 
        s.full_name, 
        s.reg_no, 
        s.email as student_email,
        s.phone_number as student_phone,
        p.email as parent_email,
        p.phone as parent_phone,
        c.class_name, 
        t.teacher_name, 
        a.status,
        sub.subject_name,
        CASE 
            WHEN EXISTS (
                SELECT 1 FROM recourse_student rs 
                WHERE rs.student_id = a.student_id 
                AND rs.subject_id = a.subject_id 
                AND (rs.academic_term_id = a.academic_term_id OR rs.academic_term_id IS NULL)
                AND rs.status = 'active'
            ) THEN 'Recourse'
            ELSE 'Regular'
        END as student_type
    FROM attendance a
    JOIN students s ON s.student_id = a.student_id
    JOIN classes c ON c.class_id = a.class_id
    JOIN teachers t ON t.teacher_id = a.teacher_id
    LEFT JOIN subject sub ON sub.subject_id = a.subject_id
    LEFT JOIN parents p ON s.parent_id = p.parent_id
    JOIN student_enroll se ON s.student_id = se.student_id
    WHERE se.faculty_id = ?
    AND se.campus_id IN (" . implode(',', array_fill(0, count($campus_ids), '?')) . ")
    ORDER BY a.attendance_date DESC
    LIMIT 50
");
$params = array_merge([$faculty_id], $campus_ids);
$stmt->execute($params);
$attendance_view = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ Recourse Students Status Check - Mark completed recourse students
// First create recourse_student table if not exists
$tableCheck = $pdo->query("SHOW TABLES LIKE 'recourse_student'")->fetch();
if (!$tableCheck) {
    $sql = "CREATE TABLE recourse_student (
        recourse_id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        subject_id INT NOT NULL,
        academic_term_id INT,
        original_campus_id INT,
        original_faculty_id INT,
        original_department_id INT,
        original_program_id INT,
        original_class_id INT,
        recourse_campus_id INT,
        recourse_faculty_id INT,
        recourse_department_id INT,
        recourse_program_id INT,
        recourse_class_id INT,
        recourse_semester_id INT,
        status VARCHAR(20) DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME,
        FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
        FOREIGN KEY (subject_id) REFERENCES subject(subject_id) ON DELETE CASCADE,
        FOREIGN KEY (academic_term_id) REFERENCES academic_term(academic_term_id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sql);
}

// Mark completed recourse students
if ($academic_term_id) {
    $completed_recourse = $pdo->prepare("
        UPDATE recourse_student rs
        SET rs.status = 'completed'
        WHERE rs.academic_term_id < ? 
        AND rs.status = 'active'
    ");
    $completed_recourse->execute([$academic_term_id]);
}

// Get all recourse students for this faculty
$stmt = $pdo->prepare("
    SELECT 
        rs.recourse_id,
        s.full_name,
        s.reg_no,
        sub.subject_name,
        oc.campus_name as original_campus,
        ofc.faculty_name as original_faculty,
        od.department_name as original_department,
        op.program_name as original_program,
        oc2.class_name as original_class,
        rc.campus_name as recourse_campus,
        rfc.faculty_name as recourse_faculty,
        rd.department_name as recourse_department,
        rp.program_name as recourse_program,
        rc2.class_name as recourse_class,
        rs.academic_term_id,
        at.term_name,
        rs.status,
        rs.created_at
    FROM recourse_student rs
    JOIN students s ON rs.student_id = s.student_id
    LEFT JOIN subject sub ON rs.subject_id = sub.subject_id
    LEFT JOIN campus oc ON rs.original_campus_id = oc.campus_id
    LEFT JOIN faculties ofc ON rs.original_faculty_id = ofc.faculty_id
    LEFT JOIN departments od ON rs.original_department_id = od.department_id
    LEFT JOIN programs op ON rs.original_program_id = op.program_id
    LEFT JOIN classes oc2 ON rs.original_class_id = oc2.class_id
    LEFT JOIN campus rc ON rs.recourse_campus_id = rc.campus_id
    LEFT JOIN faculties rfc ON rs.recourse_faculty_id = rfc.faculty_id
    LEFT JOIN departments rd ON rs.recourse_department_id = rd.department_id
    LEFT JOIN programs rp ON rs.recourse_program_id = rp.program_id
    LEFT JOIN classes rc2 ON rs.recourse_class_id = rc2.class_id
    LEFT JOIN academic_term at ON rs.academic_term_id = at.academic_term_id
    WHERE rs.recourse_faculty_id = ?
    AND rs.recourse_campus_id IN (" . implode(',', array_fill(0, count($campus_ids), '?')) . ")
    ORDER BY rs.created_at DESC
");
$params = array_merge([$faculty_id], $campus_ids);
$stmt->execute($params);
$recourse_students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Teacher View - Filter by faculty
$stmt = $pdo->prepare("
    SELECT 
        ta.date,
        t.teacher_name,
        ta.time_in,
        ta.time_out,
        ta.minutes_worked,
        ta.notes
    FROM teacher_attendance ta
    INNER JOIN teachers t ON t.teacher_id = ta.teacher_id
    INNER JOIN timetable tt ON t.teacher_id = tt.teacher_id
    INNER JOIN classes c ON tt.class_id = c.class_id
    WHERE c.faculty_id = ?
    AND c.campus_id IN (" . implode(',', array_fill(0, count($campus_ids), '?')) . ")
    GROUP BY ta.id, t.teacher_id, ta.date
    ORDER BY ta.date DESC
    LIMIT 50
");
$params = array_merge([$faculty_id], $campus_ids);
$stmt->execute($params);
$teacher_view = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================= FETCH PENDING CORRECTIONS ================= */
$stmt = $pdo->prepare("
    SELECT 
        ac.leave_id as correction_id,
        ac.student_id,
        s.full_name as student_name,
        s.reg_no,
        sub.subject_name,
        t.teacher_name,
        ac.reason,
        ac.reason_details,
        ac.start_date as correction_date,
        ac.created_at,
        u.username as requested_by
    FROM attendance_correction ac
    JOIN students s ON ac.student_id = s.student_id
    LEFT JOIN subject sub ON ac.subject_id = sub.subject_id
    LEFT JOIN teachers t ON ac.teacher_id = t.teacher_id
    LEFT JOIN users u ON ac.requested_by = u.user_id
    JOIN student_enroll se ON s.student_id = se.student_id
    WHERE ac.status = 'pending'
    AND se.faculty_id = ?
    AND se.campus_id IN (" . implode(',', array_fill(0, count($campus_ids), '?')) . ")
    ORDER BY ac.created_at DESC
");
$params = array_merge([$faculty_id], $campus_ids);
$stmt->execute($params);
$pending_corrections = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Parse original and corrected status for display
foreach ($pending_corrections as &$corr) {
    $original_status = 'absent';
    $corrected_status = 'present';
    
    if (preg_match('/original_status:(\w+)/', $corr['reason_details'] ?? '', $original_match)) {
        $original_status = $original_match[1];
    }
    if (preg_match('/corrected_status:(\w+)/', $corr['reason_details'] ?? '', $corrected_match)) {
        $corrected_status = $corrected_match[1];
    }
    
    // Remove status info from display
    $corr['reason_details'] = preg_replace('/original_status:\w+, corrected_status:\w+, details:/', '', $corr['reason_details'] ?? '');
    $corr['original_status'] = $original_status;
    $corr['corrected_status'] = $corrected_status;
}

/* ================= FETCH APPROVED CORRECTIONS ================= */
$stmt = $pdo->prepare("
    SELECT 
        ac.leave_id as correction_id,
        ac.student_id,
        s.full_name as student_name,
        s.reg_no,
        sub.subject_name,
        ac.reason,
        ac.reason_details,
        ac.start_date as correction_date,
        ac.created_at,
        u1.username as requested_by,
        u2.username as approved_by,
        ac.updated_at as approved_at
    FROM attendance_correction ac
    JOIN students s ON ac.student_id = s.student_id
    LEFT JOIN subject sub ON ac.subject_id = sub.subject_id
    LEFT JOIN users u1 ON ac.requested_by = u1.user_id
    LEFT JOIN users u2 ON ac.approved_by = u2.user_id
    JOIN student_enroll se ON s.student_id = se.student_id
    WHERE ac.status = 'approved'
    AND se.faculty_id = ?
    AND se.campus_id IN (" . implode(',', array_fill(0, count($campus_ids), '?')) . ")
    ORDER BY ac.updated_at DESC
    LIMIT 50
");
$params = array_merge([$faculty_id], $campus_ids);
$stmt->execute($params);
$approved_corrections = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Parse status for approved corrections
foreach ($approved_corrections as &$corr) {
    $original_status = 'absent';
    $corrected_status = 'present';
    
    if (preg_match('/original_status:(\w+)/', $corr['reason_details'] ?? '', $original_match)) {
        $original_status = $original_match[1];
    }
    if (preg_match('/corrected_status:(\w+)/', $corr['reason_details'] ?? '', $corrected_match)) {
        $corrected_status = $corrected_match[1];
    }
    
    $corr['original_status'] = $original_status;
    $corr['corrected_status'] = $corrected_status;
}

/* ================= FETCH REJECTED CORRECTIONS ================= */
$stmt = $pdo->prepare("
    SELECT 
        ac.leave_id as correction_id,
        ac.student_id,
        s.full_name as student_name,
        s.reg_no,
        sub.subject_name,
        ac.reason,
        ac.reason_details,
        ac.start_date as correction_date,
        ac.created_at,
        u.username as requested_by,
        ac.updated_at as rejected_at
    FROM attendance_correction ac
    JOIN students s ON ac.student_id = s.student_id
    LEFT JOIN subject sub ON ac.subject_id = sub.subject_id
    LEFT JOIN users u ON ac.requested_by = u.user_id
    JOIN student_enroll se ON s.student_id = se.student_id
    WHERE ac.status = 'rejected'
    AND se.faculty_id = ?
    AND se.campus_id IN (" . implode(',', array_fill(0, count($campus_ids), '?')) . ")
    ORDER BY ac.updated_at DESC
    LIMIT 20
");
$params = array_merge([$faculty_id], $campus_ids);
$stmt->execute($params);
$rejected_corrections = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Parse status for rejected corrections
foreach ($rejected_corrections as &$corr) {
    $original_status = 'absent';
    $corrected_status = 'present';
    
    if (preg_match('/original_status:(\w+)/', $corr['reason_details'] ?? '', $original_match)) {
        $original_status = $original_match[1];
    }
    if (preg_match('/corrected_status:(\w+)/', $corr['reason_details'] ?? '', $corrected_match)) {
        $corrected_status = $corrected_match[1];
    }
    
    $corr['original_status'] = $original_status;
    $corr['corrected_status'] = $corrected_status;
}

// ✅ Manual email sending trigger
if (isset($_GET['send_absence_emails']) && $_GET['send_absence_emails'] == 'now') {
    $today = date('Y-m-d');
    
    $stmt = $pdo->prepare("
        SELECT a.student_id, a.subject_id, 
               (SELECT COUNT(*) 
                FROM attendance a2 
                WHERE a2.student_id = a.student_id 
                  AND a2.subject_id = a.subject_id 
                  AND a2.academic_term_id = a.academic_term_id 
                  AND a2.status = 'absent') as absence_count
        FROM attendance a
        JOIN student_enroll se ON a.student_id = se.student_id
        WHERE a.attendance_date = ? 
          AND a.status = 'absent'
          AND a.academic_term_id = ?
          AND se.faculty_id = ?
          AND se.campus_id IN (" . implode(',', array_fill(0, count($campus_ids), '?')) . ")
        GROUP BY a.student_id, a.subject_id, a.academic_term_id
    ");
    $params = array_merge([$today, $academic_term_id, $faculty_id], $campus_ids);
    $stmt->execute($params);
    $absent_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $sent_count = 0;
    foreach ($absent_students as $record) {
        // Get subject name
        $subject_stmt = $pdo->prepare("SELECT subject_name FROM subject WHERE subject_id = ?");
        $subject_stmt->execute([$record['subject_id']]);
        $subject = $subject_stmt->fetch(PDO::FETCH_ASSOC);
        $subject_name = $subject['subject_name'] ?? 'Unknown Subject';
        
        if (sendDirectAbsenceEmail($record['student_id'], $subject_name, $record['absence_count'], $pdo)) {
            $sent_count++;
        }
    }
    
    $message = "✅ Sent $sent_count absence emails for today!";
    $type = "success";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Management | Faculty Admin - <?= htmlspecialchars($faculty_name) ?></title>
    <link rel="icon" type="image/png" href="../images.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --green: #00843D;
            --blue: #0072CE;
            --red: #C62828;
            --orange: #FF9800;
            --light-green: #00A651;
            --bg: #F5F9F7;
            --dark: #333;
            --light: #f8f9fa;
            --border: #e0e0e0;
            --shadow: rgba(0, 0, 0, 0.08);
            --white: #fff;
            --gold: #FFB81C;
        }
        
        body { font-family: 'Poppins', sans-serif; background: var(--bg); margin: 0; padding: 0; }
        .main-content { padding: 25px; margin-top: 90px; margin-left: 250px; transition: all .3s ease; }
        .sidebar.collapsed ~ .main-content { margin-left: 70px; }
        
        /* Page Header */
        .page-header { 
            margin-bottom: 25px; 
            padding: 20px; 
            background: var(--white); 
            border-radius: 12px; 
            box-shadow: 0 4px 15px var(--shadow); 
            border-left: 4px solid var(--green); 
        }
        .page-header h1 { 
            color: var(--blue); 
            font-weight: 600; 
            font-size: 28px; 
            margin: 0 0 10px 0; 
            display: flex; 
            align-items: center; 
            gap: 15px; 
        }
        .faculty-badge {
            background: rgba(0, 114, 206, 0.1);
            color: var(--blue);
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            white-space: nowrap;
        }
        .campus-badge {
            background: rgba(255, 184, 28, 0.1);
            color: var(--gold);
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            white-space: nowrap;
        }
        
        /* Tabs */
        .tabs { 
            display: flex; 
            gap: 10px; 
            flex-wrap: wrap; 
            margin-bottom: 20px; 
            position: relative; 
        }
        .tab-btn { 
            background: var(--blue); 
            color: var(--white); 
            border: none; 
            padding: 12px 20px; 
            border-radius: 8px; 
            cursor: pointer; 
            font-weight: 600; 
            font-size: 14px; 
            display: flex; 
            align-items: center; 
            gap: 8px; 
            transition: all 0.3s ease; 
            position: relative; 
        }
        .tab-btn:hover { 
            background: #005ba1; 
            transform: translateY(-2px); 
            box-shadow: 0 4px 12px rgba(0, 114, 206, 0.2); 
        }
        .tab-btn.active { 
            background: var(--green); 
        }
        .tab-btn.active:hover { 
            background: #006b30; 
        }
        .tab-btn.small { 
            padding: 8px 15px; 
            font-size: 13px; 
        }
        .tab-content { 
            display: none; 
            animation: fadeIn 0.4s ease; 
        }
        .tab-content.active { 
            display: block; 
        }
        
        /* Filter Box */
        .filter-box { 
            background: var(--white); 
            padding: 25px; 
            border-radius: 12px; 
            box-shadow: 0 4px 15px var(--shadow); 
            margin-bottom: 20px; 
            border: 1px solid var(--border); 
        }
        .grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); 
            gap: 20px; 
        }
        label { 
            font-weight: 600; 
            color: var(--blue); 
            font-size: 14px; 
            margin-bottom: 5px; 
            display: block; 
        }
        select, input, textarea { 
            width: 100%; 
            padding: 10px 12px; 
            border: 2px solid var(--border); 
            border-radius: 8px; 
            background: var(--light); 
            font-size: 14px; 
            transition: all 0.3s ease; 
        }
        select:focus, input:focus, textarea:focus { 
            outline: none; 
            border-color: var(--blue); 
            background: var(--white); 
            box-shadow: 0 0 0 3px rgba(0, 114, 206, 0.1); 
        }
        select:disabled {
            background: #e9ecef;
            cursor: not-allowed;
            opacity: 0.7;
        }
        
        /* Buttons */
        .btn { 
            border: none; 
            padding: 10px 20px; 
            border-radius: 8px; 
            font-weight: 600; 
            cursor: pointer; 
            font-size: 14px; 
            display: inline-flex; 
            align-items: center; 
            gap: 8px; 
            transition: all 0.3s ease; 
        }
        .btn.green { 
            background: linear-gradient(135deg, var(--green), var(--light-green)); 
            color: var(--white); 
        }
        .btn.green:hover { 
            background: linear-gradient(135deg, #006b30, #008c47); 
            transform: translateY(-2px); 
            box-shadow: 0 4px 12px rgba(0, 132, 61, 0.2); 
        }
        .btn.red { 
            background: linear-gradient(135deg, var(--red), #e53935); 
            color: var(--white); 
        }
        .btn.red:hover { 
            background: linear-gradient(135deg, #a81f1f, #c62828); 
            transform: translateY(-2px); 
            box-shadow: 0 4px 12px rgba(198, 40, 40, 0.2); 
        }
        .btn.blue { 
            background: linear-gradient(135deg, var(--blue), #2196f3); 
            color: var(--white); 
        }
        .btn.blue:hover { 
            background: linear-gradient(135deg, #005ba1, #1976d2); 
            transform: translateY(-2px); 
            box-shadow: 0 4px 12px rgba(0, 114, 206, 0.2); 
        }
        .btn.yellow { 
            background: var(--orange); 
            color: var(--white); 
        }
        .btn.yellow:hover { 
            background: #e68900; 
            transform: translateY(-2px); 
        }
        .btn.sm { 
            padding: 6px 12px; 
            font-size: 12px; 
        }
        
        /* Table */
        .table-wrapper { 
            overflow: auto; 
            background: var(--white); 
            border-radius: 12px; 
            box-shadow: 0 4px 15px var(--shadow); 
            margin-bottom: 20px; 
            border: 1px solid var(--border); 
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            min-width: 1000px; 
        }
        th, td { 
            padding: 14px 16px; 
            border-bottom: 1px solid var(--border); 
            text-align: left; 
            font-size: 14px; 
        }
        thead th { 
            background: linear-gradient(135deg, var(--blue), var(--green)); 
            color: var(--white); 
            position: sticky; 
            top: 0; 
            font-weight: 600; 
            text-transform: uppercase; 
            font-size: 13px; 
            letter-spacing: 0.5px; 
            z-index: 10;
        }
        tbody tr:hover { 
            background: #f3f8ff; 
        }
        tbody tr:nth-child(even) { 
            background: #fafafa; 
        }
        .actions { 
            text-align: center; 
            padding: 20px; 
            display: flex; 
            gap: 15px; 
            justify-content: center; 
        }
        
        /* Alert */
        .alert { 
            position: fixed; 
            top: 20px; 
            right: 20px; 
            background: var(--green); 
            color: var(--white); 
            padding: 15px 25px; 
            border-radius: 8px; 
            font-weight: 600; 
            z-index: 9999; 
            box-shadow: 0 4px 15px rgba(0, 132, 61, 0.3); 
            animation: slideIn 0.3s ease; 
        }
        .alert.error { 
            background: var(--red); 
            box-shadow: 0 4px 15px rgba(198, 40, 40, 0.3); 
        }
        
        /* Badges */
        .student-type-badge { 
            display: inline-block; 
            padding: 4px 10px; 
            border-radius: 12px; 
            font-size: 12px; 
            font-weight: 600; 
            text-transform: uppercase; 
        }
        .student-type-badge.regular { 
            background-color: #e3f2fd; 
            color: #1976d2; 
        }
        .student-type-badge.recourse { 
            background-color: #fff3e0; 
            color: #f57c00; 
        }
        .student-type-badge.completed { 
            background-color: #e8f5e9; 
            color: #2e7d32; 
        }
        .student-type-badge.cancelled { 
            background-color: #ffebee; 
            color: #c62828; 
        }
        .student-type-badge.active { 
            background-color: #e8f5e9; 
            color: #2e7d32; 
        }
        
        .email-status { 
            font-size: 12px; 
            padding: 4px 10px; 
            border-radius: 10px; 
            display: inline-flex; 
            align-items: center; 
            gap: 5px; 
            margin: 2px 0; 
        }
        .email-status.sent { 
            background: #e8f5e9; 
            color: #2e7d32; 
        }
        .email-status.missing { 
            background: #ffebee; 
            color: #c62828; 
        }
        
        .notification-badge { 
            position: absolute; 
            top: -5px; 
            right: -5px; 
            background: #ff5722; 
            color: white; 
            border-radius: 50%; 
            width: 20px; 
            height: 20px; 
            font-size: 11px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-weight: bold; 
        }
        
        /* Attendance Buttons */
        .attendance-buttons { 
            display: flex; 
            gap: 10px; 
            flex-wrap: wrap; 
        }
        .attendance-btn { 
            min-width: 70px; 
            padding: 8px 12px; 
            border-radius: 6px; 
            border: 2px solid var(--border); 
            background: var(--white); 
            cursor: pointer; 
            transition: all 0.3s ease; 
            font-weight: 500; 
            font-size: 12px; 
        }
        .attendance-btn.present { 
            border-color: #28a745; 
            color: #28a745; 
        }
        .attendance-btn.present:hover, .attendance-btn.present.selected { 
            background: #28a745; 
            color: white; 
        }
        .attendance-btn.absent { 
            border-color: #dc3545; 
            color: #dc3545; 
        }
        .attendance-btn.absent:hover, .attendance-btn.absent.selected { 
            background: #dc3545; 
            color: white; 
        }
        .attendance-btn.late { 
            border-color: #ffc107; 
            color: #ffc107; 
        }
        .attendance-btn.late:hover, .attendance-btn.late.selected { 
            background: #ffc107; 
            color: #333; 
        }
        .attendance-btn.excused { 
            border-color: var(--blue); 
            color: var(--blue); 
        }
        .attendance-btn.excused:hover, .attendance-btn.excused.selected { 
            background: var(--blue); 
            color: white; 
        }
        
        /* Status Badges */
        .status-badge { 
            padding: 6px 12px; 
            border-radius: 20px; 
            font-size: 12px; 
            font-weight: 600; 
            text-transform: uppercase; 
        }
        .status-badge.pending { 
            background: #fff3cd; 
            color: #856404; 
        }
        .status-badge.approved { 
            background: #d4edda; 
            color: #155724; 
        }
        .status-badge.rejected { 
            background: #f8d7da; 
            color: #721c24; 
        }
        
        /* Status Change */
        .status-change { 
            display: inline-flex; 
            align-items: center; 
            gap: 10px; 
            padding: 8px 16px; 
            border-radius: 8px; 
            background: var(--light); 
            margin: 5px 0; 
        }
        .status-change .from { 
            color: var(--red); 
            font-weight: bold; 
        }
        .status-change .to { 
            color: var(--green); 
            font-weight: bold; 
        }
        .status-change .arrow { 
            color: #6c757d; 
        }
        
        /* Modal */
        .correction-modal { 
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
        .correction-modal-content { 
            background: var(--white); 
            padding: 30px; 
            border-radius: 16px; 
            width: 90%; 
            max-width: 500px; 
            max-height: 90vh; 
            overflow-y: auto; 
            border-top: 5px solid var(--green); 
        }
        .reject-modal { 
            display: none; 
            position: fixed; 
            top: 0; 
            left: 0; 
            width: 100%; 
            height: 100%; 
            background: rgba(0,0,0,0.5); 
            z-index: 10000; 
            justify-content: center; 
            align-items: center; 
        }
        .reject-modal-content { 
            background: var(--white); 
            padding: 25px; 
            border-radius: 12px; 
            width: 90%; 
            max-width: 400px; 
            border-top: 5px solid var(--red); 
        }
        
        /* Sub-tabs */
        .subtab-content { 
            display: none; 
        }
        .subtab-content.active { 
            display: block; 
        }
        
        /* Student search */
        .student-result { 
            padding: 10px; 
            border-bottom: 1px solid var(--border); 
            cursor: pointer; 
            transition: background 0.2s; 
        }
        .student-result:hover { 
            background: #f3f8ff; 
        }
        .student-result strong { 
            display: block; 
            font-size: 14px; 
        }
        .student-result small { 
            font-size: 12px; 
            color: #666; 
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        /* Responsive */
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
            .grid { 
                grid-template-columns: 1fr; 
            }
            .tabs { 
                flex-direction: column; 
            }
            .tab-btn { 
                width: 100%; 
                justify-content: center; 
            }
            .actions { 
                flex-direction: column; 
            }
            .actions .btn { 
                width: 100%; 
                justify-content: center; 
            }
            .attendance-buttons { 
                flex-wrap: wrap; 
            }
            .attendance-btn { 
                min-width: 60px; 
                padding: 6px 10px; 
                font-size: 11px; 
            }
        }
        
        /* Scrollbar */
        .table-wrapper::-webkit-scrollbar,
        .correction-modal-content::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        .table-wrapper::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        .table-wrapper::-webkit-scrollbar-thumb {
            background: var(--blue);
            border-radius: 4px;
        }
        .table-wrapper::-webkit-scrollbar-thumb:hover {
            background: var(--green);
        }
    </style>
</head>
<body>
    <?php include('../includes/header.php'); ?>

    <div class="main-content">
        <div class="page-header">
            <h1>
                <i class="fas fa-clipboard-check"></i> Attendance Management
                <span class="faculty-badge"><i class="fas fa-university"></i> <?= htmlspecialchars($faculty_name) ?> (<?= htmlspecialchars($faculty_code) ?>)</span>
                <span class="campus-badge"><i class="fas fa-map-marker-alt"></i> <?= count($faculty_campuses) ?> Campus<?= count($faculty_campuses) != 1 ? 'es' : '' ?></span>
            </h1>
            <?php if ($autoTimeoutCount > 0): ?>
                <div style="color: var(--blue); font-size: 14px; margin-top: 5px;">
                    <i class="fas fa-robot"></i> Auto-timed out <?php echo $autoTimeoutCount; ?> teacher(s) today
                </div>
            <?php endif; ?>
        </div>
        
        <?php if ($message): ?>
            <div class="alert <?php echo $type === 'success' ? 'alert-success' : 'error'; ?>">
                <strong><?php echo htmlspecialchars($message); ?></strong>
            </div>
            <script>
                setTimeout(() => {
                    const alert = document.querySelector('.alert');
                    if (alert) alert.remove();
                }, 5000);
            </script>
        <?php endif; ?>

        <!-- ✅ Tabs -->
        <div class="tabs">
            <button class="tab-btn active" data-tab="tab1">
                <i class="fas fa-user-check"></i> Student Record
            </button>
            <button class="tab-btn" data-tab="tab2">
                <i class="fas fa-list"></i> Student View
            </button>
            <button class="tab-btn" data-tab="tab3">
                <i class="fas fa-chalkboard-teacher"></i> Teacher Record
            </button>
            <button class="tab-btn" data-tab="tab4">
                <i class="fas fa-eye"></i> Teacher View
            </button>
            <button class="tab-btn" data-tab="tab5">
                <i class="fas fa-envelope"></i> Email Logs
                <?php if (!empty($email_logs)): ?>
                    <span class="notification-badge"><?php echo count($email_logs); ?></span>
                <?php endif; ?>
            </button>
            <button class="tab-btn" data-tab="tab6">
                <i class="fas fa-redo"></i> Recourse Students
            </button>
            <button class="tab-btn" data-tab="tab7">
                <i class="fas fa-edit"></i> Corrections
                <?php if (!empty($pending_corrections)): ?>
                    <span class="notification-badge"><?php echo count($pending_corrections); ?></span>
                <?php endif; ?>
            </button>
        </div>

        <!-- ================= TAB 1: STUDENT RECORD ================= -->
        <div id="tab1" class="tab-content active">
            <div class="filter-box">
                <form method="POST" id="studentForm">
                    <div class="grid">
                        <!-- 🏫 CAMPUS -->
                        <div>
                            <label><i class="fas fa-university"></i> Campus</label>
                            <select name="campus_id" id="campus_id" class="form-select" required>
                                <option value="">Select Campus</option>
                                <?php foreach ($campuses as $c): ?>
                                    <option value="<?php echo $c['campus_id']; ?>" <?php echo ($_POST['campus_id'] ?? '') == $c['campus_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c['campus_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 🏛 FACULTY (read-only) -->
                        <div>
                            <label>Faculty</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($faculty_name) ?> (<?= htmlspecialchars($faculty_code) ?>)" readonly disabled>
                            <input type="hidden" name="faculty_id" id="faculty_id" value="<?= $faculty_id ?>">
                        </div>

                        <!-- 🧩 DEPARTMENT -->
                        <div>
                            <label><i class="fas fa-building"></i> Department</label>
                            <select name="department_id" id="department_id" class="form-select" disabled>
                                <option value="">Select Department</option>
                            </select>
                        </div>

                        <!-- 🎓 PROGRAM -->
                        <div>
                            <label><i class="fas fa-book"></i> Program</label>
                            <select name="program_id" id="program_id" class="form-select" disabled>
                                <option value="">Select Program</option>
                            </select>
                        </div>

                        <!-- 🧑‍🎓 CLASS -->
                        <div>
                            <label><i class="fas fa-users"></i> Class</label>
                            <select name="class_id" id="class_id" class="form-select" disabled>
                                <option value="">Select Class</option>
                            </select>
                        </div>

                        <!-- 👨‍🏫 TEACHER -->
                        <div>
                            <label><i class="fas fa-chalkboard-teacher"></i> Teacher</label>
                            <select name="teacher_id" id="teacher_id" class="form-select" required>
                                <option value="">Select Teacher</option>
                                <?php foreach ($teachers as $t): ?>
                                    <option value="<?php echo $t['teacher_id']; ?>" <?php echo ($_POST['teacher_id'] ?? '') == $t['teacher_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($t['teacher_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 📅 DATE -->
                        <div>
                            <label><i class="fas fa-calendar-alt"></i> Date</label>
                            <input type="date" name="date" id="date" class="form-control" value="<?php echo $_POST['date'] ?? date('Y-m-d'); ?>" required>
                        </div>

                        <div style="align-self: end;">
                            <button type="submit" name="load_students" class="btn blue">
                                <i class="fas fa-list"></i> Load Students
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <?php if (!empty($students)): ?>
                <form method="POST" id="attendanceForm">
                    <input type="hidden" name="action" value="student_save" id="attendanceAction">
                    <input type="hidden" name="teacher_id" value="<?php echo htmlspecialchars($_POST['teacher_id'] ?? ''); ?>">
                    <input type="hidden" name="class_id" value="<?php echo htmlspecialchars($_POST['class_id'] ?? ''); ?>">
                    <input type="hidden" name="campus_id" value="<?php echo htmlspecialchars($_POST['campus_id'] ?? ''); ?>">
                    <input type="hidden" name="date" value="<?php echo htmlspecialchars($_POST['date'] ?? date('Y-m-d')); ?>">
                    
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Student</th>
                                    <th>Reg No</th>
                                    <th>Semester</th>
                                    <th>Type</th>
                                    <th>Emails</th>
                                    <th>Attendance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $i = 1; 
                                foreach ($students as $student): 
                                    $student_id = $student['student_id'];
                                    $existing_status = $student['attendance_status'] ?? '';
                                    $student_type = $student['student_type'] ?? 'regular';
                                ?>
                                    <tr>
                                        <td><?php echo $i++; ?></td>
                                        <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($student['reg_no']); ?></td>
                                        <td><?php echo htmlspecialchars($student['semester_name'] ?? 'N/A'); ?></td>
                                        <td>
                                            <span class="student-type-badge <?php echo $student_type; ?>">
                                                <?php echo ucfirst($student_type); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if(!empty($student['student_email'])): ?>
                                                <span class="email-status sent">
                                                    <i class="fas fa-check"></i> Student
                                                </span><br>
                                            <?php else: ?>
                                                <span class="email-status missing">
                                                    <i class="fas fa-times"></i> Student
                                                </span><br>
                                            <?php endif; ?>
                                            
                                            <?php if(!empty($student['parent_email'])): ?>
                                                <span class="email-status sent">
                                                    <i class="fas fa-check"></i> Parent
                                                </span>
                                            <?php else: ?>
                                                <span class="email-status missing">
                                                    <i class="fas fa-times"></i> Parent
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="attendance-buttons">
                                                <button type="button" class="attendance-btn present <?php echo $existing_status == 'present' ? 'selected' : ''; ?>" 
                                                        data-student="<?php echo $student_id; ?>" data-status="present">
                                                    Present
                                                </button>
                                                <button type="button" class="attendance-btn absent <?php echo $existing_status == 'absent' ? 'selected' : ''; ?>" 
                                                        data-student="<?php echo $student_id; ?>" data-status="absent">
                                                    Absent
                                                </button>
                                                <button type="button" class="attendance-btn late <?php echo $existing_status == 'late' ? 'selected' : ''; ?>" 
                                                        data-student="<?php echo $student_id; ?>" data-status="late">
                                                    Late
                                                </button>
                                                <button type="button" class="attendance-btn excused <?php echo $existing_status == 'excused' ? 'selected' : ''; ?>" 
                                                        data-student="<?php echo $student_id; ?>" data-status="excused">
                                                    Excused
                                                </button>
                                                <input type="hidden" name="attendance[<?php echo $student_id; ?>]" 
                                                       id="attendance_<?php echo $student_id; ?>" 
                                                       value="<?php echo htmlspecialchars($existing_status ?: 'present'); ?>">
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="actions">
                        <button type="submit" class="btn green" onclick="document.getElementById('attendanceAction').value='student_save'">
                            <i class="fas fa-save"></i> Save Attendance
                        </button>
                        <button type="submit" class="btn red" onclick="document.getElementById('attendanceAction').value='student_unlock'; return confirm('Are you sure you want to unlock attendance? This will delete existing records for this class.')">
                            <i class="fas fa-unlock"></i> Unlock Attendance
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <!-- ================= TAB 2: STUDENT VIEW ================= -->
        <div id="tab2" class="tab-content">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Student</th>
                            <th>Reg No</th>
                            <th>Class</th>
                            <th>Subject</th>
                            <th>Teacher</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Emails</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($attendance_view)): ?>
                            <?php foreach($attendance_view as $a): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($a['attendance_date']); ?></td>
                                    <td><?php echo htmlspecialchars($a['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($a['reg_no']); ?></td>
                                    <td><?php echo htmlspecialchars($a['class_name']); ?></td>
                                    <td><?php echo htmlspecialchars($a['subject_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($a['teacher_name']); ?></td>
                                    <td>
                                        <span class="student-type-badge <?php echo strtolower($a['student_type']); ?>">
                                            <?php echo htmlspecialchars($a['student_type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($a['status'] == 'present'): ?>
                                            <span style="color:var(--green);font-weight:bold;">
                                                <i class="fas fa-check-circle"></i> Present
                                            </span>
                                        <?php elseif ($a['status'] == 'absent'): ?>
                                            <span style="color:var(--red);font-weight:bold;">
                                                <i class="fas fa-times-circle"></i> Absent
                                            </span>
                                        <?php elseif ($a['status'] == 'late'): ?>
                                            <span style="color:var(--orange);font-weight:bold;">
                                                <i class="fas fa-clock"></i> Late
                                            </span>
                                        <?php else: ?>
                                            <span style="color:var(--blue);font-weight:bold;">
                                                <i class="fas fa-user-clock"></i> Excused
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if(!empty($a['student_email'])): ?>
                                            <span class="email-status sent">
                                                <i class="fas fa-user-graduate"></i> Student
                                            </span><br>
                                        <?php endif; ?>
                                        <?php if(!empty($a['parent_email'])): ?>
                                            <span class="email-status sent">
                                                <i class="fas fa-users"></i> Parent
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" style="text-align:center; padding: 40px; color: #666;">
                                    <i class="fas fa-clipboard-list fa-3x mb-3" style="color: #ddd;"></i><br>
                                    No student attendance records found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ================= TAB 3: TEACHER RECORD ================= -->
        <div id="tab3" class="tab-content">
            <div class="filter-box">
                <form method="POST">
                    <div class="grid">
                        <!-- 👨‍🏫 TEACHER -->
                        <div>
                            <label><i class="fas fa-chalkboard-teacher"></i> Teacher</label>
                            <select name="teacher_id" class="form-select" required>
                                <option value="">Select Teacher</option>
                                <?php foreach($teachers as $t): ?>
                                    <option value="<?php echo htmlspecialchars($t['teacher_id']); ?>" 
                                        <?php echo (!empty($_POST['teacher_id']) && $_POST['teacher_id']==$t['teacher_id'])?'selected':''; ?>>
                                        <?php echo htmlspecialchars($t['teacher_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 📅 DATE -->
                        <div>
                            <label><i class="fas fa-calendar-alt"></i> Date</label>
                            <input type="date" name="date" class="form-control" 
                                   value="<?php echo htmlspecialchars($_POST['date'] ?? date('Y-m-d')); ?>" required>
                        </div>

                        <!-- ⚙️ ACTION -->
                        <div>
                            <label><i class="fas fa-cogs"></i> Action</label>
                            <select name="io_action" class="form-select" required>
                                <option value="">Select</option>
                                <option value="In">Time In</option>
                                <option value="Out">Time Out</option>
                            </select>
                        </div>

                        <!-- 📝 NOTES -->
                        <div>
                            <label><i class="fas fa-sticky-note"></i> Notes</label>
                            <input type="text" name="notes" class="form-control" 
                                   placeholder="Optional notes..." 
                                   value="<?php echo htmlspecialchars($_POST['notes'] ?? ''); ?>">
                        </div>

                        <!-- 💾 BUTTONS -->
                        <div style="align-self:end;">
                            <button type="submit" name="action" value="teacher_save" class="btn green">
                                <i class="fas fa-save"></i> Save
                            </button>
                            <button type="submit" name="action" value="teacher_unlock" class="btn red" 
                                    onclick="return confirm('Are you sure you want to unlock teacher attendance?')">
                                <i class="fas fa-unlock"></i> Unlock
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- ================= TAB 4: TEACHER VIEW ================= -->
        <div id="tab4" class="tab-content">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Teacher</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>Minutes Worked</th>
                            <th>Notes</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($teacher_view)): ?>
                            <?php foreach($teacher_view as $tv): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($tv['date']); ?></td>
                                    <td><?php echo htmlspecialchars($tv['teacher_name']); ?></td>
                                    <td><?php echo htmlspecialchars($tv['time_in']); ?></td>
                                    <td>
                                        <?php if ($tv['time_out']): ?>
                                            <?php echo htmlspecialchars($tv['time_out']); ?>
                                            <?php if (strpos($tv['notes'] ?? '', 'Auto-Out') !== false): ?>
                                                <span style="color:var(--orange);font-size:11px;margin-left:5px;">
                                                    <i class="fas fa-robot"></i> (Auto)
                                                </span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color:var(--green);">
                                                <i class="fas fa-clock"></i> Still Working
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($tv['minutes_worked']); ?></td>
                                    <td><?php echo htmlspecialchars($tv['notes']); ?></td>
                                    <td>
                                        <?php if ($tv['time_out']): ?>
                                            <span style="color:var(--blue);font-weight:bold;">
                                                <i class="fas fa-door-closed"></i> Checked Out
                                            </span>
                                        <?php else: ?>
                                            <span style="color:var(--green);font-weight:bold;">
                                                <i class="fas fa-door-open"></i> Checked In
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" style="text-align:center; padding: 40px; color: #666;">
                                    <i class="fas fa-chalkboard-teacher fa-3x mb-3" style="color: #ddd;"></i><br>
                                    No teacher attendance records found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ================= TAB 5: EMAIL LOGS ================= -->
        <div id="tab5" class="tab-content">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Student</th>
                            <th>Recipient</th>
                            <th>Subject</th>
                            <th>Type</th>
                            <th>Absences</th>
                            <th>Status</th>
                            <th>Error</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($email_logs)): ?>
                            <?php foreach($email_logs as $log): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($log['sent_at']); ?></td>
                                    <td><?php echo htmlspecialchars($log['full_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($log['recipient_email']); ?></td>
                                    <td title="<?php echo htmlspecialchars($log['subject']); ?>">
                                        <?php echo htmlspecialchars(substr($log['subject'], 0, 30)); ?>...
                                    </td>
                                    <td>
                                        <?php if ($log['message_type'] == 'absence'): ?>
                                            <span class="status-badge pending">Absence</span>
                                        <?php elseif ($log['message_type'] == 'recourse'): ?>
                                            <span class="status-badge rejected">Recourse</span>
                                        <?php else: ?>
                                            <span class="status-badge approved">Other</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($log['absence_count'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php if ($log['status'] == 'sent'): ?>
                                            <span style="color:var(--green);font-weight:bold;">
                                                <i class="fas fa-check-circle"></i> Sent
                                            </span>
                                        <?php else: ?>
                                            <span style="color:var(--red);font-weight:bold;">
                                                <i class="fas fa-times-circle"></i> Failed
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td title="<?php echo htmlspecialchars($log['error_message'] ?? ''); ?>">
                                        <?php echo htmlspecialchars(substr($log['error_message'] ?? '', 0, 20)); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" style="text-align:center; padding: 40px; color: #666;">
                                    <i class="fas fa-envelope fa-3x mb-3" style="color: #ddd;"></i><br>
                                    No email logs found. Emails will appear here after being sent.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ================= TAB 6: RECOURSE STUDENTS ================= -->
        <div id="tab6" class="tab-content">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student</th>
                            <th>Reg No</th>
                            <th>Subject</th>
                            <th>Original Class</th>
                            <th>Recourse Class</th>
                            <th>Academic Term</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recourse_students)): ?>
                            <?php $i = 1; foreach($recourse_students as $rs): ?>
                                <tr>
                                    <td><?php echo $i++; ?></td>
                                    <td><?php echo htmlspecialchars($rs['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($rs['reg_no']); ?></td>
                                    <td><?php echo htmlspecialchars($rs['subject_name']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($rs['original_campus']); ?> /
                                        <?php echo htmlspecialchars($rs['original_faculty']); ?> /
                                        <?php echo htmlspecialchars($rs['original_class']); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($rs['recourse_campus']); ?> /
                                        <?php echo htmlspecialchars($rs['recourse_faculty']); ?> /
                                        <?php echo htmlspecialchars($rs['recourse_class']); ?>
                                    </td>
                                    <td>
                                        <?php if($rs['academic_term_id'] == $academic_term_id): ?>
                                            <span style="color:var(--green);font-weight:bold;">
                                                <i class="fas fa-circle"></i> Current
                                            </span>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($rs['term_name'] ?? 'N/A'); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="student-type-badge <?php echo $rs['status']; ?>">
                                            <?php echo ucfirst($rs['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($rs['created_at']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" style="text-align:center; padding: 40px; color: #666;">
                                    <i class="fas fa-redo fa-3x mb-3" style="color: #ddd;"></i><br>
                                    No recourse student records found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ================= TAB 7: ATTENDANCE CORRECTIONS ================= -->
        <div id="tab7" class="tab-content">
            <div style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
                <button class="btn blue" onclick="openCorrectionModal()">
                    <i class="fas fa-plus-circle"></i> Request Correction
                </button>
                
                <div class="quick-actions">
                    <button class="btn yellow" onclick="window.location.href='?send_absence_emails=now'">
                        <i class="fas fa-paper-plane"></i> Send Today's Absence Emails
                    </button>
                </div>
            </div>

            <div class="tabs" style="margin-bottom: 20px;">
                <button class="tab-btn small active" data-subtab="pending-corrections">
                    Pending <span class="notification-badge" style="background: var(--orange);"><?php echo count($pending_corrections); ?></span>
                </button>
                <button class="tab-btn small" data-subtab="approved-corrections">
                    Approved <span class="notification-badge" style="background: var(--green);"><?php echo count($approved_corrections); ?></span>
                </button>
                <button class="tab-btn small" data-subtab="rejected-corrections">
                    Rejected <span class="notification-badge" style="background: var(--red);"><?php echo count($rejected_corrections); ?></span>
                </button>
            </div>

            <!-- Pending Corrections -->
            <div id="pending-corrections" class="subtab-content active">
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Student</th>
                                <th>Reg No</th>
                                <th>Subject</th>
                                <th>Teacher</th>
                                <th>Date</th>
                                <th>Correction</th>
                                <th>Reason</th>
                                <th>Requested</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($pending_corrections)): ?>
                                <?php $i = 1; foreach($pending_corrections as $corr): ?>
                                    <tr>
                                        <td><?php echo $i++; ?></td>
                                        <td><?php echo htmlspecialchars($corr['student_name']); ?></td>
                                        <td><?php echo htmlspecialchars($corr['reg_no']); ?></td>
                                        <td><?php echo htmlspecialchars($corr['subject_name']); ?></td>
                                        <td><?php echo htmlspecialchars($corr['teacher_name']); ?></td>
                                        <td><?php echo htmlspecialchars($corr['correction_date']); ?></td>
                                        <td>
                                            <div class="status-change">
                                                <span class="from"><?php echo ucfirst($corr['original_status'] ?? 'absent'); ?></span>
                                                <span class="arrow"><i class="fas fa-arrow-right"></i></span>
                                                <span class="to"><?php echo ucfirst($corr['corrected_status'] ?? 'present'); ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <strong><?php echo ucfirst(str_replace('_', ' ', $corr['reason'])); ?></strong><br>
                                            <small><?php echo htmlspecialchars($corr['reason_details']); ?></small>
                                        </td>
                                        <td>
                                            <?php echo date('Y-m-d H:i', strtotime($corr['created_at'])); ?><br>
                                            <small>By: <?php echo htmlspecialchars($corr['requested_by']); ?></small>
                                        </td>
                                        <td>
                                            <button class="btn green sm" 
                                                    onclick="approveCorrection(<?php echo $corr['correction_id']; ?>)">
                                                <i class="fas fa-check"></i> Approve
                                            </button>
                                            <button class="btn red sm" 
                                                    onclick="openRejectModal(<?php echo $corr['correction_id']; ?>)">
                                                <i class="fas fa-times"></i> Reject
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10" style="text-align:center; padding: 40px; color: #666;">
                                        <i class="fas fa-check-circle fa-3x mb-3" style="color: #ddd;"></i><br>
                                        No pending correction requests.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Approved Corrections -->
            <div id="approved-corrections" class="subtab-content">
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Student</th>
                                <th>Reg No</th>
                                <th>Subject</th>
                                <th>Date</th>
                                <th>Correction</th>
                                <th>Reason</th>
                                <th>Requested By</th>
                                <th>Approved By</th>
                                <th>Approved At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($approved_corrections)): ?>
                                <?php $i = 1; foreach($approved_corrections as $corr): ?>
                                    <tr>
                                        <td><?php echo $i++; ?></td>
                                        <td><?php echo htmlspecialchars($corr['student_name']); ?></td>
                                        <td><?php echo htmlspecialchars($corr['reg_no']); ?></td>
                                        <td><?php echo htmlspecialchars($corr['subject_name']); ?></td>
                                        <td><?php echo htmlspecialchars($corr['correction_date']); ?></td>
                                        <td>
                                            <div class="status-change">
                                                <span class="from"><?php echo ucfirst($corr['original_status'] ?? 'absent'); ?></span>
                                                <span class="arrow"><i class="fas fa-arrow-right"></i></span>
                                                <span class="to"><?php echo ucfirst($corr['corrected_status'] ?? 'present'); ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <strong><?php echo ucfirst(str_replace('_', ' ', $corr['reason'])); ?></strong>
                                        </td>
                                        <td><?php echo htmlspecialchars($corr['requested_by']); ?></td>
                                        <td><?php echo htmlspecialchars($corr['approved_by']); ?></td>
                                        <td><?php echo date('Y-m-d H:i', strtotime($corr['approved_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10" style="text-align:center; padding: 40px; color: #666;">
                                        <i class="fas fa-history fa-3x mb-3" style="color: #ddd;"></i><br>
                                        No approved corrections found.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Rejected Corrections -->
            <div id="rejected-corrections" class="subtab-content">
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Student</th>
                                <th>Reg No</th>
                                <th>Subject</th>
                                <th>Date</th>
                                <th>Correction</th>
                                <th>Reason</th>
                                <th>Requested By</th>
                                <th>Rejected At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($rejected_corrections)): ?>
                                <?php $i = 1; foreach($rejected_corrections as $corr): ?>
                                    <tr>
                                        <td><?php echo $i++; ?></td>
                                        <td><?php echo htmlspecialchars($corr['student_name']); ?></td>
                                        <td><?php echo htmlspecialchars($corr['reg_no']); ?></td>
                                        <td><?php echo htmlspecialchars($corr['subject_name']); ?></td>
                                        <td><?php echo htmlspecialchars($corr['correction_date']); ?></td>
                                        <td>
                                            <div class="status-change">
                                                <span class="from"><?php echo ucfirst($corr['original_status'] ?? 'absent'); ?></span>
                                                <span class="arrow"><i class="fas fa-arrow-right"></i></span>
                                                <span class="to"><?php echo ucfirst($corr['corrected_status'] ?? 'present'); ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <strong><?php echo ucfirst(str_replace('_', ' ', $corr['reason'])); ?></strong>
                                        </td>
                                        <td><?php echo htmlspecialchars($corr['requested_by']); ?></td>
                                        <td><?php echo date('Y-m-d H:i', strtotime($corr['rejected_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" style="text-align:center; padding: 40px; color: #666;">
                                        <i class="fas fa-ban fa-3x mb-3" style="color: #ddd;"></i><br>
                                        No rejected corrections found.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Correction Request Modal -->
        <div id="correctionModal" class="correction-modal">
            <div class="correction-modal-content">
                <h3><i class="fas fa-edit"></i> Request Attendance Correction</h3>
                <form id="correctionForm" method="POST">
                    <input type="hidden" name="action" value="request_correction">
                    
                    <div class="mb-3">
                        <label>Student Registration Number</label>
                        <input type="text" id="studentRegNo" class="form-control" placeholder="Enter reg no or name...">
                        <div id="studentSearchResults" style="display:none; max-height: 200px; overflow-y: auto; border: 1px solid var(--border); margin-top: 5px;"></div>
                        <input type="hidden" id="student_id" name="student_id">
                    </div>
                    
                    <div class="mb-3">
                        <label>Correction Date</label>
                        <input type="date" id="correction_date" name="correction_date" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label>Select Attendance Record</label>
                        <select id="attendanceSelect" name="attendance_id" class="form-control" required disabled>
                            <option value="">Select attendance record</option>
                        </select>
                        <input type="hidden" id="subject_id" name="subject_id">
                        <input type="hidden" id="original_status" name="original_status">
                    </div>
                    
                    <div class="mb-3">
                        <label>Corrected Status</label>
                        <select name="corrected_status" class="form-control" required>
                            <option value="">Select status</option>
                            <option value="present">Present</option>
                            <option value="absent">Absent</option>
                            <option value="late">Late</option>
                            <option value="excused">Excused</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label>Reason</label>
                        <select name="reason" class="form-control" required>
                            <option value="">Select reason</option>
                            <option value="system_error">System Error</option>
                            <option value="teacher_mistake">Teacher Mistake</option>
                            <option value="medical">Medical Reason</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label>Reason Details</label>
                        <textarea name="reason_details" class="form-control" rows="3" placeholder="Provide details..."></textarea>
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                        <button type="submit" class="btn green" style="flex: 1;">
                            <i class="fas fa-paper-plane"></i> Submit Request
                        </button>
                        <button type="button" class="btn red" onclick="closeCorrectionModal()" style="flex: 1;">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Reject Modal -->
        <div id="rejectModal" class="reject-modal">
            <div class="reject-modal-content">
                <h3><i class="fas fa-times-circle"></i> Reject Correction Request</h3>
                <input type="hidden" id="rejectCorrectionId">
                <div class="mb-3">
                    <label>Rejection Reason</label>
                    <textarea id="rejectionReason" class="form-control" rows="4" placeholder="Enter reason for rejection..." required></textarea>
                </div>
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="button" class="btn red" onclick="rejectCorrection()" style="flex: 1;">
                        <i class="fas fa-times"></i> Reject
                    </button>
                    <button type="button" class="btn blue" onclick="closeRejectModal()" style="flex: 1;">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
    $(document).ready(function() {
        // ✅ Tab Switching
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                btn.classList.add('active');
                document.getElementById(btn.dataset.tab).classList.add('active');
            });
        });

        // Sub-tab switching for corrections
        document.querySelectorAll('[data-subtab]').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('[data-subtab]').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.subtab-content').forEach(c => c.classList.remove('active'));
                btn.classList.add('active');
                document.getElementById(btn.dataset.subtab).classList.add('active');
            });
        });

        // ===========================================
        // ATTENDANCE HIERARCHY FUNCTIONS - FIXED TO LOAD DEPARTMENTS IMMEDIATELY
        // ===========================================

        // Campus Change - Load Departments immediately
        $('#campus_id').change(function() {
            var campusId = $(this).val();
            
            // Reset all dropdowns
            $('#department_id').html('<option value="">Select Department</option>').prop('disabled', true);
            $('#program_id').html('<option value="">Select Program</option>').prop('disabled', true);
            $('#class_id').html('<option value="">Select Class</option>').prop('disabled', true);
            
            if(campusId) {
                // Faculty admin has only one faculty, use it directly
                var facultyId = '<?php echo $faculty_id; ?>';
                
                // Load departments immediately
                $.ajax({
                    url: window.location.pathname,
                    type: 'GET',
                    data: {
                        ajax: 'get_departments_by_faculty',
                        faculty_id: facultyId,
                        campus_id: campusId
                    },
                    dataType: 'json',
                    success: function(data) {
                        if(data.status === 'success') {
                            $('#department_id').html('<option value="">Select Department</option>');
                            $.each(data.departments, function(index, department) {
                                $('#department_id').append('<option value="'+department.department_id+'">' + (department.display_name || department.department_name) + '</option>');
                            });
                            $('#department_id').prop('disabled', false);
                            
                            // Trigger change if there's already a selected value from POST
                            var selectedDeptId = '<?php echo $_POST["department_id"] ?? ""; ?>';
                            if(selectedDeptId) {
                                $('#department_id').val(selectedDeptId).trigger('change');
                            }
                        } else {
                            alert(data.message);
                        }
                    },
                    error: function() {
                        alert('Error loading departments');
                    }
                });
            }
        });

        // Department Change - Load Programs
        $('#department_id').change(function() {
            var departmentId = $(this).val();
            var facultyId = '<?php echo $faculty_id; ?>';
            var campusId = $('#campus_id').val();
            
            $('#program_id').html('<option value="">Select Program</option>').prop('disabled', true);
            $('#class_id').html('<option value="">Select Class</option>').prop('disabled', true);
            
            if(departmentId && facultyId && campusId) {
                $.ajax({
                    url: window.location.pathname,
                    type: 'GET',
                    data: {
                        ajax: 'get_programs_by_department',
                        department_id: departmentId,
                        faculty_id: facultyId,
                        campus_id: campusId
                    },
                    dataType: 'json',
                    success: function(data) {
                        if(data.status === 'success') {
                            $('#program_id').html('<option value="">Select Program</option>');
                            $.each(data.programs, function(index, program) {
                                $('#program_id').append('<option value="'+program.program_id+'">'+program.program_name+'</option>');
                            });
                            $('#program_id').prop('disabled', false);
                            
                            // Trigger change if there's already a selected value
                            var selectedProgramId = '<?php echo $_POST["program_id"] ?? ""; ?>';
                            if(selectedProgramId) {
                                $('#program_id').val(selectedProgramId).trigger('change');
                            }
                        } else {
                            alert(data.message);
                        }
                    },
                    error: function() {
                        alert('Error loading programs');
                    }
                });
            }
        });

        // Program Change - Load Classes
        $('#program_id').change(function() {
            var programId = $(this).val();
            var departmentId = $('#department_id').val();
            var facultyId = '<?php echo $faculty_id; ?>';
            var campusId = $('#campus_id').val();
            
            $('#class_id').html('<option value="">Select Class</option>').prop('disabled', true);
            
            if(programId && departmentId && facultyId && campusId) {
                $.ajax({
                    url: window.location.pathname,
                    type: 'GET',
                    data: {
                        ajax: 'get_classes_by_program',
                        program_id: programId,
                        department_id: departmentId,
                        faculty_id: facultyId,
                        campus_id: campusId
                    },
                    dataType: 'json',
                    success: function(data) {
                        if(data.status === 'success') {
                            $('#class_id').html('<option value="">Select Class</option>');
                            $.each(data.classes, function(index, cls) {
                                $('#class_id').append('<option value="'+cls.class_id+'">' + (cls.display_name || cls.class_name) + '</option>');
                            });
                            $('#class_id').prop('disabled', false);
                            
                            // Trigger change if there's already a selected value
                            var selectedClassId = '<?php echo $_POST["class_id"] ?? ""; ?>';
                            if(selectedClassId) {
                                $('#class_id').val(selectedClassId);
                            }
                        } else {
                            alert(data.message);
                        }
                    },
                    error: function() {
                        alert('Error loading classes');
                    }
                });
            }
        });

        // Initialize hierarchy on page load
        function initializeHierarchy() {
            var campusId = $('#campus_id').val();
            if(campusId) {
                // Trigger campus change to load departments
                $('#campus_id').trigger('change');
            }
        }
        
        // Call initialization on page load
        initializeHierarchy();

        // Attendance button functionality
        document.querySelectorAll('.attendance-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const studentId = this.getAttribute('data-student');
                const status = this.getAttribute('data-status');
                
                // Remove selected class from all buttons in this group
                this.parentNode.querySelectorAll('.attendance-btn').forEach(b => {
                    b.classList.remove('selected');
                });
                
                // Add selected class to clicked button
                this.classList.add('selected');
                
                // Update hidden input value
                document.getElementById('attendance_' + studentId).value = status;
            });
        });

        // ===========================================
        // CORRECTION FUNCTIONS
        // ===========================================

        // Student search
        $('#studentRegNo').on('keyup', function() {
            const query = $(this).val();
            if (query.length < 2) {
                $('#studentSearchResults').hide();
                return;
            }
            
            $.ajax({
                url: window.location.pathname,
                type: 'GET',
                data: {
                    ajax: 'search_students',
                    query: query
                },
                success: function(data) {
                    if (data.status === 'success') {
                        const results = $('#studentSearchResults');
                        results.empty();
                        
                        if (data.students.length > 0) {
                            data.students.forEach(student => {
                                results.append(`
                                    <div class="student-result" 
                                         data-id="${student.student_id}"
                                         data-name="${student.full_name}"
                                         data-reg="${student.reg_no}"
                                         style="padding: 10px; border-bottom: 1px solid #eee; cursor: pointer;">
                                        <strong>${student.full_name}</strong><br>
                                        <small>${student.reg_no}</small>
                                    </div>
                                `);
                            });
                            results.show();
                        } else {
                            results.hide();
                        }
                    }
                }
            });
        });

        // Student selection
        $(document).on('click', '.student-result', function() {
            const studentId = $(this).data('id');
            const studentName = $(this).data('name');
            const studentReg = $(this).data('reg');
            
            $('#student_id').val(studentId);
            $('#studentRegNo').val(studentReg + ' - ' + studentName);
            $('#studentSearchResults').hide();
            
            // Load student's attendance records
            loadStudentAttendance(studentId);
        });

        // Load attendance records when date changes
        $('#correction_date').change(function() {
            const studentId = $('#student_id').val();
            if (studentId) {
                loadStudentAttendance(studentId);
            }
        });

        function loadStudentAttendance(studentId) {
            const date = $('#correction_date').val();
            if (!date) return;
            
            $.ajax({
                url: window.location.pathname,
                type: 'GET',
                data: {
                    ajax: 'get_student_attendance',
                    student_id: studentId,
                    date: date
                },
                success: function(data) {
                    const select = $('#attendanceSelect');
                    select.empty().append('<option value="">Select attendance record</option>');
                    
                    if (data.status === 'success' && data.attendance.length > 0) {
                        data.attendance.forEach(record => {
                            select.append(`
                                <option value="${record.attendance_id}"
                                        data-subject="${record.subject_id}"
                                        data-status="${record.status}">
                                    ${record.subject_name} - ${record.teacher_name} (${record.status})
                                </option>
                            `);
                        });
                        select.prop('disabled', false);
                    } else {
                        select.append('<option value="">No attendance records found for this date</option>');
                        select.prop('disabled', true);
                    }
                }
            });
        }

        // When attendance record is selected
        $('#attendanceSelect').change(function() {
            const selected = $(this).find(':selected');
            $('#subject_id').val(selected.data('subject') || '');
            $('#original_status').val(selected.data('status') || '');
            
            // Set corrected status dropdown to not be the same as original
            const correctedSelect = $('select[name="corrected_status"]');
            correctedSelect.val('');
        });

        // If URL has view parameter, switch to that tab
        const urlParams = new URLSearchParams(window.location.search);
        const viewParam = urlParams.get('view');
        if (viewParam === 'email_logs') {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            
            const emailTabBtn = document.querySelector('.tab-btn[data-tab="tab5"]');
            if (emailTabBtn) {
                emailTabBtn.classList.add('active');
                document.getElementById('tab5').classList.add('active');
            }
        }

        // Auto-refresh email logs every 2 minutes
        setInterval(() => {
            if (!document.hidden && document.querySelector('.tab-content.active')) {
                const activeTab = document.querySelector('.tab-content.active').id;
                if (activeTab === 'tab5') {
                    window.location.reload();
                }
            }
        }, 120000);
    });

    // Modal Functions
    function openCorrectionModal() {
        document.getElementById('correctionModal').style.display = 'flex';
        document.getElementById('studentRegNo').focus();
    }

    function closeCorrectionModal() {
        document.getElementById('correctionModal').style.display = 'none';
        document.getElementById('correctionForm').reset();
        $('#studentSearchResults').hide();
        $('#attendanceSelect').prop('disabled', true);
    }

    function openRejectModal(correctionId) {
        document.getElementById('rejectModal').style.display = 'flex';
        document.getElementById('rejectCorrectionId').value = correctionId;
        document.getElementById('rejectionReason').focus();
    }

    function closeRejectModal() {
        document.getElementById('rejectModal').style.display = 'none';
        document.getElementById('rejectionReason').value = '';
    }

    function approveCorrection(correctionId) {
        if (!confirm('Are you sure you want to approve this correction? The attendance record will be updated immediately.')) {
            return;
        }
        
        $.ajax({
            url: window.location.pathname,
            type: 'GET',
            data: {
                ajax: 'approve_correction',
                correction_id: correctionId
            },
            success: function(data) {
                if (data.success) {
                    alert(`Correction approved successfully!\nStudent: ${data.student_name}\nChanged from: ${data.original_status} to: ${data.corrected_status}`);
                    window.location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            },
            error: function() {
                alert('Error approving correction');
            }
        });
    }

    function rejectCorrection() {
        const correctionId = document.getElementById('rejectCorrectionId').value;
        const reason = document.getElementById('rejectionReason').value.trim();
        
        if (!reason) {
            alert('Please provide a rejection reason');
            return;
        }
        
        if (!confirm('Are you sure you want to reject this correction request?')) {
            return;
        }
        
        $.ajax({
            url: window.location.pathname,
            type: 'GET',
            data: {
                ajax: 'reject_correction',
                correction_id: correctionId,
                reason: reason
            },
            success: function(data) {
                if (data.success) {
                    alert('Correction request rejected successfully!');
                    window.location.reload();
                } else {
                    alert('Error rejecting correction');
                }
            },
            error: function() {
                alert('Error rejecting correction');
            }
        });
    }

    // Close modal when clicking outside
    window.onclick = function(event) {
        const correctionModal = document.getElementById('correctionModal');
        const rejectModal = document.getElementById('rejectModal');
        
        if (event.target === correctionModal) {
            closeCorrectionModal();
        }
        if (event.target === rejectModal) {
            closeRejectModal();
        }
    };

    // Prevent form submission if student not selected
    document.getElementById('correctionForm').addEventListener('submit', function(e) {
        const studentId = document.getElementById('student_id').value;
        const subjectId = document.getElementById('subject_id').value;
        const correctedStatus = document.querySelector('select[name="corrected_status"]').value;
        const originalStatus = document.getElementById('original_status').value;
        
        if (!studentId) {
            alert('Please select a student');
            e.preventDefault();
            return;
        }
        
        if (!subjectId) {
            alert('Please select an attendance record');
            e.preventDefault();
            return;
        }
        
        if (correctedStatus === originalStatus) {
            alert('Corrected status must be different from original status');
            e.preventDefault();
            return;
        }
    });
    </script>
    <?php include('../includes/footer.php'); ?>

</body>
</html>
<?php ob_end_flush(); ?>