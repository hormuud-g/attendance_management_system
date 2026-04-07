<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Access Control - Super Admin & Admins Only
$allowed_roles = ['super_admin', 'admin'];
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], $allowed_roles)) {
    header("Location: ../login.php");
    exit;
}

$message = "";
$type = "";

/* ===========================================
   FUNCTIONS FOR AUTO-NOTIFICATIONS
=========================================== */

/**
 * Create notifications for users based on target role
 */
function createNotificationsForAnnouncement($pdo, $announcement_id, $title, $message, $target_role, $created_by) {
    try {
        // Get users based on target role
        $users_to_notify = [];
        
        if ($target_role === 'all_users') {
            // All users except deleted ones
            $stmt = $pdo->query("SELECT user_id FROM users WHERE status = 'active'");
            $users_to_notify = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } elseif ($target_role === 'student' || $target_role === 'teacher' || $target_role === 'parent') {
            // Get from specific tables
            $table = $target_role . 's'; // students, teachers, parents
            $stmt = $pdo->query("SELECT user_id FROM $table WHERE status = 'active'");
            $users_to_notify = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            // For other roles (admin, faculty, department, campus)
            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE role = ? AND status = 'active'");
            $stmt->execute([$target_role]);
            $users_to_notify = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        
        // Insert notifications for each user
        $notification_count = 0;
        $notification_stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, read_status, announcement_id, created_at)
            VALUES (?, ?, ?, 'unread', ?, NOW())
        ");
        
        // Truncate message for notification (max 255 chars)
        $notification_message = (strlen($message) > 200) ? substr($message, 0, 200) . '...' : $message;
        
        foreach ($users_to_notify as $user_id) {
            $notification_stmt->execute([$user_id, $title, $notification_message, $announcement_id]);
            $notification_count++;
        }
        
        return $notification_count;
    } catch (Exception $e) {
        error_log("Notification creation error: " . $e->getMessage());
        return 0;
    }
}

/* ===========================================
   CRUD OPERATIONS
=========================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $pdo->beginTransaction();

        // 🟢 ADD ANNOUNCEMENT WITH AUTO-NOTIFICATIONS
        if ($_POST['action'] === 'add') {
            // Upload image if exists
            $photo = null;
            if (!empty($_FILES['image']['name'])) {
                $dir = __DIR__ . '/../upload/announcements/';
                if (!is_dir($dir)) mkdir($dir, 0777, true);
                $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                
                if (!in_array($ext, $allowed_ext)) {
                    throw new Exception("Only JPG, PNG, GIF, and WEBP images are allowed");
                }
                
                $name = uniqid('ann_') . ".$ext";
                $target_file = $dir . $name;
                
                if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
                    $photo = "upload/announcements/$name";
                } else {
                    throw new Exception("Failed to upload image");
                }
            }

            // Insert announcement
            $stmt = $pdo->prepare("
                INSERT INTO announcement (title, message, image_path, target_role, status, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $_POST['title'],
                $_POST['message'],
                $photo,
                $_POST['target_role'],
                $_POST['status'],
                $_SESSION['user']['user_id']
            ]);

            $announcement_id = $pdo->lastInsertId();

            // 🎯 AUTO-CREATE NOTIFICATIONS FOR TARGET USERS
            if ($_POST['status'] === 'active') {
                $notification_count = createNotificationsForAnnouncement(
                    $pdo, 
                    $announcement_id, 
                    $_POST['title'], 
                    $_POST['message'], 
                    $_POST['target_role'],
                    $_SESSION['user']['user_id']
                );
                
                $message = "✅ Announcement added successfully! Notifications sent to {$notification_count} users.";
            } else {
                $message = "✅ Announcement added successfully! (No notifications sent - status is inactive)";
            }
            
            $type = "success";
        }

        // ✏️ UPDATE ANNOUNCEMENT
        if ($_POST['action'] === 'update') {
            $id = $_POST['announcement_id'];
            
            // Get current announcement data
            $stmt = $pdo->prepare("SELECT target_role, status, image_path FROM announcement WHERE announcement_id = ?");
            $stmt->execute([$id]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $photo = $_POST['existing_image'] ?? null;
            if (!empty($_FILES['image']['name'])) {
                $dir = __DIR__ . '/../upload/announcements/';
                if (!is_dir($dir)) mkdir($dir, 0777, true);
                $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                $name = uniqid('ann_') . ".$ext";
                $photo = "upload/announcements/$name";
                move_uploaded_file($_FILES['image']['tmp_name'], __DIR__ . '/../' . $photo);
                
                // Delete old image if exists
                if ($current['image_path'] && file_exists(__DIR__ . '/../' . $current['image_path'])) {
                    unlink(__DIR__ . '/../' . $current['image_path']);
                }
            }

            $stmt = $pdo->prepare("
                UPDATE announcement 
                SET title=?, message=?, image_path=?, target_role=?, status=?, updated_at=NOW() 
                WHERE announcement_id=?
            ");
            $stmt->execute([
                $_POST['title'],
                $_POST['message'],
                $photo,
                $_POST['target_role'],
                $_POST['status'],
                $id
            ]);

            // 🎯 IF STATUS CHANGED TO ACTIVE, CREATE NEW NOTIFICATIONS
            if ($current['status'] !== 'active' && $_POST['status'] === 'active') {
                // Delete old notifications for this announcement
                $pdo->prepare("DELETE FROM notifications WHERE announcement_id = ?")->execute([$id]);
                
                // Create new notifications
                $notification_count = createNotificationsForAnnouncement(
                    $pdo, 
                    $id, 
                    $_POST['title'], 
                    $_POST['message'], 
                    $_POST['target_role'],
                    $_SESSION['user']['user_id']
                );
                
                $message = "✏️ Announcement updated! Resent notifications to {$notification_count} users.";
            } else {
                $message = "✏️ Announcement updated successfully!";
            }
            
            $type = "success";
        }

        // 🔴 DELETE ANNOUNCEMENT (WITH NOTIFICATIONS)
        if ($_POST['action'] === 'delete') {
            $announcement_id = $_POST['announcement_id'];
            
            // Get image path to delete file
            $stmt = $pdo->prepare("SELECT image_path FROM announcement WHERE announcement_id = ?");
            $stmt->execute([$announcement_id]);
            $announcement = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Delete image file if exists
            if ($announcement['image_path'] && file_exists(__DIR__ . '/../' . $announcement['image_path'])) {
                unlink(__DIR__ . '/../' . $announcement['image_path']);
            }
            
            // First delete notifications (cascade or manual)
            $pdo->prepare("DELETE FROM notifications WHERE announcement_id = ?")->execute([$announcement_id]);
            
            // Then delete announcement
            $pdo->prepare("DELETE FROM announcement WHERE announcement_id = ?")->execute([$announcement_id]);
            
            $message = "🗑️ Announcement and associated notifications deleted successfully!";
            $type = "success";
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "❌ " . $e->getMessage();
        $type = "error";
    }
}

/* ===========================================
   SEARCH + FILTER
=========================================== */
$where = [];
$params = [];

if (!empty($_GET['search'])) {
    $where[] = "(a.title LIKE ? OR a.message LIKE ?)";
    $params[] = "%{$_GET['search']}%";
    $params[] = "%{$_GET['search']}%";
}

if (!empty($_GET['role'])) {
    $where[] = "a.target_role = ?";
    $params[] = $_GET['role'];
}

if (!empty($_GET['status'])) {
    $where[] = "a.status = ?";
    $params[] = $_GET['status'];
}

$sql = "
    SELECT a.*, u.username AS created_by_name,
           (SELECT COUNT(*) FROM notifications WHERE announcement_id = a.announcement_id) AS notification_count
    FROM announcement a
    LEFT JOIN users u ON u.user_id = a.created_by
";

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY a.announcement_id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Announcements Management | Hormuud University</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="../images.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
    :root {
        --green: #00843D;
        --blue: #0072CE;
        --red: #C62828;
        --orange: #FF9800;
        --purple: #7B1FA2;
        --bg: #F5F9F7;
    }
    
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: 'Poppins', sans-serif;
        background: var(--bg);
        line-height: 1.6;
    }
    
    .main-content {
        padding: 20px;
        margin-top: 80px;
        margin-left: 250px;
        transition: all 0.3s ease;
    }
    
    .sidebar.collapsed ~ .main-content {
        margin-left: 70px;
    }
    
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        padding-bottom: 15px;
        border-bottom: 2px solid #e0e0e0;
    }
    
    .page-header h1 {
        color: var(--blue);
        font-size: 28px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .add-btn {
        background: linear-gradient(135deg, var(--green), #00A651);
        color: #fff;
        padding: 12px 24px;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: all 0.3s ease;
        box-shadow: 0 4px 12px rgba(0, 132, 61, 0.2);
    }
    
    .add-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0, 132, 61, 0.3);
        background: linear-gradient(135deg, #00A651, var(--green));
    }
    
    /* ✅ FILTER BAR */
    .filter-bar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 12px;
        background: #fff;
        padding: 18px;
        border-radius: 12px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        margin-bottom: 25px;
        border: 1px solid #eaeaea;
    }
    
    .filter-bar input[type="text"],
    .filter-bar select {
        padding: 10px 15px;
        border: 1.5px solid #ddd;
        border-radius: 8px;
        font-size: 14px;
        min-width: 180px;
        outline: none;
        transition: all 0.3s;
        background: #fafafa;
    }
    
    .filter-bar input:focus,
    .filter-bar select:focus {
        border-color: var(--blue);
        background: #fff;
        box-shadow: 0 0 0 3px rgba(0, 114, 206, 0.1);
    }
    
    .filter-bar button,
    .filter-bar a {
        border: none;
        border-radius: 8px;
        padding: 10px 18px;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        white-space: nowrap;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: all 0.3s;
    }
    
    .filter-bar button {
        background: linear-gradient(135deg, var(--blue), #2196F3);
        color: #fff;
    }
    
    .filter-bar button:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 114, 206, 0.3);
    }
    
    .filter-bar a {
        background: #e0e0e0;
        color: #333;
    }
    
    .filter-bar a:hover {
        background: #d0d0d0;
        transform: translateY(-2px);
    }
    
    /* ✅ TABLE CONTAINER */
    .table-container {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        overflow: hidden;
        border: 1px solid #eaeaea;
    }
    
    .table-wrapper {
        overflow-x: auto;
        max-height: 550px;
    }
    
    table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        min-width: 1000px;
    }
    
    thead {
        position: sticky;
        top: 0;
        z-index: 10;
    }
    
    thead th {
        background: linear-gradient(135deg, var(--blue), #2196F3);
        color: #fff;
        font-size: 14px;
        padding: 16px;
        text-align: left;
        font-weight: 600;
        border-bottom: 3px solid #005bb5;
        position: relative;
    }
    
    thead th:after {
        content: '';
        position: absolute;
        bottom: -3px;
        left: 0;
        width: 100%;
        height: 3px;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
    }
    
    th, td {
        padding: 14px 16px;
        border-bottom: 1px solid #eee;
        font-size: 14px;
        vertical-align: middle;
        transition: background-color 0.2s;
    }
    
    tbody tr {
        transition: all 0.2s;
    }
    
    tbody tr:hover {
        background: linear-gradient(90deg, rgba(238, 248, 240, 0.8), rgba(227, 242, 253, 0.8));
        transform: scale(1.001);
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    
    /* ✅ STATUS BADGES */
    .status-badge {
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        display: inline-block;
        min-width: 80px;
        text-align: center;
    }
    
    .status-active {
        background: linear-gradient(135deg, #e8f5e8, #c8e6c9);
        color: var(--green);
        border: 1px solid #a5d6a7;
    }
    
    .status-inactive {
        background: linear-gradient(135deg, #ffebee, #ffcdd2);
        color: var(--red);
        border: 1px solid #ef9a9a;
    }
    
    /* ✅ TARGET ROLE BADGES */
    .target-badge {
        padding: 5px 12px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        display: inline-block;
        min-width: 90px;
        text-align: center;
    }
    
    .badge-student { background: #e3f2fd; color: #1565c0; border: 1px solid #90caf9; }
    .badge-teacher { background: #f3e5f5; color: #7b1fa2; border: 1px solid #ce93d8; }
    .badge-parent { background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; }
    .badge-admin { background: #fff3e0; color: #ef6c00; border: 1px solid #ffcc80; }
    .badge-campus { background: #e0f7fa; color: #006064; border: 1px solid #80deea; }
    .badge-faculty { background: #fce4ec; color: #ad1457; border: 1px solid #f48fb1; }
    .badge-department { background: #f3e5f5; color: #6a1b9a; border: 1px solid #ce93d8; }
    .badge-all_users { background: #e8eaf6; color: #3949ab; border: 1px solid #9fa8da; }
    
    /* ✅ NOTIFICATION BADGE */
    .notification-badge {
        background: linear-gradient(135deg, var(--orange), #ff9800);
        color: white;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        margin-left: 6px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        box-shadow: 0 2px 4px rgba(255, 152, 0, 0.3);
    }
    
    /* ✅ ACTION BUTTONS */
    .action-buttons {
        display: flex;
        gap: 8px;
        justify-content: center;
    }
    
    .btn-view, .btn-edit, .btn-delete {
        padding: 8px 12px;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-size: 13px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.3s;
        min-width: 40px;
        justify-content: center;
    }
    
    .btn-view {
        background: linear-gradient(135deg, var(--orange), #ffb74d);
        color: white;
    }
    
    .btn-edit {
        background: linear-gradient(135deg, var(--blue), #42a5f5);
        color: white;
    }
    
    .btn-delete {
        background: linear-gradient(135deg, var(--red), #ef5350);
        color: white;
    }
    
    .btn-view:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(255, 152, 0, 0.3);
    }
    
    .btn-edit:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 114, 206, 0.3);
    }
    
    .btn-delete:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(198, 40, 40, 0.3);
    }
    
    /* ✅ IMAGE IN TABLE */
    .announcement-image {
        width: 60px;
        height: 40px;
        object-fit: cover;
        border-radius: 6px;
        border: 2px solid #e0e0e0;
        transition: all 0.3s;
    }
    
    .announcement-image:hover {
        transform: scale(1.8);
        border-color: var(--blue);
        box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        z-index: 5;
        position: relative;
    }
    
    /* ✅ MODAL STYLES */
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.7);
        justify-content: center;
        align-items: center;
        z-index: 1000;
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
        max-width: 850px;
        max-height: 90vh;
        overflow-y: auto;
        padding: 30px;
        position: relative;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        animation: slideUp 0.3s ease;
    }
    
    @keyframes slideUp {
        from { transform: translateY(50px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }
    
    .close-modal {
        position: absolute;
        top: 20px;
        right: 25px;
        font-size: 28px;
        cursor: pointer;
        color: #666;
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        transition: all 0.3s;
    }
    
    .close-modal:hover {
        background: #f0f0f0;
        color: #000;
        transform: rotate(90deg);
    }
    
    .modal h2 {
        color: var(--blue);
        margin-bottom: 25px;
        padding-bottom: 15px;
        border-bottom: 2px solid #f0f0f0;
        font-size: 24px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    /* ✅ FORM STYLES */
    .form-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
        margin-top: 20px;
    }
    
    .form-group {
        margin-bottom: 15px;
    }
    
    .form-group.full-width {
        grid-column: 1 / -1;
    }
    
    label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: var(--blue);
        font-size: 14px;
    }
    
    label.required:after {
        content: ' *';
        color: var(--red);
    }
    
    input[type="text"],
    input[type="file"],
    select,
    textarea {
        width: 100%;
        padding: 12px 15px;
        border: 2px solid #e0e0e0;
        border-radius: 8px;
        font-size: 14px;
        font-family: 'Poppins', sans-serif;
        transition: all 0.3s;
        background: #fafafa;
    }
    
    input[type="text"]:focus,
    select:focus,
    textarea:focus {
        border-color: var(--blue);
        background: #fff;
        box-shadow: 0 0 0 3px rgba(0, 114, 206, 0.15);
        outline: none;
    }
    
    textarea {
        resize: vertical;
        min-height: 120px;
        line-height: 1.5;
    }
    
    .save-btn {
        grid-column: 1 / -1;
        background: linear-gradient(135deg, var(--green), #00C853);
        color: white;
        border: none;
        padding: 14px;
        border-radius: 8px;
        font-size: 16px;
        font-weight: 700;
        cursor: pointer;
        margin-top: 20px;
        transition: all 0.3s;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
    }
    
    .save-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(0, 132, 61, 0.3);
        background: linear-gradient(135deg, #00C853, var(--green));
    }
    
    /* ✅ INFO BOX */
    .info-box {
        background: linear-gradient(135deg, #e3f2fd, #bbdefb);
        border: 1px solid #90caf9;
        border-radius: 10px;
        padding: 15px;
        margin-top: 10px;
    }
    
    .info-box p {
        margin: 0;
        font-size: 13px;
        color: #1565c0;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    /* ✅ IMAGE PREVIEW */
    .image-preview-container {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    
    .image-preview {
        margin-top: 10px;
        text-align: center;
    }
    
    .image-preview img {
        max-width: 250px;
        max-height: 180px;
        border-radius: 10px;
        border: 2px solid #e0e0e0;
        object-fit: cover;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    
    /* ✅ ALERT POPUP */
    .alert-popup {
        display: none;
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: #fff;
        padding: 30px;
        border-radius: 16px;
        text-align: center;
        box-shadow: 0 20px 50px rgba(0,0,0,0.25);
        z-index: 5000;
        width: 350px;
        animation: popIn 0.3s ease;
    }
    
    @keyframes popIn {
        from { transform: translate(-50%, -50%) scale(0.9); opacity: 0; }
        to { transform: translate(-50%, -50%) scale(1); opacity: 1; }
    }
    
    .alert-popup.show {
        display: block;
    }
    
    .alert-popup.success {
        border-top: 6px solid var(--green);
    }
    
    .alert-popup.error {
        border-top: 6px solid var(--red);
    }
    
    .alert-icon {
        font-size: 60px;
        margin-bottom: 15px;
    }
    
    .alert-popup.success .alert-icon {
        color: var(--green);
    }
    
    .alert-popup.error .alert-icon {
        color: var(--red);
    }
    
    .alert-popup h3 {
        font-size: 18px;
        color: #333;
        margin-bottom: 20px;
        line-height: 1.5;
    }
    
    .alert-btn {
        background: linear-gradient(135deg, var(--blue), #2196F3);
        color: white;
        padding: 10px 25px;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 600;
        transition: all 0.3s;
    }
    
    .alert-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 114, 206, 0.3);
    }
    
    /* ✅ EMPTY STATE */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
    }
    
    .empty-state i {
        font-size: 70px;
        color: #ddd;
        margin-bottom: 20px;
    }
    
    .empty-state h3 {
        color: #666;
        margin-bottom: 10px;
        font-size: 20px;
    }
    
    .empty-state p {
        color: #999;
        font-size: 14px;
    }
    
    /* ✅ RESPONSIVE DESIGN */
    @media (max-width: 1200px) {
        .main-content {
            margin-left: 250px;
        }
        
        .sidebar.collapsed ~ .main-content {
            margin-left: 70px;
        }
    }
    
    @media (max-width: 992px) {
        .main-content {
            margin-left: 70px;
            padding: 15px;
        }
        
        .form-grid {
            grid-template-columns: 1fr;
        }
    }
    
    @media (max-width: 768px) {
        .main-content {
            margin-left: 0;
            margin-top: 70px;
        }
        
        .page-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 15px;
        }
        
        .page-header h1 {
            font-size: 24px;
        }
        
        .filter-bar {
            flex-direction: column;
            align-items: stretch;
        }
        
        .filter-bar input,
        .filter-bar select {
            width: 100%;
        }
        
        .table-wrapper {
            max-height: 400px;
        }
        
        table {
            min-width: 800px;
        }
        
        th, td {
            padding: 10px 12px;
            font-size: 13px;
        }
        
        .modal-content {
            width: 95%;
            padding: 20px;
        }
        
        .modal h2 {
            font-size: 20px;
        }
    }
    
    @media (max-width: 480px) {
        .main-content {
            padding: 12px;
        }
        
        .page-header h1 {
            font-size: 20px;
        }
        
        .add-btn {
            padding: 10px 16px;
            font-size: 14px;
        }
        
        .action-buttons {
            flex-direction: column;
            gap: 5px;
        }
        
        .btn-view, .btn-edit, .btn-delete {
            width: 100%;
            justify-content: center;
        }
        
        .alert-popup {
            width: 90%;
            padding: 20px;
        }
    }
    </style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
    <div class="page-header">
        <h1><i class="fa fa-bullhorn"></i> Announcements Management</h1>
    
    </div>

    <!-- ✅ FILTER BAR -->
    <form method="GET" class="filter-bar">
        <input type="text" name="search" placeholder="🔍 Search by title or message..." 
               value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
        
        <select name="role">
            <option value="">🎯 All Target Groups</option>
            <option value="student" <?= ($_GET['role']??'')==='student'?'selected':'' ?>>Students</option>
            <option value="teacher" <?= ($_GET['role']??'')==='teacher'?'selected':'' ?>>Teachers</option>
            <option value="parent" <?= ($_GET['role']??'')==='parent'?'selected':'' ?>>Parents</option>
            <option value="admin" <?= ($_GET['role']??'')==='admin'?'selected':'' ?>>Administrators</option>
            <option value="campus" <?= ($_GET['role']??'')==='campus'?'selected':'' ?>>Campus Users</option>
            <option value="faculty" <?= ($_GET['role']??'')==='faculty'?'selected':'' ?>>Faculty Users</option>
            <option value="department" <?= ($_GET['role']??'')==='department'?'selected':'' ?>>Department Users</option>
            <option value="all_users" <?= ($_GET['role']??'')==='all_users'?'selected':'' ?>>All Users</option>
        </select>
        
        <select name="status">
            <option value="">📊 All Status</option>
            <option value="active" <?= ($_GET['status']??'')==='active'?'selected':'' ?>>Active</option>
            <option value="inactive" <?= ($_GET['status']??'')==='inactive'?'selected':'' ?>>Inactive</option>
        </select>
        
        <button type="submit" class="filter-btn">
            <i class="fa fa-filter"></i> Apply Filters
        </button>
        <a href="announcements.php" class="reset-btn">
            <i class="fa fa-rotate-left"></i> Reset
        </a>
    </form>

    <!-- ✅ ANNOUNCEMENTS TABLE -->
    <div class="table-container">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Title</th>
                        <th>Message</th>
                        <th>Image</th>
                        <th>Target</th>
                        <th>Status</th>
                        <th>Notifications</th>
                        <th>Created By</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($announcements && count($announcements) > 0): ?>
                        <?php foreach($announcements as $i => $a): ?>
                        <tr>
                            <td><strong>#<?= $i + 1 ?></strong></td>
                            <td>
                                <div style="font-weight: 600; color: var(--blue);">
                                    <?= htmlspecialchars($a['title']) ?>
                                </div>
                            </td>
                            <td>
                                <div style="max-width: 250px;">
                                    <?= htmlspecialchars(substr($a['message'], 0, 70)) ?>
                                    <?= strlen($a['message']) > 70 ? '...' : '' ?>
                                </div>
                            </td>
                            <td>
                                <?php if($a['image_path']): ?>
                                    <img src="../<?= htmlspecialchars($a['image_path']) ?>" 
                                         class="announcement-image"
                                         alt="Announcement Image"
                                         title="Click to enlarge">
                                <?php else: ?>
                                    <span style="color: #999; font-size: 12px;">
                                        <i class="fa fa-image"></i> No image
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="target-badge badge-<?= htmlspecialchars($a['target_role']) ?>">
                                    <?= htmlspecialchars(ucwords(str_replace('_', ' ', $a['target_role']))) ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge status-<?= $a['status'] ?>">
                                    <?= ucfirst($a['status']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if($a['notification_count'] > 0): ?>
                                    <span class="notification-badge">
                                        <i class="fa fa-bell"></i> <?= $a['notification_count'] ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #999; font-size: 12px;">
                                        <i class="fa fa-bell-slash"></i> 0
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-weight: 500; color: #555;">
                                    <?= htmlspecialchars($a['created_by_name'] ?? 'System') ?>
                                </div>
                            </td>
                            <td>
                                <div style="font-size: 13px; color: #666;">
                                    <?= date('d/m/Y', strtotime($a['created_at'])) ?>
                                </div>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button class="btn-view" onclick="viewAnnouncement(<?= htmlspecialchars(json_encode($a)) ?>)">
                                        <i class="fa fa-eye"></i> View
                                    </button>
                                   
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10">
                                <div class="empty-state">
                                    <i class="fa fa-inbox"></i>
                                    <h3>No Announcements Found</h3>
                                    <p>Start by creating your first announcement</p>
                                  
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ✅ ADD/EDIT MODAL -->
<!-- <div class="modal" id="addModal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('addModal')">&times;</span>
        <h2 id="formTitle"><i class="fa fa-bullhorn"></i> Add New Announcement</h2>
        
        <form method="POST" enctype="multipart/form-data" onsubmit="return validateForm()">
            <div class="form-grid">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="announcement_id" id="announcement_id">
                <input type="hidden" name="existing_image" id="existing_image">
                
                <div class="form-group">
                    <label for="title" class="required">Title</label>
                    <input type="text" name="title" id="title" required 
                           placeholder="Enter announcement title" maxlength="200">
                </div>
                
                <div class="form-group">
                    <label for="target_role" class="required">Target Audience</label>
                    <select name="target_role" id="target_role" required>
                        <option value="">-- Select Target Group --</option>
                        <option value="student">Students</option>
                        <option value="teacher">Teachers</option>
                        <option value="parent">Parents</option>
                        <option value="admin">Administrators</option>
                        <option value="campus">Campus Users</option>
                        <option value="faculty">Faculty Users</option>
                        <option value="department">Department Users</option>
                        <option value="all_users">All Users</option>
                    </select>
                </div>
                
                <div class="form-group full-width">
                    <label for="message" class="required">Message</label>
                    <textarea name="message" id="message" required 
                              placeholder="Enter announcement message..." rows="6" maxlength="1000"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="status" class="required">Status</label>
                    <select name="status" id="status" required>
                        <option value="active">Active (Send notifications)</option>
                        <option value="inactive">Inactive (Save as draft)</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="image">Image (Optional)</label>
                    <input type="file" name="image" id="image" accept="image/*" 
                           onchange="previewImage(this)">
                    <div class="image-preview-container">
                        <div id="imagePreview" class="image-preview"></div>
                        <div id="existingImagePreview"></div>
                    </div>
                </div>
                
                <div class="form-group full-width">
                    <div class="info-box">
                        <p><i class="fa fa-info-circle"></i> 
                            <strong>Active announcements</strong> will automatically send notifications to all users in the selected target group.
                            <strong>Inactive announcements</strong> will be saved as drafts without sending notifications.
                        </p>
                    </div>
                </div>
                
                <button type="submit" class="save-btn" id="submitBtn">
                    <i class="fa fa-paper-plane"></i> Publish Announcement
                </button>
            </div>
        </form>
    </div>
</div> -->

<!-- ✅ VIEW MODAL -->
<div class="modal" id="viewModal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('viewModal')">&times;</span>
        <h2 id="viewTitle"><i class="fa fa-eye"></i> Announcement Details</h2>
        
        <div id="viewContent" style="margin-top: 20px;">
            <!-- Content loaded dynamically -->
        </div>
    </div>
</div>

<!-- ✅ DELETE CONFIRMATION MODAL -->
<!-- <div class="modal" id="deleteModal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('deleteModal')">&times;</span>
        <h2 style="color: var(--red);"><i class="fa fa-exclamation-triangle"></i> Confirm Delete</h2>
        
        <form method="POST">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" id="delete_id" name="announcement_id">
            
            <div style="text-align: center; margin: 30px 0;">
                <i class="fa fa-trash-alt" style="font-size: 80px; color: var(--red); opacity: 0.7; margin-bottom: 20px;"></i>
                <p style="font-size: 18px; margin-bottom: 10px; color: #333;">
                    Are you sure you want to delete this announcement?
                </p>
                <p style="color: #666; margin-bottom: 20px;">
                    This action cannot be undone. All associated notifications will also be deleted.
                </p>
            </div>
            
            <div style="display: flex; gap: 15px; margin-top: 30px;">
                <button type="button" onclick="closeModal('deleteModal')" 
                        style="flex: 1; background: #e0e0e0; color: #333; padding: 12px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; transition: all 0.3s;">
                    <i class="fa fa-times"></i> Cancel
                </button>
                <button type="submit" class="save-btn" style="flex: 1; background: linear-gradient(135deg, var(--red), #ef5350);">
                    <i class="fa fa-trash"></i> Delete Permanently
                </button>
            </div>
        </form>
    </div>
</div> -->

<!-- ✅ ALERT POPUP -->
<div id="popup" class="alert-popup <?= $type ?>">
    <div class="alert-icon">
        <?php if($type === 'success'): ?>
            <i class="fa fa-check-circle"></i>
        <?php elseif($type === 'error'): ?>
            <i class="fa fa-exclamation-circle"></i>
        <?php endif; ?>
    </div>
    <h3><?= $message ?></h3>
    <button class="alert-btn" onclick="closeAlert()">OK</button>
</div>

<script>
// Modal Management
function openModal(id) {
    document.getElementById(id).classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeModal(id) {
    document.getElementById(id).classList.remove('show');
    document.body.style.overflow = 'auto';
    
    // Reset form when closing add modal
    if (id === 'addModal') {
        resetForm();
    }
}

// Edit Announcement
function openEditModal(announcement) {
    openModal('addModal');
    
    document.getElementById('formTitle').innerHTML = '<i class="fa fa-edit"></i> Edit Announcement';
    document.getElementById('formAction').value = 'update';
    document.getElementById('announcement_id').value = announcement.announcement_id;
    document.getElementById('title').value = announcement.title || '';
    document.getElementById('message').value = announcement.message || '';
    document.getElementById('target_role').value = announcement.target_role || '';
    document.getElementById('status').value = announcement.status || 'active';
    document.getElementById('existing_image').value = announcement.image_path || '';
    document.getElementById('submitBtn').innerHTML = '<i class="fa fa-save"></i> Update Announcement';
    
    // Show existing image preview
    const existingPreview = document.getElementById('existingImagePreview');
    if (announcement.image_path) {
        existingPreview.innerHTML = `
            <div style="margin-top: 10px;">
                <p style="margin-bottom: 8px; font-size: 13px; color: #666; font-weight: 600;">
                    <i class="fa fa-image"></i> Current Image:
                </p>
                <img src="../${announcement.image_path}" 
                     style="max-width: 250px; max-height: 180px; border-radius: 10px; border: 2px solid #ddd; object-fit: cover;">
                <p style="margin-top: 8px; font-size: 12px; color: #999;">
                    Upload a new image to replace this one
                </p>
            </div>
        `;
    } else {
        existingPreview.innerHTML = '';
    }
    
    // Clear new image input
    document.getElementById('image').value = '';
    document.getElementById('imagePreview').innerHTML = '';
}

// View Announcement
function viewAnnouncement(announcement) {
    openModal('viewModal');
    document.getElementById('viewTitle').innerText = announcement.title;
    
    // Format date
    const createdDate = new Date(announcement.created_at);
    const formattedDate = createdDate.toLocaleDateString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
    
    // Determine badge class
    const badgeClass = `badge-${announcement.target_role}`;
    const targetDisplay = announcement.target_role.replace('_', ' ').toUpperCase();
    
    let content = `
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 25px;">
            <div style="background: #f8f9fa; padding: 15px; border-radius: 10px;">
                <p style="margin-bottom: 10px; color: #666;">
                    <strong><i class="fa fa-users"></i> Target Audience:</strong>
                </p>
                <span class="target-badge ${badgeClass}" style="font-size: 14px; padding: 8px 15px;">
                    ${targetDisplay}
                </span>
            </div>
            
            <div style="background: #f8f9fa; padding: 15px; border-radius: 10px;">
                <p style="margin-bottom: 10px; color: #666;">
                    <strong><i class="fa fa-chart-line"></i> Status:</strong>
                </p>
                <span class="status-badge status-${announcement.status}" style="font-size: 14px; padding: 8px 15px;">
                    ${announcement.status.toUpperCase()}
                </span>
            </div>
            
            <div style="background: #f8f9fa; padding: 15px; border-radius: 10px;">
                <p style="margin-bottom: 10px; color: #666;">
                    <strong><i class="fa fa-user"></i> Created By:</strong>
                </p>
                <p style="color: #333; font-weight: 500;">
                    ${announcement.created_by_name || 'System'}
                </p>
            </div>
            
            <div style="background: #f8f9fa; padding: 15px; border-radius: 10px;">
                <p style="margin-bottom: 10px; color: #666;">
                    <strong><i class="fa fa-calendar"></i> Created Date:</strong>
                </p>
                <p style="color: #333; font-weight: 500;">
                    ${formattedDate}
                </p>
            </div>
        </div>
        
        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-bottom: 25px;">
            <p style="margin-bottom: 15px; color: #666; font-weight: 600;">
                <i class="fa fa-align-left"></i> Message:
            </p>
            <div style="white-space: pre-wrap; color: #333; line-height: 1.6; background: white; padding: 15px; border-radius: 8px; border: 1px solid #e0e0e0;">
                ${announcement.message.replace(/\n/g, '<br>')}
            </div>
        </div>
    `;
    
    if (announcement.image_path) {
        content += `
            <div style="background: #f8f9fa; padding: 20px; border-radius: 10px;">
                <p style="margin-bottom: 15px; color: #666; font-weight: 600;">
                    <i class="fa fa-image"></i> Attached Image:
                </p>
                <div style="text-align: center;">
                    <img src="../${announcement.image_path}" 
                         style="max-width: 100%; max-height: 400px; border-radius: 10px; border: 2px solid #ddd; object-fit: contain; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                </div>
            </div>
        `;
    }
    
    // Add notification count if available
    if (announcement.notification_count > 0) {
        content += `
            <div style="margin-top: 20px; text-align: center;">
                <span class="notification-badge" style="font-size: 14px; padding: 8px 20px;">
                    <i class="fa fa-bell"></i> ${announcement.notification_count} notifications sent
                </span>
            </div>
        `;
    }
    
    document.getElementById('viewContent').innerHTML = content;
}

// Delete Announcement
function openDeleteModal(id) {
    openModal('deleteModal');
    document.getElementById('delete_id').value = id;
}

// Image Preview
function previewImage(input) {
    const preview = document.getElementById('imagePreview');
    preview.innerHTML = '';
    
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        
        reader.onload = function(e) {
            const img = document.createElement('img');
            img.src = e.target.result;
            img.style.maxWidth = '250px';
            img.style.maxHeight = '180px';
            img.style.borderRadius = '10px';
            img.style.border = '2px solid #ddd';
            img.style.marginTop = '10px';
            img.style.objectFit = 'cover';
            preview.appendChild(img);
            
            // Add instruction
            const instruction = document.createElement('p');
            instruction.style.marginTop = '8px';
            instruction.style.fontSize = '12px';
            instruction.style.color = '#666';
            instruction.innerHTML = '<i class="fa fa-info-circle"></i> Image preview';
            preview.appendChild(instruction);
        }
        
        reader.readAsDataURL(input.files[0]);
    }
}

// Form Validation
function validateForm() {
    const title = document.getElementById('title').value.trim();
    const message = document.getElementById('message').value.trim();
    const targetRole = document.getElementById('target_role').value;
    
    if (!title) {
        showToast('Please enter a title for the announcement', 'error');
        document.getElementById('title').focus();
        return false;
    }
    
    if (!message) {
        showToast('Please enter the announcement message', 'error');
        document.getElementById('message').focus();
        return false;
    }
    
    if (!targetRole) {
        showToast('Please select a target audience', 'error');
        document.getElementById('target_role').focus();
        return false;
    }
    
    return true;
}

// Reset Form
function resetForm() {
    // document.getElementById('formTitle').innerHTML = '<i class="fa fa-bullhorn"></i> Add New Announcement';
    document.getElementById('formAction').value = 'add';
    document.getElementById('announcement_id').value = '';
    document.getElementById('title').value = '';
    document.getElementById('message').value = '';
    document.getElementById('target_role').value = '';
    document.getElementById('status').value = 'active';
    document.getElementById('existing_image').value = '';
    document.getElementById('image').value = '';
    document.getElementById('imagePreview').innerHTML = '';
    document.getElementById('existingImagePreview').innerHTML = '';
    document.getElementById('submitBtn').innerHTML = '<i class="fa fa-paper-plane"></i> Publish Announcement';
}

// Alert Functions
function closeAlert() {
    document.getElementById('popup').classList.remove('show');
}

// Toast Notification
function showToast(message, type) {
    // Remove existing toast
    const existingToast = document.querySelector('.toast-notification');
    if (existingToast) {
        existingToast.remove();
    }
    
    // Create toast element
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type}`;
    toast.style.cssText = `
        position: fixed;
        top: 100px;
        right: 20px;
        background: ${type === 'error' ? 'var(--red)' : 'var(--green)'};
        color: white;
        padding: 15px 20px;
        border-radius: 8px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        z-index: 9999;
        animation: slideIn 0.3s ease;
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 500;
    `;
    
    const icon = type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle';
    toast.innerHTML = `
        <i class="fa ${icon}" style="font-size: 18px;"></i>
        <span>${message}</span>
    `;
    
    document.body.appendChild(toast);
    
    // Auto remove after 3 seconds
    setTimeout(() => {
        toast.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Show alert if there's a message
<?php if($message): ?>
    setTimeout(() => {
        document.getElementById('popup').classList.add('show');
    }, 300);
<?php endif; ?>

// Close modal on ESC key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const modals = document.querySelectorAll('.modal.show');
        modals.forEach(modal => {
            modal.classList.remove('show');
            document.body.style.overflow = 'auto';
        });
    }
});

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
        document.body.style.overflow = 'auto';
    }
};

// Add CSS for toast animations
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    
    @keyframes slideOut {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(100%); opacity: 0; }
    }
`;
document.head.appendChild(style);
</script>

<script src="../assets/js/sidebar.js"></script>
<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>