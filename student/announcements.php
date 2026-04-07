<?php
/*******************************************************************************************
 * STUDENT PORTAL — Announcements Page
 * Role: Student
 * Author: GPT-5 | PHP 8.2 | PDO Secure Queries | Modern Card UI
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
  SELECT s.full_name, s.reg_no, s.section_id, cs.section_name, p.program_name
  FROM students s
  LEFT JOIN class_section cs ON s.section_id = cs.section_id
  LEFT JOIN programs p ON cs.program_id = p.program_id
  WHERE s.student_id = ?
");
$stmt->execute([$student_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);
$section_id = $student['section_id'] ?? null;

/* =============================== FETCH ANNOUNCEMENTS =============================== */
$stmt = $pdo->prepare("
  SELECT a.announcement_id, a.title, a.message, a.created_at, a.target_role, a.section_id,
         t.teacher_name AS posted_by
  FROM announcement a
  LEFT JOIN teachers t ON a.created_by = t.teacher_id
  WHERE a.status='active'
    AND (
      a.target_role IN ('student','all')
      OR a.section_id = ?
    )
  ORDER BY a.created_at DESC
");
$stmt->execute([$section_id]);
$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
.ann-container{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;}
.ann-card{background:#fff;border-radius:12px;padding:20px;box-shadow:0 2px 10px rgba(0,0,0,0.08);position:relative;}
.ann-card h3{color:var(--blue);margin-bottom:8px;font-size:18px;}
.ann-card p{color:#444;font-size:14px;line-height:1.5;margin-bottom:10px;}
.ann-card small{color:#777;font-size:12px;}
.ann-card .meta{margin-top:8px;font-size:12px;color:#888;}
.ann-card .badge{display:inline-block;padding:4px 8px;border-radius:6px;font-size:11px;font-weight:600;color:#fff;}
.badge.student{background:var(--green);}
.badge.all{background:var(--amber);}
.badge.section{background:var(--blue);}
.no-data{text-align:center;color:#777;font-size:15px;margin-top:40px;}
@media(max-width:768px){.main-content{margin-left:0;padding:15px;}}
</style>

<div class="main-content">
  <div class="page-header">
    <h1>📢 Announcements</h1>
    <p>Welcome, <strong><?= htmlspecialchars($student_name) ?></strong>. Here are the latest announcements for you.</p>
  </div>

  <!-- ✅ Student Info -->
  <div class="info-card">
    <h3><?= htmlspecialchars($student['full_name'] ?? 'Unknown Student') ?></h3>
    <p>Reg No: <?= htmlspecialchars($student['reg_no'] ?? '-') ?> |
       Section: <?= htmlspecialchars($student['section_name'] ?? '-') ?> |
       Program: <?= htmlspecialchars($student['program_name'] ?? '-') ?></p>
  </div>

  <!-- ✅ Announcements List -->
  <?php if($announcements): ?>
    <div class="ann-container">
      <?php foreach($announcements as $a): ?>
        <?php
          $badge = 'all';
          if (strtolower($a['target_role']) === 'student') $badge = 'student';
          elseif (!empty($a['section_id'])) $badge = 'section';
        ?>
        <div class="ann-card">
          <h3><?= htmlspecialchars($a['title']) ?></h3>
          <p><?= nl2br(htmlspecialchars(substr($a['message'],0,400))) ?><?= strlen($a['message'])>400?'...':'' ?></p>
          <div class="meta">
            <i class="fa fa-calendar"></i> <?= date('M d, Y h:i A', strtotime($a['created_at'])) ?><br>
            <i class="fa fa-user"></i> Posted by: <?= htmlspecialchars($a['posted_by'] ?? 'Administrator') ?>
          </div>
          <div style="position:absolute;top:15px;right:15px;">
            <span class="badge <?= $badge ?>">
              <?= strtoupper($a['section_id'] ? 'SECTION' : $a['target_role']) ?>
            </span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="no-data"><i class="fa fa-info-circle"></i> No announcements available right now.</div>
  <?php endif; ?>
</div>

<script src="../assets/js/sidebar.js"></script>
<?php include('../includes/footer.php'); ?>
