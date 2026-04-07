<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Access Control - CHANGED: Now allows campus_admin instead of super_admin
if (strtolower($_SESSION['user']['role'] ?? '') !== 'campus_admin') {
  header("Location: ../login.php");
  exit;
}

// ✅ Get logged in campus admin's campus ID
$logged_in_campus_admin_id = $_SESSION['user']['user_id'] ?? 0;
$logged_in_campus_ids = [];

if ($logged_in_campus_admin_id > 0) {
    // Get campus IDs that this campus admin manages
    $campusAdminStmt = $pdo->prepare("
        SELECT linked_id 
        FROM users 
        WHERE user_id = ? AND role = 'campus_admin' AND linked_table = 'campus'
    ");
    $campusAdminStmt->execute([$logged_in_campus_admin_id]);
    $campusAdmin = $campusAdminStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($campusAdmin) {
        $logged_in_campus_ids[] = $campusAdmin['linked_id'];
    }
}

$message = "";
$type = "";

// ✅ AJAX ENDPOINTS FOR VALIDATION
if (isset($_GET['ajax'])) {
  ob_clean();
  
  if ($_GET['ajax'] === 'check_faculty_name_in_campuses') {
    $name = $_GET['name'] ?? '';
    $campus_ids = isset($_GET['campuses']) ? explode(',', $_GET['campuses']) : [];
    $exclude_id = $_GET['exclude_id'] ?? 0;
    
    $conflicts = [];
    if (!empty($name) && !empty($campus_ids)) {
      foreach ($campus_ids as $campus_id) {
        $query = "
          SELECT f.faculty_id, f.faculty_name 
          FROM faculty_campus fc
          INNER JOIN faculties f ON fc.faculty_id = f.faculty_id
          WHERE fc.campus_id = ? AND f.faculty_name = ?
        ";
        $params = [$campus_id, $name];
        
        if ($exclude_id > 0) {
          $query .= " AND f.faculty_id != ?";
          $params[] = $exclude_id;
        }
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        if ($stmt->fetch()) {
          // Get campus name
          $campusStmt = $pdo->prepare("SELECT campus_name FROM campus WHERE campus_id = ?");
          $campusStmt->execute([$campus_id]);
          $campus_name = $campusStmt->fetchColumn();
          $conflicts[] = $campus_name;
        }
      }
    }
    
    echo json_encode(['conflicts' => $conflicts]);
    exit;
  }
  
  if ($_GET['ajax'] === 'check_faculty_code_in_campuses') {
    $code = $_GET['code'] ?? '';
    $campus_ids = isset($_GET['campuses']) ? explode(',', $_GET['campuses']) : [];
    $exclude_id = $_GET['exclude_id'] ?? 0;
    
    $conflicts = [];
    if (!empty($code) && !empty($campus_ids)) {
      foreach ($campus_ids as $campus_id) {
        $query = "
          SELECT f.faculty_id, f.faculty_code 
          FROM faculty_campus fc
          INNER JOIN faculties f ON fc.faculty_id = f.faculty_id
          WHERE fc.campus_id = ? AND f.faculty_code = ?
        ";
        $params = [$campus_id, $code];
        
        if ($exclude_id > 0) {
          $query .= " AND f.faculty_id != ?";
          $params[] = $exclude_id;
        }
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        if ($stmt->fetch()) {
          // Get campus name
          $campusStmt = $pdo->prepare("SELECT campus_name FROM campus WHERE campus_id = ?");
          $campusStmt->execute([$campus_id]);
          $campus_name = $campusStmt->fetchColumn();
          $conflicts[] = $campus_name;
        }
      }
    }
    
    echo json_encode(['conflicts' => $conflicts]);
    exit;
  }
  
  // ✅ NEW AJAX ENDPOINT: Get campus address
  if ($_GET['ajax'] === 'get_campus_address') {
    $campus_id = $_GET['campus_id'] ?? 0;
    
    if ($campus_id > 0) {
      $query = "SELECT address FROM campus WHERE campus_id = ?";
      $stmt = $pdo->prepare($query);
      $stmt->execute([$campus_id]);
      $address = $stmt->fetchColumn();
      
      echo json_encode(['address' => $address ?: '']);
      exit;
    }
    
    echo json_encode(['address' => '']);
    exit;
  }
  
  // Keep existing AJAX endpoints for backward compatibility
  if ($_GET['ajax'] === 'check_faculty_name') {
    $name = $_GET['name'] ?? '';
    $exclude_id = $_GET['exclude_id'] ?? 0;
    
    $query = "SELECT COUNT(*) FROM faculties WHERE faculty_name = ?";
    $params = [$name];
    
    if ($exclude_id > 0) {
      $query .= " AND faculty_id != ?";
      $params[] = $exclude_id;
    }
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $exists = $stmt->fetchColumn() > 0;
    
    echo json_encode(['exists' => $exists]);
    exit;
  }
  
  if ($_GET['ajax'] === 'check_faculty_code') {
    $code = $_GET['code'] ?? '';
    $exclude_id = $_GET['exclude_id'] ?? 0;
    
    $query = "SELECT COUNT(*) FROM faculties WHERE faculty_code = ?";
    $params = [$code];
    
    if ($exclude_id > 0) {
      $query .= " AND faculty_id != ?";
      $params[] = $exclude_id;
    }
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $exists = $stmt->fetchColumn() > 0;
    
    echo json_encode(['exists' => $exists]);
    exit;
  }
}

/* ===========================================
   CRUD OPERATIONS
=========================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // 🟢 ADD FACULTY - MODIFIED: Allow same name/code in different campuses
  if ($_POST['action'] === 'add') {
    try {
      $pdo->beginTransaction();

      $photo_path = null;

      if (!empty($_FILES['profile_photo']['name'])) {
        $upload_dir = __DIR__ . '/../upload/profiles/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        $ext = pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
        $new_name = uniqid('faculty_') . '.' . strtolower($ext);
        $photo_path = 'upload/profiles/' . $new_name;
        move_uploaded_file($_FILES['profile_photo']['tmp_name'], __DIR__ . '/../' . $photo_path);
      }

      // ✅ Faculty insert - NO GLOBAL DUPLICATE CHECKS
      $stmt = $pdo->prepare("INSERT INTO faculties 
        (faculty_name, faculty_code, dean_name, phone_number, email, office_address, profile_photo_path, status)
        VALUES (?,?,?,?,?,?,?, 'active')");
      $stmt->execute([
        $_POST['faculty_name'], $_POST['faculty_code'], $_POST['dean_name'],
        $_POST['phone_number'], $_POST['email'], $_POST['office_address'], $photo_path
      ]);

      $faculty_id = $pdo->lastInsertId();

      // ✅ Check for duplicate faculty name OR code in selected campuses
      if (!empty($_POST['campus_id'])) {
        $campusCheckStmt = $pdo->prepare("
          SELECT f.faculty_id, f.faculty_name, f.faculty_code
          FROM faculty_campus fc
          INNER JOIN faculties f ON fc.faculty_id = f.faculty_id
          WHERE fc.campus_id = ? 
          AND (f.faculty_name = ? OR f.faculty_code = ?)
        ");
        
        $conflictingCampuses = [];
        foreach ($_POST['campus_id'] as $campus_id) {
          // Check if same faculty name OR code already exists in this campus
          $campusCheckStmt->execute([$campus_id, $_POST['faculty_name'], $_POST['faculty_code']]);
          if ($conflictingFaculty = $campusCheckStmt->fetch(PDO::FETCH_ASSOC)) {
            // Get campus name
            $campusNameStmt = $pdo->prepare("SELECT campus_name FROM campus WHERE campus_id = ?");
            $campusNameStmt->execute([$campus_id]);
            $campus_name = $campusNameStmt->fetchColumn();
            
            $conflictType = '';
            if ($conflictingFaculty['faculty_name'] == $_POST['faculty_name']) {
              $conflictType = 'name';
            } else if ($conflictingFaculty['faculty_code'] == $_POST['faculty_code']) {
              $conflictType = 'code';
            }
            
            $conflictingCampuses[] = "'{$campus_name}' (conflict: {$conflictType})";
          }
        }
        
        if (!empty($conflictingCampuses)) {
          throw new Exception("Cannot assign to campuses: " . implode(', ', $conflictingCampuses) . ". Faculty name or code already exists in these campuses.");
        }
        
        // Insert into faculty_campus
        $campusInsertStmt = $pdo->prepare("INSERT INTO faculty_campus (faculty_id, campus_id) VALUES (?, ?)");
        foreach ($_POST['campus_id'] as $campus_id) {
          $campusInsertStmt->execute([$faculty_id, $campus_id]);
        }
      }

      $uuid = bin2hex(random_bytes(16));
      $plain = "123";
      $hashed = password_hash($plain, PASSWORD_BCRYPT);

      // Create user account linked to faculty
      $user = $pdo->prepare("INSERT INTO users 
        (user_uuid, username, email, phone_number, profile_photo_path, password, password_plain, role, linked_id, linked_table, status)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)");
      $user->execute([
        $uuid,
        $_POST['faculty_name'] . " (" . $_POST['faculty_code'] . ")",
        $_POST['email'],
        $_POST['phone_number'],
        $photo_path,
        $hashed,
        $plain,
        'faculty_admin',  // Faculty admin role
        $faculty_id,
        'faculty',
        'active'
      ]);

      $pdo->commit();
      $message = "✅ Faculty added successfully! Default password: 123";
      $type = "success";
    } catch (PDOException $e) {
      $pdo->rollBack();
      $message = "❌ Database Error: " . $e->getMessage();
      $type = "error";
    } catch (Exception $e) {
      $pdo->rollBack();
      $message = "❌ " . $e->getMessage();
      $type = "error";
    }
  }

  // 🟡 UPDATE FACULTY - MODIFIED: Allow same name/code in different campuses
  if ($_POST['action'] === 'update') {
    try {
      $pdo->beginTransaction();
      
      $id = $_POST['faculty_id'];
      
      // ✅ Get current faculty info
      $currentStmt = $pdo->prepare("SELECT faculty_name, faculty_code FROM faculties WHERE faculty_id = ?");
      $currentStmt->execute([$id]);
      $currentFaculty = $currentStmt->fetch(PDO::FETCH_ASSOC);
      
      // ✅ NO GLOBAL DUPLICATE CHECKS - Allow same name/code globally
      
      // Fetch existing photo path
      $stmt = $pdo->prepare("SELECT profile_photo_path FROM faculties WHERE faculty_id = ?");
      $stmt->execute([$id]);
      $existing_photo = $stmt->fetchColumn();
      $photo_path = $existing_photo;

      $setClauses = [
        "faculty_name = ?",
        "faculty_code = ?",
        "dean_name = ?",
        "phone_number = ?",
        "email = ?",
        "office_address = ?",
        "status = ?"
      ];

      $params = [
        $_POST['faculty_name'],
        $_POST['faculty_code'],
        $_POST['dean_name'],
        $_POST['phone_number'],
        $_POST['email'],
        $_POST['office_address'],
        $_POST['status']
      ];

      if (!empty($_FILES['profile_photo']['name'])) {
        $upload_dir = __DIR__ . '/../upload/profiles/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        $ext = pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
        $new_name = uniqid('faculty_') . '.' . strtolower($ext);
        $photo_path = 'upload/profiles/' . $new_name;
        move_uploaded_file($_FILES['profile_photo']['tmp_name'], __DIR__ . '/../' . $photo_path);
        
        $setClauses[] = "profile_photo_path = ?";
        $params[] = $photo_path;
      }

      $params[] = $id;

      // ✅ UPDATE QUERY
      $sql = "UPDATE faculties SET " . implode(', ', $setClauses) . " WHERE faculty_id = ?";
      $pdo->prepare($sql)->execute($params);

      // Update faculty_campus relationships
      if (!empty($_POST['campus_id'])) {
        // ✅ Check for duplicate faculty in selected campuses
        $campusCheckStmt = $pdo->prepare("
          SELECT f.faculty_id, f.faculty_name, f.faculty_code
          FROM faculty_campus fc
          INNER JOIN faculties f ON fc.faculty_id = f.faculty_id
          WHERE fc.campus_id = ? 
          AND (f.faculty_name = ? OR f.faculty_code = ?)
          AND f.faculty_id != ?
        ");
        
        $conflictingCampuses = [];
        foreach ($_POST['campus_id'] as $campus_id) {
          // Check if same faculty name OR code already exists in this campus (different faculty)
          $campusCheckStmt->execute([$campus_id, $_POST['faculty_name'], $_POST['faculty_code'], $id]);
          if ($conflictingFaculty = $campusCheckStmt->fetch(PDO::FETCH_ASSOC)) {
            // Get campus name
            $campusNameStmt = $pdo->prepare("SELECT campus_name FROM campus WHERE campus_id = ?");
            $campusNameStmt->execute([$campus_id]);
            $campus_name = $campusNameStmt->fetchColumn();
            
            $conflictType = '';
            if ($conflictingFaculty['faculty_name'] == $_POST['faculty_name']) {
              $conflictType = 'name';
            } else if ($conflictingFaculty['faculty_code'] == $_POST['faculty_code']) {
              $conflictType = 'code';
            }
            
            $conflictingCampuses[] = "'{$campus_name}' (conflict: {$conflictType} with faculty ID: {$conflictingFaculty['faculty_id']})";
          }
        }
        
        if (!empty($conflictingCampuses)) {
          throw new Exception("Cannot assign to campuses: " . implode(', ', $conflictingCampuses));
        }
        
        // Remove old campus relationships
        $pdo->prepare("DELETE FROM faculty_campus WHERE faculty_id = ?")->execute([$id]);
        
        // Insert new campus relationships
        $campusInsertStmt = $pdo->prepare("INSERT INTO faculty_campus (faculty_id, campus_id) VALUES (?, ?)");
        foreach ($_POST['campus_id'] as $campus_id) {
          $campusInsertStmt->execute([$id, $campus_id]);
        }
      } else {
        // Remove all campus relationships if no campuses selected
        $pdo->prepare("DELETE FROM faculty_campus WHERE faculty_id = ?")->execute([$id]);
      }

      // ✅ Sync user account
      $userStatus = ($_POST['status'] === 'inactive') ? 'inactive' : 'active';

      $updateUser = $pdo->prepare("UPDATE users 
        SET username=?, email=?, phone_number=?, profile_photo_path=?, status=? 
        WHERE linked_id=? AND linked_table='faculty'");
      $updateUser->execute([
        $_POST['faculty_name'] . " (" . $_POST['faculty_code'] . ")",
        $_POST['email'],
        $_POST['phone_number'],
        $photo_path ?? null,
        $userStatus,
        $id
      ]);

      $pdo->commit();
      $message = "✅ Faculty updated successfully!";
      $type = "success";
    } catch (PDOException $e) {
      $pdo->rollBack();
      $message = "❌ Database Error: " . $e->getMessage();
      $type = "error";
    } catch (Exception $e) {
      $pdo->rollBack();
      $message = "❌ " . $e->getMessage();
      $type = "error";
    }
  }

  // 🔴 DELETE FACULTY (unchanged)
  if ($_POST['action'] === 'delete') {
    try {
      $pdo->beginTransaction();
      
      $id = $_POST['faculty_id'];
      
      // Check if faculty has any departments
      $deptCheck = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE faculty_id = ?");
      $deptCheck->execute([$id]);
      if ($deptCheck->fetchColumn() > 0) {
        throw new Exception("Cannot delete faculty. It has departments assigned. Please reassign or delete departments first.");
      }
      
      // Delete from faculty_campus first
      $pdo->prepare("DELETE FROM faculty_campus WHERE faculty_id=?")->execute([$id]);
      
      // Then delete from users
      $pdo->prepare("DELETE FROM users WHERE linked_id=? AND linked_table='faculty'")->execute([$id]);
      
      // Finally delete from faculties
      $pdo->prepare("DELETE FROM faculties WHERE faculty_id=?")->execute([$id]);
      
      $pdo->commit();
      $message = "✅ Faculty deleted successfully!";
      $type = "success";
    } catch (PDOException $e) {
      $pdo->rollBack();
      $message = "❌ Database Error: " . $e->getMessage();
      $type = "error";
    } catch (Exception $e) {
      $pdo->rollBack();
      $message = "❌ " . $e->getMessage();
      $type = "error";
    }
  }
}

// ✅ GET FILTER PARAMETERS
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$campus_filter = $_GET['campus'] ?? '';

// ✅ Fetch campuses - MODIFIED: Only show campuses that this campus admin manages
if (!empty($logged_in_campus_ids)) {
    $campusPlaceholders = str_repeat('?,', count($logged_in_campus_ids) - 1) . '?';
    $campusesQuery = "SELECT campus_id, campus_name, campus_code, address, status FROM campus 
                     WHERE campus_id IN ($campusPlaceholders) 
                     ORDER BY campus_name ASC";
    $campusesStmt = $pdo->prepare($campusesQuery);
    $campusesStmt->execute($logged_in_campus_ids);
    $campuses = $campusesStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // If no campus assigned, show empty array
    $campuses = [];
}

// ✅ Build query with filters - SHOW ALL CAMPUSES FOR EACH FACULTY
$query = "
  SELECT 
    f.*,
    GROUP_CONCAT(DISTINCT c.campus_id ORDER BY c.campus_id) as campus_ids,
    GROUP_CONCAT(DISTINCT CONCAT(c.campus_name, ' (', c.campus_code, ')') ORDER BY c.campus_name SEPARATOR ' | ') as campus_names,
    GROUP_CONCAT(DISTINCT c.campus_name ORDER BY c.campus_name SEPARATOR ', ') as campus_names_simple,
    COUNT(DISTINCT c.campus_id) as total_campuses
  FROM faculties f 
  LEFT JOIN faculty_campus fc ON f.faculty_id = fc.faculty_id 
  LEFT JOIN campus c ON fc.campus_id = c.campus_id 
  WHERE 1=1
";

$params = [];

// MODIFIED: Only show faculties from campuses that this campus admin manages
if (!empty($logged_in_campus_ids)) {
    $query .= " AND (fc.campus_id IS NULL OR fc.campus_id IN (" . str_repeat('?,', count($logged_in_campus_ids) - 1) . "?" . "))";
    $params = array_merge($params, $logged_in_campus_ids);
}

// Search filter
if (!empty($search)) {
  $query .= " AND (f.faculty_name LIKE ? OR f.faculty_code LIKE ? OR f.dean_name LIKE ? OR f.email LIKE ? OR f.phone_number LIKE ?)";
  $searchTerm = "%$search%";
  $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}

// Status filter
if (!empty($status_filter)) {
  $query .= " AND f.status = ?";
  $params[] = $status_filter;
}

// Campus filter
if (!empty($campus_filter)) {
  $query .= " AND fc.campus_id = ?";
  $params[] = $campus_filter;
}

$query .= " GROUP BY f.faculty_id ORDER BY f.faculty_id DESC";

// ✅ Execute query
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$faculties = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ Get total counts for stats - MODIFIED: Only count faculties in campuses managed by this admin
if (!empty($logged_in_campus_ids)) {
    $totalFacultiesQuery = "
        SELECT COUNT(DISTINCT f.faculty_id) 
        FROM faculties f 
        JOIN faculty_campus fc ON f.faculty_id = fc.faculty_id 
        WHERE fc.campus_id IN (" . str_repeat('?,', count($logged_in_campus_ids) - 1) . "?" . ")
    ";
    $totalFacultiesStmt = $pdo->prepare($totalFacultiesQuery);
    $totalFacultiesStmt->execute($logged_in_campus_ids);
    $total_faculties = $totalFacultiesStmt->fetchColumn();
} else {
    $total_faculties = 0;
}

// ✅ Get active faculties count
if (!empty($logged_in_campus_ids)) {
    $activeFacultiesQuery = "
        SELECT COUNT(DISTINCT f.faculty_id) 
        FROM faculties f 
        JOIN faculty_campus fc ON f.faculty_id = fc.faculty_id 
        WHERE fc.campus_id IN (" . str_repeat('?,', count($logged_in_campus_ids) - 1) . "?" . ")
        AND f.status = 'active'
    ";
    $activeFacultiesStmt = $pdo->prepare($activeFacultiesQuery);
    $activeFacultiesStmt->execute($logged_in_campus_ids);
    $active_faculties = $activeFacultiesStmt->fetchColumn();
} else {
    $active_faculties = 0;
}

// ✅ Get inactive faculties count
if (!empty($logged_in_campus_ids)) {
    $inactiveFacultiesQuery = "
        SELECT COUNT(DISTINCT f.faculty_id) 
        FROM faculties f 
        JOIN faculty_campus fc ON f.faculty_id = fc.faculty_id 
        WHERE fc.campus_id IN (" . str_repeat('?,', count($logged_in_campus_ids) - 1) . "?" . ")
        AND f.status = 'inactive'
    ";
    $inactiveFacultiesStmt = $pdo->prepare($inactiveFacultiesQuery);
    $inactiveFacultiesStmt->execute($logged_in_campus_ids);
    $inactive_faculties = $inactiveFacultiesStmt->fetchColumn();
} else {
    $inactive_faculties = 0;
}

// ✅ Get faculties per campus count
if (!empty($logged_in_campus_ids)) {
    $facultiesPerCampusQuery = "
      SELECT c.campus_name, COUNT(DISTINCT f.faculty_id) as faculty_count
      FROM faculties f
      JOIN faculty_campus fc ON f.faculty_id = fc.faculty_id
      JOIN campus c ON fc.campus_id = c.campus_id
      WHERE f.status = 'active'
      AND c.campus_id IN (" . str_repeat('?,', count($logged_in_campus_ids) - 1) . "?" . ")
      GROUP BY c.campus_id
      ORDER BY c.campus_name
    ";
    $facultiesPerCampusStmt = $pdo->prepare($facultiesPerCampusQuery);
    $facultiesPerCampusStmt->execute($logged_in_campus_ids);
    $facultiesPerCampus = $facultiesPerCampusStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $facultiesPerCampus = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Faculty Management | University System</title>
<link rel="icon" type="image/png" href="../images.png">
<link rel="stylesheet" href="../assets/css/header.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
<style>
/* ==============================
   BASE STYLES
   ============================== */
:root {
  --primary-color: #00843D;
  --secondary-color: #0072CE;
  --light-color: #00A651;
  --dark-color: #333333;
  --light-gray: #F5F9F7;
  --danger-color: #C62828;
  --warning-color: #FFB400;
  --white: #FFFFFF;
}

* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
  font-family: 'Poppins', sans-serif;
}

body {
  background: var(--light-gray);
  color: var(--dark-color);
  min-height: 100vh;
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
  padding-bottom: 15px;
  border-bottom: 1px solid #e0e0e0;
}

.page-header h1 {
  color: var(--secondary-color);
  font-size: 24px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 10px;
}

.add-btn {
  background: var(--primary-color);
  color: var(--white);
  border: none;
  padding: 10px 20px;
  border-radius: 6px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.3s ease;
  display: flex;
  align-items: center;
  gap: 8px;
}

.add-btn:hover {
  background: var(--light-color);
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 132, 61, 0.2);
}

/* ==============================
   STATS CARDS
   ============================== */
.stats-container {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 20px;
  margin-bottom: 25px;
}

.stat-card {
  background: var(--white);
  border-radius: 10px;
  padding: 20px;
  display: flex;
  align-items: center;
  gap: 20px;
  box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
  transition: transform 0.3s ease;
  border-left: 4px solid;
}

.stat-card:hover {
  transform: translateY(-5px);
}

.stat-card.total { border-left-color: var(--secondary-color); }
.stat-card.active { border-left-color: var(--primary-color); }
.stat-card.inactive { border-left-color: var(--danger-color); }
.stat-card.info { border-left-color: #17a2b8; }

.stat-icon {
  width: 50px;
  height: 50px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  color: var(--white);
}

.stat-icon.total { background: var(--secondary-color); }
.stat-icon.active { background: var(--primary-color); }
.stat-icon.inactive { background: var(--danger-color); }
.stat-icon.info { background: #17a2b8; }

.stat-info h3 {
  font-size: 14px;
  color: #666;
  margin-bottom: 5px;
}

.stat-info .number {
  font-size: 24px;
  font-weight: 700;
  color: var(--dark-color);
}

.stat-info .subtext {
  font-size: 12px;
  color: #888;
  margin-top: 3px;
}

/* ==============================
   FILTERS
   ============================== */
.filters-container {
  background: var(--white);
  border-radius: 10px;
  padding: 25px;
  margin-bottom: 25px;
  box-shadow: 0 3px 15px rgba(0, 0, 0, 0.08);
}

.filter-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
}

.filter-header h3 {
  color: var(--secondary-color);
  font-size: 18px;
  display: flex;
  align-items: center;
  gap: 10px;
}

.filter-form {
  display: flex;
  gap: 15px;
  align-items: flex-end;
  flex-wrap: wrap;
}

.filter-group {
  flex: 1;
  min-width: 200px;
  position: relative;
}

.filter-group label {
  display: block;
  margin-bottom: 8px;
  font-weight: 500;
  color: var(--dark-color);
  font-size: 14px;
}

.filter-input {
  width: 100%;
  padding: 12px 15px 12px 45px;
  border: 1.5px solid #ddd;
  border-radius: 8px;
  font-size: 14px;
  transition: all 0.3s ease;
  background: #fafafa;
}

.filter-input:focus {
  outline: none;
  border-color: var(--secondary-color);
  box-shadow: 0 0 0 3px rgba(0, 114, 206, 0.1);
  background: var(--white);
}

.filter-icon {
  position: absolute;
  left: 15px;
  bottom: 12px;
  color: #666;
  font-size: 16px;
}

.filter-actions {
  display: flex;
  gap: 10px;
  align-items: flex-end;
  margin-bottom: 5px;
}

.filter-btn {
  padding: 12px 20px;
  border: none;
  border-radius: 8px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.3s ease;
  display: flex;
  align-items: center;
  gap: 8px;
  white-space: nowrap;
}

.apply-btn {
  background: var(--primary-color);
  color: var(--white);
}

.apply-btn:hover {
  background: var(--light-color);
  transform: translateY(-2px);
}

.clear-btn {
  background: #6c757d;
  color: var(--white);
}

.clear-btn:hover {
  background: #5a6268;
  transform: translateY(-2px);
}

/* ==============================
   CAMPUS STATS
   ============================== */
.campus-stats-container {
  background: var(--white);
  border-radius: 10px;
  padding: 25px;
  margin-bottom: 25px;
  box-shadow: 0 3px 15px rgba(0, 0, 0, 0.08);
}

.campus-stats-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
}

.campus-stats-header h3 {
  color: var(--secondary-color);
  font-size: 18px;
  display: flex;
  align-items: center;
  gap: 10px;
}

.campus-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 15px;
}

.campus-stat-card {
  background: #f8f9fa;
  border: 1px solid #e9ecef;
  border-radius: 8px;
  padding: 15px;
  transition: all 0.2s ease;
}

.campus-stat-card:hover {
  transform: translateY(-3px);
  box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
  border-color: var(--secondary-color);
}

.campus-stat-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 10px;
}

.campus-stat-name {
  font-weight: 600;
  color: var(--secondary-color);
  font-size: 14px;
}

.campus-stat-count {
  background: var(--primary-color);
  color: white;
  padding: 4px 10px;
  border-radius: 15px;
  font-size: 12px;
  font-weight: bold;
  min-width: 30px;
  text-align: center;
}

.campus-stat-info {
  font-size: 12px;
  color: #666;
  display: flex;
  align-items: center;
  gap: 5px;
}

/* ==============================
   TABLE STYLES
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
}

.table-header h3 {
  color: var(--dark-color);
  font-size: 16px;
}

.results-count {
  color: #666;
  font-size: 14px;
}

.table-container {
  overflow-x: auto;
}

.data-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 14px;
}

.data-table thead {
  background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
}

.data-table th {
  padding: 16px 20px;
  text-align: left;
  font-weight: 600;
  color: var(--white);
  white-space: nowrap;
  border-bottom: 2px solid rgba(255, 255, 255, 0.1);
}

.data-table td {
  padding: 14px 20px;
  border-bottom: 1px solid #eee;
  vertical-align: middle;
}

.data-table tbody tr {
  transition: background 0.2s ease;
}

.data-table tbody tr:hover {
  background: #f9f9f9;
}

/* Profile photo */
.profile-photo-container {
  width: 50px;
  height: 50px;
  position: relative;
}

.profile-photo {
  width: 100%;
  height: 100%;
  border-radius: 50%;
  object-fit: cover;
  border: 2px solid var(--primary-color);
  background: linear-gradient(135deg, #f5f5f5, #e0e0e0);
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--secondary-color);
  font-weight: bold;
  font-size: 16px;
  overflow: hidden;
}

.profile-initials {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
  color: white;
  font-weight: 600;
  font-size: 18px;
  border-radius: 50%;
}

/* Status badges */
.status-badge {
  padding: 4px 12px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 500;
  display: inline-block;
}

.status-active {
  background: #e8f5e8;
  color: var(--primary-color);
}

.status-inactive {
  background: #ffebee;
  color: var(--danger-color);
}

/* Action buttons */
.action-btns {
  display: flex;
  gap: 8px;
}

.action-btn {
  width: 35px;
  height: 35px;
  border-radius: 6px;
  border: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s ease;
  font-size: 14px;
}

.view-btn {
  background: #6c757d;
  color: var(--white);
}

.view-btn:hover {
  background: #5a6268;
  transform: translateY(-2px);
}

.edit-btn {
  background: var(--secondary-color);
  color: var(--white);
}

.edit-btn:hover {
  background: #005fa3;
  transform: translateY(-2px);
}

.del-btn {
  background: var(--danger-color);
  color: var(--white);
}

.del-btn:hover {
  background: #b71c1c;
  transform: translateY(-2px);
}

/* Campus badges */
.campus-badges-container {
  max-width: 250px;
}

.campus-badges {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
  margin-bottom: 5px;
}

.campus-badge {
  background: #e3f2fd;
  color: var(--secondary-color);
  padding: 4px 10px;
  border-radius: 12px;
  font-size: 11px;
  font-weight: 500;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  border: 1px solid #bbdefb;
}

.campus-badge-code {
  background: var(--secondary-color);
  color: white;
  font-size: 9px;
  padding: 1px 5px;
  border-radius: 8px;
  font-weight: bold;
}

.campus-count-badge {
  background: var(--primary-color);
  color: white;
  padding: 2px 8px;
  border-radius: 10px;
  font-size: 10px;
  font-weight: bold;
  margin-left: 5px;
  cursor: help;
}

/* ==============================
   MODAL STYLES
   ============================== */
.modal {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  justify-content: center;
  align-items: center;
  z-index: 1000;
  padding: 20px;
  backdrop-filter: blur(5px);
}

.modal.show {
  display: flex;
}

.modal-content {
  background: var(--white);
  border-radius: 12px;
  width: 100%;
  max-width: 900px;
  max-height: 90vh;
  overflow-y: auto;
  padding: 30px;
  position: relative;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
  animation: modalSlideIn 0.3s ease;
}

@keyframes modalSlideIn {
  from {
    opacity: 0;
    transform: translateY(-20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.close-modal {
  position: absolute;
  top: 15px;
  right: 20px;
  font-size: 24px;
  color: #666;
  cursor: pointer;
  width: 30px;
  height: 30px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  transition: background 0.2s;
}

.close-modal:hover {
  background: #f5f5f5;
}

/* View Modal */
.view-content {
  display: grid;
  grid-template-columns: 200px 1fr;
  gap: 30px;
  align-items: start;
}

.view-photo-container {
  text-align: center;
}

.view-profile-photo {
  width: 180px;
  height: 180px;
  border-radius: 50%;
  object-fit: cover;
  border: 5px solid var(--primary-color);
  margin-bottom: 15px;
  background: linear-gradient(135deg, #f5f5f5, #e0e0e0);
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--secondary-color);
  font-size: 60px;
  font-weight: bold;
  overflow: hidden;
}

.view-details {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 20px;
}

.detail-item {
  margin-bottom: 15px;
}

.detail-label {
  font-weight: 600;
  color: var(--secondary-color);
  font-size: 14px;
  margin-bottom: 5px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.detail-value {
  color: var(--dark-color);
  padding: 10px 15px;
  background: #f8f9fa;
  border-radius: 8px;
  border-left: 4px solid var(--primary-color);
  font-size: 15px;
  min-height: 45px;
  display: flex;
  align-items: center;
}

/* Campus Section in View Modal */
.campus-list-section {
  grid-column: 1 / -1;
  margin-top: 20px;
  padding-top: 20px;
  border-top: 2px solid #eee;
}

.campus-list-section h4 {
  color: var(--secondary-color);
  margin-bottom: 15px;
  font-size: 16px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.campus-list-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 15px;
  margin-top: 15px;
}

.campus-card {
  background: #f8f9fa;
  border: 1px solid #e9ecef;
  border-radius: 10px;
  padding: 15px;
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.campus-card:hover {
  transform: translateY(-3px);
  box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
}

.campus-card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 10px;
}

.campus-card-name {
  font-weight: 600;
  color: var(--secondary-color);
  font-size: 15px;
}

.campus-card-code {
  background: var(--primary-color);
  color: white;
  padding: 2px 8px;
  border-radius: 12px;
  font-size: 11px;
  font-weight: bold;
}

.campus-card-location {
  display: flex;
  align-items: center;
  gap: 5px;
  color: #666;
  font-size: 12px;
  margin-top: 5px;
}

/* Form Styles */
.form-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
  gap: 20px;
}

.form-group {
  margin-bottom: 20px;
}

.form-group label {
  display: block;
  margin-bottom: 8px;
  font-weight: 500;
  color: var(--dark-color);
  font-size: 14px;
}

.form-control {
  width: 100%;
  padding: 10px 15px;
  border: 1.5px solid #ddd;
  border-radius: 6px;
  font-size: 14px;
  transition: border 0.3s ease;
}

.form-control:focus {
  outline: none;
  border-color: var(--secondary-color);
  box-shadow: 0 0 0 3px rgba(0, 114, 206, 0.1);
}

/* Validation messages */
.validation-message {
  font-size: 12px;
  color: var(--danger-color);
  margin-top: 5px;
  display: none;
}

/* Campus Selection */
.campus-selection {
  grid-column: 1 / -1;
  margin: 20px 0;
}

.campus-checkboxes-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 15px;
}

.campus-checkboxes {
  border: 1.5px solid #ddd;
  border-radius: 6px;
  padding: 20px;
  background: #fafafa;
  max-height: 250px;
  overflow-y: auto;
}

.checkbox-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 15px;
}

.checkbox-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 12px;
  background: white;
  border: 1px solid #e9ecef;
  border-radius: 6px;
  transition: all 0.2s ease;
}

.checkbox-item:hover {
  background: #f8f9fa;
  border-color: var(--secondary-color);
}

.checkbox-item input[type="checkbox"] {
  width: 18px;
  height: 18px;
  accent-color: var(--primary-color);
}

.checkbox-item label {
  margin: 0;
  font-weight: normal;
  font-size: 14px;
  flex: 1;
  cursor: pointer;
}

.campus-info {
  font-size: 12px;
  color: #666;
}

.campus-inactive {
  opacity: 0.6;
  background: #f5f5f5;
}

.campus-inactive label {
  color: #777;
}

/* Submit button */
.submit-btn {
  grid-column: 1 / -1;
  background: var(--primary-color);
  color: var(--white);
  border: none;
  padding: 12px 25px;
  border-radius: 6px;
  font-weight: 600;
  font-size: 15px;
  cursor: pointer;
  transition: all 0.3s ease;
  margin-top: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}

.submit-btn:hover {
  background: var(--light-color);
  transform: translateY(-2px);
  box-shadow: 0 4px 15px rgba(0, 132, 61, 0.3);
}

.delete-btn {
  background: var(--danger-color);
}

.delete-btn:hover {
  background: #b71c1c;
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
  border-radius: 10px;
  padding: 25px 30px;
  text-align: center;
  z-index: 1100;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
  min-width: 300px;
  animation: alertSlideIn 0.3s ease;
}

@keyframes alertSlideIn {
  from {
    opacity: 0;
    transform: translate(-50%, -40px);
  }
  to {
    opacity: 1;
    transform: translate(-50%, -50%);
  }
}

.alert-popup.show {
  display: block;
}

.alert-popup.success {
  border-top: 4px solid var(--primary-color);
}

.alert-popup.error {
  border-top: 4px solid var(--danger-color);
}

.alert-icon {
  font-size: 28px;
  margin-bottom: 15px;
  display: block;
}

.alert-message {
  color: var(--dark-color);
  font-size: 15px;
  font-weight: 500;
}

/* ==============================
   EMPTY STATE
   ============================== */
.empty-state {
  text-align: center;
  padding: 60px 20px;
  color: #666;
}

.empty-state i {
  font-size: 48px;
  margin-bottom: 20px;
  color: #ccc;
  display: block;
}

.empty-state h3 {
  font-size: 18px;
  margin-bottom: 10px;
}

/* ==============================
   RESPONSIVE DESIGN
   ============================== */
@media (max-width: 1024px) {
  .main-content {
    margin-left: 240px;
  }
  
  body.sidebar-collapsed .main-content {
    margin-left: 60px;
  }
  
  .view-content {
    grid-template-columns: 1fr;
    gap: 20px;
  }
  
  .view-photo-container {
    text-align: center;
  }
  
  .view-profile-photo {
    width: 150px;
    height: 150px;
    margin: 0 auto 20px;
  }
}

@media (max-width: 768px) {
  .main-content {
    margin-left: 0 !important;
    padding: 20px 15px;
  }
  
  .page-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 15px;
  }
  
  .add-btn {
    align-self: flex-start;
  }
  
  .filter-form {
    flex-direction: column;
    align-items: stretch;
  }
  
  .filter-group {
    min-width: 100%;
  }
  
  .view-details {
    grid-template-columns: 1fr;
  }
  
  .campus-grid, .campus-list-grid {
    grid-template-columns: 1fr;
  }
  
  .stat-card {
    padding: 15px;
  }
  
  .action-btns {
    flex-wrap: wrap;
  }
  
  .stats-container {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 480px) {
  .stats-container {
    grid-template-columns: 1fr;
  }
  
  .stat-card {
    padding: 12px;
  }
  
  .stat-icon {
    width: 40px;
    height: 40px;
    font-size: 18px;
  }
  
  .modal-content {
    padding: 20px 15px;
  }
}

/* Auto Address Button */
.auto-address-btn {
  background: var(--secondary-color);
  color: white;
  border: none;
  padding: 8px 15px;
  border-radius: 6px;
  font-size: 13px;
  cursor: pointer;
  transition: all 0.2s ease;
  display: flex;
  align-items: center;
  gap: 5px;
  margin-top: 5px;
}

.auto-address-btn:hover {
  background: #005fa3;
  transform: translateY(-1px);
}

.address-preview {
  margin-top: 10px;
  padding: 10px;
  background: #f8f9fa;
  border-radius: 6px;
  border-left: 3px solid var(--primary-color);
  font-size: 13px;
  display: none;
}

.address-preview strong {
  color: var(--secondary-color);
  display: block;
  margin-bottom: 5px;
}
</style>
</head>
<body>

<?php require_once('../includes/header.php'); ?>

<div class="main-content">
  <div class="page-header">
    <h1><i class="fas fa-university"></i> Faculty Management</h1>
    <?php if(!empty($campuses)): ?>
    <button class="add-btn" onclick="openModal('addModal')">
      <i class="fa-solid fa-plus"></i> Add Faculty
    </button>
    <?php endif; ?>
  </div>

  <!-- ✅ STATS CARDS -->
  <div class="stats-container">
    <div class="stat-card total">
      <div class="stat-icon total">
        <i class="fas fa-university"></i>
      </div>
      <div class="stat-info">
        <h3>Total Faculties</h3>
        <div class="number"><?= $total_faculties ?></div>
        <div class="subtext">Across your campuses</div>
      </div>
    </div>
    
    <div class="stat-card active">
      <div class="stat-icon active">
        <i class="fas fa-check-circle"></i>
      </div>
      <div class="stat-info">
        <h3>Active Faculties</h3>
        <div class="number"><?= $active_faculties ?></div>
        <div class="subtext">Currently operational</div>
      </div>
    </div>
    
    <div class="stat-card inactive">
      <div class="stat-icon inactive">
        <i class="fas fa-times-circle"></i>
      </div>
      <div class="stat-info">
        <h3>Inactive Faculties</h3>
        <div class="number"><?= $inactive_faculties ?></div>
        <div class="subtext">Currently disabled</div>
      </div>
    </div>
    
    <div class="stat-card info">
      <div class="stat-icon info">
        <i class="fas fa-map-marker-alt"></i>
      </div>
      <div class="stat-info">
        <h3>Campuses</h3>
        <div class="number"><?= count($campuses) ?></div>
        <div class="subtext">Under your management</div>
      </div>
    </div>
  </div>

  <?php if(!empty($facultiesPerCampus)): ?>
  <!-- ✅ CAMPUS STATS -->
  <div class="campus-stats-container">
    <div class="campus-stats-header">
      <h3><i class="fas fa-chart-bar"></i> Faculties by Campus</h3>
    </div>
    <div class="campus-grid">
      <?php foreach($facultiesPerCampus as $campusStat): ?>
        <div class="campus-stat-card">
          <div class="campus-stat-header">
            <div class="campus-stat-name"><?= htmlspecialchars($campusStat['campus_name']) ?></div>
            <div class="campus-stat-count"><?= $campusStat['faculty_count'] ?></div>
          </div>
          <div class="campus-stat-info">
            <i class="fas fa-info-circle"></i>
            <?= $campusStat['faculty_count'] == 1 ? '1 faculty' : $campusStat['faculty_count'] . ' faculties' ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ✅ FILTERS -->
  <div class="filters-container">
    <div class="filter-header">
      <h3><i class="fas fa-filter"></i> Search & Filter Faculties</h3>
    </div>
    
    <form method="GET" class="filter-form" id="filterForm">
      <div class="filter-group">
        <label for="search">Search Faculties</label>
        <div style="position:relative;">
          <i class="fas fa-search filter-icon"></i>
          <input type="text" 
                 id="search" 
                 name="search" 
                 class="filter-input" 
                 placeholder="Faculty name, code, dean..."
                 value="<?= htmlspecialchars($search) ?>">
        </div>
      </div>
      
      <div class="filter-group">
        <label for="status">Status</label>
        <div style="position:relative;">
          <i class="fas fa-circle filter-icon"></i>
          <select id="status" name="status" class="filter-input">
            <option value="">All Status</option>
            <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
          </select>
        </div>
      </div>
      
      <?php if(!empty($campuses)): ?>
      <div class="filter-group">
        <label for="campus">Campus</label>
        <div style="position:relative;">
          <i class="fas fa-map-marker-alt filter-icon"></i>
          <select id="campus" name="campus" class="filter-input">
            <option value="">All Campuses</option>
            <?php foreach($campuses as $campus): ?>
              <option value="<?= $campus['campus_id'] ?>" 
                <?= $campus_filter == $campus['campus_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($campus['campus_name']) ?> (<?= htmlspecialchars($campus['campus_code']) ?>)
                <?= $campus['status'] === 'inactive' ? ' - Inactive' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <?php endif; ?>
      
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

  <!-- ✅ MAIN TABLE -->
  <div class="table-wrapper">
    <div class="table-header">
      <h3>Faculty List</h3>
      <div class="results-count">
        Showing <?= count($faculties) ?> of <?= $total_faculties ?> faculties
        <?php if(!empty($search) || !empty($status_filter) || !empty($campus_filter)): ?>
          (filtered)
        <?php endif; ?>
      </div>
    </div>
    
    <div class="table-container">
      <table class="data-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Photo</th>
            <th>Faculty</th>
            <th>Code</th>
            <th>Dean</th>
            <th>Campuses</th>
            <th>Phone</th>
            <th>Email</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if($faculties): ?>
            <?php foreach($faculties as $i=>$f): 
              // ✅ Generate initials for profile photo
              $initials = '';
              $faculty_name = htmlspecialchars($f['faculty_name']);
              $name_parts = explode(' ', $faculty_name);
              if(count($name_parts) >= 2) {
                $initials = strtoupper(substr($name_parts[0], 0, 1) . substr($name_parts[1], 0, 1));
              } else {
                $initials = strtoupper(substr($faculty_name, 0, 2));
              }
              if(strlen($initials) === 1) {
                $initials = $initials . strtoupper(substr($faculty_name, 1, 1));
              }
              
              // ✅ Parse campus names with codes
              $campus_display = [];
              if(!empty($f['campus_names'])) {
                $campus_items = explode(' | ', $f['campus_names']);
                foreach($campus_items as $item) {
                  $campus_display[] = $item;
                }
              }
            ?>
            <tr>
              <td><?= $i+1 ?></td>
              <td>
                <div class="profile-photo-container">
                  <?php if(!empty($f['profile_photo_path'])): ?>
                    <div class="profile-photo">
                      <img src="../<?= $f['profile_photo_path'] ?>" 
                           alt="<?= $faculty_name ?>"
                           onerror="this.parentElement.innerHTML='<div class=\"profile-initials\"><?= $initials ?></div>';">
                    </div>
                  <?php else: ?>
                    <div class="profile-initials"><?= $initials ?></div>
                  <?php endif; ?>
                </div>
              </td>
              <td><strong><?= $faculty_name ?></strong></td>
              <td><code><?= htmlspecialchars($f['faculty_code']) ?></code></td>
              <td><?= htmlspecialchars($f['dean_name']) ?></td>
              <td class="campus-badges-container">
                <?php if(!empty($campus_display)): ?>
                  <div class="campus-badges">
                    <?php foreach(array_slice($campus_display, 0, 2) as $campus_item): ?>
                      <?php
                        preg_match('/(.+?) \(([^)]+)\)/', $campus_item, $matches);
                        $campus_name = $matches[1] ?? $campus_item;
                        $campus_code = $matches[2] ?? '';
                      ?>
                      <div class="campus-badge">
                        <i class="fas fa-map-marker-alt"></i>
                        <?= htmlspecialchars($campus_name) ?>
                        <?php if($campus_code): ?>
                          <div class="campus-badge-code"><?= htmlspecialchars($campus_code) ?></div>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                  <?php if(count($campus_display) > 2): ?>
                    <span class="campus-count-badge" 
                          data-tooltip="<?= htmlspecialchars(implode(' | ', $campus_display)) ?>">
                      +<?= count($campus_display) - 2 ?> more
                    </span>
                  <?php endif; ?>
                  <div style="font-size:11px; color:#666; margin-top:3px;">
                    <i class="fas fa-info-circle"></i> <?= $f['total_campuses'] ?> campus(es)
                  </div>
                <?php else: ?>
                  <span style="color: #999; font-style: italic;">No campus assigned</span>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($f['phone_number']) ?></td>
              <td><?= htmlspecialchars($f['email']) ?></td>
              <td>
                <span class="status-badge status-<?= $f['status'] ?>">
                  <?= ucfirst($f['status']) ?>
                </span>
              </td>
              <td>
                <div class="action-btns">
                  <button class="action-btn view-btn" 
                          onclick="openViewModal(
                            <?= $f['faculty_id'] ?>,
                            '<?= addslashes($f['faculty_name']) ?>',
                            '<?= addslashes($f['faculty_code']) ?>',
                            '<?= addslashes($f['dean_name']) ?>',
                            '<?= addslashes($f['campus_names'] ?? '') ?>',
                            '<?= addslashes($f['phone_number']) ?>',
                            '<?= addslashes($f['email']) ?>',
                            '<?= addslashes($f['office_address']) ?>',
                            '<?= $f['status'] ?>',
                            '<?= $f['profile_photo_path'] ?? '' ?>',
                            '<?= $initials ?>',
                            <?= $f['total_campuses'] ?? 0 ?>
                          )"
                          title="View Details">
                    <i class="fa-solid fa-eye"></i>
                  </button>
                  <button class="action-btn edit-btn" 
                          onclick="openEditModal(<?= $f['faculty_id'] ?>,
                            '<?= addslashes($f['faculty_name']) ?>',
                            '<?= addslashes($f['faculty_code']) ?>',
                            '<?= addslashes($f['dean_name']) ?>',
                            '<?= $f['campus_ids'] ?? '' ?>',
                            '<?= addslashes($f['phone_number']) ?>',
                            '<?= addslashes($f['email']) ?>',
                            '<?= addslashes($f['office_address']) ?>',
                            '<?= $f['status'] ?>')"
                          title="Edit">
                    <i class="fa-solid fa-pen"></i>
                  </button>
                  <button class="action-btn del-btn" 
                          onclick="openDeleteModal(<?= $f['faculty_id'] ?>)" 
                          title="Delete">
                    <i class="fa-solid fa-trash"></i>
                  </button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="10">
                <div class="empty-state">
                  <?php if(empty($campuses)): ?>
                    <i class="fa-solid fa-exclamation-triangle"></i>
                    <h3>No Campus Assigned</h3>
                    <p>You are not assigned to any campus. Please contact super admin.</p>
                  <?php else: ?>
                    <i class="fa-solid fa-inbox"></i>
                    <h3>No faculties found</h3>
                    <p>
                      <?php if(!empty($search) || !empty($status_filter) || !empty($campus_filter)): ?>
                        Try adjusting your filters or <a href="javascript:void(0)" onclick="clearFilters()">clear all filters</a>
                      <?php else: ?>
                        Add your first faculty using the button above
                      <?php endif; ?>
                    </p>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ✅ VIEW MODAL -->
<div class="modal" id="viewModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('viewModal')">&times;</span>
    <h2><i class="fas fa-eye"></i> Faculty Details</h2>
    
    <div class="view-content">
      <div class="view-photo-container">
        <div class="view-profile-photo" id="view_profile_photo"></div>
      </div>
      
      <div class="view-details">
        <div class="detail-item">
          <div class="detail-label"><i class="fas fa-university"></i> Faculty Name</div>
          <div class="detail-value" id="view_name"></div>
        </div>
        
        <div class="detail-item">
          <div class="detail-label"><i class="fas fa-code"></i> Faculty Code</div>
          <div class="detail-value" id="view_code"></div>
        </div>
        
        <div class="detail-item">
          <div class="detail-label"><i class="fas fa-user-tie"></i> Dean Name</div>
          <div class="detail-value" id="view_dean"></div>
        </div>
        
        <div class="detail-item">
          <div class="detail-label"><i class="fas fa-phone"></i> Phone Number</div>
          <div class="detail-value" id="view_phone"></div>
        </div>
        
        <div class="detail-item">
          <div class="detail-label"><i class="fas fa-envelope"></i> Email Address</div>
          <div class="detail-value" id="view_email"></div>
        </div>
        
        <div class="detail-item">
          <div class="detail-label"><i class="fas fa-map-marker-alt"></i> Office Address</div>
          <div class="detail-value" id="view_office"></div>
        </div>
        
        <div class="detail-item">
          <div class="detail-label"><i class="fas fa-circle"></i> Status</div>
          <div class="detail-value" id="view_status"></div>
        </div>
      </div>
      
      <!-- Campus Section -->
      <div class="campus-list-section">
        <h4><i class="fas fa-map-marker-alt"></i> Assigned Campuses (<span id="view_campus_count">0</span>)</h4>
        <div class="campus-list-grid" id="view_campuses_grid">
          <!-- Campuses will be inserted by JavaScript -->
        </div>
      </div>
    </div>
    
    <div class="view-actions" style="grid-column: 1 / -1; display: flex; justify-content: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
      <button class="add-btn" onclick="closeModal('viewModal')">
        <i class="fas fa-times"></i> Close
      </button>
    </div>
  </div>
</div>

<!-- ADD MODAL -->
<?php if(!empty($campuses)): ?>
<div class="modal" id="addModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('addModal')">&times;</span>
    <h2><i class="fas fa-plus-circle"></i> Add New Faculty</h2>
    <form method="POST" enctype="multipart/form-data" id="addForm" onsubmit="return validateAddForm()">
      <input type="hidden" name="action" value="add">
      
      <div class="form-grid">
        <div class="form-group">
          <label for="faculty_name">Faculty Name *</label>
          <input type="text" id="faculty_name" name="faculty_name" class="form-control" required
                 onblur="checkFacultyName()">
          <div class="validation-message" id="faculty_name_error"></div>
          <small style="color: #666;">Same name can be used in different campuses</small>
        </div>
        
        <div class="form-group">
          <label for="faculty_code">Faculty Code *</label>
          <input type="text" id="faculty_code" name="faculty_code" class="form-control" required
                 onblur="checkFacultyCode()">
          <div class="validation-message" id="faculty_code_error"></div>
          <small style="color: #666;">Same code can be used in different campuses</small>
        </div>
        
        <div class="form-group">
          <label for="dean_name">Dean Name</label>
          <input type="text" id="dean_name" name="dean_name" class="form-control">
        </div>
        
        <div class="form-group">
          <label for="phone_number">Phone Number</label>
          <input type="tel" id="phone_number" name="phone_number" class="form-control">
        </div>
        
        <div class="form-group">
          <label for="email">Email Address</label>
          <input type="email" id="email" name="email" class="form-control">
        </div>
        
        <div class="form-group">
          <label for="office_address">Office Address</label>
          <textarea id="office_address" name="office_address" class="form-control" rows="3"></textarea>
          <button type="button" class="auto-address-btn" onclick="applyAutoAddress()">
            <i class="fas fa-magic"></i> Auto-Fill from Selected Campus
          </button>
          
        </div>
        
        <div class="form-group">
          <label for="profile_photo">Profile Photo</label>
          <input type="file" id="profile_photo" name="profile_photo" class="form-control" accept="image/*">
          <small style="color: #666;">Optional: JPG, PNG or GIF</small>
        </div>
      </div>
      
      <div class="campus-selection">
        <div class="campus-checkboxes-header">
          <label style="font-weight: 600; color: var(--secondary-color); font-size: 16px;">
            <i class="fas fa-map-marker-alt"></i> Select Campuses *
          </label>
          <span style="color: #666; font-size: 14px;" id="selectedCampusesCount">0 selected</span>
          <div class="validation-message" id="campus_error" style="display: none;"></div>
        </div>
        <div class="campus-checkboxes">
          <div class="checkbox-grid" id="campusCheckboxes">
            <?php foreach($campuses as $c): ?>
              <div class="checkbox-item <?= $c['status'] === 'inactive' ? 'campus-inactive' : '' ?>">
                <input type="checkbox" name="campus_id[]" 
                       value="<?= $c['campus_id'] ?>" 
                       id="campus_<?= $c['campus_id'] ?>"
                       class="campus-checkbox"
                       data-campus-name="<?= htmlspecialchars($c['campus_name']) ?>"
                       data-campus-address="<?= htmlspecialchars($c['address'] ?? '') ?>"
                       <?= $c['status'] === 'inactive' ? 'disabled' : '' ?>
                       onchange="updateSelectedCount()">
                <label for="campus_<?= $c['campus_id'] ?>" style="cursor: pointer;">
                  <div><strong><?= htmlspecialchars($c['campus_name']) ?></strong></div>
                  <div class="campus-info">
                    Code: <?= htmlspecialchars($c['campus_code']) ?>
                    <?php if(!empty($c['address'])): ?>
                      | Address: <?= htmlspecialchars(substr($c['address'], 0, 50)) . (strlen($c['address']) > 50 ? '...' : '') ?>
                    <?php endif; ?>
                    <?php if($c['status'] === 'inactive'): ?>
                      <span style="color: var(--danger-color);"> (Inactive)</span>
                    <?php endif; ?>
                  </div>
                </label>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <small style="color: #666; display: block; margin-top: 10px;">
          <i class="fas fa-info-circle"></i> Note: Faculty name/code must be unique within each selected campus, but can be reused across different campuses.
        </small>
      </div>
      
      <button class="submit-btn" type="submit">
        <i class="fas fa-save"></i> Save Faculty
      </button>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- EDIT MODAL -->
<div class="modal" id="editModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('editModal')">&times;</span>
    <h2><i class="fas fa-edit"></i> Edit Faculty</h2>
    <form method="POST" enctype="multipart/form-data" id="editForm" onsubmit="return validateEditForm()">
      <input type="hidden" name="action" value="update">
      <input type="hidden" id="edit_id" name="faculty_id">
      
      <div class="form-grid">
        <div class="form-group">
          <label for="edit_name">Faculty Name *</label>
          <input type="text" id="edit_name" name="faculty_name" class="form-control" required
                 onblur="checkEditFacultyName()">
          <div class="validation-message" id="edit_name_error"></div>
          <small style="color: #666;">Same name can be used in different campuses</small>
        </div>
        
        <div class="form-group">
          <label for="edit_code">Faculty Code *</label>
          <input type="text" id="edit_code" name="faculty_code" class="form-control" required
                 onblur="checkEditFacultyCode()">
          <div class="validation-message" id="edit_code_error"></div>
          <small style="color: #666;">Same code can be used in different campuses</small>
        </div>
        
        <div class="form-group">
          <label for="edit_dean">Dean Name</label>
          <input type="text" id="edit_dean" name="dean_name" class="form-control">
        </div>
        
        <div class="form-group">
          <label for="edit_phone">Phone Number</label>
          <input type="tel" id="edit_phone" name="phone_number" class="form-control">
        </div>
        
        <div class="form-group">
          <label for="edit_email">Email Address</label>
          <input type="email" id="edit_email" name="email" class="form-control">
        </div>
        
        <div class="form-group">
          <label for="edit_office">Office Address</label>
          <textarea id="edit_office" name="office_address" class="form-control" rows="3"></textarea>
          <button type="button" class="auto-address-btn" onclick="applyAutoAddressEdit()">
            <i class="fas fa-magic"></i> Auto-Fill from Selected Campus
          </button>
          <!-- <div id="editAddressPreview" class="address-preview">
            <strong>Address will be filled from selected campus</strong>
            <div id="editAddressPreviewText"></div>
          </div> -->
        </div>
        
        <div class="form-group">
          <label for="edit_status">Status</label>
          <select id="edit_status" name="status" class="form-control">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
        
        <div class="form-group">
          <label for="edit_photo">New Photo</label>
          <input type="file" id="edit_photo" name="profile_photo" class="form-control" accept="image/*">
          <small style="color: #666;">Leave empty to keep current</small>
        </div>
      </div>
      
      <div class="campus-selection">
        <div class="campus-checkboxes-header">
          <label style="font-weight: 600; color: var(--secondary-color); font-size: 16px;">
            <i class="fas fa-map-marker-alt"></i> Select Campuses *
          </label>
          <span style="color: #666; font-size: 14px;" id="editSelectedCampusesCount">0 selected</span>
          <div class="validation-message" id="edit_campus_error" style="display: none;"></div>
        </div>
        <div class="campus-checkboxes">
          <div class="checkbox-grid" id="edit_campus_checkboxes">
            <?php foreach($campuses as $c): ?>
              <div class="checkbox-item <?= $c['status'] === 'inactive' ? 'campus-inactive' : '' ?>">
                <input type="checkbox" name="campus_id[]" 
                       value="<?= $c['campus_id'] ?>" 
                       id="edit_campus_<?= $c['campus_id'] ?>"
                       class="campus-checkbox"
                       data-campus-name="<?= htmlspecialchars($c['campus_name']) ?>"
                       data-campus-address="<?= htmlspecialchars($c['address'] ?? '') ?>"
                       <?= $c['status'] === 'inactive' ? 'disabled' : '' ?>
                       onchange="updateEditSelectedCount()">
                <label for="edit_campus_<?= $c['campus_id'] ?>" style="cursor: pointer;">
                  <div><strong><?= htmlspecialchars($c['campus_name']) ?></strong></div>
                  <div class="campus-info">
                    Code: <?= htmlspecialchars($c['campus_code']) ?>
                    <?php if(!empty($c['address'])): ?>
                      | Address: <?= htmlspecialchars(substr($c['address'], 0, 50)) . (strlen($c['address']) > 50 ? '...' : '') ?>
                    <?php endif; ?>
                    <?php if($c['status'] === 'inactive'): ?>
                      <span style="color: var(--danger-color);"> (Inactive)</span>
                    <?php endif; ?>
                  </div>
                </label>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <small style="color: #666; display: block; margin-top: 10px;">
          <i class="fas fa-info-circle"></i> Note: Faculty name/code must be unique within each selected campus, but can be reused across different campuses.
        </small>
      </div>
      
      <button class="submit-btn" type="submit">
        <i class="fas fa-sync-alt"></i> Update Faculty
      </button>
    </form>
  </div>
</div>

<!-- DELETE MODAL -->
<div class="modal" id="deleteModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('deleteModal')">&times;</span>
    <h2 style="color: var(--danger-color);"><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h2>
    <form method="POST">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="faculty_id" id="delete_id">
      
      <div style="text-align: center; margin: 30px 0;">
        <i class="fa-solid fa-exclamation-triangle" style="font-size: 48px; color: var(--danger-color); margin-bottom: 20px;"></i>
        <p style="font-size: 16px; color: var(--dark-color); margin-bottom: 10px;">
          Are you sure you want to delete this faculty?
        </p>
        <p style="color: #666; font-size: 14px;">
          This action will delete all campus relationships and cannot be undone.
        </p>
      </div>
      
      <button class="submit-btn delete-btn" type="submit">
        <i class="fas fa-trash-alt"></i> Yes, Delete Faculty
      </button>
    </form>
  </div>
</div>

<!-- ALERT POPUP -->
<div id="popup" class="alert-popup <?= $type ?> <?= !empty($message)?'show':'' ?>">
  <span class="alert-icon"><?= $type==='success' ? '✓' : '✗' ?></span>
  <div class="alert-message"><?= $message ?></div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
<script>
// Initialize Select2
$(document).ready(function() {
  $('#campus').select2({
    placeholder: "Select campus",
    allowClear: true,
    width: '100%'
  });
  
  $('#status').select2({
    minimumResultsForSearch: -1,
    width: '100%'
  });
});

// Modal functions
function openModal(id) {
  const modal = document.getElementById(id);
  if (modal) {
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
  }
}

function closeModal(id) {
  const modal = document.getElementById(id);
  if (modal) {
    modal.classList.remove('show');
    document.body.style.overflow = 'auto';
  }
}

// Close modal when clicking outside
window.addEventListener('click', function(event) {
  if (event.target.classList.contains('modal')) {
    closeModal(event.target.id);
  }
});

// Clear all filters
function clearFilters() {
  window.location.href = window.location.pathname;
}

// ✅ FORM VALIDATION FUNCTIONS - CAMPUS-SPECIFIC
async function checkFacultyName() {
  const facultyName = document.getElementById('faculty_name').value.trim();
  const selectedCampusIds = Array.from(document.querySelectorAll('#addForm input[name="campus_id[]"]:checked:not(:disabled)')).map(cb => cb.value);
  const errorElement = document.getElementById('faculty_name_error');
  
  if (!facultyName) {
    errorElement.textContent = 'Faculty name is required';
    errorElement.style.display = 'block';
    return false;
  }
  
  if (facultyName.length < 2) {
    errorElement.textContent = 'Faculty name must be at least 2 characters';
    errorElement.style.display = 'block';
    return false;
  }
  
  if (selectedCampusIds.length === 0) {
    // No campuses selected yet, can't check
    errorElement.textContent = '';
    errorElement.style.display = 'none';
    return true;
  }
  
  try {
    const response = await fetch(`faculties.php?ajax=check_faculty_name_in_campuses&name=${encodeURIComponent(facultyName)}&campuses=${selectedCampusIds.join(',')}`);
    const data = await response.json();
    
    if (data.conflicts && data.conflicts.length > 0) {
      errorElement.textContent = `Faculty name already exists in campuses: ${data.conflicts.join(', ')}`;
      errorElement.style.display = 'block';
      return false;
    } else {
      errorElement.textContent = '';
      errorElement.style.display = 'none';
      return true;
    }
  } catch (error) {
    console.error('Error checking faculty name:', error);
    return false;
  }
}

async function checkFacultyCode() {
  const facultyCode = document.getElementById('faculty_code').value.trim();
  const selectedCampusIds = Array.from(document.querySelectorAll('#addForm input[name="campus_id[]"]:checked:not(:disabled)')).map(cb => cb.value);
  const errorElement = document.getElementById('faculty_code_error');
  
  if (!facultyCode) {
    errorElement.textContent = 'Faculty code is required';
    errorElement.style.display = 'block';
    return false;
  }
  
  if (facultyCode.length < 2) {
    errorElement.textContent = 'Faculty code must be at least 2 characters';
    errorElement.style.display = 'block';
    return false;
  }
  
  if (selectedCampusIds.length === 0) {
    // No campuses selected yet, can't check
    errorElement.textContent = '';
    errorElement.style.display = 'none';
    return true;
  }
  
  try {
    const response = await fetch(`faculties.php?ajax=check_faculty_code_in_campuses&code=${encodeURIComponent(facultyCode)}&campuses=${selectedCampusIds.join(',')}`);
    const data = await response.json();
    
    if (data.conflicts && data.conflicts.length > 0) {
      errorElement.textContent = `Faculty code already exists in campuses: ${data.conflicts.join(', ')}`;
      errorElement.style.display = 'block';
      return false;
    } else {
      errorElement.textContent = '';
      errorElement.style.display = 'none';
      return true;
    }
  } catch (error) {
    console.error('Error checking faculty code:', error);
    return false;
  }
}

async function checkEditFacultyName() {
  const facultyName = document.getElementById('edit_name').value.trim();
  const facultyId = document.getElementById('edit_id').value;
  const selectedCampusIds = Array.from(document.querySelectorAll('#editForm input[name="campus_id[]"]:checked:not(:disabled)')).map(cb => cb.value);
  const errorElement = document.getElementById('edit_name_error');
  
  if (!facultyName) {
    errorElement.textContent = 'Faculty name is required';
    errorElement.style.display = 'block';
    return false;
  }
  
  if (facultyName.length < 2) {
    errorElement.textContent = 'Faculty name must be at least 2 characters';
    errorElement.style.display = 'block';
    return false;
  }
  
  if (selectedCampusIds.length === 0) {
    // No campuses selected yet, can't check
    errorElement.textContent = '';
    errorElement.style.display = 'none';
    return true;
  }
  
  try {
    const response = await fetch(`faculties.php?ajax=check_faculty_name_in_campuses&name=${encodeURIComponent(facultyName)}&campuses=${selectedCampusIds.join(',')}&exclude_id=${facultyId}`);
    const data = await response.json();
    
    if (data.conflicts && data.conflicts.length > 0) {
      errorElement.textContent = `Faculty name already exists in campuses: ${data.conflicts.join(', ')}`;
      errorElement.style.display = 'block';
      return false;
    } else {
      errorElement.textContent = '';
      errorElement.style.display = 'none';
      return true;
    }
  } catch (error) {
    console.error('Error checking faculty name:', error);
    return false;
  }
}

async function checkEditFacultyCode() {
  const facultyCode = document.getElementById('edit_code').value.trim();
  const facultyId = document.getElementById('edit_id').value;
  const selectedCampusIds = Array.from(document.querySelectorAll('#editForm input[name="campus_id[]"]:checked:not(:disabled)')).map(cb => cb.value);
  const errorElement = document.getElementById('edit_code_error');
  
  if (!facultyCode) {
    errorElement.textContent = 'Faculty code is required';
    errorElement.style.display = 'block';
    return false;
  }
  
  if (facultyCode.length < 2) {
    errorElement.textContent = 'Faculty code must be at least 2 characters';
    errorElement.style.display = 'block';
    return false;
  }
  
  if (selectedCampusIds.length === 0) {
    // No campuses selected yet, can't check
    errorElement.textContent = '';
    errorElement.style.display = 'none';
    return true;
  }
  
  try {
    const response = await fetch(`faculties.php?ajax=check_faculty_code_in_campuses&code=${encodeURIComponent(facultyCode)}&campuses=${selectedCampusIds.join(',')}&exclude_id=${facultyId}`);
    const data = await response.json();
    
    if (data.conflicts && data.conflicts.length > 0) {
      errorElement.textContent = `Faculty code already exists in campuses: ${data.conflicts.join(', ')}`;
      errorElement.style.display = 'block';
      return false;
    } else {
      errorElement.textContent = '';
      errorElement.style.display = 'none';
      return true;
    }
  } catch (error) {
    console.error('Error checking faculty code:', error);
    return false;
  }
}

// ✅ AUTO ADDRESS FUNCTIONS
function applyAutoAddress() {
  const checkboxes = document.querySelectorAll('#addForm input[name="campus_id[]"]:checked:not(:disabled)');
  const addressField = document.getElementById('office_address');
  const preview = document.getElementById('addressPreview');
  const previewText = document.getElementById('addressPreviewText');
  
  if (checkboxes.length === 0) {
    alert('Please select at least one campus first!');
    return;
  }
  
  // Get addresses from all selected campuses
  let addresses = [];
  let campusNames = [];
  
  checkboxes.forEach(checkbox => {
    const campusName = checkbox.getAttribute('data-campus-name');
    const campusAddress = checkbox.getAttribute('data-campus-address');
    
    if (campusAddress && campusAddress.trim() !== '') {
      addresses.push(campusAddress);
      campusNames.push(campusName);
    }
  });
  
  if (addresses.length === 0) {
    alert('Selected campuses don\'t have addresses configured.');
    return;
  }
  
  // If only one campus selected, use its address directly
  if (addresses.length === 1) {
    addressField.value = addresses[0];
    previewText.textContent = `Using address from: ${campusNames[0]}`;
  } else {
    // If multiple campuses, combine addresses
    let combinedAddress = "Faculty Offices at:\n";
    campusNames.forEach((name, index) => {
      combinedAddress += `• ${name}: ${addresses[index]}\n`;
    });
    addressField.value = combinedAddress;
    previewText.textContent = `Combined addresses from ${campusNames.length} campuses`;
  }
  
  preview.style.display = 'block';
}

function applyAutoAddressEdit() {
  const checkboxes = document.querySelectorAll('#editForm input[name="campus_id[]"]:checked:not(:disabled)');
  const addressField = document.getElementById('edit_office');
  const preview = document.getElementById('editAddressPreview');
  const previewText = document.getElementById('editAddressPreviewText');
  
  if (checkboxes.length === 0) {
    alert('Please select at least one campus first!');
    return;
  }
  
  // Get addresses from all selected campuses
  let addresses = [];
  let campusNames = [];
  
  checkboxes.forEach(checkbox => {
    const campusName = checkbox.getAttribute('data-campus-name');
    const campusAddress = checkbox.getAttribute('data-campus-address');
    
    if (campusAddress && campusAddress.trim() !== '') {
      addresses.push(campusAddress);
      campusNames.push(campusName);
    }
  });
  
  if (addresses.length === 0) {
    alert('Selected campuses don\'t have addresses configured.');
    return;
  }
  
  // If only one campus selected, use its address directly
  if (addresses.length === 1) {
    addressField.value = addresses[0];
    previewText.textContent = `Using address from: ${campusNames[0]}`;
  } else {
    // If multiple campuses, combine addresses
    let combinedAddress = "Faculty Offices at:\n";
    campusNames.forEach((name, index) => {
      combinedAddress += `• ${name}: ${addresses[index]}\n`;
    });
    addressField.value = combinedAddress;
    previewText.textContent = `Combined addresses from ${campusNames.length} campuses`;
  }
  
  preview.style.display = 'block';
}

function validateAddForm() {
  // Check faculty name
  const facultyName = document.getElementById('faculty_name').value.trim();
  if (!facultyName) {
    alert('Faculty name is required!');
    document.getElementById('faculty_name').focus();
    return false;
  }
  
  // Check faculty code
  const facultyCode = document.getElementById('faculty_code').value.trim();
  if (!facultyCode) {
    alert('Faculty code is required!');
    document.getElementById('faculty_code').focus();
    return false;
  }
  
  // Check campuses
  const checkboxes = document.querySelectorAll('#addForm input[name="campus_id[]"]:checked:not(:disabled)');
  if (checkboxes.length === 0) {
    document.getElementById('campus_error').textContent = 'Please select at least one campus!';
    document.getElementById('campus_error').style.display = 'block';
    return false;
  }
  
  // Check for validation errors
  const nameError = document.getElementById('faculty_name_error');
  const codeError = document.getElementById('faculty_code_error');
  
  if (nameError.style.display === 'block' || codeError.style.display === 'block') {
    alert('Please fix validation errors before submitting.');
    return false;
  }
  
  return true;
}

function validateEditForm() {
  // Check faculty name
  const facultyName = document.getElementById('edit_name').value.trim();
  if (!facultyName) {
    alert('Faculty name is required!');
    document.getElementById('edit_name').focus();
    return false;
  }
  
  // Check faculty code
  const facultyCode = document.getElementById('edit_code').value.trim();
  if (!facultyCode) {
    alert('Faculty code is required!');
    document.getElementById('edit_code').focus();
    return false;
  }
  
  // Check campuses
  const checkboxes = document.querySelectorAll('#editForm input[name="campus_id[]"]:checked:not(:disabled)');
  if (checkboxes.length === 0) {
    document.getElementById('edit_campus_error').textContent = 'Please select at least one campus!';
    document.getElementById('edit_campus_error').style.display = 'block';
    return false;
  }
  
  // Check for validation errors
  const nameError = document.getElementById('edit_name_error');
  const codeError = document.getElementById('edit_code_error');
  
  if (nameError.style.display === 'block' || codeError.style.display === 'block') {
    alert('Please fix validation errors before submitting.');
    return false;
  }
  
  return true;
}

// Update selected campus count
function updateSelectedCount() {
  const checkboxes = document.querySelectorAll('#addForm input[name="campus_id[]"]:checked:not(:disabled)');
  document.getElementById('selectedCampusesCount').textContent = checkboxes.length + ' selected';
  
  // Clear error message if campuses are selected
  if (checkboxes.length > 0) {
    document.getElementById('campus_error').style.display = 'none';
  }
  
  // Re-validate faculty name and code when campuses change
  if (document.getElementById('faculty_name').value.trim()) {
    checkFacultyName();
  }
  if (document.getElementById('faculty_code').value.trim()) {
    checkFacultyCode();
  }
  
  // Show/hide address preview
  const preview = document.getElementById('addressPreview');
  if (checkboxes.length > 0) {
    preview.style.display = 'block';
    const previewText = document.getElementById('addressPreviewText');
    const campusNames = Array.from(checkboxes).map(cb => cb.getAttribute('data-campus-name'));
    previewText.textContent = `Selected: ${campusNames.join(', ')}`;
  } else {
    preview.style.display = 'none';
  }
}

function updateEditSelectedCount() {
  const checkboxes = document.querySelectorAll('#editForm input[name="campus_id[]"]:checked:not(:disabled)');
  document.getElementById('editSelectedCampusesCount').textContent = checkboxes.length + ' selected';
  
  // Clear error message if campuses are selected
  if (checkboxes.length > 0) {
    document.getElementById('edit_campus_error').style.display = 'none';
  }
  
  // Re-validate faculty name and code when campuses change
  if (document.getElementById('edit_name').value.trim()) {
    checkEditFacultyName();
  }
  if (document.getElementById('edit_code').value.trim()) {
    checkEditFacultyCode();
  }
  
  // Show/hide address preview
  const preview = document.getElementById('editAddressPreview');
  if (checkboxes.length > 0) {
    preview.style.display = 'block';
    const previewText = document.getElementById('editAddressPreviewText');
    const campusNames = Array.from(checkboxes).map(cb => cb.getAttribute('data-campus-name'));
    previewText.textContent = `Selected: ${campusNames.join(', ')}`;
  } else {
    preview.style.display = 'none';
  }
}

// ✅ OPEN VIEW MODAL
function openViewModal(id, name, code, dean, campuses, phone, email, office, status, photo, initials, totalCampuses) {
  openModal('viewModal');
  
  // Set basic details
  document.getElementById('view_name').textContent = name || 'Not provided';
  document.getElementById('view_code').textContent = code || 'Not provided';
  document.getElementById('view_dean').textContent = dean || 'Not provided';
  document.getElementById('view_phone').textContent = phone || 'Not provided';
  document.getElementById('view_email').textContent = email || 'Not provided';
  document.getElementById('view_office').textContent = office || 'Not provided';
  
  // Set status
  const statusElement = document.getElementById('view_status');
  statusElement.textContent = status ? status.charAt(0).toUpperCase() + status.slice(1) : 'Not provided';
  statusElement.style.borderLeftColor = status === 'active' ? 'var(--primary-color)' : 'var(--danger-color)';
  
  // Set campus count
  document.getElementById('view_campus_count').textContent = totalCampuses || 0;
  
  // Set profile photo
  const photoContainer = document.getElementById('view_profile_photo');
  if (photo && photo.trim() !== '') {
    photoContainer.innerHTML = `<img src="../${photo}" alt="${name}" 
      onerror="this.onerror=null; this.parentElement.innerHTML='<div style=\"width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg, var(--secondary-color), var(--primary-color));color:white;font-size:60px;font-weight:bold;\">${initials}</div>';">`;
  } else {
    photoContainer.innerHTML = `<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg, var(--secondary-color), var(--primary-color));color:white;font-size:60px;font-weight:bold;">${initials}</div>`;
  }
  
  // Set campuses in grid view
  const campusesGrid = document.getElementById('view_campuses_grid');
  campusesGrid.innerHTML = '';
  
  if (campuses && campuses.trim() !== '') {
    const campusArray = campuses.split(' | ');
    campusArray.forEach(campus => {
      if (campus.trim()) {
        // Parse campus info
        const campusMatch = campus.match(/(.+?) \(([^)]+)\)/);
        const campusName = campusMatch ? campusMatch[1] : campus;
        const campusCode = campusMatch ? campusMatch[2] : '';
        
        // Find campus address from PHP data
        let campusAddress = '';
        <?php foreach($campuses as $c): ?>
          if("<?= $c['campus_name'] ?>" === campusName) {
            campusAddress = "<?= $c['address'] ?>";
          }
        <?php endforeach; ?>
        
        const campusCard = document.createElement('div');
        campusCard.className = 'campus-card';
        campusCard.innerHTML = `
          <div class="campus-card-header">
            <div class="campus-card-name">${campusName}</div>
            <div class="campus-card-code">${campusCode}</div>
          </div>
          ${campusAddress ? `
            <div class="campus-card-location">
              <i class="fas fa-location-dot"></i>
              ${campusAddress}
            </div>
          ` : ''}
        `;
        campusesGrid.appendChild(campusCard);
      }
    });
  } else {
    campusesGrid.innerHTML = '<div style="grid-column: 1 / -1; text-align: center; color: #999; font-style: italic; padding: 30px;">No campuses assigned to this faculty</div>';
  }
}

// Open edit modal with data
function openEditModal(id, name, code, dean, campus_ids, phone, email, office, status) {
  openModal('editModal');
  
  // Set form values
  document.getElementById('edit_id').value = id;
  document.getElementById('edit_name').value = name;
  document.getElementById('edit_code').value = code;
  document.getElementById('edit_dean').value = dean || '';
  document.getElementById('edit_phone').value = phone || '';
  document.getElementById('edit_email').value = email || '';
  document.getElementById('edit_office').value = office || '';
  document.getElementById('edit_status').value = status;
  
  // Clear all campus checkboxes first
  const checkboxes = document.querySelectorAll('#edit_campus_checkboxes input[type="checkbox"]:not(:disabled)');
  checkboxes.forEach(checkbox => {
    checkbox.checked = false;
  });
  
  // Check campuses that this faculty belongs to
  let selectedCount = 0;
  if (campus_ids) {
    const campusArray = campus_ids.split(',');
    campusArray.forEach(campusId => {
      const checkbox = document.getElementById('edit_campus_' + campusId);
      if (checkbox) {
        checkbox.checked = true;
        selectedCount++;
      }
    });
  }
  
  // Update selected count
  document.getElementById('editSelectedCampusesCount').textContent = selectedCount + ' selected';
  
  // Clear validation messages
  document.getElementById('edit_name_error').style.display = 'none';
  document.getElementById('edit_code_error').style.display = 'none';
  document.getElementById('edit_campus_error').style.display = 'none';
  
  // Show address preview if campuses are selected
  const preview = document.getElementById('editAddressPreview');
  if (selectedCount > 0) {
    preview.style.display = 'block';
    const previewText = document.getElementById('editAddressPreviewText');
    const campusNames = Array.from(checkboxes).filter(cb => cb.checked).map(cb => cb.getAttribute('data-campus-name'));
    previewText.textContent = `Selected: ${campusNames.join(', ')}`;
  } else {
    preview.style.display = 'none';
  }
}

// Open delete modal
function openDeleteModal(id) {
  openModal('deleteModal');
  document.getElementById('delete_id').value = id;
}

// Auto-hide alert
document.addEventListener('DOMContentLoaded', function() {
  const alert = document.getElementById('popup');
  if (alert && alert.classList.contains('show')) {
    setTimeout(() => {
      alert.classList.remove('show');
    }, 3500);
  }
  
  // Initialize campus counts
  updateSelectedCount();
  updateEditSelectedCount();
  
  // Auto-submit filters on change
  document.getElementById('status').addEventListener('change', function() {
    document.getElementById('filterForm').submit();
  });
  
  $('#campus').on('change', function() {
    document.getElementById('filterForm').submit();
  });
  
  // Debounced search
  let searchTimer;
  document.getElementById('search').addEventListener('input', function(e) {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      if (e.target.value.length === 0 || e.target.value.length > 2) {
        document.getElementById('filterForm').submit();
      }
    }, 600);
  });
  
  // Add keyboard shortcuts
  document.addEventListener('keydown', function(e) {
    // Ctrl + F to focus search
    if (e.ctrlKey && e.key === 'f') {
      e.preventDefault();
      document.getElementById('search').focus();
    }
    
    // Ctrl + N to open add modal
    if (e.ctrlKey && e.key === 'n') {
      e.preventDefault();
      openModal('addModal');
    }
    
    // Escape to close modals
    if (e.key === 'Escape') {
      const openModals = document.querySelectorAll('.modal.show');
      if (openModals.length > 0) {
        closeModal(openModals[0].id);
      }
    }
  });
});

// Tooltip functionality
document.addEventListener('mouseover', function(e) {
  if (e.target.classList.contains('campus-count-badge')) {
    const tooltip = e.target.getAttribute('data-tooltip');
    if (tooltip) {
      // Create tooltip element
      let tooltipEl = document.getElementById('custom-tooltip');
      if (!tooltipEl) {
        tooltipEl = document.createElement('div');
        tooltipEl.id = 'custom-tooltip';
        tooltipEl.style.cssText = `
          position: fixed;
          background: #333;
          color: white;
          padding: 8px 12px;
          border-radius: 6px;
          font-size: 12px;
          z-index: 9999;
          max-width: 300px;
          word-wrap: break-word;
          box-shadow: 0 4px 12px rgba(0,0,0,0.2);
          pointer-events: none;
        `;
        document.body.appendChild(tooltipEl);
      }
      
      tooltipEl.textContent = tooltip;
      tooltipEl.style.display = 'block';
      
      const rect = e.target.getBoundingClientRect();
      tooltipEl.style.left = (rect.left + rect.width/2 - tooltipEl.offsetWidth/2) + 'px';
      tooltipEl.style.top = (rect.top - tooltipEl.offsetHeight - 10) + 'px';
    }
  }
});

document.addEventListener('mouseout', function(e) {
  if (e.target.classList.contains('campus-count-badge')) {
    const tooltipEl = document.getElementById('custom-tooltip');
    if (tooltipEl) {
      tooltipEl.style.display = 'none';
    }
  }
});
</script>
<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>