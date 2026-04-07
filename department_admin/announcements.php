<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Access Control

$role = strtolower($_SESSION['user']['role'] ?? '');
$user_linked_id = $_SESSION['user']['linked_id'] ?? null;
$user_id = $_SESSION['user']['user_id'] ?? null; // ✅ FIX

if ($role !== 'department_admin') {
  header("Location: ../login.php");
  exit;
}

// ✅ Auto set department_id from session
$department_id = $user_linked_id;
if (!$department_id) {
  die("Department ID not found in session. Please login again.");
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
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($ext, $allowed_ext)) {
          throw new Exception("Only JPG, PNG, GIF, and WebP images are allowed.");
        }
        $name = uniqid('ann_') . ".$ext";
        $photo = "upload/announcements/$name";
        move_uploaded_file($_FILES['image']['tmp_name'], __DIR__ . '/../' . $photo);
      }

      $stmt = $pdo->prepare("
        INSERT INTO announcement (title, message, image_path, target_role, status, created_by, created_at)
        VALUES (?,?,?,?,?,?,NOW())
      ");
      $stmt->execute([
        $_POST['title'],
        $_POST['message'],
        $photo,
        $_POST['target_role'],
        $_POST['status'],
        $user_id
      ]);

      $message = "✅ Announcement added successfully!";
      $type = "success";
    }

    // ✏️ UPDATE
    if ($_POST['action'] === 'update') {
      $id = $_POST['announcement_id'];
      
      // Check if the current user is the creator of this announcement
      $checkStmt = $pdo->prepare("SELECT created_by FROM announcement WHERE announcement_id = ?");
      $checkStmt->execute([$id]);
      $announcement = $checkStmt->fetch(PDO::FETCH_ASSOC);
      
      if (!$announcement) {
        throw new Exception("Announcement not found!");
      }
      
      // Only allow update if user is the creator OR is super_admin
      if ($announcement['created_by'] != $user_id && $role != 'super_admin') {
        throw new Exception("You can only edit announcements that you created!");
      }
      
      $photo = $_POST['existing_image'] ?? null;

      if (!empty($_FILES['image']['name'])) {
        $dir = __DIR__ . '/../upload/announcements/';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($ext, $allowed_ext)) {
          throw new Exception("Only JPG, PNG, GIF, and WebP images are allowed.");
        }
        $name = uniqid('ann_') . ".$ext";
        $photo = "upload/announcements/$name";
        move_uploaded_file($_FILES['image']['tmp_name'], __DIR__ . '/../' . $photo);
      }

      $stmt = $pdo->prepare("
        UPDATE announcement 
        SET title=?, message=?, image_path=?, target_role=?, status=?, updated_at=NOW()
        WHERE announcement_id=?
      ");
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
      $id = $_POST['announcement_id'];
      
      // Check if the current user is the creator of this announcement
      $checkStmt = $pdo->prepare("SELECT created_by FROM announcement WHERE announcement_id = ?");
      $checkStmt->execute([$id]);
      $announcement = $checkStmt->fetch(PDO::FETCH_ASSOC);
      
      if (!$announcement) {
        throw new Exception("Announcement not found!");
      }
      
      // Only allow delete if user is the creator OR is super_admin
      if ($announcement['created_by'] != $user_id && $role != 'super_admin') {
        throw new Exception("You can only delete announcements that you created!");
      }
      
      $stmt = $pdo->prepare("DELETE FROM announcement WHERE announcement_id=?");
      $stmt->execute([$id]);

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
  LEFT JOIN users u ON u.user_id = a.created_by
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
  --light-green:#E8F5E8;--light-blue:#E3F2FD;--light-red:#FFEBEE;
  --border:#E0E0E0;--text-dark:#2C3E50;--text-light:#546E7A;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:var(--bg);margin:0;color:var(--text-dark);line-height:1.6;}
.main-content{padding:25px;margin-top:80px;margin-left:260px;transition:all .3s ease;}
.sidebar.collapsed ~ .main-content{margin-left:80px;}
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:25px;padding-bottom:15px;border-bottom:1px solid var(--border);}
.page-header h1{color:var(--blue);font-size:28px;font-weight:700;display:flex;align-items:center;gap:12px;}
.page-header h1 i{color:var(--green);}
.add-btn{background:linear-gradient(135deg, var(--green), #00A651);color:#fff;padding:12px 24px;border:none;border-radius:8px;font-weight:600;cursor:pointer;font-size:15px;display:flex;align-items:center;gap:8px;transition:all 0.3s ease;box-shadow:0 4px 12px rgba(0,132,61,0.3);}
.add-btn:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,132,61,0.4);}
.filter-bar{display:flex;align-items:center;flex-wrap:wrap;gap:12px;background:#fff;padding:18px 20px;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,0.08);margin-bottom:25px;}
.filter-bar input, .filter-bar select{padding:10px 14px;border:1.5px solid var(--border);border-radius:8px;font-size:14px;transition:all 0.3s;flex:1;min-width:150px;}
.filter-bar input:focus, .filter-bar select:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 3px rgba(0,114,206,0.1);}
.filter-bar button{background:linear-gradient(135deg, var(--blue), #1E88E5);color:#fff;padding:10px 20px;border:none;border-radius:8px;font-weight:600;cursor:pointer;font-size:14px;display:flex;align-items:center;gap:6px;transition:all 0.3s;}
.filter-bar button:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,114,206,0.3);}
.filter-bar a{background:#E0E0E0;color:#424242;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:500;display:flex;align-items:center;gap:6px;transition:all 0.3s;}
.filter-bar a:hover{background:#BDBDBD;}
.table-wrapper{background:#fff;border-radius:12px;box-shadow:0 6px 18px rgba(0,0,0,0.08);overflow:hidden;}
table{width:100%;border-collapse:collapse;}
thead th{background:linear-gradient(135deg, var(--blue), #1565C0);color:#fff;padding:16px 14px;text-align:left;font-weight:600;font-size:14px;position:sticky;top:0;}
th,td{padding:14px 16px;border-bottom:1px solid var(--border);font-size:14px;}
tbody tr{transition:all 0.3s;}
tbody tr:hover{background:var(--light-green);transform:translateY(-1px);box-shadow:0 4px 8px rgba(0,0,0,0.05);}
.action-buttons{display:flex;justify-content:center;gap:8px;}
.btn-edit{background:linear-gradient(135deg, var(--blue), #1976D2);color:white;padding:8px 12px;border:none;border-radius:6px;cursor:pointer;transition:all 0.3s;font-size:13px;}
.btn-edit:hover{transform:translateY(-2px);box-shadow:0 4px 8px rgba(0,114,206,0.3);}
.btn-delete{background:linear-gradient(135deg, var(--red), #D32F2F);color:white;padding:8px 12px;border:none;border-radius:6px;cursor:pointer;transition:all 0.3s;font-size:13px;}
.btn-delete:hover{transform:translateY(-2px);box-shadow:0 4px 8px rgba(198,40,40,0.3);}
.btn-disabled{background:#BDBDBD;color:#757575;padding:8px 12px;border:none;border-radius:6px;cursor:not-allowed;font-size:13px;}
.status-active{color:#2E7D32;font-weight:600;background:var(--light-green);padding:6px 12px;border-radius:20px;display:inline-block;font-size:13px;}
.status-inactive{color:#C62828;font-weight:600;background:var(--light-red);padding:6px 12px;border-radius:20px;display:inline-block;font-size:13px;}
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);justify-content:center;align-items:center;z-index:3000;backdrop-filter:blur(4px);}
.modal.show{display:flex;animation:fadeIn 0.3s ease;}
.modal-content{background:#fff;border-radius:16px;width:90%;max-width:750px;padding:30px;position:relative;overflow-y:auto;max-height:90vh;box-shadow:0 20px 40px rgba(0,0,0,0.2);animation:slideUp 0.4s ease;}
.close-modal{position:absolute;top:15px;right:20px;font-size:26px;cursor:pointer;color:#757575;transition:all 0.3s;width:36px;height:36px;display:flex;align-items:center;justify-content:center;border-radius:50%;}
.close-modal:hover{background:#f5f5f5;color:#424242;}
.modal h2{color:var(--blue);margin-bottom:20px;font-size:24px;font-weight:700;padding-bottom:12px;border-bottom:2px solid var(--border);}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
.form-group{display:flex;flex-direction:column;gap:8px;}
.form-group.full-width{grid-column:1/-1;}
.form-group label{font-weight:600;color:var(--text-dark);font-size:14px;}
.form-group input, .form-group select, .form-group textarea{padding:12px 14px;border:1.5px solid var(--border);border-radius:8px;font-size:15px;transition:all 0.3s;font-family:inherit;}
.form-group input:focus, .form-group select:focus, .form-group textarea:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 3px rgba(0,114,206,0.1);}
.form-group textarea{min-height:120px;resize:vertical;}
.file-input-wrapper{position:relative;}
.file-input-wrapper input[type="file"]{padding:12px 14px;border:1.5px dashed var(--border);border-radius:8px;background:#fafafa;width:100%;}
.file-input-wrapper input[type="file"]:focus{border-color:var(--blue);}
.image-preview{margin-top:10px;display:none;}
.image-preview img{max-width:200px;max-height:150px;border-radius:8px;border:1px solid var(--border);}
.save-btn{background:linear-gradient(135deg, var(--green), #00A651);color:#fff;padding:14px 28px;border:none;border-radius:8px;font-weight:600;cursor:pointer;font-size:16px;width:100%;margin-top:10px;transition:all 0.3s;box-shadow:0 4px 12px rgba(0,132,61,0.3);}
.save-btn:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,132,61,0.4);}
.alert-popup{display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;padding:25px 30px;border-radius:16px;text-align:center;box-shadow:0 10px 30px rgba(0,0,0,0.2);z-index:5000;width:320px;animation:popIn 0.4s ease;}
.alert-popup.show{display:block;}
.alert-popup.success{border-top:5px solid var(--green);}
.alert-popup.error{border-top:5px solid var(--red);}
.alert-popup h3{margin-bottom:20px;font-size:18px;font-weight:600;}
.alert-btn{background:var(--blue);color:white;padding:10px 24px;border:none;border-radius:8px;cursor:pointer;font-weight:600;font-size:15px;transition:all 0.3s;}
.alert-btn:hover{background:#1565C0;transform:translateY(-1px);}
.empty-state{text-align:center;padding:50px 20px;color:var(--text-light);}
.empty-state i{font-size:60px;color:#CFD8DC;margin-bottom:20px;}
.empty-state h3{font-size:22px;margin-bottom:10px;color:var(--text-light);}
.empty-state p{font-size:16px;max-width:400px;margin:0 auto;}

/* Animations */
@keyframes fadeIn{from{opacity:0;}to{opacity:1;}}
@keyframes slideUp{from{opacity:0;transform:translateY(30px);}to{opacity:1;transform:translateY(0);}}
@keyframes popIn{0%{opacity:0;transform:translate(-50%,-50%) scale(0.8);}100%{opacity:1;transform:translate(-50%,-50%) scale(1);}}

/* Responsive */
@media (max-width:1024px){.main-content{margin-left:0;padding:20px;}.sidebar.collapsed~.main-content{margin-left:0;}}
@media (max-width:768px){.form-grid{grid-template-columns:1fr;}.filter-bar{flex-direction:column;align-items:stretch;}.page-header{flex-direction:column;gap:15px;align-items:flex-start;}}
@media (max-width:576px){.modal-content{padding:20px;}.main-content{padding:15px;}}
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
  <div class="page-header">
    <h1>Announcements Management</h1>
    <button class="add-btn" onclick="openModal('addModal')"><i class="fa-solid fa-plus"></i> Add Announcement</button>
  </div>

  <!-- ✅ FILTER BAR -->
  <form method="GET" class="filter-bar">
    <input type="text" name="search" placeholder="🔍 Search by title or message..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
    <select name="role">
      <option value="">All Target Roles</option>
      <option value="student" <?= ($_GET['role']??'')==='student'?'selected':'' ?>>Student</option>
      <option value="teacher" <?= ($_GET['role']??'')==='teacher'?'selected':'' ?>>Teacher</option>
      <option value="parent" <?= ($_GET['role']??'')==='parent'?'selected':'' ?>>Parent</option>
      <option value="admin" <?= ($_GET['role']??'')==='admin'?'selected':'' ?>>Admin</option>
      <option value="all_users" <?= ($_GET['role']??'')==='all_users'?'selected':'' ?>>All Users</option>
    </select>
    <select name="status">
      <option value="">All Status</option>
      <option value="active" <?= ($_GET['status']??'')==='active'?'selected':'' ?>>Active</option>
      <option value="inactive" <?= ($_GET['status']??'')==='inactive'?'selected':'' ?>>Inactive</option>
    </select>
    <button type="submit"><i class="fa fa-filter"></i> Apply Filters</button>
    <a href="announcements.php"><i class="fa fa-rotate-right"></i> Reset</a>
  </form>

  <!-- ✅ TABLE -->
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Title</th>
          <th>Message</th>
          <th>Image</th>
          <th>Target</th>
          <th>Status</th>
          <th>Created By</th>
          <th>Date</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if($announcements): foreach($announcements as $i=>$a): 
          $canEdit = ($a['created_by'] == $user_id || $role == 'super_admin');
        ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><strong><?= htmlspecialchars($a['title']) ?></strong></td>
          <td><?= htmlspecialchars(substr($a['message'],0,50)) ?><?= strlen($a['message'])>50?'...':'' ?></td>
          <td>
            <?php if($a['image_path']): ?>
              <img src="../<?= htmlspecialchars($a['image_path']) ?>" width="60" height="45" style="object-fit:cover;border-radius:6px;border:1px solid var(--border);">
            <?php else:?>
              <span style="color:#aaa;font-style:italic;">No image</span>
            <?php endif; ?>
          </td>
          <td><span style="background:var(--light-blue);padding:4px 10px;border-radius:12px;font-size:12px;font-weight:600;"><?= ucfirst(str_replace('_', ' ', $a['target_role'])) ?></span></td>
          <td><span class="status-<?= $a['status'] ?>"><?= ucfirst($a['status']) ?></span></td>
          <td><?= htmlspecialchars($a['created_by_name'] ?: '—') ?></td>
          <td><?= date('M j, Y', strtotime($a['created_at'])) ?></td>
          <td>
            <div class="action-buttons">
              <?php if($canEdit): ?>
                <button class="btn-edit" onclick='openEditModal(<?= json_encode($a) ?>)' title="Edit announcement"><i class="fa-solid fa-pen-to-square"></i></button>
                <button class="btn-delete" onclick='openDeleteModal(<?= $a['announcement_id'] ?>)' title="Delete announcement"><i class="fa-solid fa-trash"></i></button>
              <?php else: ?>
                <button class="btn-disabled" title="You can only edit announcements you created"><i class="fa-solid fa-pen-to-square"></i></button>
                <button class="btn-disabled" title="You can only delete announcements you created"><i class="fa-solid fa-trash"></i></button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; else: ?>
        <tr>
          <td colspan="9" class="empty-state">
            <i class="fa-solid fa-bullhorn"></i>
            <h3>No Announcements Found</h3>
            <p>There are no announcements matching your criteria. Try adjusting your filters or create a new announcement.</p>
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ✅ ADD/EDIT MODAL -->
<div class="modal" id="addModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('addModal')">&times;</span>
    <h2 id="formTitle">Add New Announcement</h2>
    <form method="POST" enctype="multipart/form-data" id="announcementForm">
      <input type="hidden" name="action" id="formAction" value="add">
      <input type="hidden" name="announcement_id" id="announcement_id">
      <input type="hidden" name="existing_image" id="existing_image">
      
      <div class="form-grid">
        <div class="form-group full-width">
          <label for="title">Announcement Title *</label>
          <input type="text" name="title" id="title" placeholder="Enter announcement title" required>
        </div>
        
        <div class="form-group">
          <label for="target_role">Target Audience *</label>
          <select name="target_role" id="target_role" required>
            <option value="">Select Target Role</option>
            <option value="student">Students</option>
            <option value="teacher">Teachers</option>
            <option value="parent">Parents</option>
            <option value="admin">Admins</option>
            <option value="all_users">All Users</option>
          </select>
        </div>
        
        <div class="form-group">
          <label for="status">Status *</label>
          <select name="status" id="status" required>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
        
        <div class="form-group full-width">
          <label for="message">Announcement Message *</label>
          <textarea name="message" id="message" placeholder="Enter the full announcement message..." required></textarea>
        </div>
        
        <div class="form-group full-width">
          <label for="image">Announcement Image</label>
          <div class="file-input-wrapper">
            <input type="file" name="image" id="image" accept="image/*" onchange="previewImage(this)">
          </div>
          <div class="image-preview" id="imagePreview">
            <img id="previewImg" src="" alt="Image preview">
          </div>
        </div>
      </div>
      
      <button type="submit" class="save-btn"><i class="fa-solid fa-floppy-disk"></i> Save Announcement</button>
    </form>
  </div>
</div>

<!-- ✅ DELETE CONFIRMATION MODAL -->
<div class="modal" id="deleteModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('deleteModal')">&times;</span>
    <h2 style="color:var(--red);"><i class="fa-solid fa-triangle-exclamation"></i> Confirm Deletion</h2>
    <form method="POST">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" id="delete_id" name="announcement_id">
      <p style="margin:20px 0;font-size:16px;line-height:1.6;">Are you sure you want to delete this announcement? This action cannot be undone and the announcement will be permanently removed from the system.</p>
      <div style="display:flex;gap:12px;">
        <button type="button" class="alert-btn" style="background:#757575;flex:1;" onclick="closeModal('deleteModal')">Cancel</button>
        <button type="submit" class="save-btn" style="background:var(--red);flex:1;">Yes, Delete</button>
      </div>
    </form>
  </div>
</div>

<!-- ✅ ALERT POPUP -->
<div id="popup" class="alert-popup <?= $type ?>">
  <h3><?= $message ?></h3>
  <button class="alert-btn" onclick="closeAlert()">OK</button>
</div>

<script>
function openModal(id){document.getElementById(id).classList.add('show');}
function closeModal(id){document.getElementById(id).classList.remove('show');resetForm();}
function openEditModal(a){
  openModal('addModal');
  document.getElementById('formTitle').innerText='Edit Announcement';
  document.getElementById('formAction').value='update';
  document.getElementById('announcement_id').value=a.announcement_id;
  document.getElementById('title').value=a.title||'';
  document.getElementById('message').value=a.message||'';
  document.getElementById('target_role').value=a.target_role||'';
  document.getElementById('status').value=a.status||'active';
  document.getElementById('existing_image').value=a.image_path||'';
  
  // Show existing image if available
  if(a.image_path){
    document.getElementById('previewImg').src='../'+a.image_path;
    document.getElementById('imagePreview').style.display='block';
  }
}
function openDeleteModal(id){openModal('deleteModal');document.getElementById('delete_id').value=id;}
function closeAlert(){document.getElementById('popup').classList.remove('show');}
function resetForm(){
  document.getElementById('announcementForm').reset();
  document.getElementById('imagePreview').style.display='none';
  document.getElementById('formTitle').innerText='Add New Announcement';
  document.getElementById('formAction').value='add';
}
function previewImage(input){
  const preview=document.getElementById('imagePreview');
  const img=document.getElementById('previewImg');
  if(input.files&&input.files[0]){
    const reader=new FileReader();
    reader.onload=function(e){img.src=e.target.result;preview.style.display='block';}
    reader.readAsDataURL(input.files[0]);
  }else{preview.style.display='none';}
}
if("<?= $message ?>"){document.getElementById('popup').classList.add('show');}

// Close modal when clicking outside
document.addEventListener('click',function(e){
  if(e.target.classList.contains('modal')){
    e.target.classList.remove('show');
    resetForm();
  }
});
</script>

<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>