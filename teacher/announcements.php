<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Access Control
if (!isset($_SESSION['user'])) {
  header("Location: ../login.php");
  exit;
}

$message = "";
$type = "";

/* ===========================================
   CRUD OPERATIONS
=========================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  try {
    $pdo->beginTransaction();

    // 🟢 ADD
    if ($_POST['action'] === 'add') {
      $photo = null;
      if (!empty($_FILES['image']['name'])) {
        $dir = __DIR__ . '/../upload/announcements/';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $name = uniqid('ann_') . ".$ext";
        $photo = "upload/announcements/$name";
        move_uploaded_file($_FILES['image']['tmp_name'], __DIR__ . '/../' . $photo);
      }

      $stmt = $pdo->prepare("INSERT INTO announcement (title, message, image_path, target_role, status, created_by, created_at)
        VALUES (?,?,?,?,?,?,NOW())");
      $stmt->execute([
        $_POST['title'],
        $_POST['message'],
        $photo,
        $_POST['target_role'],
        $_POST['status'],
        $_SESSION['user']['user_id']
      ]);

      $message = "✅ Announcement added successfully!";
      $type = "success";
    }

    // ✏️ UPDATE
    if ($_POST['action'] === 'update') {
      $id = $_POST['announcement_id'];
      $photo = $_POST['existing_image'] ?? null;
      if (!empty($_FILES['image']['name'])) {
        $dir = __DIR__ . '/../upload/announcements/';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $name = uniqid('ann_') . ".$ext";
        $photo = "upload/announcements/$name";
        move_uploaded_file($_FILES['image']['tmp_name'], __DIR__ . '/../' . $photo);
      }

      $stmt = $pdo->prepare("UPDATE announcement 
        SET title=?, message=?, image_path=?, target_role=?, status=?, updated_at=NOW() 
        WHERE announcement_id=?");
      $stmt->execute([
        $_POST['title'],
        $_POST['message'],
        $photo,
        $_POST['target_role'],
        $_POST['status'],
        $id
      ]);

      $message = "✏️ Announcement updated successfully!";
      $type = "success";
    }

    // 🔴 DELETE
    if ($_POST['action'] === 'delete') {
      $pdo->prepare("DELETE FROM announcement WHERE announcement_id=?")->execute([$_POST['announcement_id']]);
      $message = "🗑️ Announcement deleted successfully!";
      $type = "success";
    }

    $pdo->commit();
  } catch (Exception $e) {
    $pdo->rollBack();
    $message = "❌ " . $e->getMessage();
    $type = "error";
  }
}

/* ===========================================
   SEARCH + FILTER
=========================================== */
$where = [];
$params = [];

if (!empty($_GET['search'])) {
  $where[] = "(a.title LIKE ? OR a.message LIKE ?)";
  $params[] = "%{$_GET['search']}%";
  $params[] = "%{$_GET['search']}%";
}

if (!empty($_GET['role'])) {
  $where[] = "a.target_role = ?";
  $params[] = $_GET['role'];
}

if (!empty($_GET['status'])) {
  $where[] = "a.status = ?";
  $params[] = $_GET['status'];
}

$sql = "
  SELECT a.*, u.username AS created_by_name
  FROM announcement a
  LEFT JOIN users u ON u.user_id=a.created_by
";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY a.announcement_id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Announcements | Hormuud University</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="../images.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
  --green:#00843D;--blue:#0072CE;--red:#C62828;--bg:#F5F9F7;
}
body{font-family:'Poppins',sans-serif;background:var(--bg);margin:0;}
.main-content{padding:20px;margin-top:90px;margin-left:250px;transition:all .3s;}
.sidebar.collapsed ~ .main-content {
  margin-left: 70px;
}
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;}
.page-header h1{color:var(--blue);}
.add-btn{background:var(--green);color:#fff;padding:9px 16px;border:none;border-radius:6px;font-weight:600;cursor:pointer;}
.add-btn:hover{background:#00A651;}
/* ✅ FILTER BAR DESIGN */
.filter-bar{
  display:flex;
  flex-wrap:nowrap;
  align-items:center;
  gap:10px;
  background:#fff;
  padding:10px 15px;
  border-radius:10px;
  box-shadow:0 2px 6px rgba(0,0,0,0.08);
  margin-bottom:20px;
  overflow-x:auto;
  white-space:nowrap;
}

.filter-bar input[type="text"],
.filter-bar select{
  padding:8px 12px;
  border:1.5px solid #ccc;
  border-radius:6px;
  font-size:14px;
  min-width:160px;
  outline:none;
}

.filter-bar input:focus,
.filter-bar select:focus{
  border-color:var(--blue);
}

.filter-bar button,
.filter-bar a{
  border:none;
  border-radius:6px;
  padding:8px 14px;
  font-weight:600;
  cursor:pointer;
  text-decoration:none;
  white-space:nowrap;
}

.filter-bar button{
  background:var(--blue);
  color:#fff;
}

.filter-bar button:hover{background:#005bb5;}
.filter-bar a{background:#ccc;color:#333;}
.filter-bar a:hover{background:#bbb;}

@media(max-width:600px){
  .filter-bar{overflow-x:auto;}
  .filter-bar input, .filter-bar select{min-width:130px;}
}


/* ✅ TABLE */
.table-wrapper{background:#fff;border-radius:10px;box-shadow:0 4px 12px rgba(0,0,0,0.08);overflow:auto;max-height:500px;}
table{width:100%;border-collapse:collapse;}
thead th{background:var(--blue);color:#fff;position:sticky;top:0;font-size:14px;}
th,td{padding:10px 12px;border-bottom:1px solid #eee;white-space:nowrap;font-size:14px;}
tr:hover{background:#eef8f0;}
.action-buttons{display:flex;justify-content:center;gap:6px;}
.btn-edit{background:#0072CE;color:white;padding:6px 9px;border:none;border-radius:6px;}
.btn-delete{background:#C62828;color:white;padding:6px 9px;border:none;border-radius:6px;}
.btn-edit:hover{background:#2196f3;}
.btn-delete:hover{background:#e53935;}

/* ✅ MODAL + ALERT */
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);justify-content:center;align-items:center;z-index:3000;}
.modal.show{display:flex;}
.modal-content{background:#fff;border-radius:10px;width:90%;max-width:750px;padding:25px;position:relative;overflow-y:auto;max-height:90vh;}
.close-modal{position:absolute;top:10px;right:15px;font-size:22px;cursor:pointer;}
form{display:grid;grid-template-columns:repeat(2,1fr);gap:15px;}
label{font-weight:600;color:#0072CE;}
input,select,textarea{width:100%;padding:10px;border:1.5px solid #ccc;border-radius:6px;}
textarea{resize:none;height:60px;}
.save-btn{grid-column:span 2;background:var(--green);color:#fff;border:none;padding:10px;border-radius:6px;font-weight:600;cursor:pointer;}
.save-btn:hover{background:#00A651;}
.alert-popup{display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;padding:20px 25px;border-radius:12px;text-align:center;box-shadow:0 4px 14px rgba(0,0,0,0.2);z-index:5000;width:280px;}
.alert-popup.show{display:block;}
.alert-popup.success{border-top:5px solid var(--green);}
.alert-popup.error{border-top:5px solid var(--red);}
.alert-popup h3{font-size:14px;color:#333;margin-bottom:15px;}
.alert-btn{background:var(--blue);color:white;padding:6px 16px;border:none;border-radius:5px;cursor:pointer;font-weight:600;}
.alert-btn:hover{background:#005bb5;}
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
  <div class="page-header">
    <h1>Announcements</h1>
    <button class="add-btn" onclick="openModal('addModal')">+ Add Announcement</button>
  </div>

  <!-- ✅ FILTER BAR -->
  <form method="GET" class="filter-bar">
  <input type="text" name="search" placeholder="🔍 Search title or message..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
  <select name="role">
    <option value="">🎯 All Roles</option>
    <option value="student" <?= ($_GET['role']??'')==='student'?'selected':'' ?>>Student</option>
    <option value="teacher" <?= ($_GET['role']??'')==='teacher'?'selected':'' ?>>Teacher</option>
    <option value="parent" <?= ($_GET['role']??'')==='parent'?'selected':'' ?>>Parent</option>
    <option value="admin" <?= ($_GET['role']??'')==='admin'?'selected':'' ?>>Admin</option>
    <option value="all_users" <?= ($_GET['role']??'')==='all_users'?'selected':'' ?>>All Users</option>
  </select>
  <select name="status">
    <option value="">📊 All Status</option>
    <option value="active" <?= ($_GET['status']??'')==='active'?'selected':'' ?>>Active</option>
    <option value="inactive" <?= ($_GET['status']??'')==='inactive'?'selected':'' ?>>Inactive</option>
  </select>
  <button type="submit"><i class="fa fa-filter"></i> Filter</button>
  <a href="announcements.php"><i class="fa fa-rotate-right"></i> Reset</a>
</form>


  <!-- ✅ TABLE -->
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>#</th><th>Title</th><th>Message</th><th>Image</th><th>Target</th><th>Status</th><th>Created By</th><th>Date</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if($announcements): foreach($announcements as $i=>$a): ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><?= htmlspecialchars($a['title']) ?></td>
          <td><?= htmlspecialchars(substr($a['message'],0,50)) ?><?= strlen($a['message'])>50?'...':'' ?></td>
          <td><?php if($a['image_path']): ?><img src="../<?= htmlspecialchars($a['image_path']) ?>" width="50" height="40" style="object-fit:cover;border-radius:6px;"><?php else:?><span style="color:#aaa;">—</span><?php endif; ?></td>
          <td><?= ucfirst($a['target_role']) ?></td>
          <td style="color:<?= $a['status']=='active'?'green':'red' ?>;font-weight:600;"><?= ucfirst($a['status']) ?></td>
          <td><?= htmlspecialchars($a['created_by_name'] ?: '—') ?></td>
          <td><?= htmlspecialchars($a['created_at']) ?></td>
          <td>
            <div class="action-buttons">
              <button class="btn-edit" onclick='openEditModal(<?= json_encode($a) ?>)'><i class="fa-solid fa-pen-to-square"></i></button>
              <button class="btn-delete" onclick='openDeleteModal(<?= $a['announcement_id'] ?>)'><i class="fa-solid fa-trash"></i></button>
            </div>
          </td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="9" style="text-align:center;color:#777;">No announcements found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ✅ MODALS -->
<div class="modal" id="addModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('addModal')">&times;</span>
    <h2 id="formTitle">Add Announcement</h2>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" id="formAction" value="add">
      <input type="hidden" name="announcement_id" id="announcement_id">
      <input type="hidden" name="existing_image" id="existing_image">
      <div><label>Title</label><input type="text" name="title" id="title" required></div>
      <div><label>Target Role</label>
        <select name="target_role" id="target_role" required>
          <option value="">Select</option>
          <option value="student">Student</option>
          <option value="teacher">Teacher</option>
          <option value="parent">Parent</option>
          <option value="admin">Admin</option>
          <option value="all_users">All Users</option>
        </select>
      </div>
      <div style="grid-column:span 2;"><label>Message</label><textarea name="message" id="message" required></textarea></div>
      <div><label>Status</label><select name="status" id="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
      <div><label>Image</label><input type="file" name="image" id="image" accept="image/*"></div>
      <button class="save-btn">Save Announcement</button>
    </form>
  </div>
</div>

<div class="modal" id="deleteModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('deleteModal')">&times;</span>
    <h2 style="color:#C62828;">Confirm Delete</h2>
    <form method="POST">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" id="delete_id" name="announcement_id">
      <p>Are you sure you want to delete this announcement?</p>
      <button class="save-btn" style="background:#C62828;">Yes, Delete</button>
    </form>
  </div>
</div>

<div id="popup" class="alert-popup <?= $type ?>"><h3><?= $message ?></h3><button class="alert-btn" onclick="closeAlert()">OK</button></div>

<script>
function openModal(id){document.getElementById(id).classList.add('show');}
function closeModal(id){document.getElementById(id).classList.remove('show');}
function openEditModal(a){
  openModal('addModal');
  document.getElementById('formTitle').innerText='Edit Announcement';
  document.getElementById('formAction').value='update';
  announcement_id.value=a.announcement_id;
  title.value=a.title||'';
  message.value=a.message||'';
  target_role.value=a.target_role||'';
  status.value=a.status||'active';
  existing_image.value=a.image_path||'';
}
function openDeleteModal(id){openModal('deleteModal');delete_id.value=id;}
function closeAlert(){document.getElementById('popup').classList.remove('show');}
if("<?= $message ?>"){document.getElementById('popup').classList.add('show');}
</script>

<script src="../assets/js/sidebar.js"></script>
<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>
