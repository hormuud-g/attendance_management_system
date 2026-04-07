<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Access Control - Changed to faculty_admin
$role = strtolower($_SESSION['user']['role'] ?? '');
if ($role !== 'faculty_admin') {
  header("Location: ../login.php");
  exit;
}

// Get faculty_id from session
$faculty_id = $_SESSION['user']['linked_id'] ?? null;
if (!$faculty_id) {
  header("Location: ../login.php");
  exit;
}

$message = "";
$type = "";

/* ===========================================
   HELPER: Generate Teacher UUID
=========================================== */
function generate_teacher_uuid($pdo) {
  $last = $pdo->query("SELECT teacher_uuid FROM teachers ORDER BY teacher_id DESC LIMIT 1")->fetchColumn();
  $num = 1;
  if ($last && preg_match('/^HU(\d{7,})$/', $last, $m)) $num = $m[1] + 1;
  return 'HU' . str_pad($num, 7, '0', STR_PAD_LEFT);
}

/* ===========================================
   CRUD OPERATIONS
=========================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // 🟢 ADD TEACHER
  if ($_POST['action'] === 'add') {
    try {
      $email = trim($_POST['email']);
      $check = $pdo->prepare("SELECT COUNT(*) FROM teachers WHERE email=?");
      $check->execute([$email]);
      if ($check->fetchColumn() > 0) throw new Exception("Email already exists!");

      $photo_path = null;
      if (!empty($_FILES['profile_photo']['name'])) {
        $upload_dir = __DIR__ . '/../upload/profiles/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        $ext = pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
        $new_name = uniqid('teacher_') . '.' . strtolower($ext);
        $photo_path = 'upload/profiles/' . $new_name;
        move_uploaded_file($_FILES['profile_photo']['tmp_name'], __DIR__ . '/../' . $photo_path);
      }

      $uuid = generate_teacher_uuid($pdo);
      $stmt = $pdo->prepare("INSERT INTO teachers 
        (teacher_uuid, teacher_name, email, phone_number, gender, qualification, position_title, profile_photo_path, status, created_at)
        VALUES (?,?,?,?,?,?,?,?, 'active', NOW())");
      $stmt->execute([
        $uuid, $_POST['teacher_name'], $email,
        $_POST['phone_number'] ?? null, $_POST['gender'] ?? null,
        $_POST['qualification'] ?? null, $_POST['position_title'] ?? null,
        $photo_path
      ]);

      $teacher_id = $pdo->lastInsertId();
      $plain_pass = "123"; // Password caadi ah
      $hashed = password_hash($plain_pass, PASSWORD_BCRYPT);

      // ✅ Insert user with both hashed and plain password
      $user = $pdo->prepare("INSERT INTO users 
        (user_uuid, username, email, phone_number, profile_photo_path, password, password_plain, role, linked_id, linked_table, status, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?, NOW())");
      $user->execute([
        $uuid,                      // 1 user_uuid
        $_POST['teacher_name'],     // 2 username
        $email,                     // 3 email
        $_POST['phone_number'],     // 4 phone_number
        $photo_path,                // 5 profile_photo_path
        $hashed,                    // 6 password
        $plain_pass,                // 7 password_plain
        'teacher',                  // 8 role
        $teacher_id,                // 9 linked_id
        'teachers',                 // 10 linked_table
        'active'                    // 11 status
      ]);

      $message = "✅ Teacher added successfully! Default password: 123";
      $type = "success";
    } catch (Exception $e) {
      $message = "❌ " . $e->getMessage();
      $type = "error";
    }
  }

  // 🟡 UPDATE TEACHER
  if ($_POST['action'] === 'update') {
    try {
      $id = $_POST['teacher_id'];
      
      // Verify teacher exists (basic check since no faculty assignment table)
      $verify_stmt = $pdo->prepare("SELECT teacher_id FROM teachers WHERE teacher_id = ?");
      $verify_stmt->execute([$id]);
      
      if (!$verify_stmt->fetch()) {
        throw new Exception("Teacher not found.");
      }

      $params = [
        $_POST['teacher_name'], $_POST['email'], $_POST['phone_number'],
        $_POST['gender'], $_POST['qualification'], $_POST['position_title'],
        $_POST['status'], $id
      ];

      $photo_sql = "";
      if (!empty($_FILES['profile_photo']['name'])) {
        $upload_dir = __DIR__ . '/../upload/profiles/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        $ext = pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
        $new_name = uniqid('teacher_') . '.' . strtolower($ext);
        $photo_path = 'upload/profiles/' . $new_name;
        move_uploaded_file($_FILES['profile_photo']['tmp_name'], __DIR__ . '/../' . $photo_path);
        $photo_sql = ", profile_photo_path=?";
        array_splice($params, 7, 0, $photo_path);
      }

      $pdo->prepare("UPDATE teachers 
        SET teacher_name=?, email=?, phone_number=?, gender=?, qualification=?, position_title=?, status=? $photo_sql
        WHERE teacher_id=?")->execute($params);

      $pdo->prepare("UPDATE users SET username=?, email=?, phone_number=? WHERE linked_id=? AND linked_table='teachers'")
        ->execute([$_POST['teacher_name'], $_POST['email'], $_POST['phone_number'], $id]);

      $message = "✅ Teacher updated successfully!";
      $type = "success";
    } catch (Exception $e) {
      $message = "❌ " . $e->getMessage();
      $type = "error";
    }
  }

  // 🔴 DELETE TEACHER
  if ($_POST['action'] === 'delete') {
    try {
      $id = $_POST['teacher_id'];
      
      // Verify teacher exists
      $verify_stmt = $pdo->prepare("SELECT teacher_id FROM teachers WHERE teacher_id = ?");
      $verify_stmt->execute([$id]);
      
      if (!$verify_stmt->fetch()) {
        throw new Exception("Teacher not found.");
      }

      // Check if teacher has timetable entries
      $timetable_check = $pdo->prepare("SELECT COUNT(*) FROM timetable WHERE teacher_id = ?");
      $timetable_check->execute([$id]);
      if ($timetable_check->fetchColumn() > 0) {
        throw new Exception("Cannot delete teacher. Teacher has timetable assignments.");
      }

      // Check if teacher has attendance records
      $attendance_check = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE teacher_id = ?");
      $attendance_check->execute([$id]);
      if ($attendance_check->fetchColumn() > 0) {
        throw new Exception("Cannot delete teacher. Teacher has attendance records.");
      }

      // Delete user account first
      $pdo->prepare("DELETE FROM users WHERE linked_id = ? AND linked_table = 'teachers'")->execute([$id]);
      
      // Delete teacher
      $pdo->prepare("DELETE FROM teachers WHERE teacher_id = ?")->execute([$id]);

      $message = "✅ Teacher deleted successfully!";
      $type = "success";
    } catch (Exception $e) {
      $message = "❌ " . $e->getMessage();
      $type = "error";
    }
  }
}

/* ===========================================
   FETCH ALL TEACHERS (Since no faculty assignment table)
=========================================== */
$teachers = $pdo->query("SELECT * FROM teachers ORDER BY teacher_id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Teachers | Hormuud University</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="../images.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{--green:#00843D;--blue:#0072CE;--red:#C62828;--bg:#F5F9F7;}
body{font-family:'Poppins',sans-serif;background:var(--bg);margin:0;}
.main-content{padding:20px;margin-top:90px;margin-left:250px;transition:all .3s ease;}
.sidebar.collapsed ~ .main-content {
  margin-left: 70px;
}
.add-btn{background:var(--green);color:#fff;padding:8px 14px;border:none;border-radius:6px;font-weight:600;cursor:pointer;}
.add-btn:hover{background:#00A651;}
.table-container{overflow-x:auto;margin-top:20px;border-radius:10px;background:#fff;box-shadow:0 4px 12px rgba(0,0,0,0.08);}
table{width:100%;min-width:1000px;border-collapse:collapse;}
th,td{padding:12px 14px;border-bottom:1px solid #eee;text-align:left;white-space:nowrap;}
thead th{background:var(--blue);color:#fff;position:sticky;top:0;}
tr:hover{background:#eef8f0;}
.action-buttons{display:flex;justify-content:center;gap:6px;}
.btn-edit{background:#0072CE;color:white;padding:8px 10px;border:none;border-radius:6px;cursor:pointer;}
.btn-delete{background:#C62828;color:white;padding:8px 10px;border:none;border-radius:6px;cursor:pointer;}
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);justify-content:center;align-items:center;z-index:3000;}
.modal.show{display:flex;}
.modal-content{background:#fff;border-radius:10px;width:90%;max-width:700px;padding:25px;position:relative;}
.close-modal{position:absolute;top:10px;right:15px;font-size:22px;cursor:pointer;}
form{display:grid;grid-template-columns:repeat(2,1fr);gap:15px;}
label{font-weight:600;color:#0072CE;}
input,select{width:100%;padding:10px;border:1.5px solid #ccc;border-radius:6px;}
.save-btn{grid-column:span 2;background:var(--green);color:#fff;border:none;padding:10px;border-radius:6px;font-weight:600;cursor:pointer;}
.alert-popup{display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;padding:20px 25px;border-radius:12px;text-align:center;box-shadow:0 4px 14px rgba(0,0,0,0.2);z-index:5000;width:280px;}
.alert-popup.show{display:block;}
.alert-popup.success{border-top:5px solid var(--green);}
.alert-popup.error{border-top:5px solid var(--red);}
.alert-popup h3{font-size:14px;color:#333;margin-bottom:15px;}
.alert-btn{background:var(--blue);color:white;padding:6px 16px;border:none;border-radius:5px;cursor:pointer;font-weight:600;}
.alert-btn:hover{background:#005bb5;}
img.photo{width:45px;height:45px;border-radius:50%;object-fit:cover;}
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
  <div style="display:flex;justify-content:space-between;align-items:center;">
    <h1 style="color:var(--blue)">Teachers Management</h1>
    <button class="add-btn" onclick="openModal('addModal')">+ Add Teacher</button>
  </div>

  <!-- ✅ Scrollable Table -->
  <div class="table-container">
    <table>
      <thead>
        <tr>
          <th>#</th><th>Photo</th><th>Name</th><th>Email</th><th>Phone</th><th>Gender</th><th>Qualification</th><th>Position</th><th>Status</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if($teachers): foreach($teachers as $i=>$t): ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><img src="../<?= $t['profile_photo_path'] ?: 'assets/img/default.png' ?>" class="photo"></td>
          <td><?= htmlspecialchars($t['teacher_name']) ?></td>
          <td><?= htmlspecialchars($t['email']) ?></td>
          <td><?= htmlspecialchars($t['phone_number']) ?></td>
          <td><?= htmlspecialchars($t['gender']) ?></td>
          <td><?= htmlspecialchars($t['qualification']) ?></td>
          <td><?= htmlspecialchars($t['position_title']) ?></td>
          <td style="color:<?= $t['status']=='active'?'green':'red' ?>;font-weight:600;"><?= ucfirst($t['status']) ?></td>
          <td>
            <div class="action-buttons">
              <button class="btn-edit" onclick="openEditModal(<?= $t['teacher_id'] ?>,'<?= htmlspecialchars($t['teacher_name'],ENT_QUOTES) ?>','<?= htmlspecialchars($t['email'],ENT_QUOTES) ?>','<?= htmlspecialchars($t['phone_number'],ENT_QUOTES) ?>','<?= htmlspecialchars($t['gender'],ENT_QUOTES) ?>','<?= htmlspecialchars($t['qualification'],ENT_QUOTES) ?>','<?= htmlspecialchars($t['position_title'],ENT_QUOTES) ?>','<?= $t['status'] ?>')"><i class='fa-solid fa-pen-to-square'></i></button>
              <button class="btn-delete" onclick="openDeleteModal(<?= $t['teacher_id'] ?>)"><i class='fa-solid fa-trash'></i></button>
            </div>
          </td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="10" style="text-align:center;color:#777;">No teachers found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ✅ ADD MODAL -->
<div class="modal" id="addModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('addModal')">&times;</span>
    <h2>Add Teacher</h2>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="add">
      <div><label>Name *</label><input type="text" name="teacher_name" required></div>
      <div><label>Email *</label><input type="email" name="email" required></div>
      <div><label>Phone</label><input type="text" name="phone_number"></div>
      <div><label>Gender</label><select name="gender"><option value="">Select Gender</option><option value="Male">Male</option><option value="Female">Female</option></select></div>
      <div><label>Qualification</label><input type="text" name="qualification"></div>
      <div><label>Position</label><input type="text" name="position_title"></div>
      <div><label>Photo</label><input type="file" name="profile_photo" accept="image/*"></div>
      <button class="save-btn" type="submit">Save Teacher</button>
    </form>
  </div>
</div>

<!-- ✅ EDIT MODAL -->
<div class="modal" id="editModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('editModal')">&times;</span>
    <h2>Edit Teacher</h2>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="update">
      <input type="hidden" id="edit_id" name="teacher_id">
      <div><label>Name *</label><input id="edit_name" name="teacher_name" required></div>
      <div><label>Email *</label><input id="edit_email" type="email" name="email" required></div>
      <div><label>Phone</label><input id="edit_phone" name="phone_number"></div>
      <div><label>Gender</label><select id="edit_gender" name="gender"><option value="">Select Gender</option><option value="Male">Male</option><option value="Female">Female</option></select></div>
      <div><label>Qualification</label><input id="edit_qual" name="qualification"></div>
      <div><label>Position</label><input id="edit_pos" name="position_title"></div>
      <div><label>Status</label><select id="edit_status" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
      <div><label>New Photo</label><input type="file" name="profile_photo" accept="image/*"></div>
      <button class="save-btn" type="submit">Update Teacher</button>
    </form>
  </div>
</div>

<!-- ✅ DELETE MODAL -->
<div class="modal" id="deleteModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('deleteModal')">&times;</span>
    <h2 style="color:#C62828">Confirm Delete</h2>
    <form method="POST">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" id="delete_id" name="teacher_id">
      <p>Are you sure you want to delete this teacher?</p>
      <button class="save-btn" style="background:#C62828" type="submit">Yes, Delete</button>
    </form>
  </div>
</div>

<!-- ✅ ALERT POPUP -->
<div id="popup" class="alert-popup <?= $type ?>"><h3><?= htmlspecialchars($message) ?></h3><button class="alert-btn" onclick="closeAlert()">OK</button></div>

<script>
function openModal(id){document.getElementById(id).classList.add('show');}
function closeModal(id){document.getElementById(id).classList.remove('show');}
function openEditModal(id,name,email,phone,gender,qual,pos,status){
  openModal('editModal');
  document.getElementById('edit_id').value=id; 
  document.getElementById('edit_name').value=name; 
  document.getElementById('edit_email').value=email;
  document.getElementById('edit_phone').value=phone; 
  document.getElementById('edit_gender').value=gender;
  document.getElementById('edit_qual').value=qual; 
  document.getElementById('edit_pos').value=pos; 
  document.getElementById('edit_status').value=status;
}
function openDeleteModal(id){openModal('deleteModal');document.getElementById('delete_id').value=id;}
function closeAlert(){document.getElementById('popup').classList.remove('show');}
if("<?= $message ?>"){document.getElementById('popup').classList.add('show');}
</script>

<script src="../assets/js/sidebar.js"></script>
<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>