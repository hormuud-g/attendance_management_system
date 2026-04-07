<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Check login
if (empty($_SESSION['user'])) {
  header("Location: ../login.php");
  exit;
}

// ✅ Access control - Only campus admin
if (strtolower($_SESSION['user']['role'] ?? '') !== 'campus_admin') {
  header("Location: ../login.php");
  exit;
}

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
      $faculty_id = $_POST['faculty_id'];
      $code = trim($_POST['room_code']);
      
      $check = $pdo->prepare("SELECT COUNT(*) FROM rooms WHERE room_code=? AND faculty_id=?");
      $check->execute([$code, $faculty_id]);

      if ($check->fetchColumn() > 0) {
        $message = "⚠️ Room code already exists in this faculty!";
        $type = "error";
      } else {
        $stmt = $pdo->prepare("INSERT INTO rooms 
          (faculty_id, department_id, building_name, floor_no, room_name, room_code, capacity, room_type, description, status)
          VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
          $faculty_id,
          $_POST['department_id'],
          $_POST['building_name'],
          $_POST['floor_no'],
          $_POST['room_name'],
          $_POST['room_code'],
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
      $stmt = $pdo->prepare("UPDATE rooms 
        SET faculty_id=?, department_id=?, building_name=?, floor_no=?, room_name=?, room_code=?, capacity=?, room_type=?, description=?, status=? 
        WHERE room_id=?");
      $stmt->execute([
        $_POST['faculty_id'],
        $_POST['department_id'],
        $_POST['building_name'],
        $_POST['floor_no'],
        $_POST['room_name'],
        $_POST['room_code'],
        $_POST['capacity'],
        $_POST['room_type'],
        $_POST['description'],
        $_POST['status'],
        $_POST['room_id']
      ]);

      $message = "✅ Room updated successfully!";
      $type = "success";
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

// ✅ All Faculties for campus admin
$stmt = $pdo->prepare("SELECT faculty_id, faculty_name FROM faculties ORDER BY faculty_name");
$stmt->execute();
$faculties = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ All Departments
$stmt = $pdo->prepare("SELECT department_id, department_name, faculty_id FROM departments ORDER BY department_name");
$stmt->execute();
$departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ Fetch all rooms across all faculties
$stmt = $pdo->prepare("SELECT r.*, f.faculty_name, d.department_name
  FROM rooms r
  LEFT JOIN faculties f ON r.faculty_id = f.faculty_id
  LEFT JOIN departments d ON r.department_id = d.department_id
  ORDER BY r.room_id DESC");
$stmt->execute();
$rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Room Management | Campus Admin | Hormuud University</title>
<link rel="icon" type="image/png" href="../images.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{--green:#00843D;--blue:#0072CE;--red:#C62828;--light-green:#00A651;--bg:#F5F9F7;}
body{font-family:'Poppins',sans-serif;background:var(--bg);margin:0;color:#333;}
.main-content{padding:20px;margin-top:90px;margin-left:250px;transition:all .3s ease;}
.sidebar.collapsed ~ .main-content{margin-left:70px;}
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;}
.page-header h1{color:var(--blue);font-size:24px;margin:0;font-weight:700;}
.add-btn{background:var(--green);color:#fff;border:none;padding:10px 18px;border-radius:6px;font-weight:600;cursor:pointer;transition:.3s;}
.add-btn:hover{background:var(--light-green);}
.table-wrapper{overflow:auto;max-height:500px;border-radius:10px;}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;box-shadow:0 4px 12px rgba(0,0,0,0.08);}
thead th{position:sticky;top:0;background:var(--blue);color:#fff;z-index:2;}
th,td{padding:12px 14px;border-bottom:1px solid #eee;text-align:left;white-space:nowrap;}
tr:hover{background:#eef8f0;}
.action-btns{display:flex;justify-content:center;gap:8px;}
.edit-btn,.del-btn{border:none;border-radius:6px;padding:8px 10px;color:#fff;cursor:pointer;transition:.3s;}
.edit-btn{background:var(--blue);} .edit-btn:hover{background:#2196f3;}
.del-btn{background:var(--red);} .del-btn:hover{background:#e53935;}
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);justify-content:center;align-items:center;z-index:3000;}
.modal.show{display:flex;}
.modal-content{background:#fff;border-radius:10px;width:90%;max-width:800px;padding:25px;position:relative;box-shadow:0 8px 25px rgba(0,0,0,.25);}
.close-modal{position:absolute;top:10px;right:15px;font-size:24px;cursor:pointer;color:#444;}
form{display:grid;grid-template-columns:repeat(2,1fr);gap:15px;}
label{font-weight:600;color:#0072CE;}
input,select,textarea{width:100%;padding:10px;border:1.5px solid #ccc;border-radius:6px;font-family:'Poppins';}
textarea{resize:none;height:60px;}
.save-btn{grid-column:span 2;background:var(--green);color:#fff;border:none;padding:10px;border-radius:6px;font-weight:600;cursor:pointer;transition:.3s;}
.save-btn:hover{background:var(--light-green);}
.alert-popup{display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:10px;padding:16px 24px;text-align:center;z-index:4000;box-shadow:0 6px 20px rgba(0,0,0,.25);}
.alert-popup.show{display:block;}
.alert-popup.success{border-top:5px solid var(--green);} .alert-popup.error{border-top:5px solid var(--red);}
.filter-section{background:#fff;padding:15px;border-radius:10px;margin-bottom:20px;box-shadow:0 2px 8px rgba(0,0,0,0.08);}
.filter-row{display:flex;gap:15px;align-items:end;flex-wrap:wrap;}
.filter-group{flex:1;min-width:200px;}
.filter-group label{display:block;margin-bottom:5px;font-weight:600;}
.filter-btn{background:var(--blue);color:#fff;border:none;padding:10px 18px;border-radius:6px;font-weight:600;cursor:pointer;transition:.3s;height:fit-content;}
.filter-btn:hover{background:#005a9e;}
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
  <div class="page-header">
    <h1>Room Management - Campus Admin</h1>
    <button class="add-btn" onclick="openModal('addModal')">+ Add Room</button>
  </div>

  <!-- Filter Section -->
  <div class="filter-section">
    <h3 style="margin-top:0;color:var(--blue);">Filter Rooms</h3>
    <div class="filter-row">
      <div class="filter-group">
        <label for="filter_faculty">Faculty</label>
        <select id="filter_faculty" onchange="filterRooms()">
          <option value="">All Faculties</option>
          <?php foreach($faculties as $f): ?>
            <option value="<?= $f['faculty_id'] ?>"><?= htmlspecialchars($f['faculty_name']) ?></option>
          <?php endforeach; ?>
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
      <button class="filter-btn" onclick="resetFilters()">Reset Filters</button>
    </div>
  </div>

  <div class="table-wrapper">
    <table id="roomsTable">
      <thead>
        <tr>
          <th>#</th><th>Faculty</th><th>Department</th><th>Building</th><th>Floor</th><th>Room Name</th><th>Code</th>
          <th>Capacity</th><th>Type</th><th>Status</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if($rooms): foreach($rooms as $i=>$r): ?>
        <tr data-faculty="<?= $r['faculty_id'] ?>" data-type="<?= $r['room_type'] ?>" data-status="<?= $r['status'] ?>">
          <td><?= $i+1 ?></td>
          <td><?= htmlspecialchars($r['faculty_name']) ?></td>
          <td><?= htmlspecialchars($r['department_name']) ?></td>
          <td><?= htmlspecialchars($r['building_name']) ?></td>
          <td><?= htmlspecialchars($r['floor_no']) ?></td>
          <td><?= htmlspecialchars($r['room_name']) ?></td>
          <td><?= htmlspecialchars($r['room_code']) ?></td>
          <td><?= htmlspecialchars($r['capacity']) ?></td>
          <td><?= htmlspecialchars($r['room_type']) ?></td>
          <td><?= ucfirst($r['status']) ?></td>
          <td class="action-btns">
            <button class="edit-btn" onclick="openEditModal(
              <?= $r['room_id'] ?>,
              '<?= $r['faculty_id'] ?>',
              '<?= $r['department_id'] ?>',
              '<?= htmlspecialchars($r['building_name']) ?>',
              '<?= htmlspecialchars($r['floor_no']) ?>',
              '<?= htmlspecialchars($r['room_name']) ?>',
              '<?= htmlspecialchars($r['room_code']) ?>',
              '<?= htmlspecialchars($r['capacity']) ?>',
              '<?= htmlspecialchars($r['room_type']) ?>',
              '<?= htmlspecialchars($r['description']) ?>',
              '<?= $r['status'] ?>'
            )">
              <i class="fa-solid fa-pen-to-square"></i>
            </button>
            <button class="del-btn" onclick="deleteRoom(<?= $r['room_id'] ?>)">
              <i class="fa-solid fa-trash"></i>
            </button>
          </td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="11" style="text-align:center;color:#777;">No rooms found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ADD MODAL -->
<div class="modal" id="addModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('addModal')">&times;</span>
    <h2>Add Room</h2>
    <form method="POST">
      <input type="hidden" name="action" value="add">
      <div><label>Faculty</label>
        <select id="add_faculty" name="faculty_id" required onchange="updateDepartments('add')">
          <option value="">Select Faculty</option>
          <?php foreach($faculties as $f): ?>
            <option value="<?= $f['faculty_id'] ?>"><?= htmlspecialchars($f['faculty_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label>Department</label>
        <select id="add_department" name="department_id" required>
          <option value="">Select Department</option>
        </select>
      </div>
      <div><label>Building</label><input name="building_name" required></div>
      <div><label>Floor</label><input name="floor_no"></div>
      <div><label>Room Name</label><input name="room_name" required></div>
      <div><label>Room Code</label><input name="room_code" required></div>
      <div><label>Capacity</label><input name="capacity" type="number"></div>
      <div><label>Room Type</label>
        <select name="room_type" required>
          <option value="">Select Type</option>
          <option value="Lecture">Lecture</option>
          <option value="Lab">Lab</option>
          <option value="Seminar">Seminar</option>
          <option value="Office">Office</option>
          <option value="Online">Online</option>
        </select>
      </div>
      <div><label>Description</label><textarea name="description"></textarea></div>
      <button class="save-btn" type="submit">Save</button>
    </form>
  </div>
</div>

<!-- EDIT MODAL -->
<div class="modal" id="editModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('editModal')">&times;</span>
    <h2>Edit Room</h2>
    <form method="POST">
      <input type="hidden" name="action" value="update">
      <input type="hidden" id="edit_id" name="room_id">
      <div><label>Faculty</label>
        <select id="edit_faculty" name="faculty_id" required onchange="updateDepartments('edit')">
          <option value="">Select Faculty</option>
          <?php foreach($faculties as $f): ?>
            <option value="<?= $f['faculty_id'] ?>"><?= htmlspecialchars($f['faculty_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label>Department</label>
        <select id="edit_department" name="department_id" required>
          <option value="">Select Department</option>
        </select>
      </div>
      <div><label>Building</label><input id="edit_building" name="building_name" required></div>
      <div><label>Floor</label><input id="edit_floor" name="floor_no"></div>
      <div><label>Room Name</label><input id="edit_roomname" name="room_name" required></div>
      <div><label>Room Code</label><input id="edit_code" name="room_code" required></div>
      <div><label>Capacity</label><input id="edit_capacity" name="capacity" type="number"></div>
      <div><label>Room Type</label>
        <select id="edit_type" name="room_type" required>
          <option value="Lecture">Lecture</option>
          <option value="Lab">Lab</option>
          <option value="Seminar">Seminar</option>
          <option value="Office">Office</option>
          <option value="Online">Online</option>
        </select>
      </div>
      <div><label>Description</label><textarea id="edit_desc" name="description"></textarea></div>
      <div><label>Status</label>
        <select id="edit_status" name="status">
          <option value="available">Available</option>
          <option value="maintenance">Maintenance</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
      <button class="save-btn" type="submit">Update</button>
    </form>
  </div>
</div>

<!-- DELETE CONFIRMATION MODAL -->
<div class="modal" id="deleteModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('deleteModal')">&times;</span>
    <h2>Confirm Deletion</h2>
    <p>Are you sure you want to delete this room? This action cannot be undone.</p>
    <form method="POST" id="deleteForm">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="room_id" id="delete_id">
      <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
        <button type="button" class="filter-btn" onclick="closeModal('deleteModal')" style="background:#777;">Cancel</button>
        <button type="submit" class="del-btn" style="padding:10px 18px;">Delete</button>
      </div>
    </form>
  </div>
</div>

<!-- ✅ ALERT POPUP -->
<div id="popup" class="alert-popup <?= $type ?> <?= !empty($message)?'show':'' ?>">
  <h3><?= $message ?></h3>
</div>

<script>
// Departments data from PHP
const departments = <?= json_encode($departments) ?>;

function openModal(id){
  document.getElementById(id).classList.add('show');
  document.body.style.overflow='hidden';
}

function closeModal(id){
  document.getElementById(id).classList.remove('show');
  document.body.style.overflow='auto';
}

// ✅ Update departments based on selected faculty
function updateDepartments(formType) {
  const facultySelect = document.getElementById(`${formType}_faculty`);
  const departmentSelect = document.getElementById(`${formType}_department`);
  const facultyId = facultySelect.value;
  
  // Clear current options
  departmentSelect.innerHTML = '<option value="">Select Department</option>';
  
  if (facultyId) {
    // Filter departments by faculty
    const filteredDepts = departments.filter(dept => dept.faculty_id == facultyId);
    
    // Add filtered departments to select
    filteredDepts.forEach(dept => {
      const option = document.createElement('option');
      option.value = dept.department_id;
      option.textContent = dept.department_name;
      departmentSelect.appendChild(option);
    });
  }
}

// ✅ Edit Modal Prefill
function openEditModal(id, faculty, dept, building, floor, name, code, capacity, type, desc, status) {
  openModal('editModal');
  document.getElementById('edit_id').value = id;
  document.getElementById('edit_faculty').value = faculty;
  document.getElementById('edit_building').value = building;
  document.getElementById('edit_floor').value = floor;
  document.getElementById('edit_roomname').value = name;
  document.getElementById('edit_code').value = code;
  document.getElementById('edit_capacity').value = capacity;
  document.getElementById('edit_type').value = type;
  document.getElementById('edit_desc').value = desc;
  document.getElementById('edit_status').value = status;
  
  // Update departments for the selected faculty
  updateDepartments('edit');
  
  // Set the department after departments are loaded
  setTimeout(() => {
    document.getElementById('edit_department').value = dept;
  }, 100);
}

// ✅ Delete Room
function deleteRoom(roomId) {
  document.getElementById('delete_id').value = roomId;
  openModal('deleteModal');
}

// ✅ Filter Rooms
function filterRooms() {
  const facultyFilter = document.getElementById('filter_faculty').value;
  const typeFilter = document.getElementById('filter_type').value;
  const statusFilter = document.getElementById('filter_status').value;
  
  const rows = document.querySelectorAll('#roomsTable tbody tr');
  
  rows.forEach(row => {
    const faculty = row.getAttribute('data-faculty');
    const type = row.getAttribute('data-type');
    const status = row.getAttribute('data-status');
    
    let show = true;
    
    if (facultyFilter && faculty !== facultyFilter) show = false;
    if (typeFilter && type !== typeFilter) show = false;
    if (statusFilter && status !== statusFilter) show = false;
    
    row.style.display = show ? '' : 'none';
  });
}

// ✅ Reset Filters
function resetFilters() {
  document.getElementById('filter_faculty').value = '';
  document.getElementById('filter_type').value = '';
  document.getElementById('filter_status').value = '';
  
  const rows = document.querySelectorAll('#roomsTable tbody tr');
  rows.forEach(row => {
    row.style.display = '';
  });
}
</script>

<?php if (!empty($message)): ?>
<script>
const p = document.getElementById('popup');
if(p){
  p.classList.add('show');
  setTimeout(()=>p.classList.remove('show'),3500);
}
</script>
<?php endif; ?>

<script src="../assets/js/sidebar.js"></script>
<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>