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

// Get faculty ID from session
$faculty_id = $_SESSION['user']['linked_id'] ?? null;
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

// CSRF Protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die(json_encode(['status' => 'error', 'message' => 'CSRF token validation failed']));
    }
}

$message = "";
$type = "";

// ===========================================
// AJAX HANDLERS
// ===========================================
if (isset($_GET['ajax']) && isset($_SESSION['csrf_token'])) {
    header('Content-Type: application/json');
    
    $ajax_action = $_GET['ajax'] ?? '';
    $token = $_GET['csrf_token'] ?? '';
    
    if ($token !== $_SESSION['csrf_token']) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token']);
        exit;
    }
    
    // GET DEPARTMENTS BY CAMPUS
    if ($ajax_action == 'get_departments') {
        $campus_id = intval($_GET['campus_id'] ?? 0);
        
        if (!$campus_id) {
            echo json_encode(['status' => 'error', 'message' => 'Campus ID required']);
            exit;
        }
        
        // Check if campus belongs to this faculty
        if (!in_array($campus_id, $campus_ids)) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT department_id, department_name 
            FROM departments 
            WHERE faculty_id = ? AND campus_id = ? AND status = 'active'
            ORDER BY department_name
        ");
        $stmt->execute([$faculty_id, $campus_id]);
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'departments' => $departments]);
        exit;
    }
    
    // GET PROGRAMS BY DEPARTMENT
    if ($ajax_action == 'get_programs') {
        $department_id = intval($_GET['department_id'] ?? 0);
        $campus_id = intval($_GET['campus_id'] ?? 0);
        
        if (!$department_id || !$campus_id) {
            echo json_encode(['status' => 'error', 'message' => 'Department and Campus ID required']);
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
            WHERE department_id = ? AND faculty_id = ? AND status = 'active'
            ORDER BY program_name
        ");
        $stmt->execute([$department_id, $faculty_id]);
        $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'programs' => $programs]);
        exit;
    }
    
    // GET CLASSES BY PROGRAM (with study mode)
    if ($ajax_action == 'get_classes') {
        $program_id = intval($_GET['program_id'] ?? 0);
        $department_id = intval($_GET['department_id'] ?? 0);
        $campus_id = intval($_GET['campus_id'] ?? 0);
        
        if (!$program_id || !$department_id || !$campus_id) {
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
            WHERE program_id = ? AND department_id = ? AND faculty_id = ? AND campus_id = ? AND status = 'Active'
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
    
    echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    exit;
}

// ===========================================
// CRUD OPERATIONS
// ===========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $action = $_POST['action'];
        
        // Common fields
        $subject_code = trim(strtoupper($_POST['subject_code'] ?? ''));
        $subject_name = trim($_POST['subject_name'] ?? '');
        $credit_hours = intval($_POST['credit_hours'] ?? 3);
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'active';
        
        // Foreign keys
        $campus_id = intval($_POST['campus_id'] ?? 0);
        $department_id = intval($_POST['department_id'] ?? 0);
        $program_id = intval($_POST['program_id'] ?? 0);
        $class_id = intval($_POST['class_id'] ?? 0);
        $semester_id = intval($_POST['semester_id'] ?? 0);
        
        // Validation
        if (empty($subject_code) || empty($subject_name)) {
            throw new Exception("Subject code and name are required!");
        }
        
        if (!$campus_id || !$department_id || !$program_id || !$class_id || !$semester_id) {
            throw new Exception("All selections (Campus, Department, Program, Class, Semester) are required!");
        }
        
        // Validate campus belongs to faculty
        if (!in_array($campus_id, $campus_ids)) {
            throw new Exception("Invalid campus selected!");
        }
        
        // Validate department belongs to campus and faculty
        $check = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE department_id = ? AND campus_id = ? AND faculty_id = ?");
        $check->execute([$department_id, $campus_id, $faculty_id]);
        if ($check->fetchColumn() == 0) {
            throw new Exception("Invalid department selected!");
        }
        
        // Validate program belongs to department and faculty
        $check = $pdo->prepare("SELECT COUNT(*) FROM programs WHERE program_id = ? AND department_id = ? AND faculty_id = ?");
        $check->execute([$program_id, $department_id, $faculty_id]);
        if ($check->fetchColumn() == 0) {
            throw new Exception("Invalid program selected!");
        }
        
        // Validate class belongs to program, department, campus, faculty
        $check = $pdo->prepare("
            SELECT COUNT(*) FROM classes 
            WHERE class_id = ? AND program_id = ? AND department_id = ? AND campus_id = ? AND faculty_id = ?
        ");
        $check->execute([$class_id, $program_id, $department_id, $campus_id, $faculty_id]);
        if ($check->fetchColumn() == 0) {
            throw new Exception("Invalid class selected!");
        }
        
        // ADD SUBJECT
        if ($action === 'add') {
            // Check duplicate subject code in same class
            $check = $pdo->prepare("
                SELECT COUNT(*) FROM subject 
                WHERE subject_code = ? AND class_id = ? AND faculty_id = ?
            ");
            $check->execute([$subject_code, $class_id, $faculty_id]);
            
            if ($check->fetchColumn() > 0) {
                throw new Exception("Subject with code '$subject_code' already exists in this class!");
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO subject 
                (subject_code, subject_name, credit_hours, description, status, 
                 campus_id, faculty_id, department_id, program_id, class_id, semester_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $subject_code, $subject_name, $credit_hours, $description, $status,
                $campus_id, $faculty_id, $department_id, $program_id, $class_id, $semester_id
            ]);
            
            $message = "✅ Subject added successfully!";
            $type = "success";
        }
        
        // UPDATE SUBJECT
        if ($action === 'update') {
            $subject_id = intval($_POST['subject_id'] ?? 0);
            
            if (!$subject_id) {
                throw new Exception("Subject ID is required!");
            }
            
            // Check if subject belongs to this faculty
            $check = $pdo->prepare("SELECT COUNT(*) FROM subject WHERE subject_id = ? AND faculty_id = ?");
            $check->execute([$subject_id, $faculty_id]);
            if ($check->fetchColumn() == 0) {
                throw new Exception("Subject not found or access denied!");
            }
            
            // Check duplicate subject code in same class (excluding current)
            $check = $pdo->prepare("
                SELECT COUNT(*) FROM subject 
                WHERE subject_code = ? AND class_id = ? AND faculty_id = ? AND subject_id != ?
            ");
            $check->execute([$subject_code, $class_id, $faculty_id, $subject_id]);
            
            if ($check->fetchColumn() > 0) {
                throw new Exception("Subject with code '$subject_code' already exists in this class!");
            }
            
            $stmt = $pdo->prepare("
                UPDATE subject 
                SET subject_code = ?, subject_name = ?, credit_hours = ?, description = ?, status = ?,
                    campus_id = ?, department_id = ?, program_id = ?, class_id = ?, semester_id = ?,
                    updated_at = NOW()
                WHERE subject_id = ? AND faculty_id = ?
            ");
            $stmt->execute([
                $subject_code, $subject_name, $credit_hours, $description, $status,
                $campus_id, $department_id, $program_id, $class_id, $semester_id,
                $subject_id, $faculty_id
            ]);
            
            $message = "✅ Subject updated successfully!";
            $type = "success";
        }
        
        // DELETE SUBJECT
        if ($action === 'delete') {
            $subject_id = intval($_POST['subject_id'] ?? 0);
            
            if (!$subject_id) {
                throw new Exception("Subject ID is required!");
            }
            
            // Check if subject belongs to this faculty
            $check = $pdo->prepare("SELECT COUNT(*) FROM subject WHERE subject_id = ? AND faculty_id = ?");
            $check->execute([$subject_id, $faculty_id]);
            if ($check->fetchColumn() == 0) {
                throw new Exception("Subject not found or access denied!");
            }
            
            // Check if subject has any allocations or student grades
            $check = $pdo->prepare("
                SELECT COUNT(*) FROM subject_allocations WHERE subject_id = ?
                UNION ALL
                SELECT COUNT(*) FROM student_grades WHERE subject_id = ?
            ");
            $check->execute([$subject_id, $subject_id]);
            $counts = $check->fetchAll(PDO::FETCH_COLUMN);
            
            if (array_sum($counts) > 0) {
                throw new Exception("Cannot delete subject. It has allocations or student grades!");
            }
            
            $stmt = $pdo->prepare("DELETE FROM subject WHERE subject_id = ?");
            $stmt->execute([$subject_id]);
            
            $message = "✅ Subject deleted successfully!";
            $type = "success";
        }
        
    } catch (PDOException $e) {
        error_log("Subject Management Error: " . $e->getMessage());
        $message = "❌ Database error: " . $e->getMessage();
        $type = "error";
    } catch (Exception $e) {
        $message = "❌ " . $e->getMessage();
        $type = "error";
    }
}

// ===========================================
// FETCH DATA FOR DISPLAY
// ===========================================
// Get filter parameters
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$campus_filter = $_GET['campus'] ?? '';
$department_filter = $_GET['department'] ?? '';
$program_filter = $_GET['program'] ?? '';
$class_filter = $_GET['class'] ?? '';
$semester_filter = $_GET['semester'] ?? '';
$study_mode_filter = $_GET['study_mode'] ?? '';

// Get all departments for this faculty (for dropdown)
$departments = $pdo->prepare("
    SELECT d.department_id, d.department_name, c.campus_name 
    FROM departments d
    JOIN campus c ON d.campus_id = c.campus_id
    WHERE d.faculty_id = ? AND d.status = 'active'
    ORDER BY d.department_name
");
$departments->execute([$faculty_id]);
$departments = $departments->fetchAll(PDO::FETCH_ASSOC);

// Get all programs for this faculty (for dropdown)
$programs = $pdo->prepare("
    SELECT p.program_id, p.program_name, p.program_code, c.campus_name 
    FROM programs p
    JOIN campus c ON p.campus_id = c.campus_id
    WHERE p.faculty_id = ? AND p.status = 'active'
    ORDER BY p.program_name
");
$programs->execute([$faculty_id]);
$programs = $programs->fetchAll(PDO::FETCH_ASSOC);

// Get all classes for this faculty (for dropdown) - include study mode
$all_classes = $pdo->prepare("
    SELECT c.class_id, c.class_name, c.study_mode, camp.campus_name
    FROM classes c
    JOIN campus camp ON c.campus_id = camp.campus_id
    WHERE c.faculty_id = ? AND c.status = 'Active'
    ORDER BY c.class_name, c.study_mode
");
$all_classes->execute([$faculty_id]);
$all_classes = $all_classes->fetchAll(PDO::FETCH_ASSOC);

// Get semesters
$semesters = $pdo->query("SELECT semester_id, semester_name FROM semester ORDER BY semester_name")->fetchAll(PDO::FETCH_ASSOC);

// Build query for subjects
$query = "
    SELECT 
        s.subject_id,
        s.subject_code,
        s.subject_name,
        s.credit_hours,
        s.description,
        s.status,
        s.created_at,
        s.updated_at,
        camp.campus_id,
        camp.campus_name,
        d.department_id,
        d.department_name,
        p.program_id,
        p.program_name,
        p.program_code,
        c.class_id,
        c.class_name,
        c.study_mode,
        sem.semester_id,
        sem.semester_name
    FROM subject s
    LEFT JOIN campus camp ON s.campus_id = camp.campus_id
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN programs p ON s.program_id = p.program_id
    LEFT JOIN classes c ON s.class_id = c.class_id
    LEFT JOIN semester sem ON s.semester_id = sem.semester_id
    WHERE s.faculty_id = ?
";

$params = [$faculty_id];

// Apply filters
if (!empty($search)) {
    $query .= " AND (s.subject_name LIKE ? OR s.subject_code LIKE ? OR p.program_name LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
}

if (!empty($status_filter)) {
    $query .= " AND s.status = ?";
    $params[] = $status_filter;
}

if (!empty($campus_filter)) {
    $query .= " AND s.campus_id = ?";
    $params[] = $campus_filter;
}

if (!empty($department_filter)) {
    $query .= " AND s.department_id = ?";
    $params[] = $department_filter;
}

if (!empty($program_filter)) {
    $query .= " AND s.program_id = ?";
    $params[] = $program_filter;
}

if (!empty($class_filter)) {
    $query .= " AND s.class_id = ?";
    $params[] = $class_filter;
}

if (!empty($semester_filter)) {
    $query .= " AND s.semester_id = ?";
    $params[] = $semester_filter;
}

if (!empty($study_mode_filter)) {
    $query .= " AND c.study_mode = ?";
    $params[] = $study_mode_filter;
}

$query .= " ORDER BY s.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Course Management | Faculty Admin - <?= htmlspecialchars($faculty_name) ?> | Hormuud University</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="../images.png">
<link rel="stylesheet" href="../assets/css/header.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
  --green: #00843D;
  --light-green: #00A651;
  --blue: #0072CE;
  --red: #C62828;
  --orange: #FF9800;
  --bg: #F5F9F7;
  --dark: #333;
  --light: #f8f9fa;
  --border: #e0e0e0;
  --shadow: rgba(0, 0, 0, 0.08);
  --white: #FFFFFF;
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

/* Page Header */
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 25px;
  padding: 20px;
  background: var(--white);
  border-radius: 12px;
  box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
  border-left: 4px solid var(--green);
  flex-wrap: wrap;
  gap: 15px;
}

.page-title {
  display: flex;
  align-items: center;
  gap: 15px;
  flex-wrap: wrap;
}

.page-title h1 {
  color: var(--blue);
  font-size: 26px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 12px;
}

.page-title h1 i {
  color: var(--green);
  background: rgba(0, 132, 61, 0.1);
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

.add-btn {
  background: linear-gradient(135deg, var(--green), var(--light-green));
  color: var(--white);
  border: none;
  padding: 12px 24px;
  border-radius: 8px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.3s ease;
  display: flex;
  align-items: center;
  gap: 10px;
  box-shadow: 0 4px 12px rgba(0, 132, 61, 0.2);
}

.add-btn:hover {
  transform: translateY(-3px);
  box-shadow: 0 6px 20px rgba(0, 132, 61, 0.3);
}

/* Filters Section */
.filters-container {
  background: var(--white);
  border-radius: 10px;
  padding: 20px;
  margin-bottom: 20px;
  box-shadow: 0 3px 15px rgba(0, 0, 0, 0.08);
}

.filter-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 15px;
}

.filter-header h3 {
  color: var(--blue);
  font-size: 16px;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 8px;
}

.filter-form {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 15px;
  align-items: flex-end;
}

.filter-group {
  position: relative;
}

.filter-group label {
  display: block;
  margin-bottom: 6px;
  font-weight: 500;
  font-size: 12px;
  color: #555;
}

.filter-input {
  width: 100%;
  padding: 10px 12px;
  border: 1.5px solid #ddd;
  border-radius: 6px;
  font-size: 13px;
  transition: all 0.2s;
  background: #f9f9f9;
}

.filter-input:focus {
  outline: none;
  border-color: var(--blue);
  background: #fff;
  box-shadow: 0 0 0 3px rgba(0,114,206,0.1);
}

.filter-actions {
  display: flex;
  gap: 10px;
  align-items: flex-end;
}

.filter-btn {
  padding: 10px 16px;
  border: none;
  border-radius: 6px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s;
  display: flex;
  align-items: center;
  gap: 6px;
  white-space: nowrap;
  font-size: 13px;
}

.apply-btn {
  background: var(--green);
  color: #fff;
}

.apply-btn:hover {
  background: var(--light-green);
}

.clear-btn {
  background: #6c757d;
  color: #fff;
}

.clear-btn:hover {
  background: #5a6268;
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

/* Campus Badge */
.campus-badge {
  background: rgba(0, 132, 61, 0.1);
  color: var(--green);
  padding: 4px 8px;
  border-radius: 12px;
  font-size: 10px;
  font-weight: 600;
  display: inline-block;
  margin-left: 5px;
}

/* Status Badges */
.status-badge {
  padding: 6px 12px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 500;
  display: inline-block;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  min-width: 70px;
  text-align: center;
}

.status-active {
  background: #e8f5e8;
  color: var(--green);
  border: 1px solid rgba(0, 132, 61, 0.2);
}

.status-inactive {
  background: #ffebee;
  color: var(--red);
  border: 1px solid rgba(198, 40, 40, 0.2);
}

/* Credit Hours Badge */
.credit-badge {
  background: #e3f2fd;
  color: #1565c0;
  padding: 6px 12px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 500;
  display: inline-block;
  min-width: 50px;
  text-align: center;
}

/* Table Styles */
.table-wrapper {
  background: var(--white);
  border-radius: 10px;
  overflow: hidden;
  box-shadow: 0 3px 15px rgba(0, 0, 0, 0.08);
  margin-bottom: 30px;
}

.table-header {
  padding: 20px;
  border-bottom: 1px solid #eee;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 15px;
}

.table-header h3 {
  color: var(--dark);
  font-size: 16px;
  margin: 0;
}

.results-count {
  color: #666;
  font-size: 14px;
  text-align: right;
}

.table-responsive {
  width: 100%;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  padding: 0 20px 20px;
}

.table-responsive::-webkit-scrollbar {
  height: 8px;
}

.table-responsive::-webkit-scrollbar-track {
  background: #f1f1f1;
  border-radius: 4px;
}

.table-responsive::-webkit-scrollbar-thumb {
  background: var(--blue);
  border-radius: 4px;
}

.data-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 14px;
  min-width: 1300px;
}

.data-table th,
.data-table td {
  padding: 14px 15px;
  border-bottom: 1px solid #eee;
  vertical-align: middle;
  white-space: nowrap;
}

.data-table td:nth-child(2),
.data-table td:nth-child(3) {
  white-space: normal;
  min-width: 150px;
  max-width: 200px;
}

.data-table th {
  position: sticky;
  top: 0;
  background: linear-gradient(135deg, var(--blue), var(--green));
  color: var(--white);
  font-weight: 600;
  z-index: 10;
}

.data-table tbody tr:hover {
  background: #f9f9f9;
}

.data-table tbody tr:nth-child(even) {
  background: rgba(0, 114, 206, 0.02);
}

/* Action Buttons */
.action-btns {
  display: flex;
  gap: 8px;
  justify-content: center;
  flex-wrap: nowrap;
  min-width: 90px;
}

.action-btn {
  min-width: 36px;
  height: 36px;
  border-radius: 8px;
  border: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s ease;
  font-size: 14px;
  box-shadow: 0 3px 8px rgba(0, 0, 0, 0.1);
  flex-shrink: 0;
}

.edit-btn {
  background: var(--blue);
  color: var(--white);
}

.edit-btn:hover {
  background: #005fa3;
  transform: translateY(-2px);
}

.del-btn {
  background: var(--red);
  color: var(--white);
}

.del-btn:hover {
  background: #b71c1c;
  transform: translateY(-2px);
}

/* Mobile Card View */
.mobile-card-view {
  display: none;
  padding: 15px;
}

.mobile-card-view.show {
  display: block;
}

.subject-card {
  background: #fff;
  border-radius: 10px;
  padding: 15px;
  margin-bottom: 15px;
  box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
  border-left: 4px solid var(--green);
}

.subject-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 12px;
  padding-bottom: 12px;
  border-bottom: 1px solid #eee;
}

.subject-title {
  font-size: 16px;
  font-weight: 600;
  color: var(--dark);
  margin-bottom: 5px;
}

.subject-code {
  font-size: 14px;
  color: var(--blue);
  font-weight: 500;
  margin-bottom: 8px;
}

.subject-meta {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 10px;
}

.subject-details {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 12px;
  margin-bottom: 15px;
}

.detail-item {
  font-size: 13px;
}

.detail-label {
  color: #666;
  font-weight: 500;
  margin-bottom: 3px;
  font-size: 12px;
}

.detail-value {
  color: var(--dark);
  font-weight: 400;
}

.subject-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  border-top: 1px solid #eee;
  padding-top: 15px;
}

.mobile-action-btn {
  padding: 8px 15px;
  border-radius: 6px;
  border: none;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 6px;
  transition: all 0.2s;
  min-width: 80px;
  justify-content: center;
}

.mobile-edit-btn {
  background: var(--blue);
  color: white;
}

.mobile-edit-btn:hover {
  background: #005fa3;
}

.mobile-delete-btn {
  background: var(--red);
  color: white;
}

.mobile-delete-btn:hover {
  background: #b71c1c;
}

/* Empty State */
.empty-state {
  text-align: center;
  padding: 80px 20px;
  color: #666;
}

.empty-state i {
  font-size: 64px;
  margin-bottom: 25px;
  color: #ddd;
  display: block;
  opacity: 0.6;
}

.empty-state h3 {
  font-size: 20px;
  margin-bottom: 15px;
  color: #888;
}

.empty-state .add-first-btn {
  background: var(--green);
  color: white;
  border: none;
  padding: 12px 30px;
  border-radius: 30px;
  font-weight: 600;
  cursor: pointer;
  margin-top: 20px;
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

/* Modal Styles */
.modal {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.6);
  justify-content: center;
  align-items: center;
  z-index: 1000;
  padding: 20px;
  backdrop-filter: blur(5px);
  animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.modal.show {
  display: flex;
}

.modal-content {
  background: var(--white);
  border-radius: 16px;
  width: 100%;
  max-width: 800px;
  max-height: 90vh;
  overflow-y: auto;
  padding: 35px;
  position: relative;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
  border-top: 4px solid var(--green);
  animation: slideUp 0.4s ease;
}

@keyframes slideUp {
  from {
    transform: translateY(50px);
    opacity: 0;
  }
  to {
    transform: translateY(0);
    opacity: 1;
  }
}

.close-modal {
  position: absolute;
  top: 15px;
  right: 20px;
  font-size: 28px;
  color: #888;
  cursor: pointer;
  width: 40px;
  height: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  transition: all 0.3s ease;
  background: rgba(0, 0, 0, 0.05);
}

.close-modal:hover {
  background: rgba(0, 0, 0, 0.1);
  color: var(--red);
  transform: rotate(90deg);
}

.modal-content h2 {
  color: var(--blue);
  margin-bottom: 30px;
  font-size: 24px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 12px;
  padding-bottom: 15px;
  border-bottom: 2px solid #f0f0f0;
}

.modal-content h2 i {
  color: var(--green);
  background: rgba(0, 132, 61, 0.1);
  padding: 10px;
  border-radius: 10px;
}

/* Form Styles */
.form-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
  gap: 25px;
  margin-bottom: 25px;
}

.form-group {
  margin-bottom: 25px;
}

.form-group label {
  display: block;
  margin-bottom: 10px;
  font-weight: 500;
  color: var(--dark);
  font-size: 14px;
  position: relative;
  padding-left: 5px;
}

.form-group label::after {
  content: '';
  position: absolute;
  left: 0;
  top: 2px;
  height: 16px;
  width: 3px;
  background: var(--green);
  border-radius: 3px;
}

.form-control {
  width: 100%;
  padding: 12px 18px;
  border: 2px solid #e0e0e0;
  border-radius: 8px;
  font-size: 14px;
  transition: all 0.3s ease;
  background: #fafafa;
}

.form-control:focus {
  outline: none;
  border-color: var(--blue);
  background: var(--white);
  box-shadow: 0 0 0 4px rgba(0, 114, 206, 0.1);
  transform: translateY(-2px);
}

textarea.form-control {
  min-height: 100px;
  resize: vertical;
  grid-column: 1 / -1;
}

.required::after {
  content: " *";
  color: var(--red);
}

.submit-btn {
  background: linear-gradient(135deg, var(--green), var(--light-green));
  color: var(--white);
  border: none;
  padding: 15px 30px;
  border-radius: 10px;
  font-weight: 600;
  font-size: 16px;
  cursor: pointer;
  transition: all 0.3s ease;
  margin-top: 20px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  box-shadow: 0 6px 20px rgba(0, 132, 61, 0.2);
  position: relative;
  overflow: hidden;
  grid-column: 1 / -1;
}

.submit-btn::before {
  content: '';
  position: absolute;
  top: 0;
  left: -100%;
  width: 100%;
  height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
  transition: 0.5s;
}

.submit-btn:hover {
  transform: translateY(-3px);
  box-shadow: 0 10px 25px rgba(0, 132, 61, 0.3);
}

.submit-btn:hover::before {
  left: 100%;
}

.delete-btn {
  background: linear-gradient(135deg, var(--red), #e53935);
}

.delete-btn:hover {
  box-shadow: 0 10px 25px rgba(198, 40, 40, 0.3);
}

/* Alert Popup */
.alert-popup {
  display: none;
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  background: var(--white);
  border-radius: 12px;
  padding: 30px 35px;
  text-align: center;
  z-index: 1100;
  box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2);
  min-width: 350px;
  animation: alertSlideIn 0.4s ease;
  border-top: 5px solid;
}

@keyframes alertSlideIn {
  from {
    opacity: 0;
    transform: translate(-50%, -60px) scale(0.9);
  }
  to {
    opacity: 1;
    transform: translate(-50%, -50%) scale(1);
  }
}

.alert-popup.show {
  display: block;
}

.alert-popup.success {
  border-top-color: var(--green);
}

.alert-popup.error {
  border-top-color: var(--red);
}

.alert-popup i {
  font-size: 48px;
  margin-bottom: 15px;
  display: block;
}

.alert-popup.success i {
  color: var(--green);
}

.alert-popup.error i {
  color: var(--red);
}

.alert-popup h3 {
  margin: 10px 0 5px;
  font-size: 20px;
  color: var(--dark);
}

.alert-popup p {
  margin: 0;
  color: #666;
  font-size: 14px;
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
    align-items: stretch;
    gap: 15px;
    padding: 15px;
  }
  
  .page-title h1 {
    font-size: 22px;
  }
  
  .add-btn {
    width: 100%;
    justify-content: center;
  }
  
  .filter-form {
    grid-template-columns: 1fr;
  }
  
  .filter-actions {
    flex-direction: column;
  }
  
  .filter-btn {
    width: 100%;
    justify-content: center;
  }
  
  .modal-content {
    padding: 20px 15px;
    max-width: 95%;
  }
  
  .form-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 576px) {
  .table-responsive {
    display: none;
  }
  
  .mobile-card-view {
    display: block;
  }
  
  .subject-details {
    grid-template-columns: 1fr;
    gap: 10px;
  }
  
  .subject-actions {
    flex-direction: column;
  }
  
  .mobile-action-btn {
    width: 100%;
  }
  
  .alert-popup {
    min-width: 280px;
    padding: 20px;
  }
}
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
  <!-- Page Header -->
  <div class="page-header">
    <div class="page-title">
      <h1><i class="fas fa-book-open"></i> Course Management</h1>
    </div>
    <button class="add-btn" onclick="openModal('addModal')">
      <i class="fa-solid fa-plus"></i> Add New Course
    </button>
  </div>
  
  <!-- Filters Section -->
  <div class="filters-container">
    <div class="filter-header">
      <h3><i class="fas fa-filter"></i> Filter Courses</h3>
    </div>
    
    <form method="GET" class="filter-form" id="filterForm">
      <div class="filter-group">
        <label for="search">Search</label>
        <input type="text" id="search" name="search" class="filter-input" 
               placeholder="Search by name, code, program..." 
               value="<?= htmlspecialchars($search) ?>">
      </div>
      
      <div class="filter-group">
        <label for="status">Status</label>
        <select id="status" name="status" class="filter-input">
          <option value="">All Status</option>
          <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
      
      <div class="filter-group">
        <label for="campus">Campus</label>
        <select id="campus" name="campus" class="filter-input">
          <option value="">All Campuses</option>
          <?php foreach($faculty_campuses as $c): ?>
            <option value="<?= $c['campus_id'] ?>" <?= $campus_filter == $c['campus_id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($c['campus_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      
      <div class="filter-group">
        <label for="department">Department</label>
        <select id="department" name="department" class="filter-input">
          <option value="">All Departments</option>
          <?php foreach($departments as $dept): ?>
            <option value="<?= $dept['department_id'] ?>" <?= $department_filter == $dept['department_id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($dept['department_name']) ?> (<?= htmlspecialchars($dept['campus_name']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      
      <div class="filter-group">
        <label for="program">Program</label>
        <select id="program" name="program" class="filter-input">
          <option value="">All Programs</option>
          <?php foreach($programs as $prog): ?>
            <option value="<?= $prog['program_id'] ?>" <?= $program_filter == $prog['program_id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($prog['program_name']) ?> (<?= htmlspecialchars($prog['campus_name']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      
      <div class="filter-group">
        <label for="class">Class</label>
        <select id="class" name="class" class="filter-input">
          <option value="">All Classes</option>
          <?php foreach($all_classes as $cls): ?>
            <option value="<?= $cls['class_id'] ?>" <?= $class_filter == $cls['class_id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($cls['class_name']) ?> (<?= htmlspecialchars($cls['study_mode']) ?>) - <?= htmlspecialchars($cls['campus_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      
      <div class="filter-group">
        <label for="study_mode">Study Mode</label>
        <select id="study_mode" name="study_mode" class="filter-input">
          <option value="">All Modes</option>
          <option value="Full-Time" <?= $study_mode_filter === 'Full-Time' ? 'selected' : '' ?>>Full-Time</option>
          <option value="Part-Time" <?= $study_mode_filter === 'Part-Time' ? 'selected' : '' ?>>Part-Time</option>
        </select>
      </div>
      
      <div class="filter-group">
        <label for="semester">Semester</label>
        <select id="semester" name="semester" class="filter-input">
          <option value="">All Semesters</option>
          <?php foreach($semesters as $sem): ?>
            <option value="<?= $sem['semester_id'] ?>" <?= $semester_filter == $sem['semester_id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($sem['semester_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      
      <div class="filter-actions">
        <button type="submit" class="filter-btn apply-btn">
          <i class="fas fa-filter"></i> Apply
        </button>
        <button type="button" class="filter-btn clear-btn" onclick="clearFilters()">
          <i class="fas fa-times"></i> Clear
        </button>
      </div>
    </form>
  </div>
  
  <!-- Courses Table -->
  <div class="table-wrapper">
    <div class="table-header">
      <h3>Course List</h3>
      <div class="results-count">
        Showing <?= count($subjects) ?> course<?= count($subjects) != 1 ? 's' : '' ?>
      </div>
    </div>
    
    <!-- Desktop/Tablet View -->
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Code</th>
            <th>Name</th>
            <th>Credit</th>
            <th>Class & Mode</th>
            <th>Semester</th>
            <th>Program</th>
            <th>Department</th>
            <th>Campus</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if($subjects): ?>
            <?php foreach($subjects as $i => $s): ?>
            <tr>
              <td><?= $i+1 ?></td>
              <td><strong><?= htmlspecialchars($s['subject_code']) ?></strong></td>
              <td><?= htmlspecialchars($s['subject_name']) ?></td>
              <td><span class="credit-badge"><?= $s['credit_hours'] ?> CH</span></td>
              <td>
                <?= htmlspecialchars($s['class_name'] ?? 'N/A') ?>
                <?php if(!empty($s['study_mode'])): ?>
                  <span class="study-mode-badge study-mode-<?= strtolower($s['study_mode']) ?>">
                    <?= htmlspecialchars($s['study_mode']) ?>
                  </span>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($s['semester_name'] ?? 'N/A') ?></td>
              <td><?= htmlspecialchars($s['program_name'] ?? 'N/A') ?></td>
              <td><?= htmlspecialchars($s['department_name'] ?? 'N/A') ?></td>
              <td>
                <?= htmlspecialchars($s['campus_name'] ?? 'N/A') ?>
                <?php if(!empty($s['campus_name'])): ?>
                  <span class="campus-badge">Campus</span>
                <?php endif; ?>
              </td>
              <td>
                <span class="status-badge status-<?= $s['status'] ?>">
                  <?= $s['status'] ?>
                </span>
              </td>
              <td>
                <div class="action-btns">
                  <button class="action-btn edit-btn" 
                          onclick='editSubject(<?= json_encode($s) ?>)'
                          title="Edit">
                    <i class="fa-solid fa-pen"></i>
                  </button>
                  <button class="action-btn del-btn" 
                          onclick='openDeleteModal(<?= $s['subject_id'] ?>, "<?= htmlspecialchars(addslashes($s['subject_name'])) ?>")' 
                          title="Delete">
                    <i class="fa-solid fa-trash"></i>
                  </button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="11">
                <div class="empty-state">
                  <i class="fas fa-book-open"></i>
                  <h3>No courses found</h3>
                  <p>Click "Add New Course" to create your first course</p>
                </div>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    
    <!-- Mobile Card View -->
    <div class="mobile-card-view">
      <?php if($subjects): ?>
        <?php foreach($subjects as $s): ?>
        <div class="subject-card">
          <div class="subject-header">
            <div>
              <div class="subject-title"><?= htmlspecialchars($s['subject_name']) ?></div>
              <div class="subject-code"><?= htmlspecialchars($s['subject_code']) ?></div>
              <div class="subject-meta">
                <span class="credit-badge"><?= $s['credit_hours'] ?> CH</span>
                <span class="status-badge status-<?= $s['status'] ?>"><?= $s['status'] ?></span>
              </div>
            </div>
          </div>
          
          <div class="subject-details">
            <div class="detail-item">
              <div class="detail-label">Class</div>
              <div class="detail-value">
                <?= htmlspecialchars($s['class_name'] ?? 'N/A') ?>
                <?php if(!empty($s['study_mode'])): ?>
                  (<?= htmlspecialchars($s['study_mode']) ?>)
                <?php endif; ?>
              </div>
            </div>
            
            <div class="detail-item">
              <div class="detail-label">Semester</div>
              <div class="detail-value"><?= htmlspecialchars($s['semester_name'] ?? 'N/A') ?></div>
            </div>
            
            <div class="detail-item">
              <div class="detail-label">Program</div>
              <div class="detail-value"><?= htmlspecialchars($s['program_name'] ?? 'N/A') ?></div>
            </div>
            
            <div class="detail-item">
              <div class="detail-label">Department</div>
              <div class="detail-value"><?= htmlspecialchars($s['department_name'] ?? 'N/A') ?></div>
            </div>
            
            <div class="detail-item">
              <div class="detail-label">Campus</div>
              <div class="detail-value"><?= htmlspecialchars($s['campus_name'] ?? 'N/A') ?></div>
            </div>
          </div>
          
          <div class="subject-actions">
            <button class="mobile-action-btn mobile-edit-btn" 
                    onclick='editSubject(<?= json_encode($s) ?>)'>
              <i class="fa-solid fa-pen"></i> Edit
            </button>
            <button class="mobile-action-btn mobile-delete-btn" 
                    onclick='openDeleteModal(<?= $s['subject_id'] ?>, "<?= htmlspecialchars(addslashes($s['subject_name'])) ?>")'>
              <i class="fa-solid fa-trash"></i> Delete
            </button>
          </div>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="empty-state">
          <i class="fas fa-book-open"></i>
          <h3>No courses found</h3>
          <p>Click "Add New Course" to create your first course</p>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ADD MODAL -->
<div class="modal" id="addModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('addModal')">&times;</span>
    <h2><i class="fas fa-plus-circle"></i> Add New Course</h2>
    
    <form method="POST" id="addForm" onsubmit="return validateForm('add')">
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
      
      <div class="form-grid">
        <div class="form-group">
          <label class="required">Course Code</label>
          <input type="text" name="subject_code" class="form-control" required 
                 placeholder="e.g., CS101" maxlength="20" style="text-transform:uppercase">
        </div>
        
        <div class="form-group">
          <label class="required">Course Name</label>
          <input type="text" name="subject_name" class="form-control" required 
                 placeholder="e.g., Introduction to Programming" maxlength="100">
        </div>
        
        <div class="form-group">
          <label class="required">Credit Hours</label>
          <select name="credit_hours" class="form-control" required>
            <option value="1">1 Credit</option>
            <option value="2">2 Credits</option>
            <option value="3" selected>3 Credits</option>
            <option value="4">4 Credits</option>
            <option value="5">5 Credits</option>
            <option value="6">6 Credits</option>
          </select>
        </div>
        
        <div class="form-group">
          <label class="required">Status</label>
          <select name="status" class="form-control" required>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
        
        <div class="form-group">
          <label class="required">Campus</label>
          <select id="add_campus" name="campus_id" class="form-control" required 
                  onchange="loadDepartments('add')">
            <option value="">Select Campus</option>
            <?php foreach($faculty_campuses as $c): ?>
              <option value="<?= $c['campus_id'] ?>">
                <?= htmlspecialchars($c['campus_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="form-group">
          <label class="required">Department</label>
          <select id="add_department" name="department_id" class="form-control" required 
                  onchange="loadPrograms('add')" disabled>
            <option value="">Select Campus First</option>
          </select>
        </div>
        
        <div class="form-group">
          <label class="required">Program</label>
          <select id="add_program" name="program_id" class="form-control" required 
                  onchange="loadClasses('add')" disabled>
            <option value="">Select Department First</option>
          </select>
        </div>
        
        <div class="form-group">
          <label class="required">Class</label>
          <select id="add_class" name="class_id" class="form-control" required disabled>
            <option value="">Select Program First</option>
          </select>
        </div>
        
        <div class="form-group">
          <label class="required">Semester</label>
          <select name="semester_id" class="form-control" required>
            <option value="">Select Semester</option>
            <?php foreach($semesters as $sem): ?>
              <option value="<?= $sem['semester_id'] ?>">
                <?= htmlspecialchars($sem['semester_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="form-group" style="grid-column: 1 / -1;">
          <label>Description</label>
          <textarea name="description" class="form-control" rows="3" 
                    placeholder="Optional description..."></textarea>
        </div>
      </div>
      
      <button class="submit-btn" type="submit">
        <i class="fas fa-save"></i> Save Course
      </button>
    </form>
  </div>
</div>

<!-- EDIT MODAL -->
<div class="modal" id="editModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('editModal')">&times;</span>
    <h2><i class="fas fa-edit"></i> Edit Course</h2>
    
    <form method="POST" id="editForm" onsubmit="return validateForm('edit')">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
      <input type="hidden" id="edit_id" name="subject_id">
      
      <div class="form-grid">
        <div class="form-group">
          <label class="required">Course Code</label>
          <input type="text" id="edit_code" name="subject_code" class="form-control" required 
                 style="text-transform:uppercase">
        </div>
        
        <div class="form-group">
          <label class="required">Course Name</label>
          <input type="text" id="edit_name" name="subject_name" class="form-control" required>
        </div>
        
        <div class="form-group">
          <label class="required">Credit Hours</label>
          <select id="edit_credit" name="credit_hours" class="form-control" required>
            <option value="1">1 Credit</option>
            <option value="2">2 Credits</option>
            <option value="3">3 Credits</option>
            <option value="4">4 Credits</option>
            <option value="5">5 Credits</option>
            <option value="6">6 Credits</option>
          </select>
        </div>
        
        <div class="form-group">
          <label class="required">Status</label>
          <select id="edit_status" name="status" class="form-control" required>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
        
        <div class="form-group">
          <label class="required">Campus</label>
          <select id="edit_campus" name="campus_id" class="form-control" required 
                  onchange="loadDepartments('edit')">
            <option value="">Select Campus</option>
            <?php foreach($faculty_campuses as $c): ?>
              <option value="<?= $c['campus_id'] ?>">
                <?= htmlspecialchars($c['campus_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="form-group">
          <label class="required">Department</label>
          <select id="edit_department" name="department_id" class="form-control" required 
                  onchange="loadPrograms('edit')" disabled>
            <option value="">Select Campus First</option>
          </select>
        </div>
        
        <div class="form-group">
          <label class="required">Program</label>
          <select id="edit_program" name="program_id" class="form-control" required 
                  onchange="loadClasses('edit')" disabled>
            <option value="">Select Department First</option>
          </select>
        </div>
        
        <div class="form-group">
          <label class="required">Class</label>
          <select id="edit_class" name="class_id" class="form-control" required disabled>
            <option value="">Select Program First</option>
          </select>
        </div>
        
        <div class="form-group">
          <label class="required">Semester</label>
          <select id="edit_semester" name="semester_id" class="form-control" required>
            <option value="">Select Semester</option>
            <?php foreach($semesters as $sem): ?>
              <option value="<?= $sem['semester_id'] ?>">
                <?= htmlspecialchars($sem['semester_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="form-group" style="grid-column: 1 / -1;">
          <label>Description</label>
          <textarea id="edit_desc" name="description" class="form-control" rows="3"></textarea>
        </div>
      </div>
      
      <button class="submit-btn" type="submit">
        <i class="fas fa-sync-alt"></i> Update Course
      </button>
    </form>
  </div>
</div>

<!-- DELETE MODAL -->
<div class="modal" id="deleteModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('deleteModal')">&times;</span>
    <h2 style="color: var(--red);"><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h2>
    
    <form method="POST">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
      <input type="hidden" name="subject_id" id="delete_id">
      
      <div style="text-align: center; margin: 30px 0;">
        <i class="fas fa-exclamation-triangle" style="font-size: 48px; color: var(--red); margin-bottom: 20px;"></i>
        <p style="font-size: 16px; margin-bottom: 10px;" id="delete_message">
          Are you sure you want to delete this course?
        </p>
        <p style="color: #666; font-size: 14px;">
          This action cannot be undone.
        </p>
      </div>
      
      <button class="submit-btn delete-btn" type="submit">
        <i class="fas fa-trash-alt"></i> Yes, Delete
      </button>
    </form>
  </div>
</div>

<!-- ALERT POPUP -->
<div id="popup" class="alert-popup <?= $type ?> <?= !empty($message) ? 'show' : '' ?>">
  <i class="fas <?= $type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
  <h3><?= $type === 'success' ? 'Success!' : 'Error!' ?></h3>
  <p><?= htmlspecialchars($message) ?></p>
</div>

<script>
const csrfToken = '<?= $_SESSION['csrf_token'] ?>';

// ===========================================
// RESPONSIVE LAYOUT
// ===========================================
function handleResponsive() {
  const table = document.querySelector('.table-responsive');
  const mobile = document.querySelector('.mobile-card-view');
  
  if (window.innerWidth <= 576) {
    if (table) table.style.display = 'none';
    if (mobile) mobile.classList.add('show');
  } else {
    if (table) table.style.display = 'block';
    if (mobile) mobile.classList.remove('show');
  }
}

window.addEventListener('DOMContentLoaded', handleResponsive);
window.addEventListener('resize', handleResponsive);

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

function openDeleteModal(id, name) {
  document.getElementById('delete_id').value = id;
  document.getElementById('delete_message').innerHTML = 
    `Are you sure you want to delete the course <strong>"${name}"</strong>?`;
  openModal('deleteModal');
}

function editSubject(data) {
  openModal('editModal');
  
  document.getElementById('edit_id').value = data.subject_id;
  document.getElementById('edit_code').value = data.subject_code;
  document.getElementById('edit_name').value = data.subject_name;
  document.getElementById('edit_credit').value = data.credit_hours || 3;
  document.getElementById('edit_status').value = data.status;
  document.getElementById('edit_desc').value = data.description || '';
  document.getElementById('edit_semester').value = data.semester_id || '';
  
  if (data.campus_id) {
    document.getElementById('edit_campus').value = data.campus_id;
    loadDepartments('edit', data.department_id, data.program_id, data.class_id);
  }
}

// Close on outside click
window.addEventListener('click', function(e) {
  if (e.target.classList.contains('modal')) {
    e.target.classList.remove('show');
    document.body.style.overflow = 'auto';
  }
});

// Close on Escape key
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal.show').forEach(modal => {
      modal.classList.remove('show');
      document.body.style.overflow = 'auto';
    });
  }
  
  if (e.ctrlKey && e.key === 'n') {
    e.preventDefault();
    openModal('addModal');
  }
  
  if (e.ctrlKey && e.key === 'f') {
    e.preventDefault();
    document.getElementById('search').focus();
  }
});

// ===========================================
// AJAX FUNCTIONS
// ===========================================
function loadDepartments(type, selectedDept = null, selectedProgram = null, selectedClass = null) {
  const campusId = type === 'add' ? 
    document.getElementById('add_campus').value : 
    document.getElementById('edit_campus').value;
  
  const deptSelect = type === 'add' ? 
    document.getElementById('add_department') : 
    document.getElementById('edit_department');
  
  const progSelect = type === 'add' ? 
    document.getElementById('add_program') : 
    document.getElementById('edit_program');
  
  const classSelect = type === 'add' ? 
    document.getElementById('add_class') : 
    document.getElementById('edit_class');
  
  if (!campusId) {
    deptSelect.innerHTML = '<option value="">Select Campus First</option>';
    deptSelect.disabled = true;
    progSelect.innerHTML = '<option value="">Select Department First</option>';
    progSelect.disabled = true;
    classSelect.innerHTML = '<option value="">Select Program First</option>';
    classSelect.disabled = true;
    return;
  }
  
  deptSelect.innerHTML = '<option value="">Loading...</option>';
  deptSelect.disabled = true;
  progSelect.innerHTML = '<option value="">Select Department First</option>';
  progSelect.disabled = true;
  classSelect.innerHTML = '<option value="">Select Program First</option>';
  classSelect.disabled = true;
  
  fetch(`?ajax=get_departments&campus_id=${campusId}&csrf_token=${csrfToken}`)
    .then(response => response.json())
    .then(data => {
      if (data.status === 'success') {
        deptSelect.innerHTML = '<option value="">Select Department</option>';
        data.departments.forEach(dept => {
          const option = document.createElement('option');
          option.value = dept.department_id;
          option.textContent = dept.department_name;
          deptSelect.appendChild(option);
        });
        deptSelect.disabled = false;
        
        if (selectedDept) {
          deptSelect.value = selectedDept;
          loadPrograms(type, selectedProgram, selectedClass);
        }
      } else {
        deptSelect.innerHTML = '<option value="">No departments found</option>';
        deptSelect.disabled = false;
      }
    })
    .catch(() => {
      deptSelect.innerHTML = '<option value="">Error loading</option>';
      deptSelect.disabled = false;
    });
}

function loadPrograms(type, selectedProgram = null, selectedClass = null) {
  const campusId = type === 'add' ? 
    document.getElementById('add_campus').value : 
    document.getElementById('edit_campus').value;
  
  const deptId = type === 'add' ? 
    document.getElementById('add_department').value : 
    document.getElementById('edit_department').value;
  
  const progSelect = type === 'add' ? 
    document.getElementById('add_program') : 
    document.getElementById('edit_program');
  
  const classSelect = type === 'add' ? 
    document.getElementById('add_class') : 
    document.getElementById('edit_class');
  
  if (!campusId || !deptId) {
    progSelect.innerHTML = '<option value="">Select Department First</option>';
    progSelect.disabled = true;
    classSelect.innerHTML = '<option value="">Select Program First</option>';
    classSelect.disabled = true;
    return;
  }
  
  progSelect.innerHTML = '<option value="">Loading...</option>';
  progSelect.disabled = true;
  classSelect.innerHTML = '<option value="">Select Program First</option>';
  classSelect.disabled = true;
  
  fetch(`?ajax=get_programs&department_id=${deptId}&campus_id=${campusId}&csrf_token=${csrfToken}`)
    .then(response => response.json())
    .then(data => {
      if (data.status === 'success') {
        progSelect.innerHTML = '<option value="">Select Program</option>';
        data.programs.forEach(prog => {
          const option = document.createElement('option');
          option.value = prog.program_id;
          option.textContent = `${prog.program_name} (${prog.program_code})`;
          progSelect.appendChild(option);
        });
        progSelect.disabled = false;
        
        if (selectedProgram) {
          progSelect.value = selectedProgram;
          loadClasses(type, selectedClass);
        }
      } else {
        progSelect.innerHTML = '<option value="">No programs found</option>';
        progSelect.disabled = false;
      }
    })
    .catch(() => {
      progSelect.innerHTML = '<option value="">Error loading</option>';
      progSelect.disabled = false;
    });
}

function loadClasses(type, selectedClass = null) {
  const campusId = type === 'add' ? 
    document.getElementById('add_campus').value : 
    document.getElementById('edit_campus').value;
  
  const deptId = type === 'add' ? 
    document.getElementById('add_department').value : 
    document.getElementById('edit_department').value;
  
  const progId = type === 'add' ? 
    document.getElementById('add_program').value : 
    document.getElementById('edit_program').value;
  
  const classSelect = type === 'add' ? 
    document.getElementById('add_class') : 
    document.getElementById('edit_class');
  
  if (!campusId || !deptId || !progId) {
    classSelect.innerHTML = '<option value="">Select Program First</option>';
    classSelect.disabled = true;
    return;
  }
  
  classSelect.innerHTML = '<option value="">Loading...</option>';
  classSelect.disabled = true;
  
  fetch(`?ajax=get_classes&program_id=${progId}&department_id=${deptId}&campus_id=${campusId}&csrf_token=${csrfToken}`)
    .then(response => response.json())
    .then(data => {
      if (data.status === 'success') {
        classSelect.innerHTML = '<option value="">Select Class</option>';
        data.classes.forEach(cls => {
          const option = document.createElement('option');
          option.value = cls.class_id;
          option.textContent = cls.display_name; // Shows "BSE1 (Full-Time)"
          classSelect.appendChild(option);
        });
        classSelect.disabled = false;
        
        if (selectedClass) {
          classSelect.value = selectedClass;
        }
      } else {
        classSelect.innerHTML = '<option value="">No classes found</option>';
        classSelect.disabled = false;
      }
    })
    .catch(() => {
      classSelect.innerHTML = '<option value="">Error loading</option>';
      classSelect.disabled = false;
    });
}

// ===========================================
// FILTER FUNCTIONS
// ===========================================
let searchTimeout;
document.getElementById('search').addEventListener('input', function() {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => {
    document.getElementById('filterForm').submit();
  }, 500);
});

const filterSelects = document.querySelectorAll('#status, #campus, #department, #program, #class, #study_mode, #semester');
filterSelects.forEach(select => {
  select.addEventListener('change', function() {
    document.getElementById('filterForm').submit();
  });
});

function clearFilters() {
  document.getElementById('search').value = '';
  document.getElementById('status').value = '';
  document.getElementById('campus').value = '';
  document.getElementById('department').value = '';
  document.getElementById('program').value = '';
  document.getElementById('class').value = '';
  document.getElementById('study_mode').value = '';
  document.getElementById('semester').value = '';
  document.getElementById('filterForm').submit();
}

// ===========================================
// FORM VALIDATION
// ===========================================
function validateForm(type) {
  const campus = type === 'add' ? 
    document.getElementById('add_campus').value : 
    document.getElementById('edit_campus').value;
  
  const dept = type === 'add' ? 
    document.getElementById('add_department').value : 
    document.getElementById('edit_department').value;
  
  const prog = type === 'add' ? 
    document.getElementById('add_program').value : 
    document.getElementById('edit_program').value;
  
  const cls = type === 'add' ? 
    document.getElementById('add_class').value : 
    document.getElementById('edit_class').value;
  
  const sem = type === 'add' ? 
    document.querySelector(`#${type}Form select[name="semester_id"]`).value : 
    document.getElementById('edit_semester').value;
  
  if (!campus) {
    alert('Please select a campus!');
    return false;
  }
  
  if (!dept) {
    alert('Please select a department!');
    return false;
  }
  
  if (!prog) {
    alert('Please select a program!');
    return false;
  }
  
  if (!cls) {
    alert('Please select a class!');
    return false;
  }
  
  if (!sem) {
    alert('Please select a semester!');
    return false;
  }
  
  return true;
}

// Auto-hide alert
setTimeout(() => {
  const alert = document.querySelector('.alert-popup.show');
  if (alert) alert.classList.remove('show');
}, 5000);
</script>

<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>