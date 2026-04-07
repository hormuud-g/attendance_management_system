<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// Check if user has permission (super_admin, campus_admin, or department_admin)
$user_role = strtolower($_SESSION['user']['role'] ?? '');
$user_id = $_SESSION['user']['id'] ?? null;
$user_linked_id = $_SESSION['user']['linked_id'] ?? null;

if (!in_array($user_role, ['super_admin', 'campus_admin', 'department_admin'])) {
    header("Location: ../login.php");
    exit;
}

date_default_timezone_set('Africa/Nairobi');
$message = ""; 
$type = "";

// Get user's linked IDs based on role
$assigned_campus_id = null;
$assigned_department_id = null;
$assigned_faculty_id = null;
$assigned_department_name = null;

if ($user_role === 'campus_admin' && $user_linked_id) {
    // Campus admin - verify the linked campus exists and is active
    $stmt = $pdo->prepare("SELECT campus_id FROM campus WHERE campus_id = ? AND status = 'active'");
    $stmt->execute([$user_linked_id]);
    $assigned_campus_id = $stmt->fetchColumn();
    
    if (!$assigned_campus_id) {
        $message = "❌ Error: Your assigned campus is not active or not found.";
        $type = "error";
        $assigned_campus_id = null;
    }
} elseif ($user_role === 'department_admin' && $user_linked_id) {
    // Department admin - get department details
    $dept_stmt = $pdo->prepare("
        SELECT d.department_id, d.department_name, d.campus_id, d.faculty_id, 
               c.campus_name, f.faculty_name
        FROM departments d
        LEFT JOIN campus c ON d.campus_id = c.campus_id AND c.status = 'active'
        LEFT JOIN faculties f ON d.faculty_id = f.faculty_id AND f.status = 'active'
        WHERE d.department_id = ? AND d.status = 'active'
    ");
    $dept_stmt->execute([$user_linked_id]);
    $dept_info = $dept_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($dept_info) {
        $assigned_department_id = $dept_info['department_id'];
        $assigned_campus_id = $dept_info['campus_id'];
        $assigned_faculty_id = $dept_info['faculty_id'];
        $assigned_department_name = $dept_info['department_name'];
    } else {
        $message = "❌ Error: Your assigned department is not active or not found.";
        $type = "error";
    }
}

// ===========================================
// AJAX HANDLERS FOR HIERARCHY
// ===========================================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    // GET FACULTIES BY CAMPUS (for FROM section - with restrictions)
    if ($_GET['ajax'] == 'get_faculties_by_campus_from') {
        $campus_id = $_GET['campus_id'] ?? 0;
        
        // Check if user is trying to access another campus in FROM section
        if (($user_role === 'campus_admin' && $campus_id != $assigned_campus_id) ||
            ($user_role === 'department_admin' && $campus_id != $assigned_campus_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied to this campus']);
            exit;
        }
        
        if ($user_role === 'department_admin' && $assigned_faculty_id) {
            // Department admin sees only their faculty in FROM section
            $stmt = $pdo->prepare("
                SELECT faculty_id, faculty_name 
                FROM faculties 
                WHERE faculty_id = ? AND status = 'active'
                ORDER BY faculty_name
            ");
            $stmt->execute([$assigned_faculty_id]);
        } else {
            $stmt = $pdo->prepare("
                SELECT DISTINCT f.faculty_id, f.faculty_name 
                FROM faculties f
                JOIN faculty_campus fc ON f.faculty_id = fc.faculty_id
                WHERE fc.campus_id = ?
                AND f.status = 'active'
                ORDER BY f.faculty_name
            ");
            $stmt->execute([$campus_id]);
        }
        
        $faculties = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'faculties' => $faculties]);
        exit;
    }
    
    // GET FACULTIES BY CAMPUS (for TO section - ALL faculties, no restrictions)
    if ($_GET['ajax'] == 'get_faculties_by_campus_to') {
        $campus_id = $_GET['campus_id'] ?? 0;
        
        // TO section shows ALL faculties in the selected campus
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
    
    // GET DEPARTMENTS BY FACULTY & CAMPUS (for FROM section - with restrictions)
    if ($_GET['ajax'] == 'get_departments_by_faculty_from') {
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        // Check if user is trying to access another campus in FROM section
        if (($user_role === 'campus_admin' && $campus_id != $assigned_campus_id) ||
            ($user_role === 'department_admin' && $campus_id != $assigned_campus_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied to this campus']);
            exit;
        }
        
        if ($user_role === 'department_admin' && $assigned_department_id) {
            // Department admin sees only their department in FROM section
            $stmt = $pdo->prepare("
                SELECT department_id, department_name 
                FROM departments 
                WHERE department_id = ? AND faculty_id = ? AND campus_id = ? AND status = 'active'
                ORDER BY department_name
            ");
            $stmt->execute([$assigned_department_id, $faculty_id, $campus_id]);
        } else {
            $stmt = $pdo->prepare("
                SELECT department_id, department_name 
                FROM departments 
                WHERE faculty_id = ? 
                AND campus_id = ?
                AND status = 'active'
                ORDER BY department_name
            ");
            $stmt->execute([$faculty_id, $campus_id]);
        }
        
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'departments' => $departments]);
        exit;
    }
    
    // GET DEPARTMENTS BY FACULTY & CAMPUS (for TO section - ALL departments)
    if ($_GET['ajax'] == 'get_departments_by_faculty_to') {
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        // TO section shows ALL departments in the selected faculty and campus
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
    
    // GET PROGRAMS BY DEPARTMENT, FACULTY & CAMPUS (for FROM section - with restrictions)
    if ($_GET['ajax'] == 'get_programs_by_department_from') {
        $department_id = $_GET['department_id'] ?? 0;
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        // Check if user is trying to access another campus in FROM section
        if (($user_role === 'campus_admin' && $campus_id != $assigned_campus_id) ||
            ($user_role === 'department_admin' && $campus_id != $assigned_campus_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied to this campus']);
            exit;
        }
        
        // For department admin, check if they're accessing their own department
        if ($user_role === 'department_admin' && $department_id != $assigned_department_id) {
            echo json_encode(['status' => 'error', 'message' => 'You can only access programs in your own department']);
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
    
    // GET PROGRAMS BY DEPARTMENT, FACULTY & CAMPUS (for TO section - ALL programs)
    if ($_GET['ajax'] == 'get_programs_by_department_to') {
        $department_id = $_GET['department_id'] ?? 0;
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        // TO section shows ALL programs in the selected department, faculty, campus
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
    
    // GET CLASSES BY PROGRAM, DEPARTMENT, FACULTY & CAMPUS (for FROM section - with restrictions)
    if ($_GET['ajax'] == 'get_classes_by_program_from') {
        $program_id = $_GET['program_id'] ?? 0;
        $department_id = $_GET['department_id'] ?? 0;
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        // Check if user is trying to access another campus in FROM section
        if (($user_role === 'campus_admin' && $campus_id != $assigned_campus_id) ||
            ($user_role === 'department_admin' && $campus_id != $assigned_campus_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied to this campus']);
            exit;
        }
        
        // For department admin, check if they're accessing their own department
        if ($user_role === 'department_admin' && $department_id != $assigned_department_id) {
            echo json_encode(['status' => 'error', 'message' => 'You can only access classes in your own department']);
            exit;
        }
        
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
    
    // GET CLASSES BY PROGRAM, DEPARTMENT, FACULTY & CAMPUS (for TO section - ALL classes)
    if ($_GET['ajax'] == 'get_classes_by_program_to') {
        $program_id = $_GET['program_id'] ?? 0;
        $department_id = $_GET['department_id'] ?? 0;
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        // TO section shows ALL classes in the selected program, department, faculty, campus
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
    
    // GET STUDENTS BY CLASS (for FROM section - with restrictions)
    if ($_GET['ajax'] == 'get_students_by_class_from') {
        $class_id = $_GET['class_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $department_id = $_GET['department_id'] ?? 0;
        
        // Check if user is trying to access another campus in FROM section
        if (($user_role === 'campus_admin' && $campus_id != $assigned_campus_id) ||
            ($user_role === 'department_admin' && $campus_id != $assigned_campus_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied to this campus']);
            exit;
        }
        
        // For department admin, check if they're accessing their own department
        if ($user_role === 'department_admin' && $department_id != $assigned_department_id) {
            echo json_encode(['status' => 'error', 'message' => 'You can only access students in your own department']);
            exit;
        }
        
        // Get students from students table with class_id
        $stmt = $pdo->prepare("
            SELECT 
                s.student_id, 
                s.full_name, 
                s.reg_no,
                s.semester_id,
                sem.semester_name,
                s.status
            FROM students s
            LEFT JOIN semester sem ON sem.semester_id = s.semester_id AND sem.status = 'active'
            WHERE s.class_id = ? 
            AND s.campus_id = ?
            AND s.faculty_id = ?
            AND s.department_id = ?
            AND s.status = 'active'
            ORDER BY s.full_name
        ");
        $stmt->execute([$class_id, $campus_id, $faculty_id, $department_id]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'students' => $students]);
        exit;
    }
    
    // GET SUBJECTS BY SEMESTER AND HIERARCHY (for TO section - ALL subjects)
    if ($_GET['ajax'] == 'get_subjects_by_semester_to') {
        $semester_id = $_GET['semester_id'] ?? 0;
        $program_id = $_GET['program_id'] ?? 0;
        $department_id = $_GET['department_id'] ?? 0;
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        // TO section shows ALL subjects in the selected hierarchy
        $stmt = $pdo->prepare("
            SELECT subject_id, subject_name, subject_code
            FROM subject 
            WHERE semester_id = ?
            AND program_id = ?
            AND department_id = ?
            AND faculty_id = ?
            AND campus_id = ?
            AND status = 'active'
            ORDER BY subject_name
        ");
        $stmt->execute([$semester_id, $program_id, $department_id, $faculty_id, $campus_id]);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'subjects' => $subjects]);
        exit;
    }
}

/* ================= HANDLE PROMOTION ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['promote'])) {
    try {
        // Check role-based restrictions
        if ($user_role === 'campus_admin') {
            $from_campus_id = $_POST['from_campus'] ?? null;
            
            // Campus admin can only promote FROM their assigned campus
            if ($from_campus_id != $assigned_campus_id) {
                throw new Exception("Access denied! You can only promote students from your assigned campus.");
            }
            
            // TO campus can be any campus (even different from their assigned)
            // No restriction on TO campus
        } elseif ($user_role === 'department_admin') {
            $from_department_id = $_POST['from_department'] ?? null;
            
            // Department admin can only promote FROM their assigned department
            if ($from_department_id != $assigned_department_id) {
                throw new Exception("Access denied! You can only promote students from your assigned department.");
            }
            
            // TO department can be any department (even different from their assigned)
            // No restriction on TO department
        }
        
        $pdo->beginTransaction();

        $student_ids     = $_POST['student_ids']  ?? [];
        $new_semester_id = $_POST['new_semester_id'] ?? null;
        $subject_ids     = $_POST['subject_ids']  ?? [];
        $remarks         = trim($_POST['remarks'] ?? '');
        $admin_id        = $_SESSION['user']['id'] ?? null;

        // To hierarchy data
        $new_campus_id   = $_POST['to_campus'] ?? null;
        $new_faculty_id  = $_POST['to_faculty'] ?? null;
        $new_department_id = $_POST['to_department'] ?? null;
        $new_program_id  = $_POST['to_program'] ?? null;
        $new_class_id    = $_POST['to_class'] ?? null;

        if (empty($student_ids))      throw new Exception("Please select at least one student!");
        if (empty($new_semester_id))  throw new Exception("Please select a new semester!");
        if (empty($new_campus_id))    throw new Exception("Please select a destination campus!");
        if (empty($new_faculty_id))   throw new Exception("Please select a destination faculty!");
        if (empty($new_department_id))throw new Exception("Please select a destination department!");
        if (empty($new_program_id))   throw new Exception("Please select a destination program!");
        if (empty($new_class_id))     throw new Exception("Please select a destination class!");

        // Validate hierarchy consistency (checking only active records)
        $hierarchy_check = $pdo->prepare("
            SELECT COUNT(*) as count FROM programs 
            WHERE program_id = ? 
            AND department_id = ? 
            AND faculty_id = ? 
            AND campus_id = ?
            AND status = 'active'
        ");
        $hierarchy_check->execute([$new_program_id, $new_department_id, $new_faculty_id, $new_campus_id]);
        $check_result = $hierarchy_check->fetch(PDO::FETCH_ASSOC);
        
        if ($check_result['count'] == 0) {
            throw new Exception("Invalid hierarchy selection! Program does not belong to selected department/faculty/campus or is inactive.");
        }

        foreach ($student_ids as $student_id) {
            // Get current student data
            $old = $pdo->prepare("
                SELECT s.*, 
                    c.campus_name,
                    f.faculty_name,
                    d.department_name,
                    p.program_name,
                    cl.class_name,
                    sem.semester_name
                FROM students s
                LEFT JOIN campus c ON c.campus_id = s.campus_id AND c.status = 'active'
                LEFT JOIN faculties f ON f.faculty_id = s.faculty_id AND f.status = 'active'
                LEFT JOIN departments d ON d.department_id = s.department_id AND d.status = 'active'
                LEFT JOIN programs p ON p.program_id = s.program_id AND p.status = 'active'
                LEFT JOIN classes cl ON cl.class_id = s.class_id AND cl.status = 'Active'
                LEFT JOIN semester sem ON sem.semester_id = s.semester_id AND sem.status = 'active'
                WHERE s.student_id = ? 
                LIMIT 1
            ");
            $old->execute([$student_id]);
            $old_data = $old->fetch(PDO::FETCH_ASSOC);
            if (!$old_data) continue;

            // Save promotion history
            $insert = $pdo->prepare("
                INSERT INTO promotion_history
                (student_id,
                old_faculty_id, old_department_id, old_program_id, old_semester_id, old_class_id,
                new_faculty_id, new_department_id, new_program_id, new_semester_id, new_class_id,
                old_campus_id, new_campus_id,
                promoted_by, remarks)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $insert->execute([
                $student_id,
                $old_data['faculty_id']    ?? null,
                $old_data['department_id'] ?? null,
                $old_data['program_id']    ?? null,
                $old_data['semester_id']   ?? null,
                $old_data['class_id']      ?? null,
                $new_faculty_id,
                $new_department_id,
                $new_program_id,
                $new_semester_id,
                $new_class_id,
                $old_data['campus_id']     ?? null,
                $new_campus_id,
                $admin_id,
                $remarks
            ]);

            // Update students table with new data
            $update = $pdo->prepare("
                UPDATE students 
                SET campus_id = ?, 
                    faculty_id = ?, 
                    department_id = ?, 
                    program_id = ?, 
                    class_id = ?, 
                    semester_id = ?, 
                    updated_at = NOW() 
                WHERE student_id = ?
            ");
            $update->execute([
                $new_campus_id,
                $new_faculty_id,
                $new_department_id,
                $new_program_id,
                $new_class_id,
                $new_semester_id,
                $student_id
            ]);

            // Also update student_enroll if exists
            $check_enroll = $pdo->prepare("SELECT COUNT(*) FROM student_enroll WHERE student_id = ?");
            $check_enroll->execute([$student_id]);
            if ($check_enroll->fetchColumn() > 0) {
                $update_enroll = $pdo->prepare("
                    UPDATE student_enroll 
                    SET campus_id = ?, 
                        faculty_id = ?, 
                        department_id = ?, 
                        program_id = ?, 
                        class_id = ?, 
                        semester_id = ?, 
                        updated_at = NOW() 
                    WHERE student_id = ?
                ");
                $update_enroll->execute([
                    $new_campus_id,
                    $new_faculty_id,
                    $new_department_id,
                    $new_program_id,
                    $new_class_id,
                    $new_semester_id,
                    $student_id
                ]);
            }

            // Remove old subject links and add new ones (only active subjects)
            $pdo->prepare("DELETE FROM student_subject WHERE student_id = ?")->execute([$student_id]);
            
            // Link new subjects (ensure subjects are active)
            foreach ($subject_ids as $sub_id) {
                $check_subject = $pdo->prepare("SELECT COUNT(*) FROM subject WHERE subject_id = ? AND status = 'active'");
                $check_subject->execute([$sub_id]);
                if ($check_subject->fetchColumn() > 0) {
                    $pdo->prepare("
                    INSERT INTO student_subject (student_id, subject_id, assigned_at)
                    VALUES (?, ?, NOW())
                    ")->execute([$student_id, $sub_id]);
                }
            }
        }

        $pdo->commit();
        $message = "✅ Selected students promoted / transferred successfully!";
        $type = "success";

    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "❌ Error: " . $e->getMessage();
        $type = "error";
    }
}

/* ================= FETCH INITIAL DATA ================= */

/* ✅ All Campuses (ACTIVE ONLY) - for TO section, show ALL campuses */
$all_campuses = $pdo->query("
    SELECT campus_id, campus_name 
    FROM campus 
    WHERE status = 'active'
    ORDER BY campus_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* ✅ Campuses for FROM section - restricted based on role */
if ($user_role === 'super_admin') {
    $from_campuses = $all_campuses;
} elseif ($user_role === 'campus_admin' && $assigned_campus_id) {
    // Campus admin only sees their assigned campus in FROM section
    $stmt = $pdo->prepare("
        SELECT campus_id, campus_name 
        FROM campus 
        WHERE campus_id = ? 
        AND status = 'active'
        ORDER BY campus_name ASC
    ");
    $stmt->execute([$assigned_campus_id]);
    $from_campuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($user_role === 'department_admin' && $assigned_campus_id) {
    // Department admin only sees their campus in FROM section
    $stmt = $pdo->prepare("
        SELECT campus_id, campus_name 
        FROM campus 
        WHERE campus_id = ? 
        AND status = 'active'
        ORDER BY campus_name ASC
    ");
    $stmt->execute([$assigned_campus_id]);
    $from_campuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $from_campuses = [];
}

/* ✅ Semesters (ACTIVE ONLY) */
$semesters = $pdo->query("
    SELECT semester_id, semester_name 
    FROM semester 
    WHERE status = 'active'
    ORDER BY semester_id ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* ✅ Promotion History (restricted based on role) */
if ($user_role === 'super_admin') {
    $history = $pdo->query("
        SELECT 
        ph.promotion_date,
        ph.student_id,
        ph.old_faculty_id,
        ph.old_department_id,
        ph.old_program_id,
        ph.old_semester_id,
        ph.new_faculty_id,
        ph.new_department_id,
        ph.new_program_id,
        ph.new_semester_id,
        ph.old_campus_id,
        ph.new_campus_id,
        ph.promoted_by,
        ph.remarks,
        s.full_name, 
        s.reg_no,
        se.semester_name AS new_sem,
        oc.campus_name AS old_campus,
        nc.campus_name AS new_campus
        FROM promotion_history ph
        JOIN students s ON s.student_id = ph.student_id
        LEFT JOIN semester se ON se.semester_id = ph.new_semester_id AND se.status = 'active'
        LEFT JOIN campus oc ON oc.campus_id = ph.old_campus_id AND oc.status = 'active'
        LEFT JOIN campus nc ON nc.campus_id = ph.new_campus_id AND nc.status = 'active'
        WHERE s.status = 'active'
        ORDER BY ph.promotion_date DESC
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);
} elseif ($user_role === 'campus_admin' && $assigned_campus_id) {
    // Campus admin only sees history within their campus
    $stmt = $pdo->prepare("
        SELECT 
        ph.promotion_date,
        ph.student_id,
        ph.old_faculty_id,
        ph.old_department_id,
        ph.old_program_id,
        ph.old_semester_id,
        ph.new_faculty_id,
        ph.new_department_id,
        ph.new_program_id,
        ph.new_semester_id,
        ph.old_campus_id,
        ph.new_campus_id,
        ph.promoted_by,
        ph.remarks,
        s.full_name, 
        s.reg_no,
        se.semester_name AS new_sem,
        oc.campus_name AS old_campus,
        nc.campus_name AS new_campus
        FROM promotion_history ph
        JOIN students s ON s.student_id = ph.student_id
        LEFT JOIN semester se ON se.semester_id = ph.new_semester_id AND se.status = 'active'
        LEFT JOIN campus oc ON oc.campus_id = ph.old_campus_id AND oc.status = 'active'
        LEFT JOIN campus nc ON nc.campus_id = ph.new_campus_id AND nc.status = 'active'
        WHERE s.status = 'active'
        AND (ph.old_campus_id = ? OR ph.new_campus_id = ?)
        ORDER BY ph.promotion_date DESC
        LIMIT 50
    ");
    $stmt->execute([$assigned_campus_id, $assigned_campus_id]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($user_role === 'department_admin' && $assigned_department_id) {
    // Department admin only sees history within their department
    $stmt = $pdo->prepare("
        SELECT 
        ph.promotion_date,
        ph.student_id,
        ph.old_faculty_id,
        ph.old_department_id,
        ph.old_program_id,
        ph.old_semester_id,
        ph.new_faculty_id,
        ph.new_department_id,
        ph.new_program_id,
        ph.new_semester_id,
        ph.old_campus_id,
        ph.new_campus_id,
        ph.promoted_by,
        ph.remarks,
        s.full_name, 
        s.reg_no,
        se.semester_name AS new_sem,
        oc.campus_name AS old_campus,
        nc.campus_name AS new_campus
        FROM promotion_history ph
        JOIN students s ON s.student_id = ph.student_id
        LEFT JOIN semester se ON se.semester_id = ph.new_semester_id AND se.status = 'active'
        LEFT JOIN campus oc ON oc.campus_id = ph.old_campus_id AND oc.status = 'active'
        LEFT JOIN campus nc ON nc.campus_id = ph.new_campus_id AND nc.status = 'active'
        WHERE s.status = 'active'
        AND (ph.old_department_id = ? OR ph.new_department_id = ?)
        ORDER BY ph.promotion_date DESC
        LIMIT 50
    ");
    $stmt->execute([$assigned_department_id, $assigned_department_id]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $history = [];
}

include('../includes/header.php');
?>
<style>
    .sidebar.collapsed ~ .main-content {
        margin-left: 70px;
    }
    .restricted-info {
        background-color: #fff3cd;
        border: 1px solid #ffeaa7;
        border-radius: 6px;
        padding: 10px 15px;
        margin-bottom: 15px;
        color: #856404;
        font-size: 14px;
    }
    .info-box {
        background: linear-gradient(135deg, #e6f3ff, #d4eaff);
        border: 2px solid #0072CE;
        border-radius: 12px;
        padding: 15px 20px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 15px;
    }
    .info-box i {
        color: #0072CE;
        font-size: 24px;
        background: white;
        width: 50px;
        height: 50px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
    }
    .info-box-content {
        flex: 1;
    }
    .info-box-title {
        font-weight: 700;
        color: #0072CE;
        margin-bottom: 5px;
        font-size: 16px;
    }
    .info-box-text {
        color: #555;
        font-size: 14px;
    }
    .note-box {
        background-color: #e7f3ff;
        border: 1px solid #b8daff;
        border-radius: 6px;
        padding: 8px 12px;
        margin-bottom: 15px;
        color: #004085;
        font-size: 13px;
    }
</style>
<div class="main-content">
    <div class="page-header">
        <h1>Student Promotion</h1>
    </div>
  
    <!-- PROMOTION FORM -->
    <div class="filter-box">
        <form method="POST" id="promotionForm">
            <!-- Hidden field to track from campus for validation -->
            <input type="hidden" id="from_campus_hidden" name="from_campus" value="">
            
            <div class="grid">

                <!-- ========== FROM HIERARCHY ========== -->
                <fieldset style="border:1px solid #ccc;border-radius:8px;padding:10px;">
                    <legend style="color:#0072CE;font-weight:600;">From (Current) - Your Assigned Area</legend>
                    
                    <div>
                        <label>Campus</label>
                        <select id="from_campus" onchange="fromCampusChange()" required 
                            <?php if ($user_role !== 'super_admin'): ?>disabled<?php endif; ?>>
                            <option value="">Select Campus</option>
                            <?php foreach($from_campuses as $c): ?>
                                <option value="<?= $c['campus_id'] ?>" 
                                    <?php if ($user_role !== 'super_admin' && $c['campus_id'] == $assigned_campus_id): ?>selected<?php endif; ?>>
                                    <?= htmlspecialchars($c['campus_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($user_role !== 'super_admin' && $assigned_campus_id): ?>
                            <input type="hidden" id="from_campus_hidden_select" name="from_campus" value="<?= $assigned_campus_id ?>">
                        <?php endif; ?>
                    </div>

                    <div>
                        <label>Faculty</label>
                        <select id="from_faculty" onchange="fromFacultyChange()" required 
                            <?= ($user_role === 'department_admin') ? 'disabled' : '' ?>>
                            <option value="">Select Faculty</option>
                        </select>
                        <?php if ($user_role === 'department_admin' && $assigned_faculty_id): ?>
                            <input type="hidden" id="from_faculty_hidden" name="from_faculty" value="<?= $assigned_faculty_id ?>">
                        <?php endif; ?>
                    </div>

                    <div>
                        <label>Department</label>
                        <select id="from_department" onchange="fromDepartmentChange()" required 
                            <?= ($user_role === 'department_admin') ? 'disabled' : '' ?>>
                            <option value="">Select Department</option>
                        </select>
                        <?php if ($user_role === 'department_admin' && $assigned_department_id): ?>
                            <input type="hidden" id="from_department_hidden" name="from_department" value="<?= $assigned_department_id ?>">
                        <?php endif; ?>
                    </div>

                    <div>
                        <label>Program</label>
                        <select id="from_program" onchange="fromProgramChange()" required disabled>
                            <option value="">Select Program</option>
                        </select>
                    </div>

                    <div>
                        <label>Class</label>
                        <select id="from_class" onchange="fromClassChange()" required disabled>
                            <option value="">Select Class</option>
                        </select>
                    </div>

                    <div class="checkbox-group">
                        <label>Select Students</label>
                        <div class="checkbox-list" id="from_student_list">
                            <small style="color:#777;">Please select class first.</small>
                        </div>
                    </div>
                </fieldset>

                <!-- ========== TO HIERARCHY ========== -->
                <fieldset style="border:1px solid #ccc;border-radius:8px;padding:10px;">
                    <legend style="color:#0072CE;font-weight:600;">To (Destination) - Any Campus/Faculty/Department</legend>
                    
                    <div>
                        <label>Campus</label>
                        <select id="to_campus" name="to_campus" onchange="toCampusChange()" required>
                            <option value="">Select Campus</option>
                            <?php foreach($all_campuses as $c): ?>
                                <option value="<?= $c['campus_id'] ?>">
                                    <?= htmlspecialchars($c['campus_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Faculty</label>
                        <select id="to_faculty" name="to_faculty" onchange="toFacultyChange()" required disabled>
                            <option value="">Select Faculty</option>
                        </select>
                    </div>

                    <div>
                        <label>Department</label>
                        <select id="to_department" name="to_department" onchange="toDepartmentChange()" required disabled>
                            <option value="">Select Department</option>
                        </select>
                    </div>

                    <div>
                        <label>Program</label>
                        <select id="to_program" name="to_program" onchange="toProgramChange()" required disabled>
                            <option value="">Select Program</option>
                        </select>
                    </div>

                    <div>
                        <label>Class</label>
                        <select id="to_class" name="to_class" onchange="toClassChange()" required disabled>
                            <option value="">Select Class</option>
                        </select>
                    </div>

                    <div>
                        <label>New Semester</label>
                        <select id="new_semester" name="new_semester_id" onchange="toSemesterChange()" required>
                            <option value="">Select Semester</option>
                            <?php foreach($semesters as $sem): ?>
                                <option value="<?= $sem['semester_id'] ?>"><?= htmlspecialchars($sem['semester_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="checkbox-group">
                        <label>Select Subjects</label>
                        <div class="checkbox-list" id="to_subject_list">
                            <small style="color:#777;">Please select semester first.</small>
                        </div>
                    </div>

                    <div>
                        <label>Remarks</label>
                        <input type="text" name="remarks" placeholder="Optional comment...">
                    </div>

                    <div style="align-self:end;">
                        <button type="submit" name="promote" class="btn green">
                            <i class="fa fa-arrow-up"></i> Promote Selected Students
                        </button>
                    </div>
                </fieldset>

            </div>
        </form>
    </div>

    <!-- PROMOTION HISTORY -->
    <div class="table-wrapper">
        <h3 style="color:#0072CE;margin:10px;">Recent Promotion History</h3>
        <?php if (empty($history)): ?>
            <div class="restricted-info">
                <i class="fa fa-info-circle"></i> No promotion history found for your access level.
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Student</th>
                        <th>Reg No</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Old Semester</th>
                        <th>New Semester</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($history as $h): ?>
                    <tr>
                        <td><?= htmlspecialchars($h['promotion_date']) ?></td>
                        <td><?= htmlspecialchars($h['full_name']) ?></td>
                        <td><?= htmlspecialchars($h['reg_no']) ?></td>
                        <td><?= htmlspecialchars($h['old_campus'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($h['new_campus'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($h['old_semester_id']) ?></td>
                        <td><?= htmlspecialchars($h['new_sem'] ?? $h['new_semester_id']) ?></td>
                        <td><?= htmlspecialchars($h['remarks']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php if($message): ?>
<div class="alert <?= $type ?>"><strong><?= $message ?></strong></div>
<script>
    setTimeout(()=>document.querySelector('.alert').remove(),5000);
</script>
<?php endif; ?>

<!-- STYLE -->
<style>
body{font-family:'Poppins',sans-serif;background:#f5f9f7;margin:0;}
.main-content{padding:25px;margin-left:250px;margin-top:90px;}
.page-header h1{color:#0072CE;margin-bottom:15px;}
.filter-box{background:#fff;padding:20px;border-radius:10px;box-shadow:0 3px 8px rgba(0,0,0,0.08);margin-bottom:15px;}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:15px;}
label{font-weight:600;color:#0072CE;font-size:13px;}
select,input{width:100%;padding:8px;border:1px solid #ccc;border-radius:6px;background:#fafafa;}
select:disabled{background:#e9ecef;cursor:not-allowed;opacity:0.7;}
.btn{border:none;padding:9px 15px;border-radius:6px;font-weight:600;cursor:pointer;}
.btn.green{background:#00843D;color:#fff;}
.btn.green:hover{background:#00A651;}
.table-wrapper{overflow:auto;background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.08);}
table{width:100%;border-collapse:collapse;}
th,td{padding:10px 12px;border-bottom:1px solid #eee;text-align:left;font-size:14px;}
thead th{background:#0072CE;color:#fff;position:sticky;top:0;}
tr:hover{background:#f3f8ff;}
.alert{position:fixed;top:15px;right:15px;background:#00843D;color:#fff;padding:10px 20px;border-radius:6px;font-weight:600;z-index:9999;}
.alert.error{background:#C62828;}
.checkbox-list{
    max-height:220px;
    overflow-y:auto;
    border:1px solid #ddd;
    border-radius:6px;
    padding:6px;
    background:#f9f9f9;
}
.checkbox-list label{
    display:block;
    padding:3px 2px;
    font-size:13px;
    cursor:pointer;
}
.checkbox-list input{margin-right:6px;}
.note-box i{margin-right:5px;}
</style>

<script>
// Set initial campus for non-super admin users (FROM section only)
<?php if ($user_role !== 'super_admin' && $assigned_campus_id): ?>
    document.addEventListener('DOMContentLoaded', function() {
        // Trigger from campus change on page load
        const fromCampusSelect = document.getElementById('from_campus');
        if (fromCampusSelect) {
            fromCampusSelect.value = '<?= $assigned_campus_id ?>';
            document.getElementById('from_campus_hidden').value = '<?= $assigned_campus_id ?>';
            fromCampusChange();
        }
        
        // TO campus is NOT pre-selected - user can choose any campus
    });
<?php endif; ?>

// ===========================================
// FROM SECTION FUNCTIONS
// ===========================================

function fromCampusChange() {
    const campusId = document.getElementById('from_campus').value;
    document.getElementById('from_campus_hidden').value = campusId;
    const facultySelect = document.getElementById('from_faculty');
    
    if (!campusId) {
        resetFromHierarchy();
        return;
    }
    
    // Reset child dropdowns
    resetFromDropdowns(['department', 'program', 'class', 'student_list']);
    facultySelect.innerHTML = '<option value="">Loading...</option>';
    facultySelect.disabled = false;
    
    fetch(`?ajax=get_faculties_by_campus_from&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'error') {
                alert(data.message);
                facultySelect.innerHTML = '<option value="">Access denied</option>';
                facultySelect.disabled = true;
                return;
            }
            
            facultySelect.innerHTML = '<option value="">Select Faculty</option>';
            
            <?php if ($user_role === 'department_admin' && $assigned_faculty_id): ?>
                // For department admin, pre-select their faculty
                if (data.faculties.length > 0) {
                    data.faculties.forEach(faculty => {
                        const option = document.createElement('option');
                        option.value = faculty.faculty_id;
                        option.textContent = faculty.faculty_name;
                        if (faculty.faculty_id == '<?= $assigned_faculty_id ?>') {
                            option.selected = true;
                        }
                        facultySelect.appendChild(option);
                    });
                    facultySelect.disabled = false;
                    // Trigger faculty change to load departments
                    fromFacultyChange();
                } else {
                    facultySelect.innerHTML = '<option value="">No active faculties found</option>';
                    facultySelect.disabled = true;
                }
            <?php else: ?>
                if (data.status === 'success' && data.faculties.length > 0) {
                    data.faculties.forEach(faculty => {
                        const option = document.createElement('option');
                        option.value = faculty.faculty_id;
                        option.textContent = faculty.faculty_name;
                        facultySelect.appendChild(option);
                    });
                    facultySelect.disabled = false;
                } else {
                    facultySelect.innerHTML = '<option value="">No active faculties found</option>';
                    facultySelect.disabled = true;
                }
            <?php endif; ?>
        })
        .catch(error => {
            console.error('Error:', error);
            facultySelect.innerHTML = '<option value="">Error loading faculties</option>';
            facultySelect.disabled = true;
        });
}

function fromFacultyChange() {
    const facultyId = document.getElementById('from_faculty').value;
    const campusId = document.getElementById('from_campus').value;
    const deptSelect = document.getElementById('from_department');
    
    if (!facultyId || !campusId) {
        resetFromDropdowns(['department', 'program', 'class', 'student_list']);
        return;
    }
    
    deptSelect.innerHTML = '<option value="">Loading...</option>';
    deptSelect.disabled = false;
    
    fetch(`?ajax=get_departments_by_faculty_from&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'error') {
                alert(data.message);
                deptSelect.innerHTML = '<option value="">Access denied</option>';
                deptSelect.disabled = true;
                return;
            }
            
            deptSelect.innerHTML = '<option value="">Select Department</option>';
            
            <?php if ($user_role === 'department_admin' && $assigned_department_id): ?>
                // For department admin, pre-select their department
                if (data.departments.length > 0) {
                    data.departments.forEach(dept => {
                        const option = document.createElement('option');
                        option.value = dept.department_id;
                        option.textContent = dept.department_name;
                        if (dept.department_id == '<?= $assigned_department_id ?>') {
                            option.selected = true;
                        }
                        deptSelect.appendChild(option);
                    });
                    deptSelect.disabled = false;
                    // Trigger department change to load programs
                    fromDepartmentChange();
                } else {
                    deptSelect.innerHTML = '<option value="">No active departments found</option>';
                    deptSelect.disabled = true;
                }
            <?php else: ?>
                if (data.status === 'success' && data.departments.length > 0) {
                    data.departments.forEach(dept => {
                        const option = document.createElement('option');
                        option.value = dept.department_id;
                        option.textContent = dept.department_name;
                        deptSelect.appendChild(option);
                    });
                    deptSelect.disabled = false;
                } else {
                    deptSelect.innerHTML = '<option value="">No active departments found</option>';
                    deptSelect.disabled = true;
                }
            <?php endif; ?>
        });
    
    resetFromDropdowns(['program', 'class', 'student_list']);
}

function fromDepartmentChange() {
    const deptId = document.getElementById('from_department').value;
    const facultyId = document.getElementById('from_faculty').value;
    const campusId = document.getElementById('from_campus').value;
    const programSelect = document.getElementById('from_program');
    
    if (!deptId || !facultyId || !campusId) {
        resetFromDropdowns(['program', 'class', 'student_list']);
        return;
    }
    
    programSelect.innerHTML = '<option value="">Loading...</option>';
    programSelect.disabled = false;
    
    fetch(`?ajax=get_programs_by_department_from&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'error') {
                alert(data.message);
                programSelect.innerHTML = '<option value="">Access denied</option>';
                programSelect.disabled = true;
                return;
            }
            
            programSelect.innerHTML = '<option value="">Select Program</option>';
            
            if (data.status === 'success' && data.programs.length > 0) {
                data.programs.forEach(program => {
                    const option = document.createElement('option');
                    option.value = program.program_id;
                    option.textContent = program.program_name + (program.program_code ? ' (' + program.program_code + ')' : '');
                    programSelect.appendChild(option);
                });
                programSelect.disabled = false;
            } else {
                programSelect.innerHTML = '<option value="">No active programs found</option>';
                programSelect.disabled = true;
            }
        });
    
    resetFromDropdowns(['class', 'student_list']);
}

function fromProgramChange() {
    const programId = document.getElementById('from_program').value;
    const deptId = document.getElementById('from_department').value;
    const facultyId = document.getElementById('from_faculty').value;
    const campusId = document.getElementById('from_campus').value;
    const classSelect = document.getElementById('from_class');
    
    if (!programId || !deptId || !facultyId || !campusId) {
        resetFromDropdowns(['class', 'student_list']);
        return;
    }
    
    classSelect.innerHTML = '<option value="">Loading...</option>';
    classSelect.disabled = false;
    
    fetch(`?ajax=get_classes_by_program_from&program_id=${programId}&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'error') {
                alert(data.message);
                classSelect.innerHTML = '<option value="">Access denied</option>';
                classSelect.disabled = true;
                return;
            }
            
            classSelect.innerHTML = '<option value="">Select Class</option>';
            
            if (data.status === 'success' && data.classes.length > 0) {
                data.classes.forEach(cls => {
                    const option = document.createElement('option');
                    option.value = cls.class_id;
                    option.textContent = cls.class_name;
                    classSelect.appendChild(option);
                });
                classSelect.disabled = false;
            } else {
                classSelect.innerHTML = '<option value="">No active classes found</option>';
                classSelect.disabled = true;
            }
        });
    
    document.getElementById('from_student_list').innerHTML = '<small style="color:#777;">Please select class first.</small>';
}

function fromClassChange() {
    const classId = document.getElementById('from_class').value;
    const facultyId = document.getElementById('from_faculty').value;
    const deptId = document.getElementById('from_department').value;
    const campusId = document.getElementById('from_campus').value;
    const studentList = document.getElementById('from_student_list');
    
    if (!classId || !campusId || !facultyId || !deptId) {
        studentList.innerHTML = '<small style="color:#777;">Please select class first.</small>';
        return;
    }
    
    studentList.innerHTML = '<small style="color:#777;">Loading active students...</small>';
    
    fetch(`?ajax=get_students_by_class_from&class_id=${classId}&campus_id=${campusId}&faculty_id=${facultyId}&department_id=${deptId}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'error') {
                alert(data.message);
                studentList.innerHTML = '<small style="color:#777;">Access denied</small>';
                return;
            }
            
            studentList.innerHTML = '';
            
            if (data.status === 'success' && data.students.length > 0) {
                data.students.forEach(student => {
                    const label = document.createElement('label');
                    label.innerHTML = `
                        <input type="checkbox" name="student_ids[]" value="${student.student_id}">
                        ${student.full_name} (${student.reg_no}) - ${student.semester_name || 'No semester'}
                    `;
                    studentList.appendChild(label);
                });
            } else {
                studentList.innerHTML = '<small style="color:#777;">No active students found for this class.</small>';
            }
        });
}

// ===========================================
// TO SECTION FUNCTIONS - ALL CAMPUSES, FACULTIES, DEPARTMENTS
// ===========================================

function toCampusChange() {
    const campusId = document.getElementById('to_campus').value;
    const facultySelect = document.getElementById('to_faculty');
    
    if (!campusId) {
        resetToHierarchy();
        return;
    }
    
    // Reset child dropdowns
    resetToDropdowns(['department', 'program', 'class', 'subject_list']);
    facultySelect.innerHTML = '<option value="">Loading...</option>';
    facultySelect.disabled = false;
    
    // Use TO-specific endpoint that returns ALL faculties
    fetch(`?ajax=get_faculties_by_campus_to&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'error') {
                alert(data.message);
                facultySelect.innerHTML = '<option value="">Access denied</option>';
                facultySelect.disabled = true;
                return;
            }
            
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
                facultySelect.innerHTML = '<option value="">No active faculties found</option>';
                facultySelect.disabled = true;
            }
        });
}

function toFacultyChange() {
    const facultyId = document.getElementById('to_faculty').value;
    const campusId = document.getElementById('to_campus').value;
    const deptSelect = document.getElementById('to_department');
    
    if (!facultyId || !campusId) {
        resetToDropdowns(['department', 'program', 'class', 'subject_list']);
        return;
    }
    
    deptSelect.innerHTML = '<option value="">Loading...</option>';
    deptSelect.disabled = false;
    
    // Use TO-specific endpoint that returns ALL departments
    fetch(`?ajax=get_departments_by_faculty_to&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'error') {
                alert(data.message);
                deptSelect.innerHTML = '<option value="">Access denied</option>';
                deptSelect.disabled = true;
                return;
            }
            
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
                deptSelect.innerHTML = '<option value="">No active departments found</option>';
                deptSelect.disabled = true;
            }
        });
    
    resetToDropdowns(['program', 'class', 'subject_list']);
}

function toDepartmentChange() {
    const deptId = document.getElementById('to_department').value;
    const facultyId = document.getElementById('to_faculty').value;
    const campusId = document.getElementById('to_campus').value;
    const programSelect = document.getElementById('to_program');
    
    if (!deptId || !facultyId || !campusId) {
        resetToDropdowns(['program', 'class', 'subject_list']);
        return;
    }
    
    programSelect.innerHTML = '<option value="">Loading...</option>';
    programSelect.disabled = false;
    
    // Use TO-specific endpoint that returns ALL programs
    fetch(`?ajax=get_programs_by_department_to&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'error') {
                alert(data.message);
                programSelect.innerHTML = '<option value="">Access denied</option>';
                programSelect.disabled = true;
                return;
            }
            
            programSelect.innerHTML = '<option value="">Select Program</option>';
            
            if (data.status === 'success' && data.programs.length > 0) {
                data.programs.forEach(program => {
                    const option = document.createElement('option');
                    option.value = program.program_id;
                    option.textContent = program.program_name + (program.program_code ? ' (' + program.program_code + ')' : '');
                    programSelect.appendChild(option);
                });
                programSelect.disabled = false;
            } else {
                programSelect.innerHTML = '<option value="">No active programs found</option>';
                programSelect.disabled = true;
            }
        });
    
    resetToDropdowns(['class', 'subject_list']);
}

function toProgramChange() {
    const programId = document.getElementById('to_program').value;
    const deptId = document.getElementById('to_department').value;
    const facultyId = document.getElementById('to_faculty').value;
    const campusId = document.getElementById('to_campus').value;
    const classSelect = document.getElementById('to_class');
    
    if (!programId || !deptId || !facultyId || !campusId) {
        resetToDropdowns(['class', 'subject_list']);
        return;
    }
    
    classSelect.innerHTML = '<option value="">Loading...</option>';
    classSelect.disabled = false;
    
    // Use TO-specific endpoint that returns ALL classes
    fetch(`?ajax=get_classes_by_program_to&program_id=${programId}&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'error') {
                alert(data.message);
                classSelect.innerHTML = '<option value="">Access denied</option>';
                classSelect.disabled = true;
                return;
            }
            
            classSelect.innerHTML = '<option value="">Select Class</option>';
            
            if (data.status === 'success' && data.classes.length > 0) {
                data.classes.forEach(cls => {
                    const option = document.createElement('option');
                    option.value = cls.class_id;
                    option.textContent = cls.class_name;
                    classSelect.appendChild(option);
                });
                classSelect.disabled = false;
            } else {
                classSelect.innerHTML = '<option value="">No active classes found</option>';
                classSelect.disabled = true;
            }
        });
    
    document.getElementById('to_subject_list').innerHTML = '<small style="color:#777;">Please select semester first.</small>';
}

function toClassChange() {
    // This function can be used if needed
    document.getElementById('to_subject_list').innerHTML = '<small style="color:#777;">Please select semester first.</small>';
}

function toSemesterChange() {
    const semesterId = document.getElementById('new_semester').value;
    const programId = document.getElementById('to_program').value;
    const deptId = document.getElementById('to_department').value;
    const facultyId = document.getElementById('to_faculty').value;
    const campusId = document.getElementById('to_campus').value;
    const subjectList = document.getElementById('to_subject_list');
    
    // Need all hierarchy elements to load subjects
    if (!semesterId || !programId || !deptId || !facultyId || !campusId) {
        subjectList.innerHTML = '<small style="color:#777;">Please complete all hierarchy selections first.</small>';
        return;
    }
    
    subjectList.innerHTML = '<small style="color:#777;">Loading active subjects...</small>';
    
    // Use TO-specific endpoint that returns ALL subjects
    fetch(`?ajax=get_subjects_by_semester_to&semester_id=${semesterId}&program_id=${programId}&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'error') {
                alert(data.message);
                subjectList.innerHTML = '<small style="color:#777;">Access denied</small>';
                return;
            }
            
            subjectList.innerHTML = '';
            
            if (data.status === 'success' && data.subjects.length > 0) {
                data.subjects.forEach(subject => {
                    const label = document.createElement('label');
                    label.innerHTML = `
                        <input type="checkbox" name="subject_ids[]" value="${subject.subject_id}">
                        ${subject.subject_name} ${subject.subject_code ? '(' + subject.subject_code + ')' : ''}
                    `;
                    subjectList.appendChild(label);
                });
            } else {
                subjectList.innerHTML = '<small style="color:#777;">No active subjects found for this semester and program.</small>';
            }
        });
}

// ===========================================
// HELPER FUNCTIONS
// ===========================================

function resetFromHierarchy() {
    document.getElementById('from_faculty').innerHTML = '<option value="">Select Faculty</option>';
    document.getElementById('from_faculty').disabled = true;
    resetFromDropdowns(['department', 'program', 'class', 'student_list']);
}

function resetToHierarchy() {
    document.getElementById('to_faculty').innerHTML = '<option value="">Select Faculty</option>';
    document.getElementById('to_faculty').disabled = true;
    resetToDropdowns(['department', 'program', 'class', 'subject_list']);
}

function resetFromDropdowns(fields) {
    fields.forEach(field => {
        const element = document.getElementById('from_' + field);
        if (element) {
            if (field === 'student_list') {
                element.innerHTML = '<small style="color:#777;">Please select class first.</small>';
            } else {
                element.innerHTML = '<option value="">Select ' + field.charAt(0).toUpperCase() + field.slice(1) + '</option>';
                element.disabled = true;
            }
        }
    });
}

function resetToDropdowns(fields) {
    fields.forEach(field => {
        const element = document.getElementById('to_' + field);
        if (element) {
            if (field === 'subject_list') {
                element.innerHTML = '<small style="color:#777;">Please select semester first.</small>';
            } else {
                element.innerHTML = '<option value="">Select ' + field.charAt(0).toUpperCase() + field.slice(1) + '</option>';
                element.disabled = true;
            }
        }
    });
}

// Form validation
document.getElementById('promotionForm').onsubmit = function(e) {
    const studentCheckboxes = document.querySelectorAll('input[name="student_ids[]"]:checked');
    if (studentCheckboxes.length === 0) {
        alert('Please select at least one student to promote.');
        e.preventDefault();
        return false;
    }
    
    const subjectCheckboxes = document.querySelectorAll('input[name="subject_ids[]"]:checked');
    if (subjectCheckboxes.length === 0) {
        if (!confirm('No subjects selected. Students will be promoted without subjects. Continue?')) {
            e.preventDefault();
            return false;
        }
    }
    
    return true;
};
</script>

<?php include('../includes/footer.php'); ?>
<?php ob_end_flush(); ?>