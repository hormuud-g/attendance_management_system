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
   AUTOMATIC STATUS UPDATE FOR TERMS
   =========================================== */
function updateExpiredTerms($pdo) {
    try {
        $current_date = date('Y-m-d');
        
        // Update expired terms to inactive
        $stmt = $pdo->prepare("
            UPDATE academic_term 
            SET status = 'inactive', updated_at = NOW()
            WHERE end_date < ? AND status = 'active'
        ");
        $stmt->execute([$current_date]);
        
        // Check if there's at least one active term in each academic year
        $years = $pdo->query("SELECT DISTINCT academic_year_id FROM academic_term")->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($years as $year_id) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM academic_term WHERE academic_year_id=? AND status='active'");
            $stmt->execute([$year_id]);
            $active_count = $stmt->fetchColumn();
            
            // If no active terms in this academic year, activate the most recent one that hasn't ended
            if ($active_count == 0) {
                $stmt = $pdo->prepare("
                    SELECT academic_term_id 
                    FROM academic_term 
                    WHERE academic_year_id = ? AND end_date >= ? 
                    ORDER BY start_date DESC 
                    LIMIT 1
                ");
                $stmt->execute([$year_id, $current_date]);
                $recent_term = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($recent_term) {
                    $pdo->prepare("UPDATE academic_term SET status='active', updated_at=NOW() WHERE academic_term_id=?")
                        ->execute([$recent_term['academic_term_id']]);
                }
            }
        }
        
    } catch (Exception $e) {
        error_log("Failed to update expired terms: " . $e->getMessage());
    }
}

// Run automatic status update
updateExpiredTerms($pdo);

/* ===========================================
   CRUD OPERATIONS
=========================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $action = $_POST['action'] ?? '';
    $academic_year_id = $_POST['academic_year_id'] ?? '';
    $term_name = $_POST['term_name'] ?? '';
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $status = $_POST['status'] ?? '';
    
    // Current date for validation
    $current_date = date('Y-m-d');

    /* ================================
       ADD NEW TERM
    ================================ */
    if ($action === 'add') {
      $pdo->beginTransaction();
      
      // ✅ Check if academic year exists
      $yearStmt = $pdo->prepare("SELECT start_date, end_date, year_name FROM academic_year WHERE academic_year_id=?");
      $yearStmt->execute([$academic_year_id]);
      $year_dates = $yearStmt->fetch(PDO::FETCH_ASSOC);

      if (!$year_dates) {
        throw new Exception("❌ Invalid academic year selected!");
      }

      // ✅ Term dates must be within academic year period
      if ($start_date < $year_dates['start_date'] || $end_date > $year_dates['end_date']) {
        throw new Exception(
          "❌ Term dates must be within the academic year period: " .
          htmlspecialchars($year_dates['start_date']) . " → " . 
          htmlspecialchars($year_dates['end_date']) . " (" . 
          htmlspecialchars($year_dates['year_name']) . ")."
        );
      }
      
      // ✅ End date validation
      if ($end_date < $start_date) {
        throw new Exception("❌ End date cannot be earlier than start date!");
      }
      
      // ✅ Check if term end date is in past (for active status)
      if ($status === 'active' && $end_date < $current_date) {
        throw new Exception("❌ Cannot set a term with past end date as active!");
      }

      // ✅ Prevent overlapping terms (within same academic year)
      $check = $pdo->prepare("
        SELECT term_name, start_date, end_date FROM academic_term 
        WHERE academic_year_id = ? 
          AND (
            (start_date <= ? AND end_date >= ?) OR
            (start_date <= ? AND end_date >= ?) OR
            (? <= start_date AND ? >= end_date)
          )
      ");
      $check->execute([
        $academic_year_id,
        $start_date, $start_date,
        $end_date, $end_date,
        $start_date, $end_date
      ]);
      
      if ($existing = $check->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception(
          "❌ The selected term dates overlap with existing term '" . 
          htmlspecialchars($existing['term_name']) . "' (" . 
          htmlspecialchars($existing['start_date']) . " → " . 
          htmlspecialchars($existing['end_date']) . ")!"
        );
      }

      // ✅ If term starts in future → auto set to inactive
      if ($status === 'active') {
        if ($start_date > $current_date) {
          $status = 'inactive';
        } else {
          // Deactivate other terms in same academic year
          $deactivate = $pdo->prepare("UPDATE academic_term SET status='inactive' WHERE academic_year_id=?");
          $deactivate->execute([$academic_year_id]);
        }
      }
      
      // Auto-correct status if end date is in past
      if ($end_date < $current_date && $status === 'active') {
          $status = 'inactive';
      }

      $stmt = $pdo->prepare("
        INSERT INTO academic_term (academic_year_id, term_name, start_date, end_date, status)
        VALUES (?, ?, ?, ?, ?)
      ");
      $stmt->execute([$academic_year_id, $term_name, $start_date, $end_date, $status]);
      
      $pdo->commit();
      $message = "✅ Academic Term added successfully!";
      $type = "success";
    }

    /* ================================
       UPDATE EXISTING TERM
    ================================ */
    if ($action === 'update') {
      $pdo->beginTransaction();
      
      $academic_term_id = $_POST['academic_term_id'];
      $academic_year_id = $_POST['academic_year_id'];
      $term_name = $_POST['term_name'];
      $start_date = $_POST['start_date'];
      $end_date = $_POST['end_date'];
      $status = $_POST['status'];

      // ✅ Check academic year validity
      $yearStmt = $pdo->prepare("SELECT start_date, end_date, year_name FROM academic_year WHERE academic_year_id=?");
      $yearStmt->execute([$academic_year_id]);
      $year_dates = $yearStmt->fetch(PDO::FETCH_ASSOC);

      if (!$year_dates) {
        throw new Exception("❌ Invalid academic year selected!");
      }

      // ✅ Term dates within academic year
      if ($start_date < $year_dates['start_date'] || $end_date > $year_dates['end_date']) {
        throw new Exception(
          "❌ Term dates must be within the academic year period: " .
          htmlspecialchars($year_dates['start_date']) . " → " . 
          htmlspecialchars($year_dates['end_date']) . " (" . 
          htmlspecialchars($year_dates['year_name']) . ")."
        );
      }
      
      // ✅ End date validation
      if ($end_date < $start_date) {
        throw new Exception("❌ End date cannot be earlier than start date!");
      }
      
      // ✅ Check if term end date is in past (for active status)
      if ($status === 'active' && $end_date < $current_date) {
        throw new Exception("❌ Cannot activate a term with past end date!");
      }

      // ✅ Prevent overlap with OTHER terms
      $check = $pdo->prepare("
        SELECT term_name, start_date, end_date FROM academic_term 
        WHERE academic_year_id = ? 
          AND academic_term_id != ?
          AND (
            (start_date <= ? AND end_date >= ?) OR
            (start_date <= ? AND end_date >= ?) OR
            (? <= start_date AND ? >= end_date)
          )
      ");
      $check->execute([
        $academic_year_id,
        $academic_term_id,
        $start_date, $start_date,
        $end_date, $end_date,
        $start_date, $end_date
      ]);
      
      if ($existing = $check->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception(
          "❌ The selected term dates overlap with existing term '" . 
          htmlspecialchars($existing['term_name']) . "' (" . 
          htmlspecialchars($existing['start_date']) . " → " . 
          htmlspecialchars($existing['end_date']) . ")!"
        );
      }

      // ✅ Future term cannot be active
      if ($status === 'active') {
        if ($start_date > $current_date) {
          $status = 'inactive';
        } else {
          // Deactivate other terms in same academic year except this one
          $deactivate = $pdo->prepare("
            UPDATE academic_term 
            SET status='inactive' 
            WHERE academic_year_id=? 
            AND academic_term_id != ?
          ");
          $deactivate->execute([$academic_year_id, $academic_term_id]);
        }
      }
      
      // Auto-correct status if end date is in past
      if ($end_date < $current_date && $status === 'active') {
          $status = 'inactive';
          $message = "ℹ️ Term updated. Status changed to inactive because end date is in the past.";
      }

      $stmt = $pdo->prepare("
        UPDATE academic_term 
        SET academic_year_id=?, term_name=?, start_date=?, end_date=?, status=?, updated_at=NOW()
        WHERE academic_term_id=?
      ");
      $stmt->execute([$academic_year_id, $term_name, $start_date, $end_date, $status, $academic_term_id]);
      
      $pdo->commit();
      $message = $message ?: "✅ Academic Term updated successfully!";
      $type = "success";
    }

    /* ================================
       DELETE TERM
    ================================ */
    if ($action === 'delete') {
      $pdo->beginTransaction();
      
      $stmt = $pdo->prepare("DELETE FROM academic_term WHERE academic_term_id=?");
      $stmt->execute([$_POST['academic_term_id']]);
      
      // After deletion, ensure at least one active term exists in the academic year if possible
      $stmt = $pdo->prepare("SELECT academic_year_id FROM academic_term WHERE academic_term_id=?");
      $stmt->execute([$_POST['academic_term_id']]);
      $year_id = $stmt->fetchColumn();
      
      if ($year_id) {
          $stmt = $pdo->prepare("SELECT COUNT(*) FROM academic_term WHERE academic_year_id=? AND status='active'");
          $stmt->execute([$year_id]);
          $active_count = $stmt->fetchColumn();
          
          if ($active_count == 0) {
              $current_date = date('Y-m-d');
              $stmt = $pdo->prepare("
                  SELECT academic_term_id 
                  FROM academic_term 
                  WHERE academic_year_id = ? AND end_date >= ? 
                  ORDER BY start_date DESC 
                  LIMIT 1
              ");
              $stmt->execute([$year_id, $current_date]);
              $recent_term = $stmt->fetch(PDO::FETCH_ASSOC);
              
              if ($recent_term) {
                  $pdo->prepare("UPDATE academic_term SET status='active', updated_at=NOW() WHERE academic_term_id=?")
                      ->execute([$recent_term['academic_term_id']]);
              }
          }
      }
      
      $pdo->commit();
      $message = "✅ Academic Term deleted successfully!";
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
$term_filter = $_GET['term'] ?? '';

// ✅ Build query with filters
$query = "
  SELECT 
    t.*,
    y.year_name,
    y.start_date as year_start,
    y.end_date as year_end,
    DATEDIFF(t.end_date, t.start_date) + 1 as duration_days
  FROM academic_term t
  JOIN academic_year y ON y.academic_year_id = t.academic_year_id
  WHERE 1=1
";
$params = [];

// Search filter
if (!empty($search)) {
  $query .= " AND (
    t.term_name LIKE ? OR 
    y.year_name LIKE ? OR 
    t.start_date LIKE ? OR 
    t.end_date LIKE ?
  )";
  $searchTerm = "%$search%";
  $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}

// Status filter
if (!empty($status_filter)) {
  $query .= " AND t.status = ?";
  $params[] = $status_filter;
}

// Academic year filter
if (!empty($year_filter) && is_numeric($year_filter)) {
  $query .= " AND t.academic_year_id = ?";
  $params[] = $year_filter;
}

// Term name filter
if (!empty($term_filter)) {
  $query .= " AND t.term_name LIKE ?";
  $params[] = "%$term_filter%";
}

$query .= " ORDER BY t.start_date DESC, t.academic_term_id DESC";

// ✅ Execute query
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$terms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ Get all academic years for filters
$years = $pdo->query("SELECT academic_year_id, year_name FROM academic_year ORDER BY year_name DESC")->fetchAll(PDO::FETCH_ASSOC);

// ✅ Get all academic years with dates for JavaScript
$all_years_data = $pdo->query("SELECT academic_year_id, year_name, start_date, end_date FROM academic_year ORDER BY year_name DESC")->fetchAll(PDO::FETCH_ASSOC);

// ✅ Get total counts for stats
$total_terms = $pdo->query("SELECT COUNT(*) FROM academic_term")->fetchColumn();
$active_terms = $pdo->query("SELECT COUNT(*) FROM academic_term WHERE status='active'")->fetchColumn();
$inactive_terms = $pdo->query("SELECT COUNT(*) FROM academic_term WHERE status='inactive'")->fetchColumn();

// ✅ Get expired terms count
$current_date = date('Y-m-d');
$stmt = $pdo->prepare("SELECT COUNT(*) FROM academic_term WHERE end_date < ?");
$stmt->execute([$current_date]);
$expired_terms = $stmt->fetchColumn();

// ✅ Get term names for filter dropdown
$term_names = $pdo->query("SELECT DISTINCT term_name FROM academic_term ORDER BY term_name")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Academic Terms Management | University System</title>
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
  --info-color: #17a2b8;
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

/* Duration & Year badges */
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

.year-badge {
  background: #e8f5e8;
  color: var(--primary-color);
  padding: 4px 10px;
  border-radius: 12px;
  font-size: 11px;
  font-weight: 500;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  border: 1px solid #c8e6c9;
}

/* Term name styling */
.term-name {
  font-weight: 600;
  font-size: 15px;
  color: var(--dark-color);
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
  max-width: 800px;
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

/* Academic Year Info Section */
.year-info-section {
  grid-column: 1 / -1;
  background: #f0f9ff;
  border-radius: 8px;
  padding: 20px;
  margin-top: 10px;
  border: 1px solid #cce5ff;
}

.year-info-section h4 {
  color: var(--secondary-color);
  margin-bottom: 15px;
  font-size: 16px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.year-dates {
  display: flex;
  gap: 30px;
  flex-wrap: wrap;
}

.year-date-item {
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.year-date-label {
  font-size: 12px;
  color: #666;
  font-weight: 500;
}

.year-date-value {
  font-size: 14px;
  font-weight: 600;
  color: var(--primary-color);
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

/* Term selection radio */
.term-radio-group {
  display: flex;
  gap: 15px;
  margin-top: 5px;
}

.term-radio-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 15px;
  border: 2px solid #ddd;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.2s ease;
  flex: 1;
}

.term-radio-item:hover {
  border-color: var(--secondary-color);
  background: #f8f9fa;
}

.term-radio-item input[type="radio"] {
  width: 18px;
  height: 18px;
  accent-color: var(--primary-color);
}

.term-radio-item label {
  margin: 0;
  font-weight: normal;
  font-size: 14px;
  cursor: pointer;
  flex: 1;
}

.term-radio-item.selected {
  border-color: var(--primary-color);
  background: #e8f5e8;
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
  
  .term-radio-group {
    flex-direction: column;
  }
  
  .stat-card {
    padding: 15px;
  }
  
  .action-btns {
    flex-wrap: wrap;
  }
  
  .year-dates {
    flex-direction: column;
    gap: 15px;
  }
}
</style>
</head>
<body>

<?php require_once('../includes/header.php'); ?>

<div class="main-content">
  <div class="page-header">
    <h1><i class="fas fa-calendar-week"></i> Academic Terms Management</h1>
    <button class="add-btn" onclick="openModal('addModal')">
      <i class="fa-solid fa-plus"></i> Add Term
    </button>
  </div>

  <!-- ✅ STATS CARDS -->
  <div class="stats-container">
    <div class="stat-card">
      <div class="stat-icon total">
        <i class="fas fa-calendar-week"></i>
      </div>
      <div class="stat-info">
        <h3>Total Terms</h3>
        <div class="number"><?= $total_terms ?></div>
      </div>
    </div>
    
    <div class="stat-card">
      <div class="stat-icon active">
        <i class="fas fa-check-circle"></i>
      </div>
      <div class="stat-info">
        <h3>Active Terms</h3>
        <div class="number"><?= $active_terms ?></div>
      </div>
    </div>
    
    <div class="stat-card">
      <div class="stat-icon inactive">
        <i class="fas fa-times-circle"></i>
      </div>
      <div class="stat-info">
        <h3>Inactive Terms</h3>
        <div class="number"><?= $inactive_terms ?></div>
      </div>
    </div>
    
    <div class="stat-card">
      <div class="stat-icon expired">
        <i class="fas fa-hourglass-end"></i>
      </div>
      <div class="stat-info">
        <h3>Expired Terms</h3>
        <div class="number"><?= $expired_terms ?></div>
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
                 placeholder="Term name, dates, or academic year..."
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
        <label for="year">Academic Year</label>
        <div style="position:relative;">
          <i class="fas fa-calendar-alt filter-icon"></i>
          <select id="year" name="year" class="filter-input">
            <option value="">All Years</option>
            <?php foreach($years as $y): ?>
              <option value="<?= $y['academic_year_id'] ?>" 
                <?= $year_filter == $y['academic_year_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($y['year_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      
      <div class="filter-group">
        <label for="term">Term Name</label>
        <div style="position:relative;">
          <i class="fas fa-tag filter-icon"></i>
          <select id="term" name="term" class="filter-input">
            <option value="">All Terms</option>
            <?php foreach($term_names as $term_name): ?>
              <option value="<?= htmlspecialchars($term_name) ?>" 
                <?= $term_filter == $term_name ? 'selected' : '' ?>>
                <?= htmlspecialchars($term_name) ?>
              </option>
            <?php endforeach; ?>
          </select>
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
      <h3>Academic Terms List</h3>
      <div class="results-count">
        Showing <?= count($terms) ?> of <?= $total_terms ?> terms
        <?php if(!empty($search) || !empty($status_filter) || !empty($year_filter) || !empty($term_filter)): ?>
          (filtered)
        <?php endif; ?>
        <?php if($expired_terms > 0): ?>
          <span style="color: var(--expired-color); margin-left: 10px;">
            <i class="fas fa-exclamation-triangle"></i> <?= $expired_terms ?> expired
          </span>
        <?php endif; ?>
      </div>
    </div>
    
    <div class="table-container">
      <table class="data-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Academic Year</th>
            <th>Term</th>
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
          <?php if($terms): ?>
            <?php foreach($terms as $i=>$t): 
              $duration_text = ($t['duration_days'] ?? 0) . ' day' . (($t['duration_days'] ?? 0) != 1 ? 's' : '');
              
              // Check if term is expired
              $current_date = new DateTime();
              $end_date = new DateTime($t['end_date']);
              $is_expired = $end_date < $current_date;
              $status_class = $t['status'];
              if ($is_expired && $t['status'] === 'active') {
                  $status_class = 'expired';
              }
            ?>
            <tr class="<?= $is_expired ? 'expired-indicator' : '' ?>">
              <td><?= $i+1 ?></td>
              <td>
                <span class="year-badge">
                  <i class="fas fa-calendar-alt"></i> <?= htmlspecialchars($t['year_name']) ?>
                </span>
              </td>
              <td>
                <div class="term-name"><?= htmlspecialchars($t['term_name']) ?></div>
                <?php if($is_expired): ?>
                  <span style="color: var(--expired-color); font-size: 11px; margin-left: 5px;">
                    <i class="fas fa-clock"></i> Ended
                  </span>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($t['start_date']) ?></td>
              <td>
                <?= htmlspecialchars($t['end_date']) ?>
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
                  <?php if($is_expired && $t['status'] === 'active'): ?>
                    <i class="fas fa-exclamation-triangle" style="margin-left: 5px;"></i>
                  <?php endif; ?>
                </span>
              </td>
              <td><?= htmlspecialchars($t['created_at']) ?></td>
              <td><?= htmlspecialchars($t['updated_at']) ?></td>
              <td>
                <div class="action-btns">
                  <button class="action-btn view-btn" 
                          onclick="openViewModal(
                            '<?= addslashes($t['term_name']) ?>',
                            '<?= addslashes($t['year_name']) ?>',
                            '<?= $t['year_start'] ?>',
                            '<?= $t['year_end'] ?>',
                            '<?= $t['start_date'] ?>',
                            '<?= $t['end_date'] ?>',
                            '<?= $t['duration_days'] ?? 0 ?>',
                            '<?= $t['status'] ?>',
                            '<?= $t['created_at'] ?>',
                            '<?= $t['updated_at'] ?>',
                            '<?= $is_expired ? 'true' : 'false' ?>'
                          )"
                          title="View Details">
                    <i class="fa-solid fa-eye"></i>
                  </button>
                  <button class="action-btn edit-btn" 
                          onclick="openEditModal(
                            <?= $t['academic_term_id'] ?>,
                            <?= $t['academic_year_id'] ?>,
                            '<?= addslashes($t['term_name']) ?>',
                            '<?= $t['start_date'] ?>',
                            '<?= $t['end_date'] ?>',
                            '<?= $t['status'] ?>'
                          )"
                          title="Edit">
                    <i class="fa-solid fa-pen"></i>
                  </button>
                  <button class="action-btn del-btn" 
                          onclick="openDeleteModal(<?= $t['academic_term_id'] ?>)" 
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
                  <i class="fa-solid fa-inbox"></i>
                  <h3>No academic terms found</h3>
                  <p>
                    <?php if(!empty($search) || !empty($status_filter) || !empty($year_filter) || !empty($term_filter)): ?>
                      Try adjusting your filters or <a href="javascript:void(0)" onclick="clearFilters()">clear all filters</a>
                    <?php else: ?>
                      Add your first academic term using the button above
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
    <h2><i class="fas fa-eye"></i> Term Details</h2>
    
    <div class="view-content">
      <div id="expiredWarning" class="status-warning" style="display: none;">
        <i class="fas fa-exclamation-triangle"></i>
        <span>This term has ended and is automatically marked as inactive.</span>
      </div>
      
      <div class="view-details">
        <div class="detail-item">
          <div class="detail-label"><i class="fas fa-tag"></i> Term Name</div>
          <div class="detail-value" id="view_name"></div>
        </div>
        
        <div class="detail-item">
          <div class="detail-label"><i class="fas fa-calendar-alt"></i> Academic Year</div>
          <div class="detail-value" id="view_year"></div>
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
      
      <!-- Academic Year Info -->
      <div class="year-info-section">
        <h4><i class="fas fa-info-circle"></i> Academic Year Information</h4>
        <div class="year-dates">
          <div class="year-date-item">
            <div class="year-date-label">Year Start Date</div>
            <div class="year-date-value" id="view_year_start"></div>
          </div>
          <div class="year-date-item">
            <div class="year-date-label">Year End Date</div>
            <div class="year-date-value" id="view_year_end"></div>
          </div>
          <div class="year-date-item">
            <div class="year-date-label">Total Year Duration</div>
            <div class="year-date-value" id="view_year_duration"></div>
          </div>
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
    <h2><i class="fas fa-plus-circle"></i> Add New Term</h2>
    <form method="POST" id="addForm" onsubmit="return validateTermForm('addForm')">
      <input type="hidden" name="action" value="add">
      
      <div class="form-grid">
        <div class="form-group">
          <label for="academic_year_id">Academic Year *</label>
          <select id="academic_year_id" name="academic_year_id" class="form-control" required
                  onchange="updateYearDates(this.value, 'add')">
            <option value="">Select Academic Year</option>
            <?php foreach($years as $y): ?>
              <option value="<?= $y['academic_year_id'] ?>"><?= htmlspecialchars($y['year_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <small id="year_dates_info_add" style="color:#666; margin-top:5px; display:none;">
            Loading year dates...
          </small>
        </div>
        
        <div class="form-group">
          <label>Term Name *</label>
          <div class="term-radio-group" id="term_name_group_add">
            <div class="term-radio-item" onclick="selectTermRadio('add', 'A')">
              <input type="radio" id="term_A_add" name="term_name" value="A" required>
              <label for="term_A_add">Term A</label>
            </div>
            <div class="term-radio-item" onclick="selectTermRadio('add', 'B')">
              <input type="radio" id="term_B_add" name="term_name" value="B" required>
              <label for="term_B_add">Term B</label>
            </div>
          </div>
        </div>
        
        <div class="form-group">
          <label for="start_date">Start Date *</label>
          <input type="date" id="start_date" name="start_date" class="form-control" required>
        </div>
        
        <div class="form-group">
          <label for="end_date">End Date *</label>
          <input type="date" id="end_date" name="end_date" class="form-control" required>
        </div>
        
        <div class="form-group">
          <label for="add_status">Status *</label>
          <select id="add_status" name="status" class="form-control" required>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
          <small style="color:#666; margin-top:5px;">
            Note: Future terms will be automatically set to inactive
          </small>
        </div>
      </div>
      
      <div class="date-validation" style="grid-column: 1 / -1; margin: 15px 0; padding: 15px; background: #f8f9fa; border-radius: 6px; display: none;" id="date_validation">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
          <i class="fas fa-info-circle" style="color: var(--secondary-color);"></i>
          <strong style="color: var(--secondary-color);">Academic Year Dates:</strong>
        </div>
        <div style="display: flex; gap: 20px; flex-wrap: wrap;">
          <div><strong>Start:</strong> <span id="year_start_date"></span></div>
          <div><strong>End:</strong> <span id="year_end_date"></span></div>
          <div style="color: var(--primary-color); font-weight: 600;" id="date_warning"></div>
        </div>
      </div>
      
      <div id="addStatusWarning" class="status-warning" style="display: none;">
        <i class="fas fa-exclamation-triangle"></i>
        <span>End date is in the past. This term will be automatically set as inactive.</span>
      </div>
      
      <button class="submit-btn" type="submit" id="addSubmitBtn">
        <i class="fas fa-save"></i> Save Term
      </button>
    </form>
  </div>
</div>

<!-- EDIT MODAL -->
<div class="modal" id="editModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('editModal')">&times;</span>
    <h2><i class="fas fa-edit"></i> Edit Term</h2>
    <form method="POST" id="editForm" onsubmit="return validateTermForm('editForm')">
      <input type="hidden" name="action" value="update">
      <input type="hidden" id="edit_id" name="academic_term_id">
      
      <div class="form-grid">
        <div class="form-group">
          <label for="edit_academic_year_id">Academic Year *</label>
          <select id="edit_academic_year_id" name="academic_year_id" class="form-control" required
                  onchange="updateYearDates(this.value, 'edit')">
            <option value="">Select Academic Year</option>
            <?php foreach($years as $y): ?>
              <option value="<?= $y['academic_year_id'] ?>"><?= htmlspecialchars($y['year_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <small id="year_dates_info_edit" style="color:#666; margin-top:5px; display:none;">
            Loading year dates...
          </small>
        </div>
        
        <div class="form-group">
          <label>Term Name *</label>
          <div class="term-radio-group" id="term_name_group_edit">
            <div class="term-radio-item" onclick="selectTermRadio('edit', 'A')">
              <input type="radio" id="term_A_edit" name="term_name" value="A" required>
              <label for="term_A_edit">Term A</label>
            </div>
            <div class="term-radio-item" onclick="selectTermRadio('edit', 'B')">
              <input type="radio" id="term_B_edit" name="term_name" value="B" required>
              <label for="term_B_edit">Term B</label>
            </div>
          </div>
        </div>
        
        <div class="form-group">
          <label for="edit_start_date">Start Date *</label>
          <input type="date" id="edit_start_date" name="start_date" class="form-control" required>
        </div>
        
        <div class="form-group">
          <label for="edit_end_date">End Date *</label>
          <input type="date" id="edit_end_date" name="end_date" class="form-control" required>
        </div>
        
        <div class="form-group">
          <label for="edit_status">Status *</label>
          <select id="edit_status" name="status" class="form-control" required>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
          <small style="color:#666; margin-top:5px;">
            Note: Future terms will be automatically set to inactive
          </small>
        </div>
      </div>
      
      <div class="date-validation" style="grid-column: 1 / -1; margin: 15px 0; padding: 15px; background: #f8f9fa; border-radius: 6px; display: none;" id="edit_date_validation">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
          <i class="fas fa-info-circle" style="color: var(--secondary-color);"></i>
          <strong style="color: var(--secondary-color);">Academic Year Dates:</strong>
        </div>
        <div style="display: flex; gap: 20px; flex-wrap: wrap;">
          <div><strong>Start:</strong> <span id="edit_year_start_date"></span></div>
          <div><strong>End:</strong> <span id="edit_year_end_date"></span></div>
          <div style="color: var(--primary-color); font-weight: 600;" id="edit_date_warning"></div>
        </div>
      </div>
      
      <div id="editStatusWarning" class="status-warning" style="display: none;">
        <i class="fas fa-exclamation-triangle"></i>
        <span>End date is in the past. This term will be automatically set as inactive.</span>
      </div>
      
      <button class="submit-btn" type="submit" id="editSubmitBtn">
        <i class="fas fa-sync-alt"></i> Update Term
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
      <input type="hidden" name="academic_term_id" id="delete_id">
      
      <div style="text-align: center; margin: 30px 0;">
        <i class="fa-solid fa-exclamation-triangle" style="font-size: 48px; color: var(--danger-color); margin-bottom: 20px;"></i>
        <p style="font-size: 16px; color: var(--dark-color); margin-bottom: 10px;">
          Are you sure you want to delete this academic term?
        </p>
        <p style="color: #666; font-size: 14px;">
          This action cannot be undone and may affect related data.
        </p>
      </div>
      
      <button class="submit-btn delete-btn" type="submit">
        <i class="fas fa-trash-alt"></i> Yes, Delete Term
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
// Global variable with year data
const YEARS_DATA = <?php echo json_encode($all_years_data); ?>;

// Initialize Select2
$(document).ready(function() {
  $('#status').select2({
    minimumResultsForSearch: -1,
    width: '100%'
  });
  
  $('#year').select2({
    placeholder: "Select academic year",
    allowClear: true,
    width: '100%'
  });
  
  $('#term').select2({
    placeholder: "Select term name",
    allowClear: true,
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
function openViewModal(name, year, yearStart, yearEnd, start, end, duration, status, created, updated, isExpired) {
  openModal('viewModal');
  
  // Calculate durations
  const termDuration = duration + ' day' + (duration != 1 ? 's' : '');
  
  // Calculate academic year duration
  const yearStartDate = new Date(yearStart);
  const yearEndDate = new Date(yearEnd);
  const yearDuration = Math.floor((yearEndDate - yearStartDate) / (1000 * 60 * 60 * 24)) + 1;
  const yearDurationText = yearDuration + ' day' + (yearDuration != 1 ? 's' : '');
  
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
  
  // Set all details
  document.getElementById('view_name').textContent = name || 'Not provided';
  document.getElementById('view_year').textContent = year || 'Not provided';
  document.getElementById('view_start').textContent = start || 'Not provided';
  document.getElementById('view_end').textContent = end || 'Not provided';
  document.getElementById('view_duration').textContent = termDuration || 'Not provided';
  document.getElementById('view_created').textContent = created || 'Not provided';
  document.getElementById('view_updated').textContent = updated || 'Not provided';
  document.getElementById('view_year_start').textContent = yearStart || 'Not provided';
  document.getElementById('view_year_end').textContent = yearEnd || 'Not provided';
  document.getElementById('view_year_duration').textContent = yearDurationText || 'Not provided';
  
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
function openEditModal(id, yearId, name, start, end, status) {
  openModal('editModal');
  
  // Set form values
  document.getElementById('edit_id').value = id;
  document.getElementById('edit_academic_year_id').value = yearId;
  document.getElementById('edit_start_date').value = start;
  document.getElementById('edit_end_date').value = end;
  document.getElementById('edit_status').value = status;
  
  // Select term name radio
  selectTermRadio('edit', name);
  
  // Load year dates
  updateYearDates(yearId, 'edit');
  
  // Check date status
  checkEditDateStatus();
}

// Open delete modal
function openDeleteModal(id) {
  openModal('deleteModal');
  document.getElementById('delete_id').value = id;
}

// Select term radio button
function selectTermRadio(formType, termName) {
  const radioItems = document.querySelectorAll(`#term_name_group_${formType} .term-radio-item`);
  radioItems.forEach(item => {
    item.classList.remove('selected');
    const radio = item.querySelector('input[type="radio"]');
    if (radio.value === termName) {
      radio.checked = true;
      item.classList.add('selected');
    }
  });
}

// Update year dates info using local data
function updateYearDates(yearId, formType) {
  if (!yearId) {
    const validationDiv = formType === 'add' ? 
      document.getElementById('date_validation') : 
      document.getElementById('edit_date_validation');
    if (validationDiv) validationDiv.style.display = 'none';
    return;
  }
  
  const infoSpan = document.getElementById(`year_dates_info_${formType}`);
  
  // Find the year in our local data
  const yearData = YEARS_DATA.find(year => year.academic_year_id == yearId);
  
  if (yearData) {
    if (infoSpan) {
      infoSpan.innerHTML = `<i class="fas fa-calendar-alt"></i> ${yearData.year_name}: ${yearData.start_date} → ${yearData.end_date}`;
      infoSpan.style.display = 'block';
    }
    
    // Show date validation info
    const validationDiv = formType === 'add' ? 
      document.getElementById('date_validation') : 
      document.getElementById('edit_date_validation');
    const yearStartSpan = formType === 'add' ? 
      document.getElementById('year_start_date') : 
      document.getElementById('edit_year_start_date');
    const yearEndSpan = formType === 'add' ? 
      document.getElementById('year_end_date') : 
      document.getElementById('edit_year_end_date');
    const warningSpan = formType === 'add' ? 
      document.getElementById('date_warning') : 
      document.getElementById('edit_date_warning');
    
    if (yearStartSpan) yearStartSpan.textContent = yearData.start_date;
    if (yearEndSpan) yearEndSpan.textContent = yearData.end_date;
    if (warningSpan) warningSpan.textContent = 'Term dates must be within this range';
    if (validationDiv) validationDiv.style.display = 'block';
    
    // Set min/max dates for date inputs
    const startInput = formType === 'add' ? 
      document.getElementById('start_date') : 
      document.getElementById('edit_start_date');
    const endInput = formType === 'add' ? 
      document.getElementById('end_date') : 
      document.getElementById('edit_end_date');
    
    if (startInput) {
      startInput.min = yearData.start_date;
      startInput.max = yearData.end_date;
    }
    if (endInput) {
      endInput.min = yearData.start_date;
      endInput.max = yearData.end_date;
    }
    
    // Check date status after loading year dates
    if (formType === 'add') {
      checkAddDateStatus();
    } else {
      checkEditDateStatus();
    }
    
  } else {
    if (infoSpan) {
      infoSpan.textContent = 'Year data not found';
      infoSpan.style.color = 'var(--danger-color)';
    }
  }
}

// Date validation and status check functions
function checkAddDateStatus() {
    const startDate = new Date(document.getElementById('start_date').value);
    const endDate = new Date(document.getElementById('end_date').value);
    const currentDate = new Date();
    const statusSelect = document.getElementById('add_status');
    const warning = document.getElementById('addStatusWarning');
    const submitBtn = document.getElementById('addSubmitBtn');
    
    if (endDate && endDate < currentDate) {
        // End date is in past
        if (warning) warning.style.display = 'block';
        if (statusSelect && statusSelect.value === 'active') {
            statusSelect.value = 'inactive';
        }
        if (statusSelect) statusSelect.disabled = true;
    } else {
        if (warning) warning.style.display = 'none';
        if (statusSelect) statusSelect.disabled = false;
    }
    
    // Enable/disable submit button based on validation
    if (submitBtn) {
      if (endDate && startDate && endDate < startDate) {
          submitBtn.disabled = true;
          submitBtn.title = "End date cannot be earlier than start date";
      } else {
          submitBtn.disabled = false;
          submitBtn.title = "";
      }
    }
}

function checkEditDateStatus() {
    const startDate = new Date(document.getElementById('edit_start_date').value);
    const endDate = new Date(document.getElementById('edit_end_date').value);
    const currentDate = new Date();
    const statusSelect = document.getElementById('edit_status');
    const warning = document.getElementById('editStatusWarning');
    const submitBtn = document.getElementById('editSubmitBtn');
    
    if (endDate && endDate < currentDate) {
        // End date is in past
        if (warning) warning.style.display = 'block';
        if (statusSelect && statusSelect.value === 'active') {
            statusSelect.value = 'inactive';
        }
        if (statusSelect) statusSelect.disabled = true;
    } else {
        if (warning) warning.style.display = 'none';
        if (statusSelect) statusSelect.disabled = false;
    }
    
    // Enable/disable submit button based on validation
    if (submitBtn) {
      if (endDate && startDate && endDate < startDate) {
          submitBtn.disabled = true;
          submitBtn.title = "End date cannot be earlier than start date";
      } else {
          submitBtn.disabled = false;
          submitBtn.title = "";
      }
    }
}

// Validate term form
function validateTermForm(formId) {
  const form = document.getElementById(formId);
  
  // Check if academic year is selected
  const yearSelect = form.querySelector('select[name="academic_year_id"]');
  if (!yearSelect.value) {
    alert('Please select an academic year!');
    return false;
  }
  
  // Check if term name is selected
  const termName = form.querySelector('input[name="term_name"]:checked');
  if (!termName) {
    alert('Please select a term name!');
    return false;
  }
  
  // Check dates
  const startDate = form.querySelector('input[name="start_date"]').value;
  const endDate = form.querySelector('input[name="end_date"]').value;
  
  if (!startDate || !endDate) {
    alert('Please select both start and end dates!');
    return false;
  }
  
  if (endDate < startDate) {
    alert('❌ End date cannot be earlier than start date!');
    return false;
  }
  
  return true;
}

// Auto-check date status on date change
document.addEventListener('DOMContentLoaded', function() {
    // Add form date validation
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    const editStartInput = document.getElementById('edit_start_date');
    const editEndInput = document.getElementById('edit_end_date');
    
    if (startDateInput) {
        startDateInput.addEventListener('change', function() {
            if (endDateInput) endDateInput.min = this.value;
            checkAddDateStatus();
        });
    }
    
    if (endDateInput) {
        endDateInput.addEventListener('change', checkAddDateStatus);
    }
    
    if (editStartInput) {
        editStartInput.addEventListener('change', function() {
            if (editEndInput) editEndInput.min = this.value;
            checkEditDateStatus();
        });
    }
    
    if (editEndInput) {
        editEndInput.addEventListener('change', checkEditDateStatus);
    }
    
    // Check initial state on modal open
    const addStatus = document.getElementById('add_status');
    if (addStatus) {
      addStatus.addEventListener('change', checkAddDateStatus);
    }
    
    const editStatus = document.getElementById('edit_status');
    if (editStatus) {
      editStatus.addEventListener('change', checkEditDateStatus);
    }
    
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
    const statusFilter = document.getElementById('status');
    if (statusFilter) {
      statusFilter.addEventListener('change', function() {
          document.getElementById('filterForm').submit();
      });
    }
    
    $('#year').on('change', function() {
        document.getElementById('filterForm').submit();
    });
    
    $('#term').on('change', function() {
        document.getElementById('filterForm').submit();
    });
    
    // Debounced search
    let searchTimer;
    const searchInput = document.getElementById('search');
    if (searchInput) {
      searchInput.addEventListener('input', function(e) {
          clearTimeout(searchTimer);
          searchTimer = setTimeout(() => {
              if (e.target.value.length === 0 || e.target.value.length > 2) {
                  document.getElementById('filterForm').submit();
              }
          }, 600);
      });
    }
    
    // Set default term name selection
    selectTermRadio('add', 'A');
    
    // Date validation for forms
    const addForm = document.getElementById('addForm');
    if (addForm) {
        addForm.addEventListener('submit', function(e) {
            if (!validateTermForm('addForm')) {
                e.preventDefault();
                return false;
            }
        });
    }
    
    const editForm = document.getElementById('editForm');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            if (!validateTermForm('editForm')) {
                e.preventDefault();
                return false;
            }
        });
    }
    
    // Auto-refresh status every 5 minutes (optional)
    setInterval(function() {
        const alertPopup = document.getElementById('popup');
        if (!alertPopup || !alertPopup.classList.contains('show')) {
            // Only refresh if no alerts are showing
            window.location.reload();
        }
    }, 300000); // 5 minutes
});
</script>

<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>