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

/* ===================== ADD / UPDATE / DELETE ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // ADD COURSE
  if ($_POST['action'] === 'add') {
    try {
      $code = trim($_POST['course_code']);
      $check = $pdo->prepare("SELECT COUNT(*) FROM courses WHERE course_code=?");
      $check->execute([$code]);
      if ($check->fetchColumn() > 0) {
        $message = "⚠️ Course code already exists!";
        $type = "error";
      } else {
        $stmt = $pdo->prepare("INSERT INTO courses 
          (campus_id, faculty_id, department_id, course_name, course_code, credit_hours, description, status, created_at)
          VALUES (?,?,?,?,?,?,?,'active',NOW())");
        $stmt->execute([
          $_POST['campus_id'], $_POST['faculty_id'], $_POST['department_id'],
          $_POST['course_name'], $_POST['course_code'], $_POST['credit_hours'], $_POST['description']
        ]);
        $message = "✅ Course added successfully!";
        $type = "success";
      }
    } catch (PDOException $e) {
      $message = "❌ ".$e->getMessage();
      $type = "error";
    }
  }

  // UPDATE COURSE
  if ($_POST['action'] === 'update') {
    try {
      $id = $_POST['course_id'];
      $stmt = $pdo->prepare("UPDATE courses SET 
        campus_id=?, faculty_id=?, department_id=?, course_name=?, credit_hours=?, description=?, status=?, updated_at=NOW()
        WHERE course_id=?");
      $stmt->execute([
        $_POST['campus_id'], $_POST['faculty_id'], $_POST['department_id'],
        $_POST['course_name'], $_POST['credit_hours'], $_POST['description'], $_POST['status'], $id
      ]);
      $message = "✅ Course updated successfully!";
      $type = "success";
    } catch (PDOException $e) {
      $message = "❌ ".$e->getMessage();
      $type = "error";
    }
  }

  // DELETE COURSE
  if ($_POST['action'] === 'delete') {
    $id = $_POST['course_id'];
    $pdo->prepare("DELETE FROM courses WHERE course_id=?")->execute([$id]);
    $message = "✅ Course deleted successfully!";
    $type = "success";
  }
}

/* ===================== FETCH ===================== */
$sql = "SELECT c.*, ca.campus_name, f.faculty_name, d.department_name
        FROM courses c
        LEFT JOIN campus ca ON ca.campus_id = c.campus_id
        LEFT JOIN faculties f ON f.faculty_id = c.faculty_id
        LEFT JOIN departments d ON d.department_id = c.department_id
        ORDER BY c.course_id DESC";
$courses = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Course Management</title>
<link rel="stylesheet" href="../assets/css/header.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{--hu-green:#009639;--hu-light:#12b74b;--hu-red:#e74c3c;}
body{background:#f9fafb;font-family:'Poppins',sans-serif;margin:0;}
.main-content{margin-top:100px;padding:20px;}
.add-btn{background:var(--hu-green);color:#fff;border:none;padding:9px 14px;border-radius:6px;font-weight:600;cursor:pointer;}
.add-btn:hover{background:var(--hu-light);}
.table-wrapper{overflow-x:auto;margin-top:15px;}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;box-shadow:0 3px 8px rgba(0,0,0,.1);}
th,td{padding:10px 14px;border-bottom:1px solid #eee;text-align:left;}
th{background:var(--hu-green);color:#fff;}
.action-btns{display:flex;gap:8px;justify-content:center;}
.action-btns button{border:none;border-radius:6px;padding:7px 10px;color:#fff;cursor:pointer;}
.edit-btn{background:#2980b9;} .edit-btn:hover{background:#3498db;}
.del-btn{background:#c0392b;} .del-btn:hover{background:#e74c3c;}
/* MODAL FORM GRID */
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);justify-content:center;align-items:center;z-index:3000;}
.modal.show{display:flex;}
.modal-content{background:#fff;border-radius:12px;width:95%;max-width:850px;padding:25px;position:relative;}
.close-modal{position:absolute;top:10px;right:15px;font-size:22px;cursor:pointer;}
.modal-content h2{text-align:center;color:var(--hu-green);}
.grid2x2{display:grid;grid-template-columns:repeat(2,1fr);gap:20px;}
.grid2x2 label{font-weight:600;color:#333;}
.grid2x2 input,.grid2x2 select,.grid2x2 textarea{padding:10px;border:1px solid #ccc;border-radius:8px;width:100%;box-sizing:border-box;}
.grid2x2 input:focus,.grid2x2 select:focus,.grid2x2 textarea:focus{border-color:var(--hu-green);outline:none;}
.save-btn{grid-column:span 2;background:var(--hu-green);color:#fff;padding:10px;border:none;border-radius:8px;font-weight:600;cursor:pointer;}
.save-btn:hover{background:var(--hu-light);}
@media(max-width:768px){.grid2x2{grid-template-columns:1fr;}}
.alert-popup{display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;padding:20px 40px;border-radius:10px;z-index:4000;}
.alert-popup.show{display:block;}
.alert-popup.success{border-top:5px solid var(--hu-green);}
.alert-popup.error{border-top:5px solid var(--hu-red);}
</style>
</head>
<body>
<?php include('../includes/header.php'); ?>

<div class="main-content">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;">
    <h1>🎓 Course Management</h1>
    <button class="add-btn" onclick="openModal('addModal')">+ Add Course</button>
  </div>

  <div class="table-wrapper">
    <table>
      <thead>
        <tr><th>#</th><th>Course Name</th><th>Code</th><th>Campus</th><th>Faculty</th><th>Department</th><th>Credit</th><th>Status</th><th>Action</th></tr>
      </thead>
      <tbody>
      <?php if($courses): foreach($courses as $i=>$c): ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><?= htmlspecialchars($c['course_name']) ?></td>
          <td><?= htmlspecialchars($c['course_code']) ?></td>
          <td><?= htmlspecialchars($c['campus_name']) ?></td>
          <td><?= htmlspecialchars($c['faculty_name']) ?></td>
          <td><?= htmlspecialchars($c['department_name']) ?></td>
          <td><?= htmlspecialchars($c['credit_hours']) ?></td>
          <td><?= ucfirst($c['status']) ?></td>
          <td class="action-btns">
            <button class="edit-btn"><i class='fa fa-pen'></i></button>
            <button class="del-btn" onclick="deleteModal(<?= $c['course_id'] ?>)"><i class='fa fa-trash'></i></button>
          </td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="9" style="text-align:center;color:#777;">No courses found.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ADD MODAL -->
<div class="modal" id="addModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('addModal')">&times;</span>
    <h2>Add Course</h2>
    <form class="grid2x2" method="POST">
      <input type="hidden" name="action" value="add">
      
      <label>Campus</label>
      <select id="campusSelect" name="campus_id" required>
        <option value="">Select Campus</option>
      </select>

      <label>Faculty</label>
      <select id="facultySelect" name="faculty_id" required>
        <option value="">Select Faculty</option>
      </select>

      <label>Department</label>
      <select id="departmentSelect" name="department_id" required>
        <option value="">Select Department</option>
      </select>

      <label>Course Name</label><input name="course_name" required>
      <label>Course Code</label><input name="course_code" required>
      <label>Credit Hours</label><input name="credit_hours" type="number" min="1">
      <label>Description</label><textarea name="description" rows="2"></textarea>

      <button class="save-btn">💾 Save Course</button>
    </form>
  </div>
</div>

<!-- DELETE MODAL -->
<div class="modal" id="delModal"><div class="modal-content">
  <span class="close-modal" onclick="closeModal('delModal')">&times;</span>
  <h3 style="color:var(--hu-red)">Confirm Delete</h3>
  <form method="POST"><input type="hidden" name="action" value="delete">
    <input type="hidden" name="course_id" id="del_id">
    <p>Are you sure you want to delete this course?</p>
    <button class="save-btn" style="background:var(--hu-red)">Delete</button>
  </form>
</div></div>

<div id="popup" class="alert-popup <?= $type ?>"><h3><?= $message ?></h3></div>

<script>
function openModal(id){document.getElementById(id).classList.add('show');}
function closeModal(id){document.getElementById(id).classList.remove('show');}
function deleteModal(id){openModal('delModal');del_id.value=id;}
if("<?= $message ?>"){let p=document.getElementById('popup');p.classList.add('show');setTimeout(()=>p.classList.remove('show'),4000);}

// ✅ Load Campus List
window.addEventListener('DOMContentLoaded', ()=>{
  fetch('ajax/get_campus.php')
  .then(res=>res.json())
  .then(data=>{
    let campusSelect=document.getElementById('campusSelect');
    campusSelect.innerHTML='<option value="">Select Campus</option>';
    data.forEach(c=>campusSelect.innerHTML+=`<option value="${c.campus_id}">${c.campus_name}</option>`);
  });
});

// ✅ Dynamic Faculty & Department Load
document.getElementById('campusSelect').addEventListener('change',function(){
  let campusId=this.value;
  fetch('ajax/get_faculties.php?campus_id='+campusId)
  .then(res=>res.json())
  .then(data=>{
    let fac=document.getElementById('facultySelect');
    fac.innerHTML='<option value="">Select Faculty</option>';
    data.forEach(f=>fac.innerHTML+=`<option value="${f.faculty_id}">${f.faculty_name}</option>`);
  });
});

document.getElementById('facultySelect').addEventListener('change',function(){
  let facultyId=this.value;
  fetch('ajax/get_department.php?faculty_id='+facultyId)
  .then(res=>res.json())
  .then(data=>{
    let dep=document.getElementById('departmentSelect');
    dep.innerHTML='<option value="">Select Department</option>';
    data.forEach(d=>dep.innerHTML+=`<option value="${d.department_id}">${d.department_name}</option>`);
  });
});
</script>
<script src="../assets/js/sidebar.js"></script>
</body>
</html>
<?php ob_end_flush(); ?>
