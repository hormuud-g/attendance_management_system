<?php
/*******************************************************************************************
 * PARENT PORTAL — View ALL Announcements
 * Role: Parent (View all system announcements)
 * Author: GPT-5 | PHP 8.2 | PDO Secure Queries
 *******************************************************************************************/
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Access Control
if (!isset($_SESSION['user']) || strtolower($_SESSION['user']['role']) !== 'parent') {
  session_destroy();
  header("Location: ../login.php?error=unauthorized");
  exit;
}

$parent_name = $_SESSION['user']['username'] ?? 'Parent';
date_default_timezone_set('Africa/Nairobi');

// ✅ Handle search
$search = trim($_GET['search'] ?? '');

// ✅ Fetch ALL announcements (no restriction by target_role)
if ($search) {
  $stmt = $pdo->prepare("
    SELECT a.announcement_id, a.title, a.message, a.image_path, a.target_role,
           a.created_at, u.username AS created_by
    FROM announcement a
    LEFT JOIN users u ON a.created_by = u.user_id
    WHERE a.status='active'
      AND (a.title LIKE ? OR a.message LIKE ?)
    ORDER BY a.created_at DESC
  ");
  $stmt->execute(["%$search%", "%$search%"]);
} else {
  $stmt = $pdo->query("
    SELECT a.announcement_id, a.title, a.message, a.image_path, a.target_role,
           a.created_at, u.username AS created_by
    FROM announcement a
    LEFT JOIN users u ON a.created_by = u.user_id
    WHERE a.status='active'
    ORDER BY a.created_at DESC
  ");
}
$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
.search-bar{margin-bottom:20px;display:flex;gap:10px;flex-wrap:wrap;}
.search-bar input{flex:1;padding:10px;border-radius:8px;border:1px solid #ccc;}
.search-bar button{background:var(--green);color:#fff;border:none;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:600;}
.ann-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;}
.ann-card{background:#fff;border-radius:12px;padding:20px;box-shadow:0 2px 10px rgba(0,0,0,0.08);position:relative;overflow:hidden;}
.ann-card::before{content:'';position:absolute;top:0;left:0;width:6px;height:100%;background:var(--blue);}
.ann-card h3{color:var(--blue);margin:0 0 10px;}
.ann-card p{color:#555;line-height:1.6;font-size:14px;}
.ann-card small{display:block;margin-top:8px;color:#888;font-size:12px;}
.ann-img{width:100%;max-height:180px;object-fit:cover;border-radius:8px;margin-bottom:10px;}
.badge{background:var(--blue);color:#fff;font-size:12px;padding:2px 8px;border-radius:6px;margin-left:5px;}
.role-badge{position:absolute;top:15px;right:15px;background:var(--amber);color:#000;font-size:11px;padding:3px 8px;border-radius:5px;}
.no-data{text-align:center;color:#777;font-size:15px;margin-top:50px;}
@media(max-width:768px){.main-content{margin-left:0;padding:15px;}.ann-list{grid-template-columns:1fr;}}
</style>

<div class="main-content">
  <div class="page-header">
    <h1>📢 All Announcements</h1>
    <p>Welcome, <strong><?= htmlspecialchars($parent_name) ?></strong>. You can view all announcements from all roles.</p>
  </div>

  <!-- ✅ Search Bar -->
  <form method="GET" class="search-bar">
    <input type="text" name="search" placeholder="Search announcements..." value="<?= htmlspecialchars($search) ?>">
    <button type="submit"><i class="fa fa-search"></i> Search</button>
  </form>

  <!-- ✅ Announcements List -->
  <?php if ($announcements): ?>
    <div class="ann-list">
      <?php foreach ($announcements as $a): ?>
        <?php
          $posted = date('M d, Y h:i A', strtotime($a['created_at']));
          $recent = (time() - strtotime($a['created_at'])) < (7 * 24 * 60 * 60); // within 7 days
        ?>
        <div class="ann-card">
          <span class="role-badge"><?= strtoupper(htmlspecialchars($a['target_role'])) ?></span>

          <?php if(!empty($a['image_path'])): ?>
            <img src="../uploads/<?= htmlspecialchars($a['image_path']) ?>" alt="Announcement Image" class="ann-img">
          <?php endif; ?>

          <h3><?= htmlspecialchars($a['title']) ?>
            <?php if($recent): ?><span class="badge">New</span><?php endif; ?>
          </h3>

          <p><?= nl2br(htmlspecialchars($a['message'])) ?></p>
          <small>
            <i class="fa fa-user"></i> <?= htmlspecialchars($a['created_by'] ?? 'Admin') ?> |
            <i class="fa fa-calendar"></i> <?= $posted ?>
          </small>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="no-data"><i class="fa fa-info-circle"></i> No announcements found.</div>
  <?php endif; ?>
</div>

<script src="../assets/js/sidebar.js"></script>
<?php include('../includes/footer.php'); ?>
