<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Check login
if (empty($_SESSION['user'])) {
  header("Location: ../login.php");
  exit;
}

// ✅ Access control - Allow both faculty_admin and department_admin
$user_role = strtolower($_SESSION['user']['role'] ?? '');
if (!in_array($user_role, ['faculty_admin', 'department_admin'])) {
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

// ✅ VERIFY DATA EXISTS IN DATABASE
$valid_faculty = false;
$valid_department = false;

if ($faculty_id) {
    $check_faculty = $pdo->prepare("SELECT COUNT(*) FROM faculties WHERE faculty_id = ?");
    $check_faculty->execute([$faculty_id]);
    $valid_faculty = $check_faculty->fetchColumn() > 0;
}

if ($department_id) {
    $check_department = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE department_id = ? AND faculty_id = ?");
    $check_department->execute([$department_id, $faculty_id]);
    $valid_department = $check_department->fetchColumn() > 0;
}

$currentPage = basename($_SERVER['PHP_SELF']);
$message = "";
$type = "";

/* =========================================================
   CRUD OPERATIONS
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  // 🟢 ADD ROOM - For both faculty_admin and department_admin
  if ($action === 'add') {
    try {
      // ✅ Validate required data
      if (!$valid_faculty) {
        throw new Exception("Invalid faculty access!");
      }
      
      if ($role === 'department_admin' && !$valid_department) {
        throw new Exception("Invalid department access!");
      }
      
      if ($role === 'faculty_admin' && empty($_POST['department_id'])) {
        throw new Exception("Please select a department!");
      }

      $code = trim($_POST['room_code']);
      
      // Check if room code already exists in this faculty
      $check = $pdo->prepare("SELECT COUNT(*) FROM rooms WHERE room_code=? AND faculty_id=?");
      $check->execute([$code, $faculty_id]);

      if ($check->fetchColumn() > 0) {
        $message = "⚠️ Room code already exists in this faculty!";
        $type = "error";
      } else {
        // For department_admin, use their department_id automatically
        $dept_id = ($role === 'department_admin') ? $department_id : $_POST['department_id'];
        
        // ✅ Verify department belongs to faculty
        if ($role === 'faculty_admin') {
          $check_dept = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE department_id = ? AND faculty_id = ?");
          $check_dept->execute([$dept_id, $faculty_id]);
          if ($check_dept->fetchColumn() === 0) {
            throw new Exception("Selected department does not belong to your faculty!");
          }
        }
        
        $stmt = $pdo->prepare("INSERT INTO rooms 
          (faculty_id, department_id, building_name, floor_no, room_name, room_code, capacity, room_type, description, status)
          VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
          $faculty_id,
          $dept_id,
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
      $message = "❌ Database Error: " . $e->getMessage();
      $type = "error";
    } catch (Exception $e) {
      $message = "❌ " . $e->getMessage();
      $type = "error";
    }
  }

  // 🟡 UPDATE ROOM - Both can update
  if ($action === 'update') {
    try {
      // ✅ Validate required data
      if (!$valid_faculty) {
        throw new Exception("Invalid faculty access!");
      }
      
      if ($role === 'department_admin' && !$valid_department) {
        throw new Exception("Invalid department access!");
      }

      // Build query based on role
      if ($role === 'faculty_admin') {
        // Verify department belongs to faculty
        $check_dept = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE department_id = ? AND faculty_id = ?");
        $check_dept->execute([$_POST['department_id'], $faculty_id]);
        if ($check_dept->fetchColumn() === 0) {
          throw new Exception("Selected department does not belong to your faculty!");
        }
        
        $stmt = $pdo->prepare("UPDATE rooms 
          SET department_id=?, building_name=?, floor_no=?, room_name=?, room_code=?, capacity=?, room_type=?, description=?, status=? 
          WHERE room_id=? AND faculty_id=?");
        $params = [
          $_POST['department_id'],
          $_POST['building_name'],
          $_POST['floor_no'],
          $_POST['room_name'],
          $_POST['room_code'],
          $_POST['capacity'],
          $_POST['room_type'],
          $_POST['description'],
          $_POST['status'],
          $_POST['room_id'],
          $faculty_id
        ];
      } else { // department_admin
        $stmt = $pdo->prepare("UPDATE rooms 
          SET building_name=?, floor_no=?, room_name=?, room_code=?, capacity=?, room_type=?, description=?, status=? 
          WHERE room_id=? AND department_id=?");
        $params = [
          $_POST['building_name'],
          $_POST['floor_no'],
          $_POST['room_name'],
          $_POST['room_code'],
          $_POST['capacity'],
          $_POST['room_type'],
          $_POST['description'],
          $_POST['status'],
          $_POST['room_id'],
          $department_id
        ];
      }

      $stmt->execute($params);
      
      if ($stmt->rowCount() > 0) {
        $message = "✅ Room updated successfully!";
        $type = "success";
      } else {
        $message = "⚠️ No changes made or room not found!";
        $type = "error";
      }
    } catch (PDOException $e) {
      $message = "❌ Database Error: " . $e->getMessage();
      $type = "error";
    } catch (Exception $e) {
      $message = "❌ " . $e->getMessage();
      $type = "error";
    }
  }
}

/* =========================================================
   FETCH RELATED DATA
========================================================= */

// ✅ Faculty Info
if ($faculty_id && $valid_faculty) {
    $stmt = $pdo->prepare("SELECT faculty_id, faculty_name FROM faculties WHERE faculty_id = ?");
    $stmt->execute([$faculty_id]);
    $faculties = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $faculties = [];
}

// ✅ Departments - Different query based on role
if ($role === 'faculty_admin' && $valid_faculty) {
    // Faculty admin sees all departments in their faculty
    $stmt = $pdo->prepare("SELECT department_id, department_name FROM departments WHERE faculty_id = ?");
    $stmt->execute([$faculty_id]);
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($role === 'department_admin' && $valid_department) {
    // Department admin only sees their own department
    $stmt = $pdo->prepare("SELECT department_id, department_name FROM departments WHERE department_id = ?");
    $stmt->execute([$department_id]);
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $departments = [];
}

// ✅ Fetch rooms - Different query based on role
if ($role === 'faculty_admin' && $valid_faculty) {
    // Faculty admin sees all rooms in their faculty
    $stmt = $pdo->prepare("SELECT r.*, f.faculty_name, d.department_name
      FROM rooms r
      LEFT JOIN faculties f ON r.faculty_id = f.faculty_id
      LEFT JOIN departments d ON r.department_id = d.department_id
      WHERE r.faculty_id = ?
      ORDER BY r.room_id DESC");
    $stmt->execute([$faculty_id]);
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($role === 'department_admin' && $valid_department) {
    // Department admin only sees rooms in their department
    $stmt = $pdo->prepare("SELECT r.*, f.faculty_name, d.department_name
      FROM rooms r
      LEFT JOIN faculties f ON r.faculty_id = f.faculty_id
      LEFT JOIN departments d ON r.department_id = d.department_id
      WHERE r.department_id = ?
      ORDER BY r.room_id DESC");
    $stmt->execute([$department_id]);
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $rooms = [];
}

// ✅ Show error if data is invalid
if (!$valid_faculty) {
    $message = "❌ Invalid faculty access! Please contact administrator.";
    $type = "error";
} elseif ($role === 'department_admin' && !$valid_department) {
    $message = "❌ Invalid department access! Please contact administrator.";
    $type = "error";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Room Management | Hormuud University</title>
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
.add-btn:disabled{background:#ccc;cursor:not-allowed;}
.table-wrapper{overflow:auto;max-height:500px;border-radius:10px;}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;box-shadow:0 4px 12px rgba(0,0,0,0.08);}
thead th{position:sticky;top:0;background:var(--blue);color:#fff;z-index:2;}
th,td{padding:12px 14px;border-bottom:1px solid #eee;text-align:left;white-space:nowrap;}
tr:hover{background:#eef8f0;}
.action-btns{display:flex;justify-content:center;gap:8px;}
.edit-btn,.del-btn{border:none;border-radius:6px;padding:8px 10px;color:#fff;cursor:pointer;transition:.3s;}
.edit-btn{background:var(--blue);} .edit-btn:hover{background:#2196f3;}
.edit-btn:disabled{background:#ccc;cursor:not-allowed;}
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
.save-btn:disabled{background:#ccc;cursor:not-allowed;}
.alert-popup{display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:10px;padding:16px 24px;text-align:center;z-index:4000;box-shadow:0 6px 20px rgba(0,0,0,.25);}
.alert-popup.show{display:block;}
.alert-popup.success{border-top:5px solid var(--green);} .alert-popup.error{border-top:5px solid var(--red);}
.department-info{background:#e8f5e9;padding:10px;border-radius:6px;margin-bottom:15px;border-left:4px solid var(--green);}
.error-access{background:#ffebee;padding:15px;border-radius:8px;border-left:4px solid var(--red);margin-bottom:20px;}
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
  <div class="page-header">
    <h1>Room Management 
      <?php if($role === 'department_admin' && !empty($departments)): ?>
        - <?= htmlspecialchars($departments[0]['department_name']) ?>
      <?php endif; ?>
    </h1>
    <button class="add-btn" onclick="openModal('addModal')" <?= (!$valid_faculty || ($role === 'department_admin' && !$valid_department)) ? 'disabled' : '' ?>>+ Add Room</button>
  </div>

  <?php if (!$valid_faculty || ($role === 'department_admin' && !$valid_department)): ?>
  <div class="error-access">
    <h3>⚠️ Access Error</h3>
    <p>Your account is not properly linked to a valid faculty/department. Please contact system administrator.</p>
  </div>
  <?php endif; ?>

  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>#</th><th>Building</th><th>Floor</th><th>Room Name</th><th>Code</th>
          <th>Capacity</th><th>Type</th>
          <?php if($role === 'faculty_admin'): ?>
            <th>Faculty</th><th>Department</th>
          <?php endif; ?>
          <th>Status</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if($rooms): foreach($rooms as $i=>$r): ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><?= htmlspecialchars($r['building_name']) ?></td>
          <td><?= htmlspecialchars($r['floor_no']) ?></td>
          <td><?= htmlspecialchars($r['room_name']) ?></td>
          <td><?= htmlspecialchars($r['room_code']) ?></td>
          <td><?= htmlspecialchars($r['capacity']) ?></td>
          <td><?= htmlspecialchars($r['room_type']) ?></td>
          <?php if($role === 'faculty_admin'): ?>
            <td><?= htmlspecialchars($r['faculty_name']) ?></td>
            <td><?= htmlspecialchars($r['department_name']) ?></td>
          <?php endif; ?>
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
            )" <?= (!$valid_faculty || ($role === 'department_admin' && !$valid_department)) ? 'disabled' : '' ?>>
              <i class="fa-solid fa-pen-to-square"></i>
            </button>
          </td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="<?= $role === 'faculty_admin' ? '11' : '9' ?>" style="text-align:center;color:#777;">
          <?= ($valid_faculty && ($role !== 'department_admin' || $valid_department)) ? 'No rooms found.' : 'Cannot load rooms due to access issues.' ?>
        </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ADD MODAL - For both faculty_admin and department_admin -->
<div class="modal" id="addModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('addModal')">&times;</span>
    <h2>Add Room</h2>
    
    <?php if($role === 'department_admin' && !empty($departments)): ?>
    <!-- <div class="department-info">
      <strong>Department:</strong> <?= htmlspecialchars($departments[0]['department_name']) ?>
    </div> -->
    <?php endif; ?>
    
    <form method="POST">
      <input type="hidden" name="action" value="add">
      
      <?php if($role === 'faculty_admin'): ?>
        <div><label>Department</label>
          <select id="add_department" name="department_id" required <?= empty($departments) ? 'disabled' : '' ?>>
            <option value="">Select Department</option>
            <?php foreach($departments as $d): ?>
              <option value="<?= $d['department_id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if(empty($departments)): ?>
            <small style="color:var(--red);">No departments available in your faculty</small>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <input type="hidden" name="department_id" value="<?= $department_id ?>">
      <?php endif; ?>
      
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
      <button class="save-btn" type="submit" <?= (!$valid_faculty || ($role === 'faculty_admin' && empty($departments)) || ($role === 'department_admin' && !$valid_department)) ? 'disabled' : '' ?>>Save</button>
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
      
      <?php if($role === 'faculty_admin'): ?>
        <div><label>Department</label>
          <select id="edit_department" name="department_id" required <?= empty($departments) ? 'disabled' : '' ?>>
            <option value="">Select Department</option>
            <?php foreach($departments as $d): ?>
              <option value="<?= $d['department_id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php else: ?>
        <input type="hidden" name="department_id" value="<?= $department_id ?>">
      <?php endif; ?>
      
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
      <button class="save-btn" type="submit" <?= (!$valid_faculty || ($role === 'faculty_admin' && empty($departments)) || ($role === 'department_admin' && !$valid_department)) ? 'disabled' : '' ?>>Update</button>
    </form>
  </div>
</div>

<!-- ✅ ALERT POPUP -->
<div id="popup" class="alert-popup <?= $type ?> <?= !empty($message)?'show':'' ?>">
  <h3><?= $message ?></h3>
</div>

<script>
function openModal(id){
  document.getElementById(id).classList.add('show');
  document.body.style.overflow='hidden';
}
function closeModal(id){
  document.getElementById(id).classList.remove('show');
  document.body.style.overflow='auto';
}

// ✅ Edit Modal Prefill
function openEditModal(id, faculty, dept, building, floor, name, code, capacity, type, desc, status) {
  openModal('editModal');
  document.getElementById('edit_id').value = id;
  <?php if($role === 'faculty_admin'): ?>
    if (document.getElementById('edit_department')) {
      document.getElementById('edit_department').value = dept;
    }
  <?php endif; ?>
  document.getElementById('edit_building').value = building;
  document.getElementById('edit_floor').value = floor;
  document.getElementById('edit_roomname').value = name;
  document.getElementById('edit_code').value = code;
  document.getElementById('edit_capacity').value = capacity;
  document.getElementById('edit_type').value = type;
  document.getElementById('edit_desc').value = desc;
  document.getElementById('edit_status').value = status;
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