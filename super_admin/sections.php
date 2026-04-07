<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Access Control
if (strtolower($_SESSION['user']['role'] ?? '') !== 'super_admin') {
  header("Location: ../login.php");
  exit;
}

$message = "";
$type = "";

// ===========================================
// AJAX HANDLERS
// ===========================================
if (isset($_GET['ajax'])) {
    // GET DEPARTMENTS BY FACULTY & CAMPUS
    if ($_GET['ajax'] == 'get_departments') {
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        if (!$faculty_id || !$campus_id) {
            echo json_encode(['status' => 'error', 'message' => 'Faculty ID and Campus ID required']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT department_id, department_name 
            FROM departments 
            WHERE faculty_id = ? AND campus_id = ?
            ORDER BY department_name
        ");
        $stmt->execute([$faculty_id, $campus_id]);
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($departments) {
            echo json_encode(['status' => 'success', 'departments' => $departments]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No departments found']);
        }
        exit;
    }
    
    // GET FACULTIES BY CAMPUS
    if ($_GET['ajax'] == 'get_faculties') {
        $campus_id = $_GET['campus_id'] ?? 0;
        
        if (!$campus_id) {
            echo json_encode(['status' => 'error', 'message' => 'Campus ID required']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT DISTINCT f.faculty_id, f.faculty_name 
            FROM faculties f
            JOIN faculty_campus fc ON f.faculty_id = fc.faculty_id
            WHERE fc.campus_id = ?
            ORDER BY f.faculty_name
        ");
        $stmt->execute([$campus_id]);
        $faculties = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($faculties) {
            echo json_encode(['status' => 'success', 'faculties' => $faculties]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No faculties found for this campus']);
        }
        exit;
    }
    
    // GET PROGRAMS BY DEPARTMENT, FACULTY & CAMPUS
    if ($_GET['ajax'] == 'get_programs') {
        $department_id = $_GET['department_id'] ?? 0;
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        if (!$department_id || !$faculty_id || !$campus_id) {
            echo json_encode(['status' => 'error', 'message' => 'Department, Faculty and Campus ID required']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT program_id, program_name, program_code 
            FROM programs 
            WHERE department_id = ? 
            AND faculty_id = ?
            AND campus_id = ?
            AND status = 'active'
            ORDER BY program_name
        ");
        $stmt->execute([$department_id, $faculty_id, $campus_id]);
        $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($programs) {
            echo json_encode(['status' => 'success', 'programs' => $programs]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No programs found for this department']);
        }
        exit;
    }
    
    // GET CLASSES BY PROGRAM, DEPARTMENT, FACULTY & CAMPUS
    if ($_GET['ajax'] == 'get_classes') {
        $program_id = $_GET['program_id'] ?? 0;
        $department_id = $_GET['department_id'] ?? 0;
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        if (!$program_id || !$department_id || !$faculty_id || !$campus_id) {
            echo json_encode(['status' => 'error', 'message' => 'All IDs required']);
            exit;
        }
        
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
        
        if ($classes) {
            echo json_encode(['status' => 'success', 'classes' => $classes]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No classes found for this program']);
        }
        exit;
    }
}

/* ===========================================
   CRUD OPERATIONS FOR CLASS SECTIONS (Simplified)
=========================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 🟢 ADD CLASS SECTION
    if ($_POST['action'] === 'add') {
        try {
            if (empty($_POST['class_id'])) {
                throw new Exception("Class selection is required!");
            }

            $section_name = trim($_POST['section_name']);

            $stmt = $pdo->prepare("
                INSERT INTO class_sections 
                (class_id, section_name, capacity, status)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $_POST['class_id'],
                strtoupper($section_name),
                $_POST['capacity'] ?? 30,
                $_POST['status'] ?? 'Active'
            ]);

            $message = "✅ Class section added successfully!";
            $type = "success";
        } catch (Exception $e) {
            $message = "❌ " . $e->getMessage();
            $type = "error";
        }
    }

    // 🟡 UPDATE CLASS SECTION
    if ($_POST['action'] === 'update') {
        try {
            if (empty($_POST['class_id'])) {
                throw new Exception("Class selection is required!");
            }

            $section_name = trim($_POST['section_name']);

            $stmt = $pdo->prepare("
                UPDATE class_sections 
                SET class_id = ?, 
                    section_name = ?, 
                    capacity = ?, 
                    status = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE section_id = ?
            ");
            $stmt->execute([
                $_POST['class_id'],
                strtoupper($section_name),
                $_POST['capacity'] ?? 30,
                $_POST['status'] ?? 'Active',
                $_POST['section_id']
            ]);

            $message = "✅ Class section updated successfully!";
            $type = "success";
        } catch (Exception $e) {
            $message = "❌ " . $e->getMessage();
            $type = "error";
        }
    }

    // 🔴 DELETE CLASS SECTION
    if ($_POST['action'] === 'delete') {
        try {
            $check = $pdo->prepare("
                SELECT COUNT(*) FROM class_allocations WHERE section_id = ? 
                UNION ALL
                SELECT COUNT(*) FROM student_enrollments WHERE section_id = ?
            ");
            $check->execute([$_POST['section_id'], $_POST['section_id']]);
            $counts = $check->fetchAll(PDO::FETCH_COLUMN);

            if (array_sum($counts) > 0) {
                throw new Exception("Cannot delete section. It has allocations or student enrollments!");
            }

            $pdo->prepare("DELETE FROM class_sections WHERE section_id = ?")
                ->execute([$_POST['section_id']]);

            $message = "✅ Class section deleted successfully!";
            $type = "success";
        } catch (Exception $e) {
            $message = "❌ " . $e->getMessage();
            $type = "error";
        }
    }

    // 🟣 TOGGLE STATUS
    if ($_POST['action'] === 'toggle_status') {
        try {
            $stmt = $pdo->prepare("
                UPDATE class_sections 
                SET status = CASE 
                    WHEN status = 'Active' THEN 'Inactive' 
                    ELSE 'Active' 
                END,
                updated_at = CURRENT_TIMESTAMP
                WHERE section_id = ?
            ");
            $stmt->execute([$_POST['section_id']]);

            $message = "✅ Class section status updated!";
            $type = "success";
        } catch (Exception $e) {
            $message = "❌ " . $e->getMessage();
            $type = "error";
        }
    }
}


/* ===========================================
   FETCH DATA
=========================================== */
$campuses = $pdo->query("SELECT * FROM campus ORDER BY campus_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$faculties = $pdo->query("SELECT * FROM faculties ORDER BY faculty_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$departments = $pdo->query("SELECT * FROM departments ORDER BY department_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch class sections with joins including class, program, department, faculty, and campus details
$sections = $pdo->query("
    SELECT 
        cs.section_id,
        cs.section_name,
        cs.capacity,
        cs.status,
        cs.created_at,
        cs.updated_at,
        c.class_id,
        c.class_name,
        p.program_id,
        p.program_name,
        p.program_code,
        d.department_id,
        d.department_name,
        f.faculty_id,
        f.faculty_name,
        cam.campus_id,
        cam.campus_name
    FROM class_sections cs
    JOIN classes c ON cs.class_id = c.class_id
    LEFT JOIN programs p ON c.program_id = p.program_id
    LEFT JOIN departments d ON c.department_id = d.department_id
    LEFT JOIN faculties f ON c.faculty_id = f.faculty_id
    LEFT JOIN campus cam ON c.campus_id = cam.campus_id
    ORDER BY cs.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Get current file name for AJAX calls
$current_file = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Class Section Management | Hormuud University</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="../images.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
:root {
    --green: #00843D;
    --blue: #0072CE;
    --red: #C62828;
    --orange: #FF9800;
    --light-green: #00A651;
    --bg: #F5F9F7;
}
body {
    font-family: 'Poppins', sans-serif;
    background: var(--bg);
    margin: 0;
    color: #333;
}
.main-content {
    padding: 20px;
    margin-top: 90px;
    margin-left: 250px;
    transition: all .3s ease;
}
.sidebar.collapsed ~ .main-content {
    margin-left: 70px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}
.page-header h1 {
    color: var(--blue);
    font-size: 24px;
    margin: 0;
    font-weight: 700;
}
.add-btn {
    background: var(--green);
    color: #fff;
    border: none;
    padding: 10px 18px;
    border-radius: 6px;
    font-weight: 600;
    cursor: pointer;
    transition: .3s;
}
.add-btn:hover {
    background: var(--light-green);
}

/* Table */
.table-wrapper {
    overflow: auto;
    max-height: 500px;
    border-radius: 10px;
}
table {
    width: 100%;
    border-collapse: collapse;
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}
thead th {
    position: sticky;
    top: 0;
    background: var(--blue);
    color: #fff;
    z-index: 2;
}
th, td {
    padding: 12px 14px;
    border-bottom: 1px solid #eee;
    text-align: left;
    white-space: nowrap;
}
tr:hover {
    background: #eef8f0;
}

/* Status badges */
.status-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}
.status-active {
    background: #d4edda;
    color: #155724;
}
.status-inactive {
    background: #f8d7da;
    color: #721c24;
}

.action-btns {
    display: flex;
    justify-content: center;
    gap: 8px;
}
.edit-btn, .del-btn, .toggle-btn {
    border: none;
    border-radius: 6px;
    padding: 8px 10px;
    color: #fff;
    cursor: pointer;
    transition: .3s;
    display: flex;
    align-items: center;
    justify-content: center;
}
.edit-btn {
    background: var(--blue);
}
.edit-btn:hover {
    background: #2196f3;
}
.del-btn {
    background: var(--red);
}
.del-btn:hover {
    background: #e53935;
}
.toggle-btn {
    background: var(--orange);
}
.toggle-btn:hover {
    background: #ffb74d;
}

/* Modal */
.modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    justify-content: center;
    align-items: center;
    z-index: 3000;
}
.modal.show {
    display: flex;
}

.modal-content {
    background: #fff;
    border-radius: 10px;
    width: 90%;
    max-width: 600px;
    padding: 25px 35px;
    position: relative;
    box-shadow: 0 8px 25px rgba(0,0,0,0.25);
    border-top: 5px solid var(--blue);
    animation: fadeIn .3s ease;
}
@keyframes fadeIn {
    from {
        opacity: 0;
        transform: scale(0.9) translateY(-20px);
    }
    to {
        opacity: 1;
        transform: scale(1) translateY(0);
    }
}
.close-modal {
    position: absolute;
    top: 10px;
    right: 15px;
    font-size: 24px;
    cursor: pointer;
    color: var(--red);
    transition: .2s;
}
.close-modal:hover {
    transform: scale(1.2);
}

/* Form */
#sectionForm {
    display: grid;
    grid-template-columns: 1fr;
    gap: 16px;
}
#sectionForm label {
    font-weight: 600;
    color: var(--blue);
    font-size: 13.5px;
    margin-bottom: 4px;
    display: block;
}
#sectionForm input, #sectionForm select {
    width: 100%;
    padding: 8px 10px;
    border: 1.5px solid #ccc;
    border-radius: 6px;
    font-size: 13.5px;
    background: #f9f9f9;
    transition: 0.2s;
}
#sectionForm input:focus, #sectionForm select:focus {
    border-color: var(--blue);
    box-shadow: 0 0 0 3px rgba(0,114,206,0.12);
    outline: none;
}
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}
.save-btn {
    background: var(--green);
    color: #fff;
    border: none;
    padding: 12px;
    border-radius: 6px;
    font-weight: 600;
    cursor: pointer;
    transition: .3s;
    font-size: 14px;
    margin-top: 10px;
}
.save-btn:hover {
    background: var(--light-green);
}

/* Required field indicator */
.required::after {
    content: " *";
    color: var(--red);
}

/* Alert Overlay */
.alert-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.45);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 4000;
}
.alert-box {
    min-width: 260px;
    max-width: 420px;
    background: #ffffff;
    border-radius: 10px;
    padding: 18px 20px 16px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.25);
    border-left: 5px solid #cccccc;
    text-align: center;
    position: relative;
    animation: fadeIn .2s ease-out;
}
.alert-box p {
    margin: 0;
    font-size: 14px;
    color: #333;
}
.alert-box.success {
    border-left-color: var(--green);
}
.alert-box.error {
    border-left-color: var(--red);
}
.alert-close {
    position: absolute;
    top: 6px;
    right: 10px;
    font-size: 18px;
    cursor: pointer;
    color: #777;
    transition: 0.2s;
}
.alert-close:hover {
    color: #000;
    transform: scale(1.1);
}

/* Responsive */
@media (max-width: 768px) {
    .main-content {
        margin-left: 0;
        padding: 15px;
    }
    .form-row {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    .modal-content {
        width: 95%;
        padding: 20px;
    }
}
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<?php if (!empty($message)): ?>
<div class="alert-overlay" id="alertOverlay">
    <div class="alert-box <?= htmlspecialchars($type) ?>">
        <span class="alert-close" onclick="document.getElementById('alertOverlay').style.display='none'">&times;</span>
        <p><?= htmlspecialchars($message) ?></p>
    </div>
</div>
<?php endif; ?>

<div class="main-content">
    <div class="page-header">
        <h1>Class Section Management</h1>
        <button class="add-btn" onclick="openModal()">
            <i class="fas fa-plus"></i> Add Section
        </button>
    </div>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Section Name</th>
                    <th>Class</th>
                    <th>Program</th>
                    <th>Department</th>
                    <th>Capacity</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Updated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if($sections): foreach($sections as $i=>$s): ?>
                <tr>
                    <td><?= $i+1 ?></td>
                    <td><strong><?= htmlspecialchars($s['section_name']) ?></strong></td>
                    <td><?= htmlspecialchars($s['class_name'] ?? 'N/A') ?></td>
                    <td>
                        <?= htmlspecialchars($s['program_name'] ?? 'N/A') ?>
                        <?php if(!empty($s['program_code'])): ?>
                            <br><small style="color:#666;">(<?= htmlspecialchars($s['program_code']) ?>)</small>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($s['department_name'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($s['capacity']) ?></td>
                    <td>
                        <span class="status-badge status-<?= strtolower($s['status']) ?>">
                            <?= htmlspecialchars($s['status']) ?>
                        </span>
                    </td>
                    <td><?= date('Y-m-d', strtotime($s['created_at'])) ?></td>
                    <td><?= date('Y-m-d', strtotime($s['updated_at'])) ?></td>
                    <td class="action-btns">
                        <button class="edit-btn" onclick='editSection(<?= json_encode($s) ?>)' title="Edit">
                            <i class="fa-solid fa-pen-to-square"></i>
                        </button>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this section?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="section_id" value="<?= $s['section_id'] ?>">
                            <button class="del-btn" type="submit" title="Delete">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="section_id" value="<?= $s['section_id'] ?>">
                            <!-- <button class="toggle-btn" type="submit" title="Toggle Status">
                                <i class="fa-solid fa-power-off"></i>
                            </button> -->
                        </form>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr>
                    <td colspan="10" style="text-align:center;color:#777;padding:30px;">
                        No class sections found. Click "Add Section" to create one.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 🔹 Add/Edit Modal -->
<div class="modal" id="sectionModal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal()">&times;</span>
        <h2 id="modalTitle">Add New Class Section</h2>
        
        <form method="POST" id="sectionForm" onsubmit="return validateForm()">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="section_id" id="section_id">
            
            <div class="form-row">
                <div>
                    <label for="section_name" class="required">Section Name</label>
                    <input type="text" name="section_name" id="section_name" required 
                           placeholder="e.g., A, B, C, 01, 02" maxlength="8"> 
                           <small style="color:#666;font-size:12px;">Only letters/numbers (max 5 chars)</small>
                </div>
                
                <div>
                    <label for="capacity">Capacity</label>
                    <input type="number" name="capacity" id="capacity" 
                           min="1" max="500" value="30"
                           placeholder="Default: 30">
                </div>
            </div>
            
            <div class="form-row">
                <div>
                    <label for="status" class="required">Status</label>
                    <select name="status" id="status" required>
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>
                
                <div>
                    <label for="campus_id" class="required">Campus</label>
                    <select name="campus_id" id="campus_id" required 
                            onchange="onCampusChange()">
                        <option value="">Select Campus</option>
                        <?php foreach($campuses as $campus): ?>
                        <option value="<?= $campus['campus_id'] ?>">
                            <?= htmlspecialchars($campus['campus_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div>
                    <label for="faculty_id" class="required">Faculty</label>
                    <select name="faculty_id" id="faculty_id" required 
                            onchange="onFacultyChange()" disabled>
                        <option value="">Select Faculty</option>
                    </select>
                </div>
                
                <div>
                    <label for="department_id" class="required">Department</label>
                    <select name="department_id" id="department_id" required 
                            onchange="onDepartmentChange()" disabled>
                        <option value="">Select Department</option>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div>
                    <label for="program_id" class="required">Program</label>
                    <select name="program_id" id="program_id" required 
                            onchange="onProgramChange()" disabled>
                        <option value="">Select Program</option>
                    </select>
                </div>
                
                <div>
                    <label for="class_id" class="required">Class</label>
                    <select name="class_id" id="class_id" required disabled>
                        <option value="">Select Class</option>
                    </select>
                </div>
            </div>
            
            <button type="submit" class="save-btn">Save Section</button>
        </form>
    </div>
</div>

<?php include('../includes/footer.php'); ?>

<script>
// Get current file name for AJAX calls
const currentFile = '<?= $current_file ?>';

function openModal() {
    const modal = document.getElementById('sectionModal');
    modal.classList.add('show');
    document.getElementById('modalTitle').innerText = "Add New Class Section";
    document.getElementById('formAction').value = "add";
    document.getElementById('sectionForm').reset();
    document.getElementById('section_id').value = "";
    document.getElementById('capacity').value = "30";
    
    // Reset dependent dropdowns
    document.getElementById('faculty_id').innerHTML = '<option value="">Select Faculty</option>';
    document.getElementById('faculty_id').disabled = true;
    document.getElementById('department_id').innerHTML = '<option value="">Select Department</option>';
    document.getElementById('department_id').disabled = true;
    document.getElementById('program_id').innerHTML = '<option value="">Select Program</option>';
    document.getElementById('program_id').disabled = true;
    document.getElementById('class_id').innerHTML = '<option value="">Select Class</option>';
    document.getElementById('class_id').disabled = true;
}

function closeModal() {
    document.getElementById('sectionModal').classList.remove('show');
}

function editSection(data) {
    const modal = document.getElementById('sectionModal');
    modal.classList.add('show');
    document.getElementById('modalTitle').innerText = "Edit Class Section";
    document.getElementById('formAction').value = "update";
    
    // Set basic values
    document.getElementById('section_id').value = data.section_id;
    document.getElementById('section_name').value = data.section_name;
    document.getElementById('capacity').value = data.capacity || 30;
    document.getElementById('status').value = data.status;
    
    // Set campus and load dependent data
    if (data.campus_id) {
        document.getElementById('campus_id').value = data.campus_id;
        loadFacultiesByCampus(data.campus_id, data.faculty_id, data.department_id, data.program_id, data.class_id);
    }
}

// Close modal when clicking outside
window.onclick = function(e) {
    const modal = document.getElementById('sectionModal');
    if (e.target === modal) closeModal();
}

// ===========================================
// AJAX FUNCTIONS - FULL HIERARCHY
// ===========================================

function onCampusChange() {
    const campusId = document.getElementById('campus_id').value;
    const facultySelect = document.getElementById('faculty_id');
    const deptSelect = document.getElementById('department_id');
    const programSelect = document.getElementById('program_id');
    const classSelect = document.getElementById('class_id');
    
    if (!campusId) {
        facultySelect.innerHTML = '<option value="">Select Faculty</option>';
        facultySelect.disabled = true;
        deptSelect.innerHTML = '<option value="">Select Department</option>';
        deptSelect.disabled = true;
        programSelect.innerHTML = '<option value="">Select Program</option>';
        programSelect.disabled = true;
        classSelect.innerHTML = '<option value="">Select Class</option>';
        classSelect.disabled = true;
        return;
    }
    
    facultySelect.innerHTML = '<option value="">Loading...</option>';
    facultySelect.disabled = true;
    deptSelect.innerHTML = '<option value="">Select Department</option>';
    deptSelect.disabled = true;
    programSelect.innerHTML = '<option value="">Select Program</option>';
    programSelect.disabled = true;
    classSelect.innerHTML = '<option value="">Select Class</option>';
    classSelect.disabled = true;
    
    // FIXED: Use current file name instead of hardcoded "classes.php"
    fetch(`${currentFile}?ajax=get_faculties&campus_id=${campusId}`)
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
            }
        })
        .catch(error => {
            console.error('Error loading faculties:', error);
            facultySelect.innerHTML = '<option value="">Error loading</option>';
            facultySelect.disabled = false;
        });
}

function onFacultyChange() {
    const facultyId = document.getElementById('faculty_id').value;
    const campusId = document.getElementById('campus_id').value;
    const deptSelect = document.getElementById('department_id');
    const programSelect = document.getElementById('program_id');
    const classSelect = document.getElementById('class_id');
    
    if (!facultyId || !campusId) {
        deptSelect.innerHTML = '<option value="">Select Department</option>';
        deptSelect.disabled = true;
        programSelect.innerHTML = '<option value="">Select Program</option>';
        programSelect.disabled = true;
        classSelect.innerHTML = '<option value="">Select Class</option>';
        classSelect.disabled = true;
        return;
    }
    
    deptSelect.innerHTML = '<option value="">Loading...</option>';
    deptSelect.disabled = true;
    programSelect.innerHTML = '<option value="">Select Program</option>';
    programSelect.disabled = true;
    classSelect.innerHTML = '<option value="">Select Class</option>';
    classSelect.disabled = true;
    
    // FIXED: Use current file name instead of hardcoded "classes.php"
    fetch(`${currentFile}?ajax=get_departments&faculty_id=${facultyId}&campus_id=${campusId}`)
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
            }
        })
        .catch(error => {
            console.error('Error loading departments:', error);
            deptSelect.innerHTML = '<option value="">Error loading</option>';
            deptSelect.disabled = false;
        });
}

function onDepartmentChange() {
    const deptId = document.getElementById('department_id').value;
    const facultyId = document.getElementById('faculty_id').value;
    const campusId = document.getElementById('campus_id').value;
    const programSelect = document.getElementById('program_id');
    const classSelect = document.getElementById('class_id');
    
    if (!deptId || !facultyId || !campusId) {
        programSelect.innerHTML = '<option value="">Select Program</option>';
        programSelect.disabled = true;
        classSelect.innerHTML = '<option value="">Select Class</option>';
        classSelect.disabled = true;
        return;
    }
    
    programSelect.innerHTML = '<option value="">Loading...</option>';
    programSelect.disabled = true;
    classSelect.innerHTML = '<option value="">Select Class</option>';
    classSelect.disabled = true;
    
    // FIXED: Use current file name instead of hardcoded "classes.php"
    fetch(`${currentFile}?ajax=get_programs&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            programSelect.innerHTML = '<option value="">Select Program</option>';
            
            if (data.status === 'success' && data.programs.length > 0) {
                data.programs.forEach(program => {
                    const option = document.createElement('option');
                    option.value = program.program_id;
                    option.textContent = `${program.program_name} (${program.program_code})`;
                    programSelect.appendChild(option);
                });
                programSelect.disabled = false;
            } else {
                programSelect.innerHTML = '<option value="">No programs found</option>';
            }
        })
        .catch(error => {
            console.error('Error loading programs:', error);
            programSelect.innerHTML = '<option value="">Error loading</option>';
            programSelect.disabled = false;
        });
}

function onProgramChange() {
    const programId = document.getElementById('program_id').value;
    const deptId = document.getElementById('department_id').value;
    const facultyId = document.getElementById('faculty_id').value;
    const campusId = document.getElementById('campus_id').value;
    const classSelect = document.getElementById('class_id');
    
    if (!programId || !deptId || !facultyId || !campusId) {
        classSelect.innerHTML = '<option value="">Select Class</option>';
        classSelect.disabled = true;
        return;
    }
    
    classSelect.innerHTML = '<option value="">Loading...</option>';
    classSelect.disabled = true;
    
    // FIXED: Use current file name instead of hardcoded "classes.php"
    fetch(`${currentFile}?ajax=get_classes&program_id=${programId}&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
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
            }
        })
        .catch(error => {
            console.error('Error loading classes:', error);
            classSelect.innerHTML = '<option value="">Error loading</option>';
            classSelect.disabled = false;
        });
}

function loadFacultiesByCampus(campusId, selectedFacultyId, selectedDeptId, selectedProgramId, selectedClassId) {
    const facultySelect = document.getElementById('faculty_id');
    
    if (!campusId) {
        facultySelect.innerHTML = '<option value="">Select Faculty</option>';
        facultySelect.disabled = true;
        return;
    }
    
    facultySelect.innerHTML = '<option value="">Loading...</option>';
    facultySelect.disabled = true;
    
    // FIXED: Use current file name instead of hardcoded "classes.php"
    fetch(`${currentFile}?ajax=get_faculties&campus_id=${campusId}`)
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
                
                // Set selected faculty if provided
                if (selectedFacultyId) {
                    facultySelect.value = selectedFacultyId;
                    // Load departments for this faculty
                    loadDepartmentsByFaculty(selectedFacultyId, campusId, selectedDeptId, selectedProgramId, selectedClassId);
                }
            } else {
                facultySelect.innerHTML = '<option value="">No faculties found</option>';
            }
        })
        .catch(error => {
            console.error('Error loading faculties:', error);
            facultySelect.innerHTML = '<option value="">Error loading</option>';
            facultySelect.disabled = false;
        });
}

function loadDepartmentsByFaculty(facultyId, campusId, selectedDeptId, selectedProgramId, selectedClassId) {
    const deptSelect = document.getElementById('department_id');
    
    if (!facultyId || !campusId) {
        deptSelect.innerHTML = '<option value="">Select Department</option>';
        deptSelect.disabled = true;
        return;
    }
    
    deptSelect.innerHTML = '<option value="">Loading...</option>';
    deptSelect.disabled = true;
    
    // FIXED: Use current file name instead of hardcoded "classes.php"
    fetch(`${currentFile}?ajax=get_departments&faculty_id=${facultyId}&campus_id=${campusId}`)
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
                
                // Set selected department if provided
                if (selectedDeptId) {
                    deptSelect.value = selectedDeptId;
                    // Load programs for this department
                    loadProgramsByDepartment(selectedDeptId, facultyId, campusId, selectedProgramId, selectedClassId);
                }
            } else {
                deptSelect.innerHTML = '<option value="">No departments found</option>';
            }
        })
        .catch(error => {
            console.error('Error loading departments:', error);
            deptSelect.innerHTML = '<option value="">Error loading</option>';
            deptSelect.disabled = false;
        });
}

function loadProgramsByDepartment(deptId, facultyId, campusId, selectedProgramId, selectedClassId) {
    const programSelect = document.getElementById('program_id');
    
    if (!deptId || !facultyId || !campusId) {
        programSelect.innerHTML = '<option value="">Select Program</option>';
        programSelect.disabled = true;
        return;
    }
    
    programSelect.innerHTML = '<option value="">Loading...</option>';
    programSelect.disabled = true;
    
    // FIXED: Use current file name instead of hardcoded "classes.php"
    fetch(`${currentFile}?ajax=get_programs&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            programSelect.innerHTML = '<option value="">Select Program</option>';
            
            if (data.status === 'success' && data.programs.length > 0) {
                data.programs.forEach(program => {
                    const option = document.createElement('option');
                    option.value = program.program_id;
                    option.textContent = `${program.program_name} (${program.program_code})`;
                    programSelect.appendChild(option);
                });
                programSelect.disabled = false;
                
                // Set selected program if provided
                if (selectedProgramId) {
                    programSelect.value = selectedProgramId;
                    // Load classes for this program
                    loadClassesByProgram(selectedProgramId, deptId, facultyId, campusId, selectedClassId);
                }
            } else {
                programSelect.innerHTML = '<option value="">No programs found</option>';
            }
        })
        .catch(error => {
            console.error('Error loading programs:', error);
            programSelect.innerHTML = '<option value="">Error loading</option>';
            programSelect.disabled = false;
        });
}

function loadClassesByProgram(programId, deptId, facultyId, campusId, selectedClassId) {
    const classSelect = document.getElementById('class_id');
    
    if (!programId || !deptId || !facultyId || !campusId) {
        classSelect.innerHTML = '<option value="">Select Class</option>';
        classSelect.disabled = true;
        return;
    }
    
    classSelect.innerHTML = '<option value="">Loading...</option>';
    classSelect.disabled = true;
    
    // FIXED: Use current file name instead of hardcoded "classes.php"
    fetch(`${currentFile}?ajax=get_classes&program_id=${programId}&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
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
                
                // Set selected class if provided
                if (selectedClassId) {
                    classSelect.value = selectedClassId;
                }
            } else {
                classSelect.innerHTML = '<option value="">No classes found</option>';
            }
        })
        .catch(error => {
            console.error('Error loading classes:', error);
            classSelect.innerHTML = '<option value="">Error loading</option>';
            classSelect.disabled = false;
        });
}

// Form validation
function validateForm() {
    const classId = document.getElementById('class_id').value;
    const sectionName = document.getElementById('section_name').value;
    
    if (!classId) {
        alert("Please select a class. Class selection is required!");
        document.getElementById('class_id').focus();
        return false;
    }
    
    // if (!/^[A-Z0-9]{1,10}$/i.test(sectionName)) {
    //     alert("Section name should be 1-10 alphanumeric characters (letters/numbers only)!");
    //     document.getElementById('section_name').focus();
    //     return false;
    // }
    
    return true;
}

// Auto-hide alert after 5 seconds
if (document.getElementById('alertOverlay')) {
    setTimeout(() => {
        const alert = document.getElementById('alertOverlay');
        if (alert) alert.style.display = 'none';
    }, 5000);
}
</script>
</body>
</html>
<?php ob_end_flush(); ?>