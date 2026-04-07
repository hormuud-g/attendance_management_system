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
   FETCH DATA (shared for all campuses)
=========================================== */
try {
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
.status-active { color: var(--green); font-weight: 600; }
.status-inactive { color: var(--red); font-weight: 600; }

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
@keyframes slideIn {
  from {opacity:0;transform:translateY(-15px);}
  to {opacity:1;transform:translateY(0);}
}
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
  <div class="page-header">
    <h1>Academic Terms</h1>
  </div>

  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Academic Year</th>
          <th>Term</th>
          <th>Start</th>
          <th>End</th>
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
          <td><?= htmlspecialchars($t['term_name']) ?></td>
          <td><?= htmlspecialchars($t['start_date']) ?></td>
          <td><?= htmlspecialchars($t['end_date']) ?></td>
          <td class="status-<?= strtolower($t['status']) ?>"><?= ucfirst($t['status']) ?></td>
          <td><?= htmlspecialchars($t['created_at'] ?? '-') ?></td>
          <td><?= htmlspecialchars($t['updated_at'] ?? '-') ?></td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="8" style="text-align:center;color:#777;">No academic terms found.</td></tr>
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
<script src="../assets/js/sidebar.js"></script>

<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>
