<?php
/*******************************************************************************************
 * PARENTS MANAGEMENT SYSTEM — Hormuud University
 * CRUD + Search + Relation View (children/siblings)
 * Linked table: parent_student (relation_type enum)
 * Author: ChatGPT (2025)
 *******************************************************************************************/
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

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

/* ============================================================
   CRUD OPERATIONS
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  try {
    $pdo->beginTransaction();

    if ($_POST['action'] === 'delete') {
      $pid = intval($_POST['parent_id']);
      $pdo->prepare("DELETE FROM users WHERE linked_id=? AND linked_table='parent'")->execute([$pid]);
      $pdo->prepare("DELETE FROM parent_student WHERE parent_id=?")->execute([$pid]);
      $pdo->prepare("DELETE FROM parents WHERE parent_id=?")->execute([$pid]);
      $message = "🗑️ Parent deleted successfully!";
    } else {
      // ✅ Handle photo upload
      $photo = $_POST['existing_photo'] ?? null;
      if (!empty($_FILES['photo']['name'])) {
        $dir = __DIR__ . '/../upload/profiles/';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $name = uniqid('par_') . ".$ext";
        $photo = "upload/profiles/$name";
        move_uploaded_file($_FILES['photo']['tmp_name'], __DIR__ . '/../' . $photo);
      }
      if (empty($photo)) $photo = 'upload/profiles/default.png';

      /* ============================
         ADD PARENT
      ============================ */
      if ($_POST['action'] === 'add') {
        $pdo->prepare("INSERT INTO parents (full_name, gender, phone, email, address, occupation, photo_path, status, created_at)
                       VALUES (?,?,?,?,?,?,?,?,NOW())")
             ->execute([
               $_POST['full_name'],
               $_POST['gender'],
               $_POST['phone'],
               $_POST['email'] ?: strtolower(str_replace(' ', '', $_POST['full_name'])) . '@example.com',
               $_POST['address'],
               $_POST['occupation'],
               $photo,
               $_POST['status']
             ]);
        $pid = $pdo->lastInsertId();

        // ✅ Auto-create user if not exists
        $plainPass = '123';
        $check = $pdo->prepare("SELECT user_id FROM users WHERE linked_id=? AND linked_table='parent'");
        $check->execute([$pid]);
        if (!$check->fetch()) {
          $pdo->prepare("INSERT INTO users 
            (user_uuid, username, email, phone_number, profile_photo_path, password, password_plain, role, linked_id, linked_table, status, created_at)
            VALUES (UUID(),?,?,?,?,?,?,?,?,?,'active',NOW())")
            ->execute([
              $_POST['full_name'],
              $_POST['email'] ?: strtolower(str_replace(' ', '', $_POST['full_name'])) . '@example.com',
              $_POST['phone'],
              $photo,
              password_hash($plainPass, PASSWORD_BCRYPT),
              $plainPass,
              'parent',
              $pid,
              'parent'
            ]);
        }

        $message = "✅ Parent added successfully!";
      }

      /* ============================
         UPDATE PARENT
      ============================ */
      else {
        $pdo->prepare("UPDATE parents SET full_name=?, gender=?, phone=?, email=?, address=?, occupation=?, photo_path=?, status=? WHERE parent_id=?")
             ->execute([
               $_POST['full_name'],
               $_POST['gender'],
               $_POST['phone'],
               $_POST['email'],
               $_POST['address'],
               $_POST['occupation'],
               $photo,
               $_POST['status'],
               $_POST['parent_id']
             ]);

        // ✅ Update user account status + photo
        $pdo->prepare("UPDATE users SET status=?, profile_photo_path=? WHERE linked_id=? AND linked_table='parent'")
             ->execute([$_POST['status'], $photo, $_POST['parent_id']]);

        $message = "✏️ Parent updated successfully!";
      }
    }

    $pdo->commit();
    $type = "success";
  } catch (Exception $e) {
    $pdo->rollBack();
    $message = "❌ " . $e->getMessage();
    $type = "error";
  }
}

/* ============================================================
   FETCH PARENTS + RELATIONS
============================================================ */
$search = trim($_GET['q'] ?? '');
$sql = "SELECT * FROM parents";
if ($search !== '') {
  $sql .= " WHERE full_name LIKE :q OR phone LIKE :q OR email LIKE :q";
  $stmt = $pdo->prepare($sql);
  $stmt->execute(['q' => "%$search%"]);
  $parents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
  $sql .= " ORDER BY parent_id DESC";
  $parents = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/* ✅ Fetch related students (children) */
$relations = [];
$stmt = $pdo->query("
  SELECT p.parent_id, s.student_id, s.full_name AS student_name, s.reg_no, s.status AS student_status, ps.relation_type
  FROM parent_student ps
  JOIN parents p ON p.parent_id=ps.parent_id
  JOIN students s ON s.student_id=ps.student_id
  ORDER BY p.parent_id, s.full_name
");
while($r=$stmt->fetch(PDO::FETCH_ASSOC)){
  $relations[$r['parent_id']][]=$r;
}

include('../includes/header.php');
?>

<div class="main-content">
  <?php if($message): ?>
  <div class="alert <?= $type=='success'?'alert-success':'alert-error' ?>"><?= htmlspecialchars($message) ?></div>
  <script>setTimeout(()=>document.querySelector('.alert')?.classList.add('hide'),3000);</script>
  <?php endif; ?>

  <div class="top-bar">
    <h2>Parents Management</h2>
    <button class="btn green" onclick="openModal('addModal')">+ Add Parent</button>
  </div>

  <!-- SEARCH -->
  <form method="GET" class="search-bar">
    <input type="text" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" placeholder="🔍 Search by Name or Phone...">
    <button class="btn blue">Search</button>
  </form>

  <!-- TABLE -->
  <div class="table-responsive">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Full Name</th>
          <th>Gender</th>
          <th>Phone</th>
          <th>Email</th>
          <th>Occupation</th>
          <th>Status</th>
          <th>Children</th>
          <th>Photo</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($parents as $i=>$p): ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><?= htmlspecialchars($p['full_name']) ?></td>
          <td><?= ucfirst($p['gender']) ?></td>
          <td><?= htmlspecialchars($p['phone']) ?></td>
          <td><?= htmlspecialchars($p['email']) ?></td>
          <td><?= htmlspecialchars($p['occupation']) ?></td>
          <td style="color:<?= $p['status']=='active'?'green':'#222' ?>"><?= ucfirst($p['status']) ?></td>
          <td>
            <?php if(!empty($relations[$p['parent_id']])): ?>
              <details>
                <summary style="cursor:pointer;color:#0072CE;">View (<?= count($relations[$p['parent_id']]) ?>)</summary>
                <ul style="margin:5px 0 0 10px;padding:0;list-style:none;">
                  <?php foreach($relations[$p['parent_id']] as $child): ?>
                    <li>
                      👦 <strong><?= htmlspecialchars($child['student_name']) ?></strong>
                      <small>(<?= htmlspecialchars($child['relation_type']) ?>)</small><br>
                      <span style="font-size:12px;color:<?= $child['student_status']=='active'?'green':'#C62828' ?>">
                        <?= ucfirst($child['student_status']) ?> — Reg: <?= htmlspecialchars($child['reg_no']) ?>
                      </span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </details>
            <?php else: ?>
              <span style="color:#999;">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if(!empty($p['photo_path'])): ?>
              <img src="../<?= htmlspecialchars($p['photo_path']) ?>" width="40" height="40" style="border-radius:50%;object-fit:cover;">
            <?php else: ?>
              <span style="color:#bbb;">N/A</span>
            <?php endif; ?>
          </td>
          <td>
            <button class="btn blue" onclick='editParent(<?= json_encode($p) ?>)'><i class="fa fa-edit"></i></button>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="parent_id" value="<?= $p['parent_id'] ?>">
              <input type="hidden" name="action" value="delete">
              <button class="btn red" onclick="return confirm('Delete this parent?')"><i class="fa fa-trash"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($parents)): ?>
          <tr><td colspan="10" style="text-align:center;color:#777;">No records found</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- MODAL -->
<div class="modal" id="addModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('addModal')">&times;</span>
    <h2 id="formTitle">Add Parent</h2>
    <form method="POST" enctype="multipart/form-data" onsubmit="return validateForm(this)">
      <input type="hidden" name="action" id="formAction" value="add">
      <input type="hidden" name="parent_id" id="parent_id">
      <input type="hidden" name="existing_photo" id="existing_photo">

      <div><label>Full Name*</label><input name="full_name" id="full_name" required></div>
      <div><label>Gender*</label>
        <select name="gender" id="gender" required>
          <option value="">Select</option>
          <option>male</option>
          <option>female</option>
        </select>
      </div>
      <div><label>Phone</label><input name="phone" id="phone"></div>
      <div><label>Email</label><input type="email" name="email" id="email"></div>
      <div><label>Address</label><input name="address" id="address"></div>
      <div><label>Occupation</label><input name="occupation" id="occupation"></div>
      <div><label>Status</label>
        <select name="status" id="status">
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
      <div><label>Photo</label><input type="file" name="photo" id="photo" accept="image/*"></div>
      <button class="btn green save-btn">Save Record</button>
    </form>
  </div>
</div>

<style>
body{font-family:'Poppins',sans-serif;background:#f7f9fb;margin:0;}
.main-content{padding:25px;margin-left:250px;margin-top:90px;transition:margin-left .3s ease;}
.sidebar.collapsed ~ .main-content {
  margin-left: 70px;
}
.btn{border:none;padding:8px 12px;border-radius:6px;font-weight:600;cursor:pointer;margin:2px;font-size:13px;}
.btn.blue{background:#0072CE;color:#fff;} .btn.green{background:#00843D;color:#fff;} .btn.red{background:#C62828;color:#fff;}
.alert{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);padding:18px 22px;border-radius:10px;color:#fff;z-index:9999;}
.alert-success{background:#00843D;} .alert-error{background:#C62828;}
.table-responsive{width:100%;overflow-x:auto;overflow-y:auto;max-height:450px;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.08);margin-top:10px;}
thead th{position:sticky;top:0;background:#0072CE;color:#fff;z-index:2;}
table{width:100%;border-collapse:collapse;}
th,td{padding:10px 12px;border-bottom:1px solid #eee;text-align:left;font-size:14px;}
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);justify-content:center;align-items:center;z-index:9999;}
.modal.show{display:flex;}
.modal-content{background:#fff;width:95%;max-width:700px;border-radius:10px;padding:20px;position:relative;max-height:90vh;overflow:auto;}
.close-modal{position:absolute;top:10px;right:12px;font-size:20px;cursor:pointer;}
form{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;}
form label{font-weight:600;color:#0072CE;font-size:13px;}
form input,form select{padding:8px;border:1px solid #ccc;border-radius:6px;}
.save-btn{grid-column:span 2;width:100%;}
.search-bar{margin-top:10px;display:flex;align-items:center;gap:8px;}
.search-bar input{width:230px;padding:6px 10px;border-radius:6px;border:1px solid #ccc;font-size:13px;}
</style>

<script>
function openModal(id){
  const m=document.getElementById(id);
  m.classList.add('show');
  m.querySelector('form').reset();
  document.getElementById('formAction').value='add';
  document.getElementById('formTitle').innerText='Add Parent';
  document.getElementById('parent_id').value='';
}
function closeModal(id){document.getElementById(id).classList.remove('show');}
function validateForm(f){
  for(let i of f.querySelectorAll('[required]')){
    if(!i.value.trim()){alert('⚠️ Please fill required fields!');i.focus();return false;}
  }
  return true;
}
function editParent(p){
  openModal('addModal');
  document.getElementById('formTitle').innerText='Edit Parent';
  document.getElementById('formAction').value='update';
  parent_id.value=p.parent_id;
  full_name.value=p.full_name||'';
  gender.value=p.gender||'';
  phone.value=p.phone||'';
  email.value=p.email||'';
  address.value=p.address||'';
  occupation.value=p.occupation||'';
  status.value=p.status||'active';
  existing_photo.value=p.photo_path||'';
}
</script>

<?php include('../includes/footer.php'); ?>
<?php ob_end_flush(); ?>
