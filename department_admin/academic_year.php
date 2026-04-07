<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Access Control - CHANGE TO DEPARTMENT_ADMIN
if (strtolower($_SESSION['user']['role'] ?? '') !== 'department_admin') {
  header("Location: ../login.php");
  exit;
}

// ✅ Department Info for display
$department_id = $_SESSION['user']['linked_id'] ?? null;
$department_info = [];
if ($department_id) {
    $stmt = $pdo->prepare("SELECT d.*, f.faculty_name, c.campus_name 
        FROM departments d 
        LEFT JOIN faculties f ON d.faculty_id = f.faculty_id 
        LEFT JOIN campus c ON d.campus_id = c.campus_id 
        WHERE d.department_id = ?");
    $stmt->execute([$department_id]);
    $department_info = $stmt->fetch(PDO::FETCH_ASSOC);
}

$message = "";
$type = "";

/* ===========================================
   FETCH DATA (shared for all departments)
=========================================== */
try {
  $stmt = $pdo->query("SELECT * FROM academic_year ORDER BY academic_year_id DESC");
  $years = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  $message = "❌ " . $e->getMessage();
  $type = "error";
  $years = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Academic Years | Hormuud University</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="../images.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
:root {
  --green: #00843D;
  --blue: #0072CE;
  --red: #C62828;
  --bg: #F5F9F7;
}
body {
  font-family: 'Poppins', sans-serif;
  background: var(--bg);
  margin: 0;
  color: #333;
}
.main-content {
  padding: 20px;
  margin-top: 90px;
  margin-left: 250px;
  transition: all .3s;
}
.sidebar.collapsed ~ .main-content { margin-left: 70px; }

.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 15px;
}
.page-header h1 {
  color: var(--blue);
  font-size: 24px;
  font-weight: 700;
}

/* ✅ Department Info Box */
.department-info {
    background: linear-gradient(135deg, #0072CE, #0056b3);
    color: white;
    padding: 20px;
    border-radius: 10px;
    margin-bottom: 20px;
    box-shadow: 0 4px 12px rgba(0, 114, 206, 0.3);
}

.department-info h2 {
    margin: 0 0 10px 0;
    font-size: 22px;
}

.department-info p {
    margin: 5px 0;
    opacity: 0.9;
}

/* ✅ Table */
.table-wrapper {
  overflow-x: auto;
  background: #fff;
  border-radius: 10px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}
table {
  width: 100%;
  border-collapse: collapse;
  background: #fff;
}
thead th {
  background: var(--blue);
  color: #fff;
  padding: 12px;
  text-align: left;
  position: sticky;
  top: 0;
}
th, td {
  padding: 12px 14px;
  border-bottom: 1px solid #eee;
}
tr:hover { background: #eef8f0; }
.status-active { color: var(--green); font-weight: 600; }
.status-inactive { color: var(--red); font-weight: 600; }

/* ✅ Alert Popup */
.alert-popup {
  display: none;
  position: fixed;
  top: 20px;
  right: 20px;
  background: #fff;
  padding: 16px 24px;
  border-radius: 10px;
  text-align: center;
  box-shadow: 0 4px 12px rgba(0,0,0,0.1);
  z-index: 4000;
}
.alert-popup.show { display: block; animation: slideIn .5s ease; }
.alert-popup.error { border-left: 5px solid var(--red); }
.alert-popup.success { border-left: 5px solid var(--green); }
@keyframes slideIn {
  from {opacity:0; transform:translateY(-15px);}
  to {opacity:1; transform:translateY(0);}
}

/* Current Year Highlight */
.current-year {
  background: #e8f5e8 !important;
  border-left: 4px solid var(--green);
}

.year-badge {
  padding: 4px 8px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 600;
  margin-left: 8px;
}

.badge-current {
  background: var(--green);
  color: white;
}

.badge-upcoming {
  background: #ff9800;
  color: white;
}

.badge-past {
  background: #757575;
  color: white;
}
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
  <!-- Department Information -->
  <?php if ($department_info): ?>
  <!-- <div class="department-info">
    <h2><?= htmlspecialchars($department_info['department_name'] ?? 'Department') ?></h2>
    <p><strong>Faculty:</strong> <?= htmlspecialchars($department_info['faculty_name'] ?? 'N/A') ?></p>
    <p><strong>Campus:</strong> <?= htmlspecialchars($department_info['campus_name'] ?? 'N/A') ?></p>
  </div> -->
  <?php endif; ?>

  <div class="page-header">
    <h1>Academic Years</h1>
  </div>

  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Year Name</th>
          <th>Start Date</th>
          <th>End Date</th>
          <th>Status</th>
          <th>Duration</th>
          <th>Created At</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($years): 
          $current_date = date('Y-m-d');
          foreach ($years as $i => $y): 
            $start_date = $y['start_date'];
            $end_date = $y['end_date'];
            $status = $y['status'];
            
            // Determine year type for styling
            $year_class = '';
            $year_badge = '';
            
            if ($status === 'active') {
              $year_class = 'current-year';
              $year_badge = '<span class="year-badge badge-current">Current</span>';
            } elseif ($start_date > $current_date) {
              $year_badge = '<span class="year-badge badge-upcoming">Upcoming</span>';
            } elseif ($end_date < $current_date) {
              $year_badge = '<span class="year-badge badge-past">Past</span>';
            }
            
            // Calculate duration
            $duration = '';
            if ($start_date && $end_date) {
              $start = new DateTime($start_date);
              $end = new DateTime($end_date);
              $interval = $start->diff($end);
              $duration = $interval->format('%m months %d days');
            }
        ?>
        <tr class="<?= $year_class ?>">
          <td><?= $i + 1 ?></td>
          <td>
            <?= htmlspecialchars($y['year_name']) ?>
            <?= $year_badge ?>
          </td>
          <td><?= htmlspecialchars($start_date) ?></td>
          <td><?= htmlspecialchars($end_date) ?></td>
          <td class="status-<?= strtolower($status) ?>"><?= ucfirst($status) ?></td>
          <td><?= $duration ?></td>
          <td><?= htmlspecialchars($y['created_at'] ?? '-') ?></td>
        </tr>
        <?php endforeach; else: ?>
        <tr>
          <td colspan="7" style="text-align:center;color:#777;padding:20px;">
            No academic years found.
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Academic Year Information -->
  <!-- <div style="background: #e3f2fd; padding: 15px; border-radius: 8px; margin-top: 20px; border-left: 4px solid var(--blue);">
    <h3 style="color: var(--blue); margin-top: 0;">About Academic Years</h3>
    <p style="margin: 5px 0; color: #555;">
      Academic years define the timeframe for your department's academic activities. 
      The <strong>current active year</strong> is highlighted in green and is used for scheduling and attendance tracking.
    </p>
  </div> -->
</div>

<!-- ✅ Alert Popup -->
<div id="popup" class="alert-popup <?= $type ?>"><strong><?= $message ?></strong></div>

<script>
if("<?= $message ?>"){
  const popup=document.getElementById('popup');
  popup.classList.add('show');
  setTimeout(()=>popup.classList.remove('show'),3500);
}

// Add some interactivity
document.addEventListener('DOMContentLoaded', function() {
  const rows = document.querySelectorAll('tbody tr');
  rows.forEach(row => {
    row.addEventListener('click', function() {
      // Remove any existing active class
      rows.forEach(r => r.classList.remove('row-active'));
      // Add active class to clicked row
      this.classList.add('row-active');
    });
  });
});
</script>

<style>
.row-active {
  background: #0072CE !important;
  color: white;
}
.row-active td {
  color: white;
}
</style>

<script src="../assets/js/sidebar.js"></script>
<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>