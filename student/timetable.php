<?php
/*******************************************************************************************
 * STUDENT PORTAL — My Timetable
 * Role: Student
 * Author: GPT-5 | PHP 8.2 | PDO Secure Queries | Responsive Design
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

// ✅ Get Student Section
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

/* =============================== FETCH TIMETABLE =============================== */
$timetable = [];
if ($section_id) {
  $stmt = $pdo->prepare("
    SELECT t.day_of_week, t.start_time, t.end_time,
           subj.subject_name, subj.subject_code,
           tr.teacher_name, r.room_name
    FROM timetable t
    JOIN subject subj ON subj.subject_id = t.subject_id
    LEFT JOIN teachers tr ON tr.teacher_id = t.teacher_id
    LEFT JOIN rooms r ON r.room_id = t.room_id
    WHERE t.section_id = ? AND t.status='active'
    ORDER BY FIELD(t.day_of_week,'Mon','Tue','Wed','Thu','Fri','Sat','Sun'), t.start_time
  ");
  $stmt->execute([$section_id]);
  $timetable = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
.info-card{background:#fff;padding:20px;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,0.08);margin-bottom:20px;}
.table-wrapper{background:#fff;border-radius:12px;padding:15px;box-shadow:0 2px 8px rgba(0,0,0,0.08);}
table{width:100%;border-collapse:collapse;}
th,td{padding:10px 12px;border-bottom:1px solid #eee;text-align:left;font-size:14px;}
thead th{background:var(--blue);color:#fff;text-transform:uppercase;}
tr:hover{background:#f3f8ff;}
.no-data{text-align:center;color:#777;font-size:15px;margin-top:40px;}
@media(max-width:768px){.main-content{margin-left:0;padding:15px;table{font-size:12px;}}}
</style>

<div class="main-content">
  <div class="page-header">
    <h1>🗓 My Timetable</h1>
  </div>

  <!-- ✅ Student Info -->
  <div class="info-card">
    <h3><?= htmlspecialchars($student['full_name'] ?? 'Unknown Student') ?> (<?= htmlspecialchars($student['reg_no'] ?? '-') ?>)</h3>
    <p>Section: <?= htmlspecialchars($student['section_name'] ?? '-') ?> | Program: <?= htmlspecialchars($student['program_name'] ?? '-') ?></p>
  </div>

  <!-- ✅ Timetable -->
  <div class="table-wrapper">
    <?php if($timetable): ?>
      <table>
        <thead>
          <tr>
            <th>Day</th>
            <th>Subject</th>
            <th>Code</th>
            <th>Teacher</th>
            <th>Room</th>
            <th>Time</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($timetable as $row): ?>
            <tr>
              <td><strong><?= htmlspecialchars($row['day_of_week']) ?></strong></td>
              <td><?= htmlspecialchars($row['subject_name']) ?></td>
              <td><?= htmlspecialchars($row['subject_code']) ?></td>
              <td><?= htmlspecialchars($row['teacher_name'] ?? 'TBA') ?></td>
              <td><?= htmlspecialchars($row['room_name'] ?? '-') ?></td>
              <td><?= date('h:i A', strtotime($row['start_time'])) . ' - ' . date('h:i A', strtotime($row['end_time'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <div class="no-data"><i class="fa fa-info-circle"></i> No timetable available for your section.</div>
    <?php endif; ?>
  </div>
</div>

<script src="../assets/js/sidebar.js"></script>
<?php include('../includes/footer.php'); ?>
