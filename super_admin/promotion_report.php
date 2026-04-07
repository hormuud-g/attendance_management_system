<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

if (strtolower($_SESSION['user']['role'] ?? '') !== 'super_admin') {
  header("Location: ../login.php");
  exit;
}

date_default_timezone_set('Africa/Nairobi');
$message = ""; $type = "";

/* ================= AJAX HANDLERS ================= */
if (isset($_GET['ajax'])) {
    // GET FACULTIES BY CAMPUS
    if ($_GET['ajax'] == 'get_faculties_by_campus') {
        $campus_id = $_GET['campus_id'] ?? 0;
        
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
        
        echo json_encode(['status' => 'success', 'faculties' => $faculties]);
        exit;
    }
    
    // GET DEPARTMENTS BY FACULTY & CAMPUS
    if ($_GET['ajax'] == 'get_departments_by_faculty') {
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
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
$campuses = $pdo->query("SELECT * FROM campus WHERE status='active' ORDER BY campus_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$semesters = $pdo->query("SELECT * FROM semester ORDER BY semester_id ASC")->fetchAll(PDO::FETCH_ASSOC);

/* ================= FILTERS ================= */
$where = "1=1";
$params = [];

// Old Campus Filter
if (!empty($_GET['old_campus_id'])) { 
    $where .= " AND ph.old_campus_id=?"; 
    $params[] = $_GET['old_campus_id']; 
}

// New Campus Filter
if (!empty($_GET['new_campus_id'])) { 
    $where .= " AND ph.new_campus_id=?"; 
    $params[] = $_GET['new_campus_id']; 
}

// Old Faculty Filter
if (!empty($_GET['old_faculty_id'])) { 
    $where .= " AND ph.old_faculty_id=?"; 
    $params[] = $_GET['old_faculty_id']; 
}

// New Faculty Filter
if (!empty($_GET['new_faculty_id'])) { 
    $where .= " AND ph.new_faculty_id=?"; 
    $params[] = $_GET['new_faculty_id']; 
}

// Old Department Filter
if (!empty($_GET['old_department_id'])) { 
    $where .= " AND ph.old_department_id=?"; 
    $params[] = $_GET['old_department_id']; 
}

// New Department Filter
if (!empty($_GET['new_department_id'])) { 
    $where .= " AND ph.new_department_id=?"; 
    $params[] = $_GET['new_department_id']; 
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
</style>
<div class="main-content">
  <div class="page-header">
    <h1>Promotion Report</h1>
  </div>

  <!-- FILTER FORM -->
  <div class="filter-box">
    <form method="GET">
     

      <div class="filter-section" style="margin-top:20px;">
        <h3 style="color:#0072CE;margin-bottom:15px;">Information Filters</h3>
        <div class="grid">
          <div>
            <label>New Campus</label>
            <select name="new_campus_id" id="new_campus" onchange="loadNewFaculties()">
              <option value="">All Campuses</option>
              <?php foreach($campuses as $c): ?>
                <option value="<?= $c['campus_id'] ?>" <?= (!empty($_GET['new_campus_id']) && $_GET['new_campus_id']==$c['campus_id'])?'selected':'' ?>>
                  <?= htmlspecialchars($c['campus_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label>New Faculty</label>
            <select name="new_faculty_id" id="new_faculty" onchange="loadNewDepartments()" disabled>
              <option value="">All Faculties</option>
              <?php if(!empty($_GET['new_faculty_id'])): ?>
                <?php 
                if(!empty($_GET['new_campus_id'])) {
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
          </div>

          <div>
            <label>New Department</label>
            <select name="new_department_id" id="new_department" onchange="loadNewPrograms()" disabled>
              <option value="">All Departments</option>
              <?php if(!empty($_GET['new_department_id']) && !empty($_GET['new_faculty_id']) && !empty($_GET['new_campus_id'])): ?>
                <?php 
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
                ?>
              <?php endif; ?>
            </select>
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
      </div>
    </div>

    <div style="padding:10px 15px;display:flex;justify-content:space-between;align-items:center;" class="noprint">
      <h3 style="color:#0072CE;">Promotion History</h3>
      <div>
        <button onclick="exportTableToCSV('promotion_history.csv')" class="btn blue"><i class="fa fa-file-excel"></i> Excel</button>
      </div>
    </div>

    <table id="reportTable">
      <thead>
        <tr>
          <th>#</th>
          <th>Student</th>
          <th>Reg No</th>
          
          <!-- Old Information -->
          <th>Old Campus</th>
          <th>Old Faculty</th>
          <th>Old Department</th>
          <th>Old Program</th>
          <th>Old Semester</th>
          
          <!-- New Information -->
          <th>New Campus</th>
          <th>New Faculty</th>
          <th>New Department</th>
          <th>New Program</th>
          <th>New Semester</th>
          
          <th>Promoted By</th>
          <th>Promotion Date</th>
          <th>Remarks</th>
        </tr>
      </thead>
      <tbody>
        <?php if($promotions): $i=1; foreach($promotions as $r): ?>
        <tr>
          <td><?= $i++ ?></td>
          <td><?= htmlspecialchars($r['full_name']) ?></td>
          <td><?= htmlspecialchars($r['reg_no']) ?></td>
          
          <!-- Old Information -->
          <td><?= htmlspecialchars($r['old_campus_name'] ?? '-') ?></td>
          <td><?= htmlspecialchars($r['old_faculty_name'] ?? '-') ?></td>
          <td><?= htmlspecialchars($r['old_department_name'] ?? '-') ?></td>
          <td><?= htmlspecialchars($r['old_program_name'] ?? '-') ?></td>
          <td><?= htmlspecialchars($r['old_semester_name'] ?? '-') ?></td>
          
          <!-- New Information -->
          <td><?= htmlspecialchars($r['new_campus_name'] ?? '-') ?></td>
          <td><?= htmlspecialchars($r['new_faculty_name'] ?? '-') ?></td>
          <td><?= htmlspecialchars($r['new_department_name'] ?? '-') ?></td>
          <td><?= htmlspecialchars($r['new_program_name'] ?? '-') ?></td>
          <td><?= htmlspecialchars($r['new_semester_name'] ?? '-') ?></td>
          
          <td><?= htmlspecialchars($r['promoted_by'] ?? '-') ?></td>
          <td><?= htmlspecialchars($r['promotion_date']) ?></td>
          <td><?= htmlspecialchars($r['remarks'] ?? '') ?></td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="16" style="text-align:center;color:#777;">No promotion records found.</td></tr>
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
label{font-weight:600;color:#0072CE;font-size:13px;}
select,input[type=date]{width:100%;padding:8px;border:1px solid #ccc;border-radius:6px;background:#fafafa;}
.btn{border:none;padding:9px 15px;border-radius:6px;font-weight:600;cursor:pointer;}
.btn.blue{background:#0072CE;color:#fff;}
.btn.green{background:#00843D;color:#fff;}
.btn.red{background:#C62828;color:#fff;}
.table-wrapper{overflow:auto;background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.08);padding-bottom:20px;}
table{width:100%;border-collapse:collapse;}
th,td{padding:10px 12px;border:1px solid #ddd;text-align:left;font-size:13px;}
thead th{background:#0072CE;color:#fff;position:sticky;top:0;}
tr:hover{background:#f3f8ff;}
.print-header{text-align:center;display:none;}
.print-header img{width:80px;margin-top:10px;}
.print-header h2{margin:5px 0;color:#0072CE;}
.signature{text-align:center;margin-top:30px;}

/* Highlight differences */
tr td:nth-child(4), tr td:nth-child(5), tr td:nth-child(6), tr td:nth-child(7), tr td:nth-child(8) {
    background-color: #fff5f5;
}

tr td:nth-child(9), tr td:nth-child(10), tr td:nth-child(11), tr td:nth-child(12), tr td:nth-child(13) {
    background-color: #f5fff5;
}

@media print{
  body{background:#fff;}
  .noprint,.filter-box{display:none!important;}
  .print-header{display:block;}
  .main-content{margin:0;padding:0;}
  table{font-size:10px;border-collapse:collapse;width:100%;}
  th,td{border:1px solid #000;padding:4px;}
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
  const csv=[];const rows=document.querySelectorAll("#reportTable tr");
  for(let i=0;i<rows.length;i++){
    const cols=rows[i].querySelectorAll("td, th");const data=[];
    for(let j=0;j<cols.length;j++){data.push('"' + cols[j].innerText.replace(/"/g,'""') + '"');}
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
    const campusId = document.getElementById('old_campus').value;
    const facultySelect = document.getElementById('old_faculty');
    
    if (!campusId) {
        resetOldHierarchy(['faculty', 'department', 'program']);
        return;
    }
    
    facultySelect.innerHTML = '<option value="">All Faculties</option>';
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
        });
}

function loadOldDepartments() {
    const facultyId = document.getElementById('old_faculty').value;
    const campusId = document.getElementById('old_campus').value;
    const deptSelect = document.getElementById('old_department');
    
    if (!facultyId || !campusId) {
        resetOldHierarchy(['department', 'program']);
        return;
    }
    
    deptSelect.innerHTML = '<option value="">All Departments</option>';
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
        });
}

// ================= NEW INFORMATION FUNCTIONS =================
function loadNewFaculties() {
    const campusId = document.getElementById('new_campus').value;
    const facultySelect = document.getElementById('new_faculty');
    
    if (!campusId) {
        resetNewHierarchy(['faculty', 'department', 'program']);
        return;
    }
    
    facultySelect.innerHTML = '<option value="">All Faculties</option>';
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
        });
}

function loadNewDepartments() {
    const facultyId = document.getElementById('new_faculty').value;
    const campusId = document.getElementById('new_campus').value;
    const deptSelect = document.getElementById('new_department');
    
    if (!facultyId || !campusId) {
        resetNewHierarchy(['department', 'program']);
        return;
    }
    
    deptSelect.innerHTML = '<option value="">All Departments</option>';
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
};
</script>

<?php include('../includes/footer.php'); ?>
<?php ob_end_flush(); ?>