<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Access Control - Super Admin, Campus Admin, or Department Admin
$role = strtolower($_SESSION['user']['role'] ?? '');
if (!in_array($role, ['super_admin', 'campus_admin', 'department_admin'])) {
    header("Location: ../login.php");
    exit;
}

$user = $_SESSION['user'] ?? [];

// Get user's linked IDs based on role
$user_campus_id = null;
$user_department_id = null;
$user_faculty_id = null;

if ($role === 'campus_admin' && !empty($user['linked_id']) && $user['linked_table'] === 'campus') {
    $user_campus_id = $user['linked_id'];
} elseif ($role === 'department_admin' && !empty($user['linked_id']) && $user['linked_table'] === 'department') {
    $user_department_id = $user['linked_id'];
    
    // Get department details including campus and faculty
    $dept_stmt = $pdo->prepare("
        SELECT campus_id, faculty_id, department_name 
        FROM departments 
        WHERE department_id = ?
    ");
    $dept_stmt->execute([$user_department_id]);
    $dept_info = $dept_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($dept_info) {
        $user_campus_id = $dept_info['campus_id'];
        $user_faculty_id = $dept_info['faculty_id'];
    }
}

// Verify user has proper linked data
if (!$user_campus_id && $role !== 'super_admin') {
    header("Location: ../login.php");
    exit;
}

// Set the working IDs based on role
$campus_id = $user_campus_id;
$department_id = $user_department_id; // Will be null for super_admin and campus_admin
$faculty_id = $user_faculty_id; // Will be null for super_admin and campus_admin

date_default_timezone_set('Africa/Nairobi');
$message = "";
$type = "";

/* =============== ACTIVE TERM =============== */
$term = $pdo->query("SELECT academic_term_id FROM academic_term WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$academic_term_id = $term['academic_term_id'] ?? null;

/* =============== AJAX HANDLERS FOR HIERARCHY =============== */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    // GET FACULTIES BY CAMPUS
    if ($_GET['ajax'] == 'get_faculties') {
        $campusId = $_GET['campus_id'] ?? 0;
        
        if (!$campusId) {
            echo json_encode(['status' => 'error', 'message' => 'Campus ID required']);
            exit;
        }
        
        // Check permission based on role
        if ($role === 'campus_admin' && $campusId != $user_campus_id) {
            echo json_encode(['status' => 'error', 'message' => 'You do not have permission to access other campuses']);
            exit;
        }
        
        if ($role === 'department_admin' && $faculty_id) {
            // Department admin sees only their faculty
            $stmt = $pdo->prepare("
                SELECT faculty_id, faculty_name 
                FROM faculties 
                WHERE faculty_id = ? AND status = 'active'
            ");
            $stmt->execute([$faculty_id]);
        } else {
            $stmt = $pdo->prepare("
                SELECT DISTINCT f.faculty_id, f.faculty_name 
                FROM faculties f
                JOIN faculty_campus fc ON f.faculty_id = fc.faculty_id
                WHERE fc.campus_id = ?
                AND f.status = 'active'
                ORDER BY f.faculty_name
            ");
            $stmt->execute([$campusId]);
        }
        
        $faculties = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($faculties) {
            echo json_encode(['status' => 'success', 'faculties' => $faculties]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No active faculties found']);
        }
        exit;
    }
    
    // GET DEPARTMENTS BY FACULTY & CAMPUS
    if ($_GET['ajax'] == 'get_departments') {
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        if (!$faculty_id || !$campus_id) {
            echo json_encode(['status' => 'error', 'message' => 'Faculty ID and Campus ID required']);
            exit;
        }
        
        if ($role === 'department_admin' && $user_department_id) {
            // Department admin sees only their department
            $stmt = $pdo->prepare("
                SELECT department_id, department_name 
                FROM departments 
                WHERE department_id = ? AND faculty_id = ? AND campus_id = ? AND status = 'active'
            ");
            $stmt->execute([$user_department_id, $faculty_id, $campus_id]);
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
        
        if ($departments) {
            echo json_encode(['status' => 'success', 'departments' => $departments]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No active departments found']);
        }
        exit;
    }
    
    // GET PROGRAMS BY DEPARTMENT, FACULTY & CAMPUS
    if ($_GET['ajax'] == 'get_programs') {
        $department_id = $_GET['department_id'] ?? 0;
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        if (!$department_id || !$faculty_id || !$campus_id) {
            echo json_encode(['status' => 'error', 'message' => 'Department, Faculty and Campus ID required']);
            exit;
        }
        
        // Check permission for department admin
        if ($role === 'department_admin' && $department_id != $user_department_id) {
            echo json_encode(['status' => 'error', 'message' => 'You can only access your own department']);
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
        
        if ($programs) {
            echo json_encode(['status' => 'success', 'programs' => $programs]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No active programs found for this department']);
        }
        exit;
    }
    
    // GET CLASSES BY PROGRAM, DEPARTMENT, FACULTY & CAMPUS
    if ($_GET['ajax'] == 'get_classes') {
        $program_id = $_GET['program_id'] ?? 0;
        $department_id = $_GET['department_id'] ?? 0;
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        if (!$program_id || !$department_id || !$faculty_id || !$campus_id) {
            echo json_encode(['status' => 'error', 'message' => 'All IDs required']);
            exit;
        }
        
        // Check permission for department admin
        if ($role === 'department_admin' && $department_id != $user_department_id) {
            echo json_encode(['status' => 'error', 'message' => 'You can only access your own department']);
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
        
        if ($classes) {
            echo json_encode(['status' => 'success', 'classes' => $classes]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No active classes found for this program']);
        }
        exit;
    }
    
    // GET SEMESTERS
    if ($_GET['ajax'] == 'get_semesters') {
        try {
            // Check if semester table exists
            $stmt = $pdo->query("SHOW TABLES LIKE 'semester'");
            if ($stmt->fetch()) {
                $semesters = $pdo->query("SELECT semester_id, semester_name FROM semester WHERE status = 'active' ORDER BY semester_name")->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // Fallback to predefined semesters
                $semesters = [
                    ['semester_id' => 1, 'semester_name' => 'Semester 1'],
                    ['semester_id' => 2, 'semester_name' => 'Semester 2'],
                    ['semester_id' => 3, 'semester_name' => 'Semester 3'],
                    ['semester_id' => 4, 'semester_name' => 'Semester 4'],
                    ['semester_id' => 5, 'semester_name' => 'Semester 5'],
                    ['semester_id' => 6, 'semester_name' => 'Semester 6'],
                    ['semester_id' => 7, 'semester_name' => 'Semester 7'],
                    ['semester_id' => 8, 'semester_name' => 'Semester 8'],
                ];
            }
            
            echo json_encode(['status' => 'success', 'semesters' => $semesters]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
}

/* =============== BULK ENROLL ACTION =============== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enroll_selected'])) {
    try {
        $pdo->beginTransaction();

        $program_id  = $_POST['program_id'];
        $semester_id = $_POST['semester_id'];
        $class_id    = $_POST['class_id'];
        $faculty_id  = $_POST['faculty_id'];
        $department_id = $_POST['department_id'];
        $campus_id   = $_POST['campus_id'];
        $students    = $_POST['student_ids'] ?? [];

        // Role-based validation
        if ($role === 'department_admin') {
            if ($department_id != $user_department_id) {
                throw new Exception("You can only enroll students in your own department!");
            }
            if ($faculty_id != $user_faculty_id) {
                throw new Exception("Invalid faculty selection!");
            }
            if ($campus_id != $user_campus_id) {
                throw new Exception("Invalid campus selection!");
            }
        } elseif ($role === 'campus_admin') {
            if ($campus_id != $user_campus_id) {
                throw new Exception("You can only enroll students in your own campus!");
            }
        }

        if (empty($students)) throw new Exception("No students selected!");
        if (!$academic_term_id) throw new Exception("No active academic term found!");

        // ✅ Get all subjects under this program + semester
        $subjects = $pdo->prepare("
            SELECT subject_id 
            FROM subject 
            WHERE program_id = ? 
            AND semester_id = ?
            AND campus_id = ?
            AND faculty_id = ?
            AND department_id = ?
            AND status = 'active'
        ");
        $subjects->execute([$program_id, $semester_id, $campus_id, $faculty_id, $department_id]);
        $subjectList = $subjects->fetchAll(PDO::FETCH_COLUMN);

        if (empty($subjectList)) {
            throw new Exception("No active subjects found for the selected program and semester!");
        }

        // ✅ Prepare insert statement
        $stmt = $pdo->prepare("
            INSERT INTO student_enroll 
            (student_id, program_id, semester_id, class_id, subject_id, 
             campus_id, faculty_id, department_id, academic_term_id, status, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,'active',NOW())
        ");

        $enrolled_count = 0;
        $skipped_count = 0;
        
        foreach ($students as $sid) {
            foreach ($subjectList as $subj_id) {
                // Avoid duplicate enrollment for same subject in same term
                $check = $pdo->prepare("
                    SELECT enroll_id FROM student_enroll 
                    WHERE student_id = ? 
                    AND academic_term_id = ? 
                    AND subject_id = ?
                    AND campus_id = ?
                    AND faculty_id = ?
                    AND department_id = ?
                    AND program_id = ?
                    AND class_id = ?
                ");
                $check->execute([$sid, $academic_term_id, $subj_id, $campus_id, $faculty_id, $department_id, $program_id, $class_id]);
                
                if ($check->rowCount() == 0) {
                    $stmt->execute([$sid, $program_id, $semester_id, $class_id, $subj_id, 
                                   $campus_id, $faculty_id, $department_id, $academic_term_id]);
                    $enrolled_count++;
                } else {
                    $skipped_count++;
                }
            }
        }

        $pdo->commit();
        
        if ($enrolled_count > 0) {
            $message = "✅ $enrolled_count student enrollments created successfully! ";
            if ($skipped_count > 0) {
                $message .= "($skipped_count already enrolled)";
            }
            $type = "success";
        } else {
            $message = "⚠️ All selected students are already enrolled in these subjects!";
            $type = "warning";
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "❌ Error: " . $e->getMessage();
        $type = "error";
    }
}

/* =============== FETCH INITIAL DATA =============== */
// ✅ CAMPUS SELECTION based on role
if ($role === 'super_admin') {
    $campuses = $pdo->query("
        SELECT campus_id, campus_name
        FROM campus
        WHERE status = 'active'
        ORDER BY campus_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $campuses = $pdo->prepare("
        SELECT campus_id, campus_name
        FROM campus
        WHERE campus_id = ? AND status = 'active'
    ");
    $campuses->execute([$campus_id]);
    $campuses = $campuses->fetchAll(PDO::FETCH_ASSOC);
    $campus_name = $campuses[0]['campus_name'] ?? 'Your Campus';
}

// ✅ Get current selections from GET or POST
$selectedCampus = $_POST['campus_id'] ?? $_GET['campus_id'] ?? (($role !== 'super_admin') ? $campus_id : null);
$selectedFaculty = $_POST['faculty_id'] ?? $_GET['faculty_id'] ?? ($role === 'department_admin' ? $faculty_id : null);
$selectedDepartment = $_POST['department_id'] ?? $_GET['department_id'] ?? ($role === 'department_admin' ? $department_id : null);
$selectedProgram = $_POST['program_id'] ?? $_GET['program_id'] ?? null;
$selectedClass = $_POST['class_id'] ?? $_GET['class_id'] ?? null;
$selectedSemester = $_POST['semester_id'] ?? $_GET['semester_id'] ?? null;

// ✅ Get filter/search parameters
$searchQuery = $_GET['search'] ?? '';
$sortBy = $_GET['sort'] ?? 'name';
$sortOrder = $_GET['order'] ?? 'asc';

// ✅ Check if semester table exists
$semesterExists = $pdo->query("SHOW TABLES LIKE 'semester'")->fetch();

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Bulk Student Enrollment | Hormuud University</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="../images.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ===========================================
   HORMUUD UNIVERSITY COLORS
   Green: #00843D
   Blue: #0072CE
=========================================== */
:root {
    --hu-green: #00843D;
    --hu-blue: #0072CE;
    --hu-light-green: #00A651;
    --hu-light-blue: #4A9FE1;
    --hu-dark-green: #00612c;
    --hu-dark-blue: #0056b3;
    --hu-red: #C62828;
    --hu-orange: #FF9800;
    --hu-gray: #F5F9F7;
    --hu-white: #FFFFFF;
    --hu-border: #E0E0E0;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Poppins', 'Segoe UI', sans-serif;
    background: var(--hu-gray);
    color: #333;
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

/* ========== PAGE HEADER ========== */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    padding: 20px 25px;
    background: var(--hu-white);
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,114,206,0.08);
    border-left: 5px solid var(--hu-green);
}

.page-header h1 {
    color: var(--hu-blue);
    font-size: 26px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 0;
}

.page-header h1 i {
    color: var(--hu-green);
    background: rgba(0,132,61,0.1);
    padding: 12px;
    border-radius: 12px;
    font-size: 24px;
}

.role-badge {
    background: var(--hu-green);
    color: white;
    padding: 6px 15px;
    border-radius: 30px;
    font-size: 13px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    letter-spacing: 0.3px;
    box-shadow: 0 2px 8px rgba(0,132,61,0.2);
}

.role-badge i {
    font-size: 14px;
}

/* ========== INFO BOX ========== */
.info-box {
    background: linear-gradient(135deg, #f0f9ff, #e6f3ff);
    border: 2px solid var(--hu-blue);
    border-radius: 16px;
    padding: 20px 25px;
    margin-bottom: 30px;
    display: flex;
    align-items: center;
    gap: 20px;
    box-shadow: 0 6px 15px rgba(0,114,206,0.1);
}

.info-box i {
    color: var(--hu-blue);
    font-size: 28px;
    background: white;
    width: 60px;
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    box-shadow: 0 4px 10px rgba(0,114,206,0.15);
}

.info-box-content {
    flex: 1;
}

.info-box-title {
    font-weight: 700;
    color: var(--hu-blue);
    margin-bottom: 5px;
    font-size: 18px;
    letter-spacing: 0.3px;
}

.info-box-text {
    color: #555;
    font-size: 15px;
    line-height: 1.5;
}

.info-box-text strong {
    color: var(--hu-green);
    font-weight: 700;
}

/* ========== FILTER BOX ========== */
.filter-box {
    background: var(--hu-white);
    padding: 25px;
    border-radius: 16px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.05);
    margin-bottom: 25px;
    border-top: 5px solid var(--hu-blue);
}

.filter-header {
    margin-bottom: 20px;
}

.filter-header h3 {
    color: var(--hu-blue);
    font-size: 18px;
    font-weight: 600;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.filter-header h3 i {
    color: var(--hu-green);
}

.grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    gap: 20px;
}

label {
    font-weight: 600;
    color: var(--hu-blue);
    font-size: 14px;
    margin-bottom: 8px;
    display: block;
    letter-spacing: 0.3px;
}

.required::after {
    content: " *";
    color: var(--hu-red);
    font-weight: bold;
}

select, input[type="text"] {
    width: 100%;
    padding: 12px 15px;
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    font-size: 14px;
    background: #fafafa;
    transition: all 0.3s ease;
    font-family: 'Poppins', sans-serif;
}

select:focus, input[type="text"]:focus {
    border-color: var(--hu-blue);
    box-shadow: 0 0 0 4px rgba(0,114,206,0.1);
    outline: none;
    background: var(--hu-white);
}

select:disabled {
    background: #e9ecef;
    cursor: not-allowed;
    opacity: 0.7;
    border-color: #ccc;
}

.btn {
    border: none;
    padding: 12px 22px;
    border-radius: 10px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    font-family: 'Poppins', sans-serif;
    letter-spacing: 0.3px;
}

.btn.blue {
    background: var(--hu-blue);
    color: white;
    box-shadow: 0 4px 12px rgba(0,114,206,0.25);
}

.btn.blue:hover {
    background: var(--hu-dark-blue);
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0,114,206,0.35);
}

.btn.green {
    background: var(--hu-green);
    color: white;
    box-shadow: 0 4px 12px rgba(0,132,61,0.25);
}

.btn.green:hover {
    background: var(--hu-dark-green);
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0,132,61,0.35);
}

.btn.orange {
    background: var(--hu-orange);
    color: white;
    box-shadow: 0 4px 12px rgba(255,152,0,0.25);
}

.btn.orange:hover {
    background: #e68900;
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(255,152,0,0.35);
}

.btn:disabled {
    background: #ccc;
    cursor: not-allowed;
    box-shadow: none;
    transform: none;
}

/* ========== TABLE ========== */
.table-wrapper {
    background: var(--hu-white);
    border-radius: 16px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.05);
    margin-top: 25px;
    overflow: hidden;
}

.table-header {
    padding: 20px 25px;
    border-bottom: 2px solid #f0f0f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
}

.table-header h3 {
    color: var(--hu-blue);
    font-size: 18px;
    font-weight: 600;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.table-header h3 i {
    color: var(--hu-green);
}

.results-count {
    color: #666;
    font-size: 14px;
    background: #f0f7ff;
    padding: 6px 15px;
    border-radius: 30px;
}

.table-responsive {
    width: 100%;
    overflow-x: auto;
    padding: 0 25px 25px;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
    min-width: 800px;
}

.data-table th {
    background: linear-gradient(135deg, var(--hu-blue), var(--hu-light-blue));
    color: white;
    font-weight: 600;
    padding: 15px 15px;
    text-align: left;
    font-size: 14px;
    letter-spacing: 0.3px;
    white-space: nowrap;
}

.data-table td {
    padding: 14px 15px;
    border-bottom: 1px solid #eee;
    vertical-align: middle;
}

.data-table tbody tr:hover {
    background: #f0f9ff;
}

.data-table tbody tr:nth-child(even) {
    background: #f8fdff;
}

/* Checkbox */
input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
    accent-color: var(--hu-green);
}

/* ========== SEARCH AND FILTER BAR ========== */
.search-filter-bar {
    background: var(--hu-white);
    padding: 20px 25px;
    border-radius: 16px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    margin-bottom: 20px;
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    align-items: center;
    border: 1px solid #e0e0e0;
}

.search-box {
    flex: 1;
    min-width: 350px;
}

.search-box .search-input {
    position: relative;
}

.search-box input {
    padding-left: 45px;
    height: 48px;
    border-radius: 30px;
}

.search-box i {
    position: absolute;
    left: 18px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--hu-blue);
    font-size: 18px;
}

.sort-options {
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
}

.sort-btn {
    padding: 10px 20px;
    background: #f0f7ff;
    border: 2px solid #e0f0ff;
    border-radius: 30px;
    color: var(--hu-blue);
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.3s;
    display: flex;
    align-items: center;
    gap: 8px;
}

.sort-btn:hover {
    background: #e0f0ff;
    border-color: var(--hu-blue);
}

.sort-btn.active {
    background: var(--hu-blue);
    color: white;
    border-color: var(--hu-blue);
}

.sort-btn .arrow {
    font-size: 12px;
}

/* ========== SELECTION SUMMARY ========== */
.selection-summary {
    background: linear-gradient(135deg, #f0f9ff, #e6f3ff);
    padding: 20px 25px;
    border-radius: 16px;
    margin-bottom: 25px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 20px;
    border: 2px solid var(--hu-blue);
}

.summary-item {
    display: flex;
    align-items: center;
    gap: 12px;
    background: white;
    padding: 8px 18px;
    border-radius: 40px;
    box-shadow: 0 2px 8px rgba(0,114,206,0.1);
}

.summary-label {
    font-weight: 600;
    color: #555;
    font-size: 13px;
}

.summary-value {
    font-weight: 700;
    color: var(--hu-blue);
    font-size: 14px;
}

/* ========== EMPTY STATE ========== */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #666;
}

.empty-state i {
    font-size: 70px;
    color: #ddd;
    margin-bottom: 25px;
    display: block;
}

.empty-state h3 {
    color: #777;
    margin-bottom: 15px;
    font-size: 22px;
    font-weight: 600;
}

.empty-state p {
    color: #999;
    font-size: 15px;
    max-width: 500px;
    margin: 0 auto;
    line-height: 1.6;
}

.empty-state a {
    color: var(--hu-blue);
    text-decoration: none;
    font-weight: 600;
}

.empty-state a:hover {
    text-decoration: underline;
}

/* ========== ALERT ========== */
.alert {
    position: fixed;
    top: 100px;
    right: 30px;
    padding: 16px 25px;
    border-radius: 12px;
    color: white;
    font-weight: 600;
    z-index: 9999;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    animation: slideIn 0.4s ease-out;
    display: flex;
    align-items: center;
    gap: 12px;
    max-width: 450px;
    border-left: 5px solid;
}

.alert.success {
    background: var(--hu-green);
    border-left-color: #00612c;
}

.alert.error {
    background: var(--hu-red);
    border-left-color: #8e1c1c;
}

.alert.warning {
    background: var(--hu-orange);
    border-left-color: #cc7a00;
}

@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

/* ========== PAGINATION ========== */
.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 15px;
    margin-top: 25px;
    padding: 15px 25px;
    border-top: 1px solid #eee;
}

.pagination button {
    padding: 10px 22px;
    background: #f0f7ff;
    border: 2px solid #e0f0ff;
    border-radius: 30px;
    color: var(--hu-blue);
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    font-size: 14px;
}

.pagination button:hover:not(:disabled) {
    background: #e0f0ff;
    border-color: var(--hu-blue);
}

.pagination button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.pagination .page-info {
    color: #666;
    font-size: 14px;
    font-weight: 500;
}

/* ========== LOADING ========== */
.loading {
    display: inline-block;
    width: 24px;
    height: 24px;
    border: 3px solid #f3f3f3;
    border-top: 3px solid var(--hu-blue);
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* ========== TFOOT ========== */
tfoot {
    background: #f0f7ff;
}

tfoot td {
    padding: 20px 15px !important;
}

#selectedCount {
    font-weight: 700;
    color: var(--hu-green);
    font-size: 18px;
    margin-right: 5px;
}

/* ========== RESPONSIVE ========== */
@media (max-width: 1024px) {
    .main-content {
        margin-left: 240px;
    }
    body.sidebar-collapsed .main-content {
        margin-left: 60px;
    }
    .grid {
        grid-template-columns: repeat(2, 1fr);
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
        gap: 15px;
    }
    .page-header h1 {
        font-size: 22px;
    }
    .role-badge {
        align-self: flex-start;
    }
    .grid {
        grid-template-columns: 1fr;
    }
    .btn {
        width: 100%;
    }
    .search-filter-bar {
        flex-direction: column;
        align-items: stretch;
    }
    .search-box {
        min-width: 100%;
    }
    .sort-options {
        justify-content: center;
    }
    .selection-summary {
        flex-direction: column;
        align-items: flex-start;
    }
    .summary-item {
        width: 100%;
    }
}

@media (max-width: 576px) {
    .table-responsive {
        padding: 0 15px 15px;
    }
    .data-table {
        min-width: 700px;
    }
    .alert {
        left: 15px;
        right: 15px;
        max-width: none;
    }
    .pagination {
        flex-direction: column;
        gap: 10px;
    }
}

/* Scrollbar Styling */
.table-responsive::-webkit-scrollbar {
    height: 8px;
}

.table-responsive::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 4px;
}

.table-responsive::-webkit-scrollbar-thumb {
    background: var(--hu-blue);
    border-radius: 4px;
}

.table-responsive::-webkit-scrollbar-thumb:hover {
    background: var(--hu-dark-blue);
}
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
    <div class="page-header">
        <h1>
            <i class="fas fa-user-graduate"></i> 
            Student Enrollment 
        </h1>
        
    </div>


    <!-- HIERARCHICAL SELECTION FORM -->
    <div class="filter-box">
        <div class="filter-header">
            <h3><i class="fas fa-filter"></i> Select Program, Class, and Semester</h3>
        </div>
        <form method="GET" id="hierarchyForm">
            <div class="grid">
                <!-- CAMPUS -->
                <?php if ($role === 'super_admin'): ?>
                <div>
                    <label for="campus_id" class="required">Campus</label>
                    <select name="campus_id" id="campus_id" required onchange="onCampusChange()">
                        <option value="">Select Campus</option>
                        <?php foreach($campuses as $c): ?>
                        <option value="<?= $c['campus_id'] ?>" 
                            <?= ($selectedCampus == $c['campus_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['campus_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                    <input type="hidden" name="campus_id" id="campus_id" value="<?= $selectedCampus ?>">
                <?php endif; ?>

                <!-- FACULTY -->
                <div>
                    <label for="faculty_id" class="required">Faculty</label>
                    <select name="faculty_id" id="faculty_id" required 
                            onchange="onFacultyChange()"
                            <?= ($role === 'department_admin' || ($role !== 'super_admin' && empty($selectedCampus))) ? 'disabled' : '' ?>>
                        <option value="">Select Faculty</option>
                        <?php 
                        if ($role === 'department_admin' && !empty($faculty_id)) {
                            // Department admin sees only their faculty
                            $faculty_stmt = $pdo->prepare("SELECT faculty_id, faculty_name FROM faculties WHERE faculty_id = ? AND status = 'active'");
                            $faculty_stmt->execute([$faculty_id]);
                            $faculties = $faculty_stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            foreach($faculties as $f): ?>
                                <option value="<?= $f['faculty_id'] ?>" <?= ($selectedFaculty == $f['faculty_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($f['faculty_name']) ?>
                                </option>
                            <?php endforeach;
                        } elseif ($role === 'super_admin' && !empty($selectedCampus)) {
                            // Super admin sees all faculties based on selected campus
                            $faculties = $pdo->prepare("
                                SELECT DISTINCT f.faculty_id, f.faculty_name 
                                FROM faculties f
                                JOIN faculty_campus fc ON f.faculty_id = fc.faculty_id
                                WHERE fc.campus_id = ?
                                AND f.status = 'active'
                                ORDER BY f.faculty_name
                            ");
                            $faculties->execute([$selectedCampus]);
                            $faculties = $faculties->fetchAll(PDO::FETCH_ASSOC);
                            
                            foreach($faculties as $f): ?>
                                <option value="<?= $f['faculty_id'] ?>" 
                                    <?= ($selectedFaculty == $f['faculty_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($f['faculty_name']) ?>
                                </option>
                            <?php endforeach;
                        } elseif ($role === 'campus_admin' && !empty($campus_id)) {
                            // Campus admin sees all faculties in their campus
                            $faculties = $pdo->prepare("
                                SELECT DISTINCT f.faculty_id, f.faculty_name 
                                FROM faculties f
                                JOIN faculty_campus fc ON f.faculty_id = fc.faculty_id
                                WHERE fc.campus_id = ?
                                AND f.status = 'active'
                                ORDER BY f.faculty_name
                            ");
                            $faculties->execute([$campus_id]);
                            $faculties = $faculties->fetchAll(PDO::FETCH_ASSOC);
                            
                            foreach($faculties as $f): ?>
                                <option value="<?= $f['faculty_id'] ?>" 
                                    <?= ($selectedFaculty == $f['faculty_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($f['faculty_name']) ?>
                                </option>
                            <?php endforeach;
                        }
                        ?>
                    </select>
                    <?php if ($role === 'department_admin' && !empty($faculty_id)): ?>
                        <input type="hidden" name="faculty_id" value="<?= $faculty_id ?>">
                    <?php endif; ?>
                </div>

                <!-- DEPARTMENT -->
                <div>
                    <label for="department_id" class="required">Department</label>
                    <select name="department_id" id="department_id" required 
                            onchange="onDepartmentChange()" 
                            <?= ($role === 'department_admin' || empty($selectedFaculty)) ? 'disabled' : '' ?>>
                        <option value="">Select Department</option>
                        <?php 
                        if ($role === 'department_admin' && !empty($department_id)) {
                            // Department admin sees only their department
                            $dept_stmt = $pdo->prepare("
                                SELECT department_id, department_name 
                                FROM departments 
                                WHERE department_id = ? AND status = 'active'
                            ");
                            $dept_stmt->execute([$department_id]);
                            $departments = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            foreach($departments as $d): ?>
                                <option value="<?= $d['department_id'] ?>" <?= ($selectedDepartment == $d['department_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($d['department_name']) ?>
                                </option>
                            <?php endforeach;
                        } elseif (!empty($selectedFaculty) && !empty($selectedCampus)) {
                            $departments = $pdo->prepare("
                                SELECT department_id, department_name 
                                FROM departments 
                                WHERE faculty_id = ? 
                                AND campus_id = ?
                                AND status = 'active'
                                ORDER BY department_name
                            ");
                            $departments->execute([$selectedFaculty, $selectedCampus]);
                            $departments = $departments->fetchAll(PDO::FETCH_ASSOC);
                            
                            foreach($departments as $d): ?>
                                <option value="<?= $d['department_id'] ?>" 
                                    <?= ($selectedDepartment == $d['department_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($d['department_name']) ?>
                                </option>
                            <?php endforeach;
                        }
                        ?>
                    </select>
                    <?php if ($role === 'department_admin' && !empty($department_id)): ?>
                        <input type="hidden" name="department_id" value="<?= $department_id ?>">
                    <?php endif; ?>
                </div>

                <!-- PROGRAM -->
                <div>
                    <label for="program_id" class="required">Program</label>
                    <select name="program_id" id="program_id" required 
                            onchange="onProgramChange()" 
                            <?= empty($selectedDepartment) ? 'disabled' : '' ?>>
                        <option value="">Select Program</option>
                        <?php 
                        if (!empty($selectedDepartment) && !empty($selectedFaculty) && !empty($selectedCampus)) {
                            $programs = $pdo->prepare("
                                SELECT program_id, program_name, program_code 
                                FROM programs 
                                WHERE department_id = ? 
                                AND faculty_id = ?
                                AND campus_id = ?
                                AND status = 'active'
                                ORDER BY program_name
                            ");
                            $programs->execute([$selectedDepartment, $selectedFaculty, $selectedCampus]);
                            $programs = $programs->fetchAll(PDO::FETCH_ASSOC);
                            
                            foreach($programs as $p): ?>
                                <option value="<?= $p['program_id'] ?>" 
                                    <?= ($selectedProgram == $p['program_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['program_name']) ?> 
                                    (<?= htmlspecialchars($p['program_code']) ?>)
                                </option>
                            <?php endforeach;
                        }
                        ?>
                    </select>
                </div>

                <!-- CLASS -->
                <div>
                    <label for="class_id" class="required">Class</label>
                    <select name="class_id" id="class_id" required 
                            <?= empty($selectedProgram) ? 'disabled' : '' ?>>
                        <option value="">Select Class</option>
                        <?php 
                        if (!empty($selectedProgram) && !empty($selectedDepartment) && !empty($selectedFaculty) && !empty($selectedCampus)) {
                            $classes = $pdo->prepare("
                                SELECT class_id, class_name 
                                FROM classes 
                                WHERE program_id = ? 
                                AND department_id = ?
                                AND faculty_id = ?
                                AND campus_id = ?
                                AND status = 'Active'
                                ORDER BY class_name
                            ");
                            $classes->execute([$selectedProgram, $selectedDepartment, $selectedFaculty, $selectedCampus]);
                            $classes = $classes->fetchAll(PDO::FETCH_ASSOC);
                            
                            foreach($classes as $cls): ?>
                                <option value="<?= $cls['class_id'] ?>" 
                                    <?= ($selectedClass == $cls['class_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cls['class_name']) ?>
                                </option>
                            <?php endforeach;
                        }
                        ?>
                    </select>
                </div>

                <!-- SEMESTER -->
                <div>
                    <label for="semester_id" class="required">Semester</label>
                    <select name="semester_id" id="semester_id" required>
                        <option value="">Select Semester</option>
                        <?php 
                        if ($semesterExists) {
                            $semesters = $pdo->query("SELECT semester_id, semester_name FROM semester WHERE status = 'active' ORDER BY semester_name")->fetchAll(PDO::FETCH_ASSOC);
                        } else {
                            // Fallback to predefined semesters
                            $semesters = [
                                ['semester_id' => 1, 'semester_name' => 'Semester 1'],
                                ['semester_id' => 2, 'semester_name' => 'Semester 2'],
                                ['semester_id' => 3, 'semester_name' => 'Semester 3'],
                                ['semester_id' => 4, 'semester_name' => 'Semester 4'],
                                ['semester_id' => 5, 'semester_name' => 'Semester 5'],
                                ['semester_id' => 6, 'semester_name' => 'Semester 6'],
                                ['semester_id' => 7, 'semester_name' => 'Semester 7'],
                                ['semester_id' => 8, 'semester_name' => 'Semester 8'],
                            ];
                        }
                        
                        foreach($semesters as $s): ?>
                        <option value="<?= $s['semester_id'] ?>" 
                            <?= ($selectedSemester == $s['semester_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['semester_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="align-self:end;">
                    <button type="submit" class="btn blue">
                        <i class="fas fa-search"></i> Load Students
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- STUDENTS LIST -->
    <?php if (!empty($selectedProgram) && !empty($selectedSemester) && !empty($selectedClass)): 
        $program_id  = $selectedProgram;
        $semester_id = $selectedSemester;
        $class_id    = $selectedClass;
        
        // Get campus name for display
        if (!empty($selectedCampus)) {
            $campus_name = $pdo->prepare("SELECT campus_name FROM campus WHERE campus_id = ?");
            $campus_name->execute([$selectedCampus]);
            $campus_name = $campus_name->fetchColumn();
        }
        
        // Get program details
        $program_info = $pdo->prepare("SELECT program_name, program_code FROM programs WHERE program_id = ?");
        $program_info->execute([$program_id]);
        $program_info = $program_info->fetch(PDO::FETCH_ASSOC);
        
        // Get class details
        $class_info = $pdo->prepare("SELECT class_name FROM classes WHERE class_id = ?");
        $class_info->execute([$class_id]);
        $class_info = $class_info->fetchColumn();
        
        // Get semester name
        if ($semesterExists) {
            $semester_info = $pdo->prepare("SELECT semester_name FROM semester WHERE semester_id = ?");
            $semester_info->execute([$semester_id]);
            $semester_name = $semester_info->fetchColumn();
        } else {
            $semester_name = "Semester $semester_id";
        }
        
        // Get subjects for this selection
        $subjects = $pdo->prepare("
            SELECT COUNT(*) as subject_count 
            FROM subject 
            WHERE program_id = ? 
            AND semester_id = ?
            AND campus_id = ?
            AND faculty_id = ?
            AND department_id = ?
            AND status = 'active'
        ");
        $subjects->execute([$program_id, $semester_id, $selectedCampus, $selectedFaculty, $selectedDepartment]);
        $subject_count = $subjects->fetchColumn();
        
        // ✅ Build query with proper filtering
        $whereClauses = [
            "s.status = 'active'",
            "s.program_id = ?",
            "s.campus_id = ?",
            "s.faculty_id = ?",
            "s.department_id = ?",
            "s.class_id = ?"
        ];
        
        $params = [
            $program_id, 
            $selectedCampus, 
            $selectedFaculty, 
            $selectedDepartment,
            $class_id
        ];
        
        // Check enrollment status
        if (!empty($academic_term_id)) {
            $whereClauses[] = "s.student_id NOT IN (
                SELECT DISTINCT student_id 
                FROM student_enroll 
                WHERE academic_term_id = ? 
                AND campus_id = ? 
                AND faculty_id = ? 
                AND department_id = ? 
                AND program_id = ? 
                AND semester_id = ?
                AND class_id = ?
            )";
            
            $params = array_merge($params, [
                $academic_term_id, 
                $selectedCampus, 
                $selectedFaculty, 
                $selectedDepartment, 
                $program_id, 
                $semester_id,
                $class_id
            ]);
        }
        
        // Add search filter
        if (!empty($searchQuery)) {
            $whereClauses[] = "(s.full_name LIKE ? OR s.reg_no LIKE ? OR s.email LIKE ? OR s.phone_number LIKE ?)";
            $searchTerm = "%" . $searchQuery . "%";
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        }
        
        $whereSQL = implode(" AND ", $whereClauses);
        
        // Build order by clause
        $orderBy = "s.full_name";
        $orderDirection = "ASC";
        
        if ($sortBy === 'reg_no') {
            $orderBy = "s.reg_no";
        } elseif ($sortBy === 'email') {
            $orderBy = "s.email";
        }
        
        if ($sortOrder === 'desc') {
            $orderDirection = "DESC";
        }
        
        // Get total count
        $countStmt = $pdo->prepare("
            SELECT COUNT(*) as total
            FROM students s
            WHERE $whereSQL
        ");
        $countStmt->execute($params);
        $totalCount = $countStmt->fetchColumn();
        
        // Pagination settings
        $perPage = 50;
        $totalPages = ceil($totalCount / $perPage);
        $currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $offset = ($currentPage - 1) * $perPage;
        
        // Main query with pagination
        $studentsQuery = "
            SELECT s.student_id, s.full_name, s.reg_no, s.email, s.phone_number
            FROM students s
            WHERE $whereSQL
            ORDER BY $orderBy $orderDirection
            LIMIT $perPage OFFSET $offset
        ";
        
        $studentsStmt = $pdo->prepare($studentsQuery);
        $studentsStmt->execute($params);
        $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
    

    
    <!-- SEARCH AND FILTER BAR -->
    <div class="search-filter-bar">
        <div class="search-box">
            <form method="GET" onsubmit="return handleSearch(event)" style="display: flex; gap: 10px;">
                <div class="search-input" style="flex: 1;">
                    <i class="fas fa-search"></i>
                    <input type="text" 
                           name="search" 
                           id="searchInput" 
                           placeholder="Search by name, registration number, email or phone..."
                           value="<?= htmlspecialchars($searchQuery) ?>"
                           onkeyup="if(event.key === 'Enter') handleSearch(event)">
                </div>
                <input type="hidden" name="campus_id" value="<?= htmlspecialchars($selectedCampus) ?>">
                <input type="hidden" name="faculty_id" value="<?= htmlspecialchars($selectedFaculty) ?>">
                <input type="hidden" name="department_id" value="<?= htmlspecialchars($selectedDepartment) ?>">
                <input type="hidden" name="program_id" value="<?= htmlspecialchars($selectedProgram) ?>">
                <input type="hidden" name="class_id" value="<?= htmlspecialchars($selectedClass) ?>">
                <input type="hidden" name="semester_id" value="<?= htmlspecialchars($selectedSemester) ?>">
                <button type="submit" class="btn orange">
                    <i class="fas fa-search"></i> Search
                </button>
                <?php if (!empty($searchQuery)): ?>
                <a href="<?php 
                    echo '?' . http_build_query([
                        'campus_id' => $selectedCampus,
                        'faculty_id' => $selectedFaculty,
                        'department_id' => $selectedDepartment,
                        'program_id' => $selectedProgram,
                        'class_id' => $selectedClass,
                        'semester_id' => $selectedSemester,
                        'sort' => $sortBy,
                        'order' => $sortOrder
                    ]); 
                ?>" class="btn" style="background: #eee; color: #666;">
                    <i class="fas fa-times"></i> Clear
                </a>
                <?php endif; ?>
            </form>
        </div>
        
        <div class="sort-options">
            <span style="font-weight: 600; color: var(--hu-blue);">Sort by:</span>
            <button class="sort-btn <?= $sortBy === 'name' ? 'active' : '' ?>" 
                    onclick="sortTable('name', '<?= $sortBy === 'name' && $sortOrder === 'asc' ? 'desc' : 'asc' ?>')">
                Name 
                <?php if ($sortBy === 'name'): ?>
                <span class="arrow"><?= $sortOrder === 'asc' ? '↑' : '↓' ?></span>
                <?php endif; ?>
            </button>
            <button class="sort-btn <?= $sortBy === 'reg_no' ? 'active' : '' ?>" 
                    onclick="sortTable('reg_no', '<?= $sortBy === 'reg_no' && $sortOrder === 'asc' ? 'desc' : 'asc' ?>')">
                Reg No
                <?php if ($sortBy === 'reg_no'): ?>
                <span class="arrow"><?= $sortOrder === 'asc' ? '↑' : '↓' ?></span>
                <?php endif; ?>
            </button>
            <button class="sort-btn <?= $sortBy === 'email' ? 'active' : '' ?>" 
                    onclick="sortTable('email', '<?= $sortBy === 'email' && $sortOrder === 'asc' ? 'desc' : 'asc' ?>')">
                Email
                <?php if ($sortBy === 'email'): ?>
                <span class="arrow"><?= $sortOrder === 'asc' ? '↑' : '↓' ?></span>
                <?php endif; ?>
            </button>
        </div>
    </div>
    
    <div class="table-wrapper">
        <form method="POST" id="enrollmentForm">
            <input type="hidden" name="campus_id" value="<?= htmlspecialchars($selectedCampus) ?>">
            <input type="hidden" name="faculty_id" value="<?= htmlspecialchars($selectedFaculty) ?>">
            <input type="hidden" name="department_id" value="<?= htmlspecialchars($selectedDepartment) ?>">
            <input type="hidden" name="program_id" value="<?= htmlspecialchars($program_id) ?>">
            <input type="hidden" name="semester_id" value="<?= htmlspecialchars($semester_id) ?>">
            <input type="hidden" name="class_id" value="<?= htmlspecialchars($class_id) ?>">
            
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h3 style="color: var(--hu-blue); margin: 0; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-users"></i> Select Students to Enroll
                    <?php if ($totalCount > 0): ?>
                    
                    <?php endif; ?>
                </h3>
                <div>
                    <input type="checkbox" id="checkAll" onchange="toggleAllCheckboxes()">
                    <label for="checkAll" style="margin-left: 5px; font-weight: 500; color: var(--hu-blue);">Select All</label>
                </div>
            </div>
            
            <?php if($totalCount > 0): ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width: 40px;">Select</th>
                            <th>#</th>
                            <th>Full Name</th>
                            <th>Reg No</th>
                            <th>Email</th>
                            <th>Phone</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = ($currentPage - 1) * $perPage + 1; foreach($students as $s): ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="student_ids[]" value="<?= $s['student_id'] ?>" 
                                       class="student-checkbox">
                            </td>
                            <td><?= $i++ ?></td>
                            <td><?= htmlspecialchars($s['full_name']) ?></td>
                            <td><strong><?= htmlspecialchars($s['reg_no']) ?></strong></td>
                            <td><?= htmlspecialchars($s['email']) ?></td>
                            <td><?= htmlspecialchars($s['phone_number']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- PAGINATION -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <button onclick="goToPage(<?= max(1, $currentPage - 1) ?>)" 
                        <?= $currentPage <= 1 ? 'disabled' : '' ?>>
                    <i class="fas fa-chevron-left"></i> Previous
                </button>
                
                <div class="page-info">
                    Page <?= $currentPage ?> of <?= $totalPages ?>
                    (<?= $totalCount ?> total students)
                </div>
                
                <button onclick="goToPage(<?= min($totalPages, $currentPage + 1) ?>)" 
                        <?= $currentPage >= $totalPages ? 'disabled' : '' ?>>
                    Next <i class="fas fa-chevron-right"></i>
                </button>
            </div>
            <?php endif; ?>
            
            <div style="padding: 20px 25px; text-align: right; border-top: 2px solid #f0f0f0;">
                <button type="submit" name="enroll_selected" class="btn green" 
                        onclick="return confirmEnrollment()">
                    <i class="fas fa-user-plus"></i> Enroll Selected Students
                </button>
            </div>
            
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-user-check"></i>
                <h3>No Students Found</h3>
                <p>
                    <?php if (!empty($searchQuery)): ?>
                    No students match your search criteria "<?= htmlspecialchars($searchQuery) ?>".<br>
                    Try a different search term or <a href="javascript:void(0)" onclick="clearSearch()">clear the search filter</a>.
                    <?php elseif ($subject_count == 0): ?>
                    No active subjects found for the selected program and semester.<br>
                    Please add subjects first before enrolling students.
                    <?php else: ?>
                    All students in this class are already enrolled for the selected term.<br>
                    No students available for enrollment.
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php if($message): ?>
<div class="alert <?= htmlspecialchars($type) ?>" id="alertMessage">
    <?php if($type == 'success'): ?>
        <i class="fas fa-check-circle"></i>
    <?php elseif($type == 'error'): ?>
        <i class="fas fa-exclamation-circle"></i>
    <?php elseif($type == 'warning'): ?>
        <i class="fas fa-exclamation-triangle"></i>
    <?php endif; ?>
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<script>
// Get current filename for AJAX calls
const currentFile = window.location.pathname.split('/').pop();
const userRole = '<?= $role ?>';

// ===========================================
// HIERARCHY AJAX FUNCTIONS
// ===========================================

function onCampusChange() {
    const campusId = document.getElementById('campus_id').value;
    const facultySelect = document.getElementById('faculty_id');
    const deptSelect = document.getElementById('department_id');
    const programSelect = document.getElementById('program_id');
    const classSelect = document.getElementById('class_id');
    
    if (!campusId) {
        resetHierarchy();
        return;
    }
    
    facultySelect.innerHTML = '<option value="">Loading...</option>';
    facultySelect.disabled = true;
    resetChildDropdowns(deptSelect, programSelect, classSelect);
    
    fetch(`${currentFile}?ajax=get_faculties&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
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
                facultySelect.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error loading faculties:', error);
            facultySelect.innerHTML = '<option value="">Error loading</option>';
            facultySelect.disabled = false;
        });
}

function onFacultyChange() {
    const facultyId = document.getElementById('faculty_id').value;
    const campusId = document.getElementById('campus_id').value;
    const deptSelect = document.getElementById('department_id');
    const programSelect = document.getElementById('program_id');
    const classSelect = document.getElementById('class_id');
    
    if (!facultyId || !campusId) {
        resetChildDropdowns(deptSelect, programSelect, classSelect);
        return;
    }
    
    deptSelect.innerHTML = '<option value="">Loading...</option>';
    deptSelect.disabled = true;
    resetChildDropdowns(programSelect, classSelect);
    
    fetch(`${currentFile}?ajax=get_departments&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
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
                deptSelect.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error loading departments:', error);
            deptSelect.innerHTML = '<option value="">Error loading</option>';
            deptSelect.disabled = false;
        });
}

function onDepartmentChange() {
    const deptId = document.getElementById('department_id').value;
    const facultyId = document.getElementById('faculty_id').value;
    const campusId = document.getElementById('campus_id').value;
    const programSelect = document.getElementById('program_id');
    const classSelect = document.getElementById('class_id');
    
    if (!deptId || !facultyId || !campusId) {
        resetChildDropdowns(programSelect, classSelect);
        return;
    }
    
    programSelect.innerHTML = '<option value="">Loading...</option>';
    programSelect.disabled = true;
    classSelect.innerHTML = '<option value="">Select Class</option>';
    classSelect.disabled = true;
    
    fetch(`${currentFile}?ajax=get_programs&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            programSelect.innerHTML = '<option value="">Select Program</option>';
            
            if (data.status === 'success' && data.programs.length > 0) {
                data.programs.forEach(program => {
                    const option = document.createElement('option');
                    option.value = program.program_id;
                    option.textContent = `${program.program_name} (${program.program_code})`;
                    programSelect.appendChild(option);
                });
                programSelect.disabled = false;
            } else {
                programSelect.innerHTML = '<option value="">No active programs found</option>';
                programSelect.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error loading programs:', error);
            programSelect.innerHTML = '<option value="">Error loading</option>';
            programSelect.disabled = false;
        });
}

function onProgramChange() {
    const programId = document.getElementById('program_id').value;
    const deptId = document.getElementById('department_id').value;
    const facultyId = document.getElementById('faculty_id').value;
    const campusId = document.getElementById('campus_id').value;
    const classSelect = document.getElementById('class_id');
    
    if (!programId || !deptId || !facultyId || !campusId) {
        classSelect.innerHTML = '<option value="">Select Class</option>';
        classSelect.disabled = true;
        return;
    }
    
    classSelect.innerHTML = '<option value="">Loading...</option>';
    classSelect.disabled = true;
    
    fetch(`${currentFile}?ajax=get_classes&program_id=${programId}&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
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
                classSelect.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error loading classes:', error);
            classSelect.innerHTML = '<option value="">Error loading</option>';
            classSelect.disabled = false;
        });
}

// Helper functions
function resetHierarchy() {
    const facultySelect = document.getElementById('faculty_id');
    const deptSelect = document.getElementById('department_id');
    const programSelect = document.getElementById('program_id');
    const classSelect = document.getElementById('class_id');
    
    facultySelect.innerHTML = '<option value="">Select Faculty</option>';
    facultySelect.disabled = true;
    deptSelect.innerHTML = '<option value="">Select Department</option>';
    deptSelect.disabled = true;
    programSelect.innerHTML = '<option value="">Select Program</option>';
    programSelect.disabled = true;
    classSelect.innerHTML = '<option value="">Select Class</option>';
    classSelect.disabled = true;
}

function resetChildDropdowns(...selects) {
    selects.forEach(select => {
        if (select) {
            const label = select.id.replace('_id', '').replace('_', ' ');
            select.innerHTML = `<option value="">Select ${label}</option>`;
            select.disabled = true;
        }
    });
}

// ===========================================
// SEARCH AND FILTER FUNCTIONS
// ===========================================

function handleSearch(event) {
    event.preventDefault();
    const searchInput = document.getElementById('searchInput');
    const searchValue = searchInput.value.trim();
    
    const urlParams = new URLSearchParams(window.location.search);
    
    if (searchValue) {
        urlParams.set('search', searchValue);
    } else {
        urlParams.delete('search');
    }
    
    urlParams.set('page', 1);
    
    window.location.href = window.location.pathname + '?' + urlParams.toString();
}

function sortTable(sortBy, order) {
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('sort', sortBy);
    urlParams.set('order', order);
    urlParams.set('page', 1);
    
    window.location.href = window.location.pathname + '?' + urlParams.toString();
}

function goToPage(page) {
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('page', page);
    
    window.location.href = window.location.pathname + '?' + urlParams.toString();
}

function clearSearch() {
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.delete('search');
    urlParams.set('page', 1);
    window.location.href = window.location.pathname + '?' + urlParams.toString();
}

// ===========================================
// SELECT ALL CHECKBOXES
// ===========================================

function toggleAllCheckboxes() {
    const checkAll = document.getElementById('checkAll');
    const checkboxes = document.querySelectorAll('.student-checkbox');
    
    checkboxes.forEach(checkbox => {
        checkbox.checked = checkAll.checked;
    });
    
    updateSelectedCount();
}

function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.student-checkbox:checked');
    const countElement = document.getElementById('selectedCount');
    
    if (countElement) {
        countElement.textContent = checkboxes.length;
        countElement.style.fontWeight = 'bold';
        countElement.style.color = checkboxes.length > 0 ? 'var(--hu-green)' : '#333';
    }
}

// ===========================================
// FORM VALIDATION
// ===========================================

function confirmEnrollment() {
    const checkboxes = document.querySelectorAll('.student-checkbox:checked');
    
    if (checkboxes.length === 0) {
        alert('Please select at least one student to enroll.');
        return false;
    }
    
    return confirm(`Are you sure you want to enroll ${checkboxes.length} student(s)?\n\nThis will enroll them in all subjects for the selected semester.`);
}

// ===========================================
// INITIALIZE ON PAGE LOAD
// ===========================================

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.student-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', updateSelectedCount);
    });
    
    updateSelectedCount();
    
    const alertMessage = document.getElementById('alertMessage');
    if (alertMessage) {
        setTimeout(() => {
            alertMessage.style.opacity = '0';
            alertMessage.style.transition = 'opacity 0.5s';
            setTimeout(() => {
                if (alertMessage.parentNode) {
                    alertMessage.parentNode.removeChild(alertMessage);
                }
            }, 500);
        }, 5000);
    }
    
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.focus();
        searchInput.select();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA' && 
        e.target.tagName !== 'SELECT' && e.target.tagName !== 'INPUT') {
        e.preventDefault();
    }
});
</script>

<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>