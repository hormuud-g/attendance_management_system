<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Access Control - Only Faculty Admin
if (strtolower($_SESSION['user']['role'] ?? '') !== 'faculty_admin') {
    header("Location: ../unauthorized.php");
    exit;
}

// ✅ Get current faculty ID from user session
$current_faculty_id = $_SESSION['user']['linked_id'] ?? null;

// ✅ Get faculty details for display
$faculty_name = '';
$faculty_code = '';

if ($current_faculty_id) {
    $stmt = $pdo->prepare("SELECT faculty_name, faculty_code FROM faculties WHERE faculty_id = ?");
    $stmt->execute([$current_faculty_id]);
    $faculty_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $faculty_name = $faculty_data['faculty_name'] ?? 'Unknown Faculty';
    $faculty_code = $faculty_data['faculty_code'] ?? '';
}

$message = "";
$type = "";

/* ===========================================
   CRUD OPERATIONS - Faculty Admin
=========================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // 🟢 ADD SEMESTER
        if ($_POST['action'] === 'add') {
            $semester_name = trim($_POST['semester_name']);
            $description = trim($_POST['description'] ?? '');
            $status = $_POST['status'];

            // Validate
            if (empty($semester_name)) {
                throw new Exception("Semester name is required!");
            }

            // Check if semester name already exists
            $check = $pdo->prepare("SELECT COUNT(*) FROM semester WHERE semester_name = ?");
            $check->execute([$semester_name]);
            if ($check->fetchColumn() > 0) {
                throw new Exception("Semester name already exists!");
            }

            $stmt = $pdo->prepare("
                INSERT INTO semester (semester_name, description, status, created_at, updated_at) 
                VALUES (?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([$semester_name, $description, $status]);

            $message = "✅ Semester added successfully!";
            $type = "success";
        }

        // 🟡 UPDATE SEMESTER
        if ($_POST['action'] === 'update') {
            $semester_id = (int)$_POST['semester_id'];
            $semester_name = trim($_POST['semester_name']);
            $description = trim($_POST['description'] ?? '');
            $status = $_POST['status'];

            // Validate
            if (empty($semester_name)) {
                throw new Exception("Semester name is required!");
            }

            // Check if semester exists
            $check = $pdo->prepare("SELECT COUNT(*) FROM semester WHERE semester_id = ?");
            $check->execute([$semester_id]);
            if ($check->fetchColumn() == 0) {
                throw new Exception("Semester not found!");
            }

            // Check if semester name already exists (excluding current)
            $checkName = $pdo->prepare("SELECT COUNT(*) FROM semester WHERE semester_name = ? AND semester_id != ?");
            $checkName->execute([$semester_name, $semester_id]);
            if ($checkName->fetchColumn() > 0) {
                throw new Exception("Semester name already exists!");
            }

            $stmt = $pdo->prepare("
                UPDATE semester 
                SET semester_name = ?, description = ?, status = ?, updated_at = NOW() 
                WHERE semester_id = ?
            ");
            $stmt->execute([$semester_name, $description, $status, $semester_id]);

            $message = "✅ Semester updated successfully!";
            $type = "success";
        }

        // 🔴 DELETE SEMESTER
        if ($_POST['action'] === 'delete') {
            $semester_id = (int)$_POST['semester_id'];

            // Check if semester exists
            $check = $pdo->prepare("SELECT COUNT(*) FROM semester WHERE semester_id = ?");
            $check->execute([$semester_id]);
            if ($check->fetchColumn() == 0) {
                throw new Exception("Semester not found!");
            }

            // Check if semester is used in academic_term
            $checkUsage = $pdo->prepare("SELECT COUNT(*) FROM academic_term WHERE semester_id = ?");
            $checkUsage->execute([$semester_id]);
            if ($checkUsage->fetchColumn() > 0) {
                throw new Exception("Cannot delete semester because it is used in academic terms!");
            }

            $stmt = $pdo->prepare("DELETE FROM semester WHERE semester_id = ?");
            $stmt->execute([$semester_id]);

            $message = "✅ Semester deleted successfully!";
            $type = "success";
        }

    } catch (PDOException $e) {
        $message = "❌ Database Error: " . $e->getMessage();
        $type = "error";
    } catch (Exception $e) {
        $message = "❌ " . $e->getMessage();
        $type = "error";
    }
}

/* ===========================================
   FETCH ALL SEMESTERS
=========================================== */
try {
    $stmt = $pdo->query("
        SELECT * FROM semester 
        ORDER BY semester_id DESC
    ");
    $semesters = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = "❌ " . $e->getMessage();
    $type = "error";
    $semesters = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Semesters | Faculty Admin - <?= htmlspecialchars($faculty_name) ?> | Hormuud University</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="../images.png">
<link rel="stylesheet" href="../assets/css/header.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
  --green: #00843D;
  --light-green: #00A651;
  --blue: #0072CE;
  --red: #C62828;
  --bg: #F5F9F7;
  --dark: #2C3E50;
  --light-gray: #f8fafc;
  --white: #ffffff;
  --gold: #FFB81C;
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
  margin-bottom: 25px;
  padding: 20px 25px;
  background: var(--white);
  border-radius: 12px;
  box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
  border-left: 6px solid var(--green);
}

.page-header h1 {
  color: var(--blue);
  font-size: 24px;
  font-weight: 700;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.page-header h1 i {
  color: var(--white);
  background: var(--green);
  padding: 12px;
  border-radius: 10px;
  box-shadow: 0 4px 10px rgba(0, 168, 89, 0.2);
}

.faculty-badge {
  font-size: 14px;
  font-weight: 400;
  background: rgba(0, 114, 206, 0.1);
  color: var(--blue);
  padding: 5px 15px;
  border-radius: 20px;
  margin-left: 15px;
}

.faculty-badge i {
  margin-right: 5px;
  color: var(--blue);
}

/* Add Button */
.add-btn {
  background: linear-gradient(135deg, var(--green), var(--light-green));
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
  box-shadow: 0 4px 12px rgba(0, 168, 89, 0.3);
  font-size: 14px;
}

.add-btn:hover {
  transform: translateY(-3px);
  box-shadow: 0 6px 20px rgba(0, 168, 89, 0.4);
}

/* Table Styles */
.table-wrapper {
  background: var(--white);
  border-radius: 12px;
  box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
  border: 1px solid #eee;
  overflow-x: auto;
  overflow-y: auto;
  max-height: 600px;
}

.table-header {
  padding: 20px 25px;
  border-bottom: 1px solid #eee;
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: linear-gradient(to right, #f9f9f9, var(--white));
}

.table-header h3 {
  color: var(--dark);
  font-size: 16px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 8px;
}

.table-header h3 i {
  color: var(--green);
}

.results-count {
  color: #666;
  font-size: 14px;
  background: #f0f0f0;
  padding: 5px 12px;
  border-radius: 20px;
}

table {
  width: 100%;
  border-collapse: collapse;
}

thead th {
  background: linear-gradient(135deg, var(--blue), var(--green));
  color: var(--white);
  position: sticky;
  top: 0;
  z-index: 2;
  padding: 16px 20px;
  text-align: left;
  font-weight: 600;
  font-size: 14px;
}

th, td {
  padding: 14px 20px;
  border-bottom: 1px solid #eee;
  white-space: nowrap;
  text-align: left;
}

tbody tr:hover {
  background: #eef8f0;
}

tbody tr:nth-child(even) {
  background: #fafafa;
}

/* Status Badges */
.status-active {
  color: var(--green);
  font-weight: 600;
  background: rgba(0, 132, 61, 0.1);
  padding: 4px 12px;
  border-radius: 20px;
  display: inline-block;
}

.status-inactive {
  color: var(--red);
  font-weight: 600;
  background: rgba(198, 40, 40, 0.1);
  padding: 4px 12px;
  border-radius: 20px;
  display: inline-block;
}

/* Semester Badge */
.semester-badge {
  background: rgba(0, 114, 206, 0.1);
  color: var(--blue);
  padding: 4px 10px;
  border-radius: 6px;
  font-weight: 600;
  font-size: 13px;
}

.semester-badge i {
  margin-right: 5px;
  color: var(--green);
}

/* Action Buttons */
.action-buttons {
  display: flex;
  gap: 8px;
  justify-content: flex-start;
}

.btn-edit, .btn-delete {
  border: none;
  border-radius: 6px;
  padding: 8px 12px;
  color: #fff;
  cursor: pointer;
  transition: all 0.3s ease;
  font-size: 13px;
  display: inline-flex;
  align-items: center;
  gap: 5px;
}

.btn-edit {
  background: var(--blue);
}

.btn-edit:hover {
  background: #005fa3;
  transform: translateY(-2px);
}

.btn-delete {
  background: var(--red);
}

.btn-delete:hover {
  background: #b71c1c;
  transform: translateY(-2px);
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
  max-width: 550px;
  max-height: 90vh;
  overflow-y: auto;
  padding: 35px;
  position: relative;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
  border-top: 6px solid var(--green);
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
  color: var(--red);
  transform: rotate(90deg);
}

.modal-content h2 {
  color: var(--blue);
  margin-bottom: 25px;
  font-size: 22px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 12px;
  padding-bottom: 15px;
  border-bottom: 2px solid #f0f0f0;
}

.modal-content h2 i {
  color: var(--white);
  background: var(--green);
  padding: 10px;
  border-radius: 10px;
}

/* Form Styles */
form {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.form-group {
  margin-bottom: 5px;
}

.form-group label {
  display: block;
  margin-bottom: 8px;
  font-weight: 600;
  color: var(--blue);
  font-size: 14px;
}

.form-group label i {
  margin-right: 8px;
  color: var(--green);
}

.form-control {
  width: 100%;
  padding: 12px 15px;
  border: 2px solid #e0e0e0;
  border-radius: 8px;
  font-size: 14px;
  transition: all 0.3s ease;
  background: #fafafa;
  font-family: 'Poppins', sans-serif;
}

.form-control:focus {
  outline: none;
  border-color: var(--green);
  background: var(--white);
  box-shadow: 0 0 0 3px rgba(0, 168, 89, 0.1);
}

textarea.form-control {
  resize: vertical;
  min-height: 80px;
}

select.form-control {
  cursor: pointer;
}

/* Submit Button */
.submit-btn {
  background: linear-gradient(135deg, var(--green), var(--light-green));
  color: var(--white);
  border: none;
  padding: 14px;
  border-radius: 8px;
  font-weight: 600;
  font-size: 16px;
  cursor: pointer;
  transition: all 0.3s ease;
  margin-top: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  box-shadow: 0 4px 12px rgba(0, 168, 89, 0.3);
}

.submit-btn:hover {
  transform: translateY(-3px);
  box-shadow: 0 6px 20px rgba(0, 168, 89, 0.4);
}

.delete-btn {
  background: linear-gradient(135deg, var(--red), #b71c1c);
}

.delete-btn:hover {
  box-shadow: 0 6px 20px rgba(198, 40, 40, 0.4);
}

/* Empty State */
.empty-state {
  text-align: center;
  padding: 60px 20px;
  color: #666;
}

.empty-state i {
  font-size: 48px;
  color: #ddd;
  margin-bottom: 15px;
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

/* Alert Popup */
.alert-popup {
  display: none;
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  background: var(--white);
  border-radius: 12px;
  padding: 30px 40px;
  text-align: center;
  z-index: 4000;
  box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2);
  min-width: 350px;
  border-top: 6px solid;
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

.alert-popup.show {
  display: block;
}

.alert-popup.error {
  border-top-color: var(--red);
}

.alert-popup.success {
  border-top-color: var(--green);
}

.alert-popup i {
  font-size: 48px;
  margin-bottom: 15px;
  display: block;
}

.alert-popup.error i {
  color: var(--red);
}

.alert-popup.success i {
  color: var(--green);
}

.alert-popup h3 {
  margin: 10px 0 5px;
  font-size: 20px;
  color: var(--dark);
}

.alert-popup p {
  margin: 0;
  color: #666;
  font-size: 14px;
}

/* Responsive */
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
    padding: 15px;
  }
  
  .faculty-badge {
    margin-left: 0;
    margin-top: 5px;
  }
  
  .add-btn {
    align-self: stretch;
    justify-content: center;
  }
  
  .table-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 10px;
  }
  
  .modal-content {
    padding: 25px 20px;
  }
}

@media (max-width: 480px) {
  .page-header h1 {
    font-size: 20px;
    flex-direction: column;
    align-items: flex-start;
  }
  
  .faculty-badge {
    margin-left: 0;
    margin-top: 5px;
  }
  
  .alert-popup {
    min-width: 280px;
    padding: 20px 25px;
  }
  
  .action-buttons {
    flex-direction: column;
  }
}

/* Scrollbar */
.table-wrapper::-webkit-scrollbar,
.modal-content::-webkit-scrollbar {
  width: 8px;
  height: 8px;
}

.table-wrapper::-webkit-scrollbar-track,
.modal-content::-webkit-scrollbar-track {
  background: #f1f1f1;
  border-radius: 4px;
}

.table-wrapper::-webkit-scrollbar-thumb,
.modal-content::-webkit-scrollbar-thumb {
  background: var(--green);
  border-radius: 4px;
}

.table-wrapper::-webkit-scrollbar-thumb:hover,
.modal-content::-webkit-scrollbar-thumb:hover {
  background: var(--blue);
}
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
  <!-- Page Header -->
  <div class="page-header">
    <h1>
      <i class="fas fa-layer-group"></i> Semesters Management
    </h1>
    <button class="add-btn" onclick="openModal('addModal')">
      <i class="fas fa-plus"></i> Add New Semester
    </button>
  </div>
  
  <!-- Table Header -->
  <div class="table-wrapper">
    <div class="table-header">
      <h3><i class="fas fa-list"></i> Semesters List</h3>
      <div class="results-count">
        <i class="fas fa-eye"></i> Showing <?= count($semesters) ?> semesters
      </div>
    </div>
    
    <!-- Semesters Table (WITH ACTION BUTTONS) -->
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Semester Name</th>
          <th>Description</th>
          <th>Status</th>
          <th>Created At</th>
          <th>Last Updated</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($semesters): ?>
          <?php foreach ($semesters as $i => $s): ?>
          <tr>
            <td><strong><?= $i + 1 ?></strong></td>
            <td>
              <span class="semester-badge">
                <i class="fas fa-tag"></i> <?= htmlspecialchars($s['semester_name']) ?>
              </span>
            </td>
            <td>
              <?= htmlspecialchars($s['description'] ?? 'No description') ?>
            </td>
            <td>
              <span class="status-<?= strtolower($s['status']) ?>">
                <i class="fas fa-<?= $s['status'] === 'active' ? 'check-circle' : 'pause-circle' ?>"></i>
                <?= ucfirst($s['status']) ?>
              </span>
            </td>
            <td>
              <span style="color: #666; font-size: 13px;">
                <i class="fas fa-clock"></i> <?= date('d M Y H:i', strtotime($s['created_at'] ?? 'now')) ?>
              </span>
            </td>
            <td>
              <span style="color: #666; font-size: 13px;">
                <i class="fas fa-sync-alt"></i> <?= date('d M Y H:i', strtotime($s['updated_at'] ?? 'now')) ?>
              </span>
            </td>
            <td>
              <div class="action-buttons">
                <button class="btn-edit" onclick="openEditModal(
                  <?= $s['semester_id'] ?>,
                  '<?= htmlspecialchars($s['semester_name'], ENT_QUOTES) ?>',
                  '<?= htmlspecialchars($s['description'] ?? '', ENT_QUOTES) ?>',
                  '<?= $s['status'] ?>'
                )">
                  <i class="fas fa-edit"></i> Edit
                </button>
                <button class="btn-delete" onclick="openDeleteModal(<?= $s['semester_id'] ?>)">
                  <i class="fas fa-trash"></i> Delete
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="7">
              <div class="empty-state">
                <i class="fas fa-layer-group"></i>
                <h3>No semesters found</h3>
                <p>Get started by adding your first semester.</p>
                <button class="add-first-btn" onclick="openModal('addModal')">
                  <i class="fas fa-plus"></i> Add First Semester
                </button>
              </div>
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ADD MODAL -->
<div class="modal" id="addModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('addModal')">&times;</span>
    <h2><i class="fas fa-plus-circle"></i> Add New Semester</h2>
    
    <form method="POST" id="addForm">
      <input type="hidden" name="action" value="add">
      
      <div class="form-group">
        <label><i class="fas fa-tag"></i> Semester Name *</label>
        <input type="text" name="semester_name" class="form-control" required 
               placeholder="e.g., Semester 1, Foundation 1, etc.">
      </div>
      
      <div class="form-group">
        <label><i class="fas fa-align-left"></i> Description</label>
        <textarea name="description" class="form-control" 
                  placeholder="Optional description..."></textarea>
      </div>
      
      <div class="form-group">
        <label><i class="fas fa-circle"></i> Status *</label>
        <select name="status" class="form-control" required>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
      
      <button class="submit-btn" type="submit">
        <i class="fas fa-save"></i> Save Semester
      </button>
    </form>
  </div>
</div>

<!-- EDIT MODAL -->
<div class="modal" id="editModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('editModal')">&times;</span>
    <h2><i class="fas fa-edit"></i> Edit Semester</h2>
    
    <form method="POST" id="editForm">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="semester_id" id="edit_id">
      
      <div class="form-group">
        <label><i class="fas fa-tag"></i> Semester Name *</label>
        <input type="text" name="semester_name" id="edit_name" class="form-control" required>
      </div>
      
      <div class="form-group">
        <label><i class="fas fa-align-left"></i> Description</label>
        <textarea name="description" id="edit_desc" class="form-control"></textarea>
      </div>
      
      <div class="form-group">
        <label><i class="fas fa-circle"></i> Status *</label>
        <select name="status" id="edit_status" class="form-control" required>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
      
      <button class="submit-btn" type="submit">
        <i class="fas fa-sync-alt"></i> Update Semester
      </button>
    </form>
  </div>
</div>

<!-- DELETE MODAL -->
<div class="modal" id="deleteModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('deleteModal')">&times;</span>
    <h2 style="color: var(--red);"><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h2>
    
    <form method="POST" id="deleteForm">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="semester_id" id="delete_id">
      
      <div style="text-align: center; margin: 30px 0;">
        <i class="fas fa-trash-alt" style="font-size: 48px; color: var(--red); margin-bottom: 15px;"></i>
        <p style="font-size: 16px; margin-bottom: 10px;">
          Are you sure you want to delete this semester?
        </p>
        <p style="color: #666; font-size: 14px;">
          This action cannot be undone.
        </p>
      </div>
      
      <button class="submit-btn delete-btn" type="submit">
        <i class="fas fa-trash"></i> Yes, Delete Semester
      </button>
    </form>
  </div>
</div>

<!-- Alert Popup -->
<div id="popup" class="alert-popup <?= $type ?>">
  <i class="fas <?= $type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
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
function openEditModal(id, name, desc, status) {
  openModal('editModal');
  document.getElementById('edit_id').value = id;
  document.getElementById('edit_name').value = name;
  document.getElementById('edit_desc').value = desc;
  document.getElementById('edit_status').value = status;
}

// Open delete modal
function openDeleteModal(id) {
  openModal('deleteModal');
  document.getElementById('delete_id').value = id;
}

// Close modal on outside click
document.addEventListener('click', function(e) {
  if (e.target.classList.contains('modal')) {
    e.target.classList.remove('show');
    document.body.style.overflow = 'auto';
  }
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal.show').forEach(modal => {
      modal.classList.remove('show');
      document.body.style.overflow = 'auto';
    });
  }
});

// Show alert if there's a message
<?php if (!empty($message)): ?>
  document.addEventListener('DOMContentLoaded', function() {
    const popup = document.getElementById('popup');
    if (popup) {
      popup.classList.add('show');
      setTimeout(() => {
        popup.classList.remove('show');
      }, 3500);
    }
  });
<?php endif; ?>
</script>

<script src="../assets/js/sidebar.js"></script>

<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>