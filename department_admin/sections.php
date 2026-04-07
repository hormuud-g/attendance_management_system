<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

/* ===========================================
   ✅ ACCESS CONTROL
=========================================== */
$role = strtolower($_SESSION['user']['role'] ?? '');
if (!in_array($role, ['faculty_admin', 'department_admin'])) {
  header("Location: ../login.php");
  exit;
}

// ✅ Get linked_id based on role
$linked_id = $_SESSION['user']['linked_id'];
$faculty_id = null;
$department_id = null;

if ($role === 'faculty_admin') {
    $faculty_id = $linked_id;
} elseif ($role === 'department_admin') {
    $department_id = $linked_id;
    
    // Get faculty_id from department
    $stmt = $pdo->prepare("SELECT faculty_id FROM departments WHERE department_id = ?");
    $stmt->execute([$department_id]);
    $dept_info = $stmt->fetch(PDO::FETCH_ASSOC);
    $faculty_id = $dept_info['faculty_id'] ?? null;
}

if (!$faculty_id) {
  die("❌ Faculty ID missing. Please re-login.");
}

$message = "";
$type = "";

/* ===========================================
   ✅ CRUD OPERATIONS
=========================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $action  = $_POST['action'] ?? '';

    $dept     = $_POST['department_id'] ?? null;
    $program  = $_POST['program_id'] ?? null;
    $name     = trim($_POST['section_name'] ?? '');
    $capacity = $_POST['capacity'] ?? null;
    $status   = $_POST['status'] ?? 'active';

    // For department_admin, use their department_id automatically
    $dept_id = ($role === 'department_admin') ? $department_id : $dept;

    /* ---------- ADD ---------- */
    if ($action === 'add' && $role === 'faculty_admin') {
      // Only faculty_admin can add sections
      if (!$dept || !$program || !$name || !$capacity)
        throw new Exception("All fields are required!");

      // ✅ verify hierarchy belongs to the same faculty
      $verify = $pdo->prepare("
        SELECT COUNT(*) FROM departments d
        JOIN programs p ON p.department_id = d.department_id
        WHERE d.department_id=? AND p.program_id=? AND d.faculty_id=?");
      $verify->execute([$dept, $program, $faculty_id]);
      if ($verify->fetchColumn() == 0)
        throw new Exception("Invalid Department/Program: not part of your Faculty!");

      $stmt = $pdo->prepare("
        INSERT INTO class_section (faculty_id, department_id, program_id, section_name, capacity, status)
        VALUES (?,?,?,?,?,?)");
      $stmt->execute([$faculty_id, $dept, $program, $name, $capacity, $status]);
      $message = "✅ Section added successfully!";
      $type = "success";
    }

    /* ---------- UPDATE ---------- */
    if ($action === 'update') {
      $id = $_POST['section_id'] ?? null;
      if (!$id || !$name || !$capacity)
        throw new Exception("All required fields must be filled!");

      // Build query based on role
      if ($role === 'faculty_admin') {
        if (!$dept || !$program)
          throw new Exception("All fields are required!");

        // ✅ verify belongs to same faculty
        $verify = $pdo->prepare("
          SELECT COUNT(*) FROM departments d
          JOIN programs p ON p.department_id = d.department_id
          WHERE d.department_id=? AND p.program_id=? AND d.faculty_id=?");
        $verify->execute([$dept, $program, $faculty_id]);
        if ($verify->fetchColumn() == 0)
          throw new Exception("Invalid Department/Program: not part of your Faculty!");

        $stmt = $pdo->prepare("
          UPDATE class_section
          SET department_id=?, program_id=?, section_name=?, capacity=?, status=?, updated_at=NOW()
          WHERE section_id=? AND faculty_id=?");
        $params = [$dept, $program, $name, $capacity, $status, $id, $faculty_id];
      } else { // department_admin
        $stmt = $pdo->prepare("
          UPDATE class_section
          SET section_name=?, capacity=?, status=?, updated_at=NOW()
          WHERE section_id=? AND department_id=?");
        $params = [$name, $capacity, $status, $id, $department_id];
      }

      $stmt->execute($params);
      $message = "✅ Section updated successfully!";
      $type = "success";
    }

    /* ---------- DELETE ---------- */
    if ($action === 'delete' && $role === 'faculty_admin') {
      // Only faculty_admin can delete sections
      $id = $_POST['section_id'] ?? null;
      if (!$id) throw new Exception("Missing section ID!");
      $pdo->prepare("DELETE FROM class_section WHERE section_id=? AND faculty_id=?")->execute([$id, $faculty_id]);
      $message = "✅ Section deleted successfully!";
      $type = "success";
    }

  } catch (Exception $e) {
    $message = "❌ " . $e->getMessage();
    $type = "error";
  }
}

/* ===========================================
   ✅ FETCH DATA
=========================================== */
// Get department info for department_admin
$department_info = [];
if ($role === 'department_admin' && $department_id) {
    $stmt = $pdo->prepare("SELECT department_name FROM departments WHERE department_id = ?");
    $stmt->execute([$department_id]);
    $department_info = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get faculty info
$faculty_info = [];
if ($faculty_id) {
    $stmt = $pdo->prepare("SELECT faculty_name FROM faculties WHERE faculty_id = ?");
    $stmt->execute([$faculty_id]);
    $faculty_info = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Faculties (only their own)
$faculties = $pdo->prepare("SELECT * FROM faculties WHERE faculty_id=?");
$faculties->execute([$faculty_id]);
$faculties = $faculties->fetchAll(PDO::FETCH_ASSOC);

// Departments based on role
if ($role === 'faculty_admin') {
    // Faculty admin sees all departments in their faculty
    $departments = $pdo->prepare("SELECT * FROM departments WHERE faculty_id=? ORDER BY department_name ASC");
    $departments->execute([$faculty_id]);
    $departments = $departments->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Department admin only sees their own department
    $departments = $pdo->prepare("SELECT * FROM departments WHERE department_id=? ORDER BY department_name ASC");
    $departments->execute([$department_id]);
    $departments = $departments->fetchAll(PDO::FETCH_ASSOC);
}

// Programs based on role
if ($role === 'faculty_admin') {
    // Faculty admin sees all programs in their faculty
    $programs = $pdo->prepare("
      SELECT p.* FROM programs p
      JOIN departments d ON p.department_id = d.department_id
      WHERE d.faculty_id=? ORDER BY p.program_name ASC
    ");
    $programs->execute([$faculty_id]);
    $programs = $programs->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Department admin only sees programs in their department
    $programs = $pdo->prepare("
      SELECT p.* FROM programs p
      WHERE p.department_id=? ORDER BY p.program_name ASC
    ");
    $programs->execute([$department_id]);
    $programs = $programs->fetchAll(PDO::FETCH_ASSOC);
}

// Sections based on role
if ($role === 'faculty_admin') {
    // Faculty admin sees all sections in their faculty
    $sections = $pdo->prepare("
      SELECT 
        s.*, 
        f.faculty_name, 
        d.department_name, 
        p.program_name
      FROM class_section s
      JOIN faculties f ON s.faculty_id = f.faculty_id
      JOIN departments d ON s.department_id = d.department_id
      JOIN programs p ON s.program_id = p.program_id
      WHERE s.faculty_id = ?
      ORDER BY s.section_id DESC
    ");
    $sections->execute([$faculty_id]);
    $sections = $sections->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Department admin only sees sections in their department
    $sections = $pdo->prepare("
      SELECT 
        s.*, 
        f.faculty_name, 
        d.department_name, 
        p.program_name
      FROM class_section s
      JOIN faculties f ON s.faculty_id = f.faculty_id
      JOIN departments d ON s.department_id = d.department_id
      JOIN programs p ON s.program_id = p.program_id
      WHERE s.department_id = ?
      ORDER BY s.section_id DESC
    ");
    $sections->execute([$department_id]);
    $sections = $sections->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Class Sections | Hormuud University</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="../images.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
  --green: #00843D;
  --light-green: #00A651;
  --blue: #0072CE;
  --red: #C62828;
  --bg: #F5F9F7;
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

/* Header */
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 15px;
}
.page-header h1 { color: var(--blue); font-size: 22px; font-weight: 700; }
.add-btn {
  background: var(--green);
  color: #fff;
  border: none;
  padding: 10px 18px;
  border-radius: 6px;
  font-weight: 600;
  cursor: pointer;
  transition: .3s;
}
.add-btn:hover { background: var(--light-green); }
.add-btn:disabled {
  background: #ccc;
  cursor: not-allowed;
}

/* Info Sections */
.department-info {
  background: #e8f5e9;
  padding: 12px 16px;
  border-radius: 8px;
  margin-bottom: 20px;
  border-left: 4px solid var(--green);
  font-weight: 600;
}
.info-banner {
  background: var(--blue);
  color: white;
  padding: 12px 15px;
  border-radius: 6px;
  margin-bottom: 20px;
  font-weight: 600;
}
.view-only-notice {
  background: #fff3cd;
  border: 1px solid #ffeaa7;
  padding: 15px;
  border-radius: 8px;
  margin-bottom: 20px;
  text-align: center;
}
.view-only-notice i {
  color: #f39c12;
  font-size: 20px;
  margin-right: 10px;
}

/* Status Badges */
.status-badge {
  padding: 4px 10px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
}
.status-active { background: #e8f5e8; color: #2e7d32; }
.status-inactive { background: #ffebee; color: #c62828; }

/* Table wrapper */
.table-wrapper {
  overflow-x: auto;
  overflow-y: auto;
  max-height: 500px;
  background: #fff;
  border-radius: 10px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}
table { width: 100%; border-collapse: collapse; }
thead th {
  background: var(--blue);
  color: #fff;
  position: sticky; top: 0; z-index: 2;
}
th, td { padding: 12px 14px; border-bottom: 1px solid #eee; white-space: nowrap; text-align: left; }
tr:hover { background: #eef8f0; }
.action-buttons { display: flex; justify-content: center; gap: 6px; }
.btn-edit, .btn-delete {
  border: none; color: #fff; border-radius: 6px; padding: 8px 10px; cursor: pointer; transition: .2s;
}
.btn-edit { background: var(--blue); }
.btn-edit:hover { background: #2196f3; }
.btn-delete { background: var(--red); }
.btn-delete:hover { background: #e53935; }
.btn-delete:disabled {
  background: #ccc;
  cursor: not-allowed;
}

/* Modal */
.modal {
  display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45);
  justify-content: center; align-items: center; z-index: 3000;
}
.modal.show { display: flex; }
.modal-content {
  background: #fff; border-radius: 10px; width: 95%; max-width: 720px;
  padding: 25px 30px; box-shadow: 0 8px 25px rgba(0,0,0,0.25);
  border-top: 5px solid var(--blue); position: relative; overflow-y: auto; max-height: 90vh;
}
.close-modal {
  position: absolute; top: 10px; right: 15px; font-size: 22px; cursor: pointer;
  color: var(--red); transition: .2s;
}
.close-modal:hover { transform: scale(1.2); }

/* Form */
form { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px 20px; }
form div { display: flex; flex-direction: column; }
label { font-weight: 600; color: var(--blue); font-size: 13px; margin-bottom: 3px; }
input, select {
  padding: 10px; border: 1.5px solid #ccc; border-radius: 6px;
  background: #f9f9f9; transition: .2s; font-size: 13px;
}
input:focus, select:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(0,114,206,0.12); outline: none; }
.save-btn {
  grid-column: span 2; background: var(--green); color: #fff; border: none;
  padding: 11px; border-radius: 6px; font-weight: 600; cursor: pointer; transition: .3s;
}
.save-btn:hover { background: var(--light-green); }
.save-btn:disabled {
  background: #ccc;
  cursor: not-allowed;
}

/* Alert Popup */
.alert-popup {
  display: none; position: fixed; top: 20px; right: 20px; background: #fff;
  padding: 16px 24px; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);
  z-index: 4000; border-left: 5px solid var(--green); max-width: 400px;
}
.alert-popup.show { display: block; animation: slideIn 0.5s ease; }
.alert-popup.success { border-left-color: var(--green); }
.alert-popup.error { border-left-color: var(--red); }
@keyframes slideIn { 
  from { opacity: 0; transform: translateX(100px); } 
  to { opacity: 1; transform: translateX(0); } 
}

/* Responsive */
@media(max-width:600px){ form{grid-template-columns:1fr;} .table-wrapper{max-height:400px;} }
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
  <!-- Department/Faculty Info -->
  <?php if ($role === 'department_admin' && !empty($department_info)): ?>
  <!-- <div class="department-info">
    <i class="fas fa-building"></i> 
    Department: <strong><?= htmlspecialchars($department_info['department_name']) ?></strong>
    <?php if (!empty($faculty_info)): ?>
      | Faculty: <strong><?= htmlspecialchars($faculty_info['faculty_name']) ?></strong>
    <?php endif; ?>
  </div> -->
  <?php elseif ($role === 'faculty_admin' && !empty($faculty_info)): ?>
  <div class="info-banner">
    <i class="fas fa-university"></i> 
    Faculty: <strong><?= htmlspecialchars($faculty_info['faculty_name']) ?></strong>
  </div>
  <?php endif; ?>

  <!-- View Only Notice for Department Admin -->
  <?php if ($role === 'department_admin'): ?>
  <!-- <div class="view-only-notice">
    <i class="fas fa-eye"></i>
    <strong>Limited Access:</strong> You can view and edit sections in your department, but cannot create new sections or delete existing ones.
  </div> -->
  <?php endif; ?>

  <div class="page-header">
    <h1>Class Sections
      <!-- <?php if ($role === 'department_admin' && !empty($department_info)): ?>
        <small style="font-size: 16px; color: #666;">- <?= htmlspecialchars($department_info['department_name']) ?></small>
      <?php endif; ?> -->
    </h1>
    <button class="add-btn" onclick="openModal('addModal')" <?= $role === 'department_admin' ? 'disabled' : '' ?>>+ Add Section</button>
  </div>

  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <?php if ($role === 'faculty_admin'): ?>
            <th>Faculty</th>
            <th>Department</th>
          <?php endif; ?>
          <th>Program</th>
          <th>Section</th>
          <th>Capacity</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if($sections): foreach($sections as $i=>$s): ?>
        <tr>
          <td><?= $i+1 ?></td>
          <?php if ($role === 'faculty_admin'): ?>
            <td><?= htmlspecialchars($s['faculty_name']) ?></td>
            <td><?= htmlspecialchars($s['department_name']) ?></td>
          <?php endif; ?>
          <td><?= htmlspecialchars($s['program_name']) ?></td>
          <td><?= htmlspecialchars($s['section_name']) ?></td>
          <td><?= htmlspecialchars($s['capacity']) ?></td>
          <td>
            <span class="status-badge status-<?= $s['status'] ?>">
              <?= ucfirst($s['status']) ?>
            </span>
          </td>
          <td>
            <div class="action-buttons">
              <button class="btn-edit" onclick="openEditModal(<?= $s['section_id'] ?>,'<?= $s['faculty_id'] ?>','<?= $s['department_id'] ?>','<?= $s['program_id'] ?>','<?= htmlspecialchars($s['section_name']) ?>','<?= $s['capacity'] ?>','<?= $s['status'] ?>')">
                <i class="fa-solid fa-pen-to-square"></i>
              </button>
              <button class="btn-delete" onclick="openDeleteModal(<?= $s['section_id'] ?>)" <?= $role === 'department_admin' ? 'disabled' : '' ?>>
                <i class="fa-solid fa-trash"></i>
              </button>
            </div>
          </td>
        </tr>
        <?php endforeach; else: ?>
        <tr>
          <td colspan="<?= $role === 'faculty_admin' ? '8' : '6' ?>" style="text-align:center;color:#777;">
            No sections found <?= $role === 'department_admin' ? 'in your department' : '' ?>.
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ADD MODAL - Only for faculty_admin -->
<?php if ($role === 'faculty_admin'): ?>
<div class="modal" id="addModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('addModal')">&times;</span>
    <h2 style="text-align:center;color:var(--blue)">Add Section</h2>
    <form method="POST">
      <input type="hidden" name="action" value="add">
      <div><label>Faculty</label>
        <select id="add_faculty" name="faculty_id" onchange="filterDepartments('add')" required>
          <option value="">Select Faculty</option>
          <?php foreach($faculties as $f): ?><option value="<?= $f['faculty_id'] ?>"><?= $f['faculty_name'] ?></option><?php endforeach; ?>
        </select>
      </div>
      <div><label>Department</label>
        <select id="add_department" name="department_id" onchange="filterPrograms('add')" required>
          <option value="">Select Department</option>
        </select>
      </div>
      <div><label>Program</label>
        <select id="add_program" name="program_id" required>
          <option value="">Select Program</option>
        </select>
      </div>
      <div><label>Section Name</label><input type="text" name="section_name" required></div>
      <div><label>Capacity</label><input type="number" name="capacity" required></div>
      <div><label>Status</label>
        <select name="status">
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
      <button class="save-btn" type="submit">Save Section</button>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- EDIT MODAL -->
<div class="modal" id="editModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('editModal')">&times;</span>
    <h2 style="text-align:center;color:var(--blue)">Edit Section</h2>
    <form method="POST">
      <input type="hidden" name="action" value="update">
      <input type="hidden" id="edit_id" name="section_id">
      
      <?php if ($role === 'faculty_admin'): ?>
      <div><label>Faculty</label>
        <select id="edit_faculty" name="faculty_id" onchange="filterDepartments('edit')" required>
          <?php foreach($faculties as $f): ?><option value="<?= $f['faculty_id'] ?>"><?= $f['faculty_name'] ?></option><?php endforeach; ?>
        </select>
      </div>
      <div><label>Department</label>
        <select id="edit_department" name="department_id" onchange="filterPrograms('edit')" required>
          <?php foreach($departments as $d): ?><option value="<?= $d['department_id'] ?>"><?= $d['department_name'] ?></option><?php endforeach; ?>
        </select>
      </div>
      <div><label>Program</label>
        <select id="edit_program" name="program_id" required>
          <?php foreach($programs as $p): ?><option value="<?= $p['program_id'] ?>"><?= $p['program_name'] ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php else: ?>
        <input type="hidden" name="department_id" value="<?= $department_id ?>">
      <?php endif; ?>
      
      <div><label>Section Name</label><input type="text" id="edit_name" name="section_name" required></div>
      <div><label>Capacity</label><input type="number" id="edit_capacity" name="capacity" required></div>
      <div><label>Status</label>
        <select id="edit_status" name="status">
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
      <button class="save-btn" type="submit">Update Section</button>
    </form>
  </div>
</div>

<!-- DELETE MODAL - Only for faculty_admin -->
<?php if ($role === 'faculty_admin'): ?>
<div class="modal" id="deleteModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('deleteModal')">&times;</span>
    <h2 style="color:#C62828;text-align:center;">Confirm Delete</h2>
    <form method="POST">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" id="delete_id" name="section_id">
      <p style="text-align:center;">Are you sure you want to delete this section?</p>
      <button class="save-btn" style="background:#C62828" type="submit">Yes, Delete</button>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Alert Popup -->
<div id="popup" class="alert-popup <?= $type ?>">
  <div style="display:flex;align-items:center;gap:10px;">
    <i class="fas <?= $type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>" 
       style="color:<?= $type === 'success' ? 'var(--green)' : 'var(--red)' ?>;font-size:18px;"></i>
    <div>
      <strong><?= $message ?></strong>
    </div>
  </div>
</div>

<script>
const departments = <?= json_encode($departments) ?>;
const programs = <?= json_encode($programs) ?>;

function filterDepartments(prefix){
  const facultyId = document.getElementById(prefix+'_faculty').value;
  const dSelect = document.getElementById(prefix+'_department');
  dSelect.innerHTML = '<option value="">Select Department</option>';
  departments.filter(d=>d.faculty_id==facultyId).forEach(d=>{
    dSelect.innerHTML += `<option value="${d.department_id}">${d.department_name}</option>`;
  });
  filterPrograms(prefix);
}

function filterPrograms(prefix){
  const deptId = document.getElementById(prefix+'_department').value;
  const pSelect = document.getElementById(prefix+'_program');
  pSelect.innerHTML = '<option value="">Select Program</option>';
  programs.filter(p=>p.department_id==deptId).forEach(p=>{
    pSelect.innerHTML += `<option value="${p.program_id}">${p.program_name}</option>`;
  });
}

function openModal(id){document.getElementById(id).classList.add('show');}
function closeModal(id){document.getElementById(id).classList.remove('show');}

function openEditModal(id,faculty,dept,program,name,capacity,status){
  openModal('editModal');
  document.getElementById('edit_id').value=id;
  
  <?php if ($role === 'faculty_admin'): ?>
  document.getElementById('edit_faculty').value=faculty;
  filterDepartments('edit');
  setTimeout(()=>{
    document.getElementById('edit_department').value=dept;
    filterPrograms('edit');
    setTimeout(()=>{ document.getElementById('edit_program').value=program; }, 100);
  },100);
  <?php endif; ?>
  
  document.getElementById('edit_name').value=name;
  document.getElementById('edit_capacity').value=capacity;
  document.getElementById('edit_status').value=status;
}

function openDeleteModal(id){
  openModal('deleteModal');
  document.getElementById('delete_id').value=id;
}

// Auto-hide alert popup
<?php if (!empty($message)): ?>
document.addEventListener('DOMContentLoaded', function() {
  const popup = document.getElementById('popup');
  if (popup) {
    popup.classList.add('show');
    setTimeout(() => {
      popup.classList.remove('show');
    }, 5000);
  }
});
<?php endif; ?>
</script>

<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>