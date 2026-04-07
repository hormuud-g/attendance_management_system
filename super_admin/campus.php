<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Access Control
if (strtolower($_SESSION['user']['role'] ?? '') !== 'super_admin') {
  header("Location: ../login.php");
  exit;
}

$message = "";
$type = "";

/* ===========================================
   CRUD OPERATIONS
=========================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  /* 🟢 ADD CAMPUS */
  if ($_POST['action'] === 'add') {
    try {
      $code = trim($_POST['campus_code']);
      $check = $pdo->prepare("SELECT COUNT(*) FROM campus WHERE campus_code=?");
      $check->execute([$code]);

      if ($check->fetchColumn() > 0) {
        $message = "⚠️ Campus code already exists!";
        $type = "error";
      } else {
        $photo_path = null;
        if (!empty($_FILES['profile_photo']['name'])) {
          $upload_dir = __DIR__ . '/../upload/profiles/';
          if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
          $ext = pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
          $new_name = uniqid('campus_') . '.' . strtolower($ext);
          $photo_path = 'upload/profiles/' . $new_name;
          move_uploaded_file($_FILES['profile_photo']['tmp_name'], __DIR__ . '/../' . $photo_path);
        }

        // ✅ Insert into campus
        $stmt = $pdo->prepare("INSERT INTO campus 
          (campus_name, campus_code, address, city, country, phone_number, email, profile_photo_path, status)
          VALUES (?,?,?,?,?,?,?,?, 'active')");
        $stmt->execute([
          $_POST['campus_name'], $_POST['campus_code'], $_POST['address'],
          $_POST['city'], $_POST['country'], $_POST['phone_number'],
          $_POST['email'], $photo_path
        ]);

        // ✅ Create default user (campus_admin)
        $campus_id = $pdo->lastInsertId();
        $uuid = bin2hex(random_bytes(16));
        $plain_pass = "123";
        $hashed = password_hash($plain_pass, PASSWORD_BCRYPT);

        $user = $pdo->prepare("INSERT INTO users 
          (user_uuid, username, email, phone_number, profile_photo_path, password, password_plain, role, linked_id, linked_table, status)
          VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $user->execute([
          $uuid,
          $_POST['campus_name'],
          $_POST['email'],
          $_POST['phone_number'],
          $photo_path,
          $hashed,
          $plain_pass,
          'campus_admin',
          $campus_id,
          'campus',
          'active'
        ]);

        $message = "✅ Campus added successfully!";
        $type = "success";
      }
    } catch (PDOException $e) {
      $message = "❌ Error: " . $e->getMessage();
      $type = "error";
    }
  }

  /* 🟡 UPDATE CAMPUS */
  if ($_POST['action'] === 'update') {
    try {
      $id = $_POST['campus_id'];
      $campus_code = $_POST['campus_code']; // ✅ Get campus code from form

      // ✅ Check if campus code already exists (excluding current campus)
      $check = $pdo->prepare("SELECT COUNT(*) FROM campus WHERE campus_code=? AND campus_id!=?");
      $check->execute([$campus_code, $id]);
      
      if ($check->fetchColumn() > 0) {
        $message = "⚠️ Campus code already exists!";
        $type = "error";
      } else {
        $photo_sql = "";
        $params = [
          $_POST['campus_name'], 
          $campus_code,
          $_POST['address'], 
          $_POST['city'],
          $_POST['country'], 
          $_POST['phone_number'], 
          $_POST['email'],
          $_POST['status'], 
          $id
        ];

        if (!empty($_FILES['profile_photo']['name'])) {
          $upload_dir = __DIR__ . '/../upload/profiles/';
          if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
          $ext = pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
          $new_name = uniqid('campus_') . '.' . strtolower($ext);
          $photo_path = 'upload/profiles/' . $new_name;
          move_uploaded_file($_FILES['profile_photo']['tmp_name'], __DIR__ . '/../' . $photo_path);
          $photo_sql = ", profile_photo_path=?";
          array_splice($params, 8, 0, $photo_path);
        }

        $sql = "UPDATE campus 
                SET campus_name=?, campus_code=?, address=?, city=?, country=?, phone_number=?, email=?, status=? $photo_sql 
                WHERE campus_id=?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $userStatus = ($_POST['status'] === 'inactive') ? 'inactive' : 'active';
        $updateUser = $pdo->prepare("UPDATE users 
          SET username=?, email=?, phone_number=?, status=? 
          WHERE linked_id=? AND linked_table='campus'");
        $updateUser->execute([
          $_POST['campus_name'],
          $_POST['email'],
          $_POST['phone_number'],
          $userStatus,
          $id
        ]);

        $message = "✅ Campus updated successfully!";
        $type = "success";
      }
    } catch (PDOException $e) {
      $message = "❌ " . $e->getMessage();
      $type = "error";
    }
  }

  /* 🔴 DELETE CAMPUS */
  if ($_POST['action'] === 'delete') {
    try {
      $id = $_POST['campus_id'];
      $pdo->prepare("DELETE FROM users WHERE linked_id=? AND linked_table='campus'")->execute([$id]);
      $pdo->prepare("DELETE FROM campus WHERE campus_id=?")->execute([$id]);
      $message = "✅ Campus deleted successfully!";
      $type = "success";
    } catch (PDOException $e) {
      $message = "❌ " . $e->getMessage();
      $type = "error";
    }
  }
}

// ✅ Search and Filter Functionality
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$country_filter = $_GET['country'] ?? '';

$query = "SELECT * FROM campus WHERE 1=1";
$params = [];

if (!empty($search)) {
  $query .= " AND (campus_name LIKE ? OR campus_code LIKE ? OR city LIKE ? OR email LIKE ?)";
  $searchTerm = "%$search%";
  $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}

if (!empty($status_filter)) {
  $query .= " AND status = ?";
  $params[] = $status_filter;
}

if (!empty($country_filter)) {
  $query .= " AND country LIKE ?";
  $params[] = "%$country_filter%";
}

$query .= " ORDER BY campus_id DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$campuses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ Get unique countries for filter dropdown
$countries_query = $pdo->query("SELECT DISTINCT country FROM campus WHERE country IS NOT NULL ORDER BY country");
$countries = $countries_query->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
<title>Campus Management | Hormuud University</title>
<link rel="icon" type="image/png" href="../images.png">
<link rel="stylesheet" href="../assets/css/header.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
  --green: #00843D;
  --blue: #0072CE;
  --red: #C62828;
  --light-green: #00A651;
  --bg: #F5F9F7;
  --white: #FFFFFF;
  --gray: #6c757d;
  --light-gray: #e9ecef;
  --border: #dee2e6;
}

* {
  box-sizing: border-box;
}

body {
  font-family: 'Poppins', sans-serif;
  background: var(--bg);
  margin: 0;
  color: #333;
  overflow-x: hidden;
  min-height: 100vh;
}

/* ========== MAIN CONTENT ========== */
.main-content {
  padding: 20px;
  margin-top: 65px;
  margin-left: 240px;
  margin-bottom: 50px;
  transition: all 0.3s ease;
  min-height: calc(100vh - 115px);
}

body.sidebar-collapsed .main-content {
  margin-left: 60px;
}

/* ========== PAGE HEADER ========== */
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 15px;
  flex-wrap: wrap;
  gap: 15px;
  padding: 10px 0;
}

.page-header h1 {
  color: var(--blue);
  font-size: 24px;
  margin: 0;
  font-weight: 700;
  flex: 1;
  line-height: 1.2;
}

.add-btn {
  background: var(--green);
  color: var(--white);
  border: none;
  padding: 10px 18px;
  border-radius: 6px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.3s;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  white-space: nowrap;
}

.add-btn:hover {
  background: var(--light-green);
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 132, 61, 0.2);
}

/* ========== FILTERS ========== */
.filters-container {
  background: var(--white);
  padding: 20px;
  border-radius: 10px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.08);
  margin-bottom: 25px;
}

.filters-row {
  display: flex;
  align-items: flex-end;
  gap: 15px;
  flex-wrap: wrap;
}

.filter-group {
  flex: 1;
  min-width: 200px;
  display: flex;
  flex-direction: column;
}

.filter-group label {
  font-weight: 600;
  color: var(--blue);
  margin-bottom: 6px;
  display: block;
  font-size: 14px;
}

.filter-group input,
.filter-group select {
  width: 100%;
  padding: 10px 10px 10px 35px;
  border: 1.5px solid var(--border);
  border-radius: 6px;
  font-family: 'Poppins';
  font-size: 14px;
  background: var(--white);
  transition: all 0.3s;
}

.filter-group input:focus,
.filter-group select:focus {
  outline: none;
  border-color: var(--blue);
  box-shadow: 0 0 0 3px rgba(0, 114, 206, 0.1);
}

.filter-actions {
  flex-shrink: 0;
  display: flex;
  gap: 10px;
  align-items: flex-end;
  margin-bottom: 5px;
}

.clear-btn {
  background: var(--gray);
  color: var(--white);
  border: none;
  padding: 10px 18px;
  border-radius: 6px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.3s;
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

.clear-btn:hover {
  background: #5a6268;
}

.filter-icon {
  position: absolute;
  left: 10px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--gray);
  z-index: 2;
}

.filter-wrapper {
  position: relative;
}

.results-count {
  margin-top: 15px;
  color: var(--gray);
  font-size: 14px;
  text-align: right;
  padding-top: 10px;
  border-top: 1px solid var(--light-gray);
}

/* ========== TABLE STYLES ========== */
.table-container {
  background: var(--white);
  border-radius: 10px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.08);
  overflow: hidden;
}

.table-wrapper {
  overflow-x: auto;
  max-height: 500px;
  border-radius: 10px 10px 0 0;
}

table {
  width: 100%;
  border-collapse: collapse;
  min-width: 900px;
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
  padding: 16px;
  text-align: left;
  white-space: nowrap;
  position: relative;
}

thead th:after {
  content: '';
  position: absolute;
  right: 0;
  top: 20%;
  height: 60%;
  width: 1px;
  background: rgba(255,255,255,0.2);
}

thead th:last-child:after {
  display: none;
}

tbody tr {
  border-bottom: 1px solid var(--light-gray);
  transition: all 0.2s;
}

tbody tr:hover {
  background: #eef8f0;
}

tbody td {
  padding: 14px 16px;
  vertical-align: middle;
}

.profile-photo {
  width: 42px;
  height: 42px;
  border-radius: 50%;
  object-fit: cover;
  border: 2px solid var(--green);
}

/* ========== ACTION BUTTONS ========== */
.action-btns {
  display: flex;
  justify-content: center;
  gap: 8px;
  flex-wrap: nowrap;
}

.view-btn,
.edit-btn,
.del-btn {
  border: none;
  border-radius: 6px;
  padding: 8px 12px;
  color: var(--white);
  cursor: pointer;
  transition: all 0.3s;
  font-size: 14px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 36px;
  min-height: 36px;
}

.view-btn {
  background: var(--gray);
}

.view-btn:hover {
  background: #5a6268;
  transform: translateY(-2px);
}

.edit-btn {
  background: var(--blue);
}

.edit-btn:hover {
  background: #2196f3;
  transform: translateY(-2px);
}

.del-btn {
  background: var(--red);
}

.del-btn:hover {
  background: #e53935;
  transform: translateY(-2px);
}

/* ========== STATUS BADGES ========== */
.status-active,
.status-inactive {
  padding: 4px 10px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
  display: inline-block;
}

.status-active {
  background: rgba(0, 132, 61, 0.1);
  color: var(--green);
}

.status-inactive {
  background: rgba(198, 40, 40, 0.1);
  color: var(--red);
}

/* ========== NO RESULTS ========== */
.no-results {
  text-align: center;
  padding: 60px 20px;
  color: var(--gray);
}

.no-results i {
  font-size: 48px;
  margin-bottom: 15px;
  color: #ddd;
  display: block;
}

.no-results p {
  font-size: 16px;
  margin: 0 0 5px 0;
}

.no-results small {
  color: #aaa;
}

/* ========== MODAL STYLES ========== */
.modal {
  display: none;
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0, 0, 0, 0.5);
  justify-content: center;
  align-items: center;
  z-index: 3000;
  padding: 20px;
  overflow-y: auto;
}

.modal.show {
  display: flex;
}

.modal-content {
  background: var(--white);
  border-radius: 12px;
  width: 100%;
  max-width: 700px;
  padding: 30px;
  position: relative;
  box-shadow: 0 20px 40px rgba(0,0,0,0.25);
  max-height: 90vh;
  overflow-y: auto;
}

.close-modal {
  position: absolute;
  top: 15px;
  right: 20px;
  font-size: 28px;
  cursor: pointer;
  color: var(--gray);
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  transition: all 0.3s;
}

.close-modal:hover {
  background: var(--light-gray);
  color: var(--red);
}

.modal-content h2 {
  color: var(--blue);
  margin: 0 0 25px 0;
  font-size: 24px;
  padding-right: 30px;
}

/* ========== FORM STYLES ========== */
.modal-form {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 20px;
}

.form-group {
  display: flex;
  flex-direction: column;
}

.form-group label {
  font-weight: 600;
  color: var(--blue);
  margin-bottom: 8px;
  font-size: 14px;
}

.form-group label.required:after {
  content: ' *';
  color: var(--red);
}

.form-group input,
.form-group select {
  width: 100%;
  padding: 12px 15px;
  border: 1.5px solid var(--border);
  border-radius: 6px;
  font-family: 'Poppins';
  font-size: 14px;
  transition: all 0.3s;
}

.form-group input:focus,
.form-group select:focus {
  outline: none;
  border-color: var(--blue);
  box-shadow: 0 0 0 3px rgba(0, 114, 206, 0.1);
}

.form-group.full-width {
  grid-column: 1 / -1;
}

.save-btn {
  grid-column: 1 / -1;
  background: var(--green);
  color: var(--white);
  border: none;
  padding: 14px;
  border-radius: 6px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.3s;
  font-size: 16px;
  margin-top: 10px;
}

.save-btn:hover {
  background: var(--light-green);
  transform: translateY(-2px);
  box-shadow: 0 6px 15px rgba(0, 132, 61, 0.2);
}

/* ========== VIEW MODAL STYLES ========== */
.view-content {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 30px;
  align-items: start;
}

.view-photo {
  width: 150px;
  height: 150px;
  border-radius: 50%;
  object-fit: cover;
  border: 4px solid var(--green);
  box-shadow: 0 8px 20px rgba(0,0,0,0.15);
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
  color: var(--blue);
  font-size: 14px;
  margin-bottom: 6px;
  display: block;
}

.detail-value {
  color: #333;
  padding: 10px 15px;
  background: #f8f9fa;
  border-radius: 8px;
  border-left: 4px solid var(--green);
  min-height: 44px;
  display: flex;
  align-items: center;
}

/* ========== DELETE MODAL ========== */
.delete-modal .modal-content h2 {
  color: var(--red);
}

.delete-modal p {
  margin: 20px 0;
  color: #666;
  line-height: 1.6;
  font-size: 16px;
}

.delete-modal .save-btn {
  background: var(--red);
}

.delete-modal .save-btn:hover {
  background: #e53935;
}

/* ========== ALERT POPUP ========== */
.alert-popup {
  display: none;
  position: fixed;
  top: 100px;
  right: 20px;
  background: var(--white);
  border-radius: 10px;
  padding: 20px 25px;
  text-align: center;
  z-index: 4000;
  box-shadow: 0 10px 25px rgba(0,0,0,0.25);
  min-width: 300px;
  max-width: 400px;
  animation: slideInRight 0.3s ease;
}

.alert-popup.show {
  display: block;
  animation: slideInRight 0.3s ease;
}

.alert-popup.success {
  border-top: 4px solid var(--green);
}

.alert-popup.error {
  border-top: 4px solid var(--red);
}

.alert-popup i {
  font-size: 24px;
  margin-bottom: 10px;
  display: block;
}

.alert-popup h3 {
  margin: 0;
  font-size: 16px;
  font-weight: 600;
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

/* ========== RESPONSIVE DESIGN ========== */

/* Large Tablets (1024px) */
@media (max-width: 1024px) {
  .main-content {
    margin-left: 200px;
    padding: 18px;
  }
  
  body.sidebar-collapsed .main-content {
    margin-left: 60px;
  }
  
  .filters-row {
    gap: 12px;
  }
  
  .filter-group {
    min-width: calc(50% - 12px);
    flex: 0 0 calc(50% - 12px);
  }
  
  .filter-actions {
    flex: 0 0 100%;
    justify-content: flex-start;
    margin-top: 10px;
  }
  
  .view-content {
    grid-template-columns: 1fr;
    gap: 25px;
  }
  
  .view-photo {
    justify-self: center;
  }
}

/* Tablets (768px) */
@media (max-width: 768px) {
  .main-content {
    margin-left: 0 !important;
    padding: 15px;
    margin-bottom: 70px;
  }
  
  .page-header {
    flex-direction: column;
    align-items: stretch;
    gap: 15px;
  }
  
  .page-header h1 {
    font-size: 22px;
    text-align: center;
  }
  
  .add-btn {
    align-self: center;
    width: 100%;
    max-width: 200px;
    justify-content: center;
  }
  
  .filters-container {
    padding: 15px;
  }
  
  .filters-row {
    flex-direction: column;
    align-items: stretch;
    gap: 15px;
  }
  
  .filter-group {
    min-width: 100%;
    flex: 0 0 100%;
  }
  
  .filter-actions {
    justify-content: stretch;
    margin-top: 5px;
  }
  
  .filter-actions button {
    flex: 1;
    justify-content: center;
  }
  
  .modal-form {
    grid-template-columns: 1fr;
    gap: 15px;
  }
  
  .view-details {
    grid-template-columns: 1fr;
    gap: 15px;
  }
  
  .modal-content {
    padding: 25px 20px;
  }
  
  .alert-popup {
    top: 80px;
    right: 10px;
    left: 10px;
    max-width: none;
  }
}

/* Mobile Phones (480px) */
@media (max-width: 480px) {
  .main-content {
    padding: 12px;
    margin-bottom: 60px;
  }
  
  .page-header h1 {
    font-size: 20px;
  }
  
  .add-btn {
    padding: 10px 15px;
    font-size: 14px;
  }
  
  table {
    font-size: 13px;
  }
  
  thead th,
  tbody td {
    padding: 12px 10px;
  }
  
  .action-btns {
    gap: 5px;
  }
  
  .view-btn,
  .edit-btn,
  .del-btn {
    padding: 6px 8px;
    min-width: 32px;
    min-height: 32px;
    font-size: 12px;
  }
  
  .profile-photo {
    width: 36px;
    height: 36px;
  }
  
  .modal-content {
    padding: 20px 15px;
    border-radius: 8px;
  }
  
  .modal-content h2 {
    font-size: 20px;
    margin-bottom: 20px;
  }
  
  .close-modal {
    top: 10px;
    right: 15px;
    font-size: 24px;
  }
  
  .form-group input,
  .form-group select {
    padding: 10px 12px;
  }
  
  .view-photo {
    width: 120px;
    height: 120px;
  }
}

/* Small Mobile (360px) */
@media (max-width: 360px) {
  .page-header h1 {
    font-size: 18px;
  }
  
  .add-btn {
    padding: 8px 12px;
    font-size: 13px;
  }
  
  .filter-group input,
  .filter-group select {
    padding: 8px 8px 8px 30px;
    font-size: 13px;
  }
  
  .filter-icon {
    font-size: 14px;
  }
  
  table {
    font-size: 12px;
  }
  
  thead th,
  tbody td {
    padding: 10px 8px;
  }
  
  .action-btns {
    flex-direction: column;
    gap: 4px;
  }
  
  .view-btn,
  .edit-btn,
  .del-btn {
    width: 100%;
    justify-content: center;
  }
  
  .modal-content {
    padding: 15px 12px;
  }
  
  .modal-content h2 {
    font-size: 18px;
  }
}

/* Landscape Mode */
@media (max-height: 500px) and (orientation: landscape) {
  .main-content {
    margin-bottom: 50px;
  }
  
  .filters-container {
    padding: 15px;
  }
  
  .table-wrapper {
    max-height: 250px;
  }
  
  .modal-content {
    max-height: 80vh;
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
  .action-btns {
    display: none !important;
  }
  
  table {
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
}

/* Accessibility Improvements */
@media (prefers-reduced-motion: reduce) {
  * {
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
  
  .modal-content {
    border: 2px solid #000;
  }
  
  table {
    border: 2px solid #000;
  }
  
  thead th {
    border-bottom: 3px solid #000;
  }
}

/* Dark Mode Support */
@media (prefers-color-scheme: dark) {
  :root {
    --bg: #1a1a1a;
    --white: #2d2d2d;
    --light-gray: #3d3d3d;
    --border: #4d4d4d;
    color-scheme: dark;
  }
  
  body {
    background: var(--bg);
    color: #f0f0f0;
  }
  
  .modal-content,
  .filters-container,
  .table-container {
    background: var(--white);
    color: #f0f0f0;
  }
  
  .detail-value {
    background: #3d3d3d;
    color: #f0f0f0;
  }
  
  input,
  select {
    background: #3d3d3d;
    color: #f0f0f0;
    border-color: #5d5d5d;
  }
  
  .alert-popup {
    background: var(--white);
    color: #f0f0f0;
  }
}
</style>
</head>
<body>

<?php require_once('../includes/header.php'); ?>

<div class="main-content">
  <div class="page-header">
    <h1>Campus Management</h1>
    <button class="add-btn" onclick="openModal('addModal')">
      <i class="fas fa-plus"></i> Add Campus
    </button>
  </div>

  <!-- ✅ FILTERS -->
  <div class="filters-container">
    <form method="GET" id="filterForm">
      <div class="filters-row">
        <!-- Search -->
        <div class="filter-group">
          <label>Search Campuses</label>
          <div class="filter-wrapper">
            <i class="fas fa-search filter-icon"></i>
            <input type="text" name="search" placeholder="Search by name, code, city..." 
                   value="<?= htmlspecialchars($search) ?>" id="searchInput">
          </div>
        </div>
        
        <!-- Status Filter -->
        <div class="filter-group">
          <label>Status</label>
          <div class="filter-wrapper">
            <i class="fas fa-filter filter-icon"></i>
            <select name="status" id="statusFilter">
              <option value="">All Status</option>
              <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
              <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
          </div>
        </div>
        
        <!-- Country Filter -->
        <div class="filter-group">
          <label>Country</label>
          <div class="filter-wrapper">
            <i class="fas fa-globe filter-icon"></i>
            <select name="country" id="countryFilter">
              <option value="">All Countries</option>
              <?php foreach($countries as $country): ?>
                <option value="<?= htmlspecialchars($country) ?>" 
                  <?= $country_filter === $country ? 'selected' : '' ?>>
                  <?= htmlspecialchars($country) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        
        <!-- Filter Actions -->
        <div class="filter-actions">
          <button type="submit" class="add-btn">
            <i class="fas fa-filter"></i> Apply Filters
          </button>
          <button type="button" class="clear-btn" onclick="clearFilters()">
            <i class="fas fa-times"></i> Clear Filters
          </button>
        </div>
      </div>
      
      <div class="results-count">
        Showing <?= count($campuses) ?> campus<?= count($campuses) !== 1 ? 'es' : '' ?>
        <?php if(!empty($search) || !empty($status_filter) || !empty($country_filter)): ?>
          (filtered)
        <?php endif; ?>
      </div>
    </form>
  </div>

  <!-- ✅ TABLE -->
  <div class="table-container">
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Photo</th>
            <th>Name</th>
            <th>Code</th>
            <th>City</th>
            <th>Country</th>
            <th>Phone</th>
            <th>Email</th>
            <th>Password</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if($campuses): ?>
            <?php foreach($campuses as $i=>$c): ?>
            <tr>
              <td><?= $i+1 ?></td>
              <td>
                <img src="../<?= $c['profile_photo_path'] ?: 'assets/img/default.png' ?>" 
                     class="profile-photo" 
                     alt="<?= htmlspecialchars($c['campus_name']) ?>">
              </td>
              <td><?= htmlspecialchars($c['campus_name']) ?></td>
              <td><?= htmlspecialchars($c['campus_code']) ?></td>
              <td><?= htmlspecialchars($c['city']) ?></td>
              <td><?= htmlspecialchars($c['country']) ?></td>
              <td><?= htmlspecialchars($c['phone_number']) ?></td>
              <td><?= htmlspecialchars($c['email']) ?></td>
              <td>123</td>
              <td>
                <span class="status-<?= $c['status'] ?>">
                  <?= ucfirst($c['status']) ?>
                </span>
              </td>
              <td class="action-btns">
                <button class="view-btn" onclick="openViewModal(
                  <?= $c['campus_id'] ?>,
                  '<?= htmlspecialchars($c['campus_name']) ?>',
                  '<?= htmlspecialchars($c['campus_code']) ?>',
                  '<?= htmlspecialchars($c['address']) ?>',
                  '<?= htmlspecialchars($c['city']) ?>',
                  '<?= htmlspecialchars($c['country']) ?>',
                  '<?= htmlspecialchars($c['phone_number']) ?>',
                  '<?= htmlspecialchars($c['email']) ?>',
                  '<?= $c['status'] ?>',
                  '<?= $c['profile_photo_path'] ?: 'assets/img/default.png' ?>'
                )" title="View">
                  <i class="fa-solid fa-eye"></i>
                </button>
                <button class="edit-btn" onclick="openEditModal(
                  <?= $c['campus_id'] ?>,
                  '<?= htmlspecialchars($c['campus_name']) ?>',
                  '<?= htmlspecialchars($c['campus_code']) ?>',
                  '<?= htmlspecialchars($c['address']) ?>',
                  '<?= htmlspecialchars($c['city']) ?>',
                  '<?= htmlspecialchars($c['country']) ?>',
                  '<?= htmlspecialchars($c['phone_number']) ?>',
                  '<?= htmlspecialchars($c['email']) ?>',
                  '<?= $c['status'] ?>'
                )" title="Edit">
                  <i class="fa-solid fa-pen-to-square"></i>
                </button>
                <button class="del-btn" onclick="openDeleteModal(<?= $c['campus_id'] ?>)" title="Delete">
                  <i class="fa-solid fa-trash"></i>
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="11" class="no-results">
                <i class="fas fa-inbox"></i>
                <p>No campuses found</p>
                <?php if(!empty($search) || !empty($status_filter) || !empty($country_filter)): ?>
                  <small>Try adjusting your filters</small>
                <?php endif; ?>
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
    <h2>Add Campus</h2>
    <form class="modal-form" method="POST" enctype="multipart/form-data" onsubmit="return validateAddForm()">
      <input type="hidden" name="action" value="add">
      <div class="form-group">
        <label class="required">Campus Name</label>
        <input name="campus_name" id="add_campus_name" required>
      </div>
      <div class="form-group">
        <label class="required">Campus Code</label>
        <input name="campus_code" id="add_campus_code" required>
      </div>
      <div class="form-group">
        <label>Address</label>
        <input name="address">
      </div>
      <div class="form-group">
        <label class="required">Country</label>
        <input id="country_input" name="country" oninput="autoCityOptions()" required>
      </div>
      <div class="form-group">
        <label class="required">City</label>
        <select id="city_select" name="city" required>
          <option value="">-- Select City --</option>
        </select>
      </div>
      <div class="form-group">
        <label>Phone Number</label>
        <input name="phone_number">
      </div>
      <div class="form-group">
        <label>Email Address</label>
        <input type="email" name="email">
      </div>
      <div class="form-group full-width">
        <label>Profile Photo</label>
        <input type="file" name="profile_photo" accept="image/*">
      </div>
      <button class="save-btn" type="submit">Save Campus</button>
    </form>
  </div>
</div>

<!-- ✅ VIEW MODAL -->
<div class="modal" id="viewModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('viewModal')">&times;</span>
    <h2>Campus Details</h2>
    <div class="view-content">
      <img id="view_photo" src="" alt="Campus Photo" class="view-photo">
      <div class="view-details">
        <div class="detail-item">
          <div class="detail-label">Campus Name</div>
          <div class="detail-value" id="view_name"></div>
        </div>
        <div class="detail-item">
          <div class="detail-label">Campus Code</div>
          <div class="detail-value" id="view_code"></div>
        </div>
        <div class="detail-item">
          <div class="detail-label">Address</div>
          <div class="detail-value" id="view_address"></div>
        </div>
        <div class="detail-item">
          <div class="detail-label">City</div>
          <div class="detail-value" id="view_city"></div>
        </div>
        <div class="detail-item">
          <div class="detail-label">Country</div>
          <div class="detail-value" id="view_country"></div>
        </div>
        <div class="detail-item">
          <div class="detail-label">Phone Number</div>
          <div class="detail-value" id="view_phone"></div>
        </div>
        <div class="detail-item">
          <div class="detail-label">Email Address</div>
          <div class="detail-value" id="view_email"></div>
        </div>
        <div class="detail-item">
          <div class="detail-label">Status</div>
          <div class="detail-value" id="view_status"></div>
        </div>
      </div>
    </div>
    <div style="margin-top: 25px; text-align: center;">
      <button class="add-btn" onclick="closeModal('viewModal')">
        <i class="fas fa-times"></i> Close
      </button>
    </div>
  </div>
</div>

<!-- ✅ EDIT MODAL -->
<div class="modal" id="editModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('editModal')">&times;</span>
    <h2>Edit Campus</h2>
    <form class="modal-form" method="POST" enctype="multipart/form-data" onsubmit="return validateEditForm()">
      <input type="hidden" name="action" value="update">
      <input type="hidden" id="edit_id" name="campus_id">
      <div class="form-group">
        <label class="required">Campus Name</label>
        <input id="edit_name" name="campus_name" required>
      </div>
      <div class="form-group">
        <label class="required">Campus Code</label>
        <input id="edit_code" name="campus_code" required>
      </div>
      <div class="form-group">
        <label>Address</label>
        <input id="edit_address" name="address">
      </div>
      <div class="form-group">
        <label class="required">Country</label>
        <input id="edit_country" name="country" oninput="autoCityOptionsEdit()" required>
      </div>
      <div class="form-group">
        <label class="required">City</label>
        <select id="edit_city" name="city" required>
          <option value="">-- Select City --</option>
        </select>
      </div>
      <div class="form-group">
        <label>Phone Number</label>
        <input id="edit_phone" name="phone_number">
      </div>
      <div class="form-group">
        <label>Email Address</label>
        <input id="edit_email" name="email" type="email">
      </div>
      <div class="form-group">
        <label>Status</label>
        <select id="edit_status" name="status">
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
      <div class="form-group full-width">
        <label>New Profile Photo (Optional)</label>
        <input type="file" name="profile_photo" accept="image/*">
      </div>
      <button class="save-btn" type="submit">Update Campus</button>
    </form>
  </div>
</div>

<!-- ✅ DELETE MODAL -->
<div class="modal delete-modal" id="deleteModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('deleteModal')">&times;</span>
    <h2>Confirm Delete</h2>
    <p>Are you sure you want to delete this campus? This action cannot be undone.</p>
    <form method="POST">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="campus_id" id="delete_id">
      <div style="display: flex; gap: 15px; margin-top: 30px;">
        <button class="add-btn" type="button" onclick="closeModal('deleteModal')" style="background: var(--gray); flex: 1;">
          <i class="fas fa-times"></i> Cancel
        </button>
        <button class="save-btn" type="submit" style="background: var(--red); flex: 1;">
          <i class="fas fa-trash"></i> Delete
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ✅ ALERT POPUP -->
<div id="popup" class="alert-popup <?= $type ?>">
  <i><?= $type==='success'?'✔️':'❌' ?></i>
  <h3><?= $message ?></h3>
</div>

<script>
// ✅ MODAL HANDLERS
function openModal(id) {
  document.getElementById(id).classList.add('show');
  document.body.style.overflow = 'hidden';
}

function closeModal(id) {
  document.getElementById(id).classList.remove('show');
  document.body.style.overflow = 'auto';
}

// ✅ VIEW MODAL
function openViewModal(id, name, code, address, city, country, phone, email, status, photo) {
  openModal('viewModal');
  document.getElementById('view_name').textContent = name || 'N/A';
  document.getElementById('view_code').textContent = code || 'N/A';
  document.getElementById('view_address').textContent = address || 'N/A';
  document.getElementById('view_city').textContent = city || 'N/A';
  document.getElementById('view_country').textContent = country || 'N/A';
  document.getElementById('view_phone').textContent = phone || 'N/A';
  document.getElementById('view_email').textContent = email || 'N/A';
  document.getElementById('view_status').textContent = status ? status.charAt(0).toUpperCase() + status.slice(1) : 'N/A';
  document.getElementById('view_status').className = 'detail-value status-' + status;
  document.getElementById('view_photo').src = '../' + (photo || 'assets/img/default.png');
}

// ✅ EDIT MODAL
function openEditModal(id, name, code, address, city, country, phone, email, status) {
  openModal('editModal');
  document.getElementById('edit_id').value = id;
  document.getElementById('edit_name').value = name;
  document.getElementById('edit_code').value = code;
  document.getElementById('edit_address').value = address;
  document.getElementById('edit_country').value = country;
  document.getElementById('edit_phone').value = phone;
  document.getElementById('edit_email').value = email;
  document.getElementById('edit_status').value = status;
  populateCitySelect(document.getElementById('edit_city'), country, city);
}

// ✅ DELETE MODAL
function openDeleteModal(id) {
  openModal('deleteModal');
  document.getElementById('delete_id').value = id;
}

// ✅ SHOW ALERT
if("<?= $message ?>") {
  const popup = document.getElementById('popup');
  popup.classList.add('show');
  setTimeout(() => popup.classList.remove('show'), 3500);
}

// ✅ FORM VALIDATION
function validateAddForm() {
  const code = document.getElementById('add_campus_code').value.trim();
  if (code === '') {
    alert('Campus code is required!');
    return false;
  }
  return true;
}

function validateEditForm() {
  const code = document.getElementById('edit_code').value.trim();
  if (code === '') {
    alert('Campus code is required!');
    return false;
  }
  return true;
}

// ✅ FILTER FUNCTIONS
let searchTimeout;
document.getElementById('searchInput').addEventListener('input', function() {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => {
    document.getElementById('filterForm').submit();
  }, 600);
});

document.getElementById('statusFilter').addEventListener('change', function() {
  document.getElementById('filterForm').submit();
});

document.getElementById('countryFilter').addEventListener('change', function() {
  document.getElementById('filterForm').submit();
});

function clearFilters() {
  document.getElementById('searchInput').value = '';
  document.getElementById('statusFilter').value = '';
  document.getElementById('countryFilter').value = '';
  document.getElementById('filterForm').submit();
}

// ✅ COUNTRY → CITIES MAP
const countryCities = {
  "Somalia": ["Mogadishu", "Hargeisa", "Kismayo", "Bosaso", "Garowe", "Baidoa"],
  "Kenya": ["Nairobi", "Mombasa", "Kisumu", "Eldoret"],
  "Ethiopia": ["Addis Ababa", "Dire Dawa", "Mekelle"],
  "South Africa": ["Cape Town", "Johannesburg", "Durban", "Pretoria", "Port Elizabeth"],
  "Egypt": ["Cairo", "Alexandria", "Giza", "Luxor"],
  "United States": ["New York", "Los Angeles", "Chicago", "Houston", "Washington", "Boston", "San Francisco"],
  "United Kingdom": ["London", "Manchester", "Liverpool", "Birmingham"],
  "China": ["Beijing", "Shanghai", "Guangzhou", "Shenzhen"],
  "India": ["Delhi", "Mumbai", "Bangalore", "Chennai", "Kolkata"],
  "France": ["Paris", "Lyon", "Marseille", "Nice", "Toulouse"],
  "Germany": ["Berlin", "Munich", "Hamburg", "Frankfurt"],
  "Brazil": ["Brasilia", "Rio de Janeiro", "Sao Paulo"],
  "Australia": ["Sydney", "Melbourne", "Perth", "Brisbane", "Adelaide"],
  "Canada": ["Toronto", "Vancouver", "Ottawa", "Montreal"],
  "Japan": ["Tokyo", "Osaka", "Kyoto", "Hiroshima"],
  "Mexico": ["Mexico City", "Guadalajara", "Monterrey"],
  "Turkey": ["Ankara", "Istanbul", "Izmir"],
  "Saudi Arabia": ["Riyadh", "Jeddah", "Mecca", "Medina"],
  "Qatar": ["Doha"],
  "United Arab Emirates": ["Dubai", "Abu Dhabi", "Sharjah"],
  "Nigeria": ["Lagos", "Abuja", "Kano"],
  "South Korea": ["Seoul", "Busan"],
  "Italy": ["Rome", "Milan", "Naples"],
  "Spain": ["Madrid", "Barcelona", "Seville"],
  "Russia": ["Moscow", "Saint Petersburg"],
  "Argentina": ["Buenos Aires", "Cordoba"],
  "Chile": ["Santiago"],
  "Colombia": ["Bogota", "Medellin"],
  "Indonesia": ["Jakarta", "Bali"],
  "Thailand": ["Bangkok"],
  "Vietnam": ["Hanoi", "Ho Chi Minh City"],
  "Philippines": ["Manila", "Cebu"],
  "Pakistan": ["Karachi", "Lahore", "Islamabad"],
  "Bangladesh": ["Dhaka", "Chittagong"],
  "Iran": ["Tehran", "Mashhad"],
  "Iraq": ["Baghdad", "Basra"],
  "Israel": ["Jerusalem", "Tel Aviv"],
  "Palestine": ["Gaza", "Ramallah"],
  "Morocco": ["Rabat", "Casablanca"],
  "Tunisia": ["Tunis"],
  "Algeria": ["Algiers", "Oran"],
  "Ghana": ["Accra", "Kumasi"],
  "Uganda": ["Kampala"],
  "Tanzania": ["Dar es Salaam", "Arusha"],
  "Sudan": ["Khartoum"],
  "Djibouti": ["Djibouti"],
  "Eritrea": ["Asmara"],
  "Zambia": ["Lusaka"],
  "Zimbabwe": ["Harare"],
  "Namibia": ["Windhoek"],
  "Rwanda": ["Kigali"],
  "Burundi": ["Bujumbura"],
  "South Sudan": ["Juba"],
  "New Zealand": ["Auckland", "Wellington"],
  "Fiji": ["Suva"]
};

// ✅ GET CITIES FOR COUNTRY
function getCitiesForCountry(country) {
  if (!country) return [];
  let cNorm = country.trim().toLowerCase();
  for (const key in countryCities) {
    if (key.toLowerCase() === cNorm) {
      return countryCities[key];
    }
  }
  return [];
}

// ✅ POPULATE CITY SELECT
function populateCitySelect(selectEl, country, selectedCity) {
  const cities = getCitiesForCountry(country);
  selectEl.innerHTML = "";
  
  if (!cities.length) {
    const opt = document.createElement('option');
    opt.value = "";
    opt.textContent = "No cities found";
    selectEl.appendChild(opt);
    return;
  }
  
  const placeholder = document.createElement('option');
  placeholder.value = "";
  placeholder.textContent = "-- Select City --";
  selectEl.appendChild(placeholder);

  cities.forEach(city => {
    const opt = document.createElement('option');
    opt.value = city;
    opt.textContent = city;
    if (selectedCity && selectedCity.toLowerCase() === city.toLowerCase()) {
      opt.selected = true;
    }
    selectEl.appendChild(opt);
  });
}

// ✅ AUTO-POPULATE CITIES
function autoCityOptions() {
  const country = document.getElementById('country_input').value;
  const citySelect = document.getElementById('city_select');
  populateCitySelect(citySelect, country, "");
}

function autoCityOptionsEdit() {
  const country = document.getElementById('edit_country').value;
  const citySelect = document.getElementById('edit_city');
  const currentCity = citySelect.value;
  populateCitySelect(citySelect, country, currentCity);
}

// ✅ CLOSE MODALS WITH ESC KEY
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    const modals = document.querySelectorAll('.modal.show');
    modals.forEach(modal => {
      modal.classList.remove('show');
      document.body.style.overflow = 'auto';
    });
  }
});

// ✅ CLOSE MODALS WHEN CLICKING OUTSIDE
window.addEventListener('click', function(event) {
  const modals = document.querySelectorAll('.modal.show');
  modals.forEach(modal => {
    if (event.target === modal) {
      modal.classList.remove('show');
      document.body.style.overflow = 'auto';
    }
  });
});

// ✅ TOUCH SUPPORT FOR MOBILE
document.addEventListener('touchstart', function() {}, {passive: true});

// ✅ INITIALIZE
document.addEventListener('DOMContentLoaded', function() {
  // Add loading indicator
  const mainContent = document.querySelector('.main-content');
  
  // Check for touch devices
  if ('ontouchstart' in window || navigator.maxTouchPoints) {
    document.body.classList.add('touch-device');
  }
  
  // Responsive table adjustments
  function adjustTable() {
    const tableWrapper = document.querySelector('.table-wrapper');
    if (window.innerWidth < 768) {
      tableWrapper.style.maxHeight = '400px';
    } else {
      tableWrapper.style.maxHeight = '500px';
    }
  }
  
  adjustTable();
  window.addEventListener('resize', adjustTable);
});
</script>

<script src="../assets/js/sidebar.js"></script>
<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>