<?php
/*******************************************************************************************
 * PARENT PORTAL — Student Attendance View
 * Role: Parent
 * Author: GPT-5 | PHP 8.2 | PDO Secure Queries | Attendance Table Only
 *******************************************************************************************/
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Access Control
if (!isset($_SESSION['user']) || strtolower($_SESSION['user']['role']) !== 'parent') {
  session_destroy();
  header("Location: ../login.php?error=unauthorized");
  exit;
}

$parent_id   = $_SESSION['user']['linked_id'] ?? null;
$parent_name = $_SESSION['user']['username'] ?? 'Parent';
date_default_timezone_set('Africa/Nairobi');

// ✅ Fetch children
$children_stmt = $pdo->prepare("
  SELECT s.student_id, s.full_name, s.reg_no
  FROM students s
  WHERE s.parent_id=?
  ORDER BY s.full_name
");
$children_stmt->execute([$parent_id]);
$children = $children_stmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ Filters
$selected_student = $_POST['student_id'] ?? ($children[0]['student_id'] ?? null);
$date_from = $_POST['date_from'] ?? date('Y-m-01');
$date_to   = $_POST['date_to'] ?? date('Y-m-d');

// ✅ Fetch attendance records
$attendance_records = [];
if ($selected_student) {
  $stmt = $pdo->prepare("
    SELECT a.attendance_date, subj.subject_name, cs.section_name, a.status
    FROM attendance a
    LEFT JOIN subject subj ON subj.subject_id=a.subject_id
    LEFT JOIN class_section cs ON cs.section_id=a.section_id
    WHERE a.student_id=? AND a.attendance_date BETWEEN ? AND ?
    ORDER BY a.attendance_date DESC
  ");
  $stmt->execute([$selected_student, $date_from, $date_to]);
  $attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

include('../includes/header.php');
?>

<style>
:root {
  --green:#00843D;
  --blue:#0072CE;
  --light:#F5F9F7;
  --red:#C62828;
  --amber:#FFB400;
  --white:#FFFFFF;
}
body{font-family:'Poppins',sans-serif;background:var(--light);margin:0;}
.main-content{padding:25px;margin-left:250px;margin-top:90px;}
.sidebar.collapsed ~ .main-content{margin-left:70px;}
.page-header h1{color:var(--blue);}
.filter-box{background:#fff;padding:20px;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,0.08);margin-bottom:20px;}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;}
label{font-weight:600;color:var(--blue);font-size:13px;}
select,input{width:100%;padding:8px;border:1px solid #ccc;border-radius:6px;background:#fafafa;}
.btn{border:none;padding:9px 15px;border-radius:6px;font-weight:600;cursor:pointer;background:var(--green);color:#fff;}
.btn:hover{opacity:0.9;}
.table-wrapper{overflow:auto;background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.08);}
table{width:100%;border-collapse:collapse;font-size:14px;}
th,td{padding:10px;border-bottom:1px solid #eee;text-align:left;}
thead th{background:var(--blue);color:#fff;}
tr:hover{background:#f9f9f9;}
.status{font-weight:700;text-transform:capitalize;}
.status.present{color:var(--green);}
.status.absent{color:var(--red);}
.status.late{color:var(--amber);}
.status.excused{color:var(--blue);}
@media(max-width:768px){.main-content{margin-left:0;padding:15px;}}
</style>

<div class="main-content">
  <div class="page-header">
    <h1>🗓️ Student Attendance</h1>
    <p>Welcome, <strong><?= htmlspecialchars($parent_name) ?></strong>. View your child's attendance below.</p>
  </div>

  <!-- ✅ Filter Form -->
  <div class="filter-box">
    <form method="POST">
      <div class="grid">
        <div>
          <label>Select Child</label>
          <select name="student_id" required>
            <?php foreach($children as $ch): ?>
              <option value="<?= $ch['student_id'] ?>" <?= $selected_student == $ch['student_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($ch['full_name']) ?> (<?= htmlspecialchars($ch['reg_no']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>From Date</label>
          <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" required>
        </div>
        <div>
          <label>To Date</label>
          <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" required>
        </div>
        <div style="align-self:end;">
          <button type="submit" class="btn"><i class="fa fa-search"></i> View</button>
        </div>
      </div>
    </form>
  </div>

  <!-- ✅ Attendance Table -->
  <div class="table-wrapper">
    <table>
      <thead>
        <tr><th>Date</th><th>Subject</th><th>Section</th><th>Status</th></tr>
      </thead>
      <tbody>
        <?php if($attendance_records): foreach($attendance_records as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['attendance_date']) ?></td>
            <td><?= htmlspecialchars($r['subject_name'] ?? '-') ?></td>
            <td><?= htmlspecialchars($r['section_name'] ?? '-') ?></td>
            <td><span class="status <?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars(ucfirst($r['status'])) ?></span></td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="4" style="text-align:center;color:#777;">No attendance records found for selected range.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="../assets/js/sidebar.js"></script>
<?php include('../includes/footer.php'); ?>
