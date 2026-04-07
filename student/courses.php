<?php
/*******************************************************************************************
 * STUDENT PORTAL — My Courses (Fixed for your DB schema)
 * Role: Student
 * Author: GPT-5 | PHP 8.2 | PDO Secure Queries | Fixed JOINs (No subj.teacher_id)
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

/* =============================== STUDENT INFO =============================== */
$stmt = $pdo->prepare("
  SELECT s.full_name, s.reg_no, cs.section_name, p.program_name
  FROM students s
  LEFT JOIN class_section cs ON s.section_id = cs.section_id
  LEFT JOIN programs p ON cs.program_id = p.program_id
  WHERE s.student_id = ?
");
$stmt->execute([$student_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

/* =============================== COURSES =============================== */
$courses_stmt = $pdo->prepare("
  SELECT subj.subject_id, subj.subject_name, subj.subject_code, subj.credit_hours,
         t.teacher_name AS teacher_name, cs.section_name
  FROM student_enroll e
  JOIN subject subj ON subj.subject_id = e.subject_id
  LEFT JOIN class_section cs ON e.section_id = cs.section_id
  LEFT JOIN timetable tt ON tt.subject_id = subj.subject_id AND tt.section_id = e.section_id
  LEFT JOIN teachers t ON t.teacher_id = tt.teacher_id
  WHERE e.student_id = ?
  GROUP BY subj.subject_id, subj.subject_name, subj.subject_code, subj.credit_hours,
           t.teacher_name, cs.section_name
  ORDER BY subj.subject_name
");
$courses_stmt->execute([$student_id]);
$courses = $courses_stmt->fetchAll(PDO::FETCH_ASSOC);

/* =============================== ANNOUNCEMENTS =============================== */
$announcements = $pdo->query("
  SELECT title, message, created_at, target_role
  FROM announcement
  WHERE status='active'
    AND target_role IN ('student','all')
  ORDER BY created_at DESC
  LIMIT 3
")->fetchAll(PDO::FETCH_ASSOC);

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
.course-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;}
.course-card{background:#fff;border-radius:12px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,0.08);}
.course-card h3{color:var(--green);margin:0 0 10px;}
.course-card p{margin:4px 0;color:#555;font-size:14px;}
.announcements{margin-top:30px;}
.ann-card{background:#fff;border-radius:10px;padding:15px;box-shadow:0 2px 8px rgba(0,0,0,0.08);margin-bottom:10px;}
.ann-card h4{color:var(--blue);margin:0 0 5px;}
.ann-card p{color:#555;font-size:13px;}
.ann-card small{color:#999;font-size:12px;}
.no-data{text-align:center;color:#777;font-size:15px;margin-top:40px;}
@media(max-width:768px){.main-content{margin-left:0;padding:15px;}}
</style>

<div class="main-content">
  <div class="page-header">
    <h1>📚 My Courses</h1>
  </div>

  <!-- ✅ Student Info -->
  <div class="info-card">
    <h3><?= htmlspecialchars($student['full_name'] ?? 'Unknown Student') ?> (<?= htmlspecialchars($student['reg_no'] ?? '-') ?>)</h3>
    <p>Section: <?= htmlspecialchars($student['section_name'] ?? '-') ?> | Program: <?= htmlspecialchars($student['program_name'] ?? '-') ?></p>
  </div>

  <!-- ✅ Courses -->
  <?php if($courses): ?>
  <div class="course-list">
    <?php foreach($courses as $c): ?>
      <div class="course-card">
        <h3><?= htmlspecialchars($c['subject_name']) ?> 
          <small style="color:#777;">(<?= htmlspecialchars($c['subject_code']) ?>)</small>
        </h3>
        <p><strong>Credit Hours:</strong> <?= htmlspecialchars($c['credit_hours'] ?? 'N/A') ?></p>
        <p><strong>Teacher:</strong> <?= htmlspecialchars($c['teacher_name'] ?? 'TBA') ?></p>
        <p><strong>Section:</strong> <?= htmlspecialchars($c['section_name'] ?? '-') ?></p>
      </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
    <div class="no-data"><i class="fa fa-info-circle"></i> You are not enrolled in any courses yet.</div>
  <?php endif; ?>

  <!-- ✅ Announcements -->
  <div class="announcements">
    <h2 style="color:var(--blue);margin-top:25px;">📢 Latest Announcements</h2>
    <?php if($announcements): foreach($announcements as $a): ?>
      <div class="ann-card">
        <h4><?= htmlspecialchars($a['title']) ?></h4>
        <p><?= htmlspecialchars(substr($a['message'],0,150)) ?><?= strlen($a['message'])>150?'...':'' ?></p>
        <small><i class="fa fa-calendar"></i> <?= date('M d, Y h:i A', strtotime($a['created_at'])) ?> |
               <strong><?= strtoupper($a['target_role']) ?></strong></small>
      </div>
    <?php endforeach; else: ?>
      <p style="color:#777;">No announcements found.</p>
    <?php endif; ?>
  </div>
</div>

<script src="../assets/js/sidebar.js"></script>
<?php include('../includes/footer.php'); ?>
