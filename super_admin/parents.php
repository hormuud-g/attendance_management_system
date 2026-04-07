<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Access Control
if (!isset($_SESSION['user']) || !in_array(strtolower($_SESSION['user']['role']), ['super_admin','admin'])) {
  header("Location: ../login.php");
  exit;
}

$message = "";
$type = "";

// Function to capitalize first letter of each word
function capitalizeName($name) {
  return ucwords(strtolower(trim($name)));
}

/* ============================================================
   CRUD OPERATIONS
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // 🟢 ADD PARENT
  if ($_POST['action'] === 'add') {
    try {
      $full_name = capitalizeName($_POST['full_name']);
      $email = trim($_POST['email']);
      $phone = trim($_POST['phone']);
      
      // Check if email exists
      if (!empty($email)) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM parents WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetchColumn() > 0) {
          throw new Exception("Email already exists!");
        }
      }
      
      // Check if phone exists
      if (!empty($phone)) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM parents WHERE phone = ?");
        $check->execute([$phone]);
        if ($check->fetchColumn() > 0) {
          throw new Exception("Phone number already exists!");
        }
      }

      // Handle photo upload
      $photo_path = 'upload/profiles/default.png';
      if (!empty($_FILES['photo']['name'])) {
        $upload_dir = __DIR__ . '/../upload/profiles/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
        $file_type = mime_content_type($_FILES['photo']['tmp_name']);
        $file_size = $_FILES['photo']['size'];
        
        if (!in_array($file_type, $allowed_types)) {
          throw new Exception("Invalid image format. Only JPG, PNG, GIF allowed.");
        }
        
        if ($file_size > 2 * 1024 * 1024) {
          throw new Exception("Image size too large. Max 2MB allowed.");
        }
        
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $new_name = 'parent_' . time() . '_' . uniqid() . '.' . $ext;
        $photo_path = 'upload/profiles/' . $new_name;
        
        if (!move_uploaded_file($_FILES['photo']['tmp_name'], __DIR__ . '/../' . $photo_path)) {
          throw new Exception("Failed to upload image.");
        }
      }

      // Insert into parents table
      $stmt = $pdo->prepare("INSERT INTO parents 
        (full_name, gender, phone, email, address, occupation, photo_path, status, created_at)
        VALUES (?,?,?,?,?,?,?, 'active', NOW())");
      $stmt->execute([
        $full_name,
        $_POST['gender'],
        $phone,
        $email,
        $_POST['address'],
        $_POST['occupation'],
        $photo_path
      ]);

      $parent_id = $pdo->lastInsertId();

      // Auto-create user account
      $plain_pass = "123";
      $hashed = password_hash($plain_pass, PASSWORD_BCRYPT);
      
      $user = $pdo->prepare("INSERT INTO users 
        (user_uuid, username, email, phone_number, profile_photo_path, password, password_plain, role, linked_id, linked_table, status, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?, NOW())");
      $user->execute([
        uniqid('PAR_'),
        $full_name,
        $email,
        $phone,
        $photo_path,
        $hashed,
        $plain_pass,
        'parent',
        $parent_id,
        'parents',
        'active'
      ]);

      $_SESSION['alert_message'] = "✅ Parent added successfully! Default password: 123";
      $_SESSION['alert_type'] = "success";
      
      header("Location: parents.php");
      exit;
      
    } catch (Exception $e) {
      $_SESSION['alert_message'] = "❌ " . $e->getMessage();
      $_SESSION['alert_type'] = "error";
      header("Location: parents.php");
      exit;
    }
  }

  // 🟡 UPDATE PARENT
  if ($_POST['action'] === 'update') {
    try {
      $id = $_POST['parent_id'];
      $full_name = capitalizeName($_POST['full_name']);
      $email = trim($_POST['email']);
      $phone = trim($_POST['phone']);
      
      // Check if email exists for OTHER parents (excluding current one)
      if (!empty($email)) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM parents WHERE email = ? AND parent_id != ?");
        $check->execute([$email, $id]);
        if ($check->fetchColumn() > 0) {
          throw new Exception("Email already exists for another parent!");
        }
      }
      
      // Check if phone exists for OTHER parents (excluding current one)
      if (!empty($phone)) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM parents WHERE phone = ? AND parent_id != ?");
        $check->execute([$phone, $id]);
        if ($check->fetchColumn() > 0) {
          throw new Exception("Phone number already exists for another parent!");
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
        
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
        $file_type = mime_content_type($_FILES['photo']['tmp_name']);
        $file_size = $_FILES['photo']['size'];
        
        if (!in_array($file_type, $allowed_types)) {
          throw new Exception("Invalid image format. Only JPG, PNG, GIF allowed.");
        }
        
        if ($file_size > 2 * 1024 * 1024) {
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

      // Update parents table
      $updateStmt = $pdo->prepare("UPDATE parents 
        SET full_name=?, gender=?, phone=?, email=?, address=?, occupation=?, status=?, photo_path=?
        WHERE parent_id=?");
      $updateResult = $updateStmt->execute([
        $full_name, 
        $_POST['gender'], 
        $phone,
        $email, 
        $_POST['address'], 
        $_POST['occupation'],
        $_POST['status'], 
        $photo_path,
        $id
      ]);

      if (!$updateResult) {
        throw new Exception("Failed to update parent record.");
      }

      // Update user account
      $userUpdate = $pdo->prepare("UPDATE users 
        SET username=?, email=?, phone_number=?, profile_photo_path=?, status=? 
        WHERE linked_id=? AND linked_table='parents'");
      $userResult = $userUpdate->execute([
        $full_name, 
        $email, 
        $phone,
        $photo_path,
        $_POST['status'], 
        $id
      ]);

      if (!$userResult) {
        throw new Exception("Failed to update user account.");
      }

      $_SESSION['alert_message'] = "✅ Parent updated successfully!";
      $_SESSION['alert_type'] = "success";
      
      header("Location: parents.php");
      exit;
      
    } catch (Exception $e) {
      $_SESSION['alert_message'] = "❌ " . $e->getMessage();
      $_SESSION['alert_type'] = "error";
      header("Location: parents.php");
      exit;
    }
  }

  // 🔴 DELETE PARENT
  if ($_POST['action'] === 'delete') {
    try {
      $id = $_POST['parent_id'];
      
      // Get photo path before deleting
      $stmt = $pdo->prepare("SELECT photo_path FROM parents WHERE parent_id = ?");
      $stmt->execute([$id]);
      $photo = $stmt->fetch(PDO::FETCH_ASSOC);
      
      // Delete photo if it's not default
      if ($photo && $photo['photo_path'] && $photo['photo_path'] !== 'upload/profiles/default.png' && file_exists(__DIR__ . '/../' . $photo['photo_path'])) {
        unlink(__DIR__ . '/../' . $photo['photo_path']);
      }
      
      // Delete in correct order
      $pdo->prepare("DELETE FROM parent_student WHERE parent_id=?")->execute([$id]);
      $pdo->prepare("DELETE FROM users WHERE linked_id=? AND linked_table='parents'")->execute([$id]);
      $pdo->prepare("DELETE FROM parents WHERE parent_id=?")->execute([$id]);
      
      $_SESSION['alert_message'] = "✅ Parent deleted successfully!";
      $_SESSION['alert_type'] = "success";
      
      header("Location: parents.php");
      exit;
      
    } catch (Exception $e) {
      $_SESSION['alert_message'] = "❌ " . $e->getMessage();
      $_SESSION['alert_type'] = "error";
      header("Location: parents.php");
      exit;
    }
  }
}

// ✅ Check for session alerts
if (isset($_SESSION['alert_message'])) {
  $message = $_SESSION['alert_message'];
  $type = $_SESSION['alert_type'];
  unset($_SESSION['alert_message']);
  unset($_SESSION['alert_type']);
}

/* ============================================================
   FETCH PARENTS + RELATIONS
============================================================ */
$search = trim($_GET['q'] ?? '');
$sql = "SELECT *, 
        CASE 
          WHEN photo_path IS NULL OR photo_path = '' THEN 'upload/profiles/default.png'
          WHEN photo_path NOT LIKE 'upload/profiles/%' THEN CONCAT('upload/profiles/', photo_path)
          ELSE photo_path 
        END as display_photo
        FROM parents";

if ($search !== '') {
  $sql .= " WHERE full_name LIKE :q OR phone LIKE :q OR email LIKE :q";
  $stmt = $pdo->prepare($sql);
  $stmt->execute(['q' => "%$search%"]);
  $parents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
  $sql .= " ORDER BY parent_id DESC";
  $parents = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

// Fix photo paths and capitalize names
foreach ($parents as &$parent) {
  if (empty($parent['display_photo'])) {
    $parent['display_photo'] = 'upload/profiles/default.png';
  }
  $parent['full_name'] = capitalizeName($parent['full_name']);
}

// Fetch related students
$relations = [];
$stmt = $pdo->query("
  SELECT ps.parent_id, s.student_id, s.full_name AS student_name, s.reg_no, s.status AS student_status, ps.relation_type
  FROM parent_student ps
  JOIN parents p ON p.parent_id = ps.parent_id
  JOIN students s ON s.student_id = ps.student_id
  ORDER BY p.parent_id, s.full_name
");
while($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $relations[$r['parent_id']][] = $r;
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
:root {
  --green: #00843D;
  --blue: #0072CE;
  --red: #C62828;
  --bg: #F5F9F7;
  --light-gray: #f8f9fa;
  --dark-gray: #6c757d;
}

* {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
}

body {
  font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
  background: var(--bg);
  color: #333;
  line-height: 1.6;
}

.main-content {
  padding: 25px;
  margin-top: 80px;
  margin-left: 250px;
  transition: all 0.3s ease;
}

.sidebar.collapsed ~ .main-content {
  margin-left: 70px;
}

.top-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 25px;
  padding-bottom: 15px;
  border-bottom: 2px solid var(--light-gray);
}

.top-bar h1 {
  color: var(--blue);
  font-size: 24px;
  font-weight: 700;
}

.add-btn {
  background: var(--green);
  color: #fff;
  padding: 10px 20px;
  border: none;
  border-radius: 6px;
  font-weight: 600;
  cursor: pointer;
  font-size: 14px;
  display: flex;
  align-items: center;
  gap: 8px;
  transition: all 0.3s ease;
}

.add-btn:hover {
  background: #006f33;
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 132, 61, 0.3);
}

.search-container {
  background: white;
  padding: 20px;
  border-radius: 10px;
  box-shadow: 0 2px 10px rgba(0,0,0,0.05);
  margin-bottom: 25px;
}

.search-bar {
  display: flex;
  align-items: center;
  gap: 12px;
  max-width: 600px;
}

.search-bar input {
  flex: 1;
  padding: 12px 15px;
  border: 1.5px solid #e0e0e0;
  border-radius: 6px;
  font-size: 14px;
  transition: all 0.3s;
}

.search-bar input:focus {
  outline: none;
  border-color: var(--blue);
  box-shadow: 0 0 0 3px rgba(0, 114, 206, 0.1);
}

.search-btn {
  background: var(--blue);
  color: white;
  padding: 12px 20px;
  border: none;
  border-radius: 6px;
  font-weight: 600;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 8px;
  transition: all 0.3s;
}

.search-btn:hover {
  background: #005bb5;
}

.table-container {
  background: #fff;
  border-radius: 10px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.08);
  overflow: hidden;
  margin-top: 20px;
}

.table-responsive {
  overflow-x: auto;
  max-height: 600px;
  border-radius: 10px;
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
  background: var(--blue);
  color: white;
  padding: 15px 20px;
  text-align: left;
  font-weight: 600;
  font-size: 14px;
  border: none;
  white-space: nowrap;
}

tbody tr {
  border-bottom: 1px solid #f0f0f0;
  transition: all 0.3s;
}

tbody tr:hover {
  background: rgba(0, 114, 206, 0.05);
}

tbody td {
  padding: 15px 20px;
  font-size: 14px;
  color: #555;
  vertical-align: middle;
  border: none;
}

.status-badge {
  padding: 5px 12px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.status-active {
  background: rgba(0, 132, 61, 0.1);
  color: var(--green);
}

.status-inactive {
  background: rgba(198, 40, 40, 0.1);
  color: var(--red);
}

.profile-photo-container {
  position: relative;
  width: 50px;
  height: 50px;
}

.profile-photo {
  width: 50px;
  height: 50px;
  border-radius: 50%;
  object-fit: cover;
  border: 2px solid #f0f0f0;
  background: #f8f9fa;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #999;
  font-size: 20px;
}

.profile-photo img {
  width: 100%;
  height: 100%;
  border-radius: 50%;
  object-fit: cover;
}

/* First letter avatar style */
.first-letter-avatar {
  width: 50px;
  height: 50px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--blue), #005bb5);
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 24px;
  font-weight: 600;
  text-transform: uppercase;
  border: 2px solid #fff;
  box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}

.children-container {
  max-width: 250px;
}

.children-list {
  list-style: none;
  padding: 0;
  margin: 0;
}

.child-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  margin-bottom: 5px;
  background: #f8f9fa;
  border-radius: 6px;
  border-left: 3px solid var(--blue);
}

.child-icon {
  color: var(--blue);
  font-size: 14px;
}

.child-info {
  flex: 1;
}

.child-name {
  font-weight: 600;
  font-size: 13px;
  color: #333;
}

.child-details {
  font-size: 11px;
  color: var(--dark-gray);
  display: flex;
  gap: 8px;
  margin-top: 2px;
}

.child-details span {
  display: flex;
  align-items: center;
  gap: 3px;
}

.action-buttons {
  display: flex;
  gap: 8px;
}

.btn-action {
  width: 36px;
  height: 36px;
  border-radius: 6px;
  border: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  transition: all 0.3s;
}

.btn-edit {
  background: rgba(0, 114, 206, 0.1);
  color: var(--blue);
}

.btn-edit:hover {
  background: var(--blue);
  color: white;
  transform: translateY(-2px);
}

.btn-delete {
  background: rgba(198, 40, 40, 0.1);
  color: var(--red);
}

.btn-delete:hover {
  background: var(--red);
  color: white;
  transform: translateY(-2px);
}

.modal {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.5);
  justify-content: center;
  align-items: center;
  z-index: 3000;
  padding: 20px;
}

.modal.show {
  display: flex;
  animation: fadeIn 0.3s;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.modal-content {
  background: #fff;
  border-radius: 12px;
  width: 100%;
  max-width: 700px;
  max-height: 90vh;
  overflow-y: auto;
  position: relative;
  box-shadow: 0 10px 30px rgba(0,0,0,0.2);
}

.close-modal {
  position: absolute;
  top: 15px;
  right: 20px;
  font-size: 24px;
  cursor: pointer;
  color: var(--dark-gray);
  background: none;
  border: none;
  width: 30px;
  height: 30px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  transition: all 0.3s;
}

.close-modal:hover {
  background: #f0f0f0;
  color: #333;
}

.modal h2 {
  color: var(--blue);
  margin-bottom: 25px;
  padding-bottom: 15px;
  border-bottom: 2px solid var(--light-gray);
}

form {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 20px;
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.form-group.full-width {
  grid-column: 1 / -1;
}

label {
  font-weight: 600;
  color: #444;
  font-size: 14px;
}

label.required::after {
  content: " *";
  color: var(--red);
}

input, select, textarea {
  width: 100%;
  padding: 12px;
  border: 1.5px solid #e0e0e0;
  border-radius: 6px;
  font-size: 14px;
  transition: all 0.3s;
  font-family: inherit;
}

input:focus, select:focus, textarea:focus {
  outline: none;
  border-color: var(--blue);
  box-shadow: 0 0 0 3px rgba(0, 114, 206, 0.1);
}

textarea {
  resize: vertical;
  min-height: 80px;
}

.save-btn {
  grid-column: 1 / -1;
  background: var(--green);
  color: #fff;
  border: none;
  padding: 14px;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  font-size: 15px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  transition: all 0.3s;
  margin-top: 10px;
}

.save-btn:hover {
  background: #006f33;
  transform: translateY(-2px);
}

.current-photo-container {
  grid-column: 1 / -1;
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 15px;
  background: #f8f9fa;
  border-radius: 8px;
  border: 1px dashed #ddd;
}

.current-photo-label {
  font-weight: 600;
  color: #555;
}

.current-photo {
  width: 80px;
  height: 80px;
  border-radius: 50%;
  object-fit: cover;
  border: 3px solid #fff;
  box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.alert-popup {
  display: none;
  position: fixed;
  top: 20px;
  right: 20px;
  background: #fff;
  padding: 15px 25px;
  border-radius: 8px;
  text-align: center;
  box-shadow: 0 4px 12px rgba(0,0,0,0.15);
  z-index: 5000;
  min-width: 300px;
  animation: slideInRight 0.3s ease;
}

@keyframes slideInRight {
  from {
    transform: translateX(100%);
    opacity: 0;
  }
  to {
    transform: translateX(0);
    opacity: 1;
  }
}

.alert-popup.show {
  display: block;
}

.alert-popup.success {
  border-left: 4px solid var(--green);
}

.alert-popup.error {
  border-left: 4px solid var(--red);
}

.alert-popup .alert-content {
  display: flex;
  align-items: center;
  gap: 12px;
}

.alert-popup i {
  font-size: 20px;
}

.alert-popup.success i {
  color: var(--green);
}

.alert-popup.error i {
  color: var(--red);
}

.alert-popup .alert-message {
  flex: 1;
  font-size: 14px;
  color: #333;
  text-align: left;
}

.alert-close {
  background: none;
  border: none;
  color: #999;
  cursor: pointer;
  font-size: 18px;
  padding: 0 5px;
}

.alert-close:hover {
  color: #333;
}

.empty-state {
  text-align: center;
  padding: 60px 20px;
  color: var(--dark-gray);
}

.empty-state i {
  font-size: 48px;
  color: #ddd;
  margin-bottom: 20px;
}

.empty-state h3 {
  font-size: 18px;
  margin-bottom: 10px;
  color: #666;
}

@media (max-width: 992px) {
  .main-content {
    margin-left: 70px;
    padding: 20px;
  }
  
  form {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 768px) {
  .main-content {
    margin-left: 0;
    margin-top: 60px;
  }
  
  .top-bar {
    flex-direction: column;
    gap: 15px;
    align-items: flex-start;
  }
  
  .search-bar {
    flex-direction: column;
    width: 100%;
  }
  
  .search-bar input,
  .search-btn {
    width: 100%;
  }
  
  .action-buttons {
    flex-wrap: wrap;
  }
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

  <!-- SEARCH -->
  <div class="search-container">
    <form method="GET" class="search-bar">
      <input type="text" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" 
             placeholder="🔍 Search by Name, Phone or Email...">
      <button class="search-btn" type="submit">
        <i class="fas fa-search"></i> Search
      </button>
      <?php if(isset($_GET['q']) && $_GET['q'] !== ''): ?>
        <a href="parents.php" style="background: #f0f0f0; color: #333; padding: 12px 20px; border-radius: 6px; text-decoration: none; font-weight: 600;">
          <i class="fas fa-times"></i> Clear
        </a>
      <?php endif; ?>
    </form>
  </div>

  <!-- ALERT POPUP -->
  <?php if($message): ?>
  <div id="alertPopup" class="alert-popup show <?= $type ?>">
    <div class="alert-content">
      <i class="fas <?= $type == 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
      <span class="alert-message"><?= htmlspecialchars($message) ?></span>
      <button class="alert-close" onclick="closeAlert()">&times;</button>
    </div>
  </div>
  <?php endif; ?>

  <!-- TABLE -->
  <div class="table-container">
    <div class="table-responsive">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Photo</th>
            <th>Full Name</th>
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
                <div class="profile-photo-container">
                  <?php 
                  // Check if photo exists and is not default
                  $photo_file = __DIR__ . '/../' . $p['display_photo'];
                  $is_default = ($p['display_photo'] == 'upload/profiles/default.png' || !file_exists($photo_file));
                  
                  if (!$is_default): 
                  ?>
                    <div class="profile-photo">
                      <img src="../<?= htmlspecialchars($p['display_photo']) ?>" 
                           onerror="this.style.display='none'; this.parentElement.innerHTML='<div class=\'first-letter-avatar\'>'+this.alt.charAt(0).toUpperCase()+'</div>';"
                           alt="<?= htmlspecialchars($p['full_name']) ?>">
                    </div>
                  <?php else: ?>
                    <div class="first-letter-avatar">
                      <?= strtoupper(substr($p['full_name'], 0, 1)) ?>
                    </div>
                  <?php endif; ?>
                </div>
              </td>
              <td><strong><?= htmlspecialchars($p['full_name']) ?></strong></td>
              <td><?= ucfirst($p['gender']) ?></td>
              <td><?= htmlspecialchars($p['phone']) ?></td>
              <td><?= htmlspecialchars($p['email']) ?></td>
              <td><?= htmlspecialchars($p['occupation']) ?></td>
              <td>
                <span class="status-badge status-<?= $p['status'] ?>">
                  <?= ucfirst($p['status']) ?>
                </span>
              </td>
              <td class="children-container">
                <?php if(!empty($relations[$p['parent_id']])): ?>
                  <ul class="children-list">
                    <?php foreach($relations[$p['parent_id']] as $child): ?>
                    <li class="child-item">
                      <i class="fas fa-child child-icon"></i>
                      <div class="child-info">
                        <div class="child-name"><?= htmlspecialchars(capitalizeName($child['student_name'])) ?></div>
                        <div class="child-details">
                          <span title="Relation">
                            <i class="fas fa-link"></i> <?= htmlspecialchars($child['relation_type'] ?? 'Parent') ?>
                          </span>
                          <span title="Registration">
                            <i class="fas fa-id-card"></i> <?= htmlspecialchars($child['reg_no']) ?>
                          </span>
                          <span style="color:<?= ($child['student_status'] ?? 'active') == 'active' ? 'var(--green)' : 'var(--red)' ?>">
                            <i class="fas fa-circle"></i> <?= ucfirst($child['student_status'] ?? 'active') ?>
                          </span>
                        </div>
                      </div>
                    </li>
                    <?php endforeach; ?>
                  </ul>
                <?php else: ?>
                  <span style="color:#999;font-size:13px;">No children assigned</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="action-buttons">
                  <button class="btn-action btn-edit" onclick='editParent(<?= json_encode($p, JSON_HEX_APOS) ?>)'>
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
              <td colspan="10">
                <div class="empty-state">
                  <i class="fas fa-users"></i>
                  <h3>No Parents Found</h3>
                  <p>
                    <?php if(isset($_GET['q']) && $_GET['q'] !== ''): ?>
                      No results for "<?= htmlspecialchars($_GET['q']) ?>". 
                      <a href="parents.php" style="color: var(--blue);">Clear search</a>
                    <?php else: ?>
                      Add your first parent by clicking the "Add Parent" button
                    <?php endif; ?>
                  </p>
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
    <h2><i class="fas fa-user-plus"></i> Add New Parent</h2>
    <form method="POST" enctype="multipart/form-data" onsubmit="return validateForm(this)" id="addForm">
      <input type="hidden" name="action" value="add">
      
      <div class="form-group">
        <label class="required">Full Name</label>
        <input type="text" name="full_name" required placeholder="Enter full name" 
               oninput="this.value = this.value.replace(/[^a-zA-Z\s]/g, '')">
      </div>
      
      <div class="form-group">
        <label class="required">Gender</label>
        <select name="gender" required>
          <option value="">Select Gender</option>
          <option value="male">Male</option>
          <option value="female">Female</option>
        </select>
      </div>
      
      <div class="form-group">
        <label>Phone Number</label>
        <input type="tel" name="phone" placeholder="Enter phone number">
      </div>
      
      <div class="form-group">
        <label>Email Address</label>
        <input type="email" name="email" placeholder="Enter email address">
      </div>
      
      <div class="form-group">
        <label>Occupation</label>
        <input type="text" name="occupation" placeholder="Enter occupation">
      </div>
      
      <div class="form-group full-width">
        <label>Address</label>
        <textarea name="address" placeholder="Enter address"></textarea>
      </div>
      
      <div class="form-group">
        <label>Profile Photo</label>
        <input type="file" name="photo" accept="image/*" id="addPhotoInput" onchange="previewAddPhoto(event)">
        <small style="color:#666;font-size:12px;">Max 2MB. JPG, PNG, GIF allowed.</small>
      </div>
      
      <div class="form-group full-width" id="addPhotoPreview" style="display:none;">
        <label>Photo Preview:</label>
        <img id="addPreviewImg" style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover; border: 3px solid #ddd;">
      </div>
      
      <button class="save-btn" type="submit">
        <i class="fas fa-save"></i> Save Parent
      </button>
    </form>
  </div>
</div>

<!-- EDIT MODAL -->
<div class="modal" id="editModal">
  <div class="modal-content">
    <button class="close-modal" onclick="closeModal('editModal')">&times;</button>
    <h2><i class="fas fa-user-edit"></i> Edit Parent</h2>
    <form method="POST" enctype="multipart/form-data" onsubmit="return validateForm(this)" id="editForm">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="parent_id" id="edit_parent_id">
      
      <div class="form-group">
        <label class="required">Full Name</label>
        <input type="text" name="full_name" id="edit_full_name" required
               oninput="this.value = this.value.replace(/[^a-zA-Z\s]/g, '')">
      </div>
      
      <div class="form-group">
        <label class="required">Gender</label>
        <select name="gender" id="edit_gender" required>
          <option value="">Select Gender</option>
          <option value="male">Male</option>
          <option value="female">Female</option>
        </select>
      </div>
      
      <div class="form-group">
        <label>Phone Number</label>
        <input type="tel" name="phone" id="edit_phone">
      </div>
      
      <div class="form-group">
        <label>Email Address</label>
        <input type="email" name="email" id="edit_email">
      </div>
      
      <div class="form-group">
        <label>Occupation</label>
        <input type="text" name="occupation" id="edit_occupation">
      </div>
      
      <div class="form-group full-width">
        <label>Address</label>
        <textarea name="address" id="edit_address"></textarea>
      </div>
      
      <div class="form-group">
        <label>Status</label>
        <select name="status" id="edit_status">
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
      
      <div class="current-photo-container">
        <div class="current-photo-label">Current Photo:</div>
        <img id="currentPhotoPreview" class="current-photo" src="../upload/profiles/default.png" alt="Current photo">
      </div>
      
      <div class="form-group">
        <label>New Profile Photo</label>
        <input type="file" name="photo" accept="image/*" id="editPhotoInput" onchange="previewEditPhoto(event)">
        <small style="color:#666;font-size:12px;">Leave empty to keep current photo</small>
      </div>
      
      <div class="form-group full-width" id="editPhotoPreview" style="display:none;">
        <label>New Photo Preview:</label>
        <img id="editPreviewImg" style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover; border: 3px solid #ddd;">
      </div>
      
      <button class="save-btn" type="submit">
        <i class="fas fa-sync-alt"></i> Update Parent
      </button>
    </form>
  </div>
</div>

<!-- DELETE MODAL -->
<div class="modal" id="deleteModal">
  <div class="modal-content">
    <button class="close-modal" onclick="closeModal('deleteModal')">&times;</button>
    <h2 style="color:var(--red)"><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h2>
    <div style="padding: 20px 0;">
      <p style="margin-bottom: 20px; font-size: 15px; color: #555;">
        Are you sure you want to delete this parent? This action will:
      </p>
      <ul style="color: #666; margin-left: 20px; margin-bottom: 25px;">
        <li>Remove the parent record permanently</li>
        <li>Delete the associated user account</li>
        <li>Remove all student associations</li>
        <li>This action cannot be undone</li>
      </ul>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" id="delete_parent_id" name="parent_id">
      <div style="display: flex; gap: 12px; justify-content: flex-end;">
        <button type="button" onclick="closeModal('deleteModal')" 
                style="background: #f0f0f0; color: #333; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">
          Cancel
        </button>
        <button type="submit" 
                style="background: var(--red); color: white; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">
          <i class="fas fa-trash"></i> Delete Parent
        </button>
      </div>
    </form>
  </div>
</div>

<script>
// Capitalize function for JavaScript
function capitalizeName(name) {
  if (!name) return '';
  return name.toLowerCase().replace(/(?:^|\s)\S/g, function(a) { 
    return a.toUpperCase(); 
  });
}

// Modal Functions
function openModal(id) {
  document.getElementById(id).classList.add('show');
  document.body.style.overflow = 'hidden';
}

function closeModal(id) {
  document.getElementById(id).classList.remove('show');
  document.body.style.overflow = 'auto';
  
  // Reset forms
  if (id === 'addModal') {
    document.getElementById('addForm').reset();
    document.getElementById('addPhotoPreview').style.display = 'none';
  }
  if (id === 'editModal') {
    document.getElementById('editForm').reset();
    document.getElementById('editPhotoPreview').style.display = 'none';
  }
}

// Edit Parent Function
function editParent(parent) {
  document.getElementById('edit_parent_id').value = parent.parent_id;
  document.getElementById('edit_full_name').value = parent.full_name || '';
  document.getElementById('edit_gender').value = parent.gender || '';
  document.getElementById('edit_phone').value = parent.phone || '';
  document.getElementById('edit_email').value = parent.email || '';
  document.getElementById('edit_address').value = parent.address || '';
  document.getElementById('edit_occupation').value = parent.occupation || '';
  document.getElementById('edit_status').value = parent.status || 'active';
  
  // Show current photo
  let currentPhoto = parent.display_photo || 'upload/profiles/default.png';
  document.getElementById('currentPhotoPreview').src = '../' + currentPhoto;
  
  // Hide new photo preview
  document.getElementById('editPhotoPreview').style.display = 'none';
  
  openModal('editModal');
}

// Delete Parent Function
function openDeleteModal(parentId) {
  document.getElementById('delete_parent_id').value = parentId;
  openModal('deleteModal');
}

// Form Validation
function validateForm(form) {
  const requiredFields = form.querySelectorAll('[required]');
  
  for (let field of requiredFields) {
    if (!field.value.trim()) {
      field.style.borderColor = 'var(--red)';
      field.focus();
      alert('⚠️ Please fill all required fields!');
      return false;
    }
    field.style.borderColor = '#e0e0e0';
  }
  
  // Validate email if provided
  const emailField = form.querySelector('input[type="email"]');
  if (emailField && emailField.value.trim()) {
    const email = emailField.value.trim();
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
      emailField.style.borderColor = 'var(--red)';
      emailField.focus();
      alert('⚠️ Please enter a valid email address!');
      return false;
    }
  }
  
  return true;
}

// Photo Preview Functions
function previewAddPhoto(event) {
  const input = event.target;
  const preview = document.getElementById('addPhotoPreview');
  const img = document.getElementById('addPreviewImg');
  
  if (input.files && input.files[0]) {
    const file = input.files[0];
    
    // Validate file type
    const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
    if (!validTypes.includes(file.type)) {
      alert('⚠️ Please select a valid image file (JPG, PNG, GIF)');
      input.value = '';
      preview.style.display = 'none';
      return;
    }
    
    // Validate file size (2MB)
    if (file.size > 2 * 1024 * 1024) {
      alert('⚠️ Image size must be less than 2MB');
      input.value = '';
      preview.style.display = 'none';
      return;
    }
    
    const reader = new FileReader();
    reader.onload = function(e) {
      img.src = e.target.result;
      preview.style.display = 'block';
    }
    reader.readAsDataURL(file);
  } else {
    preview.style.display = 'none';
  }
}

function previewEditPhoto(event) {
  const input = event.target;
  const preview = document.getElementById('editPhotoPreview');
  const img = document.getElementById('editPreviewImg');
  
  if (input.files && input.files[0]) {
    const file = input.files[0];
    
    // Validate file type
    const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
    if (!validTypes.includes(file.type)) {
      alert('⚠️ Please select a valid image file (JPG, PNG, GIF)');
      input.value = '';
      preview.style.display = 'none';
      return;
    }
    
    // Validate file size (2MB)
    if (file.size > 2 * 1024 * 1024) {
      alert('⚠️ Image size must be less than 2MB');
      input.value = '';
      preview.style.display = 'none';
      return;
    }
    
    const reader = new FileReader();
    reader.onload = function(e) {
      img.src = e.target.result;
      preview.style.display = 'block';
    }
    reader.readAsDataURL(file);
  } else {
    preview.style.display = 'none';
  }
}

// Alert Functions
function closeAlert() {
  const alertPopup = document.getElementById('alertPopup');
  if (alertPopup) {
    alertPopup.classList.remove('show');
  }
}

// Auto hide alert after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
  const alertPopup = document.getElementById('alertPopup');
  if (alertPopup && alertPopup.classList.contains('show')) {
    setTimeout(() => {
      closeAlert();
    }, 5000);
  }
});

// Close modals on escape key
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    closeModal('addModal');
    closeModal('editModal');
    closeModal('deleteModal');
  }
});

// Close modals when clicking outside
document.addEventListener('click', function(e) {
  if (e.target.classList.contains('modal')) {
    closeModal('addModal');
    closeModal('editModal');
    closeModal('deleteModal');
  }
});

// Phone number formatting
document.addEventListener('DOMContentLoaded', function() {
  const phoneInputs = document.querySelectorAll('input[type="tel"]');
  phoneInputs.forEach(input => {
    input.addEventListener('input', function(e) {
      let value = e.target.value.replace(/\D/g, '');
      if (value.length > 0 && !value.startsWith('+')) {
        value = '+' + value;
      }
      e.target.value = value;
    });
  });
});

// Capitalize name input in real-time
document.addEventListener('DOMContentLoaded', function() {
  const nameInputs = document.querySelectorAll('input[name="full_name"]');
  nameInputs.forEach(input => {
    input.addEventListener('blur', function() {
      this.value = capitalizeName(this.value);
    });
  });
});
</script>

<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>