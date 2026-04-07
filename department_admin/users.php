<?php
/*******************************************************************************************
 * USER MANAGEMENT SYSTEM — Hormuud University
 * Department Admin Version - View Only Access
 * No CRUD operations allowed - Only view department users
 * Author: ChatGPT (2025)
 *******************************************************************************************/
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

$role = strtolower($_SESSION['user']['role'] ?? '');
if ($role !== 'department_admin') {
  header("Location: ../login.php");
  exit;
}

// Get department_id from session
$department_id = $_SESSION['user']['linked_id'] ?? null;
if (!$department_id) {
  header("Location: ../login.php");
  exit;
}

$message = "";
$type = "";

/* ============================================================
   ACCESS CONTROL - NO CRUD OPERATIONS ALLOWED
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $message = "❌ Permission denied! You have view-only access to users data.";
  $type = "error";
}

/* ============================================================
   FETCH DEPARTMENT INFO
============================================================ */
// Get department name for display
$dept_stmt = $pdo->prepare("SELECT department_name FROM departments WHERE department_id = ?");
$dept_stmt->execute([$department_id]);
$department_name = $dept_stmt->fetchColumn();

// Get faculty name for the department
$faculty_stmt = $pdo->prepare("
  SELECT f.faculty_name 
  FROM faculties f 
  JOIN departments d ON f.faculty_id = d.faculty_id 
  WHERE d.department_id = ?
");
$faculty_stmt->execute([$department_id]);
$faculty_name = $faculty_stmt->fetchColumn();

/* ============================================================
   FETCH USERS (SEARCH + FILTER) - Department Restricted
============================================================ */
$search = trim($_GET['q'] ?? '');
$filterRole = $_GET['role'] ?? 'all';
$status = $_GET['status'] ?? 'all';

// ✅ Department admin can only see users from their department
$sql = "
  SELECT DISTINCT u.*, s.reg_no
  FROM users u
  LEFT JOIN students s 
    ON u.linked_table = 'student' AND u.linked_id = s.student_id
  LEFT JOIN departments d
    ON u.linked_table = 'department' AND u.linked_id = d.department_id
  WHERE (
    (u.linked_table = 'student' AND s.department_id = :department_id) OR
    (u.linked_table = 'department' AND d.department_id = :department_id) OR
    (u.role = 'department_admin' AND u.linked_id = :department_id) OR
    (u.role = 'teacher') OR  -- Teachers don't have department_id, so show all teachers
    (u.role = 'parent' AND u.linked_id IN (
      SELECT p.parent_id FROM parents p
      JOIN parent_student ps ON p.parent_id = ps.parent_id
      JOIN students s2 ON ps.student_id = s2.student_id
      WHERE s2.department_id = :department_id
    ))
  )
";

$params = ['department_id' => $department_id];

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

include('../includes/header.php');
?>
<style>
  .sidebar.collapsed ~ .main-content {
    margin-left: 70px;
  }
  body{font-family:'Poppins',sans-serif;background:#f7f9fb;margin:0;}
  .main-content{padding:25px;margin-left:250px;margin-top:90px;}
  .top-bar{display:flex;justify-content:space-between;align-items:center;background:#fff;padding:14px 20px;border-radius:10px;box-shadow:0 2px 6px rgba(0,0,0,0.08);flex-wrap: wrap;gap: 15px;}
  .btn{border:none;padding:8px 12px;border-radius:6px;font-weight:600;cursor:pointer;margin:2px;font-size:13px;}
  .btn.blue{background:#0072CE;color:#fff;} .btn.green{background:#00843D;color:#fff;} .btn.red{background:#C62828;color:#fff;}
  .alert{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);padding:18px 22px;border-radius:10px;color:#fff;z-index:9999;}
  .alert-success{background:#00843D;} .alert-error{background:#C62828;}
  .table-responsive{width:100%;overflow:auto;max-height:450px;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.08);margin-top:10px;}
  thead th{position:sticky;top:0;background:#0072CE;color:#fff;}
  table{width:100%;border-collapse:collapse;}
  th,td{padding:10px 12px;border-bottom:1px solid #eee;text-align:left;font-size:14px;}
  .hide{display:none;}
  .search-bar{margin-top:10px;display:flex;align-items:center;gap:8px;}
  .search-bar input,.search-bar select{padding:6px 10px;border:1px solid #ccc;border-radius:6px;font-size:13px;}
  
  /* View Only Styles */
  .view-only-badge {
    background: #666;
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
  }
  .department-info {
    background: #e3f2fd;
    padding: 8px 12px;
    border-radius: 6px;
    border: 1px solid #bbdefb;
    color: #1565c0;
    font-size: 14px;
  }
  /* .permission-note {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    border-radius: 6px;
    padding: 12px;
    margin: 15px 0;
    color: #856404;
    font-size: 14px;
  } */
  .top-bar h2 { 
    color:#0072CE; 
    margin:0; 
    display: flex;
    align-items: center;
    gap: 10px;
  }
</style>

<div class="main-content">
  <?php if($message): ?>
    <div class="alert <?= $type=='success'?'alert-success':'alert-error' ?>">
      <?= htmlspecialchars($message) ?>
    </div>
    <script>setTimeout(()=>document.querySelector('.alert')?.classList.add('hide'),3000);</script>
  <?php endif; ?>

  <div class="top-bar">
    <h2>User Management </h2>
    <!-- <div class="department-info">
      <strong>Department:</strong> <?= htmlspecialchars($department_name) ?> | 
      <strong>Faculty:</strong> <?= htmlspecialchars($faculty_name) ?>
    </div> -->
  </div>

  <!-- Permission Note -->
  <div class="permission-note">
    <!-- <strong><i class="fas fa-info-circle"></i> View Only Access:</strong> As a Department Admin, you can only view users in your department. Adding, editing, or deleting users is restricted. -->
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
        </tr>
        <?php endforeach; ?>
        <?php if(empty($users)): ?>
        <tr>
          <td colspan="9" style="text-align:center;color:#777;padding:20px;">
            No users found in your department.
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ================================
     JAVASCRIPT SECTION
================================ -->
<script>
// Show permission alert for any attempted actions
function showPermissionAlert() {
  alert("Permission denied! As a Department Admin, you have view-only access to users data. Adding, editing, or deleting users is restricted.");
}

// Disable all forms on the page except search
document.addEventListener('DOMContentLoaded', function() {
  // Disable search form submission if empty
  const searchForm = document.querySelector('.search-bar');
  if (searchForm) {
    searchForm.addEventListener('submit', function(e) {
      const searchInput = this.querySelector('input[name="q"]');
      if (!searchInput.value.trim()) {
        e.preventDefault();
        alert("Please enter a search term.");
        return false;
      }
    });
  }
});
</script>

<?php include('../includes/footer.php'); ?>
<?php ob_end_flush(); ?>