<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ===========================================
// SECURITY: ACCESS CONTROL & CSRF PROTECTION
// ===========================================
if (empty($_SESSION['user'])) {
    header("Location: ../login.php");
    exit;
}

if (strtolower($_SESSION['user']['role'] ?? '') !== 'super_admin') {
    header("Location: ../login.php");
    exit;
}

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Validate CSRF token for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die(json_encode(['status' => 'error', 'message' => 'CSRF token validation failed']));
    }
}

// ===========================================
// AJAX HANDLER SECTION WITH SECURITY
// ===========================================
if (isset($_GET['ajax_action']) && isset($_SESSION['csrf_token'])) {
    header('Content-Type: application/json');
    
    $action = $_GET['ajax_action'] ?? '';
    $token = $_GET['csrf_token'] ?? '';
    
    // Verify CSRF token for AJAX requests
    if ($token !== $_SESSION['csrf_token']) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token']);
        exit;
    }
    
    if ($action === 'get_faculties_by_campus') {
        $campus_id = intval($_GET['campus_id'] ?? 0);
        
        if (!$campus_id) {
            echo json_encode(['status' => 'error', 'message' => 'Campus ID required']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare("
                SELECT f.faculty_id, f.faculty_name 
                FROM faculties f
                JOIN faculty_campus fc ON f.faculty_id = fc.faculty_id
                WHERE fc.campus_id = ? AND f.status = 'active'
                ORDER BY f.faculty_name
            ");
            $stmt->execute([$campus_id]);
            $faculties = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'status' => 'success', 
                'faculties' => $faculties,
                'csrf_token' => $_SESSION['csrf_token']
            ]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error']);
        }
        exit;
    }
    
    if ($action === 'get_departments_by_faculty') {
        $faculty_id = intval($_GET['faculty_id'] ?? 0);
        $campus_id = intval($_GET['campus_id'] ?? 0);
        
        if (!$faculty_id || !$campus_id) {
            echo json_encode(['status' => 'error', 'message' => 'Faculty and Campus ID required']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare("
                SELECT d.department_id, d.department_name 
                FROM departments d
                WHERE d.faculty_id = ? AND d.campus_id = ? AND d.status = 'active'
                ORDER BY d.department_name
            ");
            $stmt->execute([$faculty_id, $campus_id]);
            $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'status' => 'success', 
                'departments' => $departments,
                'csrf_token' => $_SESSION['csrf_token']
            ]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error']);
        }
        exit;
    }
    
    if ($action === 'get_filtered_programs') {
        $campus_id = intval($_GET['campus_id'] ?? 0);
        $faculty_id = intval($_GET['faculty_id'] ?? 0);
        $department_id = intval($_GET['department_id'] ?? 0);
        $status = in_array($_GET['status'] ?? '', ['active', 'inactive']) ? $_GET['status'] : '';
        
        try {
            $sql = "
                SELECT p.*, 
                       c.campus_name,
                       f.faculty_name, 
                       d.department_name 
                FROM programs p
                LEFT JOIN campus c ON p.campus_id = c.campus_id
                LEFT JOIN faculties f ON p.faculty_id = f.faculty_id
                LEFT JOIN departments d ON p.department_id = d.department_id
                WHERE 1=1
            ";
            
            $params = [];
            
            if ($campus_id) {
                $sql .= " AND p.campus_id = ?";
                $params[] = $campus_id;
            }
            
            if ($faculty_id) {
                $sql .= " AND p.faculty_id = ?";
                $params[] = $faculty_id;
            }
            
            if ($department_id) {
                $sql .= " AND p.department_id = ?";
                $params[] = $department_id;
            }
            
            if ($status) {
                $sql .= " AND p.status = ?";
                $params[] = $status;
            }
            
            $sql .= " ORDER BY p.program_id DESC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Sanitize output
            foreach ($programs as &$program) {
                $program['program_name'] = htmlspecialchars($program['program_name'], ENT_QUOTES, 'UTF-8');
                $program['program_code'] = htmlspecialchars($program['program_code'], ENT_QUOTES, 'UTF-8');
                $program['description'] = htmlspecialchars($program['description'] ?? '', ENT_QUOTES, 'UTF-8');
                $program['campus_name'] = htmlspecialchars($program['campus_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
                $program['faculty_name'] = htmlspecialchars($program['faculty_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
                $program['department_name'] = htmlspecialchars($program['department_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
            }
            
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
    
    echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    exit;
}

// ===========================================
// CRUD OPERATIONS WITH SECURITY
// ===========================================
$message = "";
$type = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $action = $_POST['action'];
        
        // Input validation and sanitization
        $program_name = trim(htmlspecialchars($_POST['program_name'] ?? '', ENT_QUOTES, 'UTF-8'));
        $program_code = trim(htmlspecialchars($_POST['program_code'] ?? '', ENT_QUOTES, 'UTF-8'));
        $campus_id = intval($_POST['campus_id'] ?? 0);
        $faculty_id = intval($_POST['faculty_id'] ?? 0);
        $department_id = intval($_POST['department_id'] ?? 0);
        $duration_years = intval($_POST['duration_years'] ?? 4);
        $description = trim(htmlspecialchars($_POST['description'] ?? '', ENT_QUOTES, 'UTF-8'));
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';
        
        if ($action === 'add') {
            // Validate required fields
            if (empty($program_name) || empty($program_code) || !$campus_id || !$faculty_id) {
                throw new Exception("All required fields must be filled");
            }
            
            if ($duration_years < 1 || $duration_years > 8) {
                throw new Exception("Duration must be between 1 and 8 years");
            }
            
            // Check duplicate program name OR code in same campus
            $check = $pdo->prepare("
                SELECT COUNT(*) 
                FROM programs 
                WHERE (program_name = ? OR program_code = ?) 
                  AND campus_id = ?
            ");
            $check->execute([$program_name, $program_code, $campus_id]);
            
            if ($check->fetchColumn() > 0) {
                $message = "⚠️ Program with same name or code already exists in this campus!";
                $type = "warning";
            } else {
                $stmt = $pdo->prepare("INSERT INTO programs 
                    (campus_id, faculty_id, department_id, program_name, program_code, duration_years, description, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([
                    $campus_id,
                    $faculty_id ?: null,
                    $department_id ?: null,
                    $program_name,
                    $program_code,
                    $duration_years,
                    $description,
                    $status
                ]);
                $message = "✅ Program added successfully!";
                $type = "success";
            }
        }
        
        if ($action === 'update') {
            $program_id = intval($_POST['program_id'] ?? 0);
            
            if (!$program_id) {
                throw new Exception("Program ID is required");
            }
            
            if (empty($program_name) || empty($program_code) || !$campus_id || !$faculty_id) {
                throw new Exception("All required fields must be filled");
            }
            
            if ($duration_years < 1 || $duration_years > 8) {
                throw new Exception("Duration must be between 1 and 8 years");
            }
            
            // Check duplicate excluding current program
            $check = $pdo->prepare("
                SELECT COUNT(*) 
                FROM programs 
                WHERE (program_name = ? OR program_code = ?) 
                  AND campus_id = ?
                  AND program_id != ?
            ");
            $check->execute([$program_name, $program_code, $campus_id, $program_id]);
            
            if ($check->fetchColumn() > 0) {
                $message = "⚠️ Program with same name or code already exists in this campus!";
                $type = "warning";
            } else {
                $stmt = $pdo->prepare("UPDATE programs 
                    SET campus_id=?, faculty_id=?, department_id=?, program_name=?, program_code=?, duration_years=?, description=?, status=?, updated_at=NOW()
                    WHERE program_id=?");
                $stmt->execute([
                    $campus_id,
                    $faculty_id ?: null,
                    $department_id ?: null,
                    $program_name,
                    $program_code,
                    $duration_years,
                    $description,
                    $status,
                    $program_id
                ]);
                $message = "✅ Program updated successfully!";
                $type = "success";
            }
        }

        if ($action === 'delete') {
            $program_id = intval($_POST['program_id'] ?? 0);
            
            if (!$program_id) {
                throw new Exception("Program ID is required");
            }
            
            $stmt = $pdo->prepare("DELETE FROM programs WHERE program_id=?");
            $stmt->execute([$program_id]);
            $message = "✅ Program deleted successfully!";
            $type = "success";
        }

    } catch (PDOException $e) {
        error_log("Program Management Error: " . $e->getMessage());
        $message = "❌ Database error occurred. Please try again.";
        $type = "error";
    } catch (Exception $e) {
        $message = "❌ " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $type = "error";
    }
}

// ===========================================
// FETCH DATA WITH SECURITY
// ===========================================
try {
    $programs = $pdo->query("
        SELECT p.*, 
               c.campus_name,
               f.faculty_name, 
               d.department_name 
        FROM programs p
        LEFT JOIN campus c ON p.campus_id = c.campus_id AND c.status = 'active'
        LEFT JOIN faculties f ON p.faculty_id = f.faculty_id AND f.status = 'active'
        LEFT JOIN departments d ON p.department_id = d.department_id AND d.status = 'active'
        ORDER BY p.program_id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Sanitize fetched data
    foreach ($programs as &$program) {
        $program['program_name'] = htmlspecialchars($program['program_name'], ENT_QUOTES, 'UTF-8');
        $program['program_code'] = htmlspecialchars($program['program_code'], ENT_QUOTES, 'UTF-8');
        $program['description'] = htmlspecialchars($program['description'] ?? '', ENT_QUOTES, 'UTF-8');
        $program['campus_name'] = htmlspecialchars($program['campus_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
        $program['faculty_name'] = htmlspecialchars($program['faculty_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
        $program['department_name'] = htmlspecialchars($program['department_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
    }

    $campuses = $pdo->query("SELECT * FROM campus WHERE status = 'active' ORDER BY campus_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $faculties = $pdo->query("SELECT * FROM faculties WHERE status = 'active' ORDER BY faculty_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $departments = $pdo->query("SELECT * FROM departments WHERE status = 'active' ORDER BY department_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Data Fetch Error: " . $e->getMessage());
    $programs = [];
    $campuses = [];
    $faculties = [];
    $departments = [];
}

$current_file = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Programs Management | Hormuud University</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
<link rel="icon" type="image/png" href="../images.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<!-- Prevent caching -->
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
<style>
/* ===========================================
   CSS VARIABLES & RESET
=========================================== */
:root {
  --green: #00843D;
  --light-green: #00A651;
  --blue: #0072CE;
  --red: #C62828;
  --orange: #FF9800;
  --bg: #F5F9F7;
  --warning: #FF9800;
  --info: #2196F3;
  --dark: #333;
  --light: #f8f9fa;
  --border: #e0e0e0;
  --shadow: rgba(0, 0, 0, 0.08);
  --white: #FFFFFF;
}

* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
  font-family: 'Poppins', sans-serif;
}

body {
  background: var(--bg);
  color: var(--dark);
  min-height: 100vh;
  overflow-x: hidden;
  line-height: 1.6;
}

/* ===========================================
   MAIN CONTENT AREA
=========================================== */
.main-content {
  padding: 25px;
  margin-top: 65px;
  margin-left: 240px;
  margin-bottom: 70px;
  transition: all 0.3s ease;
  min-height: calc(100vh - 135px);
}

/* Sidebar collapsed state */
body.sidebar-collapsed .main-content {
  margin-left: 60px;
}

/* ===========================================
   PAGE HEADER
=========================================== */
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 25px;
  padding: 20px;
  background: var(--white);
  border-radius: 12px;
  box-shadow: 0 4px 15px var(--shadow);
  border-left: 4px solid var(--green);
  flex-wrap: wrap;
  gap: 15px;
}

.page-title {
  display: flex;
  align-items: center;
  gap: 15px;
  flex: 1;
}

.page-title h1 {
  color: var(--blue);
  font-size: 28px;
  font-weight: 700;
  margin: 0;
}

.page-title h1 i {
  color: var(--green);
  margin-right: 10px;
}

.badge {
  background: var(--light-green);
  color: white;
  padding: 6px 12px;
  border-radius: 20px;
  font-size: 14px;
  font-weight: 600;
  white-space: nowrap;
}

.header-actions {
  display: flex;
  gap: 15px;
  align-items: center;
}

.add-btn {
  background: linear-gradient(135deg, var(--green), var(--light-green));
  color: var(--white);
  border: none;
  padding: 12px 24px;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 10px;
  white-space: nowrap;
  transition: all 0.3s ease;
  box-shadow: 0 4px 12px rgba(0, 132, 61, 0.2);
}

.add-btn:hover {
  transform: translateY(-3px);
  box-shadow: 0 6px 20px rgba(0, 132, 61, 0.3);
}

.add-btn:active {
  transform: translateY(-1px);
}

/* ===========================================
   FILTERS SECTION
=========================================== */
.filters-container {
  background: var(--white);
  border-radius: 12px;
  padding: 25px;
  margin-bottom: 30px;
  box-shadow: 0 4px 12px var(--shadow);
  border: 1px solid rgba(0,0,0,0.05);
}

.filters-header {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 20px;
  padding-bottom: 15px;
  border-bottom: 2px solid var(--bg);
}

.filters-header h3 {
  color: var(--blue);
  font-size: 18px;
  margin: 0;
  font-weight: 600;
}

.filters-header i {
  color: var(--green);
  font-size: 20px;
}

.filter-form {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 20px;
  align-items: end;
}

.filter-group {
  display: flex;
  flex-direction: column;
}

.filter-group label {
  font-weight: 600;
  color: var(--blue);
  margin-bottom: 8px;
  font-size: 14px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.filter-group label i {
  color: var(--green);
}

.filter-select {
  width: 100%;
  padding: 12px 15px;
  border: 2px solid var(--border);
  border-radius: 8px;
  font-size: 14px;
  background: var(--white);
  transition: all 0.3s;
  cursor: pointer;
  appearance: none;
  -webkit-appearance: none;
}

.filter-select:focus {
  outline: none;
  border-color: var(--blue);
  box-shadow: 0 0 0 4px rgba(0, 114, 206, 0.15);
}

.filter-select option {
  padding: 10px;
}

.filter-actions {
  display: flex;
  gap: 15px;
  align-items: center;
}

.filter-btn {
  background: var(--blue);
  color: var(--white);
  border: none;
  padding: 12px 24px;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 10px;
  transition: all 0.3s ease;
  box-shadow: 0 4px 12px rgba(0, 114, 206, 0.2);
}

.filter-btn:hover {
  background: #005fa3;
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(0, 114, 206, 0.3);
}

.filter-btn.reset {
  background: var(--dark);
}

.filter-btn.reset:hover {
  background: #444;
}

/* ===========================================
   TABLE SECTION
=========================================== */
.table-container {
  background: var(--white);
  border-radius: 12px;
  box-shadow: 0 6px 20px var(--shadow);
  overflow: hidden;
  margin-bottom: 30px;
  border: 1px solid rgba(0,0,0,0.05);
}

.table-header {
  padding: 20px 25px;
  background: linear-gradient(135deg, #f8f9fa, #e9ecef);
  border-bottom: 2px solid var(--border);
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.table-header h3 {
  color: var(--blue);
  font-size: 18px;
  margin: 0;
  font-weight: 600;
}

.table-wrapper {
  overflow-x: auto;
  max-height: 500px;
  position: relative;
}

.table-wrapper::-webkit-scrollbar {
  width: 8px;
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

table {
  width: 100%;
  border-collapse: collapse;
  min-width: 1200px;
}

thead {
  background: linear-gradient(135deg, var(--blue), #005fa3);
  position: sticky;
  top: 0;
  z-index: 10;
}

thead th {
  color: var(--white);
  font-weight: 600;
  padding: 18px 20px;
  text-align: left;
  white-space: nowrap;
  position: relative;
  font-size: 15px;
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
  border-bottom: 1px solid var(--border);
  transition: all 0.2s;
}

tbody tr:hover {
  background: linear-gradient(90deg, rgba(0, 132, 61, 0.03), rgba(0, 114, 206, 0.03));
  transform: translateY(-1px);
  box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

tbody td {
  padding: 16px 20px;
  vertical-align: middle;
  font-size: 15px;
  color: var(--dark);
}

/* Status badges */
.status-badge {
  padding: 6px 14px;
  border-radius: 20px;
  font-size: 13px;
  font-weight: 600;
  display: inline-block;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}

.status-active {
  background: linear-gradient(135deg, #d4edda, #c3e6cb);
  color: #155724;
  border: 1px solid #c3e6cb;
}

.status-inactive {
  background: linear-gradient(135deg, #f8d7da, #f5c6cb);
  color: #721c24;
  border: 1px solid #f5c6cb;
}

/* Action buttons */
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
  border-radius: 6px;
  padding: 10px 14px;
  color: var(--white);
  cursor: pointer;
  transition: all 0.3s;
  font-size: 14px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 40px;
  min-height: 40px;
  box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}

.view-btn {
  background: linear-gradient(135deg, #6c757d, #5a6268);
}

.view-btn:hover {
  background: linear-gradient(135deg, #5a6268, #4a5258);
  transform: translateY(-2px) scale(1.05);
  box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
}

.edit-btn {
  background: linear-gradient(135deg, var(--blue), #2196f3);
}

.edit-btn:hover {
  background: linear-gradient(135deg, #2196f3, #0d8bf2);
  transform: translateY(-2px) scale(1.05);
  box-shadow: 0 4px 12px rgba(0, 114, 206, 0.3);
}

.del-btn {
  background: linear-gradient(135deg, var(--red), #e53935);
}

.del-btn:hover {
  background: linear-gradient(135deg, #e53935, #d32f2f);
  transform: translateY(-2px) scale(1.05);
  box-shadow: 0 4px 12px rgba(198, 40, 40, 0.3);
}

/* Empty state */
.empty-state {
  text-align: center;
  padding: 80px 20px;
  color: #666;
}

.empty-state i {
  font-size: 60px;
  margin-bottom: 20px;
  color: #ddd;
  display: block;
  opacity: 0.5;
}

.empty-state h3 {
  font-size: 18px;
  margin: 0 0 10px 0;
  font-weight: 500;
}

.empty-state p {
  color: #aaa;
  font-size: 14px;
}

/* ===========================================
   MODAL STYLES
=========================================== */
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
  background: var(--white);
  border-radius: 16px;
  width: 100%;
  max-width: 800px;
  padding: 40px;
  position: relative;
  box-shadow: 0 25px 50px rgba(0,0,0,0.3);
  max-height: 90vh;
  overflow-y: auto;
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
  top: 20px;
  right: 25px;
  font-size: 32px;
  cursor: pointer;
  color: var(--red);
  width: 40px;
  height: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  transition: all 0.3s;
  background: var(--light);
}

.close-modal:hover {
  background: var(--red);
  color: var(--white);
  transform: rotate(90deg);
}

.modal-content h2 {
  color: var(--blue);
  margin: 0 0 30px 0;
  font-size: 28px;
  padding-right: 40px;
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: 15px;
}

.modal-content h2 i {
  color: var(--green);
}

/* ===========================================
   FORM STYLES
=========================================== */
.modal-form {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 25px;
}

.form-group {
  display: flex;
  flex-direction: column;
}

.form-group.full-width {
  grid-column: 1 / -1;
}

.form-group label {
  font-weight: 600;
  color: var(--blue);
  margin-bottom: 10px;
  font-size: 15px;
  display: flex;
  align-items: center;
  gap: 5px;
}

.form-group label.required:after {
  content: ' *';
  color: var(--red);
}

.form-group input,
.form-group select,
.form-group textarea {
  width: 100%;
  padding: 15px 20px;
  border: 2px solid var(--border);
  border-radius: 10px;
  font-family: 'Poppins', sans-serif;
  font-size: 15px;
  transition: all 0.3s;
  background: var(--white);
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
  outline: none;
  border-color: var(--blue);
  box-shadow: 0 0 0 4px rgba(0, 114, 206, 0.15);
}

.form-group select {
  appearance: none;
  -webkit-appearance: none;
  background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%236c757d' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
  background-repeat: no-repeat;
  background-position: right 20px center;
  background-size: 18px;
  padding-right: 50px;
}

.form-group textarea {
  min-height: 120px;
  resize: vertical;
}

.save-btn {
  grid-column: 1 / -1;
  background: linear-gradient(135deg, var(--green), var(--light-green));
  color: var(--white);
  border: none;
  padding: 18px;
  border-radius: 10px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.3s;
  font-size: 17px;
  margin-top: 20px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  box-shadow: 0 6px 20px rgba(0, 132, 61, 0.2);
}

.save-btn:hover {
  transform: translateY(-3px);
  box-shadow: 0 10px 25px rgba(0, 132, 61, 0.3);
}

.save-btn:active {
  transform: translateY(-1px);
}

/* Delete modal specific */
.delete-modal .modal-content h2 {
  color: var(--red);
}

.delete-modal .modal-content p {
  margin: 20px 0 30px;
  color: var(--dark);
  line-height: 1.8;
  font-size: 17px;
  text-align: center;
}

.delete-modal .modal-actions {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 20px;
  margin-top: 30px;
}

.delete-modal .cancel-btn {
  background: var(--blue);
}

.delete-modal .delete-confirm-btn {
  background: linear-gradient(135deg, var(--red), #e53935);
}

.delete-modal .delete-confirm-btn:hover {
  background: linear-gradient(135deg, #e53935, #d32f2f);
}

/* ===========================================
   ALERT POPUP
=========================================== */
.alert-popup {
  display: none;
  position: fixed;
  top: 100px;
  right: 30px;
  background: var(--white);
  border-radius: 12px;
  padding: 25px 30px;
  z-index: 5000;
  box-shadow: 0 15px 35px rgba(0,0,0,0.25);
  min-width: 350px;
  max-width: 450px;
  animation: slideInRight 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55);
  border-top: 5px solid transparent;
}

.alert-popup.show {
  display: block;
  animation: slideInRight 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55);
}

.alert-popup.success {
  border-top-color: var(--green);
}

.alert-popup.error {
  border-top-color: var(--red);
}

.alert-popup.warning {
  border-top-color: var(--warning);
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
  font-size: 28px;
  margin-bottom: 15px;
  display: block;
}

.alert-popup.success i {
  color: var(--green);
}

.alert-popup.error i {
  color: var(--red);
}

.alert-popup.warning i {
  color: var(--warning);
}

.alert-popup h3 {
  margin: 0;
  font-size: 17px;
  font-weight: 600;
  line-height: 1.5;
}

/* ===========================================
   RESPONSIVE DESIGN
=========================================== */

/* Large Desktop (1440px+) */
@media (min-width: 1440px) {
  .main-content {
    max-width: 1400px;
    margin-left: auto;
    margin-right: auto;
    padding: 30px 40px;
  }
  
  body.sidebar-collapsed .main-content {
    margin-left: auto;
    margin-right: auto;
  }
}

/* Desktop (1200px - 1440px) */
@media (max-width: 1440px) {
  .modal-content {
    max-width: 700px;
  }
  
  .filter-form {
    grid-template-columns: repeat(2, 1fr);
  }
}

/* Laptop (1024px - 1200px) */
@media (max-width: 1200px) {
  .main-content {
    margin-left: 200px;
    padding: 20px;
  }
  
  body.sidebar-collapsed .main-content {
    margin-left: 60px;
  }
  
  .filter-form {
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
  }
  
  .filter-actions {
    grid-column: 1 / -1;
    justify-content: flex-start;
  }
  
  table {
    min-width: 1000px;
  }
}

/* Tablet (768px - 1024px) */
@media (max-width: 1024px) {
  .main-content {
    margin-left: 0 !important;
    padding: 20px;
    margin-bottom: 80px;
  }
  
  .page-header {
    flex-direction: column;
    align-items: stretch;
    gap: 20px;
    text-align: center;
  }
  
  .page-title {
    justify-content: center;
  }
  
  .page-title h1 {
    font-size: 26px;
  }
  
  .add-btn {
    width: 100%;
    max-width: 300px;
    align-self: center;
    justify-content: center;
  }
  
  .filters-container {
    padding: 20px;
  }
  
  .filter-form {
    grid-template-columns: 1fr;
    gap: 20px;
  }
  
  .filter-actions {
    justify-content: stretch;
  }
  
  .filter-btn {
    flex: 1;
    justify-content: center;
  }
  
  .modal-content {
    padding: 30px 25px;
    max-width: 90%;
  }
  
  .modal-form {
    grid-template-columns: 1fr;
    gap: 20px;
  }
  
  .alert-popup {
    top: 80px;
    right: 20px;
    left: 20px;
    max-width: none;
    min-width: auto;
  }
}

/* Mobile (480px - 768px) */
@media (max-width: 768px) {
  .main-content {
    padding: 15px;
    margin-bottom: 70px;
  }
  
  .page-header {
    padding: 15px;
  }
  
  .page-title h1 {
    font-size: 24px;
  }
  
  .add-btn {
    padding: 10px 20px;
    font-size: 15px;
  }
  
  .filters-container {
    padding: 15px;
  }
  
  .filter-btn {
    padding: 10px 20px;
    font-size: 14px;
  }
  
  table {
    font-size: 14px;
    min-width: 800px;
  }
  
  thead th,
  tbody td {
    padding: 14px 16px;
  }
  
  .action-btns {
    gap: 8px;
  }
  
  .view-btn,
  .edit-btn,
  .del-btn {
    padding: 8px 12px;
    min-width: 36px;
    min-height: 36px;
    font-size: 13px;
  }
  
  .modal-content {
    padding: 25px 20px;
  }
  
  .modal-content h2 {
    font-size: 24px;
    margin-bottom: 25px;
  }
  
  .close-modal {
    top: 15px;
    right: 20px;
    font-size: 28px;
    width: 36px;
    height: 36px;
  }
  
  .form-group input,
  .form-group select {
    padding: 12px 15px;
    font-size: 14px;
  }
  
  .save-btn {
    padding: 16px;
    font-size: 16px;
  }
  
  .alert-popup {
    top: 70px;
    padding: 20px;
  }
}

/* Small Mobile (360px - 480px) */
@media (max-width: 480px) {
  .main-content {
    padding: 12px;
    margin-bottom: 60px;
  }
  
  .page-title h1 {
    font-size: 22px;
  }
  
  .add-btn {
    padding: 10px 15px;
    font-size: 14px;
    gap: 8px;
  }
  
  .filter-group input,
  .filter-group select {
    padding: 10px 12px;
    font-size: 13px;
  }
  
  table {
    font-size: 13px;
    min-width: 700px;
  }
  
  thead th,
  tbody td {
    padding: 12px 14px;
  }
  
  .action-btns {
    flex-direction: column;
    gap: 6px;
  }
  
  .view-btn,
  .edit-btn,
  .del-btn {
    width: 100%;
    justify-content: center;
  }
  
  .modal-content {
    padding: 20px 15px;
    border-radius: 12px;
  }
  
  .modal-content h2 {
    font-size: 22px;
    margin-bottom: 20px;
  }
  
  .close-modal {
    top: 10px;
    right: 15px;
    font-size: 24px;
    width: 32px;
    height: 32px;
  }
  
  .delete-modal .modal-actions {
    grid-template-columns: 1fr;
    gap: 15px;
  }
  
  .alert-popup {
    top: 60px;
    right: 10px;
    left: 10px;
    padding: 15px 20px;
  }
  
  .alert-popup h3 {
    font-size: 15px;
  }
}

/* Extra Small Mobile (< 360px) */
@media (max-width: 360px) {
  .page-title h1 {
    font-size: 20px;
  }
  
  .add-btn {
    padding: 8px 12px;
    font-size: 13px;
  }
  
  table {
    font-size: 12px;
    min-width: 600px;
  }
  
  thead th,
  tbody td {
    padding: 10px 12px;
  }
  
  .modal-content {
    padding: 15px 12px;
  }
  
  .modal-content h2 {
    font-size: 20px;
  }
}

/* Landscape Mode for Mobile */
@media (max-height: 500px) and (orientation: landscape) {
  .main-content {
    margin-bottom: 50px;
  }
  
  .filters-container {
    padding: 15px;
  }
  
  .table-wrapper {
    max-height: 300px;
  }
  
  .modal-content {
    max-height: 85vh;
    padding: 20px;
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
  .alert-popup {
    display: none !important;
  }
  
  .table-container {
    box-shadow: none;
    border: 1px solid #000;
  }
  
  thead {
    background: #fff !important;
    color: #000 !important;
  }
  
  thead th {
    color: #000 !important;
    border-bottom: 2px solid #000;
  }
  
  tbody tr {
    border-bottom: 1px solid #000;
  }
}

/* Accessibility Improvements */
@media (prefers-reduced-motion: reduce) {
  *,
  *::before,
  *::after {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
  }
}

/* High Contrast Mode */
@media (prefers-contrast: high) {
  :root {
    --green: #006400;
    --blue: #00008B;
    --red: #8B0000;
  }
  
  .modal-content,
  .filters-container,
  .table-container {
    border: 3px solid #000;
  }
  
  thead th {
    border-bottom: 3px solid #000;
  }
  
  tbody tr {
    border-bottom: 2px solid #000;
  }
}

/* Dark Mode Support */
@media (prefers-color-scheme: dark) {
  :root {
    --bg: #1a1a1a;
    --white: #2d2d2d;
    --border: #4d4d4d;
    --dark: #f0f0f0;
    --light: #3d3d3d;
    color-scheme: dark;
  }
  
  body {
    background: var(--bg);
    color: var(--dark);
  }
  
  .modal-content,
  .filters-container,
  .table-container,
  .page-header {
    background: var(--white);
    color: var(--dark);
  }
  
  input,
  select,
  textarea {
    background: #3d3d3d;
    color: var(--dark);
    border-color: #5d5d5d;
  }
  
  .alert-popup {
    background: var(--white);
    color: var(--dark);
  }
  
  .status-active {
    background: linear-gradient(135deg, #1e4620, #2d5e30);
    color: #c8e6c9;
  }
  
  .status-inactive {
    background: linear-gradient(135deg, #4a2323, #5c2a2a);
    color: #f8d7da;
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
    min-height: 44px;
    min-width: 44px;
  }
  
  .filter-select,
  .form-group input,
  .form-group select {
    font-size: 16px;
    padding: 16px 20px;
  }
  
  .table-wrapper {
    -webkit-overflow-scrolling: touch;
  }
  
  .action-btns {
    gap: 12px;
  }
}
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
  <div class="page-header">
    <div class="page-title">
      <h1><i class="fas fa-graduation-cap"></i> Program Management</h1>
      <div class="badge"><?= count($programs) ?> Programs</div>
    </div>
    
    <div class="header-actions">
      <button class="add-btn" onclick="openModal('addModal')" aria-label="Add new program">
        <i class="fas fa-plus-circle"></i> Add New Program
      </button>
    </div>
  </div>

  <!-- 🔹 FILTERS SECTION -->
  <div class="filters-container">
    <div class="filters-header">
      <i class="fas fa-filter"></i>
      <h3>Filter Programs</h3>
    </div>
    
    <div class="filter-form" id="filterForm">
      <div class="filter-group">
        <label><i class="fas fa-school"></i> Campus</label>
        <select class="filter-select" id="filter_campus" onchange="onCampusFilterChange(this.value)">
          <option value="">All Campuses</option>
          <?php foreach($campuses as $c): ?>
            <option value="<?= $c['campus_id'] ?>"><?= htmlspecialchars($c['campus_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      
      <div class="filter-group">
        <label><i class="fas fa-university"></i> Faculty</label>
        <select class="filter-select" id="filter_faculty" onchange="onFacultyFilterChange(this.value)">
          <option value="">All Faculties</option>
        </select>
      </div>
      
      <div class="filter-group">
        <label><i class="fas fa-building"></i> Department</label>
        <select class="filter-select" id="filter_department">
          <option value="">All Departments</option>
        </select>
      </div>
      
      <div class="filter-group">
        <label><i class="fas fa-circle"></i> Status</label>
        <select class="filter-select" id="filter_status">
          <option value="all">All Status</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
      
      <div class="filter-actions">
        <button class="filter-btn" onclick="applyFilters()" aria-label="Apply filters">
          <i class="fas fa-search"></i> Apply Filters
        </button>
        <button class="filter-btn reset" onclick="resetFilters()" aria-label="Reset filters">
          <i class="fas fa-redo"></i> Reset
        </button>
      </div>
    </div>
  </div>

  <!-- 🔹 TABLE SECTION -->
  <div class="table-container">
    <div class="table-header">
      <h3>Program List</h3>
    </div>
    
    <div class="table-wrapper">
      <table id="programsTable" aria-label="List of academic programs">
        <thead>
          <tr>
            <th>#</th>
            <th>Program Name</th>
            <th>Code</th>
            <th>Campus</th>
            <th>Faculty</th>
            <th>Department</th>
            <th>Years</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="programsTableBody">
          <?php if ($programs): foreach ($programs as $i=>$p): ?>
          <tr>
            <td><strong><?= $i+1 ?></strong></td>
            <td>
              <div style="font-weight:600;"><?= $p['program_name'] ?></div>
              <?php if(!empty($p['description'])): ?>
                <small style="color:#666;font-size:12px;">
                  <?= substr($p['description'], 0, 50) ?>...
                </small>
              <?php endif; ?>
            </td>
            <td>
              <code style="background:#f0f9ff;padding:3px 8px;border-radius:4px;font-weight:600;">
                <?= $p['program_code'] ?>
              </code>
            </td>
            <td><?= $p['campus_name'] ?></td>
            <td><?= $p['faculty_name'] ?></td>
            <td><?= $p['department_name'] ?></td>
            <td><strong><?= $p['duration_years'] ?> years</strong></td>
            <td>
              <span class="status-badge status-<?= $p['status'] ?>">
                <?= ucfirst($p['status']) ?>
              </span>
            </td>
            <td>
              <div class="action-btns">
                <button class="view-btn" 
                        onclick="openViewModal(
                          <?= $p['program_id'] ?>,
                          '<?= addslashes($p['program_name']) ?>',
                          '<?= addslashes($p['program_code']) ?>',
                          '<?= addslashes($p['description']) ?>',
                          '<?= $p['campus_name'] ?>',
                          '<?= $p['faculty_name'] ?>',
                          '<?= $p['department_name'] ?>',
                          '<?= $p['duration_years'] ?>',
                          '<?= $p['status'] ?>',
                          '<?= $p['created_at'] ?>',
                          '<?= $p['updated_at'] ?>'
                        )" 
                        title="View program details"
                        aria-label="View program <?= $p['program_name'] ?>">
                  <i class="fa-solid fa-eye"></i>
                </button>
                <button class="edit-btn" 
                        onclick="openEditModal(
                          <?= $p['program_id'] ?>,
                          '<?= addslashes($p['program_name']) ?>',
                          '<?= addslashes($p['program_code']) ?>',
                          <?= (int)$p['duration_years'] ?>,
                          '<?= addslashes($p['description']) ?>',
                          '<?= $p['status'] ?>',
                          '<?= $p['campus_id'] ?>',
                          '<?= $p['faculty_id'] ?>',
                          '<?= $p['department_id'] ?>'
                        )" 
                        title="Edit program"
                        aria-label="Edit program <?= $p['program_name'] ?>">
                  <i class="fa-solid fa-pen-to-square"></i>
                </button>
                <button class="del-btn" 
                        onclick="openDeleteModal(<?= $p['program_id'] ?>)" 
                        title="Delete program"
                        aria-label="Delete program <?= $p['program_name'] ?>">
                  <i class="fa-solid fa-trash"></i>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; else: ?>
            <tr>
              <td colspan="9">
                <div class="empty-state">
                  <i class="fas fa-graduation-cap"></i>
                  <h3>No Programs Found</h3>
                  <p>Click "Add New Program" to create your first program</p>
                </div>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- 🔹 ADD MODAL -->
<div class="modal" id="addModal" role="dialog" aria-labelledby="addModalTitle" aria-hidden="true">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('addModal')" aria-label="Close modal">&times;</span>
    <h2 id="addModalTitle"><i class="fas fa-plus-circle"></i> Add New Program</h2>
    
    <form class="modal-form" method="POST" id="addForm" onsubmit="return validateForm('add')">
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

      <div class="form-group">
        <label class="required"><i class="fas fa-book"></i> Program Name</label>
        <input type="text" name="program_name" required placeholder="Enter program name" 
               aria-required="true" maxlength="100">
      </div>

      <div class="form-group">
        <label class="required"><i class="fas fa-code"></i> Program Code</label>
        <input type="text" name="program_code" required placeholder="e.g., CS101" 
               aria-required="true" maxlength="20">
      </div>

      <div class="form-group">
        <label class="required"><i class="fas fa-school"></i> Campus</label>
        <select id="add_campus" name="campus_id" required 
                onchange="loadFacultiesByCampus(this.value, 'add')"
                aria-required="true">
          <option value="">Select Campus</option>
          <?php foreach($campuses as $c): ?>
            <option value="<?= $c['campus_id'] ?>"><?= htmlspecialchars($c['campus_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="required"><i class="fas fa-university"></i> Faculty</label>
        <select id="add_faculty" name="faculty_id" required 
                onchange="loadDepartmentsByFaculty(this.value, 'add')"
                aria-required="true" disabled>
          <option value="">Select Faculty First</option>
        </select>
      </div>

      <div class="form-group">
        <label><i class="fas fa-building"></i> Department (Optional)</label>
        <select id="add_department" name="department_id" disabled>
          <option value="">Select Department</option>
        </select>
      </div>

      <div class="form-group">
        <label class="required"><i class="fas fa-calendar-alt"></i> Duration (Years)</label>
        <input type="number" name="duration_years" value="4" min="1" max="8" required 
               aria-required="true">
      </div>

      <div class="form-group">
        <label class="required"><i class="fas fa-circle"></i> Status</label>
        <select name="status" required aria-required="true">
          <option value="active" selected>Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>

      <div class="form-group full-width">
        <label><i class="fas fa-file-alt"></i> Description</label>
        <textarea name="description" placeholder="Enter program description..." 
                  maxlength="500"></textarea>
      </div>

      <button class="save-btn" type="submit" id="addSubmitBtn">
        <i class="fas fa-save"></i> Save Program
      </button>
    </form>
  </div>
</div>

<!-- 🔹 VIEW MODAL -->
<div class="modal view-modal" id="viewModal" role="dialog" aria-labelledby="viewModalTitle" aria-hidden="true">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('viewModal')" aria-label="Close modal">&times;</span>
    <h2 id="viewModalTitle"><i class="fas fa-eye"></i> Program Details</h2>
    
    <div class="modal-form">
      <div class="form-group">
        <label><i class="fas fa-book"></i> Program Name</label>
        <div class="detail-value" id="view_name"></div>
      </div>
      
      <div class="form-group">
        <label><i class="fas fa-code"></i> Program Code</label>
        <div class="detail-value" id="view_code"></div>
      </div>
      
      <div class="form-group">
        <label><i class="fas fa-school"></i> Campus</label>
        <div class="detail-value" id="view_campus"></div>
      </div>
      
      <div class="form-group">
        <label><i class="fas fa-university"></i> Faculty</label>
        <div class="detail-value" id="view_faculty"></div>
      </div>
      
      <div class="form-group">
        <label><i class="fas fa-building"></i> Department</label>
        <div class="detail-value" id="view_department"></div>
      </div>
      
      <div class="form-group">
        <label><i class="fas fa-calendar-alt"></i> Duration</label>
        <div class="detail-value" id="view_duration"></div>
      </div>
      
      <div class="form-group">
        <label><i class="fas fa-circle"></i> Status</label>
        <div class="detail-value" id="view_status"></div>
      </div>
      
      <div class="form-group">
        <label><i class="fas fa-clock"></i> Created Date</label>
        <div class="detail-value" id="view_created"></div>
      </div>
      
      <div class="form-group">
        <label><i class="fas fa-clock"></i> Updated Date</label>
        <div class="detail-value" id="view_updated"></div>
      </div>
      
      <div class="form-group full-width">
        <label><i class="fas fa-file-alt"></i> Description</label>
        <div class="detail-value description" id="view_description"></div>
      </div>
    </div>
    
    <div style="text-align: center; margin-top: 30px;">
      <button class="save-btn" onclick="closeModal('viewModal')" style="background: #6c757d;">
        <i class="fas fa-times"></i> Close
      </button>
    </div>
  </div>
</div>

<!-- 🔹 EDIT MODAL -->
<div class="modal" id="editModal" role="dialog" aria-labelledby="editModalTitle" aria-hidden="true">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('editModal')" aria-label="Close modal">&times;</span>
    <h2 id="editModalTitle"><i class="fas fa-edit"></i> Edit Program</h2>
    
    <form class="modal-form" method="POST" id="editForm" onsubmit="return validateForm('edit')">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
      <input type="hidden" id="edit_id" name="program_id">

      <div class="form-group">
        <label class="required"><i class="fas fa-book"></i> Program Name</label>
        <input type="text" id="edit_name" name="program_name" required>
      </div>

      <div class="form-group">
        <label class="required"><i class="fas fa-code"></i> Program Code</label>
        <input type="text" id="edit_code" name="program_code" required>
      </div>

      <div class="form-group">
        <label class="required"><i class="fas fa-school"></i> Campus</label>
        <select id="edit_campus" name="campus_id" required 
                onchange="loadFacultiesByCampus(this.value, 'edit')">
          <option value="">Select Campus</option>
          <?php foreach($campuses as $c): ?>
            <option value="<?= $c['campus_id'] ?>"><?= htmlspecialchars($c['campus_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="required"><i class="fas fa-university"></i> Faculty</label>
        <select id="edit_faculty" name="faculty_id" required 
                onchange="loadDepartmentsByFaculty(this.value, 'edit')">
          <option value="">Select Faculty</option>
        </select>
      </div>

      <div class="form-group">
        <label><i class="fas fa-building"></i> Department (Optional)</label>
        <select id="edit_department" name="department_id">
          <option value="">Select Department</option>
        </select>
      </div>

      <div class="form-group">
        <label class="required"><i class="fas fa-calendar-alt"></i> Duration (Years)</label>
        <input type="number" id="edit_duration" name="duration_years" min="1" max="8" required>
      </div>

      <div class="form-group">
        <label class="required"><i class="fas fa-circle"></i> Status</label>
        <select id="edit_status" name="status" required>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>

      <div class="form-group full-width">
        <label><i class="fas fa-file-alt"></i> Description</label>
        <textarea id="edit_desc" name="description"></textarea>
      </div>

      <button class="save-btn" type="submit" id="editSubmitBtn">
        <i class="fas fa-sync-alt"></i> Update Program
      </button>
    </form>
  </div>
</div>

<!-- 🔹 DELETE MODAL -->
<div class="modal delete-modal" id="deleteModal" role="dialog" aria-labelledby="deleteModalTitle" aria-hidden="true">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('deleteModal')" aria-label="Close modal">&times;</span>
    <h2 id="deleteModalTitle" style="color: var(--red);"><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h2>
    
    <form method="POST" id="deleteForm">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
      <input type="hidden" name="program_id" id="delete_id">
      
      <div style="text-align: center; margin: 30px 0;">
        <i class="fas fa-exclamation-triangle" style="font-size: 60px; color: var(--red); margin-bottom: 20px;"></i>
        <p style="font-size: 16px; color: var(--dark); margin-bottom: 10px; line-height: 1.6;">
          Are you sure you want to delete this program?
        </p>
        <p style="color: #666; font-size: 14px;">
          This action cannot be undone. All associated data will be permanently removed.
        </p>
      </div>
      
      <div class="modal-actions">
        <button type="button" class="save-btn cancel-btn" onclick="closeModal('deleteModal')">
          <i class="fas fa-times"></i> Cancel
        </button>
        <button type="submit" class="save-btn delete-confirm-btn" id="deleteSubmitBtn">
          <i class="fas fa-trash"></i> Yes, Delete
        </button>
      </div>
    </form>
  </div>
</div>

<!-- 🔹 ALERT POPUP -->
<div id="popup" class="alert-popup <?= $type ?> <?= !empty($message) ? 'show' : '' ?>" role="alert" aria-live="assertive">
  <i><?= $type === 'success' ? '✅' : ($type === 'warning' ? '⚠️' : '❌') ?></i>
  <h3><?= htmlspecialchars($message) ?></h3>
</div>

<script>
// CSRF token from PHP
const csrfToken = '<?= $_SESSION['csrf_token'] ?>';
const currentFile = '<?= $current_file ?>';

// ✅ MODAL FUNCTIONS
function openModal(id) {
    const modal = document.getElementById(id);
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    document.body.style.paddingRight = getScrollbarWidth() + 'px';
}

function closeModal(id) {
    const modal = document.getElementById(id);
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = 'auto';
    document.body.style.paddingRight = '0';
}

function getScrollbarWidth() {
    return window.innerWidth - document.documentElement.clientWidth;
}

function openViewModal(id, name, code, desc, campus, faculty, department, duration, status, created, updated) {
    openModal('viewModal');
    
    // Decode HTML entities
    const decodeHTML = (html) => {
        const txt = document.createElement('textarea');
        txt.innerHTML = html;
        return txt.value;
    };
    
    document.getElementById('view_name').textContent = decodeHTML(name) || '—';
    document.getElementById('view_code').textContent = decodeHTML(code) || '—';
    document.getElementById('view_description').textContent = decodeHTML(desc) || '—';
    document.getElementById('view_campus').textContent = decodeHTML(campus) || '—';
    document.getElementById('view_faculty').textContent = decodeHTML(faculty) || '—';
    document.getElementById('view_department').textContent = decodeHTML(department) || '—';
    document.getElementById('view_duration').textContent = `${duration} years`;
    document.getElementById('view_status').innerHTML = `
        <span class="status-badge status-${status}">
            ${status.charAt(0).toUpperCase() + status.slice(1)}
        </span>
    `;
    document.getElementById('view_created').textContent = formatDate(created);
    document.getElementById('view_updated').textContent = formatDate(updated);
}

function openEditModal(id, name, code, duration, desc, status, campusId, facultyId, departmentId) {
    openModal('editModal');
    
    const decodeHTML = (html) => {
        const txt = document.createElement('textarea');
        txt.innerHTML = html;
        return txt.value;
    };
    
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_name').value = decodeHTML(name);
    document.getElementById('edit_code').value = decodeHTML(code);
    document.getElementById('edit_duration').value = duration;
    document.getElementById('edit_desc').value = decodeHTML(desc);
    document.getElementById('edit_status').value = status;
    document.getElementById('edit_campus').value = campusId;
    
    loadFacultiesByCampus(campusId, 'edit', facultyId, departmentId);
}

function openDeleteModal(id) {
    openModal('deleteModal');
    document.getElementById('delete_id').value = id;
}

function formatDate(dateString) {
    if (!dateString) return '—';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

// ✅ FILTER FUNCTIONS
function onCampusFilterChange(campusId) {
    const facultySelect = document.getElementById('filter_faculty');
    const deptSelect = document.getElementById('filter_department');
    
    if (!campusId) {
        facultySelect.innerHTML = '<option value="">All Faculties</option>';
        facultySelect.disabled = false;
        deptSelect.innerHTML = '<option value="">All Departments</option>';
        return;
    }
    
    facultySelect.innerHTML = '<option value="">Loading...</option>';
    facultySelect.disabled = true;
    
    fetch(`${currentFile}?ajax_action=get_faculties_by_campus&campus_id=${campusId}&csrf_token=${csrfToken}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                facultySelect.innerHTML = '<option value="">All Faculties</option>';
                data.faculties.forEach(faculty => {
                    const option = document.createElement('option');
                    option.value = faculty.faculty_id;
                    option.textContent = faculty.faculty_name;
                    facultySelect.appendChild(option);
                });
                facultySelect.disabled = false;
            } else {
                showAlert('Error loading faculties', 'error');
            }
        })
        .catch(error => {
            console.error('Error loading faculties:', error);
            facultySelect.innerHTML = '<option value="">Error loading</option>';
            facultySelect.disabled = false;
        });
    
    deptSelect.innerHTML = '<option value="">All Departments</option>';
}

function onFacultyFilterChange(facultyId) {
    const deptSelect = document.getElementById('filter_department');
    const campusSelect = document.getElementById('filter_campus');
    const campusId = campusSelect ? campusSelect.value : null;
    
    if (!facultyId || !campusId) {
        deptSelect.innerHTML = '<option value="">All Departments</option>';
        return;
    }
    
    deptSelect.innerHTML = '<option value="">Loading...</option>';
    deptSelect.disabled = true;
    
    fetch(`${currentFile}?ajax_action=get_departments_by_faculty&faculty_id=${facultyId}&campus_id=${campusId}&csrf_token=${csrfToken}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                deptSelect.innerHTML = '<option value="">All Departments</option>';
                data.departments.forEach(dept => {
                    const option = document.createElement('option');
                    option.value = dept.department_id;
                    option.textContent = dept.department_name;
                    deptSelect.appendChild(option);
                });
                deptSelect.disabled = false;
            } else {
                showAlert('Error loading departments', 'error');
            }
        })
        .catch(error => {
            console.error('Error loading departments:', error);
            deptSelect.innerHTML = '<option value="">Error loading</option>';
            deptSelect.disabled = false;
        });
}

function applyFilters() {
    const campusId = document.getElementById('filter_campus').value;
    const facultyId = document.getElementById('filter_faculty').value;
    const departmentId = document.getElementById('filter_department').value;
    const status = document.getElementById('filter_status').value;
    
    // Show loading
    const tbody = document.getElementById('programsTableBody');
    tbody.innerHTML = `
        <tr>
            <td colspan="9" style="text-align:center; padding:40px;">
                <div style="color:#666;">
                    <i class="fas fa-spinner fa-spin" style="font-size:24px; margin-bottom:10px;"></i>
                    <p>Loading filtered programs...</p>
                </div>
            </td>
        </tr>
    `;
    
    // Build query string
    let query = `ajax_action=get_filtered_programs&csrf_token=${csrfToken}`;
    if (campusId) query += `&campus_id=${campusId}`;
    if (facultyId) query += `&faculty_id=${facultyId}`;
    if (departmentId) query += `&department_id=${departmentId}`;
    if (status && status !== 'all') query += `&status=${status}`;
    
    fetch(`${currentFile}?${query}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                updateTable(data.programs);
                updateBadgeCount(data.programs.length);
            } else {
                showAlert('Error loading filtered data', 'error');
            }
        })
        .catch(error => {
            console.error('Error fetching filtered programs:', error);
            showAlert('Network error. Please check your connection.', 'error');
        });
}

function resetFilters() {
    document.getElementById('filter_campus').value = '';
    document.getElementById('filter_faculty').innerHTML = '<option value="">All Faculties</option>';
    document.getElementById('filter_department').innerHTML = '<option value="">All Departments</option>';
    document.getElementById('filter_status').value = 'all';
    applyFilters();
}

function updateTable(programs) {
    const tbody = document.getElementById('programsTableBody');
    
    if (!programs || programs.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="9">
                    <div class="empty-state">
                        <i class="fas fa-graduation-cap"></i>
                        <h3>No Programs Found</h3>
                        <p>No programs match your filter criteria</p>
                    </div>
                </td>
            </tr>
        `;
        return;
    }
    
    let html = '';
    programs.forEach((p, i) => {
        const description = p.description ? 
            `<small style="color:#666;font-size:12px;">${escapeHtml(p.description.substring(0, 50))}...</small>` : '';
        
        html += `
            <tr>
                <td><strong>${i + 1}</strong></td>
                <td>
                    <div style="font-weight:600;">${escapeHtml(p.program_name)}</div>
                    ${description}
                </td>
                <td><code style="background:#f0f9ff;padding:3px 8px;border-radius:4px;font-weight:600;">${escapeHtml(p.program_code)}</code></td>
                <td>${escapeHtml(p.campus_name)}</td>
                <td>${escapeHtml(p.faculty_name)}</td>
                <td>${escapeHtml(p.department_name)}</td>
                <td><strong>${escapeHtml(p.duration_years)} years</strong></td>
                <td>
                    <span class="status-badge status-${p.status}">
                        ${p.status.charAt(0).toUpperCase() + p.status.slice(1)}
                    </span>
                </td>
                <td>
                    <div class="action-btns">
                        <button class="view-btn" 
                                onclick="openViewModal(
                                    ${p.program_id},
                                    '${escapeSingleQuote(p.program_name)}',
                                    '${escapeSingleQuote(p.program_code)}',
                                    '${escapeSingleQuote(p.description || '')}',
                                    '${escapeSingleQuote(p.campus_name)}',
                                    '${escapeSingleQuote(p.faculty_name)}',
                                    '${escapeSingleQuote(p.department_name)}',
                                    ${p.duration_years},
                                    '${p.status}',
                                    '${p.created_at}',
                                    '${p.updated_at}'
                                )" 
                                title="View program details"
                                aria-label="View program ${escapeHtml(p.program_name)}">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                        <button class="edit-btn" 
                                onclick="openEditModal(
                                    ${p.program_id},
                                    '${escapeSingleQuote(p.program_name)}',
                                    '${escapeSingleQuote(p.program_code)}',
                                    ${parseInt(p.duration_years)},
                                    '${escapeSingleQuote(p.description || '')}',
                                    '${p.status}',
                                    '${p.campus_id}',
                                    '${p.faculty_id}',
                                    '${p.department_id}'
                                )" 
                                title="Edit program"
                                aria-label="Edit program ${escapeHtml(p.program_name)}">
                            <i class="fa-solid fa-pen-to-square"></i>
                        </button>
                        <button class="del-btn" 
                                onclick="openDeleteModal(${p.program_id})" 
                                title="Delete program"
                                aria-label="Delete program ${escapeHtml(p.program_name)}">
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
        badge.textContent = `${count} Program${count !== 1 ? 's' : ''}`;
    }
}

// ✅ AJAX: Load faculties by campus
function loadFacultiesByCampus(campusId, prefix, selectedFacultyId = null, selectedDepartmentId = null) {
    const facultySelect = document.getElementById(prefix + '_faculty');
    const deptSelect = document.getElementById(prefix + '_department');
    
    if (!campusId) {
        facultySelect.innerHTML = '<option value="">Select Faculty</option>';
        facultySelect.disabled = true;
        deptSelect.innerHTML = '<option value="">Select Department</option>';
        deptSelect.disabled = true;
        return;
    }
    
    facultySelect.innerHTML = '<option value="">Loading...</option>';
    facultySelect.disabled = true;
    deptSelect.innerHTML = '<option value="">Select Department</option>';
    deptSelect.disabled = true;
    
    fetch(`${currentFile}?ajax_action=get_faculties_by_campus&campus_id=${campusId}&csrf_token=${csrfToken}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                facultySelect.innerHTML = '<option value="">Select Faculty</option>';
                if (data.faculties.length > 0) {
                    data.faculties.forEach(faculty => {
                        const option = document.createElement('option');
                        option.value = faculty.faculty_id;
                        option.textContent = faculty.faculty_name;
                        facultySelect.appendChild(option);
                    });
                    facultySelect.disabled = false;
                    
                    // Set selected faculty if provided
                    if (selectedFacultyId) {
                        setTimeout(() => {
                            facultySelect.value = selectedFacultyId;
                            loadDepartmentsByFaculty(selectedFacultyId, prefix, selectedDepartmentId);
                        }, 100);
                    }
                } else {
                    facultySelect.innerHTML = '<option value="">No faculties found</option>';
                    facultySelect.disabled = true;
                }
            } else {
                showAlert('Error loading faculties', 'error');
            }
        })
        .catch(error => {
            console.error('Error loading faculties:', error);
            facultySelect.innerHTML = '<option value="">Error loading</option>';
            facultySelect.disabled = false;
        });
}

// ✅ AJAX: Load departments by faculty
function loadDepartmentsByFaculty(facultyId, prefix, selectedDepartmentId = null) {
    const deptSelect = document.getElementById(prefix + '_department');
    const campusSelect = document.getElementById(prefix + '_campus');
    const campusId = campusSelect ? campusSelect.value : null;
    
    if (!facultyId || !campusId) {
        deptSelect.innerHTML = '<option value="">Select Department</option>';
        deptSelect.disabled = true;
        return;
    }
    
    deptSelect.innerHTML = '<option value="">Loading...</option>';
    deptSelect.disabled = true;
    
    fetch(`${currentFile}?ajax_action=get_departments_by_faculty&faculty_id=${facultyId}&campus_id=${campusId}&csrf_token=${csrfToken}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                deptSelect.innerHTML = '<option value="">Select Department</option>';
                if (data.departments.length > 0) {
                    data.departments.forEach(dept => {
                        const option = document.createElement('option');
                        option.value = dept.department_id;
                        option.textContent = dept.department_name;
                        deptSelect.appendChild(option);
                    });
                    deptSelect.disabled = false;
                    
                    // Set selected department if provided
                    if (selectedDepartmentId) {
                        setTimeout(() => {
                            deptSelect.value = selectedDepartmentId;
                        }, 100);
                    }
                } else {
                    deptSelect.innerHTML = '<option value="">No departments found</option>';
                    deptSelect.disabled = false;
                }
            } else {
                showAlert('Error loading departments', 'error');
            }
        })
        .catch(error => {
            console.error('Error loading departments:', error);
            deptSelect.innerHTML = '<option value="">Error loading</option>';
            deptSelect.disabled = false;
        });
}

// ✅ FORM VALIDATION
function validateForm(formType) {
    const form = document.getElementById(formType + 'Form');
    const campus = form.querySelector('select[name="campus_id"]');
    const faculty = form.querySelector('select[name="faculty_id"]');
    const programName = form.querySelector('input[name="program_name"]');
    const programCode = form.querySelector('input[name="program_code"]');
    const duration = form.querySelector('input[name="duration_years"]');
    
    const errors = [];
    
    if (!campus || !campus.value) {
        errors.push('Please select a campus');
        campus?.focus();
    } else if (!faculty || !faculty.value) {
        errors.push('Please select a faculty');
        faculty?.focus();
    } else if (!programName || !programName.value.trim()) {
        errors.push('Please enter program name');
        programName?.focus();
    } else if (!programCode || !programCode.value.trim()) {
        errors.push('Please enter program code');
        programCode?.focus();
    } else if (!duration || duration.value < 1 || duration.value > 8) {
        errors.push('Duration must be between 1 and 8 years');
        duration?.focus();
    }
    
    if (errors.length > 0) {
        showAlert(errors[0], 'error');
        return false;
    }
    
    return true;
}

// ✅ UTILITY FUNCTIONS
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function escapeSingleQuote(text) {
    return text.replace(/'/g, "\\'");
}

function showAlert(message, type = 'info') {
    // Create alert element
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert-popup ${type} show`;
    alertDiv.innerHTML = `
        <i>${type === 'success' ? '✅' : type === 'error' ? '❌' : '⚠️'}</i>
        <h3>${escapeHtml(message)}</h3>
    `;
    
    document.body.appendChild(alertDiv);
    
    // Remove alert after 5 seconds
    setTimeout(() => {
        if (alertDiv.parentNode) {
            alertDiv.classList.remove('show');
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.parentNode.removeChild(alertDiv);
                }
            }, 500);
        }
    }, 5000);
}

// ✅ EVENT LISTENERS
// Close modal when clicking outside
window.addEventListener('click', function(event) {
    if (event.target.classList.contains('modal')) {
        const modals = document.querySelectorAll('.modal.show');
        modals.forEach(modal => {
            closeModal(modal.id);
        });
    }
});

// Keyboard shortcuts
document.addEventListener('keydown', function(event) {
    // Escape key closes modals
    if (event.key === 'Escape') {
        const openModals = document.querySelectorAll('.modal.show');
        openModals.forEach(modal => {
            closeModal(modal.id);
        });
    }
    
    // Ctrl + F focuses on filter search
    if (event.ctrlKey && event.key === 'f') {
        event.preventDefault();
        document.getElementById('filter_campus')?.focus();
    }
});

// Auto-hide alert after 5 seconds
setTimeout(function() {
    const alert = document.getElementById('popup');
    if (alert && alert.classList.contains('show')) {
        alert.classList.remove('show');
        setTimeout(() => {
            if (alert.parentNode) {
                alert.parentNode.removeChild(alert);
            }
        }, 500);
    }
}, 5000);

// Loading state for buttons
document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', function(e) {
        const submitBtn = this.querySelector('button[type="submit"]');
        if (submitBtn && !submitBtn.disabled) {
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            submitBtn.disabled = true;
            
            // Re-enable button after 10 seconds (in case of error)
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 10000);
        }
    });
});

// Responsive adjustments
function adjustLayout() {
    const actionButtons = document.querySelectorAll('.action-btns');
    const isMobile = window.innerWidth < 768;
    
    actionButtons.forEach(btnGroup => {
        if (isMobile) {
            btnGroup.style.flexDirection = 'column';
            btnGroup.style.alignItems = 'center';
        } else {
            btnGroup.style.flexDirection = 'row';
        }
    });
}

// Initial call
adjustLayout();

// Adjust on resize
window.addEventListener('resize', adjustLayout);

// Touch device optimizations
if ('ontouchstart' in window || navigator.maxTouchPoints > 0) {
    document.body.classList.add('touch-device');
    
    // Add touch feedback to buttons
    const buttons = document.querySelectorAll('.add-btn, .filter-btn, .view-btn, .edit-btn, .del-btn, .save-btn');
    buttons.forEach(btn => {
        btn.addEventListener('touchstart', function() {
            this.style.opacity = '0.7';
        });
        
        btn.addEventListener('touchend', function() {
            this.style.opacity = '';
        });
    });
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Prevent form resubmission on page refresh
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }
    
    // Initialize badge count
    const initialCount = <?= count($programs) ?>;
    updateBadgeCount(initialCount);
});
</script>

<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>