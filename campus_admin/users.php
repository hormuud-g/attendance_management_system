<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// Check if user is logged in and has appropriate role
$user_role = strtolower($_SESSION['user']['role'] ?? '');
$user_campus_id = $_SESSION['user']['linked_id'] ?? null;
$is_campus_admin = ($user_role === 'campus_admin');
$is_super_admin = ($user_role === 'super_admin');

if (!$is_super_admin && !$is_campus_admin) {
    header("Location: ../login.php");
    exit;
}

$message = "";
$type = "";

/* ============================================================
   CRUD OPERATIONS
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $pdo->beginTransaction();

        // 🗑️ DELETE
        if ($_POST['action'] === 'delete') {
            $uid = intval($_POST['user_id']);
            
            // Check if user exists and get their role
            $check = $pdo->prepare("SELECT role, linked_id FROM users WHERE user_id=?");
            $check->execute([$uid]);
            $urow = $check->fetch(PDO::FETCH_ASSOC);
            
            if (!$urow) {
                throw new Exception("❌ User not found!");
            }
            
            // Security checks
            if ($urow['role'] === 'super_admin') {
                throw new Exception("❌ Super Admin lama delete-gareyn karo!");
            }
            
            // Campus admin can only delete users from their own campus
            if ($is_campus_admin && $urow['linked_id'] != $user_campus_id) {
                throw new Exception("❌ You can only delete users from your campus!");
            }
            
            $pdo->prepare("DELETE FROM users WHERE user_id=?")->execute([$uid]);
            $message = "🗑️ User deleted successfully!";
        }

        // ➕ ADD or ✏️ UPDATE
        else {
            $photo = $_POST['existing_photo'] ?? 'upload/profiles/default.png';
            if (!empty($_FILES['photo']['name'])) {
                $dir = __DIR__ . '/../upload/profiles/';
                if (!is_dir($dir)) mkdir($dir, 0777, true);
                
                // Validate file type
                $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                $filetype = mime_content_type($_FILES['photo']['tmp_name']);
                if (!in_array($filetype, $allowed)) {
                    throw new Exception("❌ Only JPG, PNG, GIF images allowed!");
                }
                
                // Validate file size (2MB max)
                if ($_FILES['photo']['size'] > 2 * 1024 * 1024) {
                    throw new Exception("❌ Image size must be less than 2MB!");
                }
                
                $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                $name = uniqid('usr_') . ".$ext";
                $photo = "upload/profiles/$name";
                move_uploaded_file($_FILES['photo']['tmp_name'], __DIR__ . '/../' . $photo);
            }

            // ➕ ADD
            if ($_POST['action'] === 'add') {
                $plainPass = $_POST['password_plain'] ?: '123';
                $role = $_POST['role'];
                $linked_id = $_POST['linked_id'] ?: null;
                $linked_table = $_POST['linked_table'] ?: null;
                
                // Campus admin restrictions
                if ($is_campus_admin) {
                    // Campus admin can only add users for their own campus
                    if ($role === 'campus_admin') {
                        throw new Exception("❌ You cannot create campus admins!");
                    }
                    
                    if ($linked_id != $user_campus_id) {
                        throw new Exception("❌ You can only add users for your campus!");
                    }
                    
                    // Campus admin can only add certain roles
                    $allowed_roles = ['teacher', 'student', 'parent', 'faculty_admin', 'department_admin'];
                    if (!in_array($role, $allowed_roles)) {
                        throw new Exception("❌ You can only add teachers, students, parents, faculty admins, or department admins!");
                    }
                }
                
                // Check if username exists
                $check_user = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username=?");
                $check_user->execute([$_POST['username']]);
                if ($check_user->fetchColumn() > 0) {
                    throw new Exception("❌ Username already exists!");
                }
                
                // Check if email exists
                if (!empty($_POST['email'])) {
                    $check_email = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email=?");
                    $check_email->execute([$_POST['email']]);
                    if ($check_email->fetchColumn() > 0) {
                        throw new Exception("❌ Email already exists!");
                    }
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO users (
                        user_uuid, username, email, phone_number, profile_photo_path, 
                        password, password_plain, role, linked_id, linked_table, 
                        status, created_at
                    )
                    VALUES (UUID(),?,?,?,?,?,?,?,?,?,?,NOW())
                ");
                $stmt->execute([
                    $_POST['username'],
                    $_POST['email'],
                    $_POST['phone_number'],
                    $photo,
                    password_hash($plainPass, PASSWORD_BCRYPT),
                    $plainPass,
                    $role,
                    $linked_id,
                    $linked_table,
                    $_POST['status']
                ]);
                $message = "✅ User added successfully!";
            }

            // ✏️ UPDATE
            elseif ($_POST['action'] === 'update') {
                $uid = intval($_POST['user_id']);
                
                // Check if user exists and get their data
                $check = $pdo->prepare("SELECT role, linked_id FROM users WHERE user_id=?");
                $check->execute([$uid]);
                $urow = $check->fetch(PDO::FETCH_ASSOC);
                
                if (!$urow) {
                    throw new Exception("❌ User not found!");
                }
                
                // Security checks
                if ($urow['role'] === 'super_admin') {
                    throw new Exception("❌ Super Admin lama edit-gareyn karo!");
                }
                
                // Campus admin can only edit users from their own campus
                if ($is_campus_admin && $urow['linked_id'] != $user_campus_id) {
                    throw new Exception("❌ You can only edit users from your campus!");
                }
                
                $plainPass = trim($_POST['password_plain']);
                $setPass = "";
                $params = [
                    $_POST['username'],
                    $_POST['email'],
                    $_POST['phone_number'],
                    $photo,
                    $_POST['role'],
                    $_POST['linked_id'] ?: null,
                    $_POST['linked_table'] ?: null,
                    $_POST['status'],
                    $uid
                ];

                // Check if username changed
                if ($_POST['username'] != $urow['username']) {
                    $check_user = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username=? AND user_id!=?");
                    $check_user->execute([$_POST['username'], $uid]);
                    if ($check_user->fetchColumn() > 0) {
                        throw new Exception("❌ Username already exists!");
                    }
                }
                
                // Check if email changed
                if (!empty($_POST['email']) && $_POST['email'] != $urow['email']) {
                    $check_email = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email=? AND user_id!=?");
                    $check_email->execute([$_POST['email'], $uid]);
                    if ($check_email->fetchColumn() > 0) {
                        throw new Exception("❌ Email already exists!");
                    }
                }

                if (!empty($plainPass)) {
                    $setPass = ", password=?, password_plain=?";
                    array_splice($params, 4, 0, [password_hash($plainPass, PASSWORD_BCRYPT), $plainPass]);
                }

                $sql = "
                    UPDATE users 
                    SET username=?, email=?, phone_number=?, profile_photo_path=? $setPass,
                        role=?, linked_id=?, linked_table=?, status=? 
                    WHERE user_id=?
                ";
                $pdo->prepare($sql)->execute($params);
                $message = "✏️ User updated successfully!";
            }
        }

        $pdo->commit();
        $type = "success";
        
        // Redirect to prevent form resubmission
        header("Location: users.php?success=1");
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = $e->getMessage();
        $type = "error";
    }
}

// Check for success redirect
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $message = "✅ Operation completed successfully!";
    $type = "success";
}

/* ============================================================
   FETCH USERS (SEARCH + FILTER)
============================================================ */
$search = trim($_GET['q'] ?? '');
$role_filter = $_GET['role'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';

// Build query based on user role
$sql = "
    SELECT u.*, 
           CASE 
               WHEN u.role = 'student' THEN s.reg_no
               WHEN u.role = 'teacher' THEN t.teacher_name
               WHEN u.role = 'parent' THEN p.full_name
               WHEN u.role = 'campus_admin' THEN c.campus_name
               ELSE NULL 
           END as linked_name,
           CASE 
               WHEN u.role = 'student' THEN s.reg_no
               ELSE NULL 
           END as reg_no
    FROM users u
    LEFT JOIN students s ON u.linked_table='student' AND u.linked_id = s.student_id
    LEFT JOIN teachers t ON u.linked_table='teacher' AND u.linked_id = t.teacher_id
    LEFT JOIN parents p ON u.linked_table='parent' AND u.linked_id = p.parent_id
    LEFT JOIN campus c ON u.linked_table='campus' AND u.linked_id = c.campus_id
    WHERE 1=1
";

$params = [];

// Campus admin can only see users from their campus
if ($is_campus_admin) {
    $sql .= " AND u.linked_id = ?";
    $params[] = $user_campus_id;
    
    // Campus admin cannot see super_admin or other campus admins
    $sql .= " AND u.role NOT IN ('super_admin', 'campus_admin')";
} else if ($is_super_admin) {
    // Super admin sees all except super_admin when not searching for it
    if ($search === '' || stripos($search, 'super_admin') === false) {
        $sql .= " AND u.role != 'super_admin'";
    }
}

// Apply filters
if ($search !== '') {
    $sql .= " AND (u.username LIKE ? OR u.email LIKE ? OR u.phone_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($role_filter !== 'all') {
    $sql .= " AND u.role = ?";
    $params[] = $role_filter;
}

if (in_array($status_filter, ['active','inactive'])) {
    $sql .= " AND u.status = ?";
    $params[] = $status_filter;
}

$sql .= " ORDER BY u.user_id DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $users = [];
    $message = "❌ Database error: " . $e->getMessage();
    $type = "error";
}

// Get campus info for campus admin
$campus_info = [];
if ($is_campus_admin && $user_campus_id) {
    try {
        $campus_stmt = $pdo->prepare("SELECT campus_name, campus_code FROM campus WHERE campus_id = ?");
        $campus_stmt->execute([$user_campus_id]);
        $campus_info = $campus_stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $campus_info = ['campus_name' => 'Unknown Campus', 'campus_code' => 'N/A'];
    }
}

// Get available roles based on user type
$available_roles = [];
if ($is_super_admin) {
    $available_roles = ['super_admin', 'campus_admin', 'faculty_admin', 'department_admin', 'teacher', 'student', 'parent', 'auditor'];
} else if ($is_campus_admin) {
    $available_roles = ['faculty_admin', 'department_admin', 'teacher', 'student', 'parent'];
}

// Get linked data for dropdowns
$linked_options = [];
if ($is_campus_admin && $user_campus_id) {
    try {
        // Get teachers for this campus
        $teachers = $pdo->prepare("SELECT teacher_id as id, full_name as name FROM teachers WHERE campus_id = ? AND status = 'active'");
        $teachers->execute([$user_campus_id]);
        $linked_options['teacher'] = $teachers->fetchAll(PDO::FETCH_ASSOC);
        
        // Get students for this campus
        $students = $pdo->prepare("SELECT student_id as id, full_name as name FROM students WHERE campus_id = ? AND status = 'active'");
        $students->execute([$user_campus_id]);
        $linked_options['student'] = $students->fetchAll(PDO::FETCH_ASSOC);
        
        // Get parents for this campus
        $parents = $pdo->prepare("SELECT parent_id as id, full_name as name FROM parents WHERE campus_id = ? AND status = 'active'");
        $parents->execute([$user_campus_id]);
        $linked_options['parent'] = $parents->fetchAll(PDO::FETCH_ASSOC);
        
        // Get faculties for this campus
        $faculties = $pdo->prepare("SELECT faculty_id as id, faculty_name as name FROM faculties WHERE campus_id = ? AND status = 'active'");
        $faculties->execute([$user_campus_id]);
        $linked_options['faculty'] = $faculties->fetchAll(PDO::FETCH_ASSOC);
        
        // Get departments for this campus
        $departments = $pdo->prepare("
            SELECT d.department_id as id, d.department_name as name 
            FROM departments d
            JOIN faculties f ON d.faculty_id = f.faculty_id
            WHERE f.campus_id = ? AND d.status = 'active'
        ");
        $departments->execute([$user_campus_id]);
        $linked_options['department'] = $departments->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        // Error fetching linked data
    }
}

include('../includes/header.php');
?>
<style>
body {
    font-family: 'Poppins', sans-serif;
    background: #f7f9fb;
    margin: 0;
}

.main-content {
    padding: 25px;
    margin-left: 250px;
    margin-top: 90px;
    transition: margin-left 0.3s ease;
}

.sidebar.collapsed ~ .main-content {
    margin-left: 70px;
}

.top-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #fff;
    padding: 14px 20px;
    border-radius: 10px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.08);
    margin-bottom: 20px;
}

.top-bar h2 {
    color: #0072CE;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.btn {
    border: none;
    padding: 10px 16px;
    border-radius: 6px;
    font-weight: 600;
    cursor: pointer;
    font-size: 14px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.btn.blue {
    background: #0072CE;
    color: #fff;
}

.btn.green {
    background: #00843D;
    color: #fff;
}

.btn.red {
    background: #C62828;
    color: #fff;
}

.btn.gray {
    background: #6c757d;
    color: #fff;
}

/* Alert Styles */
.alert {
    position: fixed;
    top: 100px;
    right: 20px;
    padding: 15px 20px;
    border-radius: 10px;
    color: #fff;
    z-index: 9999;
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    animation: slideIn 0.3s ease;
}

@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

.alert-success {
    background: #00843D;
    border-left: 4px solid #006f33;
}

.alert-error {
    background: #C62828;
    border-left: 4px solid #b71c1c;
}

.alert.hide {
    animation: slideOut 0.3s ease;
    transform: translateX(100%);
    opacity: 0;
}

@keyframes slideOut {
    from { transform: translateX(0); opacity: 1; }
    to { transform: translateX(100%); opacity: 0; }
}

/* Campus Info Box */
.campus-info-box {
    background: linear-gradient(135deg, #e3f2fd, #bbdefb);
    border-radius: 8px;
    padding: 15px 20px;
    margin-bottom: 20px;
    border-left: 4px solid #0072CE;
    display: flex;
    align-items: center;
    gap: 12px;
}

.campus-info-box i {
    color: #0072CE;
    font-size: 20px;
}

.campus-info-box div {
    font-size: 15px;
}

.campus-info-box strong {
    color: #0072CE;
}

/* Search Bar */
.search-bar {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.search-bar input[type="text"] {
    flex: 1;
    min-width: 300px;
    padding: 10px 15px;
    border: 2px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
    transition: all 0.3s ease;
}

.search-bar input[type="text"]:focus {
    outline: none;
    border-color: #0072CE;
    box-shadow: 0 0 0 3px rgba(0, 114, 206, 0.1);
}

.search-bar select {
    padding: 10px 15px;
    border: 2px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
    min-width: 150px;
    background: white;
    cursor: pointer;
}

.search-bar select:focus {
    outline: none;
    border-color: #0072CE;
    box-shadow: 0 0 0 3px rgba(0, 114, 206, 0.1);
}

/* Table Styles */
.table-responsive {
    width: 100%;
    overflow: auto;
    max-height: 500px;
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    margin-top: 10px;
}

thead th {
    position: sticky;
    top: 0;
    background: linear-gradient(135deg, #0072CE, #005bb5);
    color: #fff;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 13px;
    letter-spacing: 0.5px;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th, td {
    padding: 12px 15px;
    border-bottom: 1px solid #eee;
    text-align: left;
    font-size: 14px;
}

tbody tr:hover {
    background: rgba(0, 114, 206, 0.05);
}

/* Status Badges */
.status-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}

.status-active {
    background: rgba(0, 132, 61, 0.1);
    color: #00843D;
    border: 1px solid rgba(0, 132, 61, 0.2);
}

.status-inactive {
    background: rgba(198, 40, 40, 0.1);
    color: #C62828;
    border: 1px solid rgba(198, 40, 40, 0.2);
}

/* Role Badges */
.role-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 500;
    text-transform: uppercase;
}

.role-super_admin { background: #ffeb3b; color: #333; }
.role-campus_admin { background: #2196f3; color: white; }
.role-faculty_admin { background: #9c27b0; color: white; }
.role-department_admin { background: #ff9800; color: white; }
.role-teacher { background: #4caf50; color: white; }
.role-student { background: #607d8b; color: white; }
.role-parent { background: #795548; color: white; }
.role-auditor { background: #9e9e9e; color: white; }

/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    justify-content: center;
    align-items: center;
    z-index: 9999;
    padding: 20px;
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
    width: 95%;
    max-width: 800px;
    border-radius: 12px;
    padding: 0;
    position: relative;
    max-height: 90vh;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    animation: modalSlideIn 0.4s ease;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(-30px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.modal-header {
    padding: 20px 30px;
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    border-bottom: 1px solid #ddd;
    border-radius: 12px 12px 0 0;
}

.modal-header h2 {
    margin: 0;
    color: #0072CE;
    display: flex;
    align-items: center;
    gap: 10px;
}

.close-modal {
    position: absolute;
    top: 20px;
    right: 20px;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #f8f9fa;
    border: none;
    color: #666;
    font-size: 24px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    z-index: 10;
}

.close-modal:hover {
    background: #e9ecef;
    color: #333;
    transform: rotate(90deg);
}

.modal-body {
    padding: 30px;
    overflow-y: auto;
    max-height: calc(90vh - 100px);
}

/* Form Styles */
.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.form-group.full-width {
    grid-column: 1 / -1;
}

.form-group label {
    font-weight: 600;
    color: #0072CE;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 5px;
}

.form-group label.required::after {
    content: "*";
    color: #C62828;
    margin-left: 4px;
}

.form-group input,
.form-group select,
.form-group textarea {
    padding: 12px;
    border: 2px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
    font-family: inherit;
    transition: all 0.3s ease;
    background: #fafafa;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #0072CE;
    box-shadow: 0 0 0 3px rgba(0, 114, 206, 0.1);
    background: white;
}

.form-group textarea {
    resize: vertical;
    min-height: 80px;
}

.save-btn {
    grid-column: 1 / -1;
    background: linear-gradient(135deg, #00843D, #006f33);
    color: white;
    border: none;
    padding: 15px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 16px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    transition: all 0.3s ease;
    margin-top: 10px;
}

.save-btn:hover {
    background: linear-gradient(135deg, #006f33, #005a29);
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0, 132, 61, 0.3);
}

/* Photo Upload */
.photo-upload {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    border: 2px dashed #ddd;
    text-align: center;
}

.photo-preview {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid white;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    margin: 10px auto;
    display: block;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 8px;
}

.action-btn {
    width: 36px;
    height: 36px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    font-size: 14px;
}

.action-btn.edit {
    background: rgba(0, 114, 206, 0.1);
    color: #0072CE;
    border: 1px solid rgba(0, 114, 206, 0.2);
}

.action-btn.edit:hover {
    background: #0072CE;
    color: white;
}

.action-btn.delete {
    background: rgba(198, 40, 40, 0.1);
    color: #C62828;
    border: 1px solid rgba(198, 40, 40, 0.2);
}

.action-btn.delete:hover {
    background: #C62828;
    color: white;
}

/* Linked Info */
.linked-info {
    font-size: 12px;
    color: #666;
    font-style: italic;
    margin-top: 4px;
}

/* Loading Animation */
.loading {
    display: inline-block;
    width: 16px;
    height: 16px;
    border: 2px solid rgba(255,255,255,0.3);
    border-top: 2px solid white;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Responsive */
@media (max-width: 768px) {
    .main-content {
        margin-left: 0;
        padding: 15px;
        margin-top: 60px;
    }
    
    .top-bar {
        flex-direction: column;
        gap: 15px;
        align-items: flex-start;
    }
    
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .search-bar {
        flex-direction: column;
        align-items: stretch;
    }
    
    .search-bar input[type="text"] {
        min-width: 100%;
    }
    
    .modal-content {
        margin: 10px;
        max-height: 80vh;
    }
    
    .modal-body {
        padding: 20px;
    }
}

/* Scrollbar Styling */
::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 4px;
}

::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 4px;
}

::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}

.table-responsive::-webkit-scrollbar-track {
    background: #f8f9fa;
}

.table-responsive::-webkit-scrollbar-thumb {
    background: #0072CE;
}

.modal-body::-webkit-scrollbar-track {
    background: #f8f9fa;
}

.modal-body::-webkit-scrollbar-thumb {
    background: #6c757d;
}
</style>

<div class="main-content">
    <?php if($message): ?>
        <div class="alert <?= $type=='success'?'alert-success':'alert-error' ?>" id="alertMessage">
            <?= htmlspecialchars($message) ?>
        </div>
        <script>
            setTimeout(() => {
                const alert = document.getElementById('alertMessage');
                if (alert) {
                    alert.classList.add('hide');
                    setTimeout(() => alert.remove(), 300);
                }
            }, 5000);
        </script>
    <?php endif; ?>

    <div class="top-bar">
        <h2><i class="fas fa-users"></i> User Management</h2>
        <button class="btn green" onclick="openModal('userModal')">
            <i class="fas fa-plus"></i> Add User
        </button>
    </div>

    <!-- Campus Info for Campus Admin -->
    <?php if($is_campus_admin && !empty($campus_info)): ?>
    <div class="campus-info-box">
        <i class="fas fa-university"></i>
        <div>
            <strong>Current Campus:</strong> 
            <?= htmlspecialchars($campus_info['campus_name']) ?> (<?= htmlspecialchars($campus_info['campus_code']) ?>)
        </div>
    </div>
    <?php endif; ?>

    <!-- 🔍 FILTER + SEARCH -->
    <form method="GET" class="search-bar">
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" 
               placeholder="🔍 Search username, email, or phone...">
        
        <select name="role">
            <option value="all" <?= $role_filter=='all'?'selected':'' ?>>All Roles</option>
            <?php foreach($available_roles as $role_option): ?>
                <option value="<?= $role_option ?>" <?= $role_filter==$role_option?'selected':'' ?>>
                    <?= ucfirst(str_replace('_', ' ', $role_option)) ?>
                </option>
            <?php endforeach; ?>
        </select>
        
        <select name="status">
            <option value="all" <?= $status_filter=='all'?'selected':'' ?>>All Status</option>
            <option value="active" <?= $status_filter=='active'?'selected':'' ?>>Active</option>
            <option value="inactive" <?= $status_filter=='inactive'?'selected':'' ?>>Inactive</option>
        </select>
        
        <button type="submit" class="btn blue">
            <i class="fas fa-filter"></i> Filter
        </button>
        
        <?php if($search || $role_filter !== 'all' || $status_filter !== 'all'): ?>
            <a href="users.php" class="btn gray">
                <i class="fas fa-times"></i> Clear
            </a>
        <?php endif; ?>
    </form>

    <!-- TABLE -->
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Photo</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Linked To</th>
                    <th>Status</th>
                    <th>Password</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if(!empty($users)): ?>
                    <?php foreach($users as $i=>$u): ?>
                    <tr>
                        <td><strong><?= $i+1 ?></strong></td>
                        <td>
                            <?php if(!empty($u['profile_photo_path'])): ?>
                                <img src="../<?= htmlspecialchars($u['profile_photo_path']) ?>" 
                                     width="40" height="40" 
                                     style="border-radius:50%;object-fit:cover;border:2px solid #f0f0f0;"
                                     onerror="this.src='../upload/profiles/default.png'">
                            <?php else: ?>
                                <div style="width:40px;height:40px;border-radius:50%;background:#f0f0f0;display:flex;align-items:center;justify-content:center;">
                                    <i class="fas fa-user" style="color:#999;"></i>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                        <td>
                            <span class="role-badge role-<?= $u['role'] ?>">
                                <?= ucfirst(str_replace('_', ' ', $u['role'])) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($u['email']) ?: '<span style="color:#999;">N/A</span>' ?></td>
                        <td><?= htmlspecialchars($u['phone_number']) ?: '<span style="color:#999;">N/A</span>' ?></td>
                        <td>
                            <?php if($u['linked_name']): ?>
                                <span title="Linked ID: <?= $u['linked_id'] ?>">
                                    <?= htmlspecialchars($u['linked_name']) ?>
                                </span>
                            <?php else: ?>
                                <span style="color:#999;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status-badge status-<?= $u['status'] ?>">
                                <?= ucfirst($u['status']) ?>
                            </span>
                        </td>
                        <td>
                            <span style="font-family:monospace;color:#C62828;font-size:12px;">
                                <?= htmlspecialchars($u['password_plain']) ?>
                            </span>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <button class="action-btn edit" onclick='editUser(<?= json_encode($u) ?>)'>
                                    <i class="fas fa-edit"></i>
                                </button>
                                
                                <?php if($u['role'] !== 'super_admin'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <button class="action-btn delete" 
                                            type="submit" 
                                            onclick="return confirm('Are you sure you want to delete this user? This action cannot be undone.')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                                <?php else: ?>
                                <span class="action-btn" style="background:#f0f0f0;color:#999;cursor:default;" 
                                      title="Super Admin is protected">
                                    <i class="fas fa-lock"></i>
                                </span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="10" style="text-align:center;padding:40px;color:#777;">
                            <i class="fas fa-users" style="font-size:48px;margin-bottom:15px;color:#ddd;display:block;"></i>
                            <h3 style="margin:0 0 10px 0;color:#555;">No users found</h3>
                            <p style="margin:0;">Try adjusting your search or filters</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ADD/EDIT USER MODAL -->
<div class="modal" id="userModal">
    <div class="modal-content">
        <button class="close-modal" onclick="closeModal('userModal')">&times;</button>
        
        <div class="modal-header">
            <h2><i class="fas fa-user-plus" id="modalIcon"></i> <span id="formTitle">Add User</span></h2>
        </div>
        
        <div class="modal-body">
            <form method="POST" enctype="multipart/form-data" id="userForm" onsubmit="return validateUserForm()">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="user_id" id="user_id">
                <input type="hidden" name="existing_photo" id="existing_photo" value="upload/profiles/default.png">
                
                <div class="form-grid">
                    <!-- Campus Info for Campus Admin -->
                    <?php if($is_campus_admin): ?>
                    <div class="form-group full-width">
                        <div style="background:#e3f2fd;padding:12px;border-radius:6px;border-left:4px solid #0072CE;">
                            <strong>Creating user for:</strong> <?= htmlspecialchars($campus_info['campus_name'] ?? 'Your Campus') ?>
                            <input type="hidden" name="linked_id" id="linked_id_field" value="<?= $user_campus_id ?>">
                            <input type="hidden" name="linked_table" value="campus">
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label class="required"><i class="fas fa-user"></i> Username</label>
                        <input type="text" name="username" id="username" required 
                               placeholder="Enter username">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-envelope"></i> Email</label>
                        <input type="email" name="email" id="email" 
                               placeholder="user@example.com">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-phone"></i> Phone</label>
                        <input type="tel" name="phone_number" id="phone_number" 
                               placeholder="+252 61 1234567">
                    </div>
                    
                    <div class="form-group">
                        <label class="required"><i class="fas fa-user-tag"></i> Role</label>
                        <select name="role" id="role" required onchange="updateLinkedFields()">
                            <option value="">Select Role</option>
                            <?php foreach($available_roles as $role_option): ?>
                                <option value="<?= $role_option ?>">
                                    <?= ucfirst(str_replace('_', ' ', $role_option)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <?php if($is_super_admin): ?>
                    <div class="form-group">
                        <label><i class="fas fa-link"></i> Linked To (Optional)</label>
                        <select name="linked_table" id="linked_table" onchange="loadLinkedOptions()">
                            <option value="">None</option>
                            <option value="campus">Campus</option>
                            <option value="faculty">Faculty</option>
                            <option value="department">Department</option>
                            <option value="teacher">Teacher</option>
                            <option value="student">Student</option>
                            <option value="parent">Parent</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-hashtag"></i> Linked ID</label>
                        <select name="linked_id" id="linked_id" disabled>
                            <option value="">Select from list</option>
                        </select>
                        <small class="linked-info" id="linked_info"></small>
                    </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label><i class="fas fa-circle"></i> Status</label>
                        <select name="status" id="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="required"><i class="fas fa-key"></i> Password</label>
                        <input type="text" name="password_plain" id="password_plain" required 
                               placeholder="Enter password">
                        <small style="color:#666;font-size:12px;">Default: 123</small>
                    </div>
                    
                    <div class="form-group full-width photo-upload">
                        <label><i class="fas fa-camera"></i> Profile Photo</label>
                        <input type="file" name="photo" id="photo" accept="image/*" 
                               onchange="previewPhoto(event)">
                        <small style="color:#666;display:block;margin-top:5px;">
                            Max 2MB. JPG, PNG, GIF allowed. Leave empty for default.
                        </small>
                        <img id="photoPreview" class="photo-preview" 
                             src="../upload/profiles/default.png">
                    </div>
                    
                    <button type="submit" class="save-btn" id="saveBtn">
                        <i class="fas fa-save"></i> <span id="saveText">Save User</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Modal functions
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
    
    if (modalId === 'userModal') {
        resetForm();
        document.getElementById('formTitle').textContent = 'Add User';
        document.getElementById('modalIcon').className = 'fas fa-user-plus';
        document.getElementById('formAction').value = 'add';
        document.getElementById('saveText').textContent = 'Save User';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    modal.classList.remove('show');
    document.body.style.overflow = 'auto';
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        if (event.target === modal) {
            closeModal(modal.id);
        }
    });
};

// Form validation
function validateUserForm() {
    const form = document.getElementById('userForm');
    const requiredFields = form.querySelectorAll('[required]');
    let isValid = true;
    
    // Check required fields
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            field.style.borderColor = '#C62828';
            field.style.boxShadow = '0 0 0 3px rgba(198, 40, 40, 0.1)';
            isValid = false;
        } else {
            field.style.borderColor = '';
            field.style.boxShadow = '';
        }
    });
    
    // Validate email format
    const emailField = document.getElementById('email');
    if (emailField.value.trim()) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(emailField.value.trim())) {
            alert('❌ Please enter a valid email address!');
            emailField.focus();
            return false;
        }
    }
    
    // Validate phone format
    const phoneField = document.getElementById('phone_number');
    if (phoneField.value.trim()) {
        const phoneRegex = /^[\d\s\-\+\(\)]{8,20}$/;
        if (!phoneRegex.test(phoneField.value.trim())) {
            alert('❌ Please enter a valid phone number!');
            phoneField.focus();
            return false;
        }
    }
    
    if (!isValid) {
        alert('⚠️ Please fill in all required fields!');
        return false;
    }
    
    // Show loading state
    const saveBtn = document.getElementById('saveBtn');
    const saveText = document.getElementById('saveText');
    const originalText = saveText.textContent;
    
    saveBtn.disabled = true;
    saveText.innerHTML = '<span class="loading"></span> Processing...';
    
    // Re-enable after 5 seconds if form doesn't submit
    setTimeout(() => {
        saveBtn.disabled = false;
        saveText.textContent = originalText;
    }, 5000);
    
    return true;
}

// Reset form
function resetForm() {
    const form = document.getElementById('userForm');
    form.reset();
    document.getElementById('existing_photo').value = 'upload/profiles/default.png';
    document.getElementById('photoPreview').src = '../upload/profiles/default.png';
    document.getElementById('user_id').value = '';
    
    <?php if($is_super_admin): ?>
    document.getElementById('linked_table').value = '';
    document.getElementById('linked_id').value = '';
    document.getElementById('linked_id').disabled = true;
    document.getElementById('linked_info').textContent = '';
    <?php endif; ?>
}

// Edit user
function editUser(user) {
    // Check if user can be edited
    if (user.role === 'super_admin') {
        alert('❌ Super Admin lama edit-gareyn karo!');
        return;
    }
    
    <?php if($is_campus_admin): ?>
    // Campus admin can only edit users from their campus
    if (user.linked_id != <?= $user_campus_id ?>) {
        alert('❌ You can only edit users from your campus!');
        return;
    }
    <?php endif; ?>
    
    openModal('userModal');
    
    // Update modal title and icon
    document.getElementById('formTitle').textContent = 'Edit User';
    document.getElementById('modalIcon').className = 'fas fa-user-edit';
    document.getElementById('formAction').value = 'update';
    document.getElementById('saveText').textContent = 'Update User';
    
    // Fill form fields
    document.getElementById('user_id').value = user.user_id;
    document.getElementById('username').value = user.username || '';
    document.getElementById('email').value = user.email || '';
    document.getElementById('phone_number').value = user.phone_number || '';
    document.getElementById('role').value = user.role || '';
    document.getElementById('status').value = user.status || 'active';
    document.getElementById('password_plain').value = user.password_plain || '123';
    
    // Handle linked fields for super admin
    <?php if($is_super_admin): ?>
    if (user.linked_table && user.linked_id) {
        document.getElementById('linked_table').value = user.linked_table;
        document.getElementById('linked_id').value = user.linked_id;
        
        // Load linked options
        loadLinkedOptions();
        
        // Show linked info
        document.getElementById('linked_info').textContent = 
            'Currently linked to: ' + (user.linked_name || 'ID: ' + user.linked_id);
    }
    <?php else: ?>
    // For campus admin, set campus info
    document.getElementById('linked_id_field').value = <?= $user_campus_id ?>;
    <?php endif; ?>
    
    // Handle photo
    const photoPath = user.profile_photo_path || 'upload/profiles/default.png';
    document.getElementById('existing_photo').value = photoPath;
    document.getElementById('photoPreview').src = '../' + photoPath;
    
    // Update linked fields based on role
    updateLinkedFields();
}

// Photo preview
function previewPhoto(event) {
    const input = event.target;
    const preview = document.getElementById('photoPreview');
    
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        
        // Validate file type
        const file = input.files[0];
        const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        
        if (!validTypes.includes(file.type)) {
            alert('❌ Only JPG, PNG, and GIF images are allowed!');
            input.value = '';
            return;
        }
        
        // Validate file size (2MB)
        if (file.size > 2 * 1024 * 1024) {
            alert('❌ Image size must be less than 2MB!');
            input.value = '';
            return;
        }
        
        reader.onload = function(e) {
            preview.src = e.target.result;
        }
        
        reader.readAsDataURL(input.files[0]);
    } else {
        preview.src = '../' + document.getElementById('existing_photo').value;
    }
}

// Update linked fields based on role (for super admin)
<?php if($is_super_admin): ?>
function updateLinkedFields() {
    const role = document.getElementById('role').value;
    const linkedTable = document.getElementById('linked_table');
    const linkedId = document.getElementById('linked_id');
    
    // Clear previous values
    linkedTable.value = '';
    linkedId.innerHTML = '<option value="">Select from list</option>';
    linkedId.disabled = true;
    document.getElementById('linked_info').textContent = '';
    
    // Suggest appropriate linked table based on role
    switch(role) {
        case 'campus_admin':
            linkedTable.value = 'campus';
            break;
        case 'faculty_admin':
            linkedTable.value = 'faculty';
            break;
        case 'department_admin':
            linkedTable.value = 'department';
            break;
        case 'teacher':
            linkedTable.value = 'teacher';
            break;
        case 'student':
            linkedTable.value = 'student';
            break;
        case 'parent':
            linkedTable.value = 'parent';
            break;
        default:
            linkedTable.value = '';
    }
    
    // If a linked table is selected, enable the linked ID field
    if (linkedTable.value) {
        loadLinkedOptions();
    }
}

function loadLinkedOptions() {
    const linkedTable = document.getElementById('linked_table').value;
    const linkedId = document.getElementById('linked_id');
    const linkedInfo = document.getElementById('linked_info');
    
    if (!linkedTable) {
        linkedId.disabled = true;
        linkedId.innerHTML = '<option value="">Select from list</option>';
        linkedInfo.textContent = '';
        return;
    }
    
    // Enable the field
    linkedId.disabled = false;
    
    // Show loading
    linkedId.innerHTML = '<option value="">Loading...</option>';
    
    // Fetch data via AJAX (simplified - in real app, you'd use fetch/axios)
    setTimeout(() => {
        // This is a simplified version. In a real app, you would:
        // 1. Make an AJAX request to get options based on linkedTable
        // 2. Populate the dropdown with the response
        
        let options = '<option value="">Select ' + linkedTable + '</option>';
        
        switch(linkedTable) {
            case 'campus':
                options += '<option value="1">Main Campus</option>';
                options += '<option value="2">North Campus</option>';
                break;
            case 'faculty':
                options += '<option value="1">Faculty of Engineering</option>';
                options += '<option value="2">Faculty of Medicine</option>';
                break;
            case 'department':
                options += '<option value="1">Computer Science</option>';
                options += '<option value="2">Electrical Engineering</option>';
                break;
            case 'teacher':
                options += '<option value="1">Dr. Ahmed Hassan</option>';
                options += '<option value="2">Prof. Fatima Ali</option>';
                break;
            case 'student':
                options += '<option value="1">Mohamed Abdi (2023-001)</option>';
                options += '<option value="2">Aisha Omar (2023-002)</option>';
                break;
            case 'parent':
                options += '<option value="1">Hassan Mohamed</option>';
                options += '<option value="2">Maryan Ahmed</option>';
                break;
        }
        
        linkedId.innerHTML = options;
        linkedInfo.textContent = 'Select the ' + linkedTable + ' to link this user to';
    }, 500);
}
<?php endif; ?>

// Initialize when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Prevent form resubmission on refresh
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }
    
    // Add keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // ESC to close modals
        if (e.key === 'Escape') {
            const openModals = document.querySelectorAll('.modal.show');
            openModals.forEach(modal => {
                closeModal(modal.id);
            });
        }
        
        // Ctrl+F to focus search
        if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            const searchInput = document.querySelector('input[name="q"]');
            if (searchInput) {
                searchInput.focus();
            }
        }
    });
    
    // Add form submission handlers
    const userForm = document.getElementById('userForm');
    if (userForm) {
        userForm.addEventListener('submit', function(e) {
            if (!validateUserForm()) {
                e.preventDefault();
                return false;
            }
        });
    }
    
    // Auto-hide alerts after 5 seconds
    setTimeout(() => {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            alert.classList.add('hide');
            setTimeout(() => alert.remove(), 300);
        });
    }, 5000);
});
</script>

<?php include('../includes/footer.php'); ?>
<?php ob_end_flush(); ?>