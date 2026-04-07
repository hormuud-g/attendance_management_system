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

// ✅ PHPMailer Configuration
require_once(__DIR__ . '/../lib/PHPMailer-master/src/Exception.php');
require_once(__DIR__ . '/../lib/PHPMailer-master/src/SMTP.php');
require_once(__DIR__ . '/../lib/PHPMailer-master/src/PHPMailer.php');

// ✅ Stronger Access Control
if (!isset($_SESSION['user']) || strtolower($_SESSION['user']['role']) !== 'super_admin') {
    session_destroy();
    header("Location: ../login.php?error=unauthorized");
    exit;
}

date_default_timezone_set('Africa/Nairobi');
$message = ""; $type = "";

/* ================= SIMPLIFIED EMAIL SENDING FUNCTION ================= */
function sendEmail($to, $subject, $message, $student_id, $message_type, $absence_count, $pdo) {
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = 'tls';
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';
        
        // Recipients
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($to);
        
        // Content
        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body = $message;
        
        if ($mail->send()) {
            // Simple log entry
            $log_stmt = $pdo->prepare("
                INSERT INTO email_logs 
                (student_id, recipient_email, message_type, absence_count, status, sent_at)
                VALUES (?, ?, ?, ?, 'sent', NOW())
            ");
            $log_stmt->execute([$student_id, $to, $message_type, $absence_count]);
            return true;
        }
        return false;
    } catch (Exception $e) {
        // Minimal error logging
        error_log("Email failed: " . $e->getMessage());
        return false;
    }
}

/* ================= DIRECT ABSENCE EMAIL SENDER ================= */
function sendDirectAbsenceEmail($student_id, $pdo) {
    // Get student info
    $stmt = $pdo->prepare("
        SELECT 
            s.full_name,
            s.email as student_email,
            p.email as parent_email,
            p.full_name
        FROM students s
        LEFT JOIN parents p ON s.parent_id = p.parent_id
        WHERE s.student_id = ?
    ");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) return false;
    
    $full_name = $student['full_name'];
    $student_email = $student['student_email'];
    $parent_email = $student['parent_email'];
    $parent_name = $student['full_name'] ?? 'Waalid';
    
    // Simple email content
    $student_subject = "Absence Notification";
    $student_message = "Dear $full_name,\n\nYou have been marked absent today.\n\nAcademic Affairs Department";
    
    $parent_subject = "Ardaygaagu Maqan Yahay";
    $parent_message = "Mudane/Marwo $parent_name,\n\nArdaygaaga $full_name maanta maqan yahay.\n\nMahadsanid,\nMaamulka Waxbarashada";
    
    $emails_sent = 0;
    
    // Send to student
    if (!empty($student_email) && filter_var($student_email, FILTER_VALIDATE_EMAIL)) {
        if (sendEmail($student_email, $student_subject, $student_message, $student_id, 'absence', 1, $pdo)) {
            $emails_sent++;
        }
    }
    
    // Send to parent
    if (!empty($parent_email) && filter_var($parent_email, FILTER_VALIDATE_EMAIL)) {
        if (sendEmail($parent_email, $parent_subject, $parent_message, $student_id, 'absence', 1, $pdo)) {
            $emails_sent++;
        }
    }
    
    return $emails_sent > 0;
}

/* ================= ATTENDANCE NOTIFICATION FOR CUMULATIVE ABSENCES ================= */
function sendCumulativeAbsenceEmail($student_id, $absence_count, $subject_name, $academic_term_id, $pdo) {
    try {
        // Get student details
        $stmt = $pdo->prepare("
            SELECT 
                s.full_name,
                s.email as student_email,
                p.email as parent_email,
                p.full_name
            FROM students s
            LEFT JOIN parents p ON s.parent_id = p.parent_id
            WHERE s.student_id = ?
        ");
        $stmt->execute([$student_id]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student) return false;
        
        $full_name = $student['full_name'];
        $student_email = $student['student_email'];
        $parent_email = $student['parent_email'];
        $parent_name = $student['full_name'] ?? 'Waalid';
        
        // For 5+ absences
        if ($absence_count >= 5) {
            $email_student_subject = "Course Attendance Closure Notice";
            $email_parent_subject = "Ogaaysiis Xeritaan Cashar";
            
            $email_student_message = "Dear $full_name,\n\nYour attendance has reached $absence_count absences. You are required to retake the course.\n\nContact the academic office for guidance.\n\nSincerely,\nAcademic Affairs Department";
            
            $email_parent_message = "Mudane/Marwo $parent_name,\n\nAttendance-ka ardaygaaga $full_name wuu gaaray $absence_count maqnaansho. Waa inuu dib u qaato casharka.\n\nMahadsanid,\nMaamulka Waxbarashada";
            
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
        return false;
    }
}

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
    // GET FACULTIES BY CAMPUS
    if ($_GET['ajax'] == 'get_faculties_by_campus') {
        $campus_id = $_GET['campus_id'] ?? 0;
        
        $stmt = $pdo->prepare("
            SELECT DISTINCT f.faculty_id, f.faculty_name 
            FROM faculties f
            JOIN faculty_campus fc ON f.faculty_id = fc.faculty_id
            WHERE fc.campus_id = ?
            AND f.status = 'active'
            ORDER BY f.faculty_name
        ");
        $stmt->execute([$campus_id]);
        $faculties = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'faculties' => $faculties]);
        exit;
    }
    
    // GET DEPARTMENTS BY FACULTY & CAMPUS
    if ($_GET['ajax'] == 'get_departments_by_faculty') {
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        $stmt = $pdo->prepare("
            SELECT department_id, department_name 
            FROM departments 
            WHERE faculty_id = ? 
            AND campus_id = ?
            AND status = 'active'
            ORDER BY department_name
        ");
        $stmt->execute([$faculty_id, $campus_id]);
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'departments' => $departments]);
        exit;
    }
    
    // GET PROGRAMS BY DEPARTMENT, FACULTY & CAMPUS
    if ($_GET['ajax'] == 'get_programs_by_department') {
        $department_id = $_GET['department_id'] ?? 0;
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        $stmt = $pdo->prepare("
            SELECT program_id, program_name 
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
    
    // GET CLASSES BY PROGRAM, DEPARTMENT, FACULTY & CAMPUS
    if ($_GET['ajax'] == 'get_classes_by_program') {
        $program_id = $_GET['program_id'] ?? 0;
        $department_id = $_GET['department_id'] ?? 0;
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        $stmt = $pdo->prepare("
            SELECT class_id, class_name 
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
        
        echo json_encode(['status' => 'success', 'classes' => $classes]);
        exit;
    }
    
    // GET STUDENTS BY CLASS AND CAMPUS (INCLUDING RECOURSE STUDENTS)
    if ($_GET['ajax'] == 'get_students_by_class') {
        $class_id = $_GET['class_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        $stmt = $pdo->prepare("
            SELECT 
                s.student_id, 
                s.full_name, 
                s.reg_no,
                s.email as student_email,
                s.phone_number as student_phone,
                p.email as parent_email,
                p.phone as parent_phone,
                se.semester_id,
                sem.semester_name
            FROM students s
            JOIN student_enroll se ON se.student_id = s.student_id
            LEFT JOIN semester sem ON sem.semester_id = se.semester_id
            LEFT JOIN parents p ON s.parent_id = p.parent_id
            WHERE se.class_id = ? 
            AND se.campus_id = ?
            AND s.status IN ('active', 'recourse')
            ORDER BY s.full_name
        ");
        $stmt->execute([$class_id, $campus_id]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'students' => $students]);
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
                    $check = $pdo->prepare("SELECT attendance_id FROM attendance WHERE student_id=? AND attendance_date=? AND subject_id=?");
                    $check->execute([$student_id, $date, $subject_id]);
                    if ($check->fetch()) throw new Exception("Attendance already saved. Please unlock before editing.");
                    
                    // Insert attendance record
                    $insert = $pdo->prepare("
                        INSERT INTO attendance 
                        (student_id, class_id, teacher_id, subject_id, academic_term_id, attendance_date, status, created_at)
                        VALUES (?,?,?,?,?,?,?,NOW())
                    ");
                    $insert->execute([$student_id, $class_id, $teacher_id, $subject_id, $academic_term_id, $date, $status]);
                    
                    // ✅ IMMEDIATE EMAIL FOR ABSENCES
                    if ($status === 'absent') {
                        // Send immediate absence email
                        sendDirectAbsenceEmail($student_id, $pdo);
                        
                        // Check for cumulative absences
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
                        
                        // Send recourse email for 5+ absences
                        if ($absence_count >= 5) {
                            sendCumulativeAbsenceEmail($student_id, $absence_count, $subject_name, $academic_term_id, $pdo);
                        }
                    }
                }
                $message = "✅ Student attendance saved successfully!";
            }
            $type = "success";
        }

        /* ============================================
           👨‍🏫 TEACHER ATTENDANCE HANDLING
           ============================================ */
        if ($action === 'teacher_save' || $action === 'teacher_unlock') {
            $io_action = $_POST['io_action'] ?? '';
            $note = trim($_POST['notes'] ?? '');
            $day = date('D', strtotime($date));

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

        $pdo->commit();

    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "❌ Error: " . $e->getMessage();
        $type = "error";
    }
}

/* ================= FETCH DATA ================= */

// ✅ Teachers
$teachers = $pdo->query("SELECT teacher_id, teacher_name FROM teachers WHERE status='active' ORDER BY teacher_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// ✅ Campuses
$campuses = $pdo->query("SELECT campus_id, campus_name FROM campus WHERE status='active' ORDER BY campus_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// ✅ Cascade filters
$faculties = $departments = $programs = $classes = [];

// If POST data exists, load the hierarchy
if (!empty($_POST['campus_id'])) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT f.faculty_id, f.faculty_name 
        FROM faculties f
        JOIN faculty_campus fc ON f.faculty_id = fc.faculty_id
        WHERE fc.campus_id = ?
        AND f.status = 'active'
        ORDER BY f.faculty_name
    ");
    $stmt->execute([$_POST['campus_id']]);
    $faculties = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (!empty($_POST['faculty_id']) && !empty($_POST['campus_id'])) {
    $stmt = $pdo->prepare("
        SELECT department_id, department_name 
        FROM departments 
        WHERE faculty_id = ? 
        AND campus_id = ?
        AND status = 'active'
        ORDER BY department_name
    ");
    $stmt->execute([$_POST['faculty_id'], $_POST['campus_id']]);
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (!empty($_POST['department_id']) && !empty($_POST['faculty_id']) && !empty($_POST['campus_id'])) {
    $stmt = $pdo->prepare("
        SELECT program_id, program_name 
        FROM programs 
        WHERE department_id = ? 
        AND faculty_id = ?
        AND campus_id = ?
        AND status = 'active'
        ORDER BY program_name
    ");
    $stmt->execute([
        $_POST['department_id'],
        $_POST['faculty_id'],
        $_POST['campus_id']
    ]);
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (!empty($_POST['program_id']) && !empty($_POST['department_id']) && !empty($_POST['faculty_id']) && !empty($_POST['campus_id'])) {
    $stmt = $pdo->prepare("
        SELECT class_id, class_name 
        FROM classes 
        WHERE program_id = ? 
        AND department_id = ?
        AND faculty_id = ?
        AND campus_id = ?
        AND status = 'Active'
        ORDER BY class_name
    ");
    $stmt->execute([
        $_POST['program_id'],
        $_POST['department_id'],
        $_POST['faculty_id'],
        $_POST['campus_id']
    ]);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* 👩‍🎓 Load Students if timetable exists */
$students = [];
if (isset($_POST['load_students'])) {
    $class_id = intval($_POST['class_id'] ?? 0);
    $teacher_id = intval($_POST['teacher_id'] ?? 0);
    $campus_id = intval($_POST['campus_id'] ?? 0);
    $date = $_POST['date'] ?? date('Y-m-d');
    $day = date('D', strtotime($date));

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
        // Load students including recourse
        $stmt = $pdo->prepare("
            SELECT 
                s.student_id, 
                s.full_name, 
                s.reg_no,
                s.email as student_email,
                s.phone_number as student_phone,
                p.email as parent_email,
                p.phone as parent_phone,
                se.semester_id,
                sem.semester_name,
                a.status as attendance_status
            FROM students s
            JOIN student_enroll se ON se.student_id = s.student_id
            LEFT JOIN semester sem ON sem.semester_id = se.semester_id
            LEFT JOIN parents p ON s.parent_id = p.parent_id
            LEFT JOIN attendance a ON a.student_id = s.student_id 
                AND a.attendance_date = ? 
                AND a.class_id = ?
            WHERE se.class_id = ? 
            AND se.campus_id = ?
            AND s.status IN ('active', 'recourse')
            ORDER BY s.full_name
        ");
        $stmt->execute([$date, $class_id, $class_id, $campus_id]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ✅ Manual email sending trigger
if (isset($_GET['send_absence_emails']) && $_GET['send_absence_emails'] == 'now') {
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT DISTINCT student_id 
        FROM attendance 
        WHERE attendance_date = ? AND status = 'absent'
    ");
    $stmt->execute([$today]);
    $absent_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $sent_count = 0;
    foreach ($absent_students as $student) {
        if (sendDirectAbsenceEmail($student['student_id'], $pdo)) {
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
    <title>Attendance Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .attendance-btn { width: 100px; }
        .present-btn { background-color: #28a745; color: white; }
        .absent-btn { background-color: #dc3545; color: white; }
        .selected-present { background-color: #155724 !important; }
        .selected-absent { background-color: #721c24 !important; }
        .hierarchy-select { margin-bottom: 10px; }
        .student-row:hover { background-color: #f8f9fa; }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <h2 class="mb-4">📊 Attendance Management System</h2>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $type === 'success' ? 'success' : 'danger'; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <!-- Quick Actions -->
        <div class="mb-4">
            <a href="?send_absence_emails=now" class="btn btn-warning btn-sm">
                📧 Send Absence Emails for Today
            </a>
        </div>

        <!-- Attendance Form -->
        <form method="post" class="card p-4 mb-4">
            <div class="row">
                <div class="col-md-3">
                    <label>Teacher</label>
                    <select name="teacher_id" class="form-select" required>
                        <option value="">Select Teacher</option>
                        <?php foreach ($teachers as $t): ?>
                            <option value="<?php echo $t['teacher_id']; ?>" <?php echo ($_POST['teacher_id'] ?? '') == $t['teacher_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t['teacher_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label>Campus</label>
                    <select name="campus_id" id="campus_id" class="form-select" required>
                        <option value="">Select Campus</option>
                        <?php foreach ($campuses as $c): ?>
                            <option value="<?php echo $c['campus_id']; ?>" <?php echo ($_POST['campus_id'] ?? '') == $c['campus_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['campus_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label>Date</label>
                    <input type="date" name="date" class="form-control" value="<?php echo $_POST['date'] ?? date('Y-m-d'); ?>" required>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" name="load_students" class="btn btn-primary w-100">Load Students</button>
                </div>
            </div>

            <!-- Hierarchy Filters -->
            <div class="row mt-3">
                <div class="col-md-3">
                    <label>Faculty</label>
                    <select name="faculty_id" id="faculty_id" class="form-select hierarchy-select">
                        <option value="">Select Faculty</option>
                        <?php foreach ($faculties as $f): ?>
                            <option value="<?php echo $f['faculty_id']; ?>" <?php echo ($_POST['faculty_id'] ?? '') == $f['faculty_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($f['faculty_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label>Department</label>
                    <select name="department_id" id="department_id" class="form-select hierarchy-select">
                        <option value="">Select Department</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?php echo $d['department_id']; ?>" <?php echo ($_POST['department_id'] ?? '') == $d['department_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($d['department_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label>Program</label>
                    <select name="program_id" id="program_id" class="form-select hierarchy-select">
                        <option value="">Select Program</option>
                        <?php foreach ($programs as $p): ?>
                            <option value="<?php echo $p['program_id']; ?>" <?php echo ($_POST['program_id'] ?? '') == $p['program_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($p['program_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label>Class</label>
                    <select name="class_id" id="class_id" class="form-select hierarchy-select">
                        <option value="">Select Class</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?php echo $c['class_id']; ?>" <?php echo ($_POST['class_id'] ?? '') == $c['class_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </form>

        <!-- Teacher Attendance -->
        <div class="card p-4 mb-4">
            <h5>👨‍🏫 Teacher Attendance</h5>
            <form method="post">
                <input type="hidden" name="teacher_id" value="<?php echo $_POST['teacher_id'] ?? ''; ?>">
                <input type="hidden" name="date" value="<?php echo $_POST['date'] ?? date('Y-m-d'); ?>">
                <div class="row">
                    <div class="col-md-4">
                        <label>Action</label>
                        <select name="io_action" class="form-select" required>
                            <option value="">Select Action</option>
                            <option value="In">Time IN</option>
                            <option value="Out">Time OUT</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label>Notes</label>
                        <input type="text" name="notes" class="form-control" placeholder="Optional notes...">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" name="action" value="teacher_save" class="btn btn-success w-100">Save</button>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" name="action" value="teacher_unlock" class="btn btn-warning w-100">Unlock</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Student Attendance Table -->
        <?php if (!empty($students)): ?>
            <div class="card p-4">
                <h5>👩‍🎓 Student Attendance (<?php echo count($students); ?> students)</h5>
                <form method="post" id="studentAttendanceForm">
                    <input type="hidden" name="teacher_id" value="<?php echo $_POST['teacher_id'] ?? ''; ?>">
                    <input type="hidden" name="class_id" value="<?php echo $_POST['class_id'] ?? ''; ?>">
                    <input type="hidden" name="campus_id" value="<?php echo $_POST['campus_id'] ?? ''; ?>">
                    <input type="hidden" name="date" value="<?php echo $_POST['date'] ?? date('Y-m-d'); ?>">
                    
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Student Name</th>
                                    <th>Reg No</th>
                                    <th>Email</th>
                                    <th>Parent Email</th>
                                    <th>Attendance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $index => $student): ?>
                                    <tr class="student-row">
                                        <td><?php echo $index + 1; ?></td>
                                        <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($student['reg_no']); ?></td>
                                        <td><?php echo htmlspecialchars($student['student_email']); ?></td>
                                        <td><?php echo htmlspecialchars($student['parent_email']); ?></td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <input type="radio" class="btn-check" name="attendance[<?php echo $student['student_id']; ?>]" 
                                                       id="present_<?php echo $student['student_id']; ?>" value="present" 
                                                       <?php echo ($student['attendance_status'] ?? '') == 'present' ? 'checked' : ''; ?>>
                                                <label class="btn btn-outline-success attendance-btn present-btn" 
                                                       for="present_<?php echo $student['student_id']; ?>">Present</label>
                                                
                                                <input type="radio" class="btn-check" name="attendance[<?php echo $student['student_id']; ?>]" 
                                                       id="absent_<?php echo $student['student_id']; ?>" value="absent"
                                                       <?php echo ($student['attendance_status'] ?? '') == 'absent' ? 'checked' : ''; ?>>
                                                <label class="btn btn-outline-danger attendance-btn absent-btn" 
                                                       for="absent_<?php echo $student['student_id']; ?>">Absent</label>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="mt-3">
                        <button type="submit" name="action" value="student_save" class="btn btn-success btn-lg">Save Attendance</button>
                        <button type="submit" name="action" value="student_unlock" class="btn btn-warning btn-lg">Unlock Attendance</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            // Hierarchy cascade loading
            $('#campus_id').change(function() {
                var campusId = $(this).val();
                if (campusId) {
                    $.get('?ajax=get_faculties_by_campus&campus_id=' + campusId, function(data) {
                        var faculties = JSON.parse(data).faculties;
                        $('#faculty_id').html('<option value="">Select Faculty</option>');
                        faculties.forEach(function(faculty) {
                            $('#faculty_id').append('<option value="' + faculty.faculty_id + '">' + faculty.faculty_name + '</option>');
                        });
                        $('#department_id, #program_id, #class_id').html('<option value="">Select...</option>');
                    });
                }
            });

            $('#faculty_id').change(function() {
                var facultyId = $(this).val();
                var campusId = $('#campus_id').val();
                if (facultyId && campusId) {
                    $.get('?ajax=get_departments_by_faculty&faculty_id=' + facultyId + '&campus_id=' + campusId, function(data) {
                        var departments = JSON.parse(data).departments;
                        $('#department_id').html('<option value="">Select Department</option>');
                        departments.forEach(function(dept) {
                            $('#department_id').append('<option value="' + dept.department_id + '">' + dept.department_name + '</option>');
                        });
                        $('#program_id, #class_id').html('<option value="">Select...</option>');
                    });
                }
            });

            $('#department_id').change(function() {
                var deptId = $(this).val();
                var facultyId = $('#faculty_id').val();
                var campusId = $('#campus_id').val();
                if (deptId && facultyId && campusId) {
                    $.get('?ajax=get_programs_by_department&department_id=' + deptId + '&faculty_id=' + facultyId + '&campus_id=' + campusId, function(data) {
                        var programs = JSON.parse(data).programs;
                        $('#program_id').html('<option value="">Select Program</option>');
                        programs.forEach(function(program) {
                            $('#program_id').append('<option value="' + program.program_id + '">' + program.program_name + '</option>');
                        });
                        $('#class_id').html('<option value="">Select...</option>');
                    });
                }
            });

            $('#program_id').change(function() {
                var programId = $(this).val();
                var deptId = $('#department_id').val();
                var facultyId = $('#faculty_id').val();
                var campusId = $('#campus_id').val();
                if (programId && deptId && facultyId && campusId) {
                    $.get('?ajax=get_classes_by_program&program_id=' + programId + '&department_id=' + deptId + '&faculty_id=' + facultyId + '&campus_id=' + campusId, function(data) {
                        var classes = JSON.parse(data).classes;
                        $('#class_id').html('<option value="">Select Class</option>');
                        classes.forEach(function(cls) {
                            $('#class_id').append('<option value="' + cls.class_id + '">' + cls.class_name + '</option>');
                        });
                    });
                }
            });

            // Attendance button styling
            $('.btn-check').change(function() {
                var btn = $(this).next('label');
                if ($(this).is(':checked')) {
                    if ($(this).val() === 'present') {
                        btn.removeClass('btn-outline-success').addClass('selected-present');
                    } else {
                        btn.removeClass('btn-outline-danger').addClass('selected-absent');
                    }
                }
            });

            // Initialize checked buttons
            $('.btn-check:checked').each(function() {
                var btn = $(this).next('label');
                if ($(this).val() === 'present') {
                    btn.removeClass('btn-outline-success').addClass('selected-present');
                } else {
                    btn.removeClass('btn-outline-danger').addClass('selected-absent');
                }
            });
        });
    </script>
</body>
</html>