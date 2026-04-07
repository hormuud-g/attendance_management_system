<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// Modified access control to allow both super_admin and faculty_admin
if (!isset($_SESSION['user']) || !in_array(strtolower($_SESSION['user']['role'] ?? ''), ['super_admin', 'faculty_admin'])) {
  header("Location: ../login.php");
  exit;
}

// Get current user role and ID for restrictions
$current_user_role = strtolower($_SESSION['user']['role'] ?? '');
$current_user_linked_id = $_SESSION['user']['linked_id'] ?? null;
$is_faculty_admin = ($current_user_role === 'faculty_admin');

// Get faculty name for faculty admin
$faculty_name = '';
if ($is_faculty_admin && $current_user_linked_id) {
    $stmt = $pdo->prepare("SELECT faculty_name FROM faculties WHERE faculty_id = ?");
    $stmt->execute([$current_user_linked_id]);
    $faculty_name = $stmt->fetchColumn();
}

date_default_timezone_set('Africa/Nairobi');
$message = ""; $type = "";

/* ================== DELETE ALL LOGS ================== */
// Only super_admin can clear logs
if (isset($_POST['clear_logs'])) {
  // Check if user is super_admin
  if (!$is_faculty_admin) {
    try {
      $pdo->exec("TRUNCATE TABLE audit_log");
      $message = "🗑️ All logs cleared successfully!";
      $type = "success";
    } catch (Exception $e) {
      $message = "❌ Error: " . $e->getMessage();
      $type = "error";
    }
  } else {
    $message = "❌ Faculty admins cannot clear logs!";
    $type = "error";
  }
}

/* ================== SEARCH FILTER ================== */
$search = trim($_GET['search'] ?? '');

// Base SQL with optional faculty filtering
$sql = "
  SELECT a.*, u.username 
  FROM audit_log a 
  LEFT JOIN users u ON u.user_id = a.user_id
";

$where_conditions = [];
$params = [];

// For faculty admin, filter logs related to their faculty
if ($is_faculty_admin && $current_user_linked_id) {
    // Get all users from this faculty (students, teachers, etc.)
    // This is a complex filter - we need to find all logs related to this faculty
    $where_conditions[] = "(
        -- Logs by users from this faculty
        u.linked_id = ? AND u.linked_table IN ('student', 'teacher', 'department', 'program', 'class')
        
        -- OR logs about students from this faculty
        OR a.description LIKE ?
        
        -- OR logs about teachers from this faculty
        OR a.description LIKE ?
        
        -- OR logs about classes from this faculty
        OR a.description LIKE ?
        
        -- OR logs about departments from this faculty
        OR a.description LIKE ?
        
        -- OR logs about programs from this faculty
        OR a.description LIKE ?
    )";
    
    $faculty_search = "%" . $faculty_name . "%";
    $params[] = $current_user_linked_id;
    $params[] = $faculty_search;
    $params[] = $faculty_search;
    $params[] = $faculty_search;
    $params[] = $faculty_search;
    $params[] = $faculty_search;
}

// Add search condition if provided
if (!empty($search)) {
    $where_conditions[] = "(u.username LIKE :search OR a.action_type LIKE :search OR a.description LIKE :search OR a.ip_address LIKE :search)";
}

// Combine WHERE conditions
if (!empty($where_conditions)) {
    $sql .= " WHERE " . implode(" AND ", $where_conditions);
}

$sql .= " ORDER BY a.action_time DESC LIMIT 500";

$stmt = $pdo->prepare($sql);

// Bind parameters
if (!empty($search)) {
    $stmt->bindValue(':search', "%$search%");
}

// Execute with params array if needed
if ($is_faculty_admin && $current_user_linked_id && !empty($params)) {
    $stmt->execute($params);
} else if (!empty($search)) {
    $stmt->execute();
} else {
    $stmt->execute();
}

$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Alternative simpler approach: Get all logs and filter in PHP
// This is more reliable but may be slower for large datasets
if ($is_faculty_admin && $current_user_linked_id && empty($logs)) {
    // Fallback: Get all logs and filter in PHP
    $all_logs = $pdo->query("
        SELECT a.*, u.username 
        FROM audit_log a 
        LEFT JOIN users u ON u.user_id = a.user_id
        ORDER BY a.action_time DESC 
        LIMIT 1000
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $logs = [];
    foreach ($all_logs as $log) {
        // Check if log is related to this faculty
        $is_relevant = false;
        
        // Check username match
        if (stripos($log['username'] ?? '', $faculty_name) !== false) {
            $is_relevant = true;
        }
        
        // Check description for faculty name
        if (stripos($log['description'] ?? '', $faculty_name) !== false) {
            $is_relevant = true;
        }
        
        // Check description for common faculty-related terms
        $faculty_terms = ['faculty', 'department', 'program', 'class', 'student', 'teacher'];
        foreach ($faculty_terms as $term) {
            if (stripos($log['description'] ?? '', $term) !== false) {
                // Also check if it might be about this faculty
                // This is a heuristic - not 100% accurate
                $is_relevant = true;
                break;
            }
        }
        
        if ($is_relevant) {
            $logs[] = $log;
        }
        
        // Limit to 500 logs
        if (count($logs) >= 500) {
            break;
        }
    }
}

include('../includes/header.php');
?>
<style>
  .sidebar.collapsed ~ .main-content {
    margin-left: 70px;
  }
  .faculty-admin-badge {
    display: inline-block;
    background-color: #FF6B00;
    color: white;
    padding: 3px 10px;
    border-radius: 4px;
    font-size: 12px;
    margin-left: 10px;
    vertical-align: middle;
  }
  .info-message {
    background-color: #e3f2fd;
    color: #1565c0;
    padding: 8px 12px;
    border-radius: 4px;
    margin: 10px 0 20px;
    font-size: 13px;
    border-left: 3px solid #0072CE;
  }
</style>

<div class="main-content">
  <div class="page-header">
    <h1>
      Audit Log History
      <?php if($is_faculty_admin): ?>
      <?php endif; ?>
    </h1>
  </div>

 

  <!-- SEARCH + CLEAR -->
  <div class="filter-box">
    <form method="GET" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search user, action or IP..." style="flex:1;min-width:200px;">
      <button type="submit" class="btn blue"><i class="fa fa-search"></i> Search</button>
      
      
    </form>

    <?php if(!$is_faculty_admin): // Only super_admin can clear logs ?>
    <form method="POST" onsubmit="return confirm('Are you sure you want to clear all logs? This action cannot be undone.');" style="margin-top:10px;">
      <button type="submit" name="clear_logs" class="btn red"><i class="fa fa-trash"></i> Clear All Logs</button>
    </form>
    <?php endif; ?>
  </div>

  <!-- LOG TABLE -->
  <div class="table-wrapper">
    <div style="padding:10px 15px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #eee;">
      <h3 style="color:#0072CE;margin:0;">
        <i class="fa fa-history"></i> 
        <?php if($is_faculty_admin): ?>
          Recent Audit Logs 
        <?php else: ?>
          Recent Audit Logs
        <?php endif; ?>
      </h3>
      <span style="background:#f0f0f0;padding:4px 10px;border-radius:20px;font-size:12px;">
        <?= count($logs) ?> records shown
      </span>
    </div>
    
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
        <tr>
          <td colspan="7" style="text-align:center;padding:30px;color:#999;">
            <i class="fa fa-info-circle" style="font-size:20px;margin-bottom:10px;display:block;"></i>
            No logs found.
            <?php if($is_faculty_admin && empty($search)): ?>
              <br><small>Try adjusting your search or check back later for faculty-related activities.</small>
            <?php elseif(!empty($search)): ?>
              <br><small>Try a different search term.</small>
            <?php endif; ?>
          </td>
        </tr>
      <?php else: ?>
        <?php $i=1; foreach($logs as $log): ?>
          <tr>
            <td><?= $i++ ?></td>
            <td>
              <strong><?= htmlspecialchars($log['username'] ?? 'System') ?></strong>
              <?php if($is_faculty_admin && isset($log['user_id']) && $log['user_id']): ?>
                <br><small style="color:#666;">ID: <?= $log['user_id'] ?></small>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge <?= strtolower($log['action_type']) ?>">
                <?= htmlspecialchars($log['action_type']) ?>
              </span>
            </td>
            <td style="max-width:300px;"><?= htmlspecialchars($log['description']) ?></td>
            <td>
              <?= htmlspecialchars($log['ip_address']) ?>
              <?php if($log['ip_address']): ?>
                <br><small style="color:#666;"><?= long2ip(ip2long($log['ip_address'])) ?></small>
              <?php endif; ?>
            </td>
            <td style="max-width:200px;font-size:11px;color:#666;">
              <?= htmlspecialchars(substr($log['user_agent'] ?? '', 0, 50)) ?>...
            </td>
            <td>
              <?= date('d M Y H:i', strtotime($log['action_time'])) ?>
              <br><small style="color:#666;"><?= time_elapsed_string($log['action_time']) ?></small>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
    
    <?php if(count($logs) >= 500): ?>
    <div style="padding:10px;text-align:center;color:#666;border-top:1px solid #eee;">
      <i class="fa fa-exclamation-triangle"></i> Showing first 500 records. Refine your search for more specific results.
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if($message): ?>
<div class="alert <?= $type ?>"><strong><?= $message ?></strong></div>
<script>setTimeout(()=>document.querySelector('.alert').remove(),5000);</script>
<?php endif; ?>

<!-- Helper function for time elapsed -->
<?php
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    
    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;
    
    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }
    
    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}
?>

<!-- STYLE -->
<style>
body{font-family:'Poppins',sans-serif;background:#f5f8fa;margin:0;}
.main-content{padding:25px;margin-left:250px;margin-top:90px;}
.page-header h1{color:#0072CE;margin-bottom:10px;display:flex;align-items:center;flex-wrap:wrap;}
.filter-box{background:#fff;padding:15px 20px;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.08);margin-bottom:20px;}
input[type=text]{padding:8px;border:1px solid #ccc;border-radius:6px;background:#f8f8f8;font-size:14px;}
.btn{border:none;padding:9px 15px;border-radius:6px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block;}
.btn.blue{background:#0072CE;color:#fff;}
.btn.red{background:#C62828;color:#fff;}
.btn.gray{background:#6c757d;color:#fff;}
.table-wrapper{overflow:auto;background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.08);}
table{width:100%;border-collapse:collapse;}
th,td{padding:12px 15px;border-bottom:1px solid #eee;text-align:left;font-size:13px;}
thead th{background:#0072CE;color:#fff;position:sticky;top:0;}
tr:hover{background:#f4f8ff;}
.alert{position:fixed;top:15px;right:15px;background:#00843D;color:#fff;padding:10px 20px;border-radius:6px;font-weight:600;z-index:9999;box-shadow:0 2px 10px rgba(0,0,0,0.2);}
.alert.error{background:#C62828;}
.badge{padding:4px 8px;border-radius:5px;color:#fff;font-weight:500;text-transform:capitalize;font-size:11px;display:inline-block;}
.badge.insert{background:#4CAF50;}
.badge.update{background:#FFC107;color:#000;}
.badge.delete{background:#F44336;}
.badge.login{background:#2196F3;}
.badge.logout{background:#9C27B0;}
.badge.export{background:#FF9800;}
.badge.import{background:#009688;}

/* Faculty Admin Badge */
.faculty-admin-badge {
    display: inline-block;
    background-color: #FF6B00;
    color: white;
    padding: 3px 10px;
    border-radius: 4px;
    font-size: 12px;
    margin-left: 10px;
    vertical-align: middle;
}
.info-message {
    background-color: #e3f2fd;
    color: #1565c0;
    padding: 8px 12px;
    border-radius: 4px;
    margin: 10px 0 20px;
    font-size: 13px;
    border-left: 3px solid #0072CE;
}

@media(max-width:768px){
  .main-content{margin-left:0;padding:15px;}
  table{font-size:12px;}
  th,td{padding:8px 10px;}
  .filter-box form{flex-direction:column;align-items:stretch;}
  input[type=text]{width:100%;}
}
</style>

<?php include('../includes/footer.php'); ?>
<?php ob_end_flush(); ?>