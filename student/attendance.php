<?php
/*******************************************************************************************
 * STUDENT PORTAL — Attendance Records
 * Role: Student
 * Author: GPT-5 | PHP 8.2 | PDO Secure Queries | Responsive Modern Design
 *******************************************************************************************/
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Access Control
if (!isset($_SESSION['user']) || strtolower($_SESSION['user']['role']) !== 'student') {
  session_destroy();
  header("Location: ../login.php?error=unauthorized");
  exit;
}

$student_id   = $_SESSION['user']['linked_id'] ?? null;
$student_name = $_SESSION['user']['username'] ?? 'Student';
date_default_timezone_set('Africa/Nairobi');

/* =============================== FETCH STUDENT INFO =============================== */
$stmt = $pdo->prepare("
  SELECT s.full_name, s.reg_no, cs.section_id, cs.section_name, p.program_name
  FROM students s
  LEFT JOIN class_section cs ON s.section_id = cs.section_id
  LEFT JOIN programs p ON cs.program_id = p.program_id
  WHERE s.student_id = ?
");
$stmt->execute([$student_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);
$section_id = $student['section_id'] ?? null;

/* =============================== FILTERS =============================== */
$date_from = $_POST['date_from'] ?? date('Y-m-01');
$date_to   = $_POST['date_to'] ?? date('Y-m-d');
$subject_id = $_POST['subject_id'] ?? '';

/* =============================== SUBJECT LIST =============================== */
$subjects_stmt = $pdo->prepare("
  SELECT subj.subject_id, subj.subject_name
  FROM student_enroll e
  JOIN subject subj ON subj.subject_id = e.subject_id
  WHERE e.student_id = ?
  ORDER BY subj.subject_name
");
$subjects_stmt->execute([$student_id]);
$subjects = $subjects_stmt->fetchAll(PDO::FETCH_ASSOC);

/* =============================== ATTENDANCE RECORDS =============================== */
$sql = "
  SELECT a.attendance_date, subj.subject_name, a.status, t.teacher_name, cs.section_name
  FROM attendance a
  LEFT JOIN subject subj ON subj.subject_id = a.subject_id
  LEFT JOIN teachers t ON t.teacher_id = a.teacher_id
  LEFT JOIN class_section cs ON cs.section_id = a.section_id
  WHERE a.student_id = ? AND a.attendance_date BETWEEN ? AND ?
";
$params = [$student_id, $date_from, $date_to];

if (!empty($subject_id)) {
  $sql .= " AND a.subject_id = ?";
  $params[] = $subject_id;
}

$sql .= " ORDER BY a.attendance_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
.no-data{text-align:center;color:#777;font-size:15px;margin-top:40px;}
@media(max-width:768px){.main-content{margin-left:0;padding:15px;}}
</style>

<div class="main-content">
  <div class="page-header">
    <h1>🗓️ My Attendance</h1>
    <p>Welcome, <strong><?= htmlspecialchars($student_name) ?></strong>. Check your attendance records below.</p>
  </div>

  <!-- ✅ Filter Form -->
  <div class="filter-box">
    <form method="POST">
      <div class="grid">
        <div>
          <label>From Date</label>
          <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" required>
        </div>
        <div>
          <label>To Date</label>
          <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" required>
        </div>
        <div>
          <label>Subject</label>
          <select name="subject_id">
            <option value="">All Subjects</option>
            <?php foreach($subjects as $s): ?>
              <option value="<?= $s['subject_id'] ?>" <?= ($subject_id == $s['subject_id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($s['subject_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="align-self:end;">
          <button type="submit" class="btn"><i class="fa fa-search"></i> Filter</button>
        </div>
      </div>
    </form>
  </div>

  <!-- ✅ Attendance Table -->
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Date</th>
          <th>Subject</th>
          <th>Teacher</th>
          <th>Section</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if($records): foreach($records as $r): ?>
          <tr>
            <td><?= htmlspecialchars(date('M d, Y', strtotime($r['attendance_date']))) ?></td>
            <td><?= htmlspecialchars($r['subject_name'] ?? '-') ?></td>
            <td><?= htmlspecialchars($r['teacher_name'] ?? '-') ?></td>
            <td><?= htmlspecialchars($r['section_name'] ?? '-') ?></td>
            <td><span class="status <?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars(ucfirst($r['status'])) ?></span></td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="5" class="no-data">No attendance records found for this range.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="../assets/js/sidebar.js"></script>
<?php include('../includes/footer.php'); ?>
