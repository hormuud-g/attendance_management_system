<?php
/**
 * ATTENDANCE MANAGEMENT SYSTEM - TEACHER ATTENDANCE PAGE
 * Full design with sidebar and cards like courses.php and timetable.php
 * Includes Study Mode (Full-Time/Part-Time) and Attendance Correction
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

// ✅ Access Control - Allow teachers only
if (!isset($_SESSION['user'])) {
    header("Location: ../login.php?error=unauthorized");
    exit;
}

$user_role = strtolower($_SESSION['user']['role'] ?? '');

// Only allow teachers to access this page
if ($user_role !== 'teacher') {
    header("Location: ../dashboard.php?error=access_denied");
    exit;
}

$user_id = $_SESSION['user']['user_id'] ?? 0;

// Get user details
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$current_user = $stmt->fetch(PDO::FETCH_ASSOC);

// Get teacher_id from linked_id
$teacher_id = null;
if (!empty($current_user['linked_id']) && $current_user['linked_table'] === 'teacher') {
    $teacher_id = $current_user['linked_id'];
} else {
    // Try to find teacher by email
    $stmt = $pdo->prepare("SELECT teacher_id FROM teachers WHERE email = ?");
    $stmt->execute([$current_user['email']]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($teacher) {
        $teacher_id = $teacher['teacher_id'];
    }
}

// If still no teacher_id, redirect
if (!$teacher_id) {
    header("Location: ../dashboard.php?error=no_teacher_record");
    exit;
}

date_default_timezone_set('Africa/Nairobi');
$message = ""; 
$type = "";

// ============================================
// TEACHER-SPECIFIC FUNCTIONS
// ============================================

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
        
        if ($absence_count >= 5) {
            // Check if recourse email already sent recently
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
                return false;
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
            
            $emails_sent = 0;
            
            if (!empty($student_email) && filter_var($student_email, FILTER_VALIDATE_EMAIL)) {
                if (sendEmail($student_email, $email_student_subject, $email_student_message, $student_id, 'recourse', $absence_count, $pdo)) {
                    $emails_sent++;
                }
            }
            
            if (!empty($parent_email) && filter_var($parent_email, FILTER_VALIDATE_EMAIL)) {
                if (sendEmail($parent_email, $email_parent_subject, $email_parent_message, $student_id, 'recourse', $absence_count, $pdo)) {
                    $emails_sent++;
                }
            }
            
            return $emails_sent > 0;
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("Cumulative email error: " . $e->getMessage());
        return false;
    }
}

/* ================= GET TEACHER'S CLASSES WITH STUDY MODE ================= */
function getTeacherClasses($pdo, $teacher_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                c.class_id,
                c.class_name,
                c.study_mode,
                cmp.campus_id,
                cmp.campus_name,
                f.faculty_id,
                f.faculty_name,
                d.department_id,
                d.department_name,
                p.program_id,
                p.program_name
            FROM classes c
            JOIN timetable t ON c.class_id = t.class_id
            JOIN campus cmp ON c.campus_id = cmp.campus_id
            LEFT JOIN faculties f ON c.faculty_id = f.faculty_id
            LEFT JOIN departments d ON c.department_id = d.department_id
            LEFT JOIN programs p ON c.program_id = p.program_id
            WHERE t.teacher_id = ? 
            AND t.status = 'active'
            AND c.status = 'Active'
            ORDER BY c.class_name
        ");
        $stmt->execute([$teacher_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting teacher classes: " . $e->getMessage());
        return [];
    }
}

/* ================= GET TEACHER'S SUBJECTS BY CLASS ================= */
function getTeacherSubjectsByClass($pdo, $teacher_id, $class_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                s.subject_id,
                s.subject_name,
                s.subject_code,
                s.credit_hours,
                t.day_of_week,
                t.start_time,
                t.end_time,
                r.room_name,
                r.room_code,
                r.building_name
            FROM subject s
            JOIN timetable t ON s.subject_id = t.subject_id
            LEFT JOIN rooms r ON t.room_id = r.room_id
            WHERE t.teacher_id = ? 
            AND t.class_id = ?
            AND t.status = 'active'
            AND s.status = 'active'
            ORDER BY t.start_time
        ");
        $stmt->execute([$teacher_id, $class_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting teacher subjects: " . $e->getMessage());
        return [];
    }
}

/* ================= GET TEACHER'S STUDENTS BY CLASS WITH STUDY MODE ================= */
function getTeacherStudentsByClass($pdo, $teacher_id, $class_id, $subject_id, $date, $academic_term_id) {
    try {
        $day = date('D', strtotime($date));
        
        // First verify teacher has this class on this day (even if time passed)
        $check = $pdo->prepare("
            SELECT timetable_id, start_time, end_time FROM timetable 
            WHERE teacher_id = ? 
            AND class_id = ? 
            AND subject_id = ?
            AND day_of_week = ?
            AND status = 'active'
        ");
        $check->execute([$teacher_id, $class_id, $subject_id, $day]);
        $timetable = $check->fetch(PDO::FETCH_ASSOC);
        
        if (!$timetable) {
            return ['error' => 'No class scheduled for this day'];
        }
        
        // Check if class is locked (using attendance table's locked column)
        $lock_check = $pdo->prepare("
            SELECT locked FROM attendance 
            WHERE teacher_id = ? AND class_id = ? AND subject_id = ? AND attendance_date = ? AND locked = 1
            LIMIT 1
        ");
        $lock_check->execute([$teacher_id, $class_id, $subject_id, $date]);
        $is_locked = $lock_check->fetch();
        
        // Check if unlocked by admin (using attendance_lock table - based on your structure)
        $unlock_check = $pdo->prepare("
            SELECT * FROM attendance_lock 
            WHERE teacher_id = ? AND subject_id = ? AND lock_date = ? AND is_locked = 0
            LIMIT 1
        ");
        $unlock_check->execute([$teacher_id, $subject_id, $date]);
        $is_unlocked = $unlock_check->fetch();
        
        $can_edit = false;
        $message = "";
        
        if ($is_locked) {
            $can_edit = false;
            $message = "This class is locked. Please request admin to unlock.";
        } elseif ($is_unlocked) {
            $can_edit = true;
            $message = "This class has been unlocked by admin. You can now edit attendance.";
        } elseif (strtotime($date) < strtotime(date('Y-m-d'))) {
            $can_edit = false;
            $message = "Class time has passed. Please request admin to unlock if you need to mark attendance.";
        } elseif ($date == date('Y-m-d')) {
            $now = time();
            $class_start = strtotime($date . ' ' . $timetable['start_time']) - 600;
            $class_end = strtotime($date . ' ' . $timetable['end_time']) + 600;
            
            if ($now < $class_start) {
                $can_edit = false;
                $message = "Class has not started yet";
            } elseif ($now > $class_end) {
                $can_edit = false;
                $message = "Class time has passed. Please request admin to unlock if you need to mark attendance.";
            } else {
                $can_edit = true;
                $message = "";
            }
        } else {
            // Future dates (should not happen due to max attribute)
            $can_edit = false;
            $message = "Cannot mark attendance for future dates";
        }
        
        // Get students with their current attendance status and study mode
        $stmt = $pdo->prepare("
            SELECT 
                s.student_id,
                s.full_name,
                s.reg_no,
                s.email,
                s.phone_number,
                se.semester_id,
                sem.semester_name,
                c.study_mode,
                COALESCE(a.status, 'present') as attendance_status,
                (
                    SELECT COUNT(*) 
                    FROM attendance a2 
                    WHERE a2.student_id = s.student_id 
                    AND a2.subject_id = ?
                    AND a2.academic_term_id = ?
                    AND a2.status = 'absent'
                ) as absence_count,
                CASE WHEN a.attendance_id IS NOT NULL THEN 1 ELSE 0 END as has_attendance
            FROM students s
            JOIN student_enroll se ON s.student_id = se.student_id
            JOIN classes c ON se.class_id = c.class_id
            LEFT JOIN semester sem ON se.semester_id = sem.semester_id
            LEFT JOIN attendance a ON a.student_id = s.student_id 
                AND a.attendance_date = ? 
                AND a.subject_id = ?
            WHERE se.class_id = ? 
            AND se.subject_id = ?
            AND se.academic_term_id = ?
            AND s.status = 'active'
            AND se.status = 'active'
            ORDER BY s.full_name
        ");
        
        $stmt->execute([
            $subject_id,
            $academic_term_id,
            $date,
            $subject_id,
            $class_id,
            $subject_id,
            $academic_term_id
        ]);
        
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'students' => $students,
            'can_edit' => $can_edit,
            'message' => $message,
            'timetable' => $timetable,
            'is_unlocked' => $is_unlocked ? true : false
        ];
        
    } catch (Exception $e) {
        error_log("Error getting students: " . $e->getMessage());
        return ['error' => $e->getMessage()];
    }
}

/* ================= REQUEST UNLOCK FUNCTION ================= */
function requestUnlock($pdo, $teacher_id, $subject_id, $date, $academic_term_id, $requested_by) {
    try {
        // Check if unlock request already exists (pending)
        $check = $pdo->prepare("
            SELECT * FROM attendance_lock 
            WHERE teacher_id = ? AND subject_id = ? AND lock_date = ? AND is_locked = 1
        ");
        $check->execute([$teacher_id, $subject_id, $date]);
        $existing = $check->fetch();
        
        if ($existing) {
            return ['success' => false, 'message' => 'Unlock request already pending'];
        }
        
        // Check if already unlocked
        $check_unlocked = $pdo->prepare("
            SELECT * FROM attendance_lock 
            WHERE teacher_id = ? AND subject_id = ? AND lock_date = ? AND is_locked = 0
        ");
        $check_unlocked->execute([$teacher_id, $subject_id, $date]);
        $unlocked = $check_unlocked->fetch();
        
        if ($unlocked) {
            return ['success' => false, 'message' => 'This class is already unlocked'];
        }
        
        // Create unlock request
        // Using your attendance_lock table structure:
        // lock_id, section_id, subject_id, teacher_id, academic_term_id, lock_date, 
        // locked_by, locked_at, unlocked_by, unlocked_at, is_locked
        $stmt = $pdo->prepare("
            INSERT INTO attendance_lock 
            (teacher_id, subject_id, lock_date, academic_term_id, locked_by, locked_at, is_locked)
            VALUES (?, ?, ?, ?, ?, NOW(), 1)
        ");
        $stmt->execute([$teacher_id, $subject_id, $date, $academic_term_id, $requested_by]);
        
        return ['success' => true, 'message' => 'Unlock request sent to admin'];
        
    } catch (Exception $e) {
        error_log("Error requesting unlock: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/* ================= CHECK IF TEACHER CAN MARK ATTENDANCE ================= */
function canMarkAttendance($pdo, $teacher_id, $date, $class_id, $subject_id, $academic_term_id) {
    try {
        $day = date('D', strtotime($date));
        
        // Check if teacher has class at this time
        $stmt = $pdo->prepare("
            SELECT timetable_id, start_time, end_time
            FROM timetable
            WHERE teacher_id = ?
            AND class_id = ?
            AND subject_id = ?
            AND day_of_week = ?
            AND status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$teacher_id, $class_id, $subject_id, $day]);
        $timetable = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$timetable) {
            return ['can_mark' => false, 'message' => 'No class scheduled for this day'];
        }
        
        // Check if teacher has checked in
        $check = $pdo->prepare("
            SELECT time_in FROM teacher_attendance 
            WHERE teacher_id = ? AND date = ?
        ");
        $check->execute([$teacher_id, $date]);
        $attendance = $check->fetch(PDO::FETCH_ASSOC);
        
        if (!$attendance || empty($attendance['time_in'])) {
            return ['can_mark' => false, 'message' => 'Please check in first'];
        }
        
        // Check if class is locked
        $lock_check = $pdo->prepare("
            SELECT locked FROM attendance 
            WHERE teacher_id = ? AND class_id = ? AND subject_id = ? AND attendance_date = ? AND locked = 1
            LIMIT 1
        ");
        $lock_check->execute([$teacher_id, $class_id, $subject_id, $date]);
        if ($lock_check->fetch()) {
            return ['can_mark' => false, 'message' => 'This class is locked. Please request admin to unlock.'];
        }
        
        // Check if unlocked by admin
        $unlock_check = $pdo->prepare("
            SELECT * FROM attendance_lock 
            WHERE teacher_id = ? AND subject_id = ? AND lock_date = ? AND is_locked = 0
            LIMIT 1
        ");
        $unlock_check->execute([$teacher_id, $subject_id, $date]);
        if ($unlock_check->fetch()) {
            return ['can_mark' => true, 'message' => '', 'timetable' => $timetable, 'unlocked' => true];
        }
        
        // Check if there's a pending unlock request
        $pending_check = $pdo->prepare("
            SELECT * FROM attendance_lock 
            WHERE teacher_id = ? AND subject_id = ? AND lock_date = ? AND is_locked = 1
            LIMIT 1
        ");
        $pending_check->execute([$teacher_id, $subject_id, $date]);
        if ($pending_check->fetch()) {
            return ['can_mark' => false, 'message' => 'Unlock request pending. Please wait for admin approval.'];
        }
        
        // For past dates, check if unlock request exists
        if (strtotime($date) < strtotime(date('Y-m-d'))) {
            return ['can_mark' => false, 'message' => 'Class time has passed. Please request admin to unlock if you need to mark attendance.'];
        }
        
        // For today, check time window
        if ($date == date('Y-m-d')) {
            $now = time();
            $class_start = strtotime($date . ' ' . $timetable['start_time']) - 600; // 10 minutes before
            $class_end = strtotime($date . ' ' . $timetable['end_time']) + 600; // 10 minutes after
            
            if ($now < $class_start) {
                return ['can_mark' => false, 'message' => 'Class has not started yet'];
            }
            
            if ($now > $class_end) {
                return ['can_mark' => false, 'message' => 'Class time has passed. Please request admin to unlock if you need to mark attendance.'];
            }
        }
        
        return ['can_mark' => true, 'message' => '', 'timetable' => $timetable];
        
    } catch (Exception $e) {
        error_log("Error checking attendance permission: " . $e->getMessage());
        return ['can_mark' => false, 'message' => 'System error'];
    }
}

/* ================= GET TEACHER'S DASHBOARD STATS ================= */
function getTeacherStats($pdo, $teacher_id, $academic_term_id) {
    try {
        $stats = [];
        
        // Total classes
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT class_id) as total_classes
            FROM timetable
            WHERE teacher_id = ? AND status = 'active'
        ");
        $stmt->execute([$teacher_id]);
        $stats['total_classes'] = $stmt->fetchColumn();
        
        // Total students
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT s.student_id) as total_students
            FROM students s
            JOIN student_enroll se ON s.student_id = se.student_id
            JOIN timetable t ON t.subject_id = se.subject_id
            WHERE t.teacher_id = ? 
            AND se.academic_term_id = ?
            AND s.status = 'active'
        ");
        $stmt->execute([$teacher_id, $academic_term_id]);
        $stats['total_students'] = $stmt->fetchColumn();
        
        // Total subjects
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT subject_id) as total_subjects
            FROM timetable
            WHERE teacher_id = ? AND status = 'active'
        ");
        $stmt->execute([$teacher_id]);
        $stats['total_subjects'] = $stmt->fetchColumn();
        
        // Today's classes
        $today = date('D');
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as today_classes
            FROM timetable
            WHERE teacher_id = ? 
            AND day_of_week = ?
            AND status = 'active'
        ");
        $stmt->execute([$teacher_id, $today]);
        $stats['today_classes'] = $stmt->fetchColumn();
        
        // Average attendance rate
        $stmt = $pdo->prepare("
            SELECT 
                AVG(CASE WHEN status = 'present' OR status = 'late' THEN 100 ELSE 0 END) as attendance_rate
            FROM attendance
            WHERE teacher_id = ? AND academic_term_id = ?
        ");
        $stmt->execute([$teacher_id, $academic_term_id]);
        $stats['attendance_rate'] = round($stmt->fetchColumn() ?: 0, 1);
        
        return $stats;
    } catch (Exception $e) {
        error_log("Error getting stats: " . $e->getMessage());
        return [
            'total_classes' => 0,
            'total_students' => 0,
            'total_subjects' => 0,
            'today_classes' => 0,
            'attendance_rate' => 0
        ];
    }
}

/* ================= ATTENDANCE CORRECTION FUNCTIONS ================= */
function requestAttendanceCorrection($student_id, $subject_id, $teacher_id, $academic_term_id, $requested_by, $reason, $reason_details, $correction_date, $original_status, $corrected_status, $pdo) {
    try {
        // Check if date is in the past (only past dates can be corrected)
        if (strtotime($correction_date) >= strtotime(date('Y-m-d'))) {
            return ['success' => false, 'message' => 'Can only request correction for past dates'];
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
        
        return ['success' => true, 'id' => $pdo->lastInsertId()];
    } catch (Exception $e) {
        error_log("Correction request error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ============================================
// MAIN EXECUTION
// ============================================

/* ================= ACTIVE TERM ================= */
$term = $pdo->query("SELECT academic_term_id FROM academic_term WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$academic_term_id = $term['academic_term_id'] ?? null;

/* ================= HANDLE AJAX REQUESTS ================= */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    if ($_GET['ajax'] == 'get_subjects') {
        $class_id = $_GET['class_id'] ?? 0;
        
        $subjects = getTeacherSubjectsByClass($pdo, $teacher_id, $class_id);
        
        echo json_encode(['success' => true, 'subjects' => $subjects]);
        exit;
    }
    
    if ($_GET['ajax'] == 'get_students') {
        $class_id = $_GET['class_id'] ?? 0;
        $subject_id = $_GET['subject_id'] ?? 0;
        $date = $_GET['date'] ?? date('Y-m-d');
        
        if (!$class_id || !$subject_id || !$date) {
            echo json_encode(['error' => 'Missing parameters']);
            exit;
        }
        
        $result = getTeacherStudentsByClass($pdo, $teacher_id, $class_id, $subject_id, $date, $academic_term_id);
        
        if (isset($result['error'])) {
            echo json_encode(['error' => $result['error']]);
        } else {
            echo json_encode([
                'success' => true, 
                'students' => $result['students'],
                'can_edit' => $result['can_edit'],
                'message' => $result['message'],
                'is_unlocked' => $result['is_unlocked'] ?? false
            ]);
        }
        exit;
    }
    
    if ($_GET['ajax'] == 'check_permission') {
        $class_id = $_GET['class_id'] ?? 0;
        $subject_id = $_GET['subject_id'] ?? 0;
        $date = $_GET['date'] ?? date('Y-m-d');
        
        $result = canMarkAttendance($pdo, $teacher_id, $date, $class_id, $subject_id, $academic_term_id);
        echo json_encode($result);
        exit;
    }
    
    if ($_GET['ajax'] == 'request_unlock') {
        $subject_id = $_GET['subject_id'] ?? 0;
        $date = $_GET['date'] ?? '';
        
        if (!$subject_id || !$date) {
            echo json_encode(['success' => false, 'message' => 'Missing parameters']);
            exit;
        }
        
        $result = requestUnlock($pdo, $teacher_id, $subject_id, $date, $academic_term_id, $user_id);
        echo json_encode($result);
        exit;
    }
    
    if ($_GET['ajax'] == 'get_pending_corrections') {
        // Get ALL corrections for this teacher (not just pending)
        $stmt = $pdo->prepare("
            SELECT 
                ac.leave_id,
                s.full_name as student_name,
                s.reg_no,
                sub.subject_name,
                ac.reason,
                ac.reason_details,
                ac.start_date,
                ac.created_at,
                ac.status
            FROM attendance_correction ac
            JOIN students s ON ac.student_id = s.student_id
            JOIN subject sub ON ac.subject_id = sub.subject_id
            WHERE ac.teacher_id = ? 
            ORDER BY ac.created_at DESC
        ");
        $stmt->execute([$teacher_id]);
        $corrections = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Parse original and corrected status
        foreach ($corrections as &$corr) {
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
        
        echo json_encode(['success' => true, 'corrections' => $corrections]);
        exit;
    }
    
    if ($_GET['ajax'] == 'get_student_attendance_for_correction') {
        $student_id = $_GET['student_id'] ?? 0;
        $date = $_GET['date'] ?? '';
        
        if (!$student_id || !$date) {
            echo json_encode(['error' => 'Missing parameters']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                a.attendance_id,
                a.subject_id,
                s.subject_name,
                a.status,
                a.attendance_date
            FROM attendance a
            JOIN subject s ON a.subject_id = s.subject_id
            WHERE a.student_id = ? 
            AND a.attendance_date = ?
            AND a.teacher_id = ?
        ");
        $stmt->execute([$student_id, $date, $teacher_id]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'records' => $records]);
        exit;
    }
}

/* ================= HANDLE POST ACTIONS ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $pdo->beginTransaction();
        $action = $_POST['action'];

        if ($action === 'mark_attendance') {
            $class_id = intval($_POST['class_id'] ?? 0);
            $subject_id = intval($_POST['subject_id'] ?? 0);
            $date = trim($_POST['date'] ?? '');
            $attendance_data = $_POST['attendance'] ?? [];
            
            if (!$class_id || !$subject_id || !$date) {
                throw new Exception("Missing required fields");
            }
            
            // Check if teacher can mark attendance
            $can_mark = canMarkAttendance($pdo, $teacher_id, $date, $class_id, $subject_id, $academic_term_id);
            if (!$can_mark['can_mark']) {
                // Allow if unlocked by admin
                $unlock_check = $pdo->prepare("
                    SELECT * FROM attendance_lock 
                    WHERE teacher_id = ? AND subject_id = ? AND lock_date = ? AND is_locked = 0
                    LIMIT 1
                ");
                $unlock_check->execute([$teacher_id, $subject_id, $date]);
                if (!$unlock_check->fetch()) {
                    throw new Exception($can_mark['message']);
                }
            }
            
            // Get subject name for emails
            $subject_stmt = $pdo->prepare("SELECT subject_name FROM subject WHERE subject_id = ?");
            $subject_stmt->execute([$subject_id]);
            $subject = $subject_stmt->fetch(PDO::FETCH_ASSOC);
            $subject_name = $subject['subject_name'] ?? 'Unknown Subject';
            
            // Track absent students for email notifications
            $absent_students = [];
            
            // Save attendance for each student
            foreach ($attendance_data as $student_id => $status) {
                // Check if attendance already exists
                $check = $pdo->prepare("
                    SELECT attendance_id, status FROM attendance 
                    WHERE student_id = ? AND attendance_date = ? AND subject_id = ?
                ");
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
                    
                    // If changing to absent, track for email
                    if ($status === 'absent' && $existing['status'] !== 'absent') {
                        $absent_students[] = $student_id;
                    }
                } else {
                    // Insert new record
                    $insert = $pdo->prepare("
                        INSERT INTO attendance 
                        (student_id, class_id, teacher_id, subject_id, academic_term_id, attendance_date, status, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $insert->execute([
                        $student_id, $class_id, $teacher_id, $subject_id, 
                        $academic_term_id, $date, $status
                    ]);
                    
                    if ($status === 'absent') {
                        $absent_students[] = $student_id;
                    }
                }
            }
            
            // Send emails for absent students
            foreach ($absent_students as $student_id) {
                // Get updated absence count
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
            
            $message = "✅ Attendance marked successfully! " . count($absent_students) . " absent student(s) notified.";
            $type = "success";
        }
        
        if ($action === 'teacher_check') {
            $check_action = $_POST['check_action'] ?? '';
            $notes = trim($_POST['notes'] ?? '');
            $date = date('Y-m-d');
            
            $check = $pdo->prepare("
                SELECT id, time_in, time_out FROM teacher_attendance 
                WHERE teacher_id = ? AND date = ?
            ");
            $check->execute([$teacher_id, $date]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);
            
            if ($check_action === 'check_in') {
                if ($existing && $existing['time_in']) {
                    throw new Exception("Already checked in today");
                }
                
                if ($existing) {
                    $update = $pdo->prepare("
                        UPDATE teacher_attendance 
                        SET time_in = NOW(), notes = ?, updated_at = NOW()
                        WHERE teacher_id = ? AND date = ?
                    ");
                    $update->execute([$notes, $teacher_id, $date]);
                } else {
                    $insert = $pdo->prepare("
                        INSERT INTO teacher_attendance 
                        (teacher_id, academic_term_id, date, time_in, notes, created_at)
                        VALUES (?, ?, ?, NOW(), ?, NOW())
                    ");
                    $insert->execute([$teacher_id, $academic_term_id, $date, $notes]);
                }
                
                $message = "✅ Checked in successfully!";
                
            } elseif ($check_action === 'check_out') {
                if (!$existing || !$existing['time_in']) {
                    throw new Exception("Please check in first");
                }
                
                if ($existing['time_out']) {
                    throw new Exception("Already checked out today");
                }
                
                $time_in = strtotime($existing['time_in']);
                $time_out = time();
                $minutes = round(($time_out - $time_in) / 60);
                
                $update = $pdo->prepare("
                    UPDATE teacher_attendance 
                    SET time_out = NOW(), minutes_worked = ?, notes = ?, updated_at = NOW()
                    WHERE teacher_id = ? AND date = ?
                ");
                $update->execute([$minutes, $notes, $teacher_id, $date]);
                
                $message = "✅ Checked out! Worked $minutes minutes.";
            }
            
            $type = "success";
        }
        
        if ($action === 'request_correction') {
            $student_id = intval($_POST['student_id'] ?? 0);
            $subject_id = intval($_POST['subject_id'] ?? 0);
            $reason = $_POST['reason'] ?? '';
            $reason_details = trim($_POST['reason_details'] ?? '');
            $correction_date = $_POST['correction_date'] ?? '';
            $original_status = $_POST['original_status'] ?? '';
            $corrected_status = $_POST['corrected_status'] ?? '';
            $requested_by = $user_id;
            
            if (!$student_id || !$reason || !$correction_date || !$original_status || !$corrected_status) {
                throw new Exception("All required fields must be filled!");
            }
            
            // Check if date is in the past
            if (strtotime($correction_date) >= strtotime(date('Y-m-d'))) {
                throw new Exception("Can only request correction for past dates!");
            }
            
            // Check if correction already exists for this student/subject/date
            $check_stmt = $pdo->prepare("
                SELECT COUNT(*) FROM attendance_correction 
                WHERE student_id = ? AND subject_id = ? AND start_date = ? AND status = 'pending'
            ");
            $check_stmt->execute([$student_id, $subject_id, $correction_date]);
            if ($check_stmt->fetchColumn() > 0) {
                throw new Exception("A correction request already exists for this student on this date!");
            }
            
            // Create correction request
            $result = requestAttendanceCorrection(
                $student_id, $subject_id, $teacher_id, $academic_term_id, 
                $requested_by, $reason, $reason_details, $correction_date, 
                $original_status, $corrected_status, $pdo
            );
            
            if ($result['success']) {
                $message = "✅ Correction request submitted successfully! It will be reviewed by admin.";
                $type = "success";
            } else {
                throw new Exception($result['message'] ?? "Failed to submit correction request.");
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
// FETCH TEACHER DATA
// ============================================

// Get teacher info
$teacher_info = $pdo->prepare("SELECT * FROM teachers WHERE teacher_id = ?");
$teacher_info->execute([$teacher_id]);
$teacher = $teacher_info->fetch(PDO::FETCH_ASSOC);

// Get teacher's classes with study mode
$teacher_classes = getTeacherClasses($pdo, $teacher_id);

// Get teacher stats
$teacher_stats = getTeacherStats($pdo, $teacher_id, $academic_term_id);

// Get today's check-in status
$today_check = $pdo->prepare("
    SELECT * FROM teacher_attendance 
    WHERE teacher_id = ? AND date = CURDATE()
");
$today_check->execute([$teacher_id]);
$today_attendance = $today_check->fetch(PDO::FETCH_ASSOC);

// Get today's schedule with study mode
$today = date('D');
$today_schedule = $pdo->prepare("
    SELECT 
        s.subject_name,
        s.subject_code,
        c.class_name,
        c.study_mode,
        t.start_time,
        t.end_time,
        r.room_name,
        r.building_name
    FROM timetable t
    JOIN subject s ON t.subject_id = s.subject_id
    JOIN classes c ON t.class_id = c.class_id
    LEFT JOIN rooms r ON t.room_id = r.room_id
    WHERE t.teacher_id = ? 
    AND t.day_of_week = ?
    AND t.status = 'active'
    ORDER BY t.start_time
");
$today_schedule->execute([$teacher_id, $today]);
$today_schedule = $today_schedule->fetchAll(PDO::FETCH_ASSOC);

// Get recent attendance records
$recent_attendance = $pdo->prepare("
    SELECT 
        a.attendance_date,
        a.status,
        s.subject_name,
        c.class_name,
        c.study_mode,
        COUNT(*) as student_count
    FROM attendance a
    JOIN subject s ON a.subject_id = s.subject_id
    JOIN classes c ON a.class_id = c.class_id
    WHERE a.teacher_id = ?
    GROUP BY a.attendance_date, a.subject_id, a.class_id, a.status
    ORDER BY a.attendance_date DESC
    LIMIT 10
");
$recent_attendance->execute([$teacher_id]);
$recent = $recent_attendance->fetchAll(PDO::FETCH_ASSOC);

$role_display = "Teacher (Macalin)";
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Attendance | Teacher Dashboard | Hormuud University</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="../images.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ===========================================
   CSS VARIABLES & RESET - from courses.php
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
  --cyan-color: #00BCD4;
  --purple-color: #6A5ACD;
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
   PAGE HEADER - from courses.php
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

.role-badge {
  background: var(--secondary-color);
  color: white;
  padding: 4px 12px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 500;
  margin-left: 10px;
}

/* ==============================
   INFO PANEL - from courses.php
============================== */
.info-panel {
  background: linear-gradient(135deg, #f5f7fa 0%, #e9edf5 100%);
  border-radius: 8px;
  padding: 15px 20px;
  margin-bottom: 20px;
  border-left: 4px solid var(--secondary-color);
  display: flex;
  align-items: center;
  gap: 15px;
  flex-wrap: wrap;
}

.info-panel i {
  font-size: 24px;
  color: var(--secondary-color);
}

.info-panel p {
  color: var(--dark-color);
  font-size: 14px;
  line-height: 1.5;
  margin: 0;
}

.info-panel strong {
  color: var(--primary-color);
}

.info-stats {
  display: flex;
  gap: 20px;
  margin-left: auto;
}

.stat-item {
  text-align: center;
}

.stat-value {
  font-size: 24px;
  font-weight: 700;
  color: var(--primary-color);
  line-height: 1.2;
}

.stat-label {
  font-size: 12px;
  color: #666;
}

/* ==============================
   STATS CARDS - from courses.php
============================== */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
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
  border-left: 4px solid var(--primary-color);
  transition: var(--transition);
}

.stat-card:hover {
  transform: translateY(-5px);
  box-shadow: 0 5px 20px rgba(0,0,0,0.1);
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
  font-size: 14px;
  margin: 0;
}

/* ==============================
   CHECK-IN CARD - custom
============================== */
.checkin-card {
  background: var(--white);
  border-radius: 12px;
  padding: 25px;
  margin-bottom: 30px;
  box-shadow: 0 4px 15px rgba(0,0,0,0.05);
  display: flex;
  justify-content: space-between;
  align-items: center;
  border-left: 4px solid var(--secondary-color);
  transition: var(--transition);
}

.checkin-card:hover {
  transform: translateY(-3px);
  box-shadow: 0 8px 25px rgba(0,0,0,0.1);
}

.checkin-info {
  display: flex;
  align-items: center;
  gap: 20px;
}

.checkin-icon {
  width: 70px;
  height: 70px;
  border-radius: 50%;
  background: <?php echo $today_attendance && $today_attendance['time_in'] ? 'linear-gradient(135deg, #48bb78, #38a169)' : 'linear-gradient(135deg, #f56565, #e53e3e)'; ?>;
  display: flex;
  align-items: center;
  justify-content: center;
  color: white;
  font-size: 30px;
  box-shadow: 0 10px 20px rgba(0,0,0,0.1);
}

.checkin-details h3 {
  font-size: 20px;
  color: var(--dark-color);
  margin-bottom: 5px;
}

.checkin-details p {
  color: #718096;
}

.checkin-actions {
  display: flex;
  gap: 15px;
}

.btn-check {
  padding: 12px 25px;
  border: none;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.3s ease;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
}

.btn-check-in {
  background: linear-gradient(135deg, #48bb78, #38a169);
  color: white;
}

.btn-check-out {
  background: linear-gradient(135deg, #f56565, #e53e3e);
  color: white;
}

.btn-check:hover:not(:disabled) {
  transform: translateY(-2px);
  box-shadow: 0 5px 15px rgba(0,0,0,0.2);
}

.btn-check:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* ==============================
   MAIN CARD - from courses.php
============================== */
.main-card {
  background: var(--white);
  border-radius: 12px;
  padding: 25px;
  margin-bottom: 30px;
  box-shadow: 0 4px 15px rgba(0,0,0,0.05);
  border: 1px solid var(--border-color);
}

.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
}

.card-header h2 {
  font-size: 20px;
  color: var(--secondary-color);
  display: flex;
  align-items: center;
  gap: 10px;
}

.card-header h2 i {
  color: var(--primary-color);
  background: rgba(0, 132, 61, 0.1);
  padding: 8px;
  border-radius: 8px;
}

/* ==============================
   FILTERS SECTION - from courses.php
============================== */
.filters {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 20px;
  margin-bottom: 25px;
}

.filter-group {
  position: relative;
}

.filter-group label {
  display: block;
  margin-bottom: 8px;
  font-weight: 500;
  font-size: 13px;
  color: #555;
}

.filter-group select,
.filter-group input {
  width: 100%;
  padding: 12px 15px;
  border: 1.5px solid #ddd;
  border-radius: 8px;
  font-size: 14px;
  transition: all 0.2s;
  background: #f9f9f9;
}

.filter-group select:focus,
.filter-group input:focus {
  outline: none;
  border-color: var(--secondary-color);
  background: #fff;
  box-shadow: 0 0 0 3px rgba(0,114,206,0.1);
}

.filter-group select:disabled {
  background: #e2e8f0;
  cursor: not-allowed;
}

.btn {
  padding: 12px 25px;
  border: none;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.3s ease;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
}

.btn-primary {
  background: var(--secondary-color);
  color: white;
}

.btn-primary:hover {
  background: #005fa3;
  transform: translateY(-2px);
  box-shadow: 0 5px 15px rgba(0,114,206,0.3);
}

.btn-success {
  background: var(--primary-color);
  color: white;
}

.btn-success:hover {
  background: #006b30;
  transform: translateY(-2px);
  box-shadow: 0 5px 15px rgba(0,132,61,0.3);
}

.btn-secondary {
  background: #6c757d;
  color: white;
}

.btn-secondary:hover {
  background: #5a6268;
  transform: translateY(-2px);
}

.btn-warning {
  background: var(--warning-color);
  color: #333;
}

.btn-warning:hover {
  background: #e0a800;
  transform: translateY(-2px);
}

.btn-danger {
  background: var(--danger-color);
  color: white;
}

.btn-danger:hover {
  background: #a81f1f;
  transform: translateY(-2px);
}

.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* ==============================
   TABLE - from courses.php
============================== */
.table-container {
  overflow-x: auto;
  border-radius: 10px;
  border: 1px solid #e2e8f0;
}

table {
  width: 100%;
  border-collapse: collapse;
  font-size: 14px;
}

th {
  background: var(--secondary-color);
  color: white;
  padding: 15px;
  text-align: left;
  font-weight: 600;
  font-size: 13px;
  white-space: nowrap;
}

td {
  padding: 15px;
  border-bottom: 1px solid #eee;
  color: #2d3748;
}

tr:hover td {
  background: #f8f9fa;
}

.student-name {
  font-weight: 600;
  color: var(--dark-color);
}

.reg-no {
  font-size: 12px;
  color: #718096;
  margin-top: 3px;
}

/* Absence badges */
.absence-badge {
  display: inline-block;
  padding: 5px 12px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
}

.absence-badge.low {
  background: #c6f6d5;
  color: #22543d;
}

.absence-badge.medium {
  background: #feebc8;
  color: #744210;
}

.absence-badge.high {
  background: #fed7d7;
  color: #742a2a;
}

/* Study Mode Badges */
.study-mode-badge {
  display: inline-block;
  padding: 4px 10px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 600;
  margin-left: 5px;
}

.study-mode-fulltime {
  background: #e3f2fd;
  color: #1565c0;
  border: 1px solid #90caf9;
}

.study-mode-parttime {
  background: #fff3e0;
  color: #ef6c00;
  border: 1px solid #ffb74d;
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

.status-badge.unlocked {
  background: #d4edda;
  color: #155724;
  border: 2px solid #28a745;
}

.status-change {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  padding: 8px 16px;
  border-radius: 8px;
  background: #f8f9fa;
  margin: 5px 0;
}

.status-change .from {
  color: #dc3545;
  font-weight: bold;
}

.status-change .to {
  color: #28a745;
  font-weight: bold;
}

.status-change .arrow {
  color: #6c757d;
}

/* Controls Bar */
.controls-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
  flex-wrap: wrap;
  gap: 15px;
  padding: 15px;
  background: #f8f9fa;
  border-radius: 8px;
}

.batch-actions {
  display: flex;
  gap: 10px;
  align-items: center;
  flex-wrap: wrap;
}

.batch-label {
  font-weight: 600;
  color: #4a5568;
  margin-right: 10px;
}

.batch-btn {
  padding: 8px 16px;
  border: 2px solid;
  border-radius: 6px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.3s ease;
  background: white;
}

.batch-btn.present {
  border-color: #48bb78;
  color: #48bb78;
}

.batch-btn.present:hover {
  background: #48bb78;
  color: white;
}

.batch-btn.absent {
  border-color: #f56565;
  color: #f56565;
}

.batch-btn.absent:hover {
  background: #f56565;
  color: white;
}

.batch-btn.late {
  border-color: #ed8936;
  color: #ed8936;
}

.batch-btn.late:hover {
  background: #ed8936;
  color: white;
}

.batch-btn.excused {
  border-color: #4299e1;
  color: #4299e1;
}

.batch-btn.excused:hover {
  background: #4299e1;
  color: white;
}

.stats-badge {
  background: white;
  padding: 8px 16px;
  border-radius: 6px;
  font-size: 14px;
  color: #4a5568;
  box-shadow: 0 2px 5px rgba(0,0,0,0.05);
}

.stats-badge i {
  color: var(--secondary-color);
  margin-right: 5px;
}

/* Attendance Radio Buttons */
.attendance-options {
  display: flex;
  gap: 10px;
  align-items: center;
  flex-wrap: wrap;
}

.attendance-option {
  display: flex;
  align-items: center;
  gap: 5px;
  cursor: pointer;
  font-size: 13px;
  padding: 5px 10px;
  border-radius: 20px;
  transition: all 0.2s ease;
}

.attendance-option:hover {
  background: #f7fafc;
}

.attendance-option input[type="radio"] {
  width: 16px;
  height: 16px;
  cursor: pointer;
  accent-color: var(--secondary-color);
}

.attendance-option.present {
  color: #48bb78;
}

.attendance-option.absent {
  color: #f56565;
}

.attendance-option.late {
  color: #ed8936;
}

.attendance-option.excused {
  color: #4299e1;
}

.attendance-option[disabled] {
  opacity: 0.5;
  cursor: not-allowed;
}

/* Schedule Cards */
.schedule-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 20px;
}

.schedule-card {
  background: #f8f9fa;
  border-radius: 10px;
  padding: 20px;
  border-left: 4px solid;
  transition: var(--transition);
}

.schedule-card.today {
  border-left-color: var(--warning-color);
  background: linear-gradient(135deg, #fff9e6, #f8f9fa);
}

.schedule-card:not(.today) {
  border-left-color: var(--secondary-color);
}

.schedule-card:hover {
  transform: translateY(-3px);
  box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.schedule-time {
  font-size: 14px;
  color: #718096;
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  gap: 5px;
}

.schedule-subject {
  font-size: 18px;
  font-weight: 600;
  color: var(--dark-color);
  margin-bottom: 5px;
}

.schedule-class {
  color: #718096;
  font-size: 14px;
  margin-bottom: 5px;
  display: flex;
  align-items: center;
  gap: 5px;
  flex-wrap: wrap;
}

.schedule-room {
  color: #a0aec0;
  font-size: 13px;
  display: flex;
  align-items: center;
  gap: 5px;
}

/* Recent Activity */
.activity-list {
  margin-top: 20px;
}

.activity-item {
  display: flex;
  align-items: center;
  gap: 15px;
  padding: 15px;
  border-bottom: 1px solid #eee;
  transition: var(--transition);
}

.activity-item:hover {
  background: #f8f9fa;
  transform: translateX(5px);
}

.activity-icon {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
  display: flex;
  align-items: center;
  justify-content: center;
  color: white;
}

.activity-content {
  flex: 1;
}

.activity-title {
  font-weight: 600;
  color: var(--dark-color);
  margin-bottom: 3px;
}

.activity-time {
  font-size: 12px;
  color: #a0aec0;
}

.activity-status {
  padding: 4px 12px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
}

.activity-status.present {
  background: #c6f6d5;
  color: #22543d;
}

.activity-status.absent {
  background: #fed7d7;
  color: #742a2a;
}

.activity-status.late {
  background: #feebc8;
  color: #744210;
}

.activity-status.excused {
  background: #bee3f8;
  color: #2a4365;
}

/* Loading Spinner */
.loading {
  display: none;
  text-align: center;
  padding: 40px;
}

.spinner {
  border: 4px solid #f3f3f3;
  border-top: 4px solid var(--secondary-color);
  border-radius: 50%;
  width: 50px;
  height: 50px;
  animation: spin 1s linear infinite;
  margin: 0 auto 20px;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

/* Empty State */
.empty-state {
  text-align: center;
  padding: 60px;
  color: #718096;
}

.empty-state i {
  font-size: 60px;
  color: #cbd5e0;
  margin-bottom: 20px;
}

.empty-state h3 {
  color: #2d3748;
  margin-bottom: 10px;
}

/* Alert */
.alert {
  position: fixed;
  top: 20px;
  right: 20px;
  padding: 15px 25px;
  border-radius: 8px;
  color: white;
  font-weight: 600;
  z-index: 9999;
  animation: slideInRight 0.3s ease;
  box-shadow: 0 4px 15px rgba(0,0,0,0.2);
}

.alert-success {
  background: var(--primary-color);
}

.alert-error {
  background: var(--danger-color);
}

@keyframes slideInRight {
  from {
    transform: translateX(100%);
    opacity: 0;
  }
  to {
    transform: translateX(0);
    opacity: 1;
  }
}

/* Modal */
.modal {
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

.modal-content {
  background: white;
  padding: 30px;
  border-radius: 12px;
  width: 90%;
  max-width: 500px;
  max-height: 80vh;
  overflow-y: auto;
}

.modal-content h3 {
  color: var(--secondary-color);
  margin-bottom: 20px;
  display: flex;
  align-items: center;
  gap: 10px;
}

.form-group {
  margin-bottom: 20px;
}

.form-group label {
  display: block;
  margin-bottom: 8px;
  font-weight: 600;
  color: #4a5568;
}

.form-group input,
.form-group select,
.form-group textarea {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid #ddd;
  border-radius: 6px;
  font-size: 14px;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
  outline: none;
  border-color: var(--secondary-color);
  box-shadow: 0 0 0 3px rgba(0,114,206,0.1);
}

/* Warning Message */
.warning-message {
  background: #fff3cd;
  color: #856404;
  padding: 15px;
  border-radius: 8px;
  margin-bottom: 20px;
  display: flex;
  align-items: center;
  gap: 10px;
  border-left: 4px solid #ffc107;
}

.warning-message i {
  font-size: 24px;
}

.warning-message p {
  margin: 0;
  flex: 1;
}

.warning-message .btn-small {
  padding: 5px 15px;
  font-size: 12px;
  background: #856404;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}

.warning-message .btn-small:hover {
  background: #6d5300;
}

/* Responsive */
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
  }
  
  .role-badge {
    display: inline-block;
    margin-left: 0;
    margin-top: 5px;
  }
  
  .filters {
    grid-template-columns: 1fr;
  }
  
  .info-panel {
    flex-direction: column;
    text-align: center;
    gap: 10px;
  }
  
  .info-stats {
    margin-left: 0;
    width: 100%;
    justify-content: center;
  }
  
  .stats-grid {
    grid-template-columns: repeat(2, 1fr);
  }
  
  .checkin-card {
    flex-direction: column;
    gap: 20px;
    text-align: center;
  }
  
  .checkin-info {
    flex-direction: column;
  }
  
  .controls-bar {
    flex-direction: column;
  }
  
  .batch-actions {
    justify-content: center;
  }
  
  .attendance-options {
    flex-wrap: wrap;
    justify-content: center;
  }
  
  .schedule-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 480px) {
  .page-header h1 {
    font-size: 20px;
  }
  
  .page-header h1 i {
    padding: 8px;
    font-size: 18px;
  }
  
  .stat-card {
    padding: 15px;
  }
  
  .stat-icon {
    width: 40px;
    height: 40px;
    font-size: 20px;
  }
  
  .stat-info h3 {
    font-size: 22px;
  }
  
  .stats-grid {
    grid-template-columns: 1fr;
  }
  
  .attendance-btn {
    min-width: 60px;
    padding: 6px 10px;
    font-size: 12px;
  }
}

/* Scrollbar */
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

/* Animations */
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}

.stat-card,
.schedule-card,
.activity-item {
  animation: fadeIn 0.4s ease forwards;
}

.stat-card:nth-child(1) { animation-delay: 0.05s; }
.stat-card:nth-child(2) { animation-delay: 0.1s; }
.stat-card:nth-child(3) { animation-delay: 0.15s; }
.stat-card:nth-child(4) { animation-delay: 0.2s; }
.stat-card:nth-child(5) { animation-delay: 0.25s; }
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
  <div class="page-header">
    <h1>
      <i class="fas fa-clipboard-check"></i> My Attendance
      <span class="role-badge"><?= htmlspecialchars($role_display) ?></span>
    </h1>
  </div>
  
  <!-- ✅ INFO PANEL -->
  <div class="info-panel">
    <i class="fas fa-info-circle"></i>
    <p>
      <strong>Macalin:</strong> Halkan waxaad ka arki kartaa <strong>attendance-ka</strong> ee ardayda. 
      Waa inaad marka hore <strong>Check In</strong> gashaa ka hor intaadan calaamadin attendance-ka.
    </p>
    <div class="info-stats">
      <div class="stat-item">
        <div class="stat-value"><?php echo $teacher_stats['total_classes']; ?></div>
        <div class="stat-label">Classes</div>
      </div>
      <div class="stat-item">
        <div class="stat-value"><?php echo $teacher_stats['today_classes']; ?></div>
        <div class="stat-label">Today</div>
      </div>
    </div>
  </div>
  
  <!-- ✅ STATS CARDS -->
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-icon"><i class="fas fa-chalkboard"></i></div>
      <div class="stat-info">
        <h3><?php echo $teacher_stats['total_classes']; ?></h3>
        <p>Total Classes</p>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon"><i class="fas fa-users"></i></div>
      <div class="stat-info">
        <h3><?php echo $teacher_stats['total_students']; ?></h3>
        <p>Students</p>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon"><i class="fas fa-book-open"></i></div>
      <div class="stat-info">
        <h3><?php echo $teacher_stats['total_subjects']; ?></h3>
        <p>Subjects</p>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon"><i class="fas fa-calendar-day"></i></div>
      <div class="stat-info">
        <h3><?php echo $teacher_stats['today_classes']; ?></h3>
        <p>Today</p>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
      <div class="stat-info">
        <h3><?php echo $teacher_stats['attendance_rate']; ?>%</h3>
        <p>Avg Rate</p>
      </div>
    </div>
  </div>
  
  <!-- ✅ CHECK-IN CARD -->
  <div class="checkin-card">
    <div class="checkin-info">
      <div class="checkin-icon">
        <i class="fas <?php echo $today_attendance && $today_attendance['time_in'] ? 'fa-check-circle' : 'fa-clock'; ?>"></i>
      </div>
      <div class="checkin-details">
        <h3><?php echo $today_attendance && $today_attendance['time_in'] ? 'Checked In' : 'Not Checked In'; ?></h3>
        <p>
          <?php if ($today_attendance && $today_attendance['time_in']): ?>
            You checked in at <?php echo date('h:i A', strtotime($today_attendance['time_in'])); ?>
            <?php if ($today_attendance['time_out']): ?>
              | Checked out at <?php echo date('h:i A', strtotime($today_attendance['time_out'])); ?>
            <?php endif; ?>
          <?php else: ?>
            Please check in before marking attendance
          <?php endif; ?>
        </p>
      </div>
    </div>
    <div class="checkin-actions">
      <form method="POST" style="display: inline;">
        <input type="hidden" name="action" value="teacher_check">
        <?php if (!$today_attendance || !$today_attendance['time_in']): ?>
          <button type="submit" name="check_action" value="check_in" class="btn-check btn-check-in">
            <i class="fas fa-sign-in-alt"></i> Check In
          </button>
        <?php elseif (!$today_attendance['time_out']): ?>
          <button type="submit" name="check_action" value="check_out" class="btn-check btn-check-out">
            <i class="fas fa-sign-out-alt"></i> Check Out
          </button>
        <?php endif; ?>
      </form>
    </div>
  </div>
  
  <!-- ✅ TODAY'S SCHEDULE -->
  <div class="main-card">
    <div class="card-header">
      <h2><i class="fas fa-calendar-day"></i> Today's Schedule</h2>
    </div>
    
    <?php if (!empty($today_schedule)): ?>
      <div class="schedule-grid">
        <?php foreach ($today_schedule as $class): ?>
          <div class="schedule-card today">
            <div class="schedule-time">
              <i class="far fa-clock"></i>
              <?php echo date('h:i A', strtotime($class['start_time'])); ?> - 
              <?php echo date('h:i A', strtotime($class['end_time'])); ?>
            </div>
            <div class="schedule-subject"><?php echo htmlspecialchars($class['subject_name']); ?></div>
            <div class="schedule-class">
              <i class="fas fa-users"></i> <?php echo htmlspecialchars($class['class_name']); ?>
              <?php if (!empty($class['study_mode'])): ?>
                <span class="study-mode-badge study-mode-<?php echo strtolower(str_replace('-', '', $class['study_mode'])); ?>">
                  <?php echo $class['study_mode']; ?>
                </span>
              <?php endif; ?>
            </div>
            <?php if (!empty($class['room_name'])): ?>
              <div class="schedule-room">
                <i class="fas fa-door-open"></i> <?php echo htmlspecialchars($class['room_name']); ?>
                <?php if (!empty($class['building_name'])): ?>
                  (<?php echo htmlspecialchars($class['building_name']); ?>)
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty-state">
        <i class="fas fa-calendar-times"></i>
        <h3>No Classes Today</h3>
        <p>You have no classes scheduled for today.</p>
      </div>
    <?php endif; ?>
  </div>
  
  <!-- ✅ ATTENDANCE CORRECTIONS SECTION (Now shows ALL corrections) -->
  <div class="main-card">
    <div class="card-header">
      <h2><i class="fas fa-edit"></i> Attendance Corrections</h2>
      <button class="btn btn-warning" onclick="openCorrectionModal()">
        <i class="fas fa-plus-circle"></i> Request Correction
      </button>
    </div>
    
    <div id="correctionsList">
      <!-- Corrections will be loaded via AJAX -->
      <div class="loading" id="correctionsLoading" style="display: none;">
        <div class="spinner" style="width: 30px; height: 30px;"></div>
        <p>Loading corrections...</p>
      </div>
      <div id="correctionsTableContainer"></div>
    </div>
  </div>
  
  <!-- ✅ MARK ATTENDANCE SECTION -->
  <div class="main-card">
    <div class="card-header">
      <h2><i class="fas fa-user-check"></i> Mark Student Attendance</h2>
    </div>
    
    <!-- Filters -->
    <div class="filters">
      <div class="filter-group">
        <label><i class="fas fa-users"></i> Select Class</label>
        <select id="classSelect">
          <option value="">Choose a class...</option>
          <?php foreach ($teacher_classes as $class): ?>
            <option value="<?php echo $class['class_id']; ?>" 
                    data-campus="<?php echo htmlspecialchars($class['campus_name']); ?>"
                    data-program="<?php echo htmlspecialchars($class['program_name']); ?>">
              <?php echo htmlspecialchars($class['class_name']); ?> 
              (<?php echo htmlspecialchars($class['campus_name']); ?>)
              <?php if (!empty($class['study_mode'])): ?>
                - <?php echo $class['study_mode']; ?>
              <?php endif; ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      
      <div class="filter-group">
        <label><i class="fas fa-book"></i> Select Subject</label>
        <select id="subjectSelect" disabled>
          <option value="">First select a class...</option>
        </select>
      </div>
      
      <div class="filter-group">
        <label><i class="fas fa-calendar"></i> Date</label>
        <input type="date" id="dateInput" value="<?php echo date('Y-m-d'); ?>" 
               max="<?php echo date('Y-m-d'); ?>">
        <small style="color: #dc3545;">* Today only</small>
      </div>
      
      <div class="filter-group" style="align-self: flex-end;">
        <button class="btn btn-primary" id="loadStudentsBtn" disabled>
          <i class="fas fa-sync-alt"></i> Load Students
        </button>
      </div>
    </div>
    
    <!-- Loading Spinner -->
    <div class="loading" id="loadingSpinner">
      <div class="spinner"></div>
      <p>Loading students...</p>
    </div>
    
    <!-- Student List -->
    <div id="studentList" style="display: none;">
      <div class="controls-bar">
        <div class="batch-actions">
          <span class="batch-label">Set all to:</span>
          <button class="batch-btn present" onclick="setAllAttendance('present')">
            <i class="fas fa-check-circle"></i> Present
          </button>
          <button class="batch-btn absent" onclick="setAllAttendance('absent')">
            <i class="fas fa-times-circle"></i> Absent
          </button>
          <button class="batch-btn late" onclick="setAllAttendance('late')">
            <i class="fas fa-clock"></i> Late
          </button>
          <button class="batch-btn excused" onclick="setAllAttendance('excused')">
            <i class="fas fa-user-clock"></i> Excused
          </button>
        </div>
        <div class="stats-badge" id="attendanceStats">
          <i class="fas fa-users"></i>
          <span id="totalStudents">0</span> students
        </div>
      </div>
      
      <form method="POST" id="attendanceForm">
        <input type="hidden" name="action" value="mark_attendance">
        <input type="hidden" name="class_id" id="formClassId">
        <input type="hidden" name="subject_id" id="formSubjectId">
        <input type="hidden" name="date" id="formDate">
        
        <div class="table-container">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Student</th>
                <th>Reg No</th>
                <th>Semester</th>
                <th>Study Mode</th>
                <th>Absences</th>
                <th>Attendance</th>
              </tr>
            </thead>
            <tbody id="studentsTableBody">
            </tbody>
          </table>
        </div>
        
        <div style="margin-top: 20px; text-align: center;">
          <div id="unlockMessage" style="margin-bottom: 10px;"></div>
          <button type="submit" class="btn btn-success" id="saveAttendanceBtn">
            <i class="fas fa-save"></i> Save Attendance
          </button>
        </div>
      </form>
    </div>
    
    <!-- Empty State -->
    <div class="empty-state" id="emptyState">
      <i class="fas fa-clipboard-list"></i>
      <h3>No Students Loaded</h3>
      <p>Select a class, subject, and date then click "Load Students"</p>
    </div>
  </div>
  
  <!-- ✅ RECENT ATTENDANCE -->
  <?php if (!empty($recent)): ?>
  <div class="main-card">
    <div class="card-header">
      <h2><i class="fas fa-history"></i> Recent Attendance</h2>
    </div>
    
    <div class="activity-list">
      <?php foreach ($recent as $activity): ?>
        <div class="activity-item">
          <div class="activity-icon">
            <i class="fas fa-calendar-check"></i>
          </div>
          <div class="activity-content">
            <div class="activity-title">
              <?php echo htmlspecialchars($activity['subject_name']); ?> - 
              <?php echo htmlspecialchars($activity['class_name']); ?>
              <?php if (!empty($activity['study_mode'])): ?>
                <span class="study-mode-badge study-mode-<?php echo strtolower(str_replace('-', '', $activity['study_mode'])); ?>" style="font-size: 10px;">
                  <?php echo $activity['study_mode']; ?>
                </span>
              <?php endif; ?>
            </div>
            <div class="activity-time">
              <i class="far fa-calendar"></i> <?php echo date('M d, Y', strtotime($activity['attendance_date'])); ?>
            </div>
          </div>
          <div class="activity-status <?php echo $activity['status']; ?>">
            <?php echo ucfirst($activity['status']); ?> (<?php echo $activity['student_count']; ?> students)
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ✅ CORRECTION MODAL -->
<div class="modal" id="correctionModal">
  <div class="modal-content">
    <h3><i class="fas fa-edit"></i> Request Attendance Correction</h3>
    <form method="POST" id="correctionForm">
      <input type="hidden" name="action" value="request_correction">
      <input type="hidden" id="correction_subject_id" name="subject_id">
      <input type="hidden" id="correction_original_status" name="original_status">
      
      <div class="form-group">
        <label>Student</label>
        <select id="correction_student_id" name="student_id" class="form-control" required>
          <option value="">Select student...</option>
          <?php
          // Get all students this teacher has marked attendance for
          $stmt = $pdo->prepare("
            SELECT DISTINCT s.student_id, s.full_name, s.reg_no
            FROM attendance a
            JOIN students s ON a.student_id = s.student_id
            WHERE a.teacher_id = ?
            ORDER BY s.full_name
          ");
          $stmt->execute([$teacher_id]);
          $students_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
          foreach ($students_list as $s): ?>
            <option value="<?php echo $s['student_id']; ?>">
              <?php echo htmlspecialchars($s['full_name']); ?> (<?php echo htmlspecialchars($s['reg_no']); ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      
      <div class="form-group">
        <label>Correction Date</label>
        <input type="date" id="correction_date" name="correction_date" class="form-control" 
               max="<?php echo date('Y-m-d', strtotime('-1 day')); ?>" required>
        <small style="color: #dc3545;">* Past dates only</small>
      </div>
      
      <div class="form-group">
        <label>Subject</label>
        <select id="correction_subject_select" class="form-control" required>
          <option value="">Select date first</option>
        </select>
      </div>
      
      <div class="form-group">
        <label>Original Status</label>
        <input type="text" id="correction_original_status_display" class="form-control" readonly>
      </div>
      
      <div class="form-group">
        <label>Corrected Status</label>
        <select name="corrected_status" class="form-control" required>
          <option value="">Select status...</option>
          <option value="present">Present</option>
          <option value="absent">Absent</option>
          <option value="late">Late</option>
          <option value="excused">Excused</option>
        </select>
      </div>
      
      <div class="form-group">
        <label>Reason</label>
        <select name="reason" class="form-control" required>
          <option value="">Select reason...</option>
          <option value="system_error">System Error</option>
          <option value="teacher_mistake">Teacher Mistake</option>
          <option value="medical">Medical Reason</option>
          <option value="other">Other</option>
        </select>
      </div>
      
      <div class="form-group">
        <label>Reason Details</label>
        <textarea name="reason_details" class="form-control" rows="3" placeholder="Provide details..."></textarea>
      </div>
      
      <div style="display: flex; gap: 10px; margin-top: 20px;">
        <button type="submit" class="btn btn-success" style="flex: 1;">
          <i class="fas fa-paper-plane"></i> Submit Request
        </button>
        <button type="button" class="btn btn-secondary" onclick="closeCorrectionModal()" style="flex: 1;">
          <i class="fas fa-times"></i> Cancel
        </button>
      </div>
    </form>
  </div>
</div>

<?php if ($message): ?>
  <div class="alert alert-<?php echo $type === 'success' ? 'success' : 'error'; ?>">
    <strong><?php echo htmlspecialchars($message); ?></strong>
  </div>
  <script>
    setTimeout(() => {
      const alert = document.querySelector('.alert');
      if (alert) alert.remove();
    }, 5000);
  </script>
<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
  // Load corrections on page load AND after any form submission
  $(document).ready(function() {
    loadCorrections();
  });
  
  // Load corrections function - now shows ALL corrections
  function loadCorrections() {
    $('#correctionsLoading').show();
    $('#correctionsTableContainer').empty();
    
    $.ajax({
      url: window.location.pathname,
      type: 'GET',
      data: {
        ajax: 'get_pending_corrections'
      },
      dataType: 'json',
      success: function(data) {
        $('#correctionsLoading').hide();
        
        if (data.success && data.corrections.length > 0) {
          let html = '<div class="table-container"><table><thead><tr>' +
                     '<th>Student</th><th>Reg No</th><th>Subject</th><th>Date</th>' +
                     '<th>Correction</th><th>Reason</th><th>Status</th></tr></thead><tbody>';
          
          data.corrections.forEach(corr => {
            let statusClass = corr.status;
            html += '<tr>' +
                    '<td>' + escapeHtml(corr.student_name) + '</td>' +
                    '<td>' + escapeHtml(corr.reg_no) + '</td>' +
                    '<td>' + escapeHtml(corr.subject_name) + '</td>' +
                    '<td>' + corr.start_date + '</td>' +
                    '<td><div class="status-change"><span class="from">' + 
                    corr.original_status + '</span> <span class="arrow"><i class="fas fa-arrow-right"></i></span> ' +
                    '<span class="to">' + corr.corrected_status + '</span></div></td>' +
                    '<td><strong>' + corr.reason + '</strong><br><small>' + 
                    escapeHtml(corr.reason_details) + '</small></td>' +
                    '<td><span class="status-badge ' + statusClass + '">' + statusClass + '</span></td>' +
                    '</tr>';
          });
          
          html += '</tbody></table></div>';
          $('#correctionsTableContainer').html(html);
        } else {
          $('#correctionsTableContainer').html('<p class="empty-state" style="padding: 20px;">No correction requests found</p>');
        }
      },
      error: function() {
        $('#correctionsLoading').hide();
        $('#correctionsTableContainer').html('<p class="empty-state" style="padding: 20px;">Error loading corrections</p>');
      }
    });
  }
  
  // Class change handler
  $('#classSelect').change(function() {
    const classId = $(this).val();
    const subjectSelect = $('#subjectSelect');
    
    if (classId) {
      subjectSelect.prop('disabled', true).html('<option value="">Loading...</option>');
      
      $.ajax({
        url: window.location.pathname,
        type: 'GET',
        data: {
          ajax: 'get_subjects',
          class_id: classId
        },
        dataType: 'json',
        success: function(data) {
          if (data.success) {
            let options = '<option value="">Select subject...</option>';
            data.subjects.forEach(subject => {
              options += `<option value="${subject.subject_id}">${subject.subject_name} (${subject.subject_code})</option>`;
            });
            subjectSelect.html(options).prop('disabled', false);
          } else {
            subjectSelect.html('<option value="">No subjects found</option>').prop('disabled', true);
          }
          checkLoadButton();
        },
        error: function() {
          subjectSelect.html('<option value="">Error loading subjects</option>').prop('disabled', true);
          checkLoadButton();
        }
      });
    } else {
      subjectSelect.html('<option value="">First select a class...</option>').prop('disabled', true);
      checkLoadButton();
    }
  });
  
  // Subject and date change handlers
  $('#subjectSelect, #dateInput').change(checkLoadButton);
  
  function checkLoadButton() {
    const classId = $('#classSelect').val();
    const subjectId = $('#subjectSelect').val();
    const date = $('#dateInput').val();
    
    $('#loadStudentsBtn').prop('disabled', !(classId && subjectId && date));
  }
  
  // Load students
  $('#loadStudentsBtn').click(function() {
    const classId = $('#classSelect').val();
    const subjectId = $('#subjectSelect').val();
    const date = $('#dateInput').val();
    
    // Show loading
    $('#loadingSpinner').show();
    $('#studentList').hide();
    $('#emptyState').hide();
    $('#unlockMessage').empty();
    
    // Load students (bypass permission check - we'll show them anyway)
    $.ajax({
      url: window.location.pathname,
      type: 'GET',
      data: {
        ajax: 'get_students',
        class_id: classId,
        subject_id: subjectId,
        date: date
      },
      success: function(data) {
        $('#loadingSpinner').hide();
        
        if (data.error) {
          alert(data.error);
          $('#emptyState').show();
        } else {
          displayStudents(data.students, data.can_edit, data.message, data.is_unlocked);
          $('#formClassId').val(classId);
          $('#formSubjectId').val(subjectId);
          $('#formDate').val(date);
          
          // If cannot edit, show unlock message
          if (!data.can_edit && data.message) {
            $('#unlockMessage').html(`
              <div class="warning-message">
                <i class="fas fa-exclamation-triangle"></i>
                <p>${data.message}</p>
                <button class="btn-small" onclick="requestUnlock(${subjectId}, '${date}')">
                  <i class="fas fa-unlock-alt"></i> Request Unlock
                </button>
              </div>
            `);
          } else if (data.is_unlocked) {
            $('#unlockMessage').html(`
              <div class="warning-message" style="background: #d4edda; color: #155724; border-left-color: #28a745;">
                <i class="fas fa-check-circle"></i>
                <p>This class has been unlocked by admin. You can now edit attendance.</p>
              </div>
            `);
          }
        }
      },
      error: function() {
        $('#loadingSpinner').hide();
        $('#emptyState').show();
        alert('Error loading students');
      }
    });
  });
  
  function requestUnlock(subjectId, date) {
    if (!confirm('Are you sure you want to request admin to unlock this class?')) {
      return;
    }
    
    $.ajax({
      url: window.location.pathname,
      type: 'GET',
      data: {
        ajax: 'request_unlock',
        subject_id: subjectId,
        date: date
      },
      success: function(data) {
        if (data.success) {
          alert(data.message);
          // Reload students to show updated status
          $('#loadStudentsBtn').click();
        } else {
          alert('Error: ' + data.message);
        }
      },
      error: function() {
        alert('Error requesting unlock');
      }
    });
  }
  
  function displayStudents(students, canEdit, message, isUnlocked) {
    const tbody = $('#studentsTableBody');
    tbody.empty();
    
    if (students.length === 0) {
      tbody.append('<tr><td colspan="7" style="text-align: center; padding: 40px;">No students found for this class/subject</td></tr>');
      $('#totalStudents').text(0);
      $('#studentList').show();
      $('#emptyState').hide();
      $('#saveAttendanceBtn').prop('disabled', true);
      return;
    }
    
    students.forEach((student, index) => {
      // Determine absence badge class
      let badgeClass = 'low';
      if (student.absence_count >= 5) badgeClass = 'high';
      else if (student.absence_count >= 3) badgeClass = 'medium';
      
      // Study mode badge
      let studyModeBadge = '';
      if (student.study_mode) {
        const modeClass = student.study_mode === 'Full-Time' ? 'study-mode-fulltime' : 'study-mode-parttime';
        studyModeBadge = `<span class="study-mode-badge ${modeClass}">${student.study_mode}</span>`;
      }
      
      // Disable radio buttons if cannot edit
      const disabled = !canEdit ? 'disabled' : '';
      const readonlyClass = !canEdit ? 'attendance-option[disabled]' : '';
      
      const row = `
        <tr>
          <td>${index + 1}</td>
          <td>
            <div class="student-name">${escapeHtml(student.full_name)}</div>
            <div class="reg-no">${escapeHtml(student.reg_no)}</div>
          </td>
          <td>${escapeHtml(student.reg_no)}</td>
          <td>${escapeHtml(student.semester_name || 'N/A')}</td>
          <td>${studyModeBadge || 'N/A'}</td>
          <td>
            <span class="absence-badge ${badgeClass}">
              ${student.absence_count} / 5
            </span>
          </td>
          <td>
            <div class="attendance-options">
              <label class="attendance-option present ${readonlyClass}">
                <input type="radio" name="attendance[${student.student_id}]" 
                       value="present" ${student.attendance_status === 'present' ? 'checked' : ''} ${disabled}>
                Present
              </label>
              <label class="attendance-option absent ${readonlyClass}">
                <input type="radio" name="attendance[${student.student_id}]" 
                       value="absent" ${student.attendance_status === 'absent' ? 'checked' : ''} ${disabled}>
                Absent
              </label>
              <label class="attendance-option late ${readonlyClass}">
                <input type="radio" name="attendance[${student.student_id}]" 
                       value="late" ${student.attendance_status === 'late' ? 'checked' : ''} ${disabled}>
                Late
              </label>
              <label class="attendance-option excused ${readonlyClass}">
                <input type="radio" name="attendance[${student.student_id}]" 
                       value="excused" ${student.attendance_status === 'excused' ? 'checked' : ''} ${disabled}>
                Excused
              </label>
            </div>
          </td>
        </tr>
      `;
      tbody.append(row);
    });
    
    $('#totalStudents').text(students.length);
    $('#studentList').show();
    $('#emptyState').hide();
    
    // Disable save button if cannot edit
    if (!canEdit) {
      $('#saveAttendanceBtn').prop('disabled', true);
    } else {
      $('#saveAttendanceBtn').prop('disabled', false);
    }
  }
  
  // Set all students to same attendance status (only if enabled)
  function setAllAttendance(status) {
    const canEdit = !$('#saveAttendanceBtn').prop('disabled');
    if (!canEdit) {
      alert('Cannot edit attendance. Please request unlock first.');
      return;
    }
    $(`input[type="radio"][value="${status}"]:not(:disabled)`).prop('checked', true);
  }
  
  // Escape HTML to prevent XSS
  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }
  
  // Confirm before submitting attendance
  $('#attendanceForm').submit(function(e) {
    const totalStudents = $('#totalStudents').text();
    if (!confirm(`Are you sure you want to save attendance for ${totalStudents} students?`)) {
      e.preventDefault();
    }
  });
  
  // Correction modal functions
  function openCorrectionModal() {
    $('#correctionModal').show();
    $('#correction_date').val('');
    $('#correction_subject_select').html('<option value="">Select date first</option>').prop('disabled', true);
    $('#correction_original_status_display').val('');
    $('#correction_subject_id').val('');
    $('#correction_original_status').val('');
  }
  
  function closeCorrectionModal() {
    $('#correctionModal').hide();
    $('#correctionForm')[0].reset();
    // Reload corrections after closing modal
    loadCorrections();
  }
  
  // Load subjects when date changes in correction modal
  $('#correction_date').change(function() {
    const studentId = $('#correction_student_id').val();
    const date = $(this).val();
    
    if (!studentId || !date) {
      $('#correction_subject_select').html('<option value="">Select student and date first</option>').prop('disabled', true);
      return;
    }
    
    $('#correction_subject_select').html('<option value="">Loading...</option>').prop('disabled', true);
    
    // Get attendance records for this student on this date
    $.ajax({
      url: window.location.pathname,
      type: 'GET',
      data: {
        ajax: 'get_student_attendance_for_correction',
        student_id: studentId,
        date: date
      },
      dataType: 'json',
      success: function(data) {
        if (data.success && data.records.length > 0) {
          let options = '<option value="">Select subject...</option>';
          data.records.forEach(record => {
            options += `<option value="${record.attendance_id}" 
                              data-subject="${record.subject_id}" 
                              data-status="${record.status}">
                          ${record.subject_name} (${record.status})
                        </option>`;
          });
          $('#correction_subject_select').html(options).prop('disabled', false);
        } else {
          $('#correction_subject_select').html('<option value="">No attendance records for this date</option>').prop('disabled', true);
        }
      },
      error: function() {
        $('#correction_subject_select').html('<option value="">Error loading records</option>').prop('disabled', true);
      }
    });
  });
  
  // When subject is selected in correction modal
  $('#correction_subject_select').change(function() {
    const selected = $(this).find(':selected');
    $('#correction_subject_id').val(selected.data('subject') || '');
    $('#correction_original_status').val(selected.data('status') || '');
    $('#correction_original_status_display').val(selected.data('status') || '');
  });
  
  // Validate correction form
  $('#correctionForm').submit(function(e) {
    const correctedStatus = $('select[name="corrected_status"]').val();
    const originalStatus = $('#correction_original_status').val();
    
    if (!correctedStatus) {
      alert('Please select corrected status');
      e.preventDefault();
      return;
    }
    
    if (correctedStatus === originalStatus) {
      alert('Corrected status must be different from original status');
      e.preventDefault();
      return;
    }
    
    // Show loading on submit
    $(this).find('button[type="submit"]').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Submitting...');
  });
  
  // Close modal when clicking outside
  window.onclick = function(event) {
    const modal = document.getElementById('correctionModal');
    if (event.target === modal) {
      closeCorrectionModal();
    }
  };
  
  // Reload corrections after form submission (handled by page reload)
  // But if AJAX form, we handle it above
</script>

<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>