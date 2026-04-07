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
$message = ""; $type = "";

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

/* ================= FETCH INITIAL DATA ================= */
// Get campuses based on user role
if ($isSuperAdmin) {
    $campuses = $pdo->query("SELECT * FROM campus WHERE status='active' ORDER BY campus_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("SELECT * FROM campus WHERE campus_id = ? AND status='active' ORDER BY campus_name ASC");
    $stmt->execute([$restrictedCampusId]);
    $campuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$semesters = $pdo->query("SELECT * FROM semester ORDER BY semester_id ASC")->fetchAll(PDO::FETCH_ASSOC);

/* ================= FILTERS ================= */
$where = "1=1";
$params = [];

// Old Campus Filter
if (!empty($_GET['old_campus_id'])) { 
    if ($isDepartmentAdmin && $_GET['old_campus_id'] != $restrictedCampusId) {
        // Ignore filter if it doesn't match department admin's campus
    } else {
        $where .= " AND ph.old_campus_id=?"; 
        $params[] = $_GET['old_campus_id'];
    }
}

// New Campus Filter
if (!empty($_GET['new_campus_id'])) { 
    if ($isDepartmentAdmin && $_GET['new_campus_id'] != $restrictedCampusId) {
        // Ignore filter if it doesn't match department admin's campus
    } else {
        $where .= " AND ph.new_campus_id=?"; 
        $params[] = $_GET['new_campus_id'];
    }
}

// Old Faculty Filter
if (!empty($_GET['old_faculty_id'])) { 
    if ($isDepartmentAdmin && $_GET['old_faculty_id'] != $restrictedFacultyId) {
        // Ignore filter if it doesn't match department admin's faculty
    } else {
        $where .= " AND ph.old_faculty_id=?"; 
        $params[] = $_GET['old_faculty_id'];
    }
}

// New Faculty Filter
if (!empty($_GET['new_faculty_id'])) { 
    if ($isDepartmentAdmin && $_GET['new_faculty_id'] != $restrictedFacultyId) {
        // Ignore filter if it doesn't match department admin's faculty
    } else {
        $where .= " AND ph.new_faculty_id=?"; 
        $params[] = $_GET['new_faculty_id'];
    }
}

// Old Department Filter
if (!empty($_GET['old_department_id'])) { 
    if ($isDepartmentAdmin && $_GET['old_department_id'] != $restrictedDepartmentId) {
        // Ignore filter if it doesn't match department admin's department
    } else {
        $where .= " AND ph.old_department_id=?"; 
        $params[] = $_GET['old_department_id'];
    }
}

// New Department Filter
if (!empty($_GET['new_department_id'])) { 
    if ($isDepartmentAdmin && $_GET['new_department_id'] != $restrictedDepartmentId) {
        // Ignore filter if it doesn't match department admin's department
    } else {
        $where .= " AND ph.new_department_id=?"; 
        $params[] = $_GET['new_department_id'];
    }
}

// Old Program Filter
if (!empty($_GET['old_program_id'])) { 
    $where .= " AND ph.old_program_id=?"; 
    $params[] = $_GET['old_program_id']; 
}

// New Program Filter
if (!empty($_GET['new_program_id'])) { 
    $where .= " AND ph.new_program_id=?"; 
    $params[] = $_GET['new_program_id']; 
}

// Old Semester Filter
if (!empty($_GET['old_semester_id'])) { 
    $where .= " AND ph.old_semester_id=?"; 
    $params[] = $_GET['old_semester_id']; 
}

// New Semester Filter
if (!empty($_GET['new_semester_id'])) { 
    $where .= " AND ph.new_semester_id=?"; 
    $params[] = $_GET['new_semester_id']; 
}

if (!empty($_GET['from_date']) && !empty($_GET['to_date'])) {
    $where .= " AND DATE(ph.promotion_date) BETWEEN ? AND ?";
    $params[] = $_GET['from_date'];
    $params[] = $_GET['to_date'];
}

// Add department restriction for department admin
if ($isDepartmentAdmin) {
    $where .= " AND (ph.old_department_id = ? OR ph.new_department_id = ?)";
    $params[] = $restrictedDepartmentId;
    $params[] = $restrictedDepartmentId;
}

/* ================= GET PROMOTION DATA ================= */
$query = "
    SELECT 
        ph.*,
        s.full_name,
        s.reg_no,
        u.username AS promoted_by,
        
        -- Old Information
        old_campus.campus_name AS old_campus_name,
        old_faculty.faculty_name AS old_faculty_name,
        old_department.department_name AS old_department_name,
        old_program.program_name AS old_program_name,
        old_semester.semester_name AS old_semester_name,
        
        -- New Information
        new_campus.campus_name AS new_campus_name,
        new_faculty.faculty_name AS new_faculty_name,
        new_department.department_name AS new_department_name,
        new_program.program_name AS new_program_name,
        new_semester.semester_name AS new_semester_name
    
    FROM promotion_history ph
    
    -- Student Information
    JOIN students s ON s.student_id = ph.student_id
    
    -- Promoted By User
    LEFT JOIN users u ON u.user_id = ph.promoted_by
    
    -- Old Information Joins
    LEFT JOIN campus old_campus ON old_campus.campus_id = ph.old_campus_id
    LEFT JOIN faculties old_faculty ON old_faculty.faculty_id = ph.old_faculty_id
    LEFT JOIN departments old_department ON old_department.department_id = ph.old_department_id
    LEFT JOIN programs old_program ON old_program.program_id = ph.old_program_id
    LEFT JOIN semester old_semester ON old_semester.semester_id = ph.old_semester_id
    
    -- New Information Joins
    LEFT JOIN campus new_campus ON new_campus.campus_id = ph.new_campus_id
    LEFT JOIN faculties new_faculty ON new_faculty.faculty_id = ph.new_faculty_id
    LEFT JOIN departments new_department ON new_department.department_id = ph.new_department_id
    LEFT JOIN programs new_program ON new_program.program_id = ph.new_program_id
    LEFT JOIN semester new_semester ON new_semester.semester_id = ph.new_semester_id
    
    WHERE $where
    ORDER BY ph.promotion_date DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$promotions = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    padding: 15px 20px;
    border-radius: 10px;
    margin-bottom: 20px;
    border-left: 4px solid #0072CE;
    font-size: 14px;
  }
  
  .filter-info i {
    color: #0072CE;
    margin-right: 8px;
  }
  
  /* Highlight differences */
  .old-info {
    background-color: #fff5f5;
  }
  
  .new-info {
    background-color: #f5fff5;
  }
  
  .info-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    margin-left: 5px;
  }
  
  .info-badge.old {
    background-color: #ffebee;
    color: #c62828;
  }
  
  .info-badge.new {
    background-color: #e8f5e9;
    color: #2e7d32;
  }
</style>

<div class="main-content">
    <div class="page-header">
        <h1>
            <i class="fa fa-chart-line"></i> Promotion Report
            
        </h1>
    </div>

    <?php if ($isDepartmentAdmin && !empty($departmentInfo)): ?>
    
    <?php endif; ?>

    <!-- FILTER FORM -->
    <div class="filter-box">
        <form method="GET">
            <div class="filter-section">
                <h3 style="color:#0072CE;margin-bottom:15px;">
                    <i class="fa fa-history"></i> Old Information Filters
                </h3>
                <div class="grid">
                    <div>
                        <label>Old Campus</label>
                        <select name="old_campus_id" id="old_campus" onchange="loadOldFaculties()" <?= $isDepartmentAdmin ? 'disabled' : '' ?>>
                            <option value="">All Campuses</option>
                            <?php foreach($campuses as $c): ?>
                                <option value="<?= $c['campus_id'] ?>" 
                                    <?= (!empty($_GET['old_campus_id']) && $_GET['old_campus_id']==$c['campus_id']) ? 'selected' : '' ?>
                                    <?= ($isDepartmentAdmin && $c['campus_id']==$restrictedCampusId) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['campus_name']) ?>
                                    <?php if ($isDepartmentAdmin && $c['campus_id']==$restrictedCampusId): ?> (Your Campus) <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($isDepartmentAdmin): ?>
                            <input type="hidden" name="old_campus_id" value="<?= $restrictedCampusId ?>">
                        <?php endif; ?>
                    </div>

                    <div>
                        <label>Old Faculty</label>
                        <select name="old_faculty_id" id="old_faculty" onchange="loadOldDepartments()" <?= $isDepartmentAdmin ? 'disabled' : '' ?>>
                            <option value="">All Faculties</option>
                            <?php if(!empty($_GET['old_faculty_id']) || $isDepartmentAdmin): ?>
                                <?php 
                                if($isDepartmentAdmin) {
                                    $facultyId = $restrictedFacultyId;
                                    $facultyName = $departmentInfo['faculty_name'] ?? '';
                                    ?>
                                    <option value="<?= $facultyId ?>" selected>
                                        <?= htmlspecialchars($facultyName) ?> (Your Faculty)
                                    </option>
                                <?php
                                } else if(!empty($_GET['old_campus_id'])) {
                                    $stmt = $pdo->prepare("
                                        SELECT DISTINCT f.faculty_id, f.faculty_name 
                                        FROM faculties f
                                        JOIN faculty_campus fc ON f.faculty_id = fc.faculty_id
                                        WHERE fc.campus_id = ?
                                        AND f.status = 'active'
                                        ORDER BY f.faculty_name
                                    ");
                                    $stmt->execute([$_GET['old_campus_id']]);
                                    $old_faculties = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                    foreach($old_faculties as $f): ?>
                                        <option value="<?= $f['faculty_id'] ?>" <?= ($_GET['old_faculty_id']==$f['faculty_id'])?'selected':'' ?>>
                                            <?= htmlspecialchars($f['faculty_name']) ?>
                                        </option>
                                    <?php endforeach; 
                                }
                                ?>
                            <?php endif; ?>
                        </select>
                        <?php if ($isDepartmentAdmin): ?>
                            <input type="hidden" name="old_faculty_id" value="<?= $restrictedFacultyId ?>">
                        <?php endif; ?>
                    </div>

                    <div>
                        <label>Old Department</label>
                        <select name="old_department_id" id="old_department" onchange="loadOldPrograms()" <?= $isDepartmentAdmin ? 'disabled' : '' ?>>
                            <option value="">All Departments</option>
                            <?php if(!empty($_GET['old_department_id']) || $isDepartmentAdmin): ?>
                                <?php 
                                if($isDepartmentAdmin) {
                                    $deptId = $restrictedDepartmentId;
                                    $deptName = $departmentInfo['department_name'] ?? '';
                                    ?>
                                    <option value="<?= $deptId ?>" selected>
                                        <?= htmlspecialchars($deptName) ?> (Your Department)
                                    </option>
                                <?php
                                } else if(!empty($_GET['old_faculty_id']) && !empty($_GET['old_campus_id'])) {
                                    $stmt = $pdo->prepare("
                                        SELECT department_id, department_name 
                                        FROM departments 
                                        WHERE faculty_id = ? 
                                        AND campus_id = ?
                                        AND status = 'active'
                                        ORDER BY department_name
                                    ");
                                    $stmt->execute([$_GET['old_faculty_id'], $_GET['old_campus_id']]);
                                    $old_departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                    foreach($old_departments as $d): ?>
                                        <option value="<?= $d['department_id'] ?>" <?= ($_GET['old_department_id']==$d['department_id'])?'selected':'' ?>>
                                            <?= htmlspecialchars($d['department_name']) ?>
                                        </option>
                                    <?php endforeach; 
                                }
                                ?>
                            <?php endif; ?>
                        </select>
                        <?php if ($isDepartmentAdmin): ?>
                            <input type="hidden" name="old_department_id" value="<?= $restrictedDepartmentId ?>">
                        <?php endif; ?>
                    </div>

                    <div>
                        <label>Old Program</label>
                        <select name="old_program_id" id="old_program" disabled>
                            <option value="">All Programs</option>
                            <?php if(!empty($_GET['old_program_id']) && !empty($_GET['old_department_id']) && !empty($_GET['old_faculty_id']) && !empty($_GET['old_campus_id'])): ?>
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
                                $stmt->execute([$_GET['old_department_id'], $_GET['old_faculty_id'], $_GET['old_campus_id']]);
                                $old_programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                foreach($old_programs as $p): ?>
                                    <option value="<?= $p['program_id'] ?>" <?= ($_GET['old_program_id']==$p['program_id'])?'selected':'' ?>>
                                        <?= htmlspecialchars($p['program_name']) ?>
                                    </option>
                                <?php endforeach; 
                                ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div>
                        <label>Old Semester</label>
                        <select name="old_semester_id">
                            <option value="">All Semesters</option>
                            <?php foreach($semesters as $s): ?>
                                <option value="<?= $s['semester_id'] ?>" <?= (!empty($_GET['old_semester_id']) && $_GET['old_semester_id']==$s['semester_id'])?'selected':'' ?>>
                                    <?= htmlspecialchars($s['semester_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="filter-section" style="margin-top:20px;">
                <h3 style="color:#0072CE;margin-bottom:15px;">
                    <i class="fa fa-arrow-right"></i> New Information Filters
                </h3>
                <div class="grid">
                    <div>
                        <label>New Campus</label>
                        <select name="new_campus_id" id="new_campus" onchange="loadNewFaculties()" <?= $isDepartmentAdmin ? 'disabled' : '' ?>>
                            <option value="">All Campuses</option>
                            <?php foreach($campuses as $c): ?>
                                <option value="<?= $c['campus_id'] ?>" 
                                    <?= (!empty($_GET['new_campus_id']) && $_GET['new_campus_id']==$c['campus_id']) ? 'selected' : '' ?>
                                    <?= ($isDepartmentAdmin && $c['campus_id']==$restrictedCampusId) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['campus_name']) ?>
                                    <?php if ($isDepartmentAdmin && $c['campus_id']==$restrictedCampusId): ?> (Your Campus) <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($isDepartmentAdmin): ?>
                            <input type="hidden" name="new_campus_id" value="<?= $restrictedCampusId ?>">
                        <?php endif; ?>
                    </div>

                    <div>
                        <label>New Faculty</label>
                        <select name="new_faculty_id" id="new_faculty" onchange="loadNewDepartments()" <?= $isDepartmentAdmin ? 'disabled' : '' ?>>
                            <option value="">All Faculties</option>
                            <?php if(!empty($_GET['new_faculty_id']) || $isDepartmentAdmin): ?>
                                <?php 
                                if($isDepartmentAdmin) {
                                    $facultyId = $restrictedFacultyId;
                                    $facultyName = $departmentInfo['faculty_name'] ?? '';
                                    ?>
                                    <option value="<?= $facultyId ?>" selected>
                                        <?= htmlspecialchars($facultyName) ?> (Your Faculty)
                                    </option>
                                <?php
                                } else if(!empty($_GET['new_campus_id'])) {
                                    $stmt = $pdo->prepare("
                                        SELECT DISTINCT f.faculty_id, f.faculty_name 
                                        FROM faculties f
                                        JOIN faculty_campus fc ON f.faculty_id = fc.faculty_id
                                        WHERE fc.campus_id = ?
                                        AND f.status = 'active'
                                        ORDER BY f.faculty_name
                                    ");
                                    $stmt->execute([$_GET['new_campus_id']]);
                                    $new_faculties = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                    foreach($new_faculties as $f): ?>
                                        <option value="<?= $f['faculty_id'] ?>" <?= ($_GET['new_faculty_id']==$f['faculty_id'])?'selected':'' ?>>
                                            <?= htmlspecialchars($f['faculty_name']) ?>
                                        </option>
                                    <?php endforeach; 
                                }
                                ?>
                            <?php endif; ?>
                        </select>
                        <?php if ($isDepartmentAdmin): ?>
                            <input type="hidden" name="new_faculty_id" value="<?= $restrictedFacultyId ?>">
                        <?php endif; ?>
                    </div>

                    <div>
                        <label>New Department</label>
                        <select name="new_department_id" id="new_department" onchange="loadNewPrograms()" <?= $isDepartmentAdmin ? 'disabled' : '' ?>>
                            <option value="">All Departments</option>
                            <?php if(!empty($_GET['new_department_id']) || $isDepartmentAdmin): ?>
                                <?php 
                                if($isDepartmentAdmin) {
                                    $deptId = $restrictedDepartmentId;
                                    $deptName = $departmentInfo['department_name'] ?? '';
                                    ?>
                                    <option value="<?= $deptId ?>" selected>
                                        <?= htmlspecialchars($deptName) ?> (Your Department)
                                    </option>
                                <?php
                                } else if(!empty($_GET['new_faculty_id']) && !empty($_GET['new_campus_id'])) {
                                    $stmt = $pdo->prepare("
                                        SELECT department_id, department_name 
                                        FROM departments 
                                        WHERE faculty_id = ? 
                                        AND campus_id = ?
                                        AND status = 'active'
                                        ORDER BY department_name
                                    ");
                                    $stmt->execute([$_GET['new_faculty_id'], $_GET['new_campus_id']]);
                                    $new_departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                    foreach($new_departments as $d): ?>
                                        <option value="<?= $d['department_id'] ?>" <?= ($_GET['new_department_id']==$d['department_id'])?'selected':'' ?>>
                                            <?= htmlspecialchars($d['department_name']) ?>
                                        </option>
                                    <?php endforeach; 
                                }
                                ?>
                            <?php endif; ?>
                        </select>
                        <?php if ($isDepartmentAdmin): ?>
                            <input type="hidden" name="new_department_id" value="<?= $restrictedDepartmentId ?>">
                        <?php endif; ?>
                    </div>

                    <div>
                        <label>New Program</label>
                        <select name="new_program_id" id="new_program" disabled>
                            <option value="">All Programs</option>
                            <?php if(!empty($_GET['new_program_id']) && !empty($_GET['new_department_id']) && !empty($_GET['new_faculty_id']) && !empty($_GET['new_campus_id'])): ?>
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
                                $stmt->execute([$_GET['new_department_id'], $_GET['new_faculty_id'], $_GET['new_campus_id']]);
                                $new_programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                foreach($new_programs as $p): ?>
                                    <option value="<?= $p['program_id'] ?>" <?= ($_GET['new_program_id']==$p['program_id'])?'selected':'' ?>>
                                        <?= htmlspecialchars($p['program_name']) ?>
                                    </option>
                                <?php endforeach; 
                                ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div>
                        <label>New Semester</label>
                        <select name="new_semester_id">
                            <option value="">All Semesters</option>
                            <?php foreach($semesters as $s): ?>
                                <option value="<?= $s['semester_id'] ?>" <?= (!empty($_GET['new_semester_id']) && $_GET['new_semester_id']==$s['semester_id'])?'selected':'' ?>>
                                    <?= htmlspecialchars($s['semester_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="grid" style="margin-top:20px;border-top:1px solid #eee;padding-top:15px;">
                <div>
                    <label>From Date</label>
                    <input type="date" name="from_date" value="<?= !empty($_GET['from_date']) ? htmlspecialchars($_GET['from_date']) : '' ?>">
                </div>

                <div>
                    <label>To Date</label>
                    <input type="date" name="to_date" value="<?= !empty($_GET['to_date']) ? htmlspecialchars($_GET['to_date']) : '' ?>">
                </div>

                <div style="align-self:end;">
                    <button type="submit" class="btn blue" style="width:100%;"><i class="fa fa-search"></i> Filter</button>
                </div>

                <div style="align-self:end;">
                    <button type="button" onclick="clearFilters()" class="btn red" style="width:100%;"><i class="fa fa-times"></i> Clear</button>
                </div>
            </div>
        </form>
    </div>

    <!-- REPORT TABLE -->
    <div class="table-wrapper" id="reportArea">
        <div class="print-header">
            <img src="../assets/logo.png" alt="Logo">
            <div>
                <h2>HORMUUD UNIVERSITY</h2>
                <p>Promotion History Report</p>
                <p><strong>Date:</strong> <?= date('d M Y') ?></p>
                <?php if ($isDepartmentAdmin && !empty($departmentInfo)): ?>
                    <p><strong>Department:</strong> <?= htmlspecialchars($departmentInfo['department_name']) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div style="padding:10px 15px;display:flex;justify-content:space-between;align-items:center;" class="noprint">
            <h3 style="color:#0072CE;">
                <i class="fa fa-table"></i> Promotion History
                <?php if ($promotions): ?>
                    <span style="font-size:14px;color:#666;margin-left:10px;">
                        (<?= count($promotions) ?> records found)
                    </span>
                <?php endif; ?>
            </h3>
            <div>
                <button onclick="exportTableToCSV('promotion_history.csv')" class="btn blue">
                    <i class="fa fa-file-excel"></i> Excel
                </button>
                <button onclick="printReport()" class="btn green">
                    <i class="fa fa-print"></i> Print
                </button>
            </div>
        </div>

        <table id="reportTable">
            <thead>
                <tr>
                    <th rowspan="2">#</th>
                    <th rowspan="2">Student</th>
                    <th rowspan="2">Reg No</th>
                    
                    <!-- Old Information -->
                    <th colspan="5" class="old-info">Previous Information</th>
                    
                    <!-- New Information -->
                    <th colspan="5" class="new-info">New Information</th>
                    
                    <th rowspan="2">Promoted By</th>
                    <th rowspan="2">Promotion Date</th>
                    <th rowspan="2">Remarks</th>
                </tr>
                <tr>
                    <!-- Old Info Subheaders -->
                    <th class="old-info">Campus</th>
                    <th class="old-info">Faculty</th>
                    <th class="old-info">Department</th>
                    <th class="old-info">Program</th>
                    <th class="old-info">Semester</th>
                    
                    <!-- New Info Subheaders -->
                    <th class="new-info">Campus</th>
                    <th class="new-info">Faculty</th>
                    <th class="new-info">Department</th>
                    <th class="new-info">Program</th>
                    <th class="new-info">Semester</th>
                </tr>
            </thead>
            <tbody>
                <?php if($promotions): $i=1; foreach($promotions as $r): ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><strong><?= htmlspecialchars($r['full_name']) ?></strong></td>
                    <td><?= htmlspecialchars($r['reg_no']) ?></td>
                    
                    <!-- Old Information -->
                    <td class="old-info">
                        <?= htmlspecialchars($r['old_campus_name'] ?? '-') ?>
                        <?php if ($isDepartmentAdmin && $r['old_campus_id'] == $restrictedCampusId): ?>
                            <span class="info-badge old">Your</span>
                        <?php endif; ?>
                    </td>
                    <td class="old-info">
                        <?= htmlspecialchars($r['old_faculty_name'] ?? '-') ?>
                        <?php if ($isDepartmentAdmin && $r['old_faculty_id'] == $restrictedFacultyId): ?>
                            <span class="info-badge old">Your</span>
                        <?php endif; ?>
                    </td>
                    <td class="old-info">
                        <?= htmlspecialchars($r['old_department_name'] ?? '-') ?>
                        <?php if ($isDepartmentAdmin && $r['old_department_id'] == $restrictedDepartmentId): ?>
                            <span class="info-badge old">Your</span>
                        <?php endif; ?>
                    </td>
                    <td class="old-info"><?= htmlspecialchars($r['old_program_name'] ?? '-') ?></td>
                    <td class="old-info"><?= htmlspecialchars($r['old_semester_name'] ?? '-') ?></td>
                    
                    <!-- New Information -->
                    <td class="new-info">
                        <?= htmlspecialchars($r['new_campus_name'] ?? '-') ?>
                        <?php if ($isDepartmentAdmin && $r['new_campus_id'] == $restrictedCampusId): ?>
                            <span class="info-badge new">Your</span>
                        <?php endif; ?>
                    </td>
                    <td class="new-info">
                        <?= htmlspecialchars($r['new_faculty_name'] ?? '-') ?>
                        <?php if ($isDepartmentAdmin && $r['new_faculty_id'] == $restrictedFacultyId): ?>
                            <span class="info-badge new">Your</span>
                        <?php endif; ?>
                    </td>
                    <td class="new-info">
                        <?= htmlspecialchars($r['new_department_name'] ?? '-') ?>
                        <?php if ($isDepartmentAdmin && $r['new_department_id'] == $restrictedDepartmentId): ?>
                            <span class="info-badge new">Your</span>
                        <?php endif; ?>
                    </td>
                    <td class="new-info"><?= htmlspecialchars($r['new_program_name'] ?? '-') ?></td>
                    <td class="new-info"><?= htmlspecialchars($r['new_semester_name'] ?? '-') ?></td>
                    
                    <td><?= htmlspecialchars($r['promoted_by'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($r['promotion_date']) ?></td>
                    <td><?= htmlspecialchars($r['remarks'] ?? '') ?></td>
                </tr>
                <?php endforeach; else: ?>
                <tr>
                    <td colspan="17" style="text-align:center;padding:40px;color:#777;">
                        <i class="fa fa-history fa-3x" style="color:#ddd;margin-bottom:10px;"></i><br>
                        No promotion records found.
                        <?php if ($isDepartmentAdmin): ?>
                            <br><small>You are viewing only your department data.</small>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="signature">
            <p>__________________________</p>
            <p><strong>Registrar / Admin</strong></p>
            <p>Date: <?= date('d-m-Y') ?></p>
        </div>
    </div>
</div>

<style>
body{font-family:'Poppins',sans-serif;background:#f5f9f7;margin:0;}
.main-content{padding:25px;margin-left:250px;margin-top:90px;}
.filter-box{background:#fff;padding:20px;border-radius:10px;box-shadow:0 3px 8px rgba(0,0,0,0.08);margin-bottom:15px;}
.filter-section{margin-bottom:20px;padding-bottom:15px;border-bottom:1px solid #eee;}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;}
label{font-weight:600;color:#0072CE;font-size:13px;display:block;margin-bottom:5px;}
select,input[type=date]{width:100%;padding:8px;border:1px solid #ccc;border-radius:6px;background:#fafafa;}
select:focus,input:focus{outline:none;border-color:#0072CE;background:#fff;}
.btn{border:none;padding:9px 15px;border-radius:6px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:all 0.3s ease;}
.btn.blue{background:#0072CE;color:#fff;}
.btn.blue:hover{background:#005ba1;transform:translateY(-2px);}
.btn.green{background:#00843D;color:#fff;}
.btn.green:hover{background:#006b30;transform:translateY(-2px);}
.btn.red{background:#C62828;color:#fff;}
.btn.red:hover{background:#a81f1f;transform:translateY(-2px);}
.table-wrapper{overflow:auto;background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.08);padding-bottom:20px;}
table{width:100%;border-collapse:collapse;min-width:1200px;}
th,td{padding:10px 12px;border:1px solid #ddd;text-align:left;font-size:13px;}
thead th{background:#0072CE;color:#fff;position:sticky;top:0;font-weight:600;}
thead th.old-info{background:#c62828;color:#fff;}
thead th.new-info{background:#2e7d32;color:#fff;}
tbody tr:hover{background:#f3f8ff;}
.old-info{background-color:#fff5f5;}
.new-info{background-color:#f5fff5;}
.print-header{text-align:center;display:none;}
.print-header img{width:80px;margin-top:10px;}
.print-header h2{margin:5px 0;color:#0072CE;}
.signature{text-align:center;margin-top:30px;}
.department-badge{background:#00843D;color:white;padding:5px 15px;border-radius:20px;font-size:14px;margin-left:15px;display:inline-block;}
.filter-info{background:#e8f4fd;padding:15px 20px;border-radius:10px;margin-bottom:20px;border-left:4px solid #0072CE;font-size:14px;}
.filter-info i{color:#0072CE;margin-right:8px;}
.info-badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:600;margin-left:5px;}
.info-badge.old{background-color:#ffebee;color:#c62828;border:1px solid #ffcdd2;}
.info-badge.new{background-color:#e8f5e9;color:#2e7d32;border:1px solid #c8e6c9;}

@media print{
    body{background:#fff;}
    .noprint,.filter-box,.filter-info{display:none!important;}
    .print-header{display:block;}
    .main-content{margin:0;padding:0;}
    table{font-size:10px;border-collapse:collapse;width:100%;}
    th,td{border:1px solid #000;padding:4px;}
    thead th{background:#0072CE!important;color:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    thead th.old-info{background:#c62828!important;}
    thead th.new-info{background:#2e7d32!important;}
}
@media(max-width:768px){
    .main-content{margin-left:0;padding:15px;}
    .grid{grid-template-columns:1fr;}
    table{font-size:11px;}
    th,td{padding:6px 8px;}
}
</style>

<script>
function printReport(){
    window.print();
}

function exportTableToCSV(filename){
    const csv=[];
    const rows=document.querySelectorAll("#reportTable tr");
    for(let i=0;i<rows.length;i++){
        const cols=rows[i].querySelectorAll("td, th");
        const data=[];
        for(let j=0;j<cols.length;j++){
            data.push('"' + cols[j].innerText.replace(/"/g,'""') + '"');
        }
        csv.push(data.join(","));
    }
    const csvFile=new Blob([csv.join("\n")],{type:"text/csv"});
    const downloadLink=document.createElement("a");
    downloadLink.download=filename;
    downloadLink.href=window.URL.createObjectURL(csvFile);
    downloadLink.style.display="none";
    document.body.appendChild(downloadLink);
    downloadLink.click();
}

function clearFilters(){
    window.location.href = window.location.pathname;
}

// ================= OLD INFORMATION FUNCTIONS =================
function loadOldFaculties() {
    <?php if ($isDepartmentAdmin): ?> return; <?php endif; ?>
    
    const campusId = document.getElementById('old_campus').value;
    const facultySelect = document.getElementById('old_faculty');
    
    if (!campusId) {
        resetOldHierarchy(['faculty', 'department', 'program']);
        return;
    }
    
    facultySelect.innerHTML = '<option value="">Loading...</option>';
    facultySelect.disabled = true;
    resetOldHierarchy(['department', 'program']);
    
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
            console.error('Error:', error);
            facultySelect.innerHTML = '<option value="">Error loading</option>';
        });
}

function loadOldDepartments() {
    <?php if ($isDepartmentAdmin): ?> return; <?php endif; ?>
    
    const facultyId = document.getElementById('old_faculty').value;
    const campusId = document.getElementById('old_campus').value;
    const deptSelect = document.getElementById('old_department');
    
    if (!facultyId || !campusId) {
        resetOldHierarchy(['department', 'program']);
        return;
    }
    
    deptSelect.innerHTML = '<option value="">Loading...</option>';
    deptSelect.disabled = true;
    resetOldHierarchy(['program']);
    
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
            console.error('Error:', error);
            deptSelect.innerHTML = '<option value="">Error loading</option>';
        });
}

function loadOldPrograms() {
    const deptId = document.getElementById('old_department').value;
    const facultyId = document.getElementById('old_faculty').value;
    const campusId = document.getElementById('old_campus').value;
    const programSelect = document.getElementById('old_program');
    
    if (!deptId || !facultyId || !campusId) {
        programSelect.innerHTML = '<option value="">All Programs</option>';
        programSelect.disabled = true;
        return;
    }
    
    programSelect.innerHTML = '<option value="">Loading...</option>';
    programSelect.disabled = true;
    
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
            console.error('Error:', error);
            programSelect.innerHTML = '<option value="">Error loading</option>';
        });
}

// ================= NEW INFORMATION FUNCTIONS =================
function loadNewFaculties() {
    <?php if ($isDepartmentAdmin): ?> return; <?php endif; ?>
    
    const campusId = document.getElementById('new_campus').value;
    const facultySelect = document.getElementById('new_faculty');
    
    if (!campusId) {
        resetNewHierarchy(['faculty', 'department', 'program']);
        return;
    }
    
    facultySelect.innerHTML = '<option value="">Loading...</option>';
    facultySelect.disabled = true;
    resetNewHierarchy(['department', 'program']);
    
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
            console.error('Error:', error);
            facultySelect.innerHTML = '<option value="">Error loading</option>';
        });
}

function loadNewDepartments() {
    <?php if ($isDepartmentAdmin): ?> return; <?php endif; ?>
    
    const facultyId = document.getElementById('new_faculty').value;
    const campusId = document.getElementById('new_campus').value;
    const deptSelect = document.getElementById('new_department');
    
    if (!facultyId || !campusId) {
        resetNewHierarchy(['department', 'program']);
        return;
    }
    
    deptSelect.innerHTML = '<option value="">Loading...</option>';
    deptSelect.disabled = true;
    resetNewHierarchy(['program']);
    
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
            console.error('Error:', error);
            deptSelect.innerHTML = '<option value="">Error loading</option>';
        });
}

function loadNewPrograms() {
    const deptId = document.getElementById('new_department').value;
    const facultyId = document.getElementById('new_faculty').value;
    const campusId = document.getElementById('new_campus').value;
    const programSelect = document.getElementById('new_program');
    
    if (!deptId || !facultyId || !campusId) {
        programSelect.innerHTML = '<option value="">All Programs</option>';
        programSelect.disabled = true;
        return;
    }
    
    programSelect.innerHTML = '<option value="">Loading...</option>';
    programSelect.disabled = true;
    
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
            console.error('Error:', error);
            programSelect.innerHTML = '<option value="">Error loading</option>';
        });
}

function resetOldHierarchy(fields) {
    fields.forEach(field => {
        const element = document.getElementById('old_' + field);
        if (element) {
            element.innerHTML = '<option value="">All ' + field.charAt(0).toUpperCase() + field.slice(1) + '</option>';
            element.disabled = true;
        }
    });
}

function resetNewHierarchy(fields) {
    fields.forEach(field => {
        const element = document.getElementById('new_' + field);
        if (element) {
            element.innerHTML = '<option value="">All ' + field.charAt(0).toUpperCase() + field.slice(1) + '</option>';
            element.disabled = true;
        }
    });
}

// Enable dropdowns if they have values on page load
window.onload = function() {
    // Old Information
    const oldFaculty = document.getElementById('old_faculty');
    const oldDepartment = document.getElementById('old_department');
    const oldProgram = document.getElementById('old_program');
    
    if (oldFaculty && oldFaculty.options.length > 1) oldFaculty.disabled = false;
    if (oldDepartment && oldDepartment.options.length > 1) oldDepartment.disabled = false;
    if (oldProgram && oldProgram.options.length > 1) oldProgram.disabled = false;
    
    // New Information
    const newFaculty = document.getElementById('new_faculty');
    const newDepartment = document.getElementById('new_department');
    const newProgram = document.getElementById('new_program');
    
    if (newFaculty && newFaculty.options.length > 1) newFaculty.disabled = false;
    if (newDepartment && newDepartment.options.length > 1) newDepartment.disabled = false;
    if (newProgram && newProgram.options.length > 1) newProgram.disabled = false;
    
    <?php if ($isDepartmentAdmin): ?>
    // For department admin, load programs after page load
    setTimeout(() => {
        if (document.getElementById('old_department').value) {
            loadOldPrograms();
        }
        if (document.getElementById('new_department').value) {
            loadNewPrograms();
        }
    }, 500);
    <?php endif; ?>
};
</script>

<?php include('../includes/footer.php'); ?>
<?php ob_end_flush(); ?>