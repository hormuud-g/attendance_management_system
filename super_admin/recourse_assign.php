<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

if (!isset($_SESSION['user']) || strtolower($_SESSION['user']['role']) !== 'super_admin') {
    header("Location: ../login.php");
    exit;
}

$message = "";
$type = "";

/* ============================================================
   AJAX HANDLERS FOR HIERARCHY
============================================================ */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
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
    
    // GET STUDY GROUPS BY PROGRAM, DEPARTMENT, FACULTY & CAMPUS
    if ($_GET['ajax'] == 'get_study_groups_by_program') {
        $program_id = $_GET['program_id'] ?? 0;
        $department_id = $_GET['department_id'] ?? 0;
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
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
        $study_groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'study_groups' => $study_groups]);
        exit;
    }
    
    // GET ALL CLASSES BY CAMPUS (for when no student selected)
    if ($_GET['ajax'] == 'get_all_classes_by_campus') {
        $campus_id = $_GET['campus_id'] ?? 0;
        
        $sql = "
            SELECT c.class_id, c.class_name, c.study_mode,
                   p.program_name, d.department_name, f.faculty_name
            FROM classes c
            LEFT JOIN programs p ON c.program_id = p.program_id
            LEFT JOIN departments d ON c.department_id = d.department_id
            LEFT JOIN faculties f ON c.faculty_id = f.faculty_id
            WHERE c.campus_id = ?
            AND c.status = 'Active'
            ORDER BY c.class_name
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$campus_id]);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'classes' => $classes]);
        exit;
    }
    
    // GET STUDENTS BY FILTERS
    if ($_GET['ajax'] == 'get_students_by_filters') {
        $campus_id = $_GET['campus_id'] ?? 0;
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $department_id = $_GET['department_id'] ?? 0;
        $program_id = $_GET['program_id'] ?? 0;
        $class_id = $_GET['class_id'] ?? 0;
        $search = $_GET['q'] ?? '';
        
        $where = [];
        $params = [];
        
        if (!empty($campus_id)) {
            $where[] = "s.campus_id = ?";
            $params[] = $campus_id;
        }
        
        if (!empty($faculty_id)) {
            $where[] = "s.faculty_id = ?";
            $params[] = $faculty_id;
        }
        
        if (!empty($department_id)) {
            $where[] = "s.department_id = ?";
            $params[] = $department_id;
        }
        
        if (!empty($program_id)) {
            $where[] = "s.program_id = ?";
            $params[] = $program_id;
        }
        
        if (!empty($class_id)) {
            $where[] = "s.class_id = ?";
            $params[] = $class_id;
        }
        
        if (!empty($search)) {
            $where[] = "(s.full_name LIKE ? OR s.reg_no LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        $sql = "SELECT s.student_id, s.reg_no, s.full_name, 
                       s.campus_id, s.faculty_id, s.department_id, s.program_id,
                       c.campus_name, f.faculty_name, 
                       d.department_name, p.program_name,
                       cl.class_id, cl.class_name, cl.study_mode,
                       sem.semester_id, sem.semester_name
                FROM students s
                LEFT JOIN campus c ON s.campus_id = c.campus_id
                LEFT JOIN faculties f ON s.faculty_id = f.faculty_id
                LEFT JOIN departments d ON s.department_id = d.department_id
                LEFT JOIN programs p ON s.program_id = p.program_id
                LEFT JOIN classes cl ON s.class_id = cl.class_id
                LEFT JOIN semester sem ON s.semester_id = sem.semester_id
                WHERE s.status = 'active'";
        
        if (!empty($where)) {
            $sql .= " AND " . implode(" AND ", $where);
        }
        
        $sql .= " ORDER BY s.reg_no, s.full_name";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'students' => $students]);
        exit;
    }
    
    // GET SUBJECTS BY FULL HIERARCHY: Campus → Faculty → Department → Program → Class → Semester
    if ($_GET['ajax'] == 'get_subjects_by_full_hierarchy') {
        $campus_id = $_GET['campus_id'] ?? 0;
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $department_id = $_GET['department_id'] ?? 0;
        $program_id = $_GET['program_id'] ?? 0;
        $class_id = $_GET['class_id'] ?? 0;
        $semester_id = $_GET['semester_id'] ?? 0;
        
        if (!$campus_id || !$faculty_id || !$department_id || !$program_id || !$class_id || !$semester_id) {
            echo json_encode(['status' => 'success', 'subjects' => []]);
            exit;
        }
        
        $sql = "SELECT sub.subject_id, sub.subject_code, sub.subject_name,
                       sub.credit_hours, sub.description,
                       sem.semester_name,
                       p.program_name,
                       d.department_name,
                       f.faculty_name,
                       c.campus_name,
                       cl.class_name
                FROM subject sub
                LEFT JOIN semester sem ON sub.semester_id = sem.semester_id
                LEFT JOIN programs p ON sub.program_id = p.program_id
                LEFT JOIN departments d ON sub.department_id = d.department_id
                LEFT JOIN faculties f ON sub.faculty_id = f.faculty_id
                LEFT JOIN campus c ON sub.campus_id = c.campus_id
                LEFT JOIN classes cl ON sub.class_id = cl.class_id
                WHERE sub.status = 'active'
                AND sub.campus_id = ?
                AND sub.faculty_id = ?
                AND sub.department_id = ?
                AND sub.program_id = ?
                AND sub.class_id = ?
                AND sub.semester_id = ?
                ORDER BY sub.subject_code, sub.subject_name";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$campus_id, $faculty_id, $department_id, $program_id, $class_id, $semester_id]);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'subjects' => $subjects]);
        exit;
    }
    
    // GET CLASS DETAILS (for getting program_id, department_id, faculty_id)
    if ($_GET['ajax'] == 'get_class_details') {
        $class_id = $_GET['class_id'] ?? 0;
        
        $sql = "SELECT c.*, 
                       p.program_id, p.program_name, 
                       d.department_id, d.department_name, 
                       f.faculty_id, f.faculty_name,
                       camp.campus_id, camp.campus_name
                FROM classes c
                LEFT JOIN programs p ON c.program_id = p.program_id
                LEFT JOIN departments d ON c.department_id = d.department_id
                LEFT JOIN faculties f ON c.faculty_id = f.faculty_id
                LEFT JOIN campus camp ON c.campus_id = camp.campus_id
                WHERE c.class_id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$class_id]);
        $class = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'class' => $class]);
        exit;
    }
    
    // GET STUDENT DETAILS
    if ($_GET['ajax'] == 'get_student_details') {
        $student_id = $_GET['student_id'] ?? 0;
        
        $sql = "SELECT s.*, 
                       c.campus_name, f.faculty_name, 
                       d.department_name, p.program_name,
                       cl.class_name, cl.class_id,
                       sem.semester_id, sem.semester_name
                FROM students s
                LEFT JOIN campus c ON s.campus_id = c.campus_id
                LEFT JOIN faculties f ON s.faculty_id = f.faculty_id
                LEFT JOIN departments d ON s.department_id = d.department_id
                LEFT JOIN programs p ON s.program_id = p.program_id
                LEFT JOIN classes cl ON s.class_id = cl.class_id
                LEFT JOIN semester sem ON s.semester_id = sem.semester_id
                WHERE s.student_id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$student_id]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'student' => $student]);
        exit;
    }
    
    // GET RECOURSE HIERARCHY DETAILS
    if ($_GET['ajax'] == 'get_recourse_hierarchy') {
        $recourse_id = $_GET['recourse_id'] ?? 0;
        
        $sql = "SELECT rs.*,
                       s.reg_no, s.full_name,
                       oc.campus_name AS original_campus_name,
                       ofc.faculty_name AS original_faculty_name,
                       od.department_name AS original_department_name,
                       op.program_name AS original_program_name,
                       ocl.class_name AS original_class_name,
                       osem.semester_name AS original_semester_name,
                       rc.campus_name AS recourse_campus_name,
                       rf.faculty_name AS recourse_faculty_name,
                       rd.department_name AS recourse_department_name,
                       rp.program_name AS recourse_program_name,
                       rcl.class_name AS recourse_class_name,
                       rsem.semester_name AS recourse_semester_name,
                       sub.subject_code, sub.subject_name,
                       at.term_name
                FROM recourse_student rs
                LEFT JOIN students s ON rs.student_id = s.student_id
                LEFT JOIN campus oc ON rs.original_campus_id = oc.campus_id
                LEFT JOIN faculties ofc ON rs.original_faculty_id = ofc.faculty_id
                LEFT JOIN departments od ON rs.original_department_id = od.department_id
                LEFT JOIN programs op ON rs.original_program_id = op.program_id
                LEFT JOIN classes ocl ON rs.original_class_id = ocl.class_id
                LEFT JOIN semester osem ON rs.original_semester_id = osem.semester_id
                LEFT JOIN campus rc ON rs.recourse_campus_id = rc.campus_id
                LEFT JOIN faculties rf ON rs.recourse_faculty_id = rf.faculty_id
                LEFT JOIN departments rd ON rs.recourse_department_id = rd.department_id
                LEFT JOIN programs rp ON rs.recourse_program_id = rp.program_id
                LEFT JOIN classes rcl ON rs.recourse_class_id = rcl.class_id
                LEFT JOIN semester rsem ON rs.recourse_semester_id = rsem.semester_id
                LEFT JOIN subject sub ON rs.subject_id = sub.subject_id
                LEFT JOIN academic_term at ON rs.academic_term_id = at.academic_term_id
                WHERE rs.recourse_id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$recourse_id]);
        $recourse = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'recourse' => $recourse]);
        exit;
    }
    
    // GET STATISTICS
    if ($_GET['ajax'] == 'get_statistics') {
        $total = $pdo->query("SELECT COUNT(*) FROM recourse_student")->fetchColumn();
        $active = $pdo->query("SELECT COUNT(*) FROM recourse_student WHERE status = 'active'")->fetchColumn();
        $completed = $pdo->query("SELECT COUNT(*) FROM recourse_student WHERE status = 'completed'")->fetchColumn();
        $cancelled = $pdo->query("SELECT COUNT(*) FROM recourse_student WHERE status = 'cancelled'")->fetchColumn();
        
        echo json_encode([
            'status' => 'success',
            'statistics' => [
                'total' => $total,
                'active' => $active,
                'completed' => $completed,
                'cancelled' => $cancelled
            ]
        ]);
        exit;
    }
}

/* ============================================================
   FILTER SYSTEM
============================================================ */
$filter_campus = $_GET['filter_campus'] ?? '';
$filter_faculty = $_GET['filter_faculty'] ?? '';
$filter_department = $_GET['filter_department'] ?? '';
$filter_program = $_GET['filter_program'] ?? '';
$filter_class = $_GET['filter_class'] ?? '';
$filter_semester = $_GET['filter_semester'] ?? '';
$filter_subject = $_GET['filter_subject'] ?? '';
$filter_status = $_GET['filter_status'] ?? '';
$search = trim($_GET['q'] ?? '');

// Build WHERE conditions for filter
$whereConditions = [];
$params = [];

// Search condition
if (!empty($search)) {
    $whereConditions[] = "(s.full_name LIKE ? OR s.reg_no LIKE ? OR sub.subject_code LIKE ? OR sub.subject_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Filter conditions
if (!empty($filter_campus)) {
    $whereConditions[] = "s.campus_id = ?";
    $params[] = $filter_campus;
}

if (!empty($filter_faculty)) {
    $whereConditions[] = "s.faculty_id = ?";
    $params[] = $filter_faculty;
}

if (!empty($filter_department)) {
    $whereConditions[] = "s.department_id = ?";
    $params[] = $filter_department;
}

if (!empty($filter_program)) {
    $whereConditions[] = "s.program_id = ?";
    $params[] = $filter_program;
}

if (!empty($filter_class)) {
    $whereConditions[] = "s.class_id = ?";
    $params[] = $filter_class;
}

if (!empty($filter_semester)) {
    $whereConditions[] = "rs.original_semester_id = ?";
    $params[] = $filter_semester;
}

if (!empty($filter_subject)) {
    $whereConditions[] = "rs.subject_id = ?";
    $params[] = $filter_subject;
}

if (!empty($filter_status)) {
    $whereConditions[] = "rs.status = ?";
    $params[] = $filter_status;
}

/* ============================================================
   CRUD OPERATIONS
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $pdo->beginTransaction();

        if ($_POST['action'] === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM recourse_student WHERE recourse_id = ?");
            $stmt->execute([$_POST['recourse_id']]);
            $message = "🗑️ Recourse record deleted successfully!";
            $type = "success";
        } 
        else if ($_POST['action'] === 'add') {
            // Check for duplicate recourse entry
            $check = $pdo->prepare("SELECT COUNT(*) FROM recourse_student 
                                   WHERE student_id = ? AND subject_id = ? AND academic_term_id = ?");
            $check->execute([
                $_POST['student_id'],
                $_POST['subject_id'],
                $_POST['academic_term_id']
            ]);
            
            if ($check->fetchColumn() > 0) {
                throw new Exception("❌ This student already has a recourse for this subject in the selected academic term!");
            }
            
            // Get student's current campus, semester, and class for original details
            $student_info = $pdo->prepare("SELECT campus_id, faculty_id, department_id, program_id, class_id, semester_id FROM students WHERE student_id = ?");
            $student_info->execute([$_POST['student_id']]);
            $student = $student_info->fetch(PDO::FETCH_ASSOC);
            
            if (!$student) {
                throw new Exception("❌ Student not found!");
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO recourse_student 
                (student_id, subject_id, 
                 original_campus_id, original_faculty_id, original_department_id, original_program_id, original_class_id, original_semester_id,
                 recourse_campus_id, recourse_faculty_id, recourse_department_id, recourse_program_id, recourse_class_id, recourse_semester_id,
                 academic_term_id, reason, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $_POST['student_id'],
                $_POST['subject_id'],
                $student['campus_id'], // original_campus_id from student
                $student['faculty_id'], // original_faculty_id from student
                $student['department_id'], // original_department_id from student
                $student['program_id'], // original_program_id from student
                $student['class_id'], // original_class_id from student
                $student['semester_id'], // original_semester_id from student
                $_POST['recourse_campus_id'],
                $_POST['recourse_faculty_id'],
                $_POST['recourse_department_id'],
                $_POST['recourse_program_id'],
                $_POST['recourse_class_id'],
                $_POST['recourse_semester_id'],
                $_POST['academic_term_id'],
                $_POST['reason'],
                $_POST['status'] ?? 'active'
            ]);
            
            $message = "✅ Recourse record added successfully!";
            $type = "success";
        } 
        else if ($_POST['action'] === 'update') {
            // Check for duplicate (excluding current record)
            $check = $pdo->prepare("SELECT COUNT(*) FROM recourse_student 
                                   WHERE student_id = ? AND subject_id = ? AND academic_term_id = ?
                                   AND recourse_id != ?");
            $check->execute([
                $_POST['student_id'],
                $_POST['subject_id'],
                $_POST['academic_term_id'],
                $_POST['recourse_id']
            ]);
            
            if ($check->fetchColumn() > 0) {
                throw new Exception("❌ This student already has a recourse for this subject in the selected academic term!");
            }
            
            $stmt = $pdo->prepare("
                UPDATE recourse_student SET
                    student_id = ?,
                    subject_id = ?,
                    original_campus_id = ?,
                    original_faculty_id = ?,
                    original_department_id = ?,
                    original_program_id = ?,
                    original_class_id = ?,
                    original_semester_id = ?,
                    recourse_campus_id = ?,
                    recourse_faculty_id = ?,
                    recourse_department_id = ?,
                    recourse_program_id = ?,
                    recourse_class_id = ?,
                    recourse_semester_id = ?,
                    academic_term_id = ?,
                    reason = ?,
                    status = ?
                WHERE recourse_id = ?
            ");
            
            $stmt->execute([
                $_POST['student_id'],
                $_POST['subject_id'],
                $_POST['original_campus_id'],
                $_POST['original_faculty_id'],
                $_POST['original_department_id'],
                $_POST['original_program_id'],
                $_POST['original_class_id'],
                $_POST['original_semester_id'],
                $_POST['recourse_campus_id'],
                $_POST['recourse_faculty_id'],
                $_POST['recourse_department_id'],
                $_POST['recourse_program_id'],
                $_POST['recourse_class_id'],
                $_POST['recourse_semester_id'],
                $_POST['academic_term_id'],
                $_POST['reason'],
                $_POST['status'] ?? 'active',
                $_POST['recourse_id']
            ]);
            
            $message = "✏️ Recourse record updated successfully!";
            $type = "success";
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "❌ " . $e->getMessage();
        $type = "error";
    }
}

/* ============================================================
   FETCH DATA WITH FILTERS
============================================================ */
$campuses = $pdo->query("SELECT * FROM campus WHERE status='active' ORDER BY campus_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$semesters = $pdo->query("SELECT * FROM semester WHERE status='active' ORDER BY semester_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$academic_terms = $pdo->query("SELECT * FROM academic_term WHERE status='active' ORDER BY term_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$subjects = $pdo->query("SELECT * FROM subject WHERE status='active' ORDER BY subject_code ASC")->fetchAll(PDO::FETCH_ASSOC);

// Get filter options based on selected values
$filter_faculties = [];
if (!empty($filter_campus)) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT f.faculty_id, f.faculty_name 
        FROM faculties f
        JOIN faculty_campus fc ON f.faculty_id = fc.faculty_id
        WHERE fc.campus_id = ?
        AND f.status = 'active'
        ORDER BY f.faculty_name
    ");
    $stmt->execute([$filter_campus]);
    $filter_faculties = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$filter_departments = [];
if (!empty($filter_faculty) && !empty($filter_campus)) {
    $stmt = $pdo->prepare("
        SELECT department_id, department_name 
        FROM departments 
        WHERE faculty_id = ? 
        AND campus_id = ?
        AND status = 'active'
        ORDER BY department_name
    ");
    $stmt->execute([$filter_faculty, $filter_campus]);
    $filter_departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$filter_programs = [];
if (!empty($filter_department) && !empty($filter_faculty) && !empty($filter_campus)) {
    $stmt = $pdo->prepare("
        SELECT program_id, program_name 
        FROM programs 
        WHERE department_id = ? 
        AND faculty_id = ?
        AND campus_id = ?
        AND status = 'active'
        ORDER BY program_name
    ");
    $stmt->execute([$filter_department, $filter_faculty, $filter_campus]);
    $filter_programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$filter_classes = [];
if (!empty($filter_program) && !empty($filter_department) && !empty($filter_faculty) && !empty($filter_campus)) {
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
    $stmt->execute([$filter_program, $filter_department, $filter_faculty, $filter_campus]);
    $filter_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Build main query with filters
$sql = "
    SELECT rs.*,
           s.student_id, s.reg_no, s.full_name AS student_name,
           sub.subject_id, sub.subject_code, sub.subject_name,
           oc.campus_name AS original_campus_name,
           ofc.faculty_name AS original_faculty_name,
           od.department_name AS original_department_name,
           op.program_name AS original_program_name,
           ocl.class_name AS original_class_name,
           osem.semester_name AS original_semester_name,
           rc.campus_name AS recourse_campus_name,
           rf.faculty_name AS recourse_faculty_name,
           rd.department_name AS recourse_department_name,
           rp.program_name AS recourse_program_name,
           rcl.class_name AS recourse_class_name,
           rsem.semester_name AS recourse_semester_name,
           at.term_name,
           c.campus_name,
           f.faculty_name,
           d.department_name,
           p.program_name,
           cls.class_name
    FROM recourse_student rs
    LEFT JOIN students s ON rs.student_id = s.student_id
    LEFT JOIN subject sub ON rs.subject_id = sub.subject_id
    LEFT JOIN campus oc ON rs.original_campus_id = oc.campus_id
    LEFT JOIN faculties ofc ON rs.original_faculty_id = ofc.faculty_id
    LEFT JOIN departments od ON rs.original_department_id = od.department_id
    LEFT JOIN programs op ON rs.original_program_id = op.program_id
    LEFT JOIN classes ocl ON rs.original_class_id = ocl.class_id
    LEFT JOIN semester osem ON rs.original_semester_id = osem.semester_id
    LEFT JOIN campus rc ON rs.recourse_campus_id = rc.campus_id
    LEFT JOIN faculties rf ON rs.recourse_faculty_id = rf.faculty_id
    LEFT JOIN departments rd ON rs.recourse_department_id = rd.department_id
    LEFT JOIN programs rp ON rs.recourse_program_id = rp.program_id
    LEFT JOIN classes rcl ON rs.recourse_class_id = rcl.class_id
    LEFT JOIN semester rsem ON rs.recourse_semester_id = rsem.semester_id
    LEFT JOIN academic_term at ON rs.academic_term_id = at.academic_term_id
    LEFT JOIN campus c ON s.campus_id = c.campus_id
    LEFT JOIN faculties f ON s.faculty_id = f.faculty_id
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN programs p ON s.program_id = p.program_id
    LEFT JOIN classes cls ON s.class_id = cls.class_id
";

if (!empty($whereConditions)) {
    $sql .= " WHERE " . implode(" AND ", $whereConditions);
}

$sql .= " ORDER BY rs.created_at DESC, rs.status";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$recourse_students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get counts for statistics
$total_recourses = $pdo->query("SELECT COUNT(*) FROM recourse_student")->fetchColumn();
$active_recourses = $pdo->query("SELECT COUNT(*) FROM recourse_student WHERE status = 'active'")->fetchColumn();
$completed_recourses = $pdo->query("SELECT COUNT(*) FROM recourse_student WHERE status = 'completed'")->fetchColumn();
$cancelled_recourses = $pdo->query("SELECT COUNT(*) FROM recourse_student WHERE status = 'cancelled'")->fetchColumn();

include('../includes/header.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recourse Students Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    /* ===========================================
       MAIN LAYOUT & STRUCTURE
    =========================================== */
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Poppins', 'Segoe UI', 'Roboto', sans-serif;
        background: linear-gradient(135deg, #f7f9fb 0%, #eef2f6 100%);
        color: #333;
        line-height: 1.6;
        overflow-x: hidden;
        min-height: 100vh;
    }

    .main-content {
        padding: 30px;
        margin-left: 250px;
        margin-top: 90px;
        transition: margin-left 0.3s ease;
        min-height: calc(100vh - 90px);
    }

    /* Sidebar collapsed state */
    .sidebar.collapsed ~ .main-content {
        margin-left: 70px;
    }

    /* ===========================================
       STATISTICS CARDS - ENHANCED
    =========================================== */
    .stats-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 20px;
        margin-bottom: 25px;
    }

    .card {
        background: white;
        padding: 25px;
        border-radius: 15px;
        box-shadow: 0 6px 15px rgba(0,0,0,0.08);
        text-align: center;
        transition: all 0.4s ease;
        position: relative;
        overflow: hidden;
        border: 1px solid rgba(0,0,0,0.05);
    }

    .card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 4px;
        background: linear-gradient(90deg, #0072CE, #00843D);
    }

    .card:hover {
        transform: translateY(-5px);
        box-shadow: 0 12px 25px rgba(0,0,0,0.15);
    }

    .card h3 {
        font-size: 32px;
        margin: 0 0 10px 0;
        color: #0072CE;
        font-weight: 800;
        text-shadow: 1px 1px 3px rgba(0,0,0,0.1);
    }

    .card p {
        margin: 0;
        color: #666;
        font-size: 15px;
        font-weight: 600;
        letter-spacing: 0.5px;
    }

    .card-icon {
        font-size: 28px;
        margin-bottom: 15px;
        color: #0072CE;
        opacity: 0.8;
    }

    /* ===========================================
       TOP BAR - ENHANCED
    =========================================== */
    .top-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: white;
        padding: 20px 25px;
        border-radius: 15px;
        box-shadow: 0 6px 15px rgba(0,0,0,0.08);
        margin-bottom: 20px;
        border-left: 5px solid #0072CE;
    }

    .top-bar h2 {
        color: #0072CE;
        margin: 0;
        font-size: 24px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .top-bar h2 i {
        font-size: 28px;
        color: #00843D;
    }

    /* ===========================================
       BUTTONS - ENHANCED
    =========================================== */
    .btn {
        border: none;
        padding: 10px 18px;
        border-radius: 8px;
        font-weight: 700;
        cursor: pointer;
        margin: 3px;
        font-size: 14px;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        letter-spacing: 0.5px;
        text-transform: uppercase;
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
        20% {
            transform: scale(25, 25);
            opacity: 0.3;
        }
        100% {
            opacity: 0;
            transform: scale(40, 40);
        }
    }

    .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(0,0,0,0.15);
    }

    .btn:active {
        transform: translateY(0);
    }

    .btn.blue {
        background: linear-gradient(135deg, #0072CE, #005ea6);
        color: #fff;
        border: 2px solid #005ea6;
    }

    .btn.blue:hover {
        background: linear-gradient(135deg, #005ea6, #004a8c);
    }

    .btn.green {
        background: linear-gradient(135deg, #00843D, #006a31);
        color: #fff;
        border: 2px solid #006a31;
    }

    .btn.green:hover {
        background: linear-gradient(135deg, #006a31, #005026);
    }

    .btn.red {
        background: linear-gradient(135deg, #C62828, #a82323);
        color: #fff;
        border: 2px solid #a82323;
    }

    .btn.red:hover {
        background: linear-gradient(135deg, #a82323, #8c1e1e);
    }

    .btn.gray {
        background: linear-gradient(135deg, #6c757d, #5a6268);
        color: #fff;
        border: 2px solid #5a6268;
    }

    .btn.gray:hover {
        background: linear-gradient(135deg, #5a6268, #495057);
    }

    .btn.orange {
        background: linear-gradient(135deg, #FF9800, #F57C00);
        color: #fff;
        border: 2px solid #F57C00;
    }

    .btn.orange:hover {
        background: linear-gradient(135deg, #F57C00, #E65100);
    }

    .btn i {
        font-size: 16px;
    }

    /* ===========================================
       ALERTS - ENHANCED
    =========================================== */
    .alert {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        padding: 25px 30px;
        border-radius: 12px;
        color: #fff;
        font-weight: 700;
        z-index: 9999;
        animation: fadeIn 0.4s ease;
        min-width: 350px;
        text-align: center;
        box-shadow: 0 8px 25px rgba(0,0,0,0.2);
        backdrop-filter: blur(10px);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
    }

    .alert.hide {
        opacity: 0;
        transform: translate(-50%, -60%);
        transition: all 0.5s ease;
    }

    .alert-success {
        background: linear-gradient(135deg, #00843D, #006a31);
        border-left: 6px solid #004d24;
    }

    .alert-error {
        background: linear-gradient(135deg, #C62828, #a82323);
        border-left: 6px solid #8c1e1e;
    }

    .close-alert {
        background: rgba(255,255,255,0.2);
        border: none;
        color: white;
        width: 30px;
        height: 30px;
        border-radius: 50%;
        cursor: pointer;
        font-size: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s ease;
    }

    .close-alert:hover {
        background: rgba(255,255,255,0.3);
        transform: rotate(90deg);
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translate(-50%, -60%);
        }
        to {
            opacity: 1;
            transform: translate(-50%, -50%);
        }
    }

    /* ===========================================
       FILTER BOX - ENHANCED
    =========================================== */
    .filter-box {
        background: white;
        padding: 25px;
        border-radius: 15px;
        box-shadow: 0 6px 15px rgba(0,0,0,0.08);
        margin-bottom: 20px;
        border: 1px solid rgba(0,0,0,0.05);
    }

    .filter-box .grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 20px;
    }

    .filter-box label {
        display: block;
        font-weight: 700;
        color: #0072CE;
        font-size: 14px;
        margin-bottom: 8px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .filter-box input,
    .filter-box select {
        width: 100%;
        padding: 10px 12px;
        border: 2px solid #e0e0e0;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.3s ease;
        background: white;
        font-family: inherit;
    }

    .filter-box input:focus,
    .filter-box select:focus {
        outline: none;
        border-color: #0072CE;
        box-shadow: 0 0 0 3px rgba(0, 114, 206, 0.15);
    }

    .filter-box input::placeholder {
        color: #aaa;
        font-style: italic;
    }

    .filter-actions {
        grid-column: 1 / -1;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 10px;
        padding-top: 15px;
        border-top: 2px solid #f0f0f0;
    }

    /* ===========================================
       TABLE STYLES - ENHANCED
    =========================================== */
    .table-responsive {
        width: 100%;
        overflow-x: auto;
        overflow-y: auto;
        max-height: 500px;
        background: white;
        border-radius: 12px;
        box-shadow: 0 6px 15px rgba(0,0,0,0.08);
        margin-top: 15px;
        border: 1px solid rgba(0,0,0,0.05);
    }

    .table-responsive::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }

    .table-responsive::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 4px;
    }

    .table-responsive::-webkit-scrollbar-thumb {
        background: linear-gradient(135deg, #0072CE, #00843D);
        border-radius: 4px;
    }

    .table-responsive::-webkit-scrollbar-thumb:hover {
        background: linear-gradient(135deg, #005ea6, #006a31);
    }

    table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        background: white;
        min-width: 1300px;
    }

    thead {
        position: sticky;
        top: 0;
        z-index: 20;
    }

    thead th {
        background: linear-gradient(135deg, #0072CE, #005ea6);
        color: #fff;
        padding: 16px 18px;
        text-align: left;
        font-weight: 700;
        font-size: 15px;
        border-right: 1px solid rgba(255,255,255,0.15);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        position: relative;
    }

    thead th:last-child {
        border-right: none;
    }

    thead th::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        width: 100%;
        height: 3px;
        background: #00843D;
    }

    tbody tr {
        border-bottom: 1px solid #eee;
        transition: all 0.3s ease;
        position: relative;
    }

    tbody tr:hover {
        background: linear-gradient(90deg, rgba(0, 114, 206, 0.05) 0%, rgba(0, 132, 61, 0.05) 100%);
        transform: scale(1.002);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }

    tbody tr.active {
        background: linear-gradient(90deg, rgba(0, 114, 206, 0.1) 0%, rgba(0, 132, 61, 0.1) 100%);
    }

    tbody td {
        padding: 14px 18px;
        text-align: left;
        font-size: 14px;
        vertical-align: top;
        transition: all 0.3s ease;
    }

    tbody td:first-child {
        font-weight: 700;
        color: #0072CE;
        border-left: 3px solid transparent;
    }

    tbody tr:hover td:first-child {
        border-left: 3px solid #0072CE;
    }

    tbody td small {
        display: block;
        color: #666;
        font-size: 13px;
        margin-top: 4px;
        line-height: 1.4;
    }

    tbody td em {
        color: #999;
        font-style: italic;
        font-size: 13px;
    }

    /* ===========================================
       STATUS BADGES - ENHANCED
    =========================================== */
    .status-badge {
        display: inline-block;
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        min-width: 100px;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }

    .status-badge::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
        transition: left 0.5s ease;
    }

    .status-badge:hover::before {
        left: 100%;
    }

    .status-active {
        background: linear-gradient(135deg, #4CAF50, #2E7D32);
        color: white;
        border: 2px solid #2E7D32;
    }

    .status-completed {
        background: linear-gradient(135deg, #FF9800, #F57C00);
        color: white;
        border: 2px solid #F57C00;
    }

    .status-cancelled {
        background: linear-gradient(135deg, #F44336, #D32F2F);
        color: white;
        border: 2px solid #D32F2F;
    }

    /* ===========================================
       ACTION BUTTONS - ENHANCED
    =========================================== */
    .action-buttons {
        display: flex;
        gap: 8px;
        justify-content: center;
        align-items: center;
        flex-wrap: nowrap;
    }

    .action-btn {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        border: none;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
        color: white;
        box-shadow: 0 3px 8px rgba(0,0,0,0.15);
    }

    .action-btn::before {
        content: '';
        position: absolute;
        top: 50%;
        left: 50%;
        width: 0;
        height: 0;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.2);
        transform: translate(-50%, -50%);
        transition: width 0.6s, height 0.6s;
    }

    .action-btn:active::before {
        width: 300px;
        height: 300px;
    }

    .action-btn:hover {
        transform: translateY(-3px) scale(1.05);
        box-shadow: 0 6px 15px rgba(0,0,0,0.2);
    }

    .action-btn:active {
        transform: translateY(-1px) scale(0.98);
    }

    .action-btn.view {
        background: linear-gradient(135deg, #00843D, #006a31);
        border: 2px solid #006a31;
    }

    .action-btn.view:hover {
        background: linear-gradient(135deg, #006a31, #005026);
    }

    .action-btn.edit {
        background: linear-gradient(135deg, #0072CE, #005ea6);
        border: 2px solid #005ea6;
    }

    .action-btn.edit:hover {
        background: linear-gradient(135deg, #005ea6, #004a8c);
    }

    .action-btn.delete {
        background: linear-gradient(135deg, #C62828, #a82323);
        border: 2px solid #a82323;
    }

    .action-btn.delete:hover {
        background: linear-gradient(135deg, #a82323, #8c1e1e);
    }

    .action-btn i {
        pointer-events: none;
        z-index: 1;
    }

    /* Tooltip for buttons */
    .action-btn::after {
        content: attr(title);
        position: absolute;
        bottom: 100%;
        left: 50%;
        transform: translateX(-50%);
        background: rgba(0,0,0,0.9);
        color: white;
        padding: 6px 12px;
        border-radius: 6px;
        font-size: 12px;
        white-space: nowrap;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
        z-index: 1000;
        pointer-events: none;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .action-btn:hover::after {
        opacity: 1;
        visibility: visible;
        bottom: calc(100% + 8px);
    }

    .action-btn::after {
        margin-bottom: 5px;
    }

    /* ===========================================
       MODAL STYLES - ENHANCED
    =========================================== */
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.7);
        justify-content: center;
        align-items: center;
        z-index: 9998;
        backdrop-filter: blur(5px);
        padding: 20px;
        animation: modalBgFadeIn 0.3s ease;
    }

    @keyframes modalBgFadeIn {
        from {
            background: rgba(0,0,0,0);
            backdrop-filter: blur(0);
        }
        to {
            background: rgba(0,0,0,0.7);
            backdrop-filter: blur(5px);
        }
    }

    .modal.show {
        display: flex;
    }

    .modal-content {
        background: white;
        width: 100%;
        max-width: 1000px;
        border-radius: 20px;
        padding: 30px;
        position: relative;
        overflow-y: auto;
        max-height: 90vh;
        animation: modalSlideIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        margin: auto;
        border: 1px solid rgba(255,255,255,0.1);
    }

    @keyframes modalSlideIn {
        from {
            transform: translateY(-50px) scale(0.9);
            opacity: 0;
        }
        to {
            transform: translateY(0) scale(1);
            opacity: 1;
        }
    }

    .close-modal {
        position: absolute;
        top: 20px;
        right: 25px;
        font-size: 28px;
        cursor: pointer;
        color: #666;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        transition: all 0.3s ease;
        z-index: 10;
        background: rgba(0,0,0,0.05);
    }

    .close-modal:hover {
        background: rgba(0,0,0,0.1);
        color: #333;
        transform: rotate(90deg);
    }

    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        padding-bottom: 20px;
        border-bottom: 3px solid #0072CE;
    }

    .modal-header h2 {
        color: #0072CE;
        margin: 0;
        font-size: 26px;
        font-weight: 800;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .modal-header h2 i {
        font-size: 28px;
        color: #00843D;
    }

    .modal-content h4 {
        color: #0072CE;
        margin: 15px 0 10px 0;
        font-size: 18px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding-left: 10px;
        border-left: 4px solid #0072CE;
    }

    .modal-content form .grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }

    .modal-content form label {
        display: block;
        font-weight: 700;
        color: #0072CE;
        font-size: 14px;
        margin-bottom: 8px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .modal-content form input,
    .modal-content form select,
    .modal-content form textarea {
        width: 100%;
        padding: 12px 15px;
        border: 2px solid #e0e0e0;
        border-radius: 10px;
        font-size: 14px;
        transition: all 0.3s ease;
        background: white;
        font-family: inherit;
    }

    .modal-content form input:focus,
    .modal-content form select:focus,
    .modal-content form textarea:focus {
        outline: none;
        border-color: #0072CE;
        box-shadow: 0 0 0 3px rgba(0, 114, 206, 0.15);
        transform: translateY(-1px);
    }

    .modal-content form input[readonly] {
        background: linear-gradient(135deg, #f8f9fa, #e9ecef);
        cursor: not-allowed;
        color: #666;
        border-color: #ddd;
    }

    .modal-content form textarea {
        resize: vertical;
        min-height: 100px;
        line-height: 1.6;
    }

    .save-btn {
        grid-column: 1 / -1;
        width: 100%;
        padding: 15px;
        font-size: 16px;
        font-weight: 800;
        margin-top: 20px;
        border-radius: 12px;
        letter-spacing: 1px;
    }

    /* ===========================================
       LOADING OVERLAY
    =========================================== */
    .loading-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(255,255,255,0.9);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        display: none;
        backdrop-filter: blur(5px);
    }

    .loading-spinner {
        width: 60px;
        height: 60px;
        border: 4px solid #f3f3f3;
        border-top: 4px solid #0072CE;
        border-radius: 50%;
        animation: spin 1.5s linear infinite;
        margin-bottom: 20px;
    }

    .loading-text {
        color: #0072CE;
        font-size: 18px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 2px;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    /* ===========================================
       CONFIRMATION MODAL
    =========================================== */
    .confirm-modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.8);
        z-index: 10000;
        justify-content: center;
        align-items: center;
        backdrop-filter: blur(10px);
    }

    .confirm-content {
        background: white;
        padding: 40px;
        border-radius: 20px;
        max-width: 450px;
        width: 90%;
        text-align: center;
        box-shadow: 0 20px 60px rgba(0,0,0,0.4);
        animation: confirmSlideIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        border: 2px solid #0072CE;
    }

    @keyframes confirmSlideIn {
        from {
            transform: translateY(-30px) scale(0.9);
            opacity: 0;
        }
        to {
            transform: translateY(0) scale(1);
            opacity: 1;
        }
    }

    .confirm-content h3 {
        color: #C62828;
        margin: 0 0 15px 0;
        font-size: 24px;
        font-weight: 800;
    }

    .confirm-content p {
        color: #666;
        font-size: 16px;
        line-height: 1.6;
        margin: 0 0 25px 0;
    }

    .confirm-buttons {
        display: flex;
        gap: 15px;
        justify-content: center;
    }

    .confirm-buttons .btn {
        min-width: 120px;
        padding: 12px 25px;
        font-size: 15px;
        font-weight: 800;
    }

    /* ===========================================
       HIERARCHY SECTION STYLING
    =========================================== */
    .hierarchy-section {
        grid-column: span 4;
        background: linear-gradient(135deg, #f8f9fa, #e9ecef);
        padding: 20px;
        border-radius: 12px;
        margin-bottom: 20px;
        border: 2px solid #e0e0e0;
        position: relative;
        overflow: hidden;
    }

    .hierarchy-section::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 4px;
        background: linear-gradient(90deg, #0072CE, #00843D);
    }

    .hierarchy-section h4 {
        margin-top: 0;
        margin-bottom: 20px;
        color: #0072CE;
        border-bottom: 3px solid #0072CE;
        padding-bottom: 10px;
        font-size: 18px;
        font-weight: 800;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .hierarchy-section h4 i {
        color: #00843D;
    }

    .hierarchy-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 20px;
    }

    /* Student search container */
    .student-search-container {
        position: relative;
        grid-column: span 2;
    }

    /* Search results dropdown */
    #student_results {
        position: absolute;
        background: white;
        border: 2px solid #0072CE;
        border-radius: 10px;
        max-height: 250px;
        overflow-y: auto;
        width: 100%;
        z-index: 1000;
        box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        margin-top: 5px;
        display: none;
    }

    #student_results div {
        padding: 12px 15px;
        cursor: pointer;
        border-bottom: 1px solid #eee;
        transition: all 0.3s ease;
        font-size: 14px;
    }

    #student_results div:last-child {
        border-bottom: none;
    }

    #student_results div:hover {
        background: linear-gradient(90deg, rgba(0, 114, 206, 0.1) 0%, rgba(0, 132, 61, 0.1) 100%);
        transform: translateX(5px);
    }

    #student_results div strong {
        display: block;
        font-weight: 700;
        margin-bottom: 3px;
        color: #0072CE;
    }

    #student_results div small {
        font-size: 12px;
        color: #666;
        line-height: 1.4;
    }

    /* ===========================================
       NO DATA MESSAGE
    =========================================== */
    .no-data {
        text-align: center;
        padding: 60px 20px;
        color: #666;
        font-size: 16px;
        background: white;
        border-radius: 15px;
        margin: 20px 0;
        box-shadow: 0 6px 15px rgba(0,0,0,0.08);
    }

    .no-data i {
        font-size: 64px;
        color: #0072CE;
        margin-bottom: 20px;
        display: block;
        opacity: 0.7;
    }

    .no-data p {
        margin: 0 0 20px 0;
        font-size: 18px;
        font-weight: 700;
        color: #0072CE;
    }

    /* ===========================================
       UTILITY CLASSES
    =========================================== */
    .hide {
        display: none !important;
    }

    .show {
        display: flex !important;
    }

    .text-center {
        text-align: center;
    }

    .text-right {
        text-align: right;
    }

    .text-left {
        text-align: left;
    }

    .mt-10 {
        margin-top: 10px;
    }

    .mb-10 {
        margin-bottom: 10px;
    }

    .ml-5 {
        margin-left: 5px;
    }

    .mr-5 {
        margin-right: 5px;
    }

    .no-print {
        /* For print styles */
    }

    .print-only {
        display: none;
    }

    /* ===========================================
       RESPONSIVE DESIGN
    =========================================== */
    @media (max-width: 1200px) {
        .main-content {
            margin-left: 70px;
        }
        
        .sidebar.collapsed ~ .main-content {
            margin-left: 70px;
        }
    }

    @media (max-width: 992px) {
        .filter-box .grid {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .modal-content form .grid {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .save-btn {
            grid-column: span 2;
        }
        
        .hierarchy-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 768px) {
        .main-content {
            padding: 20px;
            margin-left: 0;
            margin-top: 70px;
        }
        
        .top-bar {
            flex-direction: column;
            gap: 20px;
            align-items: flex-start;
        }
        
        .top-bar h2 {
            font-size: 20px;
        }
        
        .filter-box .grid {
            grid-template-columns: 1fr;
        }
        
        .stats-cards {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .modal-content {
            padding: 20px;
            max-width: 95%;
        }
        
        .modal-content form .grid {
            grid-template-columns: 1fr;
        }
        
        .save-btn {
            grid-column: span 1;
        }
        
        table {
            font-size: 13px;
        }
        
        thead th,
        tbody td {
            padding: 10px 12px;
        }
        
        .hierarchy-grid {
            grid-template-columns: 1fr;
        }
        
        .student-search-container {
            grid-column: span 1;
        }
        
        .action-buttons {
            flex-direction: column;
            gap: 5px;
        }
        
        .action-btn {
            width: 36px;
            height: 36px;
            font-size: 14px;
        }
    }

    @media (max-width: 576px) {
        .stats-cards {
            grid-template-columns: 1fr;
        }
        
        .card {
            padding: 20px;
        }
        
        .card h3 {
            font-size: 28px;
        }
        
        .btn {
            padding: 8px 15px;
            font-size: 13px;
        }
        
        .alert {
            min-width: 300px;
            padding: 20px 25px;
            font-size: 15px;
        }
        
        .confirm-content {
            padding: 30px 20px;
        }
        
        .confirm-buttons {
            flex-direction: column;
        }
        
        .confirm-buttons .btn {
            width: 100%;
        }
    }

    /* ===========================================
       PRINT STYLES
    =========================================== */
    @media print {
        .main-content {
            margin: 0;
            padding: 0;
        }
        
        .top-bar,
        .filter-box,
        .btn,
        .action-buttons,
        .no-print {
            display: none !important;
        }
        
        .table-responsive {
            overflow: visible;
            max-height: none;
            box-shadow: none;
            border: 1px solid #000;
        }
        
        table {
            width: 100%;
            border: 1px solid #000;
            page-break-inside: auto;
        }
        
        thead th {
            background: #000 !important;
            color: #fff !important;
            border-bottom: 2px solid #000;
            -webkit-print-color-adjust: exact;
        }
        
        tbody tr {
            border-bottom: 1px solid #000;
            page-break-inside: avoid;
            page-break-after: auto;
        }
        
        .print-only {
            display: block;
        }
    }

    /* ===========================================
       CUSTOM SCROLLBAR FOR MODAL
    =========================================== */
    .modal-content::-webkit-scrollbar {
        width: 10px;
    }

    .modal-content::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 5px;
    }

    .modal-content::-webkit-scrollbar-thumb {
        background: linear-gradient(135deg, #0072CE, #00843D);
        border-radius: 5px;
    }

    .modal-content::-webkit-scrollbar-thumb:hover {
        background: linear-gradient(135deg, #005ea6, #006a31);
    }

    /* ===========================================
       FORM VALIDATION STYLES
    =========================================== */
    input:invalid,
    select:invalid,
    textarea:invalid {
        border-color: #ff4444;
        background: rgba(255, 68, 68, 0.05);
    }

    input:valid,
    select:valid,
    textarea:valid {
        border-color: #00C851;
    }

    .form-error {
        color: #ff4444;
        font-size: 12px;
        margin-top: 5px;
        display: block;
        font-weight: 600;
    }

    /* ===========================================
       REASON TEXT TRUNCATION
    =========================================== */
    .reason-text {
        cursor: help;
        border-bottom: 1px dashed #999;
        transition: all 0.3s ease;
    }

    .reason-text:hover {
        color: #0072CE;
        border-bottom-color: #0072CE;
    }

    /* ===========================================
       ANIMATIONS
    =========================================== */
    @keyframes pulse {
        0% {
            box-shadow: 0 0 0 0 rgba(0, 114, 206, 0.4);
        }
        70% {
            box-shadow: 0 0 0 10px rgba(0, 114, 206, 0);
        }
        100% {
            box-shadow: 0 0 0 0 rgba(0, 114, 206, 0);
        }
    }

    .pulse {
        animation: pulse 2s infinite;
    }

    /* ===========================================
       BREADCRUMB STYLE
    =========================================== */
    .breadcrumb {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 20px;
        font-size: 14px;
        color: #666;
    }

    .breadcrumb a {
        color: #0072CE;
        text-decoration: none;
        font-weight: 600;
        transition: color 0.3s ease;
    }

    .breadcrumb a:hover {
        color: #005ea6;
        text-decoration: underline;
    }

    .breadcrumb i {
        font-size: 12px;
        color: #999;
    }
    </style>
</head>
<body>
    <div class="main-content">
        <!-- Loading Overlay -->
        <div class="loading-overlay" id="loadingOverlay">
            <div class="loading-spinner"></div>
            <div class="loading-text">Loading...</div>
        </div>

        <!-- Confirmation Modal -->
        <div class="confirm-modal" id="confirmModal">
            <div class="confirm-content">
                <h3 id="confirmTitle">Confirm Action</h3>
                <p id="confirmMessage">Are you sure you want to perform this action?</p>
                <div class="confirm-buttons">
                    <button class="btn gray" onclick="closeConfirmModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button class="btn red" id="confirmActionBtn">
                        <i class="fas fa-check"></i> Confirm
                    </button>
                </div>
            </div>
        </div>

        <!-- Alert Message -->
        <?php if($message): ?>
        <div class="alert <?= $type=='success'?'alert-success':'alert-error' ?>" id="alertMessage">
            <div class="alert-content">
                <?php if($type == 'success'): ?>
                    <i class="fas fa-check-circle" style="font-size: 20px;"></i>
                <?php else: ?>
                    <i class="fas fa-exclamation-triangle" style="font-size: 20px;"></i>
                <?php endif; ?>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
            <button class="close-alert" onclick="this.parentElement.remove()">×</button>
        </div>
        <script>
            setTimeout(() => {
                const alert = document.getElementById('alertMessage');
                if (alert) {
                    alert.classList.add('hide');
                    setTimeout(() => alert.remove(), 500);
                }
            }, 4000);
        </script>
        <?php endif; ?>

        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="../dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
            <i class="fas fa-chevron-right"></i>
            <span>Recourse Students Management</span>
        </div>

        <!-- STATISTICS CARDS -->
        <div class="stats-cards">
            <div class="card">
                <div class="card-icon">
                    <i class="fas fa-layer-group"></i>
                </div>
                <h3><?= number_format($total_recourses) ?></h3>
                <p>Total Recourses</p>
            </div>
            <div class="card">
                <div class="card-icon">
                    <i class="fas fa-play-circle"></i>
                </div>
                <h3><?= number_format($active_recourses) ?></h3>
                <p>Active Recourses</p>
            </div>
            <div class="card">
                <div class="card-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h3><?= number_format($completed_recourses) ?></h3>
                <p>Completed</p>
            </div>
            <div class="card">
                <div class="card-icon">
                    <i class="fas fa-times-circle"></i>
                </div>
                <h3><?= number_format($cancelled_recourses) ?></h3>
                <p>Cancelled</p>
            </div>
        </div>

        <!-- TOP BAR -->
        <div class="top-bar no-print">
            <h2><i class="fas fa-book-medical"></i> Recourse Students Management</h2>
            <div>
                <button class="btn gray" onclick="printTable()" title="Print Report">
                    <i class="fas fa-print"></i> Print
                </button>
                <button class="btn blue" onclick="exportToExcel()" title="Export to Excel">
                    <i class="fas fa-file-excel"></i> Export
                </button>
                <button class="btn orange" onclick="refreshStatistics()" title="Refresh Statistics">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
                <button class="btn green pulse" onclick="openModal('addModal')" title="Add New Recourse">
                    <i class="fas fa-plus-circle"></i> Add Recourse
                </button>
            </div>
        </div>

        <!-- FILTER FORM -->
        <div class="filter-box no-print">
            <form method="GET" id="filterForm">
                <div class="grid">
                    <!-- Campus -->
                    <div>
                        <label><i class="fas fa-university"></i> Campus</label>
                        <select name="filter_campus" id="filter_campus" onchange="onFilterCampusChange(this.value)">
                            <option value="">All Campuses</option>
                            <?php foreach($campuses as $c): ?>
                                <option value="<?= $c['campus_id'] ?>" <?= $filter_campus==$c['campus_id']?'selected':'' ?>>
                                    <?= htmlspecialchars($c['campus_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Faculty -->
                    <div>
                        <label><i class="fas fa-graduation-cap"></i> Faculty</label>
                        <select name="filter_faculty" id="filter_faculty" onchange="onFilterFacultyChange(this.value)" <?= empty($filter_faculties)?'disabled':'' ?>>
                            <option value="">All Faculties</option>
                            <?php foreach($filter_faculties as $f): ?>
                                <option value="<?= $f['faculty_id'] ?>" <?= $filter_faculty==$f['faculty_id']?'selected':'' ?>>
                                    <?= htmlspecialchars($f['faculty_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Department -->
                    <div>
                        <label><i class="fas fa-building"></i> Department</label>
                        <select name="filter_department" id="filter_department" onchange="onFilterDepartmentChange(this.value)" <?= empty($filter_departments)?'disabled':'' ?>>
                            <option value="">All Departments</option>
                            <?php foreach($filter_departments as $d): ?>
                                <option value="<?= $d['department_id'] ?>" <?= $filter_department==$d['department_id']?'selected':'' ?>>
                                    <?= htmlspecialchars($d['department_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Program -->
                    <div>
                        <label><i class="fas fa-book"></i> Program</label>
                        <select name="filter_program" id="filter_program" onchange="onFilterProgramChange(this.value)" <?= empty($filter_programs)?'disabled':'' ?>>
                            <option value="">All Programs</option>
                            <?php foreach($filter_programs as $p): ?>
                                <option value="<?= $p['program_id'] ?>" <?= $filter_program==$p['program_id']?'selected':'' ?>>
                                    <?= htmlspecialchars($p['program_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Class -->
                    <div>
                        <label><i class="fas fa-users"></i> Study Group</label>
                        <select name="filter_class" id="filter_class" <?= empty($filter_classes)?'disabled':'' ?>>
                            <option value="">All Study Groups</option>
                            <?php foreach($filter_classes as $cl): ?>
                                <option value="<?= $cl['class_id'] ?>" <?= $filter_class==$cl['class_id']?'selected':'' ?>>
                                    <?= htmlspecialchars($cl['class_name']) ?> (<?= htmlspecialchars($cl['study_mode'] ?: 'Regular') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Semester -->
                    <div>
                        <label><i class="fas fa-calendar-alt"></i> Original Semester</label>
                        <select name="filter_semester">
                            <option value="">All Semesters</option>
                            <?php foreach($semesters as $sem): ?>
                                <option value="<?= $sem['semester_id'] ?>" <?= $filter_semester==$sem['semester_id']?'selected':'' ?>>
                                    <?= htmlspecialchars($sem['semester_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Subject -->
                    <div>
                        <label><i class="fas fa-book-open"></i> Subject</label>
                        <select name="filter_subject">
                            <option value="">All Subjects</option>
                            <?php foreach($subjects as $sub): ?>
                                <option value="<?= $sub['subject_id'] ?>" <?= $filter_subject==$sub['subject_id']?'selected':'' ?>>
                                    <?= htmlspecialchars($sub['subject_code'] . ' - ' . $sub['subject_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Status -->
                    <div>
                        <label><i class="fas fa-tag"></i> Status</label>
                        <select name="filter_status">
                            <option value="">All Status</option>
                            <option value="active" <?= $filter_status=='active'?'selected':'' ?>>Active</option>
                            <option value="completed" <?= $filter_status=='completed'?'selected':'' ?>>Completed</option>
                            <option value="cancelled" <?= $filter_status=='cancelled'?'selected':'' ?>>Cancelled</option>
                        </select>
                    </div>

                    <!-- Search -->
                    <div>
                        <label><i class="fas fa-search"></i> Search</label>
                        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" 
                               placeholder="Search by student, reg no, or subject...">
                    </div>

                    <!-- Filter Actions -->
                    <div class="filter-actions">
                        <button type="submit" class="btn blue">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <button type="button" class="btn gray" onclick="resetFilter()">
                            <i class="fas fa-redo"></i> Reset Filters
                        </button>
                        <button type="button" class="btn orange" onclick="clearSearch()">
                            <i class="fas fa-times"></i> Clear Search
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- MAIN TABLE -->
        <div class="table-responsive">
            <table id="mainTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th><i class="fas fa-user-graduate"></i> Student</th>
                        <th><i class="fas fa-book"></i> Subject</th>
                        <th><i class="fas fa-history"></i> Original Details</th>
                        <th><i class="fas fa-redo"></i> Recourse Details</th>
                        <th><i class="fas fa-calendar"></i> Academic Term</th>
                        <th><i class="fas fa-comment"></i> Reason</th>
                        <th><i class="fas fa-tag"></i> Status</th>
                        <th><i class="fas fa-clock"></i> Created</th>
                        <th class="no-print"><i class="fas fa-cogs"></i> Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($recourse_students)): ?>
                        <tr>
                            <td colspan="10" class="no-data">
                                <i class="fas fa-inbox"></i>
                                <p>No recourse records found</p>
                                <?php if(!empty($whereConditions)): ?>
                                    <p style="color:#666; font-size:14px; font-weight:normal;">
                                        Try changing your filters or 
                                        <a href="javascript:void(0);" onclick="resetFilter()" style="color:#0072CE; text-decoration:underline;">
                                            clear all filters
                                        </a>
                                    </p>
                                <?php else: ?>
                                    <button class="btn green" onclick="openModal('addModal')" style="margin-top:10px;">
                                        <i class="fas fa-plus"></i> Add your first recourse record
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($recourse_students as $i=>$rs): ?>
                        <tr data-id="<?= $rs['recourse_id'] ?>" class="recourse-row">
                            <td><?= $i+1 ?></td>
                            <td>
                                <strong><?= htmlspecialchars($rs['reg_no']) ?></strong><br>
                                <small><?= htmlspecialchars($rs['student_name']) ?></small>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($rs['subject_code']) ?></strong><br>
                                <small><?= htmlspecialchars($rs['subject_name']) ?></small>
                            </td>
                            <td>
                                <strong><i class="fas fa-university"></i> Campus:</strong> <?= htmlspecialchars($rs['original_campus_name']) ?><br>
                                <strong><i class="fas fa-graduation-cap"></i> Faculty:</strong> <?= htmlspecialchars($rs['original_faculty_name']) ?><br>
                                <strong><i class="fas fa-building"></i> Dept:</strong> <?= htmlspecialchars($rs['original_department_name']) ?><br>
                                <strong><i class="fas fa-book"></i> Program:</strong> <?= htmlspecialchars($rs['original_program_name']) ?><br>
                                <strong><i class="fas fa-users"></i> Class:</strong> <?= htmlspecialchars($rs['original_class_name']) ?><br>
                                <strong><i class="fas fa-calendar-alt"></i> Semester:</strong> <?= htmlspecialchars($rs['original_semester_name']) ?>
                            </td>
                            <td>
                                <strong><i class="fas fa-university"></i> Campus:</strong> <?= htmlspecialchars($rs['recourse_campus_name']) ?><br>
                                <strong><i class="fas fa-graduation-cap"></i> Faculty:</strong> <?= htmlspecialchars($rs['recourse_faculty_name']) ?><br>
                                <strong><i class="fas fa-building"></i> Dept:</strong> <?= htmlspecialchars($rs['recourse_department_name']) ?><br>
                                <strong><i class="fas fa-book"></i> Program:</strong> <?= htmlspecialchars($rs['recourse_program_name']) ?><br>
                                <strong><i class="fas fa-users"></i> Class:</strong> <?= htmlspecialchars($rs['recourse_class_name']) ?><br>
                                <strong><i class="fas fa-calendar-alt"></i> Semester:</strong> <?= htmlspecialchars($rs['recourse_semester_name']) ?>
                            </td>
                            <td>
                                <i class="fas fa-calendar" style="color:#0072CE;"></i>
                                <?= htmlspecialchars($rs['term_name']) ?>
                            </td>
                            <td>
                                <?php if(!empty($rs['reason'])): ?>
                                    <span class="reason-text" title="<?= htmlspecialchars($rs['reason']) ?>">
                                        <?= strlen($rs['reason']) > 50 ? htmlspecialchars(substr($rs['reason'], 0, 50)).'...' : htmlspecialchars($rs['reason']) ?>
                                    </span>
                                <?php else: ?>
                                    <em style="color:#999;"><i class="fas fa-minus-circle"></i> No reason provided</em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge status-<?= $rs['status'] ?>">
                                    <?php if($rs['status'] == 'active'): ?>
                                        <i class="fas fa-play-circle"></i>
                                    <?php elseif($rs['status'] == 'completed'): ?>
                                        <i class="fas fa-check-circle"></i>
                                    <?php else: ?>
                                        <i class="fas fa-times-circle"></i>
                                    <?php endif; ?>
                                    <?= ucfirst($rs['status']) ?>
                                </span>
                            </td>
                            <td>
                                <i class="far fa-calendar" style="color:#00843D;"></i>
                                <?= date('Y-m-d', strtotime($rs['created_at'])) ?><br>
                                <small style="color:#999;">
                                    <i class="far fa-clock"></i>
                                    <?= date('h:i A', strtotime($rs['created_at'])) ?>
                                </small>
                            </td>
                            <td class="no-print">
                                <div class="action-buttons">
                                    <button class="action-btn view" title="View Details" 
                                            onclick="viewRecourse(<?= $rs['recourse_id'] ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="action-btn edit" title="Edit Record" 
                                            onclick='editRecourse(<?= json_encode($rs) ?>)'>
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="action-btn delete" title="Delete Record" 
                                            onclick="confirmDelete(<?= $rs['recourse_id'] ?>, '<?= htmlspecialchars(addslashes($rs['reg_no'])) ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- ADD/EDIT MODAL -->
        <div class="modal" id="addModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2><i class="fas fa-book-medical"></i> <span id="formTitle">Add Recourse Student</span></h2>
                    <span class="close-modal" onclick="closeModal('addModal')">&times;</span>
                </div>

                <form method="POST" id="recourseForm" onsubmit="return validateForm(this)">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="recourse_id" id="recourse_id">

                    <!-- Hidden original fields -->
                    <input type="hidden" name="original_faculty_id" id="original_faculty_id">
                    <input type="hidden" name="original_department_id" id="original_department_id">
                    <input type="hidden" name="original_program_id" id="original_program_id">

                    <div class="grid">
                        <!-- Hierarchy Selection -->
                        <div class="hierarchy-section">
                            <h4><i class="fas fa-sitemap"></i> Student Hierarchy Selection</h4>
                            <div class="hierarchy-grid">
                                <!-- Campus -->
                                <div>
                                    <label><i class="fas fa-university"></i> Campus*</label>
                                    <select id="modal_campus" required onchange="onModalCampusChange(this.value)">
                                        <option value="">Select Campus</option>
                                        <?php foreach($campuses as $c): ?>
                                            <option value="<?= $c['campus_id'] ?>"><?= htmlspecialchars($c['campus_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Faculty -->
                                <div>
                                    <label><i class="fas fa-graduation-cap"></i> Faculty*</label>
                                    <select id="modal_faculty" required onchange="onModalFacultyChange(this.value)" disabled>
                                        <option value="">Select Faculty</option>
                                    </select>
                                </div>

                                <!-- Department -->
                                <div>
                                    <label><i class="fas fa-building"></i> Department*</label>
                                    <select id="modal_department" required onchange="onModalDepartmentChange(this.value)" disabled>
                                        <option value="">Select Department</option>
                                    </select>
                                </div>

                                <!-- Program -->
                                <div>
                                    <label><i class="fas fa-book"></i> Program*</label>
                                    <select id="modal_program" required onchange="onModalProgramChange(this.value)" disabled>
                                        <option value="">Select Program</option>
                                    </select>
                                </div>

                                <!-- Study Group -->
                                <div>
                                    <label><i class="fas fa-users"></i> Study Group*</label>
                                    <select id="modal_study_group" required onchange="onModalStudyGroupChange(this.value)" disabled>
                                        <option value="">Select Study Group</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Student Selection -->
                        <div class="student-search-container">
                            <label><i class="fas fa-search"></i> Search Student*</label>
                            <input type="text" id="student_search" placeholder="Type student name or reg no..." 
                                   onkeyup="searchStudents()" autocomplete="off" disabled>
                            <div id="student_results"></div>
                        </div>

                        <div>
                            <label><i class="fas fa-user-check"></i> Selected Student</label>
                            <input type="text" id="selected_student_display" readonly style="background:#f5f5f5;">
                            <input type="hidden" name="student_id" id="student_id">
                        </div>

                        <!-- Original Details Section -->
                        <div style="grid-column: span 4;">
                            <h4><i class="fas fa-history"></i> Original Details (Where subject was failed)</h4>
                        </div>

                        <div>
                            <label><i class="fas fa-university"></i> Original Campus*</label>
                            <input type="text" id="original_campus_display" readonly style="background:#f5f5f5;">
                            <input type="hidden" name="original_campus_id" id="original_campus_id">
                        </div>

                        <div>
                            <label><i class="fas fa-graduation-cap"></i> Original Faculty*</label>
                            <input type="text" id="original_faculty_display" readonly style="background:#f5f5f5;">
                        </div>

                        <div>
                            <label><i class="fas fa-building"></i> Original Department*</label>
                            <input type="text" id="original_department_display" readonly style="background:#f5f5f5;">
                        </div>

                        <div>
                            <label><i class="fas fa-book"></i> Original Program*</label>
                            <input type="text" id="original_program_display" readonly style="background:#f5f5f5;">
                        </div>

                        <div>
                            <label><i class="fas fa-users"></i> Original Class*</label>
                            <input type="text" id="original_class_display" readonly style="background:#f5f5f5;">
                            <input type="hidden" name="original_class_id" id="original_class_id">
                        </div>

                        <div>
                            <label><i class="fas fa-calendar-alt"></i> Original Semester*</label>
                            <input type="text" id="original_semester_display" readonly style="background:#f5f5f5;">
                            <input type="hidden" name="original_semester_id" id="original_semester_id">
                        </div>

                        <!-- Recourse Details Section - FULL HIERARCHY -->
                        <div style="grid-column: span 4;">
                            <h4><i class="fas fa-redo"></i> Recourse Details (Where taking recourse)</h4>
                        </div>

                        <!-- Recourse Campus -->
                        <div>
                            <label><i class="fas fa-university"></i> Recourse Campus*</label>
                            <select name="recourse_campus_id" id="recourse_campus_id" required onchange="onRecourseCampusChange()">
                                <option value="">Select Campus</option>
                                <?php foreach($campuses as $c): ?>
                                    <option value="<?= $c['campus_id'] ?>"><?= htmlspecialchars($c['campus_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Recourse Faculty -->
                        <div>
                            <label><i class="fas fa-graduation-cap"></i> Recourse Faculty*</label>
                            <select name="recourse_faculty_id" id="recourse_faculty_id" required onchange="onRecourseFacultyChange()" disabled>
                                <option value="">Select Faculty</option>
                            </select>
                        </div>

                        <!-- Recourse Department -->
                        <div>
                            <label><i class="fas fa-building"></i> Recourse Department*</label>
                            <select name="recourse_department_id" id="recourse_department_id" required onchange="onRecourseDepartmentChange()" disabled>
                                <option value="">Select Department</option>
                            </select>
                        </div>

                        <!-- Recourse Program -->
                        <div>
                            <label><i class="fas fa-book"></i> Recourse Program*</label>
                            <select name="recourse_program_id" id="recourse_program_id" required onchange="onRecourseProgramChange()" disabled>
                                <option value="">Select Program</option>
                            </select>
                        </div>

                        <!-- Recourse Class -->
                        <div>
                            <label><i class="fas fa-users"></i> Recourse Class*</label>
                            <select name="recourse_class_id" id="recourse_class_id" required onchange="onRecourseClassChange()" disabled>
                                <option value="">Select Class</option>
                            </select>
                        </div>

                        <!-- Recourse Semester -->
                        <div>
                            <label><i class="fas fa-calendar-alt"></i> Recourse Semester*</label>
                            <select name="recourse_semester_id" id="recourse_semester_id" required onchange="onRecourseSemesterChange()">
                                <option value="">Select Semester</option>
                                <?php foreach($semesters as $sem): ?>
                                    <option value="<?= $sem['semester_id'] ?>"><?= htmlspecialchars($sem['semester_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Subject Selection -->
                        <div style="grid-column: span 4;">
                            <h4><i class="fas fa-book-open"></i> Subject Information</h4>
                        </div>

                        <!-- Subject -->
                        <div style="grid-column: span 2;">
                            <label><i class="fas fa-book"></i> Subject*</label>
                            <select name="subject_id" id="subject_id" required disabled>
                                <option value="">Select Subject</option>
                            </select>
                        </div>

                        <!-- Academic Term -->
                        <div>
                            <label><i class="fas fa-calendar"></i> Academic Term*</label>
                            <select name="academic_term_id" id="academic_term_id" required>
                                <option value="">Select Term</option>
                                <?php foreach($academic_terms as $term): ?>
                                    <option value="<?= $term['academic_term_id'] ?>"><?= htmlspecialchars($term['term_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Status -->
                        <div>
                            <label><i class="fas fa-tag"></i> Status*</label>
                            <select name="status" id="status" required>
                                <option value="active">Active</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>

                        <!-- Reason -->
                        <div style="grid-column: span 4;">
                            <label><i class="fas fa-comment"></i> Reason (Optional)</label>
                            <textarea name="reason" id="reason" rows="4" placeholder="Enter reason for recourse..."></textarea>
                        </div>
                    </div>

                    <div style="grid-column: span 4; margin-top: 25px;">
                        <button class="btn green save-btn" type="submit">
                            <i class="fas fa-save"></i> Save Recourse Record
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- VIEW MODAL -->
        <div class="modal" id="viewModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2><i class="fas fa-eye"></i> Recourse Details</h2>
                    <span class="close-modal" onclick="closeModal('viewModal')">&times;</span>
                </div>
                <div id="viewContent">
                    <!-- Content will be loaded here -->
                </div>
            </div>
        </div>

    </div>

    <script>
    // ===========================================
    // GLOBAL VARIABLES
    // ===========================================
    let searchTimeout;
    let selectedStudentData = null;
    let deleteRecourseId = null;
    let deleteForm = null;

    // ===========================================
    // LOADING FUNCTIONS
    // ===========================================
    function showLoading(text = 'Loading...') {
        const overlay = document.getElementById('loadingOverlay');
        const textElement = overlay.querySelector('.loading-text');
        textElement.textContent = text;
        overlay.style.display = 'flex';
    }

    function hideLoading() {
        document.getElementById('loadingOverlay').style.display = 'none';
    }

    // ===========================================
    // MODAL FUNCTIONS
    // ===========================================
    function openModal(id) {
        document.getElementById(id).classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('show');
        document.body.style.overflow = 'auto';
    }

    // ===========================================
    // FILTER FUNCTIONS
    // ===========================================
    function onFilterCampusChange(campusId) {
        const facultySelect = document.getElementById('filter_faculty');
        const departmentSelect = document.getElementById('filter_department');
        const programSelect = document.getElementById('filter_program');
        const classSelect = document.getElementById('filter_class');
        
        if (!campusId) {
            facultySelect.innerHTML = '<option value="">All Faculties</option>';
            facultySelect.disabled = true;
            departmentSelect.innerHTML = '<option value="">All Departments</option>';
            departmentSelect.disabled = true;
            programSelect.innerHTML = '<option value="">All Programs</option>';
            programSelect.disabled = true;
            classSelect.innerHTML = '<option value="">All Study Groups</option>';
            classSelect.disabled = true;
            return;
        }
        
        facultySelect.innerHTML = '<option value="">Loading...</option>';
        facultySelect.disabled = true;
        
        fetch(`?ajax=get_faculties_by_campus&campus_id=${campusId}`)
            .then(response => response.json())
            .then(data => {
                facultySelect.innerHTML = '<option value="">All Faculties</option>';
                
                if (data.status === 'success' && data.faculties.length > 0) {
                    data.faculties.forEach(faculty => {
                        const option = document.createElement('option');
                        option.value = faculty.faculty_id;
                        option.textContent = faculty.faculty_name;
                        facultySelect.appendChild(option);
                    });
                    facultySelect.disabled = false;
                } else {
                    facultySelect.innerHTML = '<option value="">No faculties</option>';
                    facultySelect.disabled = true;
                }
                
                // Reset lower dropdowns
                departmentSelect.innerHTML = '<option value="">All Departments</option>';
                departmentSelect.disabled = true;
                programSelect.innerHTML = '<option value="">All Programs</option>';
                programSelect.disabled = true;
                classSelect.innerHTML = '<option value="">All Study Groups</option>';
                classSelect.disabled = true;
            });
    }

    function onFilterFacultyChange(facultyId) {
        const campusId = document.getElementById('filter_campus').value;
        const departmentSelect = document.getElementById('filter_department');
        const programSelect = document.getElementById('filter_program');
        const classSelect = document.getElementById('filter_class');
        
        if (!facultyId || !campusId) {
            departmentSelect.innerHTML = '<option value="">All Departments</option>';
            departmentSelect.disabled = true;
            programSelect.innerHTML = '<option value="">All Programs</option>';
            programSelect.disabled = true;
            classSelect.innerHTML = '<option value="">All Study Groups</option>';
            classSelect.disabled = true;
            return;
        }
        
        departmentSelect.innerHTML = '<option value="">Loading...</option>';
        departmentSelect.disabled = true;
        
        fetch(`?ajax=get_departments_by_faculty&faculty_id=${facultyId}&campus_id=${campusId}`)
            .then(response => response.json())
            .then(data => {
                departmentSelect.innerHTML = '<option value="">All Departments</option>';
                
                if (data.status === 'success' && data.departments.length > 0) {
                    data.departments.forEach(dept => {
                        const option = document.createElement('option');
                        option.value = dept.department_id;
                        option.textContent = dept.department_name;
                        departmentSelect.appendChild(option);
                    });
                    departmentSelect.disabled = false;
                } else {
                    departmentSelect.innerHTML = '<option value="">No departments</option>';
                    departmentSelect.disabled = true;
                }
                
                // Reset lower dropdowns
                programSelect.innerHTML = '<option value="">All Programs</option>';
                programSelect.disabled = true;
                classSelect.innerHTML = '<option value="">All Study Groups</option>';
                classSelect.disabled = true;
            });
    }

    function onFilterDepartmentChange(departmentId) {
        const campusId = document.getElementById('filter_campus').value;
        const facultyId = document.getElementById('filter_faculty').value;
        const programSelect = document.getElementById('filter_program');
        const classSelect = document.getElementById('filter_class');
        
        if (!departmentId || !facultyId || !campusId) {
            programSelect.innerHTML = '<option value="">All Programs</option>';
            programSelect.disabled = true;
            classSelect.innerHTML = '<option value="">All Study Groups</option>';
            classSelect.disabled = true;
            return;
        }
        
        programSelect.innerHTML = '<option value="">Loading...</option>';
        programSelect.disabled = true;
        
        fetch(`?ajax=get_programs_by_department&department_id=${departmentId}&faculty_id=${facultyId}&campus_id=${campusId}`)
            .then(response => response.json())
            .then(data => {
                programSelect.innerHTML = '<option value="">All Programs</option>';
                
                if (data.status === 'success' && data.programs.length > 0) {
                    data.programs.forEach(program => {
                        const option = document.createElement('option');
                        option.value = program.program_id;
                        option.textContent = program.program_name;
                        programSelect.appendChild(option);
                    });
                    programSelect.disabled = false;
                } else {
                    programSelect.innerHTML = '<option value="">No programs</option>';
                    programSelect.disabled = true;
                }
                
                // Reset lower dropdown
                classSelect.innerHTML = '<option value="">All Study Groups</option>';
                classSelect.disabled = true;
            });
    }

    function onFilterProgramChange(programId) {
        const campusId = document.getElementById('filter_campus').value;
        const facultyId = document.getElementById('filter_faculty').value;
        const departmentId = document.getElementById('filter_department').value;
        const classSelect = document.getElementById('filter_class');
        
        if (!programId || !departmentId || !facultyId || !campusId) {
            classSelect.innerHTML = '<option value="">All Study Groups</option>';
            classSelect.disabled = true;
            return;
        }
        
        classSelect.innerHTML = '<option value="">Loading...</option>';
        classSelect.disabled = true;
        
        fetch(`?ajax=get_study_groups_by_program&program_id=${programId}&department_id=${departmentId}&faculty_id=${facultyId}&campus_id=${campusId}`)
            .then(response => response.json())
            .then(data => {
                classSelect.innerHTML = '<option value="">All Study Groups</option>';
                
                if (data.status === 'success' && data.study_groups.length > 0) {
                    data.study_groups.forEach(sg => {
                        const option = document.createElement('option');
                        option.value = sg.class_id;
                        option.textContent = `${sg.class_name} (${sg.study_mode ? sg.study_mode : 'Regular'})`;
                        classSelect.appendChild(option);
                    });
                    classSelect.disabled = false;
                } else {
                    classSelect.innerHTML = '<option value="">No study groups</option>';
                    classSelect.disabled = true;
                }
            });
    }

    function resetFilter() {
        window.location.href = 'recourse_student.php';
    }

    function clearSearch() {
        document.querySelector('input[name="q"]').value = '';
        document.getElementById('filterForm').submit();
    }

    // ===========================================
    // MODAL HIERARCHY FUNCTIONS
    // ===========================================
    function resetModalHierarchy() {
        document.getElementById('modal_campus').value = '';
        
        const dependentIds = ['modal_faculty', 'modal_department', 'modal_program', 'modal_study_group'];
        dependentIds.forEach(id => {
            const select = document.getElementById(id);
            select.innerHTML = `<option value="">Select ${id.replace('modal_', '').replace(/_/g, ' ')}</option>`;
            select.disabled = true;
            select.value = '';
        });
        
        document.getElementById('student_id').value = '';
        document.getElementById('selected_student_display').value = '';
        document.getElementById('student_search').value = '';
        document.getElementById('student_search').disabled = true;
        
        document.getElementById('original_campus_display').value = '';
        document.getElementById('original_campus_id').value = '';
        document.getElementById('original_faculty_display').value = '';
        document.getElementById('original_faculty_id').value = '';
        document.getElementById('original_department_display').value = '';
        document.getElementById('original_department_id').value = '';
        document.getElementById('original_program_display').value = '';
        document.getElementById('original_program_id').value = '';
        document.getElementById('original_semester_display').value = '';
        document.getElementById('original_semester_id').value = '';
        document.getElementById('original_class_display').value = '';
        document.getElementById('original_class_id').value = '';
    }

    async function onModalCampusChange(campusId) {
        if (!campusId) {
            resetModalHierarchy();
            return;
        }
        
        const lowerIds = ['modal_faculty', 'modal_department', 'modal_program', 'modal_study_group'];
        lowerIds.forEach(id => {
            const select = document.getElementById(id);
            select.innerHTML = '<option value="">Loading...</option>';
            select.disabled = true;
        });
        
        await loadModalFaculties(campusId);
    }

    async function loadModalFaculties(campusId) {
        try {
            const response = await fetch(`?ajax=get_faculties_by_campus&campus_id=${campusId}`);
            const data = await response.json();
            
            const facultySelect = document.getElementById('modal_faculty');
            facultySelect.innerHTML = '<option value="">Select Faculty</option>';
            
            if (data.status === 'success' && data.faculties.length > 0) {
                data.faculties.forEach(faculty => {
                    const option = document.createElement('option');
                    option.value = faculty.faculty_id;
                    option.textContent = faculty.faculty_name;
                    facultySelect.appendChild(option);
                });
                facultySelect.disabled = false;
            } else {
                facultySelect.innerHTML = '<option value="">No faculties found</option>';
                facultySelect.disabled = true;
            }
        } catch (error) {
            console.error('Error loading faculties:', error);
            document.getElementById('modal_faculty').innerHTML = '<option value="">Error loading</option>';
        }
    }

    async function onModalFacultyChange(facultyId) {
        const campusId = document.getElementById('modal_campus').value;
        if (!facultyId || !campusId) {
            resetDependentModalDropdowns(['modal_department', 'modal_program', 'modal_study_group']);
            return;
        }
        
        document.getElementById('modal_department').innerHTML = '<option value="">Loading...</option>';
        document.getElementById('modal_department').disabled = true;
        document.getElementById('modal_program').innerHTML = '<option value="">Select Program</option>';
        document.getElementById('modal_program').disabled = true;
        document.getElementById('modal_program').value = '';
        document.getElementById('modal_study_group').innerHTML = '<option value="">Select Study Group</option>';
        document.getElementById('modal_study_group').disabled = true;
        document.getElementById('modal_study_group').value = '';
        
        await loadModalDepartments(facultyId, campusId);
    }

    async function loadModalDepartments(facultyId, campusId) {
        try {
            const response = await fetch(`?ajax=get_departments_by_faculty&faculty_id=${facultyId}&campus_id=${campusId}`);
            const data = await response.json();
            
            const deptSelect = document.getElementById('modal_department');
            deptSelect.innerHTML = '<option value="">Select Department</option>';
            
            if (data.status === 'success' && data.departments.length > 0) {
                data.departments.forEach(dept => {
                    const option = document.createElement('option');
                    option.value = dept.department_id;
                    option.textContent = dept.department_name;
                    deptSelect.appendChild(option);
                });
                deptSelect.disabled = false;
            } else {
                deptSelect.innerHTML = '<option value="">No departments found</option>';
                deptSelect.disabled = true;
            }
        } catch (error) {
            console.error('Error loading departments:', error);
            document.getElementById('modal_department').innerHTML = '<option value="">Error loading</option>';
        }
    }

    async function onModalDepartmentChange(departmentId) {
        const facultyId = document.getElementById('modal_faculty').value;
        const campusId = document.getElementById('modal_campus').value;
        
        if (!departmentId || !facultyId || !campusId) {
            resetDependentModalDropdowns(['modal_program', 'modal_study_group']);
            return;
        }
        
        document.getElementById('modal_program').innerHTML = '<option value="">Loading...</option>';
        document.getElementById('modal_program').disabled = true;
        document.getElementById('modal_study_group').innerHTML = '<option value="">Select Study Group</option>';
        document.getElementById('modal_study_group').disabled = true;
        document.getElementById('modal_study_group').value = '';
        
        await loadModalPrograms(departmentId, facultyId, campusId);
    }

    async function loadModalPrograms(departmentId, facultyId, campusId) {
        try {
            const response = await fetch(`?ajax=get_programs_by_department&department_id=${departmentId}&faculty_id=${facultyId}&campus_id=${campusId}`);
            const data = await response.json();
            
            const programSelect = document.getElementById('modal_program');
            programSelect.innerHTML = '<option value="">Select Program</option>';
            
            if (data.status === 'success' && data.programs.length > 0) {
                data.programs.forEach(program => {
                    const option = document.createElement('option');
                    option.value = program.program_id;
                    option.textContent = program.program_name;
                    programSelect.appendChild(option);
                });
                programSelect.disabled = false;
            } else {
                programSelect.innerHTML = '<option value="">No programs found</option>';
                programSelect.disabled = true;
            }
        } catch (error) {
            console.error('Error loading programs:', error);
            document.getElementById('modal_program').innerHTML = '<option value="">Error loading</option>';
        }
    }

    async function onModalProgramChange(programId) {
        const departmentId = document.getElementById('modal_department').value;
        const facultyId = document.getElementById('modal_faculty').value;
        const campusId = document.getElementById('modal_campus').value;
        
        if (!programId || !departmentId || !facultyId || !campusId) {
            document.getElementById('modal_study_group').innerHTML = '<option value="">Select Study Group</option>';
            document.getElementById('modal_study_group').disabled = true;
            return;
        }
        
        document.getElementById('modal_study_group').innerHTML = '<option value="">Loading...</option>';
        document.getElementById('modal_study_group').disabled = true;
        
        await loadModalStudyGroups(programId, departmentId, facultyId, campusId);
    }

    async function loadModalStudyGroups(programId, departmentId, facultyId, campusId) {
        try {
            const response = await fetch(`?ajax=get_study_groups_by_program&program_id=${programId}&department_id=${departmentId}&faculty_id=${facultyId}&campus_id=${campusId}`);
            const data = await response.json();
            
            const studyGroupSelect = document.getElementById('modal_study_group');
            studyGroupSelect.innerHTML = '<option value="">Select Study Group</option>';
            
            if (data.status === 'success' && data.study_groups.length > 0) {
                data.study_groups.forEach(sg => {
                    const option = document.createElement('option');
                    option.value = sg.class_id;
                    option.textContent = `${sg.class_name} (${sg.study_mode || 'Regular'})`;
                    studyGroupSelect.appendChild(option);
                });
                studyGroupSelect.disabled = false;
            } else {
                studyGroupSelect.innerHTML = '<option value="">No study groups found</option>';
                studyGroupSelect.disabled = true;
            }
        } catch (error) {
            console.error('Error loading study groups:', error);
            document.getElementById('modal_study_group').innerHTML = '<option value="">Error loading</option>';
        }
    }

    function onModalStudyGroupChange(studyGroupId) {
        document.getElementById('student_search').disabled = !studyGroupId;
    }

    function resetDependentModalDropdowns(fields) {
        fields.forEach(field => {
            const select = document.getElementById(field);
            if (select) {
                select.innerHTML = `<option value="">Select ${field.replace('modal_', '').replace(/_/g, ' ')}</option>`;
                select.disabled = true;
                select.value = '';
            }
        });
    }

    // ===========================================
    // STUDENT SEARCH FUNCTIONS
    // ===========================================
    function searchStudents() {
        clearTimeout(searchTimeout);
        const searchTerm = document.getElementById('student_search').value.trim();
        const resultsDiv = document.getElementById('student_results');
        
        if (searchTerm.length < 2) {
            resultsDiv.style.display = 'none';
            resultsDiv.innerHTML = '';
            return;
        }
        
        const campusId = document.getElementById('modal_campus').value;
        const facultyId = document.getElementById('modal_faculty').value;
        const departmentId = document.getElementById('modal_department').value;
        const programId = document.getElementById('modal_program').value;
        const classId = document.getElementById('modal_study_group').value;
        
        let queryParams = `q=${encodeURIComponent(searchTerm)}`;
        if (campusId) queryParams += `&campus_id=${campusId}`;
        if (facultyId) queryParams += `&faculty_id=${facultyId}`;
        if (departmentId) queryParams += `&department_id=${departmentId}`;
        if (programId) queryParams += `&program_id=${programId}`;
        if (classId) queryParams += `&class_id=${classId}`;
        
        searchTimeout = setTimeout(() => {
            fetch(`?ajax=get_students_by_filters&${queryParams}`)
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success' && data.students.length > 0) {
                        let html = '';
                        data.students.forEach(student => {
                            const studentData = JSON.stringify(student).replace(/"/g, '&quot;');
                            html += `
                                <div onclick="selectStudent(${studentData})">
                                    <strong>${student.reg_no}</strong> - ${student.full_name}<br>
                                    <small><i class="fas fa-book"></i> ${student.program_name || 'No Program'} | 
                                    <i class="fas fa-users"></i> ${student.class_name || 'No Class'} | 
                                    <i class="fas fa-calendar"></i> ${student.semester_name || 'No Semester'}</small>
                                </div>
                            `;
                        });
                        resultsDiv.innerHTML = html;
                        resultsDiv.style.display = 'block';
                    } else {
                        resultsDiv.innerHTML = '<div style="padding:15px; color:#666; text-align:center;"><i class="fas fa-search"></i> No students found</div>';
                        resultsDiv.style.display = 'block';
                    }
                });
        }, 300);
    }

    function selectStudent(student) {
        document.getElementById('student_id').value = student.student_id;
        document.getElementById('selected_student_display').value = `${student.reg_no} - ${student.full_name}`;
        
        document.getElementById('original_campus_id').value = student.campus_id;
        document.getElementById('original_campus_display').value = student.campus_name || 'N/A';
        document.getElementById('original_faculty_id').value = student.faculty_id;
        document.getElementById('original_faculty_display').value = student.faculty_name || 'N/A';
        document.getElementById('original_department_id').value = student.department_id;
        document.getElementById('original_department_display').value = student.department_name || 'N/A';
        document.getElementById('original_program_id').value = student.program_id;
        document.getElementById('original_program_display').value = student.program_name || 'N/A';
        document.getElementById('original_semester_id').value = student.semester_id;
        document.getElementById('original_semester_display').value = student.semester_name || 'N/A';
        document.getElementById('original_class_id').value = student.class_id;
        document.getElementById('original_class_display').value = student.class_name || 'N/A';
        
        document.getElementById('student_results').style.display = 'none';
        document.getElementById('student_search').value = '';
        
        if (!document.getElementById('recourse_campus_id').value) {
            document.getElementById('recourse_campus_id').value = student.campus_id;
            onRecourseCampusChange();
        }
    }

    // ===========================================
    // RECOURSE HIERARCHY FUNCTIONS
    // ===========================================
    function resetRecourseHierarchy() {
        document.getElementById('recourse_campus_id').value = '';
        
        const dependentIds = ['recourse_faculty_id', 'recourse_department_id', 'recourse_program_id', 'recourse_class_id'];
        dependentIds.forEach(id => {
            const select = document.getElementById(id);
            select.innerHTML = `<option value="">Select ${id.replace('recourse_', '').replace(/_/g, ' ')}</option>`;
            select.disabled = true;
            select.value = '';
        });
        
        document.getElementById('subject_id').innerHTML = '<option value="">Select Subject</option>';
        document.getElementById('subject_id').disabled = true;
        document.getElementById('subject_id').value = '';
        
        document.getElementById('recourse_semester_id').value = '';
    }

    async function onRecourseCampusChange() {
        const campusId = document.getElementById('recourse_campus_id').value;
        
        if (!campusId) {
            resetRecourseHierarchy();
            return;
        }
        
        const lowerIds = ['recourse_faculty_id', 'recourse_department_id', 'recourse_program_id', 'recourse_class_id'];
        lowerIds.forEach(id => {
            const select = document.getElementById(id);
            select.innerHTML = '<option value="">Loading...</option>';
            select.disabled = true;
        });
        
        document.getElementById('subject_id').innerHTML = '<option value="">Select Subject</option>';
        document.getElementById('subject_id').disabled = true;
        
        try {
            const response = await fetch(`?ajax=get_faculties_by_campus&campus_id=${campusId}`);
            const data = await response.json();
            
            const facultySelect = document.getElementById('recourse_faculty_id');
            facultySelect.innerHTML = '<option value="">Select Faculty</option>';
            
            if (data.status === 'success' && data.faculties.length > 0) {
                data.faculties.forEach(faculty => {
                    const option = document.createElement('option');
                    option.value = faculty.faculty_id;
                    option.textContent = faculty.faculty_name;
                    facultySelect.appendChild(option);
                });
                facultySelect.disabled = false;
            } else {
                facultySelect.innerHTML = '<option value="">No faculties found</option>';
                facultySelect.disabled = true;
            }
        } catch (error) {
            console.error('Error loading faculties:', error);
            document.getElementById('recourse_faculty_id').innerHTML = '<option value="">Error loading</option>';
        }
    }

    async function onRecourseFacultyChange() {
        const campusId = document.getElementById('recourse_campus_id').value;
        const facultyId = document.getElementById('recourse_faculty_id').value;
        
        if (!facultyId || !campusId) {
            resetDependentRecourseDropdowns(['recourse_department_id', 'recourse_program_id', 'recourse_class_id']);
            return;
        }
        
        document.getElementById('recourse_department_id').innerHTML = '<option value="">Loading...</option>';
        document.getElementById('recourse_department_id').disabled = true;
        document.getElementById('recourse_program_id').innerHTML = '<option value="">Select Program</option>';
        document.getElementById('recourse_program_id').disabled = true;
        document.getElementById('recourse_program_id').value = '';
        document.getElementById('recourse_class_id').innerHTML = '<option value="">Select Class</option>';
        document.getElementById('recourse_class_id').disabled = true;
        document.getElementById('recourse_class_id').value = '';
        
        try {
            const response = await fetch(`?ajax=get_departments_by_faculty&faculty_id=${facultyId}&campus_id=${campusId}`);
            const data = await response.json();
            
            const deptSelect = document.getElementById('recourse_department_id');
            deptSelect.innerHTML = '<option value="">Select Department</option>';
            
            if (data.status === 'success' && data.departments.length > 0) {
                data.departments.forEach(dept => {
                    const option = document.createElement('option');
                    option.value = dept.department_id;
                    option.textContent = dept.department_name;
                    deptSelect.appendChild(option);
                });
                deptSelect.disabled = false;
            } else {
                deptSelect.innerHTML = '<option value="">No departments found</option>';
                deptSelect.disabled = true;
            }
        } catch (error) {
            console.error('Error loading departments:', error);
            document.getElementById('recourse_department_id').innerHTML = '<option value="">Error loading</option>';
        }
    }

    async function onRecourseDepartmentChange() {
        const campusId = document.getElementById('recourse_campus_id').value;
        const facultyId = document.getElementById('recourse_faculty_id').value;
        const departmentId = document.getElementById('recourse_department_id').value;
        
        if (!departmentId || !facultyId || !campusId) {
            resetDependentRecourseDropdowns(['recourse_program_id', 'recourse_class_id']);
            return;
        }
        
        document.getElementById('recourse_program_id').innerHTML = '<option value="">Loading...</option>';
        document.getElementById('recourse_program_id').disabled = true;
        document.getElementById('recourse_class_id').innerHTML = '<option value="">Select Class</option>';
        document.getElementById('recourse_class_id').disabled = true;
        document.getElementById('recourse_class_id').value = '';
        
        try {
            const response = await fetch(`?ajax=get_programs_by_department&department_id=${departmentId}&faculty_id=${facultyId}&campus_id=${campusId}`);
            const data = await response.json();
            
            const programSelect = document.getElementById('recourse_program_id');
            programSelect.innerHTML = '<option value="">Select Program</option>';
            
            if (data.status === 'success' && data.programs.length > 0) {
                data.programs.forEach(program => {
                    const option = document.createElement('option');
                    option.value = program.program_id;
                    option.textContent = program.program_name;
                    programSelect.appendChild(option);
                });
                programSelect.disabled = false;
            } else {
                programSelect.innerHTML = '<option value="">No programs found</option>';
                programSelect.disabled = true;
            }
        } catch (error) {
            console.error('Error loading programs:', error);
            document.getElementById('recourse_program_id').innerHTML = '<option value="">Error loading</option>';
        }
    }

    async function onRecourseProgramChange() {
        const campusId = document.getElementById('recourse_campus_id').value;
        const facultyId = document.getElementById('recourse_faculty_id').value;
        const departmentId = document.getElementById('recourse_department_id').value;
        const programId = document.getElementById('recourse_program_id').value;
        
        if (!programId || !departmentId || !facultyId || !campusId) {
            document.getElementById('recourse_class_id').innerHTML = '<option value="">Select Class</option>';
            document.getElementById('recourse_class_id').disabled = true;
            return;
        }
        
        document.getElementById('recourse_class_id').innerHTML = '<option value="">Loading...</option>';
        document.getElementById('recourse_class_id').disabled = true;
        
        try {
            const response = await fetch(`?ajax=get_study_groups_by_program&program_id=${programId}&department_id=${departmentId}&faculty_id=${facultyId}&campus_id=${campusId}`);
            const data = await response.json();
            
            const classSelect = document.getElementById('recourse_class_id');
            classSelect.innerHTML = '<option value="">Select Class</option>';
            
            if (data.status === 'success' && data.study_groups.length > 0) {
                data.study_groups.forEach(sg => {
                    const option = document.createElement('option');
                    option.value = sg.class_id;
                    option.textContent = `${sg.class_name} (${sg.study_mode || 'Regular'})`;
                    classSelect.appendChild(option);
                });
                classSelect.disabled = false;
            } else {
                classSelect.innerHTML = '<option value="">No classes found</option>';
                classSelect.disabled = true;
            }
        } catch (error) {
            console.error('Error loading classes:', error);
            document.getElementById('recourse_class_id').innerHTML = '<option value="">Error loading</option>';
        }
    }

    function onRecourseClassChange() {
        loadSubjectsForRecourse();
    }

    function onRecourseSemesterChange() {
        loadSubjectsForRecourse();
    }

    function resetDependentRecourseDropdowns(fields) {
        fields.forEach(field => {
            const select = document.getElementById(field);
            if (select) {
                select.innerHTML = `<option value="">Select ${field.replace('recourse_', '').replace(/_/g, ' ')}</option>`;
                select.disabled = true;
                select.value = '';
            }
        });
    }

    // ===========================================
    // LOAD SUBJECTS FOR RECOURSE
    // ===========================================
    async function loadSubjectsForRecourse() {
        const campusId = document.getElementById('recourse_campus_id').value;
        const facultyId = document.getElementById('recourse_faculty_id').value;
        const departmentId = document.getElementById('recourse_department_id').value;
        const programId = document.getElementById('recourse_program_id').value;
        const classId = document.getElementById('recourse_class_id').value;
        const semesterId = document.getElementById('recourse_semester_id').value;
        
        const subjectSelect = document.getElementById('subject_id');
        
        if (!campusId || !facultyId || !departmentId || !programId || !classId || !semesterId) {
            subjectSelect.innerHTML = '<option value="">Select Subject</option>';
            subjectSelect.disabled = true;
            return;
        }
        
        subjectSelect.innerHTML = '<option value="">Loading subjects...</option>';
        subjectSelect.disabled = true;
        
        try {
            const response = await fetch(`?ajax=get_subjects_by_full_hierarchy&campus_id=${campusId}&faculty_id=${facultyId}&department_id=${departmentId}&program_id=${programId}&class_id=${classId}&semester_id=${semesterId}`);
            const data = await response.json();
            
            subjectSelect.innerHTML = '<option value="">Select Subject</option>';
            
            if (data.status === 'success' && data.subjects.length > 0) {
                data.subjects.forEach(subject => {
                    const option = document.createElement('option');
                    option.value = subject.subject_id;
                    option.textContent = `${subject.subject_code} - ${subject.subject_name}`;
                    subjectSelect.appendChild(option);
                });
                subjectSelect.disabled = false;
            } else {
                subjectSelect.innerHTML = '<option value="">No subjects found</option>';
                subjectSelect.disabled = true;
            }
        } catch (error) {
            console.error('Error loading subjects for recourse:', error);
            subjectSelect.innerHTML = '<option value="">Error loading subjects</option>';
            subjectSelect.disabled = true;
        }
    }

    // ===========================================
    // VIEW RECOURSE FUNCTION
    // ===========================================
    async function viewRecourse(recourseId) {
        showLoading('Loading recourse details...');
        try {
            const response = await fetch(`?ajax=get_recourse_hierarchy&recourse_id=${recourseId}`);
            const data = await response.json();
            
            if (data.status === 'success') {
                const recourse = data.recourse;
                
                const html = `
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 25px;">
                        <!-- Student Information -->
                        <div>
                            <h3 style="color:#0072CE; border-bottom: 3px solid #0072CE; padding-bottom: 8px; margin-bottom: 15px;">
                                <i class="fas fa-user-graduate"></i> Student Information
                            </h3>
                            <p><strong>Registration No:</strong> ${recourse.reg_no || recourse.student_id}</p>
                            <p><strong>Student Name:</strong> ${recourse.full_name || 'N/A'}</p>
                        </div>
                        
                        <!-- Subject Information -->
                        <div>
                            <h3 style="color:#0072CE; border-bottom: 3px solid #0072CE; padding-bottom: 8px; margin-bottom: 15px;">
                                <i class="fas fa-book-open"></i> Subject Information
                            </h3>
                            <p><strong>Subject Code:</strong> ${recourse.subject_code}</p>
                            <p><strong>Subject Name:</strong> ${recourse.subject_name}</p>
                        </div>
                        
                        <!-- Original Details -->
                        <div>
                            <h3 style="color:#0072CE; border-bottom: 3px solid #0072CE; padding-bottom: 8px; margin-bottom: 15px;">
                                <i class="fas fa-history"></i> Original (Failed) Details
                            </h3>
                            <p><strong><i class="fas fa-university"></i> Campus:</strong> ${recourse.original_campus_name}</p>
                            <p><strong><i class="fas fa-graduation-cap"></i> Faculty:</strong> ${recourse.original_faculty_name}</p>
                            <p><strong><i class="fas fa-building"></i> Department:</strong> ${recourse.original_department_name}</p>
                            <p><strong><i class="fas fa-book"></i> Program:</strong> ${recourse.original_program_name}</p>
                            <p><strong><i class="fas fa-users"></i> Class:</strong> ${recourse.original_class_name}</p>
                            <p><strong><i class="fas fa-calendar-alt"></i> Semester:</strong> ${recourse.original_semester_name}</p>
                        </div>
                        
                        <!-- Recourse Details -->
                        <div>
                            <h3 style="color:#0072CE; border-bottom: 3px solid #0072CE; padding-bottom: 8px; margin-bottom: 15px;">
                                <i class="fas fa-redo"></i> Recourse Details
                            </h3>
                            <p><strong><i class="fas fa-university"></i> Campus:</strong> ${recourse.recourse_campus_name}</p>
                            <p><strong><i class="fas fa-graduation-cap"></i> Faculty:</strong> ${recourse.recourse_faculty_name}</p>
                            <p><strong><i class="fas fa-building"></i> Department:</strong> ${recourse.recourse_department_name}</p>
                            <p><strong><i class="fas fa-book"></i> Program:</strong> ${recourse.recourse_program_name}</p>
                            <p><strong><i class="fas fa-users"></i> Class:</strong> ${recourse.recourse_class_name}</p>
                            <p><strong><i class="fas fa-calendar-alt"></i> Semester:</strong> ${recourse.recourse_semester_name}</p>
                        </div>
                        
                        <!-- Additional Information -->
                        <div style="grid-column: span 2; background: linear-gradient(135deg, #f8f9fa, #e9ecef); padding: 20px; border-radius: 12px; border: 2px solid #e0e0e0;">
                            <h3 style="color:#0072CE; border-bottom: 3px solid #0072CE; padding-bottom: 8px; margin-bottom: 15px;">
                                <i class="fas fa-info-circle"></i> Additional Information
                            </h3>
                            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
                                <div>
                                    <p><strong><i class="fas fa-calendar"></i> Academic Term:</strong> ${recourse.term_name}</p>
                                    <p><strong><i class="fas fa-tag"></i> Status:</strong> 
                                        <span class="status-badge status-${recourse.status}">
                                            ${recourse.status.charAt(0).toUpperCase() + recourse.status.slice(1)}
                                        </span>
                                    </p>
                                </div>
                                <div>
                                    <p><strong><i class="fas fa-clock"></i> Created At:</strong> ${recourse.created_at}</p>
                                </div>
                            </div>
                            <div style="margin-top: 15px;">
                                <p><strong><i class="fas fa-comment"></i> Reason:</strong></p>
                                <div style="background: white; padding: 15px; border-radius: 8px; border: 1px solid #ddd; margin-top: 5px;">
                                    ${recourse.reason ? recourse.reason : '<em style="color:#999;">No reason provided</em>'}
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div style="margin-top: 30px; text-align: center; display: flex; gap: 15px; justify-content: center;">
                      
                        
                    </div>
                `;
                
                document.getElementById('viewContent').innerHTML = html;
                openModal('viewModal');
            }
        } catch (error) {
            console.error('Error loading recourse details:', error);
            alert('Error loading recourse details');
        }
        hideLoading();
    }

    // ===========================================
    // EDIT FUNCTION
    // ===========================================
    async function editRecourse(recourseData) {
        showLoading('Loading data for editing...');
        openModal('addModal');
        document.getElementById('formTitle').textContent = 'Edit Recourse Record';
        document.getElementById('formAction').value = 'update';
        document.getElementById('recourse_id').value = recourseData.recourse_id;
        
        try {
            // Load student details
            const studentResponse = await fetch(`?ajax=get_student_details&student_id=${recourseData.student_id}`);
            const studentData = await studentResponse.json();
            
            if (studentData.status === 'success' && studentData.student) {
                const student = studentData.student;
                
                // Set student fields
                document.getElementById('student_id').value = recourseData.student_id;
                document.getElementById('selected_student_display').value = `${student.reg_no} - ${student.full_name}`;
                
                // Set original details
                document.getElementById('original_campus_id').value = recourseData.original_campus_id;
                document.getElementById('original_campus_display').value = recourseData.original_campus_name || student.campus_name;
                document.getElementById('original_faculty_id').value = recourseData.original_faculty_id;
                document.getElementById('original_faculty_display').value = recourseData.original_faculty_name || student.faculty_name;
                document.getElementById('original_department_id').value = recourseData.original_department_id;
                document.getElementById('original_department_display').value = recourseData.original_department_name || student.department_name;
                document.getElementById('original_program_id').value = recourseData.original_program_id;
                document.getElementById('original_program_display').value = recourseData.original_program_name || student.program_name;
                document.getElementById('original_semester_id').value = recourseData.original_semester_id;
                document.getElementById('original_semester_display').value = recourseData.original_semester_name || student.semester_name;
                document.getElementById('original_class_id').value = recourseData.original_class_id;
                document.getElementById('original_class_display').value = recourseData.original_class_name || student.class_name;
                
                // Set other fields
                document.getElementById('academic_term_id').value = recourseData.academic_term_id;
                document.getElementById('status').value = recourseData.status;
                document.getElementById('reason').value = recourseData.reason || '';
                
                // Load student hierarchy dropdowns
                document.getElementById('modal_campus').value = student.campus_id;
                await onModalCampusChange(student.campus_id);
                
                setTimeout(async () => {
                    document.getElementById('modal_faculty').value = student.faculty_id;
                    await onModalFacultyChange(student.faculty_id);
                    
                    setTimeout(async () => {
                        document.getElementById('modal_department').value = student.department_id;
                        await onModalDepartmentChange(student.department_id);
                        
                        setTimeout(async () => {
                            document.getElementById('modal_program').value = student.program_id;
                            await onModalProgramChange(student.program_id);
                            
                            setTimeout(async () => {
                                document.getElementById('modal_study_group').value = student.class_id;
                            }, 300);
                        }, 300);
                    }, 300);
                }, 300);
            }
            
            // Load recourse hierarchy
            document.getElementById('recourse_campus_id').value = recourseData.recourse_campus_id;
            await onRecourseCampusChange();
            
            setTimeout(async () => {
                document.getElementById('recourse_faculty_id').value = recourseData.recourse_faculty_id;
                await onRecourseFacultyChange();
                
                setTimeout(async () => {
                    document.getElementById('recourse_department_id').value = recourseData.recourse_department_id;
                    await onRecourseDepartmentChange();
                    
                    setTimeout(async () => {
                        document.getElementById('recourse_program_id').value = recourseData.recourse_program_id;
                        await onRecourseProgramChange();
                        
                        setTimeout(async () => {
                            document.getElementById('recourse_class_id').value = recourseData.recourse_class_id;
                            await onRecourseClassChange();
                            
                            document.getElementById('recourse_semester_id').value = recourseData.recourse_semester_id;
                            await onRecourseSemesterChange();
                            
                            setTimeout(async () => {
                                document.getElementById('subject_id').value = recourseData.subject_id;
                                hideLoading();
                            }, 500);
                        }, 300);
                    }, 300);
                }, 300);
            }, 300);
            
        } catch (error) {
            console.error('Error loading data for edit:', error);
            hideLoading();
            alert('Error loading data for editing');
        }
    }

    // ===========================================
    // DELETE CONFIRMATION
    // ===========================================
    function confirmDelete(id, regNo) {
        deleteRecourseId = id;
        document.getElementById('confirmTitle').textContent = 'Confirm Deletion';
        document.getElementById('confirmMessage').textContent = `Are you sure you want to delete recourse record for ${regNo}? This action cannot be undone.`;
        document.getElementById('confirmActionBtn').innerHTML = '<i class="fas fa-trash"></i> Delete';
        document.getElementById('confirmActionBtn').onclick = performDelete;
        document.getElementById('confirmModal').style.display = 'flex';
    }

    function closeConfirmModal() {
        document.getElementById('confirmModal').style.display = 'none';
        deleteRecourseId = null;
        if (deleteForm) {
            deleteForm.remove();
            deleteForm = null;
        }
    }

    function performDelete() {
        if (!deleteRecourseId) return;
        
        deleteForm = document.createElement('form');
        deleteForm.method = 'POST';
        deleteForm.style.display = 'none';
        
        const recourseIdInput = document.createElement('input');
        recourseIdInput.type = 'hidden';
        recourseIdInput.name = 'recourse_id';
        recourseIdInput.value = deleteRecourseId;
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'delete';
        
        deleteForm.appendChild(recourseIdInput);
        deleteForm.appendChild(actionInput);
        document.body.appendChild(deleteForm);
        deleteForm.submit();
    }

    // ===========================================
    // EXPORT TO EXCEL
    // ===========================================
    function exportToExcel() {
        showLoading('Exporting to Excel...');
        
        const table = document.getElementById('mainTable');
        const rows = table.querySelectorAll('tr');
        let csv = [];
        
        // Add headers
        const headers = [];
        table.querySelectorAll('thead th').forEach(th => {
            if (!th.classList.contains('no-print')) {
                headers.push(th.textContent.trim());
            }
        });
        csv.push(headers.join(','));
        
        // Add data rows
        table.querySelectorAll('tbody tr').forEach(row => {
            const cells = [];
            row.querySelectorAll('td').forEach((cell, index) => {
                if (!cell.classList.contains('no-print')) {
                    let text = cell.textContent.trim();
                    text = text.replace(/\n/g, ' ').replace(/\s+/g, ' ');
                    if (text.includes(',') || text.includes('"')) {
                        text = '"' + text.replace(/"/g, '""') + '"';
                    }
                    cells.push(text);
                }
            });
            csv.push(cells.join(','));
        });
        
        const csvContent = csv.join('\n');
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        
        link.href = url;
        link.download = `recourse_students_${new Date().toISOString().split('T')[0]}.csv`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
        
        hideLoading();
        
        // Show success message
        showAlert('Export completed successfully!', 'success');
    }

    // ===========================================
    // PRINT TABLE
    // ===========================================
    function printTable() {
        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
            <html>
                <head>
                    <title>Recourse Students Report</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        h1 { color: #0072CE; text-align: center; margin-bottom: 20px; }
                        .report-info { margin-bottom: 20px; text-align: center; color: #666; }
                        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                        th { background: #0072CE; color: white; padding: 10px; text-align: left; }
                        td { padding: 8px; border-bottom: 1px solid #ddd; }
                        .status-badge { padding: 3px 10px; border-radius: 3px; font-size: 11px; font-weight: bold; }
                        .status-active { background: #4CAF50; color: white; }
                        .status-completed { background: #FF9800; color: white; }
                        .status-cancelled { background: #F44336; color: white; }
                        @media print {
                            body { margin: 0; padding: 10px; }
                            table { page-break-inside: auto; }
                            tr { page-break-inside: avoid; }
                        }
                    </style>
                </head>
                <body>
                    <h1>📚 Recourse Students Report</h1>
                    <div class="report-info">
                        <p>Generated on: ${new Date().toLocaleDateString()} ${new Date().toLocaleTimeString()}</p>
                        <p>Total Records: ${document.querySelectorAll('#mainTable tbody tr').length}</p>
                    </div>
                    ${document.getElementById('mainTable').outerHTML.replace(/no-print/g, '')}
                </body>
            </html>
        `);
        printWindow.document.close();
        printWindow.focus();
        setTimeout(() => {
            printWindow.print();
            printWindow.close();
        }, 500);
    }

    // ===========================================
    // FORM VALIDATION
    // ===========================================
    function validateForm(f) {
        const requiredFields = [
            'student_id',
            'recourse_campus_id',
            'recourse_faculty_id',
            'recourse_department_id',
            'recourse_program_id',
            'recourse_class_id',
            'recourse_semester_id',
            'subject_id',
            'academic_term_id'
        ];
        
        for (const fieldId of requiredFields) {
            const field = document.getElementById(fieldId);
            if (!field || !field.value) {
                const label = field.previousElementSibling ? field.previousElementSibling.textContent.replace('*', '').trim() : fieldId;
                alert(`⚠️ Please fill in ${label}`);
                if (field) {
                    field.focus();
                    field.style.borderColor = '#ff4444';
                    field.style.boxShadow = '0 0 0 3px rgba(255, 68, 68, 0.15)';
                    
                    setTimeout(() => {
                        field.style.borderColor = '';
                        field.style.boxShadow = '';
                    }, 3000);
                }
                return false;
            }
        }
        
        return true;
    }

    // ===========================================
    // REFRESH STATISTICS
    // ===========================================
    async function refreshStatistics() {
        showLoading('Refreshing statistics...');
        try {
            const response = await fetch('?ajax=get_statistics');
            const data = await response.json();
            
            if (data.status === 'success') {
                const stats = data.statistics;
                
                // Update statistics cards
                const cards = document.querySelectorAll('.card h3');
                if (cards.length >= 4) {
                    cards[0].textContent = stats.total;
                    cards[1].textContent = stats.active;
                    cards[2].textContent = stats.completed;
                    cards[3].textContent = stats.cancelled;
                }
                
                showAlert('Statistics refreshed successfully!', 'success');
            }
        } catch (error) {
            console.error('Error refreshing statistics:', error);
            showAlert('Error refreshing statistics', 'error');
        }
        hideLoading();
    }

    // ===========================================
    // ALERT FUNCTION
    // ===========================================
    function showAlert(message, type = 'success') {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type}`;
        alertDiv.innerHTML = `
            <div class="alert-content">
                ${type === 'success' ? '<i class="fas fa-check-circle"></i>' : '<i class="fas fa-exclamation-triangle"></i>'}
                <span>${message}</span>
            </div>
            <button class="close-alert" onclick="this.parentElement.remove()">×</button>
        `;
        
        document.querySelector('.main-content').prepend(alertDiv);
        
        setTimeout(() => {
            alertDiv.classList.add('hide');
            setTimeout(() => alertDiv.remove(), 500);
        }, 4000);
    }

    // ===========================================
    // INITIALIZATION
    // ===========================================
    document.addEventListener('DOMContentLoaded', function() {
        // Close search results when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('#student_search') && !e.target.closest('#student_results')) {
                document.getElementById('student_results').style.display = 'none';
            }
        });
        
        // Close modal when clicking outside
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                e.target.classList.remove('show');
                document.body.style.overflow = 'auto';
            }
        });
        
        // Add active class to clicked rows
        document.querySelectorAll('#mainTable tbody tr').forEach(row => {
            row.addEventListener('click', function(e) {
                if (!e.target.closest('.action-btn')) {
                    document.querySelectorAll('#mainTable tbody tr').forEach(r => r.classList.remove('active'));
                    this.classList.add('active');
                }
            });
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+F for focus search
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.querySelector('input[name="q"]').focus();
            }
            
            // Ctrl+N for new recourse
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                openModal('addModal');
            }
            
            // Escape to close modals
            if (e.key === 'Escape') {
                closeModal('addModal');
                closeModal('viewModal');
                closeConfirmModal();
            }
        });
        
        // Initialize tooltips for action buttons
        document.querySelectorAll('.action-btn').forEach(btn => {
            btn.setAttribute('title', btn.getAttribute('title') || '');
        });
        
        // Auto-hide existing alert
        const existingAlert = document.querySelector('.alert');
        if (existingAlert) {
            setTimeout(() => {
                existingAlert.classList.add('hide');
                setTimeout(() => existingAlert.remove(), 500);
            }, 4000);
        }
    });

    // ===========================================
    // RESET FORM FOR ADD
    // ===========================================
    window.addEventListener('load', function() {
        const addModal = document.getElementById('addModal');
        if (addModal) {
            // When opening modal for adding new record
            const originalOpenModal = openModal;
            openModal = function(id) {
                if (id === 'addModal') {
                    document.getElementById('formTitle').textContent = 'Add Recourse Student';
                    document.getElementById('formAction').value = 'add';
                    document.getElementById('recourse_id').value = '';
                    document.getElementById('recourseForm').reset();
                    resetModalHierarchy();
                    resetRecourseHierarchy();
                    document.getElementById('status').value = 'active';
                    document.getElementById('reason').value = '';
                    document.getElementById('student_results').style.display = 'none';
                    document.getElementById('student_results').innerHTML = '';
                }
                originalOpenModal(id);
            };
        }
    });
    </script>

    <?php include('../includes/footer.php'); ?>
    <?php ob_end_flush(); ?>
</body>
</html>