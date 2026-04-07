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

/* ===========================================
   CRUD OPERATIONS
=========================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // 🟢 ADD
  if ($_POST['action'] === 'add') {
    try {
      $name = trim($_POST['semester_name']);
      $desc = trim($_POST['description']);
      $status = $_POST['status'] ?? 'active';

      if ($name == "") throw new Exception("Semester name is required!");

      $stmt = $pdo->prepare("INSERT INTO semester (semester_name, description, status) VALUES (?, ?, ?)");
      $stmt->execute([$name, $desc, $status]);

      $message = "✅ Semester added successfully!";
      $type = "success";

    } catch (Exception $e) {
      $message = "❌ " . $e->getMessage();
      $type = "error";
    }
  }

  // 🟡 UPDATE
  if ($_POST['action'] === 'update') {
    try {
      $id = $_POST['semester_id'];
      $name = trim($_POST['semester_name']);
      $desc = trim($_POST['description']);
      $status = $_POST['status'];

      if ($name == "") throw new Exception("Semester name cannot be empty!");

      $stmt = $pdo->prepare("UPDATE semester SET semester_name=?, description=?, status=?, updated_at=NOW() WHERE semester_id=?");
      $stmt->execute([$name, $desc, $status, $id]);

      $message = "✅ Semester updated successfully!";
      $type = "success";

    } catch (Exception $e) {
      $message = "❌ " . $e->getMessage();
      $type = "error";
    }
  }

  // 🔴 DELETE
  if ($_POST['action'] === 'delete') {
    try {
      $id = $_POST['semester_id'];
      $pdo->prepare("DELETE FROM semester WHERE semester_id=?")->execute([$id]);
      $message = "✅ Semester deleted successfully!";
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
$semesters = $pdo->query("SELECT * FROM semester ORDER BY semester_id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Get total counts for stats
$total_semesters = $pdo->query("SELECT COUNT(*) FROM semester")->fetchColumn();
$active_semesters = $pdo->query("SELECT COUNT(*) FROM semester WHERE status='active'")->fetchColumn();
$inactive_semesters = $pdo->query("SELECT COUNT(*) FROM semester WHERE status='inactive'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Semester Management | University System</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
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

/* ===========================================
   MAIN CONTENT AREA
=========================================== */
.main-content {
  margin-top: 65px;
  margin-left: 240px;
  padding: 25px;
  transition: margin-left 0.3s ease;
  min-height: calc(100vh - 115px);
  margin-bottom: 70px;
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
   STATS CARDS
============================== */
.stats-container {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
.stat-card.inactive { border-left-color: var(--purple-color); }

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
.stat-icon.inactive { background: var(--purple-color); }

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
  padding: 0 20px 20px;
  max-height: 480px;
}

.data-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 14px;
}

.data-table thead {
  background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
  position: sticky;
  top: 0;
  z-index: 10;
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

.data-table tbody tr:nth-child(even) {
  background: rgba(0, 114, 206, 0.02);
}

.data-table tbody tr:last-child td {
  border-bottom: none;
}

/* Status badges */
.status-badge {
  padding: 6px 15px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 500;
  display: inline-block;
  text-transform: uppercase;
  letter-spacing: 0.5px;
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

/* Action buttons */
.action-btns {
  display: flex;
  gap: 8px;
  justify-content: center;
  flex-wrap: wrap;
}

.action-btn {
  width: 38px;
  height: 38px;
  border-radius: 8px;
  border: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s ease;
  font-size: 15px;
  box-shadow: 0 3px 8px rgba(0, 0, 0, 0.1);
}

.view-btn {
  background: #6c757d;
  color: var(--white);
}

.view-btn:hover {
  background: #5a6268;
  transform: translateY(-2px);
  box-shadow: 0 5px 12px rgba(108, 117, 125, 0.3);
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
  max-width: 600px;
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

/* View Modal Styles */
.view-modal .modal-content {
  max-width: 700px;
}

.view-content {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 25px;
  margin-top: 20px;
}

.view-detail {
  background: #f8f9fa;
  border-radius: 10px;
  padding: 20px;
  border-left: 4px solid var(--primary-color);
}

.view-detail:nth-child(odd) {
  border-left-color: var(--secondary-color);
}

.view-label {
  font-size: 12px;
  color: #666;
  text-transform: uppercase;
  letter-spacing: 1px;
  margin-bottom: 8px;
  font-weight: 600;
}

.view-value {
  font-size: 16px;
  color: var(--dark-color);
  font-weight: 500;
  word-break: break-word;
}

.view-value.description {
  grid-column: 1 / -1;
  background: #f8f9fa;
  padding: 20px;
  border-radius: 10px;
  border-left: 4px solid var(--light-color);
  min-height: 100px;
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

/* ==============================
   ALERT POPUP
============================== */
.alert-popup {
  display: none;
  position: fixed;
  top: 20px;
  right: 20px;
  background: var(--white);
  border-radius: 12px;
  padding: 20px 25px;
  text-align: center;
  z-index: 1100;
  box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2);
  min-width: 300px;
  max-width: 400px;
  animation: alertSlideIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
  border-top: 5px solid;
}

@keyframes alertSlideIn {
  from {
    opacity: 0;
    transform: translateX(100px);
  }
  to {
    opacity: 1;
    transform: translateX(0);
  }
}

.alert-popup.show {
  display: block;
  animation: alertSlideIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}

.alert-popup.success {
  border-top-color: var(--primary-color);
}

.alert-popup.error {
  border-top-color: var(--danger-color);
}

.alert-icon {
  font-size: 32px;
  margin-bottom: 15px;
  display: block;
  width: 60px;
  height: 60px;
  line-height: 60px;
  border-radius: 50%;
  margin: 0 auto 15px;
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
   RESPONSIVE DESIGN
============================== */

/* Large Desktop (1200px+) */
@media (min-width: 1200px) {
  .main-content {
    max-width: 1200px;
    margin-left: auto;
    margin-right: auto;
  }
  
  body.sidebar-collapsed .main-content {
    margin-left: auto;
    margin-right: auto;
  }
}

/* Desktop (1024px - 1200px) */
@media (max-width: 1200px) {
  .main-content {
    margin-left: 240px;
    padding: 20px;
  }
  
  body.sidebar-collapsed .main-content {
    margin-left: 60px;
  }
  
  .view-content {
    grid-template-columns: 1fr 1fr;
  }
}

/* Tablet (768px - 1024px) */
@media (max-width: 1024px) {
  .main-content {
    margin-left: 0 !important;
    padding: 20px;
  }
  
  .page-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 15px;
    padding: 15px;
  }
  
  .add-btn {
    align-self: stretch;
    justify-content: center;
  }
  
  .form-grid {
    grid-template-columns: 1fr;
  }
  
  .stat-card {
    padding: 15px;
  }
  
  .action-btns {
    flex-wrap: wrap;
    justify-content: center;
  }
  
  .modal-content {
    padding: 25px 20px;
    max-width: 95%;
  }
  
  .stats-container {
    grid-template-columns: repeat(2, 1fr);
  }
  
  .view-content {
    grid-template-columns: 1fr;
  }
  
  .table-container {
    max-height: 400px;
  }
}

/* Mobile (480px - 768px) */
@media (max-width: 768px) {
  .main-content {
    padding: 15px;
    margin-bottom: 70px;
  }
  
  .page-header h1 {
    font-size: 22px;
  }
  
  .page-header h1 i {
    padding: 8px;
    font-size: 18px;
  }
  
  .add-btn {
    padding: 10px 20px;
    font-size: 14px;
  }
  
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
  
  .table-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 10px;
    padding: 15px;
  }
  
  .data-table th,
  .data-table td {
    padding: 12px 15px;
  }
  
  .action-btns {
    gap: 5px;
  }
  
  .action-btn {
    width: 32px;
    height: 32px;
    font-size: 13px;
  }
  
  .modal-content {
    padding: 20px 15px;
  }
  
  .modal-content h2 {
    font-size: 20px;
  }
  
  .view-detail {
    padding: 15px;
  }
  
  .alert-popup {
    top: 15px;
    right: 15px;
    left: 15px;
    min-width: auto;
    max-width: none;
    padding: 15px 20px;
  }
  
  .alert-icon {
    width: 50px;
    height: 50px;
    line-height: 50px;
    font-size: 24px;
  }
}

/* Small Mobile (< 480px) */
@media (max-width: 480px) {
  .page-header h1 {
    font-size: 20px;
  }
  
  .add-btn {
    width: 100%;
  }
  
  .data-table {
    font-size: 13px;
  }
  
  .data-table th,
  .data-table td {
    padding: 10px 12px;
  }
  
  .action-btns {
    flex-direction: column;
    align-items: center;
  }
  
  .action-btn {
    width: 100%;
    margin-bottom: 5px;
  }
  
  .modal-content {
    padding: 15px;
    max-width: 98%;
  }
  
  .close-modal {
    top: 10px;
    right: 15px;
    font-size: 24px;
    width: 32px;
    height: 32px;
  }
  
  .view-detail {
    grid-column: 1 / -1;
  }
  
  .form-control {
    padding: 10px 15px;
  }
  
  .submit-btn {
    padding: 12px 20px;
    font-size: 14px;
  }
}

/* Landscape Mode */
@media (max-height: 500px) and (orientation: landscape) {
  .modal-content {
    max-height: 85vh;
    padding: 20px;
  }
  
  .table-container {
    max-height: 300px;
  }
  
  .main-content {
    margin-bottom: 60px;
  }
}

/* Print Styles */
@media print {
  .page-header button,
  .action-btns,
  .modal,
  .alert-popup {
    display: none !important;
  }
  
  .main-content {
    margin: 0;
    padding: 0;
  }
  
  .table-wrapper {
    box-shadow: none;
    border: 1px solid #ddd;
  }
  
  .data-table {
    width: 100%;
    border-collapse: collapse;
  }
  
  .data-table th {
    background: #f0f0f0 !important;
    -webkit-print-color-adjust: exact;
    color-adjust: exact;
  }
}

/* ==============================
   SCROLLBAR STYLING
============================== */
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
  background: var(--secondary-color);
  border-radius: 4px;
}

.table-container::-webkit-scrollbar-thumb:hover,
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

.data-table tbody tr {
  animation: fadeIn 0.4s ease forwards;
}

.data-table tbody tr:nth-child(1) { animation-delay: 0.05s; }
.data-table tbody tr:nth-child(2) { animation-delay: 0.1s; }
.data-table tbody tr:nth-child(3) { animation-delay: 0.15s; }
.data-table tbody tr:nth-child(4) { animation-delay: 0.2s; }
.data-table tbody tr:nth-child(5) { animation-delay: 0.25s; }
.data-table tbody tr:nth-child(6) { animation-delay: 0.3s; }

/* Loading animation */
.loading {
  display: inline-block;
  width: 20px;
  height: 20px;
  border: 3px solid rgba(255, 255, 255, 0.3);
  border-radius: 50%;
  border-top-color: var(--white);
  animation: spin 1s ease-in-out infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

/* Touch Device Optimizations */
@media (hover: none) and (pointer: coarse) {
  .action-btn,
  .add-btn,
  .submit-btn {
    min-height: 44px;
    min-width: 44px;
  }
  
  .data-table tbody tr {
    min-height: 60px;
  }
  
  .table-container {
    -webkit-overflow-scrolling: touch;
  }
}
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
  <div class="page-header">
    <h1><i class="fas fa-calendar-alt"></i> Semester Management</h1>
    <button class="add-btn" onclick="openModal('addModal')" aria-label="Add new semester">
      <i class="fa-solid fa-plus"></i> Add New Semester
    </button>
  </div>

  <!-- Stats Cards -->
  <div class="stats-container">
    <div class="stat-card total">
      <div class="stat-icon total">
        <i class="fas fa-calendar"></i>
      </div>
      <div class="stat-info">
        <h3>Total Semesters</h3>
        <div class="number"><?= $total_semesters ?></div>
      </div>
    </div>
    
    <div class="stat-card active">
      <div class="stat-icon active">
        <i class="fas fa-check-circle"></i>
      </div>
      <div class="stat-info">
        <h3>Active Semesters</h3>
        <div class="number"><?= $active_semesters ?></div>
      </div>
    </div>
    
    <div class="stat-card inactive">
      <div class="stat-icon inactive">
        <i class="fas fa-times-circle"></i>
      </div>
      <div class="stat-info">
        <h3>Inactive Semesters</h3>
        <div class="number"><?= $inactive_semesters ?></div>
      </div>
    </div>
  </div>

  <!-- ✅ MAIN TABLE -->
  <div class="table-wrapper">
    <div class="table-header">
      <h3>Semester List</h3>
      <div class="results-count">
        Showing <?= count($semesters) ?> semester<?= count($semesters) !== 1 ? 's' : '' ?>
      </div>
    </div>
    
    <div class="table-container">
      <table class="data-table" aria-label="List of semesters">
        <thead>
          <tr>
            <th>#</th>
            <th>Semester Name</th>
            <th>Description</th>
            <th>Status</th>
            <th>Created</th>
            <th>Updated</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if($semesters): ?>
            <?php foreach($semesters as $i=>$s): ?>
            <tr>
              <td><?= $i+1 ?></td>
              <td><strong><?= htmlspecialchars($s['semester_name']) ?></strong></td>
              <td><?= htmlspecialchars($s['description']) ?: '—' ?></td>
              <td>
                <span class="status-badge status-<?= $s['status'] ?>">
                  <i class="fas fa-circle" style="font-size: 8px; margin-right: 5px;"></i>
                  <?= ucfirst($s['status']) ?>
                </span>
              </td>
              <td><?= date('M d, Y', strtotime($s['created_at'])) ?></td>
              <td><?= date('M d, Y', strtotime($s['updated_at'])) ?></td>
              <td>
                <div class="action-btns">
                  <button class="action-btn view-btn" 
                          onclick="openViewModal(
                            <?= $s['semester_id'] ?>,
                            '<?= htmlspecialchars(addslashes($s['semester_name'])) ?>',
                            '<?= htmlspecialchars(addslashes($s['description'])) ?>',
                            '<?= $s['status'] ?>',
                            '<?= $s['created_at'] ?>',
                            '<?= $s['updated_at'] ?>'
                          )"
                          title="View semester details"
                          aria-label="View semester <?= htmlspecialchars($s['semester_name']) ?>">
                    <i class="fa-solid fa-eye"></i>
                  </button>
                  <button class="action-btn edit-btn" 
                          onclick="openEditModal(
                            <?= $s['semester_id'] ?>,
                            '<?= htmlspecialchars(addslashes($s['semester_name'])) ?>',
                            '<?= htmlspecialchars(addslashes($s['description'])) ?>',
                            '<?= $s['status'] ?>'
                          )"
                          title="Edit semester"
                          aria-label="Edit semester <?= htmlspecialchars($s['semester_name']) ?>">
                    <i class="fa-solid fa-pen"></i>
                  </button>
                  <button class="action-btn del-btn" 
                          onclick="openDeleteModal(<?= $s['semester_id'] ?>)" 
                          title="Delete semester"
                          aria-label="Delete semester <?= htmlspecialchars($s['semester_name']) ?>">
                    <i class="fa-solid fa-trash"></i>
                  </button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="7">
                <div class="empty-state">
                  <i class="fa-solid fa-calendar-alt"></i>
                  <h3>No semesters found</h3>
                  <p>Add your first semester using the button above</p>
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
<div class="modal" id="addModal" role="dialog" aria-labelledby="addModalTitle" aria-hidden="true">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('addModal')" aria-label="Close modal">&times;</span>
    <h2 id="addModalTitle"><i class="fas fa-plus-circle"></i> Add New Semester</h2>
    <form method="POST" id="addForm" onsubmit="return validateForm('add')">
      <input type="hidden" name="action" value="add">
      
      <div class="form-grid">
        <div class="form-group">
          <label for="semester_name">Semester Name *</label>
          <input type="text" id="semester_name" name="semester_name" class="form-control" 
                 required aria-required="true" placeholder="Enter semester name">
          <div class="error-message" id="semester_name_error" style="display: none;"></div>
        </div>
        
        <div class="form-group">
          <label for="status">Status *</label>
          <select id="status" name="status" class="form-control" required aria-required="true">
            <option value="">Select Status</option>
            <option value="active" selected>Active</option>
            <option value="inactive">Inactive</option>
          </select>
          <div class="error-message" id="status_error" style="display: none;"></div>
        </div>
        
        <div class="form-group">
          <label for="description">Description</label>
          <textarea id="description" name="description" class="form-control" rows="3" 
                    placeholder="Enter semester description (optional)"></textarea>
        </div>
      </div>
      
      <button class="submit-btn" type="submit">
        <i class="fas fa-save"></i> Save Semester
      </button>
    </form>
  </div>
</div>

<!-- ✅ VIEW MODAL -->
<div class="modal view-modal" id="viewModal" role="dialog" aria-labelledby="viewModalTitle" aria-hidden="true">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('viewModal')" aria-label="Close modal">&times;</span>
    <h2 id="viewModalTitle"><i class="fas fa-eye"></i> Semester Details</h2>
    
    <div class="view-content">
      <div class="view-detail">
        <div class="view-label">Semester Name</div>
        <div class="view-value" id="view_name"></div>
      </div>
      
      <div class="view-detail">
        <div class="view-label">Status</div>
        <div class="view-value" id="view_status"></div>
      </div>
      
      <div class="view-detail">
        <div class="view-label">Created Date</div>
        <div class="view-value" id="view_created"></div>
      </div>
      
      <div class="view-detail">
        <div class="view-label">Updated Date</div>
        <div class="view-value" id="view_updated"></div>
      </div>
      
      <div class="view-detail description">
        <div class="view-label">Description</div>
        <div class="view-value" id="view_description"></div>
      </div>
    </div>
    
    <div style="text-align: center; margin-top: 30px;">
      <button class="submit-btn" onclick="closeModal('viewModal')" style="background: #6c757d;">
        <i class="fas fa-times"></i> Close
      </button>
    </div>
  </div>
</div>

<!-- ✅ EDIT MODAL -->
<div class="modal" id="editModal" role="dialog" aria-labelledby="editModalTitle" aria-hidden="true">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('editModal')" aria-label="Close modal">&times;</span>
    <h2 id="editModalTitle"><i class="fas fa-edit"></i> Edit Semester</h2>
    <form method="POST" id="editForm" onsubmit="return validateForm('edit')">
      <input type="hidden" name="action" value="update">
      <input type="hidden" id="edit_id" name="semester_id">
      
      <div class="form-grid">
        <div class="form-group">
          <label for="edit_name">Semester Name *</label>
          <input type="text" id="edit_name" name="semester_name" class="form-control" required>
          <div class="error-message" id="edit_name_error" style="display: none;"></div>
        </div>
        
        <div class="form-group">
          <label for="edit_status">Status *</label>
          <select id="edit_status" name="status" class="form-control" required>
            <option value="">Select Status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
          <div class="error-message" id="edit_status_error" style="display: none;"></div>
        </div>
        
        <div class="form-group">
          <label for="edit_desc">Description</label>
          <textarea id="edit_desc" name="description" class="form-control" rows="3"></textarea>
        </div>
      </div>
      
      <button class="submit-btn" type="submit">
        <i class="fas fa-sync-alt"></i> Update Semester
      </button>
    </form>
  </div>
</div>

<!-- ✅ DELETE MODAL -->
<div class="modal" id="deleteModal" role="dialog" aria-labelledby="deleteModalTitle" aria-hidden="true">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('deleteModal')" aria-label="Close modal">&times;</span>
    <h2 id="deleteModalTitle" style="color: var(--danger-color);"><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h2>
    <form method="POST">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="semester_id" id="delete_id">
      
      <div style="text-align: center; margin: 30px 0;">
        <i class="fa-solid fa-exclamation-triangle" style="font-size: 48px; color: var(--danger-color); margin-bottom: 20px;"></i>
        <p style="font-size: 16px; color: var(--dark-color); margin-bottom: 10px;">
          Are you sure you want to delete this semester?
        </p>
        <p style="color: #666; font-size: 14px;">
          This action cannot be undone. All associated data will be removed.
        </p>
      </div>
      
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 20px;">
        <button type="button" class="submit-btn" onclick="closeModal('deleteModal')" 
                style="background: #6c757d;">
          <i class="fas fa-times"></i> Cancel
        </button>
        <button class="submit-btn delete-btn" type="submit">
          <i class="fas fa-trash-alt"></i> Delete
        </button>
      </div>
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
// MODAL FUNCTIONS
// ===========================================
function openModal(modalId) {
    document.getElementById(modalId).classList.add('show');
    document.body.style.overflow = 'hidden';
    document.body.style.paddingRight = getScrollbarWidth() + 'px';
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
    document.body.style.overflow = 'auto';
    document.body.style.paddingRight = '0';
}

function getScrollbarWidth() {
    return window.innerWidth - document.documentElement.clientWidth;
}

function openViewModal(id, name, desc, status, created, updated) {
    openModal('viewModal');
    
    // Decode HTML entities
    const decodeHTML = (html) => {
        const txt = document.createElement('textarea');
        txt.innerHTML = html;
        return txt.value;
    };
    
    document.getElementById('view_name').textContent = decodeHTML(name) || '—';
    document.getElementById('view_description').textContent = decodeHTML(desc) || '—';
    
    const statusText = status === 'active' ? 'Active' : 'Inactive';
    document.getElementById('view_status').innerHTML = `
        <span class="status-badge status-${status}">
            <i class="fas fa-circle" style="font-size: 8px; margin-right: 5px;"></i>
            ${statusText}
        </span>
    `;
    
    document.getElementById('view_created').textContent = formatDate(created);
    document.getElementById('view_updated').textContent = formatDate(updated);
}

function openEditModal(id, name, desc, status) {
    openModal('editModal');
    
    const decodeHTML = (html) => {
        const txt = document.createElement('textarea');
        txt.innerHTML = html;
        return txt.value;
    };
    
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_name').value = decodeHTML(name);
    document.getElementById('edit_desc').value = decodeHTML(desc);
    document.getElementById('edit_status').value = status;
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
        day: 'numeric'
    });
}

// ===========================================
// FORM VALIDATION
// ===========================================
function validateForm(formType) {
    let isValid = true;
    const errorMessages = {
        'semester_name': 'Semester name is required',
        'edit_name': 'Semester name is required',
        'status': 'Please select a status',
        'edit_status': 'Please select a status'
    };
    
    if (formType === 'add') {
        // Required fields for add form
        const requiredFields = ['semester_name', 'status'];
        
        requiredFields.forEach(field => {
            const element = document.getElementById(field);
            const errorElement = document.getElementById(field + '_error');
            
            if (!element.value.trim()) {
                errorElement.textContent = errorMessages[field];
                errorElement.style.display = 'block';
                element.classList.add('error');
                element.style.borderColor = 'var(--danger-color)';
                isValid = false;
            } else {
                errorElement.textContent = '';
                errorElement.style.display = 'none';
                element.classList.remove('error');
                element.style.borderColor = '';
            }
        });
        
    } else if (formType === 'edit') {
        // Required fields for edit form
        const requiredFields = ['edit_name', 'edit_status'];
        
        requiredFields.forEach(field => {
            const element = document.getElementById(field);
            const errorElement = document.getElementById(field + '_error');
            
            if (!element.value.trim()) {
                errorElement.textContent = errorMessages[field];
                errorElement.style.display = 'block';
                element.classList.add('error');
                element.style.borderColor = 'var(--danger-color)';
                isValid = false;
            } else {
                errorElement.textContent = '';
                errorElement.style.display = 'none';
                element.classList.remove('error');
                element.style.borderColor = '';
            }
        });
    }
    
    return isValid;
}

// ===========================================
// EVENT LISTENERS
// ===========================================
// Close modal when clicking outside
window.addEventListener('click', function(event) {
    if (event.target.classList.contains('modal')) {
        const modals = document.querySelectorAll('.modal.show');
        modals.forEach(modal => {
            modal.classList.remove('show');
        });
        document.body.style.overflow = 'auto';
        document.body.style.paddingRight = '0';
    }
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Escape key closes modals
    if (e.key === 'Escape') {
        const openModals = document.querySelectorAll('.modal.show');
        openModals.forEach(modal => {
            modal.classList.remove('show');
        });
        document.body.style.overflow = 'auto';
        document.body.style.paddingRight = '0';
    }
    
    // Ctrl + N opens add modal
    if (e.ctrlKey && e.key === 'n') {
        e.preventDefault();
        openModal('addModal');
    }
});

// Auto-hide alert after 5 seconds
setTimeout(function() {
    const alert = document.getElementById('popup');
    if (alert && alert.classList.contains('show')) {
        alert.classList.remove('show');
    }
}, 5000);

// Close alert on click
document.addEventListener('click', function(e) {
    if (e.target.closest('.alert-popup.show')) {
        e.target.closest('.alert-popup.show').classList.remove('show');
    }
});

// Loading state for buttons
const forms = document.querySelectorAll('form');
forms.forEach(form => {
    form.addEventListener('submit', function() {
        const submitBtn = this.querySelector('button[type="submit"]');
        if (submitBtn) {
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<div class="loading"></div> Processing...';
            submitBtn.disabled = true;
            
            // Restore button after 5 seconds (in case of error)
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 5000);
        }
    });
});

// Responsive adjustments
function adjustLayout() {
    const actionButtons = document.querySelectorAll('.action-btns');
    const isMobile = window.innerWidth < 768;
    
    if (isMobile) {
        actionButtons.forEach(btnGroup => {
            btnGroup.style.flexDirection = 'column';
            btnGroup.style.alignItems = 'center';
        });
    } else {
        actionButtons.forEach(btnGroup => {
            btnGroup.style.flexDirection = 'row';
        });
    }
}

// Initial call
adjustLayout();

// Adjust on resize
window.addEventListener('resize', adjustLayout);

// Touch device optimizations
if ('ontouchstart' in window || navigator.maxTouchPoints > 0) {
    document.body.classList.add('touch-device');
    
    // Add touch feedback to buttons
    const buttons = document.querySelectorAll('.action-btn, .add-btn, .submit-btn');
    buttons.forEach(btn => {
        btn.addEventListener('touchstart', function() {
            this.style.transform = 'scale(0.95)';
        });
        
        btn.addEventListener('touchend', function() {
            this.style.transform = '';
        });
    });
}
</script>

<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>