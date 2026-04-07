<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Access Control
if (!isset($_SESSION['user'])) {
  header("Location: ../login.php");
  exit;
}

$user_id = $_SESSION['user']['user_id'];
$message = "";
$type = "";

/* ===========================================
   CRUD: DELETE & MARK AS READ
=========================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  try {
    if ($_POST['action'] === 'delete') {
      $pdo->prepare("DELETE FROM notifications WHERE notification_id=? AND user_id=?")
          ->execute([$_POST['notification_id'], $user_id]);
      $message = "🗑️ Notification deleted successfully!";
      $type = "success";
    }

    if ($_POST['action'] === 'mark_read') {
      $pdo->prepare("UPDATE notifications SET read_status='read' WHERE notification_id=? AND user_id=?")
          ->execute([$_POST['notification_id'], $user_id]);
      $message = "✅ Marked as read!";
      $type = "success";
    }
  } catch (Exception $e) {
    $message = "❌ " . $e->getMessage();
    $type = "error";
  }
}

/* ===========================================
   SEARCH + FILTER
=========================================== */
$where = [];
$params = [];

if ($role === 'campus_admin') {
  $campus_id = $_SESSION['user']['linked_id'];
  $where[] = "n.campus_id = ?";
  $params[] = $campus_id;
} else {
  $where[] = "n.user_id = ?";
  $params[] = $user_id;
}


if (!empty($_GET['search'])) {
  $where[] = "(n.title LIKE ? OR n.message LIKE ?)";
  $params[] = "%{$_GET['search']}%";
  $params[] = "%{$_GET['search']}%";
}

if (!empty($_GET['status'])) {
  $where[] = "n.read_status = ?";
  $params[] = $_GET['status'];
}

$sql = "
  SELECT n.*, a.title AS announcement_title 
  FROM notifications n
  LEFT JOIN announcement a ON n.announcement_id = a.announcement_id
  WHERE " . implode(" AND ", $where) . "
  ORDER BY n.notification_id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Notifications | Hormuud University</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="../images.png">
<link rel="icon" type="image/png" href="../images.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
  --green:#00843D;--blue:#0072CE;--red:#C62828;--bg:#F5F9F7;
}
body{font-family:'Poppins',sans-serif;background:var(--bg);margin:0;}
.main-content{padding:20px;margin-top:90px;margin-left:250px;transition:all .3s;}
.sidebar.collapsed ~ .main-content {
  margin-left: 70px;
}
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;}
.page-header h1{color:var(--blue);}

/* ✅ FILTER BAR */
.filter-bar{
  display:flex;
  align-items:center;
  gap:10px;
  background:#fff;
  padding:10px 15px;
  border-radius:10px;
  box-shadow:0 2px 6px rgba(0,0,0,0.08);
  margin-bottom:20px;
  overflow-x:auto;
}
.filter-bar input[type="text"],
.filter-bar select{
  padding:8px 12px;
  border:1.5px solid #ccc;
  border-radius:6px;
  font-size:14px;
  min-width:160px;
}
.filter-bar button,
.filter-bar a{
  border:none;
  border-radius:6px;
  padding:8px 14px;
  font-weight:600;
  cursor:pointer;
  text-decoration:none;
}
.filter-bar button{background:var(--blue);color:#fff;}
.filter-bar a{background:#ccc;color:#333;}
.filter-bar button:hover{background:#005bb5;}
.filter-bar a:hover{background:#bbb;}

/* ✅ TABLE */
.table-wrapper{background:#fff;border-radius:10px;box-shadow:0 4px 12px rgba(0,0,0,0.08);overflow:auto;max-height:500px;}
table{width:100%;border-collapse:collapse;}
thead th{background:var(--blue);color:#fff;position:sticky;top:0;font-size:14px;}
th,td{padding:10px 12px;border-bottom:1px solid #eee;white-space:nowrap;font-size:14px;}
tr.unread{background:#fff5f5;font-weight:600;}
tr.unread td i.fa-envelope{color:#C62828;}
tr:hover{background:#eef8f0;}
.action-buttons{display:flex;justify-content:center;gap:6px;}
.btn-read{background:#00843D;color:white;padding:6px 9px;border:none;border-radius:6px;}
.btn-delete{background:#C62828;color:white;padding:6px 9px;border:none;border-radius:6px;}
.btn-read:hover{background:#00A651;}
.btn-delete:hover{background:#e53935;}

/* ✅ ALERT */
.alert-popup{display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;padding:20px 25px;border-radius:12px;text-align:center;box-shadow:0 4px 14px rgba(0,0,0,0.2);z-index:5000;width:280px;}
.alert-popup.show{display:block;}
.alert-popup.success{border-top:5px solid var(--green);}
.alert-popup.error{border-top:5px solid var(--red);}
.alert-popup h3{font-size:14px;color:#333;margin-bottom:15px;}
.alert-btn{background:var(--blue);color:white;padding:6px 16px;border:none;border-radius:5px;cursor:pointer;font-weight:600;}
.alert-btn:hover{background:#005bb5;}
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
  <div class="page-header">
    <h1><i class="fa fa-bell"></i> My Notifications</h1>
  </div>

  <!-- ✅ FILTER -->
  <form method="GET" class="filter-bar">
    <input type="text" name="search" placeholder="🔍 Search title or message..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
    <select name="status">
      <option value="">📊 All</option>
      <option value="unread" <?= ($_GET['status']??'')==='unread'?'selected':'' ?>>Unread</option>
      <option value="read" <?= ($_GET['status']??'')==='read'?'selected':'' ?>>Read</option>
    </select>
    <button type="submit"><i class="fa fa-filter"></i> Filter</button>
    <a href="notification.php"><i class="fa fa-rotate-right"></i> Reset</a>
  </form>

  <!-- ✅ TABLE -->
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>#</th><th>Title</th><th>Message</th><th>Status</th><th>Date</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if($notifications): foreach($notifications as $i=>$n): ?>
        <tr class="<?= $n['read_status']=='unread'?'unread':'' ?>">
          <td><?= $i+1 ?></td>
          <td><?= htmlspecialchars($n['title'] ?: $n['announcement_title']) ?></td>
          <td><?= htmlspecialchars(substr($n['message'],0,60)) ?><?= strlen($n['message'])>60?'...':'' ?></td>
          <td>
  <?php if($n['read_status']=='unread'): ?>
    <i class="fa fa-envelope" style="color:#C62828;"></i>
  <?php else: ?>
    <i class="fa fa-envelope-open" style="color:gray;"></i>
  <?php endif; ?>
</td>
          <td><?= htmlspecialchars($n['created_at']) ?></td>
          <td>
            <div class="action-buttons">
              <?php if($n['read_status']=='unread'): ?>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="notification_id" value="<?= $n['notification_id'] ?>">
                <input type="hidden" name="action" value="mark_read">
                <button class="btn-read" title="Mark as Read"><i class="fa fa-check"></i></button>
              </form>
              <?php endif; ?>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="notification_id" value="<?= $n['notification_id'] ?>">
                <input type="hidden" name="action" value="delete">
                <button class="btn-read" title="Mark as Read" onclick="markRead(<?= $n['notification_id'] ?>)">
  <i class="fa fa-envelope-open"></i>
</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="6" style="text-align:center;color:#777;">No notifications found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ✅ ALERT POPUP -->
<div id="popup" class="alert-popup <?= $type ?>"><h3><?= $message ?></h3><button class="alert-btn" onclick="closeAlert()">OK</button></div>

<script>
function closeAlert(){document.getElementById('popup').classList.remove('show');}
if("<?= $message ?>"){document.getElementById('popup').classList.add('show');}
</script>
<script>
function markRead(id){
  fetch('notification.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `action=mark_read&notification_id=${id}`
  })
  .then(res=>res.text())
  .then(()=>location.reload());
}
</script>
<script src="../assets/js/sidebar.js"></script>
<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>
