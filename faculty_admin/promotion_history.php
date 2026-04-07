<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ===========================================
// SECURITY: ACCESS CONTROL
// ===========================================
if (empty($_SESSION['user'])) {
    header("Location: ../login.php");
    exit;
}

// Faculty Admin only
$user_role = strtolower($_SESSION['user']['role'] ?? '');
if ($user_role !== 'faculty_admin') {
    header("Location: ../unauthorized.php");
    exit;
}

$user = $_SESSION['user'] ?? [];

// Get faculty ID from session
$faculty_id = $user['linked_id'] ?? null;
if (!$faculty_id) {
    header("Location: ../login.php");
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
        
        // Add campus name for display
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
    
    // GET STUDENTS BY CLASS AND CAMPUS
    if ($_GET['ajax'] == 'get_students_by_class') {
        $class_id = $_GET['class_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        // Check if campus belongs to this faculty
        if (!in_array($campus_id, $campus_ids)) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied to this campus']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                s.student_id, 
                s.full_name, 
                s.reg_no,
                se.semester_id,
                sem.semester_name
            FROM students s
            JOIN student_enroll se ON se.student_id = s.student_id
            LEFT JOIN semester sem ON sem.semester_id = se.semester_id
            WHERE se.class_id = ? 
            AND se.campus_id = ?
            AND s.status = 'active'
            ORDER BY s.full_name
        ");
        $stmt->execute([$class_id, $campus_id]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'students' => $students]);
        exit;
    }
    
    // GET SUBJECTS BY SEMESTER AND HIERARCHY
    if ($_GET['ajax'] == 'get_subjects_by_semester') {
        $semester_id = $_GET['semester_id'] ?? 0;
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
        $pdo->beginTransaction();

        $student_ids     = $_POST['student_ids']  ?? [];
        $new_semester_id = $_POST['new_semester_id'] ?? null;
        $subject_ids     = $_POST['subject_ids']  ?? [];
        $remarks         = trim($_POST['remarks'] ?? '');
        $admin_id        = $_SESSION['user']['user_id'] ?? null;

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

        // Check if destination campus belongs to this faculty
        if (!in_array($new_campus_id, $campus_ids)) {
            throw new Exception("Access denied: You can only promote to campuses within your faculty!");
        }

        // Validate hierarchy consistency
        $hierarchy_check = $pdo->prepare("
            SELECT COUNT(*) as count FROM programs 
            WHERE program_id = ? 
            AND department_id = ? 
            AND faculty_id = ? 
            AND campus_id = ?
            AND status = 'active'
        ");
        $hierarchy_check->execute([$new_program_id, $new_department_id, $faculty_id, $new_campus_id]);
        $check_result = $hierarchy_check->fetch(PDO::FETCH_ASSOC);
        
        if ($check_result['count'] == 0) {
            throw new Exception("Invalid hierarchy selection! Program does not belong to selected department/faculty/campus or is inactive.");
        }

        // Check if class belongs to program
        $class_check = $pdo->prepare("
            SELECT COUNT(*) FROM classes 
            WHERE class_id = ? 
            AND program_id = ?
            AND department_id = ?
            AND faculty_id = ?
            AND campus_id = ?
            AND status = 'Active'
        ");
        $class_check->execute([$new_class_id, $new_program_id, $new_department_id, $faculty_id, $new_campus_id]);
        if ($class_check->fetchColumn() == 0) {
            throw new Exception("Selected class does not belong to the chosen program or is inactive!");
        }

        foreach ($student_ids as $student_id) {
            // Get current enrollment data
            $old = $pdo->prepare("
                SELECT se.*, 
                    c.campus_name,
                    f.faculty_name,
                    d.department_name,
                    p.program_name,
                    cl.class_name,
                    sem.semester_name
                FROM student_enroll se
                LEFT JOIN campus c ON c.campus_id = se.campus_id AND c.status = 'active'
                LEFT JOIN faculties f ON f.faculty_id = se.faculty_id AND f.status = 'active'
                LEFT JOIN departments d ON d.department_id = se.department_id AND d.status = 'active'
                LEFT JOIN programs p ON p.program_id = se.program_id AND p.status = 'active'
                LEFT JOIN classes cl ON cl.class_id = se.class_id AND cl.status = 'Active'
                LEFT JOIN semester sem ON sem.semester_id = se.semester_id AND sem.status = 'active'
                WHERE se.student_id = ? 
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
                promoted_by, remarks,
                promotion_date)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, NOW())
            ");
            $insert->execute([
                $student_id,
                $old_data['faculty_id']    ?? null,
                $old_data['department_id'] ?? null,
                $old_data['program_id']    ?? null,
                $old_data['semester_id']   ?? null,
                $old_data['class_id']      ?? null,
                $faculty_id,
                $new_department_id,
                $new_program_id,
                $new_semester_id,
                $new_class_id,
                $old_data['campus_id']     ?? null,
                $new_campus_id,
                $admin_id,
                $remarks
            ]);

            // Update student_enroll with new data
            $update = $pdo->prepare("
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
            $update->execute([
                $new_campus_id,
                $faculty_id,
                $new_department_id,
                $new_program_id,
                $new_class_id,
                $new_semester_id,
                $student_id
            ]);

            // Update students table with new class info
            $update_student = $pdo->prepare("
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
            $update_student->execute([
                $new_campus_id,
                $faculty_id,
                $new_department_id,
                $new_program_id,
                $new_class_id,
                $new_semester_id,
                $student_id
            ]);

            // Remove old subject links
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

/* ✅ Campuses (only those belonging to this faculty) */
$campuses = $faculty_campuses;

/* ✅ Semesters (ACTIVE ONLY) */
$semesters = $pdo->query("
    SELECT semester_id, semester_name 
    FROM semester 
    WHERE status = 'active'
    ORDER BY semester_id ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* ✅ Promotion History (restricted to this faculty) */
$history = $pdo->prepare("
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
        sem.semester_name AS new_sem,
        oc.campus_name AS old_campus,
        nc.campus_name AS new_campus
    FROM promotion_history ph
    JOIN students s ON s.student_id = ph.student_id
    LEFT JOIN semester sem ON sem.semester_id = ph.new_semester_id AND sem.status = 'active'
    LEFT JOIN campus oc ON oc.campus_id = ph.old_campus_id AND oc.status = 'active'
    LEFT JOIN campus nc ON nc.campus_id = ph.new_campus_id AND nc.status = 'active'
    WHERE s.status = 'active'
    AND ph.new_faculty_id = ? 
    ORDER BY ph.promotion_date DESC
    LIMIT 50
");
$history->execute([$faculty_id]);
$history = $history->fetchAll(PDO::FETCH_ASSOC);

include('../includes/header.php');
?>
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

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Segoe UI', 'Poppins', sans-serif;
}

body {
    background: var(--bg);
    color: var(--dark);
    min-height: 100vh;
}

.main-content {
    padding: 20px;
    margin-top: 90px;
    margin-left: 250px;
    transition: all .3s ease;
}

.sidebar.collapsed ~ .main-content {
    margin-left: 70px;
}

/* Page Header */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding: 20px;
    background: var(--white);
    border-radius: 12px;
    box-shadow: 0 4px 15px var(--shadow);
    border-left: 4px solid var(--green);
    flex-wrap: wrap;
    gap: 15px;
}

.page-header h1 {
    color: var(--blue);
    font-size: 24px;
    margin: 0;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.page-header h1 i {
    color: var(--green);
    background: rgba(0,132,61,0.1);
    padding: 10px;
    border-radius: 10px;
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

.campus-count {
    background: rgba(255, 184, 28, 0.1);
    color: var(--gold);
    padding: 6px 15px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 600;
    white-space: nowrap;
}

/* Filter Box */
.filter-box {
    background: var(--white);
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 4px 15px var(--shadow);
    margin-bottom: 20px;
    border-top: 4px solid var(--blue);
}

.grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
}

fieldset {
    border: 2px solid var(--border);
    border-radius: 12px;
    padding: 20px;
    background: #f9f9f9;
}

legend {
    color: var(--blue);
    font-weight: 600;
    font-size: 16px;
    padding: 0 10px;
    background: var(--white);
    border-radius: 20px;
    border: 1px solid var(--border);
}

label {
    font-weight: 600;
    color: var(--blue);
    font-size: 13px;
    margin-bottom: 5px;
    display: block;
}

select, input {
    width: 100%;
    padding: 10px 12px;
    border: 2px solid var(--border);
    border-radius: 8px;
    font-size: 14px;
    background: #f9f9f9;
    transition: 0.2s;
    margin-bottom: 10px;
}

select:focus, input:focus {
    border-color: var(--blue);
    box-shadow: 0 0 0 3px rgba(0,114,206,0.12);
    outline: none;
    background: var(--white);
}

select:disabled {
    background: #e9ecef;
    cursor: not-allowed;
    opacity: 0.7;
}

.btn {
    border: none;
    padding: 12px 20px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: .3s;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-size: 14px;
}

.btn.green {
    background: linear-gradient(135deg, var(--green), var(--light-green));
    color: var(--white);
    width: 100%;
    margin-top: 15px;
}

.btn.green:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,132,61,0.2);
}

/* Table */
.table-wrapper {
    overflow: auto;
    background: var(--white);
    border-radius: 12px;
    box-shadow: 0 4px 15px var(--shadow);
    margin-top: 20px;
    border: 1px solid var(--border);
}

.table-wrapper h3 {
    color: var(--blue);
    margin: 15px 20px;
    font-size: 18px;
    display: flex;
    align-items: center;
    gap: 10px;
}

table {
    width: 100%;
    border-collapse: collapse;
}

thead th {
    position: sticky;
    top: 0;
    background: linear-gradient(135deg, var(--blue), var(--green));
    color: var(--white);
    z-index: 2;
    padding: 14px 16px;
    font-weight: 600;
    font-size: 14px;
}

th, td {
    padding: 12px 14px;
    border-bottom: 1px solid var(--border);
    text-align: left;
    white-space: nowrap;
    font-size: 14px;
}

tr:hover {
    background: #eef8f0;
}

tbody tr:nth-child(even) {
    background: #f9f9f9;
}

/* Checkbox List */
.checkbox-group {
    margin-top: 10px;
}

.checkbox-list {
    max-height: 220px;
    overflow-y: auto;
    border: 2px solid var(--border);
    border-radius: 8px;
    padding: 10px;
    background: var(--white);
    margin-top: 5px;
}

.checkbox-list label {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 8px;
    font-size: 13px;
    cursor: pointer;
    border-bottom: 1px solid #eee;
    font-weight: normal;
    color: var(--dark);
}

.checkbox-list label:hover {
    background: #f0f7ff;
}

.checkbox-list label:last-child {
    border-bottom: none;
}

.checkbox-list input {
    width: 16px;
    height: 16px;
    margin: 0;
}

.checkbox-list small {
    color: #999;
    font-style: italic;
    padding: 10px;
    display: block;
    text-align: center;
}

/* Info Box */
.restricted-info {
    background-color: #fff3cd;
    border: 1px solid #ffeaa7;
    border-radius: 6px;
    padding: 10px 15px;
    margin: 15px 20px;
    color: #856404;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 10px;
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
    z-index: 1000;
    box-shadow: 0 5px 15px rgba(0,0,0,0.2);
    animation: slideIn 0.3s ease-out;
    display: flex;
    align-items: center;
    gap: 12px;
    max-width: 400px;
}

.alert.success {
    background: var(--green);
    border-left: 5px solid #00612c;
}

.alert.error {
    background: var(--red);
    border-left: 5px solid #8e1c1c;
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
    
    .page-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .grid {
        grid-template-columns: 1fr;
    }
    
    .table-wrapper {
        overflow-x: auto;
    }
    
    table {
        min-width: 800px;
    }
}

/* Scrollbar */
.checkbox-list::-webkit-scrollbar,
.table-wrapper::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

.checkbox-list::-webkit-scrollbar-track,
.table-wrapper::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 4px;
}

.checkbox-list::-webkit-scrollbar-thumb,
.table-wrapper::-webkit-scrollbar-thumb {
    background: var(--blue);
    border-radius: 4px;
}

.checkbox-list::-webkit-scrollbar-thumb:hover,
.table-wrapper::-webkit-scrollbar-thumb:hover {
    background: var(--green);
}
</style>

<div class="main-content">
    <div class="page-header">
        <h1>
            <i class="fas fa-arrow-up"></i> Student Promotion
        </h1>
    </div>

    <!-- PROMOTION FORM -->
    <div class="filter-box">
        <form method="POST" id="promotionForm">
            <div class="grid">
                <!-- ========== FROM HIERARCHY ========== -->
                <fieldset>
                    <legend><i class="fas fa-search"></i> From (Current)</legend>
                    
                    <div>
                        <label>Campus</label>
                        <select id="from_campus" onchange="fromCampusChange()" required>
                            <option value="">Select Campus</option>
                            <?php foreach($campuses as $c): ?>
                                <option value="<?= $c['campus_id'] ?>">
                                    <?= htmlspecialchars($c['campus_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Faculty</label>
                        <select id="from_faculty" onchange="fromFacultyChange()" required disabled>
                            <option value="">Select Faculty</option>
                        </select>
                    </div>

                    <div>
                        <label>Department</label>
                        <select id="from_department" onchange="fromDepartmentChange()" required disabled>
                            <option value="">Select Department</option>
                        </select>
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
                            <small>Please select class first.</small>
                        </div>
                    </div>
                </fieldset>

                <!-- ========== TO HIERARCHY ========== -->
                <fieldset>
                    <legend><i class="fas fa-arrow-right"></i> To (Destination)</legend>
                    
                    <div>
                        <label>Campus</label>
                        <select id="to_campus" name="to_campus" onchange="toCampusChange()" required>
                            <option value="">Select Campus</option>
                            <?php foreach($campuses as $c): ?>
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
                        <select id="to_class" name="to_class" required disabled>
                            <option value="">Select Class</option>
                        </select>
                    </div>

                    <div>
                        <label>New Semester</label>
                        <select id="new_semester" name="new_semester_id" onchange="toSemesterChange()" required>
                            <option value="">Select Semester</option>
                            <?php foreach($semesters as $sem): ?>
                                <option value="<?= $sem['semester_id'] ?>">
                                    <?= htmlspecialchars($sem['semester_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="checkbox-group">
                        <label>Select Subjects for New Semester</label>
                        <div class="checkbox-list" id="to_subject_list">
                            <small>Please complete all selections above.</small>
                        </div>
                    </div>

                    <div>
                        <label>Remarks (Optional)</label>
                        <input type="text" name="remarks" placeholder="e.g., Promoted to next semester">
                    </div>

                    <button type="submit" name="promote" class="btn green">
                        <i class="fas fa-arrow-up"></i> Promote Selected Students
                    </button>
                </fieldset>
            </div>
        </form>
    </div>

    <!-- PROMOTION HISTORY -->
    <div class="table-wrapper">
        <h3><i class="fas fa-history"></i> Recent Promotion History</h3>
        <?php if (empty($history)): ?>
            <div class="restricted-info">
                <i class="fas fa-info-circle"></i> No promotion history found for your faculty.
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Student</th>
                        <th>Reg No</th>
                        <th>From Campus</th>
                        <th>To Campus</th>
                        <th>New Semester</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($history as $h): ?>
                    <tr>
                        <td><?= date('d M Y H:i', strtotime($h['promotion_date'])) ?></td>
                        <td><?= htmlspecialchars($h['full_name']) ?></td>
                        <td><?= htmlspecialchars($h['reg_no']) ?></td>
                        <td><?= htmlspecialchars($h['old_campus'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($h['new_campus'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($h['new_sem'] ?? $h['new_semester_id']) ?></td>
                        <td><?= htmlspecialchars($h['remarks'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php if($message): ?>
<div class="alert <?= $type ?>" id="alertMessage">
    <?php if($type == 'success'): ?>
        <i class="fas fa-check-circle"></i>
    <?php elseif($type == 'error'): ?>
        <i class="fas fa-exclamation-circle"></i>
    <?php endif; ?>
    <strong><?= $message ?></strong>
</div>
<script>
    setTimeout(() => {
        const alert = document.getElementById('alertMessage');
        if (alert) {
            alert.style.opacity = '0';
            alert.style.transition = 'opacity 0.5s';
            setTimeout(() => alert.remove(), 500);
        }
    }, 5000);
</script>
<?php endif; ?>

<script>
// ===========================================
// FROM SECTION FUNCTIONS
// ===========================================

function fromCampusChange() {
    const campusId = document.getElementById('from_campus').value;
    const facultySelect = document.getElementById('from_faculty');
    
    if (!campusId) {
        resetFromHierarchy();
        return;
    }
    
    resetFromDropdowns(['department', 'program', 'class', 'student_list']);
    facultySelect.innerHTML = '<option value="">Loading...</option>';
    facultySelect.disabled = false;
    
    fetch(`?ajax=get_faculties_by_campus&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            facultySelect.innerHTML = '<option value="">Select Faculty</option>';
            
            if (data.status === 'error') {
                alert(data.message);
                facultySelect.innerHTML = '<option value="">Access denied</option>';
                facultySelect.disabled = true;
                return;
            }
            
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
    
    fetch(`?ajax=get_departments_by_faculty&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            deptSelect.innerHTML = '<option value="">Select Department</option>';
            
            if (data.status === 'error') {
                alert(data.message);
                deptSelect.innerHTML = '<option value="">Access denied</option>';
                deptSelect.disabled = true;
                return;
            }
            
            if (data.status === 'success' && data.departments.length > 0) {
                data.departments.forEach(dept => {
                    const option = document.createElement('option');
                    option.value = dept.department_id;
                    option.textContent = dept.display_name || dept.department_name;
                    deptSelect.appendChild(option);
                });
                deptSelect.disabled = false;
            } else {
                deptSelect.innerHTML = '<option value="">No active departments found</option>';
                deptSelect.disabled = true;
            }
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
    
    fetch(`?ajax=get_programs_by_department&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            programSelect.innerHTML = '<option value="">Select Program</option>';
            
            if (data.status === 'error') {
                alert(data.message);
                programSelect.innerHTML = '<option value="">Access denied</option>';
                programSelect.disabled = true;
                return;
            }
            
            if (data.status === 'success' && data.programs.length > 0) {
                data.programs.forEach(program => {
                    const option = document.createElement('option');
                    option.value = program.program_id;
                    option.textContent = program.program_name;
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
    
    fetch(`?ajax=get_classes_by_program&program_id=${programId}&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            classSelect.innerHTML = '<option value="">Select Class</option>';
            
            if (data.status === 'error') {
                alert(data.message);
                classSelect.innerHTML = '<option value="">Access denied</option>';
                classSelect.disabled = true;
                return;
            }
            
            if (data.status === 'success' && data.classes.length > 0) {
                data.classes.forEach(cls => {
                    const option = document.createElement('option');
                    option.value = cls.class_id;
                    option.textContent = cls.display_name || cls.class_name;
                    classSelect.appendChild(option);
                });
                classSelect.disabled = false;
            } else {
                classSelect.innerHTML = '<option value="">No active classes found</option>';
                classSelect.disabled = true;
            }
        });
    
    document.getElementById('from_student_list').innerHTML = '<small>Please select class first.</small>';
}

function fromClassChange() {
    const classId = document.getElementById('from_class').value;
    const campusId = document.getElementById('from_campus').value;
    const studentList = document.getElementById('from_student_list');
    
    if (!classId || !campusId) {
        studentList.innerHTML = '<small>Please select class first.</small>';
        return;
    }
    
    studentList.innerHTML = '<small>Loading active students...</small>';
    
    fetch(`?ajax=get_students_by_class&class_id=${classId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'error') {
                alert(data.message);
                studentList.innerHTML = '<small>Access denied</small>';
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
                studentList.innerHTML = '<small>No active students found for this class.</small>';
            }
        });
}

// ===========================================
// TO SECTION FUNCTIONS
// ===========================================

function toCampusChange() {
    const campusId = document.getElementById('to_campus').value;
    const facultySelect = document.getElementById('to_faculty');
    
    if (!campusId) {
        resetToHierarchy();
        return;
    }
    
    resetToDropdowns(['department', 'program', 'class', 'subject_list']);
    facultySelect.innerHTML = '<option value="">Loading...</option>';
    facultySelect.disabled = false;
    
    fetch(`?ajax=get_faculties_by_campus&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            facultySelect.innerHTML = '<option value="">Select Faculty</option>';
            
            if (data.status === 'error') {
                alert(data.message);
                facultySelect.innerHTML = '<option value="">Access denied</option>';
                facultySelect.disabled = true;
                return;
            }
            
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
    
    fetch(`?ajax=get_departments_by_faculty&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            deptSelect.innerHTML = '<option value="">Select Department</option>';
            
            if (data.status === 'error') {
                alert(data.message);
                deptSelect.innerHTML = '<option value="">Access denied</option>';
                deptSelect.disabled = true;
                return;
            }
            
            if (data.status === 'success' && data.departments.length > 0) {
                data.departments.forEach(dept => {
                    const option = document.createElement('option');
                    option.value = dept.department_id;
                    option.textContent = dept.display_name || dept.department_name;
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
    
    fetch(`?ajax=get_programs_by_department&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            programSelect.innerHTML = '<option value="">Select Program</option>';
            
            if (data.status === 'error') {
                alert(data.message);
                programSelect.innerHTML = '<option value="">Access denied</option>';
                programSelect.disabled = true;
                return;
            }
            
            if (data.status === 'success' && data.programs.length > 0) {
                data.programs.forEach(program => {
                    const option = document.createElement('option');
                    option.value = program.program_id;
                    option.textContent = program.program_name;
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
    
    fetch(`?ajax=get_classes_by_program&program_id=${programId}&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            classSelect.innerHTML = '<option value="">Select Class</option>';
            
            if (data.status === 'error') {
                alert(data.message);
                classSelect.innerHTML = '<option value="">Access denied</option>';
                classSelect.disabled = true;
                return;
            }
            
            if (data.status === 'success' && data.classes.length > 0) {
                data.classes.forEach(cls => {
                    const option = document.createElement('option');
                    option.value = cls.class_id;
                    option.textContent = cls.display_name || cls.class_name;
                    classSelect.appendChild(option);
                });
                classSelect.disabled = false;
            } else {
                classSelect.innerHTML = '<option value="">No active classes found</option>';
                classSelect.disabled = true;
            }
        });
    
    document.getElementById('to_subject_list').innerHTML = '<small>Please complete all selections above.</small>';
}

function toSemesterChange() {
    const semesterId = document.getElementById('new_semester').value;
    const programId = document.getElementById('to_program').value;
    const deptId = document.getElementById('to_department').value;
    const facultyId = document.getElementById('to_faculty').value;
    const campusId = document.getElementById('to_campus').value;
    const subjectList = document.getElementById('to_subject_list');
    
    if (!semesterId || !programId || !deptId || !facultyId || !campusId) {
        subjectList.innerHTML = '<small>Please complete all hierarchy selections first.</small>';
        return;
    }
    
    subjectList.innerHTML = '<small>Loading active subjects...</small>';
    
    fetch(`?ajax=get_subjects_by_semester&semester_id=${semesterId}&program_id=${programId}&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'error') {
                alert(data.message);
                subjectList.innerHTML = '<small>Access denied</small>';
                return;
            }
            
            subjectList.innerHTML = '';
            
            if (data.status === 'success' && data.subjects.length > 0) {
                data.subjects.forEach(subject => {
                    const label = document.createElement('label');
                    label.innerHTML = `
                        <input type="checkbox" name="subject_ids[]" value="${subject.subject_id}">
                        ${subject.subject_name} (${subject.subject_code})
                    `;
                    subjectList.appendChild(label);
                });
            } else {
                subjectList.innerHTML = '<small>No active subjects found for this semester and program.</small>';
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
                element.innerHTML = '<small>Please select class first.</small>';
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
                element.innerHTML = '<small>Please complete all selections above.</small>';
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