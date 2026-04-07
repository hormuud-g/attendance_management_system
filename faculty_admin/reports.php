<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// Modified access control to allow both super_admin and faculty_admin
if (!isset($_SESSION['user']) || !in_array(strtolower($_SESSION['user']['role'] ?? ''), ['super_admin', 'faculty_admin'])) {
  header("Location: ../login.php");
  exit;
}

// Get current user role and ID for restrictions
$current_user_role = strtolower($_SESSION['user']['role'] ?? '');
$current_user_linked_id = $_SESSION['user']['linked_id'] ?? null;
$is_faculty_admin = ($current_user_role === 'faculty_admin');

date_default_timezone_set('Africa/Nairobi');

// Get faculty name for faculty admin
$faculty_name = '';
if ($is_faculty_admin && $current_user_linked_id) {
    $stmt = $pdo->prepare("SELECT faculty_name FROM faculties WHERE faculty_id = ?");
    $stmt->execute([$current_user_linked_id]);
    $faculty_name = $stmt->fetchColumn();
}

/* ================= CHECK TABLE COLUMNS ================= */
// Check if teachers table has faculty_id column
$teachers_has_faculty = false;
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM teachers LIKE 'faculty_id'");
    $teachers_has_faculty = $stmt->rowCount() > 0;
} catch (Exception $e) {
    // Table might not exist
}

// Check if students table has faculty_id column
$students_has_faculty = false;
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM students LIKE 'faculty_id'");
    $students_has_faculty = $stmt->rowCount() > 0;
} catch (Exception $e) {
    // Table might not exist
}

// Check if classes table has faculty_id column
$classes_has_faculty = false;
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM classes LIKE 'faculty_id'");
    $classes_has_faculty = $stmt->rowCount() > 0;
} catch (Exception $e) {
    // Table might not exist
}

/* ================= FETCH SUMMARY DATA WITH FACULTY RESTRICTIONS ================= */
$stats = [];

// ✅ Academic Info (No restrictions - these are system-wide)
$stats['academic_year']   = $pdo->query("SELECT COUNT(*) FROM academic_year")->fetchColumn();
$stats['academic_term']   = $pdo->query("SELECT COUNT(*) FROM academic_term")->fetchColumn();
$stats['semester']        = $pdo->query("SELECT COUNT(*) FROM semester")->fetchColumn();

// ✅ Structure Info (Faculty admin sees only their faculty's data)
if ($is_faculty_admin && $current_user_linked_id) {
    // Campuses where faculty exists
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT campus_id) FROM faculty_campus WHERE faculty_id = ?");
    $stmt->execute([$current_user_linked_id]);
    $stats['campus'] = $stmt->fetchColumn();
    
    // Faculties (just their own)
    $stats['faculties'] = 1;
    
    // Departments in their faculty
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE faculty_id = ?");
    $stmt->execute([$current_user_linked_id]);
    $stats['departments'] = $stmt->fetchColumn();
    
    // Programs in their faculty
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM programs WHERE faculty_id = ?");
    $stmt->execute([$current_user_linked_id]);
    $stats['programs'] = $stmt->fetchColumn();
    
    // Classes in their faculty (if column exists)
    if ($classes_has_faculty) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE faculty_id = ?");
        $stmt->execute([$current_user_linked_id]);
        $stats['classes'] = $stmt->fetchColumn();
    } else {
        $stats['classes'] = $pdo->query("SELECT COUNT(*) FROM classes")->fetchColumn();
    }
    
    // Rooms (all rooms - no faculty restriction typically)
    $stats['rooms'] = $pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
    
    // Subjects (all subjects - no faculty restriction typically)
    $stats['subjects'] = $pdo->query("SELECT COUNT(*) FROM subject")->fetchColumn();
} else {
    // Super admin sees all
    $stats['campus']          = $pdo->query("SELECT COUNT(*) FROM campus")->fetchColumn();
    $stats['faculties']       = $pdo->query("SELECT COUNT(*) FROM faculties")->fetchColumn();
    $stats['departments']     = $pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn();
    $stats['programs']        = $pdo->query("SELECT COUNT(*) FROM programs")->fetchColumn();
    $stats['classes']         = $pdo->query("SELECT COUNT(*) FROM classes")->fetchColumn();
    $stats['rooms']           = $pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
    $stats['subjects']        = $pdo->query("SELECT COUNT(*) FROM subject")->fetchColumn();
}

// ✅ People Info (Faculty admin sees only their faculty's data)
if ($is_faculty_admin && $current_user_linked_id) {
    // Teachers in their faculty (if column exists)
    if ($teachers_has_faculty) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM teachers WHERE faculty_id = ?");
        $stmt->execute([$current_user_linked_id]);
        $stats['teachers'] = $stmt->fetchColumn();
    } else {
        $stats['teachers'] = $pdo->query("SELECT COUNT(*) FROM teachers")->fetchColumn();
    }
    
    // Students in their faculty (if column exists)
    if ($students_has_faculty) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE faculty_id = ?");
        $stmt->execute([$current_user_linked_id]);
        $stats['students'] = $stmt->fetchColumn();
    } else {
        $stats['students'] = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
    }
    
    // Parents (no faculty restriction - parents can have children in multiple faculties)
    $stats['parents'] = $pdo->query("SELECT COUNT(*) FROM parents")->fetchColumn();
    
    // Users (all users)
    $stats['users'] = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
} else {
    // Super admin sees all
    $stats['teachers']        = $pdo->query("SELECT COUNT(*) FROM teachers")->fetchColumn();
    $stats['students']        = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
    $stats['parents']         = $pdo->query("SELECT COUNT(*) FROM parents")->fetchColumn();
    $stats['users']           = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
}

// ✅ Attendance Info (Faculty admin sees only their faculty's data)
if ($is_faculty_admin && $current_user_linked_id) {
    // Student attendance through classes in their faculty
    if ($classes_has_faculty) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM attendance a
            JOIN classes c ON c.class_id = a.class_id
            WHERE c.faculty_id = ?
        ");
        $stmt->execute([$current_user_linked_id]);
        $stats['student_att'] = $stmt->fetchColumn();
    } else {
        $stats['student_att'] = $pdo->query("SELECT COUNT(*) FROM attendance")->fetchColumn();
    }
    
    // Teacher attendance for teachers in their faculty
    if ($teachers_has_faculty) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM teacher_attendance ta
            WHERE ta.teacher_id IN (SELECT teacher_id FROM teachers WHERE faculty_id = ?)
        ");
        $stmt->execute([$current_user_linked_id]);
        $stats['teacher_att'] = $stmt->fetchColumn();
    } else {
        $stats['teacher_att'] = $pdo->query("SELECT COUNT(*) FROM teacher_attendance")->fetchColumn();
    }
    
    // Promotion history where student's faculty matches
    if ($students_has_faculty) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM promotion_history ph
            JOIN students s ON s.student_id = ph.student_id
            WHERE s.faculty_id = ?
        ");
        $stmt->execute([$current_user_linked_id]);
        $stats['promotion'] = $stmt->fetchColumn();
    } else {
        $stats['promotion'] = $pdo->query("SELECT COUNT(*) FROM promotion_history")->fetchColumn();
    }
    
    // Timetable entries for classes in their faculty
    if ($classes_has_faculty) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM timetable t
            JOIN classes c ON c.class_id = t.class_id
            WHERE c.faculty_id = ?
        ");
        $stmt->execute([$current_user_linked_id]);
        $stats['timetable'] = $stmt->fetchColumn();
    } else {
        $stats['timetable'] = $pdo->query("SELECT COUNT(*) FROM timetable")->fetchColumn();
    }
} else {
    // Super admin sees all
    $stats['student_att']     = $pdo->query("SELECT COUNT(*) FROM attendance")->fetchColumn();
    $stats['teacher_att']     = $pdo->query("SELECT COUNT(*) FROM teacher_attendance")->fetchColumn();
    $stats['promotion']       = $pdo->query("SELECT COUNT(*) FROM promotion_history")->fetchColumn();
    $stats['timetable']       = $pdo->query("SELECT COUNT(*) FROM timetable")->fetchColumn();
}

// Audit log (no restrictions - system-wide)
$stats['audit_log']       = $pdo->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();

// ✅ RECOURSE STATISTICS (Faculty admin sees only their faculty's data)
if ($is_faculty_admin && $current_user_linked_id) {
    if ($students_has_faculty) {
        // Recourse total for students in their faculty
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM recourse_student rs
            JOIN students s ON s.student_id = rs.student_id
            WHERE s.faculty_id = ?
        ");
        $stmt->execute([$current_user_linked_id]);
        $stats['recourse_total'] = $stmt->fetchColumn();
        
        // Active recourse
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM recourse_student rs
            JOIN students s ON s.student_id = rs.student_id
            WHERE s.faculty_id = ? AND rs.status='active'
        ");
        $stmt->execute([$current_user_linked_id]);
        $stats['recourse_active'] = $stmt->fetchColumn();
        
        // Completed recourse
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM recourse_student rs
            JOIN students s ON s.student_id = rs.student_id
            WHERE s.faculty_id = ? AND rs.status='completed'
        ");
        $stmt->execute([$current_user_linked_id]);
        $stats['recourse_completed'] = $stmt->fetchColumn();
        
        // Cancelled recourse
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM recourse_student rs
            JOIN students s ON s.student_id = rs.student_id
            WHERE s.faculty_id = ? AND rs.status='cancelled'
        ");
        $stmt->execute([$current_user_linked_id]);
        $stats['recourse_cancelled'] = $stmt->fetchColumn();
    } else {
        $stats['recourse_total'] = $pdo->query("SELECT COUNT(*) FROM recourse_student")->fetchColumn();
        $stats['recourse_active'] = $pdo->query("SELECT COUNT(*) FROM recourse_student WHERE status='active'")->fetchColumn();
        $stats['recourse_completed'] = $pdo->query("SELECT COUNT(*) FROM recourse_student WHERE status='completed'")->fetchColumn();
        $stats['recourse_cancelled'] = $pdo->query("SELECT COUNT(*) FROM recourse_student WHERE status='cancelled'")->fetchColumn();
    }
} else {
    // Super admin sees all
    $stats['recourse_total']  = $pdo->query("SELECT COUNT(*) FROM recourse_student")->fetchColumn();
    $stats['recourse_active'] = $pdo->query("SELECT COUNT(*) FROM recourse_student WHERE status='active'")->fetchColumn();
    $stats['recourse_completed'] = $pdo->query("SELECT COUNT(*) FROM recourse_student WHERE status='completed'")->fetchColumn();
    $stats['recourse_cancelled'] = $pdo->query("SELECT COUNT(*) FROM recourse_student WHERE status='cancelled'")->fetchColumn();
}

/* ================= DETAILED REPORTS WITH FACULTY RESTRICTIONS ================= */

// ✅ Students with Recourse Status (filtered for faculty admin)
if ($is_faculty_admin && $current_user_linked_id && $students_has_faculty) {
    $stmt = $pdo->prepare("
        SELECT 
            s.reg_no, 
            s.full_name, 
            c.class_name, 
            p.program_name, 
            d.department_name, 
            f.faculty_name, 
            ca.campus_name,
            CASE 
                WHEN EXISTS (
                    SELECT 1 FROM recourse_student rs 
                    WHERE rs.student_id = s.student_id 
                    AND rs.status = 'active'
                ) THEN 'Recourse'
                ELSE 'Regular'
            END AS student_type,
            (SELECT COUNT(*) FROM recourse_student rs 
             WHERE rs.student_id = s.student_id AND rs.status = 'active') AS recourse_count
        FROM students s
        LEFT JOIN classes c ON c.class_id = s.class_id
        LEFT JOIN programs p ON p.program_id = c.program_id
        LEFT JOIN departments d ON d.department_id = c.department_id
        LEFT JOIN faculties f ON f.faculty_id = c.faculty_id
        LEFT JOIN campus ca ON ca.campus_id = c.campus_id
        WHERE s.status = 'active' AND s.faculty_id = ?
        ORDER BY 
            CASE 
                WHEN EXISTS (
                    SELECT 1 FROM recourse_student rs 
                    WHERE rs.student_id = s.student_id 
                    AND rs.status = 'active'
                ) THEN 0 
                ELSE 1 
            END,
            s.full_name ASC
        LIMIT 50
    ");
    $stmt->execute([$current_user_linked_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $students = $pdo->query("
        SELECT 
            s.reg_no, 
            s.full_name, 
            c.class_name, 
            p.program_name, 
            d.department_name, 
            f.faculty_name, 
            ca.campus_name,
            CASE 
                WHEN EXISTS (
                    SELECT 1 FROM recourse_student rs 
                    WHERE rs.student_id = s.student_id 
                    AND rs.status = 'active'
                ) THEN 'Recourse'
                ELSE 'Regular'
            END AS student_type,
            (SELECT COUNT(*) FROM recourse_student rs 
             WHERE rs.student_id = s.student_id AND rs.status = 'active') AS recourse_count
        FROM students s
        LEFT JOIN classes c ON c.class_id = s.class_id
        LEFT JOIN programs p ON p.program_id = c.program_id
        LEFT JOIN departments d ON d.department_id = c.department_id
        LEFT JOIN faculties f ON f.faculty_id = c.faculty_id
        LEFT JOIN campus ca ON ca.campus_id = c.campus_id
        WHERE s.status = 'active'
        ORDER BY 
            CASE 
                WHEN EXISTS (
                    SELECT 1 FROM recourse_student rs 
                    WHERE rs.student_id = s.student_id 
                    AND rs.status = 'active'
                ) THEN 0 
                ELSE 1 
            END,
            s.full_name ASC
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// ✅ Separate Regular and Recourse Students Count (filtered for faculty admin)
if ($is_faculty_admin && $current_user_linked_id && $students_has_faculty) {
    $stmt = $pdo->prepare("
        SELECT 
            'Regular' AS student_type,
            COUNT(*) AS total_students
        FROM students s
        WHERE s.status = 'active' AND s.faculty_id = ?
        AND NOT EXISTS (
            SELECT 1 FROM recourse_student rs 
            WHERE rs.student_id = s.student_id 
            AND rs.status = 'active'
        )
        
        UNION ALL
        
        SELECT 
            'Recourse' AS student_type,
            COUNT(DISTINCT s.student_id) AS total_students
        FROM students s
        WHERE s.status = 'active' AND s.faculty_id = ?
        AND EXISTS (
            SELECT 1 FROM recourse_student rs 
            WHERE rs.student_id = s.student_id 
            AND rs.status = 'active'
        )
    ");
    $stmt->execute([$current_user_linked_id, $current_user_linked_id]);
    $student_counts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $student_counts = $pdo->query("
        SELECT 
            'Regular' AS student_type,
            COUNT(*) AS total_students
        FROM students s
        WHERE s.status = 'active'
        AND NOT EXISTS (
            SELECT 1 FROM recourse_student rs 
            WHERE rs.student_id = s.student_id 
            AND rs.status = 'active'
        )
        
        UNION ALL
        
        SELECT 
            'Recourse' AS student_type,
            COUNT(DISTINCT s.student_id) AS total_students
        FROM students s
        WHERE s.status = 'active'
        AND EXISTS (
            SELECT 1 FROM recourse_student rs 
            WHERE rs.student_id = s.student_id 
            AND rs.status = 'active'
        )
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// ✅ Teachers (filtered for faculty admin)
if ($is_faculty_admin && $current_user_linked_id && $teachers_has_faculty) {
    $stmt = $pdo->prepare("
        SELECT teacher_name, email, phone_number, qualification, 
               (SELECT COUNT(DISTINCT class_id) FROM timetable WHERE teacher_id = teachers.teacher_id) AS total_classes
        FROM teachers
        WHERE status = 'active' AND faculty_id = ?
        ORDER BY teacher_name ASC
        LIMIT 50
    ");
    $stmt->execute([$current_user_linked_id]);
    $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $teachers = $pdo->query("
        SELECT teacher_name, email, phone_number, qualification, 
               (SELECT COUNT(DISTINCT class_id) FROM timetable WHERE teacher_id = teachers.teacher_id) AS total_classes
        FROM teachers
        WHERE status = 'active'
        ORDER BY teacher_name ASC
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// ✅ Attendance Summary (filtered for faculty admin)
if ($is_faculty_admin && $current_user_linked_id && $classes_has_faculty) {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS total, 
            SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) AS present,
            SUM(CASE WHEN a.status='absent' THEN 1 ELSE 0 END) AS absent,
            SUM(CASE WHEN a.status='late' THEN 1 ELSE 0 END) AS late,
            SUM(CASE WHEN a.status='excused' THEN 1 ELSE 0 END) AS excused
        FROM attendance a
        JOIN classes c ON c.class_id = a.class_id
        WHERE c.faculty_id = ?
    ");
    $stmt->execute([$current_user_linked_id]);
    $attendance_summary = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    $attendance_summary = $pdo->query("
        SELECT 
            COUNT(*) AS total, 
            SUM(CASE WHEN status='present' THEN 1 ELSE 0 END) AS present,
            SUM(CASE WHEN status='absent' THEN 1 ELSE 0 END) AS absent,
            SUM(CASE WHEN status='late' THEN 1 ELSE 0 END) AS late,
            SUM(CASE WHEN status='excused' THEN 1 ELSE 0 END) AS excused
        FROM attendance
    ")->fetch(PDO::FETCH_ASSOC);
}

// ✅ Programs Summary (filtered for faculty admin)
if ($is_faculty_admin && $current_user_linked_id) {
    $stmt = $pdo->prepare("
        SELECT p.program_name, COUNT(DISTINCT s.student_id) AS total_students,
               (SELECT COUNT(*) FROM classes WHERE program_id = p.program_id AND status = 'Active') AS total_classes
        FROM programs p
        LEFT JOIN classes c ON c.program_id = p.program_id
        LEFT JOIN students s ON s.class_id = c.class_id
        WHERE p.status = 'active' AND p.faculty_id = ?
        GROUP BY p.program_id
        ORDER BY p.program_name ASC
    ");
    $stmt->execute([$current_user_linked_id]);
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $programs = $pdo->query("
        SELECT p.program_name, COUNT(DISTINCT s.student_id) AS total_students,
               (SELECT COUNT(*) FROM classes WHERE program_id = p.program_id AND status = 'Active') AS total_classes
        FROM programs p
        LEFT JOIN classes c ON c.program_id = p.program_id
        LEFT JOIN students s ON s.class_id = c.class_id
        WHERE p.status = 'active'
        GROUP BY p.program_id
        ORDER BY p.program_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// ✅ Classes Summary (filtered for faculty admin)
if ($is_faculty_admin && $current_user_linked_id && $classes_has_faculty) {
    $stmt = $pdo->prepare("
        SELECT c.class_name, p.program_name, d.department_name, c.study_mode,
               COUNT(s.student_id) AS total_students,
               ca.campus_name
        FROM classes c
        LEFT JOIN programs p ON p.program_id = c.program_id
        LEFT JOIN departments d ON d.department_id = c.department_id
        LEFT JOIN campus ca ON ca.campus_id = c.campus_id
        LEFT JOIN students s ON s.class_id = c.class_id AND s.status = 'active'
        WHERE c.status = 'Active' AND c.faculty_id = ?
        GROUP BY c.class_id
        ORDER BY c.class_name ASC
        LIMIT 30
    ");
    $stmt->execute([$current_user_linked_id]);
    $classes_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $classes_summary = $pdo->query("
        SELECT c.class_name, p.program_name, d.department_name, c.study_mode,
               COUNT(s.student_id) AS total_students,
               ca.campus_name
        FROM classes c
        LEFT JOIN programs p ON p.program_id = c.program_id
        LEFT JOIN departments d ON d.department_id = c.department_id
        LEFT JOIN campus ca ON ca.campus_id = c.campus_id
        LEFT JOIN students s ON s.class_id = c.class_id AND s.status = 'active'
        WHERE c.status = 'Active'
        GROUP BY c.class_id
        ORDER BY c.class_name ASC
        LIMIT 30
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// ✅ Active Term (no restrictions - system-wide)
$active_term = $pdo->query("SELECT term_name, status FROM academic_term WHERE status='active'")->fetch(PDO::FETCH_ASSOC);

/* ================= RECOURSE DETAILED DATA WITH FACULTY RESTRICTIONS ================= */

// ✅ Recourse Summary (filtered for faculty admin)
if ($is_faculty_admin && $current_user_linked_id && $students_has_faculty) {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS total_recourse,
            COUNT(DISTINCT rs.student_id) AS unique_students,
            COUNT(DISTINCT rs.subject_id) AS total_subjects,
            AVG(
                (SELECT COUNT(*) FROM recourse_student rs2 
                 WHERE rs2.student_id = rs.student_id)
            ) AS avg_recourse_per_student
        FROM recourse_student rs
        JOIN students s ON s.student_id = rs.student_id
        WHERE s.faculty_id = ?
    ");
    $stmt->execute([$current_user_linked_id]);
    $recourse_summary = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    $recourse_summary = $pdo->query("
        SELECT 
            COUNT(*) AS total_recourse,
            COUNT(DISTINCT student_id) AS unique_students,
            COUNT(DISTINCT subject_id) AS total_subjects,
            AVG(
                (SELECT COUNT(*) FROM recourse_student rs2 
                 WHERE rs2.student_id = recourse_student.student_id)
            ) AS avg_recourse_per_student
        FROM recourse_student
    ")->fetch(PDO::FETCH_ASSOC);
}

// ✅ Recourse by Subject (filtered for faculty admin)
if ($is_faculty_admin && $current_user_linked_id && $students_has_faculty) {
    $stmt = $pdo->prepare("
        SELECT 
            s.subject_name,
            COUNT(rs.recourse_id) AS total_recourse,
            COUNT(DISTINCT rs.student_id) AS unique_students,
            GROUP_CONCAT(DISTINCT sem.semester_name SEPARATOR ', ') AS semesters
        FROM recourse_student rs
        JOIN subject s ON s.subject_id = rs.subject_id
        LEFT JOIN semester sem ON sem.semester_id = rs.recourse_semester_id
        JOIN students stu ON stu.student_id = rs.student_id
        WHERE rs.status = 'active' AND stu.faculty_id = ?
        GROUP BY s.subject_id
        ORDER BY total_recourse DESC
        LIMIT 15
    ");
    $stmt->execute([$current_user_linked_id]);
    $recourse_by_subject = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $recourse_by_subject = $pdo->query("
        SELECT 
            s.subject_name,
            COUNT(rs.recourse_id) AS total_recourse,
            COUNT(DISTINCT rs.student_id) AS unique_students,
            GROUP_CONCAT(DISTINCT sem.semester_name SEPARATOR ', ') AS semesters
        FROM recourse_student rs
        JOIN subject s ON s.subject_id = rs.subject_id
        LEFT JOIN semester sem ON sem.semester_id = rs.recourse_semester_id
        WHERE rs.status = 'active'
        GROUP BY s.subject_id
        ORDER BY total_recourse DESC
        LIMIT 15
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// ✅ Recourse by Campus (filtered for faculty admin)
if ($is_faculty_admin && $current_user_linked_id && $students_has_faculty) {
    $stmt = $pdo->prepare("
        SELECT 
            c.campus_name,
            COUNT(rs.recourse_id) AS total_recourse,
            COUNT(DISTINCT rs.student_id) AS unique_students,
            COUNT(DISTINCT rs.subject_id) AS total_subjects
        FROM recourse_student rs
        JOIN campus c ON c.campus_id = rs.recourse_campus_id
        JOIN students s ON s.student_id = rs.student_id
        WHERE rs.status = 'active' AND s.faculty_id = ?
        GROUP BY c.campus_id
        ORDER BY total_recourse DESC
    ");
    $stmt->execute([$current_user_linked_id]);
    $recourse_by_campus = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $recourse_by_campus = $pdo->query("
        SELECT 
            c.campus_name,
            COUNT(rs.recourse_id) AS total_recourse,
            COUNT(DISTINCT rs.student_id) AS unique_students,
            COUNT(DISTINCT rs.subject_id) AS total_subjects
        FROM recourse_student rs
        JOIN campus c ON c.campus_id = rs.recourse_campus_id
        WHERE rs.status = 'active'
        GROUP BY c.campus_id
        ORDER BY total_recourse DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// ✅ Active Recourse Students (filtered for faculty admin)
if ($is_faculty_admin && $current_user_linked_id && $students_has_faculty) {
    $stmt = $pdo->prepare("
        SELECT 
            rs.recourse_id,
            s.full_name,
            s.reg_no,
            sub.subject_name,
            oc.class_name AS original_class,
            rc.class_name AS recourse_class,
            osem.semester_name AS original_semester,
            rsem.semester_name AS recourse_semester,
            ocamp.campus_name AS original_campus,
            rcamp.campus_name AS recourse_campus,
            rs.reason,
            rs.status,
            rs.created_at
        FROM recourse_student rs
        JOIN students s ON s.student_id = rs.student_id
        JOIN subject sub ON sub.subject_id = rs.subject_id
        LEFT JOIN classes oc ON oc.class_id = rs.original_class_id
        LEFT JOIN classes rc ON rc.class_id = rs.recourse_class_id
        LEFT JOIN semester osem ON osem.semester_id = rs.original_semester_id
        LEFT JOIN semester rsem ON rsem.semester_id = rs.recourse_semester_id
        LEFT JOIN campus ocamp ON ocamp.campus_id = rs.original_campus_id
        LEFT JOIN campus rcamp ON rcamp.campus_id = rs.recourse_campus_id
        WHERE rs.status = 'active' AND s.faculty_id = ?
        ORDER BY rs.created_at DESC
        LIMIT 30
    ");
    $stmt->execute([$current_user_linked_id]);
    $recourse_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $recourse_students = $pdo->query("
        SELECT 
            rs.recourse_id,
            s.full_name,
            s.reg_no,
            sub.subject_name,
            oc.class_name AS original_class,
            rc.class_name AS recourse_class,
            osem.semester_name AS original_semester,
            rsem.semester_name AS recourse_semester,
            ocamp.campus_name AS original_campus,
            rcamp.campus_name AS recourse_campus,
            rs.reason,
            rs.status,
            rs.created_at
        FROM recourse_student rs
        JOIN students s ON s.student_id = rs.student_id
        JOIN subject sub ON sub.subject_id = rs.subject_id
        LEFT JOIN classes oc ON oc.class_id = rs.original_class_id
        LEFT JOIN classes rc ON rc.class_id = rs.recourse_class_id
        LEFT JOIN semester osem ON osem.semester_id = rs.original_semester_id
        LEFT JOIN semester rsem ON rsem.semester_id = rs.recourse_semester_id
        LEFT JOIN campus ocamp ON ocamp.campus_id = rs.original_campus_id
        LEFT JOIN campus rcamp ON rcamp.campus_id = rs.recourse_campus_id
        WHERE rs.status = 'active'
        ORDER BY rs.created_at DESC
        LIMIT 30
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// ✅ Recourse Status Distribution (filtered for faculty admin) - FIXED AMBIGUOUS COLUMN
if ($is_faculty_admin && $current_user_linked_id && $students_has_faculty) {
    $total_recourse = $stats['recourse_total'] > 0 ? $stats['recourse_total'] : 1;
    $stmt = $pdo->prepare("
        SELECT 
            rs.status,
            COUNT(*) AS total,
            ROUND(COUNT(*) * 100.0 / ?, 1) AS percentage
        FROM recourse_student rs
        JOIN students s ON s.student_id = rs.student_id
        WHERE s.faculty_id = ?
        GROUP BY rs.status
        ORDER BY rs.status
    ");
    $stmt->execute([$total_recourse, $current_user_linked_id]);
    $recourse_status_dist = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // FIXED: Added table alias to avoid ambiguous column
    $recourse_status_dist = $pdo->query("
        SELECT 
            status,
            COUNT(*) AS total,
            ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM recourse_student), 1) AS percentage
        FROM recourse_student
        GROUP BY status
        ORDER BY status
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/* ================= STUDY MODE DISTRIBUTION (filtered for faculty admin) ================= */
if ($is_faculty_admin && $current_user_linked_id && $classes_has_faculty) {
    $stmt = $pdo->prepare("
        SELECT study_mode, COUNT(*) AS total_classes, 
               COUNT(DISTINCT program_id) AS total_programs
        FROM classes 
        WHERE status = 'Active' AND faculty_id = ?
        GROUP BY study_mode
        ORDER BY study_mode
    ");
    $stmt->execute([$current_user_linked_id]);
    $study_mode_dist = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $study_mode_dist = $pdo->query("
        SELECT study_mode, COUNT(*) AS total_classes, 
               COUNT(DISTINCT program_id) AS total_programs
        FROM classes 
        WHERE status = 'Active'
        GROUP BY study_mode
        ORDER BY study_mode
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/* ================= CAMPUS DISTRIBUTION (filtered for faculty admin) ================= */
if ($is_faculty_admin && $current_user_linked_id) {
    // Simplified campus distribution for faculty admin
    $stmt = $pdo->prepare("
        SELECT 
            c.campus_name, 
            COUNT(DISTINCT fc.faculty_id) AS total_faculties,
            GROUP_CONCAT(DISTINCT f.faculty_name ORDER BY f.faculty_name SEPARATOR ', ') AS faculty_names,
            COUNT(DISTINCT d.department_id) AS total_departments,
            COUNT(DISTINCT p.program_id) AS total_programs,
            COUNT(DISTINCT cl.class_id) AS total_classes,
            COUNT(DISTINCT s.student_id) AS total_students
        FROM campus c
        LEFT JOIN faculty_campus fc ON fc.campus_id = c.campus_id AND fc.faculty_id = ?
        LEFT JOIN faculties f ON f.faculty_id = fc.faculty_id
        LEFT JOIN departments d ON d.campus_id = c.campus_id AND d.faculty_id = ?
        LEFT JOIN programs p ON p.campus_id = c.campus_id AND p.faculty_id = ?
        LEFT JOIN classes cl ON cl.campus_id = c.campus_id AND cl.faculty_id = ?
        LEFT JOIN students s ON s.campus_id = c.campus_id AND s.faculty_id = ?
        WHERE c.status = 'active'
        GROUP BY c.campus_id
        HAVING total_faculties > 0 OR total_departments > 0 OR total_programs > 0 OR total_classes > 0 OR total_students > 0
        ORDER BY c.campus_name ASC
    ");
    $stmt->execute([
        $current_user_linked_id, 
        $current_user_linked_id, 
        $current_user_linked_id, 
        $current_user_linked_id, 
        $current_user_linked_id
    ]);
    $campus_dist = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $campus_dist = $pdo->query("
        SELECT c.campus_name, 
               COUNT(DISTINCT fc.faculty_id) AS total_faculties,
               GROUP_CONCAT(DISTINCT f.faculty_name ORDER BY f.faculty_name SEPARATOR ', ') AS faculty_names,
               COUNT(DISTINCT d.department_id) AS total_departments,
               COUNT(DISTINCT p.program_id) AS total_programs,
               COUNT(DISTINCT cl.class_id) AS total_classes,
               COUNT(DISTINCT s.student_id) AS total_students
        FROM campus c
        LEFT JOIN faculty_campus fc ON fc.campus_id = c.campus_id
        LEFT JOIN faculties f ON f.faculty_id = fc.faculty_id
        LEFT JOIN departments d ON d.campus_id = c.campus_id
        LEFT JOIN programs p ON p.campus_id = c.campus_id
        LEFT JOIN classes cl ON cl.campus_id = c.campus_id
        LEFT JOIN students s ON s.campus_id = c.campus_id
        WHERE c.status = 'active'
        GROUP BY c.campus_id
        ORDER BY c.campus_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/* ================= EXPORT ================= */
if (isset($_GET['export']) && isset($_GET['type'])) {
  $type = $_GET['type'];
  $filename = "University_Report_" . ($is_faculty_admin ? str_replace(' ', '_', $faculty_name) . '_' : '') . date('Y-m-d');
  
  // Prepare data for export (filtered based on role)
  $export_data = [
    "University Statistics" => $stats,
    "Active Term" => $active_term,
    "Attendance Summary" => $attendance_summary,
    "Recourse Summary" => $recourse_summary,
    "Recourse Status Distribution" => $recourse_status_dist,
    "Study Mode Distribution" => $study_mode_dist,
    "Campus Distribution" => $campus_dist,
    "Programs Summary" => array_slice($programs, 0, 10),
    "Classes Summary" => array_slice($classes_summary, 0, 10),
    "Students Summary" => array_slice($students, 0, 10),
    "Student Type Distribution" => $student_counts,
    "Top Teachers" => array_slice($teachers, 0, 10),
    "Active Recourse Students" => array_slice($recourse_students, 0, 10),
    "Recourse by Subject" => array_slice($recourse_by_subject, 0, 10)
  ];

  if ($type === 'csv') {
    header("Content-Type: text/csv");
    header("Content-Disposition: attachment; filename={$filename}.csv");
    $out = fopen("php://output", "w");
    
    // Add header
    fputcsv($out, ["HORMUUD UNIVERSITY - ALL-IN-ONE REPORT", date('Y-m-d H:i:s')]);
    if ($is_faculty_admin) {
        fputcsv($out, ["Faculty: " . $faculty_name]);
    }
    fputcsv($out, []); // Empty row
    
    foreach ($export_data as $section_title => $section_data) {
      fputcsv($out, [$section_title]);
      fputcsv($out, []); // Empty row
      
      if (is_array($section_data)) {
        if (isset($section_data[0]) && is_array($section_data[0])) {
          // Array of arrays (like students, teachers)
          if (!empty($section_data)) {
            $headers = array_keys($section_data[0]);
            fputcsv($out, $headers);
            foreach ($section_data as $row) {
              fputcsv($out, $row);
            }
          }
        } else {
          // Single associative array (like stats)
          foreach ($section_data as $key => $value) {
            fputcsv($out, [$key, $value]);
          }
        }
      } else {
        fputcsv($out, ["Data", $section_data]);
      }
      
      fputcsv($out, []); // Empty row between sections
      fputcsv($out, []); // Extra empty row
    }
    
    fclose($out);
    exit;
    
  } elseif ($type === 'pdf') {
    // PDF export only for super admin (too complex for faculty admin)
    if ($is_faculty_admin) {
        exit("PDF export is not available for faculty admin. Please use CSV export.");
    }
    
    require_once(__DIR__ . '/../libs/fpdf/fpdf.php');
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->AddPage();
    
    // Header
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, 'HORMUUD UNIVERSITY', 0, 1, 'C');
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'All-in-One University Report', 0, 1, 'C');
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 5, 'Generated on: ' . date('d M Y H:i:s'), 0, 1, 'C');
    $pdf->Ln(10);
    
    // Statistics
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, 'UNIVERSITY STATISTICS', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    
    $col_width = 90;
    $line_height = 7;
    $count = 0;
    
    foreach ($stats as $key => $value) {
      if ($count % 2 == 0) {
        $pdf->Cell($col_width, $line_height, ucwords(str_replace('_', ' ', $key)) . ':');
        $pdf->Cell($col_width, $line_height, number_format($value));
        $pdf->Ln();
      } else {
        $pdf->Cell($col_width, $line_height, ucwords(str_replace('_', ' ', $key)) . ':');
        $pdf->Cell($col_width, $line_height, number_format($value));
        $pdf->Ln();
      }
      $count++;
    }
    
    $pdf->Ln(10);
    
    // Active Term
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, 'ACTIVE TERM', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    if ($active_term) {
        $pdf->Cell(0, 6, 'Term Name: ' . $active_term['term_name'], 0, 1);
        $pdf->Cell(0, 6, 'Status: ' . $active_term['status'], 0, 1);
    } else {
        $pdf->Cell(0, 6, 'No active term found', 0, 1);
    }
    
    $pdf->Ln(10);
    
    // Attendance Summary
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, 'ATTENDANCE SUMMARY', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 6, 'Total Records: ' . $attendance_summary['total'], 0, 1);
    $pdf->Cell(0, 6, 'Present: ' . $attendance_summary['present'], 0, 1);
    $pdf->Cell(0, 6, 'Absent: ' . $attendance_summary['absent'], 0, 1);
    $pdf->Cell(0, 6, 'Late: ' . $attendance_summary['late'], 0, 1);
    $pdf->Cell(0, 6, 'Excused: ' . $attendance_summary['excused'], 0, 1);
    
    $pdf->Output();
    exit;
  }
}

include('../includes/header.php');
?>
<style>
  .sidebar.collapsed ~ .main-content {
    margin-left: 70px;
  }
  .faculty-admin-badge {
    display: inline-block;
    background-color: #FF6B00;
    color: white;
    padding: 3px 10px;
    border-radius: 4px;
    font-size: 12px;
    margin-left: 10px;
    vertical-align: middle;
  }
  .info-message {
    background-color: #e3f2fd;
    color: #1565c0;
    padding: 8px 12px;
    border-radius: 4px;
    margin: 10px 0 20px;
    font-size: 13px;
    border-left: 3px solid #0072CE;
  }
</style>
<div class="main-content">
  <div class="page-header">
    <h1>
      <i class="fa fa-chart-pie"></i> University All-in-One Report
      <?php if($is_faculty_admin): ?>
      <?php endif; ?>
    </h1>
    
  </div>

 

  <!-- STATISTICS GRID -->
  <div class="summary-grid">
    <?php 
    $stat_labels = [
      'campus' => 'Campuses',
      'faculties' => 'Faculties',
      'departments' => 'Departments',
      'programs' => 'Programs',
      'classes' => 'Classes',
      'teachers' => 'Teachers',
      'students' => 'Students',
      'student_att' => 'Student Attendance',
      'teacher_att' => 'Teacher Attendance',
      'promotion' => 'Promotions',
      'rooms' => 'Rooms',
      'subjects' => 'Subjects',
      'timetable' => 'Timetable Entries',
      'audit_log' => 'Audit Logs',
      'recourse_total' => 'Total Recourse',
      'recourse_active' => 'Active Recourse',
      'recourse_completed' => 'Completed Recourse',
      'recourse_cancelled' => 'Cancelled Recourse'
    ];
    
    foreach($stat_labels as $key => $label): 
      if(isset($stats[$key])):
    ?>
      <div class="card">
        <div class="card-icon">
          <i class="fa 
            <?= in_array($key, ['campus', 'faculties', 'departments', 'programs', 'classes']) ? 'fa-university' : '' ?>
            <?= in_array($key, ['teachers', 'students']) ? 'fa-users' : '' ?>
            <?= in_array($key, ['student_att', 'teacher_att']) ? 'fa-clipboard-check' : '' ?>
            <?= $key === 'promotion' ? 'fa-graduation-cap' : '' ?>
            <?= $key === 'rooms' ? 'fa-door-open' : '' ?>
            <?= $key === 'subjects' ? 'fa-book' : '' ?>
            <?= $key === 'timetable' ? 'fa-calendar-alt' : '' ?>
            <?= $key === 'audit_log' ? 'fa-history' : '' ?>
            <?= strpos($key, 'recourse') !== false ? 'fa-redo-alt' : '' ?>
          "></i>
        </div>
        <h3><?= $label ?></h3>
        <p class="stat-number"><?= number_format($stats[$key]) ?></p>
      </div>
    <?php endif; endforeach; ?>
  </div>

  <!-- EXPORT BUTTONS -->
  <div class="export-box">
    <a href="?export=1&type=csv" class="btn blue"><i class="fa fa-file-excel"></i> Download CSV</a>
    <?php if(!$is_faculty_admin): ?>
      <!-- <a href="?export=1&type=pdf" class="btn red"><i class="fa fa-file-pdf"></i> Download PDF</a> -->
    <?php endif; ?>
  </div>

  <!-- ACTIVE TERM -->
  <div class="section">
    <h2><i class="fa fa-calendar-alt"></i> Active Academic Term</h2>
    <div class="info-box">
      <?php if($active_term): ?>
        <p><strong>Term Name:</strong> <?= htmlspecialchars($active_term['term_name']) ?></p>
        <p><strong>Status:</strong> <span class="status-active"><?= htmlspecialchars($active_term['status']) ?></span></p>
      <?php else: ?>
        <p class="no-data">No active term found. Please set an active academic term.</p>
      <?php endif; ?>
    </div>
  </div>

  <!-- ATTENDANCE SUMMARY -->
  <div class="section">
    <h2><i class="fa fa-clipboard-check"></i> Attendance Summary</h2>
    <div class="attendance-summary">
      <div class="att-card present">
        <h3>Present</h3>
        <p><?= number_format($attendance_summary['present'] ?? 0) ?></p>
      </div>
      <div class="att-card absent">
        <h3>Absent</h3>
        <p><?= number_format($attendance_summary['absent'] ?? 0) ?></p>
      </div>
      <div class="att-card late">
        <h3>Late</h3>
        <p><?= number_format($attendance_summary['late'] ?? 0) ?></p>
      </div>
      <div class="att-card excused">
        <h3>Excused</h3>
        <p><?= number_format($attendance_summary['excused'] ?? 0) ?></p>
      </div>
      <div class="att-card total">
        <h3>Total</h3>
        <p><?= number_format($attendance_summary['total'] ?? 0) ?></p>
      </div>
    </div>
  </div>

  <!-- STUDENT TYPE DISTRIBUTION -->
  <div class="section">
    <h2><i class="fa fa-user-graduate"></i> Student Type Distribution</h2>
    <div class="attendance-summary">
      <?php foreach($student_counts as $count): ?>
        <?php 
          $bg_color = '';
          if ($count['student_type'] === 'Regular') {
            $bg_color = '#0072CE'; // Blue for Regular
          } else {
            $bg_color = '#ff9800'; // Orange for Recourse
          }
        ?>
        <div class="att-card" style="background:<?= $bg_color ?>;">
          <h3><?= htmlspecialchars($count['student_type']) ?> Students</h3>
          <p><?= number_format($count['total_students']) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- RECOURSE SUMMARY -->
  <div class="section">
    <h2><i class="fa fa-redo-alt"></i> Recourse Summary</h2>
    
    <!-- Recourse Statistics -->
    <div class="attendance-summary">
      <div class="att-card total" style="background:#0072CE;">
        <h3>Total Recourse</h3>
        <p><?= number_format($recourse_summary['total_recourse'] ?? 0) ?></p>
      </div>
      <div class="att-card present" style="background:#00843D;">
        <h3>Unique Students</h3>
        <p><?= number_format($recourse_summary['unique_students'] ?? 0) ?></p>
      </div>
      <div class="att-card late" style="background:#ff9800;">
        <h3>Subjects</h3>
        <p><?= number_format($recourse_summary['total_subjects'] ?? 0) ?></p>
      </div>
      <div class="att-card excused" style="background:#2196f3;">
        <h3>Avg per Student</h3>
        <p><?= number_format($recourse_summary['avg_recourse_per_student'] ?? 0, 1) ?></p>
      </div>
    </div>

    <!-- Recourse Status Distribution -->
    <h3 style="margin-top:25px;"><i class="fa fa-chart-bar"></i> Recourse Status Distribution</h3>
    <div class="attendance-summary">
      <?php if ($recourse_status_dist): ?>
        <?php foreach($recourse_status_dist as $status): ?>
          <?php 
            $bg_color = '';
            $label = '';
            switch($status['status']) {
              case 'active': $bg_color = '#00843D'; $label = 'Active'; break;
              case 'completed': $bg_color = '#2196f3'; $label = 'Completed'; break;
              case 'cancelled': $bg_color = '#C62828'; $label = 'Cancelled'; break;
              default: $bg_color = '#607d8b'; $label = $status['status'];
            }
          ?>
          <div class="att-card" style="background:<?= $bg_color ?>;">
            <h3><?= $label ?></h3>
            <p><?= number_format($status['total']) ?> (<?= $status['percentage'] ?>%)</p>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="att-card" style="background:#607d8b;">
          <h3>No Recourse Data</h3>
          <p>0</p>
        </div>
      <?php endif; ?>
    </div>

    <!-- Recourse by Campus -->
    <?php if($recourse_by_campus): ?>
    <h3 style="margin-top:25px;"><i class="fa fa-university"></i> Recourse by Campus</h3>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Campus</th>
            <th>Total Recourse</th>
            <th>Unique Students</th>
            <th>Subjects</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($recourse_by_campus as $campus): ?>
            <tr>
              <td><?= htmlspecialchars($campus['campus_name']) ?></td>
              <td><?= number_format($campus['total_recourse']) ?></td>
              <td><?= number_format($campus['unique_students']) ?></td>
              <td><?= number_format($campus['total_subjects']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <!-- Recourse by Subject -->
    <?php if($recourse_by_subject): ?>
    <h3 style="margin-top:25px;"><i class="fa fa-book"></i> Top Subjects with Recourse</h3>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Subject</th>
            <th>Total Recourse</th>
            <th>Unique Students</th>
            <th>Semesters</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($recourse_by_subject as $subject): ?>
            <tr>
              <td><?= htmlspecialchars($subject['subject_name']) ?></td>
              <td><?= number_format($subject['total_recourse']) ?></td>
              <td><?= number_format($subject['unique_students']) ?></td>
              <td><?= htmlspecialchars($subject['semesters'] ?? 'N/A') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <!-- Active Recourse Students -->
    <?php if($recourse_students): ?>
    <h3 style="margin-top:25px;"><i class="fa fa-user-graduate"></i> Active Recourse Students (Top 30)</h3>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Student</th>
            <th>Reg No</th>
            <th>Subject</th>
            <th>Original Class</th>
            <th>Recourse Class</th>
            <th>Original Campus</th>
            <th>Recourse Campus</th>
            <th>Status</th>
            <th>Date</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($recourse_students as $recourse): ?>
            <tr>
              <td><?= htmlspecialchars($recourse['full_name']) ?></td>
              <td><strong><?= htmlspecialchars($recourse['reg_no']) ?></strong></td>
              <td><?= htmlspecialchars($recourse['subject_name']) ?></td>
              <td><?= htmlspecialchars($recourse['original_class'] ?? 'N/A') ?></td>
              <td><?= htmlspecialchars($recourse['recourse_class'] ?? 'N/A') ?></td>
              <td><?= htmlspecialchars($recourse['original_campus'] ?? 'N/A') ?></td>
              <td><?= htmlspecialchars($recourse['recourse_campus'] ?? 'N/A') ?></td>
              <td>
                <?php 
                  $status_color = '';
                  switch($recourse['status']) {
                    case 'active': $status_color = '#00843D'; break;
                    case 'completed': $status_color = '#2196f3'; break;
                    case 'cancelled': $status_color = '#C62828'; break;
                    default: $status_color = '#607d8b';
                  }
                ?>
                <span style="color:<?= $status_color ?>;font-weight:bold;">
                  <?= ucfirst($recourse['status']) ?>
                </span>
              </td>
              <td><?= date('d M Y', strtotime($recourse['created_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- STUDENTS LIST WITH TYPE -->
  <div class="section">
    <h2><i class="fa fa-users"></i> Students with Type (Top 50)</h2>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Reg No</th>
            <th>Name</th>
            <th>Type</th>
            <th>Class</th>
            <th>Program</th>
            <th>Department</th>
            <th>Faculty</th>
            <th>Campus</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($students as $s): ?>
            <tr>
              <td><strong><?= htmlspecialchars($s['reg_no']) ?></strong></td>
              <td><?= htmlspecialchars($s['full_name']) ?></td>
              <td>
                <?php if ($s['student_type'] === 'Recourse'): ?>
                  <span class="student-type-badge recourse" title="Active Recourse Count: <?= $s['recourse_count'] ?>">
                    <i class="fa fa-redo-alt"></i> Recourse (<?= $s['recourse_count'] ?>)
                  </span>
                <?php else: ?>
                  <span class="student-type-badge regular">
                    <i class="fa fa-user-check"></i> Regular
                  </span>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($s['class_name'] ?? '-') ?></td>
              <td><?= htmlspecialchars($s['program_name'] ?? '-') ?></td>
              <td><?= htmlspecialchars($s['department_name'] ?? '-') ?></td>
              <td><?= htmlspecialchars($s['faculty_name'] ?? '-') ?></td>
              <td><?= htmlspecialchars($s['campus_name'] ?? '-') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- STUDY MODE DISTRIBUTION -->
  <?php if($study_mode_dist): ?>
  <div class="section">
    <h2><i class="fa fa-graduation-cap"></i> Study Mode Distribution</h2>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Study Mode</th>
            <th>Total Classes</th>
            <th>Total Programs</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($study_mode_dist as $mode): ?>
            <tr>
              <td>
                <span class="study-mode-badge <?= htmlspecialchars($mode['study_mode']) ?>">
                  <?= htmlspecialchars($mode['study_mode']) ?>
                </span>
              </td>
              <td><?= number_format($mode['total_classes']) ?></td>
              <td><?= number_format($mode['total_programs']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- CAMPUS DISTRIBUTION -->
  <?php if($campus_dist): ?>
  <div class="section">
    <h2><i class="fa fa-university"></i> Campus Distribution</h2>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Campus</th>
            <th>Faculties</th>
            <th>Departments</th>
            <th>Programs</th>
            <th>Classes</th>
            <th>Students</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($campus_dist as $campus): ?>
            <tr>
              <td><?= htmlspecialchars($campus['campus_name']) ?></td>
              <td><?= number_format($campus['total_faculties']) ?></td>
              <td><?= number_format($campus['total_departments']) ?></td>
              <td><?= number_format($campus['total_programs']) ?></td>
              <td><?= number_format($campus['total_classes']) ?></td>
              <td><?= number_format($campus['total_students']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- TEACHERS LIST -->
  <div class="section">
    <h2><i class="fa fa-chalkboard-teacher"></i> Teachers (Top 50)</h2>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Phone</th>
            <th>Qualification</th>
            <th>Classes Assigned</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($teachers as $t): ?>
            <tr>
              <td><?= htmlspecialchars($t['teacher_name']) ?></td>
              <td><?= htmlspecialchars($t['email']) ?></td>
              <td><?= htmlspecialchars($t['phone_number']) ?></td>
              <td><?= htmlspecialchars($t['qualification']) ?></td>
              <td><?= number_format($t['total_classes'] ?? 0) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- PROGRAMS SUMMARY -->
  <div class="section">
    <h2><i class="fa fa-book"></i> Programs Summary</h2>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Program</th>
            <th>Total Students</th>
            <th>Total Classes</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($programs as $p): ?>
            <tr>
              <td><?= htmlspecialchars($p['program_name']) ?></td>
              <td><?= number_format($p['total_students']) ?></td>
              <td><?= number_format($p['total_classes']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- CLASSES SUMMARY -->
  <div class="section">
    <h2><i class="fa fa-door-open"></i> Classes Summary (Top 30)</h2>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Class Name</th>
            <th>Program</th>
            <th>Department</th>
            <th>Study Mode</th>
            <th>Students</th>
            <th>Campus</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($classes_summary as $c): ?>
            <tr>
              <td><?= htmlspecialchars($c['class_name']) ?></td>
              <td><?= htmlspecialchars($c['program_name'] ?? '-') ?></td>
              <td><?= htmlspecialchars($c['department_name'] ?? '-') ?></td>
              <td>
                <span class="study-mode-badge <?= htmlspecialchars($c['study_mode'] ?? '') ?>">
                  <?= htmlspecialchars($c['study_mode'] ?? '-') ?>
                </span>
              </td>
              <td><?= number_format($c['total_students']) ?></td>
              <td><?= htmlspecialchars($c['campus_name'] ?? '-') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ✅ STYLE -->
<style>
body{font-family:'Poppins',sans-serif;background:#f7f9fb;margin:0;}
.main-content{padding:25px;margin-left:250px;margin-top:90px;}
.page-header h1{color:#0072CE;margin-bottom:5px;}
.page-header .subtitle{color:#666;font-size:14px;margin-top:0;}

/* Summary Grid */
.summary-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:15px;margin-bottom:25px;}
.card{background:#fff;border-radius:10px;padding:20px;text-align:center;box-shadow:0 2px 6px rgba(0,0,0,0.08);transition:transform 0.3s;}
.card:hover{transform:translateY(-5px);box-shadow:0 5px 15px rgba(0,0,0,0.1);}
.card-icon{font-size:24px;color:#0072CE;margin-bottom:10px;}
.card h3{font-size:14px;color:#666;text-transform:uppercase;margin:0 0 10px;}
.card .stat-number{font-size:28px;font-weight:700;color:#00843D;margin:0;}

/* Sections */
.section{margin-top:30px;padding:20px;background:#fff;border-radius:10px;box-shadow:0 2px 6px rgba(0,0,0,0.05);}
.section h2{color:#0072CE;margin-top:0;margin-bottom:15px;font-size:18px;}
.section h2 i{margin-right:10px;}
.section h3{color:#333;margin-top:20px;margin-bottom:10px;font-size:16px;border-bottom:1px solid #eee;padding-bottom:5px;}

/* Info Box */
.info-box{background:#f8f9fa;padding:15px;border-radius:8px;border-left:4px solid #0072CE;}
.info-box p{margin:5px 0;}
.status-active{color:#00843D;font-weight:600;background:#e8f5e8;padding:2px 8px;border-radius:4px;}
.no-data{color:#C62828;font-style:italic;}

/* Attendance Summary */
.attendance-summary{display:flex;flex-wrap:wrap;gap:10px;margin-top:15px;}
.att-card{flex:1;min-width:120px;text-align:center;padding:15px;border-radius:8px;color:#fff;}
.att-card h3{margin:0 0 5px;font-size:14px;font-weight:600;}
.att-card p{margin:0;font-size:24px;font-weight:700;}
.att-card.present{background:#00843D;}
.att-card.absent{background:#C62828;}
.att-card.late{background:#ff9800;}
.att-card.excused{background:#2196f3;}
.att-card.total{background:#607d8b;}

/* Table Styles */
.table-wrapper{overflow:auto;background:#fff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.05);margin-top:10px;}
table{width:100%;border-collapse:collapse;}
th,td{padding:12px 15px;border-bottom:1px solid #eee;text-align:left;font-size:14px;}
thead th{background:#0072CE;color:#fff;position:sticky;top:0;}
tr:hover{background:#f3f8ff;}

/* Student Type Badge */
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
    color: #1565c0;
}
.student-type-badge.recourse {
    background-color: #fff3e0;
    color: #ef6c00;
}
.student-type-badge i {
    margin-right: 5px;
}

/* Study Mode Badge */
.study-mode-badge{display:inline-block;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;text-transform:uppercase;}
.study-mode-badge.Full-Time{background:#e3f2fd;color:#1565c0;}
.study-mode-badge.Part-Time{background:#f3e5f5;color:#7b1fa2;}
.study-mode-badge.Online{background:#e8f5e9;color:#2e7d32;}
.study-mode-badge.Distance{background:#fff3e0;color:#ef6c00;}

/* Export Buttons */
.export-box{margin:20px 0;text-align:center;}
.btn{border:none;padding:10px 20px;border-radius:6px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block;margin:0 5px;}
.btn.blue{background:#0072CE;color:#fff;}
.btn.red{background:#C62828;color:#fff;}
.btn:hover{opacity:0.9;transform:translateY(-2px);}

/* Faculty Admin Badge */
.faculty-admin-badge {
    display: inline-block;
    background-color: #FF6B00;
    color: white;
    padding: 3px 10px;
    border-radius: 4px;
    font-size: 12px;
    margin-left: 10px;
    vertical-align: middle;
}
.info-message {
    background-color: #e3f2fd;
    color: #1565c0;
    padding: 8px 12px;
    border-radius: 4px;
    margin: 10px 0 20px;
    font-size: 13px;
    border-left: 3px solid #0072CE;
}

@media(max-width:768px){
  .main-content{margin-left:0;padding:15px;}
  .summary-grid{grid-template-columns:repeat(2,1fr);}
  .attendance-summary{flex-direction:column;}
  .att-card{min-width:100%;}
  table{font-size:13px;}
  th,td{padding:8px 10px;}
}
</style>

<?php include('../includes/footer.php'); ?>
<?php ob_end_flush(); ?>