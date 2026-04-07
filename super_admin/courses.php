<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Check login
if (empty($_SESSION['user'])) {
    header("Location: ../login.php");
    exit;
}

// ✅ Access control - Super Admin only
if (strtolower($_SESSION['user']['role'] ?? '') !== 'super_admin') {
    header("Location: ../login.php");
    exit;
}

$user  = $_SESSION['user'];
$role  = strtolower(str_replace(' ', '_', $user['role'] ?? ''));
$name  = htmlspecialchars($user['username'] ?? 'User', ENT_QUOTES, 'UTF-8');
$email = htmlspecialchars($user['email'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
$phone = htmlspecialchars($user['phone_number'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
$photo = !empty($user['profile_photo_path'])
    ? "../" . htmlspecialchars($user['profile_photo_path'], ENT_QUOTES, 'UTF-8')
    : "../upload/profiles/default.png";

$message = "";
$type = "";

// ===========================================
// FILTER PARAMETERS
// ===========================================
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$campus_filter = $_GET['campus'] ?? '';
$faculty_filter = $_GET['faculty'] ?? '';
$department_filter = $_GET['department'] ?? '';
$program_filter = $_GET['program'] ?? '';
$class_filter = $_GET['class'] ?? '';
$semester_filter = $_GET['semester'] ?? '';
$academic_year_filter = $_GET['academic_year'] ?? '';
$academic_term_filter = $_GET['academic_term'] ?? '';

// ===========================================
// AJAX HANDLERS
// ===========================================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    // GET ACTIVE ACADEMIC YEARS
    if ($_GET['ajax'] == 'get_academic_years') {
        $current_date = date('Y-m-d');
        
        $stmt = $pdo->prepare("
            SELECT academic_year_id, year_name, start_date, end_date, status 
            FROM academic_year 
            WHERE status = 'active'
            AND end_date >= ?
            ORDER BY start_date DESC
        ");
        $stmt->execute([$current_date]);
        $years = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($years) {
            echo json_encode(['status' => 'success', 'years' => $years]);
        } else {
            // Fallback to any active academic years
            $fallback = $pdo->query("
                SELECT academic_year_id, year_name, start_date, end_date, status 
                FROM academic_year 
                WHERE status = 'active'
                ORDER BY start_date DESC
                LIMIT 5
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            if ($fallback) {
                echo json_encode(['status' => 'success', 'years' => $fallback]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'No active academic years found']);
            }
        }
        exit;
    }
    
    // GET ACTIVE TERMS BY ACADEMIC YEAR
    if ($_GET['ajax'] == 'get_terms_by_year') {
        $year_id = $_GET['year_id'] ?? 0;
        
        if (!$year_id) {
            echo json_encode(['status' => 'error', 'message' => 'Academic Year ID required']);
            exit;
        }
        
        $current_date = date('Y-m-d');
        
        $stmt = $pdo->prepare("
            SELECT academic_term_id, term_name, start_date, end_date, status 
            FROM academic_term 
            WHERE academic_year_id = ? 
            AND status = 'active'
            AND end_date >= ?
            ORDER BY start_date ASC
        ");
        $stmt->execute([$year_id, $current_date]);
        $terms = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($terms) {
            echo json_encode(['status' => 'success', 'terms' => $terms]);
        } else {
            // Fallback to any active terms for this year
            $fallback = $pdo->prepare("
                SELECT academic_term_id, term_name, start_date, end_date, status 
                FROM academic_term 
                WHERE academic_year_id = ? 
                AND status = 'active'
                ORDER BY start_date ASC
                LIMIT 2
            ");
            $fallback->execute([$year_id]);
            $fallback_terms = $fallback->fetchAll(PDO::FETCH_ASSOC);
            
            if ($fallback_terms) {
                echo json_encode(['status' => 'success', 'terms' => $fallback_terms]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'No active terms found for this academic year']);
            }
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
        
        $stmt = $pdo->prepare("
            SELECT department_id, department_name 
            FROM departments 
            WHERE faculty_id = ? AND campus_id = ?
            ORDER BY department_name
        ");
        $stmt->execute([$faculty_id, $campus_id]);
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($departments) {
            echo json_encode(['status' => 'success', 'departments' => $departments]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No departments found']);
        }
        exit;
    }
    
    // GET FACULTIES BY CAMPUS
    if ($_GET['ajax'] == 'get_faculties') {
        $campus_id = $_GET['campus_id'] ?? 0;
        
        if (!$campus_id) {
            echo json_encode(['status' => 'error', 'message' => 'Campus ID required']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT DISTINCT f.faculty_id, f.faculty_name 
            FROM faculties f
            JOIN faculty_campus fc ON f.faculty_id = fc.faculty_id
            WHERE fc.campus_id = ?
            ORDER BY f.faculty_name
        ");
        $stmt->execute([$campus_id]);
        $faculties = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($faculties) {
            echo json_encode(['status' => 'success', 'faculties' => $faculties]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No faculties found for this campus']);
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
            echo json_encode(['status' => 'error', 'message' => 'No programs found for this department']);
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
            echo json_encode(['status' => 'error', 'message' => 'No classes found for this program']);
        }
        exit;
    }
    
    // CHECK TERM STATUS
    if ($_GET['ajax'] == 'check_term_status') {
        $term_id = $_GET['term_id'] ?? 0;
        
        if (!$term_id) {
            echo json_encode(['status' => 'error', 'message' => 'Term ID required']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT at.term_name, at.start_date, at.end_date, at.status,
                   ay.year_name, ay.start_date as year_start, ay.end_date as year_end
            FROM academic_term at
            JOIN academic_year ay ON at.academic_year_id = ay.academic_year_id
            WHERE at.academic_term_id = ?
        ");
        $stmt->execute([$term_id]);
        $term = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($term) {
            $current_date = date('Y-m-d');
            $is_expired = ($term['end_date'] < $current_date);
            
            echo json_encode([
                'status' => 'success', 
                'term' => $term,
                'is_expired' => $is_expired,
                'current_date' => $current_date
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Term not found']);
        }
        exit;
    }
}

/* ===========================================
   CRUD OPERATIONS FOR SUBJECTS - FIXED VERSION
=========================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🟢 ADD SUBJECT
    if ($_POST['action'] === 'add') {
        try {
            // Validate required fields
            if (empty($_POST['subject_name']) || empty($_POST['subject_code'])) {
                throw new Exception("Subject name and code are required!");
            }
            
            // Check if subject code already exists
            $check = $pdo->prepare("
                SELECT subject_id 
                FROM subject
                WHERE subject_code = ? 
                AND class_id = ?
            ");
            $check->execute([
                $_POST['subject_code'], 
                $_POST['class_id']
            ]);
            
            if ($check->fetch()) {
                throw new Exception("Subject with this code already exists in the selected class!");
            }
            
            // Check if academic term is expired
            $final_status = $_POST['status'];
            if (!empty($_POST['academic_term_id']) && $_POST['status'] === 'active') {
                $term_check = $pdo->prepare("
                    SELECT end_date 
                    FROM academic_term 
                    WHERE academic_term_id = ?
                ");
                $term_check->execute([$_POST['academic_term_id']]);
                $term = $term_check->fetch(PDO::FETCH_ASSOC);
                
                if ($term) {
                    $current_date = date('Y-m-d');
                    $is_expired = ($term['end_date'] < $current_date);
                    
                    // If term is expired, force subject to be inactive
                    if ($is_expired) {
                        $final_status = 'inactive';
                    }
                }
            }
            
            // Fixed INSERT query - matches table structure
            $stmt = $pdo->prepare("
                INSERT INTO subject
                (subject_name, subject_code, class_id, campus_id, faculty_id, 
                 department_id, semester_id, academic_year_id, academic_term_id,
                 program_id, credit_hours, description, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_POST['subject_name'],
                $_POST['subject_code'],
                $_POST['class_id'],
                $_POST['campus_id'],
                $_POST['faculty_id'],
                $_POST['department_id'],
                $_POST['semester_id'],
                $_POST['academic_year_id'] ?? 1,
                $_POST['academic_term_id'] ?? 1,
                $_POST['program_id'],
                $_POST['credit_hours'] ?? 3,
                $_POST['description'] ?? '',
                $final_status
            ]);
            
            $message = "✅ Subject added successfully!";
            if ($final_status === 'inactive' && $_POST['status'] === 'active') {
                $message .= " Note: Subject was automatically set to inactive because the academic term has expired.";
            }
            $type = "success";
        } catch (Exception $e) {
            $message = "❌ " . $e->getMessage();
            $type = "error";
        }
    }
    
    // 🟡 UPDATE SUBJECT
    if ($_POST['action'] === 'update') {
        try {
            // Validate required fields
            if (empty($_POST['subject_name']) || empty($_POST['subject_code'])) {
                throw new Exception("Subject name and code are required!");
            }
            
            // Check if subject code already exists (excluding current subject)
            $check = $pdo->prepare("
                SELECT subject_id 
                FROM subject
                WHERE subject_code = ? 
                AND class_id = ?
                AND subject_id != ?
            ");
            $check->execute([
                $_POST['subject_code'], 
                $_POST['class_id'],
                $_POST['subject_id']
            ]);
            
            if ($check->fetch()) {
                throw new Exception("Subject with this code already exists in the selected class!");
            }
            
            // Check if academic term is expired
            $final_status = $_POST['status'];
            $term_warning = '';
            if (!empty($_POST['academic_term_id'])) {
                $term_check = $pdo->prepare("
                    SELECT end_date, status, term_name
                    FROM academic_term 
                    WHERE academic_term_id = ?
                ");
                $term_check->execute([$_POST['academic_term_id']]);
                $term = $term_check->fetch(PDO::FETCH_ASSOC);
                
                if ($term) {
                    $current_date = date('Y-m-d');
                    $is_expired = ($term['end_date'] < $current_date);
                    
                    // If term is expired, force subject to be inactive
                    if ($is_expired && $_POST['status'] === 'active') {
                        $final_status = 'inactive';
                        $term_warning = " Subject was automatically set to inactive because the academic term '{$term['term_name']}' has expired (ended on {$term['end_date']}).";
                    }
                }
            }
            
            // Fixed UPDATE query - matches table structure
            $stmt = $pdo->prepare("
                UPDATE subject
                SET subject_name = ?, 
                    subject_code = ?,
                    class_id = ?,
                    campus_id = ?, 
                    faculty_id = ?, 
                    department_id = ?, 
                    semester_id = ?,
                    academic_year_id = ?,
                    academic_term_id = ?,
                    program_id = ?,
                    credit_hours = ?,
                    description = ?,
                    status = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE subject_id = ?
            ");
            $stmt->execute([
                $_POST['subject_name'],
                $_POST['subject_code'],
                $_POST['class_id'],
                $_POST['campus_id'],
                $_POST['faculty_id'],
                $_POST['department_id'],
                $_POST['semester_id'],
                $_POST['academic_year_id'] ?? 1,
                $_POST['academic_term_id'] ?? 1,
                $_POST['program_id'],
                $_POST['credit_hours'] ?? 3,
                $_POST['description'] ?? '',
                $final_status, // This may have been changed to 'inactive' if term expired
                $_POST['subject_id']
            ]);
            
            $message = "✅ Subject updated successfully!" . $term_warning;
            $type = "success";
        } catch (Exception $e) {
            $message = "❌ " . $e->getMessage();
            $type = "error";
        }
    }
    
    // 🔴 DELETE SUBJECT
    if ($_POST['action'] === 'delete') {
        try {
            // Check if subject has any allocations or enrollments
            $check = $pdo->prepare("
                SELECT COUNT(*) FROM subject_allocations WHERE subject_id = ?
                UNION ALL
                SELECT COUNT(*) FROM student_grades WHERE subject_id = ?
            ");
            $check->execute([$_POST['subject_id'], $_POST['subject_id']]);
            $counts = $check->fetchAll(PDO::FETCH_COLUMN);
            
            if (array_sum($counts) > 0) {
                throw new Exception("Cannot delete subject. It has allocations or student grades!");
            }
            
            $pdo->prepare("DELETE FROM subject WHERE subject_id = ?")->execute([$_POST['subject_id']]);
            
            $message = "✅ Subject deleted successfully!";
            $type = "success";
        } catch (Exception $e) {
            $message = "❌ " . $e->getMessage();
            $type = "error";
        }
    }
}

/* ===========================================
   FETCH DATA
=========================================== */
$campuses = $pdo->query("SELECT * FROM campus ORDER BY campus_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$faculties = $pdo->query("SELECT * FROM faculties ORDER BY faculty_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$departments = $pdo->query("SELECT * FROM departments ORDER BY department_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Get active academic years
$current_date = date('Y-m-d');
$academic_years = $pdo->prepare("
    SELECT academic_year_id, year_name, start_date, end_date, status 
    FROM academic_year 
    WHERE status = 'active'
    AND end_date >= ?
    ORDER BY start_date DESC
");
$academic_years->execute([$current_date]);
$academic_years = $academic_years->fetchAll(PDO::FETCH_ASSOC);

// Get academic terms with status - ONLY ACTIVE TERMS FROM ACTIVE YEARS
$academic_terms = $pdo->prepare("
    SELECT at.*, 
           ay.year_name,
           ay.start_date as year_start,
           ay.end_date as year_end,
           CASE 
               WHEN at.end_date < ? THEN 'expired'
               ELSE at.status 
           END as display_status
    FROM academic_term at
    JOIN academic_year ay ON at.academic_year_id = ay.academic_year_id
    WHERE at.status = 'active'
    AND ay.status = 'active'
    ORDER BY at.start_date DESC
");
$academic_terms->execute([$current_date]);
$academic_terms = $academic_terms->fetchAll(PDO::FETCH_ASSOC);

// If semester table doesn't exist, create predefined list
if (!$pdo->query("SHOW TABLES LIKE 'semester'")->fetch()) {
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
} else {
    $semesters = $pdo->query("SELECT * FROM semester ORDER BY semester_name")->fetchAll(PDO::FETCH_ASSOC);
}

// Get all classes for filter
$all_classes = $pdo->query("
    SELECT c.class_id, c.class_name, p.program_name
    FROM classes c
    JOIN programs p ON c.program_id = p.program_id
    WHERE c.status = 'Active'
    ORDER BY c.class_name
")->fetchAll(PDO::FETCH_ASSOC);

// Get all programs for filter
$all_programs = $pdo->query("SELECT program_id, program_name, program_code FROM programs WHERE status = 'active' ORDER BY program_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Build query with filters for subjects - UPDATED with academic_year and academic_term
$query = "
    SELECT 
        s.subject_id,
        s.subject_name,
        s.subject_code,
        s.credit_hours,
        s.description,
        s.status,
        s.created_at,
        s.updated_at,
        s.academic_year_id,
        s.academic_term_id,
        cam.campus_id,
        cam.campus_name,
        f.faculty_id,
        f.faculty_name,
        d.department_id,
        d.department_name,
        p.program_id,
        p.program_name,
        p.program_code,
        c.class_id,
        c.class_name,
        sem.semester_id,
        sem.semester_name,
        ay.year_name as academic_year_name,
        at.term_name as academic_term_name,
        at.start_date as term_start,
        at.end_date as term_end,
        at.status as term_status
    FROM subject s
    LEFT JOIN campus cam ON s.campus_id = cam.campus_id
    LEFT JOIN faculties f ON s.faculty_id = f.faculty_id
    LEFT JOIN departments d ON s.department_id = d.department_id
    LEFT JOIN programs p ON s.program_id = p.program_id
    LEFT JOIN classes c ON s.class_id = c.class_id
    LEFT JOIN semester sem ON s.semester_id = sem.semester_id
    LEFT JOIN academic_year ay ON s.academic_year_id = ay.academic_year_id
    LEFT JOIN academic_term at ON s.academic_term_id = at.academic_term_id
    WHERE 1=1
";

$params = [];

// Search filter
if (!empty($search)) {
    $query .= " AND (s.subject_name LIKE ? OR s.subject_code LIKE ? OR p.program_name LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
}

// Status filter
if (!empty($status_filter)) {
    $query .= " AND s.status = ?";
    $params[] = $status_filter;
}

// Campus filter
if (!empty($campus_filter)) {
    $query .= " AND s.campus_id = ?";
    $params[] = $campus_filter;
}

// Faculty filter
if (!empty($faculty_filter)) {
    $query .= " AND s.faculty_id = ?";
    $params[] = $faculty_filter;
}

// Department filter
if (!empty($department_filter)) {
    $query .= " AND s.department_id = ?";
    $params[] = $department_filter;
}

// Program filter
if (!empty($program_filter)) {
    $query .= " AND s.program_id = ?";
    $params[] = $program_filter;
}

// Class filter
if (!empty($class_filter)) {
    $query .= " AND s.class_id = ?";
    $params[] = $class_filter;
}

// Semester filter
if (!empty($semester_filter)) {
    $query .= " AND s.semester_id = ?";
    $params[] = $semester_filter;
}

// Academic Year filter
if (!empty($academic_year_filter)) {
    $query .= " AND s.academic_year_id = ?";
    $params[] = $academic_year_filter;
}

// Academic Term filter
if (!empty($academic_term_filter)) {
    $query .= " AND s.academic_term_id = ?";
    $params[] = $academic_term_filter;
}

$query .= " ORDER BY s.created_at DESC";

// Execute query
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Subject Management | University System</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="../images.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ===========================================
   CSS VARIABLES & RESET
=========================================== */
:root {
  --primary-color: #00843D;
  --secondary-color: #0072CE;
  --light-color: #00A651;
  --dark-color: #333333;
  --light-gray: #F5F9F7;
  --danger-color: #C62828;
  --warning-color: #FFB400;
  --white: #FFFFFF;
  --cyan-color: #00BCD4;
  --purple-color: #6A5ACD;
  --border-color: #E0E0E0;
  --shadow-color: rgba(0, 0, 0, 0.08);
  --transition: all 0.3s ease;
}

* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
  font-family: 'Segoe UI', 'Poppins', sans-serif;
}

body {
  background: var(--light-gray);
  color: var(--dark-color);
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

/* ==============================
   PAGE HEADER
============================== */
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 25px;
  padding: 20px;
  background: var(--white);
  border-radius: 12px;
  box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
  border-left: 4px solid var(--primary-color);
}

.page-header h1 {
  color: var(--secondary-color);
  font-size: 26px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 12px;
}

.page-header h1 i {
  color: var(--primary-color);
  background: rgba(0, 132, 61, 0.1);
  padding: 10px;
  border-radius: 10px;
}

.add-btn {
  background: linear-gradient(135deg, var(--primary-color), var(--light-color));
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

/* ==============================
   FILTERS SECTION
============================== */
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
  color: var(--secondary-color);
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
  border-color: var(--secondary-color);
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
  background: var(--primary-color);
  color: #fff;
}

.apply-btn:hover {
  background: var(--light-color);
}

.clear-btn {
  background: #6c757d;
  color: #fff;
}

.clear-btn:hover {
  background: #5a6268;
}

/* ==============================
   RESPONSIVE TABLE
============================== */
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
  color: var(--dark-color);
  font-size: 16px;
  margin: 0;
}

.results-count {
  color: #666;
  font-size: 14px;
  text-align: right;
}

/* Responsive table container */
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
  background: var(--secondary-color);
  border-radius: 4px;
}

/* Table with minimum width for small screens */
.data-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 14px;
  min-width: 1400px;
}

.data-table th,
.data-table td {
  padding: 14px 15px;
  border-bottom: 1px solid #eee;
  vertical-align: middle;
  white-space: nowrap;
}

/* Allow text wrapping in specific cells for better mobile display */
.data-table td:nth-child(2), /* Subject Code */
.data-table td:nth-child(3), /* Subject Name */
.data-table td:nth-child(8) { /* Program */
  white-space: normal;
  min-width: 150px;
  max-width: 200px;
}

.data-table th {
  position: sticky;
  top: 0;
  background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
  color: var(--white);
  font-weight: 600;
  z-index: 10;
}

.data-table tbody tr {
  transition: background 0.2s ease;
}

.data-table tbody tr:hover {
  background: #f9f9f9;
}

.data-table tbody tr:nth-child(even) {
  background: rgba(0, 114, 206, 0.02);
}

.data-table tbody tr:last-child td {
  border-bottom: none;
}

/* Status badges */
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
  color: var(--primary-color);
  border: 1px solid rgba(0, 132, 61, 0.2);
}

.status-inactive {
  background: #ffebee;
  color: var(--danger-color);
  border: 1px solid rgba(198, 40, 40, 0.2);
}

.status-expired {
  background: #f5f5f5;
  color: #9E9E9E;
  border: 1px solid #E0E0E0;
}

/* Credit hours badge */
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

/* Action buttons */
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
  background: var(--secondary-color);
  color: var(--white);
}

.edit-btn:hover {
  background: #005fa3;
  transform: translateY(-2px);
  box-shadow: 0 5px 12px rgba(0, 114, 206, 0.3);
}

.del-btn {
  background: var(--danger-color);
  color: var(--white);
}

.del-btn:hover {
  background: #b71c1c;
  transform: translateY(-2px);
  box-shadow: 0 5px 12px rgba(198, 40, 40, 0.3);
}

/* Card View for Mobile */
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
  border-left: 4px solid var(--primary-color);
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
  color: var(--dark-color);
  margin-bottom: 5px;
}

.subject-code {
  font-size: 14px;
  color: var(--secondary-color);
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
  color: var(--dark-color);
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
  background: var(--secondary-color);
  color: white;
}

.mobile-edit-btn:hover {
  background: #005fa3;
}

.mobile-delete-btn {
  background: var(--danger-color);
  color: white;
}

.mobile-delete-btn:hover {
  background: #b71c1c;
}

@media (max-width: 768px) {
  .table-header {
    flex-direction: column;
    align-items: flex-start;
  }
  
  .results-count {
    align-self: flex-start;
    margin-top: 5px;
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
  
  .data-table {
    min-width: 1200px;
    font-size: 13px;
  }
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

/* ==============================
   MODAL STYLES
============================== */
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
  max-width: 900px;
  max-height: 90vh;
  overflow-y: auto;
  padding: 35px;
  position: relative;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
  animation: modalSlideIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}

@keyframes modalSlideIn {
  from {
    opacity: 0;
    transform: translateY(-30px) scale(0.95);
  }
  to {
    opacity: 1;
    transform: translateY(0) scale(1);
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
  color: var(--danger-color);
  transform: rotate(90deg);
}

.modal-content h2 {
  color: var(--secondary-color);
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
  color: var(--primary-color);
  background: rgba(0, 132, 61, 0.1);
  padding: 10px;
  border-radius: 10px;
}

/* ==============================
   FORM STYLES
============================== */
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
  color: var(--dark-color);
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
  background: var(--primary-color);
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
  border-color: var(--secondary-color);
  background: var(--white);
  box-shadow: 0 0 0 4px rgba(0, 114, 206, 0.1);
  transform: translateY(-2px);
}

textarea.form-control {
  min-height: 100px;
  resize: vertical;
  grid-column: 1 / -1;
}

/* Required field indicator */
.required::after {
  content: " *";
  color: var(--danger-color);
}

/* Submit button */
.submit-btn {
  background: linear-gradient(135deg, var(--primary-color), var(--light-color));
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
  background: linear-gradient(135deg, var(--danger-color), #e53935);
}

.delete-btn:hover {
  box-shadow: 0 10px 25px rgba(198, 40, 40, 0.3);
}

/* Delete Modal Specific */
#deleteModal .modal-content {
  max-width: 500px;
}

#deleteModal h2 {
  color: var(--danger-color);
  border-bottom: none;
  margin-bottom: 10px;
}

#deleteModal p {
  color: #666;
  font-size: 15px;
  line-height: 1.5;
  margin-bottom: 10px;
}

/* Term Warning */
.term-warning {
  background: #fff3cd;
  color: #856404;
  padding: 10px;
  border-radius: 6px;
  border: 1px solid #ffeaa7;
  margin: 10px 0;
  font-size: 13px;
  display: flex;
  align-items: center;
  gap: 8px;
  grid-column: 1 / -1;
}
.term-warning i {
  color: #ff9800;
}

/* Disabled option styling */
option:disabled {
  color: #999;
  background-color: #f5f5f5;
}

/* ==============================
   ALERT POPUP
============================== */
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
  animation: alertSlideIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
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
  border-top-color: var(--primary-color);
}

.alert-popup.error {
  border-top-color: var(--danger-color);
}

.alert-icon {
  font-size: 40px;
  margin-bottom: 20px;
  display: block;
  width: 70px;
  height: 70px;
  line-height: 70px;
  border-radius: 50%;
  margin: 0 auto 20px;
}

.alert-popup.success .alert-icon {
  background: rgba(0, 132, 61, 0.1);
  color: var(--primary-color);
}

.alert-popup.error .alert-icon {
  background: rgba(198, 40, 40, 0.1);
  color: var(--danger-color);
}

.alert-message {
  color: var(--dark-color);
  font-size: 16px;
  font-weight: 500;
  line-height: 1.5;
}

/* ==============================
   IMPROVED RESPONSIVE DESIGN
============================== */
@media (max-width: 1024px) {
  .main-content {
    margin-left: 240px;
  }
  
  body.sidebar-collapsed .main-content {
    margin-left: 60px;
  }
  
  .filter-form {
    grid-template-columns: repeat(3, 1fr);
  }
  
  .data-table {
    min-width: 1300px;
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
  
  .page-header h1 {
    font-size: 22px;
  }
  
  .add-btn {
    width: 100%;
    justify-content: center;
  }
  
  .filter-form {
    grid-template-columns: 1fr;
  }
  
  .filters-container {
    padding: 15px;
  }
  
  .filter-header {
    margin-bottom: 10px;
  }
  
  .filter-actions {
    flex-direction: column;
    gap: 8px;
  }
  
  .filter-btn {
    width: 100%;
    justify-content: center;
  }
  
  .table-header {
    padding: 15px;
  }
  
  .action-btns {
    gap: 5px;
  }
  
  .action-btn {
    min-width: 34px;
    height: 34px;
    font-size: 13px;
  }
  
  .modal-content {
    padding: 20px 15px;
    max-width: 95%;
    margin: 10px;
  }
  
  .form-grid {
    grid-template-columns: 1fr;
    gap: 15px;
  }
  
  .form-group {
    margin-bottom: 15px;
  }
  
  .submit-btn {
    padding: 12px 20px;
    font-size: 15px;
    width: 100%;
  }
}

@media (max-width: 576px) {
  .status-badge,
  .credit-badge {
    padding: 4px 10px;
    font-size: 10px;
    min-width: 60px;
  }
  
  .alert-popup {
    min-width: 280px;
    padding: 20px;
    max-width: 90%;
  }
  
  .action-btn {
    min-width: 32px;
    height: 32px;
    font-size: 12px;
  }
}

@media (max-width: 480px) {
  .page-header h1 {
    font-size: 20px;
  }
  
  .page-header h1 i {
    padding: 8px;
    font-size: 18px;
  }
  
  .add-btn {
    padding: 10px 15px;
    font-size: 14px;
  }
  
  .modal-content h2 {
    font-size: 20px;
    margin-bottom: 20px;
  }
  
  .form-control {
    padding: 10px 15px;
    font-size: 13px;
  }
  
  .close-modal {
    width: 36px;
    height: 36px;
    font-size: 24px;
  }
}

/* Landscape orientation adjustments */
@media (max-width: 768px) and (orientation: landscape) {
  .modal-content {
    max-height: 80vh;
  }
  
  .table-responsive {
    max-height: 300px;
  }
}

/* Print optimizations */
@media print {
  .main-content {
    margin: 0 !important;
    padding: 0 !important;
  }
  
  .page-header button,
  .action-btns,
  .modal,
  .alert-popup,
  .filters-container,
  .mobile-card-view {
    display: none !important;
  }
  
  .table-wrapper {
    box-shadow: none;
    border: 1px solid #ddd;
    break-inside: avoid;
  }
  
  .table-responsive {
    display: block;
    overflow: visible;
  }
  
  .data-table {
    width: 100%;
    min-width: auto;
  }
  
  .data-table th {
    background: #f0f0f0 !important;
    color: #000 !important;
    -webkit-print-color-adjust: exact;
    color-adjust: exact;
  }
}

/* ==============================
   SCROLLBAR STYLING
============================== */
.table-responsive::-webkit-scrollbar,
.modal-content::-webkit-scrollbar {
  width: 8px;
  height: 8px;
}

.table-responsive::-webkit-scrollbar-track,
.modal-content::-webkit-scrollbar-track {
  background: #f1f1f1;
  border-radius: 4px;
}

.table-responsive::-webkit-scrollbar-thumb,
.modal-content::-webkit-scrollbar-thumb {
  background: var(--secondary-color);
  border-radius: 4px;
}

.table-responsive::-webkit-scrollbar-thumb:hover,
.modal-content::-webkit-scrollbar-thumb:hover {
  background: var(--primary-color);
}

/* ==============================
   ANIMATIONS
============================== */
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}

.data-table tbody tr,
.subject-card {
  animation: fadeIn 0.4s ease forwards;
}

.data-table tbody tr:nth-child(1),
.subject-card:nth-child(1) { animation-delay: 0.05s; }
.data-table tbody tr:nth-child(2),
.subject-card:nth-child(2) { animation-delay: 0.1s; }
.data-table tbody tr:nth-child(3),
.subject-card:nth-child(3) { animation-delay: 0.15s; }
.data-table tbody tr:nth-child(4),
.subject-card:nth-child(4) { animation-delay: 0.2s; }
.data-table tbody tr:nth-child(5),
.subject-card:nth-child(5) { animation-delay: 0.25s; }
.data-table tbody tr:nth-child(6),
.subject-card:nth-child(6) { animation-delay: 0.3s; }
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
  <div class="page-header">
    <h1><i class="fas fa-book-open"></i> Subject Management</h1>
    <button class="add-btn" onclick="openModal('addModal')">
      <i class="fa-solid fa-plus"></i> Add New Subject
    </button>
  </div>
  
  <!-- ✅ FILTERS SECTION -->
  <div class="filters-container">
    <div class="filter-header">
        <h3><i class="fas fa-filter"></i> Search & Filter Subjects</h3>
    </div>
    
    <form method="GET" class="filter-form" id="filterForm">
        <!-- Search Input -->
        <div class="filter-group">
            <label for="search">Search Subjects</label>
            <div style="position:relative;">
                <input type="text" 
                       id="search" 
                       name="search" 
                       class="filter-input" 
                       placeholder="Search by subject name, code..."
                       value="<?= htmlspecialchars($search) ?>">
            </div>
        </div>
        
        <!-- Status Filter -->
        <div class="filter-group">
            <label for="status">Status</label>
            <div style="position:relative;">
                <select id="status" name="status" class="filter-input">
                    <option value="">All Status</option>
                    <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
        </div>
        
        <!-- Campus Filter -->
        <div class="filter-group">
            <label for="campus">Campus</label>
            <div style="position:relative;">
                <select id="campus" name="campus" class="filter-input">
                    <option value="">All Campuses</option>
                    <?php foreach($campuses as $campus): ?>
                    <option value="<?= $campus['campus_id'] ?>" 
                        <?= $campus_filter == $campus['campus_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($campus['campus_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <!-- Faculty Filter -->
        <div class="filter-group">
            <label for="faculty">Faculty</label>
            <div style="position:relative;">
                <select id="faculty" name="faculty" class="filter-input">
                    <option value="">All Faculties</option>
                    <?php foreach($faculties as $faculty): ?>
                    <option value="<?= $faculty['faculty_id'] ?>" 
                        <?= $faculty_filter == $faculty['faculty_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($faculty['faculty_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <!-- Department Filter -->
        <div class="filter-group">
            <label for="department">Department</label>
            <div style="position:relative;">
                <select id="department" name="department" class="filter-input">
                    <option value="">All Departments</option>
                    <?php foreach($departments as $department): ?>
                    <option value="<?= $department['department_id'] ?>" 
                        <?= $department_filter == $department['department_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($department['department_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <!-- Program Filter -->
        <div class="filter-group">
            <label for="program">Program</label>
            <div style="position:relative;">
                <select id="program" name="program" class="filter-input">
                    <option value="">All Programs</option>
                    <?php foreach($all_programs as $program): ?>
                    <option value="<?= $program['program_id'] ?>" 
                        <?= $program_filter == $program['program_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($program['program_name']) ?> 
                        (<?= htmlspecialchars($program['program_code']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <!-- Class Filter -->
        <div class="filter-group">
            <label for="class">Class</label>
            <div style="position:relative;">
                <select id="class" name="class" class="filter-input">
                    <option value="">All Classes</option>
                    <?php foreach($all_classes as $class): ?>
                    <option value="<?= $class['class_id'] ?>" 
                        <?= $class_filter == $class['class_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($class['class_name']) ?> 
                        (<?= htmlspecialchars($class['program_name']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <!-- Semester Filter -->
        <div class="filter-group">
            <label for="semester">Semester</label>
            <div style="position:relative;">
                <select id="semester" name="semester" class="filter-input">
                    <option value="">All Semesters</option>
                    <?php foreach($semesters as $sem): ?>
                    <option value="<?= $sem['semester_id'] ?>" 
                        <?= $semester_filter == $sem['semester_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($sem['semester_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <!-- Academic Year Filter -->
        <div class="filter-group">
            <label for="academic_year">Academic Year</label>
            <div style="position:relative;">
                <select id="academic_year" name="academic_year" class="filter-input" onchange="onFilterYearChange()">
                    <option value="">All Academic Years</option>
                    <?php foreach($academic_years as $year): ?>
                    <option value="<?= $year['academic_year_id'] ?>" 
                        <?= $academic_year_filter == $year['academic_year_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($year['year_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <!-- Academic Term Filter -->
        <div class="filter-group">
            <label for="academic_term">Academic Term</label>
            <div style="position:relative;">
                <select id="academic_term" name="academic_term" class="filter-input" <?= $academic_year_filter ? '' : 'disabled' ?>>
                    <option value="">All Academic Terms</option>
                    <?php foreach($academic_terms as $term): ?>
                    <option value="<?= $term['academic_term_id'] ?>" 
                        <?= $academic_term_filter == $term['academic_term_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($term['term_name']) ?> 
                        (<?= $term['display_status'] ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <!-- Filter Actions -->
        <div class="filter-actions">
            <button type="submit" class="filter-btn apply-btn">
                <i class="fas fa-filter"></i> Apply Filters
            </button>
            <button type="button" class="filter-btn clear-btn" onclick="clearFilters()">
                <i class="fas fa-times"></i> Clear All
            </button>
        </div>
    </form>
  </div>

  <!-- ✅ MAIN TABLE -->
  <div class="table-wrapper">
    <div class="table-header">
      <h3>Subject List</h3>
      <div class="results-count">
        Showing <?= count($subjects) ?> subjects
        <?php if(!empty($search) || !empty($status_filter) || !empty($campus_filter) || 
                 !empty($faculty_filter) || !empty($department_filter) || 
                 !empty($program_filter) || !empty($class_filter) || !empty($semester_filter) ||
                 !empty($academic_year_filter) || !empty($academic_term_filter)): ?>
            (filtered)
        <?php endif; ?>
      </div>
    </div>
    
    <!-- Desktop/Tablet View -->
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Subject Code</th>
            <th>Subject Name</th>
            <th>Credit Hours</th>
            <th>Class</th>
            <th>Semester</th>
            <th>Academic Year</th>
            <th>Academic Term</th>
            <th>Program</th>
            <th>Department</th>
            <th>Faculty</th>
            <th>Campus</th>
            <th>Status</th>
            <th>Created</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if($subjects): ?>
            <?php foreach($subjects as $i=>$s): 
                $current_date = date('Y-m-d');
                $term_expired = ($s['term_end'] && $s['term_end'] < $current_date);
                $display_status = $s['status'];
                if ($term_expired && $s['status'] === 'active') {
                    $display_status = 'expired';
                }
            ?>
            <tr>
              <td><?= $i+1 ?></td>
              <td><strong><?= htmlspecialchars($s['subject_code']) ?></strong></td>
              <td><?= htmlspecialchars($s['subject_name']) ?></td>
              <td>
                <span class="credit-badge">
                  <?= htmlspecialchars($s['credit_hours']) ?> CH
                </span>
              </td>
              <td><?= htmlspecialchars($s['class_name'] ?? 'N/A') ?></td>
              <td><?= htmlspecialchars($s['semester_name'] ?? 'N/A') ?></td>
              <td>
                <?= htmlspecialchars($s['academic_year_name'] ?? 'N/A') ?>
                <?php if($s['academic_year_name'] && $term_expired): ?>
                  <br><small class="status-expired">(Expired)</small>
                <?php endif; ?>
              </td>
              <td>
                <?= htmlspecialchars($s['academic_term_name'] ?? 'N/A') ?>
                <?php if($s['academic_term_name'] && $term_expired): ?>
                  <br><small class="status-expired">(Ended: <?= date('M d, Y', strtotime($s['term_end'])) ?>)</small>
                <?php endif; ?>
              </td>
              <td>
                <?= htmlspecialchars($s['program_name'] ?? 'N/A') ?>
                <?php if(!empty($s['program_code'])): ?>
                  <br><small style="color:#666;">(<?= htmlspecialchars($s['program_code']) ?>)</small>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($s['department_name'] ?? 'N/A') ?></td>
              <td><?= htmlspecialchars($s['faculty_name'] ?? 'N/A') ?></td>
              <td><?= htmlspecialchars($s['campus_name'] ?? 'N/A') ?></td>
              <td>
                <span class="status-badge status-<?= $display_status ?>">
                  <?= ucfirst($display_status) ?>
                  <?php if($term_expired && $s['status'] === 'active'): ?>
                    <i class="fas fa-exclamation-triangle" style="margin-left: 3px;"></i>
                  <?php endif; ?>
                </span>
              </td>
              <td><?= date('Y-m-d', strtotime($s['created_at'])) ?></td>
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
              <td colspan="15">
                <div class="empty-state">
                  <i class="fa-solid fa-book-open"></i>
                  <h3>No subjects found</h3>
                  <p>
                    <?php if(!empty($search) || !empty($status_filter) || !empty($campus_filter) || 
                             !empty($faculty_filter) || !empty($department_filter) || 
                             !empty($program_filter) || !empty($class_filter) || !empty($semester_filter) ||
                             !empty($academic_year_filter) || !empty($academic_term_filter)): ?>
                        Try adjusting your filters or <a href="javascript:void(0)" onclick="clearFilters()">clear all filters</a>
                    <?php else: ?>
                        Add your first subject using the "Add Subject" button above
                    <?php endif; ?>
                  </p>
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
        <?php foreach($subjects as $i=>$s): 
            $current_date = date('Y-m-d');
            $term_expired = ($s['term_end'] && $s['term_end'] < $current_date);
            $display_status = $s['status'];
            if ($term_expired && $s['status'] === 'active') {
                $display_status = 'expired';
            }
        ?>
        <div class="subject-card">
          <div class="subject-header">
            <div>
              <div class="subject-title"><?= htmlspecialchars($s['subject_name']) ?></div>
              <div class="subject-code"><?= htmlspecialchars($s['subject_code']) ?></div>
              <div class="subject-meta">
                <span class="credit-badge">
                  <?= htmlspecialchars($s['credit_hours']) ?> CH
                </span>
                <span class="status-badge status-<?= $display_status ?>">
                  <?= ucfirst($display_status) ?>
                  <?php if($term_expired && $s['status'] === 'active'): ?>
                    <i class="fas fa-exclamation-triangle" style="margin-left: 3px;"></i>
                  <?php endif; ?>
                </span>
              </div>
            </div>
          </div>
          
          <div class="subject-details">
            <div class="detail-item">
              <div class="detail-label">Class</div>
              <div class="detail-value"><?= htmlspecialchars($s['class_name'] ?? 'N/A') ?></div>
            </div>
            
            <div class="detail-item">
              <div class="detail-label">Semester</div>
              <div class="detail-value"><?= htmlspecialchars($s['semester_name'] ?? 'N/A') ?></div>
            </div>
            
            <div class="detail-item">
              <div class="detail-label">Academic Year</div>
              <div class="detail-value">
                <?= htmlspecialchars($s['academic_year_name'] ?? 'N/A') ?>
                <?php if($term_expired): ?>
                  <br><small class="status-expired">(Expired)</small>
                <?php endif; ?>
              </div>
            </div>
            
            <div class="detail-item">
              <div class="detail-label">Academic Term</div>
              <div class="detail-value">
                <?= htmlspecialchars($s['academic_term_name'] ?? 'N/A') ?>
                <?php if($term_expired): ?>
                  <br><small class="status-expired">(Ended: <?= date('M d, Y', strtotime($s['term_end'])) ?>)</small>
                <?php endif; ?>
              </div>
            </div>
            
            <div class="detail-item">
              <div class="detail-label">Program</div>
              <div class="detail-value">
                <?= htmlspecialchars($s['program_name'] ?? 'N/A') ?>
                <?php if(!empty($s['program_code'])): ?>
                  <br><small>(<?= htmlspecialchars($s['program_code']) ?>)</small>
                <?php endif; ?>
              </div>
            </div>
            
            <div class="detail-item">
              <div class="detail-label">Department</div>
              <div class="detail-value"><?= htmlspecialchars($s['department_name'] ?? 'N/A') ?></div>
            </div>
            
            <div class="detail-item">
              <div class="detail-label">Faculty</div>
              <div class="detail-value"><?= htmlspecialchars($s['faculty_name'] ?? 'N/A') ?></div>
            </div>
            
            <div class="detail-item">
              <div class="detail-label">Campus</div>
              <div class="detail-value"><?= htmlspecialchars($s['campus_name'] ?? 'N/A') ?></div>
            </div>
            
            <div class="detail-item">
              <div class="detail-label">Created</div>
              <div class="detail-value"><?= date('Y-m-d', strtotime($s['created_at'])) ?></div>
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
          <i class="fa-solid fa-book-open"></i>
          <h3>No subjects found</h3>
          <p>
            <?php if(!empty($search) || !empty($status_filter) || !empty($campus_filter) || 
                     !empty($faculty_filter) || !empty($department_filter) || 
                     !empty($program_filter) || !empty($class_filter) || !empty($semester_filter) ||
                     !empty($academic_year_filter) || !empty($academic_term_filter)): ?>
                Try adjusting your filters or <a href="javascript:void(0)" onclick="clearFilters()">clear all filters</a>
            <?php else: ?>
                Add your first subject using the "Add Subject" button above
            <?php endif; ?>
          </p>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ✅ ADD MODAL -->
<div class="modal" id="addModal">
  <div class="modal-content">
    <button class="close-modal" onclick="closeModal('addModal')">&times;</button>
    <h2><i class="fas fa-plus-circle"></i> Add New Subject</h2>
    <form method="POST" id="addForm" onsubmit="return validateForm('add')">
      <input type="hidden" name="action" value="add">
      
      <div class="form-grid">
        <div class="form-group">
          <label for="subject_code" class="required">Subject Code</label>
          <input type="text" id="subject_code" name="subject_code" class="form-control" required 
                 placeholder="e.g., CS101, MATH202">
        </div>
        
        <div class="form-group">
          <label for="subject_name" class="required">Subject Name</label>
          <input type="text" id="subject_name" name="subject_name" class="form-control" required 
                 placeholder="e.g., Introduction to Programming">
        </div>
        
        <div class="form-group">
          <label for="credit_hours" class="required">Credit Hours</label>
          <select id="credit_hours" name="credit_hours" class="form-control" required>
            <option value="1">1 Credit Hour</option>
            <option value="2">2 Credit Hours</option>
            <option value="3" selected>3 Credit Hours</option>
            <option value="4">4 Credit Hours</option>
            <option value="5">5 Credit Hours</option>
            <option value="6">6 Credit Hours</option>
          </select>
        </div>
        
        <div class="form-group">
          <label for="status" class="required">Status</label>
          <select id="status" name="status" class="form-control" required>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
        
        <div class="form-group">
          <label for="campus_id" class="required">Campus</label>
          <select id="campus_id" name="campus_id" class="form-control" required 
                  onchange="onCampusChange('add')">
            <option value="">Select Campus</option>
            <?php foreach($campuses as $campus): ?>
            <option value="<?= $campus['campus_id'] ?>">
              <?= htmlspecialchars($campus['campus_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="form-group">
          <label for="faculty_id" class="required">Faculty</label>
          <select id="faculty_id" name="faculty_id" class="form-control" required 
                  onchange="onFacultyChange('add')" disabled>
            <option value="">Select Faculty</option>
          </select>
        </div>
        
        <div class="form-group">
          <label for="department_id" class="required">Department</label>
          <select id="department_id" name="department_id" class="form-control" required 
                  onchange="onDepartmentChange('add')" disabled>
            <option value="">Select Department</option>
          </select>
        </div>
        
        <div class="form-group">
          <label for="program_id" class="required">Program</label>
          <select id="program_id" name="program_id" class="form-control" required 
                  onchange="onProgramChange('add')" disabled>
            <option value="">Select Program</option>
          </select>
        </div>
        
        <div class="form-group">
          <label for="class_id" class="required">Class</label>
          <select id="class_id" name="class_id" class="form-control" required disabled>
            <option value="">Select Class</option>
          </select>
        </div>
        
        <div class="form-group">
          <label for="semester_id" class="required">Semester</label>
          <select id="semester_id" name="semester_id" class="form-control" required>
            <option value="">Select Semester</option>
            <?php foreach($semesters as $sem): ?>
            <option value="<?= $sem['semester_id'] ?>">
              <?= htmlspecialchars($sem['semester_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="form-group">
          <label for="academic_year_id" class="required">Academic Year</label>
          <select id="academic_year_id" name="academic_year_id" class="form-control" required 
                  onchange="onAcademicYearChange('add')">
            <option value="">Select Academic Year</option>
            <?php foreach($academic_years as $year): ?>
            <option value="<?= $year['academic_year_id'] ?>">
              <?= htmlspecialchars($year['year_name']) ?> 
              (<?= date('Y', strtotime($year['start_date'])) ?>-<?= date('Y', strtotime($year['end_date'])) ?>)
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="form-group">
          <label for="academic_term_id" class="required">Academic Term</label>
          <select id="academic_term_id" name="academic_term_id" class="form-control" required 
                  onchange="onTermChange('add')">
            <option value="">Select Academic Term</option>
            <?php foreach($academic_terms as $term): ?>
            <option value="<?= $term['academic_term_id'] ?>">
              <?= htmlspecialchars($term['term_name']) ?> 
              (<?= $term['display_status'] ?>)
            </option>
            <?php endforeach; ?>
          </select>
          <div id="termInfo" style="margin-top: 5px; font-size: 12px; color: #666;"></div>
        </div>
        
        <div class="form-group" style="grid-column: 1 / -1;">
          <label for="description">Description</label>
          <textarea id="description" name="description" class="form-control" rows="3" 
                    placeholder="Optional description about the subject"></textarea>
        </div>
      </div>
      
      <!-- Term Warning -->
      <div id="termWarning" class="term-warning" style="display: none;">
        <i class="fas fa-exclamation-triangle"></i>
        <span id="termWarningText"></span>
      </div>
      
      <button class="submit-btn" type="submit">
        <i class="fas fa-save"></i> Save Subject
      </button>
    </form>
  </div>
</div>

<!-- ✅ EDIT MODAL -->
<div class="modal" id="editModal">
  <div class="modal-content">
    <button class="close-modal" onclick="closeModal('editModal')">&times;</button>
    <h2><i class="fas fa-edit"></i> Edit Subject</h2>
    <form method="POST" id="editForm" onsubmit="return validateForm('edit')">
      <input type="hidden" name="action" value="update">
      <input type="hidden" id="edit_id" name="subject_id">
      
      <div class="form-grid">
        <div class="form-group">
          <label for="edit_subject_code" class="required">Subject Code</label>
          <input type="text" id="edit_subject_code" name="subject_code" class="form-control" required>
        </div>
        
        <div class="form-group">
          <label for="edit_subject_name" class="required">Subject Name</label>
          <input type="text" id="edit_subject_name" name="subject_name" class="form-control" required>
        </div>
        
        <div class="form-group">
          <label for="edit_credit_hours" class="required">Credit Hours</label>
          <select id="edit_credit_hours" name="credit_hours" class="form-control" required>
            <option value="1">1 Credit Hour</option>
            <option value="2">2 Credit Hours</option>
            <option value="3">3 Credit Hours</option>
            <option value="4">4 Credit Hours</option>
            <option value="5">5 Credit Hours</option>
            <option value="6">6 Credit Hours</option>
          </select>
        </div>
        
        <div class="form-group">
          <label for="edit_status" class="required">Status</label>
          <select id="edit_status" name="status" class="form-control" required>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
        
        <div class="form-group">
          <label for="edit_campus_id" class="required">Campus</label>
          <select id="edit_campus_id" name="campus_id" class="form-control" required 
                  onchange="onCampusChange('edit')">
            <option value="">Select Campus</option>
            <?php foreach($campuses as $campus): ?>
            <option value="<?= $campus['campus_id'] ?>">
              <?= htmlspecialchars($campus['campus_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="form-group">
          <label for="edit_faculty_id" class="required">Faculty</label>
          <select id="edit_faculty_id" name="faculty_id" class="form-control" required 
                  onchange="onFacultyChange('edit')" disabled>
            <option value="">Select Faculty</option>
          </select>
        </div>
        
        <div class="form-group">
          <label for="edit_department_id" class="required">Department</label>
          <select id="edit_department_id" name="department_id" class="form-control" required 
                  onchange="onDepartmentChange('edit')" disabled>
            <option value="">Select Department</option>
          </select>
        </div>
        
        <div class="form-group">
          <label for="edit_program_id" class="required">Program</label>
          <select id="edit_program_id" name="program_id" class="form-control" required 
                  onchange="onProgramChange('edit')" disabled>
            <option value="">Select Program</option>
          </select>
        </div>
        
        <div class="form-group">
          <label for="edit_class_id" class="required">Class</label>
          <select id="edit_class_id" name="class_id" class="form-control" required disabled>
            <option value="">Select Class</option>
          </select>
        </div>
        
        <div class="form-group">
          <label for="edit_semester_id" class="required">Semester</label>
          <select id="edit_semester_id" name="semester_id" class="form-control" required>
            <option value="">Select Semester</option>
            <?php foreach($semesters as $sem): ?>
            <option value="<?= $sem['semester_id'] ?>">
              <?= htmlspecialchars($sem['semester_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="form-group">
          <label for="edit_academic_year_id" class="required">Academic Year</label>
          <select id="edit_academic_year_id" name="academic_year_id" class="form-control" required 
                  onchange="onAcademicYearChange('edit')">
            <option value="">Select Academic Year</option>
            <?php foreach($academic_years as $year): ?>
            <option value="<?= $year['academic_year_id'] ?>">
              <?= htmlspecialchars($year['year_name']) ?> 
              (<?= date('Y', strtotime($year['start_date'])) ?>-<?= date('Y', strtotime($year['end_date'])) ?>)
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="form-group">
          <label for="edit_academic_term_id" class="required">Academic Term</label>
          <select id="edit_academic_term_id" name="academic_term_id" class="form-control" required 
                  onchange="onTermChange('edit')">
            <option value="">Select Academic Term</option>
            <?php foreach($academic_terms as $term): ?>
            <option value="<?= $term['academic_term_id'] ?>">
              <?= htmlspecialchars($term['term_name']) ?> 
              (<?= $term['display_status'] ?>)
            </option>
            <?php endforeach; ?>
          </select>
          <div id="edit_termInfo" style="margin-top: 5px; font-size: 12px; color: #666;"></div>
        </div>
        
        <div class="form-group" style="grid-column: 1 / -1;">
          <label for="edit_description">Description</label>
          <textarea id="edit_description" name="description" class="form-control" rows="3"></textarea>
        </div>
      </div>
      
      <!-- Term Warning -->
      <div id="edit_termWarning" class="term-warning" style="display: none;">
        <i class="fas fa-exclamation-triangle"></i>
        <span id="edit_termWarningText"></span>
      </div>
      
      <button class="submit-btn" type="submit">
        <i class="fas fa-sync-alt"></i> Update Subject
      </button>
    </form>
  </div>
</div>

<!-- ✅ DELETE MODAL -->
<div class="modal" id="deleteModal">
  <div class="modal-content">
    <button class="close-modal" onclick="closeModal('deleteModal')">&times;</button>
    <h2 style="color: var(--danger-color);"><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h2>
    <form method="POST">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="subject_id" id="delete_id">
      
      <div style="text-align: center; margin: 30px 0;">
        <i class="fa-solid fa-exclamation-triangle" style="font-size: 48px; color: var(--danger-color); margin-bottom: 20px;"></i>
        <p style="font-size: 16px; color: var(--dark-color); margin-bottom: 10px;" id="deleteMessage">
          Are you sure you want to delete this subject?
        </p>
        <p style="color: #666; font-size: 14px;">
          This action cannot be undone. Make sure there are no allocations or student grades associated with this subject.
        </p>
      </div>
      
      <button class="submit-btn delete-btn" type="submit">
        <i class="fas fa-trash-alt"></i> Yes, Delete Subject
      </button>
    </form>
  </div>
</div>

<!-- ✅ ALERT POPUP -->
<div id="popup" class="alert-popup <?= $type ?> <?= !empty($message)?'show':'' ?>">
  <span class="alert-icon"><?= $type==='success' ? '✓' : '✗' ?></span>
  <div class="alert-message"><?= $message ?></div>
</div>

<script>
// ===========================================
// RESPONSIVE LAYOUT HANDLING
// ===========================================
function handleResponsiveLayout() {
  const tableContainer = document.querySelector('.table-responsive');
  const mobileView = document.querySelector('.mobile-card-view');
  
  if (window.innerWidth <= 576) {
    if (tableContainer) tableContainer.style.display = 'none';
    if (mobileView) mobileView.classList.add('show');
  } else {
    if (tableContainer) tableContainer.style.display = 'block';
    if (mobileView) mobileView.classList.remove('show');
  }
}

// Initial check and resize listener
window.addEventListener('DOMContentLoaded', handleResponsiveLayout);
window.addEventListener('resize', handleResponsiveLayout);

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

function onFilterYearChange() {
    const yearId = document.getElementById('academic_year').value;
    const termSelect = document.getElementById('academic_term');
    
    if (!yearId) {
        termSelect.disabled = true;
        termSelect.innerHTML = '<option value="">All Academic Terms</option>';
        <?php foreach($academic_terms as $term): ?>
        const option = document.createElement('option');
        option.value = '<?= $term['academic_term_id'] ?>';
        option.textContent = '<?= htmlspecialchars($term['term_name']) ?> (<?= $term['display_status'] ?>)';
        option.setAttribute('data-status', '<?= $term['display_status'] ?>');
        termSelect.appendChild(option);
        <?php endforeach; ?>
        return;
    }
    
    termSelect.disabled = false;
    termSelect.innerHTML = '<option value="">All Academic Terms</option>';
    
    fetch(`?ajax=get_terms_by_year&year_id=${yearId}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                data.terms.forEach(term => {
                    const option = document.createElement('option');
                    option.value = term.academic_term_id;
                    let displayText = term.term_name;
                    if (term.status) {
                        displayText += ` (${term.status})`;
                    }
                    option.textContent = displayText;
                    option.setAttribute('data-status', term.status);
                    termSelect.appendChild(option);
                });
            }
        });
}

function clearFilters() {
    document.getElementById('search').value = '';
    document.getElementById('status').value = '';
    document.getElementById('campus').value = '';
    document.getElementById('faculty').value = '';
    document.getElementById('department').value = '';
    document.getElementById('program').value = '';
    document.getElementById('class').value = '';
    document.getElementById('semester').value = '';
    document.getElementById('academic_year').value = '';
    document.getElementById('academic_term').value = '';
    
    document.getElementById('filterForm').submit();
}

// ===========================================
// MODAL FUNCTIONS
// ===========================================
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        document.body.style.paddingRight = window.innerWidth - document.documentElement.clientWidth + 'px';
        
        // Focus on first input in modal for desktop
        const firstInput = modal.querySelector('input, select, textarea');
        if (firstInput && window.innerWidth > 768) {
            setTimeout(() => firstInput.focus(), 100);
        }
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('show');
        document.body.style.overflow = 'auto';
        document.body.style.paddingRight = '';
    }
}

function openDeleteModal(id, subjectName) {
    document.getElementById('delete_id').value = id;
    document.getElementById('deleteMessage').innerHTML = 
        `Are you sure you want to delete the subject <strong>"${subjectName}"</strong>?`;
    openModal('deleteModal');
}

function editSubject(data) {
    openModal('editModal');
    
    // Set basic fields
    document.getElementById('edit_id').value = data.subject_id;
    document.getElementById('edit_subject_code').value = data.subject_code;
    document.getElementById('edit_subject_name').value = data.subject_name;
    document.getElementById('edit_credit_hours').value = data.credit_hours || 3;
    document.getElementById('edit_status').value = data.status;
    document.getElementById('edit_description').value = data.description || '';
    document.getElementById('edit_semester_id').value = data.semester_id || '';
    
    // Set campus and load hierarchy
    if (data.campus_id) {
        document.getElementById('edit_campus_id').value = data.campus_id;
        
        // Enable and load faculty dropdown
        const facultySelect = document.getElementById('edit_faculty_id');
        facultySelect.disabled = false;
        
        // Populate faculty dropdown with current value first
        facultySelect.innerHTML = '<option value="">Select Faculty</option>';
        const facultyOption = document.createElement('option');
        facultyOption.value = data.faculty_id;
        facultyOption.textContent = data.faculty_name || 'Faculty';
        facultyOption.selected = true;
        facultySelect.appendChild(facultyOption);
        
        // Load departments
        const deptSelect = document.getElementById('edit_department_id');
        deptSelect.disabled = false;
        deptSelect.innerHTML = '<option value="">Select Department</option>';
        const deptOption = document.createElement('option');
        deptOption.value = data.department_id;
        deptOption.textContent = data.department_name || 'Department';
        deptOption.selected = true;
        deptSelect.appendChild(deptOption);
        
        // Load programs
        const programSelect = document.getElementById('edit_program_id');
        programSelect.disabled = false;
        programSelect.innerHTML = '<option value="">Select Program</option>';
        const programOption = document.createElement('option');
        programOption.value = data.program_id;
        programOption.textContent = `${data.program_name || 'Program'} (${data.program_code || ''})`;
        programOption.selected = true;
        programSelect.appendChild(programOption);
        
        // Load classes
        const classSelect = document.getElementById('edit_class_id');
        classSelect.disabled = false;
        classSelect.innerHTML = '<option value="">Select Class</option>';
        const classOption = document.createElement('option');
        classOption.value = data.class_id;
        classOption.textContent = data.class_name || 'Class';
        classOption.selected = true;
        classSelect.appendChild(classOption);
        
        // Now load the full hierarchy for dropdowns
        setTimeout(() => {
            loadFacultiesByCampus('edit', data.campus_id, data.faculty_id, data.department_id, data.program_id, data.class_id);
        }, 100);
    }
    
    // Set academic year and load terms
    if (data.academic_year_id) {
        document.getElementById('edit_academic_year_id').value = data.academic_year_id;
        
        // Enable and populate term dropdown with current value first
        const termSelect = document.getElementById('edit_academic_term_id');
        termSelect.disabled = false;
        termSelect.innerHTML = '<option value="">Select Academic Term</option>';
        const termOption = document.createElement('option');
        termOption.value = data.academic_term_id;
        termOption.textContent = data.academic_term_name || 'Term';
        termOption.selected = true;
        termSelect.appendChild(termOption);
        
        // Check term status
        setTimeout(() => {
            checkTermExpirationAndUpdateStatus(data.academic_term_id);
        }, 200);
    }
}

// Function to check if term is expired and update status
function checkTermExpirationAndUpdateStatus(termId) {
    if (!termId) return;
    
    fetch(`?ajax=check_term_status&term_id=${termId}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                const isExpired = data.is_expired;
                const statusSelect = document.getElementById('edit_status');
                
                if (isExpired) {
                    // If term is expired, automatically set subject to inactive
                    if (statusSelect.value === 'active') {
                        statusSelect.value = 'inactive';
                    }
                    
                    // Show warning
                    const warningDiv = document.getElementById('edit_termWarning');
                    const warningText = document.getElementById('edit_termWarningText');
                    
                    warningText.innerHTML = `This academic term ended on ${data.term.end_date}. Subjects with expired terms should be set to inactive.`;
                    warningDiv.style.display = 'flex';
                    
                    // Disable active option
                    const activeOption = statusSelect.querySelector('option[value="active"]');
                    if (activeOption) {
                        activeOption.disabled = true;
                        activeOption.style.color = '#999';
                        activeOption.style.backgroundColor = '#f5f5f5';
                    }
                } else {
                    // Enable active option if term is not expired
                    const activeOption = statusSelect.querySelector('option[value="active"]');
                    if (activeOption) {
                        activeOption.disabled = false;
                        activeOption.style.color = '';
                        activeOption.style.backgroundColor = '';
                    }
                    
                    // Hide warning
                    const warningDiv = document.getElementById('edit_termWarning');
                    warningDiv.style.display = 'none';
                }
            }
        })
        .catch(error => {
            console.error('Error checking term expiration:', error);
        });
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        const modals = document.querySelectorAll('.modal.show');
        modals.forEach(modal => {
            modal.classList.remove('show');
            document.body.style.overflow = 'auto';
            document.body.style.paddingRight = '';
        });
    }
}

// ===========================================
// AJAX FUNCTIONS - MODAL HIERARCHY
// ===========================================
function loadAcademicYears(type = 'add') {
    const prefix = type === 'add' ? '' : 'edit_';
    const yearSelect = document.getElementById(prefix + 'academic_year_id');
    
    // If already populated (from PHP), skip
    if (yearSelect.options.length > 1) return;
    
    yearSelect.innerHTML = '<option value="">Loading...</option>';
    
    fetch(`?ajax=get_academic_years`)
        .then(response => response.json())
        .then(data => {
            yearSelect.innerHTML = '<option value="">Select Academic Year</option>';
            
            if (data.status === 'success' && data.years.length > 0) {
                data.years.forEach(year => {
                    const option = document.createElement('option');
                    option.value = year.academic_year_id;
                    let displayText = year.year_name;
                    if (year.start_date && year.end_date) {
                        const startYear = new Date(year.start_date).getFullYear();
                        const endYear = new Date(year.end_date).getFullYear();
                        displayText += ` (${startYear}-${endYear})`;
                    }
                    option.textContent = displayText;
                    yearSelect.appendChild(option);
                });
            } else {
                yearSelect.innerHTML = '<option value="">No active academic years found</option>';
            }
        })
        .catch(error => {
            console.error('Error loading academic years:', error);
            yearSelect.innerHTML = '<option value="">Error loading</option>';
        });
}

function onAcademicYearChange(type = 'add') {
    const prefix = type === 'add' ? '' : 'edit_';
    const yearId = document.getElementById(prefix + 'academic_year_id').value;
    const termSelect = document.getElementById(prefix + 'academic_term_id');
    
    if (!yearId) {
        termSelect.innerHTML = '<option value="">Select Academic Term</option>';
        termSelect.disabled = true;
        return;
    }
    
    termSelect.innerHTML = '<option value="">Loading...</option>';
    termSelect.disabled = false;
    
    // Load only ACTIVE terms for this academic year
    fetch(`?ajax=get_terms_by_year&year_id=${yearId}`)
        .then(response => response.json())
        .then(data => {
            termSelect.innerHTML = '<option value="">Select Academic Term</option>';
            
            if (data.status === 'success' && data.terms.length > 0) {
                data.terms.forEach(term => {
                    const option = document.createElement('option');
                    option.value = term.academic_term_id;
                    let displayText = term.term_name;
                    if (term.status) {
                        displayText += ` (${term.status})`;
                    }
                    option.textContent = displayText;
                    option.setAttribute('data-status', term.status);
                    termSelect.appendChild(option);
                });
            } else {
                termSelect.innerHTML = '<option value="">No active terms found for this year</option>';
            }
        })
        .catch(error => {
            console.error('Error loading terms:', error);
            termSelect.innerHTML = '<option value="">Error loading</option>';
        });
}

function loadTermsForYear(type, yearId, selectedTermId) {
    const prefix = type === 'add' ? '' : 'edit_';
    const termSelect = document.getElementById(prefix + 'academic_term_id');
    
    if (!yearId) {
        termSelect.innerHTML = '<option value="">Select Academic Term</option>';
        termSelect.disabled = true;
        return;
    }
    
    termSelect.innerHTML = '<option value="">Loading...</option>';
    termSelect.disabled = false;
    
    fetch(`?ajax=get_terms_by_year&year_id=${yearId}`)
        .then(response => response.json())
        .then(data => {
            termSelect.innerHTML = '<option value="">Select Academic Term</option>';
            
            if (data.status === 'success' && data.terms.length > 0) {
                data.terms.forEach(term => {
                    const option = document.createElement('option');
                    option.value = term.academic_term_id;
                    let displayText = term.term_name;
                    if (term.status) {
                        displayText += ` (${term.status})`;
                    }
                    option.textContent = displayText;
                    option.setAttribute('data-status', term.status);
                    termSelect.appendChild(option);
                });
                
                if (selectedTermId) {
                    termSelect.value = selectedTermId;
                    onTermChange(type);
                }
            } else {
                termSelect.innerHTML = '<option value="">No active terms found for this year</option>';
            }
        })
        .catch(error => {
            console.error('Error loading terms:', error);
            termSelect.innerHTML = '<option value="">Error loading</option>';
        });
}

function onTermChange(type = 'add') {
    const prefix = type === 'add' ? '' : 'edit_';
    const termId = document.getElementById(prefix + 'academic_term_id').value;
    const termWarning = document.getElementById(prefix + 'termWarning');
    const termInfo = document.getElementById(prefix + 'termInfo');
    const statusSelect = document.getElementById(prefix + 'status');
    
    if (!termId) {
        termWarning.style.display = 'none';
        if (termInfo) termInfo.innerHTML = '';
        
        // Enable all status options
        if (statusSelect) {
            const options = statusSelect.querySelectorAll('option');
            options.forEach(option => {
                option.disabled = false;
                option.style.color = '';
                option.style.backgroundColor = '';
            });
        }
        return;
    }
    
    fetch(`?ajax=check_term_status&term_id=${termId}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                const term = data.term;
                const isExpired = data.is_expired;
                
                // Display term info
                if (termInfo) {
                    termInfo.innerHTML = `
                        ${term.term_name}: 
                        ${term.start_date} to ${term.end_date}
                        <br><small>Academic Year: ${term.year_name} (${new Date(term.year_start).getFullYear()}-${new Date(term.year_end).getFullYear()})</small>
                        <br><small>Status: ${term.status} ${isExpired ? '(Expired)' : ''}</small>
                    `;
                }
                
                // Show warning if term is expired
                if (isExpired) {
                    termWarning.style.display = 'flex';
                    document.getElementById(prefix + 'termWarningText').innerHTML = 
                        `This academic term ended on ${term.end_date}. Subjects with expired terms should be set to inactive.`;
                    
                    // Automatically set to inactive if trying to set as active
                    if (statusSelect && statusSelect.value === 'active') {
                        statusSelect.value = 'inactive';
                    }
                    
                    // Disable active option
                    if (statusSelect) {
                        const activeOption = statusSelect.querySelector('option[value="active"]');
                        if (activeOption) {
                            activeOption.disabled = true;
                            activeOption.style.color = '#999';
                            activeOption.style.backgroundColor = '#f5f5f5';
                        }
                    }
                } else {
                    termWarning.style.display = 'none';
                    
                    // Enable active option if term is not expired
                    if (statusSelect) {
                        const activeOption = statusSelect.querySelector('option[value="active"]');
                        if (activeOption) {
                            activeOption.disabled = false;
                            activeOption.style.color = '';
                            activeOption.style.backgroundColor = '';
                        }
                    }
                }
            }
        })
        .catch(error => {
            console.error('Error checking term status:', error);
        });
}

function onCampusChange(type = 'add') {
    const prefix = type === 'add' ? '' : 'edit_';
    const campusId = document.getElementById(prefix + 'campus_id').value;
    const facultySelect = document.getElementById(prefix + 'faculty_id');
    const deptSelect = document.getElementById(prefix + 'department_id');
    const programSelect = document.getElementById(prefix + 'program_id');
    const classSelect = document.getElementById(prefix + 'class_id');
    const subjectSelect = type === 'add' ? document.getElementById('subject_id') : document.getElementById('edit_subject_id');
    
    if (!campusId) {
        resetDependentDropdowns(type);
        return;
    }
    
    facultySelect.innerHTML = '<option value="">Loading...</option>';
    facultySelect.disabled = true;
    resetChildDropdowns(type, deptSelect, programSelect, classSelect, subjectSelect);
    
    fetch(`?ajax=get_faculties&campus_id=${campusId}`)
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
                facultySelect.innerHTML = '<option value="">No faculties found</option>';
                facultySelect.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error loading faculties:', error);
            facultySelect.innerHTML = '<option value="">Error loading</option>';
            facultySelect.disabled = false;
        });
}

function onFacultyChange(type = 'add') {
    const prefix = type === 'add' ? '' : 'edit_';
    const facultyId = document.getElementById(prefix + 'faculty_id').value;
    const campusId = document.getElementById(prefix + 'campus_id').value;
    const deptSelect = document.getElementById(prefix + 'department_id');
    const programSelect = document.getElementById(prefix + 'program_id');
    const classSelect = document.getElementById(prefix + 'class_id');
    const subjectSelect = type === 'add' ? document.getElementById('subject_id') : document.getElementById('edit_subject_id');
    
    if (!facultyId || !campusId) {
        resetChildDropdowns(type, deptSelect, programSelect, classSelect, subjectSelect);
        return;
    }
    
    deptSelect.innerHTML = '<option value="">Loading...</option>';
    deptSelect.disabled = true;
    resetChildDropdowns(type, programSelect, classSelect, subjectSelect);
    
    fetch(`?ajax=get_departments&faculty_id=${facultyId}&campus_id=${campusId}`)
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

function onDepartmentChange(type = 'add') {
    const prefix = type === 'add' ? '' : 'edit_';
    const deptId = document.getElementById(prefix + 'department_id').value;
    const facultyId = document.getElementById(prefix + 'faculty_id').value;
    const campusId = document.getElementById(prefix + 'campus_id').value;
    const programSelect = document.getElementById(prefix + 'program_id');
    const classSelect = document.getElementById(prefix + 'class_id');
    const subjectSelect = type === 'add' ? document.getElementById('subject_id') : document.getElementById('edit_subject_id');
    
    if (!deptId || !facultyId || !campusId) {
        resetChildDropdowns(type, programSelect, classSelect, subjectSelect);
        return;
    }
    
    programSelect.innerHTML = '<option value="">Loading...</option>';
    programSelect.disabled = true;
    resetChildDropdowns(type, classSelect, subjectSelect);
    
    fetch(`?ajax=get_programs&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
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
                programSelect.innerHTML = '<option value="">No programs found</option>';
                programSelect.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error loading programs:', error);
            programSelect.innerHTML = '<option value="">Error loading</option>';
            programSelect.disabled = false;
        });
}

function onProgramChange(type = 'add') {
    const prefix = type === 'add' ? '' : 'edit_';
    const programId = document.getElementById(prefix + 'program_id').value;
    const deptId = document.getElementById(prefix + 'department_id').value;
    const facultyId = document.getElementById(prefix + 'faculty_id').value;
    const campusId = document.getElementById(prefix + 'campus_id').value;
    const classSelect = document.getElementById(prefix + 'class_id');
    const subjectSelect = type === 'add' ? document.getElementById('subject_id') : document.getElementById('edit_subject_id');
    
    if (!programId || !deptId || !facultyId || !campusId) {
        resetChildDropdowns(type, classSelect, subjectSelect);
        return;
    }
    
    classSelect.innerHTML = '<option value="">Loading...</option>';
    classSelect.disabled = true;
    if (subjectSelect) {
        subjectSelect.innerHTML = '<option value="">Select Subject</option>';
        subjectSelect.disabled = true;
    }
    
    fetch(`?ajax=get_classes&program_id=${programId}&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
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

function onClassChange(type = 'add') {
    const prefix = type === 'add' ? '' : 'edit_';
    const classId = document.getElementById(prefix + 'class_id').value;
    const programId = document.getElementById(prefix + 'program_id').value;
    const deptId = document.getElementById(prefix + 'department_id').value;
    const facultyId = document.getElementById(prefix + 'faculty_id').value;
    const campusId = document.getElementById(prefix + 'campus_id').value;
    const subjectSelect = type === 'add' ? document.getElementById('subject_id') : document.getElementById('edit_subject_id');
    
    if (!classId || !programId || !deptId || !facultyId || !campusId) {
        if (subjectSelect) {
            subjectSelect.innerHTML = '<option value="">Select Subject</option>';
            subjectSelect.disabled = true;
        }
        return;
    }
    
    if (subjectSelect) {
        subjectSelect.innerHTML = '<option value="">Loading...</option>';
        subjectSelect.disabled = true;
    }
    
    // Note: For subject management, we don't need to load subjects here
    // because we're creating/editing the subject itself
    if (subjectSelect) {
        subjectSelect.innerHTML = '<option value="">N/A (This is the subject being created/edited)</option>';
        subjectSelect.disabled = true;
    }
}

function loadFacultiesByCampus(type, campusId, selectedFacultyId, selectedDeptId, selectedProgramId, selectedClassId) {
    const prefix = type === 'add' ? '' : 'edit_';
    const facultySelect = document.getElementById(prefix + 'faculty_id');
    
    if (!campusId) {
        facultySelect.innerHTML = '<option value="">Select Faculty</option>';
        facultySelect.disabled = true;
        return;
    }
    
    facultySelect.innerHTML = '<option value="">Loading...</option>';
    facultySelect.disabled = true;
    
    fetch(`?ajax=get_faculties&campus_id=${campusId}`)
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
                
                if (selectedFacultyId) {
                    facultySelect.value = selectedFacultyId;
                    loadDepartmentsByFaculty(type, selectedFacultyId, campusId, selectedDeptId, selectedProgramId, selectedClassId);
                }
            } else {
                facultySelect.innerHTML = '<option value="">No faculties found</option>';
                facultySelect.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error loading faculties:', error);
            facultySelect.innerHTML = '<option value="">Error loading</option>';
            facultySelect.disabled = false;
        });
}

function loadDepartmentsByFaculty(type, facultyId, campusId, selectedDeptId, selectedProgramId, selectedClassId) {
    const prefix = type === 'add' ? '' : 'edit_';
    const deptSelect = document.getElementById(prefix + 'department_id');
    
    if (!facultyId || !campusId) {
        deptSelect.innerHTML = '<option value="">Select Department</option>';
        deptSelect.disabled = true;
        return;
    }
    
    deptSelect.innerHTML = '<option value="">Loading...</option>';
    deptSelect.disabled = true;
    
    fetch(`?ajax=get_departments&faculty_id=${facultyId}&campus_id=${campusId}`)
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
                
                if (selectedDeptId) {
                    deptSelect.value = selectedDeptId;
                    loadProgramsByDepartment(type, selectedDeptId, facultyId, campusId, selectedProgramId, selectedClassId);
                }
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

function loadProgramsByDepartment(type, deptId, facultyId, campusId, selectedProgramId, selectedClassId) {
    const prefix = type === 'add' ? '' : 'edit_';
    const programSelect = document.getElementById(prefix + 'program_id');
    
    if (!deptId || !facultyId || !campusId) {
        programSelect.innerHTML = '<option value="">Select Program</option>';
        programSelect.disabled = true;
        return;
    }
    
    programSelect.innerHTML = '<option value="">Loading...</option>';
    programSelect.disabled = true;
    
    fetch(`?ajax=get_programs&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
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
                
                if (selectedProgramId) {
                    programSelect.value = selectedProgramId;
                    loadClassesByProgram(type, selectedProgramId, deptId, facultyId, campusId, selectedClassId);
                }
            } else {
                programSelect.innerHTML = '<option value="">No programs found</option>';
                programSelect.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error loading programs:', error);
            programSelect.innerHTML = '<option value="">Error loading</option>';
            programSelect.disabled = false;
        });
}

function loadClassesByProgram(type, programId, deptId, facultyId, campusId, selectedClassId) {
    const prefix = type === 'add' ? '' : 'edit_';
    const classSelect = document.getElementById(prefix + 'class_id');
    
    if (!programId || !deptId || !facultyId || !campusId) {
        classSelect.innerHTML = '<option value="">Select Class</option>';
        classSelect.disabled = true;
        return;
    }
    
    classSelect.innerHTML = '<option value="">Loading...</option>';
    classSelect.disabled = true;
    
    fetch(`?ajax=get_classes&program_id=${programId}&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
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
                
                if (selectedClassId) {
                    classSelect.value = selectedClassId;
                }
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
function resetDependentDropdowns(type) {
    const prefix = type === 'add' ? '' : 'edit_';
    
    const facultySelect = document.getElementById(prefix + 'faculty_id');
    const deptSelect = document.getElementById(prefix + 'department_id');
    const programSelect = document.getElementById(prefix + 'program_id');
    const classSelect = document.getElementById(prefix + 'class_id');
    const termSelect = document.getElementById(prefix + 'academic_term_id');
    
    facultySelect.innerHTML = '<option value="">Select Faculty</option>';
    facultySelect.disabled = true;
    deptSelect.innerHTML = '<option value="">Select Department</option>';
    deptSelect.disabled = true;
    programSelect.innerHTML = '<option value="">Select Program</option>';
    programSelect.disabled = true;
    classSelect.innerHTML = '<option value="">Select Class</option>';
    classSelect.disabled = true;
    
    if (termSelect) {
        termSelect.innerHTML = '<option value="">Select Academic Term</option>';
        termSelect.disabled = true;
    }
}

function resetChildDropdowns(type, ...selects) {
    const prefix = type === 'add' ? '' : 'edit_';
    
    selects.forEach(select => {
        if (select) {
            const selectId = select.id.replace(prefix, '');
            const label = selectId.replace('_id', '').replace(/_/g, ' ');
            select.innerHTML = `<option value="">Select ${label}</option>`;
            select.disabled = true;
        }
    });
}

// ===========================================
// FORM VALIDATION
// ===========================================
function validateForm(formType) {
    const prefix = formType === 'add' ? '' : 'edit_';
    const classId = document.getElementById(prefix + 'class_id').value;
    const semesterId = document.getElementById(prefix + 'semester_id').value;
    const academicYearId = document.getElementById(prefix + 'academic_year_id').value;
    const academicTermId = document.getElementById(prefix + 'academic_term_id').value;
    const campusId = document.getElementById(prefix + 'campus_id').value;
    
    if (!campusId) {
        alert("Please select a campus. Campus selection is required!");
        document.getElementById(prefix + 'campus_id').focus();
        return false;
    }
    
    if (!classId) {
        alert("Please select a class. Class selection is required!");
        document.getElementById(prefix + 'class_id').focus();
        return false;
    }
    
    if (!semesterId) {
        alert("Please select a semester. Semester selection is required!");
        document.getElementById(prefix + 'semester_id').focus();
        return false;
    }
    
    if (!academicYearId) {
        alert("Please select an academic year. Academic year selection is required!");
        document.getElementById(prefix + 'academic_year_id').focus();
        return false;
    }
    
    if (!academicTermId) {
        alert("Please select an academic term. Academic term selection is required!");
        document.getElementById(prefix + 'academic_term_id').focus();
        return false;
    }
    
    return true;
}

// ===========================================
// KEYBOARD SHORTCUTS
// ===========================================
document.addEventListener('keydown', function(e) {
    // Escape key closes modals
    if (e.key === 'Escape') {
        const openModals = document.querySelectorAll('.modal.show');
        openModals.forEach(modal => {
            modal.classList.remove('show');
        });
        document.body.style.overflow = 'auto';
        document.body.style.paddingRight = '';
    }
    
    // Ctrl + N opens add modal
    if (e.ctrlKey && e.key === 'n') {
        e.preventDefault();
        openModal('addModal');
    }
    
    // Ctrl + F focuses on search
    if (e.ctrlKey && e.key === 'f') {
        e.preventDefault();
        document.getElementById('search').focus();
    }
});

// Auto-hide alert after 5 seconds
setTimeout(function() {
    const alert = document.querySelector('.alert-popup.show');
    if (alert) {
        alert.classList.remove('show');
    }
}, 5000);

// Close alert on click
document.addEventListener('click', function(e) {
    if (e.target.closest('.alert-popup.show')) {
        e.target.closest('.alert-popup.show').classList.remove('show');
    }
});

// Initialize when page loads
document.addEventListener('DOMContentLoaded', function() {
    handleResponsiveLayout();
    
    // Load academic years in both modals
    loadAcademicYears('add');
    loadAcademicYears('edit');
    
    // Initialize filter term select based on selected year
    <?php if($academic_year_filter): ?>
    onFilterYearChange();
    <?php endif; ?>
});
</script>

<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>