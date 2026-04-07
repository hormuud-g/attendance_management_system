<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ============================================
// ACCESS CONTROL - DEPARTMENT ADMIN SUPPORT
// ============================================
if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit;
}

$userRole = strtolower($_SESSION['user']['role'] ?? '');
$userId = $_SESSION['user']['user_id'] ?? 0;

// Check permissions
$isSuperAdmin = ($userRole === 'super_admin');
$isDepartmentAdmin = ($userRole === 'department_admin');

if (!$isSuperAdmin && !$isDepartmentAdmin) {
    header("Location: ../dashboard.php");
    exit;
}

// ============================================
// GET DEPARTMENT ADMIN'S RESTRICTED DEPARTMENT
// ============================================
$restrictedDepartmentId = null;
$restrictedFacultyId = null;
$restrictedCampusId = null;
$departmentInfo = [];

if ($isDepartmentAdmin && isset($_SESSION['user']['linked_id'])) {
    // Department admins are linked to a specific department
    $stmt = $pdo->prepare("
        SELECT d.department_id, d.department_name, d.faculty_id, d.campus_id,
               f.faculty_name, c.campus_name
        FROM departments d
        JOIN faculties f ON d.faculty_id = f.faculty_id
        JOIN campus c ON d.campus_id = c.campus_id
        JOIN users u ON u.linked_id = d.department_id
        WHERE u.user_id = ? AND u.linked_table = 'department'
    ");
    $stmt->execute([$userId]);
    $departmentInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($departmentInfo) {
        $restrictedDepartmentId = $departmentInfo['department_id'];
        $restrictedFacultyId = $departmentInfo['faculty_id'];
        $restrictedCampusId = $departmentInfo['campus_id'];
    } else {
        // If department admin not properly linked, log out
        session_destroy();
        header("Location: ../login.php?error=invalid_access");
        exit;
    }
}

date_default_timezone_set('Africa/Nairobi');

/* ================= FETCH FILTER DATA ================= */
// Get campuses based on user role
if ($isSuperAdmin) {
    $campuses = $pdo->query("SELECT * FROM campus WHERE status='active' ORDER BY campus_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("SELECT * FROM campus WHERE campus_id = ? AND status='active' ORDER BY campus_name ASC");
    $stmt->execute([$restrictedCampusId]);
    $campuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ================= AJAX HANDLERS ================= */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    // GET FACULTIES BY CAMPUS
    if ($_GET['ajax'] == 'get_faculties_by_campus') {
        $campus_id = $_GET['campus_id'] ?? 0;
        
        $sql = "
            SELECT DISTINCT f.faculty_id, f.faculty_name 
            FROM faculties f
            JOIN faculty_campus fc ON f.faculty_id = fc.faculty_id
            WHERE fc.campus_id = ?
            AND f.status = 'active'
        ";
        
        if ($isDepartmentAdmin) {
            $sql .= " AND f.faculty_id = ?";
            $params = [$campus_id, $restrictedFacultyId];
        } else {
            $params = [$campus_id];
        }
        
        $sql .= " ORDER BY f.faculty_name";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $faculties = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'faculties' => $faculties]);
        exit;
    }
    
    // GET DEPARTMENTS BY FACULTY & CAMPUS
    if ($_GET['ajax'] == 'get_departments_by_faculty') {
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        $sql = "
            SELECT department_id, department_name 
            FROM departments 
            WHERE faculty_id = ? 
            AND campus_id = ?
            AND status = 'active'
        ";
        
        if ($isDepartmentAdmin) {
            $sql .= " AND department_id = ?";
            $params = [$faculty_id, $campus_id, $restrictedDepartmentId];
        } else {
            $params = [$faculty_id, $campus_id];
        }
        
        $sql .= " ORDER BY department_name";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'departments' => $departments]);
        exit;
    }
    
    // GET PROGRAMS BY DEPARTMENT, FACULTY & CAMPUS
    if ($_GET['ajax'] == 'get_programs_by_department') {
        $department_id = $_GET['department_id'] ?? 0;
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        // For department admin, ensure they're only accessing their department
        if ($isDepartmentAdmin && $department_id != $restrictedDepartmentId) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
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
        
        // For department admin, ensure they're only accessing their department
        if ($isDepartmentAdmin && $department_id != $restrictedDepartmentId) {
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
            exit;
        }
        
        $sql = "
            SELECT class_id, class_name, study_mode 
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

    // Add role-based restrictions for department admin
    if ($isDepartmentAdmin) {
        $campus_id = $restrictedCampusId;
        $faculty_id = $restrictedFacultyId;
        $dept_id = $restrictedDepartmentId;
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

    // ====== Hierarchy filter ======
    if ($class_id) { 
        $filter_sql .= " AND c.class_id=?"; 
        $params[] = $class_id; 
    }
    elseif ($prog_id){ 
        $filter_sql .= " AND c.program_id=?"; 
        $params[] = $prog_id; 
    }
    elseif ($dept_id){ 
        $filter_sql .= " AND c.department_id=?"; 
        $params[] = $dept_id; 
    }
    elseif ($faculty_id){ 
        $filter_sql .= " AND c.faculty_id=?"; 
        $params[] = $faculty_id; 
    }
    elseif ($campus_id){ 
        $filter_sql .= " AND c.campus_id=?"; 
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

    // ====== Add department restriction for department admin ======
    if ($isDepartmentAdmin && !$dept_id) {
        $filter_sql .= " AND c.department_id = ?";
        $params[] = $restrictedDepartmentId;
    }

    // ====== Main query ======
    if ($low_only) {
        // show only students with 5 or more absences in the range
        $sql = "
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
        ";
    } else {
        $sql = "
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
        ";
    }
    
    $stmt = $pdo->prepare($sql);
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
        
        // Add department info if department admin
        if ($isDepartmentAdmin && !empty($departmentInfo)) {
            $pdf->SetFont('Arial','',12);
            $pdf->Cell(0,8,'Department: ' . $departmentInfo['department_name'] . ' - ' . $departmentInfo['faculty_name'],0,1,'C');
            $pdf->Ln(5);
        }
        
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
  
  .department-badge {
    background: #0072CE;
    color: white;
    padding: 5px 15px;
    border-radius: 20px;
    font-size: 14px;
    margin-left: 15px;
    display: inline-block;
  }
  
  .filter-info {
    background: #e8f4fd;
    padding: 10px 15px;
    border-radius: 8px;
    margin-bottom: 15px;
    border-left: 4px solid #0072CE;
  }
  
  .filter-info i {
    color: #0072CE;
    margin-right: 8px;
  }
</style>

<div class="main-content">
    <div class="page-header">
        <h1>
            <i class="fa fa-chart-bar"></i> Attendance Report
            <?php if ($isDepartmentAdmin && !empty($departmentInfo)): ?>
               
            <?php endif; ?>
        </h1>
    </div>

    <?php if ($isDepartmentAdmin && !empty($departmentInfo)): ?>
  
    <?php endif; ?>

    <!-- FILTER FORM -->
    <form method="POST" class="filter-box">
        <div class="grid">
            <div>
                <label><i class="fa fa-university"></i> Campus</label>
                <select name="campus_id" id="campus" onchange="loadFaculties()" required <?= $isDepartmentAdmin ? 'disabled' : '' ?>>
                    <option value="">All Campuses</option>
                    <?php foreach($campuses as $c): ?>
                        <option value="<?= $c['campus_id'] ?>" 
                            <?= (!empty($_POST['campus_id']) && $_POST['campus_id']==$c['campus_id']) ? 'selected' : '' ?>
                            <?= ($isDepartmentAdmin && $c['campus_id']==$restrictedCampusId) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['campus_name']) ?>
                            <?php if ($isDepartmentAdmin && $c['campus_id']==$restrictedCampusId): ?> (Your Campus) <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($isDepartmentAdmin): ?>
                    <input type="hidden" name="campus_id" value="<?= $restrictedCampusId ?>">
                <?php endif; ?>
            </div>
            
            <div>
                <label><i class="fa fa-graduation-cap"></i> Faculty</label>
                <select name="faculty_id" id="faculty" onchange="loadDepartments()" <?= $isDepartmentAdmin ? 'disabled' : '' ?>>
                    <option value="">All Faculties</option>
                    <?php if(!empty($_POST['faculty_id']) || $isDepartmentAdmin): ?>
                        <?php 
                        if($isDepartmentAdmin) {
                            $facultyId = $restrictedFacultyId;
                            $facultyName = $departmentInfo['faculty_name'] ?? '';
                            ?>
                            <option value="<?= $facultyId ?>" selected>
                                <?= htmlspecialchars($facultyName) ?> (Your Faculty)
                            </option>
                        <?php
                        } else if(!empty($_POST['campus_id'])) {
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
                <?php if ($isDepartmentAdmin): ?>
                    <input type="hidden" name="faculty_id" value="<?= $restrictedFacultyId ?>">
                <?php endif; ?>
            </div>
            
            <div>
                <label><i class="fa fa-building"></i> Department</label>
                <select name="department_id" id="department" onchange="loadPrograms()" <?= $isDepartmentAdmin ? 'disabled' : '' ?>>
                    <option value="">All Departments</option>
                    <?php if(!empty($_POST['department_id']) || $isDepartmentAdmin): ?>
                        <?php 
                        if($isDepartmentAdmin) {
                            $deptId = $restrictedDepartmentId;
                            $deptName = $departmentInfo['department_name'] ?? '';
                            ?>
                            <option value="<?= $deptId ?>" selected>
                                <?= htmlspecialchars($deptName) ?> (Your Department)
                            </option>
                        <?php
                        } else if(!empty($_POST['faculty_id']) && !empty($_POST['campus_id'])) {
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
                        }
                        ?>
                    <?php endif; ?>
                </select>
                <?php if ($isDepartmentAdmin): ?>
                    <input type="hidden" name="department_id" value="<?= $restrictedDepartmentId ?>">
                <?php endif; ?>
            </div>
            
            <div>
                <label><i class="fa fa-book"></i> Program</label>
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
                <label><i class="fa fa-clock"></i> Study Mode</label>
                <select name="study_mode" id="study_mode" onchange="loadClasses()">
                    <option value="all" <?= (empty($_POST['study_mode']) || $_POST['study_mode']=='all')?'selected':'' ?>>All Study Modes</option>
                    <option value="Full-Time" <?= (!empty($_POST['study_mode']) && $_POST['study_mode']=='Full-Time')?'selected':'' ?>>Full-Time</option>
                    <option value="Part-Time" <?= (!empty($_POST['study_mode']) && $_POST['study_mode']=='Part-Time')?'selected':'' ?>>Part-Time</option>
                </select>
            </div>
            
            <div>
                <label><i class="fa fa-users"></i> Class</label>
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
                <label><i class="fa fa-calendar"></i> Period Type</label>
                <select name="period">
                    <option value="day" <?= (!empty($_POST['period']) && $_POST['period']=='day')?'selected':'' ?>>By Day</option>
                    <option value="week" <?= (!empty($_POST['period']) && $_POST['period']=='week')?'selected':'' ?>>By Week</option>
                    <option value="month" <?= (!empty($_POST['period']) && $_POST['period']=='month')?'selected':'' ?>>By Month</option>
                    <option value="year" <?= (!empty($_POST['period']) && $_POST['period']=='year')?'selected':'' ?>>By Year</option>
                </select>
            </div>
            
            <div>
                <label><i class="fa fa-calendar-day"></i> Date</label>
                <input type="date" name="date" value="<?= !empty($_POST['date']) ? htmlspecialchars($_POST['date']) : date('Y-m-d') ?>">
            </div>
            
            <div style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
                <label>
                    <input type="checkbox" name="low_only" <?= (!empty($_POST['low_only']))?'checked':'' ?>> 
                    <i class="fa fa-exclamation-triangle"></i> Only Students with ≥5 Absences
                </label>
                
                
            </div>
            
            <div style="align-self:end;">
                <button name="filter" class="btn green"><i class="fa fa-search"></i> Generate Report</button>
            </div>
        </div>
    </form>

    <!-- EXPORT BUTTONS -->
    <?php if($report): ?>
    <div class="export-box">
        <a href="?export=1&type=csv" class="btn blue"><i class="fa fa-file-excel"></i> Download CSV</a>        
        <!-- Summary Stats -->
        <div style="margin-top: 15px; padding: 15px; background: #f0f8ff; border-radius: 8px;">
        
            <?php if(isset($_POST['low_only'])): ?>
                - Students with 5+ absences
            <?php endif; ?>
            
        </div>
    </div>
    <?php endif; ?>

    <!-- REPORT TABLE -->
    <div class="table-wrapper">
        <h3 style="color:#0072CE;margin:15px 10px;">
            <i class="fa fa-table"></i> Filtered Attendance Results
            <?php if ($isDepartmentAdmin && !empty($departmentInfo)): ?>
               
            <?php endif; ?>
        </h3>
        <table>
            <thead>
                <tr>
                    <?php if(isset($_POST['low_only'])): ?>
                        <th>Student</th>
                        <th>Reg No</th>
                        <th>Class</th>
                        <th>Study Mode</th>
                        <th>Program</th>
                        <th>Department</th>
                        <th>Faculty</th>
                        <th>Campus</th>
                        <th>Recourse</th>
                        <th>Absences</th>
                    <?php else: ?>
                        <th>Date</th>
                        <th>Student</th>
                        <th>Reg No</th>
                        <th>Class</th>
                        <th>Study Mode</th>
                        <th>Program</th>
                        <th>Department</th>
                        <th>Faculty</th>
                        <th>Campus</th>
                        <th>Teacher</th>
                        <th>Status</th>
                        <th>Recourse</th>
                        <th>Reason</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if(!$report): ?>
                    <tr>
                        <td colspan="14" style="text-align:center;padding:40px;color:#777;">
                            <i class="fa fa-database fa-3x" style="color:#ddd;margin-bottom:10px;"></i><br>
                            No attendance records found for selected filters.
                            <?php if ($isDepartmentAdmin): ?>
                                <br><small>You are viewing only your department data.</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else: foreach($report as $r): ?>
                    <tr>
                        <?php if(isset($_POST['low_only'])): ?>
                            <td><strong><?= htmlspecialchars($r['full_name']) ?></strong></td>
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
                                    <span class="recourse-badge">
                                        <i class="fa fa-redo"></i> Recourse
                                    </span>
                                <?php else: ?>
                                    <span style="color:#666;">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="absence-count"><?= htmlspecialchars($r['absences']) ?></td>
                        <?php else: ?>
                            <td><?= htmlspecialchars($r['attendance_date']) ?></td>
                            <td><strong><?= htmlspecialchars($r['full_name']) ?></strong></td>
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
                                <?php if ($r['status'] == 'present'): ?>
                                    <span class="status-badge present">Present</span>
                                <?php elseif ($r['status'] == 'absent'): ?>
                                    <span class="status-badge absent">Absent</span>
                                <?php elseif ($r['status'] == 'late'): ?>
                                    <span class="status-badge late">Late</span>
                                <?php else: ?>
                                    <span class="status-badge excused">Excused</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($r['is_recourse'] == 'Yes'): ?>
                                    <span class="recourse-badge">
                                        <i class="fa fa-redo"></i> Recourse
                                    </span>
                                <?php else: ?>
                                    <span style="color:#666;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($r['recourse_reason'])): ?>
                                    <span class="recourse-reason" title="<?= htmlspecialchars($r['recourse_reason']) ?>">
                                        <?= strlen($r['recourse_reason']) > 30 ? substr($r['recourse_reason'], 0, 30) . '...' : $r['recourse_reason'] ?>
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
    body {
        font-family:'Poppins',sans-serif;
        background:#f5f9f7;
        margin:0;
    }
    
    .main-content {
        padding:25px;
        margin-left:250px;
        margin-top:90px;
        transition: margin-left 0.3s ease;
    }
    
    .sidebar.collapsed ~ .main-content {
        margin-left: 70px;
    }
    
    .page-header h1 {
        color:#0072CE;
        margin-bottom:15px;
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
    }
    
    .filter-box {
        background:#fff;
        padding:20px;
        border-radius:10px;
        box-shadow:0 3px 8px rgba(0,0,0,0.08);
        margin-bottom:20px;
    }
    
    .grid {
        display:grid;
        grid-template-columns:repeat(auto-fit,minmax(200px,1fr));
        gap:15px;
    }
    
    label {
        font-weight:600;
        color:#0072CE;
        font-size:13px;
        display: block;
        margin-bottom: 5px;
    }
    
    select, input[type=date] {
        width:100%;
        padding:8px;
        border:1px solid #ccc;
        border-radius:6px;
        background:#fafafa;
        font-size: 13px;
    }
    
    select:focus, input:focus {
        outline: none;
        border-color: #0072CE;
        background: #fff;
        box-shadow: 0 0 0 3px rgba(0,114,206,0.1);
    }
    
    .btn {
        border:none;
        padding:10px 20px;
        border-radius:6px;
        font-weight:600;
        cursor:pointer;
        font-size:14px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.3s ease;
    }
    
    .btn.green {
        background:#00843D;
        color:#fff;
    }
    
    .btn.green:hover {
        background:#006b30;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,132,61,0.2);
    }
    
    .btn.blue {
        background:#0072CE;
        color:#fff;
    }
    
    .btn.blue:hover {
        background:#005ba1;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,114,206,0.2);
    }
    
    .btn.red {
        background:#C62828;
        color:#fff;
    }
    
    .btn.red:hover {
        background:#a81f1f;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(198,40,40,0.2);
    }
    
    .export-box {
        margin:15px 0;
        display: flex;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap;
    }
    
    .table-wrapper {
        overflow:auto;
        background:#fff;
        border-radius:10px;
        box-shadow:0 2px 8px rgba(0,0,0,0.08);
        margin-top: 20px;
    }
    
    table {
        width:100%;
        border-collapse:collapse;
        min-width: 1000px;
    }
    
    th, td {
        padding:12px 15px;
        border-bottom:1px solid #eee;
        text-align:left;
        font-size:14px;
    }
    
    thead th {
        background:#0072CE;
        color:#fff;
        position:sticky;
        top:0;
        font-weight: 600;
        font-size: 13px;
        text-transform: uppercase;
    }
    
    tbody tr:hover {
        background:#f3f8ff;
    }
    
    /* Study Mode Badge Styles */
    .study-mode-badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 20px;
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
    
    /* Status Badge Styles */
    .status-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
    }
    
    .status-badge.present {
        background-color: #d4edda;
        color: #155724;
    }
    
    .status-badge.absent {
        background-color: #f8d7da;
        color: #721c24;
    }
    
    .status-badge.late {
        background-color: #fff3cd;
        color: #856404;
    }
    
    .status-badge.excused {
        background-color: #d1ecf1;
        color: #0c5460;
    }
    
    /* Recourse Badge */
    .recourse-badge {
        background-color: #fff3e0;
        color: #f57c00;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }
    
    .recourse-reason {
        color: #5d4037;
        font-size: 12px;
        font-style: italic;
    }
    
    .absence-count {
        font-weight: 700;
        color: #c62828;
        font-size: 16px;
    }
    
    .department-badge {
        background: #00843D;
        color: white;
        padding: 5px 15px;
        border-radius: 20px;
        font-size: 14px;
        margin-left: 15px;
        display: inline-block;
    }
    
    .filter-info {
        background: #e8f4fd;
        padding: 15px 20px;
        border-radius: 10px;
        margin-bottom: 20px;
        border-left: 4px solid #0072CE;
        font-size: 14px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.05);
    }
    
    .filter-info i {
        color: #0072CE;
        margin-right: 8px;
    }
    
    input[type="checkbox"] {
        margin-right: 5px;
        transform: scale(1.1);
    }
    
    @media(max-width:768px) {
        .main-content {
            margin-left:0;
            padding:15px;
        }
        .grid {
            grid-template-columns:1fr;
        }
        .export-box {
            flex-direction: column;
        }
        .export-box .btn {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<!-- ✅ SCRIPT -->
<script>
function loadFaculties() {
    const campusId = document.getElementById('campus').value;
    const facultySelect = document.getElementById('faculty');
    
    <?php if ($isDepartmentAdmin): ?>
    // Department admin can't change faculty
    return;
    <?php endif; ?>
    
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
        })
        .catch(error => {
            console.error('Error loading faculties:', error);
            facultySelect.innerHTML = '<option value="">Error loading</option>';
        });
}

function loadDepartments() {
    const facultyId = document.getElementById('faculty').value;
    const campusId = document.getElementById('campus').value;
    const deptSelect = document.getElementById('department');
    
    <?php if ($isDepartmentAdmin): ?>
    // Department admin can't change department
    return;
    <?php endif; ?>
    
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
        })
        .catch(error => {
            console.error('Error loading departments:', error);
            deptSelect.innerHTML = '<option value="">Error loading</option>';
        });
}

function loadPrograms() {
    const deptId = document.getElementById('department').value;
    const facultyId = document.getElementById('faculty').value;
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
        })
        .catch(error => {
            console.error('Error loading programs:', error);
            programSelect.innerHTML = '<option value="">Error loading</option>';
        });
}

function loadClasses() {
    const programId = document.getElementById('program').value;
    const deptId = document.getElementById('department').value;
    const facultyId = document.getElementById('faculty').value;
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
        })
        .catch(error => {
            console.error('Error loading classes:', error);
            classSelect.innerHTML = '<option value="">Error loading</option>';
        });
}

function resetHierarchy(fields) {
    fields.forEach(field => {
        const element = document.getElementById(field);
        if (element) {
            element.innerHTML = '<option value="">All ' + field.charAt(0).toUpperCase() + field.slice(1) + '</option>';
            element.disabled = true;
        }
    });
}

// Enable dropdowns if they have values on page load
window.onload = function() {
    const faculty = document.getElementById('faculty');
    const department = document.getElementById('department');
    const program = document.getElementById('program');
    const classElem = document.getElementById('class');
    
    if (faculty.options.length > 1) {
        faculty.disabled = false;
    }
    if (department.options.length > 1) {
        department.disabled = false;
    }
    if (program.options.length > 1) {
        program.disabled = false;
    }
    if (classElem.options.length > 1) {
        classElem.disabled = false;
    }
    
    <?php if ($isDepartmentAdmin): ?>
    // For department admin, trigger program load after page load
    setTimeout(() => {
        if (document.getElementById('department').value) {
            loadPrograms();
        }
    }, 500);
    <?php endif; ?>
};
</script>

<?php include('../includes/footer.php'); ?>
<?php ob_end_flush(); ?>