<?php
/*******************************************************************************************
 * PARENT PORTAL — FULL REPORT PAGE
 * Role: Parent
 * Author: GPT-5 | PHP 8.2 | PDO Secure | Chart.js Visuals
 *******************************************************************************************/
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Access control
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

// ✅ Calculate attendance summary for each child
$report_data = [];
foreach ($children as $child) {
  $stmt = $pdo->prepare("
    SELECT 
      COUNT(*) AS total,
      SUM(CASE WHEN status='present' THEN 1 ELSE 0 END) AS present,
      SUM(CASE WHEN status='absent' THEN 1 ELSE 0 END) AS absent,
      SUM(CASE WHEN status='late' THEN 1 ELSE 0 END) AS late,
      SUM(CASE WHEN status='excused' THEN 1 ELSE 0 END) AS excused
    FROM attendance WHERE student_id=?
  ");
  $stmt->execute([$child['student_id']]);
  $summary = $stmt->fetch(PDO::FETCH_ASSOC);

  $summary['percentage'] = ($summary['total'] ?? 0) > 0 
    ? round(($summary['present'] / $summary['total']) * 100, 1) 
    : 0;

  // Subject breakdown
  $subject_stmt = $pdo->prepare("
    SELECT subj.subject_name,
           COUNT(*) AS total,
           SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) AS present,
           SUM(CASE WHEN a.status='absent' THEN 1 ELSE 0 END) AS absent
    FROM attendance a
    JOIN subject subj ON subj.subject_id=a.subject_id
    WHERE a.student_id=?
    GROUP BY subj.subject_id
    ORDER BY subj.subject_name
  ");
  $subject_stmt->execute([$child['student_id']]);
  $subjects = $subject_stmt->fetchAll(PDO::FETCH_ASSOC);

  $report_data[$child['student_id']] = [
    'summary'  => $summary,
    'subjects' => $subjects
  ];
}

// ✅ Announcements (general)
$announcements = $pdo->query("
  SELECT title, message, created_at FROM announcement
  WHERE target_role IN ('parent','all_users') AND status='active'
  ORDER BY created_at DESC LIMIT 5
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
.child-card{background:#fff;border-radius:15px;box-shadow:0 2px 10px rgba(0,0,0,0.08);padding:20px;margin-bottom:30px;}
.child-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;}
.child-header h2{margin:0;color:var(--green);}
.summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(100px,1fr));gap:10px;margin-bottom:10px;}
.summary .stat{background:var(--light);padding:10px;border-radius:8px;text-align:center;}
.summary .stat h4{margin:0;font-size:13px;color:#555;}
.summary .stat p{margin:5px 0 0;font-weight:700;font-size:18px;}
.table-wrapper{overflow:auto;background:#fff;border-radius:10px;margin-top:10px;border:1px solid #eee;}
table{width:100%;border-collapse:collapse;font-size:14px;}
th,td{padding:10px;border-bottom:1px solid #eee;text-align:left;}
thead th{background:var(--blue);color:#fff;}
tr:hover{background:#f9f9f9;}
.overall{font-size:18px;font-weight:700;}
.overall span{padding:3px 10px;border-radius:8px;color:#fff;}
.section h2{color:var(--green);}
.announcements{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;}
.ann-card{background:#fff;border-radius:10px;padding:15px;box-shadow:0 2px 8px rgba(0,0,0,0.08);}
.ann-card h4{color:var(--blue);margin:0 0 5px;}
.ann-card p{color:#555;font-size:13px;}
.ann-card small{color:#999;font-size:12px;}
.chart-container{position:relative;height:200px;margin-top:10px;}
@media(max-width:768px){.main-content{margin-left:0;padding:15px;}.child-header{flex-direction:column;align-items:flex-start;gap:5px;}}
</style>

<div class="main-content">
  <div class="page-header">
    <h1>📊 Attendance Report</h1>
    <p>Hello, <strong><?= htmlspecialchars($parent_name) ?></strong>. Here’s a detailed attendance report for your children.</p>
  </div>

  <?php if($children): foreach($children as $c): 
    $r = $report_data[$c['student_id']]['summary'] ?? ['total'=>0,'present'=>0,'absent'=>0,'late'=>0,'excused'=>0,'percentage'=>0];
    $bar_color = $r['percentage'] >= 80 ? 'var(--green)' : ($r['percentage'] >= 50 ? 'var(--amber)' : 'var(--red)');
  ?>
  <div class="child-card">
    <div class="child-header">
      <h2><?= htmlspecialchars($c['full_name']) ?> <small style="color:#777;font-size:13px;">(<?= htmlspecialchars($c['reg_no']) ?>)</small></h2>
      <div class="overall">Overall: <span style="background:<?= $bar_color ?>;"><?= $r['percentage'] ?>%</span></div>
    </div>

    <div class="summary">
      <div class="stat"><h4>Total</h4><p><?= $r['total'] ?></p></div>
      <div class="stat"><h4 style="color:var(--green)">Present</h4><p><?= $r['present'] ?></p></div>
      <div class="stat"><h4 style="color:var(--red)">Absent</h4><p><?= $r['absent'] ?></p></div>
      <div class="stat"><h4 style="color:var(--amber)">Late</h4><p><?= $r['late'] ?></p></div>
      <div class="stat"><h4 style="color:var(--blue)">Excused</h4><p><?= $r['excused'] ?></p></div>
    </div>

    <!-- ✅ Attendance Chart -->
    <div class="chart-container">
      <canvas id="chart_<?= $c['student_id'] ?>"></canvas>
    </div>

    <!-- ✅ Subject Breakdown -->
    <div class="table-wrapper" style="margin-top:15px;">
      <table>
        <thead><tr><th>Subject</th><th>Total</th><th>Present</th><th>Absent</th><th>Attendance %</th></tr></thead>
        <tbody>
          <?php foreach(($report_data[$c['student_id']]['subjects'] ?? []) as $s): 
            $pct = $s['total']>0 ? round(($s['present']/$s['total'])*100,1) : 0;
            $color = $pct>=80?'var(--green)':($pct>=50?'var(--amber)':'var(--red)');
          ?>
          <tr>
            <td><?= htmlspecialchars($s['subject_name']) ?></td>
            <td><?= $s['total'] ?></td>
            <td><?= $s['present'] ?></td>
            <td><?= $s['absent'] ?></td>
            <td><strong style="color:<?= $color ?>;"><?= $pct ?>%</strong></td>
          </tr>
          <?php endforeach; if(empty($report_data[$c['student_id']]['subjects'])): ?>
          <tr><td colspan="5" style="text-align:center;color:#777;">No attendance data available.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endforeach; else: ?>
    <p style="color:#777;">No children linked to your account.</p>
  <?php endif; ?>

  <!-- ✅ Announcements -->
  <div class="section">
    <h2>📢 Recent Announcements</h2>
    <div class="announcements">
      <?php if($announcements): foreach($announcements as $a): ?>
      <div class="ann-card">
        <h4><?= htmlspecialchars($a['title']) ?></h4>
        <p><?= htmlspecialchars(substr($a['message'],0,150)) ?><?= strlen($a['message'])>150?'...':'' ?></p>
        <small><i class="fa fa-calendar"></i> <?= date('M d, Y h:i A', strtotime($a['created_at'])) ?></small>
      </div>
      <?php endforeach; else: ?>
        <p style="color:#777;">No announcements found.</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ✅ Scripts -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
<?php foreach($children as $c): 
  $r = $report_data[$c['student_id']]['summary'] ?? ['present'=>0,'absent'=>0,'late'=>0,'excused'=>0];
?>
const ctx<?= $c['student_id'] ?> = document.getElementById('chart_<?= $c['student_id'] ?>');
new Chart(ctx<?= $c['student_id'] ?>, {
  type: 'doughnut',
  data: {
    labels: ['Present','Absent','Late','Excused'],
    datasets: [{
      data: [<?= $r['present'] ?>, <?= $r['absent'] ?>, <?= $r['late'] ?>, <?= $r['excused'] ?>],
      backgroundColor: ['#00843D','#C62828','#FFB400','#0072CE'],
      borderWidth: 1
    }]
  },
  options: {
    plugins: {legend:{position:'bottom'}},
    cutout:'70%'
  }
});
<?php endforeach; ?>
</script>

<script src="../assets/js/sidebar.js"></script>
<?php include('../includes/footer.php'); ?>
