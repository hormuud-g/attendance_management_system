<?php
/*******************************************************************************************
 * PARENT PORTAL — Dashboard (Final Model)
 * Role: Parent
 * Author: GPT-5 | PHP 8.2 | PDO Secure Queries
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

/* =============================== CHILDREN =============================== */
$children_stmt = $pdo->prepare("
  SELECT s.student_id, s.full_name, s.reg_no, cs.section_name, p.program_name
  FROM students s
  LEFT JOIN class_section cs ON s.section_id = cs.section_id
  LEFT JOIN programs p ON cs.program_id = p.program_id
  WHERE s.parent_id = ?
  ORDER BY s.full_name
");
$children_stmt->execute([$parent_id]);
$children = $children_stmt->fetchAll(PDO::FETCH_ASSOC);

/* =============================== STATISTICS =============================== */
$total_children = count($children);
$total_present = $total_absent = $total_late = $total_excused = 0;

foreach ($children as $ch) {
  $q = $pdo->prepare("
    SELECT 
      SUM(CASE WHEN status='present' THEN 1 ELSE 0 END) AS present,
      SUM(CASE WHEN status='absent' THEN 1 ELSE 0 END) AS absent,
      SUM(CASE WHEN status='late' THEN 1 ELSE 0 END) AS late,
      SUM(CASE WHEN status='excused' THEN 1 ELSE 0 END) AS excused
    FROM attendance WHERE student_id=?
  ");
  $q->execute([$ch['student_id']]);
  $r = $q->fetch(PDO::FETCH_ASSOC);

  $total_present += $r['present'] ?? 0;
  $total_absent  += $r['absent'] ?? 0;
  $total_late    += $r['late'] ?? 0;
  $total_excused += $r['excused'] ?? 0;
}

/* =============================== ANNOUNCEMENTS =============================== */
$announcements = $pdo->query("
  SELECT title, message, created_at, target_role
  FROM announcement
  WHERE status='active'
  ORDER BY created_at DESC
  LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

include('../includes/header.php');
?>

<style>
:root{
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
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:20px;margin-bottom:30px;}
.card{background:#fff;padding:20px;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,0.08);}
.card h3{margin:0;font-size:16px;color:#555;}
.card p{font-size:28px;font-weight:700;margin-top:8px;}
.green{color:var(--green);} .red{color:var(--red);}
.blue{color:var(--blue);} .amber{color:var(--amber);}
.child-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;margin-top:20px;}
.child-card{background:#fff;padding:20px;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,0.05);}
.child-card h4{margin:0;color:var(--green);}
.child-card small{color:#777;}
.announcements{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;margin-top:20px;}
.ann-card{background:#fff;padding:15px;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.08);}
.ann-card h4{color:var(--blue);margin:0 0 5px;}
.ann-card p{color:#555;font-size:13px;}
.ann-card small{color:#999;font-size:12px;}
@media(max-width:768px){.main-content{margin-left:0;padding:15px;}}
</style>

<div class="main-content">
  <div class="page-header">
    <h1>👨‍👩‍👧 Parent Dashboard</h1>
    <p>Welcome, <strong><?= htmlspecialchars($parent_name) ?></strong>. Here’s an overview of your children’s attendance and school updates.</p>
  </div>

  <!-- ✅ Stats Summary -->
  <div class="cards">
    <div class="card"><h3>My Children</h3><p class="blue"><?= $total_children ?></p></div>
    <div class="card"><h3>Present</h3><p class="green"><?= $total_present ?></p></div>
    <div class="card"><h3>Absent</h3><p class="red"><?= $total_absent ?></p></div>
    <div class="card"><h3>Late</h3><p class="amber"><?= $total_late ?></p></div>
  </div>

  <!-- ✅ Children List -->
  <h2 style="color:var(--green)">👧 Your Children</h2>
  <?php if($children): ?>
    <div class="child-list">
      <?php foreach($children as $c): ?>
        <div class="child-card">
          <h4><?= htmlspecialchars($c['full_name']) ?></h4>
          <small>Reg No: <?= htmlspecialchars($c['reg_no']) ?></small><br>
          <small>Section: <?= htmlspecialchars($c['section_name'] ?? '-') ?></small><br>
          <small>Program: <?= htmlspecialchars($c['program_name'] ?? '-') ?></small>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p style="color:#777;">No children linked to your account.</p>
  <?php endif; ?>

  <!-- ✅ Announcements -->
  <h2 style="color:var(--blue);margin-top:30px;">📢 Recent Announcements</h2>
  <div class="announcements">
    <?php if($announcements): foreach($announcements as $a): ?>
      <div class="ann-card">
        <h4><?= htmlspecialchars($a['title']) ?></h4>
        <p><?= htmlspecialchars(substr($a['message'],0,150)) ?><?= strlen($a['message'])>150?'...':'' ?></p>
        <small><i class="fa fa-calendar"></i> <?= date('M d, Y h:i A', strtotime($a['created_at'])) ?> |
               <strong><?= strtoupper($a['target_role']) ?></strong></small>
      </div>
    <?php endforeach; else: ?>
      <p style="color:#777;">No announcements available.</p>
    <?php endif; ?>
  </div>
</div>

<script src="../assets/js/sidebar.js"></script>
<?php include('../includes/footer.php'); ?>
