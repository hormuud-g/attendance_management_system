<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Access Control - Allow both faculty_admin and department_admin
$user_role = strtolower($_SESSION['user']['role'] ?? '');
if (!in_array($user_role, ['faculty_admin', 'department_admin'])) {
  header("Location: ../login.php");
  exit;
}

// ✅ Get linked_id based on role
$linked_id = $_SESSION['user']['linked_id'];
$faculty_id = null;
$department_id = null;

if ($user_role === 'faculty_admin') {
    $faculty_id = $linked_id;
} elseif ($user_role === 'department_admin') {
    $department_id = $linked_id;
    
    // Get faculty_id from department
    $stmt = $pdo->prepare("SELECT faculty_id FROM departments WHERE department_id = ?");
    $stmt->execute([$department_id]);
    $dept_info = $stmt->fetch(PDO::FETCH_ASSOC);
    $faculty_id = $dept_info['faculty_id'] ?? null;
}

$message = "";
$type = "";

/* ===========================================
   FETCH DATA
=========================================== */
try {
  // Get department info for department_admin
  $department_info = [];
  if ($user_role === 'department_admin' && $department_id) {
    $stmt = $pdo->prepare("SELECT department_name FROM departments WHERE department_id = ?");
    $stmt->execute([$department_id]);
    $department_info = $stmt->fetch(PDO::FETCH_ASSOC);
  }

  // Get faculty info
  $faculty_info = [];
  if ($faculty_id) {
    $stmt = $pdo->prepare("SELECT faculty_name FROM faculties WHERE faculty_id = ?");
    $stmt->execute([$faculty_id]);
    $faculty_info = $stmt->fetch(PDO::FETCH_ASSOC);
  }

  // Fetch semesters (shared for all faculties)
  $stmt = $pdo->query("SELECT * FROM semester ORDER BY semester_id DESC");
  $semesters = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  $message = "❌ " . $e->getMessage();
  $type = "error";
  $semesters = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Semesters | Hormuud University</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="../images.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
  --green:#00843D;
  --blue:#0072CE;
  --red:#C62828;
  --bg:#F5F9F7;
}
body {
  font-family:'Poppins',sans-serif;
  background:var(--bg);
  margin:0;
  color:#333;
}
.main-content {
  padding:20px;
  margin-top:90px;
  margin-left:250px;
  transition:all .3s ease;
}
.sidebar.collapsed ~ .main-content { margin-left:70px; }

.page-header {
  display:flex;
  justify-content:space-between;
  align-items:center;
  margin-bottom:15px;
}
.page-header h1 {
  color:var(--blue);
  font-size:24px;
  font-weight:700;
  margin:0;
}

/* ✅ Info Sections */
.department-info {
  background:#e8f5e9;
  padding:12px 16px;
  border-radius:8px;
  margin-bottom:20px;
  border-left:4px solid var(--green);
  font-weight:600;
}
.info-banner {
  background:var(--blue);
  color:white;
  padding:12px 16px;
  border-radius:8px;
  margin-bottom:20px;
  font-weight:600;
}
.view-only-notice {
  background:#fff3cd;
  border:1px solid #ffeaa7;
  padding:15px;
  border-radius:8px;
  margin-bottom:20px;
  text-align:center;
}
.view-only-notice i {
  color:#f39c12;
  font-size:20px;
  margin-right:10px;
}

/* ✅ Table */
.table-wrapper {
  background:#fff;
  border-radius:10px;
  box-shadow:0 4px 12px rgba(0,0,0,0.08);
  overflow-x:auto;
  overflow-y:auto;
  max-height:480px;
}
table {
  width:100%;
  border-collapse:collapse;
}
thead th {
  background:var(--blue);
  color:#fff;
  position:sticky;
  top:0;
  z-index:2;
  padding:12px;
}
th,td {
  padding:12px 14px;
  border-bottom:1px solid #eee;
  white-space:nowrap;
  text-align:left;
}
tr:hover { background:#eef8f0; }

/* ✅ Status Badges */
.status-badge {
  padding:6px 12px;
  border-radius:20px;
  font-size:12px;
  font-weight:600;
  display:inline-block;
}
.status-active { 
  background:#e8f5e8; 
  color:#2e7d32; 
}
.status-inactive { 
  background:#ffebee; 
  color:#c62828; 
}

/* ✅ Alert */
.alert-popup {
  display:none;
  position:fixed;
  top:20px;
  right:20px;
  background:#fff;
  padding:16px 24px;
  border-radius:10px;
  box-shadow:0 4px 12px rgba(0,0,0,0.1);
  z-index:4000;
}
.alert-popup.show { display:block; animation:slideIn .5s ease; }
.alert-popup.error { border-left:5px solid var(--red); }
.alert-popup.success { border-left:5px solid var(--green); }
@keyframes slideIn {
  from {opacity:0; transform:translateY(-15px);}
  to {opacity:1; transform:translateY(0);}
}

/* ✅ Info Box */
.info-box {
  background:#f8f9fa;
  padding:15px;
  border-radius:8px;
  border-left:4px solid var(--blue);
  margin-top:20px;
}
.info-box h4 {
  margin-top:0;
  color:var(--blue);
  display:flex;
  align-items:center;
  gap:8px;
}
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
  <!-- Department/Faculty Info -->
  <?php if ($user_role === 'department_admin' && !empty($department_info)): ?>
  <!-- <div class="department-info">
    <i class="fas fa-building"></i> 
    Department: <strong><?= htmlspecialchars($department_info['department_name']) ?></strong>
    <?php if (!empty($faculty_info)): ?>
      | Faculty: <strong><?= htmlspecialchars($faculty_info['faculty_name']) ?></strong>
    <?php endif; ?>
  </div> -->
  <?php elseif ($user_role === 'faculty_admin' && !empty($faculty_info)): ?>
  <div class="info-banner">
    <i class="fas fa-university"></i> 
    Faculty: <strong><?= htmlspecialchars($faculty_info['faculty_name']) ?></strong>
  </div>
  <?php endif; ?>

  <!-- View Only Notice for Department Admin -->
  <?php if ($user_role === 'department_admin'): ?>
  <!-- <div class="view-only-notice">
    <i class="fas fa-eye"></i>
    <strong>View Only Access:</strong> You can view semesters but cannot create or modify them. 
    Please contact your faculty administrator for any changes.
  </div> -->
  <?php endif; ?>

  <div class="page-header">
    <h1>Semesters
      
    </h1>
  </div>

  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Semester Name</th>
          <th>Description</th>
          <th>Status</th>
          <th>Created Date</th>
          <th>Updated Date</th>
        </tr>
      </thead>
      <tbody>
        <?php if($semesters): foreach($semesters as $i => $s): ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td>
            <strong><?= htmlspecialchars($s['semester_name']) ?></strong>
          </td>
          <td><?= !empty($s['description']) ? htmlspecialchars($s['description']) : '<span style="color:#999;font-style:italic;">No description</span>' ?></td>
          <td>
            <span class="status-badge status-<?= strtolower($s['status']) ?>">
              <?= ucfirst($s['status']) ?>
            </span>
          </td>
          <td><?= !empty($s['created_at']) ? date('M d, Y', strtotime($s['created_at'])) : '-' ?></td>
          <td><?= !empty($s['updated_at']) ? date('M d, Y', strtotime($s['updated_at'])) : '-' ?></td>
        </tr>
        <?php endforeach; else: ?>
        <tr>
          <td colspan="6" style="text-align:center;color:#777;padding:30px;">
            <i class="fas fa-calendar-times" style="font-size:48px;color:#ccc;margin-bottom:10px;display:block;"></i>
            No semesters found.
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Additional Information -->
  <!-- <div class="info-box">
    <h4>
      <i class="fas fa-info-circle"></i>
      About Semesters
    </h4>
    <p style="margin-bottom:10px;">
      <strong>Active Semesters:</strong> <span class="status-badge status-active">Active</span> semesters are currently in use for course scheduling and academic planning.
    </p>
    <p style="margin-bottom:0;">
      <strong>Inactive Semesters:</strong> <span class="status-badge status-inactive">Inactive</span> semesters are for reference only and not available for new course assignments.
    </p>
    <?php if ($user_role === 'department_admin'): ?>
    <p style="margin-top:10px;margin-bottom:0;padding:10px;background:#e8f5e8;border-radius:4px;">
      <i class="fas fa-lightbulb"></i> <strong>Tip:</strong> Check which semesters are active when planning your department's course schedules and room allocations.
    </p>
    <?php endif; ?>
  </div>
</div> -->

<!-- ✅ Alert -->
<div id="popup" class="alert-popup <?= $type ?>">
  <strong>
    <?php if ($type === 'success'): ?>
      <i class="fas fa-check-circle" style="color:var(--green);margin-right:8px;"></i>
    <?php elseif ($type === 'error'): ?>
      <i class="fas fa-exclamation-circle" style="color:var(--red);margin-right:8px;"></i>
    <?php endif; ?>
    <?= $message ?>
  </strong>
</div>

<script>
// Auto-hide alert popup
<?php if (!empty($message)): ?>
document.addEventListener('DOMContentLoaded', function() {
  const popup = document.getElementById('popup');
  if (popup) {
    popup.classList.add('show');
    setTimeout(() => {
      popup.classList.remove('show');
    }, 3500);
  }
});
<?php endif; ?>

// Add interactive features
document.addEventListener('DOMContentLoaded', function() {
  // Highlight active semesters
  const activeSemesters = document.querySelectorAll('.status-active');
  activeSemesters.forEach(badge => {
    const row = badge.closest('tr');
    if (row) {
      row.style.borderLeft = '3px solid var(--green)';
    }
  });

  // Add click effect on table rows
  const tableRows = document.querySelectorAll('tbody tr');
  tableRows.forEach(row => {
    row.addEventListener('click', function() {
      tableRows.forEach(r => r.style.backgroundColor = '');
      this.style.backgroundColor = '#f0f8ff';
    });
  });
});
</script>

<script src="../assets/js/sidebar.js"></script>

<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>