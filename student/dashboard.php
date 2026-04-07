<?php
/*******************************************************************************************
 * STUDENT PORTAL — Dashboard (Modern & Animated)
 * Role: Student
 * Author: GPT-5 | PHP 8.2 | PDO Secure Queries | Chart.js Visualization
 *******************************************************************************************/
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Access control
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
  SELECT s.student_id, s.full_name, s.reg_no, cs.section_name, p.program_name, f.faculty_name
  FROM students s
  LEFT JOIN class_section cs ON s.section_id = cs.section_id
  LEFT JOIN programs p ON cs.program_id = p.program_id
  LEFT JOIN departments d ON p.department_id = d.department_id
  LEFT JOIN faculties f ON d.faculty_id = f.faculty_id
  WHERE s.student_id = ?
");
$stmt->execute([$student_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

/* =============================== ATTENDANCE SUMMARY =============================== */
$q = $pdo->prepare("
  SELECT 
    COUNT(*) AS total,
    SUM(CASE WHEN status='present' THEN 1 ELSE 0 END) AS present,
    SUM(CASE WHEN status='absent' THEN 1 ELSE 0 END) AS absent,
    SUM(CASE WHEN status='late' THEN 1 ELSE 0 END) AS late,
    SUM(CASE WHEN status='excused' THEN 1 ELSE 0 END) AS excused
  FROM attendance WHERE student_id=?
");
$q->execute([$student_id]);
$summary = $q->fetch(PDO::FETCH_ASSOC);

$total   = $summary['total'] ?? 0;
$present = $summary['present'] ?? 0;
$absent  = $summary['absent'] ?? 0;
$late    = $summary['late'] ?? 0;
$excused = $summary['excused'] ?? 0;
$percentage = $total > 0 ? round(($present / $total) * 100, 1) : 0;

/* =============================== SUBJECT-WISE ATTENDANCE =============================== */
$subject_stmt = $pdo->prepare("
  SELECT subj.subject_name,
         COUNT(*) AS total,
         SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) AS present,
         SUM(CASE WHEN a.status='absent' THEN 1 ELSE 0 END) AS absent
  FROM attendance a
  JOIN subject subj ON subj.subject_id = a.subject_id
  WHERE a.student_id=?
  GROUP BY subj.subject_id
  ORDER BY subj.subject_name
");
$subject_stmt->execute([$student_id]);
$subjects = $subject_stmt->fetchAll(PDO::FETCH_ASSOC);

/* =============================== ANNOUNCEMENTS =============================== */
$announcements = $pdo->query("
  SELECT title, message, created_at, target_role
  FROM announcement
  WHERE status='active'
    AND target_role IN ('student','all_users')
  ORDER BY created_at DESC
  LIMIT 4
")->fetchAll(PDO::FETCH_ASSOC);

include('../includes/header.php');
?>

<style>
:root {
  --green:#009E60;
  --blue:#0066CC;
  --light:#F5F9F7;
  --red:#E53935;
  --amber:#FFB400;
  --white:#FFFFFF;
  --gray:#666;
}
body {
  font-family:'Poppins',sans-serif;
  background:linear-gradient(135deg,#E8F5E9,#E3F2FD);
  margin:0;
  overflow-x:hidden;
  animation:fadeIn 1s ease;
}
@keyframes fadeIn {from{opacity:0;}to{opacity:1;}}
.main-content{padding:25px;margin-left:250px;margin-top:90px;transition:margin-left 0.3s ease;}
.sidebar.collapsed ~ .main-content{margin-left:70px;}
.page-header h1{color:var(--blue);margin-bottom:10px;animation:slideIn 0.8s ease;}
@keyframes slideIn {from{transform:translateY(-20px);opacity:0;}to{transform:translateY(0);opacity:1;}}
.welcome-text{color:var(--gray);margin-bottom:25px;}

.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;margin-bottom:35px;}
.card{
  background:#fff;padding:20px;border-radius:16px;
  box-shadow:0 6px 18px rgba(0,0,0,0.08);
  transition:transform .3s, box-shadow .3s;
  position:relative;overflow:hidden;
}
.card::after{
  content:"";position:absolute;inset:0;
  background:linear-gradient(135deg,rgba(0,132,61,.05),rgba(0,114,206,.05));
  opacity:0;transition:opacity .3s;
}
.card:hover{transform:translateY(-6px);box-shadow:0 8px 20px rgba(0,0,0,0.1);}
.card:hover::after{opacity:1;}
.card h3{margin:0;font-size:15px;color:#555;}
.card p{font-size:30px;font-weight:700;margin-top:8px;}
.green{color:var(--green);} .red{color:var(--red);} .blue{color:var(--blue);} .amber{color:var(--amber);}

.table-wrapper{overflow:auto;background:#fff;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,0.06);}
table{width:100%;border-collapse:collapse;font-size:14px;}
th,td{padding:10px;border-bottom:1px solid #eee;text-align:left;}
thead th{background:var(--blue);color:#fff;}
tr:hover{background:#f9f9f9;transition:background .3s;}

.chart-container{
  background:#fff;padding:20px;border-radius:16px;
  box-shadow:0 4px 12px rgba(0,0,0,0.08);
  position:relative;height:280px;margin-bottom:40px;
  animation:fadeUp 1s ease;
}
@keyframes fadeUp {from{transform:translateY(20px);opacity:0;}to{transform:translateY(0);opacity:1;}}

.announcements{
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(300px,1fr));
  gap:20px;margin-top:25px;
}
.ann-card{
  background:#fff;padding:18px;border-radius:12px;
  box-shadow:0 3px 10px rgba(0,0,0,0.06);
  transition:transform .3s;
}
.ann-card:hover{transform:translateY(-4px);}
.ann-card h4{color:var(--blue);margin:0 0 5px;}
.ann-card p{color:#555;font-size:13px;}
.ann-card small{color:#999;font-size:12px;}
.no-data{text-align:center;color:#888;font-size:14px;padding:20px;}
@media(max-width:768px){.main-content{margin-left:0;padding:15px;}}
</style>

<div class="main-content">
  <div class="page-header">
    <h1>🎓 Student Dashboard</h1>
    <p class="welcome-text">Welcome back, <strong><?= htmlspecialchars($student_name) ?></strong> 👋</p>
  </div>

  <!-- ✅ Student Info -->
  <?php if($student): ?>
  <div class="card" style="margin-bottom:25px;">
    <h3><?= htmlspecialchars($student['full_name']) ?> (<?= htmlspecialchars($student['reg_no']) ?>)</h3>
    <p style="font-size:14px;color:#666;">
      Section: <?= htmlspecialchars($student['section_name'] ?? '-') ?> | 
      Program: <?= htmlspecialchars($student['program_name'] ?? '-') ?> | 
      Faculty: <?= htmlspecialchars($student['faculty_name'] ?? '-') ?>
    </p>
  </div>
  <?php endif; ?>

  <!-- ✅ Attendance Summary -->
  <div class="cards">
    <div class="card"><h3>Total Classes</h3><p class="blue"><?= $total ?></p></div>
    <div class="card"><h3>Present</h3><p class="green"><?= $present ?></p></div>
    <div class="card"><h3>Absent</h3><p class="red"><?= $absent ?></p></div>
    <div class="card"><h3>Late</h3><p class="amber"><?= $late ?></p></div>
    <div class="card"><h3>Attendance %</h3><p class="<?= $percentage>=80?'green':($percentage>=50?'amber':'red') ?>"><?= $percentage ?>%</p></div>
  </div>

  <!-- ✅ Attendance Chart -->
  <div class="chart-container">
    <canvas id="attendanceChart"></canvas>
  </div>

  <!-- ✅ Subject-wise Attendance -->
  <h2 style="color:var(--green);margin-top:20px;">📘 Subject-wise Attendance</h2>
  <div class="chart-container">
    <canvas id="subjectChart"></canvas>
  </div>

  <!-- ✅ Announcements -->
  <h2 style="color:var(--blue);margin-top:35px;">📢 Latest Announcements</h2>
  <div class="announcements">
    <?php if($announcements): foreach($announcements as $a): ?>
      <div class="ann-card">
        <h4><?= htmlspecialchars($a['title']) ?></h4>
        <p><?= htmlspecialchars(substr($a['message'],0,150)) ?><?= strlen($a['message'])>150?'...':'' ?></p>
        <small><i class="fa fa-calendar"></i> <?= date('M d, Y h:i A', strtotime($a['created_at'])) ?> |
               <strong><?= strtoupper($a['target_role']) ?></strong></small>
      </div>
    <?php endforeach; else: ?>
      <p class="no-data">No announcements found.</p>
    <?php endif; ?>
  </div>
</div>

<!-- ✅ Chart Visualization -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
<script>
const ctx = document.getElementById('attendanceChart').getContext('2d');
const grad1 = ctx.createLinearGradient(0,0,0,200);
grad1.addColorStop(0,'#00C853'); grad1.addColorStop(1,'#009E60');
const grad2 = ctx.createLinearGradient(0,0,0,200);
grad2.addColorStop(0,'#FF5252'); grad2.addColorStop(1,'#C62828');
const grad3 = ctx.createLinearGradient(0,0,0,200);
grad3.addColorStop(0,'#FFC107'); grad3.addColorStop(1,'#FF9800');
const grad4 = ctx.createLinearGradient(0,0,0,200);
grad4.addColorStop(0,'#64B5F6'); grad4.addColorStop(1,'#1976D2');

new Chart(ctx, {
  type: 'doughnut',
  data: {
    labels: ['Present','Absent','Late','Excused'],
    datasets: [{
      data: [<?= $present ?>, <?= $absent ?>, <?= $late ?>, <?= $excused ?>],
      backgroundColor:[grad1,grad2,grad3,grad4],
      borderWidth:0, hoverOffset:12
    }]
  },
  options:{
    plugins:{
      legend:{position:'bottom',labels:{font:{family:'Poppins',size:13}}},
      datalabels:{
        color:'#fff',font:{weight:'bold',size:13},
        formatter:(v,ctx)=>((v/ctx.chart.data.datasets[0].data.reduce((a,b)=>a+b,0))*100).toFixed(0)+'%'
      }
    },
    cutout:'70%', animation:{animateRotate:true,duration:1500,easing:'easeOutBack'}
  },
  plugins:[ChartDataLabels]
});

// ✅ Subject Bar Chart
const subCtx = document.getElementById('subjectChart').getContext('2d');
const subjects = <?= json_encode(array_column($subjects,'subject_name')) ?>;
const subjData = <?= json_encode(array_map(fn($s)=>round(($s['total']>0?($s['present']/$s['total'])*100:0),1),$subjects)) ?>;

new Chart(subCtx, {
  type: 'bar',
  data: {
    labels: subjects,
    datasets: [{
      label: 'Attendance %',
      data: subjData,
      backgroundColor: '#0072CE',
      borderRadius: 8
    }]
  },
  options: {
    responsive:true,
    plugins:{
      legend:{display:false},
      datalabels:{
        color:'#fff',anchor:'end',align:'start',font:{weight:'bold'},
        formatter:(v)=>v+'%'
      }
    },
    scales:{
      y:{beginAtZero:true,max:100,ticks:{stepSize:20,color:'#444'}},
      x:{ticks:{color:'#444'}}
    },
    animation:{duration:1200,easing:'easeOutQuart'}
  },
  plugins:[ChartDataLabels]
});
</script>

<script src="../assets/js/sidebar.js"></script>
<?php include('../includes/footer.php'); ?>
