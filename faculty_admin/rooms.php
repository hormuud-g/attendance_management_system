<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Check login
if (empty($_SESSION['user'])) {
  header("Location: ../login.php");
  exit;
}

// ✅ Access control - Only faculty admin
if (strtolower($_SESSION['user']['role'] ?? '') !== 'faculty_admin') {
  header("Location: ../login.php");
  exit;
}

// ✅ Get current faculty ID from user session
$current_faculty_id = $_SESSION['user']['linked_id'] ?? null;

// ✅ USER INFO
$user  = $_SESSION['user'];
$role  = strtolower(str_replace(' ', '_', $user['role'] ?? ''));
$name  = htmlspecialchars($user['username'] ?? 'User', ENT_QUOTES, 'UTF-8');
$email = htmlspecialchars($user['email'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
$phone = htmlspecialchars($user['phone_number'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
$photo = !empty($user['profile_photo_path'])
  ? "../" . htmlspecialchars($user['profile_photo_path'], ENT_QUOTES, 'UTF-8')
  : "../upload/profiles/default.png";

$currentPage = basename($_SERVER['PHP_SELF']);
$message = "";
$type = "";

/* =========================================================
   CRUD OPERATIONS
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  // 🟢 ADD ROOM
  if ($action === 'add') {
    try {
      $faculty_id = $current_faculty_id; // Always use current faculty
      $department_id = $_POST['department_id'];
      $code = trim($_POST['room_code']);
      
      // Check if room code already exists in this faculty
      $check = $pdo->prepare("SELECT COUNT(*) FROM rooms WHERE room_code=? AND faculty_id=?");
      $check->execute([$code, $faculty_id]);

      if ($check->fetchColumn() > 0) {
        $message = "⚠️ Room code already exists in your faculty!";
        $type = "error";
      } else {
        $stmt = $pdo->prepare("INSERT INTO rooms 
          (faculty_id, department_id, building_name, floor_no, room_name, room_code, capacity, room_type, description, status)
          VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
          $faculty_id,
          $department_id,
          $_POST['building_name'],
          $_POST['floor_no'],
          $_POST['room_name'],
          $code,
          $_POST['capacity'],
          $_POST['room_type'],
          $_POST['description'],
          'available'
        ]);
        $message = "✅ Room added successfully!";
        $type = "success";
      }
    } catch (PDOException $e) {
      $message = "❌ " . $e->getMessage();
      $type = "error";
    }
  }

  // 🟡 UPDATE ROOM
  if ($action === 'update') {
    try {
      $faculty_id = $current_faculty_id; // Always use current faculty
      $room_id = $_POST['room_id'];
      $code = trim($_POST['room_code']);
      $department_id = $_POST['department_id'];
      
      // Check if room code already exists in this faculty (excluding current room)
      $check = $pdo->prepare("SELECT COUNT(*) FROM rooms WHERE room_code=? AND faculty_id=? AND room_id!=?");
      $check->execute([$code, $faculty_id, $room_id]);

      if ($check->fetchColumn() > 0) {
        $message = "⚠️ Room code already exists in your faculty!";
        $type = "error";
      } else {
        $stmt = $pdo->prepare("UPDATE rooms 
          SET faculty_id=?, department_id=?, building_name=?, floor_no=?, room_name=?, room_code=?, capacity=?, room_type=?, description=?, status=? 
          WHERE room_id=?");
        $stmt->execute([
          $faculty_id,
          $department_id,
          $_POST['building_name'],
          $_POST['floor_no'],
          $_POST['room_name'],
          $code,
          $_POST['capacity'],
          $_POST['room_type'],
          $_POST['description'],
          $_POST['status'],
          $room_id
        ]);

        $message = "✅ Room updated successfully!";
        $type = "success";
      }
    } catch (PDOException $e) {
      $message = "❌ " . $e->getMessage();
      $type = "error";
    }
  }
  
  // 🔴 DELETE ROOM
  if ($action === 'delete') {
    try {
      $stmt = $pdo->prepare("DELETE FROM rooms WHERE room_id=?");
      $stmt->execute([$_POST['room_id']]);
      
      $message = "✅ Room deleted successfully!";
      $type = "success";
    } catch (PDOException $e) {
      $message = "❌ " . $e->getMessage();
      $type = "error";
    }
  }
}

/* =========================================================
   FETCH RELATED DATA
========================================================= */

// ✅ Get current faculty info
$stmt = $pdo->prepare("SELECT faculty_name, faculty_code FROM faculties WHERE faculty_id = ?");
$stmt->execute([$current_faculty_id]);
$faculty = $stmt->fetch(PDO::FETCH_ASSOC);
$faculty_name = $faculty['faculty_name'] ?? 'Unknown Faculty';
$faculty_code = $faculty['faculty_code'] ?? '';

// ✅ All Departments for this faculty
$stmt = $pdo->prepare("SELECT department_id, department_name FROM departments WHERE faculty_id = ? ORDER BY department_name");
$stmt->execute([$current_faculty_id]);
$departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ Fetch all rooms for this faculty
$stmt = $pdo->prepare("
  SELECT r.*, f.faculty_name, d.department_name
  FROM rooms r
  LEFT JOIN faculties f ON r.faculty_id = f.faculty_id
  LEFT JOIN departments d ON r.department_id = d.department_id
  WHERE r.faculty_id = ?
  ORDER BY r.room_id DESC
");
$stmt->execute([$current_faculty_id]);
$rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ Get filter parameters
$filter_department = $_GET['department'] ?? '';
$filter_type = $_GET['type'] ?? '';
$filter_status = $_GET['status'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Room Management | Faculty Admin | Hormuud University</title>
<link rel="icon" type="image/png" href="../images.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
  --green: #00843D;
  --blue: #0072CE;
  --red: #C62828;
  --light-green: #00A651;
  --bg: #F5F9F7;
  --orange: #FF9800;
  --purple: #9C27B0;
  --cyan: #00BCD4;
}

body {
  font-family: 'Poppins', sans-serif;
  background: var(--bg);
  margin: 0;
  color: #333;
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
  background: white;
  padding: 20px;
  border-radius: 12px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.08);
  border-left: 4px solid var(--green);
}

.header-title h1 {
  color: var(--blue);
  font-size: 24px;
  margin: 0 0 10px 0;
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: 10px;
}

.header-title h1 i {
  color: var(--green);
  background: rgba(0, 132, 61, 0.1);
  padding: 10px;
  border-radius: 10px;
}

.faculty-badge {
  display: flex;
  align-items: center;
  gap: 10px;
}

.faculty-name {
  background: var(--green);
  color: white;
  padding: 5px 15px;
  border-radius: 30px;
  font-size: 14px;
  font-weight: 500;
}

.department-count {
  background: var(--blue);
  color: white;
  padding: 5px 15px;
  border-radius: 30px;
  font-size: 14px;
  font-weight: 500;
}

.add-btn {
  background: linear-gradient(135deg, var(--green), var(--light-green));
  color: #fff;
  border: none;
  padding: 12px 24px;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  transition: .3s;
  display: flex;
  align-items: center;
  gap: 8px;
  box-shadow: 0 4px 12px rgba(0, 132, 61, 0.2);
}

.add-btn:hover {
  transform: translateY(-3px);
  box-shadow: 0 6px 20px rgba(0, 132, 61, 0.3);
}

/* Stats Cards */
.stats-container {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 20px;
  margin-bottom: 25px;
}

.stat-card {
  background: white;
  border-radius: 12px;
  padding: 20px;
  display: flex;
  align-items: center;
  gap: 20px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.08);
  border-left: 4px solid;
}

.stat-card.total { border-left-color: var(--blue); }
.stat-card.available { border-left-color: var(--green); }
.stat-card.maintenance { border-left-color: var(--orange); }
.stat-card.inactive { border-left-color: var(--red); }

.stat-icon {
  width: 50px;
  height: 50px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  color: white;
}

.stat-icon.total { background: var(--blue); }
.stat-icon.available { background: var(--green); }
.stat-icon.maintenance { background: var(--orange); }
.stat-icon.inactive { background: var(--red); }

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

/* Filter Section */
.filter-section {
  background: white;
  padding: 25px;
  border-radius: 12px;
  margin-bottom: 25px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}

.filter-section h3 {
  color: var(--blue);
  margin: 0 0 20px 0;
  font-size: 18px;
  display: flex;
  align-items: center;
  gap: 10px;
}

.filter-section h3 i {
  color: var(--green);
}

.filter-row {
  display: flex;
  gap: 15px;
  align-items: flex-end;
  flex-wrap: wrap;
}

.filter-group {
  flex: 1;
  min-width: 200px;
}

.filter-group label {
  display: block;
  margin-bottom: 8px;
  font-weight: 600;
  color: #0072CE;
  font-size: 14px;
}

.filter-group select, .filter-group input {
  width: 100%;
  padding: 12px;
  border: 2px solid #e0e0e0;
  border-radius: 8px;
  font-family: 'Poppins', sans-serif;
  transition: all 0.3s ease;
}

.filter-group select:focus, .filter-group input:focus {
  outline: none;
  border-color: var(--green);
  box-shadow: 0 0 0 3px rgba(0, 132, 61, 0.1);
}

.filter-btn {
  background: var(--blue);
  color: white;
  border: none;
  padding: 12px 24px;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  transition: .3s;
  height: fit-content;
  display: flex;
  align-items: center;
  gap: 8px;
}

.filter-btn:hover {
  background: #005fa3;
  transform: translateY(-2px);
}

.reset-btn {
  background: #6c757d;
}

.reset-btn:hover {
  background: #5a6268;
}

/* Table Styles */
.table-wrapper {
  background: white;
  border-radius: 12px;
  overflow: hidden;
  box-shadow: 0 4px 12px rgba(0,0,0,0.08);
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
  margin: 0;
}

.results-count {
  color: #666;
  font-size: 14px;
}

.table-container {
  overflow-x: auto;
  max-height: 500px;
}

.data-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 14px;
}

.data-table thead {
  background: linear-gradient(135deg, var(--blue), var(--green));
}

.data-table th {
  position: sticky;
  top: 0;
  padding: 16px;
  text-align: left;
  font-weight: 600;
  color: white;
  z-index: 2;
  white-space: nowrap;
}

.data-table td {
  padding: 14px 16px;
  border-bottom: 1px solid #eee;
  white-space: nowrap;
}

.data-table tbody tr:hover {
  background: #eef8f0;
}

.data-table tbody tr:nth-child(even) {
  background: rgba(0, 114, 206, 0.02);
}

/* Status Badges */
.status-badge {
  padding: 6px 12px;
  border-radius: 30px;
  font-size: 12px;
  font-weight: 600;
  display: inline-block;
}

.status-available {
  background: #e8f5e9;
  color: var(--green);
  border: 1px solid rgba(0, 132, 61, 0.2);
}

.status-maintenance {
  background: #fff3e0;
  color: var(--orange);
  border: 1px solid rgba(255, 152, 0, 0.2);
}

.status-inactive {
  background: #ffebee;
  color: var(--red);
  border: 1px solid rgba(198, 40, 40, 0.2);
}

/* Room Type Badges */
.type-badge {
  padding: 4px 10px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 500;
  display: inline-block;
}

.type-lecture {
  background: #e3f2fd;
  color: var(--blue);
}

.type-lab {
  background: #f3e5f5;
  color: var(--purple);
}

.type-seminar {
  background: #fff3e0;
  color: var(--orange);
}

.type-office {
  background: #e8f5e9;
  color: var(--green);
}

.type-online {
  background: #e0f7fa;
  color: var(--cyan);
}

/* Action Buttons */
.action-btns {
  display: flex;
  gap: 8px;
}

.action-btn {
  width: 36px;
  height: 36px;
  border-radius: 8px;
  border: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s ease;
  color: white;
  font-size: 14px;
  box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}

.edit-btn {
  background: var(--blue);
}

.edit-btn:hover {
  background: #005fa3;
  transform: translateY(-2px) scale(1.05);
}

.del-btn {
  background: var(--red);
}

.del-btn:hover {
  background: #b71c1c;
  transform: translateY(-2px) scale(1.05);
}

/* Modal Styles */
.modal {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.6);
  justify-content: center;
  align-items: center;
  z-index: 3000;
  backdrop-filter: blur(5px);
}

.modal.show {
  display: flex;
}

.modal-content {
  background: white;
  border-radius: 16px;
  width: 90%;
  max-width: 800px;
  max-height: 90vh;
  overflow-y: auto;
  padding: 30px;
  position: relative;
  box-shadow: 0 20px 40px rgba(0,0,0,0.3);
}

.close-modal {
  position: absolute;
  top: 15px;
  right: 20px;
  font-size: 28px;
  cursor: pointer;
  color: #888;
  transition: all 0.3s ease;
  width: 40px;
  height: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  background: rgba(0,0,0,0.05);
}

.close-modal:hover {
  background: rgba(0,0,0,0.1);
  color: var(--red);
  transform: rotate(90deg);
}

.modal-content h2 {
  color: var(--blue);
  margin: 0 0 25px 0;
  font-size: 24px;
  display: flex;
  align-items: center;
  gap: 10px;
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
  grid-template-columns: repeat(2, 1fr);
  gap: 20px;
}

.full-width {
  grid-column: span 2;
}

.form-group {
  margin-bottom: 20px;
}

.form-group label {
  display: block;
  margin-bottom: 8px;
  font-weight: 600;
  color: #0072CE;
  font-size: 14px;
}

.form-group label i {
  margin-right: 5px;
  color: var(--green);
}

.form-control {
  width: 100%;
  padding: 12px;
  border: 2px solid #e0e0e0;
  border-radius: 8px;
  font-family: 'Poppins', sans-serif;
  font-size: 14px;
  transition: all 0.3s ease;
}

.form-control:focus {
  outline: none;
  border-color: var(--green);
  box-shadow: 0 0 0 3px rgba(0, 132, 61, 0.1);
}

.form-control[readonly] {
  background: #f5f5f5;
  cursor: not-allowed;
}

textarea.form-control {
  resize: none;
  height: 80px;
}

/* Faculty Info Box */
.faculty-info-box {
  grid-column: span 2;
  background: linear-gradient(135deg, #f8f9fa 0%, #e8f5e9 100%);
  border-radius: 10px;
  padding: 15px 20px;
  margin-bottom: 20px;
  border-left: 4px solid var(--green);
  display: flex;
  align-items: center;
  gap: 15px;
  flex-wrap: wrap;
}

.info-item {
  display: flex;
  align-items: center;
  gap: 10px;
  background: white;
  padding: 8px 16px;
  border-radius: 30px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.info-item i {
  color: var(--green);
  font-size: 16px;
}

.info-item span {
  font-weight: 500;
  color: #333;
}

.info-item small {
  color: #666;
  font-size: 12px;
  margin-left: 5px;
}

/* Submit Button */
.submit-btn {
  grid-column: span 2;
  background: linear-gradient(135deg, var(--green), var(--light-green));
  color: white;
  border: none;
  padding: 14px;
  border-radius: 8px;
  font-weight: 600;
  font-size: 16px;
  cursor: pointer;
  transition: all 0.3s ease;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  box-shadow: 0 4px 12px rgba(0, 132, 61, 0.2);
  position: relative;
  overflow: hidden;
}

.submit-btn::before {
  content: '';
  position: absolute;
  top: 0;
  left: -100%;
  width: 100%;
  height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
  transition: 0.5s;
}

.submit-btn:hover {
  transform: translateY(-3px);
  box-shadow: 0 6px 20px rgba(0, 132, 61, 0.3);
}

.submit-btn:hover::before {
  left: 100%;
}

/* Alert Popup */
.alert-popup {
  display: none;
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  background: white;
  border-radius: 12px;
  padding: 30px 40px;
  text-align: center;
  z-index: 4000;
  box-shadow: 0 20px 40px rgba(0,0,0,0.2);
  min-width: 350px;
  border-top: 5px solid;
}

.alert-popup.show {
  display: block;
  animation: slideIn 0.3s ease;
}

@keyframes slideIn {
  from {
    opacity: 0;
    transform: translate(-50%, -60px);
  }
  to {
    opacity: 1;
    transform: translate(-50%, -50%);
  }
}

.alert-popup.success {
  border-top-color: var(--green);
}

.alert-popup.error {
  border-top-color: var(--red);
}

.alert-popup i {
  font-size: 50px;
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
  color: #333;
}

.alert-popup p {
  margin: 0;
  color: #666;
  font-size: 14px;
}

/* Empty State */
.empty-state {
  text-align: center;
  padding: 60px 20px;
  color: #666;
}

.empty-state i {
  font-size: 60px;
  color: #ddd;
  margin-bottom: 20px;
}

.empty-state h3 {
  font-size: 18px;
  margin-bottom: 10px;
  color: #888;
}

.empty-state p {
  color: #aaa;
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

/* Responsive Design */
@media (max-width: 1024px) {
  .main-content {
    margin-left: 240px;
  }
}

@media (max-width: 768px) {
  .main-content {
    margin-left: 0 !important;
    padding: 80px 15px 20px;
  }
  
  .page-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 15px;
  }
  
  .add-btn {
    width: 100%;
    justify-content: center;
  }
  
  .filter-row {
    flex-direction: column;
    align-items: stretch;
  }
  
  .filter-group {
    min-width: 100%;
  }
  
  .form-grid {
    grid-template-columns: 1fr;
  }
  
  .full-width {
    grid-column: span 1;
  }
  
  .modal-content {
    padding: 20px;
  }
  
  .action-btns {
    flex-direction: column;
  }
}

@media (max-width: 480px) {
  .page-header h1 {
    font-size: 20px;
  }
  
  .modal-content {
    padding: 15px;
  }
  
  .alert-popup {
    min-width: 280px;
    padding: 20px;
  }
  
  .stats-container {
    grid-template-columns: 1fr;
  }
}

/* Scrollbar */
.table-container::-webkit-scrollbar,
.modal-content::-webkit-scrollbar {
  width: 8px;
  height: 8px;
}

.table-container::-webkit-scrollbar-track,
.modal-content::-webkit-scrollbar-track {
  background: #f1f1f1;
  border-radius: 4px;
}

.table-container::-webkit-scrollbar-thumb,
.modal-content::-webkit-scrollbar-thumb {
  background: var(--green);
  border-radius: 4px;
}

.table-container::-webkit-scrollbar-thumb:hover,
.modal-content::-webkit-scrollbar-thumb:hover {
  background: var(--light-green);
}
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
  <!-- ✅ PAGE HEADER -->
  <div class="page-header">
    <div class="header-title">
      <h1><i class="fas fa-door-open"></i> Room Management</h1>
      
    </div>
    <button class="add-btn" onclick="openModal('addModal')">
      <i class="fa-solid fa-plus"></i> Add New Room
    </button>
  </div>

  <!-- ✅ STATS CARDS -->
  <?php
  $total_rooms = count($rooms);
  $available = 0;
  $maintenance = 0;
  $inactive = 0;
  
  foreach ($rooms as $room) {
    if ($room['status'] == 'available') $available++;
    elseif ($room['status'] == 'maintenance') $maintenance++;
    elseif ($room['status'] == 'inactive') $inactive++;
  }
  ?>
  
  <div class="stats-container">
    <div class="stat-card total">
      <div class="stat-icon total"><i class="fas fa-door-open"></i></div>
      <div class="stat-info">
        <h3>Total Rooms</h3>
        <div class="number"><?= $total_rooms ?></div>
      </div>
    </div>
    
    <div class="stat-card available">
      <div class="stat-icon available"><i class="fas fa-check-circle"></i></div>
      <div class="stat-info">
        <h3>Available</h3>
        <div class="number"><?= $available ?></div>
      </div>
    </div>
    
    <div class="stat-card maintenance">
      <div class="stat-icon maintenance"><i class="fas fa-tools"></i></div>
      <div class="stat-info">
        <h3>Maintenance</h3>
        <div class="number"><?= $maintenance ?></div>
      </div>
    </div>
    
    <div class="stat-card inactive">
      <div class="stat-icon inactive"><i class="fas fa-pause-circle"></i></div>
      <div class="stat-info">
        <h3>Inactive</h3>
        <div class="number"><?= $inactive ?></div>
      </div>
    </div>
  </div>

  <!-- ✅ FILTER SECTION -->
  <div class="filter-section">
    <h3><i class="fas fa-filter"></i> Filter Rooms</h3>
    <div class="filter-row">
      <div class="filter-group">
  <label for="filter_department">Department</label>
  <select id="filter_department" onchange="filterRooms()">
    <option value="">All Departments</option>
    <?php
    // ✅ Get current faculty ID from session
    $current_faculty_id = $_SESSION['user']['linked_id'] ?? null;
    
    if ($current_faculty_id) {
        // ✅ Fetch departments with campus name, ordered by campus first
        $dept_query = $pdo->prepare("
            SELECT d.department_id, d.department_name, c.campus_name, c.campus_id
            FROM departments d
            JOIN campus c ON d.campus_id = c.campus_id
            WHERE d.faculty_id = ? AND d.status = 'active'
            ORDER BY c.campus_name ASC, d.department_name ASC
        ");
        $dept_query->execute([$current_faculty_id]);
        $dept_list = $dept_query->fetchAll(PDO::FETCH_ASSOC);
        
        $current_campus_id = null;
        foreach($dept_list as $dept): 
            // Show campus header when campus changes
            if ($current_campus_id != $dept['campus_id']):
                if ($current_campus_id !== null) echo '</optgroup>';
                echo '<optgroup label="' . htmlspecialchars($dept['campus_name']) . ' Campus">';
                $current_campus_id = $dept['campus_id'];
            endif;
    ?>
            <option value="<?= $dept['department_id'] ?>">
                <?= htmlspecialchars($dept['department_name']) ?>
            </option>
    <?php 
        endforeach; 
        if ($current_campus_id !== null) echo '</optgroup>';
    }
    ?>
  </select>
</div>
      
      <div class="filter-group">
        <label for="filter_type">Room Type</label>
        <select id="filter_type" onchange="filterRooms()">
          <option value="">All Types</option>
          <option value="Lecture">Lecture</option>
          <option value="Lab">Lab</option>
          <option value="Seminar">Seminar</option>
          <option value="Office">Office</option>
          <option value="Online">Online</option>
        </select>
      </div>
      
      <div class="filter-group">
        <label for="filter_status">Status</label>
        <select id="filter_status" onchange="filterRooms()">
          <option value="">All Statuses</option>
          <option value="available">Available</option>
          <option value="maintenance">Maintenance</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
      
      <div class="filter-group">
        <label for="search_input">Search</label>
        <input type="text" id="search_input" placeholder="Room name, code, building..." onkeyup="filterRooms()">
      </div>
      
      <button class="filter-btn reset-btn" onclick="resetFilters()">
        <i class="fas fa-redo-alt"></i> Reset
      </button>
    </div>
  </div>

  <!-- ✅ ROOMS TABLE -->
  <div class="table-wrapper">
    <div class="table-header">
      <h3>Room List</h3>
      <div class="results-count" id="results_count">Showing <?= count($rooms) ?> rooms</div>
    </div>
    
    <div class="table-container">
      <table class="data-table" id="roomsTable">
        <thead>
          <tr>
            <th>#</th>
            <th>Department</th>
            <th>Building</th>
            <th>Floor</th>
            <th>Room Name</th>
            <th>Code</th>
            <th>Capacity</th>
            <th>Type</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if($rooms): ?>
            <?php foreach($rooms as $i => $r): ?>
            <tr data-department="<?= $r['department_id'] ?>" 
                data-type="<?= $r['room_type'] ?>" 
                data-status="<?= $r['status'] ?>"
                data-search="<?= strtolower($r['room_name'] . ' ' . $r['room_code'] . ' ' . $r['building_name']) ?>">
              <td><?= $i + 1 ?></td>
              <td><?= htmlspecialchars($r['department_name'] ?? 'N/A') ?></td>
              <td><?= htmlspecialchars($r['building_name'] ?? '') ?></td>
              <td><?= htmlspecialchars($r['floor_no'] ?? '') ?></td>
              <td><strong><?= htmlspecialchars($r['room_name']) ?></strong></td>
              <td><span class="code-badge"><?= htmlspecialchars($r['room_code']) ?></span></td>
              <td><?= htmlspecialchars($r['capacity']) ?></td>
              <td>
                <span class="type-badge type-<?= strtolower($r['room_type']) ?>">
                  <?= htmlspecialchars($r['room_type']) ?>
                </span>
              </td>
              <td>
                <span class="status-badge status-<?= strtolower($r['status']) ?>">
                  <?= ucfirst($r['status']) ?>
                </span>
              </td>
              <td>
                <div class="action-btns">
                  <button class="action-btn edit-btn" onclick="openEditModal(
                    <?= $r['room_id'] ?>,
                    <?= $r['department_id'] ?>,
                    '<?= htmlspecialchars(addslashes($r['building_name'] ?? '')) ?>',
                    '<?= htmlspecialchars(addslashes($r['floor_no'] ?? '')) ?>',
                    '<?= htmlspecialchars(addslashes($r['room_name'])) ?>',
                    '<?= htmlspecialchars(addslashes($r['room_code'])) ?>',
                    '<?= $r['capacity'] ?>',
                    '<?= $r['room_type'] ?>',
                    '<?= htmlspecialchars(addslashes($r['description'] ?? '')) ?>',
                    '<?= $r['status'] ?>'
                  )">
                    <i class="fa-solid fa-pen"></i>
                  </button>
                  <button class="action-btn del-btn" onclick="deleteRoom(<?= $r['room_id'] ?>)">
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
                  <i class="fas fa-door-closed"></i>
                  <h3>No rooms found</h3>
                  <p>Click the "Add New Room" button to create your first room</p>
                  <button class="add-first-btn" onclick="openModal('addModal')">
                    <i class="fas fa-plus"></i> Add First Room
                  </button>
                </div>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ✅ ADD MODAL -->
<div class="modal" id="addModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('addModal')">&times;</span>
    <h2><i class="fas fa-plus-circle"></i> Add New Room</h2>
    <form method="POST" class="form-grid">
      <input type="hidden" name="action" value="add">
      
      <!-- Faculty Info -->
      <div class="full-width faculty-info-box">
        <div class="info-item">
          <i class="fas fa-university"></i>
          <span><?= htmlspecialchars($faculty_name) ?></span>
          <small>(<?= htmlspecialchars($faculty_code) ?>)</small>
        </div>
      </div>
      
      <!-- Department Selection -->
      <div class="full-width form-group">
  <label><i class="fas fa-building"></i> Department *</label>
  <select name="department_id" class="form-control" required>
    <option value="">Select Department</option>
    <?php
    // ✅ Get current faculty ID from session
    $current_faculty_id = $_SESSION['user']['linked_id'] ?? null;
    
    if ($current_faculty_id) {
        // ✅ Fetch ONLY departments for this faculty, with campus name
        $dept_query = $pdo->prepare("
            SELECT d.department_id, d.department_name, c.campus_name, c.campus_id
            FROM departments d
            JOIN campus c ON d.campus_id = c.campus_id
            WHERE d.faculty_id = ? AND d.status = 'active'
            ORDER BY c.campus_name ASC, d.department_name ASC
        ");
        $dept_query->execute([$current_faculty_id]);
        $dept_list = $dept_query->fetchAll(PDO::FETCH_ASSOC);
        
        // Group by campus
        $current_campus_id = null;
        foreach($dept_list as $dept): 
            // Show campus header when campus changes
            if ($current_campus_id != $dept['campus_id']):
                if ($current_campus_id !== null) echo '</optgroup>';
                echo '<optgroup label="' . htmlspecialchars($dept['campus_name']) . ' Campus">';
                $current_campus_id = $dept['campus_id'];
            endif;
        ?>
            <option value="<?= $dept['department_id'] ?>">
                <?= htmlspecialchars($dept['department_name']) ?>
            </option>
        <?php 
        endforeach; 
        if ($current_campus_id !== null) echo '</optgroup>';
    } else {
        // No faculty ID found
        echo '<option value="" disabled>No faculty assigned</option>';
    }
    ?>
  </select>
</div>
      
      <div class="form-group">
        <label><i class="fas fa-building"></i> Building Name *</label>
        <input type="text" name="building_name" class="form-control" required>
      </div>
      
      <div class="form-group">
        <label><i class="fas fa-layer-group"></i> Floor No.</label>
        <input type="text" name="floor_no" class="form-control" placeholder="e.g. 1, 2, Ground">
      </div>
      
      <div class="form-group">
        <label><i class="fas fa-door-open"></i> Room Name *</label>
        <input type="text" name="room_name" class="form-control" required>
      </div>
      
      <div class="form-group">
        <label><i class="fas fa-tag"></i> Room Code *</label>
        <input type="text" name="room_code" class="form-control" required placeholder="e.g. CIT-101">
        <small style="color: #666;">Must be unique within your faculty</small>
      </div>
      
      <div class="form-group">
        <label><i class="fas fa-users"></i> Capacity</label>
        <input type="number" name="capacity" class="form-control" min="1">
      </div>
      
      <div class="form-group">
        <label><i class="fas fa-tag"></i> Room Type *</label>
        <select name="room_type" class="form-control" required>
          <option value="">Select Type</option>
          <option value="Lecture">Lecture</option>
          <option value="Lab">Lab</option>
          <option value="Seminar">Seminar</option>
          <option value="Office">Office</option>
          <option value="Online">Online</option>
        </select>
      </div>
      
      <div class="full-width form-group">
        <label><i class="fas fa-align-left"></i> Description</label>
        <textarea name="description" class="form-control" placeholder="Additional details about the room..."></textarea>
      </div>
      
      <button type="submit" class="submit-btn">
        <i class="fas fa-save"></i> Create Room
      </button>
    </form>
  </div>
</div>

<!-- ✅ EDIT MODAL -->
<div class="modal" id="editModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('editModal')">&times;</span>
    <h2><i class="fas fa-edit"></i> Edit Room</h2>
    <form method="POST" class="form-grid">
      <input type="hidden" name="action" value="update">
      <input type="hidden" id="edit_id" name="room_id">
      
      <!-- Faculty Info -->
      <div class="full-width faculty-info-box">
        <div class="info-item">
          <i class="fas fa-university"></i>
          <span><?= htmlspecialchars($faculty_name) ?></span>
          <small>(<?= htmlspecialchars($faculty_code) ?>)</small>
        </div>
      </div>
      
      <!-- Department Selection -->
     <div class="full-width form-group">
  <label><i class="fas fa-building"></i> Department *</label>
  <select id="edit_department" name="department_id" class="form-control" required>
    <option value="">Select Department</option>
    <?php
    // ✅ Get current faculty ID from session
    $current_faculty_id = $_SESSION['user']['linked_id'] ?? null;
    
    if ($current_faculty_id) {
        // ✅ Fetch departments with campus name, ordered by campus first
        $dept_query = $pdo->prepare("
            SELECT d.department_id, d.department_name, c.campus_name, c.campus_id
            FROM departments d
            JOIN campus c ON d.campus_id = c.campus_id
            WHERE d.faculty_id = ? AND d.status = 'active'
            ORDER BY c.campus_name ASC, d.department_name ASC
        ");
        $dept_query->execute([$current_faculty_id]);
        $dept_list = $dept_query->fetchAll(PDO::FETCH_ASSOC);
        
        $current_campus_id = null;
        foreach($dept_list as $dept): 
            // Show campus header when campus changes
            if ($current_campus_id != $dept['campus_id']):
                if ($current_campus_id !== null) echo '</optgroup>';
                echo '<optgroup label="' . htmlspecialchars($dept['campus_name']) . ' Campus">';
                $current_campus_id = $dept['campus_id'];
            endif;
    ?>
            <option value="<?= $dept['department_id'] ?>">
                <?= htmlspecialchars($dept['department_name']) ?>
            </option>
    <?php 
        endforeach; 
        if ($current_campus_id !== null) echo '</optgroup>';
    }
    ?>
  </select>
</div>
      
      <div class="form-group">
        <label><i class="fas fa-building"></i> Building Name *</label>
        <input type="text" id="edit_building" name="building_name" class="form-control" required>
      </div>
      
      <div class="form-group">
        <label><i class="fas fa-layer-group"></i> Floor No.</label>
        <input type="text" id="edit_floor" name="floor_no" class="form-control">
      </div>
      
      <div class="form-group">
        <label><i class="fas fa-door-open"></i> Room Name *</label>
        <input type="text" id="edit_roomname" name="room_name" class="form-control" required>
      </div>
      
      <div class="form-group">
        <label><i class="fas fa-tag"></i> Room Code *</label>
        <input type="text" id="edit_code" name="room_code" class="form-control" required>
        <small style="color: #666;">Must be unique within your faculty</small>
      </div>
      
      <div class="form-group">
        <label><i class="fas fa-users"></i> Capacity</label>
        <input type="number" id="edit_capacity" name="capacity" class="form-control" min="1">
      </div>
      
      <div class="form-group">
        <label><i class="fas fa-tag"></i> Room Type *</label>
        <select id="edit_type" name="room_type" class="form-control" required>
          <option value="Lecture">Lecture</option>
          <option value="Lab">Lab</option>
          <option value="Seminar">Seminar</option>
          <option value="Office">Office</option>
          <option value="Online">Online</option>
        </select>
      </div>
      
      <div class="form-group">
        <label><i class="fas fa-circle"></i> Status</label>
        <select id="edit_status" name="status" class="form-control">
          <option value="available">Available</option>
          <option value="maintenance">Maintenance</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
      
      <div class="full-width form-group">
        <label><i class="fas fa-align-left"></i> Description</label>
        <textarea id="edit_desc" name="description" class="form-control"></textarea>
      </div>
      
      <button type="submit" class="submit-btn">
        <i class="fas fa-sync-alt"></i> Update Room
      </button>
    </form>
  </div>
</div>

<!-- ✅ DELETE CONFIRMATION MODAL -->
<div class="modal" id="deleteModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('deleteModal')">&times;</span>
    <h2 style="color: var(--red);"><i class="fas fa-exclamation-triangle"></i> Confirm Deletion</h2>
    <form method="POST" id="deleteForm">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="room_id" id="delete_id">
      
      <div style="text-align: center; padding: 20px 0;">
        <i class="fas fa-door-open" style="font-size: 60px; color: var(--red); margin-bottom: 15px;"></i>
        <p style="font-size: 16px; margin-bottom: 10px;">Are you sure you want to delete this room?</p>
        <p style="color: #666; font-size: 14px;">This action cannot be undone.</p>
      </div>
      
      <div style="display: flex; gap: 10px; justify-content: center;">
        <button type="button" class="filter-btn reset-btn" onclick="closeModal('deleteModal')">
          <i class="fas fa-times"></i> Cancel
        </button>
        <button type="submit" class="del-btn" style="padding: 12px 24px;">
          <i class="fas fa-trash"></i> Delete
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ✅ ALERT POPUP -->
<div id="popup" class="alert-popup <?= $type ?> <?= !empty($message)?'show':'' ?>">
  <i class="<?= $type === 'success' ? 'fa-regular fa-circle-check' : 'fa-regular fa-circle-xmark' ?>"></i>
  <h3><?= $type === 'success' ? 'Success!' : 'Error!' ?></h3>
  <p><?= htmlspecialchars($message) ?></p>
</div>

<script>
// Modal functions
function openModal(id) {
  document.getElementById(id).classList.add('show');
  document.body.style.overflow = 'hidden';
}

function closeModal(id) {
  document.getElementById(id).classList.remove('show');
  document.body.style.overflow = 'auto';
}

// Open edit modal with data
function openEditModal(id, dept, building, floor, name, code, capacity, type, desc, status) {
  openModal('editModal');
  
  document.getElementById('edit_id').value = id;
  document.getElementById('edit_department').value = dept;
  document.getElementById('edit_building').value = building || '';
  document.getElementById('edit_floor').value = floor || '';
  document.getElementById('edit_roomname').value = name;
  document.getElementById('edit_code').value = code;
  document.getElementById('edit_capacity').value = capacity || '';
  document.getElementById('edit_type').value = type;
  document.getElementById('edit_desc').value = desc || '';
  document.getElementById('edit_status').value = status || 'available';
}

// Delete room
function deleteRoom(roomId) {
  document.getElementById('delete_id').value = roomId;
  openModal('deleteModal');
}

// Filter rooms function
function filterRooms() {
  const departmentFilter = document.getElementById('filter_department').value;
  const typeFilter = document.getElementById('filter_type').value;
  const statusFilter = document.getElementById('filter_status').value;
  const searchTerm = document.getElementById('search_input').value.toLowerCase().trim();
  
  const rows = document.querySelectorAll('#roomsTable tbody tr');
  let visibleCount = 0;
  
  rows.forEach(row => {
    // Skip if it's an empty state row
    if (row.querySelector('.empty-state')) return;
    
    const department = row.getAttribute('data-department');
    const type = row.getAttribute('data-type');
    const status = row.getAttribute('data-status');
    const searchText = row.getAttribute('data-search') || '';
    
    let show = true;
    
    if (departmentFilter && department !== departmentFilter) show = false;
    if (typeFilter && type !== typeFilter) show = false;
    if (statusFilter && status !== statusFilter) show = false;
    if (searchTerm && !searchText.includes(searchTerm)) show = false;
    
    row.style.display = show ? '' : 'none';
    if (show) visibleCount++;
  });
  
  // Update results count
  document.getElementById('results_count').textContent = `Showing ${visibleCount} rooms`;
}

// Reset all filters
function resetFilters() {
  document.getElementById('filter_department').value = '';
  document.getElementById('filter_type').value = '';
  document.getElementById('filter_status').value = '';
  document.getElementById('search_input').value = '';
  
  const rows = document.querySelectorAll('#roomsTable tbody tr');
  rows.forEach(row => {
    if (!row.querySelector('.empty-state')) {
      row.style.display = '';
    }
  });
  
  document.getElementById('results_count').textContent = `Showing ${rows.length - (document.querySelector('.empty-state') ? 1 : 0)} rooms`;
}

// Close modals when clicking outside
window.onclick = function(event) {
  if (event.target.classList.contains('modal')) {
    event.target.classList.remove('show');
    document.body.style.overflow = 'auto';
  }
}

// Close modals with Escape key
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal.show').forEach(modal => {
      modal.classList.remove('show');
      document.body.style.overflow = 'auto';
    });
  }
});

// Auto-hide popup
<?php if (!empty($message)): ?>
setTimeout(function() {
  const popup = document.getElementById('popup');
  if (popup) {
    popup.classList.remove('show');
  }
}, 3500);
<?php endif; ?>
</script>

<script src="../assets/js/sidebar.js"></script>
<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>