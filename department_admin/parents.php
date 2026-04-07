<?php
/*******************************************************************************************
 * PARENTS MANAGEMENT SYSTEM — Hormuud University
 * Department Admin Version - View Only Access
 * No CRUD operations allowed - Only view parents related to department students
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
  $message = "❌ Permission denied! You have view-only access to parents data.";
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
   FETCH PARENTS + RELATIONS - Department Restricted
============================================================ */
$search = trim($_GET['q'] ?? '');
$sql = "
  SELECT DISTINCT p.* 
  FROM parents p
  JOIN parent_student ps ON p.parent_id = ps.parent_id
  JOIN students s ON ps.student_id = s.student_id
  WHERE s.department_id = ?
";

if ($search !== '') {
  $sql .= " AND (p.full_name LIKE ? OR p.phone LIKE ? OR p.email LIKE ?)";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$department_id, "%$search%", "%$search%", "%$search%"]);
  $parents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
  $sql .= " ORDER BY p.parent_id DESC";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$department_id]);
  $parents = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ✅ Fetch related students (children) from current department only */
$relations = [];
$stmt = $pdo->prepare("
  SELECT p.parent_id, s.student_id, s.full_name AS student_name, s.reg_no, s.status AS student_status, ps.relation_type,
         d.department_name, pr.program_name
  FROM parent_student ps
  JOIN parents p ON p.parent_id = ps.parent_id
  JOIN students s ON s.student_id = ps.student_id
  JOIN departments d ON s.department_id = d.department_id
  LEFT JOIN programs pr ON s.program_id = pr.program_id
  WHERE s.department_id = ?
  ORDER BY p.parent_id, s.full_name
");
$stmt->execute([$department_id]);
while($r = $stmt->fetch(PDO::FETCH_ASSOC)){
  $relations[$r['parent_id']][] = $r;
}

include('../includes/header.php');
?>

<div class="main-content">
  <?php if($message): ?>
  <div class="alert <?= $type=='success'?'alert-success':'alert-error' ?>"><?= htmlspecialchars($message) ?></div>
  <script>setTimeout(()=>document.querySelector('.alert')?.classList.add('hide'),3000);</script>
  <?php endif; ?>

  <div class="top-bar">
    <h2>Parents Management </h2>
    
  </div>

  <!-- Permission Note -->
  <!-- <div class="permission-note">
  </div> -->

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
          <th>Children in Department</th>
          <th>Photo</th>
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
                    <li style="margin-bottom: 8px; padding: 5px; background: #f8f9fa; border-radius: 4px;">
                      👦 <strong><?= htmlspecialchars($child['student_name']) ?></strong>
                      <small>(<?= htmlspecialchars($child['relation_type']) ?>)</small><br>
                      <span style="font-size:12px;color:<?= $child['student_status']=='active'?'green':'#C62828' ?>">
                        <?= ucfirst($child['student_status']) ?> — Reg: <?= htmlspecialchars($child['reg_no']) ?>
                      </span><br>
                      <span style="font-size:11px;color:#666;">
                        Program: <?= htmlspecialchars($child['program_name'] ?? 'N/A') ?>
                      </span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </details>
            <?php else: ?>
              <span style="color:#999;">No students in department</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if(!empty($p['photo_path'])): ?>
              <img src="../<?= htmlspecialchars($p['photo_path']) ?>" width="40" height="40" style="border-radius:50%;object-fit:cover;">
            <?php else: ?>
              <span style="color:#bbb;">N/A</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($parents)): ?>
          <tr>
            <td colspan="9" style="text-align:center;color:#777;padding:20px;">
              No parents found for students in your department.
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ================================
     CSS DESIGN
================================ -->
<style>
body {
  font-family:'Poppins',sans-serif;
  background:#f7f9fb;
  margin:0;
}
.main-content {
  padding:25px;
  margin-left:250px;
  margin-top:90px;
  transition:margin-left 0.3s ease;
}

.sidebar.collapsed ~ .main-content {
  margin-left: 70px;
}

.top-bar {
  display:flex;
  justify-content:space-between;
  align-items:center;
  background:#fff;
  padding:15px 20px;
  border-radius:10px;
  box-shadow:0 2px 6px rgba(0,0,0,0.08);
  flex-wrap: wrap;
  gap: 15px;
}
.top-bar h2 { 
  color:#0072CE; 
  margin:0; 
  display: flex;
  align-items: center;
  gap: 10px;
}
.department-info {
  background: #e3f2fd;
  padding: 8px 12px;
  border-radius: 6px;
  border: 1px solid #bbdefb;
  color: #1565c0;
  font-size: 14px;
}
.view-only-badge {
  background: #666;
  color: white;
  padding: 4px 8px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 600;
}
.permission-note {
  background: #fff3cd;
  border: 1px solid #ffeaa7;
  border-radius: 6px;
  padding: 12px;
  margin: 15px 0;
  color: #856404;
  font-size: 14px;
}
.btn {
  border:none;
  padding:8px 12px;
  border-radius:6px;
  font-weight:600;
  cursor:pointer;
  margin:2px;
  font-size:13px;
}
.btn.blue{background:#0072CE;color:#fff;}
.btn.green{background:#00843D;color:#fff;}
.btn.red{background:#C62828;color:#fff;}
.alert {
  position:fixed;
  top:50%;
  left:50%;
  transform:translate(-50%,-50%);
  padding:18px 22px;
  border-radius:10px;
  color:#fff;
  z-index:9999;
  animation:fadeIn .4s ease;
}
.alert.hide{opacity:0;transition:opacity .5s ease;}
.alert-success{background:#00843D;}
.alert-error{background:#C62828;}
@keyframes fadeIn {
  from{opacity:0;transform:translate(-50%,-60%);}
  to{opacity:1;transform:translate(-50%,-50%);}
}

.table-responsive {
  width: 100%;
  overflow-x: auto;
  overflow-y: auto;
  max-height: 450px;
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.08);
  margin-top: 10px;
}

thead th {
  position: sticky;
  top: 0;
  background: #0072CE;
  color: #fff;
  z-index: 2;
}

table {
  width:100%;
  border-collapse:collapse;
  margin-top:15px;
  background:#fff;
  box-shadow:0 2px 6px rgba(0,0,0,.08);
}
th,td {
  padding:10px 12px;
  text-align:left;
  border-bottom:1px solid #eee;
  font-size:14px;
}
thead th { background:#0072CE; color:#fff; }

.search-bar {
  margin-top:10px;
  display:flex;
  align-items:center;
  gap:8px;
}
.search-bar input {
  width:230px;
  padding:6px 10px;
  border-radius:6px;
  border:1px solid #ccc;
  font-size:13px;
}

/* Disabled button styles */
.btn:disabled {
  background: #cccccc !important;
  color: #666666 !important;
  cursor: not-allowed !important;
  opacity: 0.6;
}

.action-disabled {
  background: #cccccc;
  color: #666666;
  padding: 8px 12px;
  border-radius: 6px;
  border: none;
  cursor: not-allowed;
  opacity: 0.6;
}
</style>

<!-- ================================
     JAVASCRIPT SECTION
================================ -->
<script>
// Show permission alert for any attempted actions
function showPermissionAlert() {
  alert("Permission denied! As a Department Admin, you have view-only access to parents data. Adding, editing, or deleting parents is restricted.");
}

// Disable all forms on the page
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