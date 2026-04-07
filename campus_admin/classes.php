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

// Role-based access control
$user_role = strtolower($_SESSION['user']['role'] ?? '');
$allowed_roles = ['super_admin', 'campus_admin', 'faculty_admin', 'department_admin'];

if (!in_array($user_role, $allowed_roles)) {
    header("Location: ../login.php");
    exit;
}

// Get user's linked info for filtering
$linked_id = $_SESSION['user']['linked_id'] ?? null;
$linked_table = $_SESSION['user']['linked_table'] ?? null;

// Campus admin: only sees their campus
$user_campus_id = null;
$user_faculty_id = null;
$user_department_id = null;

if ($user_role === 'campus_admin' && $linked_table === 'campus') {
    $user_campus_id = $linked_id;
} 
elseif ($user_role === 'faculty_admin' && $linked_table === 'faculty') {
    $user_faculty_id = $linked_id;
    
    // Get campus for this faculty
    if ($user_faculty_id) {
        $stmt = $pdo->prepare("SELECT campus_id FROM faculty_campus WHERE faculty_id = ? LIMIT 1");
        $stmt->execute([$user_faculty_id]);
        $user_campus_id = $stmt->fetchColumn();
    }
}
elseif ($user_role === 'department_admin' && $linked_table === 'department') {
    $user_department_id = $linked_id;
    
    // Get faculty and campus for this department
    if ($user_department_id) {
        $stmt = $pdo->prepare("SELECT faculty_id, campus_id FROM departments WHERE department_id = ?");
        $stmt->execute([$user_department_id]);
        $dept = $stmt->fetch(PDO::FETCH_ASSOC);
        $user_faculty_id = $dept['faculty_id'] ?? null;
        $user_campus_id = $dept['campus_id'] ?? null;
    }
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
    
    if ($action === 'get_faculties_by_campus') {
        $campus_id = intval($_GET['campus_id'] ?? 0);
        
        // Check if user has access to this campus
        if ($user_role !== 'super_admin' && $user_campus_id && $user_campus_id != $campus_id) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        
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
        
        // Check if user has access
        if ($user_role !== 'super_admin') {
            if ($user_campus_id && $user_campus_id != $campus_id) {
                echo json_encode(['status' => 'error', 'message' => 'Access denied']);
                exit;
            }
            if ($user_faculty_id && $user_faculty_id != $faculty_id) {
                echo json_encode(['status' => 'error', 'message' => 'Access denied']);
                exit;
            }
        }
        
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
    
    if ($action === 'get_programs_by_department') {
        $department_id = intval($_GET['department_id'] ?? 0);
        $campus_id = intval($_GET['campus_id'] ?? 0);
        $faculty_id = intval($_GET['faculty_id'] ?? 0);
        
        // Check if user has access
        if ($user_role !== 'super_admin') {
            if ($user_campus_id && $user_campus_id != $campus_id) {
                echo json_encode(['status' => 'error', 'message' => 'Access denied']);
                exit;
            }
            if ($user_faculty_id && $user_faculty_id != $faculty_id) {
                echo json_encode(['status' => 'error', 'message' => 'Access denied']);
                exit;
            }
            if ($user_department_id && $user_department_id != $department_id) {
                echo json_encode(['status' => 'error', 'message' => 'Access denied']);
                exit;
            }
        }
        
        if (!$department_id || !$campus_id || !$faculty_id) {
            echo json_encode(['status' => 'error', 'message' => 'Department, Campus and Faculty ID required']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare("
                SELECT program_id, program_name, program_code 
                FROM programs 
                WHERE department_id = ? AND campus_id = ? AND faculty_id = ? AND status = 'active'
                ORDER BY program_name
            ");
            $stmt->execute([$department_id, $campus_id, $faculty_id]);
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
        $campus_id = intval($_GET['campus_id'] ?? 0);
        $faculty_id = intval($_GET['faculty_id'] ?? 0);
        $department_id = intval($_GET['department_id'] ?? 0);
        $program_id = intval($_GET['program_id'] ?? 0);
        $study_mode = in_array($_GET['study_mode'] ?? '', ['Full-Time', 'Part-Time']) ? $_GET['study_mode'] : '';
        $status = in_array($_GET['status'] ?? '', ['Active', 'Inactive']) ? $_GET['status'] : '';
        
        // Apply user's role-based filters
        $user_filters = [];
        $user_params = [];
        
        if ($user_role !== 'super_admin') {
            if ($user_campus_id) {
                $user_filters[] = "c.campus_id = ?";
                $user_params[] = $user_campus_id;
            }
            if ($user_faculty_id) {
                $user_filters[] = "c.faculty_id = ?";
                $user_params[] = $user_faculty_id;
            }
            if ($user_department_id) {
                $user_filters[] = "c.department_id = ?";
                $user_params[] = $user_department_id;
            }
        }
        
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
                WHERE 1=1
            ";
            
            $params = [];
            
            // Add user's role-based filters
            foreach ($user_filters as $filter) {
                $sql .= " AND " . $filter;
            }
            $params = array_merge($params, $user_params);
            
            // Add request filters
            if ($campus_id) {
                $sql .= " AND c.campus_id = ?";
                $params[] = $campus_id;
            }
            
            if ($faculty_id) {
                $sql .= " AND c.faculty_id = ?";
                $params[] = $faculty_id;
            }
            
            if ($department_id) {
                $sql .= " AND c.department_id = ?";
                $params[] = $department_id;
            }
            
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
// CRUD OPERATIONS (WITH ACCESS CONTROL)
// ===========================================
$message = "";
$type = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $action = $_POST['action'];
        
        // Input validation and sanitization
        $class_name = trim(htmlspecialchars($_POST['class_name'] ?? '', ENT_QUOTES, 'UTF-8'));
        $campus_id = intval($_POST['campus_id'] ?? 0);
        $faculty_id = intval($_POST['faculty_id'] ?? 0);
        $department_id = intval($_POST['department_id'] ?? 0);
        $program_id = intval($_POST['program_id'] ?? 0);
        $study_mode = in_array($_POST['study_mode'] ?? '', ['Full-Time', 'Part-Time']) ? $_POST['study_mode'] : 'Full-Time';
        $status = in_array($_POST['status'] ?? '', ['Active', 'Inactive']) ? $_POST['status'] : 'Active';
        
        // Check if user has permission to manage this campus/faculty/department
        if ($user_role !== 'super_admin') {
            if ($user_campus_id && $user_campus_id != $campus_id) {
                throw new Exception("You don't have permission to manage this campus");
            }
            if ($user_faculty_id && $user_faculty_id != $faculty_id) {
                throw new Exception("You don't have permission to manage this faculty");
            }
            if ($user_department_id && $user_department_id != $department_id) {
                throw new Exception("You don't have permission to manage this department");
            }
        }
        
        if ($action === 'add') {
            if (empty($class_name) || !$campus_id || !$faculty_id || !$department_id || !$program_id) {
                throw new Exception("All required fields must be filled");
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
                $message = "⚠️ Class with same name already exists in this program and study mode for this campus!";
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
            
            if (empty($class_name) || !$campus_id || !$faculty_id || !$department_id || !$program_id) {
                throw new Exception("All required fields must be filled");
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
                $message = "⚠️ Class with same name already exists in this program and study mode for this campus!";
                $type = "warning";
            } else {
                $stmt = $pdo->prepare("UPDATE classes 
                    SET class_name=?, campus_id=?, faculty_id=?, department_id=?, program_id=?, study_mode=?, status=?, updated_at=NOW()
                    WHERE class_id=?");
                $stmt->execute([
                    $class_name,
                    $campus_id,
                    $faculty_id,
                    $department_id,
                    $program_id,
                    $study_mode,
                    $status,
                    $class_id
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
            
            // Check if class belongs to user's domain
            if ($user_role !== 'super_admin') {
                $check = $pdo->prepare("
                    SELECT campus_id, faculty_id, department_id 
                    FROM classes 
                    WHERE class_id = ?
                ");
                $check->execute([$class_id]);
                $class = $check->fetch(PDO::FETCH_ASSOC);
                
                if ($class) {
                    if ($user_campus_id && $user_campus_id != $class['campus_id']) {
                        throw new Exception("You don't have permission to delete this class");
                    }
                    if ($user_faculty_id && $user_faculty_id != $class['faculty_id']) {
                        throw new Exception("You don't have permission to delete this class");
                    }
                    if ($user_department_id && $user_department_id != $class['department_id']) {
                        throw new Exception("You don't have permission to delete this class");
                    }
                }
            }
            
            $stmt = $pdo->prepare("DELETE FROM classes WHERE class_id=?");
            $stmt->execute([$class_id]);
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
// FETCH INITIAL DATA (WITH ACCESS CONTROL)
// ===========================================
try {
    // Build query with user's role-based filters
    $sql = "
        SELECT c.*, 
               camp.campus_name,
               f.faculty_name, 
               d.department_name,
               p.program_name,
               p.program_code
        FROM classes c
        LEFT JOIN campus camp ON c.campus_id = camp.campus_id AND camp.status = 'active'
        LEFT JOIN faculties f ON c.faculty_id = f.faculty_id AND f.status = 'active'
        LEFT JOIN departments d ON c.department_id = d.department_id AND d.status = 'active'
        LEFT JOIN programs p ON c.program_id = p.program_id AND p.status = 'active'
        WHERE 1=1
    ";
    
    $params = [];
    
    // Apply user's role-based filters
    if ($user_role !== 'super_admin') {
        if ($user_campus_id) {
            $sql .= " AND c.campus_id = ?";
            $params[] = $user_campus_id;
        }
        if ($user_faculty_id) {
            $sql .= " AND c.faculty_id = ?";
            $params[] = $user_faculty_id;
        }
        if ($user_department_id) {
            $sql .= " AND c.department_id = ?";
            $params[] = $user_department_id;
        }
    }
    
    $sql .= " ORDER BY c.class_id DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Sanitize fetched data
    foreach ($classes as &$class) {
        $class['class_name'] = htmlspecialchars($class['class_name'], ENT_QUOTES, 'UTF-8');
        $class['campus_name'] = htmlspecialchars($class['campus_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
        $class['faculty_name'] = htmlspecialchars($class['faculty_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
        $class['department_name'] = htmlspecialchars($class['department_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
        $class['program_name'] = htmlspecialchars($class['program_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
    }

    // Fetch campuses for filter - but only those user has access to
    $campus_sql = "SELECT * FROM campus WHERE status = 'active'";
    $campus_params = [];
    
    if ($user_role !== 'super_admin' && $user_campus_id) {
        $campus_sql .= " AND campus_id = ?";
        $campus_params[] = $user_campus_id;
    }
    
    $campus_sql .= " ORDER BY campus_name ASC";
    
    $stmt = $pdo->prepare($campus_sql);
    $stmt->execute($campus_params);
    $campuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Data Fetch Error: " . $e->getMessage());
    $classes = [];
    $campuses = [];
}

$current_file = basename($_SERVER['PHP_SELF']);
$user_role_js = json_encode($user_role);
$user_campus_id_js = json_encode($user_campus_id);
$user_faculty_id_js = json_encode($user_faculty_id);
$user_department_id_js = json_encode($user_department_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Class Management | Hormuud University</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="../images.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<style>
/* ===========================================
   CSS STYLES (same as before)
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

.edit-btn {
  background: linear-gradient(135deg, var(--blue), #2196f3);
}

.del-btn {
  background: linear-gradient(135deg, var(--red), #e53935);
}

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

.delete-modal .modal-content h2 {
  color: var(--red);
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
}

.alert-popup.success {
  border-top-color: var(--green);
}

.alert-popup.error {
  border-top-color: var(--red);
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

/* Responsive */
@media (max-width: 1024px) {
  .main-content {
    margin-left: 0 !important;
    padding: 20px;
  }
  
  .modal-form {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 768px) {
  .page-header {
    flex-direction: column;
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
  
  table {
    min-width: 800px;
  }
}
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
  <div class="page-header">
    <div class="page-title">
      <h1><i class="fas fa-chalkboard-teacher"></i> Class Management</h1>
      <div class="badge"><?= count($classes) ?> Classes</div>
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
        <select class="filter-select" id="filter_faculty" onchange="onFacultyFilterChange(this.value)" disabled>
          <option value="">All Faculties</option>
        </select>
      </div>
      
      <div class="filter-group">
        <label><i class="fas fa-building"></i> Department</label>
        <select class="filter-select" id="filter_department" onchange="onDepartmentFilterChange(this.value)" disabled>
          <option value="">All Departments</option>
        </select>
      </div>
      
      <div class="filter-group">
        <label><i class="fas fa-graduation-cap"></i> Program</label>
        <select class="filter-select" id="filter_program" disabled>
          <option value="">All Programs</option>
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
      <h3>Class List</h3>
    </div>
    
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Class Name</th>
            <th>Program</th>
            <th>Department</th>
            <th>Faculty</th>
            <th>Campus</th>
            <th>Study Mode</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="classesTableBody">
          <?php if ($classes): foreach ($classes as $i=>$c): ?>
          <tr>
            <td><strong><?= $i+1 ?></strong></td>
            <td><strong><?= $c['class_name'] ?></strong></td>
            <td><?= $c['program_name'] ?> (<?= $c['program_code'] ?>)</td>
            <td><?= $c['department_name'] ?></td>
            <td><?= $c['faculty_name'] ?></td>
            <td><?= $c['campus_name'] ?></td>
            <td><?= $c['study_mode'] ?></td>
            <td>
              <span class="status-badge status-<?= $c['status'] ?>">
                <?= ucfirst($c['status']) ?>
              </span>
            </td>
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
                          '<?= $c['campus_id'] ?>',
                          '<?= $c['faculty_id'] ?>',
                          '<?= $c['department_id'] ?>',
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
              <td colspan="9">
                <div class="empty-state">
                  <i class="fas fa-chalkboard-teacher"></i>
                  <h3>No Classes Found</h3>
                  <p>Click "Add New Class" to create your first class</p>
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

      <div class="form-group full-width">
        <label class="required"><i class="fas fa-chalkboard"></i> Class Name</label>
        <input type="text" name="class_name" required placeholder="e.g., Form 1A, Year 1, Class A" maxlength="100">
      </div>

      <div class="form-group">
        <label class="required"><i class="fas fa-school"></i> Campus</label>
        <select id="add_campus" name="campus_id" required onchange="loadFacultiesByCampus(this.value, 'add')">
          <option value="">Select Campus</option>
          <?php foreach($campuses as $c): ?>
            <option value="<?= $c['campus_id'] ?>"><?= htmlspecialchars($c['campus_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="required"><i class="fas fa-university"></i> Faculty</label>
        <select id="add_faculty" name="faculty_id" required onchange="loadDepartmentsByFaculty(this.value, 'add')" disabled>
          <option value="">Select Faculty First</option>
        </select>
      </div>

      <div class="form-group">
        <label class="required"><i class="fas fa-building"></i> Department</label>
        <select id="add_department" name="department_id" required onchange="loadProgramsByDepartment(this.value, 'add')" disabled>
          <option value="">Select Department First</option>
        </select>
      </div>

      <div class="form-group">
        <label class="required"><i class="fas fa-graduation-cap"></i> Program</label>
        <select id="add_program" name="program_id" required disabled>
          <option value="">Select Program First</option>
        </select>
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
      <div class="form-group full-width">
        <label>Class Name</label>
        <div class="detail-value" id="view_name"></div>
      </div>
      
      <div class="form-group">
        <label>Program</label>
        <div class="detail-value" id="view_program"></div>
      </div>
      
      <div class="form-group">
        <label>Department</label>
        <div class="detail-value" id="view_department"></div>
      </div>
      
      <div class="form-group">
        <label>Faculty</label>
        <div class="detail-value" id="view_faculty"></div>
      </div>
      
      <div class="form-group">
        <label>Campus</label>
        <div class="detail-value" id="view_campus"></div>
      </div>
      
      <div class="form-group">
        <label>Study Mode</label>
        <div class="detail-value" id="view_mode"></div>
      </div>
      
      <div class="form-group">
        <label>Status</label>
        <div class="detail-value" id="view_status"></div>
      </div>
      
      <div class="form-group">
        <label>Created Date</label>
        <div class="detail-value" id="view_created"></div>
      </div>
      
      <div class="form-group">
        <label>Updated Date</label>
        <div class="detail-value" id="view_updated"></div>
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

      <div class="form-group full-width">
        <label class="required">Class Name</label>
        <input type="text" id="edit_name" name="class_name" required>
      </div>

      <div class="form-group">
        <label class="required">Campus</label>
        <select id="edit_campus" name="campus_id" required onchange="loadFacultiesByCampus(this.value, 'edit')">
          <option value="">Select Campus</option>
          <?php foreach($campuses as $c): ?>
            <option value="<?= $c['campus_id'] ?>"><?= htmlspecialchars($c['campus_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="required">Faculty</label>
        <select id="edit_faculty" name="faculty_id" required onchange="loadDepartmentsByFaculty(this.value, 'edit')" disabled>
          <option value="">Select Faculty First</option>
        </select>
      </div>

      <div class="form-group">
        <label class="required">Department</label>
        <select id="edit_department" name="department_id" required onchange="loadProgramsByDepartment(this.value, 'edit')" disabled>
          <option value="">Select Department First</option>
        </select>
      </div>

      <div class="form-group">
        <label class="required">Program</label>
        <select id="edit_program" name="program_id" required disabled>
          <option value="">Select Program First</option>
        </select>
      </div>

      <div class="form-group">
        <label class="required">Study Mode</label>
        <select id="edit_mode" name="study_mode" required>
          <option value="Full-Time">Full-Time</option>
          <option value="Part-Time">Part-Time</option>
        </select>
      </div>

      <div class="form-group">
        <label class="required">Status</label>
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
        <i class="fas fa-exclamation-triangle" style="font-size: 60px; color: var(--red);"></i>
        <p style="font-size: 16px; margin-top: 20px;">
          Are you sure you want to delete this class?<br>
          This action cannot be undone.
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
const userRole = <?= $user_role_js ?>;
const userCampusId = <?= $user_campus_id_js ?>;
const userFacultyId = <?= $user_faculty_id_js ?>;
const userDepartmentId = <?= $user_department_id_js ?>;

// Initialize filters based on user role
document.addEventListener('DOMContentLoaded', function() {
    // If user has restricted access, pre-select their filters
    if (userRole !== 'super_admin') {
        if (userCampusId) {
            const campusSelect = document.getElementById('filter_campus');
            if (campusSelect) {
                campusSelect.value = userCampusId;
                campusSelect.disabled = true;
                onCampusFilterChange(userCampusId);
            }
        }
    }
});

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
    document.getElementById('view_status').innerHTML = `<span class="status-badge status-${status}">${status}</span>`;
    document.getElementById('view_created').textContent = formatDate(created);
    document.getElementById('view_updated').textContent = formatDate(updated);
    openModal('viewModal');
}

function openEditModal(id, name, campusId, facultyId, deptId, programId, mode, status) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_campus').value = campusId;
    document.getElementById('edit_mode').value = mode;
    document.getElementById('edit_status').value = status;
    
    // Load dependencies
    loadFacultiesByCampus(campusId, 'edit', facultyId, deptId, programId);
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
function onCampusFilterChange(campusId) {
    const facultySelect = document.getElementById('filter_faculty');
    const deptSelect = document.getElementById('filter_department');
    const progSelect = document.getElementById('filter_program');
    
    if (!campusId) {
        facultySelect.innerHTML = '<option value="">All Faculties</option>';
        facultySelect.disabled = false;
        deptSelect.innerHTML = '<option value="">All Departments</option>';
        deptSelect.disabled = true;
        progSelect.innerHTML = '<option value="">All Programs</option>';
        progSelect.disabled = true;
        return;
    }
    
    facultySelect.innerHTML = '<option value="">Loading...</option>';
    facultySelect.disabled = true;
    deptSelect.innerHTML = '<option value="">All Departments</option>';
    deptSelect.disabled = true;
    progSelect.innerHTML = '<option value="">All Programs</option>';
    progSelect.disabled = true;
    
    fetch(`${currentFile}?ajax_action=get_faculties_by_campus&campus_id=${campusId}&csrf_token=${csrfToken}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                facultySelect.innerHTML = '<option value="">All Faculties</option>';
                data.faculties.forEach(f => {
                    const option = document.createElement('option');
                    option.value = f.faculty_id;
                    option.textContent = f.faculty_name;
                    facultySelect.appendChild(option);
                });
                facultySelect.disabled = false;
            } else {
                showAlert('Error loading faculties', 'error');
            }
        })
        .catch(() => {
            facultySelect.innerHTML = '<option value="">Error loading</option>';
            facultySelect.disabled = false;
        });
}

function onFacultyFilterChange(facultyId) {
    const deptSelect = document.getElementById('filter_department');
    const progSelect = document.getElementById('filter_program');
    const campusSelect = document.getElementById('filter_campus');
    const campusId = campusSelect ? campusSelect.value : null;
    
    if (!facultyId || !campusId) {
        deptSelect.innerHTML = '<option value="">All Departments</option>';
        deptSelect.disabled = true;
        progSelect.innerHTML = '<option value="">All Programs</option>';
        progSelect.disabled = true;
        return;
    }
    
    deptSelect.innerHTML = '<option value="">Loading...</option>';
    deptSelect.disabled = true;
    progSelect.innerHTML = '<option value="">All Programs</option>';
    progSelect.disabled = true;
    
    fetch(`${currentFile}?ajax_action=get_departments_by_faculty&faculty_id=${facultyId}&campus_id=${campusId}&csrf_token=${csrfToken}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                deptSelect.innerHTML = '<option value="">All Departments</option>';
                data.departments.forEach(d => {
                    const option = document.createElement('option');
                    option.value = d.department_id;
                    option.textContent = d.department_name;
                    deptSelect.appendChild(option);
                });
                deptSelect.disabled = false;
            } else {
                showAlert('Error loading departments', 'error');
            }
        })
        .catch(() => {
            deptSelect.innerHTML = '<option value="">Error loading</option>';
            deptSelect.disabled = false;
        });
}

function onDepartmentFilterChange(deptId) {
    const progSelect = document.getElementById('filter_program');
    const campusSelect = document.getElementById('filter_campus');
    const facultySelect = document.getElementById('filter_faculty');
    const campusId = campusSelect ? campusSelect.value : null;
    const facultyId = facultySelect ? facultySelect.value : null;
    
    if (!deptId || !campusId || !facultyId) {
        progSelect.innerHTML = '<option value="">All Programs</option>';
        progSelect.disabled = true;
        return;
    }
    
    progSelect.innerHTML = '<option value="">Loading...</option>';
    progSelect.disabled = true;
    
    fetch(`${currentFile}?ajax_action=get_programs_by_department&department_id=${deptId}&campus_id=${campusId}&faculty_id=${facultyId}&csrf_token=${csrfToken}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                progSelect.innerHTML = '<option value="">All Programs</option>';
                data.programs.forEach(p => {
                    const option = document.createElement('option');
                    option.value = p.program_id;
                    option.textContent = `${p.program_name} (${p.program_code})`;
                    progSelect.appendChild(option);
                });
                progSelect.disabled = false;
            } else {
                showAlert('Error loading programs', 'error');
            }
        })
        .catch(() => {
            progSelect.innerHTML = '<option value="">Error loading</option>';
            progSelect.disabled = false;
        });
}

function applyFilters() {
    const campusId = document.getElementById('filter_campus').value;
    const facultyId = document.getElementById('filter_faculty').value;
    const deptId = document.getElementById('filter_department').value;
    const programId = document.getElementById('filter_program').value;
    const studyMode = document.getElementById('filter_study_mode').value;
    const status = document.getElementById('filter_status').value;
    
    const tbody = document.getElementById('classesTableBody');
    tbody.innerHTML = `
        <tr><td colspan="9" style="text-align:center; padding:40px;">
            <i class="fas fa-spinner fa-spin"></i> Loading...
        </td></tr>
    `;
    
    let query = `ajax_action=get_filtered_classes&csrf_token=${csrfToken}`;
    if (campusId) query += `&campus_id=${campusId}`;
    if (facultyId) query += `&faculty_id=${facultyId}`;
    if (deptId) query += `&department_id=${deptId}`;
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
    document.getElementById('filter_campus').value = '';
    document.getElementById('filter_faculty').innerHTML = '<option value="">All Faculties</option>';
    document.getElementById('filter_faculty').disabled = true;
    document.getElementById('filter_department').innerHTML = '<option value="">All Departments</option>';
    document.getElementById('filter_department').disabled = true;
    document.getElementById('filter_program').innerHTML = '<option value="">All Programs</option>';
    document.getElementById('filter_program').disabled = true;
    document.getElementById('filter_study_mode').value = '';
    document.getElementById('filter_status').value = '';
    applyFilters();
}

function updateTable(classes) {
    const tbody = document.getElementById('classesTableBody');
    
    if (!classes || classes.length === 0) {
        tbody.innerHTML = `
            <tr><td colspan="9">
                <div class="empty-state">
                    <i class="fas fa-chalkboard-teacher"></i>
                    <h3>No Classes Found</h3>
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
                <td>${escapeHtml(c.department_name)}</td>
                <td>${escapeHtml(c.faculty_name)}</td>
                <td>${escapeHtml(c.campus_name)}</td>
                <td>${c.study_mode}</td>
                <td><span class="status-badge status-${c.status}">${c.status}</span></td>
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
                            ${c.campus_id},
                            ${c.faculty_id},
                            ${c.department_id},
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
    if (badge) badge.textContent = `${count} Class${count !== 1 ? 'es' : ''}`;
}

// Dependency loading functions for forms
function loadFacultiesByCampus(campusId, prefix, selectedFaculty = null, selectedDept = null, selectedProgram = null) {
    const facultySelect = document.getElementById(prefix + '_faculty');
    const deptSelect = document.getElementById(prefix + '_department');
    const progSelect = document.getElementById(prefix + '_program');
    
    if (!campusId) {
        facultySelect.innerHTML = '<option value="">Select Faculty First</option>';
        facultySelect.disabled = true;
        deptSelect.innerHTML = '<option value="">Select Department First</option>';
        deptSelect.disabled = true;
        progSelect.innerHTML = '<option value="">Select Program First</option>';
        progSelect.disabled = true;
        return;
    }
    
    facultySelect.innerHTML = '<option value="">Loading...</option>';
    facultySelect.disabled = true;
    deptSelect.innerHTML = '<option value="">Select Department First</option>';
    deptSelect.disabled = true;
    progSelect.innerHTML = '<option value="">Select Program First</option>';
    progSelect.disabled = true;
    
    fetch(`${currentFile}?ajax_action=get_faculties_by_campus&campus_id=${campusId}&csrf_token=${csrfToken}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                facultySelect.innerHTML = '<option value="">Select Faculty</option>';
                data.faculties.forEach(f => {
                    const option = document.createElement('option');
                    option.value = f.faculty_id;
                    option.textContent = f.faculty_name;
                    facultySelect.appendChild(option);
                });
                facultySelect.disabled = false;
                
                if (selectedFaculty) {
                    setTimeout(() => {
                        facultySelect.value = selectedFaculty;
                        loadDepartmentsByFaculty(selectedFaculty, prefix, selectedDept, selectedProgram);
                    }, 100);
                }
            } else {
                showAlert('Error loading faculties', 'error');
            }
        })
        .catch(() => {
            facultySelect.innerHTML = '<option value="">Error loading</option>';
            facultySelect.disabled = false;
        });
}

function loadDepartmentsByFaculty(facultyId, prefix, selectedDept = null, selectedProgram = null) {
    const deptSelect = document.getElementById(prefix + '_department');
    const progSelect = document.getElementById(prefix + '_program');
    const campusSelect = document.getElementById(prefix + '_campus');
    const campusId = campusSelect ? campusSelect.value : null;
    
    if (!facultyId || !campusId) {
        deptSelect.innerHTML = '<option value="">Select Department First</option>';
        deptSelect.disabled = true;
        progSelect.innerHTML = '<option value="">Select Program First</option>';
        progSelect.disabled = true;
        return;
    }
    
    deptSelect.innerHTML = '<option value="">Loading...</option>';
    deptSelect.disabled = true;
    progSelect.innerHTML = '<option value="">Select Program First</option>';
    progSelect.disabled = true;
    
    fetch(`${currentFile}?ajax_action=get_departments_by_faculty&faculty_id=${facultyId}&campus_id=${campusId}&csrf_token=${csrfToken}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                deptSelect.innerHTML = '<option value="">Select Department</option>';
                data.departments.forEach(d => {
                    const option = document.createElement('option');
                    option.value = d.department_id;
                    option.textContent = d.department_name;
                    deptSelect.appendChild(option);
                });
                deptSelect.disabled = false;
                
                if (selectedDept) {
                    setTimeout(() => {
                        deptSelect.value = selectedDept;
                        loadProgramsByDepartment(selectedDept, prefix, selectedProgram);
                    }, 100);
                }
            } else {
                showAlert('Error loading departments', 'error');
            }
        })
        .catch(() => {
            deptSelect.innerHTML = '<option value="">Error loading</option>';
            deptSelect.disabled = false;
        });
}

function loadProgramsByDepartment(deptId, prefix, selectedProgram = null) {
    const progSelect = document.getElementById(prefix + '_program');
    const campusSelect = document.getElementById(prefix + '_campus');
    const facultySelect = document.getElementById(prefix + '_faculty');
    const campusId = campusSelect ? campusSelect.value : null;
    const facultyId = facultySelect ? facultySelect.value : null;
    
    if (!deptId || !campusId || !facultyId) {
        progSelect.innerHTML = '<option value="">Select Program First</option>';
        progSelect.disabled = true;
        return;
    }
    
    progSelect.innerHTML = '<option value="">Loading...</option>';
    progSelect.disabled = true;
    
    fetch(`${currentFile}?ajax_action=get_programs_by_department&department_id=${deptId}&campus_id=${campusId}&faculty_id=${facultyId}&csrf_token=${csrfToken}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                progSelect.innerHTML = '<option value="">Select Program</option>';
                data.programs.forEach(p => {
                    const option = document.createElement('option');
                    option.value = p.program_id;
                    option.textContent = `${p.program_name} (${p.program_code})`;
                    progSelect.appendChild(option);
                });
                progSelect.disabled = false;
                
                if (selectedProgram) {
                    setTimeout(() => {
                        progSelect.value = selectedProgram;
                    }, 100);
                }
            } else {
                showAlert('Error loading programs', 'error');
            }
        })
        .catch(() => {
            progSelect.innerHTML = '<option value="">Error loading</option>';
            progSelect.disabled = false;
        });
}

function validateForm(formType) {
    const form = document.getElementById(formType + 'Form');
    const name = form.querySelector('input[name="class_name"]');
    const campus = form.querySelector('select[name="campus_id"]');
    const faculty = form.querySelector('select[name="faculty_id"]');
    const dept = form.querySelector('select[name="department_id"]');
    const prog = form.querySelector('select[name="program_id"]');
    
    if (!name.value.trim()) {
        showAlert('Class name is required', 'error');
        name.focus();
        return false;
    }
    if (!campus.value) {
        showAlert('Please select a campus', 'error');
        campus.focus();
        return false;
    }
    if (!faculty.value) {
        showAlert('Please select a faculty', 'error');
        faculty.focus();
        return false;
    }
    if (!dept.value) {
        showAlert('Please select a department', 'error');
        dept.focus();
        return false;
    }
    if (!prog.value) {
        showAlert('Please select a program', 'error');
        prog.focus();
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
    alertDiv.innerHTML = `<i>${type === 'success' ? '✅' : type === 'error' ? '❌' : '⚠️'}</i><h3>${escapeHtml(message)}</h3>`;
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
</script>

<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>