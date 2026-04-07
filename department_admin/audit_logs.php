<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// Modified access control to allow super_admin, faculty_admin, and department_admin
if (!isset($_SESSION['user']) || !in_array(strtolower($_SESSION['user']['role'] ?? ''), ['super_admin', 'faculty_admin', 'department_admin'])) {
  header("Location: ../login.php");
  exit;
}

// Get current user role and ID for restrictions
$current_user_role = strtolower($_SESSION['user']['role'] ?? '');
$current_user_linked_id = $_SESSION['user']['linked_id'] ?? null;
$is_faculty_admin = ($current_user_role === 'faculty_admin');
$is_department_admin = ($current_user_role === 'department_admin');

// Get faculty/department names based on role
$faculty_name = '';
$department_name = '';
$faculty_id = null;
$department_id = null;

if ($is_faculty_admin && $current_user_linked_id) {
    $faculty_id = $current_user_linked_id;
    $stmt = $pdo->prepare("SELECT faculty_name FROM faculties WHERE faculty_id = ?");
    $stmt->execute([$faculty_id]);
    $faculty_name = $stmt->fetchColumn();
} elseif ($is_department_admin && $current_user_linked_id) {
    $department_id = $current_user_linked_id;
    $stmt = $pdo->prepare("
        SELECT d.department_name, d.faculty_id, f.faculty_name 
        FROM departments d
        LEFT JOIN faculties f ON d.faculty_id = f.faculty_id
        WHERE d.department_id = ?
    ");
    $stmt->execute([$department_id]);
    $dept_info = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($dept_info) {
        $department_name = $dept_info['department_name'];
        $faculty_id = $dept_info['faculty_id'];
        $faculty_name = $dept_info['faculty_name'] ?? '';
    }
}

date_default_timezone_set('Africa/Nairobi');
$message = ""; $type = "";

/* ================== DELETE ALL LOGS ================== */
// Only super_admin can clear logs
if (isset($_POST['clear_logs'])) {
  // Check if user is super_admin
  if ($current_user_role === 'super_admin') {
    try {
      $pdo->exec("TRUNCATE TABLE audit_log");
      $message = "🗑️ All logs cleared successfully!";
      $type = "success";
    } catch (Exception $e) {
      $message = "❌ Error: " . $e->getMessage();
      $type = "error";
    }
  } else {
    $message = "❌ You don't have permission to clear logs!";
    $type = "error";
  }
}

/* ================== SEARCH FILTER WITH ROLE-BASED RESTRICTIONS ================== */
$search = trim($_GET['search'] ?? '');

// Base SQL
$sql = "
  SELECT a.*, u.username, u.role, u.linked_id, u.linked_table
  FROM audit_log a 
  LEFT JOIN users u ON u.user_id = a.user_id
";

$where_conditions = [];
$params = [];

// Apply role-based filtering
if ($current_user_role === 'super_admin') {
    // Super admin sees all logs - no filtering needed
    // Just add search if provided
    if (!empty($search)) {
        $where_conditions[] = "(u.username LIKE :search OR a.action_type LIKE :search OR a.description LIKE :search OR a.ip_address LIKE :search)";
    }
} 
elseif ($current_user_role === 'faculty_admin' && $faculty_id) {
    // Faculty admin sees logs related to their faculty
    
    // First, get all department IDs in this faculty
    $dept_stmt = $pdo->prepare("SELECT department_id FROM departments WHERE faculty_id = ?");
    $dept_stmt->execute([$faculty_id]);
    $department_ids = $dept_stmt->fetchAll(PDO::FETCH_COLUMN);
    $dept_list = implode(',', array_map('intval', $department_ids));
    
    // Get all program IDs in this faculty
    $prog_stmt = $pdo->prepare("SELECT program_id FROM programs WHERE faculty_id = ?");
    $prog_stmt->execute([$faculty_id]);
    $program_ids = $prog_stmt->fetchAll(PDO::FETCH_COLUMN);
    $prog_list = implode(',', array_map('intval', $program_ids));
    
    // Get all class IDs in this faculty
    $class_stmt = $pdo->prepare("SELECT class_id FROM classes WHERE faculty_id = ?");
    $class_stmt->execute([$faculty_id]);
    $class_ids = $class_stmt->fetchAll(PDO::FETCH_COLUMN);
    $class_list = implode(',', array_map('intval', $class_ids));
    
    // Get all teacher IDs in this faculty
    $teacher_stmt = $pdo->prepare("SELECT teacher_id FROM teachers WHERE faculty_id = ?");
    $teacher_stmt->execute([$faculty_id]);
    $teacher_ids = $teacher_stmt->fetchAll(PDO::FETCH_COLUMN);
    $teacher_list = implode(',', array_map('intval', $teacher_ids));
    
    // Get all student IDs in this faculty
    $student_stmt = $pdo->prepare("SELECT student_id FROM students WHERE faculty_id = ?");
    $student_stmt->execute([$faculty_id]);
    $student_ids = $student_stmt->fetchAll(PDO::FETCH_COLUMN);
    $student_list = implode(',', array_map('intval', $student_ids));
    
    // Build complex WHERE condition for faculty admin
    $faculty_conditions = [];
    
    // Logs by users from this faculty
    if (!empty($teacher_ids) || !empty($student_ids)) {
        $faculty_conditions[] = "(u.linked_id IN (" . 
            (!empty($teacher_ids) ? implode(',', $teacher_ids) : '0') . 
            ") AND u.linked_table IN ('teacher', 'student'))";
    }
    
    // Logs about faculty-specific entities (using description keywords)
    if (!empty($faculty_name)) {
        $faculty_conditions[] = "a.description LIKE '%" . addcslashes($faculty_name, '%_') . "%'";
    }
    
    // Logs about departments in this faculty
    if (!empty($dept_list) && $dept_list !== '0') {
        foreach ($department_ids as $dept_id) {
            $dept_name = $pdo->prepare("SELECT department_name FROM departments WHERE department_id = ?")->execute([$dept_id])->fetchColumn();
            if ($dept_name) {
                $faculty_conditions[] = "a.description LIKE '%" . addcslashes($dept_name, '%_') . "%'";
            }
        }
    }
    
    // Logs about programs in this faculty
    if (!empty($prog_list) && $prog_list !== '0') {
        $prog_names = $pdo->query("SELECT program_name FROM programs WHERE program_id IN ($prog_list)")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($prog_names as $prog_name) {
            if ($prog_name) {
                $faculty_conditions[] = "a.description LIKE '%" . addcslashes($prog_name, '%_') . "%'";
            }
        }
    }
    
    // Logs about classes in this faculty
    if (!empty($class_list) && $class_list !== '0') {
        $class_names = $pdo->query("SELECT class_name FROM classes WHERE class_id IN ($class_list)")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($class_names as $class_name) {
            if ($class_name) {
                $faculty_conditions[] = "a.description LIKE '%" . addcslashes($class_name, '%_') . "%'";
            }
        }
    }
    
    // Logs about teachers in this faculty
    if (!empty($teacher_list) && $teacher_list !== '0') {
        $teacher_names = $pdo->query("SELECT teacher_name FROM teachers WHERE teacher_id IN ($teacher_list)")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($teacher_names as $teacher_name) {
            if ($teacher_name) {
                $faculty_conditions[] = "a.description LIKE '%" . addcslashes($teacher_name, '%_') . "%'";
            }
        }
    }
    
    // Logs about students in this faculty
    if (!empty($student_list) && $student_list !== '0') {
        $student_names = $pdo->query("SELECT full_name FROM students WHERE student_id IN ($student_list)")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($student_names as $student_name) {
            if ($student_name) {
                $faculty_conditions[] = "a.description LIKE '%" . addcslashes($student_name, '%_') . "%'";
            }
        }
    }
    
    if (!empty($faculty_conditions)) {
        $where_conditions[] = "(" . implode(" OR ", $faculty_conditions) . ")";
    }
    
    // Add search if provided
    if (!empty($search)) {
        $where_conditions[] = "(u.username LIKE :search OR a.action_type LIKE :search OR a.description LIKE :search OR a.ip_address LIKE :search)";
    }
}
elseif ($current_user_role === 'department_admin' && $department_id) {
    // Department admin sees logs related to their department
    
    // Get all program IDs in this department
    $prog_stmt = $pdo->prepare("SELECT program_id FROM programs WHERE department_id = ?");
    $prog_stmt->execute([$department_id]);
    $program_ids = $prog_stmt->fetchAll(PDO::FETCH_COLUMN);
    $prog_list = implode(',', array_map('intval', $program_ids));
    
    // Get all class IDs in this department (through programs)
    $class_ids = [];
    if (!empty($program_ids)) {
        $class_stmt = $pdo->prepare("SELECT class_id FROM classes WHERE program_id IN (" . implode(',', array_fill(0, count($program_ids), '?')) . ")");
        $class_stmt->execute($program_ids);
        $class_ids = $class_stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    $class_list = implode(',', array_map('intval', $class_ids));
    
    // Get all teacher IDs in this department (through classes)
    $teacher_ids = [];
    if (!empty($class_ids)) {
        $teacher_stmt = $pdo->prepare("SELECT DISTINCT teacher_id FROM timetable WHERE class_id IN (" . implode(',', array_fill(0, count($class_ids), '?')) . ")");
        $teacher_stmt->execute($class_ids);
        $teacher_ids = $teacher_stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    $teacher_list = implode(',', array_map('intval', $teacher_ids));
    
    // Get all student IDs in this department (through classes)
    $student_ids = [];
    if (!empty($class_ids)) {
        $student_stmt = $pdo->prepare("SELECT student_id FROM students WHERE class_id IN (" . implode(',', array_fill(0, count($class_ids), '?')) . ")");
        $student_stmt->execute($class_ids);
        $student_ids = $student_stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    $student_list = implode(',', array_map('intval', $student_ids));
    
    // Build complex WHERE condition for department admin
    $dept_conditions = [];
    
    // Logs by users from this department
    if (!empty($teacher_ids) || !empty($student_ids)) {
        $dept_conditions[] = "(u.linked_id IN (" . 
            (!empty($teacher_ids) ? implode(',', $teacher_ids) : '0') . 
            ") AND u.linked_table IN ('teacher', 'student'))";
    }
    
    // Logs about department itself
    if (!empty($department_name)) {
        $dept_conditions[] = "a.description LIKE '%" . addcslashes($department_name, '%_') . "%'";
    }
    
    // Logs about programs in this department
    if (!empty($prog_list) && $prog_list !== '0') {
        $prog_names = $pdo->query("SELECT program_name FROM programs WHERE program_id IN ($prog_list)")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($prog_names as $prog_name) {
            if ($prog_name) {
                $dept_conditions[] = "a.description LIKE '%" . addcslashes($prog_name, '%_') . "%'";
            }
        }
    }
    
    // Logs about classes in this department
    if (!empty($class_list) && $class_list !== '0') {
        $class_names = $pdo->query("SELECT class_name FROM classes WHERE class_id IN ($class_list)")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($class_names as $class_name) {
            if ($class_name) {
                $dept_conditions[] = "a.description LIKE '%" . addcslashes($class_name, '%_') . "%'";
            }
        }
    }
    
    // Logs about teachers in this department
    if (!empty($teacher_list) && $teacher_list !== '0') {
        $teacher_names = $pdo->query("SELECT teacher_name FROM teachers WHERE teacher_id IN ($teacher_list)")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($teacher_names as $teacher_name) {
            if ($teacher_name) {
                $dept_conditions[] = "a.description LIKE '%" . addcslashes($teacher_name, '%_') . "%'";
            }
        }
    }
    
    // Logs about students in this department
    if (!empty($student_list) && $student_list !== '0') {
        $student_names = $pdo->query("SELECT full_name FROM students WHERE student_id IN ($student_list)")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($student_names as $student_name) {
            if ($student_name) {
                $dept_conditions[] = "a.description LIKE '%" . addcslashes($student_name, '%_') . "%'";
            }
        }
    }
    
    if (!empty($dept_conditions)) {
        $where_conditions[] = "(" . implode(" OR ", $dept_conditions) . ")";
    }
    
    // Add search if provided
    if (!empty($search)) {
        $where_conditions[] = "(u.username LIKE :search OR a.action_type LIKE :search OR a.description LIKE :search OR a.ip_address LIKE :search)";
    }
}

// Combine WHERE conditions
if (!empty($where_conditions)) {
    $sql .= " WHERE " . implode(" AND ", $where_conditions);
}

$sql .= " ORDER BY a.action_time DESC LIMIT 500";

$stmt = $pdo->prepare($sql);

// Bind search parameter if needed
if (!empty($search)) {
    $search_param = "%$search%";
    $stmt->bindParam(':search', $search_param, PDO::PARAM_STR);
}

$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

include('../includes/header.php');
?>
<style>
  .sidebar.collapsed ~ .main-content {
    margin-left: 70px;
  }
  .role-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 4px;
    font-size: 12px;
    margin-left: 10px;
    vertical-align: middle;
  }
  .faculty-admin-badge {
    background-color: #FF6B00;
    color: white;
  }
  .department-admin-badge {
    background-color: #9C27B0;
    color: white;
  }
  .info-message {
    background-color: #e3f2fd;
    color: #1565c0;
    padding: 8px 12px;
    border-radius: 4px;
    margin: 10px 0 20px;
    font-size: 13px;
    border-left: 3px solid #0072CE;
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .info-message i {
    font-size: 18px;
  }
</style>

<div class="main-content">
  <div class="page-header">
    <h1>
      Audit Log History
      <?php if($is_faculty_admin): ?>
        <span class="role-badge faculty-admin-badge">Faculty Admin View - <?= htmlspecialchars($faculty_name) ?></span>
      <?php elseif($is_department_admin): ?>
      <?php endif; ?>
    </h1>
  </div>

  <?php if($is_faculty_admin): ?>
  <div class="info-message">
    <i class="fa fa-info-circle"></i>
    <div>
      You are viewing audit logs related to <strong><?= htmlspecialchars($faculty_name) ?></strong> faculty. 
      This includes logs about departments, programs, classes, teachers, and students within your faculty.
    </div>
  </div>
  <?php elseif($is_department_admin): ?>
 
  <?php endif; ?>

  <!-- SEARCH + CLEAR -->
  <div class="filter-box">
    <form method="GET" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search user, action or IP..." style="flex:1;min-width:200px;">
      <button type="submit" class="btn blue"><i class="fa fa-search"></i> Search</button>
      
      <?php if($current_user_role === 'super_admin'): ?>
        <a href="audit_log.php" class="btn gray"><i class="fa fa-refresh"></i> Reset</a>
      <?php else: ?>
        <a href="audit_log.php" class="btn gray"><i class="fa fa-refresh"></i> Reset</a>
      <?php endif; ?>
    </form>

    <?php if($current_user_role === 'super_admin'): // Only super_admin can clear logs ?>
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
        <?php elseif($is_department_admin): ?>
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
          <th>Role</th>
          <th>Action</th>
          <th>Description</th>
          <th>IP Address</th>
          <th>Time</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$logs): ?>
        <tr>
          <td colspan="7" style="text-align:center;padding:30px;color:#999;">
            <i class="fa fa-info-circle" style="font-size:20px;margin-bottom:10px;display:block;"></i>
            No logs found.
            <?php if(($is_faculty_admin || $is_department_admin) && empty($search)): ?>
              <br><small>Try adjusting your search or check back later for activities related to your 
              <?= $is_faculty_admin ? 'faculty' : 'department' ?>.</small>
            <?php elseif(!empty($search)): ?>
              <br><small>Try a different search term.</small>
            <?php endif; ?>
          </td>
        </tr>
      <?php else: ?>
        <?php $i=1; foreach($logs as $log): ?>
          <?php
          // Highlight rows that might be especially relevant
          $row_class = '';
          if ($is_faculty_admin && $faculty_name && strpos($log['description'] ?? '', $faculty_name) !== false) {
              $row_class = 'style="background: rgba(255, 107, 0, 0.05);"';
          } elseif ($is_department_admin && $department_name && strpos($log['description'] ?? '', $department_name) !== false) {
              $row_class = 'style="background: rgba(156, 39, 176, 0.05);"';
          }
          ?>
          <tr <?= $row_class ?>>
            <td><?= $i++ ?></td>
            <td>
              <strong><?= htmlspecialchars($log['username'] ?? 'System') ?></strong>
              <?php if(isset($log['linked_id']) && $log['linked_id']): ?>
                <br><small style="color:#666;">ID: <?= $log['linked_id'] ?> (<?= $log['linked_table'] ?? 'N/A' ?>)</small>
              <?php endif; ?>
            </td>
            <td>
              <?php if(isset($log['role'])): ?>
                <span class="badge <?= strtolower($log['role']) ?>">
                  <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $log['role']))) ?>
                </span>
              <?php else: ?>
                <span class="badge system">System</span>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge <?= strtolower($log['action_type']) ?>">
                <?= htmlspecialchars($log['action_type']) ?>
              </span>
            </td>
            <td style="max-width:300px;">
              <?= htmlspecialchars($log['description']) ?>
              <?php if($is_faculty_admin && $faculty_name && strpos($log['description'], $faculty_name) !== false): ?>
                <br><small style="color:#FF6B00;">📌 Related to your faculty</small>
              <?php elseif($is_department_admin && $department_name && strpos($log['description'], $department_name) !== false): ?>
                <br><small style="color:#9C27B0;">📌 Related to your department</small>
              <?php endif; ?>
            </td>
            <td>
              <?= htmlspecialchars($log['ip_address']) ?>
              <?php if(filter_var($log['ip_address'], FILTER_VALIDATE_IP)): ?>
                <br><small style="color:#666;"><?= long2ip(ip2long($log['ip_address'])) ?></small>
              <?php endif; ?>
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
    if (!$datetime) return 'N/A';
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
.badge.super_admin{background:#00843D;}
.badge.faculty_admin{background:#FF6B00;}
.badge.department_admin{background:#9C27B0;}
.badge.teacher{background:#2196F3;}
.badge.student{background:#4CAF50;}
.badge.parent{background:#FF9800;}
.badge.system{background:#607D8B;}

/* Role Badges */
.role-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 4px;
    font-size: 12px;
    margin-left: 10px;
    vertical-align: middle;
}
.faculty-admin-badge {
    background-color: #FF6B00;
    color: white;
}
.department-admin-badge {
    background-color: #9C27B0;
    color: white;
}
.info-message {
    background-color: #e3f2fd;
    color: #1565c0;
    padding: 8px 12px;
    border-radius: 4px;
    margin: 10px 0 20px;
    font-size: 13px;
    border-left: 3px solid #0072CE;
    display: flex;
    align-items: center;
    gap: 10px;
}
.info-message i {
    font-size: 18px;
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