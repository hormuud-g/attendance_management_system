<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Access Control - Allow both faculty_admin and department_admin
$user_role = strtolower($_SESSION['user']['role'] ?? '');
if (!in_array($user_role, ['faculty_admin', 'department_admin'])) {
    header("Location: ../login.php");
    exit;
}

// ✅ Get linked_id based on role
$linked_id = $_SESSION['user']['linked_id'];
$faculty_id = null;
$department_id = null;

if ($user_role === 'faculty_admin') {
    $faculty_id = $linked_id;
} elseif ($user_role === 'department_admin') {
    $department_id = $linked_id;
    
    // Get faculty_id from department
    $stmt = $pdo->prepare("SELECT faculty_id FROM departments WHERE department_id = ?");
    $stmt->execute([$department_id]);
    $dept_info = $stmt->fetch(PDO::FETCH_ASSOC);
    $faculty_id = $dept_info['faculty_id'] ?? null;
}

$message = "";
$type = "";

/* ===========================================
   CRUD OPERATIONS
=========================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($_POST['action'] === 'add' && $user_role === 'faculty_admin') {
            // Only faculty_admin can add programs
            // Validate required fields
            $required_fields = ['program_name', 'program_code', 'department_id', 'duration_years', 'status'];
            foreach ($required_fields as $field) {
                if (empty(trim($_POST[$field] ?? ''))) {
                    throw new Exception("All required fields must be filled!");
                }
            }

            // Sanitize inputs
            $program_name = trim($_POST['program_name']);
            $program_code = trim($_POST['program_code']);
            $department_id_input = (int)$_POST['department_id'];
            $duration_years = (int)$_POST['duration_years'];
            $description = trim($_POST['description'] ?? '');
            $status = $_POST['status'];

            // Validate program name length
            if (strlen($program_name) < 2 || strlen($program_name) > 255) {
                throw new Exception("Program name must be between 2 and 255 characters!");
            }

            // Validate program code format
            if (!preg_match('/^[A-Z0-9]{2,20}$/', $program_code)) {
                throw new Exception("Program code must be 2-20 characters containing only uppercase letters and numbers!");
            }

            // Validate duration
            if ($duration_years < 1 || $duration_years > 10) {
                throw new Exception("Duration must be between 1 and 10 years!");
            }

            // Validate status
            if (!in_array($status, ['active', 'inactive'])) {
                throw new Exception("Invalid status value!");
            }

            // Check if department belongs to faculty
            $checkDept = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE department_id = ? AND faculty_id = ?");
            $checkDept->execute([$department_id_input, $faculty_id]);
            if ($checkDept->fetchColumn() == 0) {
                throw new Exception("Invalid department selected!");
            }

            // Check duplicate program name in same faculty
            $checkName = $pdo->prepare("SELECT COUNT(*) FROM programs WHERE program_name = ? AND faculty_id = ?");
            $checkName->execute([$program_name, $faculty_id]);
            if ($checkName->fetchColumn() > 0) {
                throw new Exception("Program name already exists in this faculty!");
            }

            // Check duplicate program code in same faculty
            $checkCode = $pdo->prepare("SELECT COUNT(*) FROM programs WHERE program_code = ? AND faculty_id = ?");
            $checkCode->execute([$program_code, $faculty_id]);
            if ($checkCode->fetchColumn() > 0) {
                throw new Exception("Program code already exists in this faculty!");
            }

            $stmt = $pdo->prepare("INSERT INTO programs 
                (faculty_id, department_id, program_name, program_code, duration_years, description, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([
                $faculty_id,
                $department_id_input,
                $program_name,
                $program_code,
                $duration_years,
                $description,
                $status
            ]);
            $message = "✅ Program added successfully!";
            $type = "success";
        }

        if ($_POST['action'] === 'update') {
            // Both roles can update, but with restrictions
            $required_fields = ['program_id', 'program_name', 'program_code', 'duration_years', 'status'];
            foreach ($required_fields as $field) {
                if (empty(trim($_POST[$field] ?? ''))) {
                    throw new Exception("All required fields must be filled!");
                }
            }

            // Sanitize inputs
            $program_id = (int)$_POST['program_id'];
            $program_name = trim($_POST['program_name']);
            $program_code = trim($_POST['program_code']);
            $duration_years = (int)$_POST['duration_years'];
            $description = trim($_POST['description'] ?? '');
            $status = $_POST['status'];

            // Validate program name length
            if (strlen($program_name) < 2 || strlen($program_name) > 255) {
                throw new Exception("Program name must be between 2 and 255 characters!");
            }

            // Validate program code format
            if (!preg_match('/^[A-Z0-9]{2,20}$/', $program_code)) {
                throw new Exception("Program code must be 2-20 characters containing only uppercase letters and numbers!");
            }

            // Validate duration
            if ($duration_years < 1 || $duration_years > 10) {
                throw new Exception("Duration must be between 1 and 10 years!");
            }

            // Validate status
            if (!in_array($status, ['active', 'inactive'])) {
                throw new Exception("Invalid status value!");
            }

            // Build query based on role
            if ($user_role === 'faculty_admin') {
                $department_id_input = (int)$_POST['department_id'];
                
                // Check if program exists and belongs to faculty
                $checkProgram = $pdo->prepare("SELECT COUNT(*) FROM programs WHERE program_id = ? AND faculty_id = ?");
                $checkProgram->execute([$program_id, $faculty_id]);
                if ($checkProgram->fetchColumn() == 0) {
                    throw new Exception("Program not found or access denied!");
                }

                // Check if department belongs to faculty
                $checkDept = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE department_id = ? AND faculty_id = ?");
                $checkDept->execute([$department_id_input, $faculty_id]);
                if ($checkDept->fetchColumn() == 0) {
                    throw new Exception("Invalid department selected!");
                }

                // Check duplicate program name (excluding current program)
                $checkName = $pdo->prepare("SELECT COUNT(*) FROM programs WHERE program_name = ? AND faculty_id = ? AND program_id != ?");
                $checkName->execute([$program_name, $faculty_id, $program_id]);
                if ($checkName->fetchColumn() > 0) {
                    throw new Exception("Program name already exists in this faculty!");
                }

                // Check duplicate program code (excluding current program)
                $checkCode = $pdo->prepare("SELECT COUNT(*) FROM programs WHERE program_code = ? AND faculty_id = ? AND program_id != ?");
                $checkCode->execute([$program_code, $faculty_id, $program_id]);
                if ($checkCode->fetchColumn() > 0) {
                    throw new Exception("Program code already exists in this faculty!");
                }

                $stmt = $pdo->prepare("UPDATE programs 
                    SET department_id=?, program_name=?, program_code=?, duration_years=?, description=?, status=?, updated_at=NOW()
                    WHERE program_id=? AND faculty_id=?");
                $params = [
                    $department_id_input,
                    $program_name,
                    $program_code,
                    $duration_years,
                    $description,
                    $status,
                    $program_id,
                    $faculty_id
                ];
            } else { // department_admin
                // Check if program exists and belongs to department
                $checkProgram = $pdo->prepare("SELECT COUNT(*) FROM programs WHERE program_id = ? AND department_id = ?");
                $checkProgram->execute([$program_id, $department_id]);
                if ($checkProgram->fetchColumn() == 0) {
                    throw new Exception("Program not found or access denied!");
                }

                // Check duplicate program name (excluding current program, within same department)
                $checkName = $pdo->prepare("SELECT COUNT(*) FROM programs WHERE program_name = ? AND department_id = ? AND program_id != ?");
                $checkName->execute([$program_name, $department_id, $program_id]);
                if ($checkName->fetchColumn() > 0) {
                    throw new Exception("Program name already exists in your department!");
                }

                // Check duplicate program code (excluding current program, within same department)
                $checkCode = $pdo->prepare("SELECT COUNT(*) FROM programs WHERE program_code = ? AND department_id = ? AND program_id != ?");
                $checkCode->execute([$program_code, $department_id, $program_id]);
                if ($checkCode->fetchColumn() > 0) {
                    throw new Exception("Program code already exists in your department!");
                }

                $stmt = $pdo->prepare("UPDATE programs 
                    SET program_name=?, program_code=?, duration_years=?, description=?, status=?, updated_at=NOW()
                    WHERE program_id=? AND department_id=?");
                $params = [
                    $program_name,
                    $program_code,
                    $duration_years,
                    $description,
                    $status,
                    $program_id,
                    $department_id
                ];
            }

            $stmt->execute($params);

            if ($stmt->rowCount() === 0) {
                throw new Exception("No changes made or program not found!");
            }

            $message = "✅ Program updated successfully!";
            $type = "success";
        }

        if ($_POST['action'] === 'delete' && $user_role === 'faculty_admin') {
            // Only faculty_admin can delete programs
            $program_id = (int)$_POST['program_id'];

            // Check if program exists and belongs to faculty
            $checkProgram = $pdo->prepare("SELECT COUNT(*) FROM programs WHERE program_id = ? AND faculty_id = ?");
            $checkProgram->execute([$program_id, $faculty_id]);
            if ($checkProgram->fetchColumn() == 0) {
                throw new Exception("Program not found or access denied!");
            }

            // Check if program has any students enrolled (optional safety check)
            $checkStudents = $pdo->prepare("SELECT COUNT(*) FROM students WHERE program_id = ?");
            $checkStudents->execute([$program_id]);
            if ($checkStudents->fetchColumn() > 0) {
                throw new Exception("Cannot delete program with enrolled students!");
            }

            $stmt = $pdo->prepare("DELETE FROM programs WHERE program_id=? AND faculty_id=?");
            $stmt->execute([$program_id, $faculty_id]);

            if ($stmt->rowCount() === 0) {
                throw new Exception("Failed to delete program!");
            }

            $message = "✅ Program deleted successfully!";
            $type = "success";
        }

    } catch (PDOException $e) {
        $message = "❌ Database Error: " . $e->getMessage();
        $type = "error";
    } catch (Exception $e) {
        $message = "❌ " . $e->getMessage();
        $type = "error";
    }
}

/* ===========================================
   FETCH DATA
=========================================== */
// ✅ Get current faculty info
if ($faculty_id) {
    $current_faculty = $pdo->prepare("SELECT faculty_name FROM faculties WHERE faculty_id = ?");
    $current_faculty->execute([$faculty_id]);
    $faculty_data = $current_faculty->fetch(PDO::FETCH_ASSOC);
} else {
    $faculty_data = null;
}

// ✅ Get department info for department_admin
$department_info = [];
if ($user_role === 'department_admin' && $department_id) {
    $stmt = $pdo->prepare("SELECT department_name FROM departments WHERE department_id = ?");
    $stmt->execute([$department_id]);
    $department_info = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ✅ Programs based on role
if ($user_role === 'faculty_admin') {
    // Faculty admin sees all programs in their faculty
    $stmt = $pdo->prepare("SELECT p.*, f.faculty_name, d.department_name 
        FROM programs p
        LEFT JOIN faculties f ON p.faculty_id = f.faculty_id
        LEFT JOIN departments d ON p.department_id = d.department_id
        WHERE p.faculty_id = ?
        ORDER BY p.program_name ASC");
    $stmt->execute([$faculty_id]);
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Department admin only sees programs in their department
    $stmt = $pdo->prepare("SELECT p.*, f.faculty_name, d.department_name 
        FROM programs p
        LEFT JOIN faculties f ON p.faculty_id = f.faculty_id
        LEFT JOIN departments d ON p.department_id = d.department_id
        WHERE p.department_id = ?
        ORDER BY p.program_name ASC");
    $stmt->execute([$department_id]);
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ✅ Departments under this faculty (for faculty_admin only)
$departments = [];
if ($user_role === 'faculty_admin' && $faculty_id) {
    $stmt = $pdo->prepare("SELECT * FROM departments WHERE faculty_id=? AND status='active' ORDER BY department_name ASC");
    $stmt->execute([$faculty_id]);
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Programs Management | Hormuud University</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="../images.png">
<link rel="stylesheet" href="../assets/css/header.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
:root {
    --green:#00843D;
    --light-green:#00A651;
    --blue:#0072CE;
    --red:#C62828;
    --bg:#F5F9F7;
}
body {
    font-family:'Poppins',sans-serif;
    background:var(--bg);
    margin:0;
    color:#333;
}
.main-content {
    padding:20px;
    margin-top:90px;
    margin-left:250px;
    transition:all .3s;
}
.sidebar.collapsed ~ .main-content { margin-left:70px; }

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}
.page-header h1 {
    color:var(--blue);
    font-size:24px;
    font-weight:700;
    margin:0;
}
.add-btn {
    background:var(--green);
    color:#fff;
    border:none;
    padding:10px 18px;
    border-radius:6px;
    font-weight:600;
    cursor:pointer;
    transition: background 0.3s;
}
.add-btn:hover { background:var(--light-green); }
.add-btn:disabled {
    background:#ccc;
    cursor:not-allowed;
}

.info-banner {
    background: var(--blue);
    color: white;
    padding: 12px 15px;
    border-radius: 6px;
    margin-bottom: 20px;
    font-weight: 600;
}
.department-info {
    background:#e8f5e9;
    padding:12px 16px;
    border-radius:8px;
    margin-bottom:20px;
    border-left:4px solid var(--green);
    font-weight:600;
}
.view-only-notice {
    background:#fff3cd;
    border:1px solid #ffeaa7;
    padding:15px;
    border-radius:8px;
    margin-bottom:20px;
    text-align:center;
}
.view-only-notice i {
    color:#f39c12;
    font-size:20px;
    margin-right:10px;
}

.table-wrapper {
    overflow-x:auto;
    background:#fff;
    border-radius:10px;
    box-shadow:0 4px 12px rgba(0,0,0,0.08);
    margin-top: 15px;
}
table { 
    width:100%; 
    border-collapse:collapse;
    min-width: 800px;
}
thead th {
    position:sticky; 
    top:0;
    background:var(--blue); 
    color:#fff;
    padding: 14px 16px;
    font-weight: 600;
}
th,td { 
    padding:12px 14px; 
    border-bottom:1px solid #eee; 
    text-align:left; 
}
tr:hover { background:#eef8f0; }

.action-buttons { 
    display:flex; 
    gap:8px; 
    justify-content:center; 
}
.btn-edit, .btn-delete {
    border:none; 
    border-radius:6px; 
    padding:8px 12px; 
    color:#fff; 
    cursor:pointer;
    transition: background 0.3s;
}
.btn-edit { background:var(--blue); }
.btn-delete { background:var(--red); }
.btn-edit:hover { background:#2196f3; }
.btn-delete:hover { background:#e53935; }
.btn-delete:disabled {
    background:#ccc;
    cursor:not-allowed;
}

.status-badge {
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}
.status-active { background: #e8f5e8; color: #2e7d32; }
.status-inactive { background: #ffebee; color: #c62828; }

.modal {
    display:none; 
    position:fixed; 
    inset:0; 
    background:rgba(0,0,0,.45);
    justify-content:center; 
    align-items:center; 
    z-index:3000; 
    padding:10px;
}
.modal.show { display:flex; }
.modal-content {
    background:#fff; 
    border-radius:10px; 
    width:95%; 
    max-width:650px;
    padding:25px; 
    position:relative; 
    border-top:5px solid var(--blue);
    max-height: 90vh;
    overflow-y: auto;
}
.close-modal {
    position:absolute; 
    top:10px; 
    right:15px; 
    font-size:22px;
    color:var(--red); 
    cursor:pointer;
    background: none;
    border: none;
}
form { 
    display:grid; 
    grid-template-columns:repeat(2,1fr); 
    gap:14px 18px; 
}
label { 
    font-weight:600; 
    color:var(--blue); 
    font-size:13px; 
    margin-bottom:5px; 
    display:block; 
}
input, select, textarea {
    width:100%; 
    padding:10px 12px; 
    border:1.5px solid #ddd; 
    border-radius:6px;
    background:#fff; 
    transition:.2s; 
    font-size:14px;
    font-family: inherit;
}
input:focus, select:focus, textarea:focus {
    border-color: var(--blue);
    outline: none;
    box-shadow: 0 0 0 2px rgba(0, 114, 206, 0.1);
}
textarea { 
    resize:vertical; 
    min-height:80px; 
}
.save-btn {
    grid-column:span 2; 
    background:var(--green); 
    color:#fff; 
    border:none;
    padding:12px; 
    border-radius:6px; 
    font-weight:600; 
    cursor:pointer;
    font-size: 16px;
    transition: background 0.3s;
}
.save-btn:hover { background:var(--light-green); }
.save-btn.delete { background: var(--red); }
.save-btn.delete:hover { background: #e53935; }

.alert-popup {
    display:none; 
    position:fixed; 
    top:20px; 
    right:20px; 
    background:#fff;
    padding:16px 24px; 
    border-radius:10px; 
    box-shadow:0 4px 12px rgba(0,0,0,0.15);
    z-index:4000;
    border-left: 5px solid var(--green);
    max-width: 400px;
}
.alert-popup.show { display:block; animation:slideIn 0.5s ease; }
.alert-popup.success { border-left-color: var(--green); }
.alert-popup.error { border-left-color: var(--red); }
@keyframes slideIn { 
    from { opacity:0; transform:translateX(100px); } 
    to { opacity:1; transform:translateX(0); } 
}

.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: #666;
}
.empty-state i {
    font-size: 48px;
    color: #ccc;
    margin-bottom: 15px;
}

.form-group {
    grid-column: span 2;
}
.form-group label {
    display: block;
    margin-bottom: 8px;
}

@media (max-width: 768px) {
    .main-content {
        margin-left: 0;
        padding: 15px;
    }
    form {
        grid-template-columns: 1fr;
    }
    .form-group {
        grid-column: span 1;
    }
}
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
    <!-- Department/Faculty Info -->
    <?php if ($user_role === 'department_admin' && !empty($department_info)): ?>
    <!-- <div class="department-info">
        <i class="fas fa-building"></i> 
        Department: <strong><?= htmlspecialchars($department_info['department_name']) ?></strong>
        <?php if (!empty($faculty_data)): ?>
            | Faculty: <strong><?= htmlspecialchars($faculty_data['faculty_name']) ?></strong>
        <?php endif; ?>
    </div> -->
    <?php elseif ($user_role === 'faculty_admin' && !empty($faculty_data)): ?>
    <div class="info-banner">
        <i class="fas fa-university"></i> 
        Faculty: <strong><?= htmlspecialchars($faculty_data['faculty_name']) ?></strong>
    </div>
    <?php endif; ?>

    <!-- View Only Notice for Department Admin -->
    <?php if ($user_role === 'department_admin'): ?>
    <!-- <div class="view-only-notice">
        <i class="fas fa-eye"></i>
        <strong>Limited Access:</strong> You can view and edit programs in your department, but cannot create new programs or delete existing ones.
    </div> -->
    <?php endif; ?>
    
    <div class="page-header">
        <h1>Programs Management
            <!-- <?php if ($user_role === 'department_admin' && !empty($department_info)): ?>
                <small style="font-size: 16px; color: #666;">- <?= htmlspecialchars($department_info['department_name']) ?></small>
            <?php endif; ?> -->
        </h1>
        <button class="add-btn" onclick="openModal('addModal')" <?= $user_role === 'department_admin' ? 'disabled' : '' ?>>
            <i class="fas fa-plus"></i> Add Program
        </button>
    </div>

    <div class="table-wrapper">
        <?php if (empty($programs)): ?>
        <div class="empty-state">
            <i class="fas fa-graduation-cap"></i>
            <h3>No Programs Found</h3>
            <p>
                <?php if ($user_role === 'department_admin'): ?>
                    No programs found in your department.
                <?php else: ?>
                    Get started by adding your first program to this faculty.
                <?php endif; ?>
            </p>
        </div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Program Name</th>
                    <th>Code</th>
                    <?php if ($user_role === 'faculty_admin'): ?>
                        <th>Department</th>
                    <?php endif; ?>
                    <th>Duration</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($programs as $i => $p): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td>
                        <strong><?= htmlspecialchars($p['program_name']) ?></strong>
                        <?php if (!empty($p['description'])): ?>
                        <br><small style="color: #666;"><?= htmlspecialchars(substr($p['description'], 0, 50)) ?><?= strlen($p['description']) > 50 ? '...' : '' ?></small>
                        <?php endif; ?>
                    </td>
                    <td><code><?= htmlspecialchars($p['program_code']) ?></code></td>
                    <?php if ($user_role === 'faculty_admin'): ?>
                        <td><?= htmlspecialchars($p['department_name'] ?? 'N/A') ?></td>
                    <?php endif; ?>
                    <td><?= htmlspecialchars($p['duration_years']) ?> year<?= $p['duration_years'] != 1 ? 's' : '' ?></td>
                    <td>
                        <span class="status-badge status-<?= $p['status'] ?>">
                            <?= ucfirst($p['status']) ?>
                        </span>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <button class="btn-edit" onclick="openEditModal(
                                <?= $p['program_id'] ?>,
                                '<?= htmlspecialchars($p['program_name'], ENT_QUOTES) ?>',
                                '<?= htmlspecialchars($p['program_code'], ENT_QUOTES) ?>',
                                '<?= $p['department_id'] ?>',
                                <?= (int)$p['duration_years'] ?>,
                                `<?= str_replace('`', '\`', $p['description']) ?>`,
                                '<?= $p['status'] ?>'
                            )">
                                <i class="fa-solid fa-pen-to-square"></i>
                            </button>
                            <button class="btn-delete" onclick="openDeleteModal(<?= $p['program_id'] ?>)" 
                                <?= $user_role === 'department_admin' ? 'disabled' : '' ?>>
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- 🔹 Add Modal - Only for faculty_admin -->
<?php if ($user_role === 'faculty_admin'): ?>
<div class="modal" id="addModal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('addModal')">&times;</span>
        <h2 style="text-align:center;color:var(--blue);margin-bottom:20px;">
             Add New Program
        </h2>

        <form method="POST" id="addForm">
            <input type="hidden" name="action" value="add">

            <div class="form-group">
                <label for="add_program_name">Program Name *</label>
                <input type="text" id="add_program_name" name="program_name" required 
                       maxlength="255" placeholder="Enter program name">
                <small style="color: #666;">Minimum 2 characters</small>
            </div>

            <div>
                <label for="add_program_code">Program Code *</label>
                <input type="text" id="add_program_code" name="program_code" required 
                       maxlength="20" placeholder="e.g., CS001" style="text-transform:uppercase">
                <small style="color: #666;">Uppercase letters and numbers only</small>
            </div>

            <div>
                <label for="add_department">Department *</label>
                <select id="add_department" name="department_id" required>
                    <option value="">Select Department</option>
                    <?php foreach($departments as $d): ?>
                        <option value="<?= $d['department_id'] ?>">
                            <?= htmlspecialchars($d['department_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="add_duration">Duration (Years) *</label>
                <input type="number" id="add_duration" name="duration_years" value="4" 
                       min="1" max="10" required>
            </div>

            <div>
                <label for="add_status">Status *</label>
                <select id="add_status" name="status" required>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <div class="form-group">
                <label for="add_description">Description</label>
                <textarea id="add_description" name="description" 
                          placeholder="Optional program description..." maxlength="500"></textarea>
                <small style="color: #666;">Maximum 500 characters</small>
            </div>

            <button class="save-btn" type="submit">
                <i class="fas fa-save"></i> Save Program
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- 🔹 Edit Modal -->
<div class="modal" id="editModal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('editModal')">&times;</span>
        <h2 style="text-align:center;color:var(--blue);margin-bottom:20px;">
            <i class="fas fa-edit"></i> Edit Program
        </h2>

        <form method="POST" id="editForm">
            <input type="hidden" name="action" value="update">
            <input type="hidden" id="edit_id" name="program_id">

            <div class="form-group">
                <label for="edit_name">Program Name *</label>
                <input type="text" id="edit_name" name="program_name" required 
                       maxlength="255" placeholder="Enter program name">
                <small style="color: #666;">Minimum 2 characters</small>
            </div>

            <div>
                <label for="edit_code">Program Code *</label>
                <input type="text" id="edit_code" name="program_code" required 
                       maxlength="20" placeholder="e.g., CS001" style="text-transform:uppercase">
                <small style="color: #666;">Uppercase letters and numbers only</small>
            </div>

            <?php if ($user_role === 'faculty_admin'): ?>
            <div>
                <label for="edit_department">Department *</label>
                <select id="edit_department" name="department_id" required>
                    <option value="">Select Department</option>
                    <?php foreach($departments as $d): ?>
                        <option value="<?= $d['department_id'] ?>">
                            <?= htmlspecialchars($d['department_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
                <input type="hidden" name="department_id" value="<?= $department_id ?>">
            <?php endif; ?>

            <div>
                <label for="edit_duration">Duration (Years) *</label>
                <input type="number" id="edit_duration" name="duration_years" 
                       min="1" max="10" required>
            </div>

            <div>
                <label for="edit_status">Status *</label>
                <select id="edit_status" name="status" required>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <div class="form-group">
                <label for="edit_desc">Description</label>
                <textarea id="edit_desc" name="description" 
                          placeholder="Optional program description..." maxlength="500"></textarea>
                <small style="color: #666;">Maximum 500 characters</small>
            </div>

            <button class="save-btn" type="submit">
                <i class="fas fa-save"></i> Update Program
            </button>
        </form>
    </div>
</div>

<!-- 🔹 Delete Modal - Only for faculty_admin -->
<?php if ($user_role === 'faculty_admin'): ?>
<div class="modal" id="deleteModal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('deleteModal')">&times;</span>
        <h2 style="color:#C62828;text-align:center;margin-bottom:20px;">
            <i class="fas fa-exclamation-triangle"></i> Confirm Deletion
        </h2>
        <form method="POST" id="deleteForm">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" id="delete_id" name="program_id">
            
            <div style="text-align:center;margin-bottom:25px;">
                <i class="fas fa-trash-alt" style="font-size:48px;color:#C62828;margin-bottom:15px;"></i>
                <p style="font-size:16px;line-height:1.5;">
                    Are you sure you want to delete this program?<br>
                    <strong style="color:#C62828;">This action cannot be undone!</strong>
                </p>
            </div>
            
            <div style="display:flex;gap:10px;grid-column:span2;">
                <button type="button" onclick="closeModal('deleteModal')" 
                        style="flex:1;padding:12px;border:1px solid #ddd;border-radius:6px;background:#f5f5f5;cursor:pointer;">
                    Cancel
                </button>
                <button class="save-btn delete" type="submit" style="flex:1;">
                    <i class="fas fa-trash"></i> Delete Program
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ✅ Alert Popup -->
<div id="popup" class="alert-popup <?= $type ?>">
    <div style="display:flex;align-items:center;gap:10px;">
        <i class="fas <?= $type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>" 
           style="color:<?= $type === 'success' ? 'var(--green)' : 'var(--red)' ?>;font-size:18px;"></i>
        <div>
            <strong><?= $message ?></strong>
        </div>
    </div>
</div>

<script>
// Modal functions
function openModal(id) {
    document.getElementById(id).classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeModal(id) {
    document.getElementById(id).classList.remove('show');
    document.body.style.overflow = 'auto';
}

function openEditModal(id, name, code, department, duration, desc, status) {
    openModal('editModal');
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_code').value = code;
    <?php if ($user_role === 'faculty_admin'): ?>
    document.getElementById('edit_department').value = department;
    <?php endif; ?>
    document.getElementById('edit_duration').value = duration;
    document.getElementById('edit_desc').value = desc;
    document.getElementById('edit_status').value = status;
}

function openDeleteModal(id) {
    openModal('deleteModal');
    document.getElementById('delete_id').value = id;
}

// Form validation
document.getElementById('add_program_code')?.addEventListener('input', function(e) {
    this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
});

document.getElementById('edit_code').addEventListener('input', function(e) {
    this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
});

// Client-side validation
document.getElementById('addForm')?.addEventListener('submit', function(e) {
    const name = document.getElementById('add_program_name').value.trim();
    const code = document.getElementById('add_program_code').value.trim();
    
    if (name.length < 2) {
        e.preventDefault();
        alert('Program name must be at least 2 characters long!');
        return false;
    }
    
    if (!/^[A-Z0-9]{2,20}$/.test(code)) {
        e.preventDefault();
        alert('Program code must be 2-20 characters containing only uppercase letters and numbers!');
        return false;
    }
});

document.getElementById('editForm').addEventListener('submit', function(e) {
    const name = document.getElementById('edit_name').value.trim();
    const code = document.getElementById('edit_code').value.trim();
    
    if (name.length < 2) {
        e.preventDefault();
        alert('Program name must be at least 2 characters long!');
        return false;
    }
    
    if (!/^[A-Z0-9]{2,20}$/.test(code)) {
        e.preventDefault();
        alert('Program code must be 2-20 characters containing only uppercase letters and numbers!');
        return false;
    }
});

// Close modal on outside click
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) {
        e.target.classList.remove('show');
        document.body.style.overflow = 'auto';
    }
});

// ✅ Alert popup show/hide
<?php if (!empty($message)): ?>
document.addEventListener('DOMContentLoaded', function() {
    const popup = document.getElementById('popup');
    if (popup) {
        popup.classList.add('show');
        setTimeout(() => {
            popup.classList.remove('show');
        }, 5000);
    }
});
<?php endif; ?>
</script>

<script src="../assets/js/sidebar.js"></script>
<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>