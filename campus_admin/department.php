<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Access Control - Only Campus Admin
if (strtolower($_SESSION['user']['role'] ?? '') !== 'campus_admin') {
    header("Location: ../login.php");
    exit;
}

$message = "";
$type = "";

// ✅ Get current campus ID from user session
$current_campus_id = $_SESSION['user']['linked_id'] ?? null;

/* ===========================================
   CRUD OPERATIONS
=========================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🟢 ADD DEPARTMENT
    if ($_POST['action'] === 'add') {
        try {
            $pdo->beginTransaction();

            $department_name = trim($_POST['department_name']);
            $department_code = trim($_POST['department_code']);
            $head_of_department = trim($_POST['head_of_department']);
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone_number'] ?? '');
            $faculty_id = $_POST['faculty_id'] ?? null;
            $campus_id = $current_campus_id; // Always use current campus
            
            if (!$faculty_id) {
                throw new Exception("Please select a faculty!");
            }

            // ✅ Verify faculty belongs to this campus
            $facultyCheck = $pdo->prepare("
                SELECT COUNT(*) FROM faculty_campus 
                WHERE faculty_id = ? AND campus_id = ?
            ");
            $facultyCheck->execute([$faculty_id, $campus_id]);
            
            if ($facultyCheck->fetchColumn() == 0) {
                throw new Exception("Selected faculty does not belong to your campus!");
            }

            // ✅ Check if department code already exists in this campus
            $codeCheck = $pdo->prepare("
                SELECT COUNT(*) FROM departments 
                WHERE department_code = ? AND campus_id = ?
            ");
            $codeCheck->execute([$department_code, $campus_id]);
            if ($codeCheck->fetchColumn() > 0) {
                throw new Exception("⚠️ Department code already exists in your campus!");
            }

            // ✅ Check if email already exists
            if ($email) {
                $emailCheck = $pdo->prepare("
                    SELECT COUNT(*) FROM departments 
                    WHERE email = ? AND campus_id = ?
                ");
                $emailCheck->execute([$email, $campus_id]);
                if ($emailCheck->fetchColumn() > 0) {
                    throw new Exception("⚠️ Email already exists!");
                }
            }

            // ✅ Check if head of department already exists
            if ($head_of_department) {
                $headCheck = $pdo->prepare("
                    SELECT COUNT(*) FROM departments 
                    WHERE head_of_department = ? AND campus_id = ?
                ");
                $headCheck->execute([$head_of_department, $campus_id]);
                if ($headCheck->fetchColumn() > 0) {
                    throw new Exception("⚠️ Head of Department already assigned!");
                }
            }

            // ✅ Get campus info for office location
            $campus_info = $pdo->prepare("
                SELECT campus_name, address, city, country 
                FROM campus 
                WHERE campus_id = ?
            ");
            $campus_info->execute([$campus_id]);
            $campus = $campus_info->fetch(PDO::FETCH_ASSOC);

            // ✅ Auto-generate office location
            $office_location = trim($_POST['office_location'] ?? '');
            if (empty($office_location) && $campus) {
                $address_parts = [];
                if (!empty($campus['address'])) $address_parts[] = $campus['address'];
                if (!empty($campus['city'])) $address_parts[] = $campus['city'];
                if (!empty($campus['country'])) $address_parts[] = $campus['country'];
                
                $office_location = $address_parts ? implode(', ', $address_parts) : $campus['campus_name'] . " Campus";
            }

            // ✅ Upload profile photo (if provided)
            $photo_path = null;
            if (!empty($_FILES['profile_photo']['name'])) {
                $upload_dir = __DIR__ . '/../upload/profiles/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                
                $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
                $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                
                if (!in_array($ext, $allowed_ext)) {
                    throw new Exception("Invalid file type. Please upload JPG, PNG, or GIF images.");
                }
                
                if ($_FILES['profile_photo']['size'] > 5 * 1024 * 1024) {
                    throw new Exception("File size too large. Maximum size is 5MB.");
                }
                
                $new_name = uniqid('dept_') . '.' . $ext;
                $photo_path = 'upload/profiles/' . $new_name;
                
                if (!move_uploaded_file($_FILES['profile_photo']['tmp_name'], __DIR__ . '/../' . $photo_path)) {
                    throw new Exception("Failed to upload profile photo.");
                }
            }

            // ✅ Insert department
            $stmt = $pdo->prepare("
                INSERT INTO departments 
                (department_name, department_code, head_of_department, faculty_id, campus_id, 
                 phone_number, email, office_location, profile_photo_path, status)
                VALUES (?,?,?,?,?,?,?,?,?, 'active')
            ");
            $stmt->execute([
                $department_name,
                $department_code,
                $head_of_department,
                $faculty_id,
                $campus_id,
                $phone,
                $email,
                $office_location,
                $photo_path
            ]);

            $department_id = $pdo->lastInsertId();

            // ✅ Check if user exists with this email
            $existing_user = null;
            if ($email) {
                $emailUserCheck = $pdo->prepare("SELECT * FROM users WHERE email = ?");
                $emailUserCheck->execute([$email]);
                $existing_user = $emailUserCheck->fetch(PDO::FETCH_ASSOC);
            }

            // ✅ Create or update user account (Department Admin)
            if ($existing_user) {
                // Update existing user to link this department
                $updateUser = $pdo->prepare("
                    UPDATE users 
                    SET linked_id = ?, linked_table = 'department' 
                    WHERE email = ?
                ");
                $updateUser->execute([$department_id, $email]);
                
                $message = "✅ Department added and linked to existing user!";
            } else {
                // Create new department admin account
                $uuid = bin2hex(random_bytes(16));
                $plain_pass = "123";
                $hashed = password_hash($plain_pass, PASSWORD_BCRYPT);

                // Create username from department name (clean)
                $username = preg_replace('/[^a-z0-9]/i', '', strtolower($department_name));
                $username = substr($username, 0, 20) . rand(10, 99);

                $user = $pdo->prepare("
                    INSERT INTO users 
                    (user_uuid, username, email, phone_number, profile_photo_path, 
                     password, password_plain, role, linked_id, linked_table, status)
                    VALUES (?,?,?,?,?,?,?,?,?,?,'active')
                ");
                $user->execute([
                    $uuid,
                    $username,
                    $email,
                    $phone,
                    $photo_path,
                    $hashed,
                    $plain_pass,
                    'department_admin',
                    $department_id,
                    'department'
                ]);

                $message = "✅ Department added successfully! Department Admin account created with password: 123";
            }

            $pdo->commit();
            $type = "success";

        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = empty($message) ? "❌ Database Error: " . $e->getMessage() : $message;
            $type = "error";
        } catch (Exception $ex) {
            $pdo->rollBack();
            $message = "❌ " . $ex->getMessage();
            $type = "error";
        }
    }

    // 🟡 UPDATE DEPARTMENT
    if ($_POST['action'] === 'update') {
        try {
            $pdo->beginTransaction();
            
            $id = $_POST['department_id'];
            $department_name = trim($_POST['department_name']);
            $department_code = trim($_POST['department_code']);
            $head_of_department = trim($_POST['head_of_department']);
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone_number'] ?? '');
            $faculty_id = $_POST['faculty_id'] ?? null;
            $campus_id = $current_campus_id; // Always use current campus
            
            if (!$faculty_id) {
                throw new Exception("Please select a faculty!");
            }
            
            // ✅ Security check: Ensure department belongs to this campus
            $check_stmt = $pdo->prepare("
                SELECT d.*, c.campus_name, c.address, c.city, c.country
                FROM departments d
                JOIN campus c ON d.campus_id = c.campus_id
                WHERE d.department_id = ? AND d.campus_id = ?
            ");
            $check_stmt->execute([$id, $campus_id]);
            $department = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$department) {
                throw new Exception("❌ Access denied: You can only edit departments from your campus");
            }
            
            // ✅ Verify faculty belongs to this campus
            $facultyCheck = $pdo->prepare("
                SELECT COUNT(*) FROM faculty_campus 
                WHERE faculty_id = ? AND campus_id = ?
            ");
            $facultyCheck->execute([$faculty_id, $campus_id]);
            
            if ($facultyCheck->fetchColumn() == 0) {
                throw new Exception("Selected faculty does not belong to your campus!");
            }
            
            // ✅ Check if department code is being changed and if it's unique
            if ($department_code !== $department['department_code']) {
                $check_code = $pdo->prepare("
                    SELECT COUNT(*) FROM departments 
                    WHERE department_code = ? AND campus_id = ? AND department_id != ?
                ");
                $check_code->execute([$department_code, $campus_id, $id]);
                
                if ($check_code->fetchColumn() > 0) {
                    throw new Exception("Department code already exists in your campus!");
                }
            }

            // ✅ Check if email already exists (excluding current)
            if ($email && $email !== $department['email']) {
                $emailCheck = $pdo->prepare("
                    SELECT COUNT(*) FROM departments 
                    WHERE email = ? AND campus_id = ? AND department_id != ?
                ");
                $emailCheck->execute([$email, $campus_id, $id]);
                if ($emailCheck->fetchColumn() > 0) {
                    throw new Exception("⚠️ Email already exists!");
                }
            }

            // ✅ Check if head of department already exists (excluding current)
            if ($head_of_department && $head_of_department !== $department['head_of_department']) {
                $headCheck = $pdo->prepare("
                    SELECT COUNT(*) FROM departments 
                    WHERE head_of_department = ? AND campus_id = ? AND department_id != ?
                ");
                $headCheck->execute([$head_of_department, $campus_id, $id]);
                if ($headCheck->fetchColumn() > 0) {
                    throw new Exception("⚠️ Head of Department already assigned!");
                }
            }

            // ✅ Get campus info for office location
            $campus_info = $pdo->prepare("
                SELECT campus_name, address, city, country 
                FROM campus 
                WHERE campus_id = ?
            ");
            $campus_info->execute([$campus_id]);
            $campus = $campus_info->fetch(PDO::FETCH_ASSOC);

            // ✅ Auto-generate office location if empty
            $office_location = trim($_POST['office_location'] ?? '');
            if (empty($office_location) && $campus) {
                $address_parts = [];
                if (!empty($campus['address'])) $address_parts[] = $campus['address'];
                if (!empty($campus['city'])) $address_parts[] = $campus['city'];
                if (!empty($campus['country'])) $address_parts[] = $campus['country'];
                
                $office_location = $address_parts ? implode(', ', $address_parts) : $campus['campus_name'] . " Campus";
            }

            // ✅ Get existing photo path
            $photo_path = $department['profile_photo_path'];

            // ✅ Handle photo upload
            $setClauses = [
                "department_name = ?",
                "department_code = ?",
                "head_of_department = ?",
                "faculty_id = ?",
                "phone_number = ?",
                "email = ?",
                "office_location = ?",
                "status = ?"
            ];

            $params = [
                $department_name,
                $department_code,
                $head_of_department,
                $faculty_id,
                $phone,
                $email,
                $office_location,
                $_POST['status']
            ];

            if (!empty($_FILES['profile_photo']['name'])) {
                $upload_dir = __DIR__ . '/../upload/profiles/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                
                $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
                $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                
                if (!in_array($ext, $allowed_ext)) {
                    throw new Exception("Invalid file type. Please upload JPG, PNG, or GIF images.");
                }
                
                if ($_FILES['profile_photo']['size'] > 5 * 1024 * 1024) {
                    throw new Exception("File size too large. Maximum size is 5MB.");
                }
                
                $new_name = uniqid('dept_') . '.' . $ext;
                $photo_path = 'upload/profiles/' . $new_name;
                
                if (!move_uploaded_file($_FILES['profile_photo']['tmp_name'], __DIR__ . '/../' . $photo_path)) {
                    throw new Exception("Failed to upload profile photo.");
                }
                
                $setClauses[] = "profile_photo_path = ?";
                $params[] = $photo_path;
            }

            $params[] = $id;

            // ✅ Update department (campus_id is not updated)
            $sql = "UPDATE departments SET " . implode(', ', $setClauses) . " WHERE department_id = ?";
            $pdo->prepare($sql)->execute($params);

            // ✅ Sync user account (department admin)
            $userStatus = ($_POST['status'] === 'inactive') ? 'inactive' : 'active';
            
            // Check if user exists
            $userCheck = $pdo->prepare("SELECT user_id FROM users WHERE linked_id = ? AND linked_table = 'department'");
            $userCheck->execute([$id]);
            
            if ($userCheck->rowCount() > 0) {
                // Update existing user
                $updateUser = $pdo->prepare("
                    UPDATE users 
                    SET username = ?, email = ?, phone_number = ?, profile_photo_path = ?, status = ? 
                    WHERE linked_id = ? AND linked_table = 'department'
                ");
                $updateUser->execute([
                    preg_replace('/[^a-z0-9]/i', '', strtolower($department_name)),
                    $email,
                    $phone,
                    $photo_path ?? null,
                    $userStatus,
                    $id
                ]);
            }

            $pdo->commit();
            $message = "✅ Department updated successfully!";
            $type = "success";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = empty($message) ? "❌ " . $e->getMessage() : $message;
            $type = "error";
        } catch (Exception $ex) {
            $pdo->rollBack();
            $message = "❌ " . $ex->getMessage();
            $type = "error";
        }
    }

    // 🔴 DELETE DEPARTMENT
    if ($_POST['action'] === 'delete') {
        try {
            $pdo->beginTransaction();
            
            $id = $_POST['department_id'];
            
            // ✅ Security check: Ensure department belongs to this campus
            $check_stmt = $pdo->prepare("SELECT department_id FROM departments WHERE department_id = ? AND campus_id = ?");
            $check_stmt->execute([$id, $current_campus_id]);
            $department = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$department) {
                throw new Exception("❌ Access denied: You can only delete departments from your campus");
            }
            
            // Delete user account first
            $pdo->prepare("DELETE FROM users WHERE linked_id = ? AND linked_table = 'department'")->execute([$id]);
            
            // Then delete department
            $pdo->prepare("DELETE FROM departments WHERE department_id = ?")->execute([$id]);
            
            $pdo->commit();
            $message = "✅ Department deleted successfully!";
            $type = "success";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "❌ " . $e->getMessage();
            $type = "error";
        } catch (Exception $ex) {
            $pdo->rollBack();
            $message = "❌ " . $ex->getMessage();
            $type = "error";
        }
    }
}

// ✅ GET CAMPUS INFO
$stmt = $pdo->prepare("SELECT * FROM campus WHERE campus_id = ?");
$stmt->execute([$current_campus_id]);
$campus = $stmt->fetch(PDO::FETCH_ASSOC);
$campus_name = $campus['campus_name'] ?? 'Unknown Campus';
$campus_code = $campus['campus_code'] ?? '';

// ✅ GET ALL FACULTIES FOR THIS CAMPUS (from faculty_campus table)
$stmt = $pdo->prepare("
    SELECT f.* 
    FROM faculties f
    JOIN faculty_campus fc ON f.faculty_id = fc.faculty_id
    WHERE fc.campus_id = ? AND f.status = 'active'
    ORDER BY f.faculty_name ASC
");
$stmt->execute([$current_campus_id]);
$campus_faculties = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ BUILD QUERY WITH FILTERS
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$faculty_filter = $_GET['faculty'] ?? '';

$query = "
    SELECT 
        d.*,
        f.faculty_name,
        f.faculty_code,
        c.campus_name,
        c.campus_code,
        c.address as campus_address,
        c.city as campus_city,
        c.country as campus_country
    FROM departments d
    JOIN faculties f ON d.faculty_id = f.faculty_id
    JOIN campus c ON d.campus_id = c.campus_id
    WHERE d.campus_id = ?
";

$params = [$current_campus_id];

// Search filter
if (!empty($search)) {
    $query .= " AND (d.department_name LIKE ? OR d.department_code LIKE ? OR d.head_of_department LIKE ? OR d.email LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}

// Status filter
if (!empty($status_filter)) {
    $query .= " AND d.status = ?";
    $params[] = $status_filter;
}

// Faculty filter
if (!empty($faculty_filter)) {
    $query .= " AND d.faculty_id = ?";
    $params[] = $faculty_filter;
}

$query .= " ORDER BY d.department_id DESC";

// ✅ Execute query
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ Get counts for stats
$total_departments = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE campus_id = ?");
$total_departments->execute([$current_campus_id]);
$total = $total_departments->fetchColumn();

$active_departments = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE campus_id = ? AND status = 'active'");
$active_departments->execute([$current_campus_id]);
$active = $active_departments->fetchColumn();

$inactive_departments = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE campus_id = ? AND status = 'inactive'");
$inactive_departments->execute([$current_campus_id]);
$inactive = $inactive_departments->fetchColumn();

// ✅ Function to get profile photo
function getProfilePhoto($path) {
    if (empty($path) || $path === null) {
        return null;
    }
    $full_path = __DIR__ . '/../' . $path;
    if (!file_exists($full_path)) {
        return null;
    }
    return $path;
}

// ✅ Function to get initials
function getInitials($name) {
    $name_parts = explode(' ', $name);
    if (count($name_parts) >= 2) {
        return strtoupper(substr($name_parts[0], 0, 1) . substr($name_parts[1], 0, 1));
    }
    $initials = strtoupper(substr($name, 0, 2));
    if (strlen($initials) === 1) {
        $initials = $initials . strtoupper(substr($name, 1, 1));
    }
    return $initials;
}

// ✅ Prepare campus data for JavaScript
$campus_json = [
    'id' => $current_campus_id,
    'name' => $campus_name,
    'code' => $campus_code,
    'address' => $campus['address'] ?? '',
    'city' => $campus['city'] ?? '',
    'country' => $campus['country'] ?? ''
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Management | Campus Admin - Hormuud University</title>
    <link rel="icon" type="image/png" href="../images.png">
    <link rel="stylesheet" href="../assets/css/header.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* ==============================
           BASE STYLES
           ============================== */
        :root {
            --primary-color: #00843D;
            --secondary-color: #0072CE;
            --light-color: #00A651;
            --dark-color: #333333;
            --light-gray: #F5F9F7;
            --danger-color: #C62828;
            --warning-color: #FFB400;
            --white: #FFFFFF;
            --cyan-color: #00BCD4;
            --purple-color: #6A5ACD;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: var(--light-gray);
            color: var(--dark-color);
            min-height: 100vh;
        }

        .main-content {
            margin-top: 65px;
            margin-left: 240px;
            padding: 25px;
            transition: margin-left 0.3s ease;
            min-height: calc(100vh - 115px);
        }

        body.sidebar-collapsed .main-content {
            margin-left: 60px;
        }

        /* ==============================
           PAGE HEADER
           ============================== */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding: 20px;
            background: var(--white);
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            border-left: 4px solid var(--primary-color);
        }

        .header-title h1 {
            color: var(--secondary-color);
            font-size: 26px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-title h1 i {
            color: var(--primary-color);
            background: rgba(0, 132, 61, 0.1);
            padding: 10px;
            border-radius: 10px;
        }

        .campus-badge {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        .campus-name {
            background: var(--primary-color);
            color: white;
            padding: 6px 15px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 500;
        }

        .faculty-count {
            background: var(--secondary-color);
            color: white;
            padding: 6px 15px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 500;
        }

        .add-btn {
            background: linear-gradient(135deg, var(--primary-color), var(--light-color));
            color: var(--white);
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 12px rgba(0, 132, 61, 0.2);
        }

        .add-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0, 132, 61, 0.3);
        }

        /* ==============================
           STATS CARDS
           ============================== */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: var(--white);
            border-radius: 10px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 20px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s ease;
            border-left: 4px solid;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card.total { border-left-color: var(--secondary-color); }
        .stat-card.active { border-left-color: var(--primary-color); }
        .stat-card.inactive { border-left-color: var(--danger-color); }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            color: var(--white);
        }

        .stat-icon.total { background: var(--secondary-color); }
        .stat-icon.active { background: var(--primary-color); }
        .stat-icon.inactive { background: var(--danger-color); }

        .stat-info h3 {
            font-size: 14px;
            color: #666;
            margin-bottom: 5px;
        }

        .stat-info .number {
            font-size: 24px;
            font-weight: 700;
            color: var(--dark-color);
        }

        /* ==============================
           FILTERS
           ============================== */
        .filters-container {
            background: var(--white);
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 3px 15px rgba(0, 0, 0, 0.08);
        }

        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .filter-header h3 {
            color: var(--secondary-color);
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-form {
            display: flex;
            gap: 15px;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
            position: relative;
        }

        .filter-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark-color);
            font-size: 14px;
        }

        .filter-input {
            width: 100%;
            padding: 12px 15px 12px 45px;
            border: 1.5px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: #fafafa;
        }

        .filter-input:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(0, 114, 206, 0.1);
            background: var(--white);
        }

        .filter-icon {
            position: absolute;
            left: 15px;
            bottom: 12px;
            color: #666;
            font-size: 16px;
        }

        .filter-actions {
            display: flex;
            gap: 10px;
            align-items: flex-end;
            margin-bottom: 5px;
        }

        .filter-btn {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }

        .apply-btn {
            background: var(--primary-color);
            color: var(--white);
        }

        .apply-btn:hover {
            background: var(--light-color);
            transform: translateY(-2px);
        }

        .clear-btn {
            background: #6c757d;
            color: var(--white);
        }

        .clear-btn:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }

        /* ==============================
           TABLE STYLES
           ============================== */
        .table-wrapper {
            background: var(--white);
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 3px 15px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
        }

        .table-header {
            padding: 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-header h3 {
            color: var(--dark-color);
            font-size: 16px;
        }

        .results-count {
            color: #666;
            font-size: 14px;
        }

        .table-container {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .data-table thead {
            background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
        }

        .data-table th {
            padding: 16px 20px;
            text-align: left;
            font-weight: 600;
            color: var(--white);
            white-space: nowrap;
            border-bottom: 2px solid rgba(255, 255, 255, 0.1);
        }

        .data-table td {
            padding: 14px 20px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }

        .data-table tbody tr {
            transition: background 0.2s ease;
        }

        .data-table tbody tr:hover {
            background: #f9f9f9;
        }

        .data-table tbody tr:nth-child(even) {
            background: rgba(0, 114, 206, 0.02);
        }

        /* Profile photo */
        .profile-photo-container {
            width: 50px;
            height: 50px;
            position: relative;
        }

        .profile-photo {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--primary-color);
            background: linear-gradient(135deg, #f5f5f5, #e0e0e0);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--secondary-color);
            font-weight: bold;
            font-size: 16px;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .profile-photo:hover {
            transform: scale(1.1);
            box-shadow: 0 5px 15px rgba(0, 132, 61, 0.3);
        }

        .profile-initials {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
            color: white;
            font-weight: 600;
            font-size: 18px;
            border-radius: 50%;
        }

        /* Department code */
        .code-badge {
            display: inline-block;
            background: rgba(0, 132, 61, 0.1);
            color: var(--primary-color);
            padding: 4px 10px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-weight: 600;
            font-size: 13px;
            letter-spacing: 0.5px;
        }

        /* Campus and faculty info */
        .location-info {
            font-size: 12px;
            color: #666;
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .location-info i {
            color: var(--secondary-color);
        }

        .faculty-badge {
            background: #e3f2fd;
            color: var(--secondary-color);
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            border: 1px solid #bbdefb;
        }

        .faculty-badge-code {
            background: var(--secondary-color);
            color: white;
            font-size: 9px;
            padding: 1px 5px;
            border-radius: 8px;
            font-weight: bold;
        }

        /* Status badges */
        .status-badge {
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-active {
            background: #e8f5e8;
            color: var(--primary-color);
            border: 1px solid rgba(0, 132, 61, 0.2);
        }

        .status-inactive {
            background: #ffebee;
            color: var(--danger-color);
            border: 1px solid rgba(198, 40, 40, 0.2);
        }

        /* Action buttons */
        .action-btns {
            display: flex;
            gap: 8px;
        }

        .action-btn {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            font-size: 15px;
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.1);
        }

        .view-btn {
            background: var(--cyan-color);
            color: var(--white);
        }

        .view-btn:hover {
            background: #00acc1;
            transform: translateY(-2px) rotate(5deg);
            box-shadow: 0 5px 12px rgba(0, 188, 212, 0.3);
        }

        .edit-btn {
            background: var(--secondary-color);
            color: var(--white);
        }

        .edit-btn:hover {
            background: #005fa3;
            transform: translateY(-2px);
            box-shadow: 0 5px 12px rgba(0, 114, 206, 0.3);
        }

        .del-btn {
            background: var(--danger-color);
            color: var(--white);
        }

        .del-btn:hover {
            background: #b71c1c;
            transform: translateY(-2px);
            box-shadow: 0 5px 12px rgba(198, 40, 40, 0.3);
        }

        /* ==============================
           MODAL STYLES
           ============================== */
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            justify-content: center;
            align-items: center;
            z-index: 1000;
            padding: 20px;
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
            background: var(--white);
            border-radius: 16px;
            width: 100%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 35px;
            position: relative;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
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

        .close-modal {
            position: absolute;
            top: 15px;
            right: 20px;
            font-size: 28px;
            color: #888;
            cursor: pointer;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
            background: rgba(0, 0, 0, 0.05);
        }

        .close-modal:hover {
            background: rgba(0, 0, 0, 0.1);
            color: var(--danger-color);
            transform: rotate(90deg);
        }

        .modal-content h2 {
            color: var(--secondary-color);
            margin-bottom: 30px;
            font-size: 24px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }

        .modal-content h2 i {
            color: var(--primary-color);
            background: rgba(0, 132, 61, 0.1);
            padding: 10px;
            border-radius: 10px;
        }

        /* ==============================
           VIEW MODAL STYLES
           ============================== */
        .view-content {
            display: grid;
            grid-template-columns: 200px 1fr;
            gap: 30px;
            align-items: start;
        }

        .view-photo-container {
            text-align: center;
        }

        .view-profile-photo {
            width: 180px;
            height: 180px;
            border-radius: 50%;
            object-fit: cover;
            border: 5px solid var(--primary-color);
            margin-bottom: 15px;
            background: linear-gradient(135deg, #f5f5f5, #e0e0e0);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--secondary-color);
            font-size: 60px;
            font-weight: bold;
            overflow: hidden;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .view-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .detail-item {
            margin-bottom: 15px;
        }

        .detail-label {
            font-weight: 600;
            color: var(--secondary-color);
            font-size: 14px;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .detail-value {
            color: var(--dark-color);
            padding: 10px 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid var(--primary-color);
            font-size: 15px;
            min-height: 45px;
            display: flex;
            align-items: center;
        }

        .detail-value.code {
            font-family: 'Courier New', monospace;
            color: var(--primary-color);
            font-weight: 600;
        }

        /* Campus and Faculty Section in View Modal */
        .location-section {
            grid-column: 1 / -1;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid #eee;
        }

        .location-section h4 {
            color: var(--secondary-color);
            margin-bottom: 15px;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .location-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .location-card {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 15px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .location-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .location-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .location-card-name {
            font-weight: 600;
            color: var(--secondary-color);
            font-size: 15px;
        }

        .location-card-code {
            background: var(--primary-color);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
        }

        .location-card-location {
            display: flex;
            align-items: center;
            gap: 5px;
            color: #666;
            font-size: 12px;
            margin-top: 5px;
        }

        /* ==============================
           FORM STYLES
           ============================== */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-weight: 500;
            color: var(--dark-color);
            font-size: 14px;
            position: relative;
            padding-left: 5px;
        }

        .form-group label::after {
            content: '';
            position: absolute;
            left: 0;
            top: 2px;
            height: 16px;
            width: 3px;
            background: var(--primary-color);
            border-radius: 3px;
        }

        .form-control {
            width: 100%;
            padding: 12px 18px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: #fafafa;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary-color);
            background: var(--white);
            box-shadow: 0 0 0 4px rgba(0, 114, 206, 0.1);
            transform: translateY(-2px);
        }

        .form-control.readonly {
            background-color: #f5f5f5;
            color: #888;
            border-color: #ddd;
            cursor: not-allowed;
        }

        /* Campus Info Box */
        .campus-info-box {
            grid-column: 1 / -1;
            background: linear-gradient(135deg, #f8f9fa 0%, #e8f5e9 100%);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
            border-left: 4px solid var(--primary-color);
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 10px;
            background: white;
            padding: 10px 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .info-item i {
            color: var(--primary-color);
            font-size: 18px;
        }

        .info-item span {
            font-weight: 500;
            color: var(--dark-color);
        }

        .info-item small {
            color: #666;
            font-size: 12px;
            margin-left: 5px;
        }

        /* Faculty Selection */
        .faculty-selection {
            grid-column: 1 / -1;
            margin: 20px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
            border: 2px solid var(--primary-color);
        }

        .faculty-selection label {
            font-weight: 600;
            color: var(--secondary-color);
            margin-bottom: 15px;
            display: block;
            font-size: 16px;
        }

        .faculty-select {
            width: 100%;
            padding: 14px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 15px;
            background: white;
            cursor: pointer;
        }

        .faculty-select:focus {
            border-color: var(--primary-color);
            outline: none;
        }

        /* Office location */
        .office-location-display {
            grid-column: 1 / -1;
            background: linear-gradient(135deg, #f8f9fa 0%, #f0f7ff 100%);
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
            position: relative;
            overflow: hidden;
        }

        .office-location-display::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--primary-color);
        }

        .office-location-display h4 {
            color: var(--secondary-color);
            margin-bottom: 15px;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Submit button */
        .submit-btn {
            grid-column: 1 / -1;
            background: linear-gradient(135deg, var(--primary-color), var(--light-color));
            color: var(--white);
            border: none;
            padding: 15px 30px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 6px 20px rgba(0, 132, 61, 0.2);
            position: relative;
            overflow: hidden;
        }

        .submit-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: 0.5s;
        }

        .submit-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 132, 61, 0.3);
        }

        .submit-btn:hover::before {
            left: 100%;
        }

        .delete-btn {
            background: linear-gradient(135deg, var(--danger-color), #e53935);
        }

        .delete-btn:hover {
            box-shadow: 0 10px 25px rgba(198, 40, 40, 0.3);
        }

        /* ==============================
           ALERT POPUP
           ============================== */
        .alert-popup {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: var(--white);
            border-radius: 12px;
            padding: 30px 35px;
            text-align: center;
            z-index: 1100;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2);
            min-width: 350px;
            animation: alertSlideIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            border-top: 5px solid;
        }

        @keyframes alertSlideIn {
            from {
                opacity: 0;
                transform: translate(-50%, -60px) scale(0.9);
            }
            to {
                opacity: 1;
                transform: translate(-50%, -50%) scale(1);
            }
        }

        .alert-popup.show {
            display: block;
        }

        .alert-popup.success {
            border-top-color: var(--primary-color);
        }

        .alert-popup.error {
            border-top-color: var(--danger-color);
        }

        .alert-icon {
            font-size: 40px;
            margin-bottom: 20px;
            display: block;
            width: 70px;
            height: 70px;
            line-height: 70px;
            border-radius: 50%;
            margin: 0 auto 20px;
        }

        .alert-popup.success .alert-icon {
            background: rgba(0, 132, 61, 0.1);
            color: var(--primary-color);
        }

        .alert-popup.error .alert-icon {
            background: rgba(198, 40, 40, 0.1);
            color: var(--danger-color);
        }

        .alert-message {
            color: var(--dark-color);
            font-size: 16px;
            font-weight: 500;
            line-height: 1.5;
        }

        /* ==============================
           EMPTY STATE
           ============================== */
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: #666;
        }

        .empty-state i {
            font-size: 64px;
            margin-bottom: 25px;
            color: #ddd;
            display: block;
            opacity: 0.6;
        }

        .empty-state h3 {
            font-size: 20px;
            margin-bottom: 15px;
            color: #888;
        }

        .empty-state p {
            color: #aaa;
        }

        /* ==============================
           RESPONSIVE DESIGN
           ============================== */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 240px;
            }
            
            body.sidebar-collapsed .main-content {
                margin-left: 60px;
            }
            
            .view-content {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .view-photo-container {
                text-align: center;
            }
            
            .view-profile-photo {
                width: 150px;
                height: 150px;
                margin: 0 auto 20px;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0 !important;
                padding: 20px;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
                padding: 15px;
            }
            
            .add-btn {
                align-self: stretch;
                justify-content: center;
            }
            
            .filter-form {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-group {
                min-width: 100%;
            }
            
            .view-details {
                grid-template-columns: 1fr;
            }
            
            .location-grid {
                grid-template-columns: 1fr;
            }
            
            .stat-card {
                padding: 15px;
            }
            
            .action-btns {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .modal-content {
                padding: 25px 20px;
                max-width: 95%;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .campus-badge {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media (max-width: 480px) {
            .page-header h1 {
                font-size: 20px;
            }
            
            .modal-content {
                padding: 20px 15px;
            }
            
            .alert-popup {
                min-width: 280px;
                padding: 20px 25px;
            }
            
            .action-btn {
                width: 32px;
                height: 32px;
                font-size: 13px;
            }
        }

        /* ==============================
           SCROLLBAR STYLING
           ============================== */
        .table-container::-webkit-scrollbar,
        .modal-content::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        .table-container::-webkit-scrollbar-track,
        .modal-content::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .table-container::-webkit-scrollbar-thumb,
        .modal-content::-webkit-scrollbar-thumb {
            background: var(--secondary-color);
            border-radius: 4px;
        }

        .table-container::-webkit-scrollbar-thumb:hover,
        .modal-content::-webkit-scrollbar-thumb:hover {
            background: var(--primary-color);
        }

        /* ==============================
           ANIMATIONS
           ============================== */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .data-table tbody tr {
            animation: fadeIn 0.4s ease forwards;
        }

        .data-table tbody tr:nth-child(1) { animation-delay: 0.05s; }
        .data-table tbody tr:nth-child(2) { animation-delay: 0.1s; }
        .data-table tbody tr:nth-child(3) { animation-delay: 0.15s; }
        .data-table tbody tr:nth-child(4) { animation-delay: 0.2s; }
        .data-table tbody tr:nth-child(5) { animation-delay: 0.25s; }
        .data-table tbody tr:nth-child(6) { animation-delay: 0.3s; }
    </style>
</head>
<body>

<?php require_once('../includes/header.php'); ?>

<div class="main-content">
    <!-- ✅ PAGE HEADER -->
    <div class="page-header">
        <div class="header-title">
            <h1><i class="fas fa-building"></i> Department Management</h1>
            <div class="campus-badge">
                <span class="campus-name"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($campus_name) ?> (<?= htmlspecialchars($campus_code) ?>)</span>
                <span class="faculty-count"><i class="fas fa-university"></i> <?= count($campus_faculties) ?> Faculties</span>
            </div>
        </div>
        <button class="add-btn" onclick="openAddModal()">
            <i class="fa-solid fa-plus"></i> Add New Department
        </button>
    </div>

    <!-- ✅ STATS CARDS -->
    <div class="stats-container">
        <div class="stat-card total">
            <div class="stat-icon total">
                <i class="fas fa-building"></i>
            </div>
            <div class="stat-info">
                <h3>Total Departments</h3>
                <div class="number"><?= $total ?></div>
            </div>
        </div>
        
        <div class="stat-card active">
            <div class="stat-icon active">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-info">
                <h3>Active</h3>
                <div class="number"><?= $active ?></div>
            </div>
        </div>
        
        <div class="stat-card inactive">
            <div class="stat-icon inactive">
                <i class="fas fa-pause-circle"></i>
            </div>
            <div class="stat-info">
                <h3>Inactive</h3>
                <div class="number"><?= $inactive ?></div>
            </div>
        </div>
    </div>

    <!-- ✅ FILTERS -->
    <div class="filters-container">
        <div class="filter-header">
            <h3><i class="fas fa-filter"></i> Quick Filters</h3>
        </div>
        
        <form method="GET" class="filter-form" id="filterForm">
            <div class="filter-group">
                <label for="search">Search</label>
                <div style="position:relative;">
                    <i class="fas fa-search filter-icon"></i>
                    <input type="text" 
                           id="search" 
                           name="search" 
                           class="filter-input" 
                           placeholder="Department, code, head, email..."
                           value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            
            <div class="filter-group">
                <label for="status">Status</label>
                <div style="position:relative;">
                    <i class="fas fa-circle filter-icon"></i>
                    <select id="status" name="status" class="filter-input">
                        <option value="">All Status</option>
                        <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>
            
            <div class="filter-group">
                <label for="faculty_filter">Faculty</label>
                <div style="position:relative;">
                    <i class="fas fa-university filter-icon"></i>
                    <select id="faculty_filter" name="faculty" class="filter-input">
                        <option value="">All Faculties</option>
                        <?php foreach ($campus_faculties as $f): ?>
                            <option value="<?= $f['faculty_id'] ?>" <?= $faculty_filter == $f['faculty_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($f['faculty_name']) ?> (<?= htmlspecialchars($f['faculty_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="filter-actions">
                <button type="submit" class="filter-btn apply-btn">
                    <i class="fas fa-filter"></i> Apply
                </button>
                <button type="button" class="filter-btn clear-btn" onclick="clearFilters()">
                    <i class="fas fa-times"></i> Clear
                </button>
            </div>
        </form>
    </div>

    <!-- ✅ MAIN TABLE -->
    <div class="table-wrapper">
        <div class="table-header">
            <h3>Department List</h3>
            <div class="results-count">
                Showing <?= count($departments) ?> of <?= $total ?> departments
                <?php if(!empty($search) || !empty($status_filter) || !empty($faculty_filter)): ?>
                    (filtered)
                <?php endif; ?>
            </div>
        </div>
        
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Photo</th>
                        <th>Department</th>
                        <th>Code</th>
                        <th>Head of Dept</th>
                        <th>Faculty</th>
                        <th>Office Location</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($departments): ?>
                        <?php foreach($departments as $i => $d): 
                            $initials = getInitials($d['department_name']);
                            $photo_path = getProfilePhoto($d['profile_photo_path'] ?? '');
                        ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <div class="profile-photo-container">
                                    <?php if($photo_path): ?>
                                        <div class="profile-photo" onclick="openViewModal(<?= $d['department_id'] ?>)">
                                            <img src="../<?= $photo_path ?>" 
                                                 alt="<?= htmlspecialchars($d['department_name']) ?>"
                                                 onerror="this.parentElement.innerHTML='<div class=\"profile-initials\"><?= $initials ?></div>'">
                                        </div>
                                    <?php else: ?>
                                        <div class="profile-photo" onclick="openViewModal(<?= $d['department_id'] ?>)">
                                            <div class="profile-initials"><?= $initials ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><strong><?= htmlspecialchars($d['department_name']) ?></strong></td>
                            <td><span class="code-badge"><?= htmlspecialchars($d['department_code']) ?></span></td>
                            <td><?= htmlspecialchars($d['head_of_department'] ?? '') ?></td>
                            <td>
                                <span class="faculty-badge">
                                    <i class="fas fa-university"></i>
                                    <?= htmlspecialchars($d['faculty_name']) ?>
                                    <span class="faculty-badge-code"><?= htmlspecialchars($d['faculty_code']) ?></span>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($d['office_location'] ?? '') ?></td>
                            <td><?= htmlspecialchars($d['phone_number'] ?? '') ?></td>
                            <td><?= htmlspecialchars($d['email'] ?? '') ?></td>
                            <td>
                                <span class="status-badge status-<?= $d['status'] ?>">
                                    <?= ucfirst($d['status']) ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-btns">
                                    <button class="action-btn view-btn" 
                                            onclick="openViewModal(<?= $d['department_id'] ?>)"
                                            title="View Details">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                    <button class="action-btn edit-btn" 
                                            onclick="openEditModal(<?= $d['department_id'] ?>,
                                                '<?= addslashes($d['department_name']) ?>',
                                                '<?= addslashes($d['department_code']) ?>',
                                                '<?= addslashes($d['head_of_department'] ?? '') ?>',
                                                '<?= addslashes($d['phone_number'] ?? '') ?>',
                                                '<?= addslashes($d['email'] ?? '') ?>',
                                                '<?= addslashes($d['office_location'] ?? '') ?>',
                                                '<?= $d['status'] ?>',
                                                <?= $d['faculty_id'] ?>)"
                                            title="Edit">
                                        <i class="fa-solid fa-pen"></i>
                                    </button>
                                    <button class="action-btn del-btn" 
                                            onclick="openDeleteModal(<?= $d['department_id'] ?>)" 
                                            title="Delete">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="11">
                                <div class="empty-state">
                                    <i class="fa-solid fa-inbox"></i>
                                    <h3>No departments found</h3>
                                    <p>
                                        <?php if(!empty($search) || !empty($status_filter) || !empty($faculty_filter)): ?>
                                            Try adjusting your filters or <a href="javascript:void(0)" onclick="clearFilters()">clear all filters</a>
                                        <?php else: ?>
                                            Add your first department using the button above
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ✅ VIEW MODAL -->
<div class="modal" id="viewModal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('viewModal')">&times;</span>
        <h2><i class="fas fa-eye"></i> Department Details</h2>
        
        <div class="view-content">
            <div class="view-photo-container">
                <div class="view-profile-photo" id="view_profile_photo"></div>
            </div>
            
            <div class="view-details">
                <div class="detail-item">
                    <div class="detail-label"><i class="fas fa-building"></i> Department Name</div>
                    <div class="detail-value" id="view_name"></div>
                </div>
                
                <div class="detail-item">
                    <div class="detail-label"><i class="fas fa-code"></i> Department Code</div>
                    <div class="detail-value code" id="view_code"></div>
                </div>
                
                <div class="detail-item">
                    <div class="detail-label"><i class="fas fa-user-tie"></i> Head of Department</div>
                    <div class="detail-value" id="view_head"></div>
                </div>
                
                <div class="detail-item">
                    <div class="detail-label"><i class="fas fa-phone"></i> Phone Number</div>
                    <div class="detail-value" id="view_phone"></div>
                </div>
                
                <div class="detail-item">
                    <div class="detail-label"><i class="fas fa-envelope"></i> Email Address</div>
                    <div class="detail-value" id="view_email"></div>
                </div>
                
                <div class="detail-item">
                    <div class="detail-label"><i class="fas fa-circle"></i> Status</div>
                    <div class="detail-value" id="view_status"></div>
                </div>
            </div>
            
            <!-- Location Section -->
            <div class="location-section">
                <h4><i class="fas fa-map-marker-alt"></i> Location Information</h4>
                <div class="location-grid">
                    <div class="location-card">
                        <div class="location-card-header">
                            <div class="location-card-name" id="view_faculty_name"></div>
                            <div class="location-card-code" id="view_faculty_code"></div>
                        </div>
                        <div class="location-card-location">Faculty</div>
                    </div>
                    
                    <div class="location-card">
                        <div class="location-card-header">
                            <div class="location-card-name" id="view_campus_name"></div>
                            <div class="location-card-code" id="view_campus_code"></div>
                        </div>
                        <div class="location-card-location" id="view_campus_address"></div>
                    </div>
                    
                    <div class="location-card">
                        <div class="location-card-header">
                            <div class="location-card-name">Office Location</div>
                        </div>
                        <div class="location-card-location" id="view_office"></div>
                    </div>
                </div>
            </div>
        </div>
        
        <div style="display: flex; justify-content: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
            <button class="add-btn" onclick="closeModal('viewModal')">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
    </div>
</div>

<!-- ✅ ADD MODAL -->
<div class="modal" id="addModal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('addModal')">&times;</span>
        <h2><i class="fas fa-plus-circle"></i> Add New Department</h2>
        <form method="POST" enctype="multipart/form-data" id="addForm" onsubmit="return validateAddForm()">
            <input type="hidden" name="action" value="add">
            
            <!-- Campus Info -->
            <div class="campus-info-box">
                <div class="info-item">
                    <i class="fas fa-map-marker-alt"></i>
                    <span><?= htmlspecialchars($campus_name) ?></span>
                    <small>(<?= htmlspecialchars($campus_code) ?>)</small>
                </div>
            </div>
            
            <!-- Faculty Selection -->
            <div class="faculty-selection">
                <label for="add_faculty_id"><i class="fas fa-university"></i> Select Faculty *</label>
                <select id="add_faculty_id" name="faculty_id" class="faculty-select" required>
                    <option value="">-- Select a faculty --</option>
                    <?php foreach ($campus_faculties as $faculty): ?>
                        <option value="<?= $faculty['faculty_id'] ?>">
                            <?= htmlspecialchars($faculty['faculty_name']) ?> (<?= htmlspecialchars($faculty['faculty_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-grid">
                <div class="form-group">
                    <label for="department_name">Department Name *</label>
                    <input type="text" id="department_name" name="department_name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="department_code">Department Code *</label>
                    <input type="text" id="department_code" name="department_code" class="form-control" required>
                    <small style="color: #666; margin-top: 5px; display: block;">Unique code within campus</small>
                </div>
                
                <div class="form-group">
                    <label for="head_of_department">Head of Department *</label>
                    <input type="text" id="head_of_department" name="head_of_department" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="phone_number">Phone Number</label>
                    <input type="tel" id="phone_number" name="phone_number" class="form-control">
                </div>
                
                <div class="form-group">
                    <label for="email">Email Address *</label>
                    <input type="email" id="email" name="email" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="profile_photo">Profile Photo</label>
                    <input type="file" id="profile_photo" name="profile_photo" class="form-control" accept="image/*">
                </div>
            </div>
            
            <!-- Office Location (Auto-generated) -->
            <div class="office-location-display">
                <h4><i class="fas fa-map-marker-alt"></i> Office Location</h4>
                <div class="form-group" style="margin-bottom: 0;">
                    <input type="text" id="office_location" name="office_location" class="form-control" 
                           value="<?= htmlspecialchars($campus['address'] ?? '') ?><?= $campus['city'] ? ', ' . $campus['city'] : '' ?><?= $campus['country'] ? ', ' . $campus['country'] : '' ?>"
                           required>
                    <small style="display: block; margin-top: 5px; color: #666;">
                        Office location is auto-generated based on campus
                    </small>
                </div>
            </div>
            
            <div class="password-info" style="grid-column: 1 / -1; background: #f0f7ff; padding: 15px; border-radius: 8px; border-left: 4px solid var(--primary-color); margin-top: 10px;">
                <i class="fas fa-info-circle" style="color: var(--primary-color); margin-right: 10px;"></i>
                <span>A department admin account will be created with password: <strong>123</strong></span>
            </div>
            
            <button class="submit-btn" type="submit">
                <i class="fas fa-save"></i> Create Department
            </button>
        </form>
    </div>
</div>

<!-- ✅ EDIT MODAL -->
<div class="modal" id="editModal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('editModal')">&times;</span>
        <h2><i class="fas fa-edit"></i> Edit Department</h2>
        <form method="POST" enctype="multipart/form-data" id="editForm" onsubmit="return validateEditForm()">
            <input type="hidden" name="action" value="update">
            <input type="hidden" id="edit_id" name="department_id">
            
            <!-- Campus Info -->
            <div class="campus-info-box">
                <div class="info-item">
                    <i class="fas fa-map-marker-alt"></i>
                    <span><?= htmlspecialchars($campus_name) ?></span>
                    <small>(<?= htmlspecialchars($campus_code) ?>)</small>
                </div>
            </div>
            
            <!-- Faculty Selection -->
            <div class="faculty-selection">
                <label for="edit_faculty_id"><i class="fas fa-university"></i> Select Faculty *</label>
                <select id="edit_faculty_id" name="faculty_id" class="faculty-select" required>
                    <option value="">-- Select a faculty --</option>
                    <?php foreach ($campus_faculties as $faculty): ?>
                        <option value="<?= $faculty['faculty_id'] ?>">
                            <?= htmlspecialchars($faculty['faculty_name']) ?> (<?= htmlspecialchars($faculty['faculty_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-grid">
                <div class="form-group">
                    <label for="edit_name">Department Name *</label>
                    <input type="text" id="edit_name" name="department_name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_code">Department Code *</label>
                    <input type="text" id="edit_code" name="department_code" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_head">Head of Department *</label>
                    <input type="text" id="edit_head" name="head_of_department" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_phone">Phone Number</label>
                    <input type="tel" id="edit_phone" name="phone_number" class="form-control">
                </div>
                
                <div class="form-group">
                    <label for="edit_email">Email Address *</label>
                    <input type="email" id="edit_email" name="email" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_status">Status</label>
                    <select id="edit_status" name="status" class="form-control">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="edit_photo">New Photo</label>
                    <input type="file" id="edit_photo" name="profile_photo" class="form-control" accept="image/*">
                </div>
            </div>
            
            <!-- Office Location -->
            <div class="office-location-display">
                <h4><i class="fas fa-map-marker-alt"></i> Office Location</h4>
                <div class="form-group" style="margin-bottom: 0;">
                    <input type="text" id="edit_office" name="office_location" class="form-control" required>
                </div>
            </div>
            
            <button class="submit-btn" type="submit">
                <i class="fas fa-sync-alt"></i> Update Department
            </button>
        </form>
    </div>
</div>

<!-- ✅ DELETE MODAL -->
<div class="modal" id="deleteModal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('deleteModal')">&times;</span>
        <h2 style="color: var(--danger-color);"><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h2>
        <form method="POST">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="department_id" id="delete_id">
            
            <div style="text-align: center; margin: 30px 0;">
                <i class="fa-solid fa-exclamation-triangle" style="font-size: 48px; color: var(--danger-color); margin-bottom: 20px;"></i>
                <p style="font-size: 16px; color: var(--dark-color); margin-bottom: 10px;">
                    Are you sure you want to delete this department?
                </p>
                <p style="color: #666; font-size: 14px;">
                    This action will delete the associated user account and cannot be undone.
                </p>
            </div>
            
            <button class="submit-btn delete-btn" type="submit">
                <i class="fas fa-trash-alt"></i> Yes, Delete Department
            </button>
        </form>
    </div>
</div>

<!-- ✅ ALERT POPUP -->
<div id="popup" class="alert-popup <?= $type ?> <?= !empty($message)?'show':'' ?>">
    <span class="alert-icon"><?= $type==='success' ? '✓' : '✗' ?></span>
    <div class="alert-message"><?= $message ?></div>
</div>

<script>
// Department data for view modal
const departmentData = {
    <?php foreach($departments as $d): ?>
    <?= $d['department_id'] ?>: {
        name: '<?= addslashes($d['department_name']) ?>',
        code: '<?= addslashes($d['department_code']) ?>',
        head: '<?= addslashes($d['head_of_department'] ?? '') ?>',
        phone: '<?= addslashes($d['phone_number'] ?? '') ?>',
        email: '<?= addslashes($d['email'] ?? '') ?>',
        office: '<?= addslashes($d['office_location'] ?? '') ?>',
        status: '<?= $d['status'] ?>',
        faculty_id: <?= $d['faculty_id'] ?>,
        faculty_name: '<?= addslashes($d['faculty_name']) ?>',
        faculty_code: '<?= addslashes($d['faculty_code']) ?>',
        campus_id: <?= $d['campus_id'] ?>,
        campus_name: '<?= addslashes($d['campus_name']) ?>',
        campus_code: '<?= addslashes($d['campus_code'] ?? '') ?>',
        campus_address: '<?= addslashes($d['campus_address'] ?? '') ?>',
        campus_city: '<?= addslashes($d['campus_city'] ?? '') ?>',
        campus_country: '<?= addslashes($d['campus_country'] ?? '') ?>',
        photo: '<?= $d['profile_photo_path'] ?? '' ?>',
        initials: '<?= getInitials($d['department_name']) ?>'
    },
    <?php endforeach; ?>
};

// Modal functions
function openModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.remove('show');
        document.body.style.overflow = 'auto';
    }
}

// Open add modal
function openAddModal() {
    document.getElementById('addModal').classList.add('show');
    document.body.style.overflow = 'hidden';
    document.getElementById('addForm').reset();
}

// Clear all filters
function clearFilters() {
    window.location.href = window.location.pathname;
}

// ✅ OPEN VIEW MODAL
function openViewModal(departmentId) {
    openModal('viewModal');
    
    const dept = departmentData[departmentId];
    if (!dept) return;
    
    // Set basic details
    document.getElementById('view_name').textContent = dept.name || 'Not provided';
    document.getElementById('view_code').textContent = dept.code || 'Not provided';
    document.getElementById('view_head').textContent = dept.head || 'Not provided';
    document.getElementById('view_phone').textContent = dept.phone || 'Not provided';
    document.getElementById('view_email').textContent = dept.email || 'Not provided';
    document.getElementById('view_office').textContent = dept.office || 'Not provided';
    
    // Set status
    const statusElement = document.getElementById('view_status');
    statusElement.textContent = dept.status ? dept.status.charAt(0).toUpperCase() + dept.status.slice(1) : 'Not provided';
    statusElement.style.borderLeftColor = dept.status === 'active' ? 'var(--primary-color)' : 'var(--danger-color)';
    
    // Set faculty info
    document.getElementById('view_faculty_name').textContent = dept.faculty_name || 'Not provided';
    document.getElementById('view_faculty_code').textContent = dept.faculty_code || '';
    
    // Set campus info
    document.getElementById('view_campus_name').textContent = dept.campus_name || 'Not provided';
    document.getElementById('view_campus_code').textContent = dept.campus_code || '';
    
    const campusAddress = [];
    if (dept.campus_address) campusAddress.push(dept.campus_address);
    if (dept.campus_city) campusAddress.push(dept.campus_city);
    if (dept.campus_country) campusAddress.push(dept.campus_country);
    document.getElementById('view_campus_address').innerHTML = campusAddress.join(', ') || 'Not specified';
    
    // Set profile photo
    const photoContainer = document.getElementById('view_profile_photo');
    if (dept.photo && dept.photo.trim() !== '') {
        photoContainer.innerHTML = `<img src="../${dept.photo}" alt="${dept.name}" 
            onerror="this.onerror=null; this.parentElement.innerHTML='<div style=\"width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg, var(--secondary-color), var(--primary-color));color:white;font-size:60px;font-weight:bold;\">${dept.initials}</div>';">`;
    } else {
        photoContainer.innerHTML = `<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg, var(--secondary-color), var(--primary-color));color:white;font-size:60px;font-weight:bold;">${dept.initials}</div>`;
    }
}

// ✅ OPEN EDIT MODAL
function openEditModal(id, name, code, head, phone, email, office, status, facultyId) {
    openModal('editModal');
    
    // Set form values
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_code').value = code;
    document.getElementById('edit_head').value = head;
    document.getElementById('edit_phone').value = phone;
    document.getElementById('edit_email').value = email;
    document.getElementById('edit_office').value = office;
    document.getElementById('edit_status').value = status;
    
    // Set faculty
    document.getElementById('edit_faculty_id').value = facultyId;
}

// ✅ OPEN DELETE MODAL
function openDeleteModal(id) {
    openModal('deleteModal');
    document.getElementById('delete_id').value = id;
}

// Form validation
function validateAddForm() {
    const name = document.getElementById('department_name').value.trim();
    const code = document.getElementById('department_code').value.trim();
    const head = document.getElementById('head_of_department').value.trim();
    const email = document.getElementById('email').value.trim();
    const facultyId = document.getElementById('add_faculty_id').value;
    
    if (!facultyId) {
        alert('Please select a faculty!');
        return false;
    }
    
    if (!name || !code || !head || !email) {
        alert('Please fill in all required fields!');
        return false;
    }
    
    if (!email.includes('@') || !email.includes('.')) {
        alert('Please enter a valid email address!');
        return false;
    }
    
    return true;
}

function validateEditForm() {
    const name = document.getElementById('edit_name').value.trim();
    const code = document.getElementById('edit_code').value.trim();
    const head = document.getElementById('edit_head').value.trim();
    const email = document.getElementById('edit_email').value.trim();
    const facultyId = document.getElementById('edit_faculty_id').value;
    
    if (!facultyId) {
        alert('Please select a faculty!');
        return false;
    }
    
    if (!name || !code || !head || !email) {
        alert('Please fill in all required fields!');
        return false;
    }
    
    if (!email.includes('@') || !email.includes('.')) {
        alert('Please enter a valid email address!');
        return false;
    }
    
    return true;
}

// Auto-submit filters
document.addEventListener('DOMContentLoaded', function() {
    // Auto-hide alert
    const alert = document.getElementById('popup');
    if (alert && alert.classList.contains('show')) {
        setTimeout(() => {
            alert.classList.remove('show');
        }, 3500);
    }
    
    // Filter auto-submit
    document.getElementById('status').addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
    
    document.getElementById('faculty_filter').addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
    
    // Debounced search
    let searchTimer;
    document.getElementById('search').addEventListener('input', function(e) {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            if (e.target.value.length === 0 || e.target.value.length > 2) {
                document.getElementById('filterForm').submit();
            }
        }, 600);
    });
    
    // Close modal when clicking outside
    window.addEventListener('click', function(event) {
        if (event.target.classList.contains('modal')) {
            closeModal(event.target.id);
        }
    });
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal.show').forEach(modal => {
                closeModal(modal.id);
            });
        }
    });
});
</script>

<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>