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

// Only department_admin can access
$user_role = strtolower($_SESSION['user']['role'] ?? '');
if ($user_role !== 'department_admin') {
    header("Location: ../login.php");
    exit;
}

// Get user's department info
$linked_id = $_SESSION['user']['linked_id'] ?? null;
$linked_table = $_SESSION['user']['linked_table'] ?? null;

if ($linked_table !== 'department' || !$linked_id) {
    // Invalid department admin
    header("Location: ../login.php");
    exit;
}

$user_department_id = $linked_id;

// Get department details
try {
    $stmt = $pdo->prepare("
        SELECT d.*, f.faculty_name, c.campus_name, c.campus_id, f.faculty_id
        FROM departments d
        LEFT JOIN faculties f ON d.faculty_id = f.faculty_id
        LEFT JOIN campus c ON d.campus_id = c.campus_id
        WHERE d.department_id = ?
    ");
    $stmt->execute([$user_department_id]);
    $department_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$department_info) {
        header("Location: ../login.php");
        exit;
    }
    
    $user_campus_id = $department_info['campus_id'];
    $user_faculty_id = $department_info['faculty_id'];
    $user_campus_name = $department_info['campus_name'];
    $user_faculty_name = $department_info['faculty_name'];
    $user_department_name = $department_info['department_name'];
    
} catch (Exception $e) {
    error_log("Department Info Error: " . $e->getMessage());
    header("Location: ../login.php");
    exit;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die(json_encode(['status' => 'error', 'message' => 'CSRF token validation failed']));
    }
}

// ===========================================
// AJAX HANDLER
// ===========================================
if (isset($_GET['ajax_action']) && isset($_SESSION['csrf_token'])) {
    header('Content-Type: application/json');
    
    $action = $_GET['ajax_action'] ?? '';
    $token = $_GET['csrf_token'] ?? '';
    
    if ($token !== $_SESSION['csrf_token']) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token']);
        exit;
    }
    
    if ($action === 'get_programs_by_department') {
        $department_id = intval($_GET['department_id'] ?? 0);
        
        // Check if user has access to this department
        if ($department_id != $user_department_id) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare("
                SELECT program_id, program_name, program_code 
                FROM programs 
                WHERE department_id = ? AND status = 'active'
                ORDER BY program_name
            ");
            $stmt->execute([$department_id]);
            $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'status' => 'success', 
                'programs' => $programs,
                'csrf_token' => $_SESSION['csrf_token']
            ]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error']);
        }
        exit;
    }
    
    if ($action === 'get_filtered_classes') {
        $program_id = intval($_GET['program_id'] ?? 0);
        $study_mode = in_array($_GET['study_mode'] ?? '', ['Full-Time', 'Part-Time']) ? $_GET['study_mode'] : '';
        $status = in_array($_GET['status'] ?? '', ['Active', 'Inactive']) ? $_GET['status'] : '';
        
        try {
            $sql = "
                SELECT c.*, 
                       camp.campus_name,
                       f.faculty_name, 
                       d.department_name,
                       p.program_name,
                       p.program_code
                FROM classes c
                LEFT JOIN campus camp ON c.campus_id = camp.campus_id
                LEFT JOIN faculties f ON c.faculty_id = f.faculty_id
                LEFT JOIN departments d ON c.department_id = d.department_id
                LEFT JOIN programs p ON c.program_id = p.program_id
                WHERE c.department_id = ?
            ";
            
            $params = [$user_department_id];
            
            if ($program_id) {
                $sql .= " AND c.program_id = ?";
                $params[] = $program_id;
            }
            
            if ($study_mode) {
                $sql .= " AND c.study_mode = ?";
                $params[] = $study_mode;
            }
            
            if ($status) {
                $sql .= " AND c.status = ?";
                $params[] = $status;
            }
            
            $sql .= " ORDER BY c.class_id DESC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Sanitize output
            foreach ($classes as &$class) {
                $class['class_name'] = htmlspecialchars($class['class_name'], ENT_QUOTES, 'UTF-8');
                $class['campus_name'] = htmlspecialchars($class['campus_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
                $class['faculty_name'] = htmlspecialchars($class['faculty_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
                $class['department_name'] = htmlspecialchars($class['department_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
                $class['program_name'] = htmlspecialchars($class['program_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
            }
            
            echo json_encode([
                'status' => 'success', 
                'classes' => $classes,
                'csrf_token' => $_SESSION['csrf_token']
            ]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error']);
        }
        exit;
    }
    
    echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    exit;
}

// ===========================================
// CRUD OPERATIONS
// ===========================================
$message = "";
$type = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $action = $_POST['action'];
        
        // Input validation and sanitization
        $class_name = trim(htmlspecialchars($_POST['class_name'] ?? '', ENT_QUOTES, 'UTF-8'));
        $program_id = intval($_POST['program_id'] ?? 0);
        $study_mode = in_array($_POST['study_mode'] ?? '', ['Full-Time', 'Part-Time']) ? $_POST['study_mode'] : 'Full-Time';
        $status = in_array($_POST['status'] ?? '', ['Active', 'Inactive']) ? $_POST['status'] : 'Active';
        
        // Fixed values for department admin
        $campus_id = $user_campus_id;
        $faculty_id = $user_faculty_id;
        $department_id = $user_department_id;
        
        if ($action === 'add') {
            if (empty($class_name) || !$program_id) {
                throw new Exception("All required fields must be filled");
            }
            
            // Verify program belongs to this department
            $check_program = $pdo->prepare("
                SELECT COUNT(*) FROM programs 
                WHERE program_id = ? AND department_id = ?
            ");
            $check_program->execute([$program_id, $department_id]);
            if ($check_program->fetchColumn() == 0) {
                throw new Exception("Invalid program selected");
            }
            
            // Check duplicate by campus + program + study mode + class name
            $check = $pdo->prepare("
                SELECT COUNT(*) 
                FROM classes 
                WHERE class_name = ? 
                  AND campus_id = ? 
                  AND program_id = ? 
                  AND study_mode = ?
            ");
            $check->execute([$class_name, $campus_id, $program_id, $study_mode]);
            
            if ($check->fetchColumn() > 0) {
                $message = "⚠️ Class with same name already exists in this program and study mode!";
                $type = "warning";
            } else {
                $stmt = $pdo->prepare("INSERT INTO classes 
                    (class_name, campus_id, faculty_id, department_id, program_id, study_mode, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([
                    $class_name,
                    $campus_id,
                    $faculty_id,
                    $department_id,
                    $program_id,
                    $study_mode,
                    $status
                ]);
                $message = "✅ Class added successfully!";
                $type = "success";
            }
        }
        
        if ($action === 'update') {
            $class_id = intval($_POST['class_id'] ?? 0);
            
            if (!$class_id) {
                throw new Exception("Class ID is required");
            }
            
            if (empty($class_name) || !$program_id) {
                throw new Exception("All required fields must be filled");
            }
            
            // Verify program belongs to this department
            $check_program = $pdo->prepare("
                SELECT COUNT(*) FROM programs 
                WHERE program_id = ? AND department_id = ?
            ");
            $check_program->execute([$program_id, $department_id]);
            if ($check_program->fetchColumn() == 0) {
                throw new Exception("Invalid program selected");
            }
            
            // Check if class belongs to this department
            $check_class = $pdo->prepare("
                SELECT COUNT(*) FROM classes 
                WHERE class_id = ? AND department_id = ?
            ");
            $check_class->execute([$class_id, $department_id]);
            if ($check_class->fetchColumn() == 0) {
                throw new Exception("You don't have permission to edit this class");
            }
            
            // Check duplicate excluding current
            $check = $pdo->prepare("
                SELECT COUNT(*) 
                FROM classes 
                WHERE class_name = ? 
                  AND campus_id = ? 
                  AND program_id = ? 
                  AND study_mode = ?
                  AND class_id != ?
            ");
            $check->execute([$class_name, $campus_id, $program_id, $study_mode, $class_id]);
            
            if ($check->fetchColumn() > 0) {
                $message = "⚠️ Class with same name already exists in this program and study mode!";
                $type = "warning";
            } else {
                $stmt = $pdo->prepare("UPDATE classes 
                    SET class_name=?, program_id=?, study_mode=?, status=?, updated_at=NOW()
                    WHERE class_id=? AND department_id=?");
                $stmt->execute([
                    $class_name,
                    $program_id,
                    $study_mode,
                    $status,
                    $class_id,
                    $department_id
                ]);
                $message = "✅ Class updated successfully!";
                $type = "success";
            }
        }

        if ($action === 'delete') {
            $class_id = intval($_POST['class_id'] ?? 0);
            
            if (!$class_id) {
                throw new Exception("Class ID is required");
            }
            
            // Check if class belongs to this department
            $check_class = $pdo->prepare("
                SELECT COUNT(*) FROM classes 
                WHERE class_id = ? AND department_id = ?
            ");
            $check_class->execute([$class_id, $department_id]);
            if ($check_class->fetchColumn() == 0) {
                throw new Exception("You don't have permission to delete this class");
            }
            
            $stmt = $pdo->prepare("DELETE FROM classes WHERE class_id=? AND department_id=?");
            $stmt->execute([$class_id, $department_id]);
            $message = "✅ Class deleted successfully!";
            $type = "success";
        }

    } catch (PDOException $e) {
        error_log("Class Management Error: " . $e->getMessage());
        $message = "❌ Database error occurred. Please try again.";
        $type = "error";
    } catch (Exception $e) {
        $message = "❌ " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $type = "error";
    }
}

// ===========================================
// FETCH INITIAL DATA
// ===========================================
try {
    // Fetch classes for this department only
    $stmt = $pdo->prepare("
        SELECT c.*, 
               camp.campus_name,
               f.faculty_name, 
               d.department_name,
               p.program_name,
               p.program_code
        FROM classes c
        LEFT JOIN campus camp ON c.campus_id = camp.campus_id
        LEFT JOIN faculties f ON c.faculty_id = f.faculty_id
        LEFT JOIN departments d ON c.department_id = d.department_id
        LEFT JOIN programs p ON c.program_id = p.program_id
        WHERE c.department_id = ?
        ORDER BY c.class_id DESC
    ");
    $stmt->execute([$user_department_id]);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Sanitize fetched data
    foreach ($classes as &$class) {
        $class['class_name'] = htmlspecialchars($class['class_name'], ENT_QUOTES, 'UTF-8');
        $class['campus_name'] = htmlspecialchars($class['campus_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
        $class['faculty_name'] = htmlspecialchars($class['faculty_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
        $class['department_name'] = htmlspecialchars($class['department_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
        $class['program_name'] = htmlspecialchars($class['program_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
    }

    // Fetch programs for this department only
    $stmt = $pdo->prepare("
        SELECT program_id, program_name, program_code 
        FROM programs 
        WHERE department_id = ? AND status = 'active'
        ORDER BY program_name
    ");
    $stmt->execute([$user_department_id]);
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Data Fetch Error: " . $e->getMessage());
    $classes = [];
    $programs = [];
}

$current_file = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Department Admin - Class Management | Hormuud University</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="../images.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<style>
/* ===========================================
   HORMUUD UNIVERSITY OFFICIAL COLOR SCHEME
   Based on logo: HORMUUD UNIVERSITY + الجامعة الحرمين
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
    --hu-shadow: rgba(0, 132, 61, 0.1);
    --hu-text: #333333;
    --hu-text-light: #666666;
}

* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
  font-family: 'Poppins', sans-serif;
}

body {
  background: var(--hu-gray);
  color: var(--hu-text);
  min-height: 100vh;
  overflow-x: hidden;
  line-height: 1.6;
}

.main-content {
  padding: 25px;
  margin-top: 65px;
  margin-left: 240px;
  margin-bottom: 70px;
  transition: all 0.3s ease;
  min-height: calc(100vh - 135px);
}

body.sidebar-collapsed .main-content {
  margin-left: 60px;
}

/* ===== HEADER SECTION ===== */
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 25px;
  padding: 20px 25px;
  background: var(--hu-white);
  border-radius: 16px;
  box-shadow: 0 4px 20px var(--hu-shadow);
  border-left: 6px solid var(--hu-green);
  flex-wrap: wrap;
  gap: 15px;
}

.page-title {
  display: flex;
  align-items: center;
  gap: 15px;
  flex: 1;
  flex-wrap: wrap;
}

.page-title h1 {
  color: var(--hu-dark-blue);
  font-size: 28px;
  font-weight: 700;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 10px;
}

.page-title h1 i {
  color: var(--hu-green);
  font-size: 32px;
}

/* University name badge */
.university-badge {
  background: linear-gradient(135deg, var(--hu-green), var(--hu-dark-green));
  color: white;
  padding: 6px 18px;
  border-radius: 40px;
  font-size: 14px;
  font-weight: 500;
  display: inline-flex;
  align-items: center;
  gap: 10px;
  letter-spacing: 0.5px;
  box-shadow: 0 4px 12px rgba(0, 132, 61, 0.3);
  white-space: nowrap;
}

.university-badge i {
  font-size: 16px;
}

.university-badge span {
  font-family: 'Amiri', 'Traditional Arabic', serif;
  font-size: 16px;
  font-weight: 400;
}

/* Department Badge - Blue Theme for Department Admin */
.department-badge {
  background: linear-gradient(135deg, var(--hu-blue), var(--hu-dark-blue));
  color: white;
  padding: 6px 18px;
  border-radius: 40px;
  font-size: 14px;
  font-weight: 600;
  white-space: nowrap;
  display: inline-flex;
  align-items: center;
  gap: 10px;
  box-shadow: 0 4px 12px rgba(0, 114, 206, 0.3);
}

.department-badge i {
  font-size: 16px;
}

.badge {
  background: var(--hu-light-green);
  color: white;
  padding: 6px 18px;
  border-radius: 40px;
  font-size: 14px;
  font-weight: 600;
  white-space: nowrap;
  box-shadow: 0 4px 12px rgba(0, 166, 81, 0.25);
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

.badge i {
  font-size: 14px;
}

.header-actions {
  display: flex;
  gap: 15px;
  align-items: center;
}

.add-btn {
  background: linear-gradient(135deg, var(--hu-green), var(--hu-dark-green));
  color: var(--hu-white);
  border: none;
  padding: 12px 28px;
  border-radius: 40px;
  font-weight: 600;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 12px;
  white-space: nowrap;
  transition: all 0.3s ease;
  box-shadow: 0 8px 20px rgba(0, 132, 61, 0.25);
  font-size: 15px;
  letter-spacing: 0.3px;
}

.add-btn:hover {
  transform: translateY(-3px);
  box-shadow: 0 12px 28px rgba(0, 132, 61, 0.35);
  background: linear-gradient(135deg, var(--hu-dark-green), var(--hu-green));
}

.add-btn i {
  font-size: 18px;
}

/* Info Card - Blue Theme for Department Admin */
.info-card {
  background: linear-gradient(135deg, var(--hu-white), #f8f9fa);
  border-radius: 16px;
  padding: 25px 30px;
  margin-bottom: 30px;
  border-left: 6px solid var(--hu-blue);
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 35px;
  box-shadow: 0 8px 20px rgba(0, 114, 206, 0.1);
}

.info-item {
  display: flex;
  align-items: center;
  gap: 15px;
}

.info-item i {
  font-size: 28px;
  color: var(--hu-blue);
  background: rgba(0, 114, 206, 0.1);
  width: 55px;
  height: 55px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 16px;
  box-shadow: 0 4px 12px rgba(0, 114, 206, 0.15);
}

.info-text {
  display: flex;
  flex-direction: column;
}

.info-text small {
  color: var(--hu-text-light);
  font-size: 13px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  font-weight: 500;
}

.info-text strong {
  font-size: 20px;
  color: var(--hu-text);
  font-weight: 600;
}

/* Filters Section */
.filters-container {
  background: var(--hu-white);
  border-radius: 16px;
  padding: 25px 30px;
  margin-bottom: 30px;
  box-shadow: 0 8px 20px var(--hu-shadow);
  border: 1px solid rgba(0, 132, 61, 0.1);
}

.filters-header {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 20px;
  padding-bottom: 15px;
  border-bottom: 2px solid rgba(0, 132, 61, 0.1);
}

.filters-header i {
  color: var(--hu-green);
  font-size: 22px;
}

.filters-header h3 {
  color: var(--hu-dark-blue);
  font-size: 20px;
  margin: 0;
  font-weight: 600;
}

.filter-form {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 20px;
  align-items: end;
}

.filter-group {
  display: flex;
  flex-direction: column;
}

.filter-group label {
  font-weight: 600;
  color: var(--hu-dark-blue);
  margin-bottom: 8px;
  font-size: 14px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.filter-group label i {
  color: var(--hu-green);
  font-size: 16px;
}

.filter-select {
  width: 100%;
  padding: 14px 18px;
  border: 2px solid var(--hu-border);
  border-radius: 12px;
  font-size: 14px;
  background: var(--hu-white);
  transition: all 0.3s;
  cursor: pointer;
  appearance: none;
  -webkit-appearance: none;
  background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23666' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
  background-repeat: no-repeat;
  background-position: right 18px center;
  background-size: 18px;
  padding-right: 50px;
}

.filter-select:focus {
  outline: none;
  border-color: var(--hu-blue);
  box-shadow: 0 0 0 4px rgba(0, 114, 206, 0.15);
}

.filter-actions {
  display: flex;
  gap: 15px;
  align-items: center;
}

.filter-btn {
  background: var(--hu-blue);
  color: var(--hu-white);
  border: none;
  padding: 14px 28px;
  border-radius: 40px;
  font-weight: 600;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 12px;
  transition: all 0.3s ease;
  box-shadow: 0 8px 20px rgba(0, 114, 206, 0.25);
  font-size: 15px;
}

.filter-btn:hover {
  background: var(--hu-dark-blue);
  transform: translateY(-2px);
  box-shadow: 0 12px 28px rgba(0, 114, 206, 0.35);
}

.filter-btn.reset {
  background: var(--hu-text-light);
  box-shadow: 0 8px 20px rgba(102, 102, 102, 0.2);
}

.filter-btn.reset:hover {
  background: #555;
}

/* Table Container */
.table-container {
  background: var(--hu-white);
  border-radius: 16px;
  box-shadow: 0 12px 30px var(--hu-shadow);
  overflow: hidden;
  margin-bottom: 30px;
  border: 1px solid rgba(0, 132, 61, 0.1);
}

.table-header {
  padding: 20px 30px;
  background: linear-gradient(135deg, #f8f9fa, #e9ecef);
  border-bottom: 2px solid rgba(0, 132, 61, 0.1);
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.table-header h3 {
  color: var(--hu-dark-blue);
  font-size: 18px;
  margin: 0;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 10px;
}

.table-header h3 i {
  color: var(--hu-green);
}

.table-wrapper {
  overflow-x: auto;
  max-height: 550px;
  position: relative;
}

/* Table Styles */
table {
  width: 100%;
  border-collapse: collapse;
  min-width: 1000px;
}

/* Table Header - Blue Gradient for Department Admin */
thead {
  background: linear-gradient(135deg, var(--hu-blue), var(--hu-dark-blue));
  position: sticky;
  top: 0;
  z-index: 10;
}

thead th {
  color: var(--hu-white);
  font-weight: 600;
  padding: 18px 20px;
  text-align: left;
  white-space: nowrap;
  position: relative;
  font-size: 14px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

thead th:not(:last-child):after {
  content: '';
  position: absolute;
  right: 0;
  top: 25%;
  height: 50%;
  width: 1px;
  background: rgba(255, 255, 255, 0.2);
}

tbody tr {
  border-bottom: 1px solid var(--hu-border);
  transition: all 0.2s;
}

tbody tr:hover {
  background: linear-gradient(90deg, rgba(0, 114, 206, 0.03), rgba(0, 86, 179, 0.03));
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(0, 114, 206, 0.1);
}

tbody td {
  padding: 16px 20px;
  vertical-align: middle;
  font-size: 14px;
  color: var(--hu-text);
}

/* Status Badges */
.status-badge {
  padding: 6px 14px;
  border-radius: 40px;
  font-size: 12px;
  font-weight: 600;
  display: inline-block;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.status-Active {
  background: linear-gradient(135deg, #d4edda, #c3e6cb);
  color: #155724;
  border: 1px solid #c3e6cb;
}

.status-Inactive {
  background: linear-gradient(135deg, #f8d7da, #f5c6cb);
  color: #721c24;
  border: 1px solid #f5c6cb;
}

/* Action Buttons */
.action-btns {
  display: flex;
  gap: 10px;
  justify-content: center;
  flex-wrap: wrap;
}

.view-btn,
.edit-btn,
.del-btn {
  border: none;
  border-radius: 10px;
  padding: 10px 14px;
  color: var(--hu-white);
  cursor: pointer;
  transition: all 0.3s;
  font-size: 14px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 42px;
  min-height: 42px;
  box-shadow: 0 4px 10px rgba(0,0,0,0.1);
}

.view-btn {
  background: linear-gradient(135deg, #6c757d, #5a6268);
}

.view-btn:hover {
  background: linear-gradient(135deg, #5a6268, #4a5258);
  transform: translateY(-2px) scale(1.05);
  box-shadow: 0 8px 16px rgba(108, 117, 125, 0.3);
}

.edit-btn {
  background: linear-gradient(135deg, var(--hu-blue), var(--hu-light-blue));
}

.edit-btn:hover {
  background: linear-gradient(135deg, var(--hu-light-blue), var(--hu-blue));
  transform: translateY(-2px) scale(1.05);
  box-shadow: 0 8px 16px rgba(0, 114, 206, 0.3);
}

.del-btn {
  background: linear-gradient(135deg, var(--hu-red), #e53935);
}

.del-btn:hover {
  background: linear-gradient(135deg, #e53935, #d32f2f);
  transform: translateY(-2px) scale(1.05);
  box-shadow: 0 8px 16px rgba(198, 40, 40, 0.3);
}

/* Empty State */
.empty-state {
  text-align: center;
  padding: 80px 20px;
  color: var(--hu-text-light);
}

.empty-state i {
  font-size: 70px;
  margin-bottom: 20px;
  color: #ddd;
  display: block;
  opacity: 0.7;
}

.empty-state h3 {
  color: var(--hu-dark-blue);
  font-size: 20px;
  margin-bottom: 10px;
}

.empty-state p {
  color: var(--hu-text-light);
}

/* MODAL STYLES */
.modal {
  display: none;
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0, 0, 0, 0.7);
  justify-content: center;
  align-items: center;
  z-index: 4000;
  padding: 20px;
  backdrop-filter: blur(5px);
  animation: fadeIn 0.3s ease;
}

.modal.show {
  display: flex;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.modal-content {
  background: var(--hu-white);
  border-radius: 24px;
  width: 100%;
  max-width: 700px;
  padding: 40px;
  position: relative;
  box-shadow: 0 30px 60px rgba(0, 114, 206, 0.25);
  max-height: 90vh;
  overflow-y: auto;
  animation: slideUp 0.4s ease;
  border: 1px solid rgba(0, 114, 206, 0.1);
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
  top: 25px;
  right: 30px;
  font-size: 32px;
  cursor: pointer;
  color: var(--hu-red);
  width: 45px;
  height: 45px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  transition: all 0.3s;
  background: var(--hu-gray);
}

.close-modal:hover {
  background: var(--hu-red);
  color: var(--hu-white);
  transform: rotate(90deg);
}

.modal-content h2 {
  color: var(--hu-dark-blue);
  margin: 0 0 30px 0;
  font-size: 28px;
  padding-right: 50px;
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: 15px;
}

.modal-content h2 i {
  color: var(--hu-green);
}

.modal-form {
  display: grid;
  grid-template-columns: 1fr;
  gap: 25px;
}

.form-group {
  display: flex;
  flex-direction: column;
}

.form-group label {
  font-weight: 600;
  color: var(--hu-dark-blue);
  margin-bottom: 10px;
  font-size: 15px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.form-group label.required:after {
  content: ' *';
  color: var(--hu-red);
  font-weight: 700;
}

.form-group input,
.form-group select {
  width: 100%;
  padding: 16px 22px;
  border: 2px solid var(--hu-border);
  border-radius: 14px;
  font-family: 'Poppins', sans-serif;
  font-size: 15px;
  transition: all 0.3s;
  background: var(--hu-white);
}

.form-group input:focus,
.form-group select:focus {
  outline: none;
  border-color: var(--hu-blue);
  box-shadow: 0 0 0 4px rgba(0, 114, 206, 0.15);
}

.form-group select {
  appearance: none;
  -webkit-appearance: none;
  background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23666' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
  background-repeat: no-repeat;
  background-position: right 22px center;
  background-size: 18px;
  padding-right: 55px;
}

.readonly-field {
  background: #f5f5f5 !important;
  color: var(--hu-text-light);
  cursor: not-allowed;
  border-color: #ddd !important;
}

.save-btn {
  grid-column: 1 / -1;
  background: linear-gradient(135deg, var(--hu-green), var(--hu-dark-green));
  color: var(--hu-white);
  border: none;
  padding: 18px;
  border-radius: 40px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.3s;
  font-size: 17px;
  margin-top: 15px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  box-shadow: 0 10px 25px rgba(0, 132, 61, 0.25);
}

.save-btn:hover {
  transform: translateY(-3px);
  box-shadow: 0 15px 35px rgba(0, 132, 61, 0.35);
  background: linear-gradient(135deg, var(--hu-dark-green), var(--hu-green));
}

.delete-modal .modal-content h2 {
  color: var(--hu-red);
}

.delete-modal .modal-actions {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 20px;
  margin-top: 30px;
}

.delete-modal .cancel-btn {
  background: var(--hu-blue);
}

.delete-modal .delete-confirm-btn {
  background: linear-gradient(135deg, var(--hu-red), #e53935);
}

.delete-modal .delete-confirm-btn:hover {
  background: linear-gradient(135deg, #e53935, #d32f2f);
}

/* Alert Popup */
.alert-popup {
  display: none;
  position: fixed;
  top: 100px;
  right: 30px;
  background: var(--hu-white);
  border-radius: 16px;
  padding: 25px 30px;
  z-index: 5000;
  box-shadow: 0 20px 40px rgba(0, 114, 206, 0.2);
  min-width: 380px;
  max-width: 450px;
  animation: slideInRight 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55);
  border-top: 6px solid transparent;
}

.alert-popup.show {
  display: block;
}

.alert-popup.success {
  border-top-color: var(--hu-green);
}

.alert-popup.error {
  border-top-color: var(--hu-red);
}

.alert-popup.warning {
  border-top-color: var(--hu-orange);
}

@keyframes slideInRight {
  from {
    transform: translateX(100%) translateY(-20px);
    opacity: 0;
  }
  to {
    transform: translateX(0) translateY(0);
    opacity: 1;
  }
}

.alert-popup i {
  font-size: 32px;
  margin-bottom: 15px;
  display: block;
}

.alert-popup.success i {
  color: var(--hu-green);
}

.alert-popup.error i {
  color: var(--hu-red);
}

.alert-popup.warning i {
  color: var(--hu-orange);
}

.alert-popup h3 {
  margin: 0;
  font-size: 16px;
  font-weight: 500;
  line-height: 1.6;
  color: var(--hu-text);
}

/* Responsive Design */
@media (max-width: 1200px) {
  .main-content {
    margin-left: 200px;
  }
}

@media (max-width: 1024px) {
  .main-content {
    margin-left: 0 !important;
    padding: 20px;
  }
  
  .page-title h1 {
    font-size: 24px;
  }
  
  .page-title {
    gap: 10px;
  }
}

@media (max-width: 768px) {
  .page-header {
    flex-direction: column;
    align-items: flex-start;
  }
  
  .page-title {
    flex-direction: column;
    align-items: flex-start;
    width: 100%;
  }
  
  .info-card {
    flex-direction: column;
    align-items: flex-start;
    gap: 20px;
    padding: 20px;
  }
  
  .info-item {
    width: 100%;
  }
  
  .filter-form {
    grid-template-columns: 1fr;
  }
  
  .filter-actions {
    flex-direction: column;
    width: 100%;
  }
  
  .filter-btn {
    width: 100%;
    justify-content: center;
  }
  
  .add-btn {
    width: 100%;
    justify-content: center;
  }
  
  .header-actions {
    width: 100%;
  }
  
  table {
    min-width: 800px;
  }
}

@media (max-width: 480px) {
  .main-content {
    padding: 15px;
  }
  
  .page-header {
    padding: 15px;
  }
  
  .page-title h1 {
    font-size: 20px;
  }
  
  .university-badge {
    font-size: 12px;
    padding: 4px 12px;
  }
  
  .university-badge span {
    font-size: 12px;
  }
  
  .department-badge {
    font-size: 12px;
    padding: 4px 12px;
  }
  
  .badge {
    font-size: 12px;
    padding: 4px 12px;
  }
  
  .modal-content {
    padding: 30px 20px;
  }
  
  .modal-content h2 {
    font-size: 22px;
  }
}

/* Touch Device Optimizations */
@media (hover: none) and (pointer: coarse) {
  .add-btn,
  .filter-btn,
  .view-btn,
  .edit-btn,
  .del-btn,
  .save-btn,
  .close-modal {
    min-height: 48px;
    min-width: 48px;
  }
  
  .filter-select,
  .form-group input,
  .form-group select {
    font-size: 16px;
    padding: 16px 20px;
  }
}

/* Print Styles */
@media print {
  .main-content {
    margin: 0;
    padding: 0;
  }
  
  .page-header,
  .filters-container,
  .add-btn,
  .action-btns,
  .modal,
  .alert-popup,
  .info-card,
  .header-actions {
    display: none !important;
  }
  
  .table-container {
    box-shadow: none;
    border: 2px solid #000;
  }
  
  thead {
    background: #fff !important;
    color: #000 !important;
  }
  
  thead th {
    color: #000 !important;
    border-bottom: 2px solid #000;
  }
}

/* Arabic Font Support */
.arabic-text {
  font-family: 'Amiri', 'Traditional Arabic', serif;
  direction: rtl;
}

/* Utility Classes */
.text-green { color: var(--hu-green); }
.text-blue { color: var(--hu-blue); }
.text-red { color: var(--hu-red); }
.bg-green { background-color: var(--hu-green); }
.bg-blue { background-color: var(--hu-blue); }
.bg-light-gray { background-color: var(--hu-gray); }
</style>
<!-- Add Arabic font -->
<link href="https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
  <div class="page-header">
    <div class="page-title">
      <h1>
        <i class="fas fa-chalkboard-teacher"></i> 
        Class Management
      </h1>
    
    </div>
    
    <div class="header-actions">
      <button class="add-btn" onclick="openModal('addModal')">
        <i class="fas fa-plus-circle"></i> Add New Class
      </button>
    </div>
  </div>


  <!-- FILTERS SECTION -->
  <div class="filters-container">
    <div class="filters-header">
      <i class="fas fa-filter"></i>
      <h3>Filter Classes</h3>
    </div>
    
    <div class="filter-form">
      <div class="filter-group">
        <label><i class="fas fa-graduation-cap"></i> Program</label>
        <select class="filter-select" id="filter_program">
          <option value="">All Programs</option>
          <?php foreach($programs as $p): ?>
            <option value="<?= $p['program_id'] ?>"><?= htmlspecialchars($p['program_name']) ?> (<?= htmlspecialchars($p['program_code']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      
      <div class="filter-group">
        <label><i class="fas fa-clock"></i> Study Mode</label>
        <select class="filter-select" id="filter_study_mode">
          <option value="">All Modes</option>
          <option value="Full-Time">Full-Time</option>
          <option value="Part-Time">Part-Time</option>
        </select>
      </div>
      
      <div class="filter-group">
        <label><i class="fas fa-circle"></i> Status</label>
        <select class="filter-select" id="filter_status">
          <option value="">All Status</option>
          <option value="Active">Active</option>
          <option value="Inactive">Inactive</option>
        </select>
      </div>
      
      <div class="filter-actions">
        <button class="filter-btn" onclick="applyFilters()">
          <i class="fas fa-search"></i> Apply Filters
        </button>
        <button class="filter-btn reset" onclick="resetFilters()">
          <i class="fas fa-redo"></i> Reset
        </button>
      </div>
    </div>
  </div>

  <!-- TABLE SECTION -->
  <div class="table-container">
    <div class="table-header">
      <h3><i class="fas fa-list"></i> Class List - <?= htmlspecialchars($user_department_name) ?></h3>
    </div>
    
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Class Name</th>
            <th>Program</th>
            <th>Study Mode</th>
            <th>Status</th>
            <th>Created</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="classesTableBody">
          <?php if ($classes): foreach ($classes as $i=>$c): ?>
          <tr>
            <td><strong><?= $i+1 ?></strong></td>
            <td><strong><?= $c['class_name'] ?></strong></td>
            <td><?= $c['program_name'] ?> (<?= $c['program_code'] ?>)</td>
            <td><?= $c['study_mode'] ?></td>
            <td>
              <span class="status-badge status-<?= $c['status'] ?>">
                <?= ucfirst($c['status']) ?>
              </span>
            </td>
            <td><?= date('d M Y', strtotime($c['created_at'])) ?></td>
            <td>
              <div class="action-btns">
                <button class="view-btn" 
                        onclick="openViewModal(
                          <?= $c['class_id'] ?>,
                          '<?= addslashes($c['class_name']) ?>',
                          '<?= addslashes($c['program_name']) ?>',
                          '<?= addslashes($c['department_name']) ?>',
                          '<?= addslashes($c['faculty_name']) ?>',
                          '<?= addslashes($c['campus_name']) ?>',
                          '<?= $c['study_mode'] ?>',
                          '<?= $c['status'] ?>',
                          '<?= $c['created_at'] ?>',
                          '<?= $c['updated_at'] ?>'
                        )" 
                        title="View class details">
                  <i class="fa-solid fa-eye"></i>
                </button>
                <button class="edit-btn" 
                        onclick="openEditModal(
                          <?= $c['class_id'] ?>,
                          '<?= addslashes($c['class_name']) ?>',
                          '<?= $c['program_id'] ?>',
                          '<?= $c['study_mode'] ?>',
                          '<?= $c['status'] ?>'
                        )" 
                        title="Edit class">
                  <i class="fa-solid fa-pen-to-square"></i>
                </button>
                <button class="del-btn" 
                        onclick="openDeleteModal(<?= $c['class_id'] ?>)" 
                        title="Delete class">
                  <i class="fa-solid fa-trash"></i>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; else: ?>
            <tr>
              <td colspan="7">
                <div class="empty-state">
                  <i class="fas fa-chalkboard-teacher"></i>
                  <h3>No Classes Found</h3>
                  <p>Click "Add New Class" to create your first class in this department</p>
                </div>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ADD MODAL -->
<div class="modal" id="addModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('addModal')">&times;</span>
    <h2><i class="fas fa-plus-circle"></i> Add New Class</h2>
    
    <form class="modal-form" method="POST" id="addForm" onsubmit="return validateForm('add')">
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

      <div class="form-group">
        <label class="required"><i class="fas fa-school"></i> Campus</label>
        <input type="text" class="readonly-field" value="<?= htmlspecialchars($user_campus_name) ?>" readonly disabled>
      </div>

      <div class="form-group">
        <label class="required"><i class="fas fa-university"></i> Faculty</label>
        <input type="text" class="readonly-field" value="<?= htmlspecialchars($user_faculty_name) ?>" readonly disabled>
      </div>

      <div class="form-group">
        <label class="required"><i class="fas fa-building"></i> Department</label>
        <input type="text" class="readonly-field" value="<?= htmlspecialchars($user_department_name) ?>" readonly disabled>
      </div>

      <div class="form-group">
        <label class="required"><i class="fas fa-graduation-cap"></i> Program</label>
        <select id="add_program" name="program_id" required>
          <option value="">Select Program</option>
          <?php foreach($programs as $p): ?>
            <option value="<?= $p['program_id'] ?>"><?= htmlspecialchars($p['program_name']) ?> (<?= htmlspecialchars($p['program_code']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="required"><i class="fas fa-chalkboard"></i> Class Name</label>
        <input type="text" name="class_name" required placeholder="e.g., Form 1A, Year 1, Class A" maxlength="100">
      </div>

      <div class="form-group">
        <label class="required"><i class="fas fa-clock"></i> Study Mode</label>
        <select name="study_mode" required>
          <option value="Full-Time" selected>Full-Time</option>
          <option value="Part-Time">Part-Time</option>
        </select>
      </div>

      <div class="form-group">
        <label class="required"><i class="fas fa-circle"></i> Status</label>
        <select name="status" required>
          <option value="Active" selected>Active</option>
          <option value="Inactive">Inactive</option>
        </select>
      </div>

      <button class="save-btn" type="submit">
        <i class="fas fa-save"></i> Save Class
      </button>
    </form>
  </div>
</div>

<!-- VIEW MODAL -->
<div class="modal view-modal" id="viewModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('viewModal')">&times;</span>
    <h2><i class="fas fa-eye"></i> Class Details</h2>
    
    <div class="modal-form">
      <div class="form-group">
        <label>Class Name</label>
        <div class="detail-value" id="view_name" style="padding:15px 20px; background:#f5f5f5; border-radius:10px;"></div>
      </div>
      
      <div class="form-group">
        <label>Program</label>
        <div class="detail-value" id="view_program" style="padding:15px 20px; background:#f5f5f5; border-radius:10px;"></div>
      </div>
      
      <div class="form-group">
        <label>Department</label>
        <div class="detail-value" id="view_department" style="padding:15px 20px; background:#f5f5f5; border-radius:10px;"></div>
      </div>
      
      <div class="form-group">
        <label>Faculty</label>
        <div class="detail-value" id="view_faculty" style="padding:15px 20px; background:#f5f5f5; border-radius:10px;"></div>
      </div>
      
      <div class="form-group">
        <label>Campus</label>
        <div class="detail-value" id="view_campus" style="padding:15px 20px; background:#f5f5f5; border-radius:10px;"></div>
      </div>
      
      <div class="form-group">
        <label>Study Mode</label>
        <div class="detail-value" id="view_mode" style="padding:15px 20px; background:#f5f5f5; border-radius:10px;"></div>
      </div>
      
      <div class="form-group">
        <label>Status</label>
        <div class="detail-value" id="view_status" style="padding:15px 20px; background:#f5f5f5; border-radius:10px;"></div>
      </div>
      
      <div class="form-group">
        <label>Created Date</label>
        <div class="detail-value" id="view_created" style="padding:15px 20px; background:#f5f5f5; border-radius:10px;"></div>
      </div>
      
      <div class="form-group">
        <label>Updated Date</label>
        <div class="detail-value" id="view_updated" style="padding:15px 20px; background:#f5f5f5; border-radius:10px;"></div>
      </div>
    </div>
    
    <div style="text-align: center; margin-top: 30px;">
      <button class="save-btn" onclick="closeModal('viewModal')" style="background: #6c757d;">
        <i class="fas fa-times"></i> Close
      </button>
    </div>
  </div>
</div>

<!-- EDIT MODAL -->
<div class="modal" id="editModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('editModal')">&times;</span>
    <h2><i class="fas fa-edit"></i> Edit Class</h2>
    
    <form class="modal-form" method="POST" id="editForm" onsubmit="return validateForm('edit')">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
      <input type="hidden" id="edit_id" name="class_id">

      <div class="form-group">
        <label class="required"><i class="fas fa-school"></i> Campus</label>
        <input type="text" class="readonly-field" value="<?= htmlspecialchars($user_campus_name) ?>" readonly disabled>
      </div>

      <div class="form-group">
        <label class="required"><i class="fas fa-university"></i> Faculty</label>
        <input type="text" class="readonly-field" value="<?= htmlspecialchars($user_faculty_name) ?>" readonly disabled>
      </div>

      <div class="form-group">
        <label class="required"><i class="fas fa-building"></i> Department</label>
        <input type="text" class="readonly-field" value="<?= htmlspecialchars($user_department_name) ?>" readonly disabled>
      </div>

      <div class="form-group">
        <label class="required"><i class="fas fa-graduation-cap"></i> Program</label>
        <select id="edit_program" name="program_id" required>
          <option value="">Select Program</option>
          <?php foreach($programs as $p): ?>
            <option value="<?= $p['program_id'] ?>"><?= htmlspecialchars($p['program_name']) ?> (<?= htmlspecialchars($p['program_code']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="required"><i class="fas fa-chalkboard"></i> Class Name</label>
        <input type="text" id="edit_name" name="class_name" required>
      </div>

      <div class="form-group">
        <label class="required"><i class="fas fa-clock"></i> Study Mode</label>
        <select id="edit_mode" name="study_mode" required>
          <option value="Full-Time">Full-Time</option>
          <option value="Part-Time">Part-Time</option>
        </select>
      </div>

      <div class="form-group">
        <label class="required"><i class="fas fa-circle"></i> Status</label>
        <select id="edit_status" name="status" required>
          <option value="Active">Active</option>
          <option value="Inactive">Inactive</option>
        </select>
      </div>

      <button class="save-btn" type="submit">
        <i class="fas fa-sync-alt"></i> Update Class
      </button>
    </form>
  </div>
</div>

<!-- DELETE MODAL -->
<div class="modal delete-modal" id="deleteModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('deleteModal')">&times;</span>
    <h2><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h2>
    
    <form method="POST" id="deleteForm">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
      <input type="hidden" name="class_id" id="delete_id">
      
      <div style="text-align: center; margin: 30px 0;">
        <i class="fas fa-exclamation-triangle" style="font-size: 70px; color: var(--hu-red);"></i>
        <p style="font-size: 18px; margin-top: 20px; color: var(--hu-text);">
          Are you sure you want to delete this class?<br>
          <span style="font-size: 14px; color: var(--hu-text-light);">This action cannot be undone.</span>
        </p>
      </div>
      
      <div class="modal-actions">
        <button type="button" class="save-btn cancel-btn" onclick="closeModal('deleteModal')">
          <i class="fas fa-times"></i> Cancel
        </button>
        <button type="submit" class="save-btn delete-confirm-btn">
          <i class="fas fa-trash"></i> Yes, Delete
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ALERT POPUP -->
<div id="popup" class="alert-popup <?= $type ?> <?= !empty($message) ? 'show' : '' ?>">
  <i><?= $type === 'success' ? '✅' : ($type === 'warning' ? '⚠️' : '❌') ?></i>
  <h3><?= htmlspecialchars($message) ?></h3>
</div>

<script>
const csrfToken = '<?= $_SESSION['csrf_token'] ?>';
const currentFile = '<?= $current_file ?>';

// Modal functions
function openModal(id) {
    document.getElementById(id).classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeModal(id) {
    document.getElementById(id).classList.remove('show');
    document.body.style.overflow = 'auto';
}

function openViewModal(id, name, program, dept, faculty, campus, mode, status, created, updated) {
    document.getElementById('view_name').textContent = name || '—';
    document.getElementById('view_program').textContent = program || '—';
    document.getElementById('view_department').textContent = dept || '—';
    document.getElementById('view_faculty').textContent = faculty || '—';
    document.getElementById('view_campus').textContent = campus || '—';
    document.getElementById('view_mode').textContent = mode || '—';
    
    // Status badge
    const statusSpan = document.createElement('span');
    statusSpan.className = `status-badge status-${status}`;
    statusSpan.textContent = status;
    document.getElementById('view_status').innerHTML = '';
    document.getElementById('view_status').appendChild(statusSpan);
    
    document.getElementById('view_created').textContent = formatDate(created);
    document.getElementById('view_updated').textContent = formatDate(updated);
    openModal('viewModal');
}

function openEditModal(id, name, programId, mode, status) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_program').value = programId;
    document.getElementById('edit_mode').value = mode;
    document.getElementById('edit_status').value = status;
    openModal('editModal');
}

function openDeleteModal(id) {
    document.getElementById('delete_id').value = id;
    openModal('deleteModal');
}

function formatDate(dateString) {
    if (!dateString) return '—';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        year: 'numeric', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit'
    });
}

// Filter functions
function applyFilters() {
    const programId = document.getElementById('filter_program').value;
    const studyMode = document.getElementById('filter_study_mode').value;
    const status = document.getElementById('filter_status').value;
    
    const tbody = document.getElementById('classesTableBody');
    tbody.innerHTML = `
        <tr><td colspan="7" style="text-align:center; padding:40px;">
            <i class="fas fa-spinner fa-spin" style="font-size:24px; color: var(--hu-blue);"></i><br>
            <span style="color: var(--hu-text-light); margin-top:10px; display:block;">Loading classes...</span>
        </td></tr>
    `;
    
    let query = `ajax_action=get_filtered_classes&csrf_token=${csrfToken}`;
    if (programId) query += `&program_id=${programId}`;
    if (studyMode) query += `&study_mode=${studyMode}`;
    if (status) query += `&status=${status}`;
    
    fetch(`${currentFile}?${query}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                updateTable(data.classes);
                updateBadgeCount(data.classes.length);
            } else {
                showAlert('Error loading data', 'error');
            }
        })
        .catch(() => showAlert('Network error', 'error'));
}

function resetFilters() {
    document.getElementById('filter_program').value = '';
    document.getElementById('filter_study_mode').value = '';
    document.getElementById('filter_status').value = '';
    applyFilters();
}

function updateTable(classes) {
    const tbody = document.getElementById('classesTableBody');
    
    if (!classes || classes.length === 0) {
        tbody.innerHTML = `
            <tr><td colspan="7">
                <div class="empty-state">
                    <i class="fas fa-chalkboard-teacher"></i>
                    <h3>No Classes Found</h3>
                    <p>No classes match your filter criteria</p>
                </div>
            </td></tr>
        `;
        return;
    }
    
    let html = '';
    classes.forEach((c, i) => {
        html += `
            <tr>
                <td><strong>${i+1}</strong></td>
                <td><strong>${escapeHtml(c.class_name)}</strong></td>
                <td>${escapeHtml(c.program_name)} (${escapeHtml(c.program_code)})</td>
                <td>${c.study_mode}</td>
                <td><span class="status-badge status-${c.status}">${c.status}</span></td>
                <td>${formatDate(c.created_at)}</td>
                <td>
                    <div class="action-btns">
                        <button class="view-btn" onclick="openViewModal(
                            ${c.class_id},
                            '${escapeSingleQuote(c.class_name)}',
                            '${escapeSingleQuote(c.program_name)}',
                            '${escapeSingleQuote(c.department_name)}',
                            '${escapeSingleQuote(c.faculty_name)}',
                            '${escapeSingleQuote(c.campus_name)}',
                            '${c.study_mode}',
                            '${c.status}',
                            '${c.created_at}',
                            '${c.updated_at}'
                        )"><i class="fa-solid fa-eye"></i></button>
                        <button class="edit-btn" onclick="openEditModal(
                            ${c.class_id},
                            '${escapeSingleQuote(c.class_name)}',
                            ${c.program_id},
                            '${c.study_mode}',
                            '${c.status}'
                        )"><i class="fa-solid fa-pen-to-square"></i></button>
                        <button class="del-btn" onclick="openDeleteModal(${c.class_id})">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
}

function updateBadgeCount(count) {
    const badge = document.querySelector('.badge');
    if (badge) {
        badge.innerHTML = `<i class="fas fa-list"></i> ${count} Class${count !== 1 ? 'es' : ''}`;
    }
}

function validateForm(formType) {
    const form = document.getElementById(formType + 'Form');
    const name = form.querySelector('input[name="class_name"]');
    const program = form.querySelector('select[name="program_id"]');
    
    if (!name.value.trim()) {
        showAlert('Class name is required', 'error');
        name.focus();
        return false;
    }
    if (!program.value) {
        showAlert('Please select a program', 'error');
        program.focus();
        return false;
    }
    return true;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function escapeSingleQuote(text) {
    return text.replace(/'/g, "\\'");
}

function showAlert(message, type = 'info') {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert-popup ${type} show`;
    
    let icon = 'ℹ️';
    if (type === 'success') icon = '✅';
    else if (type === 'error') icon = '❌';
    else if (type === 'warning') icon = '⚠️';
    
    alertDiv.innerHTML = `<i>${icon}</i><h3>${escapeHtml(message)}</h3>`;
    document.body.appendChild(alertDiv);
    
    setTimeout(() => {
        alertDiv.classList.remove('show');
        setTimeout(() => alertDiv.remove(), 500);
    }, 5000);
}

// Close modal on outside click
window.addEventListener('click', function(event) {
    if (event.target.classList.contains('modal')) {
        closeModal(event.target.id);
    }
});

// Auto-hide alert
setTimeout(() => {
    const alert = document.getElementById('popup');
    if (alert && alert.classList.contains('show')) {
        alert.classList.remove('show');
        setTimeout(() => alert.remove(), 500);
    }
}, 5000);

// Add loading state to buttons
document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', function(e) {
        const submitBtn = this.querySelector('button[type="submit"]');
        if (submitBtn && !submitBtn.disabled) {
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            submitBtn.disabled = true;
            
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 10000);
        }
    });
});
</script>

<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>