<?php
/*******************************************************************************************
 * ALL-IN-ONE UNIVERSITY REPORT — Hormuud University
 * Role: Super Admin, Campus Admin, Teacher
 * Author: GPT-5 | PHP 8.2 | PDO Secure | Enhanced Color Theme
 *******************************************************************************************/
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

$role = strtolower($_SESSION['user']['role'] ?? '');
$user_linked_id = $_SESSION['user']['linked_id'] ?? null;

// ✅ Access control
if (!in_array($role, ['super_admin', 'campus_admin', 'teacher'])) {
  header("Location: ../login.php");
  exit;
}

date_default_timezone_set('Africa/Nairobi');

// Initialize
$campus_id = null;
$teacher_id = null;
$stats = [];
$students = [];
$teachers = [];
$attendance_summary = [];
$teacher_attendance = [];

/* ============================== SUPER_ADMIN / CAMPUS_ADMIN ============================== */
if (in_array($role, ['super_admin', 'campus_admin'])) {

  if ($role === 'campus_admin') $campus_id = $user_linked_id;

  // Academic structure
  $stats['Academic Years'] = $pdo->query("SELECT COUNT(*) FROM academic_year")->fetchColumn();
  $stats['Terms'] = $pdo->query("SELECT COUNT(*) FROM academic_term")->fetchColumn();
  $stats['Semesters'] = $pdo->query("SELECT COUNT(*) FROM semester")->fetchColumn();
  $stats['Faculties'] = $pdo->query("SELECT COUNT(*) FROM faculties")->fetchColumn();
  $stats['Departments'] = $pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn();
  $stats['Programs'] = $pdo->query("SELECT COUNT(*) FROM programs")->fetchColumn();
  $stats['Sections'] = $pdo->query("SELECT COUNT(*) FROM class_section")->fetchColumn();

  // Staff and students
  $stats['Teachers'] = $pdo->query("SELECT COUNT(*) FROM teachers")->fetchColumn();
  $stats['Students'] = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();

  // Attendance summary (all)
  $attendance_summary = $pdo->query("
    SELECT COUNT(*) AS total,
           SUM(CASE WHEN status='present' THEN 1 ELSE 0 END) AS present,
           SUM(CASE WHEN status='absent' THEN 1 ELSE 0 END) AS absent
    FROM attendance
  ")->fetch(PDO::FETCH_ASSOC);

  // Teachers list
  $teachers = $pdo->query("
    SELECT teacher_name, email, phone_number, qualification
    FROM teachers
    ORDER BY teacher_name ASC
    LIMIT 50
  ")->fetchAll(PDO::FETCH_ASSOC);

  // Students list
  $students = $pdo->query("
    SELECT s.reg_no, s.full_name, cs.section_name, p.program_name
    FROM students s
    LEFT JOIN class_section cs ON cs.section_id = s.section_id
    LEFT JOIN programs p ON p.program_id = cs.program_id
    ORDER BY s.full_name ASC
    LIMIT 50
  ")->fetchAll(PDO::FETCH_ASSOC);
}

/* ============================== TEACHER REPORT ============================== */
if ($role === 'teacher') {
  $teacher_id = $_SESSION['user']['linked_id'];

  // Stats
  $stats['My Classes'] = $pdo->query("SELECT COUNT(*) FROM timetable WHERE teacher_id=$teacher_id")->fetchColumn();
  $stats['My Subjects'] = $pdo->query("SELECT COUNT(DISTINCT subject_id) FROM timetable WHERE teacher_id=$teacher_id")->fetchColumn();
  $stats['My Attendance Logs'] = $pdo->query("SELECT COUNT(*) FROM teacher_attendance WHERE teacher_id=$teacher_id")->fetchColumn();

  // Students taught
  $students = $pdo->query("
    SELECT DISTINCT s.reg_no, s.full_name, cs.section_name, p.program_name
    FROM students s
    JOIN class_section cs ON cs.section_id=s.section_id
    JOIN timetable t ON t.section_id=s.section_id
    JOIN programs p ON p.program_id=cs.program_id
    WHERE t.teacher_id=$teacher_id
    LIMIT 50
  ")->fetchAll(PDO::FETCH_ASSOC);

  // Teacher info
  $teachers = $pdo->query("
    SELECT teacher_name, email, phone_number, qualification
    FROM teachers
    WHERE teacher_id=$teacher_id
  ")->fetchAll(PDO::FETCH_ASSOC);

  // Teacher Attendance (IN/OUT)
  $teacher_attendance = $pdo->query("
    SELECT date, time_in, time_out,
           CASE 
             WHEN time_in IS NOT NULL AND time_out IS NOT NULL THEN 'Completed'
             WHEN time_in IS NOT NULL AND time_out IS NULL THEN 'In Progress'
             ELSE 'Not Marked'
           END AS status
    FROM teacher_attendance
    WHERE teacher_id=$teacher_id
    ORDER BY date DESC
    LIMIT 30
  ")->fetchAll(PDO::FETCH_ASSOC);

  // Attendance summary (teacher logs)
  $attendance_summary = $pdo->query("
    SELECT COUNT(*) AS total,
           SUM(CASE WHEN time_in IS NOT NULL THEN 1 ELSE 0 END) AS present,
           SUM(CASE WHEN time_in IS NULL THEN 1 ELSE 0 END) AS absent
    FROM teacher_attendance
    WHERE teacher_id=$teacher_id
  ")->fetch(PDO::FETCH_ASSOC);
}

include('../includes/header.php');
?>

<div class="main-content">
  <div class="page-header">
    <h1><i class="fa fa-chart-pie"></i> University All-in-One Report</h1>
  </div>

  <!-- Summary Grid -->
  <div class="summary-grid">
    <?php foreach($stats as $label=>$value): ?>
      <div class="card">
        <h3><?= htmlspecialchars($label) ?></h3>
        <p><?= number_format($value) ?></p>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Students -->
  <div class="section">
    <h2>👨‍🎓 Students (Top 50)</h2>
    <div class="table-wrapper">
      <table>
        <thead><tr><th>Reg No</th><th>Name</th><th>Section</th><th>Program</th></tr></thead>
        <tbody>
          <?php foreach($students as $s): ?>
            <tr>
              <td><?= htmlspecialchars($s['reg_no']) ?></td>
              <td><?= htmlspecialchars($s['full_name']) ?></td>
              <td><?= htmlspecialchars($s['section_name'] ?? '-') ?></td>
              <td><?= htmlspecialchars($s['program_name'] ?? '-') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Teachers -->
  <div class="section">
    <h2>👨‍🏫 Teacher Info</h2>
    <div class="table-wrapper">
      <table>
        <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Qualification</th></tr></thead>
        <tbody>
          <?php foreach($teachers as $t): ?>
            <tr>
              <td><?= htmlspecialchars($t['teacher_name']) ?></td>
              <td><?= htmlspecialchars($t['email']) ?></td>
              <td><?= htmlspecialchars($t['phone_number']) ?></td>
              <td><?= htmlspecialchars($t['qualification']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Teacher Attendance -->
  <?php if ($role === 'teacher'): ?>
  <div class="section">
    <h2>🕒 My Attendance (IN/OUT)</h2>
    <div class="table-wrapper">
      <table>
        <thead><tr><th>Date</th><th>Time IN</th><th>Time OUT</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach($teacher_attendance as $a): ?>
            <tr>
              <td><?= htmlspecialchars($a['date']) ?></td>
              <td style="color:#00A651;"><?= htmlspecialchars($a['time_in'] ?? '-') ?></td>
              <td style="color:#0072CE;"><?= htmlspecialchars($a['time_out'] ?? '-') ?></td>
              <td style="color:<?= $a['status']==='Completed' ? '#00843D' : ($a['status']==='In Progress' ? '#FFB400' : '#C62828') ?>;">
                <?= htmlspecialchars($a['status']) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- Attendance Summary -->
  <div class="section">
    <h2>📈 Attendance Summary</h2>
    <p>
      <strong>Total:</strong> <?= $attendance_summary['total'] ?? 0 ?> |
      <strong style="color:#00843D;">Present:</strong> <?= $attendance_summary['present'] ?? 0 ?> |
      <strong style="color:#C62828;">Absent:</strong> <?= $attendance_summary['absent'] ?? 0 ?>
    </p>
  </div>
</div>

<style>
body {
  font-family: 'Poppins', sans-serif;
  background: #F5F9F7;
  color: #333333;
  margin: 0;
}
.main-content { padding: 25px; margin-left: 250px; margin-top: 90px; }
.page-header h1 { color: #0072CE; margin-bottom: 15px; }
.summary-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 15px;
  margin-bottom: 25px;
}
.card {
  background: #FFFFFF;
  border-radius: 12px;
  padding: 15px;
  text-align: center;
  box-shadow: 0 2px 6px rgba(0,0,0,0.08);
  border-top: 4px solid #00843D;
}
.card h3 {
  font-size: 13px;
  color: #0072CE;
  text-transform: uppercase;
}
.card p {
  font-size: 22px;
  font-weight: 700;
  color: #00A651;
  margin: 5px 0 0;
}
.section { margin-top: 30px; }
.section h2 { color: #00843D; margin-bottom: 10px; }
.table-wrapper {
  overflow: auto;
  background: #FFFFFF;
  border-radius: 12px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
table { width: 100%; border-collapse: collapse; }
th, td {
  padding: 10px 12px;
  border-bottom: 1px solid #eee;
  text-align: left;
  font-size: 14px;
}
thead th {
  background: #0072CE;
  color: #FFFFFF;
}
tr:hover { background: #E8F5E9; }
</style>

<?php include('../includes/footer.php'); ?>
<?php ob_end_flush(); ?>
