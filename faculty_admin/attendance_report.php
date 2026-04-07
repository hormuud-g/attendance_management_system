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

date_default_timezone_set('Africa/Nairobi');

/* ================= FETCH FILTER DATA ================= */
// Get all active campuses (faculty admin sees all campuses)
$campuses = $pdo->query("SELECT * FROM campus WHERE status='active' ORDER BY campus_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Get faculty info for faculty admin
if ($is_faculty_admin && $current_user_linked_id) {
    $stmt = $pdo->prepare("SELECT faculty_name FROM faculties WHERE faculty_id = ?");
    $stmt->execute([$current_user_linked_id]);
    $faculty_name = $stmt->fetchColumn();
}

/* ================= AJAX HANDLERS ================= */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    // GET FACULTIES BY CAMPUS
    if ($_GET['ajax'] == 'get_faculties_by_campus') {
        $campus_id = $_GET['campus_id'] ?? 0;
        
        // If faculty admin, only return their faculty
        if ($is_faculty_admin && $current_user_linked_id) {
            $stmt = $pdo->prepare("
                SELECT f.faculty_id, f.faculty_name 
                FROM faculties f
                WHERE f.faculty_id = ? AND f.status = 'active'
            ");
            $stmt->execute([$current_user_linked_id]);
            $faculties = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $pdo->prepare("
                SELECT DISTINCT f.faculty_id, f.faculty_name 
                FROM faculties f
                JOIN faculty_campus fc ON f.faculty_id = fc.faculty_id
                WHERE fc.campus_id = ?
                AND f.status = 'active'
                ORDER BY f.faculty_name
            ");
            $stmt->execute([$campus_id]);
            $faculties = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        echo json_encode(['status' => 'success', 'faculties' => $faculties]);
        exit;
    }
    
    // GET DEPARTMENTS BY FACULTY & CAMPUS
    if ($_GET['ajax'] == 'get_departments_by_faculty') {
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        // If faculty admin, ensure they're only accessing their faculty
        if ($is_faculty_admin && $faculty_id != $current_user_linked_id) {
            echo json_encode(['status' => 'success', 'departments' => []]);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT department_id, department_name 
            FROM departments 
            WHERE faculty_id = ? 
            AND campus_id = ?
            AND status = 'active'
            ORDER BY department_name
        ");
        $stmt->execute([$faculty_id, $campus_id]);
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'departments' => $departments]);
        exit;
    }
    
    // GET PROGRAMS BY DEPARTMENT, FACULTY & CAMPUS
    if ($_GET['ajax'] == 'get_programs_by_department') {
        $department_id = $_GET['department_id'] ?? 0;
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        // If faculty admin, ensure they're only accessing their faculty
        if ($is_faculty_admin && $faculty_id != $current_user_linked_id) {
            echo json_encode(['status' => 'success', 'programs' => []]);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT program_id, program_name 
            FROM programs 
            WHERE department_id = ? 
            AND faculty_id = ?
            AND campus_id = ?
            AND status = 'active'
            ORDER BY program_name
        ");
        $stmt->execute([$department_id, $faculty_id, $campus_id]);
        $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'programs' => $programs]);
        exit;
    }
    
    // GET CLASSES BY PROGRAM, DEPARTMENT, FACULTY, CAMPUS & STUDY MODE
    if ($_GET['ajax'] == 'get_classes_by_program') {
        $program_id = $_GET['program_id'] ?? 0;
        $department_id = $_GET['department_id'] ?? 0;
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        $study_mode = $_GET['study_mode'] ?? '';
        
        // If faculty admin, ensure they're only accessing their faculty
        if ($is_faculty_admin && $faculty_id != $current_user_linked_id) {
            echo json_encode(['status' => 'success', 'classes' => []]);
            exit;
        }
        
        $sql = "
            SELECT class_id, class_name 
            FROM classes 
            WHERE program_id = ? 
            AND department_id = ?
            AND faculty_id = ?
            AND campus_id = ?
            AND status = 'Active'
        ";
        
        $params = [$program_id, $department_id, $faculty_id, $campus_id];
        
        if (!empty($study_mode) && $study_mode != 'all') {
            $sql .= " AND study_mode = ?";
            $params[] = $study_mode;
        }
        
        $sql .= " ORDER BY class_name";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'classes' => $classes]);
        exit;
    }
}

$report = [];
$params = [];
$filter_sql = "";

/* ================= HANDLE FILTER ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['filter'])) {
  $campus_id  = $_POST['campus_id'] ?? '';
  $faculty_id = $_POST['faculty_id'] ?? '';
  $dept_id    = $_POST['department_id'] ?? '';
  $prog_id    = $_POST['program_id'] ?? '';
  $class_id   = $_POST['class_id'] ?? '';
  $study_mode = $_POST['study_mode'] ?? '';
  $period     = $_POST['period'] ?? 'day';
  $date       = $_POST['date'] ?? date('Y-m-d');
  $low_only   = isset($_POST['low_only']);
  $recourse_only = isset($_POST['recourse_only']);

  // ====== FORCE FACULTY RESTRICTION FOR FACULTY ADMIN ======
  if ($is_faculty_admin && $current_user_linked_id) {
      $faculty_id = $current_user_linked_id;
      // Don't reset campus - let them select any campus
  }

  // ====== Period filter ======
  switch ($period) {
    case 'week':
      $start = date('Y-m-d', strtotime('monday this week', strtotime($date)));
      $end   = date('Y-m-d', strtotime('sunday this week', strtotime($date)));
      $filter_sql .= " AND a.attendance_date BETWEEN ? AND ?";
      $params[] = $start; $params[] = $end;
      break;
    case 'month':
      $start = date('Y-m-01', strtotime($date));
      $end   = date('Y-m-t', strtotime($date));
      $filter_sql .= " AND a.attendance_date BETWEEN ? AND ?";
      $params[] = $start; $params[] = $end;
      break;
    case 'year':
      $year = date('Y', strtotime($date));
      $filter_sql .= " AND YEAR(a.attendance_date)=?";
      $params[] = $year;
      break;
    default:
      $filter_sql .= " AND a.attendance_date=?";
      $params[] = $date;
  }

  // ====== Build hierarchy filter with proper joins ======
  // Always add faculty filter for faculty admin
  if ($is_faculty_admin && $current_user_linked_id) {
      $filter_sql .= " AND c.faculty_id = ?";
      $params[] = $current_user_linked_id;
  }
  
  // Add other filters if selected
  if (!empty($class_id)) { 
    $filter_sql .= " AND c.class_id = ?"; 
    $params[] = $class_id; 
  }
  elseif (!empty($prog_id)) { 
    $filter_sql .= " AND c.program_id = ?"; 
    $params[] = $prog_id; 
  }
  elseif (!empty($dept_id)) { 
    $filter_sql .= " AND c.department_id = ?"; 
    $params[] = $dept_id; 
  }
  elseif (!empty($faculty_id) && !$is_faculty_admin) { 
    $filter_sql .= " AND c.faculty_id = ?"; 
    $params[] = $faculty_id; 
  }
  
  if (!empty($campus_id)) { 
    $filter_sql .= " AND c.campus_id = ?"; 
    $params[] = $campus_id; 
  }
  
  // ====== Study Mode filter ======
  if (!empty($study_mode) && $study_mode != 'all') {
    $filter_sql .= " AND c.study_mode = ?";
    $params[] = $study_mode;
  }

  // ====== Recourse Students filter ======
  $recourse_join = "";
  if ($recourse_only) {
    $recourse_join = "
      INNER JOIN recourse_student rs ON (
        a.student_id = rs.student_id 
        AND a.class_id = rs.recourse_class_id 
        AND rs.status = 'active'
      )
    ";
  }

  // ====== Main query ======
  if ($low_only) {
    // show only students with 5 or more absences in the range
    $stmt = $pdo->prepare("
      SELECT s.full_name, s.reg_no, c.class_name, c.study_mode, p.program_name, d.department_name, 
             f.faculty_name, ca.campus_name,
             COUNT(CASE WHEN a.status='absent' THEN 1 END) AS absences,
             IF(rs.recourse_id IS NOT NULL, 'Yes', 'No') AS is_recourse
      FROM attendance a
      JOIN students s ON s.student_id = a.student_id
      JOIN classes c ON c.class_id = a.class_id
      JOIN programs p ON p.program_id = c.program_id
      JOIN departments d ON d.department_id = c.department_id
      JOIN faculties f ON f.faculty_id = c.faculty_id
      JOIN campus ca ON ca.campus_id = c.campus_id
      LEFT JOIN recourse_student rs ON (
        a.student_id = rs.student_id 
        AND a.class_id = rs.recourse_class_id 
        AND rs.status = 'active'
      )
      WHERE 1=1 $filter_sql
      GROUP BY s.student_id
      HAVING absences >= 5
      ORDER BY absences DESC
    ");
  } else {
    $stmt = $pdo->prepare("
      SELECT a.attendance_date, s.full_name, s.reg_no, c.class_name, c.study_mode, p.program_name, 
             d.department_name, f.faculty_name, ca.campus_name, a.status, t.teacher_name,
             IF(rs.recourse_id IS NOT NULL, 'Yes', 'No') AS is_recourse,
             rs.reason AS recourse_reason
      FROM attendance a
      JOIN students s ON s.student_id = a.student_id
      JOIN classes c ON c.class_id = a.class_id
      JOIN programs p ON p.program_id = c.program_id
      JOIN departments d ON d.department_id = c.department_id
      JOIN faculties f ON f.faculty_id = c.faculty_id
      JOIN campus ca ON ca.campus_id = c.campus_id
      JOIN teachers t ON t.teacher_id = a.teacher_id
      LEFT JOIN recourse_student rs ON (
        a.student_id = rs.student_id 
        AND a.class_id = rs.recourse_class_id 
        AND rs.status = 'active'
      )
      WHERE 1=1 $filter_sql
      ORDER BY a.attendance_date DESC
    ");
  }
  
  // Debug: Uncomment to see the query and params
  // echo "<pre>Query: " . $stmt->queryString . "\nParams: " . print_r($params, true) . "</pre>";
  
  $stmt->execute($params);
  $report = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ================= EXPORT (CSV/PDF) ================= */
if (isset($_GET['export']) && isset($_GET['type'])) {
  $type = $_GET['type'];
  $data = $_SESSION['report_data'] ?? [];
  if (!$data) exit("⚠️ No report data to export.");

  if ($type === 'csv') {
    header("Content-Type: text/csv");
    header("Content-Disposition: attachment; filename=attendance_report_" . date('Y-m-d') . ".csv");
    $out = fopen("php://output", "w");
    fputcsv($out, array_keys($data[0]));
    foreach($data as $r) fputcsv($out, $r);
    fclose($out);
    exit;
  } elseif ($type === 'pdf') {
    require_once(__DIR__ . '/../libs/fpdf/fpdf.php');
    $pdf = new FPDF('L','mm','A4');
    $pdf->AddPage();
    $pdf->SetFont('Arial','B',14);
    $pdf->Cell(0,10,'Hormuud University - Attendance Report',0,1,'C');
    $pdf->SetFont('Arial','',10);
    foreach ($data as $r) {
      $line = implode(' | ', $r);
      $pdf->MultiCell(0,6,$line,0,'L');
    }
    $pdf->Output();
    exit;
  }
}

$_SESSION['report_data'] = $report;

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
    margin: 10px 0;
    font-size: 13px;
    border-left: 3px solid #0072CE;
  }
</style>
<div class="main-content">
  <div class="page-header">
    <h1>
      <i class="fa fa-chart-bar"></i> Attendance Report
      <?php if($is_faculty_admin): ?>
      <?php endif; ?>
    </h1>
  </div>

  <?php if($is_faculty_admin): ?>
 
  <?php endif; ?>

  <!-- FILTER FORM -->
  <form method="POST" class="filter-box">
    <div class="grid">
      <div>
        <label>Campus</label>
        <select name="campus_id" id="campus" onchange="loadFaculties()">
          <option value="">All Campuses</option>
          <?php foreach($campuses as $c): ?>
            <option value="<?= $c['campus_id'] ?>" <?= (!empty($_POST['campus_id']) && $_POST['campus_id']==$c['campus_id'])?'selected':'' ?>>
              <?= htmlspecialchars($c['campus_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      
      <div>
        <label>Faculty</label>
        <?php if($is_faculty_admin): ?>
          <input type="text" class="form-control" value="<?= htmlspecialchars($faculty_name) ?> (Restricted)" readonly style="background:#f0f0f0;padding:8px;border:1px solid #ccc;border-radius:6px;">
          <input type="hidden" name="faculty_id" value="<?= $current_user_linked_id ?>">
        <?php else: ?>
          <select name="faculty_id" id="faculty" onchange="loadDepartments()" disabled>
            <option value="">All Faculties</option>
            <?php if(!empty($_POST['faculty_id'])): ?>
              <?php 
              if(!empty($_POST['campus_id'])) {
                $stmt = $pdo->prepare("
                  SELECT DISTINCT f.faculty_id, f.faculty_name 
                  FROM faculties f
                  JOIN faculty_campus fc ON f.faculty_id = fc.faculty_id
                  WHERE fc.campus_id = ?
                  AND f.status = 'active'
                  ORDER BY f.faculty_name
                ");
                $stmt->execute([$_POST['campus_id']]);
                $faculties = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach($faculties as $f): ?>
                  <option value="<?= $f['faculty_id'] ?>" <?= ($_POST['faculty_id']==$f['faculty_id'])?'selected':'' ?>>
                    <?= htmlspecialchars($f['faculty_name']) ?>
                  </option>
                <?php endforeach; 
              }
              ?>
            <?php endif; ?>
          </select>
        <?php endif; ?>
      </div>
      
      <div>
        <label>Department</label>
        <select name="department_id" id="department" onchange="loadPrograms()" disabled>
          <option value="">All Departments</option>
          <?php if(!empty($_POST['department_id']) && !empty($_POST['faculty_id']) && !empty($_POST['campus_id'])): ?>
            <?php 
            $stmt = $pdo->prepare("
              SELECT department_id, department_name 
              FROM departments 
              WHERE faculty_id = ? 
              AND campus_id = ?
              AND status = 'active'
              ORDER BY department_name
            ");
            $stmt->execute([$_POST['faculty_id'], $_POST['campus_id']]);
            $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach($departments as $d): ?>
              <option value="<?= $d['department_id'] ?>" <?= ($_POST['department_id']==$d['department_id'])?'selected':'' ?>>
                <?= htmlspecialchars($d['department_name']) ?>
              </option>
            <?php endforeach; 
            ?>
          <?php endif; ?>
        </select>
      </div>
      
      <div>
        <label>Program</label>
        <select name="program_id" id="program" onchange="loadClasses()" disabled>
          <option value="">All Programs</option>
          <?php if(!empty($_POST['program_id']) && !empty($_POST['department_id']) && !empty($_POST['faculty_id']) && !empty($_POST['campus_id'])): ?>
            <?php 
            $stmt = $pdo->prepare("
              SELECT program_id, program_name 
              FROM programs 
              WHERE department_id = ? 
              AND faculty_id = ?
              AND campus_id = ?
              AND status = 'active'
              ORDER BY program_name
            ");
            $stmt->execute([$_POST['department_id'], $_POST['faculty_id'], $_POST['campus_id']]);
            $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach($programs as $p): ?>
              <option value="<?= $p['program_id'] ?>" <?= ($_POST['program_id']==$p['program_id'])?'selected':'' ?>>
                <?= htmlspecialchars($p['program_name']) ?>
              </option>
            <?php endforeach; 
            ?>
          <?php endif; ?>
        </select>
      </div>
      
      <div>
        <label>Study Mode</label>
        <select name="study_mode" id="study_mode" onchange="loadClasses()">
          <option value="all" <?= (empty($_POST['study_mode']) || $_POST['study_mode']=='all')?'selected':'' ?>>All Study Modes</option>
          <option value="Full-Time" <?= (!empty($_POST['study_mode']) && $_POST['study_mode']=='Full-Time')?'selected':'' ?>>Full-Time</option>
          <option value="Part-Time" <?= (!empty($_POST['study_mode']) && $_POST['study_mode']=='Part-Time')?'selected':'' ?>>Part-Time</option>
        </select>
      </div>
      
      <div>
        <label>Class</label>
        <select name="class_id" id="class" disabled>
          <option value="">All Classes</option>
          <?php if(!empty($_POST['class_id']) && !empty($_POST['program_id']) && !empty($_POST['department_id']) && !empty($_POST['faculty_id']) && !empty($_POST['campus_id'])): ?>
            <?php 
            $sql = "
              SELECT class_id, class_name 
              FROM classes 
              WHERE program_id = ? 
              AND department_id = ?
              AND faculty_id = ?
              AND campus_id = ?
              AND status = 'Active'
            ";
            
            $params = [
              $_POST['program_id'],
              $_POST['department_id'],
              $_POST['faculty_id'],
              $_POST['campus_id']
            ];
            
            if (!empty($_POST['study_mode']) && $_POST['study_mode'] != 'all') {
              $sql .= " AND study_mode = ?";
              $params[] = $_POST['study_mode'];
            }
            
            $sql .= " ORDER BY class_name";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach($classes as $cls): ?>
              <option value="<?= $cls['class_id'] ?>" <?= ($_POST['class_id']==$cls['class_id'])?'selected':'' ?>>
                <?= htmlspecialchars($cls['class_name']) ?>
              </option>
            <?php endforeach; 
            ?>
          <?php endif; ?>
        </select>
      </div>

      <div>
        <label>Period Type</label>
        <select name="period">
          <option value="day" <?= (!empty($_POST['period']) && $_POST['period']=='day')?'selected':'' ?>>By Day</option>
          <option value="week" <?= (!empty($_POST['period']) && $_POST['period']=='week')?'selected':'' ?>>By Week</option>
          <option value="month" <?= (!empty($_POST['period']) && $_POST['period']=='month')?'selected':'' ?>>By Month</option>
          <option value="year" <?= (!empty($_POST['period']) && $_POST['period']=='year')?'selected':'' ?>>By Year</option>
        </select>
      </div>
      
      <div>
        <label>Date</label>
        <input type="date" name="date" value="<?= !empty($_POST['date']) ? htmlspecialchars($_POST['date']) : date('Y-m-d') ?>">
      </div>
      
      <div>
        <label style="display:flex;align-items:center;">
          <input type="checkbox" name="low_only" <?= (!empty($_POST['low_only']))?'checked':'' ?> style="width:auto;margin-right:5px;"> 
          Only Students with ≥5 Absences
        </label>
      </div>
      
      <div>
        <label style="display:flex;align-items:center;">
          <input type="checkbox" name="recourse_only" <?= (!empty($_POST['recourse_only']))?'checked':'' ?> style="width:auto;margin-right:5px;"> 
          Only Recourse Students
        </label>
      </div>
      
      <div style="align-self:end;">
        <button name="filter" class="btn green"><i class="fa fa-search"></i> Generate</button>
        <button type="button" class="btn gray" onclick="resetForm()"><i class="fa fa-refresh"></i> Reset</button>
      </div>
    </div>
  </form>

  <!-- EXPORT BUTTONS -->
  <?php if($report): ?>
  <div class="export-box">
    <a href="?export=1&type=csv" class="btn blue"><i class="fa fa-file-excel"></i> Download CSV</a>
    <?php if(!$is_faculty_admin): // Only super admin can export PDF ?>
      <a href="?export=1&type=pdf" class="btn red"><i class="fa fa-file-pdf"></i> Download PDF</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- REPORT TABLE -->
  <div class="table-wrapper">
    <h3 style="color:#0072CE;margin:10px;">
      Filtered Attendance Results
      <?php if($report): ?>
        <span style="font-size:14px;color:#666;margin-left:10px;">(<?= count($report) ?> records found)</span>
      <?php endif; ?>
    </h3>
    <table>
      <thead>
        <tr>
          <?php if(isset($_POST['low_only'])): ?>
            <th>Student</th><th>Reg No</th><th>Class</th><th>Study Mode</th><th>Program</th><th>Department</th><th>Faculty</th><th>Campus</th><th>Recourse</th><th>Absences</th>
          <?php else: ?>
            <th>Date</th><th>Student</th><th>Reg No</th><th>Class</th><th>Study Mode</th><th>Program</th><th>Department</th><th>Faculty</th><th>Campus</th><th>Teacher</th><th>Status</th><th>Recourse</th><th>Reason</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php if(!$report): ?>
          <tr><td colspan="14" style="text-align:center;padding:30px;color:#777;">
            <i class="fa fa-info-circle"></i> No attendance records found for selected filters.
            <?php if(empty($_POST)): ?>
              Please select filters and click Generate.
            <?php endif; ?>
          </td></tr>
        <?php else: foreach($report as $r): ?>
          <tr>
            <?php if(isset($_POST['low_only'])): ?>
              <td><?= htmlspecialchars($r['full_name']) ?></td>
              <td><?= htmlspecialchars($r['reg_no']) ?></td>
              <td><?= htmlspecialchars($r['class_name']) ?></td>
              <td>
                <span class="study-mode-badge <?= htmlspecialchars($r['study_mode']) ?>">
                  <?= htmlspecialchars($r['study_mode']) ?>
                </span>
              </td>
              <td><?= htmlspecialchars($r['program_name']) ?></td>
              <td><?= htmlspecialchars($r['department_name']) ?></td>
              <td><?= htmlspecialchars($r['faculty_name']) ?></td>
              <td><?= htmlspecialchars($r['campus_name']) ?></td>
              <td>
                <?php if ($r['is_recourse'] == 'Yes'): ?>
                  <span class="recourse-badge">Recourse</span>
                <?php else: ?>
                  <span style="color:#666;">No</span>
                <?php endif; ?>
              </td>
              <td style="color:#C62828;font-weight:600;"><?= htmlspecialchars($r['absences']) ?></td>
            <?php else: ?>
              <td><?= htmlspecialchars($r['attendance_date']) ?></td>
              <td><?= htmlspecialchars($r['full_name']) ?></td>
              <td><?= htmlspecialchars($r['reg_no']) ?></td>
              <td><?= htmlspecialchars($r['class_name']) ?></td>
              <td>
                <span class="study-mode-badge <?= htmlspecialchars($r['study_mode']) ?>">
                  <?= htmlspecialchars($r['study_mode']) ?>
                </span>
              </td>
              <td><?= htmlspecialchars($r['program_name']) ?></td>
              <td><?= htmlspecialchars($r['department_name']) ?></td>
              <td><?= htmlspecialchars($r['faculty_name']) ?></td>
              <td><?= htmlspecialchars($r['campus_name']) ?></td>
              <td><?= htmlspecialchars($r['teacher_name']) ?></td>
              <td>
                <?php 
                $status_colors = [
                  'present' => 'green',
                  'absent' => 'red',
                  'late' => 'orange',
                  'excused' => 'blue'
                ];
                $color = $status_colors[$r['status']] ?? 'gray';
                ?>
                <span style="color:<?= $color ?>;font-weight:bold;text-transform:capitalize;">
                  <?= htmlspecialchars($r['status']) ?>
                </span>
              </td>
              <td>
                <?php if ($r['is_recourse'] == 'Yes'): ?>
                  <span class="recourse-badge">Recourse</span>
                <?php else: ?>
                  <span style="color:#666;">No</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!empty($r['recourse_reason'])): ?>
                  <span title="<?= htmlspecialchars($r['recourse_reason']) ?>" style="color:#5D4037;cursor:help;">
                    <?= strlen($r['recourse_reason']) > 30 ? substr($r['recourse_reason'], 0, 30) . '...' : htmlspecialchars($r['recourse_reason']) ?>
                  </span>
                <?php else: ?>
                  <span style="color:#999;">-</span>
                <?php endif; ?>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ✅ STYLE -->
<style>
body{font-family:'Poppins',sans-serif;background:#f5f9f7;margin:0;}
.main-content{padding:25px;margin-left:250px;margin-top:90px;}
.page-header h1{color:#0072CE;margin-bottom:15px;display:flex;align-items:center;}
.filter-box{background:#fff;padding:20px;border-radius:10px;box-shadow:0 3px 8px rgba(0,0,0,0.08);margin-bottom:20px;}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;}
label{font-weight:600;color:#0072CE;font-size:13px;display:block;margin-bottom:5px;}
select,input[type=date],input[type=text].form-control{width:100%;padding:8px;border:1px solid #ccc;border-radius:6px;background:#fafafa;}
.btn{border:none;padding:8px 15px;border-radius:6px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block;margin-right:5px;}
.btn.green{background:#00843D;color:#fff;}
.btn.blue{background:#0072CE;color:#fff;}
.btn.red{background:#C62828;color:#fff;}
.btn.gray{background:#6c757d;color:#fff;}
.export-box{margin:10px 0;}
.table-wrapper{overflow:auto;background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.08);}
table{width:100%;border-collapse:collapse;}
th,td{padding:10px 12px;border-bottom:1px solid #eee;text-align:left;font-size:14px;}
thead th{background:#0072CE;color:#fff;position:sticky;top:0;}
tr:hover{background:#f3f8ff;}

/* Study Mode Badge Styles */
.study-mode-badge {
  display: inline-block;
  padding: 3px 8px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
}

.study-mode-badge.Full-Time {
  background-color: #e3f2fd;
  color: #1565c0;
  border: 1px solid #90caf9;
}

.study-mode-badge.Part-Time {
  background-color: #f3e5f5;
  color: #7b1fa2;
  border: 1px solid #ce93d8;
}

/* Recourse Badge */
.recourse-badge {
  background-color: #FFF3E0;
  color: #FF6B00;
  padding: 3px 8px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 600;
  display: inline-block;
}

/* Faculty Admin Badge */
.faculty-admin-badge {
  background-color: #FF6B00;
  color: white;
  padding: 3px 10px;
  border-radius: 4px;
  font-size: 12px;
  margin-left: 10px;
  vertical-align: middle;
  display: inline-block;
}

/* Info Message */
.info-message {
  background-color: #e3f2fd;
  color: #1565c0;
  padding: 8px 12px;
  border-radius: 4px;
  margin: 10px 0;
  font-size: 13px;
  border-left: 3px solid #0072CE;
}

select:disabled {
  background-color: #e9ecef;
  opacity: 0.8;
  cursor: not-allowed;
}

@media(max-width:768px){
  .main-content{margin-left:0;padding:15px;}
  .grid{grid-template-columns:1fr;}
  .page-header h1{font-size:20px;}
}
</style>

<!-- ✅ SCRIPT -->
<script>
// Global variables for faculty admin
const isFacultyAdmin = <?= $is_faculty_admin ? 'true' : 'false' ?>;
const facultyAdminId = <?= $current_user_linked_id ?: 'null' ?>;

function loadFaculties() {
    const campusId = document.getElementById('campus').value;
    
    if (isFacultyAdmin) {
        // For faculty admin, directly load departments
        loadDepartmentsDirectly(campusId);
        return;
    }
    
    // For super admin, load faculties normally
    const facultySelect = document.getElementById('faculty');
    
    if (!campusId) {
        resetHierarchy(['faculty', 'department', 'program', 'class']);
        return;
    }
    
    facultySelect.innerHTML = '<option value="">Loading...</option>';
    facultySelect.disabled = true;
    resetHierarchy(['department', 'program', 'class']);
    
    fetch(`?ajax=get_faculties_by_campus&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            facultySelect.innerHTML = '<option value="">All Faculties</option>';
            
            if (data.status === 'success' && data.faculties.length > 0) {
                data.faculties.forEach(faculty => {
                    const option = document.createElement('option');
                    option.value = faculty.faculty_id;
                    option.textContent = faculty.faculty_name;
                    facultySelect.appendChild(option);
                });
                facultySelect.disabled = false;
            } else {
                facultySelect.innerHTML = '<option value="">No faculties found</option>';
                facultySelect.disabled = true;
            }
        });
}

function loadDepartmentsDirectly(campusId) {
    if (!campusId) {
        resetHierarchy(['department', 'program', 'class']);
        return;
    }
    
    const deptSelect = document.getElementById('department');
    deptSelect.innerHTML = '<option value="">Loading...</option>';
    deptSelect.disabled = true;
    resetHierarchy(['program', 'class']);
    
    fetch(`?ajax=get_departments_by_faculty&faculty_id=${facultyAdminId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            deptSelect.innerHTML = '<option value="">All Departments</option>';
            
            if (data.status === 'success' && data.departments.length > 0) {
                data.departments.forEach(dept => {
                    const option = document.createElement('option');
                    option.value = dept.department_id;
                    option.textContent = dept.department_name;
                    deptSelect.appendChild(option);
                });
                deptSelect.disabled = false;
            } else {
                deptSelect.innerHTML = '<option value="">No departments found</option>';
                deptSelect.disabled = true;
            }
        });
}

function loadDepartments() {
    const facultyId = isFacultyAdmin ? facultyAdminId : document.getElementById('faculty').value;
    const campusId = document.getElementById('campus').value;
    const deptSelect = document.getElementById('department');
    
    if (!facultyId || !campusId) {
        resetHierarchy(['department', 'program', 'class']);
        return;
    }
    
    deptSelect.innerHTML = '<option value="">Loading...</option>';
    deptSelect.disabled = true;
    resetHierarchy(['program', 'class']);
    
    fetch(`?ajax=get_departments_by_faculty&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            deptSelect.innerHTML = '<option value="">All Departments</option>';
            
            if (data.status === 'success' && data.departments.length > 0) {
                data.departments.forEach(dept => {
                    const option = document.createElement('option');
                    option.value = dept.department_id;
                    option.textContent = dept.department_name;
                    deptSelect.appendChild(option);
                });
                deptSelect.disabled = false;
            } else {
                deptSelect.innerHTML = '<option value="">No departments found</option>';
                deptSelect.disabled = true;
            }
        });
}

function loadPrograms() {
    const deptId = document.getElementById('department').value;
    const facultyId = isFacultyAdmin ? facultyAdminId : document.getElementById('faculty').value;
    const campusId = document.getElementById('campus').value;
    const programSelect = document.getElementById('program');
    
    if (!deptId || !facultyId || !campusId) {
        resetHierarchy(['program', 'class']);
        return;
    }
    
    programSelect.innerHTML = '<option value="">Loading...</option>';
    programSelect.disabled = true;
    document.getElementById('class').innerHTML = '<option value="">All Classes</option>';
    document.getElementById('class').disabled = true;
    
    fetch(`?ajax=get_programs_by_department&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            programSelect.innerHTML = '<option value="">All Programs</option>';
            
            if (data.status === 'success' && data.programs.length > 0) {
                data.programs.forEach(program => {
                    const option = document.createElement('option');
                    option.value = program.program_id;
                    option.textContent = program.program_name;
                    programSelect.appendChild(option);
                });
                programSelect.disabled = false;
            } else {
                programSelect.innerHTML = '<option value="">No programs found</option>';
                programSelect.disabled = true;
            }
        });
}

function loadClasses() {
    const programId = document.getElementById('program').value;
    const deptId = document.getElementById('department').value;
    const facultyId = isFacultyAdmin ? facultyAdminId : document.getElementById('faculty').value;
    const campusId = document.getElementById('campus').value;
    const studyMode = document.getElementById('study_mode').value;
    const classSelect = document.getElementById('class');
    
    if (!programId || !deptId || !facultyId || !campusId) {
        classSelect.innerHTML = '<option value="">All Classes</option>';
        classSelect.disabled = true;
        return;
    }
    
    classSelect.innerHTML = '<option value="">Loading...</option>';
    classSelect.disabled = true;
    
    fetch(`?ajax=get_classes_by_program&program_id=${programId}&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}&study_mode=${studyMode}`)
        .then(response => response.json())
        .then(data => {
            classSelect.innerHTML = '<option value="">All Classes</option>';
            
            if (data.status === 'success' && data.classes.length > 0) {
                data.classes.forEach(cls => {
                    const option = document.createElement('option');
                    option.value = cls.class_id;
                    option.textContent = cls.class_name;
                    classSelect.appendChild(option);
                });
                classSelect.disabled = false;
            } else {
                classSelect.innerHTML = '<option value="">No classes found</option>';
                classSelect.disabled = true;
            }
        });
}

function resetHierarchy(fields) {
    fields.forEach(field => {
        const element = document.getElementById(field);
        if (element) {
            if (field === 'faculty' && isFacultyAdmin) {
                // Don't reset faculty for faculty admin
                return;
            }
            element.innerHTML = '<option value="">All ' + field.charAt(0).toUpperCase() + field.slice(1) + 's</option>';
            element.disabled = true;
        }
    });
}

function resetForm() {
    window.location.href = 'attendance_report.php';
}

// Enable dropdowns if they have values on page load
window.onload = function() {
    const campus = document.getElementById('campus');
    const faculty = document.getElementById('faculty');
    const department = document.getElementById('department');
    const program = document.getElementById('program');
    const classElem = document.getElementById('class');
    
    if (campus.value && isFacultyAdmin) {
        loadDepartmentsDirectly(campus.value);
    }
    
    if (faculty && faculty.options.length > 1 && !isFacultyAdmin) {
        faculty.disabled = false;
    }
    if (department && department.options.length > 1) {
        department.disabled = false;
    }
    if (program && program.options.length > 1) {
        program.disabled = false;
    }
    if (classElem && classElem.options.length > 1) {
        classElem.disabled = false;
    }
};
</script>

<?php include('../includes/footer.php'); ?>
<?php ob_end_flush(); ?>