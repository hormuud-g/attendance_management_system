<?php
/*******************************************************************************************
 * USER MANAGEMENT SYSTEM — Hormuud University
 * CRUD + Filter + Search + Profile Upload + Student RegNo
 * Faculty Admin Version - Restricted to faculty users only
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

    // 🗑️ DELETE
    if ($_POST['action'] === 'delete') {
      $uid = intval($_POST['user_id']);
      
      // Verify user belongs to faculty before deletion
      $check = $pdo->prepare("
        SELECT u.role, u.linked_id, u.linked_table,
               CASE 
                 WHEN u.linked_table = 'student' THEN s.faculty_id
                 WHEN u.linked_table = 'department' THEN d.faculty_id
                 WHEN u.role = 'faculty_admin' THEN u.linked_id
                 ELSE NULL
               END as user_faculty_id
        FROM users u
        LEFT JOIN students s ON u.linked_table = 'student' AND u.linked_id = s.student_id
        LEFT JOIN departments d ON u.linked_table = 'department' AND u.linked_id = d.department_id
        WHERE u.user_id = ?
      ");
      $check->execute([$uid]);
      $urow = $check->fetch(PDO::FETCH_ASSOC);
      
      if (!$urow) {
        throw new Exception("❌ User not found!");
      }
      
      // Check if user belongs to current faculty
      if ($urow['user_faculty_id'] != $faculty_id) {
        throw new Exception("❌ You can only delete users from your faculty!");
      }
      
      $pdo->prepare("DELETE FROM users WHERE user_id=?")->execute([$uid]);
      $message = "🗑️ User deleted successfully!";
    }

    // ➕ ADD or ✏️ UPDATE
    else {
      $photo = $_POST['existing_photo'] ?? 'upload/profiles/default.png';
      if (!empty($_FILES['photo']['name'])) {
        $dir = __DIR__ . '/../upload/profiles/';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $name = uniqid('usr_') . ".$ext";
        $photo = "upload/profiles/$name";
        move_uploaded_file($_FILES['photo']['tmp_name'], __DIR__ . '/../' . $photo);
      }

      // ➕ ADD
      if ($_POST['action'] === 'add') {
        $user_role = $_POST['role'];
        $linked_id = $_POST['linked_id'] ?: null;
        $linked_table = $_POST['linked_table'] ?: null;
        
        // Validate faculty access for new user
        if ($user_role === 'faculty_admin') {
          // Faculty admin must be linked to current faculty
          if ($linked_id != $faculty_id) {
            throw new Exception("❌ Faculty admin must be linked to your faculty!");
          }
        } else {
          // For other roles, verify they belong to current faculty
          if ($linked_table === 'student') {
            $check = $pdo->prepare("SELECT faculty_id FROM students WHERE student_id = ?");
            $check->execute([$linked_id]);
            $student = $check->fetch(PDO::FETCH_ASSOC);
            if (!$student || $student['faculty_id'] != $faculty_id) {
              throw new Exception("❌ Student does not belong to your faculty!");
            }
          } elseif ($linked_table === 'department') {
            $check = $pdo->prepare("SELECT faculty_id FROM departments WHERE department_id = ?");
            $check->execute([$linked_id]);
            $dept = $check->fetch(PDO::FETCH_ASSOC);
            if (!$dept || $dept['faculty_id'] != $faculty_id) {
              throw new Exception("❌ Department does not belong to your faculty!");
            }
          } elseif ($user_role === 'department_admin' && $linked_table === 'department') {
            $check = $pdo->prepare("SELECT faculty_id FROM departments WHERE department_id = ?");
            $check->execute([$linked_id]);
            $dept = $check->fetch(PDO::FETCH_ASSOC);
            if (!$dept || $dept['faculty_id'] != $faculty_id) {
              throw new Exception("❌ Department does not belong to your faculty!");
            }
          }
          // Teachers and Parents don't have faculty_id, so we allow them without faculty validation
        }

        $plainPass = $_POST['password_plain'] ?: '123';
        $stmt = $pdo->prepare("
          INSERT INTO users (
            user_uuid, username, email, phone_number, profile_photo_path, 
            password, password_plain, role, linked_id, linked_table, 
            status, created_at
          )
          VALUES (UUID(),?,?,?,?,?,?,?,?,?,? ,NOW())
        ");
        $stmt->execute([
          $_POST['username'],
          $_POST['email'],
          $_POST['phone_number'],
          $photo,
          password_hash($plainPass, PASSWORD_BCRYPT),
          $plainPass,
          $user_role,
          $linked_id,
          $linked_table,
          $_POST['status']
        ]);
        $message = "✅ User added successfully!";
      }

      // ✏️ UPDATE
      elseif ($_POST['action'] === 'update') {
        $uid = intval($_POST['user_id']);
        
        // Verify user belongs to faculty before update
        $check = $pdo->prepare("
          SELECT u.role, u.linked_id, u.linked_table,
                 CASE 
                   WHEN u.linked_table = 'student' THEN s.faculty_id
                   WHEN u.linked_table = 'department' THEN d.faculty_id
                   WHEN u.role = 'faculty_admin' THEN u.linked_id
                   ELSE NULL
                 END as user_faculty_id
          FROM users u
          LEFT JOIN students s ON u.linked_table = 'student' AND u.linked_id = s.student_id
          LEFT JOIN departments d ON u.linked_table = 'department' AND u.linked_id = d.department_id
          WHERE u.user_id = ?
        ");
        $check->execute([$uid]);
        $urow = $check->fetch(PDO::FETCH_ASSOC);
        
        if (!$urow) {
          throw new Exception("❌ User not found!");
        }
        
        // Check if user belongs to current faculty
        if ($urow['user_faculty_id'] != $faculty_id) {
          throw new Exception("❌ You can only update users from your faculty!");
        }

        $user_role = $_POST['role'];
        $linked_id = $_POST['linked_id'] ?: null;
        $linked_table = $_POST['linked_table'] ?: null;
        
        // Validate faculty access for updated user data
        if ($user_role === 'faculty_admin') {
          if ($linked_id != $faculty_id) {
            throw new Exception("❌ Faculty admin must be linked to your faculty!");
          }
        } else {
          if ($linked_table === 'student') {
            $check = $pdo->prepare("SELECT faculty_id FROM students WHERE student_id = ?");
            $check->execute([$linked_id]);
            $student = $check->fetch(PDO::FETCH_ASSOC);
            if (!$student || $student['faculty_id'] != $faculty_id) {
              throw new Exception("❌ Student does not belong to your faculty!");
            }
          } elseif ($linked_table === 'department') {
            $check = $pdo->prepare("SELECT faculty_id FROM departments WHERE department_id = ?");
            $check->execute([$linked_id]);
            $dept = $check->fetch(PDO::FETCH_ASSOC);
            if (!$dept || $dept['faculty_id'] != $faculty_id) {
              throw new Exception("❌ Department does not belong to your faculty!");
            }
          } elseif ($user_role === 'department_admin' && $linked_table === 'department') {
            $check = $pdo->prepare("SELECT faculty_id FROM departments WHERE department_id = ?");
            $check->execute([$linked_id]);
            $dept = $check->fetch(PDO::FETCH_ASSOC);
            if (!$dept || $dept['faculty_id'] != $faculty_id) {
              throw new Exception("❌ Department does not belong to your faculty!");
            }
          }
          // Teachers and Parents don't have faculty_id, so we allow them without faculty validation
        }

        $plainPass = trim($_POST['password_plain']);
        $setPass = "";
        $params = [
          $_POST['username'],
          $_POST['email'],
          $_POST['phone_number'],
          $photo,
          $user_role,
          $linked_id,
          $linked_table,
          $_POST['status'],
          $uid
        ];

        if (!empty($plainPass)) {
          $setPass = ", password=?, password_plain=?";
          array_splice($params, 4, 0, [password_hash($plainPass, PASSWORD_BCRYPT), $plainPass]);
        }

        $sql = "
          UPDATE users 
          SET username=?, email=?, phone_number=?, profile_photo_path=? $setPass,
              role=?, linked_id=?, linked_table=?, status=? 
          WHERE user_id=?
        ";
        $pdo->prepare($sql)->execute($params);
        $message = "✏️ User updated successfully!";
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
   FETCH USERS (SEARCH + FILTER) - Faculty Restricted
============================================================ */
$search = trim($_GET['q'] ?? '');
$filterRole = $_GET['role'] ?? 'all';
$status = $_GET['status'] ?? 'all';

// ✅ Faculty admin can only see users from their faculty
$sql = "
  SELECT DISTINCT u.*, s.reg_no
  FROM users u
  LEFT JOIN students s 
    ON u.linked_table = 'student' AND u.linked_id = s.student_id
  LEFT JOIN departments d
    ON u.linked_table = 'department' AND u.linked_id = d.department_id
  WHERE (
    (u.linked_table = 'student' AND s.faculty_id = :faculty_id) OR
    (u.linked_table = 'department' AND d.faculty_id = :faculty_id) OR
    (u.role = 'faculty_admin' AND u.linked_id = :faculty_id) OR
    (u.role = 'department_admin' AND d.faculty_id = :faculty_id) OR
    (u.role IN ('teacher', 'parent') AND u.linked_table IS NULL)
  )
";

$params = ['faculty_id' => $faculty_id];

// 🔍 Search filter
if ($search !== '') {
  $sql .= " AND (u.username LIKE :q OR u.email LIKE :q OR u.phone_number LIKE :q OR s.reg_no LIKE :q)";
  $params['q'] = "%$search%";
}

// 🧩 Role filter
if ($filterRole !== 'all') {
  $sql .= " AND u.role = :role";
  $params['role'] = $filterRole;
}

// ⚙️ Status filter
if (in_array($status, ['active','inactive'])) {
  $sql .= " AND u.status = :status";
  $params['status'] = $status;
}

$sql .= " ORDER BY u.user_id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ Fetch students from faculty for dropdown
$students = $pdo->prepare("
  SELECT student_id, full_name, reg_no 
  FROM students 
  WHERE faculty_id = ? 
  ORDER BY full_name ASC
");
$students->execute([$faculty_id]);
$students = $students->fetchAll(PDO::FETCH_ASSOC);

// ✅ Fetch departments from faculty for dropdown
$departments = $pdo->prepare("
  SELECT department_id, department_name 
  FROM departments 
  WHERE faculty_id = ? 
  ORDER BY department_name ASC
");
$departments->execute([$faculty_id]);
$departments = $departments->fetchAll(PDO::FETCH_ASSOC);

include('../includes/header.php');
?>
<style>
  .sidebar.collapsed ~ .main-content {
    margin-left: 70px;
  }
  body{font-family:'Poppins',sans-serif;background:#f7f9fb;margin:0;}
  .main-content{padding:25px;margin-left:250px;margin-top:90px;}
  .top-bar{display:flex;justify-content:space-between;align-items:center;background:#fff;padding:14px 20px;border-radius:10px;box-shadow:0 2px 6px rgba(0,0,0,0.08);}
  .btn{border:none;padding:8px 12px;border-radius:6px;font-weight:600;cursor:pointer;margin:2px;font-size:13px;}
  .btn.blue{background:#0072CE;color:#fff;} .btn.green{background:#00843D;color:#fff;} .btn.red{background:#C62828;color:#fff;}
  .alert{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);padding:18px 22px;border-radius:10px;color:#fff;z-index:9999;}
  .alert-success{background:#00843D;} .alert-error{background:#C62828;}
  .table-responsive{width:100%;overflow:auto;max-height:450px;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.08);margin-top:10px;}
  thead th{position:sticky;top:0;background:#0072CE;color:#fff;}
  table{width:100%;border-collapse:collapse;}
  th,td{padding:10px 12px;border-bottom:1px solid #eee;text-align:left;font-size:14px;}
  .modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);justify-content:center;align-items:center;z-index:9999;}
  .modal.show{display:flex;}
  .modal-content{background:#fff;width:95%;max-width:750px;border-radius:10px;padding:20px;position:relative;max-height:90vh;overflow:auto;}
  .close-modal{position:absolute;top:10px;right:12px;font-size:20px;cursor:pointer;}
  form{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;}
  form label{font-weight:600;color:#0072CE;font-size:13px;}
  form input,form select{padding:8px;border:1px solid #ccc;border-radius:6px;}
  .save-btn{grid-column:span 2;width:100%;}
  .search-bar{margin-top:10px;display:flex;align-items:center;gap:8px;}
  .search-bar input,.search-bar select{padding:6px 10px;border:1px solid #ccc;border-radius:6px;font-size:13px;}
  .hide{display:none;}
</style>

<div class="main-content">
  <?php if($message): ?>
    <div class="alert <?= $type=='success'?'alert-success':'alert-error' ?>">
      <?= htmlspecialchars($message) ?>
    </div>
    <script>setTimeout(()=>document.querySelector('.alert')?.classList.add('hide'),3000);</script>
  <?php endif; ?>

  <div class="top-bar">
    <h2>User Management</h2>
    <button class="btn green" onclick="openModal('userModal')">+ Add User</button>
  </div>

  <!-- 🔍 FILTER + SEARCH -->
  <?php
$search = $_GET['q'] ?? '';
$filterRole = $_GET['role'] ?? 'all';
$status = $_GET['status'] ?? 'all';
?>
  <form method="GET" class="search-bar">
    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="🔍 Search name, email, phone or RegNo..." style="flex:1;">
    <select name="role">
      <option value="all" <?= $filterRole=='all'?'selected':'' ?>>All Roles</option>
      <option value="faculty_admin" <?= $filterRole=='faculty_admin'?'selected':'' ?>>Faculty Admin</option>
      <option value="department_admin" <?= $filterRole=='department_admin'?'selected':'' ?>>Department Admin</option>
      <option value="teacher" <?= $filterRole=='teacher'?'selected':'' ?>>Teacher</option>
      <option value="student" <?= $filterRole=='student'?'selected':'' ?>>Student</option>
      <option value="parent" <?= $filterRole=='parent'?'selected':'' ?>>Parent</option>
    </select>
    <select name="status">
      <option value="all" <?= $status=='all'?'selected':'' ?>>All Status</option>
      <option value="active" <?= $status=='active'?'selected':'' ?>>Active</option>
      <option value="inactive" <?= $status=='inactive'?'selected':'' ?>>Inactive</option>
    </select>
    <button class="btn blue">Filter</button>
  </form>

  <!-- TABLE -->
  <div class="table-responsive">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Username</th>
          <th>Reg No (Student)</th>
          <th>Email</th>
          <th>Phone</th>
          <th>Role</th>
          <th>Status</th>
          <th>Password</th>
          <th>Photo</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($users as $i=>$u): ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><?= htmlspecialchars($u['username']) ?></td>
          <td><?= ($u['role']==='student' && !empty($u['reg_no'])) ? htmlspecialchars($u['reg_no']) : '—' ?></td>
          <td><?= htmlspecialchars($u['email']) ?></td>
          <td><?= htmlspecialchars($u['phone_number']) ?></td>
          <td><?= htmlspecialchars(ucfirst($u['role'])) ?></td>
          <td style="color:<?= $u['status']=='active'?'green':'#C62828' ?>"><?= ucfirst($u['status']) ?></td>
          <td><span style="color:#C62828;"><?= htmlspecialchars($u['password_plain']) ?></span></td>
          <td>
            <?php if($u['profile_photo_path'] && file_exists('../' . $u['profile_photo_path'])): ?>
              <img src="../<?= htmlspecialchars($u['profile_photo_path']) ?>" width="40" height="40" style="border-radius:50%;object-fit:cover;">
            <?php else: ?>
              <span style="color:#aaa;">N/A</span>
            <?php endif; ?>
          </td>
          <td>
            <button class="btn blue" onclick='editUser(<?= json_encode($u) ?>)'><i class="fa fa-edit"></i></button>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
              <input type="hidden" name="action" value="delete">
              <button class="btn red" onclick="return confirm('Delete this user?')"><i class="fa fa-trash"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($users)): ?>
        <tr><td colspan="10" style="text-align:center;color:#777;">No users found in your faculty</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- MODAL -->
<div class="modal" id="userModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('userModal')">&times;</span>
    <h2 id="formTitle">Add User</h2>
    <form method="POST" enctype="multipart/form-data" onsubmit="return validateForm(this)">
      <input type="hidden" name="action" id="formAction" value="add">
      <input type="hidden" name="user_id" id="user_id">
      <input type="hidden" name="existing_photo" id="existing_photo">

      <div><label>Username*</label><input name="username" id="username" required></div>
      <div><label>Email</label><input type="email" name="email" id="email"></div>
      <div><label>Phone</label><input name="phone_number" id="phone_number"></div>
      <div><label>Role*</label>
        <select name="role" id="role" required onchange="handleRoleChange()">
          <option value="">Select Role</option>
          <option value="faculty_admin">Faculty Admin</option>
          <option value="department_admin">Department Admin</option>
          <option value="teacher">Teacher</option>
          <option value="student">Student</option>
          <option value="parent">Parent</option>
        </select>
      </div>
      <div><label>Status</label>
        <select name="status" id="status">
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>
      <div><label>Password*</label><input name="password_plain" id="password_plain" required></div>
      
      <div><label>Linked ID</label>
        <select name="linked_id" id="linked_id">
          <option value="">Select ID</option>
        </select>
      </div>
      
      <div><label>Linked Table</label>
        <select name="linked_table" id="linked_table" onchange="handleLinkedTableChange()">
          <option value="">None</option>
          <option value="faculty">Faculty</option>
          <option value="department">Department</option>
          <option value="teacher">Teacher</option>
          <option value="student">Student</option>
          <option value="parent">Parent</option>
        </select>
      </div>
      
      <div><label>Photo</label><input type="file" name="photo" id="photo" accept="image/*"></div>
      <button class="btn green save-btn">Save User</button>
    </form>
  </div>
</div>

<script>
const students = <?= json_encode($students) ?>;
const departments = <?= json_encode($departments) ?>;
const facultyId = <?= $faculty_id ?>;

function openModal(id){
  const m=document.getElementById(id);
  m.classList.add('show');
  m.querySelector('form').reset();
  document.getElementById('formAction').value='add';
  document.getElementById('formTitle').innerText='Add User';
  document.getElementById('user_id').value='';
  document.getElementById('existing_photo').value='';
  document.getElementById('linked_id').innerHTML = '<option value="">Select ID</option>';
}

function closeModal(id){
  document.getElementById(id).classList.remove('show');
}

function validateForm(f){
  for(let i of f.querySelectorAll('[required]')){
    if(!i.value.trim()){
      alert('⚠️ Please fill required fields!');
      i.focus();
      return false;
    }
  }
  return true;
}

function editUser(u){
  openModal('userModal');
  document.getElementById('formTitle').innerText='Edit User';
  document.getElementById('formAction').value='update';
  document.getElementById('user_id').value=u.user_id;
  document.getElementById('username').value=u.username||'';
  document.getElementById('email').value=u.email||'';
  document.getElementById('phone_number').value=u.phone_number||'';
  document.getElementById('role').value=u.role||'';
  document.getElementById('status').value=u.status||'active';
  document.getElementById('password_plain').value=u.password_plain||'';
  document.getElementById('linked_table').value=u.linked_table||'';
  document.getElementById('existing_photo').value=u.profile_photo_path||'';
  
  // Populate linked_id based on linked_table
  handleLinkedTableChange(u.linked_id);
}

function handleRoleChange() {
  const role = document.getElementById('role').value;
  const linkedTable = document.getElementById('linked_table');
  
  // Auto-set linked table based on role
  if (role === 'faculty_admin') {
    linkedTable.value = 'faculty';
    populateFacultyId();
  } else if (role === 'department_admin') {
    linkedTable.value = 'department';
    populateDepartments();
  } else if (role === 'teacher') {
    linkedTable.value = 'teacher';
    document.getElementById('linked_id').innerHTML = '<option value="">No ID needed</option>';
  } else if (role === 'student') {
    linkedTable.value = 'student';
    populateStudents();
  } else if (role === 'parent') {
    linkedTable.value = 'parent';
    document.getElementById('linked_id').innerHTML = '<option value="">No ID needed</option>';
  }
}

function handleLinkedTableChange(selectedId = '') {
  const linkedTable = document.getElementById('linked_table').value;
  const linkedId = document.getElementById('linked_id');
  
  linkedId.innerHTML = '<option value="">Select ID</option>';
  
  if (linkedTable === 'faculty') {
    populateFacultyId(selectedId);
  } else if (linkedTable === 'department') {
    populateDepartments(selectedId);
  } else if (linkedTable === 'student') {
    populateStudents(selectedId);
  } else if (linkedTable === 'teacher' || linkedTable === 'parent') {
    linkedId.innerHTML = '<option value="">No ID needed</option>';
  }
}

function populateFacultyId(selectedId = '') {
  const linkedId = document.getElementById('linked_id');
  linkedId.innerHTML = '<option value="">Select Faculty</option>';
  const option = document.createElement('option');
  option.value = facultyId;
  option.textContent = 'Current Faculty (ID: ' + facultyId + ')';
  if (selectedId == facultyId) option.selected = true;
  linkedId.appendChild(option);
}

function populateDepartments(selectedId = '') {
  const linkedId = document.getElementById('linked_id');
  linkedId.innerHTML = '<option value="">Select Department</option>';
  
  departments.forEach(dept => {
    const option = document.createElement('option');
    option.value = dept.department_id;
    option.textContent = dept.department_name + ' (ID: ' + dept.department_id + ')';
    if (selectedId == dept.department_id) option.selected = true;
    linkedId.appendChild(option);
  });
}

function populateStudents(selectedId = '') {
  const linkedId = document.getElementById('linked_id');
  linkedId.innerHTML = '<option value="">Select Student</option>';
  
  students.forEach(student => {
    const option = document.createElement('option');
    option.value = student.student_id;
    option.textContent = student.full_name + ' - ' + student.reg_no + ' (ID: ' + student.student_id + ')';
    if (selectedId == student.student_id) option.selected = true;
    linkedId.appendChild(option);
  });
}
</script>

<?php include('../includes/footer.php'); ?>
<?php ob_end_flush(); ?>