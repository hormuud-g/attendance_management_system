<?php
/*******************************************************************************************
 * PARENT PORTAL — Student Progress Page
 * Role: Parent | Shows child-wise attendance progress
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

// ✅ Fetch children
$children_stmt = $pdo->prepare("
  SELECT s.student_id, s.full_name, s.reg_no, cs.section_name, p.program_name, f.faculty_name
  FROM students s
  LEFT JOIN class_section cs ON s.section_id=cs.section_id
  LEFT JOIN programs p ON cs.program_id=p.program_id
  LEFT JOIN departments d ON p.department_id=d.department_id
  LEFT JOIN faculties f ON d.faculty_id=f.faculty_id
  WHERE s.parent_id=?
  ORDER BY s.full_name
");
$children_stmt->execute([$parent_id]);
$children = $children_stmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ Calculate progress (attendance ratio)
$progress_data = [];
foreach ($children as $child) {
  $stmt = $pdo->prepare("
    SELECT subj.subject_name,
           COUNT(*) AS total,
           SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) AS present
    FROM attendance a
    JOIN subject subj ON subj.subject_id=a.subject_id
    WHERE a.student_id=?
    GROUP BY subj.subject_id
    ORDER BY subj.subject_name
  ");
  $stmt->execute([$child['student_id']]);
  $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $total_classes = 0;
  $total_present = 0;
  foreach ($subjects as $s) {
    $total_classes += $s['total'];
    $total_present += $s['present'];
  }

  $overall_percentage = $total_classes > 0 ? round(($total_present / $total_classes) * 100, 1) : 0;
  $progress_data[$child['student_id']] = [
    'subjects' => $subjects,
    'overall'  => $overall_percentage
  ];
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
.child-card{background:#fff;border-radius:15px;padding:20px;margin-bottom:25px;box-shadow:0 2px 10px rgba(0,0,0,0.08);}
.child-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;}
.child-header h2{margin:0;color:var(--green);}
.progress-bar{height:12px;border-radius:6px;background:#e0e0e0;margin-top:5px;overflow:hidden;}
.progress-fill{height:100%;border-radius:6px;transition:width .5s;}
.table-wrapper{overflow:auto;background:#fff;border-radius:10px;margin-top:10px;}
table{width:100%;border-collapse:collapse;font-size:14px;}
th,td{padding:10px;border-bottom:1px solid #eee;text-align:left;}
thead th{background:var(--blue);color:#fff;}
tr:hover{background:#f9f9f9;}
.overall{font-size:18px;font-weight:700;margin-top:10px;}
.overall span{padding:3px 10px;border-radius:8px;color:#fff;}
.section h2{color:var(--green);}
@media(max-width:768px){.main-content{margin-left:0;padding:15px;}.child-header{flex-direction:column;align-items:flex-start;gap:5px;}}
</style>

<div class="main-content">
  <div class="page-header">
    <h1>📈 Student Progress</h1>
    <p>Welcome back, <strong><?= htmlspecialchars($parent_name) ?></strong>. Here’s how your children are performing.</p>
  </div>

  <?php if($children): foreach($children as $c): 
    $progress = $progress_data[$c['student_id']] ?? ['subjects'=>[], 'overall'=>0];
    $overall = $progress['overall'];
    $bar_color = $overall >= 80 ? 'var(--green)' : ($overall >= 50 ? 'var(--amber)' : 'var(--red)');
  ?>
  <div class="child-card">
    <div class="child-header">
      <h2><?= htmlspecialchars($c['full_name']) ?> <small style="font-size:13px;color:#777;">(<?= htmlspecialchars($c['reg_no']) ?>)</small></h2>
      <div class="overall">Overall: <span style="background:<?= $bar_color ?>;"><?= $overall ?>%</span></div>
    </div>

    <div class="progress-bar">
      <div class="progress-fill" style="width:<?= $overall ?>%;background:<?= $bar_color ?>"></div>
    </div>

    <p style="margin-top:10px;color:#555;">Section: <?= htmlspecialchars($c['section_name'] ?? '-') ?> | 
       Program: <?= htmlspecialchars($c['program_name'] ?? '-') ?> | 
       Faculty: <?= htmlspecialchars($c['faculty_name'] ?? '-') ?></p>

    <div class="table-wrapper">
      <table>
        <thead>
          <tr><th>Subject</th><th>Total Classes</th><th>Present</th><th>Attendance %</th></tr>
        </thead>
        <tbody>
          <?php if($progress['subjects']): foreach($progress['subjects'] as $s): 
            $percent = $s['total'] > 0 ? round(($s['present'] / $s['total']) * 100, 1) : 0;
            $color = $percent >= 80 ? 'var(--green)' : ($percent >= 50 ? 'var(--amber)' : 'var(--red)');
          ?>
          <tr>
            <td><?= htmlspecialchars($s['subject_name']) ?></td>
            <td><?= $s['total'] ?></td>
            <td><?= $s['present'] ?></td>
            <td><strong style="color:<?= $color ?>;"><?= $percent ?>%</strong></td>
          </tr>
          <?php endforeach; else: ?>
          <tr><td colspan="4" style="text-align:center;color:#777;">No attendance data available.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endforeach; else: ?>
  <p style="color:#777;">No children linked to your account.</p>
  <?php endif; ?>
</div>

<script src="../assets/js/sidebar.js"></script>
<?php include('../includes/footer.php'); ?>
