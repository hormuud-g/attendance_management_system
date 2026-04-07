<?php
/*******************************************************************************************
 * AUDIT LOG MANAGEMENT — Hormuud University
 * View all system actions from triggers / users
 * Super Admin Panel | PHP 8.2 | PDO | Secure
 * Author: ChatGPT 2025
 *******************************************************************************************/
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

if (strtolower($_SESSION['user']['role'] ?? '') !== 'campus_admin') {
  header("Location: ../login.php");
  exit;
}

date_default_timezone_set('Africa/Nairobi');
$message = ""; $type = "";

/* ================== DELETE ALL LOGS ================== */
if (isset($_POST['clear_logs'])) {
  try {
    $pdo->exec("TRUNCATE TABLE audit_log");
    $message = "🗑️ All logs cleared successfully!";
    $type = "success";
  } catch (Exception $e) {
    $message = "❌ Error: " . $e->getMessage();
    $type = "error";
  }
}

/* ================== SEARCH FILTER ================== */
$search = trim($_GET['search'] ?? '');
$sql = "
  SELECT a.*, u.username 
  FROM audit_log a 
  LEFT JOIN users u ON u.user_id = a.user_id
";
if ($search) {
  $sql .= " WHERE u.username LIKE :search OR a.action_type LIKE :search OR a.description LIKE :search OR a.ip_address LIKE :search";
}
$sql .= " ORDER BY a.action_time DESC LIMIT 500";
$stmt = $pdo->prepare($sql);
if ($search) $stmt->bindValue(':search', "%$search%");
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

include('../includes/header.php');
?>
<style>
  .sidebar.collapsed ~ .main-content {
  margin-left: 70px;
}

</style>
<div class="main-content">
  <div class="page-header"><h1>Audit Log History</h1></div>

  <!-- SEARCH + CLEAR -->
  <div class="filter-box">
    <form method="GET" style="display:flex;gap:10px;align-items:center;">
      <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search user, action or IP...">
      <button type="submit" class="btn blue"><i class="fa fa-search"></i> Search</button>
    </form>

    <form method="POST" onsubmit="return confirm('Are you sure you want to clear all logs?');" style="margin-top:10px;">
      <button type="submit" name="clear_logs" class="btn red"><i class="fa fa-trash"></i> Clear Logs</button>
    </form>
  </div>

  <!-- LOG TABLE -->
  <div class="table-wrapper">
    <h3 style="color:#0072CE;margin:10px;">Recent Audit Logs</h3>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>User</th>
          <th>Action</th>
          <th>Description</th>
          <th>IP Address</th>
          <th>User Agent</th>
          <th>Time</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$logs): ?>
        <tr><td colspan="7" style="text-align:center;color:#999;">No logs found.</td></tr>
      <?php else: ?>
        <?php $i=1; foreach($logs as $log): ?>
          <tr>
            <td><?= $i++ ?></td>
            <td><?= htmlspecialchars($log['username'] ?? 'System') ?></td>
            <td><span class="badge <?= strtolower($log['action_type']) ?>"><?= htmlspecialchars($log['action_type']) ?></span></td>
            <td><?= htmlspecialchars($log['description']) ?></td>
            <td><?= htmlspecialchars($log['ip_address']) ?></td>
            <td style="max-width:300px;"><?= htmlspecialchars($log['user_agent']) ?></td>
            <td><?= htmlspecialchars($log['action_time']) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if($message): ?>
<div class="alert <?= $type ?>"><strong><?= $message ?></strong></div>
<script>setTimeout(()=>document.querySelector('.alert').remove(),5000);</script>
<?php endif; ?>

<!-- STYLE -->
<style>
body{font-family:'Poppins',sans-serif;background:#f5f8fa;margin:0;}
.main-content{padding:25px;margin-left:250px;margin-top:90px;}
.page-header h1{color:#0072CE;margin-bottom:10px;}
.filter-box{background:#fff;padding:15px 20px;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.08);margin-bottom:20px;}
input[type=text]{padding:8px;width:250px;border:1px solid #ccc;border-radius:6px;background:#f8f8f8;}
.btn{border:none;padding:9px 15px;border-radius:6px;font-weight:600;cursor:pointer;}
.btn.blue{background:#0072CE;color:#fff;}
.btn.red{background:#C62828;color:#fff;}
.table-wrapper{overflow:auto;background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.08);}
table{width:100%;border-collapse:collapse;}
th,td{padding:10px 12px;border-bottom:1px solid #eee;text-align:left;font-size:14px;}
thead th{background:#0072CE;color:#fff;position:sticky;top:0;}
tr:hover{background:#f4f8ff;}
.alert{position:fixed;top:15px;right:15px;background:#00843D;color:#fff;padding:10px 20px;border-radius:6px;font-weight:600;z-index:9999;}
.alert.error{background:#C62828;}
.badge{padding:4px 8px;border-radius:5px;color:#fff;font-weight:500;text-transform:capitalize;}
.badge.insert{background:#4CAF50;}
.badge.update{background:#FFC107;color:#000;}
.badge.delete{background:#F44336;}
.badge.login{background:#2196F3;}
@media(max-width:768px){.main-content{margin-left:0;padding:15px;}table{font-size:13px;}}
</style>

<?php include('../includes/footer.php'); ?>
<?php ob_end_flush(); ?>
