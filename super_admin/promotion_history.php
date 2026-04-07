<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

if (strtolower($_SESSION['user']['role'] ?? '') !== 'super_admin') {
  header("Location: ../login.php");
  exit;
}

date_default_timezone_set('Africa/Nairobi');
$message = ""; 
$type = "";

// ===========================================
// AJAX HANDLERS FOR HIERARCHY
// ===========================================
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
    
    // GET CLASSES BY PROGRAM, DEPARTMENT, FACULTY & CAMPUS
    if ($_GET['ajax'] == 'get_classes_by_program') {
        $program_id = $_GET['program_id'] ?? 0;
        $department_id = $_GET['department_id'] ?? 0;
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        $stmt = $pdo->prepare("
            SELECT class_id, class_name 
            FROM classes 
            WHERE program_id = ? 
            AND department_id = ?
            AND faculty_id = ?
            AND campus_id = ?
            AND status = 'Active'
            ORDER BY class_name
        ");
        $stmt->execute([$program_id, $department_id, $faculty_id, $campus_id]);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'classes' => $classes]);
        exit;
    }
    
    // GET STUDENTS BY CLASS AND CAMPUS
    if ($_GET['ajax'] == 'get_students_by_class') {
        $class_id = $_GET['class_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        $stmt = $pdo->prepare("
            SELECT 
                s.student_id, 
                s.full_name, 
                s.reg_no,
                se.semester_id,
                sem.semester_name
            FROM students s
            JOIN student_enroll se ON se.student_id = s.student_id
            LEFT JOIN semester sem ON sem.semester_id = se.semester_id
            WHERE se.class_id = ? 
            AND se.campus_id = ?
            AND s.status = 'active'
            ORDER BY s.full_name
        ");
        $stmt->execute([$class_id, $campus_id]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'students' => $students]);
        exit;
    }
    
    // GET SUBJECTS BY SEMESTER AND HIERARCHY
    if ($_GET['ajax'] == 'get_subjects_by_semester') {
        $semester_id = $_GET['semester_id'] ?? 0;
        $program_id = $_GET['program_id'] ?? 0;
        $department_id = $_GET['department_id'] ?? 0;
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        $stmt = $pdo->prepare("
            SELECT subject_id, subject_name 
            FROM subject 
            WHERE semester_id = ?
            AND program_id = ?
            AND department_id = ?
            AND faculty_id = ?
            AND campus_id = ?
            AND status = 'active'
            ORDER BY subject_name
        ");
        $stmt->execute([$semester_id, $program_id, $department_id, $faculty_id, $campus_id]);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'subjects' => $subjects]);
        exit;
    }
}

/* ================= HANDLE PROMOTION ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['promote'])) {
  try {
    $pdo->beginTransaction();

    $student_ids     = $_POST['student_ids']  ?? [];
    $new_semester_id = $_POST['new_semester_id'] ?? null;
    $subject_ids     = $_POST['subject_ids']  ?? [];
    $remarks         = trim($_POST['remarks'] ?? '');
    $admin_id        = $_SESSION['user']['id'] ?? null;

    // To hierarchy data
    $new_campus_id   = $_POST['to_campus'] ?? null;
    $new_faculty_id  = $_POST['to_faculty'] ?? null;
    $new_department_id = $_POST['to_department'] ?? null;
    $new_program_id  = $_POST['to_program'] ?? null;
    $new_class_id    = $_POST['to_class'] ?? null;

    if (empty($student_ids))      throw new Exception("Please select at least one student!");
    if (empty($new_semester_id))  throw new Exception("Please select a new semester!");
    if (empty($new_campus_id))    throw new Exception("Please select a destination campus!");
    if (empty($new_faculty_id))   throw new Exception("Please select a destination faculty!");
    if (empty($new_department_id))throw new Exception("Please select a destination department!");
    if (empty($new_program_id))   throw new Exception("Please select a destination program!");
    if (empty($new_class_id))     throw new Exception("Please select a destination class!");

    // Validate hierarchy consistency
    $hierarchy_check = $pdo->prepare("
        SELECT COUNT(*) as count FROM programs 
        WHERE program_id = ? 
        AND department_id = ? 
        AND faculty_id = ? 
        AND campus_id = ?
    ");
    $hierarchy_check->execute([$new_program_id, $new_department_id, $new_faculty_id, $new_campus_id]);
    $check_result = $hierarchy_check->fetch(PDO::FETCH_ASSOC);
    
    if ($check_result['count'] == 0) {
        throw new Exception("Invalid hierarchy selection! Program does not belong to selected department/faculty/campus.");
    }

    foreach ($student_ids as $student_id) {
      // Get current enrollment data
      $old = $pdo->prepare("
          SELECT se.*, 
                 c.campus_name,
                 f.faculty_name,
                 d.department_name,
                 p.program_name,
                 cl.class_name,
                 sem.semester_name
          FROM student_enroll se
          LEFT JOIN campus c ON c.campus_id = se.campus_id
          LEFT JOIN faculties f ON f.faculty_id = se.faculty_id
          LEFT JOIN departments d ON d.department_id = se.department_id
          LEFT JOIN programs p ON p.program_id = se.program_id
          LEFT JOIN classes cl ON cl.class_id = se.class_id
          LEFT JOIN semester sem ON sem.semester_id = se.semester_id
          WHERE se.student_id = ? 
          LIMIT 1
      ");
      $old->execute([$student_id]);
      $old_data = $old->fetch(PDO::FETCH_ASSOC);
      if (!$old_data) continue;

      // Save promotion history
      $insert = $pdo->prepare("
        INSERT INTO promotion_history
        (student_id,
         old_faculty_id, old_department_id, old_program_id, old_semester_id, old_class_id,
         new_faculty_id, new_department_id, new_program_id, new_semester_id, new_class_id,
         old_campus_id, new_campus_id,
         promoted_by, remarks)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
      ");
      $insert->execute([
        $student_id,
        $old_data['faculty_id']    ?? null,
        $old_data['department_id'] ?? null,
        $old_data['program_id']    ?? null,
        $old_data['semester_id']   ?? null,
        $old_data['class_id']      ?? null,
        $new_faculty_id,
        $new_department_id,
        $new_program_id,
        $new_semester_id,
        $new_class_id,
        $old_data['campus_id']     ?? null,
        $new_campus_id,
        $admin_id,
        $remarks
      ]);

      // Update student_enroll with new data
      $update = $pdo->prepare("
        UPDATE student_enroll 
        SET campus_id = ?, 
            faculty_id = ?, 
            department_id = ?, 
            program_id = ?, 
            class_id = ?, 
            semester_id = ?, 
            updated_at = NOW() 
        WHERE student_id = ?
      ");
      $update->execute([
        $new_campus_id,
        $new_faculty_id,
        $new_department_id,
        $new_program_id,
        $new_class_id,
        $new_semester_id,
        $student_id
      ]);

      // Remove old subject links and add new ones
      $pdo->prepare("DELETE FROM student_subject WHERE student_id = ?")->execute([$student_id]);
      
      // Link new subjects
      foreach ($subject_ids as $sub_id) {
        $pdo->prepare("
          INSERT INTO student_subject (student_id, subject_id, assigned_at)
          VALUES (?, ?, NOW())
        ")->execute([$student_id, $sub_id]);
      }
    }

    $pdo->commit();
    $message = "✅ Selected students promoted / transferred successfully!";
    $type = "success";

  } catch (Exception $e) {
    $pdo->rollBack();
    $message = "❌ Error: " . $e->getMessage();
    $type = "error";
  }
}

/* ================= FETCH INITIAL DATA ================= */

/* ✅ Campuses */
$campuses = $pdo->query("
  SELECT campus_id, campus_name 
  FROM campus 
  WHERE status = 'active'
  ORDER BY campus_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* ✅ Semesters */
$semesters = $pdo->query("
  SELECT semester_id, semester_name 
  FROM semester 
  WHERE status = 'active'
  ORDER BY semester_id ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* ✅ Promotion History */
$history = $pdo->query("
  SELECT 
    ph.promotion_date,
    ph.student_id,
    ph.old_faculty_id,
    ph.old_department_id,
    ph.old_program_id,
    ph.old_semester_id,
    ph.new_faculty_id,
    ph.new_department_id,
    ph.new_program_id,
    ph.new_semester_id,
    ph.old_campus_id,
    ph.new_campus_id,
    ph.promoted_by,
    ph.remarks,
    s.full_name, 
    s.reg_no,
    se.semester_name AS new_sem,
    oc.campus_name AS old_campus,
    nc.campus_name AS new_campus
  FROM promotion_history ph
  JOIN students s ON s.student_id = ph.student_id
  LEFT JOIN semester se ON se.semester_id = ph.new_semester_id
  LEFT JOIN campus oc ON oc.campus_id = ph.old_campus_id
  LEFT JOIN campus nc ON nc.campus_id = ph.new_campus_id
  ORDER BY ph.promotion_date DESC
  LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

include('../includes/header.php');
?>
<style>
  .sidebar.collapsed ~ .main-content {
  margin-left: 70px;
}
</style>
<div class="main-content">
  <div class="page-header"><h1>Student Promotion</h1></div>

<!-- PROMOTION FORM -->
<div class="filter-box">
  <form method="POST" id="promotionForm">
    <div class="grid">

      <!-- ========== FROM HIERARCHY ========== -->
      <fieldset style="border:1px solid #ccc;border-radius:8px;padding:10px;">
        <legend style="color:#0072CE;font-weight:600;">From (Current)</legend>
        
        <div>
          <label>Campus</label>
          <select id="from_campus" onchange="fromCampusChange()" required>
            <option value="">Select Campus</option>
            <?php foreach($campuses as $c): ?>
              <option value="<?= $c['campus_id'] ?>"><?= htmlspecialchars($c['campus_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label>Faculty</label>
          <select id="from_faculty" onchange="fromFacultyChange()" required disabled>
            <option value="">Select Faculty</option>
          </select>
        </div>

        <div>
          <label>Department</label>
          <select id="from_department" onchange="fromDepartmentChange()" required disabled>
            <option value="">Select Department</option>
          </select>
        </div>

        <div>
          <label>Program</label>
          <select id="from_program" onchange="fromProgramChange()" required disabled>
            <option value="">Select Program</option>
          </select>
        </div>

        <div>
          <label>Class</label>
          <select id="from_class" onchange="fromClassChange()" required disabled>
            <option value="">Select Class</option>
          </select>
        </div>

        <div class="checkbox-group">
          <label>Select Students</label>
          <div class="checkbox-list" id="from_student_list">
            <small style="color:#777;">Please select class first.</small>
          </div>
        </div>
      </fieldset>

      <!-- ========== TO HIERARCHY ========== -->
      <fieldset style="border:1px solid #ccc;border-radius:8px;padding:10px;">
        <legend style="color:#0072CE;font-weight:600;">To (Destination)</legend>
        
        <div>
          <label>Campus</label>
          <select id="to_campus" name="to_campus" onchange="toCampusChange()" required>
            <option value="">Select Campus</option>
            <?php foreach($campuses as $c): ?>
              <option value="<?= $c['campus_id'] ?>"><?= htmlspecialchars($c['campus_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label>Faculty</label>
          <select id="to_faculty" name="to_faculty" onchange="toFacultyChange()" required disabled>
            <option value="">Select Faculty</option>
          </select>
        </div>

        <div>
          <label>Department</label>
          <select id="to_department" name="to_department" onchange="toDepartmentChange()" required disabled>
            <option value="">Select Department</option>
          </select>
        </div>

        <div>
          <label>Program</label>
          <select id="to_program" name="to_program" onchange="toProgramChange()" required disabled>
            <option value="">Select Program</option>
          </select>
        </div>

        <div>
          <label>Class</label>
          <select id="to_class" name="to_class" required disabled>
            <option value="">Select Class</option>
          </select>
        </div>

        <div>
          <label>New Semester</label>
          <select id="new_semester" name="new_semester_id" onchange="toSemesterChange()" required>
            <option value="">Select Semester</option>
            <?php foreach($semesters as $sem): ?>
              <option value="<?= $sem['semester_id'] ?>"><?= htmlspecialchars($sem['semester_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="checkbox-group">
          <label>Select Subjects</label>
          <div class="checkbox-list" id="to_subject_list">
            <small style="color:#777;">Please select semester first.</small>
          </div>
        </div>

        <div>
          <label>Remarks</label>
          <input type="text" name="remarks" placeholder="Optional comment...">
        </div>

        <div style="align-self:end;">
          <button type="submit" name="promote" class="btn green">
            <i class="fa fa-arrow-up"></i> Promote Selected Students
          </button>
        </div>
      </fieldset>

    </div>
  </form>
</div>

  <!-- PROMOTION HISTORY -->
  <div class="table-wrapper">
    <h3 style="color:#0072CE;margin:10px;">Recent Promotion History</h3>
    <table>
      <thead>
        <tr>
          <th>Date</th>
          <th>Student</th>
          <th>Reg No</th>
          <th>Old Campus</th>
          <th>New Campus</th>
          <th>Old Semester</th>
          <th>New Semester</th>
          <th>Remarks</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($history as $h): ?>
        <tr>
          <td><?= htmlspecialchars($h['promotion_date']) ?></td>
          <td><?= htmlspecialchars($h['full_name']) ?></td>
          <td><?= htmlspecialchars($h['reg_no']) ?></td>
          <td><?= htmlspecialchars($h['old_campus'] ?? 'N/A') ?></td>
          <td><?= htmlspecialchars($h['new_campus'] ?? 'N/A') ?></td>
          <td><?= htmlspecialchars($h['old_semester_id']) ?></td>
          <td><?= htmlspecialchars($h['new_sem'] ?? $h['new_semester_id']) ?></td>
          <td><?= htmlspecialchars($h['remarks']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if($message): ?>
<div class="alert <?= $type ?>"><strong><?= $message ?></strong></div>
<script>
  setTimeout(()=>document.querySelector('.alert').remove(),5000);
</script>
<?php endif; ?>

<!-- STYLE -->
<style>
body{font-family:'Poppins',sans-serif;background:#f5f9f7;margin:0;}
.main-content{padding:25px;margin-left:250px;margin-top:90px;}
.page-header h1{color:#0072CE;margin-bottom:15px;}
.filter-box{background:#fff;padding:20px;border-radius:10px;box-shadow:0 3px 8px rgba(0,0,0,0.08);margin-bottom:15px;}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:15px;}
label{font-weight:600;color:#0072CE;font-size:13px;}
select,input{width:100%;padding:8px;border:1px solid #ccc;border-radius:6px;background:#fafafa;}
.btn{border:none;padding:9px 15px;border-radius:6px;font-weight:600;cursor:pointer;}
.btn.green{background:#00843D;color:#fff;}
.table-wrapper{overflow:auto;background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.08);}
table{width:100%;border-collapse:collapse;}
th,td{padding:10px 12px;border-bottom:1px solid #eee;text-align:left;font-size:14px;}
thead th{background:#0072CE;color:#fff;position:sticky;top:0;}
tr:hover{background:#f3f8ff;}
.alert{position:fixed;top:15px;right:15px;background:#00843D;color:#fff;padding:10px 20px;border-radius:6px;font-weight:600;z-index:9999;}
.alert.error{background:#C62828;}
.checkbox-list{
  max-height:220px;
  overflow-y:auto;
  border:1px solid #ddd;
  border-radius:6px;
  padding:6px;
  background:#f9f9f9;
}
.checkbox-list label{
  display:block;
  padding:3px 2px;
  font-size:13px;
  cursor:pointer;
}
.checkbox-list input{margin-right:6px;}
</style>

<script>
// ===========================================
// FROM SECTION FUNCTIONS
// ===========================================

function fromCampusChange() {
    const campusId = document.getElementById('from_campus').value;
    const facultySelect = document.getElementById('from_faculty');
    
    if (!campusId) {
        resetFromHierarchy();
        return;
    }
    
    // Reset child dropdowns
    resetFromDropdowns(['department', 'program', 'class', 'student_list']);
    facultySelect.innerHTML = '<option value="">Loading...</option>';
    
    fetch(`?ajax=get_faculties_by_campus&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            facultySelect.innerHTML = '<option value="">Select Faculty</option>';
            
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

function fromFacultyChange() {
    const facultyId = document.getElementById('from_faculty').value;
    const campusId = document.getElementById('from_campus').value;
    const deptSelect = document.getElementById('from_department');
    
    if (!facultyId || !campusId) {
        resetFromDropdowns(['department', 'program', 'class', 'student_list']);
        return;
    }
    
    deptSelect.innerHTML = '<option value="">Loading...</option>';
    
    fetch(`?ajax=get_departments_by_faculty&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            deptSelect.innerHTML = '<option value="">Select Department</option>';
            
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
    
    resetFromDropdowns(['program', 'class', 'student_list']);
}

function fromDepartmentChange() {
    const deptId = document.getElementById('from_department').value;
    const facultyId = document.getElementById('from_faculty').value;
    const campusId = document.getElementById('from_campus').value;
    const programSelect = document.getElementById('from_program');
    
    if (!deptId || !facultyId || !campusId) {
        resetFromDropdowns(['program', 'class', 'student_list']);
        return;
    }
    
    programSelect.innerHTML = '<option value="">Loading...</option>';
    
    fetch(`?ajax=get_programs_by_department&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            programSelect.innerHTML = '<option value="">Select Program</option>';
            
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
    
    resetFromDropdowns(['class', 'student_list']);
}

function fromProgramChange() {
    const programId = document.getElementById('from_program').value;
    const deptId = document.getElementById('from_department').value;
    const facultyId = document.getElementById('from_faculty').value;
    const campusId = document.getElementById('from_campus').value;
    const classSelect = document.getElementById('from_class');
    
    if (!programId || !deptId || !facultyId || !campusId) {
        resetFromDropdowns(['class', 'student_list']);
        return;
    }
    
    classSelect.innerHTML = '<option value="">Loading...</option>';
    
    fetch(`?ajax=get_classes_by_program&program_id=${programId}&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            classSelect.innerHTML = '<option value="">Select Class</option>';
            
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
    
    document.getElementById('from_student_list').innerHTML = '<small style="color:#777;">Please select class first.</small>';
}

function fromClassChange() {
    const classId = document.getElementById('from_class').value;
    const campusId = document.getElementById('from_campus').value;
    const studentList = document.getElementById('from_student_list');
    
    if (!classId || !campusId) {
        studentList.innerHTML = '<small style="color:#777;">Please select class first.</small>';
        return;
    }
    
    studentList.innerHTML = '<small style="color:#777;">Loading students...</small>';
    
    fetch(`?ajax=get_students_by_class&class_id=${classId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            studentList.innerHTML = '';
            
            if (data.status === 'success' && data.students.length > 0) {
                data.students.forEach(student => {
                    const label = document.createElement('label');
                    label.innerHTML = `
                        <input type="checkbox" name="student_ids[]" value="${student.student_id}">
                        ${student.full_name} (${student.reg_no}) - ${student.semester_name || 'No semester'}
                    `;
                    studentList.appendChild(label);
                });
            } else {
                studentList.innerHTML = '<small style="color:#777;">No students found for this class.</small>';
            }
        });
}

// ===========================================
// TO SECTION FUNCTIONS
// ===========================================

function toCampusChange() {
    const campusId = document.getElementById('to_campus').value;
    const facultySelect = document.getElementById('to_faculty');
    
    if (!campusId) {
        resetToHierarchy();
        return;
    }
    
    // Reset child dropdowns
    resetToDropdowns(['department', 'program', 'class', 'subject_list']);
    facultySelect.innerHTML = '<option value="">Loading...</option>';
    
    fetch(`?ajax=get_faculties_by_campus&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            facultySelect.innerHTML = '<option value="">Select Faculty</option>';
            
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

function toFacultyChange() {
    const facultyId = document.getElementById('to_faculty').value;
    const campusId = document.getElementById('to_campus').value;
    const deptSelect = document.getElementById('to_department');
    
    if (!facultyId || !campusId) {
        resetToDropdowns(['department', 'program', 'class', 'subject_list']);
        return;
    }
    
    deptSelect.innerHTML = '<option value="">Loading...</option>';
    
    fetch(`?ajax=get_departments_by_faculty&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            deptSelect.innerHTML = '<option value="">Select Department</option>';
            
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
    
    resetToDropdowns(['program', 'class', 'subject_list']);
}

function toDepartmentChange() {
    const deptId = document.getElementById('to_department').value;
    const facultyId = document.getElementById('to_faculty').value;
    const campusId = document.getElementById('to_campus').value;
    const programSelect = document.getElementById('to_program');
    
    if (!deptId || !facultyId || !campusId) {
        resetToDropdowns(['program', 'class', 'subject_list']);
        return;
    }
    
    programSelect.innerHTML = '<option value="">Loading...</option>';
    
    fetch(`?ajax=get_programs_by_department&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            programSelect.innerHTML = '<option value="">Select Program</option>';
            
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
    
    resetToDropdowns(['class', 'subject_list']);
}

function toProgramChange() {
    const programId = document.getElementById('to_program').value;
    const deptId = document.getElementById('to_department').value;
    const facultyId = document.getElementById('to_faculty').value;
    const campusId = document.getElementById('to_campus').value;
    const classSelect = document.getElementById('to_class');
    
    if (!programId || !deptId || !facultyId || !campusId) {
        resetToDropdowns(['class', 'subject_list']);
        return;
    }
    
    classSelect.innerHTML = '<option value="">Loading...</option>';
    
    fetch(`?ajax=get_classes_by_program&program_id=${programId}&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            classSelect.innerHTML = '<option value="">Select Class</option>';
            
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
    
    document.getElementById('to_subject_list').innerHTML = '<small style="color:#777;">Please select semester first.</small>';
}

function toSemesterChange() {
    const semesterId = document.getElementById('new_semester').value;
    const programId = document.getElementById('to_program').value;
    const deptId = document.getElementById('to_department').value;
    const facultyId = document.getElementById('to_faculty').value;
    const campusId = document.getElementById('to_campus').value;
    const subjectList = document.getElementById('to_subject_list');
    
    // Need all hierarchy elements to load subjects
    if (!semesterId || !programId || !deptId || !facultyId || !campusId) {
        subjectList.innerHTML = '<small style="color:#777;">Please complete all hierarchy selections first.</small>';
        return;
    }
    
    subjectList.innerHTML = '<small style="color:#777;">Loading subjects...</small>';
    
    fetch(`?ajax=get_subjects_by_semester&semester_id=${semesterId}&program_id=${programId}&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            subjectList.innerHTML = '';
            
            if (data.status === 'success' && data.subjects.length > 0) {
                data.subjects.forEach(subject => {
                    const label = document.createElement('label');
                    label.innerHTML = `
                        <input type="checkbox" name="subject_ids[]" value="${subject.subject_id}">
                        ${subject.subject_name}
                    `;
                    subjectList.appendChild(label);
                });
            } else {
                subjectList.innerHTML = '<small style="color:#777;">No subjects found for this semester and program.</small>';
            }
        });
}

// ===========================================
// HELPER FUNCTIONS
// ===========================================

function resetFromHierarchy() {
    document.getElementById('from_faculty').innerHTML = '<option value="">Select Faculty</option>';
    document.getElementById('from_faculty').disabled = true;
    resetFromDropdowns(['department', 'program', 'class', 'student_list']);
}

function resetToHierarchy() {
    document.getElementById('to_faculty').innerHTML = '<option value="">Select Faculty</option>';
    document.getElementById('to_faculty').disabled = true;
    resetToDropdowns(['department', 'program', 'class', 'subject_list']);
}

function resetFromDropdowns(fields) {
    fields.forEach(field => {
        const element = document.getElementById('from_' + field);
        if (element) {
            if (field === 'student_list') {
                element.innerHTML = '<small style="color:#777;">Please select class first.</small>';
            } else {
                element.innerHTML = '<option value="">Select ' + field.charAt(0).toUpperCase() + field.slice(1) + '</option>';
                element.disabled = true;
            }
        }
    });
}

function resetToDropdowns(fields) {
    fields.forEach(field => {
        const element = document.getElementById('to_' + field);
        if (element) {
            if (field === 'subject_list') {
                element.innerHTML = '<small style="color:#777;">Please select semester first.</small>';
            } else {
                element.innerHTML = '<option value="">Select ' + field.charAt(0).toUpperCase() + field.slice(1) + '</option>';
                element.disabled = true;
            }
        }
    });
}

// Form validation
document.getElementById('promotionForm').onsubmit = function(e) {
    const studentCheckboxes = document.querySelectorAll('input[name="student_ids[]"]:checked');
    if (studentCheckboxes.length === 0) {
        alert('Please select at least one student to promote.');
        e.preventDefault();
        return false;
    }
    
    const subjectCheckboxes = document.querySelectorAll('input[name="subject_ids[]"]:checked');
    if (subjectCheckboxes.length === 0) {
        if (!confirm('No subjects selected. Students will be promoted without subjects. Continue?')) {
            e.preventDefault();
            return false;
        }
    }
    
    return true;
};
</script>

<?php include('../includes/footer.php'); ?>
<?php ob_end_flush(); ?>