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
   AUTOMATIC STATUS UPDATE
   =========================================== */
function updateExpiredAcademicYears($pdo) {
    try {
        $current_date = date('Y-m-d');
        
        // Update expired academic years to inactive
        $stmt = $pdo->prepare("
            UPDATE academic_year 
            SET status = 'inactive', updated_at = NOW()
            WHERE end_date < ? AND status = 'active'
        ");
        $stmt->execute([$current_date]);
        
        // Check if there's at least one active academic year
        $active_count = $pdo->query("SELECT COUNT(*) FROM academic_year WHERE status='active'")->fetchColumn();
        
        // If no active years, activate the most recent one that hasn't ended
        if ($active_count == 0) {
            $stmt = $pdo->prepare("
                SELECT academic_year_id 
                FROM academic_year 
                WHERE end_date >= ? 
                ORDER BY start_date DESC 
                LIMIT 1
            ");
            $stmt->execute([$current_date]);
            $recent_year = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($recent_year) {
                $pdo->prepare("UPDATE academic_year SET status='active', updated_at=NOW() WHERE academic_year_id=?")
                    ->execute([$recent_year['academic_year_id']]);
            }
        }
        
    } catch (Exception $e) {
        error_log("Failed to update expired academic years: " . $e->getMessage());
    }
}

// Run automatic status update
updateExpiredAcademicYears($pdo);

/* ===========================================
   CRUD OPERATIONS
=========================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $action = $_POST['action'] ?? '';
    $year_name = trim($_POST['year_name'] ?? '');
    $start = $_POST['start_date'] ?? '';
    $end = $_POST['end_date'] ?? '';
    $status = $_POST['status'] ?? '';
    
    // Current date for validation
    $current_date = date('Y-m-d');

    // 🟡 Check date validity
    if ($end < $start) {
      throw new Exception("⚠️ End date cannot be earlier than start date!");
    }
    
    // 🟡 Check if end date is in the past (for active status)
    if ($status === 'active' && $end < $current_date) {
        throw new Exception("⚠️ Cannot set an academic year with past end date as active!");
    }

    /* ================================
       ADD NEW ACADEMIC YEAR
    ================================ */
    if ($action === 'add') {
      $pdo->beginTransaction();

      // 🔍 Overlap check
      $check = $pdo->prepare("
        SELECT COUNT(*) FROM academic_year
        WHERE 
          (start_date <= ? AND end_date >= ?) 
          OR (start_date <= ? AND end_date >= ?)
          OR (? BETWEEN start_date AND end_date)
          OR (? BETWEEN start_date AND end_date)
      ");
      $check->execute([$start, $start, $end, $end, $start, $end]);

      if ($check->fetchColumn() > 0) {
        throw new Exception("⚠️ Another academic year overlaps with these dates!");
      }

      // If setting as active, deactivate others
      if ($status === 'active') {
        $pdo->exec("UPDATE academic_year SET status='inactive', updated_at=NOW()");
      }
      
      // Auto-correct status if end date is in past
      if ($end < $current_date && $status === 'active') {
          $status = 'inactive';
      }

      $stmt = $pdo->prepare("INSERT INTO academic_year (year_name, start_date, end_date, status) VALUES (?,?,?,?)");
      $stmt->execute([$year_name, $start, $end, $status]);
      
      $pdo->commit();
      $message = "✅ Academic Year added successfully!";
      $type = "success";
    }

    /* ================================
       UPDATE EXISTING YEAR
    ================================ */
    if ($action === 'update') {
      $pdo->beginTransaction();
      
      $id = $_POST['academic_year_id'];

      // 🔍 Overlap check (excluding itself)
      $check = $pdo->prepare("
        SELECT COUNT(*) FROM academic_year
        WHERE academic_year_id <> ?
          AND (
            (start_date <= ? AND end_date >= ?)
            OR (start_date <= ? AND end_date >= ?)
            OR (? BETWEEN start_date AND end_date)
            OR (? BETWEEN start_date AND end_date)
          )
      ");
      $check->execute([$id, $start, $start, $end, $end, $start, $end]);

      if ($check->fetchColumn() > 0) {
        throw new Exception("⚠️ Another academic year overlaps with these dates!");
      }

      // If setting as active, deactivate others and check end date
      if ($status === 'active') {
          // Check if end date is in past
          if ($end < $current_date) {
              throw new Exception("⚠️ Cannot activate an academic year with past end date!");
          }
          $pdo->exec("UPDATE academic_year SET status='inactive', updated_at=NOW()");
      }
      
      // Auto-correct status if end date is in past
      if ($end < $current_date && $status === 'active') {
          $status = 'inactive';
          $message = "ℹ️ Academic year updated. Status changed to inactive because end date is in the past.";
      }

      $stmt = $pdo->prepare("UPDATE academic_year 
        SET year_name=?, start_date=?, end_date=?, status=?, updated_at=NOW()
        WHERE academic_year_id=?");
      $stmt->execute([$year_name, $start, $end, $status, $id]);
      
      $pdo->commit();
      $message = $message ?: "✅ Academic Year updated successfully!";
      $type = "success";
    }

    /* ================================
       DELETE YEAR
    ================================ */
    if ($action === 'delete') {
      $pdo->beginTransaction();
      
      $stmt = $pdo->prepare("DELETE FROM academic_year WHERE academic_year_id=?");
      $stmt->execute([$_POST['academic_year_id']]);
      
      // After deletion, ensure at least one active year exists if possible
      $active_count = $pdo->query("SELECT COUNT(*) FROM academic_year WHERE status='active'")->fetchColumn();
      $current_date = date('Y-m-d');
      
      if ($active_count == 0) {
          $stmt = $pdo->prepare("
              SELECT academic_year_id 
              FROM academic_year 
              WHERE end_date >= ? 
              ORDER BY start_date DESC 
              LIMIT 1
          ");
          $stmt->execute([$current_date]);
          $recent_year = $stmt->fetch(PDO::FETCH_ASSOC);
          
          if ($recent_year) {
              $pdo->prepare("UPDATE academic_year SET status='active', updated_at=NOW() WHERE academic_year_id=?")
                  ->execute([$recent_year['academic_year_id']]);
          }
      }
      
      $pdo->commit();
      $message = "✅ Academic Year deleted successfully!";
      $type = "success";
    }

  } catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
      $pdo->rollBack();
    }
    $message = $e->getMessage();
    $type = "error";
  } catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
      $pdo->rollBack();
    }
    $message = "❌ Database Error: " . $e->getMessage();
    $type = "error";
  }
}

// ✅ GET FILTER PARAMETERS
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$year_filter = $_GET['year'] ?? '';

// ✅ Build query with filters
$query = "SELECT * FROM academic_year WHERE 1=1";
$params = [];

// Search filter
if (!empty($search)) {
  $query .= " AND (year_name LIKE ? OR start_date LIKE ? OR end_date LIKE ?)";
  $searchTerm = "%$search%";
  $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
}

// Status filter
if (!empty($status_filter)) {
  $query .= " AND status = ?";
  $params[] = $status_filter;
}

// Year filter by name
if (!empty($year_filter)) {
  $query .= " AND year_name LIKE ?";
  $params[] = "%$year_filter%";
}

$query .= " ORDER BY academic_year_id DESC";

// ✅ Execute query
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$years = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ Get total counts for stats
$total_years = $pdo->query("SELECT COUNT(*) FROM academic_year")->fetchColumn();
$active_years = $pdo->query("SELECT COUNT(*) FROM academic_year WHERE status='active'")->fetchColumn();
$inactive_years = $pdo->query("SELECT COUNT(*) FROM academic_year WHERE status='inactive'")->fetchColumn();

// ✅ Get expired count
$current_date = date('Y-m-d');
$expired_years = $pdo->prepare("SELECT COUNT(*) FROM academic_year WHERE end_date < ?")->execute([$current_date]);
$expired_years = $pdo->prepare("SELECT COUNT(*) FROM academic_year WHERE end_date < ?")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Academic Years Management | University System</title>
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
  --expired-color: #9E9E9E;
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
}

.stat-card:hover {
  transform: translateY(-5px);
}

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
.stat-icon.expired { background: var(--expired-color); }

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

.status-expired {
  background: #f5f5f5;
  color: var(--expired-color);
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

/* Duration badge */
.duration-badge {
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

/* Expired indicator */
.expired-indicator {
  position: relative;
}

.expired-indicator::after {
  content: "Expired";
  position: absolute;
  top: 5px;
  right: 5px;
  background: var(--danger-color);
  color: white;
  font-size: 9px;
  padding: 2px 6px;
  border-radius: 3px;
  animation: pulse 2s infinite;
}

@keyframes pulse {
  0% { opacity: 0.7; }
  50% { opacity: 1; }
  100% { opacity: 0.7; }
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
}

.modal.show {
  display: flex;
}

.modal-content {
  background: var(--white);
  border-radius: 12px;
  width: 100%;
  max-width: 700px;
  max-height: 90vh;
  overflow-y: auto;
  padding: 30px;
  position: relative;
  box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
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
  gap: 25px;
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
  padding: 12px 15px;
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

/* Status warning */
.status-warning {
  background: #fff3cd;
  color: #856404;
  padding: 10px;
  border-radius: 6px;
  border: 1px solid #ffeaa7;
  margin-top: 10px;
  font-size: 13px;
  display: flex;
  align-items: center;
  gap: 8px;
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

.submit-btn:disabled {
  background: #ccc;
  cursor: not-allowed;
  transform: none;
  box-shadow: none;
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

.alert-popup.warning {
  border-top: 4px solid var(--warning-color);
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
  
  .view-details {
    grid-template-columns: 1fr;
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
  
  .stat-card {
    padding: 15px;
  }
  
  .action-btns {
    flex-wrap: wrap;
  }
}
</style>
</head>
<body>

<?php require_once('../includes/header.php'); ?>

<div class="main-content">
  <div class="page-header">
    <h1><i class="fas fa-calendar-alt"></i> Academic Years Management</h1>
    <button class="add-btn" onclick="openModal('addModal')">
      <i class="fa-solid fa-plus"></i> Add Academic Year
    </button>
  </div>

  <!-- ✅ STATS CARDS -->
  <div class="stats-container">
    <div class="stat-card">
      <div class="stat-icon total">
        <i class="fas fa-calendar-alt"></i>
      </div>
      <div class="stat-info">
        <h3>Total Academic Years</h3>
        <div class="number"><?= $total_years ?></div>
      </div>
    </div>
    
    <div class="stat-card">
      <div class="stat-icon active">
        <i class="fas fa-check-circle"></i>
      </div>
      <div class="stat-info">
        <h3>Active Years</h3>
        <div class="number"><?= $active_years ?></div>
      </div>
    </div>
    
    <div class="stat-card">
      <div class="stat-icon inactive">
        <i class="fas fa-times-circle"></i>
      </div>
      <div class="stat-info">
        <h3>Inactive Years</h3>
        <div class="number"><?= $inactive_years ?></div>
      </div>
    </div>
    
    <div class="stat-card">
      <div class="stat-icon expired">
        <i class="fas fa-hourglass-end"></i>
      </div>
      <div class="stat-info">
        <h3>Expired Years</h3>
        <div class="number"><?= $expired_years ?></div>
      </div>
    </div>
  </div>

  <!-- ✅ FILTERS -->
  <div class="filters-container">
    <div class="filter-header">
      <h3><i class="fas fa-filter"></i> Quick Filters</h3>
    </div>
    
    <form method="GET" class="filter-form" id="filterForm">
      <div class="filter-group">
        <label for="search">Search</label>
        <div style="position:relative;">
          <i class="fas fa-search filter-icon"></i>
          <input type="text" 
                 id="search" 
                 name="search" 
                 class="filter-input" 
                 placeholder="Year name, start or end date..."
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
      
      <div class="filter-group">
        <label for="year">Year Name</label>
        <div style="position:relative;">
          <i class="fas fa-calendar filter-icon"></i>
          <input type="text" 
                 id="year" 
                 name="year" 
                 class="filter-input" 
                 placeholder="Filter by year name..."
                 value="<?= htmlspecialchars($year_filter) ?>">
        </div>
      </div>
      
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
      <h3>Academic Years List</h3>
      <div class="results-count">
        Showing <?= count($years) ?> of <?= $total_years ?> academic years
        <?php if(!empty($search) || !empty($status_filter) || !empty($year_filter)): ?>
          (filtered)
        <?php endif; ?>
        <?php if($expired_years > 0): ?>
          <span style="color: var(--expired-color); margin-left: 10px;">
            <i class="fas fa-exclamation-triangle"></i> <?= $expired_years ?> expired
          </span>
        <?php endif; ?>
      </div>
    </div>
    
    <div class="table-container">
      <table class="data-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Year Name</th>
            <th>Start Date</th>
            <th>End Date</th>
            <th>Duration</th>
            <th>Status</th>
            <th>Created At</th>
            <th>Updated At</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if($years): ?>
            <?php foreach($years as $i=>$y): 
              // Calculate duration in days
              $start_date = new DateTime($y['start_date']);
              $end_date = new DateTime($y['end_date']);
              $duration = $start_date->diff($end_date)->days + 1;
              $duration_text = $duration . ' day' . ($duration != 1 ? 's' : '');
              
              // Check if academic year is expired
              $current_date = new DateTime();
              $is_expired = $end_date < $current_date;
              $status_class = $y['status'];
              if ($is_expired && $y['status'] === 'active') {
                  $status_class = 'expired';
              }
            ?>
            <tr class="<?= $is_expired ? 'expired-indicator' : '' ?>">
              <td><?= $i+1 ?></td>
              <td>
                <strong><?= htmlspecialchars($y['year_name']) ?></strong>
                <?php if($is_expired): ?>
                  <span style="color: var(--expired-color); font-size: 11px; margin-left: 5px;">
                    <i class="fas fa-clock"></i> Ended
                  </span>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($y['start_date']) ?></td>
              <td>
                <?= htmlspecialchars($y['end_date']) ?>
                <?php if($is_expired): ?>
                  <span style="color: var(--danger-color); font-size: 11px; margin-left: 5px;">
                    <i class="fas fa-exclamation-circle"></i>
                  </span>
                <?php endif; ?>
              </td>
              <td>
                <span class="duration-badge">
                  <i class="fas fa-clock"></i> <?= $duration_text ?>
                </span>
              </td>
              <td>
                <span class="status-badge status-<?= $status_class ?>">
                  <?= ucfirst($status_class) ?>
                  <?php if($is_expired && $y['status'] === 'active'): ?>
                    <i class="fas fa-exclamation-triangle" style="margin-left: 5px;"></i>
                  <?php endif; ?>
                </span>
              </td>
              <td><?= htmlspecialchars($y['created_at']) ?></td>
              <td><?= htmlspecialchars($y['updated_at']) ?></td>
              <td>
                <div class="action-btns">
                  <button class="action-btn view-btn" 
                          onclick="openViewModal(
                            '<?= addslashes($y['year_name']) ?>',
                            '<?= $y['start_date'] ?>',
                            '<?= $y['end_date'] ?>',
                            '<?= $duration ?>',
                            '<?= $y['status'] ?>',
                            '<?= $y['created_at'] ?>',
                            '<?= $y['updated_at'] ?>',
                            '<?= $is_expired ? 'true' : 'false' ?>'
                          )"
                          title="View Details">
                    <i class="fa-solid fa-eye"></i>
                  </button>
                  <button class="action-btn edit-btn" 
                          onclick="openEditModal(
                            <?= $y['academic_year_id'] ?>,
                            '<?= addslashes($y['year_name']) ?>',
                            '<?= $y['start_date'] ?>',
                            '<?= $y['end_date'] ?>',
                            '<?= $y['status'] ?>'
                          )"
                          title="Edit">
                    <i class="fa-solid fa-pen"></i>
                  </button>
                  <button class="action-btn del-btn" 
                          onclick="openDeleteModal(<?= $y['academic_year_id'] ?>)" 
                          title="Delete">
                    <i class="fa-solid fa-trash"></i>
                  </button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="9">
                <div class="empty-state">
                  <i class="fa-solid fa-inbox"></i>
                  <h3>No academic years found</h3>
                  <p>
                    <?php if(!empty($search) || !empty($status_filter) || !empty($year_filter)): ?>
                      Try adjusting your filters or <a href="javascript:void(0)" onclick="clearFilters()">clear all filters</a>
                    <?php else: ?>
                      Add your first academic year using the button above
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

<!-- ✅ VIEW MODAL -->
<div class="modal" id="viewModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('viewModal')">&times;</span>
    <h2><i class="fas fa-eye"></i> Academic Year Details</h2>
    
    <div class="view-content">
      <div id="expiredWarning" class="status-warning" style="display: none;">
        <i class="fas fa-exclamation-triangle"></i>
        <span>This academic year has ended and is automatically marked as inactive.</span>
      </div>
      
      <div class="view-details">
        <div class="detail-item">
          <div class="detail-label"><i class="fas fa-calendar-alt"></i> Year Name</div>
          <div class="detail-value" id="view_name"></div>
        </div>
        
        <div class="detail-item">
          <div class="detail-label"><i class="fas fa-calendar-day"></i> Start Date</div>
          <div class="detail-value" id="view_start"></div>
        </div>
        
        <div class="detail-item">
          <div class="detail-label"><i class="fas fa-calendar-day"></i> End Date</div>
          <div class="detail-value" id="view_end"></div>
        </div>
        
        <div class="detail-item">
          <div class="detail-label"><i class="fas fa-clock"></i> Duration</div>
          <div class="detail-value" id="view_duration"></div>
        </div>
        
        <div class="detail-item">
          <div class="detail-label"><i class="fas fa-circle"></i> Status</div>
          <div class="detail-value" id="view_status"></div>
        </div>
        
        <div class="detail-item">
          <div class="detail-label"><i class="fas fa-calendar-plus"></i> Created At</div>
          <div class="detail-value" id="view_created"></div>
        </div>
        
        <div class="detail-item">
          <div class="detail-label"><i class="fas fa-calendar-check"></i> Updated At</div>
          <div class="detail-value" id="view_updated"></div>
        </div>
      </div>
    </div>
    
    <div class="view-actions" style="display: flex; justify-content: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
      <button class="add-btn" onclick="closeModal('viewModal')">
        <i class="fas fa-times"></i> Close
      </button>
    </div>
  </div>
</div>

<!-- ADD MODAL -->
<div class="modal" id="addModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('addModal')">&times;</span>
    <h2><i class="fas fa-plus-circle"></i> Add New Academic Year</h2>
    <form method="POST" id="addForm">
      <input type="hidden" name="action" value="add">
      
      <div class="form-grid">
        <div class="form-group">
          <label for="year_name">Year Name *</label>
          <input type="text" id="year_name" name="year_name" class="form-control" required>
        </div>
        
        <div class="form-group">
          <label for="add_status">Status *</label>
          <select id="add_status" name="status" class="form-control" required>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
        
        <div class="form-group">
          <label for="start_date">Start Date *</label>
          <input type="date" id="start_date" name="start_date" class="form-control" required>
        </div>
        
        <div class="form-group">
          <label for="end_date">End Date *</label>
          <input type="date" id="end_date" name="end_date" class="form-control" required>
        </div>
      </div>
      
     
      <button class="submit-btn" type="submit" id="addSubmitBtn">
        <i class="fas fa-save"></i> Save Academic Year
      </button>
    </form>
  </div>
</div>

<!-- EDIT MODAL -->
<div class="modal" id="editModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('editModal')">&times;</span>
    <h2><i class="fas fa-edit"></i> Edit Academic Year</h2>
    <form method="POST" id="editForm">
      <input type="hidden" name="action" value="update">
      <input type="hidden" id="edit_id" name="academic_year_id">
      
      <div class="form-grid">
        <div class="form-group">
          <label for="edit_name">Year Name *</label>
          <input type="text" id="edit_name" name="year_name" class="form-control" required>
        </div>
        
        <div class="form-group">
          <label for="edit_status">Status *</label>
          <select id="edit_status" name="status" class="form-control" required>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
        
        <div class="form-group">
          <label for="edit_start">Start Date *</label>
          <input type="date" id="edit_start" name="start_date" class="form-control" required>
        </div>
        
        <div class="form-group">
          <label for="edit_end">End Date *</label>
          <input type="date" id="edit_end" name="end_date" class="form-control" required>
        </div>
      </div>
      
      
      
      <button class="submit-btn" type="submit" id="editSubmitBtn">
        <i class="fas fa-sync-alt"></i> Update Academic Year
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
      <input type="hidden" name="academic_year_id" id="delete_id">
      
      <div style="text-align: center; margin: 30px 0;">
        <i class="fa-solid fa-exclamation-triangle" style="font-size: 48px; color: var(--danger-color); margin-bottom: 20px;"></i>
        <p style="font-size: 16px; color: var(--dark-color); margin-bottom: 10px;">
          Are you sure you want to delete this academic year?
        </p>
        <p style="color: #666; font-size: 14px;">
          This action cannot be undone and may affect related data.
        </p>
      </div>
      
      <button class="submit-btn delete-btn" type="submit">
        <i class="fas fa-trash-alt"></i> Yes, Delete Academic Year
      </button>
    </form>
  </div>
</div>

<!-- ALERT POPUP -->
<div id="popup" class="alert-popup <?= $type ?> <?= !empty($message)?'show':'' ?>">
  <span class="alert-icon"><?= $type==='success' ? '✓' : ($type==='error' ? '✗' : '⚠') ?></span>
  <div class="alert-message"><?= $message ?></div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
<script>
// Initialize Select2
$(document).ready(function() {
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

// ✅ OPEN VIEW MODAL
function openViewModal(name, start, end, duration, status, created, updated, isExpired) {
  openModal('viewModal');
  
  // Set all details
  document.getElementById('view_name').textContent = name || 'Not provided';
  document.getElementById('view_start').textContent = start || 'Not provided';
  document.getElementById('view_end').textContent = end || 'Not provided';
  document.getElementById('view_duration').textContent = duration + ' day' + (duration != 1 ? 's' : '');
  document.getElementById('view_created').textContent = created || 'Not provided';
  document.getElementById('view_updated').textContent = updated || 'Not provided';
  
  // Show/hide expired warning
  const expiredWarning = document.getElementById('expiredWarning');
  if (isExpired === 'true') {
    expiredWarning.style.display = 'block';
    if (status === 'active') {
      status = 'inactive (expired)';
    }
  } else {
    expiredWarning.style.display = 'none';
  }
  
  // Set status with appropriate color
  const statusElement = document.getElementById('view_status');
  statusElement.textContent = status ? status.charAt(0).toUpperCase() + status.slice(1) : 'Not provided';
  
  if (status.includes('active')) {
    statusElement.style.borderLeftColor = 'var(--primary-color)';
  } else if (status.includes('expired')) {
    statusElement.style.borderLeftColor = 'var(--expired-color)';
  } else {
    statusElement.style.borderLeftColor = 'var(--danger-color)';
  }
}

// Open edit modal with data
function openEditModal(id, name, start, end, status) {
  openModal('editModal');
  
  // Set form values
  document.getElementById('edit_id').value = id;
  document.getElementById('edit_name').value = name;
  document.getElementById('edit_start').value = start;
  document.getElementById('edit_end').value = end;
  document.getElementById('edit_status').value = status;
  
  // Check if end date is in past
  checkEditDateStatus();
}

// Open delete modal
function openDeleteModal(id) {
  openModal('deleteModal');
  document.getElementById('delete_id').value = id;
}

// Date validation and status check functions
function checkAddDateStatus() {
    const startDate = new Date(document.getElementById('start_date').value);
    const endDate = new Date(document.getElementById('end_date').value);
    const currentDate = new Date();
    const statusSelect = document.getElementById('add_status');
    const warning = document.getElementById('addStatusWarning');
    const submitBtn = document.getElementById('addSubmitBtn');
    
    if (endDate < currentDate) {
        // End date is in past
        warning.style.display = 'block';
        if (statusSelect.value === 'active') {
            statusSelect.value = 'inactive';
        }
        statusSelect.disabled = true;
    } else {
        warning.style.display = 'none';
        statusSelect.disabled = false;
    }
    
    // Enable/disable submit button based on validation
    if (endDate < startDate) {
        submitBtn.disabled = true;
        submitBtn.title = "End date cannot be earlier than start date";
    } else {
        submitBtn.disabled = false;
        submitBtn.title = "";
    }
}

function checkEditDateStatus() {
    const startDate = new Date(document.getElementById('edit_start').value);
    const endDate = new Date(document.getElementById('edit_end').value);
    const currentDate = new Date();
    const statusSelect = document.getElementById('edit_status');
    const warning = document.getElementById('editStatusWarning');
    const submitBtn = document.getElementById('editSubmitBtn');
    
    if (endDate < currentDate) {
        // End date is in past
        warning.style.display = 'block';
        if (statusSelect.value === 'active') {
            statusSelect.value = 'inactive';
        }
        statusSelect.disabled = true;
    } else {
        warning.style.display = 'none';
        statusSelect.disabled = false;
    }
    
    // Enable/disable submit button based on validation
    if (endDate < startDate) {
        submitBtn.disabled = true;
        submitBtn.title = "End date cannot be earlier than start date";
    } else {
        submitBtn.disabled = false;
        submitBtn.title = "";
    }
}

// Auto-check date status on date change
document.addEventListener('DOMContentLoaded', function() {
    // Add form date validation
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    const editStartInput = document.getElementById('edit_start');
    const editEndInput = document.getElementById('edit_end');
    
    if (startDateInput) {
        startDateInput.addEventListener('change', function() {
            endDateInput.min = this.value;
            checkAddDateStatus();
        });
    }
    
    if (endDateInput) {
        endDateInput.addEventListener('change', checkAddDateStatus);
    }
    
    if (editStartInput) {
        editStartInput.addEventListener('change', function() {
            editEndInput.min = this.value;
            checkEditDateStatus();
        });
    }
    
    if (editEndInput) {
        editEndInput.addEventListener('change', checkEditDateStatus);
    }
    
    // Check initial state on modal open
    document.getElementById('add_status').addEventListener('change', checkAddDateStatus);
    document.getElementById('edit_status').addEventListener('change', checkEditDateStatus);
    
    // Set today as minimum date for start date
    const today = new Date().toISOString().split('T')[0];
    if (startDateInput) startDateInput.min = today;
    if (editStartInput) editStartInput.min = today;
    
    // Auto-hide alert
    const alert = document.getElementById('popup');
    if (alert && alert.classList.contains('show')) {
        setTimeout(() => {
            alert.classList.remove('show');
        }, 3500);
    }
    
    // Auto-submit filters on change
    document.getElementById('status').addEventListener('change', function() {
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
    
    // Debounced year filter
    let yearTimer;
    document.getElementById('year').addEventListener('input', function(e) {
        clearTimeout(yearTimer);
        yearTimer = setTimeout(() => {
            if (e.target.value.length === 0 || e.target.value.length > 2) {
                document.getElementById('filterForm').submit();
            }
        }, 600);
    });
    
    // Form validation
    const addForm = document.getElementById('addForm');
    if (addForm) {
        addForm.addEventListener('submit', function(e) {
            const startDate = new Date(document.getElementById('start_date').value);
            const endDate = new Date(document.getElementById('end_date').value);
            
            if (endDate < startDate) {
                e.preventDefault();
                alert('❌ End date cannot be earlier than start date!');
                return false;
            }
            
            // Auto-correct status if end date is in past
            const currentDate = new Date();
            const statusSelect = document.getElementById('add_status');
            if (endDate < currentDate && statusSelect.value === 'active') {
                statusSelect.value = 'inactive';
                alert('ℹ️ Status changed to inactive because end date is in the past.');
            }
        });
    }
    
    const editForm = document.getElementById('editForm');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            const startDate = new Date(document.getElementById('edit_start').value);
            const endDate = new Date(document.getElementById('edit_end').value);
            
            if (endDate < startDate) {
                e.preventDefault();
                alert('❌ End date cannot be earlier than start date!');
                return false;
            }
            
            // Auto-correct status if end date is in past
            const currentDate = new Date();
            const statusSelect = document.getElementById('edit_status');
            if (endDate < currentDate && statusSelect.value === 'active') {
                statusSelect.value = 'inactive';
                alert('ℹ️ Status changed to inactive because end date is in the past.');
            }
        });
    }
});

// Auto-refresh status every 5 minutes (optional)
setInterval(function() {
    // This would trigger a page refresh to update statuses
    // For a more advanced solution, use AJAX
    const alertPopup = document.getElementById('popup');
    if (!alertPopup || !alertPopup.classList.contains('show')) {
        // Only refresh if no alerts are showing
        window.location.reload();
    }
}, 300000); // 5 minutes
</script>

<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>