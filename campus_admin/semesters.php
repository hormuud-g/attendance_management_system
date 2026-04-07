<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Access Control
if (strtolower($_SESSION['user']['role'] ?? '') !== 'campus_admin') {
  header("Location: ../login.php");
  exit;
}

$message = "";
$type = "";

/* ===========================================
   FETCH SEMESTERS (shared for all campuses)
=========================================== */
try {
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
<title>Semester | Hormuud University</title>
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
.status-active { color:var(--green); font-weight:600; }
.status-inactive { color:var(--red); font-weight:600; }

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
@keyframes slideIn {
  from {opacity:0; transform:translateY(-15px);}
  to {opacity:1; transform:translateY(0);}
}
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
  <div class="page-header">
    <h1>Semesters</h1>
  </div>

  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Semester Name</th>
          <th>Description</th>
          <th>Status</th>
          <th>Created</th>
          <th>Updated</th>
        </tr>
      </thead>
      <tbody>
        <?php if($semesters): foreach($semesters as $i => $s): ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><?= htmlspecialchars($s['semester_name']) ?></td>
          <td><?= htmlspecialchars($s['description']) ?></td>
          <td class="status-<?= strtolower($s['status']) ?>"><?= ucfirst($s['status']) ?></td>
          <td><?= htmlspecialchars($s['created_at'] ?? '-') ?></td>
          <td><?= htmlspecialchars($s['updated_at'] ?? '-') ?></td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="6" style="text-align:center;color:#777;">No semesters found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ✅ Alert -->
<div id="popup" class="alert-popup <?= $type ?>"><strong><?= $message ?></strong></div>

<script>
if("<?= $message ?>"){
  const popup=document.getElementById('popup');
  popup.classList.add('show');
  setTimeout(()=>popup.classList.remove('show'),3500);
}
</script>

<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>
