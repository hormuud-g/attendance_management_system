<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// Generate CSRF token
$csrf_token = bin2hex(random_bytes(32));
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = $csrf_token;
}

// Authorization check
if (!isset($_SESSION['user']) || !in_array(strtolower($_SESSION['user']['role']), ['super_admin','admin'])) {
    header("Location: ../login.php");
    exit;
}

$message = "";
$type = "";

/* ============================================================
   VALIDATION FUNCTIONS
============================================================ */
function validatePassword($password) {
    if (strlen($password) < 8) {
        throw new Exception("Password must be at least 8 characters long");
    }
    if (!preg_match('/[A-Z]/', $password)) {
        throw new Exception("Password must contain at least one uppercase letter");
    }
    if (!preg_match('/[a-z]/', $password)) {
        throw new Exception("Password must contain at least one lowercase letter");
    }
    if (!preg_match('/[0-9]/', $password)) {
        throw new Exception("Password must contain at least one number");
    }
    return true;
}

function validateUserInput($data) {
    $errors = [];
    
    if (empty($data['username']) || strlen(trim($data['username'])) < 3) {
        $errors[] = "Username must be at least 3 characters";
    }
    
    if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }
    
    if (!empty($data['phone_number']) && !preg_match('/^\+?[0-9\s\-\(\)]{10,20}$/', $data['phone_number'])) {
        $errors[] = "Invalid phone number format";
    }
    
    $valid_roles = ['super_admin','campus_admin','faculty_admin','department_admin','teacher','student','parent'];
    if (!in_array($data['role'], $valid_roles)) {
        $errors[] = "Invalid role selected";
    }
    
    if (!in_array($data['status'], ['active','inactive'])) {
        $errors[] = "Invalid status selected";
    }
    
    return $errors;
}

/* ============================================================
   CRUD OPERATIONS
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = "❌ Security token invalid!";
        $type = "error";
    } else {
        try {
            $pdo->beginTransaction();

            // 🗑️ BULK DELETE
            if (isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'delete' && isset($_POST['selected_users'])) {
                $selected_users = $_POST['selected_users'];
                $deleted_count = 0;
                
                foreach ($selected_users as $user_id) {
                    $uid = filter_var($user_id, FILTER_VALIDATE_INT);
                    if (!$uid) continue;
                    
                    $check = $pdo->prepare("SELECT role, username FROM users WHERE user_id=?");
                    $check->execute([$uid]);
                    $urow = $check->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$urow) continue;
                    
                    // Skip super_admin
                    if ($urow['role'] === 'super_admin') {
                        continue;
                    }
                    
                    $pdo->prepare("DELETE FROM users WHERE user_id=?")->execute([$uid]);
                    $deleted_count++;
                    
                    // Log deletion
                    error_log("[" . date('Y-m-d H:i:s') . "] User deleted: ID {$uid} ({$urow['username']}) by {$_SESSION['user']['username']}");
                }
                
                $message = "🗑️ {$deleted_count} user(s) deleted successfully!";
            }
            
            // 🗑️ SINGLE DELETE
            elseif (isset($_POST['action']) && $_POST['action'] === 'delete') {
                $uid = filter_var($_POST['user_id'], FILTER_VALIDATE_INT);
                if (!$uid) {
                    throw new Exception("Invalid user ID");
                }
                
                $check = $pdo->prepare("SELECT role, username FROM users WHERE user_id=?");
                $check->execute([$uid]);
                $urow = $check->fetch(PDO::FETCH_ASSOC);
                
                if (!$urow) {
                    throw new Exception("User not found");
                }
                
                if ($urow['role'] === 'super_admin') {
                    throw new Exception("❌ Super Admin cannot be deleted!");
                }
                
                $pdo->prepare("DELETE FROM users WHERE user_id=?")->execute([$uid]);
                $message = "🗑️ User deleted successfully!";
            }

            // ➕ ADD or ✏️ UPDATE
            elseif (isset($_POST['action']) && in_array($_POST['action'], ['add', 'update'])) {
                // Input validation
                $errors = validateUserInput($_POST);
                if (!empty($errors)) {
                    throw new Exception(implode(", ", $errors));
                }
                
                $photo = $_POST['existing_photo'] ?? 'upload/profiles/default.png';
                
                // File upload handling
                if (!empty($_FILES['photo']['name'])) {
                    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    $max_size = 2 * 1024 * 1024; // 2MB
                    
                    if ($_FILES['photo']['size'] > $max_size) {
                        throw new Exception("File size exceeds 2MB limit");
                    }
                    
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime_type = finfo_file($finfo, $_FILES['photo']['tmp_name']);
                    finfo_close($finfo);
                    
                    if (!in_array($mime_type, $allowed_types)) {
                        throw new Exception("Invalid file type. Only JPEG, PNG, GIF, and WebP allowed");
                    }
                    
                    $dir = __DIR__ . '/../upload/profiles/';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    
                    $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                    $name = uniqid('usr_', true) . ".$ext";
                    $target = $dir . $name;
                    
                    if (move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
                        $photo = "upload/profiles/$name";
                        
                        // Delete old photo if exists and not default
                        if (!empty($_POST['existing_photo']) && 
                            $_POST['existing_photo'] !== 'upload/profiles/default.png' &&
                            file_exists(__DIR__ . '/../' . $_POST['existing_photo'])) {
                            @unlink(__DIR__ . '/../' . $_POST['existing_photo']);
                        }
                    } else {
                        throw new Exception("Failed to upload image");
                    }
                }

                // ➕ ADD
                if ($_POST['action'] === 'add') {
                    $plainPass = trim($_POST['password_plain']) ?: bin2hex(random_bytes(4));
                    validatePassword($plainPass);
                    
                    // Check for duplicate username/email
                    $check = $pdo->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
                    $check->execute([$_POST['username'], $_POST['email']]);
                    if ($check->fetch()) {
                        throw new Exception("Username or email already exists");
                    }
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO users (
                            user_uuid, username, email, phone_number, profile_photo_path, 
                            password, password_plain, role, linked_id, linked_table, 
                            status, created_at, created_by
                        )
                        VALUES (UUID(),?,?,?,?,?,?,?,?,?,?,NOW(),?)
                    ");
                    $stmt->execute([
                        $_POST['username'],
                        $_POST['email'] ?: null,
                        $_POST['phone_number'] ?: null,
                        $photo,
                        password_hash($plainPass, PASSWORD_BCRYPT),
                        $plainPass,
                        $_POST['role'],
                        $_POST['linked_id'] ?: null,
                        $_POST['linked_table'] ?: null,
                        $_POST['status'],
                        $_SESSION['user']['user_id']
                    ]);
                    
                    $message = "✅ User added successfully!";
                }

                // ✏️ UPDATE
                elseif ($_POST['action'] === 'update') {
                    $uid = filter_var($_POST['user_id'], FILTER_VALIDATE_INT);
                    if (!$uid) {
                        throw new Exception("Invalid user ID");
                    }
                    
                    $check = $pdo->prepare("SELECT role, username FROM users WHERE user_id=?");
                    $check->execute([$uid]);
                    $urow = $check->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$urow) {
                        throw new Exception("User not found");
                    }
                    
                    if ($urow['role'] === 'super_admin') {
                        throw new Exception("❌ Super Admin cannot be edited!");
                    }
                    
                    $plainPass = trim($_POST['password_plain']);
                    if (!empty($plainPass)) {
                        validatePassword($plainPass);
                        $setPass = ", password=?, password_plain=?";
                        $password_hash = password_hash($plainPass, PASSWORD_BCRYPT);
                    } else {
                        $setPass = "";
                    }
                    
                    // Check for duplicate username/email (excluding current user)
                    $check = $pdo->prepare("SELECT user_id FROM users WHERE (username = ? OR email = ?) AND user_id != ?");
                    $check->execute([$_POST['username'], $_POST['email'], $uid]);
                    if ($check->fetch()) {
                        throw new Exception("Username or email already exists");
                    }
                    
                    $sql = "
                        UPDATE users 
                        SET username=?, email=?, phone_number=?, profile_photo_path=?, role=?, 
                            linked_id=?, linked_table=?, status=?, updated_at=NOW(), updated_at=? 
                            {$setPass}
                        WHERE user_id=?
                    ";
                    
                    $params = [
                        $_POST['username'],
                        $_POST['email'] ?: null,
                        $_POST['phone_number'] ?: null,
                        $photo,
                        $_POST['role'],
                        $_POST['linked_id'] ?: null,
                        $_POST['linked_table'] ?: null,
                        $_POST['status'],
                        $_SESSION['user']['user_id'],
                        $uid
                    ];
                    
                    if (!empty($plainPass)) {
                        array_splice($params, 4, 0, [$password_hash, $plainPass]);
                    }
                    
                    $pdo->prepare($sql)->execute($params);
                    
                    $message = "✏️ User updated successfully!";
                }
            }

            $pdo->commit();
            $type = "success";
            
            // Regenerate CSRF token after successful POST
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "❌ " . $e->getMessage();
            $type = "error";
        }
    }
}

/* ============================================================
   PAGINATION SETUP
============================================================ */
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

/* ============================================================
   FETCH USERS (SEARCH + FILTER) - AUTO HIDE SUPER_ADMIN
============================================================ */
$search = trim($_GET['q'] ?? '');
$role = $_GET['role'] ?? 'all';
$status = $_GET['status'] ?? 'all';

// Fetch users with all details including passwords
$sql = "
    SELECT u.*, s.reg_no
    FROM users u
    LEFT JOIN students s 
        ON u.linked_table='student' 
        AND u.linked_id = s.student_id
    WHERE u.role != 'super_admin'  -- AUTO HIDE SUPER_ADMIN
";
$params = [];

if ($search !== '') {
    $sql .= " AND (u.username LIKE :q OR u.email LIKE :q OR u.phone_number LIKE :q OR s.reg_no LIKE :q)";
    $params['q'] = "%$search%";
}
if ($role !== 'all' && $role !== 'super_admin') {
    $sql .= " AND u.role = :role";
    $params['role'] = $role;
}
if (in_array($status, ['active','inactive'])) {
    $sql .= " AND u.status = :status";
    $params['status'] = $status;
}

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM (" . str_replace("SELECT u.*, s.reg_no", "SELECT u.user_id", $sql) . ") as count_table";
$count_stmt = $pdo->prepare($count_sql);
foreach ($params as $key => $value) {
    $count_stmt->bindValue(":$key", $value);
}
$count_stmt->execute();
$total_users = $count_stmt->fetchColumn();

// Add sorting and pagination
$sql .= " ORDER BY u.user_id DESC LIMIT :limit OFFSET :offset";
$params['limit'] = $limit;
$params['offset'] = $offset;

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $param_type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
    $stmt->bindValue(":$key", $value, $param_type);
}
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

include('../includes/header.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #0072CE;
            --success-color: #00843D;
            --danger-color: #C62828;
            --warning-color: #F57C00;
            --info-color: #17a2b8;
            --light-bg: #f7f9fb;
            --card-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: var(--light-bg);
            color: #333;
            overflow-x: hidden;
        }
        
        body.modal-open {
            overflow: hidden;
        }
        
        .main-content {
            padding: 25px;
            margin-left: 250px;
            margin-top: 90px;
            transition: margin-left 0.3s ease;
            min-height: calc(100vh - 90px);
        }
        
        .sidebar.collapsed ~ .main-content {
            margin-left: 70px;
        }
        
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fff;
            padding: 16px 24px;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .top-bar h2 {
            color: var(--primary-color);
            font-size: 24px;
            font-weight: 600;
        }
        
        .btn {
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
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
            background: var(--primary-color);
            color: #fff;
        }
        
        .btn.green {
            background: var(--success-color);
            color: #fff;
        }
        
        .btn.red {
            background: var(--danger-color);
            color: #fff;
        }
        
        .btn.orange {
            background: var(--warning-color);
            color: #fff;
        }
        
        .btn.info {
            background: var(--info-color);
            color: #fff;
        }
        
        .btn.purple {
            background: #6f42c1;
            color: #fff;
        }
        
        .btn.yellow {
            background: #ffc107;
            color: #333;
        }
        
        /* ======== ALERT/POPUP STYLES ======== */
        .alert {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 16px 24px;
            border-radius: 10px;
            color: #fff;
            z-index: 10001;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            animation: slideInFromRight 0.3s ease;
            max-width: 400px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        @keyframes slideInFromRight {
            from { 
                transform: translateX(100%);
                opacity: 0; 
            }
            to { 
                transform: translateX(0); 
                opacity: 1;
            }
        }
        
        .alert-success {
            background: var(--success-color);
            border-left: 5px solid #006400;
        }
        
        .alert-error {
            background: var(--danger-color);
            border-left: 5px solid #8B0000;
        }
        
        .alert.hide {
            animation: slideOutToRight 0.3s ease forwards;
        }
        
        @keyframes slideOutToRight {
            from { 
                transform: translateX(0); 
                opacity: 1; 
            }
            to { 
                transform: translateX(100%); 
                opacity: 0; 
            }
        }
        
        /* ======== MODAL STYLES ======== */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 0;
            background: rgba(0,0,0,0.5);
            z-index: 10000;
            animation: fadeIn 0.3s ease;
            padding: 20px;
            overflow-y: auto;
        }
        
        .modal.show {
            display: flex;
            align-items: flex-start;
            justify-content: flex-end;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .modal-content {
            background: #fff;
            width: 90%;
            max-width: 800px;
            border-radius: 16px;
            padding: 30px;
            position: relative;
            max-height: 90vh;
            overflow-y: auto;
            margin-top: 50px;
            margin-right: 20px;
            animation: modalSlideInFromRight 0.3s ease;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        
        @keyframes modalSlideInFromRight {
            from { 
                transform: translateX(100px);
                opacity: 0; 
            }
            to { 
                transform: translateX(0); 
                opacity: 1;
            }
        }
        
        .close-modal {
            position: absolute;
            top: 20px;
            right: 20px;
            font-size: 28px;
            cursor: pointer;
            color: #666;
            transition: color 0.3s ease;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            z-index: 10;
        }
        
        .close-modal:hover {
            color: #333;
            background: #f5f5f5;
        }
        
        .modal-content h2 {
            color: var(--primary-color);
            margin-bottom: 25px;
            font-size: 24px;
            font-weight: 600;
        }
        
        .view-modal-content,
        .report-modal-content {
            max-width: 700px;
        }
        
        /* ======== CENTER MODAL STYLES ======== */
        .modal.center-modal {
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(0, 0, 0, 0.7) !important;
        }
        
        .modal.center-modal.show {
            display: flex !important;
        }
        
        .center-modal-content {
            margin: 20px !important;
            animation: centerModalFadeIn 0.3s ease !important;
            max-height: 90vh !important;
            overflow-y: auto !important;
            position: relative !important;
            max-width: 500px !important;
            width: 90% !important;
        }
        
        @keyframes centerModalFadeIn {
            from {
                opacity: 0;
                transform: scale(0.9);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }
        
        .key-manager-container {
            padding: 10px 0;
        }
        
        .stat-number {
            font-size: 24px;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .key-item {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s ease;
        }
        
        .key-item:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .key-info {
            flex: 1;
        }
        
        .key-name {
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }
        
        .key-meta {
            font-size: 12px;
            color: #666;
        }
        
        .key-status {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 10px;
        }
        
        .status-active {
            background: #e8f5e9;
            color: #2e7d32;
        }
        
        .status-expired {
            background: #ffebee;
            color: #c62828;
        }
        
        .key-actions {
            display: flex;
            gap: 5px;
        }
        
        .key-actions button {
            padding: 6px 10px;
            font-size: 12px;
        }
        
        /* ======== RESPONSIVE MODAL ======== */
        @media (max-width: 768px) {
            .modal.show {
                justify-content: center;
                align-items: center;
                padding: 10px;
            }
            
            .modal-content {
                margin: 0;
                width: 95%;
                max-height: 85vh;
                animation: modalSlideInFromBottom 0.3s ease;
            }
            
            .center-modal-content {
                margin: 10px !important;
                width: 95% !important;
            }
            
            @keyframes modalSlideInFromBottom {
                from { 
                    transform: translateY(50px);
                    opacity: 0; 
                }
                to { 
                    transform: translateY(0); 
                    opacity: 1;
                }
            }
        }
        
        /* ======== FORM STYLES ======== */
        .modal-content form {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }
        
        .modal-content form label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #555;
            font-size: 14px;
        }
        
        .modal-content form label.required::after {
            content: " *";
            color: var(--danger-color);
        }
        
        .modal-content form input,
        .modal-content form select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .modal-content form input:focus,
        .modal-content form select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0, 114, 206, 0.1);
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group.full-width {
            grid-column: span 2;
        }
        
        .form-actions {
            grid-column: span 2;
            display: flex;
            gap: 15px;
            margin-top: 10px;
        }
        
        .photo-preview {
            grid-column: span 2;
            text-align: center;
            margin: 20px 0;
        }
        
        .current-photo {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            margin-bottom: 15px;
            border: 5px solid #fff;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        /* ======== SEARCH BAR ======== */
        .search-bar {
            background: #fff;
            padding: 20px;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            margin-bottom: 20px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .search-bar input, .search-bar select {
            padding: 10px 16px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            min-width: 200px;
            flex: 1;
        }
        
        .search-bar input:focus, .search-bar select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0, 114, 206, 0.1);
        }
        
        /* ======== TABLE STYLES ======== */
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            margin-top: 20px;
            max-height: 600px;
            position: relative;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1200px;
        }
        
        thead th {
            position: sticky;
            top: 0;
            background: var(--primary-color);
            color: #fff;
            padding: 16px;
            font-weight: 600;
            text-align: left;
            z-index: 10;
        }
        
        tbody tr {
            border-bottom: 1px solid #eee;
            transition: background 0.3s ease;
        }
        
        tbody tr:hover {
            background: #f5f9ff;
        }
        
        td {
            padding: 14px 16px;
            font-size: 14px;
            vertical-align: middle;
        }
        
        /* ======== BADGES ======== */
        .role-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: capitalize;
        }
        
        .role-admin { background: #4ecdc4; color: white; }
        .role-campus_admin { background: #45b7d1; color: white; }
        .role-faculty_admin { background: #96ceb4; color: white; }
        .role-department_admin { background: #ffeaa7; color: #333; }
        .role-teacher { background: #a29bfe; color: white; }
        .role-student { background: #fd79a8; color: white; }
        .role-parent { background: #dfe6e9; color: #333; }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-active {
            background: #e8f5e9;
            color: #2e7d32;
        }
        
        .status-inactive {
            background: #ffebee;
            color: #c62828;
        }
        
        /* ======== USER PHOTO ======== */
        .user-photo {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        /* ======== ACTION BUTTONS ======== */
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: nowrap;
        }
        
        .action-buttons .btn {
            padding: 8px 12px;
            font-size: 13px;
        }
        
        /* ======== PAGINATION ======== */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 30px;
            padding: 20px;
            flex-wrap: wrap;
        }
        
        .pagination a, .pagination span {
            padding: 10px 16px;
            border-radius: 8px;
            background: #fff;
            color: #333;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            min-width: 45px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .pagination a:hover {
            background: var(--primary-color);
            color: white;
            transform: translateY(-2px);
        }
        
        .pagination .active {
            background: var(--primary-color);
            color: white;
        }
        
        /* ======== PASSWORD FIELD ======== */
        .password-field {
            position: relative;
        }
        
        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #666;
            background: none;
            border: none;
            font-size: 18px;
            z-index: 10;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .password-toggle:hover {
            color: var(--primary-color);
            background: #f5f5f5;
            border-radius: 50%;
        }
        
        .password-field input {
            padding-right: 45px !important;
        }
        
        .password-strength {
            height: 4px;
            margin-top: 8px;
            border-radius: 2px;
            transition: all 0.3s ease;
        }
        
        .strength-weak { background: #ff4757; width: 25%; }
        .strength-fair { background: #ffa502; width: 50%; }
        .strength-good { background: #2ed573; width: 75%; }
        .strength-strong { background: #1e90ff; width: 100%; }
        
        /* ======== USER INFO GRID ======== */
        .user-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .info-card {
            background: #fff;
            padding: 20px;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
        }
        
        .info-card h3 {
            color: var(--primary-color);
            margin-bottom: 15px;
            font-size: 16px;
            font-weight: 600;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        
        .info-item:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 600;
            color: #666;
        }
        
        .info-value {
            color: #333;
        }
        
        /* ======== BULK ACTIONS ======== */
        .bulk-actions {
            background: #fff;
            padding: 15px 20px;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            margin-bottom: 15px;
            display: none;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .bulk-select-all {
            margin-right: 10px;
        }
        
        /* ======== REPORT MODAL ======== */
        .report-options {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin: 20px 0;
        }
        
        .report-option {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .report-option:hover {
            background: #f5f9ff;
            border-color: var(--primary-color);
        }
        
        .report-option input[type="radio"] {
            margin: 0;
        }
        
        /* ======== VIEW MODAL STYLES ======== */
        .user-view-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }
        
        .user-view-photo {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 5px solid #fff;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .user-view-info {
            flex: 1;
        }
        
        .user-view-info h3 {
            font-size: 24px;
            color: var(--primary-color);
            margin-bottom: 8px;
        }
        
        .user-view-info .user-id {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
        }
        
        .user-details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .detail-group {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid var(--primary-color);
        }
        
        .detail-group h4 {
            color: var(--primary-color);
            margin-bottom: 15px;
            font-size: 16px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        
        .detail-item:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            font-weight: 600;
            color: #555;
            min-width: 120px;
        }
        
        .detail-value {
            color: #333;
            text-align: right;
            flex: 1;
        }
        
        .password-display {
            font-family: 'Courier New', monospace;
            background: #f8f9fa;
            padding: 4px 8px;
            border-radius: 4px;
            border: 1px solid #ddd;
            color: #d63384;
            font-weight: bold;
        }
        
        /* ======== ACTIVITY TIMELINE ======== */
        .activity-timeline {
            margin-top: 30px;
        }
        
        .timeline-item {
            display: flex;
            gap: 15px;
            padding: 15px 0;
            border-bottom: 1px solid #eee;
        }
        
        .timeline-item:last-child {
            border-bottom: none;
        }
        
        .timeline-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        
        .timeline-content {
            flex: 1;
        }
        
        .timeline-date {
            color: #666;
            font-size: 12px;
            margin-bottom: 5px;
        }
        
        .timeline-text {
            color: #333;
            font-size: 14px;
        }
        
        /* ======== PASSWORD COLUMN IN TABLE ======== */
        .password-cell {
            font-family: 'Courier New', monospace;
            font-size: 13px;
            background: #f8f9fa;
            padding: 4px 8px;
            border-radius: 4px;
            border: 1px solid #e9ecef;
            color: #d63384;
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .password-cell:hover {
            overflow: visible;
            white-space: normal;
            z-index: 100;
            position: relative;
            background: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        
        /* ======== RESPONSIVE DESIGN ======== */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 70px;
                padding: 20px;
            }
            
            .modal-content form {
                grid-template-columns: 1fr;
            }
            
            .form-group.full-width {
                grid-column: span 1;
            }
            
            .form-actions {
                grid-column: span 1;
            }
            
            .user-details-grid {
                grid-template-columns: 1fr;
            }
            
            table {
                min-width: 1200px;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                margin-top: 70px;
                padding: 15px;
            }
            
            .top-bar {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }
            
            .search-bar {
                flex-direction: column;
            }
            
            .search-bar input, .search-bar select {
                min-width: 100%;
            }
            
            .action-buttons {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .action-buttons .btn {
                padding: 6px 10px;
                font-size: 12px;
            }
            
            .pagination {
                gap: 4px;
            }
            
            .pagination a, .pagination span {
                padding: 8px 12px;
                min-width: 35px;
                font-size: 13px;
            }
            
            .bulk-actions {
                flex-direction: column;
                align-items: stretch;
            }
            
            .user-view-header {
                flex-direction: column;
                text-align: center;
                gap: 15px;
            }
            
            .user-info-grid {
                grid-template-columns: 1fr;
            }
            
            .table-responsive {
                max-height: 500px;
            }
            
            table {
                min-width: 1400px;
            }
        }
        
        /* ======== SCROLLBAR STYLING ======== */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
    </style>
</head>
<body>    
    <div class="main-content">
        <?php if($message): ?>
            <div class="alert <?= $type=='success'?'alert-success':'alert-error' ?>">
                <i class="fas <?= $type=='success'?'fa-check-circle':'fa-exclamation-circle' ?>"></i>
                <?= htmlspecialchars($message) ?>
            </div>
            <script>
                setTimeout(() => {
                    const alert = document.querySelector('.alert');
                    if (alert) {
                        alert.classList.add('hide');
                        setTimeout(() => alert.remove(), 300);
                    }
                }, 4000);
            </script>
        <?php endif; ?>

        <div class="top-bar">
            <h2><i class="fas fa-users-cog"></i> User Management</h2>
            <div class="action-buttons">
                <button class="btn green" onclick="addNewUser()">
                    <i class="fas fa-user-plus"></i> Add User
                </button>
                <button class="btn yellow" onclick="togglePasswordVisibilityInTable()">
                    <i class="fas fa-eye"></i> Show/Hide Passwords
                </button>
                <button class="btn info" onclick="refreshPage()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
        </div>

        <!-- 🔍 FILTER + SEARCH -->
        <form method="GET" class="search-bar">
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" 
                   placeholder="🔍 Search name, email, phone or RegNo...">
            <select name="role">
                <option value="all" <?= $role=='all'?'selected':'' ?>>All Roles</option>
                <option value="campus_admin" <?= $role=='campus_admin'?'selected':'' ?>>Campus Admin</option>
                <option value="faculty_admin" <?= $role=='faculty_admin'?'selected':'' ?>>Faculty Admin</option>
                <option value="department_admin" <?= $role=='department_admin'?'selected':'' ?>>Department Admin</option>
                <option value="teacher" <?= $role=='teacher'?'selected':'' ?>>Teacher</option>
                <option value="student" <?= $role=='student'?'selected':'' ?>>Student</option>
                <option value="parent" <?= $role=='parent'?'selected':'' ?>>Parent</option>
            </select>
            <select name="status">
                <option value="all" <?= $status=='all'?'selected':'' ?>>All Status</option>
                <option value="active" <?= $status=='active'?'selected':'' ?>>Active</option>
                <option value="inactive" <?= $status=='inactive'?'selected':'' ?>>Inactive</option>
            </select>
            <button type="submit" class="btn blue">
                <i class="fas fa-filter"></i> Filter
            </button>
            <a href="?" class="btn" style="background:#666;color:#fff;">
                <i class="fas fa-undo"></i> Reset
            </a>
        </form>

        <!-- Statistics Cards -->
        <div class="user-info-grid">
            <div class="info-card">
                <h3><i class="fas fa-chart-bar"></i> User Statistics</h3>
                <div class="info-item">
                    <span class="info-label">Total Users:</span>
                    <span class="info-value"><?= number_format($total_users) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Active Users:</span>
                    <span class="info-value">
                        <?php 
                        $active_count = 0;
                        foreach ($users as $u) {
                            if ($u['status'] === 'active') $active_count++;
                        }
                        echo $active_count;
                        ?>
                    </span>
                </div>
                <div class="info-item">
                    <span class="info-label">Showing:</span>
                    <span class="info-value"><?= min($limit, count($users)) ?> of <?= $total_users ?></span>
                </div>
        </div>
        </div>

        <!-- BULK ACTIONS -->
        <div class="bulk-actions" id="bulkActions" style="display: none;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <input type="checkbox" id="selectAll" class="bulk-select-all" onchange="toggleSelectAll()">
                <span id="selectedCount">0 users selected</span>
            </div>
            <div style="display: flex; gap: 10px;">
                <form method="POST" id="bulkDeleteForm" style="display: inline;">
                    <input type="hidden" name="bulk_action" value="delete">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="selected_users[]" id="selectedUsersInput">
                    <button type="button" class="btn red" onclick="confirmBulkDelete()">
                        <i class="fas fa-trash"></i> Delete Selected
                    </button>
                </form>
                <button class="btn orange" onclick="bulkActivateUsers()">
                    <i class="fas fa-check-circle"></i> Activate
                </button>
                <button class="btn" onclick="bulkDeactivateUsers()">
                    <i class="fas fa-times-circle"></i> Deactivate
                </button>
                <button class="btn" onclick="clearSelection()">
                    <i class="fas fa-times"></i> Clear
                </button>
            </div>
        </div>

        <!-- TABLE -->
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th width="50">
                            <input type="checkbox" id="selectAllHeader" onchange="toggleSelectAll()">
                        </th>
                        <th>#</th>
                        <th>User Info</th>
                        <th>Contact</th>
                        <th>Password</th>
                        <th>Role & Status</th>
                        <th>Linked Data</th>
                        <th>Activity</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($users as $i=>$u): 
                        $row_num = ($page - 1) * $limit + $i + 1;
                        $showPassword = (strtolower($_SESSION['user']['role']) === 'super_admin');
                    ?>
                    <tr>
                        <td>
                            <input type="checkbox" class="user-checkbox" value="<?= $u['user_id'] ?>" onchange="updateSelection()">
                        </td>
                        <td><?= $row_num ?></td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <img src="../<?= htmlspecialchars($u['profile_photo_path'] ?: 'upload/profiles/default.png') ?>" 
                                     class="user-photo" 
                                     alt="<?= htmlspecialchars($u['username']) ?>">
                                <div>
                                    <strong><?= htmlspecialchars($u['username']) ?></strong><br>
                                    <small style="color:#666;">ID: <?= $u['user_id'] ?></small>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div><i class="fas fa-envelope"></i> <?= htmlspecialchars($u['email'] ?: '—') ?></div>
                            <div><i class="fas fa-phone"></i> <?= htmlspecialchars($u['phone_number'] ?: '—') ?></div>
                        </td>
                        <td>
                            <div class="password-cell" data-password="<?= htmlspecialchars($u['password_plain'] ?? '') ?>">
                                <?php if($showPassword && !empty($u['password_plain'])): ?>
                                    <?= htmlspecialchars($u['password_plain']) ?>
                                <?php elseif($showPassword && empty($u['password_plain'])): ?>
                                    <span style="color:#999; font-style:italic;">No password</span>
                                <?php else: ?>
                                    <span style="color:#666;">••••••••</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span class="role-badge role-<?= str_replace('_', '', $u['role']) ?>">
                                <?= htmlspecialchars(ucwords(str_replace('_', ' ', $u['role']))) ?>
                            </span><br>
                            <span class="status-badge status-<?= $u['status'] ?>">
                                <?= ucfirst($u['status']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if($u['role']==='student' && !empty($u['reg_no'])): ?>
                                <strong>Reg No:</strong> <?= htmlspecialchars($u['reg_no']) ?><br>
                            <?php endif; ?>
                            <?php if($u['linked_table']): ?>
                                <small><?= ucfirst($u['linked_table']) ?>: <?= $u['linked_id'] ?></small>
                            <?php else: ?>
                                <small style="color:#999;">Not linked</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <small>
                                <i class="far fa-calendar-plus"></i> <?= date('M d, Y', strtotime($u['created_at'])) ?>
                            </small>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn blue" onclick='editUser(<?= json_encode($u, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <button type="submit" class="btn red" onclick="return confirmDelete(this.parentElement, '<?= addslashes($u['username']) ?>')">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($users)): ?>
                    <tr>
                        <td colspan="9" style="text-align:center; padding: 40px; color:#777;">
                            <i class="fas fa-user-slash" style="font-size: 48px; margin-bottom: 20px; opacity: 0.5;"></i>
                            <div style="font-size: 18px; margin-bottom: 10px;">No users found</div>
                            <p style="color: #999;">Try adjusting your search or filter criteria</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION -->
        <?php if($total_users > $limit): 
            $total_pages = ceil($total_users / $limit);
            $start_page = max(1, $page - 2);
            $end_page = min($total_pages, $page + 2);
        ?>
        <div class="pagination">
            <?php if($page > 1): ?>
                <a href="?page=1&<?= http_build_query($_GET) ?>">
                    <i class="fas fa-angle-double-left"></i>
                </a>
                <a href="?page=<?= $page-1 ?>&<?= http_build_query($_GET) ?>">
                    <i class="fas fa-chevron-left"></i>
                </a>
            <?php endif; ?>
            
            <?php
            if($start_page > 1) {
                echo '<a href="?page=1&' . http_build_query($_GET) . '">1</a>';
                if($start_page > 2) echo '<span>...</span>';
            }
            
            for($i = $start_page; $i <= $end_page; $i++): ?>
                <a href="?page=<?= $i ?>&<?= http_build_query($_GET) ?>" class="<?= $i==$page?'active':'' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>
            
            <?php
            if($end_page < $total_pages) {
                if($end_page < $total_pages - 1) echo '<span>...</span>';
                echo '<a href="?page=' . $total_pages . '&' . http_build_query($_GET) . '">' . $total_pages . '</a>';
            }
            ?>
            
            <?php if($page < $total_pages): ?>
                <a href="?page=<?= $page+1 ?>&<?= http_build_query($_GET) ?>">
                    <i class="fas fa-chevron-right"></i>
                </a>
                <a href="?page=<?= $total_pages ?>&<?= http_build_query($_GET) ?>">
                    <i class="fas fa-angle-double-right"></i>
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ADD/EDIT USER MODAL -->
    <div class="modal" id="userModal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeModal('userModal')">&times;</span>
            <h2 id="formTitle">Add User</h2>
            
            <div class="photo-preview" id="photoPreview">
                <img src="../upload/profiles/default.png" class="current-photo" id="currentPhoto">
                <div style="margin-top: 10px; color: #666;">Max size: 2MB (JPEG, PNG, GIF, WebP)</div>
            </div>
            
            <form method="POST" enctype="multipart/form-data" id="userForm" onsubmit="return validateForm(this)">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="user_id" id="user_id">
                <input type="hidden" name="existing_photo" id="existing_photo">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                
                <div class="form-group">
                    <label class="required">Username</label>
                    <input type="text" name="username" id="username" required 
                           minlength="3" maxlength="50" autocomplete="off">
                    <small class="form-text" id="usernameHelp"></small>
                </div>
                
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" id="email" autocomplete="off">
                </div>
                
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="tel" name="phone_number" id="phone_number" autocomplete="off">
                </div>
                
                <div class="form-group">
                    <label class="required">Role</label>
                    <select name="role" id="role" required>
                        <option value="">Select Role</option>
                        <option value="campus_admin">Campus Admin</option>
                        <option value="faculty_admin">Faculty Admin</option>
                        <option value="department_admin">Department Admin</option>
                        <option value="teacher">Teacher</option>
                        <option value="student">Student</option>
                        <option value="parent">Parent</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="status">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                
                <div class="form-group password-field">
                    <label class="required" id="passwordLabel">Password</label>
                    <div style="position: relative;">
                        <input type="password" name="password_plain" id="password_plain" 
                               required autocomplete="new-password" class="password-input">
                        <button type="button" class="password-toggle" id="passwordToggle">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="password-strength" id="passwordStrength"></div>
                    <small class="form-text" id="passwordHelp">
                        Min. 8 chars with uppercase, lowercase, and number
                    </small>
                </div>
                
                <div class="form-group">
                    <label>Linked ID</label>
                    <input type="text" name="linked_id" id="linked_id" autocomplete="off">
                </div>
                
                <div class="form-group">
                    <label>Linked Table</label>
                    <select name="linked_table" id="linked_table">
                        <option value="">None</option>
                        <option value="campus">Campus</option>
                        <option value="faculty">Faculty</option>
                        <option value="department">Department</option>
                        <option value="teacher">Teacher</option>
                        <option value="student">Student</option>
                        <option value="parent">Parent</option>
                    </select>
                </div>
                
                <div class="form-group full-width">
                    <label>Profile Photo</label>
                    <input type="file" name="photo" id="photo" accept="image/*" 
                           onchange="previewImage(this)">
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn" onclick="closeModal('userModal')" style="flex:1;">
                        Cancel
                    </button>
                    <button type="submit" class="btn green save-btn" style="flex:1;">
                        <i class="fas fa-save"></i> Save User
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- VIEW USER MODAL -->
    <div class="modal" id="viewModal">
        <div class="modal-content view-modal-content">
            <span class="close-modal" onclick="closeModal('viewModal')">&times;</span>
            
            <div class="user-view-header" id="viewHeader">
                <img src="../upload/profiles/default.png" class="user-view-photo" id="viewPhoto">
                <div class="user-view-info">
                    <h3 id="viewUsername">Username</h3>
                    <div class="user-id">User ID: <span id="viewUserId">-</span></div>
                    <div style="display: flex; gap: 10px; margin-top: 10px;">
                        <span class="role-badge" id="viewRole">Role</span>
                        <span class="status-badge" id="viewStatus">Status</span>
                    </div>
                </div>
            </div>
            
            <div class="user-details-grid">
                <div class="detail-group">
                    <h4><i class="fas fa-user-circle"></i> Personal Information</h4>
                    <div class="detail-item">
                        <span class="detail-label">Username:</span>
                        <span class="detail-value" id="viewUserUsername">-</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Email:</span>
                        <span class="detail-value" id="viewUserEmail">-</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Phone:</span>
                        <span class="detail-value" id="viewUserPhone">-</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">User UUID:</span>
                        <span class="detail-value" id="viewUserUuid">-</span>
                    </div>
                </div>
                
                <div class="detail-group">
                    <h4><i class="fas fa-cog"></i> Account Information</h4>
                    <div class="detail-item">
                        <span class="detail-label">Role:</span>
                        <span class="detail-value" id="viewUserRole">-</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Status:</span>
                        <span class="detail-value" id="viewUserStatus">-</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Linked Table:</span>
                        <span class="detail-value" id="viewUserLinkedTable">-</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Linked ID:</span>
                        <span class="detail-value" id="viewUserLinkedId">-</span>
                    </div>
                </div>
            </div>
            
            <?php if (strtolower($_SESSION['user']['role']) === 'super_admin'): ?>
            <div class="detail-group full-width" style="grid-column: span 2; margin-top: 20px;">
                <h4><i class="fas fa-key"></i> Access - Passwords</h4>
                
                <div class="detail-item">
                    <span class="detail-label">Plain Password:</span>
                    <span class="detail-value">
                        <span class="password-display" id="viewUserPlainPassword">••••••••</span>
                        <button type="button" class="btn purple" onclick="togglePlainPassword()" style="margin-left: 10px; padding: 4px 12px; font-size: 12px;">
                            <i class="fas fa-eye"></i> Show
                        </button>
                        <button type="button" class="btn yellow" onclick="copyPassword('plain')" style="margin-left: 5px; padding: 4px 12px; font-size: 12px;">
                            <i class="fas fa-copy"></i> Copy
                        </button>
                    </span>
                </div>
                
            </div>
            <?php endif; ?>
            
            <div class="activity-timeline">
                <h4><i class="fas fa-timeline"></i> Recent Activity</h4>
                <div class="timeline-item">
                    <div class="timeline-icon">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <div class="timeline-content">
                        <div class="timeline-date" id="viewTimelineDate">-</div>
                        <div class="timeline-text">User account was created</div>
                    </div>
                </div>
            </div>
            
            <div class="form-actions" style="margin-top: 30px;">
                <button type="button" class="btn" onclick="closeModal('viewModal')" style="flex:1;">
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- 🔑 KEY/PASSWORD MANAGER MODAL - CENTERED -->
    <div class="modal center-modal" id="keyManagerModal">
        <div class="modal-content center-modal-content" style="max-width: 500px;">
            <span class="close-modal" onclick="closeModal('keyManagerModal')">&times;</span>
           
            
            <div class="key-manager-container">
                <!-- Key Statistics -->
                <div class="key-stats" style="margin-bottom: 20px;">
                    <div style="display: flex; justify-content: space-around; text-align: center;">
                        <div>
                            <div class="stat-number" id="totalKeys">0</div>
                            <div class="stat-label">Total Keys</div>
                        </div>
                        <div>
                            <div class="stat-number" id="activeKeys">0</div>
                            <div class="stat-label">Active</div>
                        </div>
                        <div>
                            <div class="stat-number" id="expiredKeys">0</div>
                            <div class="stat-label">Expired</div>
                        </div>
                    </div>
                </div>
                
                <!-- API Key Generator -->
                <div class="key-generator" style="margin-bottom: 25px; padding: 20px; background: #f8f9fa; border-radius: 10px;">
                    <h3 style="margin-bottom: 15px; font-size: 16px; color: #333;">
                        <i class="fas fa-plus-circle"></i> Generate New API Key
                    </h3>
                    
                    <form id="generateKeyForm" onsubmit="generateNewKey(event)">
                        <div class="form-group">
                            <label for="keyName">Key Name/Description</label>
                            <input type="text" id="keyName" required 
                                   placeholder="e.g., Mobile App Access" style="width: 100%;">
                        </div>
                        
                        <div class="form-group">
                            <label for="keyExpiry">Expiration</label>
                            <select id="keyExpiry" style="width: 100%;">
                                <option value="30">30 days</option>
                                <option value="90">90 days</option>
                                <option value="365">1 year</option>
                                <option value="0">Never expires</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="keyPermissions">Permissions</label>
                            <select id="keyPermissions" multiple style="width: 100%; height: 80px;">
                                <option value="read">Read Access</option>
                                <option value="write">Write Access</option>
                                <option value="delete">Delete Access</option>
                                <option value="admin">Admin Access</option>
                            </select>
                            <small style="color: #666;">Hold Ctrl/Cmd to select multiple</small>
                        </div>
                        
                        <button type="submit" class="btn green" style="width: 100%; margin-top: 10px;">
                            <i class="fas fa-key"></i> Generate API Key
                        </button>
                    </form>
                </div>
                
                <!-- Generated Key Display -->
                <div class="generated-key" id="generatedKeySection" style="display: none;">
                    <h3 style="margin-bottom: 15px; font-size: 16px; color: #333;">
                        <i class="fas fa-exclamation-triangle"></i> Your New API Key
                    </h3>
                    <div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                        <div style="font-family: monospace; font-size: 14px; word-break: break-all; color: #856404;" id="newApiKey">
                            <!-- API key will appear here -->
                        </div>
                        <div style="margin-top: 10px; font-size: 12px; color: #856404;">
                            <i class="fas fa-info-circle"></i> Save this key now! It won't be shown again.
                        </div>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <button class="btn purple" onclick="copyApiKey()" style="flex: 1;">
                            <i class="fas fa-copy"></i> Copy Key
                        </button>
                        <button class="btn" onclick="downloadApiKey()" style="flex: 1;">
                            <i class="fas fa-download"></i> Download
                        </button>
                    </div>
                </div>
                
                <!-- Existing Keys List -->
                <div class="existing-keys" style="margin-top: 25px;">
                    <h3 style="margin-bottom: 15px; font-size: 16px; color: #333;">
                        <i class="fas fa-list"></i> Existing API Keys
                    </h3>
                    <div id="existingKeysList" style="max-height: 200px; overflow-y: auto;">
                        <!-- Keys will be loaded here -->
                        <div style="text-align: center; padding: 20px; color: #666;">
                            <i class="fas fa-key" style="font-size: 24px; opacity: 0.5; margin-bottom: 10px;"></i>
                            <div>No API keys generated yet</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="form-actions" style="margin-top: 20px;">
                <button type="button" class="btn" onclick="closeModal('keyManagerModal')" style="flex: 1;">
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- REPORT MODAL -->
    

    <script>
        // Global variable to track password visibility
        let showPasswordsInTable = <?= strtolower($_SESSION['user']['role']) === 'super_admin' ? 'true' : 'false' ?>;
        let currentViewUser = null;
        let plainPasswordVisible = false;
        let passwordHashVisible = false;
        
        // Password strength checker
        function checkPasswordStrength(password) {
            let strength = 0;
            const strengthBar = document.getElementById('passwordStrength');
            
            if (!password) {
                if (strengthBar) {
                    strengthBar.className = 'password-strength';
                    strengthBar.style.width = '0%';
                }
                return;
            }
            
            // Length check
            if (password.length >= 8) strength += 25;
            if (password.length >= 12) strength += 15;
            
            // Complexity checks
            if (/[A-Z]/.test(password)) strength += 20;
            if (/[a-z]/.test(password)) strength += 20;
            if (/[0-9]/.test(password)) strength += 20;
            if (/[^A-Za-z0-9]/.test(password)) strength += 20;
            
            // Update strength bar
            if (strengthBar) {
                strengthBar.style.width = Math.min(strength, 100) + '%';
                
                if (strength < 50) {
                    strengthBar.className = 'password-strength strength-weak';
                } else if (strength < 75) {
                    strengthBar.className = 'password-strength strength-fair';
                } else if (strength < 90) {
                    strengthBar.className = 'password-strength strength-good';
                } else {
                    strengthBar.className = 'password-strength strength-strong';
                }
            }
        }
        
        // Toggle password visibility in form
        function togglePasswordVisibility() {
            const passwordField = document.getElementById('password_plain');
            const toggleBtn = document.getElementById('passwordToggle');
            
            if (!passwordField || !toggleBtn) {
                console.log('Password field or toggle button not found');
                return;
            }
            
            const icon = toggleBtn.querySelector('i');
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                if (icon) {
                    icon.className = 'fas fa-eye-slash';
                    icon.title = 'Hide Password';
                }
            } else {
                passwordField.type = 'password';
                if (icon) {
                    icon.className = 'fas fa-eye';
                    icon.title = 'Show Password';
                }
            }
        }
        
        // Toggle password visibility in table
        function togglePasswordVisibilityInTable() {
            showPasswordsInTable = !showPasswordsInTable;
            const passwordCells = document.querySelectorAll('.password-cell');
            const toggleBtn = document.querySelector('.btn.yellow');
            const icon = toggleBtn.querySelector('i');
            
            passwordCells.forEach(cell => {
                const password = cell.getAttribute('data-password');
                if (showPasswordsInTable && password) {
                    cell.innerHTML = `<span style="color:#d63384; font-weight:bold;">${password}</span>
                                     <small style="color:#666; font-size:11px; display:block; margin-top:4px;">
                                          
                                     </small>`;
                } else if (showPasswordsInTable && !password) {
                    cell.innerHTML = '<span style="color:#999; font-style:italic;">No password</span>';
                } else {
                    cell.innerHTML = '<span style="color:#666;">••••••••</span>';
                }
            });
            
            if (showPasswordsInTable) {
                toggleBtn.innerHTML = '<i class="fas fa-eye-slash"></i> Hide Passwords';
                toggleBtn.title = 'Click to hide passwords';
            } else {
                toggleBtn.innerHTML = '<i class="fas fa-eye"></i> Show Passwords';
                toggleBtn.title = 'Click to show passwords (Super Admin only)';
            }
            
            // Store preference in localStorage
            localStorage.setItem('showPasswordsInTable', showPasswordsInTable);
        }
        
        // Toggle plain password in view modal
        function togglePlainPassword() {
            const passwordSpan = document.getElementById('viewUserPlainPassword');
            const toggleBtn = document.querySelector('.btn.purple');
            
            if (!currentViewUser || !currentViewUser.password_plain) return;
            
            if (!plainPasswordVisible) {
                passwordSpan.textContent = currentViewUser.password_plain;
                passwordSpan.style.color = '#d63384';
                plainPasswordVisible = true;
                toggleBtn.innerHTML = '<i class="fas fa-eye-slash"></i> Hide';
            } else {
                passwordSpan.textContent = '••••••••';
                passwordSpan.style.color = '#333';
                plainPasswordVisible = false;
                toggleBtn.innerHTML = '<i class="fas fa-eye"></i> Show';
            }
        }
        
        // Copy password to clipboard
        function copyPassword(type) {
            let textToCopy = '';
            
            if (type === 'plain' && currentViewUser && currentViewUser.password_plain) {
                textToCopy = currentViewUser.password_plain;
            } else if (type === 'hash' && currentViewUser && currentViewUser.password) {
                textToCopy = currentViewUser.password;
            } else {
                alert('No password available to copy');
                return;
            }
            
            navigator.clipboard.writeText(textToCopy).then(() => {
                // Show success message
                const originalText = event.target.innerHTML;
                event.target.innerHTML = '<i class="fas fa-check"></i> Copied!';
                event.target.style.background = '#28a745';
                
                setTimeout(() => {
                    event.target.innerHTML = originalText;
                    event.target.style.background = '';
                }, 2000);
            }).catch(err => {
                console.error('Failed to copy: ', err);
                alert('Failed to copy to clipboard');
            });
        }
        
        // Preview uploaded image
        function previewImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const currentPhoto = document.getElementById('currentPhoto');
                    if (currentPhoto) {
                        currentPhoto.src = e.target.result;
                    }
                }
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        // Modal functions
        function openModal(id) {
            const modal = document.getElementById(id);
            if (modal) {
                modal.classList.add('show');
                document.body.classList.add('modal-open');
                
                // If it's a centered modal, add specific class
                if (modal.classList.contains('center-modal')) {
                    modal.style.display = 'flex';
                }
                
                // Initialize password toggle for user modal
                if (id === 'userModal') {
                    setTimeout(() => {
                        const toggleBtn = document.getElementById('passwordToggle');
                        if (toggleBtn) {
                            // Remove any existing event listeners
                            const newToggleBtn = toggleBtn.cloneNode(true);
                            toggleBtn.parentNode.replaceChild(newToggleBtn, toggleBtn);
                            
                            // Add new event listener
                            document.getElementById('passwordToggle').addEventListener('click', togglePasswordVisibility);
                        }
                    }, 100);
                }
                
                // Trigger custom event
                modal.dispatchEvent(new Event('shown'));
            }
        }
        
        function closeModal(id) {
            const modal = document.getElementById(id);
            if (modal) {
                modal.classList.remove('show');
                document.body.classList.remove('modal-open');
                
                // If it's a centered modal, remove flex display
                if (modal.classList.contains('center-modal')) {
                    modal.style.display = 'none';
                }
                
                // Reset form if it's user modal
                if (id === 'userModal') {
                    resetUserForm();
                }
                // Reset key manager modal
                else if (id === 'keyManagerModal') {
                    document.getElementById('generateKeyForm').reset();
                    document.getElementById('generatedKeySection').style.display = 'none';
                }
                // Reset password visibility in view modal
                else if (id === 'viewModal') {
                    plainPasswordVisible = false;
                    passwordHashVisible = false;
                }
                
                // Trigger custom event
                modal.dispatchEvent(new Event('hidden'));
            }
        }
        
        // Add new user - opens modal with empty form
        function addNewUser() {
            resetUserForm();
            openModal('userModal');
        }
        
        // Reset user form to add mode
        function resetUserForm() {
            const formTitle = document.getElementById('formTitle');
            const formAction = document.getElementById('formAction');
            const userId = document.getElementById('user_id');
            const userForm = document.getElementById('userForm');
            const existingPhoto = document.getElementById('existing_photo');
            const currentPhoto = document.getElementById('currentPhoto');
            const passwordField = document.getElementById('password_plain');
            const passwordLabel = document.getElementById('passwordLabel');
            const passwordHelp = document.getElementById('passwordHelp');
            
            if (formTitle) formTitle.innerText = 'Add User';
            if (formAction) formAction.value = 'add';
            if (userId) userId.value = '';
            if (userForm) userForm.reset();
            if (existingPhoto) existingPhoto.value = '';
            if (currentPhoto) currentPhoto.src = '../upload/profiles/default.png';
            if (passwordField) {
                passwordField.required = true;
                passwordField.type = 'password';
                passwordField.placeholder = '';
                passwordField.value = '';
            }
            if (passwordLabel) passwordLabel.classList.add('required');
            if (passwordHelp) passwordHelp.innerHTML = 'Min. 8 chars with uppercase, lowercase, and number';
            
            // Reset password toggle button
            const toggleBtn = document.getElementById('passwordToggle');
            if (toggleBtn) {
                const icon = toggleBtn.querySelector('i');
                if (icon) {
                    icon.className = 'fas fa-eye';
                    icon.title = 'Show Password';
                }
            }
            
            checkPasswordStrength('');
        }
        
        // View user function - Shows all user details
        function viewUser(user) {
            currentViewUser = user;
            openModal('viewModal');
            
            // Set user photo
            const photoPath = user.profile_photo_path || 'upload/profiles/default.png';
            document.getElementById('viewPhoto').src = '../' + photoPath;
            
            // Set header information
            document.getElementById('viewUsername').textContent = user.username || '-';
            document.getElementById('viewUserId').textContent = user.user_id || '-';
            
            // Set role and status badges
            const roleBadge = document.getElementById('viewRole');
            const statusBadge = document.getElementById('viewStatus');
            
            if (roleBadge) {
                roleBadge.textContent = user.role ? ucwords(user.role.replace('_', ' ')) : '-';
                roleBadge.className = 'role-badge role-' + user.role.replace('_', '');
            }
            
            if (statusBadge) {
                statusBadge.textContent = user.status ? ucfirst(user.status) : '-';
                statusBadge.className = 'status-badge status-' + user.status;
            }
            
            // Set personal information
            document.getElementById('viewUserUsername').textContent = user.username || '-';
            document.getElementById('viewUserEmail').textContent = user.email || '-';
            document.getElementById('viewUserPhone').textContent = user.phone_number || '-';
            document.getElementById('viewUserUuid').textContent = user.user_uuid || '-';
            
            // Set account information
            document.getElementById('viewUserRole').textContent = user.role ? ucwords(user.role.replace('_', ' ')) : '-';
            document.getElementById('viewUserStatus').textContent = user.status ? ucfirst(user.status) : '-';
            document.getElementById('viewUserLinkedTable').textContent = user.linked_table || 'Not linked';
            document.getElementById('viewUserLinkedId').textContent = user.linked_id || '-';
            
            // Set linked data
            document.getElementById('viewUserRegNo').textContent = user.reg_no || '-';
            document.getElementById('viewUserPhotoPath').textContent = user.profile_photo_path || 'Default';
            
            // Set activity log
            document.getElementById('viewUserCreated').textContent = formatDate(user.created_at) || '-';
            document.getElementById('viewUserCreatedBy').textContent = user.created_by || 'System';
            document.getElementById('viewUserUpdated').textContent = formatDate(user.updated_at) || 'Never';
            document.getElementById('viewUserUpdatedBy').textContent = user.updated_at || '-';
            
            // Set timeline
            document.getElementById('viewTimelineDate').textContent = formatDate(user.created_at) || '-';
            
            // Reset password visibility states
            plainPasswordVisible = false;
            passwordHashVisible = false;
            
            // Reset password display
            document.getElementById('viewUserPlainPassword').textContent = '••••••••';
            document.getElementById('viewUserPasswordHash').textContent = '••••••••••••••••••••••••••••••••';
            
            // Reset button texts
            const plainPasswordBtn = document.querySelector('.btn.purple');
            if (plainPasswordBtn) {
                plainPasswordBtn.innerHTML = '<i class="fas fa-eye"></i> Show';
            }
            
            const hashPasswordBtn = document.querySelectorAll('.btn.purple')[1];
            if (hashPasswordBtn) {
                hashPasswordBtn.innerHTML = '<i class="fas fa-eye"></i> Show';
            }
        }
        
        // Helper function to format date
        function formatDate(dateString) {
            if (!dateString) return '-';
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }
        
        // Helper function to capitalize first letter
        function ucfirst(str) {
            return str ? str.charAt(0).toUpperCase() + str.slice(1) : '';
        }
        
        // Helper function to capitalize words
        function ucwords(str) {
            return str ? str.replace(/\b\w/g, char => char.toUpperCase()) : '';
        }
        
        // Edit user function
        function editUser(user) {
            openModal('userModal');
            
            const formTitle = document.getElementById('formTitle');
            const formAction = document.getElementById('formAction');
            const userId = document.getElementById('user_id');
            const username = document.getElementById('username');
            const email = document.getElementById('email');
            const phone = document.getElementById('phone_number');
            const role = document.getElementById('role');
            const status = document.getElementById('status');
            const passwordField = document.getElementById('password_plain');
            const passwordLabel = document.getElementById('passwordLabel');
            const passwordHelp = document.getElementById('passwordHelp');
            const linkedId = document.getElementById('linked_id');
            const linkedTable = document.getElementById('linked_table');
            const existingPhoto = document.getElementById('existing_photo');
            const currentPhoto = document.getElementById('currentPhoto');
            
            if (formTitle) formTitle.innerText = 'Edit User';
            if (formAction) formAction.value = 'update';
            if (userId) userId.value = user.user_id || '';
            if (username) username.value = user.username || '';
            if (email) email.value = user.email || '';
            if (phone) phone.value = user.phone_number || '';
            if (role) role.value = user.role || '';
            if (status) status.value = user.status || 'active';
            if (passwordField) {
                passwordField.value = '';
                passwordField.required = false;
                passwordField.type = 'password';
                passwordField.placeholder = 'Leave blank to keep current';
            }
            if (passwordLabel) passwordLabel.classList.remove('required');
            if (passwordHelp) passwordHelp.innerHTML = 'Leave blank to keep current password<br>Min. 8 chars with uppercase, lowercase, and number';
            if (linkedId) linkedId.value = user.linked_id || '';
            if (linkedTable) linkedTable.value = user.linked_table || '';
            if (existingPhoto) existingPhoto.value = user.profile_photo_path || '';
            
            // Update photo preview
            const photoPath = user.profile_photo_path || 'upload/profiles/default.png';
            if (currentPhoto) currentPhoto.src = '../' + photoPath;
            
            // Reset password toggle button
            const toggleBtn = document.getElementById('passwordToggle');
            if (toggleBtn) {
                const icon = toggleBtn.querySelector('i');
                if (icon) {
                    icon.className = 'fas fa-eye';
                    icon.title = 'Show Password';
                }
            }
            
            checkPasswordStrength('');
        }
        
        // Delete confirmation
        function confirmDelete(form, username) {
            if (confirm(`Are you sure you want to delete user "${username}"?\n\nThis action cannot be undone!`)) {
                const btn = form.querySelector('button[type="submit"]');
                const originalHTML = btn.innerHTML;
                
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
                btn.disabled = true;
                
                // Submit the form
                form.submit();
                
                return true;
            }
            return false;
        }
        
        // Form validation
        function validateForm(form) {
            const requiredFields = form.querySelectorAll('[required]');
            for (let field of requiredFields) {
                if (!field.value.trim()) {
                    alert(`⚠️ Please fill in the "${field.previousElementSibling?.innerText || field.name}" field`);
                    field.focus();
                    return false;
                }
            }
            
            // Password validation only if not edit mode or password field has value
            const password = document.getElementById('password_plain');
            const isEditMode = document.getElementById('formAction')?.value === 'update';
            
            if (password && (password.value || !isEditMode)) {
                if (password.value && password.value.length < 8) {
                    alert('Password must be at least 8 characters long');
                    return false;
                }
                if (password.value && !/[A-Z]/.test(password.value)) {
                    alert('Password must contain at least one uppercase letter');
                    return false;
                }
                if (password.value && !/[a-z]/.test(password.value)) {
                    alert('Password must contain at least one lowercase letter');
                    return false;
                }
                if (password.value && !/[0-9]/.test(password.value)) {
                    alert('Password must contain at least one number');
                    return false;
                }
            }
            
            // Email validation
            const email = document.getElementById('email');
            if (email && email.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)) {
                alert('Please enter a valid email address');
                return false;
            }
            
            return true;
        }
        
        // Bulk selection functions
        let selectedUsers = new Set();
        
        function toggleSelectAll() {
            const checkboxes = document.querySelectorAll('.user-checkbox');
            const selectAll = document.getElementById('selectAllHeader')?.checked || false;
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll;
                if (selectAll) {
                    selectedUsers.add(checkbox.value);
                } else {
                    selectedUsers.delete(checkbox.value);
                }
            });
            
            updateSelection();
        }
        
        function updateSelection() {
            selectedUsers.clear();
            const checkboxes = document.querySelectorAll('.user-checkbox:checked');
            checkboxes.forEach(checkbox => {
                selectedUsers.add(checkbox.value);
            });
            
            const selectedCount = selectedUsers.size;
            const bulkActions = document.getElementById('bulkActions');
            const selectedCountSpan = document.getElementById('selectedCount');
            const selectAllHeader = document.getElementById('selectAllHeader');
            const selectAllCheckbox = document.getElementById('selectAll');
            
            if (selectedCount > 0) {
                if (bulkActions) bulkActions.style.display = 'flex';
                if (selectedCountSpan) selectedCountSpan.textContent = `${selectedCount} user${selectedCount > 1 ? 's' : ''} selected`;
                
                // Update select all checkboxes
                const allCheckboxes = document.querySelectorAll('.user-checkbox');
                const allChecked = allCheckboxes.length === selectedCount;
                if (selectAllHeader) selectAllHeader.checked = allChecked;
                if (selectAllCheckbox) selectAllCheckbox.checked = allChecked;
            } else {
                if (bulkActions) bulkActions.style.display = 'none';
                if (selectAllHeader) selectAllHeader.checked = false;
                if (selectAllCheckbox) selectAllCheckbox.checked = false;
            }
            
            // Update hidden input for form submission
            const selectedUsersInput = document.getElementById('selectedUsersInput');
            if (selectedUsersInput) {
                selectedUsersInput.value = Array.from(selectedUsers).join(',');
            }
        }
        
        function clearSelection() {
            const checkboxes = document.querySelectorAll('.user-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            selectedUsers.clear();
            updateSelection();
        }
        
        function toggleBulkActions() {
            const bulkActions = document.getElementById('bulkActions');
            if (selectedUsers.size > 0) {
                if (bulkActions) {
                    bulkActions.style.display = bulkActions.style.display === 'none' ? 'flex' : 'none';
                }
            } else {
                alert('Please select users first by checking the checkboxes.');
            }
        }
        
        function confirmBulkDelete() {
            if (selectedUsers.size === 0) {
                alert('Please select at least one user to delete.');
                return;
            }
            
            if (confirm(`Are you sure you want to delete ${selectedUsers.size} user${selectedUsers.size > 1 ? 's' : ''}?\n\nThis action cannot be undone!`)) {
                // Prepare the form data
                const form = document.getElementById('bulkDeleteForm');
                const input = document.getElementById('selectedUsersInput');
                if (form && input) {
                    input.value = Array.from(selectedUsers).join(',');
                    form.submit();
                }
            }
        }
        
        function bulkActivateUsers() {
            if (selectedUsers.size === 0) {
                alert('Please select users first.');
                return;
            }
            
            if (confirm(`Activate ${selectedUsers.size} user${selectedUsers.size > 1 ? 's' : ''}?`)) {
                alert('Bulk activate feature would be implemented here. Requires backend support.');
                // Implementation would require AJAX or form submission
            }
        }
        
        function bulkDeactivateUsers() {
            if (selectedUsers.size === 0) {
                alert('Please select users first.');
                return;
            }
            
            if (confirm(`Deactivate ${selectedUsers.size} user${selectedUsers.size > 1 ? 's' : ''}?`)) {
                alert('Bulk deactivate feature would be implemented here. Requires backend support.');
                // Implementation would require AJAX or form submission
            }
        }
        
        // KEY MANAGER FUNCTIONS
        
        // Open Key Manager Modal
        function openKeyManager() {
            // Load existing keys first
            loadExistingKeys();
            updateKeyStats();
            updateQuickApiKeys();
            // Open modal
            openModal('keyManagerModal');
        }
        
        // Generate New API Key
        function generateNewKey(event) {
            event.preventDefault();
            
            const keyName = document.getElementById('keyName').value;
            const expiryDays = parseInt(document.getElementById('keyExpiry').value);
            const permissions = Array.from(document.getElementById('keyPermissions').selectedOptions)
                .map(opt => opt.value);
            
            // Generate a random API key (in production, this should be generated server-side)
            const apiKey = 'sk_' + Math.random().toString(36).substr(2, 20) + 
                           Math.random().toString(36).substr(2, 20);
            
            // Display the generated key
            document.getElementById('newApiKey').textContent = apiKey;
            document.getElementById('generatedKeySection').style.display = 'block';
            
            // Save to localStorage (in production, save to database via AJAX)
            const keys = JSON.parse(localStorage.getItem('apiKeys') || '[]');
            const newKey = {
                id: Date.now(),
                name: keyName,
                key: apiKey,
                created: new Date().toISOString(),
                expires: expiryDays > 0 ? 
                    new Date(Date.now() + (expiryDays * 24 * 60 * 60 * 1000)).toISOString() : 
                    null,
                permissions: permissions,
                status: 'active'
            };
            
            keys.push(newKey);
            localStorage.setItem('apiKeys', JSON.stringify(keys));
            
            // Update stats
            updateKeyStats();
            updateQuickApiKeys();
            loadExistingKeys();
            
            // Clear form
            document.getElementById('generateKeyForm').reset();
            
            // Scroll to show generated key
            document.getElementById('generatedKeySection').scrollIntoView({ behavior: 'smooth' });
        }
        
        // Copy API Key to clipboard
        function copyApiKey() {
            const keyText = document.getElementById('newApiKey').textContent;
            navigator.clipboard.writeText(keyText).then(() => {
                alert('API key copied to clipboard!');
            });
        }
        
        // Download API Key as text file
        function downloadApiKey() {
            const keyText = document.getElementById('newApiKey').textContent;
            const keyName = document.getElementById('keyName').value || 'api_key';
            const blob = new Blob([keyText], { type: 'text/plain' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `${keyName.replace(/\s+/g, '_')}_api_key.txt`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
        
        // Load existing keys
        function loadExistingKeys() {
            const keys = JSON.parse(localStorage.getItem('apiKeys') || '[]');
            const container = document.getElementById('existingKeysList');
            
            if (keys.length === 0) {
                container.innerHTML = `
                    <div style="text-align: center; padding: 20px; color: #666;">
                        <i class="fas fa-key" style="font-size: 24px; opacity: 0.5; margin-bottom: 10px;"></i>
                        <div>No API keys generated yet</div>
                    </div>
                `;
                return;
            }
            
            let html = '';
            keys.forEach(key => {
                const isExpired = key.expires && new Date(key.expires) < new Date();
                const statusClass = isExpired ? 'status-expired' : 'status-active';
                const statusText = isExpired ? 'Expired' : 'Active';
                
                html += `
                    <div class="key-item">
                        <div class="key-info">
                            <div class="key-name">${key.name}</div>
                            <div class="key-meta">
                                Created: ${new Date(key.created).toLocaleDateString()}
                                ${key.expires ? ' | Expires: ' + new Date(key.expires).toLocaleDateString() : ''}
                            </div>
                        </div>
                        <div class="key-status ${statusClass}">${statusText}</div>
                        <div class="key-actions">
                            <button class="btn info" onclick="viewKeyDetails(${key.id})" title="View Details">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="btn red" onclick="revokeKey(${key.id})" title="Revoke Key">
                                <i class="fas fa-ban"></i>
                            </button>
                        </div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }
        
        // Update key statistics
        function updateKeyStats() {
            const keys = JSON.parse(localStorage.getItem('apiKeys') || '[]');
            const activeKeys = keys.filter(k => {
                if (k.expires) {
                    return new Date(k.expires) > new Date();
                }
                return true;
            }).length;
            
            const totalKeysEl = document.getElementById('totalKeys');
            const activeKeysEl = document.getElementById('activeKeys');
            const expiredKeysEl = document.getElementById('expiredKeys');
            
            if (totalKeysEl) totalKeysEl.textContent = keys.length;
            if (activeKeysEl) activeKeysEl.textContent = activeKeys;
            if (expiredKeysEl) expiredKeysEl.textContent = keys.length - activeKeys;
        }
        
        // Update quick API keys in statistics card
        function updateQuickApiKeys() {
            const keys = JSON.parse(localStorage.getItem('apiKeys') || '[]');
            const activeKeys = keys.filter(k => {
                if (k.expires) {
                    return new Date(k.expires) > new Date();
                }
                return true;
            }).length;
            
            const quickApiKeysEl = document.getElementById('quickApiKeys');
            if (quickApiKeysEl) {
                quickApiKeysEl.textContent = `${activeKeys} active`;
            }
        }
        
        // View key details
        function viewKeyDetails(keyId) {
            const keys = JSON.parse(localStorage.getItem('apiKeys') || '[]');
            const key = keys.find(k => k.id === keyId);
            
            if (!key) {
                alert('Key not found!');
                return;
            }
            
            const details = `
Key Name: ${key.name}
API Key: ${key.key}
Created: ${new Date(key.created).toLocaleString()}
${key.expires ? 'Expires: ' + new Date(key.expires).toLocaleString() : 'Never Expires'}
Permissions: ${key.permissions.join(', ')}
Status: ${key.expires && new Date(key.expires) < new Date() ? 'Expired' : 'Active'}
            `;
            
            alert(details);
        }
        
        // Revoke/Delete key
        function revokeKey(keyId) {
            if (!confirm('Are you sure you want to revoke this API key? This action cannot be undone.')) {
                return;
            }
            
            let keys = JSON.parse(localStorage.getItem('apiKeys') || '[]');
            keys = keys.filter(k => k.id !== keyId);
            localStorage.setItem('apiKeys', JSON.stringify(keys));
            
            loadExistingKeys();
            updateKeyStats();
            updateQuickApiKeys();
        }
        
        // Generate report function
        function generateReport() {
            const reportType = document.querySelector('input[name="report_type"]:checked');
            const format = document.getElementById('report_format');
            
            if (!reportType || !format) {
                alert('Report configuration error');
                return;
            }
            
            // Create report popup
            const reportWindow = window.open('', 'Report', 'width=800,height=600,scrollbars=yes');
            
            // Simple report content
            let reportContent = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>User Management Report</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 40px; }
                        h1 { color: #0072CE; border-bottom: 2px solid #0072CE; padding-bottom: 10px; }
                        .report-header { display: flex; justify-content: space-between; margin-bottom: 30px; }
                        .report-info { color: #666; font-size: 14px; }
                        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                        th { background: #0072CE; color: white; padding: 12px; text-align: left; }
                        td { padding: 10px 12px; border-bottom: 1px solid #ddd; }
                        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; font-size: 12px; text-align: center; }
                    </style>
                </head>
                <body>
                    <div class="report-header">
                        <h1>User Management Report</h1>
                        <div class="report-info">
                            <div>Generated: ${new Date().toLocaleString()}</div>
                            <div>Report Type: ${reportType.value.replace(/_/g, ' ')}</div>
                        </div>
                    </div>
                    <h2>Report Generated Successfully</h2>
                    <p>This is a preview of the ${reportType.value.replace(/_/g, ' ')} report.</p>
                    <p>Total Users: <?= $total_users ?></p>
                    <div class="footer">
                        <p>Report generated by Attendance Management System</p>
                        <p>Page 1 of 1</p>
                    </div>
                </body>
                </html>
            `;
            
            // Write content to popup
            reportWindow.document.write(reportContent);
            reportWindow.document.close();
            
            // Close modal
            closeModal('reportModal');
            
            // If PDF format is selected, trigger print dialog
            if (format.value === 'pdf') {
                setTimeout(() => {
                    reportWindow.print();
                }, 500);
            }
        }
        
        // Refresh page
        function refreshPage() {
            window.location.reload();
        }
        
        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Page loaded, initializing...');
            
            // Load password visibility preference
            const savedPreference = localStorage.getItem('showPasswordsInTable');
            if (savedPreference !== null) {
                showPasswordsInTable = (savedPreference === 'true');
            }
            
            // Apply password visibility if Super Admin
            const isSuperAdmin = <?= strtolower($_SESSION['user']['role']) === 'super_admin' ? 'true' : 'false' ?>;
            if (isSuperAdmin && showPasswordsInTable) {
                togglePasswordVisibilityInTable();
            }
            
            // Initialize password strength checker
            const passwordField = document.getElementById('password_plain');
            if (passwordField) {
                console.log('Password field found');
                passwordField.addEventListener('input', function(e) {
                    checkPasswordStrength(e.target.value);
                });
                
                // Initialize password toggle button
                const toggleBtn = document.getElementById('passwordToggle');
                if (toggleBtn) {
                    console.log('Password toggle button found');
                    toggleBtn.addEventListener('click', togglePasswordVisibility);
                    
                    // Set initial icon
                    const icon = toggleBtn.querySelector('i');
                    if (icon) {
                        icon.className = 'fas fa-eye';
                        icon.title = 'Show Password';
                    }
                } else {
                    console.error('Password toggle button not found');
                }
            } else {
                console.log('Password field not found (modal not open yet)');
            }
            
            // Initialize checkboxes
            const checkboxes = document.querySelectorAll('.user-checkbox');
            console.log(`Found ${checkboxes.length} user checkboxes`);
            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', updateSelection);
            });
            
            // Initialize select all checkbox
            const selectAllHeader = document.getElementById('selectAllHeader');
            if (selectAllHeader) {
                selectAllHeader.addEventListener('change', toggleSelectAll);
            }
            
            // Initialize bulk actions select all
            const selectAllCheckbox = document.getElementById('selectAll');
            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', toggleSelectAll);
            }
            
            // Update quick API keys on load
            updateQuickApiKeys();
            
            // Initialize key manager modal events
            const keyManagerModal = document.getElementById('keyManagerModal');
            if (keyManagerModal) {
                keyManagerModal.addEventListener('shown', function() {
                    updateKeyStats();
                    loadExistingKeys();
                });
            }
            
            // Add click handler for Add User button
            const addUserBtn = document.querySelector('.btn.green');
            if (addUserBtn && addUserBtn.textContent.includes('Add User')) {
                addUserBtn.onclick = addNewUser;
            }
            
            // Initialize modal close buttons
            const closeButtons = document.querySelectorAll('.close-modal');
            closeButtons.forEach(btn => {
                btn.onclick = function() {
                    const modal = this.closest('.modal');
                    if (modal) {
                        closeModal(modal.id);
                    }
                };
            });
        });
        
        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const modals = document.querySelectorAll('.modal.show');
                modals.forEach(modal => {
                    closeModal(modal.id);
                });
            }
        });
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal.show');
            modals.forEach(modal => {
                if (event.target === modal) {
                    closeModal(modal.id);
                }
            });
        }
        
        // Auto-hide alerts after 4 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.classList.add('hide');
                setTimeout(() => alert.remove(), 300);
            });
        }, 4000);
    </script>

</body>
</html>
<?php ob_end_flush(); ?>