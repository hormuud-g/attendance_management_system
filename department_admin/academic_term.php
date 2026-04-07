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

  $stmt = $pdo->query("
    SELECT t.*, y.year_name 
    FROM academic_term t
    JOIN academic_year y ON y.academic_year_id = t.academic_year_id
    ORDER BY t.academic_term_id DESC
  ");
  $terms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  $message = "❌ " . $e->getMessage();
  $type = "error";
  $terms = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Academic Terms | Hormuud University</title>
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
  margin: 0;
}

/* ✅ Info Banner */
.info-banner {
  background: var(--blue);
  color: white;
  padding: 12px 16px;
  border-radius: 8px;
  margin-bottom: 20px;
  font-weight: 600;
}
.department-info {
  background: #e8f5e9;
  padding: 12px 16px;
  border-radius: 8px;
  margin-bottom: 20px;
  border-left: 4px solid var(--green);
  font-weight: 600;
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
  white-space: nowrap;
}
tr:hover { background: #eef8f0; }

/* ✅ Status Badges */
.status-badge {
  padding: 6px 12px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
  display: inline-block;
}
.status-active { 
  background: #e8f5e8; 
  color: #2e7d32; 
}
.status-inactive { 
  background: #ffebee; 
  color: #c62828; 
}

/* ✅ Alert */
.alert-popup {
  display: none;
  position: fixed;
  top: 20px;
  right: 20px;
  background: #fff;
  padding: 16px 24px;
  border-radius: 10px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.1);
  z-index: 4000;
}
.alert-popup.show { display: block; animation: slideIn .5s ease; }
.alert-popup.error { border-left: 5px solid var(--red); }
.alert-popup.success { border-left: 5px solid var(--green); }
@keyframes slideIn {
  from {opacity:0;transform:translateY(-15px);}
  to {opacity:1;transform:translateY(0);}
}

/* ✅ View Only Notice */
.view-only-notice {
  background: #fff3cd;
  border: 1px solid #ffeaa7;
  padding: 15px;
  border-radius: 8px;
  margin-bottom: 20px;
  text-align: center;
}
.view-only-notice i {
  color: #f39c12;
  font-size: 20px;
  margin-right: 10px;
}
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
  <!-- Department/Faculty Info -->
  <!-- <?php if ($user_role === 'department_admin' && !empty($department_info)): ?>
  <div class="department-info">
    <i class="fas fa-building"></i> 
    Department: <strong><?= htmlspecialchars($department_info['department_name']) ?></strong>
    <?php if (!empty($faculty_info)): ?>
      | Faculty: <strong><?= htmlspecialchars($faculty_info['faculty_name']) ?></strong>
    <?php endif; ?>
  </div>
  <?php elseif ($user_role === 'faculty_admin' && !empty($faculty_info)): ?>
  <div class="info-banner">
    <i class="fas fa-university"></i> 
    Faculty: <strong><?= htmlspecialchars($faculty_info['faculty_name']) ?></strong>
  </div>
  <?php endif; ?> -->

  <!-- View Only Notice for Department Admin -->
  <!-- <?php if ($user_role === 'department_admin'): ?>
  <div class="view-only-notice">
    <i class="fas fa-eye"></i>
    <strong>View Only Access:</strong> You can view academic terms but cannot create or modify them. 
    Please contact your faculty administrator for any changes.
  </div>
  <?php endif; ?> -->

  <div class="page-header">
    <h1>Academic Terms
      <!-- <?php if ($user_role === 'department_admin' && !empty($department_info)): ?>
        <small style="font-size: 16px; color: #666;">- <?= htmlspecialchars($department_info['department_name']) ?></small>
      <?php endif; ?> -->
    </h1>
  </div>

  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Academic Year</th>
          <th>Term</th>
          <th>Start Date</th>
          <th>End Date</th>
          <th>Status</th>
          <th>Created</th>
          <th>Updated</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($terms): foreach ($terms as $i => $t): ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><?= htmlspecialchars($t['year_name']) ?></td>
          <td>
            <strong><?= htmlspecialchars($t['term_name']) ?></strong>
          </td>
          <td><?= htmlspecialchars($t['start_date']) ?></td>
          <td><?= htmlspecialchars($t['end_date']) ?></td>
          <td>
            <span class="status-badge status-<?= strtolower($t['status']) ?>">
              <?= ucfirst($t['status']) ?>
            </span>
          </td>
          <td><?= !empty($t['created_at']) ? date('M d, Y', strtotime($t['created_at'])) : '-' ?></td>
          <td><?= !empty($t['updated_at']) ? date('M d, Y', strtotime($t['updated_at'])) : '-' ?></td>
        </tr>
        <?php endforeach; else: ?>
        <tr>
          <td colspan="8" style="text-align:center;color:#777;padding:30px;">
            <i class="fas fa-calendar-times" style="font-size:48px;color:#ccc;margin-bottom:10px;display:block;"></i>
            No academic terms found.
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Additional Info for Department Admin -->
  <?php if ($user_role === 'department_admin'): ?>
  <!-- <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid var(--blue);">
    <!-- <h4 style="margin-top:0;color:var(--blue);">
      <i class="fas fa-info-circle"></i> About Academic Terms
    </h4>
    <p style="margin-bottom:10px;">
      <strong>Active Terms:</strong> You can allocate rooms only to <span class="status-badge status-active">Active</span> academic terms.
    </p> -->
    <!-- <p style="margin-bottom:0;">
      <strong>Inactive Terms:</strong> <span class="status-badge status-inactive">Inactive</span> terms are for reference only and cannot be used for new allocations.
    </p> -->
  </div> -->
  <?php endif; ?>
</div>

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

// Add some interactive features
document.addEventListener('DOMContentLoaded', function() {
  // Highlight current date in the table if any term is active
  const today = new Date().toISOString().split('T')[0];
  const rows = document.querySelectorAll('tbody tr');
  
  rows.forEach(row => {
    const startDate = row.cells[3].textContent;
    const endDate = row.cells[4].textContent;
    const status = row.cells[5].textContent.trim().toLowerCase();
    
    // Highlight if current date falls within an active term
    if (status === 'active' && today >= startDate && today <= endDate) {
      row.style.backgroundColor = '#e8f5e8';
      row.style.borderLeft = '4px solid var(--green)';
    }
  });
});
</script>

<script src="../assets/js/sidebar.js"></script>

<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>