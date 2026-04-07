<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Access Control
$role = strtolower($_SESSION['user']['role'] ?? '');
$user_campus_id = $_SESSION['user']['campus_id'] ?? null;

if (!in_array($role, ['super_admin','admin','campus_admin'])) {
  header("Location: ../login.php");
  exit;
}

$message = "";
$type = "";
$parents = []; // Initialize parents array
$relations = []; // Initialize relations array
$campuses = []; // Initialize campuses array

/* ============================================================
   CRUD OPERATIONS
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // 🟢 ADD PARENT
  if ($_POST['action'] === 'add') {
    try {
      $email = trim($_POST['email']);
      
      // Check if email exists
      if (!empty($email)) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM parents WHERE email=?");
        $check->execute([$email]);
        if ($check->fetchColumn() > 0) {
          throw new Exception("Email already exists!");
        }
      }

      // Handle photo upload
      $photo_path = 'upload/profiles/default.png'; // Default photo
      if (!empty($_FILES['photo']['name'])) {
        $upload_dir = __DIR__ . '/../upload/profiles/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        
        // Validate file
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
        $file_type = mime_content_type($_FILES['photo']['tmp_name']);
        $file_size = $_FILES['photo']['size'];
        
        if (!in_array($file_type, $allowed_types)) {
          throw new Exception("Invalid image format. Only JPG, PNG, GIF allowed.");
        }
        
        if ($file_size > 2 * 1024 * 1024) { // 2MB limit
          throw new Exception("Image size too large. Max 2MB allowed.");
        }
        
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $new_name = 'parent_' . time() . '_' . uniqid() . '.' . $ext;
        $photo_path = 'upload/profiles/' . $new_name;
        
        if (!move_uploaded_file($_FILES['photo']['tmp_name'], __DIR__ . '/../' . $photo_path)) {
          throw new Exception("Failed to upload image.");
        }
      }

      // Insert into parents table with campus_id if column exists
      try {
        // Check if campus_id column exists in parents table
        $check_column = $pdo->query("SHOW COLUMNS FROM parents LIKE 'campus_id'")->fetch();
        
        if ($check_column && isset($_POST['campus_id']) && !empty($_POST['campus_id'])) {
          // Insert with campus_id
          $stmt = $pdo->prepare("INSERT INTO parents 
            (full_name, gender, phone, email, address, occupation, photo_path, campus_id, status, created_at)
            VALUES (?,?,?,?,?,?,?,?, 'active', NOW())");
          $stmt->execute([
            $_POST['full_name'],
            $_POST['gender'],
            $_POST['phone'],
            $email,
            $_POST['address'],
            $_POST['occupation'],
            $photo_path,
            $_POST['campus_id']
          ]);
        } else if ($check_column && $user_campus_id) {
          // Insert with user's campus_id
          $stmt = $pdo->prepare("INSERT INTO parents 
            (full_name, gender, phone, email, address, occupation, photo_path, campus_id, status, created_at)
            VALUES (?,?,?,?,?,?,?,?, 'active', NOW())");
          $stmt->execute([
            $_POST['full_name'],
            $_POST['gender'],
            $_POST['phone'],
            $email,
            $_POST['address'],
            $_POST['occupation'],
            $photo_path,
            $user_campus_id
          ]);
        } else {
          // Insert without campus_id
          $stmt = $pdo->prepare("INSERT INTO parents 
            (full_name, gender, phone, email, address, occupation, photo_path, status, created_at)
            VALUES (?,?,?,?,?,?,?, 'active', NOW())");
          $stmt->execute([
            $_POST['full_name'],
            $_POST['gender'],
            $_POST['phone'],
            $email,
            $_POST['address'],
            $_POST['occupation'],
            $photo_path
          ]);
        }
      } catch (Exception $e) {
        // Fallback to simple insert
        $stmt = $pdo->prepare("INSERT INTO parents 
          (full_name, gender, phone, email, address, occupation, photo_path, status, created_at)
          VALUES (?,?,?,?,?,?,?, 'active', NOW())");
        $stmt->execute([
          $_POST['full_name'],
          $_POST['gender'],
          $_POST['phone'],
          $email,
          $_POST['address'],
          $_POST['occupation'],
          $photo_path
        ]);
      }

      $parent_id = $pdo->lastInsertId();

      // Auto-create user account
      $plain_pass = "123";
      $hashed = password_hash($plain_pass, PASSWORD_BCRYPT);
      
      $user = $pdo->prepare("INSERT INTO users 
        (user_uuid, username, email, phone_number, profile_photo_path, password, password_plain, role, linked_id, linked_table, campus_id, status, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?, NOW())");
      $user->execute([
        uniqid('PAR_'),                    // user_uuid
        $_POST['full_name'],              // username
        $email,                           // email
        $_POST['phone'],                  // phone_number
        $photo_path,                      // profile_photo_path
        $hashed,                          // password
        $plain_pass,                      // password_plain
        'parent',                         // role
        $parent_id,                       // linked_id
        'parent',                         // linked_table
        isset($_POST['campus_id']) ? $_POST['campus_id'] : $user_campus_id, // campus_id
        'active'                          // status
      ]);

      $message = "✅ Parent added successfully! Default password: 123";
      $type = "success";
      
      // Redirect to prevent form resubmission
      header("Location: parents.php?success=1");
      exit;
      
    } catch (Exception $e) {
      $message = "❌ " . $e->getMessage();
      $type = "error";
    }
  }

  // 🟡 UPDATE PARENT
  if ($_POST['action'] === 'update') {
    try {
      $id = $_POST['parent_id'];
      
      // Check if campus admin can edit this parent
      if ($role === 'campus_admin' && $user_campus_id) {
        $check = $pdo->prepare("SELECT campus_id FROM parents WHERE parent_id = ?");
        $check->execute([$id]);
        $parent_campus = $check->fetch(PDO::FETCH_ASSOC);
        
        if ($parent_campus && $parent_campus['campus_id'] != $user_campus_id) {
          throw new Exception("You can only edit parents from your campus!");
        }
      }
      
      // Get current photo path
      $stmt = $pdo->prepare("SELECT photo_path FROM parents WHERE parent_id = ?");
      $stmt->execute([$id]);
      $current = $stmt->fetch(PDO::FETCH_ASSOC);
      $photo_path = $current['photo_path'] ?? 'upload/profiles/default.png';
      
      // Handle new photo upload
      if (!empty($_FILES['photo']['name'])) {
        $upload_dir = __DIR__ . '/../upload/profiles/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        
        // Validate file
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
        $file_type = mime_content_type($_FILES['photo']['tmp_name']);
        $file_size = $_FILES['photo']['size'];
        
        if (!in_array($file_type, $allowed_types)) {
          throw new Exception("Invalid image format. Only JPG, PNG, GIF allowed.");
        }
        
        if ($file_size > 2 * 1024 * 1024) { // 2MB limit
          throw new Exception("Image size too large. Max 2MB allowed.");
        }
        
        // Delete old photo if it's not default
        if ($photo_path && $photo_path !== 'upload/profiles/default.png' && file_exists(__DIR__ . '/../' . $photo_path)) {
          unlink(__DIR__ . '/../' . $photo_path);
        }
        
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $new_name = 'parent_' . time() . '_' . uniqid() . '.' . $ext;
        $photo_path = 'upload/profiles/' . $new_name;
        
        if (!move_uploaded_file($_FILES['photo']['tmp_name'], __DIR__ . '/../' . $photo_path)) {
          throw new Exception("Failed to upload image.");
        }
      }

      // Update with campus_id if column exists
      try {
        $check_column = $pdo->query("SHOW COLUMNS FROM parents LIKE 'campus_id'")->fetch();
        
        if ($check_column && isset($_POST['campus_id']) && !empty($_POST['campus_id'])) {
          $pdo->prepare("UPDATE parents 
            SET full_name=?, gender=?, phone=?, email=?, address=?, occupation=?, status=?, photo_path=?, campus_id=?
            WHERE parent_id=?")->execute([
              $_POST['full_name'], 
              $_POST['gender'], 
              $_POST['phone'],
              $_POST['email'], 
              $_POST['address'], 
              $_POST['occupation'],
              $_POST['status'], 
              $photo_path,
              $_POST['campus_id'],
              $id
            ]);
        } else {
          $pdo->prepare("UPDATE parents 
            SET full_name=?, gender=?, phone=?, email=?, address=?, occupation=?, status=?, photo_path=?
            WHERE parent_id=?")->execute([
              $_POST['full_name'], 
              $_POST['gender'], 
              $_POST['phone'],
              $_POST['email'], 
              $_POST['address'], 
              $_POST['occupation'],
              $_POST['status'], 
              $photo_path,
              $id
            ]);
        }
      } catch (Exception $e) {
        // Fallback to simple update
        $pdo->prepare("UPDATE parents 
          SET full_name=?, gender=?, phone=?, email=?, address=?, occupation=?, status=?, photo_path=?
          WHERE parent_id=?")->execute([
            $_POST['full_name'], 
            $_POST['gender'], 
            $_POST['phone'],
            $_POST['email'], 
            $_POST['address'], 
            $_POST['occupation'],
            $_POST['status'], 
            $photo_path,
            $id
          ]);
      }

      // Update user account
      $pdo->prepare("UPDATE users 
        SET username=?, email=?, phone_number=?, profile_photo_path=?, status=? 
        WHERE linked_id=? AND linked_table='parent'")
        ->execute([
          $_POST['full_name'], 
          $_POST['email'], 
          $_POST['phone'],
          $photo_path,
          $_POST['status'], 
          $id
        ]);

      $message = "✅ Parent updated successfully!";
      $type = "success";
      
      // Redirect to prevent form resubmission
      header("Location: parents.php?success=1");
      exit;
      
    } catch (Exception $e) {
      $message = "❌ " . $e->getMessage();
      $type = "error";
    }
  }

  // 🔴 DELETE PARENT
  if ($_POST['action'] === 'delete') {
    try {
      $id = $_POST['parent_id'];
      
      // Check if campus admin can delete this parent
      if ($role === 'campus_admin' && $user_campus_id) {
        $check = $pdo->prepare("SELECT campus_id FROM parents WHERE parent_id = ?");
        $check->execute([$id]);
        $parent_campus = $check->fetch(PDO::FETCH_ASSOC);
        
        if ($parent_campus && $parent_campus['campus_id'] != $user_campus_id) {
          throw new Exception("You can only delete parents from your campus!");
        }
      }
      
      // Get photo path before deleting
      $stmt = $pdo->prepare("SELECT photo_path FROM parents WHERE parent_id = ?");
      $stmt->execute([$id]);
      $photo = $stmt->fetch(PDO::FETCH_ASSOC);
      
      // Delete photo if it's not default
      if ($photo && $photo['photo_path'] && $photo['photo_path'] !== 'upload/profiles/default.png' && file_exists(__DIR__ . '/../' . $photo['photo_path'])) {
        unlink(__DIR__ . '/../' . $photo['photo_path']);
      }
      
      $pdo->prepare("DELETE FROM users WHERE linked_id=? AND linked_table='parent'")->execute([$id]);
      $pdo->prepare("DELETE FROM parent_student WHERE parent_id=?")->execute([$id]);
      $pdo->prepare("DELETE FROM parents WHERE parent_id=?")->execute([$id]);
      
      $message = "✅ Parent deleted successfully!";
      $type = "success";
      
      // Redirect to prevent form resubmission
      header("Location: parents.php?success=1");
      exit;
      
    } catch (Exception $e) {
      $message = "❌ " . $e->getMessage();
      $type = "error";
    }
  }
}

// Check for success redirect
if (isset($_GET['success']) && $_GET['success'] == 1) {
  $message = "✅ Operation completed successfully!";
  $type = "success";
}

/* ============================================================
   FETCH PARENTS + RELATIONS WITH CAMPUS FILTER
============================================================ */
$search = trim($_GET['q'] ?? '');

// Get campus filter for display
$selected_campus = $_GET['campus'] ?? ($user_campus_id ?? 'all');

// Get campuses for super_admin filter
if ($role === 'super_admin') {
  try {
    $campuses = $pdo->query("SELECT * FROM campus WHERE status='active' ORDER BY campus_name")->fetchAll(PDO::FETCH_ASSOC);
  } catch (Exception $e) {
    $campuses = [];
  }
}

// Build SQL query with campus filter
$sql = "SELECT p.*, 
        CASE 
          WHEN photo_path IS NULL OR photo_path = '' THEN 'upload/profiles/default.png'
          WHEN photo_path NOT LIKE 'upload/profiles/%' THEN CONCAT('upload/profiles/', photo_path)
          ELSE photo_path 
        END as display_photo";
        
// Add campus name if available
try {
  $check_join = $pdo->query("SHOW COLUMNS FROM parents LIKE 'campus_id'")->fetch();
  if ($check_join) {
    $sql .= ", c.campus_name, c.campus_code";
    $sql .= " FROM parents p LEFT JOIN campus c ON p.campus_id = c.campus_id";
  } else {
    $sql .= " FROM parents p";
  }
} catch (Exception $e) {
  $sql .= " FROM parents p";
}

$where = [];
$params = [];

// Add campus filter for campus_admin
if ($role === 'campus_admin' && $user_campus_id) {
  try {
    $check_column = $pdo->query("SHOW COLUMNS FROM parents LIKE 'campus_id'")->fetch();
    if ($check_column) {
      $where[] = "p.campus_id = ?";
      $params[] = $user_campus_id;
    }
  } catch (Exception $e) {
    // Column doesn't exist, no filter
  }
}

// Add campus filter for super_admin
if ($role === 'super_admin' && isset($_GET['campus']) && $_GET['campus'] !== 'all' && $_GET['campus'] !== '') {
  try {
    $check_column = $pdo->query("SHOW COLUMNS FROM parents LIKE 'campus_id'")->fetch();
    if ($check_column) {
      $where[] = "p.campus_id = ?";
      $params[] = $_GET['campus'];
    }
  } catch (Exception $e) {
    // Column doesn't exist, no filter
  }
}

// Add search filter
if ($search !== '') {
  $where[] = "(p.full_name LIKE ? OR p.phone LIKE ? OR p.email LIKE ? OR p.full_name LIKE ?)";
  $params[] = "%$search%";
  $params[] = "%$search%";
  $params[] = "%$search%";
  $params[] = "%" . str_replace(' ', '%', $search) . "%";
}

if (!empty($where)) {
  $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY p.parent_id DESC";

try {
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $parents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $parents = [];
  $message = "❌ Database error: " . $e->getMessage();
  $type = "error";
}

// Ensure all parents have a valid photo path
foreach ($parents as &$parent) {
  if (empty($parent['display_photo']) || !file_exists(__DIR__ . '/../' . $parent['display_photo'])) {
    $parent['display_photo'] = 'upload/profiles/default.png';
  }
}

/* ✅ Fetch related students (children) with campus filter */
$relations = [];
if (!empty($parents)) {
  $parent_ids = array_column($parents, 'parent_id');
  $placeholders = str_repeat('?,', count($parent_ids) - 1) . '?';
  
  $relation_sql = "
    SELECT p.parent_id, s.student_id, s.full_name AS student_name, s.reg_no, 
           s.status AS student_status, ps.relation_type, s.campus_id as student_campus_id,
           c.campus_name as student_campus_name
    FROM parent_student ps
    JOIN parents p ON p.parent_id = ps.parent_id
    JOIN students s ON s.student_id = ps.student_id
    LEFT JOIN campus c ON s.campus_id = c.campus_id
    WHERE p.parent_id IN ($placeholders)";
  
  // Add campus filter for campus_admin
  if ($role === 'campus_admin' && $user_campus_id) {
    $relation_sql .= " AND s.campus_id = ?";
    $parent_ids[] = $user_campus_id;
  }
  
  $relation_sql .= " ORDER BY p.parent_id, s.full_name";
  
  try {
    $relation_stmt = $pdo->prepare($relation_sql);
    $relation_stmt->execute($parent_ids);
    
    while($r = $relation_stmt->fetch(PDO::FETCH_ASSOC)) {
      $relations[$r['parent_id']][] = $r;
    }
  } catch (Exception $e) {
    // Error fetching relations
  }
}

// Get student count statistics
$total_students = 0;
$campus_students = 0;
$other_campus_students = 0;

foreach ($relations as $parent_relations) {
  foreach ($parent_relations as $relation) {
    $total_students++;
    if ($relation['student_campus_id'] == $user_campus_id) {
      $campus_students++;
    } else {
      $other_campus_students++;
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Parents | Hormuud University</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="../images.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ============================================
   COMPLETE CSS FOR PARENTS MANAGEMENT SYSTEM
============================================ */

:root {
  --green: #00843D;
  --blue: #0072CE;
  --red: #C62828;
  --orange: #FF9800;
  --purple: #7B1FA2;
  --bg: #F5F9F7;
  --light-gray: #f8f9fa;
  --dark-gray: #6c757d;
  --border: #e0e0e0;
  --text: #333;
  --text-light: #666;
  --shadow: 0 2px 10px rgba(0,0,0,0.08);
  --shadow-hover: 0 5px 20px rgba(0,0,0,0.12);
}

* {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
}

body {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
  background: var(--bg);
  color: var(--text);
  line-height: 1.5;
  overflow-x: hidden;
}

/* MAIN CONTENT */
.main-content {
  margin-left: 250px;
  padding: 30px;
  transition: all 0.3s ease;
  margin-top: 70px;
  min-height: calc(100vh - 70px);
}

.sidebar.collapsed ~ .main-content {
  margin-left: 70px;
}

@media (max-width: 992px) {
  .main-content {
    margin-left: 0;
    padding: 20px;
  }
}

/* TOP BAR */
.top-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 30px;
  padding-bottom: 15px;
  border-bottom: 2px solid var(--border);
  animation: slideDown 0.5s ease;
}

@keyframes slideDown {
  from { transform: translateY(-20px); opacity: 0; }
  to { transform: translateY(0); opacity: 1; }
}

.top-bar h1 {
  color: var(--blue);
  font-size: 28px;
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: 10px;
}

.add-btn {
  background: var(--green);
  color: white;
  border: none;
  padding: 12px 24px;
  border-radius: 8px;
  font-weight: 600;
  font-size: 15px;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 8px;
  transition: all 0.3s ease;
  box-shadow: 0 2px 8px rgba(0, 132, 61, 0.2);
}

.add-btn:hover {
  background: #006f33;
  transform: translateY(-2px);
  box-shadow: 0 4px 15px rgba(0, 132, 61, 0.3);
}

.add-btn:active {
  transform: translateY(0);
}

/* STATS CARDS */
.stats-container {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 20px;
  margin-bottom: 30px;
  animation: fadeIn 0.6s ease;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.stat-card {
  background: white;
  padding: 25px;
  border-radius: 12px;
  box-shadow: var(--shadow);
  display: flex;
  align-items: center;
  gap: 20px;
  transition: all 0.3s ease;
  border: 1px solid rgba(0,0,0,0.05);
  position: relative;
  overflow: hidden;
}

.stat-card:hover {
  transform: translateY(-5px);
  box-shadow: var(--shadow-hover);
}

.stat-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  width: 4px;
  height: 100%;
}

.stat-card:nth-child(1)::before { background: var(--blue); }
.stat-card:nth-child(2)::before { background: var(--green); }
.stat-card:nth-child(3)::before { background: var(--orange); }
.stat-card:nth-child(4)::before { background: var(--purple); }

.stat-icon {
  width: 60px;
  height: 60px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 28px;
  flex-shrink: 0;
}

.stat-icon.blue { background: linear-gradient(135deg, #e3f2fd, #bbdefb); color: #1565c0; }
.stat-icon.green { background: linear-gradient(135deg, #e8f5e9, #c8e6c9); color: #2e7d32; }
.stat-icon.orange { background: linear-gradient(135deg, #fff3e0, #ffe0b2); color: #ef6c00; }
.stat-icon.purple { background: linear-gradient(135deg, #f3e5f5, #e1bee7); color: #7b1fa2; }

.stat-info h3 {
  margin: 0 0 5px 0;
  font-size: 32px;
  color: var(--text);
  font-weight: 700;
  line-height: 1;
}

.stat-info p {
  margin: 0;
  color: var(--text-light);
  font-size: 14px;
  font-weight: 500;
}

/* FILTERS SECTION */
.filters-section {
  background: white;
  border-radius: 12px;
  padding: 25px;
  margin-bottom: 30px;
  box-shadow: var(--shadow);
  border: 1px solid rgba(0,0,0,0.05);
  animation: slideUp 0.5s ease;
}

@keyframes slideUp {
  from { transform: translateY(20px); opacity: 0; }
  to { transform: translateY(0); opacity: 1; }
}

.campus-info {
  background: linear-gradient(135deg, #e3f2fd, #bbdefb);
  border-radius: 8px;
  padding: 15px 20px;
  margin-bottom: 20px;
  border-left: 4px solid var(--blue);
  display: flex;
  align-items: center;
  gap: 12px;
  animation: pulse 2s infinite;
}

@keyframes pulse {
  0% { box-shadow: 0 0 0 0 rgba(0, 114, 206, 0.2); }
  70% { box-shadow: 0 0 0 10px rgba(0, 114, 206, 0); }
  100% { box-shadow: 0 0 0 0 rgba(0, 114, 206, 0); }
}

.campus-info i {
  font-size: 20px;
  color: var(--blue);
}

.campus-info div {
  font-size: 15px;
}

.campus-info strong {
  color: var(--blue);
  font-weight: 600;
}

.search-row {
  display: flex;
  align-items: center;
  gap: 15px;
  flex-wrap: wrap;
}

.search-row input[type="text"] {
  flex: 1;
  min-width: 300px;
  padding: 14px 18px;
  border: 2px solid var(--border);
  border-radius: 8px;
  font-size: 15px;
  transition: all 0.3s ease;
  background: #fafafa;
}

.search-row input[type="text"]:focus {
  outline: none;
  border-color: var(--blue);
  box-shadow: 0 0 0 3px rgba(0, 114, 206, 0.1);
  background: white;
}

.search-btn, .clear-btn {
  padding: 14px 24px;
  border: none;
  border-radius: 8px;
  font-weight: 600;
  font-size: 15px;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 8px;
  transition: all 0.3s ease;
  min-width: 120px;
  justify-content: center;
}

.search-btn {
  background: var(--blue);
  color: white;
  box-shadow: 0 2px 8px rgba(0, 114, 206, 0.2);
}

.search-btn:hover {
  background: #005bb5;
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 114, 206, 0.3);
}

.clear-btn {
  background: var(--dark-gray);
  color: white;
  box-shadow: 0 2px 8px rgba(108, 117, 125, 0.2);
}

.clear-btn:hover {
  background: #5a6268;
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
}

/* CAMPUS FILTER FOR SUPER ADMIN */
.campus-filter-row {
  display: flex;
  align-items: center;
  gap: 15px;
  margin-bottom: 20px;
  padding-bottom: 20px;
  border-bottom: 1px solid var(--border);
}

.campus-filter-row label {
  font-weight: 600;
  color: var(--text);
  font-size: 15px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.campus-filter-row select {
  padding: 12px 20px;
  border: 2px solid var(--border);
  border-radius: 8px;
  font-size: 15px;
  min-width: 250px;
  background: white;
  cursor: pointer;
  transition: all 0.3s ease;
}

.campus-filter-row select:focus {
  outline: none;
  border-color: var(--blue);
  box-shadow: 0 0 0 3px rgba(0, 114, 206, 0.1);
}

/* TABLE CONTAINER */
.table-container {
  background: white;
  border-radius: 12px;
  overflow: hidden;
  box-shadow: var(--shadow-hover);
  margin-top: 20px;
  animation: slideUp 0.7s ease;
}

.table-header {
  padding: 25px;
  border-bottom: 1px solid var(--border);
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: linear-gradient(135deg, #f8f9fa, #e9ecef);
}

.table-header h2 {
  margin: 0;
  color: var(--text);
  font-size: 20px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 10px;
}

.record-count {
  background: var(--blue);
  color: white;
  padding: 8px 16px;
  border-radius: 20px;
  font-size: 14px;
  font-weight: 500;
  box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}

/* TABLE */
.table-wrapper {
  overflow-x: auto;
  max-height: 600px;
  position: relative;
}

table {
  width: 100%;
  min-width: 1200px;
  border-collapse: collapse;
}

thead {
  position: sticky;
  top: 0;
  z-index: 10;
}

thead th {
  background: linear-gradient(135deg, var(--blue), #005bb5);
  color: white;
  padding: 18px 20px;
  text-align: left;
  font-weight: 600;
  font-size: 14px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  border: none;
  position: relative;
}

thead th::after {
  content: '';
  position: absolute;
  bottom: 0;
  left: 0;
  width: 100%;
  height: 2px;
  background: rgba(255,255,255,0.2);
}

tbody tr {
  border-bottom: 1px solid #f0f0f0;
  transition: all 0.3s ease;
}

tbody tr:nth-child(even) {
  background: #fafafa;
}

tbody tr:hover {
  background: rgba(0, 114, 206, 0.05);
  transform: scale(1.002);
  box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

tbody td {
  padding: 18px 20px;
  font-size: 14px;
  color: var(--text);
  vertical-align: middle;
}

/* PROFILE PHOTO */
.profile-photo {
  width: 50px;
  height: 50px;
  border-radius: 50%;
  overflow: hidden;
  border: 3px solid #f0f0f0;
  background: #f8f9fa;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.3s ease;
}

.profile-photo:hover {
  border-color: var(--blue);
  transform: scale(1.1);
}

.profile-photo img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.profile-photo i {
  color: #999;
  font-size: 20px;
}

/* STATUS BADGES */
.status-badge {
  display: inline-block;
  padding: 6px 14px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  transition: all 0.3s ease;
}

.status-active {
  background: rgba(0, 132, 61, 0.1);
  color: var(--green);
  border: 1px solid rgba(0, 132, 61, 0.2);
}

.status-active:hover {
  background: rgba(0, 132, 61, 0.2);
  transform: translateY(-2px);
}

.status-inactive {
  background: rgba(198, 40, 40, 0.1);
  color: var(--red);
  border: 1px solid rgba(198, 40, 40, 0.2);
}

.status-inactive:hover {
  background: rgba(198, 40, 40, 0.2);
  transform: translateY(-2px);
}

/* CAMPUS BADGE */
.campus-badge {
  display: inline-block;
  padding: 4px 10px;
  border-radius: 12px;
  font-size: 11px;
  font-weight: 500;
  background: #f0f0f0;
  color: #666;
  margin-left: 8px;
  vertical-align: middle;
  transition: all 0.3s ease;
}

.campus-badge:hover {
  transform: translateY(-2px);
  box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}

.campus-badge.primary {
  background: #e3f2fd;
  color: #1976d2;
  border: 1px solid #bbdefb;
}

.campus-badge i {
  font-size: 10px;
  margin-right: 3px;
}

/* CHILDREN LIST */
.children-container {
  max-width: 300px;
}

.children-list {
  list-style: none;
  padding: 0;
  margin: 0;
}

.child-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  margin-bottom: 8px;
  background: #f8f9fa;
  border-radius: 8px;
  border-left: 3px solid var(--blue);
  transition: all 0.3s ease;
  position: relative;
  overflow: hidden;
}

.child-item:hover {
  background: #e9ecef;
  transform: translateX(5px);
  box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.child-item::before {
  content: '';
  position: absolute;
  left: 0;
  top: 0;
  height: 100%;
  width: 3px;
  background: var(--blue);
}

.child-icon {
  color: var(--blue);
  font-size: 14px;
  flex-shrink: 0;
}

.child-info {
  flex: 1;
  min-width: 0;
}

.child-name {
  font-weight: 600;
  font-size: 13px;
  color: var(--text);
  margin-bottom: 4px;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 5px;
  line-height: 1.3;
}

.child-details {
  font-size: 11px;
  color: var(--text-light);
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
}

.child-details span {
  display: flex;
  align-items: center;
  gap: 4px;
  white-space: nowrap;
}

.view-more-btn {
  width: 100%;
  background: none;
  border: 1px dashed var(--blue);
  color: var(--blue);
  padding: 8px;
  border-radius: 6px;
  font-size: 12px;
  cursor: pointer;
  transition: all 0.3s ease;
  text-align: center;
  font-weight: 500;
}

.view-more-btn:hover {
  background: rgba(0, 114, 206, 0.05);
  border-style: solid;
  transform: translateY(-2px);
}

/* ACTION BUTTONS */
.action-buttons {
  display: flex;
  gap: 8px;
}

.btn-action {
  width: 40px;
  height: 40px;
  border-radius: 8px;
  border: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 15px;
  transition: all 0.3s ease;
  position: relative;
  overflow: hidden;
}

.btn-action::after {
  content: '';
  position: absolute;
  top: 50%;
  left: 50%;
  width: 0;
  height: 0;
  border-radius: 50%;
  background: rgba(255,255,255,0.2);
  transform: translate(-50%, -50%);
  transition: width 0.6s, height 0.6s;
}

.btn-action:hover::after {
  width: 300px;
  height: 300px;
}

.btn-edit {
  background: rgba(0, 114, 206, 0.1);
  color: var(--blue);
  border: 1px solid rgba(0, 114, 206, 0.2);
}

.btn-edit:hover {
  background: var(--blue);
  color: white;
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 114, 206, 0.3);
}

.btn-delete {
  background: rgba(198, 40, 40, 0.1);
  color: var(--red);
  border: 1px solid rgba(198, 40, 40, 0.2);
}

.btn-delete:hover {
  background: var(--red);
  color: white;
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(198, 40, 40, 0.3);
}

/* EMPTY STATE */
.empty-state {
  text-align: center;
  padding: 60px 20px;
  animation: fadeIn 0.8s ease;
}

.empty-state i {
  font-size: 60px;
  color: #ddd;
  margin-bottom: 20px;
  animation: bounce 2s infinite;
}

@keyframes bounce {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-10px); }
}

.empty-state h3 {
  font-size: 20px;
  color: var(--text-light);
  margin-bottom: 10px;
  font-weight: 600;
}

.empty-state p {
  color: #999;
  font-size: 15px;
  max-width: 400px;
  margin: 0 auto;
}

/* MODALS */
.modal {
  display: none;
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0,0,0,0.5);
  justify-content: center;
  align-items: center;
  z-index: 9999;
  padding: 20px;
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
  background: white;
  border-radius: 16px;
  width: 100%;
  max-width: 700px;
  max-height: 90vh;
  overflow-y: auto;
  position: relative;
  box-shadow: 0 20px 60px rgba(0,0,0,0.3);
  animation: modalSlideIn 0.4s ease;
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
  top: 20px;
  right: 20px;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: #f8f9fa;
  border: none;
  color: var(--text-light);
  font-size: 24px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.3s ease;
  z-index: 10;
}

.close-modal:hover {
  background: #e9ecef;
  color: var(--text);
  transform: rotate(90deg);
}

.modal-header {
  padding: 30px 30px 20px;
  border-bottom: 1px solid var(--border);
  background: linear-gradient(135deg, #f8f9fa, #e9ecef);
  border-radius: 16px 16px 0 0;
}

.modal-header h2 {
  margin: 0;
  color: var(--blue);
  font-size: 24px;
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: 10px;
}

.modal-body {
  padding: 30px;
}

/* FORMS */
.form-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 20px;
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: 8px;
  position: relative;
}

.form-group.full-width {
  grid-column: 1 / -1;
}

label {
  font-weight: 600;
  color: var(--text);
  font-size: 14px;
  display: flex;
  align-items: center;
  gap: 5px;
}

label.required::after {
  content: "*";
  color: var(--red);
  font-size: 16px;
}

input, select, textarea {
  width: 100%;
  padding: 14px;
  border: 2px solid var(--border);
  border-radius: 8px;
  font-size: 15px;
  transition: all 0.3s ease;
  font-family: inherit;
  background: #fafafa;
}

input:focus, select:focus, textarea:focus {
  outline: none;
  border-color: var(--blue);
  box-shadow: 0 0 0 3px rgba(0, 114, 206, 0.1);
  background: white;
  transform: translateY(-1px);
}

textarea {
  resize: vertical;
  min-height: 100px;
}

.save-btn {
  grid-column: 1 / -1;
  background: linear-gradient(135deg, var(--green), #006f33);
  color: white;
  border: none;
  padding: 16px;
  border-radius: 8px;
  font-weight: 600;
  font-size: 16px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  transition: all 0.3s ease;
  margin-top: 10px;
  position: relative;
  overflow: hidden;
}

.save-btn:hover {
  background: linear-gradient(135deg, #006f33, #005a29);
  transform: translateY(-2px);
  box-shadow: 0 4px 15px rgba(0, 132, 61, 0.3);
}

.save-btn:active {
  transform: translateY(0);
}

.save-btn::after {
  content: '';
  position: absolute;
  top: 50%;
  left: 50%;
  width: 0;
  height: 0;
  border-radius: 50%;
  background: rgba(255,255,255,0.2);
  transform: translate(-50%, -50%);
  transition: width 0.6s, height 0.6s;
}

.save-btn:hover::after {
  width: 300px;
  height: 300px;
}

/* PHOTO PREVIEW */
.photo-upload-section {
  grid-column: 1 / -1;
  background: #f8f9fa;
  padding: 20px;
  border-radius: 12px;
  border: 2px dashed #ddd;
  transition: all 0.3s ease;
}

.photo-upload-section:hover {
  border-color: var(--blue);
  background: #f0f7ff;
}

.current-photo-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 15px;
  margin-bottom: 20px;
}

.current-photo-label {
  font-weight: 600;
  color: var(--text);
  font-size: 15px;
}

.current-photo {
  width: 120px;
  height: 120px;
  border-radius: 50%;
  object-fit: cover;
  border: 4px solid white;
  box-shadow: 0 5px 15px rgba(0,0,0,0.1);
  transition: all 0.3s ease;
}

.current-photo:hover {
  transform: scale(1.05);
  box-shadow: 0 8px 25px rgba(0,0,0,0.15);
}

.photo-preview-container {
  margin-top: 15px;
  text-align: center;
  animation: fadeIn 0.5s ease;
}

.photo-preview-container img {
  width: 100px;
  height: 100px;
  border-radius: 50%;
  object-fit: cover;
  border: 3px solid #ddd;
  margin-top: 10px;
  transition: all 0.3s ease;
}

.photo-preview-container img:hover {
  transform: scale(1.05);
  border-color: var(--blue);
}

/* ALERT POPUP */
.alert-popup {
  display: none;
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  background: white;
  padding: 30px;
  border-radius: 16px;
  text-align: center;
  box-shadow: 0 20px 60px rgba(0,0,0,0.3);
  z-index: 10000;
  width: 400px;
  animation: alertSlideIn 0.4s ease;
}

@keyframes alertSlideIn {
  from {
    opacity: 0;
    transform: translate(-50%, -60%) scale(0.9);
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
  border-top: 4px solid var(--green);
}

.alert-popup.error {
  border-top: 4px solid var(--red);
}

.alert-popup h3 {
  font-size: 17px;
  color: var(--text);
  margin-bottom: 25px;
  line-height: 1.5;
  font-weight: 600;
}

.alert-btn {
  background: var(--blue);
  color: white;
  border: none;
  padding: 12px 30px;
  border-radius: 8px;
  font-weight: 600;
  font-size: 15px;
  cursor: pointer;
  transition: all 0.3s ease;
}

.alert-btn:hover {
  background: #005bb5;
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 114, 206, 0.3);
}

/* VIEW CHILDREN MODAL */
.children-modal-content {
  max-height: 500px;
  overflow-y: auto;
  padding: 20px;
}

.child-card {
  background: white;
  border-radius: 12px;
  padding: 20px;
  margin-bottom: 15px;
  border-left: 4px solid var(--blue);
  box-shadow: 0 3px 10px rgba(0,0,0,0.05);
  transition: all 0.3s ease;
  position: relative;
  overflow: hidden;
}

.child-card:hover {
  transform: translateY(-3px);
  box-shadow: 0 5px 20px rgba(0,0,0,0.1);
}

.child-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 15px;
}

.child-name-large {
  font-size: 18px;
  font-weight: 700;
  color: var(--text);
  margin: 0 0 10px 0;
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.child-meta {
  display: flex;
  gap: 20px;
  font-size: 13px;
  color: var(--text-light);
  margin-bottom: 10px;
  flex-wrap: wrap;
}

.child-meta span {
  display: flex;
  align-items: center;
  gap: 5px;
}

.primary-badge {
  background: var(--green);
  color: white;
  padding: 4px 10px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 600;
  animation: pulse 2s infinite;
}

.child-campus {
  background: #e3f2fd;
  color: #1976d2;
  padding: 6px 12px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 500;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  white-space: nowrap;
}

/* DELETE MODAL */
.delete-warning {
  background: #fff3e0;
  border-radius: 12px;
  padding: 20px;
  margin-bottom: 25px;
  border-left: 4px solid #ff9800;
  animation: shake 0.5s ease;
}

@keyframes shake {
  0%, 100% { transform: translateX(0); }
  25% { transform: translateX(-5px); }
  75% { transform: translateX(5px); }
}

.delete-warning ul {
  list-style: none;
  padding: 0;
  margin: 15px 0 0 0;
}

.delete-warning li {
  padding: 8px 0;
  color: var(--text-light);
  font-size: 14px;
  display: flex;
  align-items: center;
  gap: 10px;
}

.delete-warning li:before {
  content: "⚠";
  color: #ff9800;
  font-size: 16px;
  font-weight: bold;
}

.delete-actions {
  display: flex;
  gap: 12px;
  justify-content: flex-end;
}

.delete-cancel {
  background: #f8f9fa;
  color: var(--text);
  border: 2px solid #ddd;
}

.delete-confirm {
  background: linear-gradient(135deg, var(--red), #b71c1c);
  color: white;
  border: none;
}

.delete-cancel:hover, .delete-confirm:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}

/* RESPONSIVE */
@media (max-width: 768px) {
  .main-content {
    margin-top: 60px;
    padding: 15px;
  }
  
  .top-bar {
    flex-direction: column;
    gap: 15px;
    align-items: flex-start;
  }
  
  .stats-container {
    grid-template-columns: 1fr;
  }
  
  .form-grid {
    grid-template-columns: 1fr;
  }
  
  .search-row {
    flex-direction: column;
  }
  
  .search-row input[type="text"] {
    min-width: 100%;
  }
  
  .campus-filter-row {
    flex-direction: column;
    align-items: flex-start;
  }
  
  .campus-filter-row select {
    width: 100%;
  }
  
  .modal-content {
    margin: 10px;
    max-height: 80vh;
  }
  
  .modal-body {
    padding: 20px;
  }
  
  .alert-popup {
    width: 90%;
    max-width: 350px;
  }
  
  .child-details {
    flex-direction: column;
    gap: 5px;
  }
  
  .action-buttons {
    flex-direction: column;
    gap: 5px;
  }
  
  .btn-action {
    width: 35px;
    height: 35px;
  }
}

/* SCROLLBAR STYLING */
::-webkit-scrollbar {
  width: 8px;
  height: 8px;
}

::-webkit-scrollbar-track {
  background: #f1f1f1;
  border-radius: 4px;
}

::-webkit-scrollbar-thumb {
  background: #c1c1c1;
  border-radius: 4px;
}

::-webkit-scrollbar-thumb:hover {
  background: #a8a8a8;
}

.table-wrapper::-webkit-scrollbar-track {
  background: #f8f9fa;
}

.table-wrapper::-webkit-scrollbar-thumb {
  background: var(--blue);
}

.modal-content::-webkit-scrollbar-track {
  background: #f8f9fa;
}

.modal-content::-webkit-scrollbar-thumb {
  background: var(--dark-gray);
}

/* PRINT STYLES */
@media print {
  .top-bar, .stats-container, .filters-section, .action-buttons, .modal {
    display: none !important;
  }
  
  .table-container {
    box-shadow: none !important;
    border: 1px solid #ddd;
  }
  
  body {
    background: white !important;
  }
  
  .main-content {
    margin: 0 !important;
    padding: 0 !important;
  }
  
  table {
    min-width: auto !important;
  }
  
  .children-list {
    max-height: none !important;
  }
}

/* LOADING ANIMATION */
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

/* TOOLTIPS */
[title] {
  position: relative;
}

[title]:hover::after {
  content: attr(title);
  position: absolute;
  bottom: 100%;
  left: 50%;
  transform: translateX(-50%);
  background: #333;
  color: white;
  padding: 5px 10px;
  border-radius: 4px;
  font-size: 12px;
  white-space: nowrap;
  z-index: 1000;
}

[title]:hover::before {
  content: '';
  position: absolute;
  bottom: 100%;
  left: 50%;
  transform: translateX(-50%);
  border: 5px solid transparent;
  border-top-color: #333;
  margin-bottom: -5px;
}
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
  <!-- TOP BAR -->
  <div class="top-bar">
    <h1><i class="fas fa-users"></i> Parents Management</h1>
    <button class="add-btn" onclick="openModal('addModal')">
      <i class="fas fa-plus"></i> Add Parent
    </button>
  </div>

  <!-- STATISTICS -->
  <div class="stats-container">
    <div class="stat-card">
      <div class="stat-icon blue">
        <i class="fas fa-users"></i>
      </div>
      <div class="stat-info">
        <h3><?= count($parents) ?></h3>
        <p>Total Parents</p>
      </div>
    </div>
    
    <div class="stat-card">
      <div class="stat-icon green">
        <i class="fas fa-user-check"></i>
      </div>
      <div class="stat-info">
        <h3><?= count(array_filter($parents, fn($p) => ($p['status'] ?? 'active') == 'active')) ?></h3>
        <p>Active Parents</p>
      </div>
    </div>
    
    
    
    <div class="stat-card">
      <div class="stat-icon purple">
        <i class="fas fa-link"></i>
      </div>
      <div class="stat-info">
        <h3><?= count(array_filter($parents, fn($p) => !empty($relations[$p['parent_id']] ?? []))) ?></h3>
        <p>Parents with Children</p>
      </div>
    </div>
  </div>

  <!-- FILTERS SECTION -->
  <div class="filters-section">
    <!-- CAMPUS INFO -->
    <?php if($role === 'campus_admin' && $user_campus_id): ?>
    <div class="campus-info">
      <i class="fas fa-university"></i>
      <div>
        <strong>Current Campus:</strong> 
        <?= htmlspecialchars($_SESSION['user']['campus_name'] ?? 'Your Campus') ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- CAMPUS FILTER (only for super_admin) -->
    <?php if($role === 'super_admin' && !empty($campuses)): ?>
    <div class="campus-filter-row">
      <label><i class="fas fa-filter"></i> Filter by Campus:</label>
      <select name="campus" onchange="this.form.submit()" form="searchForm">
        <option value="all" <?= $selected_campus=='all'?'selected':'' ?>>All Campuses</option>
        <?php foreach($campuses as $c): ?>
        <option value="<?= $c['campus_id'] ?>" <?= $selected_campus==$c['campus_id']?'selected':'' ?>>
          <?= htmlspecialchars($c['campus_name']) ?> (<?= $c['campus_code'] ?>)
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <!-- SEARCH BAR -->
    <form method="GET" id="searchForm">
      <div class="search-row">
        <input type="text" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" 
               placeholder="🔍 Search by name, phone, or email...">
        <button type="submit" class="search-btn">
          <i class="fas fa-search"></i> Search
        </button>
        <?php if(!empty($_GET)): ?>
        <a href="parents.php" class="clear-btn">
          <i class="fas fa-times"></i> Clear Filters
        </a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <!-- TABLE -->
  <div class="table-container">
    <div class="table-header">
      <h2>Parents List</h2>
      <div class="record-count"><?= count($parents) ?> records</div>
    </div>
    
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Photo</th>
            <th>Full Name</th>
            <?php if($role === 'super_admin'): ?><th>Campus</th><?php endif; ?>
            <th>Gender</th>
            <th>Phone</th>
            <th>Email</th>
            <th>Occupation</th>
            <th>Status</th>
            <th>Children</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if(!empty($parents)): ?>
            <?php foreach($parents as $i=>$p): ?>
            <tr>
              <td><strong><?= $i+1 ?></strong></td>
              <td>
                <div class="profile-photo">
                  <?php if(!empty($p['display_photo'])): ?>
                    <img src="../<?= htmlspecialchars($p['display_photo']) ?>" 
                         onerror="this.src='../upload/profiles/default.png'"
                         alt="<?= htmlspecialchars($p['full_name']) ?>">
                  <?php else: ?>
                    <i class="fas fa-user"></i>
                  <?php endif; ?>
                </div>
              </td>
              <td><strong><?= htmlspecialchars($p['full_name'] ?? '') ?></strong></td>
              
              <?php if($role === 'super_admin'): ?>
              <td>
                <?php if(!empty($p['campus_name'])): ?>
                  <span class="campus-badge primary">
                    <i class="fas fa-university"></i> <?= htmlspecialchars($p['campus_name']) ?>
                  </span>
                <?php else: ?>
                  <span style="color:#999;font-size:13px;">Not assigned</span>
                <?php endif; ?>
              </td>
              <?php endif; ?>
              
              <td><?= ucfirst($p['gender'] ?? '') ?></td>
              <td><?= htmlspecialchars($p['phone'] ?? '') ?></td>
              <td><?= htmlspecialchars($p['email'] ?? '') ?></td>
              <td><?= htmlspecialchars($p['occupation'] ?? '') ?></td>
              <td>
                <span class="status-badge status-<?= $p['status'] ?? 'active' ?>">
                  <?= ucfirst($p['status'] ?? 'active') ?>
                </span>
              </td>
              <td class="children-container">
                <?php if(!empty($relations[$p['parent_id']])): ?>
                  <ul class="children-list">
                    <?php 
                    $displayed = 0;
                    $parent_relations = $relations[$p['parent_id']] ?? [];
                    foreach($parent_relations as $child): 
                      if ($role === 'campus_admin' && $user_campus_id && ($child['student_campus_id'] ?? 0) != $user_campus_id) continue;
                      if ($displayed < 2): 
                        $displayed++;
                    ?>
                    <li class="child-item">
                      <i class="fas fa-child child-icon"></i>
                      <div class="child-info">
                        <div class="child-name">
                          <?= htmlspecialchars($child['student_name'] ?? '') ?>
                          <?php if(!empty($child['student_campus_name'])): ?>
                            <span class="campus-badge">
                              <?= htmlspecialchars($child['student_campus_name']) ?>
                            </span>
                          <?php endif; ?>
                        </div>
                        <div class="child-details">
                          <span title="Relation">
                            <i class="fas fa-link"></i> <?= htmlspecialchars($child['relation_type'] ?? '') ?>
                          </span>
                          <span title="Registration">
                            <i class="fas fa-id-card"></i> <?= htmlspecialchars($child['reg_no'] ?? '') ?>
                          </span>
                          <span style="color:<?= ($child['student_status'] ?? 'active')=='active'?'var(--green)':'var(--red)' ?>">
                            <i class="fas fa-circle"></i> <?= ucfirst($child['student_status'] ?? 'active') ?>
                          </span>
                        </div>
                      </div>
                    </li>
                    <?php endif; endforeach; ?>
                    
                    <?php 
                    $total_visible = 0;
                    foreach($parent_relations as $child) {
                      if ($role !== 'campus_admin' || !$user_campus_id || ($child['student_campus_id'] ?? 0) == $user_campus_id) {
                        $total_visible++;
                      }
                    }
                    if ($total_visible > 2): 
                    ?>
                    <li>
                      <button class="view-more-btn" onclick="viewAllChildren(<?= $p['parent_id'] ?>)">
                        +<?= $total_visible - 2 ?> more children
                      </button>
                    </li>
                    <?php endif; ?>
                  </ul>
                <?php else: ?>
                  <span style="color:#999;font-size:13px;">No children assigned</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="action-buttons">
                  <button class="btn-action btn-edit" onclick="editParent(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)">
                    <i class="fas fa-edit"></i>
                  </button>
                  <button class="btn-action btn-delete" onclick="openDeleteModal(<?= $p['parent_id'] ?>)">
                    <i class="fas fa-trash"></i>
                  </button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="<?= $role === 'super_admin' ? '11' : '10' ?>">
                <div class="empty-state">
                  <i class="fas fa-users"></i>
                  <h3>No Parents Found</h3>
                  <p>Add your first parent by clicking the "Add Parent" button</p>
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
    <button class="close-modal" onclick="closeModal('addModal')">&times;</button>
    <div class="modal-header">
      <h2><i class="fas fa-user-plus"></i> Add New Parent</h2>
    </div>
    <form method="POST" enctype="multipart/form-data" onsubmit="return validateForm(this)" id="addForm" class="modal-body">
      <input type="hidden" name="action" value="add">
      
      <div class="form-grid">
        <!-- Campus field for super_admin -->
        <?php if($role === 'super_admin' && !empty($campuses)): ?>
        <div class="form-group full-width">
          <label class="required"><i class="fas fa-university"></i> Campus</label>
          <select name="campus_id" required>
            <option value="">Select Campus</option>
            <?php foreach($campuses as $c): ?>
            <option value="<?= $c['campus_id'] ?>"><?= htmlspecialchars($c['campus_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php elseif($user_campus_id): ?>
        <input type="hidden" name="campus_id" value="<?= $user_campus_id ?>">
        <?php endif; ?>
        
        <div class="form-group">
          <label class="required"><i class="fas fa-user"></i> Full Name</label>
          <input type="text" name="full_name" required placeholder="Enter full name">
        </div>
        
        <div class="form-group">
          <label class="required"><i class="fas fa-venus-mars"></i> Gender</label>
          <select name="gender" required>
            <option value="">Select Gender</option>
            <option value="male">Male</option>
            <option value="female">Female</option>
          </select>
        </div>
        
        <div class="form-group">
          <label><i class="fas fa-phone"></i> Phone Number</label>
          <input type="tel" name="phone" placeholder="Enter phone number">
        </div>
        
        <div class="form-group">
          <label><i class="fas fa-envelope"></i> Email Address</label>
          <input type="email" name="email" placeholder="Enter email address">
        </div>
        
        <div class="form-group">
          <label><i class="fas fa-briefcase"></i> Occupation</label>
          <input type="text" name="occupation" placeholder="Enter occupation">
        </div>
        
        <div class="form-group full-width">
          <label><i class="fas fa-map-marker-alt"></i> Address</label>
          <textarea name="address" placeholder="Enter full address"></textarea>
        </div>
        
        <div class="form-group full-width photo-upload-section">
          <label><i class="fas fa-camera"></i> Profile Photo</label>
          <input type="file" name="photo" accept="image/*" id="addPhotoInput" onchange="previewAddPhoto(event)">
          <small style="color:#666;font-size:13px;margin-top:5px;display:block;">
            Max 2MB. JPG, PNG, GIF allowed. Leave empty for default photo.
          </small>
          <div class="photo-preview-container" id="addPhotoPreview" style="display:none;">
            <div style="font-weight:600;margin:15px 0 5px 0;color:#444;">Preview:</div>
            <img id="addPreviewImg">
          </div>
        </div>
        
        <button class="save-btn" type="submit">
          <i class="fas fa-save"></i> Save Parent
        </button>
      </div>
    </form>
  </div>
</div>

<!-- EDIT MODAL -->
<div class="modal" id="editModal">
  <div class="modal-content">
    <button class="close-modal" onclick="closeModal('editModal')">&times;</button>
    <div class="modal-header">
      <h2><i class="fas fa-user-edit"></i> Edit Parent</h2>
    </div>
    <form method="POST" enctype="multipart/form-data" onsubmit="return validateForm(this)" id="editForm" class="modal-body">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="parent_id" id="edit_parent_id">
      <input type="hidden" name="existing_photo" id="edit_existing_photo">
      
      <div class="form-grid">
        <!-- Campus field for super_admin -->
        <?php if($role === 'super_admin' && !empty($campuses)): ?>
        <div class="form-group full-width">
          <label class="required"><i class="fas fa-university"></i> Campus</label>
          <select name="campus_id" id="edit_campus_id" required>
            <option value="">Select Campus</option>
            <?php foreach($campuses as $c): ?>
            <option value="<?= $c['campus_id'] ?>"><?= htmlspecialchars($c['campus_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php elseif($user_campus_id): ?>
        <input type="hidden" name="campus_id" value="<?= $user_campus_id ?>">
        <?php endif; ?>
        
        <div class="form-group">
          <label class="required"><i class="fas fa-user"></i> Full Name</label>
          <input type="text" name="full_name" id="edit_full_name" required>
        </div>
        
        <div class="form-group">
          <label class="required"><i class="fas fa-venus-mars"></i> Gender</label>
          <select name="gender" id="edit_gender" required>
            <option value="">Select Gender</option>
            <option value="male">Male</option>
            <option value="female">Female</option>
          </select>
        </div>
        
        <div class="form-group">
          <label><i class="fas fa-phone"></i> Phone Number</label>
          <input type="tel" name="phone" id="edit_phone">
        </div>
        
        <div class="form-group">
          <label><i class="fas fa-envelope"></i> Email Address</label>
          <input type="email" name="email" id="edit_email">
        </div>
        
        <div class="form-group">
          <label><i class="fas fa-briefcase"></i> Occupation</label>
          <input type="text" name="occupation" id="edit_occupation">
        </div>
        
        <div class="form-group full-width">
          <label><i class="fas fa-map-marker-alt"></i> Address</label>
          <textarea name="address" id="edit_address"></textarea>
        </div>
        
        <div class="form-group">
          <label><i class="fas fa-circle"></i> Status</label>
          <select name="status" id="edit_status">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
        
        <div class="form-group full-width photo-upload-section">
          <div class="current-photo-container">
            <div class="current-photo-label">Current Photo:</div>
            <img id="currentPhotoPreview" class="current-photo" src="../upload/profiles/default.png">
          </div>
          
          <label><i class="fas fa-camera"></i> New Profile Photo</label>
          <input type="file" name="photo" accept="image/*" id="editPhotoInput" onchange="previewEditPhoto(event)">
          <small style="color:#666;font-size:13px;margin-top:5px;display:block;">
            Max 2MB. JPG, PNG, GIF allowed. Leave empty to keep current photo.
          </small>
          <div class="photo-preview-container" id="editPhotoPreview" style="display:none;">
            <div style="font-weight:600;margin:15px 0 5px 0;color:#444;">New Photo Preview:</div>
            <img id="editPreviewImg">
          </div>
        </div>
        
        <button class="save-btn" type="submit">
          <i class="fas fa-sync-alt"></i> Update Parent
        </button>
      </div>
    </form>
  </div>
</div>

<!-- DELETE MODAL -->
<div class="modal" id="deleteModal">
  <div class="modal-content">
    <button class="close-modal" onclick="closeModal('deleteModal')">&times;</button>
    <div class="modal-header">
      <h2 style="color:var(--red)"><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h2>
    </div>
    <div class="modal-body">
      <div class="delete-warning">
        <p style="color:#d84315;font-weight:600;margin:0 0 10px 0;">
          Are you sure you want to delete this parent?
        </p>
        <p style="color:#666;margin:0 0 15px 0;">
          This action will permanently remove the parent and cannot be undone.
        </p>
        <ul>
          <li>Remove the parent record permanently</li>
          <li>Delete the associated user account</li>
          <li>Remove all student associations</li>
          <li>This action cannot be undone</li>
        </ul>
      </div>
      
      <form method="POST">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" id="delete_parent_id" name="parent_id">
        <div class="delete-actions">
          <button type="button" onclick="closeModal('deleteModal')" class="search-btn delete-cancel">
            <i class="fas fa-times"></i> Cancel
          </button>
          <button type="submit" class="search-btn delete-confirm">
            <i class="fas fa-trash"></i> Delete Parent
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- VIEW ALL CHILDREN MODAL -->
<div class="modal" id="viewChildrenModal">
  <div class="modal-content">
    <button class="close-modal" onclick="closeModal('viewChildrenModal')">&times;</button>
    <div class="modal-header">
      <h2><i class="fas fa-child"></i> All Children</h2>
    </div>
    <div class="children-modal-content" id="childrenList">
      <!-- Children will be loaded here -->
    </div>
  </div>
</div>

<!-- ALERT POPUP -->
<div id="popup" class="alert-popup <?= $type ?>">
  <h3><?= htmlspecialchars($message) ?></h3>
  <button class="alert-btn" onclick="closeAlert()">OK</button>
</div>

<script>
// ============================================
// MODAL FUNCTIONS
// ============================================

// Open modal by ID
function openModal(modalId) {
    document.getElementById(modalId).classList.add('show');
    document.body.style.overflow = 'hidden';
    
    // Reset form when opening add modal
    if (modalId === 'addModal') {
        document.getElementById('addForm').reset();
        document.getElementById('addPhotoPreview').style.display = 'none';
    }
}

// Close modal by ID
function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
    document.body.style.overflow = 'auto';
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        if (event.target === modal) {
            modal.classList.remove('show');
            document.body.style.overflow = 'auto';
        }
    });
};

// ============================================
// FORM VALIDATION
// ============================================

function validateForm(form) {
    const requiredFields = form.querySelectorAll('[required]');
    let isValid = true;
    
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            field.style.borderColor = 'var(--red)';
            field.style.boxShadow = '0 0 0 3px rgba(198, 40, 40, 0.1)';
            isValid = false;
        } else {
            field.style.borderColor = '';
            field.style.boxShadow = '';
        }
    });
    
    // Validate email if provided
    const emailField = form.querySelector('input[type="email"]');
    if (emailField && emailField.value.trim()) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(emailField.value.trim())) {
            emailField.style.borderColor = 'var(--red)';
            emailField.style.boxShadow = '0 0 0 3px rgba(198, 40, 40, 0.1)';
            showAlert('Please enter a valid email address', 'error');
            isValid = false;
        }
    }
    
    // Validate phone if provided
    const phoneField = form.querySelector('input[type="tel"]');
    if (phoneField && phoneField.value.trim()) {
        const phoneRegex = /^[\d\s\-\+\(\)]{8,20}$/;
        if (!phoneRegex.test(phoneField.value.trim())) {
            phoneField.style.borderColor = 'var(--red)';
            phoneField.style.boxShadow = '0 0 0 3px rgba(198, 40, 40, 0.1)';
            showAlert('Please enter a valid phone number', 'error');
            isValid = false;
        }
    }
    
    // Validate file size if uploading photo
    const photoInput = form.querySelector('input[type="file"]');
    if (photoInput && photoInput.files.length > 0) {
        const file = photoInput.files[0];
        const maxSize = 2 * 1024 * 1024; // 2MB
        
        if (file.size > maxSize) {
            showAlert('Image size should be less than 2MB', 'error');
            isValid = false;
        }
        
        const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        if (!validTypes.includes(file.type)) {
            showAlert('Only JPG, PNG, and GIF images are allowed', 'error');
            isValid = false;
        }
    }
    
    if (!isValid) {
        showAlert('Please fill in all required fields correctly', 'error');
        return false;
    }
    
    // Show loading state
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<span class="loading"></span> Processing...';
    submitBtn.disabled = true;
    
    // Allow form submission
    setTimeout(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }, 2000);
    
    return true;
}

// ============================================
// EDIT PARENT FUNCTION
// ============================================

function editParent(parentData) {
    // Fill form with existing data
    document.getElementById('edit_parent_id').value = parentData.parent_id;
    document.getElementById('edit_full_name').value = parentData.full_name || '';
    document.getElementById('edit_gender').value = parentData.gender || '';
    document.getElementById('edit_phone').value = parentData.phone || '';
    document.getElementById('edit_email').value = parentData.email || '';
    document.getElementById('edit_occupation').value = parentData.occupation || '';
    document.getElementById('edit_address').value = parentData.address || '';
    document.getElementById('edit_status').value = parentData.status || 'active';
    
    // Handle campus for super_admin
    const campusSelect = document.getElementById('edit_campus_id');
    if (campusSelect) {
        campusSelect.value = parentData.campus_id || '';
    }
    
    // Set existing photo and preview
    const existingPhoto = parentData.display_photo || 'upload/profiles/default.png';
    document.getElementById('edit_existing_photo').value = existingPhoto;
    
    const currentPhotoPreview = document.getElementById('currentPhotoPreview');
    currentPhotoPreview.src = '../' + existingPhoto;
    currentPhotoPreview.onerror = function() {
        this.src = '../upload/profiles/default.png';
    };
    
    // Clear any previous photo preview
    document.getElementById('editPhotoPreview').style.display = 'none';
    document.getElementById('editPhotoInput').value = '';
    
    // Open edit modal
    openModal('editModal');
}

// ============================================
// DELETE PARENT FUNCTION
// ============================================

function openDeleteModal(parentId) {
    document.getElementById('delete_parent_id').value = parentId;
    openModal('deleteModal');
}

// ============================================
// VIEW CHILDREN FUNCTIONS
// ============================================

function viewAllChildren(parentId) {
    // In a real application, you would fetch children data via AJAX
    // For now, we'll just show a message
    document.getElementById('childrenList').innerHTML = `
        <div style="text-align: center; padding: 40px 20px;">
            <i class="fas fa-child" style="font-size: 48px; color: var(--blue); margin-bottom: 20px;"></i>
            <h3 style="color: var(--text); margin-bottom: 10px;">Children Data</h3>
            <p style="color: var(--text-light);">This would show all children for parent ID: ${parentId}</p>
            <p style="color: #999; font-size: 14px; margin-top: 20px;">
                <i>Note: In a real implementation, this would fetch data via AJAX</i>
            </p>
        </div>
    `;
    openModal('viewChildrenModal');
}

// ============================================
// PHOTO PREVIEW FUNCTIONS
// ============================================

function previewAddPhoto(event) {
    const input = event.target;
    const preview = document.getElementById('addPreviewImg');
    const previewContainer = document.getElementById('addPhotoPreview');
    
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        
        reader.onload = function(e) {
            preview.src = e.target.result;
            previewContainer.style.display = 'block';
        }
        
        reader.readAsDataURL(input.files[0]);
    } else {
        previewContainer.style.display = 'none';
    }
}

function previewEditPhoto(event) {
    const input = event.target;
    const preview = document.getElementById('editPreviewImg');
    const previewContainer = document.getElementById('editPhotoPreview');
    
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        
        reader.onload = function(e) {
            preview.src = e.target.result;
            previewContainer.style.display = 'block';
        }
        
        reader.readAsDataURL(input.files[0]);
    } else {
        previewContainer.style.display = 'none';
    }
}

// ============================================
// ALERT POPUP FUNCTIONS
// ============================================

function showAlert(message, type = 'success') {
    const popup = document.getElementById('popup');
    const messageElement = popup.querySelector('h3');
    
    popup.className = `alert-popup ${type} show`;
    messageElement.textContent = message;
    
    // Auto-close success messages after 5 seconds
    if (type === 'success') {
        setTimeout(closeAlert, 5000);
    }
}

function closeAlert() {
    document.getElementById('popup').classList.remove('show');
}

// ============================================
// INITIALIZATION
// ============================================

document.addEventListener('DOMContentLoaded', function() {
    // Show alert if there's a message
    <?php if(!empty($message)): ?>
    showAlert("<?= addslashes($message) ?>", "<?= $type ?>");
    <?php endif; ?>
    
    // Prevent form resubmission on refresh
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }
    
    // Add keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // ESC to close modals
        if (e.key === 'Escape') {
            const openModals = document.querySelectorAll('.modal.show');
            openModals.forEach(modal => {
                const modalId = modal.id;
                closeModal(modalId);
            });
        }
        
        // Ctrl+F to focus search
        if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            const searchInput = document.querySelector('input[name="q"]');
            if (searchInput) {
                searchInput.focus();
            }
        }
    });
    
    // Add confirmation for delete
    const deleteForm = document.querySelector('#deleteModal form');
    if (deleteForm) {
        deleteForm.addEventListener('submit', function(e) {
            const confirmBtn = this.querySelector('.delete-confirm');
            const originalText = confirmBtn.innerHTML;
            
            confirmBtn.innerHTML = '<span class="loading"></span> Deleting...';
            confirmBtn.disabled = true;
            
            // Re-enable after 3 seconds if form doesn't submit
            setTimeout(() => {
                confirmBtn.innerHTML = originalText;
                confirmBtn.disabled = false;
            }, 3000);
        });
    }
    
    // Add form submission handlers
    const addForm = document.getElementById('addForm');
    if (addForm) {
        addForm.addEventListener('submit', function(e) {
            if (!validateForm(this)) {
                e.preventDefault();
                return false;
            }
        });
    }
    
    const editForm = document.getElementById('editForm');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            if (!validateForm(this)) {
                e.preventDefault();
                return false;
            }
        });
    }
});
</script>

<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>