<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Access Control - Super Admin, Campus Admin, and Department Admin
$role = strtolower($_SESSION['user']['role'] ?? '');
if (!in_array($role, ['super_admin', 'campus_admin', 'department_admin'])) {
    header("Location: ../login.php");
    exit;
}

// Get user's linked IDs based on role
$user_campus_id = null;
$user_department_id = null;
$user_faculty_id = null;
$assigned_department_name = null;

if ($role === 'campus_admin' && !empty($_SESSION['user']['linked_id']) && ($_SESSION['user']['linked_table'] ?? '') === 'campus') {
    $user_campus_id = $_SESSION['user']['linked_id'];
} elseif ($role === 'department_admin' && !empty($_SESSION['user']['linked_id']) && ($_SESSION['user']['linked_table'] ?? '') === 'department') {
    $user_department_id = $_SESSION['user']['linked_id'];
    
    // Get department details including campus and faculty
    $dept_stmt = $pdo->prepare("
        SELECT d.department_id, d.department_name, d.campus_id, d.faculty_id, 
               c.campus_name, f.faculty_name
        FROM departments d
        LEFT JOIN campus c ON d.campus_id = c.campus_id AND c.status = 'active'
        LEFT JOIN faculties f ON d.faculty_id = f.faculty_id AND f.status = 'active'
        WHERE d.department_id = ? AND d.status = 'active'
    ");
    $dept_stmt->execute([$user_department_id]);
    $dept_info = $dept_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($dept_info) {
        $user_campus_id = $dept_info['campus_id'];
        $user_faculty_id = $dept_info['faculty_id'];
        $assigned_department_name = $dept_info['department_name'];
    }
}

$message = "";
$type = "";

/* ===========================================
   HELPER: Generate Teacher UUID
=========================================== */
function generate_teacher_uuid($pdo) {
    $last = $pdo->query("SELECT teacher_uuid FROM teachers ORDER BY teacher_id DESC LIMIT 1")->fetchColumn();
    $num = 1;
    if ($last && preg_match('/^HU(\d{7,})$/', $last, $m)) $num = $m[1] + 1;
    return 'HU' . str_pad($num, 7, '0', STR_PAD_LEFT);
}

/* ===========================================
   CRUD OPERATIONS
=========================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 🟢 ADD TEACHER
    if ($_POST['action'] === 'add') {
        try {
            $email = trim($_POST['email']);
            $check = $pdo->prepare("SELECT COUNT(*) FROM teachers WHERE email=?");
            $check->execute([$email]);
            if ($check->fetchColumn() > 0) throw new Exception("Email already exists!");

            $photo_path = null;
            if (!empty($_FILES['profile_photo']['name'])) {
                $upload_dir = __DIR__ . '/../upload/profiles/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                $ext = pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
                $new_name = uniqid('teacher_') . '.' . strtolower($ext);
                $photo_path = 'upload/profiles/' . $new_name;
                move_uploaded_file($_FILES['profile_photo']['tmp_name'], __DIR__ . '/../' . $photo_path);
            }

            $uuid = generate_teacher_uuid($pdo);
            $stmt = $pdo->prepare("INSERT INTO teachers 
                (teacher_uuid, teacher_name, email, phone_number, gender, qualification, position_title, profile_photo_path, status, created_at)
                VALUES (?,?,?,?,?,?,?,?, 'active', NOW())");
            $stmt->execute([
                $uuid, $_POST['teacher_name'], $email,
                $_POST['phone_number'] ?? null, $_POST['gender'] ?? null,
                $_POST['qualification'] ?? null, $_POST['position_title'] ?? null,
                $photo_path
            ]);

            $teacher_id = $pdo->lastInsertId();
            $plain_pass = "123"; // Password caadi ah
            $hashed = password_hash($plain_pass, PASSWORD_BCRYPT);

            // ✅ Insert user with both hashed and plain password
            $user = $pdo->prepare("INSERT INTO users 
                (user_uuid, username, email, phone_number, profile_photo_path, password, role, linked_id, linked_table, status, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?, NOW())");
            $user->execute([
                $uuid,                      // 1 user_uuid
                $_POST['teacher_name'],     // 2 username
                $email,                     // 3 email
                $_POST['phone_number'],     // 4 phone_number
                $photo_path,                // 5 profile_photo_path
                $hashed,                    // 6 password
                'teacher',                  // 7 role
                $teacher_id,                // 8 linked_id
                'teachers',                 // 9 linked_table
                'active'                    // 10 status
            ]);

            $message = "✅ Teacher added successfully! Default password: 123";
            $type = "success";
        } catch (Exception $e) {
            $message = "❌ " . $e->getMessage();
            $type = "error";
        }
    }

    // 🟡 UPDATE TEACHER
    if ($_POST['action'] === 'update') {
        try {
            $id = $_POST['teacher_id'];
            
            // Check if user has permission to edit this teacher
            if ($role === 'department_admin') {
                // Get teacher's department from teacher_timetable
                $check = $pdo->prepare("
                    SELECT COUNT(*) as cnt 
                    FROM timetable t
                    JOIN classes c ON t.class_id = c.class_id
                    WHERE t.teacher_id = ? AND c.department_id = ?
                ");
                $check->execute([$id, $user_department_id]);
                $result = $check->fetch(PDO::FETCH_ASSOC);
                
                if ($result['cnt'] == 0) {
                    throw new Exception("You can only edit teachers that teach in your department!");
                }
            }
            
            $params = [
                $_POST['teacher_name'], $_POST['email'], $_POST['phone_number'],
                $_POST['gender'], $_POST['qualification'], $_POST['position_title'],
                $_POST['status'], $id
            ];

            $photo_sql = "";
            if (!empty($_FILES['profile_photo']['name'])) {
                $upload_dir = __DIR__ . '/../upload/profiles/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                $ext = pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
                $new_name = uniqid('teacher_') . '.' . strtolower($ext);
                $photo_path = 'upload/profiles/' . $new_name;
                move_uploaded_file($_FILES['profile_photo']['tmp_name'], __DIR__ . '/../' . $photo_path);
                $photo_sql = ", profile_photo_path=?";
                array_splice($params, 7, 0, $photo_path);
            }

            $pdo->prepare("UPDATE teachers 
                SET teacher_name=?, email=?, phone_number=?, gender=?, qualification=?, position_title=?, status=? $photo_sql
                WHERE teacher_id=?")->execute($params);

            $pdo->prepare("UPDATE users SET username=?, email=?, phone_number=? WHERE linked_id=? AND linked_table='teachers'")
                ->execute([$_POST['teacher_name'], $_POST['email'], $_POST['phone_number'], $id]);

            $message = "✅ Teacher updated successfully!";
            $type = "success";
        } catch (Exception $e) {
            $message = "❌ " . $e->getMessage();
            $type = "error";
        }
    }

    // 🔴 DELETE TEACHER
    if ($_POST['action'] === 'delete') {
        try {
            $id = $_POST['teacher_id'];
            
            // Check if user has permission to delete this teacher
            if ($role === 'department_admin') {
                // Get teacher's department from teacher_timetable
                $check = $pdo->prepare("
                    SELECT COUNT(*) as cnt 
                    FROM timetable t
                    JOIN classes c ON t.class_id = c.class_id
                    WHERE t.teacher_id = ? AND c.department_id = ?
                ");
                $check->execute([$id, $user_department_id]);
                $result = $check->fetch(PDO::FETCH_ASSOC);
                
                if ($result['cnt'] == 0) {
                    throw new Exception("You can only delete teachers that teach in your department!");
                }
            }
            
            // Delete from users table first (foreign key constraint)
            $pdo->prepare("DELETE FROM users WHERE linked_id=? AND linked_table='teachers'")->execute([$id]);
            
            // Then delete from teachers table
            $pdo->prepare("DELETE FROM teachers WHERE teacher_id=?")->execute([$id]);
            
            $message = "✅ Teacher deleted successfully!";
            $type = "success";
        } catch (Exception $e) {
            $message = "❌ " . $e->getMessage();
            $type = "error";
        }
    }
}

/* ===========================================
   FETCH TEACHERS - WITH ROLE-BASED FILTERING
=========================================== */
if ($role === 'super_admin') {
    // Super admin sees all teachers
    $teachers = $pdo->query("
        SELECT t.* 
        FROM teachers t 
        ORDER BY t.teacher_id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
} elseif ($role === 'campus_admin' && $user_campus_id) {
    // Campus admin sees teachers that teach in their campus
    $teachers = $pdo->prepare("
        SELECT DISTINCT t.* 
        FROM teachers t
        INNER JOIN timetable tt ON t.teacher_id = tt.teacher_id
        INNER JOIN classes c ON tt.class_id = c.class_id
        WHERE c.campus_id = ?
        ORDER BY t.teacher_id DESC
    ");
    $teachers->execute([$user_campus_id]);
    $teachers = $teachers->fetchAll(PDO::FETCH_ASSOC);
    
} elseif ($role === 'department_admin' && $user_department_id) {
    // Department admin sees teachers that teach in their department
    $teachers = $pdo->prepare("
        SELECT DISTINCT t.* 
        FROM teachers t
        INNER JOIN timetable tt ON t.teacher_id = tt.teacher_id
        INNER JOIN classes c ON tt.class_id = c.class_id
        WHERE c.department_id = ?
        ORDER BY t.teacher_id DESC
    ");
    $teachers->execute([$user_department_id]);
    $teachers = $teachers->fetchAll(PDO::FETCH_ASSOC);
    
} else {
    $teachers = [];
}

// Get campus name for display if campus admin
$campus_name = '';
if ($role === 'campus_admin' && $user_campus_id) {
    $stmt = $pdo->prepare("SELECT campus_name FROM campus WHERE campus_id = ?");
    $stmt->execute([$user_campus_id]);
    $campus_name = $stmt->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Teachers | Hormuud University</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="../images.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
    --green: #00843D;
    --blue: #0072CE;
    --light-blue: #4A9FE1;
    --light-green: #00A651;
    --dark-green: #00612c;
    --dark-blue: #0056b3;
    --purple: #6A5ACD;
    --red: #C62828;
    --orange: #FF9800;
    --bg: #F5F9F7;
}
body {
    font-family: 'Poppins', sans-serif;
    background: var(--bg);
    margin: 0;
    min-height: 100vh;
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
    margin-bottom: 20px;
    padding: 20px 25px;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    border-left: 4px solid <?php echo $role === 'department_admin' ? 'var(--purple)' : 'var(--green)'; ?>;
}
.page-header h1 {
    color: var(--blue);
    font-size: 26px;
    font-weight: 600;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 12px;
}
.page-header h1 i {
    color: var(--green);
    background: rgba(0,132,61,0.1);
    padding: 12px;
    border-radius: 10px;
}
.role-badge {
    background: <?php echo $role === 'super_admin' ? 'var(--purple)' : ($role === 'department_admin' ? 'var(--light-blue)' : 'var(--green)'); ?>;
    color: white;
    padding: 5px 15px;
    border-radius: 30px;
    font-size: 13px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    letter-spacing: 0.3px;
}
.role-badge i {
    font-size: 14px;
}
.info-box {
    background: <?php echo $role === 'department_admin' ? 'linear-gradient(135deg, #f3e5f5, #e1bee7)' : 'linear-gradient(135deg, #e3f2fd, #bbdefb)'; ?>;
    border: 2px solid <?php echo $role === 'department_admin' ? 'var(--purple)' : 'var(--blue)'; ?>;
    border-radius: 12px;
    padding: 15px 20px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 15px;
}
.info-box i {
    color: <?php echo $role === 'department_admin' ? 'var(--purple)' : 'var(--blue)'; ?>;
    font-size: 24px;
    background: white;
    width: 50px;
    height: 50px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
}
.info-box-content {
    flex: 1;
}
.info-box-title {
    font-weight: 700;
    color: <?php echo $role === 'department_admin' ? 'var(--purple)' : 'var(--blue)'; ?>;
    margin-bottom: 5px;
    font-size: 16px;
}
.info-box-text {
    color: #555;
    font-size: 14px;
}
.info-box-text strong {
    color: var(--green);
    font-weight: 700;
}
.add-btn {
    background: var(--green);
    color: #fff;
    padding: 12px 20px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(0,132,61,0.2);
}
.add-btn:hover {
    background: var(--light-green);
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0,132,61,0.3);
}
.table-container {
    overflow-x: auto;
    margin-top: 20px;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 6px 20px rgba(0,0,0,0.05);
}
table {
    width: 100%;
    min-width: 1000px;
    border-collapse: collapse;
}
th, td {
    padding: 16px 18px;
    border-bottom: 1px solid #eee;
    text-align: left;
    white-space: nowrap;
    font-size: 14px;
}
thead th {
    background: <?php echo $role === 'department_admin' ? 'linear-gradient(135deg, var(--light-blue), var(--blue))' : 'linear-gradient(135deg, var(--green), var(--light-green))'; ?>;
    color: #fff;
    position: sticky;
    top: 0;
    font-weight: 600;
    letter-spacing: 0.3px;
}
tr:hover {
    background: <?php echo $role === 'department_admin' ? '#f3e5f5' : '#eef8f0'; ?>;
}
tbody tr:nth-child(even) {
    background: <?php echo $role === 'department_admin' ? '#faf5ff' : '#f9f9f9'; ?>;
}
.action-buttons {
    display: flex;
    justify-content: center;
    gap: 8px;
}
.btn-edit {
    background: var(--blue);
    color: white;
    padding: 8px 12px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.3s ease;
}
.btn-edit:hover {
    background: var(--dark-blue);
    transform: translateY(-2px);
}
.btn-delete {
    background: var(--red);
    color: white;
    padding: 8px 12px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.3s ease;
}
.btn-delete:hover {
    background: #b71c1c;
    transform: translateY(-2px);
}
/* Profile Photo Styles */
.photo-container {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    overflow: hidden;
    position: relative;
    background: <?php echo $role === 'department_admin' ? 'var(--light-blue)' : 'var(--green)'; ?>;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 20px;
    text-transform: uppercase;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
.photo-container img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    position: absolute;
    top: 0;
    left: 0;
}
.photo-container span {
    z-index: 1;
}
.modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.6);
    justify-content: center;
    align-items: center;
    z-index: 3000;
    backdrop-filter: blur(5px);
    animation: fadeIn 0.3s ease;
}
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
.modal.show {
    display: flex;
}
.modal-content {
    background: #fff;
    border-radius: 16px;
    width: 90%;
    max-width: 700px;
    padding: 30px;
    position: relative;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
    animation: slideIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}
@keyframes slideIn {
    from { opacity: 0; transform: translateY(-30px) scale(0.95); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
.close-modal {
    position: absolute;
    top: 15px;
    right: 20px;
    font-size: 28px;
    cursor: pointer;
    color: #888;
    transition: all 0.3s ease;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: rgba(0,0,0,0.05);
}
.close-modal:hover {
    background: rgba(0,0,0,0.1);
    color: var(--red);
    transform: rotate(90deg);
}
.modal-content h2 {
    color: <?php echo $role === 'department_admin' ? 'var(--light-blue)' : 'var(--blue)'; ?>;
    margin-bottom: 25px;
    font-size: 24px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 12px;
    padding-bottom: 15px;
    border-bottom: 2px solid #f0f0f0;
}
.modal-content h2 i {
    color: var(--green);
    background: rgba(0,132,61,0.1);
    padding: 10px;
    border-radius: 10px;
}
form {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
}
label {
    font-weight: 600;
    color: <?php echo $role === 'department_admin' ? 'var(--light-blue)' : 'var(--blue)'; ?>;
    font-size: 14px;
    margin-bottom: 5px;
    display: block;
}
input, select {
    width: 100%;
    padding: 12px 15px;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.3s ease;
    background: #fafafa;
}
input:focus, select:focus {
    outline: none;
    border-color: <?php echo $role === 'department_admin' ? 'var(--light-blue)' : 'var(--blue)'; ?>;
    background: #fff;
    box-shadow: 0 0 0 4px <?php echo $role === 'department_admin' ? 'rgba(74,159,225,0.1)' : 'rgba(0,114,206,0.1)'; ?>;
}
.save-btn {
    grid-column: span 2;
    background: var(--green);
    color: #fff;
    border: none;
    padding: 14px;
    border-radius: 10px;
    font-weight: 600;
    font-size: 16px;
    cursor: pointer;
    transition: all 0.3s ease;
    margin-top: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    box-shadow: 0 4px 12px rgba(0,132,61,0.2);
}
.save-btn:hover {
    background: var(--light-green);
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0,132,61,0.3);
}
.delete-btn {
    background: var(--red);
}
.delete-btn:hover {
    background: #b71c1c;
}
.alert-popup {
    display: none;
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: #fff;
    padding: 30px 35px;
    border-radius: 16px;
    text-align: center;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    z-index: 5000;
    width: 350px;
    animation: alertSlideIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    border-top: 5px solid;
}
@keyframes alertSlideIn {
    from { opacity: 0; transform: translate(-50%, -60px) scale(0.9); }
    to { opacity: 1; transform: translate(-50%, -50%) scale(1); }
}
.alert-popup.show {
    display: block;
}
.alert-popup.success {
    border-top-color: var(--green);
}
.alert-popup.error {
    border-top-color: var(--red);
}
.alert-popup h3 {
    font-size: 16px;
    color: #333;
    margin-bottom: 20px;
    line-height: 1.5;
}
.alert-btn {
    background: var(--blue);
    color: white;
    padding: 10px 25px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.3s ease;
}
.alert-btn:hover {
    background: var(--dark-blue);
}
.stats-container {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 25px;
}
.stat-card {
    background: #fff;
    border-radius: 12px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 15px;
    box-shadow: 0 3px 10px rgba(0,0,0,0.08);
    transition: transform 0.3s ease;
    border-left: 4px solid;
}
.stat-card:hover {
    transform: translateY(-5px);
}
.stat-card.total { border-left-color: var(--blue); }
.stat-card.active { border-left-color: var(--green); }
.stat-card.inactive { border-left-color: var(--red); }
.stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    color: #fff;
}
.stat-icon.total { background: var(--blue); }
.stat-icon.active { background: var(--green); }
.stat-icon.inactive { background: var(--red); }
.stat-info h3 {
    font-size: 14px;
    color: #666;
    margin-bottom: 5px;
}
.stat-info .number {
    font-size: 24px;
    font-weight: 700;
    color: #333;
}
.empty-state {
    text-align: center;
    padding: 80px 20px;
    color: #666;
}
.empty-state i {
    font-size: 64px;
    color: #ddd;
    margin-bottom: 25px;
    display: block;
}
.empty-state h3 {
    font-size: 20px;
    margin-bottom: 15px;
    color: #888;
}
.empty-state p {
    color: #999;
    max-width: 500px;
    margin: 0 auto;
}
/* Loading Spinner */
.loading-spinner {
    display: inline-block;
    width: 40px;
    height: 40px;
    border: 4px solid #f3f3f3;
    border-top: 4px solid <?php echo $role === 'department_admin' ? 'var(--light-blue)' : 'var(--green)'; ?>;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
@media (max-width: 1024px) {
    .main-content { margin-left: 240px; }
}
@media (max-width: 768px) {
    .main-content { margin-left: 0 !important; padding: 15px; }
    .page-header { flex-direction: column; align-items: flex-start; gap: 15px; }
    .add-btn { width: 100%; justify-content: center; }
    .stats-container { grid-template-columns: repeat(2, 1fr); }
    form { grid-template-columns: 1fr; }
    .save-btn { grid-column: 1; }
}
@media (max-width: 576px) {
    .stats-container { grid-template-columns: 1fr; }
    .table-container { overflow-x: auto; }
    .action-buttons { flex-direction: column; }
    .btn-edit, .btn-delete { width: 100%; }
    .photo-container { width: 40px; height: 40px; font-size: 16px; }
}
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
    <div class="page-header">
        <h1>
            <i class="fas fa-chalkboard-teacher"></i> 
            Teachers Management
           
        </h1>
        <?php if ($role === 'super_admin' || $role === 'campus_admin'): ?>
            <button class="add-btn" onclick="openModal('addModal')">
                <i class="fa-solid fa-plus"></i> Add Teacher
            </button>
        <?php endif; ?>
    </div>

   

    <!-- Info Box for Campus Admin -->
    <?php if ($role === 'campus_admin' && isset($campus_name)): ?>
    <div class="info-box">
        <i class="fas fa-info-circle"></i>
        <div class="info-box-content">
            <div class="info-box-title">Campus Admin Access</div>
            <div class="info-box-text">
                You are managing teachers for <strong><?php echo htmlspecialchars($campus_name); ?></strong> campus.
                Only teachers that teach in this campus are shown.
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Stats Cards -->
    <?php 
    $total = count($teachers);
    $active = 0;
    $inactive = 0;
    foreach ($teachers as $t) {
        if ($t['status'] === 'active') $active++;
        else $inactive++;
    }
    ?>
    <div class="stats-container">
        <div class="stat-card total">
            <div class="stat-icon total"><i class="fas fa-users"></i></div>
            <div class="stat-info">
                <h3>Total Teachers</h3>
                <div class="number"><?php echo $total; ?></div>
            </div>
        </div>
        <div class="stat-card active">
            <div class="stat-icon active"><i class="fas fa-check-circle"></i></div>
            <div class="stat-info">
                <h3>Active Teachers</h3>
                <div class="number"><?php echo $active; ?></div>
            </div>
        </div>
        <div class="stat-card inactive">
            <div class="stat-icon inactive"><i class="fas fa-pause-circle"></i></div>
            <div class="stat-info">
                <h3>Inactive Teachers</h3>
                <div class="number"><?php echo $inactive; ?></div>
            </div>
        </div>
    </div>

    <!-- Loading Indicator (initially hidden) -->
    <div id="loadingIndicator" style="display: none; text-align: center; padding: 50px;">
        <div class="loading-spinner"></div>
        <p style="margin-top: 20px; color: <?php echo $role === 'department_admin' ? 'var(--light-blue)' : 'var(--green)'; ?>;">Loading teachers...</p>
    </div>

    <!-- ✅ Scrollable Table -->
    <div class="table-container" id="teachersTable">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Photo</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Gender</th>
                    <th>Qualification</th>
                    <th>Position</th>
                    <th>Status</th>
                    <?php if ($role === 'super_admin' || $role === 'campus_admin'): ?>
                        <th>Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if($teachers): foreach($teachers as $i=>$t): ?>
                <tr>
                    <td><?php echo $i+1; ?></td>
                    <td>
                        <?php if (!empty($t['profile_photo_path'])): ?>
                            <div class="photo-container">
                                <img src="../<?php echo htmlspecialchars($t['profile_photo_path']); ?>" 
                                     alt="<?php echo htmlspecialchars($t['teacher_name']); ?>"
                                     onerror="this.style.display='none'; this.parentElement.classList.add('fallback');">
                                <span style="display: none;"><?php echo strtoupper(substr($t['teacher_name'], 0, 1)); ?></span>
                            </div>
                            <script>
                                // Show initial if image fails to load
                                var img = document.querySelector('img[src="../<?php echo htmlspecialchars($t['profile_photo_path']); ?>"]');
                                if (img) {
                                    img.onerror = function() {
                                        this.style.display = 'none';
                                        this.nextElementSibling.style.display = 'flex';
                                    };
                                    img.onload = function() {
                                        this.style.display = 'block';
                                        this.nextElementSibling.style.display = 'none';
                                    };
                                }
                            </script>
                        <?php else: ?>
                            <div class="photo-container" style="background: <?php 
                                // Random color based on name
                                $colors = ['#00843D', '#0072CE', '#4A9FE1', '#00A651', '#6A5ACD', '#FF9800'];
                                $color_index = abs(crc32($t['teacher_name'])) % count($colors);
                                echo $colors[$color_index];
                            ?>;">
                                <span><?php echo strtoupper(substr($t['teacher_name'], 0, 1)); ?></span>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td><strong><?php echo htmlspecialchars($t['teacher_name']); ?></strong></td>
                    <td><?php echo htmlspecialchars($t['email']); ?></td>
                    <td><?php echo htmlspecialchars($t['phone_number']); ?></td>
                    <td><?php echo htmlspecialchars($t['gender']); ?></td>
                    <td><?php echo htmlspecialchars($t['qualification']); ?></td>
                    <td><?php echo htmlspecialchars($t['position_title']); ?></td>
                    <td>
                        <span style="color:<?php echo $t['status']=='active'?'green':'red'; ?>;font-weight:600;">
                            <?php echo ucfirst($t['status']); ?>
                        </span>
                    </td>
                    <?php if ($role === 'super_admin' || $role === 'campus_admin'): ?>
                        <td>
                            <div class="action-buttons">
                                <button class="btn-edit" onclick="openEditModal(
                                    <?php echo $t['teacher_id']; ?>,
                                    '<?php echo htmlspecialchars($t['teacher_name'], ENT_QUOTES); ?>',
                                    '<?php echo htmlspecialchars($t['email'], ENT_QUOTES); ?>',
                                    '<?php echo htmlspecialchars($t['phone_number'], ENT_QUOTES); ?>',
                                    '<?php echo htmlspecialchars($t['gender'], ENT_QUOTES); ?>',
                                    '<?php echo htmlspecialchars($t['qualification'], ENT_QUOTES); ?>',
                                    '<?php echo htmlspecialchars($t['position_title'], ENT_QUOTES); ?>',
                                    '<?php echo $t['status']; ?>'
                                )">
                                    <i class='fa-solid fa-pen-to-square'></i>
                                </button>
                                <button class="btn-delete" onclick="openDeleteModal(<?php echo $t['teacher_id']; ?>)">
                                    <i class='fa-solid fa-trash'></i>
                                </button>
                            </div>
                        </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; else: ?>
                <tr>
                    <td colspan="<?php echo ($role === 'super_admin' || $role === 'campus_admin') ? 10 : 9; ?>" style="text-align:center;color:#777; padding: 40px;">
                        <i class="fas fa-chalkboard-teacher fa-3x mb-3" style="color: #ddd;"></i><br>
                        No teachers found.
                        <?php if ($role === 'department_admin'): ?>
                            <br><small>Teachers that teach in your department will appear here.</small>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ✅ ADD MODAL -->
<div class="modal" id="addModal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('addModal')">&times;</span>
        <h2><i class="fas fa-plus-circle"></i> Add Teacher</h2>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add">
            <div>
                <label>Full Name <span style="color:red;">*</span></label>
                <input type="text" name="teacher_name" placeholder="e.g., Ahmed Ali" required>
            </div>
            <div>
                <label>Email <span style="color:red;">*</span></label>
                <input type="email" name="email" placeholder="ahmed@hormuud.com" required>
            </div>
            <div>
                <label>Phone Number</label>
                <input type="text" name="phone_number" placeholder="e.g., 615001122">
            </div>
            <div>
                <label>Gender</label>
                <select name="gender">
                    <option value="Male">Male</option>
                    <option value="Female">Female</option>
                </select>
            </div>
            <div>
                <label>Qualification</label>
                <input type="text" name="qualification" placeholder="e.g., Bachelor of Science">
            </div>
            <div>
                <label>Position Title</label>
                <input type="text" name="position_title" placeholder="e.g., Lecturer">
            </div>
            <div>
                <label>Profile Photo</label>
                <input type="file" name="profile_photo" accept="image/*">
            </div>
            <button class="save-btn" type="submit">
                <i class="fas fa-save"></i> Save Teacher
            </button>
        </form>
    </div>
</div>

<!-- ✅ EDIT MODAL -->
<div class="modal" id="editModal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('editModal')">&times;</span>
        <h2><i class="fas fa-edit"></i> Edit Teacher</h2>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="update">
            <input type="hidden" id="edit_id" name="teacher_id">
            <div>
                <label>Full Name <span style="color:red;">*</span></label>
                <input id="edit_name" name="teacher_name" type="text" required>
            </div>
            <div>
                <label>Email <span style="color:red;">*</span></label>
                <input id="edit_email" name="email" type="email" required>
            </div>
            <div>
                <label>Phone Number</label>
                <input id="edit_phone" name="phone_number" type="text">
            </div>
            <div>
                <label>Gender</label>
                <select id="edit_gender" name="gender">
                    <option value="Male">Male</option>
                    <option value="Female">Female</option>
                </select>
            </div>
            <div>
                <label>Qualification</label>
                <input id="edit_qual" name="qualification" type="text">
            </div>
            <div>
                <label>Position Title</label>
                <input id="edit_pos" name="position_title" type="text">
            </div>
            <div>
                <label>Status</label>
                <select id="edit_status" name="status">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <div>
                <label>New Photo (Optional)</label>
                <input type="file" name="profile_photo" accept="image/*">
            </div>
            <button class="save-btn" type="submit">
                <i class="fas fa-sync-alt"></i> Update Teacher
            </button>
        </form>
    </div>
</div>

<!-- ✅ DELETE MODAL -->
<div class="modal" id="deleteModal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('deleteModal')">&times;</span>
        <h2 style="color: var(--red);"><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h2>
        <form method="POST">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" id="delete_id" name="teacher_id">
            <div style="text-align:center; margin:30px 0;">
                <i class="fa-solid fa-exclamation-triangle" style="font-size: 48px; color: var(--red); margin-bottom: 20px;"></i>
                <p style="font-size:16px; color:#333; margin-bottom:10px;">
                    Are you sure you want to delete this teacher?
                </p>
                <p style="color:#666; font-size:14px;">
                    This action cannot be undone. This will also remove the teacher's user account.
                </p>
            </div>
            <button class="save-btn delete-btn" type="submit">
                <i class="fas fa-trash-alt"></i> Yes, Delete Teacher
            </button>
        </form>
    </div>
</div>

<!-- ✅ ALERT POPUP -->
<div id="popup" class="alert-popup <?php echo $type; ?>">
    <span class="alert-icon"><?php echo $type === 'success' ? '✓' : '✗'; ?></span>
    <h3><?php echo htmlspecialchars($message); ?></h3>
    <button class="alert-btn" onclick="closeAlert()">OK</button>
</div>

<script>
// Open modal function
function openModal(modalId) {
    document.getElementById(modalId).classList.add('show');
}

// Close modal function
function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}

// Open edit modal with data
function openEditModal(id, name, email, phone, gender, qual, pos, status) {
    openModal('editModal');
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_email').value = email;
    document.getElementById('edit_phone').value = phone;
    document.getElementById('edit_gender').value = gender;
    document.getElementById('edit_qual').value = qual;
    document.getElementById('edit_pos').value = pos;
    document.getElementById('edit_status').value = status;
}

// Open delete modal
function openDeleteModal(id) {
    openModal('deleteModal');
    document.getElementById('delete_id').value = id;
}

// Close alert
function closeAlert() {
    document.getElementById('popup').classList.remove('show');
}

// Close modals when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
    }
}

// Close modal with ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal.show').forEach(modal => {
            modal.classList.remove('show');
        });
    }
});

// Auto-show alert if message exists
<?php if (!empty($message)): ?>
    document.getElementById('popup').classList.add('show');
    setTimeout(function() {
        document.getElementById('popup').classList.remove('show');
    }, 5000);
<?php endif; ?>

// Auto-load teachers (simulated - in real app, this would be an AJAX call)
document.addEventListener('DOMContentLoaded', function() {
    // Show loading indicator
    const loadingIndicator = document.getElementById('loadingIndicator');
    const teachersTable = document.getElementById('teachersTable');
    
    if (teachersTable) {
        // Simulate loading (remove this in production)
        setTimeout(function() {
            if (loadingIndicator) loadingIndicator.style.display = 'none';
        }, 1000);
    }
});

// Fix for image error handling
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.photo-container img').forEach(img => {
        img.addEventListener('error', function() {
            this.style.display = 'none';
            if (this.nextElementSibling) {
                this.nextElementSibling.style.display = 'flex';
            } else {
                // Create span if it doesn't exist
                const span = document.createElement('span');
                span.textContent = this.alt.charAt(0).toUpperCase();
                span.style.display = 'flex';
                this.parentElement.appendChild(span);
            }
        });
        
        img.addEventListener('load', function() {
            this.style.display = 'block';
            if (this.nextElementSibling) {
                this.nextElementSibling.style.display = 'none';
            }
        });
    });
});
</script>

<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>