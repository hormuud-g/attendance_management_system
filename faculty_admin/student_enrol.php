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
$role = strtolower($_SESSION['user']['role'] ?? '');
if ($role !== 'faculty_admin') {
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

/* =============== ACTIVE TERM =============== */
$term = $pdo->query("SELECT academic_term_id FROM academic_term WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$academic_term_id = $term['academic_term_id'] ?? null;

/* =============== AJAX HANDLERS FOR HIERARCHY =============== */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    // GET FACULTIES BY CAMPUS (filtered by faculty's campuses)
    if ($_GET['ajax'] == 'get_faculties') {
        $campus_id = $_GET['campus_id'] ?? 0;
        
        if (!$campus_id) {
            echo json_encode(['status' => 'error', 'message' => 'Campus ID required']);
            exit;
        }
        
        // Check if campus belongs to this faculty
        if (!in_array($campus_id, $campus_ids)) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        
        // For faculty admin, only return the current faculty
        $faculties = [
            ['faculty_id' => $faculty_id, 'faculty_name' => $faculty_name]
        ];
        
        echo json_encode(['status' => 'success', 'faculties' => $faculties]);
        exit;
    }
    
    // GET DEPARTMENTS BY FACULTY & CAMPUS (with campus context)
    if ($_GET['ajax'] == 'get_departments') {
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        if (!$faculty_id || !$campus_id) {
            echo json_encode(['status' => 'error', 'message' => 'Faculty ID and Campus ID required']);
            exit;
        }
        
        // Check if campus belongs to this faculty
        if (!in_array($campus_id, $campus_ids)) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT department_id, department_name, campus_id
            FROM departments 
            WHERE faculty_id = ? AND campus_id = ? AND status = 'active'
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
    if ($_GET['ajax'] == 'get_programs') {
        $department_id = $_GET['department_id'] ?? 0;
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        if (!$department_id || !$faculty_id || !$campus_id) {
            echo json_encode(['status' => 'error', 'message' => 'Department, Faculty and Campus ID required']);
            exit;
        }
        
        // Check if campus belongs to this faculty
        if (!in_array($campus_id, $campus_ids)) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
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
        
        // Get campus name for display
        $campus_stmt = $pdo->prepare("SELECT campus_name FROM campus WHERE campus_id = ?");
        $campus_stmt->execute([$campus_id]);
        $campus_name = $campus_stmt->fetchColumn();
        
        foreach ($programs as &$prog) {
            $prog['campus_name'] = $campus_name;
        }
        
        echo json_encode(['status' => 'success', 'programs' => $programs]);
        exit;
    }
    
    // GET CLASSES BY PROGRAM, DEPARTMENT, FACULTY & CAMPUS (with study mode)
    if ($_GET['ajax'] == 'get_classes') {
        $program_id = $_GET['program_id'] ?? 0;
        $department_id = $_GET['department_id'] ?? 0;
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        if (!$program_id || !$department_id || !$faculty_id || !$campus_id) {
            echo json_encode(['status' => 'error', 'message' => 'All IDs required']);
            exit;
        }
        
        // Check if campus belongs to this faculty
        if (!in_array($campus_id, $campus_ids)) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
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
            ORDER BY study_mode, class_name
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
        $campus_id   = $_POST['campus_id'];
        $department_id = $_POST['department_id'];
        $students    = $_POST['student_ids'] ?? [];

        if (empty($students)) throw new Exception("No students selected!");
        if (!$academic_term_id) throw new Exception("No active academic term found!");

        // Check if campus belongs to this faculty
        if (!in_array($campus_id, $campus_ids)) {
            throw new Exception("Access denied: Invalid campus selected!");
        }

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
            throw new Exception("No subjects found for the selected program and semester!");
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
// ✅ Get current selections from GET or POST
$selectedCampus = $_POST['campus_id'] ?? $_GET['campus_id'] ?? null;
$selectedDepartment = $_POST['department_id'] ?? $_GET['department_id'] ?? null;
$selectedProgram = $_POST['program_id'] ?? $_GET['program_id'] ?? null;
$selectedClass = $_POST['class_id'] ?? $_GET['class_id'] ?? null;
$selectedSemester = $_POST['semester_id'] ?? $_GET['semester_id'] ?? null;

// ✅ Get filter/search parameters
$searchQuery = $_GET['search'] ?? '';
$sortBy = $_GET['sort'] ?? 'name'; // name, reg_no, email
$sortOrder = $_GET['order'] ?? 'asc'; // asc, desc

// ✅ Check if semester table exists
$semesterExists = $pdo->query("SHOW TABLES LIKE 'semester'")->fetch();

// ✅ Get campus name for selected campus
$selectedCampusName = '';
if ($selectedCampus) {
    $stmt = $pdo->prepare("SELECT campus_name FROM campus WHERE campus_id = ?");
    $stmt->execute([$selectedCampus]);
    $selectedCampusName = $stmt->fetchColumn();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Bulk Student Enrollment | Faculty Admin - <?= htmlspecialchars($faculty_name) ?> | Hormuud University</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="../images.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 20px;
}

label {
    font-weight: 600;
    color: var(--blue);
    font-size: 13px;
    margin-bottom: 8px;
    display: block;
}

.required::after {
    content: " *";
    color: var(--red);
}

select, input[type="text"] {
    width: 100%;
    padding: 10px 12px;
    border: 2px solid var(--border);
    border-radius: 8px;
    font-size: 14px;
    background: #f9f9f9;
    transition: all 0.3s;
}

select:focus, input[type="text"]:focus {
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
    padding: 10px 18px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-size: 14px;
}

.btn.blue {
    background: var(--blue);
    color: var(--white);
}

.btn.blue:hover {
    background: #005fa3;
    transform: translateY(-2px);
}

.btn.green {
    background: var(--green);
    color: var(--white);
}

.btn.green:hover {
    background: var(--light-green);
    transform: translateY(-2px);
}

.btn.orange {
    background: var(--orange);
    color: var(--white);
}

.btn.orange:hover {
    background: #e68900;
    transform: translateY(-2px);
}

.btn:disabled {
    background: #ccc;
    cursor: not-allowed;
    transform: none;
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
}

tr:hover {
    background: #eef8f0;
}

tbody tr:nth-child(even) {
    background: #f9f9f9;
}

tfoot {
    background: #f0f7ff;
    font-weight: 600;
}

/* Checkbox */
input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
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
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    animation: slideIn 0.3s ease-out;
    display: flex;
    align-items: center;
    gap: 10px;
    border-left: 5px solid transparent;
}

.alert.success {
    background: var(--green);
    border-left-color: #00612c;
}

.alert.error {
    background: var(--red);
    border-left-color: #8e1c1c;
}

.alert.warning {
    background: var(--orange);
    border-left-color: #cc7a00;
}

@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

/* Search and Filter Bar */
.search-filter-bar {
    background: var(--white);
    padding: 15px 20px;
    border-radius: 12px;
    box-shadow: 0 4px 12px var(--shadow);
    margin-bottom: 15px;
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    align-items: center;
    border: 1px solid var(--border);
}

.search-box {
    flex: 1;
    min-width: 300px;
}

.search-box .search-input {
    position: relative;
}

.search-box input {
    padding-left: 40px;
    width: 100%;
}

.search-box i {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #777;
}

.sort-options {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}

.sort-btn {
    padding: 8px 15px;
    background: #f0f7ff;
    border: 1px solid #cce0ff;
    border-radius: 6px;
    color: var(--blue);
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s;
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 13px;
}

.sort-btn:hover {
    background: #e0f0ff;
    border-color: var(--blue);
}

.sort-btn.active {
    background: var(--blue);
    color: white;
    border-color: var(--blue);
}

.sort-btn .arrow {
    font-size: 12px;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 50px 20px;
    color: #666;
}

.empty-state i {
    font-size: 60px;
    color: #ddd;
    margin-bottom: 20px;
}

.empty-state h3 {
    color: #777;
    margin-bottom: 10px;
    font-size: 18px;
}

.empty-state p {
    color: #999;
    font-size: 14px;
}

/* Info Box */
.info-box {
    background: #f0f7ff;
    border-left: 4px solid var(--blue);
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 15px;
}

.info-box i {
    color: var(--blue);
    font-size: 24px;
}

.info-box-content {
    flex: 1;
}

.info-box-title {
    font-weight: 600;
    color: var(--blue);
    margin-bottom: 5px;
    font-size: 16px;
}

.info-box-text {
    color: #555;
    font-size: 14px;
}

/* Selection Summary */
.selection-summary {
    background: #f0f7ff;
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 15px;
    border: 1px solid #cce0ff;
}

.summary-item {
    display: flex;
    align-items: center;
    gap: 10px;
}

.summary-label {
    font-weight: 600;
    color: #666;
}

.summary-value {
    font-weight: 700;
    color: var(--blue);
    background: white;
    padding: 5px 12px;
    border-radius: 20px;
    border: 1px solid #cce0ff;
}

/* Campus Badge */
.campus-badge {
    background: rgba(255, 184, 28, 0.1);
    color: var(--gold);
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 600;
    display: inline-block;
    margin-left: 5px;
}

/* Study Mode Badge */
.study-mode-badge {
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 600;
    display: inline-block;
    margin-left: 5px;
    text-transform: uppercase;
}

.study-mode-full-time {
    background: rgba(0, 114, 206, 0.1);
    color: var(--blue);
    border: 1px solid rgba(0, 114, 206, 0.2);
}

.study-mode-part-time {
    background: rgba(255, 184, 28, 0.1);
    color: var(--gold);
    border: 1px solid rgba(255, 184, 28, 0.2);
}

/* Pagination */
.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 15px;
    margin-top: 20px;
    padding: 15px;
}

.pagination button {
    padding: 8px 15px;
    background: #f0f7ff;
    border: 1px solid #cce0ff;
    border-radius: 6px;
    color: var(--blue);
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s;
    font-size: 13px;
}

.pagination button:hover:not(:disabled) {
    background: #e0f0ff;
    border-color: var(--blue);
}

.pagination button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.pagination .page-info {
    color: #666;
    font-size: 14px;
    padding: 8px 15px;
    background: #f0f0f0;
    border-radius: 20px;
}

/* Loading indicator */
.loading {
    display: inline-block;
    width: 20px;
    height: 20px;
    border: 3px solid #f3f3f3;
    border-top: 3px solid var(--blue);
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
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
        flex-wrap: wrap;
        justify-content: center;
    }
    
    .selection-summary {
        flex-direction: column;
        align-items: flex-start;
    }
}

@media (max-width: 576px) {
    .table-wrapper {
        overflow-x: auto;
    }
    
    table {
        min-width: 800px;
    }
    
    .pagination {
        flex-direction: column;
        gap: 10px;
    }
}

/* Scrollbar */
.table-wrapper::-webkit-scrollbar {
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
            <i class="fas fa-user-graduate"></i> 
            Student Enrollment
        </h1>
    </div>

    <!-- HIERARCHICAL SELECTION FORM -->
    <div class="filter-box">
        <form method="GET" id="hierarchyForm">
            <div class="grid">
                <!-- CAMPUS (Faculty Admin sees all their campuses) -->
                <div>
                    <label for="campus_id" class="required">Campus</label>
                    <select name="campus_id" id="campus_id" required onchange="onCampusChange()">
                        <option value="">Select Campus</option>
                        <?php foreach($faculty_campuses as $c): ?>
                        <option value="<?= $c['campus_id'] ?>" 
                            <?= ($selectedCampus == $c['campus_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['campus_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- FACULTY (Auto-selected - read-only) -->
                <div>
                    <label for="faculty_id">Faculty</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($faculty_name) ?> (<?= htmlspecialchars($faculty_code) ?>)" readonly disabled>
                    <input type="hidden" name="faculty_id" id="faculty_id" value="<?= $faculty_id ?>">
                </div>

                <!-- DEPARTMENT -->
                <div>
                    <label for="department_id" class="required">Department</label>
                    <select name="department_id" id="department_id" required 
                            onchange="onDepartmentChange()" 
                            <?= empty($selectedCampus) ? 'disabled' : '' ?>>
                        <option value="">Select Department</option>
                        <?php 
                        if (!empty($selectedCampus)) {
                            $dept_stmt = $pdo->prepare("
                                SELECT d.department_id, d.department_name, c.campus_name
                                FROM departments d
                                JOIN campus c ON d.campus_id = c.campus_id
                                WHERE d.faculty_id = ? AND d.campus_id = ? AND d.status = 'active'
                                ORDER BY d.department_name
                            ");
                            $dept_stmt->execute([$faculty_id, $selectedCampus]);
                            $departments = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            foreach($departments as $d): ?>
                            <option value="<?= $d['department_id'] ?>" 
                                <?= ($selectedDepartment == $d['department_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($d['department_name']) ?> (<?= htmlspecialchars($d['campus_name']) ?>)
                            </option>
                            <?php endforeach;
                        }
                        ?>
                    </select>
                </div>

                <!-- PROGRAM -->
                <div>
                    <label for="program_id" class="required">Program</label>
                    <select name="program_id" id="program_id" required 
                            onchange="onProgramChange()" 
                            <?= empty($selectedDepartment) ? 'disabled' : '' ?>>
                        <option value="">Select Program</option>
                        <?php 
                        if (!empty($selectedDepartment) && !empty($selectedCampus)) {
                            $prog_stmt = $pdo->prepare("
                                SELECT program_id, program_name, program_code 
                                FROM programs 
                                WHERE department_id = ? 
                                AND faculty_id = ?
                                AND campus_id = ?
                                AND status = 'active'
                                ORDER BY program_name
                            ");
                            $prog_stmt->execute([$selectedDepartment, $faculty_id, $selectedCampus]);
                            $programs = $prog_stmt->fetchAll(PDO::FETCH_ASSOC);
                            
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

                <!-- CLASS (with study mode) -->
                <div>
                    <label for="class_id" class="required">Class</label>
                    <select name="class_id" id="class_id" required 
                            <?= empty($selectedProgram) ? 'disabled' : '' ?>>
                        <option value="">Select Class</option>
                        <?php 
                        if (!empty($selectedProgram) && !empty($selectedDepartment) && !empty($selectedCampus)) {
                            $cls_stmt = $pdo->prepare("
                                SELECT class_id, class_name, study_mode 
                                FROM classes 
                                WHERE program_id = ? 
                                AND department_id = ?
                                AND faculty_id = ?
                                AND campus_id = ?
                                AND status = 'Active'
                                ORDER BY study_mode, class_name
                            ");
                            $cls_stmt->execute([$selectedProgram, $selectedDepartment, $faculty_id, $selectedCampus]);
                            $classes = $cls_stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            foreach($classes as $cls): ?>
                            <option value="<?= $cls['class_id'] ?>" 
                                <?= ($selectedClass == $cls['class_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cls['class_name']) ?> (<?= htmlspecialchars($cls['study_mode']) ?>)
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

    <!-- SELECTION SUMMARY -->
    <?php if (!empty($selectedProgram) && !empty($selectedSemester) && !empty($selectedClass)): 
        // Get subject count for display
        $subject_count = 0;
        if (!empty($selectedProgram) && !empty($selectedSemester)) {
            $subj_stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM subject 
                WHERE program_id = ? 
                AND semester_id = ?
                AND campus_id = ?
                AND faculty_id = ?
                AND department_id = ?
                AND status = 'active'
            ");
            $subj_stmt->execute([$selectedProgram, $selectedSemester, $selectedCampus, $faculty_id, $selectedDepartment]);
            $subject_count = $subj_stmt->fetchColumn();
        }
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
                <input type="hidden" name="faculty_id" value="<?= htmlspecialchars($faculty_id) ?>">
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
                        'faculty_id' => $faculty_id,
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
            <span style="font-weight: 600; color: var(--blue);">Sort by:</span>
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

    <?php
    // Build query to get students in this specific class
    $whereClauses = [
        "s.status = 'active'",
        "s.program_id = ?",
        "s.campus_id = ?",
        "s.faculty_id = ?",
        "s.department_id = ?",
        "s.class_id = ?"
    ];
    
    $params = [
        $selectedProgram, 
        $selectedCampus, 
        $faculty_id, 
        $selectedDepartment,
        $selectedClass
    ];
    
    // Exclude already enrolled students
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
            $faculty_id, 
            $selectedDepartment, 
            $selectedProgram, 
            $selectedSemester,
            $selectedClass
        ]);
    }
    
    // Add search filter
    if (!empty($searchQuery)) {
        $whereClauses[] = "(s.full_name LIKE ? OR s.reg_no LIKE ? OR s.email LIKE ? OR s.phone_number LIKE ?)";
        $searchTerm = "%" . $searchQuery . "%";
        $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    }
    
    $whereSQL = implode(" AND ", $whereClauses);
    
    // Build order by
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
    
    // Get total count for pagination
    $countStmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM students s
        WHERE $whereSQL
    ");
    $countStmt->execute($params);
    $totalCount = $countStmt->fetchColumn();
    
    // Pagination
    $perPage = 50;
    $totalPages = ceil($totalCount / $perPage);
    $currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $offset = ($currentPage - 1) * $perPage;
    
    // Main query
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
    
    <div class="table-wrapper">
        <form method="POST" id="enrollmentForm">
            <input type="hidden" name="campus_id" value="<?= htmlspecialchars($selectedCampus) ?>">
            <input type="hidden" name="faculty_id" value="<?= htmlspecialchars($faculty_id) ?>">
            <input type="hidden" name="department_id" value="<?= htmlspecialchars($selectedDepartment) ?>">
            <input type="hidden" name="program_id" value="<?= htmlspecialchars($selectedProgram) ?>">
            <input type="hidden" name="semester_id" value="<?= htmlspecialchars($selectedSemester) ?>">
            <input type="hidden" name="class_id" value="<?= htmlspecialchars($selectedClass) ?>">
            
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding: 15px 20px;">
                <h3 style="color: var(--blue); margin: 0; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-users"></i> Available Students
                    <?php if ($totalCount > 0): ?>
                    <span style="font-size: 14px; background: #e0f0ff; padding: 2px 10px; border-radius: 20px;">
                        <?= $totalCount ?> available
                    </span>
                    <?php endif; ?>
                </h3>
                <div>
                    <input type="checkbox" id="checkAll" onchange="toggleAllCheckboxes()">
                    <label for="checkAll" style="margin-left: 5px; font-weight: normal;">Select All</label>
                </div>
            </div>
            
            <?php if($totalCount > 0): ?>
            <table>
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
                <tfoot>
                    <tr>
                        <td colspan="6" style="text-align: right; padding: 15px;">
                            <span id="selectedCount">0</span> students selected
                        </td>
                    </tr>
                </tfoot>
            </table>
            
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
            
            <div style="padding: 20px; text-align: right; border-top: 1px solid #eee;">
                <button type="submit" name="enroll_selected" class="btn green" 
                        onclick="return confirmEnrollment()">
                    <i class="fas fa-user-plus"></i> Enroll Selected Students
                </button>
            </div>
            
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-user-check"></i>
                <h3>No Students Available</h3>
                <p>
                    <?php if (!empty($searchQuery)): ?>
                    No students match your search criteria "<?= htmlspecialchars($searchQuery) ?>".<br>
                    Try a different search term or clear the search filter.
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
const facultyId = '<?= $faculty_id ?>';

// ===========================================
// HIERARCHY AJAX FUNCTIONS
// ===========================================

function onCampusChange() {
    const campusId = document.getElementById('campus_id').value;
    const deptSelect = document.getElementById('department_id');
    const progSelect = document.getElementById('program_id');
    const classSelect = document.getElementById('class_id');
    
    if (!campusId) {
        resetHierarchy();
        return;
    }
    
    // Load departments for selected campus
    deptSelect.innerHTML = '<option value="">Loading...</option>';
    deptSelect.disabled = true;
    resetChildDropdowns(progSelect, classSelect);
    
    fetch(`${currentFile}?ajax=get_departments&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            deptSelect.innerHTML = '<option value="">Select Department</option>';
            
            if (data.status === 'success' && data.departments.length > 0) {
                data.departments.forEach(dept => {
                    const option = document.createElement('option');
                    option.value = dept.department_id;
                    option.textContent = dept.display_name || dept.department_name;
                    deptSelect.appendChild(option);
                });
                deptSelect.disabled = false;
            } else {
                deptSelect.innerHTML = '<option value="">No departments found</option>';
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
    const campusId = document.getElementById('campus_id').value;
    const progSelect = document.getElementById('program_id');
    const classSelect = document.getElementById('class_id');
    
    if (!deptId || !campusId) {
        resetChildDropdowns(progSelect, classSelect);
        return;
    }
    
    progSelect.innerHTML = '<option value="">Loading...</option>';
    progSelect.disabled = true;
    classSelect.innerHTML = '<option value="">Select Class</option>';
    classSelect.disabled = true;
    
    fetch(`${currentFile}?ajax=get_programs&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            progSelect.innerHTML = '<option value="">Select Program</option>';
            
            if (data.status === 'success' && data.programs.length > 0) {
                data.programs.forEach(program => {
                    const option = document.createElement('option');
                    option.value = program.program_id;
                    option.textContent = `${program.program_name} (${program.program_code})`;
                    progSelect.appendChild(option);
                });
                progSelect.disabled = false;
            } else {
                progSelect.innerHTML = '<option value="">No programs found</option>';
                progSelect.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error loading programs:', error);
            progSelect.innerHTML = '<option value="">Error loading</option>';
            progSelect.disabled = false;
        });
}

function onProgramChange() {
    const programId = document.getElementById('program_id').value;
    const deptId = document.getElementById('department_id').value;
    const campusId = document.getElementById('campus_id').value;
    const classSelect = document.getElementById('class_id');
    
    if (!programId || !deptId || !campusId) {
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
                    option.textContent = cls.display_name;
                    classSelect.appendChild(option);
                });
                classSelect.disabled = false;
            } else {
                classSelect.innerHTML = '<option value="">No classes found</option>';
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
    const deptSelect = document.getElementById('department_id');
    const progSelect = document.getElementById('program_id');
    const classSelect = document.getElementById('class_id');
    
    deptSelect.innerHTML = '<option value="">Select Department</option>';
    deptSelect.disabled = true;
    progSelect.innerHTML = '<option value="">Select Program</option>';
    progSelect.disabled = true;
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
    
    // Get current URL parameters
    const urlParams = new URLSearchParams(window.location.search);
    
    // Update search parameter
    if (searchValue) {
        urlParams.set('search', searchValue);
    } else {
        urlParams.delete('search');
    }
    
    // Reset to page 1 when searching
    urlParams.set('page', 1);
    
    // Navigate to new URL
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
        countElement.style.color = checkboxes.length > 0 ? 'var(--green)' : '#333';
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
    // Update selected count when checkboxes change
    document.querySelectorAll('.student-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', updateSelectedCount);
    });
    
    // Initial count update
    updateSelectedCount();
    
    // Auto-hide alert after 5 seconds
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
    
    // Focus search input if it exists
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.focus();
        searchInput.select();
    }
});

// Prevent form submission with Enter key except in form fields
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